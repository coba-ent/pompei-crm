# Tasks — Caja editable + modales de medio de pago (spec 111)

**Branch**: `111-tesoreria-caja-editable` | **Fecha**: 2026-09-25
**Docs**: [spec.md](spec.md) · [plan.md](plan.md)

**Tests**: OBLIGATORIOS (principio IV): mueve saldos entre cajas.
**Migraciones**: ninguna.

---

## Phase 1: Setup

- [x] T001 Tomar la línea de base: suma total y cantidad de `movimientos_tesoreria` vivos en la base local, para comparar al final (FR-014 / SC-003)
- [x] T002 Verificar contra la base que las transferencias tienen 2 patas de signos opuestos, que es de donde el modal deduce "Sale de"/"Entra a" ([plan.md](plan.md) D2) — **ya hecho: 33/33 correctas**

---

## Phase 2: Backend — reimputación (BLOQUEANTE de US1 y US2)

- [x] T003 Extender la validación de `CuentaTesoreriaController::updateMovimiento()` con `cuenta_tesoreria_id` (requerido, `exists`) y `cuenta_contraparte_id` (requerido sólo si el movimiento tiene `transferencia_id`)
- [x] T004 Agregar la validación de FR-004: rechazar con 422 que origen y destino sean la misma caja, con un mensaje que lo explique
- [x] T005 Aplicar la caja al movimiento editado dentro de la transacción existente, y —cuando hay `transferencia_id`— aplicar `cuenta_contraparte_id` a la contraparte, sin tocar el signo de su monto (el ajuste de monto/fecha que ya existe no se modifica, FR-008)
- [x] T006 Devolver en el JSON la caja resultante de cada pata, para que el ledger pueda refrescarse

---

## Phase 3: US1 — Cambiar la caja de un movimiento suelto (P1) 🎯 MVP

**Test independiente**: editar un movimiento suelto cambiándole la caja y verificar que el saldo de la caja vieja baja y el de la nueva sube por el mismo importe.

### Tests

- [x] T007 [P] [US1] Crear `tests/Feature/Tesoreria/ReimputarMovimientoTest.php` con el caso feliz: un movimiento suelto cambia de caja, la vieja baja y la nueva sube por el importe
- [x] T008 [P] [US1] Agregar el test de la invariante: tras reimputar, la **suma total** de `movimientos_tesoreria` no cambió (FR-014)
- [x] T009 [P] [US1] Agregar el test de que editar sólo fecha/monto/observación **no** cambia la caja (FR-008)
- [x] T010 [P] [US1] Agregar el test de que un movimiento **no nativo** sigue rechazándose con 422 (FR-009)

### Implementación

- [x] T011 [US1] Agregar el selector de caja a `resources/views/tesoreria/_modal_movimiento_editar.blade.php`, con su `invalid-feedback`
- [x] T012 [US1] En `resources/js/tesoreria.js`, llenar el selector con las cajas visibles ordenadas por nombre y preseleccionar la del movimiento al abrir el modal
- [x] T013 [US1] Enviar `cuenta_tesoreria_id` en el submit y mostrar los errores de validación en el modal (toast + `invalid-feedback`), sin recargar la página
- [x] T014 [US1] Exponer la caja de cada fila en el endpoint del ledger si no viene ya, para poder preseleccionarla

**Checkpoint**: el 99,4% de los movimientos editables ya se pueden reimputar.

---

## Phase 4: US2 — Cambiar las cajas de una transferencia (P1)

**Test independiente**: editar una transferencia cambiando la caja de origen y verificar que la pata de salida se movió y la de entrada quedó donde estaba.

### Tests

- [x] T015 [P] [US2] Test: cambiar sólo el origen mueve la pata de salida y deja la de entrada intacta
- [x] T016 [P] [US2] Test: cambiar las dos cajas en la misma edición actualiza ambas patas
- [x] T017 [P] [US2] Test: origen = destino se rechaza con 422 y **no** modifica ninguna pata (FR-004)
- [x] T018 [P] [US2] Test de atomicidad: si falla la segunda pata, no queda ninguna movida (FR-006)
- [x] T019 [P] [US2] Test de la invariante sobre una transferencia: la suma total de tesorería no cambia (SC-003)

