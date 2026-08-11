# Tasks: Otros Ingresos en el informe de flujo de caja de Tesorería

**Input**: [plan.md](plan.md), [spec.md](spec.md)
**Tests**: incluidos — el fix corrige un cálculo de dinero en un informe ya en uso, se cubre con Feature tests.

**Organization**: agrupadas por user story del spec, cada una independientemente entregable/verificable.

## Phase 1: Setup

- [X] T001 Confirmar en un entorno local con datos reales (o un dump reciente) el estado actual: `otros_ingresos` count = 0, `movimientos_tesoreria` con `tipo='ingreso'` count = 61, suma = $34.570.442,27 — baseline para T009.

## Phase 2: User Story 2 — Alta de Otro Ingreso genera tipo `ingreso` (Priority: P1)

**Goal**: Un Otro Ingreso nuevo no pendiente genera su movimiento con `tipo = 'ingreso'`.

**Independent Test**: Crear un Otro Ingreso no pendiente con cuenta asignada y verificar `tipo` del `MovimientoTesoreria` generado.

- [X] T002 [US2] En `app/Services/Ingresos/Cobranzas.php`, método `registrarOtroIngreso()`: cambiar el tercer argumento de `$this->tesoreria->registrarMovimiento(...)` de `'cobro'` a `'ingreso'`.
- [X] T003 [US2] Test Feature (`tests/Feature/Ingresos/OtroIngresoTesoreriaTest.php` o el archivo de test existente de Otros Ingresos que corresponda): al crear un Otro Ingreso no pendiente, assert que `movimientoTesoreria->tipo === 'ingreso'`.
- [X] T004 [US2] Test Feature: al conciliar un Otro Ingreso pendiente (destildar "pendiente" + asignar cuenta en un update), assert que el movimiento generado también tiene `tipo === 'ingreso'`.
- [X] T005 [US2] Test Feature: correr (o confirmar que siguen pasando) los tests ya existentes de alta/pendiente/edición/eliminación de Otro Ingreso — no deben romperse por este cambio (sólo cambia el valor de `tipo`, no la lógica de cuándo se genera).

**Checkpoint**: un Otro Ingreso nuevo ya se tipifica igual que el histórico.

## Phase 3: User Story 1 — El informe de flujo de caja incluye `ingreso` en Cobros (Priority: P1)

**Goal**: `Tesoreria::flujo()` suma los movimientos `ingreso` dentro de "Cobros", en total y desglose por cuenta.

**Independent Test**: Con movimientos de prueba tipo `ingreso` en un rango de fechas, llamar `Tesoreria::flujo($desde, $hasta)` y verificar que "total_cobros" y "cobros" (desglose) los incluyen.

- [X] T006 [US1] En `app/Services/Tesoreria/Tesoreria.php`, método `flujo()`: cambiar `$desglose(['cobro'], absoluto: false)` a `$desglose(['cobro', 'ingreso'], absoluto: false)`.
- [X] T007 [US1] [P] Test Feature (`tests/Feature/Tesoreria/FlujoCajaTest.php` o el existente que corresponda): crear una cuenta con un movimiento `tipo='ingreso'` fechado dentro del rango consultado; assert que `total_cobros` lo incluye y que aparece en el desglose por cuenta de `cobros`.
- [X] T008 [US1] [P] Test Feature: movimiento `tipo='ingreso'` fechado FUERA del rango consultado no debe sumar (el filtro de fecha sigue aplicando).
- [X] T009 [US1] Verificación manual contra datos reales (o dump): consultar el informe de Movimientos en un rango que incluya los 61 históricos y confirmar que "Total Cobros" sube en $34.570.442,27 respecto del baseline de T001.
- [X] T010 [US1] Verificar que el export CSV (`TesoreriaController::movimientosExport`) y el PDF (`tesoreria/pdf/movimientos.blade.php`) reflejan el mismo total — ambos consumen `flujo()`, así que deberían heredar el fix sin tocarlos; confirmar leyendo esos dos archivos que no dupliquen la query de `flujo()` con su propio `whereIn` desactualizado.

**Checkpoint**: el informe de flujo de caja ya no subestima Otros Ingresos, ni los históricos ni los nuevos.

## Phase 4: Polish & Cross-Cutting

- [ ] T011 [P] BLOQUEADO (infra preexistente, no causado por este fix): `php artisan test` falla en este entorno con SQLite in-memory por un `ALTER TABLE ... MODIFY` (sintaxis MySQL-only) en una migración ajena a este feature — falla igual en tests ya existentes sin tocar (ej. `VentaDepositoTest`). Se verificó la lógica en su lugar corriendo `Tesoreria::flujo()` y `Cobranzas::registrarOtroIngreso()` contra la base real dentro de una transacción con rollback (ver T009); sin regresión visible en Saldos/ledger porque ese código no fue tocado.
- [X] T012 Revisar el checklist [implementation.md](checklists/implementation.md) ítem por ítem y tildar lo verificado.

## Dependencies & Execution Order

- T001 (baseline) no bloquea T002-T008, pero debe correr antes de T009 para tener el número de comparación.
- Phase 2 (US2) y Phase 3 (US1) son independientes entre sí — pueden implementarse y testearse en cualquier orden o en paralelo; ambas son P1 porque juntas cierran el bug (una sin la otra deja el informe corregido a medias: sólo históricos, o sólo altas nuevas).
- T007 y T008 son paralelizables entre sí ([P], mismo archivo de test pero casos independientes — verificar si el runner de test permite paralelismo real o son simplemente independientes de escribir).
- T010 y T011 dependen de T002-T008 completos.
- T012 es el cierre, depende de todo lo anterior.

## Implementation Strategy

MVP = Phase 2 + Phase 3 (T002-T009): con eso el bug está resuelto tanto para el histórico como para las altas nuevas. Phase 4 es verificación de no-regresión antes de dar por cerrado el fix.
