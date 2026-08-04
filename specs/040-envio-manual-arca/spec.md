# Feature Specification: Envío Manual a ARCA desde el listado de Ventas

**Feature Branch**: `040-envio-manual-arca`

**Created**: 2026-08-04

**Status**: Draft

**Input**: User description: "Corregir el envío a ARCA/AFIP: hoy la emisión del CAE se dispara automáticamente al confirmar el primer cobro de una Venta (defecto de la spec 034, FR-004 mal especificado sin respaldo de captura real — ya causó un envío real no deseado a ARCA producción, con la Función Avanzada ahora desactivada como mitigación temporal). El comportamiento real de Contagram es manual: un botón "Enviar a ARCA" por fila en la tabla/listado de Ventas (no envío en lote), disponible en cualquier Venta con Tipo de Comprobante A/B/C que todavía no tenga un ComprobanteFiscal aprobado — independientemente de si ya tiene cobros registrados o no. Alcance: sacar el trigger automático de VentaController::cobranzaStore; agregar la acción manual "Enviar a ARCA" en el listado de Ventas (menú de fila) que llama a EmisorComprobante::emitir() ya existente, con confirmación antes de enviar, toast de éxito/error, y actualización de la fila sin recargar la página. No tocar la lógica de emisión en sí ni la de NC/ND. Corregir también FR-004 y la sección relevante de spec 034 y docs/documentacion_principal_crm.md."

## Contexto del incidente (por qué existe esta spec)

El 04/08/2026, una Venta de prueba creada en el ambiente de producción (VPS) disparó automáticamente
una solicitud real de CAE contra WSFEv1 de ARCA en **ambiente de producción** (no homologación), sin
que ningún usuario ejecutara una acción explícita de envío. La solicitud fue rechazada por ARCA por un
error de cálculo de IVA (no se llegó a consumir un número de comprobante fiscal real), pero el
incidente expuso que el sistema envía comprobantes a un ente fiscal real de forma automática e
inadvertida. Como mitigación inmediata se desactivó la Función Avanzada "Facturación Electrónica" en
el VPS, deteniendo cualquier envío (manual o automático) hasta que esta spec se implemente.

La causa es un defecto de especificación de la spec 034 (`FR-004`): se documentó y construyó como
requisito que el sistema "debe... solicitar el CAE a ARCA automáticamente" al confirmar el cobro, una
decisión tomada sin respaldo de un relevamiento con capturas reales de Contagram (la cuenta de prueba
usada para relevar nunca tuvo la facturación electrónica real habilitada — ver
`docs/informe_contagram_funciones_avanzadas.md` y `docs/informe_contagram_ingresos.md`). El
comportamiento real de Contagram, confirmado por el dueño del negocio, es manual: un botón "Enviar a
ARCA" en la tabla de Ventas.

## Clarifications

### Session 2026-08-04

- Q: ¿Qué permiso debe habilitar el botón "Enviar a ARCA" en el listado de Ventas? → A: `ventas.ver` (mismo permiso que ya controla el acceso al listado de Ventas; cualquiera que vea el listado puede enviarlas a ARCA).
- Q: ¿Cómo se muestra el resultado del envío a ARCA (éxito/rechazo)? → A: Modal con el detalle del resultado — CAE y vencimiento si salió bien, o el motivo exacto del rechazo/falla de ARCA si salió mal; se queda hasta que el usuario lo cierra (no un toast efímero, dado que es una acción fiscal real e irreversible).

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Enviar una Venta a ARCA manualmente desde el listado (Priority: P1) 🎯 MVP

Como usuario que gestiona la facturación electrónica del negocio, quiero decidir yo mismo, fila por
fila, cuándo una Venta se envía a ARCA para solicitar su CAE — en vez de que el sistema lo haga solo
al confirmar un cobro — para no arriesgar envíos reales no deseados a un ente fiscal.

**Why this priority**: es la corrección del defecto que ya causó un incidente real contra ARCA
producción. Sin esto, cualquier Venta que se cobre vuelve a intentar un envío automático apenas se
reactive la función.

**Independent Test**: crear una Venta con Tipo de Comprobante B, sin cobrarla, y verificar que el
listado de Ventas ofrece la acción "Enviar a ARCA" en su fila; ejecutarla y confirmar que se solicita
el CAE (mismo resultado — aprobado o rechazado — que hoy produce el trigger automático), sin que se
haya disparado nada al guardar la Venta ni al registrar una cobranza.

**Acceptance Scenarios**:

1. **Given** una Venta con Tipo de Comprobante A/B/C recién creada, sin cobros y sin `ComprobanteFiscal`
   aprobado, **When** el usuario abre el listado de Ventas, **Then** la fila de esa Venta muestra la
   acción "Enviar a ARCA" disponible.
