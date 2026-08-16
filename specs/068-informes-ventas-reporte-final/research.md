# Phase 0 — Research: Informes Tanda 2 (Ventas, Reporte Final)

Cada punto resuelve una incógnita técnica de la spec. Formato: Decisión / Motivo / Alternativas.

## R1 — Unidad de fila y unión de ventas con notas

**Decisión**: el detalle es una subconsulta `UNION ALL` entre `venta_items` (join `ventas`) y
`notas_credito_debito_items` (join `notas_credito_debito`), envuelta en `DB::query()->fromSub(...,
'detalle')`, exactamente como ya lo hace `ComprasInformeQuery::detalle()`.

**Motivo**: el patrón ya está probado en producción en el Informe de Compras, permite filtrar,
ordenar y paginar sobre el conjunto unificado desde SQL, y hace que los dos informes hermanos se
lean igual. Las columnas de ambas ramas deben proyectarse en el **mismo orden y con los mismos
alias**; las que no existen en una rama se emiten como literales con alias.

**Alternativas descartadas**: traer ventas y notas por separado y unirlas en PHP (rompe la
paginación server-side y los KPIs sobre el conjunto completo); una vista SQL materializada (agrega
una migración que la spec no necesita).

**Nota de signo**: las filas de Nota de Crédito se emiten con cantidad e importes en **negativo**
(`* -1`), y las de Nota de Débito en positivo, para que la ecuación de KPIs y la columna Resultado
salgan directas de un `SUM()` sin ramas por tipo.

## R2 — Cómo se calcula el CMV sin costo histórico

**Decisión**: `CMV Total = costo_promedio_compras(producto) × cantidad de la línea`, donde
`costo_promedio_compras` es `SUM(compra_items.precio_unitario × compra_items.cantidad) /
SUM(compra_items.cantidad)` sobre las compras **no eliminadas** del producto, sin recorte temporal.
Productos sin compras registradas → 0. Se resuelve con un `LEFT JOIN` a una subconsulta agrupada por
`producto_id`, encapsulada en `App\Services\Informes\CostoMercaderiaVendida`.

**Motivo**: el CRM no guarda el costo por movimiento (`movimientos_stock` no tiene columna de
costo), así que el costo histórico exacto no es reconstruible. El promedio ponderado de compras es
la única derivación que reproduce los datos del relevamiento: los ítems del Id 5 tienen "Costo Total
Actual" > 0 y CMV = 0 porque esos productos nunca se compraron, mientras que la Camisa del Id 6 —la
única comprada en el período— tiene CMV = 200.

**Alternativas descartadas**: (a) usar `productos.costo` también para el CMV — colapsaría las dos
columnas del informe en la misma cifra y contradiría el relevamiento; (b) FIFO real por lote —
requiere guardar costo por movimiento, o sea migración y backfill, fuera del alcance de una feature
de sólo lectura; (c) dejar el CMV siempre en 0 — vacía de sentido el tercer bloque de KPIs.

**Consecuencia documentable**: la definición adoptada es una **regla de negocio nueva** y debe
quedar escrita en `docs/documentacion_principal_crm.md` antes de `/speckit-tasks` (constitución I).

## R3 — Dónde vive la réplica del bug de Nota de Crédito (R1 de la spec)

**Decisión**: `VentasInformeQuery` calcula **siempre** `resultado = precio_neto − cmv`. La
desviación se aplica en un único método privado de `InformeVentasExport`, sobre la hoja legible, con
un comentario que la marca como réplica deliberada y referencia a la spec. La hoja plana y el PDF
usan el valor del servicio.

**Motivo**: aislar el defecto en la capa de presentación del archivo evita que contamine KPIs,
totales, PDF o cualquier consumidor futuro, y satisface el principio III de la constitución sin
desobedecer la decisión del usuario. Un test (`ReplicasContagramTest`) fija por escrito que la celda
desviada existe y que la suma de la columna no cambia.

**Alternativas descartadas**: aplicar la rama en el servicio (contaminaría pantalla y totales); no
replicar (contradice una decisión explícita y reafirmada del usuario).

## R4 — Estructura del Reporte Final y por qué no lleva DataTables

**Decisión**: un endpoint por vista devuelve el árbol completo ya agregado
(`bloque → categoría → [subcategoría] → [cuenta de tesorería] → monto`) más los subtotales, y el
front lo renderiza como acordeón Bootstrap sin paginación.

**Motivo**: el volumen es de decenas de filas (categorías × cuentas), no de miles; y el simulador
FR-034 exige recalcular al instante sin ir al servidor, lo que requiere tener el árbol entero en
memoria del cliente. Está registrado en `plan.md` → Complexity Tracking como la única desviación de
la regla #1.

**Alternativas descartadas**: DataTables + RowGroup (no soporta el checkbox de simulación ni el
recálculo local, y paginar rompería los subtotales visibles); recalcular en el servidor a cada clic
(latencia perceptible, contradice "en el instante del clic").

## R5 — Base devengado vs. base caja

**Decisión**: dos queries independientes por vista.

- **Ventas Vs. Compras (devengado)**: se imputa por `ventas.fecha_emision`,
  `compras.fecha_emision`, `notas_credito_debito.fecha_emision`, `gastos.fecha`,
  `otros_ingresos.fecha`. Los gastos **incluyen los pendientes**.
- **Cobros Vs Pagos (caja)**: se imputa por `cobros.fecha` y `pagos.fecha`; la categoría de
  agrupación es la de la venta/compra de origen, y el tercer nivel es `cobros.cuenta_tesoreria_id` /
  `pagos.cuenta_tesoreria_id`. Los gastos y otros ingresos se toman por su propia fecha y cuenta,
  **excluyendo los pendientes** (`gastos.pendiente = 0`).

