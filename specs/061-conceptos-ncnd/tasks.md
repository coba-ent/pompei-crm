---
description: "Task list for Percepciones/Impuestos Internos/Intereses funcionales en NC/ND"
---

# Tasks: Percepciones/Impuestos Internos/Intereses funcionales en NC/ND

**Input**: Design documents from `/specs/061-conceptos-ncnd/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/payload-conceptos-ncnd.md, quickstart.md

**Tests**: Incluidos — Principio IV (campo monetario).

**Organization**: Una sola user story (P1) — feature acotada.

---

## Phase 1: Setup

Sin tareas — sin dependencias nuevas, sin migraciones.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Validación backend y persistencia — prerequisito de la UI.

- [X] T001 [P] Agregar validación `conceptos`/`conceptos.*.tipo`/`conceptos.*.concepto`/`conceptos.*.monto` a `app/Http/Requests/StoreNotaCreditoDebitoRequest.php` (mismo patrón que `StoreVentaRequest.php`, contracts/payload-conceptos-ncnd.md)
- [X] T002 [P] Idem en `app/Http/Requests/UpdateNotaCreditoDebitoRequest.php`
- [X] T003 En `app/Http/Controllers/NotaCreditoDebitoController.php`: agregar `'impuestos' => $datos['conceptos'] ?? []` al `create()` de `store()` y `storeCompra()`
- [X] T004 En `NotaCreditoDebitoController::aplicarEdicion()`: agregar `'impuestos' => $datos['conceptos'] ?? []` al `$nota->update([...])`

**Checkpoint**: Backend listo para recibir y persistir `conceptos` — la UI puede empezar.

---

## Phase 3: User Story 1 - Agregar Percepciones/Impuestos Internos/Intereses a una NC/ND (Priority: P1)

**Goal**: Los 3 bloques colapsados de la página completa de NC/ND agregan filas reales de concepto, suman al Total, y persisten.

**Independent Test**: quickstart.md Escenarios 1-6.

### Tests for User Story 1 ⚠️

- [X] T005 [P] [US1] Test Feature: crear NC/ND con `conceptos` (percepción + interés) persiste `impuestos` y el `monto` guardado incluye la suma de conceptos, en `tests/Feature/NotaCreditoDebitoConceptosTest.php`
- [X] T006 [P] [US1] Test Feature: editar una NC/ND agregando/quitando conceptos actualiza `impuestos` correctamente, en el mismo archivo
- [X] T007 [P] [US1] Test Feature: fila de concepto sin `concepto` no se persiste (backend rechaza si falta, ver FR-008 vía validación `required_with`), en el mismo archivo
- [X] T008 [P] [US1] Test Feature: eliminar una nota con conceptos cargados no deja registros huérfanos (soft delete ya cubre esto por ser json embebido — test de regresión), en el mismo archivo

### Implementation for User Story 1

- [X] T009 [US1] En `resources/views/notas-credito-debito/form.blade.php`: reemplazar los 3 enlaces `js-concepto-noop` por `js-add-concepto` con `data-tipo="percepcion"|"impuesto_interno"|"interes"` (igual `compras/form.blade.php`), agregar contenedor `<div id="conceptos-body">` antes de la tabla de totales
- [X] T010 [US1] En el mismo Blade: agregar `conceptos: @json($notaCreditoDebito->impuestos ?? [])` a `window.NotaFormData`
- [X] T011 [US1] En `resources/js/notas-credito-debito.js`: agregar el array `PERCEPCIONES` (27 entradas, copiado de `ventas.js`) y `let conceptos = Array.isArray(data.conceptos) && data.conceptos.length ? data.conceptos.slice() : [];`
- [X] T012 [US1] En el mismo archivo: agregar `renderConceptos()` (mismo patrón que `ventas.js`: selector para `percepcion`, input libre para `impuesto_interno`/`interes`, input Monto, botón eliminar) y el handler `$('.js-add-concepto').on('click', ...)`
- [X] T013 [US1] En el mismo archivo: sumar `conceptos.reduce((acc, c) => acc + (Number(c.monto) || 0), 0)` en `recalcular()` y `totalActual()`, y agregar `conceptos: conceptos.filter((c) => c.concepto)` al `payload()` de guardado

**Checkpoint**: Feature completa y testeable de forma independiente.

---

## Phase 4: Polish & Cross-Cutting Concerns

- [X] T014 [P] Actualizar `docs/documentacion_principal_crm.md` y `docs/modelo_datos.md` — ya hecho antes de `/speckit-tasks`
- [X] T015 [P] Correr `php artisan test --filter=NotaCreditoDebito` completo (spec 057/059/060/061) y confirmar 100% verde
- [X] T016 Ejecutar manualmente los 6 escenarios de `specs/061-conceptos-ncnd/quickstart.md` en el navegador (Ventas y Compras)

---

## Dependencies & Execution Order

- **Foundational (Phase 2)**: bloquea Phase 3 — validación/persistencia backend son prerequisito de la UI
- **User Story 1 (Phase 3)**: única historia — T009-T010 (Blade) y T011-T013 (JS) son secuenciales dentro del mismo archivo cada grupo, pero Blade y JS pueden avanzar en paralelo entre sí
- **Polish (Phase 4)**: depende de Phase 3

### Parallel Opportunities

- T001-T002 (FormRequests) son [P] entre sí (archivos distintos)
- T005-T008 (tests) son [P] entre sí

---

## Implementation Strategy

1. Foundational (T001-T004) → backend listo
2. US1 (T009-T013) → validar Escenarios 1-6 de quickstart.md
3. Polish → correr TODA la suite de NC/ND en verde antes de cerrar

## Notes

- Este spec no toca stock/CAE/comprobante — cualquier fallo en tests de spec 057/059 tras estos
  cambios es señal de que algo se tocó por error.
- Commitear después de cada tarea o grupo lógico.
