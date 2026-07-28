# Research — Ventas de Mercado Libre (spec 012)

**Fecha**: 2026-07-27 · **Spec**: [spec.md](./spec.md)

---

## R1. ⚠️ CONTRADICCIÓN BLOQUEANTE: las Ventas del CRM hoy NO descuentan stock

**Decisión**: esta spec construye el descuento de stock por Venta como un **servicio compartido**, y
lo cablea tanto para las Ventas originadas en Mercado Libre **como para las Ventas manuales del CRM**.

**Hallazgo** (verificado en código, no inferido):

- `App\Services\Stock\StockService` expone `registrarSalida()` y `registrarEntrada()` desde la spec 008,
  pero **nadie las llama**. Verificado con búsqueda global: los únicos consumidores de `StockService`
  son `ProductoController` (stock inicial y ajustes), `StockController` (ajustes manuales),
  `ImportadorFilas` (stock inicial por importación) y `NotaCreditoDebitoController` (NC que afecta
  stock) — todos vía `ajustar()`.
- `VentaController::store()` crea la Venta, sus ítems, conceptos y etiquetas, pero **no genera ningún
  movimiento de stock**. `CompraController` tampoco.
- La propia documentación de dominio lo confirma: `docs/documentacion_principal_crm.md §6.2` describe
  el filtro "Operación" del Informe de Stock como *"limitado hoy a `Ajuste`/`Transferencia` — **no
  existen `Entrada`/`Salida` de Compras/Ventas todavía**"*.
- `docs/modelo_datos.md` (línea 226) dice lo mismo del enum `tipo`: *"`entrada`/`salida` quedan
  reservados para cuando existan Compras/Ventas"*.

**Por qué bloquea**:

1. **Contradice la spec**: FR-046 exige descontar stock *"con el mismo comportamiento que cualquier
   otra Venta del CRM"*. Ese comportamiento **no existe**. Tomado literalmente, FR-046 se cumpliría
   no haciendo nada — lo opuesto a lo que pide el usuario.
2. **Contradice la constitución**: la sección "Flujo de Desarrollo y Calidad" lista como requisito no
   opcional que *"el stock se afecta al vender/comprar, no al remitir"*.
3. **Invalida el objetivo del usuario**: el motivo declarado de toda la integración es la reciprocidad
   de stock — *"realice una venta manual y Mercado Libre no lo sabe"*. Si la venta manual **ni
   siquiera descuenta el stock local**, la spec 013 no tiene nada significativo que empujar hacia
   Mercado Libre. El problema de sobreventa sería insoluble por diseño.
4. **Generaría dos comportamientos divergentes**: cablearlo sólo para Mercado Libre dejaría las ventas
   de marketplace descontando stock y las manuales no — exactamente la inconsistencia silenciosa que
   el principio I de la constitución prohíbe.

**Rationale**: el principio I obliga a resolver la contradicción explícitamente antes de avanzar, y
la regla de oro de `CLAUDE.md` prohíbe resolver dependencias faltantes "construyendo una versión sin
esa dependencia". El costo incremental es bajo: `StockService::registrarSalida()`/`registrarEntrada()`
ya están escritos y probados, con lock atómico y `origen` polimórfico. Falta únicamente invocarlos
desde el ciclo de vida de la Venta y elegir el depósito.

**Alternativas consideradas**:

| Alternativa | Rechazada porque |
|---|---|
| Descontar stock sólo en Ventas de Mercado Libre | Crea dos comportamientos distintos para el mismo documento; viola el principio I; deja el objetivo del usuario sin cumplir. |
| Diferir todo el stock a la spec 013 | La 013 empuja stock *hacia* Mercado Libre; sin movimiento local no hay nada que empujar. Invierte el orden lógico de las dependencias. |
| Abrir una spec 012-bis sólo para el stock de Ventas | Fragmenta una unidad de trabajo de pocas horas y bloquea la 012 detrás de otro ciclo completo de spec-kit, sin beneficio. |

**Alcance concreto de la corrección** (deliberadamente mínimo, sin rediseñar el módulo Ventas):

- Un servicio `Ingresos\StockDeVenta` que aplique salida al crear la Venta, y reintegro al eliminarla
  o al editar cantidades, delegando en `StockService`.
