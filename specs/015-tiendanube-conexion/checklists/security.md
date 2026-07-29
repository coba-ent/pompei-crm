# Specification Quality Checklist: Seguridad de credenciales, estados de conexión y consistencia con Mercado Libre

**Purpose**: Validar la calidad de los requisitos (no la implementación) de la conexión con
Tiendanube, con foco en seguridad de credenciales, manejo de errores/estados de conexión, y
consistencia con el patrón ya establecido por Mercado Libre (spec 011).
**Created**: 2026-07-29
**Feature**: [spec.md](../spec.md) | [plan.md](../plan.md)
**Depth**: Standard | **Audience**: Autor/revisor antes de `/speckit-tasks` | **Foco**: seguridad de credenciales, estados de conexión, consistencia con spec 011

## Requirement Completeness

- [ ] CHK001 - ¿Está especificado qué ocurre si el usuario pega un `store_id` con espacios o formato no numérico, más allá del caso general de "campo obligatorio"? [Completeness, Spec §Edge Cases]
- [ ] CHK002 - ¿Están definidos los requisitos para el caso en que la aplicación personalizada se elimina o revoca desde el propio panel de Tiendanube mientras el CRM la sigue mostrando como "Conectada"? [Completeness, Spec §Edge Cases]
- [ ] CHK003 - ¿Especifica la spec qué pasa si dos usuarios del CRM guardan credenciales de Tiendanube casi simultáneamente (sin bloqueo de concurrencia, a diferencia de la renovación de Mercado Libre)? [Gap]
- [ ] CHK004 - ¿Están documentados los requisitos de qué texto de ayuda debe mostrar la pantalla para guiar al usuario a generar el token en el panel de Tiendanube, dado que research.md marca ese camino como "a verificar"? [Completeness, Spec §Contexto y fuentes]

## Requirement Clarity

- [ ] CHK005 - ¿Está cuantificado qué significa "token de larga duración" more allá de "no vence"? ¿La spec aclara explícitamente que no hay ningún valor de expiración a monitorear, para que no se confunda con un descuido? [Clarity, Spec §Assumptions]
- [ ] CHK006 - ¿Es inequívoco, en FR-012, qué distingue una "credencial inválida o revocada" (→ Caída) de una "falla temporal" (→ reintento sin marcar caída), en términos de qué códigos de respuesta caen en cada bucket? [Clarity, Spec §FR-012..014]
- [ ] CHK007 - ¿Define la spec con precisión qué significa "no corresponde al token cargado" (edge case de tienda incorrecta) en términos verificables, o queda a criterio de la implementación qué campo de la respuesta usar? [Clarity, Spec §Edge Cases]
- [ ] CHK008 - ¿Especifica FR-009 el formato exacto (zona horaria, granularidad) de las fechas mostradas (guardado de credenciales, última verificación), o queda ambiguo? [Clarity, Spec §FR-009]

## Requirement Consistency

- [ ] CHK009 - ¿Es consistente el alcance del "modo sólo lectura" de Tiendanube (FR-016..019) con el ya definido para Mercado Libre (spec 011, FR-035..038), en cuanto a qué se considera lectura vs. escritura? [Consistency, Spec §FR-016..019]
- [ ] CHK010 - ¿Usa esta spec la misma terminología de estados de conexión que la 011 (No configurada / Desconectada / Conectada / Caída) sin introducir un quinto estado no justificado (dado que no existe el "Pendiente de confirmación" de Mercado Libre)? [Consistency, Spec §FR-008]
- [ ] CHK011 - ¿Es consistente el tratamiento de "conservar datos tras desconexión" (FR-011) con el ya establecido para Mercado Libre (spec 011, FR-027)? [Consistency, Spec §FR-011]
- [ ] CHK012 - ¿Reutiliza esta spec de forma consistente el mismo permiso (`configuracion.funciones`) y el mismo criterio de retención del historial que Mercado Libre, o hay una divergencia no justificada? [Consistency, Spec §Assumptions]

## Acceptance Criteria Quality

- [ ] CHK013 - ¿Es SC-001 ("menos de 2 minutos") verificable de forma objetiva, dado que depende de un paso externo (generar el token en el panel de Tiendanube) fuera del control del CRM? [Measurability, Spec §SC-001]
- [ ] CHK014 - ¿Es SC-005 ("ningún token recuperable... verificable por inspección") lo suficientemente específico sobre qué superficies inspeccionar (interfaz, historial, logs de aplicación, respuestas JSON) para ser objetivamente comprobable? [Measurability, Spec §SC-005]
- [ ] CHK015 - ¿Definen los criterios de éxito un umbral objetivo para "detectar" una credencial caída (por ejemplo, en el primer intento fallido, o tras N intentos), o queda implícito? [Clarity, Spec §User Story 4]

## Scenario Coverage

- [ ] CHK016 - ¿Cubre la spec el escenario de recuperación completo (token caído → usuario recarga token → conexión vuelve a Conectada) con los mismos pasos y datos preservados que exige el User Story 4? [Coverage, Spec §User Story 4]
- [ ] CHK017 - ¿Están cubiertos los escenarios de "excepción" (rate limit, falla temporal) con la misma profundidad que los de éxito, incluyendo qué le comunica el sistema al usuario en cada uno? [Coverage, Spec §Edge Cases]
- [ ] CHK018 - ¿Contempla la spec el escenario alterno de que el usuario desactive la función "Tiendanube" teniendo una conexión "Caída" (no sólo "Conectada"), y qué debería mostrar la confirmación en ese caso? [Coverage, Gap]

## Non-Functional Requirements

- [ ] CHK019 - ¿Especifica la spec un requisito no funcional sobre el tiempo máximo aceptable de espera en "Probar conexión" antes de considerarlo una falla, o depende enteramente de los timeouts que decida el plan? [Gap, Spec §FR-007]
- [ ] CHK020 - ¿Documenta la spec un requisito de auditoría equivalente (quién cargó/reemplazó el token y cuándo) al ya exigido implícitamente por `actualizada_por` en Mercado Libre? [Completeness, Gap]

## Dependencies & Assumptions

- [ ] CHK021 - ¿Está explícitamente validada (o marcada como pendiente de verificación) la suposición de que el token de una Aplicación personalizada de Tiendanube efectivamente no vence, antes de comprometerse a no construir ningún mecanismo de renovación? [Assumption, Spec §Assumptions]
- [ ] CHK022 - ¿Documenta la spec la dependencia de que el negocio deba crear la Aplicación personalizada por su cuenta antes de poder usar esta pantalla, con el mismo nivel de detalle que la dependencia externa de Mercado Libre (app del DevCenter)? [Dependency, Spec §Dependencies]

## Ambiguities & Conflicts

- [ ] CHK023 - ¿Hay algún punto donde la spec 015 y la sección §5.2 de `documentacion_principal_crm.md` (que hoy sólo documenta Mercado Libre) puedan quedar en conflicto una vez que ambas integraciones convivan en el mismo documento? [Conflict, Gap]
- [ ] CHK024 - ¿Es ambiguo si "Probar conexión" (FR-007) debe ejecutarse automáticamente tras guardar credenciales por primera vez, o si siempre requiere una acción explícita separada del usuario? [Ambiguity, Spec §User Story 1/2]
