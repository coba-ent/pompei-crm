# Contrato de rutas internas — Ventas de Mercado Libre (spec 012)

**Spec**: [../spec.md](../spec.md) · **Plan**: [../plan.md](../plan.md)

Todas las rutas viven bajo `Route::middleware('auth')`. Salvo aclaración, responden **JSON** y las
operaciones se ejecutan **sin recarga de página** (regla de diseño obligatoria del proyecto, SC-012).

**Convención de respuesta** (la misma que ya usan Ventas y la spec 011):

```jsonc
// Éxito
{ "ok": true, "mensaje": "…", /* datos */ }
// Error de validación → HTTP 422
{ "message": "…", "errors": { "campo": ["…"] } }
// Operación rechazada por regla de negocio → HTTP 409
{ "ok": false, "mensaje": "motivo legible para el usuario" }
```

---

## 1. Ventas de Mercado Libre — `/ingresos/mercadolibre`

Permiso: `ventas.ver` · Guard adicional: función avanzada `mercadolibre` activa (FR-002).

| Método | Ruta | Nombre | Propósito | FR |
|---|---|---|---|---|
| GET | `/` | `ingresos.mercadolibre.index` | Pantalla del listado (Blade) | FR-001 |
| GET | `datatable` | `.datatable` | Datos server-side del listado | FR-004, FR-005 |
| POST | `sincronizar` | `.sincronizar` | "Sincronizar ahora" | FR-009 |
| GET | `{orden}` | `.show` | Detalle de la orden (modal) | FR-005 |
| GET | `{orden}/convertir` | `.convertir` | Formulario de Venta precargado (**Blade, página completa**) | FR-028, FR-029 |
| POST | `{orden}/convertir` | `.convertirGuardar` | Ejecuta la conversión | FR-032, FR-044, FR-046 |

### `POST /sincronizar`

Sin cuerpo. Respuestas:

| Situación | HTTP | Cuerpo |
|---|---|---|
| Correcta | 200 | `{ ok: true, mensaje: "N órdenes nuevas, M actualizadas.", nuevas, actualizadas, convertidas }` |
| Ya hay una corrida en curso | 409 | `{ ok: false, mensaje: "Ya hay una sincronización en curso." }` |
| Función desactivada / modo sólo lectura | 409 | `{ ok: false, mensaje: "<motivo del bloqueo>" }` |
| Conexión caída o sin configurar | 409 | `{ ok: false, mensaje: "…Volvé a conectar la cuenta." }` |

Cortes verificados **antes** de paginar (FR-017/FR-018, research R10), para no generar una entrada de
historial por página.

### `POST /{orden}/convertir`

Cuerpo: la misma forma que `StoreVentaRequest` de una Venta normal (ítems, cliente, comprobante,
notas…), más `submit_token` para la protección de doble envío ya existente.

| Situación | HTTP | Cuerpo |
|---|---|---|
| Correcta | 201 | `{ ok: true, mensaje: "Venta … creada con éxito.", redirect: "/ventas/{id}" }` |
| Orden ya convertida (incluye carrera perdida) | 409 | `{ ok: false, mensaje: "Esta orden ya tiene una Venta asociada.", venta_id }` |
| Orden no pagada o cancelada | 409 | `{ ok: false, mensaje: "…" }` |
| Precondición sin resolver | 409 | `{ ok: false, mensaje: "<motivo>", motivo: "publicacion_sin_vincular" }` |
| Validación | 422 | Errores por campo |

**Idempotencia y concurrencia**: candado por orden + índice único sobre `ml_ordenes.venta_id`. El
segundo intento simultáneo obtiene 409, nunca un duplicado (FR-032a, SC-004a).

---

