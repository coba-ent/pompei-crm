# Quickstart: Lista de Precios diferenciada para publicaciones Premium de Mercado Libre

**Spec**: [spec.md](spec.md) · **Data model**: [data-model.md](data-model.md) · **Contracts**: [contracts/rutas-internas.md](contracts/rutas-internas.md)

## Prerrequisitos

- Cuenta de Mercado Libre real conectada (`ml_cuentas.estado = conectada`), función avanzada
  "mercadolibre" activa, modo sólo lectura desactivado.
- Al menos una publicación vinculada de tipo Premium (`gold_pro`) y una Clásica (`gold_special`) —
  en el entorno real de Pompei Sanitarios ya existen ambas (30 Premium / 240 Clásica).
- Dos Listas de Precios activas con precio cargado para el mismo producto (ej. "ML" y "ML Premium",
  ya existentes en el CRM real — ids 8 y 10).

## Validación 1 — Configurar la Lista de Precios Premium (US1)

1. Ir a `/configuracion/mercadolibre`, tab Ventas.
2. Elegir "ML Premium" en el campo nuevo "Lista de Precios ML Premium" y guardar.
3. **Esperado**: toast de éxito, `GET configuracion/mercadolibre/estado` devuelve
   `lista_precio_id_premium` con el id guardado.
4. Recargar la pantalla: el campo sigue mostrando "ML Premium" seleccionado (persistencia).

## Validación 2 — La sincronización usa la lista correcta por tipo (US2)

1. Confirmar que un producto tiene precio cargado tanto en "ML" (lista general) como en "ML Premium".
2. Confirmar que ese producto tiene dos publicaciones vinculadas: una Premium, una Clásica (o usar dos
   productos distintos, uno con cada tipo de publicación vinculada).
3. Disparar "Sincronizar precios ahora" (`POST productos/sincronizar-precios-ml`).
4. **Esperado**: en `ml_operaciones_log`, el request `PUT /items/{id}` de la publicación Premium lleva
   el precio de "ML Premium"; el de la Clásica lleva el precio de "ML".
5. Repetir cambiando el precio del producto en la lista general (dispara el observer) y confirmar el
   mismo comportamiento sin usar el botón manual.

## Validación 3 — Fallback sin precio en la lista Premium (edge case)

1. Tomar un producto con precio SÓLO en la lista general, vinculado a una publicación Premium.
2. Sincronizar precios.
3. **Esperado**: la publicación Premium recibe igual el precio de la lista general (no queda sin
   sincronizar, no se marca error).

## Validación 4 — Backfill y actualización diaria del tipo de publicación (US3)

1. Tras desplegar la feature, correr manualmente:
   ```bash
   php artisan mercadolibre:sincronizar-tipos-publicacion --forzar
   ```
2. **Esperado**: `ml_publicacion_producto.listing_type_id` queda completo para las 270 publicaciones ya
   vinculadas (`SELECT COUNT(*) FROM ml_publicacion_producto WHERE listing_type_id IS NULL` debe dar 0
   salvo publicaciones que ML no pudo responder).
3. Confirmar que `ml_configuracion.tipo_publicacion_ultima_sync_en` se actualizó.
4. Sin `--forzar`, correr el comando de nuevo antes de que pasen 24hs desde el paso 1: debe salir sin
   volver a golpear la API (mismo criterio que `sincronizar-stock`).

## Validación 5 — Sin Lista Premium configurada (edge case / compatibilidad hacia atrás)

1. Vaciar `lista_precio_id_premium` en la configuración.
2. Sincronizar precios.
3. **Esperado**: TODAS las publicaciones (Premium o no) reciben el precio de la lista general — mismo
   comportamiento que antes de esta feature (spec 016 sin cambios de contrato para este caso).
