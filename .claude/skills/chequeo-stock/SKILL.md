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

## Cómo se interpreta lo que aparece

**Stock negativo**: hay ~60 filas, y **un tercio son mano de obra cargada como producto**
(Colocación, Visita, Traslado, "Materiales extra"). No llevan inventario; su negativo no significa nada.

**Ventas sin `deposito_id`**: 188, y **no son un bug**. 105 son migradas —tienen `legacy_id` y la
migración no mueve inventario a propósito— y 83 son de Mercado Libre anteriores al fix `20cab20`.

**Órdenes de ML sin venta**: las canceladas no se convierten (spec 066). Es lo esperado.

**Publicación desfasada CON `[BLOQUEADA]`**: llegó al tope de reintentos. Ver el `stock_error`.

**Publicación desfasada SIN `[BLOQUEADA]`**: es una **congelada** — el caso peligroso, porque no da
error. Se destraba marcándola pendiente (ver correcciones).

**Ediciones de Ventas migradas**: `StockDeVenta::reaplicarPorEdicion()` **no** tiene el guard de
`legacy_id` que sí tiene `VentaObserver::deleting()`, así que editar una Venta migrada mueve stock
aunque no debería. Se reconoce por `usuario_id` NULL y `legacy_id` presente. **Bug abierto.**

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

**Publicaciones Full**: el stock vive en dos ubicaciones bajo un "user product" — `selling_address`
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
| 19/08/2026 | 508 | Fix de publicaciones Full desplegado (`15df08b`). Las 3 Full alineadas: 12700 Local 2, 43005 Local 0, 41363 Local 30. |
| 17/08/2026 | 401 | 5 ventas, 0 problemas. Primera venta Full descontando de Full. |
| 16/08/2026 | 394 | 2 ventas, 0 problemas. |
| 15/08/2026 | 388 | 13 ventas, 0 problemas. |
| 14/08/2026 | 358 | Stock alineado contra Contagram; bug de depósito en Notas de Crédito corregido. |

## Pendientes conocidos

- **Ediciones de Ventas migradas mueven stock** — falta el guard de `legacy_id` en `reaplicarPorEdicion()`.
- **Boquillas cruzadas**: `24107` y `24759` difieren en 1 unidad contra Contagram por una edición de la
  venta 24100 que no llegó al otro sistema. Falta definir cuál se vendió.
- **`AjustarStockDesdeHoja` sin defensa contra el signo invertido** — incidente del producto 43491.
- **Filtros por fecha sobre columnas `DATETIME`** corren el día (§7.x).
