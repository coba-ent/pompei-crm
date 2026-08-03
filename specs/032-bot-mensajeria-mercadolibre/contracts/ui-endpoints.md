# Contrato: Endpoints internos del CRM (UI)

Todos bajo autenticación de sesión Laravel estándar (`web` middleware) + permisos
`mensajeria.ver` / `mensajeria.responder`. Respuestas AJAX en JSON, notificaciones de error/éxito vía
Toastr (regla de diseño obligatoria del CLAUDE.md).

**Nota de alcance**: no hay endpoints de "sugerencia" ni de "configuración del bot" en esta spec — se
agregan en la spec futura de la Fase 1 (bot con IA), sin romper estos contratos.

## Bandeja y conversaciones (`Mensajeria\ConversacionController`)

- `GET /mensajeria` — vista principal (bandeja + panel de chat), permiso `mensajeria.ver`.
- `GET /mensajeria/datatable` — listado de conversaciones para DataTables server-side (bandeja),
  columnas: comprador, publicación/orden relacionada, último mensaje (preview), estado, fecha.
- `GET /mensajeria/{conversacion}` — detalle AJAX: historial completo de mensajes de esa conversación.
- `GET /mensajeria/actualizaciones?desde=<timestamp>` — polling (R6 de research.md): conversaciones y
  mensajes nuevos desde `desde`, para refrescar la bandeja y la conversación abierta sin recargar.
- `POST /mensajeria/{conversacion}/responder` — envía la respuesta real a Mercado Libre.
  - Body: `{ texto: string }`.
  - Permiso `mensajeria.responder` (distinto de `mensajeria.ver`, para poder dar acceso de sólo lectura
    a alguien que no debe enviar respuestas).
  - Responde `422` si la conversación ya tiene una respuesta exitosa registrada para ese mensaje
    (FR-007), con mensaje claro para mostrar en Toastr.
  - Responde error de aplicación si `ClienteMercadoLibre` devuelve error (FR-008), sin marcar la
    conversación como respondida.
