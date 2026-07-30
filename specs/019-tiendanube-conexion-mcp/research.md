# Phase 0 — Research: Conexión Tiendanube vía OAuth/MCP (corrección de spec 015)

**Feature**: `019-tiendanube-conexion-mcp` | **Fecha**: 2026-07-29

Todas las decisiones de esta sección fueron **verificadas empíricamente** en la sesión previa a esta
spec, con un cliente standalone en PHP + curl, sin ningún LLM de por medio, contra la cuenta real del
cliente (Pompei Sanitarios). No son suposiciones de documentación pública — de hecho, `admin-mcp.tiendanube.com`
no tiene documentación pública detallada al momento de escribir esta spec (es un producto reciente de
Tiendanube).

---

## R1. Mecanismo de autenticación: OAuth 2.1 + Dynamic Client Registration

**Decisión**: usar el flujo estándar `authorization_code` + PKCE (S256) contra
`admin-mcp.tiendanube.com`, con el cliente OAuth auto-registrado vía `POST /register` (RFC 7591) la
primera vez, en vez de requerir registro manual en el Partner Portal de Tiendanube.

**Verificado**:
```
GET https://admin-mcp.tiendanube.com/.well-known/oauth-protected-resource
→ scopes_supported: read_content, write_content, read_products, write_products,
  read_customers, write_customers, read_orders, write_orders, read_coupons,
  write_coupons, write_scripts, write_shipping
→ bearer_methods_supported: ["header"]

GET https://admin-mcp.tiendanube.com/.well-known/oauth-authorization-server
→ authorization_endpoint: /authorize
→ token_endpoint: /token
→ registration_endpoint: /register
→ grant_types_supported: [authorization_code, refresh_token]
→ code_challenge_methods_supported: [S256]
→ token_endpoint_auth_methods_supported: [client_secret_post, client_secret_basic]
```

`POST /register` respondió sin necesitar ningún login previo, devolviendo `client_id` + `client_secret`
inmediatamente — confirma que no hace falta el Partner Portal ni ningún trámite manual.

**Rationale**: es la única vía viable dado que el plan de la tienda real no permite el modelo de
Aplicación personalizada (spec 015). El auto-registro elimina incluso el paso manual que sí requeriría
un registro de partner tradicional.

**Alternativas descartadas**: registro manual vía Partner Portal (`partners.tiendanube.com`) — se
descarta porque el auto-registro dinámico ya resuelve lo mismo sin trámite humano, y porque no había
certeza (antes de la investigación) de si el Partner Portal exigía algún plan de tienda distinto (no lo
exige, pero el auto-registro es de todos modos más simple).

---

## R2. Transporte del protocolo MCP: JSON-RPC 2.0 sobre HTTP con SSE de un solo evento

**Decisión**: cada llamada a una tool del servidor MCP es un `POST` a `https://admin-mcp.tiendanube.com/`
con cuerpo JSON-RPC 2.0 (`{"jsonrpc":"2.0","id":N,"method":"tools/call","params":{"name":"...",
"arguments":{...}}}`) y cabecera `Accept: application/json, text/event-stream`. La respuesta llega en
formato SSE (`event: message\ndata: {...}\n\n`) pero **como un único evento por request**, no como un
stream persistente — se lee y parsea igual que cualquier respuesta HTTP síncrona.

**Verificado**: en la prueba standalone, cada `curl -X POST` devolvió exactamente un bloque `event:
message` / `data: {...}` y cerró la conexión; no hubo necesidad de mantener un socket abierto ni de
manejar múltiples eventos por respuesta.

**Rationale**: esto es lo que hace viable este enfoque en hosting compartido — no requiere ningún
mecanismo de streaming persistente que ese entorno no soporte. Un `Http::post()` normal de Laravel, con
el body de la respuesta parseado línea por línea (extraer la línea que empieza con `data: ` y hacer
`json_decode`), alcanza.

**Alternativas descartadas**: mantener una conexión SSE persistente (`GET` con `Accept:
text/event-stream` para recibir mensajes push del servidor) — no aplica a este caso de uso: todas las
tools que esta spec necesita son de request/response, no hay ningún flujo de "notificaciones push" del
lado de Tiendanube (tampoco hay webhooks, ver R7).

---

## R3. Vigencia del token y ausencia de renovación

