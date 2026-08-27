# Feature Specification: Orden de cuentas de tesorería por drag & drop

**Feature Branch**: `085-orden-cuentas-tesoreria`

**Created**: 2026-08-27

**Status**: Draft

**Input**: User description: "En la tesorería, la vista de tesorería tiene todos los totales de a cobrar, a pagar y disponible, y tenemos una ruedita de configuración para crear y editar los nombres de las cuentas. Me gustaría que en esa configuración también se pudiese editar con drag and drop el orden visible en esas cards de tesorería."

## Contexto

La pantalla **Tesorería → Saldos** muestra los saldos agrupados en tres bloques: **A Cobrar**,
**A Pagar** y **Disponible** (este último subdividido en **Cajas** y **Bancos**). Dentro de cada
bloque, las cuentas se listan según un orden de presentación que **ya existe como dato** en el
sistema (campo de orden de la cuenta, con las cuentas sin orden asignado al final y desempate
alfabético por nombre).

Ese orden hoy **no es editable desde la aplicación**: sólo puede modificarse manipulando la base
de datos directamente. El modal de configuración de cuentas —accesible desde el ícono de rueda de
la pantalla de Saldos— permite crear cuentas, editar su nombre y demás datos, y alternar su
visibilidad, pero no permite reordenarlas.

Esta feature agrega esa capacidad: reordenar las cuentas arrastrándolas dentro del modal de
configuración, y que ese orden se refleje inmediatamente en las cards de saldos.

## Clarifications

### Session 2026-08-27

- Q: ¿Con qué criterio se rechaza un reordenamiento cuando el bloque cambió en paralelo desde otra sesión? → A: Rechazo por comparación de conjunto — se compara el conjunto de cuentas enviado contra el conjunto real del bloque en ese momento; si difiere (alta, baja o cuenta ajena), se rechaza el guardado completo y se refresca el listado. No se introduce versionado ni marcas de tiempo.
- Q: ¿El orden guardado alcanza también a los selectores de cuenta, o sólo a las cards de saldos? → A: Alcanza a ambos — las cards de saldos y todos los selectores donde se elige una cuenta de tesorería (transferencias, cobros, pagos, gastos) comparten el mismo orden.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Reordenar cuentas dentro de un bloque (Priority: P1)

El responsable de tesorería abre la configuración de cuentas desde la rueda de la pantalla de
Saldos, ve las cuentas agrupadas por bloque, y arrastra una cuenta hacia arriba o hacia abajo
dentro de su bloque para colocarla en la posición donde quiere verla. Al soltarla, el nuevo orden
queda guardado y se le confirma.

**Why this priority**: Es la totalidad del valor pedido. Sin esto la feature no existe; con esto
sola, ya es utilizable de punta a punta.

**Independent Test**: Abrir la configuración, arrastrar la última cuenta de un bloque a la primera
posición, cerrar el modal, recargar la pantalla y verificar que la cuenta quedó primera en ese
bloque.

**Acceptance Scenarios**:

1. **Given** el modal de configuración abierto con un bloque de 4 cuentas, **When** el usuario
   arrastra la cuarta cuenta y la suelta en la primera posición, **Then** las cuatro cuentas se
   muestran en el nuevo orden y se confirma el guardado con una notificación de éxito.
2. **Given** un reordenamiento ya guardado, **When** el usuario cierra el modal, recarga la
   pantalla de Saldos y vuelve a abrir la configuración, **Then** el orden guardado se conserva
   tanto en el modal como en las cards.
3. **Given** un bloque con una sola cuenta, **When** el usuario intenta arrastrarla, **Then** no
   se produce ningún cambio ni se registra ninguna operación de guardado.
4. **Given** un reordenamiento en curso, **When** el usuario suelta la cuenta exactamente en la
   posición donde ya estaba, **Then** no se guarda nada y no se muestra notificación.

---

### User Story 2 - Ver el orden nuevo reflejado en las cards sin recargar (Priority: P1)

