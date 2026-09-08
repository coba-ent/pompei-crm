# Feature Specification: Reconocedor de CUIT por ARCA en Proveedores

**Feature Branch**: `100-reconocedor-cuit-proveedor`

**Created**: 2026-09-07

**Status**: Draft

**Input**: User description: "El modal de crear/editar Cliente tiene un botón Verificar junto al CUIT que consulta el padrón de ARCA y autocompleta razón social, domicilio fiscal, provincia, localidad y condición de IVA. En Proveedores el botón Verificar existe en el front pero sólo valida el dígito verificador del CUIT localmente: nunca consulta ARCA ni autocompleta nada. Portar la funcionalidad a Proveedores, con la derivación del Comprobante por defecto adaptada a la lógica de compra (Responsable Inscripto → A, Monotributista → C, resto → B)."

## Contexto

El reconocedor de CUIT contra el padrón de ARCA se construyó para Clientes en las specs 037
(consulta al padrón), 047 (condición de IVA vía constancia de inscripción) y 048 (derivación del
comprobante por defecto). En Proveedores quedó **sólo el botón**: la pantalla es "espejo
estructural de Clientes" (§2.3 del doc principal) y heredó el control visual, pero la consulta al
padrón nunca se implementó del lado del servidor.

Esto no es una degradación ni un bug de ejecución: es funcionalidad faltante que el usuario
descubre recién al usarla, porque el botón está visible y responde. La documentación de dominio
agrava la confusión al afirmar que Proveedores "reutiliza la misma validación de CUIT" que
Cliente — cierto para el dígito verificador, falso para el padrón.

El impacto práctico: quien da de alta un proveedor tiene que tipear a mano razón social,
domicilio, localidad, provincia y condición de IVA que ARCA ya conoce, con el riesgo de error de
transcripción en datos que después se usan para registrar comprobantes de compra.

## Clarifications

### Session 2026-09-07

- Q: Al editar un proveedor existente, los campos fiscales vienen precargados desde la base sin que
  el usuario los haya tocado en esa sesión del modal. ¿"Verificar" debe sobrescribirlos con lo que
  informe el padrón, o debe protegerlos? → A: Paridad con Cliente — se consideran "no tocados" y el
  padrón los sobrescribe. Es el comportamiento ya vigente y validado en Cliente; "Verificar" es una
  acción explícita del usuario, y una semántica distinta entre ambas pantallas para el mismo botón
  sería peor que el riesgo que evita.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Autocompletar los datos fiscales de un proveedor desde ARCA (Priority: P1)

Quien administra el CRM está dando de alta un proveedor nuevo del que sólo tiene el CUIT (por
ejemplo, de una factura que acaba de recibir). Escribe el CUIT en el bloque de Datos de
facturación, aprieta "Verificar" y el sistema completa por él la razón social, el domicilio
fiscal, la provincia, la localidad y la condición de IVA tal como figuran en ARCA. Revisa, ajusta
lo que quiera y guarda.

**Why this priority**: Es el núcleo del pedido y lo que hoy falta por completo. Entrega valor por
sí solo: elimina la carga manual y la transcripción errónea de los datos fiscales del proveedor.
Sin esto, el resto de las historias no tienen sentido.

**Independent Test**: Se puede probar de punta a punta abriendo el modal de Nuevo Proveedor,
ingresando un CUIT real y verificando que los cinco campos fiscales quedan cargados con lo que
devuelve el padrón, sin haber tocado ningún otro campo del formulario.

**Acceptance Scenarios**:

1. **Given** el modal de Nuevo Proveedor abierto con los campos fiscales vacíos y un CUIT válido
   ingresado que existe en el padrón de ARCA, **When** el usuario aprieta "Verificar", **Then** el
   sistema completa Razón Social, Domicilio Fiscal, Provincia Fiscal, Localidad Fiscal y Condición
   de IVA con los datos del padrón, y notifica el éxito de la consulta.
2. **Given** el mismo escenario, **When** los datos ya fueron autocompletados, **Then** el usuario
   puede editar cualquiera de esos campos y guardar el proveedor con los valores que dejó.
