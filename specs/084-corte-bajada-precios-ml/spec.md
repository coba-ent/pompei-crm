# Feature Specification: Corte de seguridad para las bajadas de precio hacia Mercado Libre

**Feature Branch**: `084-corte-bajada-precios-ml`

**Created**: 2026-08-26

**Status**: Draft

**Input**: User description: "Corte de seguridad para las bajadas de precio hacia Mercado Libre — que ninguna bajada de precio llegue a Mercado Libre sin que una persona la haya visto, y que cuando algo se desfase alguien se entere."

## Por qué existe esta spec

Dos incidentes reales, los dos verificados en producción y ya documentados en
`docs/documentacion_principal_crm.md` §3.2.ter.quinquies y §3.2.ter.sexies:

| Fecha | Qué pasó | Cómo se detectó |
|-------|----------|-----------------|
| 25/08/2026 | Una importación masiva dejó **18 publicaciones Premium publicadas un 31% por debajo** de su precio real, durante 30 horas | El usuario preguntó. Nada del sistema avisó |
| 06/08/2026 | La migración dejó **146 precios divididos por 1000**; el CRM intentó publicar $262 por un producto de $262.252 | Lo frenó **la validación de Mercado Libre**, no el CRM |

Las dos causas puntuales ya están corregidas. **Esta spec no arregla esos dos bugs: elimina la
condición que los hizo posibles y silenciosos.**

Tres hechos que ordenan todo lo que sigue:

1. **Mercado Libre acepta una bajada de precio sin chistar.** La publicación queda activa, sin
   error, y el vínculo marcado como sincronizado correctamente. Una bajada errónea es
   indistinguible de una correcta desde adentro del CRM.
2. **La única red de contención fue de Mercado Libre.** Un error grosero (÷1000) lo rechaza su
   API; uno plausible (−31%) lo publica sin objeción. La contención propia no existe.
3. **Nada compara lo publicado contra lo que el CRM cree que está publicado.** Un desfasaje puede
   durar indefinidamente sin que nadie lo note.

## Clarifications

### Sesión 2026-08-26 (con el usuario, antes de especificar)

- **Umbral**: 20%, configurable desde la pantalla.
- **Qué hace al frenar**: no publica y queda para aprobar, con el precio publicado intacto.
- **Alcance**: sólo Mercado Libre.
- **Dónde avisa el chequeo**: en la vista `/monitoreo` existente.

### Sesión 2026-08-26 (resueltas durante la especificación)

Cuatro ambigüedades que quedaban y que cambian lo que hay que construir. Se resolvieron con la
opción más segura y quedan acá registradas para que sean discutibles, no invisibles.

- **Cómo sabe el sistema cuál es "el precio publicado"** — es la decisión con más consecuencias.
  Consultar la API antes de cada envío es lo más fiel pero agrega una llamada por publicación, y una
  importación masiva mueve miles de precios de una vez. **Se adopta**: el vínculo recuerda el último
  precio que se publicó con éxito, y el chequeo periódico (US3) lo refresca contra la realidad. Si
  ese dato no existe o quedó viejo por un error, **se retiene** (FR-005): el sistema falla hacia el
  lado seguro, nunca publica a ciegas. La consulta directa a la API queda como refuerzo del chequeo
  periódico, no del camino de envío.
- **Borde exacto del umbral** — una caída *igual* al umbral **pasa**; se retiene sólo lo *mayor*.
  Con 20% configurado, −20% se publica y −20,01% se retiene. Es arbitrario, pero tiene que estar
  escrito para que el test lo pueda fijar.
- **Dónde se resuelven las retenciones** — en la pantalla de **Vinculaciones**, que es donde ya vive
  el estado por publicación. El monitoreo muestra el conteo y enlaza; no duplica las acciones.
