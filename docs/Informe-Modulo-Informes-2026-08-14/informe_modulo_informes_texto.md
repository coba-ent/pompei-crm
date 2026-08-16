CONTAGRAM
Relevamiento exhaustivo del módulo Informes
Con datos reales cargados + análisis de la lógica interna de cálculo a partir de los archivos Excel exportados

Cuenta analizada: Cuenta de Prueba (trial), usuario alberto
Fecha del relevamiento: 14/08/2026
Acceso: menú lateral &quot;Informes&quot; (/reports) — 8 tipos de informe + vista Rankings consolidada (/graphs)
Método: exploración interactiva de cada informe y cada sub-pestaña (filtros, dropdowns, modales, arrastre de dimensiones, checkboxes de simulación), generación de ventas/compras/gastos/otros ingresos de prueba para poblar los ocho informes con datos reales, y descarga + análisis binario (openpyxl) de 7 archivos .xlsx exportados desde la propia aplicación para reconstruir la lógica de cálculo interna.

Nota sobre las capturas de pantalla
Se navegó y se verificó visualmente cada pantalla, modal y variante descripta en este documento, y se generaron 30 capturas (formato .gif, algunas con varios fotogramas mostrando la secuencia de interacción) cubriendo cada informe, sub-pestaña, modal y comportamiento distintivo relevado. Los archivos están guardados como archivos sueltos en la carpeta &quot;Capturas&quot; dentro de esta misma carpeta del informe (Informe-Modulo-Informes-2026-08-14/Capturas), numerados del 01 al 30 en el mismo orden en que se describen a lo largo del documento, para poder abrirlos en paralelo con la lectura de cada sección.

