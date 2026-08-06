# Feature Specification: Selector de Depósito y Número de Comprobante Real en Ventas y Compras

**Feature Branch**: `049-deposito-ventas-compras`

**Created**: 2026-08-06

**Status**: Draft

**Input**: User description: "Selector de Depósito en Ventas y Compras: agregar un select de Depósito (Select2) al formulario 'Nueva Venta' y 'Nueva Compra' para indicar a qué depósito entra/sale el stock de esa operación (una operación = un depósito, sin granularidad por ítem). El movimiento de stock que hoy genera cada Venta/Compra usa siempre Deposito::porDefecto() (el de menor id) sin poder elegirlo. Agregar configuración de valores por defecto: para Ventas, sumar el campo Depósito a configuracion_ventas (spec 043); para Compras, crear configuracion_compras análogo, con al menos el Depósito por defecto. Cambio sólo hacia adelante. Integraciones ML/Tiendanube no se tocan. — Ampliación (misma sesión): en Compras, el N° de comprobante (`compras.nro_comprobante`) hoy se autogenera siempre con un correlativo interno ficticio (`Compra::siguienteNroComprobante()`, punto de venta fijo '0001'), sin relación con la factura real que emitió el Proveedor — pese a que el backend ya valida por separado `punto_venta_proveedor`/`numero_comprobante_proveedor`/`cae_proveedor` sin tener ningún input en el formulario. Se agrega: (1) el N° de comprobante real del Proveedor pasa a ser un campo editable desde 'Nueva Compra'/'Editar Compra'; (2) si el usuario no lo completa, el formulario le sugiere como valor precargado el correlativo interno que hoy se autogenera, pero no permite guardar la Compra sin que el campo quede completo (el campo es obligatorio, el correlativo sólo es el valor de partida sugerido)."

## Clarifications

### Session 2026-08-06

- Q: ¿Presupuestos (que se convierten en Venta con un clic y hoy no mueven stock) lleva también el selector de Depósito, o queda fuera de alcance y el Depósito se elige recién al convertir a Venta? → A: Presupuestos queda fuera de alcance — no mueve stock hoy; el selector de Depósito aplica sólo a Venta y Compra, y se completa al convertir un Presupuesto en Venta.
- Q: ¿El N° de comprobante real del Proveedor en Compras debe poder cargarse/editarse desde "Nueva Compra"/"Editar Compra"? → A: Sí, editable desde ambos formularios (alta y edición), no sólo desde un flujo aparte de Facturación Electrónica.
- Q: ¿Qué pasa si el usuario no completa el N° de comprobante real? → A: El formulario le sugiere como valor precargado el correlativo interno que hoy se autogenera (`Compra::siguienteNroComprobante()`), pero el campo queda obligatorio — no se puede guardar la Compra sin un número (real o el sugerido aceptado tal cual).

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Elegir el depósito al cargar una Venta o Compra (Priority: P1)

Como usuario que carga una Venta o una Compra, quiero poder elegir de qué depósito sale (Venta) o a qué depósito entra (Compra) la mercadería de esa operación, para que el stock quede correctamente distribuido cuando el negocio opera con más de un depósito activo.

**Why this priority**: Es el corazón del pedido — hoy toda Venta/Compra manual mueve stock siempre contra el mismo depósito implícito (`Deposito::porDefecto()`), sin posibilidad de elegir otro, lo cual ya genera inconsistencias detectadas (el filtro por Depósito del listado de Ventas no "cuadra" porque las Ventas cargadas nunca fueron realmente asignadas a otro depósito por el usuario).

**Independent Test**: Con al menos dos depósitos activos, crear una Venta eligiendo un depósito distinto del que sería el "por defecto" (menor id) y verificar que el movimiento de stock generado (`movimientos_stock`) y el descuento de stock (`stocks`) impactan sobre el depósito elegido, no sobre el de menor id. Repetir el mismo caso para Compra (suma en vez de resta).

**Acceptance Scenarios**:

