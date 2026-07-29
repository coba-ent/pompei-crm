# Feature Specification: Integración Tiendanube — Conexión de tienda (Aplicación personalizada)

**Feature Branch**: `015-tiendanube-conexion`

**Created**: 2026-07-29

**Status**: Draft

**Input**: User description: "Integración Tiendanube — Etapa 1: pantalla de configuración y conexión de la tienda (Aplicación personalizada). Dentro de la tarjeta 'Tiendanube' de Funciones Avanzadas, permitir cargar los datos de una Aplicación personalizada creada en el panel de administración de la propia tienda Tiendanube: identificador de la tienda, token de acceso generado a mano por el usuario en ese panel. A diferencia de Mercado Libre (spec 011), Tiendanube NO usa OAuth: no hay redirect a un sitio de autorización, no hay refresh token, no hay vencimiento de credencial. El sistema debe guardar esas credenciales cifradas, probar la conexión contra la API real, mostrar el estado de la conexión y los datos de la tienda vinculada, permitir desconectar, y un kill-switch de modo sólo lectura idéntico en comportamiento al ya construido para Mercado Libre, con historial de operaciones. Es la base de conexión sobre la que se apoyarán las etapas siguientes (ventas, stock)."

## Contexto y fuentes

Esta spec abre el módulo de **integración con Tiendanube**, la tercera de las diez funciones
relevadas en `docs/informe_contagram_funciones_avanzadas.md` §1 (tarjeta "Tiendanube", hoy no
construida). Es, junto con Mercado Libre, la segunda parte del CRM que **diverge deliberadamente**
de la estructura real de Contagram, por el mismo motivo documentado en `specs/011-mercadolibre-conexion-oauth/spec.md`.

**Lo que releva Contagram** (`docs/informe_contagram_funciones_avanzadas.md` §4, captura `[104]`):
al activar la tarjeta "Tiendanube" se ve un indicador parcial de **4 pasos** — Solicitar Acceso →
Acceso Permitido → Importar → Sincronizar — sugiriendo que Contagram usa una aplicación propia de
Tiendanube (partner/pública) con un flujo de autorización, seguido de una importación de catálogo.
El relevamiento no pudo completarse (requería upgrade de cuenta), por lo que el detalle exacto de
esos 4 pasos no está verificado con capturas reales, a diferencia de lo que sí se logró luego para
Mercado Libre (spec 012, corregida contra el centro de ayuda oficial). No se encontraron artículos
públicos del centro de ayuda de Contagram sobre Tiendanube al momento de escribir esta spec.

**Divergencia respecto de Contagram (explícita y decidida por el usuario)**: en lugar de replicar
esos 4 pasos con una aplicación pública y flujo de autorización, este CRM usa el modelo de
**Aplicación personalizada** que ofrece Tiendanube: una aplicación creada y usada por un único
comercio (exactamente el caso de este CRM, single-tenant), que entrega un **token de acceso directo**
generado a mano por el usuario desde el panel de administración de su propia tienda, sin pantalla de
autorización externa, sin token de renovación y sin vencimiento programado. Esta decisión es
deliberada (no una simplificación no autorizada): Tiendanube ofrece este camino específicamente para
el caso de una única tienda operando su propia integración, que es exactamente la naturaleza
single-tenant de este CRM (principio V de la constitución) — replicar el modelo OAuth completo de
Mercado Libre agregaría la complejidad de gestión de credenciales (state, callback, refresh,
concurrencia de renovación) sin ningún beneficio real, porque no existe un intercambio de
autorización entre dos partes distintas: el usuario ya es dueño de ambos sistemas.

Esta divergencia debe quedar registrada en `docs/documentacion_principal_crm.md` como excepción
explícita al principio rector de fidelidad estructural, igual que ya se hizo para Mercado Libre.

**Fuente de verdad estructural para la pantalla contenedora**: `docs/informe_contagram_funciones_avanzadas.md`
§1 (la pantalla "Funciones Avanzadas" en sí, ya construida en la spec 011, no se modifica). Lo que
esta spec agrega es el contenido de la tarjeta "Tiendanube" (hoy deshabilitada e identificada como no
disponible) y su pantalla de configuración, siguiendo el mismo patrón de pestaña/tarjeta ya
establecido para Mercado Libre.

**Fuente de dominio**: `docs/documentacion_principal_crm.md`, `docs/modelo_datos.md`.

