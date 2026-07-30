# Implementation Plan: Sincronización de stock y precios del CRM hacia Tiendanube

**Branch**: `018-stock-tiendanube` | **Date**: 2026-07-29 (ampliado 2026-07-30) | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/018-stock-tiendanube/spec.md`

> **Ampliación 30/07/2026**: este plan cubría sólo stock; se agrega la contraparte de precios (US5-US9 del
> spec, FR-021 a FR-040), calcada de `specs/016-lista-precio-mercadolibre/plan.md` (patrón ya
> implementado para Mercado Libre) y adaptada a la vinculación por variante de Tiendanube y a la tool
> `update_stock_and_price` (que acepta `price` como campo independiente de `stock` en el mismo esquema de
> ítem, research.md R6/R9). También se corrige aquí una imprecisión que ya tenía **el spec** original:
> `tiendanube:sincronizar-stock` es un comando programado **independiente**, no un paso dentro de
> `tiendanube:sincronizar-ordenes` — este plan.md ya lo tenía bien diseñado así (§3 "Programación" más
> abajo), la corrección fue sólo textual en spec.md FR-006.

## Summary

> ⚠️ **Corrección post-spec 019**: este plan asumía el endpoint REST público
> `POST /products/{product_id}/variants/stock` (`action: replace`) contra `api.tiendanube.com`. Esa
> conexión (spec 015) quedó inutilizable; la real es `specs/019-tiendanube-conexion-mcp/` (OAuth 2.1 +
> JSON-RPC contra `admin-mcp.tiendanube.com`). La llamada real es la tool `update_stock_and_price`
> (`ClienteTiendanube::escribir('update_stock_and_price', ['updates' => [...]])`), verificada contra la
> cuenta real el 30/07/2026, que además acepta **hasta 50 variantes por llamada** — mejora de diseño
> respecto del plan original (una llamada por vínculo): `SincronizadorStock` ahora lotea. El resto de
> esta sección (Observer, consolidación, orden de comandos) no cambia — sólo el mecanismo de envío.

Cerrar el riesgo de sobreventa documentado en la spec 017: cuando el stock de un producto vinculado a
una variante de Tiendanube cambia en el CRM (Venta manual, ajuste, transferencia — y, a futuro sin
cambios en esta feature, Compra), empujar la cantidad disponible resultante hacia Tiendanube, en lote, en
la misma corrida programada que ya trae las órdenes, sin que una orden de Tiendanube ya ingresada
dispare un envío de vuelta.

El enfoque técnico es el mismo que la spec 013 (Mercado Libre) aplicó sobre la 012, adaptado a las
diferencias reales de la API de Tiendanube, verificadas empíricamente contra la tool MCP real (no contra
la documentación REST pública, ver [research.md R6](./research.md), corregido): la tool
`update_stock_and_price` exige el `product_id` de Tiendanube por cada variante del lote (no alcanza con
el `variant_id` que hoy guarda `tn_variante_producto`, spec 017) y admite fijar un valor absoluto de
stock directamente (parámetro `stock`), que es exactamente la semántica que necesita esta spec — y
admite hasta 50 variantes por llamada. Se reutiliza íntegramente `ClienteTiendanube` (spec 019) como
único punto de salida, `StockService`/`MovimientoStock` (spec 002) como única fuente de verdad del
stock, y `tn_variante_producto` (spec 017) como vínculo sobre el que se apoya todo. Lo genuinamente
nuevo son cuatro piezas: una columna `tn_product_id` en `tn_variante_producto` (R6), un Observer que
detecta y consolida cambios pendientes (research.md R1-R3), un sincronizador que los empuja **en lotes
de hasta 50** (R6/R7), y un comando programado que corre después del de órdenes (R4).

**Precios (ampliación)**: agregar `tn_configuracion.lista_precio_id` como la Lista de Precios que
gestiona los precios de las variantes vinculadas de Tiendanube. Cuando el precio de un producto vinculado
cambia dentro de esa lista (modal de Producto o importación masiva), un Observer dispara, después del
`COMMIT`, el envío inmediato del nuevo precio a la variante correspondiente — sin cron. Reutiliza al 100%
`tn_variante_producto` (spec 017), `ClienteTiendanube` (spec 019) y replica el patrón exacto de
`SincronizadorPrecios`/`PrecioProductoObserver` que la spec 016 ya construyó para Mercado Libre —
extendiendo esas mismas clases con una rama Tiendanube, no creando un segundo observer/servicio paralelo.
Única diferencia real: el envío usa la misma tool `update_stock_and_price` que el flujo de stock (con
`price` en vez de `stock` en el ítem), no un endpoint separado como en Mercado Libre.

**Advertencia de secuencia de implementación**: a diferencia de la spec 013 (que se apoyó en la 012 ya
implementada en código), a la fecha de este plan la spec 017 **todavía no tiene código propio** —
existen sólo sus artefactos de spec-kit (`spec.md`/`plan.md`/`tasks.md`), mientras que la 015 sí está
implementada (`ClienteTiendanube`, `TiendanubeConfiguracion`, `TiendanubeOperacionLog`, controlador de
configuración). Esta spec 018 **no puede implementarse de forma aislada**: sus tareas de `/speckit-tasks`
deben ejecutarse **después de** (o junto con) las de la 017, porque extiende tablas y clases que la 017
todavía tiene que crear (`tn_variante_producto`, `TiendanubeVentaController`,
`TiendanubeVinculacionController`, etc.). Este plan asume esa infraestructura como si ya existiera —tal
como la describe `specs/017-ventas-tiendanube/plan.md` y `data-model.md`— para poder diseñarse sobre ella;
no es una suposición nueva de esta spec, es el mismo patrón de "planear contra el diseño ya acordado de
la dependencia" que cualquier spec de este proyecto usa con sus specs previas.

## Technical Context

**Language/Version**: PHP 8.2+ / Laravel 12

**Primary Dependencies**: Eloquent (Observers) · `ClienteTiendanube` (spec 019, JSON-RPC/MCP, sin cambios
para esta spec) · `StockService` (spec 002, sin cambios) · Laravel Scheduler · `Cache::lock` · Yajra
DataTables (extensión de columnas existentes) · Toastr

**Storage**: MySQL. Sin tablas nuevas. Columnas nuevas en `tn_variante_producto` (estado de
sincronización de stock + `tn_product_id`, ver R6; y, ampliación, cuatro columnas análogas de estado de
sincronización de precio) y en `tn_configuracion` (marca de última sincronización de stock; y,
ampliación, `lista_precio_id`).

**Testing**: PHPUnit (Feature tests), `Http::fake()` para simular las tools JSON-RPC del servidor MCP —
mismo patrón que `tests/Feature/Integraciones/` de las specs 019/017.

**Target Platform**: hosting compartido (tarea programada del sistema) y VPS con colas — mismo código,
misma restricción de portabilidad ya vigente desde la spec 019/017.

**Project Type**: aplicación web monolítica (Laravel + Blade), single-tenant.

**Performance Goals**: una corrida de sincronización de stock con hasta ~200 vínculos pendientes debe
completarse dentro del límite de ejecución típico de hosting compartido (con el loteo de hasta 50 por
llamada, 200 vínculos son ≤4 llamadas, no 200); "Sincronizar stock ahora" debe responder de forma
inmediata al usuario.

**Constraints**: sin procesos de larga duración garantizados · límite de tasa de la tool
`update_stock_and_price` no verificado públicamente (mitigado por la consolidación de R3 y el loteo de
hasta 50 por llamada, ver R6 corregido) · el push nunca debe publicar cantidad negativa · la tool exige
`product_id` por cada variante del lote, no sólo `variant_id` (R6).

**Scale/Scope**: un único negocio, una única tienda de Tiendanube, un único depósito relevante para la
integración. Volumen esperado: decenas de vínculos activos, cambios de precio esporádicos (no de alta
frecuencia como el stock). Sin pantallas nuevas — se extienden dos ya diseñadas por la spec 017 (listado
de órdenes, vinculación de variantes), la de configuración, y la pantalla de Productos (botón de precio).

## Constitution Check

*GATE: debe pasar antes de la Fase 0. Re-evaluado tras la Fase 1.*

| Principio | Estado | Justificación |
|---|---|---|
| **I. Documentación de dominio como fuente de verdad** | ✅ Pasa | `docs/documentacion_principal_crm.md` §3.2.quater/§5.3 ya anotaban esta spec como "spec 018, misma relación que la 013 respecto de la 012". Se actualizan esas secciones y `docs/modelo_datos.md §12` antes de `/speckit-tasks`. |
| **II. Desarrollo spec-driven** | ✅ Pasa | Spec 018 escrita, clarificada (ambigüedades resueltas por continuidad con la 013) y con checklist en verde antes de planear. |
| **III. Corrección fiscal innegociable** | ✅ Pasa (no aplica directamente) | Esta spec no crea documentos fiscales ni toca comprobantes; opera sobre stock. No introduce ninguna excepción a las reglas de comprobante ya vigentes. |
| **IV. Testing donde hay dinero o impacto fiscal** | ✅ Pasa | Tests obligatorios sobre: exclusión de movimientos de origen Tiendanube (FR-002, evita bucle), consolidación a un único envío (FR-003), tope en cero (FR-004), no concurrencia (FR-008), continuidad tras rechazo individual (FR-015). Impacta stock, que la constitución trata igual que dinero por su efecto en ventas futuras. Ampliación: disparo por evento sin importar el camino de escritura (FR-024/FR-025), no disparo fuera de alcance (FR-026), push inmediato al cambiar de lista (FR-028), reintento/registro de error (FR-030/FR-031), cortes de escritura (FR-032/FR-033), no concurrencia (FR-036) y exclusiones sobre el cálculo de precio de Venta (FR-039/FR-040) — precio impacta directamente lo que el negocio cobra. |
| **V. Convenciones Laravel + dominio en español** | ✅ Pasa | Observer, columnas y comando en español (reutiliza `MovimientoStockObserver` ya existente, agrega rama Tiendanube; `stock_pendiente`, `tiendanube:sincronizar-stock`); sin `empresa_id`; se reutiliza el patrón Observer ya usado por `VentaObserver`/`CompraObserver`/la rama Mercado Libre del propio `MovimientoStockObserver`. Ampliación: `lista_precio_id`, `precio_pendiente`/`precio_sincronizado_en`/`precio_error`/`precio_error_en`, rama Tiendanube en `PrecioProductoObserver` (spec 016) — mismo criterio. |

### Re-evaluación post-Fase 1

✅ **Pasa**. El diseño no agrega ninguna capa de abstracción nueva al proyecto: reutiliza el Observer ya
existente (`MovimientoStockObserver`, sólo se le agrega una rama), el único cliente de API ya existente, y
el patrón de comando programado + `Cache::lock` ya existente. No se crean tablas nuevas ni un mecanismo
de cola. La única pieza de diseño no trivial es R6 (necesidad de `tn_product_id`), resuelta con una
columna nueva poblada en el momento de vincular, sin retrocompatibilidad que resolver porque la 017 aún
no tiene datos en producción.

## Project Structure

### Documentation (this feature)

```text
specs/018-stock-tiendanube/
├── plan.md              # Este archivo
├── research.md          # Fase 0 — R1 a R7
├── data-model.md         # Fase 1 — columnas nuevas y su ciclo de vida
├── quickstart.md         # Fase 1 — guía de validación end-to-end
├── contracts/
│   └── rutas-internas.md   # Fase 1 — endpoint nuevo + extensión de datatable
├── checklists/
│   └── requirements.md
└── tasks.md              # Generado por /speckit-tasks
```

### Source Code (repository root)

```text
app/
├── Observers/
│   ├── VentaObserver.php                      # existente — NO se toca
│   ├── CompraObserver.php                     # existente — NO se toca
│   └── MovimientoStockObserver.php            # EXTENDER (spec 013) — agrega rama Tiendanube
├── Models/
│   └── MovimientoStock.php                    # existente — sin cambios (ya observado)
├── Models/Integraciones/
│   ├── TiendanubeConfiguracion.php             # EXTENDER (spec 015/017) — 2 columnas nuevas (fillable); ampliación: + lista_precio_id + relación listaPrecio()
│   └── TiendanubeVarianteProducto.php          # EXTENDER (spec 017) — 5 columnas nuevas (fillable) + scope pendientes(); ampliación: + 4 columnas de precio + scope pendientesPrecio()
├── Services/Tiendanube/
│   ├── ClienteTiendanube.php                   # existente — NO se toca (R7)
│   ├── SincronizadorStock.php                  # NUEVO — candado, consolidación, envío, manejo de error por vínculo
│   └── SincronizadorPrecios.php                # NUEVO (ampliación) — enviarUno()/ejecutar()/sincronizarListaCompleta(), contraparte de la 016
├── Observers/
│   └── PrecioProductoObserver.php              # EXTENDER (spec 016) — agrega rama Tiendanube (ampliación)
├── Http/Requests/Integraciones/
│   └── GuardarConfiguracionVentasTiendanubeRequest.php  # EXTENDER (spec 017) — regla lista_precio_id (ampliación)
├── Http/Controllers/Ingresos/
│   ├── TiendanubeVentaController.php           # EXTENDER (spec 017) — acciones "sincronizarStock" (US3) y "sincronizarPrecios" (ampliación, US7, research.md R10 — NO se toca MercadoLibreVentaController)
│   └── TiendanubeVinculacionController.php     # EXTENDER (spec 017) — exponer estado de stock Y precio en el datatable (US1/US4/US8)
├── Http/Controllers/Integraciones/
│   └── TiendanubeConfiguracionController.php   # EXTENDER (spec 017) — pasar $listasPrecio a la vista; disparar sincronizarListaCompleta() en guardarVentas() (ampliación, FR-028)
├── Console/Commands/
│   ├── SincronizarOrdenesTiendanube.php        # EXTENDER (spec 017) — NO cambia lógica, sólo convive en el schedule
│   └── SincronizarStockTiendanube.php          # NUEVO — mismo patrón, frecuencia compartida (sin comando propio para precio — evento, no cron)
└── (sin Excepciones nuevas: se reutilizan las de spec 015/017)

