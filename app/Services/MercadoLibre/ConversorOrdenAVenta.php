<?php

namespace App\Services\MercadoLibre;

use App\Enums\MercadoLibre\EstadoConversion;
use App\Enums\MercadoLibre\EstadoOrden;
use App\Models\Cliente;
use App\Models\CuentaTesoreria;
use App\Models\Deposito;
use App\Models\FuncionAvanzada;
use App\Models\Integraciones\MercadoLibreConfiguracion;
use App\Models\Integraciones\MercadoLibreOperacionLog;
use App\Models\Integraciones\MercadoLibreOrden;
use App\Models\Integraciones\MercadoLibreOrdenItem;
use App\Models\Integraciones\MercadoLibrePublicacionProducto;
use App\Models\Producto;
use App\Models\Venta;
use App\Services\Ingresos\CalculoComprobante;
use App\Services\Ingresos\Cobranzas;
use App\Services\Ingresos\StockDeVenta;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Orquesta la conversión de una orden de Mercado Libre en una Venta del CRM
 * (US3, plan.md §2): mismo camino para el flujo manual y el automático.
 * Candado por orden (FR-032a) + índice único de respaldo (FR-032b) +
 * transacción atómica (FR-048).
 */
class ConversorOrdenAVenta
{
    public function __construct(
        private readonly EvaluadorConvertibilidad $evaluador,
        private readonly ResolutorCliente $resolutorCliente,
        private readonly DerivadorComprobante $derivadorComprobante,
        private readonly StockDeVenta $stockDeVenta,
        private readonly Cobranzas $cobranzas,
        private readonly CalculoComprobante $calculo,
        private readonly ClienteMercadoLibre $cliente,
    ) {}

    /**
     * @param  ?int  $clienteIdOverride  Corrección manual del Cliente resuelto (p. ej. para desambiguar, FR-038).
     * @param  ?string  $tipoComprobanteOverride  Corrección manual del comprobante derivado (FR-043).
     * @param  bool  $forzada  spec 066: la persona confirmó explícitamente convertir una orden en
     *                         estado excepcional. Cerrado por defecto — el cron, el lote y la
     *                         conversión normal no lo pasan nunca. Saltea ÚNICAMENTE las guardas
     *                         de estado excepcional; no saltea problemas de datos (FR-013), ni
     *                         una orden pendiente de pago, ni los cortes globales (FR-014).
     * @return array{ok: bool, venta?: Venta, mensaje?: string, motivo?: string, venta_id?: int, forzada?: bool}
     */
    public function convertir(
        MercadoLibreOrden $orden,
        ?int $usuarioId,
        bool $automatica = false,
        ?int $clienteIdOverride = null,
        ?string $tipoComprobanteOverride = null,
        bool $forzada = false,
    ): array {
        $lock = Cache::lock("ml:convertir_orden:{$orden->id}", 30);

        if (! $lock->get()) {
            return $this->rechazo('Esta orden ya se está convirtiendo. Esperá a que termine.');
        }

        try {
            return $this->convertirBajoCandado($orden, $usuarioId, $automatica, $clienteIdOverride, $tipoComprobanteOverride, $forzada);
        } finally {
            $lock->release();
        }
    }

