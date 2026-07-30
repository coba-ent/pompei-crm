# Feature Specification: Vendedores como catálogo propio

**Feature Branch**: `020-vendedores`

**Created**: 2026-07-30

**Status**: Draft

**Input**: User description: "Nueva entidad 'Vendedores': tabla simple con solo nombre (igual patrón que Categorías: ABM inline desde un select, sin pantalla propia de administración). Corrección de fidelidad estructural: hoy 'Vendedor' en Ventas/Presupuestos es el usuario logueado del sistema (vendedor_id → users), pero el informe de relevamiento de Contagram real muestra 'Vendedor' y 'Usuario' como dos campos distintos en los filtros — Vendedor debe ser un catálogo propio. Se agrega el select de Vendedor (con ABM inline) al formulario de alta/edición de Venta y Presupuesto, donde hoy no existía (se autocompletaba en silencio). Se migran los datos existentes preservando el historial. Se agrega vendedor por defecto en la configuración de Tiendanube y MercadoLibre, igual patrón que 'categoría de venta por defecto'."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Elegir vendedor al cargar una Venta o Presupuesto (Priority: P1)

Como usuario que carga una Venta o un Presupuesto, quiero elegir de una lista qué vendedor realizó la operación, para que el negocio pueda distinguir quién vendió cada operación (hoy ese dato no se puede elegir: se autocompleta en silencio con el usuario que está logueado en el CRM, lo cual no refleja quién vendió realmente).

**Why this priority**: Es el cambio central pedido: sin esto, Vendedor sigue siendo un dato inútil (el usuario logueado) en vez de información de negocio real.

**Independent Test**: Se puede probar completamente abriendo el formulario de Nueva Venta (o Nuevo Presupuesto), eligiendo un vendedor del select, guardando, y verificando que el listado/detalle muestran ese vendedor — sin tocar ninguna otra parte del sistema.

**Acceptance Scenarios**:

1. **Given** el formulario de alta de Venta, **When** el usuario abre el select de Vendedor, **Then** ve la lista de vendedores existentes y puede elegir uno o dejarlo vacío.
2. **Given** un vendedor elegido en el formulario, **When** se guarda la Venta, **Then** la columna Vendedor del listado, el detalle y el PDF de esa Venta muestran el vendedor elegido (no el usuario logueado). *(Nota de alcance: hoy no existe ningún filtro por Vendedor —ni por Usuario— en el panel de filtros de Ventas/Presupuestos, pese a estar documentado en el informe de relevamiento; es una brecha preexistente de specs anteriores, fuera del alcance de esta spec — ver FR-007 y Assumptions).*
3. **Given** el mismo formulario, **When** el usuario no elige ningún vendedor, **Then** la Venta se guarda igual con Vendedor vacío (campo opcional).
4. **Given** el formulario de alta de Presupuesto, **When** se repiten los pasos anteriores, **Then** se comporta igual que en Venta.

---

### User Story 2 - Crear, renombrar y eliminar vendedores desde el mismo select (Priority: P1)

Como usuario, quiero poder dar de alta un vendedor nuevo, renombrar uno existente o eliminarlo directamente desde el select del formulario de Venta/Presupuesto, sin salir del formulario ni ir a una pantalla separada — igual que ya puedo hacerlo con Categorías.

**Why this priority**: Sin ABM inline, cada vendedor nuevo requeriría intervención manual fuera del flujo de carga, rompiendo el patrón ya validado de Categorías que el negocio ya usa a diario.

**Independent Test**: Se puede probar completamente desde el select de Vendedor de cualquiera de los dos formularios: crear un vendedor nuevo escribiendo su nombre, renombrar uno existente, e intentar eliminar uno con y sin ventas/presupuestos asociados — sin depender de otras historias.

**Acceptance Scenarios**:

1. **Given** el select de Vendedor vacío o con vendedores existentes, **When** el usuario escribe un nombre nuevo y confirma "crear", **Then** el vendedor queda creado y seleccionado en el formulario, disponible además para el resto de los formularios.
2. **Given** un vendedor existente, **When** el usuario lo renombra desde el select, **Then** el nuevo nombre se refleja en el select y en todas las Ventas/Presupuestos históricos que lo tengan asignado (es el mismo registro).
3. **Given** un vendedor sin ninguna Venta/Presupuesto asociado, **When** el usuario lo elimina desde el select, **Then** el vendedor desaparece de la lista.
4. **Given** un vendedor CON al menos una Venta o Presupuesto asociado, **When** el usuario intenta eliminarlo, **Then** el sistema rechaza la eliminación con un mensaje claro (no se puede eliminar: está en uso).

---

### User Story 3 - Vendedor por defecto para ventas automáticas de Tiendanube y MercadoLibre (Priority: P2)

