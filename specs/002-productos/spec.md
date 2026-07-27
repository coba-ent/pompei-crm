# Feature Specification: Base de Datos — Productos & Servicios

**Feature Branch**: `002-productos`

**Created**: 2026-07-17

**Status**: Draft

**Input**: User description: "Módulo Base de Datos → Productos & Servicios. Segundo módulo de negocio del CRM (después de Clientes). Alta/edición/baja de productos y servicios (los servicios no controlan stock), con código/SKU único, precios de venta y compra con IVA, variantes (talle/color) con SKU único por variante (clave de sync TiendaNube), listas de precio diferenciadas, depósitos múltiples con stock por producto/variante+depósito, ajustes de stock manuales, e histórico de movimientos. No se elimina un producto con operaciones cargadas: se marca inactivo. Listado DataTables server-side AJAX, modales Bootstrap AJAX sin recargar, toasts. Single-tenant. Importación por Excel fuera de alcance."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Alta de producto/servicio básico (Priority: P1) 🎯 MVP

Un usuario del negocio necesita registrar un nuevo producto o servicio con los datos mínimos para
poder operar con él (venderlo, comprarlo): nombre, un precio de venta y, si es un producto físico,
la posibilidad de controlar su stock más adelante. Un servicio no controla stock.

**Why this priority**: Sin la capacidad de dar de alta productos/servicios, ningún módulo que dependa
de ellos (Ventas, Compras, Stock, Listas de precio) puede funcionar. Es el ladrillo base del módulo,
equivalente al alta de Clientes.

**Independent Test**: Se puede probar completamente creando un producto sólo con nombre y precio de
venta, y un servicio con nombre y precio, verificando que ambos aparecen en el listado y se pueden
reabrir con sus datos. El servicio queda marcado como que no controla stock.

**Acceptance Scenarios**:

1. **Given** el listado de productos, **When** el usuario elige "Nuevo Producto", completa nombre y
   precio de venta y guarda, **Then** el producto queda registrado como activo y aparece en el listado.
2. **Given** el formulario de nuevo producto, **When** el usuario intenta guardar sin nombre,
   **Then** el sistema impide el guardado e indica que el nombre es obligatorio.
3. **Given** un producto existente, **When** el usuario lo abre, modifica su precio de venta y guarda,
   **Then** los cambios quedan persistidos y visibles.
4. **Given** el formulario, **When** el usuario elige tipo "Servicio", **Then** el sistema registra el
   ítem como servicio y no habilita el control de stock para él.

---

### User Story 2 - Precios y datos de compra/venta (Priority: P1)

El usuario necesita cargar en el producto sus datos económicos: precio de venta con su IVA de venta,
costo con su IVA de compra, y controlar si el producto se muestra en el buscador de ventas y/o de
compras. También puede asignarle un proveedor y una descripción.

**Why this priority**: Los importes e IVA son la base de todo cálculo posterior (ventas, compras,
Libro IVA). Por Principio IV de la constitución, la lógica de dinero/IVA es crítica. Es tan importante
como el alta básica y suele cargarse en el mismo formulario.

**Independent Test**: Se puede cargar un producto con precio de venta + IVA venta y costo + IVA compra,
marcar "mostrar en ventas"/"mostrar en compras", y verificar que todo persiste y se relee correctamente.

**Acceptance Scenarios**:

1. **Given** un producto en edición, **When** el usuario carga precio de venta, IVA de venta, costo e
   IVA de compra, **Then** esos valores quedan persistidos con la precisión decimal correcta.
2. **Given** un producto, **When** el usuario desmarca "mostrar en ventas", **Then** el producto queda
   marcado para no ofrecerse en el buscador de ventas (sin dejar de existir).
3. **Given** un producto en edición, **When** el usuario le asigna un proveedor y una descripción,
   **Then** ambos quedan guardados.
4. **Given** un precio o IVA negativo, **When** el usuario intenta guardar, **Then** el sistema lo
   rechaza e indica el error.

