# Feature Specification: Espejo del comprobante de origen al crear una NC/ND

**Feature Branch**: `095-espejo-comprobante-ncnd`

**Created**: 2026-09-02

**Status**: Draft

**Input**: User description: "Espejo total del comprobante de origen al crear una Nota de Crédito/Débito. Relevamiento propio en Contagram real (02/09/2026): al abrir 'Crear NC/ND' sobre una venta, Contagram precarga el paso 2 con el comprobante entero, de modo que la nota nace valiendo exactamente lo mismo que la venta y el usuario resta lo que no corresponda."

## Contexto y evidencia

Reportado por el cliente: *"cuando voy a crear una nota de crédito, no me trae correctamente todos los datos de la venta. A veces le falta el descuento."*

Al verificarlo aparecieron **seis** datos que no se traen, no uno.

### Cómo lo hace Contagram (relevado el 02/09/2026)

Relevamiento directo sobre la cuenta real, venta 34925956 ($6.382,75, 3 productos). Al abrir "Crear
NC/ND" el paso 2 viene con **el comprobante entero precargado**: Cliente, Categoría, las 4 fechas
(Emisión, Vto. del Cobro, Servicio Desde/Hasta), Tipo de comprobante (`B`, el mismo de la venta),
N° de factura que ajusta, los 3 productos con cantidad/precio/IVA/descuento de línea, el campo
Descuento General propio de la nota, y los bloques +Percepciones/+Impuestos Internos/+Intereses.

**El Total precargado era `$6.382,75`, idéntico al de la venta.** Ese dato es el que define el
criterio: la nota nace valiendo lo mismo que el comprobante y el usuario **resta** lo que no
corresponda, en vez de armarla desde cero.

**Decisión sobre el descuento, tomada por observación y no por criterio propio**: Contagram mantiene
dos niveles separados y **no prorratea** el descuento general en las líneas. En su formulario
conviven `note[discount]` (general, de cabecera) y `note[note_products_attributes][N][discount]`
(por línea), más `line_discount` y `general_discount` por producto. El descuento general del
comprobante se replica entonces en la **cabecera** de la nota — que es exactamente el campo
`descuento_general_tipo`/`_pct`/`_monto` que la tabla `notas_credito_debito` ya tiene hoy.

### Qué hace el CRM hoy

El alta precarga **sólo los ítems** (producto, cantidad, precio, IVA y descuento **de línea**). El
resto del formulario nace vacío: Tipo de comprobante sin valor, Descuento General sin valor y Total
en `$0,00`.

| Dato del comprobante | Contagram | CRM hoy |
| --- | --- | --- |
| Ítems + IVA + descuento de línea | Sí | Sí |
| **Descuento general (cabecera)** | Sí | **No** |
| **Tipo de comprobante** | Sí | **No** |
| **Cliente / Categoría** | Sí | **No** |
| **Las 4 fechas** | Sí | **No** |
| **N° del comprobante que ajusta** | Sí | **No** |
| **Percepciones / Impuestos internos** | Sí | **No** |
| **Total precargado** | Sí | **No** ($0,00) |

### Qué cuesta hoy (medido sobre la base real)

- Venta 24740 (descuento general del 5%): una NC total armada con lo que precarga hoy da
  **$229.956,12** contra **$218.458,32** reales — **$11.497,80 de más**.
- **9.203 ventas** tienen descuento general (por $96.855.047,99 en descuentos) y **374 compras**.
  De esas ventas, **260 ya tienen alguna NC emitida**.
- La bonificación **por línea** sí se espeja bien hoy (verificado en la venta 24677): el hueco es
  exclusivamente el descuento **general** de cabecera.
- Hay **13 notas** con el tipo de comprobante cruzado respecto de su venta (12 de A→B, 1 de B→A),
  consistente con que el campo hoy nace vacío y se completa a mano.

## Clarifications

### Session 2026-09-02