database/migrations/                            # 2 alter stock (tn_variante_producto, tn_configuracion) + 2 alter precio (ampliación: lista_precio_id en tn_configuracion, 4 columnas de precio en tn_variante_producto)
resources/views/ingresos/tiendanube/
├── index.blade.php                             # EXTENDER (spec 017) — botón "Sincronizar stock ahora"
├── vinculaciones.blade.php                     # EXTENDER (spec 017) — columnas de estado de stock Y precio (ampliación)
└── _row_actions_vinculacion.blade.php           # EXTENDER si el error necesita acción visible
resources/views/configuracion/tiendanube/
└── index.blade.php                             # EXTENDER (spec 015) — última sincronización de stock + advertencia actualizada; ampliación: <select> Lista de Precios (Select2)
resources/views/productos/
└── index.blade.php                             # EXTENDER (spec 002, ampliación) — el botón "Sincronizar precios ahora" (spec 016) sigue siendo uno solo; su handler de JS pasa a disparar también la request de Tiendanube (research.md R10)
resources/js/tiendanube-ventas.js                # EXTENDER (spec 017) — AJAX de "Sincronizar stock ahora"
resources/js/tiendanube.js                       # EXTENDER (ampliación) — leer/guardar lista_precio_id en configuración
resources/js/productos.js                        # EXTENDER (spec 016, ampliación) — el handler de "Sincronizar precios ahora" dispara `sincronizar-precios-ml` Y `sincronizar-precios-tn`, combina el resultado en un único toast (research.md R10)
bootstrap/app.php                                # EXTENDER — segundo `$schedule->command(...)`, después del de órdenes de Tiendanube (sólo stock — precio no lleva cron)
routes/web.php                                   # EXTENDER — 2 rutas nuevas: sincronizarStock (Tiendanube) y sincronizar-precios-tn (Tiendanube, controlador propio — NO se toca la ruta de Mercado Libre, research.md R10)
tests/Feature/Integraciones/                     # NUEVO — tests de esta spec (stock y precio)
```

**Structure Decision**: cero directorios nuevos. Todo vive donde ya vive (o va a vivir, spec 017) su
análogo: el sincronizador junto a los servicios de `Services/Tiendanube/`, el comando junto al de órdenes
en `Console/Commands/`, las vistas extendiendo las pantallas ya diseñadas por la 017. El único elemento
compartido con Mercado Libre es `MovimientoStockObserver`: en vez de duplicar un segundo Observer sobre el
mismo modelo (`MovimientoStock` sólo admite un observer efectivo de forma simple, y dos observers
independientes sobre la misma tabla sería redundante para una misma responsabilidad — "reaccionar a un
movimiento"), se le agrega una rama Tiendanube junto a la que ya dejó la spec 013 para Mercado Libre.
Mismo criterio que llevó a `StockDeVenta::resolverDeposito()` a tener una rama por integración (spec 017,
Enfoque técnico §5).

## Enfoque técnico por área

### 1. Detección y consolidación (Observer)

`MovimientoStockObserver::created(MovimientoStock $movimiento)` ya existe desde la spec 013 con una rama
Mercado Libre; se le agrega una rama Tiendanube con el mismo esqueleto: resuelve el depósito configurado
para Tiendanube (`TiendanubeConfiguracion::actual()->depositoEfectivo()`, ya construido por la spec 017
Enfoque técnico §5); si `$movimiento->deposito_id` no coincide, no hace nada (FR-001, sólo importa ese
depósito). Verifica la exclusión de bucle (R2): si `origen_type` es `Venta::class` y esa Venta tiene
`origen === 'tiendanube'`, no hace nada (FR-002). Busca
`TiendanubeVarianteProducto::where('producto_id', $movimiento->producto_id)->first()`; si no hay vínculo,
no hace nada (FR-005). Si pasa los tres filtros, `update(['stock_pendiente' => true])` sin condicionales
adicionales — no importa si ya estaba pendiente (idempotente por diseño). Ambas ramas (Mercado Libre y
Tiendanube) son independientes entre sí: un mismo movimiento puede marcar pendiente a lo sumo un vínculo
por integración, porque un producto vinculado a Mercado Libre y uno vinculado a Tiendanube son registros
distintos en tablas distintas.

### 2. Envío (`SincronizadorStock`) — CORREGIDO: lotes de hasta 50, no una llamada por vínculo

Mismo esqueleto que el `SincronizadorStock` de Mercado Libre (spec 013) y que `SincronizadorOrdenes` de
Tiendanube (spec 017): candado propio `Cache::lock('tn:sincronizar_stock', 300)` (FR-008, independiente
del de órdenes de Tiendanube — para que "Sincronizar stock ahora" no dependa de que la sincronización de
órdenes esté libre, y también independiente del candado de stock de Mercado Libre, que es una integración
distinta). Itera `TiendanubeVarianteProducto::where('stock_pendiente', true)->get()`, calcula
`max(0, StockService::disponibilidad($producto, null, $depositoTn))` (FR-004) por cada uno, y arma un
`array` de `['product_id' => $vinculo->tn_product_id, 'variant_id' => $vinculo->variant_id, 'stock' =>
$cantidad]` — **corrección post-019**: no se llama a `ClienteTiendanube` por vínculo; se agrupan en
`array_chunk($actualizaciones, 50)` (límite real de la tool, R6) y se llama a
`ClienteTiendanube::escribir('update_stock_and_price', ['updates' => $lote])` una vez por chunk.

El manejo de reintentos/kill-switch/logging a nivel de **llamada** ya lo resuelve `ClienteTiendanube`
(R7); `SincronizadorStock` interpreta el resultado de cada chunk:

- **Chunk bloqueado** (función desactivada / sólo lectura / conexión caída, FR-009/FR-010): corta el
  proceso entero con un único registro (igual que `SincronizadorOrdenes::verificarCortes()` de
  Tiendanube), porque ningún vínculo va a poder enviarse en esta corrida — este corte sigue siendo
  previo a armar ningún chunk, no cambia por el loteo.
- **Éxito del chunk, por ítem**: para cada vínculo del chunk que la respuesta marque exitoso →
  `stock_pendiente = false`, `stock_sincronizado_en = now()`, limpia el error.
- **Error de un vínculo puntual dentro de un chunk exitoso en general** (producto/variante eliminado o
  inexistente, u otro rechazo de Tiendanube para ese ítem, FR-014): guarda `stock_error`/`stock_error_en`
  en **ese** vínculo, deja `stock_pendiente = true`, sin afectar a los demás ítems del mismo chunk ni a
  los chunks siguientes (FR-015).

> ⚠️ **Sin verificar empíricamente**: el formato exacto de la respuesta de `update_stock_and_price` para
> un chunk con éxitos y fallos mezclados (¿día por ítem el resultado, como `bulk_delete_products`, o
> falla el chunk entero ante cualquier ítem inválido?) no se probó contra la cuenta real — esta spec no
> ejecuta escrituras reales (restricción de la 019). **T032a (nuevo, ver tasks.md) queda pendiente**:
> confirmar el formato real antes de dar por cerrada la implementación de esta pieza.

### 3. Programación

`tiendanube:sincronizar-stock` sigue el mismo patrón que `tiendanube:sincronizar-ordenes` (spec 017):
compara `tn_configuracion.stock_ultima_sync_en` contra `frecuencia_sync_minutos` (mismo campo, reutilizado
— Clarifications) y decide si corresponde. Se registra en `bootstrap/app.php` con
`everyMinute()->withoutOverlapping()`, **a continuación** del de órdenes de Tiendanube en el mismo closure
de `withSchedule()` (R4 — el orden de declaración basta, sin invocación cruzada).

### 4. Visibilidad

`TiendanubeVinculacionController::datatable()` agrega tres columnas derivadas: estado (`sincronizado` si
`!stock_pendiente && !stock_error`, `pendiente` si `stock_pendiente`, `error` si `stock_error` con
`stock_pendiente` true — FR-017), `stock_sincronizado_en`, y `stock_error`/`stock_error_en` como tooltip.
`TiendanubeVentaController` agrega la acción `sincronizarStock` (AJAX, Toastr, sin recarga — mismo patrón
que `sincronizar()` ya diseñado para órdenes) y la pantalla de configuración muestra
`stock_ultima_sync_en`/`stock_ultima_sync_resultado` junto al panel ya existente de la sincronización de
órdenes.

### 5. Precios — dato y persistencia (ampliación)

Migración `add_lista_precio_field_to_tn_configuracion_table` agrega `lista_precio_id` (FK nullable →
`listas_precio.id`, `nullOnDelete()`), calcada de la de la spec 016 para `ml_configuracion`. Migración
`add_precio_fields_to_tn_variante_producto_table` agrega `precio_pendiente`/`precio_sincronizado_en`/
`precio_error`/`precio_error_en`, calcadas de las cuatro ya agregadas por esta misma spec para stock (§1
de este plan). `fillable` + relación `listaPrecio(): BelongsTo` en `TiendanubeConfiguracion`; `fillable` +
`scopePendientesPrecio()` en `TiendanubeVarianteProducto`.

### 6. Precios — configuración (pantalla + guardado + push al cambiar de lista)

`TiendanubeConfiguracionController::index()` pasa `$listasPrecio` a la vista (mismo query ya usado por
`MercadoLibreConfiguracionController`). `GuardarConfiguracionVentasTiendanubeRequest` agrega
`'lista_precio_id' => ['nullable', 'exists:listas_precio,id']`. `guardarVentas()` detecta si
`lista_precio_id` cambió (comparando el valor previo antes del `update()`) y, si cambió y el nuevo valor
no es `null`, llama a `SincronizadorPrecios::sincronizarListaCompleta($nuevoValor)` después de persistir
(FR-028) — mismo mecanismo que `MercadoLibreConfiguracionController::guardarVentas()` ya implementa.

### 7. Precios — disparo por evento (Observer + `DB::afterCommit()`)

`PrecioProductoObserver::saved(PrecioProducto $precio)` (spec 016) se extiende con una segunda rama,
independiente de la de Mercado Libre: si `$precio->lista_precio_id === TiendanubeConfiguracion::actual()->lista_precio_id`
(y no es null) y existe `TiendanubeVarianteProducto::where('producto_id', $precio->producto_id)->first()`,
registra `DB::afterCommit(fn () => $vinculo->update(['precio_pendiente' => true]) y
app(SincronizadorPrecios::class)->enviarUno($vinculo, (float) $precio->precio))` — mismo patrón que la
rama Mercado Libre, evaluado de forma completamente independiente (un cambio de precio puede disparar
ninguna, una, o las dos ramas según qué listas estén configuradas en cada integración y qué vínculos
tenga el producto).

### 8. Precios — envío (`SincronizadorPrecios`)

Mismo esqueleto que la contraparte de Mercado Libre (spec 016 plan.md §4), reemplazando el `PUT
/items/{id}` de Mercado Libre por la tool compartida de Tiendanube: `enviarUno()` marca
`precio_pendiente = true` incondicionalmente, aplica los cortes (función desactivada/sólo lectura/conexión
caída) con un único registro si bloquea, y si no, llama a
`ClienteTiendanube::escribir('update_stock_and_price', ['updates' => [['product_id' => ..., 'variant_id'
=> ..., 'price' => $precio]]])` — un ítem por llamada (a diferencia de `SincronizadorStock`, que lotea
hasta 50: los cambios de precio son esporádicos y unitarios por evento, no hay nada que consolidar en un
lote). Éxito → `precio_pendiente = false`, `precio_sincronizado_en = now()`; rechazo no transitorio →
`precio_error`/`precio_error_en`, mantiene pendiente. `ejecutar()` (candado propio
`Cache::lock('tn:sincronizar_precios', 300)`, independiente del de stock/órdenes) y
`sincronizarListaCompleta(int $listaPrecioId)` siguen el mismo patrón que sus contrapartes de Mercado
Libre.

### 9. Precios — acción manual y visibilidad

`TiendanubeVentaController` agrega la acción `sincronizarPrecios` (AJAX, delegando a
`Tiendanube\SincronizadorPrecios::ejecutar()`), expuesta en `productos/sincronizar-precios-tn` — ruta y
controlador **propios**, sin tocar el controlador/ruta de Mercado Libre ya deployados (research.md R10
corregido). El botón "Sincronizar precios ahora" en Productos sigue siendo uno solo: su handler en
`resources/js/productos.js` dispara ambas requests (`sincronizar-precios-ml` y `sincronizar-precios-tn`)
al mismo click y combina los dos resultados en un único toast — la fusión ocurre en el cliente, no en el
servidor. `TiendanubeVinculacionController::datatable()` agrega las mismas tres columnas derivadas que ya
tiene su contraparte de Mercado Libre (`precio_estado`, `precio_sincronizado_en`, `precio_error`/
`precio_error_en` como tooltip) — FR-038.

## Complexity Tracking

*Sin violaciones que justificar: no se agregan tablas, colas, ni patrones nuevos al proyecto. La única
pieza de diseño no trivial (necesidad de `tn_product_id`, R6) es una columna, no un patrón nuevo. La
ampliación de precios reutiliza el mismo Observer y el mismo patrón de servicio que la spec 016 ya
implementó y probó para Mercado Libre — extender dos clases existentes, no crear arquitectura nueva.*
