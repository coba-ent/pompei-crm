# Tasks: Editar cobranzas de una venta

**Input**: Design documents from `/specs/053-editar-cobranzas-venta/`
**Prerequisites**: plan.md, spec.md, data-model.md, contracts/cobranzas-update.md, research.md

**Tests**: incluidos y obligatorios para la lógica de servicio/endpoint (Principio IV de la
constitución: toda lógica que toca saldos de tesorería requiere tests en verde).

## Phase 1: Setup

- [X] T001 Actualizar `docs/documentacion_principal_crm.md` con la nota de extensión propia: la
      edición de cobranza no está relevada en Contagram real (ver `docs/informe_contagram_ingresos.md`),
      se agrega como capacidad propia de este CRM (Principio I de la constitución, Spec Assumptions).

## Phase 2: Foundational (bloqueante para todas las user stories)

- [X] T002 Crear `app/Http/Requests/UpdateCobroRequest.php`: reglas `cuenta_tesoreria_id` (required,
      exists), `monto` (required, numeric, gt:0, lte: `aCobrar(venta) + cobro->monto` — ver
      research.md §2), `fecha` (required, date), `nota` (nullable, string); mismo patrón de
      `failedValidation()` JSON 422 que `StoreCobroRequest.php`.
- [X] T003 Agregar `actualizarCobro(Cobro $cobro, float $monto, CuentaTesoreria $cuenta, Carbon $fecha, ?string $nota = null): Cobro`
      en `app/Services/Ingresos/Cobranzas.php`: dentro de `DB::transaction`, actualiza el `Cobro`
      (monto, cuenta_tesoreria_id, fecha, nota) y, si existe, actualiza in-place su
      `movimientoTesoreria` (monto, cuenta_tesoreria_id, fecha) — ver data-model.md y research.md §1.
      Si el cobro está soft-deleted o no tiene `movimientoTesoreria`, lanzar excepción de dominio
      (p. ej. `\RuntimeException` o similar ya usado en el proyecto) que el controller traduce a
      404/422 (FR-006, FR-006a).
- [X] T004 Agregar ruta `Route::put('{venta}/cobranzas/{cobro}', [VentaController::class, 'cobranzaUpdate'])->name('cobranzas.update')`
      junto a las rutas existentes de cobranzas en `routes/web.php` (líneas ~202-204).
- [X] T005 Agregar método `cobranzaUpdate(UpdateCobroRequest $request, Venta $venta, Cobro $cobro)`
      en `app/Http/Controllers/VentaController.php`, análogo a `cobranzaStore` (líneas 470-485):
      valida pertenencia del cobro a la venta (404 si no), resuelve `CuentaTesoreria`, delega a
      `Cobranzas::actualizarCobro()`, responde JSON `{ok: true, cobro: {...}}` (ver contracts/cobranzas-update.md).

**Checkpoint**: con T002-T005 el endpoint de edición ya es funcional y testeable por Postman/curl,
sin UI todavía.

---

## Phase 3: User Story 1 - Corregir un dato mal cargado en una cobranza (Priority: P1) 🎯 MVP

**Goal**: el usuario puede editar monto, fecha, cuenta y nota de una cobranza desde el detalle de
venta, reflejándose en la UI sin recargar la página.

**Independent Test**: abrir el detalle de una venta con una cobranza, editar el monto, guardar, y
verificar que la tabla y el saldo pendiente se actualizan sin recarga (quickstart.md, sección
"Validar User Story 1").

### Tests (US1)

- [X] T006 [P] [US1] Test feature `tests/Feature/Cobranzas/ActualizarCobroTest.php`: editar monto
      actualiza el `Cobro` y su `MovimientoTesoreria` asociado con los mismos valores (sin duplicar
      movimientos) — cubre SC-002 y Acceptance Scenario 1 de US1.
- [X] T007 [P] [US1] Test feature (mismo archivo o `tests/Feature/Cobranzas/ActualizarCuentaCobroTest.php`):
      editar la cuenta de tesorería mueve el movimiento a la nueva cuenta y dejar de impactar en la
      vieja — cubre Acceptance Scenario 2 de US1.
- [X] T008 [P] [US1] Test feature: editar sólo la nota no altera monto/fecha/cuenta — cubre
      Acceptance Scenario 3 de US1.
