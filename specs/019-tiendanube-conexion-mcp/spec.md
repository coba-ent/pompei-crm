# Feature Specification: Conexión Tiendanube vía OAuth/MCP (corrección de spec 015)

**Feature Branch**: `019-tiendanube-conexion-mcp`

**Created**: 2026-07-29

**Status**: Draft

**Input**: User description: "Reemplazo del mecanismo de conexión de la integración Tiendanube (spec 015-tiendanube-conexion, ya implementada y deployada pero inutilizable porque el modelo de Aplicación personalizada requiere un plan Tiendanube Escala o Evolución que la tienda real del cliente no tiene). Esta spec corrige/reemplaza la spec 015 con OAuth 2.1 + auto-registro de cliente contra el servidor MCP oficial de Tiendanube (admin-mcp.tiendanube.com), verificado de punta a punta con un cliente standalone sin ningún LLM de por medio."

## Contexto y fuentes

`015-tiendanube-conexion` implementó y deployó la conexión con Tiendanube usando el modelo de **Aplicación
personalizada** (token de acceso cargado a mano desde el panel de la tienda). Esa spec quedó **inutilizable**:
el modelo de Aplicación personalizada requiere plan Tiendanube **Escala o Evolución**, y la tienda real del
cliente (Pompei Sanitarios) tiene un plan inferior — confirmado por la propia IA de soporte de Tiendanube
(Lumi) y por la ausencia de la opción "Aplicaciones a medida" en el panel de administración de la tienda.

**Investigación previa a esta spec** (sesión 2026-07-29, ver memoria de proyecto): se verificó de punta a
punta, con un cliente standalone en PHP + curl **sin ningún LLM de por medio**, que `admin-mcp.tiendanube.com`
(el servidor MCP oficial de Tiendanube, visible como la app "AdminMCP" ya activada en la tienda) es un
servidor MCP remoto estándar con autenticación **OAuth 2.1 + Dynamic Client Registration (RFC 7591)**, sin
ninguna restricción de plan:

- `POST /register` — auto-registro de cliente OAuth sin necesidad de login ni de pasar por el Partner Portal.
- `GET /authorize` (`authorization_code` + PKCE S256) — el usuario aprueba en el navegador, atado a la cuenta
  con la que esté logueado (no hace falta cargar ningún identificador de tienda a mano).
- `POST /token` — token de **larga duración (~1 año)**, sin `refresh_token` en la práctica: no hace falta
  ciclo de renovación ni lock de concurrencia.
- Protocolo MCP (JSON-RPC 2.0 sobre HTTP; la respuesta llega en formato SSE de un único evento por request,
  no es un stream persistente) contra 24 herramientas ya probadas (`list_products`, `list_orders`,
  `update_stock_and_price`, categorías, cupones, promociones, clientes, medios de pago/envío). **Sin gestión
  de webhooks** — cualquier sincronización en tiempo real de una spec futura deberá resolverse por *polling*.

Esta spec **no** vuelve a levantar la discusión de fondo (por qué Tiendanube y no otra plataforma, alcance de
la integración): sólo corrige el mecanismo de conexión de la 015, dejando intacto lo que esa spec ya construyó
y que no depende del mecanismo de autenticación.

**Restricción crítica de esta sesión, que condiciona todo el diseño de testing**: la cuenta de Tiendanube
usada para desarrollar y validar esto es la **cuenta real del cliente en producción** — no existe cuenta de
prueba/sandbox. Ningún test automatizado puede ejecutar una escritura real contra esa cuenta.

**Fuente de dominio**: `docs/documentacion_principal_crm.md` §5.3 (hoy describe el modelo de Aplicación
personalizada de la 015 — se corrige en esta spec), `docs/modelo_datos.md` §11 (esquema `tn_configuracion` /
`tn_operaciones_log` — se ajusta), `specs/015-tiendanube-conexion/` (spec que se corrige),
`specs/011-mercadolibre-conexion-oauth/` (patrón OAuth de referencia ya construido y en producción).

## Clarifications

### Session 2026-07-29

