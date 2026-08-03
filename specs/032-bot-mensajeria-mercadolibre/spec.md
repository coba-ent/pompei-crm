# Feature Specification: Mensajería de Mercado Libre (lectura y respuesta manual)

**Feature Branch**: `032-bot-mensajeria-mercadolibre`

**Created**: 2026-08-02

**Status**: Draft

**Input**: User description: "Traer a un solo lugar Preguntas pre-venta y Mensajería post-venta de Mercado Libre en una bandeja tipo chat con historial completo por comprador; recepción vía webhook de notificaciones de ML (todavía no existe el endpoint); un humano responde manualmente desde el CRM (sin IA todavía — el bot con sugerencias de IA se especifica aparte, en una spec futura, una vez migrado el VPS); nuevo desplegable 'Mensajería' en el sidebar con vista tipo chat (referencia visual: template NexaDash `chat.blade.php`); envío real de la respuesta vía API de Mercado Libre con auditoría de qué se envió y quién lo hizo."

## Contexto y fuentes

Esta spec construye una parte de lo que la spec `011-mercadolibre-conexion-oauth` dejó explícitamente
reservado ("preguntas, mensajería" quedan fuera de esa spec, para una spec posterior). Se apoya en la
conexión OAuth de Mercado Libre ya construida (app propia del negocio en el DevCenter, con permisos de
lectura y escritura) y asume que el scope de mensajería ya está habilitado ahí (confirmado por el
usuario, 2026-08-02).

**Alcance recortado a propósito (2026-08-02)**: la idea original del bot con sugerencias de IA
(`docs/bot_mensajeria_ml/`) se implementa en dos etapas. Esta spec cubre únicamente la **Fase 0**: leer
los mensajes de Mercado Libre en una bandeja unificada y responderlos **manualmente** desde el CRM, sin
ninguna generación de IA. La Fase 1 (bot con sugerencias de IA, switch en Funciones Avanzadas, pantalla
de configuración del bot) queda para una **spec separada y futura**, a especificarse recién cuando el
VPS (con colas reales) esté migrado — no bloquea ni depende de esta spec, que se implementa contra el
hosting actual. Ver `docs/bot_mensajeria_ml/flujo-y-alcance.md` para el detalle de fases.

**Fuente de contexto y decisiones ya cerradas**: `docs/bot_mensajeria_ml/` (`README.md`,
`flujo-y-alcance.md`, `infraestructura.md`, `decisiones-pendientes.md`, `riesgos-politicas-ml.md`) —
documentación de diseño armada antes de esta spec. Esta spec no reabre esas decisiones; toma de ahí
sólo lo que corresponde a la Fase 0.

**Fuente de dominio**: `docs/documentacion_principal_crm.md`, `docs/modelo_datos.md` — se actualizan
tras esta spec con las entidades nuevas (ver sección Key Entities).

**Divergencia respecto del principio de fidelidad estructural a Contagram**: este módulo no existe en
Contagram real — es una funcionalidad nueva del negocio, no una reconstrucción de una pantalla
existente de Contagram. La estructura de UI se define en esta spec según lo acordado con el usuario
(desplegable propio "Mensajería"), no contra un informe de relevamiento.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Ver todos los mensajes de compradores en un solo lugar (Priority: P1)

Como responsable de atención al cliente del negocio, entro a "Mensajería" en el sidebar y veo una
bandeja con todas las conversaciones de compradores de Mercado Libre — tanto preguntas públicas sobre
publicaciones como mensajes privados de post-venta — cada una mostrando el comprador, la publicación u
orden relacionada, un preview del último mensaje, y si está pendiente de respuesta. Al abrir una
conversación veo el historial completo del intercambio con ese comprador, ordenado cronológicamente.

**Why this priority**: Es la base de todo el módulo — sin esto no hay dónde ver ni responder nada.

**Independent Test**: Puede probarse completamente simulando la llegada de una pregunta y un mensaje
post-venta (vía el webhook) y verificando que ambos aparecen en la bandeja de "Mensajería" con su
información asociada correcta.

**Acceptance Scenarios**:

1. **Given** un comprador hizo una pregunta pública sobre una publicación vinculada a un producto del
   CRM, **When** Mercado Libre notifica esa pregunta, **Then** aparece en la bandeja de "Mensajería"
   asociada al producto y al comprador correspondiente.
