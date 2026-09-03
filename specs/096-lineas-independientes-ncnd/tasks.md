# Tasks: Cada línea del comprobante es un ajuste independiente en la NC/ND

**Feature**: 096-lineas-independientes-ncnd | **Date**: 2026-09-03

**Input**: [spec.md](./spec.md) · [plan.md](./plan.md) ·
[contracts/items-disponibles-por-linea.md](./contracts/items-disponibles-por-linea.md)

> **Requiere 1 migración aditiva** (nullable) sobre `nota_credito_debito_items`. Sin backfill: las
> NC/ND ya existentes no tienen forma de reconstruir la línea de origen (spec, Assumptions).

## Phase 1: Setup

- [X] T001 Línea base ANTES de tocar nada: correr `php artisan test --filter="NotaCreditoDebito"` y anotar cuántos pasan/fallan (ya en 74 tras la spec 095 — confirmar)
- [X] T002 Confirmar en la base local el caso de referencia: replicar la venta 24854 (3 líneas del mismo producto a $13.000, $25.000 con 10% bonif., $50.000 con 15% bonif., total $94.380) o verificarlo por lectura contra el VPS

## Phase 2: Foundational

**Bloquea todas las historias**: sin la columna nueva y el cambio de servicio, ninguna historia es posible.

- [X] T003 Migración `add_referencia_linea_a_nota_credito_debito_items`: agregar `venta_item_id` (nullable, FK → `venta_items.id`, `nullOnDelete()`) y `compra_item_id` (nullable, FK → `compra_items.id`, `nullOnDelete()`) a `nota_credito_debito_items`, según data-model.md
- [X] T004 `NotaCreditoDebitoItem`: agregar `venta_item_id`/`compra_item_id` a `$fillable` y las relaciones `ventaItem()`/`compraItem()` (`app/Models/NotaCreditoDebitoItem.php`)
- [X] T005 Reescribir `AjustesPendientesNotaCreditoDebito::pendiente()` para operar sobre una línea de origen (`VentaItem|CompraItem`) en vez de un `producto_id` suelto, con el modo dual (por línea / agregado-fallback) de data-model.md (FR-003, FR-006)
- [X] T006 Reescribir `AjustesPendientesNotaCreditoDebito::itemsDisponibles()`: quitar el `groupBy('producto_id')`, iterar cada línea del comprobante y llamar a `pendiente()` por línea; agregar `item_origen_id` a cada elemento del array de retorno (FR-001, FR-002, contrato)
- [X] T007 `NotaCreditoDebitoController::store()`/`storeCompra()`: persistir `venta_item_id`/`compra_item_id` en cada `NotaCreditoDebitoItem` cuando el payload trae `item_origen_id` (FR-004)

---

## Phase 3: User Story 1 — Anular una venta con el mismo producto en varias líneas (P1)

**Meta**: que la precarga muestre cada línea del comprobante por separado, no fundida.

**Test independiente**: abrir una NC sobre la venta 24854 con "afecta stock = Sí" y verificar 3 líneas
separadas ($13.000 / $25.000 10% / $50.000 15%), total propuesto $94.380.

- [X] T008 [US1] `resources/js/notas-credito-debito.js`: en `cargarItemsDisponibles()`, confirmar que cada elemento de `resp.data` se renderiza como una fila propia sin deduplicar por `producto_id` (revisar el `.map()`/`.find()` que arma `items` desde `itemsDisponibles` — no debe colapsar dos elementos con el mismo `producto_id` en una sola fila); si hay algún `.find((d) => d.producto_id === ...)` que asuma unicidad de producto, cambiarlo para usar `item_origen_id`
- [X] T009 [P] [US1] Test en `tests/Feature/NotaCreditoDebitoLineasIndependientesTest.php`: sobre una venta con 3 líneas del mismo producto a precios distintos y sin notas previas, `itemsDisponibles()` devuelve 3 elementos con `item_origen_id` distinto, cada uno con su propio precio/descuento_pct/cantidad
- [X] T010 [P] [US1] Test en el mismo archivo: el total propuesto (recalculado igual que en `notas-credito-debito.js`) coincide con el total real del comprobante cuando hay producto repetido (reproduce SC-001, venta 24854: $94.380)
- [X] T011 [P] [US1] Test en el mismo archivo: sobre una venta con cada producto en una única línea (sin repetición), `itemsDisponibles()` devuelve exactamente 1 elemento por producto — comportamiento idéntico al actual (FR-008)

---

## Phase 4: User Story 2 — Ajustar sólo una de las líneas repetidas (P1)

**Meta**: que borrar una línea precargada no afecte a las demás, y que una segunda nota no vuelva a
ofrecer lo ya ajustado por línea.

**Test independiente**: sobre el formulario de la venta 24854, borrar la línea de $50.000 y guardar;
confirmar 2 líneas guardadas intactas. Abrir un alta nueva sobre la misma venta y confirmar que sólo
ofrece la línea de $50.000 (la no ajustada).

