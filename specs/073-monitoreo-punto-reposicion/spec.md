# Feature Specification: Monitoreo, Punto de Reposición y Notificaciones

**Feature Branch**: `073-monitoreo-punto-reposicion`

**Created**: 2026-08-21

**Status**: Draft

**Input**: User description: "Rediseño del panel de Monitoreo + Punto de Reposición + Notificaciones en topbar. El panel de monitoreo hoy es una vista interna sin link, creada para vigilar el stock de la integración; ahora el negocio quiere usarlo de verdad, así que se rediseña por completo con las reglas de diseño del proyecto, pasa a tener permiso propio y link en la top bar con un desplegable de métricas. El punto de reposición deja de ser una lista de precios y pasa a ser un atributo del producto que reemplaza el umbral hardcodeado de 3 unidades. La campanita de notificaciones del template se activa para avisar productos en punto de reposición y publicaciones de Mercado Libre que no actualizan stock."

## Contexto y estado actual

Estos hechos ya están relevados y son el punto de partida (no son requisitos, son el "de dónde venimos"):

- Existe un panel de monitoreo interno **sin link en ningún menú**, al que se entra escribiendo la URL. Muestra el estado de las dos sincronizaciones de Mercado Libre, los interruptores de modo sólo lectura y creación automática, las publicaciones que fallan al empujar stock, el stock bajo, los productos sin stock en ningún depósito vendible, las órdenes de Mercado Libre sin venta y las últimas ventas de integraciones. Permite destrabar y reactivar publicaciones y forzar una sincronización.
- Ese panel considera "stock bajo" a **menos de 3 unidades**, con el 3 escrito a mano en el código, medido contra el **depósito de Mercado Libre**, y sólo para productos que ya tienen algo de stock (más de cero).
- El dato real de negocio **"Punto Reposición"** existe en el sistema, pero modelado como una **lista de precios más** (la lista id 14), porque así se importó del archivo del negocio. Conceptualmente no es un precio y ensucia todo selector de lista de precios de la aplicación. La brecha ya está anotada como pendiente en la documentación de dominio, esperando exactamente esta spec.
- La **campanita de notificaciones** existe en la barra superior pero está oculta y con contenido de demostración del template.
- Los depósitos vigentes son **Local** (id 5) y **Full** (id 6). `ml_configuracion.deposito_id` apunta a **Local** y `deposito_full_id` a Full — es decir, "el depósito de Mercado Libre" **es** el Local, no un depósito aparte.

## Clarifications

### Session 2026-08-21

Las cuatro decisiones de mayor impacto se resolvieron con el usuario **antes** de redactar la spec
(quedan en Assumptions). Las que siguen son las ambigüedades que quedaban abiertas tras la redacción;
todas tenían un default razonable, así que se resolvieron sin volver a interrumpir, según la regla de
encadenamiento del proyecto:

- Q: Eliminado el umbral fijo de 3, ¿con qué criterio se arma el bloque de "sin stock publicable en Mercado Libre"? → A: **El mismo punto de reposición del producto, pero contra Local + Full** (ver corrección abajo). Es la única forma de cumplir FR-011 sin reintroducir un número inventado, y hace que el número que el negocio define una vez sirva para los dos controles. El bloque se ordena por urgencia real (velocidad de venta de las últimas dos semanas), como ya lo hacía.
- **CORRECCIÓN (misma sesión, detectada en `/speckit-analyze`)**: la redacción original decía que los dos controles se evaluaban contra "el depósito Local" y "el depósito de Mercado Libre" **como si fueran dos depósitos distintos**. Se verificó contra la base: `ml_configuracion.deposito_id = 5`, que **es** el depósito Local. Los únicos depósitos que existen hoy son **Local (5)** y **Full (6)** — el "Depósito Tiendanube" que figuraba en relevamientos anteriores ya no está. Con la definición original las dos listas habrían sido **la misma lista**, una de ellas apenas filtrada por "publicado en Mercado Libre", y el negocio habría recibido dos bloques redundantes creyendo que miraba dos cosas. La distinción real y útil es **Full**:
  - **A reponer** = stock en **Local** ≤ punto de reposición → hay que comprarle al proveedor o traer de Full. Aplica a **todo** el catálogo, esté publicado o no.
  - **Riesgo de publicación** = producto **publicado en Mercado Libre** cuyo stock **Local + Full** ≤ punto de reposición → no hay de dónde vender y la publicación se cae. Un producto con 1 en Local y 50 en Full necesita reposición del Local pero **no** corre riesgo de perder la publicación; uno con 1 en Local y 0 en Full corre los dos riesgos.
