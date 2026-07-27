# Informe Contagram — Inicio (Dashboard), Informes, Ajustes Generales y Navegación Restante

**Alcance de este informe:** módulo Inicio/Dashboard, módulo Informes (los 8 tipos de reporte + constructor de informes personalizados), sección Ajustes (Mi Perfil, Mi Plan, Usuarios y Permisos, Importar Datos), elemento "Contagram 2.0 BETA", y elementos de navegación superior (menú de usuario, notificaciones, Ayuda, banner de suscripción, botón "Crear Cuenta Real").

**Rango de capturas:** `163` a `192` (carpeta `capturas_contagram/`).

**Nota sobre exclusión de IA:** por instrucción explícita del usuario, este informe **no cubre** funcionalidades de Inteligencia Artificial (ej. "Analizar con IA" en Ventas, "Buscar Precios con IA" en Productos, IA para ventas sin stock), ya documentadas de forma incidental en informes anteriores pero fuera del alcance de análisis profundo aquí.

---

## 1. Inicio (Dashboard)

Ruta: `/dashboard/index`. Es la pantalla de aterrizaje al iniciar sesión.

### 1.1 Encabezado y KPIs superiores `[163]`

Cuatro tarjetas de indicadores en la fila superior:

- **Ventas Creadas**: monto total del período, con variación porcentual vs. mes anterior (flecha verde/roja).
- **Venta Promedio**: ticket promedio, con variación vs. mes anterior.
- **Cantidad de Ventas**: número de operaciones, con variación.
- **Resultado**: ganancia neta del período (ventas + otros ingresos − compras − gastos), con variación.

Cada tarjeta usa color verde para variación positiva y rojo para negativa, con el símbolo de flecha correspondiente.

### 1.2 Panel de totales y gráfico combinado `[163][164]`

A la izquierda, cuatro totales apilados con barra de progreso de color:
- **Total Ventas** (verde)
- **Total Otros Ingresos** (azul)
- **Total Compras** (rojo)
- **Total Gastos** (amarillo)

A la derecha de estos totales, un **gráfico de barras apiladas mensual** (uno de los pocos gráficos no basados en IA) que compara Ventas/Otros Ingresos/Compras/Gastos mes a mes en los últimos ~12 meses, con leyenda inferior de colores y ejes con montos en pesos.

### 1.3 Panel de Tesorería resumido `[163]`

A la derecha del todo: **Total Disponible**, **Total Cajas**, **Total Bancos** (íconos de color azul/amarillo/rojo), seguido de una mini-tabla de **movimientos recientes** (Fecha / Cuenta / Monto, con signo + o −) — refleja directamente los últimos movimientos registrados en Tesorería (se ven aquí los movimientos de prueba creados en el informe de Tesorería: transferencia a "Caja Chica Prueba").

### 1.4 Cuentas a cobrar y a pagar `[164]`

Dos bloques idénticos en estructura, uno para **Total Ventas a Cobrar** y otro para **Total Compras a Pagar**:
- Monto total destacado con ícono de gráfico de barras (verde para cobrar, rojo para pagar).
- Mini gráfico de barras de evolución.
- Desglose por antigüedad de deuda: **A Vencer**, **Vencido**, **0 a 30**, **31 a 60**, **61 a 90**, **+ de 90** días, cada uno con su monto — un aging report clásico de cuentas corrientes.

### 1.5 Ventas Totales con selector de período `[164]`

Debajo, sección "Ventas Totales" con pestañas de rango: **Última Semana / Mes Actual / Mes Anterior / Año Actual**. Cambia el gráfico y los KPIs superiores según el período elegido (no se probaron todos los rangos para no alterar el estado del panel, pero el patrón de filtro es el mismo usado en Informes).

### 1.6 Donas de composición (Ventas / Compras / Gastos por categoría) `[164][165]`

Tres gráficos de dona (pie chart) que muestran la distribución porcentual de:
- **Ventas por categoría**
- **Compras por categoría**
- **Gastos por categoría**

