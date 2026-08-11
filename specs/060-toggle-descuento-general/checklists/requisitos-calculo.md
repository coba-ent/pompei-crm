# Checklist: Calidad de requisitos — cálculo y persistencia del descuento general

**Purpose**: Validar que los requisitos de la spec 060 sean completos, claros y consistentes antes de
tasks/implementación, con foco en el punto de mayor riesgo (cálculo fiscal compartido + persistencia
consistente entre alta/edición).
**Created**: 2026-08-11
**Feature**: [spec.md](../spec.md)
**Depth**: Standard | **Audience**: Reviewer (previo a `/speckit-tasks`) | **Focus**: corrección
fiscal/cálculo, consistencia entre los 4 módulos, persistencia alta↔edición

## Requirement Completeness

- [x] CHK001 - ¿Está especificado qué pasa cuando el modo es monto fijo y el subtotal de ítems es $0 (sin ítems cargados aún)? [Completeness, Spec Edge Cases]
- [x] CHK002 - ¿Está definido el comportamiento cuando se alterna el modo repetidas veces antes de guardar? [Completeness, Spec Edge Cases]
- [x] CHK003 - ¿Está especificado qué persiste exactamente cuando el descuento general queda en $0/vacío en cualquiera de los dos modos? [Gap]
- [x] CHK004 - ¿Está definido el valor por defecto del modo al crear un comprobante nuevo? [Completeness, Spec §FR-002]

## Requirement Clarity

- [x] CHK005 - ¿Está cuantificado qué significa "mayor al subtotal" en FR-007 (subtotal bruto vs. subtotal ya con descuentos de línea aplicados)? [Clarity, Spec §FR-007]
- [x] CHK006 - ¿Es objetivamente verificable la regla de "no reconvertir entre unidades" al alternar el botón (FR-003)? [Measurability, Spec §FR-003]
- [x] CHK007 - ¿Está claro si "prorrateado... con el mismo criterio" (FR-006) para Compras remite explícitamente a que Compras comparte el mismo servicio de cálculo que Ventas/Presupuestos, o deja lugar a una interpretación distinta? [Clarity, Spec §FR-006]

## Requirement Consistency

- [x] CHK008 - ¿Son consistentes entre sí los requisitos de persistencia (FR-004/FR-005) y el requisito de no-regresión en modo porcentaje (FR-008) — es decir, agregar persistencia nueva no contradice "el cálculo en % no cambia"? [Consistency]
- [x] CHK009 - ¿Es consistente el tratamiento de Notas de Crédito/Débito (FR-010) con el resto de los requisitos de cálculo (FR-006), dado que NC/ND no pasa por el mismo servicio de cálculo que los otros tres módulos? [Consistency, Spec §FR-010]

## Acceptance Criteria Quality

- [x] CHK010 - ¿Es medible objetivamente el criterio de éxito SC-002 ("100% de los comprobantes reabiertos... sin excepción")? [Measurability, Spec §SC-002]
- [x] CHK011 - ¿Es verificable sin conocer la implementación el criterio SC-003 (0 regresiones en modo porcentaje)? [Measurability, Spec §SC-003]

## Scenario Coverage

- [x] CHK012 - ¿Están cubiertos los requisitos para el caso de un Presupuesto con descuento en monto fijo que se convierte en Venta (traslado sin reconversión)? [Coverage, Spec Edge Cases]
- [x] CHK013 - ¿Están cubiertos los requisitos para la combinación de descuento de línea + descuento general en monto fijo? [Coverage, Spec Edge Cases]
- [x] CHK014 - ¿Está cubierto el escenario de edición donde sólo se cambia el modo (sin cambiar el valor) — qué pasa con el valor previo? [Gap]

## Edge Case Coverage

- [x] CHK015 - ¿Está definido el comportamiento cuando el monto fijo cargado es exactamente igual al subtotal (borde de FR-007, no sólo "mayor")? [Edge Case, Ambiguity, Spec §FR-007]
- [x] CHK016 - ¿Está definido qué pasa si dos requests concurrentes editan el mismo comprobante cambiando el modo de descuento en direcciones distintas? [Gap, Edge Case] — nota: fuera de alcance según Assumptions (no se menciona concurrencia); aceptar el comportamiento por defecto de "última escritura gana" ya vigente en el resto del proyecto.

## Non-Functional Requirements

- [x] CHK017 - ¿Está especificado que el cálculo en modo monto fijo debe mantener la misma garantía de recalculo server-side (nunca confiar en el total del cliente) ya vigente para el resto del comprobante? [Completeness, Spec Assumptions]
- [x] CHK018 - ¿Está documentada la excepción de NC/ND a esa garantía (arquitectura preexistente que sí confía en el monto client-side)? [Consistency, Spec Assumptions]

## Dependencies & Assumptions

- [x] CHK019 - ¿Está documentada la dependencia de esta feature respecto del criterio de prorrateo proporcional a IVA ya establecido en spec 044? [Traceability, Spec Assumptions]
- [x] CHK020 - ¿Está validada explícitamente la asunción de que no se requiere backfill/migración de datos de comprobantes existentes? [Assumption, Spec Assumptions]

## Ambiguities & Conflicts

- [x] CHK021 - ¿Queda excluido sin ambigüedad el descuento por línea/ítem del alcance de este spec (a pesar de que la captura de referencia lo muestra con un control similar)? [Ambiguity, Spec Assumptions]

## Notes

- Primera pasada: CHK001, CHK003, CHK005, CHK007, CHK014 y CHK015 quedaron marcados como gap/ambigüedad
  real (no sólo pendientes de interpretación). Se corrigió `spec.md` en el momento — se agregaron 4
  bullets nuevos a Edge Cases (subtotal $0, monto igual al subtotal, valor $0/vacío, cambio de modo sin
  recargar valor en edición) y se afinaron FR-006 (Compras confirmado sin hedge) y FR-007 (definición
  explícita de qué subtotal aplica y que el igual es válido). Segunda pasada: los 21 ítems verifican
  contra la spec actualizada. No quedan ítems abiertos que bloqueen `/speckit-tasks`.
