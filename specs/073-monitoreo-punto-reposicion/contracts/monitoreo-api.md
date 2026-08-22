# Contrato: endpoints de Monitoreo

**Feature**: 073-monitoreo-punto-reposicion

Todos bajo el prefijo `monitoreo` y el nombre de ruta `monitoreo.*`, dentro de `auth`.
Lectura → `permiso:monitoreo.ver`. Escritura → `permiso:monitoreo.gestionar`.

Los endpoints de tabla devuelven el formato de **Yajra DataTables server-side**
(`draw`, `recordsTotal`, `recordsFiltered`, `data[]`) y aceptan sus parámetros estándar
(`start`, `length`, `search[value]`, `order[...]`). Abajo sólo se documenta la forma de cada fila.

---

## Pantalla

### `GET monitoreo` → `monitoreo.index`

Shell Blade. Extiende `layouts.default`, pagelevel `monitoreo`. No trae datos de tablas: cada bloque
los pide por AJAX.

Acepta `?bloque=publicaciones|reponer|riesgo-ml|sin-stock|ordenes` para abrir posicionado en un
bloque (FR-028), navegando desde el desplegable de la barra superior.

---

## Lectura

### `GET monitoreo/pulso` → `monitoreo.pulso`

Estado general. Es el único que no es una tabla.

```json
{
  "servidor": "21/08/2026 14:32:05",
  "sincronizacion": {
    "ordenes": { "hace": 3, "resultado": "ok: 2 ordenes", "alerta": false },
    "stock":   { "hace": 47, "resultado": null, "alerta": true }
  },
  "soloLectura": false,
  "creacionAutomatica": true,
  "ultimoMovimiento": "21/08 14:12",
  "conteos": {
    "publicacionesFallando": 4,
    "aReponer": 37,
    "riesgoMl": 12,
    "sinStock": 88,
    "ordenesSinVenta": 2,
    "publicaciones": 1340
  }
}
```

- `hace`: minutos desde la última corrida. `null` = nunca corrió.
- `alerta`: `true` si `hace` es `null` o mayor a 15 (FR-014).

---

### `GET monitoreo/publicaciones` → `monitoreo.publicaciones` *(DataTables)*

Publicaciones de Mercado Libre que no logran actualizar su stock (FR-016). Fuente:
`ml_publicacion_producto` con `stock_error IS NOT NULL`.

```json
{
  "item": "MLA123456789",
  "titulo": "Grifería monocomando de cocina",
  "productoId": 4821,
  "stock": 3,
  "publicado": 0,
  "intentos": 7,
  "desde": "19/08 09:41",
  "error": "item.status under_review",
  "bloqueada": true,
  "moderacion": true
}
```

- `moderacion: true` cuando el error contiene `under_review` o `forbidden` → la fila se marca como
  "sin acción posible desde el CRM" y **no** ofrece Destrabar (FR-017, historia 1 escenario 2).
- Orden por defecto: `intentos` descendente.

---

### `GET monitoreo/reponer` → `monitoreo.reponer` *(DataTables)*

Productos a reponer: stock en el **depósito Local** ≤ `punto_reposicion` (FR-018).

```json
{
  "id": 4821,
  "nombre": "Grifería monocomando de cocina",
  "codigo": "GRI-0231",
  "stockLocal": 2,
  "stockFull": 50,
  "puntoReposicion": 6,
  "faltan": 4,
  "proveedor": "Sanitarios del Norte"
}
```

`stockFull` se muestra para que se entienda por qué este producto está acá y **no** en riesgo de
publicación (FR-019a).

- `faltan` = `puntoReposicion - stockLocal` (nunca negativo).
- Excluye servicios, inactivos y productos sin punto de reposición o con punto en 0 (FR-011a).

---

### `GET monitoreo/riesgo-ml` → `monitoreo.riesgoMl` *(DataTables)*

Productos publicados en Mercado Libre con stock **Local + Full** ≤ `punto_reposicion` (FR-019).

> **No** es "el depósito de Mercado Libre": `ml_configuracion.deposito_id` **es** el Local, así que
> ese criterio habría devuelto la misma lista que `/reponer`. Lo que distingue este bloque es que
> suma **Full** — donde el producto sí tiene de dónde venderse aunque el Local esté vacío.

```json
{
  "id": 4821,
  "nombre": "Grifería monocomando de cocina",
  "item": "MLA123456789",
  "stockLocal": 1,
  "stockFull": 0,
  "stockVendible": 1,
  "puntoReposicion": 6,
  "porDia": 0.79,
  "dias": 1.3
}
```

- `stockVendible` = `stockLocal + stockFull`, que es contra lo que se compara el punto de reposición.
- `porDia`: unidades vendidas por día en los últimos 14 días. `dias`: `stockVendible / porDia`,
  `null` si no rota.
- Orden por defecto: `dias` ascendente (lo que se agota antes, primero). Los `null` van al final.

---

### `GET monitoreo/sin-stock` → `monitoreo.sinStock` *(DataTables)*

Productos publicados en Mercado Libre sin stock **ni en el depósito de Mercado Libre ni en Full**
(FR-020). Informativo: no vende, pero no es una falla. **No** depende del punto de reposición.

```json
{ "id": 4821, "nombre": "…", "item": "MLA123456789", "local": 0, "full": 0 }
```

---

### `GET monitoreo/ordenes` → `monitoreo.ordenes` *(DataTables)*

