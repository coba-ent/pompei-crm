# Implementation Plan: Gestión de precios de Mercado Libre desde una Lista de Precios del CRM

**Branch**: `016-lista-precio-mercadolibre` | **Date**: 2026-07-29 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/016-lista-precio-mercadolibre/spec.md` (reescrita por
completo — ver "Nota de revisión" en el spec: el campo Lista de Precios de Mercado Libre pasa de ser una
etiqueta informativa a ser el mecanismo de gestión/sincronización de precios hacia Mercado Libre).

## Summary

Agregar `ml_configuracion.lista_precio_id` (FK opcional a `listas_precio`) como la Lista de Precios que
gestiona los precios de las publicaciones de Mercado Libre vinculadas. Cuando el precio de un producto
**vinculado** cambia dentro de esa lista — vía el modal de edición de Producto o vía importación masiva —
un Observer sobre `PrecioProducto` dispara, después del `COMMIT` de la transacción de guardado, el envío
inmediato del nuevo precio a la publicación correspondiente, sin cron ni corrida programada. Se agrega
también una acción manual "Sincronizar precios ahora" (reintento + respaldo para vínculos creados después
de un cambio de precio) y un push inmediato de todos los vínculos vigentes cuando cambia cuál es la Lista
de Precios configurada.

Reutiliza al 100% la infraestructura de las specs 011/012/013: `ml_publicacion_producto` (vínculo 1:1
producto↔publicación), `ClienteMercadoLibre` (kill-switch, reintentos, historial), y replica el patrón
exacto de `SincronizadorStock`/`MovimientoStockObserver` (spec 013), con una única diferencia estructural:
el disparador es un evento de escritura sobre `precios_producto`, no una corrida programada — por eso no
hay consolidación ni comando Artisan nuevo (ver [research.md](./research.md) R1-R4).

## Technical Context

**Language/Version**: PHP 8.2+ / Laravel 12

**Primary Dependencies**: Eloquent (Observers, `DB::afterCommit()`) · `ClienteMercadoLibre` (spec 011, sin
cambios) · `Cache::lock` · Yajra DataTables (extensión de columnas existentes) · Select2 · Toastr

**Storage**: MySQL. Sin tablas nuevas. Una columna nueva en `ml_configuracion` (`lista_precio_id`) y
cuatro columnas nuevas en `ml_publicacion_producto` (estado de sincronización de precio, mismo patrón que
las cuatro ya agregadas por la spec 013 para stock).

**Testing**: PHPUnit (Feature tests), `Http::fake()` para simular la API de Mercado Libre — mismo patrón
que `tests/Feature/Integraciones/` de las specs 011/012/013.

**Target Platform**: mismo entorno que specs 011/012/013 (hosting compartido y VPS). A diferencia de la
013, esta spec no agrega ningún proceso programado — el disparo es por evento, dentro del mismo ciclo de
request/response que ya guarda el precio — por lo que hereda la restricción de portabilidad sólo en el
sentido de "no depende de un `queue:work` garantizado" (research.md R1/R2), no en el sentido de un cron
nuevo.

**Project Type**: aplicación web monolítica (Laravel + Blade), single-tenant.

**Performance Goals**: el envío de un precio (evento único, un `PUT`) no debe agregar una demora
perceptible al guardado del modal de Producto — se ejecuta después del `COMMIT` (`DB::afterCommit()`),
nunca dentro de la transacción, y usa el mismo cliente HTTP con timeout ya acotado de `ClienteMercadoLibre`.
"Sincronizar precios ahora" debe responder de forma inmediata al usuario, igual que "Sincronizar stock
ahora" (spec 013).

**Constraints**: sin procesos de larga duración garantizados · límites de solicitudes de la API de
Mercado Libre (mitigado por reutilizar el reintento con espera creciente ya existente) · un fallo en la
llamada a Mercado Libre nunca debe revertir el guardado del precio en el CRM (research.md R2) · esta spec
no debe alterar el cálculo de precios de las Ventas convertidas desde Mercado Libre (FR-019) ni asignarles
Lista de Precios (FR-020) — restricciones de correctitud heredadas de la spec 012, no de performance.

**Scale/Scope**: un único negocio, una única cuenta de Mercado Libre. Volumen esperado: decenas de
vínculos activos, cambios de precio esporádicos (no de alta frecuencia como el stock). Sin pantallas
nuevas — se extienden tres ya construidas (configuración, listado de vinculaciones, pantalla de Mercado
Libre con el botón manual).

## Constitution Check

*GATE: debe pasar antes de la Fase 0. Re-evaluado tras la Fase 1.*

| Principio | Estado | Justificación |
|---|---|---|
| **I. Documentación de dominio como fuente de verdad** | ✅ Pasa | Sin contradicción: la spec reescrita reemplaza el borrador anterior (que nunca llegó a reflejarse en `docs/`, porque no se había implementado). Actualización de `docs/documentacion_principal_crm.md` y `docs/modelo_datos.md` programada antes de `/speckit-tasks` (ver spec, "Impacto en la documentación de dominio"). |
| **II. Desarrollo spec-driven** | ✅ Pasa | Spec 016 reescrita y clarificada (decisiones ya integradas en la sección Clarifications) antes de planear. |
| **III. Corrección fiscal innegociable** | ✅ Pasa | No toca CAE, tipo de comprobante, ni borrado físico. FR-019 blinda explícitamente que el cálculo de importes/IVA de la Venta convertida desde Mercado Libre no cambia — el requisito más sensible de este principio queda intacto por diseño, igual que en la versión anterior del spec. |
| **IV. Testing donde hay dinero o impacto fiscal** | ✅ Pasa | Esta spec sí mueve dinero de forma directa (escribe precios en publicaciones activas de venta): tests obligatorios sobre disparo por evento (FR-004/FR-005), no-disparo fuera de alcance (FR-006), push inmediato al cambiar de lista (FR-007), reintento/registro de error (FR-009/FR-010), cortes de escritura (FR-011/FR-012), no concurrencia (FR-015) y las dos exclusiones sobre Ventas (FR-019/FR-020) — ver spec, Restricciones de diseño y entorno § Testing. |
| **V. Convenciones Laravel + dominio en español** | ✅ Pasa | `lista_precio_id`, `precio_pendiente`/`precio_sincronizado_en`/`precio_error`/`precio_error_en`, `PrecioProductoObserver`, `SincronizadorPrecios` — snake_case/PascalCase en español, sin `empresa_id`; mismo patrón que `MovimientoStockObserver`/`SincronizadorStock` (spec 013). |

No hay violaciones — sin Complexity Tracking.

### Re-evaluación post-Fase 1

✅ Pasa. El diseño de la Fase 1 (`data-model.md`, `research.md`) no introduce ninguna pieza de
arquitectura nueva para el proyecto: reutiliza el patrón Observer ya existente (`VentaObserver`,
`CompraObserver`, `MovimientoStockObserver`), el único cliente de API ya existente
(`ClienteMercadoLibre`), y `DB::afterCommit()` (mecanismo nativo de Laravel, sin dependencia nueva) en
lugar de una cola. El único servicio nuevo (`SincronizadorPrecios`) es la contraparte directa de
`SincronizadorStock`, con la misma forma. La complejidad agregada es la mínima necesaria para conectar
"cambió un precio" con "avisarle a Mercado Libre" sin bloquear transacciones ni duplicar lógica entre los
dos caminos de escritura existentes.

## Project Structure

### Documentation (this feature)

```text
specs/016-lista-precio-mercadolibre/
├── plan.md                # Este archivo
├── research.md            # Fase 0 — R1 a R9
├── data-model.md          # Fase 1 — columnas nuevas y su ciclo de vida
├── quickstart.md          # Fase 1 — guía de validación end-to-end
├── contracts/
│   └── rutas-internas.md  # Fase 1 — ruta nueva + extensión de datatable/configuración
├── checklists/
│   └── requirements.md
└── tasks.md               # Generado por /speckit-tasks
```

### Source Code (repository root)

```text
app/
├── Observers/
│   ├── VentaObserver.php                     # existente — NO se toca
│   ├── CompraObserver.php                    # existente — NO se toca
│   ├── MovimientoStockObserver.php           # existente (spec 013) — NO se toca
│   └── PrecioProductoObserver.php            # NUEVO — research.md R1/R2, disparo por evento
├── Models/
│   └── PrecioProducto.php                    # EXTENDER — se le registra el observer (#[ObservedBy] o AppServiceProvider)
├── Models/Integraciones/
│   ├── MercadoLibreConfiguracion.php         # EXTENDER — lista_precio_id (fillable) + relación listaPrecio()
│   └── MercadoLibrePublicacionProducto.php   # EXTENDER — 4 columnas nuevas (fillable) + scope pendientesPrecio()
├── Http/Requests/Integraciones/
│   └── GuardarConfiguracionVentasMercadoLibreRequest.php  # EXTENDER — regla lista_precio_id
├── Http/Controllers/Integraciones/
│   └── MercadoLibreConfiguracionController.php  # EXTENDER — pasar $listasPrecio a la vista; disparar sincronizarListaCompleta() en guardarVentas() (FR-007)
├── Http/Controllers/Ingresos/
│   ├── MercadoLibreVentaController.php       # EXTENDER — acción "sincronizarPrecios" (US3), ruta la expone /productos, no /ingresos/mercadolibre (corrección de UX, contracts §1)
│   └── MercadoLibreVinculacionController.php # EXTENDER — exponer estado de precio en el datatable (US2/US4)
└── Services/MercadoLibre/
    ├── ClienteMercadoLibre.php               # existente — NO se toca (research.md R6)
    └── SincronizadorPrecios.php              # NUEVO — enviarUno() / ejecutar() / sincronizarListaCompleta()

