# Data Model — Vinculación automática de Mercado Libre por catálogo en vivo

**Sin cambios de esquema.** Esta spec no agrega columnas ni tablas — ver research.md R1/R2/R3 (el catálogo
en vivo de Mercado Libre reemplaza a `ml_orden_items` como fuente del SKU, sin persistir nada nuevo).

## Entidades ya existentes involucradas (sin cambios de estructura)

### `productos`
Sin cambios. El matching sigue siendo por `id` (clave primaria ya existente) — mismo criterio que la spec
021, sólo cambia de dónde sale el SKU con el que se compara.

### `ml_publicacion_producto` (specs 012/013/016, spec 021)
Sin cambios de estructura ni de campos persistidos (`ml_item_id`, `producto_id`, `titulo_ml`,
`vinculada_por`). Cambia únicamente la fuente de datos que decide qué fila crear: antes `ml_orden_items`,
ahora el catálogo en vivo.

### `ml_orden_items` (spec 012)
Sin cambios de estructura ni de sincronización — sigue poblándose igual que siempre. Deja de **leerse**
para este mecanismo específico (spec.md FR-009); puede seguir usándose para otros fines (reportes
históricos, otros mecanismos que sí dependan de órdenes).

## Flujo de resolución del SKU (no persistido — lógica del servicio, reemplaza al de la spec 021)

```
ClienteMercadoLibre::obtener(..., '/users/{seller_id}/items/search', ['search_type' => 'scan'])
  → ids de todas las publicaciones del vendedor (paginado con scroll_id hasta agotar el catálogo)
  → excluir los ya vinculados (MercadoLibrePublicacionProducto::pluck('ml_item_id'))
  → ClienteMercadoLibre::obtener(..., '/items', ['ids' => '<hasta 20 ids>'])  (multiget, en chunks)
      → por cada publicación: excluir si status=closed o variations no vacío
      → sku = attributes[] con id=='SELLER_SKU' → value_name
  → (int) $sku → Producto::find(...)
```

Sin filtrar por `productos.activo` (mismo criterio que spec 021, FR-002/FR-004 — se vincula igual aunque el
producto esté inactivo).

## Resultado de las corridas (forma de respuesta, sin cambios respecto a spec 021)

```jsonc
{
  "ok": true,
  "total": 12,
  "vinculadas": 9,
  "fallidas": 3,
  "detalle_fallidas": [
    { "referencia": "MLA123", "motivo": "sin_sku" },
    { "referencia": "MLA456", "motivo": "producto_no_encontrado" },
    { "referencia": "MLA789", "motivo": "ya_vinculado", "detalle": "producto" }
  ]
}
```

Mismos `motivo` que la spec 021: `sin_sku`, `producto_no_encontrado`, `ya_vinculado` (con `detalle`: `sku`
o `producto`). Si la corrida se aborta a mitad de camino por una falla del catálogo en vivo (spec.md
Assumptions): `ClienteMercadoLibre::obtener()` nunca lanza excepciones por fallas del proveedor (siempre
devuelve una `RespuestaMercadoLibre` con `fallo(): true`) — `VinculadorAutomatico` traduce ese `fallo()` a
una excepción propia (`VinculacionAutomaticaFallidaException`, nueva, mismo patrón que las demás
`Excepciones/` del namespace `App\Services\MercadoLibre`), que el controlador captura y devuelve como JSON
`{"ok": false, "mensaje": "..."}` (502) — no un resumen parcial con `vinculadas`/`fallidas`.
