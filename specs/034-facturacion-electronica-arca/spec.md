# Feature Specification: Facturación Electrónica (ARCA/AFIP)

**Feature Branch**: `034-facturacion-electronica-arca`

**Created**: 2026-08-02

**Status**: Draft

**Input**: User description: "Módulo Facturación Electrónica (ARCA/AFIP). Alcance: emisión real de comprobantes electrónicos (Factura A/B/C/E, Notas de Crédito/Débito) desde Ventas y Compras, vía WSAA (autenticación con certificado propio del negocio) + WSFEv1 (emisión y obtención de CAE). Reemplaza la numeración sin validez fiscal actual de Ventas (spec 008) y Compras (spec 030) por comprobantes reales con CAE, vencimiento de CAE, y PDF fiscal imprimible vía el modal-pdf compartido."

## Clarifications

### Session 2026-08-02

- Q: Los comprobantes ya emitidos con la numeración sin validez fiscal (Ventas spec 008, Compras spec 030) antes de activar este módulo, ¿qué pasa con ellos? → A: Quedan como están; no hay re-emisión retroactiva — el CAE sólo aplica a comprobantes nuevos emitidos desde que el módulo está activo. (Aparte, y fuera del alcance de esta spec, el usuario decidió purgar los registros de prueba de Ventas/Compras existentes en local y VPS antes de empezar a usar el módulo en serio — ver tarea operativa separada, no forma parte de los requisitos funcionales.)
- Q: Con varios Puntos de Venta habilitados, ¿cómo se elige cuál usa cada Venta/Compra al emitir? → A: Un único Punto de Venta activo por defecto configurado en Configuración & Ajustes; todas las Ventas lo usan automáticamente, sin selector en el formulario.
- Q: Ante un error transitorio de ARCA (timeout, servicio caído) durante la emisión, ¿el reintento es automático o manual? → A: Manual — el sistema muestra el error (FR-010) y el usuario decide cuándo reintentar volviendo a confirmar el cobro/guardado; no hay reintentos automáticos con backoff.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Emitir una Venta con CAE real (Priority: P1)

Como usuario que cobra una Venta, al confirmar el cobro quiero que el sistema solicite el CAE a ARCA
automáticamente (en vez de guardar sólo un número de comprobante sin validez fiscal), para poder
entregarle al cliente una Factura A/B/C/E legalmente válida.

**Why this priority**: Es el corazón del módulo — sin esto, Facturación Electrónica no existe; todo lo
demás (NC/ND, Compras, reintentos) depende de que la emisión básica funcione.

**Independent Test**: Cobrar una Venta con un cliente y productos cargados; verificar que el comprobante
resultante tiene CAE, fecha de vencimiento de CAE y numeración real de ARCA (punto de venta + número
correlativo asignado por el webservice, no autogenerado localmente), y que el PDF ya no muestra el
watermark "NO VÁLIDO COMO FACTURA".

**Acceptance Scenarios**:

1. **Given** una Venta en estado "A Cobrar" con Tipo de Comprobante B y cliente Consumidor Final,
   **When** el usuario confirma el cobro, **Then** el sistema autentica contra WSAA (o reutiliza un
   ticket de acceso vigente), solicita CAE vía WSFEv1 para el próximo número correlativo del punto de
   venta configurado, y guarda CAE + vencimiento de CAE en el comprobante.
2. **Given** una Venta a un cliente con CUIT y Condición de IVA Responsable Inscripto,
   **When** el usuario confirma el cobro con Tipo de Comprobante A, **Then** el comprobante se emite
   como Factura A con los datos fiscales del cliente incluidos en la solicitud a WSFEv1.
3. **Given** un comprobante ya emitido con CAE, **When** el usuario abre "Ver Detalle" desde el menú de
   fila de Ventas, **Then** el PDF se abre en el modal `modal-pdf.blade.php` compartido, sin watermark,
   mostrando CAE, vencimiento de CAE y código de barras/QR fiscal.

---

### User Story 2 - Emitir una Compra con CAE real (Priority: P2)

