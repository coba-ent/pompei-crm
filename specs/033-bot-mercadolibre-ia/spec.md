# Feature Specification: Bot de Mercado Libre con sugerencias de IA (Fase 1)

**Feature Branch**: `033-bot-mercadolibre-ia`

**Created**: 2026-08-02

**Status**: Draft

**Input**: User description: "Bot de Mercado Libre con sugerencias de respuesta generadas por IA — Fase 1, construida sobre la spec 032-bot-mensajeria-mercadolibre ya implementada (bandeja 'Mensajería', tablas ml_conversaciones/ml_mensajes/ml_respuestas_enviadas, respuesta manual con auditoría). Switch 'Bot de Mercado Libre' en Funciones Avanzadas que habilita una pantalla de configuración propia (tono/instrucciones de la IA); cuando está activo, se genera automáticamente una sugerencia de respuesta por mensaje entrante, de forma asíncrona, usando el mensaje, el historial de la conversación y datos de producto/venta relacionados; cuando está apagado, la sugerencia se puede pedir bajo demanda; la sugerencia se muestra como borrador editable dentro del flujo de respuesta ya existente, nunca se envía sola; proveedor de IA default OpenAI GPT-4o-mini detrás de una interfaz reemplazable; depende de que el VPS con colas reales ya esté migrado para desplegarse a producción."

## Contexto y fuentes

Esta spec es la **Fase 1** anunciada en `specs/032-bot-mensajeria-mercadolibre/spec.md` (que cubrió la
Fase 0: bandeja "Mensajería", recepción vía webhook, y respuesta manual con auditoría — ya implementada
por el usuario en paralelo a esta spec). Esta spec **no reimplementa nada de la Fase 0**: se apoya en
sus tablas (`ml_conversaciones`, `ml_mensajes`, `ml_respuestas_enviadas`), su vista ("Mensajería"), su
webhook de recepción, y su flujo de aprobación humana — y agrega encima la generación de sugerencias
por IA.

**Fuente de contexto y decisiones ya cerradas**: `docs/bot_mensajeria_ml/` (`README.md`,
`flujo-y-alcance.md` §Fase 1, `infraestructura.md`, `decisiones-pendientes.md`,
`riesgos-politicas-ml.md`). Esta spec no reabre esas decisiones (alcance, proveedor de IA default,
aprobación humana obligatoria); las traduce a requerimientos verificables para esta fase.

**Fuente de dominio**: `docs/documentacion_principal_crm.md` §6.5 (módulo Mensajería, ya actualizado
con la nota de que esta Fase 1 quedaba pendiente) y `docs/modelo_datos.md` §14 — se actualizan tras
esta spec con las entidades nuevas.

**Divergencia respecto del principio de fidelidad estructural a Contagram**: igual que la spec 032,
este módulo no existe en Contagram real — divergencia ya autorizada por el usuario.

**Dependencia dura de infraestructura**: a diferencia de la spec 032 (que corre síncrono en el hosting
compartido actual), esta Fase 1 depende de que el VPS con colas reales (`QUEUE_CONNECTION=database` +
`queue:work` + supervisor) esté migrado **antes de desplegarse a producción** — ver
`docs/bot_mensajeria_ml/infraestructura.md`. El desarrollo y testing pueden avanzar en local sin el VPS
(el Job corre igual con `QUEUE_CONNECTION=sync`, sin cambiar código).

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Activar el bot y configurar su tono (Priority: P1)

Como responsable del negocio, entro a Configuración & Ajustes → Funciones Avanzadas, activo el switch
"Bot de Mercado Libre", y accedo desde ahí a una pantalla de configuración propia donde defino el
tono/instrucciones que sigue la IA al redactar sugerencias.

**Why this priority**: Es el punto de entrada de toda la feature — sin el switch activo y configurado
no hay generación automática de sugerencias.

**Independent Test**: Puede probarse activando el switch, entrando a la pantalla de configuración,
guardando instrucciones de tono, y verificando que quedan persistidas.

**Acceptance Scenarios**:

1. **Given** el switch "Bot de Mercado Libre" está apagado, **When** el usuario lo activa, **Then**
   aparece un acceso a la pantalla de configuración del bot (mismo patrón que las tarjetas de
   Mercado Libre/Tiendanube ya existentes en Funciones Avanzadas).
2. **Given** el usuario está en la pantalla de configuración del bot, **When** modifica las
   instrucciones de tono y guarda, **Then** el cambio queda persistido y se aplica a las sugerencias
   generadas después de guardar.
