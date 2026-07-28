# Data Model — Ventas de Mercado Libre (spec 012)

**Fecha**: 2026-07-27 · **Spec**: [spec.md](./spec.md) · **Plan**: [plan.md](./plan.md)

Convenciones del proyecto: nombres en español, snake_case, sin `empresa_id` (single-tenant).

---

## 1. Enums

### `EstadoConversion` — estado de la orden dentro del CRM (FR-007a)

| Valor | Significado | ¿Habilita "Crear Venta"? |
|---|---|---|
| `pendiente_pago` | Pago no confirmado en Mercado Libre | No |
| `lista` | Pagada y resoluble | **Sí** |
| `requiere_atencion` | Pagada pero bloqueada (ver `MotivoRequiereAtencion`) | No |
| `convertida` | Ya generó una Venta | No |
| `cancelada` | Cancelada o reembolsada en Mercado Libre | No |

**Transiciones válidas** (FR-057/FR-058/FR-059):

```
pendiente_pago  → lista | cancelada
lista           → requiere_atencion | convertida | cancelada
requiere_atencion → lista | cancelada
convertida      → cancelada          (la Venta permanece intacta — FR-058)
cancelada       → (terminal)
```

`convertida` **nunca** vuelve a un estado anterior: una Venta creada no se "descrea".

### `MotivoRequiereAtencion` (FR-007b / FR-052)

| Valor | Cuándo | Cómo se resuelve |
|---|---|---|
| `publicacion_sin_vincular` | Alguna línea no tiene publicación vinculada | Vincular la publicación a un producto |
| `publicacion_con_variantes` | `order_items[].item.variation_id` no es nulo (FR-027) | Sin solución automática — resolver a mano |
| `cliente_ambiguo` | Más de un Cliente con el mismo apodo ML (FR-038) | Corregir los apodos duplicados |
| `producto_inexistente` | El producto vinculado fue eliminado o inactivado | Re-vincular a un producto vigente |
| `moneda_invalida` | `currency_id` distinto del of the negocio (FR-030d) | Resolver a mano |
| `alerta_fraude` | Tag `fraud_risk_detected` (FR-052a) | **No convertir**: la orden debe cancelarse y no despacharse |
| `datos_incompletos` | Respuesta parcial sin el bloque del comprador (FR-012b) | Re-sincronizar |
| `error_conversion` | Falla inesperada durante la conversión (FR-055) | Reintentar; el detalle queda en `motivo_detalle` |

### `EstadoOrden` — estado tal como lo informa Mercado Libre

Se persiste el valor crudo del proveedor en `estado_ml` (string) **además** del enum normalizado, para
no perder información ante estados nuevos del proveedor. El enum cubre los conocidos: `pendiente`,
`pagada`, `cancelada`, `otro`.

---

## 2. `ml_ordenes` (NUEVA)

Órdenes sincronizadas desde Mercado Libre. **"Orden" = documento de Mercado Libre; "Venta" = documento
del CRM** (convención terminológica de la spec).

| Campo | Tipo | Notas |
|---|---|---|
| `id` | bigint PK | |
| `ml_order_id` | string(40) | **UNIQUE** — identidad e idempotencia (FR-014/FR-032). Es la garantía estructural de no duplicación. |
| `estado_ml` | string(40) | Valor crudo del proveedor, sin normalizar |
| `estado_orden` | enum | `EstadoOrden` normalizado |
| `estado_conversion` | enum | `EstadoConversion` — derivado y persistido (ver plan §3) |
| `motivo` | enum, nullable | `MotivoRequiereAtencion` |
| `motivo_detalle` | text, nullable | Texto legible del bloqueo, para mostrar al usuario |
| `fecha_creada` | datetime | Fecha de creación de la orden en Mercado Libre |
| `fecha_cerrada` | datetime, nullable | **Se usa como `fecha_emision` de la Venta** (Assumptions) |
| `total` | decimal(14,2) | Monto de la orden — la Venta debe igualarlo exactamente (FR-030) |
| `moneda` | string(5) | `ARS` esperado |
| `comprador_ml_id` | string(40) | `buyer.id` — **llave primaria de emparejamiento** (FR-036), siempre presente |
| `comprador_apodo` | string(120), nullable | Llave secundaria contra `clientes.apodo_ml` (FR-036). **Puede faltar** (research §R2) |
| `comprador_nombre` | string(180), nullable | `billing_info.name` + `last_name` |
| `billing_info_id` | string(40), nullable | `buyer.billing_info.id` — necesario para el 2.º llamado (research §R8) |
| `comprador_doc_tipo` | string(20), nullable | `DNI` · `CUIL` · `CUIT` |
| `comprador_doc_numero` | string(20), nullable | |
| `comprador_condicion_iva` | string(60), nullable | `taxes.taxpayer_type.description` — **insumo real de la derivación** (FR-039/FR-040) |
| `es_prueba` | boolean, default false | Tag `test_order` (FR-008) |
| `tiene_alerta_fraude` | boolean, default false | Tag `fraud_risk_detected` — **bloquea la conversión** (FR-052a) |
| `datos_faltantes` | string(120), nullable | Contenido de `X-Content-Missing` en respuestas parciales (FR-012b) |
| `venta_id` | FK → `ventas`, nullable, **UNIQUE** | Unicidad = segunda defensa contra duplicados (research R6) |
| `creacion_automatica` | boolean, default false | FR-054 |
| `convertida_en` | datetime, nullable | FR-054 |
| `convertida_por` | FK → `users`, nullable | Null cuando la creación fue automática |
| `payload` | json, nullable | Respuesta cruda, para diagnóstico. **Sin datos sensibles** (FR-034 spec 011) |
| `sincronizada_en` | datetime | Última actualización desde Mercado Libre |
| `timestamps` | | |

