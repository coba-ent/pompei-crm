# Data Model: Percepciones/Impuestos Internos/Intereses funcionales en NC/ND

Sin cambios de esquema — se usa la columna `notas_credito_debito.impuestos` (json, nullable) ya
existente desde spec 039/045, nunca conectada a una UI hasta este spec.

## `notas_credito_debito.impuestos` (json)

Array de objetos, cada uno representando una fila de concepto agregada por el usuario:

| Campo | Tipo | Notas |
|---|---|---|
| tipo | string enum | `percepcion` \| `impuesto_interno` \| `interes` |
| concepto | string | Si `tipo=percepcion`: uno de las 27 percepciones del catálogo fijo (IVA Percepción, Ganancias, Sellos, IIBB × 24 jurisdicciones). Si `tipo` es `impuesto_interno`/`interes`: texto libre tipeado por el usuario. |
| monto | decimal | Importe de ese concepto, se suma al Total de la nota. |

Ejemplo:

```json
[
  { "tipo": "percepcion", "concepto": "IIBB Buenos Aires", "monto": 1250.50 },
  { "tipo": "interes", "concepto": "Interés por mora", "monto": 300 }
]
```

Filas sin `concepto` (usuario agregó la fila pero no eligió/tipeó nada) se descartan antes de
guardar — no llegan a persistirse (FR-008).

## Relación con `notas_credito_debito.monto`

`monto` (decimal(14,2), ya existente) sigue siendo el total final de la nota — ya incluye la suma de
`impuestos[].monto` (calculado client-side en `notas-credito-debito.js::totalActual()`, mismo
criterio que Ventas/Compras/Presupuestos). No hay un campo separado para "subtotal sin conceptos";
ese desglose sólo se muestra en pantalla (`#tot-*`), no se persiste aparte.
