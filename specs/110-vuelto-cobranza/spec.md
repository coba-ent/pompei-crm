# Feature Specification: Vuelto en la cobranza de una Venta

**Feature Branch**: `110-vuelto-cobranza`

**Created**: 2026-09-24

**Status**: Draft

**Input**: Pedido del cliente del 24/09/2026: poder registrar una cobranza mayor al saldo de la venta, para el caso en que el cliente paga con dólares (u otro medio que el CRM no registra tal cual) y se le da el vuelto en efectivo en el acto.

## Contexto y problema

Cuando un cliente paga con un medio que el CRM no puede registrar tal cual —el caso concreto
relevado son **dólares**— el importe que entra a la caja no coincide con el saldo de la venta.
Ejemplo real dado por el cliente: paga **US$100** que al cambio de $1.550 son **$155.000**, por una
venta de importe menor. La diferencia se le devuelve **en el momento, en efectivo**.

Hoy el operador no puede registrar eso, porque la cobranza no admite un monto mayor al saldo
pendiente. Para salir del paso hace **tres pasos sucios**:

1. Carga una cobranza por el importe que puede (o infla la venta).
2. Saca el vuelto de la caja y lo registra como un **Gasto**.
3. Emite una **Nota de Débito** para que la venta cierre en cero.

Ese circuito deja tres daños medibles en los datos:

- **El informe de Gastos queda contaminado**: figura como gasto plata que el negocio no gastó,
  sólo devolvió. Si el gasto lleva categoría, distorsiona además el resultado por categoría.
- **La Nota de Débito es un documento comercial usado como parche de cuadre**. Una ND declara
  "te debo más plata", cosa que no ocurrió. Si esa nota llegara a enviarse a ARCA, se estaría
  declarando al fisco una operación inexistente (violación directa del principio III de la
  constitución).
- **El total de la venta queda inflado**, lo que distorsiona el ranking de ventas, el ticket
  promedio y el cálculo de CMV/resultado.

Lo que el negocio necesita no es "cobrar de más": es **registrar el vuelto**. El excedente nace y
muere en la misma operación y no deja nada pendiente con el cliente.

## Clarifications

### Session 2026-09-24

- Q: El egreso del vuelto tiene que distinguirse de un gasto. ¿Cómo lo representamos en tesorería? → A: Tipo de movimiento propio "vuelto", separado de "gasto".
- Q: ¿Qué pasa si el vuelto deja el neto por debajo del saldo de la venta? → A: Se rechaza. El neto tiene que saldar exactamente la venta.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Registrar una cobranza con vuelto (Priority: P1)

El operador cobra una venta con un medio de pago cuyo importe supera el saldo pendiente, indica
cuánto vuelto entregó, y el sistema registra en un solo paso tanto la plata que entró como la que
salió, imputando a la venta únicamente el neto.

**Why this priority**: es el pedido concreto del cliente y el único camino para eliminar el Gasto
falso y la Nota de Débito de cuadre. Sin esto, la feature no tiene valor.

**Independent Test**: se puede probar íntegramente cargando una cobranza con vuelto sobre una venta
pendiente y verificando que (a) la venta queda saldada por el neto, (b) la cuenta donde entró la
plata sube por el importe recibido, y (c) la cuenta de vuelto baja por el importe devuelto. No
requiere ninguna otra historia.

**Acceptance Scenarios**:

1. **Given** una venta con saldo pendiente de $140.000, **When** el operador registra una cobranza
   de $155.000 con $15.000 de vuelto, **Then** la venta queda con saldo $0 y estado "Cobrada".
2. **Given** esa misma cobranza, **When** se consulta la cuenta por la que entró la plata,
   **Then** registra un ingreso de $155.000 (el importe real recibido, no el neto).
3. **Given** esa misma cobranza, **When** se consulta la cuenta de vuelto, **Then** registra un
   egreso de $15.000, clasificado con el tipo "vuelto" y **no** como gasto.
4. **Given** una venta con saldo pendiente, **When** el operador registra una cobranza sin
   completar el campo de vuelto, **Then** el comportamiento es idéntico al actual (una sola
   entrada de plata, sin egreso asociado).
5. **Given** una cobranza con vuelto ya registrada, **When** se consulta el informe de Gastos,
   **Then** el vuelto **no** aparece entre los gastos.

---

### User Story 2 - Configurar la caja por defecto del vuelto (Priority: P2)

El responsable configura, una sola vez, de qué cuenta de tesorería sale el vuelto habitualmente
(en el negocio relevado: la caja local), y esa cuenta viene preseleccionada en cada cobranza.

**Why this priority**: elimina fricción en el 100% de las operaciones del caso relevado, pero la
historia 1 funciona sin esto si el operador elige la cuenta manualmente cada vez.

**Independent Test**: se configura la cuenta por defecto, se abre una cobranza nueva y se verifica
que la cuenta de vuelto aparece preseleccionada con ese valor.

