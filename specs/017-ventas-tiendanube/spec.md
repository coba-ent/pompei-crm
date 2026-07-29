# Feature Specification: Ventas de Tiendanube — listado, vinculación de variantes y conversión a Venta del CRM

**Feature Branch**: `017-ventas-tiendanube`

**Created**: 2026-07-29

**Status**: Draft

**Input**: User description: "Integración Tiendanube — Etapa 2: Ventas de Tiendanube. Nueva pantalla
'Tiendanube' dentro del módulo Ingresos, que lista las órdenes de venta sincronizadas desde Tiendanube y
permite convertir cada una en una Venta del CRM, de forma manual o automática. Mismo patrón que
011→012→013 de Mercado Libre, adaptado a las diferencias reales de la API de Tiendanube."

## Contexto y fuentes

Esta spec es la **etapa 2** del módulo de integración con Tiendanube. Continúa directamente
`specs/015-tiendanube-conexion/`, que quedó implementada: credenciales de Aplicación personalizada
(`store_id` + `access_token`, sin OAuth), verificación de conexión, kill-switch de modo sólo lectura,
historial de operaciones propio (`tn_configuracion`, `tn_operaciones_log`) y el cliente de API
`ClienteTiendanube` como único punto de salida hacia Tiendanube.

La etapa 1 (`spec 015`, sección Alcance) excluyó explícitamente el listado de órdenes, la vinculación de
productos y la conversión a Venta, dejándolo anotado como continuación directa en
`docs/documentacion_principal_crm.md §5.3`. **Eso es exactamente lo que cubre esta spec**, siguiendo
**el mismo patrón estructural que las specs 011→012→013 de Mercado Libre** (pantalla de listado,
vinculación persistente, conversión manual/automática, configuración) — con las adaptaciones que impone
la API real de Tiendanube, documentadas explícitamente abajo en vez de forzar una equivalencia que no
existe.

**Sin relevamiento propio de Contagram**: el relevamiento con capturas (`informe_contagram_funciones_avanzadas.md`
§4) no pudo completarse para Tiendanube (requería upgrade de cuenta) y no hay artículos públicos del
centro de ayuda de Contagram sobre el tema, a diferencia de Mercado Libre. Esta spec no tiene una
pantalla real de Contagram contra la cual calcarse; se diseña por fidelidad al **patrón ya construido en
esta misma app** para la integración análoga (Mercado Libre), que es la referencia más confiable
disponible.

**Diferencias reales de la API de Tiendanube frente a Mercado Libre** (fuente: documentación oficial de
Nuvemshop/Tiendanube, `tiendanube.github.io/api-documentation`, consultada 29/07/2026), que esta spec
adapta en vez de ignorar:

1. **Tres campos de estado independientes**, no uno solo: `status` (`open`/`closed`/`cancelled`),
   `payment_status` (`pending`/`authorized`/`paid`/`partially_paid`/`abandoned`/`refunded`/
   `partially_refunded`/`voided`) y `shipping_status`. Mercado Libre expone un único estado de orden.
2. **Toda línea de pedido tiene `variant_id`**, incluso los productos sin variantes reales (Tiendanube
   gestiona una "variante virtual" única en ese caso). No hay equivalente a "publicación sin variantes"
   de Mercado Libre — la vinculación de esta spec es por variante, no por producto.
3. **Sin condición de IVA del comprador**: la API no expone un campo equivalente al de Mercado Libre.
   El dato disponible es `billing_document_type` (tipo de documento). La derivación del tipo de
   comprobante debe apoyarse únicamente en el tipo de documento, no en una condición de IVA informada.
4. **Sin desglose de impuestos**: igual que Mercado Libre, la API no separa el IVA del total — sólo
   expone `subtotal`, `discount`, `total`. Se mantiene el mismo criterio ya usado en la spec 012 (precio
   final con IVA incluido, desagregado con el IVA por defecto del producto), con el mismo respaldo
   normativo (Ley de Lealtad Comercial argentina).
5. **Múltiples medios de pago posibles** (`gateway`: `offline`, `not-provided`, o un proveedor externo),
   no una única pasarela como Mercado Pago en Mercado Libre.
6. **Campo `storefront`/`channels`**, con un valor `meli` que identifica **órdenes importadas desde
   Mercado Libre a través del canal integrado de Tiendanube** — ver "Riesgo de duplicación" abajo.
7. **Sin límite documentado de historial** (a diferencia de los 12 meses que retiene Mercado Libre) y
   **sin OAuth que renovar** (ya resuelto en la spec 015).

### ⚠️ Riesgo de duplicación con la integración de Mercado Libre — resuelto por exclusión

Si el negocio llegara a vender el mismo producto en Mercado Libre a través del **canal integrado de
Tiendanube** (`storefront = "meli"`), esa misma venta ingresaría **dos veces** al CRM: una vez por la
integración directa de Mercado Libre (specs 011-013, que consulta la API de Mercado Libre) y otra vez
por esta spec (que consulta la API de Tiendanube). Ambas generarían Venta, cobranza y descuento de stock
por separado para el mismo hecho económico real.

**Resolución adoptada**: esta spec **excluye por completo** las órdenes con `storefront = "meli"` — ni
siquiera se sincronizan al listado. La integración directa de Mercado Libre (specs 011-013) sigue siendo
la única vía para esas ventas. Ver Clarifications.

**Fuentes de dominio**: `docs/documentacion_principal_crm.md` §2.1 (Clientes), §2.2 (Productos), §3.2
(Ventas), §3.7 (Tesorería), §5.1 (Funciones Avanzadas), §5.3 (Integración Tiendanube); `docs/modelo_datos.md`
§5 (`ventas`, `venta_items`, `cobros`); `specs/012-ventas-mercadolibre/` (patrón estructural de
referencia); `specs/015-tiendanube-conexion/` (infraestructura de conexión ya construida).

## Alcance

**Incluye**: sincronización de órdenes de venta desde Tiendanube hacia el CRM, pantalla de listado
dentro de Ingresos, vinculación persistente entre variantes de Tiendanube y productos del CRM (con
pantalla propia de administración), conversión de una orden en una Venta del CRM —manual o automática—,
generación automática de la cobranza asociada, descuento de stock, y configuración de todo lo anterior.

**Excluye explícitamente**:

- **Sincronización de stock del CRM hacia Tiendanube** → spec posterior (018), mismo patrón que la
  spec 013 respecto de la 012. Mientras no exista, aplica el mismo riesgo de sobreventa ya documentado
  para Mercado Libre (ver Advertencias).
- Comisión de Tiendanube y costo de envío a cargo del vendedor.
- Importación masiva de catálogo, sincronización de precios, webhooks de negocio (esta spec sincroniza
  por consulta programada/manual, no por webhook — ver Clarifications).
- Órdenes con `storefront = "meli"` (ver "Riesgo de duplicación" arriba) — quedan exclusivamente a cargo
  de la integración directa de Mercado Libre.
