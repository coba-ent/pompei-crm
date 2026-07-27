---
description: "Task list — Módulo Tesorería (Cuentas y Movimientos)"
---

# Tasks: Módulo Tesorería (Cuentas y Movimientos)

**Input**: Design documents from `specs/007-tesoreria-cuentas-movimientos/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/tesoreria-rutas.md, quickstart.md

**Tests**: INCLUIDOS — es un módulo íntegramente de dinero (Constitución, Principio IV: testing
obligatorio donde hay dinero/impacto contable). Los tests de saldos, partida doble y balance corrido
son de primera clase, no opcionales.

**Organización**: por User Story (US1–US5), en orden de prioridad. Cada fase es un incremento
independientemente testeable.

## Path Conventions

Monolito Laravel: `app/`, `resources/`, `database/`, `routes/`, `tests/` en la raíz del repo.

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: base del módulo (migraciones, modelos, servicio, seed) — no toca UI todavía.

- [X] T001 Crear migración `database/migrations/2026_07_25_060001_create_cuentas_tesoreria_table.php` con el esquema de data-model.md (`nombre`, `tipo` enum, `visible`, `es_sistema`, `saldo_inicial`, `saldo_inicial_fecha`, `orden`, timestamps; índices `tipo`, `visible`).
- [X] T002 Crear migración `database/migrations/2026_07_25_060002_create_movimientos_tesoreria_table.php` (`cuenta_tesoreria_id` FK, `fecha`, `tipo` enum, `monto` signed decimal(14,2), `detalle`, `nro_comprobante`, `observacion`, `transferencia_id`, `origen_type`/`origen_id`, `usuario_id`, `deleted_at` softDeletes; índices `(cuenta_tesoreria_id,fecha,id)`, `tipo`, `transferencia_id`, `(origen_type,origen_id)`).
- [X] T003 [P] Registrar rutas del grupo `tesoreria/*` en `routes/web.php` según `contracts/tesoreria-rutas.md` (placeholders a controladores nuevos), bajo `auth` + permisos `tesoreria.*`.
- [X] T004 [P] Activar en `resources/views/elements/sidebar.blade.php` las rutas reales de Tesorería (hoy placeholder): Saldos (`tesoreria.saldos`) y Movimientos (`tesoreria.movimientos`).

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: modelos, servicio de dominio y seed que TODAS las user stories usan.

**⚠️ CRITICAL**: ninguna user story puede empezar hasta terminar esta fase.

- [X] T005 [P] Crear modelo `app/Models/CuentaTesoreria.php`: fillable, casts, `scopeVisibles`, `scopePorTipo`, `esCaja()`/`esBanco()`, relación `movimientos()`, `tieneOperaciones()`, `saldoA(?Carbon)` (data-model.md).
- [X] T006 [P] Crear modelo `app/Models/MovimientoTesoreria.php`: `SoftDeletes`, `morphTo origen()`, `belongsTo cuenta()`, scopes `hastaFecha`/`delTipo`, accesores `ingreso`/`egreso`, `esNativo()`.
- [X] T007 Crear `app/Services/Tesoreria/Tesoreria.php` con: `registrarSaldoInicial(CuentaTesoreria, monto, fecha)`, `transferir(salida, entrada, monto, fecha, obs)` (partida doble atómica en `DB::transaction`, 2 filas con `transferencia_id` compartido y signos opuestos), `registrarMovimiento(cuenta, monto, tipo, origen, ...)` (API pública para módulos futuros — FR-030), `saldos(?fecha)` (saldos por cuenta/bloque), `flujo(desde, hasta, cuentasActivas)` (informe Movimientos). Depende de T005, T006.
- [X] T008 [P] Crear `database/factories/CuentaTesoreriaFactory.php` y `database/factories/MovimientoTesoreriaFactory.php`.
- [X] T009 Crear `database/seeders/CuentasTesoreriaSeeder.php` con las cuentas del sistema (Cheque de Terceros/Propio) + cuentas del relevamiento (data-model.md §Seed); registrarlo en `DatabaseSeeder`.

**Checkpoint**: base lista — el saldo de una cuenta ya se puede calcular vía servicio; UI aún no.

---

## Phase 3: User Story 1 — Ver Saldos (Priority: P1) 🎯 MVP

**Goal**: pantalla de entrada que muestra A Cobrar / A Pagar / Disponible (Cajas/Bancos) con saldo a
fecha de corte.

**Independent Test**: con el seed aplicado, `/tesoreria` muestra los tres bloques con subtotales y
totales correctos; cambiar la fecha de corte recalcula.

### Tests for User Story 1 ⚠️

- [X] T010 [P] [US1] `tests/Feature/TesoreriaSaldosLedgerTest.php`: `Tesoreria::saldoA(fecha)` correcto por corte (movimientos con `fecha <= corte`); saldo negativo permitido; agrupación por bloque (Cajas=efectivo, Bancos=banco) y totales.

### Implementation for User Story 1

- [X] T011 [US1] `app/Http/Controllers/TesoreriaController.php` método `saldos()` (GET `/tesoreria`): arma bloques A Cobrar/A Pagar/Disponible con `Tesoreria::saldos($fecha)`, `?fecha` de corte (default hoy). Sólo cuentas `visibles`.
- [X] T012 [US1] Vista `resources/views/tesoreria/saldos.blade.php`: 3 bloques (verde/rojo/celeste), Disponible con columnas Cajas/Bancos y Total general, control "Buscar por Fecha", botón "Movimiento entre Cuentas" e ícono de ajustes. Extiende `layouts.default`.
- [X] T013 [US1] `resources/js/tesoreria.js` (base): refresco AJAX de saldos al cambiar la fecha de corte (endpoint `tesoreria.saldos.data`), Toastr. Registrar el JS por pagelevel.

**Checkpoint**: US1 funcional — se ve el estado financiero consolidado. MVP entregable.

---

## Phase 4: User Story 2 — Administrar cuentas (Priority: P1)

**Goal**: CRUD de cuentas por modales (tipo inmutable, ocultar, cuentas del sistema, bloqueo de borrado).

**Independent Test**: crear cuenta Efectivo $1.000 → aparece en config y en Saldos→Cajas con su
movimiento de saldo inicial; editar (tipo bloqueado); ocultar; cuenta del sistema no editable; borrar
con movimientos → 422.

### Tests for User Story 2 ⚠️

- [X] T014 [P] [US2] `tests/Feature/TesoreriaCuentaTest.php`: alta con saldo inicial genera 1 movimiento `saldo_inicial`; `update` no cambia `tipo`; ocultar quita de visibles; `es_sistema` no editable ni eliminable (422); `destroy` bloqueado con operaciones, permitido sin ellas (SC-004, SC-007).

### Implementation for User Story 2

- [X] T015 [P] [US2] `app/Http/Requests/StoreCuentaTesoreriaRequest.php` (nombre, tipo in enum, saldo_inicial, saldo_inicial_fecha) y `app/Http/Requests/UpdateCuentaTesoreriaRequest.php` (sin `tipo`; `visible`; `authorize` deniega si `es_sistema`).
- [X] T016 [US2] `app/Http/Controllers/CuentaTesoreriaController.php`: `store` (usa `Tesoreria::registrarSaldoInicial`), `update`, `destroy` (bloquea por `tieneOperaciones()`/`es_sistema`) — todo JSON. Depende de T007, T015.
- [X] T017 [US2] `TesoreriaController@configCuentas` (GET `/tesoreria/config/cuentas`, JSON DataTables agrupado por tipo con estado Visible / (Cuenta del sistema)).
- [X] T018 [US2] Vista `resources/views/tesoreria/_config_cuentas.blade.php` (tabla Ajustes Cuentas Tesorería agrupada por tipo) + `resources/views/tesoreria/_modal_cuenta.blade.php` (alta/edición; en edición el select Tipo deshabilitado; radios Mostrar/Ocultar).
- [X] T019 [US2] En `resources/js/tesoreria.js`: DataTable de config, modal cuenta AJAX (alta/edición/eliminar), Select2 donde aplique, Toastr, refresco de la tabla y de Saldos sin recargar.

**Checkpoint**: US1 + US2 funcionan — se pueden modelar las cuentas reales del negocio.

---

## Phase 5: User Story 3 — Transferencias (Priority: P2)

**Goal**: Movimiento entre Cuentas con partida doble, viendo saldos en el selector.

**Independent Test**: transferir $500 Caja del Local → Caja Chica; ambos saldos cambian, Total
Disponible NO; validaciones (misma cuenta, monto ≤ 0) rechazadas.

### Tests for User Story 3 ⚠️

- [X] T020 [P] [US3] `tests/Feature/TesoreriaTransferenciaTest.php`: `transferir()` crea 2 filas con mismo `transferencia_id` y signos opuestos; **Total Disponible idéntico antes/después** (SC-002); rechazo de salida=entrada y monto ≤ 0; borrar transferencia revierte ambas patas (FR-024).

### Implementation for User Story 3

- [X] T021 [P] [US3] `app/Http/Requests/StoreTransferenciaRequest.php` (fecha, monto>0, cuenta_salida_id exists, cuenta_entrada_id exists+different).
- [X] T022 [US3] `TesoreriaController@transferir` (POST `/tesoreria/transferencias`, usa `Tesoreria::transferir`) + endpoint `tesoreria.cuentas.opciones` (Select2 con saldo por cuenta — FR-017). Depende de T007, T021.
- [X] T023 [US3] Vista `resources/views/tesoreria/_modal_transferencia.blade.php` (Fecha, Monto, cuenta salida/entrada, Observación).
- [X] T024 [US3] En `resources/js/tesoreria.js`: modal de transferencia AJAX, Select2 `ajax` a `tesoreria.cuentas.opciones` mostrando saldo en el texto de la opción, `dropdownParent` = modal; refresco de saldos al confirmar; Toastr.

**Checkpoint**: US1–US3 — el dinero se puede mover entre cuentas con integridad de partida doble.

---

## Phase 6: User Story 4 — Ficha/ledger de cuenta (Priority: P2)

**Goal**: libro mayor por cuenta con saldo corrido, filtros por tipo de operación, columnas y export.

**Independent Test**: ficha de una cuenta muestra columnas del relevamiento con balance corrido
consistente fila a fila; filtrar por tipo no altera el saldo; export funciona.

### Tests for User Story 4 ⚠️

- [X] T025 [P] [US4] Extender `tests/Feature/TesoreriaSaldosLedgerTest.php`: balance corrido consistente fila a fila (SC-005); filtro por tipo de operación no altera el saldo corrido histórico; menú de fila sólo Editar/Eliminar.

### Implementation for User Story 4

- [X] T026 [US4] `CuentaTesoreriaController@show` (GET `/tesoreria/cuentas/{cuenta}`) + `@data` (GET `.../data`, DataTables server-side con balance corrido vía `SUM(monto) OVER (PARTITION BY cuenta ORDER BY fecha,id)`; filtro `?tipo_operacion`). Depende de T006.
- [X] T027 [US4] Vista `resources/views/tesoreria/cuenta.blade.php`: cabecera (nombre, Filtros, rango de fechas default último mes, selector de columnas, botón Movimiento entre Cuentas) + tabla ledger (Id, Fecha, Operación, Detalles, Ingreso, Egreso, Balance resaltado, N° Factura, Observación).
- [X] T028 [US4] `CuentaTesoreriaController@update`/`@destroy` de movimientos nativos (editar fecha/monto/obs; borrar transferencia = ambas patas por `transferencia_id`); export planilla `@export`.
- [X] T029 [US4] En `resources/js/tesoreria.js`: DataTable del ledger (server-side), filtro por tipo de operación, selector de columnas, menú de fila Editar/Eliminar (AJAX), botón Exportar; link a la ficha desde el nombre de cuenta en Saldos.

**Checkpoint**: US1–US4 — auditoría completa por cuenta (extracto con saldo corrido).

---

## Phase 7: User Story 5 — Informe Movimientos (flujo de caja) (Priority: P3)

**Goal**: informe consolidado por rango con Total Cobros/Pagos/Resultado, desglose por cuenta con
checkboxes, export planilla y PDF (modal compartido).

**Independent Test**: elegir rango → resumen correcto; expandir Cobros/Pagos; tildar/destildar cuentas
recalcula el total en vivo; Exportar a PDF abre el modal compartido.

### Tests for User Story 5 ⚠️

- [X] T030 [P] [US5] `tests/Feature/TesoreriaFlujoTest.php`: `Tesoreria::flujo(desde,hasta,cuentasActivas)` computa Cobros = cobros + otros ingresos, Pagos = pagos + gastos **excluyendo gastos pendientes** (FR-028), Resultado = Cobros − Pagos; el filtro de cuentas activas afecta sólo el total mostrado. (Con datos sembrados manualmente, ya que no existen los generadores todavía.)

### Implementation for User Story 5

- [X] T031 [US5] `TesoreriaController@movimientos` (GET `/tesoreria/movimientos`) + `@movimientosData` (JSON: totales + desglose por cuenta de Cobros/Pagos para el rango). Depende de T007.
- [X] T032 [US5] Vista `resources/views/tesoreria/movimientos.blade.php`: banner explicativo (qué contempla), selector de rango, resumen (Total Cobros/Pagos/Resultado), secciones expandibles Cobros/Pagos con checkbox "Activo" por cuenta, botones Exportar y Exportar a PDF.
- [X] T033 [US5] `TesoreriaController@movimientosPdf` (GET `/tesoreria/movimientos/pdf`, `Content-Disposition: inline`) + vista `resources/views/tesoreria/pdf/movimientos.blade.php` (dompdf).
- [X] T034 [US5] En `resources/js/tesoreria.js`: expandir/colapsar secciones, recálculo en vivo de totales al cambiar checkboxes, Exportar a PDF vía `window.AppPdf.abrir` (fallback `window.open`).

**Checkpoint**: las 5 user stories funcionan independientemente.

---

## Phase 8: Polish & Cross-Cutting Concerns

- [X] T035 [P] Actualizar `docs/modelo_datos.md`: mover `cuentas_tesoreria` y `movimientos_tesoreria` de §6 (descartadas) a sección implementada, con el esquema final (Principio I).
- [X] T036 [P] Actualizar `docs/documentacion_principal_crm.md`: agregar sección "Módulo Tesorería" (Saldos, Movimientos, Ficha, config de cuentas, transferencias) y sacarlo de §6 pendientes.
- [X] T037 [P] Actualizar `CREDENCIALES_ACCESO.txt` si se creó/usó algún acceso para pruebas manuales del módulo.
- [X] T038 Verificación de estilo/consistencia UI contra `docs/informe_contagram_tesoreria.md` (columnas exactas del ledger, orden de bloques, textos) — ajustar divergencias.
- [X] T039 Ejecutar `quickstart.md` de punta a punta (Escenarios 1–5) y `php artisan test --filter=Tesoreria` en verde.

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: sin dependencias.
- **Foundational (Phase 2)**: depende de Setup. **BLOQUEA** todas las user stories.
- **US1 (Phase 3)**: depende de Foundational. MVP.
- **US2 (Phase 4)**: depende de Foundational; comparte el servicio y modelos con US1.
- **US3 (Phase 5)**: depende de Foundational; usa cuentas creadas (US2) para probar, pero es testeable con el seed.
- **US4 (Phase 6)**: depende de Foundational; se apoya en movimientos (US1 saldo inicial + US3 transferencias) para datos ricos, pero es testeable con el seed.
- **US5 (Phase 7)**: depende de Foundational; su valor pleno depende de módulos futuros (cobros/pagos) — testeable con datos sembrados.
- **Polish (Phase 8)**: depende de las user stories deseadas completas.

### Within Each User Story

- Tests primero (deben fallar), luego modelos → servicio → controlador → vista → JS.
- El servicio `Tesoreria` (T007) es prerequisito de casi todo (partida doble/saldos centralizados).

### Parallel Opportunities

- T003/T004 (rutas/sidebar) en paralelo con las migraciones.
- T005/T006 (modelos) en paralelo; T008 (factories) en paralelo.
- Tests de cada US ([P]) en paralelo entre sí.
- Las vistas Blade de distintas US son archivos distintos → paralelizables una vez está el backend.
- Nota: `resources/js/tesoreria.js` es un **único archivo** tocado por US1–US5 (T013, T019, T024, T029, T034) → esas ediciones son secuenciales entre sí (no marcar [P] cruzado).

---

## Implementation Strategy

### MVP First (US1)

1. Phase 1 (Setup) → Phase 2 (Foundational) → Phase 3 (US1 Saldos).
2. **STOP & VALIDATE**: `/tesoreria` muestra saldos correctos (Escenario 1 del quickstart).

### Incremental Delivery

1. Setup + Foundational → base lista.
2. US1 (Saldos) → MVP: ver estado financiero.
3. US2 (Cuentas) → modelar cuentas reales.
4. US3 (Transferencias) → mover dinero con partida doble.
5. US4 (Ficha/ledger) → auditoría por cuenta.
6. US5 (Informe Movimientos) → reporte de flujo de caja.
7. Polish → docs + validación.

---

## Notes

- [P] = archivos distintos, sin dependencias. `tesoreria.js` NO es [P] entre US (mismo archivo).
- Principio IV: los tests de dinero (saldos, partida doble, balance corrido) van en verde antes de
  cerrar cada US correspondiente.
- Habilita el spec 008 (Ingresos): `Tesoreria::registrarMovimiento()` (T007) es el punto de enganche
  para los cobros de Ventas y Otros Ingresos.
- Commit por tarea o grupo lógico; los cambios de dominio (Phase 8) incluyen la actualización de docs.
