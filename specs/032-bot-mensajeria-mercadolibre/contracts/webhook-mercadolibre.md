# Contrato: Webhook de notificaciones de Mercado Libre

## `POST /webhooks/mercadolibre`

Recibe notificaciones de Mercado Libre para los topics `questions` y `messages`. Callback URL ya
configurada por el usuario en el DevCenter (2026-08-02):
`https://contagramdemo.devstudioweb.com/webhooks/mercadolibre`.

**Sin middleware de sesión/CSRF** (llamada server-to-server de ML, mismo patrón que
`webhooks/tiendanube/*`). Responder siempre `2xx` salvo error de validación real, y responder rápido.
En esta spec (sin IA) el procesamiento es liviano y corre síncrono dentro del propio request — no hace
falta encolar un Job (ver `research.md` R3/R7).

### Request (shape estándar de notificaciones de ML)

```json
{
  "resource": "/questions/123456789",
  "user_id": 987654321,
  "topic": "questions",
  "application_id": 1234567890123456,
  "attempts": 1,
  "sent": "2026-08-02T10:00:00.000Z",
  "received": "2026-08-02T10:00:01.000Z"
}
```

Para `topic: "messages"`, `resource` es directamente el `message_id` (no un path) — ver la sección
"Mensajería post-venta" más abajo para el shape confirmado.

### Comportamiento esperado

1. Validar que la notificación pertenece a la `application_id` configurada (rechazar si no matchea).
2. Identificar `topic`:
   - `questions` → procesar Pregunta (fetch del detalle vía `GET` a `resource`, upsert de
     `Conversacion`/`Mensaje` por clave natural — R4).
   - `messages` → procesar Mensaje post-venta (idem, upsert por `ml_id`).
   - cualquier otro topic → responder `200` e ignorar (no es de interés de este módulo).
3. Responder `200 {"ok": true}` una vez procesado (o detectado como duplicado — ver Casos de respuesta
   abajo).

### Casos de respuesta

| Caso | Respuesta |
|---|---|
| Notificación válida, primera vez que se ve ese `ml_id` | `200 {"ok": true}`, se crea Conversación/Mensaje |
| Notificación válida, `ml_id` ya procesado (reintento) | `200 {"ok": true}`, no se crea nada nuevo (idempotencia, FR-004) |
| `application_id` no coincide | `401` (mismo criterio que la validación de firma en `TiendanubeWebhookController`) |
| Topic no relevante para este módulo | `200 {"ok": true}`, se ignora |

## Envío de respuesta (interno, no HTTP expuesto — vía `ClienteMercadoLibre`)

### Preguntas — `POST /answers`

```json
// Request (vía ClienteMercadoLibre::enviar)
{ "question_id": 123456789, "text": "Sí, tenemos stock disponible." }
```

```json
// Response (200 esperado)
{ "id": 123456789, "text": "Sí, tenemos stock disponible.", "status": "ANSWERED", "date_created": "..." }
```

### Mensajería post-venta — `POST` sobre el recurso de mensajes del pack

**RESUELTO (2026-08-02)** — confirmado contra la documentación oficial
(`developers.mercadolibre.com.ar/es_ar/mensajeria-post-venta`, vía MCP de Mercado Libre) tras
reproducir el fallo con 2 notificaciones reales en el demo:

- `resource` para `topic=messages` **no es un path** (a diferencia de `questions`) — es directamente
  el `message_id` (string opaco, ej. `019fc16736a970f385ae18b7d7aac03a`). El fix original que asumía
  `/messages/packs/{pack_id}/sellers/{seller_id}` armaba una URL rota (`API_BASE . message_id` sin
  `/` → host inválido → `ConnectionException`, indistinguible en el log de un problema de red real).
- **Lectura correcta**: `GET /messages/{message_id}?tag=post_sale`. Respuesta con dos shapes posibles
  (documentados ambos): `{paging, conversation_status, messages: [{id, from, to, text, message_date,
  message_resources: [{id, name: "packs"|"sellers"}, ...], ...}]}` (shape "con header") o el objeto
  plano viejo (`message_id`, `text.plain`, `resource`/`resource_id`). `RecepcionMensajeMercadoLibre`
  normaliza ambos. El pack/orden asociado sale de `message_resources` (entrada `name=packs`), no del
  `resource` del webhook.
- **Envío correcto**: `POST /messages/packs/{pack_id}/sellers/{seller_id}?tag=post_sale` con
  `{from: {user_id: <vendedor>}, to: {user_id: <comprador>}, text}` — el bug original tenía `from` y
  `to` apuntando los dos al comprador (nunca al vendedor) y la URL sin `{seller_id}`. `pack_id` puede
  ser el `order_id` si no hay pack real (documentado explícitamente por ML).
- Queda auditado el payload crudo de cada webhook en `ml_operaciones_log`
  (`operacion=webhook_recibido`) para diagnosticar más rápido si vuelve a divergir el shape.

Las Preguntas pre-venta (R1, `topic=questions`) sí tienen `resource` como path real
(`/questions/123456789`) — no tuvieron este problema.

```json
// Request confirmado (POST /messages/packs/{pack_id}/sellers/{seller_id}?tag=post_sale)
{ "from": { "user_id": "<seller_id>" }, "to": { "user_id": "<buyer_id>" }, "text": "..." }
```

### Manejo de error de la API de ML (FR-008)

Cualquier respuesta no-2xx de `ClienteMercadoLibre::enviar()` (incluye rechazos de moderación de
contenido de ML, ver `docs/bot_mensajeria_ml/riesgos-politicas-ml.md`) se traduce en:

- `ml_respuestas_enviadas.resultado = 'error'` con el mensaje devuelto por ML en
  `error_mensaje`.
- La conversación permanece en estado `pendiente` (no se marca como respondida).
- El usuario ve el motivo del rechazo en la UI y puede corregir el texto sin perder lo escrito.
