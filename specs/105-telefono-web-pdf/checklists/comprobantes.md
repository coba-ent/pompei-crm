# Checklist de calidad de requisitos: comprobantes impresos y datos del emisor

**Purpose**: Validar que los requisitos de la spec 105 estén completos, claros y sin contradicciones
antes de implementar. Esto NO es un plan de pruebas: cada ítem pregunta por lo que el documento
**dice o no dice**, no por lo que el sistema hace.
**Created**: 2026-09-18
**Feature**: [spec.md](../spec.md)

**Foco pedido**: no romper ningún PDF existente; consistencia entre los 5 comprobantes; campos vacíos
que no impriman nada; ningún dato fiscal alterado.

## Completitud de los requisitos

- [x] CHK001 - ¿Está explícito **cuáles** son los comprobantes alcanzados, enumerados uno por uno en lugar de "los comprobantes"? [Completeness, Spec §FR-004]
- [x] CHK002 - ¿Está definido qué pasa cuando la ficha de empresa no existe todavía (sin ningún dato cargado)? [Completeness, Spec §Edge Cases]
- [x] CHK003 - ¿Está especificado el comportamiento cuando sólo uno de los dos datos está cargado? [Coverage, Spec §Edge Cases]
- [x] CHK004 - ¿Está documentado dónde se cargan y se corrigen estos datos, y no sólo dónde se muestran? [Completeness, Spec §FR-008]
- [x] CHK005 - ¿Está definido qué pasa con los negocios que ya tenían datos cargados antes del cambio? [Completeness, Spec §FR-010]
- [x] CHK006 - ¿Está especificado el **orden** en que los datos aparecen dentro del encabezado, y no sólo que aparecen? [Completeness, Spec §FR-011 + contracts/encabezado-emisor.md §Salida]
- [x] CHK007 - ¿Está documentado si los datos nuevos deben ser interactivos (link) o texto plano? [Gap cerrado, contracts §R2]

## Claridad y ausencia de ambigüedad

- [x] CHK008 - ¿Está resuelto a qué se refiere la palabra "dirección" del pedido original, en lugar de dejarla a interpretación? [Ambiguity, Spec §Contexto y recorte del pedido]
- [x] CHK009 - ¿Está dicho explícitamente que **no** se agrega un campo de dirección nuevo, para que nadie lo agregue por las dudas? [Clarity, Spec §Assumptions]
- [x] CHK010 - ¿Es medible "no se imprime nada" para un campo vacío, o admite la lectura de que se imprima la etiqueta sin valor? [Measurability, Spec §FR-005]
- [x] CHK011 - ¿Está definido qué significa "tal como fue cargado" (sin reformateo, sin normalización)? [Clarity, Spec §FR-007]
- [x] CHK012 - ¿Están los campos nombrados de forma consistente en toda la documentación (negocio vs. técnico), con la diferencia "página web"/`sitio_web` declarada como deliberada? [Consistency, Spec §Key Entities nota de terminología]

## Consistencia entre los cinco comprobantes

- [x] CHK013 - ¿Exige el requisito que los cinco comprobantes muestren **lo mismo**, o sólo que cada uno muestre los datos? [Consistency, Spec §FR-004]
- [x] CHK014 - ¿Está documentado **por qué mecanismo** queda garantizada esa consistencia, en lugar de depender de que quien implemente se acuerde de los cinco? [Traceability, research.md §Decisión 1]
- [x] CHK015 - ¿Está declarado explícitamente que no se admite un comprobante con encabezado distinto? [Consistency, contracts/encabezado-emisor.md §Invariante]
- [x] CHK016 - ¿Está registrado que la decisión de alcance (5 y no 2) fue deliberada, con su fundamento, para que no se lea como un exceso sobre el pedido? [Assumption, Spec §Contexto y recorte del pedido]

## No romper lo que ya funciona

- [x] CHK017 - ¿Hay un requisito explícito de que la ausencia de estos datos no puede impedir la generación de un comprobante? [Completeness, Spec §FR-006]
- [x] CHK018 - ¿Hay un criterio de éxito verificable de que los comprobantes que hoy se generan bien se siguen generando bien? [Acceptance Criteria, Spec §SC-003]
- [x] CHK019 - ¿Está especificado que el cambio de base de datos debe ser aditivo y reversible, dado que corre sobre producción con datos reales? [Completeness, plan.md §Constraints + research.md §Decisión 4]
- [x] CHK020 - ¿Está definido el estado en que quedan los registros existentes después de la migración? [Clarity, data-model.md §Migración]
- [x] CHK021 - ¿Está documentado qué archivos **no** deben tocarse, y qué significaría tener que tocarlos? [Assumption, plan.md §Source Code]

## Integridad fiscal (principio III de la constitución)

