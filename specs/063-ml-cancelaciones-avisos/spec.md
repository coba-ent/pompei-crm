# Feature Specification: Cancelaciones de Mercado Libre posteriores a la venta, y avisos de sincronización

**Feature Branch**: `063-ml-cancelaciones-avisos`

**Created**: 2026-08-12

**Status**: Draft

**Input**: User description: "Cancelaciones de órdenes de Mercado Libre posteriores a la conversión, y visibilidad de los errores de sincronización."

## Contexto del problema

Dos huecos distintos con la misma raíz: **el CRM detecta el problema pero no se lo dice a nadie**.

**1. Una orden cancelada después de convertida no revierte nada.** El sistema impide convertir en
Venta una orden que ya está cancelada, pero no contempla el camino inverso: si la orden se cancela
*después* de haberse convertido, la sincronización actualiza el estado de la orden y nada más. La
Venta, su Cobro, el movimiento de Tesorería y el de Stock quedan intactos.

Verificado en producción el 12/08/2026:

| Venta | Estado en Mercado Libre | Total | Stock descontado | Cobrada |
|---|---|---:|---:|---|
| 62 | cancelada | $52.473,80 | −1 | sí |
| 65 | reembolso parcial | $176.496,05 | −1 | sí |
| 95 | cancelada | $286.881,58 | −2 | sí |
| 116 | cancelada | $44.200,00 | −1 | sí |
| | | **$560.051,43** | **−5** | |

Son **$560.051,43 facturados y cobrados de operaciones que se cancelaron o reembolsaron**, con su
plata contada en Tesorería y 5 unidades descontadas del inventario que nunca salieron del depósito.

**2. Los errores de sincronización de stock no le avisan a nadie.** Cinco publicaciones fallan
indefinidamente, con 61 reintentos por publicación cada 6 horas —unas 305 llamadas fallidas—, y el
único registro es una columna que nadie mira. Dos de esos errores son bloqueos permanentes de
Mercado Libre, donde reintentar no puede funcionar. El desfase de stock que originó todo esto lo
detectó una persona mirando Mercado Libre, no el sistema.

Consecuencias hoy visibles: una publicación ofrece 9 unidades de un producto con stock negativo, y
otra está pausada por falta de stock teniendo 30 unidades en depósito.

## Clarifications

### Session 2026-08-12

Las cuatro decisiones de fondo —qué hacer al detectar la cancelación, cuándo reponer el stock, cómo
tratar reembolso parcial y mediación, y el alcance— se resolvieron con el usuario **antes** de
redactar la spec. El barrido de ambigüedades encontró cinco puntos adicionales; ninguno bloqueante,
todos resueltos con el default que menos superficie nueva agrega:

- Q: ¿Dónde ve el usuario las Ventas marcadas? → A: **En la pantalla de Órdenes de Mercado Libre que
  ya existe**, que ya tiene el concepto de "requiere atención", más un indicador en la fila de la
  Venta en su propio listado. No se crea una pantalla nueva.
- Q: ¿El aviso notifica activamente (correo, campana) o sólo se ve en pantalla? → A: **Sólo en
  pantalla.** La notificación proactiva de fallas es un módulo pendiente propio ya registrado, y
  mezclarlo acá ampliaría el alcance sin necesidad.
- Q: ¿Una Venta marcada queda bloqueada para editarse o cobrarse mientras el aviso está pendiente?
  → A: **No se bloquea.** Es coherente con la decisión de no tocar nada automáticamente: el aviso
  informa, no restringe.
- Q: ¿Cuántos intentos fallidos antes de marcar "requiere intervención"? → A: **5 intentos
  consecutivos con el mismo error**, para que el criterio sea testeable. Con la frecuencia actual de
  sincronización eso es aproximadamente un cuarto de hora de reintentos antes de frenar.
- Q: ¿A qué depósito vuelve el stock? → A: **Al mismo depósito del que salió** en la venta original,
  renglón por renglón.