- Q: El servidor MCP no tiene ninguna tool de "info de la tienda" (a diferencia del `GET /store` que usaba la spec 015). ¿Con qué se verifica que la conexión funciona de verdad tras el intercambio de token, y qué se muestra en el panel? → A: `list_products` con `page_size=1` inmediatamente después de conectar; el panel muestra la cantidad total de productos como confirmación indirecta de que el token funciona (no hay nombre/dominio/moneda de tienda que mostrar).
- Q: ¿Qué scopes se piden en la autorización OAuth de esta spec de conexión, si las specs futuras 017/018 van a necesitar lectura y escritura de productos/pedidos? → A: todos los scopes disponibles del servidor (`read/write_products`, `read/write_orders`, `read/write_customers`, `read/write_content`, `read/write_coupons`, `write_scripts`, `write_shipping`) en esta misma autorización, para que 017/018 no requieran una segunda aprobación en el navegador — el kill-switch de modo sólo lectura ya construido sigue siendo la barrera real contra escrituras no deseadas, no el scope otorgado.

## Alcance

**Incluye**: reemplazar el mecanismo de conexión de la tarjeta "Tiendanube" de Funciones Avanzadas por OAuth
2.1 vía `admin-mcp.tiendanube.com` (auto-registro de cliente, autorización en el navegador, obtención y
guardado cifrado del token), adaptar `ClienteTiendanube` para hablar el protocolo MCP (JSON-RPC/SSE) en vez
de REST plano, y adaptar el panel de estado a lo que el nuevo flujo puede mostrar (ya no hay `store_id`
cargado a mano). El kill-switch de modo sólo lectura, el historial de operaciones con su política de
retención, y el wiring con Funciones Avanzadas (FR-006a de la 015) se **reutilizan sin cambios de
comportamiento** — sólo cambia qué llama a `ClienteTiendanube` por dentro.

**Excluye explícitamente** (igual que excluía la 015, sigue siendo alcance de specs posteriores 017/018):
listado de órdenes de venta, vinculación de productos, conversión a Venta del CRM, sincronización de stock
hacia Tiendanube, importación masiva de catálogo. Tampoco cubre webhooks de negocio, porque el servidor MCP
no los expone — queda documentado como límite conocido para cuando esas specs se retomen.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Conectar la tienda por OAuth, sin cargar ningún dato a mano (Priority: P1)

Como responsable del negocio, entro a Configuración & Ajustes → Funciones Avanzadas → Tiendanube, presiono
"Conectar con Tiendanube", se abre la pantalla de autorización de Tiendanube en mi navegador (donde ya estoy
logueado con la cuenta de mi tienda), apruebo el acceso, y vuelvo al CRM con la conexión ya establecida — sin
tener que copiar y pegar ningún identificador de tienda ni ningún token.

**Why this priority**: es el reemplazo directo de la única razón de ser de la spec 015 (poder conectar la
tienda) — sin esto no hay nada más que construir.

**Independent Test**: se puede probar de punta a punta contra la tienda real: presionar "Conectar", aprobar
en el navegador, y verificar que el CRM queda con la conexión establecida y muestra la cantidad de productos
de la tienda como confirmación de que el token funciona.

**Acceptance Scenarios**:

1. **Given** la función Tiendanube activada y sin conectar, **When** el usuario entra a su configuración,
   **Then** ve el estado "No configurada" y el botón "Conectar con Tiendanube" (nunca un formulario para
   pegar credenciales a mano).
2. **Given** el usuario presiona "Conectar con Tiendanube", **When** el sistema arma la URL de autorización,
   **Then** redirige al usuario a `admin-mcp.tiendanube.com/authorize` con PKCE, el `client_id` ya
   auto-registrado, y un parámetro de estado de un solo uso.
3. **Given** el usuario aprobó el acceso en Tiendanube, **When** Tiendanube redirige de vuelta al CRM con el
   código de autorización, **Then** el sistema lo intercambia por un `access_token`, lo guarda cifrado, invoca
   la tool `list_products` con `page_size=1` como verificación de que el token funciona de verdad (no sólo que
   el intercambio HTTP dio 200), y recién si esa llamada responde sin error deja la conexión como "Conectada".
   Si la verificación falla, la conexión NO queda como "Conectada" aunque el intercambio de token haya sido
   exitoso.
4. **Given** el intercambio del código, **When** el `state` recibido no coincide con el enviado (o el código
   ya fue usado), **Then** el sistema rechaza la operación sin dejar la conexión como conectada, e informa el
   motivo por notificación.
