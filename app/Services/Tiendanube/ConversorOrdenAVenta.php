<?php

namespace App\Services\Tiendanube;

use App\Enums\Tiendanube\EstadoConversion;
use App\Models\CuentaTesoreria;
use App\Models\Cliente;
use App\Models\FuncionAvanzada;
use App\Models\Integraciones\TiendanubeConexionRest;
use App\Models\Integraciones\TiendanubeOrden;
use App\Models\Integraciones\TiendanubeOrdenItem;
use App\Models\Integraciones\TiendanubeVarianteProducto;
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
 * Orquesta la conversión de una orden de Tiendanube en una Venta del CRM (US3,
 * plan.md §3): mismo camino para el flujo manual y el automático. Candado por
 * orden (FR-032a) + índice único de respaldo (FR-032b) + transacción atómica
 * (FR-048). A diferencia de `App\Services\MercadoLibre\ConversorOrdenAVenta`,
 * la cuenta de Tesorería se resuelve por FK **configurada**, no por nombre
 * fijo (research.md R5).
 */
class ConversorOrdenAVenta
{
    public function __construct(
        private readonly EvaluadorConvertibilidad $evaluador,
        private readonly ResolutorCliente $resolutorCliente,
        private readonly StockDeVenta $stockDeVenta,
        private readonly Cobranzas $cobranzas,
        private readonly CalculoComprobante $calculo,
    ) {
    }