- Lista de Precios como fuente de precio o como clasificación de estas Ventas: mismo criterio que la
  spec 012 estableció para Mercado Libre — el precio real de la orden ya cerrada en Tiendanube no admite
  recalcularse contra una lista del CRM. Si en el futuro se quisiera el mismo campo de clasificación que
  la spec 016 agregó para Mercado Libre, es una spec propia, no parte de ésta.

## Clarifications

### Session 2026-07-29

Decisiones tomadas por fundamento técnico/de negocio verificado contra la documentación oficial de
Tiendanube, sin interrumpir al usuario (mismo criterio que usó la spec 012 para sus propias
ambigüedades):

- Q: ¿Cómo se traduce el estado de una orden de Tiendanube (tres campos independientes: `status`,
  `payment_status`, `shipping_status`) al conjunto cerrado de estados de conversión del CRM? → A: se
  mantienen los **mismos cinco estados de conversión** ya definidos en la spec 012 (Pendiente de pago ·
  Lista para convertir · Requiere atención · Convertida · Cancelada), derivados de `status` +
  `payment_status` (ver FR-007a). `shipping_status` **no** participa de la derivación: es información de
  logística, no de si la orden está pronta para facturarse — se muestra en el listado como dato
  informativo (FR-005), no como parte del estado de conversión.
- Q: ¿Cómo se deriva el tipo de comprobante (A/B) sin un campo de condición de IVA como el de Mercado
  Libre? → A: se reutiliza la **misma regla de aproximación por tipo de documento** que la spec 012 ya
  dejó construida para el caso "sin condición de IVA informada pero con documento" (FR-040c de la spec
  012: CUIT → A, DNI/CUIL o sin documento → B), como regla **primaria** en vez de fallback, porque acá
  nunca hay condición de IVA que consultar primero. El usuario puede corregir el comprobante después
  (mismo mecanismo que Mercado Libre).
- Q: ¿Qué pasa con las órdenes `storefront = "meli"`? → A: se **excluyen por completo** de la
  sincronización (ver "Riesgo de duplicación"), para no duplicar lo que ya cubre la integración directa
  de Mercado Libre.
- Q: ¿Sincronización por webhook o por consulta programada? → A: **consulta programada + manual**, igual
  patrón que Mercado Libre (spec 012) — los webhooks de Tiendanube requerirían exponer un endpoint
  público entrante, mientras que la conexión de la spec 015 se diseñó explícitamente para **no**
  necesitar infraestructura pública (`docs §5.3`). Introducir un webhook acá reintroduciría esa
  restricción por la puerta de atrás, sobre una integración que se armó a propósito para evitarla.
- Q: ¿A qué cuenta de Tesorería se imputa la cobranza, dado que Tiendanube admite múltiples medios de
  pago (`gateway`) y no una única pasarela como Mercado Pago? → A: una **cuenta de Tesorería
  configurable** (nueva, análoga a `categoria_venta_id`/`deposito_id` de Mercado Libre), única para
  todas las Ventas de Tiendanube sin importar el `gateway` real de cada orden — no se modela una cuenta
  por medio de pago, para no anticipar una complejidad no pedida.
- Q: ¿Contra qué se vincula un producto del CRM: la publicación (como en Mercado Libre) o la variante? →
  A: contra la **variante** de Tiendanube (`variant_id`), porque la API siempre expone una — incluso los
  productos sin variantes reales tienen una "variante virtual" única. No hay equivalente a una
  publicación sin variantes que vincular directamente.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Ver las órdenes de Tiendanube en el CRM (Priority: P1)

Como responsable del negocio, entro a Ingresos → Tiendanube y veo el listado de las órdenes de mi
tienda, cada una con su estado, fecha, comprador, productos vendidos y monto. Puedo forzar la
actualización del listado con un botón, sin esperar a la sincronización programada.

**Why this priority**: es la base de todo lo demás — sin traer y mostrar las órdenes no hay nada que
convertir. Entrega valor por sí sola: ver las ventas de Tiendanube dentro del CRM.

**Independent Test**: se puede probar con la tienda ya conectada (spec 015), presionando "Sincronizar
ahora" y verificando que aparecen las órdenes reales de la tienda con sus datos correctos, sin convertir
ninguna a Venta.

**Acceptance Scenarios**:

1. **Given** la función Tiendanube activa y la tienda conectada, **When** el usuario entra a Ingresos →
   Tiendanube, **Then** ve el listado de órdenes sincronizadas con su estado, fecha, comprador, monto y
   productos.
2. **Given** el listado abierto, **When** el usuario presiona "Sincronizar ahora", **Then** el sistema
   trae las órdenes nuevas y actualizadas, informa por notificación cuántas incorporó, y refresca el
   listado sin recargar la página.
3. **Given** órdenes en distintos estados, **When** el usuario mira el listado, **Then** distingue con
   claridad cuáles están pagadas, cuáles pendientes de pago, cuáles canceladas, cuáles ya se convirtieron
   en Venta del CRM y cuáles requieren atención.
4. **Given** una orden con `storefront = "meli"`, **When** el usuario sincroniza, **Then** esa orden
   **no** aparece en el listado (FR-012a).
5. **Given** la función Tiendanube desactivada, **When** el usuario busca la opción en el menú Ingresos,
   **Then** la entrada no aparece.
6. **Given** una sincronización ya ejecutada, **When** el usuario vuelve a sincronizar, **Then** las
   órdenes ya conocidas se actualizan en lugar de duplicarse.

---

### User Story 2 - Vincular variantes de Tiendanube con productos del CRM (Priority: P1)

Como responsable del negocio, vinculo cada variante de Tiendanube con el producto del CRM que le
corresponde, para que las ventas descuenten el stock del producto correcto. Puedo hacerlo sobre la
marcha, cuando estoy convirtiendo una orden, o desde una pantalla dedicada.

**Why this priority**: sin la vinculación, una orden no puede convertirse en una Venta que descuente
stock correctamente. Es requisito de la historia 3, y es la infraestructura sobre la que se apoyará la
futura sincronización de stock (spec 018).

**Independent Test**: se puede probar vinculando una variante con un producto y verificando que el
vínculo persiste, se muestra en la pantalla de vinculaciones y se reutiliza automáticamente en la
siguiente orden que incluya esa misma variante.

**Acceptance Scenarios**:

1. **Given** una orden con una variante sin vincular, **When** el usuario abre la conversión a Venta,
   **Then** el sistema le señala qué línea no tiene producto y le ofrece elegirlo con un selector con
   buscador.
2. **Given** el usuario elige un producto para una variante, **When** guarda, **Then** el vínculo queda
   persistido y se aplica automáticamente en todas las órdenes futuras que incluyan esa variante.
3. **Given** vinculaciones existentes, **When** el usuario entra a la pantalla de vinculación de
   variantes, **Then** ve el listado completo con la variante (producto y nombre de la variante tal como
   la muestra Tiendanube), el producto del CRM vinculado y la fecha del vínculo, y puede editarlo o
   eliminarlo.
