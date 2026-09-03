# Data Model: Cada línea del comprobante es un ajuste independiente en la NC/ND

**Feature**: 096-lineas-independientes-ncnd | **Date**: 2026-09-03

## Cambio de esquema

### `nota_credito_debito_items` — dos columnas nuevas, nullable

| Columna | Tipo | Regla |
| --- | --- | --- |
| `venta_item_id` | `unsignedBigInteger`, nullable, FK → `venta_items.id`, `nullOnDelete()` | Se llena SOLO cuando la nota es de Venta (`NotaCreditoDebito.venta_id` no nulo) y el ítem tiene un producto asociado. |
| `compra_item_id` | `unsignedBigInteger`, nullable, FK → `compra_items.id`, `nullOnDelete()` | Se llena SOLO cuando la nota es de Compra. Mutuamente excluyente con `venta_item_id` — igual que `NotaCreditoDebito.venta_id`/`.compra_id` hoy. |

**Por qué nullable y no NOT NULL con backfill**: las ~860 `NotaCreditoDebitoItem` ya existentes no
tienen forma de reconstruir a qué línea de origen correspondió cada una (spec, Assumptions) — no se
inventa un valor. Los ítems de notas con "afecta stock = No" (spec 095, FR-013) tampoco tienen línea
de origen que referenciar (no hay ítems en el comprobante que ajusten, sólo cabecera).

**Por qué `nullOnDelete()`**: si la línea de origen del comprobante se llegara a borrar (caso raro,
pero las líneas de Venta/Compra son borrables en edición), no debe arrastrar el borrado de la nota ya
emitida — la nota es un documento fiscal independiente una vez creada.

### Regla derivada: pendiente por producto en un comprobante (reemplaza la de spec 045)

```
Para un producto_id P dentro de un comprobante C (Venta o Compra):

  notasDeP = NotaCreditoDebitoItem de notas no eliminadas de C, con producto_id = P

  SI notasDeP.some(NO tiene venta_item_id NI compra_item_id) → MODO AGREGADO (fallback)
    // Basta con que UNA nota (vieja) no tenga referencia — aunque otra sí la tenga — para
    // quedarse en agregado: mezclar modos con la vieja todavía activa contaría mal el pendiente.
    pendiente(P) = SUM(cantidad de líneas de C con producto_id = P)
                   − SUM(cantidad de notasDeP, excluyendo la nota en edición)

  SI NO (ninguna nota de P carece de referencia — sin notas todavía, o todas ya la traen) → MODO POR LÍNEA
    para cada línea L de C con producto_id = P:
      pendiente(L) = L.cantidad
                     − SUM(cantidad de notasDeP que referencian L.id, excluyendo la nota en edición)
```

El modo se decide **por producto dentro de un comprobante**, no global: dos productos distintos del
mismo comprobante pueden estar en modos distintos simultáneamente (uno ya tiene una nota nueva con
referencia de línea, el otro todavía no).

## Entidades afectadas (sin tabla nueva)

- **VentaItem / CompraItem**: sin cambios de esquema. Pasan a ser el sujeto directo del cálculo de
  pendiente (antes era `producto_id` agregado).
- **NotaCreditoDebitoItem**: gana `venta_item_id`/`compra_item_id` (ver arriba). El resto de sus
  columnas (`producto_id`, `cantidad`, `precio`, `descuento_pct`, `iva_pct`, `origen`) no cambia.

## Contrato interno: `itemsDisponibles()` — forma de retorno

Cada elemento del array gana un campo:

```
{
  producto_id, descripcion, pendiente, precio, descuento_pct, iva_pct,   // igual que hoy
  item_origen_id,   // int — el id de la VentaItem/CompraItem que esta fila representa
}
```

Cuando el comprobante repite un producto, `itemsDisponibles()` devuelve **una fila por línea**
(mismo `producto_id`, distinto `item_origen_id`, distinto `precio`/`descuento_pct` según corresponda
a esa línea), en vez de una sola fila fusionada.
