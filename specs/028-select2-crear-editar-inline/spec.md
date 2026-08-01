# Feature Specification: Crear/editar catálogo inline en selects de Presupuestos

**Feature Branch**: `028-select2-crear-editar-inline`

**Created**: 2026-07-31

**Status**: Draft

**Input**: User description: "Corregir el patrón de selects dinámicos con catálogo editable (Cliente, Categoría de Venta, Vendedor) en el formulario de Presupuestos para que calque el comportamiento real de Contagram: dentro del propio dropdown, una opción fija 'Crear X' con ícono + arriba de la lista, y cada ítem existente con su propio ícono de lápiz para editarlo sin tener que seleccionarlo primero. Basado en capturas reales de Contagram (docs/capturas/saldos/WhatsApp Image 2026-07-30 at 12.16.07/12.16.30/12.16.49/12.17.17 PM.jpeg)."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Crear un cliente/categoría/vendedor nuevo sin salir del presupuesto (Priority: P1)

Un usuario está cargando un Nuevo Presupuesto y el cliente, la categoría de venta o el vendedor que necesita todavía no existen en el sistema. Hoy tiene que abandonar el formulario (perdiendo lo cargado) para ir a crear ese registro en otro módulo y volver a empezar. Necesita poder crearlo sin salir de la pantalla.

**Why this priority**: Es el caso de uso más frecuente (alta de presupuestos con clientes nuevos) y el que genera más fricción/abandono hoy.

**Independent Test**: Abrir "Nuevo Presupuesto", abrir el dropdown de Cliente sin escribir nada, click en la opción "Crear Cliente" fijada arriba de la lista, completar el nombre en el modal, confirmar, y verificar que el cliente recién creado queda seleccionado en el formulario sin recargar la página. Repetir para Categoría de Venta y Vendedor.

**Acceptance Scenarios**:

1. **Given** el formulario de Nuevo Presupuesto vacío, **When** el usuario abre el dropdown de Cliente, **Then** ve una primera fila fija "Crear Cliente" con ícono "+", visualmente destacada, por encima de los clientes existentes (aun cuando hay texto de búsqueda que no matchea a ningún cliente).
2. **Given** el dropdown de Cliente abierto, **When** el usuario hace click en "Crear Cliente", **Then** se abre un modal de alta rápida; al confirmar con el nombre cargado, el modal se cierra, el nuevo cliente queda seleccionado en el select y una notificación toast confirma la creación.
3. **Given** el dropdown de Categoría de Venta o de Vendedor abierto, **When** el usuario hace click en "Crear Categoría de ventas" / "Crear Vendedor", **Then** el comportamiento es el mismo que hoy (modal ya existente `_modal_categoria` / `_modal_vendedor`), sólo cambia el punto de entrada: se dispara desde la opción del dropdown en vez del link fijo al lado del label.
4. **Given** un alta exitosa desde cualquiera de los tres selects, **When** el usuario vuelve a abrir el mismo dropdown, **Then** el nuevo ítem aparece en la lista de opciones (sin necesidad de recargar la página).

---

### User Story 2 - Editar un cliente/categoría/vendedor existente desde la lista, sin seleccionarlo primero (Priority: P2)

El usuario nota que el nombre de una categoría de venta o de un vendedor está mal escrito (o quiere renombrar un cliente) mientras está eligiendo la opción en el presupuesto. Hoy sólo puede renombrar el ítem que ya tiene seleccionado en el formulario; para renombrar cualquier otro tiene que seleccionarlo primero (pisando su elección real) o salir del formulario.

**Why this priority**: Menos frecuente que el alta, pero es el segundo punto de fricción relevado y usa la misma superficie de UI que la historia 1.

**Independent Test**: Abrir el dropdown de Categoría de Venta, ubicar un ítem que no es el seleccionado actualmente, hacer click en su ícono de lápiz, renombrarlo en el modal, confirmar, y verificar que la lista se actualiza con el nuevo nombre sin alterar la selección vigente del formulario.

**Acceptance Scenarios**:

1. **Given** el dropdown de Cliente/Categoría/Vendedor abierto con al menos un ítem en la lista, **When** el usuario mira una fila, **Then** ve un ícono de lápiz a la derecha de esa fila, independiente de si esa fila está seleccionada o no.
2. **Given** el dropdown abierto, **When** el usuario hace click en el lápiz de un ítem (no en el texto de la fila), **Then** se abre el modal de edición de ESE ítem puntual (no el actualmente seleccionado en el formulario) sin cerrar ni alterar el resto del formulario.
3. **Given** el modal de edición abierto para un ítem, **When** el usuario confirma el cambio de nombre, **Then** la lista del select se refresca con el nombre nuevo y, si ese ítem era el seleccionado en el formulario, la selección visible también se actualiza.
4. **Given** el modal de edición abierto, **When** el usuario cancela, **Then** no se aplica ningún cambio y el dropdown vuelve a su estado anterior.

---

### Edge Cases