    /**
     * "Transformar todas en Venta" (spec 025, FR-002/FR-005/FR-006): convierte,
     * de una en una, todas las órdenes en `Lista` de la conexión vigente, reusando
     * el mismo candado/transacción por orden que `convertir()`. Guardrails
     * idénticos a `SincronizadorOrdenes::verificarCortes()` (función avanzada +
     * modo sólo lectura) — si alguno bloquea, no toca ninguna orden.
     *
     * @return array{ok: bool, tipo?: string, mensaje: string, total?: int, convertidas?: int, fallidas?: int, excluidas?: int, detalle_fallidas?: array, detalle_excluidas?: array}
     */
    public function convertirTodasLasListas(?int $usuarioId): array
    {
        if ($bloqueo = $this->verificarCortesBatch()) {
            return $bloqueo;
        }

        $ordenes = MercadoLibreOrden::where('estado_conversion', EstadoConversion::Lista->value)->get();

        $total = $ordenes->count();
        $convertidas = 0;
        $detalleFallidas = [];
        $detalleExcluidas = [];

        foreach ($ordenes as $orden) {
            // spec 066 (FR-003): una orden que quedó en estado excepcional entre que se armó
            // el listado y le llegó el turno se excluye sin intentar convertirla. El lote nunca
            // fuerza: forzar es una decisión por orden y con confirmación (FR-008).
            if ($motivoExcepcional = $orden->motivoExcepcional()) {
                $detalleExcluidas[] = [
                    'orden' => $orden->ml_order_id,
                    'motivo' => $motivoExcepcional->value,
                    'motivo_detalle' => $motivoExcepcional->etiqueta(),
                ];

                continue;
            }

            $resultado = $this->convertir($orden, $usuarioId, automatica: false);

            if ($resultado['ok']) {
                $convertidas++;

                continue;
            }

            $fresca = $orden->fresh();

            // Si el rechazo fue por un motivo excepcional detectado recién dentro del
            // conversor (bajo candado, con la orden refrescada), cuenta como exclusión y
            // no como falla: el sistema hizo lo que se le pidió.
            if ($fresca?->motivo?->esExcepcional()) {
                $detalleExcluidas[] = [
                    'orden' => $orden->ml_order_id,
                    'motivo' => $fresca->motivo->value,
                    'motivo_detalle' => $fresca->motivo->etiqueta(),
                ];

                continue;
            }

            $detalleFallidas[] = [
                'orden' => $orden->ml_order_id,
                'motivo' => $fresca?->motivo?->etiqueta() ?? $resultado['mensaje'],
                'motivo_detalle' => $fresca?->motivo_detalle,
            ];
        }

        $excluidas = count($detalleExcluidas);
        $fallidas = $total - $convertidas - $excluidas;

        return [
            'ok' => true,
            'mensaje' => "{$convertidas} de {$total} órdenes convertidas.",
            'total' => $total,
            'convertidas' => $convertidas,
            'fallidas' => $fallidas,
            'excluidas' => $excluidas,
            'detalle_fallidas' => $detalleFallidas,
            'detalle_excluidas' => $detalleExcluidas,
        ];
    }

    /** Mismos cortes que `SincronizadorOrdenes::verificarCortes()` (función avanzada + modo sólo lectura). */
    private function verificarCortesBatch(): ?array
    {
        if (! (bool) FuncionAvanzada::where('clave', 'mercadolibre')->value('activa')) {
            return ['ok' => false, 'tipo' => 'bloqueada', 'mensaje' => 'La función "Mercado Libre" está desactivada en Funciones Avanzadas.'];
        }

        if (MercadoLibreConfiguracion::actual()->modo_solo_lectura) {
            return ['ok' => false, 'tipo' => 'bloqueada', 'mensaje' => 'Bloqueada por el modo sólo lectura: la conversión está deshabilitada mientras esté activo.'];
        }

        return null;
    }

    /**
     * Vista previa para precargar el formulario de conversión (GET) sin
     * persistir la Venta — sí puede resolver/crear el Cliente y completar
     * datos fiscales de la orden, igual que haría la conversión real
     * (FR-036/FR-037/FR-039), porque Contagram resuelve el Cliente como el
     * primer paso del mismo flujo, no como un efecto exclusivo del guardado.
     *
     * @return array{datos_fiscales: array, cliente: ?Cliente, cliente_ambiguo: bool, lineas: array}
     */
    public function previsualizar(MercadoLibreOrden $orden): array
    {
        $orden->load('items.producto');

        $datosFiscales = $this->derivadorComprobante->derivar($orden);
        $resolucionCliente = $this->resolutorCliente->resolver($orden, $datosFiscales);
        $lineas = $this->armarLineas($orden);

        return [
            'datos_fiscales' => $datosFiscales,
            'cliente' => $resolucionCliente['cliente'],
            'cliente_ambiguo' => $resolucionCliente['ambiguo'],
            'lineas' => $lineas,
        ];
    }

