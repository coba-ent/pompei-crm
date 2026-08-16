# Feature Specification: Módulo Informes — Tanda 2 (Informe de Ventas, Reporte Final)

**Feature Branch**: `068-informes-ventas-reporte-final`

**Created**: 2026-08-15

**Status**: Draft

**Input**: User description: "Módulo Informes — Tanda 2: Informe de Ventas y Reporte Final. Fuente de verdad estructural: docs/Informe-Modulo-Informes-2026-08-14/. Continúa la Tanda 1 (spec 067) y sigue sus mismas convenciones. Fidelidad total a Contagram, incluso donde Contagram está mal: se replica el cálculo de 'Resultado' de las líneas de Nota de Crédito y el doble estándar de signos del Reporte Final. Rankings / Arma tu Informe / PivotTable.js y la vista /graphs quedan fuera de alcance."

## Contexto y fuente de verdad

Contagram agrupa sus informes en una landing `/reports` con **8 tarjetas**. La **Tanda 1** (spec 067)
cubrió 3 (Compras, Gastos, Cta Cte Proveedores); Stock (spec 003) y Cta Cte Clientes (spec 029) ya
existían. Esta **Tanda 2** cubre los 2 informes tabulares que faltan:

| # | Informe | Estado |
|---|---------|--------|
| 1 | Ventas | **esta tanda** |
| 2 | Compras | spec 067 ✅ |
| 3 | Cta Cte Clientes | spec 029 ✅ |
| 4 | Cta Cte Proveedores | spec 067 ✅ |
| 5 | Reporte Final | **esta tanda** |
| 6 | Gastos | spec 067 ✅ |
| 7 | Stock | spec 003 ✅ |
| 8 | Rankings | fuera de alcance (tanda futura) |

**Relevamiento base**: `docs/Informe-Modulo-Informes-2026-08-14/informe_modulo_informes_texto.md`
(§3, §7, §11.2, §11.4), sus capturas y los `.xlsx` exportados de la app real:

| Fuente | Cubre |
|--------|-------|
| `Capturas/02_informe_ventas_kpis_tabla.gif` | Ventas: 3 bloques de KPIs + tabla de detalle de 12 columnas |
| `Capturas/03_ventas_filtros_22campos.gif` | Ventas: panel de filtros |
| `Capturas/04_ventas_emision_desde_hasta_calendario.gif` | Selector "Emisión": 9 opciones + doble calendario |
| `Capturas/23_24_25_reporte_final_ventasvscompras_simulacion_cobrosvspagos.gif` | Reporte Final: ambas pestañas + simulador "Activo" |
| `Informe_de_Ventas_Resumen_14-08-2026_1303_Hs.xlsx` | Export de Ventas: KPIs + 12 columnas + el bug de NC |
| `Informe Final ... 1308 Hs.xlsx` / `... 1310 Hs.xlsx` | Reporte Final: jerarquía y convención de signos de cada pestaña |

**Divergencias estructurales deliberadas y ya decididas** (heredadas de la Tanda 1):

1. **No se construye la landing de tarjetas `/reports`**. Cada informe es un ítem propio del
   desplegable "Informes" del sidebar con su propia URL real, sin fragmentos `#`. Motivo: nuestro
   sidebar despliega submenús y el de Contagram no.
2. **Export dual con doble hoja**. Contagram exporta el Informe de Ventas en **una sola hoja**; acá
   se aplica el patrón de doble hoja (una formateada para leer + una plana para reprocesar) que
   Contagram sólo usa en Gastos y que la Tanda 1 ya adoptó como estándar del módulo (§11.5 del
   relevamiento lo recomienda explícitamente).
3. **Las pestañas "Rankings" y "Arma tu Informe" del Informe de Ventas no se construyen** en esta
   tanda. El Informe de Ventas queda como pantalla única, sin barra de pestañas.

## Réplicas deliberadas de comportamiento de origen

