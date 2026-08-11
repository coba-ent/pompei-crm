# Feature Specification: Edición y eliminación de Notas de Crédito y Débito (NC/ND)

**Feature Branch**: `057-editar-eliminar-ncnd`

**Created**: 2026-08-11

**Status**: Draft

**Input**: User description: "Edición y eliminación de Notas de Crédito y Débito (NC/ND) en Ventas y Compras, más el documento PDF de NC/ND en Compras (hoy sólo existe en Ventas). Contexto: hoy sólo existe Crear NC/ND. Contagram real tiene menú de fila Editar/Eliminar/Ver Detalle, con la nota modelada como documento propio (comprobante propio editable, ítems con IVA, encadenamiento a otras notas vía 'Documento que Ajusta')."

## Clarifications

### Session 2026-08-11

- Q: ¿Qué pasa si se intenta editar o eliminar una NC/ND que ya tiene comprobante fiscal (CAE) aprobado por ARCA? → A: Se bloquean edición y eliminación por completo (mismo criterio que Ventas/Compras con CAE). Para corregir una nota con CAE aprobado, hay que cargar una nueva NC/ND que la ajuste (encadenamiento, User Story 4).
- Q: ¿Cómo se valida el número de comprobante propio de la NC/ND al editarlo, si coincide con el de otro comprobante ya existente? → A: Se rechaza con error de validación, mismo criterio que `nroComprobante()` en `MigrarVentasContagram.php` y que el resto de los comprobantes de la app.
- Q: ¿Cuántos niveles de profundidad se permiten al encadenar una NC/ND como "documento que ajusta" de otra NC/ND? → A: 1 solo nivel — una NC/ND puede ajustar al comprobante original o a otra NC/ND de primer nivel, pero esa nota de segundo nivel no puede volver a ser ajustada por una tercera.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Corregir una NC/ND cargada por error (Priority: P1)

Un usuario administrativo carga una Nota de Crédito o Nota de Débito sobre una Venta o Compra y detecta que se equivocó (monto, ítem, cantidad, tipo de comprobante, fecha, etc.). Hoy no tiene forma de corregirla: tendría que dejarla mal cargada para siempre, afectando el saldo de cuenta corriente y los totales de IVA. Necesita poder editarla desde la ficha de detalle de la Venta/Compra, con el mismo nivel de detalle con el que la cargó (tipo, documento que ajusta, ítems, IVA, comprobante propio).

**Why this priority**: Es el caso de uso más frecuente y el que motivó el pedido — sin esto, cualquier error de carga queda irreversible y distorsiona reportes de IVA y cuenta corriente.

**Independent Test**: Puede probarse creando una NC/ND, editándola (cambiar monto o un ítem) desde el menú de fila "Editar", y verificando que el detalle de la Venta/Compra y el PDF de la nota reflejan el valor corregido.

**Acceptance Scenarios**:

1. **Given** una NC/ND existente sin CAE (sin comprobante fiscal aprobado), **When** el usuario abre "Editar" desde el menú de fila, **Then** el wizard se precarga con los valores actuales de la nota (tipo, documento que ajusta, afecta stock, ítems o descripción, mes de imputación, fecha, comprobante propio, ítems con IVA) y permite modificar todos esos campos **excepto el tipo (Crédito/Débito)**, que se muestra pero queda deshabilitado (ver Assumptions).
2. **Given** el usuario modificó el monto/ítems de una NC/ND que afecta stock, **When** guarda los cambios, **Then** el stock del depósito se recalcula para reflejar únicamente el ajuste vigente (revierte el movimiento anterior de esa nota y aplica el nuevo).
3. **Given** el usuario modificó una NC/ND que no afecta stock, **When** guarda los cambios, **Then** el detalle de la Venta/Compra (barra de ecuación A Cobrar/A Pagar) y el PDF de la nota reflejan el nuevo monto de inmediato, sin recargar la página.

---

### User Story 2 - Eliminar una NC/ND cargada por error (Priority: P1)

Un usuario administrativo determina que una NC/ND no debió cargarse (duplicada, de prueba, o ya no corresponde) y necesita eliminarla, tanto desde el menú de fila de la tabla como desde dentro del propio formulario de edición — igual que en Contagram real.

**Why this priority**: Mismo nivel de urgencia que la edición: sin poder eliminar, una carga de prueba o duplicada queda contaminando los totales de la Venta/Compra y de Tesorería/Stock para siempre.

