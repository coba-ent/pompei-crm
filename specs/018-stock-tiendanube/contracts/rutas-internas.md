# Contrato de rutas internas — Sincronización de stock y precios hacia Tiendanube (spec 018)

**Spec**: [../spec.md](../spec.md) · **Plan**: [../plan.md](../plan.md)

Extiende los contratos ya diseñados por la spec 017 (`specs/017-ventas-tiendanube/contracts/rutas-internas.md`).
Misma convención de respuesta (`{ok, mensaje}` / 422 / 409).

---

## 1. Ventas de Tiendanube — `/ingresos/tiendanube` (extensión)

Permiso: `ventas.ver` · Guard: función avanzada `tiendanube` activa (igual que la 017).

| Método | Ruta | Nombre | Propósito | FR |
|---|---|---|---|---|
| POST | `sincronizar-stock` | `.sincronizarStock` | "Sincronizar stock ahora" (US3) | FR-007 |

### `POST /sincronizar-stock`

Sin cuerpo. Respuestas:

| Situación | HTTP | Cuerpo |
|---|---|---|
| Correcta | 200 | `{ ok: true, mensaje: "N variantes actualizadas en Tiendanube.", actualizados, con_error }` |
| Ya hay una sincronización de stock en curso | 409 | `{ ok: false, mensaje: "Ya hay una sincronización de stock en curso." }` |
| Función desactivada / modo sólo lectura | 409 | `{ ok: false, mensaje: "<motivo del bloqueo>" }` |
| Conexión caída o sin configurar | 409 | `{ ok: false, mensaje: "…Hace falta reconectar Tiendanube (soporte técnico)." }` |

Candado propio (`tn:sincronizar_stock`, independiente del de órdenes de Tiendanube y del de stock de
Mercado Libre) — FR-008. Los rechazos de vínculos individuales (variante/producto eliminado, etc.) **no**
cambian el código HTTP de la respuesta global: van contados en `con_error` y quedan visibles por vínculo
en el listado de vinculación de variantes (FR-014/FR-015).

---

## 1a. Precios — `/productos` (ampliación 30/07/2026, ruta y controlador propios de Tiendanube)

**Corrección de diseño respecto de la primera idea de esta ampliación** (research.md R10): no se reutiliza
`MercadoLibreVentaController::sincronizarPrecios()` (spec 016) — se crea una acción y ruta **propias** de
Tiendanube en el mismo controlador donde ya vive `sincronizarStock` (punto 1). El botón "Sincronizar
precios ahora" en la pantalla de Productos sigue siendo uno solo: su JS dispara esta ruta **y** la de
Mercado Libre en el mismo click.

Permiso: sesión autenticada (sin `permiso:` adicional — mismo criterio que
`productos.sincronizarPreciosMl`, spec 016). Guard funcional: `Tiendanube\SincronizadorPrecios::ejecutar()`
aplica sus propios cortes (función avanzada `tiendanube` activa, modo sólo lectura, conexión conectada).

| Método | Ruta | Nombre | Propósito | FR |
|---|---|---|---|---|
| POST | `productos/sincronizar-precios-tn` | `productos.sincronizarPreciosTn` | "Sincronizar precios ahora" — mitad Tiendanube (US7) | FR-035 |

### `POST /productos/sincronizar-precios-tn`

Sin cuerpo. Respuestas:

| Situación | HTTP | Cuerpo |
|---|---|---|
| Correcta | 200 | `{ ok: true, mensaje: "N variantes actualizadas en Tiendanube.", actualizados, con_error }` |
| Ya hay una sincronización de precios en curso | 409 | `{ ok: false, mensaje: "Ya hay una sincronización de precios en curso." }` |
| Función desactivada / modo sólo lectura | 409 | `{ ok: false, mensaje: "<motivo del bloqueo>" }` |
| Conexión caída o sin configurar | 409 | `{ ok: false, mensaje: "…Hace falta reconectar Tiendanube (soporte técnico)." }` |
| Sin Lista de Precios configurada | 409 | `{ ok: false, mensaje: "No hay ninguna Lista de Precios configurada para Tiendanube." }` |

Candado propio (`tn:sincronizar_precios`, independiente del de stock/órdenes de Tiendanube y del de
precios de Mercado Libre) — FR-036. El JS del botón (`resources/js/productos.js`) llama a **ambas** rutas
(`productos.sincronizarPreciosMl` y `productos.sincronizarPreciosTn`) y combina los dos resultados en un
único toast — la fusión de UX es puramente de cliente, cada ruta responde de forma completamente
independiente (research.md R10).

---

## 2. Vinculación de variantes — `/ingresos/tiendanube/vinculaciones` (extensión de datatable)

Sin rutas nuevas. `GET datatable` (ya diseñado por la spec 017) agrega columnas derivadas del vínculo:

```jsonc
{
  // columnas ya diseñadas: variant_id, nombre_variante_tn, producto, vinculada_por, created_at…
  "stock_estado": "sincronizado", // "sincronizado" | "pendiente" | "error" — FR-017
  "stock_sincronizado_en": "2026-07-29T10:15:00-03:00",
  "stock_error": null, // motivo del último rechazo, si stock_estado = "error"
  "precio_estado": "sincronizado", // "sincronizado" | "pendiente" | "error" — FR-038 (ampliación)
  "precio_sincronizado_en": "2026-07-30T10:15:00-03:00",
  "precio_error": null // motivo del último rechazo, si precio_estado = "error"
}
```

---

## 2a. Configuración de Tiendanube — `lista_precio_id` (ampliación, extensión del punto 3)

Sin ruta nueva: `PATCH configuracion/tiendanube/ventas` (ya existente, spec 017) agrega el campo
`lista_precio_id` al payload de entrada y de respuesta:

```jsonc
// Request (PATCH ventas)
{
  "deposito_id": 1, "categoria_venta_id": 3, "cuenta_tesoreria_id": 7,
  "frecuencia_sync_minutos": 15, "dias_primera_sync": 30,
  "lista_precio_id": 2 // nuevo, nullable — FR-021/FR-022/FR-023
}
```

**Efecto adicional al guardar** (FR-028, sin exponerse como una ruta distinta): si `lista_precio_id`
cambió respecto del valor anterior y el nuevo valor no es `null`, `guardarVentas()` dispara
`Tiendanube\SincronizadorPrecios::sincronizarListaCompleta($nuevoListaPrecioId)` después de persistir,
respetando los mismos cortes de kill-switch — mismo mecanismo que `MercadoLibreConfiguracionController`
(spec 016). La respuesta no cambia de forma por este efecto adicional.

---

## 3. Configuración (extiende la pantalla de las specs 015/017)

Permiso: `configuracion.funciones` (sin cambios). Sin ruta nueva: `GET /configuracion/tiendanube` (ya
existente) agrega al payload de estado:

```jsonc
{
  // payload ya existente de la 015/017…
  "stock_ultima_sync_en": "2026-07-29T10:15:00-03:00",
  "stock_ultima_sync_resultado": "OK: 3 variantes actualizadas, 0 con error."
}
```

---

## 4. Comando programado

```bash
php artisan tiendanube:sincronizar-stock [--forzar]
```

Mismo patrón que `tiendanube:sincronizar-ordenes` (spec 017): compara `stock_ultima_sync_en` contra
`frecuencia_sync_minutos` (campo compartido); `--forzar` ignora esa comparación pero no los cortes de
FR-009/FR-010. Registrado en `bootstrap/app.php` con `everyMinute()->withoutOverlapping()`, **después**
del de órdenes de Tiendanube en el mismo `withSchedule()` ([research.md R4](../research.md)).

Códigos de salida: `0` correcta o salteada (nada pendiente o todavía no toca) · `1` bloqueada por
configuración o conexión · `2` error de ejecución.

---

## 5. Contrato externo consumido (Tiendanube)

Toda llamada pasa por `ClienteTiendanube` (spec 019, JSON-RPC/MCP), sin ningún punto de salida nuevo.

| # | Llamada | Uso |
|---|---|---|
| 1 | Tool `update_stock_and_price` con `{"updates": [{"product_id": ..., "variant_id": ..., "stock": N}, ...]}` (hasta 50 ítems por llamada) | Actualizar la cantidad disponible de hasta 50 variantes vinculadas por request (FR-012) — **corrección post-019**: no es un endpoint REST, es esta tool JSON-RPC; ver [research.md R6](../research.md) para el detalle verificado (exige `product_id` por ítem, no sólo `variant_id`; formato de respuesta ante fallos parciales no verificado empíricamente). |
| 2 | Misma tool `update_stock_and_price` con `{"updates": [{"product_id": ..., "variant_id": ..., "price": N}]}` (**un solo ítem** por llamada — sin loteo, ampliación) | Actualizar el precio de una variante vinculada al momento del evento de cambio de precio (FR-029) — mismo endpoint que la fila 1, campo `price` en vez de `stock`, sin combinar ambos en el mismo ítem (research.md R9). |

---

## 4a. Sin comando programado para precio (ampliación)

A diferencia de la sincronización de stock (punto 4), el flujo de precios **no** agrega ningún comando
Artisan ni entrada en `bootstrap/app.php`: el disparo es exclusivamente por evento (rama Tiendanube de
`PrecioProductoObserver`, spec 016, ver [research.md R8](../research.md)) o por la acción manual del
punto 1a.

Es una escritura: el kill-switch de modo sólo lectura de la spec 019 la bloquea automáticamente por el
único punto de salida (`ClienteTiendanube::peticion()`), **pero sólo por llamada individual** (es decir,
por chunk de hasta 50 vínculos, no por vínculo — corrección post-019, ver R6). `SincronizadorStock`
igual necesita su propio corte previo a armar los chunks (función desactivada, modo sólo lectura,
conexión caída) para no generar un registro de "bloqueada" por cada chunk y para no intentar la red
cuando ya se sabe que la conexión está caída — mismo motivo, en sentido opuesto, por el que la
sincronización de órdenes de Tiendanube (lectura) necesitó su propio corte. Ver
[research.md R7](../research.md).
