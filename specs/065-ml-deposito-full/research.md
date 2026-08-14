# Research: Depósito para publicaciones y órdenes Full de Mercado Libre

**Feature**: 065-ml-deposito-full
**Fecha**: 2026-08-13

Todas las consultas a la API de Mercado Libre citadas acá se hicieron **de sólo lectura** contra la
cuenta real del negocio desde el VPS (13/08/2026). No se ejecutó ninguna escritura.

---

## R1 — ¿Cómo se identifica una publicación Full?

**Decisión**: por `shipping.logistic_type === 'fulfillment'` del body de `GET /items`.

**Rationale**: es el único marcador confiable. Distribución real sobre las 270 vinculaciones:

| `logistic_type` | Cantidad | Significado |
|---|---|---|
| `xd_drop_off` | 260 | Colecta / drop off — stock del negocio |
| `not_specified` | 5 | Sin definir |
| `fulfillment` | **3** | **Full — stock en el centro de distribución de ML** |
| `self_service` | 1 | Flex |
| `custom` | 1 | Envío a cargo del vendedor |

**Alternativa descartada**: usar `inventory_id != null` como marcador. **Verificado que NO
funciona**: hay publicaciones `xd_drop_off` con `inventory_id` cargado (ej. `UJTG77764` en
`MLA850956249`). En la primera muestra parcial parecía correlacionar; sobre el universo completo no
correlaciona. `inventory_id` se conserva, pero sólo para deduplicar existencias (R4).

---

## R2 — ¿Dónde vive el stock en Mercado Libre?

**Hallazgo central**: el stock **no vive en la publicación**, vive en el *producto de usuario*
(`user_product_id`, formato `MLAU…`), que Mercado Libre expone con **dos ubicaciones separadas**:

```
GET /user-products/MLAU276256091/stock
{
  "locations": [
    { "type": "selling_address",                             "quantity": 7 },
    { "type": "meli_facility", "availability_type":"in_stock","quantity": 4 }
  ],
  "stock_mode": "countable"
}
```

- `selling_address` → existencia en el domicilio del vendedor. **Escribible** (es lo que el CRM ya
  actualiza hoy vía `PUT /items/{id}` con `available_quantity`).
- `meli_facility` → existencia en el centro de distribución de Mercado Libre (Full). **NO
  escribible**: sólo cambia cuando Mercado Libre recibe físicamente un envío o cuando vende.

La publicación expone en su `available_quantity` la ubicación que corresponde a **su** tipo de
logística: la publicación Full `MLA762900978` muestra `available_quantity = 4`, que es el
`meli_facility`.

**Consecuencia de diseño**: la regla "para Full el stock viaja siempre ML → CRM, nunca al revés" no
es una elección conservadora — es que del lado de Mercado Libre **no existe destino de escritura**.

---

## R3 — ¿De dónde se lee la existencia Full?

**Decisión**: de `GET /inventories/{inventory_id}/stock/fulfillment`, usando el `available_quantity`
de la respuesta.

```
GET /inventories/TPCW64194/stock/fulfillment
{ "inventory_id":"TPCW64194", "total":4, "available_quantity":4,
  "not_available_quantity":0, "not_available_detail":[],
  "external_references":[{"type":"item","id":"MLA762900978","variation_id":null}] }
```

**Rationale**:
- Es la fuente autoritativa y específica de Full.
- Viene **desglosada** entre vendible (`available_quantity`) y no vendible (`not_available_quantity`,
  con detalle de motivos: dañado, en transferencia, etc.). El CRM refleja sólo lo vendible (FR-009),
  que es lo que efectivamente puede despacharse.
- Está indexada por `inventory_id`, que es justamente la clave de deduplicación de R4.

**Alternativas descartadas**:
- `item.available_quantity` del multiget (cero llamadas extra): se descarta porque no distingue lo
  vendible de lo no vendible, y porque su significado depende del `logistic_type` de la publicación,
  lo que lo vuelve ambiguo si Mercado Libre cambia ese comportamiento. Con 3 publicaciones Full el
  ahorro de llamadas es irrelevante.