**Acceptance Scenarios**:

1. **Given** una cuenta de vuelto configurada por defecto, **When** el operador abre el modal de
   cobranza, **Then** esa cuenta aparece preseleccionada.
2. **Given** una cobranza donde el vuelto sale de otra caja, **When** el operador cambia la cuenta
   en el modal, **Then** el egreso se registra en la cuenta elegida y la configuración global
   **no** se modifica.
3. **Given** que no hay cuenta por defecto configurada, **When** el operador carga un vuelto,
   **Then** el sistema le exige elegir una cuenta antes de guardar.

---

### User Story 3 - Corregir una cobranza con vuelto ya registrada (Priority: P3)

El operador edita o anula una cobranza que tenía vuelto, y los dos movimientos de tesorería quedan
consistentes con la corrección.

**Why this priority**: es necesaria para no dejar datos inconsistentes ante un error de carga, pero
el valor principal de la feature ya está entregado con las historias 1 y 2.

**Independent Test**: se edita el monto de una cobranza con vuelto y se verifica que ambos
movimientos reflejan los valores nuevos; se anula y se verifica que ambos se revierten.

**Acceptance Scenarios**:

1. **Given** una cobranza con vuelto, **When** el operador cambia el importe recibido y/o el
   vuelto, **Then** ambos movimientos de tesorería quedan actualizados y la venta se reimputa por
   el neto nuevo.
2. **Given** una cobranza con vuelto, **When** el operador la anula, **Then** se revierten tanto el
   ingreso como el egreso, sin que quede ninguno de los dos vivo en las cuentas.
3. **Given** una cobranza con vuelto, **When** el operador quita el vuelto dejándolo en cero,
   **Then** el egreso se revierte y queda sólo el ingreso.

---

### Edge Cases

- **Vuelto mayor o igual al importe recibido**: debe rechazarse. El neto resultante sería cero o
  negativo, que no es una cobranza.
- **Vuelto que deja el neto por encima del saldo de la venta**: es el caso de un sobrepago real,
  distinto del vuelto. Ver "Fuera de alcance": esta feature no lo habilita.
- **Vuelto que deja el neto por debajo del saldo de la venta** (ej. venta $140.000, recibe
  $155.000, vuelto $30.000 → neto $125.000): se rechaza (FR-007). Si el cliente queda debiendo, la
  operación correcta es una cobranza parcial común, sin vuelto.
- **Vuelto en cero o vacío**: equivale a no usar la funcionalidad; la cobranza se comporta como hoy.
- **Vuelto con la misma cuenta que la de ingreso**: operación válida en el caso "recibo $155.000 en
  la caja y devuelvo $15.000 de la misma caja", y debe quedar reflejada como dos movimientos, no
  como uno neteado, para que el arqueo de caja coincida con lo que físicamente pasó.
- **La cuenta de vuelto configurada fue desactivada o eliminada**: el sistema no debe romper; debe
  pedir que se elija una cuenta válida.
- **Ventas ya sobrepagadas de la importación histórica**: hay 57 ventas con excedente heredadas de
  Contagram. Esta feature no las modifica ni las migra.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: El sistema DEBE permitir registrar, en la cobranza de una Venta, un importe de vuelto
  opcional junto al importe recibido.
- **FR-002**: El sistema DEBE imputar a la venta el **neto** (recibido − vuelto), de modo que el
  saldo pendiente y el estado de cobro se calculen sobre ese neto.
- **FR-003**: El sistema DEBE registrar el importe **recibido completo** como ingreso en la cuenta
  de tesorería elegida para el cobro.
- **FR-004**: El sistema DEBE registrar el **vuelto** como un egreso en la cuenta de tesorería de
  vuelto, clasificado con un **tipo de movimiento propio ("vuelto")**, distinto del tipo usado para
  los gastos.
- **FR-005**: El vuelto NO DEBE aparecer en el informe de Gastos ni computarse como gasto en ningún
  informe de resultados.
- **FR-006**: El sistema DEBE rechazar un vuelto mayor o igual al importe recibido, con un mensaje
  que explique el motivo.
- **FR-007**: El sistema DEBE exigir que el **neto salde exactamente el saldo pendiente** de la
  venta (recibido − vuelto = saldo pendiente). Se rechaza tanto el neto que deja saldo pendiente
  (cobro parcial con vuelto) como el que lo supera (sobrepago). Rationale: el vuelto existe para
  cerrar la operación en el acto; si el cliente queda debiendo, lo que corresponde es una cobranza
  parcial común sin vuelto.
- **FR-008**: Los dos movimientos (ingreso y egreso) DEBEN registrarse de forma atómica: o quedan
  ambos, o no queda ninguno.
- **FR-009**: El sistema DEBE permitir configurar globalmente la cuenta de tesorería por defecto
  para los vueltos.