- Q: ¿Un permiso o dos? → A: **Dos** — `ver` (pantalla, desplegable y notificaciones) y `gestionar` (destrabar, reactivar, forzar sincronización, editar punto de reposición desde el panel). Un encargado puede necesitar mirar sin poder tocar la integración.
- Q: ¿Qué rol recibe los permisos nuevos al implementar? → A: **Sólo Admin.** Los roles Vendedor y Contable no los reciben; asignarlos es una decisión posterior del negocio.
- Q: ¿Cada cuánto se refresca el contador de la campanita y del indicador de monitoreo? → A: **Al cargar cada pantalla, y cada 5 minutos mientras la pestaña esté abierta.** Alcanza para una alerta de reposición y para una sincronización que corre cada 5 minutos, sin castigar al servidor.
- Q: ¿La edición del punto de reposición desde el panel requiere además permiso de edición de productos? → A: **No**, alcanza con el permiso de gestión de Monitoreo. Es el único dato del producto que el panel toca y separarlo obligaría a dar permisos de producto a quien sólo repone.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Ver y destrabar las publicaciones de Mercado Libre que no actualizan stock (Priority: P1)

El encargado necesita saber, en cualquier momento, qué publicaciones de Mercado Libre quedaron desincronizadas: el stock que muestra la publicación no es el que tiene el negocio. Entra al panel de Monitoreo (o lo ve sin entrar, desde el desplegable de la barra superior), identifica cuáles fallan, distingue las que dependen de él de las que frenó la moderación de Mercado Libre, y las vuelve a poner en cola sin salir de la pantalla.

**Why this priority**: es la métrica que el negocio marcó como imprescindible. Una publicación desincronizada vende algo que no hay (sobreventa, cancelación y penalización en Mercado Libre) o deja de vender algo que sí hay. Es el único bloque del panel que ya se estaba usando a diario.

**Independent Test**: se puede probar sola dejando una publicación con error de stock registrado y verificando que aparece listada con su motivo, su antigüedad, su stock real contra el último publicado, y que la acción de destrabar la deja encolada para el próximo empuje.

**Acceptance Scenarios**:

1. **Given** una publicación con error al empujar stock y varios intentos fallidos, **When** el usuario abre el panel de Monitoreo, **Then** la ve listada con el identificador de la publicación, el título, el stock actual del negocio, el último stock publicado, la cantidad de intentos, desde cuándo falla y el texto del error.
2. **Given** una publicación cuyo error proviene de la moderación de Mercado Libre (bajo revisión o prohibida), **When** el usuario mira la lista, **Then** está marcada de forma distinguible como "no hay nada que hacer desde el CRM" y no se le ofrece la acción de destrabar como si fuera a resolver algo.
3. **Given** una publicación bloqueada por reintentos fallidos, **When** el usuario ejecuta la acción de reactivar, **Then** se limpia el bloqueo y el error, la publicación queda encolada, la tabla se actualiza en el lugar y aparece un aviso de éxito, **sin que la página se recargue**.
4. **Given** que ninguna publicación está fallando, **When** el usuario abre el panel, **Then** ve un estado vacío explícito ("todas las publicaciones sincronizadas") y no una tabla vacía sin explicación.

---

### User Story 2 - Punto de reposición como atributo del producto (Priority: P2)

El responsable de compras define, por producto, cuál es la cantidad mínima que quiere tener en el depósito Local. Ese número deja de vivir disfrazado de lista de precios y pasa a ser un campo propio del producto, editable tanto desde la ficha del producto como directamente desde el panel de Monitoreo cuando está revisando qué reponer.

**Why this priority**: es el cimiento de todo lo demás — sin este campo, la alerta de reposición y las notificaciones no tienen contra qué comparar, y el panel sigue usando un 3 inventado que no distingue un producto que sale todos los días de uno que rota una vez al año.

**Independent Test**: se puede probar sola cargando un punto de reposición en un producto desde su ficha, confirmando que persiste, que el valor importado del archivo del negocio quedó migrado al campo nuevo, y que la lista de precios "Punto Reposición" ya no aparece en ningún selector de listas de precios ni como columna del listado de productos.

