# Tasks: Vendedores — activar/desactivar

**Input**: Design documents from `specs/101-vendedores-activo-inactivo/`
**Prerequisites**: plan.md, spec.md, data-model.md, research.md, contracts/vendedores-api.md

**Tests**: se incluye un test de Feature acotado (Principio IV: proporcional al riesgo, no
obligatorio para CRUD simple pero de bajo costo y alto valor por tener 6 puntos de consumo).

**Organization**: tareas agrupadas por historia de usuario (spec.md), en orden de prioridad P1→P3.

## Phase 1: Setup

- [X] T001 Crear migración `add_activo_to_vendedores_table` en `database/migrations/2026_09_08_060000_add_activo_to_vendedores_table.php` (columna `activo` boolean, `default(true)`, `after('nombre')`)
- [X] T002 Correr `php artisan migrate` y verificar que los vendedores existentes quedan con `activo = 1`

## Phase 2: Foundational (bloqueante para todas las historias)

- [X] T003 En `app/Models/Vendedor.php`: agregar `'activo'` a `$fillable`, cast `'activo' => 'boolean'`, y método `scopeActivos(Builder $query): Builder` (calco de `Deposito::scopeActivos`)
- [X] T004 En `app/Http/Controllers/VendedorController.php`: método `store()` — setear `activo` en `true` explícitamente al crear (ya es default, pero deja la intención explícita) e incluir `activo` en la respuesta JSON existente
- [X] T005 En `app/Http/Controllers/VendedorController.php`: método `update()` — incluir `activo` en la respuesta JSON del vendedor actualizado
- [X] T006 En `app/Http/Controllers/VendedorController.php`: agregar método `estado(Vendedor $vendedor)` que alterna `activo` y devuelve `{ok, activo, mensaje}` (calco de `DepositoController::estado`)
- [X] T007 En `routes/web.php`, dentro del grupo `admin` de Configuración & Ajustes: agregar `Route::get('vendedores/data', ...)->name('vendedores.data')` y `Route::patch('vendedores/{vendedor}/estado', ...)->name('vendedores.estado')` junto a las rutas `vendedores.*` existentes (store/update/destroy) — estos dos nombres de ruta (`vendedores.data`, `vendedores.estado`) son los que citan T016 y T024
- [X] T008 En `app/Http/Controllers/VendedorController.php`: agregar método `data()` que devuelve `Vendedor::orderBy('nombre')->get(['id','nombre','activo'])` como `{data: [...]}`

**Checkpoint**: a partir de aquí, el modelo y los endpoints base existen; las historias de usuario pueden avanzar en paralelo sobre archivos distintos.

---

## Phase 3: User Story 1 - Desactivar un vendedor sin perder su historial (Priority: P1) 🎯 MVP

**Goal**: un vendedor inactivo desaparece de los selects de asignación nuevos, pero sigue apareciendo intacto en Ventas/Presupuestos ya emitidos.

**Independent Test**: desactivar un vendedor y verificar que no aparece en el select de Vendedor al crear una Venta nueva, mientras una Venta antigua con ese vendedor sigue mostrándolo.

