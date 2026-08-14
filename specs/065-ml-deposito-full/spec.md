# Feature Specification: Depósito para publicaciones y órdenes Full de Mercado Libre

**Feature Branch**: `065-ml-deposito-full`

**Created**: 2026-08-13

**Status**: Draft

**Input**: User description: "Gestión de depósito para publicaciones y órdenes Full de Mercado Libre. Tal como tenemos un depósito por defecto para las órdenes de ML para la creación de ventas, quisiera poder gestionar el depósito para cuando viene en Full."

## Contexto del negocio

Mercado Libre ofrece dos esquemas de logística que son **opuestos en cuanto a quién posee el
stock**:

- **Logística propia del negocio** (Flex/colecta/a cargo del vendedor): la mercadería está
  físicamente en el depósito del negocio. El CRM es la fuente de verdad del stock y se lo informa
  a Mercado Libre.
- **Full (fulfillment)**: la mercadería fue enviada previamente al centro de distribución de
  Mercado Libre y está físicamente allí. **Mercado Libre es la fuente de verdad del stock**, no
  el CRM. Mercado Libre despacha por su cuenta y descuenta stock sin intervención del negocio.

Hoy el CRM trata a **todas** las publicaciones igual: existe un único "Depósito de Mercado Libre"
en la Configuración de Mercado Libre que cumple dos funciones a la vez —(a) es el depósito del que
descuenta la Venta creada desde una orden y (b) es el depósito desde el cual se calcula el stock
que el CRM le informa a Mercado Libre.

Para las publicaciones Full esto produce dos consecuencias incorrectas:

1. El CRM le informa a Mercado Libre un stock que Mercado Libre no debe recibir (en Full el stock
   lo gobierna Mercado Libre). Esas escrituras son ruido y pueden ser rechazadas.
2. Las Ventas de órdenes Full descuentan del depósito físico del negocio, cuando en realidad la
   mercadería salió del depósito de Mercado Libre. El stock del negocio queda subvaluado.

**Hechos verificados contra la cuenta real (13/08/2026, consultas de sólo lectura)**:

- De las 270 publicaciones vinculadas, 3 son Full, 260 son de logística propia y 7 son de otros
  tipos. El volumen Full es hoy bajo, pero la distorsión contable que produce es real y silenciosa.
- Mercado Libre lleva **dos existencias separadas** por producto: la del domicilio del vendedor y la
  del centro de distribución de Mercado Libre. Son consultables por separado. La existencia del
  centro de distribución **no es escribible**: sólo cambia cuando Mercado Libre recibe físicamente un
  envío del negocio, o cuando vende. Esto no es una decisión de diseño conservadora: del lado de
  Mercado Libre no hay dónde escribir.
- El caso "un producto del CRM con una publicación Full **y** otra de logística propia" **no es
  hipotético**: hay 2 casos reales. Uno de ellos tiene 4 unidades en el centro de distribución de
  Mercado Libre y 3 en el depósito propio. Hoy el CRM las suma en un único depósito y "ve" 7
  unidades juntas, sin poder distinguir cuáles puede despachar por sus propios medios.
- El identificador de inventario **no** distingue Full: aparece también en publicaciones de logística
  propia. El único indicador confiable de Full es el tipo de logística de la publicación.

## Clarifications

### Session 2026-08-13

Las cuatro decisiones de alcance (depósito configurable vs. automático, sentido del stock en Full,
fallback sin configurar, visualización en Vinculaciones) las respondió el usuario **antes** de
redactar la spec. Las siguientes se resolvieron durante `clarify` aplicando la opción recomendada,
conforme a la regla del proyecto de no interrumpir la cadena una vez iniciada. Todas tienen un
default defendible; ninguna era bloqueante.

