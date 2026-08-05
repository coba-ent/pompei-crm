# Tasks: Neteo de Notas de Crédito/Débito en el Dashboard de Inicio

**Input**: Design documents from `/specs/046-dashboard-neteo-nc-nd/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, quickstart.md

**Tests**: Incluidos — la constitución del proyecto (Principio IV) exige testing donde hay dinero o
impacto fiscal, y estos KPIs son montos de dinero mostrados al usuario.

**Organization**: Tareas agrupadas por Historia de Usuario (spec.md). Todas tocan el mismo archivo
(`DashboardController.php`), así que casi ninguna es `[P]` entre sí dentro de una misma historia —
sí lo son entre historias distintas de test (archivos de test independientes).

## Path Conventions

Proyecto Laravel único (monolito): `app/`, `resources/`, `tests/` en la raíz del repo.

---

## Phase 1: Setup

**Purpose**: Sin infraestructura nueva — no hay dependencias, migraciones ni paquetes que agregar
(reutiliza modelos y tablas existentes, ver plan.md). Fase vacía por diseño.

**Checkpoint**: No aplica — pasar directo a Foundational.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Construir el helper SQL de neteo reutilizable (research.md Decisión 1) que todas las
Historias de Usuario necesitan, antes de tocar los 4 métodos públicos del controller.

**⚠️ CRITICAL**: Ninguna historia puede implementarse sin este helper.

- [X] T001 Agregar método privado `montoNetoQuery(string $modelo, string $columnaFk, string $campoFecha, Carbon $desde, Carbon $hasta): float` en `app/Http/Controllers/DashboardController.php` que implemente la fórmula de dos componentes de research.md Decisión 1 (subconsulta correlacionada con `GREATEST(0, ...)` por fila dentro del período + componente de notas de período cruzado sin piso), parametrizado por modelo (`Venta::class`/`Compra::class`) y columna FK (`venta_id`/`compra_id`) para reutilizarlo simétricamente
- [X] T002 Agregar método privado `montoNetoPorCategoriaQuery(string $modelo, string $columnaFk, string $campoFecha, Carbon $desde, Carbon $hasta): array` en `app/Http/Controllers/DashboardController.php` — misma lógica de T001 pero agrupada por `categoria_id` (categoría vigente heredada de la Venta/Compra, ver FR-006), para alimentar `composicionPorCategoria()`
- [X] T003 [P] Crear `tests/Feature/DashboardNeteoHelpersTest.php` con casos unitarios directos de `montoNetoQuery()`/`montoNetoPorCategoriaQuery()` (vía reflexión o exponiéndolos como `protected` para test): venta sin notas, venta con NC total (piso $0), venta con NC parcial, venta con ND, NC de período distinto sin piso — cubre research.md Decisión 1 antes de integrarlo en los endpoints públicos

**Checkpoint**: Helper de neteo probado de forma aislada — las historias de usuario ya pueden
integrarlo en los endpoints públicos.

---

## Phase 3: User Story 1 - KPIs del dashboard reflejan el monto neto real de Ventas (Priority: P1) 🎯 MVP

**Goal**: "Ventas Creadas", "Venta Promedio" y "Resultado" restan NC/suman ND de Ventas por período
de emisión de cada nota, con piso $0 (FR-001, FR-003, FR-007).

**Independent Test**: Crear una venta con NC total/parcial/ND (mismo período o período cruzado) y
verificar que `GET /dashboard/kpis?periodo=mes_actual` devuelve el monto neto esperado.

### Tests for User Story 1

- [X] T004 [P] [US1] Test Feature en `tests/Feature/DashboardNeteoNotasTest.php`: Acceptance Scenario 1 (venta $100.000 + NC total $100.000 mismo período → `ventas_creadas` aporta $0 de esa venta)
- [X] T005 [P] [US1] Test Feature en `tests/Feature/DashboardNeteoNotasTest.php`: Acceptance Scenario 2 (venta $100.000 + NC parcial $30.000 → suma $70.000)
- [X] T006 [P] [US1] Test Feature en `tests/Feature/DashboardNeteoNotasTest.php`: Acceptance Scenario 3 (venta $100.000 + ND $10.000 → suma $110.000)
- [X] T007 [P] [US1] Test Feature en `tests/Feature/DashboardNeteoNotasTest.php`: Acceptance Scenario 4 (venta en mes anterior + NC en mes actual → se resta del mes actual, mes anterior queda en bruto)
- [X] T008 [P] [US1] Test Feature en `tests/Feature/DashboardNeteoNotasTest.php`: "Resultado" y "Venta Promedio" derivan del monto neto (FR-003)
- [X] T009 [P] [US1] Test Feature en `tests/Feature/DashboardNeteoNotasTest.php`: "Cantidad de Ventas" NO cambia con una venta anulada al 100% (FR-004, regresión SC-004)

### Implementation for User Story 1

- [X] T010 [US1] Modificar `metricasRango()` en `app/Http/Controllers/DashboardController.php` para calcular `ventas_creadas` vía `montoNetoQuery(Venta::class, 'venta_id', 'fecha_emision', $desde, $hasta)` (T001) en lugar de `Venta::whereBetween(...)->sum('total')`, manteniendo `cantidad_ventas` sin cambios (FR-004)
- [X] T011 [US1] Verificar que `venta_promedio` y `resultado` en `metricasRango()` derivan automáticamente del nuevo `ventasCreadas` neto (ya son cálculos posteriores sobre esa variable — confirmar que no hace falta tocar su fórmula, sólo su insumo)
- [X] T012 [US1] Correr `php artisan test --filter=DashboardNeteoNotasTest` y `--filter=DashboardNeteoHelpersTest`, confirmar T004-T009 en verde

**Checkpoint**: "Ventas Creadas"/"Venta Promedio"/"Resultado" ya reflejan el neto real — MVP entregable.

---

## Phase 4: User Story 2 - El mismo neteo aplica simétricamente a Compras (Priority: P2)

**Goal**: El total de "Compras" del panel de Totales del Período (dentro de `metricasRango()`) resta
NC/suma ND de Compras con el mismo criterio que Ventas (FR-002).

**Independent Test**: Crear una compra con NC/ND y verificar que `GET /dashboard/totales?periodo=mes_actual` devuelve `compras` neto.

### Tests for User Story 2

- [X] T013 [P] [US2] Test Feature en `tests/Feature/DashboardNeteoNotasTest.php`: Acceptance Scenario 1 de Historia 2 (compra $50.000 + NC $50.000 → `compras` no la incluye)
- [X] T014 [P] [US2] Test Feature en `tests/Feature/DashboardNeteoNotasTest.php`: Acceptance Scenario 2 de Historia 2 (compra $50.000 + ND $5.000 → suma $55.000)

### Implementation for User Story 2

- [X] T015 [US2] Modificar `metricasRango()` en `app/Http/Controllers/DashboardController.php` para calcular `compras` vía `montoNetoQuery(Compra::class, 'compra_id', 'fecha_emision', $desde, $hasta)` (T001), en vez de `Compra::whereBetween(...)->sum('total')`
- [X] T016 [US2] Confirmar que `resultado` (que ya resta `compras` de `metricasRango()`) queda automáticamente neto de ambos lados (Ventas y Compras) sin tocar su fórmula
- [X] T017 [US2] Correr `php artisan test --filter=DashboardNeteoNotasTest`, confirmar T013-T014 en verde

**Checkpoint**: Panel de Totales del Período neto en Ventas y Compras — "Resultado" simétrico.

---

## Phase 5: User Story 3 - Evolución Mensual y donas por categoría quedan netos (Priority: P2)

**Goal**: El gráfico de 12 meses (`graficoMensual()`) y las donas de composición por categoría
(`composicionPorCategoria()`, usada por `donas()`) usan el mismo criterio de neteo mes a mes /
categoría a categoría (FR-005, FR-006).

**Independent Test**: Con la venta con NC total de US1, verificar que la barra de "Ventas" del mes
correspondiente en `GET /dashboard/grafico-mensual` y la porción de esa categoría en
`GET /dashboard/donas?periodo=mes_actual` tampoco incluyen el monto.

### Tests for User Story 3

- [X] T018 [P] [US3] Test Feature en `tests/Feature/DashboardNeteoNotasTest.php`: Acceptance Scenario 1 de Historia 3 (barra de Evolución Mensual del mes de la NC no incluye la venta anulada)
- [X] T019 [P] [US3] Test Feature en `tests/Feature/DashboardNeteoNotasTest.php`: Acceptance Scenario 2 de Historia 3 (dona de Ventas por categoría no incluye la porción anulada)
- [X] T020 [P] [US3] Test Feature en `tests/Feature/DashboardNeteoNotasTest.php`: dona con Venta/Compra sin `categoria_id` o con categoría eliminada/inactiva se agrupa bajo "Sin categoría" incluyendo el ajuste de su NC/ND (edge case FR-006)

### Implementation for User Story 3

- [X] T021 [US3] Modificar `graficoMensual()` en `app/Http/Controllers/DashboardController.php`: reemplazar `Venta::whereBetween(...)->sum('total')` y `Compra::whereBetween(...)->sum('total')` de cada iteración mensual por `montoNetoQuery()` (T001) con el rango `[$desde, $hasta]` de ese mes
- [X] T022 [US3] Modificar `composicionPorCategoria()` en `app/Http/Controllers/DashboardController.php` para usar `montoNetoPorCategoriaQuery()` (T002) en lugar de `selectRaw("categoria_id, SUM({$campoMonto}) as monto")->groupBy('categoria_id')`, preservando la lógica existente de agrupar bajo "Sin categoría" cuando la categoría está eliminada/inactiva/ausente
- [X] T023 [US3] Correr `php artisan test --filter=DashboardNeteoNotasTest`, confirmar T018-T020 en verde; validar manualmente SC-002/SC-003 (consistencia entre KPI, gráfico y dona) con el escenario 5 de quickstart.md (venta real reconstruida en VPS)

**Checkpoint**: Los 3 widgets monetarios del dashboard (KPIs, gráfico, donas) muestran la misma
fuente de verdad neta — Historias 1-3 completas.

---

## Phase 6: User Story 4 - Filtrar el Dashboard por "Hoy" (Priority: P3)

**Goal**: Agregar la opción "Hoy" al selector de período (FR-010, FR-011, FR-012), comparada contra
"Ayer" para la variación %.

**Independent Test**: Seleccionar "Hoy" en el selector y verificar que KPIs/Totales/Donas
recalculan sólo con operaciones de hoy, sin afectar gráfico mensual ni aging.

### Tests for User Story 4

- [X] T024 [P] [US4] Test Feature en `tests/Feature/DashboardPeriodoHoyTest.php`: `periodo=hoy` filtra KPIs/Totales/Donas a operaciones con `fecha_emision`/`fecha` = hoy (Acceptance Scenarios 1-2 de Historia 4)
- [X] T025 [P] [US4] Test Feature en `tests/Feature/DashboardPeriodoHoyTest.php`: con `periodo=hoy`, la variación % de cada KPI compara contra "Ayer" (Acceptance Scenario 4 / FR-012), incluyendo el caso sin datos de ayer → `null`
- [X] T026 [P] [US4] Test Feature en `tests/Feature/DashboardPeriodoHoyTest.php`: `graficoMensual()` y el aging de Cta Cte no cambian cuando `periodo=hoy` (Acceptance Scenario 3 / FR-011, regresión)

### Implementation for User Story 4

- [X] T027 [US4] Agregar `'hoy'` a `PERIODOS_VALIDOS` y al `switch` de `rangoPeriodo()` en `app/Http/Controllers/DashboardController.php`, con `$desde = $hasta = Carbon::today()` (research.md Decisión 3 — el cálculo genérico de período anterior ya produce "Ayer" sin lógica especial)
- [X] T028 [US4] Agregar un botón `<button data-periodo="hoy">Hoy</button>` en `resources/views/dashboard/_periodo.blade.php` (mismo patrón que los 4 botones existentes) — no requiere cambios en `resources/js/dashboard.js`, que ya engancha `button[data-periodo]` por delegación de eventos (`$('#dashboard-periodo').on('click', 'button[data-periodo]', ...)`, línea 165)
- [X] T029 [US4] Correr `php artisan test --filter=DashboardPeriodoHoyTest`, confirmar T024-T026 en verde; validar manualmente escenario 7 de quickstart.md en el navegador

**Checkpoint**: Las 4 historias completas — dashboard neteado + filtro "Hoy" operativo.

---

## Phase 7: Polish & Cross-Cutting Concerns

**Purpose**: Regresión final y housekeeping.

- [X] T030 [P] Test Feature de regresión en `tests/Feature/DashboardNeteoNotasTest.php`: SC-005 — con datos sin ninguna NC/ND, los 4 endpoints devuelven valores idénticos a antes del cambio (comparar contra fixture/snapshot del comportamiento previo)
- [X] T031 Correr la suite completa `php artisan test` para descartar regresiones fuera del alcance directo de esta feature
- [X] T032 Ejecutar manualmente los 8 escenarios de `specs/046-dashboard-neteo-nc-nd/quickstart.md` contra el ambiente local, incluyendo el Escenario 5 (venta real del VPS) contra el VPS de producción
- [ ] T033 [P] Deploy al VPS (`bash deploy_vps.sh`, ver `.claude/skills/deploy/SKILL.md`) una vez validados local y quickstart

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: vacía, sin bloqueos.
- **Foundational (Phase 2)**: bloquea todas las historias — el helper `montoNetoQuery()`/
  `montoNetoPorCategoriaQuery()` (T001-T002) es prerequisito de T010, T015, T021, T022.
- **User Story 1 (Phase 3)**: depende de Foundational. Es el MVP.
- **User Story 2 (Phase 4)**: depende de Foundational. Independiente de US1 (toca `compras`, no
  `ventas_creadas`, dentro del mismo `metricasRango()` pero en una línea distinta) — puede
  implementarse en paralelo a US1 por otro desarrollador, aunque ambas tocan el mismo archivo (no
  archivo compartido en sentido estricto de `[P]`, cuidado con conflictos de merge).
- **User Story 3 (Phase 5)**: depende de Foundational. Independiente de US1/US2 (toca
  `graficoMensual()`/`composicionPorCategoria()`, métodos distintos del mismo controller).
- **User Story 4 (Phase 6)**: depende de Foundational únicamente para `rangoPeriodo()` — no depende
  del neteo (T001/T002) en sí, sólo reutiliza `rangoPeriodo()` ya existente. Totalmente independiente
  de US1/US2/US3 en términos funcionales (podría implementarse primero sin bloquear nada).
- **Polish (Phase 7)**: depende de que las historias que se quieran entregar estén completas.

### Dentro de cada historia

- Tests (T004-T009, T013-T014, T018-T020, T024-T026) se escriben y deben fallar antes de la
  implementación correspondiente (T010-T012, T015-T017, T021-T023, T027-T029).
- Todas las tareas de implementación dentro de una historia tocan `DashboardController.php` — no
  son `[P]` entre sí (mismo archivo). Los tests sí son `[P]` entre sí dentro de la misma historia
  (mismo archivo de test pero casos independientes — marcar `[P]` es una simplificación aceptable
  ya que son métodos de test distintos sin dependencia de orden).

### Parallel Opportunities

- T003 (helper tests) puede escribirse en paralelo a T001-T002 si se sigue TDD estricto (tests
  primero, deben fallar).
- US4 (Phase 6) es completamente independiente de US1-US3 — puede asignarse a otro desarrollador o
  implementarse primero/último sin afectar el resto.
- T033 (deploy) es `[P]` respecto de T031/T032 sólo en el sentido de que no modifica código — pero
  lógicamente debe ir último (después de validar).

---

## Implementation Strategy

### MVP First (User Story 1 Only)

1. Completar Foundational (T001-T003).
2. Completar User Story 1 (T004-T012) — "Ventas Creadas"/"Resultado" ya reflejan el neto.
3. **Parar y validar**: correr quickstart.md Escenarios 1-4 y confirmar contra el caso real del VPS
   (Escenario 5).
4. Deploy si se decide entregar el MVP antes que el resto.

### Incremental Delivery

1. Foundational → Base lista.
2. US1 → Validar → Deploy (MVP: KPIs principales ya corregidos, que es el caso que disparó la spec).
3. US2 → Validar → Deploy (simetría en Compras).
4. US3 → Validar → Deploy (gráfico y donas consistentes).
5. US4 → Validar → Deploy (filtro "Hoy", mejora de usabilidad independiente).
6. Polish → regresión completa y deploy final.

## Notes

- No hay tareas `[P]` de implementación entre sí dentro del controller (mismo archivo,
  `DashboardController.php`) — el paralelismo real está entre historias distintas (si hay más de un
  desarrollador) o entre archivos de test.
- Commitear después de cada Checkpoint (fin de fase), no tarea por tarea, para mantener el archivo
  del controller en un estado consistente entre commits.