    private function convertirBajoCandado(
        MercadoLibreOrden $orden,
        ?int $usuarioId,
        bool $automatica,
        ?int $clienteIdOverride = null,
        ?string $tipoComprobanteOverride = null,
        bool $forzada = false,
    ): array {
        $orden->refresh(); // revalidación bajo candado (FR-032a): puede haber cambiado desde que se leyó.

        if ($orden->venta_id) {
            return $this->rechazo('Esta orden ya tiene una Venta asociada.', $orden->venta_id);
        }

        // Red de seguridad anti-duplicados (spec 038): sobrevive al borrado+resincronización
        // de la orden, porque busca por el identificador estable del pedido, no por la fila.
        if (Venta::withTrashed()->where('ml_order_id', $orden->ml_order_id)->exists()) {
            return $this->rechazo('Esta orden ya tiene una Venta asociada.');
        }

        // spec 066 (FR-008/FR-010): las guardas de estado excepcional. Sin confirmación
        // explícita se rechaza igual que antes; con confirmación se saltean SÓLO éstas.
        $motivoExcepcional = MotivoExcepcional::de($orden);

        if ($motivoExcepcional && ! $forzada) {
            return $this->rechazo(
                MotivoExcepcional::detalle($motivoExcepcional),
                null,
                $motivoExcepcional->value,
            );
        }

        // CUIDADO al tocar esto: la exigencia de "Pagada" cubre DOS casos distintos que
        // comparten la misma condición. Una orden cancelada o con reembolso parcial tampoco
        // está "Pagada", y ésas sí se pueden forzar. Una orden simplemente PENDIENTE de pago
        // NO se puede forzar nunca (FR-014): todavía no entró la plata, y facturarla sería
        // inventar un cobro. Por eso la excepción se condiciona a que haya motivo excepcional,
        // no sólo a $forzada.
        $salteaExigenciaDePago = $forzada && $motivoExcepcional !== null;

        if ($orden->estado_orden !== EstadoOrden::Pagada && ! $salteaExigenciaDePago) {
            return $this->rechazo($orden->estado_orden === EstadoOrden::Cancelada
                ? 'La orden está cancelada en Mercado Libre y no puede convertirse.'
                : 'La orden todavía no está pagada en Mercado Libre.');
        }

        $orden->load('items.producto');

        $datosFiscales = $this->derivadorComprobante->derivar($orden);
        $resolucionCliente = $this->resolutorCliente->resolver($orden, $datosFiscales);

        // Una corrección manual explícita de Cliente (p. ej. para desambiguar, FR-038) hace que el
        // caso ya no sea ambiguo a los efectos de la evaluación de convertibilidad.
        $clienteFinal = $clienteIdOverride ? Cliente::find($clienteIdOverride) : $resolucionCliente['cliente'];
        $clienteAmbiguo = $clienteIdOverride ? false : $resolucionCliente['ambiguo'];

        // spec 066 (FR-013): en el camino forzado se le pide al evaluador el veredicto sobre
        // los DATOS, salteando las guardas de estado que la persona ya confirmó. Forzar nunca
        // saltea publicación sin vincular, producto inexistente, variantes, cliente ambiguo ni
        // moneda distinta: eso no es algo que se pueda "asumir", la Venta saldría mal.
        $forzadaEfectiva = $forzada && $motivoExcepcional !== null;

        [$estado, $motivo, $detalle] = $this->evaluador->evaluar(
            $orden,
            $clienteAmbiguo,
            ignorarExcepcionales: $forzadaEfectiva,
        );

        if ($estado !== EstadoConversion::Lista) {
            $orden->update(['estado_conversion' => $estado->value, 'motivo' => $motivo?->value, 'motivo_detalle' => $detalle]);

            return $this->rechazo($detalle ?? 'La orden no está lista para convertir.', null, $motivo?->value);
        }

        if (! $clienteFinal) {
            return $this->rechazo('El Cliente indicado no existe.');
        }

        $cuentaMercadoPago = $this->cuentaMercadoPago();

        if (! $cuentaMercadoPago) {
            return $this->rechazo('No hay una cuenta de Tesorería "Mercado Pago" activa. Creala o reactivala antes de convertir.');
        }

        $tipoComprobante = $tipoComprobanteOverride ?: $datosFiscales['tipo_comprobante'];

        try {
            $venta = DB::transaction(function () use ($orden, $clienteFinal, $tipoComprobante, $cuentaMercadoPago, $usuarioId, $automatica, $forzadaEfectiva, $motivoExcepcional) {
                $lineas = $this->armarLineas($orden);

                // Las fechas de la orden se guardan en UTC. El día hay que sacarlo en hora local,
                // si no toda venta hecha después de las 21:00 argentinas cae en el día siguiente.
                $fechaEmision = ($orden->fecha_cerrada ?? $orden->fecha_creada ?? now())
                    ->setTimezone(config('app.display_timezone'))
                    ->toDateString();

                $venta = Venta::create([
                    'origen' => 'mercadolibre',
                    'ml_order_id' => $orden->ml_order_id,
                    'cliente_id' => $clienteFinal->id,
                    'categoria_id' => MercadoLibreConfiguracion::actual()->categoria_venta_id,
                    'vendedor_id' => MercadoLibreConfiguracion::actual()->vendedor_id,
                    // El depósito del que sale el stock tiene que quedar guardado en la Venta, no
                    // sólo en el movimiento: sin esto la Venta queda con `deposito_id` en NULL y al
                    // editarla el selector cae en el primer depósito de la lista, que puede no ser
                    // el que despachó. Es el mismo que usa el descuento de stock, más abajo.
                    'deposito_id' => $this->resolverDeposito($orden)->id,
                    'fecha_emision' => $fechaEmision,
                    'tipo_comprobante' => $tipoComprobante,
                    'nro_comprobante' => Venta::siguienteNroComprobante($tipoComprobante),
                    'subtotal_sin_descuento' => $lineas['subtotal_sin_descuento'],
                    'descuento' => 0,
                    'subtotal_con_descuento' => $lineas['subtotal_con_descuento'],
                    'total' => $lineas['total'],
                    'submit_token' => (string) Str::uuid(),
                ]);

                foreach ($lineas['items'] as $item) {
                    // `$lineas` viene de `CalculoComprobante::calcular()`, así que cada ítem ya
                    // trae `costo_unitario` congelado con el costo del producto vigente al momento
                    // de crear la Venta en el CRM —no al momento de la orden en el canal— (spec
                    // 075, `data-model.md §1`). Una línea cuyo producto no se pudo emparejar
                    // congela 0, que en el informe significa "costo cero", no "sin costo".
                    $venta->items()->create($item);
                }

                $venta->load('items.producto');
                $this->stockDeVenta->aplicarAlta($venta);

                $this->cobranzas->registrarCobro(
                    $venta,
                    (float) $venta->total,
                    $cuentaMercadoPago,
                    Carbon::parse($fechaEmision),
                    'Cobro automático — orden de Mercado Libre '.$orden->ml_order_id,
                );

                $orden->update([
                    'venta_id' => $venta->id,
                    'estado_conversion' => EstadoConversion::Convertida->value,
                    'motivo' => null,
                    'motivo_detalle' => null,
                    'creacion_automatica' => $automatica,
                    'convertida_en' => now(),
                    'convertida_por' => $automatica ? null : $usuarioId,
                    // spec 066 (FR-011/FR-018): los tres se escriben juntos o no se escribe
                    // ninguno. `forzada_motivo` además es contra lo que compara el detector de
                    // la spec 063 para no devolver como aviso la decisión recién tomada.
                    ...($forzadaEfectiva ? [
                        'forzada_motivo' => $motivoExcepcional->value,
                        'forzada_por_id' => $usuarioId,
                        'forzada_en' => now(),
                    ] : []),
                ]);

                foreach ($orden->items as $index => $ordenItem) {
                    if (! empty($lineas['producto_ids'][$index])) {
                        $ordenItem->update(['producto_id' => $lineas['producto_ids'][$index]]);
                    }
                }

                return $venta;
            });
        } catch (QueryException $e) {
            // Respaldo de FR-032b: el índice único de ml_ordenes.venta_id rechazó un duplicado
            // que el candado, en un caso anormalmente lento, no llegó a prevenir.
            return $this->rechazo('Esta orden ya tiene una Venta asociada.', $orden->fresh()->venta_id);
        }

        if ($forzadaEfectiva) {
            // FR-011: la fuente de verdad de "quién forzó qué" es la bitácora, no la columna
            // de la orden — ésta queda en null si el usuario se elimina, el registro no.
            MercadoLibreOperacionLog::registrar([
                'operacion' => 'convertir_orden_forzada',
                'metodo' => 'INTERNO',
                'endpoint' => '-',
                'sentido' => 'escritura',
                'resultado' => 'ok',
                'usuario_id' => $usuarioId,
                'payload_bloqueado' => "Orden {$orden->ml_order_id} convertida a mano pese a estar en estado excepcional ".
                    "(\"{$motivoExcepcional->etiqueta()}\"). Venta {$venta->nro_comprobante} creada sin emitir el comprobante.",
            ]);
        }

        return [
            'ok' => true,
            'venta' => $venta,
            'forzada' => $forzadaEfectiva,
            'motivo' => $forzadaEfectiva ? $motivoExcepcional->value : null,
        ];
    }