Como usuario que registra una Compra a un Proveedor, quiero poder cargar el comprobante fiscal recibido
del proveedor (o, si el proveedor factura mediante este mismo CRM en otro contexto, emitir el
comprobante correspondiente), de forma que el circuito de Compras quede alineado con Ventas y el informe
al Contador refleje comprobantes con validez fiscal real.

**Why this priority**: Compras ya tiene el campo Tipo de Comprobante + numeración (spec 030) esperando
esta conexión, pero el negocio puede operar con Ventas facturando en regla antes de resolver el circuito
de Compras — por eso es P2, no P1.

**Independent Test**: Registrar una Compra con Tipo de Comprobante A y validar que el comprobante queda
identificado con los datos fiscales cargados (CAE si aplica al circuito elegido, ver Assumptions), y que
el documento imprimible de la Compra deja de mostrar el watermark "NO VÁLIDO COMO FACTURA" cuando el
comprobante es válido.

**Acceptance Scenarios**:

1. **Given** una Compra nueva con Proveedor y Tipo de Comprobante A cargados, **When** el usuario guarda
   la Compra, **Then** el sistema registra el comprobante con los datos fiscales provistos y el
   documento imprimible dejar de mostrar el watermark "NO VÁLIDO COMO FACTURA".

---

### User Story 3 - Emitir Notas de Crédito/Débito con CAE (Priority: P2)

Como usuario que ajusta una Venta ya facturada, quiero que la Nota de Crédito/Débito generada desde el
wizard de 2 pasos (ya implementado en spec 008) también obtenga CAE real de ARCA, referenciando el
comprobante original, para que el ajuste sea fiscalmente válido igual que la factura que corrige.

**Why this priority**: Depende de que exista ya un comprobante original con CAE (User Story 1); es una
extensión natural pero no bloquea el valor central de emitir facturas.

**Independent Test**: Sobre una Venta con Factura B ya emitida con CAE, crear una NC de tipo Crédito
que afecta stock; verificar que la NC obtiene su propio CAE, referenciando el CAE/número del comprobante
original en la solicitud a WSFEv1.

**Acceptance Scenarios**:

1. **Given** una Venta con Factura B y CAE ya emitido, **When** el usuario completa el wizard "Crear
   NC/ND" (Paso 1 y Paso 2) y confirma, **Then** el sistema solicita CAE para la Nota de Crédito/Débito
   vía WSFEv1, referenciando el comprobante asociado que ajusta, y el documento resultante muestra su
   propio CAE y vencimiento.

---

### User Story 4 - Manejo de errores y caída del servicio ARCA (Priority: P1)

Como usuario que está cobrando una Venta en el momento, si ARCA/WSFEv1 no responde o rechaza la
solicitud (por certificado vencido, datos fiscales inválidos del cliente, o caída del servicio), quiero
un mensaje claro que me diga qué pasó y qué puedo hacer, sin perder la carga de la Venta ni dejarla en
un estado ambiguo (cobrada pero sin comprobante fiscal).

**Why this priority**: Sin este comportamiento el módulo es riesgoso de usar en producción — un fallo
silencioso o una Venta cobrada sin CAE válido es peor que no tener el módulo.

**Independent Test**: Simular un rechazo de WSFEv1 (dato fiscal inválido) al confirmar el cobro de una
Venta; verificar que la Venta no queda marcada como "Cobrada"/facturada, que se muestra un toast de error
con el motivo devuelto por ARCA, y que el usuario puede corregir el dato y reintentar sin duplicar el
comprobante.

**Acceptance Scenarios**:

1. **Given** WSFEv1 rechaza la solicitud por un error de datos (ej. CUIT inválido para el tipo de
   comprobante elegido), **When** el usuario confirma el cobro, **Then** el sistema muestra un toast de
   error con el motivo, la Venta permanece en estado "A Cobrar" sin comprobante emitido, y no se
   consume numeración del punto de venta.
2. **Given** WSAA/WSFEv1 no responde (timeout o servicio caído), **When** el usuario confirma el cobro,
   **Then** el sistema muestra un toast de error indicando que ARCA no está disponible y sugiere
   reintentar, sin dejar la Venta en un estado intermedio.
