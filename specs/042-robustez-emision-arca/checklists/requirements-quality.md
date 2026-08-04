# Specification Quality Checklist: Robustez de datos fiscales en la emisión de CAE (ARCA)

**Purpose**: Validar la calidad y completitud de los requisitos antes de pasar a `tasks`/`implement`
**Created**: 2026-08-04
**Feature**: [spec.md](../spec.md)
**Depth**: Standard | **Audience**: Autor + revisión previa a implementación fiscal

## Requirement Completeness

- [X] CHK001 - ¿Está especificado qué códigos ARCA de alícuota de IVA soporta el sistema (0%, 10,5%, 21%, 27%, 5%, 2,5%)? [Completeness, Spec Assumptions]
- [X] CHK002 - ¿Está definido el comportamiento cuando un ítem tiene una alícuota sin código ARCA soportado? [Completeness, Spec §FR-004]
- [X] CHK003 - ¿Está especificada la fuente exacta del dato de Condición de IVA del receptor (`Cliente->condicionIva`)? [Completeness, Spec §FR-005]
- [X] CHK004 - ¿Está definido el comportamiento cuando el cliente no tiene Condición de IVA cargada, distinguiendo el caso de receptor anónimo? [Completeness, Spec §FR-006, §FR-007]
- [X] CHK005 - ¿Está especificado si esta spec modifica el cálculo de neto/IVA de NC/ND (que no tiene desglose de ítems)? [Completeness, Spec Edge Cases]

## Requirement Clarity

- [X] CHK006 - ¿Está la tolerancia de redondeo de FR-003/FR-004 cuantificada con un valor numérico específico ($0.01), en vez de un término vago como "razonable"? [Clarity, Spec §FR-004, Clarifications]
- [X] CHK007 - ¿Está claro si la tolerancia aplica por comprobante total o por bloque `AlicIva` individual? [Clarity, Spec Clarifications]
- [X] CHK008 - ¿Es medible/verificable el criterio de "alícuota efectivamente presente en los ítems" sin ambigüedad de agrupación? [Clarity, Spec §FR-001]

## Requirement Consistency

- [X] CHK009 - ¿Es consistente el mecanismo de comunicación de estos nuevos rechazos de precondición (toast, no modal) con el ya definido en spec 040 FR-007a? [Consistency, Spec §FR-008]
- [X] CHK010 - ¿Es consistente el alcance declarado (no modificar spec 040 ni la lógica de negocio de NC/ND) con los requisitos funcionales listados (ninguno redefine cuándo se dispara la emisión)? [Consistency, Spec §FR-008, §FR-009]

## Acceptance Criteria Quality

- [X] CHK011 - ¿Es SC-001 (0 rechazos código 10051) verificable objetivamente contra `arca_logs_auditoria`? [Measurability, Spec §SC-001]
- [X] CHK012 - ¿Es SC-002 (100% de solicitudes con `CondicionIVAReceptorId`) verificable sin ambigüedad sobre qué cuenta como "solicitud"? [Measurability, Spec §SC-002]

## Scenario Coverage

- [X] CHK013 - ¿Cubren los escenarios de aceptación el caso de una única alícuota (no-regresión) además del caso de alícuotas mixtas? [Coverage, Spec §US1]
- [X] CHK014 - ¿Cubren los escenarios de aceptación tanto el receptor identificado como el anónimo (Consumidor Final sin CUIT/DNI) para `CondicionIVAReceptorId`? [Coverage, Spec §US2]

## Edge Case Coverage

- [X] CHK015 - ¿Está definido el comportamiento para ítems con alícuota 0% gravada (distinta de exento/`ImpOpEx`)? [Edge Case, Spec Edge Cases]
- [X] CHK016 - ¿Está definido el comportamiento cuando dos ítems distintos comparten la misma alícuota (agrupación en un único bloque)? [Edge Case, Spec Edge Cases]

## Dependencies & Assumptions

- [X] CHK017 - ¿Está documentada y validada la asunción de que `condiciones_iva.codigo_afip` ya usa los códigos oficiales de "Condición IVA Receptor" de ARCA? [Assumption, Spec Assumptions]
- [X] CHK018 - ¿Está documentada la asunción de que `VentaItem.iva_pct` refleja fielmente la alícuota real (fuera de alcance auditar cómo se carga)? [Assumption, Spec Assumptions]

## Notes

- Items marcados incompletos requieren actualización de la spec antes de `/speckit-plan` (ya completado) o antes de considerar la spec lista para `tasks`.
