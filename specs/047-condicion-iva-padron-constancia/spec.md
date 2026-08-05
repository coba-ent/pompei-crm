# Feature Specification: Condición de IVA en el autocompletado del Padrón de ARCA

**Feature Branch**: `047-condicion-iva-padron-constancia`

**Created**: 2026-08-05

**Status**: Draft

**Input**: User description: "Sumar la condición frente al IVA al autocompletado de datos fiscales por CUIT (spec 037, ya implementada para nombre/razón social y domicilio vía ws_sr_padron_a13). El servicio ws_sr_padron_a13 que ya se usa NO expone condición de IVA/impuestos en su schema (confirmado contra el WSDL real de ARCA: sólo trae identidad y domicilios) — hace falta sumar una segunda consulta SOAP al servicio ws_sr_constancia_inscripcion (WSDL personaServiceA5 o el que corresponda a 'Consulta de constancia de inscripción' en el Administrador de Relaciones de Clave Fiscal), mismo patrón de autenticación WSAA que ya existe (ClienteWsaa + certificado fiscal), para completar el campo condicion_iva_id en los dos puntos de integración ya existentes (modal de alta/edición de Cliente, y conversión automática de órdenes de MercadoLibre/Tiendanube). El servicio ws_sr_constancia_inscripcion necesita adherirse aparte en ARCA al mismo certificado (avisar esto en el quickstart, igual que se hizo para A13). Mantener el mismo comportamiento 'best effort' (timeout corto, degrada sin bloquear) y el mismo principio de no pisar ediciones manuales del usuario que ya rige para el resto del autocompletado del padrón."

## Clarifications

### Session 2026-08-05

- Q: La spec 037 asumía que `ws_sr_padron_a13` devolvía condición de IVA, pero se confirmó contra el WSDL real que no la expone — sólo identidad y domicilios. ¿Cómo debe comportarse el sistema mientras la nueva consulta a `ws_sr_constancia_inscripcion` no está disponible o falla? → A: Igual que cualquier otro caso de "ARCA no disponible" ya definido en la spec 037 — el resto del autocompletado (razón social, domicilio) sigue funcionando con lo que trae A13, y la condición de IVA queda sin completar, sin bloquear el guardado del cliente ni la conversión de la orden.
- Q: Si el nuevo servicio adherido al certificado (`ws_sr_constancia_inscripcion`) todavía no está habilitado en el Administrador de Relaciones de Clave Fiscal de ARCA (mismo tipo de problema resuelto en spec 037 para A13), ¿qué debe pasar? → A: Degrada exactamente igual que "ARCA no disponible" (mismo mensaje/comportamiento), documentando el requisito de habilitación en el quickstart para que se resuelva del lado de ARCA, no del código.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Completar condición de IVA al verificar un CUIT en el modal de cliente (Priority: P1)

Un usuario que da de alta o edita un cliente carga un CUIT/CUIL y hace clic en "Verificar". Hoy (spec 037) el sistema ya completa razón social y domicilio fiscal contra el padrón de ARCA, pero deja la condición frente al IVA sin completar porque el servicio que consulta no la expone. Con esta feature, además se completa la condición frente al IVA, siguiendo editable como el resto de los campos.

**Why this priority**: Es el dato que más impacta la facturación (determina Factura A vs B) y es justamente el que hoy falta pese a que el usuario espera verlo completado, según el comportamiento documentado en la spec 037.

**Independent Test**: Se puede probar abriendo el modal de cliente, cargando el CUIT de un contribuyente real inscripto en IVA (Responsable Inscripto), presionando "Verificar", y comprobando que el campo de condición de IVA se completa con el valor correcto además de razón social y domicilio.

**Acceptance Scenarios**:

1. **Given** el modal de cliente abierto con un CUIT válido cargado, **When** el usuario hace clic en "Verificar" y el contribuyente existe en ARCA, **Then** el sistema completa razón social, domicilio fiscal y además la condición frente al IVA (Responsable Inscripto, Monotributista, Exento, etc.) en el campo correspondiente.
2. **Given** la condición de IVA fue autocompletada, **When** el usuario la modifica manualmente antes de guardar, **Then** el sistema respeta el valor editado y no lo vuelve a sobrescribir (mismo principio ya vigente para el resto de los campos autocompletados por el padrón).
3. **Given** el CUIT existe en el padrón (razón social/domicilio se completan con éxito vía `ws_sr_padron_a13`) pero la consulta de condición de IVA falla o no está disponible, **When** el usuario hace clic en "Verificar", **Then** razón social y domicilio se completan igual, la condición de IVA queda sin completar, y el sistema no bloquea el guardado del cliente.

---

### User Story 2 - Usar la condición de IVA confirmada para determinar el tipo de comprobante en la conversión de órdenes (Priority: P2)