El usuario decidió **fidelidad total a Contagram, incluso donde Contagram está mal**, para que los
números coincidan exactamente al comparar contra la app original. Las dos anomalías detectadas en el
relevamiento se replican tal cual. **Ambas viven únicamente en el archivo Excel exportado**: la
pantalla de Contagram, en los dos casos, muestra el número correcto/coherente.

### R1 — "Resultado" de las líneas de Nota de Crédito en el Excel de Ventas (§11.2)

| Fila | Precio de Venta | CMV Total | Pantalla | Excel |
|------|-----------------|-----------|----------|-------|
| Venta | 370,00 | 200,00 | 170,00 | 170,00 |
| Nota de Crédito | -370,00 | -200,00 | **-170,00** | **-570,00** |

En pantalla `Resultado = Precio de Venta − CMV Total` para todas las filas. En el Excel, las filas de
tipo Nota de Crédito usan una rama distinta que **suma** en vez de restar (-370 + -200 = -570). El
desvío queda contenido en esa celda: **no se propaga a los KPIs ni a ningún total agregado**.

### R2 — Doble estándar de signos del Reporte Final en el Excel (§11.4)

| Pestaña | Total Egresos en el Excel | Resultado |
|---------|---------------------------|-----------|
| Ventas Vs. Compras | **negativo** (-14.157,45) | Ingresos **+** Egresos |
| Cobros Vs Pagos | **positivo** (10.863,30) | Ingresos **−** Egresos |

Además, en "Cobros Vs Pagos" los **subtotales por bloque** ("Total Compras Pagadas", "Total Gastos")
van en negativo aunque cada línea individual por cuenta de tesorería va en positivo. En pantalla, en
cambio, ambas pestañas muestran Total Egresos en positivo y Resultado = Ingresos − Egresos.

## Clarifications

### Session 2026-08-15

Las tres decisiones de fondo (alcance Ventas + Reporte Final; replicar R1; replicar R2) las tomó el
usuario antes de especificar y son firmes. Las siguientes son ambigüedades residuales resueltas con
criterio propio durante la cadena, sin interrumpir al usuario (regla "cadena completa sin preguntar"
de CLAUDE.md); quedan registradas acá para que sean revisables.

- Q: ¿Cuál es la unidad de fila de la tabla de detalle del Informe de Ventas? → A: **una fila por
  ítem de venta** (no por venta), igual que en el Informe de Compras de la Tanda 1. Es lo que
  muestran tanto la pantalla como el Excel: Producto/Servicio, Cantidad y Precio Unitario son de
  línea, mientras "Total Comprobante"/"Total Venta" se repite en cada fila del mismo comprobante.
  Las Notas de Crédito y Débito aportan sus propias filas con el mismo criterio y con signo negativo
  en el caso de las NC.
- Q: ¿Cómo se calcula "CMV Total" (Costo de Mercadería Vendida) si el CRM no guarda el costo
  histórico de cada movimiento? → A: **costo promedio ponderado de las compras registradas del
  producto**, multiplicado por la cantidad de la línea; si el producto no tiene ninguna compra
  registrada, CMV = 0. Es la única lectura compatible con los datos del relevamiento (los ítems del
  Id 5 tienen "Costo Total Actual" > 0 pero CMV = 0 porque nunca se compraron, mientras que la
  Camisa del Id 6 —comprada en el período— sí tiene CMV = 200). Se distingue explícitamente de
  "Costo Total Actual", que sí usa el `costo` vigente del producto por la cantidad.
- Q: El relevamiento dice "panel de 22 campos" pero enumera sólo 19. ¿Qué se construye? → A: **los
  19 campos efectivamente enumerados**. Los 3 restantes no están identificados en ninguna fuente, y
  la brecha se documenta en `docs/documentacion_principal_crm.md §5` como pendiente de
  re-relevamiento, en vez de inventar campos.