3. **Given** el modal de Editar Proveedor de un proveedor existente con datos fiscales ya cargados
   desde la base y no tocados en esta sesión del modal, **When** el usuario aprieta "Verificar" sobre
   su CUIT, **Then** el sistema sobrescribe esos campos con lo que informa el padrón (ver
   Clarifications, sesión 2026-09-07).
4. **Given** un CUIT ingresado cuyo dígito verificador es inválido, **When** el usuario aprieta
   "Verificar", **Then** el sistema informa que el CUIT no es válido y **no** consulta el padrón.
5. **Given** un tipo de documento distinto de CUIT o CUIL (por ejemplo DNI), **When** el usuario
   aprieta "Verificar", **Then** el sistema no realiza ninguna verificación ni consulta.

---

### User Story 2 - No perder lo que el usuario ya escribió a mano (Priority: P2)

Quien carga el proveedor ya escribió a mano algún dato fiscal —porque lo tiene de la factura en
papel, o porque el domicilio comercial que le interesa no es el que figura en ARCA— y después
aprieta "Verificar" para traer el resto. El sistema completa únicamente lo que el usuario no tocó
y respeta lo que ya había escrito.

**Why this priority**: Sin esta protección, el autocompletado destruye trabajo del usuario y se
vuelve peligroso de usar. Es lo que hace que la función sea confiable, pero la P1 ya entrega valor
en el caso normal (formulario vacío), por eso va segunda.

**Independent Test**: Se puede probar escribiendo a mano una razón social distinta a la del padrón,
apretando "Verificar", y confirmando que esa razón social sobrevive mientras el resto de los campos
sí se completan.

**Acceptance Scenarios**:

1. **Given** el modal abierto y un CUIT válido, **When** el usuario escribe manualmente la Razón
   Social y luego aprieta "Verificar", **Then** la Razón Social escrita a mano se conserva y los
   demás campos fiscales se completan desde el padrón.
2. **Given** el mismo escenario con la Condición de IVA elegida a mano, **When** el usuario aprieta
   "Verificar", **Then** la Condición de IVA elegida se conserva.
3. **Given** un modal donde el usuario ya autocompletó desde el padrón y luego cerró el modal,
   **When** vuelve a abrir el modal para otro proveedor, **Then** el sistema vuelve a considerar
   todos los campos como no tocados y puede autocompletarlos libremente.

---

### User Story 3 - Sugerir el comprobante que ese proveedor nos va a emitir (Priority: P3)

Al quedar determinada la Condición de IVA del proveedor —sea porque la trajo el padrón o porque el
usuario la eligió a mano— el sistema sugiere el "Tipo de comprobante por defecto" que ese proveedor
nos va a emitir, de modo que al registrar compras de ese proveedor el tipo ya venga propuesto
correctamente.

**Why this priority**: Ahorra un paso y previene un error de clasificación, pero es una comodidad
sobre una decisión que el usuario puede tomar solo; las dos historias anteriores entregan el grueso
del valor.

**Independent Test**: Se puede probar eligiendo distintas Condiciones de IVA en el modal (sin
siquiera consultar el padrón) y verificando que el Tipo de comprobante por defecto cambia según la
regla esperada.

**Acceptance Scenarios**:

1. **Given** el modal de Proveedor con el Tipo de comprobante por defecto sin tocar, **When** la
   Condición de IVA queda en "Responsable Inscripto", **Then** el Tipo de comprobante por defecto se
   propone como Factura A.
2. **Given** el mismo escenario, **When** la Condición de IVA queda en "Monotributista", **Then** el
   Tipo de comprobante por defecto se propone como Factura C.
3. **Given** el mismo escenario, **When** la Condición de IVA queda en "Consumidor Final", "Exento"
   o "No Categorizado", **Then** el Tipo de comprobante por defecto se propone como Factura B.
4. **Given** el usuario eligió manualmente un Tipo de comprobante por defecto, **When** después
   cambia la Condición de IVA, **Then** el tipo elegido a mano se conserva y no se pisa.

---

### User Story 4 - Que una falla de ARCA nunca impida dar de alta el proveedor (Priority: P1)

ARCA está caída, el certificado fiscal no está configurado, o el CUIT no figura en el padrón. Quien
carga el proveedor recibe un aviso claro de que no se pudo traer la información, completa los datos
a mano y guarda el proveedor sin ningún impedimento.

