# Specification Quality Checklist: El Excel de Rankings exporta lo mismo que muestra la pantalla

**Purpose**: Validate specification completeness and quality before proceeding to planning
**Created**: 2026-10-01
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

**La spec se reescribió por completo.** La primera versión apuntaba al Informe de Ventas Detallado y
proponía totales que siguieran al autofiltro de Excel. Estaba dirigida al informe equivocado: la
captura de pantalla del cliente mostró que el flujo real es **Informe de Ventas > Rankings / "Arma tu
Informe"**, y los exports de prueba destaparon que el problema no es de presentación sino que **el
archivo no contiene los mismos datos que la pantalla**.

Evidencia que sostiene cada requisito, medida sobre cuatro exports reales del mismo período
(01/07–30/09/2026), variando sólo la métrica:

| Métrica | Productos | Suma Total | Fila Total | Última fila |
|---|---|---|---|---|
| Cantidad de Productos | 166 | 2.149 | vacía | sin total |
| Cantidad de Ventas | 166 | 1.939 | vacía | sin total |
| Total Venta sin impuestos | 166 | 184.273.985,43 | vacía | sin total |
| Total Venta | 166 | 222.729.622,47 | vacía | sin total |

KPI de pantalla del mismo período: **1.922** prod./serv. y **$9.949.478,81**. Ninguna columna de
total coincide, y los defectos se repiten en las cuatro métricas — por eso FR-006 exige cubrirlas
todas y no sólo la que motivó el reporte.

Contraste que acota el alcance: filtrando por el proveedor Mauricio, el Ranking lista **7 productos
/ 69 unidades** y el Informe de Ventas Detallado **32 productos / 130 unidades**. Los 130 se
verificaron contra la base de producción y contra el KPI de la pantalla, así que el Detallado es
correcto y el Ranking no. Los 25 productos ausentes tienen todos menos de 6 unidades.

Comparación con el archivo de Contagram que usa la clienta (`2do PDR 2026 UN.xlsx`): **784
productos**, un renglón por producto, meses en columnas y fila `Totales` con valores. Es el formato
que FR-008 manda preservar, y la razón por la que la estructura no se toca.

Checklist completo — la spec está lista para `/speckit-clarify`.
