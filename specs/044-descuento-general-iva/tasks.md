# Tasks: Descuento general aplicado proporcionalmente a neto e IVA

**Input**: Design documents from `/specs/044-descuento-general-iva/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, quickstart.md

**Tests**: incluidos — Principio IV de la constitución ("Ningún cambio en lógica fiscal o de dinero se
da por terminado sin su test en verde"), y es la causa raíz de un bloqueo real de facturación
electrónica (spec 042).

## Format: `[ID] [P?] [Story] Description`

---

## Phase 1: Setup

*No aplica — no hay infraestructura nueva que inicializar (corrige `CalculoComprobante.php`
existente).*

---

## Phase 2: Foundational

*No aplica — un único servicio, sin dependencias compartidas nuevas entre historias.*

---

## Phase 3: User Story 1 - Emitir CAE de una Venta con descuento general sin rechazo por inconsistencia de IVA (Priority: P1) 🎯 MVP

**Goal**: corregir `CalculoComprobante::calcular()` para que el descuento general se aplique
proporcionalmente a neto e IVA por ítem, destrabando la emisión de CAE de Ventas con descuento
general (spec 042 las rechaza hoy por inconsistencia de importes).

**Independent Test**: recrear el caso real (Venta 0001-00016359: 3 ítems al 21%,
`descuento_general_pct=15`) con `CalculoComprobante::calcular()` directamente y verificar que el IVA
resultante guarda la proporción 21% con el neto ya descontado, y que `ValidadorDatosFiscales` (spec
042) ya no rechaza esos totales.

- [X] T001 [US1] Modificar `CalculoComprobante::calcular()` en `app/Services/Ingresos/CalculoComprobante.php`: aplicar el factor `1 - descuentoGeneralPct/100` a `subtotal` y `subtotal_con_iva` de cada ítem (no sólo restar el descuento general del total agregado al final), recalculando `subtotal_con_descuento`/`descuento`/`total` a partir de esos valores ya prorrateados por ítem (research.md §1, data-model.md)
- [X] T002 [P] [US1] Test unitario: `CalculoComprobante` con el caso real (Venta 0001-00016359 — 3 ítems al 21%, descuento general 15%) da un IVA proporcional al neto ya descontado y un `total` menor al que daría la fórmula anterior, en `tests/Unit/Services/Ingresos/CalculoComprobanteTest.php`
- [X] T003 [P] [US1] Test unitario: `CalculoComprobante` sin descuento general (`descuento_general_pct` 0 o ausente) da exactamente los mismos `subtotal_sin_descuento`/`subtotal_con_descuento`/`total` que el comportamiento actual (no-regresión), en `tests/Unit/Services/Ingresos/CalculoComprobanteTest.php`
- [X] T004 [P] [US1] Test unitario: `CalculoComprobante` con ítems en dos alícuotas distintas (21% y 10,5%) y descuento general mantiene, para cada alícuota por separado, que la suma de IVA de sus ítems es proporcional a la suma de neto de esos mismos ítems (condición para que spec 042 arme bloques `AlicIva` consistentes), en `tests/Unit/Services/Ingresos/CalculoComprobanteTest.php`
- [X] T005 [US1] Test feature: extender `tests/Feature/EmisionComprobanteVentaTest.php` (spec 042) con el caso de una Venta con descuento general — confirmar que `ValidadorDatosFiscales` ya no la rechaza por inconsistencia de importes y que el envío a ARCA (mock WSFEv1) procede normalmente

**Checkpoint**: US1 completa y demostrable de forma independiente — destraba la emisión de CAE para Ventas con descuento general.

---

## Phase 4: User Story 2 - Ver en Presupuestos el mismo total corregido que tendrá la Venta convertida (Priority: P2)

**Goal**: confirmar que Presupuestos usa la misma fórmula corregida (mismo servicio compartido) y que
convertir un Presupuesto a Venta no cambia el total por este motivo.

**Independent Test**: crear un Presupuesto con descuento general, confirmar su `total`, convertirlo a
Venta y confirmar que el `total` de la Venta resultante es idéntico.

- [X] T006 [US2] Test feature: crear un Presupuesto con ítems, descuento general y más de una alícuota, confirmar que su `total` usa la fórmula corregida (mismo valor que daría `CalculoComprobante` directamente), convertirlo a Venta y confirmar que el `total` de la Venta no cambia, extendiendo el test feature de conversión de Presupuestos existente (buscar `PresupuestoConversionTest` o equivalente en `tests/Feature/`)

**Checkpoint**: US2 completa — Presupuestos y Ventas quedan consistentes entre sí.

---

## Phase 5: Polish & Cross-Cutting

- [X] T007 Ejecutar quickstart.md Escenario 1 manualmente (o vía tinker) contra la Venta real de referencia (0001-00016359) en el ambiente que corresponda, confirmando que los totales nuevos coinciden con lo documentado en data-model.md/research.md
- [X] T008 Correr la suite completa de tests de Ventas/Presupuestos/emisión de comprobantes (`CalculoComprobanteTest` nuevo, `EmisionComprobanteVentaTest`, `EmisionComprobanteRechazoTest`, `EmisionComprobanteNotaCreditoDebitoTest`, `EnvioManualArcaTest`, tests de Presupuestos existentes que toquen totales) para confirmar no-regresión sobre spec 034/040/042 y sobre Presupuestos

## Dependencies & Execution Order

- **User Story 1 (Phase 3, P1)**: el MVP — corrige la causa raíz del bloqueo de facturación
  electrónica. T001 es la única tarea de implementación; T002-T004 en paralelo entre sí una vez T001
  implementado (mismo archivo de test, bloques de test independientes); T005 depende de T001
  (necesita el fix real para pasar).
- **User Story 2 (Phase 4, P2)**: depende de T001 (US1) — usa el mismo cálculo corregido, pero es un
  test de consistencia distinto (Presupuestos), no requiere cambios de código adicionales.
- **Polish (Phase 5)**: depende de US1 y US2 completas.

## Parallel Execution Examples

- Dentro de US1: T002, T003, T004 en paralelo entre sí (bloques de test independientes sobre el mismo
  archivo, sin dependencias cruzadas una vez T001 está implementado).
- US2 (T006) puede arrancar en paralelo con los tests de US1 (T002-T004) ya que sólo depende del
  mismo T001, no de los tests de US1 — pero por orden de prioridad (P1 antes que P2) se implementa
  después en la práctica.

## Implementation Strategy

**MVP = User Story 1** (P1): corrige la causa raíz real que hoy bloquea la emisión de CAE de
cualquier Venta con descuento general — es la urgencia inmediata. User Story 2 (P2) es un test de
consistencia sobre Presupuestos que no requiere código adicional (mismo servicio ya corregido en
US1), se agrega en la misma pasada por ser de bajo costo y cerrar la brecha de verificación entre
ambos módulos.
