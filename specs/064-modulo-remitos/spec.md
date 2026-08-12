# Feature Specification: Módulo de Remitos (Ventas y Compras)

**Feature Branch**: `064-modulo-remitos`

**Created**: 2026-08-12

**Status**: Draft

**Input**: User description: "Módulo de Remitos completo para Ventas y Compras, con fidelidad estructural a Contagram según `docs/Contagram-Informe-Remitos.md` y las 12 capturas en `docs/capturas/Capturas-Remitos/`."

## Contexto del problema

El CRM tiene hoy un botón **"Crear Remito"** que funciona a medias: crea un registro con fecha y un
número correlativo, y **nada más**. El resultado es una funcionalidad que no sirve para nada:

| Lo que existe | Lo que falta |
|---|---|
| Botón "Crear Remito" en el detalle de la Venta | El remito no tiene **ítems**: no dice qué se entrega |
| Registro con `fecha` + `nro_remito` | **No se puede ver**: se cargan en el detalle pero nunca se renderizan |
| Filtros "Con/Sin Remito" y por N° en el listado | **No se puede imprimir**: no hay PDF |
| — | **No se puede editar ni eliminar** |
| — | No hay **transportista**, ni domicilio de entrega, ni nota, ni bultos |

El propio modelo lo admite en un comentario: *"Encabezado mínimo (FR-018); detalle de ítems pendiente
de relevamiento propio"*, y `docs/documentacion_principal_crm.md` §5 lo declara como brecha abierta:
*"Remitos: estructura de pantalla real de Contagram sigue sin relevar con capturas"*.

**Ese relevamiento ya existe** (`docs/Contagram-Informe-Remitos.md`, agosto 2026, con 12 capturas de
la app real y verificación práctica: alta, edición, PDF y segundo remito sobre la misma venta). Esta
spec cierra la brecha.

Se detectaron además **tres bugs** en lo poco que hay: el tag del botón "Crear Remito" no cierra su
etiqueta (`<button ... id="btn-crear-remito"` sin `>`, así que el ícono no se renderiza), el menú de
fila del listado apunta a `ventas.show#remitos` —un ancla inexistente, que además viola la regla del
proyecto de no usar URLs con `#`—, y los remitos cargados en el detalle nunca se muestran.

## Qué es un remito (y qué NO es)

Un remito documenta la **entrega física** de la mercadería: qué productos, en qué cantidad, con qué
transportista y a qué domicilio. Es un documento **logístico e interno**, no fiscal.

Dos consecuencias que ordenan todo el diseño, verificadas en la documentación oficial de Contagram y
alineadas con el Principio de la constitución (*"stock se afecta al vender/comprar no al remitir"*):

- **No mueve stock.** Ni al crear, ni al editar, ni al eliminar. El stock ya se descontó al crear la
  Venta (o se ingresó al crear la Compra). Remitir no vuelve a tocar el inventario.
- **No es fiscal.** No tiene CAE, no se presenta ante ARCA, no lleva precios ni IVA.

## Clarifications

### Session 2026-08-12

Las cuatro decisiones de alcance se resolvieron con el usuario **antes** de redactar la spec:

- Q: ¿Sólo Ventas, o también Compras? → A: **Ventas y Compras.** El botón de Compras tiene hoy
  exactamente el mismo hueco; dejarlo a medias repetiría el problema.
- Q: ¿El Transportista lleva pantalla de ABM propia? → A: **No.** Sólo alta al vuelo desde el modal
  del remito (que pide únicamente Nombre), igual que Contagram. Sin pantalla en Base de Datos.
- Q: ¿La numeración se pasa a manual como Contagram (`____-________`)? → A: **No, se mantiene
  autonumérica correlativa** como está hoy en el CRM. Es una **divergencia deliberada** respecto del
  original, que se documenta en `docs/documentacion_principal_crm.md`.
- Q: ¿Qué se hace con los 3 remitos ya existentes en producción? → A: **Se elimina el N° 3** (creado
  por accidente el 12/08/2026 sobre la Venta 24038) y **se conservan el 1 y el 2**.

El barrido de ambigüedades posterior encontró tres puntos que el informe no cubría (relevó sólo
Ventas) y que impactan el modelo de datos:

- Q: En una Compra la mercadería viene **hacia** el negocio. ¿Qué va en "Domicilio de Entrega"? →
  A: **El depósito del negocio** (el de la Compra, o el domicilio del negocio), editable. Es a dónde
  realmente llega la mercadería; la simetría literal con Ventas —poner el domicilio del proveedor—
  documentaría el origen y no el destino.
