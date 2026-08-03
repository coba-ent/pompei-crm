# Data Model: Evitar ventas duplicadas por reconversión de órdenes ML/Tiendanube

## Venta (`ventas`) — modificación

Columnas nuevas:

| Columna | Tipo | Null | Único | Notas |
|---|---|---|---|---|
| `ml_order_id` | string | sí | sí | Identificador del pedido de Mercado Libre que originó la Venta (`origen = 'mercadolibre'`). Se completa en el momento de la conversión (`ConversorOrdenAVenta::convertirBajoCandado`), a partir de `MercadoLibreOrden.ml_order_id`. |
| `tn_order_id` | string | sí | sí | Identificador del pedido de Tiendanube que originó la Venta (`origen = 'tiendanube'`). Idem, a partir de `TiendanubeOrden.tn_order_id`. |

Reglas:

- Para una Venta con `origen` distinto de `mercadolibre`/`tiendanube`, ambas columnas quedan `null`.
- Los índices únicos NO excluyen filas soft-deleted (`deleted_at` no nulo): una Venta borrada
  lógicamente sigue "reservando" su `ml_order_id`/`tn_order_id`, para que una reconversión del
  mismo pedido siga rechazándose (edge case del spec — no hay flujo de reconversión intencional
  en esta feature).
- Backfill (FR-010): las Ventas `mercadolibre`/`tiendanube` ya existentes se completan por
  comando artisan a partir de la orden vigente que las referencia (`MercadoLibreOrden`/
  `TiendanubeOrden` con `venta_id` = esa Venta). Las Ventas cuya orden de origen ya no existe
  (borrada antes de esta feature) quedan sin backfillear — ver Assumptions del spec.

No hay cambios de relación: `Venta` no pasa a tener una relación Eloquent nueva hacia
`MercadoLibreOrden`/`TiendanubeOrden` (esa relación ya existe en sentido inverso,
`MercadoLibreOrden::venta()` / `TiendanubeOrden::venta()`); las columnas nuevas son sólo un valor
plano usado para el chequeo de unicidad, no una FK.

## MercadoLibreOrden (`ml_ordenes`) — sin cambio de esquema

Se agrega comportamiento, no columnas: método `tieneVentaAsociada(): bool` (equivalente a
`!is_null($this->venta_id)`), consultado por el punto de borrado antes de eliminar la fila
(mismo patrón que `CuentaTesoreria::tieneOperaciones()`).

## TiendanubeOrden (`tn_ordenes`) — sin cambio de esquema

Mismo agregado que `MercadoLibreOrden`: `tieneVentaAsociada(): bool`.

## Estado antes / después de una conversión (sin cambios de máquina de estados)

`estado_conversion` de la orden (`PendientePago → Lista → Convertida` / `RequiereAtencion`) no
cambia con esta feature. Lo único nuevo es un motivo de rechazo adicional, evaluado junto con los
ya existentes en `convertirBajoCandado()`: "el pedido de origen ya tiene una Venta asociada"
(por búsqueda en `ventas.ml_order_id`/`tn_order_id`), además del ya existente "esta orden ya
tiene una Venta asociada" (por `orden.venta_id`).
