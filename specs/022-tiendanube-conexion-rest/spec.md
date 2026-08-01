# Feature Specification: Conexión Tiendanube vía Application REST del Partner Portal (aditiva a spec 019)

**Feature Branch**: `022-tiendanube-conexion-rest`

**Created**: 2026-07-31

**Status**: Draft

**Input**: User description: "Nueva conexión OAuth de Tiendanube vía Application clásica del Partner Portal (REST API), como alternativa ADITIVA y aislada a la conexión MCP existente (spec 019) — no reemplaza ni modifica nada de specs 017 (ventas Tiendanube), 018 (stock Tiendanube) ni 019 (conexión MCP), que siguen funcionando exactamente igual. Verificado empíricamente que el token de esta Application no sirve contra admin-mcp.tiendanube.com (401) pero sí contra la REST API clásica (200, con datos reales del catálogo). Alcance deliberadamente chico: sólo la conexión, con su propio apartado y panel de estado en Configuración → Tiendanube, sin tocar ClienteTiendanube ni los flujos de negocio ya construidos. Migrar el resto a REST queda para una spec futura, una vez validada esta conexión en producción."

## Contexto y fuentes

`019-tiendanube-conexion-mcp` reemplazó el modelo de Aplicación personalizada de `015-tiendanube-conexion`
por una conexión OAuth 2.1 con auto-registro de cliente contra `admin-mcp.tiendanube.com` (servidor MCP
oficial de Tiendanube), porque el plan de la tienda real del cliente (Pompei Sanitarios) no permitía el
modelo de Aplicación personalizada. Esa conexión MCP está en producción y specs 017 (ventas) y 018 (stock)
ya la usan para sincronización real — **nada de eso se toca en esta spec**.

**Hallazgo de esta sesión (2026-07-31)**: registrarse como Partner en `partners.tiendanube.com` y crear ahí
una Application (distinta del modelo de Aplicación personalizada que bloqueaba spec 015) sortea la
restricción de plan — el Partner Portal no exige el plan Escala/Evolución para dar de alta ni instalar una
app. Esto abre una vía alternativa de conexión, con OAuth clásico (`authorization_code` contra
`www.tiendanube.com/apps/{app_id}/authorize`) y token para la **REST API estándar**
(`api.tiendanube.com`), no para el servidor MCP.

**Verificado empíricamente en esta sesión**, de punta a punta contra la cuenta real (Application "pompei",
App ID 38015, registrada en el Partner Portal):

- Se completó el flujo de autorización en el navegador y se canjeó el código por un `access_token` real
  (`POST https://www.tiendanube.com/apps/authorize/token`), obteniendo también `user_id` (identificador de
  tienda, requerido en la ruta de cada llamada REST) y los *scopes* otorgados.
- Ese mismo `access_token` devolvió **401 `invalid_token`** al invocar una tool contra
  `admin-mcp.tiendanube.com` — confirma que son dos sistemas de autenticación **no intercambiables**, con
  audiencias de token distintas. La conexión MCP de spec 019 sigue siendo la única vía que sirve para
  `ClienteTiendanube` tal como existe hoy.
- Ese mismo `access_token` devolvió **200 OK** con datos reales del catálogo (producto con stock, variantes,
  precio) al llamar `GET https://api.tiendanube.com/v1/{store_id}/products` — confirma que la REST API
  clásica sí es una vía viable, con dos particularidades a resolver en `/plan`: la API exige la cabecera no
  estándar `Authentication: bearer <token>` (no `Authorization: Bearer`) y un `User-Agent` identificando la
  app y un email de contacto.
- El `redirect_uri` que Tiendanube autocompletó en el Partner Portal para la Application
  (`https://partners.tiendanube.com/applications/authentication/38015`) es una pantalla propia de prueba del
  Partner Portal, no una URL del CRM — para el flujo real desde el CRM hace falta reemplazarlo por una ruta
  nueva de este sistema (ver Dependencies) y actualizarlo a mano en el Partner Portal, algo que esta spec no
  puede automatizar.

