# Feature Specification: Sincronización de stock y precios del CRM hacia Tiendanube

**Feature Branch**: `018-stock-tiendanube`

**Created**: 2026-07-29

**Status**: Draft (ampliada 30/07/2026 — ver Nota de ampliación)

**Input**: User description: "Integración Tiendanube — Etapa 3: Sincronización de stock del CRM hacia
Tiendanube. Cuando el stock de un producto vinculado (tabla `tn_variante_producto`, spec 017) cambia en
el CRM — por una Venta manual, un ajuste, una transferencia, o cualquier otro movimiento de stock
existente — el sistema debe actualizar la cantidad disponible de la variante correspondiente en
Tiendanube, para cerrar el riesgo de sobreventa documentado explícitamente en la spec 017. Es la
contraparte inversa del flujo ya construido en la 017 (que sólo trae órdenes hacia el CRM, nunca empuja
stock hacia Tiendanube). Debe seguir el mismo patrón estructural que la spec 013 (stock hacia Mercado
Libre) respecto de la spec 012, adaptado a las diferencias reales de Tiendanube: vinculación por variante
en vez de por publicación, límite de tasa distinto (~2 solicitudes/segundo, ráfagas de hasta 40), sin
OAuth ni token que renovar. Debe respetar el kill-switch de modo sólo lectura y la función avanzada
Tiendanube, convivir con la sincronización de órdenes ya programada sin condiciones de carrera, y cubrir:
qué eventos disparan una actualización, cómo evitar bucles con las órdenes de Tiendanube ya ingresadas,
qué pasa si la variante no está vinculada, qué pasa si Tiendanube rechaza la actualización, y cómo se
refleja en el historial de operaciones."

**Ampliación (30/07/2026)**: "Agregar también la sincronización de precios del CRM hacia Tiendanube,
mismo patrón que la spec 016 hizo para Mercado Libre (`lista_precio_id` en la configuración, evento de
cambio de precio en esa lista, botón manual 'Sincronizar precios ahora' en Productos, **sin** cron
propio porque el precio cambia por evento discreto, no por acumulación de movimientos como el stock).
Confirmar además que el stock de Tiendanube sí lleva su propia entrada de cron
(`tiendanube:sincronizar-stock`), igual patrón que Mercado Libre (specs 012/013: dos comandos
programados independientes, uno de órdenes y uno de stock, declarados en ese orden), en vez de fusionarlo
dentro del comando de sincronización de órdenes como asumía el primer borrador de esta spec."

## Nota de ampliación (30/07/2026)

Esta spec se escribió originalmente (29/07/2026) cubriendo sólo la sincronización de **stock**
CRM→Tiendanube. Se amplía ahora, antes de implementarse (sigue en `Status: Draft`, cero código
construido), para incorporar también la sincronización de **precios** CRM→Tiendanube — misma necesidad
que ya resolvió la spec 016 para Mercado Libre, con el mismo criterio de negocio: el precio se prueba de
entrada con un producto de prueba oculto en la tienda real (vinculado él solo), sin arriesgar catálogo,
stock ni precios reales, igual que ya se validó para el flujo de ventas de la spec 017.

**Qué cambia respecto de la versión original**:
- Se agrega el flujo completo de precios (configuración, disparo por evento, botón manual, manejo de
  errores, visibilidad) — nueva sección de historias de usuario, requisitos y entidades.
- Se **corrige** FR-006 (más abajo): el stock **no** se sincroniza "como parte de la misma corrida" del
  comando de órdenes tal como decía el borrador original — es un **comando programado independiente**
  (`tiendanube:sincronizar-stock`), declarado después de `tiendanube:sincronizar-ordenes` en
  `bootstrap/app.php` para que corra justo después dentro del mismo tick de `schedule:run`, exactamente
  el mismo patrón de dos comandos separados que ya usa Mercado Libre (specs 012/013) — no un tercer paso
  dentro del comando existente.
- El resto de la spec original (alcance de stock, historias 1-4, FR-001 a FR-020, edge cases de stock)
  se mantiene sin cambios de fondo, sólo renumerado donde hizo falta para dar lugar a las secciones de
  precio.

## Contexto y fuentes

> ⚠️ **Corrección post-19/08/2026 (revisión posterior a spec 019, junto con la corrección de la 017)**:
> esta spec asumía la conexión REST de `specs/015-tiendanube-conexion/` y el endpoint REST público
> `POST /products/{id}/variants/stock`. Esa conexión quedó inutilizable y fue reemplazada por
> `specs/019-tiendanube-conexion-mcp/` (OAuth 2.1 contra `admin-mcp.tiendanube.com`, `ClienteTiendanube`
> hablando JSON-RPC). **La llamada real para actualizar stock no es un endpoint REST, es la tool
> `update_stock_and_price`** (`ClienteTiendanube::escribir('update_stock_and_price', ['updates' =>
> [...]])`), que además admite **hasta 50 variantes por llamada** — el diseño original de esta spec
> (una llamada HTTP por vínculo pendiente) se corrige para **lotear** hasta 50 vínculos por request, lo
> que además reduce la presión sobre cualquier límite de tasa. Cada corrección puntual queda marcada
> abajo. `tn_product_id` (research.md R6) sigue siendo necesario, y de hecho ya queda disponible desde
> la corrección de la spec 017 (`tn_orden_items.tn_product_id`), lo que hace más fácil poblarlo al
> vincular desde una línea de orden.

Esta spec es la **etapa 3** del módulo de integración con Tiendanube. Continúa directamente
`specs/017-ventas-tiendanube/`, que dejó explícitamente pendiente esta funcionalidad:

> "**Sincronización de stock del CRM hacia Tiendanube** → spec posterior (018), mismo patrón que la
> spec 013 respecto de la 012. Mientras no exista, aplica el mismo riesgo de sobreventa ya documentado
> para Mercado Libre." (`specs/017-ventas-tiendanube/spec.md`, sección Alcance)

La spec 017 documentó el riesgo que esta spec cierra:

> "⚠️ Riesgo de sobreventa hasta la spec 018: mientras la sincronización de stock CRM→Tiendanube no
> exista, una Venta manual del CRM (o una Venta de Mercado Libre, o de cualquier otro origen) que
> descuente stock de un producto también vendido en Tiendanube no reduce el stock publicado en
> Tiendanube." (`specs/017-ventas-tiendanube/spec.md`, sección Advertencias; también
> `docs/documentacion_principal_crm.md` §3.2.quater)

**Relación con la 017**: la 017 construyó el flujo **Tiendanube → CRM** (una orden de Tiendanube
descuenta stock local al convertirse, spec 017 FR-046, reutilizando `StockDeVenta`). Esta spec construye
el flujo inverso, **CRM → Tiendanube** (un movimiento de stock local empuja la cantidad disponible hacia
la variante vinculada en Tiendanube), reutilizando la misma infraestructura: la vinculación
`tn_variante_producto` (1:1, spec 017), el depósito configurado en `tn_configuracion.deposito_id`
(spec 017, FR-047), el cliente de API (`ClienteTiendanube`, spec 019, JSON-RPC/MCP) con el kill-switch de
modo sólo lectura, y el historial de operaciones (`tn_operaciones_log`, spec 015, sin cambios).

**Es exactamente el mismo problema que la spec 013 ya resolvió para Mercado Libre**, con diferencias
reales de la API de Tiendanube frente a Mercado Libre — corregidas contra la tool real `update_stock_and_price`
del servidor MCP (`admin-mcp.tiendanube.com`), verificada empíricamente el 30/07/2026, no contra la
documentación REST pública que asumía la primera versión de esta spec:

1. **La vinculación es por variante (`variant_id`), no por publicación**: Tiendanube actualiza stock a
   nivel de variante (incluso los productos sin variantes reales tienen una "variante virtual" única, ya
   vinculada 1:1 por la spec 017). No existe el caso "publicación sin variantes" que la spec 012/013
   excluía en Mercado Libre — toda vinculación de la spec 017 es, por definición, una variante.
2. **La tool admite lote de hasta 50 variantes por llamada** (`update_stock_and_price`, `updates: [...]`)
   — a diferencia de lo asumido originalmente (una llamada por variante), esta spec loteará los vínculos
   pendientes en grupos de hasta 50 por request, reduciendo drásticamente la cantidad de llamadas ante
   ráfagas de movimientos. El límite de tasa exacto de esta tool específica **no está documentado
   públicamente** — se reutiliza el mismo mecanismo de espera creciente ya construido en
   `ClienteTiendanube` (spec 019) sin un número verificado propio, mismo criterio conservador que la 017.