- Depósito de origen: el configurado para Mercado Libre en las Ventas de esa procedencia; el depósito
  por defecto del CRM en las manuales.
- Sólo mueve stock por ítems con `producto_id` y de tipo Producto (los Servicios no controlan stock).
- **No** se toca el resto del módulo Ventas (formularios, listados, PDF).

**Impacto en documentación**: hay que corregir `docs/documentacion_principal_crm.md §6.2` y
`docs/modelo_datos.md` (nota del enum `tipo`), que hoy afirman que `entrada`/`salida` no existen.

---

## R2. API de órdenes de Mercado Libre — ✅ VERIFICADO contra la documentación oficial

**Verificado el 27/07/2026** contra la documentación oficial de Mercado Libre Developers (páginas
"Órdenes" y "Datos de Facturación", vía el servidor MCP autorizado por el usuario). Lo que sigue ya
**no** es supuesto.

### Búsqueda de órdenes

```
GET /orders/search?seller={SELLER_ID}&order.date_last_updated.from=...&order.date_last_updated.to=...
```

- **Paginación**: `paging: { total, offset, limit }` — por desplazamiento, `limit` por defecto 50.
  Confirma el diseño de R4.
- **Filtro incremental**: `order.date_last_updated.from` / `.to` existe y es exactamente lo que R4
  necesitaba. También hay `order.date_created.*` y `order.date_closed.*`.
- **Orden**: `sort=date_desc` (por defecto `date_asc`; para vendedor ordena por `date_closed`).
- **Estados** (`status`): `confirmed`, `payment_required`, `payment_in_process`, `partially_paid`,
  `paid`, `partially_refunded`, `pending_cancel`, `cancelled`.

### ⚠️ Hallazgo 1 — la búsqueda como vendedor EXCLUYE las órdenes canceladas

La documentación lo repite dos veces: *"si realizas la búsqueda como vendedor, filtras órdenes
canceladas"*. **Esto contradice FR-012** ("traer todas las órdenes, cualquiera sea su estado") y
rompería US6 (detectar cancelaciones posteriores) si se implementara con una sola búsqueda genérica.

**Resolución**: la sincronización hace **dos pasadas**:

1. Búsqueda incremental normal (trae las no canceladas).
2. Búsqueda explícita con `order.status=cancelled` sobre el mismo rango, para incorporar las
   canceladas y actualizar las ya conocidas.

Además, para las órdenes ya convertidas se consulta `GET /orders/{id}` puntualmente cuando haga falta
confirmar su estado actual, ya que son las que importan para US6.

### ⚠️ Hallazgo 2 — retención de 12 meses

*"Actualmente se guardan órdenes creadas hasta 12 meses"*. Acota naturalmente FR-016: no tiene sentido
configurar una primera sincronización de más de 12 meses. El campo `dias_primera_sync` debe topearse
ahí.

### ⚠️ Hallazgo 3 — respuestas parciales (HTTP 206)

El recurso puede devolver **206 Partial Content** con el encabezado `X-Content-Missing` indicando qué
bloques faltan (`buyer`, `feedback`, `mediations`, `seller`, `shipping`). El sincronizador **no debe
tratar un 206 como error**, pero tampoco puede asumir que `buyer` viene completo.

### Campos relevantes confirmados

| Campo | Uso en esta spec |
|---|---|
| `id`, `status`, `date_created`, `date_closed`, `total_amount`, `currency_id` | Datos base de la orden |
| `order_items[].item.id` | Identificador de publicación — clave de la vinculación |
| `order_items[].item.variation_id` | **No nulo ⇒ publicación con variantes** (FR-027) |
| `order_items[].item.seller_sku` / `seller_custom_field` | Código del vendedor |
| `order_items[].quantity`, `unit_price` | Cantidad y **precio unitario ya neto de descuentos** |
| `order_items[].gross_price` | Precio original antes de descuentos (informativo) |
| `order_items[].sale_fee` | **Comisión de Mercado Libre** — disponible aunque esta spec no la use (FR-049) |
| `buyer.id` | Identificador del comprador — **siempre presente** |
| `buyer.billing_info.id` | Necesario para el segundo llamado de datos fiscales |
| `tags` | Incluye `paid`, `delivered`, `test_order` y **`fraud_risk_detected`** |
| `taxes.amount` | **NO está incluido en `total_amount`** — ver nota abajo |
| `cancel_detail` | Motivo y quién solicitó la cancelación — útil para US6 |

