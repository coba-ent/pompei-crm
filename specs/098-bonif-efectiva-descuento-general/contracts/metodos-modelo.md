# Contrato: métodos nuevos en los modelos de ítem/comprobante

No es una API HTTP — esta feature no agrega rutas ni endpoints. El "contrato" acá es la interfaz
pública que exponen los modelos Eloquent hacia las vistas Blade (PDF) y, por espejo funcional,
hacia el JS de cada pantalla. Fijar esta interfaz permite escribir los tests de Feature (Fase de
tasks) contra un contrato estable antes de implementar el cuerpo.

## `PresupuestoItem::bonifEfectivaPct(): float`
## `VentaItem::bonifEfectivaPct(): float`
## `CompraItem::bonifEfectivaPct(): float`

**Entrada**: ninguna (usa `$this->cantidad`, `$this->precio_unitario`, `$this->subtotal` ya
cargados en el modelo).

**Salida**: porcentaje de bonificación efectiva de esa línea, como `float`, redondeado a 2
decimales. `0.0` cuando `cantidad * precio_unitario <= 0` (sin división por cero).

**Casos de contrato** (ver tests en Fase de tasks):

| Entrada (cantidad, precio, subtotal) | Salida esperada | Caso |
|---|---|---|
| (1, 100, 100) | 0.0 | Sin ningún descuento |
| (1, 100, 90) | 10.0 | Sólo descuento de línea (10%) |
| (1, 100, 90), con Descuento General 0% en cabecera | 10.0 | Idéntico al anterior — el método no lee la cabecera |
| (1, 100, 81) | 19.0 | 10% de línea + 10% general combinados (no 20%) |
| (0, 100, 0) | 0.0 | Cantidad cero, sin división por cero |
| (1, 0, 0) | 0.0 | Precio cero, sin división por cero |
| (2, 50, 90) | 10.0 | Bruto = 100 (2×50), subtotal 90 → 10% |

## `PresupuestoItem::bonifEfectivaEtiqueta(): string`
## `VentaItem::bonifEfectivaEtiqueta(): string`
## `CompraItem::bonifEfectivaEtiqueta(): string`

**Entrada**: ninguna.

**Salida**: `string` lista para imprimir en el PDF — `"10%"`, `"12,5%"` (coma decimal, formato
argentino, sin ceros de más) o `"-"` cuando `bonifEfectivaPct() <= 0`.

**Casos de contrato**:

| `bonifEfectivaPct()` | Salida |
|---|---|
| 0.0 | `"-"` |
| 10.0 | `"10%"` |
| 12.5 | `"12,5%"` |
| 19.0 | `"19%"` |

## `NotaCreditoDebito::montoDescuentoGeneral(): float`

**Entrada**: ninguna (usa `$this->items` — cargado o lazy-loaded — y `$this->descuento_general_tipo`
/ `_pct` / `_monto`).

**Salida**: importe en pesos que representa el Descuento General de la nota sobre el subtotal de
sus ítems (sin IVA), como `float` redondeado a 2 decimales.

**Casos de contrato**:

| Ítems (bruto tras descuento de línea) | descuento_general_tipo/valor | Salida esperada |
|---|---|---|
| Σ = 1000 | porcentaje, 10 | 100.0 |
| Σ = 1000 | monto, 150 | 150.0 |
| Σ = 0 | monto, 150 | 0.0 (sin división por cero — factor cae a 1) |
| Σ = 1000 | porcentaje, 0 (o null) | 0.0 |

## Contrato de comportamiento del JS (sin tipos formales — documentado para test manual)

`factorDescuentoGeneral()` en `presupuestos.js` / `ventas.js` / `compras.js`:

- **No** cambia su valor de retorno respecto al que ya calculaba dentro de `recalcular()` antes de
  esta feature — es una extracción, no una reimplementación. Contrato: para el mismo estado de
  `items` y del campo `#f-descuento-general`, el `factor` devuelto es bit-a-bit el mismo que
  producía el código inline anterior.
- `renderItems()` multiplica `subtotal` (ya con el descuento de línea aplicado) por este factor
  antes de pintar la celda — el campo editable "Desc." de la fila NO se toca (FR-003).

`notas-credito-debito.js`: sin cambios de contrato — no se toca ningún archivo JS de NC/ND en
esta feature (FR-008).