3. **Sin OAuth que renovar en la práctica**: a diferencia de Mercado Libre (spec 011), el `access_token`
   de Tiendanube (spec 019) dura ~1 año sin `refresh_token` — el único corte de conexión relevante para
   esta spec es "caída" por revocación/regeneración manual, detectada igual que ya lo hace la
   sincronización de órdenes (spec 017, FR-018). A diferencia de la redacción original, la recuperación
   **no** es "recargar el token": es reconectar por completo, acción técnica manual (spec 019).

**Precios — mismo problema que ya resolvió la spec 016 para Mercado Libre**: esta ampliación agrega el
flujo de precios CRM→Tiendanube, reutilizando `lista_precio_id` como concepto (una Lista de Precios del
CRM configurada como fuente de verdad de lo que Tiendanube debe mostrar) y el mismo criterio de disparo
por **evento** (no por cron) que la spec 016 fijó para Mercado Libre: el precio cambia de forma manual,
deliberada y esporádica, así que no hace falta ninguna corrida programada para este flujo — a diferencia
del stock, que se mueve por muchas causas y sí necesita reconciliación periódica (research.md R8).
**Detalle técnico propio de Tiendanube, sin equivalente en Mercado Libre**: la tool `update_stock_and_price`
(research.md R6, ya usada por el flujo de stock) acepta `stock` y `price` como campos **independientes y
opcionales** dentro del mismo ítem (`{product_id, variant_id, stock?, price?}`) — técnicamente permite
enviar ambos en una sola llamada. Esta spec **no** aprovecha esa posibilidad para fusionar los dos flujos:
mantiene `SincronizadorStock` (cron, consolidado, sólo `stock`) y un `SincronizadorPrecios` propio (evento,
sólo `price`) completamente independientes entre sí, igual que Mercado Libre mantiene separados
`SincronizadorStock` (spec 013) y `SincronizadorPrecios` (spec 016) aunque ahí sí sean dos tools/endpoints
distintos — la independencia de disparadores (cron vs. evento) y de manejo de errores importa más que el
ahorro ocasional de una llamada combinada, y evita acoplar un flujo asíncrono programado con uno síncrono
disparado por el usuario (research.md R9).

**Divergencia respecto de Contagram**: no hay relevamiento propio de esta pantalla, igual que la 017 —
Contagram no documenta públicamente el detalle de su sincronización de stock hacia Tiendanube. Esta spec
no agrega pantallas nuevas: extiende la pantalla de Tiendanube y la de Vinculación de variantes ya
construidas en la 017, con indicadores de estado de sincronización de stock — mismo criterio que la spec
013 aplicó sobre la 012.

**Fuentes de dominio**: `docs/documentacion_principal_crm.md` §3.2.quater (nota de riesgo) y §5.3
(integración Tiendanube), `docs/modelo_datos.md` §11 y §12, `specs/017-ventas-tiendanube/spec.md` y
`specs/017-ventas-tiendanube/data-model.md`, `specs/013-stock-mercadolibre/spec.md` (patrón estructural
de referencia directo para stock), `specs/016-lista-precio-mercadolibre/spec.md` (patrón estructural de
referencia directo para precios, ampliación 30/07/2026).

## Alcance

**Incluye — stock**: detectar los movimientos de stock del CRM que afectan a un producto vinculado a una
variante de Tiendanube, consolidar esos cambios y empujar la cantidad disponible resultante hacia
Tiendanube de forma programada (comando propio, cron independiente) y también bajo demanda ("Sincronizar
stock ahora"), evitando que un movimiento originado por una orden de Tiendanube rebote de vuelta hacia
Tiendanube, y dejando visible el resultado de cada intento (éxito, pendiente, rechazado) en la pantalla de
vinculación de variantes y en el historial de operaciones existente.

**Incluye — precios** (ampliación 30/07/2026, mismo patrón que la spec 016 para Mercado Libre):

- Un campo "Lista de Precios" configurable en la configuración de Tiendanube (`tn_configuracion.lista_precio_id`),
  análogo a Depósito/Categoría de Venta/Cuenta de Tesorería ya configurables ahí (spec 017), opcional.
- Cuando el precio de un producto **vinculado a una variante de Tiendanube** (`tn_variante_producto`)
  cambia **dentro de la Lista de Precios configurada**, el sistema sincroniza ese precio hacia la variante
  correspondiente en Tiendanube, en el momento del cambio, sin esperar ninguna corrida programada — sin
  importar el camino de escritura (modal de Producto o importación masiva de precios).
- Una acción manual "Sincronizar precios ahora" en la pantalla de **Productos**, mismo lugar/patrón que
  Mercado Libre (spec 016), que reintenta los vínculos con precio pendiente o con error.
- Al cambiar **cuál** es la Lista de Precios configurada para Tiendanube, el sistema sincroniza de
  inmediato los precios vigentes de la nueva lista para todos los productos actualmente vinculados.
- Visibilidad del estado de sincronización de precio (sincronizado / pendiente / error) en la pantalla de
  vinculación de variantes ya existente (spec 017), análoga a la que esta misma spec agrega para stock.

**Excluye explícitamente**:

- Sincronización de **nombre**, **descripción**, **imágenes** o **visibilidad/estado** de la variante o el
  producto: de estos atributos, sólo la cantidad disponible y el precio quedan en alcance.
- **Comisión de Tiendanube y costo de envío** a cargo del vendedor: siguen fuera de alcance, igual que en
  la spec 017.
- Sincronización de stock o precio de productos **no vinculados**: sin vínculo no hay variante a la que
  empujarle nada (comportamiento ya establecido en la 017 para el sentido inverso).
- Despausar, republicar o modificar el estado de publicación de un producto cuando su stock vuelve a ser
  positivo, o pausarlo/despublicarlo cuando llega a cero: informar cantidad cero ya alcanza para que
  Tiendanube deje de vender esa variante.
- Cualquier cambio al cálculo del precio de las líneas de una Venta convertida desde una orden de
  Tiendanube (spec 017): sigue saliendo 100% del importe pagado en la orden — la Lista de Precios
  configurada acá no interviene en ese cálculo, mismo criterio que fijó la spec 016 para Mercado Libre.
  Las Ventas de Tiendanube tampoco quedan etiquetadas con esta Lista de Precios.
- Corrida programada / cron para precios: a diferencia del stock, el disparador de precios es
  exclusivamente el evento de cambio (o la acción manual) — no hay `frecuencia_sync_minutos` aplicable a
  este flujo.
- Importación masiva de catálogo, webhooks de negocio: mismas exclusiones ya vigentes desde la spec 017.

## Clarifications

### Session 2026-07-29

Decisiones de continuidad directa con la spec 013 (mismo problema, aplicado a la API real de Tiendanube),
resueltas por decisión fundamentada sin interrumpir al usuario:

- Q: ¿Qué stock se publica cuando el negocio tiene varios depósitos? → A: el del **depósito configurado
  para Tiendanube** (`tn_configuracion.deposito_id`, ya existente desde la spec 017 FR-047; el depósito
  por defecto del CRM si no se eligió ninguno). Es el mismo depósito del que ya descuentan las Ventas
  originadas en Tiendanube — usar otro depósito como fuente daría una cantidad disponible inconsistente
  con el stock que la propia integración gestiona. Mismo criterio que la spec 013 fijó para Mercado
  Libre.
- Q: ¿Cómo se evita que una orden de Tiendanube que descuenta stock local dispare un push de vuelta hacia
  Tiendanube? → A: los movimientos de stock **originados por la conversión de una orden de Tiendanube**
  (spec 017, FR-046: todo movimiento queda referenciado a la Venta que lo originó, y esa Venta expone su
  origen "tiendanube", spec 017 FR-035/FR-035a) quedan **excluidos** de disparar sincronización de stock
  hacia Tiendanube. Tiendanube ya descontó esa unidad de su propio stock al generar la orden; empujarla
  de vuelta sería, en el mejor caso, redundante, y en el peor, una fuente de inconsistencia si llegara
  desfasada en el tiempo. Mismo mecanismo que la spec 013 (FR-002) construyó para el origen
  "mercadolibre".
- Q: ¿Cada movimiento de stock dispara un llamado inmediato a la API? → A: **no, se consolidan**, igual
  que la spec 013. Cada movimiento elegible marca el vínculo variante↔producto como "con cambios
  pendientes de sincronizar"; la corrida programada (misma frecuencia configurable de la spec 017,
  `frecuencia_sync_minutos`, ejecutada inmediatamente después de traer las órdenes nuevas) empuja **un
  único valor final** por variante, sin importar cuántos movimientos hubo desde el último envío. Evita
  exceder el límite de solicitudes de Tiendanube (~2/segundo, ráfagas de hasta 40) ante ráfagas de
  movimientos (varias Ventas seguidas, una importación).