**Why this priority**: Es P1 porque una falla acá no puede bloquear una operación administrativa
básica. ARCA es un servicio externo que se cae con regularidad; el alta de proveedores no puede
quedar rehén de eso.

**Independent Test**: Se puede probar sin certificado fiscal configurado: el botón responde con un
aviso y el alta del proveedor se completa igual.

**Acceptance Scenarios**:

1. **Given** que no hay certificado fiscal activo configurado, **When** el usuario aprieta
   "Verificar" con un CUIT válido, **Then** el sistema informa que no se pudo consultar el padrón en
   este momento, no modifica ningún campo, y el usuario puede guardar el proveedor igual.
2. **Given** que ARCA no responde o devuelve un error, **When** el usuario aprieta "Verificar",
   **Then** el sistema informa que no se pudo consultar el padrón en este momento y el formulario
   queda operable.
3. **Given** un CUIT válido que no figura en el padrón de ARCA, **When** el usuario aprieta
   "Verificar", **Then** el sistema informa que no se encontró el CUIT en el padrón, no completa
   campos, y el usuario puede guardar el proveedor igual.
4. **Given** que el padrón responde con la identidad del contribuyente pero sin su condición de IVA,
   **When** se procesa la respuesta, **Then** se completan igual los campos que sí llegaron (razón
   social, domicilio, localidad, provincia) y la Condición de IVA queda a criterio del usuario.

---

### Edge Cases

- **El padrón devuelve una provincia que no existe en el catálogo del sistema**: no se selecciona
  provincia ni se intenta cargar la localidad; el resto de los campos se completa normalmente.
- **El padrón devuelve una localidad que no está entre las de esa provincia**: la provincia queda
  seleccionada y la localidad queda sin seleccionar, a criterio del usuario.
- **El padrón devuelve una condición de IVA que no coincide con ninguna de las del sistema**
  (Consumidor Final, Exento, Monotributista, No Categorizado, Responsable Inscripto): la Condición
  de IVA queda sin cambiar.
- **El usuario aprieta "Verificar" varias veces seguidas antes de que termine la primera consulta**:
  sólo se procesa una consulta a la vez; no se disparan consultas superpuestas.
- **El usuario modifica el CUIT después de haber verificado**: el resultado de la verificación
  anterior deja de mostrarse, para no dar por válido un CUIT que ya no es el que está escrito.
- **El proveedor consultado figura como inactivo en el padrón**: se completan igualmente sus datos;
  el estado en ARCA no impide registrarlo como proveedor del negocio.
- **El CUIT existe en el padrón pero sin domicilio fiscal informado**: se completan los campos que sí
  vinieron y los ausentes quedan vacíos, sin borrar lo que el usuario tuviera escrito.
- **Se edita un proveedor cuyo domicilio fiscal guardado difiere a propósito del que informa ARCA**:
  al apretar "Verificar" el valor guardado se pierde, porque el sistema no distingue un dato cargado
  a propósito de uno desactualizado. Riesgo aceptado por paridad con Cliente (ver Clarifications);
  el usuario puede volver a escribirlo antes de guardar, ya que nada se persiste hasta confirmar.

## Requirements *(mandatory)*

### Functional Requirements

#### Consulta al padrón

- **FR-001**: El sistema DEBE consultar el padrón de ARCA cuando el usuario solicita verificar un
  documento de tipo CUIT o CUIL en el modal de alta o edición de Proveedor, obteniendo el mismo
  conjunto de información que ya obtiene para Cliente: razón social, domicilio fiscal, localidad
  fiscal, provincia fiscal y condición de IVA.
- **FR-002**: El sistema DEBE validar primero que el número ingresado sea un CUIT/CUIL
  matemáticamente válido y, sólo si lo es, consultar el padrón. Un número inválido se informa como
  tal sin generar consulta externa.
- **FR-003**: El sistema NO DEBE realizar verificación alguna cuando el tipo de documento no es CUIT
  ni CUIL.
- **FR-004**: La condición de IVA DEBE obtenerse aunque provenga de una fuente distinta a la de los
  datos de identidad, y su ausencia NO DEBE impedir que se entreguen los demás datos ya obtenidos.

#### Degradación ante fallas