3. **Given** el switch está apagado, **When** el usuario entra a la pantalla de configuración, **Then**
   puede configurar igual, pero el sistema deja claro que el bot no está generando sugerencias
   automáticamente mientras esté apagado.

---

### User Story 2 - Recibir una sugerencia de respuesta generada por IA (Priority: P1)

Como responsable de atención al cliente, cuando llega un mensaje nuevo a una conversación de
"Mensajería" y el bot está activado, encuentro ya preparada una sugerencia de respuesta al abrir la
conversación, redactada en el tono configurado y usando el contexto disponible (el mensaje, el
historial de la conversación, y los datos de producto/venta ya vinculados).

**Why this priority**: Es el valor central de esta Fase 1 — sin esto, activar el switch no genera
ningún beneficio percibido.

**Independent Test**: Puede probarse activando el switch, simulando la llegada de un mensaje nuevo
(mismo webhook de la spec 032), y verificando que — tras el procesamiento asíncrono — la conversación
muestra un texto de sugerencia, sin que se haya enviado nada todavía.

**Acceptance Scenarios**:

1. **Given** el switch está activado, **When** llega un mensaje nuevo de un comprador, **Then** el
   sistema genera automáticamente una sugerencia de respuesta asociada a ese mensaje.
2. **Given** el switch está desactivado, **When** llega un mensaje nuevo, **Then** no se genera
   sugerencia automática, pero el usuario puede pedirla manualmente desde la conversación.
3. **Given** una conversación con historial previo y datos de producto/venta vinculados (stock, precio,
   estado de envío), **When** se genera la sugerencia, **Then** el texto es coherente con esos datos.
4. **Given** la generación está en curso, **When** el usuario mira la conversación, **Then** ve un
   estado de "generando sugerencia" en vez de un vacío sin explicación.
5. **Given** el proveedor de IA falla o tarda demasiado, **When** el usuario mira la conversación,
   **Then** ve un estado de error de generación y puede responder manualmente sin esperar al bot.

---

### User Story 3 - Enviar una respuesta a partir de la sugerencia, con auditoría (Priority: P1)

Como responsable de atención al cliente, reviso la sugerencia generada, la envío tal cual o la edito
antes de confirmar el envío — usando el mismo flujo de respuesta y aprobación humana ya construido en
la spec 032 — y queda registrado si la respuesta enviada partió de una sugerencia y si fue editada.

**Why this priority**: Cierra el circuito de valor de esta fase — sin la integración con el envío ya
existente, la sugerencia sería sólo texto sin poder actuar sobre él.

**Independent Test**: Puede probarse generando una sugerencia, enviándola sin cambios una vez, editada
otra vez, y verificando en ambos casos que la auditoría (`ml_respuestas_enviadas`) refleja
correctamente el origen y si hubo edición.

**Acceptance Scenarios**:

1. **Given** una conversación con una sugerencia generada, **When** el usuario la envía tal cual sin
   editarla, **Then** el sistema registra el envío indicando que se usó la sugerencia sin cambios.
2. **Given** una conversación con una sugerencia generada, **When** el usuario edita el texto antes de
   enviar, **Then** se envía el texto editado y el sistema registra que la sugerencia fue modificada
   antes del envío.
3. **Given** una conversación sin sugerencia (switch apagado y no solicitada), **When** el usuario
   escribe una respuesta desde cero, **Then** se envía igual que en la spec 032, sin ninguna referencia
   a una sugerencia de IA.
4. **Given** cualquiera de los casos anteriores, **When** se confirma el envío, **Then** se sigue
   aplicando sin excepción el guard de doble respuesta y el manejo de error de envío ya construidos en
   la spec 032 (no se reabre ni se modifica ese comportamiento).

---

### Edge Cases

- ¿Qué pasa si el switch se desactiva mientras una sugerencia ya está en curso de generación? La
  generación en curso termina normalmente (no se cancela a mitad de camino); a partir de ese momento no
  se generan nuevas sugerencias automáticas.
- ¿Qué pasa si se pide una sugerencia bajo demanda para una conversación que ya tiene una sugerencia
  generada previamente? Se reemplaza por la nueva (no se acumulan múltiples sugerencias vigentes por
  mensaje).
- ¿Qué pasa si se confirma el envío de una respuesta mientras la sugerencia todavía está "generando"?
  El usuario puede igual escribir y enviar una respuesta propia sin esperar — la sugerencia en curso no
  bloquea el envío manual.
