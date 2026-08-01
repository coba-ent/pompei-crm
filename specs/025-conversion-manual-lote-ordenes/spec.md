# Feature Specification: Conversión manual en lote de órdenes a Venta (Tiendanube y MercadoLibre)

**Feature Branch**: `025-conversion-manual-lote-ordenes`

**Created**: 2026-07-31

**Status**: Draft

**Input**: User description: "Agregar un botón 'Transformar todas en Venta' en las vistas de listado de órdenes de Ingresos > Tiendanube y de Ingresos > MercadoLibre, para uso manual (independiente de que el modo automático de creación de ventas esté activo o no). El botón siempre está visible en el header de cada listado. Al hacer click, dispara un proceso síncrono que recorre TODAS las órdenes de esa conexión que estén en estado 'Lista para convertir', ignorando cualquier filtro aplicado en el DataTable, y convierte cada una a Venta con el usuario logueado como convertida_por. Al terminar, se muestra un modal con un resumen (total procesadas, convertidas OK, fallidas) y una tabla de detalle de las fallidas (número de orden, motivo, motivo_detalle). Las órdenes que no estaban en estado 'Lista' no se tocan y no cuentan como fallidas. Aplica idéntico patrón para Tiendanube y MercadoLibre. Debe respetar los mismos guardrails que ya tiene la conversión individual y la sincronización (función avanzada habilitada, modo_solo_lectura, lock por orden). Sin job en background ni polling: POST síncrono que devuelve JSON, consumido por JS sin recargar la página."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Convertir en lote cuando el modo automático está apagado (Priority: P1)

Un usuario administrativo desactivó (o nunca activó) la creación automática de ventas para Tiendanube o MercadoLibre. Con el correr de los días se acumularon decenas de órdenes en estado "Lista para convertir" que nadie procesó una por una. El usuario entra al listado de órdenes correspondiente, aprieta "Transformar todas en Venta" y, sin salir de la pantalla, ve un resumen de cuántas se convirtieron y cuántas no, con el motivo de cada una que falló.

**Why this priority**: Es el caso de uso que motiva la feature — recuperar el trabajo manual repetitivo de convertir órdenes una por una cuando el proceso automático estuvo apagado. Sin esto, la única alternativa hoy es entrar orden por orden.

**Independent Test**: Con varias órdenes en estado "Lista para convertir" y modo automático desactivado, apretar el botón y verificar que todas pasan a "Convertida" con su Venta asociada, y que el modal muestra el conteo correcto.

**Acceptance Scenarios**:

1. **Given** el listado de órdenes de Tiendanube tiene 5 órdenes en estado "Lista para convertir" y la creación automática está desactivada, **When** el usuario aprieta "Transformar todas en Venta", **Then** las 5 órdenes quedan convertidas (estado "Convertida", con Venta asociada y `convertida_por` = usuario logueado) y el modal muestra "5 procesadas, 5 convertidas, 0 fallidas".
2. **Given** el listado de órdenes de MercadoLibre tiene 3 órdenes en estado "Lista para convertir", **When** el usuario aprieta "Transformar todas en Venta", **Then** ocurre el mismo comportamiento que en Tiendanube, de forma independiente por conexión.

---

### User Story 2 - Ver el detalle de lo que falló y por qué (Priority: P1)

Dentro del lote que se intentó convertir, alguna orden no se puede convertir (por ejemplo, tiene un cliente ambiguo o le falta la cuenta de tesorería configurada). El usuario necesita saber, sin adivinar ni entrar orden por orden, cuáles fallaron y la razón puntual, para poder resolverlas.

**Why this priority**: Sin visibilidad del motivo de falla, el botón masivo es una caja negra que obliga a revisar el listado entero después igual — pierde el valor principal de la feature.

**Independent Test**: Con un lote que incluya al menos una orden que sabidamente no puede convertirse (ej. sin cuenta de tesorería), correr el batch y verificar que el modal lista esa orden con su motivo y detalle, sin que el resto del flujo se interrumpa.

**Acceptance Scenarios**:

1. **Given** un lote de 4 órdenes "Lista para convertir" donde 1 tiene un cliente ambiguo, **When** se ejecuta el batch, **Then** el modal muestra "4 procesadas, 3 convertidas, 1 fallida" y la tabla de detalle incluye el número de esa orden, el motivo ("Cliente ambiguo") y el texto explicativo persistido.
2. **Given** ninguna orden falla, **When** se ejecuta el batch, **Then** el modal muestra el resumen sin tabla de detalle (o con la tabla vacía/oculta).

---

### User Story 3 - Uso del botón como "forzar ya" con modo automático activo (Priority: P2)

Un usuario con la creación automática activa no quiere esperar a la próxima sincronización periódica para que una orden recién marcada como "Lista" se convierta — aprieta el mismo botón para forzar la conversión inmediata de todo lo pendiente.

**Why this priority**: Es un caso de uso secundario (comodidad), no el motivador original, pero el botón siempre visible lo habilita sin costo adicional de diseño.

**Independent Test**: Con creación automática activa y una orden recién pasada a "Lista para convertir" que todavía no corrió el sync automático, apretar el botón manualmente y verificar que se convierte igual, sin duplicar la Venta si el sync automático la agarra en simultáneo (ver Edge Cases).

**Acceptance Scenarios**:

1. **Given** la creación automática está activa, **When** el usuario aprieta "Transformar todas en Venta", **Then** el sistema igual procesa todas las órdenes "Lista para convertir" en ese momento y muestra el resultado, sin que la opción esté deshabilitada ni oculta por tener el modo automático prendido.

---

### Edge Cases

- **Sin órdenes para procesar**: si no hay ninguna orden en estado "Lista para convertir" al momento de apretar el botón, el modal debe mostrarlo claramente ("0 procesadas") en vez de dar una respuesta ambigua o de error.
- **Función avanzada deshabilitada o modo solo lectura activo**: el botón debe rechazar la operación con el mismo mensaje/guardrail que ya usan "Sincronizar ahora" y la conversión individual, sin procesar ninguna orden.
- **Carrera con la sincronización automática**: si una sincronización automática está convirtiendo una orden en el mismo instante que el batch manual llega a esa misma orden, sólo una de las dos debe convertirla — la otra debe registrar la falla como "ya tiene una Venta asociada" (o directamente omitirla del conteo de fallidas, tratándola como ya resuelta) sin generar una Venta duplicada ni un error visible al usuario.
- **Falla inesperada a mitad de lote** (ej. corte de conexión a la base de datos en la orden 8 de 20): las primeras 7 ya convertidas deben quedar persistidas igual (cada conversión es atómica por orden), y el resto del lote debe seguir procesándose — una falla puntual no debe abortar el batch completo.
- **Lote grande** (cientos de órdenes acumuladas): el usuario debe recibir el resultado dentro de un tiempo de espera razonable en la misma pantalla, sin que el botón quede en un estado ambiguo si tarda.
- **Doble click / doble disparo**: si el usuario aprieta el botón dos veces seguidas antes de que responda la primera vez, no debe iniciarse un segundo procesamiento en paralelo del mismo lote (se debe deshabilitar el botón mientras hay un procesamiento en curso).

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: El sistema DEBE mostrar un botón "Transformar todas en Venta" en el header del listado de órdenes de Tiendanube (Ingresos > Tiendanube) y en el header del listado de órdenes de MercadoLibre (Ingresos > MercadoLibre), siempre visible independientemente del estado del modo de creación automática.
- **FR-002**: Al activar el botón, el sistema DEBE identificar, en el momento de la ejecución, todas las órdenes de esa conexión que estén en estado "Lista para convertir", sin tener en cuenta ningún filtro que el usuario tenga aplicado en la tabla del listado.
- **FR-003**: El sistema DEBE intentar convertir cada una de esas órdenes a Venta, registrando al usuario logueado como quien realizó la conversión, con el mismo criterio de negocio (reglas de convertibilidad, cliente, cuenta de tesorería, moneda, etc.) que ya aplica la conversión manual individual existente.
- **FR-004**: Cada conversión dentro del lote DEBE ser independiente y atómica: la falla de una orden NO DEBE impedir ni revertir la conversión de las demás órdenes del mismo lote.
- **FR-005**: El sistema DEBE respetar, antes de procesar cualquier orden del lote, los mismos guardrails que ya aplican a la sincronización y a la conversión individual: función avanzada de la integración habilitada y modo de solo lectura desactivado. Si algún guardrail bloquea la operación, el sistema NO DEBE procesar ninguna orden y DEBE informar el motivo del bloqueo.
- **FR-006**: El sistema DEBE evitar que dos conversiones concurrentes (por ejemplo el batch manual y una sincronización automática en curso) generen dos Ventas para la misma orden.
- **FR-007**: Al finalizar el procesamiento del lote, el sistema DEBE mostrar un resumen con: cantidad total de órdenes procesadas, cantidad convertida exitosamente y cantidad fallida.
- **FR-008**: Cuando haya al menos una orden fallida, el sistema DEBE mostrar el detalle de cada una: identificador de la orden, motivo de la falla y su explicación asociada.
- **FR-009**: Las órdenes que no estaban en estado "Lista para convertir" al momento de ejecutar el batch NO DEBEN ser tocadas por la operación ni contarse como procesadas, convertidas o fallidas.
- **FR-010**: El resultado del procesamiento DEBE mostrarse sin recargar ni navegar fuera de la página del listado.
- **FR-011**: Mientras un procesamiento de lote esté en curso, el sistema DEBE impedir que se dispare un segundo procesamiento simultáneo del mismo lote (por ejemplo, deshabilitando el botón hasta obtener respuesta).
- **FR-012**: El comportamiento descrito (botón, ejecución, guardrails, resumen y detalle de fallos) DEBE ser equivalente para el listado de Tiendanube y para el listado de MercadoLibre, cada uno operando sobre sus propias órdenes.

