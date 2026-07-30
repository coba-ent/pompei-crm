# Research: Gestión de precios de Mercado Libre desde una Lista de Precios del CRM

## R1 — Punto único de disparo: Observer sobre `PrecioProducto`, no en cada controlador/servicio

**Decisión**: crear `app/Observers/PrecioProductoObserver.php`, escuchado en el evento `saved` del modelo
`PrecioProducto` (cubre tanto `created` como `updated`), en lugar de agregar la llamada de sincronización
dentro de `ProductoController::sincronizarPrecios()` y de `ImportadorFilas::crearProducto()` por separado.

**Rationale**: ya se confirmó (investigación previa a esta spec) que existen **dos** caminos de escritura
distintos sobre `precios_producto`:

- `ProductoController::sincronizarPrecios()` (línea 749-766): `$producto->precios()->updateOrCreate(...)`.
- `ImportadorFilas::crearProducto()` (línea 353-358): `$producto->precios()->create([...])`.

Ambos disparan los eventos Eloquent estándar (`creating`/`created`/`updating`/`updated`/`saving`/`saved`)
porque ambos pasan por el ciclo de vida normal del modelo (ninguno usa `insert()` masivo ni query builder
crudo). Un Observer en `saved` es el único punto que cubre los dos caminos sin duplicar lógica — y
cualquier tercer camino de escritura que se agregue en el futuro (por ejemplo, una eventual edición masiva
de precios) queda cubierto automáticamente sin tocar esta spec. Mismo patrón arquitectónico que
`MovimientoStockObserver` (spec 013) sobre `MovimientoStock`.

**Alternativas consideradas**:
- Llamar a un servicio de sincronización explícitamente desde cada uno de los dos controladores/servicios
  de escritura: descartada por duplicar la condición "¿es la lista configurada + el producto está
  vinculado?" en dos lugares, con riesgo de que un tercer camino futuro la omita.
- Un Job en cola disparado por un evento de dominio (`PrecioProductoActualizado`): descartada por
  introducir infraestructura de colas que este proyecto evita explícitamente por la restricción de
  portabilidad a hosting compartido ya establecida en specs 011/012/013 (ningún flujo de Mercado Libre
  depende hoy de un `queue:work` corriendo).

## R2 — El envío a Mercado Libre debe diferirse hasta después del COMMIT de la transacción

**Decisión**: el Observer no llama a `ClienteMercadoLibre` directamente en `saved()`. En cambio, registra
el envío con `DB::afterCommit(fn () => ...)`. Si no hay ninguna transacción abierta en ese momento,
Laravel ejecuta el callback de inmediato (mismo hilo, misma request) — no hace falta ninguna
infraestructura adicional.

**Rationale**: `ProductoController::store()`/`update()` envuelven todo el guardado del producto —
incluida la llamada a `sincronizarPrecios()` — dentro de `DB::transaction(function () {...})`
(ProductoController.php:323-354 y 377-390). Si el Observer llamara a la API de Mercado Libre de forma
síncrona dentro de ese `saved()`, la llamada HTTP externa ocurriría **con la transacción de base de datos
todavía abierta** (fila de `precios_producto` bloqueada) y, más grave, un fallo o excepción en la llamada
a Mercado Libre podría interrumpir el `DB::transaction()` y hacer *rollback* del guardado del Producto
completo — exactamente lo que el spec prohíbe (FR-010: un rechazo de Mercado Libre debe dejar el vínculo
"pendiente con error", nunca afectar el guardado del precio en el CRM). `DB::afterCommit()` es el
mecanismo estándar de Laravel para este caso exacto: efectos secundarios externos que deben ocurrir sólo
si la transacción realmente se confirmó, y cuyo resultado no debe poder revertir esa transacción.

**Alternativas consideradas**:
- Envío síncrono directo en `saved()`: descartada por el riesgo de rollback cruzado explicado arriba.
- Cola/Job asíncrono: descartada por la misma razón que en R1 (portabilidad, sin `queue:work` garantizado).
- Mover la llamada fuera del Observer, a una línea explícita después de `DB::transaction()` en cada
  controlador: descartada porque reintroduce la duplicación que R1 evita, y porque `ImportadorFilas` no
  necesariamente envuelve cada producto en su propia transacción individual (habría que auditar y
  mantener sincronizados dos lugares en vez de uno).

## R3 — Sin consolidación: el disparo por evento es de a un vínculo por vez

**Decisión**: a diferencia de `SincronizadorStock` (spec 013), que consolida múltiples movimientos en un
único valor final antes de una corrida programada, esta spec no necesita consolidar nada: cada cambio de
precio dispara, vía `DB::afterCommit()`, un intento de envío inmediato con el precio ya guardado en ese
momento para ese producto en la lista configurada. Si dos cambios de precio del mismo producto ocurren en
rápida sucesión (dos requests HTTP distintas), cada uno es una fila `precios_producto` ya persistida en su
propia transacción — no hay ventana de "varios cambios pendientes acumulados" que consolidar, porque no
hay corrida programada esperando: el propio guardado dispara el envío en el momento.

