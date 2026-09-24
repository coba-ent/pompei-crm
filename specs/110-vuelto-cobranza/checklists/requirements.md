# Specification Quality Checklist: Vuelto en la cobranza de una Venta

**Purpose**: Validate specification completeness and quality before proceeding to planning
**Created**: 2026-09-24
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

## Notas de la validación (iteración 1)

Se detectaron y corrigieron estos puntos antes de dar el checklist por cerrado:

1. **Nombres técnicos en el cuerpo de la spec**: el borrador mencionaba `StoreCobroRequest`,
   `movimientos_tesoreria.tipo` y `configuracion_ventas`. Se reemplazaron por descripciones de
   negocio ("la cobranza no admite un monto mayor al saldo", "distinguible de un gasto",
   "configuración global"). El detalle técnico relevado se pasa al `plan`, que es donde
   corresponde.

2. **FR-007 estaba implícito**: el borrador no decía qué pasa si el neto supera el saldo. Como la
   decisión de negocio es que el vuelto NO habilita sobrepagos, se explicitó como requisito para
   que sea testeable y para que no se confunda con la funcionalidad de saldo a favor.

3. **Edge case de cuenta de vuelto = cuenta de ingreso**: no estaba contemplado y es el caso más
   frecuente del negocio (recibe y devuelve de la misma caja). Se agregó con su criterio: dos
   movimientos, no uno neteado, para que el arqueo coincida con la realidad física.

4. **Supuesto sobre la moneda**: se explicitó que la conversión se hace fuera del sistema y que el
   CRM no guarda cotización ni moneda original. Sin eso, "paga en dólares" podía leerse como que la
   feature incluye soporte multimoneda, que está fuera de alcance.

## Notas de la validación (iteración 2 — post `/speckit-clarify`, 24/09/2026)

Se hicieron 2 preguntas y ambas se integraron en la spec:

1. **Tipo de movimiento del vuelto** → tipo propio `vuelto`, separado de `gasto`. Refuerza FR-004 y
   FR-005: con un tipo dedicado, que el vuelto se cuele en el informe de Gastos pasa a ser
   imposible por construcción y no depende de que cada informe se acuerde de filtrarlo.

2. **Neto por debajo del saldo** → se rechaza; el neto debe saldar exactamente la venta. Cambió
   FR-007, que antes sólo contemplaba el exceso. Efecto secundario positivo: acota la feature a un
   único caso bien definido (vuelto que cierra la operación), lo que simplifica las validaciones y
   elimina la ambigüedad entre "vuelto" y "cobro parcial".

Ambas decisiones se propagaron a los escenarios de aceptación, los edge cases y las entidades.
Todos los ítems del checklist siguen pasando (16/16).

## Notes

- Todos los ítems pasan (16/16). La spec está lista para `/speckit-plan`.
