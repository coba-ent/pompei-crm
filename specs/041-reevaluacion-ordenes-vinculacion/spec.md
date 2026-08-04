# Feature Specification: Reevaluación automática de órdenes por vinculación tardía

**Feature Branch**: `041-reevaluacion-ordenes-vinculacion`

**Created**: 2026-08-03

**Status**: Draft

**Input**: User description: "Reevaluación automática de órdenes pendientes de conversión (MercadoLibre y TiendaNube) cuando se vincula un producto después de haber sincronizado la orden."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Vincular un producto destraba automáticamente sus órdenes pendientes (Priority: P1)

Un usuario sincroniza órdenes de MercadoLibre o TiendaNube. Algunas quedan marcadas como
"requiere atención" porque el producto vendido en la publicación/variante todavía no está
vinculado a un producto del CRM. El usuario va a la pantalla de vinculación y vincula esa
publicación/variante a un producto. Sin que tenga que volver a sincronizar ni abrir la orden
manualmente, la orden pasa a reflejar su estado real (lista para convertir, o el motivo que
corresponda si todavía falta algo más).

**Why this priority**: Es la causa raíz detectada: el usuario vinculó 395 publicaciones después
de sincronizar sus órdenes y ninguna de esas órdenes reflejó el cambio, generando la falsa
percepción de que "el producto no está vinculado" cuando sí lo estaba. Es el mecanismo que
evita que el problema vuelva a ocurrir.

**Independent Test**: Sincronizar una orden con una publicación/variante sin vincular (queda
"requiere atención"), luego vincular esa publicación/variante a un producto, y verificar que
la orden cambia de estado sin ninguna acción adicional del usuario.

**Acceptance Scenarios**:

1. **Given** una orden de MercadoLibre en estado "requiere atención" por publicación sin
   vincular, **When** el usuario vincula esa publicación a un producto del CRM, **Then** la
   orden pasa a estado "lista para convertir" (si no hay ningún otro motivo pendiente).
2. **Given** una orden de TiendaNube en estado "requiere atención" por variante sin vincular,
   **When** el usuario vincula esa variante a un producto del CRM, **Then** la orden pasa a
   estado "lista para convertir" (si no hay ningún otro motivo pendiente).
3. **Given** una publicación/variante vinculada a un producto, **When** hay varias órdenes
   distintas pendientes que la referencian, **Then** todas esas órdenes se reevalúan, no sólo
   la primera.
4. **Given** una orden ya convertida en venta (tiene venta_id) o cancelada, **When** se vincula
   la publicación/variante que originalmente tenía sin vincular, **Then** esa orden no se
   modifica (no se retocan órdenes ya cerradas).
5. **Given** una orden con más de un motivo de atención pendiente (ej. publicación sin vincular
   Y otro problema), **When** se vincula la publicación, **Then** la orden refleja el motivo
   restante en vez de pasar a "lista" prematuramente.
6. **Given** el canal tiene configurada la creación automática de ventas al quedar la orden
   lista, **When** la reevaluación por vinculación deja la orden en estado "lista", **Then**
   se dispara la misma creación automática de venta que ya ocurre cuando una orden llega
   "lista" durante la sincronización normal.

---

### User Story 2 - La vista de órdenes pendientes siempre refleja el estado real al abrirla (Priority: P2)

Un usuario entra a la pantalla de órdenes pendientes de convertir (de MercadoLibre o de
TiendaNube). Antes de ver el listado, el sistema revisa que ninguna de las órdenes marcadas
"requiere atención" tenga un estado desactualizado respecto de las vinculaciones actuales, y
corrige lo que encuentre. Esto sirve de red de seguridad para casos donde la vinculación se dio
de alta por una vía distinta a la vinculación manual desde el CRM (por ejemplo una importación
masiva), o para cualquier inconsistencia que el mecanismo de la User Story 1 no haya cubierto.

**Why this priority**: Complementa la User Story 1; sin ella, cualquier vía de vinculación que
no pase por el evento cubierto en P1 podría seguir generando el mismo problema. Es de prioridad
menor porque la User Story 1 ya cubre el camino principal (vinculación manual desde el CRM, que
es como ocurrió el incidente real).

**Independent Test**: Provocar una desincronización entre el estado guardado de una orden y el
estado real de sus vinculaciones sin pasar por el flujo normal de vinculación (ej. actualizando
la vinculación por una vía distinta a la pantalla de vinculación), abrir la vista de órdenes
pendientes del canal correspondiente, y verificar que el listado ya muestra el estado corregido.