**Decisión de alcance** (explícita, tomada en esta sesión antes de especificar): esta spec cubre
**únicamente la conexión** — un apartado nuevo y separado en la pantalla de Configuración → Tiendanube, con
su propio botón "Conectar" y su propio panel de estado, que quede en paralelo a la conexión MCP existente
sin interferirla. **No** se migra `ClienteTiendanube`, ni los comandos de sincronización de specs 017/018,
ni las vinculaciones, ni webhooks de negocio — esta conexión nueva no se usa todavía para ninguna operación
real. Migrar el resto a REST (con o sin webhooks reales de Tiendanube, que la REST API sí soporta a
diferencia del MCP) queda para una spec futura, una vez validada esta conexión en producción con la cuenta
real.

**Fuente de dominio**: `docs/documentacion_principal_crm.md` §5.3 (describe hoy sólo el modelo MCP de spec
019 — se amplía en esta spec, sin reemplazar lo ya escrito), `docs/modelo_datos.md` §11 (esquema
`tn_configuracion`/`tn_operaciones_log` de la conexión MCP — no se modifica; esta spec agrega su propio
almacenamiento, ver Key Entities), `specs/015-tiendanube-conexion/contracts/api-tiendanube.md` (documentó en
su momento los endpoints REST clásicos — sigue siendo la referencia más cercana a esta API, aunque esa spec
nunca llegó a producción), `specs/019-tiendanube-conexion-mcp/` (patrón de conexión OAuth de referencia, y
la conexión que esta spec deja intacta).

## Clarifications

### Session 2026-07-31

- Q: ¿Esta spec reemplaza la conexión MCP de spec 019, o convive con ella? → A: convive, aislada: apartado
  propio en la misma pantalla, sin tocar `ClienteTiendanube` ni los flujos de negocio (specs 017/018) que
  hoy dependen del MCP. Confirmado tras verificar empíricamente que los tokens de ambos sistemas no son
  intercambiables (401 contra MCP).
- Q: La REST API sí soporta webhooks reales (a diferencia del MCP, que no tiene ninguno) — ¿esta spec migra
  también la sincronización de ventas/stock a webhooks? → A: no, fuera de alcance. Esta spec es sólo la
  conexión; mantener specs 017/018 con su polling cada 1 minuto tal como están hoy, sin ningún cambio de
  comportamiento. La decisión polling-vs-webhooks para una migración futura queda pendiente de una spec
  posterior, una vez que esta conexión esté validada en producción.

## Alcance

**Incluye**: un apartado nuevo dentro de Configuración → Tiendanube (separado visualmente del panel de la
conexión MCP existente) con acción "Conectar" que dispara el flujo OAuth clásico contra la Application del
Partner Portal, una ruta de callback propia que recibe el código y lo canjea por un `access_token`, una
verificación de que el token funciona de verdad contra la REST API real, y un panel de estado propio
(conectada/no conectada/caída, fecha de conexión, datos de tienda obtenidos en la verificación, scopes
otorgados) con su acción de "Desconectar".

**Excluye explícitamente**: cualquier cambio a `ClienteTiendanube`, a los comandos de sincronización de
ventas (spec 017) o stock (spec 018), a las vinculaciones de productos, o a la conexión MCP existente (spec
019) — todos siguen funcionando exactamente igual que hoy. Tampoco cubre usar esta conexión nueva para
ninguna operación real de negocio (leer pedidos, actualizar stock, etc.) ni suscribirse a webhooks de
negocio — sólo conectar y verificar. Migrar el resto de la integración a REST (y decidir ahí si conviene
sumar webhooks reales) queda documentado como trabajo futuro, condicionado a que esta conexión se pruebe
exitosamente en producción.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Conectar la Application REST sin afectar la conexión existente (Priority: P1)

