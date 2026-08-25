# Feature Specification: Exportar Movimientos de Cuenta Corriente (Clientes y Proveedores)

**Feature Branch**: `080-export-movimientos-cta-cte`

**Created**: 2026-08-25

**Status**: Draft

**Input**: User description: "Agregar a los informes de Cuenta Corriente Clientes y Cuenta Corriente Proveedores (pestaña Movimientos de cada uno) los botones 'Exportar' (Excel) y 'Exportar a PDF', calcados de Contagram real."

## Clarifications

### Session 2026-08-25

Sin preguntas al usuario en este paso (regla del proyecto: las preguntas se hacen todas al principio,
antes de `/speckit-specify`, y acá no surgió ninguna ambigüedad que bloqueara realmente). Único ajuste
autónomo: se fijó el tope de filas del PDF en 500 (FR-011), tomando la convención ya existente en la
app (`InformeComprasController::TOPE_FILAS_PDF`) en vez de dejarlo abierto.

## Contexto y fuente de verdad

Se relevó contra 4 archivos reales exportados desde Contagram (regla de fidelidad estructural, CLAUDE.md):

- `Informe Cuentas Corrientes Movimientos de Clientes 25-08-2026.pdf`
- `Informe Cuentas Corrientes Movimientos de Proveedores 25-08-2026.pdf`
- `Informe Cuentas Corrientes Movimientos de Clientes 24-08-2026 2159 Hs.xlsx`
- `Informe Cuentas Corrientes Movimientos de Proveedores 24-08-2026 2159 Hs.xlsx`

Este feature es el mismo patrón de botones que ya se construyó para la pestaña **Saldos** de ambos
informes (spec previa, sin número propio — commit "Cta Cte Clientes: exportar Saldos a Excel/PDF
calcados de Contagram"), pero para la pestaña **Movimientos**. El PDF de Movimientos es simple y
calca directamente el patrón de Saldos. El Excel de Movimientos es sustancialmente más grande: 33-34
columnas con desglose impositivo completo por comprobante, que reutiliza la infraestructura fiscal ya
construida para el Libro IVA del Contador (spec 077: `DesgloseImpositivoVenta`/`DesgloseImpositivoCompra`,
`LibroIvaVentasQuery`/`LibroIvaComprasQuery`).

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Exportar a PDF los Movimientos filtrados (Priority: P1)

Un usuario está en la pestaña "Movimientos" de Cuenta Corriente Clientes (o Proveedores), ya filtró
por cliente/proveedor, operación y rango de fechas, y quiere un PDF imprimible de esa lista para
mandarlo o archivarlo, igual que ya puede hacerlo con la pestaña Saldos.

**Why this priority**: Es el caso de uso más frecuente (imprimir/enviar un resumen de movimientos) y
el de menor riesgo técnico — reutiliza exactamente las 11 columnas que la pantalla ya muestra hoy.

**Independent Test**: Aplicar un filtro de fechas en Movimientos Clientes, clickear "Exportar a PDF",
y verificar que el PDF resultante tiene las mismas filas/columnas que la tabla en pantalla, con el
layout calcado del PDF real de Contagram.

**Acceptance Scenarios**:

1. **Given** la pestaña Movimientos de Cuenta Corriente Clientes con un filtro de fechas aplicado,
   **When** el usuario clickea "Exportar a PDF", **Then** se abre/descarga un PDF apaisado titulado
   "Informe - Movimientos de Clientes", con las columnas Id, Emisión, Cliente, Operación, Categoría,
   Total Venta, Cobrado, A Cobrar, N° de Comprobante, Medio de Cobro, Descripción — mismos datos que
   la tabla en pantalla con esos filtros, paginado con encabezado repetido y pie "Pág. X / Y".
2. **Given** la pestaña Movimientos de Cuenta Corriente Proveedores, **When** el usuario clickea
   "Exportar a PDF", **Then** el PDF resultante es el espejo con Proveedor/Total Compra/Pagado/A
   Pagar/Medio de Pago, título "Informe - Movimientos de Proveedores".
3. **Given** un filtro que no devuelve movimientos, **When** el usuario exporta, **Then** el PDF se
   genera igual, con la tabla vacía (sin error).

---

### User Story 2 - Exportar a Excel los Movimientos con desglose fiscal completo (Priority: P1)

El mismo usuario necesita el Excel con el detalle impositivo completo (neto gravado/no gravado/exento,
IVA por alícuota, percepciones, CUIT, tipo y punto de venta del comprobante, etc.) para pasárselo al
contador o cruzarlo con otra planilla — el mismo nivel de detalle que ya baja de Contagram hoy.

**Why this priority**: Junto con US1 son la funcionalidad mínima pedida ("los botones... calcados de
Contagram"); sin el Excel completo el botón "Exportar" no cumple su propósito real (el PDF por sí solo
ya lo cubre un botón, pero el usuario pidió ambos).

**Independent Test**: Exportar a Excel desde Movimientos Clientes con un rango de fechas que incluya
ventas con IVA a distintas alícuotas y sus cobros; abrir el .xlsx y verificar que las 34 columnas están
presentes con los valores fiscales correctos por fila, y que las filas de Cobro dejan en blanco/0 las
columnas fiscales.

**Acceptance Scenarios**:

1. **Given** la pestaña Movimientos de Cuenta Corriente Clientes con un rango de fechas filtrado,
   **When** el usuario clickea "Exportar", **Then** se descarga un .xlsx de una sola hoja "Movimientos
   de Clientes" con 34 columnas (Id, Emisión, Cliente, CUIT, Operación, Categoría, Medio de Cobro,
   Descripción, Tipo de Comprobante, Punto de Venta, N° de Comprobante, Aplicada en N° de Factura,
   Fecha Factura Aplicada, Id Venta, Vendedor, Subtotal sin Descuento, Descuento en $, Subtotal con
   Descuento, Importe Neto No Gravado, Importe Neto Gravado, IVA - 2,5%/5%/10,5%/21%/27%, Exento, No
   Gravado, Perc. IVA, Perc. IIBB, Imp. Internos, Imp. Municipales, Total Venta, Cobrado, A cobrar),
   una fila por Venta y una fila por Cobro (más NC/ND si las hay en el rango).
