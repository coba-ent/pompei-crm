# Feature Specification: Conversión manual obligatoria para órdenes de Mercado Libre en estado excepcional

**Feature Branch**: `066-ml-conversion-manual-excepciones`

**Created**: 2026-08-14

**Status**: Draft

**Input**: User description: "Que cualquier tipo de transformación automática de ventas de Mercado Libre no tome las órdenes canceladas o en mediación. Ni el cron ni el botón que convierte a todas en venta. Esas las tenés que hacer manual sí o sí."

## Contexto y problema

El CRM convierte órdenes de Mercado Libre en Ventas por tres caminos: la **creación automática** de la
tarea programada, el botón **"Transformar todas en Venta"** y la **conversión manual** orden por orden.
Hoy los tres comparten las mismas reglas, y eso deja dos huecos opuestos:

1. **Una orden en un estado excepcional se puede convertir sola.** Cuando un comprador abre un reclamo,
   la orden entra en **mediación**, pero el estado de la orden que informa Mercado Libre sigue siendo
   "pagada" — la mediación vive en el estado de los **pagos**. El CRM sólo mira eso para órdenes que
   **ya tienen Venta creada** (aviso posterior, spec 063). Una orden que entra en mediación **antes** de
   convertirse pasa como "Lista para convertir" y la tarea programada le crea la Venta igual: se emite un
   comprobante, se registra el cobro y se descuenta stock de una operación cuyo desenlace todavía no está
   definido. Lo mismo pasa con el **reembolso parcial**.

2. **Una orden cancelada no se puede convertir por ningún medio.** El sistema la bloquea por completo, así
   que cuando el negocio necesita facturarla igual —porque la mercadería salió, porque se acordó con el
   comprador por fuera— no hay forma de hacerlo desde el CRM.

La regla que el negocio quiere es la misma en los dos casos: **el sistema nunca decide solo sobre una
orden en estado excepcional, pero la persona sí puede**, asumiendo la decisión de forma explícita.

## Clarifications

### Session 2026-08-14

Ninguna de estas preguntas se elevó al usuario: todas tenían un default razonable y la cadena de specs
del proyecto pide no interrumpir una vez arrancada. Quedan registradas como decisiones tomadas, para que
se puedan revertir a conciencia si el negocio opina distinto.

- Q: Al forzar la conversión de una orden cancelada, ¿el aviso posterior a la conversión (spec 063) la
  marca inmediatamente como "requiere atención — orden cancelada"? → A: **No, si el motivo es el mismo que
  se asumió al forzar.** Sería absurdo avisarle a la persona de algo que acaba de decidir a conciencia. El
  aviso sí se genera si después aparece un motivo **distinto** (p. ej. se forzó una cancelada y luego entra
  en mediación). Ver FR-018.
- Q: ¿Dónde se registra quién forzó la conversión? → A: **En el registro de operaciones de Mercado Libre
  que el CRM ya lleva**, no en una entidad nueva. Es donde ya viven las conversiones y los errores de la
  integración, así que la conversión forzada queda en la misma línea de tiempo que el resto.
- Q: El botón "Transformar todas en Venta" muestra un resumen con total / convertidas / fallidas. ¿Cómo
  aparecen las excluidas por estado excepcional? → A: **Como una categoría propia "excluidas", separada de
  las fallidas.** Una falla es algo que salió mal; una exclusión es el sistema comportándose como se le
  pidió. Mezclarlas haría que el resumen parezca un error recurrente. Ver FR-003a.
- Q: ¿La acción de convertir sigue visible en el listado para una orden en estado excepcional? → A: **Sí,
  visible y habilitada**, con la advertencia en el propio aviso de confirmación. Ocultarla dejaría a la
  persona sin entender por qué esa orden no se puede tocar — que es exactamente el problema que hoy tienen
  las canceladas.

---

## User Scenarios & Testing *(mandatory)*

### User Story 1 - La orden en mediación no se convierte sola (Priority: P1)

