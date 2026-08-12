# Tasks: Cancelaciones de Mercado Libre posteriores a la venta, y avisos de sincronización

**Feature**: `063-ml-cancelaciones-avisos` | **Spec**: [spec.md](./spec.md) | **Plan**: [plan.md](./plan.md)

**Criterio que ordena todo**: esta feature **detecta y avisa**. No construye ni modifica los
circuitos de nota de crédito ni de eliminación de ventas — ya existen y se usan tal cual están.

**Tests**: obligatorios en la detección, porque decide sobre ventas cobradas (Principio IV de la
constitución). El caso más importante a cubrir es el negativo: **que la detección NO modifique
importes, cobros ni stock**.

---

## Phase 1: Setup

- [X] T001 Verificar los usos actuales de `EstadoOrden::Cancelada` en todo el código, para saber qué pantallas se ven afectadas al dejar de colapsar `cancelled`/`partially_refunded` (riesgo declarado en plan.md) — Sólo 2 usos: `EvaluadorConvertibilidad.php:25` y `ConversorOrdenAVenta.php:175`, ambos comparan `estado_orden === EstadoOrden::Cancelada` para IMPEDIR convertir una orden cancelada. Como `partially_refunded` deja de mapear a `Cancelada` (pasa a un caso nuevo `ReembolsoParcial`), esas dos comprobaciones dejan de bloquear la conversión de una orden con reembolso parcial. Es aceptable: una orden con reembolso parcial todavía puede tener saldo cobrado y convertirse; no es el caso que preocupa (el que preocupa es post-conversión, cubierto por US1/US3).
- [X] T002 Crear la migración `database/migrations/..._add_control_de_errores_a_ml_publicacion_producto.php` con `stock_intentos_fallidos` (entero, default 0), `stock_error_desde` (datetime nullable) y `stock_requiere_intervencion` (bool, default false) — creada y migrada

---

## Phase 2: Foundational

**Bloquea todas las user stories.** Sin estos enums no hay forma de expresar el aviso.

- [X] T003 [P] Agregar a `app/Enums/MercadoLibre/MotivoRequiereAtencion.php` los casos `OrdenCancelada`, `OrdenReembolsoParcial` y `OrdenEnMediacion`, con sus etiquetas en español
- [X] T004 [P] Ampliar en `app/Enums/MercadoLibre/EstadoConversion.php` las transiciones permitidas: `Convertida → RequiereAtencion`, y desde ahí de vuelta a `Convertida` o a `Cancelada`
- [X] T005 Separar en `app/Enums/MercadoLibre/EstadoOrden.php` los estados que hoy se colapsan: `cancelled`/`pending_cancel` y `partially_refunded` dejan de mapear al mismo caso (ver T001 antes de tocarlo) — se agregó el caso `ReembolsoParcial`
- [X] T006 Exponer en `app/Services/MercadoLibre/TraductorOrdenes.php` el estado de los pagos (`payments[].status`), que hoy se descarta — es de donde sale la mediación, que no viene en el estado de la orden — agregados `estadoPagos()`, `tieneMediacion()`, `importeReembolsado()`

---

## Phase 3: User Story 1 — La cancelación se hace visible (P1) 🎯 MVP

**Goal**: que una orden cancelada después de facturada quede marcada, sin que nadie tenga que mirar
Mercado Libre.

**Independent Test**: convertir una orden pagada en Venta, pasarla a cancelada, sincronizar, y
verificar que queda marcada **sin que cambien importes, cobros ni stock**.

