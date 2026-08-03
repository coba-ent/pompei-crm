# Feature Specification: Consulta al Padrón Fiscal de ARCA

**Feature Branch**: `037-padron-arca-cuit`

**Created**: 2026-08-03

**Status**: Draft

**Input**: User description: "Consulta al padrón fiscal de ARCA (ws_sr_padron_a13) integrada en dos puntos del CRM, reusando la infraestructura WSAA/certificado ya implementada en spec 034: (1) el botón 'Verificar' del modal de cliente se extiende para además consultar el padrón real cuando el documento es CUIT/CUIL y autocompletar razón social/domicilio/condición de IVA de forma editable; (2) en la conversión de orden a venta (Tiendanube y MercadoLibre), automática y manual, cuando la orden trae CUIT del comprador se consulta el padrón internamente (sin UI de búsqueda) para confirmar la condición frente al IVA real y usarla para determinar el tipo de comprobante (A vs B), degradando al comportamiento actual si no hay CUIT o el padrón no responde."

## Clarifications

### Session 2026-08-03

- Q: Cuando el cliente de la orden ya existe y tiene condición frente al IVA cargada manualmente, ¿qué prioridad tiene esa condición frente al resultado del padrón al determinar el tipo de comprobante? → A: La condición de IVA ya cargada manualmente en el Cliente tiene prioridad; el padrón sólo se consulta/usa para determinar el tipo de comprobante cuando el cliente es nuevo o no tiene condición de IVA cargada.
- Q: Cuando la conversión de una orden crea un Cliente nuevo y el padrón responde exitosamente, ¿el resultado (razón social, domicilio, condición IVA) se guarda como datos fiscales del Cliente creado? → A: Sí, se guarda en el Cliente nuevo, igual que si se hubiera verificado manualmente desde el modal de cliente.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Verificar CUIT contra ARCA al cargar/editar un cliente (Priority: P1)

Un usuario que da de alta o edita un cliente en Base de Datos > Clientes carga un CUIT/CUIL y hace clic en "Verificar". Además de validar el dígito verificador (como ya ocurre hoy), el sistema consulta el padrón real de ARCA y, si encuentra el contribuyente, propone completar razón social, domicilio fiscal y condición frente al IVA — datos que el usuario puede aceptar tal cual o corregir antes de guardar.

**Why this priority**: Es la funcionalidad de mayor uso directo (cada alta/edición de cliente) y reduce errores de carga manual que hoy generan comprobantes con datos fiscales incorrectos.

**Independent Test**: Se puede probar completamente abriendo el modal de cliente, cargando un CUIT válido y real, presionando "Verificar", y comprobando que los campos de razón social/domicilio/condición IVA se completan y siguen siendo editables. Entrega valor por sí sola sin requerir el resto de la spec.

**Acceptance Scenarios**:

1. **Given** el modal de alta/edición de cliente abierto con tipo de documento CUIT y un CUIT válido cargado, **When** el usuario hace clic en "Verificar", **Then** el sistema valida el dígito verificador localmente y además consulta el padrón de ARCA, mostrando un indicador de éxito y completando razón social, domicilio fiscal y condición frente al IVA en los campos correspondientes del formulario.
2. **Given** el padrón devolvió datos y autocompletó los campos, **When** el usuario modifica manualmente alguno de esos campos antes de guardar, **Then** el sistema respeta el valor editado por el usuario y lo guarda tal cual (no lo vuelve a sobrescribir).
3. **Given** el CUIT cargado es válido en dígito verificador pero no existe en el padrón de ARCA (o el servicio de ARCA no está disponible), **When** el usuario hace clic en "Verificar", **Then** el sistema informa por toast que no se pudo consultar/encontrar el dato en el padrón, sin bloquear ni impedir que el usuario complete el formulario manualmente y guarde el cliente.
4. **Given** el tipo de documento seleccionado es DNI, Pasaporte o CDI (no CUIT/CUIL), **When** el usuario hace clic en "Verificar", **Then** el sistema se comporta igual que hoy (sólo validación local), sin intentar consultar el padrón.

