# Feature Specification: Comprobantes Históricos con CAE Real de ARCA

**Feature Branch**: `088-comprobantes-historicos-arca`

**Created**: 2026-08-28

**Status**: Draft

**Input**: User description: "Recuperar comprobantes históricos pre-migración con CAE real de ARCA — 13 comprobantes de venta (12 ventas, una con dos CAE) del punto de venta 0009, emitidos entre el 04/08/2026 y el 13/08/2026 en el CRM anterior a la migración de base, que quedaron fuera de la base actual. Deben incorporarse al Libro IVA Ventas y al IVA Digital sin afectar ningún otro módulo del CRM (Reporte Final, KPIs de ventas, Stock, Cuenta Corriente, Tesorería)."

## Contexto

Antes de la migración de base de datos del 13/08/2026, el negocio ya usaba el CRM y facturó electrónicamente contra ARCA desde el punto de venta `0009`. Al reconstruir la base actual a partir del export/import de Contagram, esos comprobantes fiscales no se trajeron — el proceso de import no tenía forma de traer "comprobante con CAE", sólo el dato comercial de la venta.

Se verificó **contra ARCA directamente** (WSFEv1, `FECompUltimoAutorizado` y `FECompConsultar`), no sólo contra las bases locales, que:

- El talonario `0009` está completo y correlativo del lado de ARCA, tanto para Factura A como B — no hay huecos ni números sin usar salvo los que corresponden a intentos rechazados (que ARCA no numera).
- 13 comprobantes con CAE real y aprobado no están en la base actual del CRM: 12 vinculados a una venta que sigue existiendo en la base anterior (`contagram`), y 1 más (Factura B `0009-00000006`) que ARCA tiene aprobado pero que no quedó registrado en ninguna base local — se reconstruye únicamente a partir de la respuesta de ARCA.
- Una de esas 12 ventas (id 122 en la base anterior) tiene **dos** Facturas A aprobadas por ARCA con CAE distinto (`0009-00000007` y `0009-00000008`), mismo importe, emitidas 31 segundos aparte — un reintento real que ARCA aprobó dos veces. Ambas quedan declaradas: 14 comprobantes fiscales en total.

Si esto no se recupera, el Libro IVA Ventas y el archivo IVA Digital de agosto 2026 (specs 077 y 086) van a estar incompletos frente a lo que el propio contribuyente ya declaró ante ARCA — el contador va a liquidar de menos.

## Clarifications

### Session 2026-08-28

- Q: El comprobante reconstruido sólo desde ARCA (Factura B `0009-00000006`) no tiene cliente identificado (`DocTipo:99` en la respuesta de ARCA) — ¿cómo se guarda? → A: Sin cliente vinculado a un registro real del CRM, tratado como Consumidor Final sin identificar — mismo criterio que cualquier venta a consumidor final sin documento ya soportado hoy.
- Q: ¿Cómo se cargan los 14 registros a la base? → A: Migración de Laravel que crea la tabla y hace el `INSERT` de los 14 valores fijos en el mismo paso — reproducible en cualquier entorno sin depender de que la base anterior (`contagram`) siga accesible.
- Q: ¿Cómo se integran al Libro IVA Ventas y al IVA Digital sin tocar el comportamiento existente? → A: Una rama adicional de `UNION ALL`, mismo patrón que ya usa `LibroIvaVentasQuery` para agregar las Notas de Crédito/Débito — la tabla nueva aporta directamente sus columnas ya calculadas, sin clasificación fiscal que derivar (los importes vienen fijos).

## User Scenarios & Testing *(mandatory)*

### User Story 1 - El Libro IVA Ventas de agosto incluye los 14 comprobantes históricos (Priority: P1)

El usuario administrativo abre "Información para tu Contador" → Libro IVA Ventas, elige Agosto 2026, y ve en la tabla los 13 comprobantes de venta que ya tenían CAE real (los 12 recuperados de la base anterior, más el reconstruido sólo desde ARCA) mezclados junto con las ventas normales del período, ordenados por fecha como el resto.

