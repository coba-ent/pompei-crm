# Specification Quality Checklist: Conversión manual obligatoria para órdenes de Mercado Libre en estado excepcional

**Purpose**: Validate specification completeness and quality before proceeding to planning
**Created**: 2026-08-14
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

**Iteración 1 — 3 correcciones aplicadas:**

1. *Detalles de implementación en el problema.* El borrador nombraba clases y campos concretos
   (`ConversorOrdenAVenta`, `payments[].status = in_mediation`, `EvaluadorConvertibilidad`). Se reescribió
   en términos de negocio: "el estado de los pagos" en vez del campo, "el sistema" en vez de la clase. El
   detalle técnico verificado en el código pasa al plan, que es donde corresponde.

2. *FR-005 describía una solución, no un requisito.* Decía "agregar una columna a la tabla de órdenes".
   Se reformuló como la necesidad ("registrar en la orden que tiene un reclamo en mediación, de modo que
   la evaluación pueda usar ese dato sin volver a consultar a Mercado Libre") dejando el cómo al plan.

3. *Faltaba el borde de la carrera entre el aviso y la confirmación.* La orden puede cambiar de estado
   mientras el aviso está en pantalla. Se agregó el edge case y el FR-015, porque sin definirlo cada
   implementación resolvería distinto y el resultado sería impredecible.

**Decisiones ya cerradas por el usuario antes de escribir la spec** (no quedan como clarificaciones):

- La conversión manual exige confirmación explícita con registro de quién la forzó.
- Los cuatro estados excluidos: canceladas, en mediación, reembolso parcial y alerta de fraude.
- Alcance sólo Mercado Libre; Tiendanube queda para una spec aparte.

**Iteración 2 — tras `/speckit-clarify` (14/08/2026):**

El scan de ambigüedad encontró **un conflicto real entre specs**, no una imprecisión de redacción: forzar
la conversión de una orden cancelada dispararía de inmediato el aviso posterior de la spec 063 por ese
mismo motivo, avisándole a la persona de algo que acaba de decidir. Se resolvió con FR-018 y FR-019, y se
agregó SC-007 para poder verificarlo. Sin esto la feature se habría implementado y el resultado habría sido
molesto de usar desde el primer día.

Las otras tres decisiones (dónde se registra la conversión forzada, cómo se informan las excluidas en el
resumen del lote, y que la acción quede visible) se tomaron con defaults razonables y quedaron asentadas en
la sección Clarifications para poder revertirse a conciencia.

**Alerta para el plan**: el principio III de la constitución (corrección fiscal) aplica de lleno acá — una
conversión forzada emite un comprobante. El plan debe cubrir con tests la conversión forzada y, sobre todo,
que la confirmación no se pueda saltear (FR-010), porque es la única barrera entre el operador y una
factura sobre una orden cancelada.