- Q: "Anular" ¿significa eliminar la Venta o emitir una nota de crédito? → A: **Ninguna de las dos
  se construye acá: las dos ya existen.** El aviso sólo detecta, informa y conduce a la Venta; la
  persona resuelve con el circuito de notas de crédito o con la eliminación, según el caso. Lo
  recomendable para una factura emitida es la nota de crédito —el comprobante no debe desaparecer—
  pero la eliminación queda disponible como está hoy.
  *(Corrige dos veces el enfoque inicial: primero se planteó anular con reversión propia, después
  emitir la nota de crédito desde el aviso. Ambas inventaban lo que ya estaba hecho.)*

## User Scenarios & Testing *(mandatory)*

### User Story 1 - La cancelación de una orden ya facturada se hace visible (Priority: P1)

Un comprador cancela en Mercado Libre una compra que ya se convirtió en Venta. En la siguiente
sincronización, esa Venta queda **marcada como pendiente de revisión**, indicando que su orden se
canceló. Nadie tiene que descubrirlo mirando Mercado Libre.

**Why this priority**: Es el bug que ya costó $560.051,43 de facturación irreal. Sin esto, cada
cancelación futura vuelve a pasar desapercibida. Marcar y avisar entrega valor por sí solo: aunque
la resolución siga siendo manual, el problema deja de ser invisible.

**Independent Test**: Tomar una Venta creada desde una orden pagada, simular que la orden pasa a
cancelada en el origen, correr la sincronización y verificar que la Venta queda marcada con el
motivo, sin que se haya modificado ningún importe ni el stock.

**Acceptance Scenarios**:

1. **Given** una Venta creada desde una orden de Mercado Libre que estaba pagada, **When** la orden
   pasa a cancelada y corre la sincronización, **Then** la Venta queda marcada como pendiente de
   revisión con el motivo "orden cancelada en Mercado Libre" y la fecha de detección.
2. **Given** esa misma Venta marcada, **When** se consulta su total, su cobro y el stock del
   producto, **Then** ninguno cambió: la marca es sólo un aviso.
3. **Given** una orden cancelada que **nunca** se convirtió en Venta, **When** corre la
   sincronización, **Then** no se genera ningún aviso, porque no hay nada que revisar.
4. **Given** una Venta ya marcada por cancelación, **When** vuelve a correr la sincronización,
   **Then** no se duplica el aviso ni se altera la fecha de detección original.

---

### User Story 2 - Llegar a la venta y resolverla con lo que ya existe (Priority: P1)

Desde el aviso, quien administra llega a la Venta y la resuelve con las herramientas que el sistema
**ya tiene**: emitir una nota de crédito, o eliminarla. El aviso no ejecuta nada por su cuenta y no
agrega un circuito propio; cuando la Venta queda resuelta, el aviso se cierra.

**Why this priority**: Sin esto la marca es una lista de problemas sin salida. Pero la salida ya
está construida —el circuito de notas de crédito con ajuste de stock, y la eliminación de ventas—
así que lo único que falta es **conectarlas al aviso**, no reemplazarlas.

**Independent Test**: Sobre una Venta marcada por cancelación, navegar desde el aviso hasta la Venta,
resolverla con el circuito existente y verificar que el aviso queda cerrado.

**Acceptance Scenarios**:

1. **Given** una Venta marcada por cancelación, **When** se abre el aviso, **Then** se llega a la
   Venta con un clic, con el motivo del aviso a la vista.
2. **Given** esa Venta, **When** se le emite una nota de crédito con el circuito existente, **Then**
   el aviso queda cerrado y no vuelve a aparecer como pendiente.
3. **Given** esa Venta, **When** en cambio se la elimina con la función existente, **Then** el aviso
   también queda cerrado.
4. **Given** una Venta marcada, **When** se descarta el aviso sin tocar la Venta, **Then** la Venta
   sigue vigente con todos sus importes y el aviso desaparece de la lista, registrando quién lo
   descartó y cuándo.
5. **Given** cualquiera de esas tres resoluciones, **When** se consulta el historial, **Then** queda
   registrado quién la hizo, cuándo, y que el origen fue una cancelación de Mercado Libre.

---

### User Story 3 - Reembolso parcial y mediación se distinguen de una cancelación (Priority: P2)

Una orden con reembolso parcial, o con un reclamo en mediación, genera un aviso **propio y
diferenciado**: el desenlace todavía no está definido y puede resolverse a favor del negocio, así
que no corresponde tratarla como una venta caída.

