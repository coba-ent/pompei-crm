# Specification Quality Checklist: Importador de Datos — Actualizar por Id (Upsert)

**Purpose**: Validar completitud, claridad y consistencia de los requirements antes de pasar a `/speckit-tasks`
**Created**: 2026-07-31
**Feature**: [spec.md](../spec.md)
**Focus**: integridad de datos en la actualización parcial + casos borde de resolución de Id (foco elegido por
relevancia de dominio: es una feature que escribe sobre registros existentes, el mayor riesgo es pisar datos que
el usuario no quiso tocar)
**Depth**: Standard — checklist de release gate antes de `/speckit-tasks`, no un sanity check liviano
**Audience**: Reviewer (quien apruebe `/speckit-analyze` antes de implementar)

## Requirement Completeness

- [x] CHK001 - ¿Está definido qué pasa con cada campo del registro que NO está mapeado en una fila de
  actualización? [Completeness, Spec §FR-003]
- [x] CHK002 - ¿Está definido el comportamiento para los 4 casos de la columna Id (ausente/vacía, no numérica,
  numérica sin match, numérica con match)? [Completeness, Spec §FR-002, FR-004, FR-005, FR-008]
- [x] CHK003 - ¿Está definido si las reglas de "campo obligatorio" aplican distinto en alta vs actualización?
  [Completeness, Spec §FR-006]
- [x] CHK004 - ¿Está definido el comportamiento de las reglas de unicidad (ej. CUIT) cuando la fila de
  actualización reenvía el mismo valor que ya tiene el registro? [Completeness, Spec §FR-011]
- [x] CHK005 - ¿Está definido qué pasa si dos filas del mismo archivo mapean el mismo Id (ej. corrección
  duplicada del mismo registro dos veces en la misma corrida)? [Gap, Spec §Edge Cases]
- [x] CHK006 - ¿Está definido si el resumen de la importación debe distinguir "actualizados" de "creados" en
  algún conteo, o sólo un total combinado? [Spec §FR-010 — resuelto como "no distingue", intencional]

## Requirement Clarity

- [x] CHK007 - ¿Es inequívoco qué significa "coincide con el id de un registro existente" (id exacto vs búsqueda
  parcial/aproximada)? [Clarity, Spec §FR-002]
- [x] CHK008 - ¿Está cuantificado qué hace que un valor de celda sea "no numérico" para el campo Id (ej. `"5.0"`,
  `" 5 "` con espacios, notación científica)? [Clarity, Spec §FR-008]

## Requirement Consistency

- [x] CHK009 - ¿Es consistente el criterio de "actualización parcial" (FR-003) con el mecanismo de resolución de
  campos FK-por-nombre ya vigente (spec 006/026), que también deja el campo intacto si no está mapeado?
  [Consistency, Spec §FR-009]
- [x] CHK010 - ¿Es consistente la relajación de "obligatorio" en actualización (FR-006) con que ese mismo campo
  sigue siendo obligatorio en alta dentro del mismo archivo/corrida? [Consistency, Spec §FR-006]

## Scenario Coverage

- [x] CHK011 - ¿Hay un escenario de aceptación que cubra la actualización exitosa con conservación de campos no
  mapeados, para cada una de las 3 entidades? [Coverage, Spec §User Story 1, User Story 2]
- [x] CHK012 - ¿Hay un escenario de aceptación para el caso "Id no encontrado → fila fallida"? [Coverage, Spec
  §User Story 1 Acceptance Scenario 3]
- [x] CHK013 - ¿Hay un escenario de aceptación explícito para "Id numérico pero valor no entero" (ej. `"5.5"`)
  distinto del caso "no numérico" genérico? [Gap, Spec §Edge Cases — tratado igual que "no numérico"]

## Edge Case Coverage

- [x] CHK014 - ¿Están definidos los campos FK-por-nombre en una fila de actualización cuando no están mapeados?
  [Edge Case, Spec §Edge Cases]
- [x] CHK015 - ¿Está definido el comportamiento sobre registros inactivos (Activo=No) al actualizarlos por Id?
  [Edge Case, Spec §Edge Cases]
- [x] CHK016 - ¿Está definido qué pasa si el Id mapeado corresponde a un registro de una entidad distinta a la
  solapa activa (ej. un id de Proveedor pegado por error en la solapa Clientes)? [Gap, Spec §Assumptions —
  riesgo aceptado, documentado explícitamente]

## Dependencies & Assumptions

- [x] CHK017 - ¿Está documentado de dónde obtiene el usuario los ids reales del sistema para armar el archivo de
  actualización? [Assumption, Spec §Assumptions]
- [x] CHK018 - ¿Está documentado que esta feature no agrega detección de duplicados por otros criterios (email,
  CUIT, nombre)? [Assumption, Spec §Assumptions]

## Notes

- Los 4 gaps detectados en la primera pasada (CHK005, CHK006, CHK013, CHK016) se resolvieron editando `spec.md`
  (Edge Cases + Assumptions) antes de pasar a `/speckit-tasks` — checklist 18/18 en verde.