2. **Given** esa Venta, **When** el usuario ejecuta "Enviar a ARCA" y confirma la acción, **Then** el
   sistema solicita el CAE contra ARCA (mismo servicio `EmisorComprobante::emitir()` ya existente) y
   muestra un modal con el resultado — CAE y vencimiento si fue aprobado, o el motivo exacto del
   rechazo/falla si no — que permanece visible hasta que el usuario lo cierra, actualizando la fila
   sin recargar la página completa.
3. **Given** una Venta que ya confirmó un cobro, **When** se registra la cobranza, **Then** el sistema
   **no** solicita el CAE automáticamente — la Venta queda con su numeración local sin validez fiscal
   hasta que el usuario ejecute "Enviar a ARCA" explícitamente.
4. **Given** una Venta con `ComprobanteFiscal` ya aprobado (con CAE), **When** el usuario mira el
   listado, **Then** la acción "Enviar a ARCA" no está disponible para esa fila (ya fue enviada).
5. **Given** una Venta con Tipo de Comprobante distinto de A/B/C (sin obligación fiscal), **When** el
   usuario mira el listado, **Then** la acción "Enviar a ARCA" no está disponible para esa fila.

---

### User Story 2 - Corregir la documentación que originó el defecto (Priority: P2)

Como responsable del proyecto, quiero que la documentación de dominio y la spec 034 reflejen que el
envío a ARCA es manual (no automático), y que quede registrado el incidente que motivó la corrección,
para que una futura sesión no vuelva a asumir "automático" como comportamiento correcto.

**Why this priority**: no bloquea el uso del sistema, pero es necesario para que la fuente de verdad
del proyecto (`docs/documentacion_principal_crm.md`, spec 034) no siga describiendo como intencional
un comportamiento que causó un incidente real y que esta spec revierte.

**Independent Test**: leer `docs/documentacion_principal_crm.md` §4 (Egresos/Ingresos, sección de
Facturación Electrónica) y `specs/034-facturacion-electronica-arca/spec.md` (FR-004) y confirmar que
ambos describen el envío como una acción manual del usuario, con una nota que referencia esta spec y
el incidente que la originó.

**Acceptance Scenarios**:

1. **Given** `specs/034-facturacion-electronica-arca/spec.md`, **When** se revisa `FR-004`, **Then** el
   requisito queda corregido para reflejar que la solicitud de CAE es una acción manual del usuario
   (no automática al confirmar el cobro), con una nota de corrección que referencia esta spec (040).
2. **Given** `docs/documentacion_principal_crm.md`, **When** se revisa la sección de Facturación
   Electrónica, **Then** el texto ya no dice "al confirmar el primer cobro... el CRM solicita CAE
   real... automáticamente", sino que describe la acción manual "Enviar a ARCA" del listado de Ventas,
   dejando una nota del incidente del 04/08/2026 que motivó el cambio.

---

### Edge Cases

- ¿Qué pasa si el usuario ejecuta "Enviar a ARCA" dos veces muy rápido sobre la misma fila (doble
  click)? El sistema no debe generar dos solicitudes ni dos `ComprobanteFiscal` para la misma Venta —
  mismo resguardo que ya aplica hoy en el servicio de emisión existente.
- ¿Qué pasa si no hay certificado fiscal o Punto de Venta configurado? Es un rechazo de precondición
  (nunca se llega a contactar a ARCA) — se informa por **toast** sin romper la pantalla (FR-012),
  distinto del modal de resultado (que es sólo para cuando el sistema efectivamente contactó a ARCA y
  hay una respuesta real, aprobada o rechazada, que mostrar). Esto es explícitamente distinto del
  comportamiento del trigger automático eliminado, que silenciaba este caso sin avisar.
- ¿Qué pasa si la Función Avanzada "Facturación Electrónica" está desactivada (como quedó tras el
  incidente)? La acción "Enviar a ARCA" no debe estar disponible en el listado; si igual se intenta
  (ej. request directa), se informa por **toast** (rechazo de precondición, no de resultado de ARCA) —
  el sistema no debe intentar el envío igual.
- Qué ocurre después de tener CAE aprobado (bloqueo de edición de la Venta, etc.) queda **fuera de
  alcance** de esta spec.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: El sistema NO DEBE solicitar el CAE a ARCA automáticamente al confirmar el cobro de una
  Venta (corrige el `FR-004` original de la spec 034).
- **FR-002**: El sistema DEBE ofrecer, en el listado de Ventas, una acción por fila "Enviar a ARCA"
  para solicitar el CAE de esa Venta puntual bajo decisión explícita del usuario.
- **FR-003**: La acción "Enviar a ARCA" DEBE estar disponible únicamente para Ventas con Tipo de
  Comprobante A, B o C que todavía no tengan un `ComprobanteFiscal` en estado `aprobado`.
- **FR-004**: La acción "Enviar a ARCA" DEBE estar disponible independientemente de si la Venta ya
  tiene cobros registrados o no.
