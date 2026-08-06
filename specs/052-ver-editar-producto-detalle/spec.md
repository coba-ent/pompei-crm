# Feature Specification: Ver/Editar producto desde el detalle de Venta, Presupuesto y Compra

**Feature Branch**: `052-ver-editar-producto-detalle`

**Created**: 2026-08-06

**Status**: Draft

**Input**: User description: "En el detalle de Ventas, Presupuestos y Compras, cada fila de la tabla de Conceptos/Detalle que corresponde a un producto/servicio agregado debe tener un desplegable (▾) a la izquierda del nombre con dos acciones: 'Ver' y 'Editar'. 'Ver' abre el mismo modal de sólo lectura que ya existe en la vista de Productos, mostrando el producto de esa fila. 'Editar' abre el mismo modal de alta/edición de Productos precargado con ese producto, permitiendo guardar los cambios sin salir de la pantalla de Venta/Presupuesto/Compra (al guardar, se debe refrescar el nombre/precio de la fila si cambiaron). Fidelidad estructural con Contagram real (docs/informe_contagram_ingresos.md línea 67: 'cada fila tiene un menú ▾ con Ver / Editar'). Aplica sólo a filas de detalle con producto_id asociado."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Ver el producto de una fila del detalle sin salir del formulario (Priority: P1)

Un usuario está cargando o editando una Venta/Presupuesto/Compra y quiere revisar los datos completos de un producto que ya agregó a la tabla de Conceptos (costo, stock, listas de precio, IVA, imagen) sin perder lo que lleva cargado en el formulario.

**Why this priority**: Es la acción más frecuente (consulta) y la de menor riesgo — no modifica datos. Sin ella el usuario tiene que abrir Productos en otra pestaña y buscar el producto manualmente, perdiendo contexto.

**Independent Test**: Se puede probar cargando una Venta nueva, agregando un producto al detalle, abriendo el desplegable de esa fila, eligiendo "Ver" y confirmando que se muestran los mismos datos que muestra "Ver" en el listado de Productos.

**Acceptance Scenarios**:

1. **Given** una fila del detalle con un producto agregado, **When** el usuario abre el desplegable de la fila y hace clic en "Ver", **Then** se abre un modal de sólo lectura con los datos completos de ese producto (nombre, código, estado, tipo, proveedor, stock, costo, precio de venta, IVA, listas de precio, descripción, imagen).
2. **Given** el modal de "Ver" está abierto, **When** el usuario lo cierra, **Then** vuelve a la pantalla de Venta/Presupuesto/Compra exactamente como estaba, sin perder ningún dato cargado en el formulario.

---

### User Story 2 - Editar el producto de una fila del detalle y ver el cambio reflejado (Priority: P2)

Un usuario nota que el producto que acaba de agregar tiene un dato desactualizado (precio, costo, IVA, etc.) y quiere corregirlo ahí mismo, sin salir de la Venta/Presupuesto/Compra que está cargando.

**Why this priority**: Requiere la existencia de la acción "Ver" (comparten el modal de detalle) y agrega la capacidad de modificar datos, con más superficie de riesgo (persistencia, refresco de la fila).

**Independent Test**: Se puede probar agregando un producto al detalle, abriendo "Editar" desde el desplegable de la fila, cambiando el precio de venta, guardando, y verificando que la fila del detalle se actualiza con el nuevo precio sin recargar la página.

**Acceptance Scenarios**:

1. **Given** una fila del detalle con un producto agregado, **When** el usuario abre el desplegable de la fila y hace clic en "Editar", **Then** se abre el mismo modal de alta/edición que usa la vista de Productos, precargado con los datos de ese producto.
2. **Given** el modal de edición está abierto desde el detalle de una Venta/Presupuesto/Compra, **When** el usuario modifica un dato (ej. precio de venta) y guarda, **Then** el cambio se persiste (igual que editando desde Productos) y la fila correspondiente del detalle se actualiza automáticamente si el dato modificado afecta lo mostrado en la fila (nombre y precio unitario, cuando el producto no fue repriceado manualmente en esa fila).
3. **Given** el modal de edición está abierto desde el detalle, **When** el usuario cancela sin guardar, **Then** no se modifica ni la fila del detalle ni el producto, y vuelve a la pantalla de origen tal cual estaba.

---

### Edge Cases