**Motivo**: es literalmente la distinción contable que el relevamiento verificó con números reales
(§7.2: devengado $32.327,90 vs. caja $27.239,85). Mezclar las dos bases en una query parametrizada
haría el código más corto y mucho más difícil de auditar.

**Alternativas descartadas**: una única query con un flag de base (ilegible y propensa a arrastrar
un filtro de una base a la otra); apoyarse en `movimientos_tesoreria` como fuente única — no permite
recuperar la categoría de la venta/compra de origen con la misma limpieza.

## R6 — Convención de signos y dónde se aplica (R2 de la spec)

**Decisión**: `ReporteFinalQuery` devuelve **todo en positivo**, con un campo `naturaleza`
(`ingreso` / `egreso`) por nodo. La pantalla muestra egresos en positivo y
`Resultado = Ingresos − Egresos` en ambas vistas. El doble estándar de Contagram se aplica sólo al
escribir la hoja legible de cada vista en `ReporteFinalExport`. La hoja plana conserva el positivo +
`naturaleza`.

**Motivo**: mismo criterio que R3 — la anomalía es de formato de archivo, no de cálculo. Además deja
la hoja plana efectivamente reprocesable, que es su razón de existir.

**Alternativas descartadas**: guardar los egresos en negativo en el servicio (obliga a `abs()`
disperso en pantalla y PDF, y arrastra el doble estándar a todo el código).

## R7 — Mapeo de los 19 filtros del Informe de Ventas

| Filtro | Se resuelve contra |
|--------|--------------------|
| Id | `ventas.id` / `notas_credito_debito.id` |
| Producto/Servicio | `productos.id` (y `venta_items.descripcion` para conceptos libres) |
| Tipo de Producto/Servicio | `productos.tipo_producto_id` |
| Cliente | `ventas.cliente_id` |
| Productos | filtro booleano: líneas con producto asociado vs. conceptos libres |
| Facturado | existencia de `comprobantes_fiscales` con CAE para la venta |
| Vendedor | `ventas.vendedor_id` |
| Categoría de Venta | `ventas.categoria_id` (raíz o hija, como en Gastos de la Tanda 1) |
| Proveedor | `productos.proveedor_id` |
| Etiqueta | relación morph de etiquetas de la venta |
| Tipo y N° de Factura | `ventas.tipo_comprobante` + `ventas.nro_comprobante` |
| Usuario | `ventas.creado_por_id` |
| Nota Cliente / Nota Interna | `LIKE` sobre `ventas.nota_cliente` / `ventas.nota_interna` |
| Estado del Cobro | derivado de `SUM(cobros.monto)` vs. `ventas.total` (Cobrado / Parcial / Pendiente) |
| Tipo | tipo de operación: Venta / Nota de Crédito / Nota de Débito |
| Remitos | existencia de remito asociado a la venta |
| Tipo y N° de Remito | `remitos.tipo` + `remitos.nro` |
| Transportista | `remitos.transportista_id` |

**Decisión**: AND entre campos, OR dentro de cada campo multi-valor; los filtros de catálogo van con
Select2 (regla #5), y los que dependen de agregados (Estado del Cobro, Facturado, Remitos) se
resuelven con `whereExists` / subconsulta, no cargando colecciones en PHP.

**Brecha documentada**: el relevamiento declara 22 campos y enumera 19. Los 3 faltantes no se
inventan; la brecha se anota en `docs/documentacion_principal_crm.md §5`.

## R8 — KPIs sobre el conjunto filtrado

**Decisión**: un endpoint `stats` separado del endpoint `data`, que aplica los mismos filtros y
devuelve los 11 valores de los 3 bloques con una sola pasada de agregados sobre la subconsulta
unificada. Mismo patrón que `InformeComprasController::stats()`.

**Motivo**: DataTables ya pide la página; calcular los KPIs dentro de esa respuesta obligaría a
correr los agregados en cada paginación. Separarlos deja que el front los pida sólo cuando cambian
filtros o rango.

## R9 — Rendimiento

**Decisión**: apoyarse en los índices existentes de `ventas.fecha_emision`,
`notas_credito_debito.fecha_emision`, `cobros.fecha` y `pagos.fecha`; filtrar por rango **antes** de
la unión (dentro de cada rama del `UNION ALL`), no después; y precalcular el costo promedio por
producto en una subconsulta agrupada única en vez de una correlacionada por fila.

**Motivo**: es lo que sostiene SC-002 (< 3 s con 5.000 ventas). Sin filtrar dentro de cada rama, la
unión materializa el histórico completo antes de recortar.

**Verificación**: `quickstart.md` incluye el escenario de carga con el volumen objetivo.

## R10 — Reutilización de la Tanda 1

**Decisión**: se reutilizan sin modificar `resources/js/rango-emision.js` (selector de 9 opciones),
`App\Exports\Informes\HojaInforme` (hoja de Excel con encabezado y formato),
`resources/views/informes/pdf/_estilos.blade.php`, el permiso `informes.ver`, y el helper
`App\Services\Informes\ExpresionSql`.

**Motivo**: la Tanda 1 dejó estas piezas explícitamente como compartidas del módulo; duplicarlas
sería la sexta copia del mismo selector de fechas que ya motivó extraerlo.

**Riesgo**: si el Informe de Ventas necesitara un cambio en `HojaInforme`, hay que verificar que los
tres exports de la Tanda 1 sigan pasando sus tests antes de tocarla.