- **Cada cuánto corre el chequeo** — una vez por día, más ejecución a demanda desde el monitoreo. Un
  desfasaje detectado dentro de las 24 horas hubiera acortado el incidente del 25/08 de 30 horas a
  menos de una jornada. Más frecuencia multiplicaría llamadas a la API sin ganancia proporcional.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Ninguna bajada grande se publica sin que alguien la vea (Priority: P1)

Quien administra los precios cambia un precio en el CRM —a mano, por importación masiva o
cambiando la lista configurada— y el sistema, antes de enviarlo a Mercado Libre, compara el precio
propuesto contra el que la publicación tiene **publicado hoy**. Si la caída supera el umbral
configurado, **no lo envía**: deja la publicación con su precio anterior intacto en Mercado Libre y
la marca como "requiere aprobación", guardando el precio propuesto. Desde la pantalla de
Vinculaciones esa persona ve la lista de retenidas con los dos importes y el porcentaje de caída, y
decide aprobar (se envía) o rechazar (se descarta).

**Why this priority**: es lo único que impide que se venda barato. Sin esto, todo lo demás avisa
tarde. Los dos incidentes se habrían frenado acá: el de −31% supera el umbral del 20%, y el de
÷1000 es una caída del 99,9%.

**Independent Test**: cambiar en el CRM el precio de un producto vinculado a la mitad y verificar
que en Mercado Libre el precio no cambió, que la publicación aparece como retenida, y que al
aprobarla desde la pantalla el precio sí se publica.

**Acceptance Scenarios**:

1. **Given** una publicación con precio $100.000 publicado y un umbral del 20%, **When** el precio
   del producto en su lista pasa a $70.000 (−30%), **Then** el sistema no envía nada a Mercado
   Libre, la publicación queda "requiere aprobación" con el propuesto $70.000 registrado, y el
   precio en Mercado Libre sigue siendo $100.000.
2. **Given** la misma publicación, **When** el precio pasa a $85.000 (−15%), **Then** el sistema lo
   envía normalmente sin retenerlo.
3. **Given** la misma publicación, **When** el precio pasa a $130.000 (+30%), **Then** el sistema lo
   envía sin retenerlo: **el corte es sólo para bajadas**.
4. **Given** una publicación retenida, **When** la persona la aprueba, **Then** el precio propuesto
   se envía a Mercado Libre y la publicación deja de estar retenida.
5. **Given** una publicación retenida, **When** la persona la rechaza, **Then** el propuesto se
   descarta, la publicación deja de estar retenida y el precio en Mercado Libre no cambió.
6. **Given** una publicación retenida por $70.000, **When** antes de resolverla el precio del
   producto cambia otra vez a $95.000 (−5%), **Then** el propuesto retenido se reemplaza por el
   nuevo, que al no superar el umbral se envía y deja la publicación sin retención.
7. **Given** un cambio de precio que afecta a diez publicaciones y sólo tres superan el umbral,
   **When** se procesa, **Then** las siete restantes se publican normalmente y sólo las tres quedan
   retenidas: **una retención nunca frena al resto**.

---

### User Story 2 - Cambiar la lista configurada muestra los números antes de aplicar (Priority: P2)

Hoy, cambiar el select de "Lista de precios" en la configuración de Mercado Libre y guardar
republica inmediatamente todas las publicaciones con la lista nueva: sin confirmación, sin previa y
sin deshacer. Con esta historia, al guardar un cambio de lista el sistema primero **muestra el
impacto con números concretos** —cuántas publicaciones cambian, cuántas suben, cuántas bajan,
cuántas quedarían retenidas por el corte de la historia 1, y los importes extremos— y recién aplica
si la persona confirma.

**Why this priority**: es la vía de daño más grande que queda abierta —un clic afecta a todas las
publicaciones a la vez— pero requiere una acción deliberada, mientras que la historia 1 protege
también contra lo accidental.

**Independent Test**: cambiar la lista configurada por una notoriamente más barata y verificar que
antes de aplicar aparece el resumen con los conteos, que cancelar no modifica ni la configuración ni
ningún precio, y que confirmar aplica el cambio.

