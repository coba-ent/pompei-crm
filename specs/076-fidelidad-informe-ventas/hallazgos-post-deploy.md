# Hallazgos post-deploy (24/08/2026)

Después de deployar al VPS y comparar `Informe de Ventas Detallado 24-08-2026 1928 Hs.xlsx` (el
nuestro) contra `Informe_de_Ventas_Detallado_24-08-2026_1628_Hs.xlsx` (el de Contagram, mismo
rango), aparecieron tres defectos reales en `InformeVentasDetalladoExport`. Los tres se corrigieron
en el mismo día, con test de regresión. Documentado acá porque el bug de fondo (punto 1) es una
trampa de la librería que puede repetirse en cualquier export futuro con esta estructura.

## 1. Las filas en blanco desaparecían — corrió todo el layout

**Síntoma**: el encabezado de las 44 columnas apareció en la fila 7, no en la fila 10. El estilo
"negrita + fondo negro" que el código aplicaba explícitamente "a la fila 10" terminó cayendo sobre
una fila de **datos** (la venta que por casualidad caía ahí), no sobre el encabezado.

**Causa raíz**: `array()` armaba las 3 filas en blanco entre bloques de KPIs como `[]` (array PHP
vacío). Maatwebsite Excel arma las filas con `Collection::flatMap()`
(`vendor/maatwebsite/excel/src/Sheet.php::appendRows()`), y `ArrayHelper::hasMultipleRows([])`
devuelve `true` para un array vacío (`count([]) === count(array_filter([], 'is_array'))` → `0 === 0`).
Eso hace que `ensureMultipleRows([])` trate la fila vacía como "ya son cero filas" en vez de "una
fila sin celdas", así que el `flatMap` la aplana a **nada**: la fila en blanco no aparece en el
Excel, simplemente desaparece y todo lo que sigue se corre hacia arriba. El array de PHP (`array()`)
tenía la estructura correcta con la fila en el índice correcto — el bug sólo se manifestaba al pasar
por el escritor real de Maatwebsite, por eso ningún test que sólo inspeccionara `array()` lo detectó.

**Arreglo**: usar `[null]` en vez de `[]` para una fila en blanco. `hasMultipleRows([null])` da
`false` (`null` no es un array), así que `ensureMultipleRows` la envuelve en `[[null]]`: una fila
real, con una celda `NULL`, que sí sobrevive el `flatMap`.

**Lección para cualquier export futuro con filas en blanco intercaladas** (no sólo Ventas): nunca
usar `[]` como fila en blanco con Maatwebsite `FromArray`. Usar `[null]`. Y ningún test que sólo
llame a `->array()` y mire el PHP array alcanza para blindar esto — hace falta un test que **escriba
el archivo de verdad** (`Excel::store()`) y lo vuelva a leer con PhpSpreadsheet, como
`test_el_archivo_real_tiene_el_encabezado_en_la_fila_10_y_fechas_como_excel()` en
`InformeVentasDetalladoExportTest`. Se agregó ese test como red de seguridad permanente.

## 2. El bloque de KPIs tenía rótulo y valor en la misma fila — Contagram no

**Síntoma**: "los totales que van arriba del todo están mal puestos".

**Causa raíz**: el código armaba cada fila como `['Total Ventas Creadas', 4042167.58, 'Total Nota de
Débito', 0]` (rótulo, valor, rótulo, valor, intercalados en la misma fila). El archivo real de
Contagram usa una fila de **puros rótulos** seguida de una fila de **puros valores**, columna por
columna (`contracts/export-detallado.md §2` no bajaba a este nivel de detalle — la estructura de
fila/valor no estaba en el contrato, y se asumió mal).

**Arreglo**: reescrita la estructura de `array()` para que cada bloque sea
`[rótulos...]` en una fila y `[valores...]` en la siguiente, en las mismas posiciones de columna.

## 3. Las fechas se escribían como texto, no como fecha de Excel

**Síntoma**: no se llegó a ver en el archivo comparado (el bug de arriba corrió las filas antes de
llegar a esto), pero se encontró al escribir el test de round-trip real.

**Causa raíz**: se asumió que pasar un objeto `\DateTimeImmutable` como valor de celda alcanzaba
para que PhpSpreadsheet lo grabara como fecha de Excel (serial numérico + formato). Es falso:
`DefaultValueBinder::bindValue()` (`vendor/phpoffice/phpspreadsheet/.../DefaultValueBinder.php`)
tiene una rama explícita para `DateTimeInterface` que lo **formatea como string** (`Y-m-d H:i:s`) y
lo graba como texto — no hay conversión automática a serial.

**Arreglo**: convertir explícitamente con `\PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel()`
antes de devolver el valor de la celda, y aplicar el formato de visualización (`dd/mm/yyyy`) aparte,
en `styles()`. Éste es el patrón correcto para cualquier columna de fecha en un export por
`FromArray` en este proyecto — el export resumen (`InformeVentasExport`) sigue escribiendo fechas
como texto (`$this->fecha()` devuelve un string `d/m/Y`) y **no se tocó** en esta corrida porque no
era parte del reporte del usuario, pero tiene el mismo defecto (ya señalado por T024c como
"pendiente el resumen"). Queda para una próxima pasada.

## Estado

Los tres arreglos están en el commit que sigue al primer deploy de la spec 076, con
`test_el_archivo_real_tiene_el_encabezado_en_la_fila_10_y_fechas_como_excel()` como test de
regresión permanente contra el bug #1. Falta: validar visualmente en el VPS que ahora sí coincide
con el archivo de Contagram (el usuario lo está haciendo), y arreglar el mismo defecto de fecha en
`InformeVentasExport` (fuera de alcance de esta corrida puntual).