database/migrations/                          # 2 alter (ml_configuracion, ml_publicacion_producto)
resources/views/configuracion/mercadolibre/
└── index.blade.php                           # EXTENDER — <select> Lista de Precios (Select2)
resources/views/ingresos/mercadolibre/
└── vinculaciones.blade.php                   # EXTENDER — columnas de estado de precio
resources/views/productos/
└── index.blade.php                           # EXTENDER — botón "Sincronizar precios ahora" (corrección de UX — no en Ingresos → Mercado Libre)
resources/js/mercadolibre.js                  # EXTENDER — leer/guardar lista_precio_id
resources/js/productos.js                     # EXTENDER — AJAX de "Sincronizar precios ahora" (corrección de UX — no mercadolibre-ventas.js)
tests/Feature/Integraciones/                  # NUEVO — tests de esta spec (PrecioProductoObserverTest, SincronizadorPreciosTest o similar)
```

**Structure Decision**: cero directorios nuevos. El único elemento estructuralmente nuevo por tipo es
`app/Observers/PrecioProductoObserver.php` (mismo directorio que `MovimientoStockObserver`) y
`app/Services/MercadoLibre/SincronizadorPrecios.php` (mismo directorio que `SincronizadorStock`) — ambos
son la contraparte directa, archivo por archivo, de piezas ya existentes de la spec 013. El resto son
extensiones de archivos que las specs 011/012/013 ya crearon para exactamente este propósito
(configuración, vinculación y sincronización de Mercado Libre).

## Enfoque técnico por área

### 1. Dato y persistencia

Migración `add_lista_precio_field_to_ml_configuracion_table` agrega `lista_precio_id` (FK nullable →
`listas_precio.id`, `nullOnDelete()`). Migración `add_precio_fields_to_ml_publicacion_producto_table`
agrega `precio_pendiente`/`precio_sincronizado_en`/`precio_error`/`precio_error_en`, calcadas de las
cuatro columnas de stock ya existentes (data-model.md). `fillable` + relación `listaPrecio(): BelongsTo`
en `MercadoLibreConfiguracion`; `fillable` + `scopePendientesPrecio()` en `MercadoLibrePublicacionProducto`.

### 2. Configuración (pantalla + guardado + push al cambiar de lista)

`MercadoLibreConfiguracionController::index()` pasa `$listasPrecio =
ListaPrecio::where('activo', true)->orderBy('nombre')->get()` a la vista (mismo query ya usado en
`ProductoController`/`VentaController`/`PresupuestoController` para este mismo propósito — `ListaPrecio`
no tiene un scope `activos()` propio). El Request agrega
`'lista_precio_id' => ['nullable', 'exists:listas_precio,id']` (research.md R9). `guardarVentas()`
detecta si `lista_precio_id` cambió (comparando el valor previo antes del `update()`) y, si cambió y el
nuevo valor no es `null`, llama a `SincronizadorPrecios::sincronizarListaCompleta($nuevoValor)` después de
persistir — FR-007, respetando los mismos cortes de kill-switch que cualquier otro envío (contracts/
rutas-internas.md §3).

### 3. Disparo por evento (Observer + `DB::afterCommit()`)

`PrecioProductoObserver::saved(PrecioProducto $precio)`: si `$precio->lista_precio_id !==
MercadoLibreConfiguracion::actual()->lista_precio_id` (o la configuración no tiene ninguna lista), no hace
nada (FR-006). Busca `MercadoLibrePublicacionProducto::where('producto_id', $precio->producto_id)->first()`;
si no hay vínculo, no hace nada (FR-006). Si pasa ambos filtros, registra
`DB::afterCommit(function () use ($vinculo, $precio) { $vinculo->update(['precio_pendiente' => true]);
app(SincronizadorPrecios::class)->enviarUno($vinculo, (float) $precio->precio); })` — el `update()` a
pendiente y el envío quedan **fuera** de cualquier transacción abierta por el llamador (research.md R2).

### 4. Envío (`SincronizadorPrecios`)

Mismo esqueleto que `SincronizadorStock` para los cortes y el manejo de errores (research.md R5-R6):

- `enviarUno(MercadoLibrePublicacionProducto $vinculo, float $precio): bool` — **primero** marca
  `$vinculo->update(['precio_pendiente' => true])`, incondicionalmente y antes de evaluar ningún corte
  (research.md R4: el pendiente es el mecanismo de respaldo, no sólo el disparador — así un intento
  bloqueado también queda "conservando el pendiente para el próximo intento válido", FR-011/FR-012, en
  vez de no marcarse nunca porque el corte se evaluó antes). Recién después aplica los cortes de
  FR-011/FR-012 (función desactivada / sólo lectura / conexión caída) igual que
  `SincronizadorStock::verificarCortes()`, con un único registro de bloqueo por invocación; si no está
  bloqueado, llama a
  `ClienteMercadoLibre::enviar('sincronizar_precio', 'PUT', "/items/{$vinculo->ml_item_id}", ['price' => $precio])`;
  éxito → `precio_pendiente = false`, `precio_sincronizado_en = now()`, limpia error; fallo no transitorio
  → `precio_error`/`precio_error_en`, deja `precio_pendiente = true` (FR-010). Es el único llamador
  cuando el disparador es el Observer (un solo vínculo).
- `ejecutar(): array` — candado propio `Cache::lock('ml:sincronizar_precios', 300)` (FR-015, independiente
  del de stock/órdenes); aplica **su propio** chequeo de cortes **antes** del `foreach`, con un único
  registro si está bloqueado y **sin llamar a `enviarUno()` para ningún vínculo** en ese caso (research.md
  R5 — evita un registro de bloqueo por cada vínculo pendiente); si no está bloqueado, itera
  `MercadoLibrePublicacionProducto::pendientesPrecio()->with('producto')->get()`, resuelve el precio
  vigente de cada uno (`producto->precios()->where('lista_precio_id', $listaConfigurada)->value('precio')`)
  y llama a `enviarUno()`; si un producto ya no tiene precio en la lista configurada, se saltea sin marcar
  error (nada que enviar).
- `sincronizarListaCompleta(int $listaPrecioId): array` — mismo chequeo de cortes previo que `ejecutar()`
  (único registro si está bloqueado, sin iterar ningún vínculo en ese caso); si no está bloqueado, recorre
  `MercadoLibrePublicacionProducto::with('producto')->get()`, resuelve el precio de cada producto en
  `$listaPrecioId` y llama a `enviarUno()` para los que tengan precio cargado ahí (FR-007, US5).

### 5. Acción manual y visibilidad

`MercadoLibreVentaController` agrega la acción `sincronizarPrecios` (AJAX, Toastr, sin recarga — mismo
patrón que `sincronizarStock` ya existente), delegando a `SincronizadorPrecios::ejecutar()`. La ruta que
la expone vive en `/productos/sincronizar-precios-ml` (no en `/ingresos/mercadolibre/*`): corrección de
UX posterior a la implementación inicial — el botón "Sincronizar precios ahora" se pidió en la pantalla
de Productos, no junto a "Sincronizar ahora"/"Sincronizar stock ahora" (contracts §1). El controlador y
el servicio no cambian, sólo el punto de entrada HTTP y el disparador de UI (`resources/js/productos.js`
en vez de `mercadolibre-ventas.js`).
`MercadoLibreVinculacionController::datatable()` agrega tres columnas derivadas: `precio_estado`
(`sincronizado`/`pendiente`/`error`, mismo criterio que `stockEstado()`, vía un método `precioEstado()`
análogo), `precio_sincronizado_en`, y `precio_error`/`precio_error_en` como tooltip (FR-017).

## Complexity Tracking

*(vacío — sin violaciones que justificar; toda la complejidad agregada replica un patrón ya existente y
probado en la spec 013)*