**Acceptance Scenarios**:

1. **Given** un producto sin punto de reposición definido, **When** el usuario carga el valor 5 desde la ficha del producto y guarda, **Then** el valor queda persistido y el producto pasa a evaluarse contra ese número.
2. **Given** los productos que traían un valor en la lista de precios "Punto Reposición", **When** se aplica la migración de datos, **Then** cada uno conserva ese mismo valor en el campo nuevo del producto y la lista de precios deja de existir.
3. **Given** que la lista de precios "Punto Reposición" fue eliminada, **When** el usuario abre cualquier pantalla que ofrezca elegir una lista de precios (cliente, configuración de canales, listado de productos), **Then** esa lista ya no figura entre las opciones ni como columna.
4. **Given** un producto de tipo servicio o un producto inactivo, **When** el usuario intenta usar el punto de reposición, **Then** el campo no aplica: no se controla stock y el producto nunca genera alerta de reposición.
5. **Given** un producto listado en el panel de Monitoreo por estar en punto de reposición, **When** el usuario corrige su punto de reposición desde el propio panel, **Then** el valor se guarda y la fila se reevalúa en el lugar, sin recargar la página.
6. **Given** un intento de guardar un punto de reposición negativo o no numérico, **When** el usuario guarda, **Then** se rechaza con un mensaje claro y el valor anterior se conserva.

---

### User Story 3 - Panel de Monitoreo rediseñado, con permiso y acceso propio (Priority: P3)

El panel deja de ser una pantalla escondida de uso interno y pasa a ser una pantalla más del CRM: con su permiso, su link visible, y construida con las mismas reglas visuales que el resto (tablas con paginación y búsqueda cargadas por demanda, edición en modales, avisos por notificación flotante). El encargado ve de un vistazo el estado de las integraciones y, separados, los dos controles de stock: qué hay que **reponer** (contra el depósito Local) y qué está por quedarse **sin stock publicable** (contra el depósito de Mercado Libre).

**Why this priority**: es lo que convierte una herramienta de diagnóstico improvisada en una pantalla operativa para el negocio. Depende de la historia 2 para el control de reposición, pero el resto del panel funciona sin ella.

**Independent Test**: se puede probar sola entrando con un usuario que tiene el permiso (ve la pantalla y sus acciones), con uno que no lo tiene (no ve el link y el acceso directo por URL se rechaza), y verificando que cada bloque carga sus datos y que ninguna acción recarga la página.

**Acceptance Scenarios**:

1. **Given** un usuario **sin** el permiso de monitoreo, **When** intenta entrar a la pantalla por su URL, **Then** el acceso se rechaza y el link no aparece en su barra superior.
2. **Given** un usuario **con** el permiso, **When** abre la pantalla, **Then** ve el estado de las dos sincronizaciones (hace cuánto corrieron, su resultado, y una alerta visible si alguna lleva más del máximo tolerado sin correr) y el estado de los interruptores de modo sólo lectura y creación automática.
3. **Given** el panel abierto, **When** el usuario mira los bloques de stock, **Then** encuentra **dos listas separadas y rotuladas distinto**: productos a reponer (stock del depósito Local por debajo o igual a su punto de reposición) y productos en riesgo de quedarse sin stock publicable en Mercado Libre.
4. **Given** una lista con muchos productos, **When** el usuario la recorre, **Then** puede paginar, buscar y ordenar sin que la pantalla se recargue, y sin que la carga inicial traiga el catálogo entero.
5. **Given** el panel abierto, **When** el usuario fuerza una sincronización, **Then** recibe un aviso flotante con el resultado y el estado se actualiza en el lugar.
6. **Given** que el panel falla al obtener alguno de sus bloques, **When** el usuario lo abre, **Then** el resto de la pantalla sigue funcionando y sólo ese bloque muestra su error.

---

### User Story 4 - Acceso rápido desde la barra superior (Priority: P4)

Sin entrar a ninguna pantalla, el usuario ve en la barra superior un indicador de monitoreo. Al abrirlo, un desplegable le resume lo importante: cuántas publicaciones de Mercado Libre no están actualizando el stock, cuántos productos están en punto de reposición, y si alguna sincronización está atrasada o en error. Desde ahí entra a la pantalla completa.