- Q: Si se elimina la Venta o Compra que tenía remitos, ¿qué pasa con ellos? → A: **Se eliminan junto
  con ella.** El remito documenta la entrega *de esa operación*; sin ella no tiene dónde mostrarse ni
  qué documentar.
- Q: Al eliminar un remito, ¿borrado real o soft delete? → A: **Borrado real.** No es un documento
  fiscal ni contable, así que no le alcanza la exigencia de soft delete del Principio III de la
  constitución (que rige para ventas, compras, gastos y comprobantes fiscales).

Una cuarta ambigüedad —si el formulario va en modal o en página completa— **no se preguntó**: ya está
resuelta por precedente. La spec 059 estableció que los formularios con tabla de ítems van en página
completa por fidelidad estructural (mismo caso que NC/ND), y la captura 02 muestra "Nuevo Remito Venta
ID 5" como página completa. La regla de UI del proyecto sobre modales aplica al ABM simple, no a este
tipo de formulario.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Emitir el remito que acompaña la entrega (Priority: P1) 🎯 MVP

Quien prepara un pedido para despachar abre la Venta, crea el remito con los productos que
efectivamente salen del depósito, elige el transportista, y lo imprime para que viaje con la
mercadería.

**Why this priority**: es la razón de ser del módulo. Sin ítems y sin PDF, el remito no cumple
ninguna función: hoy se crea un registro que nadie puede ver ni usar. Con esta historia sola el
negocio ya puede despachar con su comprobante de entrega.

**Independent Test**: crear un remito sobre una Venta con productos, verificar que las líneas se
precargan, guardarlo, verlo listado en el detalle de la Venta y abrir su PDF con los productos y
cantidades correctas — **sin que cambie el stock**.

**Acceptance Scenarios**:

1. **Given** una Venta con productos, **When** se abre "Crear Remito", **Then** el formulario
   aparece precargado con el cliente, el domicilio de entrega del cliente, la fecha de hoy y **todas
   las líneas de producto de la Venta con sus cantidades originales**.
2. **Given** ese formulario, **When** se elige un transportista, se completan observaciones por línea
   y una nota para el cliente, y se guarda, **Then** el remito queda creado con un número correlativo
   y se avisa del éxito.
3. **Given** un remito recién creado, **When** se mira el detalle de la Venta, **Then** aparece una
   sección **Remitos** con las columnas Id, Fecha, Transportista, Nota, Total Bultos y Comprobante,
   con acceso a verlo e ícono para editarlo.
4. **Given** ese remito, **When** se abre "Ver Remito", **Then** se muestra un PDF con encabezado
   REMITO, número, fecha, transportista, datos del cliente, domicilio de entrega y la tabla
   Código / Productos / Observaciones / Cantidad, **sin precios, sin IVA y sin totales de dinero**.
5. **Given** cualquier remito creado, **When** se consulta el stock de sus productos, **Then** no
   cambió: emitir el remito no mueve inventario.
6. **Given** el formulario abierto, **When** se cambia una cantidad o se quita una línea, **Then** el
   **Total Bultos** se recalcula solo con la suma de las cantidades.

---

### User Story 2 - Corregir o anular un remito ya emitido (Priority: P1)

Si el remito salió con un dato equivocado —el transportista, una cantidad, el domicilio— se corrige
sin restricciones, o se elimina si no correspondía emitirlo.

**Why this priority**: un remito mal emitido acompaña mercadería física; corregirlo es tan urgente
como emitirlo. Además es barato: es el mismo formulario del alta. Verificado en Contagram: a
diferencia de las NC/ND, **ningún campo queda bloqueado** después de creado.

**Independent Test**: editar un remito existente cambiando transportista y cantidades, verificar que
se guarda y que el PDF refleja los cambios; después eliminarlo y verificar que desaparece de la
sección — sin que el stock se mueva en ninguno de los dos casos.

**Acceptance Scenarios**:

1. **Given** un remito en la sección Remitos, **When** se toca el ícono de editar, **Then** se abre
   el mismo formulario del alta con los datos guardados, **sin ningún campo bloqueado**.
2. **Given** ese formulario de edición, **When** se cambian datos y se guarda, **Then** los cambios
   quedan aplicados y se reflejan en el PDF.
3. **Given** ese formulario de edición, **When** se usa **Eliminar**, **Then** el remito desaparece
   de la sección Remitos de la Venta.
4. **Given** cualquiera de esas dos operaciones, **When** se consulta el stock, **Then** no cambió.

---

### User Story 3 - Entregar un pedido en varias veces (Priority: P2)

