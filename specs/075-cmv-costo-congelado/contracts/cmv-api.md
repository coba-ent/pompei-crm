# Contrato: cálculo del CMV (spec 075)

**Fecha**: 2026-08-24

Esta feature no expone endpoints HTTP nuevos. El contrato relevante es **interno**: la firma del
servicio que calcula el CMV y el invariante que deben respetar todos sus consumidores.

---

## 1. `App\Services\Informes\CostoMercaderiaVendida`

### 1.1 `subconsulta(): Illuminate\Database\Query\Builder`

**Sin cambios.** Sigue devolviendo `producto_id → costo_promedio` sobre compras no eliminadas, para
usar con `leftJoinSub()`. Cambia su rol, no su forma: deja de ser la regla del CMV y pasa a ser el
**fallback** para líneas sin costo congelado.

### 1.2 `sqlCmv(string $columnaCantidad): string` → firma extendida

```php
public function sqlCmv(string $columnaCantidad, ?string $columnaCostoCongelado = null): string
```

| Parámetro | Descripción |
|---|---|
| `$columnaCantidad` | Expresión SQL de la cantidad, **ya con el signo aplicado** (negativa para NC). Sin cambios. |
| `$columnaCostoCongelado` | Expresión SQL de la columna de costo congelado (p. ej. `venta_items.costo_unitario`). Si es `null`, el comportamiento es idéntico al actual. |

**Devuelve** una expresión SQL escalar:

- Con costo congelado:
  `COALESCE(<costoCongelado>, costo_compras.costo_promedio, 0) * (<cantidad>)`
- Sin costo congelado (compatibilidad):
  `COALESCE(costo_compras.costo_promedio, 0) * (<cantidad>)`

El parámetro es opcional y por defecto `null` para que ningún consumidor existente se rompa al
compilar; los consumidores del Informe de Ventas **deben** pasarlo.

### 1.3 Invariantes (cada uno debe tener test)

| # | Invariante | Por qué importa |
|---|---|---|
| I1 | `costo_unitario = NULL` ⇒ se usa el promedio de compras | Cero regresión histórica (SC-003) |
| I2 | `costo_unitario = 0` ⇒ el CMV es **0**, NO el promedio de compras | Reproduce a Contagram con productos sin costo (FR-007). Se rompe si alguien escribe `NULLIF(costo_unitario, 0)` |
| I3 | Sin costo congelado ni compras ⇒ 0, nunca `NULL` | El KPI no puede dar `NULL` |
| I4 | La misma expresión aplica a ventas, NC y ND, sin ramas por tipo | Heredado de FR-016 de la spec 068 |
| I5 | El costo se guarda en positivo; el signo lo aporta la cantidad | Una NC resta CMV sin lógica especial |

---

## 2. Contrato de congelamiento

Todo punto que crea una línea de venta **debe** dejar `costo_unitario` resuelto según la tabla de
`data-model.md §1`. Los puntos son (ver `research.md §R4`):

| Punto | Responsable |
|---|---|
| Alta manual y edición | `App\Services\Ingresos\CalculoComprobante::calcular()` |
| Mercado Libre | `App\Services\MercadoLibre\ConversorOrdenAVenta` |
| Tiendanube | `App\Services\Tiendanube\ConversorOrdenAVenta` |
| Notas de crédito/débito | `App\Http\Controllers\NotaCreditoDebitoController` (6 puntos de creación — centralizar) |
| Comandos de migración | **No congelan.** Dejan `NULL` a propósito |

### Regla de conservación en la edición (FR-009)

```
Para cada línea nueva con producto_id P:
    si queda algún costo_unitario sin consumir de una línea anterior con producto_id P:
        conservar ese valor (y marcarlo como consumido)
    si no:
        congelar productos.costo vigente hoy
```

Los costos anteriores se leen de `$itemsAnteriores`, que `VentaController::update()` **ya captura**
en la línea 538 antes del `delete()`, para `StockDeVenta::reaplicarPorEdicion()`.

---

## 3. Contrato de salida del informe (sin cambios visibles)

Las columnas y KPIs del Informe de Ventas **no cambian de nombre, orden ni formato**. Sigue habiendo
`Costo Total Actual` y `CMV Total` en el detalle, y las cards `Costo Actual`, `Costo Mercadería
Vendida` y `Resultado`. Cambia únicamente **el valor** del CMV para las líneas que tengan costo
congelado.

Aplica igual a: la tabla HTML, el export Excel (`InformeVentasExport`), el PDF
(`resources/views/informes/pdf/ventas.blade.php`), Rankings y "Arma tu Informe" — todos consumen el
mismo motor `VentasInformeQuery`, así que heredan el cambio sin tocarse.
