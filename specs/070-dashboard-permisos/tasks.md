# Tasks: Dashboard filtrado por permisos

**Input**: Design documents from `/specs/070-dashboard-permisos/`
**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/dashboard-endpoints.md, quickstart.md

**Tests**: Incluidos — el Principio IV de la constitución exige tests para lógica que involucra
cálculo de importes/totales, y esta feature cambia qué importes se calculan y exponen.

**Organization**: Tareas agrupadas por user story (US1–US4 de spec.md) para permitir implementación
y prueba incremental.

## Phase 1: Setup

- [x] T001 Confirmar que los 7 permisos usados (`ventas.ver`, `otros-ingresos.ver`, `compras.ver`, `gastos.ver`, `clientes.ver`, `productos.ver`, `tesoreria.ver`) están sembrados corriendo `php artisan db:seed --class=PermisoSeeder` en el entorno local; no requiere cambios de código.

## Phase 2: Foundational (bloqueante para todas las user stories)

**Objetivo**: Centralizar el cálculo de "qué rubros puede ver este usuario" en un único lugar reutilizable por los 6 métodos del `DashboardController` (research.md Decisión 1).

- [x] T002 Agregar método privado `permisosRubros(\App\Models\User $user): array` en `app/Http/Controllers/DashboardController.php` que devuelva `['ventas' => bool, 'otros_ingresos' => bool, 'compras' => bool, 'gastos' => bool, 'clientes' => bool, 'productos' => bool, 'tesoreria' => bool]` usando `$user->tienePermiso('<modulo>.ver')` para cada clave (mapeo `otros_ingresos` → código `otros-ingresos.ver`).
- [x] T003 Agregar método privado `resultadoVisible(array $permisos): bool` en `app/Http/Controllers/DashboardController.php` que devuelva `true` sólo si `ventas`, `otros_ingresos`, `compras` y `gastos` son todos `true` (research.md Decisión 3).
- [x] T003b [P] Crear trait de test `tests/Concerns/ActuaComoUsuarioConPermisos.php` con un método `actingAsUsuarioConTodosLosPermisosDashboard(): \App\Models\User` que cree un usuario, le asigne un rol con los 7 permisos `.ver` relevantes (`ventas.ver`, `otros-ingresos.ver`, `compras.ver`, `gastos.ver`, `clientes.ver`, `productos.ver`, `tesoreria.ver`) y lo autentique vía `$this->actingAs(...)` (**analyze F1**: el `TestCase` base autentica por defecto un usuario sin roles — `tests/TestCase.php:23` — lo que rompe todos los tests HTTP existentes del Dashboard en cuanto se implemente el filtrado, ya que hoy esperan las claves de rubro presentes en 0/vacío en vez de omitidas).
- [x] T003c [US-transversal] Actualizar el `setUp()` (o cada test individual, según corresponda) de los 8 archivos de test HTTP existentes que hoy dependen del usuario sin roles del `TestCase` base — `tests/Feature/DashboardKpisTest.php`, `tests/Feature/DashboardDonasTest.php`, `tests/Feature/DashboardGraficoMensualTest.php`, `tests/Feature/DashboardRankingsTest.php`, `tests/Feature/DashboardTesoreriaResumenTest.php`, `tests/Feature/DashboardEmptyStateTest.php`, `tests/Feature/DashboardNeteoNotasTest.php`, `tests/Feature/DashboardPeriodoHoyTest.php` — para que usen `actingAsUsuarioConTodosLosPermisosDashboard()` (T003b) y sigan probando lo que probaban (cálculo/neteo/estado vacío por falta de datos), no el filtrado por permiso. `DashboardCuentaCorrienteTest.php` y `DashboardNeteoHelpersTest.php` no hacen requests HTTP y quedan sin cambios.

**Checkpoint**: helpers de producción y de test listos; ningún endpoint ni test los usa todavía.

---

## Phase 3: User Story 1 — Usuario con permisos parciales entra al Dashboard (Priority: P1) 🎯 MVP

**Goal**: Cada widget de la pantalla `/dashboard` (KPIs, Totales, gráfico mensual, donas, rankings, tesorería) sólo se renderiza si el usuario tiene el/los permiso(s) `.ver` correspondiente(s).