---

### User Story 3 - Código/SKU único (Priority: P1)

El usuario puede asignar al producto un código/SKU que lo identifica de forma única en el sistema.
El SKU es la clave de sincronización con canales externos (TiendaNube) y no puede repetirse entre
productos ni entre variantes.

**Why this priority**: La unicidad del SKU es un invariante de integridad de datos: un SKU duplicado
rompería la sincronización de inventario con TiendaNube/MercadoLibre (módulos futuros) y la
identificación del ítem. Es crítico aunque el canal externo aún no exista.

**Independent Test**: Se puede crear un producto con SKU, intentar crear otro con el mismo SKU y
verificar que es rechazado; crear varios productos sin SKU y verificar que se permite.

**Acceptance Scenarios**:

1. **Given** un producto con SKU "ABC-1", **When** el usuario intenta crear otro producto con SKU
   "ABC-1", **Then** el sistema rechaza el guardado e indica que el código ya existe.
2. **Given** productos sin SKU, **When** el usuario crea varios sin código, **Then** el sistema los
   permite (el SKU es opcional a nivel de producto).
3. **Given** un producto con SKU, **When** el usuario lo edita sin cambiar el SKU, **Then** el guardado
   no falla por unicidad contra sí mismo.

---

### User Story 4 - Variantes con SKU propio (Priority: P2)

El usuario puede definir variantes de un producto (por ejemplo talle y/o color), cada una con su
propio SKU único. Un producto sin variantes no lleva ninguna. El SKU debe ser único considerando
tanto productos como variantes en su conjunto.

**Why this priority**: Las variantes son necesarias para negocios con talles/colores y son la unidad
real de sincronización con TiendaNube. Depende del alta de producto (P1) y de la regla de SKU (P1),
por eso es P2.

**Independent Test**: Se puede crear un producto con dos variantes (ej. Talle S / Talle M), cada una
con su SKU, guardar, reabrir y verificar que ambas persisten; intentar repetir un SKU de variante
(contra otro producto o variante) y verificar que es rechazado; quitar una variante y verificar que
se elimina.

**Acceptance Scenarios**:

1. **Given** un producto en edición, **When** el usuario agrega dos variantes con talle/color y SKU
   distintos y guarda, **Then** ambas variantes quedan persistidas y visibles al reabrir.
2. **Given** una variante con SKU "REM-S", **When** el usuario intenta asignar "REM-S" a otra variante
   o producto, **Then** el sistema lo rechaza (unicidad global de SKU).
3. **Given** un producto con variantes, **When** el usuario elimina una variante y guarda, **Then** esa
   variante deja de estar asociada (y, si tuviera stock/movimientos, se aplica la misma regla de no
   eliminación que a los productos con operaciones).
4. **Given** un producto sin variantes, **When** se consulta, **Then** no tiene filas de variante y su
   stock se lleva a nivel de producto.

---

### User Story 5 - Precios por lista de precio (Priority: P2)

El usuario puede cargar, para un producto, precios diferenciados según la lista de precio (Mayorista,
Minorista, Tarjeta, etc.), de modo que al operar con un cliente se aplique el precio de su lista.

**Why this priority**: Las listas de precio son un diferencial comercial habitual (mayorista vs
minorista) y ya están modeladas y sembradas desde el módulo Clientes. Depende del alta de producto,
por eso es P2. No bloquea el MVP.

**Independent Test**: Se puede asignar a un producto un precio para la lista "Mayorista" y otro para
"Minorista", guardar y verificar que ambos persisten asociados a sus listas.

**Acceptance Scenarios**:

1. **Given** un producto y varias listas de precio existentes, **When** el usuario carga un precio
   distinto para cada lista y guarda, **Then** cada precio queda asociado a su lista.
2. **Given** un producto con un precio para "Mayorista", **When** el usuario modifica ese precio,
   **Then** se actualiza sin duplicar la fila (un precio por producto y lista).
