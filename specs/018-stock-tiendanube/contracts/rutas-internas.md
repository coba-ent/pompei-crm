# Contrato de rutas internas — Sincronización de stock hacia Tiendanube (spec 018)

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
| Conexión caída o sin configurar | 409 | `{ ok: false, mensaje: "…Volvé a cargar el token." }` |

Candado propio (`tn:sincronizar_stock`, independiente del de órdenes de Tiendanube y del de stock de
Mercado Libre) — FR-008. Los rechazos de vínculos individuales (variante/producto eliminado, etc.) **no**
cambian el código HTTP de la respuesta global: van contados en `con_error` y quedan visibles por vínculo
en el listado de vinculación de variantes (FR-014/FR-015).

---

## 2. Vinculación de variantes — `/ingresos/tiendanube/vinculaciones` (extensión de datatable)

Sin rutas nuevas. `GET datatable` (ya diseñado por la spec 017) agrega tres columnas derivadas del
vínculo:

```jsonc
{
  // columnas ya diseñadas: variant_id, nombre_variante_tn, producto, vinculada_por, created_at…
  "stock_estado": "sincronizado", // "sincronizado" | "pendiente" | "error" — FR-017
  "stock_sincronizado_en": "2026-07-29T10:15:00-03:00",
  "stock_error": null // motivo del último rechazo, si stock_estado = "error"
}
```

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

Toda llamada pasa por `ClienteTiendanube` (spec 015), sin ningún punto de salida nuevo.

| # | Llamada | Uso |
|---|---|---|
| 1 | `POST /products/{product_id}/variants/stock` con `{"action": "replace", "value": N, "id": variant_id}` | Actualizar la cantidad disponible de la variante vinculada (FR-012) — ver [research.md R6](../research.md) para el detalle verificado del endpoint (exige `product_id`, no sólo `variant_id`). |

Es una escritura (`POST`): el kill-switch de modo sólo lectura de la spec 015 la bloquea automáticamente
por el único punto de salida (`ClienteTiendanube::peticion()`), **pero sólo por llamada individual**.
`SincronizadorStock` igual necesita su propio corte previo al `foreach` de vínculos pendientes (función
desactivada, modo sólo lectura, conexión caída) para no generar un registro de "bloqueada" por cada
vínculo y para no intentar la red cuando ya se sabe que la conexión está caída — mismo motivo, en sentido
opuesto, por el que la sincronización de órdenes de Tiendanube (lectura) necesitó su propio corte. Ver
[research.md R7](../research.md).