- ¿Qué pasa si el proveedor de IA devuelve una sugerencia con contenido inapropiado o que viola las
  políticas de Mercado Libre (por ejemplo, ofrece compartir datos de contacto externos)? La sugerencia
  se muestra igual como borrador — la responsabilidad de no enviarla tal cual es del flujo de revisión
  humana ya existente (spec 032); no se agrega en esta fase un filtro de contenido automático adicional
  (ver Assumptions).
- ¿Qué pasa si se activa el switch pero no hay ninguna instrucción de tono configurada todavía? El bot
  genera sugerencias igual, con un tono neutro por defecto, hasta que se configure algo específico.
- ¿Qué pasa si el proveedor de IA devuelve una respuesta vacía o desproporcionadamente larga? El Job la
  trata como fallo de generación (`estado=error`, `error_mensaje` explicando el motivo) en vez de
  guardarla como `lista` — no se muestra un borrador vacío ni un texto desmedido como sugerencia válida.
  El límite de "desmedido" es el que realmente aplica Mercado Libre al enviar: **350 caracteres para
  mensajería post-venta** (`seller_max_message_length`, confirmado contra la documentación oficial de
  ML, charset ISO-8859-1 + lista cerrada de emoticones — cualquier exceso es rechazado por ML con
  `limit_exceeded` recién al intentar enviar, no antes). Para Preguntas (`/answers`) la documentación no
  confirma un tope explícito; se aplica el mismo límite de forma conservadora al validar la sugerencia
  generada, para no descubrir el rechazo recién en el momento de enviar la respuesta ya aprobada por el
  usuario.
- ¿Cuánto tiempo espera el sistema la respuesta del proveedor de IA antes de considerarla fallida? El
  Job aplica un único intento con timeout (sin reintentos automáticos); si vence, queda en `estado=error`
  igual que cualquier otro fallo del proveedor — el usuario puede pedir la sugerencia de nuevo bajo
  demanda (FR-006) sin esperar un reintento automático.
- ¿Qué pasa si el switch se desactiva mientras hay varias sugerencias `generando` en simultáneo (para
  varios mensajes distintos)? Aplica el mismo criterio por cada una individualmente: cada generación en
  curso termina normalmente sin cancelarse; a partir de ese momento no se despachan nuevas generaciones
  automáticas para ningún mensaje nuevo.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: El sistema DEBE proveer, dentro de Funciones Avanzadas, una tarjeta/switch "Bot de
  Mercado Libre" que controle si la generación automática de sugerencias está activa.
- **FR-002**: Al activar el switch, el sistema DEBE dar acceso a una pantalla de configuración propia
  del bot donde se pueda editar el tono/instrucciones que sigue la IA al generar sugerencias.
- **FR-003**: El sistema DEBE aplicar los cambios guardados en la configuración del bot a las
  sugerencias generadas después de guardar ese cambio.
- **FR-004**: Cuando la generación automática está activada, el sistema DEBE generar una sugerencia de
  respuesta para cada mensaje entrante nuevo (recibido por el webhook de la spec 032), usando como
  contexto el mensaje, el historial de la conversación, y los datos de producto/venta relacionados que
  estén disponibles.
- **FR-005**: La generación de la sugerencia DEBE ejecutarse de forma asíncrona respecto de la
  recepción del mensaje, sin bloquear ni demorar la respuesta del webhook.
- **FR-006**: Cuando la generación automática está desactivada, el sistema DEBE permitir al usuario
  solicitar una sugerencia de respuesta bajo demanda para una conversación puntual.
- **FR-007**: El sistema DEBE mostrar el estado de la sugerencia (generando / lista / con error) en la
  conversación correspondiente.
- **FR-008**: El sistema DEBE notificar al frontend cuando una sugerencia queda lista, mediante el
  mismo mecanismo de actualización periódica ya usado por la bandeja de "Mensajería" (spec 032).
- **FR-009**: El sistema DEBE permitir al usuario enviar la sugerencia tal cual, editarla antes de
  enviar, o ignorarla y escribir una respuesta propia — reutilizando el flujo de envío y aprobación
  humana ya construido en la spec 032, sin duplicar ni modificar ese comportamiento (guard de doble
  respuesta, manejo de error de envío, etc. se aplican sin cambios).
- **FR-010**: El sistema DEBE registrar, para cada respuesta enviada, si partió de una sugerencia de IA
  y si fue editada antes de enviarse (ampliando la auditoría ya existente de la spec 032).
- **FR-011**: El sistema DEBE seguir permitiendo responder conversaciones manualmente aunque la
  generación de sugerencias falle, no esté disponible, o el switch esté apagado.
