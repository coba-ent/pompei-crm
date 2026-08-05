# Feature Specification: Neteo de Notas de Crédito/Débito en el Dashboard de Inicio

**Feature Branch**: `046-dashboard-neteo-nc-nd`

**Created**: 2026-08-05

**Status**: Draft

**Input**: User description: "Netear el impacto de Notas de Crédito/Débito en los KPIs y gráficos monetarios del Dashboard de Inicio (spec 010-inicio-dashboard). Hoy 'Ventas Creadas', 'Venta Promedio', 'Resultado', el panel de Totales del Período, el gráfico de Evolución Mensual y las donas de composición por categoría calculan los totales de Ventas y Compras directo desde Venta.total / Compra.total, sin restar el monto de las Notas de Crédito/Débito asociadas — sólo el aging de Cuentas a Cobrar/Pagar ya lo hace. Aplicar el neteo de forma simétrica a Ventas y a Compras. 'Cantidad de Ventas' NO cambia (sigue contando comprobantes emitidos). Además, agregar la opción 'Hoy' al selector de período del Dashboard (hoy sólo tiene Última Semana / Mes Actual / Mes Anterior / Año Actual)."

## Clarifications

### Session 2026-08-05

- Q: Al filtrar el Dashboard por "Hoy", ¿contra qué período anterior se calcula la variación % de los KPIs? → A: Comparar "Hoy" vs "Ayer" (día calendario anterior), mismo patrón que los demás filtros de período.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - KPIs del dashboard reflejan el monto neto real de Ventas (Priority: P1)

Como usuario del CRM, cuando abro la pantalla de Inicio quiero que "Ventas Creadas", "Venta
Promedio" y "Resultado" reflejen lo que efectivamente quedó facturado después de aplicar Notas de
Crédito/Débito, no el monto bruto original de cada venta — de lo contrario el dashboard sobreestima
ingresos que en la práctica fueron anulados o reducidos.

**Why this priority**: Es el caso que motivó la spec — una venta anulada al 100% por NC sigue
apareciendo como "vendida" en el KPI principal y en el Resultado, mostrando una cifra de negocio
que no es real. Es el corazón de la pantalla de aterrizaje del CRM (spec 010), así que un dato
incorrecto ahí es el de mayor visibilidad posible.

**Independent Test**: Crear una venta con total conocido, emitirle una Nota de Crédito (parcial o
total) dentro del período filtrado, y verificar que "Ventas Creadas" y "Resultado" del dashboard
reflejan el monto neto (venta − NC + ND), no el bruto.

**Acceptance Scenarios**:

1. **Given** una venta de $100.000 con una Nota de Crédito de $100.000 (anulación total) emitida en
   el mismo período filtrado, **When** se abre el Dashboard de Inicio, **Then** "Ventas Creadas" no
   suma ese $100.000 (aporta $0 neto) y "Resultado" no lo incluye.
2. **Given** una venta de $100.000 con una Nota de Crédito parcial de $30.000, **When** se abre el
   Dashboard, **Then** "Ventas Creadas" suma $70.000 por esa venta.
3. **Given** una venta de $100.000 con una Nota de Débito de $10.000 (recargo posterior), **When**
   se abre el Dashboard, **Then** "Ventas Creadas" suma $110.000 por esa venta.
4. **Given** una venta emitida en el período filtrado con una NC/ND emitida en un período distinto
   (ej. venta de julio, NC de agosto), **When** se filtra el dashboard por Mes Actual (agosto),
   **Then** el monto de esa NC igual se resta del total de Ventas del período de la NC (agosto), no
   del período de la venta — el neteo se calcula por período de emisión de cada movimiento, igual
   que ya hace el aging de Cuentas a Cobrar/Pagar.
5. **Given** la venta reconstruida manualmente en el VPS (comprobante B 0009-00000001, $307.569,76,
   anulada al 100% por NC en agosto 2026), **When** se abre el Dashboard filtrado por Mes Actual
   (agosto 2026), **Then** "Ventas Creadas" y "Resultado" del período no incluyen ese monto.

---

