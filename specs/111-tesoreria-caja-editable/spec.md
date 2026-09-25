# Feature Specification: Caja editable en Editar Movimiento + modales de medio de pago

**Feature Branch**: `111-tesoreria-caja-editable`

**Created**: 2026-09-25

**Status**: Draft

**Input**: Tres pedidos del cliente del 25/09/2026: (1) en Editar Movimiento de Tesorería no se puede
elegir la caja; (2) en los modales de cobranza/pago las cajas salen desordenadas; (3) esos botones
tienen que ser rellenos, no outline.

## Contexto y problema

### 1. No se puede cambiar la caja de un movimiento

El modal "Editar Movimiento" de Tesorería sólo tiene **Fecha, Monto y Observación**. No es que el
campo esté deshabilitado: **no existe**. Si un movimiento quedó cargado en la caja equivocada, hoy
la única salida es borrarlo y rehacerlo, lo que rompe la trazabilidad del asiento.

Sólo son editables los movimientos **nativos** de Tesorería (`saldo_inicial` y
`movimiento_entre_cuentas`); los que vienen de otro módulo (cobros, pagos, gastos) se editan desde
su documento de origen y no están en alcance.

**El caso de la transferencia.** Un "Movimiento entre Cuentas" real genera **dos asientos**: la
salida en una caja y la entrada en la otra, unidos por un identificador común. Medido contra
producción: de 10.602 movimientos de ese tipo, sólo **66 tienen contraparte**; los otros 10.536 son
asientos sueltos heredados de la importación de Contagram.

Cuando el movimiento tiene contraparte, el cliente debe poder corregir **las dos cajas**, cada una
con su propio valor: la de origen y la de destino son datos distintos y nunca deben terminar siendo
la misma caja.

### 2. Las cajas salen desordenadas en los modales

En el modal de cobranza (y el de pago) las cajas se listan en un orden arbitrario: *Banco Galicia,
Caja del Local, Cheque Propio, Mastercard, Banco Credicoop…*. Con **23 botones** en pantalla,
encontrar una caja obliga a barrer la grilla entera.

El motivo es que esos modales reutilizan el **orden manual de las cards de Tesorería**, que el
cliente configuró a propósito para esa pantalla (para tener arriba las cuentas que más mira). Ese
orden tiene sentido en las cards, pero no en un buscador de 23 botones.

### 3. Los botones se ven apagados

Los botones de medio de pago se dibujan en estilo *outline* (sólo el borde). El cliente los quiere
**rellenos**, que es el estilo del resto de las acciones principales de la app.

## Clarifications

### Session 2026-09-25

- Q: En una transferencia con dos patas, ¿se edita sólo la que se abrió o las dos cajas? → A: Las dos, cada una con su propio selector ("Sale de" / "Entra a"). Nunca se pisan con el mismo valor.
- Q: Si todos los botones quedan rellenos, ¿cómo se distingue el elegido? → A: Más oscuro **y** con un tilde, para que no dependa sólo de notar la diferencia de tono.
- Q: ¿El orden alfabético reemplaza el orden manual en todas las pantallas? → A: No. Sólo en los modales de medio de pago; las cards de Tesorería conservan el orden que configuró el cliente.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Corregir la caja de un movimiento suelto (Priority: P1)

El operador abre Editar Movimiento sobre un asiento cargado en la caja equivocada, elige la caja
correcta y guarda. El importe se va de la caja vieja y aparece en la nueva.

**Why this priority**: es el 99,4% de los movimientos editables (10.536 de 10.602) y el pedido
concreto del cliente.

**Independent Test**: editar un movimiento suelto cambiándole la caja y verificar que el saldo de
la caja original baja y el de la nueva sube por el mismo importe.

**Acceptance Scenarios**:

1. **Given** un movimiento de $50.000 en "Caja del Local", **When** el operador lo edita y elige
   "Banco Galicia", **Then** el movimiento queda en Banco Galicia y desaparece de Caja del Local.