4. **Given** una variante ya vinculada a un producto, **When** el usuario intenta vincularla a un segundo
   producto, **Then** el sistema lo impide e informa que la relación es de uno a uno.
5. **Given** un producto ya vinculado a una variante, **When** el usuario intenta vincularlo a una
   segunda variante, **Then** el sistema lo impide e informa que la relación es de uno a uno.
6. **Given** el usuario elimina una vinculación, **When** existen órdenes ya convertidas que la usaron,
   **Then** las Ventas ya creadas no se modifican y el sistema advierte que las órdenes futuras de esa
   variante quedarán sin resolver.

---

### User Story 3 - Convertir manualmente una orden en una Venta del CRM (Priority: P1)

Como responsable del negocio, desde el menú de fila de una orden pagada elijo "Crear Venta" y el sistema
me abre el formulario de Venta ya precargado con el cliente, los productos, las cantidades y los precios
de la orden de Tiendanube. Reviso, ajusto si hace falta y guardo. La Venta queda registrada, cobrada y
con el stock descontado.

**Why this priority**: es el objetivo central de la spec. Junto con las historias 1 y 2 constituye el
producto mínimo utilizable.

**Independent Test**: se puede probar tomando una orden pagada con sus variantes ya vinculadas,
convirtiéndola, y verificando que la Venta resultante tiene el cliente, los productos y el total
correctos, que figura cobrada y que el stock bajó.

**Acceptance Scenarios**:

1. **Given** una orden pagada con todas sus variantes vinculadas, **When** el usuario elige "Crear
   Venta", **Then** el sistema presenta el formulario de Venta precargado con cliente, productos,
   cantidades, precios y tipo de comprobante derivado.
2. **Given** el formulario precargado, **When** el usuario guarda, **Then** el sistema crea la Venta,
   genera la cobranza asociada dejándola cobrada, descuenta el stock del depósito configurado, y vincula
   la orden con la Venta creada.
3. **Given** una Venta creada desde una orden, **When** el usuario la abre en el listado de Ventas,
   **Then** el total coincide exactamente con el monto de la orden de Tiendanube.
4. **Given** una orden ya convertida, **When** el usuario intenta convertirla otra vez, **Then** el
   sistema lo impide e informa que ya existe una Venta asociada.
5. **Given** una orden no pagada o cancelada, **When** el usuario mira su menú de fila, **Then** la
   acción "Crear Venta" no está disponible y el motivo es visible.
6. **Given** el comprador no existe como Cliente del CRM, **When** se convierte la orden, **Then** el
   sistema crea el Cliente automáticamente con los datos que expone Tiendanube y lo asocia a la Venta.
7. **Given** el comprador ya existe como Cliente (identificado por su email), **When** se convierte la
   orden, **Then** el sistema reutiliza ese Cliente en lugar de duplicarlo.
8. **Given** una Venta creada desde una orden, **When** el usuario la edita después, **Then** puede
   modificarla como cualquier otra Venta del CRM.

---

### User Story 4 - Configurar la sincronización y su comportamiento (Priority: P2)

Como responsable del negocio, configuro desde la pantalla de Tiendanube cada cuánto se sincronizan las
órdenes, desde qué depósito se descuenta el stock, a qué cuenta de Tesorería se imputan las cobranzas, y
si las ventas se crean solas o las convierto yo a mano.

**Why this priority**: la sincronización manual de la historia 1 ya entrega valor; la programación y sus
opciones son la capa que hace el módulo desatendido.

**Independent Test**: se puede probar cambiando la frecuencia, el depósito y la cuenta de Tesorería,
verificando que persisten y que la sincronización programada respeta el intervalo elegido.

**Acceptance Scenarios**:

1. **Given** la pantalla de configuración de Tiendanube, **When** el usuario elige una frecuencia de
   sincronización, **Then** el valor se guarda y la sincronización programada pasa a ejecutarse con ese
   intervalo.
2. **Given** la configuración, **When** el usuario selecciona el depósito de las Ventas originadas en
   Tiendanube, **Then** las Ventas creadas descuentan stock de ese depósito.
3. **Given** la configuración, **When** el usuario selecciona la cuenta de Tesorería, **Then** las
   cobranzas de las Ventas de Tiendanube se imputan a esa cuenta, sin importar el medio de pago real de
   cada orden.
4. **Given** ninguna cuenta de Tesorería configurada, **When** se intenta convertir una orden, **Then**
   el sistema impide la conversión e informa el motivo con claridad, en lugar de crear la Venta sin
   cobranza.
5. **Given** varios depósitos existentes, **When** el usuario no elige ninguno explícitamente, **Then**
   el sistema usa el depósito por defecto del CRM.
6. **Given** el modo sólo lectura activo o la función Tiendanube desactivada, **When** corresponde
   ejecutar una sincronización, **Then** no se ejecuta y queda registrada en el historial de operaciones
   propio de Tiendanube.
7. **Given** la sincronización de stock hacia Tiendanube todavía no disponible, **When** el usuario mira
   la pantalla de configuración, **Then** ve una advertencia explícita sobre el riesgo de sobreventa.

---

### User Story 5 - Crear las ventas automáticamente (Priority: P2)

Como responsable del negocio, activo "Creación automática de ventas" y a partir de ahí cada venta de
Tiendanube se convierte sola en una Venta del CRM, cobrada y con el stock descontado. Si alguna no se
puede crear por falta de datos, queda señalada para que yo la resuelva.

**Why this priority**: es la automatización esperable dado que ya se construyó para Mercado Libre, pero
requiere que la conversión manual (historia 3) esté probada primero.

**Independent Test**: se puede probar activando el interruptor, sincronizando con una orden pagada y
vinculada, y verificando que la Venta aparece creada sin intervención.

**Acceptance Scenarios**:

1. **Given** la creación automática activa y una orden pagada con todas sus variantes vinculadas,
   **When** la sincronización la detecta, **Then** el sistema crea la Venta, la cobranza y el movimiento
   de stock automáticamente.
2. **Given** la creación automática activa y una orden pagada con alguna variante **sin vincular**,
   **When** la sincronización la detecta, **Then** **NO** crea la Venta, marca la orden como que
   requiere atención indicando el motivo, y no descuenta stock.
3. **Given** una orden marcada como que requiere atención por falta de vinculación, **When** el usuario
   vincula la variante faltante, **Then** la orden queda apta para convertirse, manualmente o en la
   siguiente sincronización automática.
4. **Given** la creación automática **desactivada**, **When** la sincronización detecta órdenes pagadas,
   **Then** las incorpora al listado pero no crea ninguna Venta.
5. **Given** una Venta creada automáticamente, **When** el usuario la revisa, **Then** puede editarla
   como cualquier otra Venta del CRM.
6. **Given** la creación automática activa y una falla al crear una Venta, **When** ocurre el error,
   **Then** la orden queda marcada con el motivo, no se crea una Venta a medias, y el error queda
   registrado.

---

### User Story 6 - Enterarse de cancelaciones y reembolsos posteriores (Priority: P3)

