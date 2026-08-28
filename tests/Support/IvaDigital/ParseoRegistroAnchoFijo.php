<?php

namespace Tests\Support\IvaDigital;

/**
 * Helper de test (T002, spec 086): descompone una línea de ancho fijo en sus campos con nombre,
 * según una tabla de layout `[nombre => [desde, ancho]]` — espejo de las tablas de research.md §1.
 * Es la herramienta que hace legibles los tests posicionales: sin esto, comparar 266 columnas a
 * mano es ilegible.
 */
class ParseoRegistroAnchoFijo
{
    /** Comprobantes Ventas — 266 caracteres. */
    public const LAYOUT_COMPROBANTES_VENTAS = [
        'fecha_comprobante' => [0, 8],
        'tipo_comprobante' => [8, 3],
        'punto_venta' => [11, 5],
        'numero_desde' => [16, 20],
        'numero_hasta' => [36, 20],
        'doc_tipo_comprador' => [56, 2],
        'doc_nro_comprador' => [58, 20],
        'denominacion_comprador' => [78, 30],
        'importe_total' => [108, 15],
        'no_integra_neto_gravado' => [123, 15],
        'perc_no_categorizados' => [138, 15],
        'operaciones_exentas' => [153, 15],
        'perc_iva' => [168, 15],
        'perc_iibb' => [183, 15],
        'perc_municipales' => [198, 15],
        'imp_internos' => [213, 15],
        'moneda' => [228, 3],
        'tipo_cambio' => [231, 10],
        'cantidad_alicuotas' => [241, 1],
        'codigo_operacion' => [242, 1],
        'otros_tributos' => [243, 15],
        'fecha_vto_pago' => [258, 8],
    ];

    /** Alícuotas Ventas — 62 caracteres. */
    public const LAYOUT_ALICUOTAS_VENTAS = [
        'tipo_comprobante' => [0, 3],
        'punto_venta' => [3, 5],
        'numero_comprobante' => [8, 20],
        'importe_neto_gravado' => [28, 15],
        'alicuota' => [43, 4],
        'impuesto_liquidado' => [47, 15],
    ];

    /** Comprobantes Compras — 325 caracteres. */
    public const LAYOUT_COMPROBANTES_COMPRAS = [
        'fecha_comprobante' => [0, 8],
        'tipo_comprobante' => [8, 3],
        'punto_venta' => [11, 5],
        'numero_comprobante' => [16, 20],
        'despacho_importacion' => [36, 16],
        'doc_tipo_vendedor' => [52, 2],
        'doc_nro_vendedor' => [54, 20],
        'denominacion_vendedor' => [74, 30],
        'importe_total' => [104, 15],
        'no_integra_neto_gravado' => [119, 15],
        'operaciones_exentas' => [134, 15],
        'perc_iva' => [149, 15],
        'perc_otros_impuestos_nacionales' => [164, 15],
        'perc_iibb' => [179, 15],
        'perc_municipales' => [194, 15],
        'imp_internos' => [209, 15],
        'moneda' => [224, 3],
        'tipo_cambio' => [227, 10],
        'cantidad_alicuotas' => [237, 1],
        'codigo_operacion' => [238, 1],
        'credito_fiscal_computable' => [239, 15],
        'otros_tributos' => [254, 15],
        'cuit_emisor_terceros' => [269, 11],
        'denominacion_emisor_terceros' => [280, 30],
        'iva_comision' => [310, 15],
    ];

    /** Alícuotas Compras — 84 caracteres. */
    public const LAYOUT_ALICUOTAS_COMPRAS = [
        'tipo_comprobante' => [0, 3],
        'punto_venta' => [3, 5],
        'numero_comprobante' => [8, 20],
        'doc_tipo_vendedor' => [28, 2],
        'doc_nro_vendedor' => [30, 20],
        'importe_neto_gravado' => [50, 15],
        'alicuota' => [65, 4],
        'impuesto_liquidado' => [69, 15],
    ];

    /**
     * @param  array<string, array{0: int, 1: int}>  $layout
     * @return array<string, string>
     */
    public static function parsear(string $linea, array $layout): array
    {
        $campos = [];

        foreach ($layout as $nombre => [$desde, $ancho]) {
            $campos[$nombre] = substr($linea, $desde, $ancho);
        }

        return $campos;
    }

    /** Lee un fixture en latin-1, separa por CRLF, y devuelve las líneas no vacías (sin decodificar a UTF-8: se compara byte a byte). */
    public static function leerLineas(string $rutaArchivo): array
    {
        $contenido = file_get_contents($rutaArchivo);

        return array_values(array_filter(explode("\r\n", $contenido), fn ($l) => $l !== ''));
    }
}
