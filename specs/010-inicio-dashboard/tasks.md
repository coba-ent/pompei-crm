---
description: "Task list — Módulo Inicio (Dashboard)"
---

# Tasks: Módulo Inicio (Dashboard)

**Input**: Design documents from `specs/010-inicio-dashboard/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/dashboard-rutas.md, quickstart.md

**⚠️ Dependencia dura**: **spec 007 (Tesorería)**, **spec 008 (Ingresos)** y **spec 009 (Egresos)**
implementadas — se reutilizan `Tesoreria::saldos()`, `Venta::aCobrar()`/`fecha_vto_cobro`,
`Compra::aPagar()`/`fecha_vto_pago`, `OtroIngreso`, `Gasto`, `Categoria`. Sin esas tres specs, ninguna
user story de este módulo se puede completar (es una capa de agregación sobre esos datos).

**Tests**: INCLUIDOS — el dashboard muestra dinero real (KPIs, aging de Cuenta Corriente, resumen de
Tesorería), cubierto por el Principio IV de la Constitución.

**Organización**: por User Story (US1–US6), en orden de prioridad.

## Path Conventions

Monolito Laravel: `app/`, `resources/`, `routes/`, `tests/` en la raíz. Sin migraciones nuevas (módulo
de sólo lectura).

---

## Phase 1: Setup (Shared Infrastructure)

- [X] T001 [P] Registrar el grupo de rutas `/dashboard/*` en `routes/web.php` según
  `contracts/dashboard-rutas.md` (apuntando a `DashboardController`, aún sin crear — declarar las rutas
  primero, la Fase 2 crea la clase), y cambiar `Route::get('/', [ClienteController::class, 'index'])->
  name('home')` por `Route::redirect('/', '/dashboard')`.
- [X] T002 [P] Actualizar `resources/views/elements/sidebar.blade.php`: el ítem "Inicio" pasa a apuntar
  a `route('dashboard.index')` (hoy placeholder o inexistente).
- [X] T003 [P] Agregar `resources/js/dashboard.js` a la lista de entradas de `vite.config.js` (junto a
  `clientes.js`, `ventas.js`, etc.), archivo vacío por ahora.

---

## Phase 2: Foundational (Blocking Prerequisites)

**⚠️ CRITICAL**: bloquea todas las user stories — sin esto no hay pantalla ni endpoint que probar.

- [X] T004 Crear esqueleto de `app/Http/Controllers/DashboardController.php` con método `index()`
  (`$CurrentPage = 'home'`, retorna vista `dashboard.index`) y métodos vacíos `kpis()`, `totales()`,
  `graficoMensual()`, `donas()`, `rankings()` (stubs que devuelven JSON vacío) — cada User Story
  completa su propio método. Depende de T001.
- [X] T005 Crear vista base `resources/views/dashboard/index.blade.php` (`@extends('layouts.default')`,
  contenedor `content-body`/`container-fluid`, con los `@include` de los partials que cada User Story
  agrega: `dashboard._kpis`, `dashboard._totales`, `dashboard._grafico-mensual`, `dashboard._tesoreria`,
  `dashboard._cuentas-corrientes`, `dashboard._donas`, `dashboard._rankings` — usar `@includeIf` para
  que la vista no rompa mientras los partials todavía no existen). Depende de T004.
- [X] T006 Crear helper privado `DashboardController::rangoPeriodo(string $periodo): array` (retorna
  `[desde, hasta, desde_anterior, hasta_anterior]` según `semana|mes_actual|mes_anterior|anio_actual`,
  default `mes_actual` ante valor inválido/ausente — usado por T008/T014/T025/T030). Depende de T004.

**Checkpoint**: `/dashboard` carga (vacío) sin error 500; helper de período listo para reutilizar.

---

## Phase 3: User Story 1 — Ver el estado general del negocio al iniciar sesión (Priority: P1) 🎯 MVP

**Goal**: 4 KPIs (Ventas Creadas, Venta Promedio, Cantidad de Ventas, Resultado) con variación % vs.
período anterior, y panel de totales apilados (Ventas/Otros Ingresos/Compras/Gastos) del mes actual.

**Independent Test**: con ventas/compras/gastos del mes actual cargados, cargar `/dashboard` y verificar
que las 4 tarjetas y el panel de totales muestran cifras correctas (contrastando contra `SUM` manual en
la base).

### Tests for User Story 1 ⚠️

- [X] T007 [P] [US1] `tests/Feature/DashboardKpisTest.php`: Ventas Creadas/Venta Promedio/Cantidad de
  Ventas/Resultado calculados correctamente para `mes_actual`; variación % correcta con datos en ambos
  períodos; variación `null` ("sin datos previos") cuando el período anterior es cero (spec US1-AC2);
  Resultado = Ventas + Otros Ingresos (no pendientes) − Compras − Gastos (no pendientes).

### Implementation for User Story 1

- [X] T008 [US1] Implementar `DashboardController::kpis()`: usa `rangoPeriodo()` (T006), agrega
  `SUM(Venta.total)`/`COUNT(Venta)` (excluye soft-deleted) para el rango actual y el anterior, calcula
  Venta Promedio, Resultado (+ `OtroIngreso` no pendiente, − `Compra.total`, − `Gasto` no pendiente), y
  la variación % de cada KPI (`null` si el valor anterior es 0). Devuelve JSON según
  `contracts/dashboard-rutas.md`.
- [X] T009 [US1] Implementar `DashboardController::totales()`: mismos 4 totales (Ventas/Otros Ingresos/
  Compras/Gastos) del rango actual, sin variación, para alimentar las barras de progreso.
- [X] T010 [P] [US1] Vista `resources/views/dashboard/_kpis.blade.php`: 4 tarjetas KPI con flecha verde/
  roja o texto "sin datos previos" cuando la variación es `null`.
- [X] T011 [P] [US1] Vista `resources/views/dashboard/_totales.blade.php`: 4 barras de progreso
  proporcionales al peso de cada total sobre la suma de los cuatro, con el monto exacto al lado.
- [X] T012 [US1] `resources/js/dashboard.js`: al cargar la página, `fetch` a `dashboard.kpis` y
  `dashboard.totales` con `periodo=mes_actual`, renderizar valores y barras (sin recarga de página).

**Checkpoint**: US1 funcional — `/dashboard` muestra el resumen financiero del mes actual.

---

## Phase 4: User Story 2 — Consultar el gráfico mensual comparativo (Priority: P2)

**Goal**: gráfico de barras apiladas (ApexCharts) con Ventas/Otros Ingresos/Compras/Gastos de los
últimos 12 meses, incluyendo meses sin operaciones en cero.

**Independent Test**: con operaciones en al menos 3 meses distintos, verificar que el gráfico muestra
12 barras (una por mes) con los 4 segmentos correctos, sin omitir ningún mes.

### Tests for User Story 2 ⚠️

- [X] T013 [P] [US2] `tests/Feature/DashboardGraficoMensualTest.php`: la serie devuelve exactamente 12
  puntos (uno por mes, orden cronológico); un mes sin operaciones devuelve `0` explícito para las 4
  series, no se omite del arreglo (spec US2-AC2, SC-004).

### Implementation for User Story 2

- [X] T014 [US2] Implementar `DashboardController::graficoMensual()`: para cada uno de los últimos 12
  meses (incluye el actual), `SUM` agrupado por `YEAR-MM` de `fecha_emision`/`fecha` para Venta/
  OtroIngreso/Compra/Gasto — no usa `rangoPeriodo()` (FR-008: este bloque es fijo, no depende del
  selector de período).
- [X] T015 [P] [US2] Vista `resources/views/dashboard/_grafico-mensual.blade.php`: `<div id="grafico-
  mensual">` para el canvas de ApexCharts (usa `vendor/apexchart/apexchart.js`, ya cargado vía
  `config('dz.pagelevel.home')` — ver research.md §1).
- [X] T016 [US2] `resources/js/dashboard.js`: inicializar ApexCharts (barras apiladas, leyenda de
  colores) con los datos de `dashboard.grafico-mensual`. Depende de T014, T015.

**Checkpoint**: US1 + US2 funcionan juntas — resumen mensual + histórico de 12 meses.

---

## Phase 5: User Story 3 — Consultar el resumen de Tesorería y movimientos recientes (Priority: P2)

**Goal**: panel de Tesorería (Total Disponible/Cajas/Bancos) + mini-tabla de últimos movimientos,
reutilizando el servicio de saldos de spec 007 sin duplicar lógica.

**Independent Test**: con cuentas de Tesorería y movimientos cargados (spec 007), verificar que Total
Disponible = Total Cajas + Total Bancos y coincide con lo que muestra `/tesoreria` (vista Saldos).

### Tests for User Story 3 ⚠️

- [X] T017 [P] [US3] `tests/Feature/DashboardTesoreriaResumenTest.php`: el bloque de Tesorería del
  dashboard coincide exactamente con `Tesoreria::saldos()` para las mismas cuentas (Total Disponible =
  Cajas + Bancos); la mini-tabla de movimientos recientes está ordenada por fecha descendente y
  respeta el límite de filas configurado (5-10, FR-005).

### Implementation for User Story 3

- [X] T018 [US3] Extender `DashboardController::index()`: agrega al payload de la vista el bloque de
  Tesorería (`app(Tesoreria::class)->saldos()`, ya construido en spec 007 — **sin nueva lógica de
  cálculo**) y los últimos N `MovimientoTesoreria` (`orderBy('fecha', 'desc')->limit(N)`), con signo +/−
  según el tipo de movimiento. Este bloque se resuelve una sola vez en `index()`, no tiene endpoint
  AJAX propio (research.md §3 — no depende del selector de período).
- [X] T019 [P] [US3] Vista `resources/views/dashboard/_tesoreria.blade.php`: Total Disponible/Cajas/
  Bancos con íconos de color, mini-tabla Fecha/Cuenta/Monto con signo.

**Checkpoint**: US1 + US2 + US3 funcionan juntas.

---

## Phase 6: User Story 4 — Consultar Cuentas a Cobrar y a Pagar con antigüedad de deuda (Priority: P2)

**Goal**: dos bloques (Ventas a Cobrar / Compras a Pagar) con monto total y desglose de aging (A
Vencer, Vencido, 0-30, 31-60, 61-90, +90 días), calculados con un servicio de dominio nuevo y
reutilizable.

**Independent Test**: con una venta con saldo pendiente vencida hace 45 días, verificar que su monto
aparece en el bucket "31 a 60" y suma correctamente al total del bloque.

### Tests for User Story 4 ⚠️

- [X] T020 [P] [US4] `tests/Feature/DashboardCuentaCorrienteTest.php`: clasificación correcta en cada
  bucket según `fecha_vto_cobro`/`fecha_vto_pago` vs. fecha de corte (spec US4-AC1/AC2); un documento
  con saldo cero no aporta a ningún bucket (spec US4-AC3); el `total` del bloque es exactamente la suma
  de `Venta::aCobrar()`/`Compra::aPagar()` de todos los documentos con saldo pendiente incluidos
  (invariante de data-model.md); una Nota de Crédito/Débito sobre una venta afecta el saldo usado para
  el aging (Edge Case de spec.md).

### Implementation for User Story 4

- [X] T021 [US4] Crear `app/Services/Tesoreria/CuentaCorriente.php` con método público
  `aging(string $tipo, ?Carbon $fecha = null): array` (`$tipo` = `cliente`|`proveedor`), según el
  algoritmo de data-model.md §"Entidad calculada: Aging de Cuenta Corriente" (agrupa por
  `cliente_id`/`proveedor_id`, clasifica cada documento con saldo pendiente en un único bucket).
- [X] T022 [US4] Extender `DashboardController::index()`: agrega al payload `cuentas_a_cobrar` (
  `CuentaCorriente::aging('cliente')`) y `cuentas_a_pagar` (`CuentaCorriente::aging('proveedor')`) —
  resuelto una sola vez, sin endpoint AJAX propio (no depende del período, FR-008). Depende de T021.
- [X] T023 [P] [US4] Vista `resources/views/dashboard/_cuentas-corrientes.blade.php`: dos bloques
  (verde "Total Ventas a Cobrar" / rojo "Total Compras a Pagar") con monto destacado y desglose de los
  6 buckets.

**Checkpoint**: US1-US4 funcionan juntas — el dashboard ya cubre todo lo prioritario (P1/P2) de la spec.

---

## Phase 7: User Story 5 — Filtrar por período y ver composición por categoría (Priority: P3)

**Goal**: selector de período (Última Semana/Mes Actual/Mes Anterior/Año Actual) que recalcula KPIs/
totales/gráfico mensual/donas/rankings, y 3 donas de composición por categoría.

**Independent Test**: cambiar a "Año Actual" y verificar que KPIs, totales y donas se recalculan; las
donas suman 100% entre sus porciones.

### Tests for User Story 5 ⚠️

- [X] T024 [P] [US5] `tests/Feature/DashboardDonasTest.php`: la dona de Ventas por categoría suma
  100% entre sus porciones dentro del período filtrado (spec US5-AC2); una categoría soft-deleted con
  ventas históricas se agrupa bajo "Sin categoría" (spec US5-AC3, FR-009).

### Implementation for User Story 5

- [X] T025 [US5] Implementar `DashboardController::donas()`: `SUM GROUP BY categoria_id` (Ventas,
  Compras, Gastos por separado) dentro del rango de `rangoPeriodo()` (T006), agrupando `categoria_id`
  `null` o soft-deleted bajo la etiqueta fija `"Sin categoría"`.
- [X] T026 [US5] Agregar el selector de período (tabs Última Semana/Mes Actual/Mes Anterior/Año
  Actual) a la vista del dashboard (`dashboard/index.blade.php` o un partial `_periodo.blade.php`), y
  conectar `resources/js/dashboard.js` para que, al cambiar de tab, vuelva a pedir `dashboard.kpis`,
  `dashboard.totales` y `dashboard.donas` con el nuevo `?periodo=` (Tesorería y Cuentas a Cobrar/Pagar
  **no** se vuelven a pedir — research.md §3). Depende de T008, T009, T025.
- [X] T027 [P] [US5] Vista `resources/views/dashboard/_donas.blade.php`: 3 gráficos de dona (ApexCharts)
  con leyenda de nombre de categoría + porcentaje.

**Checkpoint**: selector de período funcional sobre todos los bloques que corresponde.

---

## Phase 8: User Story 6 — Ver rankings rápidos de Clientes y Productos (Priority: P3)

**Goal**: Ranking de Clientes (top por monto vendido) y Ranking de Productos (top por cantidad
vendida) dentro del período filtrado.

**Independent Test**: con ventas de 3+ clientes y 3+ productos, verificar el orden descendente de
ambos rankings.

### Tests for User Story 6 ⚠️

- [X] T028 [P] [US6] `tests/Feature/DashboardRankingsTest.php`: Ranking de Clientes ordenado
  descendente por `SUM(Venta.total)`; Ranking de Productos ordenado descendente por
  `SUM(VentaItem.cantidad)`, excluyendo ventas soft-deleted.

### Implementation for User Story 6

- [X] T029 [US6] Implementar `DashboardController::rankings()`: `SUM(Venta.total) GROUP BY cliente_id`
  y `SUM(VentaItem.cantidad) GROUP BY producto_id` (join con `ventas` para período + exclusión de
  soft-deleted), top N (10) cada uno, dentro del rango de `rangoPeriodo()`.
- [X] T030 [P] [US6] Vista `resources/views/dashboard/_rankings.blade.php`: dos listas ordenadas con
  nombre + monto/cantidad.
- [X] T031 [US6] `resources/js/dashboard.js`: incluir `dashboard.rankings` en el refresh por cambio de
  período (junto a T026). Depende de T029, T030.

**Checkpoint**: las 6 user stories funcionan juntas — dashboard completo según la spec.

---

## Phase 9: Polish & Cross-Cutting Concerns

- [X] T032 [P] `tests/Feature/DashboardEmptyStateTest.php`: con base de datos sin datos de negocio
  (sin seeders de Ventas/Compras/Gastos/Tesorería), `GET /dashboard` responde 200 y todos los bloques
  (KPIs, totales, gráfico mensual, Tesorería, Cuentas a Cobrar/Pagar, donas, rankings) devuelven
  estado vacío (ceros) sin excepción — cubre SC-005/FR-012 (gap detectado en `/speckit-analyze`, F2).
- [X] T033 [P] Actualizar `docs/documentacion_principal_crm.md`: documentar el módulo Inicio/Dashboard
  como implementado (spec 010), y sacar "Inicio / Panel de Control" de la lista de "Módulos pendientes
  de re-relevamiento" (§7) — aclarando que el aging de Cuenta Corriente es un cálculo mínimo para este
  panel, no las pantallas completas de Cta Cte (que siguen pendientes).
- [X] T034 [P] Actualizar `docs/modelo_datos.md`: la nota "Cuenta Corriente no implementada" pasa a
  reflejar que existe un servicio de cálculo (`CuentaCorriente::aging()`, sin tabla propia) reutilizable
  por una futura spec de Informes.
- [X] T035 Ejecutar `quickstart.md` end-to-end manualmente (login → redirect a `/dashboard`, cambio de
  período, estado vacío en base sin datos de negocio).
- [X] T036 [P] Revisar responsive de los partials nuevos (mobile: gráfico mensual y donas se apilan
  verticalmente, sin scroll horizontal de página).

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: sin dependencias — puede arrancar de inmediato.
- **Foundational (Phase 2)**: depende de Setup — BLOQUEA todas las user stories.
- **User Stories (Phase 3-8)**: todas dependen de Foundational.
  - US1 (P1) es el MVP — sin dependencia de otras stories.
  - US2, US3, US4 (P2) son independientes entre sí una vez completado US1 (comparten sólo la vista base
    de Foundational), **salvo** US3 y US4 que editan el mismo método `DashboardController::index()`
    (ver Parallel Opportunities).
  - US5 (P3) depende de que existan los endpoints de US1 (`kpis`, `totales`) para poder re-pedirlos por
    período — requiere US1 completo.
  - US6 (P3) es independiente de US2-US5, sólo depende de Foundational.
- **Polish (Phase 9)**: depende de que las user stories deseadas estén completas.

### Parallel Opportunities

- Setup: T001, T002, T003 en paralelo (archivos distintos).
- Foundational: T004 → T005/T006 (T005 y T006 dependen de que exista la clase de T004, pero son
  independientes entre sí).
- Tests de cada User Story ([P] marcados) en paralelo entre sí antes de su implementación.
- US2 y US6 se pueden implementar en paralelo con cualquier otra story (no comparten archivos de
  lógica con nadie más). **US3 y US4 sí comparten archivo**: T018 (US3) y T022 (US4) extienden el
  mismo método `DashboardController::index()` — coordinar su edición (secuenciarlas, o resolverlas en
  un único commit) en vez de trabajarlas en simultáneo por personas distintas, para evitar conflictos
  de merge (gap detectado en `/speckit-analyze`, F4).

---

## Parallel Example: User Story 1

```bash
Task: "tests/Feature/DashboardKpisTest.php"
Task: "resources/views/dashboard/_kpis.blade.php"
Task: "resources/views/dashboard/_totales.blade.php"
```

---

## Implementation Strategy

### MVP First (User Story 1 Only)

1. Completar Phase 1 (Setup) + Phase 2 (Foundational).
2. Completar Phase 3 (US1): KPIs + totales del mes actual.
3. **Detener y validar**: `/dashboard` muestra el resumen financiero correcto del mes actual.
4. Demo/deploy si está listo — ya es una mejora real sobre no tener pantalla de aterrizaje.

### Incremental Delivery

1. Setup + Foundational → base lista.
2. US1 → validar → demo (MVP).
3. US2 (gráfico mensual) en paralelo con US3 (Tesorería) y/o US4 (Cuentas a Cobrar/Pagar) — pero US3 y
   US4 entre sí, coordinadas (comparten `DashboardController::index()`) → validar cada una por separado.
4. US4 (Cuentas a Cobrar/Pagar) → validar el aging con datos reales vencidos.
5. US5 (período + donas) y US6 (rankings) al final, en paralelo.
6. Polish (docs + revisión responsive).
