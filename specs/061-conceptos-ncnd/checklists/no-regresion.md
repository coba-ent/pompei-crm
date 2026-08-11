# No-Regresión (release-gate) Checklist: Percepciones/Impuestos Internos/Intereses en NC/ND

**Purpose**: Validar que la spec no deje ambigüedad sobre qué comportamiento ya construido (stock,
CAE, bloqueos de edición) debe permanecer intacto al agregar la persistencia de conceptos.
**Created**: 2026-08-11
**Feature**: [spec.md](../spec.md)

## Requirement Completeness

- [x] CHK001 - ¿Está explícito que los conceptos no participan del cálculo de stock ni de la lógica de CAE/ARCA? [Completeness, Spec §FR-010]
- [x] CHK002 - ¿Está definido dónde se persisten los conceptos (columna `impuestos` ya existente) sin requerir migración nueva? [Completeness, Spec §Assumptions]
- [x] CHK003 - ¿Especifica la spec qué pasa con una fila de concepto sin `concepto` elegido/tipeado al guardar? [Completeness, Spec §FR-008]

## Requirement Clarity

- [x] CHK004 - ¿Es inequívoco qué catálogo de percepciones se usa (el mismo fijo de 27, no uno nuevo)? [Clarity, Spec §FR-002]
- [x] CHK005 - ¿Es claro que "Impuesto Interno"/"Interés" usan texto libre en vez del selector de percepciones? [Clarity, Spec §FR-003]

## Requirement Consistency

- [x] CHK006 - ¿Es consistente el orden de cálculo del Total (subtotal con descuento + conceptos) con el ya usado en Ventas/Compras/Presupuestos? [Consistency, Spec §FR-005]
- [x] CHK007 - ¿Es consistente el comportamiento entre Ventas y Compras (mismo patrón espejo)? [Consistency, Spec §FR-009]

## Acceptance Criteria Quality

- [x] CHK008 - ¿Es objetivamente verificable que "cero pérdida de datos entre guardar y reabrir" (SC-002) sin depender de interpretación subjetiva? [Measurability, Spec §SC-002]

## Scenario Coverage

- [x] CHK009 - ¿Cubre la spec el caso de eliminar una nota que tiene conceptos cargados (no quedan huérfanos)? [Coverage, Spec §User Story 1 escenario 6]
- [x] CHK010 - ¿Cubre la spec múltiples filas del mismo tipo/concepto repetido? [Coverage, Spec §Edge Cases]

## Dependencies & Assumptions

- [x] CHK011 - ¿Está documentado que no se modifica el PDF de NC/ND ni la lógica de ARCA ya construida? [Assumption, Spec §Assumptions]
- [x] CHK012 - ¿Está documentado que el catálogo de percepciones se reutiliza sin duplicar lógica de negocio nueva? [Assumption, Spec §Assumptions]

## Notes

- Todos los ítems pasan — no quedan gaps abiertos antes de `/speckit-tasks`.