- `GET /items/{id}/stock`: **responde 404**, verificado. No existe.
- `/user-products/{id}/stock`: sirve y muestra ambas ubicaciones, pero exige filtrar
  `type == "meli_facility"` a mano y no desglosa vendible/no vendible. Queda como fuente secundaria
  documentada.

**Costo**: 1 llamada por inventario Full distinto por corrida. Hoy: 3.

---

## R4 — Deduplicación cuando varias publicaciones comparten producto

**Decisión**: agrupar por `inventory_id`. Publicaciones Full que comparten `inventory_id` computan
**una sola vez**; `inventory_id` distintos **suman**.

**Rationale**: verificado que un mismo `inventory_id` se repite entre publicaciones distintas en la
cuenta real (3 casos sobre 34 inventarios, ej. `XEIJ83575` → `MLA795022390` + `MLA2472189854`). Como
la existencia pertenece al inventario y no a la publicación, sumar por publicación duplicaría el
stock. Consultar por inventario deduplicado resuelve el problema y de paso reduce llamadas.

**Caso real que valida la funcionalidad completa** — producto CRM `12700`:

| Publicación | `logistic_type` | Existencia | Depósito destino en el CRM |
|---|---|---|---|
| `MLA762900978` | `fulfillment` | 4 | **Depósito Full** |
| `MLA1500482785` | `xd_drop_off` | 3 | Depósito general de ML |

Hoy el CRM ve "7 en un solo depósito". Después de esta funcionalidad: 4 en Full, 3 en el general.

**Anomalía documentada, fuera de alcance**: en Mercado Libre ese artículo existe como **dos
`user_product` distintos** (`MLAU276256091` con `selling_address = 7`, y `MLAU3196882771` con
`selling_address = 3`). Es una inconsistencia del catálogo de Mercado Libre, no del CRM. Leer la
existencia Full desde `/inventories/…/stock/fulfillment` evita que esa inconsistencia contamine el
depósito Full.

---

## R5 — ¿Cómo se determina que una **orden** es Full?

**Decisión**: por el `logistic_type` ya persistido del vínculo correspondiente al `ml_item_id` de
cada línea de la orden. Cero llamadas extra a la API.

**Rationale**: verificado que el payload de la orden **no trae** el tipo de logística — sólo
`shipping: { id }` y `tags`. La alternativa sería `GET /shipments/{shipping.id}`, que suma una
llamada por orden y un scope adicional, para obtener un dato que el CRM ya va a tener persistido por
FR-001. Si el vínculo no existe o no está clasificado, la orden se trata como **no Full** (FR-005),
que es el fallback seguro.

**Regla de imputación** (FR-020/FR-020a): la Venta va al depósito Full **sólo si todas sus líneas**
resuelven a publicaciones Full. Si mezcla, va al general — una Venta tiene un único `deposito_id` en
el modelo actual y partirla excede el alcance.

---

## R6 — Dónde engancha el reflejo ML → CRM, y el problema del modo sólo lectura

**Decisión**: separar los cortes previos de `SincronizadorStock` en dos:

- `verificarCortes()` (actual, para **escritura**): función avanzada activa + cuenta conectada +
  **modo sólo lectura**.
- `verificarCortesLectura()` (nuevo): función avanzada activa + cuenta conectada. **Sin** el corte de
  modo sólo lectura.