Un pedido se despacha en partes: hoy sale una parte y el resto la semana que viene. Cada entrega
lleva su propio remito, con las cantidades de esa entrega.

**Why this priority**: es el motivo por el que se permite más de un remito por Venta. No bloquea el
uso básico (US1 ya sirve para el caso de entrega única, que es el más común), pero sin esto los
envíos parciales quedan sin documentar.

**Independent Test**: sobre una Venta que ya tiene un remito, crear un segundo remito y verificar que
ambos conviven en la sección, cada uno con sus cantidades y su PDF.

**Acceptance Scenarios**:

1. **Given** una Venta que ya tiene un remito, **When** se toca "Crear Remito" otra vez, **Then** el
   botón sigue disponible y se abre un formulario nuevo.
2. **Given** ese segundo formulario, **When** se observan las cantidades precargadas, **Then** traen
   **las cantidades totales originales de la Venta**, sin descontar lo ya remitido — el ajuste queda
   a cargo de la persona (comportamiento verificado en Contagram, ver Assumptions).
3. **Given** dos remitos sobre la misma Venta, **When** se mira la sección Remitos, **Then** ambos
   aparecen listados con sus propios números, fechas, transportistas y bultos.

---

### User Story 4 - Remitar también en Compras (Priority: P2)

Al recibir mercadería de un proveedor se documenta la recepción con un remito asociado a la Compra,
con el mismo formulario y el mismo PDF.

**Why this priority**: la pantalla de Compras ya tiene el botón "Crear Remito" con el mismo hueco que
tenía Ventas. Es menos frecuente que el de Ventas —de ahí P2— pero dejarlo a medias repetiría
exactamente el problema que esta spec viene a cerrar.

**Independent Test**: crear un remito sobre una Compra con productos, verlo en la sección Remitos de
la Compra y abrir su PDF.

**Acceptance Scenarios**:

1. **Given** una Compra con productos, **When** se crea un remito, **Then** el formulario se precarga
   con los datos del proveedor y las líneas de la Compra.
2. **Given** ese remito, **When** se mira el detalle de la Compra, **Then** aparece la sección
   Remitos, estructuralmente igual a la de Ventas.
3. **Given** ese remito, **When** se abre su PDF, **Then** se muestra con la misma estructura que el
   de Ventas, con los datos del proveedor.
4. **Given** cualquier remito de Compra, **When** se consulta el stock, **Then** no cambió.

---

### Edge Cases

- **Venta sin productos** (sólo conceptos o servicios): no hay nada que remitir. El formulario no
  debe ofrecer líneas vacías ni permitir guardar un remito sin ítems.
- **Ítem libre sin producto asociado** (línea escrita a mano en la Venta): debe poder remitirse igual,
  mostrando su descripción; el código queda vacío en el PDF.
- **Producto dado de baja** después de la Venta: el remito debe poder emitirse e imprimirse igual —
  documenta una entrega de algo que efectivamente se vendió.
- **Se borran todas las líneas** con el tachito: no se debe poder guardar un remito sin ninguna línea.
- **Cantidad cero o negativa** en una línea: no se acepta; una entrega es de cantidades positivas.
- **Cantidad mayor a la vendida**: se permite (Contagram no lo controla), pero conviene advertirlo sin
  bloquear, porque suele ser un error de tipeo.
- **Cliente sin domicilio cargado**: el domicilio de entrega queda vacío y **editable** para completar
  a mano en ese remito, sin modificar la ficha del cliente.
- **Cliente sin CUIT ni condición de IVA**: el PDF los muestra en blanco (verificado en la captura 10),
  no falla ni bloquea la emisión.
- **Se elimina la Venta** que tiene remitos: sus remitos se eliminan con ella (FR-018), sin quedar
  huérfanos ni romper la pantalla.
- **Compra sin depósito asignado**: el domicilio de entrega queda vacío y editable, sin bloquear la
  emisión (mismo criterio que un cliente sin domicilio).
- **Dos personas crean un remito a la vez**: cada uno debe obtener un número distinto; la numeración
  no puede repetirse.
- **Transportista con nombre repetido**: se reutiliza el existente en vez de crear duplicados.

## Requirements *(mandatory)*

### Functional Requirements

#### Emisión

- **FR-001**: El sistema DEBE permitir crear un remito desde una Venta o una Compra, con un formulario
  precargado con los datos de la operación de origen.
- **FR-002**: El formulario DEBE precargar **todas las líneas de producto** de la operación, con su
  producto, código y **cantidad original**, y permitir editar la cantidad de cada línea y quitar
  líneas completas.
