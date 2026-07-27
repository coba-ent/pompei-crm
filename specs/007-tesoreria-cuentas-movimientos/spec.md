# Feature Specification: Módulo Tesorería (Cuentas y Movimientos)

**Feature Branch**: `007-tesoreria-cuentas-movimientos`

**Created**: 2026-07-24

**Status**: Draft

**Input**: User description: "Módulo Tesorería (Cuentas y Movimientos). Panel financiero centralizado single-tenant: pestaña Saldos (bloques A Cobrar, A Pagar, Disponible con Cajas y Bancos, saldo a fecha de corte), pestaña Movimientos (informe consolidado de flujo de caja por cuenta con secciones expandibles Cobros/Pagos y exportación a PDF), configuración de cuentas de tesorería (alta/edición/ocultar/eliminar, tipos A Cobrar/A Pagar/Banco/Efectivo, cuentas del sistema Cheque de Terceros y Cheque Propio no editables, saldo inicial con fecha), transferencias entre cuentas (Movimiento entre Cuentas, partida doble), y ficha individual de cada cuenta (ledger/libro mayor con saldo corrido, filtros por tipo de operación, selector de columnas, exportar). Fuente de verdad: docs/informe_contagram_tesoreria.md (capturas 144-162) y docs/modelo_datos.md. Es la base de la que dependen los medios de cobro de Ingresos (spec siguiente)."

## Contexto y fuentes

Este módulo es el **panel financiero centralizado** del CRM: consolida el estado de todas las cuentas
de dinero del negocio (cajas, bancos, y cuentas virtuales "A Cobrar" / "A Pagar") y permite mover
dinero entre ellas. Es la **base transversal** de la que dependen los medios de cobro/pago del resto
de la aplicación: Ingresos (Ventas, Otros Ingresos), y a futuro Egresos (Compras, Gastos).

**Fuente de verdad estructural**: `docs/informe_contagram_tesoreria.md` (relevamiento con capturas
reales 144-162). **Fuente de dominio**: `docs/documentacion_principal_crm.md` y `docs/modelo_datos.md`.

**Alcance de esta spec**: sólo el módulo Tesorería en sí — el CRUD de cuentas, las transferencias
internas, la vista de Saldos, la vista de Movimientos y la ficha (ledger) de cada cuenta. Los
movimientos que **originan** otros módulos (Cobros de Ventas, Pagos de Compras, Gastos) se **leen y
consolidan** acá, pero se **crean** desde esos módulos (fuera de alcance de esta spec, se conectan
cuando cada uno se construya). Esta spec deja el modelo de datos y el punto de enganche listos para
que Ingresos (spec siguiente) registre cobros contra una cuenta de tesorería.

## Clarificaciones incorporadas

- **Facturación Electrónica no está construida**: la columna "N° Factura" del ledger muestra el
  número de comprobante que el módulo de origen haya guardado como dato (ej. "B 0001-00000003"), sin
  validez fiscal real. No hay dependencia con ARCA en esta spec.
- **Cobros/Pagos/Gastos aún no existen** como módulos: el modelo de `movimientos_tesoreria` se diseña
  con un origen polimórfico para que esos módulos enganchen después; mientras tanto, los únicos
  movimientos que esta spec **crea** son Saldo Inicial (al alta de cuenta) y Movimiento entre Cuentas
  (transferencias). Los demás tipos de operación quedan modelados y visibles en filtros pero sin
  generadores propios todavía.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Ver el estado financiero consolidado (Saldos) (Priority: P1)

Como responsable del negocio quiero abrir Tesorería y ver de un vistazo cuánto dinero tengo disponible
(cajas y bancos), cuánto está pendiente de cobro y cuánto de pago, con la posibilidad de consultar el
saldo a una fecha de corte pasada.

**Why this priority**: es la pantalla de entrada del módulo y la que entrega valor inmediato aunque no
exista ninguna otra funcionalidad — permite responder "¿cuánta plata tengo?" sin depender de que
Ventas/Compras estén construidos. Es el MVP del módulo.

