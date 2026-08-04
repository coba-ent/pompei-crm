<?php

namespace App\Services\Arca;

/** Valida los datos fiscales mínimos requeridos según Tipo de Comprobante (FR-009). */
class ValidadorDatosFiscales
{
    /** Tolerancia de redondeo, en pesos, al comparar importes de IVA/neto contra la suma por alícuota (FR-003/FR-004). */
    private const TOLERANCIA_IMPORTE = 0.01;

    public function __construct(
        private readonly MapeadorComprobante $mapeador = new MapeadorComprobante(),
    ) {
    }

    /**
     * @param  array{cuit?: string|null, dni?: string|null, condicion_iva_codigo?: string|null}  $cliente
     * @param  array<int, array{neto: float, iva_pct: float}>|null  $items
     * @return string|null Motivo de rechazo, o null si es válido.
     */
    public function validar(string $tipoComprobante, array $cliente, ?array $items = null, ?float $neto = null, ?float $iva = null): ?string
    {
        if ($tipoComprobante === 'A' && empty($cliente['cuit'])) {
            return 'El cliente debe tener CUIT cargado para emitir Factura A.';
        }

        if ($tipoComprobante === 'A' && ! $this->cuitValido($cliente['cuit'])) {
            return 'El CUIT del cliente no tiene un formato válido para emitir Factura A.';
        }

        if ((! empty($cliente['cuit']) || ! empty($cliente['dni'])) && empty($cliente['condicion_iva_codigo'])) {
            return 'El cliente no tiene Condición de IVA cargada — cargala en Base de Datos → Clientes antes de enviar.';
        }

        if ($items !== null) {
            $motivo = $this->validarItems($items, $neto, $iva);
            if ($motivo !== null) {
                return $motivo;
            }
        }

        return null;
    }

    private function validarItems(array $items, ?float $neto, ?float $iva): ?string
    {
        $sumaNeto = 0.0;
        $sumaIva = 0.0;

        foreach ($items as $item) {
            $ivaPct = (float) $item['iva_pct'];
            $itemNeto = (float) $item['neto'];

            if ($this->mapeador->codigoAlicuotaIva($ivaPct) === null) {
                return "El ítem tiene una alícuota de IVA ({$ivaPct}%) no soportada por ARCA.";
            }

            $sumaNeto += $itemNeto;
            $sumaIva += round($itemNeto * $ivaPct / 100, 2);
        }

        if ($neto !== null && abs($sumaNeto - $neto) > self::TOLERANCIA_IMPORTE) {
            return 'El IVA calculado no coincide con la suma por alícuota — revisar los ítems de la Venta.';
        }

        if ($iva !== null && abs($sumaIva - $iva) > self::TOLERANCIA_IMPORTE) {
            return 'El IVA calculado no coincide con la suma por alícuota — revisar los ítems de la Venta.';
        }

        return null;
    }

    private function cuitValido(string $cuit): bool
    {
        return (bool) preg_match('/^\d{11}$/', preg_replace('/\D/', '', $cuit));
    }
}
