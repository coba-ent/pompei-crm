# Implementation Plan: Mensajería de Mercado Libre (lectura y respuesta manual)

**Branch**: `032-bot-mensajeria-mercadolibre` | **Date**: 2026-08-02 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `/specs/032-bot-mensajeria-mercadolibre/spec.md`

## Summary

Unificar Preguntas pre-venta y Mensajería post-venta de Mercado Libre en una bandeja tipo chat
("Mensajería", desplegable propio del sidebar), recibidas vía un webhook nuevo (`webhooks/mercadolibre`,
aún no existe) que persiste el mensaje de forma idempotente. Un humano responde manualmente desde la
conversación; el envío real va vía `ClienteMercadoLibre` existente (mismo punto único de salida hacia
la API de ML que ya usa el resto de la integración), con auditoría de qué se envió y quién lo hizo.
**No hay generación de IA en esta spec** — el bot con sugerencias (switch "Bot de Mercado Libre" en
Funciones Avanzadas, LLM, colas asíncronas) se especifica aparte, en una spec futura, una vez migrado
el VPS (ver `docs/bot_mensajeria_ml/flujo-y-alcance.md`, Fase 1). Esta spec se implementa contra el
hosting actual, sin dependencia de infraestructura nueva.

## Technical Context

**Language/Version**: PHP 8.2, Laravel 12

**Primary Dependencies**: Eloquent; `ClienteMercadoLibre` existente
(`app/Services/MercadoLibre/ClienteMercadoLibre.php`, único punto de salida hacia la API de ML —
reutilizado, no se crea un cliente HTTP paralelo); DataTables/Toastr del template NexaDash (reglas de
diseño obligatorias del CLAUDE.md) para las vistas nuevas.

**Storage**: MySQL — tablas nuevas: `ml_conversaciones`, `ml_mensajes`,
`ml_respuestas_enviadas`. Se reutilizan `ml_publicacion_producto`,
`ml_ordenes` y `clientes`/`ventas` ya existentes para la vinculación de contexto.

**Testing**: PHPUnit (Feature tests) — cobertura obligatoria de la idempotencia del webhook (FR-004) y
del guard de doble respuesta (FR-007), por ser lógica de negocio con impacto directo en la relación con
el comprador y en las políticas de ML (riesgo de sanciones — ver
`docs/bot_mensajeria_ml/riesgos-politicas-ml.md`). Mock de `ClienteMercadoLibre` en los tests (sin
llamadas reales a la API de ML).

**Target Platform**: aplicación web Laravel (backend + Blade). No requiere el VPS ni colas reales —
el procesamiento del webhook es liviano (sin llamado a un LLM) y puede correr síncrono
(`QUEUE_CONNECTION=sync`, tal como ya corre el resto del proyecto en el hosting compartido actual).

**Project Type**: web application (monolito Laravel + Blade existente)

**Performance Goals**: sin requisito de tiempo real (FR-009: polling). El webhook debe responder rápido
(mismo criterio que `TiendanubeWebhookController`, pocos segundos).

**Constraints**: el webhook de notificaciones de ML debe responder rápido y de forma idempotente ante
reintentos (ML reintenta hasta 5 veces en 1 hora — ver `research.md` R3).

**Scale/Scope**: volumen bajo-moderado (negocio unipersonal, sin cifra exacta relevada); 3 tablas
nuevas, 1 webhook nuevo, 1 pantalla nueva ("Mensajería"). Sin Jobs, sin LLM, sin pantalla de
configuración de bot en esta spec.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **Principio I (docs como fuente de verdad)**: aplica. `docs/documentacion_principal_crm.md` y
  `docs/modelo_datos.md` se actualizan en el mismo cambio con el módulo "Mensajería" (Fase 0) y las 3
  entidades nuevas, antes de `/speckit-tasks`. PASA.
- **Principio II (spec-driven)**: aplica, es el flujo que se está siguiendo. PASA.
- **Principio III (corrección fiscal ARCA)**: no aplica. N/A.
- **Principio IV (testing donde hay dinero o impacto fiscal)**: no hay movimiento de dinero directo,
  pero sí impacto reputacional/contractual con Mercado Libre y un requisito de seguridad explícito
  (FR-007: no se pueden enviar dos respuestas al mismo mensaje). Se trata con el mismo rigor que
  "dinero": tests obligatorios sobre idempotencia del webhook y el guard de doble respuesta. PASA.