**Independent Test**: con cuentas pre-cargadas (seed) y sus saldos iniciales, entrar a la pestaña
Saldos y verificar que los bloques A Cobrar, A Pagar y Disponible (Cajas / Bancos) muestran los
subtotales y totales correctos, y que cambiar la fecha de corte recalcula los saldos.

**Acceptance Scenarios**:

1. **Given** cuentas de tesorería con saldos iniciales cargados, **When** entro a Tesorería, **Then**
   veo tres bloques —A Cobrar (verde), A Pagar (rojo), Disponible (celeste, con columnas Cajas y
   Bancos)— cada uno con su subtotal, y el bloque Disponible con un Total general en la cabecera.
2. **Given** la vista de Saldos, **When** elijo una fecha de corte pasada en "Buscar por Fecha",
   **Then** los saldos mostrados corresponden al estado de cada cuenta a esa fecha (sólo movimientos
   con fecha ≤ corte).
3. **Given** una cuenta bancaria cuyos egresos superan sus ingresos, **When** veo Saldos, **Then** esa
   cuenta puede mostrar saldo negativo sin bloqueo ni advertencia (se permite descubierto).
4. **Given** el bloque Disponible, **When** miro las columnas, **Then** las cuentas tipo Efectivo
   aparecen bajo "Cajas" y las tipo Banco bajo "Bancos", cada columna con su subtotal (Total Cajas /
   Total Bancos).

---

### User Story 2 - Administrar cuentas de tesorería (Priority: P1)

Como administrador quiero crear, editar, ocultar y eliminar cuentas de tesorería (con su tipo y saldo
inicial), para modelar las cuentas reales del negocio (cajas, bancos, tarjetas, cheques).

**Why this priority**: sin cuentas configuradas no hay nada que mostrar ni contra qué registrar
cobros/pagos. Es requisito de la User Story 1 y de todo el módulo Ingresos siguiente.

**Independent Test**: desde la configuración de cuentas, crear una cuenta nueva (tipo Efectivo, saldo
inicial $1.000, con fecha), verificar que aparece inmediatamente en la tabla de Ajustes y en el bloque
Disponible → Cajas de Saldos; editarla; ocultarla y verificar que desaparece de Saldos pero no de la
configuración; intentar eliminar una cuenta con movimientos y verificar que se bloquea.

**Acceptance Scenarios**:

1. **Given** la configuración de cuentas, **When** creo una cuenta nueva con Nombre, Tipo (A Cobrar /
   A Pagar / Banco / Efectivo), Saldo Inicial y Fecha, **Then** la cuenta se crea, se registra un
   movimiento de tipo "Saldo Inicial" por ese monto y fecha, y la cuenta aparece en la tabla de
   Ajustes agrupada bajo su tipo.
2. **Given** una cuenta existente, **When** la edito, **Then** puedo cambiar Nombre, Saldo Inicial,
   Fecha del saldo inicial y su visibilidad (Mostrar / Ocultar), pero **no** su Tipo (el tipo es fijo
   una vez creada).
3. **Given** una cuenta marcada como "Ocultar", **When** veo la pestaña Saldos y los selectores de
   cuenta (transferencias, medios de cobro), **Then** la cuenta oculta no aparece; **When** veo la
   configuración de cuentas, **Then** sí aparece (con su estado Visible/Oculto).
4. **Given** las cuentas del sistema "Cheque de Terceros" (tipo A Cobrar) y "Cheque Propio" (tipo A
   Pagar), **When** abro la configuración, **Then** aparecen marcadas como "(Cuenta del sistema)" y no
   se pueden editar ni eliminar.
5. **Given** una cuenta que ya tiene movimientos asociados (transferencias, cobros, etc.), **When**
   intento eliminarla, **Then** el sistema lo impide y explica que tiene operaciones; sólo se puede
   ocultar.
