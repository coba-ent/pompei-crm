# Research: Mensajería de Mercado Libre (lectura y respuesta manual)

## R1 — API de Preguntas pre-venta (ML)

**Decision**: usar `GET /my/received_questions/search` (o `GET /questions/search?item_id=$ITEM_ID`)
para el detalle/backfill, `POST /answers` con `{question_id, text}` para responder.

**Rationale**: confirmado por WebFetch contra la documentación pública de ML
(`developers.mercadolibre.com.ar/es_ar/preguntas-y-respuestas`, 2026-08-02). Estados de pregunta:
`ANSWERED`, `UNANSWERED`, `BANNED`, `CLOSED_UNANSWERED`, `DELETED`, `DISABLED`, `UNDER_REVIEW` — el
mapeo de "pendiente de respuesta" (FR-002) es `UNANSWERED`; `BANNED`/`DISABLED`/`DELETED` se muestran
como conversación cerrada sin acción posible (Edge Case de la spec).

**Alternatives considered**: pedir el detalle completo con `api_version=4` en cada notificación — se
descarta porque expone datos de contacto del comprador (email/teléfono) que no hacen falta para este
flujo; se pide sólo lo necesario para mostrar la pregunta.

## R2 — API de Mensajería post-venta (ML) — **CONFIRMADO en implementación (2026-08-02)**

**Decision (confirmada contra `developers.mercadolibre.com.ar/es_ar/mensajeria-post-venta` durante la
implementación, T008)**:

- **Lectura** (notificación webhook `topic=messages`): a diferencia de `questions` (donde `resource` es
  un path tipo `/questions/123`), en `messages` **`resource` es directamente el `message_id`** — se
  resuelve con `GET /messages/{message_id}?tag=post_sale`. La respuesta tiene dos shapes posibles según
  el mensaje: uno "con header" (`{messages: [...]}`, campo `id`, `text` string) y uno viejo (el propio
  objeto es el mensaje, campo `message_id` en vez de `id`, `text` anidado en `text.plain`) — ambos se
  normalizan a la misma forma interna en `RecepcionMensajeMercadoLibre`.
- **Envío**: `POST /messages/packs/{pack_id}/sellers/{seller_id}?tag=post_sale` con
  `{from: {user_id: <seller_id>}, to: {user_id: <buyer_id>}, text}`. `pack_id` puede ser el `order_id`
  si no hay un pack real (documentado explícitamente por ML). **`from` es SIEMPRE el vendedor** (la
  cuenta conectada) y `to` el comprador — invertirlos hace que ML rechace el envío.
- El comprador de una conversación post-venta se resuelve como "quien NO es la cuenta conectada" (no
  asumir que siempre es `from`), y el pack/orden asociado sale de `message_resources` (`name=packs`) o,
  en el shape viejo, de `resource_id`.

**Rationale**: verificado contra la documentación oficial vigente durante la implementación (no pudo
confirmarse por WebFetch al momento de planificar — varias URLs devolvieron 404 esa sesión). Implementado
en `RecepcionMensajeMercadoLibre::procesarPostVenta()` y
`EnvioRespuestaMercadoLibre::enviarPostVenta()`.

**Alternatives considered**: ninguna — es el único mecanismo que ofrece Mercado Libre para mensajería
de post-venta ligada a una orden.

## R3 — Notificaciones (webhook) de ML para preguntas y mensajería

**Decision**: un único endpoint `POST /webhooks/mercadolibre` (ya configurado por el usuario en el
DevCenter) recibe notificaciones de los topics `questions` y `messages`; el controller identifica el
topic por el payload (`{resource, user_id, topic, application_id, attempts, sent, received}` — shape
estándar de notificaciones de ML) y despacha a la rama correspondiente (Pregunta vs. Mensaje post-venta).

**Rationale**: es el mismo mecanismo (un solo callback URL, distintos `topic`) que usa ML para todas sus
notificaciones (`orders_v2`, `items`, `questions`, `messages`, etc. — confirmado por WebFetch contra
`developers.mercadolibre.com.ar/es_ar/notificaciones`, 2026-08-02, que también documenta que ML
reintenta notificaciones fallidas **5 veces en 1 hora** — de ahí la idempotencia obligatoria, FR-004).
Sigue el mismo patrón que `TiendanubeWebhookController`: responder rápido (2xx) sin trabajo pesado en el
request. En esta spec (sin IA) el "trabajo" tras la notificación es liviano — persistir el mensaje y,
si hace falta, pedir el detalle a la API de ML — por lo que puede resolverse dentro del mismo request
del webhook sin necesidad de encolar un Job (a diferencia de la futura spec del bot, que sí necesitará
async por el llamado al LLM).

