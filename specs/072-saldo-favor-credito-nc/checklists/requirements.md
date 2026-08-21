# Specification Quality Checklist: Saldo a favor aplicable a nuevas Ventas y Compras

**Purpose**: Validate specification completeness and quality before proceeding to planning
**Created**: 2026-08-21
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

- **Iteración 1**: la primera redacción nombraba clases y métodos concretos (`Cobranzas::registrarCobro`,
  `MovimientoTesoreria`, `StoreCobroRequest`) dentro de los FR. Se reescribieron en términos de negocio
  ("no genera movimiento de tesorería", "el circuito de dinero real"); el detalle técnico corresponde a
  `plan.md`, no a la spec. El contexto técnico se conserva sólo donde documenta el caso real verificado.
- **Iteración 1**: SC-003 originalmente decía "no se rompe Tesorería", no verificable. Se reescribió
  como igualdad de seis totales concretos medibles antes/después.
- Sin marcadores [NEEDS CLARIFICATION]: las cuatro decisiones de diseño (dónde se aplica, origen del
  crédito, alcance Ventas+Compras, saldo visible en el selector) fueron respondidas por el usuario
  antes de redactar.
- **`/speckit-clarify` (sesión 2026-08-21)**: el scan detectó un error de correctitud contable en la
  primera redacción. FR-001 medía el crédito por el monto nominal de la NC, y ningún FR decía que
  aplicar el crédito debe descontar el saldo a favor del comprobante de origen. Con esa versión el
  caso Florencia dejaba al cliente con $30.771,29 a favor en lugar de $3.465,29 (doble conteo: el
  saldo a favor quedaba entero en la venta vieja y además saldaba la nueva). Corregido con FR-001
  reescrito, FR-003a (transferencia de saldo), FR-009a y SC-001a.
- Riesgo abierto para `/speckit-plan`: FR-017/018/019 chocan con el hecho de que hoy **todo** cobro
  genera movimiento de tesorería. El plan debe resolver si la aplicación de crédito es una entidad
  separada de `cobros` o un `cobro` marcado que se saltea Tesorería — con preferencia por lo que menos
  riesgo agregue sobre los saldos ya cuadrados.
