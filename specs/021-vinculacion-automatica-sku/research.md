# Research — Vinculación automática por SKU (Mercado Libre y Tiendanube)

Todas las decisiones de esta fase se validaron empíricamente contra las cuentas reales conectadas
(`contagramdemo.devstudioweb.com`) y contra datos reales del negocio, no contra documentación genérica —
ver el detalle de cada verificación abajo.

## R1. Mercado Libre expone búsqueda por SKU del vendedor

**Decisión**: usar `GET /users/{user_id}/items/search?seller_sku=X` vía `ClienteMercadoLibre::obtener()`
ya existente.

**Verificado en vivo**: contra la cuenta real conectada, `seller_sku=9010` devolvió `HTTP 200` con
`results: []` (sin esa publicación con ese SKU) — endpoint funciona, formato de respuesta confirmado
(`paging.total`, `results[]`). Documentación oficial también confirma el endpoint
(`developers.mercadolibre.com.ar/en_us/items-and-searches`): 1 SKU por llamada, sin batch. Rate limit
documentado: ~1500 req/min por vendedor.

**Nota de diseño**: esta spec NO usa este endpoint para la vinculación automática en sí — el SKU se
obtiene de `ml_orden_items.sku_vendedor` (ya sincronizado, sin llamada en vivo), igual que el diseño
descartado de la spec 021 original. El endpoint queda confirmado como disponible por si una fase futura
necesitara backfill contra publicaciones que nunca vendieron (fuera de alcance de esta spec).

**Alternativas consideradas**: ninguna — es el único endpoint de búsqueda por SKU documentado.

## R2. El "ID viejo" de Mercado Libre es el `id` de `productos`, no un campo nuevo

**Decisión**: sin migración de esquema. El matching es `Producto::find((int) $skuVendedor)`.

**Contexto**: se descartó primero la hipótesis de que el "ID viejo" era un campo separado (nullable,
único) a agregar a `productos` — descubierta a mitad de la investigación, antes de confirmar con el
usuario que en realidad los productos correspondientes a publicaciones de ML **todavía no existen** en
el CRM, y se van a crear asignándoles a propósito ese mismo valor como `id` (clave primaria), no como un
atributo aparte.

**Verificado en vivo**: 2 vínculos reales de ML ya existentes NO siguen esta convención (son datos
viejos/de prueba, según confirma el usuario) — no sirven para validar el patrón hacia atrás, sólo hacia
adelante.

**Alternativas consideradas**: columna nueva `id_legado` (descartada tras la aclaración del usuario —
hubiera sido trabajo e índice único innecesarios).

## R3. Tiendanube no expone SKU por ninguna vía de la integración conectada

**Decisión**: el SKU de Tiendanube sólo se puede obtener del archivo que el negocio exporta a mano desde
el panel de Tiendanube — nunca de `admin-mcp.tiendanube.com`.

**Verificado exhaustivamente en vivo**: se listaron las 28 tools reales del servidor MCP
(`tools/list`); ninguna incluye búsqueda por SKU. Se pidió `list_products` con
`fields_needed: ['id','name','variants']` y luego con `fields_needed: null` ("todos los campos" según la
propia documentación de la tool) contra la tienda real — en ningún caso aparece SKU en ningún campo
(tampoco escondido en `description` ni en `product_url`). `update_stock_and_price` (la tool de escritura
ya usada por la spec 018) documenta explícitamente que identifica variantes por `product_id`+
`variant_id`, nunca por SKU. Conclusión: no hay atajo posible con la integración conectada tal como
está hoy.

**Alternativas consideradas**: pedir el dato por otra tool (ninguna lo expone), REST directo de
Tiendanube fuera del MCP (fuera de alcance — la integración conectada usa exclusivamente el MCP, no hay
credenciales para la REST API clásica).

## R4. El SKU de Tiendanube corresponde a `productos.codigo`

**Decisión**: `Producto::where('codigo', $sku)->first()`, con fallback a
`Producto::where('codigo', 'like', $sku.' %')` si no hay match exacto.

**Verificado en vivo, con volumen real**: 86 filas con SKU del export real de Tiendanube (102 productos
totales, 16 sin SKU cargado) cruzadas contra `productos.xlsx` (9101 productos reales) — 85/86 (98.8%)
coinciden con `codigo`: 79 exacto, 6 por el número inicial (ej. SKU `27205` vs `codigo` real
`"27205 AL605028 BL"`). El único caso sin match es un producto de prueba (`PRU-001`, "Campera de cuero",
ajeno al rubro del negocio).

**Alternativas consideradas**: `productos.id` (descartado — 0/5 casos reales coinciden), match sólo
exacto sin fallback (descartado — perdería 6/86 casos reales confirmados).

## R5. Los ids reales de Tiendanube se resuelven contra el catálogo en vivo, por slug — no contra órdenes

**Decisión**: `ClienteTiendanube::leer('list_products', ['page_size' => 50, 'page' => N, 'fields_needed'
=> ['id', 'product_url']])`, paginado, comparando el slug de `product_url` (sin el dominio) contra la
columna "Identificador de URL" del archivo exportado.

**Verificado en vivo, con el catálogo completo**: se trajeron los 102 productos reales de la tienda
(3 páginas de `list_products`) y se cruzó el slug de cada `product_url` contra "Identificador de URL"
del export real — **102/102 (100%)** coinciden exacto. Cada producto trae también su `id` real y el
`id` de cada variante en `variants[]`, que son exactamente los identificadores que
`tn_variante_producto` necesita.

**Por qué esto reemplaza al diseño original (resolver contra `tn_orden_items`)**: el diseño descartado
dependía de que el producto ya hubiera vendido por Tiendanube (única fuente de esos ids antes de este
hallazgo). Consultando el catálogo en vivo por slug, cualquier producto **publicado** se puede vincular,
haya vendido o no — limitación real menos severa que la asumida originalmente.

**Costo**: 3 llamadas (102 productos / 50 por página) por corrida de importación, muy por debajo del
rate limit documentado de Tiendanube (burst 40, ~2 req/s sostenido). Cachear el resultado paginado en
memoria durante la corrida (no repetir la consulta por cada fila del archivo).

**Alternativas consideradas**: resolver contra `tn_orden_items` (diseño original, mantiene la
limitación de "nunca vendió"; se conserva como posible fallback si el slug no matchea, no como mecanismo
primario).

## R6. Formato real del archivo de export de Tiendanube

**Decisión**: leer con `Excel::toArray()` (ya usado por spec 006), localizando columnas por nombre de
encabezado (`SKU`, `Identificador de URL`) — nunca por posición fija.

**Verificado contra el archivo real**: separador `;`, 25 columnas, codificación **ISO-8859-1** (no
UTF-8 — confirmado con `mb_detect_encoding`), primera fila = encabezados. `SKU` es la columna 11,
`Identificador de URL` la columna 1 — posiciones que no hay que asumir fijas porque Tiendanube puede
reordenar o agregar columnas en el export sin aviso (documentado como riesgo aceptado en spec.md
Assumptions).

**Alternativas consideradas**: plantilla propia de 2 columnas (diseño original de la spec 021
descartada) — se descarta porque obliga al usuario a armar un archivo a mano en vez de subir
directamente lo que ya exporta Tiendanube.
