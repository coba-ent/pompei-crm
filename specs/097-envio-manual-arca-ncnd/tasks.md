# Tasks: Envío Manual a ARCA para Notas de Crédito/Débito, con IVA real por línea

**Feature**: 097-envio-manual-arca-ncnd | **Date**: 2026-09-03

**Input**: [spec.md](./spec.md) · [plan.md](./plan.md) · [data-model.md](./data-model.md) ·
[contracts/envio-arca-ncnd.md](./contracts/envio-arca-ncnd.md) · [research.md](./research.md)

> Sin migraciones. Reutiliza `EmisorComprobante`, `MapeadorComprobante` y las columnas ya agregadas por
> spec 096 (`venta_item_id`/`compra_item_id`).

## Phase 1: Setup

- [X] T001 Línea base: correr `php artisan test --filter="NotaCreditoDebito|EnvioManualArca"` y anotar cuántos pasan/fallan antes de tocar nada
- [X] T002 Confirmar en local un caso de referencia con dos alícuotas: una Venta con un ítem al 21% y otro al 10.5%, con CAE aprobado (homologación), lista para crear una NC sobre ambas líneas

---

## Phase 2: Foundational

**Bloquea todas las historias**: sin sacar el trigger automático y sin generalizar
`emitirComprobanteFiscalNota()` para Compra, ninguna historia es completamente probable.

- [X] T003 En `app/Http/Controllers/NotaCreditoDebitoController.php::store()`: quitar el bloque que llama a `emitirComprobanteFiscalNota()` automáticamente tras crear la nota (líneas ~189-193) — la creación ya NO dispara envío (FR-001)
- [X] T004 Generalizar `emitirComprobanteFiscalNota()` para aceptar tanto Venta como Compra como comprobante original (hoy sólo tipa `Venta $venta`) — necesario para FR-011 (paridad Compras), ya que hoy `storeCompra()` ni siquiera la invoca. Este cambio toca únicamente `NotaCreditoDebitoController`, nunca `App\Services\Arca\EmisorComprobante` (FR-005). Confirmar además que `storeCompra()` no requiere un cambio equivalente a T003: ya no dispara ningún envío automático hoy (a diferencia de `store()`), así que FR-001 ya se cumple trivialmente para Compra — no hay trigger que sacar ahí.
- [X] T005 En `emitirComprobanteFiscalNota()`: reemplazar el cálculo fijo `neto = monto/1.21` por el armado de `items` reales — recorrer `$nota->items` y, sólo si **todos** tienen `venta_item_id` o `compra_item_id` no nulo (según corresponda), construir el array `items` con `neto` (`cantidad * precio * (1 - descuento_pct/100)`) e `iva_pct` de cada línea; si algún ítem no tiene línea de origen, mantener el cálculo agregado actual como fallback (FR-009, FR-010, FR-010a)
- [X] T006 [P] Agregar a `app/Models/NotaCreditoDebito.php`: método `puedeEnviarseAArca(Venta|Compra|null $comprobanteOriginal): bool` (FR-003: tipo A/B/C, sin CAE propio aprobado, comprobante original con CAE aprobado, Función Avanzada activa) y `estadoArca(): string` (`sin_enviar`/`pendiente`/`aprobado`/`rechazado` desde `comprobanteFiscal?->estado`, FR-015)
- [X] T007 [P] Agregar a `app/Models/Venta.php` (si no existe ya un helper equivalente): confirmar que `estaFacturada()`/`puedeEnviarseAArca()` ya cubren FR-014, o agregar `estadoArca(): string` con el mismo criterio que T006 para reutilizar la misma etiqueta en la vista

---

## Phase 3: User Story 1 — Enviar una NC/ND a ARCA manualmente (Priority: P1) 🎯 MVP

**Meta**: acción "Enviar a ARCA" por nota, con confirmación y modal de resultado propios, sin disparo
automático.

**Test independiente**: crear una NC sobre una Venta con CAE aprobado, confirmar que no se envía sola,
ejecutar "Enviar a ARCA" desde el Detalle y confirmar que obtiene CAE propio.