---

### User Story 2 - Determinar tipo de comprobante con el padrón al convertir una orden en venta (Priority: P2)

Un usuario convierte una orden de Tiendanube o MercadoLibre en una venta/factura (de forma manual, revisando la pantalla de conversión, o mediante el proceso de conversión automática). Si la orden trae un CUIT del comprador, el sistema lo consulta internamente contra el padrón de ARCA para confirmar la condición real frente al IVA del contribuyente, y usa ese dato para determinar si corresponde emitir Factura A o B, en lugar de aproximarlo sólo por la longitud del documento como ocurre hoy.

**Why this priority**: Mejora la precisión fiscal de la facturación electrónica (evita emitir el tipo de comprobante incorrecto), pero depende de que la orden efectivamente traiga CUIT, lo cual no ocurre en todas las órdenes — por eso es P2 respecto de la verificación manual en el modal de cliente, que es de uso más frecuente y directo.

**Independent Test**: Se puede probar de forma independiente convirtiendo (manual o automáticamente) una orden de prueba que incluya un CUIT de un contribuyente real inscripto en IVA, y verificando que el comprobante resultante es tipo A en lugar de la aproximación anterior. También se puede probar con una orden sin CUIT y comprobar que el comportamiento no cambia respecto del actual.

**Acceptance Scenarios**:

1. **Given** una orden de Tiendanube o MercadoLibre con un CUIT de comprador válido, **When** se ejecuta la conversión de esa orden a venta (manual o automática), **Then** el sistema consulta el padrón de ARCA con ese CUIT antes de determinar el tipo de comprobante, y si el padrón confirma que el contribuyente es Responsable Inscripto, el comprobante se genera como tipo A.
2. **Given** una orden con CUIT de comprador cuya condición de IVA según el padrón no es Responsable Inscripto (por ejemplo Monotributista o Consumidor Final), **When** se convierte la orden, **Then** el comprobante se genera como tipo B (o el que corresponda según la condición confirmada), igual que si no se hubiera encontrado el CUIT.
3. **Given** una orden que no trae CUIT del comprador, **When** se convierte la orden (manual o automática), **Then** el sistema no intenta consultar el padrón y mantiene el comportamiento actual (Factura B / aproximación por documento).
4. **Given** una orden con CUIT de comprador, **When** el servicio de padrón de ARCA no está disponible o responde con error/timeout, **Then** la conversión de la orden continúa sin bloquearse, aplicando el comportamiento de aproximación actual (por longitud de documento) como si no se hubiera podido consultar el padrón.
5. **Given** la conversión automática de órdenes en lote, **When** una de las órdenes del lote falla al consultar el padrón, **Then** esa orden puntual se procesa con el comportamiento de aproximación actual y el resto del lote no se ve afectado.

---

### Edge Cases