    /**
     * @param  ?int  $clienteIdOverride  Corrección manual del Cliente resuelto (p. ej. para desambiguar, FR-038).
     * @param  ?string  $tipoComprobanteOverride  Corrección manual del comprobante derivado (FR-043).
     * @return array{ok: bool, venta?: Venta, mensaje?: string, motivo?: string, venta_id?: int}
     */
    public function convertir(
        TiendanubeOrden $orden,
        ?int $usuarioId,
        bool $automatica = false,
        ?int $clienteIdOverride = null,
        ?string $tipoComprobanteOverride = null,
    ): array {
        $lock = Cache::lock("tn:convertir_orden:{$orden->id}", 30);

        if (! $lock->get()) {
            return $this->rechazo('Esta orden ya se está convirtiendo. Esperá a que termine.');
        }

        try {
            return $this->convertirBajoCandado($orden, $usuarioId, $automatica, $clienteIdOverride, $tipoComprobanteOverride);
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
     * @return array{ok: bool, tipo?: string, mensaje: string, total?: int, convertidas?: int, fallidas?: int, detalle_fallidas?: array}
     */
    public function convertirTodasLasListas(?int $usuarioId): array
    {
        if ($bloqueo = $this->verificarCortesBatch()) {
            return $bloqueo;
        }

        $ordenes = TiendanubeOrden::where('estado_conversion', EstadoConversion::Lista->value)->get();

        $total = $ordenes->count();
        $convertidas = 0;
        $detalleFallidas = [];

        foreach ($ordenes as $orden) {
            $resultado = $this->convertir($orden, $usuarioId, automatica: false);

            if ($resultado['ok']) {
                $convertidas++;

                continue;
            }

            $detalleFallidas[] = [
                'orden' => $orden->tn_order_id,
                'motivo' => $orden->fresh()->motivo?->etiqueta() ?? $resultado['mensaje'],
                'motivo_detalle' => $orden->fresh()->motivo_detalle,
            ];
        }

        $fallidas = $total - $convertidas;

        return [
            'ok' => true,
            'mensaje' => "{$convertidas} de {$total} órdenes convertidas.",
            'total' => $total,
            'convertidas' => $convertidas,
            'fallidas' => $fallidas,
            'detalle_fallidas' => $detalleFallidas,
        ];
    }

    /** Mismos cortes que `SincronizadorOrdenes::verificarCortes()` (función avanzada + modo sólo lectura). */
    private function verificarCortesBatch(): ?array
    {
        if (! (bool) FuncionAvanzada::where('clave', 'tiendanube')->value('activa')) {
            return ['ok' => false, 'tipo' => 'bloqueada', 'mensaje' => 'La función "Tiendanube" está desactivada en Funciones Avanzadas.'];
        }

        if (TiendanubeConexionRest::actual()->modo_solo_lectura) {
            return ['ok' => false, 'tipo' => 'bloqueada', 'mensaje' => 'Bloqueada por el modo sólo lectura: la conversión está deshabilitada mientras esté activo.'];
        }

        return null;
    }

    /**
     * Vista previa para precargar el formulario de conversión (GET) sin
     * persistir la Venta.
     *
     * @return array{tipo_comprobante: string, aproximado: bool, cliente: ?Cliente, cliente_ambiguo: bool, lineas: array}
     */
    public function previsualizar(TiendanubeOrden $orden): array
    {
        $orden->load('items.producto');

        $resolucionCliente = $this->resolutorCliente->resolver($orden);
        $lineas = $this->armarLineas($orden);

        return [
            'tipo_comprobante' => $resolucionCliente['tipo_comprobante'],
            'aproximado' => $resolucionCliente['aproximado'],
            'cliente' => $resolucionCliente['cliente'],
            'cliente_ambiguo' => $resolucionCliente['ambiguo'],
            'lineas' => $lineas,
        ];
    }

    private function convertirBajoCandado(
        TiendanubeOrden $orden,
        ?int $usuarioId,
        bool $automatica,
        ?int $clienteIdOverride = null,
        ?string $tipoComprobanteOverride = null,
    ): array {
        $orden->refresh(); // revalidación bajo candado (FR-032a): puede haber cambiado desde que se leyó.

        if ($orden->venta_id) {
            return $this->rechazo('Esta orden ya tiene una Venta asociada.', $orden->venta_id);
        }

        // Red de seguridad anti-duplicados (spec 038): sobrevive al borrado+resincronización
        // de la orden, porque busca por el identificador estable del pedido, no por la fila.
        if (Venta::withTrashed()->where('tn_order_id', $orden->tn_order_id)->exists()) {
            return $this->rechazo('Esta orden ya tiene una Venta asociada.');
        }

        $orden->load('items.producto');

        $resolucionCliente = $this->resolutorCliente->resolver($orden);

        // Una corrección manual explícita de Cliente (p. ej. para desambiguar, FR-038) hace que el
        // caso ya no sea ambiguo a los efectos de la evaluación de convertibilidad.
        $clienteFinal = $clienteIdOverride ? Cliente::find($clienteIdOverride) : $resolucionCliente['cliente'];
        $clienteAmbiguo = $clienteIdOverride ? false : $resolucionCliente['ambiguo'];

        [$estado, $motivo, $detalle] = $this->evaluador->evaluar($orden, $clienteAmbiguo);

        if ($estado !== EstadoConversion::Lista) {
            $orden->update(['estado_conversion' => $estado->value, 'motivo' => $motivo?->value, 'motivo_detalle' => $detalle]);

            return $this->rechazo($detalle ?? 'La orden no está lista para convertir.', null, $motivo?->value);
        }

        if (! $clienteFinal) {
            return $this->rechazo('El Cliente indicado no existe.');
        }

        $cuentaTesoreria = $this->cuentaTesoreriaConfigurada();

        if (! $cuentaTesoreria) {
            return $this->rechazo('No hay una cuenta de Tesorería configurada (o está activa) para Tiendanube. Configurala antes de convertir.');
        }

        // clienteIdOverride apunta a un Cliente distinto del que resolvió resolver() (nunca tocado por
        // completarDatosFiscalesSinPisar), así que ahí sí hace falta derivarlo aparte; en el camino normal
        // se reutiliza el valor que resolver() ya calculó ANTES de completar datos fiscales (ver su docblock).
        $tipoComprobante = $tipoComprobanteOverride
            ?: ($clienteIdOverride ? $this->resolutorCliente->tipoComprobante($clienteFinal, $orden) : $resolucionCliente['tipo_comprobante']);

        try {
            $venta = DB::transaction(function () use ($orden, $clienteFinal, $tipoComprobante, $cuentaTesoreria, $usuarioId, $automatica) {
                $lineas = $this->armarLineas($orden);

                $fechaEmision = ($orden->fecha_cerrada ?? $orden->fecha_creada ?? now())->toDateString();

                $venta = Venta::create([
                    'origen' => 'tiendanube',
                    'tn_order_id' => $orden->tn_order_id,
                    'cliente_id' => $clienteFinal->id,
                    'categoria_id' => TiendanubeConexionRest::actual()->categoria_venta_id,
                    'vendedor_id' => TiendanubeConexionRest::actual()->vendedor_id,
                    // Mismo criterio que en Mercado Libre: el depósito del que sale el stock queda
                    // guardado en la Venta, no sólo en el movimiento. Sin esto la Venta queda con
                    // `deposito_id` en NULL y al editarla el selector cae en el primero de la lista.
                    'deposito_id' => TiendanubeConexionRest::actual()->depositoEfectivo()->id,
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
                    $venta->items()->create($item);
                }

                $venta->load('items.producto');
                $this->stockDeVenta->aplicarAlta($venta);

                $this->cobranzas->registrarCobro(
                    $venta,
                    (float) $venta->total,
                    $cuentaTesoreria,
                    Carbon::parse($fechaEmision),
                    'Cobro automático — orden de Tiendanube '.$orden->tn_order_id,
                );

                $orden->update([
                    'venta_id' => $venta->id,
                    'estado_conversion' => EstadoConversion::Convertida->value,
                    'motivo' => null,
                    'motivo_detalle' => null,
                    'creacion_automatica' => $automatica,
                    'convertida_en' => now(),
                    'convertida_por' => $automatica ? null : $usuarioId,
                ]);

                foreach ($orden->items as $index => $ordenItem) {
                    if (! empty($lineas['producto_ids'][$index])) {
                        $ordenItem->update(['producto_id' => $lineas['producto_ids'][$index]]);
                    }
                }

                return $venta;
            });
        } catch (QueryException $e) {
            // Respaldo de FR-032b: el índice único de tn_ordenes.venta_id rechazó un duplicado
            // que el candado, en un caso anormalmente lento, no llegó a prevenir.
            return $this->rechazo('Esta orden ya tiene una Venta asociada.', $orden->fresh()->venta_id);
        }

        return ['ok' => true, 'venta' => $venta];
    }

    /**
     * Desagregación de IVA desde el precio final (FR-030a/FR-030c), mismo
     * mecanismo que Mercado Libre (spec 012): reutiliza `CalculoComprobante`,
     * invirtiendo la relación neto→final al armar cada línea, y absorbe el
     * redondeo ajustando la última.
     *
     * @return array{items: array, subtotal_sin_descuento: float, descuento: float, subtotal_con_descuento: float, total: float, producto_ids: array<int, ?int>}
     */
    private function armarLineas(TiendanubeOrden $orden): array
    {
        $items = $orden->items;
        $productoIds = [];

        $itemsCalculo = $items->map(function (TiendanubeOrdenItem $item) use (&$productoIds) {
            // Resuelve contra la vinculación VIGENTE (no el snapshot de la última sincronización):
            // cubre el caso de vinculación inline recién creada desde este mismo formulario (FR-023).
            $vinculo = TiendanubeVarianteProducto::where('variant_id', $item->variant_id)->first();
            $producto = $vinculo?->producto ?? $item->producto;
            $productoIds[] = $producto?->id;

            $ivaPct = Producto::porcentajeIva($producto?->iva_venta_pct);
            $cantidad = (float) $item->cantidad;
            $netoUnitario = $cantidad > 0
                ? round(((float) $item->total_linea / (1 + $ivaPct / 100)) / $cantidad, 4)
                : 0.0;

            $descripcion = $item->nombre_producto.($item->nombre_variante ? " ({$item->nombre_variante})" : '');

            return [
                'producto_id' => $producto?->id,
                'descripcion' => $descripcion,
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

    /** FR-045/FR-045a, research.md R5: FK configurable, no lookup por nombre fijo. */
    private function cuentaTesoreriaConfigurada(): ?CuentaTesoreria
    {
        $cuenta = TiendanubeConexionRest::actual()->cuentaTesoreria;

        return ($cuenta && $cuenta->visible) ? $cuenta : null;
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
