# Specification Quality Checklist: Importación por Excel escalable a archivos grandes

**Purpose**: Validate specification completeness and quality before proceeding to planning
**Created**: 2026-08-25
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

### Iteración 1 (2026-08-25) — hallazgos corregidos

1. **Nombres de clases/archivos en los requisitos funcionales**: la primera redacción de FR-001/FR-002
   nombraba `ImportadorFilas::importar()` y `Excel::toArray()` dentro del requisito. Se reescribieron
   en términos de comportamiento observable ("interpretar el archivo una sola vez por importación",
   "cada tanda lee únicamente sus filas"). Las referencias técnicas quedaron acotadas a la sección
   *Dependencias*, donde sí corresponden.

2. **"NDJSON" como requisito**: el formato intermedio elegido estaba redactado como requisito. Se
   movió a *Assumptions → Decisiones ya tomadas*, descrito de forma neutra ("formato intermedio de
   una fila por línea, en disco"), con la nota de que el detalle se confirma en `/speckit-plan`.
   FR-004 quedó expresado como la propiedad que importa: que sea estado transitorio y se elimine.

3. **Criterios de éxito técnicos**: había criterios en segundos por tanda y MB de memoria. Se
   reemplazaron por resultados observables por el usuario (SC-001 importación completa, SC-002 menos
   de 25 minutos con progreso visible, SC-003 soporta 10.000 filas). Los números técnicos que
   sustentan el diagnóstico se conservaron en la sección de contexto del incidente, que es
   descriptiva y no normativa.

### Notas de alcance

- Las mediciones del contexto son reales (tomadas del incidente del 25/08/2026 y de pruebas sobre el
  archivo del caso), no estimaciones teóricas. Se dejan explícitas para que el plan pueda calibrar el
  tamaño de tanda (FR-005) contra datos y no contra intuición.
- El cambio de `fastcgi_read_timeout` en el VPS quedó fuera de los requisitos funcionales a propósito:
  es infraestructura, va como paso de despliegue documentado y necesita autorización explícita del
  usuario.
- FR-011 a FR-018 son deliberadamente "no romper lo que ya anda". Son verificables contra la suite de
  tests existente de las specs 026/027/074/078 (SC-007).
