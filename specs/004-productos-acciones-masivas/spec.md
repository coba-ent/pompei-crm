# Feature Specification: Selección Múltiple y Acciones Masivas en Productos

**Feature Branch**: `004-productos-acciones-masivas`

**Created**: 2026-07-24

**Status**: Draft

**Input**: User description: "Selección múltiple y Acciones Masivas en el listado de Productos & Servicios (Base de Datos → Productos). Fuente de verdad: docs/informe_contagram_base_de_datos.md §4.1 (checkbox por fila + 'seleccionar todo' en el header de la tabla) y §4.4 (barra de selección + modal 'Acciones Masivas'), con capturas capturas/nuevas/50 y 51. Checkbox por fila + seleccionar todo; barra de selección con acceso al modal; modal 'Acciones Masivas' con 11 operaciones en lote (precio, costo, mostrar en ventas/compras, estado, IVA por defecto, tipo de producto, proveedor, eliminar masivamente); eliminar masivo respeta la regla de no eliminar productos con operaciones; todo por AJAX sin recargar."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Seleccionar productos del listado (Priority: P1)

Como usuario, quiero marcar uno o varios productos del listado (o todos los que matchean el filtro
vigente) para poder aplicarles después una acción en conjunto, en vez de repetir la misma edición
producto por producto.

**Why this priority**: Es el prerrequisito de todo lo demás — sin selección no hay Acciones Masivas.
Es también, por sí sola, una mejora de usabilidad visible e independiente (marcar/desmarcar filas).

**Independent Test**: Se puede probar completamente marcando checkboxes de fila y el checkbox
"seleccionar todo" del header, y verificando que la barra de selección muestra el conteo correcto,
sin necesidad de llegar a ejecutar ninguna acción masiva todavía.

**Acceptance Scenarios**:

1. **Given** el listado de Productos con datos, **When** el usuario marca el checkbox de una fila,
   **Then** aparece una barra de selección con el texto "1 productos seleccionados. Haga click aquí
   para realizar acciones. Seleccionar los N productos." (N = total de productos que matchean el
   filtro/búsqueda vigente, no sólo los de la página visible).
2. **Given** varias filas marcadas, **When** el usuario marca el checkbox "seleccionar todo" del
   header de la tabla, **Then** se marcan todas las filas de la **página visible** (comportamiento
   estándar de "seleccionar todo" de una tabla paginada) y el contador de la barra se actualiza.
3. **Given** la barra de selección visible con "Seleccionar los N productos" como link, **When** el
   usuario hace click en ese link, **Then** la selección pasa a incluir los N productos que matchean
   el filtro/búsqueda vigente (no sólo los de la página actual), y el contador de la barra lo refleja.
4. **Given** productos seleccionados, **When** el usuario cambia de página, cambia el filtro, o
   recarga la tabla (buscar/ordenar), **Then** la selección se limpia (no persiste entre estados
   distintos de la tabla) y la barra de selección desaparece.
5. **Given** ningún producto seleccionado, **When** el usuario mira el listado, **Then** la barra de
   selección no aparece — el listado se ve exactamente igual que hoy.

---

### User Story 2 - Aplicar una acción masiva sobre los productos seleccionados (Priority: P1)

Como usuario, con uno o varios productos ya seleccionados, quiero elegir una operación (cambiar
precio, costo, mostrar en ventas/compras, estado, IVA por defecto, tipo de producto, proveedor, o
eliminar) y aplicarla a todos los seleccionados de una sola vez, para ahorrar el trabajo repetitivo
de editarlos uno por uno.

**Why this priority**: Es la razón de ser de la selección múltiple (User Story 1) — sin esto, marcar
productos no tiene ningún efecto. Ambas historias se entregan juntas como el MVP de esta feature.

**Independent Test**: Con al menos 2 productos seleccionados (User Story 1 ya validada), se puede
abrir el modal "Acciones Masivas", elegir cualquiera de las 11 acciones, completar el valor que pida
esa acción, confirmar, y verificar que los productos seleccionados (y sólo esos) reflejan el cambio.

**Acceptance Scenarios**:

1. **Given** al menos un producto seleccionado, **When** el usuario hace click en el texto "Haga
   click aquí para realizar acciones" de la barra de selección, **Then** se abre el modal "Acciones
   Masivas" ("Realizá acciones masivas sobre el producto seleccionado") con un único select "Elegí
   una Acción".
2. **Given** el modal abierto, **When** el usuario despliega "Elegí una Acción", **Then** ve
   exactamente estas 11 opciones, en este orden: Modificar Precio de Venta, Modificar Costo, Mostrar
   en Ventas, No Mostrar en Ventas, Mostrar en Compras, No Mostrar en Compras, Modificar Estado,
   Modificar IVA por defecto, Modificar Tipo de Producto, Modificar Proveedor, Eliminar Masivamente.
