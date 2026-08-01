# Phase 1 — Data Model: Conexión Tiendanube vía Application REST del Partner Portal

**Feature**: `022-tiendanube-conexion-rest`

Dos tablas nuevas, ambas independientes de `tn_configuracion`/`tn_operaciones_log` (spec 019, sin
modificar) — ver research.md R1 sobre por qué no se comparten.

## `tn_conexion_rest`

Fila única (single-tenant, mismo criterio que `tn_configuracion`), creada de forma perezosa por un método
`actual()` análogo al de `TiendanubeConfiguracion`.

| Columna | Tipo | Notas |
|---|---|---|
| `id` | bigint PK | Siempre `1` en la práctica (fila única) |
| `access_token` | text, nullable | Cifrado (`encrypted` cast), nunca en claro fuera del modelo |
| `store_id` | string, nullable | `user_id` devuelto por el canje de token — va en la ruta de cada llamada REST |
| `scopes_otorgados` | string, nullable | Tal cual los informa Tiendanube en el canje |
| `tienda_nombre` | string, nullable | De `GET /store` (verificación FR-005), idioma principal |
| `tienda_dominio` | string, nullable | `original_domain` de `GET /store` |
| `estado` | string (enum) | `no_configurada` \| `conectada` \| `caida` — mismo enum `EstadoConexion` ya usado por `tn_configuracion` (se reutiliza el Enum PHP, no la tabla) |
| `conectada_en` | timestamp, nullable | Momento en que la verificación (FR-005) fue exitosa |
| `ultimo_error` | string, nullable | Sólo con sentido cuando `estado = caida` |
| `actualizada_por` | FK `users.id`, nullable | Igual criterio que `tn_configuracion.actualizada_por` |
| `created_at`/`updated_at` | timestamps | — |

**Nunca se guardan**: `client_id`/`client_secret` (viven en `.env`/`config()`, son de la Application, no de
la conexión con una tienda particular — a diferencia de spec 019, donde sí se persisten porque se
auto-registran dinámicamente).

**Validaciones/reglas**:
- `estaCompleta()`: `filled(access_token) && filled(store_id)` — análogo a `TiendanubeConfiguracion::estaCompleta()`.
- Al desconectar (FR-008): se limpian `access_token`, `store_id`, `scopes_otorgados`, `tienda_nombre`,
  `tienda_dominio`, `conectada_en`; `estado` vuelve a `no_configurada`; se conserva el historial.

## `tn_rest_operaciones_log`

Mismo esquema y misma política de retención (30 días o 5.000 filas, purga oportunista) que
`tn_operaciones_log` — tabla separada, no columna discriminadora, para que no haya forma de confundir a qué
conexión pertenece cada fila (research.md R1).

| Columna | Tipo | Notas |
|---|---|---|
| `id` | bigint PK | |
| `operacion` | string | `conectar` \| `verificar` \| `desconectar` |
| `metodo` | string | `POST`/`GET` |
| `endpoint` | string | Ruta invocada (`/apps/authorize/token`, `/{store_id}/store`, o ruta interna en `desconectar`) |
| `sentido` | string | `lectura` \| `escritura` (esta spec sólo genera `lectura`, no hay escrituras de negocio) |
| `resultado` | string | `exito` \| `error` |
| `codigo_http` | int, nullable | |
| `duracion_ms` | int, nullable | |
| `mensaje_error` | text, nullable | Nunca contiene `access_token` ni `client_secret` (mismo saneado que `TiendanubeOperacionLog::sanear()`) |
| `usuario_id` | FK `users.id`, nullable | |
| `created_at` | timestamp | Sin `updated_at` (append-only, igual que `tn_operaciones_log`) |

## Relación con entidades existentes

- **No** hay relación de base de datos entre `tn_conexion_rest` y `tn_configuracion` — son independientes a
  propósito. La única relación es conceptual (documentada en `docs/documentacion_principal_crm.md` §5.3):
  ambas describen "conexiones con Tiendanube", pero de sistemas de autenticación distintos.
- `actualizada_por` en ambas tablas apunta a `users`, mismo patrón que el resto del CRM.
