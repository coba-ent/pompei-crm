# Implementation Plan: Bot de Mercado Libre con sugerencias de IA (Fase 1)

**Branch**: `033-bot-mercadolibre-ia` | **Date**: 2026-08-02 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `/specs/033-bot-mercadolibre-ia/spec.md`

## Summary

Sumar generación de sugerencias por IA sobre la Mensajería de Mercado Libre ya construida en la spec
032 (bandeja "Mensajería", `MercadoLibreConversacion`/`MercadoLibreMensaje`/`MercadoLibreRespuestaEnviada`,
webhook `webhooks/mercadolibre`, `ConversacionController@responder` vía
`EnvioRespuestaMercadoLibre::enviar()`). Switch "Bot de Mercado Libre" en Funciones Avanzadas (mismo
patrón `clave`/`activa`/`ruta_configuracion` que `mercadolibre`/`tiendanube`) habilita una pantalla de
configuración con el tono/instrucciones del bot. Cuando está activo, el webhook existente despacha un
Job que genera la sugerencia de forma asíncrona vía una interfaz `GeneradorDeSugerencias` (implementación
default OpenAI GPT-4o-mini). La sugerencia se integra al flujo de envío ya existente extendiendo
`EnvioRespuestaMercadoLibre::enviar()` y `ml_respuestas_enviadas` con la referencia a la sugerencia usada
y si fue editada. Depende de que el VPS con colas reales esté migrado para producción; en desarrollo
corre con `QUEUE_CONNECTION=sync` sin cambios de código.

## Technical Context

**Language/Version**: PHP 8.2, Laravel 12

**Primary Dependencies**: `ClienteMercadoLibre` (sin cambios, ya reutilizado por la spec 032); SDK
oficial de OpenAI para PHP (`openai-php/client` o `openai-php/laravel`) para `GeneradorDeSugerenciasOpenAI`;
Laravel Queues para el Job asíncrono; Toastr/AJAX del template NexaDash para la pantalla de
configuración (reglas de diseño obligatorias del CLAUDE.md).

**Storage**: MySQL — tabla nueva `ml_sugerencias`; tabla nueva `ml_bot_configuracion` (fila única, mismo
patrón que `MercadoLibreConfiguracion::actual()`); columnas nuevas en `ml_respuestas_enviadas`
(`ml_sugerencia_id` nullable, `sugerencia_editada` nullable) vía migración de alteración. Nueva fila en
`funciones_avanzadas` (`clave='mercadolibre_bot'`).

**Testing**: PHPUnit (Feature tests) — cobertura obligatoria (Principio IV de la constitución, mismo
criterio que la spec 032) sobre: el switch apagado no genera sugerencia automática (FR-004/FR-006), el
guard de doble respuesta y el flujo de envío ya existentes siguen intactos al integrar la sugerencia
(FR-009, no-regresión sobre `EnvioRespuestaMercadoLibreTest` de la spec 032), y la auditoría de origen
de la sugerencia (FR-010). Mock de `GeneradorDeSugerencias` (interfaz) en los tests — sin llamadas
reales a OpenAI.

**Target Platform**: aplicación web Laravel. Requiere el VPS con `queue:work` bajo supervisor para
producción (a diferencia de la spec 032); en local/testing corre con `QUEUE_CONNECTION=sync`.

**Project Type**: web application (monolito Laravel + Blade existente)

**Performance Goals**: SC-001 de la spec — sugerencia disponible dentro de 2 minutos para ≥90% de los
mensajes entrantes con el bot activado.

**Constraints**: la generación de la sugerencia NO debe bloquear ni demorar la respuesta del webhook
`webhooks/mercadolibre` ya existente (FR-005) — se despacha un Job, el controller del webhook sigue
respondiendo igual de rápido que en la spec 032.

**Scale/Scope**: 2 tablas nuevas (`ml_sugerencias`, `ml_bot_configuracion`), 1 columna-par nueva en
`ml_respuestas_enviadas`, 1 fila nueva en `funciones_avanzadas`, 1 Job, 1 interfaz + 1 implementación de
LLM, 1 pantalla de configuración nueva, extensión de 2 archivos ya existentes de la spec 032
(`ConversacionController`, `EnvioRespuestaMercadoLibre`) y de la vista `mensajeria/index.blade.php`.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **Principio I (docs como fuente de verdad)**: aplica. `docs/documentacion_principal_crm.md` §6.5 y
  `docs/modelo_datos.md` §14 se actualizan en el mismo cambio con las entidades de esta fase, antes de
  `/speckit-tasks`. PASA.
- **Principio II (spec-driven)**: aplica, es el flujo que se está siguiendo. PASA.
- **Principio III (corrección fiscal ARCA)**: no aplica. N/A.
- **Principio IV (testing donde hay dinero o impacto fiscal)**: mismo criterio que la spec 032 —
  riesgo reputacional/de política de ML, no de dinero. Tests obligatorios sobre que el switch controla
  correctamente la generación automática y sobre que la integración con el envío no rompe el guard de
  doble respuesta ya existente (no-regresión). PASA.
- **Principio V (convenciones Laravel + español)**: nombres en español (`MercadoLibreSugerencia`,
  `MercadoLibreBotConfiguracion`, tablas `ml_sugerencias`/`ml_bot_configuracion`), sin
  `empresa_id`/multi-tenant, mismo patrón de servicio/Eloquent que el resto de la integración. PASA.
- **Regla de fidelidad estructural a Contagram (CLAUDE.md)**: no aplica — divergencia ya autorizada
  (spec 011/032). PASA (excepción documentada, heredada).
