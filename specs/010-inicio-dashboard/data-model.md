# Data Model: Módulo Inicio (Dashboard)

Este módulo **no agrega tablas ni migraciones**. Es una capa de lectura/agregación sobre entidades ya
persistidas en specs anteriores. La única pieza nueva es un servicio de dominio (sin tabla propia) que
deriva un cálculo — documentado abajo como "entidad calculada".

## Entidades existentes reutilizadas (sin cambios de esquema)

| Entidad | Spec de origen | Campos usados por el Dashboard |
|---|---|---|
| `Venta` | 008 | `total`, `fecha_emision`, `fecha_vto_cobro`, `categoria_id`, `cliente_id`, `deleted_at` (excluir soft-deleted), métodos `cobrado()`, `aCobrar()` |
| `Presupuesto` | 008 | no participa directamente en KPIs (sólo Ventas confirmadas cuentan como "Ventas Creadas") |
| `OtroIngreso` | 008 | `monto`, `fecha`, `categoria_id`, `pendiente` (excluir si pendiente del total cobrado, según Assumptions de spec 008) |
| `VentaItem` | 008 | `producto_id`, `cantidad`, `subtotal_con_iva` — para Ranking de Productos |
| `Compra` | 009 | `total`, `fecha_emision`, `fecha_vto_pago`, `categoria_id`, `proveedor_id`, `deleted_at`, métodos `pagado()`, `aPagar()` |
| `Gasto` | 009 | `monto`, `fecha`, `categoria_id`, `pendiente` |
| `Categoria` | 001/008/009 | `tipo` (`venta`/`compra`/`gasto`), `nombre`, `deleted_at` (soft-deleted → "Sin categoría") |
| `Cliente` | 001 | `id`, `nombre` — para Ranking de Clientes y agrupación de aging |
| `Proveedor` | 003 | `id`, `nombre`/`razon_social` — para agrupación de aging |
| `CuentaTesoreria` | 007 | vía `Tesoreria::saldos()` — Total Disponible/Cajas/Bancos |
| `MovimientoTesoreria` | 007 | `fecha`, `cuenta_tesoreria_id`, `monto` — mini-tabla de movimientos recientes |

## Entidad calculada: Aging de Cuenta Corriente

No persiste — se recalcula en cada request desde `Services/Tesoreria/CuentaCorriente::aging()`.

**Input**: `tipo` (`cliente` | `proveedor`), fecha de corte (por defecto: hoy).

**Cálculo**:
1. Para `cliente`: todas las `Venta` no soft-deleted con `aCobrar() > 0.005`, agrupadas por
   `cliente_id`.
2. Para `proveedor`: todas las `Compra` no soft-deleted con `aPagar() > 0.005`, agrupadas por
   `proveedor_id`.
3. Cada documento con saldo pendiente se clasifica en un único bucket según
   `fecha_vto_cobro`/`fecha_vto_pago` vs. la fecha de corte:

   | Bucket | Condición |
   |---|---|
   | `a_vencer` | vencimiento ≥ fecha de corte (o sin vencimiento cargado) |
   | `vencido` | vencimiento < fecha de corte (total agregado de los siguientes 4 buckets) |
   | `0_30` | días de vencido entre 1 y 30 |
   | `31_60` | días de vencido entre 31 y 60 |
   | `61_90` | días de vencido entre 61 y 90 |
   | `mas_90` | días de vencido > 90 |

**Output**:

```text
{
  total: float,                 // suma de todos los buckets
  buckets: {
    a_vencer: float, vencido: float,
    "0_30": float, "31_60": float, "61_90": float, mas_90: float
  }
}
```

**Invariante** (cubierta por test): `total` == suma de `aCobrar()`/`aPagar()` de todos los documentos
con saldo pendiente incluidos en el cálculo. Un documento con saldo exactamente en cero (dentro de
tolerancia de redondeo) no aporta a ningún bucket.

## Entidad calculada: KPIs y variación por período

No persiste. `DashboardController` calcula, para un rango de fechas `[desde, hasta]` (según el período
elegido) y su rango anterior equivalente (mismo largo, inmediatamente previo):

- **Ventas Creadas** = `SUM(Venta.total)` en el rango (excluye soft-deleted).
- **Venta Promedio** = Ventas Creadas / `COUNT(Venta)` en el rango (si `COUNT = 0`, el KPI se muestra
  en cero, sin variación calculada — ver regla de "sin datos previos" en spec US1-AC2).
- **Cantidad de Ventas** = `COUNT(Venta)` en el rango.
- **Resultado** = Ventas Creadas + `SUM(OtroIngreso.monto WHERE NOT pendiente)` −
  `SUM(Compra.total)` − `SUM(Gasto.monto WHERE NOT pendiente)`, todo en el rango.
- **Variación %** de cada KPI = `(valor_actual - valor_anterior) / valor_anterior * 100`, con regla
  explícita: si `valor_anterior == 0`, la variación se reporta como `null` (el front la muestra como
  "sin datos previos", nunca `NaN`/`Infinity` — FR cubierto por US1-AC2).

## Entidad calculada: Serie mensual (12 meses)

Para cada uno de los últimos 12 meses (incluyendo el actual), se agregan por separado: `SUM(Venta.total)`,
`SUM(OtroIngreso.monto)`, `SUM(Compra.total)`, `SUM(Gasto.monto)` agrupados por `YEAR-MM` de
`fecha_emision`/`fecha`. Meses sin registros devuelven `0` explícito (no se omiten del arreglo — FR-003).

## Entidad calculada: Composición por categoría (donas) y Rankings

- **Donas**: `SUM(monto/total) GROUP BY categoria_id` dentro del período filtrado, para Ventas, Compras
  y Gastos por separado. Categoría `null` o soft-deleted se agrupa bajo la etiqueta fija
  `"Sin categoría"`.
- **Ranking de Clientes**: `SUM(Venta.total) GROUP BY cliente_id ORDER BY SUM DESC` dentro del período,
  limitado a un top N (recomendado 10).
- **Ranking de Productos**: `SUM(VentaItem.cantidad) GROUP BY producto_id ORDER BY SUM DESC` (join con
  `ventas` para filtrar por período y excluir ventas soft-deleted), top N (recomendado 10).