Como responsable técnico, entro a Configuración → Tiendanube y veo, además del panel de la conexión MCP que
ya funciona, un apartado nuevo y claramente diferenciado para esta segunda conexión. Presiono "Conectar",
apruebo el acceso en la pantalla de autorización de Tiendanube, y vuelvo al CRM con esta conexión nueva
establecida — sin que la conexión MCP existente se vea afectada de ninguna forma.

**Why this priority**: es el único propósito de esta spec — sin poder completar esta conexión no hay nada
que verificar antes de decidir si se migra el resto de la integración.

**Independent Test**: se puede probar de punta a punta contra la cuenta real: presionar "Conectar" en el
apartado nuevo, aprobar en el navegador, y verificar que ese apartado queda "Conectado" mientras el panel de
la conexión MCP (arriba o al lado, en la misma pantalla) sigue mostrando exactamente el mismo estado que
tenía antes de empezar.

**Acceptance Scenarios**:

1. **Given** la pantalla Configuración → Tiendanube con la conexión MCP ya conectada, **When** el usuario
   entra a la pantalla, **Then** ve dos apartados independientes: el existente (conexión MCP, sin cambios) y
   uno nuevo para esta Application REST, con su propio estado "No configurada" y su propio botón "Conectar".
2. **Given** el usuario presiona "Conectar" en el apartado nuevo, **When** el sistema arma la URL de
   autorización, **Then** redirige a `www.tiendanube.com/apps/{app_id}/authorize` con un parámetro de estado
   de un solo uso, usando el `client_id` fijo configurado para esta Application (no auto-registrado).
3. **Given** el usuario aprobó el acceso, **When** Tiendanube redirige de vuelta a la ruta de callback
   dedicada de esta spec con el código de autorización, **Then** el sistema valida el estado, intercambia el
   código por un `access_token` y un identificador de tienda (`store_id`), y los guarda cifrados —
   asociados **únicamente** a este apartado nuevo, sin tocar ningún dato de la conexión MCP.
4. **Given** el intercambio exitoso, **When** el sistema verifica el token con una llamada de lectura real
   contra la REST API, **Then** sólo si esa llamada responde sin error deja este apartado como "Conectado" y
   guarda los datos de tienda obtenidos (nombre, dominio) para mostrarlos en el panel — igual criterio de
   "no confiar sólo en el 200 del intercambio de token" que ya usa la conexión MCP (FR-003a de spec 019).
5. **Given** el estado recibido en el callback no coincide con el enviado, o el código ya fue usado, **When**
   el sistema lo detecta, **Then** rechaza la operación sin dejar este apartado como conectado, e informa el
   motivo por notificación, sin afectar el estado de la conexión MCP.

---

### User Story 2 - Ver el estado real de esta conexión y poder desconectarla (Priority: P1)

Como responsable técnico, una vez conectada esta Application, veo en su propio panel de estado una
confirmación de que funciona de verdad (nombre y dominio reales de la tienda, no un simple "OK") y puedo
desconectarla cuando quiera, sin que eso afecte a la conexión MCP.

**Why this priority**: sin esto, la conexión establecida en la Historia 1 no es verificable ni reversible de
forma independiente.

**Independent Test**: con ambas conexiones activas, desconectar sólo la Application REST y verificar contra
la cuenta real que la conexión MCP sigue "Conectada" sin ninguna interrupción.

**Acceptance Scenarios**:

1. **Given** esta conexión activa, **When** el usuario mira su panel de estado, **Then** ve "Conectada", la
   fecha en que se estableció, los scopes otorgados, y el nombre/dominio de la tienda obtenidos en la
   verificación.
2. **Given** esta conexión activa, **When** el usuario presiona "Desconectar" en su apartado y confirma,
   **Then** el sistema elimina sólo el `access_token` de esta Application, deja este apartado como no
   conectado, y dejar constancia en un historial — sin tocar la conexión MCP ni su propio historial.
3. **Given** ambas conexiones activas, **When** el usuario desconecta la conexión MCP existente (flujo ya
   construido en spec 019, sin cambios), **Then** el apartado de esta Application REST sigue "Conectado" sin
   verse afectado, y viceversa.