**Acceptance Scenarios**:

1. **Given** la lista general configurada en "ML", **When** se la cambia a "Mayorista/obras" y se
   guarda, **Then** el sistema muestra cuántas publicaciones se verían afectadas, cuántas suben,
   cuántas bajan y cuántas quedarían retenidas, y **no** aplica nada todavía.
2. **Given** ese resumen en pantalla, **When** la persona cancela, **Then** ni la configuración ni
   ningún precio de Mercado Libre cambian.
3. **Given** ese resumen en pantalla, **When** la persona confirma, **Then** la configuración se
   guarda y los precios se envían, aplicándose el corte de la historia 1 publicación por publicación.
4. **Given** un guardado de la configuración que **no** cambia ninguna de las dos listas, **When** se
   guarda, **Then** no se pide confirmación ni se republica nada.

---

### User Story 3 - Un chequeo periódico avisa cuando lo publicado no coincide (Priority: P3)

Periódicamente el sistema compara **cada publicación contra el precio que le corresponde según su
tipo** y muestra las diferencias en la vista de monitoreo que ya existe, junto al panel de stock,
con el detalle publicación por publicación.

**Why this priority**: no evita el daño, lo detecta. Es la red que faltó el 25/08, cuando 18
publicaciones estuvieron 30 horas baratas sin que nada avisara.

**Independent Test**: alterar el precio de una publicación por fuera del CRM, correr el chequeo y
verificar que aparece listada en la vista de monitoreo con los dos importes.

**Acceptance Scenarios**:

1. **Given** una publicación cuyo precio publicado difiere del que le corresponde, **When** corre el
   chequeo, **Then** aparece en el panel de monitoreo con su identificador, el precio del CRM, el
   publicado, la diferencia y el tipo de publicación.
2. **Given** todas las publicaciones coincidiendo, **When** corre el chequeo, **Then** el panel
   informa que no hay diferencias, con la fecha de la última corrida.
3. **Given** una publicación **Premium**, **When** corre el chequeo, **Then** se la compara contra
   la **lista Premium** y no contra la general.
4. **Given** una publicación retenida por el corte de la historia 1, **When** corre el chequeo,
   **Then** se la muestra identificada como retenida y **no** como un desfasaje a corregir: es una
   diferencia esperada.
5. **Given** una publicación que Mercado Libre no devuelve o devuelve con error, **When** corre el
   chequeo, **Then** se la informa aparte como no verificable, sin contarla como coincidente.

---

### User Story 4 - Las configuraciones que publican barato en silencio son visibles (Priority: P4)

Dos situaciones publican una Premium al precio Clásico —un 31% menos— sin que nada falle: que el
producto no tenga precio cargado en la lista Premium, y que el vínculo todavía no sepa de qué tipo
es. La primera se hace visible; la segunda se cierra.

**Why this priority**: hoy no hay ningún caso de ninguna de las dos, pero las dos aparecen solas
—una borrando un precio, la otra vinculando una publicación nueva— y ninguna avisa.

**Independent Test**: quitarle a un producto Premium su precio en la lista Premium y verificar que
aparece advertido; crear un vínculo sin tipo conocido y verificar que no se le publica precio.

**Acceptance Scenarios**:

1. **Given** una publicación Premium cuyo producto no tiene precio en la lista Premium, **When** se
   mira el monitoreo o la pantalla de Vinculaciones, **Then** aparece advertida indicando que va a
   cotizar por la lista general.
2. **Given** un vínculo cuyo tipo de publicación todavía se desconoce, **When** el precio del
   producto cambia, **Then** el sistema **no** publica precio y deja el vínculo pendiente.
3. **Given** ese mismo vínculo, **When** se conoce su tipo, **Then** el pendiente se resuelve con el
   precio de la lista que le corresponde.

---

### Edge Cases