Como usuario que configura las integraciones de Tiendanube y MercadoLibre, quiero definir un vendedor por defecto para que las Ventas que se crean automáticamente desde esas plataformas queden asignadas a ese vendedor, igual que hoy puedo definir una categoría de venta por defecto para esas mismas ventas automáticas.

**Why this priority**: Es una consecuencia directa y ya patronizada (mismo mecanismo que categoría por defecto) del cambio central, pero no bloquea el uso manual de vendedores (Historias 1 y 2), por eso es P2.

**Independent Test**: Se puede probar completamente entrando a la pantalla de configuración de Tiendanube (o MercadoLibre), eligiendo un vendedor por defecto, guardando, y disparando la creación automática de una Venta desde una orden sincronizada — sin depender de que existan datos de Historia 1/2 más allá de tener al menos un vendedor creado.

**Acceptance Scenarios**:

1. **Given** la pantalla de configuración de Tiendanube, **When** el usuario elige un vendedor por defecto y guarda, **Then** la configuración queda persistida (igual que la categoría de venta por defecto).
2. **Given** un vendedor por defecto configurado en Tiendanube, **When** se sincroniza una orden y se crea la Venta automáticamente, **Then** esa Venta queda con el vendedor por defecto asignado.
3. **Given** la misma configuración pero en MercadoLibre, **When** se repiten los pasos anteriores, **Then** se comporta igual que en Tiendanube, de forma independiente (cada integración tiene su propio vendedor por defecto).
4. **Given** ninguna integración tiene vendedor por defecto configurado, **When** se crea una Venta automática, **Then** la Venta se crea igual, con Vendedor vacío (el default es opcional, igual que el campo en el resto del sistema).

---

### Edge Cases

- ¿Qué pasa si dos usuarios intentan crear un vendedor con el mismo nombre casi al mismo tiempo desde dos formularios distintos? El sistema debe rechazar el duplicado (nombre único), igual que Categorías.
- ¿Qué pasa con las Ventas/Presupuestos ya existentes, cuyo Vendedor hoy es "el usuario que estaba logueado al crearlos"? Se migran automáticamente: se crea un vendedor por cada usuario del sistema que aparezca hoy como vendedor en al menos un registro existente, y esos registros se remapean a ese nuevo vendedor — no se pierde el historial.
- ¿Qué pasa si se elimina un vendedor que está configurado como "vendedor por defecto" de Tiendanube o MercadoLibre? Debe rechazarse igual que si tuviera Ventas asociadas (está en uso), para no dejar la integración apuntando a un vendedor inexistente.
- ¿Qué pasa si el nombre de usuario migrado (Historia de migración) coincide exactamente con un vendedor creado manualmente después? Son registros distintos con el mismo nombre — el sistema no fusiona automáticamente; si el negocio quiere unificarlos, lo hace manualmente (eliminar uno y reasignar, fuera de alcance de esta feature).

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: El sistema DEBE mantener un catálogo de Vendedores, cada uno identificado únicamente por un nombre (sin más atributos de negocio).
- **FR-002**: El sistema DEBE exigir que el nombre de un vendedor sea único dentro del catálogo.
- **FR-003**: El sistema DEBE permitir elegir un Vendedor desde un select buscable al crear o editar una Venta, y al crear o editar un Presupuesto; el campo es opcional (se puede guardar sin elegir vendedor).
- **FR-004**: El sistema DEBE permitir crear un vendedor nuevo directamente desde ese mismo select, sin salir del formulario de Venta/Presupuesto.
- **FR-005**: El sistema DEBE permitir renombrar un vendedor existente desde ese mismo select, reflejándose el cambio en todas las Ventas/Presupuestos que ya lo tengan asignado.
- **FR-006**: El sistema DEBE permitir eliminar un vendedor desde ese mismo select siempre que no esté en uso (ninguna Venta, Presupuesto, ni configuración de vendedor por defecto de una integración lo esté referenciando); si está en uso, DEBE rechazar la eliminación con un mensaje explicando el motivo.
- **FR-007**: El sistema DEBE seguir mostrando la columna "Vendedor" en los listados de Ventas y de Presupuestos, y el dato "Vendedor" en el detalle y el PDF de cada uno, ahora tomados del catálogo de Vendedores en lugar de los usuarios del sistema. *(Corregido en análisis: no existe hoy ningún filtro por Vendedor en el panel de filtros de Ventas/Presupuestos —tampoco por Usuario, pese a estar documentados ambos en el informe de relevamiento—; agregar ese filtro es una brecha preexistente de specs anteriores [008/017], fuera del alcance de esta spec. Ver Assumptions.)*
- **FR-008**: El sistema DEBE migrar, sin pérdida de historial, los datos existentes: por cada usuario del sistema que hoy figura como vendedor de al menos una Venta o Presupuesto existente, se crea un registro de Vendedor con el mismo nombre, y esos registros existentes quedan remapeados a ese nuevo vendedor.
- **FR-009**: El sistema DEBE dejar de autocompletar el campo Vendedor con el usuario logueado al crear una Venta o Presupuesto; a partir de esta feature el valor sólo sale de la elección explícita del usuario en el formulario (o del vendedor por defecto de la integración, para ventas automáticas — ver FR-010).
- **FR-010**: El sistema DEBE permitir configurar, de forma independiente, un Vendedor por defecto para las Ventas que se crean automáticamente desde Tiendanube y para las que se crean automáticamente desde MercadoLibre, siguiendo el mismo patrón ya existente para "categoría de venta por defecto" en ambas integraciones.
- **FR-011**: El sistema DEBE asignar ese Vendedor por defecto a cada Venta creada automáticamente por sincronización de Tiendanube o de MercadoLibre, cuando ese default esté configurado; si no está configurado, la Venta se crea con Vendedor vacío.
- **FR-012**: El sistema NO DEBE ofrecer una pantalla de administración separada para Vendedores: la única vía de alta/edición/baja es el select inline embebido en los formularios de Venta y Presupuesto (y, para el default, en las pantallas de configuración de Tiendanube/MercadoLibre).

