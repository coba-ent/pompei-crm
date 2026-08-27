---
name: chequeo-stock
description: Revisión de rutina del stock en producción (VPS) — movimientos, ventas contra sus movimientos, y CRM contra Mercado Libre y contra un export de Contagram. Usar cuando el usuario pide un "informe de rutina", "chequeo de stock", "cómo viene el stock", o cuando reporta un desfasaje con Mercado Libre o con Contagram.
---

# Chequeo de stock

Runbook completo. **No hace falta contexto de conversaciones anteriores**: todo lo aprendido está acá.

**Todo es sólo lectura.** Ningún paso escribe. Las correcciones están al final, cada una con su condición.

## Dónde se corre

Producción es el **VPS**: `root@46.202.146.102`, proyecto en `/var/www/contagram`, base `contagram`
(única base del servidor). Acceso por clave dedicada, sin password:

```bash
ssh -i ~/.ssh/contagram_vps root@46.202.146.102 "cd /var/www/contagram && php scripts/stock/<script>.php"
```

Los scripts están versionados en `scripts/stock/`, así que llegan solos con cada deploy.

## Los cuatro pasos

### 1. Resumen y delta

```bash
php scripts/stock/resumen.php <ultimo_corte>
```

El corte es el `movimientos_stock.id` del chequeo anterior — está en el registro al pie de este
archivo. El script imprime el próximo corte; **actualizalo acá al terminar**.

Qué mirar: movimientos nuevos por origen, **ajustes manuales sin origen** (ésos siempre se
explican), las dos sincronizaciones de Mercado Libre y las publicaciones bloqueadas.

### 2. Ventas contra sus movimientos

```bash
php scripts/stock/auditar_ventas.php "2026-08-19 00:00:00"     # fecha UTC
```

Verifica producto, depósito y cantidad de cada línea, y también el caso inverso: stock movido
sobre productos que no están en la venta. Sin argumento toma las últimas 24 horas.

### 3. CRM contra Mercado Libre

```bash
php scripts/stock/comparar_mercadolibre.php
```

**Es el único chequeo que detecta una publicación congelada.** Si nadie la marcó como pendiente,
el CRM la da por sincronizada y ningún indicador interno la denuncia.

### 4. CRM contra Contagram (sólo si el usuario trae un export)

```bash
php scripts/stock/comparar_contagram.php "/ruta/Listado de Productos y Servicios ... .xlsx"
```

## Las trampas — todas ya pisadas, no volver a caer

**Las fechas se guardan en UTC.** La app muestra en hora argentina (-03). Un export de las 20:18
locales corta en las 23:18 UTC. Filtrar mal por fecha corre el día y muestra diferencias que no existen.

**Contagram renumeró los productos.** Si el export trae columna `ID VIEJOS`, ésa es la clave; el `Id`
apunta a otro producto. Cruzar por `Id` daba **115 diferencias falsas y 7.625 "faltantes"**.

**El depósito a comparar depende de la publicación.** Una publicación Full se compara contra la
ubicación `selling_address`, no contra el depósito general. Compararlas todas contra el general marca
las Full como desfasadas sin estarlo.

**No sumar sólo las salidas.** Las ediciones generan pares entrada + salida que se cancelan: hay que
netear. Un producto figuraba con 41 unidades vendidas cuando eran 7.

**`origen_type` lleva backslashes.** Escribir `origen_type = 'App\Models\Venta'` a través de
`ssh ... mysql -e "..."` **nunca matchea** por el escapado. Usar `LIKE '%Venta'` — los scripts ya lo hacen.

**Un export es una foto.** Toda venta posterior a su hora aparece como diferencia y no lo es.

**Auditar cada venta por separado no alcanza.** Un ajuste posterior puede anular la salida de una
venta, y como pertenece a otro origen no aparece al auditar esa venta: el primer pase le pone OK.
Por eso `auditar_ventas.php` tiene un **segundo pase** que compara el neto por producto en toda la
ventana. Fue lo que dejó pasar la venta 24587 del 19/08 durante un día entero.