- [X] T007 [US1] Crear `app/Services/MercadoLibre/DetectorCancelaciones.php`: recibe una orden ya actualizada y devuelve el motivo del aviso, o ninguno. Registra motivo, fecha de detección y estado informado por el marketplace. Marca sólo si la orden **tiene `venta_id` y la Venta está vigente** (FR-001, FR-002, FR-007)
- [X] T008 [US1] Implementar en ese servicio la idempotencia: si la orden ya está marcada con el mismo motivo, no se toca `motivo_detalle` ni la fecha de detección original (FR-005)
- [X] T009 [US1] Implementar el cierre automático: si la orden volvió a un estado vigente, o su Venta fue eliminada o compensada por una nota de crédito, el aviso se cierra (FR-006, FR-010a)
- [X] T010 [US1] Invocar el detector desde `SincronizadorOrdenes::procesarOrden()`, después de actualizar la orden y **fuera** del camino de creación automática, sin agregar llamadas a la API (FR-001, SC-001)
- [X] T011 [US1] Crear `tests/Feature/Integraciones/CancelacionPosteriorTest.php` cubriendo: se marca con el motivo correcto; **no cambian total, cobro ni stock** (FR-003, SC-002); una orden cancelada sin Venta no genera aviso; re-sincronizar no duplica ni mueve la fecha
- [X] T011a [US1] Agregar al mismo test que una Venta marcada **sigue pudiendo editarse y cobrarse**: el aviso informa, no restringe (FR-008a)

**Checkpoint**: al terminar esta fase el problema deja de ser invisible, aunque la resolución siga siendo manual.

---

## Phase 4: User Story 2 — Llegar a la venta y resolverla (P1)

**Goal**: que el aviso conduzca a la Venta y se cierre cuando ésta se resuelve, sin agregar circuitos.

**Independent Test**: desde el aviso llegar a la Venta, resolverla con el circuito existente, y ver
que el aviso desaparece de pendientes.

- [X] T012 [US2] Mostrar en la pantalla de Órdenes de Mercado Libre el motivo del aviso y un link directo a la Venta afectada (FR-008, FR-009) — ya existían las columnas Motivo/Venta en el datatable; sólo se necesitaba el detector marcando bien
- [X] T013 [US2] Agregar la acción **Descartar aviso** (implementada en `MercadoLibreVentaController::descartarAviso()`, no en `MercadoLibreVinculacionController` — ese controller es de vinculaciones producto↔publicación, no de órdenes; se siguió la cohesión del código existente): devuelve la orden a `Convertida` y registra en auditoría vía `AuditoriaService` (FR-010, FR-011)
- [X] T014 [US2] Conectar esa acción en el front por AJAX, con confirmación en modal y toast, sin recargar la página (reglas de UI del proyecto)
- [X] T015 [US2] Agregar un indicador en el listado de Ventas para las que tienen aviso pendiente (FR-008) — badge amarillo junto al id en `VentaController::data()`/`queryFiltrada()`
- [X] T016 [US2] Extender `CancelacionPosteriorTest` con el cierre automático del aviso por las tres vías: nota de crédito que compensa la venta, eliminación de la venta, y descarte manual

⚠️ **No hacer**: no crear una acción de "anular", ni precargar el formulario de nota de crédito, ni
tocar `VentaController::destroy()`. La resolución usa lo que ya existe (FR-009a).

---

## Phase 5: User Story 3 — Reembolso parcial y mediación (P2)

**Goal**: que un desenlace todavía abierto no se trate como una venta caída.

**Independent Test**: simular una orden con reembolso parcial y otra con pago en mediación, y
verificar que cada una produce su propio motivo.

- [X] T017 [US3] Implementar en `DetectorCancelaciones` la distinción de los tres motivos, leyendo el estado de la orden **y** el de sus pagos (FR-004)
- [X] T018 [US3] Incluir en `motivo_detalle` el importe reembolsado cuando venga informado, y dejar constancia explícita cuando no venga (FR-004a)
- [X] T019 [US3] Implementar la transición de motivo cuando una mediación se resuelve como cancelación, **conservando la fecha de detección original** (US3 escenario 3)
- [X] T020 [US3] Extender los tests con los cuatro escenarios de US3, incluido el cierre automático cuando la mediación se resuelve a favor del negocio — creado `tests/Feature/Integraciones/CancelacionesMotivosTest.php`

---

## Phase 6: User Story 4 — Los errores dejan de reintentarse (P2)

**Goal**: que un error permanente sea visible y deje de consumir llamadas a la API.

