# Fiscal e Importes Checklist: Cada línea del comprobante es un ajuste independiente en la NC/ND

**Purpose**: Validar que los requisitos de la spec 096 están escritos con la precisión que exige el
cálculo de una NC/ND — un documento con consecuencias fiscales, donde una cantidad o un precio de
línea mal identificado no es un bug de UI sino un problema impositivo.
**Created**: 2026-09-03
**Feature**: [spec.md](../spec.md)

> Esta lista evalúa **cómo están escritos los requisitos**, no si el código funciona. Cada ítem
> pregunta si algo está bien especificado, no si anda bien.

## Identidad de línea

- [x] CHK001 - ¿Está especificado con qué clave se identifica de forma única una línea del comprobante de origen? [Clarity, Spec §Key Entities]
- [x] CHK002 - ¿Está definido qué ocurre cuando dos líneas tienen el mismo producto, precio y bonificación (indistinguibles a simple vista)? [Edge Case, Spec §Edge Cases]
- [x] CHK003 - ¿Está explicitado que las líneas NO se fusionan aunque parezcan idénticas? [Clarity, Spec §Edge Cases]

## Cálculo de pendiente y fallback

- [x] CHK004 - ¿Está definido el criterio para decidir si el pendiente de un producto se calcula agregado o por línea? [Completeness, Spec §FR-006]
- [x] CHK005 - ¿Está especificado que la decisión de modo (agregado/por línea) es por producto dentro de un comprobante, y no global? [Clarity, Spec §FR-006]
- [x] CHK006 - ¿Está cubierto el caso de que dos productos del mismo comprobante estén en modos distintos simultáneamente? [Coverage, Spec §FR-006]
- [x] CHK007 - ¿Está definido qué pasa cuando una NOTA VIEJA (sin referencia de línea) y una nota NUEVA (con referencia) coexisten sobre el mismo producto? [Edge Case, Spec §Edge Cases]
- [x] CHK008 - ¿El criterio de transición de modo agregado a modo por línea está expresado de forma verificable (qué condición exacta la dispara)? [Measurability, Spec §FR-006]

## Corrección de importes

- [x] CHK009 - ¿Está definido que cada línea precargada conserva el precio y bonificación de ESA línea puntual, no de otra línea del mismo producto? [Completeness, Spec §FR-002]
- [x] CHK010 - ¿El criterio de "total propuesto igual al del comprobante" está expresado con una línea de base concreta y medible? [Measurability, Spec §SC-001]
- [x] CHK011 - ¿Está especificada la tolerancia de redondeo aplicable al comparar el total propuesto contra el del comprobante en este escenario? [Consistency, Spec §FR-005]
- [x] CHK012 - ¿Está definido qué se precarga cuando el comprobante no tiene ningún producto repetido (caso mayoritario)? [Coverage, Spec §US3]

## Anulación parcial: borrar una línea sin afectar a las demás

- [x] CHK013 - ¿Está explicitado que borrar una línea precargada no recalcula ni redistribuye las cantidades de las demás líneas del mismo producto? [Clarity, Spec §FR-007]
- [x] CHK014 - ¿Están definidos los requisitos para el caso de guardar sólo una parte de las líneas repetidas precargadas? [Coverage, Spec §US2]

## Datos históricos y migración

- [x] CHK015 - ¿Está declarado explícitamente que las NC/ND ya existentes no se reescriben ni se les asigna retroactivamente una referencia de línea? [Assumption, Spec §Assumptions]
- [x] CHK016 - ¿Está cuantificado el alcance real del problema (cuántos comprobantes y cuántas notas ya creadas están afectados)? [Completeness, Spec §Assumptions]
- [x] CHK017 - ¿Está definido el comportamiento para una NC/ND con "afecta stock = No" (sin ítems) frente a este cambio? [Edge Case, Spec §Assumptions]

## Alcance y no regresión

- [x] CHK018 - ¿Está explicitado que la edición de una NC/ND existente sigue sin depender del comprobante de origen? [Consistency, Spec §FR-009]
- [x] CHK019 - ¿Está declarado que este fix no reabre ni modifica el alcance ya cerrado de la spec 095 (cabecera, descuento general, tipo de comprobante)? [Assumption, Spec §Assumptions]
- [x] CHK020 - ¿Está definido que el comportamiento es equivalente en Ventas y en Compras? [Consistency, Spec §FR-010]
- [x] CHK021 - ¿Está especificado que un comprobante sin producto repetido tiene comportamiento idéntico al actual, sin cambios visibles? [Completeness, Spec §FR-008]

## Cobertura de escenarios

- [x] CHK022 - ¿Está definido qué ocurre si se crea una segunda nota sobre un comprobante donde la primera nota ya ajustó una de las líneas repetidas? [Coverage, Spec §US2]
- [x] CHK023 - ¿Están cubiertos los criterios de éxito medibles para el caso verificado en producción (venta 24854)? [Measurability, Spec §SC-001]
