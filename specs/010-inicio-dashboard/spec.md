# Feature Specification: Módulo Inicio (Dashboard)

**Feature Branch**: `010-inicio-dashboard`

**Created**: 2026-07-25

**Status**: Draft

**Input**: User description: "Módulo Inicio / Dashboard — pantalla de aterrizaje al iniciar sesión (ruta /dashboard/index), según el relevamiento con capturas reales en docs/informe_contagram_inicio_informes_ajustes.md §1 (capturas 163-164): KPIs superiores (Ventas Creadas, Venta Promedio, Cantidad de Ventas, Resultado con variación % vs mes anterior), panel de totales apilados con barra de progreso (Total Ventas, Total Otros Ingresos, Total Compras, Total Gastos) + gráfico de barras apiladas mensual (últimos ~12 meses), panel de Tesorería resumido (Total Disponible, Total Cajas, Total Bancos + mini-tabla de movimientos recientes), y cuentas a cobrar y a pagar. Excluye explícitamente funcionalidades de IA. Debe basarse en los datos reales ya existentes de los módulos ya construidos: Ventas/Presupuestos/Otros Ingresos/Abonos (spec 008), Compras/Gastos (spec 009), Tesorería (spec 007)."

## Contexto y fuentes

Este módulo es la **pantalla de aterrizaje** del CRM (ruta `/dashboard/index`): un tablero que agrega
en un solo lugar el estado del negocio consolidando datos de los módulos ya construidos (Ventas,
Presupuestos, Otros Ingresos, Compras, Gastos, Tesorería). No introduce entidades de negocio nuevas —
es una capa de lectura/agregación sobre datos existentes.

**Fuente de verdad estructural**: `docs/informe_contagram_inicio_informes_ajustes.md` §1 (relevamiento
con capturas reales 163-166). **Fuente de dominio**: `docs/documentacion_principal_crm.md` y
`docs/modelo_datos.md`.

**Alcance de esta spec**: sólo la pantalla Inicio/Dashboard y el cálculo mínimo de **saldo y
antigüedad de deuda (aging) de Cuenta Corriente** necesario para alimentar el panel "Cuentas a Cobrar
y a Pagar" (§1.4 del informe). No incluye las pantallas propias de Cuenta Corriente del módulo Informes
(vista Saldos/Movimientos/ficha por cliente o proveedor, informe §2.3-2.4) — esas quedan fuera de
alcance y se abordan en una spec de Informes posterior, reutilizando el mismo servicio de cálculo que
esta spec deja creado.

## Clarificaciones incorporadas (decididas con el usuario)

- **Cuenta Corriente**: `docs/modelo_datos.md` marca la Cuenta Corriente como "no implementada". No
  depende de ninguna credencial externa (Mercado Libre, TiendaNube, ARCA) y sí tiene relevamiento con
  capturas reales (informe §2.3-2.4, aunque para el módulo Informes). Por decisión explícita del
  usuario, se incluye en esta spec el cálculo de saldo y aging (A Vencer, Vencido, 0-30, 31-60, 61-90,
  +90 días) por Cliente y por Proveedor, como servicio de dominio reutilizable — pero sólo lo necesario
  para alimentar el panel del dashboard, no las pantallas completas de Cuenta Corriente.
- **Integraciones externas excluidas**: Rankings de "Contagram 2.0 BETA", banner de estado de cuenta de
  prueba (§1.8) y cualquier elemento ligado a la infraestructura SaaS multi-tenant de Contagram
  (trial, checkout de suscripción) **no aplican** — este CRM es single-tenant, sin ciclo de
  suscripción. Excluidos de esta spec.
- **Funcionalidades de IA excluidas**: por instrucción explícita del usuario (heredada del informe
  fuente), ningún elemento de IA (ej. sugerencias, análisis automático) forma parte de esta spec.
- **Rankings embebidos (§1.7)**: se implementan como consultas directas sobre Ventas/Productos ya
  construidos (top clientes por monto vendido, top productos más vendidos) — no requieren que el
  módulo Informes completo esté construido.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Ver el estado general del negocio al iniciar sesión (Priority: P1)

Como usuario del CRM, al iniciar sesión quiero ver de un vistazo los indicadores clave del negocio
(ventas, resultado, totales por tipo de operación) sin tener que navegar a varios módulos distintos.