**Why this priority**: Hoy el sistema colapsa cancelación, reembolso parcial y mediación en un
único estado, lo que llevaría a anular ventas que siguen siendo válidas. Es menos urgente que las
cancelaciones firmes porque son menos casos, pero anular de más es peor que no anular.

**Independent Test**: Simular una orden con reembolso parcial y otra en mediación, y verificar que
cada una produce un aviso con su propio motivo, distinto del de cancelación.

**Acceptance Scenarios**:

1. **Given** una Venta cuya orden pasa a reembolso parcial, **When** corre la sincronización,
   **Then** queda marcada con el motivo "reembolso parcial", diferenciado del de cancelación, e
   indicando el importe reembolsado.
2. **Given** una Venta cuya orden entra en mediación, **When** corre la sincronización, **Then**
   queda marcada con el motivo "en mediación" y se aclara que el desenlace está pendiente.
3. **Given** una Venta marcada por mediación, **When** la mediación se resuelve como cancelación,
   **Then** el aviso pasa a motivo "cancelada" sin perder la fecha en que se detectó la mediación.
4. **Given** una Venta marcada por mediación, **When** la mediación se resuelve a favor del negocio
   y la orden vuelve a estar pagada, **Then** el aviso se cierra automáticamente y la Venta queda
   vigente.

---

### User Story 4 - Los errores de sincronización de stock son visibles y no se reintentan para siempre (Priority: P2)

Cuando una publicación no puede actualizarse en Mercado Libre, el problema queda **a la vista** con
su motivo y desde cuándo ocurre. Si el error es permanente —un bloqueo del marketplace—, el sistema
deja de reintentar y lo marca como "requiere intervención" en vez de golpear la API cada tres
minutos indefinidamente.

**Why this priority**: Un desfase de stock invisible hace que se venda lo que no hay. Es P2 y no P1
porque hoy los casos están identificados y acotados, pero sin esto vuelven a pasar desapercibidos.

**Independent Test**: Simular una publicación cuya actualización falla de forma permanente y
verificar que aparece en la lista de problemas, que deja de reintentarse, y que un error transitorio
sí se sigue reintentando.

**Acceptance Scenarios**:

1. **Given** una publicación cuya actualización de stock falla, **When** se consulta el panel de la
   integración, **Then** aparece listada con el motivo del error, la fecha de la primera falla y la
   cantidad de intentos.
2. **Given** una publicación cuyo error es permanente (bloqueada por el marketplace), **When** se
   alcanza el límite de reintentos, **Then** deja de reintentarse y queda marcada como "requiere
   intervención", sin volver a consumir llamadas a la API.
3. **Given** una publicación con un error transitorio (fallo de red o del servicio), **When** corre
   la sincronización siguiente, **Then** se reintenta normalmente.
4. **Given** una publicación marcada como "requiere intervención", **When** una persona resuelve el
   problema en el marketplace y pide reintentar, **Then** vuelve a entrar en el ciclo normal.
5. **Given** cualquier publicación cuyo stock difiere del publicado, **When** se consulta el panel,
   **Then** se ve la diferencia entre lo que el CRM tiene y lo que el marketplace publica.

---

### Edge Cases

- **La orden se cancela mientras la Venta se está cobrando o editando.** El aviso debe registrarse
  igual; nunca se pierde una cancelación por concurrencia.
- **Dos personas resuelven el mismo aviso a la vez.** Sólo la primera resolución debe aplicarse; la
  segunda debe encontrar el aviso ya resuelto y avisarlo, sin anular dos veces ni revertir de más.
- **El stock del producto cambia mientras la publicación está bloqueada** por error permanente. Al
  reactivarla debe enviarse el stock vigente en ese momento, no el que tenía al bloquearse.
- **La Venta ya fue eliminada** antes de que llegara el aviso. No debe generarse un aviso sobre una
  venta que ya no está vigente.
- **La Venta ya tiene una nota de crédito previa** que la compensa total o parcialmente. El sistema
  debe advertirlo y ofrecer sólo el saldo pendiente, para no acreditar dos veces lo mismo.
- **El producto fue dado de baja** desde que se vendió: la nota de crédito debe poder emitirse igual,
  ya que revierte una operación que existió.
- **El stock del producto es negativo** al momento de reponer. La reposición debe sumar igual, sin
  bloquearse por el signo.
