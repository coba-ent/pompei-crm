# Feature Specification: Otros Ingresos en el informe de flujo de caja de Tesorería

**Feature Branch**: `055-otros-ingresos-tesoreria`

**Created**: 2026-08-10 (revisado 2026-08-11 tras verificar el estado real del código)

**Status**: Draft

**Input**: User description original: "Otros Ingresos debe impactar en Tesorería — hoy al cargar un Otro Ingreso no se genera movimiento de tesorería". **Verificado contra el código y la base real y la premisa era incorrecta en parte** — ver "Hallazgo que redefine el alcance" abajo.

## Hallazgo que redefine el alcance

Antes de escribir requisitos se verificó el código y los datos de producción, porque contradecían el reporte original:

1. **El circuito Otros Ingresos → Tesorería ya existe y funciona.** `Cobranzas::registrarOtroIngreso()`, `conciliar()` y `anularOtroIngreso()` (`app/Services/Ingresos/Cobranzas.php`) ya replican el patrón de Gastos (incluido el detalle de `cuenta_tesoreria_id = null` mientras está pendiente), y `OtroIngresoController` ya los invoca en `store`/`update`/`destroy`. Este código viene de la spec 008, no es nuevo. El saldo de cuenta (`CuentaTesoreria::saldoA()`, que suma `monto` de **todos** los movimientos sin filtrar por tipo) ya refleja correctamente cualquier Otro Ingreso cargado.
2. **El bug real está un nivel más arriba: el informe de flujo de caja ("Movimientos" en Tesorería).** `Tesoreria::flujo()` (`app/Services/Tesoreria/Tesoreria.php:142-172`) arma la sección "Cobros" con `whereIn('tipo', ['cobro'])` y "Pagos" con `['pago', 'gasto']`. El tipo `ingreso` (agregado en la migración `2026_08_18_060005` específicamente para los Otros Ingresos históricos) **no aparece en ninguna de las dos listas** — queda invisible en el informe, ni suma a Cobros ni a Pagos.
3. **Esto ya afecta datos reales, no sólo un caso futuro.** `otros_ingresos` tiene hoy **0 registros** (nadie cargó un Otro Ingreso todavía desde el CRM nuevo), pero existen **61 movimientos con `tipo = 'ingreso'` por $34.570.442,27** cargados por la migración del histórico de Contagram. Ese monto está ausente del informe de flujo de caja ahora mismo.
4. **La propia pantalla ya declara la intención correcta.** El banner de `resources/views/tesoreria/movimientos.blade.php` dice textualmente: *"Cobros: todos los cobros realizados sobre Ventas + todos los ingresos registrados en Otros Ingresos."* — es decir, el diseño ya decidió que un Otro Ingreso debe sumar dentro de la sección **"Cobros"** de este informe (no como una sección nueva). El código no cumple esa promesa.
5. **`Cobranzas::registrarOtroIngreso()` hoy escribe `tipo = 'cobro'`** (no `'ingreso'`) al generar el movimiento de un Otro Ingreso nuevo. Es inconsistente con los 61 movimientos históricos, que sí usan `'ingreso'` — y es la causa de que, en la práctica, ambos casos (histórico vs. nuevo) necesiten tratarse igual en el informe para no volver a divergir.

**Alcance redefinido**: no hace falta "conectar" nada nuevo entre Otros Ingresos y Tesorería (ya está conectado). El fix es:
(a) unificar el `tipo` que genera `Cobranzas::registrarOtroIngreso()` a `'ingreso'` (coherente con los históricos y con el propósito documentado del enum), y
(b) hacer que `Tesoreria::flujo()` sume `'ingreso'` dentro de la sección "Cobros", cumpliendo el banner ya escrito — sin migración de datos, porque no hay ningún registro de `otros_ingresos` que recatalogar todavía; sólo hace falta que el informe empiece a leer el tipo `ingreso` que ya existe.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Los 61 movimientos históricos de Otros Ingresos aparecen en el informe de flujo de caja (Priority: P1)

Un usuario abre Tesorería → Movimientos con un rango de fechas que incluye movimientos históricos migrados (tipo `ingreso`). Hoy esos $34.570.442,27 no suman a "Total Cobros" ni aparecen en el desglose por cuenta. Después del fix, aparecen sumados dentro de "Cobros" (según el criterio ya declarado en el banner de la pantalla).

**Why this priority**: Es el bug real y ya afecta datos de producción — el informe de flujo de caja está subestimando ingresos reales ahora mismo.

**Independent Test**: Con datos de prueba que incluyan movimientos tipo `ingreso` en un rango de fechas, llamar a `Tesoreria::flujo()` para ese rango y verificar que "Total Cobros" y el desglose por cuenta incluyen esos montos.

**Acceptance Scenarios**:

1. **Given** una cuenta con un movimiento tipo `ingreso` de $1.000 fechado dentro del rango consultado, **When** se pide el informe de flujo de caja para ese rango, **Then** "Total Cobros" incluye ese $1.000 y el desglose por cuenta de Cobros lo suma a esa cuenta.
2. **Given** el mismo escenario, **When** se exporta el informe a CSV o PDF, **Then** el monto también aparece reflejado en el export (mismos datos que la vista).
3. **Given** un rango de fechas que no incluye la fecha del movimiento `ingreso`, **When** se pide el informe, **Then** ese movimiento no se cuenta (el filtro de fecha sigue aplicando igual que hoy).

---

### User Story 2 - Un Otro Ingreso nuevo se registra con tipo `ingreso`, no `cobro` (Priority: P1)

Un usuario carga un Otro Ingreso no pendiente desde `/incomes`. El movimiento de tesorería que se genera queda tipificado como `ingreso`, igual criterio que los históricos migrados — no como `cobro` (que es el tipo reservado para cobros de Venta).