Cada dona tiene su leyenda de colores con el nombre de categoría y porcentaje al costado o al pie `[166]`.

### 1.7 Rankings en el Dashboard `[165]`

Sección de rankings rápidos embebidos en el Inicio, replicando en miniatura lo que se ve en el módulo Informes → Rankings:
- **Ranking de Clientes** (top clientes por monto vendido)
- **Ranking de Productos** (top productos más vendidos)

Presentados como listas ordenadas con montos, sin necesidad de entrar al módulo Informes completo.

### 1.8 Banner de estado de cuenta de prueba

Persistente en la parte superior de **toda la aplicación** (no solo Inicio): franja roja con el texto *"Tu período de prueba finaliza en 6 días, puedes confirmar tu suscripción desde **aquí**"*. El enlace "aquí" lleva presumiblemente a la sección de facturación/checkout de suscripción (no se hizo clic para evitar iniciar un flujo de pago real). Este banner es dinámico: el conteo de días baja a medida que se acerca el vencimiento del trial.

---

## 2. Informes

Ruta: `/reports` (menú lateral "Informes"). Landing con **8 tarjetas de tipos de informe** `[167]`:

1. Informe de Ventas
2. Informe de Compras
3. Cuenta Corriente Clientes
4. Cuenta Corriente Proveedores
5. Reporte Final (Ventas vs. Compras)
6. Informe de Gastos
7. Informe de Stock
8. Rankings

Cada tarjeta lleva a una vista de informe con su propio conjunto de filtros, tabla de datos y, en varios casos, un **constructor de pivot tables** personalizado. A continuación el detalle de cada uno.

### 2.1 Informe de Ventas `[168]`

Vista completa con:
- Selector de rango de fechas y botón **Filtros** (abre panel lateral con campos adicionales: cliente, categoría, vendedor, producto, tipo de comprobante — capturados vía árbol de accesibilidad cuando la captura visual falló intermitentemente).
- Tabla principal con columnas de detalle de ventas.
- KPIs resumen en la parte superior (total vendido, cantidad de operaciones, promedio).
- Pestaña **Rankings** `[169]` con submenú desplegable de vistas prearmadas: **Ranking de Clientes**, **Ranking de Categorías**, **Ranking de Productos**, **Ranking de Tipo de Producto**, **Ranking de Vendedores**.
- Al elegir "Ranking de Clientes" se muestra una **tabla pivot** con clientes en filas y montos/cantidades en columnas, ordenable `[170]`.
- Selector **"Mostrar Como"** con opciones de visualización `[171]`: Tabla, Tabla con Gráfico de Barras, Mapa de Calor, Mapa de Calor por Fila, Mapa de Calor por Columna, Gráfico de Líneas — permite cambiar la representación visual del mismo dato pivotado sin rehacer la consulta.
- Botón/sección **"Arma tu Informe"** que abre el **constructor de informes personalizados** ("Crear Informe") `[172]`: interfaz de tipo BI con chips de dimensiones arrastrables (cliente, producto, categoría, fecha, vendedor, etc.) para armar tablas cruzadas a medida, más selector de tipo de visualización igual al de Rankings. Es la herramienta más avanzada de todo el módulo de Informes — permite combinar libremente dimensiones y métricas sin necesidad de programar.

### 2.2 Informe de Compras `[173]`

Misma estructura que Ventas pero con datos de compras: filtros, tabla de detalle, KPIs, y presumiblemente el mismo patrón de Rankings/Arma tu Informe disponible (no se repitió la exploración completa del builder por ser funcionalmente idéntica a la de Ventas).

### 2.3 Cuenta Corriente Clientes `[174][175][176]`

- Vista **Saldos**: tabla con saldo actual por cliente (a favor/en contra), acumulado histórico `[174]`.
- Al hacer clic en un cliente se abre un **modal de ficha** con el detalle de su cuenta corriente (documentos pendientes, saldo) `[175]`.
- Pestaña **Movimientos**: listado cronológico de todos los movimientos de cuenta corriente (ventas, cobros, notas de crédito/débito) que generaron variación de saldo `[176]`.

