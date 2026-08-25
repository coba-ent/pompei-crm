# Feature Specification: Neteo de NC/ND en Rankings del Dashboard

**Feature Branch**: `079-neteo-nc-nd-rankings-dashboard`

**Created**: 2026-08-24

**Status**: Draft

**Input**: User description: "Netear Notas de Crédito/Débito en el Ranking de Clientes (por monto vendido) y Ranking de Productos (por cantidad vendida) del Dashboard. Actualmente estos dos rankings se calculan sobre el monto/cantidad bruto de la Venta, sin restar NC ni sumar ND. Aplicar el mismo criterio de neteo ya implementado en spec 046 para KPIs/Totales/Donas del Dashboard. Este cambio no afecta el Ranking del módulo Informes (spec 069), que es una pantalla distinta y queda fuera de este alcance."

## Clarifications

### Session 2026-08-24

- Q: El criterio de neteo original que pediste (piso en $0 dentro del mismo período) no coincide con lo que el código de `montoNetoQuery()` hace hoy para KPIs/Totales/Donas: ese método sacó el piso el 18/08/2026 a pedido tuyo, tras verificar contra Contagram real (una NC mayor al total de su compra deja el neto negativo, sin recortar). ¿Qué criterio aplico a los Rankings? → A: Sin piso — usar exactamente el mismo criterio vigente hoy en `montoNetoQuery()` (spec 046, revisión 18/08/2026): nunca se recorta en $0, una NC/ND puede dejar el neto negativo. Así el Ranking concilia centavo a centavo con los Totales del Dashboard.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Ranking de Clientes neteado (Priority: P1)

Como usuario del negocio, al mirar el Ranking de Clientes del Dashboard quiero que el monto vendido de cada cliente ya refleje las Notas de Crédito y Débito emitidas sobre sus ventas, para no sobreestimar cuánto le vendí realmente a ese cliente en el período.

**Why this priority**: Es el ranking más consultado del Dashboard para decisiones comerciales (a quién ofrecerle condiciones, quién es el cliente top); mostrarlo bruto puede llevar a decisiones erróneas cuando hay devoluciones grandes.

**Independent Test**: Con un cliente que tiene una venta de $10.000 en el período y una NC de $3.000 sobre esa misma venta emitida en el mismo período, el Ranking de Clientes debe mostrar $7.000 para ese cliente, no $10.000.

**Acceptance Scenarios**:

1. **Given** un cliente con una venta de $10.000 en el período filtrado y una NC de $3.000 sobre esa venta, emitida dentro del mismo período, **When** se abre el Dashboard con ese período, **Then** el Ranking de Clientes muestra $7.000 para ese cliente.
2. **Given** un cliente con una venta de $10.000 en el período filtrado y una ND de $1.500 sobre esa venta, emitida dentro del mismo período, **When** se abre el Dashboard con ese período, **Then** el Ranking de Clientes muestra $11.500 para ese cliente.
3. **Given** un cliente con una venta de $5.000 en el período filtrado y una NC de $8.000 sobre esa venta, emitida dentro del mismo período (NC mayor al monto de la venta), **When** se abre el Dashboard con ese período, **Then** el Ranking de Clientes muestra $0 para ese cliente (piso en cero), no un monto negativo.
4. **Given** un cliente con una venta de $10.000 emitida en el período filtrado y una NC de $3.000 sobre esa venta pero emitida en un período distinto (posterior), **When** se abre el Dashboard con el período de la venta original, **Then** el Ranking de Clientes muestra $10.000 para ese período (la NC no afecta el período de la venta, porque se imputa al período de la venta que ajusta, no al de su propia emisión — ver FR-002).

---

### User Story 2 - Ranking de Productos neteado (Priority: P2)

Como usuario del negocio, al mirar el Ranking de Productos del Dashboard quiero que la cantidad vendida de cada producto ya refleje las unidades devueltas o ajustadas por Notas de Crédito y Débito, para tener una lectura real de qué productos rotan más.

**Why this priority**: Es el segundo ranking del Dashboard en relevancia (después del de Clientes); mismo problema de fondo pero de menor impacto porque las devoluciones de mercadería suelen ser de menor volumen relativo que los ajustes monetarios.

