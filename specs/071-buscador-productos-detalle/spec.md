# Feature Specification: Buscador de productos del detalle con foco persistente

**Feature Branch**: `071-buscador-productos-detalle`

**Created**: 2026-08-19

**Status**: Draft

**Input**: User description: "Reemplazar Select2 por un widget buscador propio SÓLO en el campo de carga de productos al detalle (`#f-producto`) de los formularios de Venta, Compra y Presupuesto, para que el foco no se pierda entre ítem e ítem. El cliente quiere: escribís → aparecen opciones; elegís una → se agrega al detalle, se cierra el panel de opciones, el buscador se vacía PERO mantiene el foco y sigue visible, para seguir tipeando el siguiente producto."

## Contexto y motivo del cambio

Los formularios de **Venta**, **Compra** y **Presupuesto** tienen, arriba del detalle, un campo para
buscar un producto/servicio y agregarlo como línea. La carga real de una operación implica repetir
esa acción muchas veces seguidas (un comprobante típico tiene varias líneas), así que la fluidez de
ese campo es el cuello de botella de toda la pantalla.

Hoy ese campo se comporta así: al elegir un producto, la línea se agrega y **el desplegable completo
se vuelve a abrir** mostrando la lista de opciones. Eso se hizo a propósito, porque es la única forma
que tiene el componente actual de devolverle el foco al usuario — su campo de búsqueda sólo existe
mientras el desplegable está abierto. El resultado es que después de cada carga queda una lista
desplegada tapando parte de la pantalla.

El cliente pidió otro comportamiento: que el buscador sea un campo de texto **siempre visible**, que
conserve el foco **independientemente** de si el panel de opciones está abierto o cerrado. Al elegir
un producto, el panel se cierra y el texto se borra, pero el cursor sigue en el buscador listo para
el ítem siguiente.

**Esta feature NO cambia ninguna regla de negocio**: no toca cómo se calculan precios, IVA,
descuentos, stock ni totales del detalle. Es exclusivamente el comportamiento de interacción del
campo que agrega líneas.

## Alcance: qué hace hoy exactamente ese buscador *(verificado en el código)*

Es importante fijarlo porque el nombre del campo en pantalla sugiere más de lo que el campo hace.
El buscador de productos de las 3 pantallas hoy **sólo**:

1. Busca en el catálogo de productos/servicios a medida que se tipea.
2. Muestra las coincidencias, cada una identificada por identificador, nombre y código (cuando lo
   tiene).
3. Al elegir una, agrega esa línea al detalle con los datos que correspondan al comprobante (precio
   de venta o costo de compra, IVA, según la pantalla).

**Lo que ese buscador NO hace hoy** (y que esta feature tampoco agrega — ver "Fuera de alcance"):

- No ofrece crear un producto nuevo desde el propio buscador, **a pesar de que la etiqueta del campo
  dice "Seleccionar o Crear Producto/Servicio"**. Esa capacidad sí existe para el selector de
  Cliente, pero nunca se implementó para el de productos.
- No permite editar un producto desde una fila de resultados. La edición de un producto ya cargado se
  hace desde el menú ▾ de la fila del detalle, que es una parte distinta de la pantalla y no se toca
  en esta feature.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Cargar varios productos seguidos sin interrupciones (Priority: P1)

Un usuario que está cargando una Venta (o Compra o Presupuesto) con varias líneas escribe parte del
nombre de un producto, lo elige, y sigue escribiendo el siguiente inmediatamente — sin volver a
hacer clic en ningún lado y sin que le quede un panel de opciones abierto tapando el detalle.

**Why this priority**: Es el pedido concreto del cliente y el motivo entero de la feature. Es
también la interacción más repetida de las tres pantallas de carga más usadas del sistema.

**Independent Test**: Abrir el formulario de Venta, escribir un término en el buscador de productos,
elegir un resultado, y verificar sin tocar el mouse que (a) la línea se agregó al detalle, (b) el
panel de opciones se cerró, (c) el buscador quedó vacío, (d) el cursor sigue en el buscador — se
puede escribir el término siguiente directamente.

**Acceptance Scenarios**:

1. **Given** el formulario de Venta abierto con el detalle vacío, **When** el usuario escribe un
   término en el buscador de productos, **Then** aparece debajo del buscador un panel con los
   productos que coinciden.
2. **Given** el panel de opciones abierto con resultados, **When** el usuario elige una opción (con
   clic o con Enter), **Then** el producto se agrega como línea del detalle, el panel se cierra, el
   texto del buscador se borra y el buscador conserva el foco.
