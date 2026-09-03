# Feature Specification: Bonificación efectiva por línea con Descuento General

**Feature Branch**: `098-bonif-efectiva-descuento-general`

**Created**: 2026-09-03

**Status**: Draft

**Input**: User description: "La ventana de crear/editar Presupuesto, Venta, Compra y NC/ND, y sus PDFs, no muestran el descuento general reflejado por línea. En pantalla, el Subtotal/Total de cada fila de ítems ignora el Descuento General de cabecera (sólo el total de a pie de página lo aplica bien). En el PDF, la columna Bonif. muestra sólo el descuento propio de línea, quedando en '-' cuando el descuento real de esa línea vino del Descuento General — contra Contagram real, esa columna debería mostrar el % efectivo combinado. El monto final siempre se calculó bien; es un bug de presentación, no de cálculo ni de datos guardados."

## Clarifications

### Session 2026-09-03

- Q: ¿Qué debe mostrar la columna "%Bonif." del PDF/Ver Detalle de una Nota de Crédito/Débito cuando la nota tiene Descuento General cargado? → A: Sólo el descuento propio de línea, separado — consistente con la documentación ya verificada (spec 095) de que Contagram mantiene el descuento de línea y el Descuento General de NC/ND como dos campos independientes (`discount` vs. `note[discount]`), sin fundirlos. El Descuento General se muestra aparte, en una fila de totales del PDF (que hoy no existe y se agrega en esta spec, igual que ya la tienen Presupuesto/Venta/Compra).

## User Scenarios & Testing *(mandatory)*

### User Story 1 - La fila de cada ítem refleja el Descuento General mientras se carga (Priority: P1)

Un usuario está armando un Presupuesto, Venta o Compra. Carga dos o más ítems sin bonificación propia y después escribe un Descuento General (por ejemplo 10%) en la cabecera. Hoy el Subtotal y el Total que se ven en cada fila de la grilla no cambian — siguen mostrando el importe bruto — aunque el total de a pie de página sí baja. Esto hace pensar que el descuento "no se aplicó a los ítems", cuando en realidad sí se va a aplicar al guardar.

**Why this priority**: Es el síntoma que reportó el cliente directamente y genera desconfianza sobre si el sistema está calculando bien — aunque el cálculo final ya era correcto, la falta de reflejo visual en la fila es lo que dispara la duda. Corregirlo es la parte más visible y de menor riesgo del cambio.

**Independent Test**: Abrir "Nueva Venta" (o Presupuesto/Compra), cargar dos ítems sin descuento de línea, escribir 10% de Descuento General y verificar que el Subtotal y Total de cada fila bajan un 10% respecto del precio bruto, sin necesidad de guardar ni de tocar ningún otro módulo.

**Acceptance Scenarios**:

1. **Given** una Venta nueva con dos ítems cargados (sin descuento de línea) y Descuento General en 0, **When** el usuario escribe 10 en el campo Descuento General, **Then** el Subtotal y el Total de cada fila de la grilla se recalculan en pantalla reflejando ese 10%, sin esperar a guardar.
2. **Given** una Venta con un ítem que además tiene 5% de descuento de línea, **When** el Descuento General está en 10%, **Then** el Subtotal de esa fila refleja ambos descuentos combinados (no la suma aritmética 15%, sino el efecto combinado: primero el 5% de línea sobre el bruto, después el 10% general sobre ese resultado).
3. **Given** un Descuento General cargado en modo "monto fijo" ($) en vez de porcentaje, **When** el usuario carga o edita ítems, **Then** el Subtotal/Total de cada fila también reflejan la porción proporcional de ese monto fijo, igual que ya lo hace el total de a pie de página.
4. **Given** Presupuesto, Venta o Compra, **When** se aplica esta corrección, **Then** el campo editable "Desc." (%) de cada fila NO se modifica ni se le escribe el % combinado — sigue mostrando únicamente el descuento propio de esa línea, tal cual lo tipeó el usuario. En NC/ND este campo ya se comporta así hoy y no cambia (ver User Story 3): el descuento de línea y el Descuento General de la nota son y siguen siendo dos campos separados, sin combinar ni en la fila ni en el input.

---