- Q: Una orden que mezcla artículos Full y de logística propia, ¿a qué depósito imputa la Venta? →
  A: **Al depósito general**. La Venta se imputa al depósito Full **sólo si todas sus líneas son
  Full**; si mezcla, va al general. Motivo: una Venta tiene un único depósito en el modelo actual y
  partirla excede el alcance; el general es el fallback conservador. El caso es además infrecuente
  porque Mercado Libre suele separar los envíos Full de los propios.
- Q: Si varias publicaciones Full apuntan al mismo producto del CRM (la vinculación múltiple está
  permitida), ¿cómo se refleja la existencia sin que una pise a la otra? → A: **Deduplicando por
  inventario de Mercado Libre**: las publicaciones que comparten el mismo inventario cuentan una sola
  vez; las que tienen inventarios distintos suman. Refleja la semántica real de Full, donde la
  existencia pertenece al inventario y no a la publicación. Verificado que un mismo inventario se
  repite entre publicaciones distintas en la cuenta real (3 casos sobre 34 inventarios).
- Q: ¿De dónde se toma la existencia Full: del disponible de la publicación o de una fuente
  específica de Full? → A: **De la existencia del centro de distribución de Mercado Libre asociada al
  inventario de la publicación**, no del disponible de la publicación. Es el dato autoritativo, viene
  desglosado entre lo vendible y lo no vendible, y evita ambigüedades cuando una publicación mezcla
  conceptos. Verificado contra la cuenta real.
- Q: Con el "modo sólo lectura" activo, ¿el reflejo de existencias de Mercado Libre hacia el CRM
  debe seguir corriendo? → A: **Sí**. El modo sólo lectura existe para impedir **escrituras hacia**
  Mercado Libre; traer información hacia el CRM no lo viola. Sólo se suspende el envío de stock, que
  para Full ya está excluido de todos modos.
- Q: ¿El reflejo de existencias alcanza sólo a los vínculos marcados como pendientes o a todos los
  Full? → A: **A todos los vínculos Full, siempre**. La marca de pendiente la produce un movimiento
  hecho en el CRM; un cambio de existencias ocurrido en Mercado Libre nunca la activaría, así que
  limitarse a los pendientes dejaría el stock Full permanentemente desactualizado.
- Q: En Vinculaciones, ¿se muestra sólo "es Full sí/no" o el tipo de logística real? → A: **El tipo
  de logística real de cada publicación**, con un distintivo destacado exclusivo para Full. El filtro
  ofrece los tipos conocidos. Mismo costo de implementación y más información diagnóstica.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Dejar de pisar el stock que gobierna Mercado Libre (Priority: P1)

El responsable del negocio necesita que el CRM **deje de informarle stock a Mercado Libre** en las
publicaciones que están en Full. En Full, la mercadería está en el depósito de Mercado Libre y es
Mercado Libre quien sabe cuánto queda; que el CRM le informe la existencia de su propio depósito es
incorrecto y puede llegar a alterar una publicación que estaba bien.

**Why this priority**: es la corrección que detiene el daño activo. No requiere que el usuario
configure nada previamente y se puede entregar sola. Todo lo demás construye encima de poder
distinguir una publicación Full de una que no lo es.

**Independent Test**: se ejecuta una sincronización de stock (automática o forzada) con al menos
una publicación Full vinculada y se verifica que el CRM no envió ninguna actualización de stock
para esa publicación, mientras sí la envió para las de logística propia.

**Acceptance Scenarios**:

1. **Given** una publicación vinculada cuyo tipo de logística es Full, **When** se ejecuta la
   sincronización de stock hacia Mercado Libre, **Then** el CRM no envía ninguna actualización de
   stock para esa publicación y la publicación no queda marcada con error.
2. **Given** una publicación vinculada de logística propia, **When** se ejecuta la sincronización
   de stock, **Then** el CRM le informa el stock normalmente, sin cambio de comportamiento respecto
   de hoy.
3. **Given** un movimiento de stock en el CRM sobre un producto vinculado a una publicación Full,
   **When** se procesa la sincronización de stock, **Then** ese vínculo no genera ningún envío hacia
   Mercado Libre ni queda pendiente de sincronizar indefinidamente.

