<?php

namespace App\Services\MercadoLibre;

use App\Enums\MercadoLibre\EstadoConversion;
use App\Enums\MercadoLibre\EstadoOrden;
use App\Models\Cliente;
use App\Models\CuentaTesoreria;
use App\Models\Integraciones\MercadoLibreConfiguracion;
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
    ) {
    }

    /**
     * @param  ?int  $clienteIdOverride  Corrección manual del Cliente resuelto (p. ej. para desambiguar, FR-038).
     * @param  ?string  $tipoComprobanteOverride  Corrección manual del comprobante derivado (FR-043).
     * @return array{ok: bool, venta?: Venta, mensaje?: string, motivo?: string, venta_id?: int}
     */
    public function convertir(
        MercadoLibreOrden $orden,
        ?int $usuarioId,
        bool $automatica = false,
        ?int $clienteIdOverride = null,
        ?string $tipoComprobanteOverride = null,
    ): array {
        $lock = Cache::lock("ml:convertir_orden:{$orden->id}", 30);

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
     * Vista previa para precargar el formulario de conversión (GET) sin
     * persistir la Venta — sí puede resolver/crear el Cliente y completar
     * datos fiscales de la orden, igual que haría la conversión real
     * (FR-036/FR-037/FR-039), porque Contagram resuelve el Cliente como el
     * primer paso del mismo flujo, no como un efecto exclusivo del guardado.
     *
     * @return array{datos_fiscales: array, cliente: ?\App\Models\Cliente, cliente_ambiguo: bool, lineas: array}
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
    ): array {
        $orden->refresh(); // revalidación bajo candado (FR-032a): puede haber cambiado desde que se leyó.

        if ($orden->venta_id) {
            return $this->rechazo('Esta orden ya tiene una Venta asociada.', $orden->venta_id);
        }

        if ($orden->estado_orden === EstadoOrden::Cancelada) {
            return $this->rechazo('La orden está cancelada en Mercado Libre y no puede convertirse.');
        }

        if ($orden->estado_orden !== EstadoOrden::Pagada) {
            return $this->rechazo('La orden todavía no está pagada en Mercado Libre.');
        }

        $orden->load('items.producto');

        $datosFiscales = $this->derivadorComprobante->derivar($orden);
        $resolucionCliente = $this->resolutorCliente->resolver($orden, $datosFiscales);

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

        $cuentaMercadoPago = $this->cuentaMercadoPago();

        if (! $cuentaMercadoPago) {
            return $this->rechazo('No hay una cuenta de Tesorería "Mercado Pago" activa. Creala o reactivala antes de convertir.');
        }

        $tipoComprobante = $tipoComprobanteOverride ?: $datosFiscales['tipo_comprobante'];

        try {
            $venta = DB::transaction(function () use ($orden, $clienteFinal, $tipoComprobante, $cuentaMercadoPago, $usuarioId, $automatica) {
                $lineas = $this->armarLineas($orden);

                $fechaEmision = ($orden->fecha_cerrada ?? $orden->fecha_creada ?? now())->toDateString();

                $venta = Venta::create([
                    'origen' => 'mercadolibre',
                    'cliente_id' => $clienteFinal->id,
                    'categoria_id' => MercadoLibreConfiguracion::actual()->categoria_venta_id,
                    'vendedor_id' => MercadoLibreConfiguracion::actual()->vendedor_id,
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

        return ['ok' => true, 'venta' => $venta];
    }

    /**
     * Desagregación de IVA desde el precio final (FR-030a/FR-030b, research.md
     * §R7): reutiliza CalculoComprobante, invirtiendo la relación neto→final
     * al armar cada línea, y absorbe el redondeo ajustando la última.
     *
     * @return array{items: array, subtotal_sin_descuento: float, descuento: float, subtotal_con_descuento: float, total: float, producto_ids: array<int, ?int>}
     */
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

        $resultado = $this->calculo->calcular($itemsCalculo, 0, []);
        $diferencia = round((float) $orden->total - $resultado['total'], 2);

        if (abs($diferencia) >= 0.01 && count($itemsCalculo) > 0) {
            $ultimoIndice = count($itemsCalculo) - 1;
            $cantidadUltimo = max((float) $itemsCalculo[$ultimoIndice]['cantidad'], 1);
            $itemsCalculo[$ultimoIndice]['precio_unitario'] = round(
                (float) $itemsCalculo[$ultimoIndice]['precio_unitario'] + ($diferencia / $cantidadUltimo),
                2
            );
            $resultado = $this->calculo->calcular($itemsCalculo, 0, []);
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
