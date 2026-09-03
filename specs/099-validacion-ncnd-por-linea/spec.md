# La validación de NC/ND de compra valida por línea, igual que el resto del flujo

**Spec**: 099 | **Fecha**: 2026-09-03 | **Estado**: listo para planificar

## El problema

Al cargar la segunda nota de crédito sobre la compra **2478** (MERCADO LIBRE, $9.713.943), el
sistema rechaza el alta con:

> Renglón 1: La cantidad máxima disponible para ajustar es 0.

Pero esa compra **sí tiene** una línea sin ajustar, de $4.616.354.

### Por qué pasa

La compra tiene **3 líneas del mismo producto** (el comodín 99999, id 100000):

| Línea | Cantidad | Importe | ¿Ajustada? |
|---|---|---|---|
| 12021 | **−1** | −$343.008 | no |
| 12022 | +1 | $4.616.354 | **no** |
| 12023 | +1 | $2.167.324 | sí, por la nota 883 |

`StoreNotaCreditoDebitoRequest` llama a `AjustesPendientesNotaCreditoDebito::pendiente()`, que suma
**todas las líneas del producto**: `+1 − 1 + 1 = 1`. Como la nota 883 ya ajustó 1 unidad, el
pendiente da **0** y la validación corta.

La línea negativa —un ajuste de ML— se come una de las positivas.

### El sistema se contradice a sí mismo

Verificado ejecutando en producción sobre la compra 2478:

```
itemsDisponibles()  → línea 12022, pendiente 1, precio $4.616.354   ← la pantalla la ofrece
pendiente(100000)   → 0                                             ← la validación la rechaza
```

**La pantalla ofrece una línea que la validación después bloquea.** No es que falte una regla: hay
dos reglas distintas conviviendo, y el usuario queda en el medio.

## Por qué es un defecto y no una decisión de diseño

La spec 096 (commit `df614d85`) migró el ajuste de NC/ND a **unidad línea**, con un modo dual: por
línea cuando las notas existentes traen la referencia (`compra_items.id`), agregado como *fallback*
para las notas históricas que no la tienen.

Todo el flujo ya está migrado:

- `itemsDisponibles()` decide el modo con `productoEnModoPorLinea()`
- El frontend **ya manda** `item_origen_id` (`resources/js/notas-credito-debito.js`)
- El request **ya lo valida** (`items.*.item_origen_id`)
- El guardado **ya lo persiste**: la nota 883 tiene `compra_item_id = 12023`

**La validación es la única pieza que quedó en el modo viejo.** El dato para hacerlo bien ya está y
no se usa.

## Cuánto pasa

- **20 compras** tienen el mismo producto repetido con al menos una línea negativa
- **Una por mes, sin faltar ninguno desde octubre 2025**, todas de **MERCADO LIBRE**

Es la factura mensual de comisiones, que viene con positivos y negativos. El cliente lo reportó así:
*"además, todos los meses me pasa lo mismo"* — y los datos le dan la razón.

(Hay además 260 compras con producto repetido sin línea negativa. Ésas hoy no fallan, porque sin
negativos la suma agregada no pierde unidades.)

## Cuánto se usa cada modo (medido en producción)

| Items de nota | Cantidad |
|---|---|
| **Sin** referencia de línea → modo agregado | **723** |
| Con referencia de línea → modo por línea | **8** (y sólo **2** de compra) |

El modo agregado no es un residuo histórico: **es el camino normal para casi todos los datos que
hay hoy**. Las 2 notas de compra con referencia son la 882 y la 883, creadas el 03/09/2026 con la
spec 096 recién deployada.

Lo que está migrado es el **código**, no los **datos**. Esto tiene una consecuencia buena —el riesgo
de regresión es mínimo, el fix casi no toca lo existente— y una limitación, en SC-004.

## Qué se quiere

Que la validación use el **mismo criterio que la pantalla**: si el renglón identifica su línea de
origen, se valida contra esa línea.

## Qué NO se quiere

- **No se toca `pendiente()`**: sigue existiendo para el fallback agregado.
- **No se cambia el modo dual** que definió la spec 096.
- **No se toca Ventas.** El mismo defecto existe del lado de las ventas, pero el usuario acotó el
  alcance a Compras (decisión del 03/09/2026). Queda anotado como brecha conocida.
- **No se tocan las notas ya emitidas.**