Un comprador abre un reclamo sobre una compra ya pagada y la orden entra en mediación. Todavía no se sabe
si el reclamo se resuelve a favor del comprador o del negocio. La persona a cargo entra al listado de
órdenes de Mercado Libre y ve la orden marcada como pendiente de revisión, con el motivo visible. La tarea
programada corre varias veces mientras tanto y **no le crea la Venta**.

**Why this priority**: es el agujero que hoy está abierto y produce daño real — comprobante emitido, cobro
registrado y stock descontado sobre una operación que puede terminar reembolsada. Es lo único de esta
feature que evita un problema que ya está ocurriendo.

**Independent Test**: sincronizar una orden pagada cuyo pago está en mediación, dejar correr la conversión
automática y verificar que no se creó ninguna Venta y que la orden quedó señalada con el motivo.

**Acceptance Scenarios**:

1. **Given** una orden pagada cuyo pago está en mediación y que todavía no tiene Venta, **When** corre la
   sincronización con creación automática activa, **Then** no se crea Venta y la orden queda marcada como
   pendiente de revisión con el motivo "hay un reclamo en mediación".
2. **Given** esa misma orden, **When** se usa "Transformar todas en Venta", **Then** la orden no se
   convierte y no aparece contada como fallida por un error, sino excluida por su estado.
3. **Given** una orden en mediación que se resuelve a favor del negocio y vuelve a un estado vigente,
   **When** corre la sincronización siguiente, **Then** la orden vuelve a quedar disponible para
   convertirse con normalidad, sin intervención manual.

---

### User Story 2 - Forzar la conversión a mano, asumiendo la decisión (Priority: P1)

La persona a cargo decide que una orden cancelada, en mediación, con reembolso parcial o con alerta de
fraude tiene que facturarse igual. Entra a la orden, pulsa convertir, y el sistema le muestra un aviso que
explica **por qué** esa orden está marcada y le pide confirmar. Al confirmar, la Venta se crea y queda
registrado quién forzó la conversión, cuándo y sobre qué motivo.

**Why this priority**: sin esto la regla anterior deja al negocio sin salida — hoy las canceladas ya son
un callejón sin salida y la feature agregaría tres casos más. Ambas historias tienen que entrar juntas
para que el resultado sea usable.

**Independent Test**: tomar una orden cancelada, convertirla desde la pantalla, verificar que aparece la
confirmación con el motivo correcto, confirmar, y comprobar que la Venta se creó y que quedó el registro
de quién la forzó.

**Acceptance Scenarios**:

1. **Given** una orden cancelada sin Venta, **When** la persona pulsa convertir, **Then** el sistema pide
   confirmación indicando que la orden está cancelada en Mercado Libre, y no crea nada hasta confirmar.
2. **Given** ese aviso en pantalla, **When** la persona cancela la confirmación, **Then** no se crea
   ninguna Venta y la orden queda exactamente como estaba.
3. **Given** ese aviso en pantalla, **When** la persona confirma, **Then** se crea la Venta con las mismas
   reglas que cualquier otra conversión (cliente, comprobante, cobro, stock) y queda registrado quién la
   forzó, cuándo y por qué motivo estaba marcada.
4. **Given** una orden con alerta de fraude, **When** la persona la convierte a mano y confirma, **Then**
   la Venta se crea igual que en el caso anterior, con su propio motivo en el registro.
5. **Given** una orden en estado excepcional, **When** se intenta convertirla sin pasar por la
   confirmación explícita, **Then** el sistema la rechaza — la confirmación no se puede saltear.

---

### User Story 3 - Ver de un vistazo qué órdenes requieren decisión (Priority: P2)

La persona entra al listado de órdenes y distingue sin abrir cada una cuáles están frenadas esperando una
decisión suya y por qué motivo. Puede filtrar para ver sólo esas.

**Why this priority**: mejora el trabajo diario pero no evita ningún daño; las dos historias anteriores ya
dejan el sistema correcto. Si esta no entra, la información sigue estando al abrir la orden.

**Independent Test**: con al menos una orden de cada motivo, abrir el listado y verificar que cada una
muestra su motivo y que el filtro por estado de conversión las agrupa.

**Acceptance Scenarios**:

1. **Given** órdenes con distintos motivos excepcionales, **When** se abre el listado, **Then** cada una
   muestra su motivo de forma legible sin necesidad de abrirla.
2. **Given** ese listado, **When** se filtra por el estado que agrupa las órdenes frenadas, **Then** se
   ven todas las que esperan una decisión y ninguna otra.

---

### Edge Cases

- **La orden cambia de estado entre que se muestra el aviso y se confirma.** Entre que la persona ve la
  confirmación y la acepta pueden pasar minutos y correr una sincronización. La conversión debe evaluar el
  estado en el momento de confirmar, no el que se mostró en pantalla: si para entonces la orden dejó de
  estar en estado excepcional, se convierte con normalidad; si pasó a otro motivo excepcional, se convierte
  igual (la persona ya asumió la decisión de forzar) y se registra el motivo vigente al confirmar.
- **Un motivo que se repite después de haber pasado por otro.** Se fuerza una orden cancelada, después entra
  en mediación (avisa, correcto) y después vuelve a estar sólo cancelada. No se avisa: la comparación es
  siempre contra el motivo que la persona asumió al forzar, sin importar por qué motivos haya pasado en el
  medio.
- **Una orden acumula más de un motivo excepcional** (por ejemplo, cancelada y además con reembolso
  parcial). El aviso debe indicar el motivo que corresponde según la precedencia ya establecida en el CRM,
  no una lista ambigua.
- **La orden ya tiene Venta.** No cambia nada: la protección anti-duplicados existente sigue siendo la
  primera barrera, antes que cualquier regla de esta feature.
- **La orden no está pagada.** Sigue sin poder convertirse; "pendiente de pago" no es un estado excepcional
  sino un estado normal previo, y esta feature no lo habilita.
- **Una orden en estado excepcional que no está lista por otro motivo** (publicación sin vincular, cliente
  ambiguo, moneda distinta). Forzar la conversión no debe saltear esas validaciones: son problemas de datos
  que impedirían crear una Venta correcta, no decisiones de negocio.
- **La función Mercado Libre está desactivada o el modo sólo lectura está activo.** Siguen bloqueando todo,
  incluida la conversión forzada. Esta feature no crea un camino que esquive esos cortes.

## Requirements *(mandatory)*

### Functional Requirements

**Exclusión de la conversión no atendida**

- **FR-001**: El sistema MUST considerar en **estado excepcional** a toda orden de Mercado Libre que esté
  cancelada, tenga un reclamo en mediación, tenga un reembolso parcial o tenga alerta de fraude.
- **FR-002**: El sistema MUST excluir las órdenes en estado excepcional de la **creación automática** de
  Ventas de la tarea programada, cualquiera sea la configuración de la integración.
- **FR-003**: El sistema MUST excluir las órdenes en estado excepcional del botón **"Transformar todas en
  Venta"**, sin contarlas como fallas de conversión.
- **FR-003a**: El resumen del botón "Transformar todas en Venta" MUST informar las órdenes excluidas por
  estado excepcional como una **categoría propia**, distinta de las fallidas, indicando el motivo de cada
  una.
- **FR-004**: El sistema MUST detectar el reclamo en mediación **antes** de que la orden se convierta, no
  sólo después. La mediación se reconoce por el estado de los pagos de la orden, no por el estado de la
  orden.
- **FR-005**: El sistema MUST registrar en la orden que tiene un reclamo en mediación, de modo que la
  evaluación de convertibilidad pueda usar ese dato sin volver a consultar a Mercado Libre.
- **FR-006**: El sistema MUST señalar cada orden en estado excepcional con el **motivo** que la frena, y
  MUST usar los motivos ya existentes en el CRM (orden cancelada, reembolso parcial, reclamo en mediación,
  alerta de fraude) sin inventar categorías nuevas.
- **FR-007**: Cuando una orden en estado excepcional vuelve a un estado vigente, el sistema MUST
  devolverla al circuito normal en la sincronización siguiente, sin intervención manual.

**Conversión manual forzada**

- **FR-008**: Usuarios MUST be able to convertir manualmente en Venta una orden en estado excepcional,
  orden por orden.
