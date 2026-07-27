# Feature Specification: Módulo Ingresos (Presupuestos · Ventas · Otros Ingresos)

**Feature Branch**: `008-ingresos-ventas-presupuestos`

**Created**: 2026-07-24

**Status**: Draft

**Input**: Módulo Ingresos con las tres pantallas base del menú lateral: Presupuestos (`/budgets`),
Ventas (`/sales`) y Otros Ingresos (`/incomes`). Flujo secuencial Presupuesto → Venta → Cobranza.
Fuente de verdad: `docs/informe_contagram_ingresos.md` (capturas 65-98) y `docs/documentacion_principal_crm.md §3`.

## Contexto y fuentes

El módulo **Ingresos** modela el flujo de dinero entrante del negocio. Tres pantallas:

- **Presupuestos** (`/budgets`): cotizaciones a clientes. Un presupuesto puede convertirse en Venta.
- **Ventas** (`/sales`): la operación central — genera cobranzas (contra cuentas de Tesorería), notas de
  crédito/débito, remitos, y comprobante (sin emisión fiscal real por ahora).
- **Otros Ingresos** (`/incomes`): ingresos de caja que no son ventas (aportes, préstamos, saldos
  iniciales), cobrados contra una cuenta de Tesorería.

**Fuente de verdad estructural**: `docs/informe_contagram_ingresos.md` (capturas 65-98). **Dominio**:
`docs/documentacion_principal_crm.md §3` y `docs/modelo_datos.md §5`.

**Alcance de esta spec**: Presupuestos + Ventas + Otros Ingresos. **Fuera de alcance** (specs aparte):
- **Abonos** (ventas recurrentes, feature avanzada con toggle) — spec futura.
- **Facturación Electrónica ARCA** (emisión real de CAE) — el Tipo de Comprobante A/B/C/E y la
  numeración se guardan como **dato sin validez fiscal** (watermark "NO VÁLIDO COMO FACTURA"), tal como
  la cuenta de prueba real de Contagram. Se conecta cuando se construya ese módulo.
- **Remitos** y **Recibos**: se relevaron sólo superficialmente; en esta spec se implementa el enganche
  mínimo (crear remito con encabezado desde una venta) y se deja el detalle para relevar con capturas.
- **Cuenta Corriente de clientes**: el ítem "Cta Cte" del menú de fila de Venta queda como enlace
  pendiente (mismo gap ya aceptado en Clientes), hasta su spec propia.

**Dependencia dura — Tesorería (spec 007)**: los "medios de cobro" de la Cobranza de Ventas y de Otros
Ingresos **son** las cuentas de Tesorería visibles. Cada cobro registra un movimiento de tesorería vía
`Services/Tesoreria/Tesoreria::registrarMovimiento()`. Esta spec asume que 007 está implementado.

## Clarificaciones incorporadas (decididas con el usuario)

- **Alcance de pantallas**: Presupuestos + Ventas + Otros Ingresos (Abonos aparte).
- **Facturación**: campos de comprobante sin emisión real (dato + watermark).
- **Medios de cobro**: leen de Tesorería (spec 007), no un catálogo propio.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Crear y gestionar Presupuestos (Priority: P1)

Como vendedor quiero armar un presupuesto para un cliente (con productos, descuentos, impuestos y
notas), guardarlo, verlo como documento imprimible y cambiar su estado, para cotizar antes de vender.

**Why this priority**: es el inicio del flujo comercial y entrega valor por sí solo (cotizar) aunque
Ventas no exista todavía. Es el MVP del módulo.

**Independent Test**: crear un presupuesto para un cliente con 1 producto, verificar el cálculo de
subtotales/descuento/IVA/total, que quede con estado Pendiente, verlo como documento y cambiarle el
estado a Aceptado.

**Acceptance Scenarios**:

1. **Given** el listado de Presupuestos, **When** entro, **Then** veo una barra de 5 KPIs (Ventas,
   Vencidos/Rechazados, Pendientes, Aceptados, Total Posibles) y la tabla con las columnas relevadas
   (Estado, Id, Emisión, Vencimiento, Cliente, Categoría, Nro., Subtotales, Descuento, Total,
   Etiquetas, Notas, Lista de Precios, Vendedor, Formas de Pago, Métodos de Envío).
