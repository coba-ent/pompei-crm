# Phase 0 — Research: Migración de la integración Tiendanube del servidor MCP a la Application REST clásica

**Feature**: `024-tiendanube-migracion-rest` | **Fecha**: 2026-07-31

Resolución de las incógnitas técnicas antes del diseño. La conexión de base (headers, reintentos,
verificación) ya está resuelta y validada en producción por spec 022 (`VerificadorConexionRest`) — esta
investigación se concentra en los endpoints de negocio nuevos que esa spec dejó explícitamente fuera de
alcance.

---

## R1. Paginación del listado de catálogo (`GET /{store_id}/products`)

**Decisión**: parámetros `page` (base 1) y `per_page` (tope documentado 200, se usa 50 para no alejarse del
tamaño de página que ya usaba `list_products` vía MCP — `ImportadorVinculaciones::cargarCatalogo()`).
Cortar el recorrido cuando una página devuelve menos de `per_page` elementos.

**Rationale**: es el paginado clásico documentado de la API REST de Tiendanube, offset-based — no tiene el
tope de 1000 resultados alcanzables que sí tiene el buscador de Mercado Libre (spec 023 R1), porque acá no
se pagina por `offset`+`limit` sino por número de página; alcanza sin necesitar un modo `scan`.

**A verificar al implementar**: confirmar contra la cuenta real el header o campo exacto de total de
páginas/elementos (Tiendanube expone históricamente `X-Total-Count` en el header de la respuesta, no un
campo `pagination.total_pages` en el body como sí devolvía `list_products` vía MCP — research.md de spec
019 R4). Si el header no está disponible o cambia, cortar por "página con menos de `per_page` resultados"
sigue siendo válido como criterio de corte, sin depender de ningún total declarado.

---

## R2. Campo de SKU en el listado de catálogo

**Decisión**: cada producto trae `variants: [{ id, sku, stock, price, ... }]` — el `sku` viene directo en
la misma respuesta paginada, sin necesitar una llamada de detalle adicional por producto (a diferencia de
Mercado Libre, spec 023 R2, que sí requirió multiget para `SELLER_SKU`).

**Rationale**: verificado empíricamente contra la cuenta real en la sesión que originó esta spec (ver
spec.md Contexto) — confirma que el catálogo en vivo es apto como fuente directa de vinculación sin costo
adicional de llamadas.

---

## R3. Endpoint de pedidos (`GET /{store_id}/orders`)

**Decisión**: parámetros `page`/`per_page` (mismo criterio que R1), `created_at_min`/`created_at_max` (ISO
8601) como ventana de tiempo — reemplazando los `completed_at_from`/`completed_at_to` que usaba
`SincronizadorOrdenes` contra `list_orders` (MCP). `status` sigue aceptando valores equivalentes
(`open`/`closed`/`cancelled`).

**Rationale**: es el nombre de parámetro documentado de la API REST clásica de pedidos — a diferencia del
nombre de parámetro de `list_orders` (MCP), que `SincronizadorOrdenes` (spec 017) dejó anotado como "nunca
verificado empíricamente" (comentario en el código actual). Migrar a REST es la oportunidad de confirmar
esto contra la cuenta real en el primer "Sincronizar ahora" post-implementación — mismo criterio de
verificación empírica que ya usó spec 019 para `list_products`.

**A verificar al implementar**: el nombre exacto de la clave de resultado del body (`orders` es la
convención de la API REST clásica, a diferencia de la incertidumbre que tenía el nombre bajo MCP) y el
criterio de filtro por fecha más preciso para reemplazar `completed_at_from/to` (¿pedidos completados o
pedidos creados/actualizados en la ventana? — mismo comportamiento observable de spec 017 debe
preservarse: qué pedidos entran a la ventana de `dias_primera_sync`).

---

## R4. Actualización de stock/precio: sin batch, una `PUT` por variante

**Decisión**: `PUT /{store_id}/products/{product_id}/variants/{variant_id}` con body `{"stock": N}` o
`{"price": "N.NN"}` (o ambos en la misma llamada si hay que actualizar los dos a la vez) — la REST API
clásica de Tiendanube no tiene un endpoint de actualización batch equivalente a la tool MCP
`update_stock_and_price`, que sí aceptaba hasta 50 actualizaciones en una sola llamada.

**Rationale**: es el modelo REST estándar de actualización de un recurso (una variante = un recurso), y
está documentado como tal. Ausencia de batch: `SincronizadorStock` deja de armar un payload de hasta 50 y
pasa a iterar una request por vínculo pendiente — plan.md §"Enfoque técnico" punto 3.

**Alternativas descartadas**: mantener el concepto de "lote" con requests en paralelo (`Http::pool()`) para
no perder el paralelismo que sí tenía el batch de MCP — descartado para esta spec por simplicidad y porque
el volumen de Tiendanube es bajo (spec.md Assumptions); documentado como optimización futura si el volumen
crece.

