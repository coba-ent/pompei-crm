# Contrato de rutas internas — Gestión de precios de Mercado Libre desde una Lista de Precios (spec 016)

**Spec**: [../spec.md](../spec.md) · **Plan**: [../plan.md](../plan.md)

Extiende los contratos ya vigentes de las specs 012 y 013
(`specs/013-stock-mercadolibre/contracts/rutas-internas.md`). Misma convención de respuesta
(`{ok, mensaje}` / 422 / 409).

---

## 1. Productos — `/productos` (extensión)

**Corrección de UX posterior a la implementación inicial**: esta acción se especificó originalmente junto
a "Sincronizar ahora"/"Sincronizar stock ahora" en `/ingresos/mercadolibre` (mismo patrón que la spec
013), pero el usuario la esperaba en la pantalla de Productos — donde efectivamente se editan los precios
que dispara la sincronización — no en la pantalla de órdenes de Mercado Libre. Movida sin cambios en el
controlador ni en el servicio subyacente, sólo la ruta y el disparador de UI.

Permiso: sesión autenticada (sin `permiso:` adicional — mismo criterio que el resto de las rutas de
`/productos/*`, que tampoco lo tienen). Guard funcional: `SincronizadorPrecios::ejecutar()` sigue
aplicando sus propios cortes (función avanzada `mercadolibre` activa, modo sólo lectura, conexión
conectada) igual que antes — el cambio de pantalla no afecta esos cortes.

| Método | Ruta | Nombre | Propósito | FR |
|---|---|---|---|---|
| POST | `productos/sincronizar-precios-ml` | `productos.sincronizarPreciosMl` | "Sincronizar precios ahora" (US3) | FR-014 |

Reutiliza sin cambios `MercadoLibreVentaController::sincronizarPrecios()` (spec 016) — sólo cambia la
ruta que apunta a esa acción, no la acción en sí.

### `POST /productos/sincronizar-precios-ml`

Sin cuerpo. Respuestas:

| Situación | HTTP | Cuerpo |
|---|---|---|
| Correcta | 200 | `{ ok: true, mensaje: "N productos actualizados en Mercado Libre.", actualizados, con_error }` |
| Ya hay una sincronización de precios en curso | 409 | `{ ok: false, mensaje: "Ya hay una sincronización de precios en curso." }` |
| Función desactivada / modo sólo lectura | 409 | `{ ok: false, mensaje: "<motivo del bloqueo>" }` |
| Conexión caída o sin configurar | 409 | `{ ok: false, mensaje: "…Volvé a conectar la cuenta." }` |
| Sin Lista de Precios configurada | 409 | `{ ok: false, mensaje: "No hay ninguna Lista de Precios configurada para Mercado Libre." }` |

Candado propio (`ml:sincronizar_precios`, independiente del de stock y del de órdenes) — FR-015. Los
rechazos de vínculos individuales (publicación pausada, etc.) **no** cambian el código HTTP de la
respuesta global: van contados en `con_error` y quedan visibles por vínculo en el listado de
vinculaciones (FR-010).

Este endpoint no depende de ninguna corrida programada: es el único disparador manual y también el
mecanismo de reintento de los vínculos que el disparo automático (evento de cambio de precio) no logró
enviar (US3).

---

## 2. Vinculación de publicaciones — `/ingresos/mercadolibre/vinculaciones` (extensión de datatable)

Sin rutas nuevas. `GET datatable` (ya existente, spec 012, extendida por 013 con columnas de stock) agrega
tres columnas derivadas del vínculo, análogas a las de stock:

```jsonc
{
  // columnas ya existentes: ml_item_id, titulo_ml, producto, vinculada_por, created_at,
  // stock_estado, stock_sincronizado_en, stock_error…
  "precio_estado": "sincronizado", // "sincronizado" | "pendiente" | "error" — FR-017
  "precio_sincronizado_en": "2026-07-29T10:15:00-03:00",
  "precio_error": null // motivo del último rechazo, si precio_estado = "error"
}
```

---

## 3. Configuración de Mercado Libre — `/configuracion/mercadolibre` (extensión)

Permiso: `configuracion.funciones` (sin cambios). Sin ruta nueva: `PATCH configuracion/mercadolibre/ventas`
(ya existente, spec 012, guarda Depósito/Categoría de Venta) agrega el campo `lista_precio_id` al payload
de entrada y de respuesta:

```jsonc
// Request (PATCH ventas)
{
  "deposito_id": 1,
  "categoria_venta_id": 3,
  "lista_precio_id": 2 // nuevo, nullable — FR-001/FR-002/FR-003
}
```

```jsonc
// Response
{
  "ok": true,
  "mensaje": "Configuración de ventas guardada.",
  "configuracion": { /* … */ "lista_precio_id": 2 }
}
```

**Efecto adicional al guardar** (FR-007, sin exponerse como una ruta distinta): si `lista_precio_id`
cambió respecto del valor anterior y el nuevo valor no es `null`, el propio `guardarVentas()` dispara
`SincronizadorPrecios::sincronizarListaCompleta($nuevoListaPrecioId)` después de persistir el cambio,
respetando los mismos cortes de kill-switch/modo sólo lectura/conexión caída que el resto de los envíos
(si está bloqueado, los vínculos con precio en la nueva lista quedan `precio_pendiente = true` para el
próximo intento válido, sin que el guardado de la configuración falle por eso — US5, escenario 3). La
respuesta de `guardarVentas()` no cambia su forma por este efecto adicional: sigue siendo
`{ok, mensaje, configuracion}`, el resultado del push no se expone en esta misma respuesta (el usuario lo
ve reflejado en el estado por-vínculo de la pantalla de vinculaciones, igual que un envío automático).

---

## 4. Sin comando programado

A diferencia de la sincronización de stock (spec 013), este flujo **no** agrega ningún comando Artisan ni
entrada en `bootstrap/app.php`: el disparo es exclusivamente por evento (Observer sobre `PrecioProducto`,
ver [research.md R1-R2](../research.md)) o por la acción manual del punto 1.