- **FR-005**: Ninguna falla en la consulta al padrón —ausencia de certificado fiscal, servicio de
  ARCA no disponible, error de comunicación, o CUIT no encontrado— DEBE impedir crear, editar o
  guardar un proveedor.
- **FR-006**: El sistema DEBE informar al usuario el resultado de la consulta distinguiendo tres
  situaciones: no se pudo consultar el padrón, el CUIT no se encontró en el padrón, y los datos se
  cargaron correctamente.
- **FR-007**: Ante una falla de consulta, el sistema NO DEBE modificar ningún campo del formulario.

#### Autocompletado en el formulario

- **FR-008**: El sistema DEBE completar con los datos del padrón los campos Razón Social, Domicilio
  Fiscal, Provincia Fiscal, Localidad Fiscal y Condición de IVA del modal de Proveedor.
- **FR-009**: El sistema NO DEBE sobrescribir un campo que el usuario haya editado manualmente desde
  que abrió el modal.
- **FR-010**: El registro de qué campos fueron editados manualmente DEBE reiniciarse cada vez que se
  abre el modal, de modo que un proveedor nuevo o distinto no arrastre el estado del anterior. En
  consecuencia, al editar un proveedor existente los valores fiscales precargados desde la base se
  consideran no editados manualmente y PUEDEN ser sobrescritos por el padrón: sólo se protege lo que
  el usuario escribió después de abrir el modal. Es el mismo criterio vigente en Cliente.
- **FR-011**: Al completar la ubicación fiscal, el sistema DEBE resolver primero la provincia y sólo
  después la localidad, dado que las localidades disponibles dependen de la provincia seleccionada.
- **FR-012**: Si un valor devuelto por el padrón no tiene correspondencia entre las opciones
  disponibles del sistema (provincia, localidad o condición de IVA), el campo correspondiente DEBE
  quedar sin modificar en lugar de recibir un valor aproximado o inválido.
- **FR-013**: El sistema DEBE impedir que se disparen consultas al padrón superpuestas por
  pulsaciones repetidas del control de verificación.
- **FR-014**: El resultado visible de una verificación DEBE dejar de mostrarse cuando el usuario
  modifica el número de documento o su tipo.

#### Derivación del comprobante por defecto

- **FR-015**: Cuando queda determinada la Condición de IVA del proveedor —por autocompletado desde el
  padrón o por elección manual del usuario— el sistema DEBE proponer el Tipo de comprobante por
  defecto según la condición: Responsable Inscripto → Factura A; Monotributista → Factura C;
  cualquier otra condición → Factura B.
- **FR-016**: El sistema NO DEBE proponer un Tipo de comprobante por defecto si el usuario ya lo
  eligió manualmente desde que abrió el modal.
- **FR-017**: La derivación del Tipo de comprobante por defecto en Proveedor DEBE ser independiente
  de la de Cliente, porque representan hechos distintos: en Cliente es el comprobante que el negocio
  emite; en Proveedor es el que el proveedor le emite al negocio.

#### No regresión

- **FR-018**: El comportamiento actual del reconocedor de CUIT en Clientes NO DEBE cambiar, incluida
  su regla propia de derivación del comprobante por defecto (Responsable Inscripto → A, cualquier
  otra → B). Esto alcanza tanto a la pantalla de Clientes como al alta rápida de cliente incluida en
  los formularios de Venta y Presupuesto.

#### Alcance: todas las pantallas donde se da de alta un proveedor

- **FR-019**: La funcionalidad DEBE comportarse de forma idéntica en **todas** las pantallas que
  ofrecen el modal de alta/edición de Proveedor, no sólo en el listado de Proveedores. Al momento de
  esta spec son dos: la pantalla de Proveedores y el **alta rápida de proveedor dentro del formulario
  de Compra**.
- **FR-020**: En particular, la regla de derivación del comprobante por defecto (FR-015) DEBE ser la
  de compra (A/C/B) en todas esas pantallas. Un mismo proveedor no puede recibir una sugerencia
  distinta según desde dónde se lo dé de alta.

### Key Entities

- **Proveedor**: entidad ya existente. Esta funcionalidad completa sus atributos fiscales (razón
  social, documento y tipo, condición de IVA, tipo de comprobante por defecto, domicilio/localidad/
  provincia fiscales) pero **no agrega ni modifica ningún campo** del modelo.