Al convertir una orden de Tiendanube o MercadoLibre en venta (manual o automática), cuando el cliente resuelto es nuevo o no tiene condición de IVA cargada y la orden trae CUIT del comprador, el sistema ya consulta el padrón (spec 037) para confirmar la condición de IVA y decidir Factura A vs B. Hoy esa determinación queda incompleta porque el dato de condición de IVA nunca llega. Con esta feature, la consulta adicional permite que esa determinación funcione como estaba previsto originalmente en la spec 037.

**Why this priority**: Depende de que la orden traiga CUIT (no ocurre en todas las órdenes) y de la User Story 1 ya resuelva el mecanismo de consulta; el impacto de negocio es el mismo que ya perseguía la spec 037 (evitar facturar tipo incorrecto).

**Independent Test**: Se puede probar convirtiendo (manual o automáticamente) una orden de prueba con el CUIT de un contribuyente real inscripto en IVA, y verificando que el comprobante resultante es tipo A, replicando el criterio de aceptación ya definido en la spec 037 pero que hoy no se cumple por la falta de este dato.

**Acceptance Scenarios**:

1. **Given** una orden con CUIT de comprador de un Responsable Inscripto confirmado, **When** se ejecuta la conversión (manual o automática), **Then** el comprobante se genera como Factura A.
2. **Given** una orden con CUIT de comprador cuya condición según la nueva consulta no es Responsable Inscripto, **When** se convierte la orden, **Then** el comprobante se genera como tipo B, igual que si no se hubiera encontrado el CUIT.
3. **Given** una orden con CUIT de comprador, **When** la consulta de condición de IVA falla o no está disponible pero la consulta de identidad/domicilio (`ws_sr_padron_a13`) sí respondió, **Then** la conversión continúa con la aproximación por longitud de documento para determinar el tipo de comprobante, sin bloquearse, guardando igualmente los datos de razón social/domicilio que sí se obtuvieron.

---

### Edge Cases

- ¿Qué pasa si `ws_sr_padron_a13` encuentra el CUIT pero `ws_sr_constancia_inscripcion` no lo encuentra (o viceversa)? El sistema completa lo que cada consulta exitosa aporte de forma independiente; la ausencia de resultado en una de las dos no invalida lo que la otra sí devolvió.
- ¿Qué pasa si el certificado fiscal tiene adherido `ws_sr_padron_a13` pero todavía no `ws_sr_constancia_inscripcion` (u otro caso de servicio no habilitado en ARCA)? Se trata igual que "ARCA no disponible" para esa consulta puntual, sin afectar la que sí funciona.
- ¿Qué pasa si el usuario hace clic en "Verificar" varias veces seguidas? Se mantiene la protección ya vigente (spec 037, FR-012) contra consultas duplicadas concurrentes, ahora cubriendo ambas consultas.
- ¿Qué pasa si la condición de IVA que devuelve `ws_sr_constancia_inscripcion` no matchea ningún valor del catálogo `condiciones_iva` del CRM? Se trata igual que "no se pudo determinar" (mismo criterio ya definido en spec 037, R6), sin crear condiciones de IVA nuevas ni bloquear.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: El sistema DEBE, en el mismo momento en que hoy consulta `ws_sr_padron_a13` (verificación manual en el modal de cliente y consulta interna en la conversión de órdenes, spec 037), consultar además el servicio `ws_sr_constancia_inscripcion` de ARCA con el mismo CUIT, reutilizando el mecanismo de autenticación WSAA y el certificado fiscal ya configurados, sin requerir credenciales adicionales del usuario.
- **FR-002**: El sistema DEBE, cuando `ws_sr_constancia_inscripcion` devuelve la condición frente al IVA del contribuyente consultado, completar ese dato en el modal de cliente (User Story 1) siguiendo el mismo principio de edición y no sobrescritura ya vigente para razón social y domicilio (spec 037, FR-003).
- **FR-003**: El sistema DEBE mapear el valor de condición de IVA que devuelve `ws_sr_constancia_inscripcion` al catálogo `condiciones_iva` ya existente del CRM, reutilizando el mismo criterio de mapeo/fallback ya definido en la spec 037 (valores no reconocidos se tratan como "no se pudo determinar", sin crear condiciones nuevas).
- **FR-004**: El sistema DEBE, en la conversión de una orden de Tiendanube o MercadoLibre (manual o automática) cuando el cliente resuelto es nuevo o no tiene condición de IVA cargada y la orden trae CUIT del comprador, usar la condición de IVA confirmada por `ws_sr_constancia_inscripcion` para determinar el tipo de comprobante (Factura A vs B), reemplazando la aproximación por longitud de documento, siguiendo exactamente las reglas de precedencia ya definidas en la spec 037 (FR-007, FR-007a, FR-007b) — que no cambian con esta feature, sólo dejan de depender de un dato que nunca llegaba.
- **FR-005**: El sistema NO DEBE bloquear ni degradar el autocompletado de razón social/domicilio fiscal (ya provisto por `ws_sr_padron_a13`) cuando la consulta a `ws_sr_constancia_inscripcion` falla, no responde a tiempo, o el servicio no está habilitado sobre el certificado — en esos casos la condición de IVA queda sin completar y el resto del flujo (guardado de cliente, conversión de orden) continúa sin interrupción, igual que el tratamiento de "ARCA no disponible" ya definido en la spec 037.
- **FR-006**: El sistema DEBE aplicar un límite de tiempo de espera "best effort" a la consulta a `ws_sr_constancia_inscripcion` (mismo criterio que la spec 037 aplicó a `ws_sr_padron_a13`: enriquecimiento opcional, nunca una operación crítica de facturación), tratando su vencimiento igual que la falta de disponibilidad del servicio (FR-005).
- **FR-007**: El sistema DEBE evitar disparar más de un par de consultas (`ws_sr_padron_a13` + `ws_sr_constancia_inscripcion`) en simultáneo para la misma acción del usuario, reusando la protección contra duplicados ya vigente (spec 037, FR-012).

