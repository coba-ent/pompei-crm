# Checklist: Integridad de tesorería, validación de montos y consistencia UI del desplegable

**Purpose**: Validar la calidad de los requisitos de la spec antes de pasar a tasks/implementación
**Created**: 2026-08-07
**Feature**: [spec.md](../spec.md)
**Focus**: integridad de tesorería, validación de montos, consistencia UI del desplegable de acciones
**Depth**: Standard | **Audience**: Autor/Revisor previo a `/speckit-tasks`

## Requirement Completeness

- [x] CHK001 - ¿Está especificado qué pasa con el `MovimientoTesoreria` asociado cuando se edita el monto o la cuenta de una cobranza? [Completeness, Spec §FR-005]
- [x] CHK002 - ¿Está definido el comportamiento cuando se intenta editar una cobranza ya anulada? [Completeness, Spec §FR-006, Edge Cases]
- [x] CHK003 - ¿Está especificado qué opciones debe tener el desplegable de acciones de la tabla de cobranzas? [Completeness, Spec §FR-007]
- [x] CHK004 - ¿Está documentado qué pasa si el `Cobro` editado no tiene `MovimientoTesoreria` asociado (caso anómalo)? [Completeness, Spec §FR-006a]

## Requirement Clarity

- [x] CHK005 - ¿Está cuantificado el límite máximo de monto editable en términos verificables (saldo pendiente + monto actual), en vez de una noción vaga como "no exceder lo cobrable"? [Clarity, Spec §FR-003]
- [x] CHK006 - ¿Es inequívoco cuáles campos son editables y cuáles no (p. ej. `venta_id` no editable)? [Clarity, Spec §FR-001, Data Model]
- [x] CHK007 - ¿Está claro que "actualizar in-place" significa modificar el mismo registro de movimiento y no crear uno nuevo? [Clarity, Spec §FR-005]

## Requirement Consistency

- [x] CHK008 - ¿La regla de validación de monto en edición es consistente con la regla ya aplicada en el alta (mismo criterio, ajustado por el monto actual)? [Consistency, Spec §FR-003, research.md §2]
- [x] CHK009 - ¿El comportamiento de "Eliminar" y "Ver recibo" declarado para el nuevo desplegable es consistente con el comportamiento actual (sin cambios de lógica, sólo de ubicación)? [Consistency, Spec §FR-008, §FR-009]

## Acceptance Criteria Quality

- [x] CHK010 - ¿Los criterios de éxito de integridad de tesorería (SC-002) son objetivamente verificables (sin movimientos duplicados ni huérfanos)? [Measurability, Spec §SC-002]
- [x] CHK011 - ¿El criterio de rechazo de sobre-cobro (SC-003) es verificable con un caso concreto (monto, saldo pendiente, resultado esperado)? [Measurability, Spec §SC-003, User Story 3]

## Scenario Coverage

- [x] CHK012 - ¿Están cubiertos los escenarios de edición de cada campo por separado (monto, fecha, cuenta, nota)? [Coverage, Spec User Story 1]
- [x] CHK013 - ¿Está cubierto el escenario de cerrar el modal sin guardar? [Coverage, Spec User Story 1, Acceptance Scenario 4]
- [x] CHK014 - ¿Está definido si se debe regenerar o invalidar el recibo PDF ya emitido cuando se edita una cobranza posterior a su emisión? [Assumption, Spec Assumptions]

## Edge Case Coverage

- [x] CHK015 - ¿Está definido el comportamiento ante ediciones concurrentes de la misma cobranza por dos usuarios? [Edge Case, Spec Edge Cases]
- [x] CHK016 - ¿Está definida la validación de fecha en edición (misma regla que alta, incluyendo fecha futura o anterior a la venta)? [Edge Case, Spec Edge Cases]
- [x] CHK017 - ¿Está definido el rechazo de monto vacío o en $0 en edición? [Edge Case, Spec Edge Cases]

## Non-Functional Requirements

- [x] CHK018 - ¿Está especificado que la actualización debe reflejarse en pantalla sin recargar la página (consistente con el resto del CRM)? [Completeness, Spec §FR-010]
- [x] CHK019 - ¿Está especificado el mecanismo de notificación (toast, no alert nativo) para éxito/error de la edición? [Completeness, Spec §FR-011]

## Dependencies & Assumptions

- [x] CHK020 - ¿Está documentada la asunción de que no se requiere historial/auditoría de cambios de cobranzas en este alcance? [Assumption, Spec Assumptions]
- [x] CHK021 - ¿Está documentada la dependencia del patrón visual `_row_actions.blade.php` ya existente en el resto del CRM como base del nuevo desplegable? [Dependency, Spec Assumptions, research.md §3]

## Ambiguities & Conflicts

- [x] CHK022 - ¿Se documenta explícitamente que esta funcionalidad no está relevada en Contagram real (informe de ingresos) y constituye una extensión propia del CRM? [Traceability, Constitution Principio I, Spec Assumptions]

## Notes

- Los tres gaps detectados (CHK004, CHK014, CHK022) se resolvieron actualizando `spec.md`
  (FR-006a y dos nuevas Assumptions). Pendiente real fuera de esta spec: reflejar la nota de
  extensión propia en `docs/documentacion_principal_crm.md`, incluido como tarea en `tasks.md`.
