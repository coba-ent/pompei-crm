# Implementation Plan: Sincronización de stock del CRM hacia Mercado Libre

**Branch**: `013-stock-mercadolibre` | **Date**: 2026-07-28 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/013-stock-mercadolibre/spec.md`

## Summary

Cerrar el riesgo de sobreventa documentado en la spec 012: cuando el stock de un producto vinculado a
una publicación de Mercado Libre cambia en el CRM (Venta manual, ajuste, transferencia — y, a futuro sin
cambios en esta feature, Compra), empujar la cantidad disponible resultante hacia Mercado Libre, en
lote, en la misma corrida programada que ya trae las órdenes, sin que una orden de Mercado Libre ya
ingresada dispare un envío de vuelta.

El enfoque técnico se apoya íntegramente en infraestructura ya construida y probada por las specs 011/012:
`ClienteMercadoLibre` como único punto de salida (reintentos, kill-switch, historial — ver
[research.md R7](./research.md)), `StockService`/`MovimientoStock` (spec 002, cableado a Ventas por la
012) como única fuente de verdad del stock, y `ml_publicacion_producto` (spec 012) como vínculo sobre el
que se apoya todo. Lo genuinamente nuevo son tres piezas: un Observer que detecta y consolida cambios
pendientes ([research.md R1-R3](./research.md)), un sincronizador que los empuja, y un comando programado
que corre después del de órdenes ([research.md R4](./research.md)).

## Technical Context

**Language/Version**: PHP 8.2+ / Laravel 12

**Primary Dependencies**: Eloquent (Observers) · `ClienteMercadoLibre` (spec 011, sin cambios) ·
`StockService` (spec 002, sin cambios) · Laravel Scheduler · `Cache::lock` · Yajra DataTables
(extensión de columnas existentes) · Toastr

**Storage**: MySQL. Sin tablas nuevas. Columnas nuevas en `ml_publicacion_producto` (estado de
sincronización de stock) y en `ml_configuracion` (marca de última sincronización de stock).

**Testing**: PHPUnit (Feature tests), `Http::fake()` para simular la API de Mercado Libre — mismo patrón
que `tests/Feature/Integraciones/` de las specs 011/012.

**Target Platform**: hosting compartido (tarea programada del sistema) y VPS con colas — mismo código,
misma restricción de portabilidad ya vigente desde la spec 011/012.

**Project Type**: aplicación web monolítica (Laravel + Blade), single-tenant.

**Performance Goals**: una corrida de sincronización de stock con hasta ~200 vínculos pendientes debe
completarse dentro del límite de ejecución típico de hosting compartido; "Sincronizar stock ahora" debe
responder de forma inmediata al usuario.

**Constraints**: sin procesos de larga duración garantizados · límites de solicitudes de la API de
Mercado Libre (mitigado por la consolidación de R3) · el push nunca debe publicar cantidad negativa.

**Scale/Scope**: un único negocio, una única cuenta de Mercado Libre, un único depósito relevante para la
integración. Volumen esperado: decenas de vínculos activos. Sin pantallas nuevas — se extienden dos ya
construidas (listado de órdenes, vinculaciones) más la de configuración.

## Constitution Check

*GATE: debe pasar antes de la Fase 0. Re-evaluado tras la Fase 1.*

| Principio | Estado | Justificación |
|---|---|---|
| **I. Documentación de dominio como fuente de verdad** | ✅ Pasa | Se verificó R5 (Compras sin stock, brecha ya documentada) — no hay contradicción, sólo una nota de alcance. Se actualiza `docs §3.6/§5.2` y `docs/modelo_datos.md §10` antes de `/speckit-tasks`. |
| **II. Desarrollo spec-driven** | ✅ Pasa | Spec 013 escrita, clarificada (ambigüedades resueltas por continuidad con la 012) y con checklist en verde antes de planear. |
| **III. Corrección fiscal innegociable** | ✅ Pasa (no aplica directamente) | Esta spec no crea documentos fiscales ni toca comprobantes; opera sobre stock. No introduce ninguna excepción a las reglas de comprobante ya vigentes. |
| **IV. Testing donde hay dinero o impacto fiscal** | ✅ Pasa | Tests obligatorios sobre: exclusión de movimientos de origen Mercado Libre (FR-002, evita bucle), consolidación a un único envío (FR-003), tope en cero (FR-004), no concurrencia (FR-008), continuidad tras rechazo individual (FR-015). Impacta stock, que la constitución trata igual que dinero por su efecto en ventas futuras. |
| **V. Convenciones Laravel + dominio en español** | ✅ Pasa | Observer, columnas y comando en español (`MovimientoStockObserver`, `stock_pendiente`, `mercadolibre:sincronizar-stock`); sin `empresa_id`; se reutiliza el patrón Observer ya usado por `VentaObserver`/`CompraObserver`. |

### Re-evaluación post-Fase 1

✅ **Pasa**. El diseño no agrega ninguna capa de abstracción nueva al proyecto: reutiliza el patrón
Observer ya existente, el único cliente de API ya existente, y el patrón de comando programado +
`Cache::lock` ya existente. No se crean tablas nuevas ni un mecanismo de cola — R3 lo justifica
explícitamente. La complejidad agregada es la mínima necesaria para conectar "algo cambió" con "hay que
avisarle a Mercado Libre" sin abrir una ventana de bucle.

## Project Structure

### Documentation (this feature)

```text
specs/013-stock-mercadolibre/
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
│   ├── VentaObserver.php                     # existente — NO se toca
│   ├── CompraObserver.php                    # existente — NO se toca
│   └── MovimientoStockObserver.php           # NUEVO — R1/R2, detecta y marca pendiente
├── Models/
│   └── MovimientoStock.php                   # existente — se le registra el observer (AppServiceProvider o #[ObservedBy])
├── Models/Integraciones/
│   ├── MercadoLibreConfiguracion.php         # EXTENDER — 2 columnas nuevas (fillable)
│   └── MercadoLibrePublicacionProducto.php   # EXTENDER — 4 columnas nuevas (fillable) + scope pendientes()
├── Services/MercadoLibre/
│   ├── ClienteMercadoLibre.php               # existente — NO se toca (R7)
│   └── SincronizadorStock.php                # NUEVO — candado, consolidación, envío, manejo de error por vínculo
├── Http/Controllers/Ingresos/
│   └── MercadoLibreVentaController.php       # EXTENDER — acción "sincronizarStock" (US3)
├── Http/Controllers/Ingresos/
│   └── MercadoLibreVinculacionController.php # EXTENDER — exponer estado de stock en el datatable (US1/US4)
├── Console/Commands/
│   ├── SincronizarOrdenesMercadoLibre.php    # existente — NO se toca
│   └── SincronizarStockMercadoLibre.php      # NUEVO — mismo patrón, frecuencia compartida
└── (sin Excepciones nuevas: se reutilizan las de spec 011/012)