**Independent Test**: Puede probarse creando una NC/ND que afecta stock, eliminándola, y verificando que (a) desaparece de la tabla, (b) el stock del depósito vuelve al valor previo a la nota, (c) la barra de ecuación de la Venta/Compra vuelve a su valor original.

**Acceptance Scenarios**:

1. **Given** una NC/ND sin CAE, **When** el usuario elige "Eliminar" desde el menú de fila y confirma, **Then** la nota se borra, cualquier ajuste de stock que haya generado se revierte por completo, y la tabla/barra de ecuación se actualizan sin recargar la página.
2. **Given** el usuario está dentro del formulario de "Editar" una NC/ND, **When** presiona el botón "Eliminar" del propio formulario, **Then** el resultado es el mismo que eliminar desde el menú de fila (mismo endpoint, misma reversión de stock).
3. **Given** una NC/ND ya fue referenciada como "documento que ajusta" por otra NC/ND (cadena), **When** el usuario intenta eliminar la nota intermedia, **Then** el sistema le avisa que no puede eliminarla mientras existan notas que la ajustan a ella, y le indica cuáles son.

---

### User Story 3 - Ver el detalle impreso (PDF) de una NC/ND, también en Compras (Priority: P2)

Un usuario necesita el documento imprimible de una NC/ND (para entregar al cliente/proveedor o archivar) tanto si la nota es de una Venta como de una Compra. Hoy ese PDF sólo existe para notas de Ventas.

**Why this priority**: Es una funcionalidad ya disponible en Ventas; extenderla a Compras cierra una asimetría entre los dos módulos espejo, pero no bloquea el caso de uso principal (editar/eliminar) si se entrega después.

**Independent Test**: Puede probarse abriendo "Ver Detalle" de una NC/ND cargada sobre una Compra y confirmando que el PDF muestra los datos del proveedor, el comprobante que ajusta, y la tabla de conceptos con IVA — igual que ya ocurre para Ventas.

**Acceptance Scenarios**:

1. **Given** una NC/ND cargada sobre una Compra, **When** el usuario elige "Ver Detalle" desde el menú de fila, **Then** se abre (en el modal de PDF compartido de la app) el documento con los datos del proveedor, fecha, comprobante que ajusta, e ítems con IVA — sin errores ni datos vacíos por intentar cargar una relación de Venta inexistente.
2. **Given** una NC/ND cargada sobre una Venta (comportamiento ya existente), **When** el usuario elige "Ver Detalle", **Then** el PDF sigue funcionando exactamente igual que hoy (no regresión).

---

### User Story 4 - Encadenar una NC/ND como corrección de otra NC/ND (Priority: P3)

Un usuario que ya cargó una NC/ND sobre una Venta/Compra necesita cargar una nueva nota que corrige esa nota anterior (no el comprobante original), replicando el comportamiento real de Contagram donde el selector "Documento que Ajusta" permite elegir tanto el comprobante original como cualquier NC/ND previa de ese comprobante.

**Why this priority**: Es un caso de uso real pero menos frecuente que corregir/eliminar una carga errónea; agrega valor pero el feature es útil sin él.

**Independent Test**: Puede probarse creando dos NC/ND sobre la misma Venta, y verificando que al crear/editar una tercera, el selector "Documento que Ajusta" lista también a las dos anteriores como opciones válidas, junto con el comprobante original.

**Acceptance Scenarios**:

1. **Given** una Venta con dos NC/ND ya cargadas, **When** el usuario abre "Agregar" (crear) o "Editar" una NC/ND sobre esa Venta, **Then** el selector "Documento que Ajusta" incluye el comprobante original de la Venta y ambas NC/ND existentes como opciones.
2. **Given** una NC/ND que ajusta a otra NC/ND (no al comprobante original), **When** se visualiza en la tabla o en el PDF, **Then** queda claro (columna "Documento que Ajusta") que corrige a esa nota puntual y no al comprobante original.

---

### Edge Cases

