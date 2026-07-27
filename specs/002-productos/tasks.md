---
description: "Task list — Base de Datos — Productos & Servicios"
---

# Tasks: Base de Datos — Productos & Servicios

**Input**: Design documents from `specs/002-productos/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/productos-rutas.md, quickstart.md

**Tests**: Se incluyen tasks de test SÓLO para la lógica con impacto de dinero/stock (unicidad de SKU,
ajuste de stock foto-vs-histórico, "servicio no controla stock", importes/IVA no negativos, regla de
no-eliminación), según Principio IV de la constitución. El CRUD trivial de campos no económicos no
lleva test obligatorio.

**Diseño obligatorio** (CLAUDE.md): DataTable AJAX server-side · alta/edición/baja y ajuste de stock en
modales Bootstrap por AJAX (sin recargar) · notificaciones con toasts de Toastr.

**Reuso** (research D1): se reutiliza el patrón de `001-clientes` (controlador JSON, `yajra`,
DataTables, Toastr, Vite, `config/dz.php`) y la tabla `listas_precio` ya creada y sembrada.

**Organización**: por user story, en orden de prioridad, para entrega incremental.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: puede correr en paralelo (archivos distintos, sin dependencias pendientes)
- **[Story]**: US1..US8 (mapea a las historias de spec.md)

---

## Phase 1: Setup (Infraestructura compartida)

**Purpose**: andamiaje de assets/UI para el módulo (la lib server-side ya está de Clientes).

- [X] T001 [P] Crear el entry JS `resources/js/productos.js` y registrarlo en `vite.config.js` (input) para que Vite lo compile
- [X] T002 [P] Agregar la entrada de página `productos` en `config/dz.php` (pagelevel) cargando los assets del template: DataTables (css/js + responsive), Toastr (css/js) y bootstrap-select si se usa en el modal
- [X] T003 [P] Inicializar Toastr con la configuración global reutilizable (misma de Clientes) en `resources/js/productos.js`

**Checkpoint**: Vite compila; DataTables y Toastr disponibles en la página de productos.

---

## Phase 2: Foundational (Prerrequisitos bloqueantes)

**Purpose**: esquema de datos, modelos, servicio de stock, ruteo y shell de vista. NINGUNA historia
puede empezar hasta terminar esta fase.

**⚠️ CRITICAL**: bloquea todas las user stories.

### Base de datos y migraciones (data-model.md)

- [X] T004 [P] Migración `create_depositos_table` (id, nombre, activo default true, timestamps) en `database/migrations/`
- [X] T005 Migración `create_productos_table` (nombre, codigo nullable unique, tipo enum producto/servicio, proveedor_id nullable —FK sólo si existe `proveedores`, research D9—, descripcion, mostrar_en_ventas, precio_venta decimal(14,2), iva_venta_pct decimal(5,2), mostrar_en_compras, costo, iva_compra_pct, activo; índices nombre/codigo/activo/tipo/proveedor_id) en `database/migrations/`
- [X] T006 Migración `create_producto_variantes_table` (producto_id FK cascade, sku nullable unique, talle, color, nombre, precio_extra nullable, activo, timestamps) en `database/migrations/` (depende de T005)
- [X] T007 Migración `create_precios_producto_table` (producto_id FK cascade, lista_precio_id FK → listas_precio, precio decimal(14,2), unique(producto_id, lista_precio_id)) en `database/migrations/` (depende de T005)
- [X] T008 Migración `create_stocks_table` (producto_id FK cascade, variante_id FK nullable, deposito_id FK, cantidad decimal(14,3), unique(producto_id, variante_id, deposito_id)) en `database/migrations/` (depende de T004-T006)
- [X] T009 Migración `create_movimientos_stock_table` (producto_id FK, variante_id FK nullable, deposito_id FK, tipo enum entrada/salida/ajuste, cantidad decimal(14,3), descripcion nullable, origen_type/origen_id nullable morph, fecha, usuario_id FK nullable; índices producto_id/variante_id/deposito_id/fecha) en `database/migrations/` (depende de T004-T006)

### Modelos (data-model.md)

- [X] T010 [P] Modelo `App\Models\Deposito` (fillable, casts activo bool, scope activos) en `app/Models/Deposito.php`
- [X] T011 [P] Modelo `App\Models\PrecioProducto` (fillable, relación producto/listaPrecio) en `app/Models/PrecioProducto.php`
- [X] T012 [P] Modelo `App\Models\Stock` (fillable, casts cantidad decimal, relaciones producto/variante/deposito) en `app/Models/Stock.php`
- [X] T013 [P] Modelo `App\Models\MovimientoStock` (fillable, casts, morphTo origen, relaciones producto/variante/deposito/usuario) en `app/Models/MovimientoStock.php`
- [X] T014 Modelo `App\Models\ProductoVariante` (fillable, casts precio_extra/activo, relaciones producto/stocks/movimientos, `tieneOperaciones()`) en `app/Models/ProductoVariante.php` (depende de T012-T013)
- [X] T015 Modelo `App\Models\Producto` (fillable, casts booleanos/decimales; relaciones proveedor?/variantes/precios/stocks/movimientos; métodos `esServicio()`, `controlaStock()`, `tieneOperaciones()`, `stockTotal()`) en `app/Models/Producto.php` (depende de T014)

### Servicio de stock, ruteo y shell de vista

- [X] T016 [P] `App\Services\Stock\StockService` con `ajustar(Producto, ?ProductoVariante, Deposito, float $cantidadConSigno, ?string $descripcion, ?User)` que, en una transacción, upsertea `stocks` y registra un `movimiento_stock` tipo `ajuste`; binding en un service provider en `app/Services/Stock/`
- [X] T017 [P] Regla de validación `App\Rules\SkuUnico` (único global considerando `productos.codigo` ∪ `producto_variantes.sku`, ignora el propio registro y los NULL) en `app/Rules/SkuUnico.php`
- [X] T018 [P] Seeder `DepositoSeeder` (depósito "Principal" activo) registrado en `database/seeders/DatabaseSeeder.php`
- [X] T019 `ProductoController` (resource parcial + data/stats/export/estado) y `StockController` (ajuste + movimientos) esqueleto con métodos vacíos en `app/Http/Controllers/`
- [X] T020 Registrar rutas de productos en `routes/web.php` según `contracts/productos-rutas.md` (index, data, stats, export, store, show, update, destroy, estado, stock.ajuste, movimientos)
- [X] T021 [P] Actualizar el submenú "Base de Datos → Productos" del sidebar (`resources/views/elements/sidebar.blade.php`) para apuntar a `productos.index`
- [X] T022 Vista shell `resources/views/productos/index.blade.php` extendiendo `layouts.default`: contenedor de la DataTable + botón "Nuevo Producto" + includes de los modales (aún vacíos) + `@section('local-js')` cargando `productos.js`

**Checkpoint**: `/productos` carga sin errores, migraciones + seed corren, ruteo resuelto.

---

## Phase 3: User Story 1 — Alta de producto/servicio básico (Priority: P1) 🎯 MVP

**Goal**: dar de alta y editar un producto/servicio (mínimo: nombre + precio de venta) desde un modal
AJAX, con toast de resultado, sin recargar. El servicio no controla stock.

**Independent Test**: crear un producto con nombre+precio desde el modal → aparece en la tabla; crear
un servicio → se guarda sin control de stock; guardar sin nombre → error en el modal.

### Tests for User Story 1

- [X] T023 [P] [US1] Feature test: crear producto con nombre+precio responde 200 JSON y persiste; sin nombre responde 422 con error en `nombre`; crear tipo servicio queda como servicio, en `tests/Feature/ProductoAltaTest.php`

### Implementation for User Story 1

- [X] T024 [US1] `StoreProductoRequest` (nombre required; tipo in producto/servicio; descripcion/proveedor_id opcionales) en `app/Http/Requests/StoreProductoRequest.php`
- [X] T025 [US1] `UpdateProductoRequest` (reglas de update) en `app/Http/Requests/UpdateProductoRequest.php`
- [X] T026 [US1] Implementar `ProductoController@store` (valida, crea, responde JSON `{ok, mensaje, producto}` / 422) en `app/Http/Controllers/ProductoController.php`
- [X] T027 [US1] Implementar `ProductoController@show` (JSON del producto para precargar edición) y `@update` (JSON) en `app/Http/Controllers/ProductoController.php`
- [X] T028 [US1] Modal de alta/edición `resources/views/productos/_modal_form.blade.php` con Datos básicos (nombre, código, tipo producto/servicio, proveedor, descripción) y estructura para las secciones de US2/US4/US5
- [X] T029 [US1] En `resources/js/productos.js`: abrir modal (nuevo/editar), submit AJAX de store/update, mostrar errores 422 en el form, cerrar modal + recargar DataTable + toast de éxito; ocultar sección stock cuando tipo=servicio

**Checkpoint**: alta y edición básicas funcionando por modal AJAX con toasts, sin recargar.

---

## Phase 4: User Story 2 — Precios y datos de compra/venta (Priority: P1)

**Goal**: cargar precio de venta + IVA venta, costo + IVA compra, y flags mostrar en ventas/compras;
rechazar importes/IVA negativos.

**Independent Test**: cargar económicos y flags → persisten; precio/IVA negativo → rechazado.

### Tests for User Story 2

- [X] T030 [P] [US2] Feature test: importes/IVA negativos responden 422; valores válidos persisten con precisión decimal; flags mostrar_en_ventas/compras se guardan, en `tests/Feature/ProductoAltaTest.php` (o `ProductoEconomicoTest.php`)

### Implementation for User Story 2

- [X] T031 [US2] Agregar reglas económicas a Store/UpdateProductoRequest: `precio_venta`/`costo` numeric min:0; `iva_venta_pct`/`iva_compra_pct` numeric min:0; `mostrar_en_ventas`/`mostrar_en_compras` boolean (FR-006/FR-007/FR-008)
- [X] T032 [US2] Persistir y precargar económicos + flags en store/update/show del controlador en `app/Http/Controllers/ProductoController.php`
- [X] T033 [US2] Sección "Económicos" en `_modal_form.blade.php` (precio de venta, IVA venta, costo, IVA compra, checks mostrar en ventas/compras)

**Checkpoint**: producto con datos económicos válidos; nunca importes/IVA negativos.

---

## Phase 5: User Story 3 — Código/SKU único (Priority: P1)

**Goal**: SKU opcional a nivel producto, único globalmente (producto ∪ variante).

**Independent Test**: duplicar SKU → rechazado; varios sin SKU → permitido; editar sin cambiar SKU →
no falla contra sí mismo.

### Tests for User Story 3

- [X] T034 [P] [US3] Unit test `SkuUnicoTest` (acepta SKU libre, rechaza duplicado contra producto y contra variante, ignora NULL y el propio) en `tests/Unit/SkuUnicoTest.php`
- [X] T035 [P] [US3] Feature test: crear dos productos con mismo `codigo` → 422; varios sin código → OK; update sin cambiar código → OK, en `tests/Feature/ProductoSkuTest.php`

### Implementation for User Story 3

- [X] T036 [US3] Aplicar la regla `SkuUnico` a `codigo` en Store/UpdateProductoRequest (ignorando el propio registro en update) en `app/Http/Requests/`
- [X] T037 [US3] Campo "Código/SKU" en la sección Datos básicos de `_modal_form.blade.php` + mostrar error de unicidad devuelto por el back

**Checkpoint**: SKU de producto único global garantizado.

---

## Phase 6: User Story 4 — Variantes con SKU propio (Priority: P2)

**Goal**: 0..N variantes por producto (talle/color/etiqueta), cada una con SKU único global.

**Independent Test**: crear producto con dos variantes con SKU distintos → persisten; repetir SKU de
variante → rechazado; quitar variante sin stock → se elimina.

### Tests for User Story 4

- [X] T038 [P] [US4] Feature test: persistencia/reemplazo de variantes; SKU de variante duplicado (contra producto y contra otra variante) → 422; quitar variante sin operaciones la elimina, en `tests/Feature/ProductoVarianteTest.php`

### Implementation for User Story 4

- [X] T039 [US4] Validación de `variantes.*` en Store/UpdateProductoRequest: `sku` con regla `SkuUnico` (+ unicidad dentro del propio payload), talle/color/nombre/precio_extra opcionales (FR-011)
- [X] T040 [US4] `ProductoController` store/update sincronizan variantes (crear/actualizar/eliminar, respetando `tieneOperaciones()` de la variante al borrar) y `show` las devuelve, en `app/Http/Controllers/ProductoController.php`
- [X] T041 [US4] Sección "Variantes" (filas dinámicas talle/color/nombre/SKU/precio_extra) en `_modal_form.blade.php` + alta/quita dinámica en `resources/js/productos.js`

**Checkpoint**: variantes con SKU único, persistidas y editables.

---

## Phase 7: User Story 5 — Precios por lista de precio (Priority: P2)

**Goal**: precios diferenciados por lista (reutiliza `listas_precio`), único por (producto, lista).

**Independent Test**: cargar precio Mayorista y Minorista → persisten; editar uno → no duplica; sin
precio de lista → usa precio base.

### Tests for User Story 5

- [X] T042 [P] [US5] Feature test: persistencia de precios por lista, unicidad por (producto, lista), edición sin duplicar, en `tests/Feature/ProductoPreciosListaTest.php`

### Implementation for User Story 5

- [X] T043 [US5] Validación de `precios.*` en Store/UpdateProductoRequest: `lista_precio_id` exists + único por lista en el payload; `precio` numeric min:0 (FR-014)
- [X] T044 [US5] `ProductoController` store/update sincronizan `precios_producto` (upsert por lista) y `show` los devuelve, en `app/Http/Controllers/ProductoController.php`
- [X] T045 [US5] Sección "Precios por lista" en `_modal_form.blade.php` (una fila por lista de precio existente) + manejo en `resources/js/productos.js`

**Checkpoint**: precios por lista guardados y editables.

---

## Phase 8: User Story 6 — Depósitos, stock y ajustes manuales (Priority: P2)

**Goal**: stock por producto/variante+depósito, ajustes manuales (aumento/disminución) con histórico;
servicios sin stock.

**Independent Test**: aumento de 10 → stock 10 + movimiento; disminución de 3 → stock 7 + movimiento;
foto = histórico; servicio no admite ajuste.

### Tests for User Story 6

- [X] T046 [P] [US6] Feature test `StockAjusteTest`: aumento/disminución actualizan `stocks` y registran `movimientos_stock` coherentes (SC-003); ajuste sobre servicio → 422 (SC-007); ajuste con variante requerida cuando el producto tiene variantes, en `tests/Feature/StockAjusteTest.php`

### Implementation for User Story 6

- [X] T047 [US6] `AjusteStockRequest` (producto debe ser tipo producto; `deposito_id` exists; `operacion` in aumento/disminucion; `cantidad` numeric >0; `variante_id` requerido/exists si el producto tiene variantes) en `app/Http/Requests/AjusteStockRequest.php`
- [X] T048 [US6] Implementar `StockController@ajuste` usando `StockService` (JSON `{ok, mensaje, stock_actual}`) y `@movimientos` (histórico filtrable por producto) en `app/Http/Controllers/StockController.php`
- [X] T049 [US6] Modal de stock `resources/views/productos/_modal_stock.blade.php` (depósito, variante si aplica, aumento/disminución, cantidad, descripción) + tabla del histórico de movimientos
- [X] T050 [US6] En `resources/js/productos.js`: abrir modal de stock por fila (oculto para servicios), submit AJAX del ajuste, refrescar stock_actual + histórico + fila de la tabla + toast

**Checkpoint**: ajuste de stock manual con histórico coherente; servicios sin stock.

---

## Phase 9: User Story 7 — Listar, buscar y filtrar (Priority: P2)

**Goal**: DataTable server-side por AJAX con búsqueda (nombre/SKU) y filtros (estado, tipo, proveedor).

**Independent Test**: buscar por nombre y por SKU; filtrar por activos/inactivos y por tipo; verificar
resultados y performance en catálogo grande.

### Tests for User Story 7

- [X] T051 [P] [US7] Feature test `ProductoListadoTest`: `productos.data` devuelve formato DataTables, respeta búsqueda por nombre/SKU y filtros estado/tipo, en `tests/Feature/ProductoListadoTest.php`

### Implementation for User Story 7

- [X] T052 [US7] Implementar `ProductoController@data` (yajra: columnas nombre/codigo/tipo/precio_venta/stock_total/estado/acciones; búsqueda global nombre+codigo; filtros estado/tipo/proveedor_id) en `app/Http/Controllers/ProductoController.php`
- [X] T053 [US7] Parcial `resources/views/productos/_row_actions.blade.php` (botones editar / stock / inactivar-activar / eliminar por fila; stock oculto para servicios)
- [X] T054 [US7] En `resources/js/productos.js`: inicializar la DataTable responsive server-side apuntando a `productos.data`, con controles de filtro (estado, tipo, proveedor) que recarguen la tabla
- [X] T055 [US7] Controles de filtro (estado, tipo, proveedor) y buscador en `resources/views/productos/index.blade.php`

**Checkpoint**: listado completo, buscable y filtrable, cargado por AJAX.

---

## Phase 10: User Story 8 — Baja lógica y eliminación (Priority: P3)

**Goal**: inactivar/reactivar (baja lógica) y eliminar físicamente sólo si no hay operaciones.

**Independent Test**: inactivar (sale de selectores, sigue en filtro inactivos, reactivable); eliminar
sin operaciones (se borra); eliminar con movimientos de stock (rechazado 409).

### Tests for User Story 8

- [X] T056 [P] [US8] Feature test `ProductoBajaTest`: `estado` alterna activo; `destroy` elimina sin operaciones y rechaza (409) con movimientos de stock, en `tests/Feature/ProductoBajaTest.php`

### Implementation for User Story 8

- [X] T057 [US8] Implementar `ProductoController@estado` (toggle activo, JSON) y `@destroy` (chequea `tieneOperaciones()` → 409 o borra → 200) en `app/Http/Controllers/ProductoController.php`
- [X] T058 [US8] En `resources/js/productos.js`: acciones AJAX de inactivar/activar y eliminar (con confirmación en modal), actualizar fila/tabla + toast según respuesta

**Checkpoint**: baja lógica y eliminación segura funcionando, con trazabilidad preservada.

---

## Phase 11: Polish & Cross-Cutting

- [X] T059 [P] Endpoint `ProductoController@stats` (total, activos, servicios, stock, nuevos del mes) + cards informativas en `resources/views/productos/index.blade.php`
- [X] T060 [P] Endpoint `ProductoController@export` (CSV/Excel del listado filtrado, BOM UTF-8, streaming) + botón en la vista índice
- [X] T061 [P] Factory `ProductoFactory` + `ProductosDemoSeeder` (~1.000 productos) para validar SC-005, en `database/factories/` y `database/seeders/`
- [X] T062 [P] Revisar responsividad de la DataTable y de ambos modales en pantallas chicas (reusar `public/css/contagram-custom.css`)
- [X] T063 Ejecutar la validación de `specs/002-productos/quickstart.md` de punta a punta (incluye la prueba de performance SC-005 con el demo seeder)
- [X] T064 Correr `php artisan test --filter=Producto` + `--filter=Stock` + `--filter=SkuUnico` y dejar toda la suite en verde
- [X] T065 Revisar `docs/modelo_datos.md` §2 / `docs/documentacion_principal_crm.md` §5.2: si la implementación reveló algún campo/regla nuevo, actualizarlos en el mismo cambio (Principio I). Opcional: fijar precisión decimal(14,2)/(14,3) en el doc de modelo (ajuste PATCH)

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Fase 1)**: sin dependencias.
- **Foundational (Fase 2)**: depende de Setup — BLOQUEA todas las historias.
- **User Stories (Fases 3-10)**: dependen de Foundational.
  - US1, US2, US3 (P1) primero (MVP): US2 y US3 extienden el modal/requests de US1.
  - US4, US5, US6, US7 (P2): US4/US5 extienden el modal; US6 usa `StockService`; US7 el listado.
  - US8 (P3): baja/eliminación (usa acciones de fila de US7 pero lógica independiente).
- **Polish (Fase 11)**: al final.

### Dependencias entre historias

- US1: base — modal + store/update/show. Sin dependencias de otras historias.
- US2, US3: extienden requests/modal de US1. Testeables aparte.
- US4: variantes (extiende modal + sincronización). Usa la regla `SkuUnico` de US3.
- US5: precios por lista (extiende modal). Independiente de US4.
- US6: stock/ajustes (usa `StockService` y `depositos` de Foundational). Independiente de US4/US5.
- US7: listado AJAX. Necesita productos cargados (US1) y muestra `stock_total` (US6 lo enriquece).
- US8: baja/eliminación. Usa acciones de fila de US7; lógica independiente (`tieneOperaciones()`).

### Paralelizables

- Setup: T001, T002, T003 en paralelo.
- Foundational: migración T004 en paralelo; modelos T010-T013 en paralelo; T016, T017, T018, T021 en
  paralelo con otras.
- Tests marcados [P] dentro de cada historia corren en paralelo.

---

## Implementation Strategy

### MVP (recomendado)

1. Fase 1 (Setup) → Fase 2 (Foundational) → Fase 3 (US1) → Fase 4 (US2) → Fase 5 (US3).
2. **STOP y validar**: se puede dar de alta/editar productos y servicios con datos económicos válidos y
   SKU único — el mínimo que desbloquea Ventas/Compras. Demo.

### Incremental

- + US4 (variantes) → + US5 (precios por lista) → + US6 (stock/ajustes) → + US7 (listado) → + US8
  (baja/eliminación). Cada historia agrega valor sin romper las anteriores.

---

## Notes

- [P] = archivos distintos, sin dependencias pendientes.
- Verificar que los tests de stock/SKU fallen antes de implementar la lógica (TDD en lo crítico).
- Commit por task o grupo lógico; parar en cada checkpoint para validar la historia.
- Respetar SIEMPRE: DataTable AJAX server-side, modales AJAX sin recargar, toasts de Toastr.
- Reusar el patrón de `001-clientes` y la tabla `listas_precio` ya existente (no recrearla).
