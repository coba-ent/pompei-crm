# Feature Specification: Verificación de documento fiscal (CUIT/CUIL)

**Feature Branch**: `014-verificacion-documento-fiscal`

**Created**: 2026-07-29

**Status**: Draft

**Input**: User description: "Verificación de CUIT/CUIL (dígito verificador local, algoritmo módulo 11 de ARCA — ya implementado en app/Rules/CuitValido.php y usado por ReglasCliente/ReglasProveedor) expuesta en dos lugares donde hoy falta: (1) botón 'Verificar' + auto-formato en el campo N° de Doc de los modales de Cliente y Proveedor, confirmado con capturas reales de Contagram; (2) reuso de la misma validación en la conversión automática de órdenes de Mercado Libre — si el documento informado por ML (CUIT/CUIL) no es matemáticamente válido, tratarlo como si no hubiera documento fiscal válido y caer al fallback existente sin datos fiscales (Consumidor Final / Comprobante B), sin bloquear la conversión automática ni marcar la orden como 'Requiere atención'."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Verificar el documento al cargar un Cliente o Proveedor (Priority: P1)

Un usuario está cargando los datos de facturación de un Cliente (o Proveedor) nuevo y tipea el
número de CUIT/CUIL. Quiere confirmar que lo escribió bien **antes** de intentar guardar, en vez de
enterarse recién al hacer clic en "Crear" y que el sistema rechace el formulario.

**Why this priority**: Es la mejora de UX que motivó el pedido — hoy la validación existe pero es
"muda" hasta el submit; el usuario se entera del error tarde y sin contexto inmediato. Es el cambio
de menor riesgo y mayor valor perceptible.

**Independent Test**: Se puede probar por completo abriendo el modal de Nuevo Cliente, tipeando un
número en el campo de documento y apretando "Verificar", sin necesidad de guardar nada ni de que
existan otras partes de la feature.

**Acceptance Scenarios**:

1. **Given** el modal de Nuevo Cliente abierto, **When** el usuario tipea "30712345678" en el campo
   N° de Doc, **Then** el campo se auto-formatea a "30-71234567-8" a medida que tipea.
2. **Given** el campo de documento con un CUIT matemáticamente inválido cargado, **When** el usuario
   hace clic en "Verificar", **Then** el sistema muestra en menos de 1 segundo un mensaje de error
   ("El CUIT ingresado no es válido." — el mismo texto que ya usa la validación de guardado, ver
   `App\Rules\CuitValido`) sin recargar la página ni intentar guardar el formulario.
3. **Given** el campo de documento con un CUIT matemáticamente válido cargado, **When** el usuario
   hace clic en "Verificar", **Then** el sistema confirma que el número es válido.
4. **Given** un CUIT inválido cargado, **When** el usuario hace clic directamente en "Crear" **sin**
   haber apretado "Verificar" antes, **Then** el sistema igual bloquea la creación del registro con el
   mismo mensaje de error (el comportamiento de bloqueo al guardar ya existe y no cambia).
5. **Given** el modal de Nuevo Proveedor (en vez de Cliente), **When** se repiten los pasos 1 a 4,
   **Then** el comportamiento es idéntico.
6. **Given** el modal de **edición** de un Cliente (o Proveedor) ya existente, **When** se repiten los
   pasos 1 a 4 sobre ese modal, **Then** el comportamiento es idéntico al de alta (FR-001 cubre alta y
   edición por igual).

---

### User Story 2 - La conversión automática de Mercado Libre no confía ciegamente en un documento inválido (Priority: P2)

Una orden de Mercado Libre se convierte automáticamente en Venta. Mercado Libre informó un tipo de
documento (CUIT o CUIL) y un número, pero **no** informó la condición frente al IVA del comprador (caso
ya cubierto hoy sólo de forma aproximada, confiando en el documento tal cual llega). Si ese número no
es matemáticamente válido, el sistema no debe tratarlo como un dato fiscal utilizable: ni para derivar
el tipo de comprobante, ni para guardarlo como CUIT del Cliente que se da de alta automáticamente.