3. **Given** un producto sin precios de lista cargados, **When** se consulta, **Then** se usa su precio
   de venta base (la ausencia de precio de lista no es un error).

---

### User Story 6 - Depósitos, stock y ajustes manuales (Priority: P2)

El usuario puede llevar el stock de un producto (o de cada variante) diferenciado por depósito, y
registrar ajustes manuales de stock (aumento o disminución con una descripción), quedando un histórico
consultable de movimientos. El stock también se afectará automáticamente por Compras (entrada) y
Ventas (salida) cuando existan esos módulos; en esta feature sólo se cubren el ajuste manual y la
consulta.

**Why this priority**: El control de stock es central para un negocio de productos, pero opera sobre
el alta ya hecha (P1) y sus módulos consumidores (Ventas/Compras) son futuros. Por Principio IV, los
movimientos de stock llevan test. Es P2: valioso, no imprescindible para el MVP.

**Independent Test**: Sobre el depósito "Principal" precargado, hacer un ajuste de aumento de 10
unidades de un producto, verificar que el stock actual queda en 10 y que el movimiento aparece en el
histórico; hacer una disminución de 3 y verificar stock 7 y el nuevo movimiento.

**Acceptance Scenarios**:

1. **Given** un producto y el depósito "Principal" precargado, **When** el usuario registra un ajuste de aumento de N unidades
   con una descripción, **Then** el stock actual del producto en ese depósito aumenta en N y se registra
   un movimiento con fecha, tipo, cantidad, descripción y usuario.
2. **Given** un producto con stock en un depósito, **When** el usuario registra un ajuste de disminución
   de M unidades, **Then** el stock disminuye en M y queda registrado el movimiento.
3. **Given** un producto con variantes, **When** el usuario ajusta stock, **Then** el stock se lleva por
   variante + depósito; para un producto sin variantes, por producto + depósito.
4. **Given** un servicio (tipo "servicio"), **When** se consulta, **Then** no admite control de stock ni
   ajustes.
5. **Given** un producto con movimientos de stock cargados, **When** se consulta su histórico filtrado
   por ese producto, **Then** se listan sus movimientos ordenados por fecha.

---

### User Story 7 - Listar, buscar y filtrar (Priority: P2)

El usuario necesita ver la lista de productos/servicios, buscarlos por nombre o código/SKU, y filtrar
por estado (activos/inactivos), por tipo (producto/servicio) y, opcionalmente, por proveedor, para
encontrar rápidamente el ítem con el que quiere operar.

**Why this priority**: A medida que crece el catálogo, encontrar un ítem puntual es necesario para la
operación diaria. Depende de que exista el alta (P1).

**Independent Test**: Con varios productos y servicios cargados, se puede buscar por parte del nombre y
por SKU, y filtrar por activos/inactivos y por tipo, verificando resultados correctos.

**Acceptance Scenarios**:

1. **Given** varios ítems cargados, **When** el usuario escribe parte de un nombre en el buscador,
   **Then** el listado muestra sólo los que coinciden.
2. **Given** ítems con SKU, **When** el usuario busca por un SKU, **Then** el listado muestra el ítem
   que lo tiene.
3. **Given** productos activos e inactivos, **When** el usuario filtra por "activos", **Then** el
   listado excluye a los inactivos.
4. **Given** productos y servicios, **When** el usuario filtra por tipo "servicio", **Then** el listado
   muestra sólo los servicios.

---

### User Story 8 - Baja lógica y eliminación (Priority: P3)

El usuario puede inactivar (baja lógica) un producto para dejar de ofrecerlo, y reactivarlo. Un
producto con operaciones cargadas (ventas, compras, movimientos de stock) nunca se elimina físicamente:
sólo se inactiva. Un producto sin ninguna operación podría eliminarse definitivamente.

**Why this priority**: Preserva la trazabilidad (alineado con Principio III y con la regla de negocio
de la sección 11 del doc: un producto con operaciones no se elimina). Es importante pero secundario
respecto de poder crear y operar con productos.

