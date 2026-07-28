# Feature Specification: Ventas de Mercado Libre — listado, vinculación de publicaciones y conversión a Venta del CRM

**Feature Branch**: `012-ventas-mercadolibre`

**Created**: 2026-07-27

**Status**: Draft

**Input**: User description: "Integración Mercado Libre — Etapa 2: Ventas de Mercado Libre. Nueva pantalla 'Mercado Libre' dentro del módulo Ingresos, que lista las órdenes de venta sincronizadas desde Mercado Libre y permite convertir cada una en una Venta del CRM, de forma manual o automática."

## Contexto y fuentes

Esta spec es la **etapa 2** del módulo de integración con Mercado Libre. Continúa directamente
`specs/011-mercadolibre-conexion-oauth/`, que quedó implementado y **probado de punta a punta el
27/07/2026**: cuenta real vinculada, autorización funcionando, cliente de API con renovación
automática de credenciales, kill-switch de sólo lectura e historial de operaciones. Ese circuito se
validó con una venta real simulada (`MERCADOLIBRE_NOTAS_TECNICAS.md` §6).

La etapa 1 excluyó explícitamente *"el ingreso de ventas/órdenes de Mercado Libre al CRM"*
(`spec 011`, sección Alcance). **Eso es exactamente lo que cubre esta spec.**

**Divergencia respecto de Contagram**: la de la spec 011 sigue vigente y está documentada en
`docs/documentacion_principal_crm.md §5.2` (aplicación propia del negocio con permisos de lectura y
escritura, en vez del acceso de sólo lectura de Contagram).

**⚠️ Corrección (27/07/2026)**: una versión anterior de esta spec afirmaba que *"no existe una
pantalla de Contagram que calcar"*, porque el relevamiento con capturas
(`docs/informe_contagram_funciones_avanzadas.md` §3) no llegó a completar el flujo de Mercado Libre.
**Eso era incorrecto.** Al consultar el centro de ayuda oficial se confirmó que Contagram **sí tiene
la pantalla y está documentada**:

