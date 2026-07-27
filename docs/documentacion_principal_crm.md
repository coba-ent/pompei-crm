# Documentación Principal del Proyecto

**Sistema de Gestión (CRM) — Investigación funcional de Contagram**

Preparado por Federico Gundel · 17/07/2026 · Recortado a alcance actual el 24/07/2026

Este documento releva el funcionamiento del sistema de gestión Contagram (www.contagram.com), utilizado como referencia funcional para el desarrollo de un CRM propio. La información fue recopilada del Centro de Ayuda oficial de Contagram (help.contagram.com).

> **Estado del proyecto (24/07/2026):** el relevamiento funcional original cubría los 8 módulos de Contagram, pero se detectó que esa documentación no reflejaba con precisión suficiente el negocio real. Se descartó todo el código construido sobre esos módulos (Ventas, Compras, Tesorería, Facturación Electrónica, Informes, Integraciones, Gastos, Presupuestos, Abonos, Otros Ingresos, Cuentas Corrientes, Retenciones, Proveedores) y se conserva únicamente lo que está validado y funcionando: **Base de Datos → Clientes y Productos & Servicios**, más **Usuarios y Permisos**. Este documento fue recortado para reflejar sólo ese alcance vigente; la documentación de los módulos descartados se irá rehaciendo módulo por módulo, a medida que se retomen, con relevamiento corregido.
>
> **Actualización (spec 003, 24/07/2026):** se reincorporó **Base de Datos → Proveedores** (re-relevado,
> espejo de Clientes con las diferencias documentadas en §2.3) y se agregó la pantalla **Informe de
> Stock** (§4.2), reemplazando el modal simple de histórico de Productos. Ver §2.3 y §4.2.
>
> **Actualización (24/07/2026):** se re-relevó el módulo **Ingresos** (Presupuestos, Ventas, Otros
> Ingresos — `docs/informe_contagram_ingresos.md`, capturas 65-98) y **Abonos** (feature avanzada que
> agrega una entrada al menú Ingresos — `docs/informe_contagram_funciones_avanzadas.md §6`, capturas
> 108-115). Documentado en la nueva §3 de este archivo, **todavía no implementado** — pendiente de
> `/speckit-specify`. El relevamiento expuso dependencias con módulos aún no reconstruidos (Tesorería,
> Cuenta Corriente, Facturación Electrónica) que no se simplifican por la regla de oro — ver §3.5.
>
> **Actualización (spec 007, 24/07/2026):** se implementó el módulo **Tesorería** (Saldos, Movimientos,
> configuración de cuentas, transferencias, ficha/ledger — `docs/informe_contagram_tesoreria.md`,
> capturas 144-162), resolviendo la dependencia de "medios de cobro" de Ingresos. Ver §3.7.

---

## 1. Stack tecnológico

- **Backend:** Laravel 12 (PHP). Estructura MVC estándar de Laravel, con Eloquent como ORM.
- **Base de datos:** MySQL (XAMPP local). El esquema se gestiona mediante **migraciones** de Laravel, versionadas junto al código.
- **Autenticación y permisos:** sistema propio de roles/permisos (tablas `roles`, `permisos`, pivots) — ver sección 3.
- **Single-tenant:** este sistema se desarrolla para un único negocio/cliente particular. No hay aislamiento multi-empresa.
- **Frontend:** template Bootstrap 5 NexaDash (Laravel Blade) + Vite/Tailwind. Tablas con DataTables server-side, altas/ediciones por modales Bootstrap + AJAX, notificaciones con Toastr, selects dinámicos con Select2 (ver `CLAUDE.md` para las reglas de diseño obligatorias).

El modelo de datos completo (entidades, campos y relaciones) está detallado en el archivo `modelo_datos.md`, y las migraciones de Laravel correspondientes en `database/migrations/`.

---

## 2. Módulo Base de Datos

### 2.1 Clientes

- Alta manual (botón "Nuevo Cliente"). El único campo obligatorio es **Cliente** (nombre / razón social). El resto es opcional. Botón "Importar datos" en la toolbar, que abre el asistente de importación por Excel/CSV (§2.4, spec 006).
- Datos básicos: Cliente*, Nombre, Apellido, Cel., Teléfono, Email, Apodo ML, Página Web, Domicilio,
  Provincia, C.P., Localidad, Nota. Provincia y Localidad son **selects linkeados** (la localidad
  depende de la provincia elegida), poblados desde el catálogo geográfico oficial argentino (georef).
  La app es sólo para Argentina, así que no hay campo País. Igual criterio en el bloque de datos de
  facturación.
- Personas de contacto: se pueden cargar **varias** por cliente ("+ Agregar Persona de Contacto"),
  cada una con nombre, apellido, teléfono, celular y email, más el check "Enviar también mails a esta
  dirección".
- Campos personalizables ("+ Agregar Nuevo campo"): abre el modal "Crear nuevo campo" con Nombre + Tipo
  (**Texto, Numérico, Fecha, Opciones**; para Opciones se carga la lista de valores). Cada campo es
  **propio de ese cliente** (no es un catálogo global): se arma en el front del modal y se guarda
  asociado al cliente recién al guardar.
- Ventas: Categoría, Lista de Precios, Descuento General (%), Nota para el Cliente, Saldo Inicial.
- Datos de facturación: Razón social, N° de Doc (tipo CUIT/CUIL/DNI/… + número), Condición de IVA, Tipo
  de comprobante por defecto, y domicilio/teléfonos fiscales (separados del domicilio comercial). La
  verificación de CUIT contra ARCA/padrón fiscal quedó fuera de alcance junto con el resto de la
  integración fiscal (se retoma cuando se rehaga Facturación Electrónica).
- Listado: tabla con columnas Id, Cliente, Nombre, Apellido, Mail, Teléfono, Teléfono Celular,
  Domicilio, Localidad, Provincia, DNI, CUIT, Condición de IVA, Usuario de Mercado Libre, Nota,
  Página Web (DNI y CUIT se muestran en columnas separadas según el tipo de documento). Buscador
  único que busca por cualquier dato del cliente. Selector de columnas visibles con la preferencia
  recordada en el navegador. Acciones por fila en menú desplegable: Ver, Editar, Inactivar/Reactivar,
  Eliminar.