**Acceptance Scenarios**:

1. **Given** una orden de MercadoLibre marcada "requiere atención / publicación sin vincular"
   cuya publicación ya está vinculada en la base, **When** el usuario abre la vista de órdenes
   pendientes de MercadoLibre, **Then** esa orden aparece con su estado corregido (no como
   pendiente de vincular).
2. **Given** el mismo escenario para TiendaNube (variante sin vincular ya vinculada en la base),
   **When** el usuario abre la vista de órdenes pendientes de TiendaNube, **Then** esa orden
   aparece con su estado corregido.
3. **Given** una cantidad grande de órdenes pendientes en el canal, **When** el usuario abre la
   vista, **Then** el listado se muestra sin una demora perceptible mayor a la que ya tiene hoy
   la carga de esa pantalla.

---

### Edge Cases

- ¿Qué pasa si se desvincula una publicación/variante que ya estaba vinculada (se borra o cambia
  la vinculación)? La orden que antes estaba "lista" por esa vinculación debe volver a marcarse
  con el motivo correspondiente si ya no puede convertirse, siguiendo el mismo mecanismo (evento
  al modificar la vinculación, no sólo al crearla).
- ¿Qué pasa si una publicación/variante tiene varias órdenes pendientes y sólo se vincula
  parcialmente (por ejemplo, un vínculo con condición o variante específica)? La reevaluación
  debe aplicar exactamente la misma lógica de negocio que ya usa `EvaluadorConvertibilidad`
  (y su equivalente de TiendaNube) para decidir si esa orden en particular queda lista o no.
- ¿Qué pasa si la vinculación se crea o edita en medio de una sincronización de órdenes en curso
  del mismo canal? No debe haber condición de carrera que dañe el estado de la orden; el
  resultado final debe ser consistente con el último estado real de la vinculación.
- ¿Qué pasa si la orden pendiente no tiene ningún ítem que referencie la publicación/variante
  recién vinculada? No debe reevaluarse (para no generar trabajo innecesario sobre órdenes no
  relacionadas).

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: Al crear, editar o eliminar una vinculación de publicación de MercadoLibre a un
  producto del CRM, el sistema DEBE reevaluar automáticamente, en el momento, todas las órdenes de
  MercadoLibre en estado "requiere atención" o "lista para convertir" (no convertidas todavía)
  cuyos ítems referencien esa publicación.
- **FR-002**: Al crear, editar o eliminar una vinculación de variante de TiendaNube a un producto
  del CRM, el sistema DEBE reevaluar automáticamente, en el momento, todas las órdenes de
  TiendaNube en estado "requiere atención" o "lista para convertir" (no convertidas todavía)
  cuyos ítems referencien esa variante.
- **FR-003**: La reevaluación disparada por vinculación DEBE usar exactamente la misma lógica de
  negocio que ya determina si una orden está lista para convertir o qué le falta (reutilizar el
  evaluador existente de cada canal, no duplicar ni reinventar reglas).
- **FR-004**: Si tras la reevaluación por vinculación una orden queda en estado "lista para
  convertir" y el canal correspondiente tiene activada la creación automática de ventas, el
  sistema DEBE disparar esa creación automática igual que lo hace hoy durante la sincronización
  normal.
- **FR-005**: La reevaluación por vinculación NO DEBE modificar órdenes que ya fueron convertidas
  en venta ni órdenes canceladas.
- **FR-006**: Al abrir la vista de órdenes pendientes de convertir de MercadoLibre, el sistema
  DEBE reevaluar, antes de mostrar el listado, todas las órdenes de ese canal en estado "requiere
  atención" contra el estado actual de sus vinculaciones.
- **FR-007**: Al abrir la vista de órdenes pendientes de convertir de TiendaNube, el sistema DEBE
  reevaluar, antes de mostrar el listado, todas las órdenes de ese canal en estado "requiere
  atención" contra el estado actual de sus vinculaciones.
- **FR-008**: Ambos mecanismos (evento-driven y on-view) DEBEN comportarse de forma simétrica en
  MercadoLibre y en TiendaNube: mismo disparador, mismo alcance de órdenes afectadas, mismo
  reuso del evaluador de cada canal.
- **FR-009**: El sistema DEBE reevaluar únicamente las órdenes efectivamente relacionadas con la
  vinculación creada/editada/eliminada (las que tienen un ítem con esa publicación/variante), no
  el total de órdenes pendientes del canal, en el mecanismo evento-driven.