### User Story 2 - El mismo neteo aplica simétricamente a Compras (Priority: P2)

Como usuario del CRM, quiero que el total de "Compras" del panel de Totales del Período, del
gráfico de Evolución Mensual y de la dona de composición por categoría también resten sus propias
Notas de Crédito/Débito, con la misma lógica que Ventas — para que "Resultado" (Ventas − Compras −
Gastos + Otros Ingresos) sea consistente en ambos lados de la cuenta.

**Why this priority**: Es la contracara simétrica de la Historia 1. Sin este ajuste, "Resultado"
quedaría neteado sólo del lado de Ventas y sobreestimaría egresos del lado de Compras, generando
una asimetría contable dentro del mismo KPI.

**Independent Test**: Crear una compra con total conocido, emitirle una Nota de Crédito/Débito
propia (`notas_credito_debito.compra_id`), y verificar que el total de "Compras" del panel de
Totales y de la dona de Compras por categoría reflejan el monto neto.

**Acceptance Scenarios**:

1. **Given** una compra de $50.000 con una Nota de Crédito de $50.000, **When** se abre el
   Dashboard, **Then** el total de "Compras" del panel de Totales del Período no incluye ese monto.
2. **Given** una compra de $50.000 con una Nota de Débito de $5.000, **When** se abre el Dashboard,
   **Then** el total de "Compras" suma $55.000 por esa compra.

---

### User Story 3 - El gráfico de Evolución Mensual y las donas por categoría también quedan netos (Priority: P2)

Como usuario del CRM, quiero que el gráfico de barras apiladas de los últimos 12 meses y las donas
de composición por categoría (Ventas/Compras) muestren los mismos montos netos que las tarjetas
KPI y el panel de Totales — para no tener dos fuentes de verdad distintas dentro de la misma
pantalla.

**Why this priority**: Evita inconsistencia visual dentro del propio dashboard (un usuario podría
notar que la tarjeta "Ventas Creadas" no coincide con la suma de las barras del gráfico mensual del
mes actual, lo cual mina la confianza en los datos).

**Independent Test**: Con la misma venta con NC total de la Historia 1, verificar que la barra de
"Ventas" del mes correspondiente en el gráfico de Evolución Mensual, y la porción de la dona de
Ventas por categoría, tampoco incluyen ese monto.

**Acceptance Scenarios**:

1. **Given** una venta con NC total emitida en agosto 2026, **When** se visualiza el gráfico de
   Evolución Mensual, **Then** la barra de "Ventas" de agosto 2026 no incluye el monto de esa venta.
2. **Given** la misma venta, **When** se visualiza la dona de composición de Ventas por categoría
   (filtrada al período que incluye agosto 2026), **Then** la porción correspondiente a la
   categoría de esa venta no incluye su monto.

---

### User Story 4 - Filtrar el Dashboard por "Hoy" (Priority: P3)

Como usuario del CRM, quiero poder filtrar el Dashboard de Inicio por el día de hoy (no sólo por
Última Semana, Mes Actual, Mes Anterior o Año Actual), para chequear el movimiento del día sin
esperar al cierre de la semana o del mes.

**Why this priority**: Es una mejora de usabilidad del selector de período existente, independiente
del neteo de NC/ND — se agrupa en esta spec porque el usuario la pidió en el mismo pedido, pero no
depende de las Historias 1-3 ni ellas dependen de esta.

**Independent Test**: Abrir el Dashboard, seleccionar "Hoy" en el selector de período, y verificar
que KPIs, panel de Totales y donas recalculan usando únicamente operaciones con fecha de hoy.

**Acceptance Scenarios**:

1. **Given** el Dashboard de Inicio, **When** el usuario abre el selector de período, **Then** ve
   una opción "Hoy" además de las cuatro existentes (Última Semana, Mes Actual, Mes Anterior, Año
   Actual).
2. **Given** existen ventas/compras/gastos con fecha de hoy y con otras fechas, **When** se
   selecciona "Hoy", **Then** los KPIs, el panel de Totales del Período y las donas recalculan
   usando sólo operaciones cuya fecha es la fecha actual — igual que ya hacen los demás filtros de
   período (FR-008 de la spec 010).
