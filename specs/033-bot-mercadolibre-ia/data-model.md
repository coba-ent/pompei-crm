# Data Model: Bot de Mercado Libre con sugerencias de IA (Fase 1)

Tablas nuevas y extensiones sobre el modelo ya construido en la spec 032. Sin `empresa_id`
(single-tenant, principio V de la constitución). Prefijo `ml_`, mismo criterio que la Fase 0.

## `ml_sugerencias` (nueva)

El borrador generado por IA para un mensaje entrante.

| Columna | Tipo | Notas |
|---|---|---|
| `id` | bigint PK | |
| `ml_mensaje_id` | FK → `ml_mensajes` | El mensaje del comprador que la originó |
| `texto_sugerido` | text, nullable | Null mientras `estado=generando` |
| `estado` | enum/string (`generando`, `lista`, `error`) | |
| `error_mensaje` | string, nullable | Detalle si `estado=error` (ej. timeout del proveedor de IA) |
| `generada_en` | timestamp, nullable | |
| `created_at` / `updated_at` | timestamp | |

**Nota**: si se pide una sugerencia bajo demanda para una conversación que ya tenía una vigente
(Edge Case de la spec), se crea una fila nueva y la anterior queda como histórico — la vista sólo
muestra la más reciente por mensaje (`latest()`).

## `ml_bot_configuracion` (nueva)

Fila única (mismo patrón que `MercadoLibreConfiguracion::actual()` de la integración base).

| Columna | Tipo | Notas |
|---|---|---|
| `id` | bigint PK | Fila única, `id=1` |
| `instrucciones_tono` | text, nullable | System prompt editable por el usuario (FR-002/FR-003) |
| `actualizada_por` | FK nullable → `users` | |
| `actualizada_en` | timestamp, nullable | |
| `created_at` / `updated_at` | timestamp | |

**Nota**: NO incluye un campo de activo/inactivo — eso vive en `funciones_avanzadas.activa` para
`clave='mercadolibre_bot'` (ver `research.md` R1), evitando dos fuentes de verdad para el mismo flag.

## `ml_respuestas_enviadas` (extensión — tabla de la spec 032)

Columnas nuevas agregadas vía migración de alteración:

| Columna nueva | Tipo | Notas |
|---|---|---|
| `ml_sugerencia_id` | FK nullable → `ml_sugerencias` | Null si se escribió desde cero sin sugerencia |
| `sugerencia_editada` | boolean, nullable | Null si no hubo sugerencia; true/false si la hubo |

Sin cambios en las columnas ni en el índice único `(ml_mensaje_id)` con `resultado=exito` ya existente
— el guard de doble respuesta de la spec 032 sigue exactamente igual.

## `funciones_avanzadas` (fila nueva — tabla existente)

Nueva fila sembrada vía `FuncionAvanzadaSeeder` (mismo patrón que `mercadolibre`/`tiendanube`):

```php
[
    'clave' => 'mercadolibre_bot',
    'orden' => 11, // FuncionAvanzadaSeeder ya siembra 10 filas (1-10) — ésta es la primera fuera de ese
                   // relevamiento original, va al final del orden existente.
    'nombre' => 'Bot de Mercado Libre',
    'descripcion' => 'Generar sugerencias de respuesta con IA para los mensajes de Mercado Libre.',
    'icono' => 'fas fa-robot',
    'disponible' => true,
    'activa' => false,
    'ruta_configuracion' => 'configuracion.mercadolibre.bot',
]
```

**Nota de implementación**: `FuncionAvanzadaSeeder::run()` (código real) itera un array donde cada fila
incluye `orden` — omitirlo rompe el patrón existente (y el `update()` idempotente que corre sobre filas
ya sembradas). Verificar contra el seeder actual cuál es el próximo `orden` libre al implementar T004.

## Relaciones con entidades existentes (spec 032)

- `ml_sugerencias.ml_mensaje_id` → `MercadoLibreMensaje` (spec 032) — de ahí se llega a
  `MercadoLibreConversacion` para el contexto (historial, producto, orden).
- `ml_respuestas_enviadas.ml_sugerencia_id` → `MercadoLibreSugerencia` (esta spec).
- `funciones_avanzadas` (clave `mercadolibre_bot`) — controla si `MercadoLibreMensajeriaWebhookController`
  (spec 032) despacha o no `GenerarSugerenciaMercadoLibre` al recibir un mensaje nuevo.