- **La orden vuelve a estado pagado** después de haber sido cancelada (revierte la cancelación). El
  aviso pendiente debe cerrarse solo.
- **La Venta está cobrada.** Si se resuelve con nota de crédito, el cobro sigue registrado y el
  cliente queda con saldo a favor: es el reflejo correcto de que el dinero lo devolvió Mercado Libre
  descontándolo de la cuenta, no el CRM.
- **La Venta se resuelve por fuera del aviso**, desde la pantalla de Ventas. El aviso debe cerrarse
  igual, sin exigir que se pase por él.

## Requirements *(mandatory)*

### Functional Requirements

#### Detección de cancelaciones

- **FR-001**: El sistema DEBE detectar, en cada sincronización, toda orden de Mercado Libre que haya
  pasado a cancelada, reembolso parcial o mediación **y que ya tenga una Venta asociada vigente**.
- **FR-002**: El sistema DEBE marcar esas Ventas como pendientes de revisión, registrando el motivo,
  la fecha de detección y el estado que informó el marketplace.
- **FR-003**: El sistema NO DEBE modificar automáticamente ningún importe, cobro, movimiento de
  Tesorería ni stock al detectar la cancelación.
- **FR-004**: El sistema DEBE distinguir al menos tres motivos: **cancelada**, **reembolso parcial**
  y **en mediación**, sin colapsarlos en un único estado. La condición de **mediación** NO surge del
  estado de la orden sino del estado de sus **pagos**, así que la detección DEBE contemplar ambos.
- **FR-004a**: Cuando el marketplace no informe el importe reembolsado, el aviso DEBE mostrarse igual
  indicando que el importe no fue informado, en vez de omitir el aviso o asumir un monto.
- **FR-005**: El sistema DEBE ser idempotente: repetir la sincronización sobre una Venta ya marcada
  no duplica el aviso ni altera su fecha de detección original.
- **FR-006**: El sistema DEBE cerrar automáticamente el aviso si la orden vuelve a un estado
  vigente (por ejemplo, una mediación resuelta a favor del negocio).
- **FR-007**: El sistema NO DEBE generar avisos sobre órdenes canceladas que nunca se convirtieron
  en Venta, ni sobre Ventas ya anuladas.

#### Resolución

- **FR-008**: Los usuarios DEBEN poder ver la lista de Ventas pendientes de revisión, con su motivo,
  importe, fecha de detección y comprador, **desde la pantalla de Órdenes de Mercado Libre ya
  existente**; además, la Venta DEBE mostrar un indicador en su propio listado. No se crea una
  pantalla nueva ni se envían notificaciones fuera del sistema.
- **FR-008a**: Una Venta marcada NO DEBE quedar bloqueada para editarse ni cobrarse: el aviso
  informa, no restringe.
- **FR-009**: Desde el aviso, los usuarios DEBEN poder navegar a la Venta afectada, con el motivo del
  aviso a la vista.
- **FR-009a**: El sistema NO DEBE crear un circuito propio de reversión. La resolución se hace con
  las funciones que ya existen —emitir una nota de crédito, o eliminar la Venta— y **el usuario elige
  cuál corresponde**. La forma recomendada de revertir una factura emitida es la nota de crédito,
  porque el comprobante no debe desaparecer, pero la eliminación sigue disponible como hoy.
- **FR-010**: Los usuarios DEBEN poder **descartar** el aviso dejando la Venta vigente, registrando
  quién lo descartó y cuándo.
- **FR-010a**: El aviso DEBE cerrarse solo cuando la Venta se resuelve por cualquiera de las vías
  existentes (nota de crédito que la compensa, o eliminación), sin pedir un paso extra.
- **FR-011**: El sistema DEBE registrar en auditoría el descarte de un aviso, con usuario, fecha y el
  motivo original. Las notas de crédito y las eliminaciones ya tienen su propia auditoría.
- **FR-012**: Esta feature NO DEBE modificar el circuito fiscal ni el de notas de crédito: sólo
  conduce hacia ellos.

> *No existe FR-013: era el requisito de atomicidad de la reversión, que dejó de aplicar cuando el
> alcance se recortó a detectar y avisar. Se deja el hueco en vez de renumerar, para no romper las
> referencias desde `tasks.md`.*