- Si se intenta editar o eliminar una NC/ND que ya tiene CAE aprobado por ARCA, el sistema lo bloquea por completo (ver Clarificaciones, sesión 2026-08-11).
- ¿Qué pasa si al editar una NC/ND que afecta stock, la nueva cantidad supera el "pendiente de ajuste" disponible del producto (considerando que la propia nota que se edita debe excluirse de ese cálculo, no restarse dos veces)? El sistema debe recalcular el pendiente excluyendo la nota en edición y rechazar si la nueva cantidad lo supera, con el mismo mensaje que ya usa la validación de creación.
- Si se cambia el tipo de comprobante/número propio de la nota a uno que ya existe en otra Venta/Compra/NC-ND, el sistema rechaza la edición (ver Clarificaciones, sesión 2026-08-11).
- Si se intenta seleccionar, como "Documento que Ajusta", una NC/ND que ya está ajustando a otra nota (segundo nivel), el selector no la ofrece como opción — el encadenamiento está limitado a un solo nivel (ver Clarificaciones, sesión 2026-08-11).
- ¿Qué pasa si se intenta eliminar una NC/ND que es "documento que ajusta" de otra nota (cadena)? El sistema debe bloquear la eliminación y listar las notas dependientes (User Story 2, escenario 3).
- ¿Qué pasa si se edita una NC/ND que afecta stock y el depósito original ya no existe o está inactivo? El formulario debe exigir elegir un depósito activo vigente antes de guardar, igual que en creación.
- ¿Qué pasa con una NC/ND migrada del histórico de Contagram (sin `venta_id`/`compra_id`, ver `docs/modelo_datos.md` — 841 notas legacy sin vínculo)? Al no estar asociadas a ninguna Venta/Compra, no aparecen en ninguna tabla de detalle hoy y quedan fuera del alcance de este feature (no son editables/eliminables desde una ficha que no las lista).

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: El sistema DEBE mostrar, en la tabla "Notas de Crédito y Débito" del detalle de Venta y de Compra, un menú de acciones por fila (trigger en la columna Estado) con las opciones Editar, Eliminar y Ver Detalle, igual estructura en ambos módulos.
- **FR-002**: El sistema DEBE permitir editar una NC/ND existente reabriendo el mismo wizard de 2 pasos usado para crearla, precargado con sus valores actuales (documento que ajusta, afecta stock, ítems o descripción, mes de imputación, fecha de emisión, tipo y número de comprobante propios, ítems con cantidad/precio/IVA) y permitiendo modificar todos ellos, **con la excepción del campo Tipo (Crédito/Débito), que no es editable una vez creada la nota** — si la petición de edición llega con un `tipo` distinto al actual, el sistema la rechaza (422).
- **FR-003**: El sistema DEBE permitir eliminar una NC/ND tanto desde el menú de fila de la tabla como desde un botón "Eliminar" dentro del propio formulario de edición, con confirmación previa del usuario.
- **FR-004**: Al eliminar (o al editar reduciendo/quitando ítems de) una NC/ND que afecta stock, el sistema DEBE revertir exactamente el ajuste de stock que esa nota había generado, dejando el stock del depósito en el valor que tendría si la nota nunca hubiera existido (o en el nuevo valor correcto, en el caso de edición).
- **FR-005**: El sistema DEBE recalcular la cantidad "pendiente de ajuste" de un producto excluyendo la propia NC/ND en edición, de forma que aumentar o mantener su cantidad no la rechace por chocar consigo misma.
- **FR-006**: El sistema DEBE impedir eliminar una NC/ND mientras exista otra NC/ND que la referencie como "documento que ajusta", informando al usuario cuáles son las notas dependientes.
- **FR-007**: El selector "Documento que Ajusta" (tanto en creación como en edición) DEBE listar, además del comprobante original de la Venta/Compra, las demás NC/ND ya existentes sobre ese mismo comprobante (excluyendo, en modo edición, a la propia nota que se está editando).
- **FR-008**: El sistema DEBE exponer el documento PDF ("Ver Detalle") de una NC/ND cargada sobre una Compra, con la misma estructura de datos (proveedor, comprobante que ajusta, ítems con IVA) que ya existe hoy para NC/ND de Ventas, reutilizando el modal de PDF compartido de la aplicación.
- **FR-009**: El sistema DEBE mantener, sin regresión, el comportamiento actual del PDF de NC/ND de Ventas.
- **FR-010**: Toda actualización o eliminación de una NC/ND DEBE reflejarse en la tabla de notas y en la barra de ecuación (A Cobrar/A Pagar) de la Venta/Compre afectada sin recargar la página (alta/edición/eliminación vía modal + AJAX, según especificación de UX del proyecto).
- **FR-011**: El sistema DEBE bloquear la edición y la eliminación (tanto desde el menú de fila como desde el propio formulario) de cualquier NC/ND que tenga un comprobante fiscal con CAE aprobado por ARCA, mostrando un mensaje explicando por qué está bloqueada y sugiriendo cargar una nueva NC/ND que la ajuste.
- **FR-012**: El sistema DEBE rechazar, con un error de validación análogo al de duplicados de comprobante en Ventas/Compras, cualquier edición que deje el tipo+número de comprobante propio de una NC/ND coincidiendo con el de otro comprobante (Venta, Compra u otra NC/ND) ya existente.
- **FR-013**: El sistema DEBE limitar el encadenamiento "Documento que Ajusta → otra NC/ND" a un único nivel de profundidad: una NC/ND puede referenciar al comprobante original o a otra NC/ND de primer nivel (que a su vez referencia al comprobante original), pero el selector "Documento que Ajusta" NO debe ofrecer como opción a una NC/ND que ya está referenciando a otra nota (evita cadenas de 3+ niveles).

