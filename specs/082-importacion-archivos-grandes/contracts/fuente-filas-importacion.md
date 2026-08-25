# Contrato interno: `FuenteFilasImportacion`

**Spec**: [../spec.md](../spec.md) | **Research**: [../research.md](../research.md) Decisión 1

No es una API HTTP: es el contrato del servicio que reemplaza el `Excel::toArray()` por tanda.
Se documenta como contrato porque es la costura entre el controlador y el importador, y porque sus
bordes (archivo vacío, rango fuera de rango, encabezados) son donde se rompen las cosas.

## Responsabilidad

Interpretar el archivo subido **una sola vez** y ofrecer lectura por rangos de filas con memoria
independiente del tamaño total.

## Superficie

```php
namespace App\Services\Import;

final class FuenteFilasImportacion
{
    /**
     * Interpreta el .xlsx/.csv UNA sola vez y lo vuelca a NDJSON (una fila JSON por línea).
     * La primera línea del NDJSON son los encabezados; el resto, filas de datos.
     *
     * @param  string  $rutaArchivo  ruta absoluta del archivo subido
     * @return string  ruta absoluta del .ndjson generado (mismo directorio, misma base, extensión .ndjson)
     */
    public static function volcar(string $rutaArchivo): string;

    public function __construct(string $rutaNdjson);

    /** Cantidad de filas de DATOS (sin contar el encabezado). */
    public function total(): int;

    /** @return array<int, mixed> encabezados tal cual vinieron en el archivo (fila 0) */
    public function encabezados(): array;

    /**
     * Filas de datos del rango pedido, 0-based sobre las filas de DATOS.
     * Sólo decodifica el JSON de las filas devueltas; las salteadas se descartan como texto.
     *
     * @return iterable<int, array<int, mixed>>
     */
    public function leerRango(int $offset, ?int $limite = null): iterable;
}
```

## Invariantes

| # | Invariante | Por qué importa |
|---|---|---|
| I1 | `volcar()` se llama **una vez por importación**, en el Paso 1 (subida). Nunca desde una tanda. | Es el punto entero de la feature (FR-001). |
| I2 | `leerRango()` no carga en memoria las filas fuera del rango. | FR-003: memoria por tanda independiente del total. |
| I3 | El orden de las filas del NDJSON es **idéntico** al del archivo original. | El mapeo se referencia por índice de columna y `numero_fila` se calcula por posición. |
| I4 | Cada fila conserva sus celdas por **índice numérico** (`0..N-1`), incluidas las vacías. | Un `array_filter` o un reindexado corrompería el mapeo por índice. |
| I5 | El `.ndjson` es estado transitorio: se borra al terminar, al cancelar y al abandonar. | FR-004 + invariante de §2.4. |
| I6 | `total()` cuenta filas de **datos**, sin el encabezado. | Es el `total` que ya devuelve `importar()` y que el frontend usa para el progreso. |

## Casos borde

| Caso | Comportamiento esperado |
|---|---|
| Archivo sólo con encabezados | `total()` → `0`; `leerRango(0, 250)` → vacío. La importación termina informando 0 filas, sin error. |
| Archivo completamente vacío (sin encabezado) | `total()` → `0`; `encabezados()` → `[]`. El Paso 2 ya rechaza esto hoy (no hay columnas que mapear). |
| `offset` más allá del final | Devuelve vacío, **no** error. Cierra el loop de tandas de forma natural. |
| `limite` que excede el final | Devuelve las filas que haya hasta el final. |
| `limite = null` | Devuelve desde `offset` hasta el final. |
| Fila con menos celdas que el encabezado | Se conserva tal cual; el importador ya tolera índices ausentes (`$fila[$indice] ?? ''`). |
| Celda con valor no serializable a JSON | Se convierte a string antes de volcar. Nunca puede romper el volcado entero por una celda. |
| `.ndjson` inexistente al leer (limpieza, reinicio) | Excepción clara que el controlador traduce a "volvé a subir el archivo", sin dejar la pantalla colgada. |

## Relación con `ImportadorFilas::importar()`

`importar()` deja de decidir **cómo** obtener las filas y pasa a recibirlas. Se mantiene la firma
pública y la semántica de `$limite = null` (Decisión 7 de research) para no romper los tests
existentes ni las llamadas por CLI.

⚠️ **Comportamiento heredado a preservar explícitamente**: hoy, con `$limite === null`, el `$offset`
se **ignora** y se procesan todas las filas. Es sutil y ya causó un error durante la resolución manual
del incidente del 25/08. El refactor lo mantiene igual y lo cubre con un test, para no cambiarlo sin
querer.
