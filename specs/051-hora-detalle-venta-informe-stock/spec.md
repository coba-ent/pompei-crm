# Feature Specification: Hora en Movimientos de Stock y Detalle de Venta en Informe de Stock

**Feature Branch**: `051-hora-detalle-venta-informe-stock`

**Created**: 2026-08-06

**Status**: Draft

**Input**: User description: "Agregar hora al movimiento de stock y ordenar el Informe de Stock por fecha+hora por defecto, y mostrar en la columna Detalle/Descripción los datos de la venta de origen (tipo y N° de comprobante + cliente) cuando el movimiento proviene de una Venta."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Ver el orden real de los movimientos del día (Priority: P1)

Como usuario que audita el stock, cuando entro varios movimientos el mismo día (por ejemplo dos ventas y un ajuste), necesito que el Informe de Stock los muestre en el orden real en que ocurrieron, no sólo agrupados por fecha sin distinguir el momento del día.

**Why this priority**: Es la base del pedido — sin hora, el saldo corrido y el orden de la tabla no reflejan la secuencia real de eventos dentro de un mismo día, lo que puede confundir a quien está reconciliando stock.

**Independent Test**: Registrar dos movimientos de stock en el mismo día en horarios distintos (por ejemplo una venta a la mañana y un ajuste a la tarde) y verificar que el Informe de Stock los liste en ese orden, con la hora visible en la columna de fecha.

**Acceptance Scenarios**:

1. **Given** dos movimientos de stock generados el mismo día en horarios distintos, **When** se abre el Informe de Stock sin aplicar ningún orden manual, **Then** los movimientos aparecen ordenados por fecha y hora ascendente (el más antiguo primero), coincidiendo con el orden real en que ocurrieron.
2. **Given** un movimiento de stock nuevo, **When** se lo genera (venta, compra, ajuste o transferencia), **Then** el sistema registra la hora real en que se generó, no sólo la fecha.
3. **Given** un movimiento de stock migrado desde antes de este cambio (sin hora registrada), **When** se lo visualiza en el Informe de Stock, **Then** se sigue mostrando de forma consistente (sin errores) usando una hora de referencia razonable para no romper el orden existente.

---

### User Story 2 - Identificar de un vistazo a qué venta corresponde un movimiento (Priority: P2)

Como usuario que revisa el Informe de Stock, cuando un movimiento de salida (o su reintegro) proviene de una Venta, necesito ver en la misma fila el comprobante y el cliente involucrados, sin tener que ir a buscar la venta por separado.

**Why this priority**: Ahorra el paso manual de cruzar el movimiento con el módulo de Ventas para saber "a quién le salió esto"; depende de que la tabla ya esté mostrando los movimientos (User Story 1), pero es una mejora independiente de esa historia.

**Independent Test**: Generar una venta que descuente stock, ir al Informe de Stock y verificar que la fila del movimiento de salida muestra el tipo y número de comprobante de la venta junto con el nombre del cliente, sin necesidad de abrir la venta.

**Acceptance Scenarios**:

1. **Given** una venta con cliente asignado que descuenta stock de un producto, **When** se consulta el Informe de Stock, **Then** la fila del movimiento de salida generado por esa venta muestra en la columna Detalle el tipo y número de comprobante de la venta junto con el nombre del cliente.
2. **Given** una venta que se elimina y por lo tanto reintegra stock, **When** se consulta el Informe de Stock, **Then** la fila del movimiento de entrada generado por esa reintegración también muestra el detalle de la venta de origen (mismo formato que la salida).
3. **Given** un movimiento de stock que NO proviene de una venta (compra, ajuste manual o transferencia), **When** se consulta el Informe de Stock, **Then** la columna Detalle sigue mostrando el mismo contenido que mostraba antes de este cambio (sin alterar su comportamiento).
4. **Given** una venta sin cliente asignado (venta a consumidor final sin cliente cargado) que descuenta stock, **When** se consulta el Informe de Stock, **Then** la fila muestra el tipo y número de comprobante de la venta, omitiendo la parte del cliente sin generar error ni texto confuso.

---

### Edge Cases

