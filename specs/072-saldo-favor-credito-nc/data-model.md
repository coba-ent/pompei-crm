# Data Model: Saldo a favor aplicable a nuevas Ventas y Compras

**Fecha**: 2026-08-21 · **Spec**: [spec.md](./spec.md) · **Research**: [research.md](./research.md)

## Tabla nueva: `aplicaciones_credito`

Registra que el saldo a favor de un comprobante se imputó a otro comprobante del mismo
cliente/proveedor. **No es dinero**: no tiene cuenta de tesorería y no genera `movimientos_tesoreria`.

| Campo | Tipo | Notas |
|---|---|---|
| `id` | bigint unsigned PK | |
| `origen_type` | string | Morph: `App\Models\Venta` o `App\Models\Compra` |
| `origen_id` | bigint unsigned | Comprobante que cede el crédito (el que tiene la NC) |
| `destino_type` | string | Morph: mismo tipo que el origen |
| `destino_id` | bigint unsigned | Comprobante que recibe el crédito |
| `nota_credito_debito_id` | bigint unsigned FK, nullable | NC del origen que justifica el crédito. Cuando el origen tiene varias NC se guarda la **más antigua con remanente**; queda nulo sólo si el crédito no puede atribuirse a ninguna en particular. Si un mismo importe se cubre con varios orígenes, se crea **una fila por origen** (contrato §2 devuelve un array) |
| `monto` | decimal(14,2) | Siempre > 0 |
| `fecha` | date | Elegida por el operador, hoy por defecto |
| `nota` | text nullable | Observación libre |
| `usuario_id` | bigint unsigned FK nullable | Quién la aplicó (auditoría) |
| `created_at` / `updated_at` | timestamps | |
| `deleted_at` | timestamp nullable | **Soft delete** (constitución, principio III) |

**Índices**: `(origen_type, origen_id)`, `(destino_type, destino_id)`, `nota_credito_debito_id`.

**Reglas de integridad**:

1. `origen_type = destino_type` (no se cruza una Venta con una Compra).
2. `origen_id ≠ destino_id` (FR-009a).
3. El cliente del origen debe ser el mismo que el del destino; ídem proveedor (FR-006).
4. El origen debe tener al menos una NC vigente (Decisión 2 de research).
5. `monto ≤ crédito disponible del origen` y `monto ≤ saldo pendiente del destino` (FR-007).

**Por qué polimórfica y no dos tablas**: Ventas y Compras comparten exactamente la misma semántica y
ya comparten `notas_credito_debito` (que resuelve el caso con `venta_id`/`compra_id` nullables).
Una sola tabla evita duplicar la lógica de validación y de cálculo. Se elige morph en vez de dos
pares de columnas nullables porque acá **ambos extremos** varían, y cuatro columnas nullables con
reglas cruzadas es más frágil que un morph con un check de tipo.

## Entidades existentes afectadas

### `Venta` / `Compra` (sin cambios de esquema)

Se extienden los métodos derivados. **No se agregan columnas**: los saldos siguen sin almacenarse.

| Método | Antes | Después |
|---|---|---|
| `aCobrar()` / `aPagar()` | `total + ND − NC − cobrado` | `total + ND − NC − cobrado − creditoRecibido + creditoCedido` |
| `creditoRecibido()` | — | Σ `aplicaciones_credito.monto` donde el comprobante es **destino** |
| `creditoCedido()` | — | Σ `aplicaciones_credito.monto` donde el comprobante es **origen** |
| `creditoDisponible()` | — | `max(0, −(total + ND − NC − cobrado)) − creditoCedido()`, o `0` si no tiene NC vigente |

**Propagación automática**: como Cuenta Corriente, aging, KPIs y filtros de estado ya se apoyan en
`aCobrar()`/`aPagar()` (o en su equivalente SQL), corregir la fórmula alcanza para que todos queden
consistentes. Los lugares que la replican en SQL deben actualizarse en el mismo cambio:

- `VentaController::sqlACobrar()` (filtros del listado de Ventas)
- `VentaController::kpis()` (JOINs de KPIs)
- `CompraController` (filtro `estado_pago` y KPIs)
- `Tesoreria\CuentaCorriente` (`porCliente()`, `aging()`, `queryMovimientos()`)
- `Informes\VentasInformeQuery` / `ComprasInformeQuery`

### `NotaCreditoDebito` (sin cambios de esquema)

- Nueva relación `aplicaciones()`.
- Nueva regla al eliminar: 422 si tiene aplicaciones vivas (FR-012).

### `Cliente` / `Proveedor` (sin cambios de esquema)

- `saldoCuentaCorriente()` expuesto para el selector (FR-014). Negativo = saldo a favor.

## Lo que NO se toca

- `cobros` y `pagos`: sin columnas nuevas, sin filas nuevas de otra naturaleza.
- `movimientos_tesoreria`: ni una fila nueva por aplicar crédito.
- `cuentas_tesoreria`: no se crea ninguna cuenta "Saldo a favor".
- Datos históricos: no se migra ni se reconstruye nada.

## Estados y transiciones

Una aplicación de crédito tiene dos estados: **vigente** y **anulada** (soft-deleted). Anularla
libera el crédito en el origen de forma automática, porque el disponible es derivado y la fila deja
de sumar en `creditoCedido()`. No hay estados intermedios ni aprobación.
