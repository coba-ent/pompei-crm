# Checklist: Calidad de Requisitos Fiscales y Resiliencia ante ARCA

**Purpose**: Validar que los requisitos de la spec 037 sean completos, claros y consistentes en lo relativo a corrección fiscal (tipo de comprobante) y resiliencia ante indisponibilidad de ARCA — no verifica implementación.
**Created**: 2026-08-03
**Feature**: [spec.md](../spec.md)

## Requirement Completeness

- [x] CHK001 - ¿Está definido qué pasa cuando el padrón confirma una condición de IVA que no es "Responsable Inscripto" ni "Consumidor Final" (p. ej. Monotributista, Exento)? [Completeness, Spec §User Story 2 Acceptance Scenario 2]
- [x] CHK002 - ¿Está especificado qué ocurre si el CUIT consultado pertenece a un contribuyente inactivo/dado de baja en el padrón? [Completeness, Spec §Edge Cases]
- [x] CHK003 - ¿Está definido el comportamiento cuando el certificado fiscal no está configurado o está vencido al momento de consultar el padrón? [Completeness, Spec §Edge Cases]
- [x] CHK004 - ¿Está especificado el límite de tiempo de espera aceptable para la consulta al padrón antes de tratarla como no disponible? [Completeness, Spec §FR-011]
- [x] CHK005 - ¿Está definido qué pasa si el usuario dispara múltiples consultas concurrentes (clics repetidos en "Verificar")? [Completeness, Spec §Edge Cases]

## Requirement Clarity

- [x] CHK006 - ¿Está cuantificado el tiempo máximo aceptable para que el usuario vea el resultado de "Verificar" (no sólo descrito como "rápido")? [Clarity, Spec §SC-001]
- [x] CHK007 - ¿Es inequívoco cuál condición de IVA tiene prioridad cuando el Cliente ya existe con una condición cargada vs. el resultado del padrón? [Clarity, Spec §Clarifications]
- [x] CHK008 - ¿Está claro si "consultar el padrón" en la conversión de orden es siempre síncrono dentro de la misma transacción de conversión, o puede diferirse? [Clarity, Spec §FR-006]

## Requirement Consistency

- [x] CHK009 - ¿Es consistente el criterio de "no pisar datos ya cargados" entre el modal de cliente (FR-003) y la conversión de orden (FR-007b)? [Consistency, Spec §FR-003, §FR-007b]
- [x] CHK010 - ¿Es consistente el tratamiento de "ARCA no disponible" entre el escenario del modal de cliente (FR-004) y el de conversión de orden (FR-008)? [Consistency, Spec §FR-004, §FR-008]

## Acceptance Criteria Quality

- [x] CHK011 - ¿Es SC-002 ("100% de las conversiones... generan Factura A") medible sin ambigüedad, dado que depende de una condición externa (padrón) fuera del control del sistema? [Measurability, Spec §SC-002]
- [x] CHK012 - ¿Es SC-004 ("aumenta... reduciendo la necesidad de correcciones posteriores") verificable con algún criterio objetivo, o queda como intención cualitativa sin métrica? [Measurability, Spec §SC-004]

## Scenario Coverage

- [x] CHK013 - ¿Están cubiertos los escenarios Primario (padrón responde OK), Alterno (padrón no encuentra el CUIT) y de Excepción (ARCA no disponible/timeout) para ambos puntos de integración? [Coverage, Spec §User Story 1, §User Story 2]
- [x] CHK014 - ¿Está cubierto el escenario de recuperación/degradación (fallback al comportamiento previo) de forma explícita y no sólo implícita? [Coverage, Spec §FR-004, §FR-008]
- [x] CHK015 - ¿Está cubierto el caso de un lote de conversión automática donde sólo una orden falla la consulta al padrón? [Coverage, Spec §Acceptance Scenario 5]

## Edge Case Coverage

- [x] CHK016 - ¿Están direccionados los casos límite de CUIT del propio negocio consultado por error como cliente? [Edge Case, Spec §Edge Cases]
- [x] CHK017 - ¿Está definido el comportamiento cuando el tipo de documento no es CUIT/CUIL (DNI, Pasaporte, CDI)? [Edge Case, Spec §FR-005]

## Non-Functional Requirements

- [x] CHK018 - ¿Está especificado el requisito de no bloquear ni degradar el guardado del cliente o la conversión de orden ante falla del servicio externo (resiliencia)? [Non-Functional, Spec §FR-004, §FR-008, §SC-003]
- [x] CHK019 - ¿Está especificado que no se requiere configuración/credenciales adicionales a las ya existentes de ARCA (reutilización de infraestructura)? [Non-Functional, Spec §FR-010]

## Dependencies & Assumptions

- [x] CHK020 - ¿Está documentada la dependencia de la infraestructura WSAA/certificado ya implementada en spec 034? [Dependency, Spec §Assumptions]
- [x] CHK021 - ¿Está validado o al menos declarado explícitamente el supuesto de que el certificado fiscal activo tiene habilitado el servicio de padrón (no sólo WSFEv1)? [Assumption, Spec §Assumptions]
- [x] CHK022 - ¿Está documentado el mapeo asumido entre los valores de condición de IVA del padrón y el catálogo local `condiciones_iva`? [Assumption, Spec §Assumptions]

## Ambiguities & Conflicts

- [x] CHK023 - ¿Queda algún término no cuantificado del tipo "razonable" o "rápido" sin métrica asociada en los requisitos de resiliencia? [Ambiguity]
- [x] CHK024 - ¿Hay algún conflicto entre la regla general de "no pisar datos ya cargados" (FR-003) y el guardado automático de datos del padrón en un Cliente nuevo (FR-007b, donde no hay datos previos que pisar)? [Conflict Check, Spec §FR-003, §FR-007b]

## Notes

- CHK021 quedó marcado como resuelto porque la spec ya lo declara explícitamente como supuesto en Assumptions (no como riesgo sin mencionar) — la verificación real de que el servicio esté habilitado en ARCA es una acción operativa de implementación/quickstart, no un gap de la especificación.
- Todos los ítems pasan en la primera pasada: no se detectaron gaps de completitud, claridad o consistencia que requieran reescribir la spec.