    /**
     * Desagregación de IVA desde el precio final (FR-030a/FR-030b, research.md
     * §R7): reutiliza CalculoComprobante, invirtiendo la relación neto→final
     * al armar cada línea, y absorbe el redondeo ajustando la última.
     *
     * @return array{items: array, subtotal_sin_descuento: float, descuento: float, subtotal_con_descuento: float, total: float, producto_ids: array<int, ?int>}
     */
    /**
     * spec 065/FR-020..FR-023: a qué depósito se imputa la Venta de esta orden.
     *
     * El depósito Full se usa **sólo si todas** las líneas mapean a vínculos Full. Una orden
     * mixta va al general (FR-020a): una Venta tiene un único depósito, y descontar de Full
     * mercadería que salió del domicilio del vendedor sería peor que la imprecisión de
     * imputar todo al general, donde al menos el stock físico existe.
     *
     * El `logistic_type` del vínculo describe la PUBLICACIÓN, no el envío. Una publicación Full
     * cuyo depósito de Mercado Libre se quedó sin unidades sigue vendiendo, pero el paquete sale
     * del domicilio (`self_service` / `xd_drop_off`): esa venta NO salió de Full. Por eso, cuando
     * los vínculos dicen Full, se confirma contra el envío real de la orden — es el único dato
     * que distingue una venta de otra dentro de la misma publicación.
     *
     * Caso real (venta 24587, 19/08/2026): publicación `fulfillment`, envío `self_service`. La
     * Venta se imputó a Full, el reflejo de Full la devolvió a cero y la unidad no se descontó
     * de ningún depósito.
     *
     * FR-022: esto nunca puede impedir que la orden se convierta — ante cualquier duda cae al
     * depósito general.
     */
    /**
     * ¿El paquete de esta orden salió del centro de distribución de Mercado Libre?
     *
     * Lo dice el envío, no la publicación: `GET /shipments/{id}` devuelve el `logistic_type`
     * con el que se despachó **esa** orden. `fulfillment` = salió de Full; `self_service`
     * (Flex) o `xd_drop_off` (Colecta) = lo despacha el vendedor desde su domicilio.
     *
     * Ante cualquier duda —sin id de envío, o la consulta falla— devuelve `false`: se imputa al
     * depósito general, que es donde el stock existe de verdad. Mismo criterio que FR-005, que
     * nunca asume Full. Y nunca traba la conversión (FR-022).
     */
    private function envioSalioDeFull(MercadoLibreOrden $orden): bool
    {
        $envioId = $orden->payload['shipping']['id'] ?? null;

        if (! $envioId) {
            return false;
        }

        $respuesta = $this->cliente->obtener('logistica_envio', "/shipments/{$envioId}");

        if ($respuesta->fallo()) {
            return false;
        }

        return ($respuesta->datos['logistic_type'] ?? null) === MercadoLibrePublicacionProducto::LOGISTICA_FULL;
    }

