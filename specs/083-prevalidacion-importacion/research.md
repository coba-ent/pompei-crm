# Research: Prevalidación y confirmación previa de la importación

**Spec**: [spec.md](./spec.md) | **Fecha**: 2026-08-26

Todo lo de acá se verificó contra el código y contra el archivo real del incidente
(`Ferrum nuevos (2).xlsx`, 148 filas), no por inspección a ojo.

## Decisión 1 — Cómo se calculan las fórmulas de Excel

**Problema medido**: `Excel::toArray()` (maatwebsite) devuelve el **texto** de la fórmula cuando el
`.xlsx` no trae el valor cacheado. El archivo real da `'=+B2&" "&A2'` en las 148 filas de Código/SKU
y `'=+ROUND(L92,2)'` en 24 celdas de precio.

**Verificado en el archivo real**:

```php
$hoja->getCell('C2')->getValue();           // '=+B2&" "&A2'
$hoja->getCell('C2')->getCalculatedValue(); // 'DEPOSITO ANDINA ... 44927'
$hoja->getCell('M92')->getCalculatedValue(); // 105106.56  (era '=+ROUND(L92,2)')
```

**Decisión**: en el volcado del Paso 1 se lee con PhpSpreadsheet pidiendo el **valor calculado**
(`toArray(null, true, false, false)` — el segundo parámetro es `$calculateFormulas`), en vez de
`Excel::toArray()`.

**Costo medido**: 148 filas → **51 ms** de cálculo, **656 ms** totales incluyendo la carga del
archivo, **14 MB** de pico. Extrapolado al catálogo real (9.632 filas): ~3,3 s de cálculo. Entra sin
problema en el Paso 1, que ya es el paso lento y ya sube el `memory_limit`.

**Descartado**: detectar las celdas que empiezan con `=` y sólo avisar. Es la alternativa que se le
ofreció al usuario y eligió que el sistema calcule — hace el trabajo en vez de devolverle el problema.

**Qué pasa si una fórmula no se puede evaluar**: `getCalculatedValue()` lanza una excepción de cálculo
(referencia externa, circular, función no soportada). Se captura **por celda**: la celda queda marcada
como no evaluable y la fila entera se reporta como error en la prevalidación. Nunca se guarda el texto
de la fórmula (FR-012, FR-013).

## Decisión 2 — Dónde vive la prevalidación

La prevalidación tiene que aplicar **exactamente** las mismas reglas que la importación real (FR-003).
Duplicar la lógica es garantía de que se desincronice.

**Decisión**: extraer de `ImportadorFilas` el camino de "mapear la fila + resolver alta/actualización +
validar" a un servicio que **no escribe**, y que tanto la prevalidación como la importación usen.
`ImportadorFilas` pasa a ser "ese servicio + persistir".

**Por qué así y no un flag `$soloValidar` en `importar()`**: un flag deja la escritura y la validación
en el mismo método, con `if` repartidos; el riesgo es que un camino escriba en modo simulacro. Con la
validación en un servicio que **no tiene forma de escribir**, FR-002 se cumple por construcción y no
por disciplina.

**Reutiliza el NDJSON de la spec 082**: la prevalidación lee del volcado, igual que las tandas. No
vuelve a abrir el Excel (FR-007).

## Decisión 3 — La prevalidación también corre por tandas

Un archivo de 9.632 filas no se puede prevalidar en una sola request: es el mismo límite de ~60 s del
proxy que motivó la spec 082, y la resolución de alta/actualización hace consultas a la base por fila.

**Decisión**: la prevalidación usa el **mismo mecanismo de tandas** que ya existe — una request por
tanda, con progreso, acumulando el informe. No se inventa un mecanismo nuevo.

**Consecuencia**: el asistente pasa de 3 pasos a 4 (subir → mapear → **revisar** → resumen). Es un
cambio estructural de pantalla y hay que dejarlo asentado en §2.4.

## Decisión 4 — Mensajes en español sin tocar el locale global

**Causa medida**: `config/app.php` tiene `'locale' => env('APP_LOCALE', 'en')` y **no existe** el
directorio `lang/`. Laravel usa sus mensajes de validación en inglés, y `:attribute` cae en el nombre
del campo con guiones bajos convertidos a espacios: de ahí *"The precio lista 2 field must be a number"*.

**Decisión**: **no** cambiar el locale global de la aplicación. Se le pasa al validador del importador
sus propios mensajes en español y los **nombres visibles de los atributos**, armados desde el mapeo
que el usuario eligió (la etiqueta del campo destino, o el encabezado del archivo).