- **La publicación no tiene precio publicado conocido** (vínculo nuevo, nunca sincronizado): no hay
  contra qué comparar la caída. El sistema debe **retener y pedir aprobación**, no publicar a ciegas.
- **Mercado Libre no responde** al consultar el precio publicado: no se puede evaluar el corte. El
  envío no se realiza y queda pendiente; **nunca se publica sin haber podido evaluar**.
- **Precio propuesto de $0 o negativo**: se retiene siempre, cualquiera sea el umbral.
- **Umbral configurado en 0%**: retiene toda bajada, por mínima que sea. Es válido y debe funcionar.
- **Umbral configurado en 100%**: no retiene nada por porcentaje, pero el precio $0 y el precio
  publicado desconocido se siguen reteniendo.
- **La publicación está pausada, cerrada o bajo revisión**: el corte se evalúa igual; que Mercado
  Libre después rechace el envío es un asunto distinto y ya tiene su tratamiento.
- **Una misma publicación recibe dos cambios de precio seguidos**: prevalece siempre el último
  propuesto; no se acumulan retenciones.
- **Un producto con varias publicaciones de distinto tipo**: cada una se evalúa por separado contra
  su propio precio publicado y su propia lista.
- **Se aprueba una retención vieja** cuyo precio de lista ya cambió: se envía el precio **vigente**
  de la lista, no el que quedó congelado, y se avisa que cambió.
- **El corte y el modo sólo lectura conviven**: si las escrituras están deshabilitadas, se conserva
  el pendiente como hasta ahora; el corte no lo pisa ni lo resuelve.

## Requirements *(mandatory)*

### Funcionales — corte de seguridad (US1)

- **FR-001**: El sistema DEBE evaluar toda propuesta de precio hacia Mercado Libre contra el precio
  actualmente publicado en esa publicación, **antes** de enviarla.
- **FR-001a**: El sistema DEBE recordar, por publicación, el último precio que se publicó con éxito,
  y el chequeo periódico DEBE refrescarlo contra el precio real.
- **FR-002**: El sistema DEBE retener el envío cuando la caída porcentual respecto del precio
  publicado sea **mayor** al umbral configurado.
- **FR-003**: El umbral DEBE ser configurable desde la pantalla de configuración de Mercado Libre,
  expresado en porcentaje, con valor por defecto **20%**.
- **FR-004**: Una subida de precio NUNCA se retiene, cualquiera sea su magnitud.
- **FR-005**: El sistema DEBE retener siempre, sin importar el umbral, cuando el precio propuesto sea
  menor o igual a cero, o cuando no haya podido obtener el precio publicado.
- **FR-006**: Al retener, el sistema NO DEBE enviar nada a Mercado Libre; el precio publicado queda
  intacto.
- **FR-007**: Al retener, el sistema DEBE registrar el precio propuesto, el precio publicado contra
  el que se comparó, el porcentaje de caída y el momento de la retención.
- **FR-008**: El corte DEBE aplicarse en **todos** los caminos que empujan precio: el cambio de
  precio en el CRM, el reintento de pendientes, y la sincronización de una lista completa.
- **FR-009**: Una retención NO DEBE interrumpir el procesamiento del resto de las publicaciones de la
  misma corrida.
- **FR-010**: Un nuevo precio propuesto para una publicación ya retenida DEBE reemplazar al anterior
  y volver a evaluarse desde cero.
- **FR-011**: Los usuarios DEBEN poder ver las publicaciones retenidas con su precio publicado, el
  propuesto, el porcentaje de caída y el motivo.
- **FR-012**: Los usuarios DEBEN poder **aprobar** una retención, lo que envía el precio vigente de
  la lista que corresponde a esa publicación y levanta la retención.
- **FR-013**: Los usuarios DEBEN poder **rechazar** una retención, lo que la levanta sin enviar nada.
- **FR-014**: Al aprobar, si el precio vigente de la lista difiere del que se retuvo, el sistema DEBE
  avisarlo antes de enviar.
