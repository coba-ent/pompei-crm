# Feature Specification: Vendedores — activar/desactivar

**Feature Branch**: `101-vendedores-activo-inactivo`

**Created**: 2026-09-08

**Status**: Draft

**Input**: User description: "El cliente pidió implementar que a los vendedores se los pueda desactivar para no eliminarlos, pero que no figuren en los listados, etc."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Desactivar un vendedor sin perder su historial (Priority: P1)

Un vendedor deja de trabajar en el negocio (o cambia de rol y ya no vende). El usuario administrador quiere que deje de aparecer como opción al registrar nuevas Ventas, Presupuestos o al configurar integraciones, pero sin borrarlo, porque sigue apareciendo asociado a ventas y presupuestos ya emitidos.

**Why this priority**: Es el pedido explícito del cliente y resuelve el problema real: hoy la única opción es borrar (lo cual puede fallar por estar en uso, o si no falla, rompe la trazabilidad histórica) o dejarlo activo para siempre ensuciando los selects.

**Independent Test**: Se puede probar completamente desactivando un vendedor desde la pantalla de gestión y verificando que deja de listarse en el select de Vendedor al crear una Venta nueva, mientras que una Venta antigua que ya lo tenía asignado sigue mostrándolo sin errores.

**Acceptance Scenarios**:

1. **Given** un vendedor activo sin ventas asociadas, **When** el administrador lo desactiva desde la pantalla de gestión de Vendedores, **Then** el vendedor pasa a estado Inactivo y deja de aparecer en el select de Vendedor al crear una Venta, un Presupuesto, o al configurar Tiendanube/MercadoLibre.
2. **Given** un vendedor activo con ventas o presupuestos ya emitidos a su nombre, **When** el administrador lo desactiva, **Then** la desactivación se completa sin error y las Ventas/Presupuestos existentes siguen mostrando ese vendedor sin cambios.
3. **Given** un vendedor inactivo, **When** el administrador lo reactiva, **Then** vuelve a aparecer en los selects para nuevas asignaciones.

---

### User Story 2 - Gestionar vendedores desde una pantalla propia en Configuración & Ajustes (Priority: P2)

Hoy Vendedor no tiene pantalla propia: se crea/edita/borra sólo desde el buscador inline de los selects de Venta y Presupuesto. El usuario administrador quiere un lugar central en Configuración & Ajustes donde ver todos los vendedores (activos e inactivos), y activar/desactivar sin tener que ir a crear una Venta para encontrar el select.

**Why this priority**: Complementa la Historia 1 dándole un lugar visible y predecible a la gestión, en línea con el resto de catálogos de Configuración & Ajustes (Depósitos, Roles, etc.), sin la cual activar/desactivar sólo sería posible desde dentro del flujo de Venta/Presupuesto.

**Independent Test**: Se puede probar entrando a Configuración & Ajustes → tab Vendedores y verificando que lista todos los vendedores con su estado, permite crear uno nuevo, renombrarlo y cambiar su estado, todo sin salir de la pantalla ni recargarla.

**Acceptance Scenarios**:

1. **Given** el administrador está en Configuración & Ajustes, **When** abre el tab Vendedores, **Then** ve una tabla con todos los vendedores (activos e inactivos) y su estado.
2. **Given** la tabla de Vendedores, **When** el administrador da de alta un vendedor nuevo, **Then** se crea como Activo y aparece en la tabla sin recargar la página.
3. **Given** un vendedor en la tabla, **When** el administrador usa el control de estado, **Then** el vendedor cambia de Activo a Inactivo (o viceversa) al instante, con una notificación de confirmación, sin recargar la página.

---

### User Story 3 - Vendedor por defecto que se desactiva (Priority: P3)

El negocio tiene configurado un "Vendedor por defecto" (Configuración & Ajustes → Ventas) que precarga el select al crear una Venta nueva. Si ese vendedor se desactiva, el usuario necesita darse cuenta de que la configuración por defecto quedó apuntando a alguien que ya no está disponible para asignar.

**Why this priority**: Es un caso derivado y menos frecuente que las Historias 1 y 2, pero si no se resuelve deja a "Crear Venta" con un valor por defecto inválido de forma silenciosa.

**Independent Test**: Se puede probar configurando un Vendedor por defecto, desactivándolo, y verificando que "Crear Venta" ya no lo precarga automáticamente y que la pantalla de configuración avisa que el vendedor por defecto configurado está inactivo.

**Acceptance Scenarios**:

1. **Given** un vendedor activo configurado como "Vendedor por defecto" en Configuración & Ajustes → Ventas, **When** el administrador lo desactiva, **Then** la sección Ventas de Configuración & Ajustes muestra un aviso indicando que el vendedor por defecto configurado está inactivo.
2. **Given** un vendedor por defecto que quedó inactivo, **When** el usuario abre "Crear Venta", **Then** el campo Vendedor no se precarga con ese vendedor inactivo (queda vacío, a elección del usuario entre los vendedores activos).

---

### Edge Cases

