# Feature Specification: Página completa de NC/ND (corrección estructural sobre spec 057)

**Feature Branch**: `059-pagina-completa-ncnd`

**Created**: 2026-08-11

**Status**: Draft

**Input**: User description: "Reestructurar el wizard de Notas de Crédito/Débito (crear y editar, Ventas y Compras) para que coincida con la estructura real de Contagram, corrigiendo el modelo mal relevado en spec 039/045/057. Hoy: modal Bootstrap de 2 pasos, todo en el mismo popup. Real Contagram (capturas del cliente, 11/08/2026): el modal es sólo el paso 1 (Tipo, Documento que Ajusta, Stock Sí/No, Mes de Imputación); al 'Siguiente' navega a una página completa nueva, mismo patrón que Nueva Venta/Nueva Compra, con tabla de ítems (Stock=Sí) o descripción libre (Stock=No), Nota Interna, Descuento General, Total, +Percepciones/+Impuestos Internos/+Intereses (fuera de alcance). Botones Cancelar/Guardar (o Eliminar/Cancelar/Guardar en edición). En el modal de Editar, Tipo Y Stock quedan deshabilitados (no sólo Tipo como asumió spec 057)."

## Clarifications

### Session 2026-08-11 (resuelta antes de escribir la spec, vía capturas del cliente)

- Q: ¿Qué pasa con el paso 2 actual (Fecha/Monto/Descripción dentro del modal)? → A: Se elimina del modal; esos campos (y los de ítems/comprobante propio ya agregados en spec 057) pasan a vivir en la página completa nueva.
- Q: ¿Se puede editar "¿Afecta Stock?" al editar una nota existente? → A: No — queda deshabilitado en el modal de edición, igual que el Tipo (corrige el supuesto de spec 057, que sólo bloqueaba Tipo).
- Q: ¿Qué pasa con los bloques +Percepciones/+Impuestos Internos/+Intereses de la página nueva? → A: Fuera de alcance funcional (mismo criterio ya asumido en spec 057) — se muestran como botones colapsados sin implementar su funcionalidad interna en este spec.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Crear una NC/ND en su propia página, no en un modal de 2 pasos (Priority: P1)

Un usuario administrativo hace clic en "Agregar" NC/ND desde el detalle de una Venta o Compra. Hoy completa todo (incluyendo ítems, monto, descripción) dentro de un único modal chico de 2 pasos. Necesita, en cambio, completar el paso 1 en un modal chico (Tipo, Documento que Ajusta, Stock, Mes) y que al continuar se abra una página completa — igual en estructura a "Nueva Venta"/"Nueva Compra" — donde carga los ítems (o la descripción libre, según si afecta stock), el comprobante propio, la nota interna y el descuento general, y guarda desde ahí.

**Why this priority**: Es la corrección estructural central — sin esto, la fidelidad de spec 057 respecto a Contagram real queda rota (el "paso 2" no existe en la app real).

**Independent Test**: Desde el detalle de una Venta, click en "Agregar" → completar paso 1 (modal) → click Siguiente → verificar que navega a una página completa (no un 2do paso de modal) con la estructura descripta → completar y Guardar → verificar que vuelve al detalle de la Venta con la nota creada.

**Acceptance Scenarios**:

1. **Given** el modal de paso 1 abierto (Crear), **When** el usuario completa Tipo/Documento que Ajusta/Stock/Mes y hace clic en "Siguiente", **Then** el modal se cierra y el navegador va a una página completa nueva (no un segundo paso dentro del mismo modal).
2. **Given** la página completa con Stock = "Sí", **When** se muestra el bloque de ítems, **Then** ofrece un selector de Producto/Servicio y una tabla con columnas Cant./Precio/Desc./Subtotal/IVA (igual estructura que el formulario de Compra).
3. **Given** la página completa con Stock = "No", **When** se muestra el bloque de ítems, **Then** en vez del selector de producto hay una única fila con un campo de texto libre "Descripción", con las mismas columnas Cant./Precio/Desc./Subtotal/IVA.
4. **Given** la página completa (cualquier variante), **When** el usuario completa los campos y hace clic en "Guardar", **Then** la nota se crea (mismas validaciones y efectos ya construidos en spec 057: reversión/aplicación de stock, bloqueo por CAE no aplica en creación, etc.) y el navegador vuelve al detalle de la Venta/Compra de origen, mostrando la nota nueva en la tabla de NC/ND.
5. **Given** la página completa, **When** el usuario hace clic en "Cancelar", **Then** vuelve al detalle de la Venta/Compra de origen sin crear nada.

