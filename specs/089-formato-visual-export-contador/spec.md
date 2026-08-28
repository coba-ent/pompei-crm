# Feature Specification: Formato visual del Excel del Libro IVA (Ventas / Compras)

**Feature Branch**: `089-formato-visual-export-contador`

**Created**: 2026-08-28

**Status**: Draft

**Input**: El Excel que exporta y envía por correo el informe "Información para tu Contador" no tiene
ningún formato: sin encabezado del negocio, sin estilos de encabezado de columna, fechas e importes
como texto plano, columnas sin ancho, y sin la sección de totales al pie. El Excel real de Contagram
(entregado por el usuario el 28/08/2026 como fuente de verdad) sí tiene todo eso. Hay que calcarlo.

## Contexto

El contenido del Libro IVA ya está cerrado y verificado: tras las specs 077, 086, 088 y las
correcciones del 28/08/2026 (partición ARCA/Manuales de NC/ND, reposición de comprobantes fiscales
migrados, exclusión de ventas sin comprobante fiscal), el informe **reproduce Contagram peso por peso**
—cantidad de filas exacta y diferencias de centavos por redondeo— contra un clon de producción.

Lo que **nunca se abordó** es la presentación del archivo Excel. El §6.7 de
`docs/documentacion_principal_crm.md` documenta la pantalla en detalle pero no dice una palabra del
formato del export, porque el relevamiento original (`docs/informe_contagram_contador/`) tiene capturas
de la pantalla, no del archivo descargado.

El usuario aportó el 28/08/2026 el Excel real que exporta Contagram para Agosto 2026, guardado como
fixture en `tests/Fixtures/LibroIvaExport/IVA Ventas Agosto 2026 Contagram.xlsx`. **Es la fuente de
verdad estructural de esta spec** (principio rector de `CLAUDE.md`).

### Qué se calca y qué no

Se calca el **formato**: encabezado del negocio, estilos, tipos de dato, totales al pie, anchos.

**No** se calca la **lista de columnas**. Contagram emite 13 columnas con una sola columna de IVA
(`IVA 21%`); el CRM emite 19 con las cinco alícuotas discriminadas más percepciones e impuestos
internos/municipales — decisión ya tomada y validada en la spec 077, que es funcionalmente superior
(un período con IVA 10,5% en Contagram queda sin desglosar). Reducirlas sería una regresión.

## Clarifications

### Session 2026-08-28

- Q: ¿Se reduce el export a las 13 columnas de Contagram para calcarlo exactamente? → A: **No.** Se
  calca el formato visual, no la lista de columnas: las 19 columnas de la spec 077 se conservan porque
  discriminan las 5 alícuotas y las percepciones, cosa que las 13 de Contagram no hacen.
- Q: El archivo de Contagram muestra `Razón` como `Raz�n` (carácter roto). ¿Se replica ese defecto? →
  A: **No.** Es un defecto de codificación del origen, no una decisión de diseño. El CRM emite los
  acentos correctamente. (El export del CRM hoy tiene el mismo síntoma por su propio bug de
  codificación — se corrige acá, ver FR-014.)
- Q: ¿Las fechas van como valor de fecha de Excel o como texto? El proyecto tiene una convención
  opuesta y documentada en `MovimientosExport` (texto, para que el locale del lector no las
  reinterprete). → A: **Valor de fecha real con formato `DD/MM/YYYY`**, igual que el fixture de
  Contagram — un libro IVA se ordena y filtra por fecha, y el formato explícito en la celda es la misma
  mitigación que usa el original. Es una divergencia deliberada respecto de Tesorería, cuyo caso (dos
  fechas sueltas de cabecera, no una columna) no tiene esa necesidad.
- Q: Con 19 columnas (6 más que Contagram), ¿cómo se maneja el ancho de la hoja? → A: **Igual a
  Contagram**: anchos ajustados al contenido de cada columna y hoja en orientación **apaisada**, como
  el fixture (FR-008, FR-016).

## User Scenarios & Testing *(mandatory)*

### User Story 1 - El contador recibe un Excel con el encabezado del negocio y el período (Priority: P1)

El contador abre el adjunto que le llegó por correo y ve arriba de todo la razón social, el CUIT, el
título del libro y el período — igual que el archivo que venía recibiendo de Contagram — antes de la
tabla de comprobantes.

**Why this priority**: sin el encabezado, el archivo no se identifica solo. Un contador que maneja
varios clientes recibe una planilla anónima que no dice de qué negocio ni de qué mes es; hoy la única
pista es el nombre del archivo adjunto.

**Independent Test**: exportar el Libro IVA Ventas de cualquier período y verificar que las primeras
filas traen razón social, CUIT, título y período, con los datos reales de la empresa configurada.

**Acceptance Scenarios**:

1. **Given** el Libro IVA Ventas de un período cualquiera, **When** se exporta a Excel, **Then** la
   primera fila muestra la razón social del negocio y la segunda su CUIT, ambas en negrita.