Inmediatamente después de soltar una cuenta en su nueva posición, el usuario ve que las cards de
A Cobrar / A Pagar / Disponible detrás del modal ya reflejan el orden nuevo, sin necesidad de
cerrar el modal ni recargar la página.

**Why this priority**: Es el feedback que hace comprensible el resultado del arrastre — el usuario
está reordenando *para* las cards, así que tiene que ver el efecto ahí. Es requisito de diseño
obligatorio del proyecto (sin recargas de página).

**Independent Test**: Con el modal abierto y las cards visibles detrás, reordenar una cuenta y
verificar que la card correspondiente cambia el orden de sus filas sin que la página se recargue.

**Acceptance Scenarios**:

1. **Given** el modal abierto sobre la pantalla de Saldos, **When** el usuario reordena una cuenta
   del bloque de Cajas, **Then** la lista de Cajas dentro de la card Disponible refleja el nuevo
   orden sin recarga de página, conservando los mismos importes y totales.
2. **Given** un reordenamiento aplicado, **When** el usuario observa los totales de cada bloque,
   **Then** los totales permanecen idénticos: reordenar no altera ningún importe ni saldo.

---

### User Story 3 - El reordenamiento no puede cambiar la naturaleza de una cuenta (Priority: P2)

Al arrastrar, el usuario sólo puede mover una cuenta dentro de su propio bloque. Soltar una cuenta
fuera de su bloque no la mueve a otro bloque ni le cambia el tipo: la cuenta vuelve a su posición
original.

**Why this priority**: Es una salvaguarda. El tipo de cuenta determina en qué card aparece y su
naturaleza contable (efectivo, banco, a cobrar, a pagar); cambiarlo por accidente en un gesto de
arrastre sería un error con consecuencias contables, no una molestia visual.

**Independent Test**: Intentar arrastrar una cuenta del bloque de Cajas al bloque de Bancos y
verificar que la cuenta queda donde estaba y que no se registró ningún cambio.

**Acceptance Scenarios**:

1. **Given** el modal con varios bloques, **When** el usuario arrastra una cuenta y la suelta
   sobre un bloque distinto al suyo, **Then** la cuenta vuelve a su posición original, su tipo no
   cambia y no se guarda nada.
2. **Given** cualquier reordenamiento guardado, **When** se consulta la cuenta afectada, **Then**
   su nombre, tipo, visibilidad, saldo inicial y movimientos permanecen sin cambios: lo único que
   se modifica es su posición.

---

### User Story 4 - Reordenar sin usar el mouse (Priority: P3)

Un usuario que opera con teclado puede enfocar el control de arrastre de una fila y moverla una
posición arriba o abajo dentro de su bloque, con el mismo efecto de guardado que el arrastre con
mouse.

**Why this priority**: Accesibilidad y comodidad. La feature es plenamente utilizable sin esto, por
eso va última.

**Independent Test**: Navegar con teclado hasta el control de arrastre de una cuenta, moverla una
posición hacia arriba y verificar que el orden se guardó igual que con el mouse.

**Acceptance Scenarios**:

1. **Given** el foco puesto en el control de arrastre de una cuenta que no es la primera de su
   bloque, **When** el usuario ejecuta la acción de mover hacia arriba, **Then** la cuenta
   intercambia posición con la anterior y el nuevo orden se guarda.
2. **Given** el foco en el control de arrastre de la primera cuenta de un bloque, **When** el
   usuario ejecuta la acción de mover hacia arriba, **Then** no ocurre nada y no se guarda nada.

---

### Edge Cases

- **Bloque vacío o de una sola cuenta**: no se ofrece reordenamiento efectivo; arrastrar no
  produce cambios ni llamadas de guardado.
- **Cuentas ocultas (no visibles)**: aparecen en el modal junto a las visibles y participan del
  orden. Su posición se guarda igual, aunque no se muestren en las cards mientras estén ocultas;
  al volverlas visibles aparecen en la posición que tenían asignada.