**Why this priority**: Es la pantalla de aterrizaje — sin esto no hay "Inicio", sólo un redirect a
otro módulo. Es el valor mínimo del feature.

**Independent Test**: Con datos de Ventas, Otros Ingresos, Compras y Gastos ya cargados (de specs
anteriores), iniciar sesión y verificar que las 4 tarjetas KPI y el panel de totales muestran cifras
correctas para el período por defecto (mes actual).

**Acceptance Scenarios**:

1. **Given** existen ventas, compras y gastos registrados en el mes actual, **When** el usuario inicia
   sesión, **Then** ve 4 tarjetas KPI (Ventas Creadas, Venta Promedio, Cantidad de Ventas, Resultado)
   con el monto/cantidad del mes actual y la variación porcentual vs. el mes anterior (flecha verde si
   sube, roja si baja).
2. **Given** el mes anterior no tuvo ventas (división por cero), **When** se calcula la variación
   porcentual, **Then** el sistema muestra la variación como "sin datos previos" en vez de un error o
   un `NaN`/`Infinity`.
3. **Given** existen registros de Ventas, Otros Ingresos, Compras y Gastos del período, **When** el
   usuario ve el panel de totales, **Then** cada barra de progreso refleja la proporción de cada total
   respecto de la suma de los cuatro, con el monto exacto al lado.

---

### User Story 2 - Consultar el gráfico mensual comparativo (Priority: P2)

Como usuario, quiero ver un gráfico de barras apiladas que compare Ventas, Otros Ingresos, Compras y
Gastos mes a mes durante el último año, para identificar tendencias del negocio.

**Why this priority**: Complementa los KPIs puntuales con una vista histórica; no bloquea el valor de
la User Story 1 pero es la razón principal de tener un "dashboard" y no sólo un resumen del mes.

**Independent Test**: Con movimientos distribuidos en al menos 3 meses distintos, verificar que el
gráfico muestra una barra apilada por mes (hasta 12 meses hacia atrás) con los 4 valores correctos.

**Acceptance Scenarios**:

1. **Given** hay operaciones registradas en los últimos 12 meses, **When** se carga el dashboard,
   **Then** el gráfico muestra una barra por mes con los 4 segmentos (Ventas/Otros Ingresos/Compras/
   Gastos) apilados y una leyenda de colores.
2. **Given** un mes sin ninguna operación dentro del rango de 12 meses, **When** se renderiza el
   gráfico, **Then** ese mes se muestra con barra en cero (no se omite del eje).

---

### User Story 3 - Consultar el resumen de Tesorería y movimientos recientes (Priority: P2)

Como usuario, quiero ver en el dashboard el estado consolidado de mis cuentas de tesorería (disponible,
cajas, bancos) y los últimos movimientos, sin entrar al módulo Tesorería.

**Why this priority**: Reutiliza directamente el servicio de dominio de Tesorería (spec 007) ya
construido; da visibilidad inmediata de liquidez, uno de los datos más consultados a diario.

**Independent Test**: Con cuentas de Tesorería y movimientos ya cargados (spec 007), verificar que el
panel muestra Total Disponible, Total Cajas, Total Bancos y una mini-tabla con los últimos movimientos
(Fecha / Cuenta / Monto con signo).

**Acceptance Scenarios**:

1. **Given** existen cuentas de tipo Caja y Banco con saldo, **When** se carga el dashboard, **Then**
   el panel de Tesorería muestra Total Disponible (suma de Cajas + Bancos), Total Cajas y Total Bancos
   por separado.
2. **Given** existen movimientos de tesorería recientes, **When** se carga el panel, **Then** la
   mini-tabla muestra los últimos movimientos ordenados por fecha descendente, con signo + (ingreso) o
   − (egreso) según corresponda.

---

### User Story 4 - Consultar Cuentas a Cobrar y a Pagar con antigüedad de deuda (Priority: P2)

Como usuario, quiero ver el total de Ventas a Cobrar y de Compras a Pagar, desglosado por antigüedad
de deuda (a vencer, vencido, y rangos de días), para priorizar gestión de cobranzas y pagos.

