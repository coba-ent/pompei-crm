# Research: Sincronización de stock del CRM hacia Mercado Libre

## R1 — Punto de detección de movimientos: Observer sobre `MovimientoStock`, no tocar cada llamador

**Pregunta**: ¿dónde se engancha la detección de "el stock de un producto vinculado cambió", dado que
hoy sólo `StockDeVenta` (spec 012) llama a `StockService`, pero mañana también lo harán Compras
(brecha documentada, ver R5) y cualquier ajuste manual/transferencia?

**Decisión**: un **Observer de Eloquent sobre el modelo `MovimientoStock`** (`created`), no un cambio en
cada método de `StockService` ni en cada controlador que hoy o mañana mueva stock.

**Rationale**: `StockService::mover()`, `ajustar()` y `transferir()` ya persisten **toda** la casuística
de FR-001 (venta, ajuste manual, transferencia) como una fila de `movimientos_stock` — es el único
punto por el que pasa, sin excepción, cualquier cambio de stock del CRM (`app/Services/Stock/StockService.php`).
Enganchar el disparador ahí, vía Observer, evita:

- Tocar `StockService` (usado desde múltiples módulos, riesgo de romper algo no relacionado).
- Tener que acordarse de marcar "pendiente" en cada nuevo lugar que llame a `StockService` en el
  futuro (por ejemplo, cuando Egresos resuelva la brecha de R5): el Observer lo cubre automáticamente
  porque escucha la tabla, no a los llamadores.
- Duplicar lógica de filtrado (depósito, vínculo, exclusión de origen ML) en varios sitios.

**Alternativas consideradas**:
- *Emitir un evento propio (`StockActualizado`) desde `StockService` y escucharlo*: capa adicional
  innecesaria — `MovimientoStock::created` ya es ese evento, provisto gratis por Eloquent.
- *Enganchar en `VentaObserver`*: sólo cubriría Ventas, no ajustes manuales ni transferencias
  (FR-001 exige "cualquier movimiento").

## R2 — Exclusión de bucle: se resuelve leyendo `origen` en el propio Observer, sin columna nueva

**Pregunta**: ¿cómo distingue el Observer un movimiento que **no** debe disparar sincronización (el que
generó la conversión de una orden de Mercado Libre, spec 012 FR-046) de uno que sí debe?

**Decisión**: `MovimientoStock` ya guarda `origen_type`/`origen_id` polimórfico, y `StockDeVenta` (spec
012) siempre pasa la `Venta` como `$origen` al llamar a `registrarSalida()`/`registrarEntrada()`
(`app/Services/Ingresos/StockDeVenta.php`). La `Venta` ya expone su propio origen mediante la columna
`origen` (enum `manual`/`presupuesto`/`mercadolibre`, spec 012). El Observer verifica: si
`$movimiento->origen_type === Venta::class` y la Venta cargada tiene `origen === 'mercadolibre'`, no
marca nada pendiente. En cualquier otro caso (incluida una Venta manual/presupuesto, un ajuste, una
transferencia, o un `origen` nulo), sí marca.

**Rationale**: no hace falta ninguna columna ni parámetro nuevo — el dato que se necesita ya existe y ya
se persiste desde la spec 012. Es la aplicación más directa posible de la decisión ya tomada en
Clarifications del spec.md.

## R3 — Consolidación: flag booleano en el vínculo, no una cola de eventos

**Pregunta**: ¿cómo se "consolidan" varios movimientos del mismo producto entre dos corridas (Clarifications,
Q3) sin construir una tabla de cola?

**Decisión**: agregar a `ml_publicacion_producto` un flag `stock_pendiente` (booleano). El Observer lo
pone en `true` (si aún no lo estaba, sin verificar duplicados: es un flag, no un contador). El
sincronizador, al procesar un vínculo pendiente, no reconstruye nada a partir de movimientos
individuales: pregunta a `StockService::disponibilidad()` el stock **actual** del producto en el
depósito configurado (ya sea el resultado de uno o de veinte movimientos) y ese es el valor que envía.

**Rationale**: es la implementación más simple posible de "un único valor final, no uno por movimiento"
— no requiere una tabla de eventos ni deduplicación explícita, porque `disponibilidad()` ya devuelve el
acumulado real en cualquier momento. Evita construir infraestructura de cola para un caso que no la
necesita (volumen esperado: decenas de vínculos, spec 012 §Scale/Scope).

