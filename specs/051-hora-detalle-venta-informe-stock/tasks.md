---

description: "Task list template for feature implementation"
---

# Tasks: Hora en Movimientos de Stock y Detalle de Venta en Informe de Stock

**Input**: Design documents from `/specs/051-hora-detalle-venta-informe-stock/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/informe-stock-data.md, quickstart.md

**Tests**: Incluidos — constitución del proyecto (principio IV) exige tests para toda lógica de
movimientos de stock; ambas historias tocan esa lógica.

**Organization**: Tareas agrupadas por historia de usuario para poder implementar y probar cada una
de forma independiente.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Puede correr en paralelo (archivos distintos, sin dependencias)
- **[Story]**: A qué historia de usuario pertenece (US1, US2)
- Se incluye el path exacto de archivo en cada descripción

## Path Conventions

Proyecto Laravel monolito único (ver plan.md → Project Structure): `app/`, `database/migrations/`,
`resources/`, `tests/Feature/` en la raíz del repo.

---

## Phase 1: Setup

**Purpose**: No hay inicialización de proyecto nueva — el feature reutiliza el stack Laravel ya
configurado. Fase sin tareas propias.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Cambio de esquema y de tipo de dato en `movimientos_stock.fecha` que ambas historias
de usuario necesitan (US1 lo usa para orden/hora, US2 lo hereda porque comparte la misma fila).

**⚠️ CRITICAL**: Ninguna historia de usuario puede implementarse antes de completar esta fase.

- [X] T001 Crear migración `database/migrations/2026_08_06_000001_add_hora_a_movimientos_stock.php` que haga `ALTER TABLE movimientos_stock MODIFY fecha DATETIME NOT NULL` (up) y revierta a `DATE NOT NULL` (down), según data-model.md
- [X] T002 Cambiar el cast `'fecha' => 'date'` a `'fecha' => 'datetime'` en `app/Models/MovimientoStock.php`
- [X] T003 En `app/Services/Stock/StockService.php`, cambiar los 4 defaults `$fecha ?: now()->toDateString()` (en `ajustar()`, `transferir()`, y los dos usos dentro de `mover()`) a `$fecha ?: now()`, **sin tocar** el caller `StockDeCompra::aplicarAlta()` que ya pasa `fecha_emision->toDateString()` explícita (research.md D1, spec.md FR-001)
- [X] T004 Correr `php artisan migrate` localmente y validar que `movimientos_stock` existentes conservan su fecha con hora `00:00:00` (quickstart.md, escenario de regresión de datos existentes)

**Checkpoint**: Esquema y modelo listos — las historias de usuario pueden implementarse.

---

## Phase 3: User Story 1 - Ver el orden real de los movimientos del día (Priority: P1) 🎯 MVP

**Goal**: Que el Informe de Stock ordene y muestre los movimientos por fecha y hora real, no sólo
por fecha, sin romper el saldo corrido existente.

**Independent Test**: Generar dos movimientos de stock el mismo día en horarios distintos (ej. una
venta a la mañana, un ajuste a la tarde) y verificar que el Informe de Stock los lista en ese orden.

### Tests for User Story 1 ⚠️

> **NOTE: Escribir estos tests PRIMERO, confirmar que fallan antes de implementar**

- [X] T005 [P] [US1] Test en `tests/Feature/InformeStockTest.php`: dos movimientos de stock creados el mismo día en horarios distintos (mockeando `now()` o seteando `fecha` directo en el registro) aparecen en la respuesta de `InformeStockController::data()` en el orden cronológico real (hora ascendente), no sólo por fecha
- [X] T006 [P] [US1] Test en `tests/Feature/InformeStockTest.php`: el `stock_saldo` calculado sigue siendo correcto (misma partición por producto/variante/depósito) cuando hay varios movimientos el mismo día con distinta hora
- [X] T007 [P] [US1] Test en `tests/Feature/StockAjusteTest.php` (o el test existente de `StockService`): al registrar un ajuste/transferencia sin `$fecha` explícita, el movimiento persiste con hora real (`fecha` no queda en `00:00:00` salvo coincidencia)
- [X] T008 [P] [US1] Test en `tests/Feature/CompraStockTest.php`: confirmar que la entrada de stock al dar de alta una Compra sigue persistiendo con hora `00:00:00` (usa `fecha_emision`, sin cambios — no debe regresionar por este feature)
- [X] T008b [P] [US1] Test en `tests/Feature/InformeStockTest.php`: los filtros existentes (`fecha_desde`/`fecha_hasta`, `usuario_id`, `operacion`, `proveedor_id`, `tipo_producto_id`, `producto_id`, `estado`) siguen devolviendo el mismo resultado que antes del cambio ahora que `mov.fecha` es `datetime` (FR-007)

### Implementation for User Story 1

- [X] T009 [US1] Verificar que `InformeStockController::data()` (`app/Http/Controllers/Informes/InformeStockController.php`) sigue ordenando por `mov.fecha, mov.id` — sin cambios de query, sólo validar que el nuevo tipo `datetime` no rompe el `->order()` ni el `whereDate('mov.fecha', ...)` de `aplicarFiltros()` (filtros de fecha deben seguir funcionando por día completo)
- [X] T010 [US1] Confirmar en `resources/js/informe-stock.js` que el `render` de la columna `fecha` (línea ~146, `String(val).slice(0, 10)...`) sigue mostrando `DD/MM/YYYY` correctamente con el string `datetime` más largo que ahora devuelve el backend (sin cambios de código esperados, sólo verificación manual/visual)

**Checkpoint**: El Informe de Stock ordena y calcula el saldo corrido por fecha+hora real, sin
romper filtros ni la visualización de fecha existente.

---

## Phase 4: User Story 2 - Identificar de un vistazo a qué venta corresponde un movimiento (Priority: P2)

**Goal**: Que la columna "Detalle" del Informe de Stock muestre comprobante + cliente cuando el
movimiento proviene de una Venta, sin alterar el detalle de otros orígenes.

**Independent Test**: Generar una venta con cliente que descuente stock, ir al Informe de Stock y
verificar que la fila muestra tipo+número de comprobante y cliente en la columna Detalle.

### Tests for User Story 2 ⚠️

- [X] T011 [P] [US2] Test en `tests/Feature/InformeStockTest.php`: una venta con cliente asignado que descuenta stock produce un movimiento cuya columna `detalle` en la respuesta JSON es `"{tipo_comprobante} {nro_comprobante} - {cliente.nombre}"`
- [X] T012 [P] [US2] Test en `tests/Feature/InformeStockTest.php`: una venta sin cliente asignado produce `detalle = "{tipo_comprobante} {nro_comprobante}"` (sin el segmento de cliente, sin "null" ni error)
- [X] T013 [P] [US2] Test en `tests/Feature/InformeStockTest.php`: al eliminar una venta (reintegro de stock), el movimiento de entrada generado también trae el mismo `detalle` de la venta de origen
- [X] T014 [P] [US2] Test en `tests/Feature/InformeStockTest.php`: movimientos de Compra, ajuste manual y transferencia devuelven `detalle` idéntico al `descripcion` actual (no regresión — FR-006, SC-003)
- [X] T015 [P] [US2] Test en `tests/Feature/Integraciones/MercadoLibreConversionTest.php`: un movimiento generado por una venta de Mercado Libre también resuelve `detalle` con comprobante + cliente (mismo criterio que venta manual, research.md D4)

### Implementation for User Story 2

- [X] T016 [US2] En `InformeStockController::baseQuery()` (`app/Http/Controllers/Informes/InformeStockController.php`), agregar `leftJoin('ventas', ...)` condicionado a `mov.origen_type = 'App\\Models\\Venta' AND ventas.id = mov.origen_id`, y `leftJoin('clientes', 'clientes.id', '=', 'ventas.cliente_id')`, según data-model.md
- [X] T017 [US2] En el mismo método, agregar la columna calculada `detalle` (SQL `CASE`/`COALESCE`: si hay `ventas.id`, arma `"{tipo_comprobante} {nro_comprobante}"` + `" - {cliente.nombre}"` cuando hay cliente; si no, usa `mov.descripcion`) al `select()` de la subconsulta
- [X] T018 [US2] Ajustar `resources/js/informe-stock.js`: la columna que hoy usa `data: 'descripcion'` (línea ~155) pasa a `data: 'detalle', defaultContent: ''`, según contracts/informe-stock-data.md

**Checkpoint**: La columna Detalle enriquece movimientos de Venta sin regresionar otros orígenes.

---

## Phase 5: Polish & Cross-Cutting Concerns

**Purpose**: Cierre del feature — validación end-to-end y consistencia documental.

- [X] T019 [P] Correr `php artisan test --filter=InformeStockTest`, `--filter=MovimientoStockObserverTest`, `--filter=VentaStockTest`, `--filter=CompraStockTest` y confirmar suite en verde
- [X] T020 Ejecutar manualmente los 3 escenarios de `quickstart.md` contra la app local (orden por hora, detalle de venta con/sin cliente, regresión en compra/ajuste)
- [X] T021 [P] Revisar que `docs/documentacion_principal_crm.md` §6.2 y `docs/modelo_datos.md` (ya actualizados en la fase de plan) queden consistentes con el comportamiento final implementado; ajustar si algo cambió durante la implementación

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: vacía, no bloquea nada
- **Foundational (Phase 2)**: BLOQUEA Phase 3 y Phase 4 — la migración y el cambio de cast son
  prerequisito de ambas historias
- **User Story 1 (Phase 3)**: depende sólo de Foundational
- **User Story 2 (Phase 4)**: depende sólo de Foundational — es independiente de US1 (distinto
  código: US1 toca orden/hora, US2 toca el join de Detalle), pero comparte la migración de T001-T003
- **Polish (Phase 5)**: depende de que Phase 3 y Phase 4 estén completas

### Parallel Opportunities

- T005-T008 (tests de US1) se pueden escribir en paralelo entre sí
- T011-T015 (tests de US2) se pueden escribir en paralelo entre sí
- Una vez completada la Fase Foundational, US1 (Phase 3) y US2 (Phase 4) pueden implementarse en
  paralelo por desarrolladores distintos — no comparten archivos de implementación (US1 no toca
  `baseQuery()` más que verificarlo; US2 sí lo edita)

---

## Implementation Strategy

### MVP First (User Story 1 only)

1. Completar Phase 1 (vacía) + Phase 2 (Foundational)
2. Completar Phase 3 (User Story 1) → validar orden por hora funcionando
3. Deploy/demo si se quiere entregar sólo la corrección de orden primero

### Incremental Delivery

1. Foundational → base lista
2. US1 → orden por fecha+hora → validar → demo
3. US2 → detalle de venta en la columna → validar → demo
4. Polish → suite completa + docs

---

## Notes

- [P] = archivos distintos, sin dependencias entre sí
- Escribir los tests de cada historia primero y confirmar que fallan antes de implementar (principio IV de la constitución)
- No se toca la firma pública de `StockService` ni de `InformeStockController::data()` (misma ruta, mismos parámetros de filtro)
- Evitar: cambiar el criterio de desempate existente (`mov.id`) para movimientos sin hora real distinguible
