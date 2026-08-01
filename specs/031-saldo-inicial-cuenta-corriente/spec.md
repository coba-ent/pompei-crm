# Feature Specification: Saldo Inicial en Cuenta Corriente

**Feature Branch**: `031-saldo-inicial-cuenta-corriente`

**Created**: 2026-08-01

**Status**: Draft

**Input**: User description: "Incorporar el campo `saldo_inicial`/`saldo_inicial_fecha` de Cliente (y de Proveedor) al cálculo de Cuenta Corriente (`App\Services\Tesoreria\CuentaCorriente::aging()` y `porCliente()`), que hoy sólo suma `Venta::aCobrar()`/`Compra::aPagar()` y ignora por completo ese campo pese a que ya se carga y guarda en la ficha del Cliente/Proveedor. El saldo inicial tiene que sumar al total de deuda de ese cliente/proveedor y caer en el bucket de antigüedad que le corresponda según `saldo_inicial_fecha`, afectando tanto el aging agregado del Dashboard (spec 010) como 'Saldos Clientes' (spec 029) y el futuro informe de Cuenta Corriente Proveedores."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - El saldo inicial de un cliente se refleja en su deuda (Priority: P1) 🎯 MVP

Un usuario carga un Cliente nuevo (migrando desde otro sistema) con un saldo inicial de $50.000 y fecha de apertura 01/03/2026. Hoy ese monto queda guardado en la ficha pero invisible en toda pantalla de Cuenta Corriente — el usuario ve al cliente con "$0 de deuda" cuando en realidad debe $50.000. Con esta feature, ese saldo inicial aparece sumado al total de deuda del cliente en "Saldos Clientes" (Informes → Cuenta Corriente, spec 029) y en el bloque "Cuentas a Cobrar" del Dashboard (spec 010), en el bucket de antigüedad que corresponda según cuánto tiempo pasó desde la fecha de apertura.

**Why this priority**: Es el caso de uso que motiva toda la feature — sin esto, el saldo inicial cargado en la ficha de Cliente es un dato muerto que no se refleja en ningún cálculo de deuda, lo cual es directamente incorrecto para cualquier cliente migrado con saldo previo.

**Independent Test**: Cargar un Cliente con saldo inicial y fecha, sin ninguna Venta asociada, y verificar que aparece en "Saldos Clientes" con el monto y bucket correctos.

**Acceptance Scenarios**:

1. **Given** un Cliente con `saldo_inicial = 50000` y `saldo_inicial_fecha` hace 45 días, **When** se consulta "Saldos Clientes", **Then** ese cliente aparece con $50.000 en el bucket "31 y 60" y en el Total.
2. **Given** ese mismo cliente además tiene una Venta con `a_cobrar = 10000` a vencer, **When** se consulta "Saldos Clientes", **Then** el Total del cliente es $60.000, con $50.000 en el bucket "31 y 60" y $10.000 en "A Vencer".
3. **Given** un Cliente con `saldo_inicial = 0` (o null), **When** se consulta "Saldos Clientes", **Then** el cálculo de su deuda no cambia respecto del comportamiento actual (sin saldo inicial no hay diferencia).
4. **Given** un Cliente con saldo inicial ya totalmente registrado, **When** se consulta el bloque "Cuentas a Cobrar" del Dashboard, **Then** el total coincide exacto con el Total General de "Saldos Clientes" (mismo invariante ya sostenido por spec 029 SC-003 — ambas pantallas comparten la misma fuente de cálculo).

---

### User Story 2 - El saldo inicial aparece en el detalle de Movimientos (Priority: P2)

Un usuario filtra "Movimientos" (spec 029) por un cliente con saldo inicial y quiere ver de dónde sale el total que le mostró "Saldos Clientes" — hoy sólo vería sus Ventas/Cobros/Notas, sin el saldo inicial, y la suma no cerraría contra el Total.

**Why this priority**: Cierra el mismo invariante que spec 029 ya probó entre "Movimientos" y "Saldos Clientes" (SC-002) — sin esto, ese invariante se rompe para cualquier cliente con saldo inicial, lo cual es confuso y parece un bug aunque no lo sea.

**Independent Test**: Filtrar "Movimientos" por un cliente con saldo inicial y verificar que aparece una fila propia para ese saldo, y que la suma de "A Cobrar" de todas sus filas coincide con el Total de "Saldos Clientes" para ese cliente.

**Acceptance Scenarios**:

1. **Given** un Cliente con saldo inicial de $50.000 y una Venta con `a_cobrar = 10000`, **When** se filtra "Movimientos" por ese cliente, **Then** aparece una fila con Operación "Saldo Inicial", fecha = `saldo_inicial_fecha`, A Cobrar = $50.000, además de la fila de la Venta.
2. **Given** esas mismas filas, **When** se suma el "A Cobrar" de todas las filas de ese cliente en "Movimientos", **Then** el resultado coincide exacto con el Total que muestra "Saldos Clientes" para ese cliente ($60.000).
3. **Given** un Cliente sin saldo inicial (0 o null), **When** se filtra "Movimientos" por ese cliente, **Then** no aparece ninguna fila "Saldo Inicial" (comportamiento actual sin cambios).
4. **Given** varios Clientes con Ventas, Cobros y saldos iniciales cargados, **When** se filtra "Movimientos" por Operación = "Saldo Inicial" (sin filtrar por Cliente), **Then** sólo quedan las filas de tipo "Saldo Inicial" de todos los Clientes que tienen uno cargado (mismo comportamiento que ya tienen los demás valores del filtro Operación, spec 029).

---

### User Story 3 - Un saldo inicial negativo se trata como saldo a favor (Priority: P3)

Un usuario carga un Cliente con `saldo_inicial = -5000` (venía con un crédito a favor de un sistema anterior). Ese crédito tiene que descontar del total de deuda del cliente, no sumarlo — y si el cliente no tiene ninguna otra deuda, su Total en "Saldos Clientes" queda en negativo (saldo a favor), visible como tal.

**Why this priority**: Es un caso real pero menos frecuente que el saldo inicial positivo (P1); se prioriza último porque no bloquea el caso de uso principal, pero hay que resolverlo para que el signo del saldo inicial no se pierda o se interprete al revés.

**Independent Test**: Cargar un Cliente con saldo inicial negativo y sin otra deuda, verificar que su Total en "Saldos Clientes" es negativo y que igual aparece en el listado (no se excluye por tener saldo "≠ 0").

**Acceptance Scenarios**:

1. **Given** un Cliente con `saldo_inicial = -5000` y ninguna Venta, **When** se consulta "Saldos Clientes", **Then** el cliente aparece con Total = -$5.000 en el bucket que le corresponda según su fecha.
2. **Given** ese mismo cliente además tiene una Venta con `a_cobrar = 8000` en el mismo bucket, **When** se consulta "Saldos Clientes", **Then** el Total de ese bucket es $3.000 (8.000 − 5.000).

---

### Edge Cases

- Cliente/Proveedor con `saldo_inicial` distinto de 0 pero `saldo_inicial_fecha` vacía: el monto se trata como "A Vencer" (no hay fecha de referencia para calcular antigüedad, no se asume vencido).
- Cliente/Proveedor con `saldo_inicial = 0`: no genera fila ni bucket — comportamiento idéntico al actual.
- El saldo inicial y las Ventas/Compras de un mismo cliente pueden caer en distintos buckets — cada uno se clasifica de forma independiente según su propia fecha de referencia.
- Un Cliente/Proveedor cuyo saldo inicial (solo) ya compensa exactamente el resto de su deuda (total = 0) no aparece en "Saldos Clientes" (misma regla de exclusión que ya aplica hoy para clientes 100% cobrados, FR-002 de spec 029).
- Editar el saldo inicial de un Cliente/Proveedor después de creado recalcula su aging en la siguiente consulta (no hay snapshot ni historial — mismo comportamiento "sin caché" que el resto del aging).

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: El cálculo de aging de Clientes (`CuentaCorriente::aging('cliente')` y `porCliente('cliente')`) DEBE incluir el `saldo_inicial` de cada Cliente con `saldo_inicial ≠ 0`, sumado al resto de su deuda derivada de Ventas.
- **FR-002**: El cálculo de aging de Proveedores (`CuentaCorriente::aging('proveedor')` y `porCliente('proveedor')`) DEBE incluir el `saldo_inicial` de cada Proveedor con `saldo_inicial ≠ 0`, sumado al resto de su deuda derivada de Compras (misma regla que FR-001, del lado de Proveedores).
- **FR-003**: El saldo inicial DEBE clasificarse en el mismo esquema de buckets de antigüedad ya usado (A Vencer / 0-30 / 31-60 / 61-90 / +90), usando `saldo_inicial_fecha` como fecha de referencia para el cálculo de antigüedad (mismo criterio que `fecha_vto_cobro`/`fecha_vto_pago` ya usan para Ventas/Compras).
- **FR-004**: Si `saldo_inicial_fecha` es nula pero `saldo_inicial ≠ 0`, el monto DEBE clasificarse en el bucket "A Vencer".
- **FR-005**: Un `saldo_inicial` negativo DEBE tratarse como saldo a favor: resta del total de deuda del Cliente/Proveedor (puede dejar su Total en negativo).
- **FR-006**: Un Cliente/Proveedor cuyo total combinado (saldo inicial + resto de la deuda) sea ≈ 0 NO DEBE aparecer en "Saldos Clientes" (misma regla de exclusión ya vigente, FR-002 de spec 029).
- **FR-007**: El total agregado que consume el Dashboard (spec 010, bloque "Cuentas a Cobrar"/"Cuentas a Pagar") DEBE incluir el saldo inicial, dado que reutiliza el mismo `aging()` que esta feature modifica — sin cambios adicionales en el Dashboard más allá de los que ya hereda de `CuentaCorriente`.
- **FR-008**: El tab "Movimientos" de Cuenta Corriente Clientes (spec 029) DEBE mostrar una fila sintética con Operación "Saldo Inicial" para todo Cliente con `saldo_inicial ≠ 0`, con fecha = `saldo_inicial_fecha` (o vacía si no tiene), A Cobrar = `saldo_inicial`, y el resto de columnas (Categoría, Total Venta, Cobrado, N° de Comprobante, Medio de Cobro, Descripción) vacías.
- **FR-009**: La suma del "A Cobrar" de todas las filas de un Cliente en "Movimientos" (incluida su fila "Saldo Inicial" si tiene) DEBE coincidir exacto con el "Total" que ese mismo Cliente muestra en "Saldos Clientes" — mismo invariante ya sostenido por spec 029 (SC-002), extendido para cubrir el saldo inicial.
- **FR-010**: Cuenta Corriente Proveedores sigue sin pantalla propia (fuera de alcance, ya documentado como pendiente en `documentacion_principal_crm.md` §7) — esta feature sólo asegura que el **cálculo** de aging de Proveedores (consumido hoy sólo por el Dashboard) ya incluya el saldo inicial, para que el día que se construya esa pantalla no arrastre la misma brecha que tenía Clientes.
- **FR-011**: El signo/monto del `saldo_inicial` no se modifica ni se resetea por esta feature — sigue siendo un campo editable en la ficha de Cliente/Proveedor (spec existente), esta feature sólo lo incorpora al cálculo de lectura.

