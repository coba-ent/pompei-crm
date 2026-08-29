# Specification Quality Checklist: Columnas del Libro IVA calcadas de Contagram

**Purpose**: Validate specification completeness and quality before proceeding to planning
**Created**: 2026-08-28
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

Validación realizada el 2026-08-28. Observaciones:

- **Esta spec revierte una decisión de la spec 089** (FR-010: "conservar las 19 columnas"). No es una
  contradicción silenciosa: la 089 asumió que 19 columnas eran superiores a 13, y al comparar contra el
  archivo real quedó a la vista que ocho de esas columnas van siempre en cero mientras faltaban tres
  que el contador sí usa. La decisión se tomó con el dato del período real a la vista (Julio 2026, 718
  comprobantes) y está registrada en Clarifications.
- **FR-008 es la salvaguarda fiscal de la decisión**: reducir a una sola columna de IVA es seguro
  únicamente si esa columna lleva el IVA *total*, no el tramo del 21%. Sin ese requisito, el día que
  aparezca una venta al 10,5% el libro subdeclararía en silencio — que es exactamente el tipo de error
  que el principio III de la constitución no tolera.
- **Riesgo asumido y explicitado**: el rótulo "IVA 21%" pasa a ser un calco de Contagram que no
  describe exactamente su contenido cuando hay alícuotas mixtas. Se prefiere la fidelidad al original
  (principio rector de CLAUDE.md) sobre un rótulo propio más preciso, pero el importe nunca se pierde.
- **El fixture está mal rotulado en origen** ("Julio 2026" con datos de agosto). Se declara en
  Assumptions y no invalida su uso: lo que se calca es la estructura de columnas, no los datos.