- **FR-010**: Si al desvincular o cambiar una vinculación una orden que dependía de ella deja de
  cumplir las condiciones para estar lista, el sistema DEBE volver a marcarla con el motivo de
  atención que corresponda.
- **FR-011**: Cuando una orden pasa de "requiere atención" a "lista para convertir" (o a
  convertida) como resultado de una reevaluación, el sistema DEBE limpiar el motivo y el detalle
  de atención previos, de forma que no quede un motivo obsoleto visible en una orden que ya no
  lo tiene.
- **FR-012**: El borrado masivo de todas las vinculaciones de un canal en una sola operación
  (distinto de eliminar una vinculación puntual) queda explícitamente fuera del alcance de esta
  feature: ni el mecanismo evento-driven ni el on-view (que sólo revisa órdenes "requiere
  atención", no las que estaban "lista") garantizan corregir las órdenes afectadas por ese
  borrado masivo. Es una limitación conocida y aceptada, no un requisito pendiente.

### Key Entities

- **Orden (MercadoLibre / TiendaNube)**: registro de una orden sincronizada desde el canal, con
  un estado de conversión (pendiente, lista, requiere atención, cancelada, convertida) y,
  cuando aplica, un motivo de atención. Contiene uno o más ítems.
- **Ítem de orden**: línea de una orden que referencia una publicación (MercadoLibre) o variante
  (TiendaNube) del canal, y opcionalmente el producto del CRM con el que quedó asociada al
  convertirse.
- **Vinculación (publicación↔producto en MercadoLibre / variante↔producto en TiendaNube)**:
  asociación entre un ítem vendible del canal y un producto del CRM, de la que depende si una
  orden puede convertirse en venta.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Después de vincular una publicación o variante, el 100% de las órdenes pendientes
  que dependían de esa vinculación reflejan su estado correcto sin que el usuario tenga que
  recargar, sincronizar de nuevo, ni abrir cada orden manualmente.
- **SC-002**: El listado de órdenes pendientes de convertir (MercadoLibre y TiendaNube) nunca
  muestra una orden con motivo "publicación/variante sin vincular" cuando esa publicación o
  variante ya está vinculada en el momento de ver la pantalla.
- **SC-003**: El tiempo de carga de la vista de órdenes pendientes no aumenta más de 1 segundo
  respecto del comportamiento actual, con el volumen de órdenes visto en producción al momento de
  esta spec (hasta ~400 órdenes en estado "requiere atención" por canal). Si la vista no tiene
  ninguna orden en ese estado, la reevaluación on-view no agrega ningún trabajo adicional.
- **SC-004**: Cero intervención manual (comandos, consultas a base de datos) requerida para
  destrabar órdenes que quedaron con estado desactualizado por vinculación tardía.

## Assumptions

- La lógica de negocio que decide si una orden está "lista para convertir" o qué le falta ya
  existe y es correcta (evaluadores actuales de MercadoLibre y TiendaNube); esta feature sólo
  agrega cuándo se dispara esa evaluación, no cambia las reglas de qué hace lista a una orden.
- "Vinculación de publicación/variante" se refiere a la relación 1:1 (o multi-vínculo, según el
  canal) entre un ítem vendible del canal y un producto del CRM, gestionada desde la pantalla de
  vinculación de cada canal.
- El volumen de órdenes "requiere atención" por canal es de cientos, no de decenas de miles; la
  reevaluación on-view (User Story 2) puede recorrer todas las órdenes pendientes del canal en
  cada carga de la vista sin requerir procesamiento asíncrono ni paginación especial.
- La creación automática de ventas al quedar una orden "lista" ya existe como funcionalidad
  configurable por canal; esta feature reutiliza ese comportamiento, no lo introduce.
- Eliminar o cambiar una vinculación existente es un caso de uso ya soportado por las pantallas
  de vinculación actuales; esta feature extiende la reevaluación automática a ese caso (FR-010)
  pero no modifica cómo se elimina o edita una vinculación en sí.
- La reevaluación automática que puede terminar creando una venta sin intervención humana directa
  ya cuenta con el mismo nivel de trazabilidad que hoy tiene la creación automática durante la
  sincronización normal (motivo/motivo_detalle persistidos en la orden, y el registro contable de
  la venta creada); no se agrega logging adicional específico para distinguir "se creó por
  sincronización" vs. "se creó por reevaluación reactiva", porque el resultado observable para el
  usuario (la orden convertida) es el mismo y no se detectó una necesidad de auditoría diferenciada
  para este caso.
