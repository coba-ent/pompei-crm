---
description: "Task list — Módulo Ingresos (Presupuestos · Ventas · Otros Ingresos)"
---

# Tasks: Módulo Ingresos (Presupuestos · Ventas · Otros Ingresos)

**Input**: Design documents from `specs/008-ingresos-ventas-presupuestos/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/ingresos-rutas.md, quickstart.md

**⚠️ Dependencia dura**: **spec 007 (Tesorería) implementada** — `CuentaTesoreria` + `Tesoreria::
registrarMovimiento()`. Sin ella, las cobranzas (US2) y Otros Ingresos con impacto (US3) no se pueden
completar. Presupuestos (US1) sí es independiente de Tesorería.

**Tests**: INCLUIDOS — módulo de dinero + impacto fiscal/stock (Constitución, Principio IV).

**Organización**: por User Story (US1–US4), en orden de prioridad.

## Path Conventions

Monolito Laravel: `app/`, `resources/`, `database/`, `routes/`, `tests/` en la raíz.

---

## Phase 1: Setup (Shared Infrastructure)

- [X] T001 [P] Crear migraciones de Presupuestos: `presupuestos`, `presupuesto_items`, `presupuesto_conceptos` (`database/migrations/2026_07_26_0601*`), según data-model.md.
- [X] T002 [P] Crear migraciones de Ventas: `ventas` (softDeletes), `venta_items`, `venta_conceptos`, `cobros` (softDeletes) (`2026_07_26_0602*`).
- [X] T003 [P] Crear migraciones de Otros Ingresos y NC/ND: `otros_ingresos` (softDeletes), `notas_credito_debito` (softDeletes), `nota_credito_debito_items`, `remitos` (`2026_07_26_0603*`).
- [X] T004 [P] Crear migraciones de etiquetas: `etiquetas`, `etiquetables` (polimórfico) (`2026_07_26_0604*`).
- [X] T005 [P] Registrar rutas del módulo en `routes/web.php` según `contracts/ingresos-rutas.md` (grupos presupuestos/ventas/otros-ingresos + categorías inline), placeholders a controladores nuevos.
- [X] T006 [P] Activar en `resources/views/elements/sidebar.blade.php` las rutas reales de Ingresos (Presupuestos, Ventas, Otros Ingresos) — hoy placeholder.
- [X] T007 [P] `database/seeders/CategoriasIngresoSeeder.php` (categorías tipo=ingreso: Aportes Socios, Otros Ingresos, Préstamos Financieros, Saldo Inicial); registrar en `DatabaseSeeder`.

---

## Phase 2: Foundational (Blocking Prerequisites)

**⚠️ CRITICAL**: bloquea todas las user stories.

- [X] T008 [P] Modelos base de Presupuesto: `app/Models/Presupuesto.php`, `PresupuestoItem.php`, `PresupuestoConcepto.php` (relaciones, casts, morphToMany etiquetas).
- [X] T009 [P] Modelos base de Venta: `app/Models/Venta.php` (SoftDeletes, derivados `cobrado`/`aCobrar`/`estadoCobro` vía accesores/withSum), `VentaItem.php`, `VentaConcepto.php`, `Cobro.php` (SoftDeletes, belongsTo CuentaTesoreria).
- [X] T010 [P] Modelos `app/Models/OtroIngreso.php` (SoftDeletes), `NotaCreditoDebito.php` (SoftDeletes), `NotaCreditoDebitoItem.php`, `Remito.php`, `Etiqueta.php`.
- [X] T011 Crear `app/Services/Ingresos/CalculoComprobante.php`: cálculo puro de subtotales por ítem, IVA, descuento general y conceptos (percepciones/impuestos/intereses) → totales. Compartido presupuesto/venta. Depende de T008/T009.
- [X] T012 Crear `app/Services/Ingresos/Cobranzas.php`: `registrarCobro(Venta, monto, cuenta, fecha, nota)` → crea `Cobro` + `Tesoreria::registrarMovimiento()` en una `DB::transaction`; `anularCobro()`; `registrarOtroIngreso()`/`conciliar()`; recomputa estado de la venta. **Único punto de integración con Tesorería (spec 007)**. Depende de T009, T010.
- [X] T013 Crear `app/Observers/VentaObserver.php`: al soft-delete de una Venta, soft-deletea sus cobros y anula los movimientos de tesorería asociados (sin saldo fantasma). Registrar en `AppServiceProvider`. Depende de T012.
- [X] T014 [P] Factories: `PresupuestoFactory`, `VentaFactory`, `CobroFactory`, `OtroIngresoFactory`, `NotaCreditoDebitoFactory` (+ items).

**Checkpoint**: modelos + servicios de cálculo/cobranza listos; sin UI todavía.

---

## Phase 3: User Story 1 — Presupuestos (Priority: P1) 🎯 MVP

**Goal**: crear/editar/ver presupuestos con cálculo correcto, estados y documento imprimible.
Independiente de Tesorería.

**Independent Test**: crear presupuesto de 1 producto → totales correctos, estado Pendiente, "Ver"
como documento, cambio de estado.

### Tests for User Story 1 ⚠️

- [X] T015 [P] [US1] `tests/Feature/PresupuestoCalculoTest.php`: `CalculoComprobante` — subtotal/IVA/descuento/percepciones correctos en varios escenarios (SC-001).
- [X] T016 [P] [US1] `tests/Feature/PresupuestoTest.php`: alta/edición, estado (pendiente/rechazado/aceptado), "Vencido" derivado, idempotencia del guardado (no duplica por doble submit — SC-007).

### Implementation for User Story 1

- [X] T017 [P] [US1] `app/Http/Requests/StorePresupuestoRequest.php` + `UpdatePresupuestoRequest.php` (cliente, items, descuento, fechas; token anti doble-submit).
- [X] T018 [US1] `app/Http/Controllers/PresupuestoController.php`: `index`/`data` (KPIs + DataTable), `create`/`edit` (página completa), `store`/`update` (usa `CalculoComprobante`, idempotente), `estado`, `show` (documento), `pdf`, `crearVenta` (marca convertido → redirige). Depende de T011, T017.
- [X] T019 [US1] Vistas: `resources/views/presupuestos/index.blade.php` (KPIs + DataTable), `form.blade.php` (página completa, 2 columnas, tabla de conceptos dinámica, percepciones/impuestos/intereses), `documento.blade.php` (imprimible), `pdf.blade.php`, `_modal_categoria.blade.php` (crear categoría venta inline).
- [X] T020 [US1] `resources/js/presupuestos.js`: DataTable server-side, Select2 (cliente/producto/lista), cálculo en vivo de totales, filas dinámicas de conceptos, autocompletar categoría/descuento al elegir cliente, Toastr, PDF por modal compartido.

**Checkpoint**: US1 funcional — se puede cotizar. MVP entregable (no requiere Tesorería).

---

## Phase 4: User Story 2 — Venta + Cobranza (Priority: P1)

**Goal**: crear venta (directa o desde presupuesto), cobrar contra cuenta de Tesorería, detalle con
barra de ecuación. **Requiere spec 007.**

**Independent Test**: crear venta, cobrarla contra "Caja del Local" → venta Cobrada, saldo de la cuenta
sube el monto, movimiento en la ficha de la cuenta.

### Tests for User Story 2 ⚠️

- [X] T021 [P] [US2] `tests/Feature/VentaCobranzaTest.php`: cobro impacta el saldo de Tesorería exactamente (SC-002); `aCobrar = Total + ND − NC − Cobrado` con cobros parciales y notas (SC-003); crear venta desde presupuesto preserva datos y marca convertido (SC-004); **soft delete revierte el movimiento de tesorería** (SC-005).

### Implementation for User Story 2

- [X] T022 [P] [US2] `app/Http/Requests/StoreVentaRequest.php` + `UpdateVentaRequest.php` (+ tipo_comprobante in A/B/C/E) y `StoreCobroRequest.php` (monto ≤ A Cobrar, cuenta_tesoreria_id exists+visible).
- [X] T023 [US2] `app/Http/Controllers/VentaController.php`: `index`/`data` (19 columnas), `create` (pre-carga `?presupuesto`), `store`/`update` (genera N° de comprobante como dato; `CalculoComprobante`), `show` (detalle), `pdf`/`ticket`, `destroy` (soft delete vía Observer). Depende de T011, T013, T022.
- [X] T024 [US2] `app/Http/Controllers/VentaController` (cobranzas): `cobranzas.store` (usa `Cobranzas::registrarCobro`), `cobranzas.destroy` (anula cobro + movimiento). JSON. Depende de T012.
- [X] T025 [US2] Vistas: `resources/views/ventas/index.blade.php` (19 columnas), `form.blade.php` (página completa, + tipo/N° comprobante, Vto. cobro), `detalle.blade.php` (barra de ecuación, tabla Cobranzas, documento con **watermark "NO VÁLIDO COMO FACTURA"**, sección NC/ND), `_modal_cobranza.blade.php` (Total/A Cobrar, monto editable, grilla de cuentas de Tesorería), `pdf.blade.php`, `ticket.blade.php`.
- [X] T026 [US2] `resources/js/ventas.js`: DataTable, Select2 (cliente/producto + `tesoreria.cuentas.opciones` para la cobranza), cálculo en vivo, modal Cobranza AJAX (cobro total/parcial), refresco del detalle y del listado, PDF por modal compartido, Toastr.

**Checkpoint**: US1 + US2 — flujo Presupuesto → Venta → Cobranza operativo, con impacto real en Tesorería.

---

## Phase 5: User Story 3 — Otros Ingresos (Priority: P2)

**Goal**: registrar ingresos de caja no-venta, con/ sin pendiente, impactando Tesorería.

**Independent Test**: crear ingreso $500 contra Caja General → saldo sube; uno pendiente no impacta.

### Tests for User Story 3 ⚠️

- [X] T027 [P] [US3] `tests/Feature/OtroIngresoTest.php`: ingreso no-pendiente genera movimiento de tesorería en la cuenta (impacta saldo); "pendiente" NO impacta (SC-006); conciliar (quitar pendiente) genera el movimiento.

### Implementation for User Story 3

- [X] T028 [P] [US3] `app/Http/Requests/StoreOtroIngresoRequest.php` + `UpdateOtroIngresoRequest.php` (categoria tipo=ingreso, cuenta required_unless pendiente).
- [X] T029 [US3] `app/Http/Controllers/OtroIngresoController.php`: `index`/`data` (7 columnas), `store`/`update` (usa `Cobranzas::registrarOtroIngreso`/`conciliar`), `destroy` (soft delete + reversión). JSON/modal. Depende de T012, T028.
- [X] T030 [US3] Vistas: `resources/views/otros-ingresos/index.blade.php` (7 columnas, sin selector de columnas/Analizar/masivas), `_modal_ingreso.blade.php` (Fecha, Monto, Categoría, Medio de Cobro=cuenta Tesorería, Descripción, "Marcar como pendiente"), `_modal_categoria.blade.php` (crear categoría ingreso inline).
- [X] T031 [US3] `resources/js/otros-ingresos.js`: DataTable, Select2 (categoría/cuenta), modal AJAX, Toastr.

**Checkpoint**: US1–US3 — circuito de ingresos completo (ventas + no-ventas) impactando Tesorería.

---

## Phase 6: User Story 4 — Notas de Crédito / Débito (Priority: P3)

**Goal**: NC/ND sobre venta con wizard de 2 pasos, opcionalmente afectando stock.

**Independent Test**: NC que afecta stock sobre una venta → stock repuesto, barra de ecuación
actualizada.

### Tests for User Story 4 ⚠️

- [X] T032 [P] [US4] `tests/Feature/NotaCreditoDebitoTest.php`: NC que afecta stock genera `movimientos_stock` correcto; NC resta y ND suma en la barra de ecuación de la venta (afecta `aCobrar`).

### Implementation for User Story 4

- [X] T033 [P] [US4] `app/Http/Requests/StoreNotaCreditoDebitoRequest.php` (wizard: si afecta_stock→items required; si no→descripcion required).
- [X] T034 [US4] `app/Http/Controllers/NotaCreditoDebitoController.php`: `store` (crea NC/ND; si afecta stock → `StockService`/`movimientos_stock`; recomputa venta). Depende de T009, T011.
- [X] T035 [US4] Vista `resources/views/ventas/_modal_ncnd.blade.php` (wizard 2 pasos) + integración en `ventas.js` (abrir desde el menú de fila / detalle, AJAX, Toastr).
- [X] T036 [US4] Enganche "Crear Remito" (`ventas.remitos.store` + encabezado) desde el menú de fila/detalle — encabezado mínimo (FR-018), sin detalle de ítems (pendiente de relevamiento).

**Checkpoint**: las 4 user stories funcionan; el detalle de venta refleja NC/ND.

---

## Phase 7: Polish & Cross-Cutting Concerns

- [X] T037 [P] Actualizar `docs/documentacion_principal_crm.md §3`: cambiar "documentado, pendiente de implementar" → implementado; actualizar §3.5 (Tesorería ya provee medios de cobro; qué queda pendiente: Abonos, Cta Cte, ARCA, Remitos detalle, Recibos, WhatsApp).
- [X] T038 [P] Actualizar `docs/modelo_datos.md §5`: marcar las tablas de Ingresos como implementadas.
- [X] T039 [P] Actualizar `CREDENCIALES_ACCESO.txt` si se creó/usó algún acceso para pruebas manuales.
- [X] T040 Verificar fidelidad de pantalla contra `docs/informe_contagram_ingresos.md` (columnas exactas de los 3 listados, menús de fila, KPIs de presupuestos, orden de campos del formulario) — ajustar divergencias.
- [X] T041 Dejar documentados como deshabilitados/pendientes (no falsos) los puntos de UI fuera de alcance: "Cta Cte", "Enviar WhatsApp", botón "Analizar" (IA), y el vínculo a Abonos.
- [X] T042 Ejecutar `quickstart.md` (Escenarios 1–4) y `php artisan test --filter="Presupuesto|Venta|OtroIngreso|NotaCredito"` en verde.

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (P1)** → **Foundational (P2)** → user stories.
- **US1 (Presupuestos)**: depende de Foundational. **No** depende de Tesorería → verdadero MVP entregable
  aunque 007 no esté.
- **US2 (Venta+Cobranza)**: depende de Foundational **y de spec 007** (medios de cobro + servicio).
- **US3 (Otros Ingresos)**: depende de Foundational **y de spec 007**.
- **US4 (NC/ND)**: depende de Foundational + US2 (existe la venta) + specs 002/003 (stock).
- **Polish (P7)**: al final.

### Within Each User Story

- Tests primero (fallan), luego Requests → Controlador → Vistas → JS.
- `CalculoComprobante` (T011) y `Cobranzas` (T012) son prerequisitos de US1/US2/US3.

### Parallel Opportunities

- T001–T007 (migraciones/rutas/sidebar/seeder) en paralelo.
- T008–T010 (modelos) y T014 (factories) en paralelo.
- Tests de cada US ([P]) en paralelo entre sí.
- Vistas de distintas US son archivos distintos → paralelizables tras el backend.
- **Nota**: `resources/js/ventas.js` lo tocan US2 (T026) y US4 (T035) → esas ediciones son secuenciales
  (no [P] cruzado). Ídem cada `*.js` es de una sola pantalla.

---

## Implementation Strategy

### MVP First

1. Setup + Foundational → US1 (Presupuestos). **STOP & VALIDATE** (Escenario 1). Entregable sin
   Tesorería.
2. (Con spec 007 lista) US2 (Venta+Cobranza) → el corazón del módulo, impacto en Tesorería.
3. US3 (Otros Ingresos) → completa el circuito de ingresos.
4. US4 (NC/ND) → ajustes contables + stock.
5. Polish → docs + fidelidad + validación.

### Nota de orden entre specs

Implementar **spec 007 (Tesorería) antes que 008** salvo que se quiera entregar sólo US1 (Presupuestos)
como avance temprano. US2/US3 se bloquean sin Tesorería (regla de oro: no se construye un catálogo de
medios de cobro paralelo).

---

## Notes

- [P] = archivos distintos, sin dependencias. Cada `*.js` es de una pantalla; `ventas.js` NO es [P]
  entre US2/US4.
- Principio IV: tests de dinero/stock (cálculo, A Cobrar, impacto Tesorería, reversión, NC-stock) en
  verde antes de cerrar cada US.
- Principio III: ventas/cobros/otros ingresos/NC-ND con **soft delete**; comprobante con watermark "NO
  VÁLIDO COMO FACTURA" (sin ARCA, por alcance acordado).
- Los cambios de dominio (Phase 7) incluyen la actualización de `docs/`.
