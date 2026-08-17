<?php

namespace App\Services\Informes;

use Illuminate\Support\Facades\DB;

/**
 * Fragmentos SQL que MySQL y SQLite escriben distinto (spec 067).
 *
 * Los informes de esta tanda arman su detalle con Query Builder crudo —es la única forma de
 * paginar en SQL con 5.000 compras (SC-006)— y ahí aparecen funciones que no son portables:
 * concatenación, partir un string por un separador y `GROUP_CONCAT`. La app corre sobre **MySQL**
 * y la suite de tests sobre **SQLite en memoria** (`phpunit.xml`), así que sin esta capa los
 * tests de dinero que la constitución exige no podrían siquiera ejecutar el SQL real.
 *
 * Es deliberadamente chica: sólo lo que estos tres informes necesitan, no un abstractor de SQL.
 */
class ExpresionSql
{
    public static function esSqlite(): bool
    {
        return DB::connection()->getDriverName() === 'sqlite';
    }

    /**
     * Concatena tratando NULL como cadena vacía y separando con un espacio, sin dejar espacios
     * sobrantes cuando alguna parte falta ("A" y NULL dan "A", no "A ").
     *
     * @param  list<string>  $partes  expresiones SQL
     */
    public static function concatEspacio(array $partes): string
    {
        if (self::esSqlite()) {
            $expr = implode(" || ' ' || ", array_map(fn ($p) => "COALESCE({$p}, '')", $partes));

            return "TRIM({$expr})";
        }

        return 'TRIM(CONCAT_WS(\' \', '.implode(', ', $partes).'))';
    }

    /**
     * Concatena expresiones sin separador, tratando NULL como cadena vacía.
     *
     * @param  list<string>  $partes  expresiones SQL
     */
    public static function concatPlano(array $partes): string
    {
        $envueltas = array_map(fn ($p) => "COALESCE(CAST({$p} AS CHAR), '')", $partes);

        return self::esSqlite()
            ? implode(' || ', array_map(fn ($p) => str_replace('AS CHAR', 'AS TEXT', $p), $envueltas))
            : 'CONCAT('.implode(', ', $envueltas).')';
    }

    /** Parte de `$columna` anterior al primer `$separador` (NULL si no aparece). */
    public static function antesDe(string $columna, string $separador): string
    {
        $sep = "'".addslashes($separador)."'";

        if (self::esSqlite()) {
            return "CASE WHEN INSTR({$columna}, {$sep}) > 0 THEN SUBSTR({$columna}, 1, INSTR({$columna}, {$sep}) - 1) ELSE NULL END";
        }

        return "CASE WHEN {$columna} LIKE '%".addslashes($separador)."%' THEN SUBSTRING_INDEX({$columna}, {$sep}, 1) ELSE NULL END";
    }

    /** Parte de `$columna` posterior al último `$separador` (la columna entera si no aparece). */
    public static function despuesDe(string $columna, string $separador): string
    {
        $sep = "'".addslashes($separador)."'";

        if (self::esSqlite()) {
            // SQLite no tiene SUBSTRING_INDEX. Con un único separador —que es el caso de
            // "0001-00000123"— alcanza con cortar después del primero que aparece.
            return "CASE WHEN INSTR({$columna}, {$sep}) > 0 THEN SUBSTR({$columna}, INSTR({$columna}, {$sep}) + 1) ELSE {$columna} END";
        }

        return "CASE WHEN {$columna} LIKE '%".addslashes($separador)."%' THEN SUBSTRING_INDEX({$columna}, {$sep}, -1) ELSE {$columna} END";
    }

    /**
     * Literal de texto entrecomillado, escapado según el motor.
     *
     * Existe por los nombres de clase de las relaciones polimórficas (`App\Models\Venta`), que
     * llevan barra invertida. `addslashes()` la duplica, y eso **sólo** es correcto en MySQL,
     * donde la barra es carácter de escape: en SQLite la barra es un carácter común, así que
     * `'App\\Models\\Venta'` queda literalmente con dos barras y no matchea nunca — la columna de
     * etiquetas volvía vacía en los tests mientras en producción andaba bien.
     */
    public static function literal(string $texto): string
    {
        $escapado = self::esSqlite()
            // En SQLite la única comilla que hay que escapar es la simple, duplicándola.
            ? str_replace("'", "''", $texto)
            : addslashes($texto);

        return "'".$escapado."'";
    }

    /** `GROUP_CONCAT` con separador explícito: la sintaxis difiere entre los dos motores. */
    public static function groupConcat(string $columna, string $separador = ', '): string
    {
        $sep = "'".addslashes($separador)."'";

        return self::esSqlite()
            ? "GROUP_CONCAT({$columna}, {$sep})"
            : "GROUP_CONCAT({$columna} ORDER BY {$columna} SEPARATOR {$sep})";
    }

    /**
     * "¿`$columna` contiene `$palabra` como palabra completa?", sin usar REGEXP.
     *
     * SQLite no trae operador REGEXP de fábrica y MySQL 8 cambió la sintaxis de los bordes de
     * palabra respecto de MariaDB, así que se resuelve con LIKE: se normalizan los separadores
     * más comunes a espacios, se rodea todo de espacios y se busca la palabra rodeada de
     * espacios. Así "Perc. IVA", "IVA:" y "s/IVA" matchean, y "activa" o "privada" no.
     */
    public static function contienePalabra(string $columna, string $palabra): string
    {
        $normalizada = "LOWER({$columna})";

        foreach (['.', ',', ';', ':', '/', '-', '(', ')'] as $signo) {
            $normalizada = "REPLACE({$normalizada}, '".addslashes($signo)."', ' ')";
        }

        // Colapsa los espacios que dejó el paso anterior: "Ing. Brutos" queda "ing  brutos" con
        // dos espacios y no matchearía "% ing brutos %". Dos pasadas alcanzan para los casos
        // reales (un signo entre palabras); no se busca un colapso general.
        $normalizada = "REPLACE(REPLACE({$normalizada}, '   ', ' '), '  ', ' ')";

        $rodeada = self::esSqlite()
            ? "(' ' || {$normalizada} || ' ')"
            : "CONCAT(' ', {$normalizada}, ' ')";

        // La palabra buscada pasa por la misma normalización que la columna, para que
        // "ing. brutos" se compare contra "ing  brutos" y no falle por el punto.
        $buscada = trim(str_replace(['.', ',', ';', ':', '/', '-', '(', ')'], ' ', mb_strtolower($palabra)));

        return "{$rodeada} LIKE '% ".addslashes($buscada)." %'";
    }
}
