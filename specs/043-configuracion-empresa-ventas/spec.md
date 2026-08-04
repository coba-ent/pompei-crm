# Feature Specification: Reorganización de Configuración & Ajustes (Empresa + acceso Admin + defaults de Ventas)

**Feature Branch**: `043-configuracion-empresa-ventas`

**Created**: 2026-08-04

**Status**: Draft

**Input**: User description: "Reorganizar Configuración & Ajustes: (1) Renombrar 'Mi Perfil' a 'Empresa': mantiene los datos fiscales del emisor y además absorbe la gestión completa de usuarios que hoy vive en 'Usuarios y Permisos' (tabla de usuarios, botón 'Nuevo Usuario', y el acceso a 'Roles y Permisos'). Se elimina la pantalla/ruta/link separado de 'Usuarios y Permisos' del dropdown del sidebar. (2) Acceso exclusivo: tanto 'Empresa' como toda la sección 'Configuración & Ajustes' (Depósitos, Funciones Avanzadas, Mercado Libre, Tiendanube, Facturación Electrónica) quedan restringidos al rol 'Admin' del sistema de roles y permisos existente (Spatie), reemplazando el permiso granular actual (configuracion.usuarios, configuracion.funciones) por ese único gate de rol. (3) El acceso a 'Configuración & Ajustes' se saca del sidebar (deja de ser una sección del menú lateral) y se agrega como ítem en el dropdown de usuario de la topbar (junto con 'Empresa'), visible solo para el rol Admin. (4) Nuevo ítem 'Ventas' dentro de Configuración & Ajustes: pantalla para configurar valores por defecto que se autocompletan al abrir 'Crear Venta': Categoría por defecto, Vendedor por defecto, Lista de Precios por defecto (hoy hardcodeado a 'Principal'), Tipo de Comprobante por defecto (hoy hardcodeado a 'B'), y cantidad de días por defecto para calcular 'Vto. del Cobro' a partir de la fecha de Emisión. Estos valores son configuración global de un solo negocio (single-tenant), no por usuario."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Gestión de usuarios centralizada en "Empresa" (Priority: P1)

Como usuario con rol Admin, quiero gestionar los datos fiscales del negocio y la gestión de usuarios (alta, edición, activar/desactivar, asignación de roles) desde una única pantalla "Empresa", en vez de tener que navegar a una pantalla separada de "Usuarios y Permisos".

**Why this priority**: Es el cambio estructural principal pedido: consolidar dos pantallas relacionadas (datos del emisor + gestión de usuarios) en un solo lugar, y es prerequisito para poder retirar "Usuarios y Permisos" del menú.

**Independent Test**: Entrando como Admin a la pantalla "Empresa", se puede ver la tarjeta de datos fiscales existente y, debajo, la tabla de usuarios con el botón "Nuevo Usuario" y el acceso a "Roles y Permisos", pudiendo dar de alta un usuario y asignarle un rol sin salir de la pantalla.

**Acceptance Scenarios**:

1. **Given** un usuario con rol Admin autenticado, **When** entra a la pantalla "Empresa", **Then** ve la tarjeta de datos fiscales del emisor y, en la misma pantalla, la tabla de usuarios con sus acciones (alta, edición, activar/desactivar) y el acceso a "Roles y Permisos".
2. **Given** la pantalla "Empresa" abierta, **When** el Admin hace clic en "Nuevo Usuario", **Then** se abre el modal de alta de usuario (mismo comportamiento AJAX/modal que tenía "Usuarios y Permisos" hoy) sin recargar la página.
3. **Given** la reorganización aplicada, **When** cualquier usuario navega a la URL que antes correspondía a "Usuarios y Permisos", **Then** ya no existe esa pantalla como entidad separada (la funcionalidad vive dentro de "Empresa").

---

### User Story 2 - Acceso a Empresa y Configuración restringido al rol Admin, movido a la topbar (Priority: P1)

