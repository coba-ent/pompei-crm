# Research — Vinculación automática de Mercado Libre por catálogo en vivo

Todas las decisiones de esta fase se validaron empíricamente contra la cuenta real conectada
(`contagramdemo.devstudioweb.com`, vendedor TESTUSER686149248667814311, ml_user_id 3554577007) — ver el
detalle de cada verificación abajo.

## R1. El modo `scan` de `/users/{seller_id}/items/search` recorre el catálogo completo sin el tope de 1000

**Decisión**: usar `ClienteMercadoLibre::obtener('vincular_automatico_scan', "/users/{seller_id}/items/search", ['search_type' => 'scan'])`
para la primera página, y en las siguientes agregar `'scroll_id' => $scrollIdDeLaRespuestaAnterior`. Parar
cuando `results` viene vacío.

**Verificado en vivo**: primera llamada (`search_type=scan`, sin `scroll_id`) devolvió
`paging: {"limit":50,"total":5}`, un `scroll_id` (`eyJpZCI6...`, opaco — no depende de interpretarlo, sólo
de reenviarlo tal cual) y los 5 ids reales del catálogo de prueba. Segunda llamada con ese mismo
`scroll_id` devolvió `results: []` (fin del catálogo, coincide con `total:5`) y un `scroll_id` **distinto**
al de la respuesta anterior — confirma que hay que usar el scroll_id más reciente en cada llamada, no
reusar siempre el primero.

**Por qué no el paginado clásico (`offset`/`limit`)**: documentado por Mercado Libre que sólo alcanza hasta
`offset+limit ≤ 1000` resultados — insuficiente para el catálogo real del negocio (miles de publicaciones,
spec.md Assumptions). El modo `scan` no tiene ese tope.

**Alternativas consideradas**: paginado clásico por `offset` (descartado — tope de 1000, no cubre el
volumen real); traer todo en una sola llamada sin paginar (no existe esa opción en la API).

## R2. Multiget de hasta 20 ids por llamada trae el detalle completo necesario

**Decisión**: `ClienteMercadoLibre::obtener('vincular_automatico_multiget', '/items', ['ids' => implode(',', $chunkDe20Ids)])`.

**Verificado en vivo**: con 5 ids reales en un solo chunk, la respuesta trajo un array de entradas
`{code, body}` por cada id — `code:200` y `body` con `attributes[]`, `status` y `variations[]` completos,
en una sola llamada. No hace falta ninguna llamada adicional por publicación.

**Costo**: para miles de publicaciones, esto son `total/20` llamadas de multiget además de las de `scan` —
muy por debajo del rate limit documentado (~1500 req/min por vendedor, spec 021 research.md R1).

**Alternativas consideradas**: `GET /items/{id}` uno por uno (descartado — research.md R1 de la spec 021 ya
lo usaba así para una sola publicación puntual; a escala de miles de publicaciones multiplicaría las
llamadas por 20 sin necesidad).

## R3. El SKU del vendedor vive en `attributes[]` con `id == 'SELLER_SKU'`, no en `seller_custom_field`

**Decisión**: `collect($item['attributes'])->first(fn ($a) => $a['id'] === 'SELLER_SKU')['value_name'] ?? null`.

**Verificado en vivo**: para la publicación `MLA3690559588`, `seller_custom_field` vino `null` (campo
legado, sin usar por el vendedor de prueba), mientras que `attributes[]` sí trajo la entrada
`{"id":"SELLER_SKU","name":"SKU","value_name":"SKU-493S3",...}`. Confirmado también contra las otras 4
publicaciones reales del catálogo (incluida `MLA1927008393` con SKU `9006`, y dos publicaciones distintas
compartiendo el SKU `KO-23423` — caso real de duplicado, spec.md Edge Cases).

**Alternativas consideradas**: `seller_custom_field` (descartado — vino vacío en la cuenta real, es el
campo legado que integraciones más viejas de Mercado Libre usaban antes del sistema de atributos).

## R4. Publicaciones con variantes se excluyen inspeccionando `variations[]` del propio multiget

**Decisión**: `count($item['variations'] ?? []) > 0` ⇒ excluir (mismo criterio FR-007 ya vigente desde spec
021).

**Verificado en vivo**: las 5 publicaciones reales del catálogo de prueba tienen `variations: []` (ninguna
usa variantes) — el campo está presente y es inspeccionable directamente en la respuesta del multiget, sin
necesitar una llamada aparte ni depender de `ml_orden_items.ml_variation_id` como hacía el mecanismo viejo.

## R5. Rate limit y volumen real

**Decisión**: sin cambios respecto al límite ya documentado por la spec 021 (research.md R1): ~1500
req/min por vendedor. Con el volumen real (miles de publicaciones, spec.md Assumptions), una corrida
completa implica del orden de cientos de llamadas (scan paginado + multiget de a 20) — muy por debajo del
límite, aunque la corrida en sí puede tardar varios minutos en tiempo de reloj (confirmado aceptable,
spec.md Clarifications).

**No verificado con el volumen real completo**: la cuenta de prueba conectada sólo tiene 5 publicaciones —
no permite medir en la práctica cuánto tarda un recorrido de miles de páginas. El mecanismo (`scan` +
multiget) está confirmado funcionando correctamente sobre datos reales, pero el tiempo total de una corrida
a escala completa queda como riesgo a observar en el primer uso real contra el catálogo completo del
negocio, no como algo bloqueante para implementar.
