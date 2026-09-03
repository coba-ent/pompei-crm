# Contrato: ítems disponibles por línea (no por producto agregado)

**Feature**: 096-lineas-independientes-ncnd | **Date**: 2026-09-03

## Dónde vive

No hay endpoint nuevo. Se amplía la forma de retorno de `itemsDisponibles()` (usado por
`items-disponibles` GET, ya existente desde spec 045) y del payload de guardado (`store`/`storeCompra`,
ya existentes).

## `GET .../items-disponibles` — forma de cada elemento

```
{
  producto_id,      // igual que hoy
  descripcion,      // igual que hoy
  pendiente,        // ahora calculado por línea (o agregado, en fallback — ver data-model.md)
  precio,           // el de ESA línea puntual, no el de la primera línea del producto
  descuento_pct,    // el de ESA línea puntual
  iva_pct,          // el de ESA línea puntual
  item_origen_id,   // NUEVO — id de venta_items/compra_items que esta fila representa
}
```

**Cambio de cardinalidad**: si el comprobante tiene el mismo producto en 3 líneas con cantidad
pendiente, el array trae 3 elementos con el mismo `producto_id` y distinto `item_origen_id` — antes
traía 1.

## Payload de guardado — `POST/PUT .../notas` (store/storeCompra/update)

`items[]` gana un campo opcional:

```
items: [
  {
    producto_id, cantidad, precio, descuento_pct, iva_pct,   // igual que hoy
    item_origen_id,   // NUEVO, opcional — si viene, se persiste en NotaCreditoDebitoItem
                       // (venta_item_id o compra_item_id según corresponda)
  }
]
```

Si `item_origen_id` no viene (formulario viejo, o línea agregada a mano por el usuario sin partir de
la precarga), la nota se guarda igual que hoy — sin esa referencia, y ese producto queda en modo
agregado (fallback) para el cálculo de pendiente de futuras notas, hasta que alguna nota sí la traiga.

## Reglas de precedencia

1. **Por línea sobre agregado**: en cuanto una nota trae `item_origen_id` para un producto de un
   comprobante, ese producto pasa a calcularse por línea de ahí en adelante (ver data-model.md,
   Decisión 2 de research.md).
2. **No se reordena ni se reetiqueta el historial**: notas ya guardadas sin la referencia siguen sin
   tenerla — no se completa retroactivamente.
3. **La edición de una NC/ND existente sigue sin depender del comprobante de origen** (spec 095,
   FR-011) — este contrato no cambia esa regla; sólo aplica al ALTA y a la reconstrucción de ítems
   disponibles.
