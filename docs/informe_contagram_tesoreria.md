# Informe Contagram — Módulo Tesorería

**Fecha del relevamiento:** 24/07/2026
**Cuenta analizada:** Cuenta de Prueba (trial), usuario alberto rodriguez
**Alcance:** Análisis exhaustivo del módulo "Tesorería" del menú lateral: vista de Saldos, vista de Movimientos, ficha individual de cada cuenta, alta/edición de cuentas de tesorería (Ajustes) y transferencias entre cuentas. Se documentan todas las vistas, subvistas, botones, formularios y comportamientos observados, incluyendo la creación de registros de prueba reales en la cuenta de prueba.
**Capturas de referencia:** `[144]` a `[162]` (ver índice al final). Carpeta: `capturas_contagram/`.

---

## 1. Estructura general de Tesorería

El módulo "Tesorería" (ícono de "$" en el menú lateral) es el panel financiero centralizado de Contagram: consolida en un solo lugar el estado de todas las cuentas de dinero de la empresa — cajas, bancos, y las cuentas virtuales "A Cobrar" / "A Pagar" que resumen las cuentas corrientes de clientes y proveedores — y permite realizar transferencias internas entre ellas.

Tiene **dos pestañas principales**:
- **Saldos**: foto instantánea del estado de todas las cuentas a la fecha.
- **Movimientos**: informe de flujo de dinero (cobros vs. pagos) agrupado por cuenta, en un rango de fechas.

Además, desde el ícono de ajustes (llave/tuerca) ubicado junto al botón "Movimiento entre Cuentas" se accede a la pantalla de **configuración de cuentas de tesorería**, donde se crean, editan, ocultan o eliminan las cuentas.

---

## 2. Pestaña "Saldos" `[144]`

Ruta: `/accounts`

Es la vista por defecto al entrar a Tesorería. Se organiza en tres bloques:

### 2.1 A Cobrar (bloque verde)
Lista de cuentas virtuales que representan dinero pendiente de cobro: Saldo Cta Cte Clientes, Cheque de Terceros, AMEX, VISA, y otras scrolleables dentro de un panel con scroll interno. Fila de cierre **Total A Cobrar**.

### 2.2 A Pagar (bloque rojo)
Espejo del anterior para las obligaciones pendientes: Saldo Cta Cte Proveedores, Gastos Pendientes, Cheque Propio, VISA Corporativa. Fila de cierre **Total A Pagar**.

### 2.3 Disponible (bloque celeste, ancho completo)
Dinero líquido real de la empresa, separado en dos columnas:
- **Cajas**: cuentas de tipo Efectivo (Caja del Local, Caja General, y la cuenta de prueba creada "Caja Chica Prueba").
- **Bancos**: cuentas de tipo Banco (Banco Galicia, Banco Santander Río, Mercado Pago).

Cada columna tiene su subtotal (**Total Cajas**, **Total Bancos**) y el bloque completo muestra un **Total** general en la cabecera (ej. "Total: $23.453,08"). Es posible que alguna cuenta bancaria muestre **saldo negativo** (Banco Galicia: -$419,30 en el momento del relevamiento), reflejando un descubierto o egresos que superan lo acreditado.

### 2.4 Controles superiores
- **Buscar por Fecha**: selector para consultar el saldo a una fecha de corte específica (con icono de calendario y checkbox de confirmación).
- **Movimiento entre Cuentas** (botón verde +): abre el modal de transferencia interna (ver sección 4).
- **Icono de ajustes** (llave): accede a la configuración de cuentas de Tesorería (ver sección 3).

---

## 3. Configuración de cuentas de Tesorería (Ajustes) `[145]` `[146]` `[147]` `[148]` `[149]`

Ruta: `/user_accounts/advanced_configuration?tab=treasury_accounts`

Accesible desde el icono de llave en Tesorería. Es parte de la sección general "Ajustes" de la cuenta (junto a Mi Perfil, Mi plan, Usuarios y Permisos, Importar Datos, Funciones Avanzadas), bajo la pestaña "Cuentas Tesorería" (las otras dos pestañas del mismo grupo son "Nueva Venta" y "Nuevo Presupuesto", plantillas de otros módulos).

