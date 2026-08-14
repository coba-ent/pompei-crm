<?php

namespace App\Services\MercadoLibre;

use App\Enums\MercadoLibre\EstadoOrden;
use Carbon\Carbon;

/**
 * Único punto que interpreta el formato externo de una orden de Mercado Libre
 * (research.md §R3): ningún controlador, job ni modelo lee directamente los
 * campos crudos del proveedor. Mapea a los arrays de atributos listos para
 * `MercadoLibreOrden`/`MercadoLibreOrdenItem` (contracts §5, data-model.md §2/§3).
 */
class TraductorOrdenes
{
    public const MONEDA_NEGOCIO = 'ARS';

    /**
     * @param  array<string, mixed>  $ordenCruda  Respuesta de `GET /orders/search` o `GET /orders/{id}`.
     * @return array<string, mixed> Atributos para `MercadoLibreOrden` (sin `estado_conversion`, que decide SincronizadorOrdenes).
     */
    public function traducirOrden(array $ordenCruda, ?string $datosFaltantes = null): array
    {
        $tags = $ordenCruda['tags'] ?? [];
        $buyer = $ordenCruda['buyer'] ?? [];

        return [
            'ml_order_id' => (string) $ordenCruda['id'],
            'estado_ml' => (string) ($ordenCruda['status'] ?? ''),
            'estado_orden' => EstadoOrden::desdeCrudo((string) ($ordenCruda['status'] ?? ''))->value,
            // ML devuelve estas fechas con offset propio (ej. "-04:00"), no en UTC:
            // hay que convertirlas explícitamente antes de que el cast `datetime`
            // del modelo las persista, si no se graba la hora local como si fuera UTC.
            'fecha_creada' => isset($ordenCruda['date_created']) ? Carbon::parse($ordenCruda['date_created'])->utc() : null,
            'fecha_cerrada' => isset($ordenCruda['date_closed']) ? Carbon::parse($ordenCruda['date_closed'])->utc() : null,
            'total' => (float) ($ordenCruda['total_amount'] ?? 0),
            'moneda' => (string) ($ordenCruda['currency_id'] ?? self::MONEDA_NEGOCIO),
            'comprador_ml_id' => (string) ($buyer['id'] ?? ''),
            'comprador_apodo' => $buyer['nickname'] ?? null,
            'comprador_nombre' => $this->nombreComprador($buyer),
            'billing_info_id' => $buyer['billing_info']['id'] ?? null,
            'es_prueba' => in_array('test_order', $tags, true),
            'tiene_alerta_fraude' => in_array('fraud_risk_detected', $tags, true),
            'datos_faltantes' => $datosFaltantes,
            'payload' => $ordenCruda,
        ];
    }

