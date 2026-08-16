# Feature Specification: Módulo Informes — Tanda 1 (Compras, Gastos, Cta Cte Proveedores)

**Feature Branch**: `067-informes-compras-gastos-ctacte-proveedores`

**Created**: 2026-08-14

**Status**: Draft

**Input**: User description: "Módulo Informes — Tanda 1: Informe de Compras, Informe de Gastos e Informe de Cuenta Corriente Proveedores. Fuente de verdad estructural: docs/Informe-Modulo-Informes-2026-08-14/. Cada informe es un ítem propio del desplegable Informes del sidebar (sin landing de tarjetas). Exposición en pantalla del desglose impositivo AFIP de Compras, export dual Excel (doble hoja) + PDF, Cta Cte Proveedores como espejo de sólo lectura de Cta Cte Clientes."

## Contexto y fuente de verdad

Contagram agrupa sus informes en una landing `/reports` con **8 tarjetas**. De esos 8, esta tanda
cubre **3**: Compras, Gastos y Cuenta Corriente Proveedores — los únicos que no dependen de ninguna
decisión de negocio todavía abierta.

**Divergencia estructural deliberada y ya decidida**: en este CRM **no se construye la landing de
tarjetas**. Cada informe es un ítem propio del desplegable "Informes" del sidebar, con su propia URL
real (sin fragmentos `#`), siguiendo el patrón ya vigente con "Informes > Stock" (spec 003) e
"Informes > Cuenta Corriente" (spec 029). Motivo: nuestro sidebar despliega submenús y el de
Contagram no, por lo que la landing sería un salto de navegación sin valor.

**Relevamiento base**: `docs/Informe-Modulo-Informes-2026-08-14/informe_modulo_informes_texto.md` y
sus 30 capturas (`Capturas/`), en particular:

| Captura | Cubre |
|---------|-------|
| `16_17_compras_vista_y_filtros_12campos.gif` | Informe de Compras: vista de detalle + panel de 12 filtros |
| `26_informe_de_gastos.gif` | Informe de Gastos: jerarquía Categoría→Subcategoría con subtotales |
| `22_cta_cte_proveedores_saldos.gif` | Cta Cte Proveedores: tab Saldos con los 5 buckets de aging |
| `19_20_21_cta_cte_clientes_saldos_modal_movimientos.gif` | Modal de ficha y tab Movimientos (espejo a replicar) |
| `04_ventas_emision_desde_hasta_calendario.gif` | Selector "Emisión": 9 opciones + widget de doble calendario |

## Clarifications

### Session 2026-08-14

Las tres decisiones de negocio de fondo (exponer el desglose impositivo en pantalla, doble hoja de
Excel en los tres informes, Cuenta Corriente Proveedores de sólo lectura) las tomó el usuario antes
de especificar y ya están incorporadas como requisitos firmes. Las siguientes son ambigüedades
residuales resueltas con criterio propio durante la cadena, sin interrumpir al usuario (regla
"cadena completa sin preguntar" de CLAUDE.md); cada una queda registrada acá para que sea revisable.

- Q: ¿Cuál es la unidad de fila de la tabla de detalle del Informe de Compras? → A: **una fila por
  ítem de compra** (no por compra). Es lo que muestra el relevamiento: las columnas
  Producto/Servicio, Cant. y Precio son de línea, mientras "Total Comprobante" se repite en cada
  fila de la misma compra. Las Notas de Crédito y Débito aportan sus propias filas con el mismo
  criterio.
- Q: ¿Qué cuenta exactamente el KPI "Cantidad Prod./Serv."? → A: **la suma de las cantidades** de
  todos los ítems del período, no el número de líneas. Coincide con los datos del relevamiento (3
  compras → 32 productos/servicios) y es la lectura de negocio útil ("cuántas unidades compré").
