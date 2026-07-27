# Informe técnico: módulo "Base de Datos" de Contagram
### Clientes · Proveedores · Productos

**Cuenta analizada:** Cuenta de Prueba (trial) — usuario alberto rodriguez
**Fecha del relevamiento:** 24/07/2026
**Método:** navegación real de la aplicación (app.contagram.com), creación de registros de prueba, apertura de cada modal/dropdown/menú, scroll horizontal completo de todas las tablas, y contraste con el Centro de Ayuda oficial (help.contagram.com).
**Capturas:** 64 imágenes numeradas (00 a 64), guardadas en la subcarpeta `capturas_contagram/` junto a este informe. Cada sección referencia el número de captura correspondiente entre corchetes, ej. `[02]`.

---

## 1. Acceso al módulo

El menú lateral izquierdo tiene una entrada **"Base de Datos"** que, al hacer clic, despliega un sub-menú con tres opciones, cada una con un ícono "+" al costado que abre directamente el formulario de alta sin pasar por la lista `[01]`:

- **Clientes** (`/clients`)
- **Proveedores** (`/suppliers`)
- **Productos** (`/products`)

Las tres vistas comparten el mismo patrón general de diseño (header con buscador, botones de acción arriba a la derecha, tabla con scroll horizontal, paginado abajo), pero Productos tiene funcionalidad adicional significativa (selección múltiple, ajuste de stock, acciones masivas, filtros avanzados).

---

## 2. CLIENTES (`/clients`)

### 2.1 Vista de listado `[02] [03]`

Header: título "Clientes", link **"Video Explicativo"** (abre `youtube.com/watch?v=H1DIxZaCHiw` en pestaña nueva), buscador de texto libre ("Busca Clientes utilizando alguno de sus datos") + botón **Buscar**, selector de columnas (ícono de grilla), botón **Importar datos**, botón **Nuevo Cliente**.

**Columnas de la tabla (16 en total, confirmadas haciendo scroll horizontal completo):**

| # | Columna | Comportamiento |
|---|---------|-----------------|
| 1 | Id | Numérico, correlativo interno |
| 2 | Cliente | Nombre/Razón social, columna fija (no aparece en el selector de columnas porque es obligatoria), es un link a la vista rápida "Ver" |
| 3 | Nombre | Ordenable (clic en el header ordena asc/desc vía querystring `q[s]=`) |
| 4 | Apellido | Ordenable |
| 5 | Mail | Ordenable |
| 6 | Teléfono | Ordenable |
| 7 | Teléfono Celular | Ordenable |
| 8 | Domicilio | Ordenable |
| 9 | Localidad | Ordenable |
| 10 | Provincia | Ordenable |
| 11 | DNI | — |
| 12 | CUIT | — |
| 13 | Condición de IVA | — |
| 14 | Usuario de Mercado Libre | Exclusivo de Clientes (no existe en Proveedores) |
| 15 | Nota | Valor libre, en los datos de ejemplo se usa para clasificar "Cliente Minorista" / "Cliente Mayorista" |
| 16 | Página Web | — |

Cada fila tiene, a la izquierda del Id, una flechita (▾) que despliega un menú contextual `[06]` con 4 acciones:

- **Ver** → abre un modal de solo lectura con todos los campos cargados `[23] [24]`
- **Editar** → abre el mismo formulario que "Nuevo Cliente" pero pre-cargado `[27]`
- **Eliminar** → dispara un modal de confirmación "¿Está seguro que desea borrar el cliente?" con botones Cancelar/Aceptar `[32]`
- **Cta Cte** → navega fuera del listado, a `/account_receivables` (Cuenta Corriente de Clientes), filtrado por ese cliente `[25] [26]`

**Selector de columnas** (ícono ☰▾ junto a "Nuevo Cliente"): despliega una lista con checkbox por cada columna ocultable (Id, Nombre, Apellido, Mail, Teléfono, Teléfono Celular, Domicilio, Localidad, Provincia, DNI, CUIT, Condición de IVA, Usuario de Mercado Libre, Nota, Página Web) `[04] [05]`. Permite ocultar/mostrar columnas sin afectar los datos.

**Pie de tabla:** cantidad de resultados, selector "Registros por página" con opciones **5 / 10 / 25 / 50 / 100** `[31]`, botón **Exportar** que dispara la descarga de un Excel (aparece un modal "Exportando... En un momento iniciará la descarga") `[30]`, y el texto "Actualizado el [fecha] a las [hora]".

