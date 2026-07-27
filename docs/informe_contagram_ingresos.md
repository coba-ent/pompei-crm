# Informe técnico: módulo "Ingresos" de Contagram
### Presupuestos · Ventas · Otros Ingresos

**Cuenta analizada:** Cuenta de Prueba (trial) — usuario alberto rodriguez
**Fecha del relevamiento:** 24/07/2026
**Método:** navegación real de la aplicación (app.contagram.com), creación de registros de prueba (incluyendo el flujo completo Presupuesto → Venta → Cobranza), apertura de cada modal/dropdown/menú, scroll horizontal completo de todas las tablas.
**Capturas:** 34 imágenes numeradas (65 a 98), guardadas en `capturas_contagram/` junto con las del informe de Base de Datos. Cada sección referencia el número de captura entre corchetes, ej. `[65]`.

---

## 1. Acceso al módulo

El menú lateral "Ingresos" despliega 3 opciones:

- **Presupuestos** (`/budgets`)
- **Ventas** (`/sales`)
- **Otros Ingresos** (`/incomes`)

A diferencia de Base de Datos, estos tres módulos representan un **flujo de negocio secuencial**: un Presupuesto puede convertirse en una Venta con un clic ("Crear Venta"), y una Venta puede generar Cobranzas, Notas de Crédito/Débito y Remitos. "Otros Ingresos" es independiente y sirve para registrar ingresos de caja que no provienen de una venta (aportes de socios, préstamos, etc.).

---

## 2. PRESUPUESTOS (`/budgets`)

### 2.1 Vista de listado `[65] [66]`

A diferencia de los listados de Base de Datos, Presupuestos tiene una **barra de 5 KPIs** sobre la tabla: Ventas (total de presupuestos convertidos en venta), Vencidos/Rechazados, Pendientes sin enviar/Pendientes enviados, Aceptados, y Total Posibles (con signos +/=/− entre las tarjetas, mostrando la fórmula del cálculo).

Header: **Video Explicativo** (link), 2 selectores de rango de fechas ("Emisión" y "Validez"), botón **Filtros**, selector de columnas, botón **Nuevo Presupuesto**.

**Columnas de la tabla (18 en total, confirmadas con scroll horizontal completo):**

Estado, Id, Emisión, Vencimiento, Cliente, Categoría, Nro. Presupuesto, Subtotal sin Descuento, Descuento, Subtotal con Descuento, Total, Etiquetas, Nota Cliente, Nota Interna, Lista de Precios, Vendedor, Formas de Pago, Métodos de Envío.

El campo **Estado** es un badge de color con una flechita (▾) que despliega el menú de fila.

### 2.2 Filtros `[67]`

Panel con 15 campos: Id, Producto/Servicio, Cliente, Estado del Presupuesto, Categoría de Venta, N° de Presupuesto, Etiqueta, Vendedor, Formas de Pago, Métodos de Envío, Usuario, Nota para el Cliente, Nota Interna, Servicio Desde, Servicio Hasta.

### 2.3 Menú de fila (Estado ▾) `[68]`

Menú con 10 opciones agrupadas en 4 bloques:

1. **Ver** / **Editar** / **Eliminar**
2. Cambio de estado directo: **Pendiente** / **Rechazado** / **Aceptado**
3. **Crear Venta** (convierte el presupuesto en una venta, ver sección 4.1)
4. **Ver Presupuesto** / **Imprimir Presupuesto** / **Enviar Presupuesto**

### 2.4 Vista "Ver" (detalle) `[69] [70]`

A diferencia de Clientes/Proveedores/Productos (que abren un modal), "Ver" en Presupuestos **navega a una página completa** con formato de documento imprimible: logo (placeholder "Agregar Mi Logo"), número de presupuesto, datos del cliente, tabla de Conceptos (Código, Descripción, Cant., Precio Unitario, Bonif., Subtotal, Alícuota IVA, Subtotal c/IVA), totales (Importe Neto Gravado, IVA, Total Presupuesto), Formas de Pago y Métodos de Envío. Al pie: botones **Enviar Presupuesto**, **Imprimir Presupuesto**, **Exportar Presupuesto**, **Editar**.

Nota: en el menú lateral, debajo de "Tesorería", aparece un ítem **"Contagram 2.0 BETA"**, lo que indica que existe una versión nueva de la interfaz en desarrollo/opt-in no explorada en este relevamiento.

### 2.5 Formulario "Nuevo Presupuesto" (`/budgets/new`) `[71]`