- ¿Qué pasa si el usuario escribe un texto de búsqueda en el dropdown y no hay resultados? → La opción "Crear X" sigue visible arriba (permite pre-cargar el nombre buscado como sugerencia inicial del modal de alta).
- ¿Qué pasa si dos usuarios crean un cliente con el mismo nombre desde dos presupuestos distintos al mismo tiempo? → No hay restricción de unicidad por nombre (ya es así hoy en Cliente/Categoría/Vendedor); ambos se crean como registros separados.
- ¿Qué pasa si el usuario intenta editar un ítem justo cuando otro usuario lo eliminó? → El modal de edición muestra el error de "no encontrado" devuelto por el backend (ya manejado por los endpoints existentes) sin romper el formulario de presupuesto.
- ¿Qué pasa con el link "Eliminar" que hoy existe al lado del label de Categoría/Vendedor? → Ver FR-006.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: El select de Cliente, el de Categoría de Venta y el de Vendedor del formulario de Presupuestos (alta y edición) DEBEN mostrar, dentro del propio dropdown desplegado, una opción fija "Crear Cliente" / "Crear Categoría de ventas" / "Crear Vendedor" con ícono "+", ubicada siempre arriba de los resultados de búsqueda/listado, independientemente del texto ingresado.
- **FR-002**: Cada ítem listado en esos tres dropdowns DEBE mostrar un ícono de edición (lápiz) a su derecha, que abre el modal de edición de ese ítem puntual sin requerir seleccionarlo previamente en el formulario.
- **FR-003**: Al confirmar un alta o edición desde estos modales, el select correspondiente DEBE reflejar el cambio inmediatamente (nuevo ítem disponible y seleccionado, o nombre actualizado en la lista) sin recargar la página, reutilizando los endpoints y modales de alta/edición ya existentes para Categoría de Venta (`categorias.venta.store` + `_modal_categoria`) y Vendedor (`vendedores.store` + `_modal_vendedor`).
- **FR-004**: El select de Cliente DEBE incorporar el mismo mecanismo de alta inline ("Crear Cliente"), usando el endpoint de creación de cliente ya existente (`ClienteController::store`, que sólo exige el campo Nombre) con un modal de alta rápida que pida como mínimo el Nombre.
- **FR-005**: El select de Cliente DEBE incorporar el mismo mecanismo de edición inline (lápiz por fila) para renombrar el nombre del cliente sin salir del presupuesto, reutilizando el endpoint de actualización de cliente ya existente, sin exponer en este modal rápido los campos que no forman parte del alta/edición mínima (facturación, contactos, campos personalizados, etc. — esos siguen editándose sólo desde el módulo Clientes).
- **FR-006**: Los links "Renombrar"/"Eliminar" fijos al lado del label de Categoría de Venta y de Vendedor DEJAN de mostrarse en este formulario; el alta y la edición pasan a dispararse exclusivamente desde el dropdown (FR-001/FR-002). La eliminación de Categoría de Venta / Vendedor queda fuera de alcance de este spec (no está presente en el comportamiento real relevado en las capturas): no se reemplaza por ningún otro punto de acceso dentro de este formulario. Si el negocio necesita poder eliminarlas, es una decisión de producto a evaluar aparte (no hay hoy un módulo de administración propio para estos catálogos).
- **FR-007**: El comportamiento de estos tres selects (Cliente, Categoría de Venta, Vendedor) en el formulario de Ventas, Otros Ingresos y Compras (que reutilizan los mismos catálogos) NO se modifica en este alcance — queda para un spec futuro.
- **FR-008**: Los modales de alta/edición inline DEBEN seguir las especificaciones de diseño obligatorias del proyecto: modal Bootstrap + AJAX (sin recarga de página) y notificación por toast (éxito/error), igual que el mecanismo ya existente para Categoría de Venta y Vendedor.

### Key Entities

- **Cliente**: entidad ya existente; esta feature sólo agrega un punto de alta/edición mínima (Nombre) adicional al ya existente en el módulo Clientes.
- **Categoría (de venta)**: entidad ya existente; esta feature reubica el disparador de alta/edición, no cambia su modelo de datos.
- **Vendedor**: entidad ya existente; mismo caso que Categoría.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Un usuario puede crear un cliente, una categoría de venta o un vendedor nuevo sin salir del formulario de Presupuestos ni perder los datos ya cargados, en menos de 15 segundos desde que detecta que el ítem no existe.
- **SC-002**: Un usuario puede corregir el nombre de cualquier cliente, categoría de venta o vendedor listado en el dropdown, sin alterar la selección vigente del presupuesto que está cargando.
- **SC-003**: 0 recargas de página durante todo el flujo de alta/edición inline (consistente con la regla de "modales + AJAX" del proyecto).
- **SC-004**: El 100% de los presupuestos nuevos que requieran un cliente/categoría/vendedor inexistente pueden completarse en una sola sesión de formulario (sin necesidad de abrir otra pestaña/módulo).

## Assumptions

- El modal de alta rápida de Cliente sólo pide el campo Nombre (igual que el modal ya relevado para Vendedor), dejando el resto de los datos de la ficha de cliente (facturación, contactos, etc.) para completarse después desde el módulo Clientes — no hay evidencia en las capturas de un formulario más extenso para el alta inline.
- La eliminación de Categoría de Venta / Vendedor desde el formulario de Presupuestos se retira sin reemplazo en este spec (no está en las capturas). Hoy no existe un módulo de administración propio para esos catálogos; si el negocio necesita poder eliminarlos, queda como decisión de producto a evaluar en un spec aparte.
- Este spec no toca Ventas, Otros Ingresos ni Compras, aunque comparten los mismos catálogos — queda documentado como extensión futura (ver `docs/documentacion_principal_crm.md`).
- Los endpoints de creación/edición de Cliente, Categoría de Venta y Vendedor ya existentes (`ClienteController`, `CategoriaController`, `VendedorController`) se reutilizan tal cual; no se crean endpoints nuevos.