3. **Given** el filtro "Hoy" está seleccionado, **When** se observa el gráfico de Evolución Mensual
   y el aging de Cuentas a Cobrar/Pagar, **Then** ninguno de los dos cambia — ambos ya son
   independientes del selector de período (comportamiento heredado de la spec 010, FR-008).
4. **Given** el filtro "Hoy" está seleccionado, **When** se calcula la variación % de cada KPI,
   **Then** se compara contra "Ayer" (el día calendario inmediatamente anterior), mostrando `null`
   ("sin datos previos") si ayer no tuvo operaciones — mismo criterio que ya usan los demás filtros
   de período (spec 010, FR-001).

---

### Edge Cases

- ¿Qué pasa si una venta tiene NC por un monto mayor a su propio total (caso anómalo de datos)? El
  neto no debe bajar de $0 para esa venta en los KPIs — se recorta en $0, no se refleja como
  negativo (evita que una venta "reste" en vez de sólo no sumar).
- ¿Qué pasa con "Cantidad de Ventas"? No cambia: sigue contando comprobantes de venta emitidos en
  el período, independientemente de si después se les emitió una NC — una venta anulada por NC
  sigue siendo un comprobante que existió y fue enviado a ARCA.
- ¿Qué pasa con el Ranking de Clientes (top por monto vendido) y el Ranking de Productos (top por
  cantidad vendida)? Quedan **fuera de alcance** de esta spec — no se especificó su neteo y no hay
  reporte del caso real que los involucre; se documenta como brecha pendiente en
  `docs/documentacion_principal_crm.md`.
- ¿Qué pasa con el aging de Cuentas a Cobrar/Pagar? No se toca — ya neteaba NC/ND correctamente
  desde la spec 010 original.
- ¿Qué pasa si no hay ninguna NC/ND en el período? El comportamiento debe ser idéntico al actual
  (los totales netos coinciden con los brutos cuando no hay notas).
- ¿Qué pasa si una Venta/Compra tiene NC/ND emitidas en más de dos períodos distintos entre sí
  (ej. venta de junio, NC de julio, ND de agosto)? Cada nota se resta/suma únicamente al período de
  su propia `fecha_emision`, de forma independiente — no hay límite a la cantidad de períodos
  involucrados; el mismo criterio de "por período de emisión de la nota" (ver primer edge case)
  aplica sin importar cuántos períodos distintos toque una misma Venta/Compra a lo largo del tiempo.
- ¿Qué pasa con Notas de Crédito/Débito eliminadas (soft delete)? No participan del neteo — se
  excluyen igual que ya excluye el aging de Cuentas a Cobrar/Pagar existente.
- ¿Qué pasa si la categoría de la Venta/Compra original fue eliminada o cambiada después de emitida
  la NC/ND? La nota hereda la categoría **vigente** de la Venta/Compra al momento de calcular la
  dona (no la que tenía al momento de emitirse la nota) — si esa categoría está inactiva o ausente,
  se agrupa bajo "Sin categoría", igual que ya hace el resto del dashboard (spec 010).
- ¿Hay un techo (tope superior) simétrico al piso de $0, para cuando una Nota de Débito hace que el
  monto neto de una Venta/Compra supere ampliamente su total original? No — a diferencia del piso
  de $0 (que evita que una venta "reste" del total), no hay ningún techo: un recargo grande vía ND
  se refleja en su totalidad, es un caso de negocio legítimo (ajuste posterior), no un dato anómalo.
- ¿Qué pasa si se filtra por "Hoy" y no existe ningún dato de "Ayer" (sistema recién puesto en
  marcha)? La variación % muestra `null` ("sin datos previos"), mismo comportamiento que ya define
  spec 010 FR-001 para cualquier período sin dato anterior — no es un caso especial de "Hoy".
