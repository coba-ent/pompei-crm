# Feature Specification: Sincronización forzada y eliminación masiva de Vinculaciones

**Feature Branch**: `035-sincronizacion-forzada-vinculaciones`

**Created**: 2026-08-03

**Status**: Draft

**Input**: User description: "Sincronización forzada de stock y precio en Vinculaciones (Tiendanube y Mercado Libre): un botón "Sincronización forzada" en cada pantalla de vinculaciones que recorre TODOS los vínculos activos de esa integración (no sólo los marcados pendientes), y para cada uno actualiza precio y stock reales hacia la plataforma externa, tomando el stock del depósito efectivo configurado para esa integración y el precio de la lista de precios por defecto configurada (si la integración tiene ese campo). Motivación: hoy la sincronización sólo se dispara por movimientos de stock (MovimientoStockObserver marca stock_pendiente); si el stock/precio de un producto se cargó por una vía que no pasa por MovimientoStock (ej. importación masiva de catálogo real), ese vínculo nunca queda marcado como pendiente y ni el cron ni el botón "Sincronizar ahora" (que sólo procesa pendientes) lo tocan nunca. Este botón es el mecanismo para la sincronización inicial completa al cargar el catálogo real, y para resincronizar todo puntualmente ante sospecha de desvío, sin depender de que haya habido un movimiento de stock. Además, agregar un botón para eliminar todas las vinculaciones en cada vista (Tiendanube y Mercado Libre). Contexto operativo: se está trabajando contra el catálogo real de un cliente conectado, así que no se van a hacer pruebas automatizadas contra la integración real — las pruebas de esta feature las hace el usuario a mano."

## Clarifications

### Session 2026-08-03

- Q: Si la pantalla de Vinculaciones tiene filtros aplicados (estado, búsqueda, etc.), ¿"Eliminar todas las vinculaciones" borra literalmente todas las de esa integración o sólo las filtradas/visibles? → A: Elimina TODAS las vinculaciones de esa integración, ignorando cualquier filtro aplicado en la tabla.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Sincronización inicial completa al cargar el catálogo real (Priority: P1)

Como usuario responsable de la integración, después de importar el catálogo real de productos con
stock actualizado (vía importación masiva, que no genera movimientos de stock individuales), quiero
un botón que recorra todos los vínculos ya establecidos con Tiendanube o Mercado Libre y empuje el
stock y precio reales de cada uno, para que el catálogo publicado en la plataforma externa quede
sincronizado desde el arranque sin depender de que haya habido ventas o ajustes previos.

**Why this priority**: Sin este mecanismo, el catálogo recién importado queda permanentemente
desincronizado con las plataformas externas — es el caso que ya ocurrió en producción (84 vínculos de
Tiendanube nunca marcados como pendientes) y bloquea el uso real de ambas integraciones.

**Independent Test**: Con vínculos existentes cuyo stock/precio en el CRM difiere del publicado en la
plataforma externa y sin haber generado movimientos de stock, se acciona el botón y se verifica que el
valor publicado en la plataforma externa (o el mock/registro de la llamada saliente) queda igual al
valor actual del CRM para cada vínculo.

**Acceptance Scenarios**:

1. **Given** una pantalla de Vinculaciones con vínculos cuyo `stock_pendiente`/`precio_pendiente` es
   `false` pero el stock/precio del producto en el CRM cambió por una vía que no generó movimiento,
   **When** el usuario hace clic en "Sincronización forzada", **Then** el sistema recorre todos los
   vínculos de esa integración (no sólo los pendientes) y envía a la plataforma externa el stock del
   depósito efectivo y el precio de la lista de precios por defecto configurados.
2. **Given** una sincronización forzada en curso, **When** termina de procesar todos los vínculos,
   **Then** se muestra una notificación tipo toast con el resumen (cantidad de vínculos actualizados y
   cantidad con error), igual que las acciones "Sincronizar ahora" existentes.

---

### User Story 2 - Bloqueo por modo sólo lectura o integración desactivada (Priority: P1)

Como usuario, si intento forzar una sincronización mientras el modo sólo lectura está activo (o la
integración está desactivada, o no hay conexión establecida), quiero que el sistema me lo impida de
forma clara con un mensaje explicando el motivo, para no asumir erróneamente que la sincronización
corrió cuando en realidad estaba bloqueada.

