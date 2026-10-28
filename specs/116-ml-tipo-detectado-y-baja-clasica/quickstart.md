# Quickstart / validación — spec 116

Todo en local. **Nunca contra la cuenta real del cliente.** En producción, después del deploy, solo
lectura: comparar los tipos y precios de algunas publicaciones contra ML.

## Prerrequisitos

- XAMPP con MySQL levantado; `php artisan migrate` (columna `origen`).
- `php artisan test --filter='CambioTipo|RevisionTipos'` en verde.

## 1. Baja a Clásica con `available_downgrades` vacío (US1)

Test `CambioTipoPublicacionTest`: con `Http::fake()` que devuelve `[]` para `available_downgrades` y
acepta el `POST /listing_type`, "Pasar a Clásica" termina en `cambiado` y el PUT de precio es el de la
lista general. Con el POST rechazado: `fallido`, el mensaje de ML en la respuesta y ningún PUT de
precio.

## 2. Cambio detectado en ML (US2)

Test `CambioTipoDetectadoTest`:
- Vínculo `gold_pro` y multiget que informa `gold_special` → vínculo `gold_special`, PUT con el precio
  de la lista general aunque baje más del 20%, fila en el historial con `origen = mercadolibre`,
  `usuario_id` null y resultado `cambiado`. Ninguna retención abierta.
- Igual, con la integración en sólo lectura → vínculo sigue `gold_pro`, sin PUT, sin historial. Al
  desactivar el modo sólo lectura y repetir la revisión, se procesa.
- Con el candado `ml:sincronizar_precios` tomado → igual que el anterior.
- PUT rechazado → vínculo `gold_special`, `precio_pendiente = 1`, `cambiado_precio_pendiente`.
- `null` → `gold_pro` (primera clasificación) → guardado, sin PUT ni historial.

## 3. Frecuencia (US3)

Test `RevisionTiposEnCronStockTest`:
- `frecuencia_sync_minutos = 15` y `tipo_publicacion_ultima_sync_en` hace 16 min → `mercadolibre:sincronizar-stock`
  hace el multiget de tipos. Hace 5 min → no lo hace.
- Integración en sólo lectura (stock bloqueado, `stock_ultima_sync_en` sin actualizar) → dos
  ejecuciones seguidas del comando de stock hacen el multiget una sola vez.
- `bootstrap/app.php` ya no programa `mercadolibre:sincronizar-tipos-publicacion`
  (`php artisan schedule:list`).

## 4. Aviso de cuotas (US4), en el navegador local

Con respuestas simuladas (o un vínculo local Premium), abrir "Pasar a Clásica" en Vinculaciones ML: se
ve la alerta "Al pasar a Clásica pierde las N cuotas sin interés". Abrir "Pasar a Premium" en una
Clásica: no aparece. Seleccionar varias y "Pasar a Clásica" en lote: aparece el aviso general.

## 5. Post-deploy (producción, solo lectura)

- `php artisan schedule:list | grep tipos` → sin entrada propia.
- Tras ≥ 15 min, `ml_configuracion.tipo_publicacion_ultima_sync_en` avanzó.
- `ml_cambios_tipo_publicacion` con `origen = mercadolibre`: revisar que cada fila coincida con el
  tipo y precio en ML (GET de lectura).
