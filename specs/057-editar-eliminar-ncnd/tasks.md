---

description: "Task list for Edición y eliminación de Notas de Crédito y Débito (NC/ND)"
---

# Tasks: Edición y eliminación de Notas de Crédito y Débito (NC/ND)

**Input**: Design documents from `/specs/057-editar-eliminar-ncnd/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/rutas-ncnd.md, quickstart.md

**Tests**: Incluidos — Principio IV de la constitución exige tests donde hay dinero, stock o
impacto fiscal, y este feature toca los tres.

**Organization**: Tareas agrupadas por user story (US1-US4 de spec.md), en orden de prioridad.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Puede ejecutarse en paralelo (archivos distintos, sin dependencias)
- **[Story]**: A qué user story pertenece (US1-US4)

## Path Conventions

Proyecto único Laravel — `app/`, `resources/`, `routes/`, `database/`, `tests/` en la raíz del
repo (ver `plan.md` → Project Structure).

---

## Phase 1: Setup

**Purpose**: Preparar el esquema de datos que todas las user stories necesitan.

- [X] T001 Crear migración `database/migrations/2026_08_11_000000_add_edicion_a_notas_credito_debito.php`: agrega `nro_comprobante` (string, nullable) y `nota_ajustada_id` (unsignedBigInteger, nullable, FK → `notas_credito_debito.id`) a `notas_credito_debito`; agrega `descuento_pct` (decimal 5,2, nullable, default 0) e `iva_pct` (decimal 5,2, nullable) a `nota_credito_debito_items`; **`producto_id` en `nota_credito_debito_items` es hoy `foreignId()` NOT NULL** (confirmado contra `2026_07_30_060006_create_notas_credito_debito_tables.php:31`) — en esta misma migración, hacerlo nullable (`->nullable()->change()` — `doctrine/dbal` **no está en `composer.json`**, así que hay que instalarlo primero con `composer require doctrine/dbal --dev` o usar `DB::statement('ALTER TABLE ...')` crudo como alternativa) ya que los ítems dejan de estar condicionados a `afecta_stock=true` y pueden representar un concepto sin producto asociado — **implementado con `DB::statement` condicionado a `mysql` (no-op en SQLite de tests); `doctrine/dbal` se instaló igual como dev-dependency durante la implementación**
- [X] T002 Correr `php artisan migrate` en local y confirmar que las columnas nuevas existen sin romper datos existentes (841 notas legacy con `venta_id`/`compra_id` NULL deben seguir intactas)

**Checkpoint**: Esquema listo — ninguna user story puede empezar antes de esto.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Modelos, relaciones y servicios base que TODAS las user stories usan.

**⚠️ CRITICAL**: Ninguna user story puede implementarse antes de completar esta fase.

- [X] T003 [P] Actualizar `app/Models/NotaCreditoDebito.php`: agregar `nro_comprobante`, `nota_ajustada_id` a `$fillable`; agregar relaciones `notaAjustada(): BelongsTo` y `notasQueLaAjustan(): HasMany` (ver data-model.md); agregar método `tieneCaeAprobado(): bool` que delega en `$this->comprobanteFiscal?->aprobado() === true`
- [X] T004 [P] Actualizar `app/Models/NotaCreditoDebitoItem.php`: agregar `descuento_pct`, `iva_pct` a `$fillable` y a `$casts` (decimal)
- [X] T005 [P] Extender `app/Services/AjustesPendientesNotaCreditoDebito.php`: método `pendiente()` acepta un parámetro opcional `?NotaCreditoDebito $excluir` para excluir del cálculo de "ya ajustada" los ítems de la nota en edición (FR-005)
- [X] T006 [P] Extender `app/Services/Stock/StockService.php`: `ajustar()` gana un parámetro `?Model $origen` (usado ya en `store`/`storeCompra`) y se agrega `revertirNotaCreditoDebito(NotaCreditoDebito $nota)` que revierte exactamente los movimientos generados con esa nota como origen
- [X] T007 [US1,US2] Crear `app/Http/Requests/UpdateNotaCreditoDebitoRequest.php`: mismas reglas base que `StoreNotaCreditoDebitoRequest` + rechazo (422) si `tipo` llega distinto al `tipo` actual de la nota (FR-002, no editable) + validación de duplicado de `tipo_comprobante`+`nro_comprobante` contra `Venta`, `Compra` y `NotaCreditoDebito` (excluyendo la propia nota) + validación de "pendiente" excluyendo la nota en edición (usa T005) + rechazo si `nota_ajustada_id` apunta a una nota que ya tiene su propio `nota_ajustada_id` seteado (FR-013)

**Checkpoint**: Modelos y servicios base listos — las user stories pueden empezar.

---

## Phase 3: User Story 1 - Corregir una NC/ND cargada por error (Priority: P1) 🎯 MVP

**Goal**: Permitir editar una NC/ND existente (Venta o Compra) reabriendo el wizard precargado,
con reversión correcta de stock si corresponde.

**Independent Test**: Crear una NC/ND, editar su monto (y, en un segundo caso, su cantidad de
stock), y verificar que el detalle/PDF/stock reflejan el nuevo valor sin duplicar ni arrastrar el
ajuste anterior.

### Tests for User Story 1 ⚠️

- [X] T008 [P] [US1] Test Feature: editar NC/ND sin stock (monto/descripción) actualiza el registro y la barra de ecuación de la Venta, en `tests/Feature/NotaCreditoDebitoEditarTest.php`
- [X] T009 [P] [US1] Test Feature: editar NC/ND que afecta stock revierte el movimiento anterior y aplica el nuevo exacto (Escenario 2 de quickstart.md), en `tests/Feature/NotaCreditoDebitoEditarTest.php`
- [X] T010 [P] [US1] Test Feature: editar una NC/ND con CAE aprobado devuelve 409 y no modifica nada (FR-011), en `tests/Feature/NotaCreditoDebitoEditarTest.php`
- [X] T011 [P] [US1] Test Feature: aumentar la cantidad de un ítem al editar no choca contra el "pendiente" de la propia nota (FR-005), en `tests/Feature/NotaCreditoDebitoEditarTest.php`
- [X] T012 [P] [US1] Test Feature: editar con un `nro_comprobante` duplicado contra otra Venta/Compra/NC-ND devuelve 422 (FR-012), en `tests/Feature/NotaCreditoDebitoEditarTest.php`
- [X] T012b [P] [US1] Test Feature: intentar cambiar el `tipo` (Crédito↔Débito) de una nota existente al editarla devuelve 422 y no modifica la nota (FR-002), en `tests/Feature/NotaCreditoDebitoEditarTest.php`

### Implementation for User Story 1

- [X] T013 [US1] Agregar rutas `PUT ventas/{venta}/notas/{notaCreditoDebito}` (`ventas.notas.update`) y `PUT compras/{compra}/notas/{notaCreditoDebito}` (`compras.notas.update`) en `routes/web.php` (ver contracts/rutas-ncnd.md)
- [X] T014 [US1] Implementar `NotaCreditoDebitoController@update` (Ventas) en `app/Http/Controllers/NotaCreditoDebitoController.php`: valida con `UpdateNotaCreditoDebitoRequest` (T007), bloquea si `tieneCaeAprobado()` (T003), revierte stock previo si `afecta_stock` (T006) y reaplica si corresponde, actualiza campos incluidos `nro_comprobante`/`nota_ajustada_id`/ítems con IVA
- [X] T015 [US1] Implementar `NotaCreditoDebitoController@updateCompra` (mismo patrón, análogo a `storeCompra`) en el mismo controller
- [X] T016 [US1] Extender `resources/views/ventas/_modal_ncnd.blade.php`: título dinámico, campos Tipo/N° de comprobante propios editables, botón Eliminar y modal de confirmación de borrado
- [X] T017 [US1] Replicar T016 en `resources/views/compras/_modal_ncnd.blade.php`
- [X] T018 [US1] Extender `resources/js/ventas.js`: función `abrirEdicionNota(id)` que precarga el wizard desde `window.VentaDetalleData.notas` (evita un endpoint GET nuevo) y handler de guardado que hace `PUT` en vez de `POST` cuando está en modo edición
- [X] T019 [US1] Replicar T018 en `resources/js/compras.js` apuntando a `compras.notas.update`
- [X] T020 [US1] Agregar el link/acción "Editar" (dropdown en la columna Estado de la fila) en la tabla de NC/ND de `resources/views/ventas/detalle.blade.php` y `resources/views/compras/detalle.blade.php` (FR-001)

**Checkpoint**: User Story 1 completa y testeable de forma independiente — es el MVP.

---

## Phase 4: User Story 2 - Eliminar una NC/ND cargada por error (Priority: P1)

**Goal**: Permitir eliminar (soft delete) una NC/ND desde el menú de fila y desde el propio
formulario de edición, revirtiendo stock y respetando el bloqueo por cadena/CAE.

**Independent Test**: Crear una NC/ND que afecta stock, eliminarla, y verificar que el stock
vuelve exacto al valor previo y la nota desaparece de la tabla (soft delete).

### Tests for User Story 2 ⚠️

- [X] T021 [P] [US2] Test Feature: eliminar NC/ND que afecta stock revierte el stock exacto (Escenario 3 de quickstart.md), en `tests/Feature/NotaCreditoDebitoEliminarTest.php`
- [X] T022 [P] [US2] Test Feature: eliminar una NC/ND con CAE aprobado devuelve 409 (FR-011), en `tests/Feature/NotaCreditoDebitoEliminarTest.php`
- [X] T023 [P] [US2] Test Feature: eliminar una NC/ND referenciada por otra (vía `nota_ajustada_id`) devuelve 409 con el listado de notas dependientes (FR-006), en `tests/Feature/NotaCreditoDebitoEliminarTest.php`
- [X] T024 [P] [US2] Test Feature: la eliminación es soft delete — la fila sigue en DB con `deleted_at` seteado, nunca `forceDelete` (Principio III constitución), en `tests/Feature/NotaCreditoDebitoEliminarTest.php`

### Implementation for User Story 2

- [X] T025 [US2] Agregar rutas `DELETE ventas/{venta}/notas/{notaCreditoDebito}` (`ventas.notas.destroy`) y `DELETE compras/{compra}/notas/{notaCreditoDebito}` (`compras.notas.destroy`) en `routes/web.php`
- [X] T026 [US2] Implementar `NotaCreditoDebitoController@destroy` y `@destroyCompra`: bloquea si `tieneCaeAprobado()` o si `notasQueLaAjustan()->exists()` (con mensaje listando IDs dependientes), revierte stock (T006), hace `$nota->delete()` (soft)
- [X] T027 [US2] Agregar la opción "Eliminar" (con confirmación) al menú de fila de NC/ND en `resources/views/ventas/detalle.blade.php` y `resources/views/compras/detalle.blade.php`
- [X] T028 [US2] Agregar el botón "Eliminar" dentro del paso 2 del wizard de edición (`_modal_ncnd.blade.php` de Ventas y Compras, T016/T017), llamando al mismo endpoint que T027
- [X] T029 [US2] Extender `resources/js/ventas.js` y `resources/js/compras.js`: handler de eliminación (menú de fila y botón dentro del modal) usando el modal de confirmación `#modal-eliminar-nota` (no `confirm()` nativo)

