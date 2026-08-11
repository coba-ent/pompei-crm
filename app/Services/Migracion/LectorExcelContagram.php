<?php

namespace App\Services\Migracion;

use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Lector de los Excel exportados de Contagram para la migración 2021-2026.
 *
 * Existe para que los defectos de esos archivos se resuelvan UNA sola vez y en un solo lugar:
 * cada uno se descubrió a fuerza de análisis y está documentado con su evidencia en
 * docs/importacion_2021_2026_plan_tecnico.md §3. No replicar esta lógica en los comandos.
 *
 * Lee en modo "sólo datos": además de ser mucho más rápido, es lo que hace que las celdas de
 * fecha lleguen como **serial numérico** y las de texto como string — justamente la distinción
 * que necesita la corrección de fechas invertidas (ver fecha()).
 *
 * Nada que ver con App\Services\Import, que es el importador por pantalla del CRM.
 */
class LectorExcelContagram
{
    /** Sufijo de columna repetida, mismo criterio que pandas: Tipo, Tipo.1 */
    private const SEPARADOR_DUPLICADA = '.';

    /**
     * Lee una hoja y devuelve filas asociativas (encabezado => valor crudo).
     *
     * @param  array<int,string>|null  $headerCanonico  Encabezado a usar cuando el archivo no lo
     *                                                  trae al principio (caso `Ventas 2023.xlsx`).
     * @return array{header: array<int,string>, filas: array<int, array<string, mixed>>}
     */
    public function leer(string $path, ?array $headerCanonico = null): array
    {
        $lector = IOFactory::createReaderForFile($path);
        $lector->setReadDataOnly(true);

        // toArray(nullValue, calculateFormulas, formatData, returnCellRef).
        //
        // `calculateFormulas: false` no es una optimización, es obligatorio: los nombres de cliente
        // traen el teléfono y muchos arrancan con `+` ("+54 9 11 6973-4..."), así que Excel los
        // guardó como FÓRMULA. Evaluándolas, PhpSpreadsheet tarda minutos y termina abortando con
        // `Calculation\Exception: internal error` (visto en Ventas 2025 E4896 y c/ cobro 2025 G1670).
        // Sin evaluar, la celda devuelve el texto literal — que es justo el teléfono que queremos.
        //
        // `formatData: false` deja las fechas como serial numérico, que es lo que espera fecha().
        $matriz = $lector->load($path)->getActiveSheet()->toArray(null, false, false, false);

        $filaHeader = $this->ubicarFilaHeader($matriz);

        if ($filaHeader === null) {
            // El archivo no trae encabezado al principio (Ventas 2023: quedó pegado al final).
            if ($headerCanonico === null) {
                throw new \RuntimeException("El archivo {$path} no tiene encabezado y no se pasó uno canónico.");
            }
            $header = $headerCanonico;
            $desde = 0;
        } else {
            $header = $this->deduplicarHeader($this->recortarColumnasFantasma($matriz[$filaHeader]));
            $desde = $filaHeader + 1;
        }

        $filas = [];
        for ($i = $desde, $n = count($matriz); $i < $n; $i++) {
            $celdas = $matriz[$i];

            // Descarta la fila de encabezado literal que en Ventas 2023 quedó al final.
            if ($this->esFilaHeader($celdas) || $this->filaVacia($celdas)) {
                continue;
            }

            $fila = [];
            foreach ($header as $col => $nombre) {
                $fila[$nombre] = $celdas[$col] ?? null;
            }
            $filas[] = $fila;
        }

        return ['header' => $header, 'filas' => $filas];
    }

    /**
     * Fecha real de una celda. Resuelve los dos defectos de origen (§3.1 y §3.2):
     *
     * - **Serial numérico**: los exports traen `46239.0` en vez de una fecha.
     * - **Día y mes invertidos**: en Cuentas/ y Compras/ la columna viene mezclada entre celdas
     *   tipo fecha y tipo texto. Excel convirtió a fecha real **sólo las ambiguas (día ≤ 12)
     *   interpretándolas al revés**; las que no podían serlo (día > 12) quedaron como texto
     *   `M/D/Y`. Por eso el intercambio se aplica únicamente cuando `día <= 12`: en los demás
     *   casos la fecha ya está bien y tocarla la rompería.
     *
     * Verificación de la regla: los archivos vienen ordenados por fecha descendente y con ella
     * aplicada los 25 de Cuentas/ quedan con 0 violaciones de orden (sin ella, 10 a 110 cada uno).
     *
     * @param  bool  $invertida  true para Cuentas/ y Compras/; false para Ventas/, que no lo tiene.
     */
    public function fecha(mixed $valor, bool $invertida = false): ?CarbonImmutable
    {
        if ($valor === null || $valor === '' || $valor === '-') {
            return null;
        }

        if (is_numeric($valor)) {
            $f = CarbonImmutable::instance(ExcelDate::excelToDateTimeObject((float) $valor))->startOfDay();

            return $invertida && $f->day <= 12
                ? CarbonImmutable::create($f->year, $f->day, $f->month)->startOfDay()
                : $f;
        }

        if (is_string($valor) && preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', trim($valor), $m)) {
            // Texto: siempre M/D/Y en estos exports (7/31/2026 no puede ser D/M).
            return CarbonImmutable::create((int) $m[3], (int) $m[1], (int) $m[2])->startOfDay();
        }

        if ($valor instanceof \DateTimeInterface) {
            $f = CarbonImmutable::instance(\DateTime::createFromInterface($valor))->startOfDay();

            return $invertida && $f->day <= 12
                ? CarbonImmutable::create($f->year, $f->day, $f->month)->startOfDay()
                : $f;
        }

        return null;
    }