- **FR-003**: Cada línea DEBE admitir una **observación** propia (texto libre).
- **FR-004**: El remito DEBE registrar: fecha de emisión, transportista, domicilio de entrega, nota
  para el cliente, y sus líneas.
- **FR-005**: El **domicilio de entrega** DEBE precargarse y ser editable para ese remito, **sin**
  modificar la ficha de origen. En Ventas se precarga con el **domicilio del cliente** (a dónde va la
  mercadería); en Compras, con el **depósito del negocio** que recibe (la mercadería viene hacia el
  negocio, ver Clarifications).
- **FR-006**: El **Total Bultos** DEBE calcularse solo, como la suma de las cantidades de las líneas,
  y actualizarse al cambiar cualquier cantidad.
- **FR-007**: El **Monto Asegurado** DEBE ser opcional: un interruptor que, al activarse, habilita un
  importe precargado con el total de la operación y editable. Es un dato interno: **NO se imprime en
  el PDF**.
- **FR-008**: El sistema DEBE asignar un **número correlativo** propio de remitos, sin repetirse ante
  emisiones simultáneas.
- **FR-009**: El sistema NO DEBE permitir guardar un remito **sin ninguna línea**, ni con cantidades
  cero o negativas.
- **FR-009a**: Si la cantidad de una línea supera la cantidad de esa línea en la operación de origen,
  el sistema DEBE **advertirlo sin bloquear** el guardado. Suele ser un error de tipeo, pero remitir
  de más es una decisión legítima que el original no impide.

#### Impacto (lo que el remito NO hace)

- **FR-010**: Crear, editar o eliminar un remito NO DEBE generar ningún movimiento de stock,
  de tesorería ni de cuenta corriente, ni modificar la Venta/Compra de origen.
- **FR-011**: El remito NO DEBE emitirse ante ARCA ni obtener CAE: no es un comprobante fiscal.
- **FR-012**: El remito NO DEBE mostrar precios, IVA, descuentos ni totales de dinero en su documento
  imprimible.

#### Visualización

- **FR-013**: El detalle de la Venta y el de la Compra DEBEN mostrar una sección **Remitos** con una
  fila por remito, con: Id, Fecha, Transportista, Nota, Total Bultos y acceso al comprobante.
  Estructuralmente equivalente a la sección de Cobranzas ya existente.
- **FR-014**: Los usuarios DEBEN poder abrir el **documento imprimible** del remito, que incluye:
  encabezado REMITO con la letra del comprobante, número, fecha de emisión, transportista, datos del
  cliente/proveedor (razón social o apellido y nombre, teléfono, persona de contacto, condición de
  IVA y CUIT), domicilio de entrega, y la tabla **Código / Productos / Observaciones / Cantidad**.
- **FR-015**: Los datos fiscales que el cliente no tenga cargados (condición de IVA, CUIT) DEBEN
  aparecer vacíos en el documento, sin impedir su emisión.

#### Edición y baja

- **FR-016**: Los usuarios DEBEN poder editar un remito ya creado **sin ningún campo bloqueado**
  (a diferencia de las NC/ND): transportista, domicilio, fecha, nota, observaciones, cantidades,
  líneas y monto asegurado.
- **FR-017**: Los usuarios DEBEN poder eliminar un remito desde su propio formulario de edición. El
  borrado es **definitivo** (no soft delete): el remito no es un documento fiscal ni contable, así que
  no le alcanza la exigencia de conservación del Principio III de la constitución.
- **FR-018**: Al eliminar la Venta o la Compra de origen, sus remitos **DEBEN eliminarse junto con
  ella**, sin quedar huérfanos ni romper ninguna pantalla.

#### Envíos parciales

- **FR-019**: El sistema DEBE permitir **varios remitos por una misma** Venta o Compra, y el botón de
  creación DEBE seguir disponible después del primero.
- **FR-020**: Cada remito nuevo DEBE precargar **las cantidades totales originales** de la operación,
  sin descontar lo ya remitido. El sistema **NO** lleva control de "cantidad pendiente de remitir":
  el ajuste es responsabilidad de quien emite (fidelidad al comportamiento de Contagram, ver
  Assumptions).

#### Transportista

- **FR-021**: El transportista DEBE ser una entidad **reutilizable** entre remitos, seleccionable con
  buscador.
- **FR-022**: Los usuarios DEBEN poder crear un transportista nuevo **al vuelo**, sin salir del
  formulario del remito, indicando únicamente su **nombre**.
- **FR-023**: No se construye pantalla de administración de transportistas (decisión de alcance, ver
  Clarifications). Un nombre ya existente se reutiliza en vez de duplicarse.

#### Correcciones de lo existente