### 2.4 Cuenta Corriente Proveedores `[177]`

Estructura espejo de Clientes: vista de **Saldos** por proveedor (deuda propia hacia cada proveedor), mismo patrón de columnas y totales.

### 2.5 Reporte Final — Ventas Vs. Compras `[178]`

Informe comparativo directo que enfrenta el total de Ventas contra el total de Compras en el período seleccionado, con diferencia neta — un resumen ejecutivo rápido de rentabilidad bruta.

### 2.6 Informe de Gastos `[179]`

Desglose de gastos por categoría, con montos y porcentajes, en formato tabla y/o gráfico según selector — replica el patrón general de filtros + tabla + visualización de los demás informes.

### 2.7 Informe de Stock `[180]`

Reporte de movimientos e inventario: entradas, salidas, ajustes de stock por producto, con saldo de stock resultante. Complementa el informe de stock ya visto en el detalle de Productos (Base de Datos).

### 2.8 Rankings (vista consolidada) `[181][182]`

Además del acceso a Rankings desde dentro de cada informe individual, existe una vista dedicada de Rankings con múltiples paneles simultáneos:
- Ranking de Productos y Ranking de Clientes lado a lado `[181]`.
- Ranking de Categorías y Ranking de Tipo de Producto lado a lado `[182]`.

Cada panel es una mini tabla ordenada de mayor a menor con el top de resultados del período.

**Patrón general del módulo Informes:** todos los informes comparten la misma arquitectura — selector de fechas, panel de Filtros, tabla de datos base, acceso a Rankings prearmados, y (en Ventas al menos) el constructor "Arma tu Informe" tipo pivot table con múltiples formas de visualización (tabla, barras, mapas de calor, líneas). Es la funcionalidad de Business Intelligence más sofisticada de Contagram.

---

## 3. Ajustes Generales

Accesible desde el ícono de engranaje en la barra superior o desde el menú lateral "Ajustes", con submenú: Mi Perfil, Mi Plan, Usuarios y Permisos, Importar Datos, Funciones Avanzadas (ya cubierto en informe anterior).

### 3.1 Mi Perfil `[183]`

Formulario con los datos personales/de la empresa del usuario logueado: nombre, apellido, email, teléfono, y datos fiscales/de facturación de la cuenta. Incluye opción para configurar los datos que aparecen impresos en los comprobantes (se intentó capturar el modal "Configurar mis datos en los Comprobantes"; ante fallas intermitentes de captura visual se relevó la lista de campos vía árbol de accesibilidad).

### 3.2 Mi Plan `[184][185]`

- Parte superior `[184]`: KPIs de uso del plan actual y contador de **días de prueba restantes** (coincide con el banner rojo global).
- **Tabla comparativa de precios** `[185]` con **4 planes** disponibles, cada uno con su lista de funcionalidades incluidas y precio mensual. Es la misma tabla de precios donde se confirmó que un **usuario adicional cuesta AR$30.000/mes**, valor consistente en los 4 planes (dato relevante detectado al intentar crear un usuario nuevo en la sección siguiente).

### 3.3 Usuarios y Permisos `[186][187]`

- Vista inicial: listado **vacío** de usuarios adicionales (solo existe el usuario principal "alberto rodriguez") `[186]`.
- Al intentar crear un nuevo usuario, aparece un **modal de confirmación** que advierte explícitamente que la acción: (a) agregará un cargo recurrente de facturación (AR$30.000/mes), y (b) enviará una invitación por email real al nuevo usuario `[187]`.
- **Se decidió no completar esta acción** (se hizo clic en "Cancelar" en lugar de "Aceptar"), dado que implica un cambio de facturación y el envío de un correo real a un tercero — ambas acciones requieren permiso explícito del usuario según las políticas de seguridad aplicadas en esta sesión. Se documenta el flujo hasta el punto de la confirmación, sin ejecutarlo.