- **Reglas de diseño obligatorias (CLAUDE.md)**: modal/formulario Bootstrap+AJAX+Toastr para la
  pantalla de configuración del bot (regla estándar de altas/ediciones). La sugerencia se integra al
  chat ya existente (excepción a DataTables ya documentada y aceptada en la spec 032, no se reabre).
  PASA.

Sin violaciones no justificadas. No aplica Complexity Tracking más allá de lo documentado arriba.

## Project Structure

### Documentation (this feature)

```text
specs/033-bot-mercadolibre-ia/
├── plan.md              # This file (/speckit-plan command output)
├── research.md          # Phase 0 output (/speckit-plan command)
├── data-model.md        # Phase 1 output (/speckit-plan command)
├── quickstart.md        # Phase 1 output (/speckit-plan command)
├── contracts/           # Phase 1 output (/speckit-plan command)
└── tasks.md             # Phase 2 output (/speckit-tasks command - NOT created by /speckit-plan)
```

### Source Code (repository root)

```text
app/
├── Http/Controllers/
│   ├── Mensajeria/
│   │   └── SugerenciaController.php                       # NUEVO — generar bajo demanda (store) y estado (show), para polling
│   └── Integraciones/
│       └── MercadoLibreBotConfiguracionController.php     # NUEVO — pantalla de configuración del bot (index/guardar)
│
├── Models/Integraciones/
│   ├── MercadoLibreSugerencia.php          # NUEVO
│   └── MercadoLibreBotConfiguracion.php    # NUEVO — fila única, patrón MercadoLibreConfiguracion::actual()
│
├── Services/MercadoLibre/Bot/
│   ├── GeneradorDeSugerencias.php          # NUEVO — interfaz/contrato: generar(MercadoLibreConversacion, MercadoLibreMensaje): string
│   └── GeneradorDeSugerenciasOpenAI.php    # NUEVO — implementación default (GPT-4o-mini)
│
├── Jobs/
│   └── GenerarSugerenciaMercadoLibre.php   # NUEVO — job encolado: arma contexto (mensaje+historial+producto/venta) y llama GeneradorDeSugerencias
│
├── Http/Controllers/Integraciones/
│   └── MercadoLibreMensajeriaWebhookController.php   # MODIFICADO (spec 032) — tras persistir el mensaje, si el switch está activo, despacha GenerarSugerenciaMercadoLibre::dispatch()
│
├── Http/Controllers/Mensajeria/
│   └── ConversacionController.php          # MODIFICADO (spec 032) — actualizaciones() incluye estado de sugerencias; responder() acepta sugerencia_id opcional
│
├── Services/MercadoLibre/Mensajeria/
│   └── EnvioRespuestaMercadoLibre.php      # MODIFICADO (spec 032) — enviar() acepta ?int $sugerenciaId, registra ml_sugerencia_id + sugerencia_editada en el insert existente
│
└── Http/Requests/Mensajeria/
    ├── EnviarRespuestaMercadoLibreRequest.php          # MODIFICADO (spec 032) — agrega regla opcional `sugerencia_id`
    └── GuardarConfiguracionBotMercadoLibreRequest.php  # NUEVO

database/migrations/
├── ..._create_ml_sugerencias_table.php
├── ..._create_ml_bot_configuracion_table.php
└── ..._add_sugerencia_columns_to_ml_respuestas_enviadas_table.php

database/seeders/
├── FuncionAvanzadaSeeder.php   # MODIFICADO — se agrega la fila 'mercadolibre_bot'
└── PermisoSeeder.php           # sin cambios — reutiliza 'configuracion.funciones' para la config del bot

resources/views/
├── mensajeria/
│   └── index.blade.php         # MODIFICADO (spec 032) — muestra la sugerencia como borrador editable en el panel de respuesta
└── configuracion/mercadolibre/
    └── bot.blade.php            # NUEVO — pantalla de configuración del bot

resources/js/
└── mensajeria.js                # MODIFICADO (spec 032) — polling incluye estado de sugerencia; botón "usar sugerencia"/"pedir sugerencia"

routes/web.php
├── mensajeria/{conversacion}/sugerencia (POST, GET estado)              # NUEVO — bajo 'mensajeria.ver'/'mensajeria.responder'
└── configuracion/mercadolibre/bot (GET/PUT)                              # NUEVO — bajo 'configuracion.funciones'

resources/views/elements/sidebar.blade.php   # sin cambios — la config del bot se accede desde Funciones Avanzadas, no desde el sidebar

tests/Feature/
├── MercadoLibreBotConfiguracionTest.php        # NUEVO — switch en Funciones Avanzadas + guardado de configuración
├── MercadoLibreSugerenciaTest.php               # NUEVO — generación automática (switch on) vs bajo demanda (switch off), estados generando/lista/error
└── MercadoLibreEnvioRespuestaTest.php          # MODIFICADO (spec 032) — casos nuevos: envío con sugerencia sin editar / editada, no-regresión del guard de doble respuesta
```

**Structure Decision**: se extiende la estructura ya construida por la spec 032 en vez de crear un
módulo paralelo. El namespace nuevo `App\Services\MercadoLibre\Bot` agrupa la interfaz de generación de
IA (igual jerarquía que `App\Services\MercadoLibre\Mensajeria` de la spec 032). La configuración del
bot va en `App\Http\Controllers\Integraciones` junto al resto de configuración de Mercado Libre, mismo
patrón que `MercadoLibreConfiguracionController`. Los archivos "MODIFICADO (spec 032)" son extensiones
puntuales y no rupturas de contrato: `EnvioRespuestaMercadoLibre::enviar()` gana un parámetro opcional
con default `null`, no cambia la firma para los llamados existentes sin sugerencia.

## Complexity Tracking

*Sin violaciones de la Constitution Check — sección no aplica.*