---

### User Story 2 - Ver qué publicaciones están en Full (Priority: P1)

El responsable del negocio necesita **ver en la pantalla de Vinculaciones de Mercado Libre cuáles
publicaciones están en Full**, con un distintivo visible, y poder filtrar el listado por tipo de
logística. Hoy no hay forma de saberlo desde el CRM: hay que entrar a Mercado Libre publicación por
publicación.

**Why this priority**: es la contraparte visible de US1 y lo que le permite al usuario entender por
qué esas publicaciones se comportan distinto (no reciben stock del CRM). Sin esto, la exclusión de
US1 es invisible y parece un bug.

**Independent Test**: se abre la pantalla de Vinculaciones y se verifica que las publicaciones Full
muestran un distintivo "FULL", que las demás no lo muestran, y que el filtro por tipo de logística
acota el listado correctamente.

**Acceptance Scenarios**:

1. **Given** publicaciones vinculadas de distintos tipos de logística, **When** el usuario abre la
   pantalla de Vinculaciones, **Then** cada publicación muestra su tipo de logística en términos
   legibles, y sólo las Full llevan además el distintivo destacado "FULL".
2. **Given** la pantalla de Vinculaciones abierta, **When** el usuario filtra por tipo de logística
   "Full", **Then** el listado muestra únicamente las publicaciones Full, sin recargar la página.
3. **Given** una publicación cuyo tipo de logística todavía no fue determinado, **When** el usuario
   ve el listado, **Then** la publicación se muestra sin distintivo de Full y se la trata como de
   logística propia a todos los efectos.
4. **Given** una publicación que el negocio pasó de logística propia a Full en Mercado Libre,
   **When** el CRM refresca la clasificación de las publicaciones, **Then** el distintivo "FULL"
   aparece en la pantalla sin que el usuario tenga que hacer nada.

---

### User Story 3 - Configurar el depósito de Full (Priority: P2)

El responsable del negocio necesita poder **elegir qué depósito del CRM representa la mercadería
que está en el centro de distribución de Mercado Libre**, desde la Configuración de Mercado Libre,
igual que hoy elige el depósito general de Mercado Libre.

**Why this priority**: es el habilitador de US4 y US5. Por sí solo no cambia comportamiento, pero
sin él no hay dónde imputar ni el stock Full ni las ventas Full.

**Independent Test**: se abre la Configuración de Mercado Libre, se elige un depósito en el campo
"Depósito para publicaciones Full", se guarda sin que la página se recargue y se verifica que al
volver a abrir la pantalla el valor quedó persistido.

**Acceptance Scenarios**:

1. **Given** el usuario en la Configuración de Mercado Libre, **When** abre el selector "Depósito
   para publicaciones Full", **Then** puede buscar y elegir entre los depósitos activos del CRM.
2. **Given** el usuario eligió un depósito para Full, **When** guarda la configuración, **Then** el
   cambio se confirma con una notificación de éxito sin que la página se recargue.
3. **Given** el usuario intenta elegir para Full el **mismo** depósito que ya está configurado como
   depósito general de Mercado Libre, **When** guarda, **Then** el sistema rechaza el guardado y
   explica que ambos depósitos deben ser distintos.
4. **Given** ningún depósito elegido para Full, **When** el usuario guarda, **Then** el guardado se
   acepta: el campo es opcional y el sistema opera con el comportamiento actual.

---

### User Story 4 - Que el CRM sepa cuánto hay en el depósito de Mercado Libre (Priority: P2)

El responsable del negocio necesita que el CRM **refleje en el depósito Full la existencia real que
Mercado Libre reporta**, para saber cuánta mercadería propia está inmovilizada en el centro de
distribución de Mercado Libre y cuándo conviene reponer.

Para Full el stock viaja **siempre de Mercado Libre hacia el CRM, nunca al revés**.