2. **Given** un comprador envió un mensaje de post-venta sobre una orden ya ingresada al CRM, **When**
   Mercado Libre notifica ese mensaje, **Then** aparece en la bandeja asociada a esa orden/venta.
3. **Given** una conversación con varios mensajes previos de ida y vuelta con el mismo comprador,
   **When** abro esa conversación, **Then** veo todos los mensajes anteriores en orden cronológico, no
   sólo el último.
4. **Given** estoy en la bandeja de "Mensajería", **When** llega un mensaje nuevo, **Then** lo veo
   reflejado sin necesidad de recargar la página manualmente (vía actualización periódica).

---

### User Story 2 - Responder manualmente con aprobación humana y auditoría (Priority: P1)

Como responsable de atención al cliente, abro una conversación, redacto la respuesta y confirmo el
envío — recién en ese momento se manda la respuesta real al comprador a través de Mercado Libre. Queda
registrado qué se envió, cuándo y quién lo hizo.

**Why this priority**: Es el cierre del flujo — sin esto el módulo no sirve para nada operativamente,
tan crítico como la User Story 1.

**Independent Test**: Puede probarse abriendo una conversación, escribiendo un texto de respuesta,
confirmando el envío, y verificando que la respuesta llegó a Mercado Libre (o al mock de la API en
pruebas) exactamente con el texto confirmado, quedando registrada en la auditoría.

**Acceptance Scenarios**:

1. **Given** una conversación pendiente de respuesta, **When** el usuario escribe un texto y confirma el
   envío, **Then** se envía ese texto a Mercado Libre y la conversación pasa a estado "respondida".
2. **Given** una respuesta fue enviada, **When** el usuario o un auditor revisa el historial de esa
   conversación, **Then** puede ver qué texto se envió, cuándo y quién confirmó el envío.
3. **Given** el envío a Mercado Libre falla (error de red o de la API), **When** el usuario confirma el
   envío, **Then** el sistema muestra un error claro y la conversación queda en estado "pendiente",
   sin registrar un envío exitoso falso.
4. **Given** dos usuarios del negocio abren la misma conversación pendiente al mismo tiempo, **When**
   uno de los dos confirma el envío primero, **Then** el segundo intento se rechaza indicando que la
   conversación ya fue respondida.

---

### Edge Cases

- ¿Qué pasa si llega una pregunta o mensaje sobre una publicación/orden que **no** está vinculada a
  ningún producto del CRM? La conversación se muestra igual en la bandeja, sólo sin el dato de contexto
  de producto asociado.
- ¿Qué pasa si dos personas del negocio intentan responder la misma conversación al mismo tiempo? Gana
  el primer envío confirmado; el segundo intento se rechaza indicando que la conversación ya fue
  respondida, para evitar mandar dos respuestas contradictorias.
- ¿Qué pasa si Mercado Libre notifica un mensaje duplicado (reintento de webhook)? El sistema no debe
  crear una conversación ni un mensaje duplicado.
- ¿Qué pasa si el comprador borra o Mercado Libre marca como eliminada una pregunta ya respondida? Queda
  reflejado el estado en la bandeja, sin bloquear la vista de las demás conversaciones.
- ¿Qué pasa si se intenta enviar una respuesta que Mercado Libre rechaza por su propia moderación de
  contenido (por ejemplo, datos de contacto externos)? El sistema muestra el motivo del rechazo devuelto
  por Mercado Libre y permite corregir el texto y reintentar, sin perder lo ya escrito.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: El sistema DEBE mostrar, en una sección propia "Mensajería" del sidebar, una bandeja
  unificada de conversaciones que incluya tanto preguntas pre-venta como mensajes de post-venta de
  Mercado Libre.
- **FR-002**: El sistema DEBE agrupar los mensajes de un mismo comprador (o de una misma
  publicación/orden, según corresponda) en una conversación con historial completo visible en orden
  cronológico.
- **FR-003**: El sistema DEBE recibir preguntas y mensajes nuevos de Mercado Libre mediante las
  notificaciones (webhook) que la cuenta ya tiene configuradas, sin requerir que el usuario refresque
  manualmente para que un mensaje entrante quede registrado.
- **FR-004**: El sistema DEBE evitar procesar dos veces una misma notificación de Mercado Libre
  (idempotencia ante reintentos de webhook), sin crear conversaciones ni mensajes duplicados.
