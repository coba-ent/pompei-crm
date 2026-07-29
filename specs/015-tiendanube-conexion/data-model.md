# Phase 1 — Data Model: Conexión Tiendanube (Aplicación personalizada)

**Feature**: `015-tiendanube-conexion` | **Fecha**: 2026-07-29

Dos tablas nuevas, ambas prefijadas `tn_` (mismo criterio que `ml_` para Mercado Libre — agrupar
visualmente el esquema de la integración). A diferencia de Mercado Libre, **no hay tabla de
"solicitud de vinculación pendiente"**: sin flujo OAuth no existe ese estado intermedio (research.md
§R1/§R6).

---

## 1. `tn_configuracion`

Registro único (single-tenant, igual criterio que `ml_configuracion` / `empresa`): credenciales,
datos de la tienda vinculada y estado de la conexión, todo en una sola fila. Se accede siempre por
`TiendanubeConfiguracion::actual()`, nunca con `find()` suelto (mismo patrón que
`MercadoLibreConfiguracion::actual()`).

| Columna | Tipo | Notas |
|---|---|---|
| `id` | bigint PK | Siempre `1` en la práctica (single-tenant). |
| `store_id` | string(50), nullable | Identificador numérico de la tienda en Tiendanube, tal como lo carga el usuario. |
| `access_token` | text, nullable, **cifrado** (`encrypted`) | Token de la Aplicación personalizada. `$hidden` en el modelo — nunca se serializa. |
| `nombre_tienda` | string(150), nullable | Obtenido de `GET /store` al verificar conexión (FR-009). |
| `dominio` | string(150), nullable | Dominio principal de la tienda. |
| `pais` | string(5), nullable | Código de país informado por Tiendanube. |
| `moneda` | string(10), nullable | Código de moneda informado por Tiendanube. |
| `estado` | string(20) | `EstadoConexion` propio: `no_configurada` \| `desconectada` \| `conectada` \| `caida`. Ver nota abajo. |
| `ultimo_error` | text, nullable | Mensaje del último rechazo, para mostrar en el panel cuando `estado = caida`. |
| `modo_solo_lectura` | boolean, default `false` | Kill-switch de escrituras (FR-016), independiente del de Mercado Libre. |
| `credenciales_guardadas_en` | timestamp, nullable | Fecha en que se guardó el `access_token` vigente (FR-009). |
| `ultima_verificacion_en` | timestamp, nullable | Última vez que "Probar conexión" respondió con éxito (FR-009). |
| `actualizada_por` | FK `users.id`, nullable, `nullOnDelete` | Igual patrón que `ml_configuracion.actualizada_por`. |
| `created_at` / `updated_at` | timestamps | Estándar. |

**`estado` es un valor mayormente derivado, con una excepción** (mismo criterio documentado en
`011-mercadolibre-conexion-oauth/data-model.md` §3 para `EstadoConexion`):

- `no_configurada`: se **calcula** en el momento (no se persiste así) cuando `store_id` o
  `access_token` están vacíos — `estaCompleta(): bool` en el modelo, igual que
  `MercadoLibreConfiguracion::estaCompleta()`.
- `desconectada`: se persiste explícitamente tras la acción "Desconectar" (FR-010), que borra
  `access_token` pero conserva `nombre_tienda`/`dominio`/`pais`/`moneda` para trazabilidad (FR-011).
- `conectada`: se persiste tras un "Probar conexión" exitoso.
- `caida`: se persiste cuando una operación es rechazada por credencial inválida/revocada (FR-012).

**Validación de datos incompletos (FR-004)**: `estaCompleta()` exige `store_id` y `access_token` no
vacíos antes de permitir "Probar conexión" — mismo método que `MercadoLibreConfiguracion::estaCompleta()`.

---

## 2. `tn_operaciones_log`

Mismo esquema exacto que `ml_operaciones_log` (`011-mercadolibre-conexion-oauth/data-model.md` §5),
tabla separada — historial propio de Tiendanube (research.md §R7).

| Columna | Tipo | Notas |
|---|---|---|
| `id` | bigint PK | |
| `operacion` | string(100) | Nombre de la operación de dominio (`probar_conexion`, `desconectar`, etc.). |
| `metodo` | string(10) | Verbo HTTP real usado contra la API. |
| `endpoint` | string(255) | Ruta relativa de Tiendanube (`/{store_id}/store`, etc.). |
| `sentido` | string(10) | `lectura` \| `escritura`. |
| `resultado` | string(20) | `exito` \| `error` \| `bloqueada`. |
| `codigo_http` | unsignedSmallInteger, nullable | |
| `duracion_ms` | unsignedInteger, nullable | |
| `mensaje_error` | text, nullable | Nunca contiene el token (FR-015). |
| `payload_bloqueado` | text, nullable | Qué se habría enviado, cuando `resultado = bloqueada`. |
| `usuario_id` | FK `users.id`, nullable, `nullOnDelete` | |
| `created_at` | timestamp | Sin `updated_at` — es un log de sólo inserción. Índices: `created_at`, `resultado`, `operacion`. |

**Retención (FR-022)**: purga oportunista de registros con más de 30 días o por encima de 5.000 filas,
lo que se alcance primero — mismo criterio y mismo mecanismo (sin tarea programada dedicada) que
`ml_operaciones_log` (research.md §R8).

---

## Diagrama de relaciones

```text
tn_configuracion (registro único)
    │
    │ actualizada_por → users.id
    │
tn_operaciones_log
    │
    │ usuario_id → users.id
```

Sin relaciones entre `tn_configuracion`/`tn_operaciones_log` y las tablas `ml_*` de Mercado Libre: son
integraciones independientes que sólo comparten el patrón de diseño, no datos.

---

## Notas de implementación para Laravel

- `TiendanubeConfiguracion::actual()`: idéntico patrón a `MercadoLibreConfiguracion::actual()`
  (`firstOrCreate` implícito con `id = 1`).
- `$hidden = ['access_token']` y `$casts = ['access_token' => 'encrypted', 'modo_solo_lectura' => 'boolean', 'credenciales_guardadas_en' => 'datetime', 'ultima_verificacion_en' => 'datetime']`.
- Enum propio `App\Enums\Tiendanube\EstadoConexion` (no reutilizar el de Mercado Libre: no incluye
  `PendienteConfirmacion`, ver research.md §R6).
- `TiendanubeOperacionLog::registrar(array $datos)`: mismo método estático que
  `MercadoLibreOperacionLog::registrar()`, mismas columnas.
