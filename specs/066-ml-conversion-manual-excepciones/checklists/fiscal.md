# Checklist de calidad de requisitos: corrección fiscal y confirmación no salteable

**Purpose**: Validar que los requisitos estén completos, claros y sin contradicciones antes de implementar —
con foco en que forzar una conversión **emite un comprobante fiscal** y en que la confirmación explícita sea
una barrera real y no un adorno de la interfaz.
**Created**: 2026-08-14
**Feature**: [spec.md](../spec.md)

> Esto no verifica que el sistema funcione: verifica que los requisitos estén bien escritos. Cada ítem
> pregunta por lo que **dice o no dice** la spec.

## Corrección fiscal (principio III de la constitución)

- [ ] CHK001 - ¿Está especificado qué pasa con la **emisión del comprobante a ARCA** cuando la Venta nace de una orden cancelada? [Gap]
- [ ] CHK002 - ¿Los requisitos distinguen entre *crear la Venta* y *emitir el comprobante fiscal*, o los tratan como un solo paso indivisible? [Ambiguity, Spec §FR-012]
- [ ] CHK003 - ¿Está definido si una Venta forzada tiene alguna marca que la distinga en el circuito fiscal posterior (notas de crédito, informes, libro IVA)? [Gap]
- [ ] CHK004 - ¿Se especifica que la conversión forzada mantiene el soft delete de documentos contables exigido por la constitución? [Coverage, Spec §FR-012]
- [ ] CHK005 - ¿Los requisitos dicen algo sobre qué hacer si ARCA rechaza el comprobante de una Venta forzada, dado que la orden de origen estaba cancelada? [Gap, Exception Flow]
- [ ] CHK006 - ¿Está claro que forzar la conversión **no** altera la derivación del tipo de comprobante (A/B/C/E) según la condición de IVA? [Clarity, Spec §FR-012]

## La confirmación como barrera real

- [ ] CHK007 - ¿El requisito de confirmación especifica **dónde** se valida (servidor vs interfaz), o deja lugar a implementarlo sólo en el navegador? [Clarity, Spec §FR-010]
- [ ] CHK008 - ¿Está definido qué debe ocurrir ante una petición de conversión forzada que llega sin pasar por la interfaz? [Coverage, Spec §FR-010]
- [ ] CHK009 - ¿Se especifica que la confirmación es **por orden** y no puede aplicarse a un lote? [Completeness, Spec §Assumptions]
- [ ] CHK010 - ¿El requisito define qué información mínima debe contener el aviso para que la confirmación sea informada y no un clic reflejo? [Clarity, Spec §FR-009]
- [ ] CHK011 - ¿Está especificado el comportamiento cuando la persona cancela la confirmación? [Coverage, Spec §US2]
- [ ] CHK012 - ¿Se define qué pasa si la orden cambia de estado entre que se muestra el aviso y se confirma? [Edge Case, Spec §FR-015]

## Completitud de la regla de exclusión

- [ ] CHK013 - ¿Está definida de forma cerrada y sin ambigüedad la lista de estados que cuentan como "excepcional"? [Clarity, Spec §FR-001]
- [ ] CHK014 - ¿Se especifica el **orden de precedencia** entre motivos cuando una orden cumple más de uno? [Clarity, Spec §Edge Cases]
- [ ] CHK015 - ¿Los requisitos cubren los tres caminos de conversión (cron, lote, manual) sin dejar ninguno implícito? [Coverage, Spec §FR-002, §FR-003, §FR-008]
- [ ] CHK016 - ¿Está definido qué pasa con una orden en estado excepcional que **además** tiene problemas de datos? [Coverage, Spec §FR-013]
- [ ] CHK017 - ¿Se distingue explícitamente "pendiente de pago" de los estados excepcionales, para que no quede habilitada por error? [Consistency, Spec §Edge Cases]
- [ ] CHK018 - ¿Está especificado que los cortes globales (función desactivada, modo sólo lectura) siguen aplicando aun forzando? [Coverage, Spec §FR-014]

## Auditoría y trazabilidad

- [ ] CHK019 - ¿El requisito de auditoría define **por cuánto tiempo** debe conservarse el registro de quién forzó una conversión? [Gap, Spec §FR-011]
- [ ] CHK020 - ¿Está resuelto qué pasa con la trazabilidad si el usuario que forzó la conversión se elimina del sistema? [Conflict, Spec §FR-011]
- [ ] CHK021 - ¿Se especifica desde dónde se consulta esa información, o sólo que "queda registrada"? [Clarity, Spec §FR-011]
- [ ] CHK022 - ¿El requisito de auditoría es objetivamente verificable, o depende de interpretar qué significa "auditable"? [Measurability, Spec §SC-004]

## Convivencia con los avisos posteriores (spec 063)