- Q: ¿El simulador "Activo" del Reporte Final afecta la exportación? → A: **sí**. El Excel y el PDF
  se generan sobre el conjunto de categorías activas en el momento de exportar, de modo que el
  archivo refleje exactamente lo que el usuario está viendo. Contagram no lo documenta; sería
  desconcertante que el archivo contradiga la pantalla.
- Q: El Excel de "Cobros Vs Pagos" de Contagram sale con las celdas Desde/Hasta **vacías**. ¿Se
  replica? → A: **no**. Es una omisión sin valor informativo (no cambia ningún número), no una regla
  de cálculo; nuestro export completa siempre Desde y Hasta en las dos pestañas.
- Q: ¿Qué comprende "mes actual" en el selector Emisión? → A: **el mes calendario completo**
  (1 al 30/31), incluyendo fechas futuras dentro del mes, tal como se verificó en Contagram (§8 del
  relevamiento) y como ya lo implementa `rango-emision.js` de la Tanda 1.

### Session 2026-08-15 (segunda pasada)

Ambigüedades detectadas al revisar la spec contra el modelo de datos del CRM, resueltas con el mismo
criterio (sin interrumpir al usuario):

- Q: ¿Qué comprobantes entran en el informe? → A: los **no eliminados**. Las ventas y notas dadas de
  baja (borrado lógico) quedan fuera de KPIs, detalle y exports, en cualquiera de los dos informes.
- Q: ¿"Precio Total Neto" / "Precio Neto" incluye IVA? → A: **no**, es el importe sin impuestos
  (subtotal de la línea después de descuento). "Total Comprobante" sí es el total con impuestos, y
  se repite en cada fila del mismo comprobante.
- Q: ¿Cuál es el orden por defecto del detalle de Ventas? → A: **fecha de emisión descendente**, y
  dentro de la misma fecha por Id descendente, para que lo más reciente quede arriba (mismo criterio
  que el Informe de Compras de la Tanda 1). Las columnas son ordenables por el usuario.
- Q: En "Cobros Vs Pagos", ¿por qué fecha se imputa un cobro o un pago? → A: por la **fecha del
  cobro/pago**, no la del comprobante que lo origina — es lo que distingue la base caja de la base
  devengado. La categoría con la que se agrupa sigue siendo la de la venta o compra de origen.
- Q: ¿Qué es el filtro "Tipo" del Informe de Ventas? → A: el **tipo de operación del comprobante**
  (Venta / Nota de Crédito / Nota de Débito), distinto de "Tipo y N° de Factura", que refiere al
  tipo de comprobante fiscal (FCA/FCB/NCB…) y su numeración.
- Q: ¿Los ítems de Nota de Crédito y Débito llevan CMV y Costo Actual? → A: **sí**, con el mismo
  criterio que las ventas y con el signo de la nota, para que R1 sea reproducible tal como se
  observó en el Excel de origen.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Ver el Informe de Ventas del período (Priority: P1)

Como responsable del negocio quiero entrar a Informes → Ventas, elegir un rango de emisión y ver de
un vistazo cuánto vendí, cuántas unidades salieron, cuál fue la venta promedio y qué resultado dejó,
con el detalle línea por línea de cada comprobante del período.

**Why this priority**: es el informe más consultado del módulo y el único de los 8 que todavía no
existe en su forma tabular. Entregado solo, ya es un MVP con valor completo.

**Independent Test**: cargar ventas, notas de crédito y notas de débito en un período, entrar a la
pantalla y verificar que los 3 bloques de KPIs y las 12 columnas del detalle coinciden con los datos
cargados, cambiando el rango de emisión y viendo recalcular todo.

**Acceptance Scenarios**:

1. **Given** ventas, ND y NC cargadas en el mes actual, **When** el usuario abre Informes → Ventas,
   **Then** ve el rango por defecto (mes calendario actual completo), los 3 bloques de KPIs y la
   tabla de detalle paginada con una fila por ítem.
2. **Given** la pantalla abierta, **When** el usuario elige "Año actual" en el selector Emisión,
   **Then** los KPIs y la tabla se recalculan sin recargar la página.