**Estado actual del CRM**: la tarjeta "Tiendanube" existe en la pantalla Funciones Avanzadas pero
aparece deshabilitada e identificada como no disponible (spec 011, FR-004). No existe ninguna tabla,
ruta ni pantalla de configuración de Tiendanube todavía.

## Alcance

**Incluye**: habilitar la tarjeta "Tiendanube" de Funciones Avanzadas, la pantalla de configuración
de la integración (carga de credenciales de la Aplicación personalizada), la verificación de
conexión contra la API real de Tiendanube, el panel de estado con los datos de la tienda vinculada,
la acción de desconectar, el kill-switch de modo sólo lectura, y el registro de operaciones contra la
API con su propia política de retención.

**Excluye explícitamente** (van en specs posteriores, continuación directa de ésta igual que 012/013
continuaron a 011): el listado de órdenes de venta de Tiendanube, la vinculación de productos de
Tiendanube con productos del CRM, la conversión de órdenes en Venta del CRM, la sincronización de
stock del CRM hacia Tiendanube, webhooks de negocio, importación masiva de catálogo, envíos y
mensajería. Esta spec deja **la base de conexión y el cliente de API** sobre la que esas specs
siguientes se apoyarán, tal como hizo la 011 para Mercado Libre.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Activar la función Tiendanube y cargar la Aplicación personalizada (Priority: P1)

Como responsable del negocio, entro a Configuración & Ajustes → Funciones Avanzadas, activo la
tarjeta "Tiendanube" y accedo a su configuración. Ahí cargo el identificador de mi tienda y el token
de acceso que generé en el panel de administración de Tiendanube al crear mi Aplicación personalizada.

**Why this priority**: sin credenciales cargadas no hay ninguna otra funcionalidad posible. Es el
paso previo obligatorio de toda la spec.

**Independent Test**: se puede probar activando la tarjeta, cargando un identificador de tienda y un
token, y verificando que se guardan cifrados y que el token nunca vuelve a mostrarse en claro.

**Acceptance Scenarios**:

1. **Given** la función Tiendanube recién activada y sin configurar, **When** el usuario entra a su
   configuración, **Then** ve el formulario de credenciales y la conexión figura como "No configurada".
2. **Given** el formulario de credenciales, **When** el usuario carga el identificador de tienda y el
   token y guarda, **Then** el sistema confirma por notificación y habilita la acción "Probar conexión".
3. **Given** credenciales ya guardadas, **When** el usuario vuelve a la pantalla, **Then** ve el
   identificador de tienda pero **nunca** el token en claro (sólo un indicador de que está cargado y
   la opción de reemplazarlo).
4. **Given** el formulario, **When** el usuario deja vacío un campo obligatorio, **Then** el sistema
   muestra el error asociado sin recargar la página ni perder lo ya escrito.
5. **Given** un usuario sin permiso de configuración de funciones, **When** intenta acceder a la
   pantalla, **Then** el sistema le deniega el acceso, reutilizando el mismo permiso `configuracion.funciones`
   ya usado por Mercado Libre.

---

### User Story 2 - Verificar la conexión y ver los datos de la tienda vinculada (Priority: P1)

Como responsable del negocio, con las credenciales ya cargadas, presiono "Probar conexión" y el
sistema confirma contra la API real de Tiendanube que el token es válido, mostrándome los datos de mi
tienda (nombre, dominio, país, moneda) y dejando la conexión en estado "Conectada".

**Why this priority**: es el objetivo central de la spec — confirmar que el CRM efectivamente puede
hablar con la cuenta real de Tiendanube del negocio, sin necesidad de ningún paso de autorización
externo.

**Independent Test**: se puede probar de punta a punta con una tienda de prueba: cargar sus
credenciales, presionar "Probar conexión" y verificar que el panel muestra los datos reales de esa
tienda.

**Acceptance Scenarios**:

1. **Given** credenciales cargadas y sin conexión verificada aún, **When** el usuario presiona
   "Probar conexión", **Then** el sistema consulta los datos de la tienda contra la API de Tiendanube
   y, si responde con éxito, marca la conexión como "Conectada" y muestra nombre, dominio, país y
   moneda de la tienda.
2. **Given** un token inválido o revocado, **When** el usuario presiona "Probar conexión", **Then** el
   sistema informa por notificación que la credencial fue rechazada, sin dejar la conexión como
   "Conectada".
