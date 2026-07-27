# Feature Specification: Módulo Egresos (Compras · Gastos)

**Feature Branch**: `009-egresos-compras-gastos`

**Created**: 2026-07-25

**Status**: Draft

**Input**: Módulo Egresos con las dos pantallas del menú lateral: Compras (`/purchases`) y Gastos
(`/expenses`). Compras es el espejo estructural de Ventas (spec 008), con Proveedor en lugar de
Cliente. Gastos es un registro simple de erogaciones, sin equivalente en Ingresos. Fuente de verdad:
`docs/informe_contagram_egresos.md` (capturas 122-143) y `docs/documentacion_principal_crm.md §4`.

## Clarifications

### Session 2026-07-25

- Q: El informe describe el estado de Compra (Pagado/A Pagar) como "editable inline... con flecha
  desplegable" — ¿es un campo que el usuario puede forzar manualmente, o es siempre derivado de
  `pagado` vs. `a_pagar`? → A: Es siempre **derivado** de `pagado` vs. `a_pagar` (igual que Ventas); la
  flecha desplegable del listado real de Contagram es sólo un filtro rápido de UI, no un override
  persistido — no existe una columna `estado` independiente en `compras`.

## Contexto y fuentes

El módulo **Egresos** modela el flujo de dinero saliente del negocio. Dos pantallas:

- **Compras** (`/purchases`): compras a proveedores — genera pagos (contra cuentas de Tesorería),
  notas de crédito/débito, retenciones sufridas y remitos, con un documento (sin emisión fiscal real
  por ahora), igual que Ventas.
- **Gastos** (`/expenses`): erogaciones operativas de carga rápida (alquiler, sueldos, marketing,
  impuestos), sin vínculo a proveedores ni documento fiscal, con categorías propias jerárquicas.

**Fuente de verdad estructural**: `docs/informe_contagram_egresos.md` (capturas 122-143). **Dominio**:
`docs/documentacion_principal_crm.md §4` y `docs/modelo_datos.md §7`.

**Alcance de esta spec**: Compras + Gastos. **Fuera de alcance** (specs aparte o dependencias ya
aceptadas como pendientes, igual patrón que Ventas en spec 008):
- **Facturación Electrónica ARCA** (emisión real de CAE) — el Tipo de Comprobante y la numeración de
  Compras se guardan como **dato sin validez fiscal** (watermark "NO VÁLIDO COMO FACTURA"), igual que
  en Ventas. Se conecta cuando se construya ese módulo.
- **Remitos** (detalle de ítems): se implementa el enganche mínimo (crear remito con encabezado desde
  una compra), igual que en Ventas; el detalle completo queda pendiente de relevar.
- **Cuenta Corriente de proveedores**: el ítem "Cta Cte" del menú de fila de Compra queda como enlace
  pendiente (mismo gap ya aceptado en Proveedores/Clientes/Ventas), hasta su spec propia.
- **Recibos de Pagos**: sin relevamiento propio con capturas; fuera de alcance.

**Dependencia dura — Tesorería (spec 007)**: el "medio de pago" del modal de Pago de Compras y del
modal de Nuevo Gasto **son** las cuentas de Tesorería visibles (mismo catálogo que la Cobranza de
Ventas y Otros Ingresos, spec 008). Cada pago/gasto registra un movimiento de tesorería vía
`Services/Tesoreria/Tesoreria::registrarMovimiento()`. Esta spec asume que 007 está implementado.

**Dependencia — Proveedores (spec 003) y Categorías (tipo=compra)**: Compras reutiliza el buscador de
Proveedores y su Categoría de Compras por defecto, igual patrón que Ventas con Clientes.

## Clarificaciones incorporadas (decididas con el usuario)

- **Alcance de pantallas**: Compras + Gastos (sin Facturación Electrónica real, sin Cuenta Corriente,
  sin Remitos con detalle).
- **Facturación**: campos de comprobante de Compras sin emisión real (dato + watermark), igual que
  Ventas.
- **Medios de pago**: leen de Tesorería (spec 007), no un catálogo propio — mismo catálogo que Ventas/
  Otros Ingresos/Gastos.
- **Retenciones**: se registran del lado de Compras (modal "Nueva Retención" en la ficha de Compra),
  reutilizando la tabla `retenciones` ya documentada en spec 008/modelo_datos.md §5, ahora poblada
  también vía `pago_id`.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Crear una Compra y pagarla (Priority: P1)