**Why this priority**: Es información de gestión de caja tan relevante como los KPIs de venta, pero
depende de un cálculo de Cuenta Corriente que no existía antes de esta spec — se prioriza P2 porque el
dashboard sigue siendo útil sin este panel (degradación aceptable) mientras se termina de construir.

**Independent Test**: Con ventas con saldo pendiente de cobro (algunas vencidas, otras no) y compras
con saldo pendiente de pago, verificar que el panel agrupa correctamente los montos en los buckets de
antigüedad (A Vencer, Vencido, 0-30, 31-60, 61-90, +90 días).

**Acceptance Scenarios**:

1. **Given** una venta con saldo pendiente y fecha de vencimiento futura, **When** se calcula el aging,
   **Then** su saldo se incluye en el bucket "A Vencer".
2. **Given** una venta con saldo pendiente y fecha de vencimiento pasada hace 45 días, **When** se
   calcula el aging, **Then** su saldo se incluye en el bucket "31 a 60" y también suma al total
   "Vencido".
3. **Given** una compra totalmente cancelada (saldo cero), **When** se calcula el aging de Compras a
   Pagar, **Then** esa compra no aporta monto a ningún bucket.

---

### User Story 5 - Filtrar el dashboard por período y ver composición por categoría (Priority: P3)

Como usuario, quiero cambiar el rango de fechas del dashboard (Última Semana / Mes Actual / Mes
Anterior / Año Actual) y ver la composición porcentual de Ventas, Compras y Gastos por categoría en
gráficos de dona, para analizar el negocio con más detalle sin salir del Inicio.

**Why this priority**: Es una mejora de análisis sobre lo que ya se ve por defecto (mes actual); no
es indispensable para que el dashboard cumpla su función principal de resumen.

**Independent Test**: Cambiar el selector de período a "Año Actual" y verificar que los KPIs, el
gráfico mensual y los totales se recalculan para ese rango; verificar que las 3 donas muestran
porcentaje por categoría sumando 100%.

**Acceptance Scenarios**:

1. **Given** el usuario está en el dashboard con el período "Mes Actual" por defecto, **When** elige
   la pestaña "Año Actual", **Then** los KPIs superiores, el panel de totales y el gráfico mensual se
   recalculan para el año en curso.
2. **Given** existen ventas en 3 categorías distintas dentro del período, **When** se renderiza la
   dona de Ventas por categoría, **Then** cada porción muestra el nombre de categoría y el porcentaje
   correspondiente, y la suma de porcentajes es 100%.
3. **Given** una categoría fue eliminada pero tiene ventas históricas asociadas, **When** se calcula la
   dona, **Then** esas ventas se agrupan bajo una categoría "Sin categoría" en vez de romper el cálculo.

---

### User Story 6 - Ver rankings rápidos de Clientes y Productos (Priority: P3)

Como usuario, quiero ver en el dashboard un ranking rápido de los clientes que más compraron y los
productos más vendidos en el período, sin entrar al módulo Informes.

**Why this priority**: Es información de valor agregado, no crítica para el propósito principal del
dashboard (resumen financiero).

**Independent Test**: Con ventas de al menos 3 clientes y 3 productos distintos, verificar que ambos
rankings muestran el top ordenado de mayor a menor por monto/cantidad vendida.

**Acceptance Scenarios**:

1. **Given** existen ventas de múltiples clientes en el período, **When** se renderiza el Ranking de
   Clientes, **Then** se listan los clientes ordenados de mayor a menor monto vendido, con el monto
   visible.
2. **Given** existen ventas de múltiples productos en el período, **When** se renderiza el Ranking de
   Productos, **Then** se listan los productos ordenados de mayor a menor cantidad vendida.

---

### Edge Cases

- ¿Qué pasa si el usuario recién configuró el sistema y no hay ninguna operación registrada todavía?
  Todos los KPIs, totales y gráficos deben mostrar estado vacío (ceros / "sin datos"), no error.
- ¿Cómo se comporta el dashboard si sólo hay datos de Tesorería pero ninguna Venta/Compra/Gasto
  cargada? El panel de Tesorería debe mostrar datos reales; el resto, estado vacío.