3. **Given** un identificador de tienda que no corresponde al token cargado, **When** se prueba la
   conexión, **Then** el sistema rechaza la verificación e informa el motivo con claridad.
4. **Given** una conexión ya establecida, **When** el usuario mira el panel de estado, **Then** ve
   nombre de la tienda, dominio, país, moneda, fecha en que se guardaron las credenciales y fecha de
   la última verificación exitosa.
5. **Given** una conexión activa, **When** el usuario presiona "Desconectar" y confirma, **Then** el
   sistema elimina el token almacenado, deja la conexión como "Desconectada" y lo registra en el
   historial, conservando los datos de la tienda para trazabilidad.

---

### User Story 3 - Operar con seguridad: modo sólo lectura y diagnóstico (Priority: P2)

Como responsable técnico, activo el modo "sólo lectura" para vincular la tienda real del negocio sin
riesgo de modificar catálogo o publicaciones reales durante el desarrollo, y consulto el historial de
operaciones contra Tiendanube para diagnosticar problemas.

**Why this priority**: no es imprescindible para que la conexión funcione, pero es la misma
salvaguarda ya construida para Mercado Libre (spec 011, historia 4), y el historial es lo que hace
diagnosticable la integración desde el día uno.

**Independent Test**: se puede probar activando el interruptor y verificando que una operación de
escritura contra Tiendanube queda registrada como bloqueada en vez de ejecutarse.

**Acceptance Scenarios**:

1. **Given** el modo sólo lectura activo, **When** cualquier parte del sistema intenta una operación
   de escritura hacia Tiendanube, **Then** la operación no se envía, se registra en el historial como
   bloqueada y quien la invocó recibe una respuesta que lo indica.
2. **Given** el modo sólo lectura activo, **When** el sistema realiza una operación de lectura (por
   ejemplo, "Probar conexión"), **Then** se ejecuta normalmente.
3. **Given** el modo sólo lectura activo, **When** el usuario mira la pantalla de configuración,
   **Then** ve un aviso visible y permanente de que las escrituras están bloqueadas.
4. **Given** operaciones ya realizadas, **When** el usuario consulta el historial, **Then** ve un
   listado paginado y filtrable con fecha, operación, resultado, código de respuesta y detalle del
   error si lo hubo — mismo formato que el historial ya existente de Mercado Libre, pero como
   registros propios de Tiendanube.
5. **Given** el historial, **When** se registra cualquier operación, **Then** ningún dato sensible
   (el token) queda visible en el registro.

---

### User Story 4 - Enterarse cuando la credencial deja de ser válida (Priority: P2)

Como responsable del negocio, si revoco o regenero el token desde el panel de Tiendanube, o
desinstalo la Aplicación personalizada, quiero que el CRM me avise claramente que la conexión se cayó
en vez de fallar en silencio o reintentar en vano.

**Why this priority**: sin este aviso, el CRM seguiría creyendo que está conectado mientras todas las
operaciones fallan calladamente. Es la contraparte, mucho más simple, de la renovación automática que
sí necesita Mercado Libre (spec 011, historia 5) — acá no hay nada que renovar, sólo detectar el
rechazo y pedir recargar un token nuevo.

**Independent Test**: se puede probar invalidando el token guardado (por ejemplo regenerándolo desde
el panel de Tiendanube) y verificando que la siguiente operación marca la conexión como caída con la
acción de recargar credenciales visible.

**Acceptance Scenarios**:

1. **Given** una conexión previamente activa, **When** una operación contra Tiendanube es rechazada
   por credencial inválida o revocada, **Then** el sistema marca la conexión como "Caída" y muestra de
   forma destacada la acción de recargar el token.
2. **Given** una conexión caída, **When** el usuario carga un token nuevo y guarda, **Then** el
   sistema permite volver a probar la conexión sin necesidad de recrear el resto de la configuración.
3. **Given** una operación rechazada por exceso de solicitudes, **When** el sistema la detecta,
   **Then** espera un tiempo creciente antes de reintentar y no la descarta silenciosamente.
4. **Given** una falla temporal del servicio de Tiendanube, **When** el sistema la detecta, **Then**
   reintenta un número acotado de veces y, si persiste, registra el error sin marcar la conexión como
   caída.

---

### Edge Cases

- **El token se pega con espacios o caracteres invisibles alrededor**: el sistema debe normalizarlo
  antes de guardar o probar, para no rechazar un token válido por un error de copiado.