---

### User Story 3 - Enterarse cuando esta conexión se cae o falla (Priority: P2)

Como responsable técnico, si revoco el acceso de esta Application desde Tiendanube o el token deja de
funcionar, quiero que el CRM lo muestre en el panel de este apartado y me ofrezca reconectar, sin que se
confunda con el estado de la conexión MCP.

**Why this priority**: sin este aviso, el apartado nuevo podría mostrar "Conectado" mientras las
verificaciones fallan en silencio — mismo riesgo que ya se cubrió para la conexión MCP en spec 019.

**Independent Test**: revocar el acceso de esta Application desde el panel de aplicaciones de Tiendanube y
verificar, en la siguiente verificación, que sólo este apartado pasa a "Caída" con la acción de reconectar
visible, mientras la conexión MCP permanece intacta.

**Acceptance Scenarios**:

1. **Given** esta conexión previamente activa, **When** una llamada de verificación contra la REST API es
   rechazada por token inválido o revocado, **Then** el sistema marca este apartado como "Caída" y muestra
   de forma destacada la acción "Conectar" para rehacer el flujo, sin alterar el estado de la conexión MCP.
2. **Given** una verificación rechazada por exceso de solicitudes (429) o una falla temporal de la REST API
   (5xx/timeout), **When** el sistema la detecta, **Then** reintenta con espera creciente un número acotado
   de veces antes de darla por fallida, sin marcar la conexión como caída — mismo criterio ya usado para la
   conexión MCP.

---

### Edge Cases

- **El usuario cierra la pestaña de autorización sin aprobar ni rechazar**: este apartado queda "No
  configurada"; el estado emitido para ese intento queda inutilizable (de un solo uso, con vencimiento).
- **El usuario cancela la aprobación en la pantalla de Tiendanube** (`error=access_denied`): la conexión no
  queda establecida, se informa por notificación sin mostrar el error crudo.
- **Se reintenta canjear el mismo código de autorización dos veces** (doble click, refresco de página, código
  ya vencido a los 5 minutos): el segundo intento debe fallar sin romper una conexión ya establecida por el
  primero.
- **El `redirect_uri` configurado en el Partner Portal no coincide exactamente con la ruta de callback real
  del CRM**: Tiendanube rechaza la autorización antes de llegar al CRM — se documenta como dependencia
  operativa (ver Dependencies), no como algo que el sistema pueda validar en tiempo de ejecución.
- **El token o `store_id` guardados no pueden descifrarse** (cambio de la clave de aplicación del entorno):
  se informa de forma comprensible y este apartado se marca como caído, igual tratamiento que ya tiene la
  conexión MCP para su propio token.
- **El usuario presiona "Conectar" en este apartado mientras ya existe una conexión previa activa** (sin
  desconectar antes): el sistema lo permite y lo trata como reconexión — si se completa con éxito, reemplaza
  la conexión anterior de este apartado; si falla, la anterior sigue intacta.
- **Se alcanza el límite de solicitudes de la REST API** durante la verificación: se trata igual que
  cualquier 429 (reintento con espera creciente, acotado).
- **El `redirect_uri` configurado en el Partner Portal se cambia a una URL que no controla el CRM** (por
  error o por un tercero con acceso a la cuenta de partner): el código de autorización de la cuenta real
  quedaría expuesto a ese destino en la próxima aprobación. El sistema no puede prevenir esto en tiempo de
  ejecución (Tiendanube no expone el `redirect_uri` como parámetro verificable desde el CRM) — se mitiga
  documentando el riesgo y restringiendo quién tiene acceso a la cuenta de partner, no con una validación
  de código.

## Requirements *(mandatory)*

### Functional Requirements — Conexión OAuth (aislada de spec 019)

- **FR-001**: El sistema DEBE presentar, en un apartado nuevo y visualmente separado dentro de Configuración
  → Tiendanube, una acción "Conectar" que inicie el flujo `authorization_code` contra la Application
  registrada en el Partner Portal de Tiendanube, usando un `client_id` fijo (no auto-registrado) tomado de
  la configuración de la aplicación.