**Checkpoint**: User Stories 1 y 2 completas — cubren el caso de uso principal del pedido original.

---

## Phase 5: User Story 3 - Ver el detalle impreso (PDF) de una NC/ND en Compras (Priority: P2)

**Goal**: Extender el PDF de NC/ND (hoy sólo Ventas) para que funcione también sobre notas de
Compras, sin regresión en Ventas.

**Independent Test**: Abrir "Ver Detalle" de una NC/ND de Compra y confirmar que el PDF muestra
proveedor/comprobante/ítems correctamente; repetir sobre una de Venta y confirmar que no cambió.

### Tests for User Story 3 ⚠️

- [X] T030 [P] [US3] Test Feature: `GET compras/notas/{nota}/pdf` sobre una NC/ND de Compra devuelve 200 con el proveedor y comprobante correctos, en `tests/Feature/NotaCreditoDebitoPdfTest.php`
- [X] T031 [P] [US3] Test Feature: `GET ventas/notas/{nota}/pdf` sobre una NC/ND de Venta sigue devolviendo 200 con los mismos datos que antes (no regresión), en `tests/Feature/NotaCreditoDebitoPdfTest.php`

### Implementation for User Story 3

- [X] T032 [US3] Generalizar `NotaCreditoDebitoController@pdf` en `app/Http/Controllers/NotaCreditoDebitoController.php`: cargar `compra.proveedor`/`compra.comprobanteFiscal` además de `venta.cliente`/`venta.comprobanteFiscal`, resolviendo cuál de las dos está seteada
- [X] T033 [US3] Generalizar `resources/views/notas-credito-debito/pdf.blade.php`: usar `$notaCreditoDebito->venta ?? $notaCreditoDebito->compra` y los campos equivalentes de Cliente/Proveedor (nombre, CUIT), + tabla de ítems con IVA
- [X] T034 [US3] Agregar ruta `GET compras/notas/{notaCreditoDebito}/pdf` (`compras.notas.pdf`) en `routes/web.php`, apuntando al mismo método `pdf()`
- [X] T035 [US3] Agregar el link "Ver Detalle" (vía `window.AppPdf.abrir`, regla de diseño obligatoria) a la tabla de NC/ND en `resources/views/compras/detalle.blade.php` (hoy ausente) y confirmar que el de `resources/views/ventas/detalle.blade.php` sigue apuntando a `ventas.notas.pdf`