**Why this priority**: convierte a Full de "punto ciego" en información de gestión. Depende de US1
(distinguir Full) y US3 (tener depósito destino).

**Independent Test**: se configura un depósito Full, se ejecuta la sincronización de stock y se
verifica que la existencia de los productos vinculados a publicaciones Full en ese depósito coincide
con la que reporta Mercado Libre.

**Acceptance Scenarios**:

1. **Given** un depósito Full configurado y una publicación Full con una existencia informada por
   Mercado Libre, **When** se ejecuta la sincronización de stock, **Then** la existencia de ese
   producto en el depósito Full del CRM queda igual a la informada por Mercado Libre.
1a. **Given** el producto real que hoy tiene 4 unidades en el centro de distribución de Mercado Libre
   (publicación Full) y 3 en el depósito propio (publicación de logística propia), **When** se
   ejecuta la sincronización de stock, **Then** el CRM queda con 4 unidades en el depósito Full y 3
   en el depósito general — nunca 7 en un solo depósito ni 4 en ambos.
2. **Given** la existencia en el depósito Full del CRM difiere de la de Mercado Libre, **When** se
   sincroniza, **Then** el CRM ajusta la diferencia dejando registro trazable del ajuste, sin
   alterar la existencia de ningún otro depósito.
3. **Given** un depósito Full **no** configurado, **When** se ejecuta la sincronización de stock,
   **Then** las publicaciones Full siguen excluidas del envío hacia Mercado Libre (US1 se mantiene),
   no se refleja existencia en ningún depósito y el hecho queda informado al usuario.
4. **Given** que el CRM reflejó existencia en el depósito Full, **When** se procesa la
   sincronización de stock siguiente, **Then** ese movimiento no provoca que el CRM le informe stock
   a Mercado Libre (no se genera un ciclo de ida y vuelta).

---

### User Story 5 - Que la venta de una orden Full descuente del depósito correcto (Priority: P2)

El responsable del negocio necesita que, cuando se vende algo que estaba en Full, **la Venta
descuente del depósito Full y no del depósito físico del negocio**, para que la existencia del
depósito propio no quede subvaluada por mercadería que en realidad ya no estaba ahí.

**Why this priority**: es la corrección contable del stock. Va después de US3 y US4 porque necesita
el depósito configurado y la clasificación de la publicación.

**Independent Test**: se convierte en Venta una orden de Mercado Libre correspondiente a una
publicación Full y se verifica que el descuento de existencias impactó en el depósito Full y no en
el depósito general de Mercado Libre.

**Acceptance Scenarios**:

1. **Given** un depósito Full configurado y una orden de Mercado Libre de una publicación Full,
   **When** la orden se convierte en Venta, **Then** la Venta queda imputada al depósito Full y el
   descuento de existencias impacta únicamente en ese depósito.
2. **Given** una orden de Mercado Libre de una publicación de logística propia, **When** se
   convierte en Venta, **Then** la Venta queda imputada al depósito general de Mercado Libre, sin
   cambio respecto de hoy.
3. **Given** una orden Full pero **sin** depósito Full configurado, **When** se convierte en Venta,
   **Then** la Venta se crea igual usando el depósito general de Mercado Libre — la conversión nunca
   se traba por falta de esta configuración.
4. **Given** una orden que contiene a la vez artículos Full y artículos de logística propia,
   **When** se convierte en Venta, **Then** la Venta se imputa al depósito general de Mercado Libre
   (no al Full), se crea sin trabarse y el criterio de imputación aplicado queda registrado de forma
   consultable.
5. **Given** una orden cuyas líneas son **todas** de publicaciones Full, **When** se convierte en
   Venta, **Then** la Venta se imputa al depósito Full.

---

### Edge Cases

- **Publicación sin clasificar todavía**: una publicación recién vinculada, o cuya clasificación
  falló, se trata como **no Full** (comportamiento actual): se le informa stock a Mercado Libre y sus
  ventas van al depósito general. Nunca se asume Full por defecto.
