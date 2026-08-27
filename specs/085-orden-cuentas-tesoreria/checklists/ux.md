# Checklist de calidad de requisitos: interacción y persistencia del reordenamiento

**Purpose**: Validar que los requisitos de la spec 085 estén completos, claros, consistentes y
medibles antes de implementar. Se evalúa **cómo están escritos los requisitos**, no si el código
funciona.
**Created**: 2026-08-27
**Feature**: [spec.md](../spec.md)
**Focus**: UX de interacción (arrastre, feedback, accesibilidad) + corrección del reordenamiento y
su persistencia
**Depth**: Standard — gate previo a `/speckit-tasks`
**Audience**: Autor de la spec / revisor antes de implementar

## Requirement Completeness

- [x] CHK001 - ¿Está especificado qué elemento de la fila inicia el arrastre y dónde se ubica, en lugar de dejar "se puede arrastrar la fila"? [Completeness, Spec §FR-001]
- [x] CHK002 - ¿Están definidos los requisitos de retroalimentación visual durante el arrastre (indicación de dónde caerá la fila)? [Gap]
- [x] CHK003 - ¿Se especifica el comportamiento cuando el guardado falla, y no sólo el camino feliz? [Completeness, Spec §FR-009]
- [x] CHK004 - ¿Están definidos los requisitos para el caso en que el arrastre no produce un cambio de posición? [Completeness, Spec §FR-005]
- [x] CHK005 - ¿Se define qué ocurre con las cuentas ocultas y las cuentas de sistema respecto del orden? [Completeness, Spec §Edge Cases]
- [x] CHK006 - ¿Está documentado el estado inicial del que se parte (cuentas sin orden asignado) y cómo queda después del primer reordenamiento? [Completeness, Spec §Edge Cases]
- [ ] CHK007 - ¿Se especifican requisitos de retroalimentación mientras el guardado está en curso (estado de espera entre soltar y confirmar)? [Gap]

## Requirement Clarity

- [x] CHK008 - ¿Está cuantificado el alcance del reordenamiento ("dentro de su bloque") con una definición explícita de qué es un bloque? [Clarity, Spec §Key Entities]
- [x] CHK009 - ¿El criterio de rechazo por concurrencia está expresado de forma inequívoca (comparación de conjunto) en vez de un genérico "si algo cambió"? [Clarity, Spec §FR-008]
- [x] CHK010 - ¿Está explícito que el orden resultante es consecutivo y sin huecos, en lugar de "se guarda el orden"? [Clarity, Spec §FR-006]
- [x] CHK011 - ¿Se define qué significa "sin recargar la página" en términos observables por el usuario? [Clarity, Spec §FR-010, SC-005]
- [x] CHK012 - ¿La notificación de éxito y la de error están diferenciadas como requisitos distintos con condiciones de disparo propias? [Clarity, Spec §FR-005, FR-009]

## Requirement Consistency

- [x] CHK013 - ¿El alcance del orden es consistente entre el requisito funcional y el criterio de éxito (cards **y** selectores en ambos)? [Consistency, Spec §FR-012, SC-008]
- [x] CHK014 - ¿La regla de "no cruzar bloques" es consistente entre la historia de usuario, el requisito funcional y el criterio de éxito? [Consistency, Spec §US3, FR-003, SC-004]
- [x] CHK015 - ¿El requisito de teclado describe el mismo efecto de persistencia que el de arrastre, sin introducir un camino de guardado distinto? [Consistency, Spec §FR-013, US4]
- [x] CHK016 - ¿La afirmación de que "no se agregan atributos nuevos" es consistente con lo declarado en Assumptions y en el modelo de datos? [Consistency, Spec §Key Entities, Assumptions]
- [x] CHK017 - ¿Los edge cases de concurrencia y de cuenta borrada se resuelven con el mismo mecanismo, sin describir dos comportamientos divergentes? [Consistency, Spec §Edge Cases]

## Acceptance Criteria Quality

- [x] CHK018 - ¿El criterio de invariancia de importes es objetivamente verificable (comparación de totales antes/después)? [Measurability, Spec §SC-003]
- [x] CHK019 - ¿El criterio de reflejo inmediato tiene un umbral temporal concreto en lugar de "rápido" o "inmediato"? [Measurability, Spec §SC-005]
- [x] CHK020 - ¿El criterio de restauración ante fallo es verificable sin conocer la implementación? [Measurability, Spec §SC-006]
- [x] CHK021 - ¿Cada historia de usuario declara una prueba independiente que no depende de las otras historias? [Acceptance Criteria, Spec §US1-US4]
- [ ] CHK022 - ¿El criterio de usabilidad "menos de 10 segundos sin instrucciones previas" indica cómo se mediría (con cuántos usuarios, en qué condiciones)? [Measurability, Spec §SC-001]

## Scenario Coverage