**Why this priority**: Es la causa raíz del malentendido que motivó esta feature (se creyó que el
botón de sincronización estaba en modo escritura cuando en realidad el modo sólo lectura lo bloqueaba
silenciosamente) — sin este corte explícito con feedback, el usuario no tiene forma de saber por qué
"no pasó nada".

**Independent Test**: Con el modo sólo lectura activo, se acciona "Sincronización forzada" y se
verifica que no se emite ningún request de escritura hacia la plataforma externa y que se muestra un
toast de error con el mensaje del corte correspondiente.

**Acceptance Scenarios**:

1. **Given** el modo sólo lectura está activo para la integración, **When** el usuario hace clic en
   "Sincronización forzada", **Then** el sistema no envía ningún request de escritura hacia la
   plataforma externa y muestra un toast de error: "Bloqueada por el modo sólo lectura: las escrituras
   hacia [Tiendanube/Mercado Libre] están deshabilitadas."
2. **Given** la función avanzada de la integración está desactivada, **When** el usuario hace clic en
   "Sincronización forzada", **Then** el sistema corta antes de procesar cualquier vínculo y muestra el
   toast de error correspondiente ("La función '[Tiendanube/Mercado Libre]' está desactivada en
   Funciones Avanzadas.").
3. **Given** no hay conexión establecida (o la conexión está caída) con la integración, **When** el
   usuario hace clic en "Sincronización forzada", **Then** el sistema corta antes de procesar cualquier
   vínculo y muestra el toast de error correspondiente.

---

### User Story 3 - Resincronización puntual ante sospecha de desvío (Priority: P2)

Como usuario, en cualquier momento (no sólo en la carga inicial) quiero poder forzar una
resincronización completa de todos los vínculos activos si sospecho que el stock o precio publicado
en la plataforma externa se desvió del CRM, sin tener que esperar un movimiento de stock nuevo ni
depender del ciclo del cron.

**Why this priority**: Cubre el caso de uso recurrente de "auditoría/corrección puntual" además de la
carga inicial — mismo mecanismo, distinto momento de uso. Prioridad P2 porque la carga inicial (User
Story 1) ya ejercita el mismo camino técnico; esta historia es sobre todo un caso de uso adicional del
mismo botón, no una implementación distinta.

**Independent Test**: Con vínculos ya sincronizados previamente (sin cambios pendientes) se acciona el
botón y se verifica que igual se reenvía el stock/precio actual de cada vínculo a la plataforma
externa.

**Acceptance Scenarios**:

1. **Given** vínculos sin ningún flag de pendiente activo, **When** el usuario hace clic en
   "Sincronización forzada", **Then** el sistema los procesa igual (no los salta por no estar
   pendientes).

---

### User Story 4 - Eliminar todas las vinculaciones de una integración (Priority: P2)

Como usuario, quiero un botón en cada pantalla de Vinculaciones (Tiendanube y Mercado Libre) que
elimine de una sola vez todos los vínculos existentes entre productos del CRM y sus equivalentes
publicados en esa plataforma externa, para poder empezar de cero la vinculación (por ejemplo si el
vínculo automático por SKU emparejó mal productos del catálogo real recién importado) sin tener que
desvincular uno por uno.

**Why this priority**: Es una herramienta de corrección masiva, complementaria a la sincronización
forzada — útil en la misma etapa de puesta a punto del catálogo real, pero no bloquea el uso normal de
la integración una vez que las vinculaciones están correctas, a diferencia de la sincronización forzada
(User Story 1) que sí es indispensable para la carga inicial.

**Independent Test**: Con vínculos existentes en una integración, se acciona el botón de eliminación
masiva, se confirma la acción, y se verifica que la tabla de vinculaciones de esa integración queda
vacía y que no se disparó ningún request hacia la plataforma externa.

**Acceptance Scenarios**:

1. **Given** una pantalla de Vinculaciones con vínculos existentes, **When** el usuario hace clic en
   "Eliminar todas las vinculaciones", **Then** el sistema pide confirmación explícita (acción
   destructiva e irreversible) antes de ejecutar nada.
2. **Given** el usuario confirmó la eliminación masiva, **When** se ejecuta la acción, **Then** el
   sistema elimina todos los registros de vínculo de esa integración (sólo el lado CRM de la relación)
   sin enviar ningún request de escritura hacia la plataforma externa — no despublica ni modifica nada
   en Tiendanube/Mercado Libre, sólo borra la relación en el CRM.
3. **Given** la eliminación masiva terminó, **When** el usuario vuelve a mirar la pantalla de
   Vinculaciones, **Then** la tabla aparece vacía y se muestra un toast de confirmación con la cantidad
   de vínculos eliminados.

---

### Edge Cases

- **Concurrencia**: si ya hay una sincronización (de cualquier tipo — cron, "Sincronizar ahora" o
  "Sincronización forzada") en curso para esa integración cuando se acciona el botón, el sistema debe
  rechazar la nueva ejecución y mostrar el toast "Ya hay una sincronización en curso" — sin encolar ni
  ejecutar en paralelo.
- **Vínculo con datos incompletos**: un vínculo sin el identificador externo necesario para armar el
  request (ej. falta `tn_product_id` en Tiendanube) se saltea sin llamar a la API externa, se registra
  el error correspondiente en el vínculo, y no interrumpe el procesamiento del resto.
- **Vínculo con error previo**: un vínculo que ya tenía un error de una sincronización anterior
  (`stock_error` o `precio_error` no nulos) se reintenta igual en la sincronización forzada — no se
  excluye por tener un error previo.
- **Falla parcial por vínculo**: si falla la actualización de precio de un vínculo, no se interrumpe el
  intento de actualizar el stock de ese mismo vínculo, ni se corta el procesamiento del resto de los
  vínculos — cada actualización (precio, stock) y cada vínculo son independientes entre sí.
- **Producto eliminado**: un vínculo cuyo producto asociado ya no existe se saltea sin llamar a la API,
  igual que ya hace el sincronizador de pendientes actual.
- **Integración sin campo de lista de precios por defecto**: si la integración no tiene un campo de
  lista de precios configurado (o no aplica esa noción), la sincronización forzada de esa integración
  sólo actualiza stock, no precio.
- **Catálogo grande**: con una cantidad alta de vínculos, la ejecución síncrona puede demorar varios
  segundos o minutos (un request de escritura por vínculo, sin loteo salvo que la plataforma externa lo
  soporte) — el usuario ve un estado de carga en el botón mientras dura.
- **Eliminación masiva sin vínculos existentes**: si se acciona "Eliminar todas las vinculaciones" sin
  que haya ningún vínculo, el sistema no pide confirmación con un conteo vacío ni falla — muestra que no
  hay nada para eliminar.
- **Eliminación masiva mientras hay una sincronización en curso**: si se acciona "Eliminar todas las
  vinculaciones" mientras el candado de sincronización de esa integración está tomado (cron,
  "Sincronizar ahora" o "Sincronización forzada" en curso), el sistema rechaza la eliminación con el
  mismo toast "Ya hay una sincronización en curso" en vez de borrar vínculos que un proceso concurrente
  está leyendo/actualizando.
