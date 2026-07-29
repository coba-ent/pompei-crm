# Contrato — Rutas internas del CRM

**Feature**: `015-tiendanube-conexion`

Todas bajo el middleware `auth` + `permiso:configuracion.funciones` (mismo permiso ya reutilizado por
Mercado Libre, ver research.md §R10). Las respuestas JSON siguen la misma convención del proyecto:
`{ ok: bool, mensaje: string, ...datos }`, con errores de validación en formato estándar de Laravel
(422 con `errors`).

**Ninguna de estas respuestas incluye jamás `access_token`.**

No hay rutas de callback/retorno de autorización (a diferencia de
`011-mercadolibre-conexion-oauth/contracts/rutas-internas.md`): sin OAuth no hay redirect que recibir.

---

## Configuración de Tiendanube

### `GET /configuracion/tiendanube` → `configuracion.tiendanube.index`

Renderiza la pantalla de configuración, estado e historial.

### `GET /configuracion/tiendanube/estado` → `configuracion.tiendanube.estado`

Devuelve el estado actual para refrescar el panel sin recargar la página.

**200** — conectada:
```json
{
  "ok": true,
  "estado": "conectada",
  "configuracion": {
    "store_id": "1234567",
    "token_cargado": true,
    "modo_solo_lectura": false,
    "credenciales_guardadas_en": "2026-07-29T10:00:00-03:00"
  },
  "tienda": {
    "nombre": "Mi Tienda",
    "dominio": "mitienda.mitiendanube.com",
    "pais": "AR",
    "moneda": "ARS",
    "ultima_verificacion_en": "2026-07-29T10:05:00-03:00"
  }
}
```

**200** — no configurada:
```json
{ "ok": true, "estado": "no_configurada", "configuracion": null, "tienda": null }
```

**200** — caída:
```json
{
  "ok": true,
  "estado": "caida",
  "configuracion": { "store_id": "1234567", "token_cargado": true, "modo_solo_lectura": false },
  "tienda": { "nombre": "Mi Tienda", "dominio": "mitienda.mitiendanube.com", "pais": "AR", "moneda": "ARS" },
  "ultimo_error": "La credencial fue rechazada por Tiendanube. Volvé a cargar el token."
}
```

### `PUT /configuracion/tiendanube/credenciales` → `configuracion.tiendanube.credenciales`

Guarda o reemplaza `store_id` y/o `access_token`. Al menos uno de los dos debe venir en el request; el
que no venga conserva su valor actual (permite, por ejemplo, actualizar sólo el token sin re-tipear el
`store_id`).

**Request**:
```json
{ "store_id": "1234567", "access_token": "xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx" }
```

**200**:
```json
{ "ok": true, "mensaje": "Credenciales guardadas. Probá la conexión para verificarlas." }
```

**422** — campo faltante o formato inválido:
```json
{ "ok": false, "errors": { "store_id": ["El identificador de tienda es obligatorio."] } }
```

**Regla (FR-005)**: si ya existía una conexión en estado `conectada` y se reemplaza el `access_token`,
la respuesta agrega `"advertencia": "La conexión anterior queda invalidada hasta que la vuelvas a probar."`
y el estado pasa a `desconectada` hasta el próximo "Probar conexión" exitoso.

### `POST /configuracion/tiendanube/probar` → `configuracion.tiendanube.probar`

Ejecuta `GET /{store_id}/store` contra la API real (research.md §R4). Actualiza `nombre_tienda`,
`dominio`, `pais`, `moneda`, `estado` y `ultima_verificacion_en` si responde con éxito.

**200** — éxito:
```json
{ "ok": true, "mensaje": "Conexión verificada con éxito.", "estado": "conectada", "tienda": { "nombre": "Mi Tienda", "dominio": "mitienda.mitiendanube.com", "pais": "AR", "moneda": "ARS" } }
```

**200** — rechazo (la operación en sí no es un error HTTP del CRM, es un resultado informativo):
```json
{ "ok": false, "mensaje": "Tiendanube rechazó la credencial. Verificá el token y el identificador de tienda.", "estado": "caida" }
```

**409** — configuración incompleta (FR-004):
```json
{ "ok": false, "mensaje": "Faltan datos: cargá el token de acceso antes de probar la conexión." }
```

### `POST /configuracion/tiendanube/desconectar` → `configuracion.tiendanube.desconectar`

Requiere confirmación en la interfaz antes de invocarse (FR-010). Borra `access_token`, conserva
`nombre_tienda`/`dominio`/`pais`/`moneda` (FR-011), pasa `estado` a `desconectada`.

**200**:
```json
{ "ok": true, "mensaje": "Tiendanube desconectado." }
```

### `PATCH /configuracion/tiendanube/modo-solo-lectura` → `configuracion.tiendanube.modo-solo-lectura`

**Request**: `{ "activo": true }`

**200**:
```json
{ "ok": true, "mensaje": "Modo sólo lectura activado.", "modo_solo_lectura": true }
```

### `GET /configuracion/tiendanube/historial` → `configuracion.tiendanube.historial`

DataTables server-side (`yajra/laravel-datatables-oracle`, ya en el proyecto). Filtrable por fecha y
por `resultado`. Nunca expone `access_token` ni ningún dato sensible (FR-015).

---

## Guard de la función avanzada "Tiendanube"

Mismo patrón que Mercado Libre (spec 011, FR-005b): toda llamada de `ClienteTiendanube` verifica
primero que `FuncionAvanzada::where('clave', 'tiendanube')->value('activa')` sea verdadero, salvo que
se invoque con `omitir_guard_funcion: true` (reservado para "Probar conexión" disparado desde la propia
pantalla de configuración al guardar credenciales, igual que T051a de Mercado Libre).
