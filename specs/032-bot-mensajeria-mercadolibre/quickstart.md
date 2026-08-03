# Quickstart: Mensajería de Mercado Libre (lectura y respuesta manual)

Guía de validación manual/E2E una vez implementada la feature. No sustituye los Feature tests
(`tests/Feature/*`, ver `plan.md` → Testing) — es la corrida de humo para confirmar el flujo completo.

## Prerrequisitos

- Migraciones corridas (`php artisan migrate`) — tablas `ml_conversaciones`,
  `ml_mensajes`, `ml_respuestas_enviadas`.
- Seeder de permisos corrido: `PermisoSeeder` (módulo `mensajeria`), y el rol del usuario de prueba con
  `mensajeria.ver` + `mensajeria.responder`.
- Conexión de Mercado Libre ya vinculada (spec 011) con scope de mensajería habilitado (confirmado por
  el usuario, ver `docs/bot_mensajeria_ml/decisiones-pendientes.md`).
- No hace falta cola/worker especial — todo corre síncrono (ver `research.md` R7).

## Escenario 1 — Bandeja unificada (User Story 1)

1. Simular la llegada de una notificación de Mercado Libre al webhook:
   ```
   POST /webhooks/mercadolibre
   { "resource": "/questions/123456789", "topic": "questions", "user_id": <seller_id>, ... }
   ```
2. Entrar a `/mensajeria` con un usuario que tenga `mensajeria.ver`.
3. **Esperado**: la conversación aparece en la bandeja, asociada al comprador y a la publicación (o al
   producto vinculado, si existe `MercadoLibrePublicacionProducto` para ese `item_id`).
4. Repetir el mismo POST (simulando un reintento de webhook).
5. **Esperado**: no aparece una segunda conversación ni un mensaje duplicado (ver
   `contracts/webhook-mercadolibre.md` → Casos de respuesta).

## Escenario 2 — Respuesta manual con auditoría (User Story 2)

1. Sobre la conversación creada en el Escenario 1, escribir un texto de respuesta y confirmar el envío:
   `POST /mensajeria/{conversacion}/responder` con `{ texto: "..." }`.
2. **Esperado**: se registra en `ml_respuestas_enviadas` con el texto, el usuario y la fecha;
   la conversación pasa a estado `respondida`.
3. Intentar responder de nuevo la misma conversación desde otra sesión/usuario.
4. **Esperado**: `422`, mensaje claro de que ya fue respondida (FR-007).
5. Simular un error de la API de Mercado Libre (mockear `ClienteMercadoLibre` para que falle) sobre una
   conversación pendiente y confirmar el envío.
6. **Esperado**: error visible al usuario, la conversación sigue en estado `pendiente`, no se registra
   un envío exitoso falso (FR-008).

## Verificación de no-regresión

- El resto de la integración de Mercado Libre (ventas, vinculación de publicaciones, sincronización de
  stock/precio — specs 011/012/013/016/023) sigue funcionando igual: `ClienteMercadoLibre` es
  reutilizado, no modificado en su contrato público.
- `TiendanubeWebhookController` y sus rutas no se tocan.
