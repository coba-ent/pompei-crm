<?php

namespace App\Services\Informes;

/**
 * Desglose impositivo AFIP de una Compra (spec 067, data-model.md §4).
 *
 * El modelo de datos no guarda el desglose: se **deriva** de `compra_items.iva_pct` (netos e
 * IVA por alícuota) y de `compra_conceptos` (percepciones e impuestos internos). Toda esa
 * derivación vive acá y en ningún otro lado, para que la pantalla, el Excel y el PDF muestren
 * exactamente los mismos números y un cambio de criterio se haga una sola vez.
 *
 * Invariante fiscal que cubren los tests (constitución III):
 *   Neto Gravado + Neto No Gravado + Neto Exento + Σ IVA por alícuota
 *   + Σ percepciones + Imp. Internos + intereses = Total Compra
 */
class DesgloseImpositivoCompra
{
    /**
     * Alícuotas con columna propia en el informe, en el orden en que se muestran.
     *
     * `2.5` no es una opción que el CRM ofrezca hoy al cargar una compra
     * (`Producto::OPCIONES_IVA` va de 5 a 27), pero sí existe en ARCA y puede aparecer en
     * datos migrados de Contagram. La columna se calcula igual: si no hay ítems con esa
     * alícuota da 0, y el día que aparezcan no se pierden dentro de otra columna.
     *
     * @var array<string, string> alícuota (tal cual se guarda en `iva_pct`) => clave de columna
     */
    public const ALICUOTAS = [
        '2.5' => 'iva_2_5',
        '5' => 'iva_5',
        '10.5' => 'iva_10_5',
        '21' => 'iva_21',
        '27' => 'iva_27',
    ];

    /**
     * Marcadores de `iva_pct` que NO son una alícuota: el ítem no tributa.
     *
     * `no_gravado` y `NULL` son lo mismo a estos efectos — data-model.md §2 documenta sólo el
     * `NULL`, pero el CRM guarda la cadena `'no_gravado'` cuando el usuario la elige
     * explícitamente en el alta de la compra, y ambas tienen que caer en Neto No Gravado.
     */
    public const NO_GRAVADO = 'no_gravado';

    public const EXENTO = 'exento';

    /**
     * Palabras que deciden a qué columna va una percepción, evaluadas **en orden**.
     *
     * IIBB va primero a propósito: "Percepción IIBB s/ IVA" contiene las dos palabras y es una
     * percepción de Ingresos Brutos, no de IVA.
     *
     * @var array<string, list<string>> columna => palabras que la disparan
     */
    private const PALABRAS_PERCEPCION = [
        'perc_iibb' => ['iibb', 'ingresos brutos', 'ing. brutos', 'ing brutos'],
        'perc_iva' => ['iva'],
    ];

    /** Columna a la que va una percepción que no matchea ninguna palabra: nunca se descarta. */
    public const PERCEPCION_OTRAS = 'otras_percepciones';

    /**
     * Clasifica el texto libre de `compra_conceptos.concepto` en una de las tres columnas de
     * percepciones. Insensible a mayúsculas y acentos (FR-015b).
     *
     * @return string 'perc_iibb' | 'perc_iva' | 'otras_percepciones'
     */
    public function clasificarPercepcion(?string $concepto): string
    {
        $texto = $this->normalizar($concepto);

        foreach (self::PALABRAS_PERCEPCION as $columna => $palabras) {
            foreach ($palabras as $palabra) {
                if ($this->contienePalabra($texto, $palabra)) {
                    return $columna;
                }
            }
        }

        return self::PERCEPCION_OTRAS;
    }

