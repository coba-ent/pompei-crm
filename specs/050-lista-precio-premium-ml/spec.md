# Feature Specification: Lista de Precios diferenciada para publicaciones Premium de Mercado Libre

**Feature Branch**: `050-lista-precio-premium-ml`

**Created**: 2026-08-06

**Status**: Draft

**Input**: User description: "Lista de precios por defecto para publicaciones Premium de Mercado Libre: hoy `SincronizadorPrecios` usa una única `lista_precio_id` guardada en `ml_configuracion` y la aplica a TODOS los vínculos de `ml_publicacion_producto` sin distinguir el tipo de publicación, así que cada sincronización de precios pisa por igual las publicaciones Premium y las Clásicas con la misma lista. Se necesita poder configurar una Lista de Precios separada específicamente para las publicaciones Premium (`listing_type_id = gold_pro`), de forma que el sincronizador use la lista Premium para esos vínculos y la lista general para el resto. Hoy `ml_publicacion_producto` no persiste el tipo de publicación — hay que agregarlo y mantenerlo actualizado."

## Clarifications

### Session 2026-08-06

- Q: ¿Con qué frecuencia debería el sistema refrescar el tipo de publicación (Premium/Clásica) de cada
  vínculo consultando la API de ML? → A: Diaria — una corrida por día alcanza y evita sumar cientos de
  llamadas extra a la API en cada corrida de stock (que hoy es cada 15 minutos), dado que el tipo de
  publicación cambia con muy poca frecuencia en la práctica.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Configurar una Lista de Precios propia para publicaciones Premium (Priority: P1)

Como responsable de la cuenta de Mercado Libre del negocio, quiero elegir, además de la Lista de
Precios general que ya se usa para sincronizar precios hacia Mercado Libre, una segunda Lista de
Precios específica para las publicaciones de tipo Premium, para que la sincronización de precios deje
de pisar con el mismo valor publicaciones que tienen una lógica de precio distinta (las Premium pagan
una comisión mayor a Mercado Libre y suelen cargarse con un precio más alto para compensarla).

**Why this priority**: Es el corazón del pedido — sin esto, toda sincronización de precios sigue
pisando las publicaciones Premium con el valor de la lista general, que es exactamente el problema que
motiva la feature. Sin esta capacidad de configuración no hay nada que probar en las demás historias.

**Independent Test**: Puede probarse configurando la Lista de Precios Premium en la pantalla de
configuración de Mercado Libre y confirmando que el valor queda guardado y se puede volver a editar,
sin necesidad de que haya corrido todavía ninguna sincronización.

**Acceptance Scenarios**:

1. **Given** la pantalla de configuración de Mercado Libre con la Lista de Precios general ya
   configurada, **When** el usuario elige además una Lista de Precios para publicaciones Premium y
   guarda, **Then** el sistema persiste esa elección junto con el resto de la configuración de Mercado
   Libre.
2. **Given** una Lista de Precios Premium ya configurada, **When** el usuario la cambia por otra o la
   deja sin seleccionar, **Then** el sistema actualiza la configuración y dejar el campo vacío es una
   opción válida (no obliga a tener una lista Premium distinta de la general).

---

### User Story 2 - La sincronización de precios usa la lista correcta según el tipo de publicación (Priority: P1)

Como responsable de la cuenta, quiero que cuando se sincronizan precios hacia Mercado Libre (por
cambio de precio de un producto, por la acción manual "Sincronizar precios ahora", o al cambiar la
Lista de Precios configurada), cada publicación reciba el precio de la lista que le corresponde según
si es Premium o no, para que las publicaciones Premium terminen con el precio pensado para ellas y las
demás sigan usando la lista general sin cambios de comportamiento.

**Why this priority**: Es la otra mitad imprescindible del pedido — configurar la lista Premium (US1)
no tiene ningún efecto real si el sincronizador sigue sin distinguir el tipo de publicación al elegir
qué precio enviar.

**Independent Test**: Puede probarse vinculando una publicación Premium y una Clásica al mismo
producto (o a productos distintos con precio cargado en ambas listas), disparando una sincronización de
precios, y confirmando que a Mercado Libre se envió el valor de la lista Premium para la publicación
Premium y el de la lista general para la Clásica.

**Acceptance Scenarios**:

1. **Given** un producto con precio cargado tanto en la Lista de Precios general como en la Premium, y
   dos publicaciones vinculadas a ese producto (una Premium, una Clásica), **When** se dispara una
   sincronización de precios, **Then** la publicación Premium recibe el precio de la Lista Premium y la
   Clásica recibe el precio de la Lista general.