**Índices**: `ml_order_id` (unique) · `venta_id` (unique) · `estado_conversion` · `fecha_cerrada` ·
`comprador_apodo`.

**Sin borrado lógico**: no se purgan (FR-061). No son documentos contables propios sino respaldo del
origen; el documento contable es la Venta, que sí usa borrado lógico.

---

## 3. `ml_orden_items` (NUEVA)

| Campo | Tipo | Notas |
|---|---|---|
| `id` | bigint PK | |
| `ml_orden_id` | FK → `ml_ordenes`, cascade | |
| `ml_item_id` | string(40) | Identificador de la publicación — clave de la vinculación |
| `ml_variation_id` | string(40), nullable | `item.variation_id` — **si viene con valor → `publicacion_con_variantes`** (FR-027) |
| `titulo` | string(255) | `item.title` al momento de la venta |
| `sku_vendedor` | string(120), nullable | `item.seller_sku` o `item.seller_custom_field` |
| `cantidad` | decimal(14,4) | `quantity` |
| `precio_unitario` | decimal(14,2) | `unit_price` — **precio FINAL con IVA incluido y ya neto de descuentos** (FR-030a) |
| `precio_bruto` | decimal(14,2), nullable | `gross_price` — antes de descuentos. Informativo; puede faltar en órdenes antiguas |
| `comision_ml` | decimal(14,2), nullable | `sale_fee` — **se guarda aunque esta spec no la use** (FR-049), para no re-sincronizar cuando se especifique |
| `total_linea` | decimal(14,2) | `unit_price × quantity` |
| `producto_id` | FK → `productos`, nullable | Producto resuelto al convertir (snapshot histórico) |
| `timestamps` | | |

**Índices**: `ml_orden_id` · `ml_item_id`.

`producto_id` se congela al convertir: si después se borra la vinculación, la línea conserva con qué
producto se convirtió (FR-026/FR-062).

---

## 4. `ml_publicacion_producto` (NUEVA) — vinculación 1:1

**Infraestructura compartida con la spec 013.**

| Campo | Tipo | Notas |
|---|---|---|
| `id` | bigint PK | |
| `ml_item_id` | string(40) | **UNIQUE** — una publicación, un solo producto (FR-022) |
| `producto_id` | FK → `productos`, cascade | **UNIQUE** — un producto, una sola publicación (FR-022) |
| `titulo_ml` | string(255), nullable | Título al vincular, para reconocerla en el listado |
| `vinculada_por` | FK → `users`, nullable | |
| `timestamps` | | |

**Los dos índices únicos son el corazón de FR-022**: hacen la cardinalidad inviolable a nivel de base
de datos, incluso ante escrituras concurrentes o rutas de código que olviden validar.

**Límite conocido y aceptado**: publicar el mismo artículo en dos publicaciones exigiría migrar a uno
a muchos (decisión explícita del usuario).

---

## 5. `ml_configuracion` (EXTENDER)

Columnas nuevas sobre la tabla existente de la spec 011:

| Campo | Tipo | Notas |
|---|---|---|
| `creacion_automatica` | boolean, default **false** | FR-050 — apagado por defecto |
| `frecuencia_sync_minutos` | unsignedSmallInt, default **15** | FR-010. Valor conservador para hosting compartido; se baja desde la pantalla al migrar a VPS |
| `deposito_id` | FK → `depositos`, nullable | FR-047. Null ⇒ depósito por defecto del CRM |
| `categoria_venta_id` | FK → `categorias`, nullable | Categoría por defecto (Assumptions) |
| `dias_primera_sync` | unsignedSmallInt, default **30** | FR-016 — acota la primera corrida |
| `ultima_sync_en` | datetime, nullable | Marca incremental (research R4) |
| `ultima_sync_resultado` | string(255), nullable | Para mostrar el estado en pantalla |

---

## 6. `ventas` (EXTENDER)