**Why this priority**: es lo que hace que el problema se vea sin que nadie tenga que acordarse de ir a mirar. Depende de las historias 1 a 3 para tener qué mostrar.

**Independent Test**: se puede probar sola provocando cada una de las tres condiciones y verificando que el desplegable las refleja y que el link lleva a la pantalla completa.

**Acceptance Scenarios**:

1. **Given** publicaciones fallando, productos en punto de reposición y una sincronización atrasada, **When** el usuario abre el desplegable, **Then** ve los tres bloques con su conteo y una muestra acotada de los casos más relevantes de cada uno.
2. **Given** el desplegable abierto, **When** el usuario elige uno de los casos o el link general, **Then** llega a la pantalla completa de Monitoreo posicionado en el bloque correspondiente.
3. **Given** que no hay ningún problema, **When** el usuario abre el desplegable, **Then** ve un estado "todo en orden" y el indicador no está resaltado.
4. **Given** un usuario sin el permiso de monitoreo, **When** carga cualquier pantalla, **Then** no ve el indicador ni su desplegable.
5. **Given** el desplegable, **When** el usuario lo mira, **Then** **no** incluye las órdenes de Mercado Libre sin venta (eso vive sólo en la pantalla completa).

---

### User Story 5 - Notificaciones de reposición y de fallas de publicación (Priority: P5)

La campanita de la barra superior, hoy oculta y con contenido de ejemplo, se activa y avisa dos cosas: que un producto llegó a su punto de reposición o quedó por debajo, y que una publicación de Mercado Libre está fallando. El usuario puede marcarlas como leídas para que el contador no le siga mostrando lo mismo que ya vio, y una alerta que se resuelve deja de figurar sola.

**Why this priority**: es la capa proactiva. Aporta valor real sólo cuando las tres historias anteriores existen, pero es lo que el negocio pidió para no depender de que alguien mire el panel.

**Independent Test**: se puede probar sola llevando un producto por debajo de su punto de reposición, verificando que aparece la notificación y suma al contador; marcándola leída y verificando que el contador baja; y reponiendo el stock para verificar que la notificación desaparece por su cuenta.

**Acceptance Scenarios**:

1. **Given** un producto cuyo stock en el depósito Local cae hasta su punto de reposición o por debajo, **When** el usuario abre la campanita, **Then** ve una notificación que identifica el producto, su stock actual y su punto de reposición.
2. **Given** una publicación de Mercado Libre que empieza a fallar al actualizar stock, **When** el usuario abre la campanita, **Then** ve una notificación que identifica la publicación y el motivo.
3. **Given** notificaciones sin leer, **When** el usuario carga cualquier pantalla, **Then** el contador de la campanita muestra cuántas hay sin leer.
4. **Given** una notificación sin leer, **When** el usuario la marca como leída (individualmente o con "marcar todas"), **Then** deja de contar para **ese** usuario, y sigue contando para los demás usuarios que no la leyeron.
5. **Given** una notificación ya leída cuyo problema se resuelve (se repone el stock, la publicación vuelve a sincronizar), **When** el usuario vuelve a abrir la campanita, **Then** la notificación ya no está.
6. **Given** un producto que se repuso y semanas después vuelve a caer por debajo de su punto de reposición, **When** el usuario abre la campanita, **Then** la notificación vuelve a aparecer **como no leída** (el "leído" anterior no la silencia para siempre).
7. **Given** un usuario sin el permiso de monitoreo, **When** carga cualquier pantalla, **Then** no recibe ninguna de estas notificaciones.

---

### Edge Cases