- Q: Cuando ya existe una NC previa sobre el mismo comprobante, ¿la precarga trae la cantidad facturada original o la pendiente de ajuste? → A: Manda lo pendiente; el total coincide con el del comprobante sólo cuando no hay notas previas.
- Q: Con el descuento general heredado en modo monto fijo, ¿qué pasa si al quitar líneas ese monto supera el subtotal restante? → A: Se hereda igual; si al ajustar supera el subtotal se avisa y se pide corregirlo antes de guardar.
- Q: El Tipo de Comprobante precargado, ¿queda libremente editable o se protege? → A: Editable, pero si difiere del comprobante de origen el sistema advierte antes de guardar.
- Q: En una nota con "afecta stock = No" (sin ítems, sólo descripción y monto), ¿qué se precarga? → A: Sólo la cabecera (fechas, tipo, tercero, categoría); monto y descripción quedan vacíos.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Anular una venta completa sin recalcular a mano (Priority: P1)

Una administrativa tiene que anular por completo una venta que se facturó con un 15% de descuento
general. Abre "Crear NC/ND", elige Crédito, y espera que la nota ya valga lo mismo que la venta para
sólo confirmarla.

**Why this priority**: es el caso más frecuente y el que hoy produce notas por importes equivocados.
Sin esto, la persona tiene que mirar la venta en otra pantalla y reconstruir el descuento de memoria.

**Independent Test**: crear una NC sobre una venta con descuento general y verificar, antes de tocar
nada, que el total de la nota coincide peso por peso con el total de la venta.

**Acceptance Scenarios**:

1. **Given** una venta con descuento general del 15%, **When** el usuario abre el alta de NC/ND con
   "afecta stock = Sí", **Then** el Descuento General de la nota viene precargado en 15% y el total
   propuesto coincide con el total de la venta.
2. **Given** una venta con descuento general expresado como monto fijo, **When** se abre el alta,
   **Then** la nota arranca en modo monto con ese mismo importe (no convertido a porcentaje).
3. **Given** una venta sin ningún descuento, **When** se abre el alta, **Then** el Descuento General
   queda vacío y el total propuesto sigue coincidiendo con el de la venta.
4. **Given** una venta con bonificación por línea, **When** se abre el alta, **Then** cada línea
   conserva su propio porcentaje y el descuento general no la pisa ni la duplica.

---

### User Story 2 - Que la nota nazca con el tipo de comprobante correcto (Priority: P1)

Sobre una factura A, la nota tiene que ser A. Hoy el campo nace vacío y depende de que la persona
elija bien.

**Why this priority**: una NC con el tipo equivocado es un problema ante ARCA que **no se corrige
editando** — hay que anularla y emitir otra. Es la consecuencia más cara de este hueco, y el
principio III de la constitución ya exige que el tipo se derive en vez de elegirse a mano.

**Independent Test**: abrir el alta de NC sobre una factura A y verificar que el Tipo de Comprobante
viene en A sin intervención del usuario.

**Acceptance Scenarios**:

1. **Given** una venta con comprobante tipo A, **When** se abre el alta de NC/ND, **Then** el Tipo
   de Comprobante de la nota viene precargado en A.
2. **Given** una venta tipo B, **When** se abre el alta, **Then** el tipo viene en B.
3. **Given** una venta sin comprobante fiscal (tipo vacío o "Sin Factura"), **When** se abre el alta,
   **Then** el campo queda vacío y no se inventa un tipo.

---

### User Story 3 - Anular sólo una parte de la venta (Priority: P2)

La misma administrativa tiene que devolver dos de los cinco productos de una venta. Necesita partir
del comprobante completo y **quitar** lo que no va.

**Why this priority**: es lo que evita que "precargar" se convierta en "imponer". Sin esta garantía,
el espejo resolvería el caso completo pero rompería el parcial, que también es habitual.

**Independent Test**: abrir el alta de NC sobre una venta de 5 ítems, borrar 3 líneas y cambiar el
descuento general, y verificar que la nota guarda exactamente lo que quedó en pantalla.

**Acceptance Scenarios**:

1. **Given** el formulario precargado, **When** el usuario elimina líneas o cambia cantidades,
   **Then** los totales se recalculan y se guarda lo que quedó, no lo precargado.
2. **Given** el formulario precargado, **When** el usuario borra o cambia el Descuento General,
   **Then** se respeta su valor, incluso vacío.
3. **Given** el formulario precargado, **When** el usuario cambia el Tipo de Comprobante por uno
   distinto al del comprobante de origen, **Then** el sistema le advierte del riesgo y, si confirma,
   guarda el que eligió.

---

### User Story 4 - Mismo comportamiento en Compras (Priority: P2)

Quien carga una NC/ND sobre una compra necesita el mismo espejo: proveedor, fechas, tipo y descuento
del comprobante del proveedor.

**Why this priority**: son 374 compras con descuento general expuestas al mismo error. El módulo es
espejo de Ventas y una asimetría acá se convierte en un segundo reporte del cliente.

**Independent Test**: crear una NC sobre una compra con descuento general y verificar que precarga
igual que en Ventas.

**Acceptance Scenarios**:

1. **Given** una compra con descuento general, **When** se abre el alta de NC/ND, **Then** precarga
   los mismos campos que en Ventas, con el Proveedor en lugar del Cliente.
2. **Given** una compra sin descuento, **When** se abre el alta, **Then** el total propuesto coincide
   con el de la compra.

---

### Edge Cases

- **Ítems ya ajustados por notas anteriores**: la precarga tiene que partir de lo que queda
  pendiente de ajustar, no de lo facturado original — si no, una segunda NC propondría anular de
  nuevo lo ya anulado.
- **Comprobante sin ítems con producto** (sólo conceptos o descripción libre): la nota no debe
  romperse; precarga cabecera y deja el detalle vacío.
- **Nota con "afecta stock = No"**: no lleva ítems. Se precarga **sólo la cabecera** (fechas, tipo
  de comprobante, tercero y categoría); el monto y la descripción quedan vacíos, porque el caso
  típico es un ajuste por un importe distinto al del comprobante (FR-013).
- **Comprobante migrado sin depósito o sin categoría**: los campos que no existen quedan vacíos, sin
  bloquear el alta.
- **Descuento general en modo monto sobre una anulación parcial**: el monto fijo del comprobante
  completo puede exceder el subtotal de las líneas que quedaron. El sistema lo hereda igual y avisa
  para que el usuario lo corrija; nunca guarda un total negativo ni lo reajusta solo (FR-012).
- **Editar una nota existente**: no cambia — sigue precargando desde la nota, no desde el
  comprobante.
- **Comprobante de origen eliminado**: no se ofrece crear una nota sobre él; si se llega a la
  pantalla, se informa en vez de precargar datos de un documento dado de baja (FR-016).

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: Al abrir el alta de una NC/ND desde una Venta o una Compra, el sistema DEBE precargar
  el formulario con los datos del comprobante de origen, de modo que el total propuesto para la nota
  coincida con el total del comprobante cuando no se ajusta nada. Sobre un comprobante que ya tiene
  notas anteriores, el total propuesto refleja lo que queda **pendiente de ajustar** (FR-009), que
  manda por encima de esta coincidencia.
- **FR-002**: El sistema DEBE precargar el **Descuento General** de la nota con el del comprobante de
  origen, respetando su modalidad: porcentaje si el comprobante usa porcentaje, monto fijo si usa
  monto.
- **FR-003**: El descuento general DEBE replicarse en la **cabecera** de la nota y NO prorratearse
  entre las líneas, conservando además el descuento propio de cada línea sin alterarlo.
- **FR-004**: El sistema DEBE precargar el **Tipo de Comprobante** de la nota con el del comprobante
  de origen. Si el comprobante no tiene tipo (vacío o "Sin Factura"), el campo queda vacío.
- **FR-004a**: El Tipo de Comprobante sigue siendo editable, pero si el usuario elige uno distinto al
  del comprobante de origen el sistema DEBE advertírselo antes de guardar, explicando que una nota
  con el tipo cruzado no se corrige editando: hay que anularla y emitir otra. La advertencia informa,
  no bloquea — hay comprobantes migrados donde el tipo legítimo puede diferir.
