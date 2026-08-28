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
- Datos de facturación: Razón social, N° de Doc (selector de tipo CUIT/DNI + campo con **auto-formato**
  de guiones, ej. "30712345678" → "30-71234567-8"), botón **"Verificar"** (ícono de refresh) junto al
  campo, Condición de IVA, Tipo de comprobante por defecto, y domicilio/teléfonos fiscales (separados
  del domicilio comercial). Confirmado con capturas reales en `informe_contagram_base_de_datos.md`
  §2.3/§2.5 (Clientes) y §3 (Proveedores, mismo comportamiento).
  - **Dos verificaciones distintas, no confundir**: el botón "Verificar" sólo corre el **algoritmo de
    dígito verificador módulo 11** de forma local (sin red) — si el CUIT/CUIL es matemáticamente
    inválido, **bloquea la creación/edición del registro** con "El número de cuit no es válido" en
    rojo, incluso si se guarda sin apretar "Verificar" primero (la validación corre igual al guardar).
    Esto **sí está en alcance** y ya está implementado en el backend (`app/Rules/CuitValido.php`,
    usado por `ReglasCliente`/`ReglasProveedor`) — lo que falta es el botón/UI y el auto-formato.
  - La **verificación/autocompletado contra ARCA/padrón fiscal** (consulta en vivo al padrón
    `ws_sr_padron_a13` para confirmar que el CUIT existe, corroborar su condición de IVA real, y
    **autocompletar razón social/domicilio/condición de IVA** en el modal de Cliente a partir del
    CUIT tipeado, dejando esos campos editables) **entró en alcance con la spec 037** (ver
    `specs/037-padron-arca-cuit/`), una vez resuelto el bloqueo técnico: ya se cuenta con
    autenticación **WSAA** y certificado propio del negocio desde Facturación Electrónica (spec 034,
    §8.bis). El botón "Verificar" pasa a hacer ambas cosas (dígito verificador local + consulta real
    al padrón cuando el documento es CUIT/CUIL); si ARCA no está disponible o no encuentra el CUIT,
    se informa por toast sin bloquear el guardado. La misma consulta al padrón se usa, de forma
    interna y sin UI de búsqueda, en la conversión de órdenes de Tiendanube/MercadoLibre a venta
    para determinar el tipo de comprobante (A/B) cuando el cliente es nuevo o no tiene condición de
    IVA ya cargada — ver §5 (Ingresos > Tiendanube/MercadoLibre).
  - **Corrección 05/08/2026 (spec 047)**: se detectó que `ws_sr_padron_a13` **no expone condición de
    IVA en su schema** (confirmado contra el WSDL real de ARCA con dos CUITs reales muy distintos) —
    sólo trae identidad y domicilios; el campo quedaba siempre sin completar pese a que la spec 037 lo
    daba por incluido. Se suma una segunda consulta, independiente y best-effort, al servicio
    **`ws_sr_constancia_inscripcion`** ("Consulta de constancia de inscripción" en el Administrador de
    Relaciones de Clave Fiscal de ARCA, WSDL real `personaServiceA5`) para completar ese dato — ver
    `specs/047-condicion-iva-padron-constancia/`. Ambas consultas usan el mismo certificado y
    autenticación WSAA; el fallo de una no afecta a la otra.
  - **Corrección 05/08/2026 (fix junto con spec 047)**: el autocompletado de domicilio fiscal conflateaba
    provincia y localidad en un único campo (`localidad_fiscal`), y el JS del modal intentaba escribir
    ese valor directo en el `<select>` de Localidad sin antes seleccionar la Provincia — como ese select
    depende de la Provincia elegida (opciones cargadas por AJAX), el autocompletado nunca surtía efecto.
    Se separaron `provinciaFiscal`/`localidadFiscal` en `ResultadoConsultaPadron` (uno viene de
    `descripcionProvincia`, el otro de `localidad` de la respuesta de ARCA) y el modal ahora selecciona
    primero la Provincia y recién con eso carga/selecciona la Localidad. Aplica tanto al modal de
    Cliente como a la creación/actualización automática de Cliente en la conversión de órdenes de
    Tiendanube/MercadoLibre (mismo `ResultadoConsultaPadron` compartido).
  - **Comprobante por defecto derivado de la Condición de IVA (spec 048)**: en el modal de alta/edición
    de Cliente, al elegir (a mano o vía "Verificar") la Condición de IVA, "Tipo de comprobante por
    defecto" se autocompleta con el mismo criterio ya usado en backend para la conversión de órdenes de
    Tiendanube/MercadoLibre: Responsable Inscripto → Factura A, cualquier otra condición → Factura B.
    El usuario puede sobreescribirlo a mano sin que se le pise (mismo mecanismo de "no pisar ediciones
    manuales" ya usado para razón social/domicilio/condición de IVA) — ver
    `specs/048-comprobante-defecto-condicion-iva/`.
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
- **Punto de Reposición** (brecha detectada en spec 026 el 31/07/2026, **cerrada por spec 073** el
  21/08/2026): cantidad mínima deseada de un producto. Es un **atributo del producto**
  (`productos.punto_reposicion`, entero ≥ 0, **NOT NULL default 0** desde el 25/08/2026 — antes era
  nullable, pero `null` y `0` significaban lo mismo y arrastrar dos valores para un solo significado
  obligaba a normalizar en cada lectura y escritura). Reglas:
  - `0` → el producto **no se controla**: no genera alerta ni notificación, por bajo que esté su
    stock. Es el valor por defecto del catálogo (poblarlo es decisión del negocio). Dejar el campo
    vacío en el modal o en una planilla de importación equivale a 0.
  - El **export de Productos** escribe siempre un número: los productos sin control salen en `0`,
    nunca con la celda vacía.
  - Sólo aplica a `tipo = 'producto'` y `activo = true`. Un producto sin fila en `stocks` para el
    depósito evaluado cuenta como stock 0; un stock negativo es el caso más urgente. Con variantes,
    se compara contra el **total del producto** en ese depósito.
  - Un producto está **en punto de reposición** cuando su stock es **≤** su punto de reposición. El
    mismo número alimenta **dos controles distintos**, que se ven en la pantalla de Monitoreo
    (§5.1):
    - **A reponer**: stock en **Local** ≤ punto de reposición → hay que comprarle al proveedor o
      traer de Full. Aplica a todo el catálogo, publicado o no.
    - **Riesgo de publicación**: producto **publicado en Mercado Libre** con stock **Local + Full**
      ≤ punto de reposición → no hay de dónde vender y la publicación se cae.
    > **Ojo**: `ml_configuracion.deposito_id` **es** el depósito Local (id 5) — no existe un
    > "depósito de Mercado Libre" aparte. Los únicos depósitos vigentes son Local (5) y Full (6).
    > Definir el segundo control "contra el depósito de ML" produciría la misma lista que el
    > primero; lo que de verdad los distingue es **Full**: un producto con 1 en Local y 50 en Full
    > hay que reponerlo, pero su publicación no corre riesgo.
  - **Historia**: la importación de datos reales (04/08/2026) lo dejó modelado como una `lista_precio`
    más (id 14, "Punto Reposición") para no tocar schema. La spec 073 migra esos valores a la columna
    y **elimina esa lista de precios**, que conceptualmente nunca fue un precio y ensuciaba todo
    selector de listas de la app. La migración corre con `migracion:punto-reposicion` (dry-run por
    defecto) y **aborta sin borrar** si alguna configuración todavía referencia la lista.
  - Reemplaza el umbral fijo de 3 unidades que el panel de Monitoreo tenía escrito a mano.
- **Variantes** (talle, color): la UI de alta de variantes está **oculta** en el modal — Contagram no
  la expone (su propio tooltip del Nombre sugiere cargar talle/color en el nombre). Se conserva la
  infraestructura (`producto_variantes`) para cuando se retome la integración con canales externos, y
  el stock puede llevarse por variante y depósito. El controller NO borra variantes existentes cuando
  el payload no las incluye. Para reactivar la UI, quitar el `d-none` de `#seccion-variantes` en
  `_modal_form.blade.php`.
- **Código/SKU no es único** (corrección, 02/08/2026): ni `productos.codigo` ni
  `producto_variantes.sku` tienen restricción de unicidad — el negocio reutiliza códigos entre
  productos distintos en su catálogo real, y el importador rechazaba miles de filas legítimas por
  esto. No hace falta que lo sea: ninguna integración vigente matchea por código/SKU — Mercado Libre
  (spec 023) y Tiendanube (spec 024) vinculan comparando el SKU del canal contra el **`id`** del
  producto en el CRM, no contra `codigo`. La regla `SkuUnico` y la constraint `unique` en ambas
  columnas se retiraron (sin spec, corrección directa por regla de negocio incorrecta).

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
  - **Clientes/Proveedores** (spec 026, 31/07/2026): además de los campos ya vigentes, el select
    ofrece el bloque fiscal completo (Razón Social, Tipo de Documento —texto libre, sin catálogo—,
    Domicilio/Localidad/Provincia/CP Fiscal, Teléfono Fiscal, Teléfono Celular Fiscal), Código Postal,
    Saldo Inicial y Fecha de Saldo Inicial (acepta fecha nativa de Excel, `DD/MM/YYYY` o `YYYY-MM-DD`),
    y Página Web. Sólo en Clientes, además: Nota para Ventas, Descuento General, Lista de Precios
    (resuelta por nombre, misma advertencia no bloqueante que Proveedor en Productos) y Usuario de
    Mercado Libre.
  - **Productos & Servicios** (spec 026): además de los campos ya vigentes, el select ofrece Activo,
    Mostrar en Ventas y Mostrar en Compras (booleano: `Si/No`, `1/0`, `true/false`, sin distinguir
    mayúsculas/acentos; celda vacía usa el default de columna vigente en el alta manual —`true`—, un
    valor no reconocido marca la fila como fallida).
- **Paso 3** (confirmar → resumen): cada fila se valida y se crea de forma independiente
  (`ReglasCliente`/`ReglasProveedor`/`ReglasProducto`, las mismas del alta manual); una fila inválida
  se omite y se reporta con su motivo, sin abortar el resto del archivo. En Productos, la columna
  "Proveedor" (y Categoría/Condición de IVA/Tipo de Producto) se resuelve por nombre existente sin
  distinguir mayúsculas/acentos — si no matchea, el producto se crea igual sin ese dato, reportado
  como advertencia (no como fallo); "Tipo" sin mapear o vacío usa "Producto" por defecto.
  - **Actualizar por Id (upsert)** (spec 027, 31/07/2026 — ajustado 31/07/2026): las 3 solapas ofrecen,
    además de los campos propios de cada entidad, un campo destino "Id" (el id interno que el sistema
    le asignó al registro, o el id que traía el sistema de origen de una migración). Por fila: celda
    "Id" ausente o vacía → alta nueva sin id forzado (comportamiento sin cambios); valor no numérico o
    no entero → fila fallida ("Id "{valor}" no es un id válido"); valor numérico **con match** →
    **actualización parcial** del registro existente (`update()`, no `create()`): sólo se pisan los
    campos efectivamente mapeados con valor no vacío en esa fila, el resto del registro queda intacto;
    valor numérico **sin match** → **alta nueva, preservando ese id** en el registro creado (no
    "fila fallida" ni "actualiza con ese id" porque no hay nada que actualizar) — pensado para migrar
    datos de un sistema anterior conservando sus ids: si el mismo archivo se reimporta más adelante, esa
    fila ya matchea como actualización en vez de generar un duplicado. Si el id preservado choca con uno
    ya tomado por otro registro (carrera entre filas de la misma corrida), esa fila puntual queda como
    fallida ("Id {valor} ya está en uso por otro registro") sin abortar el resto del archivo. En una fila
    de actualización, el campo obligatorio (Cliente (Nombre)/Proveedor/Nombre) no se exige por valor
    (puede venir vacío), pero la columna igual tiene que estar mapeada en el archivo (regla de mapeo del
    Paso 2, sin cambios); en una fila de alta (con o sin id preservado) sigue exigiéndose igual que
    siempre. La unicidad de CUIT/SKU en una fila de actualización no bloquea al propio registro que se
    está actualizando (ignora su propio id).
  - **Robustez del upsert de Productos: stock y precios** (spec 074, 22/08/2026). El circuito real del
    negocio es *exportar la planilla de Productos → editarla en Excel (típicamente una fórmula de
    aumento sobre una lista de precios, o corrección de stock) → reimportarla mapeando "Id"*. Ese
    circuito tenía dos puntos ciegos, ambos corregidos en esta spec:
    - **Stock (concurrencia).** La planilla trae el **valor final deseado**, no un delta, así que la
      actualización tiene que calcular la diferencia contra el stock actual. Se hacía leyendo el stock
      fuera de la transacción que después lo escribía: si entre la lectura y la escritura alguien vendía
      ese producto, la venta se pisaba (*lost update*), y la ventana era real (tandas de 1.000 filas,
      minutos de proceso con el local operando). Ahora usa `StockService::fijar()`, que resuelve lectura,
      cálculo y escritura bajo un mismo lock — ver `docs/modelo_datos.md` §`stocks`. Sin cambios visibles:
      el movimiento generado sigue siendo de tipo `ajuste` con descripción `Ajuste (importación)` en
      actualizaciones y `Registro inicial (importación)` en altas, y si la cantidad de la planilla ya
      coincide con la actual no se genera movimiento.
    - **Precios (trazabilidad).** Los precios por lista se pisaban sin registrar el valor anterior, así
      que un error de fórmula detectado días después era irreversible. Ahora **todo cambio de precio
      queda auditado** (operación "Precio de producto" de la pantalla de Auditoría, §Configuración &
      Ajustes), con precio anterior, precio nuevo y origen. Reimportar una planilla **sin cambios no
      genera ningún evento ni ningún movimiento de stock**.
    - **El ciclo exportar → reimportar cierra sin intervención manual** (agregado 22/08/2026, tras
      probarlo contra la base real con 9.187 productos). Dos huecos que lo rompían y quedaron
      corregidos en esta misma spec:
      - **Las columnas de stock se automapean.** La exportación de Productos escribe los encabezados
        como `Stock {depósito}` ("Stock Local", "Stock Full"), pero el asistente sólo reconocía el
        nombre pelado del depósito ("Local"), así que las dejaba en "No importar" y **el stock nunca
        se actualizaba** salvo que el usuario mapeara esas columnas a mano. Cada depósito acepta ahora
        los dos encabezados. Las listas de precio ya automapeaban bien por el nombre de la lista.
      - **El stock negativo se puede reimportar.** La validación exigía `>= 0` en el stock por
        depósito, pero la exportación escribe los negativos —que son el estado real de un producto
        sobrevendido— así que **cada producto sobrevendido tiraba la fila entera** (68 de 9.187 en la
        base real). Era además incoherente con la regla de dominio: un ajuste de stock puede dejar el
        saldo negativo. Ahora el stock por depósito admite negativos, en el alta y en la actualización.
        **Los precios siguen exigiendo `>= 0`**: un precio negativo no tiene sentido de negocio y sigue
        marcando la fila como fallida.
    - **El Punto de Reposición viaja en el export y se puede reimportar** (25/08/2026). La
      exportación de Productos agrega una última columna `Punto de Reposición` (entero, vacía si el
      producto no se controla), y el asistente de importación tiene el campo destino homónimo, que
      automapea tanto por ese encabezado como por el alias `Punto Reposicion` — que es como se
      llamaba la **lista de precios** de la que se migró este dato (`migracion:punto-reposicion`) y
      como sigue viniendo en los exports de Contagram anteriores a esa migración. Va último a
      propósito: no corre las columnas de stock/dinero, que la exportación formatea por índice.

  - **DNI y CUIT en columnas separadas mapeadas al mismo campo "CUIT"** (corrección, 31/07/2026): en Clientes y
    Proveedores, el campo destino "CUIT" acepta mapear hasta 2 columnas del archivo (el resto de los campos
    sigue permitiendo sólo una). Por fila, se toma el valor de la que tenga dato; el tipo de documento
    (DNI/CUIT) se infiere por el **encabezado original de esa columna en el archivo** (si matchea "DNI" → tipo
    DNI; cualquier otro caso → CUIT) — no por el formato del número. Si ambas columnas tienen valor en la misma
    fila (dato excepcional), gana la que matchea "DNI". La validación de dígito verificador sólo se exige
    cuando el tipo resuelto es CUIT/CUIL, igual que en el alta manual.
- Cancelable en cualquier paso antes de confirmar, sin dejar ningún registro creado. El archivo
  subido y el mapeo elegido son estado transitorio (disco temporal `storage/app/private/imports/` +
  sesión) — nunca se persisten en base de datos, y no hay detección de duplicados en esta versión
  (importar el mismo archivo dos veces crea registros nuevos).
- **Deshacer import (spec 078, sólo Productos & Servicios)**: cada corrida del Paso 3 en esa solapa
  registra una "corrida de import" y, por cada fila creada/actualizada, un snapshot de su estado
  previo. Una acción "Deshacer import" (disponible desde el resumen post-import y desde una nueva
  pantalla "Historial de Importaciones") permite revertir la corrida completa dentro de una ventana
  de **48 horas** desde la confirmación. Altas revertidas → soft-delete (`activo=false`); filas
  actualizadas → se restauran los campos pisados (precio, costo, stock por depósito vía
  `StockService::fijar()`, precios por lista), auditado igual que un cambio manual de precio (spec
  074). El undo es **parcial**: una fila cuyo producto tuvo operaciones posteriores al import
  (venta, compra, ajuste, u otra corrida de import más reciente) no se revierte y queda reportada
  con motivo, sin abortar el resto. No aplica a Clientes ni Proveedores (pendiente para spec futura
  si se decide extenderlo).
- **Archivos grandes: tandas, reintento y retoma (spec 082, 25/08/2026)**. El Paso 3 procesa el
  archivo en **tandas de 250 filas** (antes 1.000), cada una en su propia request, y el archivo se
  interpreta **una sola vez** al subirlo (Paso 1) en vez de una vez por tanda. Motivo: con el
  catálogo real (9.632 productos) el esquema anterior daba ~129 s y ~570 MB por tanda, contra los
  60 s del servidor web y 512 MB de memoria — **una importación de 1.117 filas se cortó en 1.000 y
  dejó 117 sin aplicar** (incidente real del 25/08/2026; no hubo corrupción ni pérdida, pero hizo
  falta completarla por línea de comandos). Al subir el archivo se lo vuelca a un archivo intermedio
  de una fila por línea, que vive junto al temporal y se borra con él (sigue siendo estado
  transitorio: nunca toca la base). Si una tanda falla por corte de red o
  error del servidor, la pantalla la **reintenta sola hasta 3 veces** (esperando 2 s, 4 s y 8 s); un
  error de mapeo (422) **no** se reintenta, porque repetirlo daría el mismo error. Si aun así falla,
  muestra el error y ofrece **"Reanudar desde la fila N"**
  conservando todo lo ya aplicado. Reintentar es seguro: una tanda saltea las filas que ya tienen
  snapshot en esa corrida, así que no se duplican ni se recuentan. Una importación cortada y retomada
  queda como **una sola corrida** a efectos del deshacer. Aplica a las tres solapas (el motor es
  compartido). Si al retomar los encabezados del archivo ya no son los que se mapearon (por ejemplo,
  se subió otro archivo en otra pestaña), la tanda se rechaza y se pide rehacer el mapeo en vez de
  escribir en columnas equivocadas; si el temporal ya no está, se pide volver a subir el archivo. El límite de 10 MB por archivo no cambió: sobra para 10.000 filas (~1,8 MB).

- **Modal de confirmación previa y correcciones (spec 083, 26/08/2026)**. Los 3 pasos del asistente no
  cambian, pero el botón "Confirmar importación" del Paso 2 ya no importa directamente: abre un
  **modal de confirmación** que analiza el archivo entero contra el mapeo elegido **sin crear ni
  modificar ningún registro**, y muestra **cuántas altas**, **cuántas actualizaciones**, **qué campos
  se van a modificar y a cuántos registros cada uno** (ej. "Costo: 100 registros · Precio venta: 100 ·
  Stock Local: 43") y **qué filas fallan con su motivo**. Cancelar el modal vuelve al mapeo con la
  selección intacta y sin haber tocado nada. ⚠️ **Cambio de
  comportamiento**: si hay **al menos una fila con error**, no se puede confirmar hasta corregir el
  archivo y volver a subirlo — esto **revierte la tolerancia por fila** de los specs 006/026 (donde
  una fila inválida se omitía y el resto se importaba igual). Es una decisión explícita del usuario
  tras un incidente real: entraron 124 productos con el código y el precio mal. Consecuencia a tener
  presente: **una sola fila mala en un archivo de 9.000 impide importar las 8.999 restantes**.
  El modal de confirmación **no existe en Contagram real**: es una divergencia deliberada y documentada,
  no un desvío a corregir.
  El bloqueo vive en el **backend**, no sólo en la pantalla: sin un análisis previo vigente, completo y
  sin errores para exactamente ese archivo y ese mapeo, la importación se rechaza aunque se llame al
  endpoint directamente. El análisis se acumula **en disco**, junto al volcado NDJSON del spec 082 (no
  en sesión: 10.000 filas con error son del orden de 1 MB de texto), y se borra junto con el archivo
  temporal al confirmar, al cancelar o al subir uno nuevo. La garantía de que lo que informa es lo que
  va a pasar es estructural: la prevalidación y la importación comparten el mismo servicio de decisión
  (`ValidadorFilasImportacion`), que **no tiene forma de escribir** — no recibe el `StockService` ni
  llama a `create()`/`update()`/`save()`.
  Junto con eso se corrigieron cuatro defectos detectados el mismo día:
  - **Fórmulas de Excel sin calcular**: un `.xlsx` guardado sin recalcular manda el texto de la fórmula
    (`=+B2&" "&A2`). Antes, en un campo de texto **se guardaba la fórmula como si fuera el dato** (124
    productos quedaron con ese código). Ahora el sistema **calcula las fórmulas** al interpretar el
    archivo; una que no se pueda evaluar queda reportada como error de esa fila, nunca guardada.
  - **"Precio venta" no se automapeaba**: la exportación escribe `Precio venta` y el campo destino se
    llamaba `Precio de Venta` sin alias, así que el round-trip exportar → editar → reimportar dejaba
    los productos con **precio de venta 0**. Mismo defecto que el spec 074 arregló para `Stock
    {depósito}`; ahora hay una verificación automática que falla si alguien agrega una columna a la
    exportación sin su correspondencia en la importación.
  - **Mensajes de error en inglés y con nombres internos** (*"The precio lista 2 field must be a
    number"*): ahora salen en español y nombran la columna con la etiqueta que el usuario ve en el
    mapeo (*"AHORA 3 tiene que ser un número"*).
  - **El resumen podía informar el resultado de una corrida anterior**: si una importación se abandonaba
    a mitad, su acumulado sobrevivía en la sesión y la siguiente sumaba sobre él (con 1.000 residuales,
    importar 2 registros informaba 1.002; en producción llegó a informar "1000 registros importados
    correctamente" **sin haber importado nada**). Ahora el acumulado se ata a la importación en curso y
    el resumen muestra el **archivo y la fecha** de esa corrida.

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
- Filtros (15 campos, implementados 06/08/2026 — cambio menor sin spec propia, calcado de
  `docs/informe_contagram_ingresos.md` §2.2 `[67]` y del mismo patrón ya usado en Ventas): Id,
  Producto/Servicio, Cliente, Estado del Presupuesto (incluye "Vencido", derivado), Categoría de
  Venta, N° de Presupuesto, Etiqueta, Vendedor, Formas de Pago, Métodos de Envío, Usuario, Nota
  para el Cliente, Nota Interna, Servicio Desde/Hasta. "Usuario" requirió agregar `creado_por_id`
  a `presupuestos` (migración `2026_08_16_060004`, mismo criterio que `ventas.creado_por_id`).
  Formas de Pago y Métodos de Envío filtran por texto libre (like) — no son catálogo, igual que
  en Ventas.
- Menú de fila (badge de Estado ▾, 10 opciones en 4 bloques): Ver/Editar/Eliminar · cambio de estado
  directo (Pendiente/Rechazado/Aceptado) · **Crear Venta** (convierte el presupuesto) ·
  Ver/Imprimir/Enviar Presupuesto.
- "Ver" **no abre modal**: navega a una página completa con formato documento imprimible (a diferencia
  de Clientes/Proveedores/Productos). Igual criterio para "Nuevo Presupuesto" (`/budgets/new`):
  formulario de página completa a dos columnas, no modal — excepción documentada al patrón de modales
  de esta app, igual que Importar Datos (§2.4).
- Formulario: Cliente (buscador) · Categoría · Emisión/Validez · Servicio
  Desde/Hasta · Lista de Precios · Vendedor (campo opcional; **no** es el usuario logueado: hasta
  la spec 020 el campo se autocompletaba en silencio con el usuario del sistema y no aparecía en el
  formulario, distinción confirmada porque Vendedor y Usuario figuran como dos filtros separados más
  abajo) · tabla de Conceptos (producto, cant., precio, desc., subtotal, IVA,
  total; menú Ver/Editar + tacho por fila) · Nota Cliente/Interna · Formas de Pago y Métodos de Envío
  (texto libre) · Etiquetas (catálogo con buscador + "Nueva Etiqueta") · Descuento General (%) · Total
  · **+ Percepciones / + Impuestos Internos / + Intereses** (cada uno agrega N filas de
  selector+monto+tacho). Botones Cancelar/Guardar/Guardar y Enviar.
- **Descuento General — toggle %/monto fijo (spec 060, pendiente de implementar)**: el campo
  "Descuento General" de Presupuestos, Ventas, Compras y Notas de Crédito/Débito (alta y edición de
  los 4) tiene un botón inline junto al valor que alterna entre cargarlo como porcentaje (%, modo por
  defecto) o como monto fijo en pesos ($) — confirmado contra captura real de Contagram ("Editar Nota
  de Crédito"). Al alternar el modo se limpia el valor cargado (no se convierte automáticamente entre
  unidades). El modo y valor elegidos se persisten y se muestran igual al reabrir para editar. En modo
  monto fijo, el descuento se prorratea entre ítems/alícuotas de IVA con el mismo criterio ya vigente
  para porcentaje (ver más abajo "Descuento general aplicado proporcionalmente a neto e IVA"); se
  rechaza el guardado si el monto supera el subtotal de ítems. Ver
  `specs/060-toggle-descuento-general/`.
- **Fila de Percepciones — desplegable estático, no campo de texto libre (30/07/2026)**: el selector de
  la fila "+ Percepciones" es un `<select>` con el catálogo fijo de 27 percepciones vigentes en
  Argentina (IVA, Ganancias, Sellos e IIBB de las 24 jurisdicciones), no un input de texto libre. Es una
  lista estática que no se gestiona desde ningún catálogo/CRUD — no se van a crear percepciones nuevas
  en el uso normal del sistema, por eso no amerita una entidad de base de datos propia ni un panel de
  administración; el listado vive hardcodeado en el JS de cada formulario (`PERCEPCIONES` en
  `resources/js/ventas.js` y `resources/js/presupuestos.js`). Listado completo: IVA (Percepción),
  Ganancias, Sellos, IIBB Buenos Aires, IIBB CABA, IIBB Catamarca, IIBB Chaco, IIBB Chubut, IIBB
  Córdoba, IIBB Corrientes, IIBB Entre Ríos, IIBB Formosa, IIBB Jujuy, IIBB La Pampa, IIBB La Rioja,
  IIBB Mendoza, IIBB Misiones, IIBB Neuquén, IIBB Río Negro, IIBB Salta, IIBB San Juan, IIBB San Luis,
  IIBB Santa Cruz, IIBB Santa Fe, IIBB Santiago del Estero, IIBB Tierra del Fuego, IIBB Tucumán. Las
  filas de Impuestos Internos e Intereses no se modificaron: siguen siendo texto libre. Aplicado en
  Ventas, Presupuestos y Compras (mismo `PERCEPCIONES` hardcodeado replicado en `resources/js/compras.js`,
  06/08/2026).
- **Catálogo editable inline en los selects de Cliente/Categoría de Venta/Vendedor (spec 028)**: el
  patrón real de Contagram no es un link "Renombrar"/"Eliminar" al lado del label, sino que vive
  *dentro* del propio dropdown Select2: una fila fija "Crear X" con ícono "+" siempre arriba del
  listado (aun con texto de búsqueda sin resultados), y un ícono de lápiz a la derecha de cada ítem
  existente que abre su edición puntual sin necesidad de seleccionarlo primero ni de alterar la
  selección vigente del formulario. Reutiliza los modales/endpoints ya existentes de Categoría
  (`categorias.venta.store`/`categorias.update`) y Vendedor (`vendedores.store`/`vendedores.update`);
  para Cliente se agregó un modal de alta/edición rápida (sólo Nombre) sobre los endpoints ya
  existentes de `ClienteController`. La eliminación de Categoría de Venta/Vendedor se retiró de este
  formulario sin reemplazo (no está en las capturas reales). Este patrón sólo está aplicado en
  Presupuestos por ahora — Ventas, Otros Ingresos y Compras siguen con el mecanismo anterior
  (link junto al label) hasta que se extienda en una spec futura.
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
- Filtros — **implementados (06/08/2026, fix directo sin spec)** contra captura real: Id, Cliente,
  Estado del Cobro (Sin Cobrar/Parcial/Cobrada/**Vencido**, agregado 11/08/2026 — mismo criterio que
  la card KPI "Vencido"), Categoría de Venta, Estado de Factura, Tipo y N° de Factura, Etiqueta, Vendedor,
  Remitos, Tipo y N° de Remito, Depósito, Medio de Cobro, Usuario, Nota Cliente, Nota Interna, Creada
  Desde, Servicio Desde/Hasta, y dos selectores de rango de fecha en el header (Emisión/Vencimiento,
  con presets Hoy/Ayer/Última Semana/Mes actual/Mes anterior/Últimos 30 días/Año actual/Desde-Hasta).
  "Usuario" requirió columna nueva `ventas.creado_por_id` (ver modelo_datos.md §ventas) — distinta de
  `vendedor_id`, confirmando la nota ya registrada ahí. **Transportista queda pendiente**: no existe
  tabla/columna propia en el CRM para transportistas; el campo se muestra deshabilitado en el panel
  hasta que se releve y modele esa entidad en un spec propio.
- **Depósito (spec 049, 06/08/2026)**: el formulario "Nueva Venta"/"Editar Venta" suma un campo
  Depósito obligatorio (Select2, catálogo de depósitos activos), que determina de qué depósito se
  descuenta el stock de la operación — antes siempre se usaba, sin poder elegirlo, el mismo
  `Deposito::porDefecto()`. Precarga desde Configuración & Ajustes → Ventas (sección "Ventas",
  `configuracion_ventas.deposito_id`) con fallback al mismo `Deposito::porDefecto()`. Cierra la
  inconsistencia por la que el filtro por Depósito del listado (arriba) no reflejaba ninguna elección
  real del usuario. Divergencia deliberada, sin capturas de Contagram real que confirmen este campo.
- **Servicio Desde/Hasta se autocompletan con la Emisión (17/08/2026)**: en el alta los dos campos
  arrancan en la fecha de emisión y la siguen si el vendedor la corrige. En la práctica todas las
  ventas del negocio son del día, así que llenarlos a mano era tipeo repetido en cada carga.
  **Deja de seguirla en cuanto el vendedor escribe uno de los dos** — a partir de ahí manda lo que
  puso él, incluso si lo deja vacío. **En edición no actúa nunca**: el comprobante ya tiene sus
  fechas, y una fecha vacía también es un dato; pisarla sería cambiar algo que nadie pidió cambiar.
  Tampoco actúa al convertir un Presupuesto en Venta, que hereda el período del presupuesto.
  Implementado en `AppFecha.seguir()` (`resources/js/fecha-ar.js`), compartido con Compras para que
  las dos pantallas no diverjan. Divergencia deliberada, sin capturas de Contagram que la confirmen:
  es una comodidad de carga, no una regla de negocio — el campo sigue siendo opcional y editable.
- **Buscador de productos del detalle con foco persistente (spec 071, 19/08/2026)**: el campo
  "Seleccionar o Crear Producto/Servicio" del detalle dejó de ser un Select2 y pasó a un widget
  propio (`resources/js/buscador-catalogo.js`) porque Select2 no permite mantener el foco en el
  input mientras el panel de sugerencias abre y cierra — el requisito del cliente es cargar varios
  productos seguidos sin tocar el mouse. Mismo comportamiento de búsqueda que antes (mismos
  parámetros al backend, mismo formato de fila, misma línea agregada al detalle — sin regresión),
  sólo cambia la interacción: al elegir un producto (clic o `Enter` con una opción resaltada) el
  panel se cierra, el campo queda vacío y el foco **permanece en el buscador**, listo para tipear el
  siguiente término sin ninguna acción intermedia. `Escape` cierra el panel conservando lo tipeado;
  `↓`/`↑` navegan las opciones sin auto-resaltar la primera (evita cargar "lo primero que apareció"
  con un Enter reflejo, dado que la línea alimenta un comprobante fiscal). Aplica igual en Venta,
  Compra y Presupuesto; el resto de los selects de esas pantallas (Cliente/Proveedor, Categoría,
  Vendedor, Lista de Precios) siguen siendo Select2 sin cambios.
- Botón **"Analizar" (IA/Gemini)**: exclusivo de Ventas — genera un resumen del período (producto
  estrella, categoría más rentable, récord de venta, recomendación de negocio), con advertencia
  explícita de que "puede no ser del todo precisa o real". Misma tecnología (Gemini) que "Buscar
  precios con IA" en Productos (§2.2, no implementado en este CRM).
- Menú de fila (12 opciones, el más completo de la app): Ver/Editar/Eliminar · Agregar Cobranza / Crear
  NC/ND / Crear Remito / **Cta Cte** · Ver Detalle (**PDF**, a diferencia de "Ver" en Presupuestos) /
  Imprimir Detalle / Imprimir Ticket / Enviar Detalle / Enviar WhatsApp.
- **Crear NC/ND** *(corroborado y ampliado contra `help.contagram.com`, ver §3.6; captura real
  aportada por el usuario en spec 045)*: wizard de 2 pasos.
  Paso 1: Tipo (Crédito/Débito), Documento que Ajusta, "¿Queres que afecte Stock?" — si es Sí: traer
  los productos de la venta/compra original con sus valores preexistentes (nuestro CRM restringe a
  sólo esos productos, no permite elegir productos nuevos ajenos al comprobante — spec 045); si es
  No: sólo descripción, sin productos. **Mes de Imputación** (mes/año, independiente de la Fecha de
  Emisión — mismo propósito que el campo "Contador" del formulario de Nueva Compra, ver
  informe de Egresos §2.4; en Contagram real este campo vive en el propio modal de NC/ND, tanto en
  Compras como en Ventas — spec 045). Paso 2 ("Siguiente"): Fecha de Emisión, Monto, Tipo de
  comprobante (igual al de la factura original), Descripción, Impuestos aplicables. Al guardar se
  puede solicitar aprobación ante ARCA.
  **Editar/Eliminar/Ver Detalle (capturas propias del usuario, 11/08/2026 — ver
  `informe_contagram_egresos.md` §2.5.1, mismo patrón confirmado también en Ventas)**: con notas ya
  cargadas, la fila de la tabla de NC/ND tiene menú de acciones (trigger en la columna "Estado")
  con **Editar / Eliminar / Ver Detalle** — ninguna de las tres implementada todavía en este CRM
  (hoy sólo existe Crear). "Ver Detalle" es un PDF propio de la nota (marca "X", sin CAE), con su
  propia tabla de conceptos (Código, Descripción, Cant., Precio Unit., %Bonif., Subtotal, Alícuota
  IVA, Subtotal c/IVA) — la nota **no es un monto global**, es un documento con ítems e IVA propios.
  "Editar" reabre el wizard: paso 1 igual al de creación, con el agregado de que el select
  "Documento que Ajusta" también lista **las demás NC/ND ya creadas sobre el mismo comprobante**
  (permite encadenar una NC/ND como corrección de otra, no sólo de la Compra/Venta original); paso 2
  ya no es "Fecha/Monto/Descripción" sino una página de edición completa equivalente a un
  formulario de Compra/Venta: Proveedor/Cliente heredado (bloqueado), Emisión/Vto./Servicio
  Desde-Hasta, **Tipo y N° de comprobante propios de la nota (editables)**, línea(s) de ítem con
  Cant./Precio/Desc.%/Subtotal/IVA, Nota interna, bloques +Percepciones/+Impuestos
  Internos/+Intereses, panel de totales con IVA discriminado, y botón **Eliminar** disponible
  también ahí (además del menú de fila). Brecha estructural respecto a `NotaCreditoDebito`/
  `NotaCreditoDebitoItem` (spec 039/045), que hoy sólo persiste `monto` + `descripcion` sin ítems
  con IVA propio ni comprobante propio — cerrada por spec 057 (backend: comprobante propio, ítems
  con IVA, encadenamiento).
  > **Corrección spec 059 (11/08/2026, capturas reales del cliente)**: spec 057 implementó
  > Editar/Eliminar sobre el wizard de 2 pasos existente (todo dentro del mismo modal), sin notar que
  > el "paso 2" descripto en este mismo párrafo (página de edición completa) **también aplica a
  > Crear**, no sólo a Editar — Contagram real usa el mismo patrón para ambos: el modal es sólo el
  > paso 1 (Tipo/Documento que Ajusta/Stock/Mes), y "Siguiente" navega a una página propia (no un
  > 2do paso de modal) tanto al crear como al editar. Spec 059 corrige la UI para que coincida con lo
  > ya documentado acá, sin tocar el backend de spec 057. Además: en el modal de Editar, "¿Afecta
  > Stock?" queda deshabilitado junto con el Tipo (spec 057 sólo bloqueaba el Tipo).
  > **Corrección spec 061 (11/08/2026)**: los bloques +Percepciones/+Impuestos Internos/+Intereses
  > que spec 059 dejó sin funcionalidad (FR-003 de esa spec) pasan a ser funcionales — mismo
  > comportamiento que ya tenían en Ventas/Compras/Presupuestos (selector de percepción del catálogo
  > fijo de 27, o texto libre para impuesto interno/interés, + monto, sumados al Total), persistidos
  > en la columna `notas_credito_debito.impuestos` (json) que ya existía sin usarse.
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
- **Editar Cobranza** *(extensión propia de este CRM, spec 053 — no relevada en Contagram real, ver
  `docs/informe_contagram_ingresos.md`)*: en la tabla de Cobranzas del Detalle de Venta se puede editar
  monto/fecha/cuenta/nota de una cobranza ya cargada (reutilizando el modal de "Agregar Cobranza" en modo
  edición), sin anular y recrear el `MovimientoTesoreria` asociado — se actualiza in-place. No cambia el
  flujo de alta/anulación existente ni está presente en Contagram real; se agrega porque corregir un dato
  mal cargado sin dejar rastro de un movimiento "fantasma" es una necesidad operativa del negocio.

### 3.2.bis Mercado Libre (`/ingresos/mercadolibre`) — spec 012

Entrada **condicional** del menú Ingresos: aparece sólo cuando la función avanzada "Mercado Libre"
está activa (mismo patrón que Abonos, §3.4).

> **Estructura verificada contra Contagram (27/07/2026).** El relevamiento con capturas
> (`informe_contagram_funciones_avanzadas.md` §3) nunca completó el flujo de Mercado Libre, por lo que
> inicialmente se asumió que no había pantalla que calcar. **Era incorrecto**: el centro de ayuda
> oficial documenta la pantalla. Flujo real de Contagram: las órdenes bajan en estado **Pendiente** a
> **Ingresos → MercadoLibre**; el usuario selecciona un cliente (existente o nuevo), elige o crea el
> producto, y se abre el formulario **Nueva Venta** para guardar, facturar y cobrar. La venta
> resultante se filtra en el listado de Ventas por **"Creada Desde → MercadoLibre"**. La tarjeta de
> Funciones Avanzadas ofrece un botón **"Ver mis Órdenes"**.
> *Fuentes: [cómo funciona](https://help.contagram.com/es/articles/10922610-como-funciona-la-integracion-con-mercadolibre) ·
> [dónde veo las ventas](https://help.contagram.com/es/articles/10922778-donde-veo-las-ventas-que-provienen-de-mercadolibre) ·
> [cómo integro la cuenta](https://help.contagram.com/es/articles/10922769-como-integro-mi-cuenta-de-mercadolibre-a-contagram).*
>
> La spec 012 **coincide estructuralmente** con ese flujo. Agrega, a pedido explícito del usuario:
> vinculación **persistente** publicación↔producto (Contagram elige el producto en cada conversión),
> **creación automática** de ventas (Contagram documenta sólo el flujo manual — única divergencia
> funcional real), y configuración de depósito y frecuencia de sincronización.

Ver §5.2 para la divergencia deliberada de todo el módulo (aplicación propia con permisos de escritura).

- **Listado de órdenes sincronizadas** desde Mercado Libre: identificador de orden, fecha, comprador,
  publicaciones y cantidades, monto, estado en Mercado Libre y **estado de conversión** en el CRM.
  Filtros por estado, estado de conversión y rango de fechas. Botón "Sincronizar ahora" + tarea
  programada con **frecuencia configurable**.
- **Cinco estados de conversión** (excluyentes): Pendiente de pago · Lista para convertir · Requiere
  atención (con motivo) · Convertida (con acceso a la Venta) · Cancelada. Sólo "Lista para convertir"
  habilita la creación de la Venta.
- **Vinculación publicación ↔ producto**: pantalla propia + carga inline desde la conversión. Relación
  **1:N** (un Producto puede tener varias publicaciones de ML vinculadas simultáneamente; spec 036,
  03/08/2026 — hasta esa spec fue estrictamente 1:1). La unicidad por publicación (`ml_item_id`) se
  mantiene garantizada por índice único — una publicación sigue perteneciendo a un único Producto. Es
  infraestructura compartida con la spec 013. Las publicaciones **con variantes no están soportadas**
  (el negocio no las usa) y se rechazan en vez de vincularse de forma ambigua.
  > 📋 **Vinculación automática por SKU (spec 021, implementada; corregida por spec 023)**: el SKU del
  > vendedor corresponde al `id` (clave primaria) del producto en el CRM — el negocio crea cada producto
  > nuevo asignándole a propósito ese mismo identificador, sin necesidad de ningún campo adicional. La
  > pantalla no tiene alta manual por selector: un botón "Vincular automáticamente" resuelve
  > `Producto::find((int) $sku)` por cada publicación pendiente y crea el vínculo solo. Editar el producto
  > de un vínculo existente y eliminarlo siguen disponibles. **El SKU se resuelve consultando el catálogo
  > en vivo del vendedor conectado** (recorrido completo vía modo `scan` de la API, sin el tope de 1000
  > resultados del paginado clásico — necesario porque el catálogo real tiene miles de publicaciones), no
  > contra las órdenes ya sincronizadas: cubre publicaciones que nunca vendieron y siempre usa el SKU
  > vigente en Mercado Libre en el momento de la corrida, no un valor viejo grabado en una orden pasada
  > (spec 021 original quedó reemplazada en este punto). Publicaciones con variantes siguen sin poder
  > vincularse por esta vía. Ver `specs/021-vinculacion-automatica-sku/` y
  > `specs/023-mercadolibre-catalogo-vivo/`.
- **Conversión a Venta**, manual o **automática** (interruptor en la configuración de Mercado Libre).
  La Venta se crea cobrada contra la cuenta de Tesorería **Mercado Pago** (§3.7) y descuenta stock del
  **depósito configurado**. Cliente emparejado por **Apodo ML** (§2.1) o creado automáticamente.
  Queda asignada además al **Vendedor por defecto** configurado (opcional, spec 020 —
  mismo mecanismo que el Depósito/Categoría de Venta por defecto).
  > 🔒 **Protección anti-duplicados (spec 038, implementada)**: la Venta guarda el identificador del
  > pedido de origen (`ml_order_id`/`tn_order_id` en Mercado Libre/Tiendanube respectivamente), único
  > por canal e incluyendo Ventas eliminadas lógicamente. Esto evita que, si la orden sincronizada se
  > borra y el pedido vuelve a sincronizarse (recreándose como orden "nueva"), se genere una segunda
  > Venta duplicada (doble cobro, doble descuento de stock) para el mismo pedido real. Como
  > complemento, una orden de Mercado Libre o Tiendanube con Venta asociada **no puede eliminarse**
  > (hay que desvincular/eliminar la Venta primero) — mismo patrón de bloqueo que ya usan Cuentas de
  > Tesorería/Clientes/Productos con operaciones asociadas. Ver `specs/038-evitar-ventas-duplicadas/`.
- **Lista de Precios de gestión de precios de Mercado Libre** (spec 016 — implementada):
  la configuración de Mercado Libre permite elegir opcionalmente una Lista de Precios
  (`ml_configuracion.lista_precio_id`) que **gestiona los precios de las publicaciones vinculadas**: al
  cambiar el precio de un producto vinculado dentro de esa lista (modal de Producto o importación
  masiva), el CRM sincroniza ese precio hacia la publicación de Mercado Libre correspondiente en el
  momento del cambio, sin cron ni corrida programada. **No es una fuente de precio de Ventas**: el precio
  de las líneas de las Ventas creadas al convertir una orden sigue derivándose exclusivamente del importe
  pagado en Mercado Libre, sin relación con esta lista; esas Ventas tampoco quedan etiquetadas con Lista
  de Precios (a diferencia del resto de las Ventas del CRM). Sin configurar, no hay ninguna
  sincronización de precio; no existe un fallback "por defecto del CRM" para este campo (a diferencia del
  depósito).
  > 💎 **Lista de Precios diferenciada para publicaciones Premium (spec 050, implementada)**: además de
  > la Lista de Precios general de arriba, la configuración permite elegir opcionalmente una segunda
  > Lista de Precios (`ml_configuracion.lista_precio_id_premium`) sólo para publicaciones de tipo
  > **Premium** (`listing_type_id = gold_pro`, informado por Mercado Libre). Cada vínculo
  > (`ml_publicacion_producto`) persiste su propio `listing_type_id`, evaluado por publicación —no por
  > producto—, dado que un mismo producto puede tener publicaciones de distinto tipo vinculadas
  > (spec 036). Al sincronizar, cada publicación Premium con precio cargado en la lista Premium recibe
  > ese precio; si no tiene precio ahí, o si no hay lista Premium configurada, cae al mismo
  > comportamiento de siempre (lista general). Las publicaciones no Premium siempre usan la lista
  > general. `listing_type_id` se completa al vincular una publicación nueva y se refresca una vez por
  > día mediante el comando `mercadolibre:sincronizar-tipos-publicacion` (independiente de la corrida de
  > stock cada 15 minutos, para no multiplicar llamadas a la API por un dato que casi no cambia); si la
  > consulta a Mercado Libre falla, se conserva el último tipo conocido. Ver
  > `specs/050-lista-precio-premium-ml/`.
- **Tipo de comprobante derivado** de la condición fiscal que informa Mercado Libre: Responsable
  Inscripto → A; Consumidor Final/Monotributo o sin dato → B. Coherente con el principio III de la
  constitución, que exige derivar el comprobante de la condición de IVA. Con **spec 037**
  (`specs/037-padron-arca-cuit/`), cuando el cliente resuelto de la orden es nuevo o no tiene
  condición de IVA ya cargada, y la orden trae un CUIT del comprador, esa derivación se confirma
  además contra el **Padrón de ARCA** (`ws_sr_padron_a13`, misma autenticación WSAA de spec 034) en
  reemplazo de la aproximación previa basada sólo en el dato que informa Mercado Libre — sin UI de
  búsqueda, de forma interna, y degradando al comportamiento anterior si el padrón no responde o no
  encuentra el CUIT. Mismo criterio aplica a la conversión de órdenes de Tiendanube (que ya
  aproximaba por longitud del documento crudo, no por un dato de condición fiscal propio). Con
  **spec 047** (`specs/047-condicion-iva-padron-constancia/`) se corrige que la condición de IVA
  efectivamente llegue: `ws_sr_padron_a13` no la expone (ver §2, corrección 05/08/2026), así que se
  suma `ws_sr_constancia_inscripcion` para completarla — las reglas de precedencia de la spec 037
  (arriba) no cambian, sólo dejan de depender de un dato que nunca llegaba.
- **Fuera de alcance**: comisión de Mercado Libre y costo de envío (la Venta se crea por el monto
  bruto, por lo que el saldo de Mercado Pago en el CRM no coincidirá con el real, neto de comisiones).
  Las cancelaciones posteriores se señalan pero **no** modifican la Venta ya creada.
  > 🚨 **Cancelaciones posteriores a la conversión (spec 063, implementada)**: si una orden ya
  > convertida en Venta pasa a **cancelada**, **reembolso parcial** o entra en **mediación** (esto
  > último se lee del estado de los **pagos**, `payments[].status = in_mediation`, no del estado de
  > la orden), la sincronización marca la orden como **Requiere atención** con el motivo
  > correspondiente (`orden_cancelada` / `orden_reembolso_parcial` / `orden_en_mediacion`) y la fecha
  > de detección — **sin tocar** total, cobro, movimiento de Tesorería ni stock de la Venta ya creada.
  > Es idempotente (repetir la sincronización no duplica el aviso ni mueve la fecha original) y no
  > marca nada si la orden nunca se convirtió o si la Venta ya fue eliminada. La Venta marcada **no
  > queda bloqueada**: se sigue pudiendo editar y cobrar con normalidad, el aviso sólo informa.
  > Desde el aviso se llega a la Venta con un clic; la resolución usa el circuito que ya existe (nota
  > de crédito —recomendado para un comprobante ya emitido— o eliminación de la Venta), **no se
  > construyó ningún circuito de reversión propio**. También se puede **descartar el aviso** sin
  > tocar la Venta (registra quién y cuándo). El aviso se cierra solo, sin ningún paso extra, cuando
  > la Venta queda compensada por una nota de crédito, es eliminada, se descarta a mano, o la orden
  > vuelve a un estado vigente (ej. una mediación resuelta a favor del negocio) — conservando la
  > fecha de detección original si sólo cambió el motivo (ej. una mediación que termina en
  > cancelación). El listado de Ventas muestra un indicador para las que tienen aviso pendiente. Ver
  > `specs/063-ml-cancelaciones-avisos/`.
  > 🛑 **Órdenes en estado excepcional: conversión manual obligatoria (spec 066)**: una orden
  > **cancelada**, con un **reclamo en mediación**, con **reembolso parcial** o con **alerta de fraude**
  > NUNCA se convierte en Venta automáticamente — ni por la creación automática del cron ni por el botón
  > "Transformar todas en Venta". Sólo se puede convertir **a mano, orden por orden**, y con una
  > **confirmación explícita** que indica el motivo por el que está frenada; la confirmación se valida en
  > el servidor, no alcanza con el modal. Queda registrado quién la forzó, cuándo y con qué motivo.
  >
  > Cierra un agujero real: la **mediación no viene en el estado de la orden** sino en el de los pagos
  > (`payments[].status = in_mediation`), y hasta esta spec sólo se miraba en órdenes **ya convertidas**
  > (spec 063), así que una orden que entraba en mediación **antes** de convertirse el cron la convertía
  > igual. También habilita algo que antes era imposible por cualquier vía: **facturar una orden
  > cancelada** cuando el negocio lo necesita.
  >
  > **La Venta forzada se crea sin emitir el comprobante fiscal**: la emisión queda como un paso posterior
  > y deliberado, porque facturar una operación cancelada o en disputa tiene consecuencias impositivas y no
  > debe pasar como efecto colateral de convertir. La derivación del tipo (A/B/C/E) no cambia.
  >
  > Para no duplicar avisos, el aviso posterior de la spec 063 **no se genera por el mismo motivo** que la
  > persona asumió al forzar; sí se genera si después aparece un motivo distinto. Una orden cuyo reclamo se
  > resuelve a favor del negocio vuelve sola al circuito normal. Alcance: **sólo Mercado Libre** — Tiendanube
  > tiene el mismo hueco y queda para una spec aparte. Ver `specs/066-ml-conversion-manual-excepciones/`.
  > 📋 **Transformar todas en Venta (spec 025, implementada)**: botón siempre visible en el listado,
  > independiente de que la creación automática esté activa o no. Convierte en un único request
  > síncrono todas las órdenes en estado "Lista para convertir" de la conexión (ignorando filtros de
  > la tabla), reusando exactamente las mismas reglas de conversión que el flujo manual individual. Al
  > terminar muestra un modal con el resumen (total/convertidas/fallidas/**excluidas**, spec 066 — una
  > exclusión por estado excepcional no es una falla y se informa aparte) y, si hubo fallidas o excluidas,
  > el detalle por orden (motivo y explicación ya persistidos). Sirve tanto para ponerse al día cuando la
  > creación automática estuvo apagada como para forzar la conversión inmediata sin esperar a la
  > próxima corrida programada. Ver `specs/025-conversion-manual-lote-ordenes/`.

> ✅ **Riesgo de sobreventa — cerrado por la spec 013 (implementada)**: al cierre de la spec 012 el flujo
> de stock era unidireccional (ML → CRM), por lo que una venta manual del CRM bajaba el stock local pero
> **no** reducía el stock publicado en Mercado Libre, que seguía ofreciendo unidades inexistentes. La
> spec 013 construyó el sentido inverso (CRM → ML) y ese riesgo ya no está vigente. Ver §3.2.ter.

*Fuente(s): `specs/012-ventas-mercadolibre/`, `specs/063-ml-cancelaciones-avisos/`*

### 3.2.ter Sincronización de stock hacia Mercado Libre (spec 013 — implementada)

Contraparte inversa de §3.2.bis: cierra el circuito de stock en los dos sentidos. **No agrega pantallas
nuevas** — extiende las ya construidas por la spec 012.

- **Disparo**: cualquier movimiento de stock de un producto vinculado, en el **depósito configurado para
  Mercado Libre** (`ml_configuracion.deposito_id`, o el depósito por defecto), marca el vínculo como
  "con cambios pendientes de sincronizar". Es indiferente al módulo que lo originó (Venta manual,
  ajuste, transferencia); se detecta en un único punto, el observer sobre `movimientos_stock`.
- **Anti-rebote**: los movimientos originados en la **conversión de una orden de Mercado Libre** quedan
  excluidos — Mercado Libre ya descontó esa unidad de su propio stock al generar la orden, y empujarla
  de vuelta sería redundante o directamente inconsistente si llegara desfasada.
- **Consolidación**: no se llama a la API por movimiento. Cada corrida envía **un único valor final por
  producto** (el stock actual en el depósito configurado), sin importar cuántos movimientos hubo desde
  el último envío. Evita agotar el límite de solicitudes ante ráfagas (varias Ventas seguidas, una
  importación).
- **Nunca se publica stock negativo**: si el saldo local quedó por debajo de cero (posible tras una
  orden de Mercado Libre, §3.2.bis), se empuja **cero**, sin alterar el valor real que muestra el CRM.
- **Cadencia**: la misma `frecuencia_sync_minutos` que las órdenes, en la misma corrida programada y
  **después** de traerlas, para que el valor empujado ya contemple las órdenes recién sincronizadas.
  Además hay acción manual **"Sincronizar stock ahora"** en la pantalla de Mercado Libre, junto a
  "Sincronizar ahora".
- **Es una escritura**: queda bloqueada por el **modo sólo lectura** y por la desactivación de la
  función avanzada "Mercado Libre" (§5.1), igual que cualquier otra escritura del módulo. En ambos
  casos los cambios pendientes **se conservan** para el próximo intento válido — un corte nunca pierde
  un pendiente. Lo mismo con la conexión caída.
- **Rechazos**: si Mercado Libre rechaza una publicación puntual (pausada, cerrada, inexistente), el
  vínculo queda señalado con el **motivo concreto y la fecha**, el resto de los vínculos de esa corrida
  se sincroniza con normalidad, y el pendiente se conserva para reintentarlo.
  > 🛑 **Corte de reintentos permanentes (spec 063, implementada)**: un error se reintenta en cada
  > corrida sólo mientras no se repita 5 veces seguidas. Si el **mismo** error se repite **5 intentos
  > consecutivos**, el vínculo queda marcado **"Requiere intervención"** — deja de reintentarse y se
  > excluye de la selección de pendientes, para no seguir golpeando la API por un bloqueo que no se
  > va a resolver solo (bajó las llamadas fallidas de ~305 a menos de 10 cada 6 h). Si el error
  > **cambia** respecto del anterior, el contador se reinicia en 1 en vez de acumularse (rachas
  > mezcladas no tendrían sentido). El panel de Vinculación de publicaciones muestra el estado
  > bloqueado, el motivo, los intentos acumulados, la fecha de la primera falla de la racha y la
  > diferencia entre el stock del CRM y el último confirmado en Mercado Libre. Acción manual
  > **"Reactivar"** devuelve el vínculo al ciclo normal una vez resuelto el problema en el
  > marketplace, enviando el stock **vigente al momento de reactivar** (no el que tenía cuando se
  > bloqueó). Al sincronizar con éxito se limpian contador, fecha y marca. Ver
  > `specs/063-ml-cancelaciones-avisos/`.
- > 📋 **Sincronización forzada y eliminación masiva (spec 035)**: en la pantalla de Vinculación de
  > publicaciones hay dos acciones adicionales a "Sincronizar ahora"/"Sincronizar stock ahora"/
  > "Sincronizar precios ahora":
  >   - **"Sincronización forzada"**: recorre **TODOS** los vínculos de la integración (no sólo los
  >     marcados pendientes) y reenvía stock y precio reales a Mercado Libre. Existe porque la
  >     sincronización normal sólo se dispara por movimientos de stock (`MovimientoStockObserver`); si
  >     el stock/precio de un producto se cargó por una vía que no pasa por un movimiento (ej.
  >     importación masiva del catálogo real), el vínculo nunca queda marcado como pendiente y ni el
  >     cron ni "Sincronizar ahora" lo tocan. Es el mecanismo para la sincronización inicial completa al
  >     cargar el catálogo real, y para resincronizar todo puntualmente ante sospecha de desvío. Respeta
  >     los mismos cortes (modo sólo lectura, función desactivada, sin conexión) y el mismo candado que
  >     las sincronizaciones normales.
  >   - **"Eliminar todas las vinculaciones"**: borra, con confirmación previa, **todos** los vínculos de
  >     esa integración del lado del CRM únicamente — no despublica ni modifica nada en Mercado Libre.
  >     No depende del modo sólo lectura ni de la función avanzada (no hay escritura externa), sólo
  >     requiere conexión establecida y respeta el mismo candado de concurrencia (para no borrar
  >     vínculos que una sincronización esté leyendo/actualizando en simultáneo). Es irreversible; el
  >     vínculo se reconstruye con "Vincular automáticamente".
- **Visibilidad**: la pantalla de **Vinculación de publicaciones** muestra por cada vínculo su estado
  (sincronizado / pendiente / con error), la fecha del último envío exitoso y el motivo del último
  rechazo. La pantalla de **configuración de Mercado Libre** muestra fecha y resultado de la última
  sincronización de stock, análogo a lo que ya expone para órdenes. Todo envío queda además en el
  historial de operaciones (`ml_operaciones_log`, spec 011) como operación de escritura.
- **Fuera de alcance**: precio, título, descripción, imágenes y estado (pausar/activar) de la
  publicación — **sólo se sincroniza la cantidad disponible**. Tampoco se pausa ni cierra la publicación
  al llegar a cero: informar cantidad cero ya alcanza para que Mercado Libre deje de venderla.

### 3.2.ter.bis Publicaciones **Full** (fulfillment) — spec 065 (implementada)

Excepción estructural a todo §3.2.ter: **para las publicaciones en Full el stock viaja al revés**.

**Por qué**. Mercado Libre lleva **dos existencias separadas** por producto: la del domicilio del
vendedor y la del centro de distribución de Mercado Libre. La segunda —el stock Full— **no es
escribible por la API**: sólo cambia cuando Mercado Libre recibe físicamente un envío del negocio o
cuando vende. Verificado contra la cuenta real (13/08/2026). No es una decisión de diseño: del lado de
Mercado Libre no existe destino de escritura.

Reglas de negocio:

- **Identificación**: una publicación es Full si su tipo de logística es `fulfillment`
  (`ml_publicacion_producto.logistic_type`). Es el **único** indicador confiable: el identificador de
  inventario aparece también en publicaciones de logística propia. Ante un valor desconocido, ausente
  o nuevo, la publicación se trata como **no Full** — nunca se infiere Full.
- **Depósito propio**: `ml_configuracion.deposito_full_id` designa el depósito del CRM que representa
  la mercadería alojada en el centro de distribución de Mercado Libre. Es **opcional**, lo da de alta
  el usuario a mano, y **debe ser distinto** del depósito general de Mercado Libre.
- **CRM → Mercado Libre**: la publicación Full **sí recibe** el stock del depósito general, pero por
  otro recurso — ver §3.2.ter.ter. Lo que no se escribe es la existencia del centro de distribución.
  *(Corregido el 19/08/2026: hasta entonces la publicación se salteaba entera, y su stock propio
  quedaba congelado para siempre. Ver el detalle abajo.)*
- **Mercado Libre → CRM**: el CRM **lee** la existencia vendible del centro de distribución y la
  refleja en el depósito Full, deduplicando por inventario (publicaciones que comparten inventario
  cuentan una sola vez). La existencia no vendible (dañada, en transferencia) no se computa. A
  diferencia del envío, **este reflejo NO se bloquea por el modo sólo lectura**: es una lectura, y ese
  modo restringe escrituras hacia Mercado Libre.
- **Ventas**: una orden cuyas líneas sean **todas** Full imputa la Venta al depósito Full **sólo si el
  envío salió efectivamente de Full**. Si mezcla Full con logística propia, va al general (una Venta
  tiene un único depósito). Sin depósito Full configurado, va al general. **La conversión de una orden
  nunca se traba por esta configuración.**

  *Corregido el 20/08/2026 (`2696f0a`)*: el `logistic_type` del vínculo describe la **publicación**, no
  el envío. Una publicación Full cuyo depósito de Mercado Libre se vació sigue vendiendo, pero el
  paquete sale del domicilio (`self_service` / `xd_drop_off`). Esa venta no salió de Full, y sin
  embargo se le imputaba: descontaba de Full, el reflejo lo devolvía a cero, y **la unidad vendida no
  se descontaba de ningún depósito**. Ahora, cuando los vínculos dicen Full, se confirma contra
  `GET /shipments/{id}` — el id ya viene en el payload de la orden. Ante cualquier duda va al general,
  que es donde el stock existe (mismo criterio que FR-005).

  Caso real: venta 24587 del 19/08 — publicación `fulfillment`, envío `self_service` en
  `ready_to_print`. Movimientos: salida −1 en Full a las 21:33, ajuste +1 a las 21:37.
  **Validado contra Contagram** (informe de movimientos del 20/08): los mismos productos tienen ventas
  imputadas a Full el 18/08 y a Local el 19/08, cuando el depósito Full se vació. Contagram también
  decide venta por venta.
- **Reposición hacia Full**: **manual**, por decisión del negocio y porque Mercado Libre no la expone
  por API. El CRM no la automatiza; si se quiere dejar registro, se usa un movimiento entre depósitos.
- **El depósito Full es un espejo, no un depósito escribible.** `SincronizadorStockFull` lo iguala al
  inventario real de Mercado Libre en cada corrida, así que cualquier valor que se le escriba —a mano,
  por `stock:ajustar-desde-hoja` o por el módulo de importación (§Base de Datos)— se revierte en menos
  de cinco minutos, sin aviso. Verificado el 20/08/2026: un ajuste de −2 duró **once segundos**.
  **Pendiente**: el mapeo del módulo de importación sigue ofreciendo "Stock: Full" como si fuera un
  destino válido.

**Qué problema resuelve**. Hoy el CRM suma ambas existencias en un solo depósito. Caso real: un
producto con 4 unidades en el centro de distribución de Mercado Libre y 3 en el depósito propio se ve
como "7 en un solo lugar", sin poder distinguir cuáles puede despachar el negocio por sus propios
medios. Ver `specs/065-ml-deposito-full/`.

### 3.2.ter.ter El stock **propio** de una publicación Full sí se escribe (corregido 19/08/2026)

**El error que se corrigió.** La spec 065 concluyó que en una publicación Full no había destino de
escritura, y el sincronizador la salteaba entera limpiándole el `stock_pendiente`. La conclusión era
correcta para la mitad Full y **equivocada para la otra mitad**: la existencia del domicilio del
vendedor sí es escribible.

Consecuencia: el stock propio de esas publicaciones quedaba **congelado en el valor que tenía el día
que la publicación pasó a Full**, sin que ningún indicador lo denunciara — al limpiarse el pendiente,
la publicación dejaba de figurar como que le faltaba algo.

Falla en los dos sentidos, y ambos se vieron en producción el 19/08/2026:

```
43005  Kit Arizona   ML ofrecía 4   CRM Local  0   → vendía lo que no había (lo reportó el cliente)
12700  Mixer         ML ofrecía 6   CRM Local  3   → vendía de más
41363  Tecla         ML ofrecía 0   CRM Local 30   → pausada por "sin stock" con 30 en depósito
```

**El modelo de datos de Mercado Libre.** Una publicación Full reparte el stock en dos ubicaciones
bajo un "user product":

```
GET /user-products/{user_product_id}/stock
  locations: [ {type: selling_address, quantity: N},      ← domicilio del vendedor, ESCRIBIBLE
               {type: meli_facility,   quantity: M} ]     ← centro de distribución, NO escribible
```

`available_quantity` del ítem pasa a ser un valor **derivado** de esas dos. Por eso
`PUT /items/{id}` responde `400 item.available_quantity.not_modifiable`: no se escribe un campo
calculado, se escribe lo que lo compone. En una publicación **no** Full hay una sola ubicación y
`PUT /items/{id}` sigue siendo el camino correcto (267 de las 270 publicaciones van por ahí).

**El recurso correcto** (verificado contra la cuenta real, 19/08/2026):

```
PUT /user-products/{user_product_id}/stock/type/selling_address
    header  x-version: {la que devolvió el GET de /stock}
    body    {"quantity": N}
    →  204 No Content
```

Dos detalles que hacen fallar el intento ingenuo:

1. **La ubicación va en el path**, no en el cuerpo. `PUT /user-products/{id}/stock` con un array de
   `locations` devuelve **404**, igual que `/stock/selling_address` sin el `/type/`.
2. **Control de concurrencia optimista**: el `GET` del stock devuelve un header `x-version`; el `PUT`
   tiene que reenviarlo. Sin él, 400; con una versión vieja, 409 → hay que releer y reintentar.

**Cómo quedó implementado.** `SincronizadorStock` elige el recurso según `esFull()`, y
`ml_publicacion_producto.user_product_id` se persiste al clasificar (`SincronizadorTiposPublicacion`)
y al vincular (`VinculadorAutomatico`), para no pagar un `GET` por publicación en cada corrida. Sin
ese id la publicación se saltea como antes: no hay destino hasta que la corrida de tipos lo resuelva.
`meli_facility` sigue intocable y su reflejo inverso lo sigue haciendo `SincronizadorStockFull`.

### 3.2.ter.quater Regla operativa: **nunca** corregir stock con `UPDATE` directo

Escribir `stocks.cantidad` a mano **saltea `MovimientoStockObserver`**, que es el único punto que marca
las publicaciones de Mercado Libre y Tiendanube como pendientes de sincronizar. El dato queda bien en
el CRM y **congelado en las integraciones, sin error ni marca de pendiente**.

Incidente real (14–17/08/2026): se corrigieron stocks con `UPDATE` directo a pedido del usuario para
no generar movimientos. La publicación `MLA1500482785` (Mixer, Colecta) quedó publicando **0 con 3
unidades en depósito durante tres días**, sin dar error. Se detectó recién al cruzar contra la API.

Para corregir un conteo real usar siempre `StockService::ajustar()` —el mismo mecanismo que un ajuste
desde la pantalla—: deja el movimiento trazable, dispara el observer y la cadena se completa sola
(movimiento → observer → publicación pendiente → cron → Mercado Libre). Si aun así hay que escribir
directo, hay que marcar a mano las publicaciones afectadas:

```sql
UPDATE ml_publicacion_producto SET stock_pendiente = 1 WHERE producto_id = ...;
```

**Un segundo efecto del observer**, a tener presente: sólo mira el depósito general
(`if ($movimiento->deposito_id !== $depositoMl->id) return;`). Un movimiento en el depósito **Full**
no marca nada, así que un cambio que ocurra únicamente ahí no dispara sincronización.

> **Nota — Compras también mueven stock** (spec 030, §6.2): el disparo reacciona a *cualquier* movimiento
> de stock, y desde spec 030 las Compras generan los suyos igual que Ventas y ajustes — quedan cubiertas
> por este mecanismo sin cambios adicionales.

*Fuente(s): `specs/013-stock-mercadolibre/`, `specs/035-sincronizacion-forzada-vinculaciones/`, `specs/063-ml-cancelaciones-avisos/`*

### 3.2.ter.quinquies Cada publicación cotiza por **su** lista (incidente del 25/08/2026)

Mercado Libre cobra distinta comisión según el tipo de publicación, y por eso el CRM maneja **dos**
listas de precios (spec 050), configurables en la pantalla de la integración:

```
gold_special  (Clásica)   →  lista_precio_id            "ML"
gold_pro      (Premium)   →  lista_precio_id_premium    "ML Premium"   ≈ ML × 1,4535
```

`SincronizadorPrecios::resolverListaPrecio()` es **el único lugar** donde se decide qué lista le
corresponde a un vínculo. Todo camino que empuje precios tiene que pasar por ahí.

**Qué pasó.** `PrecioProductoObserver` no lo hacía: miraba sólo la lista general y le mandaba ese
precio a *todos* los vínculos del producto. Una importación masiva del 25/08 cambió la lista general
y dejó **18 publicaciones Premium publicadas un 31% por debajo** de su precio real, durante 30 horas.
No se vendió ninguna, pero por rotación baja —tapas de inodoro y repuestos—, no por ninguna barrera
del sistema. Corregido el 26/08; regresión fijada en
`tests/Feature/Integraciones/PrecioProductoObserverPremiumTest.php`.

**Por qué fue invisible.** Mercado Libre acepta una **baja** de precio sin chistar: la publicación
queda activa, sin error y con `precio_pendiente = 0`, o sea marcada como sincronizada correctamente.
No hay forma de detectarlo desde el CRM: **sólo se ve comparando contra la API**, y comparando cada
publicación contra la lista que le toca por tipo (compararlas todas contra la lista general muestra
a las 30 Premium como desfasadas y esconde el problema real).

**Dos resquicios que quedan abiertos, por diseño:**

- Una publicación Premium **sin precio en la lista Premium** cae a la lista general (spec 050,
  FR-008). Es la misma consecuencia económica, pero deliberada: la alternativa es no publicar precio.
  Al 26/08/2026 no hay ninguna en esa situación.
- Un vínculo **recién creado no tiene `listing_type_id`** hasta que corre la sincronización de tipos,
  y hasta entonces se lo trata como Clásico. Si es Premium, en esa ventana recibe el precio Clásico.

### 3.2.ter.sexies El CRM publica cualquier precio, por absurdo que sea

**No hay ninguna validación de magnitud antes de empujar un precio a Mercado Libre.** Si el CRM tiene
un precio mal, lo publica.

Quedó demostrado el 26/08/2026: la migración del 06/08 leyó la columna de la lista `ML` con el punto
como separador decimal —"262.252,00" entró como **262,26**— y dejó **146 productos con el precio
dividido por 1000**. El CRM intentó publicar $262 por un producto de $262.252 y lo único que lo frenó
fue la validación de **Mercado Libre**, que rechazó el importe con `Validation error`. Del lado del
CRM no hubo ninguna alarma: los vínculos quedaron seis meses en `precio_pendiente = 1` sin que nadie
lo mirara.

La lección incómoda: **la red de contención fue de Mercado Libre, no nuestra**. Un error más chico
—dividir por 10, o el 31% del caso Premium— pasa el filtro de ML y se publica.

Corregido con `php artisan precios:corregir-escala-ml`, que copia el valor de la lista Tiendanube
—idéntica a `ML` en 8.695 de 9.034 productos, mientras que `ML Sugerido` es un precio distinto en el
100% de los casos sanos— y exige que una segunda lista lo confirme.

Eso se resuelve en la **spec 084** (§3.2.ter.septies).

### 3.2.ter.septies Corte de seguridad para las bajadas de precio — spec 084 (especificada)

Respuesta a los dos incidentes de arriba. **Ninguna bajada de precio llega a Mercado Libre sin que
una persona la haya visto**, y lo que se desfase se ve solo.

**El corte.** Antes de cada envío se compara el precio propuesto contra el **último precio que
Mercado Libre aceptó** (columna `precio_publicado` del vínculo). Si la caída supera el umbral
configurado —**20% por defecto**, editable en la pantalla de la integración— no se envía nada: la
publicación conserva su precio y queda **retenida**, con el propuesto guardado. Se aprueba o se
rechaza desde Vinculaciones.

Cuatro reglas que definen el comportamiento y conviene no "simplificar":

- **Sólo bajadas.** Una subida nunca se retiene: no hace perder dinero en una venta y retenerlas
  convertiría cada actualización de lista en una cola de aprobaciones.
- **El borde**: una caída *igual* al umbral pasa; se retiene lo *mayor*.
- **Sin referencia se retiene.** Un vínculo sin `precio_publicado` conocido no se publica. Falla
  cerrado: sin saber qué hay publicado no se puede afirmar que no se está bajando.
- **El corte vive dentro de `SincronizadorPrecios::enviarUno()`**, que es el único punto que hace el
  `PUT`. No se replica en los llamadores: replicar una regla en tres lugares fue exactamente la
  causa del incidente del 25/08.

**"Retenida" no es "pendiente".** `precio_pendiente` significa "hay algo que mandar y no se pudo";
retenida significa "hay algo que **no se va a mandar** hasta que alguien lo apruebe". Si se
reusara el mismo campo, el reintento de pendientes publicaría justo lo que hay que frenar.

**Cambiar la lista configurada pide confirmación.** Antes, mover ese select republicaba las 270
publicaciones al guardar, sin previa ni deshacer. Ahora muestra primero cuántas suben, cuántas bajan
y cuántas quedarían retenidas.

**Chequeo diario CRM ↔ API**, visible en `/monitoreo` junto al panel de stock. Compara cada
publicación contra la lista que le toca **por tipo**; comparar todo contra la lista general produce
30 falsos positivos sobre 270 y vuelve inservible el panel. Es de sólo lectura: informa, no corrige.

**Orden de activación, no negociable**: migrar → correr `php artisan ml:chequear-precios
--refrescar-publicado` para poblar `precio_publicado` → verificar en el monitoreo que las 270 lo
tienen → recién ahí prender el interruptor. El corte **nace apagado**
(`ml_configuracion.corte_precios_activo = false`) justamente para que ese orden no se pueda saltear:
con `precio_publicado` vacío retendría todo el primer día y la reacción natural sería desactivarlo.

⚠️ **Ese interruptor no es una perilla para cuando el corte moleste** — para eso está el umbral. Si
se apaga, el CRM vuelve a publicar cualquier precio sin validar, que es el estado del 25/08/2026.

**Brecha conocida**: Tiendanube comparte la exposición de publicar cualquier precio sin validar. No
tiene el problema de las dos listas (usa una sola) y no causó incidentes, pero queda fuera de la
spec 084 y necesita la suya.

*Fuente(s): `specs/084-corte-bajada-precios-ml/`*

### 3.2.quater Tiendanube (`/ingresos/tiendanube`) — spec 017

Entrada **condicional** del menú Ingresos: aparece sólo cuando la función avanzada "Tiendanube" está
activa (mismo patrón que Mercado Libre y Abonos). Continúa la conexión de la spec 019 (§5.3, corrige a
la 015) con el mismo alcance funcional que la etapa 2 de Mercado Libre (§3.2.bis), adaptado a las
diferencias reales de la API de Tiendanube — **sin relevamiento propio de Contagram** (el relevamiento
con capturas no pudo completarse para esta tarjeta, §5.3): el diseño sigue el patrón ya construido para
Mercado Libre en vez de calcar una pantalla real.

> ⚠️ **Corrección post-019 (30/07/2026)**: la spec 017 se escribió contra la documentación REST pública
> de Tiendanube. La conexión real (spec 019) habla contra el servidor MCP `admin-mcp.tiendanube.com`,
> con un contrato de tools verificado empíricamente que difiere en varios puntos — corregido en
> `specs/017-ventas-tiendanube/` y reflejado abajo: el campo se llama `fulfillment_status` (no
> `shipping_status`), la exclusión de `storefront=meli` es de una sola capa (no hay parámetro para
> filtrarlo en la consulta), y no existe `billing_document_type` (sólo `cpf_cnpj`, casi siempre vacío en
> la tienda real).

- **Listado de órdenes sincronizadas**: identificador de orden, fecha, comprador, productos y
  cantidades, monto, `status`/`payment_status`/`fulfillment_status` (los dos primeros derivan el estado
  de conversión; el tercero es sólo informativo) y **estado de conversión** en el CRM (los mismos cinco
  valores que Mercado Libre: Pendiente de pago · Lista para convertir · Requiere atención · Convertida ·
  Cancelada). Botón "Sincronizar ahora" + tarea programada con frecuencia configurable — **sin
  webhooks** todavía, aunque desde la spec 024 la sincronización habla contra la REST API estándar
  (`GET /orders`, que sí soporta webhooks) en vez del servidor MCP: migrar a webhooks reales queda como
  trabajo futuro, condicionado a que el proyecto migre de XAMPP local a un VPS con endpoint público
  estable.
- **⚠️ Exclusión del canal Mercado Libre integrado a Tiendanube**: las órdenes con `storefront = "meli"`
  (ventas hechas en Mercado Libre pero importadas a Tiendanube por su canal integrado) **nunca** se
  sincronizan ni aparecen en el listado — descarte explícito antes de persistir, en `TraductorOrdenes`
  (una sola capa: la tool `list_orders` no admite filtrar el canal en la propia consulta, corrección
  post-019). Sin esta exclusión, esas ventas se duplicarían con la integración directa de Mercado Libre
  (§5.2), que las ingresa por su propia vía.
- **Vinculación variante ↔ producto**: a diferencia de Mercado Libre (que vincula por publicación),
  Tiendanube siempre expone un identificador de **variante** por línea de pedido —incluso los productos
  sin variantes reales tienen una "variante virtual" única—, así que el vínculo persistente es
  variante↔producto del CRM. Relación **1:N** (un Producto puede tener varias variantes vinculadas
  simultáneamente; spec 036, 03/08/2026 — hasta esa spec fue estrictamente 1:1); la unicidad por
  variante (`variant_id`) se mantiene garantizada por índice único. Pantalla propia de administración,
  igual patrón que Mercado Libre.
  > 📋 **Vinculación automática por catálogo REST en vivo (spec 024, reemplaza a specs 017/021)**: un
  > único botón "Vincular automáticamente" recorre el catálogo REST en vivo del vendedor conectado (`GET
  > /products`, paginado) y compara el `sku` de cada variante —expuesto directo en esa misma respuesta,
  > sin llamada adicional— contra el `id` del producto del CRM, igual patrón que Mercado Libre (spec 023).
  > Reemplaza por completo tanto el selector manual que sólo conocía variantes vistas en pedidos ya
  > sincronizados (spec 017) como la importación por Excel que matcheaba por `codigo`/slug (spec 021,
  > retirada): ahora se puede vincular un producto que nunca vendió por Tiendanube con sólo cargarle el
  > `id` del producto del CRM como SKU en Tiendanube. A diferencia de Mercado Libre, acá no se excluyen
  > productos con variantes múltiples: cada variante ya es su propia unidad de vinculación. Ver
  > `specs/024-tiendanube-migracion-rest/`.
- **Conversión a Venta**, manual o automática (interruptor en la configuración de Tiendanube). La Venta
  se crea cobrada contra la **cuenta de Tesorería configurable** (a diferencia de Mercado Libre, que
  siempre usa "Mercado Pago": Tiendanube admite múltiples medios de pago sin una pasarela canónica) y
  descuenta stock del depósito configurado. Cliente emparejado por `tn_customer_id` o, si es la primera
  vez, por email. Queda asignada además al **Vendedor por defecto** configurado (opcional, spec 020 —
  independiente del de Mercado Libre).
- **Tipo de comprobante derivado**: Tiendanube no informa la condición de IVA del comprador (a
  diferencia de Mercado Libre). Se deriva primero de la condición de IVA que el Cliente ya tenga cargada
  en el CRM y, sólo si no la tiene, se aproxima por longitud del documento (`cpf_cnpj`: 11 dígitos → A,
  cualquier otro valor o ausencia → B) — la misma regla de aproximación que Mercado Libre usa como
  respaldo (§5.2) pasa a ser la regla principal acá, con la misma vía de corrección manual posterior. En
  la práctica, verificado contra la tienda real, casi ninguna orden trae este dato — Consumidor
  Final/Factura B es el resultado dominante.
- **Fuera de alcance**: comisión de Tiendanube y costo de envío; importación masiva de catálogo.
  > 📋 **Transformar todas en Venta (spec 025, implementada)**: mismo botón y comportamiento que en
  > Mercado Libre (§3.2.bis) — siempre visible, procesa en un único request síncrono todas las
  > órdenes "Lista para convertir" de la conexión de Tiendanube y muestra un modal con el resumen y el
  > detalle de fallidas. Ver `specs/025-conversion-manual-lote-ordenes/`.

> ✅ **Numeración de specs**: esta continuación de la spec 015 se documentó originalmente como "specs 016
> y 017" antes de que la 016 terminara siendo un feature chico no relacionado (gestión de precios de
> Mercado Libre desde una Lista de Precios, §5.2 etapa 4); por eso la Venta de Tiendanube quedó en la 017
> y su stock en la 018, no en la 016/017 como se anotó en un primer momento.

> ✅ **Riesgo de sobreventa — cerrado por la spec 018**: al cierre de la spec 017 el flujo de stock era
> unidireccional (Tiendanube → CRM), por lo que una Venta de cualquier origen bajaba el stock local pero
> **no** reducía el stock publicado en Tiendanube. La spec 018 construyó el sentido inverso (CRM →
> Tiendanube) y ese riesgo ya no está vigente. Ver §3.2.quinquies.

*Fuente(s): `specs/017-ventas-tiendanube/`*

### 3.2.quinquies Sincronización de stock y precios hacia Tiendanube (spec 018)

Contraparte inversa de §3.2.quater: cierra el circuito de stock en los dos sentidos, mismo patrón que la
spec 013 aplicó sobre la 012 para Mercado Libre (§3.2.ter). **Ampliada (30/07/2026)** para agregar también
la gestión de precios hacia Tiendanube, mismo patrón que la spec 016 aplicó para Mercado Libre (§3.2.bis,
"Etapa 4"). **No agrega pantallas nuevas** — extiende las ya construidas por la spec 017 más la pantalla
de Productos (botón de precios).

- **Disparo**: cualquier movimiento de stock de un producto vinculado, en el **depósito configurado para
  Tiendanube** (`tn_configuracion.deposito_id`, o el depósito por defecto), marca el vínculo como "con
  cambios pendientes de sincronizar". Es indiferente al módulo que lo originó (Venta manual, ajuste,
  transferencia); se detecta en el mismo observer sobre `movimientos_stock` que ya usa Mercado Libre
  (spec 013), con una rama propia para Tiendanube.
- **Anti-rebote**: los movimientos originados en la **conversión de una orden de Tiendanube** quedan
  excluidos — Tiendanube ya descontó esa unidad de su propio stock al generar la orden, y empujarla de
  vuelta sería redundante o directamente inconsistente si llegara desfasada.
- **Consolidación**: no se llama a la API por movimiento. Cada corrida envía **un único valor final por
  producto** (el stock actual en el depósito configurado), sin importar cuántos movimientos hubo desde el
  último envío. Los vínculos pendientes se agrupan en lotes de hasta 50 por llamada a la tool de
  Tiendanube (`update_stock_and_price`, verificado post-019), no una llamada por producto.
- **Nunca se publica stock negativo**: si el saldo local quedó por debajo de cero, se empuja **cero**, sin
  alterar el valor real que muestra el CRM.
- **Cadencia**: la misma `frecuencia_sync_minutos` que las órdenes, en la misma corrida programada y
  **después** de traerlas. Además hay acción manual **"Sincronizar stock ahora"** en la pantalla de
  Tiendanube, junto a "Sincronizar ahora".
- **Es una escritura**: queda bloqueada por el **modo sólo lectura** y por la desactivación de la función
  avanzada "Tiendanube" (§5.1), igual que cualquier otra escritura del módulo. Los cambios pendientes
  **se conservan** para el próximo intento válido.
- **Rechazos**: si Tiendanube rechaza una actualización puntual (producto/variante despublicado o
  inexistente), el vínculo queda señalado con el **motivo concreto y la fecha**, el resto de los vínculos
  de esa corrida se sincroniza con normalidad, y el pendiente se conserva para reintentarlo.
- **La tool exige el producto, no sólo la variante**: a diferencia de lo que la vinculación de la
  spec 017 capturaba (sólo `variant_id`), la tool `update_stock_and_price` del servidor MCP (spec 019,
  corrección post-019 — no un endpoint REST) exige `product_id` por cada ítem del lote. La spec 018
  agrega `tn_product_id` a la vinculación variante↔producto para poder armar esa llamada.
- **Visibilidad**: la pantalla de **Vinculación de variantes** muestra por cada vínculo su estado
  (sincronizado / pendiente / con error), la fecha del último envío exitoso y el motivo del último
  rechazo. La pantalla de **configuración de Tiendanube** muestra fecha y resultado de la última
  sincronización de stock. Todo envío queda además en el historial de operaciones (`tn_operaciones_log`,
  spec 015) como operación de escritura.
- **Fuera de alcance (stock)**: nombre, descripción, imágenes y estado de publicación de la variante —
  de estos atributos, sólo la cantidad disponible (y, desde la ampliación, el precio) quedan en alcance.
  Tampoco se despublica automáticamente al llegar a cero.

**Gestión de precios hacia Tiendanube (ampliación 30/07/2026)**: la configuración de Tiendanube permite
elegir opcionalmente una Lista de Precios (`tn_configuracion.lista_precio_id`) que gestiona los precios de
las **variantes vinculadas** — mismo rol que `ml_configuracion.lista_precio_id` cumple para Mercado Libre
(§3.2.bis, spec 016), adaptado a la vinculación por variante:

- **Disparo por evento, sin cron**: a diferencia del stock, el precio se sincroniza en el momento del
  cambio (modal de Producto o importación masiva) sobre un producto vinculado dentro de la lista
  configurada — no hay corrida programada para este flujo, porque el precio cambia por una causa directa
  y deliberada, no por acumulación de movimientos indirectos como el stock.
- **Acción manual "Sincronizar precios ahora"**: vive en la pantalla de **Productos** (no en Tiendanube),
  mismo botón ya existente para Mercado Libre (spec 016) — un solo click reintenta los pendientes de
  ambas integraciones.
- **Cambiar la Lista de Precios configurada** empuja de inmediato el precio vigente de la nueva lista a
  todas las variantes vinculadas que tengan precio cargado ahí.
- **No es fuente de precio de Ventas**: el precio de las líneas de las Ventas creadas al convertir una
  orden de Tiendanube (spec 017) sigue derivándose exclusivamente del importe pagado en la orden; esas
  Ventas tampoco quedan etiquetadas con esta Lista de Precios.
- **Visibilidad**: mismo criterio que stock — la pantalla de Vinculación de variantes muestra el estado
  de sincronización de precio (sincronizado/pendiente/error) en una columna separada de la de stock.

> 📋 **Sincronización forzada y eliminación masiva (spec 035)**: mismas dos acciones adicionales que en
> Mercado Libre (§3.2.ter), en la pantalla de Vinculación de variantes:
>   - **"Sincronización forzada"**: recorre TODOS los vínculos (no sólo pendientes) y reenvía stock y
>     precio reales a Tiendanube. Mismo caso de uso: cubrir productos cuyo stock/precio se cargó sin
>     pasar por un movimiento de stock (import masivo del catálogo real), y permitir resincronizar todo
>     puntualmente. Respeta los mismos cortes (modo sólo lectura, función desactivada, sin conexión) y
>     el mismo candado que las sincronizaciones normales.
>   - **"Eliminar todas las vinculaciones"**: borra, con confirmación previa, todos los vínculos de
>     Tiendanube del lado del CRM únicamente — no despublica ni modifica nada en Tiendanube. No depende
>     del modo sólo lectura ni de la función avanzada, sólo de que haya conexión establecida, y respeta
>     el mismo candado de concurrencia. Irreversible; se reconstruye con "Vincular automáticamente".

*Fuente(s): `specs/018-stock-tiendanube/`, `specs/035-sincronizacion-forzada-vinculaciones/`*

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
- ~~**Remitos**: estructura de pantalla real de Contagram sigue sin relevar con capturas.~~
  ✅ **Brecha cerrada el 12/08/2026** por `docs/Contagram-Informe-Remitos.md` (12 capturas reales:
  alta, modal de transportista, PDF, edición y segundo remito sobre la misma venta). El módulo
  completo se especifica en `specs/064-modulo-remitos/`. Ver §3.6bis.
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
deshabilitado en el menú de fila de Ventas), Remitos con detalle de ítems, Recibos, integración WhatsApp
("Enviar Whatsapp" deshabilitado) y Abonos (no construido en esta spec; su link en el sidebar no existe
todavía).

**Actualización (spec 034, Facturación Electrónica ARCA/AFIP):** implementada. El CRM solicita CAE real
vía WSAA (autenticación con certificado propio del negocio) + WSFEv1 (`app/Services/Arca/`), reemplazando
el watermark "NO VÁLIDO COMO FACTURA" del PDF por el CAE, vencimiento del CAE y QR fiscal AFIP cuando la
emisión es exitosa. Sin certificado/Punto de Venta configurado, o ante rechazo/caída de ARCA, la Venta
queda igual con el fallback local sin validez fiscal (numeración `tipo_comprobante`/`nro_comprobante`
existente, sin bloquear el cobro) — el motivo se informa por toast o modal según el caso (ver
Actualización spec 040 abajo). Las Notas de Crédito/Débito de Ventas con CAE obtienen su propio CAE
referenciando el comprobante original. Reintentos son siempre manuales; antes de reintentar se reconcilia
automáticamente contra `FECompConsultar` para no duplicar comprobantes ante un timeout previo. Un
comprobante con CAE aprobado es inmutable (Tipo de Comprobante/cliente/ítems bloqueados en edición).
Pantalla nueva "Configuración & Ajustes → Facturación Electrónica" para cargar el certificado (`.crt`/
`.key`, cifrado en disco) y administrar Puntos de Venta. Sigue documentada como brecha (§7) la ausencia
de un informe con capturas reales de Contagram para esa pantalla de configuración.

**Actualización (spec 040, 04/08/2026 — corrige un defecto de spec 034):** la solicitud de CAE **ya NO
se dispara automáticamente al confirmar el cobro**. Ese comportamiento (documentado antes en este mismo
párrafo) se había especificado sin respaldo de captura real, y causó un incidente real el 04/08/2026:
una Venta de prueba en el VPS de producción envió automáticamente una solicitud de CAE contra ARCA
**producción**, rechazada por un error de cálculo de IVA, sin que ningún usuario ejecutara una acción
explícita. El comportamiento correcto (confirmado por el dueño del negocio contra Contagram real) es un
botón **"Enviar a ARCA"** por fila en el listado de Ventas (menú de acciones, `resources/views/ventas/_row_actions.blade.php`),
disponible sólo para Ventas A/B/C sin `ComprobanteFiscal` aprobado, con o sin cobros registrados, y
protegido por el mismo permiso `ventas.ver` que el resto del listado. El resultado real de un intento
contra ARCA (aprobado con CAE, o rechazado) se muestra en un **modal** persistente
(`#modal-resultado-arca`) — un rechazo de precondición (Venta no elegible, función desactivada, sin
certificado configurado) se informa por **toast**, porque ahí ni siquiera se llegó a contactar a ARCA.
Detalle completo en `specs/040-envio-manual-arca/`.

**Corrección (14/08/2026 — DocTipo del receptor):** el Cliente guarda **todos los tipos de documento en
una misma columna** (`clientes.cuit`: CUIT, CUIL, DNI, Pasaporte o CDI) y es `clientes.tipo_documento`
quien determina cuál es. **El `DocTipo` que se envía a ARCA se deriva de `tipo_documento`, nunca del
contenido de la columna**: CUIT→80, CUIL→86, CDI→87, Pasaporte→94, DNI→96, y 99 (Consumidor Final sin
identificar) cuando no hay documento. Hasta esta corrección el payload informaba el número siempre como
CUIT (DocTipo 80), por lo que **toda Factura B a consumidor final con DNI era rechazada** por ARCA con
"DocNro … no se encuentra registrado en los padrones de AFIP" (caso real: Venta 24447 en el VPS,
14/08/2026, DNI 27501362 enviado como DocTipo 80). El armado del payload del receptor está centralizado
en `Cliente::datosFiscalesArca()` — Ventas y NC/ND lo consumen, no duplican la lógica. Si
`tipo_documento` está vacío se asume CUIT (comportamiento histórico): **no se infiere el tipo por
longitud del número**, para no emitir con un DocTipo adivinado.

**Corrección (14/08/2026 — REGLA CRÍTICA: una Venta/Compra/NC-ND puede tener VARIOS comprobantes
fiscales):** cada intento contra ARCA persiste su propio `ComprobanteFiscal` — los rechazos **se
conservan** (con `numero`, `cae` y `cae_vencimiento` en NULL, más su `motivo_rechazo` y su registro en
`arca_logs_auditoria`) porque son la evidencia de lo que ARCA respondió. Un reintento exitoso **agrega**
un segundo registro, aprobado, **no reemplaza al rechazado**. En consecuencia:

- **`Venta::comprobanteFiscal()` (y sus gemelas en `Compra` y `NotaCreditoDebito`) devuelve el
  comprobante VIGENTE: el aprobado si existe, y sólo si no hay ninguno aprobado, el último intento.**
  Está implementado como `morphOne` con orden explícito (`CASE WHEN estado = 'aprobado' THEN 0 ELSE 1
  END`, luego `id DESC`), no como un `morphOne` pelado. **Nunca volver a declarar esta relación sin ese
  orden**: un `morphOne` sin ordenar devuelve un intento arbitrario —en la práctica el rechazo más
  viejo— y de esta relación dependen `estaFacturada()`, `puedeEnviarseAArca()`, el PDF de la Venta
  (CAE, vencimiento y QR fiscal) y el modal de resultado del envío.
- **Para consultar el historial completo** (filtros del listado por Estado de Factura, búsqueda por
  número, cualquier `whereHas`/`whereDoesntHave`) usar la relación `comprobantesFiscales()`
  (`morphMany`), **no** `comprobanteFiscal()`. Consecuencia conocida y aceptada: una Venta rechazada y
  luego aprobada aparece bajo los filtros "Rechazado" **y** "Aprobado", igual que antes de esta
  corrección.

**Incidente que originó la regla (14/08/2026, Venta 24447 en producción):** tras corregir el DocTipo, el
reintento obtuvo CAE real (`0009-00000007`, CAE 86338366473746), pero la Venta quedó con dos
comprobantes (id 1 rechazado + id 5 aprobado) y la relación devolvía el rechazado. Efectos observados:
el modal mostró "CAE obtenido correctamente" con CAE/Vencimiento/Número en "-", el PDF salió sin CAE ni
QR (con el watermark de comprobante no válido), el botón "Enviar a ARCA" **siguió habilitado** —con
riesgo real de emitir una segunda factura ante el fisco— y la Venta siguió siendo editable pese a tener
CAE aprobado. No fue una regresión del fix de DocTipo: el defecto existía desde spec 034 y quedó latente
porque hasta entonces ninguna Venta había acumulado un rechazo y una aprobación.

**Actualización (spec 039, 03/08/2026):** las Notas de Crédito/Débito con CAE ya tienen su propio
documento imprimible ("Ver Detalle" en la sección de NC/ND del Detalle de Venta), mostrando su CAE,
vencimiento de CAE, QR fiscal y una referencia visible al comprobante de Venta que ajustan (tipo,
número y fecha) — cierra el pendiente que había quedado abierto en spec 034 (T027). Ese PDF y el de
Venta muestran además el encabezado del emisor con los datos de "Mi Perfil" (ver §5) cuando están
cargados; si no lo están, el encabezado se omite sin bloquear la generación del comprobante.
También se agregó "Ver Recibo" en la tabla de Cobranzas del Detalle de Venta y en la tabla de
Pagos del Detalle de Compra: un documento imprimible **no fiscal** (no pasa por WSFEv1/ARCA) con
los datos del emisor, la contraparte (Cliente/Proveedor), medio, monto, fecha y un número interno
`REC-{id}`. **Brecha de relevamiento**: no existe informe con capturas reales de Contagram para
Recibos (a diferencia de otros módulos) — la estructura se construyó como mejor esfuerzo siguiendo
el patrón ya usado en "Ver Detalle" de Venta/Compra, pendiente de contrastar contra capturas reales
si se relevan más adelante.

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

### 3.6bis Remitos (spec 064 — relevado 12/08/2026)

Documento que acompaña la **entrega física** de la mercadería: qué productos, en qué cantidad, con qué
transportista y a qué domicilio. Aplica a **Ventas y Compras**. Relevado con capturas reales en
`docs/Contagram-Informe-Remitos.md` (12 capturas), que cerró la brecha declarada en §5.

**Las dos reglas que lo ordenan todo:**

- **No mueve stock.** Ni al crear, ni al editar, ni al eliminar. El stock ya se descontó al vender (o
  ingresó al comprar). Coincide con la regla de negocio crítica de §11 y con la constitución del
  proyecto. Documentación oficial de Contagram: *"El stock es afectado al momento de vender o comprar,
  no al momento de emitir el remito."*
- **No es fiscal.** Sin CAE, sin ARCA, y **sin precios, IVA ni totales de dinero** en el documento
  imprimible.

**Estructura:**

- **Formulario en página completa** (no modal — mismo criterio que NC/ND, §3.2), titulado "Nuevo
  Remito Venta ID [n]". Precargado con: cliente (no editable), domicilio de entrega (editable, sin
  alterar la ficha del cliente), fecha de emisión, Tipo (X/R), N° de comprobante, transportista
  (selector con buscador), Nota para el Cliente, y **todas las líneas de producto de la operación con
  sus cantidades originales** (producto · observación por línea · cantidad, con tachito para quitar
  filas). Abajo: **Total Bultos** autocalculado y **Monto Asegurado** opcional (interruptor + importe
  precargado con el total).
- **Transportista**: entidad reutilizable con **un único atributo, el nombre**. Se crea al vuelo desde
  un modal dentro del formulario. Sin pantalla de administración propia.
- **Sección "Remitos" en el detalle** de la Venta/Compra, estructuralmente igual a la de Cobranzas:
  Id · Fecha · Transportista · Nota · Total Bultos · Comprobante (enlace "Ver Remito" + lápiz para
  editar).
- **Documento imprimible**: encabezado REMITO con la letra en recuadro, Nro. Remito, Fecha de Emisión,
  Transportista, datos del cliente (razón social, teléfono, persona de contacto, condición de IVA,
  CUIT), Domicilio de Entrega, y tabla **Código · Productos · Observaciones · Cantidad**. El **Monto
  Asegurado no se imprime**: es dato interno.
- **Edición sin campos bloqueados**, a diferencia de las NC/ND (donde Tipo y Stock quedan fijos), con
  botón **Eliminar** en el propio formulario.
- **Varios remitos por operación**, para envíos parciales. Cada remito nuevo precarga **las cantidades
  totales originales**: el sistema **no** descuenta ni recuerda lo ya remitido, el control queda a
  cargo del usuario (comportamiento verificado en Contagram, no una simplificación).

**Divergencias deliberadas respecto de Contagram:**

| Punto | Contagram | Este CRM | Motivo |
|---|---|---|---|
| N° de comprobante | Manual, formato `____-________`, queda vacío si no se completa | **Autonumérico correlativo** | Decisión del usuario (spec 064). Consecuencia: el documento **siempre** trae número |
| Domicilio de entrega en **Compras** | Sin relevar (el informe cubre sólo Ventas) | **Depósito que recibe**, no el proveedor | En una Compra la mercadería viene *hacia* el negocio |

*Fuente(s): `docs/Contagram-Informe-Remitos.md` + `docs/capturas/Capturas-Remitos/` (12 capturas) ·
[Remitos](https://help.contagram.com/es/articles/1319079-remitos) · `specs/064-modulo-remitos/`*

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

> **Reglas confirmadas contra Contagram real el 12/08/2026** (comparación del panel a la misma fecha
> de corte en ambos sistemas, ver `docs/importacion_casos_a_revisar.md` §10 y §12):
>
> - **"Buscar por Fecha" es una fecha de corte, no un rango.** Muestra el saldo **acumulado desde el
>   origen hasta esa fecha inclusive**, sin filtro de inicio. Default: hoy. Verificado: al 01/07/2026
>   los cinco bancos dan idénticos en ambos sistemas (Total Bancos $17.370.690,61 exacto).
>   Consecuencia: un movimiento con fecha futura (ej. un cheque propio a vencer) no aparece hasta que
>   llegue esa fecha.
> - **El campo se muestra en `dd/mm/aaaa`.** No usar `<input type="date">`: el navegador lo dibuja
>   según su locale y llegó a mostrar `08/05/2026` para el 5 de agosto. En este proyecto, donde el
>   origen ya traía día y mes invertidos, la fecha se lee como se escribe en pantalla.
> - **El bloque A Pagar muestra los saldos en positivo** y su total es la **suma** de las deudas
>   (verificado: 22.223.085,07 + 30.000,31 + 212.175,83 + 174.574,63 = 22.639.835,84, el total que
>   muestra Contagram). Los movimientos se guardan en negativo: es sólo convención de presentación.
> - **Los dos "Saldo Cta Cte" no son cuentas**: se calculan con el aging de Clientes/Proveedores a la
>   misma fecha de corte y se anteponen a su bloque.
> - **El nombre canónico de una cuenta es el de su ficha, no el del panel**, que los recorta: el panel
>   dice "Visa", "Mastercard", "Nulo", "Cabal Credicoop", y las fichas se titulan "Visa a Cobrar",
>   "Mastercard a Cobrar", "Nulo a Cobrar", "Cabal Credicoop a Pagar". El sufijo lo llevan las cuentas
>   de tarjeta/valores; cajas y bancos no.
> - **Los importes del panel de Contagram son un total filtrado y no sirven para conciliar.** Para
>   comparar saldos hay que usar la columna `Saldo` de la ficha/export de cada cuenta, que sí es
>   acumulada.

**Configuración de cuentas** (modal "Ajustes Cuentas Tesorería"): tabla agrupada por tipo (Efectivo,
Banco, A Cobrar, A Pagar) con estado Visible. Alta/edición por modal único (tipo bloqueado en edición).
Dos **cuentas del sistema** precargadas —"Cheque de Terceros" (A Cobrar) y "Cheque Propio" (A
Pagar)— no editables ni eliminables, para modelar el circuito de cheques. Una cuenta con movimientos
(más allá de su Saldo Inicial) no se puede eliminar físicamente, sólo ocultar.

**Orden de presentación de las cuentas** (spec 085): cada fila del modal tiene un handle de arrastre
que permite **reordenar las cuentas dentro de su tipo** por drag & drop. Reglas:

- El arrastre está **acotado al bloque**: no se puede mover una cuenta a otro tipo, porque el tipo
  determina en qué card aparece y su naturaleza contable. Reordenar nunca cambia el `tipo`.
- El orden se **persiste al soltar**, sin botón de confirmación, y reasigna `orden = 1..N` a todas
  las cuentas de ese tipo en una transacción (o se guarda el bloque entero, o nada).
- Si el conjunto de cuentas del bloque cambió en paralelo desde otra sesión (alta o baja), el
  guardado se **rechaza entero** y el listado se refresca. El control de concurrencia es la propia
  comparación de conjuntos: no hay versionado ni marcas de tiempo.
- Las cuentas **ocultas y las de sistema participan del orden** como cualquier otra.
- El orden guardado rige en **todos** los listados de cuentas: las cards de Saldos y los selectores
  de cuenta (Movimiento entre Cuentas, cobros, pagos, gastos), porque todos leen por el mismo scope
  `ordenadas()`. Las cuentas sin `orden` asignado van al final, con desempate alfabético.
- También se puede reordenar **por teclado** (flechas arriba/abajo sobre el handle enfocado), con el
  mismo efecto de guardado.
- Los bloques **entre sí** no se reordenan: A Cobrar, A Pagar, Cajas y Bancos tienen posición fija.

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

- **El botón "Exportar" devuelve un XLSX calcado del de Contagram (16/08/2026)**, no un CSV. Se
  relevó contra el archivo real `Informe Final 16-08-2026 1747 Hs.xlsx`, y el nombre sigue ese
  patrón: `Informe Final DD-MM-YYYY HHMM Hs.xlsx`, con la hoja llamada "Informe Final". Disposición:
  título "Movimientos" en C2; fila de Desde · Hasta · Total Cobros · Total Pagos · Resultado; y por
  cada sección una banda azul `#0E5DA1` y otra gris `#C5C9CC` con el nombre repetido, la fila de
  columnas "Descripción"/"Total", una fila por cuenta (nombre en A, importe en E) y el total en D/E.
  Las fechas van como **texto** `dd/mm/aaaa`, no como fecha de Excel. Ver
  `App\Exports\Tesoreria\MovimientosExport`.
- **Qué cuentas lista cada sección** (regla relevada del mismo archivo, no derivable de los datos):
  no lista sólo las que tuvieron movimiento, sino **todas las que aplican a la sección**, y las que
  no tuvieron nada van en **0**.
  - Cobros = cuentas de tipo `efectivo` + `banco` + `a_cobrar`.
  - Pagos = `efectivo` + `banco` + `a_pagar`, **más "Cheque de Terceros"**, que es `a_cobrar` y aun
    así aparece — tiene sentido de negocio (un cheque recibido se endosa para pagar), pero está
    calibrado contra un solo archivo. Si aparece otra cuenta con ese comportamiento, va a la misma
    constante del export.
  - **El signo de los Pagos difiere según el formato**, y no es un error de ninguno de los dos:
    en el **XLSX** van en **negativo** (`-4468870`) y en el **PDF** en **positivo**
    (`$4.468.870,00`). Verificado contra los dos archivos reales del mismo período (16/08/2026).
    `Tesoreria::flujo()` los devuelve en valor absoluto; `SeccionesMovimientos` los entrega con el
    signo del XLSX y la plantilla del PDF los vuelve a pasar por `abs()`.
  - Las reglas de arriba viven en **`App\Services\Tesoreria\SeccionesMovimientos`**, compartido por
    el XLSX y el PDF a propósito: antes cada uno armaba su lista y el PDF mostraba sólo las cuentas
    con movimiento mientras el Excel ya las listaba todas. Hay un test que fija que los dos
    informes listen lo mismo.

- **El botón "Exportar a PDF" replica el mismo informe** (`tesoreria.pdf.movimientos`): encabezado
  de cinco celdas con recuadro (Total Cobros en verde, Total Pagos en rojo), banda turquesa
  `#3c9aa8` con el nombre de la sección, banda gris `#eceff1` repitiéndolo, columnas
  Descripción/Total, una fila por cuenta con el nombre en marrón, y el cierre con el total chico
  sobre chip gris más el total grande. Pie "Pag. X / Y". Los colores se tomaron de una captura, no
  de un archivo con estilos: si aparece el valor exacto, conviene ajustarlos.
- **Pendiente de relevar**: el criterio de ORDEN de las filas dentro de cada sección. No coincide
  con alfabético, ni por id, ni por la columna `orden`. Hoy se ordena por tipo y alfabéticamente
  dentro de cada tipo, que se aproxima pero no calca. Hace falta otro export de un período distinto
  para deducirlo. Además, los nombres de cuenta de Contagram y los de la base importada difieren
  ("Galicia" vs "Banco Galicia", "Visa" vs "Visa a Cobrar"), lo que arrastra diferencias de orden
  aunque el criterio fuera el mismo — **el export muestra los nombres de nuestra base, a propósito**.

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

> **Ampliación spec 058 (11/08/2026, feedback directo de cliente)**: el badge de Estado y el filtro
> "Estado del Pago" ganan un cuarto valor, "Vencido" (`fecha_vto_pago` pasada y saldo A Pagar > 0,
> misma regla que el KPI "Vencido" ya existente) — antes sólo distinguían A Pagar/Parcial/Pagado. Una
> compra 100% pagada nunca es "Vencida" aunque su vencimiento haya pasado. Además, los ítems del
> formulario de Compra admiten **cantidad negativa** (el precio unitario sigue sin poder ser
> negativo) para representar bonificaciones del proveedor dentro de la misma factura — confirmado
> contra una captura real de Contagram aportada por el cliente. Un ítem negativo resta stock (en vez
> de sumarlo) si el producto controla stock.

**Filtros (12 campos, implementados 11/08/2026, spec 056)**: Id, Proveedor (multi-selección), Categoría
de Compra (multi), Estado del Pago (A Pagar/Parcial/Pagado/**Vencido**, agregado 11/08/2026 — mismo
criterio que la card KPI "Vencido"), Tipo y N° de Factura, Etiqueta (multi),
Facturado (Sí/No), Medio de pago, Usuario (multi), Nota Interna, Depósito, Desde/Hasta Servicio. Todos
combinables con AND entre campos; los de selección múltiple usan OR dentro del propio campo. Más los dos
selectores de rango de fecha (Emisión/Vencimiento) del toolbar. Mismo patrón que Ventas (§3.2).

**Menú de fila** (9 opciones, más liviano que el de Ventas que tiene 12 — sin "Imprimir Ticket",
"Enviar Detalle" ni "Enviar Whatsapp"): Ver, Editar, Ver Detalle, Agregar Pago, Crear NC/ND, Crear
Remito, Cta Cte (proveedor), Imprimir Detalle, Eliminar.

**Formulario "Nueva Compra"** (`/purchases/new`): Proveedor (autocompletado; al elegir un proveedor
existente precarga su **Categoría de Compras** guardada como default), Emisión, Vto. del Pago, Servicio
Desde/Hasta (**agregados al formulario el 17/08/2026**: el modelo, los filtros del listado y
`StoreCompraRequest` ya los tenían, pero el formulario nunca los mostró, así que no había forma de
cargarlos salvo importándolos — ver la nota de autocompletado en §3.2 Ventas), **Contador** (campo exclusivo de Compras, sin equivalente en Ventas — tooltip: "Mes de
imputación en el IVA Compras, para el informe a tu Contador"; permite imputar el período fiscal de IVA
Compras independientemente de la fecha de emisión), Tipo de comprobante + numeración. **N° de
comprobante editable (spec 049, 06/08/2026)**: antes de esta spec, la numeración se autogeneraba
siempre como un correlativo interno ficticio (`Compra::siguienteNroComprobante()`, punto de venta fijo
"0001") sin relación con la factura real del Proveedor. Ahora el campo es un input de texto editable,
precargado con ese mismo correlativo como valor sugerido de partida; el usuario puede dejarlo tal cual
o reemplazarlo por el número real de la factura del Proveedor (punto de venta + número). El campo sigue
siendo obligatorio — no se puede guardar la Compra con el campo vacío. Es independiente de
`punto_venta_proveedor`/`numero_comprobante_proveedor`/`cae_proveedor` (campos ya existentes, sólo
usados cuando la función avanzada "Facturación Electrónica" está activa y se carga el CAE real del
Proveedor) — divergencia deliberada, sin capturas de Contagram real que confirmen este comportamiento
específico. Línea de
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

**Depósito (spec 049, 06/08/2026):** el formulario "Nueva Compra"/"Editar Compra" suma un campo
Depósito obligatorio (Select2, catálogo de depósitos activos), simétrico al de Ventas (§3.2), que
determina a qué depósito suma el stock la operación. Precarga desde Configuración & Ajustes → Ventas
(sección "Compras", `configuracion_ventas.deposito_compra_id`) con fallback a `Deposito::porDefecto()`.
Divergencia deliberada, sin capturas de Contagram real que confirmen este campo — motivada por la
misma inconsistencia que en Ventas (el filtro por Depósito no reflejaba ninguna elección real).

**Compras suma stock (spec 030, cierra la brecha simétrica a la de Ventas §3.2; actualizado por spec
049):** al guardar una Compra, cada ítem cuyo producto controla stock suma su cantidad al stock del
depósito elegido en el formulario (antes de spec 049, siempre el depósito por defecto del CRM, sin
poder elegirlo). Editar una Compra reintegra el stock de la versión anterior y
aplica el de la nueva; eliminarla reintegra todo el stock que había sumado. El movimiento queda fechado
con la `fecha_emision` de la Compra (no la fecha de guardado), para que el histórico de stock refleje
cuándo entró realmente la mercadería aunque la carga sea retroactiva. Las Notas de Crédito/Débito de
Compra ya movían stock desde antes (con su propio selector de depósito en el modal, ver más abajo) — no
cambian con esta feature.

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
  **Actualización 14/08/2026 (spec 067) — IMPLEMENTADO**: esta brecha quedó cerrada con el Informe
  de Cuenta Corriente Proveedores (§6.6). El deep-link `?proveedor_id={id}` que abre ese informe en
  el tab "Movimientos" se agregó al menú de fila de **Proveedores**
  (`resources/views/proveedores/_row_actions.blade.php`), espejo exacto del que Clientes ya tenía.
  Nota de implementación: el menú de fila de **Compras** no tenía una entrada "Cta Cte" deshabilitada
  que habilitar —la opción vivía sólo del lado de la entidad, como en Clientes—, así que la brecha se
  cierra donde realmente estaba abierta.
- **Remitos**: "Crear Remito" en Compras crea sólo el encabezado (fecha + número); detalle de ítems
  pendiente de relevamiento propio — apunta al mismo hueco de estructura de pantalla que en Ventas.
- **Recibos de Pagos**: análogo a Recibos de Cobros (§3.5), sin relevamiento propio con capturas.
- **Variantes de producto en Compras**: `CompraItem` no tiene `variante_id` (a diferencia de lo que
  necesitaría para productos con variantes); el movimiento de stock que suma una Compra (spec 030) se
  aplica siempre a la variante `null`. Si el negocio compra productos con variantes por Compra, falta
  relevamiento propio para agregar el selector de variante a la grilla de ítems.

**Actualización (spec 034, Facturación Electrónica ARCA/AFIP):** implementada. Compras no solicita CAE
propio — lo emite el Proveedor (FR-015). Al guardar la Compra, si se declaran los datos fiscales del
comprobante recibido (Punto de Venta, Número, CAE, vencimiento del CAE), el CRM registra un
`ComprobanteFiscal` asociado sin llamar a WSFEv1; el documento imprimible deja de mostrar el watermark
"NO VÁLIDO COMO FACTURA" cuando esos datos están completos. Sin esos datos, la Compra sigue usando la
numeración local (`tipo_comprobante`/`nro_comprobante`) sin validez fiscal, igual que antes.

*Fuente(s): `docs/informe_contagram_egresos.md`*

---

## 5. Módulo Configuración & Ajustes (alcance actual: Empresa, Depósitos, Funciones Avanzadas, Ventas)

> **Actualización (spec 043, 04/08/2026):** reorganización de acceso y navegación de este módulo.
> "Mi Perfil" se renombra a **"Empresa"** y absorbe la gestión de usuarios que antes vivía en la
> pantalla separada "Usuarios y Permisos" (tabla de usuarios, alta, "Roles y Permisos" como link desde
> ahí). Esa pantalla separada se elimina. El acceso a "Empresa" y a **toda** la sección Configuración
> & Ajustes pasa a depender exclusivamente del rol `Admin` (ya existente, `User::esAdmin()` /
> `Gate::before`) en vez de los permisos granulares `configuracion.usuarios`/`configuracion.funciones`/
> `configuracion.roles`. El bloque de sidebar se retira; el acceso vive en el dropdown de usuario de la
> topbar como dos ítems: "Empresa" y un único link "Configuración & Ajustes" que abre una pantalla con
> **tabs** (Funciones Avanzadas —tab por defecto—, Depósitos, Mercado Libre, Tiendanube, Facturación
> Electrónica, Ventas). Los tabs de Depósitos/Mercado Libre/Tiendanube/Facturación Electrónica sólo
> están disponibles si su función avanzada correspondiente está activa (mismo campo `activa` ya
> existente en `funciones_avanzadas`). Se agrega el tab **Ventas**: configuración global (fila única,
> tabla `configuracion_ventas`) de Categoría/Vendedor/Lista de Precios/Tipo de Comprobante por defecto y
> días por defecto de "Vto. del Cobro", que precargan el alta de "Crear Venta" (no afecta ediciones ni
> conversiones desde Presupuesto). Ver `specs/043-configuracion-empresa-ventas/`.
>
> **Actualización (spec 049, 06/08/2026):** ambas secciones ("Ventas" y "Compras", ver más abajo) de
> esta misma pantalla suman un campo **Depósito por defecto** (`deposito_id`/`deposito_compra_id`).
> Precarga, respectivamente, el selector de Depósito ahora obligatorio en "Nueva Venta"/"Nueva Compra"
> (§3.2/§4.1); si no está configurado o el depósito referenciado se inactiva, cae al fallback ya
> existente `Deposito::porDefecto()`. **Divergencia deliberada** sin confirmación contra capturas
> reales de Contagram (motivada por una inconsistencia interna: el filtro por Depósito del listado de
> Ventas no reflejaba nada real porque toda Venta/Compra manual movía stock siempre contra el mismo
> depósito implícito). Ver `specs/049-deposito-ventas-compras/`.

| Sección | Contenido |
|---|---|
| Empresa | Datos fiscales del negocio emisor + gestión de usuarios (alta, roles, activar/desactivar) + link a Roles y Permisos (spec 043, ex "Mi Perfil" + "Usuarios y Permisos") |
| Roles | CRUD completo de roles (crear/renombrar/borrar) y asignación de permisos por rol |
| Depósitos | ABM de depósitos/almacenes (spec 005) |
| Funciones Avanzadas | Lista de las 10 funciones activables, con toggle Sí/No (spec 011) — ver §5.1. Tab por defecto de la pantalla Configuración & Ajustes (spec 043) |
| Mercado Libre | Configuración de la integración y vinculación de cuenta (spec 011) — ver §5.2 |
| Tiendanube | Configuración de la integración (OAuth 2.1 vía admin-mcp.tiendanube.com, spec 019, corrige a spec 015) + apartado aislado de conexión vía Application REST del Partner Portal (spec 022) — ver §5.3 |
| Ventas | Valores globales por defecto para "Crear Venta" (Categoría, Vendedor, Lista de Precios, Tipo de Comprobante, días de Vto. de Cobro, **Depósito** — spec 043/049), sección "Presupuestos" (días de Vto. de Validez, spec 044) y sección "Compras" (Categoría de Compra, Tipo de Comprobante, días de Vto. de Pago, **Depósito** — spec 044/049), todo en una misma pantalla/tabla `configuracion_ventas` |

> **Recuperación de contraseña por email (spec 081, 25/08/2026):** desde el login, un link
> "¿Olvidaste tu contraseña?" abre un modal (email) que dispara el envío de un correo con un link
> de un solo uso (Password Broker estándar de Laravel, tabla `password_reset_tokens` — ver
> `docs/modelo_datos.md §1`). La respuesta es siempre el mismo mensaje genérico exista o no la
> cuenta. Además, desde "Empresa"/perfil, un usuario logueado puede cambiar su propia contraseña
> sabiendo la actual, vía modal AJAX (sin flujo de email). Ver `specs/081-reset-password-email/`.
>
> **Adaptación single-tenant:** este CRM es single-tenant, sin plan contratado ni costo por usuario
> adicional. Los permisos son **sólo por rol** (el usuario hereda los permisos de sus roles; no hay
> overrides por usuario), y el admin tiene **CRUD completo de roles** en vez de un catálogo de roles
> fijo. El rol Admin pasa cualquier permiso (`Gate::before`). Ver `docs/modelo_datos.md §1` para el
> modelo de `roles`/`permisos`/pivots.
>
> **Actualización (spec 039, 03/08/2026):** "Mi Perfil" ya está implementada — pantalla en
> Configuración & Ajustes para cargar los datos fiscales del propio negocio (Razón Social, CUIT,
> Domicilio Fiscal, Condición de IVA, Ingresos Brutos opcional) y su logo, modal Bootstrap + AJAX
> (`app/Http/Controllers/MiPerfilController.php`, tabla `datos_empresa` de fila única). Estos datos
> se muestran como encabezado emisor en los PDFs de Venta (spec 034) y de Notas de Crédito/Débito
> (spec 039, ver más abajo). **Brecha de relevamiento**: no existe informe con capturas reales de
> Contagram para esta pantalla — se construyó siguiendo el patrón visual ya usado en el resto de
> Configuración & Ajustes, pendiente de contrastar contra capturas reales si se relevan más
> adelante. "Mi Plan" sigue sin implementar (no aplica a este CRM single-tenant, sin costo por
> plan). "Funciones Avanzadas" **sí** está implementada (spec 011, ver §5.1). "Importar
> Datos" ya está implementado, pero como pantalla propia de Base de Datos (§2.4, spec 006) —
> Contagram real la expone también desde Configuración & Ajustes, alcance no replicado en este CRM.

*Fuente(s): [Configuración & Ajustes](https://help.contagram.com/es/collections/83659-configuracion-ajustes)*

### 5.1 Funciones Avanzadas (spec 011)

Pantalla que calca la de Contagram (`docs/informe_contagram_funciones_avanzadas.md` §1, captura
`[103]`): lista vertical de tarjetas, cada una con ícono, nombre, descripción de una línea y
toggle Sí/No. Orden relevado (se respeta):

| # | Función | ¿Construida en este CRM? | Configuración propia |
|---|---|---|---|
| 1 | Facturación electrónica | No | — |
| 2 | **Mercado Libre** | **Sí** (spec 011) | §5.2 |
| 3 | **Tiendanube** | **Sí** (spec 015 conexión + spec 017 ventas) | §5.3 |
| 4 | Reportes por email | No | — |
| 5 | Abonos | Sí (spec 008) | — |
| 6 | IA | No (fuera de alcance) | — |
| 7 | Retenciones | Sí (spec 009) | — |
| 9 | Depósitos | Sí (spec 005) | ABM de Depósitos |
| 10 | Lector de código de barras | No | — |

> **Excepción al principio rector**: la función #8 "Ventas sin stock" relevada en Contagram real se
> quitó del seeder/pantalla en este CRM (2026-08-02) porque el toggle no estaba linkeado a ninguna
> lógica de negocio (no bloqueaba ni permitía nada) — un switch decorativo genera falsa expectativa
> de control. La venta/compra sin stock en este CRM se resuelve directamente en los módulos de
> Ventas/Compras, sin gate de Funciones Avanzadas.

Las funciones aún no construidas **se listan igual**, deshabilitadas e identificadas como no
disponibles, para preservar la estructura de la pantalla original (principio rector de fidelidad
estructural). El estado del toggle se persiste por función, junto con quién lo cambió y cuándo.
Permiso requerido: `configuracion.funciones` (el mismo que ya protege Depósitos).

### 5.2 Integración con Mercado Libre (specs 011, 012, 013 y 016) — **divergencia deliberada respecto de Contagram**

> ⚠️ **Ésta es la única parte del CRM que NO calca a Contagram, y la divergencia es intencional.**
>
> **Qué hace Contagram**: según el relevamiento con capturas reales (`informe_contagram_funciones_avanzadas.md`
> §3), Contagram resuelve la integración con un asistente de **2 pasos** ("Solicitar Acceso" → "Acceso
> Permitido") sobre una aplicación de Mercado Libre **propia de Contagram**. El negocio sólo autoriza y
> obtiene capacidades de **lectura**.
>
> **Qué hace este CRM**: **aplicación propia del negocio** creada en el DevCenter de Mercado Libre, con
> OAuth 2.0 y **permisos funcionales de lectura y escritura**, lo que habilita modificar stock, precios
> y publicaciones desde el CRM — algo que el acceso básico de Contagram no permite.
>
> ⚠️ **Los permisos no se piden por la API**: se configuran como *permisos funcionales* en la aplicación
> del DevCenter, por área (Usuarios, Publicación y sincronización, Ventas y envíos, Comunicación pre y
> postventa, …), cada una con alcance "sólo lectura" o "lectura y escritura". El CRM no puede
> otorgárselos a sí mismo, por lo que la pantalla de configuración le indica al usuario cuáles habilitar.
> Verificado contra la documentación oficial el 27/07/2026.
>
> **Por qué**: decisión explícita del usuario (27/07/2026). El objetivo del negocio es operar Mercado
> Libre *desde* el CRM, no sólo consultarlo. El acceso básico de Contagram no alcanza para eso.
>
> **Alcance de la divergencia**: está acotada al contenido de la tarjeta "Mercado Libre". La pantalla
> contenedora (Funciones Avanzadas, §5.1) sigue calcando a Contagram.

**Alcance implementado en spec 011 (etapa 1 — sólo conexión)**:

- Carga de las credenciales de la aplicación del DevCenter (App ID + clave secreta, cifrada) y sitio de
  operación (MLA por defecto).
- Vinculación por OAuth 2.0 (authorization code, **sin PKCE**), con protección antifalsificación de un
  solo uso y vencimiento.
- Panel de estado con los datos reales de la cuenta vinculada (apodo, identificador, correo, tipo de
  cuenta, sitio, fecha de vinculación, vencimiento del acceso, último renovado).
- Renovación automática y transparente del acceso. **Regla crítica**: el token de renovación de Mercado
  Libre es de **un solo uso** — cada renovación devuelve uno nuevo que reemplaza al anterior, y dos
  renovaciones concurrentes rompen la cadena y obligan a re-autorizar. Por eso la renovación se hace
  bajo exclusión mutua.
- Probar conexión / Desconectar.
- **Reemplazo de cuenta**: autorizar con una cuenta de Mercado Libre distinta de la ya vinculada no la
  reemplaza directamente — pide confirmación explícita (mostrando ambas cuentas lado a lado) mientras la
  cuenta vigente sigue operando con normalidad. Evita reemplazar por error la cuenta real del negocio.
- **Modo sólo lectura** (kill-switch): bloquea toda escritura hacia Mercado Libre, registrándola en vez
  de ejecutarla. Permite apuntar a la cuenta real del negocio sin riesgo de modificar publicaciones
  verdaderas durante el desarrollo.
- Historial consultable de operaciones contra la API, sin datos sensibles.

**Fuera de alcance de la etapa 1** (specs posteriores): importación de publicaciones, matcheo con
productos del CRM, sincronización de stock y precios, ingreso de ventas/órdenes al CRM, preguntas,
mensajería, envíos y webhooks de negocio.

**Etapa 2 — Ventas de Mercado Libre (spec 012, implementada)**: sincronización de órdenes hacia el CRM,
vinculación publicación↔producto (`ml_publicacion_producto`, 1:1), conversión manual o automática a
Venta del CRM con cobranza y descuento de stock. Ver §3.2.bis.

**Etapa 3 — Sincronización de stock hacia Mercado Libre (spec 013, implementada)**: contraparte inversa
de la etapa 2 — un movimiento de stock local de un producto vinculado (Venta manual, ajuste,
transferencia) marca el vínculo como pendiente y la corrida programada empuja la cantidad disponible
consolidada hacia la publicación de Mercado Libre, sin rebotar sobre los movimientos que ya vinieron de
una orden de Mercado Libre. **Cierra el riesgo de sobreventa** de la etapa 2. Reutiliza toda la
infraestructura ya construida (vinculación 1:1, depósito configurado, cliente de API, modo sólo lectura
e historial de operaciones) y no agrega pantallas propias. Detalle en §3.2.ter; ver
`specs/013-stock-mercadolibre/` y `docs/modelo_datos.md §10`.

**Etapa 4 — Gestión de precios hacia Mercado Libre desde una Lista de Precios (spec 016, implementada)**:
agrega a la configuración de Mercado Libre una Lista de Precios opcional que, a
diferencia del Depósito y la Categoría de Venta (etapa 2), no clasifica nada — es la lista que el negocio
usa como fuente de los precios que Mercado Libre debe mostrar. Cuando el precio de un producto
**vinculado** cambia dentro de esa lista (modal de Producto o importación masiva), el CRM sincroniza el
nuevo precio hacia la publicación correspondiente **en el momento del cambio** (disparo por evento, sin
cron ni corrida programada — a diferencia de la etapa 3, que sí usa una corrida programada para stock).
Incluye una acción manual "Sincronizar precios ahora" (reintento de fallas y respaldo para productos
vinculados después de un cambio de precio) y push inmediato de todos los vínculos vigentes al cambiar
cuál es la lista configurada. **No es fuente de precio de Ventas**: el precio de las líneas de las Ventas
creadas al convertir una orden sigue derivándose exclusivamente del importe pagado en Mercado Libre, tal
como en las etapas 2 y 3; esas Ventas tampoco quedan etiquetadas con esta Lista de Precios. Ver §3.2.bis;
`specs/016-lista-precio-mercadolibre/`.

**Etapa 5 — Vinculación automática por SKU (spec 021, implementada; corregida por spec 023)**: reemplaza el
alta manual de la vinculación publicación↔producto (§3.2.bis) por un botón que la resuelve sola,
comparando el SKU del vendedor contra el `id` del producto en el CRM — sin campo nuevo, sin migración de
esquema. El diseño original (spec 021) resolvía el SKU contra órdenes ya sincronizadas; se corrigió (spec
023) para resolverlo contra el **catálogo en vivo** de Mercado Libre (recorrido completo del vendedor
conectado vía el modo `scan` del buscador, sin el tope de 1000 resultados del paginado clásico —
necesario porque el catálogo real del negocio tiene miles de publicaciones, no las decenas asumidas
originalmente), porque el mecanismo basado en órdenes no podía vincular publicaciones que nunca vendieron
ni reflejaba un SKU corregido después de la última sincronización. Ver §3.2.bis;
`specs/021-vinculacion-automatica-sku/`; `specs/023-mercadolibre-catalogo-vivo/`.

**Sigue fuera de alcance** (etapas 2, 3, 4 y 5 combinadas): sincronización de título, descripción,
imágenes o estado (pausar/activar) de la publicación; comisión de Mercado Libre y costo de envío;
importación masiva de publicaciones (catálogo); preguntas, mensajería y webhooks de negocio.

**Restricciones de infraestructura** (aplican a todo el módulo): requiere que el CRM esté publicado en
una dirección pública con conexión segura — Mercado Libre no admite direcciones locales ni sin cifrar,
por lo que el flujo OAuth no puede completarse en desarrollo local. El módulo se diseñó para correr
igual en hosting compartido (sin procesos permanentes) y en VPS, cambiando sólo variables de entorno.

### 5.3 Integración con Tiendanube (specs 015→019, 017, 018) — **divergencia deliberada respecto de Contagram**

> ⚠️ **Segunda parte del CRM que NO calca a Contagram, por el mismo motivo que Mercado Libre (§5.2).**
>
> **Qué releva Contagram** (`informe_contagram_funciones_avanzadas.md` §4, captura `[104]`): al activar
> la tarjeta "Tiendanube" se ve un indicador parcial de **4 pasos** — Solicitar Acceso → Acceso
> Permitido → Importar → Sincronizar — sugiriendo una aplicación propia de Tiendanube (partner/pública)
> con flujo de autorización, seguido de importación de catálogo. El relevamiento no pudo completarse
> (requería upgrade de cuenta) y no se encontraron artículos públicos del centro de ayuda de Contagram
> sobre Tiendanube.
>
> **Qué hace este CRM (spec 019, corrige a spec 015)**: OAuth 2.1 con auto-registro de cliente (Dynamic
> Client Registration, RFC 7591) contra el servidor MCP oficial de Tiendanube
> (`admin-mcp.tiendanube.com`, la app "AdminMCP"), con protocolo JSON-RPC 2.0 sobre HTTP para las
> operaciones. **Spec 015 (modelo de Aplicación personalizada con token cargado a mano) se implementó,
> se testeó y se deployó, pero quedó inutilizable**: ese modelo requiere plan Tiendanube Escala o
> Evolución, y la tienda real del cliente tiene un plan inferior (confirmado por soporte de Tiendanube y
> por la ausencia de la opción "Aplicaciones a medida" en su panel). Spec 019 corrige el mecanismo de
> conexión sin reabrir el resto de decisiones ya tomadas en 015.
>
> **Por qué OAuth y no Aplicación personalizada**: no es una preferencia de diseño, es la única vía que
> funciona con el plan real de la tienda — verificado empíricamente (sesión 29/07/2026) con un cliente
> standalone sin ningún LLM de por medio: auto-registro sin login, autorización con PKCE, token de larga
> duración (~1 año, sin `refresh_token` en la práctica).
>
> **Alcance de la divergencia**: acotada al contenido de la tarjeta "Tiendanube". La pantalla contenedora
> (Funciones Avanzadas, §5.1) sigue calcando a Contagram.

**Alcance implementado en spec 019 (etapa 1 — conexión, corrige a spec 015)**:

- Conexión por OAuth 2.1: botón "Conectar con Tiendanube", sin ningún dato que cargar a mano (ni
  identificador de tienda ni token) — la aprobación en el navegador de Tiendanube determina la tienda.
- Verificación de que el token funciona de verdad invocando `list_products` inmediatamente después de
  conectar (el servidor MCP no tiene una tool de "info de tienda"): el panel de estado muestra la
  cantidad de productos del catálogo como confirmación, no nombre/dominio/moneda de tienda (esos datos
  ya no están disponibles con este mecanismo).
- Desconectar (conserva el cliente OAuth registrado, para no tener que auto-registrar de nuevo al
  reconectar) — sin acción "Probar conexión" separada: la verificación ocurre una sola vez, dentro del
  propio callback de conexión.
- **Sin ciclo de renovación**: igual que spec 015 asumía, el token no vence en la práctica. El único
  evento a manejar es la revocación manual desde el panel de Tiendanube, detectada en la siguiente
  llamada al servidor MCP y reflejada como conexión "Caída" — a diferencia de spec 015, acá no hay
  "recargar sólo el token": hay que rehacer el flujo de conexión completo.
- **Modo sólo lectura** (kill-switch) e historial de operaciones — reutilizados sin cambios de
  comportamiento desde spec 015, tablas propias (`tn_configuracion`, `tn_operaciones_log`).
- **Sin gestión de webhooks**: el servidor MCP no los expone (research.md de spec 019) — cualquier
  sincronización futura en tiempo real deberá resolverse por *polling*.

> ⚠️ **Hallazgo post-deploy (29/07/2026): el botón "Conectar con Tiendanube" no sirve para reconectar
> contra la cuenta real.** `admin-mcp.tiendanube.com` `/authorize` sólo acepta `redirect_uri` tipo
> *loopback* (`localhost`/`127.0.0.1`) — rechaza con 400 "does not match allowed patterns" cualquier
> dominio HTTPS de terceros, aunque `/register` (DCR) lo haya aceptado sin objeción al registrar el
> cliente. Verificado empíricamente contra la cuenta real (ver memoria de proyecto). La conexión
> **está establecida en producción** (hecha con `scripts/tiendanube_oauth_bootstrap.php`, corrido a
> mano en local por un desarrollador con aprobación real del usuario, y escrita en `tn_configuracion`
> vía `deploy.py --tinker`) pero **toda reconexión futura requiere ese mismo procedimiento manual** —
> no hay forma de que el usuario del negocio la rehaga solo desde el navegador. **✅ Resuelto en la UI
> (29/07/2026)**: el botón "Conectar con Tiendanube" queda deshabilitado con un aviso explicando que
> hace falta soporte técnico, en vez de prometer un flujo self-service que no puede completar. El panel
> también muestra los días restantes de vigencia del token (`tn_configuracion.token_expira_en`, ~1 año,
> sin alerta proactiva por mail todavía — sólo visual en el panel).

**Fuera de alcance de la etapa 1** (specs 017 y 018, continuación directa — mismo patrón que 011→012→013
de Mercado Libre): listado de órdenes de venta de Tiendanube, vinculación de productos con publicaciones
existentes, conversión de órdenes en Venta del CRM, sincronización de stock del CRM hacia Tiendanube,
importación masiva de catálogo.

**Sin restricción de infraestructura pública**: la conexión no requiere que el CRM esté publicado más
allá de tener una URL de retorno (`redirect_uri`) pública y con HTTPS para el flujo OAuth — mismo
requisito que ya cumple Mercado Libre en `contagramdemo.devstudioweb.com`.

**Etapa 2 — Ventas de Tiendanube (spec 017, implementada)**: listado de órdenes, vinculación
variante↔producto, conversión manual/automática a Venta del CRM con cobranza y descuento de stock —
mismo alcance funcional que la etapa 2 de Mercado Libre (§5.2), adaptado a que Tiendanube expone tres
campos de estado en vez de uno, no informa condición de IVA, y admite múltiples medios de pago. Excluye
por completo las órdenes del canal Mercado Libre integrado a Tiendanube (`storefront = "meli"`), para no
duplicar lo que ya cubre la integración directa de Mercado Libre. Ver §3.2.quater;
`specs/017-ventas-tiendanube/`.

**Etapa 3 — Stock y precios hacia Tiendanube (spec 018, especificada — lista para implementar, ampliada
30/07/2026)**: cierra el riesgo de sobreventa que la etapa 2 dejó documentado, empujando el stock del CRM
hacia la variante vinculada de Tiendanube, mismo patrón que la spec 013 aplicó sobre la 012 para Mercado
Libre. Agrega `tn_product_id` a la vinculación variante↔producto (la API de Tiendanube exige el producto,
no sólo la variante, para actualizar stock). **Ampliación**: agrega también la gestión de precios
(`tn_configuracion.lista_precio_id`), mismo patrón que la spec 016 para Mercado Libre — disparo por
evento, sin cron, botón en Productos. Ver §3.2.quinquies; `specs/018-stock-tiendanube/`.

**Etapa 4 — Importación de vinculaciones desde el export nativo (spec 021, implementada)**: agrega a la
pantalla de vinculación (§3.2.quater) la posibilidad de subir el archivo de productos que Tiendanube ya
permite exportar (sin plantilla propia) para crear vinculaciones en lote — el alta manual con selector
sigue intacta. La integración conectada (MCP oficial) no expone el SKU de ningún producto, así que el
SKU sólo sale de ese archivo; el `codigo` del producto en el CRM lo matchea (confirmado 98.8% sobre
datos reales), y los ids reales de Tiendanube se resuelven consultando el catálogo en vivo por el
"Identificador de URL" de cada fila (confirmado 100% sobre datos reales) — sin depender de que el
producto haya vendido antes. Ver §3.2.quater; `specs/021-vinculacion-automatica-sku/`.

> ✅ **Actualización (spec 017, 30/07/2026): implementada.** Listado, sincronización (`SincronizadorOrdenes`),
> vinculación variante↔producto (`TiendanubeVarianteProducto`), conversión manual y automática
> (`ConversorOrdenAVenta`), configuración de ventas y comando programado (`tiendanube:sincronizar-ordenes`)
> construidos sobre la conexión OAuth/MCP de la spec 019 (no sobre la 015, ya reemplazada). Suite de tests
> propia en `tests/Feature/Integraciones/Tiendanube*Test.php`, en verde junto con la regresión de las
> specs 011-013/015/019. **La spec 018 (Etapa 3, stock hacia Tiendanube) sigue sólo especificada, todavía
> no implementada** — es la continuación directa: agrega `tn_product_id` a `tn_variante_producto` (ya
> soportado en el esquema de la 017) y extiende `TiendanubeVarianteProducto`/`TiendanubeConfiguracion` sin
> tocar lo que la 017 ya dejó funcionando.

**Etapa 5 — Conexión vía Application REST del Partner Portal (spec 022, especificada — lista para
implementar)**: agrega, en un apartado nuevo y aislado dentro de la misma pantalla de configuración de
Tiendanube, una **segunda conexión OAuth**, esta vez contra una Application clásica registrada en
`partners.tiendanube.com` (App ID 38015, "pompei") en vez del servidor MCP. Motivación directa: el
"Hallazgo post-deploy" documentado arriba (29/07/2026) — la conexión MCP no admite reconexión self-service
por la restricción de `redirect_uri` tipo *loopback*. Registrarse como Partner y crear una Application ahí
sortea esa restricción (no exige el plan Tiendanube Escala/Evolución que sí bloqueaba el modelo de
Aplicación personalizada de spec 015).

**Verificado empíricamente (sesión 31/07/2026)**: el token de esta Application **no** es intercambiable con
el del servidor MCP (401 `invalid_token` al probarlo cruzado) — son sistemas de autenticación separados, con
audiencias de token distintas. Sí funciona contra la REST API estándar (`api.tiendanube.com`, 200 OK con
datos reales del catálogo). Por eso spec 022 es deliberadamente chica: **sólo conecta y verifica** (`GET
/{store_id}/store`), en tablas propias (`tn_conexion_rest`, `tn_rest_operaciones_log`), sin tocar
`ClienteTiendanube` ni ningún flujo de negocio de las etapas 2-4 (specs 017/018/021), que siguen
funcionando exactamente igual sobre la conexión MCP. Si esta conexión se valida en producción, una spec
futura evaluará migrar el resto de la integración (y decidirá ahí si conviene sumar webhooks reales de
Tiendanube, que la REST API sí soporta a diferencia del MCP). Ver `specs/022-tiendanube-conexion-rest/`.

**Etapa 6 — Migración completa del MCP a la Application REST (spec 024, implementada — Historias 1 y 2;
Historia 3 pendiente de validación en producción)**: la conexión REST validada en la etapa 5 deja de ser
"sólo conexión" y pasa a ser el transporte real de negocio. `ClienteTiendanubeRest`
(generalización de `VerificadorConexionRest`) reemplaza a `ClienteTiendanube` (MCP) como dependencia de
`SincronizadorOrdenes`, `SincronizadorStock` y `SincronizadorPrecios` — mismo comportamiento observable
(mismos cortes, mismo cronjob cada minuto, mismo criterio de creación automática de Venta), salvo que
`SincronizadorStock` deja de lotear (la REST API clásica no tiene equivalente al batch
`update_stock_and_price` del MCP) y pasa a enviar una `PUT /products/{id}/variants/{id}` por vínculo
pendiente. La vinculación de productos se reescribe por completo: `VinculadorAutomatico` (mismo patrón que
Mercado Libre, spec 023) reemplaza tanto al selector manual (fuente: `tn_orden_items`) como a la
importación por Excel de la etapa 4 (spec 021, retirada) — recorre el catálogo REST en vivo (`GET
/products`, paginado) y compara `variants[].sku` directo contra `Producto.id`, sin necesitar que la
variante haya vendido nunca ni depender de ningún archivo exportado a mano. La configuración de negocio
que vivía mezclada con las credenciales MCP en `tn_configuracion` (depósito, categoría, cuenta, lista de
precios, vendedor, modo sólo lectura, ventana de sincronización) se migra a `tn_conexion_rest`. **Historia
3 (retiro completo del MCP: `ClienteTiendanube`, `TiendanubeOAuthController`, tabla `tn_configuracion` y su
historial, apartado MCP de esta pantalla) queda condicionada a una confirmación manual explícita de que las
Historias 1 y 2 funcionan correctamente en producción** — no es un paso automático del mismo despliegue.
Ver `specs/024-tiendanube-migracion-rest/`.

**Corrección (14/08/2026 — datos fiscales del comprador de Mercado Libre):** la respuesta de
`GET /orders/billing-info/{SITE_ID}/{ID}` trae los datos del comprador **anidados bajo
`buyer.billing_info`** (`identification.type`/`identification.number`,
`taxes.taxpayer_type.description`, `name`, `address.*`), **no en la raíz**. `TraductorOrdenes` los
leía de la raíz, así que devolvía `null` en los tres campos **sin producir ningún error** (los `??
null` absorbían el fallo). Consecuencia: el Cliente creado desde una orden de ML quedaba **sin CUIT,
sin condición de IVA y sin razón social**, y como `DerivadorComprobante` deriva el tipo de comprobante
de la condición de IVA, **toda venta de Mercado Libre se emitía como Factura B**, incluso cuando el
comprador era Responsable Inscripto y ML indicaba explícitamente `seller.invoice_type: "Factura A"`.
Caso real: orden 2000017931860790 → Venta 24489 (CUIT 20186597142, IVA Responsable Inscripto,
`vat_discriminated_billing: True`, emitida como B). El test que cubría esta traducción **no detectó el
defecto porque construía la respuesta a mano en formato plano**, distinto del que devuelve la API real;
desde esta corrección el test usa la respuesta real capturada de producción. Se agregó además
`TraductorOrdenes::traducirDomicilioFiscal()` para aprovechar razón social y domicilio fiscal, que ML
también devuelve y antes se descartaban.

**Agregado (14/08/2026 — bloque "Precios de Mercado Libre" en el Detalle de Venta):** cuando la Venta
vino de una orden de ML con descuento, el Detalle muestra la cascada completa de precios, que explica
por qué el total de la Venta **no coincide con el precio publicado**:

```
Precio de lista de la publicación   (ml_orden_items.precio_bruto)
− Descuento aportado por el vendedor
= Precio registrado en esta Venta   (ml_orden_items.precio_unitario)  ← es el importe que se factura
− Descuento aportado por ML (cupón) (payload.payments[].coupon_amount)
= Precio que pagó el comprador
```

…más, en paralelo, el neto del vendedor (precio registrado − comisión de ML) y el total financiado al
comprador con su cantidad de cuotas. **Todo sale de datos ya sincronizados** (`ml_orden_items` + el
`payload` crudo de la orden): no se llama a la API, así que funciona retroactivamente para las órdenes
viejas. **El nombre y los porcentajes de la promoción NO se muestran a propósito**: viven en el ítem
(`/seller-promotions`), no en la orden, y ese endpoint devuelve la promoción **vigente hoy**, que para
una venta pasada puede ser otra o ninguna — sólo se muestran importes, que sí quedan congelados en el
payload. **Decisión explícita: esto NO va al PDF de la Venta**, que es un comprobante fiscal — el
descuento de ML no forma parte de la base imponible ni del ingreso del vendedor, y exponer tres precios
distintos en una factura invita a un reclamo del comprador.

**Agregado (14/08/2026 — Detalle de Venta):** el encabezado muestra el **Id de la Venta** a la
izquierda, alineado con los botones de Remito/ARCA, y el bloque de datos generales muestra el
**Depósito** del que salió el stock (derivado de los movimientos de stock de tipo `salida` de la Venta;
si no hubo movimiento, se informa explícitamente en vez de dejar el campo vacío).

**Corrección asociada (14/08/2026 — el documento del comprador se guarda cualquiera sea su tipo):**
`ResolutorCliente` guardaba el número de documento en `clientes.cuit` **sólo si era CUIT**, de modo que
un comprador con DNI quedaba con `tipo_documento = 'DNI'` pero **sin número**, y su Venta se enviaba a
ARCA como Consumidor Final sin identificar (DocTipo 99) pese a que Mercado Libre sí había informado el
DNI. Ahora se guarda el documento sea CUIT, DNI, CUIL, Pasaporte o CDI —coherente con el modelo de
datos, donde `clientes.cuit` es la columna única de documento y `tipo_documento` dice qué es—. Como esa
columna tiene índice único, si el número ya pertenece a otro Cliente **no se asigna y se sigue
adelante**: una colisión de documento no puede hacer fallar el alta de la Venta por un dato secundario.
Se sigue respetando la regla de no pisar datos cargados a mano. **Pendiente al 14/08/2026: relevar y corregir las ventas de ML
ya convertidas con el tipo de comprobante y los datos fiscales equivocados** (ver §7).

*Fuente(s): `docs/informe_contagram_funciones_avanzadas.md` §3; documentación oficial de Mercado Libre
Developers; `admin-mcp.tiendanube.com` (observado empíricamente, sin doc pública — ver
`specs/019-tiendanube-conexion-mcp/research.md`); documentación pública de Tiendanube
(`tiendanube.github.io/api-documentation`) y verificación empírica propia para la REST API clásica (ver
`specs/022-tiendanube-conexion-rest/research.md`, `specs/024-tiendanube-migracion-rest/research.md`);
`specs/011-mercadolibre-conexion-oauth/`, `specs/015-tiendanube-conexion/`,
`specs/019-tiendanube-conexion-mcp/`, `specs/017-ventas-tiendanube/`, `specs/018-stock-tiendanube/`,
`specs/021-vinculacion-automatica-sku/`, `specs/022-tiendanube-conexion-rest/`,
`specs/023-mercadolibre-catalogo-vivo/`, `specs/024-tiendanube-migracion-rest/`*

---

## 5.1 Monitoreo (pantalla propia, spec 073 — 21/08/2026)

Pantalla de salud operativa del negocio y de las integraciones. **No forma parte del Contagram real**:
es una pantalla propia de este CRM, nacida como URL interna sin link durante la migración de datos y
convertida en pantalla del producto por la spec 073 a pedido del negocio. Por eso no aplica acá el
principio de fidelidad estructural a Contagram — no hay pantalla original que calcar.

**Acceso**: link e indicador en la **barra superior** (no en el sidebar), con permisos propios
`monitoreo.ver` (pantalla, indicador y notificaciones) y `monitoreo.gestionar` (acciones de escritura).
Al inicio ambos van sólo al rol Admin.

**Bloques de la pantalla**:

| Bloque | Qué muestra |
|---|---|
| Pulso | Estado de las 2 sincronizaciones de ML (órdenes y stock): hace cuánto corrieron, resultado, y alerta si superan 15 min o nunca corrieron. Interruptores `modo_solo_lectura` y `creacion_automatica` |
| Publicaciones que no actualizan stock | Publicaciones de ML con error al empujar stock: item, título, stock real vs. último publicado, intentos, desde cuándo, error. Distingue los errores de **moderación** de ML (`under_review`, `forbidden`), donde no hay acción posible desde el CRM |
| A reponer | Todo el catálogo con stock en **Local** ≤ su punto de reposición → comprarle al proveedor o traer de Full |
| Riesgo de stock publicable | Productos **publicados en ML** con stock **Local + Full** ≤ su punto de reposición, ordenados por urgencia real (velocidad de venta de los últimos 14 días). Lo que lo distingue del bloque anterior es que suma Full |
| Sin stock | Productos publicados en ML sin stock ni en el depósito de ML ni en Full. Informativo: no vende, pero no es una falla. **No** depende del punto de reposición |
| Órdenes sin venta | Órdenes de ML que no generaron Venta, con el motivo en castellano. Lo accionable es `requiere_atencion`; canceladas y pendientes de pago son el curso normal |
| Últimas ventas de integraciones | Las 6 últimas, con sus movimientos de stock, para ver la cadena de punta a punta |

**Acciones** (requieren `monitoreo.gestionar`): destrabar una publicación (encolarla para el próximo
empuje), reactivar una bloqueada por reintentos fallidos, forzar una sincronización, y editar el punto
de reposición de un producto sin salir de la pantalla (no exige `productos.editar`).

**Notificaciones** (campanita de la barra superior): productos en punto de reposición y publicaciones
de ML que fallan. **No hay tabla de histórico** — se calculan sobre el estado vigente en cada consulta
y sólo se persiste el "leído" por usuario (`notificaciones_leidas`). La marca de lectura **se borra en
cuanto el problema se resuelve**, así que si el problema reaparece la notificación nace de nuevo como
**no leída** en vez de quedar silenciada para siempre. Refresco: al cargar cada pantalla y cada 5
minutos con la pestaña abierta.

*(Referencias: `specs/073-monitoreo-punto-reposicion/`)*

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
   Reactivar** / Eliminar / **Cta Cte** (agregado 01/08/2026, ver nota abajo) — seguimos con el toggle
   de estado que Contagram no expone en ese menú (regla de negocio razonable, no eliminar clientes con
   historial) pero diverge de la UX relevada; queda como decisión de producto a confirmar, no como bug.

**"Cta Cte" en el menú de fila de Clientes — cerrado 01/08/2026**: dependía de Ventas/Tesorería/
Cuenta Corriente Clientes, ninguno implementado al momento del relevamiento original. Con spec 029
(Cuenta Corriente Clientes, §6.4) ya construida, se agregó el ítem: navega a
`informes/cuenta-corriente?cliente_id=X`, que abre directo en el tab "Movimientos" con el filtro
Cliente preseleccionado (detalle accionable de ese cliente puntual, no el agregado de Saldos
Clientes). Gateado por el mismo permiso `informes.ver` que el ítem del sidebar. La brecha de
selección múltiple + "Acciones Masivas" en Productos se cerró en spec 004 (ver §2.2).

### 6.2 Informe de Stock (implementado, spec 003)

La acción de fila **"Movimientos"** en Productos ya no abre un modal (el modal de Producto queda sólo
para el form de Ajuste de Stock); navega a una **pantalla propia** (`/informes/stock`, fiel a
`informe_contagram_base_de_datos.md` §4.9), con `?producto_id=` pre-cargando el filtro "Productos".

- **Filtros**: Usuario, Operación (`Ajuste`/`Transferencia`, más **`Salida`/`Entrada` por Ventas desde
  la spec 012** — ver nota abajo), Proveedor, Tipo de Producto, Productos (buscador), Estado del
  Producto/Servicio, rango de fechas.

  > **Corrección (spec 012, 27/07/2026)**: este documento afirmaba que las operaciones `Entrada`/`Salida`
  > "no existen todavía". Era exacto hasta la spec 011: se verificó en código que `VentaController` y
  > `CompraController` **no generaban ningún movimiento de stock**, pese a que `StockService` ya exponía
  > `registrarSalida()`/`registrarEntrada()` sin consumidores. La spec 012 cierra esa brecha para
  > **Ventas** (manuales y de Mercado Libre), porque sin movimiento de stock local la sincronización
  > hacia Mercado Libre (spec 013) no tendría nada que propagar y el riesgo de sobreventa sería
  > insoluble. La spec 030 (01/08/2026) cierra la brecha simétrica para **Compras**: ver §3.1 más abajo.
- **KPIs** (recalculados según los filtros de producto vigentes): Unidades en Stock, Costo Total, Valor
  Venta Total — misma fórmula que los KPIs en $ de Productos (§2.2).
- **Tabla**: Fecha, Operación, Detalle, Producto, Cantidad, **Stock Saldo** (saldo corrido por
  producto+depósito, calculado sobre el histórico completo de `movimientos_stock` vía función de
  ventana SQL — los filtros de pantalla nunca alteran ese cálculo, sólo qué filas se muestran).
  Ordenada por defecto por **fecha y hora ascendente** (spec 051, 06/08/2026) — antes ordenaba sólo
  por fecha (sin hora), lo que no distinguía el orden real entre varios movimientos del mismo día.
- Es de **sólo lectura**: no edita ni elimina movimientos desde ahí. El alta de movimientos sigue
  siendo "Ajuste de Stock" (Aumentar/Disminuir/Transferencia) desde Productos, sin cambios.

  > **Detalle enriquecido para movimientos de Venta (spec 051, 06/08/2026)**: cuando el movimiento
  > tiene como origen una Venta (manual, Mercado Libre o Tiendanube), la columna "Detalle" muestra
  > `"{tipo de comprobante} {número de comprobante} - {cliente}"` (o sin el segmento de cliente si
  > la venta no tiene uno asignado), en vez de la descripción libre genérica. Para movimientos de
  > Compra, ajuste manual o transferencia, la columna sigue mostrando lo mismo que mostraba antes
  > (sin cambios de comportamiento).

---

### 6.3 Módulo Inicio / Dashboard (implementado — spec 010)

Pantalla de aterrizaje `/dashboard` (reemplaza a Clientes como página raíz `/`). Es **sólo lectura**:
agrega datos ya existentes de Ingresos (§3), Egresos (§4) y Tesorería (§3.7), sin crear/editar/eliminar
nada por su cuenta.

- **KPIs** (4 tarjetas, con variación % vs. el período anterior equivalente): Ventas Creadas, Venta
  Promedio, Cantidad de Ventas, Resultado (= Ventas + Otros Ingresos no pendientes − Compras − Gastos
  no pendientes). Variación `null` ("sin datos previos") cuando el período anterior valió cero.
  "Ventas Creadas"/"Resultado" y el equivalente de Compras usan el **monto neto de Notas de
  Crédito/Débito** (spec 046, ver más abajo); "Cantidad de Ventas" no se netea, sigue contando
  comprobantes emitidos.
- **Totales del período**: Ventas/Otros Ingresos/Compras/Gastos en barras de progreso proporcionales.
  Ventas y Compras ya vienen netas de NC/ND (spec 046).
- **Gráfico mensual**: barras apiladas de los últimos 12 meses (fijo, no depende del selector de
  período), con los 4 rubros anteriores; meses sin operaciones se muestran en cero, no se omiten.
  Ventas y Compras de cada mes ya vienen netas de NC/ND de ese mes (spec 046).
- **Selector de período**: Última Semana / **Hoy** / Mes Actual (default) / Mes Anterior / Año Actual
  (spec 046 agregó "Hoy", comparado contra "Ayer") — recalcula KPIs, Totales, Donas y Rankings.
  Tesorería y Cuentas a Cobrar/Pagar **no** se recalculan por período (siempre "a hoy"), para no
  repetir cómputo que no cambia con el filtro.
- **Neteo de Notas de Crédito/Débito en KPIs/Totales/Gráfico/Donas/Rankings (spec 046, revisión
  18/08/2026, y spec 079)**: a diferencia del aging de Cuentas a Cobrar/Pagar (que usa el saldo
  acumulado a hoy, sin acotar por período), estos cálculos restan el monto de NC y suman el de ND
  **por la fecha de emisión de cada nota**, no por la fecha de la Venta/Compra que ajustan — una NC
  emitida en agosto resta de "Ventas" de agosto aunque la venta que anula sea de julio. **Sin piso
  en $0**: una NC/ND puede dejar el neto de un período en negativo (se sacó el piso el 18/08/2026,
  verificado contra Contagram real — ver comentario de `DashboardController::montoNetoQuery()`).
  Sin techo superior para ND. **El Ranking de Clientes (por monto) y de Productos (por cantidad)
  del Dashboard también se netean con este mismo criterio (spec 079)** — a nivel de línea de
  producto para el Ranking de Productos (`nota_credito_debito_items.producto_id`/`cantidad`); una
  NC/ND sin ítems desglosados afecta el Ranking de Clientes pero no el de Productos. El Ranking de
  Clientes/Productos del módulo **Informes** (spec 069) es una pantalla distinta y no se netea por
  esta spec.
- **Resumen de Tesorería**: Total Disponible/Cajas/Bancos (reutiliza `Tesoreria::saldos()`, spec 007,
  sin lógica propia) + mini-tabla de últimos movimientos.
- **Cuentas a Cobrar / a Pagar con aging**: dos bloques (Ventas a Cobrar en verde, Compras a Pagar en
  rojo) con el monto total pendiente y un desglose por antigüedad de deuda (A Vencer, Vencido, 0-30,
  31-60, 61-90, +90 días). Este es un **cálculo mínimo nuevo** (`App\Services\Tesoreria\CuentaCorriente`,
  método `aging()`), construido sobre `Venta::aCobrar()`/`Compra::aPagar()` ya existentes — **no** son
  las pantallas completas de Cuenta Corriente por Cliente/Proveedor. La de **Clientes ya se implementó**
  (spec 029, ver §6.4, método `porCliente()` del mismo servicio); **Proveedores sigue en §7, pendiente**.
  Desde spec 031, ambos métodos (`aging()`/`porCliente()`) incorporan el `saldo_inicial`/
  `saldo_inicial_fecha` de Cliente/Proveedor al cálculo (sumado al bucket que corresponda según esa
  fecha; negativo = saldo a favor, resta del total) — antes ese campo se cargaba en la ficha pero no
  afectaba ningún cálculo de deuda.
- **Donas por categoría** (Ventas/Compras/Gastos) y **Rankings** (Clientes por monto vendido, Productos
  por cantidad vendida) dentro del período filtrado. Categoría inactiva o ausente se agrupa bajo
  "Sin categoría".
- **Filtrado por permiso (spec 070)**: `/dashboard` sigue siendo accesible para cualquier usuario
  autenticado (no requiere permiso propio), pero cada widget/rubro se oculta por completo —tanto en
  la vista como en la respuesta de los endpoints AJAX, sin exponer el dato aunque se llame el
  endpoint directamente— si al usuario le falta el permiso `.ver` correspondiente:
  - Ventas Creadas/Venta Promedio/Cantidad de Ventas, barra de Totales "Ventas", serie "Ventas" del
    gráfico mensual y dona "Ventas por Categoría": requieren `ventas.ver`.
  - Barra de Totales "Otros Ingresos" y su serie del gráfico mensual: `otros-ingresos.ver`.
  - KPI/Totales/gráfico/dona de "Compras": `compras.ver`.
  - KPI/Totales/gráfico/dona de "Gastos": `gastos.ver`.
  - KPI "Resultado": requiere los 4 permisos anteriores a la vez (`ventas.ver`, `otros-ingresos.ver`,
    `compras.ver`, `gastos.ver`) — al combinar los 4 rubros, un "Resultado" calculado con sólo
    algunos de ellos sería una cifra engañosa, así que directamente no se muestra.
  - Ranking de Clientes: `ventas.ver` **+** `clientes.ver`. Ranking de Productos: `ventas.ver` **+**
    `productos.ver`.
  - Resumen de Tesorería (saldos/movimientos) y Cuentas a Cobrar/Cuentas a Pagar: `tesoreria.ver`.
  - Un usuario sin ninguno de estos 7 permisos igual entra a `/dashboard` (200, sin redirección),
    con la pantalla prácticamente vacía de widgets. Admin ve siempre el Dashboard completo.

---

### 6.3bis Saldo a favor aplicable a nuevas Ventas/Compras (spec 072) — **divergencia deliberada respecto de Contagram**

> ⚠️ **Contagram NO tiene esta funcionalidad. La divergencia es intencional y fue aprobada por el
> dueño del negocio el 21/08/2026.**
>
> **Qué hace Contagram** (relevamiento del 20/08/2026 con capturas reales, ver
> `docs/informe_contagram_notas_credito_mayores/`): permite emitir Notas de Crédito por un monto
> **mayor** al del comprobante que ajustan, sin bloqueo ni advertencia, y el excedente queda como
> saldo a favor del cliente/proveedor en la cuenta corriente. Pero **no ofrece ninguna forma de
> aplicar ese crédito a un comprobante puntual**: el desplegable "Medio de Cobro" sólo lista cuentas
> de caja/banco/tarjetas, y el campo "Documento que Ajusta" del asistente de NC/ND nunca se puebla.
> Cada documento mantiene su propio "A Cobrar"/"A Pagar" independiente; el crédito sólo se ve en el
> neto acumulado de la cuenta corriente.
>
> **Por qué se diverge**: el procedimiento manual que este vacío obliga a usar **destruye datos**.
> Caso real verificado en producción (cliente FLORENCIA 1159751732, ventas 24582 y 24608, 20/08/2026):
> ante una devolución de $30.771,29 seguida de una compra de $27.306, el operador emitió la NC,
> **eliminó la cobranza** de la venta vieja y cargó una cobranza nueva por el importe menor. Las dos
> ventas quedaron en cero y los **$3.465,29 a favor del cliente desaparecieron de todo registro**. El
> cálculo del sistema era correcto: lo que falla es que el único camino disponible obliga a borrar la
> evidencia de que el cliente pagó.

**Qué agrega el CRM** (spec 072):

- **Aplicación de crédito**: el saldo a favor de un comprobante con Nota de Crédito se puede imputar
  a otro comprobante del mismo cliente/proveedor, desde el modal "Agregar Cobranza" (Ventas) o de
  Pago (Compras), eligiendo el medio **"Saldo a favor"**. Sólo aparece si hay crédito disponible.
- **El crédito se mide por el saldo a favor efectivo, no por el monto nominal de la NC**: una NC sobre
  un comprobante impago sólo cancela deuda y **no genera crédito**. Tomar el monto de la nota
  inventaría crédito inexistente.
- **La aplicación es una transferencia de saldo entre dos comprobantes**: baja el saldo a favor del
  origen y el saldo pendiente del destino por el mismo importe. El saldo de cuenta corriente del
  cliente queda **idéntico** antes y después. Sin esta regla habría doble conteo (el saldo a favor
  quedaría entero en el origen y además saldaría el destino).
- **Tesorería no se toca**: aplicar crédito **no** genera `movimientos_tesoreria` ni altera saldos de
  cajas/bancos ni el aging. No es plata que entra: es una imputación entre documentos. No existe ni
  debe existir una cuenta de tesorería "Saldo a favor".
- **Trazabilidad**: cada aplicación registra comprobante de origen, Nota de Crédito que la justifica,
  comprobante destino, importe, fecha y usuario. Es soft-delete; anularla devuelve el crédito al
  origen. No se puede eliminar una NC con crédito aplicado.
- **Saldo visible en el selector**: al crear una Venta/Compra, el selector muestra el saldo de cuenta
  corriente junto al nombre del cliente/proveedor (negativo = a favor). **Esto sí lo hace Contagram**
  (captura del 21/08/2026: `FLORENCIA 1159751732  $18.960,98`) y el CRM no lo tenía.
- **Se mantiene** la posibilidad de emitir NC mayores al comprobante: es el mecanismo que genera los
  saldos a favor, y coincide con Contagram.

**Supuesto operativo**: el crédito existe porque el comprobante quedó con saldo a favor, lo que
requiere que el pago del cliente **siga registrado**. Hay que instruir al local para que deje de
eliminar la cobranza vieja: con esta feature ya no hace falta, y borrarla es justamente lo que hace
desaparecer el crédito.

---

### 6.4 Módulo Informe de Cuenta Corriente — Clientes (implementado — spec 029)

Pantalla propia de **sólo lectura** `/informes/cuenta-corriente` (entrada "Cuenta Corriente" en el
submenú Informes del sidebar, junto a "Stock"), con **dos tabs Bootstrap sobre un único shell** (un
solo link de menú → una sola ruta, ver `no-hash-urls-para-navegacion` en memoria de proyecto):

- **Tab "Saldos Clientes"** (activo por defecto): tabla con aging por cliente — columnas Cliente,
  A Vencer, 0 y 30, 31 y 60, 61 y 90, >90, Total. Filtro: Cliente (Select2 buscador). Ordenable por
  Total. Excluye clientes sin saldo pendiente. Es una extensión de
  `App\Services\Tesoreria\CuentaCorriente` (el mismo servicio del Dashboard, §6.3): el método nuevo
  `porCliente('cliente')` reutiliza exactamente el bucketing de `aging()` pero acumulando por
  `cliente_id` en vez de en un único total — por eso el Total General de esta pantalla **coincide
  exacto** con el bloque "Cuentas a Cobrar" del Dashboard (misma fuente de cálculo, incluido el saldo
  inicial desde spec 031). **No** coincide
  necesariamente con el "Total A Cobrar" de Tesorería (§3.7): ese es un cálculo contable independiente
  vía `movimientos_tesoreria`/`CuentaTesoreria::saldoA()`, sin invariante de código entre ambos —
  diferencia es un chequeo informativo, no un bug de esta pantalla.
- **Brecha pendiente — paginación no es real en DB** (detectada 03/08/2026, tras un 500 en producción
  por `memory_limit` agotado): `porCliente()` trae todos los documentos (Venta+notas+cobros) del
  cliente/proveedor, agrega por entidad y arma la Collection completa en PHP; recién ahí
  `DataTables::collection()` corta a la página pedida — no hay `LIMIT`/`OFFSET` en SQL como si tiene el
  tab Movimientos (`DataTables::of()` sobre Query Builder). Se optimizó el 03/08/2026 para que el saldo
  por documento se calcule con SQL agregado (sin hidratar Eloquent con sus relaciones, que era lo que
  agotaba la memoria de PHP-FPM), pero la agregación por cliente y el filtro de antigüedad (buckets
  30/60/90) siguen en PHP. Llevarlo a `GROUP BY`/`CASE`/`DATEDIFF` en SQL con paginación real queda
  pendiente de un spec propio — la lógica tiene una asimetría no trivial (documentos con saldo ≤
  tolerancia se descartan, pero saldo inicial negativo se conserva) cubierta por
  `CuentaCorrientePorClienteTest`/`CuentaCorrienteSaldoInicialTest` que hay que preservar.
- **Tab "Movimientos"**: listado combinado (UNION SQL, servido con `DataTables::of()` server-side) de
  Ventas + Cobros + Notas de Crédito/Débito de clientes, con columnas Id, Emisión, Cliente, Operación,
  Categoría, Total Venta, Cobrado, A Cobrar, N° de Comprobante, Medio de Cobro, Descripción — nulas
  según el tipo de fila (p. ej. una fila de Cobro no tiene Total Venta/Categoría, sólo Medio de Cobro).
  Filtros: Cliente, Operación (Venta/Cobro/Nota de Crédito/Nota de Débito/Saldo Inicial), rango de
  fechas de Emisión. La suma de "A Cobrar" de las filas de Venta (y, desde spec 031, de la fila
  sintética "Saldo Inicial", una por cliente con `saldo_inicial ≠ 0`) coincide con el "Total" de ese
  cliente en Saldos Clientes.
- **Proveedores queda fuera de alcance** de esta spec (spec 029). **Actualización 14/08/2026**: la
  pantalla de Cuenta Corriente Proveedores está especificada en **spec 067** (tanda 1 del módulo
  Informes, ver §6.6) como espejo estructural de ésta, reutilizando `CuentaCorriente::porCliente
  ('proveedor')` — que ya soporta ese caso — **sin modificar el servicio**. **Implementado**
  (14/08/2026): `App\Services\Tesoreria\CuentaCorriente` quedó byte a byte igual, y sus tests
  previos (`CuentaCorrientePorClienteTest`, `CuentaCorrienteSaldoInicialTest`) siguen sin modificarse.
- Sin exportación CSV/PDF en esta iteración (sin evidencia de esa acción en las capturas relevadas).
  El exportador/tests huérfanos de un intento anterior (`CuentaCorrienteCsvExport`, diseño de "Saldo"
  plano sin aging) se descartaron por no coincidir con la estructura real (regla de oro).

---

### 6.5 Módulo Mensajería (Mercado Libre) — spec 032 (✅ implementada) — **divergencia deliberada respecto de Contagram**

Este módulo **no existe en Contagram real** — es una funcionalidad nueva del negocio (igual que la
integración de Mercado Libre en general, §5.2), no una reconstrucción de una pantalla relevada. No
aplica el principio de fidelidad estructural a Contagram para esta pantalla.

**Alcance de la spec 032 (Fase 0 — sin IA)**: unificar en un solo lugar los mensajes de compradores de
Mercado Libre — Preguntas pre-venta (públicas, sobre una publicación) y Mensajería post-venta (privada,
ligada a una orden) — y permitir responderlos **manualmente** desde el CRM, con auditoría de qué se
envió y quién. **No incluye ningún tipo de generación de IA**: eso se especifica aparte en una spec
futura (Fase 1, "bot de Mercado Libre"), recién cuando esté migrado el VPS (ver
`docs/bot_mensajeria_ml/flujo-y-alcance.md`).

- **Ubicación**: desplegable propio **"Mensajería"** en el sidebar (no cuelga de Ingresos ni de
  Configuración & Ajustes) → `/mensajeria`. Vista tipo bandeja + chat (referencia visual: `chat.blade.php`
  del template NexaDash), no un listado tabular puro — excepción razonable a la regla de DataTables para
  el panel de mensajes de una conversación puntual (el listado de conversaciones sí usa DataTables/AJAX).
- **Recepción**: webhook nuevo `POST /webhooks/mercadolibre` (notificaciones de los topics `questions` y
  `messages` de Mercado Libre — callback URL ya configurada por el usuario en el DevCenter, 2026-08-02),
  procesado de forma idempotente (upsert por el ID nativo de ML) para tolerar reintentos de notificación.
- **Envío de respuesta**: reutiliza `ClienteMercadoLibre` (punto único de salida hacia la API de ML ya
  usado por el resto de la integración, §5.2) — `POST /answers` para Preguntas; para post-venta,
  `POST /messages/packs/{pack_id}/sellers/{seller_id}?tag=post_sale` con `from`=vendedor/`to`=comprador
  (confirmado contra la documentación de ML durante la implementación — `pack_id` puede ser el
  `order_id` si no hay pack real). Lectura del detalle de un mensaje post-venta:
  `GET /messages/{message_id}?tag=post_sale` (a diferencia de Preguntas, acá `resource` del webhook es
  directamente el `message_id`, no un path — ver `specs/032-bot-mensajeria-mercadolibre/research.md` R2
  y `contracts/webhook-mercadolibre.md`).
- **Permisos nuevos**: módulo `mensajeria` con acciones `ver` y `responder` (separadas, para poder dar
  acceso de sólo lectura).
- **Fase 1 (spec 033, ✅ implementada — pendiente el gate operativo del VPS antes de activar en
  producción)**: switch "Bot de Mercado Libre" en Funciones Avanzadas (§5.1, fila `mercadolibre_bot`,
  `configuracion.mercadolibre.bot`), generación asíncrona de sugerencias de respuesta por IA
  (`App\Jobs\GenerarSugerenciaMercadoLibre`, proveedor default OpenAI GPT-4o-mini vía
  `openai-php/client`, detrás de la interfaz `App\Services\MercadoLibre\Bot\GeneradorDeSugerencias`),
  pantalla de configuración propia del bot (tono/instrucciones, `ml_bot_configuracion`). El límite de
  **350 caracteres** por sugerencia (`seller_max_message_length` real de ML, confirmado contra la
  documentación oficial) se instruye en el prompt y se valida en el Job antes de marcar `estado=lista`.
  La sugerencia se integra al envío ya existente (`EnvioRespuestaMercadoLibre::enviar()`, parámetro
  opcional `$sugerenciaId`) auditando en `ml_respuestas_enviadas.ml_sugerencia_id`/`sugerencia_editada`
  sin tocar el guard de doble respuesta. Depende de que el VPS con colas reales esté migrado antes de
  activar el switch en producción (en local corre con `QUEUE_CONNECTION=sync`) — ver
  `specs/033-bot-mercadolibre-ia/` y `docs/bot_mensajeria_ml/infraestructura.md`.
- **Fase 2 (reconsiderada, no implementada — cambio de rumbo 2026-08-02)**: el plan pasó de "sugerencia
  + aprobación humana obligatoria" a que el bot **conteste solo, sin aprobación humana por mensaje**,
  corriendo 24/7 en el VPS con el contexto de la empresa — decisión del usuario tras ver el bot
  funcionando en el demo. El comportamiento **real y desplegado hoy sigue siendo con aprobación
  humana** (FR-009/SC-003 de la spec 033 siguen describiendo el código en producción tal cual está); el
  envío autónomo es una fase nueva, todavía sin especificar formalmente. Detalle completo de la
  reconsideración en `docs/bot_mensajeria_ml/decisiones-pendientes.md` §"Aprobación humana obligatoria
  — REABIERTA" y `docs/bot_mensajeria_ml/flujo-y-alcance.md` §"Fases de alcance".

---

### 6.6 Módulo Informes (relevado 14/08/2026 — se construye en 3 tandas)

**Fuente**: `docs/Informe-Modulo-Informes-2026-08-14/` — 30 capturas reales, navegación completa de
cada informe/sub-pestaña/modal, más análisis binario de 7 archivos Excel exportados desde la propia
app para reconstruir la lógica de cálculo interna. Es la fuente de verdad estructural del módulo.

> **Corrección (24/08/2026, spec 077)**: el relevamiento del 14/08 documentó **8** tarjetas, pero el hub
> tiene **9** — faltaba **"Información para tu Contador"** (`/accountant_reports`), el Libro IVA
> Ventas/Compras. Se relevó aparte, con capturas propias, en
> `docs/informe_contagram_contador/` (ver §6.7). Por eso quedó fuera de las tandas 1 a 3.

En Contagram el módulo es una landing `/reports` con **9 tarjetas** (Ventas, Compras, Cta Cte
Clientes, Cta Cte Proveedores, Reporte Final, Gastos, Stock, **Información para tu Contador**,
Rankings) más una vista consolidada de gráficos en `/graphs`. **Divergencia deliberada de este CRM**: no se construye la landing de
tarjetas — cada informe es un ítem propio del desplegable "Informes" del sidebar con su URL real,
siguiendo el patrón ya vigente de "Informes > Stock" (§6.2) e "Informes > Cuenta Corriente" (§6.4).
Motivo: nuestro sidebar despliega submenús y el de Contagram no, con lo que la landing sería un salto
de navegación sin valor. Ver `no-hash-urls-para-navegacion` en memoria de proyecto.

**Arquitectura común de los 8 informes** (aprender uno enseña los ocho): selector de rango
"**Emisión**" con 9 opciones idénticas (Hoy, Ayer, Última Semana, Mes actual, Mes anterior, Últimos
30 días, Año actual, Desde-Hasta con doble calendario + accesos rápidos simultáneos, Borrar filtro),
panel de "Filtros" propio de cada informe, tabla de detalle con scroll horizontal, y exportación dual
Excel + PDF abajo a la derecha.

> **Regla relevada — "Mes actual" es el mes calendario completo** (día 1 al último del mes),
> incluyendo fechas futuras dentro del mismo mes; no se recorta a la fecha de hoy. Aplica a todos los
> informes.

**Estado por tandas:**

| Tanda | Informes | Estado |
|-------|----------|--------|
| **1** | Compras, Gastos, Cuenta Corriente Proveedores | **spec 067 — IMPLEMENTADA** (14/08/2026) |
| **2** | Ventas, Reporte Final | **spec 068 — IMPLEMENTADA** (15/08/2026) |
| **3** | Rankings, "Arma tu Informe" (**sólo render tabla**) | **spec 069 — IMPLEMENTADA** (16/08/2026) |
| **4** | Menú de gestión por fila en Cta Cte, ajustes al Informe de Stock | pendiente — spec por armar |
| **5** | **Información para tu Contador** (Libro IVA Ventas / Compras) | **spec 077 — especificada, lista para implementar** (24/08/2026) — ver §6.7. **spec 086 (IVA Digital RG 3685) — IMPLEMENTADA** (27/08/2026). **spec 087 (envío por correo) — IMPLEMENTADA** (27/08/2026), con el worker de cola pendiente de aplicar en el VPS |

> **Alcance acotado de la tanda 3, decidido por el cliente (15/08/2026)**: Rankings y "Arma tu
> Informe" **sí** se construyen, pero el selector "Mostrar Como" queda **fijo en Tabla**. Se
> descartan los demás modos de render de Contagram (mapa de calor, gráficos) y la vista consolidada
> `/graphs`: el cliente confirmó que no los usa y que mostrar la misma información de muchas formas
> sólo agranda la app. Un ranking es una tabla ordenada con su export; con eso alcanza.

**Ya implementados de antes**: Informe de Stock (spec 003, §6.2) y Cta Cte Clientes (spec 029, §6.4).

#### Tanda 3 — cómo quedó el motor de tablas dinámicas (16/08/2026)

**Contagram usa PivotTable.js y nosotros también.** Verificado en su app con el usuario presente: su
DOM trae las clases `pvtUi`, `pvtUnused`, `pvtCols`, `pvtRows` y `ui-sortable`. El arrastre de
dimensiones no es código propio de ellos ni nuestro: es el de la librería.

Diferencias de configuración que se relevaron y **se replicaron**:

- **El pool de un ranking lleva sólo la dimensión del ranking más el desglose de fecha**, no las 13.
  En su Ranking de Categorías el área sin asignar tiene un único elemento, `fecha de emision`. El
  pool completo es exclusivo de "Arma tu Informe".
- **El pool va horizontal arriba** (`unusedAttrsVertical: false`), no vertical a la izquierda.
- **Los textos van en español** y los botones del embudo con las clases del template: Contagram usa
  `btn btn-primary` / `btn btn-danger` / `btn btn-success` para "Seleccionar todo",
  "Deseleccionar todo" y "Aceptar". Se registró un locale `es` — pasar `localeStrings` como opción
  de `pivotUI` NO alcanza, el renderer de tabla se queda con "Totals" en inglés.

Divergencias **deliberadas** respecto de Contagram:

- **URLs reales en vez de fragmentos.** Contagram usa `#reporte_3`; nosotros
  `/informes/ventas/ranking/{dimension}` para poder compartir el enlace (FR-004).
- **El ranking arranca acotado al rango del informe.** Contagram abre sin filtro y muestra de 2021 a
  2026 (66 columnas de meses) porque calcula el cruce **en el servidor**. Nosotros lo calculamos en
  el navegador, así que el dataset viaja entero y hay un tope de 50.000 filas.
- **"Mostrar Como" no se dibuja** (FR-021) y el locale registra un único renderer, para que los
  modos de gráfico no puedan reaparecer ni manipulando las opciones desde afuera.

**Rendimiento medido** (16/08/2026, base de ~24.000 ventas):

| Rango | Filas | Tiempo | Peso del JSON |
|---|---|---|---|
| Un mes (caso típico) | 470 | 0,7 s | 212 KB |
| Dos años | 13.378 | 3,8 s | 5,7 MB |
| Tope de 50.000 (proyectado) | 50.000 | — | **~22 MB** |

El tope de 50.000 filas es generoso en cantidad pero **pesado en transferencia**: llegar ahí implica
mandar unos 22 MB al navegador. Si en el uso real se llega seguido a rangos así, conviene mover el
cálculo del cruce al servidor antes que subir el tope.

#### Tanda 1 — spec 067 (Compras, Gastos, Cta Cte Proveedores)

- **Informe de Compras** (`/informes/compras`): KPIs con la ecuación **Total Compras Creadas + Total
  Nota de Débito − Total Nota de Crédito = Total Compras**, más Cantidad Prod./Serv. (suma de
  cantidades, no de líneas), Cantidad Compras Creadas, Compra Promedio y Costo Actual. Tabla con
  **una fila por ítem de compra** (Id, Fecha, Comprobante, Proveedor, Producto/Servicio, Cant.,
  Precio, Total Comprobante — este último repetido por fila, **no sumable por fila**). Panel de 12
  filtros: Id, Producto/Servicio, Tipo de Producto/Servicio, Etiqueta, Productos, Facturado,
  Categoría de Compra, Proveedor, Tipo y N° de Factura, Usuario, Observación, Estado del Pago.

  > **Brecha detectada por la spec 076 (24/08/2026, CHK036/T045) — sin cerrar**: el párrafo de
  > arriba ("repetido por fila, no sumable") es **exactamente** la misma afirmación que tenía esta
  > sección para el Informe de Ventas antes de que la spec 076 la corrigiera (§ Informe de Ventas,
  > arriba) — y en Ventas resultó ser falsa: Contagram muestra el importe de cada línea, no el
  > total del comprobante repetido. `InformeComprasExport` y `resources/js/informe-compras.js`
  > todavía usan/muestran `total_comprobante` (repetido) en vez de un importe por línea, igual que
  > hacía Ventas antes de esta spec. **No se corrigió acá**: la spec 076 tiene alcance explícito
  > sólo sobre Ventas (`spec.md` Assumptions, `research.md §R6`). Queda pendiente una spec propia
  > para Compras que repita el mismo diagnóstico y arreglo (importe de línea + prorrateo de
  > conceptos extra si los tiene) antes de dar por buena esta descripción.
- **Informe de Gastos** (`/informes/gastos`): el más simple. Bloque Desde/Hasta/Gasto Total y
  estructura jerárquica Categoría → Subcategoría expandible con subtotal por nivel y detalle (Id,
  Fecha, Descripción, Medio de Pago, Total). Filtros: Categoría y/o Subcategoría, Medio de pago,
  Estado del Pago, Usuario.
- **Informe de Cuenta Corriente Proveedores** (`/informes/cuenta-corriente-proveedores`): espejo
  exacto del de Clientes (§6.4) — tabs "Saldos Proveedores" (A Vencer / 0-30 / 31-60 / 61-90 / >90 /
  Total, con saldos negativos listados) y "Movimientos" (Compra/Pago/NC/ND/Saldo Inicial). Modal de
  ficha de proveedor de **sólo lectura** al hacer clic en el nombre. Reutiliza
  `CuentaCorriente::porCliente('proveedor')` **sin modificar el servicio**.

**Divergencias deliberadas de la tanda 1 respecto de Contagram** (decididas por el cliente el
14/08/2026):

1. **El desglose impositivo AFIP se expone en pantalla**, no sólo en el Excel. El análisis binario
   reveló que Contagram vuelca **35 columnas** al exportar Compras (netos Gravado/No Gravado/Exento,
   IVA por alícuota 2,5/5/10,5/21/27 en columnas separadas, Perc. IVA, Perc. IIBB, Imp. Internos,
   CUIT, Punto de Venta, Afecta Stock…) mientras la pantalla muestra sólo 8. Acá van todas al
   selector de columnas. **No requiere migraciones**: se derivan de `compra_items.iva_pct` y
   `compra_conceptos.tipo`.
2. **Excel de doble hoja en los tres informes** (una formateada para leer + una plana para
   reprocesar). Contagram sólo lo hace en Gastos; el relevamiento lo señala como buen patrón a
   copiar.
3. **Tercera columna "Otras Percepciones"**: `compra_conceptos.tipo` no distingue percepción de IVA
   de percepción de IIBB (sólo tiene `percepcion|impuesto_interno|interes`). La clasificación se hace
   por el texto del concepto y lo no clasificable cae en una columna propia — nunca se descarta ni se
   imputa a la columna equivocada. Deuda anotada: tipificar el concepto en el formulario de Compra
   sería una spec aparte.
4. **Cta Cte de sólo lectura**: Contagram tiene un menú Ver/Editar/Eliminar por fila en Movimientos
   (es decir, usa el informe como pantalla de gestión). Se deja fuera de la tanda 1; queda para la
   tanda 3.

#### Bugs de Contagram detectados en el relevamiento

El análisis de los Excel exportados encontró inconsistencias reales del propio Contagram. **Dos de
ellas SÍ se replican por decisión expresa del cliente (15/08/2026)** y dos no.

> **Cambio de criterio — 15/08/2026.** Este apartado decía originalmente que ninguno de estos bugs se
> replicaba. Al especificar la tanda 2 el cliente eligió **fidelidad total**, para que los números de
> los archivos exportados coincidan exactamente con los de Contagram al comparar. La decisión se
> acota con una condición dura: **las dos réplicas viven sólo en la clase que escribe el Excel**,
> nunca en el servicio de cálculo, de modo que pantalla, PDF, hoja plana y todos los totales
> agregados siguen siendo correctos (ver spec 068 §Réplicas deliberadas y su `plan.md`).

**Se replican (sólo en el Excel):**

- **Resultado de líneas de Nota de Crédito en el Excel de Ventas** (réplica *R1*): para ventas
  normales `Resultado = Precio − CMV`, pero para la fila de NC el Excel exporta `-570` donde la
  pantalla muestra `-170` — o sea suma en vez de restar cuando el comprobante es NC. Error acotado a
  esa celda: no se propaga a los totales. En nuestro CRM se replica **únicamente en la hoja legible
  del Excel**; la pantalla, el PDF y la hoja plana aplican `Precio − CMV` a ventas y notas por igual,
  sin ramas por tipo.
- **Doble convención de signos en el Reporte Final** (réplica *R2*): en "Ventas Vs. Compras" el Total
  Egresos se guarda negativo y el Resultado es una suma; en "Cobros Vs Pagos" se guarda positivo, el
  Resultado es una resta, los subtotales por bloque van negativos y las líneas por cuenta de
  tesorería positivas. Se replica **en las hojas legibles del Excel**; el servicio devuelve todo en
  positivo con una bandera `naturaleza` (ingreso/egreso), la pantalla muestra egresos en positivo con
  `Resultado = Ingresos − Egresos` en ambas vistas, y la hoja plana conserva el criterio unificado.

**NO se replican:**

- **Saldo corrido del Informe de Stock calculado sobre el orden de visualización** y no el
  cronológico, lo que produce saldos intermedios negativos que nunca existieron. A revisar contra
  nuestro §6.2 en la tanda 3.
- **KPIs negativos en el Informe de Stock** por multiplicar el **costo actual** por el saldo de stock
  en vez del costo histórico. Nuestro "Costo Actual" de Compras tiene la misma semántica a propósito
  (es un indicador de valorización actual), pero **con tooltip explicativo obligatorio** para que no
  se confunda con el costo real de compra.

#### Tanda 2 — spec 068 (Ventas, Reporte Final)

- **Informe de Ventas** (`/informes/ventas`): espejo estructural del de Compras. Tres bloques de
  KPIs — **Total Ventas Creadas + Total Nota de Débito − Total Nota de Crédito = Total Ventas**;
  Cantidad Prod./Serv. (suma de cantidades) / Cantidad Ventas Creadas / Venta Promedio / Costo
  Actual; **Precio Neto − Costo Mercadería Vendida = Resultado**. Tabla con **una fila por ítem de
  venta** y 12 columnas: Id, Fecha, Comprobante, Cliente, Prod./Serv., Cant., Precio Unitario, Costo
  Total Actual, CMV Total, Precio Total Neto, Result., Total Comprobante (el importe **de esa
  línea** con impuestos, **sumable**: la columna suma el total del período). Botones "Exportar
  Resumen", "Exportar Excel Detallado" y "Exportar a PDF".

  > **Corrección del 24/08/2026 (spec 076).** Hasta esta fecha este párrafo afirmaba que Total
  > Comprobante iba *"repetido por fila, **no sumable**"* y que había sólo dos botones. **Las dos
  > cosas eran falsas**, y venían de un relevamiento incompleto de la tanda 2. Una captura de la
  > pantalla real de Contagram del 01/07/2026 lo desmiente: la venta 23501, de 12 líneas, muestra
  > **12 importes distintos** que suman $1.349.647,48 —el total del comprobante—, y al pie hay
  > **tres** botones. El CRM, construido sobre la afirmación equivocada, repetía el total en las 12
  > filas: sumar esa columna daba doce veces el valor real.
  >
  > Lección, la misma que dejó la spec 075 con el CMV: cuando un relevamiento y un archivo o
  > captura reales se contradicen, **gana el archivo**. Y conviene sospechar de las afirmaciones
  > que explican por qué algo *no* se puede hacer (acá, "no sumable"): suelen ser racionalizaciones
  > de un dato mal leído. El motor ya tenía la columna bien calculada desde la spec 069 para el
  > pivot; sólo la pantalla y los exports se habían quedado con el criterio viejo.
- **Reporte Final** (`/informes/reporte-final`): resultado del período en dos vistas.
  **Ventas Vs. Compras** (base devengado): `Ingresos → Ventas → Categoría`,
  `Ingresos → Otros Ingresos → Categoría`, `Egresos → Compras → Categoría`,
  `Egresos → Gastos → Categoría → Subcategoría`, **incluyendo gastos pendientes**.
  **Cobros Vs Pagos** (base caja): la misma estructura con un nivel más por **Cuenta de Tesorería**,
  imputando por la fecha del cobro/pago y **excluyendo los gastos pendientes**. Cada categoría lleva
  un checkbox "Activo" que funciona como **simulador "qué pasaría si"**: destildarlo recalcula
  subtotal de bloque, Total Ingresos/Egresos y Resultado en el instante del clic, sin ir al servidor
  y sin tocar los datos; el escenario simulado también se refleja en los archivos exportados.

  > **Cómo se identifica una categoría en el simulador** (decidido al implementar, 15/08/2026): por
  > una **clave `bloque|id`** (p. ej. `ventas|3`) y no por el id de la categoría a secas. El nodo
  > "Sin categoría" —que es un caso real, porque `ventas.categoria_id` y `compras.categoria_id` son
  > nullable— no tiene id, y con el id solo habría quedado imposible de destildar. La clave viaja
  > al servidor en `excluidas[]` al exportar.

**Regla de negocio — cómo se calcula el CMV:**

> ⚠️ **CORREGIDO el 24/08/2026 (spec 075).** Lo que la spec 068 fijó acá era **incorrecto**. Se deja
> el texto original más abajo, tachado conceptualmente, porque la corrección sólo se entiende
> sabiendo qué se creía antes y por qué se creía.

**Regla vigente (spec 075):**

```
CMV Total (línea) = costo_unitario_congelado_al_momento_de_la_venta × cantidad
```

Contagram guarda el **costo del producto congelado en el ítem de la venta**. Ese costo no se mueve
nunca más: ni cuando el proveedor aumenta la lista, ni cuando se edita la ficha del producto, ni
cuando se registran compras nuevas.

*Evidencia* (`actualziacion/julio/Informe_de_Ventas_Detallado_*.xlsx`, 1.016 líneas de julio 2026,
cuadran al centavo con las cards de Contagram): el ratio `CMV Total / Costo Total Actual` por línea
no es continuo, son **pocos valores discretos agrupados por proveedor y dependientes de la fecha de
la venta** — FV usa 0,96618 hasta el 24/07 y 1,0 desde el 20/07; KURYMAR 0,92593 hasta el 07/07 y
1,0 desde el 16/07; Ferrum/Ideal/Pompei SRL/GOOD LOOKING/Mauricio/RAO dan 1,0 en el 100% de sus
líneas; Peirano tiene ratios **mayores a 1** (1,11 y 1,20: el costo bajó después de la venta). Son
aumentos de lista por proveedor. Un promedio de compras no puede producir eso.

*Fallback* para líneas sin costo congelado (todas las ventas anteriores a la spec 075, que no se
backfillean): se usa el promedio ponderado de compras descripto abajo. El informe convive con los
dos criterios a propósito, y está asumido.

**Lo que decía la spec 068 y por qué estaba mal:**

Decía que el CMV se derivaba del promedio ponderado de las compras registradas del producto
(`SUM(compra_items.precio_unitario × cantidad) / SUM(cantidad)`, sin recorte temporal; producto sin
compras → 0). Lo dedujo de un único caso en la cuenta demo: "los ítems de la venta Id 5 tienen Costo
Total Actual > 0 y CMV 0 **porque esos productos nunca se compraron**".

Esa última parte era una **inferencia sobre la causa, no un dato observado**. La explicación
alternativa —"esos productos no tenían costo cargado cuando se hicieron esas ventas"— explica lo
mismo, y con datos reales explica más: en julio hay 45 líneas de 1.016 (4,4%) con CMV 0, y el CRM
tiene 227 productos con costo 0. Medido contra producción, la fórmula vieja daba **$24.603.190,02
contra $40.574.923 reales**, dejando el KPI "Resultado" inflado en ~$16M.

**Lección**: una regla de cálculo no se deriva de un caso único en una cuenta demo vacía. Se valida
contra un export con volumen, cruzando al menos dos variables independientes (acá: proveedor y fecha).

El CMV sigue estando explícitamente distinguido de **Costo Actual**, que es `productos.costo ×
cantidad` (valorización vigente). Que las dos difieran es esperado y es la razón de existir de ambas.

> **El Informe de Compras NO tiene este problema** — verificado el 24/08/2026 contra
> `migracion-nueva/excel-origen/Compras/2026 Compras.xlsx`: su card "Costo Actual" es
> `SUM(Costo × Cantidad) = 194.444.921,65`, que coincide con la card de Contagram, y 699 de 700
> productos tienen un único valor de costo en todo el año (no varía por fecha ⇒ no está congelado).
> Además Compras no tiene card de CMV, y el costo real de una compra ya vive en
> `compra_items.precio_unitario`.

**Divergencias deliberadas de la tanda 2 respecto de Contagram:**

1. **Excel de doble hoja también en Ventas y Reporte Final** (Contagram los exporta en una sola
   hoja), por coherencia con el estándar del módulo fijado en la tanda 1. **Excepción desde la spec
   076**: el "Exportar Excel Detallado" de Ventas sale en **una sola hoja**, como en Contagram — es
   un archivo nuevo, no tiene coherencia previa que romper, y su valor está en ser comparable
   celda a celda con el original.
2. **Sin barra de pestañas en el Informe de Ventas**: "Rankings" y "Arma tu Informe" quedan para la
   tanda 3 (con el alcance acotado de arriba), así que por ahora la pantalla es única.
3. **El Reporte Final no usa DataTables server-side** (única excepción a la regla obligatoria #1):
   no es un listado sino un árbol agregado de decenas de filas con checkboxes de simulación que
   deben recalcular en el cliente. Justificado en `specs/068-.../plan.md` §Complexity Tracking.
4. **No se replica** una omisión del export de Contagram: nuestro Excel completa siempre las celdas
   Desde/Hasta en las dos vistas del Reporte Final (Contagram las deja vacías en "Cobros Vs Pagos").

**Brecha abierta — 3 filtros del Informe de Ventas sin identificar**: el relevamiento declara un
panel de **22 campos** pero enumera sólo **19** (Id, Producto/Servicio, Tipo de Producto/Servicio,
Cliente, Productos, Facturado, Vendedor, Categoría de Venta, Proveedor, Etiqueta, Tipo y N° de
Factura, Usuario, Nota Cliente, Nota Interna, Estado del Cobro, Tipo, Remitos, Tipo y N° de Remito,
Transportista). Se construyen los 19; los 3 restantes **no se inventan** y quedan pendientes de
re-relevamiento sobre la app real.

#### Rankings / "Arma tu Informe" (tanda 3) — nota de arquitectura

Ventas y Compras en Contagram montan un motor de tablas dinámicas sobre **PivotTable.js**: 8 modos de
"Mostrar Como" (Tabla, Tabla con Gráfico de Barras, Mapa de Calor y sus variantes por fila/columna,
Líneas, Barras, Histograma), 4 opciones de "Dato" y hasta 7 de "Acción" (la lista de Acción **se
reduce a "Suma"** cuando el Dato es un conteo), con drag & drop de 13 dimensiones y guardado de vistas
personalizadas como **pestañas persistentes**. Su exportación a Excel es 100% client-side (SheetJS),
sin ida al servidor.

**Decisión de arquitectura (spec 069, 16/08/2026)**: se adopta la misma librería (PivotTable.js),
pero **vendorizada con un único renderer registrado ("Table")** — el recorte de "Mostrar Como" a sólo
Tabla es entonces una propiedad estructural del bundle, no una opción escondida. El cruce se calcula
**en el cliente**, sobre un dataset proyectado que el servidor entrega ya filtrado (mismo conjunto y
mismos filtros que el detalle de cada informe, para que los totales concilien al centavo); es la
misma excepción a "toda tabla es DataTables server-side" ya aceptada para el Reporte Final en la
tanda 2, por el mismo motivo: es un agregado interactivo, no un listado paginable. La **exportación**
NO es client-side como en Contagram: el cliente calcula la matriz y la manda al servidor, que la
escribe con `HojaInforme` (mismo patrón de doble hoja del módulo) — evita sumar SheetJS como
dependencia nueva y mantiene el mismo formato de archivo en los 9 exports del módulo. Detalle completo
en `specs/069-informes-rankings-pivot/research.md` R1-R3.

**Vistas guardadas**: se persisten en una tabla nueva, `informes_vistas` (config JSON, no datos),
**compartidas por todo el negocio** y no por usuario — el CRM es de un solo equipo y duplicar el
mismo cruce por persona no aporta. Una vista pertenece a un solo informe (Ventas o Compras) y no se
lista en el otro. Sin soft delete: es configuración de presentación, no documento fiscal.

*Fuente(s): `docs/Informe-Modulo-Informes-2026-08-14/`, `specs/069-informes-rankings-pivot/`*

---

### 6.7 Informe "Información para tu Contador" — Libro IVA Ventas / Compras (spec 077, 24/08/2026)

**Fuente**: `docs/informe_contagram_contador/` — relevamiento de `/accountant_reports` con 7 capturas
reales sobre la cuenta del cliente (24/08/2026). Es la fuente de verdad estructural de esta pantalla.

Novena tarjeta del hub de Informes: *"Obtené con un click toda la información que necesita tu contador
para el cálculo de tus impuestos."* Dos pestañas —**IVA VENTAS** e **IVA COMPRAS**— que arman el Libro
IVA del período con el desglose impositivo completo por comprobante.

**Divergencia estructural respecto de los otros 8 informes**: acá el período **no se precarga**. Los
demás arrancan en "Mes actual"; éste arranca vacío, con dos combos **Mes** y **Año** y el mensaje
*"Utilizá los filtros y generá tu informe a medida"*. Tampoco usa el selector de rango "Emisión" de 9
opciones: el período de un libro IVA es un mes calendario, no un rango libre.

#### Reglas de negocio relevadas

- **Barra de 5 totales con operadores**: `No Gravados/Exentos (+) Gravados (+) IVA Total (+) Perc.
  IVA/IIBB Total (=) Total Facturado`. **Imp. Internos e Imp. Municipales NO participan de la ecuación**,
  aunque sí se listan como columnas por comprobante.
- **El período se resuelve distinto según el tipo de fila** — regla central del informe:

  | Fila | Columna de período |
  |---|---|
  | Venta | `fecha_emision` (una venta no tiene mes de imputación: el campo "Contador" es exclusivo de Compras) |
  | Compra | `mes_imputacion_iva` (campo **"Contador"**), con respaldo en `fecha_emision` si está vacío |
  | NC/ND (de venta o de compra) | `mes_imputacion` propio (`NOT NULL`, spec 045) |

  Este informe es el **consumidor real** de los campos de imputación creados por las specs 045 y la de
  Compras: hasta ahora se le pedían al usuario sin que nada los leyera.
- **"Facturas Aprobadas por ARCA" vs "Facturas Manuales"**: dos casillas que existen **sólo en IVA
  VENTAS** (por defecto la primera tildada y la segunda no). En IVA Compras **no existen**: el
  comprobante lo emite el proveedor, así que el CAE no es un atributo propio de la operación. Firme =
  tiene CAE aprobado; manual = todo el resto (nunca enviado, pendiente **o rechazado**). Las dos clases
  particionan el universo sin solapamiento.
- **Las NC/ND se muestran con su IVA discriminado**, no en cero. Verificado en la captura de IVA Compras:
  una NCA muestra `Neto Gravado $30.577,03` e `IVA 21% $6.421,18` (y `30.577,03 × 1,21 = 36.998,21`).
  Es una diferencia importante con el Informe de Compras (spec 067), que sí las emite en cero porque
  `nota_credito_debito_items` no guarda `iva_pct`. **En un libro IVA no alcanza**: dejarlas sin
  discriminar subdeclara IVA (crédito fiscal perdido en Compras, débito no declarado en Ventas). La spec
  077 define un orden de precedencia para derivarlo — ver `specs/077-informe-contador-iva/data-model.md §4`.
- **Columna "Tipo"**: ventas y compras muestran su tipo tal cual (`FEA`, `FEB`, `FA`, `FB`); las notas se
  muestran como `NCA`/`NDA`, componiendo `NC`/`ND` + la letra del comprobante ajustado.
- **19 columnas** con selector de visibilidad, 8 filtros (Id, Tipo de Comprobante, N° de Comprobante,
  Cliente/Proveedor, N° de CUIT, Condición de IVA, Medio de Cobro/Pago, Provincia) y export a Excel.

#### Divergencias deliberadas respecto de Contagram

- **La ecuación de totales cierra exacta.** En Contagram no siempre: la captura de IVA Ventas muestra
  `2.669.509,27 + 560.596,95 = 3.230.106,22` pero informa `3.230.106,21` — 1 centavo de deriva, porque
  calcula el Total Facturado por separado. Acá se define como la suma de sus cuatro componentes, así que
  cierra por construcción. Mismo criterio con el que la spec 067 corrigió el signo de las NC.

#### Brechas conocidas

- **Imp. Municipales**: el modelo no tiene un concepto de impuesto municipal diferenciado
  (`venta_conceptos`/`compra_conceptos` sólo manejan `percepcion` e `impuesto_interno`). La columna se
  emite en **cero** para no divergir estructuralmente; como no participa de la ecuación de totales, no
  descuadra nada.
- **Condición de IVA histórica**: el sistema no guarda un snapshot de la condición fiscal en el
  comprobante, se lee de la ficha. Si un cliente cambia de condición, **el libro de un período ya cerrado
  cambia retroactivamente**. Resolverlo (persistir el dato fiscal en la venta) excede la spec 077.
- **"Exportar IVA Digital"**: **implementado** (spec 086, 27/08/2026). **"Enviar Info. a mi Contador"**
  también **implementado** (spec 087, 27/08/2026).
  - **spec 086 — IVA Digital (RG 3685)**: los 4 archivos TXT de ancho fijo del régimen más el ZIP que
    los agrupa. Relevada **decodificando los archivos reales** que genera Contagram (Agosto 2026,
    cuenta del cliente), guardados en `contador/` y usados como fixture de test
    (`tests/Fixtures/IvaDigital/`). Anchos de línea: 266 (Comprobantes Ventas), 62 (Alícuotas Ventas),
    325 (Comprobantes Compras), 84 (Alícuotas Compras); codificación **latin-1** y terminador **CRLF**
    — en UTF-8 un nombre con `Ñ` correría todas las posiciones del registro y ARCA rechazaría el
    archivo entero. Endpoint: `GET informes/contador/iva-digital?mes=&anio=` (botón "IVA Digital" en
    la pantalla de la 077, habilitado sólo con mes elegido). Implementación en
    `app/Services/Informes/IvaDigital/` (`IvaDigitalPaquete` orquesta los 4 writers) y
    `app/Support/ArchivosFiscales/RegistroAnchoFijo` (primitiva de padding/truncado/centavos/latin-1,
    reusable para futuros formatos de ancho fijo).
  - **spec 087 — Enviar Información a tu Contador por Correo**: **implementada**, el modal de envío
    relevado con 4 capturas. Regla central: el panel de adjuntos depende del período — sin período va
    vacío; con año solo van los 2 XLSX anuales; con año **y** mes se suma el ZIP de IVA Digital; y la
    casilla "PDF factura de ventas" agrega un ZIP más. **"Facturas Manuales" no es un adjunto sino un
    filtro**: son las ventas sin CAE, misma clasificación que las casillas ARCA/manual de la 077.
    Endpoints: `POST informes/contador/adjuntos-previstos` (alimenta el panel en vivo, sin generar
    nada) y `POST informes/contador/enviar` (valida, registra en `envios_contador` y encola
    `EnviarInformacionContador`). Implementación en `app/Services/Informes/Contador/`
    (`PaqueteContador` es la única fuente de "qué archivos corresponden" — la usan tanto el panel
    como el envío, así nunca divergen) y `app/Mail/CorreoContador.php`. El mail del contador se
    agregó como un único campo (`mail_contador`) a `datos_empresa` (Configuración → Empresa), sin
    pantalla nueva.
    > **Cola en `database`, no `sync`** (27/08/2026): se cambió `QUEUE_CONNECTION=sync` → `database`
    > en el `.env` local para que FR-021 (envío en segundo plano) se cumpla de verdad. La tabla
    > `jobs` (migración default de Laravel) ya existía. **Falta correr un worker real en el VPS** —
    > sin él, los jobs se acumulan en la tabla `jobs` sin procesarse nunca. Unit de systemd lista en
    > `deploy/contagram-queue-worker.service` (copiar a `/etc/systemd/system/`, `systemctl enable
    > --now contagram-queue-worker`), pendiente de aplicar en el VPS — no se tocó el VPS desde esta
    > sesión (memoria del proyecto: nunca probar/deployar sin OK puntual). Hasta que el worker esté
    > arriba, verificar manualmente que los envíos no queden pendientes para siempre.

  > **Hallazgos de spec 086 que conviene no volver a "corregir"** (verificados también contra datos
  > reales de MySQL, no sólo el fixture — la suite corre en SQLite y no lo garantiza por sí sola):
  > 1. En los archivos de ARCA el **importe total del comprobante no se recalcula** como suma de sus
  >    componentes: se emite el total almacenado, aunque difiera en ±1 centavo por doble redondeo. Debe
  >    coincidir con lo declarado en el CAE. Es lo **opuesto** al criterio de la 077 para la barra de
  >    totales en pantalla, y es deliberado en ambos casos.
  > 2. Contagram tiene un **defecto real** en esos archivos: dos comprobantes de compra de MercadoLibre
  >    declaran `Cantidad de alícuotas = 0` pero traen una fila de alícuota al 21% y crédito fiscal
  >    computable. La spec 086 **no lo replica**: emite el conteo real (`count()` de lo efectivamente
  >    escrito en Alícuotas Compras, nunca un valor calculado en paralelo). El archivo del CRM no será
  >    byte-idéntico al de Contagram en esos dos registros, y eso es correcto.
  > 3. **Compras "Sin Factura"** (`compras.tipo_comprobante` `NULL` o `'S'` — la opción del formulario
  >    para gastos sin comprobante fiscal real) **se excluyen** del período de IVA Digital: no tienen
  >    tipo/número de comprobante que declarar ante ARCA. Siguen apareciendo normalmente en el Libro IVA
  >    Compras en pantalla (spec 077) — sólo no entran al TXT. Encontrado recién al validar contra la
  >    base real (no aparece en el fixture de Agosto 2026, que no tenía ninguna): sin este filtro la
  >    generación del período rompe con `InvalidArgumentException` apenas aparece la primera.

*Fuente(s): `docs/informe_contagram_contador/`, `specs/077-informe-contador-iva/`,
`specs/086-iva-digital-rg3685/`, `specs/087-envio-contador-correo/`, archivos reales en `contador/`*

---

## 7. Módulos pendientes de re-relevamiento

Los siguientes módulos de Contagram fueron investigados en la primera pasada de este documento, pero
esa documentación se descartó junto con el código porque no reflejaba con precisión el negocio real.
Se re-relevarán módulo por módulo antes de retomar su implementación. **Ingresos (Ventas, Presupuestos,
Otros Ingresos, Abonos) ya se re-relevó** (ver §3), **Tesorería (Cuentas, Movimientos) ya se
implementó** (ver §3.7), **Egresos (Compras, Gastos) ya se re-relevó** (ver §4), **Inicio / Panel de
Control ya se implementó** (ver §6.3), **la integración con Mercado Libre + Funciones Avanzadas ya se
implementaron** (specs 011/012/013/016 — ver §3.2.bis, §3.2.ter, §5.1 y §5.2) y **la integración con
Tiendanube (conexión + ventas) ya se implementó** (specs 019/017, corrigiendo a la 015 — ver §3.2.quater, §5.3; etapa 3 — stock
y precios hacia Tiendanube, spec 018 — ya especificada y lista para implementar (ampliada 30/07/2026 con
precios), ver §3.2.quinquies) — todos
salieron de esta lista:

- ~~Pendiente asociado a Facturación Electrónica: autocompletado de datos de Cliente a partir del
    CUIT/CUIL consultando el Padrón de ARCA~~ — **retomado y especificado (03/08/2026) en spec 037**
    (`specs/037-padron-arca-cuit/`), una vez disponible WSAA/certificado propio (spec 034). Consulta
    `ws_sr_padron_a13` reutilizando `App\Services\Arca\ClienteWsaa`; ver §2 (ficha de Cliente) y §5
    (Ingresos > Tiendanube/MercadoLibre) para el detalle funcional. Queda listo para implementar, no
    aplica más como pendiente de esta lista.
- ~~Informes (Ventas, Compras, Gastos, Contador, Ranking, Reporte Final)~~ — **re-relevado el
  14/08/2026** con capturas reales (`docs/Informe-Modulo-Informes-2026-08-14/`, 30 capturas + análisis
  binario de 7 Excel exportados). Ver §6.6. El módulo se construye **en tres tandas**; la tanda 1
  (Compras, Gastos, Cuenta Corriente Proveedores) está especificada en spec 067. **Cuenta Corriente
  Clientes ya se implementó** (spec 029, ver §6.4); **Cuenta Corriente Proveedores dejó de ser una
  brecha: spec 067 la implementó el 14/08/2026**. Quedan pendientes las tandas 2 y 3.
- Retenciones (transversal a Cobros/Pagos — ya resuelta a nivel de regla de negocio en Ingresos §3.5 y
  Egresos §4.1 vía el modal "Nueva Retención"; sigue faltando el relevamiento de una pantalla propia de
  administración de retenciones, si existiera)
- Configuración & Ajustes → Ajustes de formularios (**Funciones Avanzadas** ya está implementada,
  spec 011 — §5.1; **Mi Perfil ya está implementada, spec 039** — ver §5)
- **Remitos con detalle de ítems** y **Recibos con capturas reales**: el documento imprimible de
  Recibos ya se implementó como mejor esfuerzo (spec 039, ver §3.5) por no existir informe con
  capturas; sigue pendiente contrastarlo contra la estructura real de Contagram si se releva.
- ~~Editar/Eliminar NC/ND de Ventas y Compras~~ — **especificado (11/08/2026) en spec 057**
  (`specs/057-editar-eliminar-ncnd/`), cadena completa specify→clarify→plan→checklist→tasks→analyze
  ya corrida, lista para implementar. Cubre: menú de fila Editar/Eliminar/Ver Detalle, wizard de
  edición con comprobante propio (tipo+número) y encadenamiento de 1 nivel en "Documento que
  Ajusta", bloqueo total si la nota ya tiene CAE aprobado, reversión exacta de stock al
  editar/eliminar, y el PDF de NC/ND en **Compras** (hoy sólo existe en Ventas). Ya no aplica más
  como pendiente de esta lista.
- **Reparación de las ventas de Mercado Libre convertidas con datos fiscales vacíos (detectado
  14/08/2026, sin spec)** — hasta la corrección de `TraductorOrdenes` de esa fecha (ver §5.2), toda
  orden de ML convertida a Venta creó/actualizó el Cliente **sin CUIT, sin condición de IVA y sin razón
  social**, y derivó el comprobante a **Factura B** aunque el comprador fuera Responsable Inscripto.
  El fix corrige las conversiones **futuras**; las ya convertidas quedaron con el tipo de comprobante y
  los datos del Cliente equivocados. Pendiente: medir el alcance (cuántas ventas y cuántos clientes),
  y definir el criterio de reparación distinguiendo las que **ya tienen CAE aprobado** —donde el
  comprobante ya fue declarado al fisco y no se puede reescribir sin más— de las que todavía no se
  emitieron.
- **Notificaciones (módulo nuevo, todavía sin spec)** — no existe en Contagram real, sería una
  funcionalidad propia del negocio. Necesidad detectada el 02/08/2026: la cuenta de Mercado Libre quedó
  `desconectada` el 31/07 a las 22:30 (falló el cron `mercadolibre:sincronizar-stock` en ese momento) y
  nadie se enteró hasta que se detectó manualmente varios días después revisando `ml_operaciones_log` a
  raíz de un error reportado en la bandeja de Mensajería (spec 032). Alcance mínimo a especificar más
  adelante: avisar (email / notificación en el CRM) cuando un comando programado (`withSchedule()` en
  `bootstrap/app.php` — sincronización de órdenes/stock/precios de Mercado Libre y Tiendanube) falla o
  cuando una cuenta de integración pasa a `caida`/`desconectada`, en vez de que ese estado sólo quede
  registrado en un log que nadie mira proactivamente.
  > **Parcialmente cubierto por la spec 073** (21/08/2026): la campanita de la barra superior ya
  > existe y avisa dos cosas —productos en punto de reposición y publicaciones de ML que no
  > actualizan stock— con el modelo "estado vigente + leído por usuario, sin histórico" (ver §5.1).
  > **Lo que sigue pendiente** es justamente el disparador original: avisar cuando un **comando
  > programado falla** o cuando una **cuenta de integración se cae/desconecta**. La 073 sí muestra en
  > el panel y en el desplegable si una sincronización lleva más de 15 minutos sin correr, que es la
  > señal indirecta de ese problema, pero no genera notificación propia por la falla del cron ni
  > manda email. Cuando se especifique, conviene reusar la infraestructura de la 073 (mismo endpoint
  > `monitoreo/resumen`, misma tabla `notificaciones_leidas`, misma clave de episodio) en vez de
  > armar un segundo sistema de notificaciones en paralelo.
- ~~Ranking de Clientes/Productos del Dashboard sin netear NC/ND~~ — **resuelto por spec 079**
  (24/08/2026): ambos rankings ya restan NC y suman ND con el mismo criterio de KPIs/Totales/Donas
  (sin piso, sin techo). Ver §6.3.
- **Auditoría (`Menú de usuario → Auditoría`, pantalla "Operaciones") — spec 054, implementada
  (07/08/2026).** En este CRM el link vive en el dropdown de usuario de la
  topbar (`resources/views/elements/header.blade.php`), inmediatamente debajo de "Configuración &
  Ajustes" (mismo bloque visible sólo para Admin) — calca la posición real de Contagram, donde
  "Auditoría" está en el menú de usuario junto a "Cerrar sesión" (ver
  `docs/informe_contagram_inicio_informes_ajustes.md` §4.1). Log transversal de todas las operaciones creadas/modificadas en la cuenta, con quién y cuándo
  las hizo. No tiene informe con capturas dedicado (no está documentado en `help.contagram.com`); la
  estructura de pantalla surge de dos capturas reales aportadas por el usuario el 07/08/2026 (una de
  cuenta de prueba vacía, otra de cuenta real con datos) — no se considera necesario un informe
  `docs/informe_contagram_*` aparte dado lo simple y autocontenido de la pantalla, pero si al especificar
  aparecen ambigüedades (ej. lista cerrada de valores de "Operación" o "Tipo") conviene volver a la app
  real a confirmarlas antes de cerrar la spec.
  - **Filtros** (accesible vía botón "Filtros"): Id (número de operación), Operación (dropdown,
    "Todos" + valores — se observaron Cobro, Venta, Gasto, Movimiento), Usuario (dropdown, "Todos" +
    usuarios de la cuenta). Selector de fecha aparte arriba a la derecha (ej. "7 Agosto").
  - **Columnas de la tabla**: Id, Fecha y Hora (ordenable), Usuario (nombre de persona o canal de
    integración, ej. "Ventas Online"), Tipo (verbo de la acción — sólo se observó "Creó"; probablemente
    también "Modificó"/"Eliminó"/"Anuló"), Operación (entidad afectada: Cobro, Venta, Gasto,
    Movimiento — coincide con las entidades de negocio ya mapeadas en Ingresos/Egresos/Tesorería),
    Detalle (texto libre resumen humano-legible de la operación: cliente + nº comprobante, proveedor +
    concepto de gasto, etc. — no parece ser un campo estructurado fijo sino una descripción generada por
    tipo de operación), Total (columna final, no se llegó a ver el contenido en las capturas).
  - DataTable estándar: paginación con selector "Registros por página", contador de resultados, botón
    "Exportar", fecha de "Actualizado el [fecha] a las [hora]" al pie.
  - **Operación "Precio de producto" (spec 074, 22/08/2026):** se suma a la lista de operaciones
    auditadas (que hasta ahora era Venta, Presupuesto, Cobro, Gasto, Compra, Movimiento de Tesorería y
    Movimiento de Stock). Registra **cada creación, modificación o eliminación del precio de un producto
    en una lista de precios**, con el precio anterior y el nuevo. Es una operación propia de este CRM —
    no se relevó en Contagram real — y nace de un problema concreto del negocio: los aumentos de precio
    se hacen en masa (fórmula en Excel + reimportación, o la acción "Modificar Precio de Venta" del
    listado), y hasta esta spec **no quedaba rastro alguno del valor anterior**, así que un error de
    fórmula detectado días después era irreversible.
    - Se captura en `PrecioProductoObserver`, el punto único por el que pasan las escrituras de
      `precios_producto` vía modelo, así que cubre los cuatro orígenes con una sola implementación:
      **importación masiva**, **edición manual** (ficha del producto), **edición masiva de
      precios/costos** (§Base de Datos → Productos, acción `accionAjustarPrecios`) y **copia de
      producto**. El origen queda como rótulo dentro del texto de "Detalle".
    - Detalle del modelo de datos (campos, formato del texto, escritura en lote, volumen esperado):
      ver `docs/modelo_datos.md` §`logs_auditoria`.
    - **Limitación conocida**: las escrituras de precio que no pasan por el modelo (query builder crudo)
      **no se auditan**. Hoy el único caso es el comando de migración `MigrarPuntoReposicion`.
  - Mapeo de dominio en este CRM: sería una nueva tabla `logs_auditoria` (o similar) poblada por
    observers/eventos de Eloquent en las entidades transaccionales existentes (Venta, Cobro, Gasto,
    Movimiento de stock/caja, etc.), con `usuario_id` nullable (para acciones de sistema/integración
    como Mercado Libre/Tiendanube), `tipo_accion`, `entidad`, `entidad_id`, `detalle`, `fecha`.
- **Brecha detectada (spec 071, 19/08/2026): "Crear Producto" desde el buscador del detalle no
  existe.** El campo del detalle de Venta/Compra/Presupuesto rotula la etiqueta
  *"Seleccionar o Crear Producto/Servicio"*, pero relevando el código (`resources/js/ventas.js`,
  `compras.js`, `presupuestos.js`) se confirmó que ese buscador **nunca tuvo** una acción de
  creación rápida — a diferencia del selector de Cliente (`#f-cliente`), que sí tiene "Crear
  Cliente" con su propio modal. La etiqueta viene prometiendo algo que no existe desde antes de
  esta feature; no se resuelve acá (el widget nuevo, `resources/js/buscador-catalogo.js`, es
  deliberadamente genérico y no sabe crear productos). Dos salidas posibles, sin resolver:
  (a) implementar la creación rápida de Producto/Servicio desde el buscador en una spec futura
  (análoga a "Crear Cliente"), o (b) corregir la etiqueta a "Seleccionar Producto/Servicio" para
  que deje de prometer algo que no hace. Ver `specs/071-buscador-productos-detalle/research.md`
  (hallazgo previo).

### 7.x Pendiente técnico — filtros por fecha sobre columnas `DATETIME` en UTC

**Detectado el 15/08/2026.** La app guarda en UTC (`app.timezone`) y muestra en
`America/Argentina/Buenos_Aires` (`app.display_timezone`). Las pantallas que filtran por día contra
una columna **`DATETIME`** comparan una fecha tipeada en hora argentina contra un valor en UTC, así
que **las últimas 3 horas de cada día (21:00 a 23:59 ARG) quedan imputadas al día siguiente**.

**No afecta nada contable.** Todas las columnas de negocio son `DATE` puro, sin hora, y por lo tanto
son inmunes:

```
ventas.fecha_emision   compras.fecha_emision   cobros.fecha   pagos.fecha
gastos.fecha           otros_ingresos.fecha    movimientos_tesoreria.fecha
notas_credito_debito.fecha_emision   presupuestos.fecha_emision
remitos.fecha          retenciones.fecha
```

Dashboard, Cuenta Corriente (clientes y proveedores), Tesorería, Informe de Compras y de Gastos
filtran sobre esas columnas: sus totales están bien.

**Las columnas afectadas son `DATETIME` en UTC:**

```
movimientos_stock.fecha
ml_ordenes.fecha_creada / fecha_cerrada
tn_ordenes.fecha_creada / fecha_cerrada
created_at de los logs de integraciones
```

**Pantallas a corregir:**

| Pantalla | Archivo | Filtro |
|---|---|---|
| Informe de Stock | `Informes/InformeStockController.php:147,151` | `whereDate('mov.fecha', …)` |
| Órdenes de Mercado Libre | `Ingresos/MercadoLibreVentaController.php:66,69` | `whereDate('fecha_cerrada', …)` |
| Órdenes de Tiendanube | `Ingresos/TiendanubeVentaController.php:59,62` | `whereDate('fecha_cerrada', …)` |
| Logs de integraciones | `Integraciones/MercadoLibreConfiguracionController.php:301,304` y `TiendanubeConfiguracionController.php:92,95` | `whereDate('created_at', …)` |

El **Informe de Stock además muestra la hora cruda sin convertir** (`resources/js/informe-stock.js`,
render de la columna `fecha`: `texto.slice(11, 16)`). Por eso un ajuste corrido a las 19:43 ARG se
lee como `22:43`, y una venta de las 23:32 aparece fechada al día siguiente.

Que el filtro y la vista estén desfasados **de la misma forma** tiene una consecuencia buena: son
coherentes entre sí, así que no se pierden ni se duplican registros. Sólo que el "día" que muestran
arranca a las 21:00 del día anterior.

**Magnitud medida al 15/08/2026** (registros en la franja 00:00–03:00 UTC = 21:00–24:00 ARG del día
anterior):

```
movimientos_stock     9 de 358    2,5 %
ml_ordenes           25 de 157     16 %   ← se vende de noche
```

**Arreglo propuesto**: `CONVERT_TZ(columna, '+00:00', '-03:00')` en el `whereDate` de esos cuatro
controladores, y convertir a `display_timezone` en el render de la fecha del Informe de Stock. Ojo
con dos cosas: `CONVERT_TZ` con offset fijo ignora el horario de verano (hoy Argentina no lo usa,
pero conviene dejarlo anotado), y aplicar la función sobre la columna **invalida el índice** — medir
si hace falta un índice funcional en `movimientos_stock`.

**Prioridad**: baja. No corrompe datos ni afecta importes; sólo la atribución de día en pantallas de
consulta. Se vuelve importante si se cierra caja de stock por día o se concilian órdenes de ML
contra Contagram por fecha.

**Corregido el 16/08/2026, la mitad de front del mismo problema.** Ocho modales calculaban "hoy"
con `new Date().toISOString().slice(0, 10)`, que es UTC: después de las 21:00 ARG proponían el día
**siguiente**. Un cobro cargado a las 22:00 quedaba fechado mañana, y eso sí escribía en la base
—no era sólo atribución de pantalla—. Ahora usan `AppFecha.hoy()`, que lee el reloj local. Ver la
regla de diseño #6 en `CLAUDE.md`.

En la misma pasada se reemplazaron los 51 campos `<input type="date">` del proyecto por campos de
texto `dd/mm/aaaa` (`AppFecha`): el input nativo se dibuja con el locale del **navegador**, no con
el de la app, y mostraba `08/05/2026` para el 5 de agosto — indistinguible de un dato invertido en
un proyecto cuyo origen ya venía con día y mes cambiados. **El contrato con el backend no cambió:
sigue viajando ISO `YYYY-MM-DD`.** Lo fijan `tests/js/fecha-ar.test.mjs` (barrido de las 144 fechas
ambiguas del año, las de día ≤ 12, que al invertirse siguen existiendo y por eso se guardan mal en
silencio) y `tests/Feature/FechaIdaYVueltaTest.php` (incluido que reabrir y guardar sin cambios no
mueva la fecha).

---

## 8. Fuentes principales

- Sitio institucional: https://contagram.com/
- Centro de ayuda: https://help.contagram.com/es/