- **Sincronización forzada mientras hay una eliminación masiva en curso**: mismo candado, en sentido
  inverso — si se acciona "Sincronización forzada" mientras una eliminación masiva está tomando el
  candado, se rechaza con el mismo toast "Ya hay una sincronización en curso".
- **Sincronización forzada sin vínculos existentes**: si se acciona "Sincronización forzada" sin que
  haya ningún vínculo para esa integración, el sistema no falla — muestra el toast de resumen con 0
  actualizados y 0 con error.
- **Usuario navega fuera o cierra la pestaña durante una sincronización forzada larga**: la ejecución en
  el servidor continúa hasta terminar (es un request HTTP normal, no depende de que el navegador siga
  esperando la respuesta); el usuario simplemente no ve el toast final, pero el estado de los vínculos
  queda actualizado igual. No se requiere un mecanismo de cancelación para esta versión.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: El sistema DEBE mostrar un botón "Sincronización forzada" en la pantalla de Vinculaciones
  de Tiendanube y en la pantalla de Vinculaciones de Mercado Libre, distinto y adicional a los botones
  existentes ("Sincronizar ahora", "Sincronizar stock ahora", "Sincronizar precios ahora").
- **FR-002**: Al accionar el botón, el sistema DEBE recorrer todos los vínculos activos de esa
  integración, sin filtrar por ningún flag de "pendiente" (a diferencia de las sincronizaciones
  existentes que sólo procesan vínculos marcados como pendientes).
- **FR-003**: Para cada vínculo procesado, el sistema DEBE calcular el stock a partir del depósito
  efectivo configurado para esa integración (mismo criterio que ya usan `SincronizadorStock` de cada
  integración) y enviarlo a la plataforma externa.
