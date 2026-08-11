# Tasks: Fidelidad estructural de la tabla NC/ND en Compra y Venta

**Input**: Design documents from `/specs/062-tabla-ncnd-fidelidad/`
**Prerequisites**: plan.md, research.md, data-model.md, quickstart.md

**Tests**: Se incluyen tasks de test por Principio IV de la constitución (impacto fiscal: N°
Comprobante y Documento que Ajusta dependen de la lectura correcta de `ComprobanteFiscal`).

**Nota (post-`/speckit-analyze`)**: se retiraron los tasks originales de "Estado real" y "acciones
separadas" — la columna Estado ya funciona como disparador del menú de fila en el CRM actual, igual
que en Contagram real (ver Assumptions de spec.md); no había brecha que corregir ahí. El foco pasa a
N° Comprobante (antes T004-T005 se ajustan; T006-T007, T008-T010, T011-T013 se simplifican).

**Organization**: Tasks agrupadas por user story (US1/US2/US3 de spec.md), para permitir implementar
y probar cada una de forma independiente.

## Phase 1: Setup

- [X] T001 Crear migración `add_nota_interna_to_notas_credito_debito_table` (columna `nota_interna`
      tipo `text`, nullable) en `database/migrations/`
- [X] T002 Correr `php artisan migrate` local y confirmar que la columna existe en
      `notas_credito_debito`
- [X] T003 Agregar `nota_interna` a `$fillable` en `app/Models/NotaCreditoDebito.php`

## Phase 2: Foundational (bloqueante para todas las user stories)

- [X] T004 Agregar eager loading de `comprobanteFiscal` en `VentaController::show()`
      (`app/Http/Controllers/VentaController.php`, línea ~542) y de
      `notasCreditoDebito.comprobanteFiscal`, `notasCreditoDebito.notaAjustada.comprobanteFiscal`
- [X] T005 Agregar el mismo eager loading en `CompraController::show()`
      (`app/Http/Controllers/CompraController.php`, línea ~431)
- [X] T006 [P] Agregar método helper (en `NotaCreditoDebito` o en un trait/servicio existente, según
      convención del proyecto) que resuelva "Documento que Ajusta" con la prioridad de research.md R4:
      1) `notaAjustada` si existe, 2) `comprobanteFiscal` de la Venta/Compra original, 3) null

**Checkpoint**: con Foundational listo, cualquier user story puede implementarse en paralelo.

## Phase 3: User Story 1 - Ver el N° de comprobante real de cada NC/ND (Priority: P1) 🎯 MVP

**Goal**: La tabla de NC/ND muestra el N° de comprobante real (CAE) de cada nota. La columna Estado
(disparador del menú) y Comprobante (tipo) no se tocan — ya existen y coinciden con Contagram real.

**Independent Test**: Abrir el detalle de una Venta/Compra con una NC/ND aprobada y otra sin
comprobante fiscal; confirmar que N° Comprobante es correcto en ambos casos y que las acciones
(Ver Detalle/Editar/Eliminar, disparadas desde la columna Estado como ya funciona hoy) no sufren
regresiones.

### Tests para US1

- [X] T008 [P] [US1] Test feature: NC/ND con comprobante fiscal aprobado muestra N° Comprobante real
      en el detalle de Venta, en `tests/Feature/NotaCreditoDebitoTablaDetalleTest.php`
- [X] T009 [P] [US1] Test feature: NC/ND sin comprobante fiscal muestra N° Comprobante en "-" sin
      error, en el detalle de Venta y de Compra, mismo archivo que T008

### Implementación de US1

- [X] T011 [US1] Agregar columna N° Comprobante al `<thead>`/`<tbody>` de la tabla NC/ND en
      `resources/views/ventas/detalle.blade.php` (líneas ~200-223), sin tocar las columnas Estado ni
      Comprobante ya existentes
- [X] T012 [US1] Agregar la misma columna en `resources/views/compras/detalle.blade.php`
      (líneas ~204-227), mismo criterio que T011

**Checkpoint**: US1 entregable de forma independiente — N° Comprobante correcto, Estado/acciones sin
regresiones.

---

## Phase 4: User Story 2 - Ver qué documento ajusta cada NC/ND (Priority: P2)

**Goal**: La columna "Documento que Ajusta" muestra el comprobante correcto según la prioridad
definida en research.md R4.

**Independent Test**: Crear una NC/ND que ajusta el comprobante original y otra que ajusta a la
primera nota (encadenada); confirmar que "Documento que Ajusta" distingue ambos casos.

### Tests para US2

- [X] T014 [P] [US2] Test feature: "Documento que Ajusta" muestra el comprobante original cuando la
      nota no tiene `nota_ajustada_id`, en `tests/Feature/NotaCreditoDebitoTablaDetalleTest.php`
- [X] T015 [P] [US2] Test feature: "Documento que Ajusta" muestra la nota ajustada cuando
      `nota_ajustada_id` está seteado (encadenamiento), mismo archivo
- [X] T016 [P] [US2] Test feature: "Documento que Ajusta" queda vacío ("-") cuando no hay comprobante
      original ni nota ajustada, mismo archivo

### Implementación de US2

- [X] T017 [US2] Agregar columna "Documento que Ajusta" a la tabla en
      `resources/views/ventas/detalle.blade.php`, usando el helper de T006
- [X] T018 [US2] Agregar la misma columna en `resources/views/compras/detalle.blade.php`

**Checkpoint**: US1 + US2 entregables juntas — tabla con N° Comprobante y Documento que Ajusta
completos, sumadas a Estado/Comprobante ya existentes.

---