- ¿Qué pasa si una venta tiene Notas de Crédito/Débito que afectan su saldo pendiente? El cálculo de
  aging de Cuentas a Cobrar debe usar el saldo neto (venta − NC + ND), consistente con el saldo que ya
  calcula Tesorería/Cobranzas (spec 008).
- ¿Qué pasa con Otros Ingresos o Gastos marcados "como pendiente" (no cobrados/pagados)? No participan
  del aging de Cuenta Corriente (ese cálculo es sólo Ventas/Compras con Cliente/Proveedor); si están
  pendientes de cobro/pago, sólo se reflejan en los totales del panel 1.2, no en el panel 1.4.
- ¿Qué pasa si el usuario no tiene permisos para ver Tesorería o Compras? (dependiente del futuro
  módulo de Roles y Permisos, spec 013) — fuera de alcance de esta spec; por ahora el dashboard asume
  un único usuario con acceso total, consistente con el resto del CRM construido hasta ahora.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: El sistema DEBE mostrar, al iniciar sesión, una pantalla de Inicio en la ruta del
  dashboard con 4 tarjetas KPI: Ventas Creadas, Venta Promedio, Cantidad de Ventas y Resultado
  (Ventas + Otros Ingresos − Compras − Gastos), cada una con variación porcentual vs. el período
  anterior equivalente.
- **FR-002**: El sistema DEBE mostrar un panel de totales apilados (Total Ventas, Total Otros
  Ingresos, Total Compras, Total Gastos) con barra de progreso proporcional al peso de cada total
  sobre la suma de los cuatro.
- **FR-003**: El sistema DEBE mostrar un gráfico de barras apiladas mensual comparando Ventas, Otros
  Ingresos, Compras y Gastos de los últimos 12 meses (incluyendo meses sin operaciones, en cero).
- **FR-004**: El sistema DEBE mostrar un panel de Tesorería resumido con Total Disponible, Total
  Cajas y Total Bancos, calculados con el mismo servicio de saldos que usa el módulo Tesorería
  (spec 007).
- **FR-005**: El sistema DEBE mostrar una mini-tabla con los últimos movimientos de Tesorería (Fecha,
  Cuenta, Monto con signo +/−), limitada a un top 10 fijo (ver Assumptions).
- **FR-006**: El sistema DEBE calcular el saldo pendiente y la antigüedad de deuda (aging: A Vencer,
  Vencido, 0-30, 31-60, 61-90, +90 días) de Ventas con saldo pendiente agrupadas por Cliente (Total
  Ventas a Cobrar), y de Compras con saldo pendiente agrupadas por Proveedor (Total Compras a Pagar),
  como servicio de dominio reutilizable.
- **FR-007**: El sistema DEBE mostrar el resultado de FR-006 en dos bloques del dashboard (Cuentas a
  Cobrar / Cuentas a Pagar) con el monto total destacado y el desglose por bucket de antigüedad.
- **FR-008**: El sistema DEBE permitir filtrar el dashboard por período (Última Semana, Mes Actual,
  Mes Anterior, Año Actual), recalculando KPIs, panel de totales y donas según el rango elegido. El
  gráfico mensual (FR-003) NO se filtra por período — siempre muestra el histórico fijo de los
  últimos 12 meses, independiente del rango elegido (es una vista de tendencia anual, acortarla a
  "última semana" no tendría sentido). El aging de Cuentas a Cobrar/Pagar (FR-006/FR-007) tampoco se
  filtra por período — refleja siempre el saldo pendiente a la fecha actual, igual que un aging
  report estándar.
- **FR-009**: El sistema DEBE mostrar 3 gráficos de dona con la composición porcentual de Ventas,
  Compras y Gastos por categoría dentro del período filtrado, agrupando bajo "Sin categoría" las
  operaciones cuya categoría fue eliminada o no tiene categoría asignada.
- **FR-010**: El sistema DEBE mostrar un Ranking de Clientes (top por monto vendido) y un Ranking de
  Productos (top por cantidad vendida) dentro del período filtrado, calculados directamente sobre
  Ventas ya registradas.
- **FR-011**: El sistema NO DEBE incluir funcionalidades de Inteligencia Artificial, banner de estado
  de cuenta de prueba, ni ningún elemento ligado al ciclo de suscripción SaaS multi-tenant de
  Contagram — el CRM es single-tenant sin esos conceptos.
