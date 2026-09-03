# Fiscal Requirements Quality Checklist: Envío Manual a ARCA para NC/ND

**Purpose**: Validar que los requisitos de esta spec sean suficientemente completos, claros y
consistentes para una funcionalidad de facturación electrónica (impacto fiscal directo, Principio III de
la constitución).
**Created**: 2026-09-03
**Feature**: [spec.md](../spec.md)

## Requirement Completeness

- [x] CHK001 - ¿Están definidas las precondiciones exactas que habilitan la acción "Enviar a ARCA" para una NC/ND (tipo de comprobante, estado propio, estado del comprobante original)? [Completeness, Spec §FR-003]
- [x] CHK002 - ¿Está definido qué ocurre si el comprobante original (Venta/Compra) no tiene CAE aprobado al momento del envío? [Completeness, Spec §Edge Cases]
- [x] CHK003 - ¿Está especificado el criterio de fallback cuando una NC/ND tiene ítems sin línea de origen (spec 096)? [Completeness, Spec §FR-010]
- [x] CHK004 - ¿Está especificado el criterio quando los ítems son mixtos (algunos con línea de origen, otros sin ella)? [Completeness, Spec §FR-010a]
- [x] CHK005 - ¿Están definidos los requisitos de paridad Venta/Compra para toda la funcionalidad (no sólo el envío)? [Completeness, Spec §FR-011]

## Requirement Clarity

- [x] CHK006 - ¿Está cuantificado qué significa "IVA real por línea" en términos de los campos concretos de datos que se usan (neto, iva_pct)? [Clarity, Spec §FR-009, data-model.md]
- [x] CHK007 - ¿Está claro qué distingue un "rechazo de precondición" (toast) de un "resultado real de ARCA" (modal), sin zona gris? [Clarity, Spec §FR-006, FR-006a]
- [x] CHK008 - ¿Está definido con precisión qué valores puede tomar el "indicador de estado ARCA" (US4) y a qué estado interno corresponde cada uno? [Clarity, Spec §FR-014]

## Requirement Consistency

- [x] CHK009 - ¿Es consistente el criterio de fallback agregado (FR-010/FR-010a) con el ya usado por `AjustesPendientesNotaCreditoDebito::pendiente()` de spec 096, sin introducir un tercer criterio? [Consistency, Spec §Assumptions]
- [x] CHK010 - ¿Es consistente FR-003 (elegibilidad de envío) con FR-015 (estado a mostrar) para evitar que una nota "sin enviar" aparezca con un estado ambiguo respecto del comprobante original? [Consistency, Spec §FR-003, FR-015]
- [x] CHK011 - ¿Los modales propios de NC/ND (Clarifications) se especifican de forma consistente con el patrón ya usado por spec 040 en cuanto a comportamiento (confirmación, persistencia del resultado), aunque no en componente? [Consistency, Spec §Clarifications]

## Acceptance Criteria Quality

- [x] CHK012 - ¿SC-003 (paridad alícuota por alícuota) es objetivamente verificable sin ambigüedad sobre qué constituye "coincidir"? [Measurability, Spec §SC-003]
- [x] CHK013 - ¿SC-001 (cero envíos automáticos) especifica una fuente de verificación concreta? [Measurability, Spec §SC-001]

## Scenario Coverage

- [x] CHK014 - ¿Están cubiertos los escenarios de éxito, rechazo real de ARCA y rechazo de precondición para el envío de NC/ND? [Coverage, Spec §User Story 1]
- [x] CHK015 - ¿Está cubierto el escenario de una NC/ND "global" sin ítems propios? [Coverage, Spec §User Story 2, Acceptance Scenario 4]
- [x] CHK016 - ¿Está cubierto el escenario de doble envío/doble click? [Coverage, Spec §Edge Cases]
- [x] CHK017 - ¿Está cubierto el escenario de visualización de estado para una NC/ND ya aprobada, no sólo para la pendiente de envío? [Coverage, Spec §User Story 4, Acceptance Scenario 5]

## Edge Case Coverage

- [x] CHK018 - ¿Está definido el comportamiento cuando no hay certificado fiscal configurado? [Edge Case, Spec §Edge Cases]
- [x] CHK019 - ¿Está definido el comportamiento cuando la Función Avanzada está desactivada? [Edge Case, Spec §Edge Cases, FR-007]
- [x] CHK020 - ¿Está fuera de alcance explícitamente la inmutabilidad post-CAE de una NC/ND, evitando ambigüedad sobre si esta spec la resuelve? [Edge Case, Spec §Edge Cases, Assumptions]

## Dependencies & Assumptions

- [x] CHK021 - ¿Está documentada la dependencia de esta spec respecto de spec 096 (venta_item_id/compra_item_id) y spec 040 (patrón de interacción)? [Dependency, Spec §Contexto]
- [x] CHK022 - ¿Está validada la asunción de que `MapeadorComprobante::armarBloquesAlicIva()` no requiere cambios para consumir el nuevo payload? [Assumption, Spec §Assumptions, research.md R3]
- [x] CHK023 - ¿Está documentada la asunción de que ningún envío en lote es necesario? [Assumption, Spec §Assumptions]

## Notes

- Los 23 ítems se marcan `[x]` porque, tras revisar la spec, el plan y el data-model, cada pregunta tiene
  respuesta explícita en el texto ya escrito (referencias `[Spec §...]` verificables) — no quedaron
  huecos sin resolver antes de pasar a `/speckit-tasks`.