2. **Given** no hay ninguna Lista de Precios Premium configurada (sólo la general), **When** se
   sincronizan precios, **Then** todas las publicaciones —Premium o no— reciben el precio de la lista
   general, igual que el comportamiento actual.
3. **Given** una publicación Premium cuyo producto no tiene precio cargado en la Lista Premium
   configurada pero sí en la general, **When** se sincroniza esa publicación, **Then** el sistema usa el
   precio de la lista general para esa publicación en lugar de dejarla sin sincronizar.
4. **Given** se cambia cuál es la Lista de Precios Premium configurada, **When** se guarda el cambio,
   **Then** el sistema empuja de inmediato el precio vigente de la nueva lista a todas las publicaciones
   Premium vinculadas que tengan precio cargado ahí, igual que ya ocurre hoy al cambiar la lista general
   (spec 016).

---

### User Story 3 - El tipo de publicación (Premium/Clásica) se mantiene al día automáticamente (Priority: P2)

Como responsable de la cuenta, quiero que el sistema sepa y mantenga actualizado qué publicaciones son
Premium sin que yo tenga que revisarlo a mano en Mercado Libre, para que la sincronización de precios
de la Historia 2 siga siendo correcta si en el futuro cambia el tipo de una publicación (por ejemplo,
si paso una publicación de Clásica a Premium o viceversa desde Mercado Libre).

**Why this priority**: Sin esto, la distinción Premium/Clásica quedaría congelada en el momento en que
se vinculó cada publicación y se desactualizaría con el tiempo — funciona igual el día 1, pero degrada
silenciosamente. No bloquea la entrega de valor de las Historias 1 y 2 (que pueden lanzarse con el tipo
completado sólo al vincular), por eso va en prioridad 2.

**Independent Test**: Puede probarse cambiando manualmente el tipo de una publicación en Mercado Libre
(o simulándolo) y confirmando que, tras la próxima actualización periódica, el sistema refleja el nuevo
tipo y la sincronización de precios de la Historia 2 empieza a usar la lista que corresponde al tipo
nuevo.

**Acceptance Scenarios**:

1. **Given** una publicación recién vinculada al CRM, **When** se completa la vinculación, **Then** el
   sistema consulta y guarda el tipo de publicación (Premium o no) vigente en Mercado Libre en ese
   momento.
2. **Given** una publicación vinculada cuyo tipo cambió del lado de Mercado Libre desde la última
   consulta, **When** corre la actualización periódica del tipo de publicación, **Then** el sistema
   actualiza el tipo guardado para que coincida con el real.
3. **Given** las publicaciones que ya estaban vinculadas antes de esta feature, **When** se despliega la
   feature, **Then** el sistema completa el tipo de publicación de todas ellas sin intervención manual
   (no quedan indefinidamente sin clasificar a la espera de la próxima actualización periódica).

---

### Edge Cases

- ¿Qué pasa si Mercado Libre no puede responder la consulta del tipo de publicación (caída de la
  conexión, publicación pausada o cerrada)? El sistema conserva el último tipo conocido y lo vuelve a
  intentar en la próxima corrida, sin bloquear la sincronización de precios mientras tanto (usa el
  último tipo conocido).
- ¿Qué pasa si una publicación Premium no tiene precio cargado ni en la lista Premium ni en la general?
  Se comporta igual que hoy cuando cualquier publicación no tiene precio cargado en la lista
  configurada: no se envía nada para esa publicación, sin marcarla como error.
- ¿Qué pasa si la Lista de Precios elegida como Premium es la misma que la lista general? Es una
  configuración válida (el usuario puede no querer diferenciar precios) y el comportamiento resultante
  es idéntico al actual: todas las publicaciones reciben el mismo precio.
- ¿Qué pasa con una publicación Premium vinculada a un producto que también tiene otra publicación
  Clásica vinculada (vínculo 1:N, spec 036)? Cada publicación recibe el precio de la lista que
  corresponde a su propio tipo — el tipo se evalúa por publicación, no por producto.
- ¿Qué pasa si se borra o desactiva la Lista de Precios que estaba configurada como Premium? Mismo
  comportamiento que ya existe hoy para la lista general en ese caso (spec 016): deja de tener efecto
  para esas publicaciones hasta que se configure una lista válida.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: El sistema MUST permitir configurar, en la misma pantalla donde hoy se elige la Lista de
  Precios general de Mercado Libre, una Lista de Precios adicional y opcional específica para
  publicaciones Premium.
- **FR-002**: El sistema MUST persistir el tipo de publicación (Premium o no) de cada publicación
  vinculada de Mercado Libre.