- **FR-009**: El sistema MUST pedir **confirmación explícita** antes de convertir una orden en estado
  excepcional, indicando el motivo por el que está marcada.
- **FR-010**: El sistema MUST rechazar la conversión de una orden en estado excepcional que llegue sin la
  confirmación explícita, de modo que la confirmación no pueda saltearse.
- **FR-011**: El sistema MUST registrar, para cada conversión forzada, **quién** la hizo, **cuándo** y el
  **motivo** por el que la orden estaba marcada, de forma auditable después.
- **FR-012**: El sistema MUST aplicar a la conversión forzada exactamente las mismas reglas de negocio que
  a cualquier otra conversión: resolución del cliente, derivación del comprobante, cobro contra la cuenta
  de Tesorería, descuento de stock del depósito configurado y protección anti-duplicados.
- **FR-013**: El sistema MUST seguir rechazando la conversión —forzada o no— cuando la orden tiene
  problemas de datos que impiden crear una Venta correcta (publicación sin vincular, producto inexistente,
  publicación con variantes, cliente ambiguo, moneda distinta de la del negocio, datos del comprador
  incompletos).
- **FR-014**: El sistema MUST seguir rechazando la conversión —forzada o no— cuando la orden todavía no
  está pagada, cuando ya tiene una Venta asociada, cuando la función Mercado Libre está desactivada o
  cuando el modo sólo lectura está activo.
- **FR-015**: El sistema MUST evaluar el estado de la orden en el momento de confirmar la conversión, no
  el que se mostró al abrir el aviso.

**Emisión del comprobante**

- **FR-021**: Una Venta creada forzando una orden en estado excepcional MUST crearse **sin emitir el
  comprobante fiscal automáticamente**. La emisión queda como un paso posterior y deliberado, con el mismo
  circuito que cualquier otra Venta pendiente de emitir. Facturar una operación cancelada o en disputa es
  una decisión con consecuencias impositivas y no debe ocurrir como efecto colateral de convertir.
  > **Ya se cumple hoy**: se verificó que la conversión de órdenes de Mercado Libre nunca llamó al emisor de
  > comprobantes — la emisión vive en el circuito de Ventas y se dispara a pedido. Este requisito no pide
  > construir nada: **fija la garantía por escrito** para que no se agregue emisión automática más adelante
  > sin advertir esta consecuencia. Se cubre con un test de regresión, no con implementación.
- **FR-022**: La derivación del tipo de comprobante (A/B/C/E) según la condición de IVA MUST comportarse
  igual que en cualquier otra conversión; forzar no habilita elegir el tipo salteando esa regla.

**Convivencia con el aviso posterior a la conversión**

- **FR-018**: Cuando una Venta se crea forzando una orden en estado excepcional, el sistema MUST NOT
  generar el aviso posterior a la conversión **por el mismo motivo que se asumió al forzarla** — la
  persona ya tomó esa decisión y volver a avisárselo convierte el aviso en ruido.
- **FR-019**: El sistema MUST generar el aviso posterior con normalidad si, después de la conversión
  forzada, la orden pasa a un motivo **distinto** del que se asumió (por ejemplo, se forzó una cancelada y
  luego entra en mediación).

**Visibilidad**

- **FR-016**: El listado de órdenes MUST mostrar el motivo por el que cada orden en estado excepcional está
  frenada, sin necesidad de abrirla.
- **FR-017**: El listado de órdenes MUST permitir filtrar las órdenes que esperan una decisión manual.
- **FR-020**: La acción de convertir MUST permanecer **visible y habilitada** para las órdenes en estado
  excepcional; la advertencia se da en el aviso de confirmación, no escondiendo la acción.

### Key Entities

- **Orden de Mercado Libre**: además de lo que ya registra (estado en Mercado Libre, estado de conversión,
  motivo y detalle), necesita registrar si tiene un **reclamo en mediación**, dato que hoy sólo existe en
  la respuesta cruda del marketplace y se pierde para la evaluación previa a la conversión.