---

### User Story 2 - Editar una NC/ND en la misma página, con Tipo y Stock bloqueados (Priority: P1)

Un usuario administrativo elige "Editar" desde el menú de fila de una NC/ND existente. Necesita que el modal de paso 1 se abra precargado pero con "Tipo" y "¿Afecta Stock?" deshabilitados (no editables — corrige spec 057, que sólo bloqueaba Tipo), y que al continuar se abra la misma página completa que la de creación, precargada con los datos actuales, agregando un botón "Eliminar" (a la izquierda de Cancelar/Guardar) que no existe en el flujo de creación.

**Why this priority**: Mismo peso que US1 — es la otra mitad del flujo que hoy vive mal modelada en el modal de 2 pasos.

**Independent Test**: Editar una NC/ND existente sin CAE aprobado; verificar que el modal de paso 1 muestra Tipo y Stock deshabilitados con sus valores actuales, que "Siguiente" navega a la página completa precargada, que tiene el botón "Eliminar", y que Guardar aplica los cambios y vuelve al detalle de origen.

**Acceptance Scenarios**:

1. **Given** una NC/ND existente sin CAE aprobado, **When** el usuario elige "Editar" desde el menú de fila, **Then** se abre el modal de paso 1 con Tipo y "¿Afecta Stock?" deshabilitados (grises, no interactuables) mostrando los valores actuales de la nota.
2. **Given** el modal de edición (paso 1), **When** el usuario hace clic en "Siguiente", **Then** navega a la página completa (misma estructura que Crear) precargada con los ítems/descripción, comprobante propio, nota interna, descuento general y demás campos actuales de la nota.
3. **Given** la página completa en modo edición, **When** se renderizan los botones de acción, **Then** aparece "Eliminar" (a la izquierda) además de "Cancelar" y "Guardar" — a diferencia del modo Crear, que no tiene "Eliminar".
4. **Given** la página completa en modo edición, **When** el usuario modifica ítems/monto y hace clic en "Guardar", **Then** se aplican los mismos efectos ya construidos en spec 057 (revierte y reaplica stock, valida duplicado de comprobante, etc.) y vuelve al detalle de origen.
5. **Given** la página completa en modo edición, **When** el usuario hace clic en "Eliminar", **Then** se comporta igual que la eliminación ya construida en spec 057 (confirmación, soft delete, reversión de stock) y vuelve al detalle de origen.
6. **Given** una NC/ND con CAE aprobado, **When** el usuario intenta "Editar" o "Eliminar", **Then** sigue bloqueado (409) — sin cambios respecto a spec 057, sólo cambia dónde vive la UI.

---

### Edge Cases