3. **Given** una venta de $370 con CMV $200 y su Nota de Crédito total, **When** se mira el detalle,
   **Then** la fila de venta muestra Resultado 170,00 y la fila de NC muestra Resultado -170,00.
4. **Given** un período sin ventas, **When** se consulta, **Then** los KPIs muestran $0,00 y la
   tabla muestra el mensaje de vacío, sin errores.

---

### User Story 2 - Filtrar y exportar el Informe de Ventas (Priority: P2)

Como usuario quiero acotar el informe por cliente, producto, vendedor, categoría, estado del cobro,
etc., y llevarme el resultado en Excel o verlo en PDF para archivarlo o mandarlo al contador.

**Why this priority**: sin filtros el informe sirve para mirar, no para trabajar; el export es el
uso concreto de fin de mes. Depende de US1 para tener qué filtrar.

**Independent Test**: aplicar cada filtro por separado y verificar que la tabla, los KPIs y ambos
archivos exportados responden al mismo conjunto filtrado.

**Acceptance Scenarios**:

1. **Given** ventas de varios clientes, **When** se filtra por un cliente y se presiona Buscar,
   **Then** tabla y KPIs muestran sólo ese cliente.
2. **Given** un informe filtrado, **When** se presiona "Exportar Resumen", **Then** se descarga un
   Excel de dos hojas cuyos totales coinciden con los KPIs en pantalla.
3. **Given** un informe filtrado, **When** se presiona "Exportar a PDF", **Then** el PDF se abre en
   el modal compartido de la app, sin abrir una pestaña nueva.
4. **Given** un informe con una Nota de Crédito, **When** se abre la hoja legible del Excel,
   **Then** la celda Resultado de esa fila replica el valor de Contagram (suma, no resta), mientras
   la pantalla sigue mostrando la resta.

---

### User Story 3 - Consultar el Reporte Final del período (Priority: P3)

Como dueño del negocio quiero ver mi resultado del período en dos lecturas —lo devengado (Ventas Vs.
Compras) y lo efectivamente cobrado y pagado (Cobros Vs Pagos)— con el desglose por categoría y por
cuenta de tesorería, y poder simular qué habría pasado sin tal o cual categoría.

**Why this priority**: es el informe de cierre, el más agregado y el que menos se consulta a diario,
pero el que responde la pregunta "¿cómo me fue?". Es independiente del Informe de Ventas.

**Independent Test**: cargar ventas con cobros parciales, compras con pagos, otros ingresos y gastos
(pendientes y pagados), y verificar que cada pestaña arma sus totales según su base (devengado vs
caja) y que el simulador recalcula al vuelo.

**Acceptance Scenarios**:

1. **Given** movimientos del período, **When** se abre Informes → Reporte Final, **Then** se ve la
   pestaña "Ventas Vs. Compras" con Desde/Hasta/Total Ingresos/Total Egresos/Resultado y los bloques
   Ingresos (Ventas, Otros Ingresos) y Egresos (Compras, Gastos) expandibles por categoría.
2. **Given** ventas facturadas pero cobradas sólo en parte, **When** se pasa a "Cobros Vs Pagos",
   **Then** el Total Ingresos es menor que el devengado y cada categoría se abre además por cuenta
   de tesorería.
3. **Given** la pestaña "Ventas Vs. Compras" con la categoría "Online" en $1.893,65, **When** se
   destilda su checkbox "Activo", **Then** Total Ventas, Total Ingresos y Resultado bajan en ese
   monto en el instante del clic, sin recargar ni volver a buscar.
4. **Given** un gasto pendiente de pago, **When** se comparan ambas pestañas, **Then** aparece en
   "Ventas Vs. Compras" y **no** aparece en "Cobros Vs Pagos".
5. **Given** categorías destildadas, **When** se exporta, **Then** el archivo refleja el escenario
   simulado, no el total sin simular.

---