3. **Given** el certificado ARCA del negocio está vencido o el Ticket de Acceso no pudo renovarse,
   **When** cualquier usuario intenta emitir un comprobante, **Then** el sistema muestra un aviso
   explícito de "certificado ARCA vencido/no configurado" y no permite continuar con la emisión (la
   Venta puede guardarse como "A Cobrar" pendiente de facturar más tarde).

---

### Edge Cases

- ¿Qué pasa si dos usuarios confirman el cobro de dos Ventas al mismo tiempo? El número correlativo del
  punto de venta lo asigna WSFEv1 en cada solicitud individual — el sistema no debe pre-asignar
  numeración local; si hay contención, el segundo pedido simplemente recibe el siguiente número que
  ARCA entregue.
- ¿Qué pasa si se emite el comprobante en ARCA pero la respuesta se pierde antes de guardarse en el CRM
  (timeout de red después de que WSFEv1 ya asignó CAE)? El sistema debe poder consultar el estado de un
  comprobante ya emitido contra ARCA (`FECompConsultar` o equivalente) antes de reintentar, para evitar
  emitir dos veces el mismo comprobante.
- ¿Qué pasa si el negocio no tiene aún el certificado ARCA cargado? El módulo debe permitir configurar
  el certificado y quedar deshabilitado (no oculto) hasta que esté cargado — Ventas/Compras siguen
  operando con la numeración sin validez fiscal actual como fallback.
- ¿Qué pasa con un cliente/proveedor sin CUIT válido y Tipo de Comprobante A? El sistema debe bloquear
  la emisión con un mensaje claro antes de llamar a WSFEv1 (Factura A exige CUIT del receptor).
- ¿Qué pasa si el usuario cambia el Tipo de Comprobante de una Venta ya emitida con CAE? No debe
  permitirse — un comprobante con CAE es inmutable; cualquier corrección se hace vía NC/ND.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: El sistema DEBE permitir configurar uno o más Puntos de Venta habilitados en ARCA
  (número de punto de venta, tipo — Web Service —) desde Configuración & Ajustes, marcando exactamente
  uno como "activo por defecto". Las Ventas y Compras usan siempre ese Punto de Venta por defecto al
  emitir — no hay selector de Punto de Venta en el formulario de Venta/Compra.
- **FR-002**: El sistema DEBE permitir cargar el certificado ARCA del negocio (par `.crt`/`.key` y CUIT
  asociado) y almacenarlo de forma segura (no en texto plano en base de datos ni en el repositorio).
- **FR-003**: El sistema DEBE autenticarse contra WSAA obteniendo un Ticket de Acceso (token +
  signature), reutilizándolo mientras esté vigente y renovándolo automáticamente al vencer, sin
  intervención manual del usuario en cada emisión.
- **FR-004**: El sistema DEBE, al confirmar el cobro de una Venta con Tipo de Comprobante A/B/C, solicitar
  CAE vía WSFEv1 (`FECAESolicitar` o equivalente) para el punto de venta y tipo de comprobante
  correspondientes, usando los datos fiscales del cliente y el detalle de ítems/IVA de la Venta.
- **FR-005**: El sistema DEBE guardar en el comprobante emitido: CAE, fecha de vencimiento de CAE,
  número de comprobante real asignado por ARCA (punto de venta + número correlativo) y el resultado
  crudo de la respuesta del webservice (para auditoría/soporte).
- **FR-006**: El sistema DEBE dejar de mostrar el watermark "NO VÁLIDO COMO FACTURA" en el PDF de un
  comprobante que tiene CAE válido, manteniéndolo en cualquier comprobante sin CAE (fallback actual).
- **FR-007**: El sistema DEBE generar el PDF fiscal del comprobante (incluyendo CAE, vencimiento de CAE
  y el código de barras/QR fiscal exigido por ARCA) y exponerlo a través del modal `modal-pdf.blade.php`
  compartido (`window.AppPdf.abrir`), consistente con el resto del CRM.
