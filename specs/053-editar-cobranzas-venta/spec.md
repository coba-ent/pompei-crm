# Feature Specification: Editar cobranzas de una venta

**Feature Branch**: `053-editar-cobranzas-venta`

**Created**: 2026-08-07

**Status**: Draft

**Input**: User description: "Permitir editar una cobranza existente en el detalle de venta. Reutilizar el modal de alta en modo edición (monto, fecha, cuenta de tesorería y nota). Al editar una cobranza con MovimientoTesoreria asociado, actualizar ese movimiento existente in-place. Agregar servicio actualizarCobro(), controller cobranzaUpdate y ruta PUT/PATCH. Cambiar la columna de acciones de la tabla de cobranzas a un desplegable (como el patrón _row_actions.blade.php ya usado en el resto del CRM) con las opciones Editar, Ver recibo y Eliminar. No permitir editar el monto por encima del saldo pendiente + el monto actual del cobro editado."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Corregir un dato mal cargado en una cobranza (Priority: P1)

Un usuario cargó una cobranza de una venta con un dato incorrecto (por ejemplo el monto, la fecha o la cuenta de tesorería a la que ingresó el dinero) y necesita corregirlo sin tener que anular la cobranza y cargarla de nuevo desde cero.

**Why this priority**: Es el corazón de la feature — sin esto no hay nada que entregar. Hoy la única forma de corregir un error es anular (soft-delete) y volver a cargar, lo que genera un registro "fantasma" y obliga a rehacer todo el alta.

**Independent Test**: Desde el detalle de una venta con al menos una cobranza cargada, abrir la acción "Editar" de una fila de la tabla de cobranzas, modificar el monto y guardar; el listado y el saldo pendiente de la venta deben reflejar el nuevo monto sin recargar la página.

**Acceptance Scenarios**:

1. **Given** una venta con una cobranza cargada por $10.000 en la cuenta "Caja", **When** el usuario edita la cobranza y cambia el monto a $8.000, **Then** la cobranza queda en $8.000, el saldo pendiente de la venta se recalcula (+$2.000) y el movimiento de tesorería asociado a esa cobranza queda actualizado a $8.000 en la misma cuenta.
2. **Given** una venta con una cobranza cargada en la cuenta "Caja", **When** el usuario edita la cobranza y cambia la cuenta de tesorería a "Banco Galicia", **Then** el movimiento de tesorería asociado se actualiza para reflejar la nueva cuenta (deja de impactar en "Caja" y pasa a impactar en "Banco Galicia").
3. **Given** una cobranza con una nota cargada, **When** el usuario la edita y sólo cambia la nota, **Then** se guarda el nuevo texto y el resto de los datos (monto, fecha, cuenta) permanece igual.
4. **Given** el modal de edición abierto para una cobranza, **When** el usuario lo cierra sin guardar, **Then** la cobranza no sufre ningún cambio.

---

### User Story 2 - Acceder a las acciones de una cobranza desde un desplegable consistente con el resto del CRM (Priority: P2)

Un usuario que ya conoce el patrón de "desplegable de acciones en la primera columna" del resto de las tablas del CRM (ventas, gastos, productos, clientes, etc.) espera encontrar el mismo patrón en la tabla de cobranzas del detalle de venta, en lugar de íconos sueltos.

**Why this priority**: Es una mejora de consistencia de UI que depende de que exista la acción "Editar" (User Story 1) para tener sentido pleno, pero aporta valor por sí sola (agrupa las acciones existentes de forma prolija) incluso evaluada de forma aislada.

**Independent Test**: Abrir el detalle de una venta con cobranzas cargadas y verificar que la primera columna de la tabla de cobranzas muestra un botón desplegable con las opciones "Ver recibo", "Editar" y "Eliminar", igual en estilo al desplegable de acciones de otras tablas del CRM.

**Acceptance Scenarios**:

1. **Given** el detalle de una venta con cobranzas cargadas, **When** el usuario abre el desplegable de acciones de una fila, **Then** ve las opciones "Ver recibo", "Editar" y "Eliminar".
2. **Given** el desplegable de acciones abierto, **When** el usuario elige "Eliminar", **Then** el comportamiento es el mismo que hoy (confirmación + anulación soft-delete), sólo cambia dónde vive la acción dentro de la fila.

---

### User Story 3 - No permitir que una edición deje la venta sobre-cobrada (Priority: P1)

Un usuario edita el monto de una cobranza y lo sube por error a un valor que haría que el total cobrado de la venta supere el total de la venta.

**Why this priority**: Es una regla de integridad de datos que ya existe para el alta de cobranzas; extenderla a la edición es indispensable para no introducir una forma nueva de romper el cuadre entre ventas y tesorería.

**Independent Test**: En una venta con saldo pendiente $0 (totalmente cobrada) y una cobranza de $5.000, intentar editar esa cobranza subiendo el monto a $6.000 debe ser rechazado con un mensaje claro, y la cobranza debe permanecer en $5.000.

**Acceptance Scenarios**:

1. **Given** una venta de $10.000 con una única cobranza de $10.000 (saldo pendiente $0), **When** el usuario intenta editar esa cobranza y subir el monto a $11.000, **Then** el sistema rechaza el cambio con un mensaje de error y la cobranza conserva su monto original.
2. **Given** una venta de $10.000 con dos cobranzas de $4.000 y $3.000 (saldo pendiente $3.000), **When** el usuario edita la cobranza de $4.000 y la sube a $7.000 (dentro del margen disponible: $4.000 + $3.000 de saldo), **Then** el cambio se acepta y el nuevo saldo pendiente pasa a $0.

---

### Edge Cases

- ¿Qué pasa si se intenta editar una cobranza que ya fue anulada (soft-deleted)? El sistema debe rechazar la edición — una cobranza anulada no es editable, sólo consultable en su estado anulado.
- ¿Qué pasa si se edita el monto o la cuenta de una cobranza cuyo movimiento de tesorería asociado también fue anulado o eliminado por otra vía? El sistema debe rechazar la edición o señalar la inconsistencia en vez de crear un movimiento nuevo silenciosamente, ya que el requerimiento es actualizar el movimiento existente in-place.
- ¿Qué pasa si dos usuarios intentan editar la misma cobranza al mismo tiempo? Se aplica el comportamiento estándar ya existente en el resto del CRM para condiciones de carrera en formularios (last write wins vía transacción de base de datos).
- ¿Qué pasa si se edita la fecha de la cobranza a una fecha posterior a la fecha de la venta o a una fecha futura? Debe aplicarse la misma validación de fecha que ya rige para el alta de una cobranza nueva.
- ¿Qué pasa si el usuario deja el campo monto vacío o en $0 al editar? Debe rechazarse, igual que en el alta.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: El sistema DEBE permitir editar una cobranza existente (no anulada) de una venta, modificando monto, fecha, cuenta de tesorería y nota.
- **FR-002**: El sistema DEBE reutilizar el modal de carga de cobranza existente, precargado con los datos actuales de la cobranza cuando se abre en modo edición.
- **FR-003**: El sistema DEBE aplicar a la edición la misma validación de monto máximo que aplica al alta: el nuevo monto no puede exceder el saldo pendiente de la venta más el monto actual de la cobranza que se está editando.
- **FR-004**: El sistema DEBE aplicar a la edición la misma validación de fecha que aplica al alta de una cobranza.
- **FR-005**: Cuando una cobranza editada tiene un movimiento de tesorería asociado, el sistema DEBE actualizar ese movimiento existente in-place (mismo monto, cuenta y fecha que la cobranza editada), sin anularlo ni crear uno nuevo.
- **FR-006**: El sistema DEBE rechazar la edición de una cobranza que ya fue anulada (soft-deleted).
- **FR-006a**: Si una cobranza activa no tiene movimiento de tesorería asociado (estado anómalo), el sistema DEBE rechazar la edición señalando la inconsistencia, en vez de crear un movimiento nuevo silenciosamente.
- **FR-007**: El sistema DEBE mostrar, en la primera columna de la tabla de cobranzas del detalle de venta, un botón desplegable de acciones (mismo patrón visual y de interacción que el resto de los desplegables de acciones del CRM) con las opciones "Ver recibo", "Editar" y "Eliminar".
- **FR-008**: Al elegir "Eliminar" desde el nuevo desplegable, el sistema DEBE conservar el comportamiento actual (confirmación + anulación soft-delete) sin cambios de lógica.
- **FR-009**: Al elegir "Ver recibo" desde el nuevo desplegable, el sistema DEBE conservar el comportamiento actual de visualización del recibo.
- **FR-010**: El sistema DEBE actualizar en pantalla, sin recargar la página, el listado de cobranzas y el saldo pendiente de la venta luego de guardar una edición exitosa.
- **FR-011**: El sistema DEBE mostrar una notificación de éxito o error (toast) al guardar una edición, sin usar alerts nativos del navegador.