- **El identificador de tienda no corresponde al token cargado** (token de otra tienda): la
  verificación debe rechazarlo con un mensaje claro, en vez de guardar una conexión que nunca va a
  funcionar.
- **Se cambia el token con una conexión activa**: el token anterior deja de usarse inmediatamente; si
  el nuevo es inválido, la conexión pasa a "Caída" en lugar de mantener silenciosamente el token
  viejo.
- **Se desactiva la función Tiendanube desde Funciones Avanzadas con una conexión activa**: el sistema
  debe pedir confirmación explicando que se suspenderán las operaciones, y conservar (no borrar) la
  configuración salvo desconexión explícita — mismo comportamiento que Mercado Libre (spec 011,
  FR-005a).
- **El token almacenado no puede descifrarse** (cambio de la clave de aplicación del entorno): el
  sistema debe informarlo de forma comprensible en vez de fallar de manera opaca.
- **Operación de escritura mientras el modo sólo lectura está activo**: debe quedar claro para quien la
  invocó que no se ejecutó, para que no se interprete como éxito.
- **La tienda tiene más de un dominio o idioma configurado en Tiendanube**: el panel de estado muestra
  el dominio principal; los demás quedan fuera de alcance de esta spec.
- **Se desinstala la Aplicación personalizada desde el panel de Tiendanube sin avisar al CRM**: se
  detecta recién en la siguiente operación (no hay notificación push de Tiendanube en esta etapa), que
  falla y marca la conexión como caída.

## Requirements *(mandatory)*

### Functional Requirements — Configuración de la integración

- **FR-001**: El sistema DEBE permitir cargar y actualizar las credenciales de la Aplicación
  personalizada de Tiendanube: identificador de la tienda y token de acceso.
- **FR-002**: El sistema DEBE almacenar el token de acceso de forma cifrada y NUNCA devolverlo en
  claro a la interfaz, ni siquiera al usuario que lo cargó — mismo tratamiento que la clave secreta de
  Mercado Libre (spec 011, FR-010).
- **FR-003**: El sistema DEBE validar el formato de los datos de configuración (identificador de
  tienda numérico, token no vacío) y devolver los errores asociados a cada campo sin recargar la
  página.
- **FR-004**: El sistema DEBE impedir probar o dar por establecida una conexión mientras la
  configuración esté incompleta, nombrando explícitamente en el mensaje cuál es el dato que falta.
- **FR-005**: El sistema DEBE advertir al usuario, cuando reemplaza el token con una conexión activa,
  que la conexión existente quedará invalidada hasta volver a probarla.
- **FR-006**: El sistema DEBE restringir el acceso a esta configuración a usuarios con el permiso
  `configuracion.funciones` ya existente (spec 011), reutilizado por ser exactamente el alcance que
  corresponde.
- **FR-006a**: El sistema DEBE pedir confirmación al desactivar la función "Tiendanube" mientras exista
  una conexión configurada, explicando que se suspenderán las operaciones contra Tiendanube y que la
  configuración **se conserva** (no se borra el token salvo desconexión explícita) — mismo
  comportamiento que Mercado Libre (spec 011, FR-005a).
- **FR-006b**: El sistema DEBE rechazar toda operación contra Tiendanube mientras la función
  "Tiendanube" esté desactivada, registrándola en el historial del mismo modo que una operación
  bloqueada, sin que ello altere el estado de la conexión.

### Functional Requirements — Conexión y estado

- **FR-007**: El sistema DEBE ofrecer una acción "Probar conexión" que realice una verificación real
  contra la API de Tiendanube (consultando los datos de la tienda) e informe el resultado por
  notificación, actualizando el estado mostrado.
- **FR-008**: El sistema DEBE presentar un panel de estado que indique de forma inequívoca si la
  conexión está No configurada, Desconectada, Conectada o Caída (requiere recargar credenciales).
- **FR-009**: El sistema DEBE mostrar, con la conexión activa: nombre de la tienda, dominio principal,
  país, moneda, fecha en que se guardaron las credenciales vigentes y fecha de la última verificación
  exitosa.
- **FR-010**: El sistema DEBE ofrecer una acción "Desconectar", con confirmación previa, que elimine el
  token almacenado y deje la conexión como desconectada.
- **FR-011**: El sistema DEBE conservar los datos de la tienda y el historial tras una desconexión,
  salvo el token, para trazabilidad.
