# Investigación — Crear publicaciones de Mercado Libre desde un producto del CRM

> **Estado**: investigación previa a la spec (15/10/2026). **Todavía no especificado ni implementado.**
> Es la próxima spec de la integración ML, sucesora de la spec 115 (cambio de tipo Clásica ↔ Premium).
> Antes de `/speckit-specify` hay que leer este documento entero y resolver las preguntas de §8 con el
> cliente.

## 1. Qué se quiere

Desde un producto del CRM, crear directamente la publicación en Mercado Libre **eligiendo el tipo**
(Clásica / Premium) y que quede **vinculada de una** al producto (sin pasar por "Vincular
automáticamente"). Incluye el caso de un producto que ya tiene una Clásica y se le quiere sumar la
Premium (y al revés).

Lo que **no** es: cambiar el tipo de una publicación existente. Eso ya lo resuelve la spec 115
(`POST /items/{id}/listing_type`) y sigue siendo útil por separado. Ver §3.2.ter.octies de
`documentacion_principal_crm.md`.

## 2. Cómo funciona hoy en Mercado Libre (lo que cambia el diseño)

### 2.1 Una publicación = un tipo; un producto = varias publicaciones

Cada publicación (`item`) tiene **un solo** `listing_type_id`. Lo que puede tener las dos cosas es el
producto, con dos publicaciones. En la cuenta de Pompei, al 15/10/2026 (producción, sólo lectura):

| Situación | Productos |
|---|---|
| Sólo Clásica, una publicación | 103 |
| Sólo Clásica, dos o más publicaciones | 54 |
| Clásica y Premium | 20 |
| Sólo Premium | 2 |
| **Total con publicación** (276 vínculos) | **179** |
| Productos activos **sin** publicación en ML | **7.969** |

### 2.2 En Argentina, "Clásica / Premium" ahora son "sin cuotas / con cuotas"

ML dejó de mostrar los nombres Clásica y Premium en el frontend de MLA; ahora diferencia por cuotas.
En la API se sigue usando `gold_special` / `gold_pro`.

| Tipo (API) | Qué ofrece | Tag de campaña en `/items` |
|---|---|---|
| `gold_special` | Sin cuotas (sólo las de los bancos, con interés) | sin tag |
| `gold_special` | 3 a 12 cuotas con interés bajo (+4% de costo) | `pcj-co-funded` |
| `gold_pro` | **6 cuotas al mismo precio (default al crear una Premium)** | sin tag |
| `gold_pro` | 3 / 9 / 12 cuotas al mismo precio | `3x_campaign` / `9x_campaign` / `12x_campaign` |

- Sólo **una** campaña de cuotas por publicación. Se manda en `tags` al crear (`POST /items`) o con un
  `PUT /items/{id}` que reenvíe **todos** los tags (los que no se reenvían se pierden).
- Campañas disponibles por categoría/marca/tipo: `GET /special_installments/campaigns?category_id=…`.
- Costo real por vender (comisión + costo de cuotas) para un precio/tipo/tag/dominio:
  `GET /sites/MLA/listing_prices?price=…&listing_type_id=…&tags=…&domain_id=…`. Sirve para mostrar en la
  confirmación cuánto cobra ML (la spec 115 sólo avisa "Premium cobra más").
- **Relación con el CRM**: el CRM hoy **no lee ni escribe** estos tags. Si un vendedor dice "las
  cuotas cambiaron" (caso del producto 27341, 15/10/2026), la forma de verificarlo es `GET /items/{id}` y
  mirar `tags`; el historial del CRM sólo muestra precio y tipo.

### 2.3 La cuenta de Pompei está en el modelo **User Products** (UP)

Las 276 publicaciones vinculadas tienen `user_product_id` (211 UP distintos). En el modelo UP:

- El **User Product** es el producto físico; cada **item** es una *condición de venta* (precio, tipo,
  cuotas, envío). Un UP admite hasta **30** items.
- Ya hay productos con Clásica y Premium **bajo el mismo UP** (ej. productos 23896, 24846, 25842) y
  otros con las dos como UP distintos (publicaciones viejas, anteriores a la unificación de ML).
- Atributos, fotos, `family_name`, título, condición y **`available_quantity`** se sincronizan entre
  todos los items de un mismo UP (ML los replica de forma asíncrona). **El stock es del UP, no del item.**
- Si el vendedor tiene el tag `user_product_seller` (verificar con `GET /users/me`), **no se puede
  publicar en el modelo viejo**: el POST con `title` + `variations` da 400. Hay que mandar
  `family_name` y **no** mandar `title` (lo arma ML con dominio + atributos + family_name).

## 3. Los dos caminos para crear

### Camino A — Sumar una condición de venta a un UP existente (lo "fácil")

Caso: el producto ya tiene una publicación (ej. Clásica) y se quiere la Premium, o una segunda Clásica.

```
POST /user-products/{USER_PRODUCT_ID}/items
{ "price": 145350, "category_id": "MLA…", "currency_id": "ARS",
  "buying_mode": "buy_it_now", "listing_type_id": "gold_pro",
  "tags": ["3x_campaign"],              // opcional: campaña de cuotas
  "shipping": {…}, "sale_terms": […] }  // opcionales
→ 201 con el item nuevo (hereda fotos, atributos, family_name, título y stock del UP)
```

- Campos requeridos: `price`, `category_id`, `currency_id`, `buying_mode`, `listing_type_id`.
- **No hay que reenviar fotos, atributos ni descripción**: el CRM no los tiene (ver §5) y acá no hacen
  falta. Es el único camino viable hoy sin cargar contenido en el CRM.
- El `user_product_id` ya está guardado en `ml_publicacion_producto` (spec 065).
- Precio: el de la lista del tipo elegido, con la regla estricta `SincronizadorPrecios::listaPrecioParaTipo()`
  de la spec 115 (sin fallback; sin precio → no se crea).
- Al crear, se da de alta el vínculo `ml_publicacion_producto` en la misma operación (con
  `listing_type_id`, `user_product_id`, `precio_publicado`).

### Camino B — Publicación nueva desde cero (lo "difícil")

Caso: producto sin ninguna publicación (los 7.969). Flujo de API:

1. **Categoría**: `GET /sites/MLA/domain_discovery/search?q={nombre}&limit=3` → dominio, categoría y
   atributos sugeridos. ML **modera** si se publica en otra categoría que la recomendada. Hojas
   únicamente (`listing_allowed`).
2. **Ficha técnica**: `GET /categories/{CAT}/attributes` → obligatorios (`tags.required`,
   `conditional_required`, `catalog_required`). Típicamente `BRAND`, `MODEL`, `GTIN` o
   `EMPTY_GTIN_REASON`, `ITEM_CONDITION`, y los propios del dominio (medidas, material, color…).
   GTIN pasa a obligatorio si la marca ya tiene ≥ 30 GTIN publicados.
3. **Garantía**: `GET /categories/{CAT}/sale_terms` → `WARRANTY_TYPE` + `WARRANTY_TIME`.
4. **Tipos disponibles**: `GET /users/{USER_ID}/available_listing_types?category_id={CAT}`.
5. **Fotos** (obligatorias para `gold_special` y `gold_pro`): por URL pública (`pictures[].source`) o
   subiéndolas antes con `POST /pictures/items/upload` (multipart) y mandando el `id`. JPG/PNG, mínimo
   500 px de lado, recomendado 1200×1200, hasta 10 MB; máximo por categoría en `max_pictures_per_item`.
6. **Envío**: la cuenta está en ME2 (Colecta/Flex/Full según la publicación). Dimensiones y peso del
   paquete como atributos `SELLER_PACKAGE_HEIGHT/WIDTH/LENGTH` (cm) y `SELLER_PACKAGE_WEIGHT` (g),
   **strings numéricos sin unidad**. Envío gratis obligatorio por encima del umbral (`mandatory_free_shipping`).
7. **Validar sin publicar**: `POST /items/validate` con el mismo JSON → 204 si está OK, o la lista de
   errores/warnings. **Es la única "prueba" segura: ML no tiene sandbox** (todo lo publicado es real).
8. **Crear**: `POST /items` con `family_name` (sin `title`), `category_id`, `price`, `currency_id: ARS`,
   `available_quantity`, `buying_mode: buy_it_now`, `listing_type_id`, `attributes` (incluye
   `ITEM_CONDITION`, `SELLER_SKU` = código del producto), `sale_terms`, `pictures`, `shipping`, `tags`.
   `condition` está deprecado: usar el atributo `ITEM_CONDITION`.
9. **Descripción**: aparte, después de crear: `POST /items/{id}/description` con `plain_text` (sin HTML,
   sin emojis; saltos de línea con `\n`). Si ya tiene, se edita con `PUT …/description?api_version=2`.
10. **Verificar**: `GET /items/{id}` con un breve retardo. Un item recién creado puede pasar a `closed`
    en minutos si ML lo considera **duplicado** de otro de la cuenta o falla la descarga de fotos.

## 4. Errores frecuentes documentados por ML (para mostrar en español)

| cause_id / code | Qué significa |
|---|---|
| 147 `item.attributes.missing_required` | Falta un atributo obligatorio de la categoría |
| 7810 `item.attribute.missing_conditional_required` | Falta GTIN (o `EMPTY_GTIN_REASON`) u otro condicional |
| 173 `item.listing_type_id.requiresPictures` | Sin fotos no se puede Clásica/Premium |
| 3703 `item.pictures.invalid_size` | Foto de menos de 500 px |
| 109 `item.price.invalid` | Precio por debajo del mínimo de la categoría |
| 126 `item.category_id.invalid` | Categoría que no es hoja |
| 3250 `moderations.seller.not_authorized` | Marca que exige acreditación (Ventas > Preferencias > Acreditaciones) |
| 3711/3712 GTIN de otro dominio/marca | GTIN mal cargado |
| 3705 `item.title.minimum_length` | Nombre poco descriptivo |
| 4029 `shipping.me2_adoption_mandatory` (warning) | ML completa ME2 solo |

Los `warning` no bloquean; los `error` sí. El CRM debería traducir los más comunes y mostrar el
`message` crudo del resto (mismo criterio que `ClienteMercadoLibre`).

## 5. Lo que le falta al CRM (dependencias reales)

Datos en producción al 15/10/2026 sobre 9.784 productos:

| Dato que pide ML | ¿Lo tiene el CRM? |
|---|---|
| Fotos | **No**: 0 productos con `imagen`. La columna existe pero nunca se cargó |
| Descripción | Casi no: 4 productos |
| Marca, modelo | No como campo: está mezclado en el `nombre` |
| GTIN / código de barras | No |
| Categoría ML y ficha técnica | No |
| Medidas y peso del paquete | No |
| Garantía | No (habría que definir un default de la empresa) |
| SKU | Sí: `codigo` → `SELLER_SKU` (es lo que usa la vinculación automática) |
| Precio por tipo | Sí: listas ML / ML Premium (specs 050/115) |
| Stock | Sí: depósito ML configurado (spec 013) |

**Conclusión**: el camino A no depende de nada de esto. El camino B depende de cargar fotos y ficha
técnica, que hoy no existen. Para el B hay que decidir de dónde salen (ver §8): cargarlas en el CRM,
traerlas de Tiendanube (los productos vinculados allá sí tienen fotos), o copiarlas de una publicación
de ML existente de otro producto parecido. Por la regla de oro de `CLAUDE.md`, la dependencia no se
"simplifica": o se especifica junto (contenido de producto + publicar) o se acota la spec al camino A
y el B queda documentado como pendiente.

## 6. Impactos sobre lo que ya existe

- **Stock (spec 013)**: con UP, dos items del mismo UP **comparten** stock. El sincronizador hoy manda
  stock por vínculo; para dos vínculos del mismo UP manda lo mismo dos veces (redundante, no incorrecto).
  Crear una segunda condición de venta no cambia el stock disponible: ML lo reparte, no lo duplica.
- **Precio (specs 016/050/084)**: el precio sí es por item. La publicación nueva entra al circuito
  normal: lista según su tipo y corte de seguridad. El precio inicial lo confirma una persona en el
  modal (como en la spec 115), así que no hace falta pasar por el corte.
- **Vinculación**: al crear, se inserta el vínculo con `vinculada_por` = usuario. No hace falta
  "Vincular automáticamente".
- **Logística (spec 065)**: la publicación nueva sale con la logística que ML le asigne (Colecta por
  default en esta cuenta); Full requiere enviar mercadería al depósito de ML y queda fuera.
- **Cuotas**: si se decide ofrecer campañas (3x/9x/12x en Premium, `pcj-co-funded` en Clásica), es un
  campo más en la creación. Leerlas y mostrarlas en Vinculaciones sería útil también para auditar casos
  como el del 27341.

## 7. Cómo se prueba sin romper nada

- **Nunca contra la cuenta real del cliente** sin OK puntual (memorias "nunca probar en producción" y
  "Tiendanube cuenta real").
- `POST /items/validate` valida el JSON completo sin publicar: los tests de integración y una prueba
  manual pueden apoyarse en eso.
- Usuarios de test de ML (app de pruebas, ver `CREDENCIALES_ACCESO.txt`): publicar en categoría "Otros"
  con título "Item de Prueba - Por favor, NO OFERTAR". Para probar el modelo User Products hay que pedir
  que activen el usuario de test por el formulario de ML (se activan cada 7 días).
- La base local tiene una cuenta ML "conectada" con token vencido: ninguna prueba local puede llegar a
  llamar a la API real (renovaría el token y rompería el de producción). Probar con `Http::fake()` o con
  el modo sólo lectura activo.

## 8. Preguntas para el cliente antes de especificar

**Respuestas del usuario (15/10/2026):**

- **Alcance: publicaciones desde cero** (camino B). Hay que confirmar si el camino A (sumar un tipo a
  un producto ya publicado) entra en la misma spec o queda para después.
- **Fotos: se cargan en el CRM.** Al crear la publicación, el CRM las pide. Implica construir la carga de
  imágenes del producto, que hoy no existe (0 productos con imagen).
- **Salen pausadas**, para revisarlas en Mercado Libre antes de activarlas.
- **Cuotas por defecto: configurables** desde Configuración (integración ML), en vez de fijas en el código.

Siguen abiertas: 3 (ficha técnica), 4 (garantía por defecto), 7 (mostrar cuotas en Vinculaciones) y
8 (desde dónde se dispara).


1. ¿Alcanza para empezar con el **camino A** (sumar Premium/Clásica a un producto que ya está
   publicado), o necesitan desde el día uno publicar productos que nunca estuvieron en ML?
2. Si hace falta el B: ¿de dónde salen las fotos? (cargarlas en el CRM / traerlas de Tiendanube / otra).
3. ¿Quién completa la ficha técnica (marca, modelo, medidas, GTIN)? ¿Hay códigos de barras de los
   proveedores?
4. Garantía por defecto de la empresa (tipo y tiempo).
5. ¿Las publicaciones nuevas salen **activas** o **pausadas** para revisarlas en ML antes de vender?
6. Cuotas: al crear una Premium, ¿qué campaña por defecto (6 cuotas sin tag / 3x / 9x / 12x)? ¿Y en
   Clásica, ofrecen "cuotas con interés bajo"?
7. ¿Quieren que el CRM también **muestre** las cuotas actuales de cada publicación en Vinculaciones?
8. ¿Desde dónde se dispara: ficha de Producto, Vinculaciones, o ambas?

## 9. Fuentes (documentación oficial de ML Argentina, consultada el 15/10/2026)

- Publicar productos: https://developers.mercadolibre.com.ar/es_ar/publica-productos
- User Products: https://developers.mercadolibre.com.ar/es_ar/user-products
- Precio por variación (incluye `POST /user-products/{id}/items`): https://developers.mercadolibre.com.ar/es_ar/precio-variacion
- Stock distribuido: https://developers.mercadolibre.com.ar/es_ar/stock-distribuido
- Tipos de publicación: https://developers.mercadolibre.com.ar/es_ar/tipos-de-publicacion-y-actualizaciones-de-articulos
- Campañas con cuotas: https://developers.mercadolibre.com.ar/es_ar/campana-con-cuotas-para-marketplace
- Categorización: https://developers.mercadolibre.com.ar/es_ar/categoriza-productos
- Identificadores (GTIN): https://developers.mercadolibre.com.ar/es_ar/identificadores-de-productos
- Imágenes: https://developers.mercadolibre.com.ar/es_ar/trabajar-con-imagenes
- Descripción: https://developers.mercadolibre.com.ar/es_ar/descripcion-de-articulos
- Validaciones (códigos de error): https://developers.mercadolibre.com.ar/es_ar/validaciones
- Validador: https://developers.mercadolibre.com.ar/es_ar/validador-de-publicaciones
- Envío — dimensiones: https://developers.mercadolibre.com.ar/es_ar/items-atributos-de-envio-y-dimensiones
- Realiza pruebas: https://developers.mercadolibre.com.ar/es_ar/realiza-pruebas