5. **Given** que el cliente OAuth (client_id/client_secret) todavía no fue auto-registrado contra
   `admin-mcp.tiendanube.com`, **When** el sistema necesita iniciar el flujo de conexión por primera vez,
   **Then** lo auto-registra de forma transparente (sin intervención del usuario) antes de redirigir a
   `/authorize`.

---

### User Story 2 - Ver que la conexión funciona y poder desconectar (Priority: P1)

Como responsable del negocio, una vez conectada la tienda, veo en el panel de estado una confirmación de que
la conexión funciona de verdad (la cantidad de productos de mi catálogo, no un simple "OK") y puedo
desconectarla cuando quiera, sin perder el historial de operaciones.

**Why this priority**: sin esto la conexión establecida en la Historia 1 no es verificable ni reversible.

**Independent Test**: con la tienda conectada, se puede verificar contra la cuenta real que el panel muestra
el estado "Conectada" y que "Desconectar" borra el token sin borrar el historial.

**Acceptance Scenarios**:

1. **Given** una conexión activa, **When** el usuario mira el panel de estado, **Then** ve "Conectada", la
   fecha en que se estableció la conexión, los *scopes* de acceso otorgados, y la cantidad total de productos
   del catálogo obtenida en la verificación (FR-003a) — no hay nombre, dominio ni moneda de tienda para
   mostrar, porque el servidor MCP no expone esa información.
2. **Given** una conexión activa, **When** el usuario presiona "Desconectar" y confirma, **Then** el sistema
   elimina el token almacenado, deja la conexión como "Desconectada", y lo registra en el historial.
3. **Given** una conexión ya desconectada, **When** el usuario presiona "Conectar con Tiendanube" de nuevo,
   **Then** el sistema repite el flujo de autorización completo (mismo criterio que la Historia 1) sin
   arrastrar ningún dato de la conexión anterior salvo el historial.

---

### User Story 3 - Operar con seguridad: modo sólo lectura y diagnóstico (Priority: P2)

Como responsable técnico, sigo teniendo el interruptor de modo sólo lectura y el historial de operaciones tal
como ya funcionaban en la spec 015 — esta spec no les cambia el comportamiento, sólo verifica que sigan
funcionando con el nuevo `ClienteTiendanube` basado en MCP.

**Why this priority**: es la misma salvaguarda ya construida y en producción; el riesgo de esta spec es
romperla al cambiar el transporte por dentro, no reconstruirla.

**Independent Test**: activar el modo sólo lectura y verificar que una operación de escritura contra el
servidor MCP (por ejemplo `update_stock_and_price`) queda bloqueada y registrada como tal, exactamente igual
que antes.

**Acceptance Scenarios**:

1. **Given** el modo sólo lectura activo, **When** cualquier parte del sistema invoca una tool de escritura
   del servidor MCP, **Then** la llamada no se envía, se registra en el historial como bloqueada, y quien la
   invocó recibe una respuesta que lo indica — mismo comportamiento que FR-016/FR-017 de la spec 015.
2. **Given** el modo sólo lectura activo, **When** el sistema invoca una tool de lectura, **Then** se ejecuta
   normalmente.
3. **Given** operaciones ya realizadas contra el servidor MCP, **When** el usuario consulta el historial,
   **Then** ve fecha, operación (nombre de la tool invocada), resultado, duración y detalle del error si lo
   hubo — mismo formato ya construido, sin cambios.

---

### User Story 4 - Enterarse cuando la conexión se cae (Priority: P2)

Como responsable del negocio, si revoco el acceso desde el panel de Tiendanube (Aplicaciones → esta
integración → Desinstalar/Revocar), quiero que el CRM me avise que la conexión se cayó y me ofrezca
reconectar, en vez de fallar en silencio.

**Why this priority**: sin este aviso, el CRM seguiría creyendo que está conectado mientras todas las
operaciones fallan calladamente — misma razón que FR-012 de la spec 015.

**Independent Test**: revocar el acceso desde el panel de Tiendanube (o simular el rechazo del servidor MCP)
y verificar que la siguiente operación marca la conexión como caída con la acción de reconectar visible.

**Acceptance Scenarios**:

1. **Given** una conexión previamente activa, **When** una llamada al servidor MCP es rechazada por token
   inválido o revocado, **Then** el sistema marca la conexión como "Caída" y muestra de forma destacada la
   acción "Conectar con Tiendanube" para rehacer el flujo completo (no hay "recargar sólo el token": al no
   haber `refresh_token`, la única salida es repetir la Historia 1).
2. **Given** una operación rechazada por exceso de solicitudes (429) o una falla temporal del servidor MCP
   (5xx/timeout), **When** el sistema la detecta, **Then** reintenta con espera creciente un número acotado de
   veces antes de darla por fallida, sin marcar la conexión como caída — mismo criterio que FR-013/FR-014 de
   la spec 015.

---

### Edge Cases

- **El usuario cierra la pestaña de autorización sin aprobar ni rechazar**: la conexión queda "No
  configurada"; el `state` emitido para ese intento queda inutilizable (de un solo uso, con vencimiento).
- **El usuario aprueba con una cuenta de Tiendanube distinta a la que se conectó antes**: a diferencia de
  Mercado Libre (que pide confirmación explícita para reemplazo de cuenta), acá no hay forma de detectar de
  antemano "otra cuenta" sin datos de tienda cargados manualmente — el sistema simplemente conecta con lo que
  el usuario haya aprobado en el navegador. Se documenta como comportamiento esperado, no como bug.
- **Vuelve a intercambiarse un mismo código de autorización dos veces** (doble click, refresco de página):
  el segundo intercambio debe fallar sin romper la conexión ya establecida por el primero.
- **El token almacenado no puede descifrarse** (cambio de la clave de aplicación del entorno): mismo
  tratamiento que FR edge case de la spec 015 — informarlo de forma comprensible, marcar la conexión como
  caída.
- **Se desactiva la función "Tiendanube" desde Funciones Avanzadas con una conexión activa**: mismo
  comportamiento ya construido (FR-006a de la 015, sin cambios) — exige confirmación, conserva la
  configuración.
- **El auto-registro del cliente OAuth (`POST /register`) falla o el servidor MCP no está disponible**: el
  botón "Conectar con Tiendanube" debe informar el error con claridad en vez de redirigir a una URL rota.
- **El `client_secret` guardado no puede descifrarse** (cambio de la clave de aplicación del entorno,
  mismo escenario que el de `access_token` pero sobre el cliente OAuth): el sistema DEBE registrar un
  cliente OAuth nuevo (repetir FR-001) en vez de fallar de forma opaca — a diferencia del `access_token`
  ilegible (que sólo exige reconectar), acá ni siquiera se puede reconectar con el cliente existente.
- **El usuario presiona "Conectar con Tiendanube" mientras ya existe una conexión activa** (sin
  desconectar antes): el sistema DEBE permitirlo y tratarlo como una reconexión — el flujo completo se
  repite y, si se completa con éxito, reemplaza la conexión anterior; si falla, la conexión anterior sigue
  intacta hasta que el usuario la reemplace con éxito.
- **El usuario cancela la aprobación en la pantalla de Tiendanube** (Tiendanube redirige con
  `error=access_denied`): mismo tratamiento que cualquier otro error de `/authorize` — la conexión no
  queda establecida, se informa por notificación sin mostrar el código de error crudo.

## Requirements *(mandatory)*

### Functional Requirements — Conexión OAuth

- **FR-001**: El sistema DEBE auto-registrar un cliente OAuth contra `admin-mcp.tiendanube.com` (Dynamic
  Client Registration) la primera vez que haga falta, sin intervención manual del usuario, y guardar el
  `client_id`/`client_secret` resultantes de forma cifrada.
- **FR-002**: El sistema DEBE ofrecer una acción "Conectar con Tiendanube" que inicie el flujo
  `authorization_code` con PKCE (S256), incluyendo un parámetro de estado de un solo uso que vence a los
  10 minutos (mismo orden de magnitud que el `state` ya usado por `MercadoLibreOAuthController`) y
  solicitando **todos los scopes disponibles** del servidor (`read/write_products`, `read/write_orders`,
  `read/write_customers`, `read/write_content`, `read/write_coupons`, `write_scripts`, `write_shipping`) —
  para que las specs futuras (017 ventas, 018 stock) no requieran una segunda aprobación del usuario en el
  navegador. El kill-switch de modo sólo lectura (FR-012) es la barrera real contra escrituras no deseadas,
  no el scope otorgado.