**Tabla "Ajustes Cuentas Tesorería"**, agrupada por categoría, con columnas Nombre / Editar / Visible:

- **Efectivo**: Caja del Local, Caja General (y, tras la prueba, Caja Chica Prueba)
- **Banco**: Banco Galicia, Banco Santander Río, Mercado Pago
- **A Cobrar**: AMEX, VISA (editables) y **Cheque de Terceros** — marcada como **"(Cuenta del sistema)"**, no editable
- **A Pagar**: **Cheque Propio** — también "(Cuenta del sistema)", no editable — y VISA Corporativa (editable)

Todas las cuentas muestran su estado "Visible" en la columna derecha.

**Modal "Editar Cuenta"** `[147]`: Fecha (de saldo inicial), Monto (saldo inicial en $), Nombre, **Tipo** (desplegable no editable en cuentas existentes, mostrando el tipo actual: Banco, Efectivo, etc.), radio buttons **Mostrar Cuenta / Ocultar Cuenta**, botones Eliminar / Cancelar / Guardar.

**Modal "Nueva Cuenta"** `[148]`: Fecha, **Saldo Inicial** ($), **Nombre de la Cuenta**, **Seleccionar Tipo de Cuenta** — desplegable con 4 opciones: **A Cobrar, A Pagar, Banco, Efectivo**. Botones Cancelar / Crear.

Se creó una cuenta de prueba real: **"Caja Chica Prueba"**, tipo Efectivo, saldo inicial $1.000, la cual apareció inmediatamente en la tabla de Ajustes `[149]` y en el bloque "Disponible → Cajas" de la vista Saldos.

---

## 4. Transferencias — "Movimiento entre Cuentas" `[154]` `[155]` `[156]` `[157]`

Botón verde disponible tanto en la vista principal de Saldos como dentro de la ficha de cada cuenta individual.

**Modal "Nuevo Movimiento Entre Cuentas":**
- Fecha
- Monto ($)
- **Elija cuenta de salida** (desplegable buscable que muestra, junto al nombre de cada cuenta, su **saldo actual** — muy útil para decidir el origen de fondos sin salir del modal)
- **Elija cuenta de entrada** (mismo desplegable con saldos)
- Observación (texto libre)
- Botones Cancelar / Crear

**Prueba real ejecutada**: transferencia de **$500** desde "Caja del Local" hacia "Caja Chica Prueba", con observación "Transferencia de prueba - fondeo Caja Chica". Al confirmar, apareció el mensaje "Movimiento creado con éxito" y los saldos se actualizaron de inmediato en la vista de Saldos: Caja Chica Prueba pasó de $1.000 a **$1.500**, Caja del Local bajó de $6.445,88 a **$5.945,88**. El Total Disponible general no varió ($23.453,08), confirmando que es un movimiento interno de partida doble (no genera ni destruye dinero, solo lo reubica entre cuentas).

---

## 5. Ficha individual de cuenta `[158]` `[159]` `[160]` `[161]` `[162]`

Al hacer clic sobre el **nombre de cualquier cuenta** (tanto en el bloque Saldos como en Movimientos) se accede a su ficha propia, en `/accounts/{id}`, que funciona como un **libro mayor (ledger)** de esa cuenta puntual.

**Cabecera**: nombre de la cuenta, botón Filtros, selector de rango de fechas (por defecto "24 Jun - 24 Jul", es decir el último mes), selector de columnas, botón "Movimiento entre cuentas".

**Tabla de movimientos**, columnas: **Id, Fecha, Operación, Detalles, Ingreso, Egreso, Balance (saldo corriente, resaltado en amarillo), N° Factura, Observación**.

Se observó que la columna **Operación** clasifica cada línea según su origen real en otros módulos de la aplicación:
- **Saldo Inicial**: el alta de la cuenta.
- **Movimiento entre Cuenta**: transferencias internas, con el nombre de la cuenta contraparte en "Detalles".
- **Cobro**: cobros de Ventas, con el nombre del Cliente en "Detalles" y el número de comprobante en "N° Factura" (ej. "B 0001-00000003").
- **Pago**: pagos de Compras, con el nombre del Proveedor en "Detalles" y el comprobante correspondiente (ej. "A 0003-00000185").
- **Gasto**: pagos registrados desde el módulo Gastos, con la subcategoría en "Detalles" (ej. "Facebook Add's", "IVA", "Alquiler") y sin N° Factura (los gastos no generan comprobante fiscal).