- ¿Qué pasa si el CUIT cargado en el modal de cliente tiene dígito verificador válido pero el padrón devuelve un contribuyente dado de baja o con CUIT inactivo? El sistema informa la condición encontrada (incluyendo si está inactivo) pero no bloquea el guardado del cliente.
- ¿Qué pasa si el usuario hace clic en "Verificar" varias veces seguidas (doble click, conexión lenta)? El sistema debe evitar disparar consultas duplicadas concurrentes al padrón mientras una consulta anterior sigue en curso.
- ¿Qué pasa si el certificado fiscal (`CertificadoFiscal::activo()`) no está configurado o está vencido al momento de consultar el padrón? El sistema informa que la consulta al padrón no está disponible (mismo tratamiento que "ARCA no disponible"), sin impedir el resto del flujo (guardado de cliente o conversión de orden).
- ¿Qué pasa si el padrón tarda demasiado en responder? La consulta debe tener un límite de tiempo de espera razonable y, al vencer, tratarse igual que "ARCA no disponible".
- ¿Qué pasa con el CUIT del propio negocio (el titular del certificado) si por error se carga como cliente? El sistema lo trata igual que cualquier otro CUIT consultado; no hay restricción especial en el alcance de esta feature.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: El sistema DEBE, al presionar "Verificar" en el modal de alta/edición de cliente con tipo de documento CUIT o CUIL, ejecutar la validación local de dígito verificador ya existente y, si esa validación es exitosa, consultar además el padrón fiscal de ARCA con ese número de documento.
- **FR-002**: El sistema DEBE, cuando el padrón de ARCA devuelve datos del contribuyente consultado, ofrecer completar en el formulario del cliente: razón social, domicilio fiscal y condición frente al IVA, dejando esos campos editables por el usuario antes de guardar.
- **FR-003**: El sistema NO DEBE sobrescribir campos que el usuario haya modificado manualmente después de que el padrón los haya autocompletado.
- **FR-004**: El sistema DEBE informar mediante notificación toast (sin diálogos nativos del navegador) cuando la consulta al padrón no encuentra el CUIT, o cuando el servicio de ARCA no está disponible, sin impedir que el usuario continúe completando y guardando el formulario manualmente.
- **FR-005**: El sistema NO DEBE intentar consultar el padrón cuando el tipo de documento seleccionado no sea CUIT ni CUIL (DNI, Pasaporte, CDI); en esos casos el botón "Verificar" mantiene su comportamiento actual sin cambios.
- **FR-006**: El sistema DEBE, durante la conversión de una orden de Tiendanube o MercadoLibre en venta (tanto en el flujo manual como en el automático), consultar internamente el padrón de ARCA con el CUIT del comprador cuando la orden lo incluya, sin exponer ningún control de búsqueda manual en la pantalla de conversión.
- **FR-007**: El sistema DEBE usar la condición frente al IVA confirmada por el padrón (cuando la consulta es exitosa) para determinar el tipo de comprobante a emitir (Factura A si corresponde a Responsable Inscripto, u otro tipo según la condición confirmada), en reemplazo de la aproximación actual basada únicamente en la longitud del documento — únicamente cuando el cliente resuelto de la orden es nuevo o no tiene condición frente al IVA ya cargada.
- **FR-007a**: El sistema NO DEBE usar el resultado del padrón para determinar el tipo de comprobante cuando el cliente resuelto de la orden ya existe y tiene una condición frente al IVA cargada manualmente; en ese caso prevalece la condición ya cargada en el Cliente, igual que en el comportamiento actual.
- **FR-007b**: El sistema DEBE, cuando el Cliente resuelto de la orden (nuevo o existente sin condición de IVA cargada) y la consulta al padrón fue exitosa, guardar en ese Cliente los datos fiscales devueltos (razón social, domicilio fiscal, condición frente al IVA), de la misma forma que si hubiesen sido cargados vía verificación manual en el modal de cliente (FR-002), sin pisar ningún dato que el Cliente ya tuviera cargado.
- **FR-008**: El sistema DEBE mantener el comportamiento de determinación de tipo de comprobante actual (aproximación por documento / Factura B) cuando la orden no incluye CUIT del comprador, cuando el padrón no encuentra el CUIT, o cuando el servicio de ARCA no está disponible o responde con error o fuera de tiempo.
- **FR-009**: El sistema DEBE continuar procesando el resto de un lote de conversión automática de órdenes sin interrupción cuando la consulta al padrón falla para una orden puntual del lote.
- **FR-010**: El sistema DEBE reutilizar el mecanismo de autenticación (ticket de acceso) ya existente contra ARCA para consultar el padrón, sin requerir que el usuario cargue credenciales o certificados adicionales a los ya configurados para facturación electrónica.
- **FR-011**: El sistema DEBE aplicar un límite de tiempo de espera a la consulta al padrón, tratando el vencimiento de ese límite igual que una falta de disponibilidad del servicio (FR-004, FR-008).
- **FR-012**: El sistema DEBE evitar disparar más de una consulta al padrón en simultáneo para la misma acción del usuario (por ejemplo, clics repetidos en "Verificar" mientras una consulta anterior está en curso).

