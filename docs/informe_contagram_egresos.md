# Informe Contagram — Módulo Egresos (Compras y Gastos)

**Fecha del relevamiento:** 24/07/2026
**Cuenta analizada:** Cuenta de Prueba (trial), usuario alberto rodriguez
**Alcance:** Análisis exhaustivo del dropdown "Egresos" del menú lateral, compuesto por dos submódulos: **Compras** y **Gastos**. Se documentan todas las vistas, subvistas, botones, formularios, menús y comportamientos observados, incluyendo la creación de registros de prueba reales en la cuenta de prueba.
**Capturas de referencia:** `[122]` a `[143]` (ver índice al final). Carpeta: `capturas_contagram/`.

---

## 1. Estructura general de Egresos

El menú lateral "Egresos" se despliega en dos ítems, cada uno con su propio botón "+" de acceso rápido a la creación de un nuevo registro:

- **Compras**: registro de compras a proveedores, con flujo documental completo (ítems, IVA, pagos, notas de crédito/débito, remitos).
- **Gastos**: registro simple de gastos operativos (alquiler, sueldos, marketing, impuestos, etc.), sin vínculo a proveedores ni a productos.

Estructuralmente, **Compras es el espejo de Ventas** (dentro de Ingresos): mismo esqueleto de listado, KPIs, filtros, formulario de nuevo documento y ficha de detalle, pero con Proveedor en lugar de Cliente y con particularidades propias que se detallan en la sección 2. **Gastos, en cambio, no tiene equivalente en Ingresos** — es un módulo mucho más liviano, pensado para carga rápida de erogaciones sin la complejidad de ítems, IVA discriminado o documentos fiscales.

---

## 2. Submódulo Compras

### 2.1 Listado de Compras `[122]` `[135]`

Ruta: `/purchases`

**Barra de KPIs** (idéntica en estructura a la de Ventas, con ecuación visual +/=/−):
- Cantidad de Compras
- Pagado (verde)
- A Pagar (ámbar)
- Vencido (rojo)
- Total Compras (azul), resultado de la suma

**Controles superiores:**
- Botón **Filtros** (icono de embudo)
- Dos selectores de rango de fecha: **Emisión** y **Vencimiento** (Compras tiene dos, a diferencia de Gastos que solo tiene uno)
- Selector de columnas (icono de grilla)
- Botón verde **Nueva Compra** (+)

**Columnas visibles por defecto:** Estado, Id, Emisión, Vencimiento, Proveedor, Categoría, Subtotal sin Descuento, Descuento, Subtotal con Descuento, Total Compra, Pagado, A Pagar, Etiquetas, Medio de Pago (esta última visible solo hciendo scroll horizontal).

**Scroll horizontal** `[123]`: la tabla requiere desplazamiento lateral para revelar columnas adicionales como Etiquetas y Medio de Pago — mismo patrón ya documentado en Base de Datos e Ingresos.

**Selector de columnas** `[135]`: al hacer clic en el ícono de grilla se despliega un panel con checkboxes para columnas adicionales no visibles por defecto: CUIT, Servicio Desde, Servicio Hasta, Teléfono, Mail, y otras (lista scrolleable). Permite personalizar completamente qué información se muestra en la tabla.

Cada fila cuenta con un **estado editable inline** (etiqueta de color: "Pagado" en verde, "A Pagar" en amarillo/ámbar) con flecha desplegable.

### 2.2 Filtros `[124]`

Panel expandible con los siguientes campos (patrón consistente con Ventas):
- Id
- Proveedor
- Categoría
- Estado del Pago
- Etiquetas
- Descripción / Nota
- Usuario
- Botón **Buscar**

### 2.3 Menú de acciones por fila `[125]`

Desplegable de 9 opciones — **más liviano que el de Ventas (12 opciones)**, ya que carece de "Imprimir Ticket", "Enviar Detalle" y "Enviar Whatsapp":
1. Ver
2. Editar
3. Ver Detalle
4. Agregar Pago
5. Crear NC/ND (Nota de Crédito / Nota de Débito)
6. Crear Remito
7. Cta Cte (Cuenta Corriente del proveedor)
8. Imprimir Detalle
9. Eliminar

### 2.4 Formulario "Nueva Compra" `[126]` `[127]` `[128]`

Ruta: `/purchases/new`