- Un vendedor inactivo no debe poder volver a crearse con el mismo nombre desde el buscador inline de Venta/Presupuesto sin antes verificar duplicados (la unicidad de `nombre` ya existe y sigue aplicando también a inactivos).
- Si todos los vendedores están inactivos, el select de Vendedor en Crear Venta/Presupuesto queda vacío (el campo ya es opcional, no bloquea el alta).
- Buscar por nombre en el buscador inline de Venta/Presupuesto no debe ofrecer vendedores inactivos como resultado, para que no se puedan volver a asignar por accidente.
- Desactivar y reactivar repetidamente un mismo vendedor no debe duplicar registros ni afectar las Ventas/Presupuestos ya asociados.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: El sistema DEBE agregar un estado (Activo/Inactivo) a cada Vendedor, con Activo como valor por defecto para vendedores nuevos y para los ya existentes al momento de este cambio.
- **FR-002**: El sistema DEBE permitir a un usuario administrador cambiar el estado de un vendedor entre Activo e Inactivo, en cualquier momento y sin restricción por tener ventas/presupuestos asociados.
- **FR-003**: El sistema DEBE excluir a los vendedores Inactivos de todo select/buscador usado para **asignar** un vendedor en una operación nueva (alta de Venta, alta de Presupuesto, configuración de Tiendanube, configuración de MercadoLibre, "Vendedor por defecto" en Configuración & Ajustes → Ventas).
- **FR-004**: El sistema NO DEBE alterar ni ocultar el vendedor ya asignado a Ventas, Presupuestos o configuraciones existentes por el solo hecho de que ese vendedor pase a Inactivo — el historial se sigue mostrando igual.
- **FR-005**: El sistema DEBE agregar una pantalla/tab "Vendedores" dentro de Configuración & Ajustes con una tabla (listado paginado vía AJAX) que muestre todos los vendedores (activos e inactivos) con su nombre y estado.
- **FR-006**: Desde esa tabla, el sistema DEBE permitir dar de alta, renombrar y cambiar el estado de un vendedor mediante modal/control AJAX, sin recargar la página, con notificación tipo toast de confirmación o error.
- **FR-007**: El sistema DEBE seguir permitiendo el alta/edición inline de vendedores desde los buscadores Select2 de Venta y Presupuesto (comportamiento de la spec 020), y esos vendedores creados inline nacen Activos.
- **FR-008**: El sistema DEBE seguir permitiendo eliminar (borrar) un vendedor como alternativa a desactivarlo, sin cambios sobre esa funcionalidad existente.
- **FR-009**: El sistema DEBE validar que el nombre de un vendedor sea único considerando tanto vendedores activos como inactivos (no se puede reutilizar el nombre de un inactivo para un alta nueva mientras siga existiendo).
- **FR-010**: Si el vendedor configurado como "Vendedor por defecto" (Configuración & Ajustes → Ventas) pasa a Inactivo, el sistema DEBE mostrar un aviso en esa sección de configuración indicando que el vendedor por defecto configurado está inactivo, y DEBE dejar de precargarlo en "Crear Venta" (el campo queda sin precargar).

### Key Entities

- **Vendedor**: catálogo plano existente (tabla `vendedores`, sólo `nombre` hasta hoy). Se le agrega un atributo de estado (Activo/Inactivo) que determina su disponibilidad para nuevas asignaciones, sin afectar las asignaciones ya existentes en Venta, Presupuesto ni configuraciones de integraciones.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Un administrador puede desactivar un vendedor desde la nueva pantalla de gestión en menos de 10 segundos (2 clics: abrir el tab, cambiar el estado).
- **SC-002**: El 100% de los vendedores inactivos deja de aparecer en los selects de asignación de Venta, Presupuesto, Tiendanube y MercadoLibre, verificable inmediatamente después de desactivar.
- **SC-003**: El 100% de las Ventas y Presupuestos ya emitidos con un vendedor que luego se desactiva sigue mostrando ese vendedor sin errores ni datos faltantes.
- **SC-004**: Ningún cambio de estado de vendedor requiere recargar la página en la que se realiza (ni en la nueva pantalla de gestión, ni en el buscador inline de Venta/Presupuesto).

## Assumptions

- Sólo usuarios con rol Admin (mismo criterio que el resto de Configuración & Ajustes, spec 043) pueden acceder al tab Vendedores y cambiar estados.
- El catálogo de Vendedores es chico (decenas de filas, no miles); no se define un límite de rendimiento explícito para la carga de la tabla del nuevo tab, siguiendo el mismo criterio ya aplicado a Depósitos.
- El nuevo tab "Vendedores" es una divergencia deliberada respecto de Contagram real: no hay evidencia en los informes de relevamiento (`docs/informe_contagram_*.md`) de una pantalla dedicada a vendedores en Contagram — hoy Vendedor sólo aparece como dimensión de filtro/informe y como campo de configuración de defaults. Se documenta como tal en `docs/documentacion_principal_crm.md`.
- "No aparecer en los listados" (pedido del cliente) se interpreta como selects de asignación en altas nuevas; los reportes/informes que ya usan Vendedor como dimensión (Ranking de Vendedores, filtros de Informes) siguen incluyendo vendedores inactivos para no perder datos históricos de análisis — no está en el alcance de esta spec ocultarlos ahí.
- La tabla nueva de gestión de Vendedores usa el mismo patrón visual/técnico ya validado en Depósitos (DataTables + modal Bootstrap + AJAX + toasts), por ser el catálogo de Configuración & Ajustes más análogo en complejidad.
