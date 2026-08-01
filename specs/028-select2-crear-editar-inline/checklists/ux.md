# UX Requirements Quality Checklist: Crear/editar catálogo inline en selects de Presupuestos

**Purpose**: Validar que los requisitos de interacción (spec.md) sean completos, claros, consistentes y verificables antes de pasar a tasks/implementación — no valida la implementación en sí.
**Created**: 2026-07-31
**Feature**: [spec.md](../spec.md)

## Requirement Completeness

- [x] CHK001 - ¿Están especificados los tres selects afectados (Cliente, Categoría de Venta, Vendedor) con el mismo nivel de detalle, sin dejar ninguno implícito? [Completeness, Spec §FR-001-005]
- [x] CHK002 - ¿Está definido qué campos mínimos pide el modal de alta rápida de Cliente? [Completeness, Spec §FR-004, Assumptions]
- [x] CHK003 - ¿Está definido qué pasa con los campos de Cliente que el modal rápido no pide (facturación, contactos)? [Completeness, Spec Assumptions]
- [ ] CHK004 - ¿Está especificado el estado de carga/deshabilitado del botón "Crear"/"Guardar" del modal mientras la request AJAX está en curso? [Gap]

## Requirement Clarity

- [x] CHK005 - ¿Está descripta sin ambigüedad la posición de la opción "Crear X" dentro del dropdown (siempre primera fila, incluso con texto de búsqueda sin resultados)? [Clarity, Spec §FR-001, Edge Cases]
- [x] CHK006 - ¿Está claro que el ícono de edición actúa sobre el ítem de ESA fila y no sobre el valor actualmente seleccionado del formulario? [Clarity, Spec §FR-002, User Story 2]
- [ ] CHK007 - ¿Está cuantificado qué significa "visualmente destacada" para la opción "Crear X" (contraste, color, ícono) más allá de la referencia a la captura? [Ambiguity, Spec §FR-001]

## Requirement Consistency

- [x] CHK008 - ¿El comportamiento descripto para "Crear X" es consistente entre Cliente, Categoría de Venta y Vendedor (mismos verbos, misma estructura de aceptación)? [Consistency, Spec §User Story 1]
- [x] CHK009 - ¿El retiro de los links "Renombrar"/"Eliminar" del label es consistente con la ausencia de esos mismos triggers ahora reemplazados por FR-001/FR-002, sin dejar un camino alternativo no documentado? [Consistency, Spec §FR-006]

## Acceptance Criteria Quality

- [x] CHK010 - ¿Los criterios de éxito (SC-001 a SC-004) son medibles sin conocer la implementación (tiempo, ausencia de recargas, cobertura de flujo)? [Measurability, Spec §Success Criteria]
- [ ] CHK011 - ¿SC-001 ("menos de 15 segundos") tiene un método de medición implícito acordado (ej. desde click en "Crear X" hasta ítem seleccionado), o queda abierto a interpretación? [Ambiguity, Spec §SC-001]

## Scenario Coverage

- [x] CHK012 - ¿Están cubiertos los escenarios de alta y de edición para los tres catálogos? [Coverage, Spec §User Story 1, §User Story 2]
- [x] CHK013 - ¿Está cubierto el caso de cancelar el modal sin aplicar cambios? [Coverage, Spec §User Story 2 Acceptance Scenario 4]
- [ ] CHK014 - ¿Está definido el comportamiento si el usuario intenta crear un ítem con el nombre vacío o inválido (validación del backend ya existente) reflejado en el modal inline? [Gap, Exception Flow]

## Edge Case Coverage

- [x] CHK015 - ¿Está definido el comportamiento de "Crear X" cuando la búsqueda no matchea ningún resultado existente? [Edge Case, Spec §Edge Cases]
- [x] CHK016 - ¿Está definido qué pasa si dos usuarios crean el mismo nombre de catálogo en paralelo? [Edge Case, Spec §Edge Cases]
- [x] CHK017 - ¿Está definido qué pasa si se intenta editar un ítem que otro usuario eliminó mientras el dropdown estaba abierto? [Edge Case, Spec §Edge Cases]

## Non-Functional Requirements

- [x] CHK018 - ¿Está referenciado el cumplimiento de las reglas de diseño obligatorias del proyecto (modal + AJAX, toasts, sin recarga)? [Coverage, Spec §FR-008]
- [ ] CHK019 - ¿Hay algún requisito de accesibilidad (navegación por teclado del ícono de lápiz dentro del dropdown Select2) documentado? [Gap]

## Dependencies & Assumptions

- [x] CHK020 - ¿Están documentadas explícitamente las suposiciones sobre el alcance del modal de alta rápida de Cliente y la ausencia de "Eliminar"? [Assumption, Spec §Assumptions]
- [x] CHK021 - ¿Está documentado que el spec no reutiliza este patrón todavía en Ventas/Otros Ingresos/Compras, y que esos módulos comparten el mismo catálogo? [Dependency, Spec §FR-007, Assumptions]

## Ambiguities & Conflicts

- [ ] CHK022 - ¿Hay algún conflicto entre "el ícono de lápiz no requiere seleccionar el ítem primero" (FR-002) y el comportamiento nativo de Select2 de seleccionar la fila al hacer click, que deba aclararse explícitamente como requisito de "click en el ícono no dispara selección"? [Conflict, Spec §FR-002]

## Notes

- Ítems marcados sin resolver (CHK004, CHK007, CHK011, CHK014, CHK019, CHK022) son de bajo impacto para continuar a `/speckit-tasks` (no bloquean: son detalles de estado de carga, accesibilidad y mensajes de validación que ya resuelven los endpoints/patrones reutilizados) — se dejan como notas para afinar durante `/speckit-tasks` en vez de reabrir `/speckit-clarify`.