- **Principio V (convenciones Laravel + español)**: nombres en español (`Conversacion`, `Mensaje`,
  `RespuestaEnviada`, tablas `ml_*`), sin `empresa_id`/multi-tenant, Eloquent + FormRequests +
  Services siguiendo el patrón ya usado por `MercadoLibreConfiguracion`/`ClienteMercadoLibre`. PASA.
- **Regla de fidelidad estructural a Contagram (CLAUDE.md)**: este módulo no existe en Contagram real —
  divergencia explícita ya autorizada por el usuario (igual que la integración de ML en general, spec
  011). No aplica el principio rector de fidelidad a Contagram para esta feature. PASA (excepción
  documentada).
- **Reglas de diseño obligatorias (CLAUDE.md)**: DataTables+AJAX para el listado de conversaciones
  (bandeja), Toastr para notificaciones. La vista de detalle de una conversación es un chat, no un
  listado tabular: se implementa con AJAX/polling en vez de DataTables para los mensajes de una
  conversación puntual (excepción razonable y explícita, ya documentada en
  `docs/bot_mensajeria_ml/decisiones-pendientes.md`). PASA.

Sin violaciones no justificadas. No aplica Complexity Tracking más allá de lo documentado arriba.

## Project Structure

### Documentation (this feature)

```text
specs/032-bot-mensajeria-mercadolibre/
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
│   ├── Integraciones/
│   │   └── MercadoLibreMensajeriaWebhookController.php   # NUEVO — recibe notificaciones de ML (preguntas + post-venta), valida, idempotente
│   └── Mensajeria/
│       └── ConversacionController.php                     # NUEVO — bandeja + detalle de conversación (index/datatable/show/actualizaciones/responder)
│
├── Models/Integraciones/
│   ├── MercadoLibreConversacion.php     # NUEVO
│   ├── MercadoLibreMensaje.php          # NUEVO
│   └── MercadoLibreRespuestaEnviada.php # NUEVO
│
├── Services/MercadoLibre/Mensajeria/
│   ├── RecepcionMensajeMercadoLibre.php   # NUEVO — normaliza notificación ML → Conversacion/Mensaje (idempotente)
│   └── EnvioRespuestaMercadoLibre.php     # NUEVO — envía vía ClienteMercadoLibre existente, registra auditoría
│
└── Http/Requests/Mensajeria/
    └── EnviarRespuestaMercadoLibreRequest.php   # NUEVO

database/migrations/
├── ..._create_ml_conversaciones_table.php
├── ..._create_ml_mensajes_table.php
└── ..._create_ml_respuestas_enviadas_table.php

database/seeders/
└── PermisoSeeder.php           # MODIFICADO — se agrega el módulo 'mensajeria' (ver/responder)

resources/views/
└── mensajeria/
    └── index.blade.php         # NUEVO — bandeja + chat, adaptado de template NexaDash chat.blade.php

resources/js/
└── mensajeria.js                # NUEVO — AJAX de la bandeja, polling, envío de respuesta

routes/web.php
├── webhooks/mercadolibre (POST)                                        # NUEVO — sin middleware de sesión, valida origen
└── mensajeria/* (bajo permiso 'mensajeria.ver'/'mensajeria.responder')  # NUEVO

resources/views/elements/sidebar.blade.php   # MODIFICADO — nuevo desplegable "Mensajería"

tests/Feature/
├── MercadoLibreMensajeriaWebhookTest.php     # NUEVO — idempotencia (FR-004), asociación a producto/venta
└── MercadoLibreEnvioRespuestaTest.php        # NUEVO — doble respuesta (FR-007), auditoría (FR-006), error de envío (FR-008)
```

**Structure Decision**: se sigue la estructura existente del proyecto (monolito Laravel). El controlador
de mensajería va en un namespace propio `App\Http\Controllers\Mensajeria` (nuevo módulo de primer nivel,
igual jerarquía que `Ingresos`/`Egresos`), mientras que el webhook va en
`App\Http\Controllers\Integraciones` (junto al resto de la integración de Mercado Libre, mismo patrón
que `TiendanubeWebhookController`). Los servicios de dominio van en
`App\Services\MercadoLibre\Mensajeria` (subcarpeta nueva dentro del namespace `MercadoLibre` ya
existente, sin tocar `ClienteMercadoLibre` — se reutiliza tal cual). **Nada de esto crea la carpeta
`App\Services\MercadoLibre\Bot` ni el Job/LLM** — eso queda para la spec futura del bot.

## Complexity Tracking

*Sin violaciones de la Constitution Check — sección no aplica.*
