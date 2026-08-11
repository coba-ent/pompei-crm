# Feature Specification: Percepciones/Impuestos Internos/Intereses funcionales en NC/ND

**Feature Branch**: `061-conceptos-ncnd`

**Created**: 2026-08-11

**Status**: Draft

**Input**: User description: "Percepciones, Impuestos Internos e Intereses funcionales en Notas de
Crédito/Débito (Ventas y Compras) — hoy los bloques '+Percepciones / +Impuestos Internos /
+Intereses' en la página completa de NC/ND (spec 059) están marcados sin funcionalidad (FR-003 de
esa spec). Hay que darles la misma funcionalidad real que ya tienen en Ventas/Compras/Presupuestos:
al hacer click, agregan una fila con selector de concepto (percepción del catálogo fijo de 27
percepciones vigentes, o descripción libre para impuesto interno/interés) + monto + tacho de
eliminar, se suman al total de la nota, y se persisten."

## Clarifications

### Session 2026-08-11

- Q: ¿Hace falta una tabla/columna nueva para persistir estos conceptos en `notas_credito_debito`?
  → A: No — la tabla ya tiene una columna `impuestos` (json, nullable, agregada en la migración
  original de spec 039/045 pero nunca conectada a ninguna UI) documentada en
  `docs/modelo_datos.md` como "mismo patrón que `presupuesto_conceptos`". Este spec conecta esa
  columna ya existente al formulario, sin migración nueva — sólo define su forma (array de
  `{tipo, concepto, monto}`, igual a como `venta_conceptos`/`presupuesto_conceptos` lo modelan en
  tabla propia, pero acá vive embebido en JSON por ser la columna que ya existe).
- Q: ¿El catálogo de 27 percepciones es el mismo que ya usan Ventas/Compras/Presupuestos, o uno
  propio de NC/ND? → A: El mismo catálogo fijo (IVA Percepción, Ganancias, Sellos, IIBB de las 24
  jurisdicciones) — no se duplica ni se reinventa.
- Q: ¿Estos conceptos participan del cálculo de stock/CAE o son puramente informativos+monto? → A:
  Puramente monto — se suman al Total de la nota (mismo criterio que Ventas/Compras/Presupuestos:
  no afectan cantidad ni stock, sólo el importe final), sin impacto en la lógica fiscal de ARCA ya
  construida (fuera de alcance de este spec).

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Agregar Percepciones/Impuestos Internos/Intereses a una NC/ND (Priority: P1)

Un usuario administrativo está creando o editando una Nota de Crédito/Débito (Venta o Compra) y
necesita reflejar una percepción impositiva, un impuesto interno o un interés adicional sobre el
monto de la nota — hoy los botones "+Percepciones/+Impuestos Internos/+Intereses" de esa pantalla
no hacen nada al hacer clic.

**Why this priority**: Es la corrección de una brecha funcional explícita ya documentada (FR-003 de
spec 059) — sin esto, NC/ND es la única pantalla de comprobantes del sistema donde estos bloques son
decorativos en vez de funcionales, rompiendo la fidelidad estructural exigida por el proyecto.

**Independent Test**: Crear una NC/ND, hacer clic en "+ Percepciones", elegir una percepción del
catálogo y un monto, guardar; verificar que el Total de la nota incluye ese monto y que al reabrir
la nota en modo Editar la percepción sigue ahí con el mismo concepto y monto.

**Acceptance Scenarios**:

1. **Given** la página completa de NC/ND (Crear o Editar), **When** el usuario hace clic en
   "+ Percepciones", **Then** se agrega una fila nueva con un selector "Seleccionar..." poblado con
   el catálogo fijo de 27 percepciones (IVA Percepción, Ganancias, Sellos, IIBB × 24
   jurisdicciones), un campo de Monto y un botón para eliminar esa fila.
2. **Given** la misma página, **When** el usuario hace clic en "+ Impuestos Internos" o
   "+ Intereses", **Then** se agrega una fila con un campo de texto libre "Concepto" (no un
   selector) en vez del catálogo de percepciones, más Monto y botón de eliminar — mismo patrón que
   ya usan Ventas/Compras/Presupuestos para estos dos tipos.
3. **Given** una o más filas de conceptos cargadas con monto, **When** se recalcula el Total de la
   nota, **Then** el Total incluye la suma de todos los montos de conceptos, además del subtotal de
   ítems con descuento ya aplicado (mismo orden de cálculo que Ventas/Compras/Presupuestos: subtotal
   con descuento + conceptos = total).
4. **Given** una fila de concepto cargada, **When** el usuario hace clic en su botón de eliminar,
   **Then** la fila desaparece y el Total se recalcula sin ese monto.
5. **Given** una NC/ND guardada con conceptos cargados, **When** el usuario la abre en modo Editar,
   **Then** las mismas filas de concepto (tipo, concepto elegido/tipeado, monto) aparecen
   precargadas tal cual quedaron guardadas.