- **Condición de IVA**: catálogo ya existente con cinco valores (Consumidor Final, Exento,
  Monotributista, No Categorizado, Responsable Inscripto). Es la clave de la derivación del
  comprobante por defecto. No se modifica.
- **Resultado de consulta al padrón**: información transitoria obtenida de ARCA para un CUIT
  (identidad, domicilio y condición de IVA). No se persiste: sólo alimenta el formulario en el
  momento de la consulta.
- **Certificado fiscal**: credencial ya existente que habilita las consultas a ARCA. Su ausencia
  degrada la funcionalidad sin romperla.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Dar de alta un proveedor del que sólo se conoce el CUIT requiere que el usuario escriba
  únicamente el nombre y el CUIT; los cinco datos fiscales restantes llegan sin tipeo manual.
- **SC-002**: El 100% de las fallas de ARCA (servicio caído, sin certificado, CUIT inexistente)
  terminan con el proveedor pudiendo guardarse igual, y con un mensaje que distingue cuál de las tres
  situaciones ocurrió.
- **SC-003**: Ningún dato fiscal escrito a mano por el usuario se pierde al usar la verificación: en
  0 de los casos un campo editado manualmente resulta sobrescrito.
- **SC-004**: El comprobante por defecto propuesto coincide con el que el proveedor efectivamente
  emite en las cinco condiciones de IVA del catálogo.
- **SC-005**: El comportamiento del modal de Cliente permanece idéntico al actual, verificable porque
  su cobertura de pruebas existente sigue en verde sin modificaciones.
- **SC-006**: La verificación en Proveedor produce el mismo resultado que la de Cliente para un mismo
  CUIT, en los campos que ambos formularios comparten.
- **SC-007**: Dar de alta el mismo proveedor desde la pantalla de Proveedores o desde el formulario
  de Compra produce exactamente los mismos datos autocompletados y la misma sugerencia de comprobante.

## Assumptions

- Se asume que la funcionalidad de Cliente (specs 037, 047 y 048) es la referencia correcta y está
  validada en producción; esta feature busca paridad con ella, no rediseñarla.
- Se asume que los campos fiscales del modal de Proveedor son equivalentes a los de Cliente, por lo
  que no hay que agregar campos nuevos ni renombrar los existentes.
- Se asume que la derivación del comprobante por defecto en Proveedor sigue la lógica de compra
  (A/C/B) confirmada por el usuario, y no la de Cliente (A/B). Esta regla es una **sugerencia
  editable**, no una restricción: no bloquea ni valida el guardado, y por lo tanto no contradice el
  Principio III de la constitución (que exige que el tipo de comprobante *emitido* se derive de la
  condición de IVA sin poder saltearse). El comprobante que un tercero nos emite es un dato
  informado, no una decisión fiscal del negocio.
- Se asume que la opción "Factura E" del selector de comprobante por defecto de Proveedor
  (exportación) queda fuera de la derivación automática, ya que no se deduce de la condición de IVA
  sino del carácter internacional de la operación. Se elige a mano cuando corresponde.
- Se asume que la consulta al padrón se dispara únicamente por acción explícita del usuario sobre el
  control de verificación, y no automáticamente al tipear el CUIT, para no generar tráfico contra
  ARCA en cada pulsación.
- Se asume que los mensajes al usuario mantienen el mismo texto que ya usa Cliente, por consistencia.
- Se asume que este cambio no requiere modificaciones en el modelo de datos.

## Dependencias

- Requiere la integración con ARCA ya existente (autenticación y consulta de padrón/constancia de
  inscripción), construida en las specs 034, 037 y 047.
- Requiere un certificado fiscal activo configurado para que la consulta funcione; sin él, la
  funcionalidad degrada informando el problema.
- Depende del catálogo de provincias y del mecanismo de localidades por provincia ya existentes en el
  modal de Proveedor.

## Fuera de alcance

- Modificar el comportamiento del reconocedor de CUIT en Clientes.
- Consultar el padrón automáticamente sin acción del usuario, o de forma masiva sobre los proveedores
  ya cargados.
- Persistir o cachear las respuestas del padrón de ARCA.
- Agregar campos nuevos al Proveedor o cambiar el modelo de datos.
- Derivar automáticamente el comprobante "Factura E" (exportación).
