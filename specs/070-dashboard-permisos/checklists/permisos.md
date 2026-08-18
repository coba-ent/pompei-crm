# Checklist: Calidad de requisitos — Dashboard filtrado por permisos

**Purpose**: Validar que los requisitos de la spec (no la implementación) sean completos, claros,
consistentes y verificables antes de pasar a tasks/implement — con foco en permisos/seguridad
(fuga de datos) y consistencia entre widgets, que es el riesgo central de esta feature.
**Created**: 2026-08-18
**Feature**: [spec.md](../spec.md)

## Requirement Completeness

- [x] CHK001 - ¿Está especificado, para cada uno de los 5 widgets/grupos de datos del Dashboard (KPIs, Totales, gráfico mensual, donas, rankings, tesorería), qué permiso(s) exacto(s) lo habilitan? [Completeness, Spec §Requirements FR-002–FR-008]
- [x] CHK002 - ¿Está especificado el comportamiento del KPI "Resultado", que combina 4 rubros, cuando el usuario no tiene los 4 permisos simultáneamente? [Completeness, Spec §FR-003]
- [x] CHK003 - ¿Está especificado si el filtrado aplica también a la carga inicial de la vista (`index`) y no sólo a los endpoints AJAX? [Completeness, Spec §FR-002, FR-009]
- [x] CHK004 - ¿Está especificado qué pasa si un usuario no tiene ninguno de los 7 permisos relevantes (se bloquea el acceso a `/dashboard` o se permite con pantalla vacía)? [Completeness, Spec §FR-012]

## Requirement Clarity

- [x] CHK005 - ¿Está cuantificado sin ambigüedad qué significa "ocultar completamente" un widget (vs. mostrarlo vacío o bloqueado)? [Clarity, Spec §FR-010]
- [x] CHK006 - ¿Es inequívoco, para el Ranking de Clientes y el de Productos, qué combinación exacta de permisos habilita cada uno (no sólo "algún permiso de ventas")? [Clarity, Spec §FR-006, FR-007]
- [x] CHK007 - ¿Está definido sin ambigüedad qué significa "omitir del JSON" (ausencia de la clave) frente a alternativas como enviar `null` o `0`? [Clarity, Spec §FR-009]

## Requirement Consistency

- [x] CHK008 - ¿El criterio de ocultamiento (oculto completo, sin estado vacío/bloqueado) es el mismo para los 5 widgets, sin excepciones no justificadas? [Consistency, Spec §FR-010]
- [x] CHK009 - ¿Es consistente el requisito de que Admin vea todo (FR-011) con el resto de los requisitos de filtrado, sin dejar un caso donde Admin quede afectado por error? [Consistency, Spec §FR-011]
- [x] CHK010 - ¿Son consistentes entre sí los requisitos de "acceso a la ruta `/dashboard` siempre permitido" (FR-001, FR-012) y "cada widget requiere su permiso", de forma que no haya contradicción sobre cuándo se autoriza qué? [Consistency, Spec §FR-001, FR-012]

## Acceptance Criteria Quality / Measurability

- [x] CHK011 - ¿El criterio de éxito sobre fuga de datos (SC-001) es verificable de forma objetiva inspeccionando la respuesta HTTP, sin depender de interpretación subjetiva? [Measurability, Spec §SC-001]
- [x] CHK012 - ¿Los criterios de éxito distinguen claramente "dato no viaja en la respuesta" de "dato viaja pero no se muestra en pantalla", dado que son dos riesgos distintos (US1 vs US2)? [Measurability, Spec §SC-001, SC-002]

## Scenario Coverage

- [x] CHK013 - ¿Hay un escenario de aceptación que cubra un usuario con permiso de rubro pero sin el permiso complementario necesario para un widget compuesto (ej. `ventas.ver` sin `clientes.ver` para el Ranking de Clientes)? [Coverage, Spec §Edge Cases]
- [x] CHK014 - ¿Hay un escenario que cubra el caso de un usuario con únicamente `tesoreria.ver` (sin ningún permiso de rubro de KPIs/Totales)? [Coverage, Spec §Edge Cases]
- [x] CHK015 - ¿Hay un escenario que valide explícitamente el comportamiento cuando se llama un endpoint AJAX directamente (no sólo la carga de la vista), sin pasar por la UI? [Coverage, Spec §User Story 2]

## Edge Case Coverage

- [x] CHK016 - ¿Está definido el comportamiento cuando el permiso de un usuario cambia mientras tiene el Dashboard abierto en el navegador (actualización en caliente vs. próximo refresh)? [Edge Case, Spec §Edge Cases]
- [x] CHK017 - ¿Está cubierto el caso de un usuario con `ventas.ver` pero sin `clientes.ver` ni `productos.ver` simultáneamente (ambos rankings ausentes) para evitar que quede ambiguo cuál ranking depende de cuál permiso? [Edge Case, Spec §Edge Cases]

## Non-Functional Requirements

- [x] CHK018 - ¿Especifica la spec que el filtrado no debe degradar el tiempo de respuesta de los endpoints (más allá de la mejora esperable por saltear cálculos)? [Gap] — Nota: no está explícito como requisito propio; se apoya en el criterio general SC-004, considerado suficiente para el alcance de esta feature.

## Dependencies & Assumptions

- [x] CHK019 - ¿Está documentada la dependencia del catálogo de permisos ya existente (`PermisoSeeder`) y el supuesto de que no se necesitan permisos nuevos? [Traceability, Spec §Assumptions]
- [x] CHK020 - ¿Está documentado el supuesto de que `User::tienePermiso()` es la única fuente de verdad reutilizada, sin introducir un mecanismo de permisos paralelo? [Traceability, Spec §Assumptions]

## Notes

- Todos los items pasan tras revisar la spec (versión post-clarify). No quedan `[Gap]` bloqueantes:
  CHK018 es una observación menor, cubierta indirectamente por SC-004 y por Decisión 1 en
  `research.md` (el filtrado evita ejecutar queries de rubros sin permiso, por lo que si algo
  cambia es una mejora, no una degradación).