- **FR-015**: Aprobar y rechazar DEBEN quedar registrados con usuario, momento e importes.

### Funcionales — confirmación al cambiar la lista (US2)

- **FR-016**: Un cambio de la lista general o de la lista Premium configuradas NO DEBE republicar
  precios hasta que la persona lo confirme explícitamente.
- **FR-017**: Antes de confirmar, el sistema DEBE mostrar: cantidad de publicaciones afectadas,
  cuántas suben, cuántas bajan, cuántas quedarían retenidas por el corte, y la bajada más grande.
- **FR-018**: Cancelar la confirmación NO DEBE modificar la configuración ni ningún precio.
- **FR-019**: Un guardado que no cambie ninguna de las dos listas NO DEBE pedir confirmación ni
  republicar.

### Funcionales — chequeo periódico (US3)

- **FR-020**: El sistema DEBE comparar periódicamente el precio publicado de cada vínculo contra el
  precio de **la lista que le corresponde según su tipo de publicación**.
- **FR-021**: La resolución de qué lista corresponde a cada vínculo DEBE ser la misma que usa el
  envío; no puede existir una segunda definición.
- **FR-022**: El resultado DEBE mostrarse en la vista de monitoreo existente, con el detalle por
  publicación: identificador, producto, tipo, precio del CRM, precio publicado y diferencia.
- **FR-023**: Las publicaciones retenidas DEBEN presentarse como tales y no como desfasajes.
- **FR-024**: Las publicaciones que no se pudieron consultar DEBEN informarse aparte, nunca contarse
  como coincidentes.
- **FR-025**: El panel DEBE mostrar cuándo corrió el chequeo por última vez.
- **FR-026**: El chequeo DEBE poder ejecutarse a demanda además de su corrida periódica.
- **FR-027**: El chequeo es de **sólo lectura**: NO corrige precios por su cuenta.

### Funcionales — configuraciones riesgosas (US4)

- **FR-028**: El sistema DEBE advertir las publicaciones Premium cuyo producto no tiene precio en la
  lista Premium configurada, indicando que cotizan por la lista general.
- **FR-029**: El sistema NO DEBE publicar precio a un vínculo cuyo tipo de publicación se desconoce;
  lo deja pendiente hasta conocerlo.
- **FR-030**: Al conocerse el tipo de un vínculo pendiente por esa causa, su precio DEBE resolverse
  con la lista que le corresponde.

### Transversales

- **FR-031**: Toda retención, aprobación y rechazo DEBE quedar en el historial de operaciones de la
  integración.
- **FR-032**: Ningún dato sensible (credenciales, tokens) puede quedar registrado en ese historial.
- **FR-033**: Las pantallas nuevas DEBEN respetar las especificaciones de diseño obligatorias del
  proyecto: tablas DataTables con carga por AJAX, altas/ediciones/acciones por modal Bootstrap sin
  recargar la página, notificaciones por toast, y Select2 en los selects de datos dinámicos.

### Key Entities

- **Retención de precio**: el hecho de que una propuesta de precio quedó frenada. Pertenece a una
  publicación vinculada; guarda el precio propuesto, el precio publicado contra el que se comparó, el
  porcentaje de caída, el motivo (supera el umbral / precio inválido / precio publicado desconocido),
  el momento, y cómo se resolvió (aprobada, rechazada, reemplazada por una propuesta nueva). Una
  publicación tiene a lo sumo **una** retención sin resolver.
- **Precio publicado conocido**: por publicación, el último precio que se envió y Mercado Libre
  aceptó, con el momento en que se supo. Es la referencia contra la que se mide la caída. Puede no
  existir (vínculo nuevo), y en ese caso el envío se retiene.
- **Umbral de caída**: porcentaje máximo de bajada admitido sin aprobación. Vive junto al resto de la
  configuración de la integración, que es única.