- [¿Cómo funciona la integración con MercadoLibre?](https://help.contagram.com/es/articles/10922610-como-funciona-la-integracion-con-mercadolibre)
- [¿Dónde veo las ventas que provienen de MercadoLibre?](https://help.contagram.com/es/articles/10922778-donde-veo-las-ventas-que-provienen-de-mercadolibre)
- [¿Cómo integro mi cuenta de MercadoLibre a Contagram?](https://help.contagram.com/es/articles/10922769-como-integro-mi-cuenta-de-mercadolibre-a-contagram)

**Estructura real de Contagram, ahora sí verificada**, y su correspondencia con esta spec:

| Contagram | Esta spec | ¿Coincide? |
|---|---|---|
| Menú **Ingresos → MercadoLibre** | Ídem (FR-001, FR-002) | ✅ |
| Órdenes listadas en estado **Pendiente** | Estados de conversión (FR-007a) | ✅ (se amplía a cinco estados) |
| Se **selecciona un cliente** existente o se crea uno nuevo | FR-036, FR-037 | ✅ |
| Se **elige o crea el producto** correspondiente | Vinculación publicación↔producto (FR-021) | ✅ (se agrega persistencia del vínculo) |
| Se abre el formulario **Nueva Venta** para guardar, facturar y cobrar | FR-028, FR-029 | ✅ |
| Filtro **"Creada Desde → MercadoLibre"** en el listado de Ventas | FR-035 | ✅ (ver FR-035a) |
| Botón **"Ver mis Órdenes"** en la tarjeta de Funciones Avanzadas | — | Se agrega (FR-002a) |
| Conversión **sólo manual** | Manual **+ automática** | ⚠️ Divergencia deliberada (ver abajo) |

El diseño de esta spec **coincide estructuralmente** con Contagram en todo lo relevante; lo que agrega
—vinculación persistente, creación automática, configuración de depósito y frecuencia— son ampliaciones
pedidas explícitamente por el usuario, no reinterpretaciones. La **creación automática de ventas** es
la única divergencia funcional real: Contagram documenta únicamente el flujo manual.

**Fuentes de dominio**: `docs/documentacion_principal_crm.md` (§2.1 Clientes — campo "Apodo ML";
§2.2 Productos; §3.2 Ventas; §3.7 Tesorería; §5.1 Funciones Avanzadas; §5.2 Integración Mercado
Libre) y `docs/modelo_datos.md` (§5 `ventas`, `venta_items`, `cobros`).

## Alcance

**Incluye**: la sincronización de órdenes de venta desde Mercado Libre hacia el CRM, la pantalla de
listado dentro de Ingresos, la vinculación persistente entre publicaciones de Mercado Libre y
productos del CRM (con pantalla propia de administración), la conversión de una orden en una Venta
del CRM —manual o automática—, la generación automática de la cobranza asociada, el descuento de
stock, y la configuración de todo lo anterior.

**Excluye explícitamente**:

- **Sincronización de stock del CRM hacia Mercado Libre** → **spec 013**, encadenada inmediatamente
  después de ésta. Ver la sección "Riesgo conocido: sobreventa" más abajo. Esta spec deja construida
  la tabla de vinculación publicación↔producto sobre la que la 013 se apoya.
- Comisión de Mercado Libre y costo de envío a cargo del vendedor (spec posterior).
- Importación masiva de publicaciones, sincronización de precios, preguntas, mensajería, envíos y
  webhooks de negocio.

### Riesgo conocido: sobreventa (dependencia con la spec 013)

Mientras la spec 013 no exista, el flujo de stock es **unidireccional**: las ventas que llegan de
Mercado Libre descuentan stock en el CRM, pero **una venta manual cargada en el CRM no le avisa a
Mercado Libre**. El resultado es que Mercado Libre sigue ofreciendo unidades que ya no están
físicamente disponibles, produciendo **sobreventa**. Este riesgo es conocido, aceptado de forma
transitoria, y es la razón de ser de la spec 013. Debe quedar advertido en la propia pantalla de
configuración mientras la sincronización inversa no esté disponible.

## Clarifications

### Session 2026-07-27

Ambigüedades detectadas en el escaneo de cobertura y resueltas por decisión fundamentada, sin
interrumpir al usuario (las decisiones de negocio ya se habían acordado antes de redactar la spec):

- Q: ¿Cuál es el conjunto canónico de estados de conversión de una orden en el CRM? → A: cinco estados excluyentes — **Pendiente de pago**, **Lista para convertir**, **Requiere atención** (con motivo), **Convertida**, **Cancelada**. Sin este conjunto cerrado, el listado, los filtros y las reglas de habilitación de "Crear Venta" quedaban sin definición verificable.
- Q: ¿Los precios que informa Mercado Libre son netos o finales con IVA? → A: **finales con IVA incluido**; el sistema desagrega el neto usando el IVA por defecto del producto vinculado. Se promueve de supuesto a requisito (FR-030a) por afectar el cálculo de todas las líneas de todas las ventas de Mercado Libre.
- Q: ¿Qué política de retención aplica a las órdenes sincronizadas? → A: **sin purga automática**. Son respaldo de documentos con impacto contable y muchas tienen una Venta asociada. La retención acotada aplica sólo al historial de operaciones de la spec 011, que sí es diagnóstico y de alto volumen.
- Q: ¿Qué pasa si la conversión manual y la automática toman la misma orden a la vez? → A: la conversión debe adquirir un **bloqueo exclusivo por orden**; la segunda espera y encuentra la orden ya convertida, informándolo. La sola verificación previa no alcanza porque deja una ventana de carrera que duplicaría venta, cobranza y movimiento de stock.
- Q: ¿Cómo se distingue terminológicamente lo que viene de Mercado Libre de lo que vive en el CRM? → A: **"orden"** designa siempre el documento sincronizado desde Mercado Libre; **"Venta"** (mayúscula) designa siempre el documento del CRM. Se evita "venta de Mercado Libre" como nombre de entidad por ser ambiguo entre ambos.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Ver las órdenes de Mercado Libre en el CRM (Priority: P1)

Como responsable del negocio, entro a Ingresos → Mercado Libre y veo el listado de las órdenes de
venta de mi cuenta de Mercado Libre, cada una con su estado, fecha, comprador, productos vendidos y
monto. Puedo forzar la actualización del listado con un botón, sin esperar a la sincronización
programada.

**Why this priority**: es la base de todo lo demás. Sin traer y mostrar las órdenes no hay nada que
convertir. Entrega valor por sí sola: ver las ventas de Mercado Libre dentro del CRM, sin entrar a
Mercado Libre.

**Independent Test**: se puede probar con la cuenta ya vinculada, presionando "Sincronizar ahora" y
verificando que aparecen las órdenes reales de la cuenta con sus datos correctos, sin necesidad de
convertir ninguna a Venta.

**Acceptance Scenarios**:

1. **Given** la función Mercado Libre activa y una cuenta conectada, **When** el usuario entra a Ingresos → Mercado Libre, **Then** ve el listado de órdenes sincronizadas con su estado, fecha, comprador, monto y productos.
2. **Given** el listado abierto, **When** el usuario presiona "Sincronizar ahora", **Then** el sistema trae las órdenes nuevas y actualizadas de Mercado Libre, informa por notificación cuántas incorporó, y refresca el listado sin recargar la página.
3. **Given** órdenes en distintos estados, **When** el usuario mira el listado, **Then** distingue con claridad cuáles están pagadas, cuáles pendientes de pago, cuáles canceladas, cuáles ya se convirtieron en Venta del CRM y cuáles requieren atención.
4. **Given** una orden ya convertida en Venta, **When** el usuario la mira en el listado, **Then** ve un acceso directo a la Venta del CRM correspondiente.
5. **Given** la función Mercado Libre desactivada, **When** el usuario busca la opción en el menú Ingresos, **Then** la entrada no aparece.
6. **Given** un usuario sin permiso sobre el módulo Ingresos, **When** intenta acceder a la pantalla, **Then** el sistema le deniega el acceso.
7. **Given** una sincronización ya ejecutada, **When** el usuario vuelve a sincronizar, **Then** las órdenes ya conocidas se actualizan en lugar de duplicarse.

---

### User Story 2 - Vincular publicaciones de Mercado Libre con productos del CRM (Priority: P1)

Como responsable del negocio, vinculo cada publicación de Mercado Libre con el producto del CRM que
le corresponde, para que las ventas descuenten el stock del producto correcto. Puedo hacerlo sobre la
marcha, cuando estoy convirtiendo una orden, o desde una pantalla dedicada donde veo y administro
todas las vinculaciones existentes.

**Why this priority**: sin la vinculación, una orden no puede convertirse en una Venta que descuente
stock correctamente. Es requisito de la historia 3. Además es la infraestructura sobre la que se
apoya la spec 013 (sincronización de stock), por lo que su valor excede a esta spec.

**Independent Test**: se puede probar vinculando una publicación con un producto y verificando que el
vínculo persiste, se muestra en la pantalla de vinculaciones y se reutiliza automáticamente en la
siguiente orden de esa misma publicación.

**Acceptance Scenarios**:

1. **Given** una orden cuya publicación no está vinculada, **When** el usuario abre la conversión a Venta, **Then** el sistema le señala qué línea no tiene producto y le ofrece elegirlo con un selector con buscador.
2. **Given** el usuario elige un producto para una publicación, **When** guarda, **Then** el vínculo queda persistido y se aplica automáticamente en todas las órdenes futuras de esa publicación, sin volver a preguntarlo.
3. **Given** vinculaciones existentes, **When** el usuario entra a la pantalla de vinculación de publicaciones, **Then** ve el listado completo con la publicación, el producto vinculado y la fecha del vínculo, y puede editarlo o eliminarlo.
4. **Given** una publicación ya vinculada a un producto, **When** el usuario intenta vincular esa misma publicación a un segundo producto, **Then** el sistema lo impide e informa que la relación es de uno a uno.
5. **Given** un producto ya vinculado a una publicación, **When** el usuario intenta vincularlo a una segunda publicación, **Then** el sistema lo impide e informa que la relación es de uno a uno.
6. **Given** el usuario elimina una vinculación, **When** existen órdenes ya convertidas que la usaron, **Then** las Ventas ya creadas no se modifican y el sistema advierte que las órdenes futuras de esa publicación quedarán sin resolver.

---

### User Story 3 - Convertir manualmente una orden en una Venta del CRM (Priority: P1)

Como responsable del negocio, desde el menú de fila de una orden pagada elijo "Crear Venta" y el
sistema me abre el formulario de Venta ya precargado con el cliente, los productos, las cantidades y
los precios de la orden de Mercado Libre. Reviso, ajusto si hace falta y guardo. La Venta queda
registrada, cobrada y con el stock descontado.

**Why this priority**: es el objetivo central de la spec — el motivo por el que el usuario pidió esta
funcionalidad. Junto con las historias 1 y 2 constituye el producto mínimo utilizable.

**Independent Test**: se puede probar tomando una orden pagada con su publicación ya vinculada,
convirtiéndola, y verificando que la Venta resultante tiene el cliente, los productos y el total
correctos, que figura cobrada y que el stock bajó.

**Acceptance Scenarios**:

1. **Given** una orden pagada con todas sus publicaciones vinculadas, **When** el usuario elige "Crear Venta", **Then** el sistema presenta el formulario de Venta precargado con cliente, productos, cantidades, precios y tipo de comprobante derivado.
2. **Given** el formulario precargado, **When** el usuario guarda, **Then** el sistema crea la Venta, genera la cobranza asociada dejándola cobrada, descuenta el stock del depósito configurado, y vincula la orden con la Venta creada.
3. **Given** una Venta creada desde una orden, **When** el usuario la abre en el listado de Ventas, **Then** el total coincide exactamente con el monto de la orden de Mercado Libre.
4. **Given** una orden ya convertida, **When** el usuario intenta convertirla otra vez, **Then** el sistema lo impide e informa que ya existe una Venta asociada.
5. **Given** una orden no pagada o cancelada, **When** el usuario mira su menú de fila, **Then** la acción "Crear Venta" no está disponible y el motivo es visible.
6. **Given** el comprador no existe como Cliente del CRM, **When** se convierte la orden, **Then** el sistema crea el Cliente automáticamente con los datos que expone Mercado Libre y lo asocia a la Venta.
7. **Given** el comprador ya existe como Cliente (identificado por su apodo de Mercado Libre), **When** se convierte la orden, **Then** el sistema reutiliza ese Cliente en lugar de duplicarlo.
8. **Given** una Venta creada desde una orden, **When** el usuario la edita después, **Then** puede modificarla como cualquier otra Venta del CRM.

---

### User Story 4 - Configurar la sincronización y su comportamiento (Priority: P2)

Como responsable del negocio, configuro desde la pantalla de Mercado Libre cada cuánto se sincronizan
las órdenes, desde qué depósito se descuenta el stock de estas ventas, y si las ventas se crean solas
o las convierto yo a mano.

**Why this priority**: la sincronización manual de la historia 1 ya entrega valor; la programación y
sus opciones son la capa que hace el módulo desatendido. Alto valor, pero depende de que exista lo
anterior.

**Independent Test**: se puede probar cambiando la frecuencia y el depósito, verificando que
persisten y que la sincronización programada respeta el intervalo elegido.

**Acceptance Scenarios**:

1. **Given** la pantalla de configuración de Mercado Libre, **When** el usuario elige una frecuencia de sincronización, **Then** el valor se guarda y la sincronización programada pasa a ejecutarse con ese intervalo, sin necesidad de cambios técnicos ni de acceso al servidor.
2. **Given** la configuración, **When** el usuario selecciona el depósito de las Ventas originadas en Mercado Libre, **Then** las Ventas creadas desde órdenes descuentan stock de ese depósito.
3. **Given** varios depósitos existentes, **When** el usuario no elige ninguno explícitamente, **Then** el sistema usa el depósito por defecto del CRM e informa cuál está usando.
4. **Given** la sincronización programada activa, **When** transcurre el intervalo configurado, **Then** el sistema trae las órdenes nuevas sin intervención del usuario.
5. **Given** una sincronización programada en curso, **When** se dispara otra, **Then** sólo una se ejecuta y la otra se descarta, sin duplicar órdenes ni ventas.
6. **Given** el modo sólo lectura activo o la función Mercado Libre desactivada, **When** corresponde ejecutar una sincronización, **Then** no se ejecuta, queda registrada en el historial de operaciones y el motivo es visible en pantalla.
7. **Given** la sincronización de stock hacia Mercado Libre todavía no disponible, **When** el usuario mira la pantalla de configuración, **Then** ve una advertencia explícita sobre el riesgo de sobreventa.

---

### User Story 5 - Crear las ventas automáticamente (Priority: P2)

Como responsable del negocio, activo "Creación automática de ventas" y a partir de ahí cada venta de
Mercado Libre se convierte sola en una Venta del CRM, cobrada y con el stock descontado, sin que yo
tenga que hacer nada. Si alguna no se puede crear por falta de datos, queda señalada para que yo la
resuelva.

**Why this priority**: es la automatización que el usuario pidió explícitamente, pero requiere que la
conversión manual (historia 3) esté probada y sea confiable antes de dejarla correr sin supervisión.

**Independent Test**: se puede probar activando el interruptor, sincronizando con una orden pagada y
vinculada, y verificando que la Venta aparece creada sin intervención.

**Acceptance Scenarios**:

1. **Given** la creación automática activa y una orden pagada con todas sus publicaciones vinculadas, **When** la sincronización la detecta, **Then** el sistema crea la Venta, la cobranza y el movimiento de stock automáticamente, y lo refleja en el listado.
2. **Given** la creación automática activa y una orden pagada con alguna publicación **sin vincular**, **When** la sincronización la detecta, **Then** **NO** crea la Venta, marca la orden como que requiere atención indicando el motivo, y no descuenta stock.
3. **Given** una orden marcada como que requiere atención por falta de vinculación, **When** el usuario vincula la publicación faltante, **Then** la orden queda apta para convertirse, manualmente o en la siguiente sincronización automática.
4. **Given** la creación automática **desactivada**, **When** la sincronización detecta órdenes pagadas, **Then** las incorpora al listado pero no crea ninguna Venta.
5. **Given** la creación automática activa, **When** se crea una Venta automáticamente, **Then** queda registrado que fue creada de forma automática y en qué momento.
6. **Given** una Venta creada automáticamente, **When** el usuario la revisa, **Then** puede editarla como cualquier otra Venta del CRM.
7. **Given** la creación automática activa y una falla al crear una Venta, **When** ocurre el error, **Then** la orden queda marcada con el motivo, no se crea una Venta a medias, y el error queda registrado.

---

### User Story 6 - Enterarse de cancelaciones y reembolsos posteriores (Priority: P3)

Como responsable del negocio, si una orden de Mercado Libre que ya ingresé al CRM después se cancela
o se reembolsa, quiero verlo señalado en el listado para decidir yo qué ajuste hacer.

**Why this priority**: es una salvaguarda de consistencia, no un flujo principal. El volumen esperado
es bajo y el ajuste es manual por decisión explícita del usuario.

**Independent Test**: se puede probar simulando el cambio de estado de una orden ya convertida y
verificando que el listado lo refleja y la Venta permanece intacta.

**Acceptance Scenarios**:

1. **Given** una orden ya convertida en Venta, **When** la sincronización detecta que se canceló o reembolsó en Mercado Libre, **Then** el listado lo refleja de forma destacada y la Venta del CRM **no** se modifica.
2. **Given** una orden convertida que cambió de estado, **When** el usuario la mira, **Then** ve tanto el estado actual en Mercado Libre como el acceso a la Venta del CRM, para poder decidir el ajuste.
3. **Given** una orden **no** convertida que se cancela, **When** la sincronización lo detecta, **Then** el listado lo refleja y la acción de convertirla deja de estar disponible.

---

### Edge Cases

- **Orden con una publicación que tiene variantes**: no está soportado. La línea debe marcarse como no resoluble y la orden requerir atención, en lugar de vincularse a un producto equivocado en silencio.
- **Más de un Cliente del CRM con el mismo apodo de Mercado Libre**: el emparejamiento es ambiguo. El sistema no debe elegir uno al azar: marca la orden como que requiere atención para que el usuario resuelva cuál corresponde.
- **La orden se convierte mientras el modo sólo lectura está activo**: la conversión es una operación **interna del CRM** (crear una Venta), no una escritura hacia Mercado Libre, por lo que debe permitirse. Lo que el modo sólo lectura bloquea son las escrituras hacia Mercado Libre.
- **Un producto vinculado se elimina o se inactiva en el CRM**: las órdenes futuras de esa publicación quedan sin resolver y deben marcarse como que requieren atención, con el motivo visible.
- **La orden trae un producto con stock insuficiente en el depósito configurado**: la venta ya ocurrió en Mercado Libre, no puede rechazarse. La Venta se crea igual y el stock queda en negativo o se advierte, según la regla vigente del CRM, pero nunca se pierde la venta.
- **Una orden agrupa varias unidades de la misma publicación**: la cantidad de la línea debe respetarse tal como la informa Mercado Libre.
- **Una orden contiene varias publicaciones distintas**: la Venta resultante debe tener una línea por cada una.
- **La sincronización se interrumpe a mitad de camino** (corte de red, límite de tiempo del servidor): las órdenes ya procesadas no deben reprocesarse ni duplicarse en la corrida siguiente.
- **Mercado Libre rechaza la consulta por exceso de solicitudes**: la sincronización debe reintentar con espera creciente y retomar donde quedó, sin perder órdenes.
- **La conexión con Mercado Libre está caída** (requiere re-vinculación): la sincronización no debe ejecutarse ni marcar órdenes como fallidas; debe informar que hay que re-vincular la cuenta.
- **Mercado Libre no devuelve los datos fiscales del comprador**: el sistema asume consumidor final y continúa, sin bloquear la conversión.
- **La orden llega con un monto que no coincide con la suma de sus líneas** (por descuentos o promociones de Mercado Libre): el total de la Venta debe respetar el monto real de la orden.
- **Órdenes de prueba de Mercado Libre**: se tratan igual que cualquier otra orden por decisión explícita del usuario. Con la creación automática activa, generan Ventas reales y descuentan stock real (ver Riesgos).
- **La primera sincronización sobre una cuenta con historial**: debe acotarse para no arrastrar años de órdenes antiguas de golpe.

## Requirements *(mandatory)*

### Functional Requirements — Pantalla y listado

- **FR-001**: El sistema DEBE ofrecer una pantalla "Mercado Libre" dentro del módulo Ingresos, accesible desde el menú lateral, que liste las órdenes de venta sincronizadas desde Mercado Libre.
- **FR-002**: El sistema DEBE mostrar esa entrada del menú **únicamente** cuando la función avanzada "Mercado Libre" esté activa, replicando el patrón de Abonos (`docs §3.4`).
- **FR-002a**: El sistema DEBE ofrecer, dentro de la tarjeta "Mercado Libre" de Funciones Avanzadas y con la cuenta conectada, un acceso directo **"Ver mis Órdenes"** hacia la pantalla del listado, tal como lo hace Contagram (artículo *"¿Cómo integro mi cuenta de MercadoLibre a Contagram?"*). Es el mismo patrón del botón "Ir al listado de Abonos" ya relevado (`informe_contagram_funciones_avanzadas.md` §6.1).
- **FR-003**: El sistema DEBE restringir el acceso a la pantalla mediante el permiso del módulo Ingresos, denegándolo a quien no lo tenga.
- **FR-004**: El sistema DEBE presentar el listado con carga por demanda desde el servidor, con paginación, ordenamiento, búsqueda y panel de filtros, conforme a las reglas de diseño obligatorias del proyecto.
- **FR-005**: El sistema DEBE mostrar por cada orden, como mínimo: identificador de la orden en Mercado Libre, fecha, comprador, publicaciones y cantidades vendidas, monto total, estado en Mercado Libre, estado de conversión en el CRM y acceso a la Venta creada cuando exista.
- **FR-006**: El sistema DEBE permitir filtrar el listado por estado de la orden, estado de conversión y rango de fechas.
- **FR-007**: El sistema DEBE distinguir visualmente las órdenes que requieren atención, indicando el motivo concreto por el que no pueden convertirse.
- **FR-007a**: El sistema DEBE clasificar cada orden en **exactamente uno** de estos cinco estados de conversión, mutuamente excluyentes:

  | Estado | Significado | ¿Habilita "Crear Venta"? |
  |---|---|---|
  | **Pendiente de pago** | La orden existe en Mercado Libre pero el pago aún no está confirmado | No |
  | **Lista para convertir** | Pagada, con todas sus líneas resolubles y comprador inequívoco | Sí |
  | **Requiere atención** | Pagada, pero algo impide resolverla (ver FR-052) | No, hasta resolver el motivo |
  | **Convertida** | Ya generó una Venta del CRM | No (ya fue) |
  | **Cancelada** | Cancelada o reembolsada en Mercado Libre | No |

- **FR-007b**: El sistema DEBE registrar, en las órdenes en estado "Requiere atención", el **motivo concreto** que las bloquea, de modo que el usuario sepa qué acción tomar sin investigar.
- **FR-008**: El sistema DEBE identificar en el listado las órdenes marcadas como de prueba por Mercado Libre.

### Functional Requirements — Sincronización de órdenes

- **FR-009**: El sistema DEBE ofrecer una acción manual "Sincronizar ahora" que traiga las órdenes nuevas y actualice las ya conocidas, informando el resultado por notificación sin recargar la página.
- **FR-010**: El sistema DEBE ejecutar la sincronización de forma programada, con una frecuencia **configurable por el usuario desde la pantalla de configuración**, sin requerir cambios en el código ni acceso al servidor.
- **FR-011**: El sistema DEBE funcionar de forma equivalente en un entorno sin procesos permanentes (hosting compartido, tarea programada del sistema) y en uno con procesamiento diferido en segundo plano (servidor dedicado), sin cambios en el código.
- **FR-012**: El sistema DEBE traer **todas** las órdenes de la cuenta, cualquiera sea su estado, y reflejar ese estado en el listado.
- **FR-012a**: El sistema DEBE consultar las órdenes **canceladas de forma explícita y separada**, porque la búsqueda estándar del vendedor las excluye. Verificado contra la documentación oficial (research §R2). Sin esta segunda consulta, FR-012 y toda la historia 6 quedarían incumplidas.
- **FR-012b**: El sistema DEBE tratar una respuesta parcial del proveedor —aquella que indica explícitamente qué bloques de datos faltan— como válida y no como error, registrando qué información no llegó y marcando la orden como que requiere atención si falta algo imprescindible para convertirla.
- **FR-013**: El sistema DEBE actualizar el estado de las órdenes ya sincronizadas cuando cambie en Mercado Libre, sin duplicarlas.
- **FR-014**: El sistema DEBE garantizar que dos sincronizaciones no se ejecuten simultáneamente: si una está en curso, la siguiente se descarta.
- **FR-015**: El sistema DEBE retomar la sincronización desde el punto en que quedó si una corrida se interrumpe, sin reprocesar ni perder órdenes.
- **FR-016**: El sistema DEBE acotar el alcance de la primera sincronización a un período reciente configurable, para no arrastrar el historial completo de la cuenta de golpe. El período configurable DEBE topearse en **12 meses**, que es el máximo que Mercado Libre conserva (research §R2): permitir más daría la falsa impresión de que se puede recuperar historial más antiguo.
- **FR-017**: El sistema NO DEBE ejecutar la sincronización mientras la función "Mercado Libre" esté desactivada o el modo sólo lectura esté activo, y DEBE registrar el intento bloqueado en el historial de operaciones existente.
- **FR-018**: El sistema NO DEBE ejecutar la sincronización mientras la conexión esté caída o no configurada, informando que se requiere re-vinculación en lugar de acumular errores.
- **FR-019**: El sistema DEBE registrar cada operación de sincronización contra Mercado Libre en el historial de operaciones ya existente, sin incluir datos sensibles.
- **FR-020**: El sistema DEBE aplicar espera creciente ante rechazos por exceso de solicitudes y reintentar un número acotado de veces ante fallas temporales, sin descartar órdenes silenciosamente.

### Functional Requirements — Vinculación publicación ↔ producto

- **FR-021**: El sistema DEBE permitir vincular una publicación de Mercado Libre con un producto del CRM, y persistir ese vínculo para reutilizarlo en todas las órdenes futuras de esa publicación.
- **FR-022**: El sistema DEBE hacer cumplir una relación **estrictamente uno a uno**: una publicación no puede vincularse a más de un producto, ni un producto a más de una publicación. La restricción DEBE garantizarse a nivel de datos, no sólo en la interfaz.
- **FR-023**: El sistema DEBE permitir crear el vínculo **sobre la marcha**, desde el formulario de conversión, mediante un selector con buscador, cuando una línea de la orden no tenga producto asociado.
- **FR-024**: El sistema DEBE ofrecer una pantalla propia de "Vinculación de publicaciones" que liste todos los vínculos existentes y permita crearlos, editarlos y eliminarlos.
- **FR-025**: El sistema DEBE mostrar, por cada vínculo, la publicación de Mercado Libre, el producto del CRM asociado y la fecha en que se estableció.
- **FR-026**: El sistema DEBE conservar intactas las Ventas ya creadas cuando se elimina o modifica un vínculo, advirtiendo que el cambio sólo afecta a las órdenes futuras.
- **FR-027**: El sistema NO DEBE vincular publicaciones que tengan variantes: DEBE rechazarlo informando que no están soportadas, en lugar de asociarlas a un producto de forma ambigua.

### Functional Requirements — Conversión a Venta del CRM

- **FR-028**: El sistema DEBE ofrecer, en el menú de fila de cada orden **pagada y resoluble**, la acción "Crear Venta", que presenta el formulario de Venta precargado con los datos de la orden.
- **FR-029**: El sistema DEBE precargar cliente, productos, cantidades, precios y tipo de comprobante, permitiendo al usuario revisarlos y ajustarlos antes de guardar.
- **FR-030**: El sistema DEBE garantizar que el total de la Venta creada **coincida exactamente** con el monto de la orden de Mercado Libre.
- **FR-030a**: El sistema DEBE tratar los importes que informa Mercado Libre como **precios finales con IVA incluido**, y desagregar el importe neto de cada línea aplicando el IVA por defecto del producto vinculado. La suma de neto más IVA de todas las líneas DEBE reconstruir exactamente el monto de la orden, absorbiendo cualquier diferencia por redondeo sin alterar el total.
- **FR-030b**: El sistema DEBE tratar los productos con IVA "Exento" o "No Gravado" como de tasa cero a los efectos de la desagregación: el neto **es igual** al importe informado, sin división.
- **FR-030c**: El sistema DEBE crear las Ventas originadas en Mercado Libre **sin descuento general ni conceptos extra** (percepciones, impuestos internos, intereses): el precio de cada línea ya viene neto de promociones aplicadas por Mercado Libre, y agregarlos rompería la coincidencia exacta con el monto de la orden.
- **FR-030d**: El sistema DEBE rechazar la conversión de una orden cuya moneda no sea la del negocio, marcándola como que requiere atención, en lugar de convertir importes con una cotización implícita.
- **FR-031**: El sistema DEBE permitir la conversión **únicamente** de órdenes pagadas, deshabilitando la acción con motivo visible en cualquier otro estado.
- **FR-032**: El sistema DEBE garantizar que una orden genere **como máximo una** Venta del CRM: reintentar la conversión sobre una orden ya convertida DEBE rechazarse sin duplicar ventas, cobranzas ni movimientos de stock.
- **FR-032a**: El sistema DEBE serializar la conversión mediante un **bloqueo exclusivo por orden**, de modo que una conversión manual y una automática sobre la misma orden no puedan ejecutarse simultáneamente. La segunda en llegar DEBE encontrar la orden ya convertida e informarlo, en lugar de crear un duplicado. La verificación previa sin bloqueo NO es suficiente: deja una ventana de carrera que duplicaría Venta, cobranza y movimiento de stock.
- **FR-032b**: El sistema DEBE respaldar el bloqueo lógico con una **restricción de unicidad a nivel de datos** sobre la referencia orden→Venta, de modo que ni siquiera la expiración del bloqueo durante una conversión anormalmente lenta pueda producir una Venta duplicada.
- **FR-033**: El sistema DEBE registrar el vínculo entre la orden y la Venta creada, y ofrecer navegación directa entre ambas.
- **FR-034**: El sistema DEBE tratar la Venta resultante como una Venta ordinaria del CRM en todo lo demás: editable, eliminable, con detalle imprimible y con las mismas reglas de negocio.
- **FR-035**: El sistema DEBE identificar las Ventas originadas en Mercado Libre, distinguiéndolas de las creadas manualmente o desde un Presupuesto.
- **FR-035a**: El sistema DEBE exponer ese origen a través de la columna y el filtro **"Creada Desde"** ya existentes en el listado de Ventas (`docs §3.2`), agregando "MercadoLibre" como tercer valor junto a Presupuesto y venta directa. **NO** debe crearse una columna ni un filtro separados: así lo hace Contagram (artículo *"¿Dónde veo las ventas que provienen de MercadoLibre?"*, filtro "Creada Desde - MercadoLibre") y el principio rector de fidelidad estructural lo exige.

### Functional Requirements — Cliente y tipo de comprobante

- **FR-036**: El sistema DEBE emparejar al comprador de la orden con un Cliente existente del CRM usando, **en este orden**: (1) el **identificador de usuario de Mercado Libre** del comprador, que es estable y siempre está presente; (2) si no hay coincidencia, el campo "Apodo ML" ya presente en la ficha de Cliente (`docs §2.1`), para enganchar con los clientes cargados a mano.
- **FR-036a**: El sistema DEBE guardar el identificador de usuario de Mercado Libre en el Cliente la primera vez que lo empareja por apodo, de modo que los emparejamientos siguientes usen la vía estable.

  > **Motivo** (research §R2/§R12): se verificó que el bloque de datos del comprador puede llegar reducido a sólo su identificador, o faltar por completo en respuestas parciales. Emparejar únicamente por apodo fallaría de forma intermitente y silenciosa.
- **FR-037**: El sistema DEBE crear automáticamente un Cliente nuevo, con los datos que expone Mercado Libre, cuando no exista ninguno con ese apodo.
- **FR-038**: El sistema DEBE tratar como **ambiguo** el caso en que más de un Cliente tenga el mismo apodo: NO debe elegir uno arbitrariamente, sino marcar la orden como que requiere atención para que el usuario resuelva.
- **FR-039**: El sistema DEBE **derivar** el tipo de comprobante a partir de la condición frente al IVA del comprador informada por Mercado Libre, sin intervención manual, conforme al principio III de la constitución (el tipo de comprobante se deriva de la condición de IVA, no se elige a mano).
- **FR-040**: El sistema DEBE aplicar la siguiente derivación, basada en la **condición frente al IVA** informada por Mercado Libre:

  | Condición del comprador | Comprobante |
  |---|---|
  | IVA Responsable Inscripto | **A** |
  | Monotributo | **B** |
  | IVA Exento | **B** |
  | Consumidor Final | **B** |
  | Sin datos fiscales informados | **B** (se asume Consumidor Final, ver FR-040a) |

- **FR-040b**: El sistema NO DEBE derivar el tipo de comprobante del **tipo de documento** del comprador, pese a que la documentación de Mercado Libre indica hacerlo así ("CUIT → Factura A, DNI → Factura B").

  > **Motivo**: esa regla es fiscalmente incorrecta en un caso frecuente. **Un Monotributista tiene CUIT**, y bajo esa regla recibiría Factura A cuando fiscalmente le corresponde **B**. El principio III de la constitución exige derivar de la condición de IVA, y ese dato **está disponible** en la respuesta de Mercado Libre, así que usar el documento como sustituto sería degradar la precisión fiscal teniendo el dato correcto a mano. Divergencia deliberada y documentada en research §R8.

- **FR-040c**: El sistema DEBE, únicamente cuando la condición frente al IVA no venga informada pero sí el documento, usar el tipo de documento como aproximación (CUIT → A, DNI/CUIL → B), dejando registrado que fue una derivación aproximada para que el usuario pueda corregirla (FR-043).
- **FR-040a**: El sistema DEBE, cuando asuma consumidor final por falta de datos, **persistir explícitamente la condición de IVA "Consumidor Final"** en el Cliente, en lugar de dejarla vacía. Motivo: el principio III de la constitución prohíbe operar con la condición de IVA sin cargar, y una condición asumida pero no registrada dejaría al Cliente sin el dato del que se derivó su comprobante.
- **FR-040d**: El sistema DEBE completar en todo Cliente creado automáticamente tanto la **Condición de IVA** como el **Tipo de comprobante por defecto**, dejando ambos campos cargados y nunca en blanco.

  > **Fundamento externo**: Contagram documenta que ambos campos son obligatorios para poder facturar y que *"no pueden quedar en blanco"* ([Error por Condición de IVA en Blanco](https://help.contagram.com/es/articles/10922459-error-por-condicion-de-iva-en-blanco)). Crear clientes automáticamente con esos campos vacíos generaría, al retomarse Facturación Electrónica, un lote de clientes imposibles de facturar sin corrección manual uno por uno.
- **FR-041**: El sistema DEBE persistir la condición de IVA y los datos de documento del comprador en el Cliente creado o emparejado, para que la derivación sea trazable y reutilizable.
- **FR-041a**: El sistema NO DEBE sobrescribir la condición de IVA ni los datos fiscales que un Cliente **ya tenía cargados** cuando Mercado Libre informe algo distinto: completa sólo los campos vacíos y, ante discrepancia, usa el dato de Mercado Libre para derivar el comprobante de esa orden dejando la ficha del Cliente intacta. Los datos cargados a mano en el CRM se consideran más confiables que los del comprador en el marketplace.
- **FR-042**: El sistema NO DEBE consultar servicios fiscales externos (ARCA/padrón) para determinar el tipo de comprobante: la fuente son los datos que provee Mercado Libre. La validación local del formato del identificador fiscal se mantiene.
- **FR-043**: El sistema DEBE permitir al usuario corregir el tipo de comprobante en la Venta creada, tanto en la conversión manual como después de una creación automática.

### Functional Requirements — Cobranza y stock

- **FR-044**: El sistema DEBE generar automáticamente la cobranza asociada a la Venta creada, dejándola **cobrada** en el mismo acto, dado que Mercado Libre ya percibió el pago antes de que la orden llegue al CRM.
- **FR-045**: El sistema DEBE imputar esa cobranza a la cuenta de Tesorería correspondiente a Mercado Pago, existente desde el módulo Tesorería (`docs §3.7`).
- **FR-045a**: El sistema DEBE impedir la conversión, informando el motivo con claridad, cuando la cuenta de Tesorería de Mercado Pago no exista o esté inactiva, en lugar de crear la Venta sin cobranza o imputarla a otra cuenta.
- **FR-046**: El sistema DEBE descontar el stock de los productos vendidos, con el mismo comportamiento que cualquier otra Venta del CRM.

  > ⚠️ **Ese comportamiento no existe todavía**: se verificó que las Ventas del CRM no generan movimientos de stock. Esta spec lo construye como servicio compartido y lo cablea para Ventas manuales y de Mercado Libre. Ver [research.md §R1](./research.md) y el Constitution Check de [plan.md](./plan.md).

- **FR-046a**: El sistema DEBE mover stock **únicamente** por las líneas que tengan un producto asociado y sean de tipo Producto. Los Servicios y los ítems libres sin producto NO generan movimiento de stock.
- **FR-046b**: El sistema DEBE reintegrar el stock al eliminar una Venta, y al editarla DEBE reintegrar las cantidades anteriores y aplicar las nuevas, dejando el saldo consistente sin descuadres.
- **FR-046c**: El sistema DEBE registrar cada movimiento de stock con referencia a la Venta que lo originó, para que sea trazable desde el Informe de Stock.
- **FR-046d**: El sistema DEBE crear la Venta **aunque el stock del depósito resulte insuficiente**, dejando el saldo en negativo y advirtiéndolo. Fundamento: la venta ya ocurrió en Mercado Libre y no puede rechazarse; perder el registro sería peor que un saldo negativo.

  > **Nota**: la función avanzada "Ventas sin stock" (§5.1, tarjeta 8) **no está construida**, por lo que no existe una regla configurable que consultar. Hasta que exista, el permitir stock negativo es incondicional para las Ventas originadas en Mercado Libre. Cuando esa función se construya, deberá contemplar que este caso queda fuera de su control.

- **FR-046e**: El sistema NO DEBE revertir el stock automáticamente cuando una orden ya convertida se cancela en Mercado Libre: el ajuste queda a criterio del usuario, coherente con FR-058.
- **FR-047**: El sistema DEBE permitir configurar **desde qué depósito** se descuenta el stock de las Ventas originadas en Mercado Libre, usando el depósito por defecto del CRM cuando no se elija uno explícitamente.
- **FR-048**: El sistema DEBE crear la Venta, la cobranza y el movimiento de stock de forma **atómica**: si algo falla, no debe quedar ninguno de los tres a medias.
- **FR-049**: El sistema NO DEBE registrar la comisión de Mercado Libre ni el costo de envío en esta etapa: la Venta se crea por el monto bruto de los productos. Esta limitación DEBE quedar documentada como pendiente.
- **FR-049a**: El sistema DEBE advertir que, al registrar el monto bruto, **el saldo de la cuenta de Mercado Pago en el CRM no coincidirá con el saldo real** de Mercado Pago, que está neto de comisiones. Es una consecuencia conocida de FR-049 y debe ser explícita para que no se interprete como un error de conciliación.

### Functional Requirements — Creación automática

- **FR-050**: El sistema DEBE ofrecer, en la configuración de Mercado Libre, un interruptor "Creación automática de ventas", **desactivado por defecto**.
- **FR-051**: El sistema DEBE, con el interruptor activo, convertir automáticamente en Venta del CRM cada orden pagada y resoluble que detecte la sincronización, sin intervención del usuario.
- **FR-052**: El sistema NO DEBE crear la Venta automáticamente cuando la orden no sea resoluble —publicación sin vincular, publicación con variantes, comprador ambiguo, producto vinculado inexistente o inactivo, moneda distinta, **alerta de fraude**—: DEBE marcarla como que requiere atención, con el motivo concreto, sin descontar stock.
- **FR-052a**: El sistema DEBE bloquear la conversión —manual y automática— de toda orden que Mercado Libre haya marcado con **alerta de fraude**, mostrando de forma destacada que la mercadería **no debe enviarse** y que la orden debe cancelarse.

  > **Motivo** (research §R2): Mercado Libre puede detectar fraude *después* de aprobar el pago y marcar la orden en consecuencia, notificando que no se despache. Convertirla en Venta y descontar stock sería exactamente lo contrario de lo que corresponde, y con la creación automática activa ocurriría sin que nadie lo advierta.
- **FR-053**: El sistema DEBE dejar apta para conversión una orden que requería atención, en cuanto el usuario resuelva el motivo que la bloqueaba.
- **FR-054**: El sistema DEBE registrar, en cada Venta creada automáticamente, que su origen fue automático y en qué momento se creó.
- **FR-055**: El sistema DEBE, ante un fallo durante la creación automática, dejar la orden marcada con el motivo y registrar el error, sin crear una Venta parcial.
- **FR-056**: El sistema DEBE, con el interruptor desactivado, incorporar las órdenes al listado sin crear ninguna Venta.

### Functional Requirements — Estados posteriores

- **FR-057**: El sistema DEBE reflejar en el listado los cambios de estado de las órdenes detectados en sincronizaciones posteriores, incluidas cancelaciones y reembolsos.
- **FR-058**: El sistema NO DEBE modificar automáticamente una Venta ya creada cuando su orden de origen se cancela o reembolsa: DEBE señalarlo de forma destacada y dejar el ajuste a criterio del usuario.
- **FR-059**: El sistema DEBE deshabilitar la conversión de una orden que se canceló antes de haber sido convertida.

### Functional Requirements — Advertencias

- **FR-060**: El sistema DEBE mostrar en la pantalla de configuración una advertencia explícita sobre el riesgo de **sobreventa** mientras la sincronización de stock hacia Mercado Libre no esté disponible, explicando que una venta manual del CRM no reduce el stock publicado en Mercado Libre.

### Functional Requirements — Retención de datos

- **FR-061**: El sistema NO DEBE purgar automáticamente las órdenes sincronizadas ni sus líneas: son el respaldo de documentos con impacto contable y muchas tienen una Venta asociada. La política de retención acotada aplica únicamente al historial de operaciones ya existente (spec 011), que es material de diagnóstico y de alto volumen.
- **FR-062**: El sistema DEBE conservar la orden y su vínculo con la Venta aunque la vinculación publicación↔producto que se usó para convertirla se elimine después, preservando la trazabilidad histórica.

### Key Entities

- **Orden de Mercado Libre**: cada venta sincronizada desde Mercado Libre. **Nota terminológica**: "orden" designa siempre el documento que viene de Mercado Libre; "Venta" designa siempre el documento del CRM. Atributos: identificador de la orden en Mercado Libre, fecha de creación y de cierre, estado en Mercado Libre, monto total, moneda, datos del comprador (identificador, apodo, nombre, datos fiscales cuando existan), indicador de orden de prueba, **estado de conversión** (uno de los cinco de FR-007a), motivo por el que requiere atención cuando corresponda, referencia a la Venta creada, indicador de si la creación fue manual o automática, y fecha de la última actualización desde Mercado Libre. El identificador de la orden es único: es la garantía de que no se dupliquen.

  **Transiciones de estado válidas**: `Pendiente de pago → Lista para convertir` (se confirma el pago) · `Pendiente de pago → Cancelada` · `Lista para convertir → Requiere atención` (se detecta un bloqueo al intentar convertir) · `Lista para convertir → Convertida` · `Lista para convertir → Cancelada` · `Requiere atención → Lista para convertir` (el usuario resuelve el motivo) · `Requiere atención → Cancelada` · `Convertida → Cancelada` (la orden se cancela en Mercado Libre después de haberse convertido; la Venta permanece intacta, FR-058). **`Convertida` nunca vuelve a un estado anterior**: una Venta creada no se "descrea".
- **Línea de orden de Mercado Libre**: cada producto vendido dentro de una orden. Atributos: referencia a la orden, identificador de la publicación en Mercado Libre, título de la publicación, código del vendedor cuando exista, cantidad, precio unitario y total de la línea, y producto del CRM resuelto en el momento de la conversión.
- **Vinculación publicación ↔ producto**: relación persistente y **uno a uno** entre una publicación de Mercado Libre y un producto del CRM. Atributos: identificador de la publicación, producto del CRM, título de la publicación al momento de vincular, usuario que estableció el vínculo y fecha. Es infraestructura compartida con la spec 013.
- **Configuración de ventas de Mercado Libre**: extensión de la configuración de la integración ya existente. Atributos nuevos: creación automática de ventas activa/inactiva, frecuencia de sincronización, depósito de descuento de stock, antigüedad máxima de la primera sincronización, y marca temporal de la última sincronización exitosa.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Un responsable del negocio ve una venta hecha en Mercado Libre reflejada en el CRM sin haber entrado a Mercado Libre, dentro del intervalo de sincronización configurado.
- **SC-002**: Partiendo de una orden pagada con su publicación ya vinculada, el usuario la convierte en una Venta del CRM en menos de 30 segundos y sin asistencia técnica.
- **SC-003**: El total de toda Venta creada desde una orden coincide **exactamente** con el monto de esa orden en Mercado Libre, verificable comparando ambos importes en el 100% de los casos.
- **SC-004**: Ninguna orden de Mercado Libre genera más de una Venta en el CRM, verificable ejecutando la sincronización repetidas veces sobre el mismo conjunto de órdenes sin que aparezcan duplicados.
- **SC-004a**: Ante intentos **simultáneos** de convertir la misma orden (conversión manual y automática a la vez), se crea exactamente una Venta, una cobranza y un movimiento de stock, verificable de forma automatizada con al menos 10 intentos concurrentes.
- **SC-005**: Con la creación automática activa, el 100% de las órdenes pagadas y resolubles se convierte en Venta sin intervención, y el 100% de las no resolubles queda señalada con el motivo, sin ninguna Venta parcial ni movimiento de stock incorrecto.
- **SC-006**: Una publicación se vincula a un producto **una sola vez**: las órdenes siguientes de esa publicación se resuelven solas, verificable convirtiendo dos órdenes consecutivas de la misma publicación y comprobando que la segunda no pide el producto.
- **SC-007**: El sistema rechaza el 100% de los intentos de vincular una publicación a un segundo producto, o un producto a una segunda publicación.
- **SC-008**: El stock de un producto vendido en Mercado Libre disminuye exactamente en la cantidad vendida, en el depósito configurado.
- **SC-009**: Toda Venta creada desde una orden queda cobrada e imputada a la cuenta de Tesorería de Mercado Pago, sin ningún paso manual.
- **SC-010**: El tipo de comprobante derivado coincide con la condición fiscal informada por Mercado Libre en el 100% de las órdenes que traen ese dato, y es B en las que no lo traen.
- **SC-011**: Ante cualquiera de los escenarios de error contemplados (conexión caída, exceso de solicitudes, orden no resoluble, falla de conversión), el usuario recibe un mensaje que indica qué pasó y qué hacer, sin ver errores técnicos crudos.
- **SC-012**: Todas las operaciones de la pantalla (sincronizar, vincular, convertir, configurar) se completan sin ninguna recarga de página.
- **SC-013**: El módulo opera de forma equivalente en hosting compartido y en servidor dedicado, sin cambios en el código, verificable ejecutando el mismo conjunto de pruebas en ambos entornos.
- **SC-014**: Una sincronización interrumpida a la mitad se retoma sin perder ni duplicar órdenes, verificable interrumpiéndola deliberadamente y volviendo a ejecutarla.

## Assumptions

Decisiones tomadas por defecto ante aspectos no especificados, documentadas para revisión:

- **Los precios de Mercado Libre incluyen IVA**: promovido a requisito en FR-030a. **Se buscó confirmación documental y no existe** (27/07/2026): ni el centro de ayuda de Contagram ni la documentación de la API de Mercado Libre tratan el tema — la API expone `unit_price` sin calificarlo como neto o final.

  El supuesto se sostiene sobre la **normativa argentina de lealtad comercial**, que obliga a exhibir al consumidor precios finales con todos los impuestos incluidos. Un marketplace de venta al público no puede publicar precios netos. Evidencia indirecta que lo respalda: la API expone `attributes.vat_discriminated_billing` ("el comprador solicita factura con IVA discriminado"), que sólo tiene sentido si el precio ya lo contiene y lo que se decide es **discriminarlo o no en la factura**, no agregarlo.

  Es el punto de la spec con mayor impacto si resultara incorrecto: cambia el cálculo de todas las líneas de todas las Ventas de Mercado Libre. **Verificación práctica recomendada al implementar**: comparar el `unit_price` de una orden real contra el precio publicado en la web de Mercado Libre — si coinciden, el supuesto está confirmado de forma empírica y definitiva.
- **Una sola cuenta de Mercado Libre**: se mantiene el supuesto de la spec 011 — el CRM es single-tenant y hay una única cuenta vinculada por vez.
- **Número de comprobante autogenerado**: las Ventas creadas desde órdenes usan la misma numeración correlativa que cualquier otra Venta del CRM. Mercado Libre no aporta un número de comprobante fiscal.
- **Fecha de la Venta**: se usa la fecha de cierre de la orden en Mercado Libre, no la fecha en que se sincronizó ni en que se convirtió.
- **Sin vendedor asignado**: las Ventas originadas en Mercado Libre no llevan vendedor, por no existir tal figura en una venta de marketplace.
- **Sin lista de precios**: se usa el precio real de la orden, ignorando las listas de precios del CRM, que no aplican a una venta ya cerrada en un tercero.
- **Categoría de venta**: se asume configurable una categoría por defecto para las Ventas originadas en Mercado Libre, para que sigan siendo agrupables en los informes; si no se configura, la Venta se crea sin categoría.
- **Órdenes de prueba tratadas como reales**: por decisión explícita del usuario (27/07/2026), las órdenes marcadas como de prueba por Mercado Libre se sincronizan y se tratan igual que cualquier otra, con el fundamento de que la cuenta productiva del negocio no generará órdenes de prueba. Se identifican en el listado (FR-008). **Riesgo aceptado**: mientras se sigan haciendo pruebas con la creación automática activa, se generarán Ventas reales y se descontará stock real.
- **Variantes fuera de alcance**: el negocio no vende con variantes en Mercado Libre. El sistema las detecta y las rechaza explícitamente (FR-027) en lugar de ignorarlas, para que el día que aparezcan no produzcan datos incorrectos en silencio.
- **Límite conocido de la relación uno a uno**: si el negocio publicara el mismo artículo en dos publicaciones distintas de Mercado Libre, el modelo uno a uno no lo soporta y requeriría una migración. Decisión explícita del usuario, aceptando esa limitación.
- **Frecuencia de sincronización por defecto**: se asume un valor conservador, compatible con hosting compartido, ajustable a un intervalo más corto desde la pantalla cuando el negocio migre a un entorno con procesamiento en segundo plano. El valor concreto se define en el plan.
- **Alcance de la primera sincronización**: se asume un período reciente acotado por defecto, configurable, para no arrastrar el historial completo de la cuenta en la primera corrida.
- **La conversión no es una escritura hacia Mercado Libre**: crear una Venta en el CRM no modifica nada en Mercado Libre, por lo que el modo sólo lectura no la bloquea. Lo que sí bloquea es la sincronización, por ser una operación contra la API.
- **Permiso reutilizado**: se reutiliza el permiso del módulo Ingresos ya existente, en lugar de crear uno nuevo, por ser exactamente el alcance que corresponde.

## Dependencies

- **Interna — spec 011 (implementada)**: conexión OAuth, cliente de API con renovación automática de credenciales, modo sólo lectura, historial de operaciones y pantalla de configuración de Mercado Libre, que esta spec extiende.
- **Interna — spec 008 (implementada)**: módulo Ventas (`ventas`, `venta_items`, `cobros`), sobre el que se crean las ventas resultantes.
- **Interna — spec 007 (implementada)**: Tesorería, para la cuenta de Mercado Pago a la que se imputa la cobranza.
- **Interna — spec 005 (implementada)**: Depósitos, para el depósito configurable de descuento de stock.
- **Interna — spec 002 (implementada)**: Productos, para la vinculación con publicaciones y el movimiento de stock.
- **Interna — spec 001 (implementada)**: Clientes, en particular el campo "Apodo ML" usado para el emparejamiento del comprador.
- **Externa**: cuenta de Mercado Libre vinculada con los permisos funcionales de ventas habilitados en la aplicación del DevCenter. Ya habilitados y verificados (`MERCADOLIBRE_NOTAS_TECNICAS.md`).
- **Externa**: el CRM debe estar publicado en una dirección accesible con conexión segura, y el entorno debe tener configurada la ejecución de tareas programadas.
- **Sucesora — spec 013**: sincronización de stock del CRM hacia Mercado Libre. No es dependencia técnica de esta spec, pero **sí es necesaria para cerrar el riesgo de sobreventa** que esta spec deja abierto.

## Restricciones de diseño y entorno

- **Especificaciones de diseño obligatorias del proyecto** (`CLAUDE.md`): listados mediante tablas con carga por demanda desde el servidor; altas, ediciones y bajas mediante ventanas modales sin recarga de página; notificaciones mediante el sistema de avisos emergentes del template; selectores de datos dinámicos con buscador; documentos imprimibles en el visor compartido. **Excepción documentada**: el formulario de conversión a Venta sigue el patrón de página completa ya vigente para "Nueva Venta" y "Nuevo Presupuesto" (`docs §3.1`), no un modal, por coherencia con el módulo Ingresos existente.
- **Portabilidad de entorno**: el módulo debe comportarse igual en hosting compartido (sin procesos permanentes) y en servidor dedicado (con procesamiento en segundo plano). El código debe ser el mismo; sólo cambia la configuración del entorno.
- **Idioma del dominio**: nombres de tablas, columnas, rutas y textos de interfaz en español (principio V de la constitución).
- **Trazabilidad contable**: las Ventas creadas son documentos con impacto contable, por lo que se rigen por la regla de borrado lógico del principio III de la constitución.
- **Testing**: por el principio IV de la constitución, la lógica de cálculo de importes, la derivación del tipo de comprobante, la idempotencia de la conversión, los movimientos de stock y la imputación de la cobranza requieren tests obligatorios.
- **Secretos**: ninguna credencial se versiona ni se registra en logs; el historial de operaciones no debe contener datos sensibles.

## Impacto en la documentación de dominio

Conforme al principio I de la constitución, esta spec introduce contenido que debe reflejarse en la
documentación de dominio **antes de pasar a `/speckit-tasks`**:

1. `docs/documentacion_principal_crm.md`:
   - Agregar la pantalla "Mercado Libre" al módulo Ingresos (§3), documentando que es una entrada
     condicionada a la función avanzada, igual que Abonos.
   - Ampliar §5.2 con el alcance de la etapa 2 y con la advertencia del riesgo de sobreventa mientras
     la etapa 3 (spec 013) no exista.
   - Registrar que el relevamiento de Contagram no cubre esta pantalla y que la estructura se apoya en
     el patrón ya relevado de Presupuesto → Venta.
2. `docs/modelo_datos.md`:
   - Agregar las entidades nuevas: órdenes de Mercado Libre, líneas de orden y vinculación
     publicación↔producto.
   - Agregar los campos nuevos de la configuración de la integración: creación automática, frecuencia
     de sincronización, depósito de stock, antigüedad de la primera sincronización y marca de última
     sincronización.
   - Registrar el origen "Mercado Libre" en `ventas`, junto a los ya existentes Presupuesto y venta
     directa.