    /**
     * Nombre normalizado para matchear clientes y proveedores.
     *
     * Colapsa espacios: la base tiene 377 clientes guardados con doble espacio entre nombre y
     * teléfono ("Alberto Diaz  1164854451") mientras los exports nuevos traen uno solo. Sin esto
     * el matching por igualdad falla y crea duplicados (§3.6).
     */
    public function normalizarNombre(mixed $valor): string
    {
        return preg_replace('/\s+/u', ' ', trim((string) $valor)) ?? '';
    }

    /** Texto limpio, o null si la celda está vacía o trae el guion de "sin dato" de Contagram. */
    public function texto(mixed $valor): ?string
    {
        if ($valor === null) {
            return null;
        }
        $t = trim((string) $valor);

        return $t === '' || $t === '-' ? null : $t;
    }

    public function numero(mixed $valor): ?float
    {
        return is_numeric($valor) ? (float) $valor : null;
    }

    /**
     * Id de producto embebido en la columna `Código` ("40356 3031 DL-00059" => 40356).
     * Contempla también el formato alternativo "ID:26230 SKU 7032 AB-7072".
     */
    public function idProductoDesdeCodigo(mixed $codigo): ?int
    {
        $c = (string) ($this->texto($codigo) ?? '');

        if (preg_match('/^(\d{3,6})\b/', $c, $m)) {
            return (int) $m[1];
        }
        if (preg_match('/ID:(\d+)/', $c, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    /** Busca la fila del encabezado real (Compras 2021/2025/2026 lo traen en la 7). */
    private function ubicarFilaHeader(array $matriz): ?int
    {
        foreach (array_slice($matriz, 0, 15, true) as $i => $celdas) {
            if ($this->esFilaHeader($celdas)) {
                return $i;
            }
        }

        return null;
    }

    private function esFilaHeader(array $celdas): bool
    {
        return isset($celdas[0]) && strcasecmp(trim((string) $celdas[0]), 'Id') === 0;
    }

    private function filaVacia(array $celdas): bool
    {
        foreach ($celdas as $c) {
            if (trim((string) $c) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Recorta las columnas vacías del final del encabezado.
     *
     * `Ventas 2026.xlsx` trae 6 columnas fantasma después de `Afecta Stock` (el export las dejó
     * con el rango dimensionado de más). Sin recortarlas el header queda con nombres `''`, `.1`…
     * y deja de ser idéntico al de los otros años — que sí lo es, verificado columna por columna.
     *
     * @param  array<int,mixed>  $header
     * @return array<int,mixed>
     */
    private function recortarColumnasFantasma(array $header): array
    {
        while ($header !== [] && trim((string) end($header)) === '') {
            array_pop($header);
        }

        return $header;
    }

    /**
     * Desduplica nombres de columna repetidos igual que pandas (`Tipo`, `Tipo.1`).
     *
     * En el export por ítem la columna `Tipo` aparece dos veces (tipo de comprobante y rubro del
     * producto). Con array_combine se queda la última y se pierde el A/B en silencio (§3.5).
     *
     * @param  array<int,mixed>  $header
     * @return array<int,string>
     */
    public function deduplicarHeader(array $header): array
    {
        $vistos = [];
        $salida = [];

        foreach ($header as $nombre) {
            $nombre = trim((string) $nombre);
            if (! isset($vistos[$nombre])) {
                $vistos[$nombre] = 0;
                $salida[] = $nombre;

                continue;
            }
            $vistos[$nombre]++;
            $salida[] = $nombre.self::SEPARADOR_DUPLICADA.$vistos[$nombre];
        }

        return $salida;
    }
}