- Q: Los conceptos de compra tipifican percepciones sin distinguir IVA de Ingresos Brutos. ¿Cómo se
  pueblan las columnas "Perc. IVA" y "Perc. IIBB"? → A: **tres columnas** — Perc. IVA, Perc. IIBB y
  Otras Percepciones — clasificando por el texto del concepto; lo que no se pueda clasificar cae en
  Otras Percepciones, nunca se descarta ni se suma a la columna equivocada.
- Q: El Informe de Gastos es jerárquico, pero la regla obligatoria del proyecto exige tablas
  paginadas desde el servidor. ¿Cómo se concilian? → A: **una única tabla paginada desde el servidor
  con agrupación por filas** (Categoría → Subcategoría) y filas de subtotal, en vez de una tabla
  independiente por subcategoría. Mantiene la lectura jerárquica del relevamiento sin romper la
  paginación real.
- Q: ¿Con qué rango se abre cada informe por primera vez? → A: **Mes actual**, entendido como mes
  calendario completo (FR-005), en los tres informes. Es el default de Contagram y el período que
  más se consulta.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Analizar las compras del período con su desglose impositivo (Priority: P1)

El responsable del negocio necesita saber cuánto compró en un período, a qué proveedores, de qué
categorías, y con qué composición impositiva (IVA discriminado por alícuota, percepciones, impuestos
internos), para controlar el gasto de mercadería y para pasarle el detalle al contador.

**Why this priority**: es el informe más rico de la tanda y el que hoy no tiene ningún sustituto —
sin él, responder "cuánto compré este mes y con qué IVA" obliga a recorrer las compras de a una.

**Independent Test**: se carga un conjunto de compras con distintas alícuotas de IVA, categorías y
proveedores; se entra al informe, se elige un rango, y se verifica que los KPIs, la tabla de detalle
y las columnas impositivas activables reflejen exactamente esas compras.

**Acceptance Scenarios**:

1. **Given** compras registradas en el período, **When** el usuario abre el Informe de Compras,
   **Then** ve el bloque de KPIs con la ecuación Total Compras Creadas + Total Nota de Débito −
   Total Nota de Crédito = Total Compras, más Cantidad Prod./Serv., Cantidad Compras Creadas, Compra
   Promedio y Costo Actual, todos calculados sobre el rango vigente.
2. **Given** el informe abierto, **When** el usuario cambia el selector "Emisión" a otra de las 9
   opciones, **Then** KPIs y tabla se recalculan sin recargar la página.
3. **Given** el informe abierto, **When** el usuario abre el selector de columnas y activa las
   columnas impositivas (IVA por alícuota, Perc. IVA, Perc. IIBB, Imp. Internos, netos), **Then**
   esas columnas aparecen en la tabla en pantalla, no sólo en la exportación.
4. **Given** una compra con Nota de Crédito asociada, **When** se la ve en el informe, **Then** el
   Total Comprobante de la línea de NC es negativo y coincide con el total de la NC, y el KPI Total
   Compras la resta una sola vez.
5. **Given** cualquier combinación de los 12 filtros, **When** el usuario presiona "Buscar",
   **Then** la tabla y los KPIs se restringen a las compras que cumplen todos los filtros (AND entre
   campos, OR dentro de un campo de selección múltiple).

---

### User Story 2 - Ver en qué se está gastando el dinero, por categoría (Priority: P2)

El responsable necesita ver el total de gastos de un período desagregado por Categoría y
Subcategoría, con el detalle de cada gasto y el subtotal de cada agrupación.

**Why this priority**: es el informe más simple de construir y responde una pregunta de negocio
directa ("¿en qué se me va la plata?"), pero su impacto es menor que el de Compras porque los gastos
son un volumen chico frente a la mercadería.

**Independent Test**: se cargan gastos en varias categorías y subcategorías; se abre el informe y se
verifica el Gasto Total, la jerarquía de dos niveles, los subtotales y el detalle expandible.

**Acceptance Scenarios**:

1. **Given** gastos cargados en el período, **When** el usuario abre el Informe de Gastos, **Then**
   ve el bloque Desde / Hasta / Gasto Total y la lista de Categorías con su subtotal.