    /**
     * @param  array<string, mixed>  $ordenCruda
     * @return array<int, array<string, mixed>> Atributos para `MercadoLibreOrdenItem` (sin `ml_orden_id`).
     */
    public function traducirItems(array $ordenCruda): array
    {
        $items = [];

        foreach ($ordenCruda['order_items'] ?? [] as $ordenItem) {
            $item = $ordenItem['item'] ?? [];
            $cantidad = (float) ($ordenItem['quantity'] ?? 0);
            $precioUnitario = (float) ($ordenItem['unit_price'] ?? 0);

            $items[] = [
                'ml_item_id' => (string) ($item['id'] ?? ''),
                'ml_variation_id' => filled($item['variation_id'] ?? null) ? (string) $item['variation_id'] : null,
                'titulo' => (string) ($item['title'] ?? ''),
                'sku_vendedor' => $item['seller_sku'] ?? $item['seller_custom_field'] ?? null,
                'cantidad' => $cantidad,
                'precio_unitario' => $precioUnitario,
                'precio_bruto' => isset($ordenItem['gross_price']) ? (float) $ordenItem['gross_price'] : null,
                'comision_ml' => isset($ordenItem['sale_fee']) ? (float) $ordenItem['sale_fee'] : null,
                'total_linea' => round($cantidad * $precioUnitario, 2),
            ];
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $billingInfo  Respuesta de `GET /orders/billing-info/{SITE_ID}/{ID}`.
     * @return array<string, mixed> Datos fiscales del comprador (research.md §R8).
     */
    public function traducirDatosFiscales(array $billingInfo): array
    {
        // Los datos vienen anidados bajo `buyer.billing_info`, no en la raíz de la respuesta.
        // Leerlos de la raíz devolvía null en los tres campos —sin error, por los `??`—, así que el
        // comprador quedaba sin CUIT ni condición de IVA y toda venta de ML se derivaba a Factura B
        // aunque fuera Responsable Inscripto (incidente 14/08/2026, orden 2000017931860790). Se
        // mantiene la raíz como fallback por si la API responde en formato plano.
        $datos = $billingInfo['buyer']['billing_info'] ?? $billingInfo;

        return [
            'comprador_doc_tipo' => $datos['identification']['type'] ?? null,
            'comprador_doc_numero' => $datos['identification']['number'] ?? null,
            'comprador_condicion_iva' => $datos['taxes']['taxpayer_type']['description'] ?? null,
        ];
    }

    /**
     * Razón social y domicilio fiscal del comprador, de la misma respuesta de billing-info. No se
     * persisten en la orden (no hay columnas): los consume el Cliente al darse de alta.
     *
     * @param  array<string, mixed>  $billingInfo  Respuesta de `GET /orders/billing-info/{SITE_ID}/{ID}`.
     * @return array{razon_social: ?string, domicilio_fiscal: ?string, localidad_fiscal: ?string, provincia_fiscal: ?string}
     */
    public function traducirDomicilioFiscal(array $billingInfo): array
    {
        $datos = $billingInfo['buyer']['billing_info'] ?? $billingInfo;
        $direccion = $datos['address'] ?? [];

        return [
            'razon_social' => $datos['name'] ?? null,
            'domicilio_fiscal' => $direccion['street_name'] ?? null,
            'localidad_fiscal' => $direccion['city_name'] ?? null,
            'provincia_fiscal' => $direccion['state']['name'] ?? null,
        ];
    }

    /** FR-027: al menos un ítem con publicación con variantes, no soportado. */
    public function tieneItemConVariante(array $itemsTraducidos): bool
    {
        foreach ($itemsTraducidos as $item) {
            if (filled($item['ml_variation_id'])) {
                return true;
            }
        }

        return false;
    }

    /** FR-052a: Mercado Libre marcó la orden con riesgo de fraude — bloquea manual y automática. */
    public function tieneAlertaFraude(array $ordenTraducida): bool
    {
        return (bool) $ordenTraducida['tiene_alerta_fraude'];
    }

    /** FR-030d: la conversión sólo admite la moneda del negocio. */
    public function monedaValida(array $ordenTraducida): bool
    {
        return $ordenTraducida['moneda'] === self::MONEDA_NEGOCIO;
    }

    /**
     * Estados de los pagos de la orden (`payments[].status`), que hoy se descartaban.
     * La mediación no aparece en el estado de la orden, sólo acá (spec 063 / research.md §R3).
     *
     * @param  array<string, mixed>  $ordenCruda
     * @return array<int, string>
     */
    public function estadoPagos(array $ordenCruda): array
    {
        $pagos = $ordenCruda['payments'] ?? [];

        return array_values(array_filter(array_map(
            static fn (array $pago) => (string) ($pago['status'] ?? ''),
            $pagos
        )));
    }

    /** FR-004: la mediación se detecta por el estado de los pagos, no por el de la orden. */
    public function tieneMediacion(array $ordenCruda): bool
    {
        return in_array('in_mediation', $this->estadoPagos($ordenCruda), true);
    }

    /**
     * Importe reembolsado informado por el marketplace, si viene en algún pago
     * (`transaction_amount_refunded`). FR-004a: si no viene informado, null —
     * el aviso debe mostrarse igual, dejando constancia de que no vino el dato.
     *
     * @param  array<string, mixed>  $ordenCruda
     */
    public function importeReembolsado(array $ordenCruda): ?float
    {
        $pagos = $ordenCruda['payments'] ?? [];
        $total = null;

        foreach ($pagos as $pago) {
            if (isset($pago['transaction_amount_refunded']) && (float) $pago['transaction_amount_refunded'] > 0) {
                $total = ($total ?? 0) + (float) $pago['transaction_amount_refunded'];
            }
        }

        return $total;
    }

    private function nombreComprador(array $buyer): ?string
    {
        $billing = $buyer['billing_info'] ?? [];
        $nombre = trim(($billing['name'] ?? $buyer['first_name'] ?? '').' '.($billing['last_name'] ?? $buyer['last_name'] ?? ''));

        return $nombre !== '' ? $nombre : null;
    }
}
