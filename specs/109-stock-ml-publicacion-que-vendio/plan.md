# Plan técnico — spec 109

## Enfoque

Se **quita** la exclusión de la publicación que vendió. Es borrar código, no agregarlo.

La razón por la que esto es seguro está verificada leyendo el código, no supuesta:
`SincronizadorStock::procesarVinculos()` calcula la cantidad **dentro del loop**, al momento del
envío. Marcar de más nunca publica un número viejo.

## El cambio

`app/Observers/MovimientoStockObserver.php`:

```php
// HOY
$yaDescontadas = $this->publicacionesDeLaOrdenMl($movimiento);

MercadoLibrePublicacionProducto::where('producto_id', $movimiento->producto_id)
    ->when($yaDescontadas !== [], fn ($q) => $q->whereNotIn('ml_item_id', $yaDescontadas))
    ->update(['stock_pendiente' => true]);

// QUEDA
MercadoLibrePublicacionProducto::where('producto_id', $movimiento->producto_id)
    ->update(['stock_pendiente' => true]);
```

Idéntico en `ramaTiendanube()`.

Quedan sin uso `publicacionesDeLaOrdenMl()`, `variantesDeLaOrdenTn()` y `ventaDeOrigen()`, más los
imports de `MercadoLibreOrden`, `TiendanubeOrden` y `Venta`. Se borran: código muerto que documenta
una decisión revertida confunde más de lo que explica. El **por qué** va en el comentario del método
que queda.

## Lo que NO cambia, y hay que probarlo

El corte por depósito (`if ($movimiento->deposito_id !== $depositoMl->id) return;`) **se conserva
tal cual**. Es lo que evita que un movimiento en otro depósito toque las publicaciones.

Para ventas manuales, compras y ajustes el comportamiento queda **idéntico**: en esos casos
`publicacionesDeLaOrdenMl()` ya devolvía `[]` y el `when()` no filtraba nada. Ese es el camino de la
mayoría de los movimientos del sistema y no debe moverse un milímetro.

## Los 3 tests que hay que reescribir

Exigen hoy el comportamiento viejo:

1. `test_convertir_orden_de_mercadolibre_no_marca_pendiente_el_vinculo` — pasa a afirmar que **sí**
   marca.
2. `test_orden_de_ml_marca_pendientes_las_otras_publicaciones_del_producto` — su assert sobre la
   publicación vendida se invierte; el de la otra se conserva.
3. `test_venta_manual_sobre_mismo_producto_si_marca_pendiente_tras_una_orden_ml` — el assert
   intermedio ("la orden no debe marcar") se invierte; la parte de la venta manual se conserva.

No se borran. Cada uno lleva en su docblock por qué cambió, con referencia a esta spec.

Revisar además el equivalente de Tiendanube en
`tests/Feature/Integraciones/TiendanubeMovimientoStockObserverTest.php` y
`MovimientoStockObserverTiendanubeTest.php`.

## Orden de trabajo

1. Correr la suite del observer **antes de tocar nada** y anotar el verde de partida.
2. Reescribir los tests de Mercado Libre con la regla nueva → tienen que **fallar**.
3. Quitar la exclusión en `ramaMercadoLibre()` → pasan.
4. Lo mismo para Tiendanube.
5. Borrar los métodos e imports sin uso.
6. Suite completa, comparando contra las fallas preexistentes.
7. Deploy y verificación contra la API real.

## Riesgos

| Riesgo | Mitigación |
|---|---|
| Romper el guardado de ventas/compras | El cambio quita una condición; el camino de venta manual/compra/ajuste queda igual y tiene test propio |
| Más llamadas a la API (429) | El cliente ya tiene backoff; se mide el volumen antes/después |
| Que un PUT redundante pise un dato bueno | Imposible: el valor enviado es el del CRM, leído al enviar |
| Romper Tiendanube al tocar las dos ramas | Test propio por plataforma |

## Verificación en producción

**Antes**: conteo de escrituras de stock del día en `ml_operaciones_log`.

**Después**: `scripts/stock/comparar_mercadolibre.php` sin desfasadas fuera de las `under_review`, y
el conteo de escrituras comparado contra el de antes.
