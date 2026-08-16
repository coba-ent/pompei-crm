# Phase 1 — Data Model: Informes Tanda 3

## Entidad nueva: `informes_vistas`

**Única migración de esta spec.** Persiste la configuración de un cruce guardado, no sus datos
(research R4): al abrir la pestaña, el cruce se recalcula con la información vigente.

| Columna | Tipo | Notas |
|---------|------|-------|
| `id` | bigint PK | |
| `informe` | enum(`ventas`,`compras`) | a qué informe pertenece (FR-035); una vista de Ventas nunca se lista en Compras |
| `descripcion` | string | nombre que el usuario le dio; se usa como rótulo de la pestaña (FR-032) |
| `config` | json | ver forma abajo |
| `creado_por_id` | FK → `users`, nullable on delete | para auditoría (FR-034); no restringe la lectura |
| `created_at` / `updated_at` | timestamps | |

**Sin `deleted_at`**: no es documento fiscal ni contable (constitución III); es configuración de
presentación. Eliminarla es un `DELETE` real (FR-036).

**Índice**: `(informe)` — todo listado de pestañas filtra por informe.

### Forma de `config` (JSON)

```json
{
  "filas": ["clientes"],
  "columnas": ["fecha_emision.año", "fecha_emision.mes"],
  "dato": "total_venta",
  "accion": "suma",
  "exclusiones": { "clientes": ["Consumidor Final"] }
}
```

- `filas` / `columnas`: listas ordenadas de claves de dimensión (research R9). El orden importa: es
  el orden de anidado del cruce.
- `dato`: una de las 4 claves de medida del informe (FR-012).
- `accion`: una de las claves de agregador (FR-013); si `dato` es un conteo, sólo puede ser `suma`
  (FR-014) — se valida al guardar, no sólo en el cliente.
- `exclusiones`: por dimensión, los valores que el embudo de columna dejó afuera (FR-015). Mapa
  vacío si no se excluyó nada.

Un ranking predefinido (Ranking de Clientes, etc.) es la misma forma de `config` con `filas` fijado
a la dimensión del ranking y `columnas` en `["fecha_emision.año", "fecha_emision.mes"]` — **no se
persiste**, se construye en memoria al entrar a la pestaña (FR-019).

## Dataset proyectado (no persistido)

Lo que el servidor entrega para que el cliente arme el cruce. Una fila por ítem — el mismo conjunto
que ya produce el detalle de cada informe (FR-011b) —, con **sólo** las columnas que son dimensión o
medida; nada de las columnas de export que no participan del pivot.

### Ventas — `VentasPivotDataset`

Reutiliza `VentasInformeQuery::detalle()` con sus mismos filtros de rango y panel (FR-040), y
**amplía** su proyección (ya existente para el detalle de la tanda 2) con las columnas que faltan
como dimensión:

| Columna del dataset | Tipo | Origen |
|----------------------|------|--------|
| `fecha_emision` | fecha | ya proyectada en el detalle |
| `cliente` | texto | ya proyectada |
| `categoria` | texto | **nueva**: nombre de la categoría raíz de la venta, o "Sin categoría" |
| `vendedor` | texto | **nueva**: `vendedores.nombre`, o "Sin vendedor" |
| `tipo_comprobante` | texto | ya proyectada (`ventas.tipo_comprobante` / de la nota) |
| `producto` | texto | ya proyectada |
| `tipo_producto` | texto | **nueva**: `tipos_producto.nombre`, o "Sin tipo de producto" |
| `proveedor` | texto | **nueva**: proveedor del producto de la línea, o "Sin proveedor" |
| `cantidad` | decimal | ya proyectada, con el signo de la nota |
| `descuento_pct` | decimal | **nueva**: descuento de línea, 0 si no tiene |
| `etiquetas` | texto[] | **nueva**: etiquetas del comprobante (una línea puede aportar a varias) |
| `total_venta` | decimal | ya proyectada como `precio_total_neto` + el IVA de la línea (con impuestos) |
| `total_venta_sin_impuestos` | decimal | = `precio_neto` ya proyectado (FR-012b) |
| `comprobante_id` | entero técnico | para que "Cantidad de Ventas" cuente comprobantes distintos, no líneas (FR-012b) |