**Why this priority**: es la razón de ser de la feature — sin esto el contador liquida de menos, que es el problema real que dispara todo el trabajo.

**Independent Test**: filtrar Agosto 2026 en el Libro IVA Ventas y verificar que aparecen los 14 comprobantes históricos, con neto/IVA/total idénticos a los que ARCA tiene aprobados, sumando correctamente en la barra de totales del período.

**Acceptance Scenarios**:

1. **Given** el Libro IVA Ventas filtrado a Agosto 2026, **When** se carga la tabla, **Then** aparecen los 14 comprobantes históricos junto con las ventas ya existentes del período, sin duplicar ni faltar ninguno.
2. **Given** esos 14 comprobantes, **When** se mira la barra de totales del período, **Then** el neto/IVA/total de esos 14 está incluido en la suma general.
3. **Given** la venta histórica con dos CAE (id 122 de la base anterior), **When** se mira la tabla, **Then** aparecen como dos filas separadas, cada una con su propio número de comprobante y su propio CAE — no una fila con el importe duplicado ni una sola fila que oculte el segundo comprobante.

---

### User Story 2 - El IVA Digital de agosto incluye los 14 comprobantes históricos (Priority: P1)

El usuario genera el ZIP de IVA Digital (spec 086) para Agosto 2026 y los 4 archivos de ancho fijo incluyen los 14 comprobantes históricos con el mismo formato posicional que el resto — listos para presentar ante ARCA sin diferencias respecto de lo que ARCA ya tiene aprobado.

**Why this priority**: comparte prioridad con la historia 1 — el Libro IVA en pantalla y el archivo que se presenta ante ARCA tienen que decir lo mismo (mismo criterio que ya rige specs 077/086/087: una sola fuente de verdad).

**Independent Test**: generar el IVA Digital de Agosto 2026 y verificar que los archivos "Comprobantes Ventas" y "Alícuotas Ventas" incluyen las 14 líneas correspondientes, con los números de comprobante y CAE reales.

**Acceptance Scenarios**:

1. **Given** el ZIP de IVA Digital de Agosto 2026, **When** se abre "Comprobantes Ventas...txt", **Then** están las 14 líneas de los comprobantes históricos, con el ancho de registro y el formato exigido por RG 3685 (igual que cualquier otro comprobante del período).
2. **Given** el mismo ZIP, **When** se abre "Alicuotas Ventas...txt", **Then** cada comprobante histórico tiene su línea de alícuota con neto e IVA reales, y "Cantidad de alícuotas" del comprobante coincide con la cantidad real de líneas emitidas (mismo criterio que FR-016 de la spec 086).

---

### User Story 3 - Los comprobantes históricos no aparecen ni suman en ningún otro módulo (Priority: P1)

El usuario revisa Reporte Final, el Informe de Stock, la Cuenta Corriente de los clientes involucrados y Tesorería para el período correspondiente, y no encuentra ningún rastro de estos 14 comprobantes — ni una venta nueva, ni un cobro, ni un movimiento de stock, ni un saldo alterado.

**Why this priority**: requisito explícito y no negociable del usuario — el riesgo de "arreglar" el Libro IVA rompiendo otro módulo (duplicando ventas, inflando el Reporte Final, generando cobros o movimientos de stock fantasma) es peor que el problema original.

**Independent Test**: comparar el Reporte Final, KPIs de ventas, Informe de Stock y Cuenta Corriente de Clientes de Agosto 2026 antes y después de cargar los 14 comprobantes históricos — deben ser idénticos, sin ninguna diferencia atribuible a ellos.

**Acceptance Scenarios**:

1. **Given** los 14 comprobantes históricos ya cargados, **When** se abre el Reporte Final de Agosto 2026, **Then** los totales de ventas/ingresos no incluyen ni un peso de estos comprobantes.
2. **Given** los mismos comprobantes, **When** se abre la Cuenta Corriente de cualquiera de los clientes involucrados, **Then** no aparece ningún movimiento nuevo ni cambia el saldo.
3. **Given** los mismos comprobantes, **When** se revisa Tesorería, **Then** no existe ningún cobro ni movimiento de cuenta asociado a ellos.
4. **Given** los mismos comprobantes, **When** se revisa el Informe de Stock, **Then** no se descontó ni ajustó ningún producto por ellos.