- **FR-008**: El sistema DEBE solicitar CAE también para Notas de Crédito/Débito generadas desde el
  wizard existente de Ventas (spec 008), referenciando el comprobante original que ajustan.
- **FR-009**: El sistema DEBE bloquear la emisión (antes de llamar a WSFEv1) y mostrar un mensaje claro
  cuando el cliente/proveedor no tenga los datos fiscales mínimos requeridos por el Tipo de Comprobante
  elegido (ej. CUIT válido para Factura A).
- **FR-010**: El sistema DEBE, ante un rechazo o error transitorio de ARCA (WSAA o WSFEv1, incluyendo
  timeout o servicio caído), mostrar el motivo mediante un toast de error y dejar la Venta/Compra en el
  estado previo a la emisión (sin comprobante fiscal asignado). El sistema NO DEBE reintentar
  automáticamente — el reintento es siempre una acción manual del usuario (volver a confirmar el
  cobro/guardado), sin duplicar numeración.
- **FR-011**: El sistema DEBE permitir consultar contra ARCA el estado de un comprobante potencialmente
  emitido antes de reintentar una emisión, para evitar duplicar comprobantes ante fallos de red después
  de que ARCA ya asignó CAE.
- **FR-012**: El sistema DEBE impedir modificar el Tipo de Comprobante, cliente/proveedor o ítems de un
  comprobante que ya tiene CAE asignado (comprobante inmutable una vez emitido).
- **FR-013**: El sistema DEBE registrar en un log de auditoría cada solicitud a WSAA/WSFEv1 (éxito o
  error), incluyendo timestamp, usuario que la disparó, comprobante afectado y respuesta recibida.
- **FR-014**: El sistema DEBE permitir operar Ventas y Compras con la numeración sin validez fiscal
  actual (fallback existente, spec 008/030) cuando el certificado ARCA no esté configurado o el punto de
  venta no esté habilitado, sin bloquear el resto del CRM.
- **FR-015**: El sistema DEBE registrar, para cada Compra con comprobante recibido de un Proveedor, los
  datos fiscales del comprobante (Tipo, Punto de Venta, Número, CAE si el proveedor lo provee) sin
  requerir que el propio negocio solicite un CAE por una operación de compra (el CAE de una Compra lo
  emite el Proveedor, no este CRM) — ver Assumptions.
- **FR-016**: El sistema DEBE avisar proactivamente cuando el certificado ARCA del negocio esté por
  vencer (dentro de una ventana configurable, ej. 30 días antes), para que se pueda renovar antes de
  que falle la emisión — conectando con el futuro módulo de Notificaciones ya anotado en
  `documentacion_principal_crm.md` §7; hasta que ese módulo exista, el aviso mínimo es un banner visible
  en la pantalla de Configuración de Facturación Electrónica.

### Key Entities *(include if feature involves data)*

- **PuntoVenta**: Punto de venta habilitado en ARCA para el negocio (número, descripción, tipo de
  Web Service asociado, activo/inactivo, flag "por defecto" — exactamente uno activo a la vez).
- **CertificadoFiscal**: Certificado ARCA del negocio (CUIT, referencia al almacenamiento seguro del
  `.crt`/`.key`, fecha de emisión/vencimiento, ambiente — homologación/producción).
- **TicketAcceso**: Ticket de Acceso WSAA vigente (token, signature, fecha de expiración, servicio —
  wsfe —), cacheado para no re-autenticar en cada solicitud.
- **ComprobanteFiscal**: Datos fiscales de un comprobante emitido por este CRM (Venta o NC/ND de Venta),
  vinculado 1 a 1 al comprobante de Ventas existente — Tipo, Punto de Venta, Número, CAE, Vencimiento de
  CAE, estado (emitido/rechazado/pendiente), respuesta cruda de ARCA, comprobante que ajusta (si es
  NC/ND).
- **LogAuditoriaARCA**: Registro de cada llamada a WSAA/WSFEv1 (timestamp, usuario, comprobante
  relacionado, tipo de operación, resultado, mensaje de error si aplica).

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Al confirmar el cobro de una Venta con datos fiscales válidos, el usuario obtiene un
  comprobante con CAE en menos de 15 segundos en condiciones normales de red.