### Key Entities

- **Orden (Tiendanube / MercadoLibre)**: registro de una orden importada de la integración correspondiente, con un estado de conversión (entre otros: "Lista para convertir", "Requiere atención", "Convertida", "Cancelada", "Pendiente de pago"), un motivo y detalle de motivo cuando no pudo convertirse, y una referencia a la Venta que generó cuando sí se convirtió. Esta feature no agrega atributos nuevos a la entidad: reutiliza el estado y los motivos ya existentes.
- **Resultado del lote**: agrupación transitoria (no persistida como entidad propia) del resultado de ejecutar el botón: total procesado, cantidad convertida, cantidad fallida y el detalle por orden fallida (identificador, motivo, explicación). Se genera y se muestra en el momento; no queda como un registro histórico consultable después de cerrar el modal.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Un usuario puede convertir todas las órdenes pendientes acumuladas de una conexión (Tiendanube o MercadoLibre) en una sola acción, sin tener que abrir cada orden individualmente.
- **SC-002**: Después de ejecutar la conversión en lote, el usuario puede identificar el 100% de las órdenes que no se convirtieron y el motivo puntual de cada una, sin necesidad de consultar otra pantalla.
- **SC-003**: Ninguna ejecución del botón genera una Venta duplicada para una misma orden, incluso si coincide con una sincronización automática en curso.
- **SC-004**: Ninguna orden que no estaba en condiciones de convertirse (estado distinto de "Lista para convertir") cambia de estado como efecto de apretar el botón.
- **SC-005**: El usuario obtiene el resultado completo del lote (resumen + detalle de fallos) en la misma pantalla, sin recargar la página, en el mismo request que dispara la acción.

## Assumptions

- El botón está disponible para los mismos roles/usuarios que ya pueden operar hoy la conversión individual y la sincronización manual de cada integración; no se introducen permisos nuevos.
- El volumen típico de órdenes acumuladas en estado "Lista para convertir" en este negocio es acotado (decenas, ocasionalmente unos pocos cientos) y admite ser procesado de forma síncrona dentro de un único request sin necesidad de una cola de procesamiento en background ni de una pantalla de progreso.
- El motivo y la explicación mostrados para cada orden fallida son los que el sistema ya calcula y persiste como parte de la evaluación de convertibilidad existente; esta feature no define nuevos motivos de falla.
- "Todas las órdenes de esa conexión" se limita a la conexión activa vigente (Tiendanube o MercadoLibre) del negocio, dado que el sistema es single-tenant y no hay múltiples conexiones simultáneas por integración.
- Cerrado el modal, el resultado del lote no necesita quedar guardado como historial consultable más adelante — cada ejecución es informativa para ese momento.