- **FR-005**: Antes de ejecutar el envío, el sistema DEBE pedir confirmación explícita del usuario
  (es una acción real contra un ente fiscal, no reversible).
- **FR-006**: Al confirmar, el sistema DEBE reutilizar el servicio de emisión existente
  (`EmisorComprobante::emitir()`) sin modificar su lógica interna (WSAA/WSFEv1, reconciliación ante
  timeout, etc.).
- **FR-007**: El resultado de un intento real de emisión contra ARCA (aprobado con CAE, o
  rechazado/falla de conexión con su motivo) DEBE comunicarse en un **modal** que permanece visible
  hasta que el usuario lo cierra (no un toast efímero), mostrando CAE y vencimiento si fue aprobado, o
  el motivo exacto si no. Cerrado el modal, la fila de la tabla DEBE reflejar el nuevo estado sin
  recargar la página completa (ya no ofrece "Enviar a ARCA" si quedó aprobada).
- **FR-007a**: Un rechazo de **precondición** (Venta no elegible, Función Avanzada desactivada,
  certificado no configurado) — es decir, cuando el sistema ni siquiera llega a contactar a ARCA —
  DEBE comunicarse por **toast**, no por el modal de FR-007 (que es exclusivo para respuestas reales
  de ARCA).
- **FR-008**: Si la Función Avanzada "Facturación Electrónica" está desactivada, el sistema NO DEBE
  ofrecer ni ejecutar la acción "Enviar a ARCA".
- **FR-009**: La documentación (`docs/documentacion_principal_crm.md` y
  `specs/034-facturacion-electronica-arca/spec.md`) DEBE actualizarse para reflejar el envío manual y
  dejar registrado el incidente del 04/08/2026 que motivó esta corrección.
- **FR-010**: La emisión de CAE para Notas de Crédito/Débito (spec 034 US3) NO se modifica — sigue
  disparándose desde su propio flujo existente, fuera de alcance de esta spec.
- **FR-011**: La acción "Enviar a ARCA" DEBE estar protegida por el mismo permiso que ya controla el
  acceso al listado de Ventas (`ventas.ver`) — no se crea un permiso nuevo ni se restringe a un
  subconjunto de usuarios distinto.
- **FR-012**: Si no hay un certificado fiscal configurado, el sistema DEBE informarlo como rechazo de
  precondición (FR-007a, toast) — a diferencia del trigger automático eliminado, que silenciaba este
  caso como fallback sin aviso; una acción manual explícita del usuario siempre debe recibir una
  respuesta visible.

### Key Entities *(include if feature involves data)*

- **Venta**: entidad existente, sin cambios de esquema. Su relación `comprobanteFiscal` (existente)
  determina si la acción "Enviar a ARCA" está disponible.
- **ComprobanteFiscal**: entidad existente, sin cambios de esquema — se sigue creando/actualizando vía
  `EmisorComprobante::emitir()`.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Ninguna Venta solicita CAE a ARCA sin que un usuario haya ejecutado explícitamente
  "Enviar a ARCA" sobre esa fila (0 envíos automáticos, verificable contra `arca_logs_auditoria`).
- **SC-002**: Un usuario puede enviar una Venta puntual a ARCA desde el listado en menos de 3 clicks
  (abrir listado → acción de fila → confirmar).
- **SC-003**: El listado de Ventas refleja el resultado del envío (aprobado/rechazado) sin recargar la
  página, con exactamente 1 confirmación visual persistente (modal) por intento real contra ARCA.
- **SC-004**: La documentación de dominio (`documentacion_principal_crm.md`, spec 034) ya no describe
  el envío como automático — 0 referencias a "automáticamente" en la sección de Facturación
  Electrónica salvo en la nota histórica del incidente.

## Assumptions

- El servicio `EmisorComprobante::emitir()` y su manejo de errores (`ArcaRechazoException`,
  `ArcaNoDisponibleException`, reconciliación vía `verificarPendiente()`) siguen siendo válidos tal
  cual están — esta spec sólo cambia **quién y cuándo** los invoca, no su lógica interna.
- La acción "Enviar a ARCA" vive en el menú de fila del listado de Ventas (DataTables), consistente
  con el resto de acciones de fila ya existentes en esa tabla (Ver Detalle, Editar, etc.).
- No se requiere una acción de envío en lote (selección múltiple) — confirmado por el dueño del
  negocio: el envío es fila por fila.
- Esta spec no resuelve qué pasa con la edición de una Venta una vez que tiene CAE aprobado
  (inmutabilidad) — se asume que el comportamiento ya existente (si lo hay) no cambia.
- La Función Avanzada "Facturación Electrónica" se reactivará manualmente por el usuario/dueño del
  negocio una vez que esta spec esté implementada y desplegada — no es parte del alcance de esta spec
  reactivarla.