- **SC-002**: El 100% de los comprobantes con CAE asignado muestran ese CAE y su vencimiento en el PDF
  imprimible, sin el watermark "NO VÁLIDO COMO FACTURA".
- **SC-003**: Ante un rechazo de ARCA, el usuario ve el motivo del rechazo en menos de 3 segundos desde
  la respuesta del webservice, sin quedar la operación en un estado ambiguo.
- **SC-004**: Cero comprobantes duplicados ante reintentos después de una falla de red (verificado por
  auditoría de los logs de WSAA/WSFEv1 contra los comprobantes efectivamente guardados).
- **SC-005**: El negocio puede seguir emitiendo Ventas y Compras con el fallback sin validez fiscal en el
  100% de los casos en que el certificado ARCA no esté configurado, sin interrupción del resto del CRM.

## Assumptions

- No hay re-emisión retroactiva de comprobantes: los comprobantes de Ventas/Compras guardados antes de
  activar este módulo mantienen su numeración sin validez fiscal y el watermark "NO VÁLIDO COMO
  FACTURA" para siempre. El CAE sólo se solicita para comprobantes emitidos desde que el módulo está
  activo y el Punto de Venta por defecto está configurado.
- No existe un `docs/informe_contagram_facturacion.md` con capturas reales de Contagram para este
  módulo (a diferencia de otros módulos ya relevados). Se documenta esta brecha explícitamente: la
  estructura de pantalla de esta spec se basa en lo ya relevado con capturas sobre los puntos de
  integración existentes — el menú de fila de Ventas (`docs/informe_contagram_ingresos.md`, opción "Cta
  Cte"/comprobante) y de Compras (`docs/informe_contagram_egresos.md`) donde Facturación Electrónica ya
  aparece como acción pendiente/deshabilitada — y en el flujo estándar de Contagram documentado en
  `documentacion_principal_crm.md` §3.2, §3.5, §4.1 y §4.3. Corresponde relevar con capturas reales una
  eventual pantalla propia de "Configuración de Facturación Electrónica" (Puntos de Venta, certificado)
  antes de darla por cerrada estructuralmente — no forma parte de las pantallas ya relevadas.
- El certificado ARCA propio del negocio (CUIT, `.crt`/`.key` generados en el sitio de AFIP/ARCA) **no
  está disponible todavía** al momento de esta spec. No bloquea specify/clarify/plan/tasks, pero es un
  prerequisito operativo obligatorio antes de `/speckit-implement` poder probarse contra el ambiente de
  homologación o producción de ARCA — se documenta como dependencia externa, no como tarea de este
  equipo.
- Se asume que el ambiente de **homologación** de ARCA (testing) está disponible para desarrollo antes
  de contar con el certificado de producción, permitiendo construir e integrar el módulo sin esperar al
  certificado real del negocio.
- El circuito de Compras (User Story 2) asume que el CAE de una Compra lo genera el Proveedor emisor,
  no este CRM — el negocio, del lado de Compras, sólo registra los datos fiscales del comprobante
  recibido (no solicita CAE propio). Si el negocio necesitara emitir autofacturas u otro circuito
  especial de Compras con CAE propio, queda fuera de alcance de esta spec y debe tratarse aparte.
- Se asume que el "padrón fiscal" (autocompletado de datos de Cliente/Proveedor a partir de CUIT, spec
  014) es una capacidad relacionada pero separada: esta spec no la incluye, aunque comparte la
  infraestructura WSAA/certificado — queda como posible ampliación futura conectando spec 014 con este
  módulo una vez ambos existan.
- Se asume que el punto de venta de tipo "Web Service" es el único soportado (no CAI/CAEA manual ni
  factura en papel), consistente con que el CRM es 100% digital.
- Se asume que las alícuotas de IVA y demás datos de la Venta/Compra ya están correctamente calculados
  por el CRM (specs 008/030) antes de esta spec — el módulo no recalcula IVA, sólo lo transmite a
  WSFEv1 tal como está guardado en el comprobante local.
