# Contrato — API externa de Tiendanube (REST clásica, consumida para negocio)

**Feature**: `024-tiendanube-migracion-rest`

Extiende `specs/022-tiendanube-conexion-rest/` (que sólo documentó `GET /{store_id}/store` para
verificación) con los endpoints de negocio que esta spec consume. Mismos headers y mismo esquema de
reintentos que spec 022 (research.md R1-R3 de spec 015, ya validados en producción) — no se repiten acá.

## 1. Base, direccionamiento y autenticación

Sin cambios respecto de spec 022: `https://api.tiendanube.com/v1/{store_id}/{recurso}`, headers
`Authentication: bearer {access_token}` + `User-Agent: Contagram CRM (contacto@contagram.com.ar)`.

## 2. Catálogo — `GET /{store_id}/products`

**Query**: `page` (base 1), `per_page` (50, research.md R1).

**200** — ⚠️ **verificado contra la cuenta real (31/07/2026): el body es un array JSON plano en la
raíz**, no un objeto `{"products": [...]}` como asumía la primera versión de este contrato:
```json
[
  {
    "id": 123456,
    "status": "published",
    "variants": [
      { "id": 987654, "sku": "9006", "stock": 3, "price": "15000.00" }
    ]
  }
]
```

`ClienteTiendanubeRest`/`VinculadorAutomatico` distinguen ambos casos con `array_is_list()` (por si
Tiendanube cambia de formato más adelante), pero el formato real y único observado es el array plano.
Usado por `VinculadorAutomatico` (recorrido completo, comparando `variants[].sku` contra `Producto::id`,
research.md R2) — `status: "closed"` excluye el producto completo (spec.md Edge Cases); `paused`/`published`
se consideran vinculables por igual, mismo criterio que spec 021/023.

## 3. Pedidos — `GET /{store_id}/orders`

**Query**: `page`, `per_page` (50), `created_at_min`/`created_at_max` (ISO 8601, research.md R3). **Sin
`status`**: verificado contra la cuenta real que enviarlo como array (`status[]=open&status[]=closed&...`,
la forma que arma Laravel `Http::get()` a partir de un array PHP) devuelve **500 Internal Server Error** en
la REST API clásica — a diferencia de la tool `list_orders` del MCP, que sí lo aceptaba así. Sin el
parámetro, la API devuelve órdenes de todos los estados (incluidas `cancelled`), que es lo que
`SincronizadorOrdenes` necesita de todos modos.

**200** — ⚠️ **verificado contra la cuenta real (31/07/2026), forma real y completa (no la asumida
originalmente)**: body es un **array JSON plano** en la raíz (no `{"orders": [...]}`); campos usados por
`TraductorOrdenes` con sus tipos reales:

```json
[
  {
    "id": 111,
    "status": "open",
    "payment_status": "paid",
    "shipping_status": "unpacked",
    "storefront": "store",
    "total": "262252.00",
    "currency": "ARS",
    "completed_at": { "date": "2026-07-30 12:10:18.000000", "timezone_type": 3, "timezone": "UTC" },
    "created_at": "2026-07-30T12:10:18+0000",
    "contact_email": "comprador@ejemplo.com",
    "contact_name": "Nombre Apellido",
    "contact_identification": "20304050607",
    "products": [
      { "product_id": 123456, "variant_id": 987654, "name": "...", "sku": "9006", "quantity": 1, "price": "262252.00", "variant_values": [] }
    ]
  }
]
```

Diferencias clave respecto de lo asumido antes de verificar contra la cuenta real:
- **Sin objeto `customer`**: no hay ningún id de cliente estable. El comprador viene en campos planos
  `contact_email`/`contact_name`/`contact_identification` (documento crudo, reemplaza a `cpf_cnpj`) — `tn_customer_id`
  queda siempre `null`, `ResolutorCliente` resuelve por email (ya toleraba esto).
- **`total`/`currency` son campos planos de la orden**, no `total.amount`/`total.currency`. Mismo caso para
  `products[].price` (string plano, no `{amount, currency}`).
- **`completed_at` es un objeto** (`{date, timezone_type, timezone}`, formato de serialización de
  `DateTime` de PHP), no un string ISO — `TraductorOrdenes` extrae `completed_at.date`, con `created_at`
  (string ISO) como respaldo si `completed_at` viene vacío.
- **`fulfillment_status` no existe**: el campo real es `shipping_status`.

`storefront: "meli"` sigue descartándose por completo (FR-012a heredado de spec 019, sin cambios de lógica
en `TraductorOrdenes`, sólo de los nombres/formas de campo de arriba).

## 4. Actualización de variante — `PUT /{store_id}/products/{product_id}/variants/{variant_id}`

**Body** (uno o ambos campos, según qué sincronizador llama):
```json
{ "stock": 5 }
```
```json
{ "price": "15000.00" }
```

**200**: variante actualizada, cuerpo con la representación completa de la variante (no se usa, sólo se
confirma `successful()`). Sin batch (research.md R4) — una llamada por variante, reemplazando
`update_stock_and_price` (MCP).

## 5. Códigos de error relevantes

Idéntico a spec 022 §4 (`specs/022-tiendanube-conexion-rest/contracts` heredado de spec 015): 401/404 →
conexión caída sin reintento; 429/5xx → espera creciente acotada; otros 4xx → error no reintentable,
informado con el mensaje del proveedor.