- **Fallo al consultar el tipo de logística**: si Mercado Libre no responde, cada publicación
  conserva su última clasificación conocida; no se la pisa con un valor vacío ni se aborta el resto
  de la corrida.
- **Publicación que pasa de Full a logística propia**: al refrescarse la clasificación, la
  publicación vuelve a recibir stock del CRM y sus ventas vuelven al depósito general. La existencia
  que había quedado reflejada en el depósito Full **no** se migra automáticamente a otro depósito;
  queda a cargo del usuario mediante un movimiento entre depósitos.
- **Publicación que pasa de logística propia a Full**: deja de recibir stock del CRM y pasa a
  reflejar el del centro de distribución de Mercado Libre. La existencia que tenía en el depósito
  general **no** se migra automáticamente al Full: representa mercadería que sigue físicamente en el
  depósito del negocio hasta que se la envíe. Migrarla sola inventaría stock en Full que Mercado
  Libre todavía no recibió.
- **Tipo de logística desconocido**: se persiste y se muestra tal cual lo informa Mercado Libre, y se
  trata como no Full. Un valor nuevo nunca activa el tratamiento de Full por inferencia.
- **Depósito Full inactivo o eliminado**: si el depósito configurado para Full deja de estar activo,
  el sistema se comporta como si no hubiera depósito Full configurado (US4 escenario 3, US5
  escenario 3), sin trabar ninguna operación.
- **Depósito Full igual al depósito general**: rechazado en la configuración (US3 escenario 3),
  porque reflejar la existencia de Mercado Libre sobre el depósito propio destruiría el stock real
  del negocio.
- **Orden Full de una publicación no vinculada**: si la orden no puede asociarse a una publicación
  vinculada, no hay forma de saber si es Full; se usa el depósito general.
- **Existencia informada por Mercado Libre igual a la que ya tiene el CRM**: no se genera ningún
  ajuste ni registro, para no ensuciar el histórico de movimientos con ruido.
- **Venta Full que deja el depósito Full en negativo**: la Venta se crea igual (el hecho ya ocurrió
  en Mercado Libre); la próxima lectura desde Mercado Libre corrige la existencia.
- **Modo sólo lectura activo**: se suspende el envío de stock hacia Mercado Libre (irrelevante para
  Full, que ya está excluido), pero el reflejo de existencias desde Mercado Libre hacia el depósito
  Full continúa funcionando, porque no implica ninguna escritura hacia Mercado Libre.
- **Dos publicaciones Full del mismo producto**: si comparten el inventario de Mercado Libre, su
  existencia se computa una sola vez; si son inventarios distintos, se suman. Nunca una publicación
  sobrescribe lo reflejado por la otra.
- **Orden Full de un producto sin depósito Full pero con publicaciones Full ya reflejadas**: no puede
  darse, porque sin depósito configurado tampoco se refleja existencia; la Venta cae al depósito
  general y el stock Full simplemente no se lleva en el CRM.

## Requirements *(mandatory)*

### Functional Requirements

#### Clasificación de las publicaciones

- **FR-001**: El sistema MUST registrar, para cada publicación vinculada de Mercado Libre, su tipo
  de logística informado por Mercado Libre, distinguiendo explícitamente el valor que corresponde a
  Full del resto.
- **FR-002**: El sistema MUST refrescar periódicamente el tipo de logística de todas las
  publicaciones vinculadas, sin intervención del usuario, de modo que un cambio hecho en Mercado
  Libre se refleje en el CRM.
- **FR-003**: El sistema MUST determinar el tipo de logística de una publicación en el momento en
  que se la vincula, sin esperar al refresco periódico.
- **FR-004**: Ante un fallo al consultar Mercado Libre, el sistema MUST conservar el último tipo de
  logística conocido de cada publicación afectada y MUST continuar procesando las restantes.
- **FR-005**: Una publicación sin tipo de logística conocido MUST tratarse como **no Full** en todas
  las reglas de esta especificación.