- Q: ¿Cómo se interpreta un rechazo puntual de Tiendanube al actualizar el stock de una variante (variante
  eliminada, producto despublicado o inexistente)? → A: se trata como **rechazo definitivo** de ese
  vínculo puntual (mismo tratamiento que la spec 013 dio a una publicación pausada/cerrada de Mercado
  Libre): se registra el motivo y la fecha, el vínculo queda con cambios pendientes para reintentar en la
  próxima corrida, y el resto de los vínculos de la misma corrida se sincroniza sin verse afectado.

### Session 2026-07-30 (ampliación — precios)

Decisiones de continuidad directa con la spec 016 (mismo problema, aplicado a la vinculación por variante
de Tiendanube en vez de por publicación), resueltas por decisión fundamentada sin interrumpir al usuario:

- Q: ¿El precio de Tiendanube se sincroniza por cron, igual que el stock? → A: **No**. El precio cambia
  por evento discreto (edición manual, importación, vinculación), no por acumulación de movimientos como
  el stock — no hay `frecuencia_sync_minutos` aplicable a este flujo, mismo criterio que la spec 016 fijó
  para Mercado Libre.
- Q: ¿El comando de sincronización de stock es un paso más dentro de `tiendanube:sincronizar-ordenes`, o
  un comando programado propio? → A: **comando propio** (`tiendanube:sincronizar-stock`), declarado
  después de `tiendanube:sincronizar-ordenes` en `bootstrap/app.php` — corrige la redacción original de
  FR-006, que asumía "como parte de la misma corrida". Mismo patrón de dos comandos independientes que ya
  usa Mercado Libre (specs 012/013).
- Q: ¿Dónde vive el botón "Sincronizar precios ahora"? → A: en **Productos** (Base de Datos → Productos &
  Servicios), no en la pantalla de Tiendanube — mismo criterio de UX que la spec 016 fijó para Mercado
  Libre: el lugar natural para reintentar precios es donde se editan, no donde se gestionan órdenes/stock.
- Q: La tool `update_stock_and_price` acepta `stock` y `price` en el mismo ítem — ¿se combinan los dos
  flujos en una sola llamada cuando ambos están pendientes? → A: **No**, se mantienen `SincronizadorStock`
  y `SincronizadorPrecios` completamente independientes (Contexto y fuentes) — simplicidad y aislamiento
  de fallos por sobre el ahorro ocasional de una llamada combinada.
- Q: ¿Cambiar la Lista de Precios configurada para Tiendanube empuja de inmediato los precios vigentes de
  la nueva lista? → A: Sí, mismo comportamiento que la spec 016 fijó para Mercado Libre (push inmediato al
  guardar el cambio de configuración).

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Que una Venta cargada a mano en el CRM se refleje en Tiendanube (Priority: P1)

Como responsable del negocio, cuando cargo una Venta en el CRM sobre un producto que tengo vinculado a
una variante de Tiendanube, quiero que la cantidad disponible de esa variante baje sola en Tiendanube,
sin que yo tenga que entrar a Tiendanube a corregirla a mano.

**Why this priority**: es el motivo de ser de esta spec — cerrar el riesgo de sobreventa documentado en
la 017. Sin esto, el negocio sigue vendiendo en Tiendanube unidades que ya no tiene.

**Independent Test**: se puede probar cargando una Venta manual sobre un producto vinculado con stock
disponible, esperando el intervalo de sincronización (o forzándola), y verificando que la cantidad
disponible de la variante en Tiendanube bajó exactamente en la cantidad vendida.

**Acceptance Scenarios**:

1. **Given** un producto vinculado con stock disponible, **When** se carga una Venta manual del CRM que
   lo incluye, **Then** el vínculo queda marcado con cambios pendientes de sincronizar.
2. **Given** un vínculo con cambios pendientes, **When** corre la sincronización programada, **Then**
   Tiendanube recibe la nueva cantidad disponible de la variante y el vínculo deja de estar pendiente.
3. **Given** varias Ventas seguidas sobre el mismo producto entre dos corridas, **When** corre la
   sincronización, **Then** Tiendanube recibe un único valor, igual al stock final del CRM en ese
   momento, no una llamada por Venta.
4. **Given** el listado de vinculaciones de variantes, **When** el usuario lo mira, **Then** ve para cada
   vínculo si está sincronizado, pendiente, o con error, y cuándo fue el último envío exitoso.
5. **Given** un ajuste de stock que **incrementa** el stock de un producto vinculado, **When** corre la
   sincronización, **Then** la cantidad disponible en Tiendanube también sube.

---

### User Story 2 - Que una orden de Tiendanube no rebote de vuelta (Priority: P1)

Como responsable del negocio, cuando una orden de Tiendanube descuenta stock local (spec 017), no quiero
que eso dispare un envío de vuelta hacia Tiendanube: Tiendanube ya sabe que esa unidad se vendió, fue la
causa del descuento.

**Why this priority**: sin esta exclusión, el sistema generaría llamadas redundantes o, peor, una carrera
entre "traer la orden que bajó el stock" y "avisar de ese mismo stock bajado" que podría desincronizar la
cantidad real. Es tan crítico como la historia 1: sin él, la sincronización no es confiable.

**Independent Test**: se puede probar convirtiendo una orden de Tiendanube en Venta (spec 017), esperando
la siguiente sincronización, y verificando en el historial de operaciones que no se generó ningún envío
de stock asociado a ese movimiento.

**Acceptance Scenarios**:

1. **Given** una orden de Tiendanube convertida en Venta, **When** se descuenta el stock del producto
   vinculado, **Then** ese movimiento **no** marca el vínculo como pendiente de sincronizar.
2. **Given** la misma corrida programada, **When** trae órdenes nuevas y luego sincroniza stock, **Then**
   el orden de ejecución garantiza que el stock empujado ya contempla las órdenes recién traídas, sin una
   segunda corrida necesaria para que quede consistente.
3. **Given** un producto vinculado que tuvo tanto una Venta manual como una orden de Tiendanube entre dos
   corridas, **When** corre la sincronización, **Then** se empuja igual, porque la Venta manual sí generó
   cambios pendientes; el valor enviado es el stock final correcto.

---

### User Story 3 - Forzar la sincronización de stock manualmente (Priority: P2)

Como responsable del negocio, quiero poder forzar el envío del stock actual hacia Tiendanube sin esperar
al intervalo programado, igual que ya puedo forzar la sincronización de órdenes.

**Why this priority**: da control inmediato en momentos puntuales (por ejemplo, después de un ajuste de
stock grande), pero la sincronización programada de la historia 1 ya entrega el valor central sin esta
acción manual.

**Independent Test**: se puede probar presionando "Sincronizar stock ahora" con vínculos pendientes y
verificando que se envían de inmediato, sin esperar el intervalo configurado.

**Acceptance Scenarios**:

1. **Given** vínculos con cambios pendientes, **When** el usuario presiona "Sincronizar stock ahora",
   **Then** el sistema los envía de inmediato e informa por notificación cuántos se actualizaron, sin
   recargar la página.
2. **Given** el modo sólo lectura activo o la función Tiendanube desactivada, **When** el usuario busca la
   acción, **Then** no está disponible, con el motivo visible.
3. **Given** una sincronización de stock ya en curso, **When** el usuario dispara otra, **Then** sólo una
   se ejecuta y la otra se descarta, sin enviar la misma variante dos veces en simultáneo.

---

### User Story 4 - Enterarse cuando Tiendanube rechaza una actualización (Priority: P2)

