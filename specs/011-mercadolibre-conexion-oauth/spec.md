# Feature Specification: Funciones Avanzadas + Conexión de cuenta Mercado Libre (OAuth)

**Feature Branch**: `011-mercadolibre-conexion-oauth`

**Created**: 2026-07-27

**Status**: Draft

**Input**: User description: "Integración Mercado Libre — Etapa 1: pantalla de Funciones Avanzadas y conexión de cuenta (OAuth). Crear la pantalla Funciones Avanzadas dentro de Configuración & Ajustes, con una tarjeta/pestaña Mercado Libre que permita cargar las credenciales de la aplicación propia del DevCenter, disparar el flujo OAuth, mostrar el estado de la conexión y los datos de la cuenta vinculada, probar la conexión, desconectar, y un kill-switch de sólo lectura. Gestión de tokens con refresh automático y protección contra refresh concurrente."

## Contexto y fuentes

Esta spec abre el módulo de **integración con Mercado Libre**, y es —de forma deliberada y
documentada— **la única parte del CRM que NO calca a Contagram**.

**Divergencia respecto de Contagram (explícita y justificada)**: según el relevamiento con capturas
reales (`docs/informe_contagram_funciones_avanzadas.md` §3, captura `[103]`), Contagram resuelve la
integración con un asistente de **2 pasos** ("Solicitar Acceso" → "Acceso Permitido") sobre una
aplicación de Mercado Libre **propia de Contagram**: el negocio sólo autoriza y obtiene capacidades
de lectura. Este CRM va deliberadamente más profundo: **aplicación propia del negocio en el DevCenter
de Mercado Libre**, con OAuth 2.0 y permisos de **lectura y escritura**, para poder modificar stock,
precios y publicaciones desde el CRM — algo que el acceso básico de Contagram no permite.

Esta divergencia está autorizada por el usuario y **debe quedar registrada** en
`docs/documentacion_principal_crm.md` como excepción explícita al principio rector de fidelidad
estructural del `CLAUDE.md` (que sigue vigente para todo el resto del sistema).

**Fuente de verdad estructural para la pantalla contenedora**: `docs/informe_contagram_funciones_avanzadas.md`
§1 (lista vertical de 10 tarjetas con ícono, nombre, descripción de una línea y toggle Sí/No). La
pantalla "Funciones Avanzadas" **sí** calca a Contagram; lo que diverge es únicamente el contenido de
la tarjeta de Mercado Libre.

**Fuente de dominio**: `docs/documentacion_principal_crm.md`, `docs/modelo_datos.md`.

**Estado actual del CRM**: no existe la pantalla "Funciones Avanzadas". El menú "Configuración &
Ajustes" hoy expone sólo "Usuarios y Permisos" y "Depósitos" (spec 005), este último bajo el permiso
`configuracion.funciones`.

## Alcance

**Incluye**: la pantalla Funciones Avanzadas, la activación/desactivación persistida de funciones, la
configuración de la integración con Mercado Libre, el flujo de vinculación OAuth completo, el ciclo de
vida de los tokens, el kill-switch de sólo lectura, el registro de operaciones contra la API y la
verificación de conexión.

**Excluye explícitamente** (van en specs posteriores): importación de publicaciones, matcheo de
publicaciones con productos del CRM, sincronización de stock y precios, ingreso de ventas/órdenes de
Mercado Libre al CRM, preguntas, mensajería, envíos, y la recepción/procesamiento de notificaciones
(webhooks) de negocio. Esta spec deja **la base de conexión y el cliente de API** sobre la que esas
specs se apoyarán.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Ver y administrar las Funciones Avanzadas (Priority: P1)

Como responsable del negocio, entro a Configuración & Ajustes → Funciones Avanzadas y veo la lista de
funciones del sistema, cada una con su nombre, una descripción breve y un interruptor Sí/No que
refleja si está activa. Puedo activar o desactivar las funciones disponibles, y el cambio queda
guardado sin que la página se recargue.

**Why this priority**: es la pantalla contenedora. Sin ella no hay dónde alojar la configuración de
Mercado Libre, y además es una pantalla que el CRM le debe a Contagram independientemente de esta
integración. Entrega valor por sí sola.

**Independent Test**: se puede probar entrando a la pantalla, alternando el interruptor de una función
y verificando que el estado persiste tras recargar, sin tocar nada de Mercado Libre.

