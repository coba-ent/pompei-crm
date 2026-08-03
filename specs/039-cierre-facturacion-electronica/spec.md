# Feature Specification: Cierre de Facturación Electrónica — PDF NC/ND, Mi Perfil y Recibos

**Feature Branch**: `039-cierre-facturacion-electronica`

**Created**: 2026-08-03

**Status**: Draft

**Input**: User description: "Cierre de pendientes de Facturación Electrónica (spec 034) y documentos imprimibles relacionados: (1) PDF propio de Notas de Crédito/Débito de Ventas mostrando su CAE real y referencia al comprobante que ajustan — pendiente T027 de spec 034. (2) Pantalla 'Mi Perfil' para cargar los datos fiscales del propio negocio y su logo, mostrados como encabezado emisor en los PDFs de Venta y NC/ND. (3) Documento imprimible de Recibos de Cobros y Pagos, sin informe con capturas reales de Contagram — estructura documentada como mejor esfuerzo."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Ver el PDF de una Nota de Crédito/Débito con su CAE (Priority: P1)

Después de crear una Nota de Crédito o Débito sobre una Venta con comprobante fiscal aprobado (spec 034, spec 008), el usuario abre su propio documento imprimible desde la sección de Notas de Crédito/Débito del Detalle de Venta, y ve el CAE real de esa NC/ND, su vencimiento, y una referencia clara al comprobante de Venta original que ajusta.

**Why this priority**: sin este documento, una NC/ND con CAE real no tiene ningún comprobante imprimible entregable al cliente — es el cierre del circuito fiscal de NC/ND que quedó pendiente en spec 034 (T027).

**Independent Test**: sobre una Venta con CAE ya emitido, crear una NC de Crédito (spec 008); abrir su "Ver Detalle"; verificar que el PDF muestra CAE, vencimiento de CAE, y los datos del comprobante de Venta ajustado (tipo, número, fecha), sin el watermark "NO VÁLIDO COMO FACTURA".

**Acceptance Scenarios**:

1. **Given** una NC/ND con `ComprobanteFiscal` en estado `aprobado` (tiene CAE), **When** el usuario abre "Ver Detalle" desde el menú de la NC/ND, **Then** el PDF se abre en el modal compartido mostrando CAE, fecha de vencimiento del CAE, código de barras/QR fiscal, y los datos del comprobante de Venta que ajusta (tipo de comprobante, número, fecha de emisión), sin watermark "NO VÁLIDO COMO FACTURA".
2. **Given** una NC/ND sin `ComprobanteFiscal` aprobado (falló la emisión o no se solicitó CAE — p. ej. certificado no configurado), **When** el usuario abre "Ver Detalle", **Then** el PDF se abre igual, mostrando el watermark "NO VÁLIDO COMO FACTURA" y sin datos de CAE, igual que el comportamiento ya existente para Ventas sin CAE.
3. **Given** una NC/ND con ítems que afectan stock (spec 008), **When** se genera el PDF, **Then** la tabla de ítems muestra los mismos productos/cantidades/precios registrados al crear la NC/ND.

---

### User Story 2 - Cargar los datos fiscales del negocio en "Mi Perfil" (Priority: P1)

Un usuario Admin entra a Configuración & Ajustes → Mi Perfil y carga los datos fiscales del propio negocio (Razón Social, CUIT, Domicilio Fiscal, Condición de IVA, Ingresos Brutos si aplica) y opcionalmente un logo, para que los comprobantes emitidos por el CRM identifiquen al negocio emisor.

**Why this priority**: es prerequisito de las User Story 1 y 3 (los PDFs necesitan estos datos para el encabezado emisor) y cierra un pendiente documentado desde antes de spec 034 ("Mi Perfil" pausado "hasta retomar Facturación Electrónica").

**Independent Test**: entrar a Mi Perfil, cargar Razón Social/CUIT/Domicilio Fiscal/Condición de IVA y un logo, guardar; verificar que los datos persisten y que aparecen reflejados en el encabezado del PDF de una Venta ya emitida.

**Acceptance Scenarios**:

1. **Given** que "Mi Perfil" nunca se cargó, **When** el usuario Admin completa Razón Social, CUIT, Domicilio Fiscal y Condición de IVA y guarda, **Then** los datos quedan persistidos y disponibles para los documentos imprimibles del CRM.
2. **Given** datos de Mi Perfil ya cargados, **When** el usuario sube un logo (imagen), **Then** el logo se guarda y se muestra tanto en la pantalla de Mi Perfil como en el encabezado de los PDFs de Venta/NC/ND/Recibo generados después de la carga.
3. **Given** que Mi Perfil no tiene datos cargados todavía, **When** se genera cualquier PDF de comprobante (Venta, NC/ND, Recibo), **Then** el encabezado emisor se omite o muestra en blanco sin romper el resto del documento (no bloquea la emisión ni la impresión).
4. **Given** un usuario sin permiso de administración de Configuración, **When** intenta acceder a Mi Perfil, **Then** el acceso es denegado igual que el resto de las pantallas de Configuración & Ajustes.

---

### User Story 3 - Imprimir un Recibo de Cobro o Pago (Priority: P2)

Un usuario abre el documento imprimible de un Recibo asociado a una Cobranza de Venta o a un Pago a Proveedor, para entregárselo como comprobante no fiscal del movimiento de dinero (distinto del comprobante de Venta/Compra en sí).

**Why this priority**: depende de Mi Perfil (User Story 2) para el encabezado emisor, y su estructura de pantalla no está relevada con capturas reales de Contagram (sólo referenciada como "artículo propio" en la documentación oficial) — se implementa como mejor esfuerzo, análogo al patrón ya usado en Ver Detalle de Venta/Compra, dejando expresamente documentada la brecha de relevamiento.

**Independent Test**: sobre una Venta con al menos una Cobranza registrada, abrir "Ver Recibo" de esa Cobranza; verificar que el PDF muestra los datos del emisor (Mi Perfil), del Cliente, el medio de cobro, el monto y la fecha. Repetir el mismo flujo para un Pago a Proveedor.

**Acceptance Scenarios**:

1. **Given** una Cobranza registrada sobre una Venta, **When** el usuario abre "Ver Recibo" de esa Cobranza, **Then** se abre en el modal compartido un PDF con los datos del emisor (Mi Perfil), del Cliente, el medio de cobro (cuenta de Tesorería), el monto, la fecha y un número de recibo correlativo interno (sin validez fiscal — no es un comprobante ARCA).
2. **Given** un Pago a Proveedor registrado, **When** el usuario abre "Ver Recibo" de ese Pago, **Then** se abre el mismo tipo de documento con los datos del Proveedor en lugar del Cliente.
3. **Given** que la estructura real de esta pantalla en Contagram no fue relevada con capturas, **When** se documenta esta funcionalidad, **Then** la brecha queda anotada explícitamente en `docs/documentacion_principal_crm.md` como pendiente de contrastar contra capturas reales cuando estén disponibles.

---

### Edge Cases

- ¿Qué pasa si se genera el PDF de una NC/ND cuyo comprobante de Venta ajustado fue eliminado o su `ComprobanteFiscal` ya no está disponible? El PDF debe seguir mostrando la referencia con los datos ya persistidos en la NC/ND (tipo/número/fecha guardados al momento de crearla), sin fallar por falta de relación viva.
- ¿Qué pasa si el logo cargado en Mi Perfil es un archivo corrupto o de un formato no soportado? El guardado se rechaza con un mensaje claro (toast), sin persistir un archivo inválido.
- ¿Qué pasa si se cambian los datos de Mi Perfil después de haber emitido comprobantes? Los PDFs generados a partir de ese momento muestran los datos actualizados; no se reprocesan retroactivamente comprobantes ya emitidos (el CAE y los datos fiscales del comprobante en sí no cambian).
- ¿Qué pasa si se intenta ver el Recibo de una Cobranza que fue eliminada/anulada? Debe mostrarse un mensaje de error claro (toast) en lugar de un PDF vacío o roto.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: El sistema DEBE ofrecer, desde la sección de Notas de Crédito/Débito del Detalle de Venta, una acción "Ver Detalle" propia de cada NC/ND que abre su documento imprimible en el modal PDF compartido.
- **FR-002**: El PDF de una NC/ND DEBE mostrar el CAE y la fecha de vencimiento del CAE de su propio `ComprobanteFiscal` cuando está `aprobado`, igual que ya lo hace el PDF de Venta.
- **FR-003**: El PDF de una NC/ND DEBE mostrar una referencia visible al comprobante de Venta que ajusta: tipo de comprobante, número y fecha de emisión.
- **FR-004**: El PDF de una NC/ND DEBE ocultar el watermark "NO VÁLIDO COMO FACTURA" únicamente cuando su propio `ComprobanteFiscal` está `aprobado` con CAE.
- **FR-005**: El sistema DEBE ofrecer una pantalla "Mi Perfil" en Configuración & Ajustes donde un Admin pueda cargar y editar: Razón Social, CUIT, Domicilio Fiscal, Condición de IVA, Ingresos Brutos (opcional) y un logo del negocio.
- **FR-006**: La pantalla Mi Perfil DEBE seguir las reglas de diseño obligatorias del proyecto (modal Bootstrap + AJAX, sin recarga de página, notificaciones vía Toastr).
- **FR-007**: Los PDFs de Venta y de NC/ND DEBEN mostrar, como encabezado del emisor, los datos cargados en Mi Perfil (Razón Social, CUIT, Domicilio Fiscal, Condición de IVA, logo si existe) cuando estén disponibles.
- **FR-008**: Si Mi Perfil no tiene datos cargados, el sistema DEBE seguir generando los PDFs de comprobantes normalmente, omitiendo el encabezado del emisor sin bloquear la operación.
- **FR-009**: El sistema DEBE ofrecer un documento imprimible ("Recibo") para cada Cobranza de Venta, accesible desde el Detalle de Venta, abierto en el modal PDF compartido.
- **FR-010**: El sistema DEBE ofrecer el mismo tipo de documento imprimible para cada Pago a Proveedor registrado en Egresos.
- **FR-011**: El Recibo DEBE mostrar: datos del emisor (Mi Perfil), datos del Cliente o Proveedor según corresponda, medio de cobro/pago (cuenta de Tesorería), monto, fecha y un número correlativo interno de recibo.
- **FR-012**: El Recibo NO es un comprobante fiscal — no solicita CAE a ARCA ni participa del circuito de `EmisorComprobante` de spec 034.
- **FR-013**: El acceso a Mi Perfil DEBE estar restringido igual que el resto de las pantallas de Configuración & Ajustes (mismo esquema de permisos por rol ya existente).
- **FR-014**: Al guardar Mi Perfil con un archivo de logo inválido o corrupto, el sistema DEBE rechazar el guardado con un mensaje de error claro vía toast, sin persistir el archivo.

