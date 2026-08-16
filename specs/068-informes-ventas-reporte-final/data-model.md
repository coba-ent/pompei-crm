# Phase 1 — Data Model: Informes Tanda 2

**Sin migraciones.** Esta feature sólo lee. Lo que sigue documenta qué tablas se leen y cómo se
derivan las cifras que no existen como columna.

## Tablas leídas

| Tabla | Se usa para |
|-------|-------------|
| `ventas` | cabecera del comprobante: cliente, categoría, vendedor, fecha de emisión, tipo y nro de comprobante, total, notas, usuario creador |
| `venta_items` | unidad de fila del detalle: producto/descripción, cantidad, precio unitario, descuento, IVA, subtotales |
| `venta_conceptos` | conceptos no-producto que integran el total del comprobante |
| `notas_credito_debito` | filas de NC/ND del detalle y términos de la ecuación de KPIs |
| `nota_credito_debito_items` | ítems de la nota (cantidad, precio, descuento, IVA) |
| `cobros` | base caja del Reporte Final (fecha, monto, cuenta de tesorería) |
| `pagos` | base caja, lado egresos |
| `compras`, `compra_items` | egresos devengados del Reporte Final y **fuente del costo promedio del CMV** |
| `gastos` | egresos por categoría → subcategoría (pendientes incluidos sólo en la vista devengado) |
| `otros_ingresos` | ingresos no provenientes de ventas |
| `categorias` | jerarquía padre/hijo, tipada por venta/compra/gasto/ingreso |
| `cuentas_tesoreria` | tercer nivel de la vista Cobros Vs Pagos |
| `clientes`, `productos`, `tipos_producto`, `proveedores`, `etiquetas`, `vendedores`, `users` | rótulos y filtros |
| `remitos`, `transportistas` | filtros Remitos / Tipo y N° de Remito / Transportista |
| `comprobantes_fiscales` | filtro "Facturado" |

Todas las consultas respetan el **borrado lógico** (`deleted_at IS NULL`) en las tablas que lo
tienen (FR-009).

## Fila del detalle de Ventas (proyección unificada)

Una fila por ítem. Las dos ramas del `UNION ALL` proyectan estas columnas en este orden:

| Columna | Rama Venta | Rama Nota (NC en negativo, ND en positivo) |
|---------|-----------|--------------------------------------------|
| `id` | `ventas.id` | `notas_credito_debito.id` |
| `tipo_operacion` | `'venta'` | `'nc'` / `'nd'` |
| `fecha` | `ventas.fecha_emision` | `notas_credito_debito.fecha_emision` |
| `comprobante` | tipo + nro de la venta | tipo + nro de la nota |
| `cliente` | `clientes.nombre` | cliente de la venta asociada; si la nota no tiene venta asociada, el rótulo `Sin cliente` (la fila **nunca** se descarta — edge case de la spec) |
| `producto` | `productos.nombre` o `venta_items.descripcion` | ídem sobre la nota |
| `cantidad` | `venta_items.cantidad` | `± nota_items.cantidad` |
| `precio_unitario` | `venta_items.precio_unitario` | `± nota_items.precio` |
| `costo_total_actual` | `productos.costo × cantidad` | ídem, con el signo de la nota |
| `cmv_total` | `costo_promedio_compras × cantidad` | ídem, con el signo de la nota |
| `precio_neto` | `venta_items.subtotal` (ya neto de IVA y con los dos descuentos) | **recalculado**: `cantidad × precio × (1 − descuento_pct/100) × factor general`, con signo — los ítems de nota no guardan subtotal |
| `resultado` | `precio_neto − cmv_total` | ídem (misma fórmula, sin rama por tipo) |
| `total_comprobante` | `ventas.total` (repetido en cada fila) | `notas_credito_debito.monto` |

Columnas técnicas adicionales (no visibles) para filtrar y ordenar: `cliente_id`, `producto_id`,
`tipo_producto_id`, `proveedor_id`, `categoria_id`, `vendedor_id`, `usuario_id`, `tipo_comprobante`,
`nro_comprobante`.

## Derivaciones

### Costo promedio de compras (base del CMV)

```
costo_promedio_compras(producto) =
    SUM(compra_items.precio_unitario × compra_items.cantidad)
  / SUM(compra_items.cantidad)
```

sobre compras y compra_items no eliminados; sin recorte de fecha. `NULL` (producto sin compras) → 0.
Se calcula una sola vez por producto en una subconsulta agrupada (research R2, R9).

