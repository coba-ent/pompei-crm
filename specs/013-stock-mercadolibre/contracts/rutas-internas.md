# Contrato de rutas internas — Sincronización de stock hacia Mercado Libre (spec 013)

**Spec**: [../spec.md](../spec.md) · **Plan**: [../plan.md](../plan.md)

Extiende los contratos ya vigentes de la spec 012 (`specs/012-ventas-mercadolibre/contracts/rutas-internas.md`).
Misma convención de respuesta (`{ok, mensaje}` / 422 / 409).

---

## 1. Ventas de Mercado Libre — `/ingresos/mercadolibre` (extensión)

Permiso: `ventas.ver` · Guard: función avanzada `mercadolibre` activa (igual que la 012).

| Método | Ruta | Nombre | Propósito | FR |
|---|---|---|---|---|
| POST | `sincronizar-stock` | `.sincronizarStock` | "Sincronizar stock ahora" (US3) | FR-007 |

### `POST /sincronizar-stock`

Sin cuerpo. Respuestas:

| Situación | HTTP | Cuerpo |
|---|---|---|
| Correcta | 200 | `{ ok: true, mensaje: "N productos actualizados en Mercado Libre.", actualizados, con_error }` |
| Ya hay una sincronización de stock en curso | 409 | `{ ok: false, mensaje: "Ya hay una sincronización de stock en curso." }` |
| Función desactivada / modo sólo lectura | 409 | `{ ok: false, mensaje: "<motivo del bloqueo>" }` |
| Conexión caída o sin configurar | 409 | `{ ok: false, mensaje: "…Volvé a conectar la cuenta." }` |

Candado propio (`ml:sincronizar_stock`, independiente del de órdenes) — FR-008. Los rechazos de vínculos
individuales (publicación pausada, etc.) **no** cambian el código HTTP de la respuesta global: van
contados en `con_error` y quedan visibles por vínculo en el listado de vinculaciones (FR-014/FR-015).

---

## 2. Vinculación de publicaciones — `/ingresos/mercadolibre/vinculaciones` (extensión de datatable)

Sin rutas nuevas. `GET datatable` (ya existente, spec 012) agrega tres columnas derivadas del vínculo:

```jsonc
{
  // columnas ya existentes: ml_item_id, titulo_ml, producto, vinculada_por, created_at…
  "stock_estado": "sincronizado", // "sincronizado" | "pendiente" | "error" — FR-017
  "stock_sincronizado_en": "2026-07-28T10:15:00-03:00",
  "stock_error": null // motivo del último rechazo, si stock_estado = "error"
}
```

---

## 3. Configuración (extiende la pantalla de las specs 011/012)

Permiso: `configuracion.funciones` (sin cambios). Sin ruta nueva: `GET /configuracion/mercadolibre`
(ya existente) agrega al payload de estado:

```jsonc
{
  // payload ya existente de la 011/012…
  "stock_ultima_sync_en": "2026-07-28T10:15:00-03:00",
  "stock_ultima_sync_resultado": "OK: 3 productos actualizados, 0 con error."
}
```

---

## 4. Comando programado

```bash
php artisan mercadolibre:sincronizar-stock [--forzar]
```

Mismo patrón que `mercadolibre:sincronizar-ordenes` (spec 012): compara `stock_ultima_sync_en` contra
`frecuencia_sync_minutos` (campo compartido); `--forzar` ignora esa comparación pero no los cortes de
FR-009/FR-010. Registrado en `bootstrap/app.php` con `everyMinute()->withoutOverlapping()`, **después**
del de órdenes en el mismo `withSchedule()` ([research.md R4](../research.md)).

Códigos de salida: `0` correcta o salteada (nada pendiente o todavía no toca) · `1` bloqueada por
configuración o conexión · `2` error de ejecución.

---

## 5. Contrato externo consumido (Mercado Libre)

Toda llamada pasa por `ClienteMercadoLibre` (spec 011), sin ningún punto de salida nuevo.

| # | Llamada | Uso |
|---|---|---|
| 1 | `PUT /items/{item_id}` con `{"available_quantity": N}` | Actualizar la cantidad disponible de la publicación vinculada (FR-012) — ver [research.md R6](../research.md) para la verificación pendiente de tags de la cuenta real. |

Es una escritura (`PUT`): el kill-switch de modo sólo lectura de la spec 011 la bloquea automáticamente
por el único punto de salida (`ClienteMercadoLibre::peticion()`), **pero sólo por llamada individual**.
`SincronizadorStock` igual necesita su propio corte previo al `foreach` de vínculos pendientes (función
desactivada, modo sólo lectura, conexión caída) para no generar un registro de "bloqueada" por cada
vínculo y para no intentar la red cuando ya se sabe que la conexión está caída — el mismo motivo, en el
sentido opuesto, por el que la sincronización de órdenes (lectura) necesitó su propio corte. Ver
[research.md R7](../research.md).
