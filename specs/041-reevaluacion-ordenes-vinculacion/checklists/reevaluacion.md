# Checklist: Calidad de requisitos — Reevaluación automática de órdenes por vinculación tardía

**Purpose**: Validar que spec.md y plan.md están completos, claros y sin ambigüedades antes de pasar a tasks/implement
**Created**: 2026-08-03
**Feature**: [spec.md](../spec.md) / [plan.md](../plan.md)
**Depth**: Standard | **Audience**: Reviewer (PR) | **Focus**: disparadores evento-driven + cobertura de edge cases

## Requirement Completeness

- [x] CHK001 - ¿Está especificado qué pasa si una vinculación se crea/edita en medio de una sincronización de órdenes en curso del mismo canal (posible condición de carrera)? [Completeness, Spec Edge Cases]
- [x] CHK002 - ¿Están definidos los requisitos para el caso de borrado masivo de vinculaciones (`eliminarTodas()`), o está explícitamente declarado fuera de alcance? [Gap, Plan §R2]
- [x] CHK003 - ¿Está especificado qué debe pasar con el `motivo_detalle` cuando una orden pasa de "requiere atención" a "lista" (se limpia, se conserva)? [Gap]

## Requirement Clarity

- [x] CHK004 - ¿Está cuantificado qué significa "sin demora perceptible" en SC-003, o queda sujeto a interpretación? [Clarity, Spec §SC-003]
- [x] CHK005 - ¿Es unívoco a qué se refiere "todas las órdenes... que la referencian" en FR-001/FR-002 (por publicación/variante puntual, no por producto del CRM en general)? [Clarity, Spec §FR-001]

## Requirement Consistency

- [x] CHK006 - ¿Son simétricos los FR de MercadoLibre y TiendaNube en redacción y alcance (mismo verbo modal, mismo nivel de detalle)? [Consistency, Spec §FR-001/FR-002, §FR-006/FR-007]
- [x] CHK007 - ¿Es consistente el criterio de qué estados de orden se reevalúan (`requiere_atencion` y `lista`) entre el Edge Case de desvinculación (spec) y el alcance de la query descripto en el plan? [Consistency, Spec Edge Cases, Plan/research.md §R4]

## Acceptance Criteria Quality

- [x] CHK008 - ¿Es SC-001 verificable sin conocer la implementación (no menciona Observers, colas, ni mecanismos internos)? [Measurability, Spec §SC-001]
- [x] CHK009 - ¿Definen las Acceptance Scenarios de la User Story 1 un caso donde la orden queda con OTRO motivo pendiente tras vincular (no sólo el caso feliz de queda "lista")? [Coverage, Spec §User Story 1]

## Scenario Coverage

- [x] CHK010 - ¿Están cubiertos los tres tipos de mutación de vinculación (crear, editar, eliminar) en los requisitos, y no sólo "crear"? [Coverage, Spec §FR-001/FR-002]
- [x] CHK011 - ¿Hay un requisito o escenario que cubra qué pasa cuando la creación automática de venta falla durante la reevaluación (no sólo cuando tiene éxito)? [Coverage, Exception Flow, Spec §Acceptance Scenario 6]
- [x] CHK012 - ¿Está definido el comportamiento esperado cuando la vista de órdenes pendientes se abre sin ninguna orden en `requiere_atencion` (caso vacío)? [Edge Case, Gap]

## Non-Functional Requirements

- [x] CHK013 - ¿Especifica la spec un límite superior de volumen a partir del cual la estrategia on-view (barrida completa) dejaría de ser aceptable? [Gap, Spec Assumptions]
- [x] CHK014 - ¿Hay requisitos de trazabilidad/observabilidad (logging) para poder auditar qué disparó cada reevaluación automática, dado que puede terminar creando una venta sin intervención humana directa? [Gap, Non-Functional]

## Dependencies & Assumptions

- [x] CHK015 - ¿Está documentada la dependencia de que `EvaluadorConvertibilidad` (y su equivalente TN) sea la única fuente de verdad para decidir convertibilidad, sin lógica duplicada? [Traceability, Spec §FR-003, Plan §R3]
- [x] CHK016 - ¿Está validada la asunción de volumen ("cientos, no decenas de miles") contra el dato real relevado en producción (396 ML / 3 TN) al momento de esta spec? [Assumption, Spec Assumptions]

## Notes

- Los ítems marcados como [Gap] no bloquean necesariamente el pase a `/speckit-tasks` — se resuelven
  en la fase de `/speckit-analyze` si generan inconsistencia real entre spec/plan/tasks, o quedan
  como decisión de diseño ya tomada implícitamente (ver Assumptions de spec.md).
