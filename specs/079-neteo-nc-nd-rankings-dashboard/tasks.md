---

description: "Task list for feature implementation"
---

# Tasks: Neteo de NC/ND en Rankings del Dashboard

**Input**: Design documents from `/specs/079-neteo-nc-nd-rankings-dashboard/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, quickstart.md

**Tests**: Incluidos — Principio IV de la constitución exige test para todo cálculo de importes
(Ranking de Clientes es dinero) y esta feature comparte lógica de imputación de período con el
Ranking de Productos, así que se testean ambos.

**Organization**: Tasks agrupadas por user story (spec.md): US1 = Ranking de Clientes (P1), US2 =
Ranking de Productos (P2), US3 = neteo cruzando períodos sin piso (P3, cubre ambos rankings).

## Format: `[ID] [P?] [Story] Description`

## Path Conventions

Proyecto Laravel single-app: `app/Http/Controllers/DashboardController.php`, `tests/Feature/`.

---

## Phase 1: Setup

No aplica — no hay dependencias nuevas, ni configuración de proyecto: se extiende un controlador
existente en un proyecto Laravel ya corriendo.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Confirmar los datos de prueba mínimos que todas las user stories necesitan antes de
poder implementarse y testearse.

- [ ] T001 Verificar/crear en el seeder o fixture de test los datos mínimos: un Cliente con una
  Venta y una NC/ND en el mismo período, y otro caso con NC/ND en período distinto al de su venta
  de origen (usar `database/factories/VentaFactory.php`, `NotaCreditoDebitoFactory.php` si existen;
  crearlas si no existen, en `database/factories/`)

**Checkpoint**: Datos de prueba disponibles — se puede empezar con US1.

---

## Phase 3: User Story 1 - Ranking de Clientes neteado (Priority: P1) 🎯 MVP

**Goal**: El Ranking de Clientes del Dashboard muestra el monto vendido ya neteado de NC/ND, sin
piso en $0, imputando cada nota al período de la venta que ajusta.

**Independent Test**: Cliente con venta de $10.000 y NC de $3.000 sobre esa venta en el mismo
período → el ranking muestra $7.000 para ese cliente.

### Tests for User Story 1

- [ ] T002 [P] [US1] Test: NC en el mismo período resta del monto del cliente, sin piso incluso si
  la NC supera el total (caso -$2.000), en `tests/Feature/DashboardRankingsNeteoTest.php` (método
  `test_ranking_clientes_neta_nc_mismo_periodo_sin_piso`)
- [ ] T003 [P] [US1] Test: ND en el mismo período suma al monto del cliente sin techo, en
  `tests/Feature/DashboardRankingsNeteoTest.php` (método `test_ranking_clientes_neta_nd_sin_techo`)
- [ ] T004 [P] [US1] Test: cliente cuyo neto da exactamente $0 sigue apareciendo en el ranking (no
  se excluye de la lista), en `tests/Feature/DashboardRankingsNeteoTest.php` (método
  `test_ranking_clientes_incluye_cliente_con_neto_cero`)
- [ ] T005 [P] [US1] Test: el total sumado de todos los clientes del ranking (sin cortar a Top 10)
  concilia centavo a centavo con `montoNetoQuery(Venta::class, ...)` para el mismo rango (SC-001),
  en `tests/Feature/DashboardRankingsNeteoTest.php` (método
  `test_ranking_clientes_concilia_con_kpi_total_ventas`)

### Implementation for User Story 1

- [ ] T006 [US1] Agregar método privado `montoNetoPorClienteQuery(Carbon $desde, Carbon $hasta): array`
  en `app/Http/Controllers/DashboardController.php`, replicando los dos componentes de
  `montoNetoQuery()` (líneas 398-430) pero agrupado por `cliente_id` de la Venta de origen —
  Componente 1: `ventas.total ± notas del propio rango` agrupado por `ventas.cliente_id`;
  Componente 2: notas del rango cuya venta base quedó fuera del rango, agrupadas por
  `ventas.cliente_id` vía join. Devuelve `[cliente_id => monto_neto]` combinando ambos componentes.
  Sin piso, sin techo (data-model.md).
- [ ] T007 [US1] Reemplazar en el método `rankings()` (líneas 245-259 de
  `app/Http/Controllers/DashboardController.php`) el cálculo bruto de Ranking de Clientes por el
  resultado de `montoNetoPorClienteQuery()`, ordenando descendente y aplicando `TOP_N_RANKING = 10`
  **después** de netear (research.md Decisión 4), preservando la forma de respuesta actual
  (`[{cliente_id, nombre, monto}]`)

**Checkpoint**: Ranking de Clientes neteado, testeado y funcional de forma independiente.

---

## Phase 4: User Story 2 - Ranking de Productos neteado (Priority: P2)

**Goal**: El Ranking de Productos del Dashboard muestra la cantidad vendida ya neteada de NC/ND a
nivel de línea de producto.

**Independent Test**: Producto con 20 unidades vendidas y NC que ajusta 5 unidades de esa venta en
el mismo período → el ranking muestra 15 unidades para ese producto.

### Tests for User Story 2

- [ ] T008 [P] [US2] Test: NC en el mismo período resta cantidad del producto, incluyendo el caso
  de ajuste mayor a lo vendido (neto negativo, sin piso), en
  `tests/Feature/DashboardRankingsNeteoTest.php` (método
  `test_ranking_productos_neta_nc_mismo_periodo_sin_piso`)
- [ ] T009 [P] [US2] Test: NC sin ítems desglosados (`nota_credito_debito_items` vacío) no afecta el
  Ranking de Productos aunque sí afecte el Ranking de Clientes, en
  `tests/Feature/DashboardRankingsNeteoTest.php` (método
  `test_ranking_productos_ignora_nota_sin_items_pero_clientes_si_la_computa`)

### Implementation for User Story 2

- [ ] T010 [US2] Agregar método privado `cantidadNetaPorProductoQuery(Carbon $desde, Carbon $hasta): array`
  en `app/Http/Controllers/DashboardController.php`, mismo patrón de 2 componentes que T006 pero
  operando sobre `venta_items.cantidad` (Componente 1) y `nota_credito_debito_items.cantidad` join
  con `notas_credito_debito.tipo`/`fecha_emision` (Componente 2), agrupado por
  `nota_credito_debito_items.producto_id` — filtrando ítems con `producto_id` nulo (data-model.md
  "Sin cambios de esquema" + research.md Decisión 3)
- [ ] T011 [US2] Reemplazar en el método `rankings()` (líneas 261-278 de
  `app/Http/Controllers/DashboardController.php`) el cálculo bruto de Ranking de Productos por el
  resultado de `cantidadNetaPorProductoQuery()`, ordenando descendente y aplicando
  `TOP_N_RANKING = 10` después de netear, preservando la forma de respuesta actual
  (`[{producto_id, nombre, cantidad}]`)

**Checkpoint**: Ambos rankings (Clientes y Productos) neteados y funcionando independientemente.

---

## Phase 5: User Story 3 - Neteo cruza períodos sin piso (Priority: P3)

**Goal**: Confirmar que el Componente 2 (nota emitida en un período, venta de origen en otro) se
aplica correctamente en ambos rankings, sin piso, consistente con el resto del Dashboard.

**Independent Test**: Venta de julio ya neteada a $0 en julio por una NC, y una segunda NC sobre la
misma venta emitida en agosto → el ranking de agosto para ese cliente muestra el excedente negativo
de esa segunda NC, sin piso.

### Tests for User Story 3

- [ ] T012 [P] [US3] Test: NC emitida en un período distinto al de la venta de origen no afecta el
  ranking del período de la venta, pero sí afecta (sin piso) el ranking del período de su propia
  emisión, para Clientes, en `tests/Feature/DashboardRankingsNeteoTest.php` (método
  `test_ranking_clientes_nc_periodo_cruzado_sin_piso`)
- [ ] T013 [P] [US3] Mismo caso que T012 pero para Ranking de Productos, en
  `tests/Feature/DashboardRankingsNeteoTest.php` (método
  `test_ranking_productos_nc_periodo_cruzado_sin_piso`)

### Implementation for User Story 3

- [ ] T014 [US3] Verificar (sin cambio de código esperado si T006/T010 ya implementaron el
  Componente 2 correctamente) que ambos métodos nuevos resuelven el caso de período cruzado; si
  T012/T013 fallan, corregir el join/filtro de fecha en `montoNetoPorClienteQuery()` o
  `cantidadNetaPorProductoQuery()` en `app/Http/Controllers/DashboardController.php`

**Checkpoint**: Los 3 user stories completos y verificados de forma independiente.

---

## Phase 6: Polish & Cross-Cutting Concerns

**Purpose**: Dejar la documentación de dominio consistente con el nuevo comportamiento (Principio I
de la constitución) y correr la validación end-to-end.

- [ ] T015 [P] Corregir `docs/documentacion_principal_crm.md` §6.3 (línea ~2198-2202): reemplazar la
  descripción del criterio "con piso en $0" por el criterio vigente "sin piso" (igual al ya
  documentado en el comentario de `montoNetoQuery()`), y extender la mención a que el Ranking de
  Clientes/Productos del Dashboard ahora también aplica este neteo
- [ ] T016 [P] Actualizar `docs/documentacion_principal_crm.md` §7 (línea ~2851-2855): quitar el
  Ranking de Clientes/Productos del Dashboard de la lista de "pendientes sin netear" — la deuda
  quedó saldada por esta feature
- [ ] T017 Ejecutar manualmente los 6 escenarios de `quickstart.md` contra el Dashboard en
  navegador (datos de prueba locales), confirmando visualmente que Ranking de Clientes/Productos y
  KPIs concilian, y que Informes > Ranking no cambió
- [ ] T018 Correr la suite completa `php artisan test --filter=DashboardRankingsNeteoTest` y
  confirmar 0 fallos
- [ ] T019 [P] Test de regresión: comparar el resultado del Ranking de Clientes/Productos del
  módulo Informes (spec 069) antes y después de este cambio, para el mismo período y mismos datos
  de prueba — confirma SC-003, en `tests/Feature/DashboardRankingsNeteoTest.php` (método
  `test_ranking_informes_no_cambia_tras_neteo_dashboard`)
- [ ] T020 [P] Test de regresión: `montoNetoQuery()` (KPIs/Totales/Donas) devuelve exactamente el
  mismo resultado antes y después de agregar `montoNetoPorClienteQuery()` y
  `cantidadNetaPorProductoQuery()`, para el mismo período y mismos datos — confirma FR-008, en
  `tests/Feature/DashboardRankingsNeteoTest.php` (método
  `test_montonetoquery_kpis_sin_cambios_tras_agregar_rankings_neteados`)

---

## Dependencies & Execution Order

### Phase Dependencies

- **Foundational (Phase 2)**: bloquea todas las user stories (necesita los datos de prueba).
- **US1 (Phase 3)**: puede arrancar apenas Phase 2 termine. Es el MVP.
- **US2 (Phase 4)**: independiente de US1 (archivos/métodos distintos), puede ir en paralelo.
- **US3 (Phase 5)**: depende de que T006 y T010 ya existan (US1 y US2 implementados), porque valida
  el mismo código que esas fases escriben — no agrega código nuevo si el Componente 2 ya está bien.
- **Polish (Phase 6)**: depende de que US1, US2 y US3 estén completos.

### Parallel Opportunities

- T002-T005 (tests US1) en paralelo entre sí.
- T008-T009 (tests US2) en paralelo entre sí, y en paralelo con T002-T005 (archivos de test
  distintos métodos, mismo archivo — cuidado con conflictos de merge si se ejecutan a la vez con
  herramientas automáticas; en la práctica: mismo archivo `DashboardRankingsNeteoTest.php`, distinto
  método, sin dependencia de datos entre sí).
- T006 (US1) y T010 (US2) en paralelo — métodos privados distintos, sin dependencia entre sí.
- T015-T016 (docs) en paralelo.

---

## Implementation Strategy

### MVP First (User Story 1 Only)

1. Completar Phase 2 (Foundational).
2. Completar Phase 3 (US1 — Ranking de Clientes).
3. Parar y validar con T017 (sólo escenarios 1-3 y el de control de KPIs de quickstart.md) antes de
   seguir con Productos.

### Incremental Delivery

1. Foundational → Ranking de Clientes (MVP) → Ranking de Productos → validación de período cruzado
   → docs actualizadas → validación end-to-end completa (T017-T018).