Como responsable del negocio, si una orden de Tiendanube que ya ingresé al CRM después se cancela o se
reembolsa, quiero verlo señalado en el listado para decidir yo qué ajuste hacer.

**Why this priority**: es una salvaguarda de consistencia, no un flujo principal.

**Independent Test**: se puede probar simulando el cambio de estado de una orden ya convertida y
verificando que el listado lo refleja y la Venta permanece intacta.

**Acceptance Scenarios**:

1. **Given** una orden ya convertida en Venta, **When** la sincronización detecta que pasó a `cancelled`
   o su `payment_status` pasó a `refunded`/`voided`/`partially_refunded`, **Then** el listado lo refleja
   de forma destacada y la Venta del CRM **no** se modifica.
2. **Given** una orden convertida que cambió de estado, **When** el usuario la mira, **Then** ve tanto el
   estado actual en Tiendanube como el acceso a la Venta del CRM.
3. **Given** una orden **no** convertida que se cancela, **When** la sincronización lo detecta, **Then**
   el listado lo refleja y la acción de convertirla deja de estar disponible.

---

### Edge Cases

- **Orden con `storefront = "meli"`**: se excluye por completo de la sincronización (Clarifications,
  "Riesgo de duplicación") — no aparece en el listado bajo ninguna circunstancia.
- **Más de un Cliente del CRM con el mismo email**: el emparejamiento es ambiguo. El sistema no elige
  uno al azar: marca la orden como que requiere atención.
- **La orden se convierte mientras el modo sólo lectura está activo**: la conversión es una operación
  interna del CRM, no una escritura hacia Tiendanube, por lo que debe permitirse.
- **Un producto vinculado se elimina o se inactiva en el CRM**: las órdenes futuras de esa variante
  quedan sin resolver y deben marcarse como que requieren atención.
- **La orden trae una variante con stock insuficiente en el depósito configurado**: la venta ya ocurrió
  en Tiendanube y no puede rechazarse; la Venta se crea igual y el stock queda en negativo o se advierte,
  según la regla vigente del CRM.
- **Una orden contiene varias variantes distintas, o varias unidades de la misma**: cada línea se
  respeta tal como la informa Tiendanube.
- **La sincronización se interrumpe a mitad de camino**: las órdenes ya procesadas no se reprocesan ni
  se duplican en la corrida siguiente.
- **Tiendanube rechaza la consulta por exceso de solicitudes** (leaky bucket, ~2 solicitudes/segundo,
  ráfagas de hasta 40): la sincronización reintenta con espera creciente y retoma donde quedó.
- **La conexión con Tiendanube está caída** (token revocado o regenerado, spec 015): la sincronización no
  se ejecuta ni marca órdenes como fallidas; informa que hay que recargar el token.
- **Comprador sin `billing_document_type` informado**: se asume Consumidor Final, comprobante B, mismo
  criterio que Mercado Libre cuando no hay dato fiscal.
- **La orden llega con un monto que no coincide con la suma de sus líneas** (por descuentos de
  Tiendanube): el total de la Venta respeta el monto real de la orden, igual que Mercado Libre.
- **La primera sincronización sobre una tienda con historial extenso**: se acota a un período reciente
  configurable, para no arrastrar años de órdenes de golpe, aunque Tiendanube no imponga un límite propio
  de retención como sí lo hace Mercado Libre.
- **Una orden cuya moneda no es la del negocio**: se rechaza la conversión, marcándola como que requiere
  atención, mismo criterio que Mercado Libre (FR-030d de la spec 012).
- **`shipping_status` de la orden**: es puramente informativo en el listado; no participa de los cinco
  estados de conversión (Clarifications).

## Requirements *(mandatory)*

> **Nota de numeración**: los identificadores FR-### se mantienen **a propósito** alineados con los de
> la spec 012 cuando el requisito es el mismo o una adaptación directa (ej. FR-030a, FR-032a, FR-040d),
> para que sea trivial comparar ambas specs lado a lado. Por eso hay saltos (no existe, por ejemplo,
> FR-008 ni FR-027 en esta spec): no indican requisitos faltantes, sino números que en Mercado Libre
> correspondían a algo que acá no aplica (ej. FR-008 de la 012 identificaba órdenes de prueba, concepto
> sin equivalente confirmado en Tiendanube) o que se fusionó en un requisito vecino.

### Functional Requirements — Pantalla y listado

- **FR-001**: El sistema DEBE ofrecer una pantalla "Tiendanube" dentro del módulo Ingresos, accesible
  desde el menú lateral, que liste las órdenes sincronizadas desde Tiendanube.
- **FR-002**: El sistema DEBE mostrar esa entrada del menú únicamente cuando la función avanzada
  "Tiendanube" esté activa, mismo patrón que Mercado Libre y Abonos.
- **FR-003**: El sistema DEBE restringir el acceso a la pantalla mediante el permiso del módulo Ingresos.
- **FR-004**: El sistema DEBE presentar el listado con carga por demanda desde el servidor, con
  paginación, ordenamiento, búsqueda y panel de filtros (DataTables server-side, regla obligatoria del
  proyecto).
- **FR-005**: El sistema DEBE mostrar por cada orden, como mínimo: número de orden en Tiendanube, fecha,
  comprador, productos y cantidades vendidas, monto total, `status`, `payment_status` y
  `shipping_status` (informativo), estado de conversión en el CRM y acceso a la Venta creada cuando
  exista.
- **FR-006**: El sistema DEBE permitir filtrar el listado por estado de conversión y rango de fechas.
- **FR-007**: El sistema DEBE distinguir visualmente las órdenes que requieren atención, indicando el
  motivo concreto.
- **FR-007a**: El sistema DEBE clasificar cada orden en **exactamente uno** de estos cinco estados de
  conversión, mutuamente excluyentes, derivados de `status` y `payment_status` (Clarifications):

  | Estado | Condición (`status` / `payment_status`) | ¿Habilita "Crear Venta"? |
  |---|---|---|
  | **Pendiente de pago** | `status = open` y `payment_status` ∈ {`pending`, `authorized`, `partially_paid`, `abandoned`} | No |
  | **Lista para convertir** | `status = open` y `payment_status = paid`, con todas sus líneas resolubles y comprador inequívoco | Sí |
  | **Requiere atención** | `status = open` y `payment_status = paid`, pero algo impide resolverla (ver FR-052) | No, hasta resolver el motivo |
  | **Convertida** | Ya generó una Venta del CRM | No (ya fue) |
  | **Cancelada** | `status = cancelled`, o (si ya estaba pagada) `payment_status` ∈ {`refunded`, `partially_refunded`, `voided`} | No |

- **FR-007b**: El sistema DEBE registrar, en las órdenes en estado "Requiere atención", el motivo
  concreto que las bloquea.