- **Producto sin punto de reposición definido**: no genera alerta ni notificación de reposición. El sistema no inventa un valor por defecto para todo el catálogo (serían miles de alertas el primer día).
- **Punto de reposición en cero**: se interpreta como "no controlar este producto", igual que no tenerlo definido.
- **Producto en cero absoluto**: si el stock Local es cero y tiene punto de reposición definido, está en punto de reposición (es el caso más urgente, no una excepción que lo saca de la lista).
- **Servicios y productos inactivos**: quedan fuera de todos los controles de stock, sin excepción.
- **La lista de precios "Punto Reposición" está referenciada por otra configuración** (por ejemplo, la lista de precios de un canal de venta o de un cliente): la eliminación **no debe** dejar esas referencias rotas ni borrar en cascada un precio de venta real. Si existe una referencia, el proceso se detiene e informa en lugar de borrar.
- **Valores no enteros migrados desde la lista de precios** (venían de un campo pensado para importes con decimales): se convierten al entero correspondiente; si un valor no puede interpretarse como cantidad, el producto queda sin punto de reposición y el caso se informa.
- **Producto con stock en Full pero cero en Local**: aparece en el control de reposición (hay que traerlo o comprarlo), pero **no** en el de riesgo de publicación ni como "sin stock para vender", porque en Full sí hay de dónde vender. Es exactamente el caso que distingue los dos bloques.
- **Muchos productos por debajo del punto de reposición** (cientos): las listas del panel y el desplegable no pueden degradarse; el desplegable muestra una muestra acotada y el conteo total.
- **Sincronización que nunca corrió** (sin fecha registrada): se trata como atrasada, no como correcta.
- **Dos usuarios mirando a la vez**: que uno marque leída una notificación no la marca para el otro.
- **Cambio del punto de reposición desde el panel**: si el producto deja de estar en punto de reposición por esa edición, su notificación asociada debe dejar de figurar.
- **Stock negativo en un depósito**: se trata como el caso más urgente posible (está por debajo de cualquier punto de reposición), no como un dato a ignorar.
- **Producto sin registro de stock en el depósito evaluado** (nunca tuvo un movimiento ahí): se interpreta como stock cero, no como "sin dato". Si tiene punto de reposición, entra en el control.
- **Producto sin ningún movimiento de stock registrado**: no se puede determinar desde cuándo está en punto de reposición; su aviso se trata como un episodio único mientras la condición se mantenga.
- **Productos con variantes**: el stock puede llevarse por variante. El punto de reposición es del producto y se compara contra el **total del producto en ese depósito** (suma de sus variantes), no variante por variante.
- **"Marcar todas como leídas" con alertas apareciendo en el medio**: sólo se marcan las que el usuario tenía a la vista; una alerta que apareció después sigue contando como no leída.
- **El usuario pierde el permiso con la sesión abierta**: en el siguiente refresco deja de recibir datos y los indicadores desaparecen, sin romper la pantalla en la que esté.
- **Integración con Mercado Libre desconectada o sin depósito configurado**: los bloques que dependen de ella informan esa condición como causa, en vez de mostrarse vacíos como si todo estuviera bien.

## Requirements *(mandatory)*

### Functional Requirements

#### Punto de reposición

- **FR-001**: El sistema DEBE incorporar el **punto de reposición** como atributo propio del producto: una cantidad entera, mayor o igual a cero, opcional (puede no estar definida).
- **FR-002**: El usuario DEBE poder ver y editar el punto de reposición de un producto desde la ficha/formulario del producto, junto al resto de sus datos de stock.
- **FR-003**: El usuario DEBE poder editar el punto de reposición desde el propio panel de Monitoreo, sin salir de la pantalla ni recargarla, y la fila DEBE reevaluarse con el valor nuevo.
- **FR-004**: El sistema DEBE rechazar valores negativos o no numéricos, informando el motivo y conservando el valor previo.
- **FR-005**: El sistema DEBE migrar al campo nuevo los valores de punto de reposición que hoy están guardados como precios de la lista de precios "Punto Reposición", preservando el valor de cada producto.
- **FR-006**: Tras la migración, el sistema DEBE eliminar la lista de precios "Punto Reposición" y sus precios asociados, de modo que deje de ofrecerse como lista de precios en cualquier pantalla, selector, columna de listado o exportación.
- **FR-007**: Antes de eliminar esa lista de precios, el sistema DEBE verificar que ninguna otra configuración la referencie; si alguna lo hace, DEBE detenerse e informar sin borrar nada.
- **FR-008**: La migración DEBE informar un resumen verificable: cuántos productos recibieron valor, cuántos quedaron sin valor y cuáles no se pudieron interpretar.
- **FR-009**: Un producto está **en punto de reposición** cuando su stock en el **depósito Local** es menor o igual a su punto de reposición, siempre que éste esté definido y sea mayor a cero.
- **FR-010**: Sólo se evalúan productos que controlan stock (tipo producto) y están activos.
- **FR-010a**: Un producto sin registro de stock en el depósito evaluado DEBE tratarse como stock cero. Un stock negativo DEBE tratarse como el caso más urgente. En productos con variantes, la comparación DEBE hacerse contra el total del producto en ese depósito.
- **FR-011**: El sistema NO DEBE seguir usando el umbral fijo de 3 unidades para determinar stock bajo; queda reemplazado por el punto de reposición de cada producto, **tanto para el control de reposición (stock Local) como para el de stock publicable (stock Local + Full)**.
- **FR-011a**: Un producto sin punto de reposición definido (o en cero) NO DEBE aparecer en ninguno de los dos controles de stock, aunque su stock sea bajo o cero.