    private function resolverDeposito(MercadoLibreOrden $orden): Deposito
    {
        $configuracion = MercadoLibreConfiguracion::actual();
        $depositoFull = $configuracion->depositoFullEfectivoONulo();

        if (! $depositoFull) {
            return $configuracion->depositoEfectivo();
        }

        $itemIds = $orden->items->pluck('ml_item_id')->filter()->unique();

        if ($itemIds->isEmpty()) {
            return $configuracion->depositoEfectivo();
        }

        $vinculos = MercadoLibrePublicacionProducto::whereIn('ml_item_id', $itemIds)->get()->keyBy('ml_item_id');

        // Una publicación sin vincular no se puede clasificar, así que cuenta como no-Full:
        // el sistema nunca asume Full ante la duda (FR-005).
        $todasFull = $itemIds->every(fn (string $itemId) => $vinculos->get($itemId)?->esFull() === true);

        $deposito = $todasFull && $this->envioSalioDeFull($orden)
            ? $depositoFull
            : $configuracion->depositoEfectivo();

        // FR-023: queda registrado el criterio aplicado, para poder responder después por qué
        // una Venta descontó de un depósito y no del otro.
        MercadoLibreOperacionLog::registrar([
            'operacion' => 'imputar_deposito_venta',
            'metodo' => 'INTERNO',
            'endpoint' => '-',
            'sentido' => 'interno',
            'resultado' => 'ok',
            'usuario_id' => auth()->id(),
            'payload_bloqueado' => "Orden {$orden->ml_order_id}: ".($todasFull
                ? "íntegramente Full → depósito \"{$deposito->nombre}\"."
                : "no es íntegramente Full → depósito general \"{$deposito->nombre}\"."),
        ]);

        return $deposito;
    }

