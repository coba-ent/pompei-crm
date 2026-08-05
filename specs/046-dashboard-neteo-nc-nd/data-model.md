# Data Model: Neteo de NC/ND en el Dashboard

No se agregan entidades, campos ni migraciones nuevas. Esta feature es puramente de agregación
sobre entidades ya existentes:

## Entidades existentes reutilizadas

### Venta (`app/Models/Venta.php`, tabla `ventas`)
- Campos usados: `id`, `total`, `fecha_emision`, `categoria_id`, `cliente_id`.
- Relación usada: `notasCreditoDebito()` (HasMany hacia `NotaCreditoDebito` vía `venta_id`).
- Métodos ya existentes reutilizados como referencia de criterio (no se llaman directamente desde
  el Dashboard por performance — se replica el cálculo en SQL agregado): `totalCredito()`,
  `totalDebito()`.

### Compra (`app/Models/Compra.php`, tabla `compras`)
- Análogo simétrico a Venta: `id`, `total`, `fecha_emision`, `categoria_id`, `proveedor_id`,
  relación `notasCreditoDebito()` vía `compra_id`.

### NotaCreditoDebito (`app/Models/NotaCreditoDebito.php`, tabla `notas_credito_debito`)
- Campos usados: `tipo` (`credito`/`debito`), `monto`, `fecha_emision`, `venta_id` (nullable),
  `compra_id` (nullable), `deleted_at` (SoftDeletes — se excluyen notas borradas).
- Sin cambios de esquema. Una nota siempre referencia exactamente una Venta o una Compra (no
  ambas), heredado del diseño existente (spec 039/042/045).

## Estructura de salida (contrato JSON existente, sin cambios de forma)

Los 4 endpoints AJAX de `DashboardController` (`kpis`, `totales`, `graficoMensual`, `donas`) ya
devuelven la misma forma de JSON documentada en spec 010 — esta feature sólo cambia **el valor**
calculado en los campos monetarios (`ventas_creadas`, `venta_promedio`, `resultado`, `ventas`,
`compras` dentro de `totales`, las series `ventas`/`compras` de `graficoMensual`, y los montos por
categoría en `donas`), no la forma de la respuesta. El único cambio de contrato es el parámetro
`periodo` aceptado por los 4 endpoints, que ahora admite el valor adicional `'hoy'` junto a
`semana`/`mes_actual`/`mes_anterior`/`anio_actual`.

## Cálculo derivado (no persiste, ver research.md Decisión 1)

Para un rango `[$desde, $hasta]`, el monto neto de Ventas es:

```
neto_ventas(desde, hasta) =
    SUM_venta( GREATEST(0, venta.total + ND_en_rango(venta, desde, hasta) - NC_en_rango(venta, desde, hasta)) )
      WHERE venta.fecha_emision BETWEEN desde AND hasta
  + SUM_nota( signo(nota) * nota.monto )
      WHERE nota.fecha_emision BETWEEN desde AND hasta
        AND nota.venta.fecha_emision NOT BETWEEN desde AND hasta
```

donde `ND_en_rango`/`NC_en_rango` son subconsultas correlacionadas sobre `notas_credito_debito`
filtradas por `fecha_emision BETWEEN desde AND hasta` y `tipo`, y `signo(nota) = +1` si
`tipo='debito'`, `-1` si `tipo='credito'`. El cálculo de `neto_compras` es simétrico, sustituyendo
`venta_id` por `compra_id`.

Este mismo cálculo se reutiliza (parametrizado por rango de fechas) en `metricasRango()` (KPIs y
Totales del Período), en cada iteración mensual de `graficoMensual()`, y — agrupado además por
`categoria_id` — en `composicionPorCategoria()`.