### 3.4 Importar Datos `[188]`

Herramienta de carga masiva vía archivo, con pestañas por tipo de entidad: **Clientes**, Proveedores, Productos, Servicios (mismo patrón visto en la captura `28` para Clientes individualmente, pero esta es la vista centralizada de Ajustes).

- Botón "Seleccionar Archivo" con formatos permitidos: .xls, .xlsx, .csv.
- Columna izquierda "Acerca de la importación": explica el proceso paso a paso (subir archivo → vista previa → mapeo de columnas → importar, con opción de cancelar la importación después).
- Columna derecha "Notas Técnicas": recomienda importar primero Proveedores antes que Productos si se quiere vincular producto-proveedor; detalla los campos por defecto soportados (Nombre, Apellido, Teléfono, Dirección, Correo Electrónico, con opción de "personalizar" para agregar más).
- Panel "Cómo Importar" con video tutorial embebido y enlace "Tips Para Importar".
- Botón "Ver mis Clientes" para saltar directo al listado sin importar.

---

## 4. Navegación superior y elementos restantes

### 4.1 Menú de usuario ("alberto rodriguez ▾") `[191]`

Desplegable en la esquina superior derecha con dos opciones:
- **Auditoría** — presumiblemente un log de acciones/cambios realizados en la cuenta (no explorado en profundidad para no generar ruido adicional en el registro de auditoría de la cuenta de prueba).
- **Cerrar sesión** — cierra la sesión actual (no ejecutado).

### 4.2 Notificaciones (ícono de campana) `[190]`

Panel desplegable "Notificaciones" con badge numérico (mostraba "1" pendiente). Contenido de ejemplo observado: aviso del sistema tipo changelog — *"Aviso de Stock Insuficiente en Ventas"*, anunciando una mejora de producto (alerta de stock insuficiente durante la venta), fechado "hace casi 2 años". Es un canal de comunicación de novedades/anuncios de la plataforma hacia el usuario, no notificaciones transaccionales de la cuenta específica.

### 4.3 Ayuda

Enlace en la barra superior (junto al ícono de "?") que dirige al centro de ayuda de Contagram (`/help_center` según el árbol de accesibilidad). No se navegó en profundidad por tratarse de contenido de soporte externo al producto en sí, pero se confirma su presencia y destino como parte del mapeo de navegación.

### 4.4 Chat de soporte (Intercom)

Burbuja de chat flotante en la esquina inferior derecha (visible en casi todas las capturas), correspondiente a un widget de **Intercom** para soporte en vivo — visto también en la página de waitlist de Contagram 2.0 con el agente "Patricio".

### 4.5 "Crear Cuenta Real" `[192]`

Botón verde destacado en la parte superior del menú lateral, por encima de "Inicio", visible en toda la aplicación mientras la cuenta esté en modo prueba (el encabezado de la barra lateral dice "Cuenta de Prueba"). Es el **call-to-action de conversión de cuenta de prueba a cuenta paga**. No se hizo clic para completarlo: se trata de una acción irreversible de cambio de tipo de cuenta y facturación, fuera del alcance de una exploración de solo documentación y que requeriría permiso explícito del usuario.

### 4.6 Contagram 2.0 (BETA) `[189]`

Ítem del menú lateral, con etiqueta "BETA" en violeta. Al hacer clic **abre una pestaña nueva** hacia `/beta_waitlist` — **no es una funcionalidad de IA**, sino un formulario de lista de espera/feedback para la futura versión 2.0 de la plataforma:
- Título: "Completá tus datos — Sé uno de los primeros en probar el nuevo CONTAGRAM 2.0".
- Campos: Nombre y Apellido, Mail, Teléfono (prellenados con los datos de la cuenta de prueba).
- Selector de "Condición de cliente": Soy cliente / No soy cliente.
- Textarea abierta: "¿Qué funcionalidad o cambio esperas ver en la versión 2.0?".
- Widget de chat Intercom visible también en esta página externa.

