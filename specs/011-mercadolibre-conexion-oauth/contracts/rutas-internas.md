# Contrato — Rutas internas del CRM

**Feature**: `011-mercadolibre-conexion-oauth`

Todas bajo el middleware `auth` + `permiso:configuracion.funciones` (permiso ya existente, ver R10).
Las respuestas JSON siguen la convención del proyecto: `{ ok: bool, mensaje: string, ...datos }`, con
errores de validación en formato estándar de Laravel (422 con `errors`).

**Ninguna de estas respuestas incluye jamás `client_secret`, `access_token` ni `refresh_token`.**

---

## Funciones Avanzadas

### `GET /configuracion/funciones` → `configuracion.funciones.index`

Renderiza la pantalla con las 10 tarjetas. Vista Blade, no JSON.

### `PATCH /configuracion/funciones/{funcion}/estado` → `configuracion.funciones.estado`

Alterna el toggle de una función.

**Request**: `{ "activa": true }`

**200**:
```json
{ "ok": true, "mensaje": "Función activada.", "funcion": { "clave": "mercadolibre", "activa": true } }
```

**422** — función no disponible:
```json
{ "ok": false, "mensaje": "Esta función todavía no está disponible en el CRM." }
```

**Regla**: el servidor valida `disponible = true` antes de permitir la activación. Deshabilitar el
control en la interfaz no alcanza (FR-004).

---

## Configuración de Mercado Libre

### `GET /configuracion/mercadolibre` → `configuracion.mercadolibre.index`

Renderiza la pantalla de configuración, estado e historial.

### `GET /configuracion/mercadolibre/estado` → `configuracion.mercadolibre.estado`

Devuelve el estado actual para refrescar el panel sin recargar la página.

**200** — conectada:
```json
{
  "ok": true,
  "estado": "conectada",
  "configuracion": { "client_id": "1234567890123456", "site_id": "MLA", "secret_cargado": true, "modo_solo_lectura": false },
  "cuenta": {
    "ml_user_id": 123456789,
    "nickname": "TESTUSER1234",
    "email": "test@testuser.com",
    "tipo_cuenta": "normal",
    "site_id": "MLA",
    "vinculada_en": "2026-07-27T14:32:10-03:00",
    "token_expira_en": "2026-07-27T20:32:10-03:00",
    "ultimo_refresh_en": "2026-07-27T14:32:10-03:00"
  },
  "redirect_uri": "https://midominio.cloud/configuracion/mercadolibre/callback"
}
```

**200** — no configurada:
```json
{
  "ok": true,
  "estado": "no_configurada",
  "configuracion": { "client_id": null, "site_id": "MLA", "secret_cargado": false, "modo_solo_lectura": false },
  "cuenta": null,
  "redirect_uri": "https://midominio.cloud/configuracion/mercadolibre/callback"
}
```

**200** — caída:
```json
{
  "ok": true,
  "estado": "caida",
  "cuenta": { "nickname": "TESTUSER1234", "ultimo_error": "La autorización expiró. Volvé a conectar la cuenta." },
  "...": "..."
}
```

**Nota**: `secret_cargado` es un booleano — el valor del secreto **nunca** viaja (FR-010).

**Advertencias de entorno** (FR-011a): cuando la dirección de retorno calculada no coincide con el host
por el que se accede, o no usa conexión segura, la respuesta incluye:

```json
{
  "advertencias": [
    "La URL de retorno usa el dominio 'localhost' pero estás accediendo desde 'midominio.cloud'. Revisá APP_URL: la vinculación va a fallar.",
    "La URL de retorno no usa conexión segura. Mercado Libre sólo acepta direcciones cifradas."
  ]
}
```

### `GET /configuracion/mercadolibre/pendiente` → `configuracion.mercadolibre.pendiente`

Devuelve la autorización retenida a la espera de confirmación, si existe (FR-022).

**200** — hay una pendiente:
```json
{
  "ok": true,
  "pendiente": {
    "cuenta_actual":  { "ml_user_id": 111, "nickname": "CUENTA_VIEJA" },
    "cuenta_nueva":   { "ml_user_id": 222, "nickname": "CUENTA_NUEVA", "email": "n@x.com", "tipo_cuenta": "normal" },
    "expira_en": "2026-07-27T14:47:10-03:00"
  }
}
```

**200** — no hay ninguna: `{ "ok": true, "pendiente": null }`

### `POST /configuracion/mercadolibre/pendiente/confirmar` → `configuracion.mercadolibre.confirmarReemplazo`

Sustituye la cuenta vigente por la retenida. **Operación atómica**: activa la nueva y desconecta la
anterior en la misma transacción, para que nunca haya dos cuentas conectadas.

**200**:
```json
{ "ok": true, "mensaje": "Cuenta reemplazada. Ahora estás operando con CUENTA_NUEVA.", "estado": "conectada" }
```

**409** — la autorización retenida venció:
```json
{ "ok": false, "mensaje": "La autorización expiró. Volvé a conectar la cuenta." }
```

### `DELETE /configuracion/mercadolibre/pendiente` → `configuracion.mercadolibre.descartarPendiente`

Descarta la autorización retenida. La cuenta vigente queda intacta.

**200**:
```json
{ "ok": true, "mensaje": "Se descartó la conexión con la otra cuenta. Seguís operando con CUENTA_VIEJA." }
```

### `PUT /configuracion/mercadolibre/configuracion` → `configuracion.mercadolibre.guardar`