- **FR-005**: El sistema DEBE precargar las fechas de la nota (Emisión, Vencimiento, Servicio Desde
  y Servicio Hasta) tomándolas del comprobante de origen; si alguna no está cargada allí, cae en la
  fecha de Emisión del comprobante.
- **FR-006**: El sistema DEBE mostrar los datos del tercero (Cliente en Ventas, Proveedor en Compras)
  y su Categoría heredados del comprobante de origen.
- **FR-007**: El sistema DEBE precargar las percepciones e impuestos internos del comprobante de
  origen en los bloques correspondientes de la nota.
- **FR-008**: Todos los campos precargados DEBEN quedar editables antes de guardar; el sistema guarda
  lo que el usuario dejó en pantalla, no lo precargado. El único campo con tratamiento especial es el
  Tipo de Comprobante, que se puede cambiar pero avisa cuando difiere del origen (FR-004a).
- **FR-009**: La precarga de ítems DEBE seguir partiendo de la cantidad **pendiente de ajuste** de
  cada producto, descontando lo ya cubierto por notas anteriores sobre el mismo comprobante. Este
  criterio prevalece sobre la coincidencia de totales de FR-001: evita que una segunda nota proponga
  anular de nuevo lo ya anulado.
- **FR-010**: El comportamiento DEBE ser equivalente en Ventas y en Compras, con la única diferencia
  del tercero mostrado.
- **FR-011**: La edición de una NC/ND existente NO DEBE cambiar: sigue precargando desde la nota
  guardada y no desde el comprobante de origen.
- **FR-013**: En una nota con "afecta stock = No" el sistema DEBE precargar únicamente la cabecera
  (fechas, tipo de comprobante, tercero y categoría). El monto y la descripción quedan vacíos: sin
  ítems no hay subtotal del cual derivar un importe, y ese tipo de nota suele ajustar por un valor
  distinto al del comprobante. El Descuento General no aplica en este caso.
- **FR-014**: La coincidencia de importes entre la nota y el comprobante se evalúa con la misma
  tolerancia que el resto del sistema usa para comparar dinero (medio centavo), y los totales se
  redondean a dos decimales. Una diferencia por debajo de esa tolerancia se considera coincidencia.
- **FR-015**: La condición de total no negativo (FR-012) DEBE evaluarse **al intentar guardar**, no
  mientras el usuario edita: durante la carga los importes pasan por estados intermedios inválidos de
  forma normal, y avisar en cada tecla sería ruido.
- **FR-016**: El sistema NO DEBE ofrecer crear una NC/ND sobre un comprobante eliminado. Si se llega
  igual a esa pantalla, se informa que el comprobante no está disponible en vez de precargar datos de
  un documento dado de baja.
- **FR-012**: El sistema NO DEBE permitir que la precarga derive en un total negativo. El descuento
  general en modo monto fijo se hereda tal cual del comprobante; si al quitar o reducir líneas ese
  monto pasa a superar el subtotal restante, el sistema DEBE avisar al usuario y pedirle que lo
  corrija antes de guardar, sin ajustarlo por su cuenta ni convertirlo a porcentaje.

### Key Entities

- **Nota de Crédito/Débito**: documento con ítems, IVA y descuento general propios; ya cuenta con los
  campos de cabecera necesarios (`descuento_general_tipo`, `descuento_general_pct`,
  `descuento_general_monto`, `tipo_comprobante`) — este trabajo los llena, no los crea.
- **Comprobante de origen** (Venta o Compra): fuente de todos los valores precargados.
- **Ítem de la nota**: línea con producto, cantidad, precio, descuento de línea e IVA.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Al abrir una NC/ND sobre un comprobante **sin notas previas** que tiene descuento
  general, y no modificar nada, el total propuesto coincide peso por peso con el total del
  comprobante (diferencia $0,00). Hoy en la venta 24740 esa diferencia es de $11.497,80. Si el
  comprobante ya tiene notas, el total propuesto refleja lo que queda pendiente de ajustar (FR-009).