1. Resumen ejecutivo
La sección Informes de Contagram (/reports) ofrece 8 tipos de informe accesibles desde el menú lateral, más una vista consolidada de Rankings (/graphs). Los 8 informes comparten una arquitectura común: selector de rango de fechas &quot;Emisión&quot; (9 opciones idénticas en todos), panel de &quot;Filtros&quot; específico por informe, tabla de detalle scrolleable, y botones de exportación (Excel + PDF) en la esquina inferior derecha. Dos de los ocho informes (Ventas y Compras) incorporan además un motor de tablas dinámicas (Rankings + &quot;Arma tu Informe&quot;) construido sobre la librería open-source PivotTable.js, que permite arrastrar dimensiones libremente para armar cruces a medida y guardarlos como pestañas persistentes.
Para este relevamiento se cargaron ventas, compras, gastos, otros ingresos y cobros/pagos reales en la cuenta de prueba, lo que permitió — a diferencia de un relevamiento anterior hecho sobre una cuenta vacía — verificar en vivo el comportamiento de las tablas dinámicas con datos reales (arrastre de dimensiones, los 8 modos de visualización, las 7 acciones de agregación), el modal &quot;Guardar Informe&quot;, los popups de ficha de cliente/proveedor, el menú de acciones por fila en Movimientos, y el simulador de &quot;qué pasaría si&quot; del Reporte Final.
Además, se descargaron y analizaron a nivel binario (con openpyxl) 7 archivos Excel generados por la propia aplicación desde los botones &quot;Exportar&quot;. Este análisis reveló que varios informes exportan muchísimas más columnas de las que se ven en pantalla — incluyendo el desglose impositivo completo (IVA por alícuota, percepciones IIBB, impuestos internos) en Compras — y permitió detectar una inconsistencia concreta en el cálculo de &quot;Resultado&quot; para líneas de Nota de Crédito en el informe de Ventas. Todos estos hallazgos se detallan en la sección 11.
2. Landing de Informes (/reports)
La pantalla /reports muestra 8 tarjetas en una grilla de 2 columnas, cada una con ícono, título y una descripción de una línea:
#
Informe
Descripción mostrada en la tarjeta
1
Ventas
Mirá tus ventas desglosadas por clientes, productos, vendedores y categorías.
2
Compras
Mirá tus compras desglosadas por proveedores, productos y categorías.
3
Cuenta Corriente Clientes
Conocé cuánto te deben tus clientes y hace cuánto tiempo. También podes ver el detalle de los movimientos realizados con cada cliente.
4
Cuenta Corriente Proveedores
Conocé cuánto le debes a tus proveedores y hace cuánto tiempo. También podes ver el detalle de los movimientos realizados con cada proveedor.
5
Reporte Final
Personalizá tu informe para conocer tu resultado mensual.
6
Gastos
Mirá todos tus gastos desglosados por categorías, y conocé en qué estas gastando tu dinero.
7
Stock
Conocé los movimientos históricos del stock de tus productos. Visualiza las cantidades vendidas, compradas o fabricadas históricamente.
8
Rankings (etiqueta &quot;¡NUEVO!&quot;)
Arma tus rankings a medida y conoce mejor tu negocio.
3. Informe de Ventas (/sale_reports)
3.1 Encabezado y KPIs
Tres pestañas internas: &quot;Informe de Ventas&quot; (activa por defecto), &quot;Rankings&quot; (dropdown) y &quot;Arma tu Informe&quot; (dropdown). Enlace &quot;Video Explicativo&quot; arriba a la derecha.
Fórmula visible en tres bloques de KPIs, con datos reales de la cuenta al momento del relevamiento:
Total Ventas Creadas ($7.641,15) + Total Nota de Débito ($0,00) − Total Nota de Crédito ($447,70) = Total Ventas ($7.193,45)
Cantidad Prod./Serv. (19) / Cantidad Ventas Creadas (3) / Venta Promedio ($2.397,82) / Costo Actual ($2.510,00)
Precio Neto ($5.945,00) − Costo Mercadería Vendida ($0,00) = Resultado ($5.945,00)
Tabla de detalle con columnas: Id, Fecha, Comprobante, Cliente, Prod./Serv., Cant., Precio Unitario, Costo Total Actual, CMV Total, Precio Total Neto, Result., Total Comprobante (scrolleable horizontalmente). Al pie, botones &quot;Exportar Resumen&quot; y &quot;Exportar a PDF&quot;.
3.2 Panel de Filtros — 22 campos
Al hacer clic en &quot;Filtros&quot; se despliega un panel de 22 campos (más que los 20 relevados en una pasada anterior sobre cuenta vacía; esta vez aparecen además &quot;Remitos&quot;, &quot;Tipo y N° de Remito&quot; y &quot;Transportista&quot;):
Fila
Campos
1
Id · Producto/Servicio · Tipo de Producto/Servicio · Cliente
2
Productos · Facturado · Vendedor · Categoría de Venta
3
Proveedor · Etiqueta · Tipo y N° de Factura · Usuario
4
Nota Cliente · Nota Interna · Estado del Cobro · Tipo
5
Remitos · Tipo y N° de Remito · Transportista
Botón &quot;Buscar&quot; al final del panel.
3.3 Selector de rango &quot;Emisión&quot;
Dropdown con 9 opciones: Hoy, Ayer, Última Semana, Mes actual, Mes anterior, Últimos 30 días, Año actual, Desde - Hasta (rango personalizado) y Borrar filtro. Al elegir &quot;Año actual&quot; el rango pasa a &quot;1 Ene - 14 Ago&quot; y los totales se recalculan al instante (de 3 a 6 ventas, de $7.193,45 a $16.135,35 en Total Ventas).
Al elegir &quot;Desde - Hasta&quot; se abre un widget compuesto: a la izquierda dos calendarios mensuales contiguos (mes actual + mes siguiente) con navegación por flechas, y a la derecha — simultáneamente visible — la misma lista de 9 accesos rápidos (Hoy/Ayer/.../Borrar filtro) a modo de atajo. Los campos de texto &quot;14-08-2026&quot; (desde) y &quot;14-08-2026&quot; (hasta) sobre cada calendario permiten también tipear la fecha directamente.
3.4 Pestaña Rankings — tabla dinámica (pivot)
El dropdown &quot;Rankings&quot; ofrece 5 vistas: Clientes, Categorías, Productos, Tipo de Producto, Vendedores. Con datos reales cargados, cada vista renderiza una tabla dinámica plenamente funcional (a diferencia de una cuenta vacía, donde el área de arrastre no llega a inicializarse).
Al entrar a &quot;Ranking de Clientes&quot; con el rango Año actual, se ve una tabla cruzada con &quot;Clientes&quot; como fila y &quot;fecha de emisión → año → mes&quot; como columnas, más tres selectores de configuración arriba:
Mostrar Como — 8 opciones confirmadas una por una: Tabla, Tabla con Gráfico de Barras (minibarras dentro de cada celda, superpuestas al valor numérico), Mapa de Calor (intensidad de rojo proporcional al valor — probado: Consumidor Final $2.994,75 en rojo suave, Total Emisión 8 en rojo intenso), Mapa de Calor por Fila, Mapa de Calor por Columna, Gráfico de Líneas, Gráfico de Barras (gráfico de barras verticales a pantalla completa reemplazando la tabla), Histograma.
Dato — 4 opciones: Total Venta, Total Venta sin impuestos, Cantidad de Productos, Cantidad de Ventas.
Accion — hasta 7 opciones cuando Dato = &quot;Total Venta&quot;: Suma, Promedio, Mínimo, Máximo, Suma como Fracción del Total, Suma como Fracción por Línea, Suma como Fracción por Columna.
Hallazgo no documentado antes — Accion depende de Dato
El dropdown &quot;Accion&quot; no es estático: cuando se cambia &quot;Dato&quot; a un campo de conteo (&quot;Cantidad de Ventas&quot; o &quot;Cantidad de Productos&quot;), la lista de &quot;Accion&quot; se reduce a una sola opción (&quot;Suma&quot;) — las 6 agregaciones estadísticas (Promedio, Mínimo, Máximo, fracciones) desaparecen porque no tienen sentido sobre un conteo de filas. Al volver &quot;Dato&quot; a &quot;Total Venta&quot; reaparecen las 7 opciones completas. Se verificó también que &quot;Suma como Fracción del Total&quot; recalcula todas las celdas como porcentaje en tiempo real (ej.: Maria Emilia Lopez pasó de $6.382,75 a 39,6%).
Hallazgo — arrastrar y soltar dimensiones (drag &amp; drop) funciona
Se arrastró el chip &quot;Clientes&quot; desde el área de filas hasta el área de columnas (al lado de &quot;mes&quot;): la tabla se reconstruyó al instante, ahora con los 5 clientes como columnas y sólo una fila de &quot;Totales&quot;. Cada columna de cliente incorpora además un ícono de embudo (filtro) para excluir valores puntuales, y al hacer clic sobre el encabezado de una columna se activa un ordenamiento ascendente/descendente (flecha verde) sobre esa columna — comportamiento estándar de PivotTable.js.
Cada Ranking tiene su propio botón &quot;Exportar Excel&quot; (generado 100% del lado del cliente vía JavaScript, no pasa por el servidor — ver sección 11.7).
3.5 Pestaña &quot;Arma tu Informe&quot; — builder de tabla cruzada
Al elegir &quot;Crear Informe&quot; se abre un builder con los mismos tres selectores (Mostrar Como / Dato / Accion) que Rankings, pero en lugar de partir de una dimensión fija, muestra las 13 dimensiones disponibles como &quot;fichas&quot; sueltas en una zona de &quot;pool&quot; sin asignar:
fecha de emision · mes · año · categorías · clientes · tipos de factura · vendedores
productos · tipos de producto · proveedores · cantidades · descuento en % · etiquetas
Hallazgo — el builder es plenamente utilizable con datos reales
En una pasada anterior sobre cuenta vacía esta interacción había quedado sin documentar porque el área de chips no llega a renderizarse sin al menos un registro. Con datos reales se arrastró &quot;productos&quot; al área de filas y &quot;clientes&quot; al área de columnas: el resultado fue una tabla cruzada de 5 productos × 5 clientes con el Total Venta de cada combinación (ej.: &quot;(P024) Pantalon Negro Hombre Slim T32&quot; × &quot;Maria Emilia Lopez&quot; = $3.327,50) y fila/columna de Totales. Cualquier combinación de las 13 dimensiones es arrastrable a filas o a columnas, en cualquier orden, igual que en Rankings.
Botón verde &quot;Guardar informe&quot; arriba a la derecha. Al presionarlo se abre un modal &quot;Guardar Informe&quot; con un campo de texto &quot;Descripción&quot; y botones Cancelar / Guardar. Se guardó un informe de prueba con la descripción &quot;Productos x Clientes - prueba informe&quot;:
Hallazgo — los informes guardados quedan como pestañas persistentes
Al confirmar &quot;Guardar&quot;, el modal se cierra y la pestaña que antes decía &quot;Crear Informe&quot; cambia su etiqueta por el texto de la descripción ingresada (&quot;Productos x Clientes - prueba informe&quot;), quedando fijada en la barra de pestañas superior del Informe de Ventas junto a &quot;Informe de Ventas&quot; y &quot;Rankings&quot;. Es decir: cada tabla cruzada personalizada que se guarda se convierte en un acceso directo permanente dentro de ese informe, sin necesidad de reconfigurar filas/columnas cada vez.
4. Informe de Compras (/purchase_reports)
Misma estructura de 3 pestañas (Informe de Compras / Rankings / Arma tu Informe) y misma fórmula de KPIs (Total Compras Creadas + Nota de Débito − Nota de Crédito = Total Compras; Cantidad Prod./Serv., Cantidad Compras Creadas, Compra Promedio, Costo Actual). Con datos reales: $2.184,05 creadas − $121,00 de NC = $2.063,05 Total Compras, sobre 3 compras y 32 productos/servicios.
Tabla de detalle: Id, Fecha, Comprobante, Proveedor, Producto/Servicio, Cant., Precio, Total Comprobante. Botones &quot;Exportar&quot; (a diferencia de Ventas, acá el botón dice &quot;Exportar&quot; a secas, no &quot;Exportar Resumen&quot; — ver por qué en la sección 11.3) y &quot;Exportar a PDF&quot;.
4.1 Filtros — 12 campos
Id, Producto/Servicio, Tipo de Producto/Servicio, Etiqueta, Productos, Facturado, Categoría de Compra, Proveedor, Tipo y N° de Factura, Usuario, Observación, Estado del Pago. A diferencia de Ventas no tiene Cliente ni Vendedor; agrega &quot;Observación&quot; y &quot;Estado del Pago&quot;.
4.2 Rankings de Compras
Dropdown con 4 vistas (una menos que Ventas, sin equivalente a &quot;Vendedores&quot;): Proveedores, Categorías, Productos, Tipo de Producto. Se probó &quot;Ranking de Proveedores&quot; con datos reales: tabla cruzada Proveedor × mes con Distribuidora SRL, Taller Confección, Indumentaria Jazmin, Avellaneda Pantalones, Mariana Lanzini y Total $6.588,45 — misma estructura de pivot table con Mostrar Como / Dato / Accion y botón &quot;Exportar Excel&quot;.
5. Cuenta Corriente Clientes (/account_receivables)
5.1 Pestaña Saldos Clientes
Filtro simple por Cliente. Tabla con columnas: Cliente, A Vencer, y el desglose de Vencido en cuatro tramos de antigüedad (0 y 30, 31 y 60, 61 y 90, &gt;90), más columna Total (ordenable, con flecha). Con datos reales se ven 4 clientes, incluyendo un saldo negativo (Agustín Gómez: -$447,70, producto de una Nota de Crédito superior a su deuda). Selector &quot;Registros por página&quot; (10 por defecto). Botones Exportar / Exportar a PDF.
Hallazgo nuevo — ficha de cliente en modal
Al hacer clic sobre el nombre de un cliente (son enlaces en azul) se abre un modal &quot;Cliente&quot; con su ficha completa: Cliente, Nombre, Apellido, Email, Teléfono, Cel., Página Web, Domicilio, Localidad, Provincia, C.P., Condición de IVA, Comprobante por defecto, Nota. Es una vista de sólo lectura (no se ofrecen botones de edición dentro del modal) pensada para consultar rápidamente los datos de contacto sin salir del informe. Esta interacción no había podido probarse antes porque la cuenta vacía no tenía clientes cargados.
5.2 Pestaña Movimientos
Filtros por Cliente y por Operación, más selector de rango &quot;Emisión&quot; (mismas 9 opciones). Tabla con columnas: Id, Emisión (ordenable), Cliente, Operación, Categoría, Total Venta, Cobrado, A Cobrar, N° de Comprobante, Medio de Cobro, Descripción (scrolleable).
Hallazgo nuevo — menú de acciones por fila
Cada fila de Movimientos tiene un ícono desplegable (▾) a la izquierda del Id que abre un menú contextual con tres opciones: Ver, Editar, Eliminar. Es decir, este informe no es un listado de sólo lectura: funciona también como una pantalla de gestión desde la que se puede editar o borrar directamente el cobro/venta/nota de crédito subyacente, sin ir al módulo de Ingresos. Se verificó la existencia del menú; no se ejecutó Editar ni Eliminar para no alterar los datos de prueba usados en el resto del relevamiento.
Se observó además una celda resaltada en verde ($2.435,05 bajo &quot;A Cobrar&quot;) sobre la fila más reciente — un indicador visual de &quot;cambio recién ocurrido&quot; tras registrar un cobro nuevo.
6. Cuenta Corriente Proveedores (/account_payables)
Estructura idéntica a Clientes en espejo: pestañas &quot;Saldos Proveedores&quot; / &quot;Movimientos&quot;, mismo desglose de antigüedad de deuda (A Vencer / 0-30 / 31-60 / 61-90 / &gt;90 / Total), mismo modal de ficha al hacer clic en el nombre del proveedor, mismos botones de exportación. Con datos reales: 4 proveedores, incluyendo un saldo negativo en Distribuidora SRL (-$24,20) por la misma lógica de Nota de Crédito que en Clientes.
7. Reporte Final (/result_reports)
7.1 Ventas Vs. Compras (base devengado)
Banner informativo (descartable con ✕) que explica qué contempla el informe: Ventas (agrupadas por Categoría, incluye NC/ND), Otros Ingresos (agrupados por Categoría), Compras (agrupadas por Categoría, incluye NC/ND), Gastos (agrupados por Categoría y Subcategoría, incluye Pendientes).
Con datos reales (rango &quot;12 Jun - 14 Ago&quot;, auto-calculado): Total Ingresos $46.485,35, Total Egresos $14.157,45, Resultado $32.327,90. Al expandir &quot;Ingresos → Ventas&quot; se ve el desglose por categoría: Online $1.893,65, Mayorista $10.436,25, Local $3.805,45, con una columna &quot;Activo&quot; de checkboxes tildados por defecto junto a cada categoría.
Hallazgo mayor — simulador &quot;qué pasaría si&quot; en tiempo real
Destildar el checkbox &quot;Activo&quot; de una categoría (se probó con &quot;Online&quot;) excluye instantáneamente esos $1.893,65 del Total Ventas, del Total Ingresos y del Resultado general, sin recargar la página ni volver a buscar: Total Ingresos bajó de $46.485,35 a $44.591,70 y Resultado de $32.327,90 a $30.434,25 en el mismo momento del clic. Es una función de simulación real: permite responder &quot;¿cómo me hubiera ido este mes sin la categoría Online?&quot; sin tocar los datos reales ni exportar nada. No estaba documentada en relevamientos previos porque la cuenta vacía no tenía categorías con datos para tildar/destildar.
7.2 Cobros Vs Pagos (base caja)
Segunda pestaña, con su propio banner: Ventas Cobradas (cobros sobre ventas, por Categoría), Otros Ingresos (cobros del módulo Otros Ingresos), Compras Pagadas (pagos sobre compras, por Categoría), Gastos (pagos de gastos — a diferencia de &quot;Ventas Vs. Compras&quot;, acá los gastos Pendientes NO se contemplan, porque no implicaron salida real de dinero).
Con datos reales del mismo período: Total Ingresos $38.103,15, Total Egresos $10.863,30, Resultado $27.239,85 — sensiblemente más bajo que el devengado ($32.327,90) porque Ventas Cobradas ($7.753,15) es menor a Total Ventas ($16.135,35): hay ventas ya facturadas pero todavía no cobradas en su totalidad. Esto confirma en la práctica, con números reales, la distinción contable entre resultado devengado (Ventas Vs. Compras) y resultado percibido / flujo de caja real (Cobros Vs Pagos).
8. Informe de Gastos (/expenditure_reports)
El más simple de los 8. Filtros: Categoría y/o Subcategoría, Medio de pago, Estado del Pago, Usuario. Selector de rango &quot;Emisión&quot;.
Detalle del rango por defecto
Con &quot;Emisión&quot; en su valor por defecto (mes actual), el rango mostrado fue &quot;Desde 2026-08-01 / Hasta 26/08/2026&quot; — es decir, el filtro &quot;mes actual&quot; abarca el mes calendario completo (1 al 30/31), no se recorta a la fecha de hoy (14/08). Esto significa que &quot;mes actual&quot; en todos los informes de Contagram probablemente incluye fechas futuras dentro del mismo mes, no sólo lo transcurrido.
Bloque de totales: Desde / Hasta / Gasto Total ($1.370,00 en el período). Estructura en dos niveles: Categoría (ej. &quot;Impuestos&quot;, &quot;Oficina&quot;) → Subcategoría (&quot;IVA&quot;, &quot;Luz&quot;) expandible, cada una con su propia tabla de detalle (Id, Fecha, Descripción, Medio de Pago, Total) y su subtotal. Botones Exportar / Exportar a PDF.
9. Informe de Stock (/product_balance_reports)
9.1 Filtros — 6 campos
Usuario, Operación, Proveedor, Tipo de Producto, Productos, Estado del Producto. Selector de rango &quot;Emisión&quot; y enlace &quot;Video Explicativo&quot;.
9.2 KPIs con tooltips explicativos
Tres indicadores: Unidades en Stock (13, con datos reales), Costo Total (con ícono ⓘ — tooltip: &quot;Corresponde al total que surge de multiplicar el costo de los productos (asignado en la base de datos) por la cantidad de unidades en stock disponibles&quot;), y Valor Venta Total (tooltip análogo con el precio de venta).
Hallazgo — KPIs negativos con datos reales
Con la cuenta de prueba cargada, Costo Total se mostró en $-660,00 (rojo) y Valor Venta Total en $-1.020,00 (verde, por convención de color de la propia UI aunque el valor sea negativo). Es decir, el cálculo puede arrojar resultados negativos cuando el costo/precio asignado a un producto fue modificado después de haberse registrado ventas/compras con un costo distinto — la fórmula multiplica el costo *actual* por el saldo de stock, no el costo histórico de cada movimiento, así que un producto con stock bajo o negativo y costo actual alto puede arrastrar el total a negativo. Es un comportamiento reproducible del propio motor de cálculo, no un error de captura.
9.3 Selector de columnas y tabla de movimientos
Ícono de columnas arriba a la derecha abre un menú con checkboxes: ID, Fecha, Usuario (desmarcado por defecto), Operación, Detalle, Cantidad, Stock Saldo. Tabla de detalle scrolleable, paginada (17 resultados en el período de prueba, 2 páginas de 10).
Hallazgo — operación &quot;Nota de Crédito Eliminada&quot;
Entre los tipos de &quot;Operación&quot; que aparecen en el historial real (no documentados antes) está &quot;Nota de Crédito Eliminada (Venta)&quot;, con cantidades en -0 o -1: cuando se elimina una Nota de Crédito ya cargada, el sistema no borra el rastro del movimiento de stock sino que agrega una contra-entrada explícita para revertirlo, dejando trazabilidad completa de altas, bajas y reversiones sobre cada producto.
10. Rankings — vista consolidada (/graphs)
A diferencia del acceso a Rankings dentro de cada informe individual, ésta es una pantalla dedicada con 4 paneles de gráfico apilados verticalmente, cada uno con un enlace directo &quot;Arma tu Informe&quot; en la esquina superior derecha para saltar al builder correspondiente ya filtrado por esa dimensión:
Ranking de Productos
Ranking de Clientes
Ranking de Categorías
Rankings de Tipo Producto
Precisión sobre el tipo de gráfico
Con datos reales se confirmó que estos 4 paneles no son gráficos de barras verticales clásicos por ranking, sino barras horizontales apiladas a lo largo del eje temporal del período (&quot;01/08/2026 - 14/08/2026&quot;): cada producto/cliente/categoría/tipo aparece como un segmento de color distinto posicionado en el tramo de fechas donde tuvo actividad, más una leyenda de referencia debajo. Es más un gráfico de tipo Gantt/comparación temporal que un ranking de barras tradicional — un matiz que no se podía apreciar sin datos reales, ya que sobre cuenta vacía el eje Y sólo mostraba una escala fija de 0 a 5/7 sin barras.