### KPIs del Informe de Ventas

| KPI | Fórmula |
|-----|---------|
| Total Ventas Creadas | `SUM(ventas.total)` de las ventas del rango |
| Total Nota de Débito | `SUM(notas.monto)` con `tipo = 'debito'` |
| Total Nota de Crédito | `SUM(notas.monto)` con `tipo = 'credito'` (se muestra en positivo y se **resta**) |
| **Total Ventas** | `Creadas + ND − NC` |
| Cantidad Prod./Serv. | `SUM(cantidad)` de todas las líneas (no el conteo de filas) |
| Cantidad Ventas Creadas | `COUNT(DISTINCT ventas.id)` |
| Venta Promedio | `Total Ventas / Cantidad Ventas Creadas`; 0 si no hay ventas |
| Costo Actual | `SUM(costo_total_actual)` |
| Precio Neto | `SUM(precio_neto)` |
| Costo Mercadería Vendida | `SUM(cmv_total)` |
| **Resultado** | `Precio Neto − CMV` |

Todos se calculan sobre el conjunto filtrado completo, nunca sobre la página visible.

### Árbol del Reporte Final

Nodo genérico devuelto por `ReporteFinalQuery`:

```
{ nivel, etiqueta, naturaleza: 'ingreso'|'egreso', monto (siempre positivo), hijos[] }
```

**Vista devengado — Ventas Vs. Compras**

```
Ingresos → Ventas          → Categoría
Ingresos → Otros Ingresos  → Categoría
Egresos  → Compras         → Categoría
Egresos  → Gastos          → Categoría → Subcategoría        (incluye pendientes)
```

**Vista caja — Cobros Vs Pagos**

```
Ingresos → Ventas Cobradas → Categoría → Cuenta de Tesorería
Ingresos → Otros Ingresos  → Categoría → Cuenta de Tesorería
Egresos  → Compras Pagadas → Categoría → Cuenta de Tesorería
Egresos  → Gastos          → Categoría → Subcategoría → Cuenta de Tesorería   (sin pendientes)
```

Rótulos de fallback: `Sin categoría`, `Sin subcategoría`, `Sin cuenta de tesorería`.

> `ventas.categoria_id` y `compras.categoria_id` son **nullable**, así que "Sin categoría" es un caso
> real en el Reporte Final. En `gastos`, en cambio, `categoria_id` es `NOT NULL` (ver
> `modelo_datos.md` §Deuda de modelo): ahí el rótulo queda como red de contención, igual que en la
> Tanda 1, y el caso que sí ocurre es "Sin subcategoría".

Dentro de una categoría con actividad se listan **todas** las cuentas de tesorería con
`cuentas_tesoreria.visible = 1`, aunque el monto sea 0 (FR-038). Una cuenta no visible **con**
movimientos en el período también se lista, para que ningún importe quede oculto.

### Simulación

El nodo de nivel Categoría lleva `activo: true` por defecto. Destildarlo excluye su monto (y el de
sus descendientes) de: el subtotal de su bloque, el Total de su naturaleza y el Resultado. El
recálculo es **local**; el estado de los checkboxes viaja al servidor únicamente al exportar, como
lista de categorías excluidas.

## Invariantes verificables (base de los tests)

1. `Total Ventas = Total Ventas Creadas + Total ND − Total NC`.
2. `Resultado (KPI) = Precio Neto − CMV`, y coincide con la suma de la columna `resultado` del
   detalle **en pantalla**.
3. La suma de la columna `total_comprobante` **no** es un KPI: se repite por fila y no debe sumarse.
4. En el Reporte Final, `Resultado = Total Ingresos − Total Egresos` en las dos vistas en pantalla.
5. `Total Ingresos (caja) ≤ Total Ingresos (devengado)` para el mismo período cuando no hay cobros
   de períodos anteriores; la diferencia es exactamente lo facturado y no cobrado en el rango.
6. Ningún registro con `deleted_at` computa en ningún total.
7. **R1**: la celda Resultado de una fila NC en la hoja legible del Excel = `precio_neto + cmv`,
   mientras que la misma fila en pantalla y en la hoja plana = `precio_neto − cmv`; la suma de la
   columna y los KPIs no cambian.
8. **R2**: en el Excel, la hoja devengado trae Total Egresos negativo y
   `Resultado = Ingresos + Egresos`; la hoja caja trae Total Egresos positivo,
   `Resultado = Ingresos − Egresos`, subtotales de bloque negativos y líneas de cuenta positivas.