- **Cuentas de sistema**: se reordenan como cualquier otra. Ser cuenta de sistema restringe borrado
  y edición de ciertos datos, no la posición de presentación.
- **Cuentas sin orden previo asignado**: la primera vez que se reordena un bloque, todas las
  cuentas de ese bloque quedan con una posición explícita y consecutiva, eliminando el
  comportamiento de "sin orden va al final" para ese bloque.
- **Fallo al guardar** (conexión caída, error del servidor): se informa el error con una
  notificación y la lista vuelve visualmente al orden previo, para que el usuario no crea que
  guardó algo que no se guardó.
- **Reordenamientos rápidos y sucesivos**: si el usuario hace varios arrastres seguidos, el orden
  que queda persistido es el del último arrastre; no debe quedar un orden intermedio por llegada
  desordenada de respuestas.
- **Cambio hecho en paralelo desde otra sesión**: si otra sesión creó o borró una cuenta del mismo
  bloque entre la apertura del modal y el arrastre, el conjunto enviado deja de coincidir con el
  conjunto real del bloque; el guardado se rechaza entero con un mensaje claro y el listado se
  refresca, en lugar de persistir un orden calculado sobre datos viejos. El usuario vuelve a
  arrastrar sobre el listado actualizado.
- **Cuenta borrada mientras el modal estaba abierto**: cae en el mismo rechazo por conjunto — la
  cuenta enviada ya no existe en el bloque, se rechaza y el listado se refresca.
- **Identificador repetido en el envío**: se rechaza igual que cualquier otra discrepancia de
  conjunto; nunca se aplica un orden parcial.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: El modal de configuración de cuentas de tesorería MUST mostrar, en cada fila de
  cuenta, un control de arrastre visualmente identificable como tal, ubicado al inicio de la fila.
- **FR-002**: Los usuarios MUST poder cambiar la posición de una cuenta dentro de su bloque
  arrastrándola y soltándola en otra posición del mismo bloque.
- **FR-003**: El sistema MUST restringir el arrastre al bloque de origen de la cuenta: soltar
  fuera de ese bloque NO debe modificar ni la posición ni el tipo de la cuenta.
- **FR-004**: El sistema MUST persistir el nuevo orden automáticamente al soltar la cuenta, sin
  requerir una acción adicional de confirmación o guardado por parte del usuario.
- **FR-005**: El sistema MUST confirmar el guardado exitoso mediante una notificación no intrusiva,
  y NO debe mostrarla cuando el arrastre no produjo un cambio real de posición.
- **FR-006**: Al persistir, el sistema MUST asignar posiciones consecutivas y sin huecos a todas
  las cuentas del bloque afectado, de forma que el orden resultante sea estable y reproducible.
- **FR-007**: El sistema MUST aplicar el guardado de forma atómica: o queda persistido el orden
  completo del bloque, o no se modifica ninguna cuenta.
- **FR-008**: El sistema MUST validar cada reordenamiento comparando el conjunto de cuentas recibido
  contra el conjunto real de cuentas del bloque declarado en ese momento, y MUST rechazar el
  guardado completo si difieren en cualquier sentido: una cuenta que no pertenece al bloque, una
  cuenta faltante (borrada o movida en paralelo), una cuenta agregada en paralelo, o un identificador
  repetido. La validación NO usa números de versión ni marcas de tiempo: el conjunto de cuentas del
  bloque es en sí mismo el control de concurrencia.
- **FR-009**: Ante un rechazo o un fallo de guardado, el sistema MUST informar el motivo con una
  notificación de error y restaurar en pantalla el orden previo al arrastre.
- **FR-010**: Tras un guardado exitoso, las cards de saldos de la pantalla de fondo MUST reflejar
  el nuevo orden sin que la página se recargue.
- **FR-011**: El reordenamiento MUST NOT alterar ningún dato de la cuenta más allá de su posición:
  nombre, tipo, visibilidad, condición de cuenta de sistema, saldo inicial, movimientos y saldos
  permanecen intactos.
