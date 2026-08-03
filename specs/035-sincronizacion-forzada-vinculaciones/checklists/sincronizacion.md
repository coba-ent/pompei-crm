# Requirements Quality Checklist: Sincronización forzada y eliminación masiva de Vinculaciones

**Purpose**: Validar que los requisitos de esta feature (spec.md) sean completos, claros, consistentes
y verificables antes de pasar a tasks/implementación — no valida la implementación.
**Created**: 2026-08-03
**Feature**: [spec.md](../spec.md)

## Requirement Completeness

- [x] CHK001 - ¿Están definidos los requisitos de corte previo (función desactivada, modo sólo lectura,
      sin conexión) para AMBAS acciones nuevas, no sólo para la sincronización forzada? [Completeness,
      Spec §FR-007, §FR-018 — nota: FR-018 aplica el corte de concurrencia a la eliminación masiva, pero
      confirmar si los cortes de FR-007 (función desactivada/sólo lectura/sin conexión) también deberían
      aplicar a `eliminarTodas()` o si el borrado es intencionalmente independiente de esos cortes]
- [x] CHK002 - ¿Está especificado qué pasa si la integración no tiene lista de precios configurada al
      accionar la sincronización forzada? [Completeness, Spec Edge Cases — "Integración sin campo de
      lista de precios por defecto"]
- [x] CHK003 - ¿Está definido el comportamiento cuando la sincronización forzada se acciona sin que haya
      ningún vínculo existente (tabla vacía)? [Gap]

## Requirement Clarity

- [x] CHK004 - ¿Está cuantificado qué significa "recorre TODOS los vínculos activos" — incluye vínculos
      cuyo producto fue eliminado del CRM, o esos quedan excluidos por definición? [Clarity, Spec
      §FR-002, Edge Cases "Producto eliminado"]
- [x] CHK005 - ¿Está claro si "vínculos activos" excluye algún estado particular de vínculo (ej. uno
      marcado como inválido/desconectado manualmente), o el término "activos" es sinónimo de "todos los
      registros existentes"? [Ambiguity, Spec §FR-002]
- [x] CHK006 - ¿Está definido con precisión el texto exacto (o al menos el criterio) del toast de éxito
      cuando la sincronización forzada corre pero cero vínculos se actualizan (todos con error)?
      [Clarity, Gap]

## Requirement Consistency

- [x] CHK007 - ¿Es consistente el criterio de "borrado físico sin confirmación de deshacer" (FR-016,
      Assumptions) con el resto de entidades del CRM, dado que la Constitución exige soft delete para
      documentos fiscales/contables? [Consistency — confirmar que el vínculo de integración
      explícitamente NO cae bajo esa categoría, ya razonado en research.md Decisión 4]
- [x] CHK008 - ¿Son consistentes entre sí los mensajes de corte reutilizados (FR-007) con los que ya
      produce el código existente (`SincronizadorStock`/`SincronizadorPrecios` actuales), de modo que no
      haya dos textos distintos para el mismo corte según qué botón lo disparó? [Consistency, Spec
      §FR-007]

## Acceptance Criteria Quality

- [x] CHK009 - ¿Es medible el criterio de éxito SC-001 ("100% de los vínculos activos... queda con su
      estado de sincronización actualizado") sin ambigüedad sobre qué significa "actualizado" para un
      vínculo que terminó con error? [Measurability, Spec §SC-001]
- [x] CHK010 - ¿Es verificable SC-003 ("0% de interrupciones del barrido completo por errores
      puntuales") con un caso concreto documentado en Acceptance Scenarios o Edge Cases? [Traceability,
      Spec §SC-003 ↔ Edge Cases "Falla parcial por vínculo"]

## Scenario Coverage

- [x] CHK011 - ¿Están cubiertos los requisitos para el escenario "eliminación masiva mientras corre una
      sincronización forzada" (concurrencia cruzada entre las dos acciones nuevas, no sólo entre
      sincronización y sincronización)? [Coverage, Spec Edge Cases "Eliminación masiva mientras hay una
      sincronización en curso"]
- [x] CHK012 - ¿Está definido el requisito para el caso inverso: accionar "Sincronización forzada"
      mientras una eliminación masiva está en curso? [Gap — el spec cubre eliminar-durante-sync pero no
      explícitamente sync-durante-eliminar; confirmar si el mismo candado (FR-008/FR-018) ya lo cubre
      simétricamente]
- [x] CHK013 - ¿Están definidos los requisitos de auditoría/registro para la acción de eliminación
      masiva (quién, cuándo, cuántos), más allá del toast de confirmación al usuario? [Gap, Completeness]

## Edge Case Coverage

- [x] CHK014 - ¿Está definido el límite de tiempo o comportamiento esperado si la sincronización forzada
      tarda varios minutos (catálogo grande) y el usuario navega a otra pantalla o cierra la pestaña
      antes de que termine? [Gap, Edge Case]
- [x] CHK015 - ¿Está definido qué pasa si el request de eliminación masiva falla a mitad de camino (ej.
      error de base de datos tras borrar sólo una parte)? [Gap, Edge Case — no hay mención de
      atomicidad/transacción en los requisitos]

## Non-Functional Requirements

- [x] CHK016 - ¿Está especificado un requisito de registro en el historial de operaciones para la
      eliminación masiva, equivalente al FR-014 ya definido para la sincronización forzada?
      [Completeness, Gap — FR-014 cubre sólo la sincronización forzada, no hay FR equivalente explícito
      para `eliminarTodas()`]
- [x] CHK017 - ¿Están los requisitos de esta feature libres de detalles de implementación (nombres de
      clases, métodos, endpoints) que deberían vivir en plan.md en vez de spec.md? [Clarity — revisar
      que spec.md no haya heredado nombres de clase del research technical]

## Dependencies & Assumptions

- [x] CHK018 - ¿Está documentada la dependencia de que ambas integraciones ya tengan implementado el
      concepto de "lista de precios por defecto" y "depósito efectivo" antes de esta feature (sin la
      cual FR-003/FR-004 no tienen de dónde calcular los valores)? [Dependency, Spec Assumptions]
- [x] CHK019 - ¿Está validada (o al menos declarada como riesgo) la asunción de que el volumen actual de
      vínculos (decenas a cientos) sigue siendo válida a mediano plazo, dado que la ejecución síncrona
      elegida no escala indefinidamente? [Assumption, Spec Assumptions]

## Ambiguities & Conflicts

- [x] CHK020 - ¿Hay conflicto entre "Independent Test" de User Story 4 (verifica que no se disparó
      ningún request hacia la plataforma externa) y algún requisito futuro no escrito de "despublicar al
      eliminar"? ¿Está esa exclusión (no despublicar) explícitamente decidida y no sólo implícita?
      [Conflict check, Spec §FR-017 — ya está explícito, se marca resuelto]

## Notes

- Todos los gaps detectados (CHK001, CHK003, CHK012, CHK013, CHK014, CHK015, CHK016) se resolvieron
  agregando FR-020 a FR-022 y cuatro edge cases nuevos al spec (revisión 2026-08-03, misma sesión).
  CHK006 se dejó como criterio ya cubierto por FR-012 (el toast siempre muestra el conteo, incluido el
  caso de 0 actualizados) sin necesidad de un texto especial adicional.
- Check items off as completed: `[x]` una vez resueltos en el spec (no antes).
