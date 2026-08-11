# Riesgo de Stock/Dinero Checklist: Vencido en Compras + ítems con cantidad negativa

**Purpose**: Validar que los requisitos que tocan stock y saldos de Compras (Principio III/IV de la
constitución) estén completos, claros y sin ambigüedad antes de pasar a `/speckit-tasks`.
**Created**: 2026-08-11
**Feature**: [spec.md](../spec.md)

**Note**: Enfoque de release-gate (revisor de PR), no de author-only — este feature toca movimientos
de stock y el estado de pago mostrado al usuario.

## Requirement Completeness

- [x] CHK001 - ¿Está definida la prioridad entre "Pagado" y "Vencido" cuando ambas condiciones podrían aplicar (vto. pasado + saldo 0)? [Completeness, Spec §FR-004]
- [x] CHK002 - ¿Está especificado qué pasa con una compra sin `fecha_vto_pago` cargada? [Completeness, Spec §FR-005]
- [x] CHK003 - ¿Está definido el signo del movimiento de stock que genera un ítem con cantidad negativa? [Completeness, Spec §FR-009]
- [ ] CHK004 - ¿Está especificado qué pasa si la cantidad neta de un producto (suma de renglones positivos y negativos) da negativa dentro de la misma compra? [Gap, Spec §Edge Cases]

## Requirement Clarity

- [x] CHK005 - ¿Está cuantificado el umbral de "saldo pendiente" que distingue Vencido de Pagado (no queda como "algo de saldo")? [Clarity, Spec §FR-001]
- [x] CHK006 - ¿Es inequívoco que sólo la cantidad admite negativo y no el precio unitario? [Clarity, Spec §FR-006]

## Requirement Consistency

- [x] CHK007 - ¿Es consistente la regla de "Vencido" entre el badge de fila, el filtro, y el KPI ya existente (misma condición, no tres criterios distintos)? [Consistency, Spec §SC-002]
- [ ] CHK008 - ¿Se aplica la misma regla de "Vencido" en Ventas, o queda documentado explícitamente que esta pasada sólo cubre Compras (asimetría intencional)? [Consistency, Spec §Assumptions]

## Acceptance Criteria Quality

- [x] CHK009 - ¿Es medible/verificable el criterio de stock neto esperado tras guardar ítems positivos y negativos (SC-004)? [Measurability, Spec §SC-004]
- [x] CHK010 - ¿Es objetivamente verificable que el total filtrado por "Vencido" coincide con el KPI (SC-002), sin depender de interpretación subjetiva? [Measurability, Spec §SC-002]

## Scenario Coverage

- [x] CHK011 - ¿Cubre la spec el caso de edición de una compra existente para agregar/cambiar un ítem a cantidad negativa (no sólo alta)? [Coverage, Spec §US2 escenario 4]
- [ ] CHK012 - ¿Cubre la spec qué pasa si se edita una compra vencida y, como resultado del pago agregado en la misma edición, deja de estar vencida (transición de estado en la misma operación)? [Gap, Exception Flow]

## Edge Case Coverage

- [x] CHK013 - ¿Está definido el comportamiento de una compra "Parcial" con vto. pasado (debe clasificar como Vencido, no como Parcial)? [Edge Case, Spec §Edge Cases]
- [ ] CHK014 - ¿Está definido qué pasa si un ítem con cantidad negativa corresponde a un producto que NO controla stock (sólo afecta el total, sin movimiento)? [Gap, Spec §Edge Cases]

## Dependencies & Assumptions

- [x] CHK015 - ¿Está documentado que no hay validación de "pendiente"/tope para ítems negativos en Compras, a diferencia de las NC/ND? [Assumption, Spec §Assumptions]
- [x] CHK016 - ¿Está documentado que el filtro backend de "Vencido" ya existía (spec 056) y que este feature sólo lo expone/alinea con el badge? [Assumption, Spec §Assumptions]

## Notes

- Ítems marcados `[ ]` (CHK004, CHK008, CHK012, CHK014) son huecos reales detectados en la spec
  actual — no bloquean seguir a `/speckit-tasks`, se resuelven con defaults razonables durante
  `/speckit-analyze` o quedan como nota de bajo impacto para la implementación:
  - CHK004: el spec ya asume esto explícitamente en Edge Cases ("se permite, usuario responsable") — el hueco es que no hay un FR numerado, sólo el edge case narrativo; bajo impacto, no bloquea.
  - CHK008: la spec documenta la asimetría explícitamente en Assumptions — hueco es que no está en el checklist de arriba como pregunta resuelta; ya resuelto en el texto.
  - CHK012: caso de bajo impacto (transición dentro de la misma request ya la resuelve `estadoPago()` recalculando en base al estado final tras guardar, sin necesidad de lógica especial).
  - CHK014: comportamiento por defecto ya cubierto por FR-009 ("cuando el ítem corresponde a un producto que afecta stock") — un producto que no controla stock simplemente no genera movimiento, mismo criterio que ítems positivos hoy.