## 2. Vinculación de publicaciones — `/ingresos/mercadolibre/vinculaciones`

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
{ "ml_item_id": "MLA1927008393", "producto_id": 42, "titulo_ml": "…" }
```

| Situación | HTTP | Cuerpo |
|---|---|---|
| Correcta | 201 | `{ ok: true, mensaje: "Publicación vinculada.", vinculacion: {…} }` |
| Publicación ya vinculada a otro producto | 422 | `{ errors: { ml_item_id: ["Esta publicación ya está vinculada a …"] } }` |
| Producto ya vinculado a otra publicación | 422 | `{ errors: { producto_id: ["Este producto ya está vinculado a …"] } }` |
| Publicación con variantes | 422 | `{ errors: { ml_item_id: ["Las publicaciones con variantes no están soportadas."] } }` |

La cardinalidad 1:1 se valida en el request **y** la garantizan dos índices únicos (FR-022). La
validación da el mensaje amable; el índice es la garantía real.

### `DELETE /{vinculacion}`

Responde 200 con advertencia si existen órdenes convertidas que usaron el vínculo — las Ventas ya
creadas **no** se modifican (FR-026):

```jsonc
{ "ok": true, "mensaje": "Vinculación eliminada.", "advertencia": "N órdenes ya convertidas conservan este producto. Las órdenes futuras de esta publicación quedarán sin resolver." }
```

---

## 3. Configuración (extiende la pantalla de la spec 011)

Permiso: `configuracion.funciones` (sin cambios).

| Método | Ruta | Nombre | Propósito | FR |
|---|---|---|---|---|
| PATCH | `/configuracion/mercadolibre/ventas` | `mercadolibre.ventas.configurar` | Guardar opciones de ventas | FR-010, FR-047, FR-050 |

```jsonc
{
  "creacion_automatica": false,       // FR-050 — false por defecto
  "frecuencia_sync_minutos": 15,      // FR-010 — 5|10|15|30|60
  "deposito_id": 3,                   // FR-047 — null ⇒ depósito por defecto
  "categoria_venta_id": null,
  "dias_primera_sync": 30             // FR-016
}
```

La pantalla muestra de forma permanente la advertencia de **sobreventa** mientras la spec 013 no exista
(FR-060).

---

## 4. Comando programado

```bash
php artisan mercadolibre:sincronizar-ordenes [--forzar]
```

Registrado con evaluación por minuto; en cada disparo decide si corresponde ejecutar comparando el
tiempo transcurrido desde `ultima_sync_en` contra `frecuencia_sync_minutos` (research R5). `--forzar`
ignora esa comparación (útil para diagnóstico), pero **no** los cortes de FR-017/FR-018.

Códigos de salida: `0` correcta o salteada · `1` bloqueada por configuración o conexión · `2` error de
ejecución.

---

## 5. Contrato externo consumido (Mercado Libre) — ✅ verificado 27/07/2026

Verificado contra la documentación oficial. Toda llamada pasa por `ClienteMercadoLibre` (research R10);
**no** se agrega ningún otro punto de salida. Todo el acoplamiento al formato externo vive en
`TraductorOrdenes` (research R3).

| # | Llamada | Uso |
|---|---|---|
| 1 | `GET /orders/search?seller={id}&order.date_last_updated.from=…&offset=…&limit=50` | Sincronización incremental paginada |
| 2 | `GET /orders/search?seller={id}&order.status=cancelled&…` | **Segunda pasada obligatoria**: la búsqueda del vendedor excluye las canceladas (FR-012a) |
| 3 | `GET /orders/{id}` | Detalle puntual y `buyer.billing_info.id` |
| 4 | `GET /orders/billing-info/{SITE_ID}/{BILLING_INFO_ID}` | Condición fiscal del comprador (FR-039) |

Todas son de **lectura** (`GET`), por lo que el kill-switch de escrituras de la spec 011 no las bloquea
por sí solo — el corte durante el modo sólo lectura es una decisión propia de esta spec (FR-017,
research R10).

**Paginación**: `paging: { total, offset, limit }`, `limit` por defecto 50.

**Estados** (`status`): `confirmed` · `payment_required` · `payment_in_process` · `partially_paid` ·
`paid` · `partially_refunded` · `pending_cancel` · `cancelled`.

**Tags relevantes**: `paid` · `delivered` · `test_order` · **`fraud_risk_detected`** (bloquea la
conversión, FR-052a).

### Comportamientos del proveedor que condicionan el diseño

| Comportamiento verificado | Consecuencia |
|---|---|
| La búsqueda como vendedor **excluye canceladas** | Segunda pasada explícita (llamada 2). Sin ella, US6 no funciona |
| Retención de **12 meses** | `dias_primera_sync` se topea ahí (FR-016) |
| **HTTP 206** con `X-Content-Missing` | No es error: se procesa y se registra qué faltó (FR-012b) |
| `buyer` puede venir reducido a sólo `id` | El emparejamiento usa el identificador, no el apodo (FR-036) |
| `unit_price` ya viene **neto de descuentos** | Es el valor a usar; `gross_price` es informativo |
| `total_amount` **no** incluye `taxes.amount` ni envío | El total de la Venta iguala `total_amount` (coherente con FR-049) |
| `sale_fee` por ítem | Se persiste aunque esta spec no lo use, para no re-sincronizar cuando se especifique |
| `invoice_type` **fue removido** de la API | El tipo de comprobante lo deriva el CRM (FR-039/FR-040) |