**Campos de cabecera:**
- **Proveedor** (buscador con autocompletado; al seleccionar un proveedor existente, precarga automáticamente su **Categoría de Compras** guardada como valor por defecto — comportamiento confirmado en prueba real: al elegir "Textiles del Sur SRL", el campo Categoría se autocompletó con "Insumos", el default configurado en la ficha del proveedor)
- **Emisión** (fecha, con selector de calendario y checkbox de confirmación)
- **Vto. del Pago** (fecha)
- **Servicio Desde / Servicio Hasta** (fechas)
- **Contador** — campo **exclusivo de Compras, sin equivalente en Ventas**. Tooltip `[127]`: *"Mes de imputación en el IVA Compras, para el informe a tu Contador"*. Permite indicar a qué período fiscal de IVA Compras corresponde imputar la operación, independientemente de la fecha de emisión — relevante para compras con desfasaje entre fecha de recepción y fecha de imputación contable.
- **Tipo** (tipo de comprobante) y numeración

**Línea de producto/servicio:**
- Buscador "Seleccionar o Crear Producto/Servicio"
- **Icono de lector de código de barras** junto al buscador `[126]` — campo dedicado para escaneo directo, vinculado a la Función Avanzada "Lector de código de barras" (documentada en el informe de Funciones Avanzadas). Este es el punto de la aplicación donde esa función se materializa en la interfaz.
- **Etiquetas** (botón)

**Columnas de la grilla de ítems:** Producto, Cant., Precio, Desc. (%), Subtotal, **IVA**, Total.

**Diferencia clave frente a Ventas** `[128]`: al agregar un producto, la columna **IVA no viene preseleccionada** — muestra "Elegir" en lugar de un valor por defecto. En Ventas, en cambio, el IVA se autocompleta en 21% al agregar el producto. Como consecuencia, mientras el IVA está sin elegir, el panel de totales muestra **"Importe Neto No Gravado"** en lugar de "Importe Neto Gravado".

**Selector de IVA** (desplegable "Elegir" → lista): Elegir, IVA - 2,5%, IVA - 5%, IVA - 10,5%, IVA - 21%, IVA - 27% (scrolleable, mismas alícuotas que Ventas).

**Bloques repetibles adicionales** (igual que Ventas): + Percepciones, + Impuestos Internos, + Intereses.

**Nota Interna** (campo de texto libre).

**Botones:** Cancelar / Guardar.

### 2.5 Ficha de detalle de una Compra `[129]` `[131]` `[134]`

Al guardar, redirige a `/purchases/{id}` con mensaje de éxito ("Compra N creada con éxito").

**Barra superior de ecuación** (patrón Ventas): Total Compra (+) ND (−) NC (−) Pagado (=) A Pagar.

**Sección Pagos:**
- Tabla: Id, Fecha, Medio de pago, Nota, Total, Comprobante
- Dos enlaces: **+ Agregar Pago** y **+ Agregar Retención**

**Modal "Nuevo Pago"** `[130]` `[131]`: Fecha, Monto (precargado con el saldo pendiente), **Elija Medio de Pago** (desplegable de cuentas de tesorería: Crear Cuenta, Caja del Local, Caja General, Banco Galicia, Banco Santander Río, etc. — mismas cuentas configuradas en Tesorería), Nota, botones Cancelar/Crear. Al confirmarse, genera un comprobante correlativo (ej. "X 0001-00000005") y actualiza el estado de la compra a "Pagado".

**Modal "Nueva Retención"** `[132]` `[133]` — **hallazgo relevante**: este es el punto de la aplicación donde la Función Avanzada "Retenciones" (activada previamente, sin efecto visible en el flujo de Ventas/Cobranzas) sí se materializa. Campos: Fecha, Monto, **Elija Tipo** (desplegable con: Ganancias, IVA, Seguridad Social, Sellos, Ingresos Brutos Buenos Aires, Ingresos Brutos Capital Federal, y otras jurisdicciones scrolleables), campo de número/comprobante junto al tipo, Descripción, botones Cancelar/Crear. Esto confirma que las retenciones sufridas por el negocio se registran del lado de Compras (como retenciones que le practican al comprador… en este caso, dado que es una Compra, en rigor modela retenciones que la empresa sufre como comprador o que debe declarar en el pago a proveedores).