### Implementación

- [x] T020 [US2] En el modal, mostrar **dos** selectores rotulados "Sale de" / "Entra a" cuando el movimiento tiene `transferencia_id`, y uno solo cuando no
- [x] T021 [US2] Derivar qué selector es origen y cuál destino **del signo del monto** (negativo = sale), y preseleccionar la caja de cada pata; pedir la contraparte al backend o resolverla desde los datos del ledger
- [x] T022 [US2] Enviar `cuenta_contraparte_id` junto a `cuenta_tesoreria_id` cuando es transferencia, y validar en el cliente que no sean iguales (además de la validación del backend)
- [x] T023 [US2] Tratar como **movimiento suelto** el caso de un `transferencia_id` cuya contraparte ya no existe (edge case de la spec)

---

## Phase 5: US3 — Modales de medio de pago (P2)

**Test independiente**: abrir el modal de cobranza y verificar orden alfabético y botones rellenos.

### Tests

- [x] T024 [P] [US3] Test: el contexto del modal de cobranza de Venta devuelve las cajas en orden alfabético (FR-010)
- [x] T025 [P] [US3] Test: ídem para el modal de pago de Compra
- [x] T026 [P] [US3] **Test de no-regresión**: las cards de Tesorería **conservan** el orden manual (FR-011) — es la verificación de que el cambio no se filtró

### Implementación

- [x] T027 [US3] Reemplazar `ordenadas()` por `orderBy('nombre')` en las 3 consultas de `VentaController` (líneas 68, 624, 731) que alimentan el modal de cobranza
- [x] T028 [US3] Ídem en las 2 consultas de `CompraController` (líneas 496, 523) del modal de pago
- [x] T029 [P] [US3] En `resources/js/ventas.js:1360`, pintar los botones como `btn-primary` (relleno) y marcar el elegido con `active` + ícono de tilde (FR-012, FR-013)
- [x] T030 [P] [US3] Ídem en `resources/js/compras.js:1047`

---

## Phase 6: Validación final

- [x] T031 Correr la suite completa y dejar en verde los tests de esta spec; verificar que las fallas restantes sean las preexistentes ya conocidas
- [x] T032 `npm run build` y validar en el navegador contra MySQL local: reimputar un movimiento suelto, reimputar una transferencia, intentar origen=destino, y ver los modales de cobranza y pago
- [x] T033 Verificar que la suma total de `movimientos_tesoreria` coincide con la línea de base de T001
- [x] T034 Verificar que las **cards de Tesorería** siguen con el orden manual y que `cuentas_tesoreria.saldo_inicial` no cambió

---

## Dependencias

```
Phase 1 (T001-T002)
   ↓
Phase 2 (T003-T006)  ← BLOQUEANTE del backend
   ↓
   ├─ Phase 3 US1 (T007-T014)  🎯 MVP
   ├─ Phase 4 US2 (T015-T023)  ← necesita el backend de US2 (T005)
   └─ Phase 5 US3 (T024-T030)  ← independiente de todo lo demás
         ↓
   Phase 6 (T031-T034)
```

## Paralelizables

- **Phase 3**: T007–T010 (tests) entre sí
- **Phase 4**: T015–T019 (tests) entre sí
- **Phase 5**: T029 y T030 (archivos distintos); toda la fase es independiente de US1/US2

## MVP

**Phase 1 + 2 + 3 (US1)** = el pedido del cliente resuelto para el 99,4% de los casos.

US3 es independiente y se puede hacer primero si se quiere una victoria rápida: son 5 líneas de
backend y 2 de JS.

## Resumen

| Fase | Tareas | Foco |
|---|---|---|
| 1. Setup | T001–T002 | Línea de base y verificación de signos |
| 2. Backend | T003–T006 | Reimputación de una o dos patas |
| 3. US1 (P1) | T007–T014 | MVP: movimiento suelto |
| 4. US2 (P1) | T015–T023 | Transferencias (las dos cajas) |
| 5. US3 (P2) | T024–T030 | Orden alfabético + botones rellenos |
| 6. Validación | T031–T034 | Suite + navegador + invariantes |

**Total: 34 tareas.**