- **FR-003**: El sistema DEBE recibir la redirección de autorización, validar el parámetro de estado, e
  intercambiar el código recibido por un `access_token`, guardándolo cifrado.
- **FR-003a**: El sistema DEBE verificar que el `access_token` obtenido funciona de verdad invocando la tool
  `list_products` con `page_size=1` inmediatamente después del intercambio (no hay tool de "info de tienda"
  en el servidor MCP — research.md documenta esta ausencia). "La verificación falla" cubre **ambos**
  casos: un error a nivel HTTP/JSON-RPC (4xx/5xx en la respuesta a `tools/call`) y un error a nivel de
  protocolo MCP (`result.isError: true` con la request en HTTP 200) — research.md R6 documenta por qué
  son dos niveles distintos. Sólo si la llamada responde sin ninguno de los dos tipos de error el sistema
  deja la conexión como "Conectada" y guarda la cantidad total de productos informada, para mostrarla en
  el panel de estado. Si la verificación falla (cualquiera de los dos casos), la conexión NO queda como
  "Conectada" aunque el intercambio de token haya sido HTTP 200.
- **FR-004**: El sistema DEBE rechazar el intercambio si el estado no coincide, si el código ya fue usado, o
  si Tiendanube informa un error, sin dejar la conexión como "Conectada" e informando el motivo.
- **FR-005**: El sistema NUNCA DEBE exponer `client_secret` ni `access_token` en claro a la interfaz, al
  historial, ni a ningún log de aplicación.

### Functional Requirements — Estado y desconexión

- **FR-006**: El sistema DEBE presentar un panel de estado con los valores No configurada / Conectada /
  Caída (sin el estado "Desconectada" tratado distinto de "No configurada": ambos llevan al mismo botón
  "Conectar con Tiendanube", dado que no hay datos parciales que conservar sin `store_id` manual).
- **FR-007**: El sistema DEBE ofrecer una acción "Desconectar", con confirmación previa, que elimine el
  `access_token` almacenado y deje la conexión como no configurada, conservando el historial de
  operaciones **y** el `client_id`/`client_secret` del cliente OAuth ya auto-registrado (para que una
  reconexión posterior no dispare un nuevo auto-registro, FR-001).
- **FR-008**: El sistema DEBE marcar la conexión como "Caída" cuando una llamada al servidor MCP sea
  rechazada por token inválido o revocado, mostrando de forma destacada la acción "Conectar con Tiendanube"
  para rehacer el flujo completo.

### Functional Requirements — Cliente MCP y reintentos

- **FR-009**: El sistema DEBE hablar el protocolo MCP (JSON-RPC 2.0 sobre HTTP) contra
  `admin-mcp.tiendanube.com`, incluyendo el `access_token` vigente como credencial Bearer, y parsear la
  respuesta en formato SSE de un único evento por request.
- **FR-010**: El sistema DEBE aplicar espera creciente ante rechazos por exceso de solicitudes (429) o fallas
  temporales del servidor (5xx / error de conexión), con un número acotado de reintentos, sin descartar la
  operación silenciosamente.
- **FR-011**: El sistema DEBE distinguir un error a nivel de protocolo MCP (`isError: true` en la respuesta de
  una tool) de un error a nivel HTTP/JSON-RPC, registrando ambos en el historial con su propio detalle.

### Functional Requirements — Reutilización de spec 015 (sin cambios de comportamiento)

- **FR-012**: El sistema DEBE mantener el kill-switch de modo sólo lectura verificado en un único punto,
  bloqueando toda tool de escritura del servidor MCP cuando esté activo, y registrándolo en el historial.
- **FR-013**: El sistema DEBE mantener el historial de operaciones consultable, paginado y filtrable, con la
  misma política de retención (30 días o 5.000 filas) ya construida en spec 015.
- **FR-014**: El sistema DEBE mantener la confirmación explícita al desactivar la función "Tiendanube" desde
  Funciones Avanzadas mientras exista una conexión activa (FR-006a de la spec 015), sin cambios.

### Key Entities

