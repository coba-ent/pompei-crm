# Implementation Plan: Sincronización de stock del CRM hacia Tiendanube

**Branch**: `018-stock-tiendanube` | **Date**: 2026-07-29 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/018-stock-tiendanube/spec.md`

## Summary

Cerrar el riesgo de sobreventa documentado en la spec 017: cuando el stock de un producto vinculado a
una variante de Tiendanube cambia en el CRM (Venta manual, ajuste, transferencia — y, a futuro sin
cambios en esta feature, Compra), empujar la cantidad disponible resultante hacia Tiendanube, en lote, en
la misma corrida programada que ya trae las órdenes, sin que una orden de Tiendanube ya ingresada
dispare un envío de vuelta.

El enfoque técnico es el mismo que la spec 013 (Mercado Libre) aplicó sobre la 012, adaptado a dos
diferencias reales de la API de Tiendanube verificadas contra la documentación oficial
(`tiendanube.github.io/api-documentation`, consultada 29/07/2026, ver [research.md R6](./research.md)):
el endpoint de actualización de stock exige el `product_id` de Tiendanube en la ruta (no alcanza con el
`variant_id` que hoy guarda `tn_variante_producto`, spec 017) y admite fijar un valor absoluto
(`action: "replace"`), que es exactamente la semántica que necesita esta spec. Se reutiliza íntegramente
`ClienteTiendanube` (spec 015) como único punto de salida, `StockService`/`MovimientoStock` (spec 002) como
única fuente de verdad del stock, y `tn_variante_producto` (spec 017) como vínculo sobre el que se apoya
todo. Lo genuinamente nuevo son cuatro piezas: una columna `tn_product_id` en `tn_variante_producto`
(R6), un Observer que detecta y consolida cambios pendientes (research.md R1-R3), un sincronizador que
los empuja (R6/R7), y un comando programado que corre después del de órdenes (R4).

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

**Primary Dependencies**: Eloquent (Observers) · `ClienteTiendanube` (spec 015, sin cambios) ·
`StockService` (spec 002, sin cambios) · Laravel Scheduler · `Cache::lock` · Yajra DataTables (extensión
de columnas existentes) · Toastr

**Storage**: MySQL. Sin tablas nuevas. Columnas nuevas en `tn_variante_producto` (estado de
sincronización de stock + `tn_product_id`, ver R6) y en `tn_configuracion` (marca de última
sincronización de stock).

**Testing**: PHPUnit (Feature tests), `Http::fake()` para simular la API de Tiendanube — mismo patrón que
`tests/Feature/Integraciones/` de las specs 015/017.

**Target Platform**: hosting compartido (tarea programada del sistema) y VPS con colas — mismo código,
misma restricción de portabilidad ya vigente desde la spec 015/017.

**Project Type**: aplicación web monolítica (Laravel + Blade), single-tenant.

**Performance Goals**: una corrida de sincronización de stock con hasta ~200 vínculos pendientes debe
completarse dentro del límite de ejecución típico de hosting compartido; "Sincronizar stock ahora" debe
responder de forma inmediata al usuario.

**Constraints**: sin procesos de larga duración garantizados · límite de tasa de la API de Tiendanube
(mitigado por la consolidación de R3; el endpoint de stock usa un token bucket ponderado propio, distinto
del leaky bucket ~2/s de lectura, ver R6) · el push nunca debe publicar cantidad negativa · el endpoint de
stock exige `product_id` en la ruta, no sólo `variant_id` (R6).

**Scale/Scope**: un único negocio, una única tienda de Tiendanube, un único depósito relevante para la
integración. Volumen esperado: decenas de vínculos activos. Sin pantallas nuevas — se extienden dos ya
diseñadas por la spec 017 (listado de órdenes, vinculación de variantes) más la de configuración.

## Constitution Check

*GATE: debe pasar antes de la Fase 0. Re-evaluado tras la Fase 1.*

| Principio | Estado | Justificación |
|---|---|---|
| **I. Documentación de dominio como fuente de verdad** | ✅ Pasa | `docs/documentacion_principal_crm.md` §3.2.quater/§5.3 ya anotaban esta spec como "spec 018, misma relación que la 013 respecto de la 012". Se actualizan esas secciones y `docs/modelo_datos.md §12` antes de `/speckit-tasks`. |
| **II. Desarrollo spec-driven** | ✅ Pasa | Spec 018 escrita, clarificada (ambigüedades resueltas por continuidad con la 013) y con checklist en verde antes de planear. |
| **III. Corrección fiscal innegociable** | ✅ Pasa (no aplica directamente) | Esta spec no crea documentos fiscales ni toca comprobantes; opera sobre stock. No introduce ninguna excepción a las reglas de comprobante ya vigentes. |
| **IV. Testing donde hay dinero o impacto fiscal** | ✅ Pasa | Tests obligatorios sobre: exclusión de movimientos de origen Tiendanube (FR-002, evita bucle), consolidación a un único envío (FR-003), tope en cero (FR-004), no concurrencia (FR-008), continuidad tras rechazo individual (FR-015). Impacta stock, que la constitución trata igual que dinero por su efecto en ventas futuras. |
| **V. Convenciones Laravel + dominio en español** | ✅ Pasa | Observer, columnas y comando en español (reutiliza `MovimientoStockObserver` ya existente, agrega rama Tiendanube; `stock_pendiente`, `tiendanube:sincronizar-stock`); sin `empresa_id`; se reutiliza el patrón Observer ya usado por `VentaObserver`/`CompraObserver`/la rama Mercado Libre del propio `MovimientoStockObserver`. |

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
│   ├── TiendanubeConfiguracion.php             # EXTENDER (spec 015/017) — 2 columnas nuevas (fillable)
│   └── TiendanubeVarianteProducto.php          # EXTENDER (spec 017) — 5 columnas nuevas (fillable) + scope pendientes()
├── Services/Tiendanube/
│   ├── ClienteTiendanube.php                   # existente — NO se toca (R7)
│   └── SincronizadorStock.php                  # NUEVO — candado, consolidación, envío, manejo de error por vínculo
├── Http/Controllers/Ingresos/
│   └── TiendanubeVentaController.php           # EXTENDER (spec 017) — acción "sincronizarStock" (US3)
├── Http/Controllers/Ingresos/
│   └── TiendanubeVinculacionController.php     # EXTENDER (spec 017) — exponer estado de stock en el datatable (US1/US4)
├── Console/Commands/
│   ├── SincronizarOrdenesTiendanube.php        # EXTENDER (spec 017) — NO cambia lógica, sólo convive en el schedule
│   └── SincronizarStockTiendanube.php          # NUEVO — mismo patrón, frecuencia compartida
└── (sin Excepciones nuevas: se reutilizan las de spec 015/017)

database/migrations/                            # 2 alter (tn_variante_producto, tn_configuracion)
resources/views/ingresos/tiendanube/
├── index.blade.php                             # EXTENDER (spec 017) — botón "Sincronizar stock ahora"
├── vinculaciones.blade.php                     # EXTENDER (spec 017) — columnas de estado de stock
└── _row_actions_vinculacion.blade.php           # EXTENDER si el error necesita acción visible
resources/views/configuracion/tiendanube/
└── index.blade.php                             # EXTENDER (spec 015) — última sincronización de stock + advertencia actualizada
resources/js/tiendanube-ventas.js                # EXTENDER (spec 017) — AJAX de "Sincronizar stock ahora"
bootstrap/app.php                                # EXTENDER — segundo `$schedule->command(...)`, después del de órdenes de Tiendanube
routes/web.php                                   # EXTENDER — 1 ruta nueva
tests/Feature/Integraciones/                     # NUEVO — tests de esta spec
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

### 2. Envío (`SincronizadorStock`)

Mismo esqueleto que el `SincronizadorStock` de Mercado Libre (spec 013) y que `SincronizadorOrdenes` de
Tiendanube (spec 017): candado propio `Cache::lock('tn:sincronizar_stock', 300)` (FR-008, independiente
del de órdenes de Tiendanube — para que "Sincronizar stock ahora" no dependa de que la sincronización de
órdenes esté libre, y también independiente del candado de stock de Mercado Libre, que es una integración
distinta). Itera `TiendanubeVarianteProducto::where('stock_pendiente', true)->get()`; por cada uno calcula
`max(0, StockService::disponibilidad($producto, null, $depositoTn))` (FR-004) y llama a
`ClienteTiendanube::enviar('sincronizar_stock', 'POST', "/products/{$vinculo->tn_product_id}/variants/stock", ['action' => 'replace', 'value' => $cantidad, 'id' => $vinculo->variant_id])`
(R6). El manejo de reintentos/kill-switch/logging ya lo resuelve `ClienteTiendanube` (R7); `SincronizadorStock`
sólo interpreta la `RespuestaTiendanube`: éxito → `stock_pendiente = false`, `stock_sincronizado_en =
now()`, limpia el error; fallo → dos ramas:

- **Bloqueada** (función desactivada / sólo lectura / conexión caída, FR-009/FR-010): corta el `foreach`
  entero con un único registro (igual que `SincronizadorOrdenes::verificarCortes()` de Tiendanube), porque
  ningún vínculo va a poder enviarse en esta corrida.
- **Error de un vínculo puntual** (producto/variante eliminado o inexistente en Tiendanube, u otro
  rechazo de Tiendanube, FR-014): guarda `stock_error`/`stock_error_en` en **ese** vínculo, deja
  `stock_pendiente = true`, y **continúa con el resto** (FR-015) — no se corta el `foreach`.

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

## Complexity Tracking

*Sin violaciones que justificar: no se agregan tablas, colas, ni patrones nuevos al proyecto. La única
pieza de diseño no trivial (necesidad de `tn_product_id`, R6) es una columna, no un patrón nuevo.*
