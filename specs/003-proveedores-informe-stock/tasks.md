---
description: "Task list — Proveedores + Informe de Stock"
---

# Tasks: Proveedores + Informe de Stock

**Input**: Design documents from `specs/003-proveedores-informe-stock/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/proveedores-informe-stock-rutas.md, quickstart.md

**Tests**: Se incluyen tasks de test para la lógica con impacto de dinero/stock (Principio IV de la
constitución): la regla "no eliminar proveedor con productos asociados" (FR-006), la validación de
CUIT del proveedor (FR-003, reuso de `CuitValido`), el cálculo del saldo corrido "Stock Saldo"
(FR-011 — el punto más delicado de todo el spec, research.md §2), el límite del filtro "Operación"
(FR-013) y los KPIs monetarios del Informe de Stock (FR-010). El CRUD trivial de campos no
económicos de Proveedor (espejo exacto de Cliente) sólo lleva un test de listado básico, sin repetir
exhaustivamente la cobertura ya validada en `001-clientes`.

**Diseño obligatorio** (CLAUDE.md): DataTable AJAX server-side · alta/edición/baja de Proveedor en
modal Bootstrap por AJAX (sin recargar) · notificaciones con toasts de Toastr · Select2 en selects
dinámicos (Proveedor, Tipo de Producto, Usuario) · **Informe de Stock es una pantalla propia, no un
modal** (principio de fidelidad estructural a Contagram).

**Reuso**: se clona el patrón de `001-clientes` (`ClienteController`, `ReglasCliente`,
`StoreClienteRequest`, `clientes.js`, `_modal_form.blade.php`) para Proveedor, y el patrón de
`addSelect` de subconsulta ya usado en `ProductoController::queryFiltrada()` (columnas dinámicas de
lista de precio) para el `SUM() OVER (...)` del Informe de Stock.

**Organización**: por user story, en orden de prioridad, para entrega incremental.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: puede correr en paralelo (archivos distintos, sin dependencias pendientes)
- **[Story]**: US1..US3 (mapea a las historias de spec.md)

---

## Phase 1: Setup (Infraestructura compartida)

**Purpose**: andamiaje de assets/UI para los dos módulos nuevos (la lib server-side ya está de
Clientes/Productos).

- [X] T001 [P] Agregar los entries JS `resources/js/proveedores.js` y `resources/js/informe-stock.js` a `vite.config.js` (array `input`) para que Vite los compile
- [X] T002 [P] Agregar las entradas de página `proveedores` e `informes-stock` en `config/dz.php` (pagelevel), reutilizando los mismos vendors ya cargados para `clientes`/`productos` (DataTables + responsive, Toastr, Select2) y agregando `bootstrap-daterangepicker` (ya usado en el pagelevel `home`) para el selector de rango de fechas del Informe de Stock
- [X] T003 [P] Inicializar Toastr con la configuración global reutilizable (misma de Clientes/Productos) en `resources/js/proveedores.js` y en `resources/js/informe-stock.js`

**Checkpoint**: Vite compila los 2 entries nuevos; DataTables/Toastr/Select2/daterangepicker disponibles en ambas páginas.

---

## Phase 2: Foundational (Prerrequisitos bloqueantes)

**Purpose**: esquema de datos, modelos, ruteo y shells de vista. NINGUNA historia puede empezar
hasta terminar esta fase.

**⚠️ CRITICAL**: bloquea las 3 user stories.

### Base de datos y migraciones (data-model.md, research.md §1)

- [X] T004 [P] Migración `create_proveedores_table` (mismos campos que `clientes` salvo sin `apodo_ml` ni `lista_precio_id`; agrega `categoria_id` FK → `categorias`, `nota_interna` en vez de `nota_cliente`; resto idéntico: nombre obligatorio, bloque de facturación, `campos_personalizados` json, `saldo_inicial`/`saldo_inicial_fecha`, `activo`; índices `unique(cuit)`, `index(nombre)`, `index(activo)`) en `database/migrations/`
- [X] T005 Migración `create_proveedor_contactos_table` (proveedor_id FK cascade, nombre, apellido nullable, telefono nullable, telefono_celular nullable, email nullable, enviar_mails boolean default false) en `database/migrations/` (depende de T004)
- [X] T006 Migración `add_foreign_key_proveedor_id_to_productos_table` que agrega `$table->foreign('proveedor_id')->references('id')->on('proveedores')->nullOnDelete()` sobre la columna `proveedor_id` ya existente en `productos` (research.md §1 — no crear la columna de nuevo, sólo la FK) en `database/migrations/` (depende de T004)

### Modelos (data-model.md)

- [X] T007 [P] Modelo `App\Models\Proveedor` (fillable, casts booleanos/decimales, relaciones `contactos()` hasMany, `condicionIva()` belongsTo, `categoria()` belongsTo, `productos()` hasMany; método `tieneOperaciones(): bool` → existe algún `Producto` con `proveedor_id` = este proveedor) en `app/Models/Proveedor.php`
- [X] T008 [P] Modelo `App\Models\ProveedorContacto` (fillable, relación `proveedor()` belongsTo) en `app/Models/ProveedorContacto.php`
- [X] T009 Reincorporar en `App\Models\Producto`: `proveedor_id` de vuelta en `$fillable`, y la relación `proveedor(): BelongsTo` (`app/Models/Producto.php`) (depende de T007)

### Reglas de validación, ruteo y shells de vista

- [X] T010 [P] Trait `App\Http\Requests\Concerns\ReglasProveedor` (clon de `ReglasCliente`: `reglasProveedor(?int $proveedorId = null)` con `nombre` requerido, CUIT vía `CuitValido` sólo si `tipo_documento` es CUIT/CUIL, `categoria_id` exists en `categorias`, `contactos.*`/`campos_personalizados.*` mismo shape que Cliente; SIN `apodo_ml` ni `lista_precio_id`) en `app/Http/Requests/Concerns/ReglasProveedor.php`
- [X] T011 [P] `StoreProveedorRequest` y `UpdateProveedorRequest` (usan `ReglasProveedor`, mismo patrón `failedValidation`/JSON 422 que `StoreClienteRequest`) en `app/Http/Requests/`
- [X] T012 `ProveedorController` (resource parcial + `data`/`stats`/`export`/`estado`, espejo exacto de `ClienteController`) y `Informes\InformeStockController` (esqueleto con `index`/`data`/`stats` vacíos) en `app/Http/Controllers/`
- [X] T013 Registrar rutas de Proveedores e Informe de Stock en `routes/web.php` según `contracts/proveedores-informe-stock-rutas.md` (proveedores.index/data/stats/export/store/show/update/destroy/estado; informes.stock.index/data/stats), y actualizar `productos.movimientos` para que el link de "Movimientos" apunte a `informes.stock.index` con `?producto_id=` (depende de T012)
- [X] T014 [P] Reincorporar en el submenú "Base de Datos" del sidebar (`resources/views/elements/sidebar.blade.php`) el ítem "Proveedores" (apuntando a `proveedores.index`), y agregar un ítem "Informes → Stock" apuntando a `informes.stock.index`
- [X] T015 [P] Vista shell `resources/views/proveedores/index.blade.php` extendiendo `layouts.default`: contenedor de la DataTable + botón "Nuevo Proveedor" + include de `_modal_form` (vacío) + `@section('local-js')` cargando `proveedores.js`
- [X] T016 [P] Vista shell `resources/views/informes/stock/index.blade.php` extendiendo `layouts.default`: fila de KPIs + panel de Filtros colapsable (vacío) + contenedor de la DataTable + `@section('local-js')` cargando `informe-stock.js`

**Checkpoint**: `/proveedores` e `/informes/stock` cargan sin errores, migraciones corren, ruteo resuelto, `productos.proveedor_id` tiene FK real.

---

## Phase 3: User Story 1 - Alta y gestión de Proveedores (Priority: P1) 🎯 MVP

**Goal**: CRUD completo de Proveedores (alta, edición, inactivar/reactivar, eliminar), espejo fiel de
Clientes con las diferencias documentadas.

**Independent Test**: Crear, editar, inactivar y eliminar un proveedor desde `/proveedores`, sin
depender de Productos ni del Informe de Stock (spec.md, User Story 1).

### Tests for User Story 1 ⚠️

- [X] T017 [P] [US1] Feature test: alta de proveedor con sólo el nombre requerido persiste correctamente, y un CUIT matemáticamente inválido (con `tipo_documento` CUIT/CUIL) es rechazado mientras que un CUIT vacío se acepta (FR-001, FR-003, reuso de `CuitValido`) en `tests/Feature/ProveedorAltaTest.php`
- [X] T018 [P] [US1] Feature test: `proveedores.data` devuelve las 15 columnas esperadas (sin "Usuario de Mercado Libre") y la búsqueda global encuentra por cualquier campo (FR-004) en `tests/Feature/ProveedorListadoTest.php`
- [X] T019 [P] [US1] Feature test: no se puede eliminar físicamente un proveedor con al menos un producto asociado (`proveedor_id`) — espera HTTP 409 y mensaje "sólo puede inactivarse"; sí se puede eliminar uno sin productos asociados (FR-006) en `tests/Feature/ProveedorBajaTest.php`

### Implementation for User Story 1

- [X] T020 [P] [US1] `ProveedorController@index` (stats + vista) y `@stats` (total/activos/nuevos del mes) en `app/Http/Controllers/ProveedorController.php`
- [X] T021 [US1] `ProveedorController@data` (DataTable server-side: 15 columnas — Id, Proveedor, Nombre, Apellido, Mail, Teléfono, Teléfono Celular, Domicilio, Localidad, Provincia, DNI, CUIT, Condición de IVA, Nota, Página Web — split DNI/CUIT según `tipo_documento` igual que Cliente, búsqueda global) en `app/Http/Controllers/ProveedorController.php` (depende de T020, cubre T018)
- [X] T022 [US1] `ProveedorController@export` (CSV streaming, BOM UTF-8, mismas columnas que `data`) en `app/Http/Controllers/ProveedorController.php` (depende de T021)
- [X] T023 [US1] `ProveedorController@store`/`@update` (crea/actualiza proveedor + sincroniza `contactos` + `campos_personalizados`, espejo de `ClienteController@store`/`@update`) en `app/Http/Controllers/ProveedorController.php` (depende de T011, cubre T017)
- [X] T024 [US1] `ProveedorController@show` (datos para precargar el modal de edición, con `contactos`) en `app/Http/Controllers/ProveedorController.php`
- [X] T025 [US1] `ProveedorController@destroy` (rechaza con 409 si `tieneOperaciones()`, si no elimina físicamente) y `@estado` (toggle activo/inactivo) en `app/Http/Controllers/ProveedorController.php` (depende de T007, cubre T019)
- [X] T026 [US1] `resources/views/proveedores/_modal_form.blade.php` (espejo de `clientes/_modal_form.blade.php`: datos generales + contactos dinámicos + campos personalizados + saldo inicial + datos de facturación; bloque "Compras" en vez de "Ventas" con "Categoría Compras" y SIN selector de Lista de Precios; "Nota Interna" en vez de "Nota para el Cliente"; SIN "Apodo ML")
- [X] T027 [US1] `resources/views/proveedores/_row_actions.blade.php` (Ver, Editar, Inactivar/Reactivar, Eliminar — espejo de `clientes/_row_actions.blade.php`, sin "Cta Cte")
- [X] T028 [US1] `resources/views/proveedores/index.blade.php` (tabla completa con las 15 columnas, buscador, selector de columnas, botón Exportar, cards de stats) — completa el shell de T015
- [X] T029 [US1] `resources/js/proveedores.js` (DataTable + submit AJAX del modal + contactos dinámicos + campos personalizados + saldo inicial + estado + eliminar + exportar + buscador, espejo de `clientes.js` con los campos renombrados)

**Checkpoint**: Proveedores funciona de punta a punta, independiente de Productos y del Informe de Stock.

---

## Phase 4: User Story 2 - Asociar un Proveedor a un Producto (Priority: P2)

**Goal**: Reincorporar el selector "Proveedor" en el modal de Producto, la columna y el filtro por
Proveedor en el listado de Productos.

**Independent Test**: Con al menos un proveedor activo, asignar un proveedor a un producto desde el
modal, y verificar que la columna y el filtro de Proveedor del listado de Productos lo reflejan
(spec.md, User Story 2).

### Implementation for User Story 2

- [X] T030 [US2] En `ReglasProducto` (`app/Http/Requests/Concerns/ReglasProducto.php`), simplificar la regla de `proveedor_id` ahora que `proveedores` siempre existe (quitar el `Schema::hasTable('proveedores')` condicional, dejar `['nullable', 'integer', 'exists:proveedores,id']` directo) (depende de T004, T009)
- [X] T031 [US2] En `ProductoController@index` (`app/Http/Controllers/ProductoController.php`), volver a pasar `$proveedores = Proveedor::where('activo', true)->orderBy('nombre')->get(['id','nombre'])` a la vista (depende de T030)
- [X] T032 [US2] En `ProductoController::queryFiltrada()`, reincorporar `->with(['proveedor:id,nombre'])` y el filtro `if ($request->filled('proveedor_id')) { $query->where('proveedor_id', ...); }` (depende de T031)
- [X] T033 [US2] En `ProductoController@data`, reincorporar `->addColumn('proveedor', fn (Producto $p) => optional($p->proveedor)->nombre)` (depende de T032)
- [X] T034 [US2] En `ProductoController@export`, reincorporar la columna "Proveedor" en encabezados y filas del CSV (depende de T032)
- [X] T035 [P] [US2] Feature test: crear/editar un producto con `proveedor_id`, verificar que persiste y que el filtro `proveedor_id` de `productos.data` lo devuelve en `tests/Feature/ProductoProveedorTest.php` (depende de T033)
- [X] T036 [US2] En `resources/views/productos/_modal_form.blade.php`, reincorporar el selector "Proveedor" (select con buscador, `@foreach ($proveedores ?? [] as $proveedor)`) en la misma posición que tenía antes de la limpieza
- [X] T037 [US2] En `resources/views/productos/index.blade.php`, reincorporar el filtro "Proveedor" en el panel de Filtros y la columna "Proveedor" en el `<thead>` de la tabla
- [X] T038 [US2] En `resources/js/productos.js`, reincorporar: `initSelect2` del selector Proveedor del modal (con `dropdownParent`), el filtro `d.proveedor_id = $('#filtro-proveedor').val()`, la columna `proveedor` de la DataTable, y el reset del selector en `resetForm()`/`refreshSelect2` al editar

**Checkpoint**: Productos vuelve a tener Proveedor en modal, columna y filtro — funciona independiente del Informe de Stock.

---

## Phase 5: User Story 3 - Informe de Stock (Priority: P3)

**Goal**: Pantalla propia de Informe de Stock (filtros, rango de fechas, 3 KPIs, tabla con "Stock
Saldo"), reemplazando el modal simple de histórico, accesible desde "Movimientos" de Productos.

**Independent Test**: Desde el menú de fila de un producto, "Movimientos" navega al Informe de
Stock pre-filtrado por ese producto, con saldo corrido correcto (spec.md, User Story 3).

### Tests for User Story 3 ⚠️

- [X] T039 [P] [US3] Feature test: el "Stock Saldo" calculado por `InformeStockController@data` da el mismo valor para una fila, con y sin filtros de fecha/tipo/proveedor aplicados que excluyan movimientos anteriores de esa fila (verifica que el filtro no altera la ventana de cálculo — research.md §2) en `tests/Feature/InformeStockTest.php`
- [X] T040 [P] [US3] Feature test: los KPIs de `InformeStockController@stats` (Unidades en Stock, Costo Total, Valor Venta Total) coinciden, sin filtros, con la suma exacta de stock/costo/precio de los productos activos (misma fórmula que `ProductoController::estadisticas()`) en `tests/Feature/InformeStockTest.php`
- [X] T041 [P] [US3] Feature test: el filtro "Operación" de `informes.stock.data`/la vista sólo expone las opciones `ajuste` y `transferencia` (FR-013) — no aparecen `entrada`/`salida` aunque existan en el enum de `movimientos_stock`, para dejar constancia explícita del alcance hasta que existan Compras/Ventas en `tests/Feature/InformeStockTest.php`

### Implementation for User Story 3

- [X] T042 [US3] `InformeStockController@data`: query base sobre `movimientos_stock` con `addSelect(['stock_saldo' => ...])` usando `SUM(cantidad) OVER (PARTITION BY producto_id, variante_id, deposito_id ORDER BY fecha, id)` vía expresión raw, `with(['producto', 'deposito', 'usuario'])`, filtros externos (`usuario_id`, `operacion` limitado a `ajuste`/`transferencia`, `proveedor_id` vía `whereHas('producto', fn ($q) => $q->where('proveedor_id', ...))`, `tipo_producto_id`, `producto_id`, `estado`, `fecha_desde`/`fecha_hasta`) aplicados como capa externa sobre la proyección ya calculada (research.md §2) en `app/Http/Controllers/Informes/InformeStockController.php` (depende de T032; cubre T039, T041)
- [X] T043 [US3] `InformeStockController@stats` (3 KPIs recalculados sobre los productos que matchean los filtros vigentes, misma fórmula que `ProductoController::estadisticas()`) en `app/Http/Controllers/Informes/InformeStockController.php` (depende de T042; cubre T040)
- [X] T044 [US3] `InformeStockController@index` (vista con filtros pre-cargados desde querystring, especialmente `producto_id`) en `app/Http/Controllers/Informes/InformeStockController.php`
- [X] T045 [US3] En `resources/views/productos/_row_actions.blade.php`, cambiar la acción "Movimientos" de un botón AJAX (`.js-producto-movimientos`) a un link `<a href="{{ route('informes.stock.index', ['producto_id' => $producto->id]) }}">` (depende de T013)
- [X] T046 [US3] Eliminar de `resources/js/productos.js` el manejo de `.js-producto-movimientos` (ya no abre el modal de stock para ver histórico) y de `resources/views/productos/_modal_stock.blade.php` la tabla de histórico embebida (el modal queda sólo para el form de ajuste, sin la tabla de movimientos) — mantener `.js-producto-stock` (Aumentar/Disminuir) intacto (FR-014)
- [X] T047 [P] [US3] Regresión: correr `php artisan test --filter=StockAjuste` (suite ya existente de `002-productos`) tras T046 y confirmar que el endpoint `productos.stock.ajuste` sigue en verde — el cambio de T046 es sólo de UI/JS, no debe afectar la lógica de `StockService` (FR-014, Principio IV)
- [X] T048 [US3] `resources/views/informes/stock/index.blade.php` completo: panel de Filtros (Usuario, Operación, Proveedor con Select2, Tipo de Producto con Select2, Productos con Select2 vía `productos.opciones`, Estado), selector de rango de fechas (`bootstrap-daterangepicker`), fila de 3 KPIs, tabla con columnas Fecha/Operación/Detalle/Producto/Cantidad/Stock Saldo/Usuario — completa el shell de T016
- [X] T049 [US3] `resources/js/informe-stock.js`: DataTable server-side con los filtros de T048, lectura de `producto_id` desde querystring al cargar la página para pre-seleccionar el filtro "Productos", refresco de KPIs al aplicar filtros, Select2 en los selects dinámicos

**Checkpoint**: Las 3 user stories funcionan de punta a punta e independientemente entre sí.

---

## Phase 6: Polish & Cross-Cutting Concerns

**Purpose**: consistencia de documentación y validación final.

- [X] T050 [P] Mover "Base de Datos → Proveedores" de "Módulos pendientes de re-relevamiento" (`docs/documentacion_principal_crm.md` §5) a una sección activa `## 2.3 Proveedores`, con el mismo nivel de detalle que §2.1 Clientes
- [X] T051 [P] Documentar el Informe de Stock como sección activa nueva en `docs/documentacion_principal_crm.md` (reemplaza la mención de §4.2 "pendiente"), y mover `proveedores`/`proveedor_contactos` de "Tablas descartadas" a la sección activa de `docs/modelo_datos.md` §2, agregando la nota sobre `stock_saldo` calculado (Principio I de la constitución)
- [X] T052 Revisar responsividad de la DataTable y de los modales/pantalla nuevos en pantallas chicas (reusar `public/css/contagram-custom.css`)
- [X] T053 Ejecutar la validación de `specs/003-proveedores-informe-stock/quickstart.md` de punta a punta (los 15 escenarios)
- [X] T054 Correr `php artisan test --filter=Proveedor` + `--filter=ProductoProveedor` + `--filter=InformeStock` + `--filter=StockAjuste` y dejar toda la suite en verde
- [X] T055 `npm run build` final y `php artisan route:list` para confirmar que todas las rutas de `contracts/proveedores-informe-stock-rutas.md` están registradas sin errores

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Fase 1)**: sin dependencias.
- **Foundational (Fase 2)**: depende de Setup — BLOQUEA las 3 historias.
- **User Stories (Fases 3-5)**: dependen de Foundational.
  - US1 (P1) primero — MVP, sin dependencia de US2/US3.
  - US2 (P2) depende de que exista el modelo `Proveedor` (Foundational, T007) y de la simplificación
    de `ReglasProducto` (T030, ahora dentro de la propia US2) — no del CRUD completo de US1.
  - US3 (P3) depende de US2 para el filtro "Proveedor" del informe (si no hay productos con
    proveedor asociado, ese filtro simplemente no tiene datos, no rompe nada — spec.md Independent
    Test de US3).