**Acceptance Scenarios**:

1. **Given** un usuario con permiso de configuración, **When** entra a Funciones Avanzadas, **Then** ve la lista completa de funciones con su estado actual (activa/inactiva).
2. **Given** la función "Depósitos" ya operativa en el CRM, **When** el usuario la ve en la lista, **Then** aparece activa y con acceso directo a su configuración existente.
3. **Given** una función activable, **When** el usuario cambia su interruptor, **Then** el sistema confirma por notificación y el estado persiste al recargar, sin recarga de página durante la operación.
4. **Given** una función todavía no construida en el CRM, **When** el usuario la ve, **Then** aparece listada pero deshabilitada e identificada como no disponible aún, y su interruptor no puede activarse.
5. **Given** un usuario sin permiso de configuración, **When** intenta acceder a la pantalla, **Then** el sistema le deniega el acceso.

---

### User Story 2 - Configurar las credenciales de la aplicación de Mercado Libre (Priority: P1)

Como responsable del negocio, entro a la configuración de Mercado Libre y cargo los datos de la
aplicación que creé en el DevCenter de Mercado Libre (identificador de aplicación y clave secreta),
además del sitio de operación. La pantalla me muestra —en modo sólo lectura y con botón de copiar— la
dirección de retorno que debo registrar en el DevCenter, para que coincida exactamente.

**Why this priority**: sin credenciales cargadas no puede iniciarse ninguna vinculación. Es el paso
previo obligatorio de la historia 3.

**Independent Test**: se puede probar cargando credenciales, verificando que se guardan, que la clave
secreta nunca vuelve a mostrarse en claro y que la dirección de retorno mostrada es copiable.

**Acceptance Scenarios**:

1. **Given** la configuración vacía, **When** el usuario entra a la pestaña Mercado Libre, **Then** ve el formulario de credenciales y la conexión figura como "No configurada".
2. **Given** el formulario de credenciales, **When** el usuario guarda datos válidos, **Then** el sistema confirma por notificación y habilita la acción de conectar.
3. **Given** credenciales ya guardadas, **When** el usuario vuelve a la pantalla, **Then** ve el identificador de aplicación pero **nunca** la clave secreta en claro (sólo un indicador de que está cargada y la opción de reemplazarla).
4. **Given** el formulario, **When** el usuario deja vacío un campo obligatorio o carga un valor con formato inválido, **Then** el sistema muestra el error asociado al campo sin recargar la página ni perder lo ya escrito.
5. **Given** la pantalla de configuración, **When** el usuario mira la dirección de retorno, **Then** puede copiarla con un clic y coincide exactamente con la que el sistema usará al conectar.

---

### User Story 3 - Vincular la cuenta de Mercado Libre y ver el estado de la conexión (Priority: P1)

Como responsable del negocio, presiono "Conectar con Mercado Libre", autorizo la aplicación en el
sitio de Mercado Libre, y vuelvo al CRM viendo la conexión establecida junto con los datos de la
cuenta vinculada (apodo, identificador de usuario, correo, tipo de cuenta), la fecha de vinculación y
el estado de vigencia del acceso.

