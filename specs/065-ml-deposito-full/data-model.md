# Data Model: Depósito para publicaciones y órdenes Full de Mercado Libre

**Feature**: 065-ml-deposito-full
**Fecha**: 2026-08-13

Ninguna tabla nueva. Tres columnas nuevas sobre dos tablas existentes, en una única migración.

---

## `ml_publicacion_producto` (columnas nuevas)

Misma tabla que ya lleva `listing_type_id` de la spec 050; se sigue exactamente ese molde.

| Columna | Tipo | Descripción |
|---|---|---|
| `logistic_type` | `string(40)`, nullable, indexada | Tipo de logística crudo de Mercado Libre, de `shipping.logistic_type` del body de `GET /items`. Valores observados en la cuenta real: `fulfillment` (Full), `xd_drop_off` (colecta), `self_service` (Flex), `custom`, `not_specified`. `null` = todavía no clasificada. **Único indicador confiable de Full** (research R1). Indexada porque la usan el filtro de la grilla, la exclusión del push y la resolución de depósito de cada Venta. |
| `inventory_id` | `string(40)`, nullable, indexada | Identificador de inventario de Mercado Libre. **No sirve para detectar Full** (aparece también en publicaciones de logística propia — research R1); se usa exclusivamente como **clave de deduplicación** de existencias: publicaciones que comparten `inventory_id` computan una sola vez (FR-009b). Verificado que se repite entre publicaciones distintas en la cuenta real. |
| `logistica_sincronizada_en` | `datetime`, nullable | Cuándo se determinó por última vez el tipo de logística de este vínculo. Análogo a `listing_type_sincronizado_en`. Sirve para diagnosticar clasificaciones viejas. |

> **Nota**: `logistic_type` e `inventory_id` conservan el nombre crudo de la API, en inglés,
> siguiendo el precedente ya aceptado de `listing_type_id` (spec 050). La traducción a etiquetas en
> español ocurre en la capa de presentación. Justificado en `plan.md` §Complexity Tracking.

### Reglas de derivación

- **`esFull()`**: `logistic_type === 'fulfillment'`. Cualquier otro valor, **incluido `null`**, es
  no-Full (FR-005). Nunca se asume Full por defecto.
- **Persistencia**: la escribe `SincronizadorTiposPublicacion` en el mismo multiget que ya trae
  `listing_type_id` (research R8). Ante fallo de un chunk o de un ítem, el vínculo **conserva su
  último valor conocido** — no se pisa con `null` (FR-004).
- **Scopes**: `esFull()` / `noFull()` sobre el query builder, para no repetir la comparación literal
  en los cuatro puntos que la necesitan.

---

## `ml_configuracion` (columna nueva)

| Columna | Tipo | Descripción |
|---|---|---|
| `deposito_full_id` | `bigint`, nullable, FK → `depositos.id`, `nullOnDelete` | Depósito del CRM que representa la mercadería del negocio alojada en el centro de distribución de Mercado Libre. **Opcional** (FR-016). Convive con `deposito_id` sin reemplazarlo. `nullOnDelete` para no bloquear la eliminación de un depósito, igual que `deposito_id`. |

### Reglas de derivación y validación

- **`depositoFullEfectivoONulo(): ?Deposito`** — devuelve el depósito sólo si `deposito_full_id` está
  seteado **y** el depósito está activo. En cualquier otro caso devuelve `null`.

  > **Diferencia deliberada con `depositoEfectivo()`**: el depósito general cae a
  > `Deposito::porDefecto()` cuando no hay uno configurado. El depósito Full **no tiene fallback
  > propio**: si no está configurado, la funcionalidad de Full simplemente no opera (FR-014) y la
  > imputación de Ventas cae al depósito general (FR-021). Caer a `Deposito::porDefecto()` sería
  > peligroso: escribiría existencias del centro de distribución de Mercado Libre sobre un depósito
  > físico real. Mismo criterio que `lista_precio_id` de la spec 016, que tampoco tiene fallback.

- **Validación de unicidad cruzada** (FR-017, research R9): `deposito_full_id` **debe ser distinto**
  de `deposito_id`. Regla `different:deposito_id` en
  `GuardarConfiguracionVentasMercadoLibreRequest`, con mensaje en español explicando el motivo. Es la
  validación más importante de la feature: si coincidieran, el reflejo ML → CRM sobrescribiría el
  stock físico real del negocio, y además se abriría el ciclo de sincronización que FR-013 prohíbe
  (research R7).

---

## Entidades existentes afectadas, sin cambio de esquema

| Entidad | Impacto |
|---|---|
| `ventas.deposito_id` | Sin cambio de esquema. Cambia **quién lo calcula**: pasa de ser siempre `depositoEfectivo()` a resolverse según la logística de las líneas de la orden (FR-020/FR-020a). |
| `stock` / `movimientos_stock` | Sin cambio de esquema. El reflejo Full genera movimientos de ajuste normales sobre el depósito Full, vía `StockService::ajustar()`, con origen identificable (FR-010). |
| `depositos` | Sin cambio. El usuario da de alta el depósito Full a mano desde la gestión existente (FR-018). |

---

## Invariantes

1. **Un vínculo Full nunca produce una escritura de existencias hacia Mercado Libre.** (FR-006,
   FR-009c) — la existencia del centro de distribución de Mercado Libre no es escribible por API.
2. **El depósito Full nunca coincide con el depósito general.** (FR-017) — de esta invariante depende
   que no se forme el ciclo de sincronización (research R7).
3. **Un `inventory_id` se refleja una sola vez por corrida**, sin importar cuántas publicaciones lo
   compartan. (FR-009b)
4. **`logistic_type = null` se comporta idénticamente a una publicación de logística propia.**
   (FR-005) — el sistema nunca asume Full ante la duda.
5. **Ninguna condición de esta feature puede impedir que una orden se convierta en Venta.** (FR-022)
