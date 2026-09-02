# Tasks: Espejo del comprobante de origen al crear una NC/ND

**Feature**: 095-espejo-comprobante-ncnd | **Date**: 2026-09-02

**Input**: [spec.md](./spec.md) · [plan.md](./plan.md) · [contracts/precarga-ncnd.md](./contracts/precarga-ncnd.md)

> **Sin migraciones.** Todos los campos que se llenan ya existen en `notas_credito_debito`.
> **Sin endpoints nuevos.** La cabecera viaja por `window.NotaFormData.comprobanteOrigen`, que la
> vista ya emite (research, Decisión 2).

## Phase 1: Setup

- [X] T001 Tomar la línea base de tests ANTES de tocar nada: correr `php artisan test --filter="NotaCreditoDebito"` y anotar cuántos pasan y cuántos fallan, para distinguir después las fallas preexistentes de las propias
- [X] T002 Verificar en la base local los comprobantes de referencia del quickstart (ventas 24740, 24741, 24677 y compra 2442) y anotar su total actual, para comparar contra el total que proponga la nota

## Phase 2: Foundational

**Bloquea todas las historias**: sin esto la cabecera no llega al formulario.

- [X] T003 Ampliar `AjustesPendientesNotaCreditoDebito` en `app/Services/AjustesPendientesNotaCreditoDebito.php` con un método que arme la cabecera del comprobante (tipo de comprobante, descuento general con su modalidad, las 4 fechas, tercero, categoría y conceptos) según `contracts/precarga-ncnd.md`, sin tocar `itemsDisponibles()` (FR-004, FR-006)
- [X] T004 Traducir los conceptos del comprobante (`venta_conceptos` / `compra_conceptos`) a la forma `{tipo, concepto, monto}` que la nota usa en su columna JSON `impuestos`, dentro del mismo método de T003 (FR-007; research, Decisión 3)
- [X] T005 Aplicar el respaldo de fechas en ese método: cada fecha usa la del comprobante y, si falta, cae en su fecha de emisión (FR-005)
- [X] T006 Pasar la cabecera a la vista desde `create()` y `createCompra()` en `app/Http/Controllers/NotaCreditoDebitoController.php`, y bloquear el alta cuando el comprobante de origen está eliminado (FR-016)
- [X] T007 Ampliar `window.NotaFormData.comprobanteOrigen` en `resources/views/notas-credito-debito/form.blade.php` con los campos del contrato, emitiendo las fechas en ISO (`YYYY-MM-DD`) y nunca ya formateadas

---

## Phase 3: User Story 1 — Anular una venta completa sin recalcular a mano (P1)

**Meta**: que la nota nazca valiendo lo mismo que el comprobante cuando tiene descuento general.

**Test independiente**: abrir una NC sobre la venta 24740 y, sin tocar nada, verificar que el total
propuesto es $218.458,32 y no $229.956,12.

- [X] T008 [US1] Aplicar el descuento general precargado en `resources/js/notas-credito-debito.js`, respetando la modalidad: porcentaje o monto fijo, sin convertir entre una y otra (FR-002)
- [X] T009 [US1] Verificar que el descuento de línea ya existente no se altera ni se aplica dos veces al sumar el general de cabecera (FR-003)
- [X] T010 [US1] Disparar el recálculo de totales después de aplicar la cabecera, para que el total propuesto aparezca ya calculado al abrir el formulario
- [X] T011 [P] [US1] Test en `tests/Feature/NotaCreditoDebitoPrecargaTest.php`: sobre una venta con descuento general en porcentaje, la precarga trae el pct y la modalidad correctos
- [X] T012 [P] [US1] Test en el mismo archivo: sobre una venta con descuento general en modo monto, se hereda el importe tal cual, sin convertirlo a porcentaje
- [X] T013 [P] [US1] Test en el mismo archivo: sobre una venta sin descuento, el general queda vacío y no se inventa un valor
- [X] T014 [P] [US1] Test en el mismo archivo: sobre una venta con bonificación por línea, la línea conserva su porcentaje y el general no la pisa (no-regresión de lo que hoy ya funciona)
- [X] T014a [P] [US1] Test en el mismo archivo: el total propuesto coincide con el del comprobante dentro de la tolerancia de medio centavo que usa el resto del sistema, con los importes redondeados a dos decimales (FR-014, SC-001)

---

## Phase 4: User Story 2 — Que la nota nazca con el tipo de comprobante correcto (P1)

**Meta**: que una NC sobre una factura A nazca en A, sin depender de que la persona elija bien.

**Test independiente**: abrir el alta sobre la venta 24740 (tipo A) y verificar que el Tipo de
Comprobante viene en A.

- [X] T015 [US2] Aplicar el tipo de comprobante precargado al campo correspondiente en `resources/js/notas-credito-debito.js`, dejándolo vacío cuando el comprobante no tiene tipo (FR-004)
- [X] T016 [US2] Implementar la advertencia al guardar cuando el tipo elegido difiere del comprobante de origen, explicando que una nota con el tipo cruzado no se corrige editando; informa sin bloquear (FR-004a)
- [X] T017 [P] [US2] Test en `tests/Feature/NotaCreditoDebitoPrecargaTest.php`: la precarga trae el tipo del comprobante para A y para B
- [X] T018 [P] [US2] Test en el mismo archivo: si el comprobante no tiene tipo (vacío o "Sin Factura"), el campo llega vacío y no se infiere ninguno

