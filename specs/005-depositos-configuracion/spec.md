# Feature Specification: Gestión de Depósitos

**Feature Branch**: `005-depositos-configuracion`

**Created**: 2026-07-24

**Status**: Draft

**Input**: User description: "Gestión de Depósitos (Configuración & Ajustes → Depósitos). Fuente de verdad: docs/informe_contagram_funciones_avanzadas.md §10, con capturas 117-119. Alcance acotado a la gestión de Depósitos en sí (CRUD: alta, renombrar, activar/desactivar, eliminar) — el resto de 'Funciones Avanzadas' (Facturación Electrónica, Mercado Libre, Tiendanube, Reportes por email, Abonos, IA, Retenciones, Ventas sin stock, Lector de código de barras) queda fuera de alcance. Modal 'Configuración de Depósitos' con lista editable inline (nombre, activo, editar, eliminar), '+ Agregar Depósito', Cancelar/Guardar. Reutiliza la tabla `depositos` y el modelo `Deposito` ya existentes (hoy gestionados sólo por seeder/DB directa). El filtro 'Depósito' ya existente en Productos sigue funcionando igual. No se elimina físicamente un depósito con stock o movimientos asociados. Divergencia documentada: no se reproduce la advertencia de 'operación que puede tardar minutos' de Contagram real, porque este sistema ya soporta multi-depósito desde el modelo de datos original (no hay migración de single a multi-depósito que ejecutar)."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Administrar el catálogo de Depósitos (Priority: P1)

Como usuario del negocio, quiero crear, renombrar, activar/desactivar y eliminar depósitos desde una
pantalla propia, para poder organizar dónde se guarda el stock de mis productos sin depender de que
alguien edite la base de datos directamente.

**Why this priority**: Es toda la feature — hoy el alta/baja de depósitos se hace vía seeder/DB
directa (ver `docs/documentacion_principal_crm.md` §2.2), lo cual no es viable para el uso real del
negocio. No hay una segunda historia: esta es la única entregable.

**Independent Test**: Se puede probar completamente entrando a Configuración & Ajustes → Depósitos,
agregando un depósito nuevo, renombrándolo, desactivándolo, reactivándolo, y eliminando uno sin
stock — todo sin depender de ningún otro módulo (Productos ya existe y sólo consume el resultado,
no hace falta tocarlo).

**Acceptance Scenarios**:

1. **Given** la pantalla de Depósitos, **When** el usuario hace click en "+ Agregar Depósito",
   **Then** aparece una fila nueva con nombre editable inline (prellenado "Depósito N", donde N es
   correlativo), checkbox de activo marcado por defecto, ícono de editar y de eliminar.
2. **Given** una fila de depósito recién agregada o existente, **When** el usuario edita el nombre y
   hace click en "Guardar", **Then** el depósito se crea o actualiza persistiendo el nuevo nombre,
   sin recargar la página, y un toast confirma el resultado.
3. **Given** un depósito activo, **When** el usuario desmarca su checkbox de activo y guarda,
   **Then** el depósito pasa a inactivo: deja de aparecer como opción en el filtro "Depósito" y en
   el selector de depósito de los ajustes de stock de Productos, pero el stock/histórico ya cargado
   en ese depósito no se pierde ni se modifica.
4. **Given** un depósito sin stock cargado y sin movimientos de stock asociados, **When** el usuario
   hace click en el ícono de eliminar y confirma, **Then** el depósito se elimina físicamente de la
   lista.
5. **Given** un depósito con stock cargado (`cantidad != 0` en algún producto) o con movimientos de
   stock asociados, **When** el usuario intenta eliminarlo, **Then** el sistema rechaza la
   eliminación física con un mensaje explicando que tiene stock/movimientos asociados, y sugiere
   desactivarlo en su lugar.
6. **Given** la pantalla de Depósitos abierta, **When** el usuario hace click en "Cancelar",
   **Then** se descartan los cambios no guardados (nombres editados, filas agregadas, checkboxes
   tocados) y la pantalla vuelve a mostrar el estado persistido la última vez que se guardó.

---

### Edge Cases

- ¿Qué pasa si se intenta guardar un depósito con el nombre vacío? → Se rechaza esa fila con un
  mensaje de validación (nombre requerido), sin perder los cambios de las demás filas del mismo
  guardado.
- ¿Qué pasa si dos depósitos terminan con el mismo nombre? → Se permite (Contagram real no muestra
  evidencia de que el nombre deba ser único); no es una restricción de este spec.
- ¿Qué pasa si se desactiva el único depósito activo del sistema? → Se permite (no hay una regla de
  "al menos un depósito activo" relevada), pero el usuario pierde temporalmente la posibilidad de
  cargar nuevo stock hasta reactivar alguno — se documenta como comportamiento esperado, no como bug.
- ¿Qué pasa con productos/movimientos que ya referencian un depósito inactivado? → Siguen mostrando
  su nombre con normalidad en el historial (Informe de Stock, listado de Productos); un depósito
  inactivo sólo deja de ofrecerse para **nuevas** operaciones, igual que clientes/proveedores/
  productos inactivos.
- ¿Qué pasa si se elimina (icono tacho) una fila recién agregada que todavía no se guardó? → Se
  quita de la lista en el momento, sin necesidad de confirmar nada contra el servidor (no existe
  todavía como registro persistido).

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: El sistema DEBE proveer una pantalla "Depósitos" accesible desde Configuración &
  Ajustes, no existente hoy en el menú lateral.