**Filtro de baja**: hereda el de `VentasInformeQuery` (`deleted_at IS NULL` en ventas y notas,
FR-044).

**Signo de NC/ND**: hereda el de la tanda 2 — NC en negativo, ND en positivo (FR-045) — sin rama
nueva, porque ya viene resuelto en la proyección que se reutiliza.

**Tope de filas**: 50.000 (research R2). Superado, `422` con mensaje para acotar el rango, mostrado
por Toastr.

### Compras — `ComprasPivotDataset`

Mismo criterio sobre `ComprasInformeQuery::detalle()`. Ampliación de proyección análoga, **sin**
`vendedor` (Compras no tiene esa dimensión — research R9) y con `proveedor` como dimensión primaria
(ya existe en la proyección de compras) en vez de derivada del producto.

| Columna del dataset | Tipo | Origen |
|----------------------|------|--------|
| `fecha_emision`, `proveedor`, `categoria`, `tipo_comprobante`, `producto`, `tipo_producto`,
  `cantidad`, `descuento_pct`, `etiquetas`, `total_compra`, `total_compra_sin_impuestos`,
  `comprobante_id` | — | mismo criterio que Ventas, sobre las columnas ya proyectadas por `ComprasInformeQuery` + las que ésta agrega |

## Medidas ("Dato") y su fórmula

| Clave | Ventas | Compras |
|-------|--------|---------|
| `total_venta` / `total_compra` | `SUM(total_venta)` de las filas del cruce | `SUM(total_compra)` |
| `total_venta_sin_impuestos` / `..._sin_impuestos` | `SUM(total_venta_sin_impuestos)` | ídem |
| `cantidad_productos` | `SUM(cantidad)` | `SUM(cantidad)` |
| `cantidad_ventas` / `cantidad_compras` | `COUNT(DISTINCT comprobante_id)` dentro de la celda | ídem |

Ninguna medida usa el total del comprobante (FR-012b): se repetiría por línea.

## Agregadores ("Accion")

Mapeo directo a los agregadores nativos de PivotTable.js (research R1, R7):

| Clave | Agregador PivotTable.js | Disponible cuando... |
|-------|--------------------------|------------------------|
| `suma` | `Sum` | siempre |
| `promedio` | `Average` | Dato es importe |
| `minimo` | `Minimum` | Dato es importe |
| `maximo` | `Maximum` | Dato es importe |
| `fraccion_total` | `Sum as Fraction of Total` | Dato es importe |
| `fraccion_fila` | `Sum as Fraction of Rows` | Dato es importe |
| `fraccion_columna` | `Sum as Fraction of Columns` | Dato es importe |

Cuando `dato` ∈ {`cantidad_ventas`, `cantidad_compras`}, la única opción ofrecida es `suma` (FR-014).

## Invariantes verificables (base de los tests)

1. El dataset de Ventas, agregado con `dato = total_venta`, `accion = suma`, sin ninguna dimensión
   (un solo total), coincide con `Total Ventas` de `VentasInformeQuery::kpis()` sobre el mismo rango
   y los mismos filtros (SC-006).
2. Ídem para Compras contra `ComprasInformeQuery::kpis()`.
3. `cantidad_ventas` sobre un comprobante con 3 líneas cuenta **1**, no 3.
4. Una fila de NC resta del total; una de ND suma — sin rama de cálculo distinta a la ya probada en
   la tanda 2.
5. Ningún registro con `deleted_at` aparece en el dataset.
6. Una vista guardada con `informe = ventas` no aparece en el listado de pestañas de Compras.
7. Guardar una `config` con `accion` inválida para el `dato` elegido (p. ej. `promedio` con
   `cantidad_ventas`) es rechazado por el servidor, no sólo evitado por el cliente.
8. Eliminar una vista guardada no toca ninguna fila de `ventas`, `compras` ni tablas relacionadas.