#### Errores de sincronización

- **FR-014**: El sistema DEBE exponer las publicaciones cuya sincronización de stock está fallando,
  con el motivo, la fecha de la primera falla y la cantidad de intentos acumulados.
- **FR-015**: El sistema DEBE distinguir errores **transitorios** (se reintentan) de **permanentes**
  (dejan de reintentarse y quedan marcados como "requiere intervención"). Una publicación DEBE
  marcarse como "requiere intervención" tras **5 intentos consecutivos fallidos con el mismo
  error**.
- **FR-016**: El sistema DEBE dejar de reintentar una publicación con error permanente, para no
  consumir llamadas a la API indefinidamente.
- **FR-017**: Los usuarios DEBEN poder reactivar manualmente una publicación marcada como "requiere
  intervención", una vez resuelto el problema en el marketplace.
- **FR-018**: El sistema DEBE mostrar, por publicación, la diferencia entre el stock del CRM y el
  publicado en el marketplace.

### Key Entities

- **Aviso de revisión sobre una Venta**: vínculo entre una Venta vigente y un problema detectado en
  su orden de origen. Atributos: motivo (cancelada / reembolso parcial / en mediación), fecha de
  detección, estado (pendiente / resuelto por anulación / descartado), quién y cuándo lo resolvió.
- **Orden de Mercado Libre**: ya existe. Aporta el estado del marketplace que dispara el aviso.
- **Venta**: ya existe. Recibe la marca y, si se confirma la anulación, cambia de estado.
- **Publicación vinculada a un producto**: ya existe y registra el error de sincronización. Se le
  suman la clasificación del error, el conteo de intentos y la marca de "requiere intervención".

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: El 100% de las cancelaciones de órdenes ya facturadas queda visible para quien
  administra dentro del ciclo de sincronización siguiente, sin que nadie deba revisar el
  marketplace.
- **SC-002**: Ninguna venta se revierte ni ningún importe se modifica sin que una persona lo haga
  explícitamente.
- **SC-003**: Resolver un aviso no requiere aprender un circuito nuevo: se usa el mismo que ya se
  usa para cualquier otra nota de crédito o eliminación.
- **SC-003a**: Ningún aviso queda abierto después de que su Venta fue resuelta.
- **SC-004**: Las llamadas fallidas a la API por publicaciones con error permanente bajan de ~305
  cada 6 horas a menos de 10.
- **SC-005**: Un desfase entre el stock del CRM y el publicado es detectable desde el propio sistema,
  sin abrir el marketplace.
- **SC-006**: Un reembolso parcial o una mediación nunca producen la anulación automática de una
  venta que sigue siendo válida.

## Assumptions

- **Las 4 ventas ya afectadas se corrigen aparte, a mano.** Esta feature evita que vuelva a pasar;
  no incluye la limpieza del histórico.
- **Tiendanube queda fuera de alcance.** Tiene el mismo hueco, pero no se verificó con datos reales
  y se resolverá en un spec propio.
- **La reversión usa las funciones que ya existen**: el circuito de notas de crédito (con su ajuste
  de stock) y la eliminación de ventas. Esta feature **no construye ninguna de las dos ni las
  modifica**: sólo detecta el problema y conduce hacia ellas.
- **El cobro no se revierte.** La nota de crédito compensa la factura; el cobro queda y el cliente
  queda con saldo a favor. Registrar la devolución del dinero, que Mercado Libre descuenta de la
  cuenta, es parte de la conciliación de esa cuenta y queda fuera de alcance.
- **La autorización de la nota de crédito ante ARCA sigue el circuito fiscal existente**, sin
  cambios.
- **"Requiere intervención" se alcanza a los 5 intentos fallidos consecutivos** con el mismo error
  (ver Clarifications).
- **La notificación proactiva queda fuera de alcance.** Los avisos se ven en pantalla; el módulo de
  notificaciones de fallas está registrado como pendiente propio.
- **La detección se apoya en la sincronización periódica ya existente**, incluida su pasada
  dedicada a órdenes canceladas. No se asume disponibilidad de notificaciones en tiempo real.
- **El volumen es bajo**: 12 órdenes canceladas sobre 126 en el histórico. La solución no necesita
  optimizarse para alto volumen.