Como usuario quiero registrar una compra a un proveedor (con productos, IVA por elegir, percepciones/
impuestos internos/intereses), guardarla y pagarla contra una cuenta de Tesorería, para registrar el
egreso y su impacto financiero.

**Why this priority**: es la operación central del módulo y el punto donde el dinero sale y toca
Tesorería — espejo de la Venta (spec 008), el corazón de Ingresos.

**Independent Test**: crear una compra a un proveedor con 1 producto, dejar el IVA en "Elegir" primero
(verificar que el panel muestra "Importe Neto No Gravado"), luego elegir 21% (verificar que pasa a
"Importe Neto Gravado"), guardar, abrir "Agregar Pago", pagar el total contra "Caja del Local";
verificar que la compra queda "Pagado", que se creó un movimiento de tesorería (pago, egreso) en esa
cuenta por el monto, y que el saldo de la cuenta bajó.

**Acceptance Scenarios**:

1. **Given** el listado de Compras, **When** entro, **Then** veo la barra de KPIs (Cantidad de Compras,
   Pagado, A Pagar, Vencido, Total Compras) y la tabla con las columnas relevadas (Estado, Id, Emisión,
   Vencimiento, Proveedor, Categoría, Subtotales, Total Compra, Pagado, A Pagar, Etiquetas, Medio de
   Pago vía scroll horizontal), con dos selectores de rango de fecha (Emisión y Vencimiento) y selector
   de columnas (CUIT, Servicio Desde/Hasta, Teléfono, Mail, entre otras). El badge de Estado (Pagado/A
   Pagar) es siempre **derivado** de `pagado` vs. `a_pagar` — no existe override manual (ver
   Clarifications).
2. **Given** el formulario "Nueva Compra" (página completa), **When** selecciono un proveedor con
   Categoría de Compras guardada en su ficha, **Then** el formulario autocompleta esa categoría
   (fidelidad al relevamiento §2.4).
3. **Given** el formulario, **When** agrego un producto a la grilla de ítems, **Then** la columna IVA
   queda en "Elegir" (sin preseleccionar, a diferencia de Ventas que autocompleta 21%) y el panel de
   totales muestra "Importe Neto No Gravado" hasta que se elija una alícuota, momento en que pasa a
   "Importe Neto Gravado"; puedo agregar Percepciones / Impuestos Internos / Intereses (N filas cada
   uno) y usar el campo **Contador** (mes de imputación en el IVA Compras, independiente de la fecha de
   emisión).
4. **Given** una compra guardada, **When** abro el Detalle (`/purchases/:id`), **Then** veo la barra de
   ecuación (Total Compra + ND − NC − Pagado = A Pagar), la sección Pagos con "+ Agregar Pago" y "+
   Agregar Retención", el documento con watermark "NO VÁLIDO COMO FACTURA", y la sección de Notas de
   Crédito/Débito.
5. **Given** el modal "Nuevo Pago", **When** lo completo (Monto precargado con el saldo pendiente, Elija
   Medio de Pago = cuenta de Tesorería visible, Nota), **Then** se registra el pago, genera un
   comprobante correlativo, actualiza el estado de la compra a "Pagado" cuando el pagado cubre el total,
   y se crea un movimiento de tesorería (`tipo=pago`, egreso) en la cuenta elegida.
6. **Given** el menú de fila de una compra, **When** lo abro, **Then** ofrece Ver/Editar/Ver Detalle/
   Agregar Pago/Crear NC-ND/Crear Remito/Cta Cte/Imprimir Detalle/Eliminar (Cta Cte puede quedar
   deshabilitado/pendiente, documentado).

---

### User Story 2 - Registrar retenciones sufridas en una Compra (Priority: P2)

Como usuario quiero registrar una retención que me practicó o que debo declarar al pagar a un
proveedor, para reflejarla en el detalle de la compra.

**Why this priority**: es el punto donde la Función Avanzada "Retenciones" (sin efecto visible en
Ventas/Cobranzas) se materializa; secundario respecto de US1 pero forma parte del flujo de pago
relevado con capturas.