**Sección "DETALLE DE COMPRA"**: documento con marca de agua "NO VÁLIDO COMO FACTURA", datos del Proveedor (Nombre, Apellido, CUIT, Domicilio), Categoría, tabla de Conceptos (Código, Descripción, Cant., Precio Unitario, % Bonif., Subtotal, Alícuota IVA, Subtotal c/IVA), panel de totales (Importe Neto Gravado, IVA, Total Compra, Total Pagado, Total a Pagar) y campo Observaciones.

**Botones de acción bajo el detalle:** Imprimir Detalle, Exportar Detalle, Editar Compra.

**Sección "Notas de Crédito y Débito"**: tabla vacía por defecto (Estado, ID, Emisión, Comprobante, N° Comprobante, Documento que Ajusta, Total, Nota Interna) con enlace **+ Agregar**.

**Botón "Crear Remito"** visible en la parte superior de la ficha de detalle (junto a "Ver mis Compras").

---

## 3. Submódulo Gastos

### 3.1 Listado de Gastos `[136]` `[143]`

Ruta: `/expenses`

**Sin barra de KPIs** — a diferencia de Compras y Ventas, el listado de Gastos no muestra ecuación de totales en la parte superior.

**Controles superiores:**
- Botón **Filtros**
- Un único selector de fecha: **Emisión** (Compras tiene dos: Emisión y Vencimiento; Gastos solo uno, reflejando que un gasto no maneja vencimiento de pago separado de su fecha)
- Selector de columnas
- Botón verde **Nuevo Gasto** (+)

**Columnas de la tabla:** Estado, Id, Emisión, Categoría, Subcategoría, Descripción, Medio de Pago. No hay columna "Proveedor" (los gastos no se asocian a un proveedor de la Base de Datos), ni columnas de Subtotal/Descuento como en Compras.

**Selector de columnas** `[143]`: bastante más simple que el de Compras — solo 6 columnas disponibles en total (Emisión, Categoría, Subcategoría, Descripción, Medio de Pago, Monto), todas activas por defecto excepto **Monto**, que está oculta de entrada.

### 3.2 Filtros `[138]`

Panel con: Id, Categoría y/o Subcategoría, Medio de pago, Estado del Pago, Descripción (campo "Contiene"), Usuario. Botón Buscar.

### 3.3 Menú de acciones por fila `[137]`

Solo **3 opciones** — el menú más liviano de todo Contagram relevado hasta el momento:
1. Ver
2. Editar
3. Eliminar

No hay opciones de "Ver Detalle", "Agregar Pago", "Crear NC/ND", "Imprimir" ni "Cta Cte": el gasto es un registro atómico sin documento fiscal asociado.

### 3.4 Formulario "Nuevo Gasto" `[139]` `[140]` `[141]`

A diferencia de Compras y Ventas, **no es una página completa sino un modal** que se abre sobre el listado (`/expenses?modal_opened=true`).

**Campos:**
- Fecha (precargada con la fecha actual)
- Monto ($)
- **Seleccionar Categoría**: desplegable jerárquico de dos niveles (Categoría → Subcategoría), con opción "Crear Categoría de Gasto" y, dentro de cada categoría, "Crear Subcategoría". Categorías relevadas en la cuenta de prueba: **Empleados**, **Impuestos**, **Marketing** (con subcategorías "Facebook Add's" y "Material de Promoción"), **Oficina** (con subcategoría "Alquiler", "Luz"), **Otros Gastos**, **Servicios Profesionales**.
- **Elija un medio de pago**: mismo desplegable de cuentas de tesorería que en Compras (Crear Cuenta, Caja del Local, Caja General, Banco Galicia, Banco Santander Río…)
- **Descripción** (campo de texto libre)
- Checkbox **"Marcar como pendiente"** — permite crear el gasto sin conciliarlo como pagado, quedando en estado "Pendiente"
- Botones Cancelar / Crear

Al guardar, el modal se cierra y el listado se actualiza inmediatamente con el nuevo registro — **no redirige a una ficha de detalle propia**; hacer clic sobre el Id de un gasto reabre directamente el mismo modal en modo edición ("Editar Gasto"), confirmando que Gastos no tiene una vista de "detalle" o "documento imprimible" independiente como sí ocurre en Compras y Ventas.

---

## 4. Observaciones y hallazgos relevantes