- **FR-012**: Cuando no existan operaciones registradas para el período o cálculo correspondiente, el
  sistema DEBE mostrar un estado vacío (cifras en cero / mensaje "sin datos"), nunca un error.

### Key Entities *(include if feature involves data)*

- **Dashboard (agregación, sin tabla propia)**: no persiste datos nuevos; lee y agrega Ventas,
  Presupuestos, Otros Ingresos, Compras, Gastos y Movimientos de Tesorería ya modelados en specs
  anteriores.
- **Saldo/Aging de Cuenta Corriente (servicio de dominio, sin tabla propia en esta spec)**: cálculo
  derivado sobre Ventas y Compras existentes (monto total − cobros/pagos aplicados − impacto de NC/ND)
  agrupado por Cliente o Proveedor y clasificado en buckets de antigüedad respecto de la fecha de
  vencimiento del comprobante. Reutilizable por una futura spec de Informes (Cuenta Corriente
  Clientes/Proveedores).

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Un usuario puede ver el estado financiero consolidado del mes actual (ventas, resultado,
  totales por tipo) en la pantalla de Inicio sin navegar a ningún otro módulo.
- **SC-002**: Cambiar el período del dashboard (Última Semana / Mes Actual / Mes Anterior / Año Actual)
  actualiza todos los indicadores dependientes de período en menos de 2 segundos percibidos.
- **SC-003**: El total mostrado en Cuentas a Cobrar y en Cuentas a Pagar coincide exactamente con la
  suma de saldos pendientes reales de Ventas y Compras respectivamente, verificable contra los datos
  de esos módulos.
- **SC-004**: El 100% de los meses del gráfico de 12 meses se muestran (ninguno se omite), incluso si
  no tuvieron operaciones.
- **SC-005**: Ante ausencia total de datos (instalación nueva), el dashboard se renderiza completo con
  estados vacíos, sin errores ni pantallas en blanco.

## Assumptions

- El dashboard es de **sólo lectura**: no crea, edita ni elimina ninguna operación; toda alta/edición
  sigue haciéndose desde su módulo de origen (Ventas, Compras, Gastos, Tesorería).
- El período por defecto al iniciar sesión es **Mes Actual**, consistente con el informe fuente (§1.5).
- "Resultado" se calcula como Ventas + Otros Ingresos − Compras − Gastos, sin incluir todavía
  Facturación Electrónica ni retenciones fiscales (no construidas), consistente con cómo esos módulos
  ya excluyen ese cálculo hoy.
- El aging de Cuenta Corriente (FR-006/007) usa la fecha de vencimiento que ya guardan Ventas/Compras
  (o la fecha de emisión si no hay vencimiento explícito), sin requerir un módulo de Cuenta Corriente
  completo con pantallas propias — esas pantallas (Informes → Cuenta Corriente Clientes/Proveedores)
  quedan fuera de esta spec y se abordan después, reutilizando este mismo servicio de cálculo.
- No hay todavía sistema de Roles y Permisos (spec 013 pendiente), por lo que el dashboard no aplica
  restricciones de visibilidad por rol.
- Se excluyen de esta spec (por decisión del usuario y por falta de aplicabilidad al modelo
  single-tenant): banner de trial/suscripción, "Contagram 2.0 BETA", y cualquier funcionalidad de IA.
- La mini-tabla de movimientos recientes de Tesorería (FR-005) y los Rankings de Clientes/Productos
  (FR-010) muestran un **top 10** fijo — no es un límite configurable en esta spec.

## Dependencias y relación con otros módulos

- **Depende de** (ya construidos): Ventas/Presupuestos/Otros Ingresos/Abonos (spec 008), Compras/
  Gastos (spec 009), Tesorería (spec 007) — el dashboard sólo lee y agrega datos de estos módulos.
- **Introduce** un servicio de dominio de Saldo/Aging de Cuenta Corriente, pensado para ser reutilizado
  por una futura spec de Informes (Cuenta Corriente Clientes/Proveedores, informe §2.3-2.4) sin
  necesidad de recalcular la lógica.
- **No depende de** Facturación Electrónica (ARCA), MercadoLibre ni TiendaNube — ninguno de los datos
  del dashboard requiere esas integraciones externas.