- **FR-005a**: Un tipo de logística **desconocido o nuevo** que Mercado Libre introduzca a futuro
  MUST tratarse como **no Full** y MUST persistirse y mostrarse tal cual lo informa Mercado Libre, sin
  descartarlo. Sólo el valor que designa Full activa el tratamiento especial; el sistema nunca infiere
  Full a partir de un valor que no conoce.

#### Stock hacia Mercado Libre

- **FR-006**: El sistema MUST excluir a las publicaciones Full de toda actualización de existencias
  enviada hacia Mercado Libre, tanto en la sincronización periódica como en la forzada. El envío de
  existencias desde el CRM MUST limitarse a las publicaciones cuya existencia Mercado Libre permite
  escribir, es decir las que no están en Full.
- **FR-007**: Una publicación Full excluida NO MUST registrarse como error de sincronización ni
  contarse como fallo, y MUST quedar liberada de su marca de "pendiente de sincronizar" para que no
  se reintente indefinidamente.
- **FR-008**: El resultado de una sincronización MUST informar cuántas publicaciones fueron
  omitidas por ser Full, diferenciándolas de las actualizadas y de las que fallaron.

#### Stock desde Mercado Libre

- **FR-009**: El sistema MUST leer de Mercado Libre la existencia **vendible del centro de
  distribución de Mercado Libre** correspondiente al inventario de cada publicación Full, y MUST
  reflejarla en el depósito Full configurado, dejando la existencia del CRM igual a la informada por
  Mercado Libre. La existencia declarada como no vendible NO MUST computarse.
- **FR-009c**: El sistema NO MUST intentar escribir la existencia del centro de distribución de
  Mercado Libre bajo ninguna circunstancia. La reposición de mercadería hacia Full la gestiona el
  negocio por fuera del CRM.
- **FR-009a**: El reflejo de existencias MUST alcanzar a **todas** las publicaciones Full en cada
  corrida, con independencia de que estén o no marcadas como pendientes de sincronizar. La marca de
  pendiente responde a movimientos hechos en el CRM y nunca se activaría por un cambio ocurrido en
  Mercado Libre.
- **FR-009b**: Cuando varias publicaciones Full correspondan al mismo producto del CRM, el sistema
  MUST deduplicar la existencia por inventario de Mercado Libre: las publicaciones que comparten
  inventario MUST computarse una sola vez, y las de inventarios distintos MUST sumarse. En ningún
  caso una publicación puede pisar la existencia reflejada por otra.
- **FR-010**: El reflejo de existencias MUST dejar registro trazable del ajuste realizado,
  identificable como originado por la sincronización de Full.
- **FR-011**: El reflejo de existencias MUST afectar únicamente al depósito Full configurado y NO
  MUST alterar la existencia de ningún otro depósito.
- **FR-012**: Si la existencia informada por Mercado Libre coincide con la que ya tiene el CRM, el
  sistema NO MUST generar ajuste ni registro alguno.
- **FR-013**: El ajuste de existencias producido por el reflejo desde Mercado Libre NO MUST
  desencadenar un envío de stock desde el CRM hacia Mercado Libre.
- **FR-014**: Si no hay depósito Full configurado, o el configurado no está activo, el sistema NO
  MUST reflejar existencias y MUST informar esa condición en el resultado de la sincronización, sin
  interrumpir el resto de la corrida.
- **FR-014a**: El reflejo de existencias desde Mercado Libre hacia el CRM MUST seguir ejecutándose
  con el "modo sólo lectura" activo. Ese modo restringe únicamente las escrituras **hacia** Mercado
  Libre; traer información hacia el CRM no las involucra.
- **FR-014b**: Un vínculo Full cuyo producto del CRM ya no exista MUST saltearse sin generar ajuste
  ni error, igual que hace hoy el envío de existencias.