Como responsable del negocio, si Tiendanube rechaza la actualización de stock de una variante (porque el
producto está despublicado, eliminado, o por cualquier otro motivo), quiero verlo señalado con el motivo,
sin que eso bloquee la sincronización del resto de mis productos vinculados.

**Why this priority**: sin visibilidad del rechazo, el CRM cree que el stock está sincronizado cuando no
lo está, reintroduciendo en silencio el mismo riesgo de sobreventa que esta spec busca cerrar.

**Independent Test**: se puede probar vinculando una variante que luego se elimina o despublica en
Tiendanube, generando un movimiento de stock sobre su producto, sincronizando, y verificando que queda
señalada con el motivo del rechazo mientras el resto de los vínculos se sincroniza con normalidad.

**Acceptance Scenarios**:

1. **Given** una variante eliminada o un producto despublicado en Tiendanube, **When** su producto
   vinculado tiene un cambio de stock pendiente, **Then** el envío se rechaza, el vínculo queda señalado
   con el motivo concreto, y el resto de los vínculos de esa misma corrida se sincroniza sin verse
   afectado.
2. **Given** un rechazo por exceso de solicitudes o una falla temporal de red, **When** ocurre, **Then**
   el sistema reintenta con espera creciente antes de marcarlo como error, sin descartar el pendiente
   (corrección post-019: el límite exacto de la tool `update_stock_and_price` no está verificado
   públicamente, ver FR-013).
3. **Given** un vínculo marcado con error, **When** el usuario lo revisa en la pantalla de vinculación de
   variantes, **Then** ve el motivo del último rechazo y cuándo ocurrió.
4. **Given** un vínculo con error persistente, **When** vuelve a tener un cambio de stock pendiente y
   corre la sincronización, **Then** el sistema lo vuelve a intentar (el error no lo excluye
   permanentemente de futuras corridas).

---

### User Story 5 - Configurar la Lista de Precios que gestiona Tiendanube (Priority: P1)

Como responsable del negocio, quiero elegir, desde la configuración de Tiendanube, qué Lista de Precios
del CRM es la que gestiona los precios de mis variantes vinculadas en Tiendanube, igual que ya elijo el
Depósito, la Categoría de Venta y la Cuenta de Tesorería (spec 017).

**Why this priority**: sin este campo no hay ninguna lista de referencia — es el prerrequisito de todo lo
demás en precios.

**Independent Test**: se puede probar entrando a la configuración de Tiendanube, seleccionando una Lista
de Precios activa en el nuevo campo, guardando, y verificando que la selección persiste al recargar.

**Acceptance Scenarios**:

1. **Given** la pantalla de configuración de Tiendanube, **When** el usuario abre el selector de Lista de
   Precios, **Then** ve listadas las Listas de Precios activas del CRM.
2. **Given** una Lista de Precios seleccionada, **When** el usuario guarda la configuración, **Then** el
   sistema confirma el guardado por notificación, sin recargar la página, y la selección queda persistida.
3. **Given** ninguna Lista de Precios seleccionada (campo vacío), **When** el usuario guarda, **Then** el
   sistema lo permite sin error — el campo es opcional, y sin él no hay sincronización de precios.

---

### User Story 6 - Que un cambio de precio en esa lista se refleje solo en Tiendanube (Priority: P1)

Como responsable del negocio, cuando cambio el precio de un producto vinculado a una variante de
Tiendanube, dentro de la Lista de Precios que configuré para gestionar Tiendanube, quiero que ese nuevo
precio se actualice en Tiendanube automáticamente, sin tener que entrar a Tiendanube a corregirlo a mano
ni esperar ninguna corrida programada.

**Why this priority**: es el motivo de ser de esta ampliación — sin esto, el campo configurado en la
historia 5 no tendría ningún efecto.

**Independent Test**: se puede probar configurando una Lista de Precios (historia 5), vinculando un
producto de prueba oculto a una variante de Tiendanube (spec 017), cambiando su precio en esa lista desde
el modal de edición de Producto, y verificando que la variante en Tiendanube queda con el nuevo precio sin
ninguna acción manual adicional.

**Acceptance Scenarios**:

1. **Given** una Lista de Precios configurada en Tiendanube y un producto vinculado a una variante,
   **When** se guarda un nuevo precio de ese producto dentro de esa lista (modal de Producto), **Then**
   el sistema envía de inmediato el nuevo precio a la variante de Tiendanube correspondiente.
2. **Given** el mismo escenario, **When** el precio que cambia es el de un producto **sin** vínculo con
   ninguna variante, **Then** no se dispara ningún envío hacia Tiendanube.
3. **Given** el mismo escenario, **When** el precio que cambia es el de una Lista de Precios **distinta**
   a la configurada para Tiendanube, **Then** no se dispara ningún envío hacia Tiendanube.
4. **Given** ninguna Lista de Precios configurada en Tiendanube, **When** cambia cualquier precio de
   cualquier producto, **Then** no se dispara ningún envío hacia Tiendanube.
5. **Given** un cambio de precio disparado por la importación masiva de precios (Excel/CSV) sobre un
   producto vinculado, dentro de la lista configurada, **When** se procesa la importación, **Then** se
   dispara el mismo envío hacia Tiendanube que si el cambio se hubiera hecho a mano.
6. **Given** una variante recién vinculada (FR-023 de la spec 017, vinculación inline o desde la pantalla
   dedicada) cuyo producto ya tiene precio cargado en la lista configurada, **When** se guarda el vínculo,
   **Then** el sistema envía de inmediato ese precio vigente a la variante recién vinculada.

---

### User Story 7 - Sincronizar precios manualmente (Priority: P2)

Como responsable del negocio, quiero poder forzar el envío de los precios pendientes hacia Tiendanube en
cualquier momento, desde la pantalla de Productos, sin esperar a un nuevo cambio de precio.

**Why this priority**: es la red de seguridad del flujo automático de la historia 6 — sin ella, un envío
que falló quedaría desactualizado en Tiendanube indefinidamente.

**Independent Test**: se puede probar provocando una falla de envío, presionando "Sincronizar precios
ahora", y verificando que el precio pendiente se envía y el vínculo deja de estar marcado como
pendiente/error.

**Acceptance Scenarios**:

1. **Given** vínculos con precio pendiente o con error, **When** el usuario presiona "Sincronizar precios
   ahora", **Then** el sistema los envía de inmediato e informa por notificación cuántos se actualizaron y
   cuántos quedaron con error, sin recargar la página.
2. **Given** el modo sólo lectura activo o la función Tiendanube desactivada, **When** el usuario busca la
   acción, **Then** no está disponible, con el motivo visible.
3. **Given** una sincronización de precios ya en curso, **When** el usuario dispara otra, **Then** sólo una
   se ejecuta y la otra se descarta, sin enviar el mismo producto dos veces en simultáneo.

---

### User Story 8 - Enterarse cuando Tiendanube rechaza una actualización de precio (Priority: P2)

Como responsable del negocio, si Tiendanube rechaza la actualización de precio de una variante, quiero
verlo señalado con el motivo, sin que eso bloquee la sincronización del resto de mis productos vinculados.

**Why this priority**: sin visibilidad del rechazo, el CRM cree que el precio está sincronizado cuando en
realidad Tiendanube sigue mostrando un precio desactualizado.

**Independent Test**: se puede probar con el producto de prueba oculto, forzando un rechazo (por ejemplo
despublicándolo en Tiendanube), cambiando su precio en la lista configurada, y verificando que queda
señalado con el motivo del rechazo mientras el resto de los vínculos se sincroniza con normalidad.

**Acceptance Scenarios**:

1. **Given** una variante despublicada o inexistente en Tiendanube, **When** su producto vinculado tiene un
   cambio de precio en la lista configurada, **Then** el envío se rechaza, el vínculo queda señalado con
   el motivo concreto y "pendiente de sincronizar precio", y el resto de los vínculos afectados en la
   misma operación se sincroniza sin verse afectado.
2. **Given** un vínculo marcado con error de precio, **When** el usuario lo revisa en la pantalla de
   vinculación de variantes, **Then** ve el motivo del último rechazo y cuándo ocurrió.
3. **Given** un vínculo con error persistente, **When** vuelve a tener un cambio de precio y se dispara el
   envío (automático o manual), **Then** el sistema lo vuelve a intentar.

