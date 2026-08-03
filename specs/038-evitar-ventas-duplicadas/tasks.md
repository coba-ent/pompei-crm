---

description: "Task list for 038-evitar-ventas-duplicadas"

---

# Tasks: Evitar ventas duplicadas por reconversión de órdenes de Mercado Libre y Tiendanube

**Input**: Design documents from `/specs/038-evitar-ventas-duplicadas/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, quickstart.md

**Tests**: Incluidos — la conversión mueve dinero (cobro en Tesorería) y stock, lo que activa el principio IV de la constitución ("Testing donde hay dinero o impacto fiscal").

**Organization**: Tareas agrupadas por user story (US1 = red de seguridad anti-duplicados, US2 = bloqueo de borrado).

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Puede ejecutarse en paralelo (archivos distintos, sin dependencias)
- **[Story]**: A qué user story pertenece (US1, US2)
- Rutas de archivo exactas en cada descripción

## Path Conventions

Laravel monolito existente (single project): `app/`, `database/migrations/`, `tests/Feature/` en la raíz del repo.

---

## Phase 1: Setup

**Purpose**: Migración de esquema compartida por ambas user stories

- [X] T001 Crear migración `database/migrations/<timestamp>_add_ml_tn_order_id_to_ventas_table.php` que agrega a `ventas` las columnas `ml_order_id` (string, nullable, unique) y `tn_order_id` (string, nullable, unique) — ver data-model.md

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Cambios base que ambas user stories necesitan antes de implementar su lógica

**⚠️ CRITICAL**: Ninguna user story puede implementarse antes de completar esta fase

- [X] T002 Ejecutar `php artisan migrate` y agregar `ml_order_id`, `tn_order_id` a `$fillable` en `app/Models/Venta.php`
- [X] T003 [P] Agregar método `tieneVentaAsociada(): bool` a `app/Models/Integraciones/MercadoLibreOrden.php` (equivalente a `!is_null($this->venta_id)`, mismo patrón que `CuentaTesoreria::tieneOperaciones()`)
- [X] T004 [P] Agregar método `tieneVentaAsociada(): bool` a `app/Models/Integraciones/TiendanubeOrden.php` (idéntico a T003)

**Checkpoint**: Esquema y modelos listos — las dos user stories pueden implementarse

---

## Phase 3: User Story 1 - La conversión rechaza un pedido que ya generó una Venta, aunque la orden se haya borrado y resincronizado (Priority: P1) 🎯 MVP

**Goal**: Ninguna conversión (manual o automática) de una orden de Mercado Libre o Tiendanube puede generar una segunda Venta para el mismo pedido de origen, incluso si la orden fue borrada y resincronizada.

**Independent Test**: Con una Venta existente originada en un pedido de ML/Tiendanube con identificador conocido, intentar convertir otra orden con el mismo identificador de pedido de origen debe rechazarse sin crear una segunda Venta ni afectar Tesorería/stock (Escenario 1 y 2 de quickstart.md).

### Tests for User Story 1 ⚠️

> Escribir estos tests PRIMERO, confirmar que fallan antes de implementar

- [X] T005 [P] [US1] Feature test en `tests/Feature/MercadoLibre/ConversionDuplicadaTest.php`: convertir una orden ML cuyo `ml_order_id` ya tiene una Venta asociada (con la fila de `ml_ordenes` original borrada/recreada) debe rechazarse sin crear una segunda Venta, sin cobro nuevo en Tesorería ni movimiento de stock nuevo
- [X] T006 [P] [US1] Feature test en `tests/Feature/MercadoLibre/ConversionDuplicadaTest.php`: convertir una orden ML con `ml_order_id` que nunca generó Venta se completa con normalidad y la Venta queda con `ml_order_id` cargado
- [X] T007 [P] [US1] Feature test en `tests/Feature/MercadoLibre/ConversionDuplicadaTest.php`: una Venta con `ml_order_id` soft-deleted sigue bloqueando la reconversión del mismo pedido
- [X] T008 [P] [US1] Feature test en `tests/Feature/Tiendanube/ConversionDuplicadaTest.php`: mismos tres casos que T005-T007 para Tiendanube (`tn_order_id`)
- [X] T009 [P] [US1] Feature test en `tests/Feature/Integraciones/BackfillReferenciaPedidoVentasTest.php`: correr el comando de backfill sobre Ventas ML/Tiendanube existentes con orden vigente completa `ml_order_id`/`tn_order_id`; Ventas cuya orden de origen ya no existe quedan sin tocar

### Implementation for User Story 1

- [X] T010 [US1] En `app/Services/MercadoLibre/ConversorOrdenAVenta.php::convertirBajoCandado()`, antes de crear la Venta, verificar `Venta::withTrashed()->where('ml_order_id', $orden->ml_order_id)->exists()` y rechazar con el mismo mensaje que el rechazo existente por `orden.venta_id` ("Esta orden ya tiene una Venta asociada.") si ya existe
- [X] T011 [US1] En el mismo método, al crear la `Venta`, incluir `'ml_order_id' => $orden->ml_order_id` en el `Venta::create([...])`
- [X] T012 [US1] [P] Repetir T010 y T011 en `app/Services/Tiendanube/ConversorOrdenAVenta.php` con `tn_order_id`
- [X] T013 [US1] Crear comando artisan `app/Console/Commands/BackfillReferenciaPedidoVentas.php` (`ventas:backfill-referencia-pedido`) que recorre `MercadoLibreOrden::whereNotNull('venta_id')` y `TiendanubeOrden::whereNotNull('venta_id')` y completa `ventas.ml_order_id`/`tn_order_id` cuando estén vacíos, según research.md R4

**Checkpoint**: User Story 1 funcional y testeable de forma independiente — es el MVP de esta feature

---

## Phase 4: User Story 2 - El sistema impide borrar una orden que ya tiene una Venta asociada (Priority: P2)

**Goal**: No se puede eliminar una orden de Mercado Libre o Tiendanube con `venta_id` cargado por ningún camino de borrado existente en la app.

**Independent Test**: Intentar eliminar una orden con Venta asociada debe rechazarse con mensaje claro; una orden sin Venta asociada debe poder borrarse sin cambios respecto del comportamiento actual (Escenario 3 de quickstart.md).

### Tests for User Story 2 ⚠️

- [X] T014 [P] [US2] Feature test en `tests/Feature/Integraciones/BorradoOrdenConVentaTest.php`: eliminar una `MercadoLibreOrden` con `venta_id` cargado se rechaza y la fila sigue existiendo
- [X] T015 [P] [US2] Feature test en `tests/Feature/Integraciones/BorradoOrdenConVentaTest.php`: eliminar una `MercadoLibreOrden` sin `venta_id` se completa sin restricciones
- [X] T016 [P] [US2] Feature test en `tests/Feature/Integraciones/BorradoOrdenConVentaTest.php`: mismos dos casos que T014-T015 para `TiendanubeOrden`

### Implementation for User Story 2

- [X] T017 [US2] Hoy no existe ruta HTTP `destroy` para `ml_ordenes`/`tn_ordenes` (confirmado en research.md R2) — el único camino de borrado real es manual (tinker/script). Envolver ese camino en un método explícito reutilizable: agregar `eliminarSiSinVenta(): bool` (o excepción de dominio) a `MercadoLibreOrden`/`TiendanubeOrden` que internamente valida `tieneVentaAsociada()` (T003/T004) antes de `->delete()` y rechaza con "La orden tiene una Venta asociada; desvinculá o eliminá la Venta antes de borrarla." (mismo patrón de mensaje que `CuentaTesoreriaController::destroy()`), para que valga tanto para el borrado manual actual como para un futuro endpoint/UI que lo reutilice sin volver a implementar la regla

**Checkpoint**: User Stories 1 y 2 funcionan juntas de forma independiente

---

## Phase 5: Polish & Cross-Cutting Concerns

**Purpose**: Cierre de la feature

- [X] T018 Ejecutar manualmente los tres escenarios de `quickstart.md` contra el ambiente de desarrollo local (no VPS/demo) antes de considerar la feature lista para deploy
- [X] T019 Revisar que `docs/documentacion_principal_crm.md` y `docs/modelo_datos.md` (ya actualizados en esta ronda de spec-kit) sigan reflejando fielmente lo implementado tras el paso anterior; ajustar si algo cambió durante la implementación

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: sin dependencias — arranca de inmediato
- **Foundational (Phase 2)**: depende de Setup — bloquea ambas user stories
- **User Story 1 (Phase 3)**: depende de Foundational — es el MVP
- **User Story 2 (Phase 4)**: depende de Foundational — independiente de US1 (usa T003/T004, no T010-T013)
- **Polish (Phase 5)**: depende de que ambas user stories estén completas

### User Story Dependencies

- **US1 (P1)**: puede arrancar apenas termina Foundational — sin dependencia de US2
- **US2 (P2)**: puede arrancar apenas termina Foundational — sin dependencia de US1 (comparte sólo los modelos de Phase 2, no el código de conversión)

### Parallel Opportunities

- T003 y T004 en paralelo (archivos de modelo distintos)
- T005-T009 en paralelo entre sí (archivos de test distintos), todos antes de T010-T013
- T010-T011 (ML) en paralelo con T012 (Tiendanube) — archivos de servicio distintos
- T014-T016 en paralelo entre sí (mismo archivo de test, pero casos independientes — evaluar si conviene un solo archivo con varios test methods en vez de tareas separadas al implementar)
- US1 completa y US2 completa pueden desarrollarse en paralelo por dos personas distintas una vez cerrado Phase 2

---

## Parallel Example: User Story 1

```bash
# Tests de User Story 1 en paralelo:
Task: "Feature test conversión duplicada ML en tests/Feature/MercadoLibre/ConversionDuplicadaTest.php"
Task: "Feature test conversión duplicada Tiendanube en tests/Feature/Tiendanube/ConversionDuplicadaTest.php"
Task: "Feature test backfill en tests/Feature/Integraciones/BackfillReferenciaPedidoVentasTest.php"

