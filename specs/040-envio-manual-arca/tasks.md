# Tasks: Envío Manual a ARCA desde el listado de Ventas

**Input**: Design documents from `/specs/040-envio-manual-arca/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/enviar-arca.md, quickstart.md

**Tests**: incluidos — Principio IV de la constitución ("Ningún cambio en lógica fiscal o de dinero se
da por terminado sin su test en verde"), y es lógica de emisión fiscal real.

## Format: `[ID] [P?] [Story] Description`

---

## Phase 1: Setup

*No aplica — no hay infraestructura nueva que inicializar (reutiliza el módulo Ventas existente).*

---

## Phase 2: Foundational

- [X] T001 Agregar helper `Venta::puedeEnviarseAArca(): bool` en `app/Models/Venta.php` (tipo A/B/C, sin `ComprobanteFiscal` aprobado, `FuncionAvanzada::activa('facturacion_electronica')`) — reutilizado por T003 y T005

**Checkpoint**: helper de disponibilidad listo — bloquea US1 (lo usan tanto el endpoint como el listado).

---

## Phase 3: User Story 1 - Enviar una Venta a ARCA manualmente desde el listado (Priority: P1) 🎯 MVP

**Goal**: eliminar el envío automático (causa del incidente del 04/08/2026) y reemplazarlo por una
acción manual, explícita y confirmada, por fila del listado de Ventas.

**Independent Test**: crear una Venta B, cobrarla, verificar que NO se genera `ComprobanteFiscal`;
luego ejecutar "Enviar a ARCA" desde el listado y verificar que sí se genera (quickstart.md Escenario
1 y 2).

- [X] T003 [US1] Eliminar la llamada a `emitirComprobanteFiscal($venta)` dentro de `cobranzaStore()` en `app/Http/Controllers/VentaController.php` (deja de disparar automáticamente al confirmar el cobro)
- [X] T004 [US1] Agregar acción `enviarArca(Venta $venta)` en `app/Http/Controllers/VentaController.php`: valida `Venta::puedeEnviarseAArca()` y `CertificadoFiscal::activo()` (422/toast si no corresponde — FR-012, research.md §7), reutiliza el método privado existente `emitirComprobanteFiscal()` para el envío real (200 con `ok:true/false` según el resultado), devuelve JSON según el contrato de `contracts/enviar-arca.md`
- [X] T005 [US1] Agregar ruta `POST ventas/{venta}/enviar-arca` → `VentaController::enviarArca`, con middleware `permiso:ventas.ver` (spec 040 §Clarifications), en `routes/web.php`
- [X] T005b [P] [US1] Actualizar `tests/Feature/EmisionComprobanteNotaCreditoDebitoTest.php` (spec 034) para llamar explícitamente a `POST ventas/{venta}/enviar-arca` (T004/T005) en vez de depender del trigger automático eliminado en T003 — sin este ajuste el test queda roto por esta spec. Requiere que T003, T004 y T005 ya existan.
- [X] T006 [US1] Incluir la disponibilidad de la acción (vía `Venta::puedeEnviarseAArca()`) en el listado de Ventas — resuelto directamente en el partial server-side `_row_actions.blade.php` (eager-load de `comprobanteFiscal` en `queryFiltrada()`), sin necesidad de un flag JSON separado
- [X] T007 [US1] Agregar la acción de fila "Enviar a ARCA" en `resources/views/ventas/_row_actions.blade.php` (condicionada a `puedeEnviarseAArca()`), y el modal nuevo `#modal-resultado-arca` (FR-007, research.md §6) en `resources/views/ventas/index.blade.php`
- [X] T008 [US1] Agregar handler en `resources/js/ventas.js`: `confirm()` antes de enviar, POST AJAX a la ruta de T005, deshabilita el botón mientras está en vuelo; si la respuesta es `422` (rechazo de precondición, FR-007a) muestra **toast**; si es `200` (intento real contra ARCA, FR-007) abre el **modal** de T007 con CAE/vencimiento o el motivo del rechazo; en ambos casos recarga sólo la fila/tabla (DataTables `ajax.reload()`) sin recargar la página
- [X] T009 [P] [US1] Test feature: confirmar un cobro ya NO dispara emisión de CAE (verifica que `ComprobanteFiscal` sigue `null` tras `cobranzaStore`), en `tests/Feature/EnvioManualArcaTest.php`
- [X] T010 [P] [US1] Test feature: `POST ventas/{venta}/enviar-arca` sobre una Venta B con datos fiscales válidos obtiene CAE, respondiendo `200 ok:true` (mock de `EmisorComprobante`, reutilizado en `tests/Feature/EmisionComprobanteVentaTest.php`)
- [X] T011 [P] [US1] Test feature: la acción devuelve `422` sin intentar el envío cuando la Venta no es A/B/C, cuando ya tiene `ComprobanteFiscal` aprobado, cuando la Función Avanzada está desactivada, o cuando no hay certificado fiscal configurado (FR-012), en `tests/Feature/EnvioManualArcaTest.php`

