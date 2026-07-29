# Data Model: Ventas de Tiendanube

**Spec**: [../spec.md](../spec.md) · **Plan**: [../plan.md](../plan.md) · **Research**: [../research.md](../research.md)

Tres tablas nuevas (prefijo `tn_`, mismo criterio que `ml_`), dos alter sobre tablas existentes.

## `tn_ordenes`

| Campo | Tipo | Notas |
|---|---|---|
| `id` | bigint PK | |
| `tn_order_id` | bigint, **unique** | Número de orden en Tiendanube — identidad e idempotencia (FR-013). |
| `status` | string(20) | Valor crudo de Tiendanube (`open`/`closed`/`cancelled`). |
| `payment_status` | string(20) | Valor crudo (`pending`/`authorized`/`paid`/`partially_paid`/`abandoned`/`refunded`/`partially_refunded`/`voided`). |
| `shipping_status` | string(30), nullable | Informativo (FR-005); no participa del mapeo (research.md R1). |
| `estado_conversion` | enum-string (`Tiendanube\EstadoConversion`) | Derivado de `status`+`payment_status` (FR-007a). Persistido y recalculado en cada sincronización/intento de conversión. |
| `motivo` | enum-string (`Tiendanube\MotivoRequiereAtencion`), nullable | Sólo cuando `estado_conversion = requiere_atencion`. |
| `motivo_detalle` | string(255), nullable | Texto legible del motivo (FR-007b). |
| `fecha_creada` | datetime | `created_at` de Tiendanube. |
| `fecha_cerrada` | datetime, nullable | Usada como `fecha_emision` de la Venta, análogo a `ml_ordenes.fecha_cerrada`. |
| `total` | decimal(14,2) | |
| `moneda` | string(5) | ISO 4217. |
| `storefront` | string(20) | `store`/`api`/`form`/`pos` (nunca `meli` — se descarta antes de persistir, FR-012a, research.md R2). |
| `tn_customer_id` | bigint, nullable | Comprador — identificador de Tiendanube. |
| `comprador_email` | string(150), nullable | |
| `comprador_nombre` | string(150), nullable | |
| `billing_document_type` | string(20), nullable | Fuente de la derivación de comprobante (FR-039/FR-040). |
| `billing_document_number` | string(20), nullable | |
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
| `variant_id` | bigint | Identificador de variante en Tiendanube (siempre presente, incluso variante "virtual"). |
| `nombre_producto` | string(255) | |
| `nombre_variante` | string(255), nullable | Vacío si es la variante virtual de un producto sin variantes reales. |
| `cantidad` | decimal | |
| `precio_unitario` | decimal(14,2) | Precio FINAL con IVA incluido (research.md, mismo criterio que Mercado Libre). |
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