- **FR-012a**: El sistema NO DEBE sincronizar ni mostrar en ningún momento órdenes con `storefront =
  "meli"` (Clarifications, "Riesgo de duplicación"). Esto se garantiza en **dos capas independientes**:
  (1) la propia consulta a Tiendanube pide sólo canales distintos de `meli` (`channels`, research.md
  R2); (2) aunque una orden `meli` llegara igual — por un cambio futuro no anunciado de la API, o porque
  `storefront` venga vacío/no informado —, `TraductorOrdenes` la descarta explícitamente antes de
  persistir nada. La ausencia del dato `storefront` NO se interpreta como `meli`: sólo se excluye el
  valor exacto `"meli"`; toda orden sin ese valor exacto se sincroniza con normalidad.

### Functional Requirements — Sincronización de órdenes

- **FR-009**: El sistema DEBE ofrecer una acción manual "Sincronizar ahora" que traiga las órdenes
  nuevas y actualice las ya conocidas, informando el resultado por notificación sin recargar la página.
- **FR-010**: El sistema DEBE ejecutar la sincronización de forma programada, con frecuencia
  configurable desde la pantalla de configuración, sin requerir cambios en el código.
- **FR-011**: El sistema DEBE funcionar de forma equivalente en hosting compartido y en servidor
  dedicado, sin cambios en el código — reutilizando el mismo mecanismo de portabilidad ya construido
  para Mercado Libre (spec 012, FR-011).
- **FR-012**: El sistema DEBE traer todas las órdenes de la tienda (salvo las excluidas por FR-012a),
  cualquiera sea su estado, y reflejarlo en el listado.
- **FR-013**: El sistema DEBE actualizar el estado de las órdenes ya sincronizadas cuando cambie en
  Tiendanube, sin duplicarlas (identificador de orden único).
- **FR-014**: El sistema DEBE garantizar que dos sincronizaciones no se ejecuten simultáneamente.
- **FR-015**: El sistema DEBE retomar la sincronización desde el punto en que quedó si una corrida se
  interrumpe, sin reprocesar ni perder órdenes.
- **FR-016**: El sistema DEBE acotar el alcance de la primera sincronización a un período reciente
  configurable, para no arrastrar el historial completo de la tienda de golpe.
- **FR-017**: El sistema NO DEBE ejecutar la sincronización mientras la función "Tiendanube" esté
  desactivada o el modo sólo lectura esté activo, y DEBE registrarlo en el historial de operaciones
  propio de Tiendanube (`tn_operaciones_log`, spec 015).
- **FR-018**: El sistema NO DEBE ejecutar la sincronización mientras la conexión esté caída o no
  configurada, informando que se requiere recargar el token (spec 015) en lugar de acumular errores.
- **FR-019**: El sistema DEBE registrar cada operación de sincronización en el historial de operaciones
  propio de Tiendanube, sin incluir datos sensibles (el `access_token`).
- **FR-020**: El sistema DEBE aplicar espera creciente ante rechazos por exceso de solicitudes (límite
  documentado: ~2 solicitudes/segundo, ráfagas de hasta 40) y reintentar un número acotado de veces ante
  fallas temporales, sin descartar órdenes silenciosamente.

### Functional Requirements — Vinculación variante ↔ producto

- **FR-021**: El sistema DEBE permitir vincular una variante de Tiendanube (`variant_id`) con un
  producto del CRM, y persistir ese vínculo para reutilizarlo en todas las órdenes futuras que incluyan
  esa variante.
- **FR-022**: El sistema DEBE hacer cumplir una relación **estrictamente uno a uno**: una variante no
  puede vincularse a más de un producto, ni un producto a más de una variante. Garantizada a nivel de
  datos, no sólo en la interfaz.
- **FR-023**: El sistema DEBE permitir crear el vínculo sobre la marcha, desde el formulario de
  conversión, mediante un selector con buscador, cuando una línea de la orden no tenga producto
  asociado.
- **FR-024**: El sistema DEBE ofrecer una pantalla propia de "Vinculación de variantes" que liste todos
  los vínculos existentes y permita crearlos, editarlos y eliminarlos.
- **FR-025**: El sistema DEBE mostrar, por cada vínculo, el producto y el nombre de la variante tal como
  los informa Tiendanube, el producto del CRM asociado y la fecha del vínculo.
- **FR-026**: El sistema DEBE conservar intactas las Ventas ya creadas cuando se elimina o modifica un
  vínculo, advirtiendo que el cambio sólo afecta a las órdenes futuras.

### Functional Requirements — Conversión a Venta del CRM

- **FR-028**: El sistema DEBE ofrecer, en el menú de fila de cada orden en estado "Lista para
  convertir", la acción "Crear Venta", que presenta el formulario de Venta precargado con los datos de
  la orden.
- **FR-029**: El sistema DEBE precargar cliente, productos, cantidades, precios y tipo de comprobante,
  permitiendo al usuario revisarlos y ajustarlos antes de guardar.
- **FR-030**: El sistema DEBE garantizar que el total de la Venta creada coincida exactamente con el
  monto (`total`) de la orden de Tiendanube.
- **FR-030a**: El sistema DEBE tratar los importes que informa Tiendanube como precios finales con IVA
  incluido, y desagregar el importe neto de cada línea aplicando el IVA por defecto del producto
  vinculado, absorbiendo cualquier diferencia por redondeo en la última línea sin alterar el total —
  mismo mecanismo ya construido en la spec 012 (FR-030a/b), reutilizado sin cambios.
- **FR-030c**: El sistema DEBE crear las Ventas originadas en Tiendanube sin descuento general ni
  conceptos extra: el precio de cada línea ya viene neto de descuentos aplicados por Tiendanube.
- **FR-030d**: El sistema DEBE rechazar la conversión de una orden cuya moneda no sea la del negocio,
  marcándola como que requiere atención.
- **FR-031**: El sistema DEBE permitir la conversión únicamente de órdenes en estado "Lista para
  convertir", deshabilitando la acción con motivo visible en cualquier otro estado.
- **FR-032**: El sistema DEBE garantizar que una orden genere como máximo una Venta del CRM: reintentar
  la conversión sobre una orden ya convertida DEBE rechazarse sin duplicar ventas, cobranzas ni
  movimientos de stock.
- **FR-032a**: El sistema DEBE serializar la conversión mediante un bloqueo exclusivo por orden, de modo
  que una conversión manual y una automática sobre la misma orden no puedan ejecutarse simultáneamente
  — mismo mecanismo que la spec 012 (FR-032a).
- **FR-032b**: El sistema DEBE respaldar el bloqueo lógico con una restricción de unicidad a nivel de
  datos sobre la referencia orden→Venta.
- **FR-033**: El sistema DEBE registrar el vínculo entre la orden y la Venta creada, y ofrecer
  navegación directa entre ambas.
- **FR-034**: El sistema DEBE tratar la Venta resultante como una Venta ordinaria del CRM en todo lo
  demás: editable, eliminable, con detalle imprimible.
- **FR-035**: El sistema DEBE identificar las Ventas originadas en Tiendanube, distinguiéndolas de las
  creadas manualmente, desde un Presupuesto, o desde Mercado Libre.
