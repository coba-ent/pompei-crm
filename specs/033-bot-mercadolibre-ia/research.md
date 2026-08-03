# Research: Bot de Mercado Libre con sugerencias de IA (Fase 1)

## R1 — Switch del bot: reutilizar `funciones_avanzadas`, no un flag nuevo

**Decision**: la activación/desactivación del bot vive en `funciones_avanzadas` (fila nueva
`clave='mercadolibre_bot'`, columnas `activa` y `ruta_configuracion` ya existentes en ese modelo), no en
una columna propia de `ml_bot_configuracion`.

**Rationale**: es exactamente el mecanismo que ya usan `mercadolibre` y `tiendanube`
(`database/seeders/FuncionAvanzadaSeeder.php`): `disponible`, `activa`, `ruta_configuracion` apuntando a
la pantalla de configuración. El sidebar/Funciones Avanzadas ya sabe renderizar el link condicional
(`@if (\App\Models\FuncionAvanzada::where('clave', '...')->value('activa'))`). Reusarlo evita una
segunda fuente de verdad para "¿está prendido el bot?".

**Alternatives considered**: un booleano en `ml_bot_configuracion` — se descarta, duplicaría el patrón
ya establecido y complicaría el guard que el webhook necesita consultar (tendría que ir a buscar la
config en vez de reusar la misma consulta que ya hace `FuncionAvanzada`).

## R2 — Dónde se dispara la generación de la sugerencia

**Decision**: `MercadoLibreMensajeriaWebhookController` (spec 032), después de persistir el mensaje vía
`RecepcionMensajeMercadoLibre`, chequea `FuncionAvanzada::where('clave', 'mercadolibre_bot')->value('activa')`
y, si está activo, despacha `GenerarSugerenciaMercadoLibre::dispatch($mensaje)`.

**Rationale**: mantiene el webhook respondiendo rápido (FR-005) — `dispatch()` sólo encola, no ejecuta.
Reutiliza el punto de entrada ya existente en vez de crear un segundo listener sobre la misma
notificación de ML.

**Alternatives considered**: un Job/Observer sobre la creación de `MercadoLibreMensaje` (Eloquent
`created` event) — funcionalmente equivalente, pero se descarta porque dispersa la lógica de "¿cuándo
generar sugerencia?" fuera del flujo explícito del webhook, dificultando trazar el comportamiento.

## R3 — Contexto para la sugerencia

**Decision**: `GenerarSugerenciaMercadoLibre` (Job) arma el contexto leyendo:
`$mensaje->conversacion->mensajes` (historial completo, ya ordenable por `enviado_en`),
`$mensaje->conversacion->publicacionProducto->producto` (stock/precio, si hay vinculación) y
`$mensaje->conversacion->orden` (estado de envío, si es post-venta) — todo ya modelado por la spec 032,
sin tablas ni joins nuevos.

**Rationale**: exactamente los datos que la spec 033 pide como contexto (FR-004) y que ya están
disponibles en el modelo de datos de la Fase 0; no hace falta ampliar `ml_conversaciones`.

**Alternatives considered**: pedir el detalle de producto/venta en tiempo real a la API de ML en vez de
leer lo ya vinculado en el CRM — se descarta, es más lento y el dato del CRM (stock/precio actual) es
la fuente de verdad para lo que el negocio quiere ofrecer, no lo que dice la publicación de ML.

**Corrección tras revisar el código real de la spec 032** (`MercadoLibreOrden`, verificado 02/08/2026):
no existe una columna dedicada de "estado de envío" en `ml_ordenes` — el modelo tiene `estado_ml` /
`estado_orden` (conversión al CRM), pero el detalle de envío (tracking, shipping status) sólo está
disponible dentro de la columna `payload` (json crudo de la orden, cast `array`). El Job (T016) debe
leer `$mensaje->conversacion->orden?->payload['shipping'] ?? null` (o el path real dentro del payload,
a confirmar contra un payload real al implementar) en vez de asumir un accessor limpio tipo
`$orden->estado_envio`.

## R4 — Integración con el envío ya existente (no rediseñar `EnvioRespuestaMercadoLibre`)

**Decision**: `EnvioRespuestaMercadoLibre::enviar()` gana un parámetro opcional
`?int $sugerenciaId = null`. Si viene informado, el insert de `MercadoLibreRespuestaEnviada` (ya
existente) agrega `ml_sugerencia_id` y `sugerencia_editada` (comparando `$texto` contra
`MercadoLibreSugerencia::find($sugerenciaId)->texto_sugerido`). El resto del método (guard de doble
respuesta vía índice único, envío vía `ClienteMercadoLibre`, manejo de error) **no cambia**.

**Rationale**: la spec 032 ya resolvió correctamente la parte difícil (condición de carrera, auditoría,
manejo de error) — reabrir ese código para "rediseñarlo" sería puro riesgo de regresión. Un parámetro
opcional con default `null` es 100% compatible con los llamados existentes (`responder()` sin
sugerencia sigue funcionando exactamente igual).

**Alternatives considered**: una tabla/flujo de envío paralelo específico para respuestas "con IA" — se
descarta explícitamente, generaría dos caminos de auditoría y dos guards de doble respuesta a mantener
sincronizados (justo el tipo de duplicación que la spec 032 ya evitó con su diseño).