- **FR-014c**: Si un mismo inventario de Mercado Libre resulta compartido por vínculos Full que
  apuntan a **productos distintos** del CRM, el sistema NO MUST reflejar ese inventario en ningún
  depósito y MUST reportarlo como inconsistencia de vinculación a resolver por el usuario. Repartir o
  duplicar esa existencia sería inventar stock.

#### Configuración

- **FR-015**: Los usuarios MUST poder elegir, desde la Configuración de Mercado Libre, un depósito
  del CRM como "Depósito para publicaciones Full", mediante un selector con búsqueda.
- **FR-016**: El campo "Depósito para publicaciones Full" MUST ser opcional; sin valor, el sistema
  opera con el comportamiento previo a esta funcionalidad salvo por la exclusión de FR-006.
- **FR-017**: El sistema MUST rechazar la configuración si el depósito elegido para Full coincide
  con el depósito general de Mercado Libre, explicando el motivo.
- **FR-018**: El sistema NO MUST crear depósitos automáticamente; el depósito destinado a Full lo da
  de alta el usuario desde la gestión de Depósitos.
- **FR-019**: El guardado de la configuración MUST realizarse sin recargar la página y MUST
  confirmarse mediante una notificación.

#### Ventas de órdenes Full

- **FR-020**: Cuando **todas** las líneas de una orden de Mercado Libre correspondan a publicaciones
  Full y exista un depósito Full configurado y activo, la Venta generada MUST quedar imputada a ese
  depósito Full y su descuento de existencias MUST impactar únicamente allí.
- **FR-020a**: Cuando una orden combine líneas Full y de logística propia, la Venta MUST quedar
  imputada al depósito general de Mercado Libre. No se admite repartir una Venta entre dos depósitos.
- **FR-020b**: El depósito imputado a la Venta y el depósito del que se descuentan las existencias
  MUST ser siempre el mismo. No puede darse que la Venta quede registrada contra un depósito y el
  movimiento de stock impacte en otro.
- **FR-021**: Cuando no exista depósito Full configurado y activo, o la orden no corresponda a una
  publicación Full, la Venta MUST quedar imputada al depósito general de Mercado Libre.
- **FR-022**: La ausencia de configuración de depósito Full NO MUST impedir ni demorar la conversión
  de una orden en Venta bajo ninguna circunstancia.
- **FR-023**: El criterio de imputación de depósito aplicado a cada Venta creada desde Mercado Libre
  MUST quedar registrado de forma consultable para poder auditar por qué una Venta descontó de un
  depósito y no del otro.

#### Visualización

- **FR-024**: La pantalla de Vinculaciones de Mercado Libre MUST mostrar el tipo de logística de cada
  publicación en términos legibles, con un distintivo destacado y exclusivo "FULL" para las que estén
  en Full.
- **FR-025**: Los usuarios MUST poder filtrar el listado de Vinculaciones por tipo de logística —
  incluyendo Full, los demás tipos conocidos y las aún sin clasificar—, sin recargar la página.
- **FR-026**: La pantalla de Configuración de Mercado Libre MUST indicar cuándo no hay depósito Full
  configurado habiendo publicaciones Full vinculadas, para que el usuario sepa que le falta
  completar la configuración.

### Key Entities

- **Vínculo de publicación**: la asociación entre una publicación de Mercado Libre y un producto del
  CRM. Suma el **tipo de logística** de la publicación y la marca temporal de cuándo se determinó.
  Es lo que permite responder "¿esta publicación es Full?".
- **Configuración de Mercado Libre**: registro único de parámetros de la integración. Suma el
  **depósito para publicaciones Full**, opcional, que convive con el depósito general ya existente
  sin reemplazarlo.
- **Depósito**: entidad ya existente del CRM. Uno de ellos pasa a representar, por configuración, la
  mercadería del negocio alojada en el centro de distribución de Mercado Libre.
- **Venta**: entidad ya existente. Su imputación de depósito pasa a depender del tipo de logística de
  la publicación de origen cuando proviene de una orden de Mercado Libre.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: El 100% de las publicaciones en Full deja de recibir actualizaciones de existencias
  desde el CRM, y el 100% de las de logística propia las sigue recibiendo.