- **FR-035a**: El sistema DEBE exponer ese origen a través de la columna y el filtro "Creada Desde" ya
  existentes en el listado de Ventas, agregando "Tiendanube" como cuarto valor junto a Presupuesto,
  venta directa y MercadoLibre (spec 012, FR-035a). **NO** debe crearse una columna ni un filtro
  separados.

### Functional Requirements — Cliente y tipo de comprobante

- **FR-036**: El sistema DEBE emparejar al comprador de la orden con un Cliente existente del CRM
  usando, **en este orden**: (1) el identificador de cliente de Tiendanube (`tn_customer_id`), estable y
  persistido en el Cliente desde el primer emparejamiento; (2) si no hay coincidencia, el **email** de
  la orden.
- **FR-036a**: El sistema DEBE guardar el identificador de cliente de Tiendanube en el Cliente la
  primera vez que lo empareja por email, de modo que los emparejamientos siguientes usen la vía estable
  — mismo patrón que la spec 012 construyó para el identificador de usuario de Mercado Libre (FR-036a).
- **FR-037**: El sistema DEBE crear automáticamente un Cliente nuevo, con los datos que expone
  Tiendanube, cuando no exista ninguno con ese email.
- **FR-038**: El sistema DEBE tratar como ambiguo el caso en que más de un Cliente tenga el mismo email:
  NO debe elegir uno arbitrariamente, sino marcar la orden como que requiere atención.
- **FR-039**: El sistema DEBE derivar el tipo de comprobante **primero** a partir de la condición de IVA
  que el Cliente emparejado **ya tenga cargada** en el CRM (mismo mecanismo estándar que usa cualquier
  Venta manual, `CalculoComprobante`) y, únicamente si el Cliente es nuevo o no tiene condición de IVA
  cargada, aproximarla a partir del **tipo de documento** (`billing_document_type`) que informa
  Tiendanube (FR-040), sin intervención manual, conforme al principio III de la constitución.

  > **Motivo**: Tiendanube nunca informa la condición de IVA real, sólo el tipo de documento. Si un
  > Cliente ya está cargado en el CRM con su condición de IVA correcta (por ejemplo, Responsable
  > Inscripto, cargada a mano alguna vez), usar esa fuente es estrictamente más confiable que
  > re-adivinar a partir del CUIT en cada orden nueva — evita, en particular, que un Monotributista con
  > CUIT ya identificado como tal reciba Factura A por la aproximación de FR-040 cuando el CRM ya sabía
  > que no correspondía.
- **FR-040**: Para Clientes nuevos o sin condición de IVA cargada, el sistema DEBE aplicar la siguiente
  derivación por tipo de documento (Clarifications — reutiliza la regla de aproximación de la spec 012,
  FR-040c, como regla primaria en ausencia de mejor dato):

  | Tipo de documento informado (`billing_document_type`) | Comprobante |
  |---|---|
  | CUIT | **A** |
  | DNI / CUIL | **B** |
  | Otro (Pasaporte, CDI, u otro valor no reconocido) | **B** |
  | Sin dato | **B** (se asume Consumidor Final) |

  Sólo CUIT aproxima a **A**; cualquier otro valor —incluidos los no listados explícitamente— aproxima a
  **B**, por ser la opción fiscalmente más conservadora ante un dato no reconocido.
- **FR-040a**: El sistema DEBE, cuando asuma Consumidor Final por falta de dato, persistir
  explícitamente esa condición de IVA en el Cliente, en lugar de dejarla vacía — mismo fundamento que la
  spec 012 (FR-040a): el principio III prohíbe operar con la condición de IVA sin cargar.
- **FR-040d**: El sistema DEBE completar en todo Cliente creado automáticamente tanto la Condición de
  IVA como el Tipo de comprobante por defecto, nunca en blanco — mismo fundamento que la spec 012
  (FR-040d).
- **FR-041**: El sistema DEBE persistir la condición de IVA y los datos de documento del comprador en el
  Cliente creado o emparejado.
- **FR-041a**: El sistema NO DEBE sobrescribir la condición de IVA ni los datos fiscales que un Cliente
  ya tenía cargados cuando Tiendanube informe algo distinto: completa sólo los campos vacíos.
- **FR-042**: El sistema NO DEBE consultar servicios fiscales externos (ARCA/padrón) para determinar el
  tipo de comprobante.
- **FR-043**: El sistema DEBE permitir al usuario corregir el tipo de comprobante en la Venta creada,
  tanto en la conversión manual como después de una creación automática — la aproximación de FR-040
  puede ser incorrecta (un CUIT puede corresponder a un Monotributista, que factura B, no A) y necesita
  esta vía de corrección.

### Functional Requirements — Cobranza y stock

- **FR-044**: El sistema DEBE generar automáticamente la cobranza asociada a la Venta creada, dejándola
  cobrada en el mismo acto, dado que Tiendanube ya percibió o confirmó el pago antes de que la orden
  llegue al CRM.
- **FR-045**: El sistema DEBE imputar esa cobranza a la cuenta de Tesorería **configurada para
  Tiendanube** (Clarifications), sin importar el `gateway` real de cada orden.
- **FR-045a**: El sistema DEBE impedir la conversión, informando el motivo, cuando no haya ninguna
  cuenta de Tesorería configurada para Tiendanube o esté inactiva.
- **FR-046**: El sistema DEBE descontar el stock de los productos vendidos, reutilizando el mismo
  servicio compartido que ya construyó la spec 012 (`StockDeVenta`) para cualquier Venta del CRM — sin
  brecha que cerrar acá, a diferencia de lo que tuvo que resolver la spec 012 en su momento.
- **FR-046a**: El sistema DEBE mover stock únicamente por las líneas que tengan un producto asociado y
  sean de tipo Producto.
- **FR-046d**: El sistema DEBE crear la Venta aunque el stock del depósito resulte insuficiente, dejando
  el saldo en negativo, mismo criterio que Mercado Libre (spec 012, FR-046d).
- **FR-047**: El sistema DEBE permitir configurar desde qué depósito se descuenta el stock de las Ventas
  originadas en Tiendanube, usando el depósito por defecto del CRM cuando no se elija uno explícitamente.
- **FR-048**: El sistema DEBE crear la Venta, la cobranza y el movimiento de stock de forma atómica.
- **FR-049**: El sistema NO DEBE registrar la comisión de Tiendanube ni el costo de envío en esta etapa:
  la Venta se crea por el monto bruto.

### Functional Requirements — Creación automática

- **FR-050**: El sistema DEBE ofrecer, en la configuración de Tiendanube, un interruptor "Creación
  automática de ventas", desactivado por defecto.
- **FR-051**: El sistema DEBE, con el interruptor activo, convertir automáticamente en Venta del CRM
  cada orden en estado "Lista para convertir" que detecte la sincronización.
- **FR-052**: El sistema NO DEBE crear la Venta automáticamente cuando la orden no sea resoluble —
  variante sin vincular, comprador ambiguo, producto vinculado inexistente o inactivo, moneda distinta,
  cuenta de Tesorería no configurada—: DEBE marcarla como que requiere atención, con el motivo concreto,
  sin descontar stock.
