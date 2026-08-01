# Data Model: Cuenta Corriente Clientes

No se agregan entidades, tablas ni columnas nuevas. Se reutilizan tal cual las entidades ya existentes.

## Venta (reutilizada, sin cambios de esquema)

Campos relevantes ya existentes: `id`, `cliente_id`, `categoria_id`, `fecha_emision`, `fecha_vto_cobro`, `nro_comprobante`, `total`. Métodos ya existentes reutilizados: `cobrado()`, `aCobrar()`, `totalNotasCredito()`/`totalNotasDebito()`.

## Cobro (reutilizada, sin cambios de esquema)

Campos relevantes: `id`, `venta_id` (→ `cliente_id` vía `venta.cliente_id`), `fecha`, `cuenta_tesoreria_id` (→ "Medio de Cobro", vía `cuentaTesoreria.nombre`), `monto`.

## NotaCreditoDebito (reutilizada, sin cambios de esquema)

Campos relevantes: `id`, `venta_id` (→ `cliente_id` vía `venta.cliente_id`), `tipo` (`credito`/`debito`), `fecha_emision`, `monto`, `descripcion`.

## Cliente (reutilizada, sin cambios de esquema)

Agrupador de "Saldos Clientes"; campos relevantes: `id`, `nombre`.

## Vista derivada: fila de "Saldos Clientes" (no persistida)

Salida de `CuentaCorriente::porCliente('cliente')`, una entrada por cliente con saldo pendiente ≠ 0:

| Campo | Origen |
|---|---|
| `cliente_id`, `cliente_nombre` | `Cliente` |
| `a_vencer` | Σ `aCobrar()` de Ventas del cliente sin vencer |
| `vencido_0_30` / `_31_60` / `_61_90` / `_mas_90` | Σ `aCobrar()` de Ventas vencidas, bucketed por días desde `fecha_vto_cobro` |
| `total` | Σ de todos los buckets anteriores |

## Vista derivada: fila de "Movimientos" (no persistida)

Salida de la UNION (research.md R2), una fila por Venta / Cobro / Nota de Crédito-Débito:

| Campo | Origen (Venta) | Origen (Cobro) | Origen (Nota) |
|---|---|---|---|
| `id` | `venta.id` | `cobro.id` | `nota.id` |
| `fecha_emision` | `venta.fecha_emision` | `cobro.fecha` | `nota.fecha_emision` |
| `cliente_id` | `venta.cliente_id` | `cobro.venta.cliente_id` | `nota.venta.cliente_id` |
| `operacion` | `'venta'` | `'cobro'` | `'nota_credito'` / `'nota_debito'` (según `nota.tipo`) |
| `categoria` | `venta.categoria.nombre` | `null` | `null` |
| `total_venta` | `venta.total` | `null` | `null` |
| `cobrado` | `venta.cobrado()` (a la fecha) | `null` | `null` |
| `a_cobrar` | `venta.aCobrar()` | `null` | `null` |
| `nro_comprobante` | `venta.nro_comprobante` | `null` | `null` |
| `medio_cobro` | `null` | `cobro.cuentaTesoreria.nombre` | `null` |
| `descripcion` | `null` | `cobro.nota` | `nota.descripcion` |
