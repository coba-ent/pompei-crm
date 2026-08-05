# Checklist: Calidad de requisitos — corrección fiscal y consistencia con spec 037

**Purpose**: Validar que los requisitos de esta feature sean completos, no ambiguos y consistentes con la
spec 037 antes de pasar a tareas — foco en el impacto fiscal (determina Factura A/B) y en el hallazgo
técnico que motiva la corrección.
**Created**: 2026-08-05
**Feature**: [spec.md](../spec.md)

## Requirement Completeness

- [x] CHK001 - ¿Está especificado qué pasa cuando una de las dos consultas (A13, constancia) tiene éxito y la otra falla, para cada dato afectado? [Completeness, Spec §FR-005, Edge Cases]
- [x] CHK002 - ¿Está definido el comportamiento cuando el certificado no tiene adherido `ws_sr_constancia_inscripcion` (caso análogo al ya resuelto para A13 en spec 037)? [Completeness, Spec Clarifications]
- [x] CHK003 - ¿Está especificado qué pasa si el valor de condición de IVA derivado no matchea ningún registro del catálogo `condiciones_iva`? [Completeness, Spec §FR-003]

## Requirement Clarity

- [x] CHK004 - ¿Está cuantificado el límite de tiempo ("best effort") para la nueva consulta en vez de dejarlo como término vago? [Clarity, Spec §FR-006]
- [x] CHK005 - ¿Es "condición de IVA confirmada" un término trazable a una regla de derivación concreta, no sólo un campo de texto libre de ARCA? [Clarity, Spec §FR-002]

## Requirement Consistency

- [x] CHK006 - ¿Las reglas de precedencia (cliente existente con condición ya cargada vs. cliente nuevo) siguen siendo exactamente las mismas que en spec 037, sin redefinirlas en esta spec? [Consistency, Spec §FR-004, Assumptions]
- [x] CHK007 - ¿El criterio de "no pisar ediciones manuales del usuario" se aplica de forma consistente al nuevo campo igual que a razón social/domicilio? [Consistency, Spec §FR-002]

## Acceptance Criteria Quality

- [x] CHK008 - ¿Los criterios de éxito (SC-001/SC-002/SC-003) son medibles y no dependen de una implementación específica? [Measurability, Spec Success Criteria]
- [x] CHK009 - ¿Los escenarios Given/When/Then de ambas historias de usuario cubren tanto el caso exitoso como el de degradación parcial? [Acceptance Criteria, Spec User Stories]

## Scenario Coverage

- [x] CHK010 - ¿Están cubiertos los escenarios donde sólo una de las dos consultas encuentra el CUIT (asimetría entre A13 y constancia)? [Coverage, Spec Edge Cases]
- [x] CHK011 - ¿Está cubierto el escenario de conversión en lote donde la nueva consulta falla para una orden puntual sin afectar al resto? [Coverage, Spec §FR-004 vía spec 037 FR-009]

## Edge Case Coverage

- [x] CHK012 - ¿Está definido el comportamiento cuando un contribuyente no tiene ni `datosRegimenGeneral` ni `datosMonotributo` (sin inscripciones relevadas)? [Edge Case, Spec Edge Cases]
- [x] CHK013 - ¿Está definido cómo se distingue "Responsable Inscripto" de "Exento"/"dado de baja de IVA" cuando ambos comparten la presencia de un ítem de impuesto IVA? [Edge Case, Gap — ver research.md R4]

## Non-Functional Requirements

- [x] CHK014 - ¿Está especificado que la nueva consulta no debe agregar bloqueo perceptible al guardado de cliente ni a la conversión de órdenes? [Non-Functional, Spec §FR-006]

## Dependencies & Assumptions

- [x] CHK015 - ¿Está documentada la dependencia de que el certificado tenga adherido el nuevo servicio en ARCA, incluyendo dónde verificarlo? [Dependency, Spec Assumptions]
- [x] CHK016 - ¿Está validada (no sólo asumida) la estructura real de la respuesta del servicio nuevo antes de definir la regla de mapeo? [Assumption, Spec Assumptions — confirmado contra ARCA real, ver research.md R3]

## Ambiguities & Conflicts

- [x] CHK017 - ¿Se identificó y corrigió explícitamente la ambigüedad heredada de la spec 037 (asumía que A13 traía condición de IVA)? [Ambiguity, Spec Input / research.md R1]
- [x] CHK018 - ¿Hay algún requisito de esta spec que contradiga una regla ya vigente de la spec 037 sin señalarlo? [Conflict] — Ninguno detectado: FR-004 reafirma explícitamente que las reglas de precedencia de spec 037 no cambian.

## Notes

- Todos los ítems cerraron en verde en la primera pasada porque el hallazgo técnico (estructura real de
  ambos servicios SOAP) se verificó contra ARCA producción **antes** de escribir la spec, no después —
  evitó los mismos supuestos no verificados que causaron el bug original de la spec 037.
- CHK013 queda resuelto a nivel de spec (ver Edge Cases del spec.md) apoyándose en la regla de derivación
  documentada en `research.md` R4; si en implementación aparece un caso real no contemplado por esa regla,
  amerita revisar research.md antes de improvisar en el código.