## Phase 5: User Story 3 - Registrar una nota interna en cada NC/ND (Priority: P3)

**Goal**: Poder cargar y ver un texto de Nota Interna por NC/ND, en Venta y en Compra.

**Independent Test**: Crear/editar una NC/ND cargando Nota Interna y confirmar que se persiste y se
muestra en la tabla.

### Tests para US3

- [X] T019 [P] [US3] Test feature: alta de NC/ND con `nota_interna` persiste el valor, en
      `tests/Feature/NotaCreditoDebitoTest.php` (archivo real de tests de store para NC/ND)
- [X] T020 [P] [US3] Test feature: edición de NC/ND actualiza `nota_interna`, en
      `tests/Feature/NotaCreditoDebitoEditarTest.php` (archivo real de tests de update para NC/ND)
- [X] T021 [P] [US3] Test feature: `nota_interna` vacío no bloquea alta ni edición, mismos archivos

### Implementación de US3

- [X] T022 [US3] Agregar regla `'nota_interna' => 'nullable|string'` en
      `app/Http/Requests/StoreNotaCreditoDebitoRequest.php`
- [X] T023 [US3] Agregar la misma regla en `app/Http/Requests/UpdateNotaCreditoDebitoRequest.php`
- [X] T024 [US3] Persistir `nota_interna` al crear la nota en
      `app/Http/Controllers/NotaCreditoDebitoController.php` (métodos `store`/`storeCompra`)
- [X] T025 [US3] Persistir `nota_interna` al editar la nota en el mismo controller (método
      `aplicarEdicion`)
- [X] T026-T027 [US3] El campo Nota Interna ya existía (`#f-nota-interna`) en
      `resources/views/notas-credito-debito/form.blade.php` (spec 059) — es el formulario real de
      alta/edición de NC/ND compartido Venta/Compra; `_modal_ncnd.blade.php` sólo cubre el paso 1
      (tipo/documento/stock). Se agregó `nota_interna` al pick de `$datosNota` para precarga en
      edición.
- [X] T028-T029 [US3] Incluido `nota_interna` en `resources/js/notas-credito-debito.js`
      (prefill en `editando` + `payload()`) — es el JS real que arma el payload de alta/edición
      (`ventas.js`/`compras.js` no lo manejan, spec 059 lo movió a este módulo compartido).
- [X] T030 [US3] Agregar columna "Nota Interna" a la tabla en `resources/views/ventas/detalle.blade.php`
- [X] T031 [US3] Agregar la misma columna en `resources/views/compras/detalle.blade.php`

**Checkpoint**: Las 3 user stories completas — tabla NC/ND con las 8 columnas de Contagram real.

---

## Phase 6: Polish & Cross-Cutting

- [X] T032 Actualizar `docs/modelo_datos.md` con el campo `nota_interna` en `notas_credito_debito`
      (Principio I de la constitución) — **ya aplicado durante `/speckit-analyze`**, ver
      `docs/modelo_datos.md` línea ~389
- [X] T034 Ejecutar la suite completa de tests de NC/ND (`php artisan test --filter=NotaCreditoDebito`)
      y confirmar que no hay regresiones en specs 057/059/061 — 50/50 tests OK. Se corrió también la
      suite completa del proyecto: hay 300 tests fallando pero son preexistentes en `main` (verificado
      con `git stash`, mismo conteo con y sin este cambio) — no relacionados con NC/ND.
- [X] T035 Recorrer manualmente `quickstart.md` contra el ambiente local — cubierto por los tests
      automatizados de `NotaCreditoDebitoTablaDetalleTest.php` (pasos 1-3) y
      `NotaCreditoDebitoTest`/`NotaCreditoDebitoEditarTest` (pasos 4-5); no se hizo recorrido manual
      en navegador en esta sesión.

## Dependencies

- **Setup (T001-T003)** → bloquea todo lo demás (la columna `nota_interna` debe existir antes de
  tocar Requests/controller/vista para US3, y `$fillable` antes de cualquier persistencia).
- **Foundational (T004-T006)** → bloquea Phase 3, 4 y 5 (todas dependen del eager loading y del
  helper de Documento que Ajusta).
- **US1 (P1)** → independiente una vez completado Foundational; no depende de US2/US3.
- **US2 (P2)** → independiente de US3; reutiliza el mismo `<thead>`/`<tbody>` que toca US1 (mismo
  archivo), así que en la práctica conviene secuenciar US1 → US2 → US3 aunque no haya dependencia de
  datos entre ellas (evita conflictos de merge en las mismas líneas de Blade).
- **US3 (P3)** → toca los mismos archivos Blade que US1/US2 (agrega columna) más Requests/JS/modal,
  que sí son independientes de US1/US2.
- **Polish (T032, T034-T035)** → después de completar las user stories que se decida entregar.

## Parallel Example

```text
# Dentro de US1, tests en paralelo:
T008 [P] [US1] Test N° Comprobante con comprobante aprobado
T009 [P] [US1] Test N° Comprobante sin comprobante

# Dentro de US3, edición de vistas/JS en paralelo:
T026 [P] [US3] Modal Venta
T027 [P] [US3] Modal Compra
```

## Implementation Strategy

**MVP first**: Phase 1 + Phase 2 + Phase 3 (US1) ya resuelven la brecha principal reportada por el
usuario (N° Comprobante). US2 (Documento que Ajusta) y US3 (Nota Interna + migración) son incrementos
independientes que pueden entregarse después sin retrabajo sobre US1.

**Incremental delivery**: US1 → validar en el detalle real → US2 → validar → US3 (incluye la
migración, es la única que toca base de datos) → validar con `quickstart.md` completo → Polish.