### Key Entities *(include if feature involves data)*

- **NotaCreditoDebito**: documento de ajuste sobre una Venta o Compra (o, tras este feature, sobre otra NC/ND). Pasa de tener sólo alta a tener también edición y baja. Gana tipo/número de comprobante propios (hoy hereda el de la Venta/Compra) y, potencialmente, una referencia a la nota que ajusta (además de venta_id/compra_id).
- **NotaCreditoDebitoItem**: ítem/renglón de una NC/ND. Hoy sólo existe cuando `afecta_stock = true` y sin desglose de IVA/precio propio visible en el documento; pasa a sostener también la información de precio/IVA que hoy sólo vive en el PDF renderizado a partir de otros datos.
- **Movimiento de Stock**: registro generado cuando una NC/ND con `afecta_stock = true` se crea; debe poder revertirse/recalcularse cuando la nota que lo originó se edita o elimina.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Un usuario puede corregir una NC/ND mal cargada (monto, ítem o comprobante) en menos de 1 minuto, sin tener que eliminar y recrear manualmente ni contactar soporte técnico.
- **SC-002**: El 100% de las eliminaciones de NC/ND que afectan stock dejan el stock del depósito exactamente en el valor previo a esa nota (verificable comparando el stock antes de crear la nota vs. después de eliminarla).
- **SC-003**: El 100% de los intentos de eliminar una NC/ND que es referenciada por otra quedan bloqueados con un mensaje claro, sin generar inconsistencias de datos (notas "huérfanas" que apuntan a una nota inexistente).
- **SC-004**: El documento PDF de NC/ND está disponible en el 100% de las notas cargadas sobre Compras, con la misma información y calidad que las de Ventas.
- **SC-005**: Ninguna operación de edición o eliminación de NC/ND requiere recargar la página (verificable observando que la URL no cambia y la tabla/barra de ecuación se actualizan en el lugar).

## Assumptions

- Se asume que sólo usuarios con permiso sobre Ventas/Compras (mismos permisos que ya gobiernan Crear NC/ND hoy) pueden editar/eliminar — no se introduce un permiso nuevo y separado para esta capacidad.
- Se asume que las 841 NC/ND migradas del histórico de Contagram (sin `venta_id`/`compra_id`, ver `docs/modelo_datos.md`) quedan fuera de alcance: no son editables/eliminables por este feature porque no aparecen en ninguna tabla de detalle de Venta/Compra hoy.
- Se asume que el tipo (Crédito/Débito) de una NC/ND no es editable una vez creada — igual que se observa en la captura real de Contagram, donde el campo "Seleccionar Tipo" aparece deshabilitado en modo edición.
- Se asume que "editar" una NC/ND que afecta stock permite cambiar tanto la cantidad de los ítems existentes como el depósito elegido, y que el sistema debe recalcular el movimiento de stock desde cero (revertir el anterior + aplicar el nuevo) en vez de aplicar un delta, para evitar arrastrar errores de cálculo.
- Se asume que los bloques "+Percepciones / +Impuestos Internos / +Intereses" observados en la captura de edición real de Contagram quedan documentados como estructura de referencia pero **fuera de alcance funcional** de este feature (no se implementa su lógica de cálculo), salvo que `/speckit-clarify` indique lo contrario — ya existen como bloques repetibles en los formularios de Compra/Venta y se evaluará reutilizarlos en el plan técnico, pero no son el objetivo central del pedido (editar/eliminar/ver detalle/PDF en Compras).