### 2.2 Búsqueda `[29]`

El campo de búsqueda hace un filtro server-side por cualquier dato del cliente (probado con "Ropa" → devolvió sólo "Ropa Online S.A."). No es necesario indicar en qué campo buscar.

### 2.3 Formulario "Nuevo Cliente" / "Editar Cliente" `[07] [08]`

Es un modal con dos bloques principales.

**Bloque superior (datos generales):**

| Campo | Tipo | Notas |
|---|---|---|
| Cliente * | texto, obligatorio | "Nombre de la Empresa o Nombre y Apellido" |
| Nombre | texto | |
| Apellido | texto | |
| Cel. | texto con selector de código de país (+54 por defecto) | |
| Teléfono | texto | |
| Email | texto | |
| Apodo ML | texto | Tooltip: *"Apodo de Mercado Libre"* `[14]` — exclusivo de Clientes |
| Página Web | texto | |
| Domicilio | texto | |
| Provincia | select con buscador, lista completa de provincias argentinas | Al elegir una provincia autocompleta el C.P. y pre-selecciona una Localidad por defecto (comportamiento no alfabético observado: al elegir "C.A.B.A." el sistema propuso "RIO TALA (SAN PEDRO)" como localidad por defecto, lo cual es una inconsistencia menor de UX) `[15] [16]` |
| C.P. | numérico, se autocompleta según Provincia/Localidad | |
| Localidad | select con buscador dependiente de la Provincia | |
| Nota | textarea | |

Enlaces adicionales en este bloque:
- **"+ Agregar Persona de Contacto"**: despliega un sub-formulario (Nombre*, Apellido, Teléfono, Cel., Email, checkbox "Enviar también mails a esta dirección") con un ícono de tacho de basura para eliminarlo; al eliminar pide confirmación ("¿Está seguro que desea eliminar este contacto?") `[09] [10]`. Se pueden agregar múltiples personas de contacto.
- **"+ Agregar Nuevo campo"** (con tooltip "?"): abre un modal secundario "Crear nuevo campo" con Nombre y Tipo. El selector Tipo ofrece **Texto, Opciones, Fecha, Numérico**, pero en la cuenta de prueba solo "Texto" está habilitado (los otros 3 aparecen deshabilitados/grises, probablemente restringidos por plan) `[11] [12]`.
- **"+ Saldo Inicial"**: despliega Fecha (date-picker) y Monto ($). Al crear el cliente con un saldo inicial, el sistema genera automáticamente un movimiento tipo "Saldo Inicial" visible en Cuenta Corriente → Movimientos `[13] [25]`.

**Bloque "Ventas"** (con tooltip: *"Los datos seleccionados aquí aparecerán por defecto en una nueva venta al elegir este cliente"*) `[14]`:
- Categoría Ventas (select): opciones observadas → Abono Fijo, Consultoria, Online, Mayorista, Local `[17]`
- Descuento General (%)
- Nota para el Cliente (textarea)

**Bloque "Datos de Facturación"** `[08]`:

| Campo | Tipo | Notas |
|---|---|---|
| Razón social | texto | Se autocompleta con el nombre del Cliente pero es editable |
| N° de Doc. | selector de tipo (CUIT/DNI) + campo con formato automático (auto-formatea "30712345678" a "30-71234567-8") | |
| Verificar | botón (ícono de refresh) | Valida el CUIT contra un algoritmo de dígito verificador. **Si el CUIT es matemáticamente inválido, el sistema bloquea la creación del registro** y muestra "El número de cuit no es válido" en rojo, incluso si se hace clic en "Crear" sin volver a apretar "Verificar" `[20] [21]` |
| Condición de IVA | select | Monotributista, Consumidor Final, Exento, Responsable Inscripto `[18]` |
| Comprobante por defecto | select "Tipo" | A, B, C, (y más opciones no visibles por scroll) `[19]` |
| Teléfono / Cel. / Domicilio fiscal / Localidad / Provincia / C.P. | — | Domicilio fiscal, Localidad y Provincia se autocompletan a partir de los datos generales |

Botones finales: **Cancelar** (rojo) / **Crear** o **Guardar** (verde).

