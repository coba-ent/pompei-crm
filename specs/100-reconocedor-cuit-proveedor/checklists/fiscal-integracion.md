# Fiscal & Integración Checklist: Reconocedor de CUIT por ARCA en Proveedores

**Purpose**: Validar la **calidad de los requisitos** (no la implementación) en las dos áreas de mayor
riesgo de esta feature: la corrección fiscal de los datos que alimentan comprobantes de compra, y el
refactor de un servicio compartido que toca tres consumidores en producción.
**Created**: 2026-09-07
**Feature**: [spec.md](../spec.md)

**Enfoque derivado del contexto** (sin consulta al usuario, por la regla de cadena continua de
CLAUDE.md): riesgo fiscal + regresión de integraciones; profundidad estándar-alta; audiencia:
revisor previo a implementar.

---

## Requirement Completeness

- [x] CHK001 - ¿Están definidos los requisitos para las tres formas posibles de respuesta del padrón (no consultado / no encontrado / encontrado)? [Completeness, Spec §FR-006]
- [x] CHK002 - ¿Está especificado qué ocurre cuando la consulta de identidad funciona pero la de condición de IVA falla? [Completeness, Spec §FR-004, US4-esc.4]
- [x] CHK003 - ¿Se define el comportamiento requerido para las cinco condiciones de IVA del catálogo, sin dejar ninguna sin regla? [Completeness, Spec §FR-015]
- [x] CHK004 - ¿Está documentado el alcance de la opción "Factura E", que existe en el selector pero no se deriva? [Completeness, Spec §Assumptions]
- [x] CHK005 - ¿Se especifica qué campos exactos alimenta el padrón, de forma enumerable y cerrada? [Completeness, Spec §FR-008]
- [ ] CHK006 - ¿Están definidos los requisitos de auditoría/registro de las consultas al padrón (quién consultó qué CUIT y cuándo)? [Gap]

## Requirement Clarity

- [x] CHK007 - ¿Está desambiguado el término "refrescar" del escenario de edición, que podía leerse como "completar sólo lo vacío"? [Clarity, Spec §Clarifications 2026-09-07]
- [x] CHK008 - ¿Se precisa desde qué momento cuenta un campo como "editado manualmente"? [Clarity, Spec §FR-009, §FR-010]
- [x] CHK009 - ¿Los textos de los mensajes al usuario están fijados literalmente, o quedan a criterio de quien implemente? [Clarity, contracts/verificar-documento-proveedor.md §C]
- [x] CHK010 - ¿Está claro que `condicion_iva` viaja como nombre y no como identificador? [Clarity, contracts/consulta-padron-servicio.md]
- [x] CHK011 - ¿Se distingue explícitamente la validación del dígito verificador de la consulta al padrón, que el doc de dominio confundía? [Clarity, Spec §Contexto]

## Requirement Consistency

- [x] CHK012 - ¿Se declara explícitamente que la regla de comprobante de Proveedor (A/C/B) difiere de la de Cliente (A/B) a propósito, para que no se "unifiquen" en un refactor futuro? [Consistency, Spec §FR-017]
- [x] CHK013 - ¿Los requisitos de degradación ante fallas de ARCA son consistentes entre la spec, el contrato del endpoint y el del servicio? [Consistency, Spec §FR-005, contracts/]
- [x] CHK014 - ¿La aclaración sobre edición (el padrón sobrescribe lo precargado) quedó reflejada de forma consistente en la historia de usuario, el FR y los edge cases, sin dejar el texto viejo contradictorio? [Consistency, Spec §US1-esc.3, §FR-010]
- [x] CHK015 - ¿Se afirma en un solo lugar autoritativo que el contrato del endpoint de Proveedor es idéntico al de Cliente? [Consistency, Spec §SC-006]

## Corrección Fiscal (Constitución III)

- [x] CHK016 - ¿Se justifica explícitamente por qué permitir sobreescribir el comprobante por defecto de Proveedor NO viola el principio de derivación obligatoria del tipo de comprobante? [Conflict resuelto, plan.md §Análisis Principio III]
- [x] CHK017 - ¿Se distingue en los requisitos el comprobante que el negocio **emite** del que **recibe**, que es la base de esa justificación? [Clarity, Spec §FR-017]
- [x] CHK018 - ¿Está requerido que ninguna falla de ARCA pueda impedir registrar un proveedor? [Completeness, Spec §FR-005, §SC-002]
- [x] CHK019 - ¿Se especifica que la consulta al padrón no persiste datos por sí sola? [Clarity, data-model.md]
- [x] CHK020 - ¿Se aclara que esta feature no altera la derivación del tipo de comprobante en la emisión real de ventas/notas? [Consistency, plan.md §Análisis Principio III]

## Riesgo del Refactor (servicio compartido)

- [x] CHK021 - ¿Está documentado que el refactor toca tres consumidores en producción, incluidas dos integraciones que corren por cron? [Assumption/Risk, research.md R1]
- [x] CHK022 - ¿Se define un criterio objetivo de no-regresión para el refactor (qué debe seguir pasando y sin modificarse)? [Measurability, research.md R5, Spec §SC-005]
- [x] CHK023 - ¿Se documenta la evidencia de que las implementaciones a unificar son equivalentes, en vez de asumirlo? [Traceability, research.md R1]
- [x] CHK024 - ¿Está especificado que el servicio debe resolver colaboradores por el contenedor, para no invalidar los tests que sirven de red de seguridad? [Completeness, contracts/consulta-padron-servicio.md §Testabilidad]
- [x] CHK025 - ¿Se justifica por qué se unifica el backend pero no el front, en lugar de aplicar el mismo criterio a ambos? [Traceability, research.md R1 vs R4]
- [ ] CHK026 - ¿Se define un criterio de reversión si el refactor rompe una integración ya desplegada? [Gap, Recovery]