### Edge Cases

- **Período vacío**: los dos informes muestran KPIs/totales en $0,00 y una tabla vacía con mensaje,
  nunca un error ni una división por cero (Venta Promedio con 0 ventas = $0,00).
- **Venta sin categoría / gasto sin categoría o subcategoría**: no se omiten; caen en los rótulos
  explícitos "Sin categoría"/"Sin subcategoría", igual que en la Tanda 1.
- **Cobro o pago sin cuenta de tesorería**: se agrupa bajo "Sin cuenta de tesorería".
- **Ítem sin producto asociado** (concepto libre): aparece con su descripción; Costo Total Actual y
  CMV Total van en 0.
- **Producto cuyo costo cambió después de la venta**: "Costo Total Actual" usa el costo vigente, así
  que puede diferir del CMV; es el mismo comportamiento reproducible que Contagram (§9.2).
- **Nota de Crédito o Débito sin venta asociada**: aporta sus filas al detalle igual, con cliente y
  comprobante propios.
- **Todas las categorías destildadas en el simulador**: Total Ingresos, Total Egresos y Resultado
  quedan en $0,00, sin romper la pantalla; el botón de exportar sigue disponible.
- **Rango que cruza el cierre de un mes o un año**: los agrupamientos y subtotales se calculan sobre
  el rango elegido, no sobre meses calendario.
- **Volumen alto**: el detalle de Ventas se pagina desde el servidor; los KPIs y subtotales se
  calculan siempre sobre **todo el conjunto filtrado**, nunca sobre la página visible.

## Requirements *(mandatory)*

### Requisitos comunes a los dos informes

- **FR-001**: Cada informe DEBE ser un ítem propio del desplegable "Informes" del sidebar, con URL
  real y sin fragmentos `#`.
- **FR-002**: Ambos informes DEBEN ofrecer el selector de rango "Emisión" con las 9 opciones: Hoy,
  Ayer, Última Semana, Mes actual, Mes anterior, Últimos 30 días, Año actual, Desde - Hasta y Borrar
  filtro; con el widget de doble calendario (mes actual + siguiente) y campos de fecha tipeables,
  mostrando simultáneamente la lista de accesos rápidos.
- **FR-003**: El rango por defecto DEBE ser el **mes calendario actual completo** (día 1 al último
  día del mes), incluyendo fechas futuras dentro del mes.
- **FR-004**: Cambiar el rango o aplicar filtros DEBE recalcular KPIs, totales y detalle **sin
  recargar la página**.
- **FR-005**: Ambos informes DEBEN ofrecer exportación a **Excel** y a **PDF**; el PDF DEBE
  visualizarse en el modal compartido de la aplicación, no en una pestaña nueva.
- **FR-006**: Los archivos exportados DEBEN reflejar exactamente el conjunto filtrado (y, en el
  Reporte Final, el escenario simulado) que el usuario ve al momento de exportar.
- **FR-007**: El acceso a ambos informes DEBE estar sujeto al mismo permiso de informes ya vigente
  en la Tanda 1; sin ese permiso, ni la pantalla ni sus endpoints deben responder datos.
- **FR-008**: Los errores y avisos DEBEN mostrarse con las notificaciones toast del template, sin
  alerts nativos ni recargas.
- **FR-009**: Ningún comprobante, cobro, pago, gasto u otro ingreso **dado de baja** debe computar
  en KPIs, detalle, totales ni exports de ninguno de los dos informes.

### Informe de Ventas

- **FR-010**: La pantalla DEBE mostrar los tres bloques de KPIs, con estas fórmulas visibles:
  - Bloque 1: `Total Ventas Creadas + Total Nota de Débito − Total Nota de Crédito = Total Ventas`
  - Bloque 2: `Cantidad Prod./Serv.` · `Cantidad Ventas Creadas` · `Venta Promedio` · `Costo Actual`
  - Bloque 3: `Precio Neto − Costo Mercadería Vendida = Resultado`
