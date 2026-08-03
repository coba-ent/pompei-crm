# Quickstart: validar que no se dupliquen ventas por reconversión ML/Tiendanube

## Prerrequisitos

- Migraciones de esta feature aplicadas (`php artisan migrate`).
- Comando de backfill corrido una vez en el ambiente con datos (`php artisan
  ventas:backfill-referencia-pedido`).
- Al menos una cuenta de Mercado Libre o Tiendanube conectada (o datos de prueba equivalentes a
  `MercadoLibreOrden`/`TiendanubeOrden` + `Venta` vinculada).

## Escenario 1 — Reconversión tras borrado y resincronización (User Story 1, SC-001)

1. Tomar una `Venta` existente con `origen = 'mercadolibre'` y su `MercadoLibreOrden` asociada
   (`orden.venta_id === venta.id`). Anotar `orden.ml_order_id`.
2. Borrar la fila de `MercadoLibreOrden` (simulando el borrado accidental) — usando el guard
   nuevo, esto debe rechazarse mientras `venta_id` no sea null (ver Escenario 3). Para simular el
   caso "ya se borró antes del fix" en un entorno de test, desvincular primero (`venta_id = null`)
   o truncar el guard vía factory que la recrea desde cero, y luego eliminarla.
3. Recrear una `MercadoLibreOrden` nueva con el mismo `ml_order_id` (simulando lo que hace
   `SincronizadorOrdenes::procesarOrden()` al no encontrar la fila) y estado `Lista` para
   conversión.
4. Intentar convertirla (`ConversorOrdenAVenta::convertir()`).
5. **Esperado**: `ok: false`, mensaje indicando que el pedido ya tiene una Venta asociada;
   `Venta::count()` no incrementa; no se registra un cobro nuevo en Tesorería ni un movimiento de
   stock nuevo.
6. Repetir 1-5 con Tiendanube (`TiendanubeOrden`, `tn_order_id`, `origen = 'tiendanube'`).

## Escenario 2 — Conversión normal de un pedido nunca convertido (User Story 1, caso feliz)

1. Tomar una `MercadoLibreOrden` (o `TiendanubeOrden`) en estado `Lista`, sin `venta_id`, cuyo
   `ml_order_id`/`tn_order_id` nunca generó una Venta.
2. Convertirla.
3. **Esperado**: se crea la `Venta` con normalidad y queda con `ml_order_id`/`tn_order_id`
   completado con el identificador del pedido.

## Escenario 3 — Bloqueo de borrado (User Story 2, SC-002)

1. Tomar una `MercadoLibreOrden` (o `TiendanubeOrden`) con `venta_id` no nulo.
2. Intentar eliminarla por el punto de borrado disponible (comando de mantenimiento / futura
   acción de UI que reutilice `tieneVentaAsociada()`).
3. **Esperado**: el borrado se rechaza con un mensaje indicando que hay que desvincular o
   eliminar la Venta asociada primero; la fila de la orden sigue existiendo.
4. Repetir con una orden sin `venta_id`: el borrado debe completarse sin restricciones.

## Verificación de éxito

- Los tres escenarios cubren SC-001, SC-002 y SC-003 del spec.
- No se requiere UI nueva para validar esto: alcanza con tests de feature (Pest/PHPUnit) sobre
  `ConversorOrdenAVenta` de ambas integraciones y sobre el guard de borrado de ambos modelos de
  orden — ver `tests/Feature/...` listado en `plan.md`.
