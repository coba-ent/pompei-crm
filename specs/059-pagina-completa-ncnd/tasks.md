---

description: "Task list for Página completa de NC/ND (corrección estructural sobre spec 057)"
---

# Tasks: Página completa de NC/ND (corrección estructural sobre spec 057)

**Input**: Design documents from `/specs/059-pagina-completa-ncnd/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/rutas-pagina-ncnd.md, quickstart.md

**Tests**: Incluidos — Principio IV, aunque el foco es no-regresión (el backend no cambia).

**Organization**: Tareas agrupadas por user story (US1-US2 de spec.md).

---

## Phase 1: Setup

Sin tareas — sin migraciones ni dependencias nuevas.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: La vista compartida y las rutas `GET` que ambas user stories necesitan.

- [X] T001 [P] Crear `resources/views/notas-credito-debito/form.blade.php`: página completa Crear/Editar compartida Ventas y Compras, modelada sobre `resources/views/compras/form.blade.php` (research.md §1) — header con Cliente/Proveedor heredado (no editable), Emisión/Vto. del Pago/Servicio Desde-Hasta, Tipo y N° de comprobante propio, bloque de ítems (ver T002/T003), Nota Interna, Descuento General, Total, bloques colapsados +Percepciones/+Impuestos Internos/+Intereses (sin funcionalidad, FR-003), botones Cancelar/Guardar (+ Eliminar si `$notaCreditoDebito` no es null)
- [X] T002 [US1,US2] En `form.blade.php`: bloque de ítems con selector de Producto/Servicio (Cant./Precio/Desc./Subtotal/IVA por fila) cuando `afecta_stock=true`, reusando el patrón de renderizado de filas ya existente en `compras.js` (research.md §4)
- [X] T003 [US1,US2] En `form.blade.php`: bloque de ítems reducido a una única fila con `<textarea>` "Descripción" (mismas columnas Cant./Precio/Desc./Subtotal/IVA) cuando `afecta_stock=false`
- [X] T004 Agregar rutas en `routes/web.php`: `GET ventas/{venta}/notas/nueva` (`ventas.notas.create`), `GET ventas/{venta}/notas/{notaCreditoDebito}/editar` (`ventas.notas.edit`), `GET compras/{compra}/notas/nueva` (`compras.notas.create`), `GET compras/{compra}/notas/{notaCreditoDebito}/editar` (`compras.notas.edit`) — contracts/rutas-pagina-ncnd.md
- [X] T005 Implementar `NotaCreditoDebitoController@create`/`edit`/`createCompra`/`editCompra`: resuelven `$venta`/`$compra`/`$notaCreditoDebito` (nullable en create) y renderizan `notas-credito-debito.form`, precargando desde query string (create, research.md §5) o desde `$notaCreditoDebito->load('items.producto')` (edit)

**Checkpoint**: Página completa y rutas listas — las user stories pueden empezar.

---

## Phase 3: User Story 1 - Crear una NC/ND en su propia página (Priority: P1)

**Goal**: El modal de paso 1 navega a la página completa en vez de mostrar un 2do paso; Guardar crea la nota y vuelve al detalle de origen.

**Independent Test**: quickstart.md Escenario 1 y 2.

### Tests for User Story 1 ⚠️

- [X] T006 [P] [US1] Test Feature: `GET ventas/{venta}/notas/nueva` devuelve 200 y renderiza el formulario, en `tests/Feature/NotaCreditoDebitoPaginaTest.php`
- [X] T007 [P] [US1] Test Feature: `GET ventas/{venta}/notas/nueva?afecta_stock=1&...` precarga los controles de paso 1 con los valores de la query string, en `tests/Feature/NotaCreditoDebitoPaginaTest.php`
- [X] T008 [P] [US1] Test Feature: crear una NC/ND desde el formulario completo (`POST` ya existente de spec 057) sigue produciendo los mismos efectos ya cubiertos por `NotaCreditoDebitoTest`/`NotaCreditoDebitoCompraTest` — correr esas suites sin modificar y confirmar que siguen en verde (no-regresión, CHK003)

### Implementation for User Story 1

- [X] T009 [US1] Recortar `resources/views/ventas/_modal_ncnd.blade.php`: eliminar el bloque `#ncnd-paso-2` completo (Fecha/Monto/Descripción/Tipo Comprobante/N° Comprobante, agregados en spec 057) — el modal queda con sólo Tipo/Documento que Ajusta/Stock/Mes + Cancelar/Siguiente (research.md §3)
- [X] T010 [US1] Idem T009 en `resources/views/compras/_modal_ncnd.blade.php`
- [X] T011 [US1] En `resources/js/ventas.js` (`inicializarNcNd`): el handler de `#btn-ncnd-siguiente` deja de llamar `irAPaso(2)` — arma la query string con los valores de paso 1 y navega (`window.location.href`) a `ventas.notas.create` (research.md §3); eliminar el handler de `#btn-ncnd-guardar` del modal (ya no existe ese botón ahí)
- [X] T012 [US1] Idem T011 en `resources/js/compras.js`
- [X] T013 [US1] En `resources/js/notas-credito-debito.js` (NUEVO, o inline en `form.blade.php`): lógica de guardado del formulario completo (agregar/quitar ítems, cálculo de subtotales, POST a `ventas.notas.store`/`compras.notas.store`, redirect al detalle de origen en éxito) — reusa la lógica ya escrita en spec 057 dentro del modal, movida a este archivo