- ¿Qué pasa si una NC/ND fue cargada con `fecha_emision` futura respecto de hoy (dato anómalo, ya
  que el campo no tiene restricción de rango a nivel de base)? Se trata igual que cualquier otra
  fecha: participa del neteo del período al que corresponda esa fecha cuando ese período se
  visualice — no se agrega validación ni caso especial en esta spec (la integridad de
  `fecha_emision` es responsabilidad del flujo de creación de NC/ND, spec 045, no de este cálculo
  de reporting).

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: El sistema DEBE calcular "Ventas Creadas" (KPI) como la suma de `Venta.total` del
  período filtrado, menos el monto de Notas de Crédito de tipo `credito` y más el de tipo `debito`
  asociadas a esas ventas, ambas contabilizadas por su propia fecha de emisión dentro del mismo
  período filtrado (no por la fecha de la venta que ajustan).
- **FR-002**: El sistema DEBE calcular el total de "Compras" (panel de Totales del Período) con el
  mismo criterio de neteo que FR-001, aplicado sobre `Compra.total` y las Notas de Crédito/Débito
  asociadas vía `notas_credito_debito.compra_id`.
- **FR-003**: "Venta Promedio" y "Resultado" DEBEN derivarse del monto neto de Ventas (FR-001) y,
  para Resultado, también del monto neto de Compras (FR-002).
- **FR-004**: "Cantidad de Ventas" NO DEBE verse afectada por este cambio — sigue contando la
  cantidad de comprobantes de Venta emitidos en el período, neteo de NC/ND aparte.
- **FR-005**: El gráfico de Evolución Mensual (últimos 12 meses) DEBE mostrar, para cada mes y cada
  rubro (Ventas, Compras), el monto neto de NC/ND de ese mes, con el mismo criterio de FR-001/FR-002
  aplicado mes a mes.
- **FR-006**: Las donas de composición por categoría de Ventas y de Compras DEBEN mostrar montos
  netos de NC/ND por categoría, dentro del período filtrado. Una Nota de Crédito/Débito no tiene
  categoría propia — hereda la categoría **vigente** de la Venta/Compra que ajusta (no la que tenía
  al momento de emitirse la nota); si esa categoría fue eliminada, está inactiva, o la Venta/Compra
  nunca tuvo categoría asignada, se agrupa bajo "Sin categoría", igual que el resto del dashboard.
- **FR-007**: El monto neto de una Venta o Compra individual DEBE calcularse considerando
  únicamente las Notas de Crédito/Débito cuya `fecha_emision` cae dentro del **mismo período que se
  está evaluando** (ver Key Entities), y NUNCA DEBE ser negativo dentro de ese cálculo — se recorta
  en $0 como piso cuando las Notas de Crédito de ese período superan el total original de la
  Venta/Compra. Notas cuya `fecha_emision` cae en un período distinto al de la Venta/Compra
  original se contabilizan aparte, en el período que les corresponde por su propia fecha (FR-001),
  sin este piso — porque en ese período no existe un total "base" de esa Venta/Compra contra el
  cual acotar (ver Acceptance Scenario 4 de la Historia 1). No existe techo simétrico: una Nota de
  Débito puede hacer que el monto neto supere ampliamente el total original sin límite superior.
- **FR-008**: El aging de Cuentas a Cobrar/Pagar y el Ranking de Clientes/Productos NO DEBEN
  modificarse por esta spec — quedan fuera de alcance (el primero ya estaba neteado; los segundos
  se documentan como brecha pendiente, no se resuelven acá).
- **FR-009**: El sistema DEBE producir resultados idénticos a los actuales cuando no existen Notas
  de Crédito/Débito en el rango de datos evaluado (no debe alterar el comportamiento del caso sin
  notas).
- **FR-010**: El sistema DEBE agregar la opción "Hoy" al selector de período del Dashboard (junto a
  Última Semana, Mes Actual, Mes Anterior, Año Actual), que filtra KPIs, panel de Totales del
  Período y donas por categoría a operaciones con fecha igual a la fecha actual — mismo mecanismo
  ya usado por los demás filtros de período (spec 010, FR-008).