- **FR-002**: El sistema DEBE incluir en la URL de autorización un parámetro de estado de un solo uso, con
  vencimiento acotado (mismo orden de magnitud que el ya usado en spec 019: 10 minutos).
- **FR-003**: El sistema DEBE exponer una ruta de callback dedicada a esta conexión (distinta de la usada por
  la conexión MCP), que reciba el código de autorización, valide el estado recibido, e intercambie el código
  por un `access_token` y el identificador de tienda (`store_id`) asociado, guardándolos cifrados.
- **FR-004**: El sistema DEBE rechazar el intercambio si el estado no coincide, si el código ya fue usado o
  venció, o si Tiendanube informa un error, sin dejar este apartado como "Conectado" e informando el motivo
  por notificación.
- **FR-005**: El sistema DEBE verificar que el `access_token` obtenido funciona de verdad, mediante una
  llamada de lectura real contra la REST API (datos de la tienda), antes de dejar este apartado como
  "Conectado". Si la verificación falla, la conexión NO queda como "Conectada" aunque el intercambio de
  token haya sido exitoso — mismo criterio que FR-003a de spec 019.
- **FR-006**: El sistema NUNCA DEBE exponer el `client_secret` de esta Application ni el `access_token` de
  esta conexión en claro, en la interfaz, en el historial, ni en ningún log de aplicación.

### Functional Requirements — Estado, desconexión y aislamiento

- **FR-007**: El sistema DEBE presentar un panel de estado propio de esta conexión (No configurada /
  Conectada / Caída), con fecha de conexión, scopes otorgados, y los datos de tienda obtenidos en la
  verificación (FR-005) — completamente independiente del panel de estado de la conexión MCP existente.
- **FR-008**: El sistema DEBE ofrecer una acción "Desconectar" propia de este apartado, con confirmación
  previa, que elimine sólo el `access_token`/`store_id` de esta conexión sin afectar en absoluto el estado,
  los datos ni el funcionamiento de la conexión MCP existente (ni viceversa).
- **FR-009**: El sistema DEBE marcar este apartado como "Caída" cuando una verificación contra la REST API
  sea rechazada por token inválido o revocado, mostrando la acción "Conectar" para rehacer el flujo — sin
  alterar el estado de la conexión MCP.
- **FR-010**: El sistema DEBE aplicar espera creciente ante rechazos por exceso de solicitudes (429) o fallas
  temporales de la REST API (5xx / error de conexión) durante la verificación, con un número acotado de
  reintentos.
- **FR-011**: El sistema DEBE registrar cada intento de conexión, verificación y desconexión de esta
  Application en un historial consultable, sin mezclar sus registros con los de la conexión MCP de forma que
  se puedan confundir entre sí.

### Functional Requirements — No-alcance explícito

- **FR-012**: El sistema NO DEBE usar la conexión de esta spec para ninguna operación real de negocio
  (lectura o escritura de productos, pedidos, stock, clientes, etc.) — esta conexión existe únicamente para
  conectar y verificar. Ningún código de specs 017 (ventas), 018 (stock) o de vinculaciones DEBE invocarla.
- **FR-013**: El sistema NO DEBE modificar `ClienteTiendanube`, sus tests, ni ningún comportamiento existente
  de la conexión MCP (spec 019) — la suite de tests de spec 019 DEBE seguir pasando sin cambios tras
  implementar esta spec.

### Key Entities

- **Conexión Application REST de Tiendanube**: registro único (single-tenant, igual criterio que
  `tn_configuracion`) con el `access_token` cifrado y el `store_id` obtenidos del intercambio, los scopes
  otorgados, los datos de tienda obtenidos en la verificación (nombre, dominio), la fecha de conexión y el
  estado. Independiente de `tn_configuracion` (que sigue siendo exclusiva de la conexión MCP) — son
  credenciales de sistemas de autenticación distintos y no intercambiables.