**Independent Test**: Loguearse con un usuario cuyo rol sólo tiene `ventas.ver`, entrar a `/dashboard`, verificar que sólo aparecen los elementos de Ventas.

### Tests para User Story 1

- [x] T004 [P] [US1] Crear `tests/Feature/DashboardPermisosTest.php` con caso "usuario con sólo `ventas.ver` no ve tesorería ni rubros sin permiso en la vista `/dashboard`" (assert sobre el contenido HTML devuelto, p.ej. ausencia de los ids de las partials `_tesoreria`/`_cuentas-corrientes`).
- [x] T005 [P] [US1] En el mismo `tests/Feature/DashboardPermisosTest.php`, agregar caso "usuario con sólo `tesoreria.ver` ve el bloque de tesorería pero no KPIs/Totales/gráfico/donas/rankings".

### Implementación para User Story 1

- [x] T006 [US1] En `DashboardController::index()` (`app/Http/Controllers/DashboardController.php`), calcular `$permisos = $this->permisosRubros($request->user())` y pasarlo a la vista vía `compact`; envolver el cálculo de `$saldos`, `$movimientosRecientes`, `$cuentasACobrar`, `$cuentasAPagar` en `if ($permisos['tesoreria'])` (si no, usar colecciones/arrays vacíos como hoy hace el `EmptyState`).
- [x] T007 [US1] En `resources/views/dashboard/index.blade.php`, envolver cada `@includeIf(...)` con el `@if($permisos[...])` correspondiente: `_kpis`/`_totales`/`_grafico-mensual`/`_donas` requieren al menos un rubro visible (`$permisos['ventas'] || $permisos['otros_ingresos'] || $permisos['compras'] || $permisos['gastos']`), `_tesoreria`/`_cuentas-corrientes` requieren `$permisos['tesoreria']`, `_rankings` requiere `$permisos['ventas'] && ($permisos['clientes'] || $permisos['productos'])`.
- [x] T008 [US1] En `resources/views/dashboard/index.blade.php`, agregar `permisos: @json($permisos)` dentro de `window.DashboardConfig` para que `resources/js/dashboard.js` sepa qué tarjetas/series intentar poblar.
- [x] T009 [US1] En `resources/js/dashboard.js`, antes de poblar cada tarjeta/serie individual (KPI "Resultado", barra de un rubro en Totales, serie de un rubro en el gráfico mensual, dona de un rubro, tarjeta de un ranking), chequear `window.DashboardConfig.permisos` y/o la presencia de la clave en la respuesta AJAX (ver T012–T016) antes de intentar seleccionar o escribir en el elemento DOM correspondiente — evitar errores de "elemento no encontrado" cuando la partial no se renderizó.

**Checkpoint**: la vista `/dashboard` ya oculta bloques completos según permiso (aunque los endpoints AJAX todavía no filtran sus JSON — eso es US2).

---

## Phase 4: User Story 2 — Las respuestas AJAX no filtran nada que el usuario no debería ver (Priority: P1)

**Goal**: Ninguno de los 5 endpoints AJAX del Dashboard incluye en su JSON un rubro/serie que el usuario no tiene permiso de ver.

**Independent Test**: Con sesión de un usuario sin `compras.ver` ni `gastos.ver`, invocar `GET /dashboard/totales?periodo=mes_actual` y verificar que la respuesta no incluye `compras` ni `gastos`.

### Tests para User Story 2