- **FR-011**: Al seleccionar "Hoy", el gráfico de Evolución Mensual y el aging de Cuentas a
  Cobrar/Pagar NO DEBEN recalcularse — mantienen el comportamiento ya definido en spec 010 (FR-008)
  de ser independientes del selector de período.
- **FR-012**: Cuando el período filtrado es "Hoy", la variación % de cada KPI DEBE calcularse
  contra "Ayer" (el día calendario inmediatamente anterior) como período anterior equivalente,
  mostrando `null` ("sin datos previos") si ayer no tuvo operaciones — mismo criterio que el resto
  de los filtros de período (spec 010, FR-001).

### Key Entities *(include if feature involves data)*

- **Venta** (existente): además de `total`, ya expone `totalCredito()`/`totalDebito()` (suma de sus
  Notas de Crédito/Débito asociadas) — se reutiliza ese mismo criterio de neteo, agregado por período
  de emisión de cada nota, para los cálculos de dashboard.
- **Compra** (existente): análogo a Venta, ya expone `totalCredito()`/`totalDebito()`.
- **NotaCreditoDebito** (existente): `tipo` (`credito`/`debito`), `monto`, `fecha_emision`,
  `venta_id`/`compra_id` — es la entidad cuyo monto se resta/suma en los cálculos de esta spec,
  agrupada por su propia `fecha_emision` (no la de la Venta/Compra original).

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Al filtrar el Dashboard por un período que contiene una venta 100% anulada por NC, el
  KPI "Ventas Creadas" y "Resultado" excluyen esa venta del monto mostrado (aporte neto $0), sin
  requerir ninguna acción manual del usuario.
- **SC-002**: El total de "Ventas" mostrado en el panel de Totales del Período, en la barra del mes
  correspondiente del gráfico de Evolución Mensual, y en la dona de Ventas por categoría, coinciden
  entre sí para el mismo período, con una tolerancia de redondeo de hasta $0,01 (dos decimales,
  mismo criterio de redondeo monetario ya usado en el resto del dashboard) — sin discrepancias por
  encima de ese margen.
- **SC-003**: El mismo criterio de consistencia y tolerancia de SC-002 se cumple del lado de
  Compras.
- **SC-004**: "Cantidad de Ventas" no varía respecto del comportamiento actual al introducir este
  cambio (regresión cero sobre ese KPI puntual).
- **SC-005**: En un dashboard sin ninguna Nota de Crédito/Débito cargada, todos los valores
  mostrados son idénticos a los que mostraba el sistema antes de este cambio.
- **SC-006**: Un usuario puede filtrar el Dashboard al día de hoy y ver únicamente el movimiento
  del día en KPIs, panel de Totales y donas, sin necesidad de esperar al cierre semanal o mensual.

## Assumptions

- Las Notas de Crédito/Débito de Ventas y de Compras ya existen como entidad (`NotaCreditoDebito`,
  spec 039/042/045) con `tipo`, `monto` y `fecha_emision` — esta spec no crea ni modifica esa
  entidad, sólo cambia cómo el Dashboard agrega esos montos.
- El criterio de "por período de emisión de la nota, no de la venta/compra original" es **distinto
  por diseño** del que usa el aging de Cuentas a Cobrar/Pagar (spec 010, FR-006): el aging calcula
  un saldo **acumulado a la fecha de hoy** (no acotado a un rango), mientras que los KPIs/gráficos
  de esta spec necesitan acotar cada monto a un período específico para poder filtrarlo y compararlo
  mes a mes. No es una inconsistencia — son dos preguntas de negocio distintas ("¿cuánto me deben
  hoy?" vs. "¿cuánto vendí neto en este período?") que legítimamente usan ventanas de tiempo
  distintas sobre las mismas Notas de Crédito/Débito.
- Ranking de Clientes/Productos y aging de Cta Cte quedan fuera de alcance por decisión explícita
  del usuario al momento de especificar esta feature (ver Edge Cases) — se documentan como brecha
  pendiente, no como "no aplica".
- El piso de $0 (FR-007) aplica por Venta/Compra individual antes de agregar al total del período,
  no al total agregado del período completo.
