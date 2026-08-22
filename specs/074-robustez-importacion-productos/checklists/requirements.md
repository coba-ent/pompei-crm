# Specification Quality Checklist: Robustez del importador de Productos (stock concurrente y auditoría de precios)

**Purpose**: Validate specification completeness and quality before proceeding to planning
**Created**: 2026-08-22
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

- Validación ejecutada en 1 iteración. Correcciones aplicadas durante la redacción:
  - Los nombres de clases/métodos concretos del código (`ImportadorFilas::actualizarProducto()`,
    `StockService::disponibilidad()`, `precios_producto`) se mantuvieron **sólo** en la sección
    "Contexto del problema" reformulados en lenguaje de negocio, y se sacaron de los FR y de los
    Success Criteria, que quedaron expresados como comportamiento observable.
  - Los textos literales de motivo de movimiento de stock ("Ajuste (importación)" / "Registro inicial
    (importación)") se conservan explícitamente en FR-003 porque son **contrato de no-regresión
    verificable por el usuario en el histórico**, no detalle de implementación.
- **Alerta para `/speckit-plan` (Principio I de la constitución)**: la spec introduce un **tipo de
  operación auditable nuevo**, que hoy no existe en el conjunto de tipos de `logs_auditoria`
  (venta, presupuesto, cobro, gasto, compra, movimiento_tesoreria, movimiento_stock). Esto es un
  cambio de modelo de datos y **obliga a actualizar `docs/modelo_datos.md` §`logs_auditoria` y
  `docs/documentacion_principal_crm.md` antes de `/speckit-tasks`**.
- **Dato relevante hallado durante el relevamiento**: ya existe un punto único de escritura sobre los
  precios por lista (usado hoy para empujar cambios a Mercado Libre y Tiendanube), documentado como
  "único punto por el que pasa cualquier escritura, sin importar el camino que la originó (modal de
  Producto, importación masiva)". Es el candidato natural para cubrir FR-009 sin duplicar lógica —
  a validar en `/speckit-plan`, incluyendo cómo determina el origen y cómo obtiene el precio anterior.

## Revalidación post-`/speckit-clarify` (2026-08-22)

Estado: **16/16 items pasando** (sin regresiones; se mantiene el estado anterior). Cambios de alcance
incorporados durante la clarificación, todos asentados en `## Clarifications` de la spec:

- **Ampliación de alcance material**: el relevamiento de los caminos de escritura de precios encontró
  **dos orígenes no contemplados en el pedido original** — la **edición masiva de precios/costos** del
  listado de Productos (`accionAjustarPrecios`: ajusta por % o monto fijo todos los productos que
  matchean el filtro) y la **copia de producto**. La edición masiva es el camino de mayor riesgo del
  sistema para el problema que motivó la spec, y quedaba fuera si se tomaba el pedido literal
  ("importación + edición manual"). Se incorporó a FR-008/FR-009, con escenario propio (US1 #7) y
  SC-007.
- **Gap documentado, no cubierto**: `MigrarPuntoReposicion` borra precios con consulta directa a la
  base, salteando el modelo y por lo tanto el punto único de auditoría. Se agregó FR-009a para que la
  excepción quede documentada en vez de asumirse cubierta, y se listó en Fuera de alcance.
- **Ambigüedad de concurrencia resuelta**: el escenario US2 #2 pedía "la cantidad refleja el valor de la
  planilla ajustado por el movimiento concurrente", que no era verificable. Se reformuló como criterio
  de *no-lost-update* con equivalencia secuencial (final 50 o 47 según orden, ambos correctos), que sí
  es testeable.
- **SC-005 cuantificado**: pasó de "dentro de los límites de tiempo del asistente" (adjetivo vago) a una
  tanda de 1.000 filas dentro del margen actual, incluyendo la auditoría.
