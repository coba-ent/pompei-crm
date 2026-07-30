# Data Model: Ventas de Tiendanube

**Spec**: [../spec.md](../spec.md) · **Plan**: [../plan.md](../plan.md) · **Research**: [../research.md](../research.md)

Tres tablas nuevas (prefijo `tn_`, mismo criterio que `ml_`), dos alter sobre tablas existentes.

## `tn_ordenes`

| Campo | Tipo | Notas |
|---|---|---|
| `id` | bigint PK | |
| `tn_order_id` | bigint, **unique** | **Corrección post-019**: mapea al campo `id` de la tool `list_orders` (identificador estable del recurso), no a `number` (correlativo visible al comprador) — identidad e idempotencia (FR-013). |
| `status` | string(20) | Valor crudo de Tiendanube (`open`/`closed`/`cancelled`). |
| `payment_status` | string(20) | Valor crudo — verificado contra la tool real: `pending`/`authorized`/`paid`/`partially_paid`/`voided`/`expired`/`refunded`/`partially_refunded`/`abandoned`/`chargeback` (agrega `expired`/`chargeback` respecto de la versión original de esta tabla). |
| `fulfillment_status` | string(30), nullable | **Corrección post-019**: la tool real llama así a lo que esta tabla llamaba `shipping_status`. Informativo (FR-005); no participa del mapeo (research.md R1). |
| `estado_conversion` | enum-string (`Tiendanube\EstadoConversion`) | Derivado de `status`+`payment_status` (FR-007a). Persistido y recalculado en cada sincronización/intento de conversión. |
| `motivo` | enum-string (`Tiendanube\MotivoRequiereAtencion`), nullable | Sólo cuando `estado_conversion = requiere_atencion`. |
| `motivo_detalle` | string(255), nullable | Texto legible del motivo (FR-007b). |
| `fecha_creada` | datetime | **Corrección post-019**: la tool real `list_orders` sólo expone `completed_at` — no hay `created_at`/`closed_at` separados. Se completa con `completed_at` (mismo valor que `fecha_cerrada`); se conserva la columna por compatibilidad con el patrón de `ml_ordenes`, aunque en Tiendanube ambas fechas coincidan siempre. |
| `fecha_cerrada` | datetime, nullable | `completed_at` de la tool `list_orders`. Usada como `fecha_emision` de la Venta, análogo a `ml_ordenes.fecha_cerrada`. |
| `total` | decimal(14,2) | Mapea a `order.total.amount` (objeto `{amount, currency}` en la respuesta real, no un escalar). |
| `moneda` | string(5) | ISO 4217, `order.total.currency`. |
| `storefront` | string(20) | Verificado contra órdenes reales: `store`/`form`/`mobile` (nunca `meli` observado todavía en la cuenta real, pero el campo es el único dato disponible para detectarlo — se descarta antes de persistir, FR-012a, research.md R2 corregido). **Corrección**: no hay `api`/`pos` confirmados; se documentan como posibles pero no verificados. |
| `tn_customer_id` | bigint, nullable | Comprador — `order.customer.id`. |
| `comprador_email` | string(150), nullable | `order.customer.email`. |
| `comprador_nombre` | string(150), nullable | `order.customer.name`. |
| `billing_document_number` | string(20), nullable | **Corrección post-019**: mapea a `order.customer.cpf_cnpj` (documento crudo, sin clasificar) — no existe `billing_document_type` en la tool real. Verificado nulo en las 9 órdenes reales de la tienda. La derivación de comprobante (FR-039/FR-040) aproxima el tipo por **longitud** de este valor cuando esté presente. |
| `venta_id` | FK → `ventas`, nullable, **unique** | Garantiza a nivel de datos que una orden genera como máximo una Venta (FR-032b). |
| `creacion_automatica` | boolean, default false | |
| `convertida_en` | timestamp, nullable | |
| `convertida_por` | FK `users.id`, nullable | |
| `payload` | json, nullable | Respuesta cruda, sin datos sensibles. |
| `sincronizada_en` | timestamp | Última actualización desde Tiendanube. |

**Sin soft delete ni purga** (FR-061) — respaldo de documentos contables, mismo criterio que `ml_ordenes`.

**Transiciones válidas de `estado_conversion`** (idénticas a `MercadoLibre\EstadoConversion`,
research.md R6): `pendiente_pago → lista | cancelada` · `lista → requiere_atencion | convertida |
cancelada` · `requiere_atencion → lista | cancelada` · `convertida → cancelada` (la orden se cancela
después de convertida; la Venta permanece intacta, FR-058) · `cancelada` no transiciona.

## `tn_orden_items`

