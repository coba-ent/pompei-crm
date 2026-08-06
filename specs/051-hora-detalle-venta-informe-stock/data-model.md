# Data Model: Hora en Movimientos de Stock y Detalle de Venta en Informe de Stock

## Entidad: `movimientos_stock` (existente, modificada)

| Campo | Tipo actual | Tipo nuevo | Notas |
|---|---|---|---|
| `fecha` | `DATE` | `DATETIME` | Pasa a guardar fecha+hora real del momento del movimiento. Registros existentes conservan su fecha con hora `00:00:00` (comportamiento por defecto de MySQL al ensanchar DATE→DATETIME, ver research.md D2). Sigue siendo el campo usado para el `ORDER BY` y para la partición temporal de la función de ventana del saldo corrido. |

Resto de columnas (`producto_id`, `variante_id`, `deposito_id`, `tipo`, `cantidad`, `descripcion`,
`origen_type`, `origen_id`, `usuario_id`) sin cambios.

**Modelo Eloquent** (`app/Models/MovimientoStock.php`): el cast `'fecha' => 'date'` pasa a
`'fecha' => 'datetime'`.

**Puntos de escritura** (`app/Services/Stock/StockService.php`): los 4 defaults
`$fecha ?: now()->toDateString()` pasan a `$fecha ?: now()` (Carbon completo). El parámetro
`?string $fecha` de las firmas públicas (`ajustar`, `transferir`, `registrarSalida`,
`registrarEntrada`) no cambia. Único caller que sí pasa `$fecha` explícita hoy:
`StockDeCompra::aplicarAlta()`, con `fecha_emision->toDateString()` — se mantiene sin cambios
(sigue guardando hora `00:00:00`, es la excepción documentada en FR-001).

## Vista derivada: fila del Informe de Stock (`InformeStockController::baseQuery()`)

Columna nueva calculada `detalle` (no persistida, sólo en la proyección SQL de lectura):

| Condición | Contenido de `detalle` |
|---|---|
| `mov.origen_type = 'App\Models\Venta'` y la venta tiene cliente | `"{tipo_comprobante} {nro_comprobante} - {cliente.nombre}"` |
| `mov.origen_type = 'App\Models\Venta'` y la venta NO tiene cliente | `"{tipo_comprobante} {nro_comprobante}"` |
| `mov.origen_type` distinto de Venta (o venta no accesible) | igual que hoy: `mov.descripcion` |

**Joins nuevos** (LEFT JOIN, condicionados por `origen_type` para no afectar otros orígenes):

```
LEFT JOIN ventas       ON mov.origen_type = 'App\Models\Venta' AND ventas.id = mov.origen_id
LEFT JOIN clientes     ON clientes.id = ventas.cliente_id
```

Al ser `LEFT JOIN`, una venta eliminada (soft delete) sigue resolviendo el join mientras la fila
exista en `ventas` (soft delete no borra la fila); si por algún motivo no está accesible, `detalle`
cae naturalmente a `mov.descripcion` (`COALESCE`).

## Contrato JSON afectado

Ver [contracts/informe-stock-data.md](contracts/informe-stock-data.md) para el shape completo de
la respuesta de `InformeStockController::data()`.