### Key Entities

- **Cobro (cobranza)**: pago recibido asociado a una venta. Atributos relevantes para esta feature: monto, fecha, cuenta de tesorería, nota, estado (activo / anulado).
- **MovimientoTesoreria**: movimiento de caja/cuenta generado a partir de una cobranza (relación de origen polimórfica). Al editar la cobranza asociada, este movimiento debe reflejar los mismos valores editados.
- **Venta**: entidad dueña de las cobranzas; su saldo pendiente se recalcula en base a la suma de cobranzas activas.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Un usuario puede corregir un dato mal cargado en una cobranza (monto, fecha, cuenta o nota) en menos de 30 segundos, sin abandonar la pantalla de detalle de venta.
- **SC-002**: El 100% de las ediciones de cobranzas con movimiento de tesorería asociado dejan ese movimiento con los mismos valores que la cobranza editada (sin duplicar ni dejar movimientos huérfanos).
- **SC-003**: El 100% de los intentos de edición que dejarían la venta sobre-cobrada son rechazados con un mensaje de error claro, sin alterar el monto original de la cobranza.
- **SC-004**: La tabla de cobranzas del detalle de venta usa el mismo patrón visual de desplegable de acciones que el resto de las tablas del CRM, sin íconos sueltos remanentes en la columna de acciones.

## Assumptions

- El usuario que puede editar una cobranza es el mismo que hoy puede darla de alta o anularla (no se introduce un permiso/rol nuevo).
- Como en el alta, la edición no permite dejar el monto en $0 o negativo, ni una fecha inválida; se reutilizan las reglas de validación que ya existen para `StoreCobroRequest`, adaptadas para excluir el propio registro editado del cálculo de saldo disponible.
- El desplegable de acciones de cobranzas replica el patrón visual y de marcado (`dropdown` + `dropdown-menu` + `dropdown-item`) ya usado en `_row_actions.blade.php` de otras tablas del CRM (ventas, gastos, productos, etc.), no un componente nuevo.
- No se requiere un historial/auditoría de cambios de cobranzas en el alcance de esta feature (no se pidió explícitamente); si se corrige un dato, no queda rastro de cuál era el valor anterior más allá de los logs estándar de la aplicación.
- La edición de la cuenta de tesorería de una cobranza no requiere aprobación ni pasos adicionales de conciliación: el movimiento de tesorería simplemente se actualiza a la nueva cuenta.
- El recibo PDF de una cobranza se genera al vuelo a partir de sus datos vigentes (no se almacena una versión estática), por lo que editar una cobranza no requiere una acción explícita de "regenerar recibo": la próxima vez que se lo vea, ya refleja los valores editados.
- Esta funcionalidad de edición de cobranza es una extensión propia de este CRM: el relevamiento de Contagram real (`docs/informe_contagram_ingresos.md`) no documenta una acción de "editar cobranza" separada de anular y recargar. Se incorpora igual porque agrega valor operativo concreto (evitar el ruido de anular+recargar por errores de tipeo), y se deja asentada como extensión propia en `docs/documentacion_principal_crm.md` (no como calca de Contagram) conforme al Principio I de la constitución.