A diferencia de los formularios modales de Base de Datos, este es una **página completa**, con layout de dos columnas:

**Columna izquierda:**
- Seleccionar Cliente (buscador con resultados en vivo)
- Seleccionar Categoría (con opción "Crear Categoría de ventas" y edición inline de las existentes: Abono Fijo, Consultoria, Online, Mayorista)
- Emisión / Validez (fechas)
- Servicio Desde / Servicio Hasta
- Lista de Precios (selector)
- Seleccionar o Crear Producto/Servicio (buscador que agrega filas a la tabla de conceptos)
- Tabla de Conceptos: Producto, Cant, Precio, Desc., Subtotal, IVA, Total — cada fila tiene un menú ▾ con **Ver** / **Editar** y un ícono de tacho para eliminar `[75]`
- Nota para el Cliente / Nota interna

**Columna derecha:**
- Formas de Pago y Métodos de Envío (campos de texto libre, sin autocompletado detectado) `[77]`
- **Etiquetas** (botón que abre un popup con buscador y opción "Nueva Etiqueta"; en la cuenta de prueba no había etiquetas creadas) `[76]`
- Descuento General (%)
- Total Presupuesto
- **+ Percepciones**, **+ Impuestos Internos**, **+ Intereses**: cada enlace agrega una fila con selector "Seleccionar" + monto ($) + tacho de eliminar; se pueden agregar múltiples conceptos de cada tipo `[72]`

Botones: Cancelar, Guardar, Guardar y Enviar.

**Hallazgo relevante — autocompletado por cliente:** al seleccionar un cliente que tenía configurada una Categoría de Ventas y un Descuento General por defecto (dato cargado en su ficha, ver informe de Base de Datos), el formulario **autocompletó automáticamente** tanto la Categoría ("Mayorista") como el Descuento General (10%) `[74]`. Esto confirma en la práctica lo que la documentación del formulario de Cliente indica: *"Los datos seleccionados aquí aparecerán por defecto en una nueva venta al elegir este cliente"*.

**Hallazgo — error transitorio al guardar:** en la primera prueba de creación, al hacer clic en "Guardar" inmediatamente después de completar el formulario, el sistema mostró el error **"No se salvó el Presupuesto, revise el formulario"** sin indicar qué campo era inválido `[78]`. Al volver a hacer clic en "Guardar" sin cambiar ningún dato, el presupuesto se guardó exitosamente ("Presupuesto 7 creado con éxito") `[79]`. Esto sugiere una condición de carrera (race condition) en el guardado, no un error de validación real — posiblemente relacionado con que el clic anterior disparó una navegación parcial. Se recomienda que el equipo de producto revise el manejo de doble clic / clics rápidos en el botón Guardar.

---

## 3. VENTAS (`/sales`)

### 3.1 Flujo Presupuesto → Venta `[80]`

Desde el detalle de un Presupuesto, el botón **"Crear Venta"** navega a `/sales/new?budget=ID` con el formulario de Nueva Venta **pre-cargado** con cliente, categoría, producto(s), notas y descuento del presupuesto de origen. Se agregan campos que no existen en Presupuestos:

- **Tipo de comprobante**: selector con opciones **A, B, C, E** `[81]`
- **N° de comprobante**: autogenerado (ej. "0001-00000003")
- **Vto. del Cobro** (con tooltip "?")

El botón "Guardar y Enviar" de Presupuestos es reemplazado por **"Cobrar"** (botón verde con ícono $).

### 3.2 Modal de Cobranza `[82]`

Al presionar "Cobrar", el sistema guarda la venta (el título cambia a "Editar Venta") y abre automáticamente un modal **"Cobranza"** con:
- Total Venta / A Cobrar (montos)
- Campo "Cobrar" editable (por si se cobra un monto parcial)
- Grilla de **8 medios de cobro** como botones: Caja del Local, Caja General, Banco Galicia, Banco Santander Río, Mercado Pago, AMEX, VISA, Cheque de Terceros — estos corresponden exactamente a las cuentas configuradas en el módulo Tesorería.
- Botón "Volver"

Al seleccionar un medio de cobro, la venta queda marcada como **Cobrada** de inmediato (confirmado con el mensaje "Venta 6 actualizada con éxito") y el formulario vuelve a un estado "Nueva Venta" en blanco, listo para cargar otra operación `[83]`.

### 3.3 Vista de listado `[84] [85]`