2. **Given** la misma pantalla del lado Proveedores, **When** se exporta, **Then** el .xlsx tiene 33
   columnas (igual que Clientes pero sin "Vendedor" y con "Sellos" agregada antes de "Total Compra").
3. **Given** una fila de tipo Venta/Compra con ítems a alícuota 21% y otra a 10,5%, **When** se
   exporta, **Then** los importes de neto gravado e IVA aparecen desglosados en las columnas de
   alícuota correspondientes, calculados con la misma lógica que ya usa el Libro IVA del Contador
   (spec 077) — no una reimplementación distinta.
4. **Given** una fila de tipo Cobro/Pago, **When** se exporta, **Then** las columnas fiscales
   (Subtotal.../Neto.../IVA.../Perc.../Imp...) van en blanco o 0, y sólo se completan Medio de
   Cobro/Pago, Descripción, N° de Comprobante (el del recibo/orden de pago), Id Venta/Compra
   (la operación a la que pertenece) y, si aplica, Vendedor.

---

### Edge Cases

- ¿Qué pasa si el rango de fechas filtrado no tiene movimientos? El PDF/Excel se genera vacío (con
  encabezados), sin error — mismo criterio que Saldos.
- ¿Qué pasa con una NC/ND dentro del rango filtrado? Aparece como fila propia (operación "Nota de
  Crédito"/"Nota de Débito"), con su propio desglose fiscal (mismas 4 ramas de precedencia que ya usa
  el Libro IVA del Contador para NC/ND — FR-022d de spec 077), igual que ya hace la tabla en pantalla.
- ¿Y un Saldo Inicial dentro del rango? Aparece como hoy en pantalla (fila "Saldo Inicial"), con todas
  las columnas fiscales/de comprobante en blanco.
- Un comprobante sin CUIT cargado, sin Vendedor asignado, o sin Categoría: la celda va vacía, no se
  inventa un valor.
- El PDF de Movimientos, a diferencia del de Saldos, no trae un tope de filas documentado en los
  archivos reales relevados (Contagram lo pagina libremente); ver FR-011 sobre el tope elegido acá.

## Requirements *(mandatory)*

### Functional Requirements

**PDF (US1)**

- **FR-001**: El sistema DEBE agregar un botón "Exportar a PDF" en la pestaña Movimientos de Cuenta
  Corriente Clientes y otro igual en la de Proveedores, en la esquina inferior derecha de la tabla
  (mismo lugar que en Saldos).
- **FR-002**: El PDF DEBE respetar los filtros activos de la pestaña Movimientos (cliente/proveedor,
  operación, rango de fechas) al momento del clic — igual criterio que el export de Saldos respeta el
  filtro de esa pestaña.
- **FR-003**: El PDF DEBE ser apaisado (landscape), con las 11 columnas que ya muestra la tabla en
  pantalla, título "Informe - Movimientos de Clientes" / "Informe - Movimientos de Proveedores",
  encabezado de tabla en el mismo estilo (fondo teal) que el PDF de Saldos, repetido en cada página, y
  pie "Pág. X / Y" — sin logo de empresa ni línea de metadata de filtros (mismo criterio que Saldos).
- **FR-004**: La columna "N° de Comprobante" del PDF DEBE mostrar el número tal como lo muestra hoy la
  pantalla (comprobante fiscal aprobado si existe, si no el cargado a mano, "-" si no hay ninguno) —
  no se introduce un formato nuevo.
