# Feature Specification: Saldo a favor aplicable a nuevas Ventas y Compras

**Feature Branch**: `072-saldo-favor-credito-nc`

**Created**: 2026-08-21

**Status**: Draft

**Input**: User description: "Saldo a favor del cliente/proveedor aplicable a nuevas Ventas y Compras (crédito de Notas de Crédito con remanente)."

## Contexto y decisión de producto

Cuando un cliente devuelve mercadería y se lleva otra más barata, le queda plata a favor. Hoy el
sistema no tiene forma de imputar ese crédito a la venta nueva, y el procedimiento manual que se usa
en el local **borra la evidencia de que el cliente pagó**.

Caso real verificado en producción (cliente FLORENCIA 1159751732, ventas 24582 y 24608, 20/08/2026),
reconstruido con los timestamps de la base:

| Hora | Acción | Efecto |
|------|--------|--------|
| 19:57:45 | Cobro de $30.771,29 en la venta 24582 (Visa a Cobrar) | El cliente pagó |
| 19:58:58 | Nota de Crédito por $30.771,29 sobre esa venta | Devolución registrada |
| 20:03:38 | **Se elimina** el cobro de $30.771,29 | Se borra la evidencia del pago |
| 20:03:48 | Cobro de $27.306 en la venta nueva 24608 (Visa a Cobrar) | Venta nueva saldada |

Resultado: las dos ventas quedan en cero y **los $3.465,29 que el cliente pagó de más desaparecen de
todo registro**. El cálculo del sistema es correcto; lo que falla es que el único camino disponible
obliga a destruir el dato.

**Esta feature NO es una réplica de Contagram.** Se relevó Contagram real el 20/08/2026 (ver
`docs/informe_contagram_notas_credito_mayores/`) y se confirmó que **no ofrece ninguna forma de
aplicar un crédito a un comprobante puntual**: el "Medio de Cobro" sólo lista cuentas de
caja/banco/tarjetas y el campo "Documento que Ajusta" nunca se puebla. El dueño del negocio revisó
el hallazgo el 21/08/2026, confirmó que Contagram flaquea en este aspecto y dio vía libre explícita
para mejorarlo. Es una **divergencia deliberada** respecto de Contagram y debe quedar documentada
como tal en `docs/documentacion_principal_crm.md` (principio rector de fidelidad estructural: la
divergencia se documenta, no se oculta).

## Clarifications

### Session 2026-08-21

- Q: ¿El crédito disponible se mide por el monto de la Nota de Crédito o por el saldo a favor que esa
  nota efectivamente generó? → A: **Por el saldo a favor efectivo.** Una NC sobre un comprobante
  impago sólo cancela deuda y NO genera crédito; tomar el monto de la nota crearía crédito de la
  nada. (Caso Florencia tal como quedó en la base hoy: la venta 24582 tiene una NC de $30.771,29 y
  cero cobros, así que su crédito disponible es $0, no $30.771,29.)
- Q: ¿Aplicar un crédito reduce el saldo a favor del comprobante de origen? → A: **Sí. La aplicación
  es una transferencia de saldo entre dos comprobantes del mismo cliente/proveedor.** Sin esto hay
  doble conteo: el saldo a favor seguiría entero en el comprobante de origen y además saldaría el
  comprobante destino, dejando al cliente con $30.771,29 a favor en vez de $3.465,29.
- Q: ¿Qué fecha lleva la aplicación de crédito? → A: La que elige el operador, con hoy por defecto,
  igual que una cobranza.
- Q: ¿Se puede aplicar crédito a un comprobante emitido antes que la Nota de Crédito? → A: Sí:
  cualquier comprobante del mismo cliente/proveedor con saldo pendiente, sin importar su fecha.
- Q: ¿Qué permiso hace falta para aplicar crédito? → A: El mismo que ya se exige para cargar una
  cobranza (Ventas) o un pago (Compras); no se crea un permiso nuevo.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Aplicar el crédito a la venta nueva (Priority: P1)