**Alternativas consideradas**: tabla `ml_stock_pendientes` con una fila por movimiento — rechazada por
sobre-ingeniería: no aporta nada que el flag + `disponibilidad()` no den ya, y el proyecto no tiene otro
patrón de cola de eventos al que sumarse.

## R4 — Orden de ejecución CRM→ML después de ML→CRM: dos comandos programados en el mismo tick, no uno encadenado

**Pregunta**: ¿cómo se garantiza "inmediatamente después de traer las órdenes nuevas" (Clarifications
Q3, FR-006) sin acoplar en código el sincronizador de stock al de órdenes?

**Decisión**: dos comandos Artisan independientes (`mercadolibre:sincronizar-ordenes`, ya existente, y
`mercadolibre:sincronizar-stock`, nuevo), ambos registrados con `everyMinute()->withoutOverlapping()`
en `bootstrap/app.php`, **en ese orden**. El scheduler de Laravel ejecuta en un mismo tick de
`schedule:run` las tareas debidas de forma secuencial y en el orden en que están declaradas (ninguna usa
`runInBackground()`), así que el de stock corre después del de órdenes sin necesitar invocarlo desde
dentro del primero.

**Rationale**: mantiene la responsabilidad única de cada comando (mismo criterio que ya separa
`SincronizadorOrdenes` de `ConversorOrdenAVenta` en la spec 012) y no depende de que alguien recuerde
llamar al segundo desde el primero si algún día se refactoriza uno de los dos. El acoplamiento real
—que el stock que se envía ya contemple las órdenes recién traídas— queda garantizado por el orden de
declaración, no por una dependencia en código.

**Alternativa considerada**: invocar `SincronizadorStock` al final de
`SincronizarOrdenesMercadoLibre::handle()`. Rechazada: acopla dos comandos de responsabilidad distinta
y complica que "Sincronizar stock ahora" (US3) se pueda disparar solo, sin re-sincronizar órdenes.

## R5 — Compras todavía no mueven stock: no es una contradicción de esta spec

**Verificado en código** (no sólo en docs): `docs/documentacion_principal_crm.md` (línea ~808-814)
documenta que `CompraController` **no genera ningún movimiento de stock** — brecha explícitamente
diferida "cuando se retome Egresos", ajena a la spec 012 y a ésta.

**Impacto en esta spec**: ninguno que bloquee. FR-001 exige que el sistema reaccione a cualquier
movimiento de `MovimientoStock`, sin importar su origen — no exige que esta spec **genere** movimientos
de Compra. Como el disparador es el Observer sobre `MovimientoStock` (R1), el día que Egresos resuelva
esa brecha y las Compras empiecen a llamar a `StockService`, la sincronización hacia Mercado Libre
empieza a cubrirlas automáticamente, sin ningún cambio en esta feature. Se documenta como nota, no como
contradicción del principio I (a diferencia de R1 en la spec 012, que sí bloqueaba un FR entero).

## R6 — Endpoint de actualización de cantidad disponible

**Decisión preliminar**: `PUT /items/{item_id}` con cuerpo `{"available_quantity": N}` — el mecanismo
estándar y más ampliamente documentado de la API de Ítems de Mercado Libre para actualizar stock de una
publicación sin variantes.

**Verificación pendiente al implementar** (mismo criterio que spec 012 aplicó a varios supuestos,
research §R2/§R8): la cuenta de prueba usada en `MERCADOLIBRE_NOTAS_TECNICAS.md` §5 ya está migrada al
modelo **User Products** (tag `user_product_seller`), que cambia varios campos de la publicación
respecto a la documentación clásica (`family_name` en vez de `title`). Se consultó la documentación
oficial del flujo de **Stock Multi Origen** (`stock-multi-origen`, MCP de Mercado Libre): ese flujo
alternativo —`PUT /user-products/{id}/stock/type/seller_warehouse` con header `x-version`— **sólo
aplica a vendedores con el tag `warehouse_management`** (gestión de múltiples depósitos propios), un
tag distinto y más avanzado que el simple `user_product_seller` de la cuenta de prueba. Mientras la
cuenta real del negocio no tenga el tag `warehouse_management` (caso esperado: un solo depósito
relevante para Mercado Libre, spec 013 Clarifications), el `PUT /items/{item_id}` estándar es el
mecanismo correcto. **Antes de implementar**: confirmar con un `GET /users/{id}` contra la cuenta real
qué tags trae, y si apareciera `warehouse_management`, adaptar `SincronizadorStock` al flujo de stock
multi origen en lugar del estándar.

