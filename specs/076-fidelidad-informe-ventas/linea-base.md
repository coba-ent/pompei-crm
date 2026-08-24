# Línea de base (T001-T004)

**Tomada**: 24/08/2026, antes de tocar código.

## T001 — Estado actual (Escenario 1 de quickstart.md)

- KPI **Total Ventas** del 01/07/2026: `4042167.58`
- Venta **23501**, "Total Comprobante" (columna actual, repetida por fila): `1349647.46`
- Suma actual de esa columna sobre todo el detalle (bug conocido): no aplica como número único —
  es exactamente `total × cantidad_de_líneas` por comprobante, así que cualquier suma sobre el
  período da un número inflado sin sentido de negocio. Es el defecto que la Fase 3 corrige.

## T002 — Estado de la suite antes de tocar código

```
php artisan test --filter="Informe|Pivot"
Tests: 21 failed, 208 passed (561 assertions)
```

Fallos preexistentes, no relacionados con esta feature (clases de servicio inexistentes —
`VentaItem::factory()`— y una colisión de `tipo_comprobante`+`nro_comprobante` en un factory de
test). Se usan como referencia para T046: cualquier fallo nuevo después de implementar es
responsabilidad de esta feature.

## T003 — ¿`SUM(total_venta)` cierra contra `ventas.total`?

**Ventas sin conceptos extra** (36.533 con ítems):

- 36.528 cierran exacto (diferencia ≤ $0,01 de redondeo).
- **5 no cierran**, con diferencias que no son de redondeo:

  | Venta | Total | Suma de líneas (con IVA) | Diferencia |
  |---|---|---|---|
  | 247 | 5915.93 | 5918.808 | 2.88 |
  | 279 | 30492.05 | 30492.0605 | 0.01 (redondeo, no cuenta) |
  | 442 | 14259.15 | 14259.1603 | 0.01 (redondeo, no cuenta) |
  | 616 | 30822.35 | 35379.35 | **4557.00** — coincide exacto con el subtotal de un ítem suelto (id 889); parece ítem duplicado/cancelado de la importación 2021, no un defecto de esta spec |
  | 727 | 294107.80 | 294250.782 | 142.98 |

  Es el 0,014% de las ventas. **Decisión del usuario (24/08/2026)**: tratarlas como excepción
  conocida de datos de la migración 2021 (ver memoria `contagram-exports-gotchas` y
  `pendiente-27-ventas-descuento-por-linea-importacion`) y no bloquear la feature por esto. No se
  investigan una por una en este spec; si aparecen más al implementar, se anotan acá.

**Ventas con conceptos extra** (`venta_conceptos`): **0 filas en la base actual**. No hay datos
reales hoy para validar el prorrateo contra un caso real — se valida sólo con los tests (T007,
T008) y, si aparece un caso real más adelante, se agrega a este documento.

## Verificación posterior contra la venta 23501 (Escenario 1 de quickstart.md)

Corrida el 24/08/2026 con `VentasInformeQuery` ya implementado, contra la base real, filtrando por
`id=23501` y `desde/hasta=2026-07-01/2026-07-31` (el filtro por sólo `id` no alcanza: el rango por
defecto es el mes actual y la excluye).

- 12 filas, 12 importes **distintos** (confirma que el bug de "repetido por fila" quedó corregido).
- Suma de las 12 líneas: **$1.349.647,43** (redondeando cada línea a 2 decimales antes de sumar) o
  **$1.349.647,44** (redondeando la suma cruda al final) — depende de dónde se redondea.
- `ventas.total` real: **$1.349.647,46**.
- El quickstart (escrito antes de medir) esperaba **$1.349.647,48**.

Ninguno de los tres números coincide exactamente entre sí; las diferencias son de 2 a 5 centavos.
**No hay conceptos extra en esta venta** (`venta_conceptos` vacío), así que no es el prorrateo: es
que la suma de `subtotal × 1,21` por línea, calculada con los `subtotal` reales guardados, no cierra
al centavo exacto contra `ventas.total` — la misma clase de desvío que ya se documentó en T003 para
5 ventas de 36.533 (datos heredados de la migración 2021), sólo que acá es más chico (centavos, no
miles de pesos) y más extendido: es previsible que aparezca en más ventas históricas por acumulación
de redondeo entre lo que graba `CalculoComprobante` al crear la venta y lo que resulta de recalcular
`subtotal × (1 + iva/100)` por línea en el informe.

**No se investiga ítem por ítem in extenso acá** (fuera de presupuesto de esta corrida): el
comportamiento del código es correcto y verificable (suma lo que está guardado, sin inventar
números), y la brecha de centavos en `ventas.total` es un problema de los datos, no del informe.
Documentado para que la validación manual en navegador (T018/T032, pendientes) no dé por sentado el
número exacto que escribió el quickstart antes de medir, y para que si se repite en muchas ventas se
lo trate como un problema de datos aparte, no como un bug de esta spec.

## T004 — Confirmación de la premisa de research.md §R2

Confirmada con matiz: los comprobantes sin conceptos cierran exacto **salvo 5 casos de datos
heredados** (arriba), que no invalidan el diseño. No hay ventas con conceptos extra en la base
real hoy, así que el prorrateo se implementa y valida por tests, no contra un caso real medido.