- [x] T010 [P] [US2] Extender `tests/Feature/DashboardKpisTest.php` con un caso: usuario sin `compras.ver`/`gastos.ver`/`otros-ingresos.ver` (sólo `ventas.ver`) → `assertJsonMissingPath('resultado')` y assert que `ventas_creadas`/`venta_promedio`/`cantidad_ventas` sí están presentes.
- [x] T011 [P] [US2] Extender `tests/Feature/DashboardKpisTest.php` (no existe archivo dedicado al endpoint `totales`; sus casos ya conviven ahí con los de `kpis`) con caso: usuario con sólo `ventas.ver` → la respuesta de `GET /dashboard/totales` sólo trae la clave `ventas`.
- [x] T012 [P] [US2] Extender `tests/Feature/DashboardGraficoMensualTest.php` con caso: usuario con sólo `ventas.ver` → `series` sólo trae la clave `ventas`; `labels` sigue completo.
- [x] T013 [P] [US2] Extender `tests/Feature/DashboardDonasTest.php` con caso: usuario con sólo `ventas.ver` → la respuesta sólo trae la clave `ventas`.
- [x] T014 [P] [US2] Extender `tests/Feature/DashboardRankingsTest.php` con casos: (a) usuario con `ventas.ver` + `clientes.ver` pero sin `productos.ver` → sólo `clientes` en la respuesta; (b) usuario con `ventas.ver` + `productos.ver` pero sin `clientes.ver` → sólo `productos`; (c) usuario sin `ventas.ver` → `{}`.
- [x] T015 [P] [US2] Extender `tests/Feature/DashboardTesoreriaResumenTest.php` (o el test que cubra `saldos`/`movimientosRecientes`/cuentas corrientes en `index()`) con caso: usuario sin `tesoreria.ver` → esos datos no llegan renderizados en la vista.

### Implementación para User Story 2

- [x] T016 [US2] En `DashboardController::kpis()`, calcular `$permisos = $this->permisosRubros($request->user())`, condicionar el cálculo de `metricasRango()` a los rubros con permiso (evitar ejecutar queries de rubros sin permiso) y construir el array de respuesta agregando cada clave (`ventas_creadas`, `venta_promedio`, `cantidad_ventas`) sólo si `$permisos['ventas']`, y `resultado` sólo si `$this->resultadoVisible($permisos)`.
- [x] T017 [US2] En `DashboardController::totales()`, aplicar el mismo patrón: calcular y agregar `ventas` sólo si `$permisos['ventas']`, `otros_ingresos` sólo si `$permisos['otros_ingresos']`, `compras` sólo si `$permisos['compras']`, `gastos` sólo si `$permisos['gastos']`.
- [x] T018 [US2] En `DashboardController::graficoMensual()`, mover el cálculo de cada serie (`ventas`, `otros_ingresos`, `compras`, `gastos`) dentro de un condicional por permiso, agregando la clave a `$series` sólo si corresponde; `labels` no cambia.
- [x] T019 [US2] En `DashboardController::donas()`, construir la respuesta agregando `ventas` sólo si `$permisos['ventas']`, `compras` sólo si `$permisos['compras']`, `gastos` sólo si `$permisos['gastos']`.
- [x] T020 [US2] En `DashboardController::rankings()`, calcular y agregar `clientes` sólo si `$permisos['ventas'] && $permisos['clientes']`, y `productos` sólo si `$permisos['ventas'] && $permisos['productos']`.

**Checkpoint**: US1 + US2 completas — el Dashboard ya no expone datos sin permiso ni en la vista ni en los endpoints AJAX. Esto ya resuelve el problema de fondo reportado.

---

## Phase 5: User Story 3 — Admin y usuarios con todos los permisos ven el Dashboard igual que hoy (Priority: P2)

**Goal**: Verificar (sin cambios de código adicionales, ya que `tienePermiso()` ya exime a Admin) que no hay regresión para el caso de uso principal actual.

**Independent Test**: Loguearse como Admin, entrar a `/dashboard`, confirmar que aparecen todos los widgets con los mismos datos que antes del cambio.

### Tests para User Story 3

- [x] T021 [P] [US3] En `tests/Feature/DashboardPermisosTest.php`, agregar caso "usuario Admin ve absolutamente todos los widgets" (vista `index` con todas las partials presentes) y caso "endpoints `kpis`/`totales`/`grafico-mensual`/`donas`/`rankings` para Admin devuelven todas las claves, incluida `resultado`".
- [x] T022 [P] [US3] Correr la suite completa `php artisan test --filter=Dashboard` y confirmar que los tests Feature ya existentes (creados antes de esta feature, con usuarios de prueba que hoy no tienen roles asignados o son Admin implícito) siguen en verde sin modificar sus aserciones originales — si algún test preexistente asumía un usuario sin rol y ahora depende de permisos, ajustar el setup de ese test para asignarle el rol/permisos necesarios (no relajar la lógica de producción).

**Checkpoint**: US1+US2+US3 completas — no hay regresión para Admin.

---