**Independent Test**: Se puede inactivar un producto y verificar que deja de aparecer en los buscadores
de ventas/compras pero sigue en el listado con filtro "inactivos"; eliminar un producto sin operaciones
(se borra); intentar eliminar uno con movimientos de stock (rechazado).

**Acceptance Scenarios**:

1. **Given** un producto activo, **When** el usuario lo marca como inactivo, **Then** deja de ofrecerse
   en nuevas operaciones pero permanece consultable con su historial intacto.
2. **Given** un producto inactivo, **When** el usuario lo reactiva, **Then** vuelve a estar disponible.
3. **Given** un producto con al menos una operación asociada, **When** el usuario intenta eliminarlo
   definitivamente, **Then** el sistema lo impide e indica que sólo puede inactivarse.
4. **Given** un producto sin ninguna operación asociada, **When** el usuario lo elimina, **Then** el
   sistema lo elimina definitivamente.

---

### Edge Cases

- **SKU duplicado (producto vs variante)**: un SKU no vacío debe ser único considerando productos y
  variantes en conjunto; se rechaza cualquier repetición. Varios ítems sin SKU están permitidos.
- **Servicio con stock**: un ítem tipo "servicio" no debe permitir control de stock ni ajustes; la UI
  no ofrece stock para servicios.
- **Ajuste de stock que deja negativo**: definir si el stock puede quedar negativo por un ajuste manual
  (ver Assumptions — por defecto se permite en ajustes manuales, pero se registra el movimiento).
- **Precio/IVA/costo negativos**: se rechazan; los importes son ≥ 0.
- **Eliminar variante con stock/movimientos**: se aplica la misma regla que a productos con
  operaciones — no se elimina físicamente si tiene movimientos asociados.
- **Producto inactivo referenciado**: un producto inactivo con operaciones históricas debe seguir
  mostrándose correctamente en informes y comprobantes ya emitidos.
- **Depósito sin stock de un producto**: consultar stock de un producto en un depósito donde nunca tuvo
  movimientos devuelve 0, no error.

## Requirements *(mandatory)*

### Functional Requirements

**Alta y edición (producto/servicio)**

- **FR-001**: El sistema MUST permitir crear un producto o servicio con, como mínimo, nombre y precio
  de venta.
- **FR-002**: El sistema MUST rechazar el guardado de un ítem sin nombre, indicando el error.
- **FR-003**: El sistema MUST permitir clasificar el ítem como **producto** o **servicio**; los
  servicios NO controlan stock.
- **FR-004**: El sistema MUST permitir editar todos los datos de un ítem existente y persistir los
  cambios.
- **FR-005**: El sistema MUST permitir registrar una descripción y asociar un proveedor (opcional) al
  ítem.

**Datos económicos**

- **FR-006**: El sistema MUST permitir registrar precio de venta e IVA de venta (porcentaje), y costo
  e IVA de compra (porcentaje).
- **FR-007**: El sistema MUST rechazar importes o porcentajes de IVA negativos.
- **FR-008**: El sistema MUST permitir marcar si el ítem se muestra en el buscador de ventas y/o en el
  de compras (ambos por defecto activos).

**Código/SKU y unicidad**

- **FR-009**: El sistema MUST permitir registrar un código/SKU opcional para el producto.
- **FR-010**: El sistema MUST garantizar que un código/SKU no vacío sea **único considerando productos
  y variantes en conjunto**; MUST rechazar cualquier duplicado. MUST permitir múltiples ítems sin SKU.

**Variantes**

- **FR-011**: El sistema MUST permitir definir 0..N variantes de un producto (ej. talle, color, o
  etiqueta libre), cada una con su propio SKU único (según FR-010).
- **FR-012**: El sistema MUST permitir eliminar una variante que no tenga stock/movimientos asociados; si
  los tiene, se aplica la regla de no eliminación (FR-020).
- **FR-013**: Un producto sin variantes MUST llevar su stock a nivel de producto; uno con variantes, a
  nivel de variante.

