# Feature Specification: Descuento general aplicado proporcionalmente a neto e IVA

**Feature Branch**: `044-descuento-general-iva`

**Created**: 2026-08-04

**Status**: Draft

**Input**: User description: "Corregir CalculoComprobante (Presupuestos y Ventas): el descuento
general (descuento_general_pct) hoy se resta únicamente del neto (subtotal_con_descuento) pero NO
reduce proporcionalmente el IVA — el total final resulta de restarle ese descuento (calculado solo
sobre neto) al total-con-IVA sin descontar, dejando el IVA facturado matemáticamente inconsistente
con el neto declarado (ej: neto descontado 15% pero IVA sigue siendo 21% del neto SIN descontar).
Esto ya está bloqueando el envío a ARCA de Ventas con descuento general (spec 042 lo detecta y
rechaza correctamente por inconsistencia de importes antes de contactar a ARCA). Corregir para que
el descuento general se aplique proporcionalmente tanto al neto como al IVA de cada línea/alícuota
(criterio fiscal estándar: descuento pre-impuesto), de modo que BaseImp × alícuota = Importe se
mantenga consistente por cada bloque AlicIva, y que el total resultante sea menor al actual (hoy
estas Ventas están facturando de más). Afecta a Presupuestos y Ventas por igual (mismo servicio
compartido CalculoComprobante). Caso real de referencia para tests: Venta 0001-00016359 (VPS
producción), 3 ítems al 21%, descuento_general_pct=15%, subtotal_sin_descuento=299046.92,
descuento=44857.04, subtotal_con_descuento=254189.88, total actual=316989.74 (a corregir)."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Emitir CAE de una Venta con descuento general sin rechazo por inconsistencia de IVA (Priority: P1) 🎯 MVP

Un usuario carga una Venta con varios ítems y le aplica un descuento general (ej. 15%) a nivel
comprobante. Al enviarla a ARCA, el IVA declarado hoy no coincide con el neto descontado (el
descuento nunca le pega al IVA), y spec 042 rechaza el envío por inconsistencia de importes antes de
contactar a ARCA. El usuario necesita que el descuento general reduzca proporcionalmente tanto el
neto como el IVA, para que la Venta se pueda facturar electrónicamente sin fricción.

**Why this priority**: bloquea la facturación electrónica real de Ventas con descuento general —
mismo tipo de incidente fiscal que spec 042 (causa raíz distinta, mismo síntoma: rechazo antes de
ARCA por importes inconsistentes). Sin esto, ninguna Venta con descuento general puede emitir CAE.

**Independent Test**: crear una Venta con 3 ítems al 21% y descuento general del 15% (caso real:
Venta 0001-00016359), confirmar que el neto y el IVA calculados guardan la proporción 21% entre sí, y
que el envío a ARCA (mock WSFEv1) no es rechazado por `ValidadorDatosFiscales`.

**Acceptance Scenarios**:

1. **Given** una Venta con ítems al 21% de IVA y un descuento general del 15%, **When** se guarda,
   **Then** el IVA total resultante es el 21% del neto ya con el 15% de descuento aplicado (no del
   neto bruto).
2. **Given** esa misma Venta, **When** se envía a ARCA, **Then** la solicitud no es rechazada por
   `ValidadorDatosFiscales` por inconsistencia entre `ImpNeto`/`ImpIVA` y los bloques `AlicIva`.
3. **Given** una Venta con ítems en dos alícuotas distintas (21% y 10,5%) y descuento general,
   **When** se guarda, **Then** el descuento se prorratea dentro de cada alícuota, de forma que
   `BaseImp × alícuota = Importe` se mantiene consistente para cada bloque por separado.

---

### User Story 2 - Ver en Presupuestos el mismo total corregido que tendrá la Venta convertida (Priority: P2)

Un usuario arma un Presupuesto con descuento general y después lo convierte en Venta. El total del
Presupuesto (y de la Venta resultante) debe reflejar el descuento aplicado también sobre el IVA, para
que el número que el usuario vio y aprobó en el Presupuesto sea el mismo que termina facturado.

**Why this priority**: consistencia entre lo que el usuario ve en Presupuestos y lo que termina
facturado en Ventas — no es tan urgente como destrabar la emisión de CAE (P1), pero es el mismo
cálculo compartido y no puede quedar inconsistente entre ambas pantallas.

**Independent Test**: crear un Presupuesto con descuento general, confirmar que el total mostrado usa
la misma fórmula corregida, y que al convertirlo a Venta el total no cambia por este motivo.

**Acceptance Scenarios**:

1. **Given** un Presupuesto con ítems e IVA y un descuento general, **When** se calcula el total,
   **Then** usa la misma fórmula corregida que Ventas (mismo servicio `CalculoComprobante`).
2. **Given** ese Presupuesto, **When** se convierte a Venta, **Then** el total de la Venta resultante
   es igual al total ya mostrado en el Presupuesto (sin recalcular distinto).

---

### Edge Cases

- Sin descuento general (`descuento_general_pct` es 0 o null): el comportamiento no cambia respecto a
  hoy — el neto, el IVA y el total dan exactamente igual que antes de esta corrección (no-regresión).