---

## R5. Historial de operaciones de negocio sobre la conexión REST

**Decisión**: reutilizar `tn_rest_operaciones_log` (`TiendanubeRestOperacionLog`, ya creada por spec 022
junto con `tn_conexion_rest`) para las operaciones de negocio del cliente REST nuevo (`orders`, `products`,
`variants`), sumándose a las operaciones de conexión (`conectar`/`verificar`/`desconectar`) que ya registra
`TiendanubeConexionRestController`.

**Rationale**: es la tabla natural para esto — está atada a la misma conexión (`tn_conexion_rest`) que
`ClienteTiendanubeRest` usa como fuente de credenciales, y ya tiene exactamente el mismo esquema de
columnas (`operacion`, `metodo`, `endpoint`, `sentido`, `resultado`, `codigo_http`, `duracion_ms`,
`mensaje_error`, `usuario_id`) que usaba `tn_operaciones_log` para el MCP. No hace falta crear una tabla
nueva ni reutilizar `tn_operaciones_log` (que se retira con el resto del MCP en Historia 3).

---

## R6. Configuración de negocio mezclada en `tn_configuracion`

**Decisión**: antes de retirar `tn_configuracion` (Historia 3), migrar a `tn_conexion_rest` los campos que
son configuración operativa de specs 017/018 (no credenciales MCP): `modo_solo_lectura`,
`creacion_automatica`, `frecuencia_sync_minutos`, `deposito_id`, `categoria_venta_id`,
`cuenta_tesoreria_id`, `dias_primera_sync`, `ultima_sync_en`, `ultima_sync_resultado`,
`stock_ultima_sync_en`, `stock_ultima_sync_resultado`, `lista_precio_id`, `vendedor_id`. Ver plan.md
§"Enfoque técnico" punto 4 para el detalle de la migración.

**Rationale**: hallazgo de esta fase de planificación — `tn_configuracion` (spec 019) no es sólo la tabla
de credenciales del MCP, también terminó siendo donde vive toda la configuración operativa de las specs
017/018/020 (ampliaciones sucesivas). Retirar la tabla sin migrar estos campos rompería la sincronización
de stock (¿qué depósito?), la conversión automática (¿a qué categoría/cuenta/vendedor?) y el push de
precios (¿qué Lista de Precios?) — contradiciendo SC-002 de spec.md ("mismo comportamiento observable").

**Alternativas descartadas**: crear una tabla de configuración de negocio separada, independiente tanto de
`tn_configuracion` como de `tn_conexion_rest` — descartada por sumar una tabla más sin necesidad real; el
criterio single-tenant de este CRM (constitución, principio V) ya trata "configuración + conexión" como una
sola fila en el resto de las integraciones (`tn_configuracion` mismo, `MercadoLibreConfiguracion`), así que
extender `tn_conexion_rest` es coherente con el patrón existente, no una excepción.

---

## R8. Corrección post-implementación (31/07/2026): formato real de `/products` y `/orders`

**Contexto**: R1-R3 de arriba se escribieron antes de verificar contra la cuenta real (sólo se había
verificado R2, el campo `sku`). Al probar contra producción, tres asunciones resultaron incorrectas —
corregidas en el código y en `contracts/api-tiendanube-rest.md` §2/§3:

1. **Ambos endpoints devuelven un array JSON plano en la raíz**, no `{"products": [...]}` /
   `{"orders": [...]}` como asumía el contrato original.
2. **`status` como array rompe `/orders` con 500** (Internal Server Error) — se dejó de enviar por
   completo; sin el parámetro la API devuelve órdenes de todos los estados.
3. **La forma de una orden difiere sustancialmente de lo asumido**: sin objeto `customer` (comprador en
   campos planos `contact_email`/`contact_name`/`contact_identification`), `total`/`currency`/`price`
   como campos planos (no `{amount, currency}`), `completed_at` como objeto de fecha serializado (no
   string ISO), y `fulfillment_status` reemplazado por `shipping_status`.

Ver `contracts/api-tiendanube-rest.md` §2/§3 para el detalle completo y `TraductorOrdenes`/
`VinculadorAutomatico`/`ClienteTiendanubeRest` para la implementación corregida.

## R7. Vinculación: variante como unidad (a diferencia de Mercado Libre)

**Decisión**: `VinculadorAutomatico` de Tiendanube no excluye productos con variantes múltiples — evalúa
cada variante (talle/color) por separado, cada una con su propio SKU.

**Rationale**: `TiendanubeVarianteProducto` (spec 017/018) ya modela el vínculo a nivel de variante, no de
producto — a diferencia de `MercadoLibrePublicacionProducto`, que vincula a nivel de publicación completa y
por eso spec 021/023 excluyen publicaciones con variantes (no hay forma de decidir a qué variante de la
publicación corresponde el vínculo). En Tiendanube esa ambigüedad no existe: cada variante ya es su propia
fila vinculable.