El cliente devuelve un producto de $30.771,29 que ya había pagado. Se le emite la Nota de Crédito.
Acto seguido elige otro producto de $27.306. El vendedor carga la venta nueva y, en "Agregar
Cobranza", elige el medio de cobro **"Saldo a favor"**, que muestra el crédito disponible del
cliente. Aplica $27.306. La venta queda cobrada y **quedan $3.465,29 de crédito vivo** para la
próxima compra, visibles como saldo a favor del cliente.

**Why this priority**: es el caso que motivó la feature y el que hoy hace desaparecer plata. Sin
esto, nada de lo demás tiene sentido.

**Independent Test**: emitir una NC mayor al importe de una venta posterior del mismo cliente,
aplicar el crédito desde la cobranza, y verificar que la venta queda saldada y el remanente sigue
disponible. Entrega valor completo por sí solo.

**Acceptance Scenarios**:

1. **Given** una venta pagada de $30.771,29 anulada por una NC del mismo importe (saldo a favor
   $30.771,29) y una venta nueva de $27.306,
   **When** el vendedor agrega una cobranza con medio "Saldo a favor" por $27.306,
   **Then** la venta nueva queda con A Cobrar $0, la venta de origen pasa de −$30.771,29 a
   −$3.465,29, y el saldo de cuenta corriente del cliente sigue siendo −$3.465,29 antes y después.
2. **Given** el mismo cliente con $3.465,29 de crédito remanente,
   **When** se le carga otra venta de $10.000 y se aplica el saldo a favor,
   **Then** se aplican como máximo $3.465,29, el crédito disponible queda en $0 y la venta muestra
   $6.534,71 pendientes.
3. **Given** una venta de $27.306 y un crédito disponible de $30.771,29,
   **When** el vendedor intenta aplicar más de $27.306,
   **Then** el sistema lo rechaza indicando que no puede superar el saldo a cobrar del comprobante.
4. **Given** un cliente sin ningún crédito disponible,
   **When** se abre "Agregar Cobranza",
   **Then** el medio "Saldo a favor" no se ofrece como opción seleccionable.

---

### User Story 2 - Ver el saldo del cliente al cargar la venta (Priority: P1)

Al crear una Venta, el selector de cliente muestra el saldo de cuenta corriente junto al nombre
(ej.: `FLORENCIA 1159751732  $-3.465,29`), de modo que el vendedor **se entera de que hay crédito
en el momento en que lo necesita**, sin ir a buscarlo a otra pantalla.

**Why this priority**: es la señal que dispara todo el flujo. Sin esto, el crédito existe pero nadie
se acuerda de usarlo, que es exactamente lo que viene pasando. Contagram sí muestra este dato
(captura del 21/08/2026: `FLORENCIA 1159751732  $18.960,98`), así que acá además se corrige una
diferencia real contra Contagram.

**Independent Test**: abrir Nueva Venta, buscar un cliente con saldo distinto de cero y verificar
que el importe aparece junto al nombre con el signo correcto. Es independiente de la User Story 1:
aporta valor aunque el crédito todavía se aplique a mano.

**Acceptance Scenarios**:

1. **Given** un cliente con saldo a favor de $3.465,29,
   **When** el vendedor lo busca en el selector de Nueva Venta,
   **Then** ve su nombre acompañado del saldo, distinguible como saldo a favor.
2. **Given** un cliente que debe $18.960,98,
   **When** el vendedor lo busca en el selector,
   **Then** ve el saldo presentado como deuda, no como crédito.
3. **Given** un cliente con saldo cero,
   **When** aparece en el selector,
   **Then** se muestra sólo el nombre, sin importe.

---

### User Story 3 - Saber qué Nota de Crédito pagó qué comprobante (Priority: P2)

Quien revisa la cuenta más tarde —el dueño, el contador— puede ver de qué Nota de Crédito salió cada
crédito aplicado y cuánto queda sin consumir de cada una.

**Why this priority**: sin trazabilidad, el crédito aplicado es indistinguible de un cobro real y se
pierde la capacidad de auditar. No bloquea la operación diaria, pero es lo que evita el próximo
descuadre silencioso.

**Independent Test**: aplicar un crédito parcial y verificar que desde la venta cobrada se llega a
la NC de origen, y desde la NC se ve cuánto se consumió y dónde.

**Acceptance Scenarios**:

1. **Given** una NC de $30.771,29 con $27.306 aplicados a la venta 24608,
   **When** se consulta esa NC,
   **Then** muestra $27.306 consumidos, $3.465,29 disponibles y la venta donde se aplicó.
2. **Given** una venta saldada con crédito,
   **When** se mira su detalle de cobranzas,
   **Then** la línea indica que el medio fue saldo a favor e identifica la NC de origen.

---

### User Story 4 - Aplicar crédito de proveedor a una Compra (Priority: P3)

Cuando se devuelve mercadería a un proveedor y éste emite una Nota de Crédito, ese saldo a favor
nuestro se puede aplicar a una Compra posterior del mismo proveedor, con el mismo mecanismo.

**Why this priority**: el circuito es simétrico y el volumen es mucho menor que en Ventas (5 compras
saldadas con NC contra 457 ventas), pero dejarlo afuera obligaría a rediseñar lo mismo dos veces.

**Independent Test**: emitir una NC de compra mayor al importe de una compra posterior del mismo
proveedor y aplicar el crédito desde el registro de pago.

**Acceptance Scenarios**:

1. **Given** un proveedor con una NC de compra sin consumir,
   **When** se registra el pago de una compra nueva usando "Saldo a favor",
   **Then** la compra queda con A Pagar $0 y el remanente queda disponible para la próxima compra.

---

### Edge Cases

- **Anulación de la NC de origen**: si se elimina una Nota de Crédito que ya tiene crédito aplicado,
  el sistema debe impedirlo o exigir revertir primero las aplicaciones. Nunca puede quedar un
  comprobante saldado por un crédito cuyo origen ya no existe.
- **Anulación de la aplicación**: al eliminar una cobranza hecha con saldo a favor, el crédito
  vuelve a estar disponible en la NC de origen por el mismo importe.
- **Varias NC disponibles**: un cliente con dos o más NC con remanente puede cubrir un comprobante
  con la suma de todas ellas; el consumo se imputa de la más antigua a la más nueva.
- **Aplicación parcial en varias veces**: una misma NC puede aplicarse a varios comprobantes
  distintos hasta agotar su remanente.
- **Cliente equivocado**: sólo se ofrece el crédito del cliente del comprobante; el crédito de un
  cliente no puede aplicarse a la venta de otro. Ídem proveedores.
- **Crédito mayor al comprobante**: si el crédito disponible supera el saldo a cobrar, se aplica
  como máximo ese saldo; el resto permanece disponible.
- **Comprobante ya saldado**: si el comprobante no tiene saldo pendiente, no se ofrece aplicar
  crédito.
- **Concurrencia**: dos operadores aplicando el mismo crédito al mismo tiempo no pueden consumirlo
  dos veces (el remanente no puede quedar negativo).
- **NC de compra sobre crédito de cliente**: los créditos de clientes y de proveedores son
  universos separados y no se mezclan.

## Requirements *(mandatory)*

### Functional Requirements

**Crédito disponible**

- **FR-001**: El sistema MUST calcular el **crédito disponible** de un comprobante con Nota de
  Crédito como su saldo a favor efectivo —lo que el comprobante quedó debiéndole al cliente/proveedor
  una vez descontado lo ya aplicado a otros comprobantes— y nunca como el monto nominal de la nota.
  Un comprobante impago cuya NC sólo canceló deuda tiene crédito disponible cero.
- **FR-002**: El sistema MUST considerar disponible únicamente el crédito originado en Notas de
  Crédito (`tipo = credito`) vigentes (no anuladas), del cliente del comprobante en Ventas y del
  proveedor del comprobante en Compras.
- **FR-003**: El sistema MUST exponer el **crédito total disponible** de un cliente/proveedor como la
  suma del disponible de todos sus comprobantes con saldo a favor de este origen.
- **FR-003a**: Aplicar crédito MUST comportarse como una **transferencia de saldo entre dos
  comprobantes del mismo cliente/proveedor**: reduce en el mismo importe el saldo a favor del
  comprobante de origen y el saldo pendiente del comprobante destino. El saldo de cuenta corriente
  del cliente/proveedor MUST quedar idéntico antes y después de la aplicación: no se crea ni se
  destruye deuda, sólo se reubica.