2. **Given** una Categoría listada, **When** el usuario la expande, **Then** ve sus Subcategorías, y
   al expandir una Subcategoría ve la tabla de detalle (Id, Fecha, Descripción, Medio de Pago,
   Total) y el subtotal de esa Subcategoría.
3. **Given** gastos de una categoría sin subcategoría asignada, **When** se muestra el informe,
   **Then** esos gastos aparecen igual bajo su Categoría, sin desaparecer del total.
4. **Given** el filtro "Estado del Pago" en "Pendiente", **When** se aplica, **Then** el Gasto Total
   y el detalle sólo contemplan gastos marcados como pendientes.
5. **Given** la suma de los subtotales de todas las Categorías, **When** se la compara con el KPI
   Gasto Total, **Then** ambos coinciden exactamente.

---

### User Story 3 - Saber cuánto se le debe a cada proveedor y desde cuándo (Priority: P2)

El responsable necesita ver el saldo pendiente con cada proveedor, clasificado por antigüedad de la
deuda, y poder abrir el detalle de movimientos de un proveedor puntual.

**Why this priority**: cierra una brecha explícita que el spec 029 dejó abierta (FR-010: proveedores
fuera de alcance) y que hoy aparece como "Próximamente" deshabilitado en el menú de fila de Compras.
Es la contracara de una pantalla que ya funciona para clientes, con el motor de cálculo ya escrito.

**Independent Test**: se cargan compras con distintos vencimientos y pagos parciales; se abre el
informe y se verifica que cada proveedor caiga en el bucket de antigüedad correcto y que el Total
por proveedor coincida con la suma de "A Pagar" de sus movimientos.

**Acceptance Scenarios**:

1. **Given** proveedores con saldo pendiente, **When** el usuario abre el tab "Saldos Proveedores",
   **Then** ve una fila por proveedor con A Vencer, Vencido 0-30, 31-60, 61-90, >90 y Total, y puede
   ordenar por Total.
2. **Given** un proveedor con una Nota de Crédito mayor a su deuda, **When** se muestra el informe,
   **Then** su fila aparece con Total negativo (saldo a favor), no se oculta.
3. **Given** la lista de saldos, **When** el usuario hace clic sobre el nombre de un proveedor,
   **Then** se abre un modal de sólo lectura con su ficha de contacto.
4. **Given** el tab "Movimientos", **When** el usuario filtra por un proveedor y una Operación,
   **Then** ve las filas de Compra / Pago / Nota de Crédito / Nota de Débito / Saldo Inicial de ese
   proveedor con las columnas Id, Emisión, Proveedor, Operación, Categoría, Total Compra, Pagado, A
   Pagar, N° de Comprobante, Medio de Pago y Descripción, con celdas vacías donde no aplican.
5. **Given** un proveedor cualquiera, **When** se suma la columna "A Pagar" de sus filas de Compra
   más su fila sintética de Saldo Inicial, **Then** el resultado coincide con su Total en el tab
   "Saldos Proveedores".

---

### User Story 4 - Llevarse el informe afuera (Excel y PDF) (Priority: P3)

El responsable necesita entregarle un informe al contador o guardarlo, tanto en un formato legible
para imprimir como en uno plano para reprocesar en otra herramienta.

**Why this priority**: agrega valor real pero ninguno de los tres informes queda inutilizable sin
exportación; es la capa que se puede entregar al final sin bloquear las otras tres historias.

**Independent Test**: se exporta cada informe con filtros aplicados y se verifica que el archivo
contenga exactamente los mismos registros y totales que la pantalla.

**Acceptance Scenarios**:

1. **Given** un informe con filtros aplicados, **When** el usuario exporta a Excel, **Then** el
   archivo contiene dos hojas: una formateada (jerárquica, con subtotales y encabezados de sección)
   y una plana con una fila por registro y una columna por campo.
2. **Given** el mismo informe, **When** el usuario exporta a PDF, **Then** el documento se abre
   dentro del modal de PDF compartido de la aplicación, sin abrir una pestaña nueva.