- **FR-012**: El sistema DEBE marcar la conexión como "Caída" cuando una operación sea rechazada por
  credencial inválida o revocada, mostrando de forma destacada la acción para recargar el token, y
  DEBE dejar de reintentar esa operación hasta que se cargue un token nuevo.
- **FR-013**: El sistema DEBE aplicar una espera creciente entre reintentos ante rechazos por exceso de
  solicitudes, sin descartar la operación silenciosamente.
- **FR-014**: El sistema DEBE reintentar un número acotado de veces ante fallas temporales del servicio
  y, si persisten, registrar el error sin marcar la conexión como caída.
- **FR-015**: El sistema NO DEBE registrar el token ni ningún dato sensible en ningún log o historial
  visible.

### Functional Requirements — Modo sólo lectura e historial

- **FR-016**: El sistema DEBE ofrecer un interruptor "Modo sólo lectura" que, estando activo, impida la
  ejecución de toda operación de escritura hacia Tiendanube.
- **FR-017**: El sistema DEBE registrar toda operación de escritura bloqueada por el modo sólo lectura,
  con el detalle de lo que se habría enviado, y devolver a quien la invocó una respuesta que lo indique
  inequívocamente.
- **FR-018**: El sistema DEBE permitir normalmente las operaciones de lectura mientras el modo sólo
  lectura esté activo.
- **FR-019**: El sistema DEBE mostrar un aviso visible y permanente en la pantalla de configuración
  mientras el modo sólo lectura esté activo.
- **FR-020**: El sistema DEBE registrar en un historial consultable toda operación contra Tiendanube,
  con fecha y hora, tipo de operación, resultado, código de respuesta, duración y detalle del error
  cuando corresponda.
- **FR-021**: El sistema DEBE presentar ese historial en un listado paginado y filtrable por fecha y
  por resultado, independiente del historial ya existente de Mercado Libre (son integraciones
  distintas, con su propia trazabilidad).
- **FR-022**: El sistema DEBE aplicar una política de retención al historial, descartando los
  registros más antiguos para que no crezca de forma indefinida, con el mismo criterio ya usado para
  el historial de Mercado Libre.

### Key Entities

- **Configuración de integración Tiendanube**: registro único que guarda los datos de la Aplicación
  personalizada. Atributos: identificador de la tienda, token de acceso (cifrado), modo sólo lectura,
  fecha de última modificación.
- **Tienda de Tiendanube vinculada**: los datos de la tienda obtenidos al verificar la conexión.
  Atributos: identificador de tienda, nombre, dominio principal, país, moneda, fecha de la última
  verificación exitosa, estado de la conexión.
- **Registro de operación Tiendanube**: entrada del historial de interacciones con Tiendanube.
  Atributos: fecha y hora, tipo de operación, sentido (lectura/escritura), resultado, código de
  respuesta, duración, detalle del error, indicador de bloqueo por modo sólo lectura o función
  desactivada. Nunca contiene datos sensibles.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Un responsable del negocio, partiendo de una Aplicación personalizada ya creada en el
  panel de Tiendanube, completa la conexión de la tienda en menos de 2 minutos y sin asistencia
  técnica — más rápido que el equivalente de Mercado Libre (SC-001 de la spec 011) por no requerir
  ningún paso de autorización externa.
- **SC-002**: Tras probar la conexión, la pantalla muestra los datos reales de la tienda (nombre,
  dominio, país, moneda), verificables contra lo que muestra el panel de Tiendanube.
- **SC-003**: Con el modo sólo lectura activo, el 100% de las operaciones de escritura queda bloqueado
  y registrado, y ninguna alcanza a Tiendanube.
- **SC-004**: Ante cualquiera de los escenarios de error contemplados (token inválido, tienda
  incorrecta, conexión caída), el usuario recibe un mensaje que le indica qué pasó y qué hacer, sin ver
  errores técnicos crudos.
- **SC-005**: Ningún token es recuperable desde la interfaz ni aparece en el historial de operaciones,
  verificable por inspección.
- **SC-006**: Todas las operaciones de la pantalla (activar función, guardar configuración, probar,
  desconectar) se completan sin ninguna recarga de página.
- **SC-007**: El módulo opera de forma equivalente en hosting compartido y en servidor dedicado, sin
  cambios en el código, verificable ejecutando el mismo conjunto de pruebas en ambos entornos.

## Assumptions

Decisiones tomadas por defecto ante aspectos no especificados, documentadas para revisión:

