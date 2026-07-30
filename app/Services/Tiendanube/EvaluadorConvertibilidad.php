<?php

namespace App\Services\Tiendanube;

use App\Enums\Tiendanube\EstadoConversion;
use App\Enums\Tiendanube\MotivoRequiereAtencion;
use App\Models\Integraciones\TiendanubeConfiguracion;
use App\Models\Integraciones\TiendanubeOrden;
use App\Models\Integraciones\TiendanubeOrdenItem;
use App\Models\Integraciones\TiendanubeVarianteProducto;

/**
 * Único punto que deriva el estado de conversión de una orden de Tiendanube
 * (FR-007a/FR-052): lo usa `SincronizadorOrdenes` en cada corrida y
 * `ConversorOrdenAVenta` en cada intento de conversión, para no duplicar la
 * lógica de qué hace a una orden "Lista para convertir" — mismo rol que
 * `App\Services\MercadoLibre\EvaluadorConvertibilidad`, independiente de él
 * (research.md R6).
 */
class EvaluadorConvertibilidad
{
    public function __construct(private readonly TraductorOrdenes $traductor)
    {
    }

    /**
     * @return array{0: EstadoConversion, 1: ?MotivoRequiereAtencion, 2: ?string}
     */
    public function evaluar(TiendanubeOrden $orden, bool $clienteEsAmbiguo = false): array
    {
        $estadoBase = $this->traductor->estadoBaseDesdeCrudo($orden->status, $orden->payment_status);

        // Cancelada es prioritaria incluso sobre una orden ya convertida (FR-058):
        // la Venta permanece intacta, pero el listado debe reflejar la cancelación.
        if ($estadoBase === EstadoConversion::Cancelada) {
            return [EstadoConversion::Cancelada, null, null];
        }

        // Convertida es terminal salvo por la cancelación de arriba: una Venta ya
        // creada no se "descrea" ni se reevalúa.
        if ($orden->venta_id) {
            return [EstadoConversion::Convertida, null, null];
        }

        if ($estadoBase === EstadoConversion::PendientePago) {
            return [EstadoConversion::PendientePago, null, null];
        }

        if (! $this->traductor->monedaValida($orden->moneda)) {
            return [
                EstadoConversion::RequiereAtencion,
                MotivoRequiereAtencion::MonedaInvalida,
                "La orden está en {$orden->moneda}, distinta de la moneda del negocio.",
            ];
        }

        if (! $this->cuentaTesoreriaConfigurada()) {
            return [
                EstadoConversion::RequiereAtencion,
                MotivoRequiereAtencion::CuentaTesoreriaNoConfigurada,
                'No hay una cuenta de Tesorería configurada (o está inactiva) para Tiendanube.',
            ];
        }

        $items = $orden->relationLoaded('items') ? $orden->items : $orden->items()->get();

        foreach ($items as $item) {
            $motivo = $this->motivoPorItem($item);
            if ($motivo) {
                return [EstadoConversion::RequiereAtencion, $motivo[0], $motivo[1]];
            }
        }

        if ($clienteEsAmbiguo) {
            return [
                EstadoConversion::RequiereAtencion,
                MotivoRequiereAtencion::ClienteAmbiguo,
                'Hay más de un Cliente del CRM con el mismo email. Corregí los emails duplicados.',
            ];
        }

        return [EstadoConversion::Lista, null, null];
    }

    /**
     * @return array{0: MotivoRequiereAtencion, 1: string}|null
     */
    private function motivoPorItem(TiendanubeOrdenItem $item): ?array
    {
        $vinculo = TiendanubeVarianteProducto::where('variant_id', $item->variant_id)->first();

        if (! $vinculo) {
            $descripcion = $item->nombre_producto.($item->nombre_variante ? " ({$item->nombre_variante})" : '');

            return [
                MotivoRequiereAtencion::VarianteSinVincular,
                "La variante {$item->variant_id} ({$descripcion}) no está vinculada a ningún producto.",
            ];
        }

        if (! $vinculo->producto || ! $vinculo->producto->activo) {
            return [
                MotivoRequiereAtencion::ProductoInexistente,
                "El producto vinculado a la variante {$item->variant_id} fue eliminado o inactivado.",
            ];
        }

        return null;
    }

    private function cuentaTesoreriaConfigurada(): bool
    {
        $cuenta = TiendanubeConfiguracion::actual()->cuentaTesoreria;

        return (bool) ($cuenta && $cuenta->visible);
    }
}
