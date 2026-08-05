# Tasks: Comprobante por defecto derivado de la Condición de IVA

**Input**: Design documents from `/specs/048-comprobante-defecto-condicion-iva/`
**Prerequisites**: plan.md, spec.md, research.md, data-model.md

**Tests**: no aplica — es una derivación de UX/frontend sin lógica de emisión de comprobantes nueva
(plan.md, Constitución IV); el proyecto no tiene suite de tests de JS, se verifica manualmente en
navegador vía `quickstart.md`, igual criterio que el resto de `cliente-modal.js` (spec 037/047).

**Organization**: una única historia de usuario cubre el mecanismo central (US1+US2 del spec se
implementan juntas porque comparten el mismo cambio de código); US3 es la verificación de que también
se dispara desde el autocompletado del padrón.

## Format: `[ID] [P?] [Story] Description`

---

## Phase 1: Setup

*No aplica — no hay dependencias ni configuración nueva que instalar.*

## Phase 2: Foundational

*No aplica — no hay prerequisito bloqueante compartido entre historias: es un único archivo, un único cambio.*

---

## Phase 3: User Story 1 - Comprobante por defecto se autocompleta al elegir Condición de IVA (Priority: P1) 🎯 MVP

**Goal**: al elegir una Condición de IVA en el modal de cliente, "Comprobante por defecto" se completa
solo con Factura A (Responsable Inscripto) o Factura B (cualquier otra), sin pisar una edición manual
previa del propio campo (Historia 2 del spec, implementada en el mismo cambio por compartir código).

**Independent Test**: abrir "Nuevo Cliente", elegir "Responsable Inscripto" → confirmar "Factura A";
cambiar a "Monotributista" → confirmar "Factura B"; editar el campo a mano y volver a cambiar la
condición → confirmar que el valor editado no se pisa (quickstart.md Escenarios 1 y 2).

### Implementación de User Story 1

- [ ] T001 [US1] Agregar `'tipo_comprobante_defecto'` a `CAMPOS_PADRON` en `resources/js/cliente-modal.js` (research.md R3) para que el mecanismo de "tocado" ya existente (`tocadoPadron`, `resetearTocadoPadron()`, el listener genérico `input change` sobre `CAMPOS_PADRON`) cubra también este campo sin código adicional
- [ ] T002 [US1] Crear función `derivarComprobantePorCondicionIva()` en `resources/js/cliente-modal.js`: lee el texto visible de la `<option>` seleccionada de `select[name="condicion_iva_id"]` (mismo patrón de match por texto ya usado en `autocompletarDesdePadron()` para `condicion_iva`), completa `select[name="tipo_comprobante_defecto"]` con `'A'` si el texto es exactamente `'Responsable Inscripto'`, `'B'` en cualquier otro caso — sólo si `!tocadoPadron.tipo_comprobante_defecto` (research.md R2)
- [ ] T003 [US1] Enganchar `derivarComprobantePorCondicionIva()` al evento `change` de `select[name="condicion_iva_id"]` (delegado sobre `$form`, mismo bloque donde ya está el listener de `.js-provincia` en `resources/js/cliente-modal.js`) (research.md R1)
- [ ] T004 [US1] En `autocompletarDesdePadron()` (`resources/js/cliente-modal.js`), agregar `.trigger('change')` al `$select` de `condicion_iva_id` cuando se le asigna un valor nuevo del padrón, para que dispare el listener de T003 y la derivación también aplique al autocompletado por "Verificar" (research.md R1 — cubre además la Historia 3 del spec)

**Checkpoint**: con T001-T004 la feature está completa y es el único cambio de código necesario — MVP y alcance total en un solo checkpoint (no hay historias P2/P3 con código propio).

---

## Phase 4: Polish & Cross-Cutting

- [ ] T005 [P] `npm run build` para regenerar `public/build/` con el `cliente-modal.js` actualizado
- [ ] T006 Verificar manualmente en navegador los 4 escenarios de `quickstart.md` (alta con derivación, edición manual no pisada, autocompletado por padrón, edición de cliente existente sin recálculo al abrir)
- [ ] T007 [P] Confirmar que `docs/documentacion_principal_crm.md` §2.1 (ya actualizado durante `/speckit-plan`) sigue siendo consistente con la implementación final; ajustar si algo cambió

---

## Dependencies & Execution Order

- T001 → T002 (la función de derivación depende de que el campo ya esté en `CAMPOS_PADRON` para poder consultar `tocadoPadron`).
- T002 → T003 → T004 (orden natural: primero la función, después engancharla al evento manual, después extender el autocompletado del padrón para que dispare el mismo evento).
- Polish (Phase 4) depende de que Phase 3 esté completa.

## Parallel Example

```
T001-T004 son cambios secuenciales sobre el mismo archivo (cliente-modal.js) — no paralelizables entre sí.
T005 (build) y T007 (docs) sí son paralelizables entre sí una vez completada la Phase 3.
```

## Implementation Strategy

**Todo en un solo paso**: a diferencia de specs más grandes, acá no hay MVP parcial que tenga sentido
entregar por separado — las 4 historias del spec (autocompletado, no pisar edición manual, disparo
desde el padrón, no recalcular al abrir edición) comparten exactamente el mismo cambio de 4 tareas
(T001-T004). No hay forma de entregar la Historia 1 sin la 2 (serían inconsistentes entre sí: completar
sin poder corregir a mano sería peor UX que no completar nada).
