# Contrato — Rutas internas del CRM

**Feature**: `022-tiendanube-conexion-rest`

Todas bajo el mismo middleware `auth` + `permiso:configuracion.funciones` que ya usa la conexión MCP
(spec 019), dentro del mismo `prefix('tiendanube')->name('tiendanube.')` de `routes/web.php` — nombres de
ruta nuevos, sin pisar ni modificar los existentes (`conectar`, `callback`, `estado`, `desconectar` siguen
siendo exclusivos de spec 019).

---

## Conexión REST (nueva, aislada)

### `GET /configuracion/tiendanube/conectar-rest` → `configuracion.tiendanube.conectarRest`

Arma la URL de autorización clásica con un `state` de un solo uso (guardado en sesión, vencimiento 10
minutos, clave de sesión distinta a la que usa spec 019 para no pisarla) y **redirige** al navegador.

**302** → `https://www.tiendanube.com/apps/{app_id}/authorize?state=...`

### `GET /configuracion/tiendanube/callback-rest` → `configuracion.tiendanube.callbackRest`

Recibe la redirección de Tiendanube tras la aprobación. Ésta es la URL que el usuario debe cargar a mano en
el Partner Portal como `redirect_uri` de la Application (research.md R2) — hoy apunta a una pantalla del
propio Partner Portal.

**Request** (query string, enviado por Tiendanube): `?code=...&state=...` o
`?error=access_denied&error_description=...`

**Éxito**: valida `state`, intercambia `code` por `access_token`/`store_id`/`scope`
(`POST https://www.tiendanube.com/apps/authorize/token`), invoca `GET /{store_id}/store` (FR-005) para
verificar, guarda `access_token` cifrado + `store_id` + `scopes_otorgados` + `tienda_nombre` +
`tienda_dominio` + `conectada_en` en `tn_conexion_rest`, deja `estado = conectada`, redirige a
`configuracion.tiendanube.index` con notificación de éxito.

**Error** (`state` inválido, código ya usado/vencido, error de Tiendanube, o verificación FR-005 fallida):
redirige a `configuracion.tiendanube.index` con notificación de error describiendo el motivo; este apartado
**no** queda como "Conectado". En ningún caso se modifica `tn_configuracion` (conexión MCP).

### `GET /configuracion/tiendanube/estado-rest` → `configuracion.tiendanube.estadoRest`

**200** — no configurada:
```json
{ "ok": true, "estado": "no_configurada", "conexion": null }
```

**200** — conectada:
```json
{
  "ok": true,
  "estado": "conectada",
  "conexion": {
    "conectada_en": "2026-07-31T10:00:00-03:00",
    "scopes_otorgados": "read_products,write_products,read_orders,read_discounts,write_discounts",
    "tienda_nombre": "Pompei Sanitarios",
    "tienda_dominio": "pompeisanitarios.com"
  }
}
```

**200** — caída:
```json
{
  "ok": true,
  "estado": "caida",
  "conexion": { "conectada_en": "2026-07-31T10:00:00-03:00", "tienda_nombre": "Pompei Sanitarios", "tienda_dominio": "pompeisanitarios.com" },
  "ultimo_error": "La credencial fue rechazada por Tiendanube. Volvé a conectar."
}
```

**Ninguna respuesta incluye jamás `access_token`** (no hay `client_secret` guardado en esta tabla — vive en
`.env`).

### `POST /configuracion/tiendanube/desconectar-rest` → `configuracion.tiendanube.desconectarRest`

Con confirmación previa en la interfaz (mismo patrón que spec 019): limpia `access_token`/`store_id`/
`scopes_otorgados`/`tienda_nombre`/`tienda_dominio`/`conectada_en` de `tn_conexion_rest`, deja
`estado = no_configurada`, registra la operación en `tn_rest_operaciones_log`. No toca `tn_configuracion` ni
el historial de la conexión MCP.

**200**:
```json
{ "ok": true, "mensaje": "Conexión REST de Tiendanube desconectada." }
```

---

## Explícitamente fuera de este contrato

- No hay ruta de historial dedicada en esta spec — el volumen esperado (conectar/verificar/desconectar,
  sin operaciones repetidas de negocio) no justifica una pantalla de historial propia todavía; queda para
  cuando (si) se migre el resto de la integración. `tn_rest_operaciones_log` igual se llena desde ya, para
  no perder trazabilidad.
- Ninguna ruta de esta spec expone ni acepta datos de productos, pedidos, stock o clientes — sólo conexión.

## Guard de la función avanzada "Tiendanube"

Se reutiliza el mismo guard que spec 019 (`FuncionAvanzada::where('clave', 'tiendanube')->value('activa')`)
antes de permitir `conectarRest`/`callbackRest` — misma función avanzada, ambas conexiones dependen de que
esté activada.