3. **Given** cualquier exportación, **When** se comparan sus totales con los KPIs de la pantalla,
   **Then** coinciden exactamente.
4. **Given** el Informe de Compras exportado, **When** se revisan sus columnas, **Then** incluye el
   desglose impositivo completo aunque esas columnas estuvieran desactivadas en pantalla.

---

### Edge Cases

- **Período sin datos**: cada informe muestra un estado vacío explícito con los KPIs en cero, no una
  tabla rota ni un error.
- **Compra sin ítems de producto** (sólo conceptos como percepciones o intereses): aparece en el
  informe con Cantidad Prod./Serv. cero, sin desaparecer del Total Compras.
- **Producto borrado (soft delete) referenciado por una compra vieja**: la línea sigue mostrando la
  descripción histórica guardada en el ítem, no una celda vacía.
- **Compra sin categoría o gasto sin subcategoría**: se agrupan bajo un rótulo explícito de "Sin
  categoría" / "Sin subcategoría" en vez de omitirse.
- **Ítem de compra con cantidad negativa** (bonificación del proveedor, spec 058): resta en el
  informe con su signo, sin romper el conteo de Cantidad Prod./Serv.
- **Compra con ítems de varias alícuotas de IVA**: cada alícuota aporta a su propia columna; la suma
  de las columnas de IVA más los netos reconstruye el Total Comprobante.
- **Proveedor con saldo exactamente cero**: no aparece en Saldos Proveedores (mismo criterio de
  tolerancia que la pantalla de clientes).
- **Proveedor con saldo inicial cargado pero sin ninguna compra**: aparece igual en Saldos, con su
  fila sintética de Saldo Inicial en Movimientos.
- **Compra sin fecha de vencimiento de pago**: cae en el bucket "A Vencer" o en el bucket que ya
  define el servicio de cuenta corriente vigente para clientes, sin inventar una regla nueva.
- **Volumen alto**: con miles de compras/gastos en el rango, la tabla pagina y el informe sigue
  respondiendo, sin traer todo el dataset al navegador.
- **Sesión sin permiso de informes**: la entrada del sidebar no se muestra y el acceso directo a la
  URL se rechaza.

## Requirements *(mandatory)*

### Navegación y estructura común

- **FR-001**: El submenú "Informes" del sidebar MUST exponer tres entradas nuevas — "Compras",
  "Gastos" y "Cuenta Corriente Proveedores" — cada una apuntando a su propia ruta real, sumadas a
  las ya existentes "Stock" y "Cuenta Corriente" (que pasa a rotularse "Cuenta Corriente Clientes"
  para desambiguar). NO se construye una landing de tarjetas equivalente a `/reports`.
- **FR-002**: Las tres entradas MUST respetar el permiso de visualización de informes ya vigente;
  sin ese permiso no se muestran ni son accesibles por URL directa.
- **FR-003**: Los informes de Compras y Gastos MUST ofrecer el selector de rango "Emisión" con las 9
  opciones relevadas: Hoy, Ayer, Última Semana, Mes actual, Mes anterior, Últimos 30 días, Año
  actual, Desde-Hasta y Borrar filtro. "Desde - Hasta" MUST abrir el widget compuesto de dos
  calendarios mensuales contiguos con la lista de accesos rápidos visible en simultáneo, y campos de
  fecha tipeables.
- **FR-004**: El tab "Movimientos" de Cuenta Corriente Proveedores MUST ofrecer el mismo selector de
  rango "Emisión", igual que ya lo hace el de clientes.
- **FR-004b**: Los tres informes MUST abrirse por defecto con el rango **Mes actual**.
- **FR-005**: "Mes actual" MUST abarcar el mes calendario completo (día 1 al último día del mes),
  incluyendo fechas futuras dentro del mismo mes — mismo criterio que Contagram, para que los
  totales sean comparables.
- **FR-006**: Toda tabla de detalle MUST cargarse paginada desde el servidor; ningún informe puede
  depender de traer el dataset completo al navegador.
