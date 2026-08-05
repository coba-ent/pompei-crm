# Specification Quality Checklist: Neteo de NC/ND en el Dashboard

**Purpose**: Validar la calidad de los requisitos (spec + plan) antes de pasar a tasks/implementaciÃ³n
**Created**: 2026-08-05
**Feature**: [spec.md](../spec.md) / [plan.md](../plan.md)
**Focus**: Completitud del criterio de neteo (Ventas/Compras simÃ©trico) + cobertura de casos de
borde (perÃ­odo cruzado, piso $0, filtro "Hoy") â€” los dos clusters de mayor riesgo detectados en la
spec, dado que el bug original nace justo de un caso de borde no contemplado.
**Depth**: Standard â€” spec de tamaÃ±o acotado (4 endpoints de un controller existente)
**Audience**: Autor + revisor en PR review

## Requirement Completeness

- [x] CHK001 - Â¿EstÃ¡ especificado quÃ© ocurre cuando una Venta tiene NC/ND emitidas en mÃ¡s de un
  perÃ­odo distinto entre sÃ­ (no sÃ³lo "perÃ­odo de la venta" vs. "un perÃ­odo de NC")? [Gap, Spec
  Edge Cases]
- [x] CHK002 - Â¿EstÃ¡ definido el criterio de neteo para "Venta Promedio" cuando el numerador neto
  es distinto al denominador ("Cantidad de Ventas", que no se netea por FR-004)? [Clarity, Spec
  Â§FR-003/FR-004]
- [x] CHK003 - Â¿Especifica la spec quÃ© pasa con el Ranking de Clientes/Productos cuando el usuario
  espera consistencia con los KPIs ya neteados (aunque estÃ© fuera de alcance)? [Completeness, Spec
  Edge Cases]
- [x] CHK004 - Â¿EstÃ¡ documentado si las Notas de CrÃ©dito/DÃ©bito soft-deleted (`deleted_at`) deben
  excluirse del neteo? [Gap]

## Requirement Clarity

- [x] CHK005 - Â¿Es "monto neto" (FR-001/FR-002) suficientemente preciso para distinguir entre
  "neteo por venta individual" y "neteo por evento/nota independiente" sin ambigÃ¼edad, dado que el
  plan (research.md DecisiÃ³n 1) tuvo que introducir dos fÃ³rmulas distintas para resolverlo? [Clarity,
  Spec Â§FR-001, Plan research.md]
- [x] CHK006 - Â¿Cuantifica la spec el piso de $0 (FR-007) como aplicable "por Venta/Compra dentro
  del mismo perÃ­odo", o queda abierto a interpretaciÃ³n cuando la nota cae en un perÃ­odo distinto?
  [Ambiguity, Spec Â§FR-007]
- [x] CHK007 - Â¿EstÃ¡ definido con precisiÃ³n quÃ© categorÃ­a hereda una Nota de CrÃ©dito/DÃ©bito cuando
  la Venta/Compra que ajusta cambiÃ³ de categorÃ­a despuÃ©s de emitida (o fue eliminada)? [Ambiguity,
  Spec Â§FR-006]

## Requirement Consistency

- [x] CHK008 - Â¿Es el criterio de neteo de Ventas (FR-001) exactamente simÃ©trico al de Compras
  (FR-002) en los tres casos de borde (piso $0, perÃ­odo cruzado, sin notas), o hay alguna asimetrÃ­a
  no declarada? [Consistency, Spec Â§FR-001/FR-002]
- [x] CHK009 - Â¿Es coherente el criterio de "perÃ­odo de emisiÃ³n de la nota" (FR-001) con el que ya
  usa el aging de Cuentas a Cobrar/Pagar (FR-008, que usa el acumulado a hoy, no por rango)? Â¿La
  spec aclara que son criterios distintos por diseÃ±o y no una inconsistencia? [Consistency, Spec
  Assumptions]

## Acceptance Criteria Quality

- [x] CHK010 - Â¿Es el Acceptance Scenario 4 (perÃ­odo cruzado) medible/verificable sin ambigÃ¼edad â€”
  es decir, da un resultado numÃ©rico esperado concreto, o sÃ³lo describe la direcciÃ³n del efecto?
  [Measurability, Spec User Story 1, Acceptance Scenario 4]
- [x] CHK011 - Â¿Define SC-002/SC-003 un margen de tolerancia numÃ©rica (redondeo) para considerar
  que los tres totales (KPI, grÃ¡fico mensual, dona) "coinciden entre sÃ­"? [Measurability, Spec
  Â§SC-002/SC-003]

## Scenario Coverage

- [x] CHK012 - Â¿Cubre la spec el escenario de una Venta/Compra sin categorÃ­a (`categoria_id` nulo)
  combinada con una NC/ND, para la dona de composiciÃ³n? [Coverage, Spec Â§FR-006]
- [x] CHK013 - Â¿Cubre la spec el caso de una Nota de DÃ©bito que hace que el monto neto de una Venta
  supere ampliamente su total original (recargo grande)? Â¿Hay algÃºn techo anÃ¡logo al piso de $0?
  [Coverage, Gap]

## Edge Case Coverage

- [x] CHK014 - Â¿Especifica la spec el comportamiento cuando el filtro "Hoy" no tiene ningÃºn dÃ­a
  "Ayer" con datos previos (sistema reciÃ©n puesto en marcha)? [Edge Case, Spec Â§FR-012]
- [x] CHK015 - Â¿EstÃ¡ definido quÃ© pasa si una NC/ND fue emitida con `fecha_emision` futura respecto
  de "Hoy" (dato inconsistente cargado a mano)? [Gap]

## Dependencies & Assumptions

- [x] CHK016 - Â¿EstÃ¡ validada la asunciÃ³n de que `NotaCreditoDebito.fecha_emision` siempre estÃ¡
  poblado (no nullable) para que el criterio de "perÃ­odo de la nota" sea aplicable sin casos NULL?
  [Assumption, Plan data-model.md]
- [x] CHK017 - Â¿Documenta la spec la dependencia de que el mecanismo de "perÃ­odo anterior
  equivalente" existente (`rangoPeriodo()`) generaliza correctamente a "Hoy" sin cambios de fÃ³rmula,
  o asume esto sin validarlo explÃ­citamente contra el cÃ³digo? [Assumption, Plan research.md
  DecisiÃ³n 3]

## Notes

- CHK001-CHK004, CHK012-CHK013, CHK015 quedan como huecos a resolver antes de `/speckit-tasks`
  (mayormente vÃ­a Assumptions adicionales o ajuste de FR, ya que no cambian el enfoque general).
- CHK005-CHK011 son de clarity/consistency â€” ya estÃ¡n mayormente resueltos por research.md
  (DecisiÃ³n 1/3), pero conviene reflejar esa resoluciÃ³n tambiÃ©n en el spec.md antes de generar
  tasks, para que la fuente de verdad de negocio (spec) no dependa sÃ³lo del artefacto tÃ©cnico
  (plan/research).
