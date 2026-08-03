# Quickstart: Bot de Mercado Libre con sugerencias de IA (Fase 1)

Guía de validación manual/E2E. Prerrequisito: la spec 032 ya implementada y funcionando (bandeja
"Mensajería", webhook, respuesta manual). No sustituye los Feature tests de `plan.md` → Testing.

## Prerrequisitos

- Migraciones de esta fase corridas: `ml_sugerencias`, `ml_bot_configuracion`, columnas nuevas en
  `ml_respuestas_enviadas`.
- `FuncionAvanzadaSeeder` corrido con la fila `mercadolibre_bot`.
- `OPENAI_API_KEY` configurada en `.env` (o mock de `GeneradorDeSugerencias` en el entorno de pruebas).
- `QUEUE_CONNECTION=sync` alcanza en local (ver `research.md` R7); para probar el comportamiento
  asíncrono real hace falta `QUEUE_CONNECTION=database` + `php artisan queue:work` (o VPS en
  producción).

## Escenario 1 — Activar y configurar el bot (User Story 1)

1. Activar el switch "Bot de Mercado Libre" en Configuración & Ajustes → Funciones Avanzadas.
2. **Esperado**: aparece el link a la pantalla de configuración del bot.
3. Entrar, escribir instrucciones de tono, guardar.
4. **Esperado**: Toastr de éxito; `ml_bot_configuracion.instrucciones_tono` queda persistido.

## Escenario 2 — Sugerencia automática (User Story 2)

1. Con el switch activo, simular la llegada de una pregunta nueva sobre una publicación vinculada a un
   producto con stock cargado (mismo webhook de la spec 032).
2. Esperar a que el Job procese (o correr `php artisan queue:work --once` si es síncrono en pruebas).
3. Abrir la conversación en `/mensajeria`.
4. **Esperado**: se ve un texto de sugerencia, coherente con el stock/precio del producto y con el tono
   configurado en el Escenario 1.
5. Desactivar el switch y repetir con otra pregunta nueva.
6. **Esperado**: sin sugerencia automática; al pedirla bajo demanda
   (`POST /mensajeria/{conversacion}/sugerencia`), se genera igual.

## Escenario 3 — Envío con sugerencia y auditoría (User Story 3)

1. Sobre una conversación con sugerencia generada, enviarla tal cual:
   `POST /mensajeria/{conversacion}/responder` con `{ texto: <igual a texto_sugerido>, sugerencia_id }`.
2. **Esperado**: se registra en `ml_respuestas_enviadas` con `ml_sugerencia_id` informado y
   `sugerencia_editada=false`.
3. Sobre otra conversación con sugerencia, editar el texto antes de enviar.
4. **Esperado**: se registra con `sugerencia_editada=true`.
5. Repetir el Escenario 3 de `specs/032-bot-mensajeria-mercadolibre/quickstart.md` (doble respuesta,
   error de envío) — **debe seguir comportándose exactamente igual**, sin sugerencia de por medio.

## Verificación de no-regresión

- Todo lo de `specs/032-bot-mensajeria-mercadolibre/quickstart.md` sigue funcionando sin cambios de
  comportamiento (guard de doble respuesta, manejo de error de envío, bandeja, polling de mensajes).
- El resto de la integración de Mercado Libre (ventas, vinculación de publicaciones, sincronización de
  stock/precio) sigue funcionando igual.
