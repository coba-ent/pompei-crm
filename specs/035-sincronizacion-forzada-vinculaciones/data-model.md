# Data Model: Sincronización forzada y eliminación masiva de Vinculaciones

Esta feature **no agrega columnas ni tablas nuevas**. Reutiliza por completo el modelo de datos ya
existente de las integraciones Mercado Libre (spec 013) y Tiendanube (spec 018/024). Se documentan acá
sólo los campos relevantes para esta feature, a título de referencia — el detalle completo ya vive en
`docs/modelo_datos.md`.

## Entidades reutilizadas (sin cambios de esquema)

### `MercadoLibrePublicacionProducto` (vínculo Mercado Libre)

Campos relevantes ya existentes, usados por la sincronización forzada:

- `producto_id`, `ml_item_id` — identifican qué producto del CRM corresponde a qué publicación externa.
- `stock_pendiente`, `stock_sincronizado_en`, `stock_error`, `stock_error_en` — actualizados igual por
  `sincronizarTodos()` que por el flujo de pendientes existente.
- `precio_pendiente`, `precio_sincronizado_en`, `precio_error`, `precio_error_en` — actualizados igual
  por `sincronizarListaCompleta()` (sin cambios, método ya existente).

La sincronización forzada **no agrega campos nuevos** a este modelo: usa los mismos que ya escriben
`SincronizadorStock::sincronizar()` y `SincronizadorPrecios::enviarUno()`.

### `TiendanubeVarianteProducto` (vínculo Tiendanube)

Mismo criterio que arriba, con `tn_product_id`/`variant_id` como identificador externo en vez de
`ml_item_id`.

### `MercadoLibreConfiguracion` / `TiendanubeConexionRest` (configuración de la integración)

Campos ya existentes, leídos (no escritos) por esta feature:

- `deposito_id` (vía `depositoEfectivo()`) — depósito del que se calcula el stock a enviar.
- `lista_precio_id` (vía relación `listaPrecio()`) — lista de precios de la que se calcula el precio a
  enviar. Si es `null`, la sincronización forzada de precio no se ejecuta para esa integración (mismo
  corte que ya aplica `SincronizadorPrecios::ejecutar()` hoy: "No hay ninguna Lista de Precios
  configurada").
- `modo_solo_lectura`, y la fila de `FuncionAvanzada` con `clave` = `mercadolibre`/`tiendanube` — cortes
  previos ya usados por `verificarCortes()`.

## Sin nuevas entidades

No hay una entidad "Sincronización forzada" ni "Eliminación masiva" persistida — son acciones, no
datos. El resultado de cada corrida se refleja en los campos ya existentes de cada vínculo
(`*_sincronizado_en`, `*_error`) y en el log de operaciones ya existente (`MercadoLibreOperacionLog` /
`TiendanubeRestOperacionLog`), sin tabla nueva.
