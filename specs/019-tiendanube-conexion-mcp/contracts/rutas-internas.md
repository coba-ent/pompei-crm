# Contrato — Rutas internas del CRM

**Feature**: `019-tiendanube-conexion-mcp`

Todas bajo el middleware `auth` + `permiso:configuracion.funciones` (sin cambios respecto de spec
015/011). Reemplaza las rutas de credenciales manuales de `015-tiendanube-conexion/contracts/rutas-internas.md`.

---

## Configuración de Tiendanube

### `GET /configuracion/tiendanube` → `configuracion.tiendanube.index`

Sin cambios de contrato — renderiza la pantalla de configuración, estado e historial.

### `GET /configuracion/tiendanube/estado` → `configuracion.tiendanube.estado`

**200** — no configurada:
```json
{ "ok": true, "estado": "no_configurada", "configuracion": null }
```

**200** — conectada:
```json
{
  "ok": true,
  "estado": "conectada",
  "configuracion": {
    "conectada_en": "2026-07-29T10:00:00-03:00",
    "scopes_otorgados": "read_products write_products read_orders write_orders read_customers write_customers read_content write_content read_coupons write_coupons write_scripts write_shipping",
    "productos_total": 101,
    "modo_solo_lectura": false
  }
}
```

**200** — caída:
```json
{
  "ok": true,
  "estado": "caida",
  "configuracion": { "conectada_en": "2026-07-29T10:00:00-03:00", "productos_total": 101, "modo_solo_lectura": false },
  "ultimo_error": "La credencial fue rechazada por Tiendanube. Volvé a conectar."
}
```

**Ninguna respuesta incluye jamás `client_secret` ni `access_token`.**

### `GET /configuracion/tiendanube/conectar` → `configuracion.tiendanube.conectar`

Auto-registra el cliente OAuth si todavía no existe (research.md R1), arma la URL de `/authorize` con
PKCE + `state` de un solo uso (guardado en sesión con vencimiento corto, ej. 10 minutos), y **redirige**
al navegador a `admin-mcp.tiendanube.com/authorize`.

**302** → `https://admin-mcp.tiendanube.com/authorize?response_type=code&client_id=...&redirect_uri=...&scope=...&state=...&code_challenge=...&code_challenge_method=S256`

### `GET /configuracion/tiendanube/callback` → `configuracion.tiendanube.callback`

Recibe la redirección de Tiendanube tras la aprobación del usuario.

**Request** (query string, enviado por Tiendanube): `?code=...&state=...` o `?error=...&error_description=...`

**Éxito** — valida `state`, intercambia `code` por token (`POST /token` con `code_verifier`), invoca
`list_products` (`page_size: 1`, FR-003a) para verificar, guarda `access_token`/`scopes_otorgados`/
`productos_total`/`conectada_en`, deja `estado = conectada`, redirige a
`configuracion.tiendanube.index` con notificación de éxito.

**Error** (`state` inválido, código ya usado, error de Tiendanube, o verificación FR-003a fallida):
redirige a `configuracion.tiendanube.index` con notificación de error describiendo el motivo; la conexión
**no** queda como "Conectada".

### `POST /configuracion/tiendanube/desconectar` → `configuracion.tiendanube.desconectar`

Sin cambios de contrato respecto de spec 015 (mismo verbo, misma confirmación previa en la interfaz):
borra `access_token`, `client_secret` **se conserva** (permite reconectar sin volver a auto-registrar el
cliente OAuth), deja `estado = no_configurada`, registra la operación en el historial.

**200**:
```json
{ "ok": true, "mensaje": "Tiendanube desconectado." }
```

### `PATCH /configuracion/tiendanube/modo-solo-lectura` → `configuracion.tiendanube.modoSoloLectura`

Sin cambios respecto de spec 015.

### `GET /configuracion/tiendanube/historial` → `configuracion.tiendanube.historial`

Sin cambios respecto de spec 015 (DataTables server-side, mismo formato de columnas).

---

## Rutas que se eliminan (existían en spec 015)

- `PUT /configuracion/tiendanube/credenciales` (`configuracion.tiendanube.credenciales`) — ya no hay
  formulario de credenciales manuales.
- `POST /configuracion/tiendanube/probar` (`configuracion.tiendanube.probar`) — la verificación ahora
  ocurre una sola vez, dentro de `callback()` (FR-003a), no como acción repetible separada.

---

## Guard de la función avanzada "Tiendanube"

Sin cambios respecto de spec 015 (FR-006b): toda llamada de `ClienteTiendanube` verifica primero que
`FuncionAvanzada::where('clave', 'tiendanube')->value('activa')` sea verdadero, salvo
`omitir_guard_funcion: true` (reservado para la verificación FR-003a dentro del propio flujo de
conexión).