Como negocio single-tenant, quiero que sólo el usuario con rol Admin pueda ver y acceder a "Empresa" y a toda la sección "Configuración & Ajustes" (Depósitos, Funciones Avanzadas, Mercado Libre, Tiendanube, Facturación Electrónica, y el nuevo ítem Ventas), y que ese acceso se ofrezca desde el dropdown de usuario de la topbar en lugar de ocupar espacio permanente en el sidebar.

**Why this priority**: Es un cambio de seguridad/acceso (quién ve qué) y de navegación que condiciona dónde y cómo se llega a todo lo demás en este spec; debe resolverse junto con la User Story 1 para que el resultado sea consistente.

**Independent Test**: Con un usuario sin rol Admin, se verifica que ni el sidebar ni el dropdown de la topbar muestran accesos a "Empresa" ni a "Configuración & Ajustes". Con un usuario Admin, se verifica que el dropdown de la topbar (donde hoy ya vive el link "Mi Perfil") ofrece un ítem "Empresa" y un ítem "Configuración & Ajustes" que lleva a una única pantalla con tabs, y que la sección "Configuración & Ajustes" ya no aparece como bloque en el sidebar para nadie.

**Acceptance Scenarios**:

1. **Given** un usuario autenticado sin rol Admin, **When** abre el sidebar, **Then** no ve ninguna sección "Configuración & Ajustes".
2. **Given** un usuario autenticado sin rol Admin, **When** abre el dropdown de usuario de la topbar, **Then** no ve accesos a "Empresa" ni a "Configuración & Ajustes".
3. **Given** un usuario autenticado con rol Admin, **When** abre el dropdown de usuario de la topbar, **Then** ve un ítem "Empresa" y un único ítem "Configuración & Ajustes" (no un submenú desplegable) que navega a una pantalla propia con las distintas configuraciones organizadas en tabs, cada tab con su ícono.
4. **Given** la pantalla "Configuración & Ajustes" abierta, **When** el Admin hace clic en un tab (por ejemplo "Ventas"), **Then** el contenido de ese tab se muestra sin recargar la página completa (navegación por tabs dentro de la misma vista).
5. **Given** la pantalla "Configuración & Ajustes" recién abierta, **When** carga por primera vez, **Then** el tab activo por defecto es "Funciones Avanzadas".
6. **Given** el tab "Funciones Avanzadas" abierto, **When** el Admin activa (Sí) o desactiva (No) una función que tiene pantalla propia dentro de Configuración & Ajustes (Depósitos, Mercado Libre, Tiendanube, Facturación Electrónica), **Then** el tab correspondiente a esa función pasa a estar disponible o dejar de estarlo en la misma pantalla, acorde a ese estado (igual criterio de activación que ya usa "Funciones Avanzadas" hoy).
5. **Given** un usuario autenticado con rol Admin, **When** intenta acceder por URL directa a la pantalla "Configuración & Ajustes", **Then** el acceso es permitido y la pantalla carga en su primer tab por defecto (sin depender de un fragmento `#` en la URL).
6. **Given** un usuario autenticado sin rol Admin, **When** intenta acceder por URL directa a "Configuración & Ajustes" o a "Empresa", **Then** el acceso es rechazado (igual criterio que hoy aplica el sistema para pantallas sin permiso).

---

### User Story 3 - Configurar valores por defecto de "Crear Venta" (Priority: P2)

Como usuario con rol Admin, quiero definir en Configuración & Ajustes > Ventas los valores que se autocompletan al abrir el formulario de "Crear Venta" (Categoría, Vendedor, Lista de Precios, Tipo de Comprobante, y días por defecto hasta el "Vto. del Cobro"), para no tener que elegirlos manualmente en cada venta cuando casi siempre son los mismos.

**Why this priority**: Mejora de productividad sobre un flujo ya existente (Crear Venta); no bloquea ni depende estrictamente de las User Stories 1 y 2, pero comparte la misma ubicación de menú (Configuración & Ajustes) y el mismo gate de acceso Admin.