- **Registro de operación de esta conexión**: fecha y hora, operación (conectar/verificar/desconectar),
  resultado, duración, detalle del error si lo hubo. Nunca contiene el `access_token` ni el `client_secret`.
  Distinguible del historial de operaciones de la conexión MCP (`tn_operaciones_log`).

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Un responsable técnico completa la conexión de esta Application (desde "Conectar" hasta ver el
  estado "Conectada" con datos reales de la tienda) en menos de 1 minuto.
- **SC-002**: Después de implementar esta spec, la conexión MCP existente (spec 019) y los flujos de venta y
  stock de Tiendanube (specs 017/018) funcionan exactamente igual que antes — verificable con la suite de
  tests existente de esas specs pasando sin modificaciones y sin regresiones manuales observables.
- **SC-003**: Ningún `client_secret` ni `access_token` de esta conexión es recuperable desde la interfaz ni
  aparece en su historial de operaciones ni en los logs de aplicación, verificable por inspección.
- **SC-004**: Ante una conexión revocada o caída, el usuario recibe un aviso claro con la acción para
  reconectar, sin ver errores técnicos crudos de la REST API.
- **SC-005**: La verificación de la conexión muestra datos reales de la tienda (nombre y dominio), no un
  simple indicador booleano de "OK".

## Assumptions

- **El `access_token` de esta Application no requiere renovación mediante `refresh_token` en el flujo
  estándar de Tiendanube** (a diferencia de Mercado Libre): se asume vigencia hasta que el usuario lo revoque
  desde Tiendanube o desconecte desde el CRM. Si `/plan` encuentra evidencia de que Tiendanube emite
  `refresh_token` para este modelo de Application, se documenta ahí y se ajusta.
- **Una sola tienda conectada a la vez** por esta conexión, mismo supuesto single-tenant que el resto del
  CRM.
- **El `client_id`/`client_secret` de esta Application se cargan una única vez desde configuración de
  entorno** (no se auto-registran como en spec 019, porque el modelo de Application del Partner Portal exige
  alta manual previa) — ya están disponibles (App ID 38015, secret en `CREDENCIALES_ACCESO.txt`).
- **Sin cuenta de prueba/sandbox de Tiendanube disponible**: mismo criterio que spec 019 — todo testing
  automatizado usa dobles de prueba del cliente HTTP; la validación de punta a punta contra la cuenta real la
  hace el usuario manualmente.
- **Permiso reutilizado**: se mantiene `configuracion.funciones`, mismo criterio que specs 015/019.

## Dependencies

- **Externa, manual, fuera del alcance del código**: el `redirect_uri` de la Application "pompei" en el
  Partner Portal de Tiendanube DEBE actualizarse a mano, apuntando a la ruta de callback real que esta spec
  crea en el CRM (hoy apunta a una pantalla de prueba propia del Partner Portal). Sin ese cambio manual, el
  flujo de autorización no puede completarse contra el callback del CRM. Se documenta como paso posterior a
  `/speckit-implement`, a cargo del usuario — mismo tipo de dependencia operativa que la configuración de
  Cron Jobs en hPanel para specs con tareas programadas.
- **Interna**: infraestructura de historial y cifrado ya construida (mismo mecanismo que `tn_operaciones_log`
  y el cifrado de `tn_configuracion`), reutilizada como patrón, no como tabla compartida.
- **Interna**: patrón de conexión OAuth de spec 019 (`TiendanubeOAuthController`) como referencia de diseño,
  sin modificar su código.

## Impacto en la documentación de dominio

Antes de pasar a `/speckit-tasks`, corresponde actualizar:

1. `docs/documentacion_principal_crm.md` §5.3: agregar la descripción de este apartado nuevo (conexión
   Application REST), dejando explícito que convive con la conexión MCP sin reemplazarla y que no se usa
   todavía para operaciones de negocio.
2. `docs/modelo_datos.md` §11: documentar la entidad nueva de esta conexión (independiente de
   `tn_configuracion`).
