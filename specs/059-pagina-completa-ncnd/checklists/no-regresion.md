# No-Regresión (release-gate) Checklist: Página completa de NC/ND

**Purpose**: Validar que la spec no deje ambigüedad sobre qué comportamiento de negocio (spec 057)
debe permanecer intacto al reestructurar sólo la UI.
**Created**: 2026-08-11
**Feature**: [spec.md](../spec.md)

**Note**: Enfoque de release-gate (revisor de PR) — el riesgo de este spec no es lógica de negocio
nueva, es que un cambio de UI arrastre sin querer un cambio de comportamiento ya validado.

## Requirement Completeness

- [x] CHK001 - ¿Está explícito que ninguna validación/regla de negocio de spec 057 cambia (sólo la UI que la invoca)? [Completeness, Spec §FR-006]
- [x] CHK002 - ¿Está definido qué pasa con las rutas `PUT`/`DELETE`/`POST` ya existentes de spec 057 (se reusan tal cual, no se duplican)? [Completeness, Spec §Assumptions]
- [x] CHK003 - ¿Está especificado si los tests Feature de spec 057 (`NotaCreditoDebitoEditarTest`, `NotaCreditoDebitoEliminarTest`) deben seguir pasando sin modificación, como prueba de no-regresión? [Gap] — Resuelto: tasks.md T008/T015 corren esas suites sin modificar como criterio de no-regresión.

## Requirement Clarity

- [x] CHK004 - ¿Es inequívoco qué campos se mueven del modal a la página completa (Fecha/Monto/Descripción/Comprobante propio/Ítems) vs. cuáles quedan en el modal (Tipo/Documento que Ajusta/Stock/Mes)? [Clarity, Spec §FR-001]
- [x] CHK005 - ¿Es claro que "Afecta Stock" deshabilitado en edición es un requisito nuevo (no estaba en spec 057), no una reinterpretación de algo ya dicho? [Clarity, Spec §FR-008]

## Requirement Consistency

- [x] CHK006 - ¿Es consistente el comportamiento entre Ventas y Compras (mismo patrón espejo, sin asimetrías nuevas)? [Consistency, Spec §FR-011]
- [x] CHK007 - ¿Es consistente el criterio de "vuelve al detalle de origen" (FR-007) con el que ya usan Nueva Venta/Nueva Compra tras guardar (misma redirección, o un patrón distinto)? [Consistency, Gap] — Resuelto: spec.md líneas 34/35/52/53 son inequívocas — redirect a `ventas.show`/`compras.show` de la Venta/Compra de origen tanto en Guardar como en Cancelar/Eliminar, mismo patrón que ya usan Nueva Venta/Nueva Compra.

## Acceptance Criteria Quality

- [x] CHK008 - ¿Es objetivamente verificable que "cero regresiones funcionales" (SC-004) sin depender de interpretación subjetiva? [Measurability, Spec §SC-004]

## Scenario Coverage

- [x] CHK009 - ¿Cubre la spec el acceso directo por URL a la página completa sin pasar por el modal? [Coverage, Spec §FR-010]
- [x] CHK010 - ¿Cubre la spec el caso de una nota con ítems mixtos (con y sin producto) heredado de la migración histórica? [Coverage, Spec §Edge Cases]
- [x] CHK011 - ¿Cubre la spec qué pasa si el usuario abre la página completa de Crear, la abandona sin guardar, y vuelve a "Agregar" — queda algún residuo (borrador) o arranca limpio cada vez? [Gap, Edge Case] — Resuelto: la página completa de Crear es una vista `GET` server-rendered sin borrador persistido (ni localStorage ni sesión) — cada visita arranca limpia desde la query string de paso 1, igual que Nueva Venta/Nueva Compra.

## Dependencies & Assumptions

- [x] CHK012 - ¿Está documentado que el backend de spec 057 no se modifica en absoluto? [Assumption, Spec §Assumptions]
- [x] CHK013 - ¿Está documentado que "Documento que Ajusta"/encadenamiento (US4 de spec 057) sigue sin resolverse, sin ampliar su alcance en este spec? [Assumption, Spec §Assumptions]

## Notes

- Ítems `[ ]` (CHK003, CHK007, CHK011) son de bajo impacto — se resuelven como parte natural de
  `/speckit-tasks` (T de "correr suite de spec 057 sin cambios" cubre CHK003; CHK007/CHK011 quedan
  como criterio de implementación, no bloquean seguir).
