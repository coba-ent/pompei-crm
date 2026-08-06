# Specification Quality Checklist: Resolución de precio por tipo de publicación

**Purpose**: Validar que los requisitos de la spec sean completos, claros y consistentes antes de planear/implementar la resolución de qué Lista de Precios usa cada publicación al sincronizar (foco: lógica de dinero — principio IV de la constitución)
**Created**: 2026-08-06
**Feature**: [spec.md](../spec.md)

**Depth**: Standard · **Audience**: Reviewer (previo a `/speckit-tasks`) · **Focus**: resolución de precio por tipo de publicación + frescura del dato de clasificación Premium/Clásica

## Requirement Completeness

- [x] CHK001 - ¿Está especificado qué lista se usa cuando la publicación es Premium y tiene precio en la lista Premium? [Completeness, Spec FR-006]
- [x] CHK002 - ¿Está especificado qué lista se usa cuando la publicación es Premium pero NO tiene precio en la lista Premium? [Completeness, Spec FR-007]
- [x] CHK003 - ¿Está especificado qué lista se usa cuando no hay ninguna lista Premium configurada? [Completeness, Spec FR-008]
- [x] CHK004 - ¿Está especificado el comportamiento para publicaciones no Premium en todos los casos anteriores? [Completeness, Spec FR-009]
- [x] CHK005 - ¿Está especificado qué pasa si la publicación no tiene todavía un tipo clasificado (`listing_type_id` nulo, publicación recién vinculada antes del primer refresh)? [Gap]

## Requirement Clarity

- [x] CHK006 - ¿Está cuantificada la frecuencia de actualización del tipo de publicación en vez de un adjetivo vago ("periódicamente")? [Clarity, Spec FR-004 / Clarifications 2026-08-06]
- [x] CHK007 - ¿Está definido explícitamente qué valor de `listing_type_id` corresponde a "Premium" en vez de dejarlo implícito? [Clarity, Spec Assumptions]
- [x] CHK008 - ¿Es medible el criterio de éxito de que las publicaciones Premium reciben el precio correcto (no sólo "funciona bien")? [Measurability, Spec SC-001]

## Requirement Consistency

- [x] CHK009 - ¿Es consistente el criterio de resolución de lista entre los tres disparadores de sincronización (cambio de precio del producto, botón manual, cambio de configuración)? [Consistency, Spec US2]
- [x] CHK010 - ¿Es consistente FR-011 (evaluación por publicación individual) con el edge case de un producto con publicaciones de distinto tipo vinculadas (spec 036, 1:N)? [Consistency, Spec FR-011 / Edge Cases]

## Scenario Coverage

- [x] CHK011 - ¿Están cubiertos los escenarios primario (Premium con precio propio), alternativo (fallback a general) y de ausencia de configuración (sin lista Premium)? [Coverage, Spec US2 Acceptance Scenarios]
- [x] CHK012 - ¿Está cubierto el escenario de falla de la API de Mercado Libre al consultar el tipo de publicación? [Coverage, Spec Edge Cases]
- [x] CHK013 - ¿Está cubierto el escenario de despliegue inicial (publicaciones ya vinculadas antes de la feature, sin tipo clasificado todavía)? [Coverage, Spec US3 / FR-005]

## Non-Functional Requirements

- [x] CHK014 - ¿Está acotada la cantidad de llamadas a la API de Mercado Libre que agrega la clasificación de tipo, para no degradar la corrida de stock existente (cada 15 min)? [Coverage, Spec Clarifications 2026-08-06]

## Dependencies & Assumptions

- [x] CHK015 - ¿Está documentada la dependencia de que "Premium" se define exclusivamente por el valor `gold_pro` de la API de Mercado Libre, y que esa definición fue validada contra datos reales? [Assumption, Spec Assumptions]
- [x] CHK016 - ¿Está documentado que el respaldo a la lista general no incluye ningún cálculo o ajuste automático de precio (ej. no aplica IVA ni ningún porcentaje)? [Assumption, Spec Assumptions]

## Ambiguities & Conflicts

- [x] CHK017 - ¿Queda excluido explícitamente que esta feature no modifica el precio de las líneas de Venta creadas al convertir una orden de Mercado Libre? [Boundary, Spec Assumptions — ver también data-model.md "Sin cambios en `ml_ordenes`"]

## Notes

- Sin ambigüedades pendientes: los 17 ítems ya pasan contra la versión actual de la spec (post-clarify).
  No se generaron preguntas dinámicas al usuario para este checklist — el foco (resolución de precio +
  frescura de clasificación) se derivó directamente de FR-006 a FR-011 y de la Clarification ya resuelta,
  sin ambigüedad material que requiriera confirmación adicional antes de escribir los ítems.