**Request**:
```json
{ "client_id": "1234567890123456", "client_secret": "abc...", "site_id": "MLA" }
```

`client_secret` es opcional al editar: si viene vacío, se conserva el guardado.

**Validación**:

| Campo | Reglas |
|---|---|
| `client_id` | requerido, numérico, 8-32 dígitos |
| `client_secret` | opcional al editar / requerido en el alta, string, 16-128 caracteres |
| `site_id` | requerido, en la lista de sitios soportados |

**200**:
```json
{ "ok": true, "mensaje": "Configuración guardada.", "requiere_revinculacion": false }
```

**200** con advertencia — se cambiaron credenciales con cuenta vinculada:
```json
{ "ok": true, "mensaje": "Configuración guardada. La cuenta vinculada quedó invalidada, volvé a conectarla.", "requiere_revinculacion": true }
```

**422**: errores por campo, formato estándar de Laravel.

### `PATCH /configuracion/mercadolibre/modo-solo-lectura` → `configuracion.mercadolibre.modoSoloLectura`

**Request**: `{ "activo": true }`

**200**:
```json
{ "ok": true, "mensaje": "Modo sólo lectura activado. Las escrituras hacia Mercado Libre quedan bloqueadas.", "modo_solo_lectura": true }
```

### `POST /configuracion/mercadolibre/probar` → `configuracion.mercadolibre.probar`

Ejecuta una lectura real contra el proveedor (`GET /users/me`) y devuelve el resultado.

**200** — éxito:
```json
{ "ok": true, "mensaje": "Conexión correcta con la cuenta TESTUSER1234.", "estado": "conectada" }
```

**200** — falla (no es un error del CRM, es un diagnóstico):
```json
{ "ok": false, "mensaje": "La autorización expiró. Volvé a conectar la cuenta.", "estado": "caida" }
```

**409** — sin configuración:
```json
{ "ok": false, "mensaje": "Cargá primero las credenciales de la aplicación." }
```

### `DELETE /configuracion/mercadolibre/desconectar` → `configuracion.mercadolibre.desconectar`

**200**:
```json
{ "ok": true, "mensaje": "Cuenta desconectada.", "estado": "desconectada" }
```

Borra credenciales; conserva datos de cuenta e historial (FR-027).

### `GET /configuracion/mercadolibre/operaciones` → `configuracion.mercadolibre.operaciones`

Historial paginado del lado del servidor (formato DataTables). Filtros: `desde`, `hasta`, `resultado`.

**200**:
```json
{
  "draw": 1, "recordsTotal": 143, "recordsFiltered": 12,
  "data": [
    { "created_at": "2026-07-27 14:32:10", "operacion": "vincular_cuenta", "sentido": "lectura",
      "resultado": "exito", "codigo_http": 200, "duracion_ms": 412, "mensaje_error": null }
  ]
}
```

---

## Flujo OAuth

### `GET /configuracion/mercadolibre/conectar` → `configuracion.mercadolibre.conectar`

**No devuelve JSON**: crea la solicitud de vinculación y redirige (302) al dominio de autorización de
Mercado Libre.

**409** si la configuración está incompleta — redirige de vuelta con un mensaje de error.

### `GET /configuracion/mercadolibre/callback` → `configuracion.mercadolibre.callback`

Retorno del proveedor. **Ruta pública dentro de `auth`** (el usuario vuelve con su sesión activa).
Es la dirección que debe registrarse en el DevCenter.

**Parámetros recibidos**: `code` + `state`, o `error` + `error_description`.

**Comportamiento**: siempre redirige a la pantalla de configuración con un mensaje (no renderiza vista
propia). Los casos:

| Situación | Resultado |
|---|---|
| `code` + `state` válidos, canje exitoso, **sin cuenta previa** | Redirige con éxito; estado `conectada` |
| `code` + `state` válidos, canje exitoso, **cuenta distinta a la vigente** | Redirige a la pantalla con la confirmación de reemplazo abierta; la nueva queda en `pendiente_confirmacion` y la vigente **sigue operando** |
| `code` + `state` válidos, canje exitoso, **misma cuenta que la vigente** | Actualiza los tokens de la cuenta existente; estado `conectada`. No pide confirmación (no hay reemplazo) |
| El usuario canceló (`error=access_denied`) | Redirige con aviso; estado sin cambios |
| `state` inexistente, vencido o ya consumido | Redirige con error; se registra como incidente de seguridad |
| Cuenta de sitio distinto al configurado | Redirige con error explicando el motivo; no se persiste nada |
| Canje rechazado por el proveedor | Redirige con el motivo traducido a lenguaje comprensible |
| Retorno repetido (`state` ya consumido) | Redirige informando que la vinculación ya se completó; **no** rompe la conexión existente |

**Traducción de errores del proveedor** (FR-011, SC-006) — el usuario nunca ve el error crudo:

| Error del proveedor | Mensaje mostrado |
|---|---|
| `invalid_client` | "El App ID o la clave secreta no son correctos. Revisá las credenciales." |
| `invalid_grant` | "La autorización expiró o ya fue usada. Volvé a intentar la conexión." |
| `redirect_uri` no coincide | "La URL de retorno no coincide con la registrada en Mercado Libre. Copiá la que muestra esta pantalla y pegala en el DevCenter." |
| `invalid_scope` | "La aplicación no tiene los permisos necesarios. Verificá que tenga lectura, escritura y acceso prolongado." |