### User Story 2 - El PDF de Presupuesto/Venta/Compra explica la bonificación real de cada línea (Priority: P1)

Un usuario emite un Presupuesto, Venta o Compra con Descuento General cargado y ninguna bonificación por línea. Al abrir o enviar el PDF, la columna "Bonif." de cada ítem muestra "-", aunque el Subtotal de esa misma línea sí está reducido por el descuento general. El PDF se lee como si el precio hubiera bajado sin motivo, y así se lo mandan al cliente final.

**Why this priority**: Es un documento que sale del negocio hacia terceros (clientes, proveedores). Que el número de la línea no coincida con la explicación visible en la misma fila es una inconsistencia que llega a alguien externo al sistema, no sólo un problema interno de UX.

**Independent Test**: Emitir el PDF de una Venta con 10% de Descuento General y dos ítems sin bonificación de línea, y verificar que la columna "Bonif." de cada ítem muestra 10% (no "-"), consistente con el Subtotal impreso en esa misma fila.

**Acceptance Scenarios**:

1. **Given** una Venta con Descuento General 10% y un ítem sin descuento de línea, **When** se genera el PDF, **Then** la columna "Bonif." de ese ítem muestra "10%", igual que lo hace Contagram.
2. **Given** un ítem con 5% de descuento de línea sobre una Venta con 10% de Descuento General, **When** se genera el PDF, **Then** la columna "Bonif." de ese ítem muestra el porcentaje efectivo combinado (no la suma aritmética de ambos).
3. **Given** una Venta sin Descuento General y un ítem con 8% de descuento de línea, **When** se genera el PDF, **Then** la columna "Bonif." sigue mostrando "8%" como hasta ahora (sin regresión del caso ya correcto).
4. **Given** un ítem con precio unitario cero o cantidad cero, **When** se genera el PDF, **Then** la columna "Bonif." no produce error ni división por cero — muestra "-".
5. Esta corrección aplica igual a los PDF de Presupuesto, Venta y Compra (mismo patrón de columna "Bonif." en los tres).

---

### User Story 3 - El PDF de NC/ND muestra el Descuento General como una fila propia de totales (Priority: P2)

A diferencia de Presupuesto/Venta/Compra, en NC/ND el descuento de línea y el Descuento General de la nota son dos conceptos que Contagram mantiene deliberadamente separados (documentado y verificado contra el propio formulario de Contagram en la spec 095): el usuario ve el descuento propio de cada línea en su columna, y el Descuento General de la nota aparte, no fundido. Hoy el PDF de NC/ND directamente no muestra el Descuento General en ningún lado — ni en la columna de línea (correcto, no debe mostrarlo ahí) ni en los totales (falta esa fila).

**Why this priority**: Es una inconsistencia menor comparada con User Story 1/2 — no hay ninguna columna que "mienta" mostrando "-" donde debería haber un %, porque en NC/ND esa columna nunca debió mostrar el combinado. Pero el PDF sí omite información real (cuánto Descuento General se aplicó), a diferencia de los otros tres PDF que ya tienen esa fila en sus totales.

**Independent Test**: Emitir el PDF ("Ver Detalle") de una NC/ND con Descuento General cargado y verificar que los totales del documento incluyen una fila "Descuento General" con el importe correspondiente, mientras que la columna "%Bonif." de cada línea sigue mostrando únicamente el descuento propio de esa línea.

**Acceptance Scenarios**:

1. **Given** una NC/ND con Descuento General del 10% y dos ítems sin bonificación propia, **When** se genera su PDF, **Then** la columna "%Bonif." de cada ítem sigue mostrando "0%" o "-" (el descuento propio de línea, no el general) — sin cambios respecto del comportamiento actual de esa columna.
2. **Given** la misma nota, **When** se genera el PDF, **Then** los totales del documento incluyen una fila "Descuento General" con el importe que ese descuento representa sobre el subtotal de los ítems, igual en estructura a como ya lo muestran los PDF de Presupuesto/Venta/Compra.
3. **Given** una NC/ND sin Descuento General (0% o no cargado), **When** se genera el PDF, **Then** esa fila de totales no rompe ni queda con un "$0,00" fuera de lugar — se admite mostrar $0,00 de forma consistente con el resto de la fila de totales.