## Cómo se interpreta lo que aparece

**Stock negativo**: hay ~60 filas, y **un tercio son mano de obra cargada como producto**
(Colocación, Visita, Traslado, "Materiales extra"). No llevan inventario; su negativo no significa nada.

**Ventas sin `deposito_id`**: 188, y **no son un bug**. 105 son migradas —tienen `legacy_id` y la
migración no mueve inventario a propósito— y 83 son de Mercado Libre anteriores al fix `20cab20`.

**Órdenes de ML sin venta**: las canceladas no se convierten (spec 066). Es lo esperado.

**Publicación desfasada CON `[BLOQUEADA]`**: llegó al tope de reintentos. Ver el `stock_error`. Si dice
`under_review`, la frenó la moderación de Mercado Libre y no hay nada que hacer del lado del CRM.

**Publicación desfasada SIN `[BLOQUEADA]`**: es una **congelada** — el caso peligroso, porque no da
error. Se destraba marcándola pendiente (ver correcciones).

**Una salida seguida de un ajuste que la devuelve** (mismo producto y depósito, minutos después) es
la firma de los tres bugs encontrados hasta ahora. Ojo: una **entrada + salida en el mismo segundo y
mismo origen** es otra cosa —una edición de venta— y es normal. Neto cero = la mercadería salió y no se descontó de
ningún lado. Nunca da error ni deja la publicación pendiente: hay que buscarlo a propósito.

**Ediciones de Ventas migradas — mueven stock, y está BIEN.** La migración no generó movimientos
porque el stock contado ya reflejaba todo el histórico. Pero una edición hecha hoy sí tiene que mover:
si la venta tenía 1 unidad y se edita a 3, dos unidades más están saliendo ahora. El sistema revierte
lo viejo (entrada) y aplica lo nuevo (salida), y el neto es exactamente **el delta de la edición** —
cero si los ítems no cambiaron.

⚠️ **Al auditar una Venta migrada editada, la vara es el delta, no el total.** Comparar "lo que movió"
contra "los ítems que tiene hoy" da falsos positivos: una migrada nunca descontó su total original.
Ese error de método produjo dos alarmas falsas (14 y 16/08) que quedaron documentadas como bug y no
lo eran. Verificado el 20/08 sobre las 13 ediciones del período: las 13 correctas.

*Distinto es el momento de importar histórico*: ahí la importación **no debe** generar movimientos,
porque el stock contado ya los contempla. Es la decisión que se tomó en la migración y sigue vigente
para cualquier carga masiva de comprobantes viejos.

## Correcciones

### Corregir un conteo real

**NUNCA con `UPDATE` directo.** Saltea `MovimientoStockObserver`, que es el único punto que marca las
publicaciones como pendientes de sincronizar: el dato queda bien en el CRM y **congelado en Mercado
Libre, sin error ni marca**. Incidente real: una publicación estuvo tres días ofreciendo 0 con 3
unidades en depósito.

Usar `StockService::ajustar()`, el mismo mecanismo que un ajuste desde la pantalla — deja movimiento
trazable y dispara la cadena completa: movimiento → observer → publicación pendiente → cron → ML.

```php
app(\App\Services\Stock\StockService::class)->ajustar(
    producto: \App\Models\Producto::find($id),
    variante: null,
    deposito: \App\Models\Deposito::where('nombre', 'Local')->firstOrFail(),
    cantidadConSigno: $objetivo - $actual,
    descripcion: 'Conteo real informado por el cliente (fecha)',
);
```

Si por el motivo que sea hay que escribir directo, marcar a mano después:

```sql
UPDATE ml_publicacion_producto SET stock_pendiente = 1 WHERE producto_id = ...;
```

### Destrabar una publicación congelada

```sql
UPDATE ml_publicacion_producto SET stock_pendiente = 1 WHERE ml_item_id = '...';
```

