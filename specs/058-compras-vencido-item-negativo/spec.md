# Feature Specification: Estado "Vencido" en Compras + ítems con cantidad negativa

**Feature Branch**: `058-compras-vencido-item-negativo`

**Created**: 2026-08-11

**Status**: Draft

**Input**: User description: "Feedback del cliente: (1) en el listado de Compras los estados no muestran 'Vencido' — el badge de fila y el filtro 'Estado del Pago' sólo ofrecen A Pagar/Parcial/Pagado, mientras el KPI 'Vencido' de la barra superior sí existe. Confirmado con el cliente que el hueco es en el badge de fila. (2) poder cargar ítems con cantidad negativa en Compras para representar bonificaciones del proveedor (confirmado con captura de Contagram real: el campo Cantidad admite negativos, ej. -1, y el subtotal/total del renglón da negativo automáticamente; el precio se mantiene positivo)."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Ver de un vistazo qué compras están vencidas (Priority: P1)

Un usuario administrativo necesita identificar, sin abrir cada compra, cuáles tienen el pago vencido (fecha de vencimiento pasada y todavía no pagadas del todo) para priorizar pagos a proveedores. Hoy el badge de cada fila sólo distingue A Pagar/Parcial/Pagado — una compra vencida se ve igual que una recién cargada con vencimiento futuro.

**Why this priority**: Es el gap que el cliente reportó explícitamente; sin esto, el KPI "Vencido" de la barra superior no tiene forma de explorarse fila por fila ni de filtrarse.

**Independent Test**: Cargar una compra con `fecha_vto_pago` en el pasado y sin pago registrado, y verificar que su badge de fila muestra "Vencido" (rojo) en vez de "A Pagar", y que aparece al filtrar por "Vencido" en "Estado del Pago".

**Acceptance Scenarios**:

1. **Given** una compra con `fecha_vto_pago` anterior a hoy y sin pagos (o pago parcial) que dejen saldo A Pagar, **When** se lista en `/compras`, **Then** su badge de fila muestra "Vencido" en rojo (no "A Pagar" ni "Parcial").
2. **Given** una compra con `fecha_vto_pago` anterior a hoy, **When** el pago registrado cubre el 100% del saldo (A Pagar = 0), **Then** el badge muestra "Pagado" (el estado "Pagado" tiene prioridad sobre "Vencido" — una compra pagada no está vencida aunque su fecha de vencimiento haya pasado).
3. **Given** el filtro "Estado del Pago" del listado de Compras, **When** el usuario selecciona la opción "Vencido", **Then** la tabla muestra únicamente las compras vencidas según la regla del escenario 1.
4. **Given** una compra sin `fecha_vto_pago` cargada (campo opcional), **When** se lista, **Then** nunca se clasifica como "Vencido" (no hay fecha contra la cual comparar) — se comporta como hoy (A Pagar/Parcial/Pagado).

---

### User Story 2 - Cargar una bonificación del proveedor como ítem de cantidad negativa (Priority: P1)

Un usuario administrativo carga una compra cuya factura incluye, en el mismo comprobante, unidades que el proveedor bonifica o descuenta (ej. "2x1", devolución de mercadería facturada previamente, ajuste de cantidad). Hoy el formulario de Nueva/Editar Compra rechaza cualquier ítem con cantidad ≤ 0, obligando a omitir esa línea o falsear el total a mano. Contagram real permite cargar el renglón con cantidad negativa (ej. `-1`), calculando su subtotal y total en negativo automáticamente, y ese renglón resta stock igual que uno positivo lo suma (si el producto afecta stock).

**Why this priority**: Sin esto, el total de la compra y el detalle de IVA por renglón no reflejan la operación real facturada por el proveedor, forzando cargas manuales incorrectas.

**Independent Test**: Crear una compra con dos ítems del mismo producto, uno con cantidad positiva y otro con cantidad negativa, y verificar que el subtotal/total del renglón negativo es negativo, que el total de la compra resta ese importe, y que el stock del depósito refleja la suma neta (positivo menos negativo).

**Acceptance Scenarios**:

1. **Given** el formulario de Nueva Compra, **When** el usuario carga un ítem con cantidad `-2` y precio unitario positivo, **Then** el subtotal y el total con IVA de ese renglón se calculan en negativo (cantidad × precio, con el signo de la cantidad) y el total general de la compra los resta.
2. **Given** un ítem con cantidad negativa sobre un producto que afecta stock, **When** se guarda la compra, **Then** el movimiento de stock generado para ese renglón tiene signo inverso al de un renglón positivo (una compra normalmente suma stock; el renglón negativo lo resta), coherente con el signo de la cantidad cargada.
3. **Given** un ítem con precio unitario negativo, **When** el usuario intenta guardar, **Then** la validación lo rechaza igual que hoy (sólo la cantidad puede ser negativa, no el precio — confirmado contra la captura de Contagram real).
4. **Given** una compra existente editada para agregar o modificar un ítem a cantidad negativa, **When** se guarda, **Then** el recálculo de stock y de totales sigue el mismo criterio que en la creación (Escenario 1 y 2).