**Checkpoint**: US1 completa y demostrable de forma independiente — cierra el incidente del 04/08/2026.

---

## Phase 4: User Story 2 - Corregir la documentación que originó el defecto (Priority: P2)

**Goal**: que `docs/documentacion_principal_crm.md` y `specs/034-.../spec.md` dejen de describir el
envío como automático, y quede registrado el incidente.

**Independent Test**: leer ambos documentos y confirmar que describen la acción manual, con nota del
incidente (quickstart.md Escenario 4).

- [X] T012 [P] [US2] Corregir `FR-004` en `specs/034-facturacion-electronica-arca/spec.md` para reflejar que la solicitud de CAE es manual (acción del usuario), con nota de corrección que referencia la spec 040 y el incidente del 04/08/2026
- [X] T013 [P] [US2] Corregir la sección de Facturación Electrónica en `docs/documentacion_principal_crm.md` (la que dice "al confirmar el primer cobro... automáticamente") para describir la acción manual "Enviar a ARCA" del listado de Ventas, dejando una nota del incidente que motivó el cambio

**Checkpoint**: documentación alineada con el comportamiento real corregido.

---

## Phase 5: Polish & Cross-Cutting

- [ ] T014 Ejecutar quickstart.md Escenarios 1 a 4 manualmente contra **homologación** (nunca producción) y documentar resultados
- [ ] T015 Confirmar con el usuario si corresponde reactivar la Función Avanzada "Facturación Electrónica" en el VPS una vez desplegado este fix (fuera de alcance técnico, pero pendiente operativo real)

## Dependencies & Execution Order

- **Foundational (Phase 2)** bloquea User Story 1 (T003-T011 dependen del helper T001).
- **User Story 1 (Phase 3, P1)**: el MVP — cierra el incidente. T003 y T004/T005 son secuenciales (mismo archivo `VentaController.php`); T006 puede ir en paralelo con T004/T005 (método distinto); T007/T008 dependen de que T005/T006 ya expongan la ruta y el flag. T005b depende de que T003, T004 y T005 ya existan (ajusta un test existente de la spec 034 que de otro modo queda roto). T009-T011 en paralelo entre sí una vez que T003-T006 están implementados (mismo archivo de test nuevo, pero tests independientes).
- **User Story 2 (Phase 4, P2)**: independiente de US1 en términos de archivos (sólo toca docs/specs), pero conceptualmente depende de que US1 ya defina el comportamiento real a documentar — se hace después para evitar describir algo que todavía puede cambiar en clarify/plan.
- **Polish (Phase 5)**: depende de US1 y US2 completas.

## Parallel Execution Examples

- Dentro de US1: T009, T010, T011 en paralelo entre sí (mismo archivo, pero se pueden escribir en simultáneo como bloques independientes antes del commit final); T005b en paralelo con T006 (archivos distintos, ambos dependen sólo de T003-T005).
- Entre historias: US2 (Phase 4) puede redactarse en paralelo con la implementación de US1 (Phase 3) — son archivos completamente distintos (docs vs. código).

## Implementation Strategy

**MVP = User Story 1** (P1): es la corrección real del incidente — sin esto, el sistema sigue en
riesgo de repetir un envío automático no deseado apenas se reactive la Función Avanzada. User Story 2
(P2) es necesaria para que el proyecto no vuelva a asumir "automático" como comportamiento correcto en
una sesión futura, pero no bloquea el uso seguro del sistema una vez que US1 está implementada y
desplegada.