1. **Given** existen 2+ depósitos activos y un producto con stock en ambos, **When** el usuario crea una Venta seleccionando el Depósito B (no el de menor id) y confirma, **Then** el stock del producto se descuenta del Depósito B y el movimiento de stock queda registrado con `deposito_id` = B.
2. **Given** existen 2+ depósitos activos, **When** el usuario crea una Compra seleccionando el Depósito B y confirma, **Then** el stock del producto se suma al Depósito B y el movimiento de stock queda registrado con `deposito_id` = B.
3. **Given** una Venta o Compra ya guardada con Depósito A, **When** el usuario la edita y cambia el Depósito a B, **Then** el sistema reintegra/retira el stock de A según corresponda y aplica el movimiento contra B (mismo patrón de "reintegra el anterior, aplica el nuevo" ya usado hoy para cantidades).
4. **Given** una Venta o Compra ya guardada, **When** el usuario la elimina, **Then** el stock se reintegra/retira sobre el depósito que esa operación tenía asignado (no sobre el depósito por defecto actual, que puede haber cambiado desde entonces).

---

### User Story 2 - Configurar el depósito por defecto de Ventas y de Compras (Priority: P2)

Como administrador, quiero configurar en Configuración & Ajustes cuál es el depósito que se precarga al abrir "Nueva Venta" y, por separado, cuál se precarga al abrir "Nueva Compra", para no tener que elegirlo manualmente en cada operación cuando el negocio opera mayormente contra un mismo depósito.

**Why this priority**: Depende de que exista el selector (User Story 1) para tener sentido; es la comodidad de no repetir la selección en cada alta, pero el sistema ya es funcionalmente completo (aunque menos cómodo) sin esto — de ahí P2.

**Independent Test**: Configurar en el tab "Ventas" de Configuración & Ajustes (que ya incluye, en su misma pantalla, una sección "Compras" con los defaults de Categoría/Tipo de Comprobante/Vto. de Pago de Compra — spec 043/044) un Depósito por defecto distinto en la sección Ventas y verificar que "Nueva Venta" abre con ese depósito preseleccionado. Repetir en la sección Compras de esa misma pantalla contra "Nueva Compra".

**Acceptance Scenarios**:

1. **Given** el admin entra al tab "Ventas" de Configuración & Ajustes, sección "Ventas", **When** selecciona un Depósito en el nuevo campo y guarda, **Then** el valor persiste y el próximo "Nueva Venta" lo trae preseleccionado.
2. **Given** el admin entra al mismo tab, sección "Compras" (ya existente, junto a Categoría de Compra/Tipo de Comprobante/Vto. de Pago por defecto), **When** selecciona un Depósito por defecto y guarda, **Then** el valor persiste y el próximo "Nueva Compra" lo trae preseleccionado.
3. **Given** no se configuró ningún Depósito por defecto para Ventas o para Compras, **When** el usuario abre "Nueva Venta" o "Nueva Compra", **Then** el selector de Depósito precarga el mismo `Deposito::porDefecto()` (menor id entre activos) que usa hoy el sistema, preservando el comportamiento actual como fallback.
4. **Given** un depósito configurado como "por defecto" en Ventas o Compras es luego inactivado desde Configuración & Ajustes → Depósitos, **When** el admin vuelve al tab correspondiente, **Then** el sistema indica que el default configurado ya no está activo y vuelve a aplicar el fallback (`Deposito::porDefecto()`) hasta que se configure uno activo.

---

### User Story 3 - Cargar el N° de comprobante real del Proveedor en una Compra (Priority: P1)

Como usuario que carga una Compra, quiero poder escribir el número de comprobante real que emitió el Proveedor (punto de venta + número, y el tipo A/B/C), en vez de que el sistema me imponga siempre un correlativo interno ficticio, para que el registro de la Compra refleje el comprobante real y sirva para conciliar contra lo que el proveedor efectivamente entregó.

**Why this priority**: Mismo nivel que US1 — es un dato central del comprobante de Compra que hoy es sistemáticamente incorrecto (un correlativo interno en vez del número real), afecta directamente la utilidad del "Informe a tu Contador" (IVA Compras) y cualquier conciliación contra el proveedor. No depende de US1/US2 (Depósito) ni ellas dependen de esto — ambas mejoras son independientes entre sí, conviven en el mismo formulario de Compra.