**Why this priority**: Depende de la misma lógica de validación que la User Story 1, pero es un cambio
más profundo (toca la conversión automática de ventas, con implicancia fiscal) y de menor frecuencia
real (sólo aplica cuando Mercado Libre no informa condición de IVA, que ya es el caso "aproximado").

**Independent Test**: Se puede probar de forma aislada simulando una orden de Mercado Libre con
`comprador_condicion_iva` vacío, `comprador_doc_tipo = "CUIT"` (o "CUIL") y un `comprador_doc_numero`
matemáticamente inválido, y verificando que la conversión automática se completa igual (con el
comprobante por defecto de Consumidor Final) y que el Cliente creado no queda con ese número guardado
como CUIT.

**Acceptance Scenarios**:

1. **Given** una orden de Mercado Libre sin condición de IVA informada, con `doc_tipo = "CUIT"` y un
   `doc_numero` que falla el dígito verificador, **When** se ejecuta la conversión automática, **Then**
   el comprobante se deriva como si no hubiera ningún documento informado (Consumidor Final /
   Comprobante B), igual que hoy cuando Mercado Libre no manda documento en absoluto.
2. **Given** el mismo escenario, **When** la conversión automática da de alta un Cliente nuevo para
   ese comprador, **Then** el Cliente se crea **sin** CUIT cargado (el campo queda vacío, no se
   persiste el número inválido).
3. **Given** el mismo escenario, **When** se ejecuta la conversión automática, **Then** la orden
   **no** queda marcada "Requiere atención" ni se bloquea por este motivo — se completa igual que
   cualquier venta sin datos fiscales del comprador.
4. **Given** una orden con `doc_tipo = "CUIL"` (en vez de "CUIT") y un número matemáticamente inválido,
   **When** se ejecuta la conversión automática, **Then** el comportamiento es el mismo que con
   "CUIT": se descarta el documento y se usa el fallback sin datos fiscales.
5. **Given** una orden con `doc_tipo = "CUIT"` y un número **válido** (dígito verificador correcto),
   **When** se ejecuta la conversión automática, **Then** el comportamiento no cambia respecto al
   actual (se sigue usando ese documento como aproximación, comprobante A).
6. **Given** una orden donde Mercado Libre **sí** informó la condición frente al IVA del comprador
   (no cae en la rama de aproximación) pero además informó un `doc_numero` matemáticamente inválido,
   **When** la conversión automática da de alta un Cliente nuevo para ese comprador, **Then** el
   Cliente igual se crea sin CUIT cargado — FR-007 aplica sin importar qué rama derivó el
   comprobante (ver Edge Cases).

---

### Edge Cases

- **Campo de documento vacío** (Cliente/Proveedor): dado que el N° de Doc es opcional, apretar
  "Verificar" con el campo vacío no debe mostrar "documento inválido" — el documento es opcional, no
  hay nada que validar.
- **Tipo de documento DNI u otro distinto de CUIT/CUIL**: el botón "Verificar" y la auto-validación de
  Mercado Libre sólo aplican cuando el tipo es CUIT o CUIL (documentos con dígito verificador). Un DNI,
  o cualquier otro tipo que Mercado Libre pueda informar (ej. "CI", "Otro"), no tiene ese algoritmo y
  queda fuera de esta validación, sin cambios respecto al comportamiento actual.
- **Cambio de tipo de documento después de verificar**: si el usuario aprieta "Verificar", ve el
  resultado, y después cambia el selector de tipo de documento (por ejemplo de CUIT a DNI) o edita el
  número, el mensaje de resultado de la verificación anterior DEBE limpiarse — no debe quedar un "es
  válido"/"no es válido" visible que ya no corresponde al valor actual de los campos.