Igual que Presupuestos pero con una columna adicional **"Creada Desde"** que indica si la venta se originó de un Presupuesto o fue creada directamente ("Venta"), y con columnas de cobro específicas: **A Cobrar**, **Cobrado**, **Medio de Cobro** (con link directo a la cuenta de Tesorería).

**Columnas completas (19 en total):** Estado, Id, Creada Desde, Emisión, Vencimiento, Cliente, Categoría, Subtotal sin Descuento, Descuento, Subtotal con Descuento, Total, A Cobrar, Cobrado, Etiquetas, Medio de Cobro, Nota Cliente, Nota Interna, Lista de Precios, Vendedor.

El selector de columnas además ofrece 2 columnas ocultas por defecto no presentes en la tabla principal: **Envío de Mail** y **CUIT**, además de **Servicio Desde** / **Servicio Hasta** `[91]`.

### 3.4 Botón "Analizar" (IA) `[86]`

Exclusivo de Ventas (no está presente en Presupuestos, Otros Ingresos, ni en ningún listado de Base de Datos). Al presionarlo, muestra un estado "Analizando..." y luego un panel **"¡Análisis listo!"** con un resumen generado por IA sobre las ventas del período: identifica el producto estrella, la categoría más rentable, el récord de venta individual, y sugiere una recomendación de negocio ("explorar estrategias para expandir el canal Mayorista..."). Al pie del panel se aclara explícitamente: *"Generado con Gemini. La información puede no ser del todo precisa o real."* — confirmando que Contagram usa el modelo Gemini de Google para esta función de analítica conversacional, en la misma línea que la función "Buscar precios con IA" detectada en el módulo Productos.

### 3.5 Filtros `[90]`

Panel con 11 campos: Id, Cliente, Estado del Cobro, Categoría de Venta, Facturado, Tipo y N° de Factura, Etiqueta, Vendedor, Medio de Cobro, Usuario, Nota Cliente, Nota Interna, Creada Desde, Servicio Desde, Servicio Hasta.

### 3.6 Menú de fila `[87]`

El más completo de todos los relevados en Contagram, con 12 opciones en 4 bloques:

1. **Ver** / **Editar** / **Eliminar**
2. **Agregar Cobranza** / **Crear NC/ND** (Nota de Crédito o Débito) / **Crear Remito** / **Cta Cte**
3. **Ver Detalle** (abre un **PDF** en pestaña nueva, a diferencia de "Ver" en Presupuestos que navega dentro de la app) / **Imprimir Detalle** / **Imprimir Ticket** / **Enviar Detalle** / **Enviar Whatsapp** (deshabilitado en la cuenta de prueba, probablemente requiere integración de WhatsApp Business configurada)

### 3.7 Crear NC/ND (Nota de Crédito/Débito) `[88]`

Modal con: **Seleccionar Tipo** (Nota de Crédito / Nota de Débito), **Documento que Ajusta** (selector de comprobante a ajustar), **"¿Querés que afecte Stock?"** (Sí/No + selector de productos de la venta a incluir en el ajuste). Botones Cancelar / Siguiente.

### 3.8 Detalle de Venta `[89]`

La página de detalle de una venta (`/sales/:id`) tiene una estructura distinta a la de Presupuestos: arriba muestra una **barra de ecuación con 5 valores**: Total Venta (+) ND (−) NC (=) Cobrado → A Cobrar, seguida de una tabla **"Cobranzas"** (Id, Fecha, Medio de cobro, Nota, Total, Comprobante con ícono de edición) con un link "+ Agregar Cobranza", y más abajo el documento imprimible con la leyenda de comprobante (A/B/C) y un sello **"NO VÁLIDO COMO FACTURA"** superpuesto — confirma que la cuenta de prueba no tiene habilitada la facturación electrónica AFIP real. Debajo del documento hay una sección **"Notas de Crédito y Débito"** con su propia tabla y un link "+ Agregar". Botón superior **"Crear Remito"** (amarillo).

---

## 4. OTROS INGRESOS (`/incomes`)

El módulo más simple de los tres, pensado para registrar movimientos de caja que no son ventas (aportes de capital, préstamos recibidos, etc.).

### 4.1 Vista de listado `[92]`

Solo 7 columnas: Estado, Id, Fecha, Categoría, Descripción, Medio de Cobro, Monto. Header con Filtros, selector de fecha "Emisión" y botón **Nuevo Ingreso**. No tiene selector de columnas, botón "Analizar", ni acciones masivas — es deliberadamente minimalista.

### 4.2 Filtros `[93]`