6. **Given** una NC/ND guardada con conceptos, **When** se elimina la nota, **Then** los conceptos
   se eliminan junto con ella (no quedan huérfanos) — comportamiento ya cubierto por el soft delete
   existente de `notas_credito_debito`.

---

### Edge Cases

- ¿Qué pasa si el usuario agrega una fila de concepto y no completa el monto (o lo deja en 0) antes
  de guardar? Se descarta esa fila al guardar (mismo criterio que Ventas: `conceptos.filter(c =>
  c.concepto)` — una fila sin concepto elegido/tipeado no se persiste; con concepto pero monto vacío
  se persiste con monto 0, sin afectar el Total).
- ¿Puede haber más de una fila del mismo tipo de percepción, o del mismo concepto repetido? Sí —
  mismo criterio que Ventas/Compras/Presupuestos, no hay restricción de unicidad.
- ¿Estos conceptos entran en el PDF de la nota? Fuera de alcance de este spec — el PDF de NC/ND
  (`notas-credito-debito.pdf`, spec 039) no se modifica; si hace falta mostrarlos ahí es un spec
  aparte.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: Los enlaces "+ Percepciones", "+ Impuestos Internos", "+ Intereses" de la página
  completa de NC/ND DEBEN agregar una fila nueva de concepto al hacer clic, reemplazando el
  comportamiento actual sin funcionalidad (`js-concepto-noop`) — mismo patrón visual/interactivo que
  ya usan Ventas, Compras y Presupuestos.
- **FR-002**: Cada fila de tipo "Percepción" DEBE ofrecer un selector con el mismo catálogo fijo de
  27 percepciones ya usado en Ventas/Compras/Presupuestos (no un catálogo nuevo ni texto libre).
- **FR-003**: Cada fila de tipo "Impuesto Interno" o "Interés" DEBE ofrecer un campo de texto libre
  para el concepto (no el selector de percepciones).
- **FR-004**: Cada fila DEBE tener un campo de Monto editable y un botón para eliminar esa fila
  específica.
- **FR-005**: El Total de la nota (mostrado en pantalla y persistido en `monto`) DEBE incluir la
  suma de los montos de todos los conceptos cargados, sumados al subtotal de ítems ya calculado
  (mismo orden que Ventas/Compras/Presupuestos).
- **FR-006**: Al guardar (crear o editar), el sistema DEBE persistir los conceptos cargados en la
  columna `impuestos` (json) ya existente en `notas_credito_debito`, con la forma `[{tipo, concepto,
  monto}, ...]` — sin migración de esquema nueva.
- **FR-007**: Al editar una NC/ND existente, la página completa DEBE precargar los conceptos ya
  guardados en `impuestos`, mostrando las mismas filas con su tipo/concepto/monto.
- **FR-008**: Filas de concepto sin un concepto elegido o tipeado NO se persisten al guardar (se
  descartan silenciosamente, igual que en Ventas).
- **FR-009**: Todo lo anterior aplica simétricamente a NC/ND de Ventas y de Compras (mismo patrón
  espejo ya usado en el resto del módulo).
- **FR-010**: Esta funcionalidad no modifica ninguna regla de negocio de stock, CAE o validación de
  comprobante ya construida en spec 057/059 — los conceptos son un agregado puramente de monto sobre
  el total, sin interacción con esas reglas.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Un usuario puede agregar, editar el monto de, y eliminar una fila de Percepción/
  Impuesto Interno/Interés en la página completa de NC/ND, viendo el Total reflejar el cambio
  inmediatamente, sin recargar la página.
- **SC-002**: Una NC/ND guardada con conceptos, al reabrirse en modo Editar, muestra exactamente los
  mismos conceptos con los que se guardó — cero pérdida de datos entre guardar y reabrir.
- **SC-003**: El comportamiento de estos tres bloques en NC/ND es indistinguible (mismo catálogo de
  percepciones, misma estructura de fila, mismo cálculo de total) del que ya tienen en Ventas/
  Compras/Presupuestos para un usuario familiarizado con esas pantallas.

## Assumptions

- La columna `impuestos` (json, nullable) de `notas_credito_debito` ya existe desde la migración
  original de spec 039/045 y nunca se conectó a ninguna UI — este spec la usa tal cual, sin agregar
  columnas ni tablas nuevas. Ver `docs/modelo_datos.md` §`notas_credito_debito`.
- El catálogo fijo de 27 percepciones (`PERCEPCIONES` en `resources/js/ventas.js`) se reutiliza sin
  duplicar — si el spec de implementación decide extraerlo a un archivo compartido en vez de
  copiarlo en `notas-credito-debito.js`, es una decisión técnica del plan, no de este spec.
- No se modifica el PDF de NC/ND ni ninguna lógica de ARCA/facturación electrónica — estos conceptos
  son puramente un agregado de monto al total ya persistido, mismo criterio que Ventas/Compras/
  Presupuestos (que tampoco los reflejan en el CAE, sólo en el total facturado).
- El toggle %/monto del Descuento General (spec 060) y esta feature son independientes — no hay
  interacción entre ambos más allá de que los dos suman/restan sobre el mismo Total.