**Rationale**: la consolidación de la spec 013 existe porque el stock cambia con mucha frecuencia y por
muchas causas distintas (Ventas, ajustes) entre corridas programadas espaciadas por minutos; el precio, en
cambio, cambia por una acción manual y deliberada del usuario (editar el modal de Producto, o correr una
importación puntual) — no hay "corrida" que agrupe varios cambios, cada cambio ya es, en sí mismo, el
evento a sincronizar.

## R4 — Reutilizar el campo de "pendiente" también como respaldo, no sólo como disparador

**Decisión**: aunque el envío es inmediato (R2/R3), `SincronizadorPrecios::enviarUno()` marca
`$vinculo->precio_pendiente = true` como su **primer paso**, incondicionalmente, **antes** de evaluar
cualquier corte de kill-switch/modo sólo lectura/conexión caída y antes de llamar a
`ClienteMercadoLibre`; sólo se vuelve a `false` si el envío tiene éxito. Marcarlo antes de los cortes (y
no sólo antes del `PUT`) es necesario porque un intento bloqueado nunca llega a intentar el `PUT` — si el
pendiente se marcara después del chequeo de cortes, un vínculo bloqueado quedaría sin marca alguna,
violando FR-011/FR-012 ("conservando el pendiente para el próximo intento válido"). Esto da, gratis, el
mecanismo de reintento manual (US3/FR-014): un vínculo que falló, uno que fue bloqueado, o uno que no
tenía publicación vinculada en el momento del cambio (US3, escenario 4), queda con `precio_pendiente =
true` sin necesidad de ningún camino de código adicional para "marcarlo pendiente" — es el mismo estado
que ya tendría si el envío automático lo hubiera intentado y fallado.

**Rationale**: reutilizar exactamente el mismo campo y el mismo significado que `stock_pendiente` de la
spec 013 evita introducir un segundo vocabulario de estados; sólo cambia *cuándo* se dispara el primer
intento (evento vs. corrida programada).

## R5 — Un único servicio `SincronizadorPrecios` con un método por-vínculo reutilizado por los tres disparadores

**Decisión**: `app/Services/MercadoLibre/SincronizadorPrecios.php`, con:

- `enviarUno(MercadoLibrePublicacionProducto $vinculo, float $precio): bool` — verifica los cortes de
  kill-switch/modo sólo lectura/conexión caída (FR-011/FR-012, con **un único registro en el historial**
  por invocación bloqueada — mismo criterio que `SincronizadorStock::verificarCortes()`); si no está
  bloqueado, hace el `PUT` a `ClienteMercadoLibre` para el vínculo con el precio ya resuelto, interpreta
  la `RespuestaMercadoLibre` y actualiza los campos `precio_*` de ese vínculo. Devuelve si tuvo éxito. Es
  el único llamador directo cuando el disparador es el evento de cambio de precio (un solo vínculo, un
  solo posible bloqueo, un solo registro correcto).
- `ejecutar(): array` — candado propio `Cache::lock('ml:sincronizar_precios', 300)` (FR-015); aplica
  **su propio** chequeo de cortes **antes** de entrar al `foreach` (idéntico a
  `SincronizadorStock::verificarCortes()`: si está bloqueado, corta con un único registro y **no llama a
  `enviarUno()` para ningún vínculo** — evita N registros redundantes en una corrida con muchos
  pendientes); si no está bloqueado, barre
  `MercadoLibrePublicacionProducto::pendientesPrecio()->with('producto')->get()`, resuelve el precio
  vigente de cada uno en la lista configurada y llama a `enviarUno()` por cada uno (que ya no encuentra
  motivo de bloqueo, porque el corte de la corrida completa ya se verificó). Usado por la acción manual
  "Sincronizar precios ahora" (US3) y funciona como reintento.
- `sincronizarListaCompleta(int $listaPrecioId): array` — mismo patrón que `ejecutar()`: su propio corte
  previo con registro único, y sólo si pasa recorre los vínculos vigentes, resuelve el precio de cada
  producto en la nueva lista (si existe) y llama a `enviarUno()` — usado por FR-007 (cambiar la Lista de
  Precios configurada).

Los tres disparadores (Observer vía `afterCommit`, acción manual, cambio de configuración) terminan en el
mismo método `enviarUno()`, que es el único lugar que sabe hacer el `PUT` a `/items/{id}` — pero el corte
de kill-switch/modo sólo lectura/conexión caída se verifica en **dos niveles**: una vez por corrida
completa en `ejecutar()`/`sincronizarListaCompleta()` (evita registros duplicados en un barrido), y una
vez más dentro de `enviarUno()` para cubrir la llamada directa del Observer (un único vínculo, sin
corrida que la envuelva). Reutiliza `ClienteMercadoLibre` sin cambios (mismo patrón que R6 de esta spec /
R7 de la spec 013).

