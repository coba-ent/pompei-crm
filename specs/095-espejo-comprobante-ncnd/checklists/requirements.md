# Specification Quality Checklist: Espejo del comprobante de origen al crear una NC/ND

**Purpose**: Validate specification completeness and quality before proceeding to planning
**Created**: 2026-09-02
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

- **Sin marcadores de clarificación**: la decisión que podía trabar el spec (replicar el descuento
  general en cabecera vs. prorratearlo en las líneas) quedó resuelta por observación directa de
  Contagram el 02/09/2026, no por criterio propio. Ver §Contexto y evidencia.
- **Criterios medibles con línea de base real**: SC-001 se ancla en la venta 24740, donde hoy la
  diferencia es de $11.497,80. El resto se verifica abriendo el alta y comparando contra el
  comprobante.
- **Nombres de tablas y campos en el spec**: `notas_credito_debito`, `descuento_general_pct`, etc.
  aparecen sólo en §Contexto y en Key Entities, para dejar asentado que el cambio **no** requiere
  migración. Los requisitos (FR) están redactados en lenguaje de negocio.
- **Alcance acotado**: cubre el ALTA. La edición de notas existentes queda explícitamente sin
  cambios (FR-011), y el hueco de validaciones al editar (notas 856 y 859 con crédito aplicado)
  queda documentado como riesgo conocido fuera de alcance.
- **Principio III de la constitución**: el tipo de comprobante debe derivarse y no elegirse a mano;
  FR-004 lo satisface para las NC/ND.