---

### Edge Cases

- **Cliente sin identificar** (el comprobante reconstruido sólo desde ARCA, Factura B `0009-00000006`, tiene `DocTipo:99` en la respuesta de ARCA — sin CUIT/DNI real): el Libro IVA y el IVA Digital deben tratarlo igual que cualquier comprobante a Consumidor Final sin identificar, sin necesitar un `cliente_id` real del CRM.
- **Doble CAE de una misma operación comercial** (venta id 122 de la base anterior): se declaran como dos comprobantes fiscales independientes con el mismo detalle de neto/IVA (ver Clarifications) — no se intenta "fusionarlos" ni elegir uno solo.
- **Filtros ARCA/Manuales del Libro IVA Ventas** (spec 077, FR-014): todos los 14 comprobantes tienen CAE real aprobado, así que deben clasificar siempre como "ARCA" (aprobados), nunca como "Manuales", sin excepción.
- **Reintento de generación del IVA Digital**: generar el mismo período dos veces debe seguir dando bytes idénticos (SC-005 de la spec 086) incluyendo estos comprobantes — no pueden introducir no-determinismo (por ejemplo, por depender de un orden de inserción no fijado).

## Requirements *(mandatory)*

### Carga de los datos

- **FR-001**: El sistema MUST incorporar los 13 comprobantes de venta identificados (12 recuperados de la base anterior más 1 reconstruido sólo desde la respuesta de ARCA) con sus datos fiscales reales: fecha de emisión, tipo de comprobante, punto de venta, número, CAE, vencimiento de CAE, cliente (CUIT/DNI y nombre cuando exista, o sin identificar cuando no), neto, IVA por alícuota, y total.
- **FR-002**: La venta histórica con dos comprobantes fiscales aprobados MUST cargarse como dos registros de comprobante fiscal separados, cada uno con su propio número de comprobante, CAE, y el neto/IVA/total completo de la operación (14 comprobantes fiscales en total sobre 13 operaciones).
- **FR-003**: Los datos numéricos (neto, IVA, total) MUST tomarse tal como están registrados — sin recalcular ni ajustar por redondeo — igual que el criterio ya establecido para el IVA Digital (spec 086, FR-015): se declara lo que ARCA ya tiene, no una reconstrucción propia.

### Aislamiento del resto del CRM

- **FR-004**: El sistema MUST modelar estos comprobantes en una estructura de datos separada de las ventas normales del CRM, de forma que ningún módulo que hoy sume, liste o calcule sobre la tabla de ventas los incluya sin un cambio explícito.
- **FR-005**: El sistema MUST NOT crear cobros, movimientos de tesorería, movimientos de stock, ni remitos asociados a estos comprobantes.
- **FR-006**: El sistema MUST NOT incluir estos comprobantes en Reporte Final, KPIs de ventas, Informe de Stock, ni Cuenta Corriente de Clientes.
- **FR-007**: El sistema MUST tratar estos comprobantes como un conjunto cerrado y fijo — el sistema no ofrece una pantalla ni un flujo para agregar nuevos comprobantes históricos de este tipo en el futuro (si apareciera un caso similar, es una carga de datos puntual, no una funcionalidad de uso recurrente).

### Integración con Libro IVA Ventas e IVA Digital

- **FR-008**: El Libro IVA Ventas (spec 077) MUST incluir estos comprobantes cuando su fecha de emisión cae dentro del período (mes/año) consultado, mezclados con el resto de las filas del período y contribuyendo a los totales del período de la misma forma.
- **FR-009**: El IVA Digital (spec 086) MUST incluir estos comprobantes cuando su fecha de emisión cae dentro del período generado, con el mismo formato posicional y las mismas reglas (incluida la de "Cantidad de alícuotas" calculada por construcción) que el resto de los comprobantes.
- **FR-010**: Estos comprobantes MUST clasificar siempre como aprobados por ARCA (nunca como "Manuales") en el filtro Electrónicas/Manuales del Libro IVA Ventas y del envío al contador (spec 087), porque los 14 tienen CAE real vigente.
- **FR-011**: La incorporación de estos comprobantes al Libro IVA Ventas y al IVA Digital MUST hacerse sin modificar el comportamiento de esos módulos para el resto de los comprobantes ya existentes — el resultado sobre datos que no incluyen estos 14 comprobantes debe ser exactamente el mismo antes y después de esta feature.