**Listas de precio**

- **FR-014**: El sistema MUST permitir registrar, para un producto, un precio diferenciado por cada
  lista de precio existente, con a lo sumo un precio por (producto, lista).
- **FR-015**: El sistema MUST tratar la ausencia de precio de lista como uso del precio de venta base
  (no es un error). *(Regla de **lectura** que consumirá el módulo Ventas al aplicar el precio; en esta
  feature sólo se garantiza que un producto sin precios de lista es válido y conserva su precio base.
  La selección efectiva del precio según la lista del cliente es cross-módulo y se resuelve en Ventas.)*

**Depósitos y stock**

- **FR-016**: El sistema MUST llevar el stock de un producto o variante **diferenciado por depósito**,
  sobre un depósito **"Principal" precargado** por defecto (para poder operar el stock desde el alta sin
  configuración previa). *(El **ABM de depósitos** —crear/renombrar/dar de baja depósitos adicionales—
  corresponde a "Depósitos múltiples" de Configuración → Funciones Avanzadas (sección 5.2 del doc de
  dominio) y queda **fuera del alcance** de esta feature; se especificará por separado. Aquí el modelo
  de datos ya soporta múltiples depósitos, pero la UI de gestión no es parte de este módulo.)*
- **FR-017**: El sistema MUST permitir registrar ajustes de stock manuales (aumento o disminución) con
  cantidad y descripción, actualizando el stock actual y registrando un movimiento en el histórico
  (fecha, tipo, cantidad, descripción, usuario).
- **FR-018**: El sistema MUST mantener un histórico de movimientos de stock consultable y filtrable por
  producto.
- **FR-019**: El sistema MUST NO permitir control ni ajustes de stock sobre ítems tipo servicio.

**Baja lógica y eliminación**

- **FR-020**: El sistema MUST impedir la eliminación física de un producto (o variante) con al menos una
  operación asociada (venta, compra o movimiento de stock); en ese caso sólo se permite inactivarlo.
- **FR-021**: El sistema MUST permitir marcar un producto como inactivo (baja lógica) y reactivarlo.
- **FR-022**: Un producto inactivo MUST dejar de ofrecerse en nuevas operaciones pero MUST permanecer
  consultable con su historial intacto.
- **FR-023**: El sistema MUST permitir eliminar físicamente un producto sin ninguna operación asociada.

**Listado y búsqueda**

- **FR-024**: El sistema MUST mostrar un listado de productos/servicios.
- **FR-025**: Los usuarios MUST poder buscar por nombre y por código/SKU.
- **FR-026**: Los usuarios MUST poder filtrar por estado (activos/inactivos) y por tipo
  (producto/servicio); el filtro por proveedor es deseable.

**Contexto single-tenant**

- **FR-027**: El sistema MUST gestionar los productos de un único negocio; no existe segmentación por
  empresa (sin `empresa_id`).

### Key Entities *(include if feature involves data)*

- **Producto/Servicio**: bien o servicio que el negocio vende y/o compra. Atributos: nombre, código/SKU
  (opcional, único), tipo (producto/servicio), descripción, proveedor (opcional), mostrar en
  ventas/compras, precio de venta + IVA venta, costo + IVA compra, estado (activo/inactivo). Un servicio
  no controla stock. Relaciones: pertenece opcionalmente a un proveedor; tiene 0..N variantes, 0..N
  precios por lista, stock por depósito y movimientos de stock; será referenciado por ventas y compras
  (módulos futuros).
- **Variante**: unidad diferenciada de un producto (talle/color o etiqueta libre) con su propio SKU
  único. Pertenece a un producto (0..N); lleva su propio stock por depósito cuando existe.
- **Lista de precio**: conjunto de precios diferenciados (Mayorista/Minorista/Tarjeta). Reutilizada del
  módulo Clientes. Referenciada por los precios de producto.
- **Precio por lista**: precio de un producto para una lista de precio determinada (único por producto y
  lista).