**Decisión**: no implementar ningún mecanismo de renovación de token (ni `refresh_token`, ni lock de
concurrencia). Cuando el token deje de funcionar, la única acción disponible es repetir el flujo de
conexión completo (Historia 1 de spec.md).

**Verificado**: la respuesta de `POST /token` fue `{"access_token": "...", "token_type": "Bearer",
"expires_in": 31536000, "scope": "..."}` — **sin** campo `refresh_token`, con `expires_in` de 31.536.000
segundos (365 días), a pesar de que `grant_types_supported` del authorization server incluye
`refresh_token`.

**Rationale**: un token de un año de vigencia, sin refresh emitido en la práctica, no justifica
construir el mecanismo de renovación con lock atómico que sí necesita Mercado Libre (spec 011, cuyo
`refresh_token` es de un solo uso y expira en horas). Es, de hecho, más simple que el modelo de
Aplicación personalizada de spec 015 en este aspecto (que tampoco tenía renovación, pero por diseño
explícito de Tiendanube para ese modelo).

**A verificar en producción** (documentado como asunción en spec.md): si Tiendanube cambiara esta
política y empezara a emitir tokens de vida más corta con `refresh_token`, haría falta revisar este
research. No hay forma de confirmarlo sin esperar a que ocurra.

---

## R4. Ausencia de tool de "info de tienda": verificación con `list_products`

**Decisión**: FR-003a (ver spec.md, Clarifications) — tras el intercambio de código por token, invocar
`tools/call` con `list_products` (`page_size: 1`) como verificación de que el token funciona de verdad.
Guardar la cantidad total de productos informada (`pagination.total_elements`) para mostrarla en el panel
de estado.

**Verificado**: el listado completo de 24 tools del servidor (confirmado dos veces: vía Claude y vía el
cliente standalone) no incluye ninguna operación de "datos de la tienda" (nombre, dominio, moneda, país)
equivalente al `GET /store` que usaba spec 015 ni al `GET /users/me` de Mercado Libre.

**Rationale**: `list_products` es una lectura liviana, siempre disponible (toda tienda Tiendanube tiene
un catálogo, aunque esté vacío), y de paso confirma que el scope `read_products` otorgado realmente
funciona — no sólo que el intercambio HTTP de `/token` devolvió 200 (eso sólo prueba que el flujo OAuth
en sí funcionó, no que el token sirva para invocar tools).

**Alternativas descartadas**: `get_payment_methods` (también liviana, pero no da un dato numérico
representativo para mostrar en el panel) y "no verificar nada, confiar en el 200 de `/token`" (se
descarta porque un `access_token` con scopes mal otorgados o un servidor MCP con algún problema de
propagación podría devolver un token que technically existe pero no sirve para ninguna tool real —
mejor detectarlo en el momento de conectar que en la primera operación real del usuario).

---

## R5. Scopes solicitados: todos los disponibles

**Decisión**: la autorización (`/authorize`) pide los 7 scopes completos que expone el servidor:
`read_products`, `write_products`, `read_orders`, `write_orders`, `read_customers`, `write_customers`,
`read_content`, `write_content`, `read_coupons`, `write_coupons`, `write_scripts`, `write_shipping`.

**Rationale**: esta spec sólo cubre la conexión, pero las specs futuras que continúan el trabajo de
Tiendanube (017 ventas, 018 stock) van a necesitar lectura de pedidos y escritura de productos/stock como
mínimo. Pedir todo de una vez evita que el usuario tenga que volver a aprobar en el navegador una segunda
vez cuando esas specs se implementen. La barrera real contra escrituras no deseadas durante el desarrollo
sigue siendo el kill-switch de modo sólo lectura ya construido en spec 015 (FR-012), no el scope
otorgado — pedir de más acá no relaja esa protección.

**Alternativas descartadas**: pedir sólo `read_products` (lo mínimo que usa FR-003a) o sólo los `read_*`
— ambas se descartan por la fricción de tener que reautorizar más adelante, decisión tomada explícitamente
por el usuario en la fase de clarify de esta spec.

---

## R6. Manejo de errores

**Decisión**: mapeo de dos niveles, más simple que el de spec 011 (Mercado Libre) porque no hay
renovación que disparar:

| Nivel | Código/condición | Tratamiento |
|---|---|---|
| HTTP/JSON-RPC | 401 (token inválido/revocado) | Conexión → "Caída" (FR-008), sin reintento, sin intento de renovación (no existe) |
| HTTP/JSON-RPC | 429 | Espera creciente + reintento acotado, respetando `Retry-After` si viene |
| HTTP/JSON-RPC | 5xx / error de conexión | Reintento acotado, sin marcar la conexión como caída |
| Protocolo MCP | `isError: true` en la respuesta de una tool (la request HTTP fue 200, pero la tool falló — ej. un `product_id` inexistente) | Se registra como error en el historial, con el mensaje que trae el resultado de la tool; **no** afecta el estado de la conexión (el problema es del argumento de la llamada, no de la credencial) |

**Rationale**: reutiliza el vocabulario y la taxonomía de resultado ya usada en `ml_operaciones_log` /
`tn_operaciones_log` (éxito / error / bloqueada), extendida con la distinción HTTP vs. protocolo MCP que
no existía en la REST plana de spec 015/011.

**Valores concretos de "espera creciente" (hallazgo A1 de `/speckit-analyze`)**: se reutilizan tal cual
las constantes ya definidas en `ClienteTiendanube` desde spec 015 — `ESPERAS_SEGUNDOS = [1, 2, 4]` y
`MAX_INTENTOS_TRANSITORIOS = 3` — no se redefinen para esta spec. FR-010 no repite estos valores porque
no son una decisión nueva, son la infraestructura ya construida que T007 (tasks.md) reutiliza.

---

## R7. Sin webhooks

**Decisión**: no se implementa nada relacionado a webhooks en esta spec ni se asume que existan.

**Verificado**: las 24 tools del servidor no incluyen ninguna de gestión de webhooks (ni alta, ni baja,
ni consulta). Confirmado dos veces (listado completo vía Claude y vía `tools/list` del cliente
standalone).

**Rationale**: documentar esto ahora evita que una spec futura (017/018) asuma erróneamente que puede
suscribirse a eventos de "nuevo pedido" o "stock actualizado" — cualquier sincronización futura deberá
ser por *polling* periódico.

---

## R8. Reutilización de `tn_operaciones_log` y su retención

**Decisión**: sin cambios respecto de spec 015 — mismo esquema de columnas, mismo criterio de retención
(30 días o 5.000 filas, purga oportunista). El campo `operacion` ahora registra el nombre de la tool MCP
invocada (`list_products`, `update_stock_and_price`, etc.) en vez de un nombre de operación REST inventado.

**Rationale**: no hay ningún motivo de dominio para cambiar esto; es infraestructura ya construida,
probada y en producción (aunque nunca haya recibido tráfico real, dado que spec 015 quedó inutilizable).

---

## R9. Portabilidad de entorno

**Decisión**: sin procesos permanentes, sin locks, sin cron — más portable todavía que spec 015, porque
ni siquiera existe el ciclo de verificación de vencimiento que spec 015 sí tenía (aunque tampoco
necesitaba lock).

**Rationale**: al no haber renovación de ningún tipo, desaparece cualquier motivo para sincronizar
procesos concurrentes. El único estado compartido es el `access_token` guardado en la fila única de
`tn_configuracion`, leído y usado dentro del mismo request — igual que ya hacía spec 015.

---

## R10. Permiso de acceso

**Decisión**: se reutiliza `configuracion.funciones`, sin cambios respecto de spec 015/011.

---

## R11. Testing sin tocar la cuenta real

**Decisión**: **ningún test automatizado de esta spec ejecuta una llamada real** contra
`admin-mcp.tiendanube.com` — ni de lectura ni de escritura. Todo el testing automatizado usa
`Http::fake()` (mismo mecanismo ya usado en los tests de Mercado Libre) para simular:
- La respuesta de `POST /register` (auto-registro).
- La respuesta de `POST /token` (intercambio de código).
- Las respuestas de `tools/call` (incluyendo `list_products` para la verificación FR-003a, y respuestas
  de error 401/429/5xx e `isError: true`).

La validación de punta a punta contra la cuenta real (que sí requiere aprobar de verdad en el navegador
de Tiendanube) queda documentada en `quickstart.md` como procedimiento **manual**, a cargo del usuario —
nunca como parte de la suite de PHPUnit.

**Rationale**: restricción explícita y no negociable de esta spec (ver spec.md "Contexto y fuentes" y
"Restricción crítica"): la cuenta usada es la cuenta real de producción del cliente, sin sandbox
disponible. Un test automatizado que corra en CI o en la máquina de cualquier desarrollador no debe tener
la posibilidad de alterar (ni siquiera leer masivamente) esa cuenta.