3. **Given** una acción que requiere un valor (Modificar Precio de Venta, Modificar Costo, Modificar
   Estado, Modificar IVA por defecto, Modificar Tipo de Producto, Modificar Proveedor), **When** el
   usuario la elige, **Then** aparece el control correspondiente para cargar ese valor (numérico para
   precio/costo; select para estado/IVA/tipo de producto/proveedor).
4. **Given** una acción sin valor adicional (Mostrar/No Mostrar en Ventas o en Compras), **When** el
   usuario la elige y confirma, **Then** se aplica directamente sin pedir ningún dato extra.
5. **Given** una acción y su valor cargados, **When** el usuario confirma, **Then** el sistema aplica
   el cambio a todos los productos seleccionados por AJAX (sin recargar la página), la tabla se
   actualiza reflejando el nuevo valor, y un toast confirma cuántos productos se modificaron.
6. **Given** "Eliminar Masivamente" elegida, **When** el usuario confirma, **Then** el sistema
   elimina físicamente los productos seleccionados que no tengan operaciones asociadas (movimientos
   de stock), inactiva o deja intactos (según Assumptions) los que sí las tengan, y muestra un
   resumen: cuántos se eliminaron y cuántos no pudieron eliminarse (con el motivo).
7. **Given** el modal de Acciones Masivas abierto sin haber elegido ninguna acción, **When** el
   usuario intenta confirmar, **Then** el sistema no ejecuta nada y pide elegir una acción primero.

---

### Edge Cases

- ¿Qué pasa si, entre que el usuario selecciona productos y confirma la acción masiva, otro usuario
  edita o elimina alguno de esos productos? → La acción se aplica sobre los que todavía existan; los
  que ya no existan se reportan como "no procesados" en el resumen, sin abortar el resto del lote.
- ¿Qué pasa si "Eliminar Masivamente" se aplica sobre una selección donde **todos** tienen
  operaciones asociadas? → Ninguno se elimina físicamente; el resumen final lo deja explícito (0
  eliminados, N con operaciones asociadas) — no es un error silencioso ni bloquea el modal.
- ¿Qué pasa si se elige "Modificar Proveedor" y no hay proveedores activos cargados? → El select de
  proveedores queda vacío/deshabilitado con una nota, sin romper el modal (mismo patrón que el
  selector de Proveedor ya usado en el alta de Producto).
- ¿Qué pasa si el usuario selecciona "los N productos" (todos los que matchean el filtro) y ese N es
  muy grande (todo el catálogo)? → La acción masiva igual se aplica a todos por lotes en el backend,
  sin límite artificial de cantidad ni timeout visible para el usuario (ver Success Criteria).
- ¿Qué pasa si se cambia el valor de un campo con reglas de validación existentes (ej. precio
  negativo, IVA fuera de las opciones fijas) durante una acción masiva? → Se valida igual que en el
  alta/edición individual; si el valor no pasa validación, no se aplica a ningún producto del lote y
  se informa el motivo (falla atómica de la operación masiva, no parcial por regla de negocio).

## Requirements *(mandatory)*

### Functional Requirements

**Selección (US1)**

- **FR-001**: El listado de Productos DEBE mostrar un checkbox por fila, adicional al menú de
  acciones de fila ya existente (Ver/Editar/Eliminar/Crear Copia/Movimientos/Aumentar/Disminuir
  Stock), que se mantiene sin cambios.
- **FR-002**: El header de la tabla DEBE incluir un checkbox "seleccionar todo" que marca/desmarca
  todas las filas de la página visible actual.
- **FR-003**: Al haber al menos un producto seleccionado, el sistema DEBE mostrar una barra de
  selección con el conteo de seleccionados y un link para ampliar la selección a **todos** los
  productos que matchean el filtro/búsqueda vigente (no sólo los de la página visible).
- **FR-004**: La selección DEBE limpiarse automáticamente al cambiar de página, filtro, orden o
  búsqueda de la tabla (no persiste entre distintos estados de la vista).

**Acciones Masivas (US2)**

- **FR-005**: El sistema DEBE ofrecer, desde la barra de selección, un modal "Acciones Masivas" con
  un único select "Elegí una Acción" con exactamente estas 11 opciones: Modificar Precio de Venta,
  Modificar Costo, Mostrar en Ventas, No Mostrar en Ventas, Mostrar en Compras, No Mostrar en
  Compras, Modificar Estado, Modificar IVA por defecto, Modificar Tipo de Producto, Modificar
  Proveedor, Eliminar Masivamente.
