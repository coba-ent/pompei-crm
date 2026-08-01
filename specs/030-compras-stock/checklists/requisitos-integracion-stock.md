# Requisitos: Integración Compras↔Stock Checklist

**Purpose**: Validar la calidad de los requisitos (no la implementación) en torno a fidelidad estructural con Contagram, atomicidad de los movimientos de stock, y simetría alta/edición/baja, antes de pasar a tasks.
**Created**: 2026-08-01
**Feature**: [spec.md](../spec.md)

## Fidelidad estructural (Contagram real)

- [x] CHK001 - ¿Está documentada la evidencia (o su ausencia) del informe relevado para justificar no agregar un campo de depósito al formulario de Compra? [Traceability, Spec §Assumptions]
- [x] CHK002 - ¿Se especifica qué pasa si en el futuro se releva que Contagram real sí tiene un selector de depósito en Compras (para no dejar la decisión implícita como definitiva)? [Completeness, Spec §Assumptions]
- [x] CHK003 - ¿Están los requisitos de esta feature libres de introducir cualquier elemento de UI/pantalla nuevo no relevado, más allá del comportamiento interno de stock? [Consistency, Spec §FR-006]

## Atomicidad de los movimientos de stock

- [x] CHK004 - ¿Especifica la spec que la suma/resta de stock debe ser atómica junto con el guardado de la Compra y sus ítems (sin movimientos huérfanos ante fallos parciales)? [Completeness, Spec §FR-007]
- [x] CHK005 - ¿Está definido el comportamiento cuando falla el guardado de un ítem después de haber aplicado parcialmente movimientos de stock de otros ítems de la misma Compra? [Gap, Edge Case]
- [x] CHK006 - ¿Es medible/verificable el requisito de atomicidad (FR-007) sin conocer detalles de implementación (transacciones, locks)? [Measurability, Spec §FR-007]

## Simetría alta / edición / baja

- [x] CHK007 - ¿Los tres requisitos de alta (FR-001), edición (FR-003) y baja (FR-004) usan el mismo criterio de "producto que controla stock" sin definiciones divergentes? [Consistency, Spec §FR-001/FR-003/FR-004]
- [x] CHK008 - ¿Especifica la spec el caso de edición donde un ítem cambia de producto (no sólo de cantidad), y que el efecto neto sea equivalente a reintegrar+reaplicar? [Coverage, Spec §US2 Acceptance Scenario 2]
- [x] CHK009 - ¿Es verificable de forma objetiva el resultado esperado de la edición (SC-002 "cero desviación") sin ambigüedad sobre qué se compara contra qué? [Measurability, Spec §SC-002]
- [x] CHK010 - ¿Define la spec explícitamente qué depósito usa el reintegro por baja/edición cuando el depósito por defecto del CRM cambió entre el alta y la baja/edición? [Edge Case, Spec §Edge Cases]

## Consistencia con funcionalidad ya existente (NC/ND de Compra)

- [x] CHK011 - ¿Queda explícito en la spec que las NC/ND de Compra ya afectan stock hoy y que esta feature no las modifica, para evitar que una futura lectura interprete que hay que construirlas? [Clarity, Spec §Assumptions]
- [x] CHK012 - ¿Se documenta la relación (o ausencia de relación) entre el `deposito_id` que ya usan las NC/ND de Compra y el "depósito por defecto" que usará esta feature, para que no parezcan criterios contradictorios? [Consistency, Gap]

## Notes

- Ninguno de estos ítems evalúa si el código funciona — evalúan si la spec está completa, clara,
  consistente y medible en las tres dimensiones pedidas por el usuario (fidelidad estructural,
  atomicidad, simetría alta/edición/baja).
- CHK005, CHK010 y CHK012 señalan huecos reales de la spec — se resuelven antes de pasar a `/speckit-tasks`.