2. **Given** ese mismo movimiento, **When** se consultan los saldos, **Then** Caja del Local bajó
   $50.000 y Banco Galicia subió $50.000.
3. **Given** el modal abierto sobre un movimiento suelto, **When** el operador lo mira, **Then** ve
   **un solo** selector de caja, con la caja actual preseleccionada.
4. **Given** el modal abierto, **When** el operador cambia sólo la fecha o el monto sin tocar la
   caja, **Then** el movimiento conserva su caja original (comportamiento actual intacto).

---

### User Story 2 - Corregir las cajas de una transferencia (Priority: P1)

El operador abre un movimiento que es parte de una transferencia y ve **las dos cajas** —origen y
destino—, pudiendo corregir cualquiera de las dos.

**Why this priority**: son sólo 66 movimientos, pero es donde un error de carga es más caro: una
transferencia mal imputada descuadra **dos** cajas a la vez.

**Independent Test**: editar una transferencia cambiando la caja de origen y verificar que la pata
de salida se movió y la de entrada quedó donde estaba.

**Acceptance Scenarios**:

1. **Given** una transferencia de Caja del Local a Banco Galicia, **When** el operador abre
   Editar Movimiento, **Then** ve **dos** selectores rotulados "Sale de" y "Entra a", cada uno con
   su caja actual.
2. **Given** ese modal, **When** cambia "Sale de" a "Caja General" y guarda, **Then** la pata de
   salida queda en Caja General y la de entrada sigue en Banco Galicia.
3. **Given** ese modal, **When** cambia las dos cajas en la misma edición, **Then** ambas patas se
   actualizan.
4. **Given** ese modal, **When** elige la misma caja en los dos selectores, **Then** el sistema lo
   rechaza: una transferencia de una caja a sí misma no existe.
5. **Given** una transferencia editada, **When** se consultan los saldos, **Then** la suma total de
   tesorería no cambió: mover una transferencia no crea ni destruye plata.

---

### User Story 3 - Modales de medio de pago legibles (Priority: P2)

El operador abre el modal de cobranza (o de pago) y encuentra la caja que busca de un vistazo: las
opciones están en orden alfabético y los botones se ven como acciones activas.

**Why this priority**: mejora de uso, no corrige datos incorrectos. Las historias 1 y 2 entregan
valor sin ésta.

**Independent Test**: abrir el modal de cobranza y verificar que las cajas están alfabéticamente y
que los botones son rellenos.

**Acceptance Scenarios**:

1. **Given** el modal de cobranza de una Venta, **When** se abre, **Then** las cajas aparecen en
   orden alfabético.
2. **Given** el modal de pago de una Compra, **When** se abre, **Then** ídem.
3. **Given** las cards de Tesorería, **When** se miran después del cambio, **Then** **conservan el
   orden manual** que configuró el cliente: el alfabético es sólo para los modales.
4. **Given** el modal abierto, **When** el operador mira los botones, **Then** son **rellenos**.
5. **Given** el modal en modo edición con una caja ya elegida, **When** el operador la mira,
   **Then** el botón elegido se distingue por ser **más oscuro y llevar un tilde**.

---

### Edge Cases

- **Movimiento no nativo** (cobro, pago, gasto): no se edita desde Tesorería. El comportamiento
  actual —rechazarlo con un mensaje— no cambia.
- **Caja destino inactiva o eliminada**: el selector sólo ofrece cajas visibles; si la caja actual
  del movimiento quedó inactiva, se muestra igual para no perder el dato, marcada como tal.
- **Transferencia con las dos patas en la misma caja**: se rechaza (US2-4).
- **Cambiar la caja de un `saldo_inicial`**: permitido. Ojo: la columna `saldo_inicial` de la cuenta
  y su movimiento **ya están desincronizados hoy** en producción (ej. Mercado Pago tiene −$1.000.000
  en el movimiento y $0 en la columna). Esta feature **no** toca esa columna ni intenta
  resincronizarla: es un problema preexistente que merece su propio análisis.