### Key Entities *(include if feature involves data)*

- **Cliente / Proveedor**: entidades ya existentes; se reutilizan los campos ya existentes `saldo_inicial` (decimal) y `saldo_inicial_fecha` (date, nullable) — sin cambios de esquema.
- **Fila de aging por Cliente/Proveedor** (spec 029, vista derivada no persistida): se extiende el cálculo que la genera para que sume el saldo inicial al bucket correspondiente, sin cambios en su forma (mismos campos `a_vencer`/`vencido_0_30`/.../`total`).
- **Fila de "Saldo Inicial" en Movimientos** (nueva variante de fila derivada, no persistida): mismo shape que las filas de Venta/Cobro/Nota ya definidas en spec 029 data-model.md, con `operacion = 'saldo_inicial'`.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Un Cliente/Proveedor con saldo inicial cargado y ninguna otra operación aparece en "Saldos Clientes" con el monto y bucket correctos, donde hoy no aparece en absoluto.
- **SC-002**: El Total General de "Saldos Clientes" y el total de "Cuentas a Cobrar" del Dashboard coinciden exacto entre sí después de incluir saldos iniciales (mismo invariante que spec 029 SC-003, verificado de nuevo tras el cambio).
- **SC-003**: Para cualquier Cliente con saldo inicial, la suma de "A Cobrar" de sus filas en "Movimientos" coincide exacto con su "Total" en "Saldos Clientes" (100% de los casos, no sólo los que no tienen saldo inicial).
- **SC-004**: Ningún Cliente/Proveedor sin saldo inicial (0 o null) cambia de monto o de aparición/no-aparición en ninguna pantalla respecto del comportamiento anterior a esta feature (cero regresiones para el caso ya cubierto).

## Assumptions

- Proveedores no tiene pantalla de Informe de Cuenta Corriente propia todavía (sigue fuera de alcance, documentado en §7) — esta feature toca sólo el cálculo compartido (`CuentaCorriente` service), no crea esa pantalla.
- No hay requisito de mostrar el saldo inicial como una entidad editable desde las pantallas de Cuenta Corriente — sigue editándose únicamente desde la ficha de Cliente/Proveedor (fuera de alcance de esta feature).
- No se versiona ni se guarda historial de cambios al saldo inicial — es el mismo campo mutable ya existente; si se edita, el aging se recalcula en la siguiente consulta (sin snapshot).
- La fila sintética "Saldo Inicial" en Movimientos no tiene "N° de Comprobante" ni "Medio de Cobro" porque no es un documento real emitido por el sistema — es un monto de apertura de cuenta.
- Compras/Proveedores no se testean con la misma profundidad que Clientes en esta iteración (spec 029 tampoco construyó pantalla de Movimientos para Proveedores) — FR-002 y su test cubren el aging agregado, no un "Movimientos de Proveedor" que no existe.