> **Nota sobre `total_amount`**: la documentación define
> `total_amount_with_shipping = total_amount + taxes.amount + lead_time.cost`. Es decir, `total_amount`
> **no** incluye impuestos adicionales ni envío. Para esta spec, el total de la Venta se iguala a
> `total_amount` (los productos), coherente con FR-049 que deja envío y comisiones fuera de alcance.

### ⚠️ Hallazgo 4 — tag `fraud_risk_detected`

Tras aprobar el pago, Mercado Libre puede marcar una orden con `fraud_risk_detected` y notificar que
**la mercadería no debe enviarse** y la orden debe cancelarse. Convertirla en Venta y descontar stock
sería exactamente lo contrario de lo que corresponde. Se incorpora como motivo de bloqueo.

**Alternativas consideradas**: notificaciones en tiempo real (webhooks, tópico `orders_v2`) —
descartadas por decisión explícita del usuario y porque la spec 011 las dejó fuera de alcance.

---

## R3. Aislar la traducción del formato externo

**Decisión**: un único servicio traduce la respuesta cruda de Mercado Libre a las entidades del CRM.
Ningún controlador, job ni modelo interpreta la estructura del proveedor.

**Rationale**: protege contra cambios de la API y concentra en un solo archivo la incertidumbre
señalada en R2. Es además el patrón que la spec 011 ya adoptó con `ClienteMercadoLibre` como punto
único de salida.

---

## R4. Sincronización incremental e idempotente

**Decisión**: guardar la marca temporal de la última sincronización exitosa y pedir a Mercado Libre
sólo las órdenes actualizadas desde entonces, con un solapamiento de seguridad. La identidad es el
identificador de orden de Mercado Libre, con índice único, y la escritura es *upsert*.

**Rationale**: cubre FR-013 (actualizar sin duplicar), FR-015 (retomar tras interrupción) y FR-016
(acotar la primera corrida). El solapamiento evita perder órdenes por desfasaje de reloj entre el
servidor y Mercado Libre. La unicidad a nivel de base de datos hace que la no duplicación sea una
garantía estructural y no una convención.

**Alternativas consideradas**: paginar el historial completo en cada corrida — descartado por costo y
por chocar contra los límites de solicitudes.

---

## R5. Programación con frecuencia configurable

**Decisión**: registrar la tarea con evaluación por minuto y decidir en cada disparo si corresponde
ejecutar, comparando el tiempo transcurrido desde la última corrida contra la frecuencia guardada en
la configuración. Se suma un candado de ejecución única.

**Rationale**: la frecuencia vive en base de datos y el usuario la cambia desde la pantalla (FR-010);
leerla en cada evaluación evita tener que reescribir la programación o reiniciar nada. Funciona igual
con el `cron` de un hosting compartido y con un proceso permanente en VPS (FR-011), que es la
restricción de portabilidad heredada de la spec 011.

**Alternativas consideradas**: registrar expresiones de cron distintas según la configuración —
descartado porque la programación se resuelve al arrancar el proceso y no reflejaría un cambio hecho
desde la pantalla hasta el siguiente reinicio.

---

## R6. Exclusión mutua: sincronización y conversión

**Decisión**: dos candados de alcance distinto, ambos sobre el almacén de caché atómico ya usado por
la spec 011:

| Candado | Alcance | Cubre |
|---|---|---|
| Sincronización | Global, uno por corrida | FR-014 (no solapar corridas), FR-032a |
| Conversión | Por orden individual | FR-032a (manual vs. automática sobre la misma orden) |

Además, el bloqueo lógico se respalda con la restricción de unicidad sobre la referencia a la Venta:
aunque un candado fallara, la base de datos rechazaría el segundo vínculo.

**Rationale**: la spec 011 ya resolvió el mismo problema para la renovación de credenciales con
`Cache::lock(...)->block(...)`, y funciona tanto en hosting compartido (almacén en base de datos o
archivos) como en VPS. Reutilizar ese mecanismo mantiene una sola forma de hacer exclusión mutua en
el proyecto. La defensa en profundidad importa porque el costo de un duplicado no es cosmético: sería
una venta, una cobranza y un movimiento de stock fantasma.