**Independent Test**: Crear una Compra sin tocar el campo de número (queda con el valor sugerido, el correlativo) y verificar que se guarda igual que hoy; crear otra Compra editando ese campo a un número real distinto y verificar que `compras.nro_comprobante` persiste exactamente lo que el usuario escribió, no el correlativo. Intentar guardar con el campo vacío y verificar que el formulario lo bloquea.

**Acceptance Scenarios**:

1. **Given** el usuario abre "Nueva Compra", **When** el formulario carga, **Then** el campo de N° de comprobante aparece precargado con el valor que hoy calcula `Compra::siguienteNroComprobante()` (el correlativo interno), editable.
2. **Given** el usuario deja el campo con el valor sugerido (no lo toca), **When** guarda la Compra, **Then** se persiste ese valor sugerido — mismo comportamiento observable que tiene el sistema hoy para quien no necesita cargar el número real.
3. **Given** el usuario borra el valor sugerido y escribe el número real de la factura del Proveedor (punto de venta + número), **When** guarda la Compra, **Then** `compras.nro_comprobante` persiste exactamente el valor cargado por el usuario, no el correlativo.
4. **Given** el usuario borra completamente el campo y lo deja vacío, **When** intenta guardar la Compra, **Then** el sistema bloquea el guardado con un mensaje de validación (el campo es obligatorio, sin excepción).
5. **Given** una Compra ya guardada, **When** el usuario la edita, **Then** el campo de N° de comprobante muestra el valor ya persistido (real o sugerido-aceptado), editable con las mismas reglas que en el alta.

---

### Edge Cases

