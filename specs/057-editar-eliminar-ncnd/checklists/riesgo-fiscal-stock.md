# Riesgo Fiscal y de Stock Checklist: Edición y eliminación de NC/ND

**Purpose**: Validar que los requisitos que tocan dinero, IVA/CAE y stock (Principio III y IV de
la constitución) estén completos, claros y sin ambigüedad antes de pasar a `/speckit-tasks`.
**Created**: 2026-08-11
**Feature**: [spec.md](../spec.md)

**Note**: Enfoque de release-gate (revisor de PR), no de author-only — este feature toca CAE,
stock y saldos de cuenta corriente.

## Requirement Completeness

- [x] CHK001 - ¿Está definido qué campos quedan bloqueados vs. editables cuando la nota tiene CAE aprobado? [Completeness, Spec §Clarifications/FR-011]
- [x] CHK002 - ¿Está especificado qué pasa con el stock al editar (no sólo al crear/eliminar) una nota que afecta stock? [Completeness, Spec §US1 escenario 2]
- [x] CHK003 - ¿Está definida la regla de exclusión de la propia nota en el cálculo de "pendiente de ajuste" al editar? [Completeness, Spec §FR-005]
- [ ] CHK004 - ¿Está especificado qué pasa si el depósito de una nota que afecta stock deja de estar activo antes de editarla? [Gap, Spec §Edge Cases]
- [x] CHK005 - ¿Está definido el comportamiento cuando se intenta eliminar una nota referenciada por otra (cadena)? [Completeness, Spec §FR-006]

## Requirement Clarity

- [x] CHK006 - ¿Está cuantificado el límite de profundidad del encadenamiento "Documento que Ajusta" (no queda como "varios niveles")? [Clarity, Spec §FR-013]
- [x] CHK007 - ¿Está definido, sin ambigüedad, qué combinación de campos determina un comprobante "duplicado" (tipo+número, contra qué tablas)? [Clarity, Spec §FR-012]
- [ ] CHK008 - ¿Está definido el mensaje/código de error exacto que recibe el usuario cuando el bloqueo por CAE aplica, de forma distinguible de un error de validación común? [Ambiguity, Spec §FR-011]

## Requirement Consistency

- [x] CHK009 - ¿Es consistente la regla de reversión de stock entre "editar" y "eliminar" (misma garantía de dejar el stock exacto)? [Consistency, Spec §US1/US2]
- [x] CHK010 - ¿Son consistentes las acciones disponibles (Editar/Eliminar/Ver Detalle) entre Ventas y Compras, sin asimetrías no documentadas? [Consistency, Spec §FR-001]
- [ ] CHK011 - ¿Es consistente el criterio de "documento que ajusta" entre el selector de creación y el de edición (excluye la propia nota en ambos casos por igual)? [Consistency, Spec §FR-007]

## Acceptance Criteria Quality

- [x] CHK012 - ¿Es medible/verificable objetivamente el criterio de éxito de reversión de stock (SC-002)? [Measurability, Spec §SC-002]
- [x] CHK013 - ¿Es medible el criterio de bloqueo de eliminación por cadena (SC-003), sin depender de interpretación subjetiva de "inconsistencia"? [Measurability, Spec §SC-003]
- [ ] CHK014 - ¿Existe un criterio de aceptación específico para el caso de recálculo de IVA/monto cuando se editan ítems (no sólo "el monto se actualiza")? [Gap, Spec §US1]

## Scenario Coverage

- [x] CHK015 - ¿Cubre la spec el escenario de edición sin afectar stock (sólo monto/descripción)? [Coverage, Spec §US1 escenario 3]
- [x] CHK016 - ¿Cubre la spec el escenario de eliminación desde dentro del formulario de edición (no sólo desde el menú de fila)? [Coverage, Spec §US2 escenario 2]
- [x] CHK017 - ¿Cubre la spec el escenario de PDF sobre una nota de Compra, incluyendo el caso de no-regresión sobre Ventas? [Coverage, Spec §US3]
- [ ] CHK018 - ¿Cubre la spec qué pasa si se edita una nota y, como resultado, dos comprobantes distintos (Venta/Compra/otra NC-ND) terminan compitiendo por el mismo número en el mismo instante (condición de carrera, no sólo validación secuencial)? [Gap, Exception Flow]

## Edge Case Coverage

- [x] CHK019 - ¿Está definido el comportamiento ante notas migradas sin `venta_id`/`compra_id` (fuera de alcance, explícito)? [Edge Case, Spec §Assumptions]
- [x] CHK020 - ¿Está definido qué pasa si la nueva cantidad de un ítem supera el pendiente disponible al editar? [Edge Case, Spec §Edge Cases]
- [ ] CHK021 - ¿Está definido qué pasa si se intenta editar/eliminar una nota que fue eliminada (soft-deleted) por otra sesión concurrente, entre que el usuario abre el modal y confirma? [Gap, Exception Flow]

## Non-Functional Requirements

- [x] CHK022 - ¿Está especificado que ninguna operación de edición/eliminación recarga la página (UX obligatoria del proyecto)? [Completeness, Spec §FR-010]
- [ ] CHK023 - ¿Está especificado el comportamiento de auditoría/trazabilidad (quién editó/eliminó y cuándo) para una nota con impacto fiscal, dado que el proyecto ya tiene un módulo de Auditoría? [Gap, Non-Functional]

## Dependencies & Assumptions

- [x] CHK024 - ¿Está documentada la dependencia de que el tipo (crédito/débito) no es editable una vez creada? [Assumption, Spec §Assumptions]
- [x] CHK025 - ¿Está documentado que los bloques +Percepciones/+Impuestos Internos/+Intereses quedan fuera de alcance funcional de este feature? [Assumption, Spec §Assumptions]
- [ ] CHK026 - ¿Está validada la asunción de que sólo los permisos actuales de Ventas/Compras (sin uno nuevo) gobiernan editar/eliminar, incluyendo el caso de un usuario que sólo tenía permiso de "crear" bajo el modelo de permisos granulares? [Assumption, Spec §Assumptions]

## Ambiguities & Conflicts

- [x] CHK027 - ¿Hay algún conflicto entre "el tipo no es editable" (Assumptions) y el payload de contrato que igual incluye el campo `tipo` en el request de edición? [Conflict, Spec §Assumptions vs. contracts/rutas-ncnd.md] — Resuelto en `/speckit-analyze` (11/08/2026): contrato y FR-002 aclaran que `tipo` se rechaza (422) si difiere del actual; test T012b agregado.

## Notes

- Ítems marcados `[ ]` (CHK004, CHK008, CHK011, CHK014, CHK018, CHK021, CHK023, CHK026, CHK027)
  son huecos o ambigüedades reales detectados en la spec/contratos actuales — no bloquean seguir a
  `/speckit-tasks`, pero se resuelven con fixes puntuales durante `/speckit-analyze` (más abajo en
  la cadena) o se dejan como nota para la implementación si son de bajo impacto.