- **FR-004**: Para cada vínculo procesado, el sistema DEBE calcular el precio a partir de la lista de
  precios por defecto configurada para esa integración (si la integración tiene ese campo) y enviarlo
  a la plataforma externa.
- **FR-005**: La actualización de precio y la actualización de stock de un mismo vínculo DEBEN ser
  independientes entre sí: el error de una no debe impedir el intento de la otra.
- **FR-006**: Un error en el procesamiento de un vínculo (de precio, de stock, o de ambos) NO DEBE
  interrumpir el procesamiento del resto de los vínculos del barrido.
- **FR-007**: Antes de procesar cualquier vínculo, el sistema DEBE aplicar los mismos cortes previos
  que ya aplican los sincronizadores existentes: integración desactivada en Funciones Avanzadas, modo
  sólo lectura activo, o conexión no establecida/caída. Ante cualquiera de estos cortes, el sistema NO
  DEBE procesar ningún vínculo y DEBE mostrar un toast de error con el mensaje correspondiente al
  corte (mismo texto que ya usan los sincronizadores existentes para ese corte).
- **FR-008**: El sistema DEBE reutilizar el mismo mecanismo de candado (evitar corridas concurrentes)
  que ya usan las sincronizaciones existentes de esa integración: si hay cualquier sincronización en
  curso (cron, "Sincronizar ahora" o "Sincronización forzada") para esa integración, el sistema DEBE
  rechazar la nueva ejecución y mostrar el toast "Ya hay una sincronización en curso" sin ejecutar
  nada.
- **FR-009**: Un vínculo con datos incompletos que impiden armar el request hacia la plataforma externa
  (ej. falta el identificador externo del producto) DEBE saltearse sin llamar a la API, registrando el
  error correspondiente en el vínculo.
- **FR-010**: Un vínculo cuyo producto asociado en el CRM ya no existe DEBE saltearse sin llamar a la
  API externa.
- **FR-011**: Un vínculo con un error previo (de una sincronización anterior) DEBE reintentarse igual
  en la sincronización forzada, sin excluirse por tener un error previo.
- **FR-012**: Al finalizar el procesamiento de todos los vínculos, el sistema DEBE mostrar una
  notificación tipo toast con el resumen de la corrida: cantidad de vínculos actualizados y cantidad de
  vínculos con error.
- **FR-013**: Mientras dura la ejecución, el sistema DEBE mostrar un estado de carga visible en el
  botón (el usuario espera el resultado; no es una ejecución en background).
- **FR-014**: El sistema DEBE registrar en el historial de operaciones de la integración (mismo
  mecanismo de log ya usado por los sincronizadores existentes) cada actualización, error y corte de
  la sincronización forzada.
- **FR-015**: El sistema DEBE mostrar un botón "Eliminar todas las vinculaciones" en la pantalla de
  Vinculaciones de Tiendanube y en la pantalla de Vinculaciones de Mercado Libre.
- **FR-016**: Antes de ejecutar la eliminación masiva, el sistema DEBE pedir una confirmación explícita
  al usuario (acción destructiva e irreversible sobre datos del CRM).
- **FR-017**: La eliminación masiva DEBE borrar TODOS los registros de vínculo del lado CRM (la
  relación producto ↔ publicación/variante externa) de esa integración, sin importar filtros aplicados
  en la tabla de vinculaciones al momento de accionar el botón — NO DEBE enviar ningún request de
  escritura hacia la plataforma externa (no despublica productos, no modifica stock/precio externo).
- **FR-018**: La eliminación masiva DEBE respetar el mismo candado de concurrencia que las
  sincronizaciones (FR-008): si hay una sincronización en curso para esa integración, la eliminación se
  rechaza con el mismo toast "Ya hay una sincronización en curso".
- **FR-019**: Al finalizar la eliminación masiva, el sistema DEBE mostrar un toast de confirmación con
  la cantidad de vínculos eliminados, y la tabla de vinculaciones DEBE reflejar el estado vacío sin
  necesidad de recargar la página manualmente.
- **FR-020**: La eliminación masiva NO DEBE aplicar los cortes de "función desactivada en Funciones
  Avanzadas" ni "modo sólo lectura" (FR-007) — a diferencia de la sincronización forzada, no envía
  ningún request a la plataforma externa (FR-017), así que esos cortes no aplican. Sí DEBE aplicar el
  corte de conexión no establecida (no tiene sentido operar sobre vínculos de una integración sin
  configurar) y el candado de concurrencia (FR-018).