**Independent Test**: Con un producto que vendió 20 unidades en el período y una NC que devuelve 5 unidades de esa misma venta emitida en el mismo período, el Ranking de Productos debe mostrar 15 unidades para ese producto.

**Acceptance Scenarios**:

1. **Given** un producto con 20 unidades vendidas en el período filtrado y una NC que ajusta 5 unidades de esa venta, emitida dentro del mismo período, **When** se abre el Dashboard con ese período, **Then** el Ranking de Productos muestra 15 unidades para ese producto.
2. **Given** un producto con 10 unidades vendidas en el período filtrado y una NC que ajusta 12 unidades de esa venta, emitida dentro del mismo período (ajuste mayor a lo vendido), **When** se abre el Dashboard con ese período, **Then** el Ranking de Productos muestra 0 unidades para ese producto (piso en cero).

---

### User Story 3 - Neteo cruza períodos sin piso (Priority: P3)

Como usuario del negocio, cuando una NC/ND ajusta una venta de un período anterior, quiero que ese ajuste impacte en el período en que se emitió la venta original (no en el período de emisión de la nota), consistente con el resto del Dashboard, y que en ese caso no exista piso en cero si el ajuste hace que el neto del período sea negativo.

**Why this priority**: Es el caso menos frecuente (ajustes que cruzan período) pero es el que exige la regla más fina del criterio de neteo ya vigente en KPIs/Totales/Donas (spec 046); hay que preservar esa misma regla para que el Ranking sea consistente con el resto del Dashboard.

**Independent Test**: Con una venta de julio ya neteada a $0 en el ranking de julio (por una NC de julio) y una segunda NC sobre esa misma venta emitida en agosto, el ranking de agosto para ese cliente debe reflejar el excedente de esa segunda NC sin piso en cero.

**Acceptance Scenarios**:

1. **Given** una venta de $5.000 emitida en julio, ya neteada a $0 en julio por una NC de $5.000 de julio, y una segunda NC de $2.000 sobre la misma venta pero emitida en agosto, **When** se abre el Dashboard filtrado por agosto, **Then** el Ranking de Clientes muestra -$2.000 para ese cliente en agosto (sin piso, porque la nota cae en un período distinto al de la venta que ajusta).

---

### Edge Cases

- ¿Qué pasa si la NC/ND ajusta una venta que queda fuera del período filtrado y la propia nota también queda fuera? No debe aparecer en el ranking del período consultado (ninguno de los dos hechos cae en el período).
- ¿Qué pasa con un cliente/producto cuyo neto en el período da exactamente $0 (todas sus ventas fueron neteadas por completo)? Debe seguir apareciendo en el ranking con $0, no desaparecer de la lista (mismo criterio de piso, no de exclusión).
- ¿Qué pasa si una NC/ND ajusta una venta de un cliente o un producto que fue eliminado o dado de baja? Debe seguir sumando/restando igual que hoy lo hace el neteo de KPIs/Totales/Donas (spec 046) — no se introduce un caso nuevo, se reutiliza el comportamiento ya vigente.
- ¿Aplica este neteo también al Ranking de Productos por NC/ND que no detallan qué producto se devuelve (nota global, sin ítems)? Ver FR-006 y supuesto sobre notas sin ítems.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: El Ranking de Clientes del Dashboard (por monto vendido) MUST restar el monto de las Notas de Crédito y sumar el monto de las Notas de Débito asociadas a las ventas de cada cliente, dentro del período filtrado.
- **FR-002**: El Ranking de Productos del Dashboard (por cantidad vendida) MUST restar la cantidad devuelta/ajustada por Notas de Crédito y sumar la cantidad ajustada por Notas de Débito, a nivel de cada ítem de producto, dentro del período filtrado.
- **FR-003**: Toda NC/ND MUST imputarse al período de la fecha de la Venta que ajusta (no a la fecha de emisión propia de la nota), igual que el criterio ya vigente para KPIs/Totales/Donas del Dashboard (spec 046).
- **FR-004**: Cuando la NC/ND cae dentro del mismo período que la Venta que ajusta, el neto resultante para ese cliente/producto en ese período MUST tener piso en $0 / 0 unidades — nunca debe mostrarse un valor negativo en ese caso.
- **FR-005**: Cuando la NC/ND cae en un período distinto al de la Venta que ajusta, el ajuste se aplica crudo (resta/suma) sobre el período de la venta original, sin piso en $0 — el resultado para ese cliente/producto en ese período puede ser negativo.
- **FR-006**: No existe techo superior para el efecto de una ND (Nota de Débito): puede incrementar el monto/cantidad del cliente/producto sin límite superior.
- **FR-007**: El Ranking de Clientes y de Productos del módulo Informes (spec 069) MUST permanecer sin cambios — este neteo aplica exclusivamente a los rankings del Dashboard.
- **FR-008**: Los KPIs, Totales, Gráfico Mensual y Donas del Dashboard (ya neteados por spec 046) MUST seguir comportándose exactamente igual — esta feature no debe alterar ese cálculo existente, sólo extender el mismo criterio a los dos rankings.
- **FR-009**: Un cliente o producto cuyo neto en el período dé exactamente $0 / 0 unidades MUST seguir apareciendo en el ranking (no debe excluirse de la lista por dar cero).