- **FR-005**: El sistema NO DEBE reproducir el resaltado de color (verde/rojo) que aparece en una sola
  fila de los PDF reales relevados: se identificó como un artefacto puntual de la captura (probable
  fila con foco/hover activo al momento de generar ese PDF en Contagram), no un patrón de diseño
  consistente en el resto de filas con el mismo signo.

**Excel (US2)**

- **FR-006**: El sistema DEBE agregar un botón "Exportar" (Excel) junto al de PDF, en ambas pestañas
  Movimientos, que descargue un `.xlsx` de una sola hoja titulada "Movimientos de Clientes" /
  "Movimientos de Proveedores", respetando los mismos filtros activos que el PDF (FR-002).
- **FR-007**: El Excel de Clientes DEBE tener exactamente estas 34 columnas, en este orden: Id,
  Emisión, Cliente, CUIT, Operación, Categoría, Medio de Cobro, Descripción, Tipo de Comprobante,
  Punto de Venta, N° de Comprobante, Aplicada en N° de Factura, Fecha Factura Aplicada, Id Venta,
  Vendedor, Subtotal sin Descuento, Descuento en $, Subtotal con Descuento, Importe Neto No Gravado,
  Importe Neto Gravado, IVA - 2,5%, IVA - 5%, IVA - 10,5%, IVA - 21%, IVA - 27%, Exento, No Gravado,
  Perc. IVA, Perc. IIBB, Imp. Internos, Imp. Municipales, Total Venta, Cobrado, A cobrar.
- **FR-008**: El Excel de Proveedores DEBE tener las mismas 33 columnas que Clientes, sin "Vendedor",
  y con "Sellos" agregada inmediatamente antes de "Total Compra"; el resto de los nombres cambia
  Cliente→Proveedor, Cobrado→Pagado, A cobrar→A pagar, Medio de Cobro→Medio de Pago, Total Venta→Total
  Compra, Id Venta→Id Compra.
- **FR-009**: El desglose impositivo por alícuota (Importe Neto No Gravado/Gravado, IVA por alícuota,
  Exento, No Gravado, Perc. IVA, Perc. IIBB, Imp. Internos) de las filas de Venta/Compra y de NC/ND
  DEBE calcularse reutilizando `DesgloseImpositivoVenta`/`DesgloseImpositivoCompra` y la lógica de
  `LibroIvaVentasQuery`/`LibroIvaComprasQuery` (spec 077) — no reimplementar la clasificación fiscal.
  El filtro de período de esta pantalla es por rango de fechas libre (`fecha_desde`/`fecha_hasta`), a
  diferencia del Libro IVA que filtra por mes/año calendario; la lógica fiscal se reutiliza igual, sólo
  cambia cómo se acota el rango.
- **FR-010**: Las filas de Cobro/Pago DEBEN dejar en blanco (no en 0, para diferenciarlas de un
  importe fiscal real de $0) las columnas fiscales (Subtotal.../Importe Neto.../IVA - */Exento/No
  Gravado/Perc. */Imp. */Vendedor/Sellos), y completar únicamente: Medio de Cobro/Pago, Descripción,
  Tipo de Comprobante y N° de Comprobante (los del recibo/orden de pago, no los de la venta/compra),
  Id Venta/Compra (la operación que cancela, total o parcialmente), y Cobrado/Pagado.
- **FR-011**: El PDF (US1) DEBE tener un tope de **500 filas** (mismo valor ya usado como
  `InformeComprasController::TOPE_FILAS_PDF` en el resto de los informes de la app — convención
  interna existente, no un número nuevo a decidir), con el mismo aviso ya usado en esos otros
  informes ("el detalle se cortó en las primeras 500 filas, para el listado íntegro usá 'Exportar'")
  — no se documentó un tope explícito en los PDF reales relevados porque las muestras eran chicas.
- **FR-012**: "Aplicada en N° de Factura" y "Fecha Factura Aplicada" DEBEN ir vacías en todas las filas
  de este export: el modelo de datos actual no soporta que un Cobro/Pago se aplique a un comprobante
  distinto del que cancela (siempre es 1 a 1 vía `venta_id`/`compra_id`), a diferencia de Contagram que
  sí permite esa distinción — documentado como brecha conocida, no se implementa el feature de
  "aplicar cobro a otra factura" en este alcance.
- **FR-013**: El Excel DEBE tener el mismo formato de header (fondo oscuro, letra blanca en negrita,
  autosize de columnas) que ya usan el resto de los exports de informes de la app (`HojaInforme`), no
  el estilo del archivo original de Contagram (fondo azul `#0E5DA1`) — consistencia visual interna del
  CRM por sobre el calco exacto de color, mismo criterio ya aplicado en el export de Saldos.
