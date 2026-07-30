# Contrato de rutas internas — Ventas de Tiendanube (spec 017)

**Spec**: [../spec.md](../spec.md) · **Plan**: [../plan.md](../plan.md)

Todas las rutas viven bajo `Route::middleware('auth')`. Responden **JSON** y las operaciones se ejecutan
sin recarga de página (SC-012). Misma convención de respuesta que Mercado Libre
(`specs/012-ventas-mercadolibre/contracts/rutas-internas.md`):

```jsonc
// Éxito
{ "ok": true, "mensaje": "…", /* datos */ }
// Error de validación → HTTP 422
{ "message": "…", "errors": { "campo": ["…"] } }
// Operación rechazada por regla de negocio → HTTP 409
{ "ok": false, "mensaje": "motivo legible para el usuario" }
```

---

## 1. Ventas de Tiendanube — `/ingresos/tiendanube`

Permiso: `ventas.ver` · Guard adicional: función avanzada `tiendanube` activa (FR-002).

| Método | Ruta | Nombre | Propósito | FR |
|---|---|---|---|---|
| GET | `/` | `ingresos.tiendanube.index` | Pantalla del listado (Blade) | FR-001 |
| GET | `datatable` | `.datatable` | Datos server-side del listado | FR-004, FR-005 |
| POST | `sincronizar` | `.sincronizar` | "Sincronizar ahora" | FR-009 |
| GET | `{orden}` | `.show` | Detalle de la orden (modal) | FR-005 |
| GET | `{orden}/convertir` | `.convertir` | Formulario de Venta precargado (Blade, página completa) | FR-028, FR-029 |
| POST | `{orden}/convertir` | `.convertirGuardar` | Ejecuta la conversión | FR-032, FR-044, FR-046 |

### `POST /sincronizar`

Sin cuerpo.

| Situación | HTTP | Cuerpo |
|---|---|---|
| Correcta | 200 | `{ ok: true, mensaje: "N órdenes nuevas, M actualizadas.", nuevas, actualizadas, convertidas }` |
| Ya hay una corrida en curso | 409 | `{ ok: false, mensaje: "Ya hay una sincronización en curso." }` |
| Función desactivada / modo sólo lectura | 409 | `{ ok: false, mensaje: "<motivo del bloqueo>" }` |
| Conexión caída o sin configurar | 409 | `{ ok: false, mensaje: "…Hace falta reconectar Tiendanube (soporte técnico)." }` |

Cortes verificados **antes** de paginar (FR-017/FR-018), mismo criterio que Mercado Libre. **Corrección
post-019**: la consulta a Tiendanube (`list_orders`) no admite excluir `storefront=meli` en el propio
filtro — no existe el parámetro `channels`. La exclusión es post-proceso, en `TraductorOrdenes`
(research.md R2 corregido).

### `POST /{orden}/convertir`

Cuerpo: la misma forma que `StoreVentaRequest` de una Venta normal, más `submit_token`.

| Situación | HTTP | Cuerpo |
|---|---|---|
| Correcta | 201 | `{ ok: true, mensaje: "Venta … creada con éxito.", redirect: "/ventas/{id}" }` |
| Orden ya convertida (incluye carrera perdida) | 409 | `{ ok: false, mensaje: "Esta orden ya tiene una Venta asociada.", venta_id }` |
| Orden no en estado "Lista para convertir" | 409 | `{ ok: false, mensaje: "…" }` |
| Precondición sin resolver | 409 | `{ ok: false, mensaje: "<motivo>", motivo: "variante_sin_vincular" }` |
| Sin cuenta de Tesorería configurada/activa | 409 | `{ ok: false, mensaje: "No hay una cuenta de Tesorería configurada para Tiendanube. Configurala antes de convertir." }` |
| Validación | 422 | Errores por campo |

**Idempotencia y concurrencia**: candado por orden + índice único sobre `tn_ordenes.venta_id` (FR-032a/
032b, SC-004a), mismo mecanismo que Mercado Libre.

---

## 2. Vinculación de variantes — `/ingresos/tiendanube/vinculaciones`

Permiso: `ventas.ver` · Mismo guard de función activa.

| Método | Ruta | Nombre | Propósito | FR |
|---|---|---|---|---|
| GET | `/` | `.vinculaciones.index` | Pantalla de vinculaciones (Blade) | FR-024 |
| GET | `datatable` | `.vinculaciones.datatable` | Datos server-side | FR-025 |
| POST | `/` | `.vinculaciones.store` | Crear vínculo (también desde el flujo inline) | FR-021, FR-023 |
| PATCH | `{vinculacion}` | `.vinculaciones.update` | Cambiar el producto vinculado | FR-024 |
| DELETE | `{vinculacion}` | `.vinculaciones.destroy` | Eliminar vínculo | FR-026 |

### `POST /` — crear vínculo

```jsonc
{ "variant_id": 123456789, "producto_id": 42, "nombre_variante_tn": "Remera Básica — Talle M" }
```

| Situación | HTTP | Cuerpo |
|---|---|---|
| Correcta | 201 | `{ ok: true, mensaje: "Variante vinculada.", vinculacion: {…} }` |
| Variante ya vinculada a otro producto | 422 | `{ errors: { variant_id: ["Esta variante ya está vinculada a …"] } }` |
| Producto ya vinculado a otra variante | 422 | `{ errors: { producto_id: ["Este producto ya está vinculado a …"] } }` |

La cardinalidad 1:1 se valida en el request **y** la garantizan dos índices únicos (FR-022).

### `DELETE /{vinculacion}`

Responde 200 con advertencia si existen órdenes convertidas que usaron el vínculo — las Ventas ya
creadas **no** se modifican (FR-026):

```jsonc
{ "ok": true, "mensaje": "Vinculación eliminada.", "advertencia": "N órdenes ya convertidas conservan este producto. Las órdenes futuras de esta variante quedarán sin resolver." }
```

---

## 3. Configuración (extiende la pantalla de la spec 015)

Permiso: `configuracion.funciones`.

| Método | Ruta | Nombre | Propósito | FR |
|---|---|---|---|---|
| PATCH | `/configuracion/tiendanube/ventas` | `tiendanube.ventas.configurar` | Guardar opciones de ventas | FR-010, FR-016, FR-045, FR-047, FR-050 |

```jsonc
{
  "creacion_automatica": false,
  "frecuencia_sync_minutos": 15,
  "deposito_id": null,           // null ⇒ depósito por defecto del CRM
  "categoria_venta_id": null,
  "cuenta_tesoreria_id": 7,       // NUEVO respecto de Mercado Libre — nullable, pero bloquea convertir si null (FR-045a)
  "dias_primera_sync": 30
}
```

La pantalla muestra de forma permanente la advertencia de sobreventa mientras la spec 018 no exista
(spec.md, sección Advertencias).

---

## 4. Comando programado

```bash
php artisan tiendanube:sincronizar-ordenes [--forzar]
```

Registrado con evaluación por minuto, mismo mecanismo de portabilidad que
`mercadolibre:sincronizar-ordenes` (spec 012).