database/migrations/                          # 2 alter (ml_publicacion_producto, ml_configuracion)
resources/views/ingresos/mercadolibre/
├── index.blade.php                           # EXTENDER — botón "Sincronizar stock ahora"
├── vinculaciones.blade.php                   # EXTENDER — columnas de estado de stock
└── _row_actions_vinculacion.blade.php        # EXTENDER si el error necesita acción visible
resources/views/configuracion/mercadolibre/
└── index.blade.php                           # EXTENDER — última sincronización de stock + advertencia actualizada
resources/js/mercadolibre-ventas.js           # EXTENDER — AJAX de "Sincronizar stock ahora"
bootstrap/app.php                             # EXTENDER — segundo `$schedule->command(...)`, después del de órdenes
routes/web.php                                # EXTENDER — 1 ruta nueva
tests/Feature/Integraciones/                  # NUEVO — tests de esta spec
```

**Structure Decision**: cero directorios nuevos. Todo vive donde ya vive su análogo de la spec 012: el
sincronizador junto a `SincronizadorOrdenes` en `Services/MercadoLibre/`, el comando junto al de órdenes
en `Console/Commands/`, las vistas extendiendo las pantallas ya construidas. El único elemento
estructuralmente nuevo es `app/Observers/MovimientoStockObserver.php`, y va en `Observers/` porque ahí
ya viven `VentaObserver` y `CompraObserver` — mismo patrón, mismo directorio.

## Enfoque técnico por área

### 1. Detección y consolidación (Observer)

`MovimientoStockObserver::created(MovimientoStock $movimiento)`: resuelve el depósito configurado para
Mercado Libre (`MercadoLibreConfiguracion::actual()->deposito_id`, o el depósito por defecto si es
`null` — mismo criterio que `StockDeVenta::resolverDeposito()`); si `$movimiento->deposito_id` no
coincide, no hace nada (FR-001, sólo importa ese depósito). Si coincide, verifica la exclusión de bucle
(R2): si `origen_type` es `Venta::class` y esa Venta tiene `origen === 'mercadolibre'`, no hace nada
(FR-002). Busca `MercadoLibrePublicacionProducto::where('producto_id', $movimiento->producto_id)->first()`;
si no hay vínculo, no hace nada (FR-005). Si pasa los tres filtros, `update(['stock_pendiente' => true])`
sin condicionales adicionales — no importa si ya estaba pendiente (idempotente por diseño).

### 2. Envío (`SincronizadorStock`)

Mismo esqueleto que `SincronizadorOrdenes` (candado, cortes, log de bloqueo único): toma
`Cache::lock('ml:sincronizar_stock', 300)` (FR-008, candado propio — independiente del de órdenes, para
que "Sincronizar stock ahora" no dependa de que la sincronización de órdenes esté libre). Itera
`MercadoLibrePublicacionProducto::where('stock_pendiente', true)->get()`; por cada uno calcula
`max(0, StockService::disponibilidad($producto, null, $depositoMl))` (FR-004) y llama a
`ClienteMercadoLibre::enviar('sincronizar_stock', 'PUT', "/items/{$vinculo->ml_item_id}", ['available_quantity' => $cantidad])`.//
El manejo de reintentos/kill-switch/logging ya lo resuelve `ClienteMercadoLibre` (R7); `SincronizadorStock`
sólo interpreta la `RespuestaMercadoLibre`: éxito → `stock_pendiente = false`,
`stock_sincronizado_en = now()`, limpia el error; fallo → dos ramas:
- **Bloqueada** (función desactivada / sólo lectura / conexión caída, FR-009/FR-010): corta el `foreach`
  entero con un único registro (igual que `SincronizadorOrdenes::verificarCortes()`), porque ningún
  vínculo va a poder enviarse en esta corrida.
- **Error de un vínculo puntual** (publicación pausada/cerrada, u otro rechazo de Mercado Libre,
  FR-014): guarda `stock_error`/`stock_error_en` en **ese** vínculo, deja `stock_pendiente = true`, y
  **continúa con el resto** (FR-015) — no se corta el `foreach`.

### 3. Programación

`mercadolibre:sincronizar-stock` sigue el mismo patrón que `mercadolibre:sincronizar-ordenes`
(`app/Console/Commands/SincronizarOrdenesMercadoLibre.php`): compara `ml_configuracion.stock_ultima_sync_en`
contra `frecuencia_sync_minutos` (mismo campo, reutilizado — Clarifications Q3) y decide si corresponde.
Se registra en `bootstrap/app.php` con `everyMinute()->withoutOverlapping()`, **a continuación** del de
órdenes en el mismo closure de `withSchedule()` (R4 — el orden de declaración basta, sin invocación
cruzada).

### 4. Visibilidad

`MercadoLibreVinculacionController::datatable()` agrega tres columnas derivadas: estado (`sincronizado`
si `!stock_pendiente && !stock_error`, `pendiente` si `stock_pendiente`, `error` si `stock_error` con
`stock_pendiente` true — FR-017), `stock_sincronizado_en`, y `stock_error`/`stock_error_en` como tooltip.
`MercadoLibreVentaController` agrega la acción `sincronizarStock` (AJAX, Toastr, sin recarga — mismo
patrón que `sincronizar()` ya existente para órdenes) y la pantalla de configuración muestra
`stock_ultima_sync_en`/`stock_ultima_sync_resultado` junto al panel ya existente de la sincronización de
órdenes.

## Complexity Tracking

*Sin violaciones que justificar: no se agregan tablas, colas, ni patrones nuevos al proyecto.*
