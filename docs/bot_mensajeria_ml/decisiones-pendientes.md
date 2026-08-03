# Decisiones (cerradas 2026-08-02, antes de especificar)

## Alcance de mensajería — CERRADO

**Ambas**: Preguntas pre-venta (`/questions`, públicas, sobre una publicación) y Mensajería post-venta
(privada, ligada a una orden). Son dos APIs/modelos de datos distintos en ML, pero para el usuario del
CRM se muestran como una sola bandeja de "Mensajería".

**Historial**: se muestra el **historial completo de conversación por comprador** (hilo tipo chat), no
sólo el mensaje aislado — mejor contexto tanto para la sugerencia de IA como para la revisión humana.

## Scope de OAuth — CERRADO

Confirmado por el usuario en el DevCenter de ML: el permiso/scope de mensajería ya está habilitado y
la callback URL de notificaciones (`https://contagramdemo.devstudioweb.com/webhooks/mercadolibre`) ya
quedó configurada ahí (2026-08-02). Falta implementar el controller/ruta que la reciba — hoy no existe
en el proyecto (sólo existe el callback de OAuth, `mercadolibre.callback`, distinto de este webhook de
notificaciones).

## Dónde vive en el CRM — CERRADO

- **Sidebar**: nuevo desplegable propio **"Mensajería"** (no cuelga de Ingresos ni de Funciones
  Avanzadas).
- **Vista principal**: bandeja de conversaciones estilo chat, usando como base visual
  `template/Laravel-NexaDash-v1.0-28_May_2025/package/resources/views/chat.blade.php` del template
  NexaDash (adaptado a Blade del proyecto + DataTables/AJAX/Select2 donde corresponda, según reglas de
  diseño obligatorias del CLAUDE.md — el chat en sí es una excepción razonable a "todo con DataTables"
  porque es una vista de mensajería, no un listado tabular).
- **Activación**: tarjeta/switch **"Bot de Mercado Libre"** dentro de **Funciones Avanzadas**
  (mismo lugar que la tarjeta de conexión OAuth de ML, spec 011). Al activar el switch, aparece un
  link/acceso a una **pantalla de configuración propia** del bot (separada de "Mensajería" y de la
  tarjeta de Funciones Avanzadas) — ahí van los ajustes específicos del bot: tono/instrucciones del
  LLM, proveedor, etc.

## LLM a usar — CERRADO (default de la spec)

- **Proveedor default: OpenAI (GPT)** — GPT-4o-mini como modelo por defecto (barato, apto para
  redacción conversacional en español; el humano revisa cada sugerencia antes de enviar, así que no
  hace falta el modelo más caro). GPT-4o queda como alternativa si la calidad de 4o-mini no alcanza.
  Requiere `OPENAI_API_KEY` en `.env` de producción, llamada server-side desde el VPS vía SDK oficial
  de OpenAI para PHP/Laravel.
- Esto es un default de arranque, no una prueba empírica ya hecha — si al calibrar con ejemplos reales
  la calidad no convence, se reevalúa contra Anthropic (Claude Haiku 4.5/Sonnet 5, ver historial de
  esta conversación) sin rehacer la arquitectura: el llamado al LLM debe quedar detrás de una
  interfaz/adapter simple para poder cambiar de proveedor sin tocar el resto del sistema.
- Tono/instrucciones (system prompt): pendiente que el negocio provea ejemplos reales de respuestas
  buenas para calibrar — no bloquea especificar, se resuelve en la implementación/calibración del
  prompt.

## Volumen y urgencia real

- No se relevó un número exacto de mensajes/día. Se asume volumen bajo-moderado (negocio unipersonal);
  la arquitectura ya se diseña asumiendo VPS + colas reales (ver `infraestructura.md`), así que esto no
  bloquea especificar — sólo afecta el dimensionamiento de workers más adelante.

## Notificación al frontend