**Independent Test**: sobre una compra, usar "+ Agregar Retención", elegir tipo "IVA", monto y N° de
comprobante; verificar que la retención queda listada en el detalle de la compra.

**Acceptance Scenarios**:

1. **Given** el detalle de una compra, **When** uso "+ Agregar Retención", **Then** el modal pide
   Fecha, Monto, Elija Tipo (Ganancias, IVA, Seguridad Social, Sellos, Ingresos Brutos por
   jurisdicción...), N°/comprobante y Descripción.
2. **Given** una retención guardada, **When** la reviso, **Then** queda vinculada a la compra vía
   `pago_id` (o sin pago asociado si se registra antes del pago), reutilizando la tabla `retenciones`
   ya documentada en spec 008.

---

### User Story 3 - Registrar un Gasto (Priority: P1)

Como usuario quiero registrar rápidamente un gasto operativo (alquiler, sueldos, marketing, impuestos)
por categoría/subcategoría, con su medio de pago, sin tener que asociarlo a un proveedor ni generar un
documento fiscal, para llevar el control de erogaciones simples.

**Why this priority**: junto con Compras completa el módulo Egresos; es el segundo módulo más simple
de toda la app (menú de fila de 3 opciones) pero de uso frecuente y no depende de Compras.

**Independent Test**: crear un gasto de $5.000 en categoría "Marketing" → subcategoría "Facebook Ads",
medio de pago "Banco Galicia"; verificar que aparece en el listado, que genera un movimiento de
tesorería (egreso) en esa cuenta, y que marcándolo "pendiente" NO impacta el saldo hasta conciliarse.

**Acceptance Scenarios**:

1. **Given** el listado de Gastos, **When** entro, **Then** veo (sin KPIs) las columnas Estado, Id,
   Emisión, Categoría, Subcategoría, Descripción, Medio de Pago, un único selector de fecha (Emisión) y
   un selector de columnas de sólo 6 opciones (Monto oculta por defecto).