2. **Given** el formulario "Nuevo Presupuesto" (página completa, dos columnas), **When** selecciono un
   cliente con Categoría de Ventas y Descuento por defecto en su ficha, **Then** el formulario
   autocompleta esa categoría y descuento (fidelidad al relevamiento §2.5).
3. **Given** el formulario, **When** agrego productos a la tabla de conceptos, **Then** cada fila
   calcula subtotal, IVA y total, y el pie recalcula Subtotal sin/con descuento, Descuento General (%)
   y Total; puedo agregar Percepciones / Impuestos Internos / Intereses (N filas cada uno).
4. **Given** un presupuesto guardado, **When** abro "Ver", **Then** navega a una página de documento
   imprimible (no modal) con conceptos, totales, formas de pago y métodos de envío.
5. **Given** el menú de fila (badge Estado ▾), **When** lo abro, **Then** ofrece Ver/Editar/Eliminar,
   cambio de estado (Pendiente/Rechazado/Aceptado), Crear Venta, y Ver/Imprimir/Enviar Presupuesto.
6. **Given** un presupuesto con fecha de validez pasada y estado Pendiente, **When** veo el listado,
   **Then** se refleja como Vencido (derivado, no un estado almacenado).

---

### User Story 2 - Crear una Venta y cobrarla (Priority: P1)

Como vendedor quiero crear una venta (directa o desde un presupuesto), elegir tipo de comprobante,
guardarla y cobrarla contra una cuenta de Tesorería, para registrar el ingreso y su impacto financiero.

**Why this priority**: es la operación central del negocio y el punto donde el dinero entra y toca
Tesorería. Junto con US1 forma el corazón del módulo.

**Independent Test**: crear una venta con 1 producto, tipo de comprobante B, guardar, abrir el modal de
Cobranza, cobrar el total contra "Caja del Local"; verificar que la venta queda Cobrada, que se creó un
movimiento de tesorería (cobro) en esa cuenta por el monto, y que el saldo de la cuenta subió.

**Acceptance Scenarios**:

1. **Given** un presupuesto, **When** uso "Crear Venta", **Then** navega al formulario de Venta
   pre-cargado (cliente, categoría, productos, notas, descuento) y agrega Tipo de Comprobante (A/B/C/E),
   N° de Comprobante autogenerado y Vto. del Cobro; el presupuesto queda marcado como convertido.
2. **Given** el formulario de Venta, **When** presiono "Cobrar", **Then** la venta se guarda y se abre
   el modal "Cobranza" con Total Venta / A Cobrar, campo "Cobrar" editable (cobro parcial) y la grilla
   de **medios de cobro = cuentas de Tesorería visibles**.
3. **Given** el modal de Cobranza, **When** elijo un medio de cobro, **Then** se registra el cobro, la
   venta queda Cobrada (o Parcialmente cobrada si el monto < total) y se genera un movimiento de
   tesorería (`tipo=cobro`, origen = ese Cobro) en la cuenta elegida, subiendo su saldo.
4. **Given** el listado de Ventas, **When** entro, **Then** veo las 19 columnas relevadas incluyendo
   "Creada Desde" (Presupuesto/Venta directa), A Cobrar, Cobrado y Medio de Cobro (con link a la cuenta
   de Tesorería).
5. **Given** una venta cobrada, **When** veo el Detalle (`/sales/:id`), **Then** muestra la barra de
   ecuación (Total + ND − NC = Cobrado → A Cobrar), la tabla de Cobranzas con "+ Agregar Cobranza", el
   documento imprimible con watermark "NO VÁLIDO COMO FACTURA", y la sección de Notas de Crédito/Débito.
6. **Given** el menú de fila de una venta, **When** lo abro, **Then** ofrece Ver/Editar/Eliminar,
   Agregar Cobranza / Crear NC-ND / Crear Remito / Cta Cte, y Ver Detalle (PDF) / Imprimir Detalle /
   Imprimir Ticket / Enviar Detalle / Enviar WhatsApp (los dos últimos y Cta Cte pueden estar
   deshabilitados/pendientes).