---

## R7. Desagregación de IVA desde el precio final

**Decisión**: tratar el importe de cada línea como precio final con IVA incluido y obtener el neto
dividiendo por el coeficiente del IVA del producto vinculado. La diferencia por redondeo se absorbe
ajustando la última línea, de modo que la suma reconstruya exactamente el monto de la orden.

**Rationale**: FR-030 exige coincidencia exacta y FR-030a fija el supuesto. `CalculoComprobante` ya
calcula `subtotal_con_iva` a partir del neto, por lo que alcanza con invertir esa relación al armar
las líneas; el cálculo de totales se deja intacto y se sigue recalculando en el servidor. Absorber el
redondeo en una sola línea es preferible a repartirlo, porque mantiene la trazabilidad de qué línea
se ajustó.

**Alternativas consideradas**: guardar el precio de Mercado Libre como neto y dejar que el sistema le
sume IVA — produciría un total mayor al realmente cobrado, rompiendo la conciliación con Tesorería.

---

## R8. Derivación del tipo de comprobante — ✅ VERIFICADO, con divergencia deliberada

**Verificado el 27/07/2026** contra la documentación oficial ("Datos de Facturación").

### Cómo se obtiene el dato (dos llamados, no uno)

```
1) GET /orders/{ORDER_ID}                              → buyer.billing_info.id
2) GET /orders/billing-info/{SITE_ID}/{BILLING_INFO_ID} → datos fiscales
```

El diseño inicial asumía un solo llamado. **Son dos**, y el segundo sólo es posible si la orden trae
`buyer.billing_info.id`.

### Valores reales para Argentina (MLA)

| Campo | Valores confirmados |
|---|---|
| `identification.type` | `DNI`, `CUIL` (persona física) · `CUIT` (persona jurídica) |
| `taxes.taxpayer_type.description` | `Consumidor Final` (id `01`) · `IVA Responsable Inscripto` · `Monotributo` · `IVA Exento` |
| `attributes.cust_type` | `CO` (persona física) · `BU` (persona jurídica) |
| `attributes.vat_discriminated_billing` | El comprador pidió factura con IVA discriminado |
| `taxes.iibb_number` | Ingresos Brutos, sólo si el comprador tiene CUIT y lo informó |

### ⚠️ Divergencia deliberada respecto de la recomendación de Mercado Libre

La documentación oficial dice, textualmente:

> *"El campo `invoice_type` fue oficialmente removido de las respuestas de la API de Billing Info. La
> determinación del tipo de factura ahora es responsabilidad total de la lógica del integrador,
> basada en el documento de identidad del comprador. **Mapeo obligatorio para MLA: Documento CUIT →
> Emitir Factura A | Documento DNI → Emitir Factura B.**"*

**Este CRM NO adopta ese mapeo.** Deriva de la **condición frente al IVA** (`taxpayer_type`), no del
tipo de documento.

**Motivo**: el mapeo de Mercado Libre es incorrecto para un caso real y frecuente. **Un
Monotributista tiene CUIT**, y por la regla de Mercado Libre recibiría una Factura A. Fiscalmente, un
Responsable Inscripto que le vende a un Monotributista debe emitir **Factura B**, no A. Emitir A a un
Monotributista es un error fiscal, no una diferencia de criterio.

Además, el **principio III de la constitución** es explícito y no negociable:

> *"El tipo de comprobante (A/B/C/E) se deriva de la condición de IVA del cliente y del emisor; no se
> permite emitir sin condición de IVA cargada."*

El dato de condición de IVA **está disponible** (`taxes.taxpayer_type.description`), así que usar el
tipo de documento como sustituto sería degradar deliberadamente la precisión fiscal teniendo el dato
correcto a mano.

### Mapeo adoptado

| `taxpayer_type.description` | Comprobante | Fundamento |
|---|---|---|
| `IVA Responsable Inscripto` | **A** | Único caso que corresponde A |
| `Monotributo` | **B** | ⚠️ Tiene CUIT, pero fiscalmente le corresponde B |
| `IVA Exento` | **B** | |
| `Consumidor Final` | **B** | |
| Sin `billing_info` o sin `taxpayer_type` | **B** | Se asume Consumidor Final y se persiste (FR-040a) |