**Gap real encontrado al verificar la firma actual contra el código ya implementado**:
`EnvioRespuestaMercadoLibre::enviar(MercadoLibreConversacion $conversacion, string $texto, int $usuarioId): array`
resuelve el `MercadoLibreMensaje` a responder **internamente**, buscando el último mensaje con
`origen='comprador'` de la conversación (`$conversacion->mensajes()->where('origen','comprador')->latest('enviado_en')->first()`)
— no lo recibe el caller. Si entre que se generó una sugerencia y que el usuario confirma el envío llega
un mensaje nuevo del comprador, `enviar()` respondería a ese mensaje nuevo pero podría igual recibir el
`$sugerenciaId` de la sugerencia vieja (generada para el mensaje anterior), grabando una auditoría
incorrecta (`ml_sugerencia_id` apuntando a la sugerencia de un mensaje distinto al efectivamente
respondido). **T023 debe agregar una validación**: si `$sugerenciaId` viene informado, comparar
`MercadoLibreSugerencia::find($sugerenciaId)->ml_mensaje_id` contra el `id` del `$mensaje` que `enviar()`
ya resuelve internamente — si no coinciden, guardar `ml_sugerencia_id=null` (la sugerencia no
corresponde al mensaje que efectivamente se está respondiendo), sin bloquear el envío en sí (el guard de
doble respuesta y el resto del flujo no cambian). Firma final:
`enviar(MercadoLibreConversacion $conversacion, string $texto, int $usuarioId, ?int $sugerenciaId = null): array`
— el parámetro nuevo se agrega al final, 100% compatible con los tres llamados existentes sin sugerencia.

## R5 — Notificación al frontend del estado de la sugerencia

**Decision**: se extiende `ConversacionController::actualizaciones()` (ya existente, R6/spec 032) para
incluir, junto a `conversaciones`/`mensajes`, un array `sugerencias` (id, mensaje_id, estado,
texto_sugerido cuando `estado=lista`) actualizado desde `?desde=`.

**Rationale**: mismo mecanismo de polling ya construido — no hace falta un endpoint nuevo de polling
separado, sólo ampliar el payload que ya se consulta periódicamente.

**Alternatives considered**: endpoint de polling específico sólo para sugerencias — se descarta, dos
peticiones periódicas en vez de una es peor para el volumen bajo-moderado de este negocio, sin ningún
beneficio real.

## R6 — Interfaz reemplazable para el LLM (igual que lo ya documentado en `decisiones-pendientes.md`)

**Decision**: `App\Services\MercadoLibre\Bot\GeneradorDeSugerencias` es una interfaz con un método
(`generar(MercadoLibreConversacion $conversacion, MercadoLibreMensaje $mensaje, string $instrucciones): string`),
resuelta vía el Service Container de Laravel (binding en `AppServiceProvider`).
`GeneradorDeSugerenciasOpenAI` es la implementación default, `gpt-4o-mini`, API key en `OPENAI_API_KEY`.

**Rationale**: ya decidido en `docs/bot_mensajeria_ml/decisiones-pendientes.md` — el proveedor puede
cambiar tras una prueba empírica de calidad (Claude Haiku/Sonnet quedaron documentados como
alternativa). Una interfaz simple aísla ese cambio al binding y a una clase nueva, sin tocar el Job ni
los controllers.

**Alternatives considered**: acoplar el Job directamente al SDK de OpenAI — se descarta, contradice la
decisión ya tomada de mantener el proveedor reemplazable.

## R7 — Infraestructura: colas reales requeridas para producción, no para desarrollo

**Decision**: `GenerarSugerenciaMercadoLibre` es un Job estándar de Laravel — corre en
`QUEUE_CONNECTION=database` + `queue:work` en el VPS (producción), y corre igual (síncrono) con
`QUEUE_CONNECTION=sync` en desarrollo/testing local, sin cambiar una línea de código.

**Rationale**: documentado en `docs/bot_mensajeria_ml/infraestructura.md` — a diferencia de la spec 032
(que no necesita colas), esta Fase 1 sí depende de un worker real en producción por la latencia del
llamado a OpenAI, pero eso no bloquea desarrollar/testear la feature antes de que el VPS esté listo.

**Alternatives considered**: ninguna — es la razón de ser de la separación en dos specs (032 sin
depender del VPS, 033 sí).

## R8 — Límite de caracteres y charset de Mercado Libre (confirmado vía MCP de documentación oficial, 02/08/2026)

**Decision**: `GeneradorDeSugerenciasOpenAI` (T008) incluye en el prompt la instrucción explícita de no
superar **350 caracteres**, y `GenerarSugerenciaMercadoLibre` (T016) valida el largo de la respuesta del
proveedor antes de guardar `estado=lista` (FR-011a) — si excede 350 caracteres o viene vacía, se guarda
`estado=error`.

**Rationale**: la documentación oficial de Mercado Libre (`developers.mercadolibre.com.ar/es_ar/mensajeria-post-venta`,
sección "Enviar mensaje al comprador") confirma `seller_max_message_length: 350` para mensajería
post-venta, con error explícito `limit_exceeded` / "The message content is too long, max characters
allowed are 350" si se excede, y sólo acepta charset ISO-8859-1 (Latin1) más una lista cerrada de
emoticones — no UTF-8 arbitrario. Para Preguntas (`/answers`) la documentación no confirma un tope
distinto, por lo que se aplica el mismo límite de forma conservadora. Sin esta validación, una sugerencia
podría mostrarse como `lista`, ser aprobada por el usuario, y recién fallar al momento de enviar
(`EnvioRespuestaMercadoLibre::enviar()`, ya con el `resultado=error` que maneja la spec 032) — mejor
detectarlo antes, en la generación, para no hacerle perder tiempo al usuario revisando un borrador que
Mercado Libre va a rechazar igual.

**Alternatives considered**: no validar y dejar que el error aparezca recién al enviar (ya cubierto por
el manejo de error existente de la spec 032, FR-009) — se descarta como única barrera porque degrada la
experiencia (el usuario revisa/edita un borrador que después de todo falla), aunque sigue siendo la red
de seguridad final si la validación en generación tuviera algún caso no cubierto.