3. **Given** que el usuario acaba de agregar un producto y el buscador quedó vacío y con foco,
   **When** empieza a escribir un término nuevo sin hacer clic en ningún lado, **Then** el panel se
   vuelve a abrir con los resultados del término nuevo.
4. **Given** que el usuario agregó varios productos seguidos, **When** mira la pantalla, **Then** no
   quedó ningún panel de opciones abierto tapando el detalle.
5. **Given** el mismo flujo, **When** se repite en Compra y en Presupuesto, **Then** el
   comportamiento es idéntico al de Venta.

---

### User Story 2 - La búsqueda encuentra exactamente lo mismo que antes (Priority: P1)

Un usuario que ya conoce el sistema busca productos como siempre (por nombre, por código, por
fragmentos) y obtiene los mismos resultados, en el mismo orden y con la misma información visible en
cada fila que antes del cambio.

**Why this priority**: Es una condición de no-regresión explícita del usuario del proyecto ("lo que
más me importa es que funcione igual y filtre de la misma manera"). Un cambio de comportamiento de
búsqueda sería percibido como una rotura, no como una mejora, y afectaría la carga diaria.

**Independent Test**: Tomar una lista de términos de búsqueda representativos (nombre exacto,
fragmento de nombre, código de producto, término con varias palabras) y verificar que devuelven el
mismo conjunto de resultados, en el mismo orden, que el buscador anterior.

**Acceptance Scenarios**:

1. **Given** un término de búsqueda cualquiera, **When** el usuario lo escribe en el buscador nuevo,
   **Then** obtiene el mismo conjunto de productos, en el mismo orden, que obtenía con el buscador
   anterior.
2. **Given** un producto con código cargado, **When** aparece como resultado, **Then** su fila
   muestra la misma información que antes (identificador, nombre y código).
3. **Given** que el usuario está tipeando rápido, **When** escribe varias letras seguidas, **Then**
   el sistema no consulta el catálogo una vez por tecla, sino que espera a que la escritura se
   detenga brevemente (mismo comportamiento que antes).
4. **Given** el formulario de Venta o Presupuesto con una lista de precios seleccionada, **When** el
   usuario elige un producto, **Then** el precio que se carga en la línea corresponde a esa lista,
   igual que antes.
5. **Given** el formulario de Compra, **When** el usuario elige un producto, **Then** la línea se
   carga con el costo de compra y la lógica de IVA propia de Compra, igual que antes.

---

### User Story 3 - Operar el buscador enteramente con el teclado (Priority: P2)

Un usuario que carga comprobantes todo el día opera el buscador sin soltar el teclado: escribe,
recorre las opciones con las flechas, confirma con Enter y descarta el panel con Escape.

**Why this priority**: Complementa y hace efectivo el objetivo de fluidez de US1 — conservar el foco
sólo rinde de verdad si además se puede elegir sin ir al mouse. Va en P2 porque la feature ya es
usable con mouse aunque esto se valide después.

**Independent Test**: Sin usar el mouse en ningún momento: escribir un término, bajar con la flecha
hasta una opción, confirmarla con Enter, verificar que se agregó al detalle y que el foco sigue en el
buscador.

**Acceptance Scenarios**:

1. **Given** el panel abierto con resultados, **When** el usuario presiona flecha abajo o flecha
   arriba, **Then** se resalta la opción siguiente o anterior de la lista.
2. **Given** una opción resaltada, **When** el usuario presiona Enter, **Then** se agrega ese
   producto al detalle y aplica todo lo descrito en US1 (panel cerrado, buscador vacío y con foco).
3. **Given** el panel abierto, **When** el usuario presiona Escape, **Then** el panel se cierra, el
   buscador conserva el foco y el texto tipeado no se pierde.

### Edge Cases

- **Sin resultados**: si el término no coincide con ningún producto, el panel se muestra igual con un
  mensaje explícito de que no hubo coincidencias (no queda un panel vacío ni se cierra en silencio,
  que se confundiría con "todavía buscando").
- **Búsqueda en curso**: mientras se está consultando el catálogo, el panel indica que está buscando,
  para que el usuario no crea que no hay resultados.
- **Falla de la consulta**: si la búsqueda no se puede completar (por ejemplo, un corte de red), el
  usuario recibe un aviso y el buscador queda utilizable para reintentar; no se agrega ninguna línea
  ni se pierde lo cargado en el comprobante.
- **Respuestas fuera de orden**: si el usuario tipea rápido y una consulta anterior responde después
  de una posterior, el panel muestra los resultados del término vigente, no los de la consulta vieja.
- **Clic fuera del widget**: el panel se cierra sin agregar nada; el texto tipeado se mantiene.
- **Tab / salir del campo**: el panel se cierra y el foco pasa al control siguiente del formulario;
  no se agrega ninguna línea automáticamente.
- **Enter sin ninguna opción resaltada**: no se agrega nada y el panel permanece como está (no se
  elige "la primera" por accidente, para no cargar un producto equivocado).
- **Producto ya presente en el detalle**: se comporta igual que hoy (se agrega otra línea); esta
  feature no introduce deduplicación.
- **Elegir dos veces muy rápido**: dos confirmaciones seguidas agregan dos líneas (una por
  confirmación), sin que una "pise" a la otra ni se pierda alguna.
- **Formulario en modo edición**: el buscador se comporta igual al cargar líneas nuevas sobre un
  comprobante que ya tenía líneas.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: El campo para agregar productos/servicios al detalle en los formularios de Venta,
  Compra y Presupuesto DEBE ser un campo de texto permanentemente visible que puede tener el foco de
  forma independiente de que el panel de opciones esté abierto o cerrado.
- **FR-002**: Al escribir en el buscador, el sistema DEBE mostrar debajo un panel con los productos
  que coinciden con el término, consultando el catálogo sólo después de una breve pausa en la
  escritura (no una consulta por tecla).
- **FR-003**: Al elegir una opción de producto, el sistema DEBE, en un solo paso: agregar el producto
  como línea del detalle, cerrar el panel de opciones, vaciar el texto del buscador y conservar el
  foco en el buscador.
- **FR-004**: Después de agregar un producto, el usuario DEBE poder escribir el término siguiente
  directamente, sin hacer clic ni ninguna otra acción intermedia, y el panel DEBE volver a abrirse
  con los resultados nuevos.
- **FR-005**: El conjunto de resultados, su orden y la información visible de cada fila (identificador,
  nombre y código cuando existe) DEBEN ser idénticos a los que devuelve el buscador actual para el
  mismo término y la misma lista de precios.
- **FR-006**: Los datos con los que se arma la línea al elegir un producto (precio o costo según la
  pantalla, alícuota de IVA, descripción, cantidad inicial) DEBEN ser exactamente los mismos que hoy,
  incluida la lógica propia de cada pantalla (lista de precios en Venta y Presupuesto; costo de
  compra e IVA condicionado al tipo de comprobante en Compra).
- **FR-007**: El buscador DEBE ser operable con teclado: flechas arriba/abajo para recorrer las
  opciones, Enter para aplicar la opción resaltada, Escape para cerrar el panel conservando el foco y
  el texto tipeado.
- **FR-008**: El panel DEBE cerrarse cuando el usuario hace clic fuera del widget o saca el foco del
  campo, sin agregar ninguna línea.
- **FR-009**: Cuando la búsqueda no devuelve coincidencias, el panel DEBE indicarlo explícitamente en
  lugar de quedar vacío o cerrarse en silencio.
- **FR-010**: Mientras una búsqueda está en curso, el panel DEBE indicar que se está buscando.
- **FR-011**: Cuando la búsqueda falla, el sistema DEBE avisarle al usuario y dejar el buscador
  utilizable, sin perder lo ya cargado en el comprobante.
- **FR-012**: Si llegan respuestas de búsquedas desordenadas respecto de cómo se dispararon, el panel
  DEBE mostrar siempre los resultados correspondientes al término vigente del buscador.
- **FR-013**: El cambio DEBE aplicarse únicamente al campo de carga de productos al detalle de Venta,
  Compra y Presupuesto. Todos los demás selectores de datos dinámicos del sistema (incluidos el de
  Cliente, el de Proveedor, el de Categoría y el de Vendedor de esos mismos formularios) DEBEN quedar
  sin cambios.
- **FR-014**: El comportamiento del buscador DEBE ser el mismo en los tres formularios (Venta, Compra
  y Presupuesto), sin diferencias de interacción entre ellos.
- **FR-015**: El buscador DEBE quedar visualmente coherente con el resto de los controles del
  formulario (misma altura y tipografía que los demás campos compactos del sistema).
- **FR-016**: El buscador DEBE exponer semántica de accesibilidad equivalente o mejor que la del
  componente que reemplaza (identificación del campo como buscador con lista de sugerencias asociada
  y la opción resaltada anunciable).
- **FR-017**: Esta feature NO DEBE modificar ninguna regla de negocio del detalle: cálculo de precio,
  IVA, bonificaciones, totales, afectación de stock y validaciones del comprobante quedan exactamente
  como están.
- **FR-018**: El menú ▾ de cada fila del detalle (Ver / Editar producto) DEBE seguir funcionando
  exactamente como hoy; no forma parte del widget que se reemplaza.

### Fuera de alcance (explícito)

- **Crear un producto nuevo desde el buscador**: hoy no existe en el buscador de productos, aunque la
  etiqueta del campo diga "Seleccionar o Crear Producto/Servicio". Esta feature **no lo agrega**: es
  una capacidad nueva, no una regresión a evitar, y agregarla acá mezclaría dos cambios distintos en
  la misma entrega. Queda registrado como brecha pendiente para una spec futura, junto con la
  decisión de qué hacer con la etiqueta que hoy la promete.
- **Editar un producto desde una fila del panel de resultados**: tampoco existe hoy en este buscador
  (sí existe para el selector de Cliente y desde el menú ▾ de la fila del detalle, que no se tocan).
- Cualquier cambio en el servicio de catálogo que alimenta las sugerencias (criterio de búsqueda,
  orden, cantidad máxima de resultados).

### Key Entities

Esta feature no introduce entidades nuevas ni modifica las existentes. Consume el catálogo de
**Producto/Servicio** ya existente (identificador, nombre, código, precio según lista de precios,
costo de compra, alícuotas de IVA de venta y de compra) exclusivamente en modo lectura para poblar
las sugerencias y para armar la línea del detalle.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Un usuario puede cargar 10 productos consecutivos al detalle usando únicamente el
  teclado, sin ninguna interacción de mouse entre un producto y el siguiente.
- **SC-002**: Después de agregar un producto, el buscador queda vacío y con el foco en el 100% de los
  casos, y no queda ningún panel de opciones abierto.
- **SC-003**: Para un conjunto de términos de búsqueda representativos, el buscador nuevo devuelve
  exactamente los mismos productos, en el mismo orden, que el buscador anterior (0 diferencias).
- **SC-004**: Para un mismo producto elegido, la línea que se agrega al detalle queda con exactamente
  los mismos valores (descripción, cantidad, precio/costo e IVA) que antes del cambio, en las 3
  pantallas.
- **SC-005**: El tiempo entre que el usuario deja de escribir y ve los resultados no empeora respecto
  del buscador actual.
- **SC-006**: Ningún otro selector del sistema cambia de comportamiento como consecuencia de esta
  feature.
- **SC-007**: Los tres estados no-felices del panel (buscando, sin resultados, error de consulta) son
  distinguibles entre sí para el usuario.

## Assumptions

- El origen de datos de las sugerencias (el servicio de catálogo de productos que ya alimenta al
  buscador actual, con su búsqueda flexible, su orden y su tope de resultados) **no se modifica**;
  esta feature sólo cambia la interfaz que lo consume. Por eso la equivalencia de resultados de
  FR-005/SC-003 es verificable de forma directa.
- El widget se construye a medida en lugar de adoptar otra librería de terceros: el comportamiento
  pedido (foco del campo independiente del panel) no lo ofrece el componente actual por diseño, y el
  alcance acotado a 3 pantallas no justifica incorporar una dependencia nueva al proyecto. Decisión
  ya tomada con el usuario del proyecto.
- El widget se implementa una sola vez y se reutiliza en los 3 formularios, en lugar de duplicarse
  por pantalla — condición para que FR-014 (comportamiento idéntico en los tres) se sostenga en el
  tiempo. Cada pantalla sigue aportando su propia lógica de "qué línea armar" con el producto
  elegido, que es lo único que legítimamente difiere entre ellas (FR-006).
- La apariencia exacta no necesita replicar la del componente actual: el usuario del proyecto indicó
  explícitamente que prioriza el comportamiento y la paridad de búsqueda por sobre el estilo, siempre
  que quede coherente con el resto del formulario (FR-015).
- El sistema se usa principalmente en escritorio con teclado y mouse; el comportamiento en pantallas
  táctiles es el mismo, sin adaptaciones específicas en esta feature.
- Esta feature constituye una **excepción documentada** a la regla general del proyecto de usar el
  componente estándar de selects con buscador para todo dato dinámico. La excepción se limita a este
  caso y su justificación (el requisito de foco persistente) queda registrada; la regla general sigue
  vigente para el resto del sistema.
- La guía de desarrollo del proyecto documenta hoy la solución vigente de "reabrir el desplegable"
  para este caso de carga en lote; esa documentación deberá actualizarse como parte de esta feature
  para no quedar contradiciendo el comportamiento nuevo.