**Independent Test**: Configurando una Categoría, un Vendedor, una Lista de Precios y un Tipo de Comprobante por defecto, y un número de días para el vencimiento del cobro, se abre "Crear Venta" y se verifica que esos campos aparecen preseleccionados/precalculados según lo configurado, sin afectar ventas ya creadas.

**Acceptance Scenarios**:

1. **Given** un Admin configura Categoría=X, Vendedor=Y, Lista de Precios=Z, Tipo de Comprobante=A y Días de Vto. de Cobro=15 en Configuración & Ajustes > Ventas, **When** cualquier usuario abre "Crear Venta", **Then** el formulario carga con Categoría=X, Vendedor=Y, Lista de Precios=Z y Tipo de Comprobante=A preseleccionados, y "Vto. del Cobro" precalculado como fecha de Emisión + 15 días.
2. **Given** no se configuró ningún valor por defecto todavía, **When** se abre "Crear Venta", **Then** el formulario se comporta como hoy (Lista de Precios en "Principal", Tipo de Comprobante en "B", Categoría y Vendedor vacíos, Vto. del Cobro vacío).
3. **Given** un valor configurado como default (por ejemplo una Categoría) es luego eliminado del catálogo, **When** se abre "Crear Venta", **Then** el sistema no rompe: ese campo queda sin preselección (se comporta como si no hubiera default cargado para ese campo) en vez de mostrar un error.
4. **Given** una Venta se abre en modo edición (no alta) o viene de un Presupuesto convertido, **When** se carga el formulario, **Then** los valores por defecto de Configuración NO sobrescriben los valores ya existentes de esa venta/presupuesto de origen.
5. **Given** el Admin cambia el valor por defecto de Días de Vto. de Cobro después de haber creado ventas previas, **When** se abren ventas ya creadas anteriormente, **Then** sus fechas ya guardadas no se recalculan ni se ven afectadas por el nuevo default (el default sólo aplica a ventas nuevas).

### Edge Cases

