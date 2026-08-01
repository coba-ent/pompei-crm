---

description: "Task list for feature 031: Saldo Inicial en Cuenta Corriente"
---

# Tasks: Saldo Inicial en Cuenta Corriente

**Input**: Design documents from `/specs/031-saldo-inicial-cuenta-corriente/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/endpoints.md, quickstart.md

**Tests**: Constitución IV exige tests para cuenta corriente/saldos — se incluyen como parte de Foundational y de cada historia (no son opcionales en esta feature).

**Organization**: Tareas agrupadas por historia de usuario.

## Format: `[ID] [P?] [Story] Description`

---

## Phase 1: Foundational (Blocking Prerequisites)

**Purpose**: Extender `CuentaCorriente::aging()`/`porCliente()` para sumar el saldo inicial (Cliente y
Proveedor a la vez, mismo método parametrizado por `$tipo`) es la base que consumen tanto el Dashboard
como "Saldos Clientes" — ninguna historia puede validarse sin esto.

**⚠️ CRITICAL**: No se puede implementar ninguna historia sin esta fase completa

- [x] T001 En `app/Services/Tesoreria/CuentaCorriente.php`, extraer la clasificación por bucket de antigüedad (a_vencer/vencido 0-30/31-60/61-90/+90 según una fecha de referencia) a un método privado reutilizable (research.md R1), sin cambiar el comportamiento actual de `aging()`/`porCliente()` para Venta/Compra (refactor puro, cubierto por los tests ya existentes de spec 029/010 en verde).
- [x] T002 En `CuentaCorriente::aging()`, agregar el recorrido de `Cliente::where('saldo_inicial', '!=', 0)->get(['saldo_inicial', 'saldo_inicial_fecha'])` (tipo cliente) / `Proveedor::where('saldo_inicial', '!=', 0)->get([...])` (tipo proveedor), sumando cada saldo inicial al bucket que le corresponda usando `saldo_inicial_fecha` como fecha de referencia (research.md R1/R5) — a diferencia del recorrido de documentos, NO se descarta por `saldo <= TOLERANCIA`, sólo si `abs(saldo_inicial) <= TOLERANCIA` (research.md R2, FR-005).
- [x] T003 En `CuentaCorriente::porCliente()`, mismo cambio que T002 pero acumulando por `cliente_id`/`proveedor_id`, incluyendo la inicialización perezosa de la fila en `$acumulado` si el Cliente/Proveedor todavía no tiene ninguna entrada (research.md R3 — cubre el caso de un Cliente con saldo inicial y cero Ventas).
- [x] T004 [P] Test unitario en `tests/Unit/CuentaCorrienteSaldoInicialTest.php`: un Cliente con `saldo_inicial = 50000` y `saldo_inicial_fecha` hace 45 días, sin ninguna Venta → `porCliente('cliente')` lo devuelve con $50.000 en `vencido_31_60` y en `total` (User Story 1, Acceptance Scenario 1).
- [x] T005 [P] Test unitario: mismo Cliente de T004 con una Venta adicional `a_cobrar = 10000` a vencer → `total = 60000`, con `vencido_31_60 = 50000` y `a_vencer = 10000` (User Story 1, Acceptance Scenario 2).
- [x] T006 [P] Test unitario: un Cliente con `saldo_inicial ≠ 0` y `saldo_inicial_fecha = null` → el monto cae en `a_vencer` (FR-004).
- [x] T007 [P] Test unitario: un Cliente con `saldo_inicial = 0` (o null) y una Venta normal → `porCliente()`/`aging()` devuelven exactamente el mismo resultado que antes de esta feature (SC-004, cero regresión).
- [x] T008 [P] Test unitario: un Proveedor con `saldo_inicial ≠ 0` y sin Compras → `porCliente('proveedor')`/`aging('proveedor')` lo reflejan igual que T004/T005 del lado de Clientes (FR-002).
- [x] T009 [P] Test unitario: un Cliente con `saldo_inicial = -5000` (saldo a favor) y sin Ventas → `total = -5000`, sigue apareciendo en el resultado de `porCliente()` (no se excluye por ser negativo, sólo por ser ≈0) (User Story 3, Acceptance Scenario 1).
- [x] T010 [P] Test unitario: ese mismo Cliente con una Venta adicional `a_cobrar = 8000` en el mismo bucket → el total del bucket es `3000` (8000 − 5000) (User Story 3, Acceptance Scenario 2).
- [x] T011 [P] Test unitario: un Cliente cuyo saldo inicial compensa exactamente el resto de su deuda (total ≈ 0) no aparece en el resultado de `porCliente()` (Edge Case, misma regla de exclusión de spec 029 FR-002).

**Checkpoint**: cálculo de aging con saldo inicial listo y testeado (Clientes y Proveedores, positivo y negativo) — las historias de UI pueden validarse.

---

## Phase 2: User Story 1 - El saldo inicial de un cliente se refleja en su deuda (Priority: P1) 🎯 MVP

**Goal**: "Saldos Clientes" (spec 029) y el Dashboard (spec 010) reflejan el saldo inicial sin cambios de código propios — sólo consumiendo el `CuentaCorriente` ya extendido en Phase 1.

**Independent Test**: Ver `quickstart.md` Escenario 1 y 2.

### Implementation for User Story 1

- [x] T012 [US1] Test de integración en `tests/Feature/CuentaCorrienteSaldoInicialEndpointTest.php`: request a `informes.cuenta-corriente.saldos.data` con un Cliente con saldo inicial (sin Ventas) → la fila aparece con el monto y bucket correctos (mismo caso de T004 pero a través del endpoint HTTP, cubre que `saldosData()` no filtra ni transforma el resultado de `porCliente()`).
- [x] T013 [US1] Test de integración: comparar el Total General de `informes.cuenta-corriente.saldos.data` contra `CuentaCorriente::aging('cliente')['total']` (el mismo agregado que consume el Dashboard) para un set de Clientes con y sin saldo inicial → deben coincidir exacto (SC-002, mismo invariante de spec 029 SC-003 verificado de nuevo tras el cambio).

**Checkpoint**: User Story 1 funcional — validar con `quickstart.md` Escenario 1 y 2. Ya es demostrable (MVP): un Cliente migrado con saldo inicial deja de mostrar "$0 de deuda" cuando en realidad debe plata.

---

## Phase 3: User Story 2 - El saldo inicial aparece en el detalle de Movimientos (Priority: P2)

**Goal**: Tab "Movimientos" (spec 029) muestra una fila sintética "Saldo Inicial" por cada Cliente con `saldo_inicial ≠ 0`, filtrable por Operación, sosteniendo el invariante contra "Saldos Clientes".

**Independent Test**: Ver `quickstart.md` Escenario 3.

### Implementation for User Story 2

- [x] T014 [US2] En `app/Http/Controllers/Informes/CuentaCorrienteController.php`, agregar el 4to `SELECT` al UNION de `queryMovimientos()`: `clientes` filtrado por `saldo_inicial != 0`, proyectando `operacion = 'saldo_inicial'` y el resto de columnas según data-model.md (research.md R4).
- [x] T015 [US2] En el mismo controller, agregar `'saldo_inicial'` a `OPERACIONES_DISPONIBLES` para que el filtro `operacion=saldo_inicial` funcione en `aplicarFiltrosMovimientos()`.
- [x] T016 [US2] En `resources/views/informes/cuenta-corriente/index.blade.php`, agregar la opción `<option value="saldo_inicial">Saldo Inicial</option>` al `<select id="filtro-movimientos-operacion">`.
- [x] T017 [US2] En `resources/js/informe-cuenta-corriente.js`, agregar `'saldo_inicial': 'Saldo Inicial'` a `ETIQUETAS_OPERACION` para que la columna Operación renderice el label legible.
- [x] T018 [US2] Test de integración en `tests/Feature/CuentaCorrienteMovimientosSaldoInicialTest.php`: un Cliente con saldo inicial + una Venta → `movimientos.data` devuelve 2 filas (`venta`, `saldo_inicial`), la fila `saldo_inicial` trae `fecha_emision = saldo_inicial_fecha`, `a_cobrar = saldo_inicial`, y el resto de columnas `null` (User Story 2, Acceptance Scenario 1).
- [x] T019 [US2] Test de integración: para ese mismo Cliente, la suma de `a_cobrar` de todas sus filas en `movimientos.data` (venta + saldo_inicial) coincide exacto con el `total` de ese Cliente en `saldos.data` (SC-003, FR-009).
- [x] T020 [US2] Test de integración: un Cliente sin saldo inicial no genera ninguna fila `operacion=saldo_inicial` en `movimientos.data` (User Story 2, Acceptance Scenario 3).
- [x] T021 [US2] Test de integración: filtrar `movimientos.data` por `operacion=saldo_inicial` (sin filtrar por cliente) sólo devuelve filas de ese tipo, de todos los Clientes que tengan saldo inicial cargado (User Story 2, Acceptance Scenario 4).

**Checkpoint**: User Stories 1 y 2 funcionan de punta a punta — validar con `quickstart.md` Escenario 1, 2 y 3.

---

## Phase 4: User Story 3 - Un saldo inicial negativo se trata como saldo a favor (Priority: P3)

**Goal**: Confirmar de punta a punta (UI + endpoints) que un saldo inicial negativo resta del total y no se excluye del listado — el cálculo ya lo soporta desde Phase 1 (T009/T010), esta fase valida que no se pierde ese signo en ningún punto intermedio (endpoint, DataTable, formateo).

**Independent Test**: Ver `quickstart.md` Escenario 4.

### Implementation for User Story 3

- [x] T022 [US3] Test de integración en `tests/Feature/CuentaCorrienteSaldoInicialEndpointTest.php` (mismo archivo de T012): un Cliente con `saldo_inicial = -5000` y sin Ventas → `saldos.data` lo devuelve con `total = -5000`, no queda excluido de la respuesta.
- [ ] T023 [US3] Verificar manualmente en el navegador (`quickstart.md` Escenario 4) que el formateo de moneda (`Intl.NumberFormat` en `informe-cuenta-corriente.js`) muestra el signo negativo de forma legible en la columna Total de "Saldos Clientes" — sin cambios de código si ya funciona (el formateador ya maneja negativos por defecto), dejar registrado el resultado.

**Checkpoint**: Las 3 historias funcionan de punta a punta — validar con `quickstart.md` completo (Escenarios 1 a 5).

---

## Phase 5: Polish & Cross-Cutting Concerns

- [x] T024 [P] Actualizar `docs/modelo_datos.md`: la nota de `saldo_inicial_fecha` ("sin uso funcional en el aging") pasa a reflejar que ya se usa (spec 031), tanto para Cliente como para Proveedor.
- [x] T025 [P] Actualizar `docs/documentacion_principal_crm.md` §6.3 (Dashboard) y §6.4 (Informe de Cuenta Corriente Clientes, spec 029) para reflejar que el aging ahora incluye el saldo inicial de Cliente/Proveedor, con la fila sintética "Saldo Inicial" en Movimientos.
- [ ] T026 Ejecutar manualmente los 5 escenarios de `specs/031-saldo-inicial-cuenta-corriente/quickstart.md` y dejar registrado el resultado — en particular que el Total de "Saldos Clientes" sigue coincidiendo exacto con el Dashboard (SC-002) y que no hay regresión para Clientes sin saldo inicial (SC-004).
- [x] T027 Reejecutar toda la suite de tests de Cuenta Corriente ya existente (spec 029: `CuentaCorrientePorClienteTest`, `CuentaCorrienteSaldosClientesTest`, `CuentaCorrienteMovimientosQueryTest`, `CuentaCorrienteMovimientosClienteTest`, `CuentaCorrienteMovimientosFiltrosTest`, `DashboardCuentaCorrienteTest`, `CuentaCorrienteDeepLinkClienteTest`) para confirmar cero regresión (SC-004) antes de dar la feature por cerrada.

---

## Dependencies & Execution Order

- **Foundational (Phase 1)**: sin dependencias — bloquea todas las historias.
- **User Story 1 (Phase 2)**: depende de Phase 1 (T001-T003). Es el MVP — demostrable con sólo esta fase.
- **User Story 2 (Phase 3)**: depende de Phase 1 (T001-T003, el cálculo de `porCliente('cliente')` que sostiene el invariante FR-009). Independiente de US1 en cuanto a código (toca un archivo distinto, `queryMovimientos()`), pero su test de invariante (T019) necesita que US1 ya esté funcionando.
- **User Story 3 (Phase 4)**: depende de Phase 1 (T009/T010 ya prueban el caso negativo a nivel de servicio) y de Phase 2 (T012, el endpoint `saldos.data` que T022 reusa). No agrega código nuevo, sólo tests/verificación end-to-end.
- **Polish (Phase 5)**: depende de las 3 historias completas.

### Parallel Opportunities

- T004-T011 (tests unitarios de Foundational) son paralelos entre sí una vez T001-T003 están implementados.
- T014-T017 (implementación US2) son secuenciales entre sí (mismo archivo de controller/vista/JS en partes distintas, bajo riesgo de conflicto pero no verdaderamente paralelos); T018-T021 (tests US2) son paralelos entre sí una vez T014-T017 completos.
- T024/T025 (Polish, docs) son paralelos entre sí y con T026/T027.

## Implementation Strategy

### MVP First (User Story 1 Only)

1. Foundational (Phase 1) — el cálculo ya soporta Cliente, Proveedor, positivo y negativo desde acá.
2. User Story 1 (Phase 2) → validar con quickstart Escenario 1 y 2 → ya es demostrable y resuelve el caso de uso central (un Cliente migrado con saldo inicial deja de verse con "$0 de deuda").
3. User Story 2 (Phase 3) → validar con quickstart Escenario 3 → cierra el invariante de detalle accionable en Movimientos.
4. User Story 3 (Phase 4) → validar con quickstart Escenario 4 → confirma de punta a punta el caso de saldo a favor (el cálculo ya lo soportaba desde Phase 1).
5. Polish (Phase 5) → documentación y regresión completa antes de cerrar.