**Rationale**: hoy `verificarCortes()` aborta toda la corrida si `modo_solo_lectura` está activo
([SincronizadorStock.php:118](../../app/Services/MercadoLibre/SincronizadorStock.php#L118)). Pero ese
modo existe para impedir **escrituras hacia** Mercado Libre; traer información hacia el CRM no lo
viola (FR-014a). Sin esta separación, el stock Full quedaría congelado cada vez que se active el modo
sólo lectura.

---

## R7 — El bucle de sincronización se cierra solo

**Decisión**: no hace falta lógica extra para cumplir FR-013.

**Rationale**: `MovimientoStockObserver::ramaMercadoLibre()` sólo marca vínculos como pendientes si
`movimiento->deposito_id === depositoEfectivo()->id`
([MovimientoStockObserver.php:33-36](../../app/Observers/MovimientoStockObserver.php#L33-L36)). Como
FR-017 obliga a que el depósito Full sea **distinto** del general, los ajustes del reflejo ML → CRM
caen en un depósito que el observer ignora. El ciclo no se puede formar por construcción.

Se agrega igualmente un test de regresión que lo verifique, porque la propiedad depende de FR-017 y
se rompería en silencio si esa validación se relajara.

FR-007 (limpiar `stock_pendiente` de los vínculos Full salteados) sigue siendo necesario por otra
vía: un movimiento en el depósito **general** sobre un producto que también tiene publicación Full sí
marca ese vínculo Full como pendiente, y si el push lo saltea sin limpiar la marca, queda pendiente
para siempre y ensucia el indicador de estado de la grilla.

---

## R8 — Reutilizar el sincronizador de tipos de publicación

**Decisión**: extender `SincronizadorTiposPublicacion` (spec 050) para que el mismo multiget persista
también `logistic_type` e `inventory_id`. **No** se crea un sincronizador nuevo.

**Rationale**: ese servicio ya hace exactamente el `GET /items?ids=…` en chunks de 20 que se necesita
([SincronizadorTiposPublicacion.php:68](../../app/Services/MercadoLibre/SincronizadorTiposPublicacion.php#L68)),
ya corre a diario por comando, ya cubre el backfill inicial recorriendo todos los vínculos, ya se
invoca al vincular una publicación nueva (`sincronizarUno()`, FR-003) y ya conserva el último valor
conocido ante fallos (FR-004). Traer dos campos más del mismo body es costo cero en llamadas.

**Consecuencia**: el servicio pasa a clasificar más que "tipo de publicación". Se lo renombra
conceptualmente a "clasificación de publicaciones" en documentación, manteniendo el nombre de clase y
de comando para no romper el cron ya configurado en el VPS.

**Alternativa descartada**: un `SincronizadorLogistica` separado. Duplicaría el multiget, el manejo
de chunks, el manejo de errores y agregaría un segundo cron, sin ningún beneficio.

---

## R9 — Validación "depósito Full ≠ depósito general"

**Decisión**: validar en `GuardarConfiguracionVentasMercadoLibreRequest` con `different:deposito_id`,
mensaje en español explicando el motivo.

**Rationale**: si coincidieran, el reflejo ML → CRM sobrescribiría el stock físico real del negocio
con el del centro de distribución de Mercado Libre — pérdida de datos silenciosa. Además R7 depende
de esta separación para que no se forme el bucle. Es la validación más importante de la spec.

---

## R10 — Grilla de Vinculaciones

**Decisión**: `logistic_type` como columna nueva en el DataTables server-side existente, con etiqueta
legible y badge destacado sólo para Full; filtro por tipo de logística resuelto server-side sobre la
columna persistida.

**Rationale**: `MercadoLibreVinculacionController::datatable()` ya usa `DataTables::eloquent()` sobre
`MercadoLibrePublicacionProducto`
([controlador](../../app/Http/Controllers/Ingresos/MercadoLibreVinculacionController.php#L43)). Al ser
`logistic_type` una columna real de la tabla, el filtro y el ordenamiento salen sin trabajo extra ni
consultas a la API. Cumple la regla obligatoria del proyecto de tablas server-side por AJAX.

**Traducciones de etiqueta**: `fulfillment` → "Full" (badge destacado), `xd_drop_off` → "Colecta",
`self_service` → "Flex", `custom` → "A cargo del vendedor", `not_specified` → "Sin especificar",
`null` → "Sin clasificar".