- **FR-014**: El archivo `.xlsx` descargado DEBE nombrarse `Informe Cuentas Corrientes Movimientos
  de Clientes {fecha} {hora} Hs.xlsx` / `... de Proveedores ...` (mismo patrón de nombre que ya usa
  el export de Saldos).
- **FR-015**: Los totales agregados (neto no gravado/gravado/exento, IVA por alícuota, percepciones)
  del rango exportado DEBEN coincidir exactamente con los que muestra el Libro IVA del Contador (spec
  077) para ese mismo rango de fechas cuando coincide con un mes calendario completo — verificable con
  un test que compare ambos exports sobre el mismo período.
- **FR-016**: Las filas de NC/ND en el export DEBEN dejar en blanco las mismas columnas que una fila
  de Cobro/Pago (Subtotal sin/con Descuento, Descuento en $, Medio de Cobro/Pago, Vendedor, Id
  Venta/Compra) — sólo completan Id, Emisión, Cliente/Proveedor, CUIT, Operación, Categoría (la de la
  venta/compra que ajustan), N° de Comprobante propio, Descripción, y su desglose fiscal con signo
  (crédito resta, débito suma), igual que ya hace `LibroIvaVentasQuery`/`ComprasQuery`.
- **FR-017**: La columna "Sellos" del export de Proveedores DEBE ir siempre en 0: no hay un concepto
  de Sellos modelado hoy en `compra_conceptos` — se agrega la columna por fidelidad estructural con el
  archivo real, sin inventar un cálculo que el negocio no relevó.

### Key Entities

- **Fila de Movimiento (export)**: una Venta/Compra, un Cobro/Pago, o una NC/ND dentro del rango
  filtrado — mismas fuentes que ya arma `CuentaCorrienteController::queryMovimientos()` /
  `CuentaCorrienteProveedorController::queryMovimientos()`, enriquecida con las columnas fiscales de
  `LibroIvaVentasQuery`/`LibroIvaComprasQuery` para las filas de Venta/Compra y NC/ND.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Un usuario puede exportar a PDF los Movimientos de Clientes o Proveedores filtrados, con
  el mismo resultado visual (columnas, orden, título, paginación) que el PDF real de Contagram, en
  menos de 5 segundos.
- **SC-002**: Un usuario puede exportar a Excel los Movimientos de Clientes o Proveedores filtrados,
  con las 33/34 columnas reales y los importes fiscales verificables contra el Libro IVA del Contador
  para el mismo período (mismos totales agregados).
- **SC-003**: El 100% de las filas de Cobro/Pago en el Excel exportado tienen sus columnas fiscales en
  blanco (no en 0), evitando que alguien las confunda con comprobantes de $0.

## Assumptions

- **"A cobrar"/"A pagar" = saldo por comprobante, no un acumulado global.** Al inspeccionar el Excel y
  el PDF reales fila por fila se observó que esa columna repite el mismo valor en varias filas de
  Cobro de **clientes distintos** (ej. `54140.13` en 5 cobros seguidos de 5 clientes diferentes, después
  salta a `-24191.66` y se repite en el siguiente bloque) — compatible con un acumulado corrido de todo
  el listado filtrado (sin importar el cliente), no con el saldo de ese comprobante puntual. Replicar
  ese comportamiento exacto sería frágil (depende del orden/página mostrada) y divergiría de lo que
  ya calcula correctamente `queryMovimientos()` hoy en pantalla (el saldo pendiente de esa venta/compra
  puntual, que es lo que un usuario necesita para saber "cuánto falta cobrar de esta factura"). Este
  export usa el mismo cálculo por comprobante que ya está en pantalla, no el acumulado observado en los
  archivos reales — se documenta la discrepancia en vez de copiarla en silencio.
- El desglose fiscal por comprobante se calcula reutilizando la infraestructura de la spec 077 (Libro
  IVA), adaptada a filtrado por rango de fechas en vez de mes/año — no se reimplementa el cálculo.
- El resaltado de color visto en una fila puntual de los PDF reales relevados es un artefacto de
  captura, no un patrón de diseño a replicar (FR-005).
- "Aplicada en N° de Factura" / "Fecha Factura Aplicada" quedan vacías por brecha de modelo de datos
  (FR-012) — no se agrega la capacidad de aplicar un cobro/pago a una factura distinta de la propia en
  este alcance.
- El Excel de Movimientos exportado NO trae límite de filas (a diferencia del PDF, FR-011): es el
  mismo criterio que ya usan los demás exports de Excel de la app (Compras, Gastos, Libro IVA).
- Igual que en Saldos, el estilo visual del Excel (colores de header) sigue la convención interna del
  CRM (`HojaInforme`) en vez de calcar el color azul original de Contagram.
