# Análisis de consistencia — spec 099

Chequeo cruzado entre `spec.md`, `plan.md`, `checklists/requirements.md` y `tasks.md`.
**Los hallazgos ya están corregidos.**

**Fecha**: 2026-09-03

---

## Cobertura

| Bloque | Requisitos | Tareas |
|---|---|---|
| Reproducir el defecto | — | T001, T002 |
| Decisión del modo | FR-001, FR-002, FR-003 | T003–T006 |
| Los dos requests | FR-004 | T007–T009 |
| No debilitar el tope | FR-008, FR-009 | T010–T012 |
| Que el usuario entienda | FR-005, FR-006, FR-007 | T013, T014 |
| Verificación real | SC-001 … SC-004 | T015–T018 |

Los 9 requisitos tienen tarea. Los dos que definen seguridad (FR-003, FR-009) tienen test propio.

---

## Hallazgos y correcciones

### H1 — El fallback agregado es la norma, no la excepción (severidad: alta) ✅ corregido

La spec presentaba el modo por línea como el camino principal y el agregado como un *fallback* para
casos históricos. **Medido en producción, es al revés:**

| Items de nota | Cantidad |
|---|---|
| **Sin** referencia de línea | **723** |
| Con referencia de línea | **8** |

Y de esos 8, sólo **2 son de compra** (notas 882 y 883, creadas hoy con la spec 096 recién
deployada). Los otros 6 son de venta.

O sea: hoy el 99% de las notas usa el camino agregado, y el fix sólo cambia el comportamiento de las
notas nuevas. Eso es **bueno** —el riesgo de regresión es mínimo— pero invalida la lectura de que
"todo el flujo ya está migrado y sólo falta la validación": lo que está migrado es el *código*, no
los *datos*.

**Corrección**: agregada la sección "Cuánto se usa cada modo" a la spec, con los números, y
reformulado FR-002 para que el modo agregado quede como **camino normal para los datos existentes**
y no como excepción residual.

### H2 — SC-004 prometía más de lo que el fix entrega (severidad: media) ✅ corregido

SC-004 decía *"las 20 compras mensuales de ML dejan de trabarse"*. **No es cierto para las 20.**

El modo por línea se activa sólo cuando la nota existente de ese producto trae `compra_item_id`. De
las 20 compras, sólo las que reciban su **primera nota desde ahora** entran en modo por línea; las
que ya tengan notas viejas sin referencia siguen en el agregado y **van a seguir trabándose**.

**Corrección**: SC-004 reescrito a *"la compra 2478 y toda compra cuya primera nota se emita desde
este cambio"*, y agregado a los casos de borde el de una compra con nota vieja sin referencia.

Esto es una limitación heredada del diseño dual de la spec 096, no un defecto de esta spec — pero
prometerlo sin la salvedad habría hecho que el cliente reporte el mismo problema el mes que viene.

### H3 — Faltaba el caso de la línea negativa aislada (severidad: media) ✅ corregido

T012 verificaba que la línea negativa "se valida sola", pero ninguna tarea cubría el caso de emitir
una nota **sobre** la línea negativa. Es raro pero posible: `pendienteDeLinea()` devolvería −1, y una
cantidad positiva contra un pendiente negativo es siempre mayor, así que quedaría rechazada.

**Corrección**: explicitado en T012 que una línea de cantidad negativa **no se ofrece para ajustar**
(`itemsDisponibles()` ya filtra `pendiente > 0`) y que la validación la rechaza, coherente con la
pantalla.

### H4 — El mensaje de error podía filtrar el importe de otra línea (severidad: baja) ✅ corregido

FR-005 dice que el mensaje nombra la línea por su importe. Si el `item_origen_id` es de **otro
comprobante** (FR-003), el modo cae al agregado — pero el plan no decía qué mensaje mostrar ahí.
Mostrar el importe de una línea ajena sería filtrar un dato de otro comprobante.

**Corrección**: el plan aclara que el mensaje con importe **sólo** aparece cuando la línea pertenece
a este comprobante; en cualquier otro caso va el texto agregado de siempre.

---

## Consistencias verificadas

- **El diagnóstico está medido, no razonado**: `itemsDisponibles()` → línea 12022 con pendiente 1;
  `pendiente(100000)` → 0. Ejecutado en producción, y citado igual en spec, plan y checklist.
- **El alcance excluye Ventas** en spec, plan y tasks, siempre con la misma razón (decisión del
  usuario) y anotado como brecha conocida, no como olvido.
- **FR-009 y SC-002 aparecen enlazados** en spec, checklist y T010, con el mismo argumento: los dos
  caminos hacen que la compra 2478 deje de dar error, y sólo uno es el correcto.
- **No se toca `pendiente()`**: ninguna tarea lo modifica, y las tres piezas de la spec 096
  (`pendienteDeLinea`, `productoEnModoPorLinea`, `itemsDisponibles`) quedan intactas.

---

## Riesgos vivos

1. **T010 es la tarea crítica.** "Arreglé el bug" y "rompí la validación" producen el mismo síntoma
   visible: la compra 2478 deja de dar error. Sólo T010 los distingue.
2. **El fix no alcanza a las compras con notas viejas** (H2). Es esperado y está documentado, pero es
   lo que el cliente puede volver a reportar.
3. **La duplicación entre los dos requests queda viva.** Se decidió no unificarla en esta spec para
   no ampliar la superficie sobre una validación fiscal; si mañana se toca una sola, vuelven a
   divergir — que es exactamente el origen de este bug.

---

## Veredicto

**Listo para implementar.** Un hallazgo alto, dos medios y uno bajo, los cuatro corregidos. Sin
requisitos huérfanos ni contradicciones entre artefactos.

El hallazgo alto (H1) no cambia el fix, pero sí la expectativa: esto arregla las notas **de acá en
adelante**, no las que ya tienen historia con el modo viejo.