- [X] T009 [P] [US1] Test feature: `cobranzaUpdate` sobre un cobro anulado (soft-deleted) responde
      404/422 y no modifica nada — cubre FR-006.
- [X] T010 [P] [US1] Test feature: `cobranzaUpdate` sobre un cobro cuya `venta_id` no coincide con
      la venta de la ruta responde 404 (mismo criterio que `cobranzaDestroy`).
- [X] T010A [P] [US1] Test feature: `cobranzaUpdate` sobre un cobro activo sin `movimientoTesoreria`
      asociado (estado anómalo) responde error sin crear un movimiento nuevo — cubre FR-006a.

### Implementation (US1)

- [X] T011 [US1] Agregar campo de nota visible y campo oculto `#cobranza-id` en
      `resources/views/ventas/_modal_cobranza.blade.php`, y ajustar el título/label del modal para
      poder distinguir "Nueva cobranza" de "Editar cobranza" (atributo `data-modo` o similar).
- [X] T012 [US1] En `resources/js/ventas.js`, modificar `abrirCobranza()` (líneas 994-1008) para
      aceptar un `cobro` opcional: si se pasa, precarga monto/fecha/cuenta/nota, cambia el título
      del modal a modo edición y guarda el id en `#cobranza-id`; si no se pasa, mantiene el
      comportamiento actual de alta.
- [X] T013 [US1] En `resources/js/ventas.js`, modificar el submit del modal (líneas ~1012-1022):
      si `#cobranza-id` tiene valor, hacer `$.ajax({method:'PUT', url: rutas.cobranzaUpdateBase + '/' + id, ...})`
      contra la nueva ruta; si no, mantener el `$.post(rutas.cobranzaStore, ...)` actual. Actualizar
      la fila de la tabla y el saldo pendiente de la venta con la respuesta, sin recargar (FR-010).
      Mostrar toast de éxito/error (FR-011), reutilizando el mecanismo Toastr ya usado en el archivo.
- [X] T014 [US1] Exponer `cobranzaUpdateBase` (o `route('ventas.cobranzas.update', [$venta, 0])`
      recortado) junto a `cobranzaStore`/`cobranzaDestroyBase` en el bloque de rutas JS de
      `resources/views/ventas/detalle.blade.php` (líneas ~235-236).
- [X] T015 [US1] Agregar handler `.js-editar-cobro` en `resources/js/ventas.js` que, dado el `id`
      de la fila, busque los datos del cobro (desde el dataset de la fila o vía fetch al detalle) y
      llame a `abrirCobranza(cobro)` en modo edición.

**Checkpoint**: US1 completa y verificable de punta a punta según quickstart.md.

---

## Phase 4: User Story 2 - Desplegable de acciones consistente con el resto del CRM (Priority: P2)

**Goal**: la columna de acciones de `#tabla-cobranzas` usa el mismo patrón de desplegable que el
resto de las tablas del CRM, con "Ver recibo", "Editar" y "Eliminar".

**Independent Test**: abrir el detalle de una venta y verificar visualmente el desplegable con las
tres opciones (quickstart.md, sección "Validar User Story 2").

### Implementation (US2)

- [X] T016 [US2] Crear `resources/views/ventas/_row_actions_cobranza.blade.php` calcado del
      marcado de `resources/views/ventas/_row_actions.blade.php` (dropdown Bootstrap), con ítems
      "Ver recibo" (`js-ver-recibo-cobranza`), "Editar" (`js-editar-cobro`, nuevo de T015) y
      "Eliminar" (`js-eliminar-cobro`), todos con `data-id="{{ $cobro->id }}"`.
- [X] T017 [US2] En `resources/views/ventas/detalle.blade.php`, reemplazar la columna de acciones
      de `#tabla-cobranzas` (líneas 74-93) para que la primera columna incluya
      `@include('ventas._row_actions_cobranza', ['cobro' => $cobro])` en vez de los íconos sueltos
      actuales, preservando el resto de columnas (Id, Fecha, Medio de cobro, Nota, Total).
- [X] T018 [US2] Verificar que los handlers existentes `.js-ver-recibo-cobranza` (líneas 1060-1068)
      y `.js-eliminar-cobro` (líneas 1030-1034) de `resources/js/ventas.js` siguen funcionando sin
      cambios de lógica al mover los enlaces dentro del nuevo dropdown (sólo cambia el selector de
      contexto si hiciera falta delegar el evento a nivel de tabla).