- **FR-053**: El sistema DEBE dejar apta para conversión una orden que requería atención, en cuanto el
  usuario resuelva el motivo que la bloqueaba.
- **FR-054**: El sistema DEBE registrar, en cada Venta creada automáticamente, que su origen fue
  automático y en qué momento se creó.
- **FR-055**: El sistema DEBE, ante un fallo durante la creación automática, dejar la orden marcada con
  el motivo y registrar el error, sin crear una Venta parcial.
- **FR-056**: El sistema DEBE, con el interruptor desactivado, incorporar las órdenes al listado sin
  crear ninguna Venta.

### Functional Requirements — Estados posteriores

- **FR-057**: El sistema DEBE reflejar en el listado los cambios de estado de las órdenes detectados en
  sincronizaciones posteriores, incluidas cancelaciones y reembolsos.
- **FR-058**: El sistema NO DEBE modificar automáticamente una Venta ya creada cuando su orden de origen
  se cancela o reembolsa: DEBE señalarlo de forma destacada y dejar el ajuste a criterio del usuario.
- **FR-059**: El sistema DEBE deshabilitar la conversión de una orden que se canceló antes de haber sido
  convertida.

### Functional Requirements — Retención de datos

- **FR-061**: El sistema NO DEBE purgar automáticamente las órdenes sincronizadas ni sus líneas: son
  respaldo de documentos con impacto contable. La retención acotada aplica únicamente al historial de
  operaciones de Tiendanube (`tn_operaciones_log`, spec 015).
- **FR-062**: El sistema DEBE conservar la orden y su vínculo con la Venta aunque la vinculación
  variante↔producto que se usó para convertirla se elimine después.

### Key Entities

- **Orden de Tiendanube**: cada venta sincronizada. Atributos: número de orden en Tiendanube (único),
  `status`, `payment_status`, `shipping_status`, fecha de creación, monto total, moneda, `storefront`,
  datos del comprador (`tn_customer_id`, email, nombre, `billing_document_type` y número de documento
  cuando existan), **estado de conversión** (uno de los cinco de FR-007a), motivo por el que requiere
  atención cuando corresponda, referencia a la Venta creada, indicador manual/automático, fecha de
  última actualización. **Nunca incluye** órdenes con `storefront = "meli"` (FR-012a).
- **Línea de orden de Tiendanube**: cada variante vendida dentro de una orden. Atributos: referencia a
  la orden, `variant_id`, nombre del producto y de la variante en Tiendanube, cantidad, precio unitario
  y total de línea, producto del CRM resuelto en el momento de la conversión.
- **Vinculación variante ↔ producto**: relación persistente y uno a uno entre una variante de Tiendanube
  y un producto del CRM. Atributos: `variant_id`, producto del CRM, nombre de producto+variante al
  momento de vincular, usuario que estableció el vínculo, fecha.
- **Configuración de ventas de Tiendanube**: extensión de `tn_configuracion` (spec 015). Atributos
  nuevos: creación automática activa/inactiva, frecuencia de sincronización, depósito de descuento de
  stock, cuenta de Tesorería, antigüedad máxima de la primera sincronización, marca temporal de la
  última sincronización exitosa.
- **Cliente** (ya existente, spec 001): se le agrega el atributo `tn_customer_id` (nullable, análogo a
  `ml_user_id` de la spec 012), para el emparejamiento estable de FR-036/036a.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Un responsable del negocio ve una venta hecha en Tiendanube reflejada en el CRM sin haber
  entrado a Tiendanube, dentro del intervalo de sincronización configurado.
- **SC-002**: Partiendo de una orden pagada con sus variantes ya vinculadas, el usuario la convierte en
  una Venta del CRM en menos de 30 segundos.
- **SC-003**: El total de toda Venta creada desde una orden coincide exactamente con el monto de esa
  orden en Tiendanube, verificable en el 100% de los casos.
- **SC-004**: Ninguna orden de Tiendanube genera más de una Venta en el CRM, verificable ejecutando la
  sincronización repetidas veces sobre el mismo conjunto de órdenes.
- **SC-004a**: Ante intentos simultáneos de convertir la misma orden, se crea exactamente una Venta, una
  cobranza y un movimiento de stock.
- **SC-005**: Con la creación automática activa, el 100% de las órdenes en estado "Lista para convertir"
  se convierte en Venta sin intervención, y el 100% de las no resolubles queda señalada con el motivo.
- **SC-006**: Una variante se vincula a un producto una sola vez: las órdenes siguientes que la incluyan
  se resuelven solas.
- **SC-007**: El sistema rechaza el 100% de los intentos de vincular una variante a un segundo producto,
  o un producto a una segunda variante.
- **SC-008**: El stock de un producto vendido en Tiendanube disminuye exactamente en la cantidad
  vendida, en el depósito configurado.
- **SC-009**: Toda Venta creada desde una orden queda cobrada e imputada a la cuenta de Tesorería
  configurada, sin ningún paso manual.
- **SC-010**: Ninguna orden con `storefront = "meli"` aparece jamás en el listado ni genera una Venta,
  verificable inyectando una orden de prueba con ese valor y confirmando que la sincronización la
  descarta.
- **SC-011**: Ante cualquiera de los escenarios de error contemplados, el usuario recibe un mensaje que
  indica qué pasó y qué hacer, sin ver errores técnicos crudos.
- **SC-012**: Todas las operaciones de la pantalla se completan sin ninguna recarga de página.
- **SC-013**: El módulo opera de forma equivalente en hosting compartido y en servidor dedicado, sin
  cambios en el código.
- **SC-014**: Una sincronización interrumpida a la mitad se retoma sin perder ni duplicar órdenes.
- **SC-015**: El tipo de comprobante derivado coincide con la tabla de FR-039/FR-040 en el 100% de los
  casos, verificable de forma determinística por tests. **Aclaración de alcance**: este criterio mide
  que la **regla se aplicó correctamente**, no que el resultado sea fiscalmente correcto en todos los
  casos — FR-040 es una aproximación conocida (ver Assumptions) que puede requerir corrección manual
  (FR-043); no existe una fuente de datos que permita a esta spec verificar la condición de IVA real del
  comprador.

## Assumptions

- **Sin alerta de fraude equivalente**: Mercado Libre expone una marca de fraude que la spec 012 usa
  para bloquear la conversión (FR-052a de esa spec). Se buscó un equivalente en la documentación oficial
  de Tiendanube consultada (29/07/2026) y **no se encontró** ningún campo de este tipo en el recurso
  Order. Esta spec no incorpora, por lo tanto, un bloqueo análogo — es una decisión informada por
  ausencia de evidencia, no un olvido. Si Tiendanube expusiera esa señal en el futuro, se agregaría como
  spec/enmienda propia.