**Checkpoint**: PDF de NC/ND disponible y simétrico en Ventas y Compras.

---

## Phase 6: User Story 4 - Encadenar una NC/ND como corrección de otra (Priority: P3)

**Goal**: El selector "Documento que Ajusta" (creación y edición) permite elegir otra NC/ND de
primer nivel además del comprobante original, con el límite de 1 nivel ya validado en T007.

**Independent Test**: Crear dos NC/ND sobre la misma Venta; al crear/editar una tercera, el
selector debe ofrecer ambas como opción; intentar encadenar sobre la segunda debe estar bloqueado.

**Estado**: **NO implementado en esta pasada** — el bloqueo de negocio (FR-013, 1 nivel) ya está
cubierto en el backend por T007 (`UpdateNotaCreditoDebitoRequest`), pero el selector
`#ncnd-documento` de la UI sigue deshabilitado/estático (no poblado con las NC/ND existentes) y no
hay endpoint que liste las notas de nivel 0. Prioridad P3, fuera del MVP (US1+US2) — queda como
pendiente explícito para una iteración siguiente.

### Tests for User Story 4 ⚠️

- [ ] T036 [P] [US4] Test Feature: el endpoint de items/opciones para "Documento que Ajusta" incluye las NC/ND existentes de nivel 0 del mismo comprobante, en `tests/Feature/NotaCreditoDebitoEncadenamientoTest.php`
- [ ] T037 [P] [US4] Test Feature: crear/editar una NC/ND con `nota_ajustada_id` apuntando a una nota que ya tiene su propio `nota_ajustada_id` es rechazado (FR-013) — **cubierto parcialmente por T012 vía el FormRequest; falta el test dedicado a este archivo**