2. **Given** ese mismo archivo, **When** se lo mira, **Then** el título del libro ("Libro IVA Ventas" o
   "Libro IVA Compras" según la pestaña) aparece centrado y en cuerpo grande, con el período debajo
   escrito en castellano ("Periodo: Agosto de 2026").
3. **Given** una empresa sin razón social o sin CUIT cargados en Configuración, **When** se exporta,
   **Then** el archivo se genera igual, sin romperse, dejando esos renglones vacíos.

---

### User Story 2 - La tabla se lee como una planilla contable, no como un volcado de datos (Priority: P1)

El contador recorre las 19 columnas y distingue de un vistazo los encabezados del cuerpo, ve las fechas
como fechas, los importes alineados a la derecha con dos decimales, y no tiene que ensanchar cada
columna a mano para leer los nombres de los clientes.

**Why this priority**: es el grueso del valor. Un Excel con importes como `-338561.56` en texto plano y
columnas de ancho default es incómodo de leer y, peor, **no se puede sumar ni filtrar sin retocarlo**.

**Independent Test**: exportar y verificar que la fila de encabezados tiene fondo azul con texto
blanco, que las fechas se ordenan como fechas (no como texto), que los importes tienen formato numérico
de dos decimales, y que ninguna columna corta su contenido.

**Acceptance Scenarios**:

1. **Given** el Excel exportado, **When** se mira la fila de encabezados de columna, **Then** tiene
   fondo azul sólido con el texto en blanco, centrado, y se distingue claramente del cuerpo.
2. **Given** la columna Emisión, **When** se ordena por ella, **Then** ordena cronológicamente (es un
   valor de fecha real, no texto), y se muestra en formato día/mes/año.
3. **Given** cualquier columna de importe, **When** se la selecciona, **Then** Excel muestra su suma en
   la barra de estado (son números, no texto) y los valores se ven alineados a la derecha con dos
   decimales, los negativos entre paréntesis.
4. **Given** la columna de Cliente/Proveedor con nombres largos, **When** se abre el archivo, **Then**
   el ancho de la columna alcanza para leerlos sin retocar nada.

---

### User Story 3 - El pie del libro muestra los totales desglosados (Priority: P2)

Al final de la tabla, el contador ve tres renglones de totales: lo facturado, lo de notas de crédito, y
el total neto del período — que es lo que necesita para volcar a la declaración.

**Why this priority**: hoy los totales están **arriba** de la tabla (la barra de 5 KPIs de la pantalla,
volcada tal cual) y sin desglosar entre facturación y notas. Contagram los pone al pie y separados, que
es el orden en que un contador los usa. Aporta valor real pero el archivo ya sirve sin esto.

**Independent Test**: exportar un período que tenga facturas y notas de crédito, y verificar los tres
renglones al pie con los subtotales correctos y el total en negrita.

**Acceptance Scenarios**:

1. **Given** un período con facturas y notas de crédito, **When** se exporta, **Then** al pie aparecen
   los renglones "Por Facturación:", "Por Nota de Crédito:" y "Totales:", cada uno con sus importes por
   columna.
2. **Given** esos tres renglones, **When** se los suma, **Then** "Por Facturación" más "Por Nota de
   Crédito" da exactamente "Totales" en cada columna de importe.
3. **Given** un período sin notas de crédito, **When** se exporta, **Then** el renglón de notas aparece
   en cero y el total coincide con el de facturación.

---

### Edge Cases

- **Período vacío**: el archivo se genera igual, con encabezado, fila de títulos de columna y los
  totales en cero — no un archivo en blanco ni un error.
- **Empresa sin datos cargados** (sin razón social/CUIT): ver US1 escenario 3, el archivo no se rompe.
- **Nombres con acentos y `Ñ`** (clientes, condición de IVA, "Periodo: Diciembre"): deben verse
  correctamente en Excel; hoy se ven rotos (`Emisi�n`, `N�`).
- **Un período con muchas filas** (cientos de comprobantes): el formato se aplica a todas las filas de
  datos, no sólo a las primeras.

## Requirements *(mandatory)*

### Encabezado del negocio

- **FR-001**: El archivo MUST comenzar con un bloque de encabezado, antes de la tabla, que muestre la
  razón social del negocio, su CUIT, el título del libro y el período.
- **FR-002**: El título MUST distinguir Ventas de Compras ("Libro IVA Ventas" / "Libro IVA Compras") y
  MUST presentarse centrado y en un cuerpo notoriamente mayor al del resto.
