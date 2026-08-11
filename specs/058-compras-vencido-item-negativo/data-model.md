# Data Model: Estado "Vencido" en Compras + ítems con cantidad negativa

Sin cambios de esquema (ninguna migración) — ambos comportamientos son derivados/de validación sobre
columnas que ya existen.

## `Compra::estadoPago()` (existente — extendido)

Hoy devuelve `'a_pagar' | 'parcial' | 'pagado'`. Pasa a devolver `'a_pagar' | 'parcial' | 'pagado' |
'vencido'`, evaluando en este orden (FR-001, FR-004, FR-005):

1. Si `aPagar() <= 0` → `'pagado'` (igual que hoy, tiene prioridad — una compra pagada nunca es
   "vencida").
2. Si `fecha_vto_pago` está seteada, es anterior a hoy, y `aPagar() > 0.005` → `'vencido'` (nuevo).
3. Si `pagado() > 0` (y no cayó en los casos anteriores) → `'parcial'` (igual que hoy).
4. Si no → `'a_pagar'` (igual que hoy).

Misma condición que ya usa `CompraController@data` (filtro `estado_pago=vencido`, líneas 112-124) y
`CompraController@kpisData` (KPI "Vencido") — no se inventa una regla nueva, se centraliza la ya
existente en el modelo para que el badge de fila la use también.

## `CompraItem` (existente — sin cambios de esquema)

`cantidad` (decimal 14,3) ya admite valores negativos a nivel de columna (no hay `UNSIGNED` en la
migración original) — el único bloqueo es la regla de validación en el FormRequest (ver
`contracts`/plan). `subtotal` y `subtotal_con_iva` (decimal 14,2) también admiten negativos sin
cambios — se calculan en el cliente (`cantidad × precio_unitario`, con el signo de `cantidad`
propagado sin `abs()`) y se persisten tal cual llegan, validados.

## `MovimientoStock` (existente — sin cambios de esquema)

Un ítem de Compra con `cantidad` negativa genera un movimiento con `tipo = 'salida'` (en vez de
`'entrada'`) y `cantidad = abs(item.cantidad)` en valor absoluto dentro del movimiento (mismo patrón
ya usado por `StockService::registrarSalida`, que persiste la cantidad con signo negativo en la
columna `movimientos_stock.cantidad` — ver `StockService::mover()`). No es una tabla ni columna nueva,
sólo un cambio de qué método de `StockService` invoca `StockDeCompra` según el signo del ítem.