**Independent Test**: simular una publicación que falla siempre y verificar que a los 5 intentos
queda bloqueada y deja de reintentarse; y que un error distinto reinicia el contador.

- [X] T021 [US4] Implementar en `SincronizadorStock` el conteo de intentos: mismo error incrementa, error distinto reinicia en 1 y actualiza `stock_error_desde` (FR-014)
- [X] T022 [US4] Marcar `stock_requiere_intervencion` al llegar a 5 intentos consecutivos (FR-015)
- [X] T023 [US4] Excluir las publicaciones marcadas de la selección de pendientes en `MercadoLibrePublicacionProducto` — acá está el ahorro de las ~305 llamadas fallidas (FR-016, SC-004)
- [X] T024 [US4] Limpiar contador, fecha y marca al sincronizar con éxito
- [X] T025 [US4] Agregar la acción **Reactivar** para devolver una publicación bloqueada al ciclo normal, enviando el stock **vigente al momento de reactivar**, no el que tenía al bloquearse (FR-017, edge case) — `MercadoLibreVinculacionController::reactivar()`
- [X] T026 [US4] Mostrar en el panel de vinculaciones: estado bloqueado, motivo del error, intentos acumulados, fecha de la primera falla y la diferencia entre el stock del CRM y el publicado (FR-014, FR-018) — se agregó columna `ultimo_stock_publicado` (migración adicional no prevista en data-model.md original, necesaria para poder calcular la diferencia sin llamar a la API en cada carga del panel)
- [X] T027 [US4] Crear `tests/Feature/Integraciones/ErroresSincronizacionStockTest.php` con: corte a los 5, exclusión de las bloqueadas, reinicio ante error distinto, limpieza al tener éxito, y reactivación manual

---

## Phase 7: Polish

- [X] T028 Actualizar `docs/documentacion_principal_crm.md` con el circuito de avisos por cancelación (el modelo de datos ya se actualizó antes de esta fase)
- [X] T029 Registrar en `docs/importacion_casos_a_revisar.md` el hallazgo que excede esta spec: `VentaController::destroy()` permite eliminar una Venta con comprobante fiscal emitido, sin ninguna verificación (research §R7)
- [X] T030 Correr la suite completa y verificar que no hay regresiones respecto de la línea base conocida (287 fallos preexistentes al 11/08/2026) — la línea base documentada quedó desactualizada (hoy son 301 fallos sin esta feature, por trabajo no relacionado en curso en el repo); se verificó por comparación directa con `git stash`: 301 fallos sin los cambios de esta spec, 300 con ellos. Sin regresiones (de hecho uno menos).
- [ ] T031 Verificar contra producción los números de SC-004: las llamadas fallidas cada 6 h deben bajar de ~305 a menos de 10 (consultas en quickstart.md)

---

## Dependencias

```
Phase 1 (Setup)
   └─> Phase 2 (Foundational: enums + traductor)
          ├─> Phase 3 (US1 · detección)          ← MVP
          │      └─> Phase 4 (US2 · resolución)
          │      └─> Phase 5 (US3 · tres motivos)
          └─> Phase 6 (US4 · errores de stock)   ← independiente del resto
                 └─> Phase 7 (Polish)
```

- **US4 no depende de US1/US2/US3**: toca la sincronización de stock, no las órdenes. Puede hacerse
  en paralelo o incluso primero, y es la que da el ahorro medible de SC-004.
- **US3 depende de US1**, porque extiende el mismo detector.
- **T005 depende de T001**: no tocar el enum sin saber qué pantallas lo usan.

## Paralelizables

- T003 y T004 (enums distintos, sin dependencia entre sí)
- Toda la Phase 6 respecto de las Phases 3-5

## MVP sugerido

**Phase 1 + 2 + 3** (T001-T011). Con eso una cancelación posterior deja de ser invisible, que es el
problema que costó $560.051,43. La resolución sigue siendo manual, pero deja de depender de que
alguien mire Mercado Libre por casualidad.

Si se busca el resultado más medible con menos trabajo, **Phase 6 sola** (US4) también entrega valor
completo por sí misma y es verificable con un número duro.
