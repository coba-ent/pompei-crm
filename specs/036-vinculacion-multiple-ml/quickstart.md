# Quickstart: Vinculación múltiple Producto ↔ Publicaciones (ML y Tiendanube)

## Prerequisitos

- Migraciones corridas: `php artisan migrate` (elimina el índice único sobre `producto_id` en
  `ml_publicacion_producto` y `tn_variante_producto`).
- Cuenta de Mercado Libre y/o conexión de Tiendanube conectadas (para el escenario 1); para los
  escenarios 2 y 3 alcanza con datos locales, sin llamar a las APIs reales.

## Escenario 1 — Vinculación automática crea todos los vínculos (User Story 1)

1. Con el catálogo real conectado (o un fake/mock del cliente HTTP en tests), asegurarse de tener 2
   publicaciones de ML activas con el mismo SKU numérico, correspondiente a un Producto existente sin
   vínculo previo.
2. Correr la Vinculación Automática de Mercado Libre (botón en el CRM, o
   `App\Services\MercadoLibre\VinculadorAutomatico::ejecutar()`).
3. **Resultado esperado**: ambas publicaciones quedan como filas en `ml_publicacion_producto`
   apuntando al mismo `producto_id`; el resumen del resultado las cuenta como "vinculadas", no
   aparece ninguna con motivo `ya_vinculado` por ese producto.
4. Repetir el mismo escenario para Tiendanube (`App\Services\Tiendanube\VinculadorAutomatico`) con 2
   variantes activas del mismo SKU.

## Escenario 2 — El stock se propaga a todas las publicaciones vinculadas (User Story 2)

1. Vincular manualmente (o insertar directo en tests) un Producto a 2 filas de
   `ml_publicacion_producto`.
2. Registrar un `MovimientoStock` que cambie la disponibilidad de ese Producto en el depósito
   efectivo de ML.
3. **Resultado esperado**: ambas filas de `ml_publicacion_producto` quedan con `stock_pendiente = true`.
4. Correr `SincronizadorStock::ejecutar()`.
5. **Resultado esperado**: ambas filas quedan `stock_pendiente = false`, `stock_sincronizado_en`
   seteado, y (si se testea con `Http::fake()`) se verifica que la API de ML recibió una llamada
   `PUT /items/{id}` por cada una de las 2 publicaciones con la misma `available_quantity`.
6. Repetir para Tiendanube con `tn_variante_producto` y su sincronizador equivalente.

## Escenario 3 — El precio se propaga a todas las publicaciones vinculadas (User Story 3)

1. Vincular un Producto a 2 publicaciones/variantes.
2. Modificar el precio del Producto en la lista de precios configurada como la efectiva para ML (o
   Tiendanube).
3. **Resultado esperado**: `PrecioProductoObserver` despacha `enviarUno()` una vez por cada vínculo
   (verificable con `Http::fake()` esperando 2 llamadas, una por publicación/variante, con el mismo
   precio).

## Validación de éxito

- `tests/Feature/Integraciones/MercadoLibreVinculacionMultipleTest.php`,
  `TiendanubeVinculacionMultipleTest.php` en verde (vinculación automática crea N vínculos, sin
  rechazo por `ya_vinculado` cuando corresponde al mismo producto).
- Tests (nuevos o extendidos) de `MovimientoStockObserver`/`PrecioProductoObserver` verificando que
  con 2+ vínculos por producto, TODOS quedan marcados/despachados, no sólo el primero.
- Regresión: los tests existentes de vinculación automática (specs 021/023/024) y de sincronización de
  stock/precio (specs 013/016/018) siguen en verde — el cambio no debe romper el caso 1:1 existente
  (que sigue siendo válido, simplemente deja de ser el único caso soportado).
