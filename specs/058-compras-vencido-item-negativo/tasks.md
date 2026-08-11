---

description: "Task list for Estado \"Vencido\" en Compras + ítems con cantidad negativa"
---

# Tasks: Estado "Vencido" en Compras + ítems con cantidad negativa

**Input**: Design documents from `/specs/058-compras-vencido-item-negativo/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, quickstart.md

**Tests**: Incluidos — Principio IV de la constitución exige tests donde hay dinero o stock, y este
feature toca ambos.

**Organization**: Tareas agrupadas por user story (US1-US2 de spec.md), en orden de prioridad.

## Format: `[ID] [P?] [Story] Description`

---

## Phase 1: Setup

Sin tareas — no hay migración ni dependencia nueva (ver plan.md, sin cambios de esquema).

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Extender `Compra::estadoPago()` — usado tanto por el badge (US1) como por la columna
`estado_pago` del DataTable.

**⚠️ CRITICAL**: Ninguna user story puede completarse antes de esta fase.

- [X] T001 Extender `app/Models/Compra.php`: `estadoPago()` gana la rama `'vencido'` cuando `fecha_vto_pago` está seteada, es anterior a hoy, y `aPagar() > 0.005` — evaluada DESPUÉS del caso "pagado" (`aPagar() <= 0`) y ANTES de "parcial"/"a_pagar" (research.md §1, data-model.md)

**Checkpoint**: Modelo listo — las user stories pueden empezar.

---

## Phase 3: User Story 1 - Ver de un vistazo qué compras están vencidas (Priority: P1)

**Goal**: El badge de fila y el filtro "Estado del Pago" del listado de Compras distinguen "Vencido".

**Independent Test**: Cargar una compra con `fecha_vto_pago` pasada y sin pagos; verificar que su
badge de fila muestra "Vencido" y que aparece al filtrar por esa opción.

### Tests for User Story 1 ⚠️

- [X] T002 [P] [US1] Test Feature: compra con vto. pasado y sin pagos muestra badge "Vencido" (vía `estado_pago` del JSON del DataTable), en `tests/Feature/CompraVencidoTest.php`
- [X] T003 [P] [US1] Test Feature: compra con vto. pasado pero 100% pagada muestra "Pagado", no "Vencido" (FR-004), en `tests/Feature/CompraVencidoTest.php`
- [X] T004 [P] [US1] Test Feature: compra con vto. pasado y pago parcial muestra "Vencido", no "Parcial" (Edge Case), en `tests/Feature/CompraVencidoTest.php`
- [X] T005 [P] [US1] Test Feature: compra sin `fecha_vto_pago` nunca es "Vencido" aunque tenga saldo (FR-005), en `tests/Feature/CompraVencidoTest.php`
- [X] T006 [P] [US1] Test Feature: `GET /compras/data` con `estado_pago=vencido` devuelve sólo las compras del criterio de T002 (el filtro backend ya existe desde spec 056 — este test confirma que sigue alineado con `estadoPago()`), en `tests/Feature/CompraVencidoTest.php`

### Implementation for User Story 1

- [X] T007 [US1] Agregar la rama `'vencido' => 'danger'` / `'Vencido'` al `match ($compra->estadoPago())` en `resources/views/compras/_row_actions.blade.php` (research.md §2)
- [X] T008 [US1] Agregar `<option value="vencido">Vencido</option>` al `<select id="filtro-estado-pago">` en `resources/views/compras/index.blade.php`

**Checkpoint**: User Story 1 completa y testeable de forma independiente.

---

## Phase 4: User Story 2 - Cargar una bonificación del proveedor como ítem de cantidad negativa (Priority: P1)

**Goal**: El formulario de Nueva/Editar Compra acepta ítems con cantidad negativa (precio sigue
positivo), calculando subtotales y stock con el signo correcto.

**Independent Test**: Crear una compra con un ítem positivo y uno negativo del mismo producto;
verificar que el subtotal del renglón negativo es negativo y que el stock resultante es la suma neta
exacta.

### Tests for User Story 2 ⚠️

- [X] T009 [P] [US2] Test Feature: crear una Compra con un ítem de cantidad `-2` y precio positivo es aceptado (201), en `tests/Feature/CompraItemNegativoTest.php`
- [X] T010 [P] [US2] Test Feature: crear una Compra con un ítem de cantidad `0` sigue rechazado (422) (no se rompió la regla existente), en `tests/Feature/CompraItemNegativoTest.php`
- [X] T011 [P] [US2] Test Feature: crear una Compra con un ítem de precio unitario negativo es rechazado (422) (FR-006), en `tests/Feature/CompraItemNegativoTest.php`
- [X] T012 [P] [US2] Test Feature: una Compra con ítems `+3` y `-1` del mismo producto (que controla stock) deja el stock del depósito en neto `+2`, no `+4` (research.md §5 — el bug de signo que este feature corrige), en `tests/Feature/CompraItemNegativoTest.php`
- [X] T013 [P] [US2] Test Feature: editar una Compra existente para cambiar la cantidad de un ítem de positiva a negativa recalcula el stock correctamente (reintegra lo anterior, aplica lo nuevo con el signo correcto), en `tests/Feature/CompraItemNegativoTest.php`

### Implementation for User Story 2

- [X] T014 [P] [US2] Cambiar `items.*.cantidad` de `'required|numeric|gt:0'` a `'required|numeric|not_in:0'` en `app/Http/Requests/StoreCompraRequest.php` (research.md §3)
- [X] T015 [P] [US2] Idem T014 en `app/Http/Requests/UpdateCompraRequest.php`
- [X] T016 [US2] Corregir `App\Services\Egresos\StockDeCompra::aplicarAlta()`: por cada ítem que mueve stock, llamar `registrarEntrada()` si `cantidad > 0` o `registrarSalida(abs(cantidad))` si `cantidad < 0` (research.md §5) — hoy siempre llama `registrarEntrada`, perdiendo el signo
- [X] T017 [US2] Idem T016 en `reintegrarPorEliminacion()` (dirección invertida: `registrarSalida()` para ítems que fueron positivos, `registrarEntrada(abs(cantidad))` para los que fueron negativos)
- [X] T018 [US2] Confirmar que `reaplicarPorEdicion()` (que reusa `reintegrarPorEliminacion`-style + `aplicarAlta()`) queda corregido transitivamente por T016/T017 sin necesitar cambios propios — si no es así, aplicar el mismo criterio ahí

**Checkpoint**: User Stories 1 y 2 completas — cubren el feedback completo del cliente.

---

## Phase 5: Polish & Cross-Cutting Concerns

- [X] T019 [P] Actualizar `docs/documentacion_principal_crm.md` §4.1 (Compras): nota de que el campo Cantidad admite negativo (confirmado con captura real del cliente, 11/08/2026) y que "Vencido" es un estado más del badge/filtro (Principio I de la constitución) — hecho antes de `/speckit-tasks` per regla del proyecto
- [X] T020 [P] Correr `php artisan test --filter=CompraVencido` y `php artisan test --filter=CompraItemNegativo` y confirmar que ambas suites (T002-T006, T009-T013) pasan en verde
- [ ] T021 Ejecutar manualmente los 4 escenarios de `specs/058-compras-vencido-item-negativo/quickstart.md` en el navegador antes de dar la feature por terminada — regla del proyecto: probar en el browser real, no sólo tests

---

## Dependencies & Execution Order

### Phase Dependencies

- **Foundational (Phase 2)**: sin dependencias — arranca primero, bloquea US1 (US2 no depende de `estadoPago()`, podría ir en paralelo, pero conviene hacer Foundational primero por ser trivial)
- **User Story 1 (Phase 3)**: depende de Foundational (T001)
- **User Story 2 (Phase 4)**: independiente de US1 — puede implementarse en paralelo (archivos totalmente distintos: `StockDeCompra.php`, `Store/UpdateCompraRequest.php` vs `Compra.php`, vistas de Compras)
- **Polish (Phase 5)**: depende de US1 y US2

### Parallel Opportunities

- T002-T006 (tests US1) son [P] entre sí
- T009-T013 (tests US2) son [P] entre sí
- T014-T015 (FormRequests US2) son [P] entre sí
- US1 completa (Phase 3) y US2 completa (Phase 4) pueden implementarse en paralelo por dos personas/sesiones distintas — no comparten ningún archivo

---

## Implementation Strategy

### MVP First

Ambas user stories son P1 y de tamaño similar (mitad del feedback del cliente cada una) — no hay una
más "MVP" que la otra. Se recomienda completarlas juntas dado que ninguna es grande por sí sola.

### Incremental Delivery

1. Foundational (T001) → base lista
2. US1 (Vencido) → validar Escenario 1-2 de quickstart.md
3. US2 (cantidad negativa) → validar Escenario 3-4 de quickstart.md
4. Polish → docs + tests + validación manual completa

---

## Notes

- Los tests son obligatorios (Principio IV de la constitución).
- T016/T017 son la única tarea no trivial del feature (research.md §5) — el resto es exponer en UI o
  relajar una regla de validación ya existente.
- Commitear después de cada tarea o grupo lógico; parar en cualquier checkpoint para validar antes de
  seguir.