11. Lógica interna revelada por las exportaciones Excel
A pedido explícito, se descargaron y analizaron a nivel binario (librería openpyxl, sin fórmulas — todos los .xlsx exportados traen valores ya calculados, no fórmulas de Excel) los archivos generados por los botones &quot;Exportar&quot; / &quot;Exportar Excel&quot; de seis informes distintos. El objetivo fue reconstruir el modelo de datos y las reglas de cálculo reales del backend, útiles para replicar la lógica en otra aplicación.
11.1 Metodología
Se descargaron 7 archivos: Informe de Ventas (Exportar Resumen), Informe de Compras (Exportar), Reporte Final — ambas pestañas (Exportar), Informe de Gastos (Exportar), Informe de Stock (Exportar) y Ranking de Clientes (Exportar Excel, generado 100% en el navegador). Cada archivo se abrió con openpyxl en modo data_only=False y se inspeccionaron todas las hojas, encabezados y filas para comparar contra lo mostrado en pantalla.
11.2 Ventas — inconsistencia real en el cálculo de &quot;Resultado&quot; para Notas de Crédito
El Excel &quot;Informe de Ventas Resumen&quot; reproduce en una hoja los mismos 3 bloques de KPIs de la pantalla y después una tabla de detalle con columnas Id, Emisión, Cliente, Tipo de Comprobante, Producto/Servicio, Cantidad, Precio Unitario, Costo Total Actual, CMV Total, Precio de Venta, Resultado, Total Venta.
Comparando fila por fila contra la tabla en pantalla surge lo siguiente:
Fila (Id / Tipo)
Precio de Venta
CMV Total
Resultado en pantalla
Resultado en el Excel
Fórmula esperada (Precio − CMV)
Id 6 / Venta
370,00
200,00
170,00
170,00
170,00 ✓ coincide
Id 4 / Nota de Crédito
-370,00
-200,00
-170,00
-570,00
-170,00 ✗ NO coincide
Conclusión técnica
Para las filas de venta normal, Resultado = Precio de Venta − CMV Total, y esa fórmula reproduce exactamente lo que se ve en pantalla (170 = 370 − 200). Para la fila de Nota de Crédito, el valor exportado (-570) no sale de esa resta (-370 − (-200) = -170, que sí coincide con lo mostrado en pantalla), sino de una suma directa de columnas (-370 + -200 = -570). Todo indica que el código que genera este Excel usa, para las filas de Nota de Crédito, una rama de cálculo distinta a la que alimenta la tabla HTML de la pantalla — probablemente resta el CMV con el signo equivocado o directamente suma en vez de restar cuando el comprobante es de tipo &quot;NC&quot;. El error queda contenido en esa única celda del detalle línea por línea: no se propaga a los totales agregados (el KPI &quot;Resultado&quot; de $5.945,00 en pantalla sí coincide con la suma de la columna &quot;Precio de Venta&quot; del Excel, 5.945,00 exactos), así que es un bug acotado y silencioso, útil de tener en cuenta si se replica esta lógica: la fórmula &quot;Resultado = Precio − CMV&quot; debe aplicarse por igual a ventas y notas de crédito, respetando el signo de cada columna, sin ramas especiales.
11.3 Compras — el Excel expone el modelo de datos impositivo completo (AFIP), oculto en pantalla
La tabla en pantalla del Informe de Compras sólo muestra 8 columnas (Id, Fecha, Comprobante, Proveedor, Producto/Servicio, Cant., Precio, Total Comprobante). El botón &quot;Exportar&quot; (a diferencia de &quot;Exportar Resumen&quot; en Ventas, acá no hay versión resumida) vuelca 35 columnas por línea, revelando que cada compra guarda internamente el detalle impositivo argentino completo:
Grupo
Columnas reales en el Excel (no visibles en pantalla)
Identificación / comprobante
Id, Fecha, Vencimiento, Categoría, Proveedor, CUIT/DNI, Tipo, Tipo de Comprobante, Punto de Venta, N° Factura
Producto
Producto/Servicio, Código, Tipo (&quot;Compra y Venta&quot; / &quot;Fabricado&quot;), Cantidad, Costo, Precio unitario
Subtotales
Subtotal sin Descuento, Descuento en $, Subtotal con Descuento
Base imponible
Importe Neto No Gravado, Importe Neto Exento, Importe Neto Gravado
IVA por alícuota
IVA - 2,5% · IVA - 5% · IVA - 10,5% · IVA - 21% · IVA - 27% (columnas separadas, una por alícuota)
Otros conceptos AFIP
Exento, No Gravado, Perc. IVA, Perc. IIBB, Imp. Internos
Cierre
Total Compra, Etiquetas, Afecta Stock (Si/No)
Se verificó además que, a diferencia de Ventas, en Compras el Excel SÍ coincide exactamente con la pantalla incluso en la fila de Nota de Crédito: Total Compra = -121,00 = Subtotal con Descuento (-100,00) + IVA 21% (-21,00), igual que &quot;Total Comprobante&quot; en pantalla. No se detectó el mismo bug que en Ventas.
El link de exportación (&quot;/purchase_reports/export.js?q[issue_date_gteq]=2026-08-01&quot;) usa la sintaxis de parámetros q[campo_operador]=valor propia de la gema Ransack de Ruby on Rails, lo que confirma con bastante certeza que el backend de Contagram está construido en Ruby on Rails con Ransack para el filtrado de los informes — un dato útil de arquitectura si se quiere replicar el mismo patrón de filtros dinámicos vía query string.
11.4 Reporte Final — jerarquía real de 4-5 niveles y convención de signos
En pantalla, &quot;Ventas Vs. Compras&quot; y &quot;Cobros Vs Pagos&quot; sólo muestran, al expandir, el nivel Categoría (ej. &quot;Local&quot;, &quot;Mayorista&quot;). El Excel exportado de cada pestaña revela un nivel adicional no visible en la interfaz: dentro de cada Categoría, el monto se desglosa además por Cuenta de Tesorería (medio de cobro/pago).
Jerarquía real reconstruida a partir del archivo &quot;Informe Final&quot; de la pestaña Cobros Vs Pagos:
Ingresos → Ventas Cobradas → Categoría (Local / Mayorista) → Cuenta de Tesorería (Caja del Local, Caja General, Banco Galicia, Banco Santander Río, Mercado Pago, AMEX, VISA, Cheque de Terceros) → monto
Ingresos → Otros Ingresos → Categoría (Préstamos Financieros, Saldo, Aportes Socios) → misma lista de 8 cuentas de tesorería → monto
Egresos → Compras Pagadas → Categoría (Insumos, Otras Categorías, Productos Terminados, Mano de Obra) → Cuenta de Tesorería, pero con un set DISTINTO de 8 cuentas (reemplaza AMEX/VISA personales por Cheque Propio y VISA Corporativa) → monto
Egresos → Gastos → Categoría → Subcategoría (ej. Oficina→Internet, Oficina→Alquiler, Impuestos→IVA) → Cuenta de Tesorería → monto (un nivel más que en Ventas/Compras, por la Subcategoría)
Convención de signos detectada (relevante para replicar el cálculo)
En la pestaña &quot;Ventas Vs. Compras&quot;, &quot;Total Egresos&quot; se guarda con signo NEGATIVO en la celda (-14.157,45) y Resultado = Total Ingresos + Total Egresos (suma directa: 46.485,35 + (-14.157,45) = 32.327,90). En cambio en &quot;Cobros Vs Pagos&quot;, &quot;Total Egresos&quot; se guarda POSITIVO (10.863,30) y Resultado = Total Ingresos − Total Egresos (resta: 38.103,15 − 10.863,30 = 27.239,85). Dentro de esta última, los subtotales por bloque (&quot;Total Compras Pagadas&quot;, &quot;Total Gastos&quot;) sí llevan signo negativo (-3.493,30 y -7.370,00) aunque cada línea de cuenta de tesorería individual es positiva. Si se replica esta lógica en otra aplicación conviene unificar la convención de signos (por ejemplo, todo en positivo con una bandera de tipo ingreso/egreso) para evitar el doble estándar que tiene Contagram entre sus dos sub-informes.
11.5 Gastos — el Excel trae dos hojas: una &quot;para leer&quot; y una &quot;para procesar&quot;
El archivo &quot;Informe de Gastos&quot; exportado trae dos hojas dentro del mismo libro:
&quot;Informe de Gastos&quot;: réplica visual de la pantalla, con encabezados de Categoría y Subcategoría como títulos de sección, sub-tablas independientes (Id, Fecha, Subcategoría, Descripción, Medio de pago, Total) y subtotales intercalados — pensada para imprimir o mirar.
&quot;Gastos&quot;: una tabla plana única, sin agrupar, con columnas Id, Fecha, Categoría, Subcategoría, Descripción, Medio de pago, Total y una fila por gasto — pensada para reprocesar en otra herramienta (Excel, Python, Power BI, etc.) sin tener que parsear la estructura jerárquica de la primera hoja.
Este patrón de &quot;doble hoja&quot; (una formateada para humanos + una plana para máquinas) es un buen patrón de diseño a copiar si se replica la exportación: evita que quien consuma el archivo programáticamente tenga que lidiar con celdas combinadas y encabezados de sección.
11.6 Stock — columnas adicionales y saldo corrido
La tabla en pantalla muestra 7 columnas configurables (ID, Fecha, Usuario, Operación, Detalle, Cantidad, Stock Saldo). El Excel exportado trae 13: ID, Fecha, Usuario, Operación, Descripción, Tipo de Factura, N° de Factura, Código, Producto, Tipo de Producto, Proveedor, Cantidad, Saldo Stock. Es decir, &quot;Tipo de Factura&quot;, &quot;N° de Factura&quot;, &quot;Código&quot; (de producto), &quot;Tipo de Producto&quot; y &quot;Proveedor&quot; existen en el modelo de datos de cada movimiento de stock pero no están disponibles como columnas activables desde el selector de columnas de la pantalla — sólo se pueden ver exportando.
La columna &quot;Saldo Stock&quot; es un saldo corrido (running balance) calculado fila a fila en el mismo orden en que aparecen los movimientos en el reporte. Como el orden por defecto es de fecha más reciente a más antigua, el saldo mostrado en las filas superiores no representa el stock histórico real en ese momento sino el resultado de recorrer la lista tal como está ordenada — se observaron saldos intermedios negativos (-12, -5, -2) sobre movimientos de Musculosa Londres y Pantalón Negro que, leídos en orden cronológico real, nunca estuvieron en stock negativo. Al replicar este cálculo conviene decidir explícitamente si el saldo corrido se calcula sobre orden cronológico ascendente (para que tenga sentido histórico) o sobre el orden de visualización (como hace hoy Contagram).
11.7 Rankings / pivot — exportación 100% client-side
El botón &quot;Exportar Excel&quot; de Rankings y de &quot;Arma tu Informe&quot; no dispara ninguna petición al servidor (a diferencia de los otros 6 exports, todos con URL a un endpoint .js del backend): su enlace es &quot;javascript:void(0)&quot; y genera el archivo enteramente en el navegador a partir de los datos ya cargados en la tabla dinámica — consistente con que PivotTable.js incluye su propio exportador a XLSX (basado en SheetJS) para no depender de una ida y vuelta al servidor. El archivo resultante (&quot;Ranking de Clientes 14-8-2026.xlsx&quot;, hoja &quot;Hoja 1&quot;) es un volcado directo y fiel de la tabla cruzada tal como se ve en pantalla en ese momento (mismos encabezados de año/mes, mismos totales), sin columnas adicionales ocultas — a diferencia de Compras o Stock, acá no hay nada extra por descubrir porque el Excel y la pantalla comparten el mismo dataset en memoria del navegador.
11.8 Síntesis de arquitectura técnica inferida
Backend probablemente Ruby on Rails, con filtros de informes vía Ransack (parámetros q[campo_operador]=valor visibles en las URLs de exportación).
Los exports &quot;pesados&quot; (Ventas, Compras, Reporte Final, Gastos, Stock) son server-side: generan el .xlsx en el backend y lo entregan con cabecera de descarga adjunta (attachment), por lo que el navegador los guarda directo sin preguntar dónde.
Las tablas dinámicas (Rankings / Arma tu Informe) están montadas sobre PivotTable.js (confirmado por inspección del DOM en un relevamiento anterior), con export a Excel resuelto 100% en el cliente vía SheetJS — de ahí que no dependan de filtros de backend adicionales para exportar exactamente lo que se ve en pantalla.
El modelo de datos subyacente es más rico que la UI expuesta: hay campos con desglose impositivo completo (Compras), cuentas de tesorería cruzadas por categoría (Reporte Final) y atributos de producto/comprobante (Stock) que sólo aparecen en las exportaciones, nunca en pantalla ni en el selector de columnas — importante tenerlo en cuenta si el objetivo es replicar la cobertura completa de datos, no sólo lo que se ve a simple vista en la interfaz.
Los valores dentro de los .xlsx exportados son siempre valores calculados (no hay una sola fórmula de Excel real en ninguno de los 7 archivos revisados): todo el cálculo se resuelve antes de escribir el archivo, ya sea en el servidor o en el navegador.