## Requisitos funcionales

### La validación

- **FR-001** Si el renglón trae `item_origen_id`, el tope se calcula con `pendienteDeLinea()` sobre
  esa línea, no con `pendiente()` sobre el producto agregado.
- **FR-002** Si el renglón **no** trae `item_origen_id`, se mantiene el cálculo por producto
  agregado. **Es el camino que hoy usa el 99% de las notas** (723 items contra 8), no un caso
  residual: cubre las notas históricas y la carga manual, y no puede cambiar de comportamiento.
- **FR-003** La `item_origen_id` recibida tiene que pertenecer **a este comprobante**. Un id de otra
  compra no puede colarse para saltear el tope: en ese caso se ignora y se valida por el agregado,
  que es el criterio más restrictivo. No se lanza excepción — un dato viejo o manipulado tiene que
  quedar bloqueado, no producir un error 500.
- **FR-004** Vale igual en el alta (`StoreNotaCreditoDebitoRequest`) y en la edición
  (`UpdateNotaCreditoDebitoRequest`), que hoy repiten la misma validación. En la edición, la nota que
  se está editando se excluye del "ya ajustado", como ya hace hoy.
- **FR-005** El mensaje de error nombra la línea cuando el modo es por línea, para que el usuario
  entienda cuál renglón topó y no crea que es el producto entero.

### La pantalla

- **FR-006** Cuando un producto está en modo por línea, cada opción del selector muestra **su
  importe y su pendiente**, para poder distinguir líneas del mismo producto.
- **FR-007** Sin esto el fix desbloquea la operación pero el usuario elige a ciegas: la compra 2478
  muestra **3 renglones con la misma descripción** ("99999") y nada que los diferencie.

### La invariante

- **FR-008** Un comprobante **sin producto repetido** valida exactamente igual que hoy: con una sola
  línea por producto, el agregado y el por-línea dan el mismo número.
- **FR-009** No se puede ajustar más de lo facturado en ninguna línea. Es el propósito de esta
  validación y el fix no lo puede debilitar: es lo único que impide emitir una nota de crédito por
  más de lo comprado.

## Criterios de éxito

- **SC-001** En la compra 2478 se puede emitir la segunda nota de crédito sobre la línea 12022
  ($4.616.354), que nunca fue ajustada.
- **SC-002** En esa misma compra, intentar ajustar **de nuevo** la línea 12023 —ya cubierta por la
  nota 883— sigue siendo rechazado.
- **SC-003** La pantalla y la validación coinciden siempre: lo que `itemsDisponibles()` ofrece, la
  validación lo acepta.
- **SC-004** La compra 2478 y **toda compra cuya primera nota se emita desde este cambio** dejan de
  trabarse. ⚠️ **No las 20 automáticamente**: el modo por línea se activa sólo cuando las notas
  existentes de ese producto traen la referencia, así que una compra que ya tenga una nota vieja sin
  referencia sigue en el modo agregado y puede volver a trabarse. Es una limitación heredada del
  diseño dual de la spec 096, no de este fix.

## Casos de borde

| Caso | Tratamiento |
|---|---|
| Renglón sin `item_origen_id` | FR-002: cálculo agregado, como hoy |
| `item_origen_id` de otra compra | FR-003: se ignora y se valida por el agregado (más restrictivo) |
| Línea negativa (ajuste de ML) | Se valida sola, sin compensar contra las positivas |
| Ajustar **sobre** una línea negativa | No se ofrece (`itemsDisponibles()` filtra pendiente > 0) y la validación la rechaza: su pendiente es negativo |
| Compra con nota vieja sin referencia | Sigue en modo agregado y puede trabarse (SC-004) |
| Producto que aparece una sola vez | FR-008: idéntico a hoy |
| Nota histórica sin referencia de línea | El producto entero cae al fallback (spec 096, FR-006) |
| Edición de una nota existente | FR-004: la nota en edición se excluye del ya-ajustado |

## Riesgo

Esta validación es lo único que impide emitir una nota de crédito **por más de lo facturado**, sobre
un comprobante fiscal. Un error hacia el lado permisivo no da un cartel: deja pasar una nota mal
emitida. Por eso FR-009 y SC-002 son tan importantes como SC-001, y los tests tienen que cubrir el
rechazo con la misma fuerza que la aceptación.