- **Depósito**: ubicación física donde se lleva stock. Referenciado por stock y movimientos.
- **Stock**: cantidad actual de un producto (o variante) en un depósito (foto del stock).
- **Movimiento de stock**: registro histórico de una entrada, salida o ajuste de stock (fecha, tipo,
  cantidad, descripción, usuario, origen). En esta feature el origen es el ajuste manual; a futuro,
  ventas y compras.
- **Proveedor**: entidad que provee el producto (referencia opcional). Se especifica en su propia
  feature; aquí sólo se asume que puede seleccionarse.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Un usuario puede dar de alta un producto básico (nombre + precio de venta) en menos de 1
  minuto.
- **SC-002**: Ningún código/SKU no vacío queda duplicado entre productos y variantes (unicidad
  garantizada al 100%).
- **SC-003**: El 100% de los ajustes de stock quedan reflejados tanto en el stock actual como en el
  histórico de movimientos, sin discrepancia entre ambos.
- **SC-004**: El 100% de los intentos de eliminar físicamente un producto con operaciones asociadas son
  rechazados, y ninguna operación histórica queda huérfana.
- **SC-005**: Un usuario puede localizar un producto puntual en un catálogo de al menos 1.000 productos
  usando búsqueda por nombre o SKU en menos de 5 segundos.
- **SC-006**: Ningún importe o porcentaje de IVA negativo queda persistido en un producto.
- **SC-007**: Un servicio nunca queda con registros de stock asociados.

## Assumptions

- Las **listas de precio** y su catálogo (`listas_precio`) ya existen desde el módulo Clientes
  (001-clientes); esta feature las reutiliza sin recrearlas.
- Los **depósitos**: en esta feature se crea la tabla `depositos` (del modelo de datos) y se siembra un
  depósito **"Principal"** para operar el stock desde el alta. El **ABM de depósitos** (crear/renombrar/
  dar de baja depósitos adicionales, "Depósitos múltiples" de Funciones Avanzadas) queda **fuera del
  alcance** y se especificará aparte; el modelo ya soporta múltiples depósitos aunque la UI de gestión no
  sea parte de este módulo.
- El concepto de "operación asociada" que impide la eliminación física abarca ventas, compras y
  movimientos de stock; como Ventas/Compras aún no existen, la regla se implementa de forma extensible
  (método `tieneOperaciones()` que hoy considera sólo movimientos de stock y queda como costura para los
  módulos futuros), igual que en Clientes.
- La afectación **automática** de stock por Ventas (salida) y Compras (entrada) queda **fuera del
  alcance** de esta feature; se implementará con esos módulos. Aquí sólo se cubren el ajuste manual y la
  consulta del stock/movimientos.
- Por defecto, un **ajuste manual** de stock puede dejar el stock negativo (se prioriza registrar el
  movimiento real); la política de "no vender sin stock" pertenece al módulo Ventas (Configuración →
  Funciones Avanzadas → "Ventas sin stock").
- La **importación masiva de productos por Excel** (y la edición masiva de precios por Excel,
  mencionadas en la sección 5.2 del doc de dominio) quedan **fuera del alcance** de esta feature; se
  especificarán como feature aparte (Importar Datos).
- La **mercadería en consignación** (flujo específico de recepción sin compra formal, sección 5.2) queda
  fuera del alcance de esta feature.
- La sincronización real con **TiendaNube/MercadoLibre** (que usa el SKU como clave) queda fuera del
  alcance; aquí sólo se garantiza el invariante de SKU único que esa sincronización requerirá.
- Los **proveedores** se seleccionan pero se gestionan en su propia feature; si al implementar aún no
  existen, el campo proveedor queda disponible pero opcional.
- La UI se construye sobre el layout base existente (`layouts.default` + template NexaDash), en español,
  reutilizando el patrón del módulo Clientes: DataTable AJAX server-side, alta/edición/baja por modal
  Bootstrap vía AJAX sin recargar, y notificaciones con toasts.