*Fuente(s): [Clientes y Proveedores](https://help.contagram.com/es/articles/1318059-clientes-y-proveedores)*

### 2.2 Productos & Servicios / Stock

**Formulario "Nuevo Producto":** dos columnas de datos generales arriba (izq: Nombre, Código con
sugerencia del último código generado, Tipo, "+ Agregar Imagen"; der: **Stock inicial + Depósito**
(sólo al crear, con Tipo=Producto), Estado Activo/Inactivo) y debajo dos secciones lado a lado
**Ventas** (Mostrar en Ventas, Precio de Venta, IVA por defecto, Lista de Precios) y **Compras**
(Mostrar en Compras, Costo, IVA por defecto).

- **Stock inicial** (numérico) + **Depósito** (select): visibles únicamente al crear un producto de
  Tipo=Producto (se ocultan al editar y para Tipo=Servicio). Si se carga una cantidad > 0, al guardar
  se genera un movimiento de stock con descripción "Registro inicial" (equivalente al comportamiento
  real de Contagram, confirmado en el informe de relevamiento: crea un producto con stock inicial y
  aparece un movimiento "Registro Inicial" trazable). El ajuste de stock posterior a la creación se
  sigue haciendo por la acción aparte "Ajuste de Stock".

- **Tipo**: sólo dos valores, **Producto** o **Servicio** (los servicios no controlan stock; dispara
  cambios dinámicos en el resto del formulario).
- **Tipo de Producto** *(sólo si Tipo = Producto)*: select buscable, **sí es un campo real del
  sistema** (catálogo `tipos_producto`, compartido con "Tipo de Servicio" cuando Tipo = Servicio) —
  confirmado con capturas reales (`informe_contagram_base_de_datos.md` §4.4/§4.10): **Compra y Venta,
  Consignación, Fabricado, Insumo**, más la opción **"Crear Tipo de Producto"** (+) para agregar
  valores custom, cada uno editable. Aparece también en el listado (columna), panel de Filtros, y en
  "Acciones Masivas" → **Modificar Tipo de Producto** (dos selects separados, Producto/Servicio, mismo
  catálogo). **Ojo con planillas de clientes que traen una columna del mismo nombre** ("Tipo de
  Producto/Servicio") pero con valores de rubro propios (ej. Griferia, Repuesto, Sanitario…): esos
  valores **no** corresponden al catálogo real de Contagram y no van a matchear al importar — es
  meramente una coincidencia de nombre de columna, no el mismo campo de datos.
- **IVA por defecto** (ventas y compras): **desplegable con opciones fijas** — `5`, `10,5`, `21`,
  `27`, `Exento`, `No Gravado` (default `21`). No es un porcentaje de texto libre. "Exento" y "No
  Gravado" computan 0% pero se conservan como opción distinta. Implementación: se persiste el código
  de la opción en `productos.iva_venta_pct` / `iva_compra_pct` (string) y el % numérico se deriva con
  `Producto::porcentajeIva()`.
- **Estado** Activo/Inactivo (radios en el modal): un producto Inactivo no aparece en la base de
  datos salvo que se lo busque con los filtros.
- **Imagen**: campo "+ Agregar Imagen" en el modal (upload opcional). La columna "Imagen" del listado
  muestra SÍ/NO.
- **Opciones Avanzadas** (colapsable en el modal): contiene **Listas de precio** (toggle "Lista de
  Precios $" + gestión inline: agregar / renombrar / eliminar del catálogo global, con su precio por
  producto) y **Variantes** (talle/color/SKU, infraestructura presente pero UI oculta — ver más abajo).

**Toolbar del listado:** botón **Filtros** (abre panel colapsable), **selector de columnas**
(mostrar/ocultar), **Ajuste de Stock** (dropdown: Aumento / Disminución / Movimiento entre Depósitos)
y **Nuevo Producto**. Debajo de los KPIs de cantidad (Total, Activos, Servicios, Stock total) hay dos
KPIs en pesos: **Costo total del stock** y **Valor de venta total del stock** (cantidad en stock ×
costo/precio de cada producto, sumado). Equivalen al ícono "Ver Totales" de Contagram.

**Panel de Filtros:** Id, Producto/Servicio (nombre o código), Estado, Tipo, **Tipo de Producto**
(catálogo `tipos_producto`), Depósito, Stock menor que, Stock mayor que.

**Columnas del listado (dinámicas para las listas de precio):** Id, Nombre, Código/SKU, Tipo, Tipo de
Producto, Costo, Precio venta, **una columna por cada lista de precios activa** (si se crea o borra
una lista desde "Opciones Avanzadas" del modal, el listado y el export CSV la reflejan sin tocar
código — no es una columna fija "Lista 1"), IVA Ventas, IVA Compras, Stock total, **Descripción
(SI/NO)**, Imagen (SI/NO), Estado, Acciones.

**Acciones por fila** (dropdown): Ver · Editar · Eliminar · Crear Copia · Inactivar/Reactivar ·
Movimientos · Aumentar Stock · Disminuir Stock.

**Selección múltiple + "Acciones Masivas"** (spec 004): cada fila tiene un checkbox (además del menú
de acciones) y el header de la tabla un checkbox "seleccionar todo" (sólo afecta la página visible).
Al marcar al menos un producto aparece una barra sobre la tabla: *"N productos seleccionados. Haga
click aquí para realizar acciones. Seleccionar los N productos."* — este último link amplía la
selección a **todos** los productos que matchean el filtro/búsqueda vigente (no sólo la página),
resuelto en el backend reconstruyendo la misma query de la DataTable (sin mandar miles de IDs al
cliente). La selección se limpia automáticamente al cambiar de página, filtro, orden o búsqueda.

El modal "Acciones Masivas" ofrece 11 operaciones en lote, en este orden: Modificar Precio de Venta,
Modificar Costo, Mostrar en Ventas, No Mostrar en Ventas, Mostrar en Compras, No Mostrar en Compras,
Modificar Estado, Modificar IVA por defecto, Modificar Tipo de Producto, Modificar Proveedor,
Eliminar Masivamente. **4 de esas 11 acciones abren su propio modal dedicado** en Contagram real
(confirmado con capturas — `capturas/acciones masivas/`), no el modal genérico "Elegí una Acción" +
un único valor:

- **Modificar Precio de Venta / Modificar Costo** → modal "Edición Masiva de Precios de
  Venta"/"Costos": toggle **Cambiar por Porcentaje / Cambiar por Valor Fijo**, un campo por cada
  precio afectado (Precio de Venta **y cada Lista de precio activa**, o sólo Costo) con su propio
  radio **Aumentar/Disminuir**, y un toggle "Redondear... al primer entero". El ajuste se calcula
  **sobre el valor actual de cada producto** (no fija un valor único para todo el lote): `nuevo =
  max(0, actual ± (modo=porcentaje ? actual*valor/100 : valor))`, redondeado si se pidió.
- **Modificar IVA por defecto** → modal "Edición IVA por Defecto": **IVA Venta e IVA Compra son
  selects independientes** (no se fuerza el mismo valor en ambos, a diferencia de una asunción
  anterior descartada).
- **Modificar Tipo de Producto** → modal con **dos selects separados**, "Elegí el Tipo de Producto" y
  "Elegí el Tipo de Servicio" (mismo catálogo `tipos_producto`, compartido): cada uno aplica sólo a la
  porción del lote seleccionado cuyo `tipo` (producto/servicio) coincide — el lote puede mezclar
  ambos.

Las otras 7 acciones (Mostrar/No Mostrar en Ventas/Compras, Modificar Estado, Modificar Proveedor,
Eliminar Masivamente) sí usan el modal genérico "Elegí una Acción" con el control de valor inline
correspondiente (o ninguno, para los flags simples). Las acciones de valor único son atómicas para
todo el lote (si el valor no pasa validación, no se aplica a ninguno); "Eliminar Masivamente" es la
excepción — se evalúa producto por producto vía `Producto::tieneOperaciones()` (mismo criterio que el
`destroy()` individual), y la respuesta detalla cuáles no se eliminaron y por qué ("tiene operaciones
asociadas").

**Operaciones de stock** (Ajuste de Stock):
- **Aumento / Disminución**: Fecha (hoy por defecto) · Cantidad · Producto · Depósito · Nota interna.
- **Movimiento entre Depósitos**: Fecha · Cantidad · Producto · Depósito de Salida · Depósito de
  Entrada · Observación. Registra dos movimientos (`transferencia`) sin alterar el stock total.
- Edición de precios: manual (ícono lápiz) o vía "Opciones Avanzadas" del modal, por lista de precios.
- Stock: se ajusta manualmente desde Base de Datos → Productos → Ajuste de Stock (no hay Ventas/Compras
  todavía que lo muevan automáticamente).
- Consulta de stock actual: Base de Datos → Productos (columna Stock total) e historial de movimientos
  por producto (acción "Movimientos").
- Productos inactivos: no se pueden eliminar productos con operaciones cargadas (movimientos de stock)
  — se marcan como "Inactivo".
- **Variantes** (talle, color): la UI de alta de variantes está **oculta** en el modal — Contagram no
  la expone (su propio tooltip del Nombre sugiere cargar talle/color en el nombre). Se conserva la
  infraestructura (`producto_variantes`) para cuando se retome la integración con canales externos: el
  SKU debe ser **único por producto y por variante**, y el stock puede llevarse por variante y
  depósito. El controller NO borra variantes existentes cuando el payload no las incluye. Para
  reactivar la UI, quitar el `d-none` de `#seccion-variantes` en `_modal_form.blade.php`.

**Listas de precio**

- Catálogo global gestionable desde "Opciones Avanzadas" del modal de producto (agregar, renombrar,
  eliminar) — no hay una pantalla de Configuración/Importación separada por ahora.
- En cada producto se cargan los precios diferenciados por lista (ej.: Mayorista, Tarjeta de Crédito,
  Minorista).

**Depósitos múltiples**

- Cada depósito activo aparece como opción dentro de Base de Datos → Productos (filtros y ajustes de
  stock), permitiendo llevar stock diferenciado por depósito. El alta/baja de depósitos se gestiona
  desde Configuración & Ajustes → Depósitos (spec 005): modal "Configuración de Depósitos" con lista
  editable inline (nombre, checkbox de activo, editar, eliminar) + "+ Agregar Depósito", cada acción
  persistida por su propia llamada AJAX. No se puede eliminar físicamente un depósito con stock o
  movimientos asociados (`Deposito::tieneOperaciones()`) — sólo se puede inactivar.

*Fuente(s): [Productos](https://help.contagram.com/es/articles/1318074-productos) · [Precios de los Productos](https://help.contagram.com/es/articles/1318107-precios-de-los-productos) · [Stock](https://help.contagram.com/es/articles/1318095-stock) · [Manejo de Multistock](https://help.contagram.com/es/articles/10923135-manejo-de-multistock)*

### 2.3 Proveedores

Espejo estructural de Clientes (§2.1), con las diferencias documentadas abajo — reincorporado en el
spec 003 (24/07/2026) junto con el Informe de Stock (§4.2).

- Alta manual (botón "Nuevo Proveedor"). El único campo obligatorio es **Proveedor** (nombre de la
  empresa o nombre y apellido). El resto es opcional.
- Datos básicos: Proveedor*, Nombre, Apellido, Cel., Teléfono, Email, Página Web, Domicilio,
  Provincia, C.P., Localidad, Nota. Provincia/Localidad son selects linkeados, igual que en Cliente.
  **Sin "Apodo ML"** (exclusivo de Cliente, para Mercado Libre).
- Personas de contacto: mismo comportamiento que Cliente (0..N, con nombre/apellido/teléfono/
  celular/email + "Enviar también mails a esta dirección").
- Campos personalizables: mismo mecanismo que Cliente ("+ Agregar Nuevo campo", propio de cada
  proveedor).
- **Compras** (equivalente al bloque "Ventas" de Cliente): Categoría Compras (categorías tipo=compra),
  Nota Interna, Saldo Inicial. **Sin "Lista de Precios"** (Proveedor no vende con lista de precios) ni
  "Descuento General %".
- Datos de facturación: idéntico a Cliente (Razón social, N° de Doc + tipo, Condición de IVA, Tipo de
  comprobante por defecto, domicilio/teléfonos fiscales). Reutiliza la misma validación de CUIT.
- Listado: columnas Id, Proveedor, Nombre, Apellido, Mail, Teléfono, Teléfono Celular, Domicilio,
  Localidad, Provincia, DNI, CUIT, Condición de IVA, Nota, Página Web (sin "Usuario de Mercado
  Libre", exclusivo de Cliente). Buscador único, selector de columnas, exportar a CSV. Acciones por
  fila: Ver, Editar, Inactivar/Reactivar, Eliminar.
- **No se puede eliminar físicamente un proveedor con productos asociados** (`productos.proveedor_id`)
  — sólo se puede inactivar (mismo patrón que Cliente/Producto).
- Cada producto puede tener un **Proveedor** asociado (selector con buscador en el modal de Producto),
  reflejado como columna y filtro en el listado de Productos (§2.2).

*Fuente(s): [Clientes y Proveedores](https://help.contagram.com/es/articles/1318059-clientes-y-proveedores)*

### 2.4 Importar Datos

Asistente por páginas reales (única pantalla de la app que no usa modal, excepción documentada) con
3 solapas: Clientes, Proveedores, Productos & Servicios — reincorporado en el spec 006
(24/07/2026). En Contagram real son 4 solapas (Productos y Servicios separados); acá se unifican
porque ya son un único modelo (`Producto` con campo `tipo`).

- **Paso 1** (`/importar-datos/{entidad}`): botón "Seleccionar Archivo" (.xls/.xlsx/.csv, máx.
  10MB), paneles "Acerca de la importación" y "Notas Técnicas" (recomienda importar primero
  Proveedores si la solapa activa es Productos & Servicios, para poder asociarlos por nombre).
- **Paso 2** (mapear): vista previa de las primeras filas del archivo; por cada columna detectada, un
  select con los campos destino de la entidad, "No importar", o "Campo personalizado" (con nombre a
  elección — no disponible para Productos, que no tiene campos personalizables). No se puede
  confirmar sin el campo obligatorio (Cliente/Proveedor/Nombre) mapeado, ni con dos columnas
  mapeadas al mismo campo.
- **Paso 3** (confirmar → resumen): cada fila se valida y se crea de forma independiente
  (`ReglasCliente`/`ReglasProveedor`/`ReglasProducto`, las mismas del alta manual); una fila inválida
  se omite y se reporta con su motivo, sin abortar el resto del archivo. En Productos, la columna
  "Proveedor" (y Categoría/Condición de IVA/Tipo de Producto) se resuelve por nombre existente sin
  distinguir mayúsculas/acentos — si no matchea, el producto se crea igual sin ese dato, reportado
  como advertencia (no como fallo); "Tipo" sin mapear o vacío usa "Producto" por defecto.
- Cancelable en cualquier paso antes de confirmar, sin dejar ningún registro creado. El archivo
  subido y el mapeo elegido son estado transitorio (disco temporal `storage/app/private/imports/` +
  sesión) — nunca se persisten en base de datos, y no hay detección de duplicados en esta versión
  (importar el mismo archivo dos veces crea registros nuevos).

*Fuente(s): `docs/informe_contagram_base_de_datos.md` §2.6/§4.10*

---

## 3. Módulo Ingresos

> Re-relevado el 24/07/2026 con navegación real de `app.contagram.com` (cuenta de prueba), incluyendo
> el flujo completo Presupuesto → Venta → Cobranza y la activación real del toggle "Abonos". Fuente:
> `docs/informe_contagram_ingresos.md` (Presupuestos/Ventas/Otros Ingresos, capturas 65-98) y
> `docs/informe_contagram_funciones_avanzadas.md §6` (Abonos, capturas 108-115).
>
> **Implementado (spec 008-ingresos-ventas-presupuestos, 30/07/2026)**: §3.1 Presupuestos, §3.2 Ventas
> (comprobante A/B/C/E como dato sin CAE, Cobranza contra cuentas de Tesorería, NC/ND con impacto en
> stock, Remitos como encabezado mínimo) y §3.3 Otros Ingresos, tal como quedaron acotados en
> `specs/008-ingresos-ventas-presupuestos/spec.md`. Quedan **fuera de alcance y documentados como
> pendientes** (UI deshabilitada, no falsa): §3.4 Abonos, Cta Cte, Facturación Electrónica real (CAE),
> Retenciones sobre Cobranza, Recibos, integración WhatsApp y el botón "Analizar" (IA) — ver §3.5.

El menú lateral "Ingresos" despliega 3 opciones — Presupuestos, Ventas, Otros Ingresos — más una
cuarta, **Abonos**, que sólo aparece (de forma permanente) cuando esa función avanzada está activada.
Presupuestos y Ventas forman un flujo secuencial (un Presupuesto se convierte en Venta con un clic);
Otros Ingresos y Abonos son independientes.

### 3.1 Presupuestos (`/budgets`)

- Listado con barra de **5 KPIs** (Ventas, Vencidos/Rechazados, Pendientes sin enviar/enviados,
  Aceptados, Total Posibles) y **18 columnas**: Estado, Id, Emisión, Vencimiento, Cliente, Categoría,
  Nro. Presupuesto, Subtotal sin Descuento, Descuento, Subtotal con Descuento, Total, Etiquetas, Nota
  Cliente, Nota Interna, Lista de Precios, Vendedor, Formas de Pago, Métodos de Envío.
- Filtros (15 campos): Id, Producto/Servicio, Cliente, Estado del Presupuesto, Categoría de Venta, N°
  de Presupuesto, Etiqueta, Vendedor, Formas de Pago, Métodos de Envío, Usuario, Nota para el Cliente,
  Nota Interna, Servicio Desde/Hasta.
- Menú de fila (badge de Estado ▾, 10 opciones en 4 bloques): Ver/Editar/Eliminar · cambio de estado
  directo (Pendiente/Rechazado/Aceptado) · **Crear Venta** (convierte el presupuesto) ·
  Ver/Imprimir/Enviar Presupuesto.
- "Ver" **no abre modal**: navega a una página completa con formato documento imprimible (a diferencia
  de Clientes/Proveedores/Productos). Igual criterio para "Nuevo Presupuesto" (`/budgets/new`):
  formulario de página completa a dos columnas, no modal — excepción documentada al patrón de modales
  de esta app, igual que Importar Datos (§2.4).
- Formulario: Cliente (buscador) · Categoría (con creación/edición inline) · Emisión/Validez · Servicio
  Desde/Hasta · Lista de Precios · tabla de Conceptos (producto, cant., precio, desc., subtotal, IVA,
  total; menú Ver/Editar + tacho por fila) · Nota Cliente/Interna · Formas de Pago y Métodos de Envío
  (texto libre) · Etiquetas (catálogo con buscador + "Nueva Etiqueta") · Descuento General (%) · Total
  · **+ Percepciones / + Impuestos Internos / + Intereses** (cada uno agrega N filas de
  selector+monto+tacho). Botones Cancelar/Guardar/Guardar y Enviar.
- **Autocompletado por Cliente**: si el cliente tiene Categoría de Ventas y Descuento General cargados
  en su ficha (§2.1), el formulario los autocompleta al seleccionarlo — confirma en la práctica lo que
  ya documentaba el formulario de Cliente.

### 3.2 Ventas (`/sales`)

- **Flujo Presupuesto → Venta**: "Crear Venta" navega a `/sales/new?budget=ID` pre-cargado (cliente,
  categoría, productos, notas, descuento). Se suman: Tipo de Comprobante (**A/B/C/E**), N° de
  Comprobante (autogenerado), Vto. del Cobro. El botón "Guardar y Enviar" se reemplaza por **Cobrar**.
- **Modal de Cobranza**: al Cobrar, la venta se guarda y se abre el modal con Total Venta/A Cobrar,
  campo "Cobrar" editable (permite cobro parcial) y una grilla de **medios de cobro** — idéntica a las
  cuentas configuradas en Tesorería (Caja del Local, Caja General, Banco Galicia, Banco Santander Río,
  Mercado Pago, AMEX, VISA, Cheque de Terceros). Al elegir un medio, la venta queda **Cobrada** de
  inmediato y el formulario vuelve a "Nueva Venta" en blanco.
- Listado: **19 columnas** — igual que Presupuestos más "Creada Desde" (Presupuesto/Venta directa), A
  Cobrar, Cobrado, Medio de Cobro (con link a la cuenta de Tesorería); columnas ocultas opcionales:
  Envío de Mail, CUIT, Servicio Desde/Hasta.
- Filtros (11 campos): Id, Cliente, Estado del Cobro, Categoría de Venta, Facturado, Tipo y N° de
  Factura, Etiqueta, Vendedor, Medio de Cobro, Usuario, Nota Cliente/Interna, Creada Desde, Servicio
  Desde/Hasta.
- Botón **"Analizar" (IA/Gemini)**: exclusivo de Ventas — genera un resumen del período (producto
  estrella, categoría más rentable, récord de venta, recomendación de negocio), con advertencia
  explícita de que "puede no ser del todo precisa o real". Misma tecnología (Gemini) que "Buscar
  precios con IA" en Productos (§2.2, no implementado en este CRM).
- Menú de fila (12 opciones, el más completo de la app): Ver/Editar/Eliminar · Agregar Cobranza / Crear
  NC/ND / Crear Remito / **Cta Cte** · Ver Detalle (**PDF**, a diferencia de "Ver" en Presupuestos) /
  Imprimir Detalle / Imprimir Ticket / Enviar Detalle / Enviar WhatsApp.
- **Crear NC/ND** *(corroborado y ampliado contra `help.contagram.com`, ver §3.6)*: wizard de 2 pasos.
  Paso 1: Tipo (Crédito/Débito), Documento que Ajusta, "¿Afecta Stock?" — si es Sí: traer los productos
  de la venta original con sus valores preexistentes, o elegir productos nuevos; si es No: sólo
  descripción, sin productos. Paso 2 ("Siguiente"): Fecha de Emisión, Monto, Tipo de comprobante (igual
  al de la factura original), Descripción, Impuestos aplicables. Al guardar se puede solicitar
  aprobación ante ARCA.
- **Agregar Retención** *(corroborado, ver §3.6 — resuelve la duda abierta en
  `informe_contagram_funciones_avanzadas.md`)*: no está en el modal simple "Nuevo Cobro"; el botón vive
  **dentro de la sección de Cobranzas del Detalle de Venta**. Campos: Fecha, Monto, Tipo de Retención,
  N° de Comprobante (opcional), Descripción (opcional). Se refleja en el informe "Cuentas Corrientes de
  Clientes" (pestaña Movimientos). Requiere la función avanzada "Retenciones" activada.
- **Detalle de Venta** (`/sales/:id`): barra de ecuación (Total Venta + ND − NC = Cobrado → A Cobrar),
  tabla de Cobranzas (Id, Fecha, Medio de cobro, Nota, Total, Comprobante) con "+ Agregar Cobranza" y
  ahora también "+ Agregar Retención", documento imprimible con sello **"NO VÁLIDO COMO FACTURA"**
  (cuenta sin AFIP real habilitado), sección de Notas de Crédito/Débito, botón "Crear Remito".
- **Cheques** (medio de cobro/pago especial, corroborado sólo por doc oficial — sin capturas propias
  todavía): "Cheque de Terceros" recibido en una Cobranza no es un medio de cobro simple — es una
  cuenta de Tesorería con su propio ciclo: se registra la cobranza contra "cheques de terceros" (con
  N° y fecha de depósito en observaciones), y al depositarlo se hace una **transferencia entre cuentas**
  ("cheques de terceros" → "banco"). Simétrico para "cheques propios" emitidos en Pagos a Proveedores.
  Ambos se siguen desde Tesorería.

### 3.3 Otros Ingresos (`/incomes`)

Deliberadamente minimalista — sin selector de columnas, sin "Analizar", sin acciones masivas.

- Listado: 7 columnas — Estado, Id, Fecha, Categoría, Descripción, Medio de Cobro, Monto.
- Filtros (6 campos): Id, Categoría, Medio de Cobro, Estado del Cobro, Descripción, Usuario.
- Menú de fila: sólo Ver/Editar/Eliminar — el más simple de toda la app.
- Formulario "Nuevo Ingreso" (modal, un solo bloque): Fecha, Monto, **Categoría** (tipo=ingreso, con
  "Crear Categoría de Ingreso"; ej. Aportes Socios, Préstamos Financieros, Saldo), **Medio de Cobro**
  (mismo catálogo de cuentas de Tesorería que la Cobranza de Ventas), Descripción, checkbox **"Marcar
  como pendiente"** (registra el ingreso sin darlo por cobrado).

### 3.4 Abonos (feature avanzada — activable desde Configuración & Ajustes → Funciones Avanzadas)

Ventas recurrentes automáticas (suscripciones). Al activar el toggle, Contagram agrega
permanentemente **"Abonos"** al menú "Ingresos" (entre "Ingresos" y "Presupuestos").

- Listado: 5 KPIs (Abonos Activos, Abonos Inactivos, ventas creadas mes pasado/actual, $ del mes
  actual). Columnas: Estado, Id, Cliente, Frecuencia, Ventas Creadas, Venta Previa, Próxima Venta,
  Categoría, Tipo de Factura, Subtotal sin/con Descuento, Descuento, Importe Neto No Gravado (+ más
  al scrollear).
- Formulario "Nuevo Abono": mismo bloque de cliente/categoría/productos que Presupuestos/Ventas, más
  **"Configurar Periodicidad"**: Frecuencia (sólo **Mensual** habilitada en el plan de prueba — el resto
  deshabilitadas, mismo patrón de restricción de plan que los tipos de campo personalizado de
  Cliente/Proveedor) y "El Abono finalizará": **Nunca** (habilitado) / Después de N repeticiones /
  fecha específica (deshabilitados en el plan de prueba).
- Al guardar genera una venta inmediata (la "primera venta") y programa las siguientes según la
  frecuencia; el detalle muestra la frecuencia en texto natural ("Esta venta se creará Mensualmente el
  1er día de cada mes") y calcula Inicio/Fin de servicio automáticamente por mes de generación.

### 3.5 Dependencias no resueltas (regla de oro — no se simplifican)

El relevamiento fiel de Ingresos expone varias dependencias con módulos que siguen en
`§6 Módulos pendientes de re-relevamiento` y que **no** se resuelven construyendo una versión sin esa
dependencia (ver `CLAUDE.md`, principio rector):

- **Tesorería — resuelta (spec 007)**: el listado de "medios de cobro" en la Cobranza de Ventas y en
  Otros Ingresos ya tiene de dónde completarse — es el catálogo de cuentas visibles de Tesorería (§3.7,
  `tesoreria.cuentas.opciones`). Cuando Ingresos se implemente, sus cobros usan
  `Tesoreria::registrarMovimiento()` para impactar la cuenta elegida.
- **Cuenta Corriente**: el menú de fila de Ventas incluye "Cta Cte" (mismo gap ya aceptado para
  Clientes, §4.1).
- **Facturación Electrónica (ARCA/AFIP)**: Tipo de Comprobante A/B/C/E, numeración real de
  comprobantes, y el watermark "NO VÁLIDO COMO FACTURA" sólo tienen sentido pleno con ese módulo.
- **Remitos**: estructura de pantalla real de Contagram sigue sin relevar con capturas (§3.6 sólo pudo
  confirmar la forma genérica de un remito, no la pantalla real de la app).
- **Cheques**: recién detectado (§3.6) como su propio sub-ciclo dentro de Tesorería (cuentas "cheques de
  terceros"/"cheques propios" + transferencia al depositar), no un medio de cobro simple. Sin capturas
  propias todavía.
- **Recibos de Cobros y Pagos**: artículo propio en la colección oficial de Ventas, documento imprimible
  aparte del "Ver Detalle"/PDF ya relevado — no explorado en ningún informe con capturas.
- **integración WhatsApp** ("Enviar WhatsApp", visto deshabilitado en la cuenta de prueba) sin
  relevamiento propio.

Retenciones **ya no es una dependencia ciega**: §3.6 confirmó dónde vive el campo y qué datos pide, así
que el hallazgo pendiente en `informe_contagram_funciones_avanzadas.md` queda resuelto a nivel de regla
de negocio — sólo falta el relevamiento con capturas reales de esa pantalla (regla de oro: la fidelidad
de estructura de pantalla exige capturas, no alcanza con la doc oficial).

**Actualización (spec 007, 24/07/2026):** Tesorería (cuentas, transferencias, saldos, ficha/ledger e
informe de flujo de caja) ya está implementada — ver §3.7. El circuito de cheques (cuentas del sistema
Cheque de Terceros/Propio) queda modelado; el resto de las dependencias de esta lista (Cuenta Corriente,
Facturación Electrónica, Remitos, Recibos, WhatsApp) siguen pendientes.

**Actualización (spec 008, 30/07/2026):** Presupuestos, Ventas (comprobante A/B/C/E como dato + watermark
"NO VÁLIDO COMO FACTURA", Cobranza contra cuentas de Tesorería reales vía `Cobranzas::registrarCobro()`,
NC/ND con impacto en stock, Remitos encabezado) y Otros Ingresos ya están implementados. Siguen
pendientes, ahora ya sin bloquear ningún módulo construido: Cuenta Corriente ("Cta Cte" queda
deshabilitado en el menú de fila de Ventas), Facturación Electrónica real (CAE — el tipo/N° de
comprobante quedan listos para conectarlo), Remitos con detalle de ítems, Recibos, integración WhatsApp
("Enviar Whatsapp" deshabilitado) y Abonos (no construido en esta spec; su link en el sidebar no existe
todavía).

### 3.6 Corroboración contra documentación oficial (`help.contagram.com`, 24/07/2026)

Búsqueda y lectura de artículos oficiales para contrastar contra el relevamiento por capturas (§3.1 a
§3.4). **Ninguna discordancia real** con lo ya documentado — los artículos oficiales no bajan a nivel de
columnas/pantallas (varios remiten a un video), así que no contradicen las capturas; donde sí agregan
detalle es en reglas de negocio no capturadas:

1. **Retenciones**: resuelto — ver nota en §3.5. Coincide con el patrón ya visto (función avanzada,
   agrega un campo dentro de un flujo existente en vez de una pantalla nueva).
2. **Notas de Crédito/Débito**: confirmado que es un wizard de 2 pasos con más campos que los que
   alcanzó a capturar el informe original (Fecha de Emisión, Monto, Tipo, Descripción, Impuestos en el
   paso 2) — ver §3.2 actualizado.
3. **Cheques**: hallazgo nuevo no capturado en ningún informe — es un sub-ciclo de Tesorería, no un
   medio de cobro plano. Agregado como dependencia en §3.5.
4. **Otros Ingresos / categoría "Saldo"**: la doc oficial confirma que es específicamente para *cargar
   Saldos Iniciales* de una cuenta de Tesorería vía Otros Ingresos — coincide y aclara lo que el informe
   ya había listado como categoría "Saldo".
5. **Recibos de Cobros y Pagos**: artículo propio detectado, sin relevar — agregado a §3.5.
6. La colección oficial de Ventas confirma la agrupación en 2 bloques (gestión de venta / cobros) que ya
   reflejaba el informe con capturas, y no menciona ninguna pantalla o campo que contradiga lo
   documentado en §3.2.

*Fuente(s): [Presupuestos](https://help.contagram.com/es/articles/1319064-presupuestos) ·
[Colección Ventas](https://help.contagram.com/es/collections/12841887-ventas) ·
[Retenciones en Cobros y Pagos](https://help.contagram.com/es/articles/1319082-retenciones-en-cobros-y-pagos) ·
[Cuentas Corrientes, Cobranzas y Pagos](https://help.contagram.com/es/articles/1318138-cuentas-corrientes-cobranzas-y-pagos) ·
[Crear Notas de Crédito/Notas de Débito](https://help.contagram.com/es/articles/1319041-crear-notas-de-credito-notas-de-debito) ·
[Cheques](https://help.contagram.com/es/articles/1318124-cheques) ·
[Preguntas Frecuentes](https://help.contagram.com/es/articles/1319608-preguntas-frecuentes)*

*Fuente(s): `docs/informe_contagram_ingresos.md` · `docs/informe_contagram_funciones_avanzadas.md §6`*

### 3.7 Módulo Tesorería (implementado — spec 007)

Panel financiero centralizado single-tenant: consolida el estado de todas las cuentas de dinero del
negocio (cajas, bancos, y las cuentas virtuales "A Cobrar"/"A Pagar") y permite transferencias
internas. Resuelve la dependencia de "medios de cobro" señalada en §3.5. Fuente:
`docs/informe_contagram_tesoreria.md` (capturas 144-162).

**Pestaña Saldos** (`/tesoreria`, vista por defecto): tres bloques —**A Cobrar** (verde), **A Pagar**
(rojo), **Disponible** (celeste, con columnas Cajas/Bancos y su Total general)— cada uno con subtotal.
Control "Buscar por Fecha" (saldo a fecha de corte, sólo movimientos con fecha ≤ corte); botón
"Movimiento entre Cuentas"; ícono de ajustes (llave) para la configuración de cuentas. Saldo negativo
permitido sin bloqueo (descubierto).

**Configuración de cuentas** (modal "Ajustes Cuentas Tesorería"): tabla agrupada por tipo (Efectivo,
Banco, A Cobrar, A Pagar) con estado Visible. Alta/edición por modal único (tipo bloqueado en edición).
Dos **cuentas del sistema** precargadas —"Cheque de Terceros" (A Cobrar) y "Cheque Propio" (A
Pagar)— no editables ni eliminables, para modelar el circuito de cheques. Una cuenta con movimientos
(más allá de su Saldo Inicial) no se puede eliminar físicamente, sólo ocultar.

**Movimiento entre Cuentas** (transferencias internas, partida doble): modal con Fecha, Monto, cuenta
de salida/entrada (Select2 mostrando el saldo de cada cuenta) y Observación. Genera dos movimientos
vinculados (egreso + ingreso) en una transacción atómica; el Total Disponible del negocio no cambia.

**Ficha de cuenta** (`/tesoreria/cuentas/{id}`, libro mayor/ledger): tabla Id, Fecha, Operación,
Detalles, Ingreso, Egreso, **Balance** (saldo corrido resaltado), N° Factura, Observación. Filtro por
Tipo de Operación, selector de columnas, rango de fechas (default último mes) y exportar. El menú de
fila (Editar/Eliminar) sólo gestiona íntegramente los movimientos nativos de Tesorería (Saldo Inicial,
Movimiento entre Cuenta); eliminar una transferencia revierte ambas patas.

**Pestaña Movimientos** (`/tesoreria/movimientos`, informe de flujo de caja): banner explicativo,
selector de rango, resumen Total Cobros/Total Pagos/Resultado, secciones expandibles Cobros/Pagos con
desglose por cuenta y checkbox "Activo" (recalcula el total en vivo), Exportar y **Exportar a PDF**
(modal compartido) — único botón de exportación a PDF nativo relevado hasta el momento.

**Punto de enganche para otros módulos**: `App\Services\Tesoreria\Tesoreria::registrarMovimiento()` es
la API pública que Ingresos (Cobros de Ventas, Otros Ingresos) y a futuro Egresos (Pagos de Compras,
Gastos) usan para impactar una cuenta de tesorería, sin que Tesorería conozca cada módulo (origen
polimórfico en `movimientos_tesoreria`). Ver `modelo_datos.md §6`.

---

## 4. Módulo Egresos (Compras y Gastos)

> **Estado: implementado** (spec 009-egresos-compras-gastos). Antes figuraba acá como "documentado,
> pendiente de implementar"; ya está construido siguiendo exactamente esta sección.

Fuente: `docs/informe_contagram_egresos.md` (capturas [122] a [143], 24/07/2026). El menú lateral
"Egresos" se despliega en dos ítems, cada uno con su propio botón "+" de acceso rápido: **Compras**
(registro de compras a proveedores, con flujo documental completo) y **Gastos** (registro simple de
erogaciones operativas, sin vínculo a proveedores ni a productos). **Compras es estructuralmente el
espejo de Ventas** (§3.2): mismo esqueleto de listado, KPIs, filtros, formulario y ficha de detalle,
con Proveedor en lugar de Cliente. **Gastos no tiene equivalente en Ingresos** — es un módulo mucho
más liviano.

### 4.1 Compras (`/purchases`)

**Listado**: barra de KPIs con ecuación visual (Cantidad de Compras, Pagado, A Pagar, Vencido, Total
Compras). Controles: Filtros, dos selectores de rango de fecha (**Emisión** y **Vencimiento** — a
diferencia de Gastos, que sólo tiene uno), selector de columnas, botón **Nueva Compra**. Columnas por
defecto: Estado, Id, Emisión, Vencimiento, Proveedor, Categoría, Subtotal sin Descuento, Descuento,
Subtotal con Descuento, Total Compra, Pagado, A Pagar, Etiquetas, Medio de Pago (scroll horizontal).
Selector de columnas agrega CUIT, Servicio Desde/Hasta, Teléfono, Mail y otras. Estado editable inline
("Pagado"/"A Pagar", flecha desplegable).

**Filtros**: Id, Proveedor, Categoría, Estado del Pago, Etiquetas, Descripción/Nota, Usuario (mismo
patrón que Ventas).

**Menú de fila** (9 opciones, más liviano que el de Ventas que tiene 12 — sin "Imprimir Ticket",
"Enviar Detalle" ni "Enviar Whatsapp"): Ver, Editar, Ver Detalle, Agregar Pago, Crear NC/ND, Crear
Remito, Cta Cte (proveedor), Imprimir Detalle, Eliminar.

**Formulario "Nueva Compra"** (`/purchases/new`): Proveedor (autocompletado; al elegir un proveedor
existente precarga su **Categoría de Compras** guardada como default), Emisión, Vto. del Pago, Servicio
Desde/Hasta, **Contador** (campo exclusivo de Compras, sin equivalente en Ventas — tooltip: "Mes de
imputación en el IVA Compras, para el informe a tu Contador"; permite imputar el período fiscal de IVA
Compras independientemente de la fecha de emisión), Tipo de comprobante + numeración. Línea de
producto/servicio con buscador + ícono de **lector de código de barras** (materializa la Función
Avanzada homónima). Grilla de ítems: Producto, Cant., Precio, Desc. (%), Subtotal, **IVA**, Total —
**diferencia clave frente a Ventas**: el IVA **no viene preseleccionado** al agregar un producto
(muestra "Elegir" en vez del 21% automático de Ventas); mientras esté sin elegir, el panel de totales
muestra "Importe Neto No Gravado" en vez de "Importe Neto Gravado". Selector de IVA: mismas alícuotas
que Ventas (2,5% / 5% / 10,5% / 21% / 27%). Bloques repetibles + Percepciones / + Impuestos Internos /
+ Intereses (igual que Ventas). Nota Interna. Botones Cancelar/Guardar.

**Ficha de detalle** (`/purchases/{id}`): barra de ecuación Total Compra (+) ND (−) NC (−) Pagado (=) A
Pagar. Sección Pagos (tabla Id/Fecha/Medio de pago/Nota/Total/Comprobante) con enlaces **+ Agregar
Pago** y **+ Agregar Retención**.

- **Modal "Nuevo Pago"**: Fecha, Monto (precargado con el saldo pendiente), Elija Medio de Pago
  (desplegable de cuentas de Tesorería — mismo catálogo que Cobranza de Ventas y Otros Ingresos), Nota.
  Al confirmar genera un comprobante correlativo (ej. "X 0001-00000005") y pasa la compra a "Pagado".
- **Modal "Nueva Retención"** (hallazgo relevante): punto donde la Función Avanzada "Retenciones" —
  sin efecto visible en Ventas/Cobranzas — sí se materializa. Campos: Fecha, Monto, Elija Tipo
  (Ganancias, IVA, Seguridad Social, Sellos, Ingresos Brutos por jurisdicción...), N°/comprobante,
  Descripción. Confirma que las retenciones se registran del lado de Compras/Pagos a proveedores.

Sección "DETALLE DE COMPRA": documento con watermark "NO VÁLIDO COMO FACTURA", datos del Proveedor,
Categoría, tabla de Conceptos, panel de totales, Observaciones. Botones Imprimir Detalle/Exportar
Detalle/Editar Compra. Sección "Notas de Crédito y Débito" (tabla + enlace + Agregar, igual patrón que
Ventas §3.2). Botón "Crear Remito" visible en la parte superior de la ficha.

### 4.2 Gastos (`/expenses`)

Módulo deliberadamente más simple que Compras: **sin KPIs**, sin vínculo a proveedores de la Base de
Datos, sin documento imprimible, sin NC/ND, sin pagos parciales — libro de erogaciones de carga rápida
(alquiler, sueldos, marketing, impuestos).

**Listado**: Filtros, un único selector de fecha (**Emisión** — a diferencia de Compras que tiene dos),
selector de columnas, botón **Nuevo Gasto**. Columnas: Estado, Id, Emisión, Categoría, Subcategoría,
Descripción, Medio de Pago (sin columna Proveedor ni Subtotal/Descuento). Selector de columnas: sólo 6
disponibles (Emisión, Categoría, Subcategoría, Descripción, Medio de Pago, Monto) — Monto oculta por
defecto.

**Filtros**: Id, Categoría y/o Subcategoría, Medio de pago, Estado del Pago, Descripción ("Contiene"),
Usuario.

**Menú de fila**: sólo 3 opciones — Ver, Editar, Eliminar (el más liviano de toda la app junto con
Otros Ingresos, §3.3).

**Formulario "Nuevo Gasto"**: a diferencia de Compras/Ventas, **es un modal**, no una página completa
(`/expenses?modal_opened=true`). Campos: Fecha (default hoy), Monto, **Seleccionar Categoría**
(desplegable jerárquico de dos niveles Categoría→Subcategoría, con "Crear Categoría de Gasto" y "Crear
Subcategoría"; taxonomía propia de Gastos, **independiente** del árbol de Categorías de Compras del
Proveedor — dos catálogos distintos aunque comparten la tabla genérica `categorias`, tipo=`gasto` vs.
tipo=`compra`), **Elija un medio de pago** (mismo catálogo de cuentas de Tesorería que Compras),
Descripción, checkbox **"Marcar como pendiente"**. Botones Cancelar/Crear. Al guardar el modal se
cierra y el listado se actualiza in place — clic en el Id reabre el mismo modal en modo edición; no
existe ficha de detalle propia.

### 4.3 Dependencias no resueltas (regla de oro — no se simplifican)

Compras hereda las mismas brechas que Ventas (§3.5), ya aceptadas y sin bloquear la implementación.
**Tesorería (spec 007) ya está implementada y provee los medios de pago reales** (cuentas + `Tesoreria::
registrarMovimiento()`) usados por Pagos y Gastos — no quedó pendiente al cierre de esta spec:

- **Cuenta Corriente**: "Cta Cte" en el menú de fila de Compras (proveedor) — mismo gap que Clientes/
  Ventas. Deshabilitado en la UI (`disabled`, "Próximamente"), no oculto ni simulado.
- **Facturación Electrónica**: Tipo de Comprobante + numeración de Compras sin validez fiscal real
  hasta ese módulo (documento con watermark "NO VÁLIDO COMO FACTURA").
- **Remitos**: "Crear Remito" en Compras crea sólo el encabezado (fecha + número); detalle de ítems
  pendiente de relevamiento propio — apunta al mismo hueco de estructura de pantalla que en Ventas.
- **Recibos de Pagos**: análogo a Recibos de Cobros (§3.5), sin relevamiento propio con capturas.

*Fuente(s): `docs/informe_contagram_egresos.md`*

---

## 5. Módulo Configuración & Ajustes (alcance actual: Usuarios y Permisos)

| Sección | Contenido |
|---|---|
| Usuarios y Permisos | Alta de usuarios, asignación de roles, activar/desactivar usuarios |
| Roles | CRUD completo de roles (crear/renombrar/borrar) y asignación de permisos por rol |

> **Adaptación single-tenant:** este CRM es single-tenant, sin plan contratado ni costo por usuario
> adicional. Los permisos son **sólo por rol** (el usuario hereda los permisos de sus roles; no hay
> overrides por usuario), y el admin tiene **CRUD completo de roles** en vez de un catálogo de roles
> fijo. El rol Admin pasa cualquier permiso (`Gate::before`). Ver `docs/modelo_datos.md §1` para el
> modelo de `roles`/`permisos`/pivots.
>
> Las secciones de Contagram "Mi Perfil" (datos fiscales/logo de la empresa), "Mi Plan" y "Funciones
> Avanzadas" no están implementadas por ahora — dependían de módulos descartados (Facturación
> Electrónica) y se retoman junto con ellos. "Importar Datos" ya está implementado, pero como
> pantalla propia de Base de Datos (§2.4, spec 006) — Contagram real la expone también desde
> Configuración & Ajustes, alcance no replicado en este CRM.

*Fuente(s): [Configuración & Ajustes](https://help.contagram.com/es/collections/83659-configuracion-ajustes)*

---

## 6. Reglas de negocio clave (alcance actual)

- Un producto no se puede eliminar si tiene operaciones cargadas (movimientos de stock); se marca como
  "Inactivo".
- El stock se lleva por producto (o variante) + depósito; los ajustes manuales quedan en el historial
  de movimientos (`movimientos_stock`).
- Un cliente sólo requiere el campo "Cliente" (nombre) para darse de alta; el resto —incluido todo el
  bloque de facturación— es opcional hasta que se retome Facturación Electrónica.

---

## 6.1 Puntos a confirmar (control 24/07/2026 contra relevamiento real de Contagram)

Al contrastar la implementación actual contra `informe_contagram_base_de_datos.md` (relevamiento con
capturas reales de Contagram) surgieron dos divergencias que no se resolvieron aún porque requieren
más evidencia antes de tocar código:

1. **Bloque "Ventas" del modal de Cliente:** el relevamiento real (capturas nuevas) muestra ese bloque
   con sólo Categoría Ventas, Descuento General (%) y Nota para el Cliente — **sin** "Lista de
   Precios". Nuestro modal sí incluye Lista de Precios ahí. Puede que en Contagram esté en otro lugar
   del formulario, o que la documentación original se haya equivocado. Pendiente de confirmar con más
   capturas antes de sacarlo.
2. **Menú de fila de Cliente:** Contagram real tiene Ver / Editar / Eliminar / **Cta Cte** (Eliminar es
   un borrado directo con confirmación simple). Nuestro menú tiene Ver / Editar / **Inactivar-
   Reactivar** / Eliminar — agregamos un toggle de estado que Contagram no expone en ese menú. Es una
   regla de negocio razonable (no eliminar clientes con historial) pero diverge de la UX relevada;
   queda como decisión de producto a confirmar, no como bug.

Brechas ya identificadas y aceptadas como pendientes (no bugs, alcance futuro): "Cta Cte" en el menú
de fila de Clientes (depende de Ventas/Tesorería, no implementados). La brecha de selección múltiple
+ "Acciones Masivas" en Productos se cerró en spec 004 (ver §2.2).

### 6.2 Informe de Stock (implementado, spec 003)

La acción de fila **"Movimientos"** en Productos ya no abre un modal (el modal de Producto queda sólo
para el form de Ajuste de Stock); navega a una **pantalla propia** (`/informes/stock`, fiel a
`informe_contagram_base_de_datos.md` §4.9), con `?producto_id=` pre-cargando el filtro "Productos".

- **Filtros**: Usuario, Operación (limitado hoy a `Ajuste`/`Transferencia` — no existen `Entrada`/
  `Salida` de Compras/Ventas todavía), Proveedor, Tipo de Producto, Productos (buscador), Estado del
  Producto/Servicio, rango de fechas.
- **KPIs** (recalculados según los filtros de producto vigentes): Unidades en Stock, Costo Total, Valor
  Venta Total — misma fórmula que los KPIs en $ de Productos (§2.2).
- **Tabla**: Fecha, Operación, Detalle, Producto, Cantidad, **Stock Saldo** (saldo corrido por
  producto+depósito, calculado sobre el histórico completo de `movimientos_stock` vía función de
  ventana SQL — los filtros de pantalla nunca alteran ese cálculo, sólo qué filas se muestran).
- Es de **sólo lectura**: no edita ni elimina movimientos desde ahí. El alta de movimientos sigue
  siendo "Ajuste de Stock" (Aumentar/Disminuir/Transferencia) desde Productos, sin cambios.

---

### 6.3 Módulo Inicio / Dashboard (implementado — spec 010)

Pantalla de aterrizaje `/dashboard` (reemplaza a Clientes como página raíz `/`). Es **sólo lectura**:
agrega datos ya existentes de Ingresos (§3), Egresos (§4) y Tesorería (§3.7), sin crear/editar/eliminar
nada por su cuenta.

- **KPIs** (4 tarjetas, con variación % vs. el período anterior equivalente): Ventas Creadas, Venta
  Promedio, Cantidad de Ventas, Resultado (= Ventas + Otros Ingresos no pendientes − Compras − Gastos
  no pendientes). Variación `null` ("sin datos previos") cuando el período anterior valió cero.
- **Totales del período**: Ventas/Otros Ingresos/Compras/Gastos en barras de progreso proporcionales.
- **Gráfico mensual**: barras apiladas de los últimos 12 meses (fijo, no depende del selector de
  período), con los 4 rubros anteriores; meses sin operaciones se muestran en cero, no se omiten.
- **Selector de período**: Última Semana / Mes Actual (default) / Mes Anterior / Año Actual — recalcula
  KPIs, Totales, Donas y Rankings. Tesorería y Cuentas a Cobrar/Pagar **no** se recalculan por período
  (siempre "a hoy"), para no repetir cómputo que no cambia con el filtro.
- **Resumen de Tesorería**: Total Disponible/Cajas/Bancos (reutiliza `Tesoreria::saldos()`, spec 007,
  sin lógica propia) + mini-tabla de últimos movimientos.
- **Cuentas a Cobrar / a Pagar con aging**: dos bloques (Ventas a Cobrar en verde, Compras a Pagar en
  rojo) con el monto total pendiente y un desglose por antigüedad de deuda (A Vencer, Vencido, 0-30,
  31-60, 61-90, +90 días). Este es un **cálculo mínimo nuevo** (`App\Services\Tesoreria\CuentaCorriente`,
  método `aging()`), construido sobre `Venta::aCobrar()`/`Compra::aPagar()` ya existentes — **no** son
  las pantallas completas de Cuenta Corriente por Cliente/Proveedor (esas siguen en §7, pendientes de
  relevamiento propio; el servicio queda ahí listo para que una futura spec de Informes lo reutilice).
- **Donas por categoría** (Ventas/Compras/Gastos) y **Rankings** (Clientes por monto vendido, Productos
  por cantidad vendida) dentro del período filtrado. Categoría inactiva o ausente se agrupa bajo
  "Sin categoría".

---

## 7. Módulos pendientes de re-relevamiento

Los siguientes módulos de Contagram fueron investigados en la primera pasada de este documento, pero
esa documentación se descartó junto con el código porque no reflejaba con precisión el negocio real.
Se re-relevarán módulo por módulo antes de retomar su implementación. **Ingresos (Ventas, Presupuestos,
Otros Ingresos, Abonos) ya se re-relevó** (ver §3), **Tesorería (Cuentas, Movimientos) ya se
implementó** (ver §3.7), **Egresos (Compras, Gastos) ya se re-relevó** (ver §4) y **Inicio / Panel de
Control ya se implementó** (ver §6.3) — los cuatro salieron de esta lista:

- Facturación Electrónica (ARCA/AFIP — WSAA + WSFEv1)
- Informes (Ventas, Compras, Cuenta Corriente, Gastos, Contador, Ranking, Reporte Final) — nota: el
  aging de Cuenta Corriente ya tiene un cálculo mínimo reutilizable (`CuentaCorriente::aging()`, §6.3);
  las pantallas completas de Cuenta Corriente por Cliente/Proveedor siguen pendientes acá.
- Integraciones (MercadoLibre, TiendaNube)
- Retenciones (transversal a Cobros/Pagos — ya resuelta a nivel de regla de negocio en Ingresos §3.5 y
  Egresos §4.1 vía el modal "Nueva Retención"; sigue faltando el relevamiento de una pantalla propia de
  administración de retenciones, si existiera)
- Configuración & Ajustes → Mi Perfil, Funciones Avanzadas, Ajustes de formularios

---

## 8. Fuentes principales

- Sitio institucional: https://contagram.com/
- Centro de ayuda: https://help.contagram.com/es/