- **FR-021**: La eliminación masiva DEBE ejecutarse de forma atómica (una única transacción): si falla a
  mitad de camino, ningún vínculo debe quedar borrado — se conserva el estado previo completo y se
  informa el error al usuario.
- **FR-022**: El sistema DEBE registrar en el historial de operaciones de la integración un único
  registro por la eliminación masiva (cantidad eliminada, usuario, fecha) — mismo mecanismo de log ya
  usado por las demás acciones (FR-014), no un registro por vínculo eliminado.

### Key Entities

- **Vínculo (MercadoLibrePublicacionProducto / TiendanubeVarianteProducto)**: relación existente entre
  un producto del CRM y su equivalente publicado en la plataforma externa. Ya tiene los campos de
  estado de sincronización de stock y precio (pendiente, error, fecha de última sincronización) que la
  sincronización forzada actualiza igual que las sincronizaciones existentes — no se agregan campos
  nuevos.
- **Configuración de integración (MercadoLibreConfiguracion / TiendanubeConexionRest)**: contiene el
  depósito efectivo y (si aplica) la lista de precios por defecto que la sincronización forzada usa
  para calcular los valores a enviar — mismos campos que ya usan los sincronizadores existentes.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Después de una importación masiva de catálogo con vínculos ya establecidos, un único
  clic en "Sincronización forzada" deja el 100% de los vínculos activos de esa integración con su
  estado de sincronización actualizado (sin necesidad de generar movimientos de stock manuales para
  cada producto).
- **SC-002**: Con el modo sólo lectura activo, accionar "Sincronización forzada" resulta en cero
  llamadas de escritura hacia la plataforma externa, en el 100% de los casos.
- **SC-003**: Un error en un vínculo puntual durante la sincronización forzada no reduce la cantidad de
  vínculos procesados exitosamente en el resto del barrido (0% de interrupciones del barrido completo
  por errores puntuales).
- **SC-004**: El usuario puede identificar, sin consultar logs ni la base de datos, cuántos vínculos se
  actualizaron y cuántos fallaron, inmediatamente después de que termina la sincronización forzada (vía
  el toast de resumen).
- **SC-005**: Después de accionar "Eliminar todas las vinculaciones" y confirmar, el 100% de los
  vínculos de esa integración desaparecen de la tabla sin que el usuario tenga que recargar la página,
  y sin que se haya modificado nada del lado de la plataforma externa.

## Assumptions

- La sincronización forzada reutiliza el depósito efectivo y (si aplica) la lista de precios por
  defecto ya configurados para cada integración — no introduce una pantalla de configuración nueva ni
  permite elegir un depósito/lista distintos para esta acción puntual.
- No hay control de permisos/roles adicional al ya existente para las acciones de sincronización de
  cada integración: cualquier usuario que hoy puede usar "Sincronizar ahora" puede usar "Sincronización
  forzada".
- El volumen de vínculos por integración en este CRM (decenas a unos pocos cientos) hace aceptable una
  ejecución síncrona con espera visible en el botón; no se requiere un mecanismo de cola/background
  para esta versión.
- La sincronización forzada no agrega ni elimina vínculos — sólo actualiza stock/precio de los vínculos
  ya existentes y activos. Vínculos inexistentes (productos sin publicar/vincular) quedan fuera del
  alcance de esta acción.
- Este CRM está conectado en este momento contra la cuenta y el catálogo reales de un cliente
  (Tiendanube y Mercado Libre en producción, no cuentas de prueba/sandbox). Por eso esta feature no
  incluye pruebas automatizadas que ejecuten requests reales contra esas plataformas: la validación de
  ambas acciones (sincronización forzada y eliminación masiva) la hace el usuario manualmente en el
  entorno real después de implementada. El trabajo de implementación puede (y debe) tener tests
  automatizados que mockeen/fake-en el cliente HTTP de cada integración, pero ningún test debe apuntar
  a la API real de Tiendanube/Mercado Libre.
- La eliminación masiva es un borrado físico de los registros de vínculo (o el mecanismo de borrado ya
  usado por el resto del CRM para estas entidades) — no un soft-delete con posibilidad de deshacer;
  por eso requiere confirmación explícita antes de ejecutarse (FR-016).