- **FR-006**: Al elegir una acción que requiere un valor (precio, costo, estado, IVA por defecto,
  tipo de producto, proveedor), el sistema DEBE mostrar el control correspondiente para cargarlo
  antes de permitir confirmar.
- **FR-007**: El sistema DEBE aplicar la acción elegida únicamente a los productos seleccionados en
  ese momento, por AJAX, sin recargar la página, actualizando la tabla y notificando el resultado
  con un toast (regla de diseño obligatoria del proyecto).
- **FR-008**: "Eliminar Masivamente" NO DEBE eliminar físicamente ningún producto que tenga
  operaciones asociadas (movimientos de stock) — misma regla ya vigente para la eliminación
  individual — y DEBE informar al usuario cuáles productos del lote no se pudieron eliminar y por
  qué, en la misma respuesta (no en una notificación separada ni silenciosamente).
- **FR-009**: "Modificar IVA por defecto" DEBE actualizar tanto el IVA de ventas como el de compras
  del producto, usando las mismas opciones fijas ya validadas en el alta individual (5, 10.5, 21,
  27, Exento, No Gravado).
- **FR-010**: Todas las validaciones ya vigentes para los campos individuales (precio/costo no
  negativos, IVA dentro de las opciones fijas, proveedor/tipo de producto existentes) DEBEN aplicarse
  también en el contexto de la acción masiva.

### Key Entities *(include if feature involves data)*

- No se agregan entidades nuevas. La feature opera enteramente sobre `Producto` (entidad ya
  existente): actualización en lote de sus campos (`precio_venta`, `costo`, `mostrar_en_ventas`,
  `mostrar_en_compras`, `activo`, `iva_venta_pct`/`iva_compra_pct`, `tipo_producto_id`,
  `proveedor_id`) o eliminación en lote respetando la regla de `tieneOperaciones()`.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Un usuario puede aplicar un cambio (ej. modificar precio de venta) a 10 productos
  seleccionados en una sola operación, en menos de 15 segundos, sin recargar la página.
- **SC-002**: El 100% de los productos con operaciones asociadas quedan protegidos de la eliminación
  física al ser incluidos en un lote de "Eliminar Masivamente", y el usuario recibe siempre un
  resumen claro de cuántos se eliminaron y cuántos no.
- **SC-003**: La selección "todos los N productos que matchean el filtro" selecciona exactamente esa
  cantidad (verificable comparando contra el contador de resultados del listado), incluso cuando
  supera la cantidad de filas visibles en la página actual.
- **SC-004**: La estructura del modal "Acciones Masivas" (título, subtítulo, las 11 opciones en el
  mismo orden) coincide con lo relevado en `docs/informe_contagram_base_de_datos.md` §4.4 y las
  capturas `capturas/nuevas/50` y `51`.

## Assumptions

- El checkbox "seleccionar todo" del header sólo afecta la página visible (comportamiento estándar
  de tablas paginadas); seleccionar **todos** los que matchean el filtro es una acción explícita
  aparte (el link "Seleccionar los N productos" de la barra), tal como se relevó en Contagram real.
- "Modificar IVA por defecto" actualiza `iva_venta_pct` **e** `iva_compra_pct` a la vez con el mismo
  valor elegido — el informe releva una sola acción en el dropdown (no dos separadas para venta y
  compra), y no hay evidencia en las capturas de que pida dos valores distintos.
- La eliminación masiva es **atómica por producto, no por lote**: cada producto del lote se evalúa
  individualmente (se elimina si no tiene operaciones, se reporta si las tiene); un producto con
  operaciones asociadas no aborta la eliminación del resto del lote.
- Las demás acciones masivas (precio, costo, estado, IVA, tipo de producto, proveedor, mostrar en
  ventas/compras) se aplican de forma atómica **para todo el lote**: si algún valor no pasa
  validación (ej. precio negativo), no se aplica a ningún producto del lote — evita dejar el
  catálogo en un estado parcialmente actualizado por un error de tipeo.
- Esta feature reutiliza el patrón ya vigente en Productos (002-productos): DataTable AJAX
  server-side, modal Bootstrap por AJAX sin recargar, toasts de resultado, Select2 en los selects de
  Tipo de Producto/Proveedor dentro del modal (reglas de diseño obligatorias de `CLAUDE.md`).
- No se agrega paginado, filtro ni buscador propio al modal "Acciones Masivas" — es un único select
  con el valor correspondiente debajo, tal como se relevó (sin evidencia de que Contagram real
  incluya más controles ahí).
- El límite de cantidad de productos por lote no está acotado artificialmente por este spec — el
  plan técnico decide si conviene procesar en background/por chunks para lotes muy grandes, pero
  desde la perspectiva del usuario la operación siempre se percibe como una sola acción.