- **FR-024**: El botón "Crear Remito" DEBE renderizarse correctamente, con su ícono visible (hoy su
  etiqueta HTML está mal cerrada).
- **FR-025**: El acceso a "Crear Remito" desde el menú de fila del listado DEBE llevar a un destino
  real, sin usar URLs con fragmento (`#`), en cumplimiento de las reglas de navegación del proyecto.
- **FR-026**: Los remitos existentes al momento de implementar (N° 1 y N° 2, sin ítems ni
  transportista) DEBEN seguir visualizándose sin romper la sección ni el documento imprimible.

### Key Entities

- **Remito**: documento de entrega asociado a **una** Venta **o** a **una** Compra (exactamente una de
  las dos). Atributos: número correlativo, letra del comprobante, fecha de emisión, transportista,
  domicilio de entrega, nota para el cliente, monto asegurado (opcional), total de bultos (derivado).
- **Línea de remito**: qué se entrega. Atributos: producto (o descripción libre), código,
  observación, cantidad. Sin precio ni impuestos.
- **Transportista**: quien traslada la mercadería. Único atributo: nombre. Reutilizable entre remitos.
- **Venta / Compra**: ya existen. Son el origen del remito y de sus líneas precargadas. **No se
  modifican** por la emisión de un remito.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Un remito emitido puede imprimirse y acompañar físicamente la mercadería, con el detalle
  de lo que se entrega — hoy es imposible.
- **SC-002**: El 100% de los remitos creados son visibles desde el detalle de su Venta o Compra. Hoy
  el 0% lo es.
- **SC-003**: Emitir, editar o eliminar un remito **nunca** modifica el stock, la tesorería, la cuenta
  corriente ni la operación de origen.
- **SC-004**: Un remito emitido con un dato equivocado puede corregirse sin recrearlo desde cero.
- **SC-005**: Un pedido entregado en dos veces queda documentado con dos remitos, cada uno con las
  cantidades de su entrega.
- **SC-006**: Ningún documento imprimible de remito muestra precios, IVA ni totales de dinero.
- **SC-007**: La estructura de la pantalla (formulario, sección en el detalle y documento imprimible)
  coincide con la de Contagram relevada en `docs/Contagram-Informe-Remitos.md` y sus 12 capturas,
  salvo las divergencias documentadas explícitamente en esta spec.

## Assumptions

- **La numeración se mantiene autonumérica correlativa**, en vez del campo manual
  `____-________` de Contagram. Es una **divergencia deliberada** decidida por el usuario (ver
  Clarifications) y debe quedar documentada en `docs/documentacion_principal_crm.md`. Consecuencia
  visible: en el CRM el "Nro. Remito" del documento **siempre** trae número, mientras que en
  Contagram aparece vacío si no se completó a mano.
- **La letra del comprobante es `X`** por defecto, que es la que se observó en el documento impreso
  real (captura 10). Contagram ofrece `X` y `R`, deshabilitadas en cuentas sin ARCA activo. Como el
  remito **no es fiscal en ningún caso**, la letra es informativa y no deriva de la condición de IVA.
- **No se lleva control de cantidad pendiente de remitir.** Es fidelidad al original, no una
  simplificación: se verificó en Contagram que el segundo remito precarga las cantidades totales
  originales. Si el negocio necesitara ese control, sería una spec propia y una divergencia a decidir.
- **El transportista no tiene CUIT, patente ni contacto**: sólo nombre, tal como el alta rápida de
  Contagram (captura 04).
- **El Monto Asegurado no se imprime** — verificado en el PDF real (captura 10), aunque se carga en el
  formulario. Es un dato de referencia interna (por ejemplo, para el seguro del transportista).
- **Los remitos son documentos internos, no fiscales**, así que no les aplica la exigencia de soft
  delete del Principio III de la constitución (que rige para ventas, compras, gastos y comprobantes).
  El criterio quedó decidido: **borrado real** (ver Clarifications y FR-017).
- **El remito de Compras usa los datos del proveedor** donde el de Ventas usa los del cliente; la
  estructura del documento es la misma. La excepción es el **domicilio de entrega**, que en Compras
  apunta al depósito que recibe, no al proveedor (FR-005).
- **El formulario vive en una página completa**, no en un modal, siguiendo el precedente de la spec
  059 para formularios con tabla de ítems y la estructura observada en la captura 02.
- **Alcance cerrado**: no se construyen remitos electrónicos oficiales ante ARCA, ni pantalla propia
  de listado de remitos (fuera del detalle de la operación), ni administración de transportistas, ni
  control de entregas pendientes.
