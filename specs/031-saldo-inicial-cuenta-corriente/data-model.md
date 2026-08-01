# Data Model: Saldo Inicial en Cuenta Corriente

No se agregan entidades, tablas ni columnas nuevas. Se reutilizan `Cliente.saldo_inicial` /
`Cliente.saldo_inicial_fecha` y `Proveedor.saldo_inicial` / `Proveedor.saldo_inicial_fecha` ya
existentes (decimal / date nullable, sin uso funcional en el aging hasta esta feature).

## Cliente / Proveedor (reutilizadas, sin cambios de esquema)

Campos relevantes ya existentes: `saldo_inicial` (decimal:2, default 0), `saldo_inicial_fecha` (date,
nullable). Se incorporan por primera vez al cálculo de `CuentaCorriente::aging()`/`porCliente()`.

## Vista derivada: fila de "Saldos Clientes" (spec 029, extendida)

Sin cambios de forma — mismos campos `{cliente_id, cliente_nombre, a_vencer, vencido_0_30,
vencido_31_60, vencido_61_90, vencido_mas_90, total}`. Cambia el **origen** de los montos: cada bucket
ahora es la suma de (a) `Venta::aCobrar()` de las Ventas del cliente que caen en ese bucket, más (b)
`Cliente.saldo_inicial` si ese cliente tiene saldo inicial ≠ 0 y su `saldo_inicial_fecha` cae en ese
mismo bucket (o `saldo_inicial_fecha` es nula → bucket "a_vencer", research.md R5).

## Vista derivada: fila de "Movimientos" (spec 029, extendida)

Se agrega una cuarta variante de fila (además de Venta/Cobro/Nota), una por Cliente con
`saldo_inicial ≠ 0`:

| Campo | Origen (Saldo Inicial) |
|---|---|
| `id` | `cliente.id` |
| `fecha_emision` | `cliente.saldo_inicial_fecha` (puede ser `NULL`) |
| `cliente_id` | `cliente.id` |
| `operacion` | `'saldo_inicial'` (nuevo valor, se suma a `venta`/`cobro`/`nota_credito`/`nota_debito`) |
| `categoria` | `NULL` |
| `total_venta` | `NULL` |
| `cobrado` | `NULL` |
| `a_cobrar` | `cliente.saldo_inicial` (puede ser negativo — saldo a favor, FR-005) |
| `nro_comprobante` | `NULL` |
| `medio_cobro` | `NULL` |
| `descripcion` | `NULL` |

## Invariante extendido (FR-009)

Para cualquier Cliente: `SUM(a_cobrar WHERE operacion IN ('venta', 'saldo_inicial'))` en "Movimientos"
== `total` de ese cliente en "Saldos Clientes". El saldo inicial participa de la suma exactamente
igual que una fila de Venta (mismo signo, sin transformación).