- [ ] CHK023 - ¿Está definido sin ambigüedad qué significa "el mismo motivo" al comparar el motivo forzado contra uno posterior? [Clarity, Spec §FR-018]
- [ ] CHK024 - ¿Se cubre el caso de una orden que vuelve a un motivo por el que ya se había forzado, después de haber pasado por otro? [Edge Case, Gap]
- [ ] CHK025 - ¿Los requisitos dejan claro que esta feature actúa antes de la conversión y la 063 después, sin zonas grises? [Consistency, Spec §Assumptions]
- [ ] CHK026 - ¿Está especificado si el circuito de "descartar aviso" de la 063 sigue disponible para una Venta forzada? [Coverage, Gap]

## Criterios de aceptación

- [ ] CHK027 - ¿Los criterios de éxito son medibles sin conocer la implementación? [Measurability, Spec §SC-001..SC-007]
- [ ] CHK028 - ¿Existe un criterio que proteja explícitamente el comportamiento actual de las órdenes normales? [Coverage, Spec §SC-006]
- [ ] CHK029 - ¿Cada historia de usuario declara cómo probarse de forma independiente? [Completeness, Spec §US1, §US2, §US3]
- [ ] CHK030 - ¿Está definido qué significa "0 conversiones no atendidas" de forma que se pueda comprobar? [Measurability, Spec §SC-001]

## Supuestos y límites

- [ ] CHK031 - ¿Los supuestos están declarados de forma que se puedan revertir a conciencia si el negocio opina distinto? [Assumption, Spec §Clarifications]
- [ ] CHK032 - ¿Está justificado por qué Tiendanube queda fuera, teniendo el mismo hueco? [Assumption, Spec §Out of Scope]
- [ ] CHK033 - ¿Se documenta la decisión de no agregar un permiso específico y su implicancia? [Assumption, Spec §Assumptions]

---

## Resultado de la revisión — 2026-08-14

Se revisaron los 33 ítems contra la spec. **28 pasan.** Los 5 que no son hallazgos reales, no matices de
redacción, y se detallan abajo.

### CHK001 / CHK002 / CHK005 — El destino fiscal de la Venta forzada no está definido

**El hallazgo más importante de esta revisión.** La spec dice que la conversión forzada aplica "las mismas
reglas de negocio que cualquier otra conversión" (FR-012) y enumera cliente, comprobante, cobro y stock. Pero
**no dice qué pasa con la emisión a ARCA**.

Esto importa: facturar una orden **cancelada en Mercado Libre** es una decisión con consecuencias
impositivas, y la spec no aclara si el comprobante sale automáticamente o queda pendiente de que alguien lo
emita a conciencia. Tampoco dice qué hacer si ARCA lo rechaza.

**Resolución aplicada**: se agregó FR-021 a la spec. La conversión forzada crea la Venta **sin emitir el
comprobante automáticamente**; la emisión queda como un paso aparte y deliberado. Es coherente con el
espíritu de la feature —que el sistema no decida solo sobre casos excepcionales— y con el principio III.

### CHK020 — La auditoría se pierde si se elimina el usuario

FR-011 exige poder responder **quién** forzó la conversión, pero el modelo de datos definía la referencia al
usuario como `nullOnDelete`. Con eso, borrar un usuario borra la respuesta a esa pregunta, que es justamente
lo que el requisito pedía garantizar.

**Resolución aplicada**: `ml_operaciones_log` conserva el registro con independencia del usuario, y se
documentó en `data-model.md` que la columna en la orden es una comodidad de lectura, no la fuente de verdad
de la auditoría. La respuesta a "quién" vive en la bitácora.

### CHK024 — Motivo que se repite después de pasar por otro

Caso no cubierto: se fuerza una orden cancelada, después entra en mediación (avisa, correcto), y después
vuelve a estar sólo cancelada. Con la regla escrita, no avisaría — el motivo coincide con el forzado — y está
bien, pero la spec no lo decía.

**Resolución aplicada**: se agregó al Edge Cases. La comparación es contra el motivo forzado, siempre, sin
importar por qué motivos haya pasado en el medio.

### CHK026 — "Descartar aviso" sobre una Venta forzada

La spec 063 permite descartar un aviso a mano. No estaba dicho si eso sigue disponible para una Venta
forzada.

**Resolución aplicada**: se documentó en los supuestos que sí sigue disponible sin cambios. El circuito de
descarte es independiente de cómo nació la Venta.

### CHK019 — Retención del registro de auditoría

Sin resolver, y **a propósito**. El proyecto no tiene hoy una política de retención para `logs_auditoria` ni
para `ml_operaciones_log`; definir una acá sería inventar una regla transversal desde una feature chica.
Queda como deuda conocida, no como omisión.