- **Precios de Tiendanube incluyen IVA**: mismo supuesto y mismo respaldo normativo (Ley de Lealtad
  Comercial) que la spec 012 estableció para Mercado Libre — la API tampoco discrimina impuestos.
  Verificación práctica recomendada al implementar: comparar `total` de una orden real contra lo
  publicado en la tienda.
- **Tipo de comprobante aproximado por tipo de documento**: dado que Tiendanube no informa condición de
  IVA, la derivación (FR-040) puede asignar Factura A a un Monotributista con CUIT que en realidad
  factura B. Riesgo aceptado y mitigado por la corrección manual (FR-043) — mismo mecanismo, mismo
  riesgo ya aceptado parcialmente en la spec 012 para su propio caso de fallback.
- **Vinculación por variante, no por producto CRM con variantes propias**: no se mapea contra el modelo
  de variantes propio del CRM (`ProductoVariante`) — cada variante de Tiendanube se vincula directamente
  a un Producto del CRM, misma granularidad simple que usó Mercado Libre para sus publicaciones. Modelar
  la correspondencia variante Tiendanube ↔ variante CRM queda fuera de esta spec.
- **Órdenes `storefront = "meli"` completamente excluidas**: no se ofrece una vía alternativa para
  ingresarlas por esta integración ni siquiera de forma manual — quedan exclusivamente a cargo de la
  integración directa de Mercado Libre (specs 011-013).
- **Sin migración de datos previos que limpiar**: `tn_ordenes` es una tabla nueva creada por esta misma
  spec — no existe ningún registro sincronizado antes de que la exclusión de `storefront=meli` (FR-012a)
  estuviera implementada, así que no hace falta backfill ni limpieza retroactiva.
- **Sin lista de precios**: mismo criterio que la spec 012 — se usa el precio real de la orden, sin
  relación con las listas de precios del CRM.
- **Cuenta de Tesorería única para todas las órdenes de Tiendanube**: sin importar el `gateway` real
  informado por cada orden (Clarifications).
- **Sin webhooks**: sincronización exclusivamente por consulta programada/manual, para no reintroducir
  el requisito de infraestructura pública que la spec 015 evitó a propósito.
- **Una sola tienda de Tiendanube**: se mantiene el supuesto single-tenant de la spec 015.
- **Número de comprobante autogenerado**: mismo criterio que Mercado Libre — numeración correlativa
  propia del CRM.
- **Sin vendedor asignado**: las Ventas originadas en Tiendanube no llevan vendedor.
- **Categoría de venta configurable**: mismo patrón que Mercado Libre — si no se configura, la Venta se
  crea sin categoría.
- **Permiso reutilizado**: se reutiliza el permiso del módulo Ingresos ya existente.

## Dependencies

- **Interna — spec 015 (implementada)**: conexión de Aplicación personalizada, `ClienteTiendanube`,
  modo sólo lectura, historial de operaciones y pantalla de configuración de Tiendanube, que esta spec
  extiende.
- **Interna — spec 012 (implementada)**: `StockDeVenta` (servicio compartido de descuento de stock ya
  generalizado — sin brecha que cerrar acá), patrón de derivación de comprobante por documento (FR-040c
  original), patrón de bloqueo por orden, patrón de estados de conversión.
- **Interna — spec 008 (implementada)**: módulo Ventas, sobre el que se crean las Ventas resultantes.
- **Interna — spec 007 (implementada)**: Tesorería, para la cuenta configurable de imputación.
- **Interna — spec 005 (implementada)**: Depósitos.
- **Interna — spec 002 (implementada)**: Productos, para la vinculación y el movimiento de stock.
- **Interna — spec 001 (implementada)**: Clientes, se le agrega `tn_customer_id`.
- **Externa**: tienda de Tiendanube conectada (spec 015) con el `access_token` de una Aplicación
  personalizada con permisos de lectura sobre Órdenes y Clientes.
- **Sucesora — spec 018**: sincronización de stock del CRM hacia Tiendanube. No es dependencia técnica
  de esta spec, pero es necesaria para cerrar el riesgo de sobreventa que esta spec deja abierto.

## Restricciones de diseño y entorno

- **Especificaciones de diseño obligatorias del proyecto** (`CLAUDE.md`): listados mediante DataTables
  con carga por demanda; altas, ediciones y bajas mediante modales AJAX sin recarga de página;
  notificaciones toast; selectores de datos dinámicos con Select2/buscador. **Excepción documentada**:
  el formulario de conversión a Venta sigue el patrón de página completa ya vigente para "Nueva Venta"
  (`docs §3.1`), no un modal — mismo criterio ya establecido por la spec 012.
- **Portabilidad de entorno**: el módulo debe comportarse igual en hosting compartido y en servidor
  dedicado.
- **Idioma del dominio**: nombres de tablas, columnas, rutas y textos de interfaz en español.
- **Trazabilidad contable**: las Ventas creadas son documentos con impacto contable, sujetas a borrado
  lógico (principio III).
- **Testing**: por el principio IV de la constitución, el cálculo de importes (desagregación de IVA), la
  derivación del tipo de comprobante, la idempotencia de la conversión, la exclusión de órdenes
  `storefront = "meli"`, los movimientos de stock y la imputación de la cobranza requieren tests
  obligatorios.
- **Secretos**: el `access_token` no se registra en logs ni en el historial de operaciones.

## Advertencias

- ⚠️ **Riesgo de sobreventa hasta la spec 018**: mientras la sincronización de stock CRM→Tiendanube no
  exista, una Venta manual del CRM (o una Venta de Mercado Libre, o de cualquier otro origen) que
  descuente stock de un producto también vendido en Tiendanube **no** reduce el stock publicado en
  Tiendanube. Debe advertirse en la propia pantalla de configuración, mismo criterio que la spec 012
  estableció para Mercado Libre.

## Impacto en la documentación de dominio

Conforme al principio I de la constitución, esta spec introduce contenido que debe reflejarse en la
documentación de dominio **antes de pasar a `/speckit-tasks`**:

1. `docs/documentacion_principal_crm.md`:
   - Agregar la pantalla "Tiendanube" al módulo Ingresos (§3), entrada condicionada a la función
     avanzada, igual que Mercado Libre y Abonos.
   - Ampliar §5.3 con el alcance de esta etapa (listado, vinculación por variante, conversión,
     exclusión de `storefront = "meli"`) y la advertencia del riesgo de sobreventa mientras la spec 018
     no exista.
   - Registrar que no hay relevamiento propio de Contagram para esta pantalla; el diseño sigue el patrón
     ya construido para Mercado Libre.
2. `docs/modelo_datos.md`:
   - Agregar las entidades nuevas: órdenes de Tiendanube, líneas de orden y vinculación
     variante↔producto.
   - Agregar los campos nuevos de `tn_configuracion`: creación automática, frecuencia de sincronización,
     depósito, cuenta de Tesorería, antigüedad de la primera sincronización, marca de última
     sincronización.
   - Agregar `tn_customer_id` a `clientes`.
   - Registrar el origen "Tiendanube" en `ventas`, junto a los ya existentes.
