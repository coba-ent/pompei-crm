# Research: Sincronización de stock y precios del CRM hacia Tiendanube

## R1 — Punto de detección de movimientos: extender el Observer ya existente, no crear uno paralelo

**Pregunta**: ¿dónde se engancha la detección de "el stock de un producto vinculado a Tiendanube
cambió", dado que la spec 013 ya creó `app/Observers/MovimientoStockObserver.php` para el caso análogo de
Mercado Libre?

**Decisión**: agregar una **rama Tiendanube** al `MovimientoStockObserver` ya existente (`created`), en
vez de crear un segundo Observer sobre el mismo modelo `MovimientoStock`.

**Rationale**: `StockService::mover()`/`ajustar()`/`transferir()` (spec 002) ya persisten toda la
casuística de FR-001 como una fila de `movimientos_stock` — es el mismo único punto de entrada que ya
usó la spec 013 para Mercado Libre. Registrar dos observers de Eloquent independientes sobre el mismo
evento (`MovimientoStock::created`) para responsabilidades que son, en esencia, la misma ("¿hay una
integración externa vinculada a este producto? marcarla pendiente") es una duplicación de infraestructura
sin beneficio: ambas ramas son independientes entre sí (un producto puede estar vinculado a Mercado Libre,
a Tiendanube, a ambas o a ninguna) y viven bien como dos bloques dentro del mismo método.

**Alternativas consideradas**:
- *Segundo Observer independiente (`MovimientoStockTiendanubeObserver`)*: rechazada — mismo evento, misma
  responsabilidad conceptual, ninguna ventaja sobre agregar una rama al ya existente; sí agregaría una
  segunda suscripción a mantener en `AppServiceProvider`/`#[ObservedBy]`.
- *Enganchar en `VentaObserver`*: sólo cubriría Ventas, no ajustes manuales ni transferencias (FR-001
  exige "cualquier movimiento") — mismo motivo por el que la spec 013 ya lo descartó.

## R2 — Exclusión de bucle: mismo mecanismo que Mercado Libre, con el origen `tiendanube`

**Pregunta**: ¿cómo distingue el Observer un movimiento que **no** debe disparar sincronización (el que
generó la conversión de una orden de Tiendanube, spec 017 FR-046) de uno que sí debe?

**Decisión**: `MovimientoStock` ya guarda `origen_type`/`origen_id` polimórfico (spec 012/013), y
`StockDeVenta` (spec 012, extendida por la 017) pasa la `Venta` como `$origen` para **cualquier** Venta,
sin importar su procedencia. La `Venta` expone su propio origen mediante la columna `origen` (enum
`manual`/`presupuesto`/`mercadolibre`/`tiendanube`, spec 017 FR-035a). La rama Tiendanube del Observer
verifica: si `$movimiento->origen_type === Venta::class` y la Venta cargada tiene `origen ===
'tiendanube'`, no marca nada pendiente en `tn_variante_producto`. En cualquier otro caso (incluida una
Venta manual/presupuesto/de Mercado Libre, un ajuste, una transferencia, o un `origen` nulo), sí marca.

**Rationale**: no hace falta ninguna columna ni parámetro nuevo — el dato ya existe y ya se persiste
desde la spec 012/017. Es la aplicación directa a Tiendanube de la misma decisión que la spec 013 tomó
para Mercado Libre (FR-002), con el valor de enum correspondiente.

## R3 — Consolidación: flag booleano en el vínculo, no una cola de eventos

**Pregunta**: ¿cómo se "consolidan" varios movimientos del mismo producto entre dos corridas
(Clarifications) sin construir una tabla de cola?

**Decisión**: agregar a `tn_variante_producto` un flag `stock_pendiente` (booleano) — misma solución que
la spec 013 aplicó a `ml_publicacion_producto`. El Observer lo pone en `true` (si aún no lo estaba, sin
verificar duplicados: es un flag, no un contador). El sincronizador, al procesar un vínculo pendiente, no
reconstruye nada a partir de movimientos individuales: pregunta a `StockService::disponibilidad()` el
stock **actual** del producto en el depósito configurado (ya sea el resultado de uno o de veinte
movimientos) y ese es el valor que envía.

**Rationale**: mismo razonamiento que R3 de la spec 013 — es la implementación más simple de "un único
valor final, no uno por movimiento", sin tabla de eventos ni deduplicación explícita, coherente con el
volumen esperado (decenas de vínculos, spec 017 §Scale/Scope).

## R4 — Orden de ejecución CRM→Tiendanube después de Tiendanube→CRM: dos comandos en el mismo tick

**Pregunta**: ¿cómo se garantiza "inmediatamente después de traer las órdenes nuevas" (FR-006) sin
acoplar en código el sincronizador de stock al de órdenes?

**Decisión**: dos comandos Artisan independientes (`tiendanube:sincronizar-ordenes`, spec 017, y
`tiendanube:sincronizar-stock`, nuevo), ambos registrados con `everyMinute()->withoutOverlapping()` en
`bootstrap/app.php`, **en ese orden**, igual que ya conviven `mercadolibre:sincronizar-ordenes` y
`mercadolibre:sincronizar-stock`. El scheduler de Laravel ejecuta en un mismo tick de `schedule:run` las
tareas debidas de forma secuencial y en el orden de declaración (ninguna usa `runInBackground()`), así que
el de stock de Tiendanube corre después del de órdenes de Tiendanube sin necesitar invocarlo desde dentro
del primero — y sin interferir con el par análogo de Mercado Libre, que es una integración
independiente con su propio candado.

**Rationale**: mantiene la responsabilidad única de cada comando (mismo criterio que la spec 013 ya
estableció) y no depende de que alguien recuerde llamar al segundo desde el primero si se refactoriza
uno de los dos.

**Alternativa considerada**: invocar `SincronizadorStock` al final de
`SincronizarOrdenesTiendanube::handle()`. Rechazada por el mismo motivo que la spec 013: acopla dos
comandos de responsabilidad distinta y complica que "Sincronizar stock ahora" (US3) se dispare solo.

## R5 — Compras todavía no mueven stock: no es una contradicción de esta spec

**Verificado en código**: igual que documentó la spec 013 (su R5) para Mercado Libre,
`docs/documentacion_principal_crm.md` (nota de §6.2) documenta que `CompraController` no genera ningún
movimiento de stock — brecha diferida "cuando se retome Egresos", ajena tanto a la 013 como a ésta.

**Impacto en esta spec**: ninguno que bloquee. FR-001 exige que el sistema reaccione a cualquier
movimiento de `MovimientoStock`, sin importar su origen. Como el disparador es el Observer sobre
`MovimientoStock` (R1), el día que Egresos resuelva esa brecha, la sincronización hacia Tiendanube empieza
a cubrir Compras automáticamente, sin ningún cambio en esta feature.

## R6 — Actualización de stock: `update_stock_and_price`, requiere `product_id`, admite lote de 50 (CORREGIDO post-019)

> ⚠️ **Corrección post-019 (verificado empíricamente 30/07/2026)**: este research se escribió contra la
> documentación **REST pública** de Tiendanube (`tiendanube.github.io/api-documentation`). El CRM habla
> en realidad contra el servidor MCP `admin-mcp.tiendanube.com` — el endpoint REST descripto abajo
> **no es el que se usa**. La tool real es `update_stock_and_price`, con un contrato distinto en varios
> puntos (batching nativo, nombres de parámetro). El hallazgo central de este research —que hace falta
> `product_id` además de `variant_id`— **sigue siendo válido**, pero el resto de la decisión se corrige.

**Pregunta**: ¿qué llamada de la API de Tiendanube actualiza la cantidad disponible de una variante, y
alcanza con el `variant_id` que ya guarda `tn_variante_producto` (spec 017)?

**Verificado contra la tool MCP real** (`mcp__tiendanube__update_stock_and_price`, esquema de parámetros
consultado directamente el 30/07/2026 — sin necesidad de ejecutar ninguna escritura real):

- La tool es **`update_stock_and_price`**, no un endpoint REST. Body: `updates` (array, **1 a 50**
  entradas), cada una `{product_id, variant_id, stock?, price?, location_id?}` — `product_id` y
  `variant_id` son obligatorios por ítem, `stock`/`price` opcionales (se puede actualizar sólo uno de
  los dos), `location_id` opcional (se omite: usa la ubicación por defecto de la tienda, alcanza para el
  caso de esta spec, un solo depósito relevante). Confirma el hallazgo central de la versión original de
  este research: **siempre hace falta `product_id`**, no alcanza con `variant_id` solo.
- **Máximo 50 actualizaciones por llamada** — a diferencia del endpoint REST original (una llamada = una
  variante), esta tool permite loteo nativo. Con hasta ~200 vínculos pendientes por corrida
  (plan.md §Scale/Scope), son ≤4 llamadas, no 200.
- **Formato de respuesta ante lotes con éxitos y fallos mezclados: no verificado**, porque esta spec no
  ejecuta ninguna escritura real contra la cuenta (restricción de la 019). Se **asume** por analogía con
  otra tool de escritura en lote del mismo servidor (`bulk_delete_products`, que sí documenta "returns
  per-product results: which were deleted and which failed") que el comportamiento es equivalente — a
  confirmar en la primera implementación real (T032a de tasks.md).
- Límite de tasa: no documentado públicamente para esta tool específica (a diferencia del leaky bucket de
  la API REST, que ya no aplica porque no es lo que se usa). Se trata como piso conservador, reutilizando
  el mismo mecanismo de backoff de `ClienteTiendanube` sin un número propio verificado (FR-013).

**Decisión**: agregar la columna `tn_product_id` (string) a `tn_variante_producto` — **no existe hoy**
porque la spec 017 sólo capturó `variant_id` (su vinculación es por variante, no necesitaba el producto
para nada de lo que hace esa spec). Se puebla en el momento de crear el vínculo (FR-021/023 de la spec
017: tanto si se vincula desde una línea de una orden ya sincronizada —cuyo `tn_orden_items.tn_product_id`
ya lo trae, agregado en la corrección post-019 de la 017— como si se vincula desde la pantalla dedicada
—que necesariamente consulta el catálogo de productos de Tiendanube, donde `product_id` es el propio
identificador del recurso consultado—). No requiere una migración de datos retroactiva: la 017 todavía
no tiene código ni datos en producción (ver plan.md, "Advertencia de secuencia de implementación").
`SincronizadorStock` agrupa los vínculos pendientes en `array_chunk(..., 50)` y llama a
`ClienteTiendanube::escribir('update_stock_and_price', ['updates' => $lote])` una vez por chunk, en vez
de una vez por vínculo (corrección respecto del diseño original de una llamada por variante).

Para el reintento con espera creciente (FR-013), se reutiliza el mismo criterio de tope y backoff ya
establecido para el resto de la integración (`ClienteTiendanube`, spec 019), sin inventar un mecanismo
separado: ante cualquier rechazo por límite de tasa, la respuesta correcta es la misma —esperar y
reintentar—, y `ClienteTiendanube` (R7) ya centraliza esa lógica sin distinguir tools.

**Alternativas consideradas**:
- *Resolver `product_id` en el momento del envío, con una consulta previa a Tiendanube*: rechazada —
  agregaría una llamada de lectura extra por cada envío de escritura, exactamente lo que la consolidación
  (R3) busca evitar, y es información que ya está disponible gratis en el momento de vincular.
- *Guardar `product_id` en `tn_orden_items` en vez de en `tn_variante_producto`*: no alcanza para el caso
  de vínculos creados desde la pantalla dedicada (FR-024), que no parten de una orden — sí se agrega
  **además** en `tn_orden_items` (corrección de la 017) porque ahí sirve para poblar
  `tn_variante_producto.tn_product_id` al vincular desde una línea, no como reemplazo.
- *Una llamada por vínculo, sin lotear*: era el diseño original (asumía un endpoint REST sin batching);
  se descarta ahora que se confirmó que la tool real soporta lotes de hasta 50 — desperdiciaría la
  capacidad de batching sin ningún beneficio.

## R7 — Reintentos y logging: reutilización total de `ClienteTiendanube`. Kill-switch: reutilizado **más** un corte propio previo

**Verificado en código**: `ClienteTiendanube` (spec 019) ya implementa, para cualquier llamada que pase
por `escribir()`/`leer()`: el guard de función avanzada desactivada, el kill-switch de modo sólo lectura
sobre operaciones de escritura, reintentos con espera creciente ante 429/5xx, y registro en
`tn_operaciones_log` de cada resultado (éxito/error/bloqueada) — mismo diseño que `ClienteMercadoLibre`
(spec 011), sin el ciclo de renovación de token porque el `access_token` de Tiendanube dura ~1 año sin
`refresh_token` (spec 019). El envío de stock de esta spec es una escritura como cualquier otra: llamando
a `$this->cliente->escribir('update_stock_and_price', ['updates' => $lote])` (corrección post-019: no
`enviar('sincronizar_stock', 'POST', ...)`) se obtienen gratis FR-013 y FR-016 (reintentos y logging) sin
escribir ninguna lógica propia — **una vez por chunk de hasta 50 vínculos, no una vez por vínculo**
(R6 corregido).

**Mismo matiz que la spec 013 (su R7) ya documentó para Mercado Libre**: `ClienteTiendanube` bloquea (o
falla) cada llamada **individualmente** — si `SincronizadorStock` no cortara antes, con función
desactivada o sólo lectura activo terminaría llamando a `escribir()` una vez por cada chunk de vínculos
pendientes, generando **N** registros de "bloqueada" en el historial (uno por chunk, ya no uno por
vínculo gracias al loteo — pero igual más de uno si hay más de 50 pendientes) en lugar de uno solo. Eso
contradice la letra de FR-009/FR-010 ("el sistema **NO DEBE ejecutar la sincronización**..."), que exige
**no arrancar** la corrida. Por eso `SincronizadorStock` necesita su propio corte previo al `foreach`
—exactamente el mismo criterio que ya aplicaron tanto `SincronizadorOrdenes` de Tiendanube (spec 017,
lado lectura) como `SincronizadorStock` de Mercado Libre (spec 013, mismo lado, misma integración
distinta).

`SincronizadorStock` de Tiendanube necesita, en definitiva: su propio corte previo (FR-009/FR-010, un
único registro por corrida bloqueada) + su propio candado (`Cache::lock`, mismo patrón que
`SincronizadorOrdenes::LOCK_KEY` de Tiendanube, para FR-008, **independiente** del candado de stock de
Mercado Libre) + su propio manejo de "qué hacer con el vínculo" ante éxito o error definitivo
(FR-014/FR-015). Lo único genuinamente gratuito, sin código propio, es el reintento ante 429/5xx (FR-013)
y el registro de cada llamada individual (FR-016).

## R8 — Precios: disparo por evento, sin cron (AMPLIACIÓN 30/07/2026)

**Pregunta**: ¿la sincronización de precios hacia Tiendanube necesita un comando programado, igual que la
de stock?

**Decisión**: no. El precio se sincroniza exclusivamente por evento (`PrecioProductoObserver`, spec 016,
rama Tiendanube) y por acción manual — sin `frecuencia_sync_minutos` ni ningún `$schedule->command(...)`
propio para precio.

**Rationale**: el stock necesita cron porque se mueve por **muchas causas indirectas** (una Venta, un
ajuste, una transferencia) que pueden ocurrir sin que nadie mire la pantalla de Tiendanube — la
reconciliación periódica es la red de seguridad contra que algo quede desincronizado en silencio. El
precio, en cambio, cambia por **una única causa directa y deliberada**: alguien edita el precio de un
producto (a mano o por importación). Ese mismo evento ya es el punto perfecto para disparar el envío —
no hay "muchas causas indirectas" que reconciliar, y agregar un cron sólo introduciría llamadas
innecesarias a Tiendanube sin ningún cambio de precio real detrás. Mismo criterio, exactamente, que la
spec 016 ya estableció para Mercado Libre (spec 016, research.md R1).

**Alternativas consideradas**:
- *Sincronizar precios también por cron, igual que stock*: rechazada — no hay nada que "consolidar" entre
  corridas (un precio no se mueve varias veces por minuto como el stock), así que el cron sólo agregaría
  latencia de reconciliación innecesaria sobre un evento que ya se puede capturar de inmediato.

## R9 — Precio y stock comparten la tool `update_stock_and_price` pero no el flujo (AMPLIACIÓN)

**Pregunta**: dado que `update_stock_and_price` acepta `stock` y `price` como campos independientes del
mismo ítem (R6), ¿conviene fusionar `SincronizadorStock` y `SincronizadorPrecios` en un único servicio que
envíe ambos juntos cuando coincidan?

**Decisión**: no — se mantienen como dos servicios completamente independientes, cada uno enviando sólo
su propio campo (`SincronizadorStock` envía `stock` en lotes de hasta 50 por cron; `SincronizadorPrecios`
envía `price` de a un ítem por evento).

**Rationale**: son dos disparadores de naturaleza distinta (programado/consolidado vs. evento/inmediato)
con manejo de errores y candados independientes (research.md R7 de stock vs. el candado propio de
precio). Fusionarlos acoplaría un flujo asíncrono programado con uno síncrono disparado por el usuario —
un fallo o una demora en uno terminaría afectando al otro sin necesidad. El ahorro real (una llamada HTTP
combinada, en el caso poco frecuente de que un mismo vínculo tenga stock y precio pendientes exactamente
en el mismo instante) es marginal frente al costo de acoplar dos responsabilidades distintas. Mismo
principio de independencia que ya aplican, en Mercado Libre, `SincronizadorStock` (spec 013) y
`SincronizadorPrecios` (spec 016) — ahí ni siquiera comparten tool/endpoint, así que la pregunta no se
planteó, pero el criterio de diseño es el mismo.

**Alternativas consideradas**:
- *Un único `SincronizadorStockYPrecio`, con un método que arme el ítem con `stock` y/o `price` según qué
  esté pendiente*: rechazada — ganancia marginal (una llamada combinada ocasional) a cambio de acoplar dos
  ciclos de vida (cron vs. evento) y dos candados en un único servicio, complicando el testeo aislado de
  cada uno.

## R10 — Botón "Sincronizar precios ahora": un solo botón, dos rutas independientes por integración (AMPLIACIÓN, corregido)

**Pregunta**: ¿Tiendanube necesita su propia ruta/controlador de precio en Productos, o se cuelga del
`MercadoLibreVentaController::sincronizarPrecios()` que ya existe (spec 016)?

**Decisión**: **ruta y controlador propios** (`TiendanubeVentaController::sincronizarPrecios()`, mismo
controlador donde ya vive `sincronizarStock` de esta misma spec, expuesta en una ruta nueva
`productos/sincronizar-precios-tn`), pero el **botón sigue siendo uno solo** en la pantalla de Productos:
`resources/js/productos.js` dispara **ambas** llamadas (`sincronizar-precios-ml` y
`sincronizar-precios-tn`) al mismo click y combina los dos resultados en un único resumen por toast.

**Rationale**: la primera idea (reutilizar literalmente `MercadoLibreVentaController::sincronizarPrecios()`
inyectándole también el `Tiendanube\SincronizadorPrecios`) acoplaría un controlador nombrado y ubicado
específicamente para Mercado Libre (`app/Http/Controllers/Ingresos/MercadoLibreVentaController.php`) a una
integración que no tiene nada que ver con su namespace — mala señal de diseño sin beneficio real. Separar
las rutas/controladores por integración (mismo patrón que ya usa esta misma spec para
`sincronizarStock` en `TiendanubeVentaController`) mantiene cada controlador acotado a su propia
integración, tal como ya lo está todo el resto del código. La experiencia de un solo botón para el
usuario —el objetivo real detrás de la idea original— se logra en el **cliente** (JS), no fusionando
servidores: dos requests AJAX en paralelo desde un mismo handler de click, un solo resumen visible.

**Alternativas consideradas**:
- *Inyectar `Tiendanube\SincronizadorPrecios` en `MercadoLibreVentaController`*: rechazada — acopla
  nombre/namespace de un controlador a una integración ajena, con cero beneficio sobre la alternativa de
  JS (ver Decisión).
- *Botones separados y visibles, uno por integración*: rechazada — fragmenta una acción que el usuario
  percibe como una sola ("reintentá los precios"), obligándolo a saber qué botón corresponde a cada
  integración.
- *Endpoint único nuevo, integración-agnóstico, en `ProductoController`*: descartada por alcance — movería
  la responsabilidad de Mercado Libre (spec 016, ya deployada) a un controlador distinto sin necesidad
  funcional real; la solución de "un botón, dos requests" logra el mismo resultado sin tocar código ya
  en producción.

## Resumen de decisiones

| # | Decisión | Ubicación en el código |
|---|---|---|
| R1 | Rama Tiendanube dentro del `MovimientoStockObserver` ya existente | `app/Observers/MovimientoStockObserver.php` (extender) |
| R2 | Exclusión de bucle vía `origen_type`/`origen_id` → `Venta::origen === 'tiendanube'` | mismo Observer |
| R3 | Flag `stock_pendiente` en `tn_variante_producto`, sin cola | migración + `TiendanubeVarianteProducto` |
| R4 | Dos comandos programados en orden, sin invocación cruzada | `bootstrap/app.php` |
| R5 | Sin acción — cubierto automáticamente cuando Egresos resuelva su brecha | — |
| R6 | Tool `update_stock_and_price` (lote de hasta 50, `product_id`+`variant_id`+`stock` por ítem); agregar `tn_product_id` a `tn_variante_producto` | `SincronizadorStock` + migración |
| R7 | Reutilizar `ClienteTiendanube` sin cambios + corte propio previo al `foreach` | `app/Services/Tiendanube/SincronizadorStock.php` (nuevo) |
| R8 | Precio: disparo por evento, sin cron propio | `app/Observers/PrecioProductoObserver.php` (extender, spec 016) |
| R9 | Precio y stock: mismo tool, servicios independientes (sin fusionar) | `app/Services/Tiendanube/SincronizadorPrecios.php` (nuevo) |
| R10 | Ruta/controlador de precio propios por integración; un solo botón que dispara ambas requests (JS) | `TiendanubeVentaController::sincronizarPrecios()` (nuevo) + `resources/js/productos.js` (extender) |