**Why this priority**: Evita que la nueva carga vuelva a divergir del criterio ya usado en el histórico, y mantiene `tipo` como un dato correcto para cualquier análisis futuro que distinga Ventas de Otros Ingresos (aunque en este informe puntual ambos se muestren juntos en "Cobros").

**Independent Test**: Crear un Otro Ingreso no pendiente con cuenta asignada; verificar que el `MovimientoTesoreria` generado tiene `tipo = 'ingreso'`.

**Acceptance Scenarios**:

1. **Given** un Otro Ingreso nuevo no pendiente con cuenta asignada, **When** se guarda, **Then** el movimiento de tesorería generado tiene `tipo = 'ingreso'`.
2. **Given** un Otro Ingreso pendiente que se concilia (se le saca "pendiente" y se le asigna cuenta), **When** se genera su movimiento, **Then** también queda con `tipo = 'ingreso'`.
3. **Given** el comportamiento de alta/conciliación/edición/eliminación de Otros Ingresos ya vigente (pendiente sin cuenta ni movimiento, conciliación al destildar pendiente, edición in-place del monto/cuenta/fecha, reversión al eliminar), **When** se aplica este fix, **Then** ese comportamiento no cambia — sólo cambia el valor de `tipo` del movimiento generado.

---

### Edge Cases

- El informe de flujo de caja ya excluye Gastos pendientes de "Pagos" por construcción (no generan movimiento hasta pagarse); el mismo criterio aplica de forma simétrica a Otros Ingresos pendientes, que ya no generan movimiento hasta conciliarse — no requiere cambio adicional.
- Movimientos `saldo_inicial` y `movimiento_entre_cuentas` siguen fuera de "Cobros"/"Pagos" en este informe, sin cambios.
- No hay Otros Ingresos existentes en `otros_ingresos` con `tipo = 'cobro'` que recatalogar (la tabla está vacía) — el cambio de `Cobranzas::registrarOtroIngreso()` sólo afecta altas futuras.
- Los 61 movimientos históricos ya tienen `tipo = 'ingreso'` correctamente — no se tocan, sólo empiezan a ser leídos por el informe.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: `Tesoreria::flujo()` DEBE incluir los movimientos con `tipo = 'ingreso'` dentro de la sección "Cobros" (mismo criterio ya declarado en el banner de la pantalla de Movimientos), tanto en el total como en el desglose por cuenta.
- **FR-002**: El export a CSV y a PDF del informe de Movimientos DEBEN reflejar el mismo total y desglose que la vista (es decir, heredan automáticamente el fix de FR-001 al consumir el mismo método `flujo()`).
- **FR-003**: `Cobranzas::registrarOtroIngreso()` DEBE generar el movimiento de tesorería de un Otro Ingreso no pendiente con `tipo = 'ingreso'`, no `'cobro'`.
- **FR-004**: El comportamiento ya vigente de Otros Ingresos (pendiente sin cuenta/movimiento hasta conciliarse, conciliación in-place, edición in-place del movimiento existente, reversión del movimiento al eliminar) NO DEBE modificarse — este fix sólo cambia el `tipo` usado y qué lee el informe.
- **FR-005**: El fix NO DEBE requerir ninguna migración de datos: los 61 movimientos históricos ya tienen `tipo = 'ingreso'` correcto, y no existen registros de `otros_ingresos` con `tipo = 'cobro'` que recatalogar.
- **FR-006**: El saldo de cuenta (`CuentaTesoreria::saldoA()`) y la pestaña Saldos NO requieren cambios — ya suman todos los tipos de movimiento sin filtrar.

### Key Entities *(include if feature involves data)*

- **MovimientoTesoreria**: sin cambios de esquema. El campo `tipo` ya acepta `ingreso`; este feature corrige quién lo usa (`Cobranzas::registrarOtroIngreso()`) y quién lo lee (`Tesoreria::flujo()`).
- **OtroIngreso**: sin cambios de esquema ni de comportamiento — el fix es enteramente sobre la tipificación del movimiento asociado y el informe que lo consume.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: El informe de flujo de caja de Tesorería, para cualquier rango de fechas, muestra un "Total Cobros" que incluye el 100% de los movimientos tipo `ingreso` de ese rango (verificable comparando `SUM(monto) WHERE tipo IN ('cobro','ingreso')` contra el total mostrado).
- **SC-002**: Los $34.570.442,27 de los 61 movimientos históricos migrados aparecen reflejados en el informe de flujo de caja al consultar un rango que los incluya, sin ninguna acción manual de recatalogación.
- **SC-003**: Todo Otro Ingreso nuevo no pendiente, desde este fix en adelante, genera un movimiento con `tipo = 'ingreso'` — verificable en el 100% de las altas.
- **SC-004**: Ningún otro informe o pantalla de Tesorería (Saldos, ficha/ledger de cuenta) cambia su resultado como efecto de este fix — sólo cambia el informe de Movimientos.

## Assumptions

- Se confirma con capturas/código, no se reinterpreta el diseño: el criterio de "Otros Ingresos cuentan como Cobros en este informe" ya está declarado en el banner de la pantalla (`resources/views/tesoreria/movimientos.blade.php`) y se toma como la fuente de verdad de negocio para esta sección — no se crea una sección "Ingresos" nueva separada de "Cobros".
- No se modifica el copy del banner porque ya describe correctamente el comportamiento deseado; sólo el código pasa a cumplirlo.
- Fuera de alcance: cualquier cambio al circuito de alta/edición/baja de Otros Ingresos ya implementado (spec 008) — sigue funcionando igual, sólo cambia el valor de `tipo` que persiste.
- Fuera de alcance: Cuenta Corriente/aging — Otros Ingresos sigue sin relación con ese módulo.