Esto confirma que la ficha de cuenta es el **punto de consolidación real** de todos los movimientos de dinero de la aplicación — Ventas, Compras y Gastos convergen aquí, cada uno aportando su columna Ingreso o Egreso según corresponda, con un balance corrido que permite auditar el saldo de la cuenta en cualquier momento.

**Menú de acciones por fila** `[159]`: solo 2 opciones — **Editar** y **Eliminar** — reflejando que cada línea es en general la contracara de una operación registrada en otro módulo (excepto los Movimientos entre Cuentas y el Saldo Inicial, que se gestionan íntegramente desde Tesorería).

**Filtros** `[160]`: un único campo, "Elija Tipo de Operación" — permite filtrar el ledger por el tipo de movimiento (Cobro, Pago, Gasto, Movimiento entre Cuenta, Saldo Inicial).

**Selector de columnas** `[162]`: Acciones, Id, Fecha, Operación, Ingreso, Egreso, Balance, N° Factura, Observación — todas activadas por defecto.

**Botón Exportar**: descarga el detalle de la ficha (mismo patrón que el resto de los listados de Contagram).

---

## 6. Pestaña "Movimientos" `[150]` `[151]` `[152]` `[153]`

Ruta: `/accounts` con la pestaña "Movimientos" seleccionada. A diferencia de la ficha de cuenta individual (que es un ledger por cuenta), esta pestaña es un **informe consolidado de flujo de caja** de todo el negocio en un rango de fechas.

**Banner informativo** (desplegable, con opción de cerrarlo) que explica exactamente qué contempla el informe:
- **Cobros**: todos los cobros realizados sobre Ventas + todos los ingresos registrados en "Otros Ingresos".
- **Retenciones Sufridas**: todas las retenciones registradas sobre Ventas.
- **Pagos**: todos los pagos realizados sobre Compras + todos los pagos realizados al registrar Gastos (**los Gastos en estado "Pendiente" quedan explícitamente excluidos** del cómputo).

**Selector de rango de fechas** (por Emisión) y tabla resumen: Desde / Hasta / **Total Cobros** (verde) / **Total Pagos** (rojo) / **Resultado** (Total Cobros − Total Pagos).

**Secciones expandibles "Cobros" y "Pagos"**: cada una despliega un desglose por cuenta de tesorería (mismas 9-10 cuentas configuradas: Caja Chica Prueba, Caja del Local, Caja General, Banco Galicia, Banco Santander Río, Mercado Pago, AMEX, VISA, Cheque de Terceros), cada fila con un **checkbox "Activo"** que permite incluir o excluir esa cuenta puntual del total mostrado — una función de filtrado ad-hoc muy útil para, por ejemplo, calcular el resultado de caja excluyendo cuentas específicas sin tener que armar un filtro complejo. Cada sección cierra con su **Total Cobros** / **Total Pagos**.

**Botones de exportación**: Exportar (planilla) y **Exportar a PDF** — esta última opción no se había visto en otros listados de Contagram relevados hasta el momento, sugiriendo que este informe está pensado también como un reporte formal para compartir o archivar.

---

## 7. El dashboard "Inicio" como vidriera de Tesorería `[150]`

Aunque el dashboard de Inicio (`/dashboard/index`) no forma parte del módulo Tesorería en sí, se observó durante el relevamiento que **replica en miniatura la información de Tesorería**: un panel lateral "Total Disponible / Total Cajas / Total Bancos" y una tabla de "Últimos Movimientos" (Fecha, Cuenta, Monto) que mostró en tiempo real los tres movimientos de tesorería creados durante esta sesión de pruebas (alta de Caja Chica Prueba, pago del Gasto de Marketing, pago de la Compra), confirmando que toda la aplicación comparte el mismo estado de tesorería subyacente sin desfasajes.

---

## 8. Observaciones y hallazgos relevantes