- **FR-007**: Todo selector de datos dinámicos de los paneles de filtro (Proveedor, Categoría,
  Producto, Tipo de Producto, Etiqueta, Usuario, Medio de pago) MUST ser un selector con buscador.
- **FR-008**: Ninguna interacción de los informes (cambio de rango, aplicación de filtros,
  activación de columnas, expansión de agrupaciones, apertura de modales, exportación) puede
  recargar la página.
- **FR-009**: Toda notificación de éxito, error o advertencia MUST mostrarse como alerta toast del
  template.

### Informe de Compras

- **FR-010**: El informe MUST mostrar un bloque de KPIs con la ecuación visible **Total Compras
  Creadas + Total Nota de Débito − Total Nota de Crédito = Total Compras**, calculada sobre el rango
  y los filtros vigentes.
- **FR-011**: El informe MUST mostrar además los indicadores Cantidad Prod./Serv. (**suma de las
  cantidades** de todos los ítems del período, no el número de líneas), Cantidad Compras Creadas,
  Compra Promedio (Total Compras ÷ Cantidad Compras Creadas) y Costo Actual.
- **FR-012**: "Costo Actual" MUST calcularse multiplicando el costo vigente del producto por la
  cantidad de la línea, y su definición MUST quedar explicada en un tooltip en pantalla, dado que
  puede diferir del costo histórico al que se compró.
- **FR-013**: La tabla de detalle MUST tener **una fila por ítem de compra** y mostrar por defecto
  las columnas Id, Fecha, Comprobante, Proveedor, Producto/Servicio, Cant., Precio y Total
  Comprobante, con desplazamiento horizontal cuando no entren. "Total Comprobante" repite el total
  de la compra en cada una de sus filas; los KPIs NO lo suman por fila sino una vez por comprobante.
  Las Notas de Crédito y Débito aportan sus propias filas con el mismo criterio.
- **FR-014**: La tabla MUST ofrecer un selector de columnas que permita activar el desglose
  impositivo completo, disponible **en pantalla** y no sólo en la exportación: Vencimiento, CUIT/DNI,
  Tipo, Tipo de Comprobante, Punto de Venta, N° Factura, Código de producto, Tipo de producto,
  Costo, Subtotal sin Descuento, Descuento en $, Subtotal con Descuento, Importe Neto No Gravado,
  Importe Neto Exento, Importe Neto Gravado, IVA 2,5%, IVA 5%, IVA 10,5%, IVA 21%, IVA 27%, Exento,
  No Gravado, Perc. IVA, Perc. IIBB, **Otras Percepciones**, Imp. Internos, Total Compra, Etiquetas
  y Afecta Stock. Esta es una **divergencia deliberada y documentada** respecto de Contagram, que
  sólo las vuelca al Excel.
- **FR-015**: El desglose de IVA por alícuota MUST derivarse de la alícuota registrada en cada ítem
  de la compra, sin agregar campos nuevos al modelo de datos.
- **FR-015b**: Las percepciones MUST repartirse en tres columnas — Perc. IVA, Perc. IIBB y Otras
  Percepciones — clasificando cada concepto de tipo percepción por su descripción. Una percepción
  que no pueda clasificarse MUST caer en "Otras Percepciones"; nunca puede descartarse ni imputarse
  a una columna que no le corresponde. La suma de las tres columnas MUST igualar el total de
  percepciones de la compra.
- **FR-016**: Las líneas de Nota de Crédito y Nota de Débito MUST calcularse con la misma fórmula
  que las líneas de compra normal, respetando el signo de cada columna, sin ramas de cálculo
  especiales por tipo de comprobante. El bug de signos detectado en el Excel de Ventas de Contagram
  (§11.2 del relevamiento) NO se replica.
- **FR-017**: La preferencia de columnas activadas MUST persistir para el usuario entre visitas.
- **FR-018**: El panel de Filtros MUST ofrecer los 12 campos relevados: Id, Producto/Servicio, Tipo
  de Producto/Servicio, Etiqueta, Productos, Facturado, Categoría de Compra, Proveedor, Tipo y N° de
  Factura, Usuario, Observación y Estado del Pago, con un botón "Buscar" al final.