### Key Entities *(include if feature involves data)*

- **Ranking de Clientes (Dashboard)**: lista de clientes ordenada por monto vendido neto en el período filtrado; cada fila expone el monto ya neteado de NC/ND.
- **Ranking de Productos (Dashboard)**: lista de productos ordenada por cantidad vendida neta en el período filtrado; cada fila expone la cantidad ya neteada de NC/ND.
- **Nota de Crédito/Débito**: ajuste sobre una Venta existente, con fecha de emisión propia y una venta de origen cuya fecha determina el período de imputación de este cálculo.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Para cualquier período del Dashboard, el monto total del Ranking de Clientes (sumado entre todos los clientes visibles, sin limitar al Top 10) coincide, centavo a centavo, con el total de Ventas ya neteado que hoy muestran los KPIs del Dashboard para ese mismo período.
- **SC-002**: En 100% de los casos de prueba, el monto/cantidad neto de cada cliente/producto en el ranking coincide con el que resultaría de aplicar el mismo criterio de neteo que usan hoy los KPIs/Totales/Donas del Dashboard (spec 046) a nivel de cliente/producto — incluyendo los casos donde ese neto da negativo, sin recortar a $0.
- **SC-003**: El Ranking de Clientes/Productos del módulo Informes no cambia su resultado antes y después de este cambio, para el mismo período y los mismos datos.

## Assumptions

- Se reutiliza exactamente el mismo criterio de imputación de período que la spec 046 definió para KPIs/Totales/Donas del Dashboard, en su revisión vigente del 18/08/2026: **sin piso en $0** (una NC/ND puede dejar el neto de un cliente/producto en negativo, igual que ya sucede a nivel de total) y sin techo para ND — no se introduce un criterio nuevo de neteo, ver Clarifications.
- "Período" en toda esta spec es el rango de fechas que el usuario tiene seleccionado en el filtro del Dashboard (Última Semana/Hoy/Mes Actual/Mes Anterior/Año Actual), no necesariamente un mes calendario — "mismo período"/"período distinto" se resuelve comparando contra ese rango, igual que ya lo hace `montoNetoQuery()`.
- El Top 10 de cada ranking se recalcula sobre el conjunto ya neteado completo: un cliente/producto puede entrar o salir del Top 10 por efecto del neteo (no se limita a ajustar el monto de los que ya estaban en el Top 10 bruto).
- El Ranking de Productos se calcula a partir de los ítems de la NC/ND (qué producto y qué cantidad ajusta cada línea). Si una NC/ND no detalla ítems (nota global sin desglose de productos), su efecto se refleja igual que hoy sucede en Informes de Compras (spec 067) para casos sin desglose: no se distribuye a un producto puntual, sólo se refleja en el total, y por lo tanto no afecta el Ranking de Productos (que es por producto individual).
- El alcance queda limitado a Ventas/Clientes/Productos: no incluye un eventual Ranking de Proveedores o de Compras, que no existen hoy en el Dashboard.
- No se requiere ajuste de permisos: el Ranking de Clientes y de Productos del Dashboard ya tiene su propia gate de permisos (spec 070) y esta feature no cambia quién puede verlos, sólo cómo se calcula el valor mostrado.