- [X] T009 [P] [US1] En `app/Http/Controllers/VentaController.php` líneas ~65, ~428, ~526: reemplazar `Vendedor::orderBy('nombre')->get(...)` por `Vendedor::activos()->orderBy('nombre')->get(...)` en los puntos que alimentan el select de alta/edición; dejar sin tocar la resolución de un `vendedor_id` ya guardado (línea ~398, `Vendedor::find(...)`)
- [X] T010 [P] [US1] En `app/Http/Controllers/PresupuestoController.php` líneas ~43, ~214, ~298: mismo reemplazo que T009; dejar sin tocar `Vendedor::find($configuracionVentas->vendedor_id)` (línea ~191)
- [X] T011 [P] [US1] En `app/Http/Controllers/Integraciones/TiendanubeConfiguracionController.php` línea ~36: reemplazar por `Vendedor::activos()->orderBy('nombre')->get()`
- [X] T012 [P] [US1] En `app/Http/Controllers/Integraciones/MercadoLibreConfiguracionController.php` línea ~40: mismo reemplazo que T011
- [X] T013 [US1] En `app/Http/Controllers/Configuracion/ConfiguracionController.php` línea ~30: reemplazar la colección `$vendedores` usada para el select de "Vendedor por defecto" del tab Ventas por `Vendedor::activos()->orderBy('nombre')->get()`
- [X] T014 [US1] Verificar que `app/Http/Controllers/Informes/InformeVentasController.php` línea ~49 (filtro de Informes) **no** se toca — el filtro de Informes debe seguir incluyendo inactivos (ver Assumptions de spec.md)
- [X] T014b [US1] Localizar el/los endpoint(s) del buscador inline Select2 de Vendedor en Venta/Presupuesto (spec 020 — probablemente comparte la misma colección ya filtrada por T009/T010, o es una ruta AJAX de búsqueda separada tipo `vendedores.buscar`); si es una ruta separada, aplicar `Vendedor::activos()` también ahí para cumplir el Edge Case de spec.md ("no debe ofrecer vendedores inactivos como resultado de búsqueda")
- [X] T015 [US1] Test de Feature en `tests/Feature/VendedorActivoInactivoTest.php`: crear vendedor, desactivarlo, assertir que no aparece en la data pasada a la vista de "Crear Venta" ni "Crear Presupuesto", y que una Venta/Presupuesto existente con ese vendedor sigue resolviendo su nombre correctamente

**Checkpoint**: la Historia 1 es funcional y probable de punta a punta (aunque el toggle de estado todavía sólo se pueda accionar por API/tinker, sin UI — eso lo agrega la Historia 2).

---

## Phase 4: User Story 2 - Gestionar vendedores desde Configuración & Ajustes (Priority: P2)

**Goal**: tab "Vendedores" con tabla DataTables + modal AJAX para alta/edición/cambio de estado, sin recargar la página.

**Independent Test**: entrar a Configuración & Ajustes → tab Vendedores, ver la tabla con todos los vendedores y su estado, crear uno nuevo, renombrarlo y cambiarle el estado, todo sin recarga.

- [X] T016 [US2] En `app/Http/Controllers/Configuracion/ConfiguracionController.php::index()`: agregar la ruta del endpoint de datos de Vendedores a las variables pasadas a la vista (siguiendo el patrón ya usado para Depósitos, ej. `route('vendedores.data')`)
- [X] T017 [US2] Crear `resources/views/configuracion/vendedores/_tab.blade.php` (calco de `resources/views/configuracion/depositos/_tab.blade.php`): encabezado + botón "Configurar Vendedores" + card descriptiva + `@include` del modal
- [X] T018 [US2] Crear `resources/views/configuracion/_modal_vendedores.blade.php` (calco de `resources/views/configuracion/_modal_depositos.blade.php`): modal Bootstrap con tabla DataTables (columnas Nombre, Estado, Acciones) + formulario de alta/edición inline + control de alternar estado por fila
- [X] T019 [US2] En `resources/views/configuracion/index.blade.php`: agregar `<li>` de nav-tab "Vendedores" (sin `data-tab-clave`, siempre visible — ver research.md R2) y su `tab-pane` con `@include('configuracion.vendedores._tab')`
- [X] T020 [US2] Crear `resources/js/configuracion-vendedores.js` (calco de `resources/js/configuracion-depositos.js`): inicializa DataTable vía AJAX contra `vendedores.data`, maneja submit de alta/edición vía AJAX con toasts, y el botón/switch de estado contra `vendedores.{id}.estado` con actualización in-place de la fila sin recargar
- [X] T021 [US2] Registrar `@vite(['resources/js/configuracion-vendedores.js'])` en `resources/views/configuracion/index.blade.php`
- [X] T022 [US2] Actualizar `docs/documentacion_principal_crm.md §5` (tabla de secciones de Configuración & Ajustes): agregar fila "Vendedores" documentando el nuevo tab como divergencia deliberada (sin evidencia de pantalla equivalente en los informes de Contagram relevados)
- [X] T023 [US2] Actualizar `docs/modelo_datos.md`: agregar la columna `activo` a la definición de la tabla `vendedores`