### Key Entities *(include if feature involves data)*

- **DatosEmpresa** (Mi Perfil): entidad única (single-tenant) con Razón Social, CUIT, Domicilio Fiscal, Condición de IVA, Ingresos Brutos (opcional) y ruta del logo. Consumida como encabezado emisor por los documentos imprimibles del CRM.
- **NotaCreditoDebito**: entidad ya existente (spec 008); se le agrega la capacidad de exponer su propio documento imprimible, apoyándose en su relación existente con `ComprobanteFiscal` (spec 034, vía `comprobantable`).
- **Recibo**: no requiere necesariamente una tabla nueva — representa la vista imprimible de una Cobranza (Venta) o un Pago (Compra/Proveedor) ya existentes, con numeración correlativa interna propia sin validez fiscal.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: El 100% de las NC/ND con `ComprobanteFiscal` aprobado tienen un PDF propio accesible en menos de 3 clics desde el Detalle de Venta.
- **SC-002**: Los datos de Mi Perfil, una vez cargados, aparecen reflejados en el encabezado de un PDF de Venta nuevo en el 100% de los casos, sin recargar la página durante la carga.
- **SC-003**: El usuario puede generar un Recibo imprimible de cualquier Cobranza o Pago existente en menos de 3 clics desde el Detalle de Venta/Compra correspondiente.
- **SC-004**: Cero comprobantes de NC/ND quedan sin documento imprimible disponible después de implementada esta feature, cerrando el pendiente T027 de spec 034.

## Assumptions

- La estructura real de pantalla de "Mi Perfil" en Contagram no fue relevada con capturas propias; se construye siguiendo el patrón visual ya usado en otras pantallas de Configuración & Ajustes (modal + AJAX), documentando esta brecha en `docs/documentacion_principal_crm.md` según el principio rector del proyecto.
- La estructura real de pantalla de "Recibos de Cobros y Pagos" en Contagram tampoco fue relevada con capturas; se documenta como mejor esfuerzo basado en el patrón de "Ver Detalle" de Venta/Compra ya implementado, dejando expresamente marcada la brecha de relevamiento (regla de oro, CLAUDE.md).
- El logo de Mi Perfil se almacena como archivo de imagen; está fuera del alcance de esta spec definir compresión/transformaciones avanzadas — se reutiliza el manejo estándar de uploads ya usado en el proyecto.
- La numeración interna del Recibo es independiente de la numeración fiscal de comprobantes (`comprobantes_fiscales.numero`) — es un correlativo propio sin relación con ARCA.
- Un solo conjunto de datos de Mi Perfil por instalación (single-tenant), consistente con el resto del CRM.