- Si el rol "Admin" no existe todavía en el sistema o el usuario actual no tiene ningún rol asignado, no debe quedar nadie con acceso a "Empresa" ni a "Configuración & Ajustes" hasta que se le asigne explícitamente ese rol a al menos un usuario.
- Si se intenta eliminar o desactivar al único usuario con rol Admin, el sistema debe evitarlo (debe existir siempre al menos un Admin), igual que ya aplica hoy la regla de no poder desactivarse/eliminarse a uno mismo si es el único usuario activo.
- Los días configurados para "Vto. del Cobro" deben ser un entero no negativo; 0 significa que Vto. del Cobro por defecto es la misma fecha que Emisión.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: La pantalla hoy llamada "Mi Perfil" se renombra a "Empresa" en todos los lugares donde aparece en la interfaz (título de pantalla, link del dropdown de la topbar). Se conserva el mismo nombre de ruta interno (`configuracion.mi-perfil.index`) para no romper accesos ya guardados; sólo cambia el rótulo visible.
- **FR-002**: La pantalla "Empresa" debe incluir, además de la tarjeta de datos fiscales ya existente, la tabla de usuarios con sus columnas y acciones actuales (Nombre, Email, Roles, Estado, Acciones) y el botón "Nuevo Usuario", igual funcionalidad que la pantalla "Usuarios y Permisos" tenía. El link "Roles y Permisos" se mueve junto con esa tabla (a la cabecera de la sección de usuarios dentro de "Empresa") pero sigue apuntando a su pantalla propia ya existente — "Roles y Permisos" **no** se fusiona su contenido dentro de "Empresa", sólo se reubica el acceso a ella.
- **FR-003**: La pantalla y ruta propias de listado de "Usuarios y Permisos" (`configuracion.usuarios.index`) se eliminan; el link correspondiente se retira del sidebar. Acceder a esa URL eliminada devuelve un 404 estándar (no se mantiene un redirect). Las rutas AJAX de usuarios (alta, edición, datos de tabla) se conservan sin cambios, consumidas ahora desde "Empresa".
- **FR-004**: El acceso a la pantalla "Empresa" y a toda la sección "Configuración & Ajustes" (Depósitos, Funciones Avanzadas, Mercado Libre, Tiendanube, Facturación Electrónica, Ventas) se restringe a los usuarios que tengan asignado el rol "Admin" del sistema de roles y permisos existente.
- **FR-005**: Los permisos granulares usados hoy para gatear estas pantallas (`configuracion.usuarios`, `configuracion.funciones`, y cualquier otro permiso específico de esta sección) se reemplazan por una única verificación de rol "Admin". Los usuarios sin ese rol no pueden ver ni acceder (incluso por URL directa) a ninguna pantalla de "Empresa" ni de "Configuración & Ajustes".
- **FR-006**: La sección "Configuración & Ajustes" se retira del sidebar como bloque de menú lateral (para todos los usuarios).
- **FR-007**: El dropdown de usuario de la topbar (donde hoy vive el link "Mi Perfil") pasa a mostrar, únicamente a los usuarios con rol Admin, un ítem "Empresa" y un único ítem "Configuración & Ajustes" (un solo link, no un submenú desplegable) que navega a una única pantalla propia. Esa pantalla organiza Funciones Avanzadas, Depósitos, Mercado Libre, Tiendanube, Facturación Electrónica y Ventas como tabs dentro de la misma vista (navegación por tabs client-side, sin fragmento `#` en la URL y sin crear links de menú adicionales para cada tab), cada tab identificado con un ícono.
- **FR-007a**: El tab activo por defecto al entrar o recargar la pantalla "Configuración & Ajustes" es siempre "Funciones Avanzadas" (no se persiste el último tab visitado entre cargas de página). Desde ese tab, el Admin activa o desactiva (toggle Sí/No, comportamiento ya existente) cada función; los tabs correspondientes a funciones con pantalla propia (Depósitos, Mercado Libre, Tiendanube, Facturación Electrónica) sólo están disponibles/visibles cuando esa función está activada — cuando no lo está, ese tab directamente no aparece en la lista de tabs (no se muestra deshabilitado). El tab "Ventas" no depende de ningún toggle de Funciones Avanzadas: está siempre disponible. El listado completo de tabs posibles es: Funciones Avanzadas, Depósitos, Mercado Libre, Tiendanube, Facturación Electrónica, Ventas — no hay otros.
- **FR-007b**: Si el Admin desactiva una función mientras su tab está abierto y visible en pantalla, ese tab desaparece de la lista de tabs disponibles inmediatamente (sin necesidad de recargar la página) y la vista pasa a mostrar el tab "Funciones Avanzadas".
- **FR-008**: Se agrega un nuevo tab "Ventas" dentro de la pantalla "Configuración & Ajustes", con un formulario para definir valores globales por defecto (configuración única del negocio, no por usuario): Categoría de venta por defecto, Vendedor por defecto, Lista de Precios por defecto, Tipo de Comprobante por defecto, y cantidad de días por defecto entre la fecha de Emisión y la fecha de "Vto. del Cobro".
- **FR-009**: Los selects de Categoría, Vendedor, Lista de Precios y Tipo de Comprobante en la pantalla de configuración de Ventas deben ofrecer, además de un valor concreto, la opción de no definir default (dejar ese campo sin preselección en "Crear Venta").
- **FR-010**: Al abrir "Crear Venta" para una venta nueva (no edición, no conversión desde Presupuesto), el formulario debe precargar Categoría, Vendedor, Lista de Precios y Tipo de Comprobante con los valores configurados por defecto (cuando existan), y calcular "Vto. del Cobro" como fecha de Emisión (hoy) + días configurados (cuando exista ese default).
- **FR-011**: Si no hay ningún valor por defecto configurado para un campo dado, "Crear Venta" debe comportarse igual que hoy para ese campo (Lista de Precios "Principal", Tipo de Comprobante "B", Categoría y Vendedor sin preselección, Vto. del Cobro vacío). El default global de Tipo de Comprobante es sólo una preselección inicial: no reemplaza ni valida contra la derivación fiscal existente por condición de IVA (Principio III de la constitución) — esa regla de negocio ya existente sigue aplicando igual que hoy sobre el valor que quede seleccionado al momento de guardar la Venta.
- **FR-012**: Editar una Venta existente o convertir un Presupuesto en Venta no debe verse afectado por estos valores por defecto: se siguen usando los valores ya existentes de esa venta o presupuesto de origen.
- **FR-013**: Si un valor configurado como default (Categoría, Vendedor o Lista de Precios) deja de existir en su catálogo (fue eliminado), "Crear Venta" debe cargar sin ese default en vez de fallar.
- **FR-014**: Cambiar los valores por defecto de Ventas no debe alterar ventas ya creadas anteriormente.