**Fallback**: si `taxpayer_type` no viniera pero sí el documento, se usa `CUIT → A`, `DNI/CUIL → B`
(la regla de Mercado Libre) como aproximación, dejándolo registrado para que el usuario pueda
corregirlo (FR-043).

**No** se consulta ARCA: no existe integración con el padrón en este CRM (sólo `App\Rules\CuitValido`,
validación local de dígito verificador módulo 11) y Mercado Libre ya provee el dato.

---

## R12. Identificación del comprador — corrección de diseño

**Decisión**: emparejar al Cliente por el **identificador numérico del comprador** (`buyer.id`), no
por el apodo.

**Motivo** (hallazgo de R2): en las respuestas de búsqueda como vendedor, el bloque `buyer` puede
venir **reducido a sólo `id`**, e incluso ausente por completo en respuestas parciales (HTTP 206 con
`X-Content-Missing: buyer`). Un emparejamiento que dependa exclusivamente del apodo fallaría de forma
intermitente y silenciosa.

**Diseño resultante**:

1. Emparejar por `comprador_ml_id` contra un campo nuevo del Cliente — identificador estable y
   siempre presente.
2. Si no hay coincidencia, emparejar por `apodo_ml` (compatibilidad con los clientes ya cargados a
   mano, §2.1 del dominio), y al hacerlo **guardar también el identificador** para que la próxima vez
   resuelva por la vía estable.
3. Si el apodo empareja con más de un Cliente, tratarlo como ambiguo (FR-038).

Esto refuerza FR-036 en lugar de reemplazarlo: el apodo sigue siendo el puente con los datos
existentes, pero deja de ser la única llave.

---

## R9. Cardinalidad uno a uno a nivel de datos

**Decisión**: dos índices únicos sobre la tabla de vinculación, uno por cada extremo de la relación.

**Rationale**: FR-022 exige que la restricción se garantice en los datos y no sólo en la interfaz. Dos
índices únicos la vuelven inviolable incluso ante escrituras concurrentes o futuras rutas de código
que olviden validar.

**Límite conocido y aceptado**: si el negocio publicara el mismo artículo en dos publicaciones, el
modelo requeriría migrar a una relación de uno a muchos. Decisión explícita del usuario.

---

## R10. Reutilización de la infraestructura de la spec 011

**Decisión**: toda llamada a Mercado Libre pasa por `ClienteMercadoLibre`. No se agrega ningún otro
punto de salida.

**Rationale**: ese servicio ya resuelve —y tiene probado— renovación perezosa de credenciales bajo
candado, reintentos con espera creciente ante 429 y 5xx, respeto de `Retry-After`, traducción de 403
por permiso funcional faltante, marcado de conexión caída ante 401 irrecuperable, guard de función
desactivada, kill-switch de escrituras y registro en el historial. Reimplementar cualquiera de esas
piezas sería duplicar lógica crítica ya validada.

**Consecuencia sobre FR-017**: el guard de función desactivada y el bloqueo de escrituras ya operan
dentro de `ClienteMercadoLibre::peticion()`. La sincronización debe además **cortar antes** de entrar
al bucle de paginación cuando la función está desactivada, el modo sólo lectura está activo o la
conexión está caída, para no generar una entrada de historial por página.

> **Nota sobre el modo sólo lectura**: la sincronización es una operación de **lectura** (`GET`), por
> lo que el kill-switch de la spec 011 **no la bloquearía por sí solo**. El bloqueo durante el modo
> sólo lectura que pide FR-017 es una decisión de esta spec —evitar que se creen Ventas automáticas
> mientras el sistema está en modo seguro— y debe implementarse como guard explícito del
> sincronizador, no esperarse de `ClienteMercadoLibre`.

---

## R11. Retención de datos

**Decisión**: sin purga automática de órdenes ni de líneas.

**Rationale**: son respaldo de documentos con impacto contable y muchas tienen una Venta asociada
(FR-061). El volumen de un único negocio no lo justifica. La retención acotada sigue aplicando sólo
al historial de operaciones de la spec 011, que sí es diagnóstico de alto volumen.