- **FR-005**: El sistema DEBE permitir al usuario redactar y enviar una respuesta manual desde la
  conversación, sin depender de ninguna sugerencia automática.
- **FR-006**: El sistema DEBE registrar, para cada respuesta enviada, el texto final enviado, quién
  confirmó el envío, y cuándo.
- **FR-007**: El sistema DEBE impedir que se envíen dos respuestas a la misma pregunta/mensaje ya
  respondido, informando claramente al segundo usuario que intente hacerlo.
- **FR-008**: El sistema DEBE mostrar un error claro al usuario si el envío de la respuesta a Mercado
  Libre falla, sin registrar el envío como exitoso.
- **FR-009**: El sistema DEBE notificar a la vista de "Mensajería" sobre mensajes nuevos de forma
  periódica, sin requerir que el usuario recargue la página manualmente.
- **FR-010**: El sistema DEBE restringir el acceso a "Mensajería" y la posibilidad de responder según
  permisos de usuario, siguiendo el mismo esquema de permisos que el resto del CRM (ver/responder por
  separado).

### Key Entities *(include if feature involves data)*

- **Conversación de Mercado Libre**: representa el hilo de intercambio con un comprador. Para
  Preguntas pre-venta, se agrupa por comprador + publicación (todas las preguntas de un mismo
  comprador sobre la misma publicación forman una conversación, aunque Mercado Libre las trate como
  preguntas independientes puertas adentro). Para Mensajería post-venta, se agrupa por orden/venta, que
  es como Mercado Libre ya la agrupa nativamente. Incluye el comprador, la referencia a la
  publicación/producto o a la venta del CRM cuando exista vinculación, y su estado (pendiente de
  respuesta / respondida).
- **Mensaje**: cada entrada individual dentro de una conversación — quién lo escribió (comprador o
  negocio), el texto, el momento en que se envió/recibió, y si fue una respuesta enviada por el negocio
  o un mensaje recibido del comprador.
- **Envío/Respuesta confirmada**: el registro de auditoría de una respuesta efectivamente enviada al
  comprador — texto final, usuario que confirmó, y fecha/hora.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: El 100% de las preguntas y mensajes de post-venta que Mercado Libre notifica a la cuenta
  quedan visibles en la bandeja de "Mensajería" del CRM, sin necesidad de revisar la app de Mercado
  Libre por separado.
- **SC-002**: 0% de respuestas enviadas al comprador sin confirmación explícita de un usuario humano.
- **SC-003**: 0% de conversaciones o mensajes duplicados generados por reintentos de notificación de
  Mercado Libre, verificado sobre un período de uso real de al menos dos semanas.
- **SC-004**: 100% de las respuestas enviadas quedan auditadas con texto, usuario y fecha/hora
  recuperables desde el CRM.

## Assumptions

- El scope de OAuth de Mercado Libre necesario para leer y responder mensajería ya está habilitado en
  la aplicación del negocio en el DevCenter (confirmado por el usuario); si en la implementación se
  detecta que el token actual no lo incluye, hace falta re-autorizar la conexión existente (spec 011),
  lo cual no requiere volver a especificar ese flujo.
- La actualización de la bandeja de "Mensajería" se hace por actualización periódica (polling),
  consistente con el resto del CRM, no en tiempo real instantáneo.
- El acceso a "Mensajería" se controla con el mismo esquema de permisos que ya usa el resto del CRM
  (permisos por pantalla/acción: ver y responder por separado), sin necesidad de un modelo de permisos
  nuevo.
- Esta spec se implementa contra el hosting actual (sin depender del VPS ni de colas reales) —
  simplemente encolar en modo síncrono es aceptable, dado que no hay ningún llamado a un proveedor
  externo lento (LLM) en esta fase; el procesamiento del webhook es liviano.
- Quedan fuera de alcance de esta spec: cualquier generación de sugerencia de respuesta por IA, el
  switch/tarjeta "Bot de Mercado Libre" en Funciones Avanzadas y su pantalla de configuración (spec
  futura, Fase 1, después de migrar el VPS); el envío de mensajes salientes iniciados por el negocio
  fuera de un hilo ya existente; canales de mensajería fuera de Mercado Libre (WhatsApp, Instagram,
  etc.).
