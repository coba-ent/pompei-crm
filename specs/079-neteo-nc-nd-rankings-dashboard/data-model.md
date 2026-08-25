# Data Model: Neteo de NC/ND en Rankings del Dashboard

Sin migraciones nuevas — todos los campos necesarios ya existen. Este documento describe cómo se
relacionan las entidades existentes para el cálculo, no un esquema nuevo.

## Entidades involucradas (ya existentes)

### `ventas` (modelo `Venta`)
Campos relevantes: `id`, `cliente_id`, `fecha_emision`, `total`, `deleted_at` (soft delete).

### `venta_items` (modelo `VentaItem`)
Campos relevantes: `venta_id`, `producto_id`, `cantidad`. Ya usado hoy por el Ranking de Productos
bruto (`rankings()` líneas 261-278).

### `notas_credito_debito` (modelo `NotaCreditoDebito`)
Campos relevantes: `id`, `venta_id` (FK a la venta que ajusta; puede ser `compra_id` para notas de
Compra, fuera de alcance de esta feature), `tipo` (`'credito'` | `'debito'`), `fecha_emision`,
`monto`, `deleted_at` (soft delete). Relación `items(): HasMany` → `NotaCreditoDebitoItem`.

### `nota_credito_debito_items` (modelo `NotaCreditoDebitoItem`)
Campos relevantes: `nota_credito_debito_id`, `producto_id` (nullable — ausente en notas globales sin
desglose), `cantidad` (decimal:3), `origen` (`venta_original` | `nuevo`).

## Relaciones para el cálculo

```
Venta (1) ──< NotaCreditoDebito (N)      [FK: notas_credito_debito.venta_id]
Venta (1) ──< VentaItem (N)              [FK: venta_items.venta_id]
NotaCreditoDebito (1) ──< NotaCreditoDebitoItem (N)   [FK: nota_credito_debito_items.nota_credito_debito_id]
NotaCreditoDebitoItem (N) >── Producto (1)            [FK: nota_credito_debito_items.producto_id, nullable]
```

## Regla de cálculo — Ranking de Clientes (por monto)

Para cada `cliente_id`, en el rango `[$desde, $hasta]`:

```
monto_neto(cliente) =
    SUM(ventas.total WHERE ventas.cliente_id = cliente
                        AND ventas.fecha_emision BETWEEN desde Y hasta)
  + SUM(notas.monto WHERE notas.tipo = 'debito'
                       AND notas.venta.cliente_id = cliente
                       AND notas.fecha_emision BETWEEN desde Y hasta)
  - SUM(notas.monto WHERE notas.tipo = 'credito'
                       AND notas.venta.cliente_id = cliente
                       AND notas.fecha_emision BETWEEN desde Y hasta)
```

Sin piso, sin techo (research.md Decisión 1). El Top 10 se calcula sobre este `monto_neto`, no sobre
el bruto (research.md Decisión 4).

## Regla de cálculo — Ranking de Productos (por cantidad)

Para cada `producto_id`, en el rango `[$desde, $hasta]`:

```
cantidad_neta(producto) =
    SUM(venta_items.cantidad WHERE venta_items.producto_id = producto
                                AND venta.fecha_emision BETWEEN desde Y hasta)
  + SUM(items.cantidad WHERE items.producto_id = producto
                          AND items.nota.tipo = 'debito'
                          AND items.nota.fecha_emision BETWEEN desde Y hasta)
  - SUM(items.cantidad WHERE items.producto_id = producto
                          AND items.nota.tipo = 'credito'
                          AND items.nota.fecha_emision BETWEEN desde Y hasta)
```

Notas sin ítems (`producto_id` nulo) no participan de este cálculo (research.md Decisión 3). Sin
piso, sin techo. Top 10 sobre `cantidad_neta`.

## Nota sobre "mismo período vs. período distinto"

Igual que `montoNetoQuery()`, el cálculo real se resuelve en dos componentes por las mismas razones
de performance/corrección (no es una simplificación conceptual, es la forma de expresar en SQL "la
nota se imputa al período de su venta de origen, esté esa venta dentro o fuera del rango filtrado"):

1. Ventas/ítems del propio rango ± notas cuya `fecha_emision` también cae en el rango.
2. Notas cuya `fecha_emision` cae en el rango pero cuya venta de origen quedó fuera del rango
   (ajuste "suelto" que igual pertenece a este período porque así se decidió imputarlo).

## Sin cambios de esquema

No se agregan columnas, tablas ni índices. Si el volumen de `notas_credito_debito` /
`nota_credito_debito_items` creciera al punto de requerir índices para estos joins, queda fuera de
alcance de esta feature (ya se resuelve con los índices de FK existentes: `venta_id`, `producto_id`).
