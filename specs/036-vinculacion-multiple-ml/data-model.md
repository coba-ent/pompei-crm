# Data Model: Vinculación múltiple Producto ↔ Publicaciones (ML y Tiendanube)

## Entidades modificadas

### `ml_publicacion_producto`

| Columna | Tipo | Antes | Después |
|---|---|---|---|
| `id` | bigint PK | — | sin cambios |
| `ml_item_id` | string(40) | `unique()` | `unique()` — **sin cambios** |
| `producto_id` | FK → `productos.id` | `unique()` | **se elimina el índice único** (queda FK simple, `cascadeOnDelete()` sin cambios) |
| `titulo_ml` | string(255) nullable | — | sin cambios |
| `vinculada_por` | FK → `users.id` nullable | — | sin cambios |
| `stock_pendiente`, `stock_sincronizado_en`, `stock_error`, `stock_error_en` | (spec 013) | — | sin cambios |
| `precio_pendiente`, `precio_sincronizado_en`, `precio_error`, `precio_error_en` | (spec 016) | — | sin cambios |

**Cardinalidad**: Producto 1 ↔ N `ml_publicacion_producto` (antes 1 ↔ 0..1). Publicación (`ml_item_id`)
sigue siendo 1 ↔ 0..1 vínculo (unicidad preservada).

### `tn_variante_producto`

| Columna | Tipo | Antes | Después |
|---|---|---|---|
| `id` | bigint PK | — | sin cambios |
| `variant_id` | unsignedBigInteger | `unique()` | `unique()` — **sin cambios** |
| `producto_id` | FK → `productos.id` | `unique()` | **se elimina el índice único** |
| `tn_product_id`, `nombre_variante_tn`, `vinculada_por`, campos de stock/precio (specs 017/018) | — | — | sin cambios |

**Cardinalidad**: Producto 1 ↔ N `tn_variante_producto` (antes 1 ↔ 0..1). Variante (`variant_id`) sigue
siendo 1 ↔ 0..1 vínculo.

## Relaciones Eloquent

Sin cambios en las declaraciones de relación existentes:

- `MercadoLibrePublicacionProducto::producto()` — `belongsTo(Producto::class)`, sin cambios (sigue
  siendo correcta: cada fila de vínculo pertenece a un único producto).
- `TiendanubeVarianteProducto::producto()` — `belongsTo(Producto::class)`, ídem.
- `Producto` no declara relación inversa `hasOne`/`hasMany` hacia estos modelos hoy — no hay nada que
  migrar de `hasOne` a `hasMany` porque esa relación inversa no está declarada en el modelo (las
  consultas existentes usan `::where('producto_id', ...)` directo, ver Servicios afectados).

## Servicios y Observers afectados (comportamiento, no esquema)

| Archivo | Cambio |
|---|---|
| `app/Services/MercadoLibre/VinculadorAutomatico.php` | `procesar()`: eliminar el `return` por `'ya_vinculado'` cuando el producto ya tiene otro vínculo; crear el vínculo igual. |
| `app/Services/Tiendanube/VinculadorAutomatico.php` | Mismo cambio, en su `procesar()`. |
| `app/Observers/MovimientoStockObserver.php` | `ramaMercadoLibre()`/`ramaTiendanube()`: `::first()` → `::get()` + `foreach` marcando `stock_pendiente = true` en cada vínculo. |
| `app/Observers/PrecioProductoObserver.php` | `ramaMercadoLibre()`/`ramaTiendanube()`: `::first()` → `::get()` + `foreach` despachando `enviarUno($vinculo, $precio)` por cada vínculo (después del commit, sin cambios en ese mecanismo). |

## Sin cambios (confirmado en research.md R4)

- `SincronizadorStock` (ML) y su equivalente de Tiendanube: ya iteran por vínculo individual vía
  `::pendientes()->get()`, no requieren modificación.
- Vistas de listado de publicaciones/variantes (`resources/views/ingresos/mercadolibre/*`): ya son
  tablas server-side con una fila por publicación/variante, no agrupadas por producto — soportan N
  filas por producto sin cambios (research.md R5).