---

### Edge Cases

- Ítem con precio unitario o cantidad en cero: el cálculo de porcentaje efectivo no debe dividir por cero ni mostrar `NaN`/`Infinity` en pantalla o PDF.
- El porcentaje efectivo calculado nunca puede resultar negativo: tanto el descuento de línea como el Descuento General ya están validados en el backend a un rango de 0 a 100% (`between:0,100` en línea; el modo monto fijo se acota a no superar el subtotal de ítems), así que su composición no puede producir un subtotal mayor al bruto. No se requiere manejo especial de ese caso porque no es alcanzable con los datos que el sistema ya valida al guardar.
- Descuento General en 100%: el Subtotal de cada fila debe llegar a $0 sin quedar en negativo por errores de redondeo.
- Redondeo con muchos ítems: la suma de los Subtotales de fila (ya con el Descuento General aplicado) debe seguir coincidiendo, dentro de una tolerancia de centavos, con el "Subtotal con Descuento" que ya se muestra a pie de página — no introducir una segunda fuente de redondeo que desacuerde fila vs. total.
- Cambiar el Descuento General de %(porcentaje) a $ (monto fijo) a mitad de carga: el Subtotal/Total de cada fila debe recalcularse con el nuevo modo sin quedar con el valor del modo anterior.
- Comprobante con un solo ítem: el Descuento General completo recae sobre esa única línea; el Subtotal de fila y el total de pie de página deben coincidir exactamente (sin redondeo residual visible).
- PDF con línea de descripción libre (sin producto de catálogo asociado): el cálculo de "Bonif." efectiva no depende de tener `producto_id`, sólo de cantidad/precio/descuento — debe funcionar igual.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: En las pantallas de alta y edición de Presupuesto, Venta y Compra (NC/ND queda fuera, ver FR-008), el Subtotal y el Total que se muestran en cada fila de la grilla de ítems DEBEN reflejar en tiempo real el Descuento General cargado en la cabecera del comprobante, combinado con el descuento propio de esa línea si lo tiene, sin esperar a guardar.
- **FR-002**: El recálculo de Subtotal/Total por fila (FR-001) DEBE producir, para el mismo comprobante, el mismo total final que ya calcula hoy el total de a pie de página — no se introduce una segunda lógica de cálculo que pueda divergir de la que ya usa el backend al guardar.
- **FR-003**: El campo editable de descuento por línea (columna "Desc.") NO DEBE modificarse ni reescribirse con el porcentaje combinado — conserva únicamente el valor que el usuario cargó como descuento propio de esa línea.
- **FR-004**: En el documento PDF de Presupuesto, Venta y Compra, la columna "Bonif." de cada ítem DEBE mostrar el porcentaje de descuento EFECTIVO de esa línea — la combinación del descuento propio de línea y el Descuento General del comprobante — en vez de sólo el descuento propio de línea.
- **FR-005**: Cuando una línea no tiene descuento propio y el comprobante no tiene Descuento General (o es 0%), la columna "Bonif." del PDF DEBE seguir mostrando "-", igual que hoy (sin regresión).
- **FR-006**: El cálculo de porcentaje efectivo (FR-004) DEBE ser resistente a precio unitario o cantidad en cero, sin producir errores ni valores no numéricos en el PDF.
- **FR-007**: Ningún comprobante ya emitido (Presupuesto, Venta, Compra o NC/ND histórica) requiere recalculo ni migración de datos: esta corrección es exclusivamente de presentación (pantalla y PDF), no de los montos ya guardados.
- **FR-008**: En NC/ND, la columna "%Bonif." (tanto en el "Ver Detalle"/PDF como en la grilla de carga) NO combina el descuento de línea con el Descuento General de la nota — muestra únicamente el descuento propio de esa línea, sin cambios respecto del comportamiento actual. FR-001 a FR-006 (recálculo de fila combinado, columna "Bonif." combinada) NO aplican a NC/ND.
- **FR-009**: El PDF ("Ver Detalle") de NC/ND DEBE agregar una fila "Descuento General" en su bloque de totales, mostrando el importe que ese descuento representa sobre el subtotal de los ítems de la nota — información que hoy no se muestra en ningún lado del documento.