- **Tesorería es el punto de consolidación financiera de toda la aplicación**: cada cobro de Venta, pago de Compra y pago de Gasto impacta automáticamente el saldo de la cuenta de tesorería elegida en el momento de esa operación, sin necesidad de ninguna carga manual adicional.
- **Las cuentas "del sistema"** (Cheque de Terceros, Cheque Propio) no son editables ni eliminables — existen de forma fija para modelar el circuito de cheques de terceros recibidos y cheques propios emitidos, típico de la operatoria PyME argentina.
- **El desplegable de selección de cuenta en "Movimiento entre Cuentas" muestra el saldo de cada cuenta en línea**, evitando tener que consultar la vista de Saldos antes de decidir el origen de una transferencia.
- **La sección "Movimientos" excluye explícitamente los Gastos en estado Pendiente** del cómputo de Total Pagos, coherente con el hallazgo del informe de Egresos de que un Gasto puede crearse sin conciliar como pagado (checkbox "Marcar como pendiente").
- **El "Exportar a PDF"** en la pestaña Movimientos es, hasta el momento, el único botón de exportación a PDF nativo encontrado en el relevamiento — el resto de los módulos ofrece solo exportación a planilla.
- **La ficha individual de cada cuenta es, en la práctica, un extracto bancario / libro de caja generado automáticamente por Contagram**, con saldo corrido y trazabilidad completa hacia el comprobante de origen (Venta, Compra o Gasto) vía la columna N° Factura y el link clickeable al documento correspondiente.
- El **saldo negativo observado en Banco Galicia** (-$419,30) no generó ninguna advertencia o bloqueo visible en la aplicación — Contagram permite saldos en descubierto sin restricción aparente.

---

## 9. Registros de prueba creados

| Elemento | Detalle |
|---|---|
| Cuenta nueva | **"Caja Chica Prueba"**, tipo Efectivo, saldo inicial $1.000,00, visible. |
| Movimiento entre Cuentas | Transferencia de **$500,00** desde "Caja del Local" hacia "Caja Chica Prueba", observación "Transferencia de prueba - fondeo Caja Chica". Saldo final de Caja Chica Prueba: **$1.500,00**. |

---

## 10. Índice de capturas

| N.° | Descripción |
|---|---|
| 144 | Vista principal de Saldos (A Cobrar, A Pagar, Disponible) |
| 145 | Ajustes Cuentas Tesorería — parte superior (Efectivo, Banco, A Cobrar) |
| 146 | Ajustes Cuentas Tesorería — scroll hasta A Pagar |
| 147 | Modal "Editar Cuenta" |
| 148 | Modal "Nueva Cuenta" — desplegable de Tipo (A Cobrar/A Pagar/Banco/Efectivo) |
| 149 | Cuenta "Caja Chica Prueba" creada, reflejada en la tabla de Ajustes |
| 150 | Dashboard Inicio — KPIs y tabla de Últimos Movimientos reflejando la actividad de Tesorería |
| 151 | Pestaña Movimientos — resumen (Total Cobros / Total Pagos / Resultado) |
| 152 | Pestaña Movimientos — sección Cobros expandida, desglose por cuenta |
| 153 | Pestaña Movimientos — sección Pagos expandida, desglose por cuenta |
| 154 | Modal "Nuevo Movimiento Entre Cuentas" |
| 155 | Desplegable "Elija cuenta de salida" mostrando saldos de cada cuenta |
| 156 | Formulario de transferencia completo, listo para crear |
| 157 | "Movimiento creado con éxito" — saldos actualizados en la vista Saldos |
| 158 | Ficha individual de cuenta (Caja Chica Prueba) — ledger de movimientos |
| 159 | Menú de acciones por fila en la ficha de cuenta (Editar/Eliminar) |
| 160 | Panel de Filtros en la ficha de cuenta |
| 161 | Ficha de "Banco Galicia" — ledger completo con Cobros, Pagos y Gastos consolidados |
| 162 | Selector de columnas de la ficha de cuenta |

---

*Informe generado como parte del relevamiento exhaustivo de Contagram. Módulos previos: Base de Datos (Clientes, Proveedores, Productos), Ingresos (Presupuestos, Ventas, Otros Ingresos), Funciones Avanzadas, Egresos (Compras, Gastos).*