#### Panel de Monitoreo

- **FR-012**: El panel de Monitoreo DEBE estar protegido por un **permiso propio de visualización**; sin él, ni el acceso directo por URL ni el link son posibles.
- **FR-013**: Las acciones de escritura del panel (destrabar, reactivar, forzar sincronización, editar punto de reposición) DEBEN estar protegidas por un **permiso de gestión** distinto del de sólo lectura. Editar el punto de reposición desde el panel NO DEBE exigir además permiso de edición de productos.
- **FR-013a**: Los dos permisos nuevos DEBEN otorgarse inicialmente **sólo al rol Admin**; los demás roles no los reciben.
- **FR-014**: El panel DEBE mostrar el estado de las dos sincronizaciones de Mercado Libre (órdenes y stock): hace cuánto corrió cada una, su resultado, y una alerta visible cuando supera el tiempo máximo tolerado sin correr o nunca corrió.
- **FR-015**: El panel DEBE mostrar el estado de los interruptores de modo sólo lectura y de creación automática de ventas.
- **FR-016**: El panel DEBE listar las **publicaciones de Mercado Libre que no logran actualizar su stock**, con: identificador de publicación, título, producto asociado, stock actual, último stock publicado, cantidad de intentos fallidos, desde cuándo falla, texto del error, si está bloqueada y si el motivo es moderación de Mercado Libre.
- **FR-017**: El panel DEBE distinguir visualmente los errores de moderación (bajo revisión / prohibida), sobre los que no hay acción posible desde el CRM.
- **FR-018**: El panel DEBE listar, **en un bloque separado**, los **productos a reponer** (en punto de reposición según FR-009), con producto, stock Local, punto de reposición, cuánto falta para alcanzarlo y su proveedor habitual si lo tiene.
- **FR-019**: El panel DEBE listar, **en otro bloque separado**, los **productos en riesgo de quedarse sin stock publicable en Mercado Libre**: publicados en Mercado Libre y con stock **Local + Full** por debajo o igual a su punto de reposición. Este bloque DEBE ordenarse por urgencia real, estimada a partir de lo vendido en las últimas dos semanas (un producto que sale todos los días urge antes que uno que no rota).
- **FR-019a**: Los dos bloques DEBEN mostrar el desglose de stock que justifica su presencia (Local y Full por separado), para que se entienda por qué un producto está en uno y no en el otro.
- **FR-020**: El panel DEBE seguir mostrando las órdenes de Mercado Libre sin venta asociada con su motivo, las últimas ventas de integraciones con sus movimientos de stock, y los productos publicados sin stock en ningún depósito vendible.
- **FR-021**: El panel DEBE permitir destrabar una publicación (encolarla para el próximo empuje), reactivar una bloqueada por reintentos fallidos, y forzar una sincronización, informando en cada caso el resultado.
- **FR-022**: Todos los listados del panel DEBEN cargarse por demanda y permitir paginar, buscar y ordenar sin recargar la página; ninguna acción del panel puede recargar la página.
- **FR-023**: Todo aviso de resultado (éxito, error, advertencia) DEBE mostrarse como notificación flotante, no como recarga ni cuadro de diálogo del navegador.
- **FR-024**: La falla de un bloque del panel NO DEBE impedir que el resto de la pantalla funcione.
- **FR-024a**: Cada bloque DEBE tener su propio estado vacío explicativo; ninguno puede quedar como una tabla en blanco sin explicación.
- **FR-024b**: Cuando la integración con Mercado Libre está desconectada o sin depósito configurado, los bloques que dependen de ella DEBEN informar esa condición como causa, en lugar de presentarse vacíos.
- **FR-024c**: El panel DEBE seguir siendo legible en pantalla de teléfono, que es desde donde más se lo consulta hoy.