| Campo | Tipo | Notas |
|---|---|---|
| `origen` | enum(`manual`,`presupuesto`,`mercadolibre`), default `manual` | FR-035. Hoy "Creada Desde" se deriva de `presupuesto_id`; se explicita para admitir el tercer origen |

`ml_ordenes.venta_id` provee la relación inversa; no se agrega FK en `ventas` para no acoplar el módulo
Ingresos a la integración.

---

## 7. Cierre de la brecha de stock (research R1)

**No agrega tablas.** `movimientos_stock` ya tiene los valores `entrada`/`salida` en su enum `tipo` y
las columnas polimórficas `origen_type`/`origen_id`, reservadas desde la spec 002 justamente para esto.
`StockService::registrarSalida()`/`registrarEntrada()` ya están implementadas con lock atómico.

Lo único que falta es **invocarlas** desde el ciclo de vida de la Venta:

| Momento | Acción | Depósito |
|---|---|---|
| Alta de Venta | `registrarSalida()` por cada ítem con `producto_id` de tipo Producto | ML: `ml_configuracion.deposito_id`; manual: depósito por defecto |
| Edición de Venta | Reintegrar lo anterior y aplicar lo nuevo | El mismo con que se registró |
| Borrado lógico de Venta | `registrarEntrada()` (reintegro) | El mismo con que se registró |

Los ítems de tipo Servicio y los ítems libres (sin `producto_id`) no mueven stock.

**Documentación a corregir** (obligatorio antes de `/speckit-tasks`): `docs/documentacion_principal_crm.md §6.2`
y la nota del enum `tipo` en `docs/modelo_datos.md` afirman hoy que `entrada`/`salida` no existen.

---

## 8. Cálculos clave

### Desagregación de IVA (FR-030a, research R7)

Mercado Libre informa **precio final con IVA incluido**. `CalculoComprobante` espera el neto, así que
se invierte la relación al armar las líneas:

```
neto_unitario = precio_final_unitario / (1 + iva_pct/100)
```

donde `iva_pct` sale de `Producto::porcentajeIva($producto->iva_venta_pct)`.

**Conciliación exacta** (FR-030): tras calcular todas las líneas, si el total difiere del monto de la
orden por redondeo, la diferencia se absorbe ajustando el neto de la **última** línea. Se ajusta una
sola línea —no se reparte— para que quede trazable cuál se corrigió.

### Derivación del tipo de comprobante (FR-039/FR-040, research R8)

Requiere **dos llamados**: `GET /orders/{id}` → `buyer.billing_info.id`, y luego
`GET /orders/billing-info/{SITE_ID}/{BILLING_INFO_ID}`.

| `taxes.taxpayer_type.description` | Comprobante |
|---|---|
| `IVA Responsable Inscripto` | **A** |
| `Monotributo` | **B** ⚠️ tiene CUIT, pero fiscalmente le corresponde B |
| `IVA Exento` | **B** |
| `Consumidor Final` (id `01`) | **B** |
| Sin `billing_info` o sin `taxpayer_type` | **B**, y se persiste "Consumidor Final" (FR-040a) |

> ⚠️ **NO se usa el tipo de documento** como criterio, pese a que Mercado Libre lo recomienda
> ("CUIT → A, DNI → B"). Esa regla daría Factura A a un Monotributista, que es un error fiscal. Ver
> FR-040b y research R8. Sólo se usa como aproximación cuando falta la condición de IVA (FR-040c).

Se persiste además la condición y el documento en el Cliente (FR-041), sin pisar lo ya cargado a mano
(FR-041a). Alineado con el principio III de la constitución.

### Cobranza automática (FR-044/FR-045)

Se delega en `Cobranzas::registrarCobro()` —ya existente— por el total de la Venta, contra la cuenta de
Tesorería de Mercado Pago, con fecha `fecha_cerrada`. Eso genera el movimiento de tesorería por el
camino ya probado, sin duplicar lógica.

---

## 9. Resolución del Cliente (FR-036/FR-037/FR-038)

```
1. Buscar por clientes.ml_user_id = comprador_ml_id        ← llave estable, siempre presente
   Encontrado → usarlo

2. Si no, buscar por clientes.apodo_ml = comprador_apodo   ← puente con clientes cargados a mano
   Exactamente 1 → usarlo Y guardarle ml_user_id (FR-036a), para que la próxima resuelva por (1)
   2 o más       → NO elegir: requiere_atencion / cliente_ambiguo
   0             → crear Cliente con ml_user_id, apodo_ml, nombre, doc y condición IVA
```

**Columna nueva en `clientes`**: `ml_user_id` (string, nullable, **unique**) — identificador del
comprador en Mercado Libre.

El orden importa: el apodo **puede no venir** en la respuesta (research §R2), así que no puede ser la
única llave. El caso ambiguo nunca se resuelve arbitrariamente: es el mismo criterio de "no inventar
datos" aplicado a la vinculación de publicaciones.
