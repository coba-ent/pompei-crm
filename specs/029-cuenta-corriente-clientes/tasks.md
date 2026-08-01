---

description: "Task list for feature 029: Cuenta Corriente Clientes"
---

# Tasks: Cuenta Corriente Clientes

**Input**: Design documents from `/specs/029-cuenta-corriente-clientes/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/endpoints.md, quickstart.md

**Tests**: Constitución IV exige tests para cuenta corriente/saldos — se incluyen como parte de Foundational y de cada historia (no son opcionales en esta feature).

**Organization**: Tareas agrupadas por historia de usuario.

## Format: `[ID] [P?] [Story] Description`

---

## Phase 1: Setup

- [X] T001 Agregar rutas en `routes/web.php`: `GET informes/cuenta-corriente` → `Informes\CuentaCorrienteController@index` (`informes.cuenta-corriente.index`), `GET informes/cuenta-corriente/saldos` → `@saldosData` (`informes.cuenta-corriente.saldos.data`), `GET informes/cuenta-corriente/movimientos` → `@movimientosData` (`informes.cuenta-corriente.movimientos.data`), agrupadas junto a las rutas de `informes.stock.*` ya existentes.
- [X] T002 [P] Agregar ítem "Cuenta Corriente" al submenú "Informes" en `resources/views/elements/sidebar.blade.php`, junto a "Stock" (research.md R3), con su `@can` correspondiente si aplica el mismo esquema de permisos que el resto de Informes.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: El cálculo de aging por cliente y el listado combinado de movimientos son la base que ambas historias consumen.

**⚠️ CRITICAL**: No se puede implementar ninguna historia sin esta fase completa

- [X] T003 En `app/Services/Tesoreria/CuentaCorriente.php`, agregar método `porCliente(string $tipo, ?Carbon $fecha = null): \Illuminate\Support\Collection` que reutiliza la misma lógica de buckets que `aging()` (research.md R1) pero acumulando por `cliente_id`/`proveedor_id` en vez de en un total único; devuelve una colección de arrays `{cliente_id, cliente_nombre, a_vencer, vencido_0_30, vencido_31_60, vencido_61_90, vencido_mas_90, total}` (data-model.md), excluyendo clientes con `total` ≈ 0 (FR-002).
- [X] T004 [P] Test unitario en `tests/Unit/CuentaCorrientePorClienteTest.php`: dado un cliente con una Venta vencida hace 45 días y otro con una Venta a vencer, `porCliente('cliente')` devuelve los montos en los buckets correctos (`vencido_31_60` y `a_vencer` respectivamente) y un cliente 100% cobrado no aparece en el resultado (Constitución IV).
- [X] T005 [P] Test unitario: la suma de `total` de `porCliente('cliente')` sobre todos los clientes coincide con `aging('cliente')['total']` (el agregado global ya existente, misma fuente de cálculo que el Dashboard) — invariante que sostiene SC-003 frente al Dashboard. No aplica ni se testea contra el "Total A Cobrar" de Tesorería (research.md R6: cálculo independiente, sin invariante de código esperada).
- [X] T006 Crear `app/Http/Controllers/Informes/CuentaCorrienteController.php` con `index(Request $request)` (shell, `CurrentPage = 'informe-cuenta-corriente'`) y un método privado `queryMovimientos(): \Illuminate\Database\Query\Builder` que arma la UNION de Venta/Cobro/NotaCreditoDebito vía `DB::query()->fromSub(...)` (research.md R2, mismo patrón que `InformeStockController::baseQuery()`), proyectando las columnas de `data-model.md` (`id`, `fecha_emision`, `cliente_id`, `operacion`, `categoria`, `total_venta`, `cobrado`, `a_cobrar`, `nro_comprobante`, `medio_cobro`, `descripcion`).
- [X] T007 [P] Test de integración en `tests/Feature/CuentaCorrienteMovimientosQueryTest.php`: crea una Venta con un Cobro parcial y una Nota de Crédito asociada, y verifica que `queryMovimientos()` (via el endpoint `movimientos.data`, T010) devuelve 3 filas (`venta`, `cobro`, `nota_credito`) con las columnas esperadas y los `null` correctos por tipo (Constitución IV).

**Checkpoint**: cálculo de aging por cliente y query de movimientos listos y testeados — las dos historias pueden implementarse.

---

## Phase 3: User Story 1 - Ver de un vistazo qué clientes deben plata y hace cuánto (Priority: P1) 🎯 MVP

**Goal**: Tab "Saldos Clientes" funcional: tabla con aging por cliente, ordenable por Total, filtrable por Cliente.

**Independent Test**: Ver `quickstart.md` Escenario 1.

### Implementation for User Story 1

- [X] T008 [US1] Implementar `CuentaCorrienteController::saldosData(Request $request): JsonResponse` que llama a `CuentaCorriente::porCliente('cliente', ...)`, aplica el filtro `cliente_id` si viene, y sirve el resultado paginado/ordenable en formato DataTables (usar `DataTables::collection()` dado que el origen es una Collection en memoria, no un query builder — ver research.md R1 sobre por qué el cálculo queda en PHP por ahora).
- [X] T009 [US1] Crear `resources/views/informes/cuenta-corriente/index.blade.php`: shell con tabs Bootstrap "Saldos Clientes" (activo por defecto) / "Movimientos" (estructura calcada de `resources/views/tesoreria/saldos.blade.php` que ya usa el mismo patrón de tabs), con el tab "Saldos Clientes" conteniendo: filtro Cliente (Select2 `ajax` contra `clientes.opciones`, con opción "Todos") y tabla `#tabla-saldos-clientes` (columnas: Cliente, A Vencer, 0 y 30, 31 y 60, 61 y 90, >90, Total).
- [X] T010 [US1] Crear `resources/js/informe-cuenta-corriente.js`: inicializa DataTable server-side sobre `informes.cuenta-corriente.saldos.data` con `order` por defecto ascendente en Cliente, columna Total ordenable (FR-003), formateo de moneda en las columnas de importe, y filtro Cliente que recarga la tabla en `select2:select`/`select2:clear`.
- [X] T011 [US1] Registrar la ruta `informes.cuenta-corriente.index` en el controller (`index()` ya creado en T006) pasando a la vista los datos iniciales necesarios (lista de clientes para el Select2 si no se resuelve 100% por `ajax`), y cablear `@vite(['resources/js/informe-cuenta-corriente.js'])`.
- [X] T012 [US1] Test de integración en `tests/Feature/CuentaCorrienteSaldosClientesTest.php`: request a `informes.cuenta-corriente.saldos.data` con un cliente con deuda vencida hace 45 días → la fila trae el monto en `vencido_31_60` y en `total`; filtrado por `cliente_id` acota a un solo cliente; ordenar por `total` desc devuelve el orden esperado (Constitución IV, cubre SC-001/SC-003).

