# Data Model: Toggle %/monto fijo para el Descuento General

## Columnas nuevas (por tabla)

Aplican a `ventas`, `presupuestos`, `compras` y `notas_credito_debito`.

| Columna | Tipo | Null | Default | Notas |
|---|---|---|---|---|
| `descuento_general_tipo` | `ENUM('porcentaje','monto')` | NOT NULL | `'porcentaje'` | Preserva el comportamiento de filas existentes sin backfill. |
| `descuento_general_monto` | `DECIMAL(12,2)` | NULLABLE | `NULL` | Sólo tiene valor cuando `descuento_general_tipo = 'monto'`. |
| `descuento_general_pct` | `DECIMAL(5,2)` | NULLABLE | `NULL` | Ya existe en `ventas`/`presupuestos`/`compras`; **se agrega** en `notas_credito_debito` (no existía). Sólo tiene valor cuando `descuento_general_tipo = 'porcentaje'`. |

**Invariante de aplicación** (validada en los FormRequest, no a nivel de constraint SQL): exactamente
uno de `descuento_general_pct` / `descuento_general_monto` tiene valor no nulo, según
`descuento_general_tipo` — el otro queda `NULL`. Ambos pueden ser `NULL` simultáneamente (sin
descuento general cargado), igual que hoy es válido que `descuento_general_pct` sea `NULL`.

## Entidades afectadas

### Venta / Presupuesto / Compra

- `$fillable` de cada modelo (`Venta`, `Presupuesto`, `Compra`) agrega `descuento_general_tipo`,
  `descuento_general_monto`.
- `$casts` agrega `'descuento_general_monto' => 'decimal:2'`.
- Sin cambios de relaciones.

### NotaCreditoDebito

- `$fillable` agrega `descuento_general_tipo`, `descuento_general_pct`, `descuento_general_monto` (los
  tres son campos nuevos para este modelo).
- `$casts` agrega `'descuento_general_pct' => 'decimal:2'`, `'descuento_general_monto' => 'decimal:2'`.
- El campo `monto` (total final, ya existente) no cambia de significado — sigue siendo el importe
  total de la nota, calculado client-side como hoy (ver research.md R4).

## Flujo de datos: `CalculoComprobante::calcular()`

**Firma actual**:

```php
public function calcular(array $items, float|string|null $descuentoGeneralPct, array $conceptos = []): array
```

**Firma nueva**:

```php
public function calcular(
    array $items,
    string $descuentoGeneralTipo,       // 'porcentaje' | 'monto'
    float|string|null $descuentoGeneralValor,
    array $conceptos = []
): array
```

Internamente, antes del loop de ítems existente:

```php
$descuentoGeneralValor = (float) ($descuentoGeneralValor ?? 0);

if ($descuentoGeneralTipo === 'monto') {
    $subtotalBruto = /* Σ (cantidad*precio_unitario - descuento de línea) por ítem, sin descuento general */;
    $descuentoGeneralPctEfectivo = $subtotalBruto > 0
        ? min(100, ($descuentoGeneralValor / $subtotalBruto) * 100)
        : 0;
} else {
    $descuentoGeneralPctEfectivo = $descuentoGeneralValor;
}

$factor = 1 - ($descuentoGeneralPctEfectivo / 100);
// ... resto del algoritmo sin cambios (spec 044)
```

Como el algoritmo original calcula `$subtotalSinDescuento` recorriendo los ítems una vez, y el pct
efectivo en modo `monto` necesita ese mismo subtotal *antes* de aplicarlo, la implementación real hace
un primer paso liviano (sólo brutos por línea, sin IVA) para obtener `$subtotalBruto` cuando el tipo es
`monto`, y recién después corre el loop completo con el factor ya resuelto — evita duplicar la lógica
de IVA/redondeo, sólo pre-calcula el subtotal.

## Controllers / FormRequests

- `StoreVentaRequest` / `UpdatePresupuestoRequest` / etc. (los 6 de Venta+Presupuesto+Compra):
  - `descuento_general_tipo` → `'nullable', 'in:porcentaje,monto'` (default `porcentaje` si ausente,
    mismo criterio que hoy para `descuento_general_pct` ausente = sin descuento).
  - `descuento_general_pct` → sin cambios (`nullable|numeric|between:0,100`), sólo se usa si tipo es
    `porcentaje`.
  - `descuento_general_monto` → `nullable|numeric|min:0`, con regla condicional adicional (FR-007,
    ver research.md R3) que lo compara contra el subtotal bruto de los ítems enviados.
- `StoreNotaCreditoDebitoRequest` / `UpdateNotaCreditoDebitoRequest`: mismas 3 reglas, agregadas de
  cero (hoy no validan nada de descuento general).
- Los controllers (`VentaController::store/update`, ídem Presupuesto/Compra) pasan
  `$datos['descuento_general_tipo'] ?? 'porcentaje'` y el valor correspondiente
  (`descuento_general_pct` o `descuento_general_monto` según el tipo) a
  `$this->calculo->calcular(...)`, y persisten las 2-3 columnas junto con el resto de los campos ya
  guardados hoy.
- `NotaCreditoDebitoController::store/storeCompra/update/updateCompra` persisten las 3 columnas nuevas
  tal cual llegan (sin pasar por `CalculoComprobante` — ver research.md R4).