### Key Entities *(include if feature involves data)*

- **Vendedor**: catálogo simple de nombres de vendedores. Atributos: nombre (único). Se relaciona con Ventas y Presupuestos (un vendedor puede estar asociado a muchas Ventas/Presupuestos; cada Venta/Presupuesto tiene a lo sumo un vendedor, opcional). También puede estar referenciado como "vendedor por defecto" desde la configuración de Tiendanube y desde la de MercadoLibre (cada una por separado).
- **Venta / Presupuesto** (existentes): pasan a asociarse con Vendedor (catálogo propio) en lugar de con Usuario del sistema.
- **Configuración de Tiendanube / Configuración de MercadoLibre** (existentes): incorporan una referencia opcional a "vendedor por defecto", análoga a la ya existente "categoría de venta por defecto".

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Un usuario puede cargar una Venta o Presupuesto y dejar asentado quién la vendió en menos de 10 segundos adicionales de trabajo (elegir de un select ya abierto).
- **SC-002**: El 100% de las Ventas y Presupuestos existentes antes del cambio conservan un Vendedor asignado equivalente al que tenían antes de la migración (ningún registro histórico queda con el dato de vendedor perdido).
- **SC-003**: Un usuario puede crear un vendedor nuevo desde cero y usarlo en una Venta sin abandonar el formulario de carga, en un solo flujo continuo.
- **SC-004**: Las ventas automáticas creadas por Tiendanube o MercadoLibre después de configurar un vendedor por defecto quedan con ese vendedor asignado en el 100% de los casos, sin intervención manual.

## Assumptions

- El catálogo de Vendedores es plano (sin jerarquía, sin tipos, sin distinción activo/inactivo) — a diferencia de Categorías, que sí tiene esos atributos porque los necesita para otros usos (subcategorías de Gasto, categorías de sistema no eliminables).
- El campo Vendedor sigue siendo opcional en Ventas y Presupuestos, igual que lo era el `vendedor_id` actual (apuntando a usuarios).
- No se requiere una pantalla de administración dedicada para Vendedores (decisión explícita del negocio): el ABM vive enteramente dentro del select, igual que Categorías.
- La migración de datos históricos asume que el nombre del usuario del sistema (`users.name`) es un nombre razonable para representar a ese "vendedor" migrado; si dos usuarios distintos tienen el mismo `name`, se crea un vendedor por cada uno igual (evaluar unicidad en el detalle de implementación, fuera del alcance de negocio de esta spec).
- "Usuario" (quién está logueado) y "Vendedor" (quién realizó la venta) quedan como conceptos completamente independientes de acá en adelante, tal como confirma el relevamiento real de Contagram (ambos aparecen documentados como filtros separados en el informe).
- **Brecha preexistente detectada en análisis (fuera de alcance de esta spec)**: el panel de filtros de Ventas y Presupuestos hoy sólo implementa 3 de los 15/11 campos documentados en el informe de relevamiento (Buscar, Cliente, Creada Desde) — ni "Vendedor" ni "Usuario" ni el resto existen todavía como filtro real, pese a que la columna sí se muestra. Esta spec no agrega el filtro por Vendedor (ni por ningún otro campo): construirlo pertenece a completar la brecha ya documentada de specs 008/017, no a spec 020. Queda documentado también en `docs/documentacion_principal_crm.md`.