- **FR-011**: "Cantidad Prod./Serv." DEBE ser la **suma de las cantidades** de los ítems del período
  (no el número de líneas), coherente con el criterio ya adoptado en el Informe de Compras.
- **FR-012**: "Venta Promedio" DEBE ser `Total Ventas / Cantidad Ventas Creadas`, y valer $0,00
  cuando no hay ventas.
- **FR-013**: "Costo Actual" DEBE ser la suma de `costo vigente del producto × cantidad` de todas
  las líneas del período.
- **FR-014**: "Costo Mercadería Vendida" DEBE ser la suma del **costo promedio ponderado de las
  compras registradas** de cada producto × la cantidad de la línea; los productos sin compras
  registradas aportan 0.
- **FR-015**: La tabla de detalle DEBE tener **una fila por ítem** y estas 12 columnas, en este
  orden: Id · Fecha · Comprobante · Cliente · Prod./Serv. · Cant. · Precio Unitario · Costo Total
  Actual · CMV Total · Precio Total Neto · Result. · Total Comprobante, con scroll horizontal.
- **FR-016**: En pantalla, `Result. = Precio Total Neto − CMV Total` para **todas** las filas,
  incluidas las de Nota de Crédito (que llevan cantidades e importes en negativo).
- **FR-016b**: "Precio Total Neto" DEBE ser el importe **sin impuestos** de la línea (después de
  descuento), y "Total Comprobante" el total **con impuestos** del comprobante, repetido en cada una
  de sus filas.
- **FR-017**: El detalle DEBE paginarse desde el servidor y ordenarse por defecto por fecha de
  emisión descendente y luego por Id descendente, con columnas reordenables por el usuario; los KPIs
  DEBEN calcularse sobre todo el conjunto filtrado, no sobre la página visible.
- **FR-017b**: El filtro "Tipo" DEBE filtrar por tipo de operación (Venta / Nota de Crédito / Nota
  de Débito), distinto del filtro "Tipo y N° de Factura", que refiere al comprobante fiscal.
- **FR-018**: El panel "Filtros" DEBE ofrecer los 19 campos relevados, agrupados en filas:
  1. Id · Producto/Servicio · Tipo de Producto/Servicio · Cliente
  2. Productos · Facturado · Vendedor · Categoría de Venta
  3. Proveedor · Etiqueta · Tipo y N° de Factura · Usuario
  4. Nota Cliente · Nota Interna · Estado del Cobro · Tipo
  5. Remitos · Tipo y N° de Remito · Transportista

  con un botón "Buscar" al final del panel.
- **FR-019**: Los filtros de catálogo (Cliente, Producto/Servicio, Tipo de Producto/Servicio,
  Vendedor, Categoría de Venta, Proveedor, Etiqueta, Usuario, Transportista) DEBEN ser selects con
  buscador; los filtros combinan con AND entre campos y OR dentro de un mismo campo multi-valor.
- **FR-020**: Los botones al pie DEBEN llamarse "Exportar Resumen" y "Exportar a PDF", como en
  Contagram.
- **FR-021**: El Excel DEBE traer **dos hojas**: una legible que reproduce los 3 bloques de KPIs más
  la tabla de detalle con las columnas del export real (Id · Emisión · Cliente · Tipo de Comprobante
  · Producto/Servicio · Cantidad · Precio Unitario · Costo Total Actual · CMV Total · Precio de
  Venta · Resultado · Total Venta), y una hoja plana sin agrupar ni encabezados de sección, con una
  fila por ítem, apta para reprocesar.
- **FR-022**: En la hoja legible del Excel, la celda "Resultado" de las filas de tipo **Nota de
  Crédito** DEBE replicar el comportamiento de origen (R1): `Precio de Venta + CMV Total` en vez de
  la resta. Esta desviación NO debe propagarse a los KPIs ni a ningún total. La hoja plana usa la
  fórmula correcta (`Precio − CMV`) en todas las filas, por ser la hoja destinada a reprocesamiento.