- [X] T008 [US1] Rutas nuevas en `routes/web.php`: `POST {venta}/notas/{notaCreditoDebito}/enviar-arca` → `ventas.notas.enviarArca` (dentro del grupo `permiso:ventas.ver`, junto a las rutas de `notas.*` ya existentes) y `POST {compra}/notas/{notaCreditoDebito}/enviar-arca` → `compras.notas.enviarArca` (grupo `permiso:compras.ver`)
- [X] T009 [US1] `NotaCreditoDebitoController::enviarArca(Venta $venta, NotaCreditoDebito $notaCreditoDebito)`: valida precondiciones (FR-003, FR-007) devolviendo 422 + `{ok:false, motivo}` si no corresponde; si corresponde, llama a `emitirComprobanteFiscalNota()` y devuelve `{ok, cae, cae_vencimiento, motivo, estado_arca}` (contracts/envio-arca-ncnd.md)
- [X] T010 [P] [US1] `NotaCreditoDebitoController::enviarArcaCompra(Compra $compra, NotaCreditoDebito $notaCreditoDebito)`: mismo comportamiento que T009 para Compra (paridad FR-011)
- [X] T011 [US1] Nueva vista parcial `resources/views/ventas/_modales_arca_nota.blade.php`: modal de confirmación (`#modal-confirmar-arca-nota`) con texto "¿Enviar esta Nota de Crédito/Débito a ARCA...?" y modal de resultado persistente (`#modal-resultado-arca-nota`) — independientes de `_modales_arca.blade.php` de Venta (Clarifications)
- [X] T012 [US1] Incluir `@include('ventas._modales_arca_nota')` en `resources/views/ventas/detalle.blade.php` (junto a `@include('ventas._modales_arca')` ya existente, línea ~449)
- [X] T013 [US1] En la tabla de NC/ND de `resources/views/ventas/detalle.blade.php` (dentro del `@forelse ($venta->notasCreditoDebito as $nota)`, ~líneas 397-435): agregar ítem "Enviar a ARCA" al dropdown de acciones de fila, visible sólo si `$nota->puedeEnviarseAArca($venta)`, con `data-id`/`data-url` apuntando a la ruta de T008
- [X] T014 [US1] `resources/js/notas-credito-debito.js`: handler del click en "Enviar a ARCA" → abre `#modal-confirmar-arca-nota` → al confirmar, `POST` AJAX a la url de la fila → éxito/rechazo real de ARCA abre `#modal-resultado-arca-nota` con CAE/vencimiento o motivo (FR-006); rechazo de precondición (HTTP 422) muestra toast (FR-006a); al cerrar el modal de resultado, refresca la fila sin recargar la página completa
- [X] T015 [P] [US1] Test en `tests/Feature/EnvioManualArcaNotaCreditoDebitoTest.php`: crear una NC sobre una Venta con `ComprobanteFiscal` aprobado y verificar que la nota queda sin `comprobanteFiscal` propio tras `store()` (FR-001, no regresión del trigger)
- [X] T016 [P] [US1] Test en el mismo archivo: `POST` a `ventas.notas.enviarArca` sobre una nota elegible, con `EmisorComprobante` mockeado, resulta en `ComprobanteFiscal` aprobado y respuesta `{ok:true, cae, cae_vencimiento}` (Acceptance Scenario 3)
- [X] T017 [P] [US1] Test en el mismo archivo: `POST` sobre una nota cuyo comprobante original NO tiene CAE aprobado devuelve 422 sin invocar `EmisorComprobante` (Acceptance Scenario 4, precondición)
- [X] T018 [P] [US1] Test en el mismo archivo: `POST` sobre una nota que ya tiene `ComprobanteFiscal` aprobado devuelve 422 sin reintentar (Acceptance Scenario 5)
- [X] T019 [P] [US1] Test en el mismo archivo — paridad Compras: mismo comportamiento que T016 vía `compras.notas.enviarArca` (Acceptance Scenario 6, FR-011)
- [X] T020 [P] [US1] Test en el mismo archivo: doble `POST` consecutivo sobre la misma nota no genera dos `ComprobanteFiscal` (Edge Case doble click, mismo resguardo de `EmisorComprobante`)

---

## Phase 4: User Story 2 — El envío a ARCA usa el IVA real de cada línea (Priority: P1)

**Meta**: el payload enviado a ARCA desglosa por alícuota real cuando la nota tiene todos sus ítems con
línea de origen; cae a fallback agregado en caso contrario.

**Test independiente**: NC sobre una Venta con líneas a 21% y 10.5%; verificar que el `FeDetReq` trae dos
bloques `AlicIva` con neto/IVA reales, no un único bloque al 21%.

