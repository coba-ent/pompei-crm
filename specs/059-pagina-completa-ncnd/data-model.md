# Data Model: Página completa de NC/ND

Sin cambios de esquema — todos los campos ya existen desde spec 057
(`notas_credito_debito.nro_comprobante`/`nota_ajustada_id`, `nota_credito_debito_items.descuento_pct`/
`iva_pct`, `producto_id` nullable). Este spec es exclusivamente de UI/routing.

## Datos que la página completa necesita del controller (Crear)

Vía query string (pasados por el modal de paso 1, ver research.md §3) + datos ya disponibles del
comprobante de origen (`$venta`/`$compra`, cargados normalmente por route model binding):

- `tipo` (`credito`|`debito`)
- `documento_ajusta` (id del comprobante original o de otra NC/ND — hoy sólo el original, US4 sigue
  pendiente)
- `afecta_stock` (`0`|`1`)
- `deposito_id` (si `afecta_stock=1`)
- `mes_imputacion`

## Datos que la página completa necesita del controller (Editar)

`$notaCreditoDebito->load('items.producto')` — mismo shape que ya arma
`window.VentaDetalleData.notas`/`window.CompraDetalleData.notas` en spec 057, pero resuelto
server-side en el controller de la página en vez de en el JSON global de detalle.
