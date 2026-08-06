# Data Model: Ver/Editar producto desde el detalle de Venta, Presupuesto y Compra

No se agregan tablas, columnas ni migraciones. Esta feature es puramente de UI/orquestación de
frontend sobre entidades y endpoints ya existentes.

## Entidades existentes reutilizadas (sin cambios de esquema)

### Producto
Ya modelado en `docs/modelo_datos.md`. Campos consumidos por los modales Ver/Editar (sin cambios):
`id`, `nombre`, `codigo`, `activo`, `tipo`, `tipo_producto_id`, `proveedor_id`, `stock_total`,
`costo`, `precio_venta`, `iva_venta_pct`, `iva_compra_pct`, `mostrar_en_ventas`,
`mostrar_en_compras`, `precios[]` (listas de precio), `descripcion`, `imagen_url`, `variantes[]`.

### Línea de Detalle (Concepto) — estructura en memoria del frontend
Ya existe como array `items` en `ventas.js` / `presupuestos.js` / `compras.js` (no persistido hasta
el submit del formulario padre). Estructura actual por ítem:

```
{
  producto_id: number | null,
  descripcion: string,
  cantidad: number,
  precio_unitario: number,
  descuento_pct: number | null,
  iva_pct: string  // '5' | '10.5' | '21' | '27' | 'exento' | 'no_gravado'
}
```

**Campo nuevo agregado a este objeto en memoria (no persistido en backend, sólo runtime)**:

- `_precioCatalogoOriginal: number` — precio de venta del producto en el catálogo al momento en
  que la fila fue agregada (o recotizada por última vez). Se usa exclusivamente para decidir si
  `precio_unitario` puede pisarse automáticamente tras una edición del producto (FR-006), sin
  alterar el contrato con el backend al guardar la Venta/Presupuesto/Compra (este campo no se
  envía en el payload de guardado — se filtra igual que hoy se arma el payload desde `items`).

## Evento de integración entre módulos (contrato JS interno)

`producto:actualizado` — CustomEvent disparado en `document` por `producto-modales.js` tras un
guardado exitoso del modal de edición.

```
detail: {
  producto: { id, nombre, precio_venta, ...resto de campos devueltos por productos.update }
}
```

Consumido por:
- `productos.js`: recarga el DataTable (`tabla.ajax.reload(null, false)`) y refresca stats, si la
  página actual es la de Productos.
- `ventas.js` / `presupuestos.js` / `compras.js`: recorre `items`, actualiza los que coincidan por
  `producto_id` (nombre siempre; `precio_unitario` sólo si coincide con `_precioCatalogoOriginal`),
  actualiza `_precioCatalogoOriginal` al nuevo precio, y llama `renderItems()`.

## Sin cambios de backend

`ProductoController@show` y `@update` (ya existentes vía `Route::resource`) se reutilizan tal cual.
No se agregan Form Requests ni validaciones nuevas.
