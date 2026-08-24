# Specification Quality Checklist: Costo congelado en el ítem de venta para un CMV fiel a Contagram

**Purpose**: Validate specification completeness and quality before proceeding to planning
**Created**: 2026-08-24
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

Todos los ítems pasan. Los 3 marcadores [NEEDS CLARIFICATION] que quedaron abiertos en `specify`
fueron resueltos en `/speckit-clarify` (sesión 2026-08-24) e integrados en FR-008, FR-009 y FR-010:

1. **FR-009 — edición de venta**: el costo congelado se conserva siempre; una línea agregada en la
   edición congela el costo del día de la edición. Evita que corregir una venta vieja altere el
   Resultado de un mes cerrado.
2. **FR-008 — notas de crédito**: las líneas con `origen = venta_original` copian el costo congelado
   de la venta que revierten; las `nuevo` y las NC sin venta asociada congelan el costo vigente al
   emitir. Si la venta original es histórica y no tiene costo congelado, cae al fallback de FR-003.
3. **FR-010 — Informe de Compras**: **fuera de alcance por evidencia.** Verificado contra
   `migracion-nueva/excel-origen/Compras/2026 Compras.xlsx`: `SUM(Costo × Cantidad) = 194.444.921,65`
   coincide con la card "Costo Actual" ($194.444.921), 699 de 700 productos tienen un único valor de
   costo en todo el año (no varía por fecha ⇒ no está congelado) y el informe no tiene card de CMV.
   No arrastra el error.

### Decisiones que NO son ambigüedades (ya resueltas por el usuario, no reabrir)

- **Fallback al promedio de compras** para líneas sin costo congelado (FR-003).
- **Sin backfill** de datos históricos (FR-012 lo documenta como opción futura).
- **Cantidad Prod./Serv. y Precio Neto quedan fuera de alcance** (ver §Out of Scope).

### Verificación de consistencia con la documentación de dominio (principio I)

Esta spec **contradice deliberadamente** a `docs/documentacion_principal_crm.md §21.1` y a
`docs/modelo_datos.md §Deuda de modelo`, que hoy presentan el promedio ponderado de compras como
la regla del CMV. La contradicción está identificada, fundamentada con evidencia (export de julio
2026) y su resolución es FR-011: corregir ambos documentos **antes** de `/speckit-tasks`. No se
avanza con la inconsistencia en silencio.

Nota a favor: `docs/modelo_datos.md` ya anticipaba esta spec — dice textualmente que "congelar el
costo al confirmar la venta sigue siendo la solución exacta, pero es una spec propia que toca el
alta de Ventas". Esta es esa spec.