**Checkpoint**: User Story 1 funcional — validar con `quickstart.md` Escenario 1 y 3.

---

## Phase 4: User Story 2 - Ver el detalle de movimientos que componen la deuda de un cliente (Priority: P2)

**Goal**: Tab "Movimientos" funcional: listado combinado Venta/Cobro/Nota, filtrable por Cliente, Operación y rango de fechas.

**Independent Test**: Ver `quickstart.md` Escenario 2.

### Implementation for User Story 2

- [X] T013 [US2] Implementar `CuentaCorrienteController::movimientosData(Request $request): JsonResponse` sobre `queryMovimientos()` (T006), aplicando filtros `cliente_id`, `operacion`, `fecha_desde`/`fecha_hasta` (sobre `fecha_emision`) como capa externa (mismo patrón que `InformeStockController::aplicarFiltros()`), servido con `DataTables::of($query)`, orden por defecto `fecha_emision` descendente.
- [X] T014 [US2] En `resources/views/informes/cuenta-corriente/index.blade.php`, agregar el contenido del tab "Movimientos": filtros Cliente (Select2), Operación (select simple: Todos/Venta/Cobro/Nota de Crédito/Nota de Débito) y selector de rango de fechas "Emisión"; tabla `#tabla-movimientos` (columnas: Id, Emisión, Cliente, Operación, Categoría, Total Venta, Cobrado, A Cobrar, N° de Comprobante, Medio de Cobro, Descripción).
- [X] T015 [US2] En `resources/js/informe-cuenta-corriente.js`, inicializar el DataTable server-side del tab Movimientos sobre `informes.cuenta-corriente.movimientos.data`, con los 3 filtros (Cliente/Operación/rango de fechas) recargando la tabla, formateo de moneda, y renderizado de `operacion` con label legible (Venta/Cobro/Nota de Crédito/Nota de Débito).
- [X] T016 [US2] Test de integración en `tests/Feature/CuentaCorrienteMovimientosClienteTest.php`: para un cliente con Ventas + Cobros, la suma de `a_cobrar` de sus filas `operacion=venta` en `movimientos.data` (sin filtrar por fecha) coincide con el `total` de ese mismo cliente en `saldos.data` (Constitución IV, cubre SC-002).
- [X] T017 [US2] Test de integración: filtrar `movimientos.data` por `operacion=cobro` sólo devuelve filas de cobro; filtrar por `cliente_id` acota correctamente; filtrar por rango de fechas excluye operaciones fuera de rango.