**Rationale**: evita triplicar la lógica de "cómo se llama a Mercado Libre y qué se hace con la
respuesta" en el Observer, en el controlador de la acción manual y en el controlador de configuración —
exactamente la clase de abstracción que sí se justifica (tres llamadores reales, no uno hipotético). El
chequeo de cortes en dos niveles replica, a propósito, el mismo criterio de "un único registro por corte"
que ya usa `SincronizadorStock::verificarCortes()` (spec 013) — sin él, "Sincronizar precios ahora" con
modo sólo lectura activo y 50 vínculos pendientes generaría 50 registros de bloqueo idénticos en el
historial de operaciones en lugar de uno solo.

**Alternativas consideradas**: método único `ejecutar()` sin `enviarUno()` público, invocado también desde
el Observer con un query filtrado a un solo vínculo — descartada porque fuerza al disparo por evento
(que ya conoce el precio exacto recién guardado) a volver a leerlo de la base de datos y a pasar por el
mismo candado global que la acción manual, cuando no hace falta: el disparo por evento es de un único
vínculo, no un barrido.

## R6 — Sin cambios a `ClienteMercadoLibre`, kill-switch ni historial de operaciones

**Decisión**: se reutiliza `ClienteMercadoLibre::enviar('sincronizar_precio', 'PUT', "/items/{$mlItemId}",
['price' => $precio])` sin ninguna modificación a esa clase. El manejo de reintento con espera creciente,
el kill-switch (función desactivada / modo sólo lectura / conexión caída) y el registro en
`ml_operaciones_log` ya están resueltos ahí (specs 011/012/013) y aplican igual para este `PUT` que para
el de stock — cambia sólo la clave `operacion` (`'sincronizar_precio'` en vez de `'sincronizar_stock'`) y
el cuerpo del payload (`price` en vez de `available_quantity`).

**Rationale**: es exactamente el mismo tipo de operación de escritura que ya soporta `ClienteMercadoLibre`
— un `PUT` sobre un atributo de `/items/{id}` — documentado como patrón válido en
`MERCADOLIBRE_NOTAS_TECNICAS.md` (ya referenciado por la spec 013 para el atributo `available_quantity`).

## R7 — Columnas nuevas en `ml_publicacion_producto`, mismo patrón que las de stock

**Decisión**: migración que agrega `precio_pendiente` (boolean, default false), `precio_sincronizado_en`
(datetime nullable), `precio_error` (string 255 nullable), `precio_error_en` (datetime nullable) —
calcadas 1:1 de `stock_pendiente`/`stock_sincronizado_en`/`stock_error`/`stock_error_en` agregadas por la
spec 013 (`database/migrations/2026_08_03_060001_add_stock_fields_to_ml_publicacion_producto_table.php`).
Se agrega el scope `scopePendientesPrecio()` en `MercadoLibrePublicacionProducto`, análogo a
`scopePendientes()` ya existente para stock.

**Rationale**: consistencia total con el patrón ya construido y ya probado; cero necesidad de diseñar un
esquema nuevo.

## R8 — `ml_configuracion.lista_precio_id`: sin columnas de "última sincronización" propias

**Decisión**: a diferencia de `stock_ultima_sync_en`/`stock_ultima_sync_resultado` (que existen porque la
sincronización de stock corre en una corrida programada sin una request HTTP asociada, y la pantalla
necesita mostrar "cuándo corrió por última vez sin que nadie la mirara"), esta spec no agrega columnas
análogas a `ml_configuracion` para precio: el estado relevante ya vive por-vínculo en
`ml_publicacion_producto` (R7), y la acción manual "Sincronizar precios ahora" ya informa su resultado por
notificación en el momento (FR-014) — no hay una corrida silenciosa en segundo plano cuyo resultado haya
que persistir para mostrarlo después.

**Rationale**: evita agregar dos columnas sin lector real (nada corre en background para este flujo);
mantiene la superficie de cambio en `ml_configuracion` al mínimo indispensable: una sola columna nueva,
`lista_precio_id`.

## R9 — Validación del campo de configuración

**Decisión**: `'lista_precio_id' => ['nullable', 'exists:listas_precio,id']` en
`GuardarConfiguracionVentasMercadoLibreRequest` (mismo Request ya usado por `deposito_id` y
`categoria_venta_id`), sin `activo` como condición — mismo criterio que ya usa `categoria_venta_id`
(Assumptions "sin validación de activa").

**Rationale**: replica exactamente la regla ya existente para `categoria_venta_id` en el mismo Request.
