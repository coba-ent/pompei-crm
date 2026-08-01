# Funcional Checklist: Conversión manual en lote de órdenes a Venta (Tiendanube y MercadoLibre)

**Purpose**: Validar la calidad de los requisitos de la spec antes de pasar a tasks/implement — foco en guardrails, idempotencia/no-duplicación, UX del modal de resultados y consistencia entre Tiendanube y MercadoLibre.
**Created**: 2026-07-31
**Feature**: [spec.md](../spec.md)

**Note**: Generado por `/speckit-checklist`. Ítems evalúan la calidad de los requisitos (completitud, claridad, consistencia, medibilidad), no el comportamiento del sistema ya implementado.

## Guardrails

- [x] CHK001 - ¿Está especificado qué guardrails deben chequearse antes de procesar cualquier orden del lote (función avanzada, modo solo lectura)? [Completeness, Spec §FR-005]
- [x] CHK002 - ¿Está definido el comportamiento exacto cuando un guardrail bloquea la operación (cero órdenes tocadas + motivo informado)? [Clarity, Spec §FR-005]
- [x] CHK003 - ¿Se especifica que los mismos guardrails aplican de forma idéntica a Tiendanube y a MercadoLibre, sin divergencias? [Consistency, Spec §FR-012]
- [ ] CHK004 - ¿Está definido si el guardrail de "modo solo lectura" se evalúa contra la conexión de la integración específica o contra un estado global, para el caso de que ambas integraciones tengan configuraciones independientes? [Ambiguity, Spec §FR-005]

## Idempotencia y no-duplicación

- [x] CHK005 - ¿Está especificado el criterio para evitar que dos conversiones concurrentes generen dos Ventas para la misma orden? [Completeness, Spec §FR-006]
- [x] CHK006 - ¿Se define el comportamiento esperado cuando el batch manual y una sincronización automática compiten por la misma orden (cuál gana, qué le pasa a la que pierde)? [Coverage, Spec Edge Cases]
- [x] CHK007 - ¿Está definido que re-ejecutar el botón sobre un conjunto ya convertido no reprocesa ni afecta esas órdenes (efecto neto idempotente)? [Clarity, Spec §FR-009]
- [x] CHK008 - ¿Es medible/verificable el criterio de "ninguna Venta duplicada" sin conocer detalles de implementación (lock, transacción)? [Measurability, Spec §SC-003]

## UX del modal de resultados

- [x] CHK009 - ¿Está especificado el contenido exacto del resumen mostrado al finalizar el lote (total, convertidas, fallidas)? [Completeness, Spec §FR-007]
- [x] CHK010 - ¿Está especificado qué información se muestra por cada orden fallida (identificador, motivo, explicación)? [Completeness, Spec §FR-008]
- [x] CHK011 - ¿Se define el comportamiento del modal/resumen cuando no hay ninguna orden fallida (se omite la tabla de detalle vs. se muestra vacía)? [Clarity, Spec §User Story 2 Acceptance Scenario 2]
- [x] CHK012 - ¿Se especifica que el resultado se muestra sin recargar ni navegar fuera del listado? [Completeness, Spec §FR-010]
- [x] CHK013 - ¿Está definido el estado del botón mientras el procesamiento está en curso (deshabilitado, evitando doble disparo)? [Coverage, Spec Edge Cases]
- [ ] CHK014 - ¿Se especifica si el usuario recibe alguna indicación de progreso o espera (más allá del estado deshabilitado del botón) durante un lote grande, o se asume espera silenciosa? [Gap, Spec Edge Cases]

## Consistencia Tiendanube / MercadoLibre

- [x] CHK015 - ¿Los requisitos funcionales declaran explícitamente que el comportamiento (botón, guardrails, resumen, detalle) debe ser equivalente entre ambas integraciones? [Consistency, Spec §FR-012]
- [x] CHK016 - ¿Está claro que cada integración opera únicamente sobre sus propias órdenes, sin mezclar resultados de Tiendanube y MercadoLibre en un mismo resumen? [Clarity, Spec §Key Entities]
- [ ] CHK017 - ¿Se documenta si ambas integraciones comparten exactamente el mismo conjunto de estados/motivos de falla, o si existen motivos exclusivos de una integración que deban reflejarse de forma distinta en el modal? [Ambiguity, Spec §Key Entities]

## Cobertura de escenarios y bordes

- [x] CHK018 - ¿Está definido el comportamiento cuando no hay ninguna orden en estado "Lista para convertir" al momento de ejecutar el batch? [Edge Case, Spec Edge Cases]
- [x] CHK019 - ¿Está definido que una falla puntual dentro del lote no aborta ni revierte el procesamiento de las demás órdenes? [Coverage, Spec §FR-004]
- [x] CHK020 - ¿Se especifica que las órdenes en otros estados (Pendiente de pago, Requiere atención, Convertida, Cancelada) quedan explícitamente fuera del conteo del batch? [Completeness, Spec §FR-009]
- [ ] CHK021 - ¿Se define un límite superior razonable de tamaño de lote (o la ausencia deliberada de límite) más allá de la suposición de "volumen acotado" en Assumptions? [Gap, Spec §Assumptions]

## Notes

- CHK004, CHK014, CHK017 y CHK021 quedan como observaciones abiertas de bajo impacto — no bloquean el avance a `/speckit-tasks` (se resuelven con defaults razonables durante el diseño/implementación: guardrail evaluado contra la conexión de cada integración por separado, sin indicador de progreso adicional al deshabilitar el botón, motivos de falla ya diferenciados por integración vía enums separados existentes, sin límite artificial de tamaño de lote).
