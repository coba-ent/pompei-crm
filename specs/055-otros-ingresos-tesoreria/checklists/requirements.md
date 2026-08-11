# Specification Quality Checklist: Otros Ingresos impacta en Tesorería

**Purpose**: Validate specification completeness and quality before proceeding to planning
**Created**: 2026-08-10
**Feature**: [spec.md](../spec.md)

## Content Quality

- [x] No implementation details (languages, frameworks, APIs)
- [x] Focused on user value and business needs
- [x] Written for non-technical stakeholders
- [x] All mandatory sections completed

## Requirement Completeness

- [x] No [NEEDS CLARIFICATION] markers remain
- [x] Requirements are testable and unambiguous
- [x] Success criteria are measurable
- [x] Success criteria are technology-agnostic (no implementation details)
- [x] All acceptance scenarios are defined
- [x] Edge cases are identified
- [x] Scope is clearly bounded
- [x] Dependencies and assumptions identified

## Feature Readiness

- [x] All functional requirements have clear acceptance criteria
- [x] User scenarios cover primary flows
- [x] Feature meets measurable outcomes defined in Success Criteria
- [x] No implementation details leak into specification

## Notes

- Revisión 2026-08-11: el spec inicial asumía que el circuito Otros Ingresos→Tesorería no existía. Se verificó código y datos de producción y se corrigió el alcance — el circuito ya existe (spec 008); el bug real es que `Tesoreria::flujo()` no lee el tipo `ingreso` y que `Cobranzas::registrarOtroIngreso()` escribe `tipo='cobro'` en vez de `'ingreso'`. Ver sección "Hallazgo que redefine el alcance" en spec.md.
- Decisiones de negocio ya vigentes (edición in-place, reversión al eliminar, patrón pendiente/conciliación con `cuenta_tesoreria_id` null) se preservan sin cambios — no son objeto de este fix, sólo se documentan como comportamiento existente que no debe romperse.
