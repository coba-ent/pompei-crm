# Phase 1 — Data Model: Conexión Tiendanube vía OAuth/MCP

**Feature**: `019-tiendanube-conexion-mcp` | **Fecha**: 2026-07-29

Se **modifica** `tn_configuracion` (no se crea tabla nueva). `tn_operaciones_log` no cambia — ver
`specs/015-tiendanube-conexion/data-model.md` §2 para su esquema completo, que sigue vigente tal cual.

---

## 1. `tn_configuracion` (modificada)

Registro único (single-tenant, se sigue accediendo por `TiendanubeConfiguracion::actual()`).

### Columnas que se agregan

| Columna | Tipo | Notas |
|---|---|---|
| `client_id` | string(100), nullable | Id del cliente OAuth auto-registrado contra `admin-mcp.tiendanube.com` (`POST /register`). No es secreto, pero se trata igual con cuidado. |
| `client_secret` | text, nullable, **cifrado** (`encrypted`) | Secreto del cliente OAuth auto-registrado. `$hidden` en el modelo. |
| `scopes_otorgados` | string(255), nullable | Scopes efectivamente devueltos por `/token` (research.md R5), separados por espacio, tal como los informa Tiendanube. |
| `productos_total` | unsignedInteger, nullable | Cantidad total de productos informada por la verificación FR-003a (`list_products`, `pagination.total_elements`) al momento de conectar. Se usa como dato a mostrar en el panel, no se mantiene sincronizado en vivo. |
| `conectada_en` | timestamp, nullable | Fecha en que se completó el intercambio de token + verificación exitosa (reemplaza el uso que spec 015 le daba a `credenciales_guardadas_en`). |

> ✅ **Post-deploy 29/07/2026**: se agregó `token_expira_en` (timestamp, nullable —
> `conectada_en` + `expires_in`) en la migración `2026_08_07_060001`, a pedido del usuario, para mostrar
> los días restantes de vigencia del token en el panel de estado. No estaba en el diseño original de
> esta spec. Ver también el hallazgo de que `/authorize` sólo acepta `redirect_uri` loopback
> (`docs/documentacion_principal_crm.md` §5.3) — el botón "Conectar con Tiendanube" quedó deshabilitado
> en la UI por ese motivo.

### Columnas que se quitan (existían en spec 015, ya no aplican)

- `store_id` — no hace falta: el OAuth ata la conexión a la cuenta que el usuario apruebe en el navegador.
- `nombre_tienda`, `dominio`, `pais`, `moneda` — no hay tool de "info de tienda" que los provea
  (research.md R4); se reemplazan conceptualmente por `productos_total`.
- `ultima_verificacion_en` — se reemplaza por `conectada_en` (la verificación ya no es una acción
  separada "Probar conexión": ocurre una sola vez, dentro del callback OAuth, FR-003a).
- `credenciales_guardadas_en` — ya no aplica (no hay credenciales cargadas a mano).

### Columnas que se mantienen sin cambios

`access_token` (cifrado, ahora contiene el token OAuth en vez del token manual), `estado`,
`ultimo_error`, `modo_solo_lectura`, `actualizada_por`, `created_at`/`updated_at`.

### `estado` — mismo criterio derivado que spec 015, valores ajustados

- `no_configurada`: se calcula cuando `access_token` está vacío (ya no depende de `store_id`, que no
  existe). Cubre tanto "nunca conectado" como "desconectado" — spec.md FR-006 unifica ambos casos porque
  no hay datos parciales que distinguirlos (Clarifications, decisión de diseño explícita).
- `conectada`: se persiste cuando el intercambio de token **y** la verificación FR-003a son exitosos.
- `caida`: se persiste cuando una llamada al servidor MCP es rechazada por token inválido/revocado
  (401).

**Nota de migración**: la migración que ajusta esta tabla (`2026_08_06_060001_...`) corre sobre una tabla
que en producción nunca llegó a tener datos reales (spec 015 quedó inutilizable antes de que el cliente
llegara a conectar nada) — no hace falta backfill de datos existentes, sólo agregar/quitar columnas.

---

## 2. `tn_operaciones_log` — sin cambios

Ver `specs/015-tiendanube-conexion/data-model.md` §2. El campo `operacion` ahora contiene el nombre de la
tool MCP invocada (`list_products`, `update_stock_and_price`, `registrar_cliente_oauth`,
`intercambiar_token`, etc.) en vez de un endpoint REST — mismo tipo de dato (`string(100)`), sin cambio
de esquema.

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

Sin cambios respecto de spec 015.

---

## Notas de implementación para Laravel

- `TiendanubeConfiguracion::actual()`: mismo patrón, sin cambios en la firma.
- `$hidden = ['access_token', 'client_secret']` (se agrega `client_secret` a la lista ya existente).
- `$casts`: se agrega `'client_secret' => 'encrypted'`, `'conectada_en' => 'datetime'`; se quitan los
  casts de las columnas eliminadas.
- `estaCompleta()`: se redefine como `filled($this->getRawOriginal('access_token'))` — ya no depende de
  `store_id` (mismo criterio de "presencia, no legibilidad" ya usado en spec 015 para evitar
  `DecryptException` al sólo querer saber si hay algo cargado).