#### Barra superior

- **FR-025**: La barra superior DEBE ofrecer un acceso a Monitoreo, visible sólo para usuarios con permiso de visualización.
- **FR-026**: Ese acceso DEBE abrir un desplegable con exactamente tres bloques: publicaciones de Mercado Libre que no actualizan stock, productos en punto de reposición, y estado de las sincronizaciones; cada uno con su conteo y una muestra acotada.
- **FR-027**: El desplegable NO DEBE incluir las órdenes de Mercado Libre sin venta.
- **FR-028**: El desplegable DEBE ofrecer navegación a la pantalla completa, posicionada en el bloque elegido.
- **FR-029**: El acceso DEBE indicar visualmente cuando hay algo que atender, y presentarse neutro cuando no lo hay.

#### Notificaciones

- **FR-030**: El sistema DEBE activar el indicador de notificaciones de la barra superior, reemplazando su contenido de demostración.
- **FR-031**: El sistema DEBE generar una notificación por cada producto en punto de reposición y por cada publicación de Mercado Libre que falla al actualizar stock.
- **FR-032**: Las notificaciones se DEBEN determinar a partir del estado vigente en cada consulta; el sistema NO DEBE mantener un histórico de notificaciones pasadas.
- **FR-033**: El sistema DEBE persistir, **por usuario**, qué notificaciones vigentes fueron leídas, y el contador DEBE reflejar sólo las no leídas de ese usuario.
- **FR-034**: El usuario DEBE poder marcar una notificación como leída y marcar todas como leídas.
- **FR-035**: Cuando la condición que originó una notificación deja de cumplirse, la notificación DEBE desaparecer sin intervención del usuario, y su marca de lectura DEBE descartarse, de modo que si la condición vuelve a darse la notificación reaparezca **como no leída**.
- **FR-036**: Las notificaciones DEBEN entregarse sólo a usuarios con permiso de visualización de Monitoreo. Si un usuario pierde el permiso con la sesión abierta, los indicadores DEBEN desaparecer en el siguiente refresco sin romper la pantalla en la que esté.
- **FR-036a**: "Marcar todas como leídas" DEBE afectar únicamente a las notificaciones que el usuario tenía a la vista; una alerta surgida después sigue contando como no leída.
- **FR-037**: Cada notificación DEBE permitir navegar al lugar donde se resuelve (el producto, o el bloque de publicaciones del panel).
- **FR-037a**: El contador de la campanita y el del indicador de Monitoreo DEBEN actualizarse al cargar cada pantalla y refrescarse automáticamente cada 5 minutos mientras la pestaña permanezca abierta, sin recargar la página.

#### Documentación y consistencia

- **FR-038**: La documentación de dominio y el modelo de datos DEBEN actualizarse: incorporar el punto de reposición como atributo del producto, cerrar la brecha ya anotada al respecto, dejar constancia de la eliminación de la lista de precios "Punto Reposición", y documentar la pantalla de Monitoreo (que hoy no figura por haber nacido como pantalla interna).

### Key Entities