Órdenes de Mercado Libre sin venta asociada, con su motivo en castellano (FR-020).

```json
{
  "orden": "2000012345678901",
  "comprador": "JUANP1982",
  "total": 45300.00,
  "cuando": "21/08 11:02",
  "estado": "requiere_atencion",
  "causa": "La publicación no está vinculada a ningún producto",
  "detalle": null,
  "accionable": true,
  "mediacion": false,
  "fraude": false
}
```

- `accionable: true` sólo cuando `estado = requiere_atencion` — el resto es el curso normal.

---

### `GET monitoreo/ventas` → `monitoreo.ventas`

Últimas 6 ventas de integraciones con sus movimientos de stock, para ver la cadena de punta a punta
(FR-020). No es DataTables: lista fija y corta.

```json
[{ "id": 812, "origen": "mercadolibre", "total": 45300.00, "deposito": "Local",
   "cuando": "21/08 11:05", "movimientos": 2, "neto": -3 }]
```

---

### `GET monitoreo/resumen` → `monitoreo.resumen`

**El endpoint que se llama desde todas las pantallas del sistema** (barra superior). Alimenta el
desplegable de Monitoreo y la campanita (FR-026, FR-031, FR-033). Debe ser barato: conteos + una
muestra de 5 por bloque.

```json
{
  "conteos": { "publicacionesFallando": 4, "aReponer": 37, "sincronizacionAlerta": true },
  "muestra": {
    "publicaciones": [{ "item": "MLA123", "titulo": "…", "moderacion": false }],
    "reponer": [{ "id": 4821, "nombre": "…", "stockLocal": 2, "puntoReposicion": 6 }]
  },
  "sincronizacion": {
    "ordenes": { "hace": 3, "alerta": false },
    "stock":   { "hace": 47, "alerta": true }
  },
  "notificaciones": {
    "sinLeer": 12,
    "items": [
      { "clave": "reposicion:4821", "tipo": "reposicion",
        "titulo": "Grifería monocomando de cocina",
        "detalle": "Quedan 2, el punto de reposición es 6",
        "cuando": "21/08 14:12", "leida": false, "url": "/productos?producto_id=4821" },
      { "clave": "ml_stock:MLA123456789", "tipo": "ml_stock",
        "titulo": "Grifería monocomando de cocina",
        "detalle": "item.status under_review",
        "cuando": "19/08 09:41", "leida": true, "url": "/monitoreo?bloque=publicaciones" }
    ]
  }
}
```

- `sinLeer` cuenta sólo los `items` con `leida: false` **de ese usuario** (FR-033).
- `items` se acota a las 20 alertas vigentes más recientes; `sinLeer` es el total real, no el de la
  muestra.
- Sin `permiso:monitoreo.ver` el endpoint responde 403 y el Blade ni siquiera renderiza los widgets
  (FR-025, FR-036) — no hay llamada.
- Efecto lateral: limpia las filas de `notificaciones_leidas` del usuario cuya clave ya no está
  vigente (data-model §2).

---

## Escritura

Todas requieren `permiso:monitoreo.gestionar` y responden `{ "ok": bool, "mensaje": string }`, que
la vista muestra con Toastr (FR-023). Ninguna recarga la página (FR-022).

### `POST monitoreo/destrabar` → `monitoreo.destrabar`

Body: `{ "ml_item_id": "MLA123456789" }`. Marca la publicación como pendiente para que el cron le
empuje el stock en la próxima corrida.

### `POST monitoreo/reactivar` → `monitoreo.reactivar`

Body: `{ "ml_item_id": "MLA123456789" }`. Limpia el bloqueo por reintentos fallidos y el error, y la
vuelve a encolar (historia 1 escenario 3).

### `POST monitoreo/sincronizar` → `monitoreo.sincronizar`

Body: `{ "que": "ordenes" | "stock" }`. Fuerza una corrida.

### `POST monitoreo/punto-reposicion` → `monitoreo.puntoReposicion`

Edición del punto de reposición desde el panel, sin salir de la pantalla (FR-003).

Body: `{ "producto_id": 4821, "punto_reposicion": 6 }`

- Validación `nullable|integer|min:0`. Un valor inválido responde **422** con el formato de errores
  de Laravel, que el modal muestra sin recargar y conservando el valor anterior (FR-004).
- No exige `productos.editar` — alcanza con `monitoreo.gestionar` (FR-013, clarificación 5).
- Respuesta en éxito: `{ "ok": true, "mensaje": "…", "fila": { …misma forma que en /reponer… } }`
  para que la fila se reevalúe en el lugar. Si el producto deja de estar en punto de reposición,
  `fila` es `null` y la fila se saca de la tabla.

### `POST monitoreo/notificaciones/leer` → `monitoreo.notificaciones.leer`

Marca notificaciones como leídas para el usuario autenticado (FR-034).

Body: `{ "claves": ["reposicion:4821"] }` o `{ "claves": [...], "todas": true }`.

`todas: true` marca únicamente las claves que el cliente envía (las que el usuario tenía a la vista),
no "todo lo que exista en el servidor en este instante" — FR-036a.

Requiere sólo `permiso:monitoreo.ver` — marcar algo como leído es una acción sobre el propio estado
de lectura del usuario, no sobre la integración.

Respuesta: `{ "ok": true, "sinLeer": 11 }`.