### Key Entities

- **Comprobante Fiscal Histórico**: un comprobante de venta con CAE real aprobado por ARCA, emitido antes de la migración de base, que no corresponde a ninguna venta viva en el CRM actual. Atributos: fecha de emisión, tipo de comprobante (A/B), punto de venta, número, CAE, vencimiento de CAE, documento y nombre del cliente (o sin identificar), neto, IVA por alícuota, total. No tiene relación con cobros, stock, remitos ni cuenta corriente.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: El Libro IVA Ventas y el IVA Digital de Agosto 2026 muestran/incluyen los 14 comprobantes históricos con los mismos importes que ARCA tiene aprobados, verificable comprobante por comprobante contra la consulta directa a ARCA.
- **SC-002**: Ningún reporte ajeno al Libro IVA Ventas / IVA Digital (Reporte Final, KPIs de ventas, Informe de Stock, Cuenta Corriente de Clientes) cambia su resultado por la incorporación de estos comprobantes — comparación antes/después sin diferencias.
- **SC-003**: Cero movimientos de tesorería, cobros, remitos o ajustes de stock creados como efecto de esta carga.
- **SC-004**: El total de IVA declarado en el Libro IVA Ventas de Agosto 2026 sube exactamente en la suma del IVA de los 14 comprobantes ($278.472,23, verificable por prueba independiente contra los datos de ARCA — ver data-model.md §2).

## Assumptions

- El neto/IVA/total de los 12 comprobantes recuperados de la base anterior (`contagram`) es correcto tal como está guardado ahí — no se vuelve a auditar la factura original, sólo se traslada.
- El comprobante reconstruido únicamente desde ARCA (Factura B `0009-00000006`) no tiene detalle de items ni cliente identificado en ninguna base local; se reconstruye con los campos que devuelve la consulta a ARCA (`FECompConsultar`): neto, IVA (una sola alícuota, 21%), total, y receptor sin identificar (`DocTipo:99`).
- Los `cliente_id` de la base anterior corresponden a la misma persona en la base actual (verificado por nombre/CUIT coincidente) — se usa esa referencia cuando el cliente existe, y "sin identificar" para el comprobante reconstruido desde ARCA.
- Esta feature es una carga de datos puntual y cerrada (14 comprobantes fijos), no un flujo operativo del día a día — no incluye una UI de alta para cargar comprobantes históricos adicionales en el futuro.
- El punto de venta `0009` ya existe en la configuración del sistema (usado por la emisión electrónica real); no hace falta crearlo.

## Dependencies

- **Spec 077**: Libro IVA Ventas — estos comprobantes se integran como una fuente adicional a la consulta existente, sin modificar su comportamiento para el resto de los datos.
- **Spec 086**: IVA Digital — misma integración aditiva sobre el generador de archivos.
- **Spec 087**: Envío al contador por correo — al reusar el Libro IVA Ventas y el IVA Digital, hereda automáticamente estos comprobantes en los adjuntos que envía, sin trabajo adicional.

## Out of Scope

- Corregir o completar el registro de estos comprobantes en la base anterior (`contagram`) — esa base queda como está, sólo se lee de ella una vez para la carga inicial.
- Resolver ante ARCA el comprobante duplicado de la venta 122 (Nota de Crédito de anulación, consulta al contador, etc.) — queda fuera, es una decisión fiscal del usuario/contador, no una tarea de sistema.
- Una pantalla o flujo para cargar comprobantes históricos nuevos en el futuro — es una carga de datos única y cerrada.
- Migrar o corregir cualquier otro dato de la base anterior que no sean estos 14 comprobantes fiscales.