- **FR-002**: La pantalla DEBE mostrar la lista de depósitos existentes, cada uno con: nombre
  editable inline, checkbox de activo, ícono de editar y de eliminar — fiel a la estructura relevada
  en `docs/informe_contagram_funciones_avanzadas.md` §10 y las capturas 117/118.
- **FR-003**: El sistema DEBE permitir agregar depósitos nuevos ("+ Agregar Depósito"), con un
  nombre por defecto sugerido ("Depósito N") que el usuario puede sobrescribir antes de guardar.
- **FR-004**: El sistema DEBE permitir activar/desactivar cada depósito de forma independiente; un
  depósito inactivo deja de ofrecerse en los selectores de depósito de Productos (filtro y ajuste de
  stock) para nuevas operaciones, sin afectar el stock/historial ya existente en ese depósito.
- **FR-005**: El sistema NO DEBE permitir eliminar físicamente un depósito que tenga stock cargado
  (`stocks.cantidad != 0` para algún producto) o al menos un movimiento de stock asociado
  (`movimientos_stock`) — mismo patrón ya vigente para Cliente/Proveedor/Producto ("no eliminar con
  operaciones asociadas"). En ese caso sólo permite inactivarlo.
- **FR-006**: Todas las altas, renombrados, cambios de estado y eliminaciones DEBEN aplicarse por
  AJAX sin recargar la página, con notificación de resultado vía toast (regla de diseño obligatoria
  del proyecto).
- **FR-007**: El filtro "Depósito" y el selector de depósito de los ajustes de stock, ya existentes
  en Productos, DEBEN seguir poblándose únicamente con depósitos activos, sin requerir cambios
  adicionales en `ProductoController` más allá de que la fuente de esos depósitos ahora se gestione
  desde esta pantalla en vez de por seeder/DB directa.
- **FR-008**: El nombre del depósito DEBE ser obligatorio; el sistema DEBE rechazar el guardado de
  una fila con nombre vacío sin descartar los cambios válidos de las demás filas del mismo guardado.

### Key Entities *(include if feature involves data)*

- **Depósito** (`Deposito`, entidad ya existente — sin cambios de esquema): `id`, `nombre`,
  `activo`. Se relaciona con `Stock` (1..N, cantidad actual por producto/variante) y
  `MovimientoStock` (1..N, histórico), ambas ya existentes. Esta feature agrega la primera UI de
  gestión sobre una tabla que hoy sólo se puebla por seeder.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Un usuario puede crear un depósito nuevo y verlo disponible en el filtro "Depósito" de
  Productos en menos de 10 segundos, sin recargar ninguna página ni tocar la base de datos
  directamente.
- **SC-002**: El 100% de los intentos de eliminar un depósito con stock o movimientos asociados son
  rechazados con un mensaje claro, y el 100% de los depósitos sin esas asociaciones se eliminan
  correctamente.
- **SC-003**: La estructura de la pantalla (lista editable inline, `+ Agregar Depósito`, checkbox de
  activo con su tooltip, Cancelar/Guardar) coincide con lo relevado en
  `docs/informe_contagram_funciones_avanzadas.md` §10 y las capturas 117/118, salvo la divergencia
  documentada explícitamente en Assumptions (advertencia de operación larga).

## Assumptions

- **Divergencia documentada y aceptada**: Contagram real muestra una advertencia "la operación puede
  tardar algunos minutos" al crear un depósito, porque internamente migra el stock de un esquema
  single-depósito a uno multi-depósito. Este sistema fue diseñado multi-depósito desde el modelo de
  datos original (`docs/modelo_datos.md`, tablas `stocks`/`movimientos_stock` con `deposito_id` desde
  el inicio) — no existe esa migración que ejecutar, por lo que agregar un depósito es una operación
  instantánea. No se reproduce esa advertencia de forma literal para no mentirle al usuario sobre un
  tiempo de espera que no existe.
- Esta feature **no** construye el resto de la pantalla "Funciones Avanzadas" de Contagram
  (Facturación Electrónica, Mercado Libre, Tiendanube, Reportes por email, Abonos, IA, Retenciones,
  Ventas sin stock, Lector de código de barras) — quedan fuera de alcance porque son funciones de
  un sistema de planes/upsell que no aplica a este CRM single-tenant, o dependen de módulos no
  construidos todavía (Ventas, integraciones externas).
- El botón "Ver mis Productos" que Contagram real muestra junto a "Configurar Depósitos" es un
  atajo de navegación (link directo a Productos) sin lógica propia — se incluye si el plan técnico
  lo considera trivial, pero no es un requisito bloqueante de esta spec.
- La tabla `depositos` y el modelo `Deposito` ya existen (creados en `002-productos`) y no requieren
  ninguna migración nueva de esquema — sólo se agrega la UI de gestión que faltaba.
- Esta feature reutiliza el patrón ya vigente en el proyecto: modal Bootstrap por AJAX sin recargar,
  toasts de resultado (reglas de diseño obligatorias de `CLAUDE.md`). Al ser un catálogo chico
  (pocos depósitos esperados), no requiere DataTable server-side ni paginado — mismo criterio ya
  usado para los catálogos de Listas de Precio y Tipos de Producto dentro de Productos.