- [X] T021 [US2] Test en `tests/Feature/EnvioManualArcaNotaCreditoDebitoTest.php`: NC con dos ítems (uno con `venta_item_id` y `iva_pct=21`, otro con `venta_item_id` y `iva_pct=10.5`) — al enviar a ARCA, capturar el payload pasado a `EmisorComprobante::emitir()` (mock/spy) y verificar que `MapeadorComprobante::mapear()` sobre esos datos produce dos bloques `AlicIva` (Id 5 y AlicIva Id 4) con neto/IVA reales de cada línea (Acceptance Scenario 1, SC-003)
- [X] T022 [P] [US2] Test en el mismo archivo: NC con todos los ítems a la misma alícuota (todos con línea de origen) produce el mismo resultado numérico que el desglose real, distinto del cálculo al 21% fijo cuando la alícuota real es otra (Acceptance Scenario 2)
- [X] T023 [P] [US2] Test en el mismo archivo: NC vieja simulada (ítems con `venta_item_id=null`) usa el fallback agregado (bloque único), igual que el comportamiento actual (Acceptance Scenario 3, FR-010)
- [X] T024 [P] [US2] Test en el mismo archivo: NC con ítems mixtos (uno con `venta_item_id`, otro sin él) usa el fallback agregado para toda la nota — no combina ambos criterios (Acceptance Scenario 3a, FR-010a)
- [X] T025 [P] [US2] Test en el mismo archivo: NC sin ítems propios (nota "global") sigue usando el bloque único actual, sin regresión (Acceptance Scenario 4)
- [X] T026 [US2] Verificar (T005 ya implementado en Phase 2) que el cálculo de `neto` por línea usa `cantidad * precio * (1 - descuento_pct/100)` consistente con cómo ya se calcula el `monto` final de la nota en `resources/js/notas-credito-debito.js::recalcular()` — ajustar T005 si hay divergencia

---

## Phase 5: User Story 3 — Corregir la documentación (Priority: P3)

**Meta**: `docs/documentacion_principal_crm.md` refleja el envío manual de NC/ND y el IVA real por línea.

**Test independiente**: leer la sección de Facturación Electrónica / NC-ND y confirmar que no dice
"automáticamente" ni describe el IVA como fijo al 21%.

- [X] T027 [US3] Actualizar `docs/documentacion_principal_crm.md` en la sección de Facturación Electrónica (cerca de la actualización de spec 040, §1500-1513): agregar párrafo describiendo el envío manual de NC/ND (botón "Enviar a ARCA" en el Detalle de Venta/Compra, modales propios) y el cálculo de IVA real por línea, referenciando esta spec (097) junto a la 040 y la 096

---

## Phase 6: User Story 4 — Ver el estado de ARCA en el Detalle de Venta y de NC/ND (Priority: P2)

**Meta**: indicador de estado ARCA siempre visible (no sólo tooltip) en el Detalle de Venta, y estado
propio (distinto del original) en cada fila de NC/ND.

**Test independiente**: abrir el Detalle de una Venta aprobada y ver el estado sin pasar el mouse; abrir
el Detalle de una NC/ND sin enviar y ver "Sin enviar" distinto del estado de la Venta.

- [X] T028 [US4] En `resources/views/ventas/detalle.blade.php` (header, ~líneas 29-40): reemplazar el botón deshabilitado "Enviada a ARCA" (sólo cubre el caso aprobado) por un badge de estado siempre visible con los 4 valores (Sin enviar/Pendiente/Aprobado/Rechazado vía `$venta->estadoArca()` de T007), manteniendo el botón "Enviar a ARCA" cuando `puedeEnviarseAArca()` sea `true` (FR-014)
- [X] T029 [P] [US4] Si el estado es `rechazado`, mostrar el motivo del último intento (desde `$venta->comprobanteFiscal->motivo_rechazo` o campo equivalente ya existente en `ComprobanteFiscal`) en el badge o un tooltip adicional (Acceptance Scenario 2)
- [X] T030 [US4] En la tabla de NC/ND de `resources/views/ventas/detalle.blade.php` (~líneas 397-435): agregar columna o badge de estado propio de la nota (`$nota->estadoArca()` de T006), visualmente distinguible de "Documento que Ajusta" (que muestra el estado del comprobante ORIGINAL) — evita a FR-015 confundir ambos estados
- [X] T031 [P] [US4] Cuando el estado de la nota es `aprobado`, mostrar su CAE y vencimiento en la misma fila o en un tooltip (FR-016) — reutilizar el dato ya expuesto por `NotaCreditoDebito::comprobanteFiscal`
- [X] T032 [P] [US4] Aplicar T028-T031 (estado de Compra y de sus NC/ND) al Detalle de Compra equivalente (buscar la vista análoga, probablemente `resources/views/compras/detalle.blade.php`) — paridad FR-011. Antes de tocar código, verificar que esa vista tiene una sección de header comparable a la de Venta (líneas ~29-40 de `ventas/detalle.blade.php`); si la estructura difiere, adaptar el criterio en vez de copiar literal.
- [X] T033 [P] [US4] Test en `tests/Feature/EnvioManualArcaNotaCreditoDebitoTest.php`: `NotaCreditoDebito::estadoArca()` devuelve `sin_enviar`/`pendiente`/`aprobado`/`rechazado` correctamente según el estado de su `comprobanteFiscal` (Acceptance Scenarios 3, 4, 5)
- [X] T034 [P] [US4] Test en el mismo archivo: `Venta::estadoArca()` (o helper equivalente) devuelve los 4 valores correctamente, incluido `rechazado` con motivo (Acceptance Scenarios 1, 2, 3)