- **Producto**: suma el atributo **punto de reposición** — cantidad mínima deseada en el depósito Local. Opcional; sin valor (o en cero) el producto no se controla.
- **Lista de precios "Punto Reposición"**: entidad **a eliminar**. Hoy contiene el dato real importado del negocio; su contenido migra al atributo del producto y luego deja de existir.
- **Estado de reposición** (derivado, no almacenado): la comparación entre el stock del depósito Local de un producto y su punto de reposición.
- **Estado de publicación de Mercado Libre** (ya existente): error de actualización de stock, antigüedad del error, intentos fallidos, bloqueo por intervención y último stock publicado.
- **Estado de sincronización** (ya existente): momento y resultado de la última corrida de órdenes y de stock, más los interruptores generales de la integración.
- **Notificación** (derivada del estado vigente, no histórica): un aviso identificable de forma estable mientras la condición se mantenga, para poder recordar que un usuario ya lo leyó.
- **Marca de lectura por usuario**: la única parte persistida de las notificaciones — qué aviso vigente leyó cada usuario. Se descarta cuando el aviso deja de estar vigente.
- **Permisos de Monitoreo**: uno de visualización (pantalla, desplegable y notificaciones) y uno de gestión (acciones de escritura).

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: El 100% de los productos que tenían un punto de reposición cargado en el archivo del negocio conserva exactamente ese valor tras la migración, y la lista de precios "Punto Reposición" deja de aparecer en cualquier pantalla, selector o exportación de listas de precios.
- **SC-002**: Ningún control de stock del sistema usa un umbral fijo: cada producto se evalúa contra su propio punto de reposición.
- **SC-003**: Un usuario detecta que hay publicaciones de Mercado Libre desincronizadas **sin entrar a ninguna pantalla**, desde cualquier lugar del sistema, en un solo vistazo a la barra superior.
- **SC-004**: Desde que un usuario decide revisar el estado hasta que destraba una publicación fallida pasan menos de 30 segundos y ninguna recarga de página.
- **SC-005**: El panel completo queda utilizable con el catálogo real del negocio (más de 8.000 productos) sin que ninguna lista tarde en responder más de lo que tarda hoy cualquier otro listado del sistema.
- **SC-006**: Un producto que cae por debajo de su punto de reposición aparece notificado en la campanita en la siguiente interacción del usuario con el sistema, sin que nadie haya tenido que abrir el panel.
- **SC-007**: Un problema resuelto (stock repuesto, publicación sincronizada) desaparece solo de las notificaciones y del panel, sin que ningún usuario tenga que descartarlo a mano.
- **SC-008**: Un problema que aparece mientras el usuario ya tiene el sistema abierto se refleja en su barra superior en 5 minutos o menos, sin que haya navegado a ninguna pantalla nueva.
- **SC-009**: Un usuario sin permiso de Monitoreo no ve el link, ni el desplegable, ni las notificaciones, ni puede entrar por URL; y uno con permiso de sólo lectura no puede ejecutar ninguna acción de escritura.

## Assumptions

- **Alcance del acceso**: el link a Monitoreo vive en la **barra superior**, no en el menú lateral, tal como lo pidió el negocio. No se agrega una entrada al menú lateral.
- **Punto de reposición sin valor por defecto**: los productos que no tenían valor en la lista de precios quedan sin punto de reposición y no generan alertas. Poblar el resto del catálogo es una decisión de negocio posterior, fuera de esta spec.
- **El control de reposición se mide contra el depósito Local**, no contra el stock total: es la señal de "hay que comprarle al proveedor o traer de Full". El control de stock publicable se mantiene aparte, contra **Local + Full** y sólo sobre productos publicados en Mercado Libre. Esta separación es una decisión explícita del negocio. Ambos controles usan **el mismo número** (el punto de reposición del producto): un solo dato a mantener, dos preguntas distintas respondidas. Ver la corrección en `## Clarifications` sobre por qué el segundo control **no** puede definirse "contra el depósito de Mercado Libre".
- **Los depósitos vigentes son dos**: Local (id 5) y Full (id 6). El "Depósito Tiendanube" que figura en relevamientos anteriores ya no existe en la base, y `ml_configuracion.deposito_id` apunta a Local.
- **Cantidad entera**: el punto de reposición se expresa en unidades enteras. Los valores decimales heredados de haber vivido en un campo de precios se convierten al migrar.
- **Sin histórico de notificaciones**: se descartó una tabla de eventos con historial. Las notificaciones se calculan sobre el estado vigente y sólo se guarda el "leído" por usuario. En consecuencia, no hay forma de consultar "qué me avisaste la semana pasada" — decisión explícita del negocio a cambio de no acumular basura ni depender de un proceso programado que genere eventos.
- **Aislamiento del panel**: se mantiene el criterio de que el panel de Monitoreo no arrastre al resto del CRM si falla, en la medida en que sea compatible con las reglas de diseño obligatorias del proyecto y con leer el punto de reposición del producto.
- **Los depósitos Local, Full y Tiendanube ya existen** y no se crean ni renombran en esta feature.
- **Se conservan las capacidades actuales del panel** (órdenes sin venta, últimas ventas, productos publicados sin stock, interruptores): el rediseño no es una amputación, es un rediseño.
- **Umbral de sincronización atrasada**: se mantiene el criterio vigente de considerar atrasada una sincronización que lleva más de 15 minutos sin correr.
- **Fuera de alcance**: notificaciones por correo o push fuera del navegador; sugerencia u orden de compra automática a partir del punto de reposición; punto de reposición por depósito (es uno solo por producto); histórico de notificaciones.
