# Specification Quality Checklist: Descuento general aplicado proporcionalmente a neto e IVA

**Purpose**: Validar la calidad y completitud de los requerimientos antes de pasar a tasks/implement
**Created**: 2026-08-04
**Feature**: [spec.md](../spec.md)
**Focus**: cálculo fiscal (neto/IVA/descuento), consistencia entre Presupuestos y Ventas, no-regresión

## Requirement Completeness

- [X] CHK001 - ¿Está especificado qué le pasa a `subtotal_sin_descuento` (invariante) frente a lo que
      sí cambia (`total`, `subtotal_con_descuento`, `descuento`)? [Completeness, Spec §Key Entities]
- [X] CHK002 - ¿Está definido el comportamiento cuando `descuento_general_pct` combina con
      `descuento_pct` de línea? [Completeness, Spec §Edge Cases]
- [X] CHK003 - ¿Está especificado qué pasa con comprobantes que tienen ítems en más de una alícuota de
      IVA junto con descuento general? [Completeness, Spec §FR-004]
- [X] CHK004 - ¿Está definido si los comprobantes ya emitidos con CAE (antes de esta corrección) se
      recalculan o quedan como están? [Completeness, Spec §FR-007]

## Requirement Clarity

- [X] CHK005 - ¿"Proporcionalmente" está cuantificado con una fórmula concreta (factor
      `1 - pct/100` aplicado a neto e IVA) en vez de quedar como término ambiguo? [Clarity, Spec §FR-001, §FR-002]
- [X] CHK006 - ¿Está cuantificada la tolerancia de redondeo aceptable entre la suma por ítem y el
      total agregado? [Clarity, Spec §Edge Cases]
- [X] CHK007 - ¿"Menor o igual" en FR-003 está acotado a los casos con `descuento_general_pct > 0`,
      sin ambigüedad sobre el caso 0%? [Clarity, Spec §FR-003, §FR-006]

## Requirement Consistency

- [X] CHK008 - ¿Los requerimientos para Ventas y Presupuestos son consistentes entre sí (mismo
      servicio, mismo resultado esperado) sin reglas contradictorias por entidad? [Consistency, Spec §FR-005]
- [X] CHK009 - ¿La spec es consistente con las precondiciones ya establecidas en spec 042
      (`ValidadorDatosFiscales`, tolerancia $0.01) sin redefinirlas de forma distinta? [Consistency, Spec §Edge Cases]

## Acceptance Criteria Quality

- [X] CHK010 - ¿Los criterios de éxito (SC-001 a SC-004) son verificables sin conocer la
      implementación (no mencionan clases/métodos)? [Measurability, Spec §Success Criteria]
- [X] CHK011 - ¿SC-002 y SC-003 son objetivamente medibles con datos concretos (caso real de
      referencia) en vez de una afirmación cualitativa? [Measurability, Spec §SC-002, §SC-003]

## Scenario Coverage

- [X] CHK012 - ¿Está cubierto el escenario de no-regresión (sin descuento general)? [Coverage, Spec §Edge Cases]
- [X] CHK013 - ¿Está cubierto el escenario de múltiples alícuotas con descuento general? [Coverage, Spec §User Story 1, Acceptance Scenario 3]
- [X] CHK014 - ¿Está cubierto el flujo de conversión Presupuesto → Venta como escenario de
      consistencia? [Coverage, Spec §User Story 2]

## Dependencies & Assumptions

- [X] CHK015 - ¿Está documentada la dependencia con spec 042 (mismo mecanismo de rechazo de
      precondición, misma tolerancia)? [Dependency, Spec §Assumptions]
- [X] CHK016 - ¿Está documentado explícitamente que no se toca el cálculo de NC/ND (fuera de
      alcance)? [Assumption, Spec §Assumptions]
- [X] CHK017 - ¿Está validado el supuesto de que no hay otros consumidores de `VentaItem.subtotal`
      fuera de Ventas/Presupuestos/ARCA que se vean afectados por el cambio de significado del
      campo? [Assumption, Spec §Assumptions]

## Notes

- Checklist generada con foco en cálculo fiscal (neto/IVA/descuento) y consistencia entre módulos,
  dado que es el área de mayor riesgo de esta spec (constitución del proyecto, Principio III/IV).
- Todos los ítems pasaron en la primera revisión — la spec ya incorpora la decisión de negocio
  confirmada por el usuario y el caso real de referencia (Venta 0001-00016359) como ancla concreta
  para clarity/measurability.
