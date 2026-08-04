# Specification Quality Checklist: Modal "Nueva Nota de Crédito/Débito" completo

**Purpose**: Validar la calidad de los requisitos (spec + plan), no la implementación.
**Created**: 2026-08-04
**Feature**: [spec.md](../spec.md) | [plan.md](../plan.md)
**Depth**: Standard | **Audience**: Reviewer (pre-tasks) | **Focus**: afectación de stock, mes de imputación, paridad Compras/Ventas (zonas de mayor riesgo de la feature)

## Requirement Completeness

- [x] CHK001 - ¿Está definido qué pasa si el comprobante original no tiene ítems de producto y el usuario intenta poner "afecta stock" en Sí? [Completeness, Spec Edge Cases]
- [x] CHK002 - ¿Está especificado el valor por defecto de "¿Queres que afecte Stock?" al abrir el modal? [Completeness, Spec §FR-002]
- [x] CHK003 - ¿Está definido qué formato/granularidad tiene "Mes de Imputación" (día, mes+año, timestamp)? [Completeness, Spec Assumptions]
- [x] CHK004 - ¿Está especificado si el Depósito es obligatorio en todos los casos en que afecta_stock=true? [Completeness, Spec §FR-006]

## Requirement Clarity

- [x] CHK005 - ¿Está cuantificado el "máximo disponible" para ajustar por producto (no sólo descrito como "lo pendiente")? [Clarity, Spec §FR-005]
- [x] CHK006 - ¿Es objetivamente verificable la regla de "paridad" entre el modal de Compras y el de Ventas, o queda como una afirmación vaga? [Clarity, Spec §FR-011]

## Requirement Consistency

- [x] CHK007 - ¿El comportamiento cuando afecta_stock=false es consistente entre esta spec y el comportamiento ya implementado hoy (sin regresión)? [Consistency, Spec §FR-007]
- [x] CHK008 - ¿Las reglas de signo de movimiento de stock (entrada/salida según tipo × módulo) descritas en el plan son consistentes con la lógica ya existente en el controller? [Consistency, Plan/data-model.md]

## Acceptance Criteria Quality

- [x] CHK009 - ¿Los criterios de éxito (SC-001 a SC-004) son medibles sin conocer detalles de implementación? [Measurability, Spec Success Criteria]
- [x] CHK010 - ¿Existe un criterio de aceptación verificable para el tope de cantidad por producto (no sólo un enunciado cualitativo)? [Acceptance Criteria, Spec §US2 Scenario 3]

## Scenario Coverage

- [x] CHK011 - ¿Están cubiertos los tres flujos principales (no afecta stock / sí afecta stock / paridad Compras-Ventas) con al menos un escenario Given/When/Then cada uno? [Coverage, Spec User Stories]
- [x] CHK012 - ¿Está cubierto el escenario de dos notas sucesivas sobre el mismo comprobante y mismo producto (consumo acumulado del cupo)? [Coverage, Spec Edge Cases]

## Edge Case Coverage

- [x] CHK013 - ¿Está definido el comportamiento si el usuario alterna "Sí"→"No" en afecta_stock antes de guardar (se descarta o se conserva la selección de productos)? [Edge Case, Spec Edge Cases]
- [x] CHK014 - ¿Está definido qué pasa con una nota soft-deleted respecto del cálculo de cupo pendiente de un producto? [Edge Case, data-model.md "Regla derivada"]

## Non-Functional Requirements

- [x] CHK015 - ¿Identifica el plan qué parte de esta feature requiere test obligatorio según el principio de testing de la constitución (dinero/stock)? [Traceability, Plan Constitution Check]

## Dependencies & Assumptions

- [x] CHK016 - ¿Está documentada la decisión de mantener "Documento que Ajusta" de sólo lectura (no multi-documento) junto con su razón? [Assumption, Spec Assumptions / research.md]
- [x] CHK017 - ¿Está documentada la dependencia de que ya existan Depósitos configurados para poder probar el flujo de afectar stock? [Dependency, quickstart.md Prerrequisitos]

## Ambiguities & Conflicts

- [x] CHK018 - ¿Queda algún término no cuantificado tipo "razonable", "rápido" o similar sin métrica asociada en spec o plan? [Ambiguity] — no se detectaron.
- [x] CHK019 - ¿Hay algún conflicto entre lo que documenta `docs/documentacion_principal_crm.md` (actualizado en este mismo cambio) y lo que dice el spec sobre el alcance de Mes de Imputación (Compras vs. Ventas)? [Conflict] — no, doc actualizado en la misma tarea, sin contradicción.

## Notes

Todos los ítems pasan en la primera pasada — no se detectaron vacíos, ambigüedades ni
inconsistencias entre spec.md, plan.md, research.md y data-model.md. Listo para `/speckit-tasks`.
