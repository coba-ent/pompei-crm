# Checklist: Calidad de negocio — Neteo NC/ND en Rankings del Dashboard

**Purpose**: Validar que los requisitos de spec.md/plan.md sean completos, claros y consistentes antes de generar tasks.md
**Created**: 2026-08-24
**Feature**: [spec.md](../spec.md) · [plan.md](../plan.md)

## Requirement Completeness

- [x] CHK001 - ¿Está especificado qué pasa cuando una NC/ND ajusta una venta cuyo cliente fue dado de baja? [Completeness, Spec Edge Cases]
- [x] CHK002 - ¿Está definido el comportamiento cuando una NC/ND no tiene ítems desglosados por producto? [Completeness, Spec Assumptions]
- [x] CHK003 - ¿Se especifica si el Top 10 del ranking se recalcula sobre el conjunto neteado completo (podría entrar/salir un cliente del Top 10 por efecto del neteo) o sólo se ajustan los montos de los que ya estaban en el bruto? [Completeness, Spec Assumptions]
- [x] CHK004 - ¿Está documentada la fuente de verdad de dominio que hay que corregir (`documentacion_principal_crm.md` §6.3/§7) como parte del alcance? [Completeness, Plan Constitution Check]

## Requirement Clarity

- [x] CHK005 - ¿Está claro, sin ambigüedad, cuál es el criterio de piso a aplicar (con piso vs. sin piso) tras la resolución de la contradicción encontrada? [Clarity, Spec Clarifications]
- [x] CHK006 - ¿"Mismo período" y "período distinto" están definidos en términos de qué fecha se compara contra qué fecha (fecha de la nota vs. fecha de la venta de origen)? [Clarity, Spec FR-003]
- [x] CHK007 - ¿Se especifica qué unidad de tiempo define un "período" para esta comparación (el rango de fechas que el usuario eligió en el filtro del Dashboard, no necesariamente un mes calendario)? [Clarity, Spec Assumptions]

## Requirement Consistency

- [x] CHK008 - ¿El criterio de piso elegido para los Rankings coincide exactamente con el que usa `montoNetoQuery()` para KPIs/Totales/Donas, sin introducir una variante nueva? [Consistency, Spec Clarifications, Plan Summary]
- [x] CHK009 - ¿Los requisitos dejan explícito que el Ranking del módulo Informes (spec 069) no se modifica, para que no se generen dos comportamientos de "ranking" divergentes bajo el mismo nombre? [Consistency, Spec FR-007]
- [x] CHK010 - ¿SC-001 (conciliación centavo a centavo con los Totales del Dashboard) es consistente con el criterio "sin piso" finalmente elegido, y no con el piso descartado? [Consistency, Spec SC-001]

## Acceptance Criteria Quality

- [x] CHK011 - ¿SC-001, SC-002 y SC-003 son verificables sin conocer la implementación (no mencionan queries, tablas ni código)? [Measurability]
- [x] CHK012 - ¿SC-002 sigue siendo válido tal cual está redactado ("ningún cliente/producto se muestra con monto o cantidad negativa... en el mismo período") ahora que se decidió NO aplicar piso? [Conflict, Spec SC-002] — corregido: redactado en términos del criterio sin piso.

## Scenario Coverage

- [x] CHK013 - ¿Existe un escenario de aceptación para el caso "nota emitida en período distinto al de la venta, con neto resultante negativo, sin piso"? [Coverage, Spec User Story 3]
- [x] CHK014 - ¿Existe un escenario para ND sin techo superior en ambos rankings (Clientes y Productos)? [Coverage, Spec FR-006]
- [x] CHK015 - ¿Existe un escenario de aceptación para una venta con múltiples NC/ND parciales acumuladas en el mismo período (no sólo una nota por venta)? [Gap] — aceptado sin escenario nuevo: FR-001/FR-002 ya exigen `SUM` agregado de todas las notas del cliente/producto en el período, cubre N notas sin distinción.

## Edge Case Coverage

- [x] CHK016 - ¿Se define qué pasa con un cliente/producto cuyo neto da exactamente $0 en el período (debe seguir listado, no excluirse)? [Coverage, Spec FR-009]
- [x] CHK017 - ¿Se define el caso de NC/ND que ajusta una venta fuera del período filtrado y cuya propia emisión también cae fuera? [Coverage, Spec Edge Cases]

## Dependencies & Assumptions

- [x] CHK018 - ¿Está validada explícitamente la asunción de que `nota_credito_debito_items` tiene `producto_id`/`cantidad` (necesaria para el Ranking de Productos)? [Assumption, Plan Project Structure]
- [x] CHK019 - ¿Está documentada la dependencia de no modificar `montoNetoQuery()` ni el comportamiento ya vigente de KPIs/Totales/Donas? [Dependency, Spec FR-008]

## Ambiguities & Conflicts

- [x] CHK020 - ¿Quedó resuelta y trazada la contradicción entre la redacción original del usuario (piso en $0) y el código vigente (sin piso)? [Conflict, Spec Clarifications]

## Notes

- **Acción requerida antes de `/speckit-tasks`**: corregir **SC-002** en spec.md — quedó redactado para el criterio "con piso" que se descartó en la Clarification. Debe reformularse en términos del criterio "sin piso" (p. ej.: "el neto del Ranking coincide exactamente con el que resultaría de aplicar el mismo cálculo de `montoNetoQuery()` a nivel de cliente/producto, incluyendo casos negativos").
- **Gaps a resolver o aceptar como fuera de alcance antes de tasks**: CHK003 (recomputar Top 10 sobre neteado vs. ajustar sólo montos de los ya presentes), CHK007 (unidad de "período": rango del filtro, no mes calendario), CHK015 (múltiples NC/ND acumuladas sobre la misma venta).