- ¿Qué pasa si el usuario deja el selector de Depósito vacío al guardar una Venta/Compra? El campo es obligatorio (toda operación debe tener un depósito), así que el formulario debe bloquear el guardado con un mensaje de validación, igual que otros campos obligatorios del formulario.
- ¿Qué pasa si el Depósito elegido se inactiva entre que el usuario abre el formulario y hace submit (otra pestaña, otro usuario)? El guardado MUST rechazarse con el mismo mensaje de validación que un Depósito inexistente (la regla de validación no distingue "no existe" de "existe pero inactivo" — ver FR-001a).
- ¿Qué pasa si no existe ningún depósito activo en el sistema al abrir "Nueva Venta"/"Nueva Compra"? El formulario MUST bloquear la carga de la operación (no se puede mover stock sin depósito) con un aviso claro de que hace falta al menos un depósito activo, remitiendo a Configuración & Ajustes → Depósitos — mismo problema de fondo que ya existe hoy si `Deposito::porDefecto()` no encuentra ninguno (`RuntimeException` en `StockDeVenta`/`StockDeCompra`), pero detectado antes, en el formulario, en vez de fallar recién al guardar.
- ¿Qué pasa si sólo existe un depósito activo en el sistema? El selector igual se muestra (consistencia de UI) pero con una única opción preseleccionada; no cambia el comportamiento actual para negocios de un solo depósito.
- ¿El `deposito_id` de una Nota de Crédito/Débito de Compra debe coincidir con el `deposito_id` de la Compra "raíz"? No — son independientes, igual que hoy (la NC/ND ya tiene su propio selector desacoplado del resto de la Compra); este feature no agrega ninguna validación de coincidencia entre ambos.
- ¿Qué pasa con Ventas/Compras ya cargadas antes de este cambio? No se reescriben ni se les asigna retroactivamente un `deposito_id` explícito distinto del que ya tenían implícito; sus movimientos de stock históricos quedan tal cual están.
- ¿Qué pasa con las Notas de Crédito/Débito de Compra, que ya tienen su propio selector de depósito en su modal? No se tocan — siguen funcionando igual, de forma independiente del depósito de la Compra "raíz".
- ¿Qué pasa con Presupuestos? Quedan fuera de alcance de este feature — no mueven stock hoy, así que no llevan selector de Depósito; el Depósito se elige recién en el paso "Convertir a Venta" (ya dentro del formulario/flujo de Venta).
- ¿Qué pasa con Ventas/Compras creadas automáticamente por Mercado Libre o Tiendanube? Siguen usando el `deposito_id` configurado en `ml_configuracion`/`tn_configuracion` (o el fallback por defecto general), sin selector manual — el nuevo campo del formulario "Nueva Venta"/"Nueva Compra" sólo aplica a la carga manual desde el CRM.
- ¿Qué pasa si se intenta eliminar (baja física) un depósito que está configurado como "por defecto" en Ventas o Compras? Debe seguir aplicando la regla ya existente de `Deposito::tieneOperaciones()` — si tiene stock o movimientos no se puede borrar físicamente, sólo inactivar; y al inactivarlo, ver edge case de configuración arriba.
- ¿El N° de comprobante editable de Compra valida que sea único o que no se repita entre Compras? No — el spec no pide esa validación; hoy tampoco existe (el correlativo interno nunca colisiona porque es autogenerado, pero un número real cargado a mano en teoría podría repetirse entre dos Compras de proveedores distintos con la misma numeración de punto de venta — se acepta como límite conocido, no se agrega validación de unicidad en este feature).
- ¿El campo N° de comprobante editable reemplaza a `tipo_comprobante` (A/B/C) o son cosas distintas? Son campos distintos que conviven — `tipo_comprobante` (ya existente, select A/B/C) sigue igual; el N° de comprobante es el punto de venta + número, ahora editable, del comprobante de ese tipo.
- ¿Este cambio afecta a `punto_venta_proveedor`/`numero_comprobante_proveedor`/`cae_proveedor` (campos ya validados en el backend para el flujo de Facturación Electrónica con CAE del Proveedor)? No se eliminan ni se renombran — siguen existiendo para ese flujo (gated por la función avanzada "Facturación Electrónica"). El campo editable de N° de comprobante de este feature es independiente y está disponible siempre (no depende de esa función avanzada); ver Assumptions para cómo conviven ambos.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: El formulario "Nueva Venta" (`/sales/new` o equivalente) MUST incluir un campo Depósito (select con buscador, catálogo de depósitos activos) obligatorio, ubicado en el bloque de datos generales de la operación (no por ítem, y sin distinción por tipo de ítem — todos los ítems de una Venta/Compra comparten el mismo Depósito de la operación, muevan o no stock).
- **FR-001a**: El sistema MUST validar en el guardado (no sólo en la carga inicial del formulario) que el Depósito elegido siga existiendo y activo; si no, MUST rechazar el guardado con un error de validación explícito.
- **FR-001b**: Si no hay ningún depósito activo, el formulario "Nueva Venta"/"Nueva Compra" MUST impedir la carga de la operación y mostrar un aviso que remita a Configuración & Ajustes → Depósitos, en vez de fallar recién al intentar mover stock.
- **FR-002**: El formulario "Nueva Compra" (`/purchases/new`) MUST incluir un campo Depósito obligatorio, con el mismo comportamiento que en Ventas (FR-001, FR-001a, FR-001b).
- **FR-003**: Al guardar una Venta, el movimiento de stock (descuento) generado por cada ítem que controla stock MUST aplicarse contra el Depósito elegido en el formulario, no contra `Deposito::porDefecto()`.
- **FR-004**: Al guardar una Compra, el movimiento de stock (suma) generado por cada ítem que controla stock MUST aplicarse contra el Depósito elegido en el formulario, no contra `Deposito::porDefecto()`.
- **FR-005**: Al editar una Venta o Compra existente, si el usuario cambia el Depósito seleccionado, el sistema MUST reintegrar/retirar el stock de la versión anterior sobre el depósito original y aplicar el nuevo movimiento sobre el depósito recién elegido (mismo patrón que ya aplica hoy para cambios de cantidad).
- **FR-006**: Al eliminar una Venta o Compra, el sistema MUST reintegrar/retirar el stock sobre el depósito que esa operación tenía asignado al momento de guardarse (persistido en el registro), no sobre el depósito por defecto vigente al momento de eliminar.
- **FR-007**: El sistema MUST persistir el Depósito elegido en cada Venta y cada Compra (columna `deposito_id` o equivalente en las tablas correspondientes). La columna es nullable a nivel de base de datos (para no requerir backfill de registros históricos, FR-013) aunque el formulario la exija siempre completa para operaciones nuevas.
- **FR-008**: La sección "Ventas" del tab "Ventas" de Configuración & Ajustes (tabla `configuracion_ventas`) MUST incorporar un campo de Depósito por defecto, junto a los campos ya existentes (Categoría, Vendedor, Lista de Precios, Tipo de Comprobante, días de Vto. de Cobro).
- **FR-009**: La sección "Compras" de esa misma pantalla (ya existente desde spec 043/044 — Categoría de Compra por defecto, Tipo de Comprobante de Compra, días de Vto. de Pago) MUST incorporar un campo de Depósito por defecto propio para "Nueva Compra", en la misma tabla `configuracion_ventas` (columna `deposito_compra_id`, análoga a `categoria_compra_id`) — no se crea una tabla ni un tab nuevo, el proyecto ya reutiliza una única fila de configuración global para Ventas y Compras.
- **FR-010**: El formulario "Nueva Venta" MUST precargar el selector de Depósito con el valor de `configuracion_ventas.deposito_id`; si no hay valor configurado o el configurado ya no está activo, MUST usar `Deposito::porDefecto()` como fallback.
- **FR-011**: El formulario "Nueva Compra" MUST precargar el selector de Depósito con el valor de `configuracion_ventas.deposito_compra_id`; mismo fallback que FR-010.
- **FR-012**: El selector de Depósito en ambos formularios MUST implementarse con Select2 (regla de diseño obligatoria del proyecto para selects de datos dinámicos), sólo con depósitos activos como opciones.
- **FR-013**: El cambio MUST ser sólo hacia adelante — Ventas y Compras ya cargadas antes de este feature no se reescriben ni se les infiere un `deposito_id` retroactivo; se puede dejar el campo nulo en esos registros históricos o completarlo con el `Deposito::porDefecto()` vigente al momento de la migración, documentando la elección en el plan técnico.
- **FR-014**: El disparo de sincronización de stock hacia Mercado Libre/Tiendanube (que hoy reacciona a "cualquier movimiento de stock del depósito configurado para la integración") MUST seguir funcionando sin cambios: si una Venta o Compra manual elige un depósito distinto del configurado para ML/Tiendanube, esa integración simplemente no debe reaccionar (mismo comportamiento actual para movimientos en depósitos no vinculados).
- **FR-015**: Las Notas de Crédito/Débito de Compra MUST seguir usando su propio selector de depósito existente, independiente del Depósito de la Compra "raíz" — no se modifican por este feature.
- **FR-016**: El formulario "Nueva Compra"/"Editar Compra" MUST incluir un campo de texto editable para el N° de comprobante (punto de venta + número), en reemplazo de mostrar `nro_comprobante` como un dato fijo no editable.
- **FR-017**: Al abrir "Nueva Compra", el campo de N° de comprobante MUST precargarse con el valor que hoy calcula `Compra::siguienteNroComprobante($tipo_comprobante)` — el mismo correlativo interno que el sistema genera hoy — como valor de partida editable, no como texto de placeholder vacío.
- **FR-018**: El campo de N° de comprobante MUST ser obligatorio en el guardado (alta y edición) — el sistema MUST rechazar el guardado de una Compra con ese campo vacío, sin excepción, incluso si el usuario nunca tocó el valor sugerido.
- **FR-019**: El sistema MUST persistir en `compras.nro_comprobante` exactamente el valor que quedó en el campo al momento de guardar (el sugerido si no se editó, o el real si el usuario lo cambió) — deja de autogenerarse de forma no editable en el backend.
- **FR-020**: Al editar una Compra existente, el campo de N° de comprobante MUST mostrar el valor ya persistido (`compras.nro_comprobante`), editable con las mismas reglas de obligatoriedad que en el alta.
- **FR-021**: Los campos `punto_venta_proveedor`, `numero_comprobante_proveedor`, `cae_proveedor`, `cae_vencimiento_proveedor` (ya existentes, usados por el flujo de Facturación Electrónica con CAE del Proveedor cuando esa función avanzada está activa) MUST seguir funcionando sin cambios — son independientes del campo de N° de comprobante que este feature vuelve editable.

