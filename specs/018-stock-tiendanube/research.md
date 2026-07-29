# Research: Sincronización de stock del CRM hacia Tiendanube

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

## R6 — Endpoint de actualización de stock: requiere `product_id` de Tiendanube, no sólo `variant_id`

**Pregunta**: ¿qué llamada de la API de Tiendanube actualiza la cantidad disponible de una variante, y
alcanza con el `variant_id` que ya guarda `tn_variante_producto` (spec 017)?

**Verificado contra la documentación oficial** (`tiendanube.github.io/api-documentation`, recurso
Product Variant, consultado 29/07/2026):

- El endpoint es **`POST /{store_id}/products/{product_id}/variants/stock`** — a diferencia de lo que
  podría suponerse, **no** es un endpoint de variante "suelta"; siempre cuelga del producto. **No existe**
  una vía para actualizar stock usando únicamente el `variant_id`.
- Cuerpo: `{"action": "replace" | "variation", "value": N, "id": variant_id}`. `action: "replace"` fija
  el valor absoluto (exactamente la semántica que necesita esta spec: FR-003 siempre envía "el stock
  actual", no un delta); `id` (opcional) acota la actualización a una única variante — sin él, actualizaría
  **todas** las variantes del producto, que no es lo que se quiere.
- Errores documentados: `404` ("Product with such id does not exist"), `422` (acción inválida), `500`
  genérico. La documentación no detalla el caso específico de una variante eliminada dentro de un producto
  existente ni el de un producto despublicado — se tratan igual que cualquier rechazo no exitoso (FR-014):
  motivo registrado tal cual lo informa Tiendanube, vínculo con cambios pendientes conservados.
- Límite de tasa: el propio recurso documenta un **token bucket ponderado** específico para este
  endpoint ("consume tokens según la complejidad del payload"), distinto del leaky bucket ~2
  solicitudes/segundo (ráfagas de 40) que rige lectura de órdenes (spec 017 FR-020). No se documenta un
  número exacto de solicitudes/segundo para este endpoint en particular.

**Decisión**: agregar la columna `tn_product_id` (string) a `tn_variante_producto` — **no existe hoy**
porque la spec 017 sólo capturó `variant_id` (su vinculación es por variante, no necesitaba el producto
para nada de lo que hace esa spec). Se puebla en el momento de crear el vínculo (FR-021/023 de la spec
017: tanto si se vincula desde una línea de una orden ya sincronizada —cuyo payload de Tiendanube incluye
`product_id` por línea— como si se vincula desde la pantalla dedicada —que necesariamente consulta el
catálogo de productos de Tiendanube, donde el `product_id` es el propio identificador del recurso
consultado—). No requiere una migración de datos retroactiva: la 017 todavía no tiene código ni datos en
producción (ver plan.md, "Advertencia de secuencia de implementación").

Para el reintento con espera creciente (FR-013), se reutiliza el mismo criterio de tope y backoff ya
establecido para el resto de la integración (spec 017, FR-020), sin inventar un mecanismo separado sólo
porque el límite documentado para este endpoint puntual sea "ponderado" en vez de "por segundo": ante
cualquier rechazo por límite de tasa (cualquiera sea su forma exacta), la respuesta correcta es la misma
—esperar y reintentar—, y `ClienteTiendanube` (R7) ya centraliza esa lógica sin distinguir endpoints.

**Alternativas consideradas**:
- *Resolver `product_id` en el momento del envío, con una consulta previa a Tiendanube*: rechazada —
  agregaría una llamada de lectura extra por cada envío de escritura, exactamente lo que la consolidación
  (R3) busca evitar, y es información que ya está disponible gratis en el momento de vincular.
- *Guardar `product_id` en `tn_orden_items` en vez de en `tn_variante_producto`*: no alcanza para el caso
  de vínculos creados desde la pantalla dedicada (FR-024), que no parten de una orden.

## R7 — Reintentos y logging: reutilización total de `ClienteTiendanube`. Kill-switch: reutilizado **más** un corte propio previo

**Verificado en código**: `ClienteTiendanube` (spec 015) ya implementa, para cualquier llamada que pase
por `enviar()`/`obtener()`: el guard de función avanzada desactivada, el kill-switch de modo sólo lectura
sobre operaciones de escritura, reintentos con espera creciente ante 429/5xx, y registro en
`tn_operaciones_log` de cada resultado (éxito/error/bloqueada) — mismo diseño que `ClienteMercadoLibre`
(spec 011), sin el ciclo de renovación de token porque el `access_token` de Tiendanube no vence (spec
015). El envío de stock de esta spec es una escritura (`POST`) como cualquier otra: llamando a
`$this->cliente->enviar('sincronizar_stock', 'POST', "/products/{$productId}/variants/stock", [...])` se
obtienen gratis FR-013 y FR-016 (reintentos y logging) sin escribir ninguna lógica propia.

**Mismo matiz que la spec 013 (su R7) ya documentó para Mercado Libre**: `ClienteTiendanube` bloquea (o
falla) cada llamada **individualmente** — si `SincronizadorStock` no cortara antes, con función
desactivada o sólo lectura activo terminaría llamando a `enviar()` una vez por cada vínculo pendiente,
generando **N** registros de "bloqueada" en el historial (uno por vínculo) en lugar de uno solo. Eso
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

## Resumen de decisiones

| # | Decisión | Ubicación en el código |
|---|---|---|
| R1 | Rama Tiendanube dentro del `MovimientoStockObserver` ya existente | `app/Observers/MovimientoStockObserver.php` (extender) |
| R2 | Exclusión de bucle vía `origen_type`/`origen_id` → `Venta::origen === 'tiendanube'` | mismo Observer |
| R3 | Flag `stock_pendiente` en `tn_variante_producto`, sin cola | migración + `TiendanubeVarianteProducto` |
| R4 | Dos comandos programados en orden, sin invocación cruzada | `bootstrap/app.php` |
| R5 | Sin acción — cubierto automáticamente cuando Egresos resuelva su brecha | — |
| R6 | `POST /products/{product_id}/variants/stock`, `action: replace`; agregar `tn_product_id` a `tn_variante_producto` | `SincronizadorStock` + migración |
| R7 | Reutilizar `ClienteTiendanube` sin cambios + corte propio previo al `foreach` | `app/Services/Tiendanube/SincronizadorStock.php` (nuevo) |