---

### User Story 9 - Cambiar la Lista de Precios configurada actualiza Tiendanube de una vez (Priority: P2)

Como responsable del negocio, si decido que Tiendanube pase a gestionarse con otra Lista de Precios
distinta a la que tenía configurada, quiero que los precios de Tiendanube se actualicen de inmediato según
la nueva lista, sin ir producto por producto.

**Why this priority**: sin esto, cambiar de lista dejaría todas las variantes vinculadas con el precio de
la lista anterior hasta que cada producto tuviera, individualmente, un cambio de precio futuro.

**Independent Test**: se puede probar con productos vinculados y precios cargados en dos Listas de Precios
distintas, cambiando la Lista de Precios configurada en Tiendanube de una a la otra, y verificando que
todas las variantes vinculadas reciben de inmediato el precio vigente en la nueva lista.

**Acceptance Scenarios**:

1. **Given** productos vinculados con precio cargado en la nueva Lista de Precios, **When** el usuario
   guarda el cambio de configuración, **Then** el sistema sincroniza de inmediato el precio vigente de la
   nueva lista hacia la variante de cada producto vinculado.
2. **Given** un producto vinculado que **no** tiene precio cargado en la nueva Lista de Precios, **When**
   se guarda el cambio de configuración, **Then** ese vínculo no se sincroniza y queda señalado según
   corresponda, sin bloquear al resto.
3. **Given** el cambio de configuración de Lista de Precios, **When** se guarda con el modo sólo lectura
   activo o la función Tiendanube desactivada, **Then** la configuración se guarda igual, pero el push
   inmediato de precios no se ejecuta — los vínculos quedan marcados como pendientes para el próximo
   intento válido, igual que cualquier otro corte de escritura.

---

### Edge Cases

- **Stock local negativo** (por ejemplo, tras una orden de Tiendanube que vendió de más, spec 017
  FR-046d): el sistema **nunca** publica una cantidad negativa; empuja **cero**.
- **Movimiento de stock en un depósito distinto al configurado para Tiendanube**: no dispara
  sincronización — sólo importa el stock del depósito configurado (ver Clarifications).
- **Vínculo eliminado con un envío pendiente**: el pendiente se descarta; no hay variante vigente a la
  que empujarle nada.
- **Producto vinculado inactivado en el CRM**: sigue empujando su stock real (que puede ser cero); no se
  despublica ni se elimina la variante (fuera de alcance).
- **Cambio de depósito configurado para Tiendanube**: todos los vínculos existentes quedan marcados como
  pendientes, para que la próxima corrida sincronice contra el stock del nuevo depósito.
- **La sincronización de stock se interrumpe a mitad de camino**: los vínculos ya enviados no se
  reenvían de más; los que quedaron pendientes se retoman en la corrida siguiente.
- **Conexión con Tiendanube caída** (token revocado o regenerado, spec 019 — la única recuperación es
  reconectar por completo, no "recargar el token"): no se ejecuta el envío,
  igual que la sincronización de órdenes (spec 017, FR-018); el pendiente se conserva para cuando se
  restablezca.
- **Tiendanube rechaza por exceso de solicitudes**: reintento con espera creciente, reutilizando el mismo
  mecanismo que la spec 017 (FR-020), sin descartar pendientes.
- **El proceso que ejecuta la sincronización se interrumpe a mitad de camino** (caída del worker/proceso,
  no una interrupción lógica): los vínculos ya enviados exitosamente en esa corrida quedan sincronizados;
  los que no llegaron a procesarse siguen `stock_pendiente = true` y se retoman en la corrida siguiente,
  sin necesidad de una marca de "corrida en progreso" adicional a la del candado (FR-008) — el candado ya
  se libera solo si el proceso muere, permitiendo que la próxima corrida programada continúe.
- **Reintentos agotados sin éxito**: tras el número acotado de reintentos con espera creciente (FR-013),
  el vínculo queda con `stock_error`/`stock_error_en` (FR-014), sin un límite adicional de "cuántas
  corridas puede seguir reintentándose": mientras el vínculo exista y tenga cambios pendientes, cada
  corrida programada o manual lo vuelve a intentar (mismo criterio ya fijado por FR-014, última viñeta).
- **Vínculo sin `tn_product_id` completo** (por ejemplo, por una edición manual de la base de datos que lo
  dejara vacío): el sistema NO DEBE intentar el envío para ese vínculo — lo trata como error de datos
  propio (no un rechazo de Tiendanube), lo señala con un motivo distintivo ("vínculo incompleto") y no
  bloquea el resto de la corrida, mismo tratamiento que un rechazo de Tiendanube (FR-014/FR-015).

**Edge cases de precio (ampliación 30/07/2026)**:

- **Precio con más de un cambio antes de que el envío anterior termine**: cada cambio marca el vínculo
  como pendiente y dispara un intento con el valor vigente al momento de ese intento — no hace falta
  encolar valores intermedios, porque siempre se envía el precio actual del producto en la lista
  configurada, no un historial de cambios (mismo criterio que la spec 016).
- **Vínculo eliminado con un envío de precio pendiente**: el pendiente se descarta; no hay variante
  vigente a la que empujarle nada.
- **Producto inactivado en el CRM pero vinculado**: sigue sincronizando su precio si cambia.
- **Lista de Precios configurada que se desactiva o se elimina**: mismo criterio que la spec 016 fijó
  para Mercado Libre — la configuración conserva la referencia; si el borrado de Listas de Precios ya
  impide eliminar una en uso (comportamiento existente, ajeno a esta spec), ese resguardo cubre también
  este uso.
- **Conexión con Tiendanube caída o modo sólo lectura activo en el momento del cambio de precio**: el
  envío no se ejecuta; el vínculo queda marcado como pendiente para el próximo intento válido (automático
  ante un nuevo cambio, o manual), sin perder el pendiente.
- **Tiendanube rechaza por exceso de solicitudes o falla temporal de red**: mismo mecanismo de espera
  creciente que el flujo de stock (FR-013), sin descartar el pendiente.
- **Dos cambios de precio casi simultáneos sobre el mismo producto**: el último envío exitoso es el que
  queda reflejado en Tiendanube, consistente con el precio vigente en el CRM en ese momento.

## Requirements *(mandatory)*

> **Nota de numeración**: los identificadores FR-### se mantienen **a propósito** alineados con los de la
> spec 013 cuando el requisito es el mismo o una adaptación directa, para que sea trivial comparar ambas
> specs lado a lado.

### Functional Requirements — Disparo y consolidación

- **FR-001**: El sistema DEBE marcar un vínculo variante↔producto (`tn_variante_producto`) como "con
  cambios pendientes de sincronizar" cada vez que el stock del producto vinculado cambie en el depósito
  configurado para Tiendanube (`tn_configuracion.deposito_id`, o el depósito por defecto si no hay
  ninguno elegido), sin importar el origen del movimiento (Venta, ajuste manual, transferencia, o
  cualquier otro movimiento de stock existente en el CRM).
- **FR-002**: El sistema NO DEBE marcar como pendiente un movimiento de stock que se originó en la
  conversión de una orden de Tiendanube en Venta (spec 017, FR-046), para no generar un envío redundante
  o inconsistente hacia el mismo origen del que vino el dato.
- **FR-003**: El sistema DEBE consolidar todos los cambios pendientes de un mismo producto ocurridos
  entre dos sincronizaciones en un único valor a enviar: el stock actual del producto en el depósito
  configurado en el momento de la corrida, no un acumulado de movimientos. Esto aplica **aunque el valor
  final coincida con el último enviado** (por ejemplo, movimientos que se cancelan entre sí): el sistema
  igual lo envía, en lugar de intentar detectar que "no cambió nada".
- **FR-004**: El sistema DEBE tratar el stock negativo como **cero** al momento de enviarlo a Tiendanube,
  sin alterar el valor real que se muestra dentro del CRM.
- **FR-005**: El sistema NO DEBE generar cambios pendientes para productos sin vínculo vigente con una
  variante de Tiendanube.
- **FR-005a**: El sistema NO DEBE intentar el envío de un vínculo cuyo `tn_product_id` esté vacío o
  incompleto; DEBE señalarlo con un motivo distintivo de error de datos (no un rechazo de Tiendanube) y
  continuar con el resto de los vínculos pendientes de la misma corrida, sin bloquearla.