### Key Entities

- **Venta**: gana un atributo Depósito (relación a Depósito), obligatorio, que determina de qué depósito se descuenta el stock de sus ítems al guardarse.
- **Compra**: gana un atributo Depósito (relación a Depósito), obligatorio, que determina a qué depósito se suma el stock de sus ítems al guardarse. El atributo N° de comprobante (`nro_comprobante`, ya existente) cambia de autogenerado-no-editable a editable-obligatorio, con el correlativo interno como valor sugerido de partida en el alta.
- **Configuración de Ventas/Compras** (`configuracion_ventas`, fila única ya existente — spec 043, extendida en spec 044 con defaults de Compra): gana dos atributos nuevos, `deposito_id` (Depósito por defecto de Venta) y `deposito_compra_id` (Depósito por defecto de Compra), ambos relación opcional a Depósito.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Un negocio con múltiples depósitos activos puede cargar una Venta o Compra contra cualquiera de sus depósitos activos, sin pasos adicionales fuera del formulario habitual.
- **SC-002**: El filtro por Depósito en el listado de Ventas refleja fielmente, para el 100% de las Ventas cargadas después de este cambio, el depósito real que el usuario eligió al cargarlas.
- **SC-003**: Configurar un Depósito por defecto distinto para Ventas y para Compras reduce a cero los clics adicionales necesarios para seleccionar el depósito correcto en el flujo de carga más frecuente del negocio (el default ya viene preseleccionado).
- **SC-004**: Ninguna Venta, Compra o movimiento de stock cargado antes de este cambio ve alterado su depósito o su historial al desplegar el feature.
- **SC-005**: El 100% de las Compras cargadas después de este cambio tiene en `nro_comprobante` un valor que el usuario efectivamente vio y pudo editar antes de guardar (sugerido o real), nunca un valor invisible generado sólo en el backend.
- **SC-006**: Cero Compras se guardan con N° de comprobante vacío después de este cambio.