- **FR-010**: El sistema DEBE preseleccionar esa cuenta al cargar un vuelto, y DEBE permitir
  cambiarla en la operación puntual sin alterar la configuración global.
- **FR-011**: El sistema DEBE exigir una cuenta de vuelto cuando se informa un importe de vuelto
  mayor a cero.
- **FR-012**: Al editar una cobranza con vuelto, el sistema DEBE dejar ambos movimientos
  consistentes con los valores nuevos.
- **FR-013**: Al anular una cobranza con vuelto, el sistema DEBE revertir ambos movimientos.
- **FR-014**: Una cobranza sin vuelto DEBE comportarse exactamente como hoy (compatibilidad hacia
  atrás con los cobros ya existentes).
- **FR-015**: El vuelto DEBE quedar visible en la ficha de la venta junto a su cobranza, para que un
  tercero entienda por qué el importe recibido difiere del imputado.
- **FR-016**: El **recibo imprimible** de una cobranza con vuelto DEBE reflejar la operación real:
  el importe recibido, el vuelto entregado y el neto imputado. Un recibo que muestre sólo el neto
  contradice lo que el cliente entregó en mano y es un documento que se le da al cliente.

### Key Entities

- **Cobro**: registro de plata recibida contra una Venta. Suma dos datos nuevos: el importe de
  vuelto entregado y la cuenta de tesorería de la que salió. Un cobro sin vuelto es el caso actual.
- **Movimiento de tesorería**: asiento en una cuenta. Esta feature suma un **tipo de movimiento
  nuevo ("vuelto")** a los ya existentes, para que un egreso por vuelto nunca se confunda con un
  gasto en los informes ni en la grilla de tesorería.
- **Configuración de Ventas**: fila única de defaults globales del sistema. Suma la cuenta de
  tesorería por defecto para vueltos.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: El operador registra una cobranza con vuelto en **una sola operación**, contra los
  3 pasos actuales (cobranza + gasto + nota de débito).
- **SC-002**: El 100% de los vueltos registrados con la funcionalidad nueva quedan **fuera** del
  informe de Gastos.
- **SC-003**: Se deja de emitir Notas de Débito con el único fin de cuadrar una venta pagada con
  vuelto (objetivo: cero notas de ese tipo a partir de la puesta en producción).
  *(Outcome de negocio observable después del lanzamiento, no trabajo construible: se mide mirando
  las ND emitidas en los meses siguientes, no con una tarea de implementación.)*
- **SC-004**: El saldo de las cuentas de tesorería involucradas coincide, peso por peso, con el
  movimiento físico de plata (entró el importe recibido, salió el vuelto).
- **SC-005**: El total de la venta refleja lo efectivamente vendido, sin inflarse por el vuelto, de
  modo que ranking de ventas, ticket promedio y CMV no se distorsionan.
- **SC-006**: Las cobranzas existentes (sin vuelto) siguen comportándose igual, sin que ningún
  saldo de venta, cliente o cuenta cambie tras la puesta en producción.

## Fuera de alcance

- **Saldo a favor por cobro excedente**: cuando el cliente deja plata a cuenta, el negocio usa Notas
  de Crédito, que ya está implementado (spec 072). Esta feature NO habilita cobranzas que dejen
  saldo a favor: el vuelto se entrega en el acto.
- **Pagos a proveedores**: decisión explícita del 24/09/2026. La estructura es espejada, pero no se
  incluye hasta que el negocio lo pida.
- **Cuentas de tesorería en moneda extranjera**: se evaluó registrar los dólares en una cuenta USD
  con cotización. Es la solución contablemente más completa, pero excede lo que el negocio necesita
  hoy (implicaría cotizaciones, diferencias de cambio y arqueos por moneda). Queda anotado como
  posible evolución.
- **Migración de las 57 ventas sobrepagadas históricas**: quedan como están.

## Assumptions

- El vuelto se entrega **siempre en el momento** de la cobranza. No existe el caso "te lo debo y te
  lo doy mañana", que sería otro flujo (una devolución pendiente).
- El vuelto se entrega **siempre en pesos**, aunque lo recibido haya sido en otra moneda. El CRM
  registra únicamente importes en pesos.
- El operador convierte la moneda extranjera a pesos **por fuera del sistema** (calculadora) y carga
  el importe ya convertido. El CRM no almacena la cotización ni la moneda original en esta versión.
- La cuenta de vuelto habitual es la caja local del negocio, pero se deja configurable porque el
  usuario anticipó que podría cambiar.
- Los permisos existentes de cobranza alcanzan: quien puede registrar una cobranza puede registrar
  su vuelto. No se introduce un permiso separado.
- El caso ocurre con **baja frecuencia** (operaciones puntuales en dólares), por lo que no hay
  requisitos de volumen ni de performance particulares.