# Implementación ML y Tiendanube en paralelo (archivos de servicio distintos):
Task: "Chequeo de duplicado + guardar ml_order_id en app/Services/MercadoLibre/ConversorOrdenAVenta.php"
Task: "Chequeo de duplicado + guardar tn_order_id en app/Services/Tiendanube/ConversorOrdenAVenta.php"
```

---

## Implementation Strategy

### MVP First (User Story 1 Only)

1. Completar Phase 1: Setup (migración)
2. Completar Phase 2: Foundational (modelos + fillable)
3. Completar Phase 3: User Story 1 (red de seguridad anti-duplicados)
4. **Parar y validar**: correr Escenarios 1 y 2 de quickstart.md
5. Esto ya cierra el riesgo más grave (ventas duplicadas); User Story 2 puede seguir después

### Incremental Delivery

1. Setup + Foundational → base lista
2. User Story 1 → validar independientemente → esto es el MVP real de la feature
3. User Story 2 → validar independientemente → cierra el camino más común hacia el problema
4. Polish → correr quickstart.md completo, confirmar docs actualizados

---

## Notes

- No hay tareas [P] que toquen el mismo archivo simultáneamente salvo T014-T016 (mismo archivo de test) — al implementar, evaluar si conviene un único PR/commit para ese archivo en vez de forzar el paralelismo literal.
- Cada Venta creada en los tests de US1 debe verificarse contra Tesorería (cobro registrado) y stock (movimiento aplicado) para confirmar que NO se duplican — no alcanza con contar filas de `ventas`.
- Detener y validar en cada checkpoint antes de seguir a la fase siguiente.
