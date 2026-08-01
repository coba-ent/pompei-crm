# Feature Specification: Cuenta Corriente Clientes

**Feature Branch**: `029-cuenta-corriente-clientes`

**Created**: 2026-07-31

**Status**: Draft

**Input**: User description: "Implementar la pantalla de Cuenta Corriente de Clientes (`/cuentas-corrientes/clientes` o similar), fiel a las capturas reales de Contagram (docs/capturas/saldos/WhatsApp Image 2026-07-30 at 7.21.55 PM (1)/(2).jpeg): dos tabs, 'Saldos Clientes' (tabla con aging por antigüedad: A Vencer, Vencido 0-30/31-60/61-90/>90, Total, filtro por Cliente) y 'Movimientos' (detalle de ventas/cobros/notas por cliente: Id, Emisión, Cliente, Operación, Categoría, Total Venta, Cobrado, A Cobrar, N° de Comprobante, Medio de Cobro, Descripción, filtro por Cliente y Operación). Alcance acotado a Clientes — Proveedores queda documentado como pendiente (sin capturas propias). Cuenta Corriente de Proveedores NO se construye en este spec."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Ver de un vistazo qué clientes deben plata y hace cuánto (Priority: P1)

El negocio necesita saber, para cada cliente, cuánto le debe y desde cuándo (para priorizar reclamos de cobro y detectar deuda vieja). Hoy esa información sólo existe agregada (el total "A Cobrar" en Tesorería) o dispersa por cada Venta individual; no hay una vista por cliente con antigüedad de deuda.

**Why this priority**: Es el caso de uso central del módulo — sin esto no hay Cuenta Corriente, sólo un listado de ventas.

**Independent Test**: Ir a Base de Datos (o Informes) → Cuenta Corriente → tab "Saldos Clientes", ver la tabla con un cliente con deuda vencida hace más de 90 días mostrando ese monto en la columna ">90", y un cliente sin deuda vencida sólo con monto en "A Vencer" o sin fila (si su saldo es 0).

**Acceptance Scenarios**:

1. **Given** un cliente con una Venta impaga cuyo vencimiento de cobro ya pasó hace 45 días, **When** el usuario abre "Saldos Clientes", **Then** ve una fila para ese cliente con el saldo pendiente en la columna "31 y 60" (bucket correspondiente a 45 días de vencido) y en "Total".
2. **Given** un cliente con una Venta cuyo vencimiento de cobro todavía no llegó, **When** el usuario abre "Saldos Clientes", **Then** ve el saldo pendiente en la columna "A Vencer", no en ninguna columna de "Vencido".
3. **Given** un cliente sin ningún saldo pendiente (todo cobrado), **When** el usuario abre "Saldos Clientes" sin filtrar, **Then** ese cliente no aparece en la lista (o aparece con Total $0, según FR-002).
4. **Given** la tabla de Saldos Clientes con varias filas, **When** el usuario hace click en el encabezado "Total", **Then** la tabla se reordena por ese monto (columna ordenable, tal como muestra la captura real).
5. **Given** el filtro "Cliente" vacío ("Todos"), **When** el usuario escribe el nombre de un cliente puntual y busca, **Then** la tabla muestra sólo ese cliente.

---

### User Story 2 - Ver el detalle de movimientos que componen la deuda de un cliente (Priority: P2)

Una vez identificado un cliente con saldo pendiente (User Story 1), el negocio necesita ver el detalle: qué ventas, cobros y notas de crédito/débito componen ese saldo, para poder explicárselo al cliente o reconciliar un reclamo puntual.

**Why this priority**: Es el complemento natural de la vista de saldos — sin el detalle, el saldo agregado no es accionable (no se puede saber "por qué" debe esa plata).

**Independent Test**: Desde el tab "Movimientos", filtrar por un cliente puntual y verificar que aparecen sus Ventas (con Total Venta y A Cobrar) y sus Cobros (con el medio de cobro usado), ordenados por fecha, y que la suma de "A Cobrar" de sus Ventas no canceladas coincide con el Total que ese cliente mostraba en "Saldos Clientes".

**Acceptance Scenarios**:

1. **Given** el tab "Movimientos" sin filtros, **When** el usuario lo abre, **Then** ve un listado de operaciones (Venta, Cobro, Nota de Crédito) de todos los clientes, ordenado por fecha de emisión descendente por defecto.
2. **Given** el filtro "Cliente" con un cliente seleccionado, **When** el usuario busca, **Then** la tabla muestra sólo las operaciones de ese cliente.
3. **Given** el filtro "Operación" con "Cobro" seleccionado, **When** el usuario busca, **Then** la tabla muestra sólo las filas de tipo Cobro (ocultando Ventas y Notas).
4. **Given** una fila de tipo Venta, **When** el usuario la mira, **Then** ve Total Venta, Cobrado (acumulado a la fecha) y A Cobrar (saldo pendiente de esa venta), Categoría y N° de Comprobante.
5. **Given** una fila de tipo Cobro, **When** el usuario la mira, **Then** ve el Medio de Cobro (cuenta de Tesorería usada) y el monto cobrado, sin Total Venta/A Cobrar propios (esos campos quedan vacíos o con guion, como en la captura real).
6. **Given** el selector de rango de fechas ("Emisión"), **When** el usuario lo cambia, **Then** la tabla se filtra por ese rango sobre la fecha de emisión de cada operación.

---

### Edge Cases

- ¿Qué pasa con un cliente que tiene saldo a favor (cobró de más)? → Ver FR-007 (fuera del alcance visual de este spec: las capturas no muestran un caso de saldo negativo/a favor; se documenta como comportamiento no confirmado — ver Assumptions).
- ¿Qué pasa si un cliente no tiene ningún movimiento todavía? → No aparece en "Saldos Clientes" (sin fila) y "Movimientos" filtrado por ese cliente muestra tabla vacía, sin error.
- ¿Qué pasa con las Notas de Crédito/Débito? → Reducen/aumentan el saldo "A Cobrar" de la Venta a la que están asociadas (ya calculado por `Venta::aCobrar()`); en "Movimientos" aparecen como su propia fila de Operación "Nota de Crédito"/"Nota de Débito".
- ¿Qué pasa con Otros Ingresos asociados a un cliente (spec 008, `OtroIngreso` con `cliente_id` opcional)? → No forman parte de la Cuenta Corriente de Ventas/Cobros de este spec (no están documentados como parte de este ciclo en `documentacion_principal_crm.md`); quedan fuera de alcance — ver Assumptions.
- ¿Qué pasa con Compras/Gastos? → No aplica, esta pantalla es sólo Clientes.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: La pantalla de Cuenta Corriente de Clientes DEBE tener dos tabs — "Saldos Clientes" (vista por defecto) y "Movimientos" — replicando la estructura de navegación de las capturas reales.
- **FR-002**: El tab "Saldos Clientes" DEBE mostrar, por cada cliente con saldo pendiente distinto de cero (`Venta::aCobrar()` sumado sobre todas sus Ventas no anuladas), una fila con las columnas: Cliente, A Vencer, Vencido (subdividido en 0 y 30 / 31 y 60 / 61 y 90 / >90), y Total — reutilizando la lógica de bucketing por antigüedad ya existente en `App\Services\Tesoreria\CuentaCorriente::aging()`, extendida para calcular por cliente individual (hoy sólo agrega un total global).
- **FR-003**: La columna "Total" del tab "Saldos Clientes" DEBE ser ordenable (ascendente/descendente al hacer click en el encabezado), igual que en la captura real.
- **FR-004**: El tab "Saldos Clientes" DEBE tener un filtro "Cliente" (buscador tipo Select2, con opción "Todos" por defecto) que acota la tabla a un cliente puntual.
- **FR-005**: El tab "Movimientos" DEBE mostrar un listado combinado de Ventas, Cobros y Notas de Crédito/Débito del cliente (u de todos si no hay filtro), con las columnas: Id, Emisión, Cliente, Operación (Venta/Cobro/Nota de Crédito/Nota de Débito), Categoría, Total Venta, Cobrado, A Cobrar, N° de Comprobante, Medio de Cobro, Descripción — ordenado por Emisión descendente por defecto.
- **FR-006**: El tab "Movimientos" DEBE tener filtros "Cliente" (buscador), "Operación" (Todos/Venta/Cobro/Nota de Crédito/Nota de Débito) y un selector de rango de fechas sobre "Emisión".
- **FR-007**: El sistema NO se pronuncia sobre el tratamiento visual de saldo a favor (negativo) en este spec — se calcula igual que hoy (`aCobrar()` puede dar negativo) pero no hay evidencia en capturas de cómo se distingue visualmente; se muestra el número tal cual (con signo negativo) hasta que se releve ese caso puntual.
- **FR-008**: Ambos tabs son de sólo lectura — no crean, editan ni eliminan Ventas/Cobros/Notas desde esta pantalla (eso ya existe en sus módulos respectivos, Ingresos §3.2).
- **FR-009**: Las tablas de ambos tabs DEBEN seguir la regla de diseño obligatoria del proyecto: DataTables responsive con server-side processing vía AJAX (no listados estáticos Blade).
- **FR-010**: La Cuenta Corriente de Proveedores (menú "Cta Cte" en Compras/Proveedores) queda fuera de alcance de este spec — sigue documentada como pendiente en `documentacion_principal_crm.md` §7 hasta que se releve con capturas propias.