- **FR-012**: El orden guardado MUST aplicarse de forma consistente en todos los lugares donde se
  listan cuentas de tesorería, no sólo en el modal de configuración: las cards de saldos (A Cobrar,
  A Pagar, Cajas, Bancos) y todos los selectores donde el usuario elige una cuenta de tesorería
  (movimiento entre cuentas, cobros, pagos, gastos) MUST presentar las cuentas en el mismo orden.
- **FR-013**: El sistema MUST permitir mover una cuenta una posición hacia arriba o hacia abajo
  dentro de su bloque mediante teclado, con el mismo efecto de persistencia que el arrastre.
- **FR-014**: El sistema MUST NOT ofrecer reordenamiento de los bloques entre sí: el orden de los
  bloques A Cobrar, A Pagar, Cajas y Bancos permanece fijo.
- **FR-015**: Ante arrastres sucesivos rápidos sobre el mismo bloque, el sistema MUST garantizar
  que el orden finalmente persistido corresponda al último arrastre realizado.

### Key Entities

- **Cuenta de tesorería**: representa una caja, banco, cuenta a cobrar o cuenta a pagar. Ya posee
  un atributo de **posición de presentación** dentro de su tipo, hasta ahora sólo modificable
  fuera de la aplicación. Esta feature no agrega atributos nuevos: le da una interfaz de edición
  al que ya existe.
- **Bloque (tipo de cuenta)**: agrupación de presentación (A Cobrar, A Pagar, Cajas, Bancos) que
  define en qué card aparece la cuenta y delimita el alcance del reordenamiento. No es editable
  por arrastre.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Un usuario puede reordenar una cuenta dentro de su bloque en menos de 10 segundos
  desde que abre la configuración, sin instrucciones previas y sin salir del modal.
- **SC-002**: El orden elegido por el usuario se conserva en el 100% de los casos tras cerrar el
  modal, recargar la pantalla y volver a entrar.
- **SC-003**: El reordenamiento no modifica ningún importe: los totales de A Cobrar, A Pagar,
  Cajas, Bancos y Disponible son idénticos antes y después de la operación en el 100% de los casos.
- **SC-004**: Ningún reordenamiento cambia el tipo de una cuenta: 0 casos de cuentas que cambian de
  bloque como consecuencia de un arrastre.
- **SC-005**: El usuario ve el orden nuevo reflejado en las cards y en el modal sin recargar la
  página, en menos de 2 segundos desde que suelta la cuenta.
- **SC-006**: Cuando el guardado falla, el usuario recibe un mensaje de error y ve el orden previo
  restaurado en el 100% de los casos: nunca queda en pantalla un orden que no se guardó.
- **SC-007**: La necesidad de modificar el orden de las cuentas manipulando la base de datos se
  elimina por completo (0 intervenciones manuales requeridas).
- **SC-008**: Tras reordenar un bloque, las cuentas de ese bloque aparecen en el mismo orden en las
  cards de saldos y en los selectores de elección de cuenta: 0 pantallas con un orden divergente.

## Assumptions

- El atributo de posición de presentación ya existente en la cuenta de tesorería es el que se
  edita; no se introduce un mecanismo de orden paralelo.
- El desempate cuando dos cuentas comparten posición (situación heredada previa a esta feature)
  sigue siendo alfabético por nombre, tal como se comporta hoy.
- Las cuentas ocultas se listan en el modal junto a las visibles y participan del orden guardado;
  el modal ya las muestra hoy con su control de visibilidad.
- El volumen de cuentas por bloque es pequeño (decenas como máximo), por lo que el listado completo
  del bloque se envía en cada guardado sin problema de rendimiento.
- Todos los usuarios con acceso a la pantalla de Tesorería y a su configuración pueden reordenar:
  no se introduce un permiso nuevo específico para esta acción.
- El orden es una preferencia única del negocio (instalación single-tenant), no una preferencia por
  usuario.
- La pantalla se opera desde escritorio con mouse; el soporte táctil es deseable pero no es
  criterio de aceptación de esta feature.