**Alternatives considered**: dos endpoints separados (uno por topic) — se descarta, ML permite un único
callback URL registrado por aplicación, no uno por topic.

## R4 — Idempotencia ante reintentos de webhook (FR-004)

**Decision**: cada `Mensaje` se identifica por una clave única `(tipo, ml_id)` — el ID de la
pregunta (`question_id`) o del mensaje post-venta (`message_id` del pack) tal como lo asigna Mercado
Libre. El procesamiento de la notificación hace un upsert por esa clave: si ya existe, no se crea de
nuevo.

**Rationale**: es el patrón estándar para procesar webhooks con reintentos — la clave natural del
recurso en el sistema de origen, no un ID generado localmente, evita duplicados incluso si dos
notificaciones del mismo evento llegan casi al mismo tiempo (colisión de upsert resuelta con constraint
único a nivel de base de datos, no sólo a nivel de aplicación).

**Alternatives considered**: deduplicar por hash del payload completo — se descarta, es más frágil (un
campo que cambia entre reintentos, como un timestamp de reenvío, rompería la deduplicación).

## R5 — Reutilización de `ClienteMercadoLibre` para el envío

**Decision**: `EnvioRespuestaMercadoLibre` llama a `ClienteMercadoLibre::enviar()` (servicio ya
existente, `app/Services/MercadoLibre/ClienteMercadoLibre.php`) para el `POST /answers` o el `POST` de
mensajería post-venta — no se crea un cliente HTTP paralelo.

**Rationale**: `ClienteMercadoLibre` ya es, por diseño del proyecto ("Punto ÚNICO de salida hacia la API
de Mercado Libre", ver docstring de la clase), el único lugar donde se resuelve el token, el kill-switch
de sólo lectura (`modo_solo_lectura`), el guard de función avanzada activa, y el registro en el
historial de operaciones (`MercadoLibreOperacionLog`). Reusarlo da todo eso gratis para el envío de
respuestas, sin reimplementarlo.

**Alternatives considered**: ninguna considerada seriamente — ir en contra de esto violaría el diseño
ya establecido de la integración de Mercado Libre en este proyecto.

## R6 — Notificación al frontend (polling)

**Decision**: la vista de "Mensajería" hace polling AJAX periódico (intervalo corto, ej. 15-20s,
ajustable) contra un endpoint que devuelve conversaciones/mensajes actualizados desde la última consulta,
igual que el patrón ya usado en otras pantallas del CRM con actualización periódica.

**Rationale**: decidido en `docs/bot_mensajeria_ml/decisiones-pendientes.md` — consistente con el resto
del CRM, sin necesidad de WebSockets/SSE para esta primera versión.

**Alternatives considered**: Laravel Echo/WebSockets — descartado por complejidad de infraestructura
adicional no justificada para esta feature.

## R7 — Sin dependencia de VPS/colas (alcance recortado)

**Decision**: esta spec no depende de la migración a VPS ni de `QUEUE_CONNECTION=database`/`queue:work`.
Todo el procesamiento (recepción del webhook, persistencia, envío de la respuesta) corre síncrono,
dentro del ciclo de request normal de Laravel, compatible con el hosting compartido actual.

**Rationale**: decisión explícita del usuario (2026-08-02) de recortar el alcance de la primera spec al
"sin bot" precisamente para no depender del VPS (que se contrata al día siguiente, 2026-08-03). El bot
con sugerencias de IA —que sí necesita colas reales por el llamado a un LLM externo, ver
`docs/bot_mensajeria_ml/infraestructura.md`— queda para la spec futura de la Fase 1.

**Alternatives considered**: implementar el Job/cola igual desde ahora "por las dudas" — se descarta,
agregaría complejidad de infraestructura no utilizada en esta fase y contradice la decisión explícita
del usuario de mantener esta spec simple y desplegable ya, sin esperar al VPS.