---

### User Story 3 - Registrar Otros Ingresos (Priority: P2)

Como usuario quiero registrar un ingreso de caja que no es una venta (aporte, préstamo, saldo inicial),
eligiendo categoría y la cuenta de Tesorería donde entra, para reflejarlo en el flujo financiero.

**Why this priority**: es el módulo más simple y completa el circuito de ingresos, pero es secundario
respecto de Presupuestos/Ventas. Depende de Tesorería igual que la Cobranza.

**Independent Test**: crear un "Otro Ingreso" de $500, categoría "Otros Ingresos", medio de cobro "Caja
General"; verificar que aparece en el listado, que genera un movimiento de tesorería en esa cuenta, y
que marcándolo "pendiente" NO impacta el saldo hasta cobrarse.

**Acceptance Scenarios**:

1. **Given** el listado de Otros Ingresos, **When** entro, **Then** veo 7 columnas (Estado, Id, Fecha,
   Categoría, Descripción, Medio de Cobro, Monto), sin selector de columnas ni "Analizar" ni acciones
   masivas (minimalista, fiel al relevamiento §4).
2. **Given** el modal "Nuevo Ingreso", **When** lo completo (Fecha, Monto, Categoría con "Crear
   Categoría de Ingreso", Medio de Cobro = cuenta de Tesorería, Descripción), **Then** al crear se
   registra el ingreso y, si no está "pendiente", un movimiento de tesorería en esa cuenta.
3. **Given** un ingreso con "Marcar como pendiente" tildado, **When** se crea, **Then** queda en estado
   Pendiente y NO genera movimiento de tesorería hasta que se lo concilie como cobrado.
4. **Given** el menú de fila, **When** lo abro, **Then** ofrece sólo Ver/Editar/Eliminar.
5. **Given** la categoría "Saldo" / "Saldo Inicial", **When** registro un ingreso con ella, **Then**
   sirve para cargar el saldo inicial de una cuenta (coherente con la doc oficial).

---

### User Story 4 - Notas de Crédito / Débito sobre una Venta (Priority: P3)

Como usuario quiero emitir una Nota de Crédito o Débito que ajuste una venta, opcionalmente afectando
stock, para corregir o complementar una operación.

**Why this priority**: ajuste contable menos frecuente; depende de que exista la Venta (US2) y toca
stock. P3 pero se especifica por fidelidad de pantalla.

**Independent Test**: sobre una venta, crear una NC que afecte stock trayendo un producto de la venta;
verificar que la barra de ecuación de la venta refleja la NC (Cobrado/A Cobrar) y que el stock del
producto se ajusta.

**Acceptance Scenarios**:

1. **Given** una venta, **When** uso "Crear NC/ND", **Then** un wizard de 2 pasos pide: Paso 1 —Tipo
   (Crédito/Débito), Documento que Ajusta, ¿Afecta Stock? (Sí: traer productos de la venta o elegir
   nuevos; No: sólo descripción); Paso 2 —Fecha de Emisión, Monto, Tipo de comprobante (igual al de la
   venta), Descripción, Impuestos.
2. **Given** una NC que afecta stock, **When** se guarda, **Then** ajusta el stock de los productos
   incluidos (movimiento de stock) y actualiza la barra de ecuación de la venta.
3. **Given** una ND, **When** se guarda, **Then** incrementa el monto adeudado de la venta en la barra
   de ecuación (Total + ND).

---

### Edge Cases

- **Guardar presupuesto con doble clic / clic rápido**: no debe crear dos presupuestos ni fallar con un
  error genérico (el relevamiento detectó una race condition en Contagram real §2.5 — evitarla).
- **Cobrar más que el total de la venta**: rechazar o limitar el cobro al saldo pendiente.
- **Cobro parcial**: la venta queda "Parcialmente cobrada"; el saldo A Cobrar refleja lo pendiente;
  sucesivas cobranzas van sumando hasta saldar.
- **Eliminar una venta cobrada**: debe revertir (soft delete) también sus movimientos de tesorería para
  no dejar saldo fantasma (principio III: documentos con impacto contable usan soft delete).
- **Convertir dos veces el mismo presupuesto a venta**: impedir (un presupuesto ya convertido no se
  reconvierte).
- **Cuenta de Tesorería oculta**: no aparece como medio de cobro.
- **Producto sin stock al vender**: permitido si la función "Ventas sin stock" está activa (deja stock
  negativo, §funciones avanzadas); si no, advertir. (La función avanzada en sí es otra spec; acá se
  respeta el flag si existe, con default permisivo coherente con el relevamiento.)
- **Cliente sin condición de IVA**: la venta se puede registrar (sin emisión fiscal real); el tipo de
  comprobante es un dato. Cuando llegue Facturación Electrónica, ahí sí se exigirá.

## Requirements *(mandatory)*

### Functional Requirements

**Presupuestos**

- **FR-001**: El sistema MUST listar presupuestos con la barra de 5 KPIs y las columnas relevadas
  (§2.1 del informe), tabla DataTables server-side.
- **FR-002**: El sistema MUST permitir crear/editar un presupuesto en un formulario de página completa a
  dos columnas, con: cliente, categoría de venta (con creación/edición inline), emisión/validez,
  servicio desde/hasta, lista de precios, tabla de conceptos (producto, cant., precio, desc., subtotal,
  IVA, total), notas (cliente/interna), formas de pago y métodos de envío (texto libre), etiquetas,
  descuento general (%), y percepciones/impuestos internos/intereses (N filas cada uno).
- **FR-003**: Al seleccionar un cliente con categoría de venta y descuento general por defecto, el
  formulario MUST autocompletar ambos (FR de fidelidad al relevamiento).
- **FR-004**: El sistema MUST calcular por concepto el subtotal, IVA y total, y en el pie el Subtotal
  sin/con descuento, el descuento general y el Total del presupuesto.
- **FR-005**: El presupuesto MUST tener estado Pendiente/Rechazado/Aceptado, cambiable desde el menú de
  fila; "Vencido" es derivado (validez pasada + Pendiente), no un estado almacenado.
- **FR-006**: "Ver" un presupuesto MUST navegar a una página de documento imprimible (no modal), con
  conceptos, totales, formas de pago y métodos de envío, y acciones Enviar/Imprimir/Exportar/Editar.
- **FR-007**: El menú de fila MUST ofrecer Ver/Editar/Eliminar, cambio de estado, Crear Venta, y
  Ver/Imprimir/Enviar Presupuesto.
- **FR-008**: El sistema MUST evitar la creación duplicada por doble clic al guardar (idempotencia del
  submit).

**Ventas**

- **FR-009**: "Crear Venta" desde un presupuesto MUST pre-cargar el formulario de venta con los datos
  del presupuesto y marcar el presupuesto como convertido (no reconvertible).
- **FR-010**: La venta MUST agregar Tipo de Comprobante (A/B/C/E), N° de Comprobante autogenerado y Vto.
  del Cobro; el Tipo y N° se guardan como **dato sin emisión fiscal** (watermark "NO VÁLIDO COMO
  FACTURA").
- **FR-011**: El sistema MUST permitir cobrar una venta mediante el modal "Cobranza", con Total/A
  Cobrar, monto a cobrar editable (parcial) y la grilla de medios de cobro = **cuentas de Tesorería
  visibles** (spec 007).
- **FR-012**: Cada cobro MUST registrar un movimiento de tesorería (`tipo=cobro`, origen = el Cobro) en
  la cuenta elegida, actualizando su saldo, vía `Tesoreria::registrarMovimiento()`.
- **FR-013**: El sistema MUST soportar cobros parciales y múltiples cobranzas por venta; el estado del
  cobro (Sin cobrar / Parcial / Cobrada) y "A Cobrar" derivan de la suma de cobros menos NC más ND.
- **FR-014**: El sistema MUST listar ventas con las 19 columnas relevadas, incluyendo Creada Desde, A
  Cobrar, Cobrado y Medio de Cobro (link a la cuenta), tabla DataTables server-side.
- **FR-015**: El Detalle de Venta MUST mostrar la barra de ecuación (Total + ND − NC = Cobrado → A
  Cobrar), la tabla de Cobranzas con "+ Agregar Cobranza", el documento imprimible con watermark, y la
  sección de Notas de Crédito/Débito.
- **FR-016**: El menú de fila de venta MUST ofrecer Ver/Editar/Eliminar, Agregar Cobranza / Crear NC-ND
  / Crear Remito / Cta Cte, y Ver Detalle (PDF en modal compartido) / Imprimir Detalle / Imprimir
  Ticket / Enviar Detalle / Enviar WhatsApp (Cta Cte y WhatsApp pueden quedar deshabilitados/pendientes,
  documentado).
- **FR-017**: Eliminar una venta con impacto contable MUST usar soft delete y revertir sus movimientos
  de tesorería asociados (sin saldo fantasma).
- **FR-018**: "Crear Remito" desde una venta MUST generar un remito con su encabezado vinculado a la
  venta (detalle interno mínimo; el relevamiento completo de Remitos queda pendiente).

**Otros Ingresos**

- **FR-019**: El sistema MUST listar Otros Ingresos con 7 columnas (Estado, Id, Fecha, Categoría,
  Descripción, Medio de Cobro, Monto), sin selector de columnas, sin "Analizar", sin acciones masivas.
- **FR-020**: El sistema MUST permitir crear/editar un Otro Ingreso por modal: Fecha, Monto, Categoría
  (tipo=ingreso, con "Crear Categoría de Ingreso"), Medio de Cobro (cuenta de Tesorería), Descripción,
  y checkbox "Marcar como pendiente".
- **FR-021**: Un Otro Ingreso no pendiente MUST registrar un movimiento de tesorería en la cuenta
  elegida; uno "pendiente" NO impacta el saldo hasta conciliarse como cobrado.
- **FR-022**: El menú de fila MUST ofrecer sólo Ver/Editar/Eliminar.

**Notas de Crédito / Débito**

- **FR-023**: El sistema MUST permitir crear NC/ND sobre una venta con un wizard de 2 pasos (Paso 1:
  Tipo, Documento que Ajusta, ¿Afecta Stock? con productos; Paso 2: Fecha, Monto, Tipo de comprobante,
  Descripción, Impuestos).
- **FR-024**: Una NC/ND que afecta stock MUST generar el movimiento de stock correspondiente sobre los
  productos incluidos; NC resta y ND suma en la barra de ecuación de la venta.

**Transversal**

- **FR-025**: Todas las tablas MUST ser DataTables server-side; todas las altas/ediciones por modal
  Bootstrap + AJAX (salvo las pantallas de página completa de Presupuesto/Venta, excepción documentada
  igual que Importar Datos); notificaciones Toastr; selects dinámicos (cliente, producto, categoría,
  cuenta) con Select2; PDFs (detalle de venta, presupuesto) en el modal compartido.
- **FR-026**: Categorías de venta e ingreso MUST reutilizar la tabla `categorias` existente (tipo=venta
  / tipo=ingreso) con creación inline donde el relevamiento lo muestra.

### Key Entities *(include if feature involves data)*

- **Presupuesto**: cotización a un cliente. Cliente, categoría, lista de precios, fechas
  (emisión/validez/servicio), estado (pendiente/rechazado/aceptado), notas, formas de pago, métodos de
  envío, descuento general, totales; ítems (conceptos) y conceptos extra (percepciones/impuestos
  internos/intereses); etiquetas. Puede vincularse a la Venta que generó.
- **Presupuesto Item / Venta Item**: línea de concepto: producto (o descripción libre), cantidad,
  precio, descuento, IVA, subtotales.
- **Venta**: operación de venta. Espejo del presupuesto + tipo/N° de comprobante (dato sin emisión),
  vto. de cobro, "creada desde" (presupuesto o directa), A Cobrar / Cobrado (derivados). Genera Cobros,
  NC/ND y Remitos. Soft delete.
- **Cobro (Cobranza)**: un cobro sobre una venta: fecha, monto, cuenta de Tesorería (medio de cobro),
  nota. Genera un movimiento de tesorería. Múltiples por venta (cobros parciales).
- **Otro Ingreso**: ingreso de caja no-venta: fecha, monto, categoría (ingreso), cuenta de Tesorería,
  descripción, pendiente (sí/no). Genera movimiento de tesorería si no está pendiente.
- **Nota de Crédito/Débito**: ajuste sobre una venta: tipo, ¿afecta stock? + ítems, fecha, monto, tipo
  de comprobante, descripción, impuestos.
- **Remito**: comprobante de entrega vinculado a una venta (encabezado en esta spec).
- **Etiqueta**: catálogo reutilizable, asociable a presupuestos y ventas.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Un vendedor puede crear un presupuesto de 1 producto y verlo como documento en menos de 2
  minutos, con todos los totales (subtotal, descuento, IVA, total) calculados correctamente en el 100%
  de los casos (verificable con test de cálculo).
- **SC-002**: Al cobrar una venta contra una cuenta de Tesorería, el saldo de esa cuenta aumenta
  exactamente en el monto cobrado, en el 100% de los cobros (invariante Ingresos↔Tesorería, test).
- **SC-003**: El campo "A Cobrar" de una venta siempre iguala Total + ND − NC − Cobrado, para el 100%
  de las ventas, con cualquier combinación de cobros parciales y notas (test).
- **SC-004**: Convertir un presupuesto en venta preserva el 100% de los datos (cliente, ítems,
  descuentos, notas) sin recarga de página intermedia perceptible.
- **SC-005**: Eliminar una venta cobrada no deja ningún saldo fantasma en Tesorería (0 casos de
  movimiento de tesorería huérfano tras el soft delete).
- **SC-006**: Un Otro Ingreso marcado "pendiente" no altera ningún saldo de Tesorería hasta conciliarse
  (0 impactos prematuros).
- **SC-007**: El guardado de un presupuesto por doble clic nunca crea dos presupuestos (0 duplicados).

## Assumptions

- **Tesorería (spec 007) implementada**: existe el catálogo `cuentas_tesoreria` y el servicio
  `Tesoreria::registrarMovimiento()`. Si 008 se implementa antes que 007, se bloquea hasta tenerlo (no
  se construye un catálogo de medios de cobro paralelo — regla de oro).
- **Sin ARCA**: tipo/N° de comprobante son datos; el documento lleva watermark "NO VÁLIDO COMO
  FACTURA". La numeración es una secuencia interna simple por tipo, reemplazable por la real luego.
- **Productos, Clientes, Categorías, Listas de Precio, Stock**: ya existen (specs 001-006) y se
  reutilizan (buscadores Select2, `movimientos_stock` para NC que afectan stock).
- **Abonos, Cta Cte, Retenciones, Recibos, WhatsApp, botón "Analizar" (IA)**: fuera de alcance; los
  puntos de UI que los invocan quedan como enlaces deshabilitados/pendientes documentados, sin construir
  una versión falsa.
- **Remitos**: sólo encabezado vinculado a la venta; el detalle se releva y especifica aparte.
- **Reglas de diseño obligatorias** (CLAUDE.md §1-5): aplican a todas las pantallas.

## Dependencias y relación con otros módulos

- **Depende de**: Tesorería (spec 007) — medios de cobro y registro de movimientos; Clientes/Productos/
  Categorías/Listas/Stock (specs 001-006).
- **Habilita**: el informe Movimientos de Tesorería (US5 de 007) empieza a reflejar cobros reales; a
  futuro, Cuenta Corriente de clientes, Abonos, Facturación Electrónica.
- **Documentación a actualizar** (principio I): al cierre, marcar en `docs/documentacion_principal_crm.md
  §3` y `docs/modelo_datos.md §5` las entidades de Ingresos como implementadas (hoy documentadas como
  "pendiente de implementar").
