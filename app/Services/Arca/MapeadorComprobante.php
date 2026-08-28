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

    /**
     * Códigos `AlicIva.Id` de WSFEv1 (`FEParamGetTiposIva`) por alícuota de IVA. Claves como string
     * para evitar el truncamiento a entero que PHP aplica a claves de array de tipo float (10.5 → 10).
     */
    public const ALICUOTAS_IVA = [
        '0' => 3,
        '10.5' => 4,
        '21' => 5,
        '27' => 6,
        '5' => 8,
        '2.5' => 9,
    ];

    /** Código `CondicionIVAReceptorId` por defecto para receptor sin CUIT/DNI identificado (Consumidor Final). */
    private const CONDICION_IVA_CONSUMIDOR_FINAL = 5;

    /** Código `DocTipo` de ARCA por `tipo_documento` del cliente (tabla de tipos de documento de WSFEv1). */
    private const DOC_TIPOS = [
        'CUIT' => 80,
        'CUIL' => 86,
        'CDI' => 87,
        'Pasaporte' => 94,
        'DNI' => 96,
    ];

    /** `DocTipo` de ARCA para documento sin identificar (spec 086, research §6). */
    public const DOC_TIPO_SIN_IDENTIFICAR = 99;

    /** @return int|null Código ARCA para la alícuota, o null si no está soportada (FR-004). */
    public function codigoAlicuotaIva(float $ivaPct): ?int
    {
        return self::ALICUOTAS_IVA[self::formatearAlicuota($ivaPct)] ?? null;
    }

    /** Normaliza una alícuota float a la representación string usada como clave en ALICUOTAS_IVA. */
    private static function formatearAlicuota(float $ivaPct): string
    {
        return rtrim(rtrim(number_format($ivaPct, 1, '.', ''), '0'), '.');
    }

    /**
     * Devuelve el código ARCA de Condición de IVA del receptor: el informado, o Consumidor Final (5)
     * por defecto si el receptor no tiene CUIT/DNI identificado (research.md §4, FR-007).
     */
    public function resolverCondicionIvaReceptor(?string $condicionIvaCodigo, array $cliente): int
    {
        if ($condicionIvaCodigo !== null) {
            return (int) $condicionIvaCodigo;
        }

        return self::CONDICION_IVA_CONSUMIDOR_FINAL;
    }

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
     *     items?: array<int, array{neto: float, iva_pct: float}>,
     *     comprobante_ajustado?: array{tipo: int, punto_venta: int, numero: int}|null,
     * }  $datos
     */
    public function mapear(array $datos): array
    {
        $cbteTipo = $this->cbteTipo($datos['tipo_comprobante'], $datos['tipo_nota'] ?? null);
        $fecha = Carbon::parse($datos['fecha'])->format('Ymd');
        $cliente = $datos['cliente'] ?? [];
        [$docTipo, $docNro] = $this->documentoReceptor($cliente);

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
            'CondicionIVAReceptorId' => $this->resolverCondicionIvaReceptor($cliente['condicion_iva_codigo'] ?? null, $cliente),
        ];

        if ((float) $datos['iva'] > 0) {
            $detalle['Iva'] = ['AlicIva' => $this->armarBloquesAlicIva($datos)];
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

    /**
     * Arma uno o más bloques `AlicIva` a partir de los ítems reales (agrupados por alícuota), o un
     * único bloque con el comportamiento anterior (`alicuota_iva_id` o 21% por defecto) si no hay
     * `items` — caso NC/ND, que no tiene desglose propio (research.md §1).
     *
     * @return array<string, mixed>|array<int, array<string, mixed>> Objeto único si hay una sola
     *     alícuota, array de objetos si hay más de una (contracts/solicitud-cae.md).
     */
    private function armarBloquesAlicIva(array $datos): array
    {
        if (empty($datos['items'])) {
            return [
                'Id' => $datos['alicuota_iva_id'] ?? 5,
                'BaseImp' => round((float) $datos['neto'], 2),
                'Importe' => round((float) $datos['iva'], 2),
            ];
        }

        $grupos = [];
        foreach ($datos['items'] as $item) {
            $ivaPct = (float) $item['iva_pct'];
            $clave = self::formatearAlicuota($ivaPct);
            $neto = (float) $item['neto'];
            $grupos[$clave]['iva_pct'] = $ivaPct;
            $grupos[$clave]['neto'] = ($grupos[$clave]['neto'] ?? 0) + $neto;
            $grupos[$clave]['iva'] = ($grupos[$clave]['iva'] ?? 0) + round($neto * $ivaPct / 100, 2);
        }

        $bloques = [];
        foreach ($grupos as $montos) {
            $bloques[] = [
                'Id' => $this->codigoAlicuotaIva($montos['iva_pct']) ?? throw new \InvalidArgumentException("Alícuota de IVA no soportada por ARCA: {$montos['iva_pct']}%"),
                'BaseImp' => round($montos['neto'], 2),
                'Importe' => round($montos['iva'], 2),
            ];
        }

        return count($bloques) === 1 ? $bloques[0] : $bloques;
    }

    /** @return array{0: int, 1: string} [DocTipo, DocNro] — 99=Consumidor Final sin identificar. */
    public function documentoReceptor(array $cliente): array
    {
        // El cliente guarda todos los documentos en una misma columna (`clientes.cuit`) y es
        // `tipo_documento` quien determina el DocTipo de ARCA. Mandar el número sin mirar el tipo
        // hace que un DNI viaje como DocTipo 80 y ARCA lo rechace buscándolo en el padrón de CUIT.
        $tipo = $cliente['tipo_documento'] ?? null;
        $numero = preg_replace('/\D/', '', (string) ($cliente['documento'] ?? ''));

        if ($numero !== '' && isset(self::DOC_TIPOS[$tipo])) {
            return [self::DOC_TIPOS[$tipo], $numero];
        }

        if (! empty($cliente['cuit'])) {
            return [self::DOC_TIPOS['CUIT'], preg_replace('/\D/', '', $cliente['cuit'])];
        }

        if (! empty($cliente['dni'])) {
            return [self::DOC_TIPOS['DNI'], preg_replace('/\D/', '', $cliente['dni'])];
        }

        return [self::DOC_TIPO_SIN_IDENTIFICAR, '0'];
    }

    /**
     * Documento del vendedor (proveedor) para IVA Digital Compras (spec 086, FR-020): mismo criterio
     * que {@see documentoReceptor}, aplicado al CUIT del proveedor — hoy siempre identificado por
     * CUIT en el CRM, pero cae a "sin identificar" si faltara.
     *
     * @return array{0: int, 1: string} [DocTipo, DocNro]
     */
    public function documentoVendedor(?string $cuit): array
    {
        $numero = preg_replace('/\D/', '', (string) $cuit);

        if ($numero === '') {
            return [self::DOC_TIPO_SIN_IDENTIFICAR, '0'];
        }

        return [self::DOC_TIPOS['CUIT'], $numero];
    }
}