**Checkpoint**: Historias 1 y 2 combinadas entregan el pedido completo del cliente (desactivar sin borrar, gestionado desde una pantalla propia).

---

## Phase 5: User Story 3 - Vendedor por defecto que se desactiva (Priority: P3)

**Goal**: avisar en Configuración & Ajustes → Ventas si el vendedor por defecto configurado quedó inactivo, y no precargarlo en "Crear Venta".

**Independent Test**: configurar un Vendedor por defecto, desactivarlo, y verificar el aviso en el tab Ventas y que "Crear Venta" ya no lo precarga.

- [X] T024 [US3] En `app/Http/Controllers/Configuracion/ConfiguracionController.php::index()`: calcular `$vendedorPorDefectoInactivo` (bool) comparando `ConfiguracionVentas::first()->vendedor_id` contra un vendedor con `activo = false`, y pasarlo a la vista
- [X] T025 [US3] En `resources/views/configuracion/ventas/_tab.blade.php`: mostrar un `alert-warning` cuando `$vendedorPorDefectoInactivo` sea verdadero, indicando que el vendedor por defecto configurado está inactivo
- [X] T026 [US3] En `app/Http/Controllers/VentaController.php` (línea ~398, precarga de "Crear Venta"): condicionar `Vendedor::find($configuracionVentas->vendedor_id)` a que el vendedor encontrado tenga `activo = true`; si está inactivo, no precargar (dejar `null`)
- [X] T027 [US3] Test de Feature (extiende `tests/Feature/VendedorActivoInactivoTest.php`): configurar vendedor por defecto, desactivarlo, assertir que la vista de Configuración recibe el flag de aviso y que "Crear Venta" no trae ese vendedor precargado

**Checkpoint**: las tres historias completas cubren el pedido del cliente y el caso derivado del vendedor por defecto.

---

## Phase 6: Polish & Cross-Cutting

- [X] T028 [P] Revisar manualmente en navegador (regla CLAUDE.md): tab Vendedores completo (alta, edición, activar, desactivar, sin recargas, toasts), select de Vendedor en Crear Venta y Crear Presupuesto excluyendo inactivos, aviso de vendedor por defecto inactivo
- [X] T029 [P] Confirmar que el buscador inline Select2 de Vendedor en Venta/Presupuesto (spec 020) sigue funcionando sin cambios de comportamiento (regresión)
- [X] T030 Actualizar `CREDENCIALES_ACCESO.txt` sólo si la prueba manual requirió resetear alguna contraseña de prueba (condicional, según CLAUDE.md) — no fue necesario, se usaron las credenciales existentes

## Dependencies

- **Setup (T001-T002)** bloquea todo lo demás.
- **Foundational (T003-T008)** bloquea las tres historias de usuario.
- **US1 (T009-T015)** no depende de US2 ni US3; es el MVP.
- **US2 (T016-T023)** depende de Foundational (necesita `data()`/`estado()`); es independiente de US1 en el código, aunque construye sobre el mismo modelo.
- **US3 (T024-T027)** depende de Foundational y es más clara/testeable si US1 y US2 ya están (usa el flujo de desactivación real), pero técnicamente sólo depende de T003 (scope `activos()`) y T006 (`estado()`).
- **Polish (T028-T030)** depende de que US1, US2 y US3 estén implementadas.

## Parallel Example: User Story 1

```
T009, T010, T011, T012 pueden ejecutarse en paralelo (archivos distintos, sin dependencias entre sí).
T013 y T014 van después de que el patrón esté validado en T009-T012 (mismo controller/archivo Configuracion).
```

## Implementation Strategy

**MVP primero**: Setup + Foundational + User Story 1 (T001-T015) ya resuelve el pedido central del
cliente a nivel de datos (vendedor inactivo no asignable, historial intacto), aunque el cambio de
estado todavía se haría por Tinker/API sin UI. Recomendado entregar User Story 2 (T016-T023) en el
mismo lote porque sin UI el cliente no tiene forma de operar la feature — juntas son el alcance
mínimo utilizable. User Story 3 (T024-T027) es un incremento independiente que puede ir después.