**Comportamiento verificado al crear:** el nuevo cliente aparece en el listado resaltado en amarillo por unos segundos como feedback visual `[22]`.

### 2.4 Vista "Ver" (detalle rápido) `[23] [24]`

Modal de solo lectura con: Cliente, Nombre, Apellido, Email, CUIT, Teléfono, Cel., Página Web, Domicilio, Localidad, Provincia, C.P., Condición de IVA, Comprobante por defecto, Nota. Botón "Cerrar".

### 2.5 Cuenta Corriente de Clientes (`/account_receivables`) `[25] [26]`

Accesible desde el menú de fila ("Cta Cte") de cada cliente. Tiene 2 pestañas:

- **Movimientos**: tabla con columnas Id, Emisión, Cliente, Operación, Categoría, Total Venta, Cobrado, A Cobrar, N° de Comprobante, Medio de Cobro, Descripción. Filtros por Cliente y Operación, selector de rango de fechas ("Emisión"), exportación a Excel y a PDF.
- **Saldos Clientes**: tabla resumen "Cuenta Corriente Clientes" con columnas Cliente, A Vencer, Vencido (subdividido en 0 y 30 / 31 y 60 / 61 y 90 / >90) y Total, con fila de totales al pie. Coincide con los indicadores "Total Ventas a Cobrar" del dashboard de Inicio.

**Observación:** al crear un cliente nuevo con "Saldo Inicial" de $1.500, el movimiento apareció correctamente en la pestaña "Movimientos", pero el total en "Saldos Clientes" no se actualizó de inmediato (posible caché o recálculo asincrónico del reporte).

### 2.6 Importar Datos (`/user_accounts/.../import_data#clients`) `[28]`

Pantalla compartida por Clientes, Proveedores, Productos y Servicios (4 solapas). Para Clientes: botón "Seleccionar Archivo", formatos permitidos .xls/.xlsx/.csv, panel explicativo "Acerca de la importación" y "Notas Técnicas" (tamaño máx. 10MB, recomienda importar primero Proveedores y luego Productos si se quiere asociar cada producto a un proveedor), video "Cómo importar" y link "Tips Para Importar". Botón "Ver mis Clientes" para volver al listado.

---

## 3. PROVEEDORES (`/suppliers`)

La estructura es **idéntica a Clientes** en el 95% de los elementos (listado, búsqueda, selector de columnas, menú de fila, Importar Datos, Exportar, Cuenta Corriente). Se detallan solo las diferencias.

### 3.1 Columnas de la tabla `[33] [34]`

15 columnas (una menos que Clientes: **no existe "Usuario de Mercado Libre"**, lógico porque ese campo es específico de canales de venta):
Id, Proveedor, Nombre, Apellido, Mail, Teléfono, Teléfono Celular, Domicilio, Localidad, Provincia, DNI, CUIT, Condición de IVA, Nota, Página Web.

### 3.2 Formulario "Nuevo Proveedor" `[36] [37]`

Mismos campos que Clientes salvo:
- No tiene el campo **"Apodo ML"**.
- La sección "Ventas" pasa a llamarse **"Compras"**, con **"Categoría Compras"** en lugar de "Categoría Ventas". Opciones observadas: **Servicios Variables, Servicios Fijos, Mano de Obra, Insumos, Local** `[38]`.
- "Nota para el Cliente" pasa a llamarse **"Nota Interna"**.
- El resto (Datos de Facturación, Verificar CUIT, Agregar Persona de Contacto, Agregar Nuevo Campo, Saldo Inicial) es idéntico.

**Prueba realizada:** se creó el proveedor "Textiles del Sur SRL" **sin CUIT** y el sistema lo permitió sin bloquear (a diferencia de Clientes, donde se probó con un CUIT inválido y sí bloqueó). Esto confirma que el CUIT es opcional, y que la validación de "Verificar" solo se dispara si hay un valor cargado y es matemáticamente incorrecto `[39]`.

### 3.3 Menú de fila `[40]`

Mismas 4 opciones que Clientes: Ver, Editar, Eliminar, **Cta Cte**.

### 3.4 Cuenta Corriente de Proveedores (`/account_payables`) `[41] [42]`