    private function armarLineas(MercadoLibreOrden $orden): array
    {
        $items = $orden->items;
        $productoIds = [];

        $itemsCalculo = $items->map(function (MercadoLibreOrdenItem $item) use (&$productoIds) {
            // Resuelve contra la vinculación VIGENTE (no el snapshot de la última sincronización):
            // cubre el caso de vinculación inline recién creada desde este mismo formulario (FR-023).
            $vinculo = MercadoLibrePublicacionProducto::where('ml_item_id', $item->ml_item_id)->first();
            $producto = $vinculo?->producto ?? $item->producto;
            $productoIds[] = $producto?->id;

            $ivaPct = Producto::porcentajeIva($producto?->iva_venta_pct);
            $cantidad = (float) $item->cantidad;
            $netoUnitario = $cantidad > 0
                ? round(((float) $item->total_linea / (1 + $ivaPct / 100)) / $cantidad, 4)
                : 0.0;

            return [
                'producto_id' => $producto?->id,
                'descripcion' => $item->titulo,
                'cantidad' => $cantidad,
                'precio_unitario' => $netoUnitario,
                'descuento_pct' => null,
                'iva_pct' => $producto?->iva_venta_pct,
            ];
        })->values()->all();

        $resultado = $this->calculo->calcular($itemsCalculo, 'porcentaje', 0, []);
        $diferencia = round((float) $orden->total - $resultado['total'], 2);

        if (abs($diferencia) >= 0.01 && count($itemsCalculo) > 0) {
            $ultimoIndice = count($itemsCalculo) - 1;
            $cantidadUltimo = max((float) $itemsCalculo[$ultimoIndice]['cantidad'], 1);
            $itemsCalculo[$ultimoIndice]['precio_unitario'] = round(
                (float) $itemsCalculo[$ultimoIndice]['precio_unitario'] + ($diferencia / $cantidadUltimo),
                2
            );
            $resultado = $this->calculo->calcular($itemsCalculo, 'porcentaje', 0, []);
        }

        // Conciliación exacta (FR-030): el total de la Venta iguala el de la orden al centavo.
        $resultado['total'] = round((float) $orden->total, 2);
        $resultado['producto_ids'] = $productoIds;

        return $resultado;
    }

    private function cuentaMercadoPago(): ?CuentaTesoreria
    {
        return CuentaTesoreria::where('nombre', 'Mercado Pago')->where('visible', true)->first();
    }

    private function rechazo(string $mensaje, ?int $ventaId = null, ?string $motivo = null): array
    {
        $rechazo = ['ok' => false, 'mensaje' => $mensaje];

        if ($ventaId) {
            $rechazo['venta_id'] = $ventaId;
        }
        if ($motivo) {
            $rechazo['motivo'] = $motivo;
        }

        return $rechazo;
    }
}
