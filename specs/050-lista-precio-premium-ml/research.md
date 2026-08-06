# Research: Lista de Precios diferenciada para publicaciones Premium de Mercado Libre

**Spec**: [spec.md](spec.md)

Sin `[NEEDS CLARIFICATION]` pendientes tras `/speckit-clarify` — este documento consolida decisiones
técnicas para las que ya existe patrón vigente en el código de las specs 012/013/016/036, en vez de
research externo.

## R1 — Cómo se detecta si una publicación es Premium

**Decisión**: consultar `GET /items?ids=...` (bulk, hasta 20 ids por request — límite de la API de ML
ya respetado por el patrón de chunking usado en la exploración manual de esta feature) y leer
`listing_type_id` del `body` de cada entrada. `gold_pro` = Premium; cualquier otro valor (`gold_special`,
`free`, etc.) = no Premium.

**Rationale**: Es el mismo endpoint y campo ya verificados contra la cuenta real (`ClienteMercadoLibre`
soporta `GET` genérico vía `obtener()`/`peticion()`, que ya aplica los cortes de kill-switch/modo sólo
lectura/función desactivada). No hace falta un endpoint nuevo de ML ni credenciales adicionales.

**Alternatives considered**: `GET /items/{id}` uno por uno — descartado, 270 publicaciones vinculadas
hoy implicarían 270 requests en vez de ~14 (chunks de 20), sin ninguna ventaja.

## R2 — Persistencia del tipo de publicación

**Decisión**: columna nueva `listing_type_id` (string, nullable) en `ml_publicacion_producto`, con el
valor crudo que informa ML (no un booleano `es_premium`), más un helper `esPremium(): bool` en el
modelo que compara contra la constante `gold_pro`.

**Rationale**: Guardar el valor crudo (no sólo un booleano) deja trazabilidad de qué tipo real tiene
cada publicación (útil para diagnóstico/soporte) sin costo adicional, y aísla en un único lugar
(`esPremium()`) la regla "Premium = gold_pro" documentada en la spec — si algún día se necesitara
ampliar la definición, cambia en un solo método. Mismo patrón que otros campos de estado del modelo
(`stock_pendiente`, `precio_pendiente`, etc. — todos columnas simples, sin tabla de enum aparte).

**Alternatives considered**: boolean `es_premium` directo — descartado, pierde el dato crudo sin
beneficio real (la spec ya fija la regla, y el research previo a la spec ya validó el mapeo 1:1 contra
la cuenta real).

## R3 — Mecanismo de actualización diaria (US3/FR-004, resuelto en clarify)

**Decisión**: comando Artisan nuevo (`mercadolibre:sincronizar-tipos-publicacion`), registrado en
`bootstrap/app.php` con `$schedule->command(...)->everyMinute()->withoutOverlapping()`, mismo patrón que
`sincronizar-stock`/`sincronizar-ordenes` (research.md §R5 de spec 013: se evalúa cada minuto pero el
propio comando decide internamente si corresponde correr). Se agrega `ml_configuracion.tipo_publicacion_ultima_sync_en`
(datetime, nullable) y el comando compara contra un intervalo fijo de 24 horas (constante en el propio
comando, no un campo configurable en pantalla — a diferencia de `frecuencia_sync_minutos`, no hay
necesidad de negocio de exponerlo como configurable, la Clarification ya fijó "diaria").

**Rationale**: Reutiliza exactamente la infraestructura de scheduler y de portabilidad a hosting
compartido (sin `crontab`, todo vía `schedule:run` + hPanel) que ya existe y está probada para stock y
órdenes — cero mecanismo nuevo de programación, sólo un comando más en el mismo `withSchedule()`.

**Alternatives considered**: acoplarlo a `mercadolibre:sincronizar-stock` (que corre cada 15 min) —
descartado explícitamente en la Clarification: multiplicaría ~96x las llamadas a `/items` sólo para un
dato que casi no cambia.

## R4 — Backfill de las publicaciones ya vinculadas (FR-005)

**Decisión**: el comando nuevo de R3, al correr por primera vez (o cualquier vez), procesa TODAS las
publicaciones vinculadas sin filtrar por "recién vinculadas" — no hace falta un comando de backfill
separado ni un seeder. Alcanza con que el comando se ejecute una vez tras el deploy (manual, igual que
cualquier comando post-deploy de este proyecto) para completar las 270 publicaciones ya vinculadas antes
de que llegue la primera corrida programada del scheduler.

**Rationale**: Evita duplicar lógica entre "comando de backfill" y "comando de actualización periódica"
cuando son exactamente la misma operación (traer `listing_type_id` de todo lo vinculado). Coherente con
el criterio ya usado en el proyecto (ver `deploy.py --tinker` para operaciones puntuales post-deploy).

**Alternatives considered**: migración con lógica de backfill embebida — descartado, las migraciones de
este proyecto no hacen llamadas HTTP externas (rompería en cualquier entorno sin credenciales de ML
configuradas, como un `migrate` en CI o en un clon local sin cuenta vinculada).

## R5 — Punto de integración en `SincronizadorPrecios`

**Decisión**: extender `SincronizadorPrecios` para resolver, por cada `MercadoLibrePublicacionProducto`,
qué `lista_precio_id` usar (`ml_configuracion.lista_precio_id_premium` si `$vinculo->esPremium()` y hay
precio ahí; si no, cae a `ml_configuracion.lista_precio_id`). Esto reemplaza el `$listaPrecioId` fijo que
hoy reciben `enviarPendientes()`/`sincronizarListaCompleta()` por una resolución por vínculo.

**Rationale**: Es el único punto de la app que hoy decide qué precio enviar a Mercado Libre (contracts
§16 de spec 016) — extenderlo ahí evita duplicar la lógica de resolución de lista en varios lugares y
mantiene FR-011 (evaluación por publicación, no por producto) en un solo sitio.

**Alternatives considered**: filtrar de antemano los vínculos Premium y llamar dos veces a
`sincronizarListaCompleta()` (una con la lista general para no-Premium, otra con la Premium) —
descartado: `sincronizarListaCompleta()` hoy asume una sola lista y un único candado
(`ml:sincronizar_precios`); llamarlo dos veces duplicaría el lock y complicaría el conteo de
`actualizados`/`con_error` sin necesidad, cuando resolver la lista por vínculo dentro del mismo bucle ya
alcanza.

## R6 — UI de configuración

**Decisión**: agregar el campo "Lista de Precios ML Premium" al mismo formulario/tab de "Ventas" de
`/configuracion/mercadolibre` donde ya vive "Lista de Precios" (general), mismo componente Select2 ya
usado ahí (`dropdownParent` del modal/tab, catálogo chico — sin `ajax`, igual que el campo existente).

**Rationale**: Es el mismo patrón exigido por CLAUDE.md §"Selects con buscador" y el lugar donde el
usuario ya espera configurar todo lo relacionado a precios de Mercado Libre — no se crea una pantalla
nueva para un campo more.
