# Contrato: Endpoints internos del CRM (UI) — Fase 1

Extiende `specs/032-bot-mensajeria-mercadolibre/contracts/ui-endpoints.md`. No repite lo ya vigente
(bandeja, detalle, responder) salvo donde cambia.

## Sugerencias (`Mensajeria\SugerenciaController`, nuevo)

- `POST /mensajeria/{conversacion}/sugerencia` — solicita generación bajo demanda (FR-006, switch
  apagado o sugerencia previa con error). Despacha `GenerarSugerenciaMercadoLibre` (mismo Job que el
  flujo automático). Permiso `mensajeria.ver` alcanza (no es una acción de envío). Responde
  `202 {"ok": true, "estado": "generando"}`.

## `GET /mensajeria/actualizaciones` (spec 032, EXTENDIDO)

Se agrega al payload existente (`conversaciones`, `mensajes`) un array nuevo:

```json
{
  "ok": true,
  "ahora": "...",
  "conversaciones": [...],
  "mensajes": [...],
  "sugerencias": [
    { "id": 1, "ml_mensaje_id": 42, "estado": "lista", "texto_sugerido": "..." },
    { "id": 2, "ml_mensaje_id": 43, "estado": "error", "error_mensaje": "Timeout del proveedor de IA" }
  ]
}
```

## `POST /mensajeria/{conversacion}/responder` (spec 032, EXTENDIDO)

Body ahora acepta un campo opcional:

```json
{ "texto": "...", "sugerencia_id": 1 }
```

- `sugerencia_id` presente → `EnvioRespuestaMercadoLibre::enviar()` recibe ese ID, compara `texto`
  contra `MercadoLibreSugerencia::texto_sugerido` para setear `sugerencia_editada`.
- `sugerencia_id` ausente → comportamiento idéntico a la spec 032 (`ml_sugerencia_id` queda `null`).
- El resto del contrato (permiso `mensajeria.responder`, `422` en doble respuesta, error de envío) **no
  cambia**.

## Configuración del bot (`Integraciones\MercadoLibreBotConfiguracionController`, nuevo)

- `GET /configuracion/mercadolibre/bot` — pantalla de configuración (permiso `configuracion.funciones`,
  mismo que el resto de Funciones Avanzadas). Accesible desde el link que aparece en la tarjeta "Bot de
  Mercado Libre" de Funciones Avanzadas cuando el switch está activo (`ruta_configuracion` de
  `FuncionAvanzada`, mismo mecanismo que `mercadolibre`/`tiendanube`).
- `PUT /configuracion/mercadolibre/bot` — guarda `instrucciones_tono` (FR-002/FR-003). Body:
  `{ "instrucciones_tono": "..." }`. Toastr de éxito, sin recarga de página.

## Activación del switch (reutiliza el mecanismo existente, sin cambios)

`PATCH /configuracion/funciones/{funcion}/estado` (`FuncionAvanzadaController::estado`, ya existente)
sobre la fila `clave='mercadolibre_bot'` — igual que `mercadolibre`/`tiendanube`.