**Por qué no cambiar `APP_LOCALE` a `es`**: afectaría los mensajes de **toda** la aplicación de golpe
—todos los formularios, no sólo la importación— y eso es una feature aparte, con su propia validación
de pantalla por pantalla. Está fuera del alcance de esta spec. Se deja anotado como candidato futuro.

**Ventaja del enfoque acotado**: el nombre que ve el usuario sale del **mapeo real de esa importación**,
así que puede decir "AHORA 3" (el encabezado del archivo) en vez de "Lista de Precios: AHORA 3" o
`precio_lista_2`. Un locale global nunca podría lograr eso, porque no conoce el mapeo.

## Decisión 5 — Correspondencia exportación ↔ importación

**Verificado**: el único export que existe es `app/Exports/ProductosExport.php`. **No hay** export de
Clientes ni de Proveedores, así que **FR-015 no aplica** por ahora y queda registrado.

**Causa del defecto**: `ProductosExport::headings()` escribe `'Precio venta'`;
`DefinicionCamposImportables` define `'precio_venta' => ['etiqueta' => 'Precio de Venta']` **sin
alias**. El automapeo exige coincidencia exacta (a propósito: no hace matching parcial), así que no
matchea.

**Decisión**: agregar el alias faltante **y** —lo importante— un test que compare **todos** los
encabezados de `ProductosExport` contra las etiquetas y alias de `DefinicionCamposImportables`, y falle
listando los huérfanos. El alias suelto arregla hoy; el test evita que vuelva a pasar mañana (FR-016).

**Precedente**: es exactamente el mismo defecto que la spec 074 arregló para `Stock {depósito}`. Que
haya vuelto a pasar con otra columna es la prueba de que el alias suelto no alcanza y hace falta la
verificación automática.

## Decisión 6 — Causa raíz del resumen contaminado

**Reproducido con test** (26/08/2026), no inferido:

```
>>> clientes creados realmente: 2 | el resumen dice: 1002
```

**Causa**: `ImportacionController::confirmarLote()` arranca con

```php
$acumulado = session('importacion_resultado_parcial', ['importados' => 0, ...]);
```

Esa clave sólo se limpia cuando una importación **termina**. Si se abandona a mitad —que es
exactamente lo que pasó en el incidente del 25/08— queda viva en la sesión del usuario, y la siguiente
importación **suma sobre ella**. Con 1000 residuales e importando 2, informa 1002. En el VPS el
residuo era 1000 y la importación nueva aportó 0, así que informó "1000 registros importados
correctamente" sin haber importado nada.

**Decisión (dos capas, como pidió el usuario)**:

1. **Causa raíz**: el acumulado se ata a la importación en curso. Al empezar una importación nueva
   (Paso 1) se descarta cualquier acumulado anterior. Una importación no puede heredar el estado de otra.
2. **Blindaje**: el resumen deja de ser un número suelto en sesión. Para Productos ya existe la
   `ImportacionCorrida` con su archivo, su fecha y sus contadores — el resumen se arma **desde esa
   corrida**, que es un registro real de lo que pasó, no de lo que quedó en la sesión.

**Detalle a resolver en el plan**: Clientes y Proveedores **no** tienen corrida (la spec 078 sólo cubre
Productos), así que para esas dos entidades el resumen sigue viniendo de la sesión y hay que atarlo a
la importación en curso de otra forma — un identificador de corrida en sesión que se genera en el
Paso 1 y se valida al mostrar el resumen.

## Decisión 7 — Qué pasa entre la prevalidación y la confirmación

La prevalidación mira el estado de la base en el momento en que corre. Entre ese momento y la
confirmación, algo puede cambiar (otro usuario borra un producto, una venta modifica un stock).

**Decisión**: **no** se congela nada ni se bloquean registros. Los conteos son un informe del momento,
el resumen final es la fuente de verdad de lo que pasó (FR-024). Sí se verifica que **el archivo y el
mapeo** sigan siendo los mismos (FR-009), que es el error que sí puede corromper datos —escribir en
columnas equivocadas— y que ya tiene su mecanismo de huella en la spec 082.

**Por qué no bloquear registros**: un lock sobre miles de filas durante minutos, en un sistema con
ventas entrando en vivo, causa más daño que el que evita. El desfasaje real es benigno: una fila
prevista como actualización que termina siendo alta.