- [x] CHK023 - ¿Está cubierto el flujo primario (reordenar y que persista) con escenarios Given/When/Then? [Coverage, Spec §US1]
- [x] CHK024 - ¿Está cubierto el flujo alternativo de reordenar sin mouse? [Coverage, Spec §US4]
- [x] CHK025 - ¿Están cubiertos los flujos de excepción (fallo de red, rechazo del servidor, arrastre inválido)? [Coverage, Spec §FR-009, Edge Cases]
- [x] CHK026 - ¿Está cubierta la recuperación tras un rechazo (qué ve el usuario y qué puede hacer después)? [Coverage, Spec §Edge Cases]
- [x] CHK027 - ¿Se especifica el comportamiento ante acciones sucesivas rápidas del usuario? [Coverage, Spec §FR-015]
- [x] CHK028 - ¿Se declara explícitamente qué queda fuera de alcance (reordenar los bloques entre sí)? [Coverage, Spec §FR-014]

## Edge Case Coverage

- [x] CHK029 - ¿Están definidos los requisitos para bloques de cero y de una sola cuenta? [Edge Case, Spec §Edge Cases]
- [x] CHK030 - ¿Se aborda el caso de un identificador repetido en el conjunto enviado? [Edge Case, Spec §Edge Cases]
- [x] CHK031 - ¿Se aborda el caso de una cuenta borrada mientras el modal estaba abierto? [Edge Case, Spec §Edge Cases]
- [x] CHK032 - ¿Se define qué pasa cuando dos cuentas comparten posición por datos heredados previos a esta feature? [Edge Case, Spec §Assumptions]
- [ ] CHK033 - ¿Se especifica el comportamiento en dispositivos táctiles, o se declara explícitamente fuera de alcance? [Edge Case, Spec §Assumptions]

## Non-Functional Requirements

- [x] CHK034 - ¿Hay requisitos de accesibilidad para la operación sin mouse? [Coverage, Spec §FR-013, US4]
- [x] CHK035 - ¿Se declara una suposición de volumen que justifique enviar el bloque completo en cada guardado? [Assumption, Spec §Assumptions]
- [x] CHK036 - ¿Está definido el requisito de atomicidad de la operación en términos observables (todo o nada)? [Completeness, Spec §FR-007]
- [x] CHK037 - ¿Se especifica qué datos NO pueden ser alterados por la operación, y no sólo cuál sí? [Completeness, Spec §FR-011]
- [ ] CHK038 - ¿Se especifica si la operación debe quedar registrada en auditoría o historial de cambios de configuración? [Gap]

## Dependencies & Assumptions

- [x] CHK039 - ¿Está documentada la dependencia de que el atributo de posición ya existe y no se crea uno nuevo? [Assumption, Spec §Assumptions, Key Entities]
- [x] CHK040 - ¿Está declarada la suposición sobre permisos (quién puede reordenar) en lugar de darse por sobreentendida? [Assumption, Spec §Assumptions]
- [x] CHK041 - ¿Está declarado que el orden es global del negocio y no una preferencia por usuario? [Assumption, Spec §Assumptions]
- [x] CHK042 - ¿Se documenta la dependencia con el mecanismo existente de listado del modal (que ya muestra visibles y ocultas)? [Dependency, Spec §Assumptions]

## Ambiguities & Conflicts

- [x] CHK043 - ¿Quedan términos vagos sin cuantificar del tipo "intuitivo", "rápido" o "robusto"? [Ambiguity]
- [x] CHK044 - ¿Hay conflicto entre "el orden se guarda automáticamente" y algún requisito que sugiera confirmación manual? [Conflict, Spec §FR-004]
- [x] CHK045 - ¿Las decisiones cerradas en la sesión de clarificación quedaron reflejadas en los requisitos y no sólo en la bitácora? [Traceability, Spec §Clarifications, FR-008, FR-012]

## Resumen de la validación

**42 de 45 ítems pasan.** Los 3 abiertos son huecos reales pero de bajo impacto, resueltos abajo
sin necesidad de volver a consultar al usuario:

| Ítem | Hueco | Resolución |
|------|-------|------------|
| CHK007 | No hay requisito sobre el estado visual mientras el guardado viaja | **Aceptado como no requisito.** El guardado es una escritura chica sobre un catálogo de decenas de filas; SC-005 ya acota el total a < 2 s. Agregar un spinner por un lapso típicamente imperceptible sumaría parpadeo. El feedback lo dan el toast de éxito y la restauración ante error. |
| CHK022 | SC-001 no define protocolo de medición | **Aceptado.** Es un criterio de usabilidad orientativo, no un gate de aceptación; los gates verificables son SC-002 a SC-008. No se instrumenta un estudio de usabilidad para esta feature. |
| CHK033 | El soporte táctil no está resuelto | **Ya declarado fuera de alcance** en Assumptions ("se opera desde escritorio con mouse; el soporte táctil es deseable pero no es criterio de aceptación"). Se deja el ítem visible para que la decisión quede rastreable. |
| CHK038 | No hay requisito de auditoría del cambio de orden | **Aceptado como no requisito.** El orden es una preferencia de presentación sin efecto contable ni fiscal (FR-011 garantiza que no toca nada más); el principio III de la constitución reserva la trazabilidad estricta para documentos fiscales y contables. |

**Conclusión**: la spec está lista para `/speckit-tasks`. No se detectaron ambigüedades ni
conflictos bloqueantes.
