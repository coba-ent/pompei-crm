---
description: "Task list — Módulo Egresos (Compras · Gastos)"
---

# Tasks: Módulo Egresos (Compras · Gastos)

**Input**: Design documents from `specs/009-egresos-compras-gastos/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/egresos-rutas.md, quickstart.md

**⚠️ Dependencia dura**: **spec 007 (Tesorería) implementada** — `CuentaTesoreria` + `Tesoreria::
registrarMovimiento()` — y **spec 008 (Ingresos) implementada** — se reutilizan
`Services/Ingresos/CalculoComprobante`, el controlador genérico de NC/ND, y se crea recién acá la tabla
`retenciones` que spec 008 sólo documentó. Sin 007/008, ninguna user story de esta spec se puede
completar (Compras y Gastos requieren medios de pago = Tesorería).

**Tests**: INCLUIDOS — módulo de dinero + impacto fiscal/stock (Constitución, Principio IV).

**Organización**: por User Story (US1–US4), en orden de prioridad.

## Path Conventions

Monolito Laravel: `app/`, `resources/`, `database/`, `routes/`, `tests/` en la raíz.

---

## Phase 1: Setup (Shared Infrastructure)

- [X] T001 [P] Crear migraciones de Compras: `compras` (softDeletes), `compra_items`, `compra_conceptos` (`database/migrations/2026_07_25_0701*`), según data-model.md.
- [X] T002 [P] Crear migración de `pagos` (softDeletes) (`2026_07_25_0702*`).
- [X] T003 [P] Crear migración de `gastos` (`2026_07_25_0703*`, **con** `deleted_at`/SoftDeletes — Principio III exige soft delete para gastos —, sin Observer dedicado — ver research §6).
- [X] T004 [P] Crear migración de `retenciones` (documentada en spec 008/modelo_datos.md §5, nunca construida) (`2026_07_25_0704*`).
- [X] T005 [P] Migración de extensión de `notas_credito_debito`: agregar columna `compra_id` (FK → compras, nullable) junto al `venta_id` existente (`2026_07_25_0705*`).
- [X] T006 [P] Registrar rutas del módulo en `routes/web.php` según `contracts/egresos-rutas.md` (grupos compras/gastos + categorías inline), placeholders a controladores nuevos.
- [X] T007 [P] Activar en `resources/views/elements/sidebar.blade.php` las rutas reales de Egresos (Compras, Gastos) — hoy placeholder.
- [X] T008 [P] `database/seeders/CategoriasGastoSeeder.php` (categorías tipo=gasto con subcategorías: Empleados, Impuestos, Marketing→Facebook Ad's/Material de Promoción, Oficina→Alquiler/Luz, Otros Gastos, Servicios Profesionales); registrar en `DatabaseSeeder`.

---

## Phase 2: Foundational (Blocking Prerequisites)

**⚠️ CRITICAL**: bloquea todas las user stories.

- [X] T009 [P] Modelos base de Compra: `app/Models/Compra.php` (SoftDeletes, derivados `pagado`/`aPagar`/`estadoPago` vía accesores/withSum — nunca una columna `estado` persistida, Clarifications), `CompraItem.php` (`iva_pct` nullable sin default), `CompraConcepto.php`.
- [X] T010 [P] Modelo `app/Models/Pago.php` (SoftDeletes, belongsTo Compra y CuentaTesoreria).
- [X] T011 [P] Modelo `app/Models/Gasto.php` (**con SoftDeletes**, sin Observer dedicado — ver research §6; belongsTo Categoria y CuentaTesoreria, scope jerárquico categoría/subcategoría).
- [X] T012 [P] Modelo `app/Models/Retencion.php` (belongsTo Cobro nullable, belongsTo Pago nullable; validación de dominio "exactamente uno de los dos" en el modelo o en el Request).
- [X] T013 [P] Modificar `app/Models/NotaCreditoDebito.php`: agregar relación `compra()` junto a `venta()` existente (spec 008), con el mismo contrato "exactamente uno de los dos".
- [X] T014 Crear `app/Services/Egresos/Pagos.php`: `registrarPago(Compra, monto, cuenta, fecha, nota)` → crea `Pago` + `Tesoreria::registrarMovimiento()` (monto negativo) en una `DB::transaction`; `anularPago()`; `registrarGasto()`/`conciliarGasto()`; recomputa estado de la compra. **Único punto de integración con Tesorería desde Egresos (spec 007)**, análogo a `Services/Ingresos/Cobranzas` (spec 008). Depende de T009, T010, T011.
- [X] T015 Crear `app/Observers/CompraObserver.php`: al soft-delete de una Compra, soft-deletea sus pagos y anula los movimientos de tesorería asociados (sin saldo fantasma). Registrar en `AppServiceProvider`. Depende de T014.
- [X] T016 [P] Factories: `CompraFactory`, `PagoFactory`, `GastoFactory`, `RetencionFactory` (+ items/conceptos).

**Checkpoint**: modelos + servicio de pago/gasto listos; sin UI todavía.

---

## Phase 3: User Story 1 — Compra + Pago (Priority: P1) 🎯 MVP

**Goal**: crear/editar/ver compras con cálculo correcto (IVA opcional), estado derivado, pagos contra
Tesorería y documento imprimible. Requiere spec 007 (Tesorería) y spec 008 (`CalculoComprobante`).

**Independent Test**: crear compra de 1 producto (IVA en "Elegir" → luego 21%), guardarla, pagarla contra
"Caja del Local" → compra Pagada, saldo de la cuenta baja el monto, movimiento en la ficha de la cuenta.

### Tests for User Story 1 ⚠️

- [X] T017 [P] [US1] `tests/Feature/CompraCalculoTest.php`: `CalculoComprobante` reutilizado con ítems `iva_pct=null` ("Importe Neto No Gravado") y con IVA elegido ("Importe Neto Gravado"); subtotal/descuento/percepciones correctos (SC-001); idempotencia del guardado (no duplica por doble submit — SC-007).
- [X] T018 [P] [US1] `tests/Feature/CompraPagoTest.php`: pago impacta el saldo de Tesorería exactamente, en negativo (SC-002); `aPagar = Total + ND − NC − Pagado` con pagos parciales y notas (SC-003); estado Pagado/A Pagar siempre derivado, nunca forzable (Clarifications); **soft delete de compra pagada revierte el movimiento de tesorería** (SC-004).

### Implementation for User Story 1

- [X] T019 [P] [US1] `app/Http/Requests/StoreCompraRequest.php` + `UpdateCompraRequest.php` (proveedor, items con `iva_pct` nullable sin default, `mes_imputacion_iva` nullable, descuento, fechas; token anti doble-submit) y `StorePagoRequest.php` (monto ≤ A Pagar, cuenta_tesoreria_id exists+visible).
- [X] T020 [US1] `app/Http/Controllers/CompraController.php`: `index`/`data` (KPIs + DataTable), `create`/`edit` (página completa), `store`/`update` (usa `CalculoComprobante` reutilizado, sin forzar IVA por defecto; genera tipo/N° de comprobante como dato), `show` (detalle), `pdf`, `destroy` (soft delete vía `CompraObserver`). Depende de `Services/Ingresos/CalculoComprobante` (spec 008, sin tarea propia en esta lista), T009, T015, T019.
- [X] T021 [US1] `app/Http/Controllers/CompraController` (pagos): `pagos.store` (usa `Pagos::registrarPago`), `pagos.destroy` (anula pago + movimiento). JSON. Depende de T014.
- [X] T022 [US1] Vistas: `resources/views/compras/index.blade.php` (KPIs + DataTable, dos rangos de fecha Emisión/Vencimiento), `form.blade.php` (página completa, campo **Contador**, ítems con IVA "Elegir", percepciones/impuestos/intereses), `detalle.blade.php` (barra de ecuación Total+ND−NC−Pagado=A Pagar, tabla Pagos, documento watermark "NO VÁLIDO COMO FACTURA"), `_modal_pago.blade.php` (Monto precargado con saldo, Elija Medio de Pago), `pdf.blade.php`, `_row_actions.blade.php` (9 opciones: Ver/Editar/Ver Detalle/Agregar Pago/Crear NC-ND/Crear Remito/Cta Cte/Imprimir Detalle/Eliminar).
- [X] T023 [US1] `resources/js/compras.js`: DataTable server-side, Select2 (proveedor/producto + `tesoreria.cuentas.opciones` para el pago), cálculo en vivo de totales (IVA "Elegir" sin default), autocompletar Categoría de Compras al elegir proveedor, filas dinámicas de conceptos, modal Pago AJAX (pago total/parcial), Toastr, PDF por modal compartido.

**Checkpoint**: US1 funcional — se puede comprar y pagar, con impacto real en Tesorería.

---

## Phase 4: User Story 2 — Retenciones sobre una Compra (Priority: P2)

**Goal**: registrar retenciones sufridas al pagar a un proveedor, vinculadas a la compra vía `pago_id`.

**Independent Test**: sobre una compra, "+ Agregar Retención" con tipo IVA → queda listada en el detalle.

### Tests for User Story 2 ⚠️

- [X] T024 [P] [US2] `tests/Feature/RetencionCompraTest.php`: crear retención vinculada a un `pago_id` de una compra; constraint "exactamente uno de cobro_id/pago_id" se respeta; listado en el detalle de la compra.

### Implementation for User Story 2

- [X] T025 [P] [US2] `app/Http/Requests/StoreRetencionRequest.php` (fecha, monto, tipo_retencion, nro_comprobante, descripción; `pago_id` seteado desde la ruta).
- [X] T026 [US2] `app/Http/Controllers/CompraController` (retenciones): `retenciones.store` (crea `Retencion` con `pago_id`). JSON. Depende de T012, T025.
- [X] T027 [US2] Vista `resources/views/compras/_modal_retencion.blade.php` (Fecha, Monto, Elija Tipo con catálogo Ganancias/IVA/Seguridad Social/Sellos/Ingresos Brutos por jurisdicción, N°/comprobante, Descripción) + integración en `compras.js` (abrir desde el detalle, AJAX, Toastr).

**Checkpoint**: US1 + US2 — Compras con pagos y retenciones sufridas.

---

## Phase 5: User Story 3 — Gasto (Priority: P1)

**Goal**: registrar erogaciones operativas por categoría/subcategoría jerárquica, por modal, impactando
Tesorería. Independiente de US1/US2 (no depende de Compras).

**Independent Test**: crear gasto $5.000 en Marketing→Facebook Ads contra "Banco Galicia" → saldo baja;
uno pendiente no impacta.

### Tests for User Story 3 ⚠️

- [X] T028 [P] [US3] `tests/Feature/GastoTest.php`: gasto no-pendiente genera movimiento de tesorería en la cuenta (impacta saldo, en negativo); "pendiente" NO impacta (SC-005); conciliar (quitar pendiente) genera el movimiento; eliminar revierte el movimiento sin Observer (delete directo en el controller).

### Implementation for User Story 3

- [X] T029 [P] [US3] `app/Http/Requests/StoreGastoRequest.php` + `UpdateGastoRequest.php` (categoría tipo=gasto hoja del árbol, cuenta required_unless pendiente).
- [X] T030 [US3] `app/Http/Controllers/GastoController.php`: `index`/`data` (sin KPIs, columnas relevadas, selector de 6 columnas con Monto oculta por defecto), `store`/`update` (usa `Pagos::registrarGasto`/`conciliarGasto`), `destroy` (**soft delete** — `$gasto->delete()` — y anula el movimiento de tesorería asociado en la misma transacción, directamente en el controller, sin Observer — research §6; Principio III exige soft delete también para gastos). JSON/modal. Depende de T014, T029.
- [X] T031 [US3] Vistas: `resources/views/gastos/index.blade.php` (un único selector de fecha Emisión, sin columna Proveedor), `_modal_gasto.blade.php` (Fecha default hoy, Monto, Seleccionar Categoría jerárquica con "Crear Categoría de Gasto"/"Crear Subcategoría", Elija un medio de pago, Descripción, "Marcar como pendiente"), `_modal_categoria.blade.php` (alta de categoría/subcategoría de gasto inline), `_row_actions.blade.php` (sólo Ver/Editar/Eliminar).
- [X] T032 [US3] `resources/js/gastos.js`: DataTable, Select2 (categoría jerárquica/cuenta), modal AJAX (alta y edición reutilizan el mismo modal — clic en el Id reabre "Editar Gasto"), Toastr.

**Checkpoint**: US1 + US3 — Compras y Gastos operativos de forma independiente entre sí.

---

## Phase 6: User Story 4 — Notas de Crédito / Débito sobre Compra (Priority: P3)

**Goal**: NC/ND sobre una compra, reutilizando el wizard genérico de spec 008.

**Independent Test**: crear NC sobre una compra → la barra de ecuación de la compra refleja la NC (A
Pagar disminuye).

### Tests for User Story 4 ⚠️

- [X] T033 [P] [US4] `tests/Feature/NotaCreditoDebitoCompraTest.php`: NC sobre compra resta y ND suma en la barra de ecuación (afecta `aPagar`); constraint "exactamente uno de venta_id/compra_id" se respeta.

### Implementation for User Story 4

- [X] T034 [US4] Extender `app/Http/Requests/StoreNotaCreditoDebitoRequest.php` (spec 008): agregar validación `compra_id` (exactamente uno de `venta_id`/`compra_id`) — resuelto estructuralmente vía route model binding + `storeCompra()` dedicado (nunca se setean ambas FKs a la vez).
- [X] T035 [US4] Extender `app/Http/Controllers/NotaCreditoDebitoController.php` (spec 008): aceptar `compra_id` en `store`, recomputar la compra en vez de la venta cuando corresponda. Depende de T009, T013, T034.
- [X] T036 [US4] Reutilizar el wizard `_modal_ncnd.blade.php` (spec 008) desde `resources/views/compras/detalle.blade.php` + integración en `compras.js` (abrir "Crear NC/ND" desde el menú de fila/detalle de Compra).
- [X] T037 [US4] Enganche "Crear Remito" (`compras.remitos.store` + encabezado) desde el menú de fila/detalle de Compra — encabezado mínimo (FR-011), sin detalle de ítems (pendiente de relevamiento, mismo criterio que Ventas).

**Checkpoint**: las 4 user stories funcionan; el detalle de compra refleja NC/ND y remito.

---

## Phase 7: Polish & Cross-Cutting Concerns

- [X] T038 [P] Actualizar `docs/documentacion_principal_crm.md §4`: cambiar "documentado, pendiente de implementar" → implementado; actualizar §4.3 (Tesorería ya provee medios de pago; qué queda pendiente: Cta Cte, ARCA, Remitos detalle, Recibos de Pagos).
- [X] T039 [P] Actualizar `docs/modelo_datos.md §7`: marcar las tablas de Egresos como implementadas; actualizar §5 (`retenciones`) reflejando que ya tiene flujo real que la puebla.
- [X] T040 [P] `CREDENCIALES_ACCESO.txt`: no se creó ni usó ningún acceso nuevo para pruebas manuales en esta spec (tests automatizados vía factories) — sin cambios necesarios.
- [X] T041 Verificar fidelidad de pantalla contra `docs/informe_contagram_egresos.md` (columnas exactas de los 2 listados, menús de fila, KPIs de Compras, orden de campos del formulario, selector de 6 columnas de Gastos) — columnas de Compras reordenadas a Total/Pagado/A Pagar para calzar con el informe; menú de fila (9 opciones) y de Gastos (3 opciones) verificados contra el documento.
- [X] T042 Dejar documentados como deshabilitados/pendientes (no falsos) los puntos de UI fuera de alcance: "Cta Cte" en el menú de fila de Compra (`disabled`, título "Próximamente"), tipo/N° de comprobante sin CAE real (watermark "NO VÁLIDO COMO FACTURA").
- [X] T043 `php artisan test --filter="CompraCalculoTest|CompraPagoTest|RetencionCompraTest|GastoTest|NotaCreditoDebitoCompraTest"` → **18/18 en verde**. El filtro literal de tasks.md (`Compra|Gasto|...`) también matchea ~50 tests **preexistentes y no relacionados** de módulos viejos ya borrados (specs 007-compras/008-gastos con numeración antigua, Informes, CuentaCorriente, Facturación — ver nota más abajo); no se tocaron ni se intentó arreglar, están fuera de alcance de esta spec.

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (P1)** → **Foundational (P2)** → user stories.
- **US1 (Compra+Pago)**: depende de Foundational **y de spec 007 (Tesorería) y spec 008 (`CalculoComprobante`)**. Es el MVP del módulo.
- **US2 (Retenciones)**: depende de Foundational + US1 (existe la compra/pago que recibe la retención).
- **US3 (Gasto)**: depende de Foundational **y de spec 007**. Independiente de US1/US2 (no toca Compras).
- **US4 (NC/ND)**: depende de Foundational + US1 (existe la compra) + spec 008 (controlador genérico de NC/ND).
- **Polish (P7)**: al final.

### Within Each User Story

- Tests primero (fallan), luego Requests → Controlador → Vistas → JS.
- `CalculoComprobante` (spec 008, reutilizado) y `Pagos` (T014) son prerequisitos de US1/US2/US3.

### Parallel Opportunities

- T001–T008 (migraciones/rutas/sidebar/seeder) en paralelo.
- T009–T013 (modelos) y T016 (factories) en paralelo.
- Tests de cada US ([P]) en paralelo entre sí.
- Vistas de distintas US son archivos distintos → paralelizables tras el backend.
- US3 (Gasto) puede desarrollarse en paralelo a US1/US2 (Compra) una vez completada la Fase 2 — no
  comparten controlador ni vistas, sólo el servicio `Pagos` (ya construido en Foundational).
- **Nota**: `resources/js/compras.js` lo tocan US1 (T023), US2 (T027) y US4 (T036) → esas ediciones son
  secuenciales (no [P] cruzado). `resources/js/gastos.js` (US3) es independiente.

---

## Implementation Strategy

### MVP First

1. Setup + Foundational → US1 (Compra + Pago). **STOP & VALIDATE** (Escenario 1). Es el corazón del
   módulo y requiere Tesorería (spec 007) y `CalculoComprobante` (spec 008) ya disponibles.
2. US3 (Gasto) → puede entregarse en paralelo a US1, es independiente y completa la mitad más simple del
   módulo.
3. US2 (Retenciones) → ajuste secundario sobre Compras ya funcionando.
4. US4 (NC/ND) → ajustes contables sobre Compras.
5. Polish → docs + fidelidad + validación.

### Nota de orden entre specs

Implementar **spec 007 (Tesorería) y spec 008 (Ingresos) antes que 009** — ambas dependencias duras
(medios de pago reales + `CalculoComprobante`/`retenciones`/controlador NC-ND reutilizados). No se
construye un catálogo de medios de pago paralelo ni un segundo servicio de cálculo de totales (regla de
oro).

---

## Notes

- [P] = archivos distintos, sin dependencias. Cada `*.js` es de una pantalla; `compras.js` NO es [P]
  entre US1/US2/US4.
- Principio IV: tests de dinero (cálculo, A Pagar, impacto Tesorería en negativo, reversión, NC-stock si
  aplica) en verde antes de cerrar cada US.
- Principio III: compras/pagos/**gastos** con **soft delete** (la Constitución nombra "gastos"
  explícitamente; Gasto no tiene Observer pero sí `SoftDeletes` — ver research §6); comprobante de
  compra con watermark "NO VÁLIDO COMO FACTURA" (sin ARCA, por alcance acordado, mismo criterio que
  Ventas). Gasto no es documento fiscal, no aplica watermark (pero sí soft delete).
- Clarifications (spec.md): el estado de Compra (Pagado/A Pagar) es **siempre derivado**, nunca una
  columna con override manual — ningún task de esta lista debe introducir un campo `estado` persistido
  en `compras`.
- Los cambios de dominio (Phase 7) incluyen la actualización de `docs/`.