- **FR-019**: El filtro "Estado del Pago" MUST ofrecer los cuatro valores ya vigentes en el listado
  de Compras: A Pagar, Parcial, Pagado y Vencido, con el mismo criterio de "Vencido" (vencimiento
  pasado y saldo mayor a cero).
- **FR-020**: Los filtros MUST combinarse con AND entre campos distintos y OR dentro de un mismo
  campo de selección múltiple.
- **FR-021**: Las compras eliminadas (soft delete) NO deben aparecer en el informe ni sumar a los
  KPIs.

### Informe de Gastos

- **FR-022**: El informe MUST mostrar un bloque con Desde, Hasta y Gasto Total del período.
- **FR-023**: El cuerpo MUST estructurarse en dos niveles expandibles, Categoría → Subcategoría,
  aprovechando la jerarquía de categorías ya existente, con el subtotal de cada nivel. Se resuelve
  como **una única tabla paginada desde el servidor con agrupación por filas** y filas de subtotal,
  no como una tabla independiente por subcategoría, para no perder la paginación real (FR-006).
- **FR-024**: Cada Subcategoría expandida MUST mostrar su detalle con Id, Fecha, Descripción, Medio
  de Pago y Total.
- **FR-025**: El panel de Filtros MUST ofrecer Categoría y/o Subcategoría, Medio de pago, Estado del
  Pago y Usuario.
- **FR-026**: La suma de los subtotales de todas las Categorías MUST coincidir exactamente con el
  Gasto Total mostrado.
- **FR-027**: El informe MUST contemplar los gastos marcados como pendientes, distinguibles vía el
  filtro Estado del Pago.

### Informe de Cuenta Corriente Proveedores

- **FR-028**: La pantalla MUST tener dos tabs sobre un único shell y una única ruta — "Saldos
  Proveedores" (activo por defecto) y "Movimientos" — espejo estructural exacto de la pantalla de
  clientes ya construida.
- **FR-029**: El tab "Saldos Proveedores" MUST mostrar por proveedor las columnas Proveedor, A
  Vencer, Vencido 0-30, Vencido 31-60, Vencido 61-90, Vencido >90 y Total, con el Total ordenable y
  un filtro por Proveedor.
- **FR-030**: El cálculo de saldos y buckets de antigüedad MUST reutilizar el servicio de cuenta
  corriente ya existente, que ya contempla el caso "proveedor"; NO se reimplementa la lógica de
  aging ni se duplica su criterio de tolerancia.
- **FR-031**: Los proveedores con saldo negativo (saldo a favor por Nota de Crédito) MUST listarse,
  no descartarse; sólo se excluyen los saldos dentro de la tolerancia de cero.
- **FR-032**: El saldo inicial de proveedor MUST contemplarse igual que el de cliente: crea la fila
  aunque el proveedor no tenga ninguna compra, y suma con su signo.
- **FR-033**: Hacer clic sobre el nombre de un proveedor MUST abrir un modal de **sólo lectura** con
  su ficha (identificación, contacto, domicilio, condición fiscal, comprobante por defecto, nota),
  sin botones de edición dentro del modal.
- **FR-034**: El tab "Movimientos" MUST listar de forma combinada Compras, Pagos, Notas de Crédito,
  Notas de Débito y Saldo Inicial, con las columnas Id, Emisión, Proveedor, Operación, Categoría,
  Total Compra, Pagado, A Pagar, N° de Comprobante, Medio de Pago y Descripción, dejando vacías las
  celdas que no aplican a cada tipo de fila.
- **FR-035**: El tab "Movimientos" MUST ofrecer filtros por Proveedor y por Operación además del
  rango de Emisión.
- **FR-036**: La suma de "A Pagar" de las filas de Compra de un proveedor, más su fila de Saldo
  Inicial, MUST coincidir con el Total de ese proveedor en el tab "Saldos Proveedores".