**Aplicación**

- **FR-004**: Los usuarios MUST poder aplicar crédito a una Venta desde el modal "Agregar Cobranza"
  existente, eligiendo "Saldo a favor" entre los medios de cobro, que MUST mostrar el crédito
  disponible del cliente.
- **FR-005**: Los usuarios MUST poder aplicar crédito a una Compra desde el registro de pago
  existente, con el mismo mecanismo.
- **FR-006**: El sistema MUST ofrecer el medio "Saldo a favor" **sólo** cuando el cliente/proveedor
  tenga crédito disponible mayor a cero y el comprobante tenga saldo pendiente.
- **FR-007**: El sistema MUST limitar el importe aplicado al menor entre el crédito disponible y el
  saldo pendiente del comprobante, y MUST rechazar con un mensaje claro cualquier intento de
  superarlo.
- **FR-008**: El sistema MUST imputar el consumo de crédito del comprobante con saldo a favor más
  antiguo al más nuevo cuando haya varios con remanente.
- **FR-009**: El sistema MUST registrar cada aplicación como un movimiento propio, distinguible de
  un cobro/pago con dinero, que identifique el comprobante de origen (y su Nota de Crédito) y el
  comprobante destino.
- **FR-009a**: El sistema MUST impedir aplicar crédito de un comprobante sobre sí mismo.
- **FR-010**: El sistema MUST reflejar el crédito aplicado en el saldo del comprobante (A Cobrar /
  A Pagar) igual que un cobro/pago.
- **FR-011**: Los usuarios MUST poder revertir una aplicación de crédito; al hacerlo el importe MUST
  volver a quedar disponible en la Nota de Crédito de origen.
- **FR-012**: El sistema MUST impedir anular una Nota de Crédito que tenga crédito aplicado, hasta
  que se reviertan sus aplicaciones.
- **FR-013**: El sistema MUST impedir que el crédito disponible de una Nota de Crédito quede
  negativo, incluso ante dos aplicaciones simultáneas.

**Visibilidad**

- **FR-014**: El selector de cliente de Nueva Venta MUST mostrar el saldo de cuenta corriente del
  cliente junto a su nombre, distinguiendo visualmente deuda de saldo a favor, y omitiéndolo cuando
  el saldo es cero. Ídem el selector de proveedor en Nueva Compra.
- **FR-015**: El detalle de un comprobante saldado con crédito MUST indicar que el medio fue saldo a
  favor e identificar la Nota de Crédito de origen.
- **FR-016**: El detalle de una Nota de Crédito MUST mostrar su monto, cuánto se consumió, cuánto
  queda disponible y en qué comprobantes se aplicó.

**Tesorería (restricción innegociable)**

- **FR-017**: Aplicar crédito MUST NOT generar ningún movimiento de tesorería: no es plata que entra
  ni que sale.
- **FR-018**: Aplicar crédito MUST NOT alterar los saldos de las cuentas de tesorería (cajas,
  bancos, tarjetas), ni los totales de A Cobrar / A Pagar / Disponible, ni el aging de clientes y
  proveedores, más allá del efecto que ya tiene la propia Nota de Crédito sobre el saldo del
  cliente/proveedor.
- **FR-019**: El medio "Saldo a favor" MUST NOT aparecer como una cuenta de tesorería en ninguna
  pantalla de Tesorería, ni sumar a ningún bloque de saldos.

**Compatibilidad**

- **FR-020**: El sistema MUST seguir permitiendo Notas de Crédito por un monto mayor al del
  comprobante que ajustan: ése es el mecanismo por el cual se genera el saldo a favor.
- **FR-021**: Los comprobantes y cobranzas existentes MUST seguir comportándose exactamente igual;
  esta feature agrega un camino nuevo, no modifica el circuito de cobranzas con dinero.
- **FR-022**: Aplicar o anular crédito MUST exigir el mismo permiso que ya se requiere para cargar
  una cobranza (Ventas) o un pago (Compras). No se crea un permiso nuevo.
