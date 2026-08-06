# Contracts: Selector de Depósito en Ventas y Compras

No se agregan endpoints nuevos. Se extiende el payload/response de endpoints AJAX existentes.

## POST `/sales` (VentaController@store) — ya existente

**Request (agrega)**:
```json
{ "deposito_id": 2, "...": "resto de campos existentes sin cambios" }
```
`deposito_id` requerido, debe existir en `depositos` (no se valida `activo` a nivel Request — un
depósito inactivado después de elegido en el form ya no debería aparecer como opción en el select,
pero la validación de integridad referencial alcanza).

**Response**: sin cambios de forma (incluye el modelo `venta` serializado, que ahora trae `deposito_id`).

## PUT `/sales/{venta}` (VentaController@update) — ya existente

Mismo agregado de `deposito_id` requerido en el request. Response sin cambios de forma.

## POST `/purchases` (CompraController@store) — ya existente

**Request (agrega)**:
```json
{ "deposito_id": 2, "nro_comprobante": "0002-00000015", "...": "resto de campos existentes sin cambios" }
```
`deposito_id` requerido, igual regla que Venta. `nro_comprobante` requerido (antes no viajaba en el
request — se calculaba en el servidor; ahora el servidor sólo lo sugiere como precarga y persiste lo
que llega).

## PUT `/purchases/{compra}` (CompraController@update) — ya existente

Ídem — `deposito_id` y `nro_comprobante` requeridos en el request.

## POST `/configuracion/ventas` (ConfiguracionVentasController@guardar) — ya existente

**Request (agrega)**:
```json
{ "deposito_id": 2, "deposito_compra_id": 3, "...": "resto de campos existentes sin cambios" }
```
Ambos opcionales (`nullable`), igual criterio que el resto de los campos de esa pantalla.

**Response**: sin cambios de forma.

## GET `/sales/create` y GET `/sales/{venta}/edit` (vistas) — ya existentes

La vista recibe además `depositos` (catálogo de activos, ya se pasa hoy en `index`/`show`, se agrega
también a `create`/`edit`) y `defaults.depositoId` (sólo en `create`, ver data-model.md).

## GET `/purchases/create` y GET `/purchases/{compra}/edit` (vistas) — ya existentes

Ídem, con `defaults.depositoId` resuelto contra `deposito_compra_id`. `create()` agrega además
`defaults.nroComprobanteSugerido` (el correlativo interno, ver data-model.md § Precarga del N° de
comprobante sugerido); `edit()` no agrega nada nuevo — el campo se precarga con
`$compra->nro_comprobante` como el resto de los campos de edición.