    /**
     * Minúsculas y sin acentos, para que "Percepción IIBB" y "PERCEPCION IIBB" sean lo mismo.
     * Se resuelve con una tabla explícita y no con `iconv`/`intl`, que dependen de extensiones
     * y de locale del server.
     */
    private function normalizar(?string $texto): string
    {
        $texto = mb_strtolower(trim((string) $texto), 'UTF-8');

        return strtr($texto, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        ]);
    }

    /**
     * Coincidencia por palabra completa, no por substring.
     *
     * Sin esto, el marcador corto `ib` de IIBB matchearía dentro de "distribuidora" o
     * "contribución" y mandaría a IIBB percepciones que no lo son.
     */
    private function contienePalabra(string $texto, string $palabra): bool
    {
        return (bool) preg_match('/(?<![a-z0-9])'.preg_quote($palabra, '/').'(?![a-z0-9])/u', $texto);
    }

    /**
     * Expresión SQL que devuelve el neto de un ítem para una de las tres clases de neto.
     *
     * Se calcula sobre `subtotal` (neto, sin IVA) del ítem, así que las tres columnas suman
     * exactamente el neto del comprobante sin solaparse.
     *
     * @param  string  $clase  'gravado' | 'no_gravado' | 'exento'
     */
    public function sqlNeto(string $clase, string $alias = 'compra_items'): string
    {
        $col = "{$alias}.subtotal";
        $iva = "{$alias}.iva_pct";
        $alicuotas = $this->listaSqlAlicuotas();

        $condicion = match ($clase) {
            'gravado' => "{$iva} IN ({$alicuotas})",
            'exento' => "{$iva} = '".self::EXENTO."'",
            // Todo lo que no es una alícuota conocida ni "exento" es No Gravado: NULL, la cadena
            // 'no_gravado' y cualquier valor histórico raro. Así ningún importe queda sin clasificar.
            default => "({$iva} IS NULL OR ({$iva} NOT IN ({$alicuotas}) AND {$iva} <> '".self::EXENTO."'))",
        };

        return "CASE WHEN {$condicion} THEN {$col} ELSE 0 END";
    }

    /**
     * Expresión SQL del IVA de un ítem para una alícuota puntual.
     *
     * El IVA no está guardado: es `subtotal_con_iva − subtotal`. Se usa la diferencia real y no
     * `subtotal × pct` para que el informe muestre exactamente lo que se grabó al cargar la
     * compra, incluido el redondeo (si no, el desglose no reconstruiría el total al centavo).
     */
    public function sqlIva(string $alicuota, string $alias = 'compra_items'): string
    {
        $iva = "{$alias}.iva_pct";
        $literal = "'".addslashes($alicuota)."'";

        return "CASE WHEN {$iva} = {$literal} THEN COALESCE({$alias}.subtotal_con_iva, {$alias}.subtotal) - {$alias}.subtotal ELSE 0 END";
    }

    /** Lista SQL de las alícuotas con columna: `'2.5','5','10.5','21','27'`. */
    private function listaSqlAlicuotas(): string
    {
        return collect(array_keys(self::ALICUOTAS))
            ->map(fn (string $a) => "'".addslashes($a)."'")
            ->implode(', ');
    }

    /**
     * Subconsulta escalar con el total de una columna de percepción de una compra.
     *
     * La clasificación por texto se replica en SQL a partir de la **misma** tabla de palabras
     * que usa {@see self::clasificarPercepcion()}, para que no haya dos criterios. La coincidencia
     * por palabra completa se emula con REGEXP y los mismos bordes que el `preg_match` de PHP.
     *
     * @param  string  $columna  'perc_iva' | 'perc_iibb' | 'otras_percepciones'
     */
    public function sqlPercepcion(string $columna, string $compraIdExpr = 'compras.id'): string
    {
        $condicion = $columna === self::PERCEPCION_OTRAS
            // "Otras" es el complemento: ni IIBB ni IVA. Definido por negación para que la suma
            // de las tres columnas iguale siempre el total de percepciones (invariante FR-015b).
            ? 'NOT ('.$this->sqlMatchPercepcion('perc_iibb').') AND NOT ('.$this->sqlMatchPercepcion('perc_iva').')'
            // IVA excluye explícitamente lo que ya se llevó IIBB, igual que el orden de PHP.
            : ($columna === 'perc_iva'
                ? $this->sqlMatchPercepcion('perc_iva').' AND NOT ('.$this->sqlMatchPercepcion('perc_iibb').')'
                : $this->sqlMatchPercepcion('perc_iibb'));

        return 'COALESCE((SELECT SUM(cc.monto) FROM compra_conceptos cc '.
            "WHERE cc.compra_id = {$compraIdExpr} AND cc.tipo = 'percepcion' AND ({$condicion})), 0)";
    }

    /** Coincidencia de palabra completa sobre `cc.concepto` para una de las columnas con palabras. */
    private function sqlMatchPercepcion(string $columna): string
    {
        return collect(self::PALABRAS_PERCEPCION[$columna])
            ->map(fn (string $p) => ExpresionSql::contienePalabra('cc.concepto', $p))
            ->implode(' OR ');
    }

    /** Subconsulta escalar de los impuestos internos de una compra. */
    public function sqlImpuestosInternos(string $compraIdExpr = 'compras.id'): string
    {
        return 'COALESCE((SELECT SUM(cc.monto) FROM compra_conceptos cc '.
            "WHERE cc.compra_id = {$compraIdExpr} AND cc.tipo = 'impuesto_interno'), 0)";
    }

    /** Subconsulta escalar de los intereses de una compra (entran al total, sin columna propia). */
    public function sqlIntereses(string $compraIdExpr = 'compras.id'): string
    {
        return 'COALESCE((SELECT SUM(cc.monto) FROM compra_conceptos cc '.
            "WHERE cc.compra_id = {$compraIdExpr} AND cc.tipo = 'interes'), 0)";
    }
}