**Checkpoint**: User Stories 1 y 2 funcionan de punta a punta — validar con `quickstart.md` Escenario 2 y 3.

---

## Phase 5: Polish & Cross-Cutting Concerns

- [X] T018 [P] Actualizar `docs/documentacion_principal_crm.md`: mover "Cuenta Corriente" de §7 (pendiente) a una sección propia (p. ej. §6.4 "Módulo Informe de Cuenta Corriente — Clientes"), documentando la estructura real (2 tabs, columnas, filtros) igual que ya se hizo para Tesorería (§3.7) y Dashboard (§6.3); dejar registrado que Proveedores sigue pendiente (Constitución I).
- [X] T019 Ejecutar manualmente los 3 escenarios de `specs/029-cuenta-corriente-clientes/quickstart.md` y dejar registrado el resultado: la comparación contra el Dashboard debe coincidir (SC-003, misma fuente de cálculo); la comparación contra Tesorería es informativa — si difiere, registrar la diferencia como hallazgo aparte (research.md R6), no como bug de este spec.
- [X] T020 Revisar visualmente ambos tabs contra `docs/capturas/saldos/WhatsApp Image 2026-07-30 at 7.21.55 PM (1)/(2).jpeg` (regla de oro).

---

## Dependencies & Execution Order

- **Setup (Phase 1)**: sin dependencias.
- **Foundational (Phase 2)**: depende de Setup — bloquea Phase 3 y 4.
- **User Story 1 (Phase 3)**: depende de Phase 2 (T003, T006). Independiente de US2.
- **User Story 2 (Phase 4)**: depende de Phase 2 (T006). Comparte la vista/JS (`index.blade.php`, `informe-cuenta-corriente.js`) con US1 — coordinar si se implementan en paralelo por archivo compartido.
- **Polish (Phase 5)**: depende de US1 y US2 completas.

### Parallel Opportunities

- T004/T005/T007 (tests de Foundational) son paralelos entre sí.
- T002 (sidebar) es paralelo al resto de Setup/Foundational.
- T012 (test US1) y T016/T017 (tests US2) son paralelos entre sí una vez cada historia tiene su implementación.
- T018 (Polish, docs) es paralelo a T019/T020.

## Implementation Strategy

### MVP First (User Story 1 Only)

1. Setup + Foundational.
2. User Story 1 (Saldos Clientes) → validar con quickstart Escenario 1 → ya es demostrable y resuelve el caso de uso central (SC-001, SC-003).
3. User Story 2 (Movimientos) → validar con quickstart Escenario 2 → cierra el detalle accionable (SC-002).
4. Polish → documentación y verificación final contra capturas.
