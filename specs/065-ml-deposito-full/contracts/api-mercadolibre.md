# Contrato: endpoints de Mercado Libre consumidos

**Feature**: 065-ml-deposito-full

Todas las respuestas de abajo son **capturas reales** de la cuenta del negocio (13/08/2026,
consultas de sólo lectura). No hay ninguna escritura nueva hacia Mercado Libre en esta feature.

---

## 1. `GET /items?ids={id1,id2,…}` — multiget (YA EN USO, se amplía lo que se lee)

Ya lo invoca `SincronizadorTiposPublicacion` en chunks de 20 (límite de la API). **No se agrega
ninguna llamada**: se leen dos campos más del mismo body.

**Campos que pasan a leerse**:

| Campo del body | Destino | Uso |
|---|---|---|
| `shipping.logistic_type` | `ml_publicacion_producto.logistic_type` | Único indicador de Full |
| `inventory_id` | `ml_publicacion_producto.inventory_id` | Clave de deduplicación de existencias |

**Respuesta real (recortada)** — publicación Full:

```json
{
  "code": 200,
  "body": {
    "id": "MLA762900978",
    "available_quantity": 4,
    "inventory_id": "TPCW64194",
    "user_product_id": "MLAU276256091",
    "shipping": {
      "mode": "me2",
      "logistic_type": "fulfillment",
      "tags": ["self_service_in", "mandatory_free_shipping"],
      "free_shipping": true
    }
  }
}
```

**Manejo de errores**: si `code !== 200` o falta `shipping.logistic_type`, el vínculo **conserva su
último valor conocido**; no se pisa con `null` y no se aborta el resto de los chunks (FR-004).

---

## 2. `GET /inventories/{inventory_id}/stock/fulfillment` — NUEVO

Fuente autoritativa de la existencia Full. Se invoca **una vez por `inventory_id` distinto** de los
vínculos Full (hoy: 3 llamadas por corrida).

**Respuesta real**:

```json
{
  "inventory_id": "TPCW64194",
  "total": 4,
  "available_quantity": 4,
  "not_available_quantity": 0,
  "not_available_detail": [],
  "external_references": [
    { "type": "item", "id": "MLA762900978", "variation_id": null }
  ]
}
```

**Campo que se refleja**: `available_quantity` — la existencia **vendible**. El
`not_available_quantity` (dañado, en transferencia, etc.) **no se computa** (FR-009), porque no es
mercadería despachable.

**Manejo de errores**: ante fallo, ese inventario se saltea conservando la existencia actual del CRM;
se cuenta como error en el resultado y **no** se aborta la corrida ni se pone el stock en cero.

---

## 3. `PUT /items/{id}` con `available_quantity` — YA EN USO, se ACOTA

Es el push de stock actual. **Cambio**: las publicaciones con `logistic_type = 'fulfillment'` quedan
excluidas (FR-006). El resto se comporta exactamente igual que hoy (SC-007).

> **Por qué se excluyen**: en una publicación Full, `available_quantity` refleja la existencia del
> centro de distribución de Mercado Libre, que **no es escribible**. Sólo cambia cuando Mercado Libre
> recibe físicamente un envío o cuando vende. No es una decisión conservadora: del otro lado no hay
> destino de escritura.

---

## Endpoints evaluados y descartados

| Endpoint | Resultado | Por qué se descartó |
|---|---|---|
| `GET /items/{id}/stock` | **HTTP 404** | No existe. Verificado sobre las 3 publicaciones Full. |
| `GET /user-products/{user_product_id}/stock` | Funciona | Devuelve ambas ubicaciones (`selling_address` y `meli_facility`), pero exige filtrar por tipo a mano y **no desglosa** vendible de no vendible. Queda documentado como fuente secundaria. Respuesta real: `{"locations":[{"type":"selling_address","quantity":7},{"type":"meli_facility","availability_type":"in_stock","quantity":4}],"stock_mode":"countable"}` |
| `GET /shipments/{shipping.id}` | No probado | Serviría para clasificar una orden como Full, pero suma una llamada por orden y un scope adicional para obtener un dato que el CRM ya tendrá persistido (research R5). |

---

## Nota sobre el payload de las órdenes

Verificado que `GET /orders/{id}` **no trae** el tipo de logística. Sólo:

```json
{ "shipping": { "id": 47764620769 }, "tags": ["b2b","order_has_discount","paid","not_delivered"], "fulfilled": null }
```

Por eso la clasificación de una orden como Full se resuelve por el `logistic_type` **ya persistido**
del vínculo correspondiente al `ml_item_id` de cada línea, sin llamadas extra (research R5).
