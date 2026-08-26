<?php

namespace App\Services\Import;

use RuntimeException;

/**
 * Lo que el modal de confirmación va a mostrar: cuántas altas, cuántas actualizaciones, qué campos
 * se van a escribir y a cuántos registros, y qué filas fallan (spec 083, FR-001 y FR-005b).
 *
 * **Dónde vive** (T020): en **disco**, junto al NDJSON de la spec 082, no en sesión. La medición que
 * lo decide es el peor caso realista, no el típico: un archivo de 10.000 filas **todas** con error
 * son 10.000 motivos de texto — del orden de 1 MB. La sesión de Laravel se serializa entera en cada
 * request, así que meter eso ahí encarece todas las requests de la importación y, con el driver
 * `cookie`, directamente no entra. En disco cuesta un `file_put_contents` por tanda.
 *
 * **Ciclo de vida**: nace al prevalidar, se descarta al confirmar, al cancelar o al subir un archivo
 * nuevo — junto con el `.xlsx` y el `.ndjson`, con los que se borra en conjunto.
 *
 * No escribe en la base ni sabe cómo hacerlo: sólo acumula el veredicto de
 * `ValidadorFilasImportacion`.
 */
final class InformePrevalidacion
{
    public int $altas = 0;

    public int $actualizaciones = 0;

    public int $procesadas = 0;

    public int $total = 0;

    /** @var array<string, int> etiqueta visible del campo => a cuántos registros afecta */
    public array $camposAfectados = [];

    /** @var array<int, array{fila: int, motivos: array<int, string>}> */
    public array $errores = [];

    /** @var array<int, array{fila: int, motivo: string}> */
    public array $advertencias = [];

    public string $huella = '';

    /**
     * Huella del archivo + el mapeo prevalidados (FR-009). Si al confirmar no coincide, es que
     * cambió alguna de las dos cosas y el informe que el usuario aprobó ya no describe lo que se
     * está por escribir: se rechaza en vez de escribir a ciegas.
     *
     * @param  array<int, mixed>  $columnas
     * @param  array<int|string, string>  $mapeo
     * @param  array<int|string, string>  $personalizados
     */
    public static function huellaDe(array $columnas, array $mapeo, array $personalizados): string
    {
        return sha1((string) json_encode([
            array_map(fn ($c) => (string) $c, $columnas),
            $mapeo,
            $personalizados,
        ]));
    }

    /** Ruta del informe que le corresponde a un volcado NDJSON: misma base, extensión `.prevalidacion.json`. */
    public static function rutaPara(string $rutaNdjson): string
    {
        return dirname($rutaNdjson).DIRECTORY_SEPARATOR.pathinfo($rutaNdjson, PATHINFO_FILENAME).'.prevalidacion.json';
    }

    /** Informe vacío para arrancar una prevalidación (o retomarla desde cero). */
    public static function nuevo(string $huella, int $total): self
    {
        $informe = new self;
        $informe->huella = $huella;
        $informe->total = $total;

        return $informe;
    }

    /** Informe ya acumulado en disco, o `null` si no hay ninguno todavía. */
    public static function cargar(string $ruta): ?self
    {
        if (! is_file($ruta)) {
            return null;
        }

        $datos = json_decode((string) file_get_contents($ruta), true);
        if (! is_array($datos)) {
            return null;
        }

        $informe = new self;
        $informe->altas = (int) ($datos['altas'] ?? 0);
        $informe->actualizaciones = (int) ($datos['actualizaciones'] ?? 0);
        $informe->procesadas = (int) ($datos['procesadas'] ?? 0);
        $informe->total = (int) ($datos['total'] ?? 0);
        $informe->camposAfectados = (array) ($datos['campos_afectados'] ?? []);
        $informe->errores = (array) ($datos['errores'] ?? []);
        $informe->advertencias = (array) ($datos['advertencias'] ?? []);
        $informe->huella = (string) ($datos['huella'] ?? '');

        return $informe;
    }

    public function guardar(string $ruta): void
    {
        $json = json_encode($this->toArray(), JSON_UNESCAPED_UNICODE);
        if ($json === false || file_put_contents($ruta, $json) === false) {
            throw new RuntimeException("No se pudo guardar el informe de prevalidación: {$ruta}");
        }
    }

    /**
     * Suma a la cuenta el veredicto de una fila.
     *
     * @param  array{modo: string, motivos: array<int, string>, advertencias: array<int, string>, campos: array<int, string>}  $veredicto
     */
    public function acumular(int $numeroFila, array $veredicto): void
    {
        $this->procesadas++;

        foreach ($veredicto['advertencias'] as $motivo) {
            $this->advertencias[] = ['fila' => $numeroFila, 'motivo' => $motivo];
        }

        if ($veredicto['modo'] === 'error') {
            $this->errores[] = ['fila' => $numeroFila, 'motivos' => array_values($veredicto['motivos'])];

            return;
        }

        if ($veredicto['modo'] === 'alta') {
            $this->altas++;
        } else {
            $this->actualizaciones++;
        }

        // FR-005b: se cuentan los campos de las filas que **se van a escribir**. Una fila con error
        // no escribe nada, así que sumarla acá haría que el modal prometa cambios que no ocurren.
        foreach ($veredicto['campos'] as $campo) {
            $this->camposAfectados[$campo] = ($this->camposAfectados[$campo] ?? 0) + 1;
        }
    }

    public function hayErrores(): bool
    {
        return $this->errores !== [];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        // Los campos se muestran de mayor a menor impacto: lo primero que el usuario tiene que ver
        // es qué se toca en más registros.
        $campos = $this->camposAfectados;
        arsort($campos);

        return [
            'altas' => $this->altas,
            'actualizaciones' => $this->actualizaciones,
            'procesadas' => $this->procesadas,
            'total' => $this->total,
            'campos_afectados' => $campos,
            'errores' => $this->errores,
            'advertencias' => $this->advertencias,
            'huella' => $this->huella,
            'hay_errores' => $this->hayErrores(),
            'cantidad_errores' => count($this->errores),
            'cantidad_advertencias' => count($this->advertencias),
        ];
    }
}