## Phase 6: User Story 4 — Usuario sin ningún permiso relevante igual puede entrar a Inicio (Priority: P3)

**Goal**: Confirmar que `/dashboard` sigue siendo accesible (200, sin redirección) para un usuario sin ninguno de los 7 permisos, mostrando una pantalla sin widgets de datos.

**Independent Test**: Loguearse con un usuario cuyo único permiso es `mensajeria.ver`, entrar a `/dashboard`, verificar 200 sin ningún widget de datos.

### Tests para User Story 4

- [x] T023 [US4] En `tests/Feature/DashboardPermisosTest.php`, agregar caso "usuario con sólo `mensajeria.ver` recibe 200 en `GET /dashboard` y la respuesta no contiene ninguna de las partials de datos (`_kpis`, `_totales`, `_grafico-mensual`, `_donas`, `_rankings`, `_tesoreria`, `_cuentas-corrientes`)".

### Implementación para User Story 4

- [x] T024 [US4] Confirmar (sin cambio de código esperado, dado T002/T006/T007) que con `$permisos` totalmente en `false` ninguna partial se incluye; si el test T023 falla, ajustar las condiciones de `resources/views/dashboard/index.blade.php` (T007) para cubrir el caso de los 7 permisos en `false`.

**Checkpoint**: las 4 user stories completas y probadas.

---

## Phase 7: Polish & Cross-Cutting Concerns

- [x] T025 [P] Actualizar `docs/documentacion_principal_crm.md` (sección de la spec 010 / Dashboard) documentando que cada widget ahora respeta los permisos `.ver` granulares del usuario, con la tabla de mapeo permiso→widget (igual a la de `data-model.md`), por el Principio I de la constitución.
- [x] T026 [P] Revisar `resources/js/dashboard.js` para eliminar cualquier acceso directo a una clave de respuesta AJAX que ahora pueda estar ausente (`data.compras`, `data.resultado`, etc.) sin el guard agregado en T009, evitando errores de consola cuando un rubro no viene.
- [x] T027 Ejecutar `php artisan test --filter=Dashboard` completo y confirmar 100% verde antes de reportar la feature como lista.

## Dependencies & Execution Order

- **Setup (T001)** → sin dependencias.
- **Foundational (T002-T003, T003b-T003c)** → depende de T001; bloquea todas las user stories. T003c (actualizar tests existentes) debe completarse antes de T016-T020 (implementación de US2), o los 8 tests existentes quedarán rotos entre ambos pasos.
- **US1 (T004-T009)** → depende de Foundational. Es el MVP: ya oculta widgets en la vista.
- **US2 (T010-T020)** → depende de Foundational; puede desarrollarse en paralelo con US1 (T010-T015 son tests de endpoints, independientes de los cambios de vista de US1), pero conceptualmente cierra el problema junto con US1.
- **US3 (T021-T022)** → depende de US1 + US2 completas (verifica que no rompieron nada).
- **US4 (T023-T024)** → depende de US1 (T007) — es el caso límite de la misma lógica de ocultamiento.
- **Polish (T025-T027)** → depende de todas las user stories completas.

## Parallel Example

Dentro de US2, los tests T010–T015 tocan 5 archivos de test distintos y pueden escribirse en paralelo:

```
T010 [P] [US2] tests/Feature/DashboardKpisTest.php (caso resultado ausente)
T011 [P] [US2] tests/Feature/DashboardKpisTest.php o test de Totales (caso totales parcial)
T012 [P] [US2] tests/Feature/DashboardGraficoMensualTest.php
T013 [P] [US2] tests/Feature/DashboardDonasTest.php
T014 [P] [US2] tests/Feature/DashboardRankingsTest.php
T015 [P] [US2] tests/Feature/DashboardTesoreriaResumenTest.php
```

## Implementation Strategy

**MVP first**: Foundational (T002-T003) + US1 (T004-T009) ya resuelve la fuga visible en pantalla.
**Completo**: sumar US2 (T010-T020) es lo que cierra la fuga real (respuestas AJAX crudas) — no
debería entregarse US1 sin US2, ambas son P1 y forman parte del mismo arreglo de seguridad.
US3 y US4 son verificación de no-regresión y del caso límite, respectivamente.