---

## Phase 5: User Story 3 — Anular sólo una parte de la venta (P2)

**Meta**: que precargar no se convierta en imponer.

**Test independiente**: sobre un formulario precargado, borrar líneas y cambiar el descuento, guardar,
y verificar que se guardó lo que quedó en pantalla.

- [X] T019 [US3] Verificar que quitar líneas o cambiar cantidades recalcula los totales y que el guardado toma lo que quedó en pantalla, no lo precargado (FR-008)
- [X] T020 [US3] Implementar el aviso cuando el descuento general en modo monto supera el subtotal de las líneas restantes, evaluándolo **al guardar** y no mientras se edita (FR-012, FR-015)
- [X] T021 [P] [US3] Test en `tests/Feature/NotaCreditoDebitoPrecargaTest.php`: una nota guardada con líneas quitadas conserva exactamente lo enviado, sin restos de la precarga
- [X] T022 [P] [US3] Test en el mismo archivo: se rechaza guardar cuando el descuento general dejaría el total negativo

---

## Phase 6: User Story 4 — Mismo comportamiento en Compras (P2)

**Meta**: paridad con Ventas, con Proveedor en lugar de Cliente.

**Test independiente**: abrir una NC sobre la compra 2442 (tipo A, descuento 7%) y verificar que
precarga igual que en Ventas.

- [X] T023 [US4] Verificar que `createCompra()` entrega la misma cabecera que `create()`, con el Proveedor como tercero (FR-010)
- [X] T024 [P] [US4] Test en `tests/Feature/NotaCreditoDebitoPrecargaTest.php`: la precarga en Compras trae descuento general, tipo de comprobante y fechas del comprobante del proveedor
- [X] T025 [P] [US4] Test en el mismo archivo: el tercero precargado es el Proveedor y no un Cliente

---

## Phase 7: Polish & no regresión

- [X] T026 [P] Test en `tests/Feature/NotaCreditoDebitoPrecargaTest.php`: sobre un comprobante que ya tiene una NC, la precarga parte de lo **pendiente de ajuste** y no del total del comprobante (FR-009 por encima de FR-001)
- [X] T027 [P] Test en el mismo archivo: editar una NC existente sigue precargando desde la nota y no desde el comprobante (FR-011, SC-006)
- [X] T028 [P] Test en el mismo archivo: una nota con "afecta stock = No" precarga sólo cabecera; monto y descripción quedan vacíos (FR-013)
- [X] T029 Verificar en el navegador local los 5 escenarios de [quickstart.md](./quickstart.md), confirmando primero que la app servida es Pompei
- [X] T030 Correr `php artisan test --filter="NotaCreditoDebito"` y comparar contra la línea base de T001: los tests nuevos pasan y el número de fallas preexistentes no empeora
- [X] T031 Actualizar `docs/documentacion_principal_crm.md` sólo si durante la implementación aparece una regla que el relevamiento no cubrió (el §3.6 ya se actualizó con el hallazgo del espejo)

---

## Dependencias

```
Phase 1 (Setup)
   ↓
Phase 2 (Foundational) ← BLOQUEA todo lo demás
   ↓
   ├─→ Phase 3 (US1: descuento general)     ← MVP
   ├─→ Phase 4 (US2: tipo de comprobante)
   ├─→ Phase 5 (US3: anulación parcial)
   └─→ Phase 6 (US4: Compras)
              ↓
       Phase 7 (Polish)
```

- **US1 y US2** son independientes entre sí: ambas dependen sólo de la Phase 2.
- **US3** conviene después de US1, porque prueba que lo precargado se puede modificar.
- **US4** es paridad: se apoya en lo hecho para Ventas, sin lógica nueva.

## Paralelismo

Los tests marcados `[P]` van todos al mismo archivo, así que **se escriben en paralelo pero se
integran de a uno** para evitar conflictos. Las tareas de implementación de cada historia tocan el
mismo `notas-credito-debito.js`: no se paralelizan entre sí.

Oportunidad real de paralelismo: **T003–T005** (servicio) y **T007** (vista) los puede tomar una
persona distinta de quien hace **T006** (controlador), una vez acordado el contrato.

## Estrategia de implementación

**MVP = Phase 1 + Phase 2 + Phase 3 (US1)**. Con eso solo ya se resuelve el reporte original del
cliente: la NC deja de nacer por un importe equivocado cuando la venta tenía descuento. Es entregable
y verificable por sí mismo contra la venta 24740.

Después, en orden: US2 (el riesgo fiscal más caro), US3 (garantiza que no se rompió la anulación
parcial) y US4 (paridad con Compras).

**Recordatorio del principio IV**: esto toca cálculo de importes y descuentos, así que ningún cambio
se da por terminado sin su test en verde.
