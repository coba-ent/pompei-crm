# Research: Consulta al Padrón Fiscal de ARCA

## R1 — Servicio de padrón a usar

**Decision**: `ws_sr_padron_a13` (Consulta general de persona — nivel A13, sucesor de `ws_sr_padron_a5`).

**Rationale**: Es el servicio de padrón vigente de ARCA que devuelve datos generales de un
contribuyente (razón social/nombre, domicilio fiscal, condición frente al IVA, estado de la
CUIT) autenticado igual que WSFEv1 — mismo mecanismo WSAA con el CUIT del certificado como
`Cuit` de `Auth`, y el nombre de servicio (`ws_sr_padron_a13`) pasado a `ClienteWsaa::obtenerTicketAcceso()`
en vez de `wsfe`. No requiere habilitación adicional distinta a la que ya tiene el certificado
fiscal usado para WSFEv1 (ambos servicios se habilitan sobre el mismo certificado en el portal
de ARCA — aclarar en `quickstart.md` que puede requerir habilitar el servicio W2 asociado si el
certificado no lo tiene ya).

**Alternatives considered**:
- `ws_sr_padron_a5`: predecesor, mismo patrón, pero A13 es el recomendado actualmente por ARCA
  para consulta general. Se descarta salvo que en implementación se detecte que el certificado
  sólo tiene habilitado A5, en cuyo caso se documenta el fallback (mismo patrón de llamada, WSDL
  distinto).
- Proveedor tercero (Nosis/cuitonline), mencionado como alternativa en
  `docs/documentacion_principal_crm.md`: descartado — ya no hace falta, dado que WSAA/certificado
  propio están disponibles desde spec 034, que era justamente la razón por la que se había
  considerado esa alternativa.

## R2 — Estructura del wrapper SOAP (`ClientePadron`)

**Decision**: Clase `App\Services\Arca\ClientePadron`, mismo patrón que `ClienteWsfev1`:
constructor con `CertificadoFiscal` inyectado, método público `consultarConstancia(array $ticketAcceso, string $cuit): object`
que llama al método SOAP `getPersona` (o `getPersona_v2` según WSDL de A13) con
`token`/`sign`/`cuit` de autenticación, y un método privado `llamar()` que envuelve `SoapFault`/`Throwable`
en `ArcaNoDisponibleException` (reusa la excepción ya existente de `app/Services/Arca/Excepciones/`).

**Rationale**: Consistencia con el código ya existente — mismo negociado SSL `SECLEVEL=1` que
`ClienteWsfev1` (probablemente necesario, mismos servidores de ARCA), mismo timeout de conexión.
Reduce sorpresas y mantiene el patrón "un wrapper delgado por servicio SOAP" ya establecido.

**Alternatives considered**: Extender `ClienteWsfev1` con métodos de padrón — descartado, mezclaría
dos servicios SOAP con WSDLs y respuestas distintas en una sola clase, rompiendo la separación que
ya existe (un wrapper por WSDL).

## R3 — Timeout específico para consulta de padrón

**Decision**: 8 segundos de `connection_timeout` para la llamada al padrón (más corto que los 15s
usados en WSFEv1), porque esta consulta es "best effort": si tarda, el sistema debe degradar rápido
al comportamiento actual sin demorar visiblemente ni el guardado del cliente ni la conversión de
una orden (más aún en la conversión en lote de `convertirTodasLasListas`, secuencial hoy).

**Rationale**: Cumple SC-001 (respuesta en <5s en el caso feliz) dejando margen para timeout+fallback
sin que la operación total se sienta colgada. 15s (como WSFEv1) sería aceptable para una operación
crítica de facturación, pero acá la operación NO es crítica — es un enriquecimiento opcional.

**Alternatives considered**: Reusar el mismo timeout de 15s de `ClienteWsfev1` — descartado por el
argumento anterior; en conversión en lote, 15s por orden sin CUIT-en-padrón podría sumar demoras
notables si ARCA está lenta pero no caída.

## R4 — Punto exacto de integración en `ResolutorCliente` (Tiendanube y MercadoLibre)

**Decision**: La consulta al padrón se dispara únicamente en el mismo punto donde hoy se ejecuta
`tipoComprobantePorDocumento()` (Tiendanube: `ResolutorCliente::tipoComprobante()`, línea ~107 del
archivo hoy vigente) — es decir, exactamente cuando el cliente es nuevo o no tiene `condicion_iva_id`
cargado. Si el padrón responde con una condición de IVA, esa reemplaza la aproximación por longitud
de documento; si no responde o no encuentra el CUIT, se cae al comportamiento actual (aproximación
por documento) sin cambios. Esto respeta exactamente la clarificación de precedencia registrada en
spec.md (FR-007/FR-007a): cuando el cliente YA tiene `condicion_iva_id`, el padrón ni se consulta.

**Rationale**: Reusar el punto de decisión existente minimiza el diff y no introduce una ruta de
código paralela; la aproximación por documento queda como fallback natural (mismo método, mismo
orden de prioridad ya vigente en el código).

**Alternatives considered**: Consultar el padrón siempre (incluso con condición de IVA ya cargada)
y comparar — descartado explícitamente por la clarificación del usuario (la condición ya cargada
manda siempre que exista).

## R5 — Autocompletado en el modal de cliente sin pisar ediciones manuales

**Decision**: El JS (`clientes.js`) sólo escribe en los campos de razón social/domicilio/condición
IVA cuando el padrón responde con datos Y el usuario no los había tocado desde la última consulta
(se resetea el flag de "tocado" al abrir el modal / cambiar de cliente). Si el usuario ya escribió
algo en esos campos antes de hacer clic en "Verificar", el autocompletado no sobrescribe — mismo
principio que ya aplica `completarDatosFiscalesSinPisar()` en el backend para la conversión de
órdenes (FR-041/FR-041a existente), aplicado ahora también en el frontend del modal.

**Rationale**: Consistencia de comportamiento entre los dos puntos de integración: "el padrón nunca
pisa lo que el usuario ya cargó a mano", ya sea en backend (Cliente nuevo desde una orden) o en
frontend (modal de cliente).

**Alternatives considered**: Sobrescribir siempre al autocompletar tras "Verificar" — descartado,
contradice la clarificación de la Historia 1 ("el usuario puede aceptar tal cual o corregir"; forzar
sobrescritura de una corrección ya hecha sería peor UX).

## R6 — Manejo de condiciones de IVA que no mapean 1:1 al catálogo local

**Decision**: Se define un mapeo explícito (tabla de correspondencia, análoga a la ya existente en
`App\Services\MercadoLibre\ResolutorCliente`/su derivador — `condicionIvaId()`) entre los valores
que devuelve el padrón (p. ej. "IVA RESPONSABLE INSCRIPTO", "IVA SUJETO EXENTO", "Monotributista")
y los `nombre` de `condiciones_iva` ya existentes en el CRM. Si el valor del padrón no matchea
ninguna entrada conocida, se trata igual que "no se pudo determinar" (fallback FR-004/FR-008), sin
crear condiciones de IVA nuevas ni bloquear.

**Rationale**: Evita depender de que el texto exacto que devuelve ARCA coincida con los nombres ya
cargados en `condiciones_iva`; consistente con el patrón ya usado para MercadoLibre.

**Alternatives considered**: Crear automáticamente una condición de IVA nueva si no matchea —
descartado, fuera de alcance de la spec (Assumptions: "no crea condiciones de IVA nuevas").