- ¿Qué pasa si el usuario navega directo a la URL de la página completa (`.../notas/nueva` o `.../notas/{nota}/editar`) sin pasar por el modal de paso 1? Debe funcionar igual (misma página, con los campos del paso 1 — Tipo/Documento que Ajusta/Stock/Mes — visibles también ahí arriba del formulario, ya que son parte de los datos de la nota, no exclusivos del modal).
- ¿Qué pasa si en la página completa el usuario cambia "¿Afecta Stock?" — existe ese control ahí también, o sólo en el modal? Ver Assumptions — el control vive únicamente en el modal de paso 1; la página completa lo hereda ya decidido (no se puede volver a cambiar sin cancelar y volver a empezar el modal).
- ¿Qué pasa con una nota que tiene ítems con producto Y sin producto mezclados (edge case ya cubierto por spec 057, migración histórica)? Sin cambios — la página completa debe poder mostrar ambos casos (fila con selector de producto lleno, fila con sólo descripción) simultáneamente si la nota los tiene así.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: El modal de paso 1 (Crear o Editar) DEBE contener únicamente: Tipo (Crédito/Débito), Documento que Ajusta, "¿Afecta Stock?" (Sí/No), Mes de Imputación — sin los campos de Fecha/Monto/Descripción que hoy tiene el paso 2.
- **FR-002**: Al hacer clic en "Siguiente" del modal de paso 1, el sistema DEBE navegar a una página completa nueva (no mostrar un segundo paso dentro del mismo modal).
- **FR-003**: La página completa DEBE tener la misma estructura general que "Nueva Venta"/"Nueva Compra": datos heredados del comprobante de origen (Cliente/Proveedor, no editables), Emisión, Vto. del Pago, Servicio Desde/Hasta, Tipo y N° de comprobante propio, bloque de ítems, Nota Interna, Descuento General, Total, y los bloques colapsados +Percepciones/+Impuestos Internos/+Intereses (fuera de alcance funcional).
- **FR-004**: Cuando "¿Afecta Stock?" = Sí, el bloque de ítems de la página completa DEBE ofrecer un selector de Producto/Servicio por renglón, con columnas Cant./Precio/Desc./Subtotal/IVA.
- **FR-005**: Cuando "¿Afecta Stock?" = No, el bloque de ítems de la página completa DEBE mostrar una única fila con un campo de texto libre "Descripción" en vez de selector de producto, con las mismas columnas Cant./Precio/Desc./Subtotal/IVA.
- **FR-006**: Al guardar (Crear o Editar) desde la página completa, el sistema DEBE aplicar exactamente la misma lógica de negocio ya construida en spec 057 (validaciones de `Store`/`UpdateNotaCreditoDebitoRequest`, reversión/aplicación de stock, bloqueo por CAE aprobado, bloqueo de edición de Tipo, rechazo de comprobante duplicado) — este spec no cambia reglas de negocio, sólo dónde vive la UI.
- **FR-007**: Al guardar o cancelar desde la página completa, el sistema DEBE volver al detalle de la Venta/Compra de origen.
- **FR-008**: El modal de paso 1 en modo Edición DEBE mostrar "¿Afecta Stock?" deshabilitado además de "Tipo" (ambos no editables una vez creada la nota) — corrige el alcance de spec 057, que sólo bloqueaba Tipo.
- **FR-009**: La página completa en modo Edición DEBE incluir un botón "Eliminar" (a la izquierda de Cancelar/Guardar) que no está presente en modo Creación, con el mismo comportamiento de eliminación ya construido en spec 057 (confirmación, soft delete, reversión de stock, bloqueo por cadena/CAE).
- **FR-010**: El sistema DEBE exponer la página completa en una URL propia navegable (no sólo accesible completando el modal de paso 1 primero) — accederla directamente debe mostrar el mismo formulario funcional, incluyendo los campos que hoy sólo se cargan en el modal (Tipo, Documento que Ajusta, Stock, Mes), visibles también en la página.
- **FR-011**: Todos los requisitos anteriores aplican simétricamente a Ventas y Compras (mismo patrón espejo ya usado en el resto de estos dos módulos).

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Un usuario puede crear una NC/ND completando el modal de paso 1 y la página completa, terminando en el detalle de la Venta/Compra de origen con la nota visible, sin que ningún paso del flujo se sienta "atrapado" dentro de un popup chico para cargar ítems o comprobante.
- **SC-002**: La estructura de la página completa de NC/ND es indistinguible en su esqueleto general (secciones, orden, botones) de la de "Nueva Venta"/"Nueva Compra" para un usuario familiarizado con esas pantallas.
- **SC-003**: Un usuario que intenta cambiar el Tipo o el "¿Afecta Stock?" de una nota existente encuentra esos controles deshabilitados de inmediato en el modal de edición, sin tener que llegar a la página completa para descubrir que no se puede.
- **SC-004**: Editar y eliminar una NC/ND desde la página completa produce exactamente los mismos resultados de negocio (stock, bloqueos, validaciones) que ya se validaron en spec 057 — cero regresiones funcionales, sólo cambia dónde vive la interacción.

## Assumptions

- Toda la lógica de backend de spec 057 (`UpdateNotaCreditoDebitoRequest`, `NotaCreditoDebitoController@update/destroy/pdf`, `StockService::revertirNotaCreditoDebito`, rutas `PUT`/`DELETE .../notas/{nota}`) se reutiliza sin cambios — este spec sólo agrega las rutas `GET` de la página completa y reemplaza el paso 2 del modal por esa página.
- Los bloques +Percepciones/+Impuestos Internos/+Intereses de la página completa se muestran como enlaces/acordeones colapsados sin funcionalidad propia en este spec (mismo criterio que ya se asumió para el formulario de Compra y en spec 057) — implementarlos queda para un spec futuro si se decide.
- "Documento que Ajusta" mantiene el comportamiento ya construido (selector con el comprobante original; el encadenamiento a otra NC/ND — US4 de spec 057 — sigue pendiente, sin cambios de alcance por este spec).
- El botón "+Agregar" NC/ND del detalle de Venta/Compra sigue abriendo el modal de paso 1 (sin cambios) — lo único que cambia es que el modal ya no tiene paso 2.