- **SC-002**: El responsable del negocio puede identificar todas las publicaciones en Full desde el
  CRM en menos de 15 segundos, sin entrar a Mercado Libre.
- **SC-003**: Tras una sincronización, la existencia registrada en el depósito Full coincide con la
  informada por Mercado Libre para el 100% de las publicaciones Full con depósito configurado.
- **SC-004**: El 100% de las Ventas originadas en órdenes Full, con depósito Full configurado,
  descuenta de ese depósito y no del depósito físico del negocio.
- **SC-005**: Ninguna orden de Mercado Libre queda sin convertir en Venta a causa de la
  configuración de depósito Full, esté configurada o no.
- **SC-006**: El responsable del negocio puede configurar el depósito de Full en menos de 1 minuto,
  sin asistencia y sin que la página se recargue.
- **SC-007**: Las publicaciones de logística propia mantienen exactamente el comportamiento previo:
  cero cambios en el depósito imputado a sus Ventas y en el stock que se les informa a Mercado Libre.

## Assumptions

- **Frecuencia del reflejo de stock**: la lectura de existencias desde Mercado Libre hacia el
  depósito Full se realiza dentro de la misma sincronización de stock que ya existe (periódica y
  forzada), sin introducir una programación separada. Se asume que la frecuencia actual es adecuada
  también para Full.
- **Refresco de la clasificación**: el tipo de logística se refresca con la misma cadencia diaria
  con la que ya se refresca la clasificación de tipo de publicación de cada vínculo, aprovechando
  que se consulta la misma información de Mercado Libre.
- **Granularidad del stock**: se trabaja con la existencia total de la publicación, sin desagregar
  por variante — mismo criterio que usa hoy el CRM al informarle stock a Mercado Libre.
- **Origen de la determinación "orden Full"**: se asume que el tipo de logística de la publicación
  vinculada es suficiente para clasificar la orden, sin necesidad de consultar el envío en Mercado
  Libre. Verificado que el detalle de la orden no trae esa información de forma directa.
- **Alcance de la corrección**: la funcionalidad rige de aquí en adelante. Las Ventas ya creadas con
  el depósito general no se reimputan retroactivamente, ni se corrige el histórico de existencias.
- **Alta del depósito Full**: se asume que el usuario dará de alta un depósito propio (por ejemplo
  "Mercado Libre Full") desde la gestión de Depósitos existente, antes de configurarlo.
- **Reposición hacia Full**: decisión explícita del negocio — el envío de mercadería del depósito
  propio al centro de distribución de Mercado Libre **se gestiona manualmente**. Si se quiere dejar
  registro en el CRM, se hace con un movimiento entre depósitos con las herramientas ya existentes.
  Automatizarlo queda fuera de alcance, y además Mercado Libre no lo permitiría por API.
- **Divergencia de fichas en Mercado Libre**: se detectó que un mismo artículo físico puede existir
  en Mercado Libre como dos productos de usuario distintos, cada uno con su propia existencia de
  domicilio. Esa inconsistencia es de Mercado Libre y esta funcionalidad no la corrige; sólo evita
  que contamine el depósito Full leyendo la existencia desde el inventario del centro de
  distribución y no sumando publicaciones.
- **Volumen**: se asume que la cantidad de publicaciones Full se mantiene en el orden de unidades a
  decenas, no cientos, por lo que no se requieren optimizaciones específicas de volumen.

## Out of Scope

- Automatizar el envío de mercadería del negocio hacia el centro de distribución de Mercado Libre.
- Reimputar retroactivamente las Ventas ya creadas ni corregir el histórico de existencias.
- Aplicar el mismo tratamiento a otras integraciones (Tiendanube u otras).
- Gestionar costos, comisiones o cargos de almacenamiento propios del servicio Full.
- Desagregar el stock de Full por variante de producto.
