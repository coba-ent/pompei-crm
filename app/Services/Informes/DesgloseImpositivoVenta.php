<?php

namespace App\Services\Informes;

/**
 * Desglose impositivo AFIP de una Venta (spec 076, data-model.md §3), sólo para el export
 * detallado (US2). Espeja deliberadamente a {@see DesgloseImpositivoCompra}: los netos y las
 * alícuotas se derivan igual, de `venta_items.iva_pct`.
 *
 * Diferencia clave con Compras: acá los conceptos extra (`venta_conceptos` — percepciones e
 * impuestos internos) se **prorratean por línea** en proporción a su neto (contrato
 * `export-detallado.md §3`, columnas 37-39), en vez de repetirse enteros en cada fila del
 * comprobante. Es el mismo criterio de prorrateo que ya usa `VentasInformeQuery` para el importe
 * de línea (data-model §2), aplicado ahora por categoría de concepto.
 *
 * Invariante fiscal (constitución III):
 *   Neto Gravado + Neto No Gravado + Neto Exento + Σ IVA por alícuota
 *   + Perc. IVA + Perc. IIBB + Imp. Internos (ya prorrateados) = Total Venta de la línea
 */
class DesgloseImpositivoVenta
{
    /** @var array<string, string> alícuota (tal cual se guarda en `iva_pct`) => clave de columna */
    public const ALICUOTAS = [
        '2.5' => 'iva_2_5',
        '5' => 'iva_5',
        '10.5' => 'iva_10_5',
        '21' => 'iva_21',
        '27' => 'iva_27',
    ];

    public const NO_GRAVADO = 'no_gravado';

    public const EXENTO = 'exento';

    /** @var array<string, list<string>> columna => palabras que la disparan, evaluadas en orden (IIBB primero) */
    private const PALABRAS_PERCEPCION = [
        'perc_iibb' => ['iibb', 'ingresos brutos', 'ing. brutos', 'ing brutos'],
        'perc_iva' => ['iva'],
    ];

    /**
     * Neto de una línea para una de las tres clases (data-model §3), a partir de expresiones SQL
     * de neto y de `iva_pct` — no de un alias fijo: los ítems de venta guardan `subtotal` en
     * columna, pero los de nota lo reconstruyen (`VentasInformeQuery::sqlNetoNota()`), así que la
     * expresión de neto la aporta quien llama.
     *
     * @param  string  $clase  'gravado' | 'no_gravado' | 'exento'
     */
    public function sqlNeto(string $clase, string $netoExpr, string $ivaPctExpr): string
    {
        $alicuotas = $this->listaSqlAlicuotas();

        $condicion = match ($clase) {
            'gravado' => "{$ivaPctExpr} IN ({$alicuotas})",
            'exento' => "{$ivaPctExpr} = '".self::EXENTO."'",
            // Todo lo que no es una alícuota conocida ni "exento" es No Gravado: NULL, la cadena
            // 'no_gravado' y cualquier valor no reconocido (FR-011a). Ninguna línea queda afuera.
            default => "({$ivaPctExpr} IS NULL OR ({$ivaPctExpr} NOT IN ({$alicuotas}) AND {$ivaPctExpr} <> '".self::EXENTO."'))",
        };

        return "(CASE WHEN {$condicion} THEN ({$netoExpr}) ELSE 0 END)";
    }

    /**
     * IVA de una línea para una alícuota puntual.
     *
     * Si se pasa `$conIvaExpr` (ítems de venta, que guardan `subtotal_con_iva`), se usa la
     * diferencia realmente grabada, con su redondeo. Si no (ítems de nota, que no guardan esa
     * columna), se recalcula `neto × alícuota / 100`.
     */
    public function sqlIva(string $alicuota, string $netoExpr, string $ivaPctExpr, ?string $conIvaExpr = null): string
    {
        $literal = "'".addslashes($alicuota)."'";

        $importe = $conIvaExpr !== null
            ? "COALESCE({$conIvaExpr}, {$netoExpr}) - ({$netoExpr})"
            // `* 1.0` para no caer en división entera de SQLite (mismo gotcha de siempre).
            : "({$netoExpr}) * {$literal} * 1.0 / 100.0";

        return "(CASE WHEN {$ivaPctExpr} = {$literal} THEN {$importe} ELSE 0 END)";
    }

    private function listaSqlAlicuotas(): string
    {
        return collect(array_keys(self::ALICUOTAS))
            ->map(fn (string $a) => "'".addslashes($a)."'")
            ->implode(', ');
    }

    /**
     * Prorrateo por línea de los conceptos extra de tipo `percepcion` que clasifican como Perc.
     * IVA o Perc. IIBB, y de los de tipo `impuesto_interno`, en proporción al neto de la línea —
     * mismo criterio y misma técnica (residuo en la última línea vía funciones de ventana) que
     * {@see \App\Services\Informes\VentasInformeQuery::sqlProrateoConceptos()}.
     *
     * @param  string  $columna  'perc_iva' | 'perc_iibb' | 'imp_internos'
     */
    public function sqlConceptoProrateado(string $columna, string $itemAlias = 'venta_items'): string
    {
        $condicionTipo = match ($columna) {
            'imp_internos' => "vc.tipo = 'impuesto_interno'",
            'perc_iva' => "vc.tipo = 'percepcion' AND (".$this->sqlMatchPercepcion('perc_iva').
                ') AND NOT ('.$this->sqlMatchPercepcion('perc_iibb').')',
            'perc_iibb' => "vc.tipo = 'percepcion' AND (".$this->sqlMatchPercepcion('perc_iibb').')',
            default => throw new \InvalidArgumentException("Columna de concepto desconocida: {$columna}"),
        };

        $conceptoTotal = "COALESCE((SELECT SUM(vc.monto) FROM venta_conceptos vc ".
            "WHERE vc.venta_id = ventas.id AND ({$condicionTipo})), 0)";

        $netoComprobante = '(SELECT COALESCE(SUM(vi2.subtotal), 0) FROM venta_items vi2 '.
            'WHERE vi2.venta_id = ventas.id)';

        $cantidadLineas = '(SELECT COUNT(*) FROM venta_items vi3 WHERE vi3.venta_id = ventas.id)';

        // `* 1.0 /` y no `/`: con enteros SQLite hace división entera (mismo gotcha documentado
        // en VentasInformeQuery).
        $ratio = "(CASE WHEN {$netoComprobante} <> 0 THEN {$itemAlias}.subtotal * 1.0 / {$netoComprobante} ".
            "ELSE 1.0 / NULLIF({$cantidadLineas}, 0) END)";

        $shareRedondeado = "ROUND({$conceptoTotal} * {$ratio}, 2)";
        $sumaShares = "SUM({$shareRedondeado}) OVER (PARTITION BY ventas.id)";
        $maxId = "MAX({$itemAlias}.id) OVER (PARTITION BY ventas.id)";
        $residuo = "(CASE WHEN {$itemAlias}.id = {$maxId} THEN ({$conceptoTotal} - ({$sumaShares})) ELSE 0 END)";

        return "(({$shareRedondeado}) + ({$residuo}))";
    }

    private function sqlMatchPercepcion(string $columna): string
    {
        return collect(self::PALABRAS_PERCEPCION[$columna])
            ->map(fn (string $p) => ExpresionSql::contienePalabra('vc.concepto', $p))
            ->implode(' OR ');
    }
}