## Assumptions

- La granularidad es por operación completa (una Venta = un depósito, una Compra = un depósito), no por ítem — confirmado explícitamente por el usuario.
- No hay capturas reales de Contagram (`docs/informe_contagram_ingresos.md`, `docs/informe_contagram_egresos.md`) que muestren un campo Depósito en los formularios de Nueva Venta/Compra ni en pantallas de configuración de valores por defecto — este feature es una mejora estructural del CRM para resolver una inconsistencia interna ya detectada (el filtro por Depósito de Ventas no cuadraba), no una fidelidad 1:1 confirmada contra Contagram real. Se documenta como divergencia deliberada en `docs/documentacion_principal_crm.md` una vez implementado.
- Compras ya tiene una sección de valores por defecto propia dentro del tab "Ventas" de Configuración & Ajustes (spec 043/044, tabla `configuracion_ventas` extendida con `categoria_compra_id`/`tipo_comprobante_compra`/`dias_vto_pago_compra`); este feature suma el Depósito por defecto a esa misma sección/tabla, sin crear tab ni tabla nueva.
- Las integraciones Mercado Libre y Tiendanube mantienen su propio `deposito_id` de configuración, totalmente independiente del Depósito por defecto de Ventas/Compras que introduce este feature.
- El campo Depósito en Venta/Compra es obligatorio (no puede quedar sin elegir), consistente con que toda operación necesita un depósito real para mover stock.
- Para los registros históricos (Ventas/Compras previas a este feature), el plan técnico decidirá si la columna `deposito_id` queda nula o se completa con el `Deposito::porDefecto()` vigente al momento de la migración — en ambos casos sin alterar el stock ya movido.
- El campo N° de comprobante editable (US3) no reemplaza ni se fusiona con `punto_venta_proveedor`/`numero_comprobante_proveedor` (los campos del flujo de Facturación Electrónica con CAE): son dos mecanismos que conviven — el de este feature es el número "informal" que identifica la Compra en el propio CRM (visible siempre, editable siempre), mientras que `punto_venta_proveedor`/`numero_comprobante_proveedor`/`cae_proveedor` alimentan específicamente el `ComprobanteFiscal` cuando la función avanzada de Facturación Electrónica está activa y el usuario carga el CAE real. No se ataca la posible redundancia entre ambos en este feature — queda documentada como algo a revisar en un spec futuro si se detecta fricción real.
- Compras existentes (previas a este feature) conservan su `nro_comprobante` autogenerado tal cual — no se les fuerza a completar un número real retroactivamente (mismo principio "sólo hacia adelante" que FR-013 para Depósito).