No se envió el formulario (hubiera implicado enviar datos de contacto y feedback en nombre del usuario sin su confirmación explícita).

---

## 5. Observaciones y hallazgos relevantes

- El módulo **Informes** es, con diferencia, la herramienta analítica más potente de Contagram: el constructor "Arma tu Informe" permite pivotear libremente cualquier combinación de dimensiones (cliente, producto, categoría, vendedor, fecha) con múltiples formas de visualización (tabla, barras, mapas de calor, líneas), sin necesidad de programar ni exportar a Excel.
- El **Dashboard de Inicio** funciona como un resumen ejecutivo que reutiliza los mismos datos de Ventas/Compras/Gastos/Tesorería vistos en sus módulos respectivos, más un aging report de cuentas a cobrar/pagar por antigüedad de deuda (0-30/31-60/61-90/+90 días) — útil para gestión de cobranzas y pagos.
- **Costo de usuarios adicionales**: confirmado en AR$30.000/mes, igual en los 4 planes de precios — dato relevante para decisiones de expansión del equipo dentro de la plataforma.
- **Seguridad de acciones sensibles**: Contagram interpone confirmaciones explícitas antes de acciones con impacto de facturación (alta de usuario) y antes de activar integraciones que requieren "cuenta definitiva" (Facturación Electrónica, Tiendanube), evitando que un usuario de cuenta de prueba dispare cargos o compromisos sin darse cuenta.
- El período de prueba de esta cuenta vence en **6 días** desde la fecha de esta sesión (24/07/2026) — el banner rojo persistente lo recuerda en toda la app.
- Todas las funcionalidades de Inteligencia Artificial detectadas durante todo el relevamiento (Analizar con IA en Ventas, Buscar Precios con IA en Productos, sugerencia de IA para ventas sin stock) fueron **excluidas** de este informe por instrucción explícita, aunque se mencionan aquí solo a título de referencia de dónde aparecen en la interfaz.

---

## 6. Índice de capturas (este informe)

| Captura | Descripción |
|---|---|
| 163 | Dashboard Inicio — KPIs superiores y gráfico combinado |
| 164 | Inicio — Ventas Totales, cuentas a cobrar/pagar, donas de categoría |
| 165 | Inicio — Rankings de Clientes/Productos, donas Compras/Gastos |
| 166 | Inicio — leyendas de donas Compras y Gastos por categoría |
| 167 | Informes — menú principal con 8 tipos de informe |
| 168 | Informe de Ventas completo |
| 169 | Informe de Ventas — dropdown Rankings |
| 170 | Ranking de Clientes — tabla pivot |
| 171 | Dropdown "Mostrar Como" — tipos de visualización |
| 172 | Informe de Ventas — builder "Arma tu Informe" |
| 173 | Informe de Compras completo |
| 174 | Cuenta Corriente Clientes — Saldos |
| 175 | Cuenta Corriente Clientes — modal ficha cliente |
| 176 | Cuenta Corriente Clientes — Movimientos |
| 177 | Cuenta Corriente Proveedores — Saldos |
| 178 | Reporte Final — Ventas Vs. Compras |
| 179 | Informe de Gastos por categoría |
| 180 | Informe de Stock |
| 181 | Rankings — Productos y Clientes |
| 182 | Rankings — Categorías y Tipo de Producto |
| 183 | Ajustes — Mi Perfil |
| 184 | Ajustes — Mi Plan (KPIs y días de prueba) |
| 185 | Ajustes — Mi Plan (tabla de precios, 4 planes) |
| 186 | Ajustes — Usuarios y Permisos (vacío) |
| 187 | Ajustes — Usuarios — modal confirmación de costo (cancelado) |
| 188 | Ajustes — Importar Datos (pestaña Clientes) |
| 189 | Contagram 2.0 BETA — formulario waitlist |
| 190 | Panel de Notificaciones |
| 191 | Menú desplegable de usuario (Auditoría / Cerrar sesión) |
| 192 | Dashboard — botón "Crear Cuenta Real" en sidebar |