### Reporte Final

- **FR-030**: El Reporte Final DEBE tener dos vistas: **"Ventas Vs. Compras"** (base devengado, por
  defecto) y **"Cobros Vs Pagos"** (base caja), con un bloque de cabecera común: Desde · Hasta ·
  Total Ingresos · Total Egresos · Resultado.
- **FR-031**: Cada vista DEBE mostrar un banner informativo descartable que explique qué contempla:
  - Ventas Vs. Compras: Ventas por Categoría (incluye NC/ND), Otros Ingresos por Categoría, Compras
    por Categoría (incluye NC/ND), Gastos por Categoría y Subcategoría (**incluye Pendientes**).
  - Cobros Vs Pagos: Ventas Cobradas por Categoría, Otros Ingresos (cobros), Compras Pagadas por
    Categoría, Gastos pagados (**los Pendientes NO se contemplan**, por no implicar salida real de
    dinero).
- **FR-032**: En "Ventas Vs. Compras", la jerarquía DEBE ser: `Ingresos → Ventas → Categoría` /
  `Ingresos → Otros Ingresos → Categoría` / `Egresos → Compras → Categoría` /
  `Egresos → Gastos → Categoría → Subcategoría`, con subtotal por bloque (Total Ventas, Total Otros
  Ingresos, Total Compras, Total Gastos) y los totales generales.
- **FR-033**: En "Cobros Vs Pagos", cada categoría DEBE abrirse un nivel más, por **Cuenta de
  Tesorería**: `… → Categoría → Cuenta de Tesorería → monto`, y en Gastos
  `… → Categoría → Subcategoría → Cuenta de Tesorería → monto`.
- **FR-034**: Cada categoría DEBE llevar una columna "Activo" con un checkbox tildado por defecto;
  destildarlo DEBE excluir su monto del subtotal de su bloque, del Total Ingresos o Total Egresos y
  del Resultado **en el instante del clic**, sin recargar ni volver a consultar el servidor, y sin
  alterar los datos reales.
- **FR-035**: En pantalla, ambas vistas DEBEN mostrar Total Egresos en **positivo** y
  `Resultado = Total Ingresos − Total Egresos`.
- **FR-036**: En el Excel exportado DEBE replicarse el doble estándar de signos de origen (R2):
  - hoja "Ventas Vs. Compras": Total Egresos y los montos de Compras y Gastos en **negativo**,
    `Resultado = Total Ingresos + Total Egresos`;
  - hoja "Cobros Vs Pagos": Total Egresos en **positivo**, `Resultado = Total Ingresos − Total
    Egresos`, con los subtotales por bloque ("Total Compras Pagadas", "Total Gastos") en negativo y
    cada línea individual por cuenta de tesorería en positivo.
- **FR-037**: El Excel DEBE incluir, además de la hoja legible de la vista exportada, una hoja plana
  con una fila por combinación (vista · bloque · categoría · subcategoría · cuenta de tesorería ·
  monto), con signos unificados en positivo más una columna que indique si es ingreso o egreso.
- **FR-037b**: En "Cobros Vs Pagos", cada cobro y cada pago DEBE imputarse al período por **su
  propia fecha** (la del cobro o pago), no por la del comprobante de origen, y agruparse por la
  categoría de la venta o compra que lo origina.
- **FR-038**: Las cuentas de tesorería **visibles** DEBEN listarse aunque su monto sea $0,00 dentro
  de una categoría con actividad, como hace Contagram. Una cuenta no visible con movimientos en el
  período se lista igual (no se oculta dinero); una cuenta no visible sin movimientos no aparece.
- **FR-039**: El Excel DEBE completar siempre las celdas Desde y Hasta en las dos vistas.

### Fuera de alcance

- **FR-040**: NO se construyen las pestañas "Rankings" ni "Arma tu Informe" (tablas dinámicas /
  PivotTable.js), ni la vista consolidada de gráficos `/graphs`, ni el guardado de vistas
  personalizadas como pestañas persistentes. Quedan documentadas como pendientes para una tanda
  futura.