- **Una sola tienda de Tiendanube vinculada**: mismo supuesto single-tenant que Mercado Libre (spec
  011) — el CRM es de un único negocio, por lo que se asume una única tienda vinculada a la vez.
- **Modelo de Aplicación personalizada en vez de OAuth público**: decisión explícita del usuario
  (confirmada en esta sesión), documentada arriba en "Contexto y fuentes". El modelo de datos se
  diseña sin impedir una futura migración a OAuth si el negocio necesitara distribuir la integración a
  otros comercios, pero esta spec asume Aplicación personalizada.
- **El token no vence ni requiere renovación**: a diferencia de Mercado Libre, Tiendanube no expone un
  ciclo de vencimiento/refresh para el token de una Aplicación personalizada; el único evento a manejar
  es la revocación o regeneración manual desde el panel de Tiendanube, cubierta por el estado "Caída"
  (FR-012).
- **Las credenciales se cargan por interfaz, no por variables de entorno**: mismo criterio que Mercado
  Libre (spec 011), por permitir reconfigurar sin acceso al servidor y mantener consistencia entre
  ambas integraciones.
- **Permiso reutilizado**: se reutiliza `configuracion.funciones` (spec 005/011) en lugar de crear uno
  nuevo.
- **Modo sólo lectura desactivado por defecto**: mismo criterio que Mercado Libre, para no sorprender
  con escrituras bloqueadas silenciosamente.
- **Retención del historial**: se asume una retención acotada por antigüedad y volumen, con el mismo
  criterio ya definido para Mercado Libre; el valor concreto se define en el plan.
- **Verificación de conexión**: "Probar conexión" consulta los datos de la propia tienda, por ser la
  operación de lectura más liviana y representativa disponible — mismo criterio que Mercado Libre.
- **Historiales independientes**: el historial de operaciones de Tiendanube es una tabla propia,
  separada de `ml_operaciones_log` de Mercado Libre, porque son integraciones y credenciales
  distintas; no corresponde mezclarlas.

## Dependencies

- **Externa**: Aplicación personalizada creada por el negocio desde el panel de administración de su
  propia tienda Tiendanube (o desde el Partner Portal, según cómo Tiendanube la exponga al momento de
  implementar), con el token de acceso generado y los permisos de API necesarios habilitados.
- **Externa**: el CRM debe estar publicado en una dirección accesible desde internet mediante conexión
  segura, para que las llamadas salientes a la API de Tiendanube sean confiables (no se requiere
  dirección de retorno pública como en el OAuth de Mercado Libre, al no haber redirect).
- **Interna**: módulo de Configuración & Ajustes y pantalla "Funciones Avanzadas" ya construida (spec
  011), sobre la que se habilita la tarjeta "Tiendanube".
- **Interna**: mecanismo de cifrado de la aplicación ya usado para resguardar las credenciales de
  Mercado Libre, reutilizado aquí para el token de Tiendanube.

## Restricciones de diseño y entorno

Restricciones que condicionan la implementación y que toda tarea derivada debe respetar:

- **Portabilidad de entorno**: el módulo debe comportarse igual en hosting compartido y en servidor
  dedicado, mismo criterio ya vigente para Mercado Libre (specs 011/012/013).
- **Especificaciones de diseño obligatorias del proyecto** (`CLAUDE.md`): listados mediante tablas con
  carga por demanda desde el servidor; altas, ediciones y bajas mediante ventanas modales sin recarga
  de página; notificaciones mediante el sistema de avisos emergentes del template; selectores de datos
  dinámicos con buscador donde corresponda.
- **Idioma del dominio**: nombres de tablas, columnas, rutas y textos de interfaz en español
  (principio V de la constitución).
- **Secretos**: ninguna credencial se versiona ni se registra en logs.

## Impacto en la documentación de dominio

Esta spec introduce contenido nuevo que, conforme al principio I de la constitución, debe reflejarse
en la documentación de dominio **antes de pasar a la implementación**:

1. `docs/documentacion_principal_crm.md`: ampliar §5 con una sección de integración con Tiendanube que
   **documente explícitamente la divergencia deliberada respecto de Contagram** (Aplicación
   personalizada en vez del flujo de 4 pasos con partner app) y su justificación, análoga a la sección
   ya existente para Mercado Libre (§5.2).
2. `docs/modelo_datos.md`: agregar las entidades nuevas (configuración de la integración Tiendanube,
   tienda vinculada, registro de operaciones Tiendanube).