## R7 — Reintentos y logging: reutilización total de `ClienteMercadoLibre`. Kill-switch: reutilizado **más** un corte propio previo

**Verificado en código**: `ClienteMercadoLibre::peticion()` (spec 011) ya implementa, para **cualquier**
llamada que pase por `enviar()`/`obtener()`: el guard de función avanzada desactivada, el kill-switch de
modo sólo lectura sobre operaciones de escritura, reintentos con espera creciente ante 429/5xx
(`ESPERAS_SEGUNDOS = [1, 2, 4]`), renovación de token, y registro en `ml_operaciones_log` de cada
resultado (éxito/error/bloqueada). El envío de stock de esta spec es una escritura (`PUT`) como
cualquier otra: llamando a `$this->cliente->enviar('sincronizar_stock', 'PUT', "/items/{$itemId}", [...])`
se obtienen gratis FR-013 y FR-016 (reintentos y logging) sin escribir ninguna lógica propia.

**Corrección respecto de una versión anterior de esta nota**: FR-009/FR-010 **no** quedan enteramente
cubiertos por ese mecanismo. `ClienteMercadoLibre` bloquea (o falla) cada llamada **individualmente** —
si `SincronizadorStock` no cortara antes, con función desactivada o sólo lectura activo terminaría
llamando a `enviar()` una vez por cada vínculo pendiente, generando **N** registros de "bloqueada" en el
historial (uno por vínculo) en lugar de uno solo, y con la conexión caída generaría **N** intentos
fallidos reales contra la red antes de renunciar. Eso contradice la letra de FR-009/FR-010 ("el sistema
**NO DEBE ejecutar la sincronización** ... mientras ..."), que exige **no arrancar** la corrida, no
"arrancarla y que cada ítem falle". Por eso `SincronizadorStock` sí necesita su propio corte previo al
`foreach` — **exactamente el mismo criterio** por el que `SincronizadorOrdenes::verificarCortes()` (spec
012) tuvo que construir el suyo para el lado de lectura (research.md de la 012, mismo argumento, lado
opuesto: ahí era necesario porque el kill-switch de `ClienteMercadoLibre` sólo cubre escrituras y la
sincronización de órdenes es puro `GET`; acá es necesario porque, aunque el kill-switch sí cubre
escrituras, sólo actúa **por llamada**, no **por corrida**). Ver plan.md §"Enfoque técnico" punto 2 y
tasks.md T010, que ya reflejan esta corrección — la inconsistencia era únicamente de esta nota y de
`contracts/rutas-internas.md §5` (corregido en el mismo cambio).

`SincronizadorStock` necesita, en definitiva: su propio corte previo (FR-009/FR-010, un único registro
por corrida bloqueada) + su propio candado (`Cache::lock`, mismo patrón que
`SincronizadorOrdenes::LOCK_KEY`, para FR-008) + su propio manejo de "qué hacer con el vínculo" ante
éxito o error definitivo (FR-014/FR-015). Lo único genuinamente gratuito, sin código propio, es el
reintento ante 429/5xx (FR-013) y el registro de cada llamada individual (FR-016).

## Resumen de decisiones

| # | Decisión | Ubicación en el código |
|---|---|---|
| R1 | Observer sobre `MovimientoStock::created` | `app/Observers/MovimientoStockObserver.php` (nuevo) |
| R2 | Exclusión de bucle vía `origen_type`/`origen_id` → `Venta::origen` | mismo Observer |
| R3 | Flag `stock_pendiente` en `ml_publicacion_producto`, sin cola | migración + `MercadoLibrePublicacionProducto` |
| R4 | Dos comandos programados en orden, sin invocación cruzada | `bootstrap/app.php` |
| R5 | Sin acción — cubierto automáticamente cuando Egresos resuelva su brecha | — |
| R6 | `PUT /items/{id}` con `available_quantity`; verificar tags de la cuenta real antes de implementar | `SincronizadorStock` |
| R7 | Reutilizar `ClienteMercadoLibre` sin cambios | `app/Services/MercadoLibre/SincronizadorStock.php` (nuevo) |
