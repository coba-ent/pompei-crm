# Flujo propuesto y alcance por fases

> **Nota (2026-08-02)**: el flujo completo de abajo describe la visión de largo plazo (con bot de IA).
> La spec `032-bot-mensajeria-mercadolibre` que se está implementando ahora cubre sólo la **Fase 0**
> (ver más abajo): leer mensajes + responder manualmente, sin generación de IA. El bot (pasos 3 y 5 de
> este flujo) queda para una spec futura, después de migrar el VPS.

## Flujo (visión completa, con bot — a implementar en fases)

1. Mercado Libre notifica un mensaje nuevo vía webhook (o se detecta por polling si el webhook de
   mensajería no aplica — ver [decisiones-pendientes.md](decisiones-pendientes.md)).
2. El CRM guarda el mensaje en una tabla propia (conversación + mensajes), asociado al comprador y,
   si es posible, a la publicación/producto y a la venta relacionada.
3. Se encola un job que le pide al LLM una respuesta sugerida, usando como contexto: el mensaje, el
   historial de la conversación, y datos del producto/venta relacionados (stock, precio, estado de
   envío) para que la sugerencia sea útil y no genérica.
4. La vista del panel (nueva, dentro de algún módulo existente — a definir dónde cuelga en el sidebar)
   lista las conversaciones con DataTables + AJAX, siguiendo las reglas de diseño obligatorias del
   proyecto (modales, Select2, toasts, todo AJAX).
5. Un humano abre la conversación, ve la sugerencia generada por el bot, la edita si hace falta, y
   confirma el envío. Recién ahí se llama a la API de ML para efectivamente mandar la respuesta.
6. Queda registro de qué se envió, si fue la sugerencia tal cual o editada, y quién la aprobó
   (auditoría — relevante también de cara a políticas de ML, ver
   [riesgos-politicas-ml.md](riesgos-politicas-ml.md)).

**Decisión original (2026-08-02, al especificar la spec 033)**: arrancar como *borrador + aprobación
humana*, no envío automático — así quedó implementado y desplegado (specs 032/033, ver más abajo).

**Cambio de rumbo (2026-08-02, post-demo)**: el usuario reconsideró esta decisión al ver el bot
funcionando — el plan pasa a ser que el bot corra en el VPS **24/7** y **conteste solo desde el
arranque**, usando el contexto de la empresa, en vez de quedar atado a la aprobación humana por
mensaje. El código desplegado hoy **sigue con aprobación humana** (no se tocó todavía) — el paso 5 de
abajo describe el comportamiento actual; el envío automático es la próxima spec a especificar. Detalle
completo de esta reconsideración y sus implicancias en
[decisiones-pendientes.md § Aprobación humana obligatoria — REABIERTA](decisiones-pendientes.md).

## Fases de alcance — CERRADO (2026-08-02)

- **Fase 0 — sin bot, esta es la spec `032-bot-mensajeria-mercadolibre`** (a pesar del nombre, el
  alcance real de esta primera spec queda acotado a lo siguiente — ver spec.md): traer los mensajes de
  ML (Preguntas + post-venta) al panel de "Mensajería", verlos centralizados con historial completo por
  comprador, y permitir **responder manualmente** desde ahí (sin sugerencia de IA todavía). Sirve para
  validar el modelo de datos, la vinculación con productos/ventas, la vista, y el circuito de
  envío/auditoría, sin la complejidad del LLM ni de las colas de background.
  - **Motivo del recorte**: el VPS (con colas reales) todavía no está disponible al momento de arrancar
    la implementación — se contrata "mañana" (2026-08-03). No tiene sentido depender de esa
    infraestructura para la primera entrega.
- **Fase 1 — bot con sugerencia de IA + aprobación humana (spec 033, ✅ implementada y desplegada al
  demo, 2026-08-02)**: switch "Bot de Mercado Libre" en Funciones Avanzadas, generación asíncrona de
  sugerencias con LLM, pantalla de configuración del bot. Se apoya en el modelo de datos y la vista ya
  construidos en la Fase 0. **Este es el comportamiento real hoy en el demo** — el bot sugiere, un
  humano aprueba/edita y confirma el envío.
- **Fase 2 — envío 100% autónomo (reconsiderada, reemplaza el "futura, no comprometida" original —
  ver decisiones-pendientes.md § Aprobación humana obligatoria — REABIERTA)**: el bot contesta solo,
  sin aprobación humana por mensaje, corriendo 24/7 en el VPS. Ya no acotado a "categorías muy
  acotadas y de bajo riesgo" como se planteaba originalmente — el pedido actual es que conteste en
  general, con el contexto de la empresa, desde el arranque. **Pendiente especificar formalmente**
  (`/speckit-specify` sobre esta fase) antes de implementar — no se tocó código todavía por este
  cambio de rumbo.

## Fuera de alcance (por ahora)

- Envío de mensajes salientes iniciados por el negocio (fuera del hilo de una pregunta/mensaje
  entrante) — esto es otro caso de uso distinto (marketing/proactivo) y no está pedido.
- Multi-canal (WhatsApp, Instagram, etc.) — el pedido original es específicamente Mercado Libre.
