# Quickstart: validar la reevaluación automática de órdenes

Prerrequisitos: entorno local con MySQL/XAMPP corriendo, migraciones aplicadas, al menos un
producto del CRM existente.

## Escenario 1 — Evento-driven (User Story 1, ML)

1. Insertar una orden ML de prueba en `ml_ordenes` con `estado_conversion = requiere_atencion`,
   `motivo = publicacion_sin_vincular`, `venta_id = null`, y un `ml_orden_items` con
   `ml_item_id = 'MLA_TEST_001'` sin fila correspondiente en `ml_publicacion_producto`.
2. Desde la UI (Configuración > Funciones Avanzadas > Mercado Libre > vinculaciones) o vía
   `MercadoLibrePublicacionProducto::create(['ml_item_id' => 'MLA_TEST_001', 'producto_id' => $id])`,
   vincular esa publicación a un producto existente.
3. **Resultado esperado**: sin ninguna acción adicional, `ml_ordenes.estado_conversion` de la
   orden de prueba pasa a `lista` (o `convertida` si el canal tiene `creacion_automatica`
   activo), y `motivo`/`motivo_detalle` quedan `null` (salvo que exista otro motivo pendiente).

## Escenario 2 — Evento-driven (User Story 1, TiendaNube)

Análogo al Escenario 1, con `tn_ordenes`/`tn_orden_items`/`TiendanubeVarianteProducto` y
`motivo = variante_sin_vincular`.

## Escenario 3 — Desvinculación (Edge Case, FR-010)

1. Con una orden ML `lista` (no convertida) cuyo ítem depende de una publicación vinculada,
   eliminar esa vinculación (`MercadoLibrePublicacionProducto::find($id)->delete()` o desde la UI).
2. **Resultado esperado**: la orden vuelve a `requiere_atencion` con
   `motivo = publicacion_sin_vincular`.

## Escenario 4 — Red de seguridad on-view (User Story 2)

1. Provocar una desincronización sin pasar por el flujo normal de vinculación: crear la fila en
   `ml_publicacion_producto` directamente por SQL (sin pasar por el controlador/Observer) para
   una orden que sigue marcada `requiere_atencion` en `ml_ordenes`.
2. Abrir la vista de órdenes pendientes de MercadoLibre en el CRM (la que dispara
   `GET .../mercadolibre/ventas/datatable` por AJAX).
3. **Resultado esperado**: la respuesta del datatable ya refleja el estado corregido de esa orden
   (no aparece como `requiere_atencion / publicacion_sin_vincular`), sin haber recargado ni
   sincronizado.

## Validación de no regresión

- Una orden con `venta_id` seteado no cambia de estado en ninguno de los 4 escenarios (verificar
  `updated_at` sin cambios, o un `spy`/`Event::fake()` según el test).
- Una orden `cancelada` no cambia de estado en ninguno de los 4 escenarios.
- El tiempo de respuesta de `datatable()` con ~400 órdenes `requiere_atencion` (volumen real
  observado en producción) se mantiene dentro de lo esperable para una carga de DataTables server
  side (sin timeout, sin demora perceptible agregada — SC-003).