- **FR-041**: NO se construye la landing de tarjetas `/reports`.

### Key Entities

- **Venta**: comprobante de ingreso con cliente, categoría de venta, vendedor, fecha de emisión,
  tipo y número de comprobante, y sus ítems.
- **Ítem de venta**: línea con producto o descripción libre, cantidad, precio unitario, descuento e
  IVA; unidad de fila del detalle.
- **Nota de Crédito / Débito**: ajuste sobre una venta, con su propio comprobante y fecha; las NC
  aportan filas en negativo.
- **Cobro**: dinero efectivamente ingresado por una venta, con fecha y cuenta de tesorería; base de
  la vista "Cobros Vs Pagos".
- **Pago**: dinero efectivamente egresado por una compra, con fecha y cuenta de tesorería.
- **Otro Ingreso**: ingreso no proveniente de ventas, con categoría y cuenta de tesorería.
- **Gasto**: egreso con categoría, subcategoría (categoría con padre), cuenta de tesorería y marca
  de pendiente.
- **Categoría**: clasificador jerárquico (padre/hijo) tipado por venta, compra, gasto o ingreso.
- **Cuenta de Tesorería**: caja, banco o medio por el que entra o sale el dinero.
- **Producto**: aporta el costo vigente (para "Costo Actual") y el vínculo con sus compras (para el
  CMV).

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Un usuario puede obtener el total de ventas de cualquier período en **menos de 3
  interacciones** desde el sidebar (abrir Informes → Ventas → elegir rango).
- **SC-002**: El informe presenta resultados de un período de un año con miles de líneas en **menos
  de 3 segundos**, con el detalle paginado.
- **SC-003**: El 100% de los KPIs y subtotales se calcula sobre el conjunto filtrado completo: al
  navegar entre páginas del detalle, ningún total cambia.
- **SC-004**: Los totales de los archivos exportados coinciden **al centavo** con lo mostrado en
  pantalla, salvo las dos desviaciones documentadas (R1 y R2), que coinciden al centavo con lo que
  exporta Contagram.
- **SC-005**: El simulador del Reporte Final actualiza Ingresos, Egresos y Resultado de forma
  **inmediata y perceptible** al destildar una categoría, sin ninguna espera de red.
- **SC-006**: Las dos lecturas del Reporte Final (devengado y caja) sobre el mismo período difieren
  exactamente en lo no cobrado / no pagado, verificable sumando cobros y pagos del período.
- **SC-007**: Un período sin movimientos se resuelve mostrando ceros y mensajes de vacío, con **cero
  errores** en pantalla o en los exports.

## Assumptions

- Se reutiliza toda la infraestructura ya construida en la Tanda 1 (spec 067): selector de rango de
  emisión, patrón de exportación a Excel de doble hoja, plantillas y estilos de PDF, y el permiso de
  acceso a informes. No se rediseña ninguno de esos elementos.
- El CRM ya tiene cargados y en uso Ventas, Notas de Crédito/Débito, Cobros, Pagos, Otros Ingresos,
  Gastos, Categorías (con jerarquía padre/hijo) y Cuentas de Tesorería; esta feature **sólo lee**
  esos datos, no crea ni modifica ninguno.
- El CRM no guarda el costo histórico por movimiento de stock, por lo que el CMV se deriva de las
  compras registradas del producto (ver Clarifications).
- "Facturado", "Estado del Cobro" y "Remitos" como filtros se resuelven con los estados ya
  existentes en los módulos de Ventas, Cobranzas y Remitos; no se introducen estados nuevos.
- Las tres columnas de filtro no identificadas (19 relevadas vs. 22 declaradas) quedan como brecha
  documentada, no como requisito de esta feature.
- Los informes son de **sólo lectura**: no se editan ni eliminan comprobantes desde estas pantallas.
