# Requirements Quality Checklist: Envío Manual a ARCA desde el listado de Ventas

**Purpose**: Validar que la spec corrige sin ambigüedad el defecto de emisión automática y que la
acción manual de reemplazo queda completamente especificada antes de planificar/implementar.
**Created**: 2026-08-04
**Feature**: [spec.md](../spec.md)

## Requirement Completeness

- [X] CHK001 - ¿Está especificado qué reemplaza exactamente al trigger automático eliminado? [Completeness, Spec §FR-001/FR-002]
- [X] CHK002 - ¿Está definida la condición exacta que habilita/deshabilita la acción "Enviar a ARCA" por fila? [Completeness, Spec §FR-003/FR-004]
- [X] CHK003 - ¿Está especificado qué pasa si la Función Avanzada de Facturación Electrónica está desactivada al momento de intentar el envío? [Completeness, Spec §FR-008]
- [X] CHK004 - ¿Está definido qué permiso protege la nueva acción? [Completeness, Spec §Clarifications, FR-011]
- [X] CHK005 - ¿Está especificado cómo se comunica el resultado del envío (éxito/error) al usuario? [Completeness, Spec §FR-007]

## Requirement Clarity

- [X] CHK006 - ¿"Confirmación explícita antes de enviar" (FR-005) está descripta con suficiente detalle para no interpretarse como opcional? [Clarity, Spec §FR-005]
- [X] CHK007 - ¿Queda claro que "actualización de la fila sin recargar" no implica una re-renderización completa del listado? [Clarity, Spec §FR-007]
- [X] CHK008 - ¿El término "Venta con Tipo de Comprobante A/B/C" está acotado sin ambigüedad respecto a otros tipos existentes (X, Y, u otros no fiscales)? [Clarity, Spec §FR-003]

## Requirement Consistency

- [X] CHK009 - ¿Los Acceptance Scenarios de User Story 1 son consistentes con los Functional Requirements (mismo criterio de disponibilidad en ambos)? [Consistency, Spec §US1/FR-003/FR-004]
- [X] CHK010 - ¿La spec es consistente con el Principio III de la constitución (resiliencia ante caídas de ARCA, reintento manual sin pérdida de datos)? [Consistency, constitution.md §III]
- [X] CHK011 - ¿La corrección documentada en User Story 2 no deja contradicciones residuales entre `spec.md` de la 034 y `documentacion_principal_crm.md`? [Consistency, Spec §US2]

## Acceptance Criteria Quality

- [X] CHK012 - ¿Los Success Criteria (SC-001 a SC-004) son verificables sin conocer la implementación (por ejemplo, contra `arca_logs_auditoria`, sin nombrar clases/métodos)? [Measurability, Spec §Success Criteria]
- [X] CHK013 - ¿SC-001 ("0 envíos automáticos") tiene una fuente de verificación objetiva definida? [Measurability, Spec §SC-001]

## Scenario Coverage

- [X] CHK014 - ¿Están cubiertos los escenarios de Venta sin cobros y con cobros ya registrados para la disponibilidad de la acción? [Coverage, Spec §US1 Acceptance Scenarios]
- [X] CHK015 - ¿Está cubierto el caso de doble ejecución accidental de la acción (doble click) sobre la misma fila? [Coverage, Edge Case, Spec §Edge Cases]
- [X] CHK016 - ¿Está cubierto el caso de certificado/Punto de Venta no configurado al intentar el envío? [Coverage, Edge Case, Spec §Edge Cases]
- [X] CHK017 - ¿Está explícitamente fuera de alcance el comportamiento de NC/ND y la inmutabilidad post-CAE, para evitar que la implementación se expanda sin control? [Coverage, Scope Boundary, Spec §Assumptions/FR-010]

## Dependencies & Assumptions

- [X] CHK018 - ¿Está documentada la dependencia del servicio `EmisorComprobante::emitir()` ya existente, sin requerir cambios en su lógica interna? [Dependency, Spec §Assumptions]
- [X] CHK019 - ¿Está documentado que la reactivación de la Función Avanzada desactivada es responsabilidad del usuario, fuera de alcance de esta spec? [Assumption, Spec §Assumptions]
- [X] CHK020 - ¿Está registrado el incidente real que originó esta corrección, con fecha y ambiente afectado, para trazabilidad futura? [Traceability, Spec §Contexto del incidente]

## Requirement Clarity (ronda 2 — feedback de envío)

- [X] CHK021 - ¿Está clarificada la diferencia entre un rechazo de precondición (toast) y un resultado real de ARCA (modal)? [Clarity, Spec §FR-007/FR-007a]
- [X] CHK022 - ¿Está especificado qué señal usa el cliente (código HTTP) para distinguir ambos casos, sin ambigüedad de implementación? [Clarity, contracts/enviar-arca.md]

## Requirement Consistency (ronda 2)

- [X] CHK023 - ¿Es consistente el nuevo FR-012 (certificado no configurado = error visible) con el comportamiento ya existente de `emitirComprobanteFiscal()` (que lo silencia para el flujo automático)? [Consistency, Spec §FR-012, research.md §7]

## Notes

- Todos los ítems pasan en la primera iteración — la spec ya incorpora el incidente real, las
  clarifications resueltas, y el alcance explícitamente acotado (NC/ND e inmutabilidad post-CAE fuera
  de alcance).
- Ronda 2 (04/08/2026): se incorporó el clarify de "modal vs. toast" para el resultado del envío
  (FR-007/FR-007a) y se detectó y resolvió una inconsistencia real con el comportamiento silencioso
  existente de `CertificadoNoConfiguradoException` (FR-012, research.md §7) — no estaba contemplada en
  la primera iteración porque no era evidente hasta revisar el código de `emitirComprobanteFiscal()`.