- **Registro de conversión forzada**: quién forzó la conversión de una orden en estado excepcional, cuándo
  y sobre qué motivo. Es lo que permite auditar después por qué existe una Venta a partir de una orden que
  el sistema había frenado. Se apoya en el **registro de operaciones de Mercado Libre que el CRM ya lleva**
  —donde ya conviven las conversiones y los errores de la integración— en vez de crear una bitácora
  paralela. El motivo asumido al forzar además tiene que quedar accesible desde la propia orden, porque
  FR-018 depende de poder compararlo contra motivos posteriores.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Ninguna orden en estado excepcional se convierte en Venta sin que una persona lo haya
  decidido explícitamente — 0 conversiones no atendidas sobre órdenes canceladas, en mediación, con
  reembolso parcial o con alerta de fraude.
- **SC-002**: Una orden que entra en mediación antes de convertirse queda frenada en la primera
  sincronización que la detecta, no después de haberse convertido.
- **SC-003**: La persona a cargo puede facturar una orden cancelada desde el CRM, cosa que hoy es
  imposible por cualquier vía.
- **SC-004**: Para toda Venta creada a partir de una orden en estado excepcional se puede responder quién
  la autorizó y por qué motivo estaba frenada la orden.
- **SC-005**: Una orden cuyo reclamo se resuelve a favor del negocio vuelve a estar disponible para
  convertirse sin que nadie tenga que destrabarla a mano.
- **SC-006**: El comportamiento de las órdenes sin ningún estado excepcional no cambia: se siguen
  convirtiendo igual que antes, automáticamente o en lote.
- **SC-007**: Forzar una conversión no dispara un aviso inmediato por el mismo motivo que la persona acaba
  de asumir — 0 avisos redundantes sobre Ventas recién forzadas.

## Assumptions

- **Los avisos posteriores a la conversión (spec 063) siguen vigentes, con un matiz.** Esta feature actúa
  **antes** de la conversión; aquella actúa **después**. La única interferencia es la que resuelven FR-018
  y FR-019: no se avisa por el motivo que la persona ya asumió al forzar, sí por cualquier motivo nuevo.
- **Los cinco estados de conversión existentes no se amplían.** Los cuatro casos excepcionales se expresan
  con los estados y motivos que el CRM ya tiene; no se agrega un sexto estado.
- **La precedencia entre motivos ya está definida en el CRM** (la mediación se evalúa primero porque puede
  convivir con cualquier estado de orden) y esta feature la reusa en vez de definir una nueva.
- **Alcance limitado a Mercado Libre.** Tiendanube tiene un conversor con la misma estructura y el mismo
  hueco, pero queda fuera por decisión explícita del usuario; se hará en una spec aparte si el negocio lo
  pide.
- **No se agrega un permiso nuevo.** Cualquier usuario que hoy puede convertir órdenes puede forzar la
  conversión; la trazabilidad se resuelve con el registro de quién lo hizo, no restringiendo el acceso.
- **La confirmación es por orden, no por lote.** No se contempla forzar varias órdenes excepcionales de una
  vez: la decisión es individual por definición.
- **El aviso de confirmación explica el motivo pero no pide justificación escrita.** Se registra el motivo
  que ya detectó el sistema, no un texto libre del usuario.
- **El circuito de "descartar aviso" de la spec 063 sigue disponible** para una Venta nacida de una
  conversión forzada. Es independiente de cómo nació la Venta.
- **La auditoría de quién forzó la conversión sobrevive al borrado del usuario**, porque vive en la bitácora
  de operaciones de la integración y no depende de que la fila del usuario siga existiendo.
- **No se define una política de retención** para ese registro. El proyecto no tiene una política transversal
  hoy y definirla desde esta feature sería inventar una regla general desde un caso particular. Queda como
  deuda conocida.

## Out of Scope

- Modificar el comportamiento de Tiendanube.
- Construir un circuito de reversión de Ventas creadas por error (ya se resuelve con nota de crédito o
  eliminación, según la spec 063).
- Reflejar comisiones o costos de envío de Mercado Libre en la Venta.
- Un permiso o rol específico para autorizar conversiones forzadas.
- Forzar la conversión de varias órdenes excepcionales en lote.
