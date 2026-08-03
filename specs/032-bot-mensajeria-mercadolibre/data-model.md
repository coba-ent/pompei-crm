# Data Model: Mensajería de Mercado Libre (lectura y respuesta manual)

Tablas nuevas, todas sin `empresa_id` (single-tenant, principio V de la constitución). Nombres en
español, snake_case, prefijo `ml_` (mismo prefijo que las tablas existentes de la integración, ej.
`ml_ordenes`, `ml_configuracion`, `ml_publicacion_producto` — no `mercadolibre_`).

**Nota de alcance**: no hay tabla de "sugerencias" ni de "configuración del bot" en esta spec — eso se
suma en la spec futura del bot (Fase 1), sin romper este modelo (se agregaría una FK opcional desde
`ml_respuestas_enviadas` hacia una futura tabla de sugerencias).

## `ml_conversaciones`

Representa el hilo de intercambio con un comprador (ver spec, Key Entities).

| Columna | Tipo | Notas |
|---|---|---|
| `id` | bigint PK | |
| `tipo` | enum/string (`pregunta`, `post_venta`) | Determina de qué API de ML viene y cómo se agrupa (R1 vs R2 de research.md) |
| `comprador_ml_id` | string | `user_id` del comprador en Mercado Libre |
| `comprador_nickname` | string, nullable | Para mostrar en la bandeja sin pegarle a la API en cada render |
| `publicacion_id_ml` | string, nullable | Sólo `tipo=pregunta` — `item_id` de Mercado Libre |
| `ml_publicacion_producto_id` | FK nullable → `ml_publicacion_producto` | Vinculación a producto del CRM, si existe |
| `ml_orden_id` | FK nullable → `ml_ordenes` | Sólo `tipo=post_venta` — la orden/venta ligada |
| `estado` | enum/string (`pendiente`, `respondida`, `cerrada`) | `cerrada` cubre `BANNED`/`DISABLED`/`DELETED` de ML (Edge Case) |
| `ultimo_mensaje_en` | timestamp | Para ordenar la bandeja por actividad reciente |
| `created_at` / `updated_at` | timestamp | |

**Restricción única**: `(tipo, comprador_ml_id, publicacion_id_ml)` para `pregunta`;
`(tipo, ml_orden_id)` para `post_venta` — implementada como índice único parcial o validado a
nivel de aplicación en el upsert de R4 (research.md).

## `ml_mensajes`

Cada entrada individual dentro de una conversación.

| Columna | Tipo | Notas |
|---|---|---|
| `id` | bigint PK | |
| `ml_conversacion_id` | FK → `ml_conversaciones` | |
| `ml_id` | string | `question_id` o `message_id` de ML — clave natural para idempotencia (R4) |
| `origen` | enum/string (`comprador`, `negocio`) | Quién lo escribió |
| `texto` | text | |
| `enviado_en` | timestamp | Momento del mensaje según ML (no necesariamente igual a `created_at`) |
| `created_at` / `updated_at` | timestamp | |

**Restricción única**: índice único sobre `ml_id` (ML no reutiliza IDs entre preguntas y
mensajes post-venta).

## `ml_respuestas_enviadas`

Auditoría de una respuesta efectivamente enviada al comprador (FR-006).

| Columna | Tipo | Notas |
|---|---|---|
| `id` | bigint PK | |
| `ml_mensaje_id` | FK → `ml_mensajes` | El mensaje del comprador que se está respondiendo |
| `texto_enviado` | text | El texto final, tal como se mandó a Mercado Libre |
| `usuario_id` | FK → `users` | Quién confirmó el envío |
| `enviado_en` | timestamp | |
| `resultado` | enum/string (`exito`, `error`) | Si el POST a la API de ML falló (FR-008) |
| `error_mensaje` | string, nullable | |

**Restricción única**: `(ml_mensaje_id)` con `resultado=exito` — garantiza a nivel de base de
datos que no se puede registrar dos respuestas exitosas al mismo mensaje (FR-007, condición de carrera
entre dos usuarios).

## Relaciones con entidades existentes

- `ml_conversaciones.ml_publicacion_producto_id` → `MercadoLibrePublicacionProducto`
  (ya existe) — da acceso a `Producto` para mostrar contexto en la bandeja.
- `ml_conversaciones.ml_orden_id` → `MercadoLibreOrden` (ya existe) — da acceso al
  estado de envío y a la venta del CRM si ya fue convertida.
- `permisos` (existente) — nuevo módulo `mensajeria` con acciones `ver` y `responder`.