### Key Entities

- **ConfiguracionVentasDefault**: Configuración global única del negocio (single-tenant, un solo registro) que guarda las referencias a Categoría de venta por defecto, Vendedor por defecto, Lista de Precios por defecto, Tipo de Comprobante por defecto y días por defecto para Vto. del Cobro.
- **Rol "Admin"**: Rol del sistema de roles y permisos existente que pasa a ser el único gate de acceso a "Empresa" y "Configuración & Ajustes" en su totalidad.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Un usuario con rol Admin puede dar de alta un usuario nuevo y asignarle un rol sin salir de la pantalla "Empresa" (sin navegar a ninguna otra pantalla), en menos de 1 minuto.
- **SC-002**: El 100% de los usuarios sin rol Admin no encuentran ningún acceso visible (sidebar ni topbar) ni pueden entrar por URL directa a "Empresa" ni a ninguna pantalla de "Configuración & Ajustes".
- **SC-003**: Configurando los 5 valores por defecto de Ventas una sola vez, el usuario que crea ventas deja de tener que completar manualmente esos campos en el 100% de las ventas nuevas subsiguientes (salvo que quiera cambiarlos puntualmente).
- **SC-004**: Ninguna venta o presupuesto ya existente cambia sus datos como consecuencia de configurar o modificar los valores por defecto de Ventas.

## Assumptions

- El rol "Admin" ya existe en el sistema de roles y permisos (seed `RolSeeder`, con todos los permisos asignados) y ya hay al menos un usuario con ese rol (seed `UsuarioAdminSeeder`); no hace falta crearlo, sólo usarlo como gate único de acceso.
- "Lista de Precios" y "Tipo de Comprobante" configurables como default en Ventas reemplazan sus valores hardcodeados actuales ("Principal" y "B" respectivamente) sólo como fallback: si no se configura nada, el comportamiento actual se mantiene igual (ver FR-011).
- El link "Mi Perfil" que hoy ya existe en el dropdown de usuario de la topbar es el mismo punto de entrada que se renombra a "Empresa" y junto al cual se agrega "Configuración & Ajustes"; no se crea un dropdown nuevo.
- Fuera de alcance: cambiar el modelo de permisos granulares de otras secciones del CRM (Ingresos, Egresos, Tesorería, Informes, etc.) — este spec sólo afecta el gate de acceso de "Empresa" y "Configuración & Ajustes".
- Fuera de alcance: agregar valores por defecto configurables para "Etiquetas", "Nota para el Cliente", "Nota interna", "Formas de Pago" o "Métodos de Envío" del formulario de Ventas — sólo se cubren los 5 campos explícitamente pedidos (Categoría, Vendedor, Lista de Precios, Tipo de Comprobante, días de Vto. de Cobro).
- Fuera de alcance: requisitos específicos de accesibilidad (navegación por teclado en los tabs) más allá de los ya provistos por los componentes estándar de Bootstrap `nav-tabs` del template NexaDash ya en uso en el resto del CRM.
- Fuera de alcance: un registro de auditoría (quién y cuándo cambió los defaults de Ventas o activó/desactivó una Función Avanzada) — se mantiene el mismo nivel de trazabilidad que ya tiene hoy "Funciones Avanzadas" (`actualizada_por`/`actualizada_en`), sin agregar auditoría nueva para la tabla `configuracion_ventas`.