2. **Given** el modal "Nuevo Gasto" (no es página completa), **When** lo completo (Fecha default hoy,
   Monto, Seleccionar Categoría con árbol propio de dos niveles y opciones "Crear Categoría de Gasto"/
   "Crear Subcategoría", Elija un medio de pago = cuenta de Tesorería, Descripción, checkbox "Marcar
   como pendiente"), **Then** al crear se cierra el modal, el listado se actualiza in place y, si no
   está pendiente, se registra un movimiento de tesorería (`tipo=gasto`, egreso) en la cuenta elegida.
3. **Given** un gasto marcado "pendiente", **When** se crea, **Then** queda en estado Pendiente y NO
   genera movimiento de tesorería hasta conciliarse.
4. **Given** el menú de fila, **When** lo abro, **Then** ofrece sólo Ver/Editar/Eliminar; hacer clic en
   el Id reabre el mismo modal en modo edición (no existe ficha de detalle propia).
5. **Given** la categoría de Gasto, **When** la administro, **Then** es un árbol independiente
   (Categoría→Subcategoría) del árbol de Categoría de Compras usado por Proveedores, aunque ambos
   reutilizan la tabla genérica `categorias` con `tipo` distinto (`gasto` vs. `compra`).

---

### User Story 4 - Notas de Crédito / Débito sobre una Compra (Priority: P3)

Como usuario quiero emitir una Nota de Crédito o Débito que ajuste una compra, para corregir o
complementar una operación con un proveedor.

**Why this priority**: ajuste contable menos frecuente; depende de que exista la Compra (US1). Se
especifica por fidelidad de pantalla, igual patrón que Ventas (spec 008, US4).

**Independent Test**: sobre una compra, crear una NC; verificar que la barra de ecuación de la compra
refleja la NC (Pagado/A Pagar).

**Acceptance Scenarios**:

1. **Given** una compra, **When** uso "Crear NC/ND", **Then** el sistema pide Tipo (Crédito/Débito),
   Documento que Ajusta, Fecha de Emisión, Monto, Tipo de comprobante (igual al de la compra) y
   Descripción — mismo patrón de wizard que Ventas (spec 008, FR-023).
2. **Given** una NC, **When** se guarda, **Then** resta en la barra de ecuación de la compra (Total +
   ND − NC − Pagado = A Pagar); una ND suma.

---

### Edge Cases

- **Guardar compra con doble clic / clic rápido**: no debe crear dos compras (misma idempotencia ya
  exigida en Ventas, spec 008 FR-008).
- **Pagar más que el total de la compra**: rechazar o limitar el pago al saldo pendiente.
- **Pago parcial**: la compra queda "Parcialmente pagada" (o el estado editable inline lo refleja); "A
  Pagar" refleja lo pendiente; sucesivos pagos van sumando hasta saldar.
- **Eliminar una compra pagada**: debe revertir (soft delete) también sus movimientos de tesorería
  asociados, para no dejar saldo fantasma (mismo principio que Ventas, spec 008 FR-017).
- **Cuenta de Tesorería oculta**: no aparece como medio de pago (en Compras ni en Gastos).
- **Ítem de compra sin IVA elegido al guardar**: permitido — queda "sin gravar" hasta que se edite; no
  bloquea el guardado (fiel al relevamiento: "Elegir" es el estado inicial, no un default forzado).
- **Gasto sin categoría**: la categoría es obligatoria (el "Seleccionar Categoría" del modal no permite
  guardar sin elegir una hoja del árbol Categoría/Subcategoría).
- **Proveedor sin Categoría de Compras configurada**: la compra se puede registrar igual, el campo
  Categoría queda vacío en vez de autocompletarse.

## Requirements *(mandatory)*

### Functional Requirements

**Compras**

- **FR-001**: El sistema MUST listar compras con la barra de KPIs (Cantidad de Compras, Pagado, A
  Pagar, Vencido, Total Compras) y las columnas relevadas (§2.1 del informe de Egresos), tabla
  DataTables server-side, con dos selectores de rango de fecha (Emisión y Vencimiento).
- **FR-002**: El sistema MUST permitir crear/editar una compra en un formulario de página completa
  (mismo patrón que Venta): proveedor (con autocompletado de Categoría de Compras por defecto),
  emisión, vto. del pago, servicio desde/hasta, campo **Contador** (mes de imputación IVA Compras),
  tipo de comprobante + numeración, tabla de ítems (producto, cant., precio, desc., subtotal, IVA,
  total), Nota Interna, y percepciones/impuestos internos/intereses (N filas cada uno).
- **FR-003**: Al agregar un ítem a la grilla de una compra, la columna IVA MUST quedar sin preseleccionar
  ("Elegir") — a diferencia de Venta, que autocompleta 21% —; mientras no se elija, el panel de totales
  MUST mostrar "Importe Neto No Gravado" en vez de "Importe Neto Gravado".
- **FR-004**: El sistema MUST calcular por ítem el subtotal, IVA (cuando esté elegido) y total, y en el
  pie el Subtotal sin/con descuento y el Total de la compra.
- **FR-005**: El sistema MUST permitir pagar una compra mediante el modal "Nuevo Pago", con Monto
  precargado con el saldo pendiente y **Elija Medio de Pago = cuentas de Tesorería visibles** (spec
  007, mismo catálogo que Ventas/Otros Ingresos/Gastos).
- **FR-006**: Cada pago MUST registrar un movimiento de tesorería (`tipo=pago`, origen = el Pago) en la
  cuenta elegida, actualizando su saldo, vía `Tesoreria::registrarMovimiento()`, y generar un
  comprobante correlativo (ej. "X 0001-00000005").
- **FR-007**: El sistema MUST soportar pagos parciales y múltiples pagos por compra; el estado de pago
  (A Pagar / Pagado) MUST derivarse siempre de `pagado` vs. `a_pagar` (sin campo de override manual,
  igual que Ventas) y "A Pagar" deriva de la suma de pagos menos NC más ND.
- **FR-008**: El Detalle de Compra MUST mostrar la barra de ecuación (Total Compra + ND − NC − Pagado =
  A Pagar), la tabla de Pagos con "+ Agregar Pago" y "+ Agregar Retención", el documento con watermark
  "NO VÁLIDO COMO FACTURA", y la sección de Notas de Crédito/Débito.
- **FR-009**: El menú de fila de compra MUST ofrecer Ver/Editar/Ver Detalle/Agregar Pago/Crear NC-ND/
  Crear Remito/Cta Cte/Imprimir Detalle/Eliminar (Cta Cte puede quedar deshabilitado/pendiente,
  documentado, igual patrón que Ventas).
- **FR-010**: Eliminar una compra con impacto contable MUST usar soft delete y revertir sus movimientos
  de tesorería asociados (sin saldo fantasma), mismo patrón que Ventas (spec 008 FR-017).
- **FR-011**: "Crear Remito" desde una compra MUST generar un remito con su encabezado vinculado a la
  compra (detalle interno mínimo; el relevamiento completo de Remitos queda pendiente, igual que en
  Ventas).
- **FR-012**: El sistema MUST permitir registrar una retención sobre una compra mediante el modal
  "Nueva Retención" (Fecha, Monto, Elija Tipo, N°/comprobante, Descripción), reutilizando la tabla
  `retenciones` (spec 008/modelo_datos.md §5) con `pago_id` seteado.

**Gastos**

- **FR-013**: El sistema MUST listar gastos sin barra de KPIs, con columnas Estado, Id, Emisión,
  Categoría, Subcategoría, Descripción, Medio de Pago, un único selector de fecha (Emisión) y selector
  de columnas de sólo 6 opciones (Emisión, Categoría, Subcategoría, Descripción, Medio de Pago, Monto —
  Monto oculta por defecto).
- **FR-014**: El sistema MUST permitir crear/editar un gasto mediante un **modal** (no página completa):
  Fecha (default hoy), Monto, Seleccionar Categoría (árbol jerárquico propio de dos niveles, con "Crear
  Categoría de Gasto" y "Crear Subcategoría", independiente del árbol de Categoría de Compras aunque
  comparta la tabla `categorias` con `tipo=gasto`), Elija un medio de pago (cuenta de Tesorería),
  Descripción, checkbox "Marcar como pendiente".
- **FR-015**: Un gasto no pendiente MUST registrar un movimiento de tesorería (`tipo=gasto`, egreso) en
  la cuenta elegida vía `Tesoreria::registrarMovimiento()`; uno "pendiente" NO impacta el saldo hasta
  conciliarse.
- **FR-016**: El menú de fila de gasto MUST ofrecer sólo Ver/Editar/Eliminar; hacer clic en el Id MUST
  reabrir el mismo modal de alta en modo edición ("Editar Gasto"), sin ficha de detalle propia.

**Notas de Crédito / Débito (Compras)**

- **FR-017**: El sistema MUST permitir crear NC/ND sobre una compra (Tipo, Documento que Ajusta, Fecha
  de Emisión, Monto, Tipo de comprobante igual al de la compra, Descripción), mismo patrón que Ventas
  (spec 008 FR-023).
- **FR-018**: Una NC MUST restar y una ND MUST sumar en la barra de ecuación de la compra (A Pagar).

**Transversal**

- **FR-019**: Todas las tablas MUST ser DataTables server-side; todas las altas/ediciones por modal
  Bootstrap + AJAX (salvo la página completa de Compra, excepción documentada igual que Presupuesto/
  Venta); notificaciones Toastr; selects dinámicos (proveedor, producto, categoría, cuenta) con
  Select2; PDFs (detalle de compra) en el modal compartido.
- **FR-020**: La Categoría de Compras (proveedor) y la Categoría de Gasto MUST reutilizar la tabla
  `categorias` existente (`tipo=compra` / `tipo=gasto`) con creación inline donde el relevamiento lo
  muestra, manteniéndose como taxonomías independientes entre sí.

### Key Entities *(include if feature involves data)*

- **Compra**: operación de compra a un proveedor. Espejo de la Venta: proveedor, categoría, fechas
  (emisión/vto. pago/servicio), campo Contador (mes de imputación IVA Compras), tipo/N° de comprobante
  (dato sin emisión), A Pagar / Pagado (derivados). Genera Pagos, Retenciones, NC/ND y Remitos. Soft
  delete.
- **Compra Item**: línea de concepto de una compra: producto (o descripción libre), cantidad, precio,
  descuento, IVA (sin preseleccionar), subtotales.
- **Pago**: un pago sobre una compra: fecha, monto, cuenta de Tesorería (medio de pago), nota, N° de
  comprobante autogenerado. Genera un movimiento de tesorería. Múltiples por compra (pagos parciales).
- **Gasto**: erogación operativa: fecha, monto, categoría/subcategoría de gasto (árbol propio), cuenta
  de Tesorería, descripción, pendiente (sí/no). Genera movimiento de tesorería si no está pendiente. Sin
  ficha de detalle propia.
- **Retención (Compras)**: retención sufrida al pagar a un proveedor: fecha, monto, tipo (Ganancias/
  IVA/Seguridad Social/Sellos/Ingresos Brutos por jurisdicción), N°/comprobante, descripción, vinculada
  a un Pago (reutiliza la tabla `retenciones` de spec 008).
- **Nota de Crédito/Débito (Compra)**: ajuste sobre una compra: tipo, fecha, monto, tipo de comprobante,
  descripción.
- **Remito (Compra)**: comprobante de recepción vinculado a una compra (encabezado en esta spec).

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Un usuario puede crear una compra de 1 producto y verla como documento en menos de 2
  minutos, con todos los totales (subtotal, IVA cuando corresponda, total) calculados correctamente en
  el 100% de los casos (test de cálculo).
- **SC-002**: Al pagar una compra contra una cuenta de Tesorería, el saldo de esa cuenta disminuye
  exactamente en el monto pagado, en el 100% de los pagos (invariante Egresos↔Tesorería, test).
- **SC-003**: El campo "A Pagar" de una compra siempre iguala Total + ND − NC − Pagado, para el 100% de
  las compras, con cualquier combinación de pagos parciales y notas (test).
- **SC-004**: Eliminar una compra pagada no deja ningún saldo fantasma en Tesorería (0 casos de
  movimiento de tesorería huérfano tras el soft delete).
- **SC-005**: Un gasto marcado "pendiente" no altera ningún saldo de Tesorería hasta conciliarse (0
  impactos prematuros).
- **SC-006**: Un usuario puede registrar un gasto completo (categoría, subcategoría, medio de pago) en
  menos de 1 minuto, sin salir del listado (modal, sin recarga de página).
- **SC-007**: El guardado de una compra por doble clic nunca crea dos compras (0 duplicados).

## Assumptions

- **Tesorería (spec 007) implementada**: existe el catálogo `cuentas_tesoreria` y el servicio
  `Tesoreria::registrarMovimiento()`. Si 009 se implementa antes de que 007 esté disponible, se bloquea
  hasta tenerlo (no se construye un catálogo de medios de pago paralelo — regla de oro).
- **Proveedores, Productos, Categorías**: ya existen (specs 001-003, 006) y se reutilizan (buscador
  Select2 de Proveedor, Categoría de Compras por defecto).
- **Sin ARCA**: tipo/N° de comprobante de Compras son datos; el documento lleva watermark "NO VÁLIDO
  COMO FACTURA". La numeración es una secuencia interna simple por tipo, igual que Ventas.
- **Cta Cte, Remitos (detalle), Recibos de Pagos, Facturación Electrónica real**: fuera de alcance; los
  puntos de UI que los invocan quedan como enlaces deshabilitados/pendientes documentados, sin construir
  una versión falsa (mismo patrón que Ventas, spec 008).
- **Retenciones**: reutiliza la tabla `retenciones` ya documentada en spec 008/modelo_datos.md §5; esta
  spec la completa poblándola también desde Compras (`pago_id`).
- **Categorías de Gasto**: usan la tabla genérica `categorias` con `tipo=gasto` y `categoria_padre_id`
  para la jerarquía (mismo mecanismo ya soportado por el modelo, sin tabla nueva).
- **Reglas de diseño obligatorias** (CLAUDE.md §1-5): aplican a todas las pantallas.

## Dependencias y relación con otros módulos

- **Depende de**: Tesorería (spec 007) — medios de pago y registro de movimientos; Proveedores/
  Productos/Categorías (specs 001-003, 006); tabla `retenciones` (documentada en spec 008, sin UI de
  administración propia todavía).
- **Habilita**: el informe Movimientos de Tesorería (spec 007) empieza a reflejar pagos y gastos reales;
  a futuro, Cuenta Corriente de proveedores, Facturación Electrónica, Informes de Compras/Gastos
  (§7 de `documentacion_principal_crm.md`).
- **Documentación a actualizar** (principio I): al cierre, marcar en `docs/documentacion_principal_crm.md
  §4` y `docs/modelo_datos.md §7` las entidades de Egresos como implementadas (hoy documentadas como
  "pendiente de implementar").