- **FR-003**: El período MUST escribirse en castellano con el mes en palabra ("Periodo: Agosto de
  2026"), no como número de mes.
- **FR-004**: Si la empresa no tiene razón social o CUIT configurados, el archivo MUST generarse igual
  con esos renglones vacíos.

### Formato de la tabla

- **FR-005**: La fila de encabezados de columna MUST tener fondo de color sólido con el texto en
  blanco y centrado, visualmente separada del cuerpo de datos.
- **FR-006**: Las fechas MUST emitirse como valores de fecha (ordenables y filtrables como tales),
  mostradas en formato día/mes/año.
- **FR-007**: Los importes MUST emitirse como valores numéricos con dos decimales, alineados a la
  derecha, con los negativos entre paréntesis.
- **FR-008**: Los anchos de columna MUST alcanzar para el contenido esperado de cada una, sin que el
  usuario tenga que ajustarlos a mano.
- **FR-009**: El archivo MUST usar una tipografía uniforme y legible en todo el documento, con el
  cuerpo de datos en un tamaño menor que el de los títulos.
- **FR-010**: El archivo MUST conservar las **19 columnas** del contrato de la spec 077, en el mismo
  orden — esta spec cambia la presentación, no el contenido.
- **FR-016**: La hoja MUST quedar en orientación apaisada, igual que el archivo de Contagram.

### Totales

- **FR-011**: El archivo MUST cerrar con tres renglones de totales al pie de la tabla: por facturación,
  por notas de crédito, y el total del período.
- **FR-012**: El renglón de total del período MUST destacarse visualmente de los otros dos.
- **FR-013**: Los tres renglones MUST ser consistentes entre sí: facturación más notas de crédito igual
  al total, en cada columna de importe.

### Corrección de defectos actuales

- **FR-014**: ~~Los textos con acentos y `Ñ` MUST verse correctamente en el archivo.~~ **SIN EFECTO —
  falso positivo, verificado el 28/08/2026 durante la implementación (T011).** Los bytes del
  `sharedStrings.xml` del archivo que genera el CRM son `c3 b3` (= `ó` en UTF-8 correcto); el `�` que se
  observó era la consola de Windows al imprimir, no el archivo. El mismo síntoma aparecía al leer el
  fixture de Contagram, que también está bien. Se deja el requisito tachado en vez de borrarlo para que
  nadie vuelva a "arreglar" un encoding que nunca estuvo roto.
- **FR-015**: Los totales que hoy se emiten **arriba** de la tabla (la barra de KPIs de la pantalla)
  MUST dejar de emitirse ahí, reemplazados por los del pie (FR-011).

### Key Entities

Ninguna entidad de datos nueva: esta spec cambia exclusivamente la presentación de un archivo ya
existente. Los datos y su cálculo son los de las specs 077 / 086 / 088, sin modificación.

## Success Criteria *(mandatory)*

- **SC-001**: Un contador que recibía el Excel de Contagram puede usar el del CRM sin retocar formato:
  sin ensanchar columnas, sin convertir texto a número ni a fecha.
- **SC-002**: Los importes de cualquier columna se pueden sumar y filtrar directamente en Excel, sin
  conversión previa.
- **SC-003**: El archivo identifica por sí solo de qué negocio y de qué período es, sin depender del
  nombre del adjunto.
- **SC-004**: El contenido (filas, importes, columnas) del archivo es **idéntico** al que se emite hoy —
  esta spec no cambia ningún número, sólo cómo se ve.
- **SC-005**: El mismo formato se aplica al Libro IVA Ventas y al de Compras, y tanto a la descarga
  directa desde la pantalla como al adjunto que viaja por correo (spec 087).

## Assumptions

- La razón social y el CUIT salen de los datos de empresa ya existentes (`datos_empresa`, usados hoy en
  los PDF de comprobantes); esta spec no agrega configuración nueva.
- El fixture `tests/Fixtures/LibroIvaExport/IVA Ventas Agosto 2026 Contagram.xlsx` es la referencia de
  formato. Sólo existe la variante Ventas; para Compras se aplica el mismo criterio, adaptando el
  título y los rótulos de columna que ya define la spec 077.
- Contagram muestra un carácter roto donde va `ó` (`Raz�n Social`). Es un defecto del origen y **no** se
  replica (ver Clarifications).
- El color de encabezado, la tipografía y los tamaños se toman del fixture; no se inventa una paleta.

## Dependencies

- **Spec 077**: define las 19 columnas, los filtros y el cálculo de los importes. Esta spec no los toca.
- **Spec 087**: el adjunto del correo al contador usa el mismo generador, así que hereda el formato sin
  trabajo adicional (SC-005).
- **Spec 088**: los comprobantes históricos entran al Libro IVA por la misma vía; se formatean igual que
  cualquier otra fila, sin distinción visual.

## Out of Scope

- Cambiar qué columnas se emiten, su orden o su cálculo (spec 077).
- El formato de los archivos TXT del IVA Digital (spec 086): son de ancho fijo para ARCA, no tienen
  presentación.
- El cuerpo del correo o los nombres de los archivos adjuntos (spec 087).
- Formato de impresión (márgenes, repetición de encabezados por página, pie de página).
- Aplicar este formato a los otros informes del módulo (Ventas, Compras, Gastos, etc.).