- **Resultado del chequeo de precios**: la foto de la última comparación contra Mercado Libre.
  Guarda, por publicación, el precio del CRM, el publicado, la lista contra la que se comparó y el
  estado (coincide / difiere / retenida / no verificable), más el momento de la corrida.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Ninguna publicación puede quedar publicada con una caída de precio mayor al umbral sin
  que una persona la haya aprobado explícitamente. Verificable reproduciendo los dos incidentes: una
  bajada del 31% y una división por 1000 quedan las dos retenidas, con el precio publicado intacto.
- **SC-002**: Una bajada dentro del umbral y cualquier subida siguen publicándose sin intervención:
  el corte no agrega fricción a la operación normal.
- **SC-003**: Cambiar la lista de precios configurada nunca modifica un precio publicado sin que
  antes se hayan mostrado en pantalla la cantidad de publicaciones afectadas y cuántas bajan.
- **SC-004**: Un desfasaje entre lo publicado y lo que el CRM cree publicado es visible en el
  monitoreo dentro del período de chequeo, sin que nadie tenga que consultar la API a mano.
- **SC-005**: El chequeo no produce falsos positivos por tipo de publicación: con todos los precios
  correctos, reporta cero diferencias (comparar las Premium contra la lista general produciría 30
  falsos positivos sobre 270 publicaciones).
- **SC-006**: Una retención en una publicación no impide que el resto de la corrida se publique.
- **SC-007**: Quien administra precios puede entender por qué una publicación quedó retenida y
  resolverla —aprobar o rechazar— sin salir de la pantalla de Vinculaciones.

## Assumptions

- **La comparación es contra el precio publicado en Mercado Libre, no contra el precio anterior del
  CRM.** Es lo que evita el caso del 25/08: ahí el precio del CRM cambió poco (la lista general se
  actualizó normalmente) pero el publicado cayó un 31%, porque el que estaba publicado venía de otra
  lista. Comparar CRM contra CRM no habría detectado nada.
- **El corte es unidireccional**: sólo bajadas. Una subida errónea no hace perder dinero en una venta
  y frenarla entorpecería la operación normal de actualización de precios.
- **El umbral es único para toda la integración**, no por producto ni por categoría. No hay evidencia
  de que haga falta más granularidad, y agregarla ahora sería complejidad sin caso de uso.
- **20% como valor por defecto**: deja pasar los ajustes y descuentos habituales y atrapa los dos
  escenarios peligrosos conocidos —el salto de lista Premium a Clásica (−31%) y el de lista general a
  Mayorista (−30%)— además de cualquier error de escala.
- **Alcance limitado a Mercado Libre.** Tiendanube comparte la exposición de publicar cualquier
  precio sin validar, pero no tiene el problema de las dos listas (usa una sola). Queda como brecha
  documentada, no cubierta acá.
- **La resolución de lista por tipo de publicación ya existe y es correcta** (spec 050); esta spec la
  reusa y no la redefine.
- **La vista de monitoreo ya existe** y admite paneles adicionales; esta spec agrega uno, no crea una
  pantalla nueva.
- **Consultar el precio publicado tiene costo de API.** Se asume aceptable dentro de los límites de
  uso actuales para el volumen de publicaciones vigente (270 al 26/08/2026); si creciera mucho, el
  plan deberá contemplarlo.
- Los dos incidentes que motivan esta spec **ya están corregidos**; esta spec no los vuelve a
  arreglar, previene su repetición por cualquier vía.

## Out of Scope

- Tiendanube y cualquier otro canal de venta.
- Corte de seguridad sobre **stock** (existe el chequeo de stock, con su propio mecanismo).
- Corrección automática de un desfasaje detectado por el chequeo: informa, no corrige.
- Umbrales diferenciados por producto, categoría o publicación.
- Avisos por fuera de la aplicación (mail, notificaciones push) — depende del módulo de
  notificaciones todavía no construido (§7 del documento principal).
- Rehacer la resolución de lista por tipo de publicación (spec 050).
