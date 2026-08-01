# Data Model: Compras suman stock

Sin migraciones nuevas. Todas las entidades relevantes ya existen; esta feature sólo agrega
comportamiento (movimientos) sobre ellas.

## Entidades reutilizadas (sin cambio de esquema)

| Entidad | Rol en esta feature |
|---|---|
| `Compra` | Origen polimórfico (`origen_type`/`origen_id`) de los `MovimientoStock` que genera. Se lee `fecha_emision` para fechar el movimiento (R3). No se le agrega ningún campo. |
| `CompraItem` | Fuente de `producto_id` y `cantidad` para cada movimiento. Sólo los ítems cuyo `producto->controlaStock()` generan movimiento (FR-001/FR-002). Variante siempre `null` (R5). |
| `Stock` | Foto de cantidad actual por `producto_id`+`variante_id`+`deposito_id`; se actualiza vía `StockService` (sin cambios de estructura). |
| `MovimientoStock` | Histórico; se generan nuevas filas `tipo=entrada`/`salida` con `origen_type=Compra::class`, `origen_id=$compra->id`, `fecha=$compra->fecha_emision`. Sin cambios de estructura. |
| `Deposito` | Se usa `Deposito::porDefecto()` como destino (R2), ya existente. |

## Flujo de datos por operación

### Alta (`CompraController::store`)

```
Compra::create(...)
  → guardarItems($compra, ...)   // ya existente
  → StockDeCompra::aplicarAlta($compra)   // NUEVO
      por cada CompraItem con producto->controlaStock():
        StockService::registrarEntrada(producto, null, depositoPorDefecto, cantidad, origen=$compra, fecha=$compra->fecha_emision)
          → Stock.cantidad += cantidad   (lock atómico)
          → MovimientoStock::create(tipo=entrada, cantidad=+cantidad, origen=Compra, fecha=fecha_emision)
```

### Edición (`CompraController::update`)

```
$itemsAnteriores = $compra->items;   // capturado ANTES de borrar (NUEVO, mismo patrón que VentaController)
$compra->update(...)
$compra->items()->delete();
guardarItems($compra, ...nuevos...)
  → StockDeCompra::reaplicarPorEdicion($compra, $itemsAnteriores)   // NUEVO
      por cada item de $itemsAnteriores con producto->controlaStock():
        StockService::registrarSalida(...)   // reintegra (resta) lo que había sumado la versión vieja
      luego: aplicarAlta($compra)   // suma la versión nueva ya persistida
```

### Baja (`CompraObserver::deleting`, NO en el controlador)

`CompraController::destroy` sólo llama `$compra->delete()` (sin cambios). El reintegro de stock se
dispara desde `CompraObserver::deleting`, que ya existe y ya revierte los pagos de la Compra en el mismo
evento — se le agrega la misma responsabilidad para stock, exactamente como `VentaObserver::deleting` ya
hace para Ventas:

```
CompraObserver::deleting($compra)
  if (! $compra->isForceDeleting()):
    DB::transaction:
      revertir pagos (ya existente)
      StockDeCompra::reintegrarPorEliminacion($compra->load('items.producto'))   // NUEVO
        por cada CompraItem con producto->controlaStock():
          StockService::registrarSalida(producto, null, depositoPorDefecto, cantidad, origen=$compra, fecha=hoy)
```

Nota: en la baja, la fecha del movimiento de reintegro es la fecha del día en que se elimina (no la
`fecha_emision`), porque es cuando efectivamente se está revirtiendo la existencia física del stock —
igual criterio que usa `StockDeVenta::reintegrarPorEliminacion` (no recibe `$fecha`, usa el default).

## Validaciones existentes reutilizadas (no se duplican)

- Cantidad de `CompraItem` > 0: ya validado por `StoreCompraRequest`/`UpdateCompraRequest`.
- Existencia de al menos un depósito activo: mismo `RuntimeException` que ya lanza
  `StockDeVenta::depositoPorDefecto()` si `Deposito::porDefecto()` devuelve `null`.