### Key Entities *(include if feature involves data)*

- **Ítem de comprobante** (`presupuesto_items`, `venta_items`, `compra_items`): ya persiste `precio_unitario`, `cantidad`, `descuento_pct` (propio de línea) y `subtotal` (ya neto de línea + Descuento General, calculado por `CalculoComprobante`). Esta feature no agrega columnas: el porcentaje efectivo se deriva de los valores ya guardados.
- **Comprobante** (`presupuestos`, `ventas`, `compras`): ya persiste `descuento_general_tipo`, `descuento_general_pct`, `descuento_general_monto`. Sin cambios de esquema.
- **Ítem de NC/ND** (`nota_credito_debito_items`): a diferencia de los anteriores, no tiene columna `subtotal` propia — sólo `precio`, `cantidad`, `descuento_pct`. No requiere ningún cálculo de porcentaje efectivo (FR-008): su descuento de línea se muestra tal cual, sin combinar con el Descuento General de la nota.
- **Nota de Crédito/Débito** (`notas_credito_debito`): ya persiste `descuento_general_tipo`, `descuento_general_pct`, `descuento_general_monto` (heredados del comprobante de origen o editados). Sin cambios de esquema; FR-009 sólo agrega esa información al PDF, que ya la tiene disponible.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: En la pantalla de carga de Presupuesto, Venta y Compra, el Subtotal y Total de cada fila de ítems coinciden, dentro de 1 centavo, con lo que resulta de aplicarle a esa línea el Descuento General vigente — verificable con y sin descuento de línea propio.
- **SC-002**: El PDF de Presupuesto, Venta y Compra emitido con Descuento General cargado y sin bonificaciones de línea muestra el porcentaje del Descuento General en la columna "Bonif." de cada ítem, igual que lo hace Contagram, en el 100% de los casos probados.
- **SC-003**: El total final impreso en el PDF y el total mostrado a pie de página en la pantalla de carga no cambian respecto de su valor actual (no se corrige ni se altera ningún monto ya correcto) en ningún caso de prueba.
- **SC-004**: Cero comprobantes históricos requieren reprocesamiento: la corrección no toca datos ya guardados, sólo cómo se leen y muestran.
- **SC-005**: El PDF de una NC/ND con Descuento General cargado muestra el importe de ese descuento en su bloque de totales en el 100% de los casos probados, sin alterar lo que muestra la columna "%Bonif." de cada línea.

## Assumptions

- El comportamiento de referencia para "qué debería mostrar la columna Bonif." en Presupuesto/Venta/Compra es Contagram real, confirmado con capturas del usuario del 03/09/2026 (alta con 10% de Descuento General, ítems sin bonificación propia, columna Bonif. del PDF mostrando 10% en cada línea).
- El campo editable "Desc." (%) de cada fila, en la pantalla de carga, se mantiene mostrando sólo el descuento propio de línea (no el combinado) — así es como se ve también en Contagram real, según las mismas capturas.
- El "porcentaje efectivo combinado" de descuento de línea + Descuento General no es la suma aritmética de ambos porcentajes, sino el resultado de aplicar uno sobre el resultado del otro (composición multiplicativa), consistente con cómo ya lo calcula `CalculoComprobante` en el backend.
- Ningún comprobante ya guardado (histórico) tiene su monto final incorrecto: esta spec no incluye ninguna tarea de recálculo, backfill o corrección retroactiva de datos.
- Modo Descuento General "monto fijo" ($) se resuelve igual que hoy lo resuelve `CalculoComprobante`: convertido a un porcentaje efectivo equivalente sobre el subtotal bruto del comprobante, y ese mismo criterio es el que debe reflejar la fila individual.
- En NC/ND, el criterio "separado, no combinado" se basa en la documentación ya verificada de la spec 095 sobre el **formulario de carga** de Contagram. No hay capturas propias del **PDF final** de una NC/ND con Descuento General de Contagram real; se extiende el mismo criterio por consistencia interna (el formulario ya lo mantiene separado) y se documenta como asunción, no como hecho verificado con captura — si en el futuro aparece una captura del PDF real que contradiga esto, se ajusta puntualmente sin tocar el resto de la spec.
