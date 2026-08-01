# Requirements Quality Checklist: Saldo Inicial en Cuenta Corriente

**Purpose**: Validar que los requisitos de spec.md sean completos, claros, consistentes y verificables antes de tasks/implementación.
**Created**: 2026-08-01
**Feature**: [spec.md](../spec.md)

## Requirement Completeness

- [x] CHK001 - ¿Está especificado qué pasa cuando `saldo_inicial ≠ 0` pero `saldo_inicial_fecha` es nula? [Completeness, Spec §FR-004]
- [x] CHK002 - ¿Está especificado el comportamiento para Proveedores, no sólo Clientes? [Completeness, Spec §FR-002]
- [x] CHK003 - ¿Está definido qué columnas quedan vacías en la fila sintética "Saldo Inicial" de Movimientos? [Completeness, Spec §FR-008]
- [x] CHK004 - ¿Está especificado el caso de un Cliente con saldo inicial pero sin ninguna Venta? [Completeness, Spec §User Story 1 Acceptance Scenario 1]

## Requirement Clarity

- [x] CHK005 - ¿Está claro qué bucket de antigüedad recibe el saldo inicial y con qué fecha de referencia? [Clarity, Spec §FR-003]
- [x] CHK006 - ¿Está claro qué significa un `saldo_inicial` negativo (resta vs. se ignora)? [Clarity, Spec §FR-005]
- [x] CHK007 - ¿Está claro el criterio de exclusión de un Cliente/Proveedor del listado (total ≈ 0)? [Clarity, Spec §FR-006]

## Requirement Consistency

- [x] CHK008 - ¿Es consistente el tratamiento del saldo inicial entre "Saldos Clientes" y el Dashboard (misma fuente de cálculo)? [Consistency, Spec §FR-007]
- [x] CHK009 - ¿Es consistente la regla de "fecha nula → A Vencer" con la que ya aplica hoy a `fecha_vto_cobro`/`fecha_vto_pago`? [Consistency, Spec §Edge Cases]
- [x] CHK010 - ¿El invariante Movimientos↔Saldos Clientes ya probado en spec 029 queda explícitamente extendido para cubrir el saldo inicial? [Consistency, Spec §FR-009]

## Acceptance Criteria Quality

- [x] CHK011 - ¿SC-001 a SC-004 son medibles sin conocer la implementación? [Measurability, Spec §Success Criteria]
- [x] CHK012 - ¿Está definido un caso de "cero regresión" para clientes sin saldo inicial, verificable de forma objetiva? [Measurability, Spec §SC-004]

## Scenario Coverage

- [x] CHK013 - ¿Está cubierto el caso de saldo inicial + Venta en el mismo bucket? [Coverage, Spec §Edge Cases]
- [x] CHK014 - ¿Está cubierto el caso de saldo inicial + Venta en buckets distintos? [Coverage, Spec §User Story 1 Acceptance Scenario 2]
- [x] CHK015 - ¿Está cubierto el filtro por Operación = "Saldo Inicial" en Movimientos? [Coverage, Spec §User Story 2 Acceptance Scenario 4]

## Edge Case Coverage

- [x] CHK016 - ¿Está definido el tratamiento de un saldo inicial que compensa exactamente el resto de la deuda (total = 0)? [Edge Case, Spec §Edge Cases]
- [x] CHK017 - ¿Está definido qué pasa si se edita el saldo inicial de un Cliente/Proveedor después de creado (recalculo vs. snapshot)? [Edge Case, Spec §Edge Cases]

## Non-Functional Requirements

- [x] CHK018 - ¿Hay un requisito explícito de no-regresión de performance (sin N+1 adicional) para el nuevo cálculo? [Coverage, Spec — sólo en plan.md Technical Context, no en Success Criteria]

## Dependencies & Assumptions

- [x] CHK019 - ¿Está documentado que Proveedores sigue sin pantalla propia de Informe de Cuenta Corriente (fuera de alcance)? [Assumption, Spec §Assumptions]
- [x] CHK020 - ¿Está documentado que no hay versionado/historial de cambios al saldo inicial? [Assumption, Spec §Assumptions]

## Notes

- CHK018 es un detalle menor (formalizar el requisito de performance también en Success Criteria, no
  sólo en plan.md Technical Context) que no bloquea `/speckit-tasks` — mismo criterio ya usado en
  spec 029 (CHK017 de esa spec), se resuelve con el mismo estándar ya validado (research.md R6).