---

### Edge Cases

- ¿Qué pasa si la suma de cantidades (positivas y negativas) de un mismo producto en la misma compra da como resultado una cantidad neta negativa? Se permite — no hay una regla de "pendiente" a validar en Compras (a diferencia de las NC/ND, no hay un comprobante "original" contra el cual limitar lo bonificado); el usuario es responsable de que el neto tenga sentido para su operación.
- ¿Una compra con `fecha_vto_pago` pasada pero con estado "Parcial" (pagó una parte)? Se clasifica como "Vencido" (el escenario 2 sólo excluye el caso 100% pagado).
- ¿El KPI "Vencido" de la barra superior cambia? No — ya existe y ya usa esta misma regla (fecha pasada + saldo A Pagar > 0); esta feature sólo expone la misma regla a nivel de fila y de filtro, no cambia el cálculo agregado.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: El sistema DEBE clasificar una compra como "Vencido" cuando `fecha_vto_pago` está seteada, es anterior a la fecha actual, y el saldo A Pagar de la compra es mayor a 0 (no está completamente pagada).
- **FR-002**: El badge de estado de cada fila del listado de Compras DEBE mostrar "Vencido" (color rojo/danger) para las compras que cumplen FR-001, en lugar de "A Pagar" o "Parcial".
- **FR-003**: El filtro "Estado del Pago" del listado de Compras DEBE incluir "Vencido" como opción seleccionable, filtrando por el mismo criterio de FR-001.
- **FR-004**: Una compra completamente pagada (A Pagar = 0) NUNCA se clasifica como "Vencido", incluso si su `fecha_vto_pago` ya pasó.
- **FR-005**: Una compra sin `fecha_vto_pago` cargada NUNCA se clasifica como "Vencido".
- **FR-006**: El formulario de Nueva/Editar Compra DEBE permitir ítems con cantidad negativa (además de positiva); NO DEBE permitir precio unitario negativo ni cantidad igual a cero.
- **FR-007**: El subtotal y el total con IVA de un ítem con cantidad negativa se calculan con el mismo signo que la cantidad (cantidad × precio unitario, sin tomar valor absoluto).
- **FR-008**: El total general de la compra DEBE incluir la suma de todos los renglones, positivos y negativos, sin tratamiento especial.
- **FR-009**: Cuando un ítem con cantidad negativa corresponde a un producto que afecta stock, el sistema DEBE generar un movimiento de stock con signo inverso al que generaría la misma cantidad en positivo (una compra normal suma stock; el renglón negativo lo resta).
- **FR-010**: La regla de FR-006 a FR-009 aplica igual al crear una compra nueva y al editar una existente.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Un usuario puede identificar todas las compras vencidas del listado filtrando por "Vencido", sin tener que abrir cada compra individualmente para comparar fechas a mano.
- **SC-002**: El total de compras clasificadas como "Vencido" en el filtro/badges coincide exactamente con el monto que ya muestra el KPI "Vencido" de la barra superior (misma regla, dos vistas).
- **SC-003**: Un usuario puede cargar una compra con un renglón de bonificación (cantidad negativa) sin recurrir a cargarlo aparte ni a ajustar manualmente el total.
- **SC-004**: El stock resultante tras guardar una compra con renglones positivos y negativos del mismo producto es exactamente la suma neta esperada (verificable comparando el stock antes/después).

## Assumptions

- "Vencido" no es un estado persistido en la base — es derivado (misma filosofía que `estadoPago()` ya implementado: nunca forzable, siempre calculado a partir de `fecha_vto_pago` + saldo A Pagar), consistente con el KPI que ya existe.
- No se aplica el mismo cambio a Ventas en este spec — el cliente reportó puntualmente Compras; si se decide extender a Ventas (mismo gap: el badge tampoco distingue "Vencido" ahí), queda para un spec separado.
- El precio unitario nunca puede ser negativo (confirmado con la captura de Contagram real) — sólo la cantidad admite signo negativo.
- No hay validación de "pendiente"/tope para ítems negativos en Compras (a diferencia de las NC/ND) — a criterio del usuario que carga la compra.