6. **Given** una cuenta sin ningún movimiento más que su Saldo Inicial, **When** la elimino, **Then**
   se elimina junto con su movimiento de saldo inicial.

---

### User Story 3 - Transferir dinero entre cuentas (Priority: P2)

Como usuario quiero registrar un movimiento de dinero de una cuenta a otra (ej. depositar la caja en
el banco, fondear una caja chica), viendo el saldo de cada cuenta al elegirla, sin que la operación
altere el total de dinero del negocio.

**Why this priority**: es la única operación de dinero "nativa" de Tesorería (las demás las originan
otros módulos). Aporta valor real (arqueo, depósitos, cartera de cheques) pero depende de que ya
existan cuentas (US2).

**Independent Test**: abrir "Movimiento entre Cuentas", transferir $500 de "Caja del Local" a "Caja
Chica Prueba", confirmar y verificar que la cuenta de salida baja $500, la de entrada sube $500, el
Total Disponible general no cambia, y ambas fichas de cuenta muestran el movimiento con su contraparte.

**Acceptance Scenarios**:

1. **Given** dos cuentas visibles, **When** abro "Movimiento entre Cuentas" y elijo cuenta de salida,
   cuenta de entrada, monto, fecha y observación, **Then** al confirmar se registra el movimiento, el
   saldo de la cuenta de salida disminuye y el de la de entrada aumenta en el monto indicado.
2. **Given** el selector de cuenta de salida/entrada, **When** lo abro, **Then** cada cuenta se muestra
   junto a su saldo actual, para decidir el origen sin salir del modal.
3. **Given** una transferencia registrada, **When** veo la ficha de cualquiera de las dos cuentas,
   **Then** el movimiento aparece con Operación "Movimiento entre Cuenta" y el nombre de la cuenta
   contraparte en "Detalles" (egreso en la de salida, ingreso en la de entrada).
4. **Given** una transferencia, **When** miro el Total Disponible del negocio antes y después, **Then**
   es idéntico (movimiento interno de partida doble: no crea ni destruye dinero).
5. **Given** el modal de transferencia, **When** elijo la misma cuenta como salida y entrada, o un
   monto ≤ 0, **Then** el sistema rechaza la operación con un mensaje claro.

---

### User Story 4 - Consultar la ficha (ledger) de una cuenta (Priority: P2)

Como usuario quiero abrir una cuenta puntual y ver su libro mayor: cada movimiento con su saldo
corrido (balance), pudiendo filtrar por tipo de operación y exportar el detalle.

**Why this priority**: es la vista de auditoría/extracto de una cuenta. Muy valiosa, pero se apoya en
US2/US3 (que existan cuentas y movimientos) y su valor pleno crece cuando Ventas/Compras aporten
cobros/pagos.

**Independent Test**: entrar a la ficha de una cuenta con saldo inicial + una transferencia, verificar
que la tabla muestra Id, Fecha, Operación, Detalles, Ingreso, Egreso, Balance (saldo corrido), N°
Factura y Observación, con el balance acumulado correcto fila a fila; filtrar por tipo de operación y
verificar que el resto se oculta pero el balance sigue siendo el histórico real.

**Acceptance Scenarios**:

1. **Given** una cuenta con varios movimientos, **When** abro su ficha, **Then** veo una tabla tipo
   extracto con columnas Id, Fecha, Operación, Detalles, Ingreso, Egreso, Balance, N° Factura,
   Observación, ordenada cronológicamente, con un balance corrido (saldo acumulado) por fila.
2. **Given** la ficha de cuenta, **When** filtro por "Tipo de Operación" (Cobro / Pago / Gasto /
   Movimiento entre Cuenta / Saldo Inicial), **Then** la tabla muestra sólo esos movimientos, sin que
   el filtro altere el saldo corrido real de la cuenta.
3. **Given** un movimiento originado en otro módulo (cobro de venta, pago de compra, gasto), **When**
   lo veo en la ficha, **Then** su columna Operación identifica el origen, "Detalles" muestra el
   cliente/proveedor/subcategoría y "N° Factura" el comprobante cuando corresponde.