El cron la empuja en la próxima corrida. **Tarda hasta 5 minutos**: el comando corre cada minuto pero
respeta `frecuencia_sync_minutos`. Para no esperar: `php artisan mercadolibre:sincronizar-stock --forzar`.

Una publicación pausada por `out_of_stock` **se reactiva sola** al recibir stock — es un sub-estado
automático, no una pausa manual. Verificado en producción.

### Reactivar una que quedó bloqueada

```sql
UPDATE ml_publicacion_producto
   SET stock_requiere_intervencion = 0, stock_error = NULL, stock_intentos_fallidos = 0,
       stock_pendiente = 1
 WHERE ml_item_id = '...';
```

Sólo después de entender y resolver la causa del `stock_error`.

## Contexto técnico que suele hacer falta

**Depósitos**: `Local` (general de Mercado Libre) y `Full`. El de Tiendanube ya no existe.

**Publicaciones Full — dos cosas distintas que se confunden fácil:**

*Que la publicación sea Full no significa que la venta salga de Full.* Cuando el depósito de Mercado
Libre se queda vacío, la publicación **sigue vendiendo** y el paquete sale del domicilio del vendedor
(`self_service` = Flex, `xd_drop_off` = Colecta). Esa venta descuenta de **Local**, no de Full. El dato
que lo decide es el `logistic_type` del **envío**, no el de la publicación:

```
GET /shipments/{id}      → logistic_type: fulfillment | self_service | xd_drop_off
```

El id del envío viene en `ml_ordenes.payload->shipping.id`. Desde `2696f0a` el conversor lo consulta
antes de imputar el depósito. **Confirmado contra Contagram** (informe de movimientos del 20/08): los
mismos tres productos tienen ventas imputadas a Full el 18/08 y a Local el 19/08, cuando el depósito
Full se vació. Contagram decide venta por venta, igual que nosotros ahora.

*El depósito Full del CRM es un espejo, no un depósito propio.* `SincronizadorStockFull` lo iguala al
inventario real de Mercado Libre cada corrida. **Cualquier valor que le escribas —a mano, por Excel o
por el módulo de importación— lo pisa en menos de 5 minutos.** Verificado: un ajuste de −2 duró 11
segundos. Si necesitás que Full tenga unidades, tiene que recibirlas Mercado Libre físicamente.

*El stock propio sí se escribe.* Vive en dos ubicaciones bajo un "user product" — `selling_address`
(del vendedor, **escribible**) y `meli_facility` (de ML, **no escribible**). El `available_quantity`
del ítem es derivado, y por eso `PUT /items/{id}` responde `item.available_quantity.not_modifiable`.
El recurso correcto es:

```
PUT /user-products/{user_product_id}/stock/type/selling_address
    header  x-version: {la del GET de /stock}      <- sin esto, 400; con una vieja, 409
    body    {"quantity": N}
```

Detalle completo en `docs/documentacion_principal_crm.md` §3.2.ter.ter.

**Cadencias**: órdenes y stock cada 5 minutos (`frecuencia_sync_minutos`); tipos de publicación y
logística, **cada 24 horas** — si hace falta antes, `mercadolibre:sincronizar-tipos-publicacion --forzar`.

## Documentación relacionada

- `docs/documentacion_principal_crm.md` §3.2.ter a §3.2.ter.quater — Mercado Libre y stock
- `docs/documentacion_principal_crm.md` §7.x — filtros por fecha sobre columnas `DATETIME` en UTC
- `docs/importacion_casos_a_revisar.md` §9 y §10 — auditorías hechas y qué quedó descartado

## Registro de chequeos

Actualizar al terminar cada uno. El corte es `movimientos_stock.id`.

