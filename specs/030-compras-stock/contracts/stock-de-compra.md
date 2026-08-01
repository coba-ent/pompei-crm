# Contratos: sin endpoints HTTP nuevos

Esta feature no agrega ni cambia rutas — reutiliza los endpoints existentes de Compras
(`compras.store`, `compras.update`, `compras.destroy`). El contrato relevante es el del servicio interno
nuevo, análogo al de `App\Services\Ingresos\StockDeVenta`.

## `App\Services\Egresos\StockDeCompra`

### `aplicarAlta(Compra $compra): void`

Recorre `$compra->items` (recién persistidos), filtra los que tienen producto y `producto->controlaStock()`,
y por cada uno llama `StockService::registrarEntrada($item->producto, null, $depositoPorDefecto,
$item->cantidad, $compra, fecha: $compra->fecha_emision)`. Invocado al final de `CompraController::store`,
dentro de la misma transacción DB que crea la Compra y sus ítems.

### `reintegrarPorEliminacion(Compra $compra): void`

Recorre los ítems que mueven stock y por cada uno llama `StockService::registrarSalida(...)` con la misma
cantidad, para revertir lo que había sumado `aplicarAlta`. Invocado desde `CompraObserver::deleting`
(evento `deleting` de Eloquent, dentro de su `if (! $compra->isForceDeleting())`), **no** desde
`CompraController::destroy` — mismo patrón exacto que ya usa `VentaObserver::deleting` +
`StockDeVenta::reintegrarPorEliminacion` para Ventas. `CompraObserver` ya existe (revierte pagos en el
mismo evento); se le agrega esta llamada dentro de la misma transacción.

### `reaplicarPorEdicion(Compra $compra, Collection $itemsAnteriores): void`

Reintegra (resta) el stock de `$itemsAnteriores` (capturados por el controlador ANTES de
`$compra->items()->delete()`) y luego llama `aplicarAlta($compra)` con los ítems nuevos ya persistidos.
Invocado en `CompraController::update`, mismo patrón que `StockDeVenta::reaplicarPorEdicion`.

## Extensión de `App\Services\Stock\StockService`

### `registrarEntrada(...)` / `registrarSalida(...)` — firma extendida

Se agrega un parámetro opcional al final: `?string $fecha = null`. Si es `null`, se preserva el
comportamiento actual (`now()->toDateString()`), por lo que las llamadas existentes de `StockDeVenta` no
cambian de comportamiento. `StockDeCompra` es el único llamador que pasa `$fecha` explícito
(`$compra->fecha_emision->toDateString()`).

## Fuera de contrato (no se agrega)

- Sin nuevas rutas HTTP.
- Sin cambios en `StoreCompraRequest`/`UpdateCompraRequest` (no hay campo de depósito ni de fecha de
  movimiento que el usuario pueda elegir — ver spec.md Assumptions/FR-006).
- Sin cambios en la respuesta JSON de `compras.store`/`compras.update`/`compras.destroy` — el movimiento
  de stock es un efecto secundario interno, no expuesto en el payload.