- **FR-003**: El sistema MUST completar el tipo de publicación al momento de vincular una publicación
  nueva.
- **FR-004**: El sistema MUST actualizar el tipo de publicación de todas las publicaciones ya
  vinculadas una vez por día, sin depender de una acción manual del usuario y sin acoplarse a la
  corrida de stock (cada 15 minutos) para no multiplicar innecesariamente las llamadas a la API por un
  dato que cambia con muy poca frecuencia.
- **FR-005**: El sistema MUST, al desplegar esta feature, completar el tipo de publicación de todas las
  publicaciones que ya estaban vinculadas antes del despliegue.
- **FR-006**: Al sincronizar el precio de una publicación (por cambio de precio del producto, por la
  acción manual "Sincronizar precios ahora", o al cambiar cuál es la lista configurada), el sistema
  MUST usar la Lista de Precios Premium configurada como fuente del precio si la publicación es Premium
  y hay precio cargado ahí para su producto.
- **FR-007**: Si la publicación es Premium pero su producto no tiene precio cargado en la Lista de
  Precios Premium configurada, el sistema MUST usar el precio de la Lista de Precios general como
  respaldo, en vez de dejar la publicación sin sincronizar.
- **FR-008**: Si no hay ninguna Lista de Precios Premium configurada, el sistema MUST sincronizar todas
  las publicaciones (Premium o no) con la Lista de Precios general, preservando el comportamiento
  actual.
- **FR-009**: Las publicaciones que no son Premium MUST seguir sincronizándose siempre con la Lista de
  Precios general, sin cambios respecto del comportamiento actual.
- **FR-010**: Al guardar un cambio en la Lista de Precios Premium configurada, el sistema MUST empujar
  de inmediato el precio vigente de la nueva lista a todas las publicaciones Premium vinculadas que
  tengan precio cargado ahí, con el mismo criterio que ya aplica hoy al cambiar la Lista de Precios
  general (spec 016).
- **FR-011**: El sistema MUST evaluar el tipo Premium/Clásica por publicación individual, no por
  producto, dado que un mismo producto puede tener varias publicaciones vinculadas de distinto tipo
  (spec 036).

### Key Entities

- **Publicación de Mercado Libre vinculada** (`ml_publicacion_producto`): gana un atributo nuevo que
  indica si la publicación es de tipo Premium o no, mantenido actualizado por el sistema.
- **Configuración de Mercado Libre** (`ml_configuracion`): gana un atributo nuevo, opcional, que
  identifica cuál Lista de Precios usar para las publicaciones Premium — coexiste con el atributo ya
  existente que identifica la Lista de Precios general.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Después de configurar una Lista de Precios Premium, el 100% de las publicaciones Premium
  con precio cargado en esa lista terminan, tras la siguiente sincronización, con el precio de esa
  lista en Mercado Libre — no con el de la lista general.
- **SC-002**: El 100% de las publicaciones que no son Premium siguen recibiendo el precio de la Lista
  de Precios general después de esta feature, sin ninguna publicación Clásica afectada por el cambio.
- **SC-003**: Dentro de las 24 horas de vincular una publicación nueva o de que cambie su tipo del lado
  de Mercado Libre, el tipo guardado en el CRM refleja el valor real.
- **SC-004**: Al desplegar la feature, el 100% de las publicaciones ya vinculadas quedan con su tipo de
  publicación completado, sin necesidad de que el usuario dispare nada manualmente.

## Assumptions

- "Premium" se corresponde con el tipo de publicación `gold_pro` que informa la API de Mercado Libre
  (confirmado contra la cuenta real del negocio: coincide exactamente con el listado de publicaciones
  Premium que maneja el usuario por fuera del CRM). El resto de los tipos (`gold_special`, etc.) se
  consideran "no Premium" para efectos de esta feature.
- La actualización diaria del tipo de publicación (US3/FR-004) corre en su propia corrida programada,
  independiente de la de stock (cada 15 minutos) — el mecanismo concreto (comando propio vs. reutilizar
  infraestructura de scheduler ya existente) es una decisión de implementación para `/speckit-plan`.
- No es necesario mostrar en pantalla, para esta feature, un listado explícito de qué publicaciones son
  Premium — alcanza con que el dato exista y se use correctamente en la sincronización de precios. Si
  el usuario quiere visibilidad de eso en la UI, es una ampliación futura fuera de este alcance.
- El "respaldo a la lista general" (FR-007) es sólo por falta de precio cargado en la lista Premium, no
  un mecanismo de aproximación de precio (no se calcula un +21% ni ningún otro ajuste automático — eso
  ya se resolvió como una operación manual puntual, separada de esta feature).
