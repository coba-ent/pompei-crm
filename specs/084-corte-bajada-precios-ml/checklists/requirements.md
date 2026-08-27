# Specification Quality Checklist: Corte de seguridad para las bajadas de precio hacia Mercado Libre

**Purpose**: Validate specification completeness and quality before proceeding to planning
**Created**: 2026-08-26
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

## Notas de la validación

**Iteración 1** — tres correcciones aplicadas antes de dar el checklist por cerrado:

1. **FR-002 decía "supere el umbral" sin definir el borde.** Una caída exactamente igual al umbral
   quedaba indefinida: con 20% de umbral, ¿una caída del 20% se retiene o pasa? Corregido a
   **"mayor** al umbral", y el escenario 2 de la US1 usa −15% para no rozar el borde.
2. **Faltaba el caso del precio publicado desconocido.** La spec asumía que siempre hay contra qué
   comparar. Un vínculo nuevo o una consulta fallida dejaban el corte sin criterio, y la lectura
   natural —"si no supera el umbral, publicá"— es la peligrosa. Agregados FR-005 y dos edge cases
   con la regla explícita: **sin precio publicado conocido no se publica**.
3. **SC-001 no era verificable.** Decía "el sistema previene bajadas peligrosas". Reescrito para
   reproducir los dos incidentes reales con sus números (−31% y ÷1000) como prueba concreta.

**Sobre "no implementation details":** la spec nombra `SincronizadorPrecios::resolverListaPrecio()`
en el input del usuario, pero el cuerpo lo expresa como requisito de negocio (FR-021: "la resolución
DEBE ser la misma que usa el envío; no puede existir una segunda definición"). Se dejó así a
propósito: es una restricción real —dos definiciones divergentes fue la causa del incidente— y no un
detalle de implementación.

**Sobre FR-033:** referencia las especificaciones de diseño obligatorias del proyecto (CLAUDE.md).
No es un detalle de implementación filtrado sino una restricción de producto vigente para toda
pantalla del CRM.