### Key Entities

- **Venta** (reutilizada, sin cambios de esquema): fuente de "A Cobrar" por cliente (ya calculado vía `aCobrar()`) y de las filas tipo "Venta" en Movimientos.
- **Cobro** (reutilizada, sin cambios de esquema): fuente de las filas tipo "Cobro" en Movimientos (con su cuenta de Tesorería = Medio de Cobro).
- **NotaCreditoDebito** (reutilizada, sin cambios de esquema): fuente de las filas tipo "Nota de Crédito"/"Nota de Débito" en Movimientos, y ya afecta el `aCobrar()` de la Venta asociada.
- **Cliente** (reutilizada, sin cambios de esquema): agrupador de "Saldos Clientes".

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Un usuario puede identificar, en menos de 10 segundos desde que abre "Saldos Clientes", qué clientes tienen deuda vencida hace más de 90 días (columna ">90" visible sin scroll horizontal en desktop).
- **SC-002**: El Total de "Saldos Clientes" por cliente coincide exactamente (mismo valor, tolerancia $0,01) con la suma de "A Cobrar" de sus Ventas visibles en "Movimientos" filtrado por ese cliente.
- **SC-003**: La suma de todos los "Total" del tab "Saldos Clientes" coincide, para la misma fecha de corte, con el bloque "Cuentas a Cobrar" del Dashboard (ambos derivados de `Venta::aCobrar()` vía `CuentaCorriente`, misma fuente de cálculo — ver Assumptions). La comparación contra el "Total A Cobrar" de Tesorería (`/tesoreria`, que se calcula por un camino contable independiente, el saldo de una cuenta propia) es sólo un chequeo de reconciliación informativo al validar manualmente (quickstart.md); una diferencia ahí señala un gap de sincronización preexistente entre Tesorería y Ventas/Cobros, no un bug de esta feature.
- **SC-004**: Ambos tabs cargan y filtran sin recargar la página (AJAX/DataTables), consistente con la regla de diseño obligatoria del proyecto.

## Assumptions

- Esta feature reemplaza y formaliza el intento anterior sin terminar (tests/exportador huérfanos de Cuenta Corriente encontrados en el commit inicial del proyecto, con un diseño de "Saldo" plano sin aging) — ese código se descartó por no coincidir con la estructura real relevada en las capturas nuevas; no se reutiliza su contrato de rutas/JSON.
- El aging (buckets de antigüedad) se calcula sobre `fecha_vto_cobro` de la Venta, igual que ya lo hace `CuentaCorriente::aging()` para el agregado global — se asume el mismo criterio también a nivel de cada cliente individual.
- Exportación a CSV/PDF de estas tablas NO está confirmada por las capturas relevadas en este spec (no se vio un botón de exportar en las imágenes disponibles) — queda fuera de alcance; si existe en Contagram real, se agrega en una iteración futura con su propia captura.
- Otros Ingresos con `cliente_id` no forman parte de esta Cuenta Corriente (no hay evidencia de que Contagram los incluya ahí) — sólo Ventas/Cobros/Notas de Crédito-Débito.
- El "Total A Cobrar" que hoy muestra Tesorería (`/tesoreria`) se calcula por un camino distinto (saldo de una cuenta contable propia en `movimientos_tesoreria`, no una suma directa de `Venta::aCobrar()`) — no hay garantía de que coincida centavo a centavo con el Total de esta pantalla; SC-003 sólo exige coincidencia contra el Dashboard, que comparte la misma fuente de cálculo (`CuentaCorriente`).
- La navegación de entrada a esta pantalla (desde qué menú/submenú del sidebar) no está confirmada por las capturas (sólo se ve la URL/contenido, no el punto de entrada) — se asume que cuelga de "Base de Datos" (junto a Clientes/Proveedores) o de "Informes", a definir en `/speckit-plan` según lo que ya exponga el sidebar actual; no bloquea el resto del spec.