- Se define en la spec: dado que el resto del CRM usa AJAX/polling consistente y no hay pedido
  explícito de tiempo real instantáneo, arrancar con **polling simple** (consistente con el resto del
  CRM) para detectar mensajes nuevos y sugerencias listas, dejando abierta la puerta a algo más
  reactivo si la latencia percibida molesta en el uso real.

## Aprobación humana obligatoria — REABIERTA (2026-08-02, post-implementación de la spec 033)

**La decisión "borrador + aprobación humana, sin envío automático" (más abajo, en
`flujo-y-alcance.md`) queda reconsiderada.** Definición del usuario tras ver el bot funcionando en el
demo: el bot va a correr en el VPS **24/7** y **va a contestar solo, ya desde el arranque** (no como
"Fase 2" acotada a categorías de bajo riesgo), usando el contexto de la empresa (tono, historial,
producto/venta) como ya arma `GeneradorDeSugerencias`.

**Qué NO cambió todavía**: el código desplegado (specs 032/033) sigue exactamente como está —
`ml_sugerencias` genera un borrador, y sólo se envía a través de `EnvioRespuestaMercadoLibre::enviar()`
cuando un usuario humano confirma desde `/mensajeria`. La spec 033 y su FR-009/SC-003 ("0% de
sugerencias enviadas sin aprobación humana") siguen describiendo fielmente el comportamiento **actual**
del sistema en producción/demo — no se tocó el código todavía, sólo se documenta acá el cambio de
rumbo para la próxima fase.

**Qué implica para la próxima spec** (a especificar formalmente con `/speckit-specify` cuando se
retome, no implementado por esta nota):

- Un nuevo flujo de envío que llame a `ClienteMercadoLibre`/`EnvioRespuestaMercadoLibre` directamente
  desde `GenerarSugerenciaMercadoLibre` (o un Job nuevo que lo suceda) cuando la sugerencia está lista,
  sin esperar clic humano — sigue reutilizando el mismo guard de doble respuesta e índice único de
  `ml_respuestas_enviadas` (no se reabre esa parte, sigue siendo la garantía real contra duplicados).
  `ml_sugerencia_id`/`sugerencia_editada` en la auditoría pasarían a reflejar "enviada por el bot, sin
  edición humana" como caso normal, no como excepción.
- Requiere el VPS con `queue:work` real corriendo **24/7** de forma estable — a diferencia de la Fase 1
  (donde `QUEUE_CONNECTION=sync` alcanzaba para demo), acá si el worker se cae, el negocio deja de
  responder mensajes reales sin que nadie se entere salvo que exista el módulo de Notificaciones ya
  anotado como pendiente (`documentacion_principal_crm.md` §7).
- El switch "Bot de Mercado Libre" en Funciones Avanzadas pasaría a significar "el bot contesta
  solo", no "el bot sugiere" — ese cambio de semántica tiene que quedar explícito en la nueva spec
  (título, descripción de la tarjeta, textos de la pantalla de configuración), no asumirse implícito.
- Revisitar `riesgos-politicas-ml.md`: el riesgo de política de ML (moderación, contenido inapropiado,
  `blocked_by_conversation_started_by_seller`, etc.) que hoy mitiga la revisión humana pasa a depender
  enteramente de las instrucciones de tono + lo que ML modere automáticamente — sin ese filtro humano,
  conviene revisar si hace falta algún control adicional (ej. lista de categorías de pregunta excluidas,
  límite de confianza antes de enviar) antes de prender esto con clientes reales.
- Sigue pendiente confirmar con el negocio: ¿todas las conversaciones entrantes, o sólo un subconjunto
  para arrancar (ej. sólo Preguntas, no post-venta; o sólo si hay producto vinculado con datos
  confiables)? El pedido del usuario fue "contestará solo... con el contexto de la empresa" sin acotar
  categorías, pero vale confirmarlo explícitamente en la spec nueva antes de implementar, no asumirlo.
