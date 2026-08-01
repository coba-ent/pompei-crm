# Quickstart: validar Compras suman stock

Prerrequisitos: entorno local levantado (XAMPP + `php artisan serve` o equivalente), DB `contagram`
migrada, al menos un `Deposito` marcado como por defecto, un `Proveedor` y un `Producto` con
`controla_stock=true` ya cargados.

## Escenario 1 — Alta suma stock (US1)

1. Anotar el stock actual del producto en el depósito por defecto (Configuración → Depósitos, o
   `php artisan tinker` → `StockService::disponibilidad(...)`).
2. Egresos → Compras → Nueva Compra. Cargar Proveedor, un ítem con ese producto, cantidad 10. Guardar.
3. Verificar: el stock del producto en el depósito por defecto aumentó en 10.
4. Verificar en la tabla `movimientos_stock`: nueva fila `tipo=entrada`, `cantidad=10`,
   `origen_type=App\Models\Compra`, `origen_id=<id de la compra>`, `fecha=<fecha_emision de la compra>`.

## Escenario 2 — Edición reajusta stock (US2)

1. Sobre la Compra del escenario 1, Editar → cambiar la cantidad del ítem de 10 a 6. Guardar.
2. Verificar: el stock neto atribuible a esa Compra (comparado contra el valor previo al escenario 1) es
   +6, no +16 ni +10.

## Escenario 3 — Baja reintegra stock (US3)

1. Eliminar la Compra del escenario 2 (menú de fila → Eliminar).
2. Verificar: el stock del producto vuelve exactamente al valor que tenía antes del escenario 1.

## Escenario 4 — Ítem sin control de stock no genera movimiento

1. Nueva Compra con un ítem de un producto con `controla_stock=false` (o un ítem sin producto asociado,
   si el formulario lo permite). Guardar.
2. Verificar: no se generó ningún `MovimientoStock` para ese ítem.

## Automatización

Estos 4 escenarios se cubren con `tests/Feature/CompraStockTest.php` (ver tasks.md) — correr con
`php artisan test --filter=CompraStockTest`.