- **Cliente OAuth Tiendanube**: registro único con las credenciales del cliente auto-registrado
  (`client_id`, `client_secret` cifrado) y del token vigente (`access_token` cifrado, fecha de conexión,
  *scopes* otorgados, cantidad de productos informada en la verificación FR-003a, estado de la conexión).
  Reemplaza los campos `store_id`/`access_token` manuales de `tn_configuracion` en spec 015.
- **Registro de operación Tiendanube**: sin cambios respecto de spec 015 — fecha y hora, tool invocada,
  sentido (lectura/escritura), resultado, duración, detalle del error, indicador de bloqueo. Nunca contiene
  el token ni el `client_secret`.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Un responsable del negocio completa la conexión de la tienda (desde "Conectar con Tiendanube"
  hasta ver el estado "Conectada") en menos de 1 minuto, sin necesitar ningún dato técnico que copiar y pegar.
- **SC-002**: El 100% de las operaciones de escritura queda bloqueado y registrado cuando el modo sólo lectura
  está activo, verificable sobre la cuenta real sin que ninguna alcance a modificarla.
- **SC-003**: Ningún `client_secret` ni `access_token` es recuperable desde la interfaz ni aparece en el
  historial de operaciones ni en los logs de aplicación, verificable por inspección.
- **SC-004**: Ante una conexión revocada o caída, el usuario recibe un aviso claro con la acción para
  reconectar, sin ver errores técnicos crudos del protocolo MCP.
- **SC-005**: La suite de tests automatizados de esta spec corre en verde sin ejecutar ni una sola escritura
  real contra la cuenta de Tiendanube del cliente — verificable revisando que todos los tests de escritura
  usan un doble de prueba del cliente HTTP.

## Assumptions

- **El servidor MCP (`admin-mcp.tiendanube.com`) es first-party de Tiendanube y no depende del plan de la
  tienda**: confirmado empíricamente (lectura y escritura funcionaron con el plan actual, que sí bloquea el
  modelo de Aplicación personalizada). Si Tiendanube cambiara esta política, la conexión dejaría de
  funcionar — está fuera de control del CRM.
- **El token de acceso no requiere renovación en la práctica** (vigencia observada ~1 año, sin
  `refresh_token`): si Tiendanube emitiera en el futuro tokens de vida más corta, haría falta revisar esta
  asunción; por ahora no se construye ningún mecanismo de renovación.
- **Una sola tienda conectada a la vez**: mismo supuesto single-tenant que el resto del CRM.
- **El auto-registro del cliente OAuth se hace una única vez y se reutiliza**: no se re-registra en cada
  conexión/desconexión, sólo si no existe un registro previo guardado.
- **Sin cuenta de prueba/sandbox de Tiendanube disponible**: todo testing automatizado usa dobles de prueba
  del cliente HTTP; la validación de punta a punta contra la cuenta real la hace el usuario manualmente
  (quickstart.md la documenta como pasos manuales, no como parte de la suite automatizada).
- **Permiso reutilizado**: se mantiene `configuracion.funciones`, mismo criterio que spec 015.

## Dependencies

- **Externa**: disponibilidad y estabilidad de `admin-mcp.tiendanube.com` — no hay documentación pública
  detallada de este servidor al momento de escribir esta spec (es un producto nuevo de Tiendanube); el
  contrato técnico se documenta en `research.md` a partir de lo verificado empíricamente.
- **Interna**: infraestructura ya construida en spec 015 que se reutiliza (`tn_operaciones_log`, kill-switch,
  pantalla de Funciones Avanzadas, mecanismo de cifrado).
- **Interna**: patrón OAuth de spec 011 (Mercado Libre) como referencia de diseño para
  `TiendanubeOAuthController`.

## Impacto en la documentación de dominio

Antes de pasar a `/speckit-tasks`, corresponde actualizar:

1. `docs/documentacion_principal_crm.md` §5.3: reemplazar la descripción del modelo de Aplicación
   personalizada por el modelo OAuth/MCP, dejando constancia de por qué se corrigió (plan de la tienda) y
   qué de la 015 se conserva sin cambios.
2. `docs/modelo_datos.md` §11: ajustar el esquema de `tn_configuracion` a los nuevos campos
   (`client_id`/`client_secret`/`access_token` en vez de `store_id`/`access_token` manual).
