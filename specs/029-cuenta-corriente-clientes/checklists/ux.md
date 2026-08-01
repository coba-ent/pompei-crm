# UX Requirements Quality Checklist: Cuenta Corriente Clientes

**Purpose**: Validar que los requisitos de spec.md sean completos, claros, consistentes y verificables antes de tasks/implementación.
**Created**: 2026-07-31
**Feature**: [spec.md](../spec.md)

## Requirement Completeness

- [x] CHK001 - ¿Están especificadas las columnas exactas de "Saldos Clientes" y su origen? [Completeness, Spec §FR-002]
- [x] CHK002 - ¿Están especificadas las columnas exactas de "Movimientos" y su origen por tipo de operación? [Completeness, Spec §FR-005]
- [x] CHK003 - ¿Está definido el comportamiento cuando un cliente no tiene movimientos? [Completeness, Spec §Edge Cases]
- [ ] CHK004 - ¿Está especificado qué paginación/tamaño de página por defecto usan las tablas? [Gap]

## Requirement Clarity

- [x] CHK005 - ¿Está claro el criterio exacto de bucketing por antigüedad (campo de fecha, límites de días)? [Clarity, Spec §FR-002, data-model.md]
- [x] CHK006 - ¿Está claro qué campos quedan vacíos en una fila de tipo Cobro vs. Venta en Movimientos? [Clarity, Spec §User Story 2 Acceptance Scenario 5]

## Requirement Consistency

- [x] CHK007 - ¿El Total de Saldos Clientes es consistente (misma fuente de cálculo) con el "A Cobrar" ya usado en Tesorería/Dashboard? [Consistency, Spec §SC-003]
- [x] CHK008 - ¿La exclusión de Cuenta Corriente Proveedores es consistente con lo ya documentado como pendiente en `documentacion_principal_crm.md` §7? [Consistency, Spec §FR-010]

## Acceptance Criteria Quality

- [x] CHK009 - ¿SC-001 a SC-004 son medibles sin conocer la implementación? [Measurability, Spec §Success Criteria]
- [ ] CHK010 - ¿SC-001 ("10 segundos", "sin scroll horizontal en desktop") tiene un ancho de pantalla de referencia acordado? [Ambiguity, Spec §SC-001]

## Scenario Coverage

- [x] CHK011 - ¿Están cubiertos filtrado por Cliente y por Operación en Movimientos? [Coverage, Spec §User Story 2]
- [x] CHK012 - ¿Está cubierto el ordenamiento de la columna Total en Saldos Clientes? [Coverage, Spec §User Story 1 Acceptance Scenario 4]

## Edge Case Coverage

- [x] CHK013 - ¿Está definido el tratamiento de saldo a favor (negativo)? [Edge Case, Spec §FR-007]
- [x] CHK014 - ¿Está definido qué pasa con Notas de Crédito/Débito en ambos tabs? [Edge Case, Spec §Edge Cases]
- [x] CHK015 - ¿Está definido que Otros Ingresos con cliente_id NO forman parte de este cálculo? [Edge Case, Spec §Edge Cases]

## Non-Functional Requirements

- [x] CHK016 - ¿Está referenciada la regla de diseño obligatoria (DataTables server-side AJAX)? [Coverage, Spec §FR-009]
- [ ] CHK017 - ¿Hay un requisito explícito de performance (p.ej. "sin N+1 por fila") en el spec, o sólo en plan.md? [Gap, Spec — sólo en plan.md Technical Context, no en Success Criteria]

## Dependencies & Assumptions

- [x] CHK018 - ¿Está documentada la decisión de descartar el código huérfano previo (tests/export)? [Assumption, Spec §Assumptions]
- [x] CHK019 - ¿Está documentada la exclusión de exportación CSV/PDF en esta iteración? [Assumption, Spec §Assumptions]
- [x] CHK020 - ¿Está documentada la falta de confirmación sobre el punto de entrada exacto en el sidebar? [Assumption, Spec §Assumptions]

## Notes

- CHK004, CHK010, CHK017 son detalles menores (paginación por defecto, ancho de referencia, formalizar el requisito de performance también en Success Criteria) que no bloquean `/speckit-tasks` — se resuelven con los defaults ya usados en Informe de Stock (misma pantalla de referencia) durante la implementación.
