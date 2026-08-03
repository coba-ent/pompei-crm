# Fiscal/ARCA Checklist: Facturación Electrónica (ARCA/AFIP)

**Purpose**: Validar que los requisitos de corrección fiscal (Principio III de la constitución) estén completos, claros y sin ambigüedad antes de pasar a tasks/implement — es el área de mayor riesgo del módulo.
**Created**: 2026-08-02
**Feature**: [spec.md](../spec.md)

## Requirement Completeness

- [x] CHK001 - ¿Está definido explícitamente qué campos hacen a un comprobante "aprobado" (CAE + vencimiento no nulos) vs sólo "guardado localmente"? [Completeness, Spec §FR-005, data-model.md]
- [x] CHK002 - ¿Están definidos los requisitos de reconciliación tras un timeout, para evitar comprobantes duplicados? [Completeness, Spec §FR-011]
- [x] CHK003 - ¿Está especificado qué pasa con comprobantes emitidos antes de activar el módulo (sin re-emisión retroactiva)? [Completeness, Spec Clarifications]
- [ ] CHK004 - ¿Están definidos los requisitos de rotación/renovación del certificado cuando vence en producción (más allá del aviso, FR-016)? [Gap]

## Requirement Clarity

- [x] CHK005 - ¿Está cuantificada la ventana de aviso de vencimiento de certificado ("30 días antes") o queda como valor configurable sin default claro? [Clarity, Spec §FR-016]
- [x] CHK006 - ¿Es inequívoco cuándo el sistema debe usar el fallback sin validez fiscal vs bloquear la operación? [Clarity, Spec §FR-014]
- [x] CHK007 - ¿Está claro qué constituye "datos fiscales mínimos" por Tipo de Comprobante (A requiere CUIT; B/C no)? [Clarity, Spec §FR-009]

## Requirement Consistency

- [x] CHK008 - ¿Es consistente el requisito de inmutabilidad de un comprobante con CAE (FR-012) con el flujo existente de NC/ND (spec 008), que ajusta en vez de modificar? [Consistency, Spec §FR-012, User Story 3]
- [x] CHK009 - ¿Es consistente el requisito de soft-delete de comprobantes fiscales con el resto de documentos contables ya implementados (Ventas/Compras)? [Consistency, data-model.md]

## Acceptance Criteria Quality

- [x] CHK010 - ¿Es medible el criterio de éxito de tiempo de emisión (SC-001, "menos de 15 segundos")? [Measurability, Spec §SC-001]
- [x] CHK011 - ¿Es verificable objetivamente "cero comprobantes duplicados" (SC-004) sin conocer la implementación? [Measurability, Spec §SC-004]

## Scenario Coverage

- [x] CHK012 - ¿Están cubiertos los tres resultados posibles de una solicitud a ARCA (aprobado, rechazado, no disponible) con requisitos propios para cada uno? [Coverage, Spec §User Story 4]
- [x] CHK013 - ¿Está cubierto el escenario de Compras, donde el CAE lo emite el Proveedor y no este CRM? [Coverage, Spec §FR-015, Assumptions]
- [ ] CHK014 - ¿Está definido el requisito para el caso de un comprobante rechazado que el usuario corrige y reintenta con datos distintos (no sólo "reintentar igual")? [Gap]

## Dependencies & Assumptions

- [x] CHK015 - ¿Está documentada la dependencia del certificado ARCA real como prerequisito externo, sin bloquear specify/plan/tasks? [Dependency, Spec Assumptions]
- [x] CHK016 - ¿Está documentada la brecha de no contar con un informe con capturas reales de Contagram para este módulo? [Assumption, Spec Assumptions]
- [ ] CHK017 - ¿Está validada la asunción de que el ambiente de homologación de ARCA es suficiente para probar el módulo sin certificado de producción? [Assumption, research.md §5 — pendiente de confirmar en la práctica al implementar]

## Notes

- CHK004, CHK014 y CHK017 quedan abiertos como riesgos de bajo impacto para el alcance de esta
  primera versión — no bloquean `/speckit-tasks`, pero conviene revisarlos durante
  `/speckit-implement` o en una spec de ampliación posterior (ej. renovación de certificado sin
  downtime).