- **FR-023**: Los informes de Ventas y de Compras MUST reflejar el mismo saldo y el mismo estado de
  cobro/pago que el listado y el detalle. Como sus filtros hoy calculan el saldo sin contemplar
  siquiera las Notas de Crédito, MUST alinearse en este mismo cambio: un comprobante no puede figurar
  como pendiente en el informe y como saldado en el listado.

### Key Entities

- **Nota de Crédito (existente)**: comprobante que acredita a favor del cliente/proveedor. Suma al
  concepto nuevo de **crédito disponible** = monto − aplicado.
- **Aplicación de crédito (nueva)**: vincula una Nota de Crédito de origen con el comprobante
  (Venta o Compra) al que se imputa, por un importe y una fecha. Es el registro que hoy no existe y
  cuya ausencia hace desaparecer la plata.
- **Cliente / Proveedor (existentes)**: dueños del crédito. Su saldo de cuenta corriente es lo que
  se muestra en el selector.
- **Cobranza / Pago (existentes)**: circuito de dinero real, que impacta Tesorería. La aplicación de
  crédito comparte la ubicación en la UI pero **no** el impacto en Tesorería.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Reproduciendo el caso Florencia (devolución de $30.771,29 ya pagada y compra posterior
  de $27.306), el sistema conserva $3.465,29 de saldo a favor visible, contra $0 con el procedimiento
  actual.
- **SC-001a**: El saldo de cuenta corriente del cliente es idéntico antes y después de aplicar un
  crédito: la aplicación reubica saldo entre comprobantes y nunca lo duplica ni lo destruye.
- **SC-002**: El vendedor completa "devolución + venta nueva con crédito aplicado" sin eliminar
  ningún registro previo: cero cobranzas borradas en el flujo.
- **SC-003**: Los totales de Tesorería (A Cobrar, A Pagar, Disponible, aging de clientes, aging de
  proveedores y suma de movimientos) son **idénticos** antes y después de aplicar un crédito.
- **SC-004**: Para cualquier crédito aplicado se puede responder, sin consultar la base de datos, de
  qué Nota de Crédito salió y a qué comprobante fue.
- **SC-005**: El vendedor se entera de que el cliente tiene saldo a favor en la misma pantalla en la
  que carga la venta, sin navegar a otra sección.
- **SC-006**: La suma de crédito aplicado de una Nota de Crédito nunca supera su monto, en ningún
  escenario, incluidas aplicaciones simultáneas.

## Assumptions

- **Divergencia deliberada de Contagram**: relevado el 20/08/2026, Contagram no ofrece esta
  funcionalidad. El dueño del negocio dio vía libre el 21/08/2026 para superarla. Se documenta en
  `docs/documentacion_principal_crm.md`.
- **El crédito nace sólo de comprobantes con Nota de Crédito**, no del neto de la cuenta corriente.
  Un saldo a favor originado en un cobro de más sin NC, o en el saldo inicial del cliente, no se
  ofrece como crédito aplicable (sí sigue reflejándose en la cuenta corriente, como hoy).
- **Requiere que la cobranza original no se haya borrado**: el crédito existe porque el comprobante
  quedó con saldo a favor, y eso supone que el pago del cliente sigue registrado. Hay que instruir al
  local para que deje de eliminar la cobranza vieja — con esta feature ya no hace falta, y borrarla
  es justamente lo que hace desaparecer el crédito.
- **No se topea el monto de las Notas de Crédito**: se mantiene el comportamiento actual, que
  coincide con Contagram y es el que genera los créditos.
- **El crédito no se aplica automáticamente**: siempre es una acción explícita del operador, para no
  consumir crédito cuando el cliente prefiere que se le devuelva la plata.
- **Créditos de clientes y de proveedores son universos separados**, sin compensación cruzada.
- **Sin vencimiento del crédito**: un saldo a favor no caduca por el paso del tiempo.
- **Sin impacto fiscal nuevo**: aplicar un crédito es una imputación interna; no emite comprobantes
  ni interactúa con ARCA. La Nota de Crédito ya resolvió su parte fiscal cuando se emitió.
- **Los datos históricos no se migran**: los créditos ya perdidos por el procedimiento manual (como
  los $3.465,29 de Florencia) no se reconstruyen automáticamente; si el negocio quiere recuperarlos,
  se cargan a mano.