- **Comprador de Mercado Libre que ya existe como Cliente** (emparejado por `ml_user_id` o apodo): si
  la orden trae un documento inválido, no debe pisar ni completar datos fiscales del Cliente ya
  existente — el mecanismo actual (`completarDatosFiscalesSinPisar`) sólo completa campos vacíos, así
  que un documento inválido simplemente no se usa como fuente para completarlos.
- **Orden con condición de IVA informada por Mercado Libre**: la derivación del **tipo de comprobante**
  sigue basándose en esa condición de IVA como hoy, sin mirar el documento en absoluto (esta feature
  no toca esa rama). Pero la regla de **no persistir un documento inválido en el Cliente** (FR-007) es
  independiente de qué rama derivó el comprobante y se aplica siempre — ver FR-007.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: El sistema DEBE mostrar un botón "Verificar" junto al campo N° de Documento, en los
  modales de alta y edición de Cliente y de Proveedor.
- **FR-002**: Al presionar "Verificar", el sistema DEBE validar de forma local (sin consultar ningún
  servicio externo) si el valor cargado es un CUIT/CUIL matemáticamente válido, y mostrar el
  resultado en menos de 1 segundo dentro del propio modal, sin necesidad de guardar el formulario.
  Cuando es inválido, el mensaje DEBE ser el mismo texto que ya usa la validación de guardado
  (`App\Rules\CuitValido`: "El CUIT ingresado no es válido."), no uno nuevo.
- **FR-003**: El sistema DEBE auto-formatear el campo N° de Documento con guiones a medida que se
  tipea (formato `XX-XXXXXXXX-X`), igual en Cliente y en Proveedor.
- **FR-004**: El sistema DEBE seguir bloqueando el guardado (alta o edición) de Cliente/Proveedor
  cuando el documento cargado sea matemáticamente inválido, se haya presionado "Verificar" o no —
  este comportamiento ya existe y no debe cambiar.
- **FR-005**: En la conversión automática de una orden de Mercado Libre a Venta, cuando el tipo de
  documento informado por Mercado Libre es CUIT o CUIL pero el número no es matemáticamente válido,
  el sistema DEBE tratarlo como si no se hubiese informado ningún documento fiscal válido, y aplicar
  el mismo comportamiento que cuando Mercado Libre no informa ni condición de IVA ni documento
  (Consumidor Final, Comprobante B).
- **FR-006**: Este tratamiento (FR-005) NO DEBE bloquear la conversión automática ni marcar la orden
  como "Requiere atención" por tratarse de un documento inválido — la conversión se completa igual,
  usando el fallback conservador.
- **FR-007**: Al dar de alta automáticamente un Cliente nuevo a partir de una orden de Mercado Libre,
  el sistema NO DEBE persistir en el campo CUIT del Cliente un número que no sea matemáticamente
  válido — ese campo debe quedar vacío en ese caso. Esta regla es **incondicional**: aplica sin
  importar si el tipo de comprobante de esa orden se derivó de la condición de IVA informada por
  Mercado Libre o de la aproximación por tipo de documento (FR-005) — un documento matemáticamente
  inválido nunca queda guardado como CUIT de un Cliente, en ningún camino de la conversión automática.
- **FR-008**: La validación de dígito verificador usada en FR-002, FR-005 y FR-007 DEBE ser la misma
  lógica/algoritmo en los tres casos, para que el criterio de "documento válido" sea consistente en
  todo el sistema.
- **FR-009**: Esta validación es puramente matemática/local (dígito verificador módulo 11); NO
  implica ninguna consulta a ARCA/padrón fiscal ni a ningún servicio externo — esa verificación sigue
  explícitamente fuera de alcance.
- **FR-010**: El sistema DEBE limpiar el resultado de una verificación previa (botón "Verificar") en
  cuanto el usuario modifique el tipo o el número de documento, para que nunca quede visible un
  resultado que ya no corresponde al valor actual de los campos.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Un usuario que carga un CUIT/CUIL inválido en el modal de Cliente o Proveedor recibe
  feedback de que es inválido en menos de 1 segundo, sin tener que llegar a intentar guardar el
  formulario.