- [x] CHK022 - ¿Hay un requisito explícito de que ningún dato fiscal cambie como consecuencia de esta feature? [Completeness, Spec §FR-009]
- [x] CHK023 - ¿Están **enumerados** los datos fiscales que no deben cambiar (importes, tipo, numeración, CAE, QR), en lugar de decir "nada fiscal"? [Clarity, Spec §FR-009]
- [x] CHK024 - ¿Hay un criterio de éxito medible sobre la invariancia fiscal, y no sólo una afirmación en el cuerpo? [Measurability, Spec §SC-004]
- [x] CHK025 - ¿Está justificado por qué esta feature no entra en conflicto con el principio de corrección fiscal innegociable? [Traceability, plan.md §Constitution Check]

## Cobertura de escenarios y casos borde

- [x] CHK026 - ¿Están cubiertos los escenarios de valores largos que podrían romper la maquetación, con un requisito y no sólo como caso borde? [Edge Case, Spec §FR-012]
- [x] CHK027 - ¿Está contemplado que el teléfono pueda contener más de un número o aclaraciones? [Coverage, Spec §Edge Cases]
- [x] CHK028 - ¿Está contemplado que la página web se escriba de distintas formas (con/sin www, con/sin protocolo)? [Coverage, Spec §Edge Cases]
- [x] CHK029 - ¿Está cubierto el escenario de **borrar** datos previamente cargados, y no sólo el de cargarlos? [Coverage, Spec §User Story 2 escenario 3]
- [x] CHK030 - ¿Está cubierto que guardar un campo nuevo no pise los datos ya existentes de la ficha? [Coverage, Spec §User Story 2 escenario 4]

## Requisitos no funcionales

- [x] CHK031 - ¿Está declarado si la feature tiene objetivos propios de performance, o se justifica explícitamente que no los tenga? [Completeness, plan.md §Performance Goals]
- [x] CHK032 - ¿Está evaluada la dimensión de privacidad de estos datos, aunque la conclusión sea que no requieren tratamiento especial? [Non-Functional, Spec §Assumptions]
- [x] CHK033 - ¿Están identificadas las reglas de diseño obligatorias del proyecto que aplican y las que no, con su razón? [Traceability, research.md §Decisión 5]
- [x] CHK034 - ¿Está definida la estrategia de verificación, dado que los tests de PDF existentes no pueden afirmar sobre el contenido? [Completeness, research.md §Decisión 3]

## Dependencias y supuestos

- [x] CHK035 - ¿Está validado el supuesto de que los cinco comprobantes ya reciben los datos de la empresa? [Assumption, research.md §Decisión 1 — verificado contra el código]
- [x] CHK036 - ¿Están documentados los supuestos sobre formato libre de los datos, con su fundamento? [Assumption, Spec §Assumptions]
- [x] CHK037 - ¿Está señalada la obligación de actualizar la documentación de dominio antes de continuar? [Traceability, plan.md §Constitution Check principio I]

## Conflictos detectados y resueltos

- [x] CHK038 - ¿Se detectó y registró la discrepancia entre lo que la documentación de dominio decía (2 PDFs) y la realidad del código (5)? [Conflict, data-model.md §Actualización obligatoria]
- [x] CHK039 - ¿Se detectó y registró la columna faltante en la documentación de dominio (`mail_contador`)? [Conflict, data-model.md §Actualización obligatoria]
- [x] CHK040 - ¿Se registró la imprecisión de la constitución sobre el nombre de la tabla (`empresa` vs `datos_empresa`), aclarando que no bloquea? [Conflict, plan.md §Constitution Check nota]

## Notas

Resultado: **40/40**. No quedaron ítems abiertos.

Revisión cruzada posterior (`/speckit-analyze`, 18/09/2026): se detectaron y corrigieron tres brechas
menores de trazabilidad, ya reflejadas arriba — el orden de los datos y la tolerancia a valores largos
vivían sólo en el contrato y ahora son requisitos explícitos (FR-011 y FR-012), y la diferencia de
nombre entre "página web" y `sitio_web` quedó declarada como deliberada en la spec.

Tres hallazgos de esta revisión ya quedaron incorporados a los artefactos y no requieren acción
posterior:

1. **CHK038 / CHK039** — la documentación de dominio estaba desactualizada en dos puntos
   (alcance del encabezado y columna faltante). Ambos se corrigieron en `docs/modelo_datos.md` y
   `docs/documentacion_principal_crm.md` como exige el principio I de la constitución.
2. **CHK040** — la constitución nombra una tabla `empresa` que en realidad se llama `datos_empresa`.
   Es una imprecisión de redacción de la constitución, no un conflicto de diseño; queda anotada en
   `plan.md` para una futura enmienda, sin bloquear esta feature.
3. **CHK007** — el contrato tuvo que decidir explícitamente que `sitio_web` **no** se convierte en un
   link: es un documento pensado para imprimirse, donde un `<a href>` no aporta nada.
