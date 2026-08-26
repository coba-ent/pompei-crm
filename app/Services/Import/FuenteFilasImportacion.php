<?php

namespace App\Services\Import;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
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
    /**
     * Marca que se escribe en el volcado cuando una celda tiene una fórmula que no se pudo evaluar
     * (spec 083, FR-012). No es un valor: `ValidadorFilasImportacion` la traduce a un error de fila
     * que nombra la columna. Se usa un sentinela con bytes nulos justamente para que no pueda
     * colisionar con contenido real de una planilla.
     */
    public const MARCA_FORMULA = "\x00#formula-no-evaluable#\x00";

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
     * Spec 083 (FR-011): se lee con PhpSpreadsheet directo en vez de `Excel::toArray()` para poder
     * pedir el **valor calculado** de cada fórmula. Una planilla guardada sin recalcular trae el
     * texto de la fórmula en la caché de valores; así entraron 124 productos con el código puesto
     * en `=CONCATENAR(...)` y el precio en cero (incidente del 25/08/2026).
     *
     * FR-012: el fallo de cálculo se captura **por celda**. Una fórmula rota marca esa celda y no
     * aborta el volcado del archivo entero — el resto de las filas se sigue pudiendo revisar.
     *
     * @param  string  $rutaArchivo  ruta absoluta del archivo subido
     * @return string ruta absoluta del .ndjson generado (mismo directorio y misma base, extensión .ndjson)
     */
    public static function volcar(string $rutaArchivo): string
    {
        $rutaNdjson = self::rutaNdjsonPara($rutaArchivo);

        $handle = fopen($rutaNdjson, 'w');
        if ($handle === false) {
            throw new RuntimeException("No se pudo crear el archivo temporal de importación: {$rutaNdjson}");
        }

        $lector = IOFactory::createReaderForFile($rutaArchivo);
        // `setReadDataOnly(true)` descartaría las fórmulas junto con el formato, que es justamente lo
        // que hay que poder evaluar. El costo es el formato de celda, que este volcado no usa.
        $lector->setReadDataOnly(false);
        $libro = $lector->load($rutaArchivo);

        try {
            $hoja = $libro->getActiveSheet();
            $ultimaColumna = Coordinate::columnIndexFromString($hoja->getHighestDataColumn());
            $ultimaFila = $hoja->getHighestDataRow();

            for ($numeroFila = 1; $numeroFila <= $ultimaFila; $numeroFila++) {
                $celdas = [];
                for ($columna = 1; $columna <= $ultimaColumna; $columna++) {
                    $celdas[$columna - 1] = self::valorDeCelda($hoja, $columna, $numeroFila);
                }

                fwrite($handle, self::codificar($celdas)."\n");
            }
        } finally {
            fclose($handle);
            // Sin esto el libro entero queda en memoria hasta el final del request — con el catálogo
            // real son cientos de MB que no hacen falta una vez volcado el NDJSON.
            $libro->disconnectWorksheets();
            unset($libro);
        }

        return $rutaNdjson;
    }

    /**
     * Valor de una celda para el volcado: el crudo si no es fórmula, y el **resultado calculado** si
     * lo es. Una fórmula que no se puede evaluar (excepción del motor de cálculo, o un código de
     * error de Excel como `#REF!`/`#DIV/0!`) devuelve `MARCA_FORMULA`, nunca su texto.
     */
    private static function valorDeCelda(Worksheet $hoja, int $columna, int $fila): mixed
    {
        $coordenada = Coordinate::stringFromColumnIndex($columna).$fila;

        // `cellExists()` antes de `getCell()`: éste último CREA la celda vacía si no existe, y sobre
        // una planilla dispersa eso multiplica la memoria del volcado sin agregar ningún dato.
        if (! $hoja->cellExists($coordenada)) {
            return null;
        }

        $celda = $hoja->getCell($coordenada);

        if ($celda->getDataType() !== DataType::TYPE_FORMULA) {
            return $celda->getValue();
        }

        try {
            $valor = $celda->getCalculatedValue();
        } catch (\Throwable) {
            return self::MARCA_FORMULA;
        }

        if (is_string($valor) && (str_starts_with($valor, '#') || str_starts_with(ltrim($valor), '='))) {
            // Códigos de error de Excel (#REF!, #DIV/0!, #NAME?) y el caso en que el motor devuelve
            // la fórmula tal cual: en los dos, no hay valor que importar.
            return self::MARCA_FORMULA;
        }

        return $valor;
    }

    /**
     * Fuente de filas para una ruta, sea la del `.ndjson` ya volcado o la del archivo original.
     *
     * Si el volcado todavía no existe (llamada directa con un `.xlsx` desde un test o la CLI, sin
     * pasar por el Paso 1 del asistente), se hace acá. Spec 083: el camino alternativo con
     * `Excel::toArray()` **se eliminó** — era el único que no evaluaba las fórmulas, así que
     * mantenerlo habría dejado un camino por el que el defecto del 25/08 podía volver a entrar.
     */
    public static function paraArchivo(string $ruta): self
    {
        if (str_ends_with(strtolower($ruta), '.ndjson')) {
            return new self($ruta);
        }

        $rutaNdjson = self::rutaNdjsonPara($ruta);

        if (! is_file($rutaNdjson)) {
            self::volcar($ruta);
        }

        return new self($rutaNdjson);
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