- **FR-037**: La pantalla MUST ser de **sólo lectura**. El menú por fila Ver/Editar/Eliminar que
  Contagram ofrece en Movimientos queda explícitamente **fuera de alcance** de esta tanda y se
  documenta como brecha pendiente.
- **FR-038**: La opción "Cta Cte" del menú de fila del listado de Compras, hoy deshabilitada con la
  leyenda "Próximamente", MUST habilitarse y navegar a este informe con el proveedor de esa compra
  ya preseleccionado y el tab "Movimientos" abierto — mismo comportamiento que el deep-link de
  Clientes.

### Exportación

- **FR-039**: Los tres informes MUST ofrecer exportación a Excel y a PDF, respetando el rango y los
  filtros vigentes al momento de exportar.
- **FR-040**: Cada archivo Excel MUST contener **dos hojas**: una formateada para leer (respetando
  la agrupación y los subtotales que se ven en pantalla) y una plana de una fila por registro, sin
  celdas combinadas ni encabezados de sección, apta para reprocesar. Esta es una extensión
  deliberada del patrón que Contagram aplica sólo a Gastos.
- **FR-041**: El Excel del Informe de Compras MUST incluir el desglose impositivo completo con
  independencia de qué columnas estuvieran activadas en pantalla.
- **FR-042**: El PDF MUST abrirse dentro del modal de PDF compartido de la aplicación; abrir una
  pestaña o ventana nueva sólo se admite como último recurso si ese modal no está disponible.
- **FR-043**: Los totales de cualquier exportación MUST coincidir exactamente con los KPIs y
  subtotales mostrados en pantalla.
- **FR-044**: Los valores exportados MUST ser valores ya calculados, sin depender de fórmulas
  evaluadas por la herramienta que abra el archivo.

### Key Entities

- **Compra**: documento de egreso con proveedor, categoría, depósito, fechas (emisión, vencimiento
  de pago, servicio desde/hasta, mes de imputación de IVA), tipo y número de comprobante, subtotales
  y descuento general, total, y estado de pago derivado. Fuente principal del Informe de Compras y
  del lado "debe" de la Cuenta Corriente Proveedores.
- **Ítem de Compra**: línea de producto/servicio con cantidad (admite negativa), precio unitario,
  descuento, **alícuota de IVA** y subtotales. La alícuota por ítem es el origen del desglose de IVA
  por columna del informe.
- **Concepto de Compra**: importe adicional de la compra tipificado como percepción, impuesto
  interno o interés, con su descripción. Origen de las columnas Perc. IVA, Perc. IIBB e Imp.
  Internos.
- **Nota de Crédito / Débito de Compra**: ajusta el total adeudado al proveedor con su signo;
  participa de la ecuación de KPIs y de los saldos de cuenta corriente.
- **Pago**: cancelación total o parcial de una compra contra una cuenta de tesorería; determina
  Pagado / A Pagar y aparece como fila propia en Movimientos.
- **Gasto**: erogación simple con fecha, monto, categoría (de tipo gasto, con jerarquía
  Categoría→Subcategoría), cuenta de tesorería como medio de pago, descripción, marca de pendiente y
  usuario. Única fuente del Informe de Gastos.
- **Categoría**: taxonomía compartida diferenciada por tipo (venta / compra / gasto / ingreso) con
  jerarquía padre-hijo. Provee tanto la "Categoría de Compra" del filtro como el árbol
  Categoría→Subcategoría del Informe de Gastos, que son **dos catálogos distintos**.
- **Proveedor**: entidad con datos de contacto, fiscales y saldo inicial. Es la fila del tab Saldos
  y el contenido del modal de ficha.
- **Cuenta de Tesorería**: medio de pago de compras y gastos; aparece como "Medio de Pago" en los
  informes.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: El responsable puede responder "cuánto compré este mes, a qué proveedores y con qué
  IVA" en menos de 1 minuto desde que entra al sistema, sin abrir ninguna compra individual.
- **SC-002**: El responsable puede responder "en qué categoría se me fue la plata este mes" en menos
  de 30 segundos, con el detalle de cada gasto a un clic de distancia.