### Key Entities

- **Consulta de Constancia de Inscripción**: Resultado de una consulta puntual a `ws_sr_constancia_inscripcion` por un CUIT/CUIL — incluye el documento consultado, si fue encontrado, y la condición frente al IVA informada. No se persiste como entidad propia; complementa a la "Consulta de Padrón" ya definida en la spec 037 (que sigue aportando razón social/domicilio/estado de la clave vía `ws_sr_padron_a13`).
- **Cliente**: Entidad ya existente, afectada en que su campo de condición frente al IVA ahora puede autocompletarse también a partir de esta segunda consulta, con el mismo criterio de no pisar ediciones manuales ya vigente para el resto de sus datos fiscales.
- **Orden (Tiendanube / MercadoLibre)**: Entidades ya existentes; sin cambios estructurales — la determinación de tipo de comprobante ya prevista en la spec 037 pasa a poder completarse porque el dato que le faltaba ahora está disponible.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Al verificar en el modal de cliente el CUIT de un contribuyente real inscripto en IVA, el usuario ve la condición frente al IVA completada junto con razón social y domicilio, en menos de 5 segundos desde que hace clic en "Verificar" (mismo umbral ya definido en spec 037, SC-001).
- **SC-002**: El 100% de las conversiones de orden a venta (manuales o automáticas) con CUIT de comprador confirmado como Responsable Inscripto generan Factura A sin intervención manual adicional — cumpliendo el criterio que la spec 037 ya definía (SC-002) y que hoy no se cumple por la falta de este dato.
- **SC-003**: Ninguna conversión de orden a venta ni ningún guardado de cliente queda bloqueado o falla por indisponibilidad de `ws_sr_constancia_inscripcion` — el sistema siempre degrada al comportamiento equivalente a "ARCA no disponible" ya definido en la spec 037.

## Assumptions

- El servicio a sumar es `ws_sr_constancia_inscripcion` ("Consulta de constancia de inscripción" en el Administrador de Relaciones de Clave Fiscal de ARCA), el mismo que genera la constancia de inscripción tipo AFIP; requiere adherirse aparte al certificado fiscal ya usado para `wsfe`/`wsfev1` y `ws_sr_padron_a13`, con el mismo procedimiento ya documentado para éste último en `specs/037-padron-arca-cuit/quickstart.md` — se documenta el requisito equivalente para el nuevo servicio en el quickstart de esta spec.
- Se mantiene la autenticación WSAA y el certificado fiscal ya configurados (`App\Services\Arca\ClienteWsaa`, `CertificadoFiscal::activo()`); no se requiere ningún dato ni permiso adicional del usuario.
- Los dos servicios (`ws_sr_padron_a13` y `ws_sr_constancia_inscripcion`) se consultan de forma independiente y ambos resultados son "best effort": el éxito o fracaso de uno no condiciona al otro.
- El mapeo de condición de IVA reutiliza el mismo catálogo `condiciones_iva` y el mismo criterio de fallback para valores no reconocidos ya definido en la spec 037 (R6); esta feature no agrega condiciones de IVA nuevas.
- Esta feature es una corrección/extensión de la spec 037: no cambia las reglas de precedencia ya definidas ahí (condición de IVA ya cargada manualmente en el Cliente sigue teniendo prioridad sobre el resultado del padrón), sólo resuelve que el dato de condición de IVA efectivamente llegue desde ARCA.