### Key Entities

- **Consulta de Padrón**: Resultado de una consulta puntual a ARCA por un CUIT/CUIL determinado — incluye el documento consultado, si fue encontrado, razón social, domicilio fiscal, condición frente al IVA informada, y si el contribuyente está activo. No se persiste como entidad propia del dominio; es un resultado transitorio usado para autocompletar el cliente o decidir el tipo de comprobante en el momento de la consulta.
- **Cliente**: Entidad ya existente (`Cliente`), afectada por esta feature en que sus campos de razón social, domicilio fiscal y condición frente al IVA pueden autocompletarse a partir de una Consulta de Padrón exitosa.
- **Orden (Tiendanube / MercadoLibre)**: Entidades ya existentes, afectadas en que el CUIT del comprador (cuando está presente) dispara una Consulta de Padrón durante su conversión a venta.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Al verificar un CUIT real y válido en el modal de cliente, el usuario ve los datos fiscales (razón social, domicilio, condición IVA) propuestos en menos de 5 segundos desde que hace clic en "Verificar".
- **SC-002**: El 100% de las conversiones de orden a venta (manuales o automáticas) con CUIT de comprador válido y confirmado como Responsable Inscripto por el padrón generan Factura A, sin intervención manual adicional.
- **SC-003**: Ninguna conversión de orden a venta ni ningún guardado de cliente queda bloqueado o falla por indisponibilidad del servicio de padrón de ARCA — el sistema siempre degrada al comportamiento previo a esta feature en esos casos.
- **SC-004**: La proporción de clientes nuevos cargados con datos fiscales (razón social/domicilio) verificados contra ARCA aumenta respecto de la carga manual sin verificación, reduciendo la necesidad de correcciones posteriores.

## Assumptions

- El servicio de padrón usado es el vigente de ARCA (identificado como `ws_sr_padron_a13` o equivalente que reemplace al anterior `ws_sr_padron_a5`), consultado mediante el mismo mecanismo de autenticación WSAA ya implementado en la spec 034 (`ClienteWsaa`) con el certificado fiscal activo (`CertificadoFiscal::activo()`).
- La consulta al padrón se realiza en tiempo real en el momento de la acción del usuario (clic en "Verificar") o del proceso de conversión de orden; esta spec no contempla una sincronización periódica ni cacheo prolongado de datos del padrón — cada consulta es puntual. El detalle de si se cachea por un período corto (para evitar duplicar llamadas ante reintentos) queda a criterio de la fase de planificación técnica.
- "Condición frente al IVA" del padrón se mapea a los valores ya existentes en el catálogo `condiciones_iva` del CRM (Responsable Inscripto, Monotributista, Consumidor Final, Exento, etc.); esta spec no crea condiciones de IVA nuevas.
- El alcance de esta feature es exclusivamente CUIT/CUIL (personas jurídicas y físicas registradas en AFIP/ARCA); no incluye verificación de DNI, Pasaporte ni CDI contra ningún padrón.
- La determinación de tipo de comprobante (FR-007) sigue respetando las reglas de negocio ya vigentes para los demás casos (por ejemplo, condición de IVA ya cargada manualmente en el Cliente cuando el cliente ya existe) — el padrón sólo se usa para confirmar/reemplazar la aproximación por longitud de documento cuando hay CUIT en la orden, no para pisar reglas ya resueltas por otros medios documentados en `docs/documentacion_principal_crm.md`.
- Esta feature retoma y resuelve el pendiente documentado en `specs/014-verificacion-documento-fiscal/spec.md` (Assumptions) y en `docs/documentacion_principal_crm.md` (líneas 69-79 y 1543-1550), que difería la consulta real al padrón hasta contar con infraestructura WSAA — ya disponible desde la spec 034.
- No se requiere ningún permiso o rol de usuario adicional a los que ya pueden crear/editar clientes o convertir órdenes en venta.