12. Observaciones y hallazgos clave (resumen)
Los 8 informes comparten una arquitectura de UI común (Emisión de 9 opciones, panel de Filtros, tabla scrolleable, export dual Excel+PDF), lo que hace que aprender uno enseñe prácticamente a los ocho.
Ventas y Compras están construidos sobre PivotTable.js: 8 modos de &quot;Mostrar Como&quot;, 4 &quot;Dato&quot; y hasta 7 &quot;Accion&quot; (menos cuando el Dato es un conteo), con arrastre de dimensiones plenamente funcional y guardado de vistas personalizadas como pestañas persistentes.
Reporte Final incorpora un simulador &quot;qué pasaría si&quot; en tiempo real (checkboxes &quot;Activo&quot; por categoría) que recalcula Ingresos/Egresos/Resultado al vuelo — la función más potente y menos evidente de todo el módulo.
Cuenta Corriente (Clientes y Proveedores) no es sólo de lectura: tiene fichas de contacto en modal y un menú Ver/Editar/Eliminar por movimiento.
El análisis de las exportaciones Excel reveló que el modelo de datos real es sensiblemente más rico que lo expuesto en pantalla (impuestos AFIP completos en Compras, cuentas de tesorería cruzadas en Reporte Final, atributos extra en Stock), y detectó una inconsistencia puntual y acotada en el cálculo de &quot;Resultado&quot; para líneas de Nota de Crédito en el Excel de Ventas.
Se generaron y guardaron 30 capturas (.gif) de cada pantalla, modal e interacción relevante, disponibles en la carpeta Capturas/ dentro de esta misma carpeta del informe (ver nota en la portada).