### Functional Requirements — Ejecución y concurrencia

- **FR-006**: El sistema DEBE ejecutar la sincronización de stock de forma programada, con la misma
  frecuencia configurable ya existente para las órdenes (`tn_configuracion.frecuencia_sync_minutos`,
  spec 017). **Corrección (ampliación 30/07/2026)**: no es un paso adicional dentro del comando
  `tiendanube:sincronizar-ordenes` como decía la redacción original — es un **comando programado
  independiente** (`tiendanube:sincronizar-stock`), declarado en `bootstrap/app.php` **después** de
  `tiendanube:sincronizar-ordenes` para que, dentro del mismo tick de `schedule:run`, el stock que se
  empuja ya contemple las órdenes recién traídas — mismo patrón de dos comandos separados que ya usa
  Mercado Libre (specs 012/013), no una fusión de responsabilidades en un único comando.
- **FR-007**: El sistema DEBE ofrecer una acción manual "Sincronizar stock ahora" que envíe de inmediato
  los vínculos con cambios pendientes, informando el resultado por notificación sin recargar la página.
- **FR-008**: El sistema DEBE garantizar que dos sincronizaciones de stock no se ejecuten
  simultáneamente: si una está en curso, la siguiente (programada o manual) se descarta. Este control es
  **independiente** del que ya impide dos sincronizaciones de órdenes simultáneas (spec 017, FR-014): un
  envío de stock en curso no bloquea una sincronización de órdenes, y viceversa.
- **FR-009**: El sistema NO DEBE ejecutar la sincronización de stock mientras la función "Tiendanube" esté
  desactivada o el modo sólo lectura esté activo, dado que es una operación de escritura hacia Tiendanube;
  DEBE registrar el intento bloqueado en el historial de operaciones existente, **conservando los cambios
  pendientes** para el próximo intento en que ninguno de los dos esté activo.
- **FR-010**: El sistema NO DEBE ejecutar la sincronización de stock mientras la conexión con Tiendanube
  esté caída o no configurada, conservando los cambios pendientes para el próximo intento válido.
- **FR-011**: El sistema DEBE funcionar de forma equivalente en un entorno sin procesos permanentes
  (hosting compartido) y en uno con procesamiento en segundo plano, sin cambios en el código, reutilizando
  el mismo mecanismo de portabilidad ya construido para la sincronización de órdenes (spec 017, FR-011).

### Functional Requirements — Envío y manejo de errores

- **FR-012**: El sistema DEBE actualizar la cantidad disponible de las variantes con cambios pendientes en
  Tiendanube con el valor consolidado (FR-003/FR-004). **Corrección post-019**: no es una llamada por
  vínculo — la tool real `update_stock_and_price` acepta hasta **50 actualizaciones por llamada**
  (`updates: [{product_id, variant_id, stock}, ...]`); el sistema DEBE agrupar los vínculos pendientes en
  lotes de hasta 50 y enviar un lote por llamada, no una llamada por variante.
- **FR-013**: El sistema DEBE aplicar espera creciente ante rechazos por exceso de solicitudes y
  reintentar un número acotado de veces ante fallas temporales, sin descartar el pendiente ni bloquear el
  envío del resto de los lotes de la misma corrida — mismo mecanismo ya construido en `ClienteTiendanube`
  (spec 019). **Corrección**: el límite de "~2/s, ráfagas de 40" de la versión original de esta spec
  venía de la documentación REST pública, no de la tool MCP real — no hay un número de tasa verificado
  específicamente para `update_stock_and_price`; se trata como piso conservador, no como dato confirmado.