- **SC-002**: Los 8 datos de la tabla comparativa (ítems, descuento de línea, descuento general,
  tipo de comprobante, tercero/categoría, fechas, percepciones, total) llegan precargados al abrir el
  alta, tanto en Ventas como en Compras.
- **SC-003**: Ninguna NC/ND nueva nace con un tipo de comprobante distinto al del comprobante que
  ajusta, salvo que el usuario lo cambie a propósito habiendo visto la advertencia.
- **SC-004**: Una persona puede emitir una NC que anula una venta completa sin consultar otra
  pantalla ni recalcular importes a mano.
- **SC-005**: Una anulación parcial sigue siendo posible: quitar líneas o cambiar el descuento
  guarda exactamente lo que quedó en pantalla.
- **SC-006**: Las notas ya existentes no se modifican por este cambio.

## Assumptions

- El criterio "espejo" se toma del comportamiento observado en Contagram real el 02/09/2026, no de
  una preferencia de diseño propia.
- Precargar el descuento general en la cabecera (y no prorratearlo) se decide por la misma
  observación: Contagram mantiene los dos niveles separados.
- Los ítems siguen partiendo de la cantidad pendiente de ajuste, como hoy; el espejo suma la cabecera
  y no reemplaza esa lógica ya validada.
- El campo "Mes de Imputación" mantiene su comportamiento actual (spec 045): por defecto el mes de la
  fecha de emisión.
- La nota conserva su propio N° de comprobante, que no se hereda del comprobante de origen; lo que se
  hereda es el **tipo**, y el número del comprobante ajustado se muestra como referencia.

## Riesgo conocido — fuera de alcance

Durante el relevamiento se detectó que la **edición** de una NC/ND valida menos que la eliminación:
al editar sólo se bloquea por CAE aprobado, mientras que al eliminar se valida además el crédito ya
aplicado y las notas encadenadas. Hay **2 notas reales (856 y 859)** sin CAE, con crédito ya aplicado
a otros comprobantes, hoy editables sin ninguna traba: bajarles el monto dejaría el saldo aplicado
por encima del monto de la nota.

**No se resuelve en este spec** — queda registrado para tratarse por separado.

## Dependencies

- Módulo NC/ND ya existente (specs 045, 057, 059, 061, 062): wizard de 2 pasos, página completa de
  alta/edición y cálculo de totales.
- Cálculo de cantidad pendiente de ajuste por producto, ya en uso.
- Este trabajo no requiere cambios en el esquema de datos: los campos de cabecera de la nota ya
  existen.

---

## Verificación contra la base real (02/09/2026)

Comprobado en local sobre la base clon, replicando el cálculo del front (precio bruto × IVA ×
factor de descuento general). Confirma la premisa de la spec:

| Comprobante | Total del comprobante | Sin heredar el descuento | Heredándolo (implementado) |
| --- | --- | --- | --- |
| Venta 24740 (A, 5%) | 218.458,32 | **229.956,12** (+11.497,80) | 218.458,32 ✅ |
| Venta 24741 (B, 15%) | 103.906,03 | 122.242,39 (+18.336,36) | 103.906,03 ✅ |
| Venta 24677 (B, sin desc.) | 3.008,16 | 3.008,16 | 3.008,16 ✅ |
| Compra 2442 (A, 7%) | 468.700,81 | 504.001,95 (+35.301,14) | 468.700,81 ✅ |

El $229.956,12 de la venta 24740 coincide exactamente con el valor que el quickstart había
identificado como el total erróneo, lo que confirma tanto el diagnóstico como el arreglo.

**Detalle que explica por qué no hay doble descuento**: la Venta guarda en cada ítem el `subtotal`
ya descontado (3.393,69 sobre un bruto de 3.572,30), pero `itemsDisponibles()` sirve el
`precio_unitario` **bruto** con `descuento_pct = 0`. El descuento general heredado se aplica sobre
ese bruto, una sola vez.