- **Polish (Fase 6)**: al final.

### Dependencias entre historias

- US1: base — CRUD completo de Proveedor. Sin dependencias de otras historias.
- US2: extiende `ProductoController`/`Producto`/vistas de Productos ya existentes. Necesita el
  modelo `Proveedor` de Foundational (T007), no el CRUD completo de US1. Incluye la simplificación
  de `ReglasProducto` (T030) porque sólo tiene sentido una vez que `proveedores` existe siempre.
- US3: pantalla nueva sobre `movimientos_stock` ya existente. El filtro "Proveedor" usa la relación
  reincorporada en US2 (T032); el resto del informe (KPIs, saldo corrido, otros filtros) no depende
  de US2.

### Paralelizables

- Setup: T001, T002, T003 en paralelo.
- Foundational: T004/T007/T008/T010/T011/T014/T015/T016 marcados [P] en paralelo con el resto.
- Tests marcados [P] dentro de cada historia corren en paralelo.
- T050/T051 (documentación) en paralelo entre sí.

---

## Parallel Example: User Story 1

```bash
# Tests de User Story 1 en paralelo:
Task: "Feature test alta de proveedor + CUIT en tests/Feature/ProveedorAltaTest.php"
Task: "Feature test listado/columnas en tests/Feature/ProveedorListadoTest.php"
Task: "Feature test no-eliminar-con-productos en tests/Feature/ProveedorBajaTest.php"

# Modelos y request de Proveedor en paralelo (Foundational):
Task: "Modelo Proveedor en app/Models/Proveedor.php"
Task: "Modelo ProveedorContacto en app/Models/ProveedorContacto.php"
Task: "Trait ReglasProveedor en app/Http/Requests/Concerns/ReglasProveedor.php"
```

---

## Implementation Strategy

### MVP (recomendado)

1. Fase 1 (Setup) → Fase 2 (Foundational) → Fase 3 (US1).
2. **STOP y validar**: se puede dar de alta/editar/inactivar/eliminar proveedores igual que
   clientes. Demo.

### Incremental

- + US2 (selector Proveedor en Productos) → + US3 (Informe de Stock). Cada historia agrega valor
  sin romper las anteriores; US3 es la más grande y la última, consistente con su prioridad P3 en
  spec.md.

---

## Notes

- [P] = archivos distintos, sin dependencias pendientes.
- Verificar que T017, T019, T035, T039, T040 y T041 fallen antes de implementar la lógica que
  prueban (TDD en lo crítico: dinero y stock, Principio IV).
- Commit por task o grupo lógico; parar en cada checkpoint para validar la historia.
- Respetar SIEMPRE: DataTable AJAX server-side, modales AJAX sin recargar (salvo el Informe de
  Stock, que es pantalla propia por diseño — spec.md), toasts de Toastr, Select2 en selects
  dinámicos.
- No crear una migración nueva para `productos.proveedor_id` (ya existe) — sólo la migración de FK
  (T006) y la reincorporación en el modelo (T009).