### Implementation for User Story 4

- [ ] T038 [US4] Agregar endpoint (o extender `itemsDisponiblesVenta`/`itemsDisponiblesCompra` existentes en `NotaCreditoDebitoController`) que devuelva, además de los ítems, la lista de NC/ND de nivel 0 del comprobante para poblar el select "Documento que Ajusta"
- [ ] T039 [US4] Extender el select `#ncnd-documento` en `resources/js/ventas.js` y `resources/js/compras.js` para poblarse con el comprobante original + las notas de T038, excluyendo (en edición) la propia nota
- [ ] T040 [US4] Mostrar en la columna "Documento que Ajusta" de la tabla de NC/ND (`ventas/detalle.blade.php`, `compras/detalle.blade.php`) el número de la nota ajustada cuando `nota_ajustada_id` está seteado, en vez del comprobante original

**Checkpoint**: Las 4 user stories funcionan de forma independiente y combinada — **US4 pendiente**.

---

## Phase 7: Polish & Cross-Cutting Concerns

**Purpose**: Cierre de calidad transversal a todas las historias.

- [X] T041 [P] Actualizar `docs/documentacion_principal_crm.md` §3.2 y `docs/modelo_datos.md` si la implementación reveló algún detalle no contemplado en el plan (Principio I de la constitución) — ya estaban actualizados desde la fase de `/speckit-plan` (11/08/2026), verificado sin brechas nuevas
- [X] T042 [P] Correr `php artisan test --filter=NotaCreditoDebito` y confirmar que las suites (T008-T012b, T021-T024, T030-T031) pasan en verde — **29/29 verde** (T036-T037 de US4 no existen todavía)
- [ ] T043 Ejecutar manualmente los 6 escenarios de `specs/057-editar-eliminar-ncnd/quickstart.md` en local (navegador) antes de dar la feature por terminada — regla del proyecto: probar en el browser real, no sólo tests — **pendiente, no ejecutado en esta pasada**
- [ ] T044 [P] Resolver los ítems abiertos del checklist `specs/057-editar-eliminar-ncnd/checklists/riesgo-fiscal-stock.md` (CHK004, CHK008, CHK011, CHK014, CHK018, CHK021, CHK023, CHK026, CHK027) — pendiente, no abordado en esta pasada

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: sin dependencias — arranca primero
- **Foundational (Phase 2)**: depende de Phase 1 — bloquea las 4 user stories
- **User Story 1 (Phase 3)**: depende de Foundational — es el MVP
- **User Story 2 (Phase 4)**: depende de Foundational; reutiliza T006/T016-T019 de US1 (bloque del wizard y botón Eliminar viven en los mismos archivos) — en la práctica conviene implementarla junto con US1, aunque son independientemente testeables
- **User Story 3 (Phase 5)**: depende sólo de Foundational — 100% independiente de US1/US2
- **User Story 4 (Phase 6)**: depende de Foundational y de T007 (validación de 1 nivel) — puede implementarse en paralelo a US1/US2 salvo por tocar los mismos archivos de vista/JS (conflicto de merge, no de lógica)
- **Polish (Phase 7)**: depende de todas las user stories que se decida incluir en el release