| Campo | Tipo | Notas |
|---|---|---|
| `id` | bigint PK | |
| `tn_orden_id` | FK → `tn_ordenes`, cascade | |
| `tn_product_id` | bigint | **Agregado post-019**: `item.product_id` de la línea real — necesario para `update_stock_and_price` (spec 018), que exige `product_id` además de `variant_id`. No estaba en el diseño original de esta tabla. |
| `variant_id` | bigint | `item.variant_id` (siempre presente, incluso variante "virtual"). |
| `nombre_producto` | string(255) | `item.name` — es el nombre del **producto**; la tool real no separa nombre de producto y nombre de variante en campos distintos. |
| `nombre_variante` | string(255), nullable | **Corrección post-019**: no existe un campo de nombre de variante suelto — se arma concatenando `item.variant_values` (array de valores de atributo, ej. `["Rojo", "M"]`; vacío para la variante virtual de un producto sin variantes reales, que es el caso observado en las 9 órdenes reales de la tienda). |
| `sku` | string(100), nullable | **Agregado post-019**: `item.sku`, disponible en la respuesta real y útil para diagnóstico/vinculación manual. |
| `cantidad` | decimal | `item.quantity`. |
| `precio_unitario` | decimal(14,2) | `item.price.amount` (objeto `{amount, currency}`, no escalar) — precio FINAL con IVA incluido (research.md, mismo criterio que Mercado Libre). |
| `total_linea` | decimal(14,2) | |
| `producto_id` | FK → `productos`, nullable | Se congela al convertir. |

## `tn_variante_producto`

Vinculación **estrictamente 1:1** (research.md R3).

| Campo | Tipo | Notas |
|---|---|---|
| `id` | bigint PK | |
| `variant_id` | bigint, **unique** | |
| `producto_id` | FK → `productos`, **unique** | |
| `nombre_variante_tn` | string(255) | Nombre producto+variante al momento de vincular (trazabilidad si cambia en Tiendanube). |
| `vinculada_por` | FK `users.id`, nullable | |
| `created_at` / `updated_at` | timestamps | |

Los dos índices únicos garantizan la cardinalidad 1:1 a nivel de datos (FR-022), mismo patrón que
`ml_publicacion_producto`.

## `tn_configuracion` (columnas nuevas — extiende spec 015)

| Campo | Tipo | Notas |
|---|---|---|
| `creacion_automatica` | boolean, default false | FR-050. |
| `frecuencia_sync_minutos` | unsignedSmallInteger, default 15 | Mismo rango que Mercado Libre (5\|10\|15\|30\|60). |
| `deposito_id` | FK → `depositos`, nullable, `nullOnDelete` | FR-047. `depositoEfectivo()` propio (research.md R4). |
| `categoria_venta_id` | FK → `categorias`, nullable, `nullOnDelete` | Mismo patrón que Mercado Libre. |
| `cuenta_tesoreria_id` | FK → `cuentas_tesoreria`, nullable, `nullOnDelete` | FR-045/045a. **Nuevo respecto de Mercado Libre** — ahí es un lookup por nombre, acá es configurable (research.md R5). |
| `dias_primera_sync` | unsignedSmallInteger, default 30 | FR-016. |
| `ultima_sync_en` | datetime, nullable | |
| `ultima_sync_resultado` | string(255), nullable | |

## `clientes` (columna nueva)

| Campo | Tipo | Notas |
|---|---|---|
| `tn_customer_id` | bigint, nullable | Análogo a `ml_user_id`. Emparejamiento estable (FR-036/036a): se persiste la primera vez que un Cliente se empareja por email. |

## `ventas` (sin columna nueva, valor de enum nuevo)

`origen` (existente, spec 012) agrega el valor `'tiendanube'` junto a `manual`/`presupuesto`/
`mercadolibre`. Alimenta la columna/filtro "Creada Desde" ya existente (FR-035a) y la rama
`resolverDeposito()` de `StockDeVenta` (plan.md §5).

## Diagrama de relaciones

```text
tn_ordenes ──< tn_orden_items >── productos (nullable, congelado al convertir)
    │
    │ venta_id (unique) → ventas
    │
tn_variante_producto ── producto_id (unique) → productos
                       ── variant_id (unique, externo)

tn_configuracion (registro único)
    ├── deposito_id → depositos
    ├── categoria_venta_id → categorias
    └── cuenta_tesoreria_id → cuentas_tesoreria

clientes.tn_customer_id (externo, sin FK — igual que ml_user_id)
```

Sin relaciones entre las tablas `tn_*` y las `ml_*`: integraciones independientes (research.md R4/R6).