- Fila del detalle sin `producto_id` (línea manual/libre, si existiera): no muestra el desplegable Ver/Editar — sólo aplica a filas que provienen de un producto/servicio del catálogo.
- El usuario edita el producto y cambia su precio de venta, pero la fila del detalle ya tenía un precio unitario tipeado manualmente distinto al del catálogo: la fila no debe pisar silenciosamente ese valor manual con el nuevo precio de catálogo (ver FR-006).
- El usuario intenta "Editar" un producto que fue eliminado o inactivado por otro proceso mientras tenía el formulario abierto: al fallar la carga, se informa el error con un toast y no se abre el modal vacío.
- La pantalla de Venta/Presupuesto/Compra está en modo alta (todavía no persistida) cuando se edita un producto del detalle: la edición del producto es independiente de si la Venta/Presupuesto/Compra en curso ya se guardó o no.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: En la tabla de Conceptos/Detalle de los formularios de Venta, Presupuesto y Compra, cada fila que tiene un producto/servicio del catálogo asociado (con `producto_id`) MUST mostrar un control desplegable (▾) ubicado a la izquierda del nombre del producto, con dos opciones: "Ver" y "Editar".
- **FR-002**: La opción "Ver" MUST abrir el mismo modal de sólo lectura que usa el listado de Productos ("Ver" de la fila de Productos), mostrando los datos completos del producto de esa fila del detalle.
- **FR-003**: La opción "Editar" MUST abrir el mismo modal de alta/edición que usa el listado de Productos, precargado con los datos del producto de esa fila del detalle.
- **FR-004**: Al guardar cambios desde el modal de edición abierto desde el detalle, el sistema MUST persistir los cambios del producto de la misma forma que al editarlo desde la vista de Productos (mismas validaciones, mismo endpoint/lógica de guardado).
- **FR-005**: Después de guardar una edición exitosa desde el detalle, el sistema MUST actualizar en pantalla el nombre mostrado en la fila del detalle si el nombre del producto cambió.
- **FR-006**: Después de guardar una edición exitosa desde el detalle, el sistema MUST actualizar el precio unitario de la fila del detalle con el nuevo precio de venta del producto, salvo que el precio unitario de esa fila ya hubiera sido modificado manualmente por el usuario de forma distinta al precio de catálogo vigente al agregarla — en ese caso el valor manual se conserva y no se pisa.
- **FR-007**: Cerrar el modal de "Ver" o cancelar el modal de "Editar" (sin guardar) MUST devolver al usuario a la pantalla de Venta/Presupuesto/Compra con el formulario intacto (sin pérdida de datos cargados).
- **FR-008**: El desplegable Ver/Editar de la fila MUST NOT mostrarse en filas del detalle que no tengan un `producto_id` asociado (líneas manuales/libres, conceptos de percepciones/impuestos/intereses, etc.).
- **FR-009**: Si falla la carga de los datos del producto al abrir "Ver" o "Editar" desde el detalle (ej. producto eliminado), el sistema MUST mostrar una notificación de error (toast) y no abrir el modal.
- **FR-010**: La disponibilidad y el comportamiento de "Ver" y "Editar" desde el detalle MUST ser consistente entre Ventas, Presupuestos y Compras (mismo desplegable, mismos modales, mismo comportamiento de refresco de fila).

### Key Entities

- **Producto/Servicio**: entidad del catálogo ya existente (módulo Productos) — nombre, código, tipo, precio de venta, costo, IVA, listas de precio, stock, proveedor, imagen. No se agregan atributos nuevos; esta feature sólo agrega puntos de entrada a su visualización/edición.
- **Línea de Detalle (Concepto)**: fila de la tabla de Conceptos de Venta/Presupuesto/Compra — referencia a un producto (`producto_id`), cantidad, precio unitario, descuento, IVA. Esta feature añade el control Ver/Editar a las filas que tienen `producto_id`, y consume el precio/nombre actualizados del producto tras una edición.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Desde el detalle de una Venta, Presupuesto o Compra, un usuario puede consultar los datos completos de un producto agregado sin abandonar la pantalla ni perder los datos cargados en el formulario, en menos de 5 segundos desde que hace clic en "Ver".
- **SC-002**: Un usuario puede corregir un dato de un producto (ej. precio) desde el detalle de una Venta/Presupuesto/Compra y ver la fila actualizada en pantalla sin recargar la página, en el 100% de los casos en que el precio unitario de la fila no fue modificado manualmente.
- **SC-003**: La estructura de la acción (desplegable ▾ con Ver/Editar por fila) es idéntica en Ventas, Presupuestos y Compras, verificable por inspección visual comparando las tres pantallas entre sí y contra el informe de relevamiento de Contagram.

## Assumptions

- Los modales de "Ver" y "Editar" de Productos ya cubren todos los campos relevantes del producto; esta feature no agrega ni quita campos a esos modales, sólo los hace accesibles desde otras pantallas.
- El endpoint y la lógica de guardado de edición de producto ya existentes (usados hoy por la vista de Productos) se reutilizan sin cambios de reglas de negocio.
- "Editar" desde el detalle usa el mismo control de permisos que "Editar" desde la vista de Productos (si un usuario no puede editar productos, tampoco puede hacerlo desde acá) — no se introduce un nuevo nivel de permisos.
- Compras no tiene documentación de relevamiento tan explícita sobre este desplegable como Presupuestos/Ventas (`docs/informe_contagram_ingresos.md`), pero por indicación directa del usuario y por consistencia estructural entre los tres formularios de Conceptos (misma tabla, mismo patrón de agregado de producto), se implementa igual en los tres.
- No se requiere reflejar en la fila el cambio de otros campos del producto (IVA, stock, etc.) más allá de nombre y precio unitario, ya que son los únicos que la fila del detalle muestra directamente.