4. **Given** un movimiento cuya contracara es una operación de otro módulo, **When** miro su menú de
   fila, **Then** sólo ofrece Editar y Eliminar (los movimientos nativos de Tesorería —Saldo Inicial y
   Movimiento entre Cuentas— se gestionan íntegramente acá).
5. **Given** la ficha de cuenta, **When** uso el selector de columnas o el botón Exportar, **Then**
   puedo mostrar/ocultar columnas y descargar el detalle.

---

### User Story 5 - Informe consolidado de flujo de caja (Movimientos) (Priority: P3)

Como responsable quiero un informe de todo el flujo de dinero del negocio en un rango de fechas: total
cobrado vs. total pagado y el resultado, desglosado por cuenta y con la posibilidad de incluir/excluir
cuentas puntuales, exportable a planilla y a PDF.

**Why this priority**: es un reporte de análisis. Aporta más valor cuando ya hay cobros y pagos reales
de otros módulos; hasta entonces refleja principalmente transferencias y saldos iniciales. Por eso es
P3, pero se especifica para no divergir de la estructura real de pantalla.

**Independent Test**: en la pestaña Movimientos elegir un rango de fechas y verificar el resumen (Total
Cobros, Total Pagos, Resultado), expandir las secciones Cobros y Pagos para ver el desglose por cuenta,
tildar/destildar una cuenta y ver el total recalcularse, y exportar a planilla y PDF.

**Acceptance Scenarios**:

1. **Given** la pestaña Movimientos con un rango de fechas, **When** la veo, **Then** muestra un
   resumen con Desde / Hasta / Total Cobros (verde) / Total Pagos (rojo) / Resultado (Cobros − Pagos).
2. **Given** el informe, **When** expando la sección "Cobros" o "Pagos", **Then** veo el desglose por
   cuenta de tesorería, cada fila con un checkbox "Activo" que la incluye/excluye del total, y el
   total se recalcula al instante al cambiar los checkboxes.
3. **Given** la definición del informe, **When** se computan los totales, **Then** Cobros = cobros de
   Ventas + Otros Ingresos; Pagos = pagos de Compras + Gastos, **excluyendo** los Gastos en estado
   "Pendiente"; Retenciones Sufridas se contemplan como concepto informado.
4. **Given** el informe, **When** uso los botones de exportación, **Then** puedo exportar a planilla y
   a PDF.

---

### Edge Cases

- **Fecha de corte anterior al saldo inicial de una cuenta**: la cuenta muestra saldo 0 (o no aparece)
  a una fecha previa a su alta; el sistema no debe romper ni mostrar saldos de movimientos futuros.
- **Cuenta oculta con saldo distinto de cero**: se puede ocultar; deja de sumar a los totales visibles
  de Saldos pero sus movimientos históricos permanecen y siguen apareviendo en su ficha.
- **Eliminar un Movimiento entre Cuentas desde la ficha**: debe revertir el efecto en **ambas** cuentas
  (las dos patas de la partida doble), no sólo en la cuenta desde la que se abrió la ficha.
- **Editar el monto/fecha de un Saldo Inicial**: recalcula el balance corrido de la cuenta desde esa
  fecha en adelante.
- **Saldo negativo**: permitido en cualquier cuenta, sin bloqueo (descubierto).
- **Transferencia con cuenta de salida = cuenta de entrada**: rechazada.
- **Monto de transferencia ≤ 0 o no numérico**: rechazado con mensaje en el modal.
- **Concurrencia**: dos movimientos sobre la misma cuenta no deben corromper el saldo corrido (el
  balance se calcula sobre el histórico, no se guarda un "saldo actual" mutable que se pise).

## Requirements *(mandatory)*

### Functional Requirements

**Cuentas de tesorería (configuración)**

- **FR-001**: El sistema MUST permitir crear cuentas de tesorería con Nombre, Tipo (uno de: A Cobrar,
  A Pagar, Banco, Efectivo), Saldo Inicial (monto) y Fecha del saldo inicial.