### Parallel Opportunities

- T003-T007 (Foundational) son en su mayoría [P] — archivos distintos
- T008-T012 (tests US1) son [P] entre sí
- T021-T024 (tests US2) son [P] entre sí
- T030-T031 (tests US3) son [P] entre sí y con cualquier tarea de US1/US2 (archivos distintos)
- T036-T037 (tests US4) son [P] entre sí

---

## Implementation Strategy

### MVP First (User Story 1 Only)

1. Completar Phase 1 (Setup) + Phase 2 (Foundational)
2. Completar Phase 3 (US1 — Editar)
3. **Parar y validar**: correr Escenario 1 y 2 de quickstart.md
4. Esto ya resuelve el caso de uso que originó el pedido ("no puedo corregir una nota mal cargada")

### Incremental Delivery

1. Setup + Foundational → base lista
2. US1 (Editar) → validar → MVP
3. US2 (Eliminar) → validar (comparte archivos con US1, conviene entregar junto)
4. US3 (PDF en Compras) → validar → 100% independiente, se puede priorizar antes que US2 si conviene
5. US4 (Encadenamiento) → validar → cierre de fidelidad estructural con Contagram real

---

## Notes

- Los tests son obligatorios (Principio IV de la constitución) — no se marcan como opcionales pese
  a que el template de tasks los presenta como tales por defecto.
- T016/T017/T028 (mismo archivo `_modal_ncnd.blade.php`) y T018/T019/T029 (mismo archivo `*.js`)
  no son `[P]` entre sí dentro de la misma historia por tocar los mismos archivos — sí lo son entre
  Ventas y Compras (archivos distintos).
- Commitear después de cada tarea o grupo lógico; parar en cualquier checkpoint para validar antes
  de seguir.