| Fecha | Corte | Resultado |
|---|---|---|
| 27/08/2026 | 1024 | 100 líneas, 0 problemas. 268/270 alineadas con ML y **267/270 en precio**. Las mismas 3 `under_review` en las dos cosas: es moderación de ML, no del CRM. Los 10 ajustes manuales sin descripción de Pompei1 son **armado de kits** (−5/−6 de la grifería 24613 y del mixer 36317 contra +5/+6 del Kit Arizona 43005 y del combo 12700): correcto, sólo sin documentar. Los netos que no cierran en 27198 y 43005 son eso mismo más las ediciones de la venta 22416 (el trabajo abierto de JPD). |
| 24/08/2026 | 792 | 28 ventas, 35 líneas, 0 problemas; el neto por producto cierra en los 31 del período. 268/270 alineadas con ML. Movida fuerte de compras: +147 unidades en 27 entradas. |
| 21/08/2026 | 597 | 25 ventas, 36 líneas, 0 problemas. 268/270 alineadas con ML. La venta 24594 se anuló entera con la NC 854 y repuso en Local, el depósito correcto: el fix `3e3bc49` funcionando con un caso real. Panel `/monitoreo` en producción. |
| 20/08/2026 (10:13) | 542 | 1 venta, 0 problemas. 268/270 alineadas con ML. Sin ventas Full todavía: el fix `2696f0a` sigue sin probarse con una venta real. |
| 20/08/2026 | 539 | Fix: la venta de una publicación Full se imputa según el envío, no según la publicación (`2696f0a`). Validado contra el informe de movimientos de Contagram. 5 de 6 diferencias alineadas; queda 12700 en Full, que el reflejo no deja fijar. |
| 19/08/2026 (14:31) | 516 | 12 ventas, 13 líneas, 0 problemas. 268/270 alineadas con ML. Fix de publicaciones Full desplegado (`15df08b`). Las 3 Full alineadas: 12700 Local 2, 43005 Local 0, 41363 Local 30. Quedan 3 bloqueadas por moderación de ML (`under_review`), ajenas al CRM: MLA1953964180, MLA2053709352, MLA1489377153. |
| 17/08/2026 | 401 | 5 ventas, 0 problemas. Primera venta Full descontando de Full. |
| 16/08/2026 | 394 | 2 ventas, 0 problemas. |
| 15/08/2026 | 388 | 13 ventas, 0 problemas. |
| 14/08/2026 | 358 | Stock alineado contra Contagram; bug de depósito en Notas de Crédito corregido. |

## Por qué estos chequeos son manuales (y qué falta)

Los tres bugs encontrados —depósito equivocado en Notas de Crédito, publicaciones Full congeladas, y
venta Full despachada desde el domicilio— **no daban ningún error**. Stock que no baja, publicación
que no se actualiza: para el sistema todo salió bien. Los tres aparecieron comparando contra una
fuente externa (un export de Contagram o la API de ML), nunca por una alarma.

Pendiente de construir: un detector que corra cada 15 minutos y verifique tres invariantes sobre lo
que se movió — que todo lo vendido se haya descontado, que ningún movimiento se anule a sí mismo, y
que Mercado Libre ofrezca lo que dice el CRM— avisando cuando alguno falle.

## Pendientes conocidos

- **Boquillas cruzadas**: `24107` y `24759` difieren en 1 unidad contra Contagram porque la venta 24100
  se corrigió acá (cambió una boquilla por la otra) y esa corrección no está en Contagram. **El CRM está
  bien**; el que quedó atrasado es el otro sistema.
- **`AjustarStockDesdeHoja` sin defensa contra el signo invertido** — incidente del producto 43491.
- **Filtros por fecha sobre columnas `DATETIME`** corren el día (§7.x).
- **El módulo de importación ofrece mapear "Stock: Full"** y ese valor lo revierte el reflejo a los
  pocos minutos, sin avisar. Habría que sacar los depósitos Full de esa lista de campos.
- **No hay ningún canal de alertas**: `MAIL_MAILER=log` en el VPS y no existe ninguna clase de
  notificación. Todo problema de stock se descubre mirando a propósito. Pendiente: detector de
  invariantes con aviso (ver abajo).