- **FR-002**: Al crear una cuenta con Saldo Inicial distinto de 0, el sistema MUST registrar un
  movimiento de tipo "Saldo Inicial" por ese monto y fecha, que es el primer asiento de su ledger.
- **FR-003**: El sistema MUST permitir editar Nombre, Saldo Inicial, Fecha del saldo inicial y
  visibilidad (Mostrar / Ocultar) de una cuenta existente.
- **FR-004**: El sistema MUST impedir cambiar el Tipo de una cuenta una vez creada.
- **FR-005**: El sistema MUST permitir ocultar una cuenta: una cuenta oculta no aparece en la vista de
  Saldos, ni en los selectores de cuenta (transferencias y medios de cobro/pago de otros módulos),
  pero sí en la configuración de cuentas y en su propia ficha.
- **FR-006**: El sistema MUST tener dos cuentas del sistema precargadas — "Cheque de Terceros" (tipo A
  Cobrar) y "Cheque Propio" (tipo A Pagar) — marcadas como "(Cuenta del sistema)", que no se pueden
  editar ni eliminar.
- **FR-007**: El sistema MUST impedir eliminar físicamente una cuenta que tenga movimientos asociados
  (más allá de su Saldo Inicial); en ese caso sólo se permite ocultarla.
- **FR-008**: El sistema MUST permitir eliminar una cuenta que no tenga más movimientos que su Saldo
  Inicial, borrando también ese movimiento.
- **FR-009**: La configuración de cuentas MUST mostrar las cuentas agrupadas por Tipo (Efectivo, Banco,
  A Cobrar, A Pagar), cada una con su estado de visibilidad.

**Vista Saldos**

- **FR-010**: El sistema MUST mostrar en la pestaña Saldos tres bloques: "A Cobrar" (cuentas tipo A
  Cobrar), "A Pagar" (cuentas tipo A Pagar) y "Disponible" (cuentas tipo Efectivo y Banco), cada bloque
  con su total.
- **FR-011**: El bloque "Disponible" MUST separar las cuentas en dos columnas — "Cajas" (Efectivo) y
  "Bancos" (Banco) — cada una con su subtotal (Total Cajas / Total Bancos), y un Total general.