- **Compras es el espejo de Ventas** en estructura de listado, filtros, KPIs, formulario y ficha de detalle (con Proveedor/Pagos/A Pagar en lugar de Cliente/Cobranzas/A Cobrar), pero con tres diferencias de comportamiento propias: el campo "Contador" (imputación de IVA Compras), el IVA no preseleccionado por defecto en los ítems (a diferencia del 21% automático en Ventas), y un menú de fila más acotado.
- **El icono de lector de código de barras** en el formulario de Nueva Compra es el punto concreto donde se materializa la Función Avanzada "Lector de código de barras" activada en un informe anterior, que en aquel momento no había mostrado ningún cambio visible inmediato.
- **Las Retenciones sí tienen un punto de entrada visible en Compras** ("+ Agregar Retención" en la ficha de detalle, con tipos Ganancias/IVA/Seguridad Social/Sellos/Ingresos Brutos por jurisdicción), a diferencia de Ventas, donde la Función Avanzada "Retenciones" no mostró ningún campo visible en el modal "Nuevo Cobro" durante el informe de Funciones Avanzadas. Esto sugiere que el sistema modela las retenciones principalmente del lado de las compras/pagos a proveedores.
- **Gastos es un módulo deliberadamente más simple** que Compras: sin KPIs, sin vínculo a proveedores de la Base de Datos, sin documento imprimible, sin notas de crédito/débito, sin pagos parciales — es un libro de erogaciones de rápida carga, ideal para gastos operativos recurrentes (alquiler, sueldos, marketing, impuestos) que no requieren trazabilidad fiscal de comprobante.
- **Gastos usa categorías propias**, independientes del árbol de Categorías de Compras usado en la Base de Datos de Proveedores — son dos taxonomías distintas dentro de la misma aplicación (Categoría de Compras del Proveedor vs. Categoría de Gasto).
- El **selector de cuenta de tesorería** (medio de pago) es idéntico entre Compras y Gastos, confirmando que ambos módulos descargan en el mismo pool de cuentas configurado en Tesorería.
- El **contador de prueba** ("Tu período de prueba finaliza en 6 días") avanzó respecto a informes anteriores de la sesión (era 7 días), reflejando el paso del tiempo real durante el relevamiento.

---

## 5. Registros de prueba creados

| Módulo | Registro | Detalle |
|---|---|---|
| Compras | Compra N.° 6 | Proveedor "Textiles del Sur SRL", Categoría "Insumos" (autocompletada), 1× Camisa Hombre Blanca Large, $200 + IVA 21% = **$242,00**. Pago completo registrado vía "Banco Galicia" (Caja del Local seleccionada como medio efectivo). Estado final: **Pagado**. |
| Gastos | Gasto N.° 7 | Categoría "Marketing" → Subcategoría "Facebook Add's", $5.000,00, medio de pago "Banco Galicia", descripción "Campaña Facebook Ads Julio - registro de prueba". Estado: **Pagado**. |

---

## 6. Índice de capturas

| N.° | Descripción |
|---|---|
| 122 | Listado de Compras |
| 123 | Listado de Compras — scroll horizontal derecha |
| 124 | Panel de Filtros de Compras |
| 125 | Menú de acciones por fila (Compras) |
| 126 | Formulario Nueva Compra completo |
| 127 | Tooltip del campo "Contador" |
| 128 | Producto agregado con IVA en "Elegir" (sin preseleccionar) |
| 129 | Compra 6 creada — detalle con enlaces Agregar Pago / Agregar Retención |
| 130 | Modal Nuevo Pago — desplegable de medio de pago |
| 131 | Pago registrado con éxito — Compra pasa a estado Pagado |
| 132 | Modal Nueva Retención |
| 133 | Desplegable de tipos de retención (Ganancias, IVA, Seguridad Social, Sellos, Ingresos Brutos...) |
| 134 | Ficha de detalle — Conceptos, totales y botones Imprimir/Exportar/Editar |
| 135 | Selector de columnas de la tabla de Compras |
| 136 | Listado de Gastos |
| 137 | Menú de acciones por fila (Gastos) — Ver/Editar/Eliminar |
| 138 | Panel de Filtros de Gastos |
| 139 | Modal Nuevo Gasto (vacío) |
| 140 | Desplegable de Categoría/Subcategoría de Gasto |
| 141 | Modal Nuevo Gasto completo, listo para crear |
| 142 | Gasto 7 creado con éxito — listado actualizado |
| 143 | Selector de columnas de la tabla de Gastos |

---

*Informe generado como parte del relevamiento exhaustivo de Contagram. Módulos previos: Base de Datos (Clientes, Proveedores, Productos), Ingresos (Presupuestos, Ventas, Otros Ingresos), Funciones Avanzadas.*
