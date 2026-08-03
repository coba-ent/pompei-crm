<?php

namespace App\Services\Arca;

use Carbon\Carbon;

/**
 * Traduce los datos normalizados de Venta/Compra/NC-ND del CRM al formato `FeCAEReq` esperado por
 * `FECAESolicitar` de WSFEv1.
 */
class MapeadorComprobante
{
    /** Códigos `CbteTipo` de WSFEv1 para comprobante "normal" (Factura). */
    private const CBTE_TIPO_FACTURA = ['A' => 1, 'B' => 6, 'C' => 11];

    /** Códigos `CbteTipo` de WSFEv1 para Nota de Crédito. */
    private const CBTE_TIPO_NOTA_CREDITO = ['A' => 3, 'B' => 8, 'C' => 13];

    /** Códigos `CbteTipo` de WSFEv1 para Nota de Débito. */
    private const CBTE_TIPO_NOTA_DEBITO = ['A' => 2, 'B' => 7, 'C' => 12];

    public function cbteTipo(string $tipoComprobante, ?string $tipoNota = null): int
    {
        $tabla = match ($tipoNota) {
            'credito' => self::CBTE_TIPO_NOTA_CREDITO,
            'debito' => self::CBTE_TIPO_NOTA_DEBITO,
            default => self::CBTE_TIPO_FACTURA,
        };

        return $tabla[$tipoComprobante] ?? throw new \InvalidArgumentException("Tipo de comprobante desconocido: {$tipoComprobante}");
    }

    /**
     * @param  array{
     *     tipo_comprobante: string,
     *     tipo_nota?: string|null,
     *     punto_venta: int,
     *     numero: int,
     *     fecha: string|Carbon,
     *     cliente: array{cuit?: string|null, dni?: string|null},
     *     neto: float,
     *     iva: float,
     *     total: float,
     *     alicuota_iva_id?: int,
     *     comprobante_ajustado?: array{tipo: int, punto_venta: int, numero: int}|null,
     * }  $datos
     */
    public function mapear(array $datos): array
    {
        $cbteTipo = $this->cbteTipo($datos['tipo_comprobante'], $datos['tipo_nota'] ?? null);
        $fecha = Carbon::parse($datos['fecha'])->format('Ymd');
        [$docTipo, $docNro] = $this->documentoReceptor($datos['cliente'] ?? []);

        $detalle = [
            'Concepto' => 1,
            'DocTipo' => $docTipo,
            'DocNro' => $docNro,
            'CbteDesde' => $datos['numero'],
            'CbteHasta' => $datos['numero'],
            'CbteFch' => $fecha,
            'ImpTotal' => round((float) $datos['total'], 2),
            'ImpTotConc' => 0,
            'ImpNeto' => round((float) $datos['neto'], 2),
            'ImpOpEx' => 0,
            'ImpIVA' => round((float) $datos['iva'], 2),
            'ImpTrib' => 0,
            'MonId' => 'PES',
            'MonCotiz' => 1,
        ];

        if ((float) $datos['iva'] > 0) {
            $detalle['Iva'] = [
                'AlicIva' => [
                    'Id' => $datos['alicuota_iva_id'] ?? 5,
                    'BaseImp' => round((float) $datos['neto'], 2),
                    'Importe' => round((float) $datos['iva'], 2),
                ],
            ];
        }

        if (! empty($datos['comprobante_ajustado'])) {
            $detalle['CbtesAsoc'] = [
                'CbteAsoc' => [
                    'Tipo' => $datos['comprobante_ajustado']['tipo'],
                    'PtoVta' => $datos['comprobante_ajustado']['punto_venta'],
                    'Nro' => $datos['comprobante_ajustado']['numero'],
                ],
            ];
        }

        return [
            'FeCabReq' => [
                'CantReg' => 1,
                'PtoVta' => $datos['punto_venta'],
                'CbteTipo' => $cbteTipo,
            ],
            'FeDetReq' => [
                'FECAEDetRequest' => $detalle,
            ],
        ];
    }

    /** @return array{0: int, 1: string} [DocTipo, DocNro] — 80=CUIT, 96=DNI, 99=Consumidor Final sin identificar. */
    private function documentoReceptor(array $cliente): array
    {
        if (! empty($cliente['cuit'])) {
            return [80, preg_replace('/\D/', '', $cliente['cuit'])];
        }

        if (! empty($cliente['dni'])) {
            return [96, preg_replace('/\D/', '', $cliente['dni'])];
        }

        return [99, '0'];
    }
}
