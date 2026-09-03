# Cálculo y Casos Límite de Descuentos Checklist: Bonificación efectiva por línea con Descuento General

**Purpose**: Validar que la especificación (spec.md, plan.md, research.md, data-model.md, contracts/)
deja sin ambigüedad la corrección matemática del cálculo, la cobertura de casos límite de
descuentos, y la garantía explícita de que el total ya correcto no cambia de valor.
**Created**: 2026-09-03
**Feature**: [spec.md](../spec.md)

**Note**: Generado por `/speckit-checklist`. Foco: corrección del cálculo, cobertura de casos
límite de descuentos, no-regresión del total ya correcto (input del usuario).

## Correctitud del Cálculo — Composición de Descuentos

- [x] CHK001 - ¿La especificación define explícitamente que el descuento de línea y el Descuento General se componen multiplicativamente (no se suman) para las 4 combinaciones posibles (sólo línea, sólo general, ambos, ninguno)? [Completeness, Spec §Assumptions]
- [x] CHK002 - ¿La fórmula de composición está anclada a un ejemplo numérico verificable (no sólo descrita en palabras) que un lector pueda reproducir a mano? [Clarity, Spec §Assumptions, research.md Decisión 1]
- [x] CHK003 - ¿Se especifica, para el modo "monto fijo" ($) de Descuento General, de dónde sale el porcentaje efectivo que se aplica a cada línea, dado que el campo `descuento_general_pct` de cabecera queda `null` en ese modo? [Clarity, Spec §Assumptions, research.md Decisión 1]
- [x] CHK004 - ¿Es coherente, entre spec.md (User Story 1, Acceptance Scenario 2) y research.md (Decisión 1), la fuente de datos elegida para derivar el porcentaje efectivo (columnas ya persistidas vs. recalcular desde la cabecera)? [Consistency]

## Correctitud del Cálculo — No Regresión del Total

- [x] CHK005 - ¿La especificación fija un requisito explícito y verificable de que el total final (a pie de página y al guardar) no cambia de valor como resultado de esta feature? [Measurability, Spec §FR-002, §SC-003]
- [x] CHK006 - ¿Se especifica de dónde debe salir el cálculo del "factor" que se usa en el render de cada fila, para evitar que exista una segunda fórmula que pueda divergir de la que ya produce el total correcto? [Clarity, Spec §FR-002, research.md Decisión 3]
- [x] CHK007 - ¿Está definida una tolerancia numérica explícita (y su justificación) para la comparación entre la suma de Subtotales de fila y el total de pie de página, en vez de exigir una coincidencia exacta no realista dado el redondeo por línea? [Measurability, Spec §Edge Cases, research.md Decisión 4]

## Cobertura de Casos Límite de Descuentos

- [x] CHK008 - ¿Están definidos los requisitos de comportamiento cuando el precio unitario o la cantidad de una línea son cero, para evitar división por cero tanto en pantalla como en el PDF? [Edge Case, Spec §Edge Cases, §FR-006]
- [x] CHK009 - ¿Está definido el comportamiento cuando el Descuento General es 100%? [Edge Case, Spec §Edge Cases]
- [x] CHK010 - ¿Está definido el comportamiento al alternar el Descuento General entre modo porcentaje y modo monto fijo a mitad de carga de un comprobante? [Edge Case, Spec §Edge Cases]
- [x] CHK011 - ¿Está cubierto el caso de un comprobante con un único ítem, donde el Descuento General recae íntegramente sobre esa línea? [Edge Case, Spec §Edge Cases]
- [x] CHK012 - ¿Está definido el comportamiento del cálculo para líneas de descripción libre sin producto de catálogo asociado (sin `producto_id`)? [Edge Case, Spec §Edge Cases]
- [x] CHK013 - ¿Está definido qué debe mostrar la columna "Bonif."/"%Bonif." cuando el porcentaje efectivo calculado da negativo o indefinido, más allá del caso ya cubierto de precio/cantidad cero? [Gap]

## Consistencia Entre los 4 Comprobantes (Presupuesto/Venta/Compra vs. NC/ND)

- [x] CHK014 - ¿La especificación deja inequívoco, con una única fuente de verdad (no repartida en varias secciones que puedan desalinearse), que NC/ND queda fuera del cálculo de porcentaje efectivo combinado? [Consistency, Spec §Clarifications, §FR-008]
- [x] CHK015 - ¿Están definidos requisitos de correctitud para el nuevo cálculo del importe de Descuento General en el PDF de NC/ND (FR-009), incluyendo de dónde sale el subtotal base sobre el que se calcula ese importe? [Completeness, Spec §FR-009, research.md Decisión 5]
- [x] CHK016 - ¿Se especifica qué debe pasar cuando la NC/ND no tiene Descuento General cargado (0% o vacío), para la nueva fila de totales que se agrega a su PDF? [Edge Case, Spec §User Story 3 Acceptance Scenario 3]

## Medibilidad de Éxito

- [x] CHK017 - ¿Cada Success Criteria (SC-001 a SC-005) es verificable sin conocer detalles de implementación, y con una condición de "100% de los casos probados" o tolerancia explícita en vez de un umbral vago? [Measurability, Spec §Success Criteria]
- [x] CHK018 - ¿Existe un criterio de éxito específico para NC/ND separado del de Presupuesto/Venta/Compra, dado que su comportamiento correcto es distinto (no combinar) en vez de asumir el mismo criterio para las 4 pantallas? [Coverage, Spec §SC-005]

## Notes

- Ejecutar esta validación sobre spec.md + research.md + data-model.md + contracts/ ya escritos,
  antes de `/speckit-tasks` — es una revisión de calidad de los requisitos, no de código (no
  existe código de esta feature todavía; se descartó intencionalmente código exploratorio previo
  a la especificación, ver bitácora de la conversación).
- Todos los ítems marcados [Gap] indican una omisión real detectada en el pase de validación, no
  una plantilla sin completar — revisar y decidir si se cierra con una edición a spec.md o se
  documenta como fuera de alcance.