- [X] T012 [US2] Confirmar que el flujo de guardado ya envía `item_origen_id` por línea (payload del front, sin cambios de lógica adicionales más allá de T007/T008 — sólo verificación)
- [X] T013 [P] [US2] Test en `tests/Feature/NotaCreditoDebitoLineasIndependientesTest.php`: guardar una nota con sólo 2 de las 3 líneas precargadas (una borrada por el usuario) persiste exactamente esas 2, cada una con su `venta_item_id` propio, cantidad/precio/bonificación intactos (FR-007)
- [X] T014 [P] [US2] Test en el mismo archivo: tras guardar una nota que ajustó la línea de $25.000 completa, un segundo `itemsDisponibles()` sobre el mismo comprobante devuelve sólo las líneas de $13.000 y $50.000 — no vuelve a ofrecer la de $25.000 ni mezcla cantidades (FR-003)
- [X] T015 [P] [US2] Test en el mismo archivo — fallback FR-006: crear una `NotaCreditoDebitoItem` SIN `venta_item_id` (simulando una nota vieja) sobre un producto con 2 líneas; verificar que `pendiente()` para ese producto se calcula agregado (como hoy), no por línea
- [X] T016 [P] [US2] Test en el mismo archivo — persistencia del fallback (FR-006, matiz descubierto en implementación): sobre el caso de T015, crear una SEGUNDA nota que sí trae `venta_item_id`; verificar que el producto SIGUE en modo agregado mientras la nota vieja exista (no se mezcla por línea hasta que no quede ninguna sin referencia)

---

## Phase 5: User Story 3 — No romper lo que hoy funciona bien (P2)

**Meta**: cero regresiones sobre la spec 095 y el flujo existente.

**Test independiente**: correr la suite completa de NC/ND; abrir el alta sobre la venta 24740
(referencia de la spec 095, sin producto repetido) y confirmar que el total propuesto sigue en
$218.458,32.

- [X] T017 [US3] Verificar manualmente (o con test) que la edición de una NC/ND ya existente sigue sin recibir cabecera ni ítems del comprobante de origen — sin cambios respecto a spec 095 FR-011 (FR-009)
- [X] T018 [P] [US3] Test en `tests/Feature/NotaCreditoDebitoLineasIndependientesTest.php`: sobre los comprobantes de referencia de la spec 095 sin producto repetido (réplica de venta 24740), el total propuesto no cambia tras este fix (SC-004)
- [X] T019 [P] [US3] Test en el mismo archivo: paridad en Compras — mismo comportamiento de líneas independientes que en Ventas, con `compra_item_id` en vez de `venta_item_id` (FR-010)

---

## Phase 6: Polish & no regresión

- [X] T020 Correr `php artisan test --filter="NotaCreditoDebito"` completo y comparar contra la línea base de T001: los tests nuevos pasan y el número de fallas preexistentes no empeora (SC-003)
- [X] T021 Verificar en el navegador LOCAL (Chrome DevTools) los 6 escenarios de [quickstart.md](./quickstart.md) simulando el uso real antes de tocar producción
- [ ] T022 Deploy a producción (VPS) sólo después de T020/T021 en verde — sin crear ni guardar datos de prueba en producción; verificación post-deploy de sólo lectura sobre la venta 24854 real
- [X] T023 Actualizar `docs/documentacion_principal_crm.md` §3.6 con el criterio de línea independiente y el fallback agregado, si el relevamiento no lo cubre ya

---

## Dependencias

```
Phase 1 (Setup)
   ↓
Phase 2 (Foundational) ← BLOQUEA todo lo demás (migración + servicio reescrito)
   ↓
   ├─→ Phase 3 (US1: precarga por línea)     ← MVP
   ├─→ Phase 4 (US2: ajuste parcial + fallback)
   └─→ Phase 5 (US3: no regresión)
              ↓
       Phase 6 (Polish + deploy)
```

- **US1** es el MVP: resuelve el bug reportado (total mal calculado).
- **US2** depende de US1 (usa el mismo caso de referencia) pero prueba una capacidad distinta:
  ajuste parcial y el fallback de FR-006. Es igual de P1 porque sin ella US1 no es utilizable en la
  práctica (no se puede ajustar sólo una línea).
- **US3** es no-regresión: puede correr en paralelo a US1/US2 una vez terminada la Phase 2.

## Paralelismo

Los tests marcados `[P]` van todos al mismo archivo (`NotaCreditoDebitoLineasIndependientesTest.php`):
se escriben en paralelo pero se integran de a uno. Las tareas de servicio (T005, T006) tocan el mismo
archivo y no se paralelizan entre sí; T007 (controlador) y T008 (JS) sí son paralelizables entre ellas
una vez cerrado el contrato de T006.

## Estrategia de implementación

**MVP = Phase 1 + Phase 2 + Phase 3 (US1)**: ya corrige el total mal calculado, que es el bug
reportado y verificado en producción (venta 24854, $47.190 en vez de $94.380).

**US2 es igual de crítica** porque sin ella el ajuste parcial (la razón real de que existan líneas
separadas) sigue roto — conviene implementarla junto con US1, no después.

**Recordatorio del principio IV**: esto toca cálculo de cantidad pendiente e importes por línea, así
que ningún cambio se da por terminado sin su test en verde. **Recordatorio explícito del usuario**:
probar primero en LOCAL con Chrome DevTools simulando un usuario real (Phase 6, T021) antes de
cualquier verificación o deploy en producción (T022) — nunca crear/guardar datos de prueba en el VPS.