- **Movimiento con `transferencia_id` pero sin contraparte viva** (la otra pata fue eliminada): se
  trata como movimiento suelto, con un solo selector.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: El modal Editar Movimiento DEBE permitir cambiar la caja de un movimiento nativo.
- **FR-002**: En un movimiento **suelto**, el modal DEBE mostrar un único selector de caja, con la
  caja actual preseleccionada.
- **FR-003**: En un movimiento que es parte de una **transferencia**, el modal DEBE mostrar **dos**
  selectores —origen y destino— con sus cajas actuales, editables por separado.
- **FR-004**: El sistema DEBE rechazar que las dos cajas de una transferencia sean la misma.
- **FR-005**: Al guardar, el movimiento DEBE quedar imputado a la caja elegida, y los saldos de la
  caja anterior y la nueva DEBEN reflejar el cambio.
- **FR-006**: Editar una transferencia DEBE dejar las dos patas consistentes y en una sola
  operación atómica: o se guardan las dos, o ninguna.
- **FR-007**: Los selectores DEBEN ofrecer sólo cajas visibles, en orden alfabético.
- **FR-008**: El comportamiento actual de fecha, monto y observación NO DEBE cambiar, incluida la
  regla vigente de que editar el monto de una transferencia actualiza también su contraparte.
- **FR-009**: Los movimientos **no nativos** DEBEN seguir rechazándose con el mensaje actual.
- **FR-010**: En los modales de **medio de pago** (cobranza de Venta y pago de Compra), las cajas
  DEBEN listarse en orden alfabético.
- **FR-011**: El orden manual de las **cards de Tesorería** NO DEBE cambiar.
- **FR-012**: Los botones de medio de pago DEBEN ser rellenos.
- **FR-013**: El botón de la caja elegida DEBE distinguirse por un tono más oscuro **y** un tilde.
- **FR-014**: Cambiar la caja de un movimiento NO DEBE alterar la suma total de tesorería: es una
  reimputación entre cajas, no un alta ni una baja de dinero.

### Key Entities

- **Movimiento de tesorería**: asiento en una caja. Su caja pasa a ser editable cuando el
  movimiento es nativo. Los que son parte de una transferencia comparten un identificador con su
  contraparte y se editan en conjunto.
- **Cuenta de tesorería (caja)**: además del orden manual que ya tiene para las cards, se la lista
  alfabéticamente en los modales de medio de pago.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Corregir la caja de un movimiento mal imputado pasa a ser **una edición**, contra el
  borrar-y-rehacer actual que pierde la trazabilidad del asiento.
- **SC-002**: En una transferencia, las dos cajas quedan corregibles sin que ninguna pata quede
  apuntando a la caja equivocada.
- **SC-003**: La suma total de tesorería no cambia al reimputar un movimiento: lo que baja de una
  caja sube en la otra, peso por peso.
- **SC-004**: Encontrar una caja en el modal deja de requerir barrer los 23 botones: con el orden
  alfabético se ubica por la inicial.
- **SC-005**: Las cards de Tesorería siguen mostrando el orden que el cliente configuró.

## Fuera de alcance

- **Editar movimientos no nativos** (cobros, pagos, gastos): se editan desde su documento de origen.
- **Resincronizar `cuentas_tesoreria.saldo_inicial` con su movimiento**: ya están desalineados hoy
  en producción y arreglarlo requiere decidir cuál de los dos valores manda. Merece su propio
  análisis.
- **Cambiar el orden manual de las cards** ni la pantalla que lo configura.
- **Convertir un movimiento suelto en transferencia** (o al revés).

## Assumptions

- Los permisos actuales de Tesorería alcanzan: quien puede editar un movimiento puede cambiarle la
  caja. No se introduce un permiso separado.
- El cliente corrige movimientos mal imputados de forma esporádica: no hay requisitos de volumen.
- Los modales de Gastos y Otros Ingresos **ya listan alfabéticamente** (usan `orderBy('nombre')`),
  así que no requieren cambios; el problema es sólo de Venta y Compra.