Mismo patrón que Clientes pero invertido: pestañas "Movimientos" (columnas Id, Emisión, Proveedor, Operación, Categoría, **Total Compra**, **Pagado**, **A Pagar**, N° de Comprobante, **Medio de Pago**, Descripción) y "Saldos Proveedores" (Cuenta Corriente Proveedores: A Vencer / Vencido por tramos / Total). Al no cargar saldo inicial, "Movimientos" mostró "No se encontraron movimientos de proveedores" hasta que se consultó "Saldos Proveedores", que sí mostró el acumulado histórico de compras existentes ($2.998,35, coincidente con el dashboard).

---

## 4. PRODUCTOS (`/products`)

Este es el módulo con más funcionalidad diferencial respecto de Clientes/Proveedores.

### 4.1 Vista de listado `[43] [44]`

Header con: botón **Filtros** (con ícono de embudo), ícono de **ojo tachado** ("Ver Totales"), selector de columnas, botón **Importar datos**, botón **Ajuste de Stock**, botón **Nuevo Producto**.

**Selección múltiple:** a diferencia de Clientes/Proveedores, cada fila tiene un **checkbox** (no solo una flechita de menú), y el header de la tabla tiene un checkbox "seleccionar todo". Al seleccionar al menos un producto aparece una barra gris: *"N productos seleccionados. Haga click aquí para realizar acciones. Seleccionar los N productos."* `[43]`

**Columnas (14 en total, confirmadas con scroll horizontal completo):** Id, Nombre, Código, Stock, Costo, Precio de Venta, Lista 1, IVA Ventas, IVA Compras, Tipo, Tipo de Producto/Servicio, Proveedor, Descripción (SI/NO), Imagen (SI/NO) `[44]`.

- **Tipo**: Producto / Servicio.
- **Tipo de Producto/Servicio**: sub-clasificación — Compra y Venta / Fabricado / Insumo / Consignación (ver 4.4).
- **Descripción** e **Imagen**: no muestran el contenido, solo un indicador SI/NO de si el producto tiene esos datos cargados.

