# Data Model — Vinculación automática por SKU

**Sin cambios de esquema.** Esta spec no agrega columnas ni tablas — ver research.md R2 (el "ID viejo"
de Mercado Libre es el propio `id` de `productos`, no un campo nuevo) y R4/R5 (Tiendanube resuelve
contra `codigo`, ya existente, y contra el catálogo en vivo, sin persistir nada nuevo).

## Entidades ya existentes involucradas (sin cambios de estructura)

### `productos`
Sin cambios. El matching de Mercado Libre usa `id` (clave primaria ya existente); el de Tiendanube usa
`codigo` (ya existente, spec 002). Ningún índice nuevo: `id` ya es único por ser PK; `codigo` no
requiere unicidad adicional para esta spec (ya se valida en el alta/edición de producto, `SkuUnico`).

### `ml_publicacion_producto` (specs 012/013/016)
Sin cambios de estructura. Cambia únicamente **quién** crea la fila: antes el operador vía selector
manual (`store()`), ahora `VinculadorAutomatico` (nuevo servicio). Los mismos campos que ya persiste
`store()` hoy (`ml_item_id`, `producto_id`, `titulo_ml`, `vinculada_por`) se siguen persistiendo igual.

### `tn_variante_producto` (specs 017/018)
Sin cambios de estructura. Se agrega una segunda vía de creación (`ImportadorVinculaciones`, nuevo
servicio) además del alta manual ya existente (`store()`, que sigue intacta). Mismos campos
(`variant_id`, `tn_product_id`, `producto_id`, `nombre_variante_tn`, `vinculada_por`).

### `ml_orden_items` (spec 012)
Sin cambios. Fuente del SKU del vendedor (`sku_vendedor`) para la vinculación automática de ML — mismo
campo que ya usaba el diseño descartado, sin cambios de lectura (`orderByDesc('id')` para "más
reciente", mismo criterio ya vigente en `publicacionesPendientes()`).

### `tn_orden_items` (spec 017)
Sin cambios, sin uso nuevo en esta spec (el diseño original que resolvía ids de Tiendanube contra esta
tabla quedó reemplazado por la resolución contra el catálogo en vivo — research.md R5). Se mantiene
disponible por si una fase futura la necesitara como fallback.

## Flujo de resolución del SKU (no persistido — lógica de los dos servicios nuevos)

### Mercado Libre

```
MercadoLibreOrdenItem::where('ml_item_id', $mlItemId)->orderByDesc('id')->value('sku_vendedor')
  → (int) $sku
  → Producto::find((int) $sku)
```

Sin filtrar por `productos.activo` (clarificación de spec.md — se vincula igual aunque el producto esté
inactivo).

### Tiendanube

```
Fila del archivo: SKU + "Identificador de URL"
  → Producto::where('codigo', $sku)->first()
      ?? Producto::where('codigo', 'like', $sku.' %')->first()
  → catálogo en vivo (ClienteTiendanube::leer('list_products', ...), paginado y cacheado en memoria
    durante la corrida) → producto cuyo `product_url` (slug) == "Identificador de URL" de la fila
  → { product_id, variant_id } reales de Tiendanube
```

## Resultado de las corridas (forma de respuesta, no entidad persistida)

Mismo formato para ambos canales (vinculación automática de ML y la importación de TN), consistente con
el patrón ya usado por otras importaciones del CRM:

```jsonc
{
  "ok": true,
  "total": 12,
  "vinculadas": 9,
  "fallidas": 3,
  "detalle_fallidas": [
    { "referencia": "MLA123", "motivo": "sin_sku" },
    { "referencia": "9099", "motivo": "producto_no_encontrado" },
    { "referencia": "27205", "motivo": "ya_vinculado", "detalle": "producto" }
  ]
}
```

`motivo` (Mercado Libre): `sin_sku`, `producto_no_encontrado`, `ya_vinculado` (con `detalle`: `sku` o
`producto`).

`motivo` (Tiendanube): `producto_no_encontrado` (SKU no matchea `codigo`, exacto ni parcial),
`tiendanube_no_encontrado` (el "Identificador de URL" no está en el catálogo en vivo), `ya_vinculado`
(con `detalle`: `sku` o `producto`).