- **FR-014**: El sistema DEBE registrar, por cada **vínculo individual** cuyo envío fue rechazado de forma
  no transitoria (variante eliminada, producto despublicado o inexistente, u otro rechazo definitivo
  informado por Tiendanube dentro de la respuesta del lote), el motivo concreto y el momento del rechazo,
  dejando el vínculo con cambios pendientes para reintentarlo en la próxima corrida en lugar de
  descartarlo. **Nota de verificación pendiente**: el formato exacto de la respuesta de
  `update_stock_and_price` para un lote con éxitos y fallos mezclados no está verificado empíricamente
  (esta spec no ejecutó ninguna escritura real contra la cuenta — spec.md de la 019, restricción de no
  tocar la cuenta real fuera de lo estrictamente necesario); se **asume**, por consistencia con otras
  tools de escritura en lote del mismo servidor (ej. `bulk_delete_products`, que sí documenta "returns
  per-product results: which were deleted and which failed"), que devuelve resultado por ítem — a
  confirmar en la primera implementación real (T032 de tasks.md, ya marcada pendiente).
- **FR-015**: El sistema DEBE continuar sincronizando el resto de los vínculos pendientes de una corrida
  aunque uno de ellos sea rechazado.
- **FR-016**: El sistema DEBE registrar cada envío de stock (exitoso, rechazado o bloqueado) en el
  historial de operaciones ya existente (`tn_operaciones_log`, spec 015), como operación de sentido
  "escritura", sin incluir datos sensibles (el `access_token`).

### Functional Requirements — Visibilidad

- **FR-017**: El sistema DEBE mostrar, en la pantalla de "Vinculación de variantes" (spec 017, FR-024),
  por cada vínculo, su estado de sincronización de stock: sincronizado, con cambios pendientes, o con
  error; la fecha del último envío exitoso; y, cuando el estado sea "con error", el **motivo concreto del
  último rechazo y la fecha en que ocurrió** (mismo nivel de detalle que ya exige FR-007b de la spec 017
  para las órdenes que requieren atención).
- **FR-018**: El sistema DEBE ofrecer la acción "Sincronizar stock ahora" (FR-007) desde la misma pantalla
  de Tiendanube donde ya existe "Sincronizar ahora" para órdenes (spec 017, FR-009).
- **FR-019**: El sistema DEBE mostrar, en la pantalla de configuración de Tiendanube, la fecha y el
  resultado de la última sincronización de stock, análogo a lo ya expuesto para la de órdenes.

### Functional Requirements — Retención

- **FR-020**: El sistema NO DEBE requerir una tabla de historial propia para los envíos de stock más allá
  del historial de operaciones ya existente (spec 015): el estado vigente de cada vínculo
  (pendiente/sincronizado/error) es el único dato que se conserva de forma persistente y mutable sobre el
  propio vínculo.

### Functional Requirements — Precios (ampliación 30/07/2026): configuración

> **Nota de numeración**: los FR-### de precio siguen la numeración de la spec 016 (Mercado Libre)
> desplazada para no chocar con los de stock de esta misma spec — mismo criterio de alineación por
> comparación directa ya usado en la 017.

- **FR-021**: El sistema DEBE permitir configurar, desde la configuración de Tiendanube, una Lista de
  Precios entre las activas del CRM (`tn_configuracion.lista_precio_id`), como la que gestiona los precios
  de las variantes vinculadas.
- **FR-022**: El campo Lista de Precios de la configuración de Tiendanube DEBE ser opcional: sin ninguna
  seleccionada, el sistema NO DEBE disparar ninguna sincronización de precio.
- **FR-023**: El sistema DEBE rechazar el guardado de la configuración si el valor enviado para Lista de
  Precios no corresponde a ninguna Lista de Precios existente del CRM, informando el error sin guardar el
  resto de la configuración.

### Functional Requirements — Precios: disparo por evento

- **FR-024**: El sistema DEBE detectar, en el momento en que se crea o modifica un precio en
  `precios_producto`, si esa fila pertenece a la Lista de Precios configurada para Tiendanube y si el
  producto correspondiente tiene un vínculo vigente con una variante de Tiendanube
  (`tn_variante_producto`). Si ambas condiciones se cumplen, DEBE disparar el envío del nuevo precio a esa
  variante en el momento del cambio, sin esperar ninguna corrida programada.
- **FR-025**: El sistema DEBE disparar este comportamiento sin importar el camino de escritura sobre
  `precios_producto` — tanto el modal de edición de Producto como la importación masiva de precios DEBEN
  producir el mismo resultado ante el mismo cambio de dato.
- **FR-026**: El sistema NO DEBE disparar ningún envío hacia Tiendanube por un cambio de precio en una
  Lista de Precios distinta a la configurada, ni por el cambio de precio de un producto sin vínculo
  vigente con ninguna variante.
- **FR-027**: El sistema DEBE, al crearse un vínculo variante↔producto (spec 017, FR-021/FR-023) cuyo
  producto ya tenga precio cargado en la Lista de Precios configurada, disparar de inmediato el envío de
  ese precio vigente a la variante recién vinculada.
- **FR-028**: El sistema DEBE, al guardarse un cambio de **cuál** es la Lista de Precios configurada,
  sincronizar de inmediato el precio vigente de la nueva lista hacia la variante de cada producto
  actualmente vinculado que tenga un precio cargado en esa lista.

### Functional Requirements — Precios: envío y manejo de errores

- **FR-029**: El sistema DEBE enviar, por cada disparo elegible (FR-024/FR-025/FR-027/FR-028), el precio
  vigente del producto en la Lista de Precios configurada a la variante de Tiendanube vinculada, vía la
  tool `update_stock_and_price` (research.md R6) enviando únicamente el campo `price` del ítem (sin
  `stock`, que administra `SincronizadorStock` de forma independiente).
- **FR-030**: El sistema DEBE aplicar el mismo mecanismo de espera creciente y reintento acotado ante
  rechazos por exceso de solicitudes o fallas temporales de red ya existente en `ClienteTiendanube`
  (spec 019), sin descartar el pendiente ni bloquear el envío de otros vínculos.
- **FR-031**: El sistema DEBE registrar, ante un rechazo no transitorio (variante despublicada, eliminada,
  inexistente u otro rechazo definitivo), el motivo concreto y el momento del rechazo, dejando el vínculo
  marcado como "pendiente de sincronizar precio" para reintentarlo, en lugar de descartarlo.
- **FR-032**: El sistema NO DEBE ejecutar ningún envío de precio (automático o manual) mientras la función
  "Tiendanube" esté desactivada o el modo sólo lectura esté activo; DEBE registrar el intento bloqueado en
  el historial de operaciones existente, conservando el pendiente para el próximo intento válido.
- **FR-033**: El sistema NO DEBE ejecutar ningún envío de precio mientras la conexión con Tiendanube esté
  caída o no configurada, conservando el pendiente para el próximo intento válido.
- **FR-034**: El sistema DEBE registrar cada envío de precio (exitoso, rechazado o bloqueado) en el
  historial de operaciones ya existente (`tn_operaciones_log`, spec 015), como operación de sentido
  "escritura", sin incluir datos sensibles.

### Functional Requirements — Precios: acción manual y visibilidad

- **FR-035**: El sistema DEBE ofrecer una acción manual "Sincronizar precios ahora" en la pantalla de
  Productos (Base de Datos → Productos & Servicios) que envíe de inmediato el precio vigente de todos los
  vínculos de Tiendanube con precio pendiente o con error, informando el resultado por notificación sin
  recargar la página. **Coexistencia con Mercado Libre**: si un producto está vinculado a la vez a una
  publicación de Mercado Libre y a una variante de Tiendanube, el mismo botón dispara el reintento de
  ambas integraciones — no son acciones separadas por integración en esta pantalla.
- **FR-036**: El sistema DEBE garantizar que dos sincronizaciones de precio de Tiendanube (automáticas o
  manuales) no se ejecuten simultáneamente sobre el mismo vínculo. Este control es independiente del que
  ya existe para stock (FR-008) y para órdenes (spec 017, FR-014).
- **FR-037**: La acción manual NO DEBE estar disponible (con el motivo visible) mientras el modo sólo
  lectura esté activo o la función Tiendanube esté desactivada.
- **FR-038**: El sistema DEBE mostrar, en la pantalla de "Vinculación de variantes" (spec 017, FR-024), por
  cada vínculo, su estado de sincronización de **precio**: sincronizado, con cambios pendientes, o con
  error; la fecha del último envío exitoso; y, cuando el estado sea "con error", el motivo concreto del
  último rechazo y la fecha en que ocurrió — mismo nivel de detalle que FR-017 ya exige para stock, columna
  separada.

### Functional Requirements — Precios: exclusiones explícitas

- **FR-039**: El sistema NO DEBE usar la Lista de Precios configurada aquí (ni ninguna otra) para calcular
  o modificar el precio unitario, el subtotal ni el total de ninguna línea de Venta convertida desde una
  orden de Tiendanube (spec 017): esos valores siguen derivándose exclusivamente del importe pagado en la
  orden.
- **FR-040**: El sistema NO DEBE asignar Lista de Precios a las Ventas creadas al convertir una orden de
  Tiendanube: quedan sin Lista de Precios asignada, igual que el comportamiento vigente desde la spec 017.

### Key Entities

- **Vinculación variante ↔ producto** (`tn_variante_producto`, ya existente desde la spec 017): se le
  agregan el identificador del **producto** de Tiendanube dueño de la variante (necesario para poder
  actualizar su stock, FR-005a), atributos de sincronización de **stock** — indicador de cambios
  pendientes, fecha del último envío exitoso, motivo del último error (si lo hubo) y fecha de ese error —
  y, en esta ampliación, los mismos cuatro atributos para **precio** (indicador de cambios pendientes,
  fecha del último envío exitoso, motivo y fecha del último error), en columnas separadas de las de stock.
  No es una entidad nueva, es una extensión de la ya construida en la 017.
- **Configuración de Tiendanube** (`tn_configuracion`, ya existente desde la spec 017): se le agrega el
  atributo Lista de Precios (`lista_precio_id`, referencia opcional a una Lista de Precios del CRM) — a
  diferencia de Depósito/Categoría de Venta/Cuenta de Tesorería (que clasifican), este atributo dispara
  sincronización de precios hacia Tiendanube. No es una entidad nueva.
- **Envío de stock**: no es una entidad persistente propia; es la operación (registrada en el historial
  de operaciones ya existente) que consolida y transmite el estado de un vínculo en un momento dado.
- **Envío de precio**: no es una entidad persistente propia; es la operación (registrada en el historial
  de operaciones ya existente) que transmite el precio vigente de un vínculo en un momento dado.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Una Venta manual del CRM sobre un producto vinculado se refleja en la cantidad disponible
  de Tiendanube dentro del intervalo de sincronización configurado, sin ninguna acción manual del usuario
  en Tiendanube.
- **SC-002**: Ninguna orden de Tiendanube ya ingresada al CRM (spec 017) genera un envío de stock de
  vuelta hacia Tiendanube por ese mismo movimiento, verificable revisando que el historial de operaciones
  no registra un envío de escritura asociado a ese movimiento puntual.
- **SC-003**: Ante múltiples movimientos de stock del mismo producto entre dos corridas, Tiendanube
  recibe exactamente un envío con el valor final, no uno por movimiento.
- **SC-004**: La cantidad disponible publicada en Tiendanube nunca es negativa, verificable en el 100% de
  los casos donde el stock local del CRM cayó por debajo de cero.
- **SC-005**: Con el modo sólo lectura activo o la función Tiendanube desactivada, cero envíos de stock
  llegan a Tiendanube, y el 100% de los intentos bloqueados queda registrado en el historial de
  operaciones.
- **SC-006**: El rechazo de una variante individual (eliminada, producto despublicado) no impide que el
  resto de los vínculos de la misma corrida se sincronice con normalidad.
- **SC-007**: El usuario puede forzar una sincronización de stock manual y ver su resultado, sin recargar
  la página, en menos de un minuto.
- **SC-008** (precio): el usuario puede configurar la Lista de Precios de Tiendanube y confirmar que quedó
  guardada en menos de 30 segundos, sin recargar la página.
- **SC-009** (precio): el 100% de los cambios de precio sobre un producto vinculado, dentro de la Lista de
  Precios configurada, dispara un intento de sincronización hacia Tiendanube en el momento del cambio, sin
  esperar ninguna corrida programada.
- **SC-010** (precio): el 100% de los cambios de precio sobre productos sin vínculo vigente, o en una Lista
  de Precios distinta a la configurada, no genera ningún envío hacia Tiendanube.
- **SC-011** (precio): el 100% de las Ventas creadas al convertir órdenes de Tiendanube mantiene precios de
  línea idénticos a los que el sistema ya calculaba antes de esta ampliación, y ninguna queda con Lista de
  Precios asignada.
- **SC-012** (precio): el rechazo de una variante individual no impide que el resto de los vínculos
  afectados por el mismo evento o por la acción manual se sincronice con normalidad.
- **SC-013** (precio): al cambiar la Lista de Precios configurada, el 100% de los productos vinculados con
  precio cargado en la nueva lista recibe el precio actualizado sin que el usuario toque cada producto
  individualmente.

## Assumptions

- **Fuente única de stock**: el depósito configurado para Tiendanube (`tn_configuracion.deposito_id`,
  spec 017) es la única fuente de la cantidad publicada; se documenta como decisión en Clarifications.
- **Sin sincronización en tiempo real estricto**: el envío ocurre en la corrida programada (misma
  cadencia que la de órdenes) o bajo demanda manual, no de forma instantánea al momento exacto del
  movimiento — coherente con la restricción de portabilidad a hosting compartido ya vigente desde la
  spec 019/017.
- **Sin despublicado automático**: llegar a cantidad disponible cero alcanza para que Tiendanube deje de
  vender esa variante; no se agrega una acción adicional de pausar, despublicar o eliminar.
- **Una sola tienda de Tiendanube**: se mantiene el supuesto single-tenant ya vigente desde la spec 019.
- **Reintentos acotados**: se reutiliza el mismo criterio de reintento con espera creciente y tope ya
  definido en `ClienteTiendanube` (spec 019), sin un nuevo mecanismo propio. **Corrección post-019**: sin
  un límite de tasa verificado específicamente para `update_stock_and_price` — se trata como piso
  conservador, no como número confirmado (ver FR-013).
- **Las Compras todavía no mueven stock**: es una brecha ya documentada y ajena a esta spec
  (`docs/documentacion_principal_crm.md`, nota de §6.2) — el sistema reacciona a **cualquier** movimiento
  de stock (FR-001), pero hoy sólo las Ventas y los ajustes los generan. El día que Egresos resuelva esa
  brecha, las Compras quedan cubiertas por esta spec sin cambios adicionales.
- **Formato de respuesta de `update_stock_and_price` para lotes mixtos, no verificado**: ver nota de
  verificación pendiente en FR-014 — esta spec asume (sin confirmar contra una escritura real) que la
  tool devuelve resultado por ítem del lote, por analogía con `bulk_delete_products`.
- **Precio: sin corrida programada** (ampliación 30/07/2026): a diferencia del stock, este flujo no usa
  `frecuencia_sync_minutos` ni ningún cron — el disparador es siempre el evento de cambio de precio, la
  vinculación, el cambio de configuración, o la acción manual.
- **Precio: fuente única**: el precio enviado a Tiendanube siempre sale de `precios_producto.precio` para
  la Lista de Precios configurada; ningún otro campo (por ejemplo `productos.precio_venta`) interviene.
- **Precio: sin validación de "activa" al sincronizar**: mismo criterio ya vigente para
  `categoria_venta_id`/`deposito_id` (spec 017) — la configuración no revalida en cada evento que la Lista
  de Precios elegida siga activa.
- **Precio y stock comparten tool pero no flujo**: `update_stock_and_price` acepta ambos campos en el mismo
  ítem, pero `SincronizadorStock` y `SincronizadorPrecios` siguen siendo servicios independientes (Contexto
  y fuentes) — no hay un caso de uso real que dependa de que un mismo request incluya ambos.

## Dependencies

- **Interna — spec 017 (implementada)**: tabla `tn_variante_producto` (vinculación 1:1), movimientos de
  stock con referencia a la Venta que los originó, depósito configurado
  (`tn_configuracion.deposito_id`), y sincronización programada de órdenes, sobre la que esta spec
  agrega el paso de push de stock. `tn_orden_items.tn_product_id` (agregado en la corrección post-019 de
  la 017) facilita poblar `tn_variante_producto.tn_product_id` (R6) al vincular desde una línea de orden.
- **Interna — spec 019 (implementada y deployada, corrige a la 015)**: cliente de API `ClienteTiendanube`
  (JSON-RPC/MCP), kill-switch de modo sólo lectura, historial de operaciones (`tn_operaciones_log`).
- **Interna — spec 005 (implementada)**: Depósitos.
- **Interna — spec 002 (implementada)**: Productos y su stock.
- **Externa**: la conexión OAuth de la spec 019 ya tiene otorgado el scope `write_products` (todos los
  scopes se piden de una vez en esa spec) — no hace falta una segunda aprobación en el navegador para
  que esta spec pueda escribir stock.
- **Patrón de referencia — spec 013 (implementada)**: mismo problema ya resuelto para Mercado Libre;
  esta spec reutiliza su estructura de requisitos y decisiones, adaptada a las diferencias reales de la
  API de Tiendanube documentadas arriba.
- **Patrón de referencia — spec 016 (implementada)**: mismo problema de precios ya resuelto para Mercado
  Libre; esta ampliación reutiliza su estructura de requisitos y decisiones (disparo por evento, sin cron,
  botón en Productos), adaptada a la vinculación por variante de Tiendanube.
- **Interna**: módulo de Listas de Precios y Precios de Producto (`listas_precio`, `precios_producto`,
  `ListaPrecio`, `PrecioProducto`), ya existente — `ProductoController::sincronizarPrecios()` y
  `app/Services/Import/ImportadorFilas.php` como los dos caminos de escritura conocidos sobre
  `precios_producto` (mismos que ya dispara la spec 016 para Mercado Libre).

## Restricciones de diseño y entorno

- **Especificaciones de diseño obligatorias del proyecto** (`CLAUDE.md`): el estado de sincronización de
  stock se muestra dentro de la tabla ya existente de vinculación de variantes (DataTables, carga por
  demanda); "Sincronizar stock ahora" usa el mismo patrón AJAX sin recarga de página y notificaciones
  toast que "Sincronizar ahora" de órdenes (spec 017). El selector de Lista de Precios usa Select2, dentro
  del mismo formulario AJAX de configuración de Tiendanube ya usado por Depósito/Categoría/Cuenta de
  Tesorería; el estado de sincronización de precio se muestra en la misma tabla de vinculaciones (columna
  separada de la de stock); "Sincronizar precios ahora" (Productos) usa el mismo patrón AJAX/toast que el
  resto de las acciones de esa pantalla.
- **Portabilidad de entorno**: igual que la spec 017 — mismo código en hosting compartido y en servidor
  dedicado. El flujo de precios no depende de ningún mecanismo de corrida programada, por lo que no
  hereda la restricción de portabilidad asociada a los cron jobs (mismo criterio que la spec 016).
- **Idioma del dominio**: nombres de columnas, rutas y textos de interfaz en español.
- **Secretos**: ninguna credencial se registra en logs; el historial de operaciones no debe contener
  datos sensibles (igual que specs 015/017).
- **Testing**: por el principio IV de la constitución, la exclusión de movimientos originados en
  Tiendanube (FR-002), la consolidación de cambios pendientes (FR-003), el tope en cero (FR-004) y la no
  concurrencia (FR-008) requieren tests obligatorios para stock; FR-024/FR-025 (disparo por evento sin
  importar el camino de escritura), FR-026 (no disparo fuera de alcance), FR-028 (push inmediato al
  cambiar de lista), FR-030/FR-031 (reintento y registro de error), FR-032/FR-033 (cortes de escritura),
  FR-036 (no concurrencia) y FR-039/FR-040 (exclusiones sobre el cálculo de precio de Venta) requieren
  tests obligatorios para precio.

## Impacto en la documentación de dominio

Conforme al principio I de la constitución, esta spec introduce contenido que debe reflejarse en la
documentación de dominio **antes de pasar a `/speckit-tasks`**:

1. `docs/documentacion_principal_crm.md`:
   - Actualizar §3.2.quater y §5.3 para reflejar que el riesgo de sobreventa documentado queda cerrado
     por esta spec, describiendo el sentido CRM → Tiendanube y su cadencia (stock por cron, precio por
     evento), y el comando programado independiente `tiendanube:sincronizar-stock`.
2. `docs/modelo_datos.md`:
   - Ampliar `tn_variante_producto` (§12) con los atributos nuevos de sincronización de stock
     (pendiente, último envío exitoso, motivo y fecha del último error) y los cuatro análogos de precio.
   - Agregar `lista_precio_id` (FK → `listas_precio`, nullable) a `tn_configuracion` (§12).