- **SC-002**: El 100% de los números de documento cargados en ese campo quedan formateados con
  guiones, sin que el usuario tenga que tipearlos a mano.
- **SC-003**: Ninguna orden de Mercado Libre queda bloqueada ("Requiere atención") a causa de un
  documento de comprador matemáticamente inválido — la conversión automática se sigue completando.
- **SC-004**: Ningún Cliente dado de alta automáticamente desde Mercado Libre queda con un CUIT
  guardado que no pase la validación de dígito verificador.
- **SC-005**: El criterio de "documento válido" es idéntico entre el alta manual (botón Verificar) y
  la conversión automática de Mercado Libre — no hay dos algoritmos distintos conviviendo.

## Assumptions

- El algoritmo de validación (dígito verificador módulo 11, prefijos CUIT/CUIL) ya existe en el
  sistema y no cambia; esta feature reusa esa lógica en dos puntos donde hoy falta, no la reimplementa.
- "CUIL" se trata igual que "CUIT" a los efectos de esta validación: mismo algoritmo, mismo conjunto
  de prefijos válidos (ambos son números de 11 dígitos con la misma estructura de dígito verificador).
- El botón "Verificar" es una mejora de experiencia (feedback inmediato); no reemplaza ni relaja el
  bloqueo al guardar que ya existe hoy — ambos caminos (con y sin apretar el botón) siguen bloqueando
  igual ante un documento inválido.
- La verificación contra ARCA/padrón fiscal (consulta externa en vivo) sigue explícitamente fuera de
  alcance, sin cambios respecto a la decisión ya documentada.
- Cuando Mercado Libre sí informa la condición frente al IVA del comprador, el comprobante se sigue
  derivando de esa condición como hoy (principio III de la constitución); esta feature sólo afecta la
  rama de aproximación por tipo de documento que se usa cuando ese dato falta.
- **Autocompletado de datos (razón social/domicilio/condición de IVA) vía Padrón de ARCA — evaluado y
  diferido, no en alcance de esta spec** (decisión 30/07/2026): se planteó reemplazar/ampliar el botón
  "Verificar" para que, además de validar el dígito verificador, consulte el Padrón de ARCA y
  autocomplete los inputs del modal de Cliente/Proveedor (y que la conversión automática de Mercado
  Libre use ese mismo dato real de condición de IVA en vez de aproximarla por tipo de documento). Se
  descarta hacerlo dentro de esta spec porque el webservice oficial de Padrón exige autenticación WSAA
  con certificado propio del negocio — infraestructura que este proyecto no tiene todavía (se comparte
  con Facturación Electrónica/WSFEv1, ambas quedaron fuera de la lista de módulos construidos, ver
  `docs/documentacion_principal_crm.md` §7). La alternativa de un proveedor tercero (API paga, sin
  certificado propio) quedó identificada pero no evaluada en detalle. Queda documentado como pendiente
  ligado a una futura spec (a especificar cuando se aborde Facturación Electrónica, o antes si se
  decide puntualmente ir por el proveedor tercero) — no cambia nada del alcance ni del diseño de FR-001
  a FR-010 de esta spec, que siguen siendo sólo verificación local del dígito verificador.

- **Por qué "usar el fallback" y no "bloquear" ante un documento inválido de Mercado Libre** (FR-006,
  decisión explícita del usuario, 29/07/2026): a diferencia de un Cliente ambiguo (dos posibles
  matches, FR-038 de spec 012) — donde no hay forma automática correcta de elegir — un documento
  inválido sí tiene una resolución automática segura y conservadora: tratarlo como "sin datos
  fiscales", que es exactamente el comportamiento ya vigente y probado para compradores sin ningún
  dato fiscal informado (FR-040a de spec 012). Bloquear frenaría la automatización por un dato sucio
  de Mercado Libre que no requiere, en rigor, criterio humano para resolverse.