- **SC-003**: El responsable puede identificar en menos de 30 segundos qué proveedores tienen deuda
  vencida hace más de 90 días.
- **SC-004**: Los totales de los tres informes coinciden al centavo con los totales de las pantallas
  de origen (listado de Compras, listado de Gastos, bloque de cuentas a pagar del Dashboard) en el
  100% de los períodos comparados.
- **SC-005**: Los archivos exportados reproducen exactamente los mismos registros y totales que la
  pantalla en el 100% de las combinaciones de filtros probadas.
- **SC-006**: Cada informe muestra sus resultados en menos de 3 segundos sobre un volumen de al
  menos 5.000 compras y 5.000 gastos en el rango consultado.
- **SC-007**: Las tres pantallas coinciden estructuralmente con las capturas del relevamiento
  (columnas, orden, campos de filtro, agrupaciones, KPIs) salvo las divergencias explícitamente
  documentadas en esta spec.
- **SC-008**: Ninguna operación del módulo provoca una recarga de página completa.

## Assumptions

- **Navegación**: se asume confirmada la divergencia de no construir la landing `/reports` de 8
  tarjetas, decidida por el usuario antes de esta spec.
- **Alcance por tandas**: se asume que Informe de Ventas y Reporte Final (tanda 2, bloqueados por la
  decisión pendiente sobre costo histórico / CMV en los ítems de venta) y Rankings / "Arma tu
  Informe" / vista consolidada de gráficos, más el menú de gestión por fila en Cuenta Corriente y
  los ajustes al Informe de Stock ya construido (tanda 3), quedan fuera de esta spec.
- **Sin cambios de esquema**: se asume que los tres informes se construyen sobre el modelo de datos
  vigente, sin migraciones nuevas. En particular, el desglose de IVA por alícuota se deriva
  agrupando ítems por su alícuota, y las percepciones e impuestos internos se derivan de los
  conceptos de compra ya tipificados.
- **Perc. IVA vs Perc. IIBB**: resuelto en Clarifications — tres columnas con "Otras Percepciones"
  como destino de lo no clasificable (FR-015b). Se asume que la descripción del concepto es
  suficientemente consistente en los datos reales como para que "Otras Percepciones" sea residual y
  no el caso mayoritario; si no lo fuera, la salida es tipificar el concepto en el formulario de
  Compra, lo que sería una spec aparte.
- **Aging de proveedores**: se asume que el criterio de vencimiento y los cinco buckets ya
  implementados para clientes aplican tal cual a proveedores, sin regla nueva.
- **Rendimiento del tab Saldos**: se asume conocida y aceptada la brecha ya documentada de que la
  agregación por entidad y el bucketing del tab Saldos ocurren fuera de la base de datos; esta spec
  no la resuelve, pero tampoco la empeora, y el informe de proveedores hereda el mismo
  comportamiento que el de clientes.
- **Permisos**: se asume que el permiso de visualización de informes ya existente cubre las tres
  pantallas nuevas, sin crear permisos granulares por informe.
- **Costo Actual**: se asume que replicar el cálculo de Contagram (costo vigente × cantidad, no
  costo histórico) es correcto para Compras, ya que es un indicador de valorización actual; el
  tooltip explicativo evita que se lo confunda con el costo real de compra.
- **Estado vacío**: se asume que un período sin datos muestra KPIs en cero y un mensaje explícito,
  no un error.

## Out of Scope

- Landing `/reports` con tarjetas de informes.
- Informe de Ventas y Reporte Final (tanda 2).
- Rankings, "Arma tu Informe" y la vista consolidada de gráficos (tanda 3).
- Menú de fila Ver/Editar/Eliminar dentro de los Movimientos de Cuenta Corriente — estas pantallas
  siguen siendo de sólo lectura.
- Cambios al Informe de Stock ya implementado.
- Optimización de la paginación en base de datos del tab Saldos (brecha ya documentada, spec propia).
- Envío programado de informes por correo.
