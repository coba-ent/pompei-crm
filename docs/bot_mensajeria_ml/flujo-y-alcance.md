# Flujo propuesto y alcance por fases

## Flujo (borrador, a validar en la spec)

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

**Decisión ya tomada con el usuario**: arranca como *borrador + aprobación humana*, no envío
automático. Envío 100% autónomo queda descartado para la v1 y se replantea (si acaso) más adelante,
una vez que el flujo con humano en el medio esté probado y sea confiable.

## Fases de alcance (propuesta, a confirmar)

- **Fase 0 — sólo lectura**: traer los mensajes de ML al panel, verlos centralizados, sin generación
  de IA todavía. Sirve para validar el modelo de datos, la vinculación con productos/ventas, y la
  vista, sin la complejidad del LLM ni de las colas.
- **Fase 1 — sugerencia con IA + aprobación humana**: se suma el job asíncrono que genera la
  respuesta sugerida, editable antes de enviar. Necesita el VPS y colas reales.
- **Fase 2 (futura, no comprometida)** — automatizaciones parciales: por ejemplo auto-responder sólo
  ciertas categorías de preguntas muy acotadas y de bajo riesgo (ej. "¿tienen stock?", si el stock es
  data estructurada y confiable), mantiniendo aprobación humana para todo lo demás. Esto **no se
  especifica ahora** — es sólo para que quede anotado como posible horizonte y no se cierre el diseño
  de forma que lo haga imposible después.

## Fuera de alcance (por ahora)

- Envío de mensajes salientes iniciados por el negocio (fuera del hilo de una pregunta/mensaje
  entrante) — esto es otro caso de uso distinto (marketing/proactivo) y no está pedido.
- Multi-canal (WhatsApp, Instagram, etc.) — el pedido original es específicamente Mercado Libre.