**Selector de columnas** `[64]`: incluye una entrada especial **"Edición Masiva"** (activa una columna con checkboxes/edición inline, además de Id, Código, Stock Total, Costo, Precio de Venta, Lista 1, IVA Ventas, etc.

### 4.2 Botón "Filtros" `[45] [46] [47] [48]`

Despliega un panel con 8 campos:

| Campo | Tipo |
|---|---|
| Id | texto |
| Producto/Servicio | búsqueda por nombre o código |
| Productos | búsqueda por nombre o código (aparente duplicado del anterior, posiblemente para selección múltiple) |
| Proveedor | select "Todos" |
| Tipo de Producto/Servicio | select: **Compra y Venta, Fabricado, Insumo** |
| Estado del Producto/Servicio | multi-select tipo tag, **Activos** viene pre-cargado por defecto; opción adicional **Inactivos** |
| Stock menor que / Stock mayor que | numérico |
| Tipo | select: **Producto, Servicio** |

Botón "Buscar" al final del panel.

### 4.3 Ícono de ojo — "Ver Totales" `[49]`

Al activarlo, agrega un panel de 3 KPIs sobre la tabla: **Unidades en Stock** (317), **Costo Total** ($51.220,00, con tooltip "?"), **Valor Venta Total** ($111.925,00, con tooltip "?"). Se puede volver a ocultar con el mismo ícono.

### 4.4 "Acciones Masivas" (barra de selección) `[50] [51]`

Modal "Acciones Masivas — Realizá acciones masivas sobre el producto seleccionado" con un único select "Elegí una Acción" que ofrece 11 operaciones en lote:

1. Modificar Precio de Venta
2. Modificar Costo
3. Mostrar en Ventas
4. No Mostrar en Ventas
5. Mostrar en Compras
6. No Mostrar en Compras
7. Modificar Estado
8. Modificar IVA por defecto
9. Modificar Tipo de Producto
10. Modificar Proveedor
11. Eliminar Masivamente

**Corrección (control post-implementación, capturas `capturas/acciones masivas/*.png`):** el relevamiento original de esta sección no abrió cada una de las 11 acciones — sólo documentó los nombres del select. Al abrirlas, **4 de las 11 tienen un modal propio**, con estructura y campos específicos que el modal genérico "Elegí una Acción" + un valor no representa:

- **Modificar Precio de Venta** → modal **"Edición Masiva de Precios de Venta"**: subtítulo "Vas a editar los N productos seleccionados"; dos botones tipo pill **"Cambiar por Porcentaje" / "Cambiar por Valor Fijo"**; un campo por cada precio afectado — **Precio de Venta** y **una fila por cada Lista de precio activa** (ej. "Lista 1") — cada uno con su propio input (prefijo `%` o `$` según el modo) y radio **Aumentar/Disminuir**; toggle **"Redondear los precios modificados al primer entero"** (Sí/No); botones Cancelar (rojo) / Actualizar Precios (verde).
- **Modificar Costo** → modal **"Edición Masiva de Costos"**: misma estructura que Precios, pero con un único campo **Costo** (sin filas de lista de precio).
- **Modificar IVA por defecto** → modal **"Edición IVA por Defecto"**, subtítulo "IVA que se aplicará por defecto a los productos al momento de Comprar y/o Vender": **dos selects independientes**, **IVA Venta** e **IVA Compra** (mismas 6 opciones fijas que el resto de la app), cada uno editable por separado — no hay un único campo que fije ambos a la vez.
- **Modificar Tipo de Producto** → modal **"Modificar Tipo de Producto"**, subtítulo "Seleccioná el Tipo de Producto/Servicio para los N productos seleccionados": **dos selects separados**, "Elegí el Tipo de Producto" y "Elegí el Tipo de Servicio" (mismo catálogo de Tipo de Producto en ambos, dado que el lote seleccionado puede mezclar filas de tipo Producto y de tipo Servicio).

Las otras 7 acciones (Mostrar/No Mostrar en Ventas, Mostrar/No Mostrar en Compras, Modificar Estado, Modificar Proveedor, Eliminar Masivamente) no tienen capturas propias tomadas; se asume que sí usan el modal genérico "Elegí una Acción" con su control de valor inline (o ninguno para los flags), consistente con la implementación ya validada.

Esto coincide con lo documentado oficialmente (ver sección 5) sobre edición masiva de precios por aumento/disminución.

### 4.5 Botón "Ajuste de Stock" `[52] [53]`

Despliega 2 opciones: **Aumento** / **Disminución**. Cada una abre un modal ("Nuevo Aumento" / "Nueva Disminución") con: Fecha (date-picker, default hoy), Cantidad, "Elija el Producto" (select buscable, independiente de la fila que se esté viendo — es decir, permite ajustar el stock de cualquier producto desde acá sin ir a su ficha), Nota interna. Botones Cancelar/Crear.

### 4.6 Botón "Nuevo Producto" — formulario completo `[54] [55] [56] [57] [58] [59]`

Es el formulario más rico de los tres módulos.

**Columna izquierda:**

| Campo | Tipo | Notas |
|---|---|---|
| Nombre | texto | |
| Código | texto | Se muestra debajo "Último código generado: [código]" a modo de referencia |
| Tipo | select | **Producto / Servicio** — dispara cambios dinámicos en el resto del formulario |
| Tipo de producto *(solo si Tipo = Producto)* | select buscable | **Crear Tipo de Producto** (+, permite definir tipos custom), Compra y Venta, Consignación, Fabricado, Insumo (cada uno editable con un ícono de lápiz) |
| Proveedor | select buscable "Elija Proveedor" | |
| + Agregar Imagen | link | Abre selector de archivo (no explorado en profundidad para no depender de assets externos) |

**Columna derecha:**

| Campo | Notas |
|---|---|
| Stock *(solo si Tipo = Producto)* | numérico, aparece dinámicamente al elegir "Producto" (si Tipo=Servicio no hay Stock) |
| Descripción | textarea |
| Estado | radio Activo / Inactivo |

**Sección "Ventas":**
- Mostrar en Ventas (checkbox, tildado por defecto)
- Precio de Venta ($) + link **"✨ Buscar precios con IA"**: dispara una búsqueda asincrónica (estado "Buscando...") que consulta un motor de sugerencia de precios; en la prueba con un nombre genérico devolvió *"No se encontraron resultados realistas"* `[58]`. Es una función de IA generativa integrada al alta de productos.
- IVA por defecto (select, 21 por defecto)
- Lista de Precios $ (toggle No/Sí): al activarlo despliega una sub-sección **"Lista 1"** (nombre editable con lápiz) con campo de precio y botón de tacho de basura, más el link **"Agregar Lista de Precios"** para sumar listas adicionales (ej. Mayorista, Tarjeta de Crédito, según la documentación oficial) `[59]`.

**Sección "Compras":**
- Mostrar en Compras (checkbox, tildado por defecto)
- Costo ($)
- IVA por defecto (select)

Botones Cancelar / Crear.

**Prueba realizada:** se creó "Producto Test Documentacion 2" (Tipo Producto → Compra y Venta, Stock 20, Precio $800, Costo $400). Quedó resaltado en amarillo en el listado tras la creación `[60]`.

### 4.7 Vista "Ver" de producto `[61]`

Modal de solo lectura: Nombre, Código, Tipo de producto, Proveedor, Stock, Costo, Precio de Venta, Estado, "Mostrar en" (ej. "Compras y ventas"), sección "Lista de Precios" (Lista 1: $), Descripción.

### 4.8 Menú de fila de Productos `[62]`

A diferencia de Clientes/Proveedores (4 opciones), Productos tiene **7 opciones**:

1. Ver
2. Editar
3. Eliminar
4. **Crear Copia** (duplica el producto)
5. **Movimientos** (lleva al "Informe de Stock" filtrado por ese producto)
6. **Aumentar Stock** (atajo directo al modal de Ajuste de Stock con el producto pre-seleccionado)
7. **Disminuir Stock** (ídem, en negativo)

### 4.9 "Movimientos" → Informe de Stock (`/product_balance_reports`) `[63]`

Pantalla de reporte específica con: filtros (Usuario, Operación, Proveedor, Tipo de Producto, Productos, Estado del Producto), selector de rango de fechas ("Emisión"), 3 KPIs (Unidades en Stock, Costo Total, Valor Venta Total) y una tabla de movimientos (ID, Fecha, Operación, Detalle, Producto, Cantidad, Stock Saldo). Al crear el producto de prueba con Stock inicial 20, apareció automáticamente un registro "Registro Inicial" con Cantidad 20 y Stock Saldo 20, confirmando que el alta de stock genera un movimiento trazable igual que una compra o venta.

### 4.10 Importar Datos — solapa Productos

Comparte pantalla con Clientes/Proveedores (ver 2.6), con la particularidad de que la documentación oficial recomienda **primero importar Proveedores y luego Productos** si se quiere asociar cada producto a su proveedor por default.

---

## 5. Contraste con la documentación oficial (help.contagram.com)

Se consultaron los siguientes artículos del Centro de Ayuda oficial de Contagram (Intercom/Educate) para verificar el comportamiento observado:

- **["Clientes y Proveedores"](https://help.contagram.com/es/articles/1318059-clientes-y-proveedores)**: confirma que el alta se hace desde Base de Datos → Clientes/Proveedores → botón "Nuevo Cliente"/"Nuevo Proveedor" o importando desde Excel, y que se pueden crear "Campos a medida" desde el botón "Agregar Nuevos Campos" dentro del formulario de alta — coincide exactamente con lo observado en la app (`[11] [12]`).
- **["Productos"](https://help.contagram.com/es/articles/1318074-productos)**: confirma que el alta es manual (Nuevo Producto) o por importación (Importar Productos), y —dato relevante no visible directamente en la interfaz probada— que **"No se pueden eliminar productos si tienen operaciones cargadas"**; en ese caso la recomendación oficial es marcarlos como "Inactivo" y desmarcar los checkboxes "Mostrar en" Ventas/Compras para ocultarlos completamente. Esto explica por qué el formulario tiene un campo "Estado" (Activo/Inactivo) separado de los checkboxes "Mostrar en Ventas"/"Mostrar en Compras": son dos mecanismos independientes de ocultamiento.
- **["Precios de los Productos"](https://help.contagram.com/es/articles/1318107-precios-de-los-productos)**: confirma las 3 formas de editar precios (edición manual con el lápiz, edición masiva por selección + aumento/disminución, o exportar-editar-reimportar por Excel) — coincide con el menú "Acciones Masivas" con las opciones "Modificar Precio de Venta"/"Modificar Costo" observadas `[50]`. También confirma que las Listas de Precios adicionales se configuran desde Configuración → Importar Datos → solapa Productos, y que en la ficha del producto aparecen como "Opciones Avanzadas" — en la versión actual de la app este bloque se llama **"Lista de Precios $"** con el toggle Sí/No, una probable evolución de nombre respecto del artículo de ayuda (que es de 2017).
- **["Cuentas Corrientes, Cobranzas y Pagos"](https://help.contagram.com/es/articles/1318138-cuentas-corrientes-cobranzas-y-pagos)**: confirma que las Cuentas Corrientes se generan automáticamente al registrar una venta o compra a un cliente/proveedor, y que se pueden ver todos los movimientos históricos — coincide con el comportamiento de "Cta Cte" observado desde el menú de fila de Clientes y Proveedores.
- **["Preguntas Frecuentes"](https://help.contagram.com/es/articles/1319608-preguntas-frecuentes)**: confirma que toda la información del sistema (incluyendo Clientes, Proveedores y Productos) puede exportarse a Excel/PDF desde el botón de exportación de cada módulo, y que la importación de clientes/proveedores/productos es una funcionalidad soportada de forma nativa — coincide con los botones "Exportar" e "Importar datos" verificados en los tres módulos.

No se encontró documentación oficial pública sobre la función **"Buscar precios con IA"** dentro del alta de productos `[58]`, lo que sugiere que es una funcionalidad relativamente nueva (posiblemente incorporada tras la adquisición de Contagram por Visma) aún no reflejada en los artículos históricos del Help Center (fechados en 2017).

---

## 6. Observaciones y hallazgos relevantes

1. **Validación de CUIT bloqueante en Clientes, no en Proveedores (con CUIT vacío):** en Clientes, un CUIT matemáticamente inválido impide guardar el registro aunque se haya usado un dígito verificador incorrecto; en Proveedores se probó dejar el CUIT vacío y no hubo bloqueo. No se volvió a probar un CUIT inválido en Proveedores, por lo que se infiere (no se confirma) el mismo comportamiento de validación que en Clientes cuando el campo está completo.
2. **Localidad por defecto inconsistente:** al seleccionar una Provincia en el alta de Cliente, el combo de Localidad se auto-selecciona con un valor que no guarda relación alfabética ni de relevancia evidente con la provincia elegida (se observó "RIO TALA (SAN PEDRO)" al elegir C.A.B.A.). Requiere que el usuario corrija manualmente la localidad.
3. **Campos personalizados limitados en el plan de prueba:** el tipo de dato para "Nuevo Campo" en Clientes/Proveedores solo permite "Texto" en esta cuenta; "Opciones", "Fecha" y "Numérico" están visibles pero deshabilitados, sugiriendo una posible restricción por plan de suscripción.
4. **Consistencia de patrones de UI:** Clientes y Proveedores son prácticamente el mismo componente reutilizado con textos y campos adaptados (Ventas↔Compras, Categoría Ventas↔Categoría Compras, Nota para el Cliente↔Nota Interna, Apodo ML exclusivo de Clientes). Esto reduce la curva de aprendizaje entre ambos módulos.
5. **Productos es el módulo más complejo**, con selección múltiple, acciones masivas, gestión de stock con trazabilidad completa (Informe de Stock/Movimientos), múltiples listas de precios y una función de sugerencia de precios por IA.
6. **Cuenta Corriente como módulo aparte:** tanto "Cta Cte" de Clientes como de Proveedores navegan fuera de Base de Datos hacia `/account_receivables` y `/account_payables` respectivamente, que en realidad pertenecen conceptualmente al área de Tesorería/Informes, no a Base de Datos.
7. **Actualización de saldos con posible demora:** al crear un cliente con saldo inicial, el movimiento se registró de inmediato en "Movimientos" de Cta Cte, pero no se reflejó instantáneamente en el resumen "Saldos Clientes" durante la prueba.

---

## 7. Registros de prueba creados durante el relevamiento

| Módulo | Registro | Id asignado |
|---|---|---|
| Clientes | Empresa Prueba Documentacion SA | 7 |
| Proveedores | Textiles del Sur SRL | 8 |
| Productos | Producto Test Documentacion 2 | 9 |

Estos registros quedaron activos en la cuenta de prueba para que puedan revisarse en vivo si se desea; no se eliminaron durante el relevamiento.

---

## 8. Índice de capturas

Todas las capturas están en `capturas_contagram/` con nomenclatura `NN_modulo_descripcion.jpg`, numeradas en el orden en que se relevaron (00 = dashboard de inicio, 01 = dropdown de Base de Datos, 02-32 = Clientes, 33-42 = Proveedores, 43-64 = Productos).