---

## Phase 7: Polish & no regresión

- [X] T035 Correr `php artisan test --filter="NotaCreditoDebito|EnvioManualArca"` completo y comparar contra la línea base de T001: los tests nuevos pasan y no empeora ninguno preexistente (incluye `EmisionComprobanteNotaCreditoDebitoTest` ajustado para invocar el envío manual en vez del trigger eliminado)
- [ ] T036 Verificar en el navegador LOCAL (Chrome DevTools) los 6 escenarios de [quickstart.md](./quickstart.md) simulando uso real, incluida la paridad Compras
- [ ] T037 Deploy a producción (VPS) sólo después de T035/T036 en verde, y con la Función Avanzada "Facturación Electrónica" reactivada explícitamente por el usuario (sigue desactivada desde el incidente de spec 040) — sin crear/guardar datos de prueba en producción
- [X] T038 Verificar que `docs/documentacion_principal_crm.md` (T027) quedó consistente tras la implementación real (si algo cambió de diseño respecto del plan, reflejarlo)

---

## Dependencias

```
Phase 1 (Setup)
   ↓
Phase 2 (Foundational) ← BLOQUEA todo lo demás (sacar trigger + generalizar emitirComprobanteFiscalNota)
   ↓
   ├─→ Phase 3 (US1: envío manual)       ← MVP
   ├─→ Phase 4 (US2: IVA real por línea) ← usa el mismo emitirComprobanteFiscalNota de Phase 2
   ├─→ Phase 5 (US3: documentación)       ← independiente, puede ir en paralelo
   └─→ Phase 6 (US4: estado visible)      ← independiente de US1/US2 en implementación,
                                             pero de poco valor sin US1 (no hay "Enviar a ARCA" que contextualizar)
              ↓
       Phase 7 (Polish + deploy)
```

- **US1 y US2** comparten el mismo punto de cambio (`emitirComprobanteFiscalNota()`, Phase 2) — conviene
  implementarlas juntas, no una y después la otra, para no tocar el mismo método dos veces.
- **US3** (documentación) no depende de código — puede escribirse en cualquier momento tras cerrar el
  diseño, incluso antes de terminar la implementación.
- **US4** es independiente técnicamente pero de bajo valor sin US1 desplegada (el usuario necesita el
  botón nuevo para que el estado tenga sentido en el flujo).

## Paralelismo

Los tests marcados `[P]` en Phase 3/4/6 van todos al mismo archivo
(`EnvioManualArcaNotaCreditoDebitoTest.php`): se escriben en paralelo pero se integran de a uno. T009/T010
(controlador Venta/Compra) son paralelizables entre sí una vez cerrado T004/T005. T006/T007 (modelos) son
paralelizables entre sí.

## Estrategia de implementación

**MVP = Phase 1 + Phase 2 + Phase 3 (US1)**: corrige el defecto más urgente (envío automático no
deseado), replicando el patrón ya validado de spec 040.

**US2 va junto con US1** en la práctica porque ambas tocan `emitirComprobanteFiscalNota()` — separarlas
en el tiempo sólo duplicaría trabajo de revisión sobre el mismo método.

**US4 se recomienda para el mismo release que US1**, aunque sea P2: sin visibilidad de estado, el usuario
no tiene forma de saber si ya envió una nota antes de intentarlo de nuevo.

**Recordatorio del principio IV**: cálculo de IVA e impacto fiscal — ningún cambio se da por terminado sin
su test en verde. **Recordatorio explícito**: probar primero en LOCAL con Chrome DevTools (Phase 7, T036)
antes de cualquier verificación o deploy en producción (T037) — nunca crear/guardar datos de prueba en el
VPS, y no reactivar la Función Avanzada en producción sin decisión explícita del usuario (sigue
desactivada desde el incidente de spec 040).
