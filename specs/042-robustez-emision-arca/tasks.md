# Tasks: Robustez de datos fiscales en la emisión de CAE (ARCA)

**Input**: Design documents from `/specs/042-robustez-emision-arca/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/solicitud-cae.md, quickstart.md

**Tests**: incluidos — Principio IV de la constitución ("Ningún cambio en lógica fiscal o de dinero se
da por terminado sin su test en verde"), y es lógica de emisión fiscal real que ya causó un incidente.

## Format: `[ID] [P?] [Story] Description`

---

## Phase 1: Setup

*No aplica — no hay infraestructura nueva que inicializar (extiende `app/Services/Arca/` existente).*

---

## Phase 2: Foundational

- [X] T001 Agregar tabla constante de mapeo `iva_pct → código ARCA` (0%→3, 10,5%→4, 21%→5, 27%→6, 5%→8, 2,5%→9) en `app/Services/Arca/MapeadorComprobante.php` (research.md §2) — reutilizada por T003 y T004
- [X] T002 Agregar método `resolverCondicionIvaReceptor(?string $condicionIvaCodigo, array $cliente): int` en `app/Services/Arca/MapeadorComprobante.php`: devuelve el código informado, o 5 (Consumidor Final) por defecto si el receptor no tiene CUIT/DNI identificado (research.md §4, FR-007) — reutilizado por T007

**Checkpoint**: mapeos base listos — bloquean US1 y US2.

---

## Phase 3: User Story 1 - Enviar comprobantes con distintas alícuotas de IVA sin que ARCA los rechace por error de cálculo (Priority: P1) 🎯 MVP

**Goal**: corregir la causa raíz confirmada del incidente del 04/08/2026 (rechazo ARCA código 10051) —
declarar un bloque `AlicIva` por cada alícuota real presente en los ítems de la Venta, en vez de uno
solo fijo en 21%.

**Independent Test**: crear una Venta con ítems al 21% y al 10,5%, emitir (mock WSFEv1) y verificar que
la solicitud arma dos bloques `AlicIva` consistentes con sus porcentajes reales (quickstart.md
Escenario 2).

- [X] T003 [US1] Extender `MapeadorComprobante::mapear()` en `app/Services/Arca/MapeadorComprobante.php` para aceptar `items` opcional (array de `{neto, iva_pct}`): si está presente, agrupa por alícuota y arma un bloque `AlicIva` por grupo usando la tabla de T001 (array de bloques si hay más de uno, objeto único si hay uno solo — contracts/solicitud-cae.md); si está ausente, conserva el comportamiento actual (fallback para NC/ND, research.md §1)
- [X] T004 [US1] Agregar en `app/Services/Arca/ValidadorDatosFiscales.php`: validación de que toda alícuota de los ítems resuelve a un código ARCA soportado (T001), y de que la suma de `Importe`/`BaseImp` de los bloques armados coincide con `ImpIVA`/`ImpNeto` totales con tolerancia $0.01 (FR-003/FR-004) — devuelve motivo de rechazo igual que las validaciones existentes
- [X] T005 [US1] Modificar `VentaController::enviarArca()` en `app/Http/Controllers/VentaController.php`: cargar `venta.items` y pasar `items` (con `subtotal`/`iva_pct` de cada `VentaItem`) en el array `$datos` en vez de sólo `neto`/`iva` agregados
- [X] T006 [P] [US1] Test unitario: `MapeadorComprobante` arma un único bloque `AlicIva` para alícuota única (no-regresión) y dos bloques consistentes para alícuotas mixtas, en `tests/Unit/Services/Arca/MapeadorComprobanteTest.php`
- [X] T007 [P] [US1] Test unitario: `ValidadorDatosFiscales` rechaza alícuota no soportada y rechaza inconsistencia de importes fuera de tolerancia $0.01, en `tests/Unit/Services/Arca/ValidadorDatosFiscalesTest.php`
- [X] T008 [P] [US1] Test feature: enviar a ARCA una Venta con ítems de alícuotas mixtas obtiene CAE con la solicitud correctamente desglosada (mock `EmisorComprobante`/WSFEv1), extendiendo `tests/Feature/EmisionComprobanteVentaTest.php`

**Checkpoint**: US1 completa y demostrable de forma independiente — corrige la causa raíz del incidente.

---

## Phase 4: User Story 2 - Informar la Condición frente al IVA del receptor en cada solicitud de CAE (Priority: P1) 🎯 MVP

**Goal**: anticiparse al 01/09/2026 (fecha en que ARCA vuelve obligatorio `CondicionIVAReceptorId`)
incluyendo ese dato en toda solicitud de CAE.

**Independent Test**: emitir un comprobante para un cliente con Condición de IVA cargada y verificar en
`arca_logs_auditoria.payload_solicitud` que la solicitud incluye `CondicionIVAReceptorId` correcto
(quickstart.md Escenario 4).

- [X] T009 [US2] Incluir `CondicionIVAReceptorId` en el detalle armado por `MapeadorComprobante::mapear()` en `app/Services/Arca/MapeadorComprobante.php`, usando `resolverCondicionIvaReceptor()` (T002) sobre `datos['cliente']['condicion_iva_codigo']`
- [X] T010 [US2] Agregar en `app/Services/Arca/ValidadorDatosFiscales.php`: rechazo de precondición cuando el cliente tiene CUIT/DNI identificado pero no tiene Condición de IVA cargada (FR-006) — no aplica al receptor anónimo (FR-007)
- [X] T011 [US2] Modificar `VentaController::enviarArca()` en `app/Http/Controllers/VentaController.php` y `NotaCreditoDebitoController` en `app/Http/Controllers/NotaCreditoDebitoController.php`: cargar `cliente.condicionIva` y pasar `condicion_iva_codigo` (`$venta->cliente?->condicionIva?->codigo_afip`) en el array `$datos`
- [X] T012 [P] [US2] Test unitario: `MapeadorComprobante` incluye `CondicionIVAReceptorId` con el código del cliente, y con el código de Consumidor Final (5) por defecto para receptor anónimo, en `tests/Unit/Services/Arca/MapeadorComprobanteTest.php`
- [X] T013 [P] [US2] Test unitario: `ValidadorDatosFiscales` rechaza un cliente identificado sin Condición de IVA cargada, y no rechaza un receptor anónimo sin ese dato, en `tests/Unit/Services/Arca/ValidadorDatosFiscalesTest.php`
- [X] T014 [P] [US2] Test feature: enviar a ARCA una Venta cuyo cliente no tiene Condición de IVA cargada devuelve rechazo de precondición sin contactar a ARCA, en `tests/Feature/EmisionComprobanteVentaTest.php`

**Checkpoint**: US2 completa y demostrable de forma independiente — el sistema ya no queda expuesto al corte del 01/09/2026.

---

## Phase 5: Polish & Cross-Cutting

- [ ] T015 Ejecutar quickstart.md Escenario 4 manualmente contra **homologación** (nunca producción) y confirmar en `arca_logs_auditoria` que `CondicionIVAReceptorId` viaja correctamente
- [X] T016 Correr la suite completa de tests de emisión de comprobantes (`EmisionComprobanteVentaTest`, `EmisionComprobanteRechazoTest`, `EmisionComprobanteNotaCreditoDebitoTest`, `EnvioManualArcaTest`) para confirmar no-regresión sobre spec 034/040

## Dependencies & Execution Order

- **Foundational (Phase 2)** bloquea User Story 1 y User Story 2 (T003-T014 dependen de las tablas/métodos de T001-T002).
- **User Story 1 (Phase 3, P1)**: causa raíz del incidente — el MVP más urgente. T003 y T004 son secuenciales con T005 (T005 depende de que T003/T004 ya acepten `items`). T006-T008 en paralelo entre sí una vez T003-T005 implementados.
- **User Story 2 (Phase 4, P1)**: independiente de US1 en términos de lógica (campo distinto dentro del mismo detalle), pero comparte los mismos archivos (`MapeadorComprobante.php`, `ValidadorDatosFiscales.php`, `VentaController.php`) — se implementa después de US1 para evitar conflictos de edición simultánea sobre los mismos métodos. T009/T010 secuenciales con T011; T012-T014 en paralelo entre sí una vez T009-T011 implementados.
- **Polish (Phase 5)**: depende de US1 y US2 completas.

## Parallel Execution Examples

- Dentro de US1: T006, T007, T008 en paralelo entre sí (archivos de test independientes).
- Dentro de US2: T012, T013, T014 en paralelo entre sí (archivos de test independientes, T012/T013 en el mismo archivo que T006/T007 pero como bloques de test independientes).
- Entre historias: US1 y US2 tocan los mismos archivos de servicio (`MapeadorComprobante.php`,
  `ValidadorDatosFiscales.php`) — no son paralelizables entre sí a pesar de ser independientes
  conceptualmente; se implementan en secuencia (US1 primero, causa raíz del incidente ya ocurrido).

## Implementation Strategy

**MVP = User Story 1 + User Story 2** (ambas P1): US1 corrige un defecto que ya causó un incidente real
contra ARCA producción — no puede quedar pendiente. US2 previene un corte total de la facturación
electrónica el 01/09/2026 — tiene fecha límite conocida pero no bloquea el uso inmediato del sistema
(el campo es opcional hasta esa fecha). Se implementan ambas en la misma pasada por compartir archivos
y evitar dos rondas de revisión sobre el mismo servicio crítico.
