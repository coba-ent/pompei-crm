<?php

namespace App\Services\Import;

use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;

/**
 * Fuente de filas del asistente de importación (spec 082).
 *
 * El archivo subido se interpreta con PhpSpreadsheet **una sola vez**, al subirlo, y se vuelca a un
 * NDJSON (una fila JSON por línea) junto al temporal. Cada tanda del Paso 3 lee sólo sus líneas, así
 * que su memoria y su tiempo no dependen del tamaño total del archivo — que era exactamente el
 * problema: `Excel::toArray()` por tanda daba ~129 s y ~570 MB con el catálogo real de 9.632 filas,
 * contra 60 s de límite del servidor web.
 *
 * La primera línea del NDJSON son los encabezados; el resto, filas de datos.
 * Ver `specs/082-importacion-archivos-grandes/contracts/fuente-filas-importacion.md`.
 */
final class FuenteFilasImportacion
{
    /** @var array<int, mixed>|null */
    private ?array $encabezados = null;

    private ?int $total = null;

    public function __construct(private readonly string $rutaNdjson)
    {
        if (! is_file($this->rutaNdjson)) {
            // I5: el .ndjson es transitorio y se borra al terminar/cancelar. Si no está, el
            // controlador lo traduce a "volvé a subir el archivo" en vez de colgar la pantalla.
            throw new RuntimeException('El archivo temporal de la importación ya no está disponible. Volvé a subir el archivo.');
        }
    }

    /**
     * Interpreta el archivo subido UNA sola vez y lo vuelca a NDJSON.
     *
     * @param  string  $rutaArchivo  ruta absoluta del archivo subido
     * @return string ruta absoluta del .ndjson generado (mismo directorio y misma base, extensión .ndjson)
     */
    public static function volcar(string $rutaArchivo): string
    {
        $rutaNdjson = self::rutaNdjsonPara($rutaArchivo);

        $filas = (Excel::toArray(null, $rutaArchivo))[0] ?? [];

        $handle = fopen($rutaNdjson, 'w');
        if ($handle === false) {
            throw new RuntimeException("No se pudo crear el archivo temporal de importación: {$rutaNdjson}");
        }

        try {
            foreach ($filas as $fila) {
                fwrite($handle, self::codificar((array) $fila)."\n");
            }
        } finally {
            fclose($handle);
        }

        // El array completo sólo vive acá dentro: liberarlo antes de volver evita arrastrar el pico
        // de memoria del volcado al resto del request de subida.
        unset($filas);

        return $rutaNdjson;
    }

    /**
     * Ruta del volcado que le corresponde a un archivo subido: mismo directorio, misma base,
     * extensión `.ndjson`. Es el único lugar donde se decide ese nombre.
     */
    public static function rutaNdjsonPara(string $rutaArchivo): string
    {
        return dirname($rutaArchivo).DIRECTORY_SEPARATOR.pathinfo($rutaArchivo, PATHINFO_FILENAME).'.ndjson';
    }

    /** Cantidad de filas de DATOS (sin contar el encabezado). */
    public function total(): int
    {
        if ($this->total !== null) {
            return $this->total;
        }

        $lineas = 0;
        $archivo = $this->abrir();
        while (! $archivo->eof()) {
            $linea = $archivo->fgets();
            if (trim((string) $linea) !== '') {
                $lineas++;
            }
        }

        return $this->total = max($lineas - 1, 0); // I6: la primera línea es el encabezado
    }

    /**
     * Encabezados tal cual vinieron en el archivo (fila 0).
     *
     * @return array<int, mixed>
     */
    public function encabezados(): array
    {
        if ($this->encabezados !== null) {
            return $this->encabezados;
        }

        $archivo = $this->abrir();
        $linea = $archivo->eof() ? '' : (string) $archivo->fgets();

        return $this->encabezados = self::decodificar($linea);
    }

    /**
     * Filas de datos del rango pedido, 0-based sobre las filas de DATOS.
     *
     * I2: sólo se decodifica el JSON de las filas devueltas; las de afuera del rango se descartan
     * como texto sin construir arrays.
     *
     * @return iterable<int, array<int, mixed>>
     */
    public function leerRango(int $offset, ?int $limite = null): iterable
    {
        $offset = max($offset, 0);
        if ($limite !== null && $limite <= 0) {
            return;
        }

        $archivo = $this->abrir();
        $indice = -1; // 0-based sobre las filas de DATOS: la primera línea es el encabezado
        $devueltas = 0;
        $encabezadoSalteado = false;

        while (! $archivo->eof()) {
            $linea = (string) $archivo->fgets();
            if (trim($linea) === '') {
                continue;
            }

            if (! $encabezadoSalteado) {
                $encabezadoSalteado = true;

                continue;
            }

            $indice++;
            if ($indice < $offset) {
                continue;
            }

            yield $indice => self::decodificar($linea);

            $devueltas++;
            if ($limite !== null && $devueltas >= $limite) {
                return;
            }
        }
    }

    private function abrir(): \SplFileObject
    {
        $archivo = new \SplFileObject($this->rutaNdjson, 'r');
        $archivo->setFlags(\SplFileObject::DROP_NEW_LINE);

        return $archivo;
    }

    /**
     * I3/I4: se preserva el orden de las filas y el índice numérico de cada celda, incluidas las
     * vacías. Una celda no serializable (objeto, recurso, fecha de PhpSpreadsheet) se pasa a string
     * en vez de romper el volcado entero.
     *
     * @param  array<int|string, mixed>  $fila
     */
    private static function codificar(array $fila): string
    {
        $celdas = [];
        foreach ($fila as $indice => $celda) {
            $celdas[$indice] = self::normalizarCelda($celda);
        }

        $json = json_encode($celdas, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return $json === false ? '[]' : $json;
    }

    private static function normalizarCelda(mixed $celda): mixed
    {
        if ($celda === null || is_scalar($celda)) {
            return $celda;
        }

        if ($celda instanceof \DateTimeInterface) {
            return $celda->format('Y-m-d H:i:s');
        }

        if (is_object($celda) && method_exists($celda, '__toString')) {
            return (string) $celda;
        }

        if (is_array($celda)) {
            return array_map(static fn ($v) => self::normalizarCelda($v), $celda);
        }

        return '';
    }

    /** @return array<int, mixed> */
    private static function decodificar(string $linea): array
    {
        $linea = trim($linea);
        if ($linea === '') {
            return [];
        }

        $fila = json_decode($linea, true);

        return is_array($fila) ? $fila : [];
    }
}