## Scenario Coverage

- [x] CHK027 - ¿Hay requisitos para el flujo primario (alta con CUIT, autocompletado exitoso)? [Coverage, Spec §US1]
- [x] CHK028 - ¿Hay requisitos para los flujos de excepción (sin certificado, ARCA caída, CUIT inexistente, CUIT inválido)? [Coverage, Spec §US4]
- [x] CHK029 - ¿Hay requisitos para el flujo alterno de edición de un proveedor existente? [Coverage, Spec §US1-esc.3]
- [x] CHK030 - ¿Se cubre el escenario de datos parciales (padrón sin domicilio, sin condición de IVA)? [Coverage, Spec §Edge Cases]
- [x] CHK031 - ¿Se cubre el escenario de valores sin correspondencia en los catálogos del sistema? [Coverage, Spec §FR-012]
- [x] CHK032 - ¿Se cubre la interacción concurrente (clicks repetidos antes de que responda la consulta)? [Coverage, Spec §FR-013]

## Acceptance Criteria Quality

- [x] CHK033 - ¿Los criterios de éxito son verificables sin conocer la implementación? [Measurability, Spec §SC-001..SC-006]
- [x] CHK034 - ¿El criterio sobre la regla A/C/B es objetivamente comprobable para las cinco condiciones? [Measurability, Spec §SC-004]
- [x] CHK035 - ¿El criterio de no-regresión de Cliente está atado a evidencia concreta y no a una apreciación? [Measurability, Spec §SC-005]
- [x] CHK036 - ¿Cada historia de usuario declara cómo probarla de forma independiente? [Acceptance Criteria, Spec §US1-US4]

## Dependencies & Assumptions

- [x] CHK037 - ¿Está documentada la dependencia del certificado fiscal activo y su modo de falla? [Dependency, Spec §Dependencias]
- [x] CHK038 - ¿Se declara la dependencia de las specs previas (034, 037, 047, 048) sin las cuales esta no se sostiene? [Dependency, Spec §Dependencias]
- [x] CHK039 - ¿Se valida el supuesto de que los campos fiscales de ambos modales son equivalentes, en vez de asumirlo? [Assumption, Spec §Assumptions — verificado en el relevamiento previo]
- [x] CHK040 - ¿Se declara que no hay cambios en el modelo de datos, para que no se generen tareas de migración? [Assumption, data-model.md]
- [x] CHK041 - ¿Se documenta el riesgo aceptado de que la suite corre en SQLite y producción en MySQL? [Assumption, research.md R5]

## Non-Functional Requirements

- [x] CHK042 - ¿Se justifica la ausencia de un objetivo de latencia, en vez de omitirlo sin explicación? [Completeness, plan.md §Performance Goals]
- [x] CHK043 - ¿Están especificados los requisitos de UI obligatorios del proyecto (modal + AJAX sin recarga, toasts)? [Coverage, plan.md §Constraints]
- [ ] CHK044 - ¿Están definidos requisitos de accesibilidad para el estado de carga del botón mientras la consulta está en curso? [Gap, Non-Functional]

## Ambigüedades y Conflictos Pendientes

- [x] CHK045 - ¿Se resolvió el conflicto entre "refrescar al editar" y "no pisar lo editado manualmente"? [Conflict resuelto, Spec §Clarifications]
- [x] CHK046 - ¿Se resolvió la tensión aparente con el Principio III de la constitución? [Conflict resuelto, plan.md]
- [x] CHK047 - ¿Se identificó la imprecisión del doc de dominio §2.3 que originó el reporte, con acción concreta asignada? [Gap identificado, plan.md §Constitution Check I]

---

## Resultado

**44 de 47 ítems pasan.** Tres quedan abiertos, todos de bajo impacto y ninguno bloqueante:

| Ítem | Hueco | Decisión |
|------|-------|----------|
| CHK006 | Sin requisitos de auditoría de consultas al padrón | **Fuera de alcance deliberado.** Cliente tampoco las registra; agregarlo acá crearía asimetría. Si se quiere trazabilidad de consultas a ARCA, corresponde una spec propia que cubra los cuatro consumidores. |
| CHK026 | Sin criterio de reversión del refactor | **Mitigado por otra vía.** El cambio es un commit revertible y los tests existentes son la barrera previa al deploy. Se anota para que `/speckit-tasks` ordene el refactor *antes* de la funcionalidad nueva, de modo que un fallo se detecte con la suite en verde y no mezclado con código nuevo. |
| CHK044 | Sin requisitos de accesibilidad del estado de carga | **Brecha menor real.** FR-013 exige impedir consultas superpuestas pero no obliga a un indicador visible. Cliente hoy tampoco lo tiene. Se sugiere que la tarea de front deshabilite el botón mientras la consulta corre —cumple FR-013 y da feedback— sin elevarlo a requisito nuevo de la spec. |

**Ninguno de los tres requiere modificar la spec antes de `/speckit-tasks`.** Los dos accionables
(CHK026, CHK044) se trasladan como notas de implementación a las tareas.