- **FR-011a**: El sistema DEBE tratar como error de generación (no como sugerencia válida) tanto una
  respuesta vacía del proveedor de IA como una que exceda **350 caracteres** (el límite real que aplica
  Mercado Libre a los mensajes de post-venta del vendedor, aplicado también a Preguntas por no haber un
  tope distinto confirmado), y DEBE aplicar un timeout único sin reintentos automáticos a la llamada al
  proveedor, registrando el motivo en `error_mensaje`. La instrucción de largo máximo DEBE formar parte
  del prompt/instrucciones que recibe el proveedor de IA (no sólo una validación posterior), para que el
  texto generado ya venga dentro del límite en la mayoría de los casos.
- **FR-012**: El sistema DEBE restringir el acceso a la configuración del bot según permisos de
  usuario, siguiendo el mismo esquema de permisos que el resto del CRM (mismo permiso que gobierna
  Funciones Avanzadas).

### Key Entities *(include if feature involves data)*

- **Sugerencia de respuesta**: el borrador generado por IA para un mensaje entrante — mensaje que la
  originó, texto sugerido, estado (generando / lista / con error), y cuándo se generó.
- **Configuración del bot**: parámetros de comportamiento del bot — tono/instrucciones que sigue la IA
  al redactar sugerencias (el flag de activo/inactivo vive en la Función Avanzada correspondiente, no
  acá, para no duplicar fuente de verdad).
- **Envío/Respuesta confirmada** (existente, spec 032, se amplía): gana la relación con la sugerencia
  que la originó, si hubo alguna, y si fue editada antes de enviarse.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Con el bot activado, al menos el 90% de los mensajes entrantes tienen una sugerencia de
  respuesta disponible dentro de los 2 minutos de haber llegado.
- **SC-002**: El tiempo que le toma al responsable de atención al cliente redactar y enviar una
  respuesta a un mensaje con sugerencia disponible se reduce de forma perceptible respecto de
  escribirla desde cero (medible cualitativamente por el usuario del negocio tras una semana de uso).
- **SC-003**: 0% de sugerencias enviadas al comprador sin pasar por el flujo de aprobación humana ya
  existente — ninguna sugerencia sale sola (mismo invariante que la spec 032, ahora también válido
  para respuestas que parten de una sugerencia).
- **SC-004**: 100% de las respuestas enviadas que partieron de una sugerencia quedan auditadas
  indicando si fueron editadas o no.

## Assumptions

- El proveedor de IA por defecto es OpenAI (GPT-4o-mini), configurado detrás de una interfaz que
  permite reemplazarlo más adelante sin rediseñar el resto del sistema — ver
  `docs/bot_mensajeria_ml/decisiones-pendientes.md`. La elección concreta del proveedor/modelo es un
  detalle de implementación, no de esta especificación funcional.
- Esta spec depende de que el VPS con colas reales esté migrado antes de desplegarse a producción; el
  desarrollo y testing pueden avanzar en local sin esa infraestructura (Job síncrono en local).
- No se agrega en esta fase ningún filtro de contenido automático sobre lo que devuelve el proveedor de
  IA más allá de las instrucciones de tono configuradas — la responsabilidad de no enviar contenido
  inapropiado recae en la revisión humana ya obligatoria (spec 032).
- El acceso a la configuración del bot se controla con el mismo esquema de permisos que ya usa el resto
  de Funciones Avanzadas, sin necesidad de un permiso nuevo específico para esto.
- Los datos de producto/venta usados como contexto (stock, precio, estado de envío) son los que ya
  están vinculados en `ml_conversaciones` desde la spec 032; si no hay vinculación, la sugerencia se
  genera con el contexto disponible, sin ese detalle.
- Quedan fuera de alcance de esta spec: el envío 100% automático de sugerencias sin revisión humana; la
  auto-respuesta parcial para categorías acotadas de preguntas de bajo riesgo (posible fase futura, no
  comprometida); y cualquier canal de mensajería fuera de Mercado Libre.
- No se define en esta spec un tope de gasto ni una alerta de costo del proveedor de IA — dado el
  volumen bajo-moderado esperado (ver `docs/bot_mensajeria_ml/decisiones-pendientes.md`), se acepta
  como riesgo operativo menor a monitorear manualmente al principio; se puede sumar como mejora
  posterior si el volumen real lo justifica.
- Si el switch "Bot de Mercado Libre" se activa en producción antes de que el VPS tenga `queue:work`
  corriendo, las sugerencias quedan encoladas sin generarse (no se pierden, no fallan ruidosamente)
  hasta que el worker esté activo — comportamiento aceptable dado que el resto del sistema (bandeja,
  respuesta manual) sigue funcionando igual mientras tanto (FR-011).