- Ítems con descuento de línea (`descuento_pct`) individual **y** descuento general combinados: el
  general se aplica sobre el neto que ya tiene descontado el de línea, igual que hoy se hace para el
  neto — sólo cambia que ahora también reduce el IVA proporcionalmente.
- Comprobante con ítems en varias alícuotas de IVA: el descuento general se prorratea dentro de cada
  grupo de alícuota por separado (no se puede sacar como un único monto agregado), para que cada
  bloque `AlicIva` siga siendo internamente consistente.
- Redondeo por ítem a 2 decimales puede generar una diferencia acumulada de centavos entre la suma de
  IVA por ítem y el IVA total — cubierto por la tolerancia de $0.01 ya definida en spec 042
  (`ValidadorDatosFiscales`), no se introduce una tolerancia nueva.
- Ventas que ya obtuvieron CAE aprobado con el cálculo anterior (antes de esta corrección): no se
  recalculan ni se reemite nada retroactivamente — el comprobante fiscal ya declarado ante ARCA queda
  como está; la corrección aplica sólo hacia adelante, a comprobantes nuevos o aún no enviados.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: `CalculoComprobante::calcular()` MUST aplicar el descuento general
  (`descuento_general_pct`) proporcionalmente tanto al neto como al IVA de cada ítem, no sólo al
  neto.
- **FR-002**: Para cada ítem, el IVA resultante después del descuento general DEBE mantener la misma
  proporción respecto de su neto post-descuento que su `iva_pct` original (ej. un ítem al 21% de IVA
  sigue siendo 21% de su neto ya descontado, no del neto bruto).
- **FR-003**: El total final del comprobante (`total`) DEBE ser menor o igual al que resultaría sin
  aplicar el descuento general al IVA (equivalente a: el descuento general ahora también reduce el
  IVA, no sólo el neto) — para cualquier `descuento_general_pct` mayor a 0.
- **FR-004**: Cuando el comprobante tiene ítems en más de una alícuota de IVA, el descuento general
  DEBE prorratearse dentro de cada grupo de alícuota, de forma que la relación `BaseImp × alícuota =
  Importe` se mantenga consistente por cada bloque, dentro de la tolerancia de $0.01 ya definida en
  spec 042.
- **FR-005**: Esta corrección MUST aplicarse por igual a Presupuestos y Ventas, dado que ambos usan
  `CalculoComprobante::calcular()` como único punto de cálculo.
- **FR-006**: Cuando `descuento_general_pct` es 0 o no está presente, el resultado de
  `CalculoComprobante::calcular()` MUST ser idéntico al comportamiento actual (no-regresión para el
  caso sin descuento general).
- **FR-007**: Comprobantes fiscales ya aprobados (CAE obtenido) antes de esta corrección NO deben
  recalcularse ni reemitirse — la corrección aplica sólo a cálculos nuevos hacia adelante.

### Key Entities *(include if feature involves data)*

- **VentaItem / PresupuestoItem**: ítem individual de un comprobante — su `subtotal` (neto) y
  `subtotal_con_iva` pasan a reflejar el descuento general prorrateado, además del descuento de línea
  que ya tenían.
- **Venta / Presupuesto**: `subtotal_con_descuento` (neto) no cambia de significado; `total` sí cambia
  de valor para los comprobantes con descuento general, porque ahora el IVA que lo compone también
  está descontado.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Una Venta con descuento general y una única alícuota de IVA puede enviarse a ARCA sin
  ser rechazada por `ValidadorDatosFiscales` por inconsistencia de importes (0 rechazos por este
  motivo en el caso de referencia, Venta 0001-00016359, y en cualquier Venta equivalente).
- **SC-002**: Para cualquier comprobante con `descuento_general_pct` > 0, el total facturado es menor
  al que se hubiese calculado con la fórmula anterior (el usuario deja de facturar de más).
- **SC-003**: Para comprobantes con `descuento_general_pct` = 0, el total calculado no cambia en
  ningún caso (0 regresiones sobre comprobantes sin descuento general).
- **SC-004**: El total mostrado en un Presupuesto con descuento general es idéntico al de la Venta que
  resulta de convertirlo, en el 100% de los casos.

## Assumptions

- El criterio fiscal elegido (confirmado por el usuario) es que el descuento general es un descuento
  **pre-impuesto**: se aplica proporcionalmente tanto al neto como al IVA de cada línea, no sólo al
  neto — es el criterio estándar de facturación con descuentos.
- El descuento por línea (`descuento_pct` de cada ítem) no cambia su comportamiento actual — sigue
  aplicándose primero, sobre el bruto del ítem, tal como hoy. Sólo cambia cómo se aplica el descuento
  *general* (a nivel comprobante) sobre lo que resulta de ahí.
- No se requiere una migración de datos ni recálculo retroactivo de Ventas/Presupuestos ya guardados
  con la fórmula anterior — sólo afecta a cálculos nuevos (spec 042 ya estableció el mismo criterio
  para no tocar comprobantes ya declarados ante ARCA).
- El caso de Notas de Crédito/Débito (que no tienen desglose de ítems propio, ver spec 042 research.md
  §1) queda fuera de alcance — su cálculo (`monto / 1.21`) no depende de `CalculoComprobante` y no se
  toca en esta spec.
