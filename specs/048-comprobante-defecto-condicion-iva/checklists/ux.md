# Checklist: Calidad de requisitos — autocompletado y consistencia UX

**Purpose**: Validar que los requisitos de esta feature (derivación automática de "Comprobante por
defecto" desde Condición de IVA en el modal de Cliente) sean completos, no ambiguos y consistentes con
el mecanismo de "no pisar ediciones manuales" ya vigente en el mismo modal.
**Created**: 2026-08-05
**Feature**: [spec.md](../spec.md)

## Requirement Completeness

- [x] CHK001 - ¿Está especificado el criterio exacto de derivación (qué condición de IVA deriva Factura A vs. B)? [Completeness, Spec §FR-001/FR-002]
- [x] CHK002 - ¿Está definido qué pasa si el usuario deselecciona la Condición de IVA (vuelve al placeholder)? [Completeness, Spec Edge Cases]
- [x] CHK003 - ¿Está cubierto el caso de edición de un cliente existente, no sólo el alta? [Completeness, Spec §FR-005/FR-006]

## Requirement Clarity

- [x] CHK004 - ¿Es "no pisar ediciones manuales" trazable al mismo mecanismo ya usado para los otros campos autocompletables, en vez de una regla nueva ambigua? [Clarity, Spec §FR-004]
- [x] CHK005 - ¿Está especificado si la derivación se dispara sólo por selección manual o también por el autocompletado del padrón de ARCA? [Clarity, Spec §FR-003]

## Requirement Consistency

- [x] CHK006 - ¿El criterio de derivación especificado en el spec coincide exactamente con el ya implementado en backend (ResolutorCliente/DerivadorComprobante), sin introducir una tercera variante? [Consistency, Spec Assumptions]
- [x] CHK007 - ¿El ciclo de vida del "tocado" (reset al abrir modal para cliente nuevo/distinto) es consistente con el ya definido para razón social/domicilio/condición de IVA? [Consistency, Spec §FR-007]

## Acceptance Criteria Quality

- [x] CHK008 - ¿Los criterios de éxito (SC-001/SC-002/SC-003) son medibles sin depender de detalles de implementación? [Measurability, Spec Success Criteria]
- [x] CHK009 - ¿Los escenarios Given/When/Then cubren tanto la derivación exitosa como el caso de no pisar una edición manual? [Acceptance Criteria, Spec User Stories]

## Scenario Coverage

- [x] CHK010 - ¿Está cubierto el escenario donde el usuario cambia la Condición de IVA varias veces antes de guardar? [Coverage, Spec User Story 1 Scenario 3]
- [x] CHK011 - ¿Está cubierto el escenario donde el autocompletado del padrón trae la condición de IVA en vez de una selección manual? [Coverage, Spec User Story 3]

## Edge Case Coverage

- [x] CHK012 - ¿Está definido el comportamiento cuando se agreguen condiciones de IVA nuevas al catálogo en el futuro? [Edge Case, Spec Edge Cases]
- [x] CHK013 - ¿Está definido que "Verificar" sin resultado (CUIT no encontrado/ARCA no disponible) no dispara la derivación? [Edge Case, Spec Edge Cases]

## Non-Functional Requirements

- [x] CHK014 - ¿Está especificado que la derivación no requiere una llamada de red adicional (es instantánea)? [Non-Functional, Spec §SC-001]

## Dependencies & Assumptions

- [x] CHK015 - ¿Está documentada la dependencia del criterio ya existente en backend (única fuente de verdad del mapeo condición→comprobante)? [Dependency, Spec Assumptions]
- [x] CHK016 - ¿Está explícitamente delimitado que esta feature no aplica a la creación automática de clientes desde Tiendanube/MercadoLibre (ya resuelta en backend)? [Assumption, Spec Assumptions]

## Ambiguities & Conflicts

- [x] CHK017 - ¿Hay algún requisito de esta spec que contradiga el comportamiento ya vigente del mecanismo de "tocado" del modal sin señalarlo? [Conflict] — Ninguno detectado: FR-004/FR-007 reafirman explícitamente que se reusa el mismo mecanismo.

## Notes

- Todos los ítems cerraron en verde en la primera pasada: el criterio de derivación ya está probado en
  producción en dos lugares de backend (spec 037), por lo que no hay incertidumbre de negocio que
  resolver — sólo replicarlo en frontend.