**Why this priority**: es el objetivo central de la spec. Es el hito que el usuario definió como
condición para avanzar con el resto del módulo ("asegurarnos de que estemos conectados y que recibamos
los datos").

**Independent Test**: se puede probar de punta a punta con una cuenta de prueba de Mercado Libre:
iniciar la vinculación, autorizar y verificar que el panel muestra los datos reales traídos de la
cuenta.

**Acceptance Scenarios**:

1. **Given** credenciales cargadas y sin conexión activa, **When** el usuario presiona "Conectar con Mercado Libre", **Then** el sistema lo lleva a la pantalla de autorización de Mercado Libre solicitando permisos de lectura, escritura y acceso prolongado.
2. **Given** el usuario autorizó la aplicación, **When** Mercado Libre lo devuelve al CRM, **Then** el sistema completa la vinculación, obtiene y muestra los datos de la cuenta vinculada, y presenta el estado "Conectada" con la fecha de vinculación.
3. **Given** el usuario canceló o rechazó la autorización en Mercado Libre, **When** vuelve al CRM, **Then** el sistema informa que la vinculación no se completó y la conexión permanece desconectada, sin dejar datos parciales.
4. **Given** un retorno de autorización cuyo identificador de sesión no coincide con el emitido por el sistema, **When** llega al CRM, **Then** el sistema rechaza la vinculación y registra el intento como incidente de seguridad.
5. **Given** una conexión activa, **When** el usuario mira el panel de estado, **Then** ve apodo, identificador de usuario, correo, tipo de cuenta, sitio, fecha de vinculación, vencimiento del acceso vigente y fecha del último renovado exitoso.
6. **Given** una conexión activa, **When** el usuario presiona "Probar conexión", **Then** el sistema realiza una verificación real contra Mercado Libre y muestra el resultado por notificación, actualizando el panel si el estado cambió.
7. **Given** una conexión activa, **When** el usuario presiona "Desconectar" y confirma, **Then** el sistema elimina las credenciales de acceso almacenadas, deja la conexión como desconectada y lo registra en el historial.
8. **Given** una cuenta ya vinculada, **When** el usuario vuelve de la autorización habiendo autorizado con **otra** cuenta, **Then** el sistema retiene la autorización sin reemplazar nada, muestra ambas cuentas y pide confirmación explícita.
9. **Given** una autorización retenida a la espera de confirmación, **When** el usuario la rechaza o abandona, **Then** la cuenta vigente permanece intacta y la autorización retenida se descarta sin efectos.

---

### User Story 4 - Operar con seguridad: modo sólo lectura y diagnóstico (Priority: P2)

Como responsable técnico, activo el modo "sólo lectura" para poder vincular la cuenta real del negocio
sin riesgo de modificar publicaciones verdaderas durante el desarrollo, y consulto el historial de
operaciones contra Mercado Libre para diagnosticar problemas.

**Why this priority**: no es imprescindible para que la conexión funcione, pero es la salvaguarda que
permite trabajar contra datos reales sin causar daño, y el historial es lo que hace diagnosticable la
integración. Alto valor, dependencia baja.

**Independent Test**: se puede probar activando el interruptor y verificando que una operación de
escritura queda registrada como bloqueada en vez de ejecutarse.

**Acceptance Scenarios**:

1. **Given** el modo sólo lectura activo, **When** cualquier parte del sistema intenta una operación de escritura hacia Mercado Libre, **Then** la operación no se envía, se registra en el historial como bloqueada y quien la invocó recibe una respuesta que lo indica.
2. **Given** el modo sólo lectura activo, **When** el sistema realiza una operación de lectura, **Then** se ejecuta normalmente.
3. **Given** el modo sólo lectura activo, **When** el usuario mira la pantalla de configuración, **Then** ve un aviso visible y permanente de que las escrituras están bloqueadas.
4. **Given** operaciones ya realizadas, **When** el usuario consulta el historial, **Then** ve un listado paginado y filtrable con fecha, operación, resultado, código de respuesta y detalle del error si lo hubo.
5. **Given** el historial, **When** se registra cualquier operación, **Then** ningún dato sensible (claves, credenciales de acceso) queda visible en el registro.

---

### User Story 5 - Mantener la conexión viva sin intervención (Priority: P2)

Como responsable del negocio, no quiero tener que reconectar la cuenta cada pocas horas: el sistema
renueva el acceso solo, y si algo se rompe de forma irrecuperable me avisa claramente en la pantalla
para que vuelva a autorizar.

**Why this priority**: sin renovación automática la integración es inutilizable en la práctica, pero
sólo puede probarse una vez que la vinculación de la historia 3 existe.

**Independent Test**: se puede probar forzando el vencimiento del acceso almacenado y verificando que
la siguiente operación se ejecuta correctamente tras renovarlo de forma transparente.

**Acceptance Scenarios**:

1. **Given** un acceso próximo a vencer, **When** el sistema necesita operar contra Mercado Libre, **Then** renueva el acceso automáticamente y completa la operación sin intervención del usuario.
2. **Given** dos procesos que necesitan operar al mismo tiempo con el acceso vencido, **When** ambos intentan renovarlo, **Then** sólo uno realiza la renovación y el otro espera y reutiliza el resultado, sin invalidar la cadena de renovación.
3. **Given** una renovación que falla de forma irrecuperable, **When** el sistema lo detecta, **Then** marca la conexión como caída, lo muestra de forma destacada en la pantalla con la acción para volver a vincular, y deja de reintentar.
4. **Given** una operación rechazada por exceso de solicitudes, **When** el sistema la detecta, **Then** espera un tiempo creciente antes de reintentar y no la descarta silenciosamente.
5. **Given** una falla temporal del servicio de Mercado Libre, **When** el sistema la detecta, **Then** reintenta un número acotado de veces y, si persiste, registra el error sin marcar la conexión como caída.

---

### Edge Cases

- **La dirección de retorno registrada en el DevCenter no coincide con la del sistema**: Mercado Libre rechaza la autorización. El sistema debe mostrar un mensaje que apunte explícitamente a esa causa y recordar cuál es la dirección exacta a registrar.
- **El usuario vincula una cuenta de Mercado Libre distinta de la que ya estaba vinculada**: se detecta al volver de la autorización (antes no se sabe qué cuenta va a elegir). El sistema retiene la autorización sin reemplazar nada, pide confirmación mostrando ambas cuentas, y registra el cambio en el historial sólo si se confirma.
- **Queda una autorización retenida sin confirmar**: debe descartarse por vencimiento en lugar de quedar viva indefinidamente, y no debe interferir con la cuenta vigente ni con un intento de vinculación posterior.
- **La cuenta autorizada no coincide con el sitio configurado** (por ejemplo, cuenta de otro país): el sistema debe rechazar la vinculación con un mensaje claro.
- **El retorno de autorización llega dos veces** (usuario recarga la página de retorno): el segundo intento debe ser inofensivo y no romper la conexión ya establecida.
- **El identificador de sesión de la autorización expiró** (el usuario dejó la pantalla abierta demasiado tiempo): el sistema debe rechazar y pedir reiniciar la vinculación.
- **Se cambian las credenciales de la aplicación con una conexión activa**: las credenciales de acceso vigentes dejan de ser válidas; el sistema debe advertirlo y marcar la conexión como pendiente de re-vinculación.
- **Se desactiva la función Mercado Libre desde Funciones Avanzadas con una conexión activa**: el sistema debe pedir confirmación explicando que se suspenderán las operaciones, y conservar (no borrar) la vinculación salvo desconexión explícita.
- **La clave secreta almacenada no puede descifrarse** (cambio de la clave de aplicación del entorno): el sistema debe informarlo de forma comprensible en vez de fallar de manera opaca.
- **Pérdida de la cadena de renovación**: si la credencial de renovación quedó inutilizable, la única salida es re-autorizar; el sistema debe decirlo explícitamente en vez de reintentar en vano.
- **Operación de escritura mientras el modo sólo lectura está activo**: debe quedar claro para quien la invocó que no se ejecutó, para que no se interprete como éxito.

## Requirements *(mandatory)*

### Functional Requirements — Pantalla Funciones Avanzadas

- **FR-001**: El sistema DEBE ofrecer una pantalla "Funciones Avanzadas" dentro de Configuración & Ajustes, accesible desde el menú lateral, que liste las funciones del sistema como tarjetas verticales con ícono, nombre, descripción de una línea e interruptor Sí/No, replicando la estructura relevada de Contagram.
- **FR-002**: El sistema DEBE listar las diez funciones relevadas, en el mismo orden: Facturación electrónica, Mercado Libre, Tiendanube, Reportes por email, Abonos, IA, Retenciones, Ventas sin stock, Depósitos, Lector de código de barras.
- **FR-003**: El sistema DEBE persistir el estado activo/inactivo de cada función y reflejarlo al volver a la pantalla.
- **FR-004**: El sistema DEBE distinguir visualmente las funciones aún no construidas en el CRM, presentándolas deshabilitadas e identificadas como no disponibles, impidiendo su activación.
- **FR-005**: El sistema DEBE mostrar la función "Depósitos" como activa y ofrecer acceso directo a su configuración ya existente.
- **FR-005a**: El sistema DEBE pedir confirmación al desactivar la función "Mercado Libre" mientras exista una cuenta conectada, explicando que se suspenderán las operaciones contra Mercado Libre y que la vinculación **se conserva** (no se desconecta la cuenta).
- **FR-005b**: El sistema DEBE rechazar toda operación contra Mercado Libre mientras la función "Mercado Libre" esté desactivada, registrándola en el historial del mismo modo que una operación bloqueada, sin que ello altere el estado de la conexión.
- **FR-006**: El sistema DEBE restringir el acceso a esta pantalla a usuarios con permiso de configuración de funciones, y denegarlo al resto.
- **FR-007**: El sistema DEBE aplicar los cambios de interruptor sin recargar la página y confirmar el resultado mediante notificación.
- **FR-008**: El sistema DEBE registrar qué usuario activó o desactivó cada función y cuándo.

### Functional Requirements — Configuración de la integración

- **FR-009**: El sistema DEBE permitir cargar y actualizar las credenciales de la aplicación de Mercado Libre: identificador de aplicación, clave secreta y sitio de operación.
- **FR-010**: El sistema DEBE almacenar la clave secreta de forma cifrada y NUNCA devolverla en claro a la interfaz, ni siquiera al usuario que la cargó.
- **FR-011**: El sistema DEBE mostrar la dirección de retorno de autorización en modo sólo lectura, con acción de copiado, y esa dirección DEBE ser exactamente la que el sistema utilice al iniciar la vinculación.
- **FR-011a**: El sistema DEBE advertir en pantalla cuando la dirección de retorno que muestra no se corresponda con la dirección por la que se está accediendo realmente, o cuando no use conexión segura, indicando que la vinculación va a fallar hasta corregir la configuración del entorno.
- **FR-012**: El sistema DEBE validar los datos de configuración y devolver los errores asociados a cada campo sin recargar la página.
- **FR-013**: El sistema DEBE impedir iniciar una vinculación mientras la configuración esté incompleta, nombrando explícitamente en el mensaje cuál es el dato que falta.
- **FR-014**: El sistema DEBE advertir al usuario, cuando modifica las credenciales de la aplicación con una conexión activa, que la conexión existente quedará invalidada.

### Functional Requirements — Vinculación (OAuth)

- **FR-015**: El sistema DEBE iniciar la vinculación redirigiendo al usuario al sitio de autorización de Mercado Libre correspondiente al sitio configurado.

  > **Corrección respecto de una versión anterior de esta spec** (verificado contra la documentación oficial): los permisos **no se solicitan en la dirección de autorización**. Se configuran como *permisos funcionales* en la aplicación del DevCenter, por área (Usuarios, Publicación y sincronización, Ventas y envíos, …), cada una con alcance "sólo lectura" o "lectura y escritura". La pantalla de configuración del CRM debe **indicarle al usuario qué permisos funcionales habilitar** en el DevCenter, ya que el sistema no puede pedirlos por sí mismo.
- **FR-015a**: El sistema DEBE informar en la pantalla de configuración qué permisos funcionales debe habilitar el usuario en su aplicación del DevCenter, **advirtiendo explícitamente que conviene habilitar desde el inicio todos los que se necesitarán en etapas posteriores** —publicaciones, ventas y comunicación, en modo lectura y escritura— aunque esta etapa sólo use el de usuarios.

  > **Motivo**: el alcance de la autorización queda fijado con los permisos que la aplicación tenía al momento de otorgarse. Habilitar un permiso después obliga a **re-vincular la cuenta**, lo que en producción implica interrumpir al usuario. Habilitar de más no tiene costo: se otorgan todos en una sola pantalla de autorización.
- **FR-015b**: El sistema DEBE advertir que la autorización debe realizarse con la **cuenta principal** de Mercado Libre: los usuarios operadores o colaboradores no pueden otorgar el permiso, y el intento devuelve un error específico que debe traducirse a un mensaje comprensible.
- **FR-016**: El sistema DEBE generar por cada intento de vinculación un identificador de sesión único, de un solo uso y con vencimiento, y DEBE rechazar todo retorno cuyo identificador no coincida, esté vencido o ya haya sido usado.
- **FR-017**: El sistema DEBE, al recibir un retorno de autorización válido, canjearlo por las credenciales de acceso y de renovación, y persistirlas de forma cifrada.
- **FR-018**: El sistema DEBE obtener y almacenar, al momento de vincular, los datos de la cuenta de Mercado Libre: identificador de usuario, apodo, correo, tipo de cuenta y sitio.
- **FR-019**: El sistema DEBE rechazar la vinculación cuando la cuenta autorizada pertenezca a un sitio distinto del configurado, informando el motivo.
- **FR-020**: El sistema DEBE manejar el rechazo o cancelación de la autorización por parte del usuario informándolo con claridad y sin dejar datos parciales persistidos.
- **FR-021**: El sistema DEBE tratar un retorno de autorización repetido de forma idempotente, sin romper ni duplicar la conexión establecida.
- **FR-022**: El sistema DEBE detectar, **al volver de la autorización**, si la cuenta autorizada es distinta de la ya vinculada, y en ese caso NO reemplazarla automáticamente: debe retener la autorización en un estado intermedio, mostrar ambas cuentas (la vigente y la nueva) y exigir confirmación explícita antes de sustituirla. Si el usuario no confirma, la cuenta vigente permanece intacta y la autorización retenida se descarta.

  > **Nota de diseño**: la confirmación no puede pedirse antes de redirigir a Mercado Libre, porque hasta que el usuario no autoriza no se sabe con qué cuenta lo hizo. El punto de control es el retorno, no el inicio.

### Functional Requirements — Estado y operación de la conexión

- **FR-023**: El sistema DEBE presentar un panel de estado que indique de forma inequívoca si la conexión está No configurada, Desconectada, Conectada o Caída (requiere re-vinculación).
- **FR-024**: El sistema DEBE mostrar, con la conexión activa: apodo, identificador de usuario, correo, tipo de cuenta, sitio, fecha de vinculación, vencimiento del acceso vigente y fecha del último renovado exitoso.
- **FR-025**: El sistema DEBE ofrecer una acción "Probar conexión" que realice una verificación real contra Mercado Libre e informe el resultado por notificación, actualizando el estado mostrado.
- **FR-026**: El sistema DEBE ofrecer una acción "Desconectar", con confirmación previa, que elimine las credenciales almacenadas y deje la conexión como desconectada.
- **FR-027**: El sistema DEBE conservar los datos de la cuenta y el historial tras una desconexión, salvo las credenciales de acceso, para trazabilidad.

### Functional Requirements — Ciclo de vida de credenciales

- **FR-028**: El sistema DEBE renovar automáticamente el acceso, sin intervención del usuario, cuando esté vencido o próximo a vencer, antes de ejecutar cualquier operación.
- **FR-028a**: El sistema DEBE calcular el vencimiento del acceso a partir del valor de vigencia **que devuelve el proveedor en cada respuesta**, sin asumir una duración fija. La documentación del proveedor es ambigua al respecto (el texto declara 6 horas y los ejemplos muestran 3), por lo que fijar el valor en el código produciría renovaciones tardías y fallos intermitentes.
- **FR-028b**: El sistema DEBE contemplar que la autorización puede invalidarse **antes** de su vencimiento previsto por causas externas —cambio de contraseña del usuario en Mercado Libre, revocación de permisos, modificación de la clave secreta de la aplicación, o inactividad prolongada— y tratar esos casos como conexión caída que requiere re-vinculación, no como un error transitorio.
- **FR-028c**: El sistema DEBE contemplar que la credencial de renovación tiene una vida máxima (6 meses según el proveedor), tras la cual la re-vinculación manual es inevitable, e informarlo como tal en lugar de reintentar.
- **FR-029**: El sistema DEBE reemplazar la credencial de renovación por la nueva que se recibe en cada renovación, garantizando que nunca se reutilice una ya consumida.
- **FR-030**: El sistema DEBE garantizar que dos procesos concurrentes no renueven simultáneamente: sólo uno ejecuta la renovación y el otro espera y reutiliza el resultado.
- **FR-031**: El sistema DEBE marcar la conexión como caída y solicitar re-vinculación en la interfaz cuando la renovación falle de forma irrecuperable, dejando de reintentar.
- **FR-032**: El sistema DEBE aplicar una espera creciente entre reintentos ante rechazos por exceso de solicitudes, sin descartar la operación silenciosamente.
- **FR-033**: El sistema DEBE reintentar un número acotado de veces ante fallas temporales del servicio y, si persisten, registrar el error sin marcar la conexión como caída.
- **FR-034**: El sistema NO DEBE registrar credenciales, claves ni datos sensibles en ningún log o historial visible.

### Functional Requirements — Modo sólo lectura e historial

- **FR-035**: El sistema DEBE ofrecer un interruptor "Modo sólo lectura" que, estando activo, impida la ejecución de toda operación de escritura hacia Mercado Libre.
- **FR-036**: El sistema DEBE registrar toda operación de escritura bloqueada por el modo sólo lectura, con el detalle de lo que se habría enviado, y devolver a quien la invocó una respuesta que lo indique inequívocamente.
- **FR-037**: El sistema DEBE permitir normalmente las operaciones de lectura mientras el modo sólo lectura esté activo.
- **FR-038**: El sistema DEBE mostrar un aviso visible y permanente en la pantalla de configuración mientras el modo sólo lectura esté activo.
- **FR-039**: El sistema DEBE registrar en un historial consultable toda operación contra Mercado Libre, con fecha y hora, tipo de operación, resultado, código de respuesta, duración y detalle del error cuando corresponda.
- **FR-040**: El sistema DEBE presentar ese historial en un listado paginado y filtrable por fecha y por resultado.
- **FR-041**: El sistema DEBE aplicar una política de retención al historial, descartando los registros más antiguos para que no crezca de forma indefinida.

### Key Entities

- **Función avanzada**: cada una de las funciones activables del sistema. Atributos: clave identificatoria, nombre visible, descripción breve, orden de presentación, disponibilidad (construida o no), estado activo/inactivo, usuario y fecha de la última modificación de estado.
- **Configuración de integración Mercado Libre**: registro único que guarda los datos de la aplicación del DevCenter. Atributos: identificador de aplicación, clave secreta (cifrada), sitio de operación, modo sólo lectura, fecha de última modificación.
- **Cuenta de Mercado Libre vinculada**: la cuenta autorizada. Atributos: identificador de usuario en Mercado Libre, apodo, correo, tipo de cuenta, sitio, credenciales de acceso y renovación (cifradas), vencimiento del acceso, fecha de vinculación, fecha del último renovado exitoso, estado de la conexión.
- **Solicitud de vinculación pendiente**: representa un intento de autorización en curso. Atributos: identificador de sesión único, fecha de emisión, vencimiento, estado (pendiente/consumida/vencida), usuario que la inició. Es de un solo uso.
- **Registro de operación**: entrada del historial de interacciones con Mercado Libre. Atributos: fecha y hora, tipo de operación, sentido (lectura/escritura), resultado, código de respuesta, duración, detalle del error, indicador de bloqueo por modo sólo lectura. Nunca contiene datos sensibles.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Un responsable del negocio, partiendo de una aplicación ya creada en el DevCenter de Mercado Libre, completa la vinculación de la cuenta en menos de 3 minutos y sin asistencia técnica.
- **SC-002**: Tras la vinculación, la pantalla muestra los datos reales de la cuenta de Mercado Libre (apodo, identificador, correo, tipo de cuenta), verificables contra lo que muestra Mercado Libre.
- **SC-003**: La conexión sobrevive a renovaciones sucesivas del acceso sin intervención: verificable de forma automatizada con al menos 3 renovaciones consecutivas manteniendo la cadena intacta, y de forma operativa comprobando que permanece activa durante 7 días corridos sin ninguna acción manual de reconexión.
- **SC-004**: Ante 10 intentos simultáneos de operar con el acceso vencido, se produce exactamente una renovación y ninguna operación falla por credencial inválida.
- **SC-005**: Con el modo sólo lectura activo, el 100% de las operaciones de escritura queda bloqueado y registrado, y ninguna alcanza a Mercado Libre.
- **SC-006**: Ante cualquiera de los escenarios de error contemplados (autorización cancelada, retorno inválido, sitio incorrecto, conexión caída), el usuario recibe un mensaje que le indica qué pasó y qué hacer, sin ver errores técnicos crudos.
- **SC-007**: Ninguna credencial ni clave secreta es recuperable desde la interfaz ni aparece en el historial de operaciones, verificable por inspección.
- **SC-008**: La pantalla de Funciones Avanzadas presenta las diez funciones en el orden relevado de Contagram, y su estructura coincide con la captura de referencia.
- **SC-009**: Todas las operaciones de la pantalla (activar función, guardar configuración, probar, desconectar) se completan sin ninguna recarga de página.
- **SC-010**: El módulo opera de forma equivalente en hosting compartido y en servidor dedicado, sin cambios en el código, verificable ejecutando el mismo conjunto de pruebas en ambos entornos.

## Assumptions

Decisiones tomadas por defecto ante aspectos no especificados, documentadas para revisión:

- **Una sola cuenta de Mercado Libre vinculada**: el CRM es single-tenant (un único negocio, principio V de la constitución), por lo que se asume una única cuenta vinculada a la vez. El modelo de datos se diseña sin impedir una futura extensión a múltiples cuentas, pero la interfaz y las reglas de esta spec asumen una.
- **Las diez tarjetas se listan aunque no estén construidas**: por el principio rector de fidelidad estructural a Contagram, la pantalla muestra las diez funciones relevadas; las no construidas aparecen deshabilitadas e identificadas como no disponibles, en lugar de omitirse. Esto preserva la estructura de la pantalla original y evita que la lista cambie de forma cada vez que se construye un módulo.
- **Las credenciales de la aplicación se cargan por interfaz, no por variables de entorno**: así lo pidió el usuario explícitamente ("que me pida todos los datos necesarios"), lo que además permite reconfigurar sin acceso al servidor. Se compensa el riesgo cifrando la clave secreta y no devolviéndola nunca a la interfaz.
- **Permiso reutilizado**: se reutiliza el permiso de configuración de funciones ya existente en el CRM (`configuracion.funciones`, spec 005) en lugar de crear uno nuevo, por ser exactamente el alcance que corresponde.
- **Sitio de operación**: se asume Argentina (MLA) como valor por defecto, siendo el negocio argentino, pero el campo es configurable.
- **La desactivación de la función Mercado Libre no borra la vinculación**: desactivar la función suspende las operaciones pero conserva la cuenta vinculada; sólo la acción explícita de desconectar elimina las credenciales.
- **Modo sólo lectura desactivado por defecto**: para no sorprender con escrituras bloqueadas silenciosamente. La pantalla explica para qué sirve.
- **Retención del historial**: se asume una retención acotada por antigüedad y volumen, suficiente para diagnóstico sin crecimiento indefinido; el valor concreto se define en el plan.
- **Verificación de conexión**: se asume que "Probar conexión" consulta los datos de la propia cuenta, por ser la operación de lectura más liviana y representativa disponible.

## Dependencies

- **Externa**: aplicación creada por el negocio en el DevCenter de Mercado Libre, con la dirección de retorno del CRM registrada. Requiere una cuenta de Mercado Libre con los datos del titular validados (requisito de Mercado Libre en Argentina para crear aplicaciones).
- **Externa**: el CRM debe estar publicado en una dirección accesible desde internet mediante conexión segura; Mercado Libre no admite direcciones de retorno locales ni sin cifrar.
- **Interna**: módulo de Configuración & Ajustes y sistema de permisos existente (usuarios y roles; depósitos, spec 005).
- **Interna**: mecanismo de cifrado de la aplicación para resguardar credenciales.

## Restricciones de diseño y entorno

Restricciones que condicionan la implementación y que toda tarea derivada debe respetar:

- **Portabilidad de entorno**: el módulo debe comportarse igual en hosting compartido (sin procesos permanentes, tareas diferidas disparadas por tarea programada) y en servidor dedicado (procesos permanentes, almacenamiento en memoria). El código debe ser el mismo; sólo cambia la configuración del entorno. No se puede asumir la existencia de procesos de larga duración ni de almacenamiento en memoria para los mecanismos de exclusión mutua.
- **Especificaciones de diseño obligatorias del proyecto** (`CLAUDE.md`): listados mediante tablas con carga por demanda desde el servidor; altas, ediciones y bajas mediante ventanas modales sin recarga de página; notificaciones mediante el sistema de avisos emergentes del template; selectores de datos dinámicos con buscador.
- **Idioma del dominio**: nombres de tablas, columnas, rutas y textos de interfaz en español (principio V de la constitución).
- **Secretos**: ninguna credencial se versiona ni se registra en logs.

## Impacto en la documentación de dominio

Esta spec introduce contenido nuevo que, conforme al principio I de la constitución, debe reflejarse
en la documentación de dominio **antes de pasar a la implementación**:

1. `docs/documentacion_principal_crm.md`: agregar la pantalla "Funciones Avanzadas" al módulo
   Configuración & Ajustes, y una sección de integración con Mercado Libre que **documente
   explícitamente la divergencia deliberada respecto de Contagram** y su justificación.
2. `docs/modelo_datos.md`: agregar las entidades nuevas (funciones avanzadas, configuración de la
   integración, cuenta vinculada, solicitudes de vinculación, registro de operaciones).