- **FR-012**: El sistema MUST permitir consultar los saldos a una fecha de corte elegida ("Buscar por
  Fecha"), computando sólo los movimientos con fecha ≤ corte.
- **FR-013**: El sistema MUST permitir y mostrar saldos negativos sin bloqueo ni advertencia.
- **FR-014**: El saldo de cada cuenta MUST calcularse como Saldo Inicial + suma de ingresos − suma de
  egresos de sus movimientos hasta la fecha vigente (nunca un valor mutable almacenado que pueda
  desincronizarse).

**Transferencias (Movimiento entre Cuentas)**

- **FR-015**: El sistema MUST permitir registrar una transferencia interna con Fecha, Monto, cuenta de
  salida, cuenta de entrada y Observación.
- **FR-016**: Una transferencia MUST generar el efecto de partida doble: egreso en la cuenta de salida
  e ingreso en la cuenta de entrada por el mismo monto, sin alterar el total de dinero del negocio.
- **FR-017**: Los selectores de cuenta de salida y entrada MUST mostrar el saldo actual de cada cuenta
  junto a su nombre.
- **FR-018**: El sistema MUST rechazar una transferencia cuando la cuenta de salida es igual a la de
  entrada, o cuando el monto es ≤ 0 o no numérico, con un mensaje claro.
- **FR-019**: El botón "Movimiento entre Cuentas" MUST estar disponible tanto en la vista de Saldos
  como en la ficha individual de cada cuenta.

**Ficha de cuenta (ledger)**

- **FR-020**: El sistema MUST mostrar, al abrir una cuenta, un libro mayor con columnas Id, Fecha,
  Operación, Detalles, Ingreso, Egreso, Balance (saldo corrido), N° Factura y Observación.
- **FR-021**: El Balance de cada fila MUST ser el saldo acumulado de la cuenta hasta ese movimiento
  inclusive (saldo corrido cronológico).
- **FR-022**: La ficha MUST permitir filtrar por Tipo de Operación (Cobro, Pago, Gasto, Movimiento
  entre Cuenta, Saldo Inicial) sin que el filtro altere el saldo corrido real de la cuenta.
- **FR-023**: La ficha MUST clasificar cada movimiento por su Operación de origen y mostrar en
  "Detalles" el dato correspondiente (contraparte de la transferencia, cliente del cobro, proveedor del
  pago, subcategoría del gasto) y en "N° Factura" el comprobante cuando exista.
- **FR-024**: El menú de fila de un movimiento MUST ofrecer Editar y Eliminar; eliminar un Movimiento
  entre Cuentas MUST revertir el efecto en ambas cuentas involucradas.
- **FR-025**: La ficha MUST ofrecer selector de columnas y exportación del detalle, y un rango de
  fechas (por defecto el último mes).

**Informe Movimientos (flujo de caja)**

- **FR-026**: El sistema MUST mostrar en la pestaña Movimientos un resumen por rango de fechas con
  Total Cobros, Total Pagos y Resultado (Cobros − Pagos).
- **FR-027**: El informe MUST desglosar Cobros y Pagos por cuenta de tesorería en secciones
  expandibles, cada fila con un checkbox "Activo" que la incluye/excluye del total, recalculando en
  vivo.
- **FR-028**: La definición de los totales MUST ser: Cobros = cobros de Ventas + Otros Ingresos; Pagos
  = pagos de Compras + Gastos **excluyendo los Gastos en estado "Pendiente"**. (Los generadores de esos
  movimientos son otros módulos; esta spec sólo define cómo se consolidan y computan cuando existan.)
- **FR-029**: El informe MUST permitir exportar a planilla y a PDF.

**Consolidación con otros módulos (punto de enganche)**

- **FR-030**: El modelo de movimientos de tesorería MUST admitir un origen polimórfico, de modo que
  módulos futuros (Cobros de Ventas, Pagos de Compras, Gastos, Otros Ingresos) registren su impacto en
  el saldo de una cuenta sin que Tesorería tenga que conocer cada módulo. Esta spec no construye esos
  generadores, sólo el modelo y las vistas que los consolidan.
- **FR-031**: El dashboard de Inicio MUST poder leer del mismo estado de Tesorería (Total Disponible /
  Cajas / Bancos y últimos movimientos) sin duplicar el cálculo. (La construcción del dashboard puede
  quedar fuera de esta spec; el requisito es que Tesorería exponga ese estado de forma reutilizable.)

### Key Entities *(include if feature involves data)*

- **Cuenta de Tesorería**: una cuenta de dinero del negocio. Atributos: nombre, tipo (A Cobrar / A
  Pagar / Banco / Efectivo), visible/oculta, es del sistema (no editable/eliminable), saldo inicial y
  su fecha. Su saldo actual es derivado de sus movimientos, no un valor almacenado. Las cuentas
  Efectivo se agrupan como "Cajas" y las Banco como "Bancos" en la vista de Disponible.
- **Movimiento de Tesorería**: un asiento en el ledger de una cuenta. Atributos: cuenta, fecha, tipo de
  operación (Saldo Inicial, Movimiento entre Cuenta, Cobro, Pago, Gasto), monto con su signo
  (ingreso/egreso), detalles/contraparte, N° de comprobante (opcional), observación, y un origen
  polimórfico opcional hacia la operación que lo generó en otro módulo. Una transferencia produce dos
  movimientos vinculados (partida doble). El balance corrido se calcula sobre el histórico.
- **Tipo de Cuenta**: clasificación fija (A Cobrar / A Pagar / Banco / Efectivo) que determina en qué
  bloque de Saldos aparece la cuenta.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Un usuario puede saber cuánto dinero disponible tiene el negocio (Total Disponible,
  Cajas y Bancos) en menos de 5 segundos desde que entra a Tesorería, sin pasos intermedios.
- **SC-002**: El total de dinero del negocio (suma de todas las cuentas Disponible) permanece idéntico
  antes y después de cualquier transferencia interna, en el 100% de los casos (invariante de partida
  doble verificable con test).
- **SC-003**: El saldo mostrado para cualquier cuenta coincide exactamente con Saldo Inicial + ingresos
  − egresos de su ledger a la fecha de corte, en el 100% de las cuentas (verificable con test de
  cálculo de saldos).
- **SC-004**: Ninguna cuenta con movimientos asociados puede eliminarse físicamente (0 casos de
  pérdida de historial financiero por borrado).
- **SC-005**: El balance corrido de la ficha de una cuenta es consistente fila a fila (cada Balance =
  Balance anterior + Ingreso − Egreso) para el 100% de los movimientos, independientemente de los
  filtros aplicados en pantalla.
- **SC-006**: Un usuario puede completar una transferencia entre dos cuentas en menos de 30 segundos,
  viendo el saldo de origen sin salir del modal.
- **SC-007**: Las cuentas del sistema (Cheque de Terceros, Cheque Propio) nunca pueden editarse ni
  eliminarse (0 casos), garantizando el circuito de cheques.

## Assumptions

- **Single-tenant**: no hay `empresa_id`; hay un único conjunto de cuentas de tesorería para el negocio
  (coherente con la constitución y `docs/modelo_datos.md`).
- **Seed de cuentas reales**: se precargan las cuentas observadas en el relevamiento (Caja del Local,
  Caja General como Efectivo; Banco Galicia, Banco Santander Río, Mercado Pago como Banco; AMEX, VISA,
  Cheque de Terceros como A Cobrar; Cheque Propio, VISA Corporativa como A Pagar), más las dos del
  sistema. Los nombres exactos y el conjunto inicial se confirman contra el informe; el usuario puede
  ajustarlos desde la configuración de cuentas.
- **Los movimientos que originan Ventas/Compras/Gastos no se crean en esta spec**: se modela el enganche
  (origen polimórfico) y las vistas los consolidan, pero sus generadores llegan con cada módulo. Hasta
  entonces, el informe Movimientos reflejará principalmente Saldos Iniciales y Transferencias.
- **Sin ARCA**: la columna N° Factura muestra el dato de comprobante que guarde el módulo de origen,
  sin emisión ni validación fiscal (Facturación Electrónica fuera de alcance).
- **Cheques**: el circuito de cheques (recibido en cobranza → depósito) se modela vía las cuentas del
  sistema Cheque de Terceros / Cheque Propio y transferencias entre cuentas; el detalle operativo del
  cheque (número, fecha de depósito) vive en la observación del movimiento, según el relevamiento.
- **Reglas de diseño obligatorias** (CLAUDE.md): tablas con DataTables server-side, altas/ediciones por
  modales Bootstrap + AJAX (sin recargar), notificaciones Toastr, selects dinámicos con Select2, PDFs en
  el modal compartido. Aplican a todas las pantallas de este módulo.

## Dependencias y relación con otros módulos

- **Depende de**: nada nuevo — se apoya en el núcleo existente (usuarios). Es un módulo base.
- **Habilita**: el módulo **Ingresos** (spec 008 siguiente) usará el catálogo de cuentas visibles como
  "medios de cobro" y registrará cobros de Ventas y Otros Ingresos como movimientos de tesorería. A
  futuro, Egresos (Compras, Gastos) hará lo propio con pagos.
- **Documentación a actualizar** (constitución, principio I): al cerrar esta spec se documentan en
  `docs/documentacion_principal_crm.md` (nueva sección Tesorería) y `docs/modelo_datos.md` las tablas
  `cuentas_tesoreria` y `movimientos_tesoreria` (hoy listadas como descartadas/pendientes).