- ¿Qué pasa con movimientos de stock ya existentes en la base (creados antes de este cambio) que no tienen hora registrada? Deben seguir ordenándose de forma estable (ver Assumptions) sin romper el saldo corrido ya calculado.
- ¿Qué pasa si la venta de origen de un movimiento fue borrada (soft delete) o ya no existe? El movimiento debe seguir mostrando su detalle de venta si la venta sigue accesible (aunque esté eliminada lógicamente); si no es accesible, la columna Detalle no debe romperse ni mostrar error, degradando a vacío.
- ¿Qué pasa con movimientos de stock que provienen de Mercado Libre o Tiendanube (ventas con origen distinto a manual)? Deben mostrar el mismo detalle de comprobante + cliente que una venta manual, porque técnicamente siguen siendo una Venta.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: El sistema DEBE registrar la hora real (no sólo la fecha) en que se genera cada movimiento de stock, para cualquier origen (venta, compra, ajuste manual, transferencia), **excepto** en el caso ya existente de la entrada de stock al dar de alta una Compra, que intencionalmente usa la fecha de emisión del comprobante de compra (potencialmente retroactiva) en lugar del momento real de carga — ese caso conserva su comportamiento actual (hora `00:00:00`) sin cambios.
- **FR-002**: El Informe de Stock DEBE ordenar sus filas por fecha y hora ascendente por defecto (el movimiento más antiguo primero), en lugar de ordenar sólo por fecha.
- **FR-003**: El cálculo del saldo corrido ("Stock Saldo") del Informe de Stock DEBE mantener la misma partición (por producto/variante/depósito) y DEBE seguir respetando el orden cronológico real de los movimientos al incorporar la hora.
- **FR-004**: Cuando un movimiento de stock tiene como origen una Venta, la columna Detalle del Informe de Stock DEBE mostrar el tipo y número de comprobante de esa venta junto con el nombre del cliente asociado (si tiene cliente asignado).
- **FR-005**: Cuando la venta de origen no tiene cliente asignado, la columna Detalle DEBE mostrar igualmente el tipo y número de comprobante, omitiendo la parte del cliente sin error.
- **FR-006**: Cuando un movimiento de stock tiene un origen distinto a Venta (compra, ajuste manual, transferencia), la columna Detalle DEBE conservar exactamente el mismo comportamiento que tenía antes de este cambio.
- **FR-007**: Los filtros existentes del Informe de Stock (rango de fechas, usuario, operación, proveedor, tipo de producto, producto, estado) DEBEN seguir funcionando sin cambios de comportamiento tras incorporar la hora.
- **FR-008**: Los movimientos de stock existentes (previos a este cambio) DEBEN seguir siendo listados y ordenados de forma estable en el Informe de Stock, sin generar errores ni duplicar/perder filas.

### Key Entities

- **Movimiento de Stock**: Registro de una entrada, salida, ajuste o transferencia de stock de un producto/variante en un depósito, en un momento dado. Pasa a llevar fecha y hora del momento en que ocurrió (antes sólo fecha). Puede tener un origen polimórfico (por ejemplo, una Venta o una Compra) que lo generó.
- **Venta**: Comprobante de venta con tipo y número de comprobante y, opcionalmente, un cliente asociado. Es una de las fuentes posibles de un Movimiento de Stock.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Al consultar el Informe de Stock sin aplicar filtros, el 100% de los movimientos generados el mismo día quedan listados en el orden real en que ocurrieron (verificable comparando el orden de la tabla contra la secuencia real de alta de esos movimientos).
- **SC-002**: Un usuario puede identificar el comprobante y cliente de una venta que originó un movimiento de stock leyendo únicamente la fila del Informe de Stock, sin necesidad de navegar a otra pantalla, en el 100% de los movimientos originados en ventas con cliente asignado.
- **SC-003**: Ningún movimiento de stock con origen distinto a Venta cambia su contenido de Detalle respecto al comportamiento actual (0 regresiones sobre compras, ajustes y transferencias existentes).

## Assumptions

- Para los movimientos de stock creados antes de este cambio (sin hora real registrada), se asume una hora de referencia fija (por ejemplo 00:00:00) para no alterar retroactivamente su fecha; el orden entre movimientos del mismo día sin hora original se sigue resolviendo por el criterio secundario ya existente (ID de alta), igual que hoy.
- El formato del detalle de venta a mostrar es "{tipo de comprobante} {número de comprobante} - {nombre de cliente}" (por ejemplo "B 0001-00001234 - Juan Pérez"); cuando no hay cliente, se omite la parte "- {nombre de cliente}".
- Se considera "venta" tanto a las ventas manuales como a las originadas automáticamente por Mercado Libre o Tiendanube, ya que todas usan el mismo modelo de Venta como origen del movimiento de stock.
- No forma parte de este alcance agregar una acción de navegación (link) desde la fila del Informe de Stock hacia la venta de origen; queda fuera de este cambio y puede evaluarse como mejora futura.
- No forma parte de este alcance permitir que el usuario edite manualmente la hora de un movimiento de stock; la hora se registra automáticamente al momento de la operación, igual que ya ocurre con la fecha.
- La entrada de stock al dar de alta una Compra ya usa explícitamente `fecha_emision` de la Compra (fecha de negocio, potencialmente retroactiva) en vez de la fecha/hora real de carga — comportamiento documentado en `docs/modelo_datos.md` desde antes de este cambio; se mantiene tal cual, sin agregarle hora real.