**Checkpoint**: US2 completa; UI de cobranzas alineada al resto del CRM.

---

## Phase 5: User Story 3 - No permitir que una edición sobre-cobre la venta (Priority: P1)

**Goal**: ninguna edición de monto puede dejar la venta con saldo cobrado mayor a su total.

**Independent Test**: intentar subir el monto de una cobranza por encima del margen disponible en
una venta totalmente cobrada y verificar el rechazo (quickstart.md, "Validar User Story 3"). Nota:
la validación de servidor (T002) ya cubre esto; esta fase agrega el caso límite exacto y el manejo
en UI del error.

### Tests (US3)

- [X] T019 [P] [US3] Test feature: venta totalmente cobrada ($0 pendiente), editar su única
      cobranza subiendo el monto por encima del total → 422 con mensaje `monto.lte`, cobranza sin
      cambios — cubre Acceptance Scenario 1 de US3.
- [X] T020 [P] [US3] Test feature: venta con saldo pendiente parcial, editar una cobranza dentro
      del margen disponible (monto actual + saldo pendiente) → 200, saldo pendiente recalculado a
      $0 — cubre Acceptance Scenario 2 de US3.
- [X] T021 [P] [US3] Test feature: editar monto a 0 o negativo → 422 (edge case del spec).
- [X] T022 [P] [US3] Test feature: editar con fecha inválida → 422, misma regla que alta (edge case
      del spec).

### Implementation (US3)

- [X] T023 [US3] En `resources/js/ventas.js`, en el handler de submit de edición (T013), mostrar el
      mensaje de error `monto.lte` devuelto por el backend en el toast de error sin cerrar el modal,
      para que el usuario pueda corregir el valor sin perder los demás campos cargados.

**Checkpoint**: las tres user stories completas; feature lista para QA manual con quickstart.md.

---

## Phase 6: Polish & Cross-Cutting

- [X] T024 [P] Revisar que el ícono/estilo del botón de desplegable de `_row_actions_cobranza.blade.php`
      respete los estilos compactos de `public/css/contagram-custom.css` (referencia: memoria
      "Altura uniforme de form controls" del proyecto).
- [X] T025 Ejecutar la suite de tests de la feature (`php artisan test --filter=Cobranzas`) y
      confirmar que T006-T010, T010A y T019-T022 están en verde.
- [X] T026 Validar manualmente los tres flujos de `quickstart.md` en navegador (golden path + casos
      negativos), incluyendo revisar en Tesorería que no queden movimientos duplicados/huérfanos.

## Dependencies & Execution Order

- **Phase 1 (Setup)** → sin dependencias, puede ir en paralelo con Phase 2.
- **Phase 2 (Foundational)** → bloquea todas las user stories (US1, US2, US3 dependen de
  T002-T005: request, servicio, ruta y controller).
- **User Story 1 (P1)** → depende sólo de Phase 2. Es el MVP.
- **User Story 2 (P2)** → depende de Phase 2 y de que exista el handler `.js-editar-cobro` (T015,
  parte de US1) para incluirlo en el dropdown; en la práctica se implementa después de US1.
- **User Story 3 (P1)** → depende de Phase 2 (la validación de servidor ya está en T002); su parte
  de tests puede hacerse en paralelo con US1/US2, su única tarea de UI (T023) depende de T013 (US1).
- **Polish** → depende de que US1, US2 y US3 estén completas.

## Parallel Execution Examples

- Dentro de Phase 3 (US1): T006, T007, T008, T009, T010, T010A son `[P]` (archivos/casos de test
  independientes) y pueden escribirse en paralelo antes de T011-T015.
- Dentro de Phase 5 (US3): T019, T020, T021, T022 son `[P]` y pueden escribirse en paralelo.
- T001 (Phase 1) puede hacerse en paralelo con toda la Phase 2, ya que sólo toca documentación.

## Implementation Strategy

**MVP = User Story 1** (edición funcional end-to-end, aunque la tabla siga con íconos sueltos).
Orden recomendado de entrega: Phase 1 + Phase 2 → **US1 (MVP)** → US3 (endurecer validación de
sobre-cobro, en paralelo posible con US1 dado que la regla ya vive en T002) → US2 (pulido de UI).