**Checkpoint**: US1 completa y testeable de forma independiente.

---

## Phase 4: User Story 2 - Editar una NC/ND en la misma página, con Tipo y Stock bloqueados (Priority: P1)

**Goal**: El modal de edición bloquea Tipo Y Stock; "Siguiente" navega a la página completa precargada, con botón Eliminar.

**Independent Test**: quickstart.md Escenario 3 y 4.

### Tests for User Story 2 ⚠️

- [X] T014 [P] [US2] Test Feature: `GET ventas/{venta}/notas/{nota}/editar` devuelve 200 con los datos de la nota precargados (tipo, ítems, comprobante propio), en `tests/Feature/NotaCreditoDebitoPaginaTest.php`
- [X] T015 [P] [US2] Test Feature: editar/eliminar desde el formulario completo (`PUT`/`DELETE` ya existentes) sigue produciendo los mismos efectos ya cubiertos por `NotaCreditoDebitoEditarTest`/`NotaCreditoDebitoEliminarTest` — correr esas suites sin modificar y confirmar que siguen en verde (no-regresión, CHK003)

### Implementation for User Story 2

- [X] T016 [US2] En `ventas.js`/`compras.js` (`abrirEdicionNota`): además de deshabilitar `#ncnd-tipo` (ya lo hacía spec 057), deshabilitar también los radios `#ncnd-afecta-si`/`#ncnd-afecta-no` (research.md §6, FR-008)
- [X] T017 [US2] En `abrirEdicionNota`: el handler de "Siguiente" navega a `ventas.notas.edit`/`compras.notas.edit` (con el id de la nota) en vez de mostrar el paso 2 del modal
- [X] T018 [US2] En `form.blade.php` (modo edición): agregar botón "Eliminar" a la izquierda de Cancelar/Guardar, visible sólo cuando `$notaCreditoDebito` no es null (FR-009) — reusa el mismo endpoint `DELETE` y el modal de confirmación `#modal-eliminar-nota` ya construidos en spec 057 (movido de `_modal_ncnd.blade.php` a `form.blade.php`, research.md §7)

**Checkpoint**: US1 y US2 completas — cubren la corrección estructural completa.

---

## Phase 5: Polish & Cross-Cutting Concerns

- [X] T019 [P] Actualizar `docs/documentacion_principal_crm.md` §3.2 — ya hecho antes de `/speckit-tasks` (nota agregada sobre la corrección de spec 059 sobre lo ya documentado en spec 045/057)
- [ ] T020 [P] Correr `php artisan test --filter=NotaCreditoDebito` completo (todas las suites de spec 057 + las nuevas de spec 059) y confirmar 100% verde — es el criterio de no-regresión de este spec (SC-004)
- [ ] T021 Ejecutar manualmente los 6 escenarios de `specs/059-pagina-completa-ncnd/quickstart.md` en el navegador (Ventas y Compras) antes de dar la feature por terminada
- [X] T022 [P] Eliminar código muerto: confirmar que no queda ningún resto del paso 2 del modal (JS, IDs de Blade sin uso) tras T009-T013

---

## Dependencies & Execution Order

- **Foundational (Phase 2)**: bloquea US1 y US2 — la página completa y las rutas GET son prerequisito de ambas
- **US1 (Phase 3)** y **US2 (Phase 4)**: comparten archivos (`_modal_ncnd.blade.php`, `ventas.js`/`compras.js`, `form.blade.php`) — no son estrictamente paralelizables entre sí pese a ser independientes conceptualmente; conviene implementarlas en la misma sesión
- **Polish (Phase 5)**: depende de US1 y US2

### Parallel Opportunities

- T001-T005 (Foundational) son en su mayoría [P] entre sí (archivos distintos)
- T006-T008 (tests US1) son [P] entre sí; T014-T015 (tests US2) son [P] entre sí

---

## Implementation Strategy

1. Foundational (T001-T005) → página completa y rutas listas
2. US1 (Crear) → validar Escenario 1-2 de quickstart.md
3. US2 (Editar/Eliminar) → validar Escenario 3-4
4. Polish → correr TODA la suite de NC/ND (spec 057 + 059) en verde antes de cerrar

## Notes

- Este spec es de UI pura — cualquier fallo en los tests de spec 057 tras estos cambios es señal de
  que algo del backend se tocó por error; el criterio de éxito no es "tests nuevos en verde" sino
  "tests viejos siguen en verde + tests nuevos en verde".
- Commitear después de cada tarea o grupo lógico.