6 campos: Id, Categoría, Medio de Cobro, Estado del Cobro, Descripción, Usuario.

### 4.3 Menú de fila `[94]`

Solo 3 opciones: **Ver / Editar / Eliminar** — el más simple de toda la aplicación, sin las acciones de conversión o documentos que sí tienen Presupuestos y Ventas.

### 4.4 Formulario "Nuevo Ingreso" `[95] [96] [97]`

Modal compacto de un solo bloque: Fecha, Monto ($), **Categoría** (con "Crear Categoría de Ingreso"; categorías predefinidas observadas: Aportes Socios, Otros Ingresos, Préstamos Financieros, Saldo — con más opciones al hacer scroll), **Medio de Cobro** (mismo listado de cuentas de Tesorería que en la Cobranza de Ventas: Caja del Local, Caja General, Banco Galicia, Banco Santander Río...), Descripción (textarea), y un checkbox **"Marcar como pendiente"** (permite registrar el ingreso sin darlo por cobrado todavía). Botones Cancelar / Crear.

**Prueba realizada:** se creó el ingreso "Ingreso de prueba para documentacion" por $500 en la categoría "Otros Ingresos" con medio de cobro "Caja General"; quedó resaltado en amarillo en el listado tras la creación con Id 7 `[98]`.

---

## 5. Observaciones y hallazgos relevantes

1. **Consistencia de "Medios de Cobro"/"Formas de Pago" con Tesorería:** tanto en la Cobranza de una Venta como en el alta de un Otro Ingreso, el listado de cuentas disponibles (Caja del Local, Caja General, Banco Galicia, Banco Santander Río, Mercado Pago, AMEX, VISA, Cheque de Terceros) es idéntico, confirmando que ambos módulos leen de la misma fuente de datos: las cuentas configuradas en Tesorería.
2. **Autocompletado de Categoría y Descuento desde la ficha del Cliente**, verificado en la práctica en el alta de un Presupuesto — coincide con lo documentado en el informe de Base de Datos.
3. **Dos funciones de IA generativa (Gemini) detectadas en Contagram**: "Buscar precios con IA" en el alta de Productos (Base de Datos) y el botón "Analizar" en el listado de Ventas (Ingresos). Ambas están claramente etiquetadas como generadas por IA y con la advertencia de que la información puede no ser precisa.
4. **Ventas es notablemente más rico que Presupuestos y Otros Ingresos**: es el único de los tres con comprobantes fiscales tipados (A/B/C/E), Notas de Crédito/Débito, Remitos, envío por WhatsApp (aunque deshabilitado en esta cuenta) y el botón de análisis por IA.
5. **Error transitorio al guardar un Presupuesto** ("No se salvó el Presupuesto, revise el formulario") que se resolvió reintentando sin cambios — posible race condition, reportado como hallazgo técnico para el equipo de desarrollo.
6. **Watermark "NO VÁLIDO COMO FACTURA"** en el detalle de Venta confirma que la cuenta de prueba no está habilitada para facturación electrónica AFIP real, comportamiento esperable en un entorno de trial.
7. **Menú "Contagram 2.0 BETA"** visible en el sidebar sugiere una versión nueva de la interfaz en desarrollo, no explorada en este relevamiento (fuera del alcance solicitado).
8. **Presupuestos y Ventas comparten prácticamente el mismo formulario de carga** (selección de cliente, categoría, productos, descuentos, percepciones/impuestos internos/intereses), reforzando el patrón de reutilización de componentes ya observado entre Clientes y Proveedores en el informe anterior.

---

## 6. Registros de prueba creados durante este relevamiento

| Módulo | Registro | Id asignado | Notas |
|---|---|---|---|
| Presupuestos | Presupuesto para "Empresa Prueba Documentacion SA" | 7 | 1 unidad de "Camisa Hombre Blanca Large", $402,93 total |
| Ventas | Venta generada desde el Presupuesto 7 | 6 | Cobrada completamente vía "Caja del Local" |
| Otros Ingresos | "Ingreso de prueba para documentacion" | 7 | $500, categoría "Otros Ingresos", medio "Caja General" |

Estos registros quedaron activos en la cuenta de prueba para revisión.

---

## 7. Índice de capturas

Capturas 65 a 98 en `capturas_contagram/`, numeradas en el orden en que se relevaron: 65-79 Presupuestos, 80-91 Ventas, 92-98 Otros Ingresos. Continúan la numeración del informe de Base de Datos (00-64).
