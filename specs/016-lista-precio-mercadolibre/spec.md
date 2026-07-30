# Feature Specification: Gestión de precios de Mercado Libre desde una Lista de Precios del CRM

**Feature Branch**: `016-lista-precio-mercadolibre`

**Created**: 2026-07-29

**Status**: Draft (corrige el alcance original de esta misma spec — ver nota de revisión)

**Input**: User description: "La lista de precios por defecto de Mercado Libre debe ser para GESTIONAR
PRECIOS DE MERCADO LIBRE: cuando cambien uno o varios precios de productos de esa lista seleccionada,
tiene que sincronizar esos precios desde el CRM a Mercado Libre. No es necesario un cron porque sólo pasa
cuando hay un cambio de precios en esa lista default."

## Nota de revisión (2026-07-29)

Esta spec reemplaza por completo un borrador anterior que trataba la Lista de Precios de Mercado Libre
como un campo puramente informativo/etiqueta (análogo a Categoría de Venta, sin efecto sobre precios ni
sobre Mercado Libre). Esa versión quedó obsoleta antes de implementarse: el usuario redefinió el propósito
del campo. **Se elimina por completo** el escenario de "etiquetar la Venta convertida con esta Lista de
Precios" — el campo pasa a ser exclusivamente el mecanismo de gestión de precios hacia Mercado Libre
descripto abajo. No hay código de la versión anterior implementado (`ml_configuracion` no tiene todavía
`lista_precio_id`, no hay migraciones ni vistas tocadas), por lo que esta corrección no requiere revertir
nada, sólo reemplazar la spec antes de pasar a plan/tasks.

## Contexto y fuentes

`ml_configuracion` (specs 011/012/013) ya tiene dos campos de clasificación configurables en Configuración
→ Integraciones → Mercado Libre: `deposito_id` (depósito del que se descuenta/publica stock, con reserva
"depósito por defecto del CRM" si no se elige ninguno) y `categoria_venta_id` (categoría de las Ventas
convertidas). Esta spec agrega un tercer campo, `lista_precio_id`, con un rol distinto a los dos
anteriores: no clasifica nada — es la Lista de Precios que el negocio usa como **fuente de verdad de los
precios que Mercado Libre debe mostrar**. Cuando el precio de un producto cambia dentro de esa lista
específica, el cambio se empuja a la publicación de Mercado Libre vinculada a ese producto.

Esta spec es la contraparte de precio de la spec 013 (sincronización de **stock** CRM → Mercado Libre):
reutiliza la misma vinculación 1:1 producto↔publicación (`ml_publicacion_producto`, spec 012), el mismo
cliente de API con kill-switch y renovación de credenciales (`ClienteMercadoLibre`, spec 011), y el mismo
historial de operaciones (`ml_operaciones_log`, spec 011). La diferencia central respecto de la 013 es el
disparador: la sincronización de stock corre en una corrida programada (cadencia configurable) porque el
stock cambia con frecuencia y por muchas causas; la sincronización de precio se dispara **en el momento
del propio cambio de precio** (evento), porque los cambios de precio son manuales, deliberados y
esporádicos — no hace falta ninguna corrida programada ni cron para este flujo.

**Aclaración de alcance que se mantiene sin cambios**: en ningún flujo de Mercado Libre el precio de una
línea de Venta convertida desde una orden se calcula ni se deriva de `listas_precio`/`precios_producto`.
`ConversorOrdenAVenta::armarLineas()` desagrega el IVA directamente del importe total que pagó el
comprador en Mercado Libre — ese cálculo no se toca, y ninguna Venta convertida queda etiquetada con
Lista de Precios (a diferencia del resto de las Ventas del CRM, spec 008): ese comportamiento actual no
cambia.

**Fuentes de dominio**: `docs/documentacion_principal_crm.md` §3.2.bis y §5.2 (Mercado Libre), §3
(Ventas/Presupuestos — bloque Lista de Precios); `docs/modelo_datos.md` (tablas `ml_configuracion`,
`ml_publicacion_producto`, `listas_precio`, `precios_producto`); `specs/012-ventas-mercadolibre/` (origen
de `ml_publicacion_producto`); `specs/013-stock-mercadolibre/` (precedente directo de esta spec: mismo
patrón de sincronización CRM → Mercado Libre sobre un atributo de la publicación, con
pendiente/error/reintento manual, sólo que disparado por evento en vez de por cron).

## Alcance

**Incluye**:

- Un campo "Lista de Precios" configurable en Configuración → Integraciones → Mercado Libre, en la misma
  sección donde ya se configuran Depósito y Categoría de Venta, opcional.
- Cuando el precio de un producto **vinculado a una publicación de Mercado Libre**
  (`ml_publicacion_producto`) cambia **dentro de la Lista de Precios configurada**, el sistema sincroniza
  ese precio hacia la publicación correspondiente en Mercado Libre, en el momento del cambio, sin esperar
  ninguna corrida programada.
- Esto aplica sin importar el camino por el que se escribió el precio: el modal de edición de Producto
  (`ProductoController::sincronizarPrecios()`) y la importación masiva de precios (`ImportadorFilas.php`)
  disparan el mismo comportamiento.
- Una acción manual "Sincronizar precios ahora" en la pantalla de **Productos** (Base de Datos → Productos
  & Servicios — no en la pantalla de Mercado Libre, corrección de UX posterior a la implementación
  inicial: el lugar natural para reintentar precios es donde se editan, no la pantalla de órdenes), que
  reintenta los vínculos con precio pendiente o con error, y cubre el caso de un producto que se vinculó a
  una publicación **después** de que su precio ya hubiera cambiado (por lo tanto nunca disparó el evento).
- Al cambiar **cuál** es la Lista de Precios configurada, el sistema sincroniza de inmediato los precios
  vigentes de la nueva lista para todos los productos actualmente vinculados.
- Visibilidad del estado de sincronización de precio (sincronizado / pendiente / error) en la pantalla de
  vinculación de publicaciones ya existente (spec 012/013), análoga a la que ya existe para stock.

**Excluye explícitamente**:

- Cualquier cambio al cálculo del precio de las líneas de una Venta convertida desde una orden de
  Mercado Libre: sigue saliendo 100% del importe pagado en la orden.
- Etiquetar la Venta convertida con esta Lista de Precios (`venta.lista_precio_id`): comportamiento
  descartado en esta corrección (ver Nota de revisión) — las Ventas de Mercado Libre siguen sin Lista de
  Precios asignada, igual que hoy.
- Sincronización de precio de productos **sin vínculo vigente** con una publicación de Mercado Libre: sin
  vínculo no hay publicación a la que empujarle nada (mismo criterio que la spec 013 para stock).
- Sincronización de título, descripción, imágenes, stock o estado (pausar/activar) de la publicación:
  el stock ya lo cubre la spec 013; el resto no forma parte de esta spec.
- Publicaciones con variantes: siguen sin poder vincularse (spec 012, FR-027), por lo tanto no participan.
- Corrida programada / cron para precios: a diferencia de la sincronización de stock (spec 013), el
  disparador es exclusivamente el cambio de precio; no hay `frecuencia_sync_minutos` aplicable a este
  flujo.
- Un concepto nuevo de "Lista de Precios por defecto del CRM" a nivel global: si no se configura ninguna
  Lista de Precios en Mercado Libre, no hay ninguna sincronización de precio; el resto del sistema
  (Ventas/Presupuestos) sigue funcionando igual que hoy.

## Clarifications

### Session 2026-07-29

- Q: ¿El campo Lista de Precios de Mercado Libre sigue etiquetando también las Ventas convertidas de
  Mercado Libre (comportamiento del borrador original), además de ahora gestionar precios? → A: No. Se
  elimina el etiquetado de Ventas por completo; el campo pasa a ser exclusivamente para gestión/
  sincronización de precios hacia Mercado Libre.
- Q: Si el envío de un precio a Mercado Libre falla en el momento (API caída, rate limit, publicación
  pausada/cerrada), ¿qué debe pasar? → A: el vínculo queda marcado con el error y "pendiente de
  sincronizar precio" (mismo patrón que la spec 013 para stock), con un botón manual "Sincronizar precios
  ahora" para reintentar sin esperar otro cambio de precio.
- Q: ¿La importación masiva de precios (Excel/CSV), que escribe en `precios_producto` por un camino
  distinto al modal de Producto, debe disparar la sincronización igual que la edición manual? → A: Sí,
  mismo disparador, sin importar el camino de escritura — sólo afecta a productos que además estén
  vinculados a una publicación de Mercado Libre; el botón manual "Sincronizar precios ahora" es el
  respaldo tanto para reintentos de fallas automáticas como para vínculos creados después de un cambio de
  precio que no llegó a dispararse.
- Q: Al cambiar cuál es la Lista de Precios configurada en Mercado Libre, ¿hay que empujar de inmediato
  los precios vigentes de la nueva lista para todos los productos vinculados? → A: Sí, push inmediato al
  guardar el cambio de configuración, sin esperar a que cada producto tenga un cambio de precio futuro.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Configurar la Lista de Precios que gestiona Mercado Libre (Priority: P1)

Como responsable del negocio, quiero elegir, desde la configuración de Mercado Libre, qué Lista de
Precios del CRM es la que gestiona los precios de mis publicaciones en Mercado Libre, igual que ya elijo
el Depósito y la Categoría de Venta.

**Why this priority**: sin este campo no hay ninguna lista de referencia — es el prerrequisito de todo lo
demás.

**Independent Test**: se puede probar entrando a Configuración → Integraciones → Mercado Libre,
seleccionando una Lista de Precios activa en el nuevo campo, guardando, y verificando que la selección
persiste al recargar la pantalla.

**Acceptance Scenarios**:

1. **Given** la pantalla de configuración de Mercado Libre, **When** el usuario abre el selector de Lista
   de Precios, **Then** ve listadas las Listas de Precios activas del CRM.
2. **Given** una Lista de Precios seleccionada, **When** el usuario guarda la configuración, **Then** el
   sistema confirma el guardado por notificación, sin recargar la página, y la selección queda persistida.
3. **Given** ninguna Lista de Precios seleccionada (campo vacío), **When** el usuario guarda, **Then** el
   sistema lo permite sin error — el campo es opcional, y sin él no hay sincronización de precios.

---

### User Story 2 - Que un cambio de precio en esa lista se refleje solo en Mercado Libre (Priority: P1)

Como responsable del negocio, cuando cambio el precio de un producto vinculado a una publicación de
Mercado Libre, dentro de la Lista de Precios que configuré para gestionar Mercado Libre, quiero que ese
nuevo precio se actualice en Mercado Libre automáticamente, sin tener que entrar a Mercado Libre a
corregirlo a mano ni esperar ninguna corrida programada.

**Why this priority**: es el motivo de ser de esta spec — sin esto, el campo configurado en la historia 1
no tendría ningún efecto.

**Independent Test**: se puede probar configurando una Lista de Precios (historia 1), vinculando un
producto a una publicación de Mercado Libre (spec 012), cambiando el precio de ese producto en esa lista
desde el modal de edición de Producto, y verificando que la publicación en Mercado Libre queda con el
nuevo precio sin ninguna acción manual adicional.

**Acceptance Scenarios**:

1. **Given** una Lista de Precios configurada en Mercado Libre y un producto vinculado a una publicación,
   **When** se guarda un nuevo precio de ese producto dentro de esa lista (modal de Producto), **Then**
   el sistema envía de inmediato el nuevo precio a la publicación de Mercado Libre correspondiente.
2. **Given** el mismo escenario, **When** el precio que cambia es el de un producto **sin** vínculo con
   ninguna publicación, **Then** no se dispara ningún envío hacia Mercado Libre.
3. **Given** el mismo escenario, **When** el precio que cambia es el de una Lista de Precios **distinta**
   a la configurada para Mercado Libre, **Then** no se dispara ningún envío hacia Mercado Libre.
4. **Given** ninguna Lista de Precios configurada en Mercado Libre, **When** cambia cualquier precio de
   cualquier producto, **Then** no se dispara ningún envío hacia Mercado Libre (comportamiento igual al
   actual, sin esta spec).
5. **Given** un cambio de precio disparado por la importación masiva de precios (Excel/CSV) sobre un
   producto vinculado, dentro de la lista configurada, **When** se procesa la importación, **Then** se
   dispara el mismo envío hacia Mercado Libre que si el cambio se hubiera hecho a mano.

---

### User Story 3 - Sincronizar precios manualmente (Priority: P2)

Como responsable del negocio, quiero poder forzar el envío de los precios pendientes hacia Mercado Libre
en cualquier momento, desde la pantalla de Productos (donde ya cargo y edito los precios), sin esperar a
un nuevo cambio de precio, para poder resolver fallas puntuales o productos que se vincularon después de
que su precio ya había cambiado.

**Why this priority**: es la red de seguridad del flujo automático de la historia 2 — sin ella, un envío
que falló o un producto vinculado tarde quedaría desactualizado en Mercado Libre indefinidamente.

**Independent Test**: se puede probar provocando una falla de envío (o vinculando un producto después de
un cambio de precio), presionando "Sincronizar precios ahora", y verificando que el precio pendiente se
envía y el vínculo deja de estar marcado como pendiente/error.

**Acceptance Scenarios**:

1. **Given** vínculos con precio pendiente o con error, **When** el usuario presiona "Sincronizar precios
   ahora", **Then** el sistema los envía de inmediato e informa por notificación cuántos se actualizaron
   y cuántos quedaron con error, sin recargar la página.
2. **Given** el modo sólo lectura activo o la función Mercado Libre desactivada, **When** el usuario busca
   la acción, **Then** no está disponible, con el motivo visible.
3. **Given** una sincronización de precios ya en curso, **When** el usuario dispara otra, **Then** sólo
   una se ejecuta y la otra se descarta, sin enviar el mismo producto dos veces en simultáneo.
4. **Given** un producto que se vinculó a una publicación después de que su precio ya había cambiado en la
   lista configurada (por lo tanto nunca disparó el envío automático), **When** el usuario presiona
   "Sincronizar precios ahora", **Then** el precio vigente de ese producto en la lista configurada se
   envía a la publicación recién vinculada.

---

### User Story 4 - Enterarse cuando Mercado Libre rechaza una actualización de precio (Priority: P2)

Como responsable del negocio, si Mercado Libre rechaza la actualización de precio de una publicación
(porque está pausada, cerrada, o por cualquier otro motivo), quiero verlo señalado con el motivo, sin que
eso bloquee la sincronización del resto de mis productos vinculados.

**Why this priority**: sin visibilidad del rechazo, el CRM cree que el precio está sincronizado cuando en
realidad Mercado Libre sigue mostrando un precio desactualizado — el negocio podría vender a un precio que
ya no es el vigente.

**Independent Test**: se puede probar vinculando una publicación que luego se pausa en Mercado Libre,
cambiando el precio de su producto en la lista configurada, y verificando que queda señalada con el motivo
del rechazo mientras el resto de los vínculos se sincroniza con normalidad.

**Acceptance Scenarios**:

1. **Given** una publicación pausada o cerrada en Mercado Libre, **When** su producto vinculado tiene un
   cambio de precio en la lista configurada, **Then** el envío se rechaza, el vínculo queda señalado con
   el motivo concreto y "pendiente de sincronizar precio", y el resto de los vínculos afectados en la
   misma operación se sincroniza sin verse afectado.
2. **Given** un vínculo marcado con error de precio, **When** el usuario lo revisa en la pantalla de
   vinculaciones, **Then** ve el motivo del último rechazo y cuándo ocurrió.
3. **Given** un vínculo con error persistente, **When** vuelve a tener un cambio de precio y se dispara el
   envío (automático o manual), **Then** el sistema lo vuelve a intentar (el error no lo excluye
   permanentemente de futuros intentos).

---

### User Story 5 - Cambiar la Lista de Precios configurada actualiza Mercado Libre de una vez (Priority: P2)

Como responsable del negocio, si decido que Mercado Libre pase a gestionarse con otra Lista de Precios
distinta a la que tenía configurada, quiero que los precios de Mercado Libre se actualicen de inmediato
según la nueva lista, sin tener que ir producto por producto tocando algo para que se dispare el envío.

**Why this priority**: sin esto, cambiar de lista dejaría todas las publicaciones vinculadas con el precio
de la lista anterior hasta que cada producto tuviera, individualmente, un cambio de precio futuro — una
inconsistencia silenciosa que puede durar mucho tiempo.

**Independent Test**: se puede probar con varios productos vinculados y precios cargados en dos Listas de
Precios distintas, cambiando la Lista de Precios configurada en Mercado Libre de una a la otra, y
verificando que todas las publicaciones vinculadas reciben de inmediato el precio vigente en la nueva
lista.

**Acceptance Scenarios**:

1. **Given** productos vinculados con precio cargado en la nueva Lista de Precios, **When** el usuario
   guarda el cambio de configuración, **Then** el sistema sincroniza de inmediato el precio vigente de la
   nueva lista hacia la publicación de cada producto vinculado.
2. **Given** un producto vinculado que **no** tiene precio cargado en la nueva Lista de Precios, **When**
   se guarda el cambio de configuración, **Then** ese vínculo no se sincroniza (no hay precio que enviar)
   y queda señalado según corresponda, sin bloquear al resto.
3. **Given** el cambio de configuración de Lista de Precios, **When** se guarda con el modo sólo lectura
   activo o la función Mercado Libre desactivada, **Then** la configuración se guarda igual, pero el push
   inmediato de precios no se ejecuta — los vínculos quedan marcados como pendientes para el próximo
   intento válido (automático o manual), igual que cualquier otro corte de escritura.

---

### Edge Cases

- **Precio con más de un cambio antes de que el envío anterior termine**: el envío en curso es
  asincrónico respecto de la request del usuario sólo en el sentido de que puede tardar; cada cambio de
  precio marca el vínculo como pendiente y dispara un intento de envío con el valor vigente al momento de
  ese intento — no hace falta encolar valores intermedios, porque siempre se envía el precio actual del
  producto en la lista configurada, no un historial de cambios.
- **Precio negativo o cero**: la validación de `precios_producto.precio` (≥ 0) ya existe aguas arriba
  (spec 002); esta spec no agrega ni relaja validaciones de precio.
- **Vínculo eliminado con un envío pendiente**: el pendiente se descarta; no hay publicación vigente a la
  que empujarle nada.
- **Producto inactivado en el CRM pero vinculado**: sigue sincronizando su precio si cambia (fuera de
  alcance pausar o cerrar la publicación).
- **Lista de Precios configurada que se desactiva**: la configuración conserva la referencia; si el
  precio de un producto de esa lista sigue existiendo y cambia, la sincronización sigue funcionando (mismo
  criterio que la spec original usaba para `categoria_venta_id`: no se revalida "activa" en cada
  operación).
- **Lista de Precios configurada que se elimina**: si el borrado de Listas de Precios ya impide eliminar
  una en uso (fuera de esta spec, comportamiento existente del módulo de Listas de Precios), ese resguardo
  cubre también este uso; si no existiera, queda documentado como brecha preexistente ajena a esta spec.
- **Conexión con Mercado Libre caída o modo sólo lectura activo en el momento del cambio de precio**: el
  envío no se ejecuta; el vínculo queda marcado como pendiente para el próximo intento válido (automático
  ante un nuevo cambio, o manual vía "Sincronizar precios ahora"), sin perder el pendiente.
- **Mercado Libre rechaza por exceso de solicitudes o falla temporal de red**: reintento con espera
  creciente, reutilizando el mismo mecanismo ya existente en `ClienteMercadoLibre` (specs 011/012/013),
  sin descartar el pendiente.
- **Dos cambios de precio casi simultáneos sobre el mismo producto** (por ejemplo, guardado del modal y
  una importación superpuestos): el último envío exitoso es el que queda reflejado en Mercado Libre,
  consistente con el precio vigente en el CRM en ese momento; no se requiere orden estricto adicional más
  allá del que ya impone la base de datos sobre `precios_producto`.

## Requirements *(mandatory)*

### Functional Requirements — Configuración

- **FR-001**: El sistema DEBE permitir configurar, desde la pantalla de configuración de Mercado Libre,
  una Lista de Precios entre las activas del CRM, como la lista que gestiona los precios de las
  publicaciones vinculadas.
- **FR-002**: El campo Lista de Precios de la configuración de Mercado Libre DEBE ser opcional: sin
  ninguna seleccionada, el sistema NO DEBE disparar ninguna sincronización de precio.
- **FR-003**: El sistema DEBE rechazar el guardado de la configuración de Mercado Libre si el valor
  enviado para Lista de Precios no corresponde a ninguna Lista de Precios existente del CRM, informando el
  error sin guardar el resto de la configuración.

### Functional Requirements — Disparo por evento

- **FR-004**: El sistema DEBE detectar, en el momento en que se crea o modifica un precio en
  `precios_producto`, si esa fila pertenece a la Lista de Precios configurada para Mercado Libre y si el
  producto correspondiente tiene un vínculo vigente con una publicación de Mercado Libre
  (`ml_publicacion_producto`). Si ambas condiciones se cumplen, DEBE disparar el envío del nuevo precio a
  esa publicación en el momento del cambio, sin esperar ninguna corrida programada.
- **FR-005**: El sistema DEBE disparar este comportamiento sin importar el camino de escritura sobre
  `precios_producto` — tanto el guardado del modal de edición de Producto como la importación masiva de
  precios DEBEN producir el mismo resultado ante el mismo cambio de dato.
- **FR-006**: El sistema NO DEBE disparar ningún envío hacia Mercado Libre por un cambio de precio en una
  Lista de Precios distinta a la configurada, ni por el cambio de precio de un producto sin vínculo
  vigente con ninguna publicación.
- **FR-007**: El sistema DEBE, al guardarse un cambio de **cuál** es la Lista de Precios configurada,
  sincronizar de inmediato el precio vigente de la nueva lista hacia la publicación de cada producto
  actualmente vinculado que tenga un precio cargado en esa lista.

### Functional Requirements — Envío y manejo de errores

- **FR-008**: El sistema DEBE enviar, por cada disparo elegible (FR-004/FR-005/FR-007), el precio vigente
  del producto en la Lista de Precios configurada a la publicación de Mercado Libre vinculada.
- **FR-009**: El sistema DEBE aplicar el mismo mecanismo de espera creciente y reintento acotado ante
  rechazos por exceso de solicitudes o fallas temporales de red ya existente en `ClienteMercadoLibre`
  (specs 011/012/013), sin descartar el pendiente ni bloquear el envío de otros vínculos.
- **FR-010**: El sistema DEBE registrar, ante un rechazo no transitorio (publicación pausada, cerrada,
  inexistente u otro rechazo definitivo), el motivo concreto y el momento del rechazo, dejando el vínculo
  marcado como "pendiente de sincronizar precio" para reintentarlo, en lugar de descartarlo.
- **FR-011**: El sistema NO DEBE ejecutar ningún envío de precio (automático o manual) mientras la función
  "Mercado Libre" esté desactivada o el modo sólo lectura esté activo; DEBE registrar el intento bloqueado
  en el historial de operaciones existente, conservando el pendiente para el próximo intento válido.
- **FR-012**: El sistema NO DEBE ejecutar ningún envío de precio mientras la conexión con Mercado Libre
  esté caída o no configurada, conservando el pendiente para el próximo intento válido.
- **FR-013**: El sistema DEBE registrar cada envío de precio (exitoso, rechazado o bloqueado) en el
  historial de operaciones ya existente (`ml_operaciones_log`, spec 011), como operación de sentido
  "escritura", sin incluir datos sensibles.

### Functional Requirements — Acción manual

- **FR-014**: El sistema DEBE ofrecer una acción manual "Sincronizar precios ahora" en la pantalla de
  Productos (Base de Datos → Productos & Servicios) que envíe de inmediato el precio vigente (Lista de
  Precios configurada) de todos los vínculos con precio pendiente o con error, informando el resultado
  por notificación sin recargar la página.
- **FR-015**: El sistema DEBE garantizar que dos sincronizaciones de precio (automáticas o manuales) no se
  ejecuten simultáneamente sobre el mismo vínculo: si una operación de sincronización de precio está en
  curso, una segunda invocación se descarta en lugar de ejecutarse en paralelo. Este control es
  independiente del que ya existe para la sincronización de stock (spec 013) y de órdenes (spec 012): son
  operaciones distintas.
- **FR-016**: La acción manual NO DEBE estar disponible (con el motivo visible) mientras el modo sólo
  lectura esté activo o la función Mercado Libre esté desactivada.

### Functional Requirements — Visibilidad

- **FR-017**: El sistema DEBE mostrar, en la pantalla de "Vinculación de publicaciones" (spec 012, FR-024;
  extendida por spec 013 para stock), por cada vínculo, su estado de sincronización de precio:
  sincronizado, con cambios pendientes, o con error; la fecha del último envío exitoso; y, cuando el
  estado sea "con error", el motivo concreto del último rechazo y la fecha en que ocurrió.
- **FR-018**: El sistema DEBE ofrecer la acción "Sincronizar precios ahora" (FR-014) desde la pantalla de
  Productos — deliberadamente **distinta** de la pantalla de Mercado Libre donde viven "Sincronizar ahora"
  (órdenes, spec 012) y "Sincronizar stock ahora" (spec 013): a diferencia de esas dos, que son
  específicas del canal Mercado Libre, el reintento de precios es una acción que el usuario espera
  encontrar donde edita los precios, no donde gestiona órdenes (corrección de UX posterior a la
  implementación inicial, que sí la había puesto junto a las otras dos por replicar el patrón de la spec
  013 sin cuestionar si aplicaba).

### Functional Requirements — Exclusiones explícitas

- **FR-019**: El sistema NO DEBE usar la Lista de Precios configurada aquí (ni ninguna otra) para calcular
  o modificar el precio unitario, el subtotal ni el total de ninguna línea de Venta convertida desde una
  orden de Mercado Libre: esos valores siguen derivándose exclusivamente del importe pagado en la orden,
  exactamente igual que antes de esta spec.
- **FR-020**: El sistema NO DEBE asignar Lista de Precios a las Ventas creadas al convertir una orden de
  Mercado Libre: quedan sin Lista de Precios asignada, igual que el comportamiento actual, sin relación
  con esta spec.

### Key Entities

- **Configuración de Mercado Libre** (`ml_configuracion`, ya existente): se le agrega el atributo Lista de
  Precios (`lista_precio_id`, referencia opcional a una Lista de Precios del CRM) — a diferencia de
  Depósito y Categoría de Venta (que clasifican), este atributo dispara sincronización de precios hacia
  Mercado Libre. No es una entidad nueva.
- **Vinculación publicación ↔ producto** (`ml_publicacion_producto`, ya existente desde la spec 012,
  extendida por la 013 con estado de stock): se le agregan atributos de sincronización de precio —
  indicador de cambios pendientes, fecha del último envío exitoso, motivo del último error (si lo hubo) y
  fecha de ese error. Mismo patrón que los campos de stock agregados en la spec 013, para el atributo
  precio. No es una entidad nueva.
- **Envío de precio**: no es una entidad persistente propia; es la operación (registrada en el historial
  de operaciones ya existente) que transmite el precio vigente de un vínculo en un momento dado.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: El usuario puede configurar la Lista de Precios de Mercado Libre y confirmar que quedó
  guardada en menos de 30 segundos, sin recargar la página.
- **SC-002**: El 100% de los cambios de precio sobre un producto vinculado, dentro de la Lista de Precios
  configurada, dispara un intento de sincronización hacia Mercado Libre en el momento del cambio, sin
  esperar ninguna corrida programada.
- **SC-003**: El 100% de los cambios de precio sobre productos sin vínculo vigente, o en una Lista de
  Precios distinta a la configurada, no genera ningún envío hacia Mercado Libre.
- **SC-004**: El 100% de las Ventas creadas al convertir órdenes de Mercado Libre mantiene precios de
  línea idénticos a los que el sistema ya calculaba antes de esta spec, y ninguna queda con Lista de
  Precios asignada.
- **SC-005**: El rechazo de una publicación individual (pausada, cerrada) no impide que el resto de los
  vínculos afectados por el mismo evento o por la acción manual se sincronice con normalidad.
- **SC-006**: El usuario puede forzar "Sincronizar precios ahora" y ver su resultado, sin recargar la
  página, en menos de un minuto.
- **SC-007**: Al cambiar la Lista de Precios configurada, el 100% de los productos vinculados con precio
  cargado en la nueva lista recibe el precio actualizado sin necesidad de que el usuario toque cada
  producto individualmente.

## Assumptions

- **Sin corrida programada**: a diferencia de la sincronización de stock (spec 013), este flujo no usa
  `frecuencia_sync_minutos` ni ningún cron — el disparador es siempre el evento de cambio de precio o la
  acción manual.
- **Fuente única**: el precio enviado a Mercado Libre siempre sale de `precios_producto.precio` para la
  Lista de Precios configurada; ningún otro campo (por ejemplo `productos.precio_venta`) interviene en
  este flujo.
- **Sin validación de "activa" al sincronizar**: se sigue el mismo criterio ya vigente para
  `categoria_venta_id` en la spec anterior — la configuración no revalida en cada evento que la Lista de
  Precios elegida siga activa.
- **Una sola cuenta de Mercado Libre**: se mantiene el supuesto single-tenant ya vigente desde la spec 011.
- **Reintentos acotados**: se reutiliza el mismo criterio de reintento con espera creciente y tope ya
  definido en las specs 012/013, sin un nuevo mecanismo propio.

## Dependencies

- **Interna — spec 012 (implementada)**: `ml_publicacion_producto` (vinculación 1:1 producto↔publicación),
  pantalla de vinculación de publicaciones, sobre las que esta spec agrega estado de sincronización de
  precio.
- **Interna — spec 013 (implementada)**: patrón de referencia directo (`SincronizadorStock`, campos
  `stock_pendiente`/`stock_sincronizado_en`/`stock_error`/`stock_error_en`) que esta spec replica para
  precio, adaptado a disparo por evento en lugar de corrida programada.
- **Interna — spec 011 (implementada)**: `ClienteMercadoLibre` (kill-switch, renovación de credenciales,
  reintento con espera creciente), `ml_operaciones_log`.
- **Interna**: módulo de Listas de Precios y Precios de Producto (`listas_precio`, `precios_producto`,
  `ListaPrecio`, `PrecioProducto`), ya existente — `ProductoController::sincronizarPrecios()` y
  `app/Services/Import/ImportadorFilas.php` como los dos caminos de escritura conocidos sobre
  `precios_producto`.

## Restricciones de diseño y entorno

- **Especificaciones de diseño obligatorias del proyecto** (`CLAUDE.md`): el selector de Lista de Precios
  usa Select2, dentro del mismo formulario AJAX sin recarga de página y notificación toast ya usado por
  Depósito y Categoría de Venta en esta misma pantalla; el estado de sincronización de precio se muestra
  dentro de la tabla ya existente de vinculaciones (DataTables, carga por demanda); "Sincronizar precios
  ahora" (pantalla de Productos, FR-018) usa el mismo patrón AJAX sin recarga de página y notificaciones
  toast que el resto de las acciones ya existentes en esa pantalla (alta/edición de Producto, acciones
  masivas).
- **Portabilidad de entorno**: igual que las specs 012/013 — mismo código en hosting compartido y en
  servidor dedicado; a diferencia de esas specs, este flujo no depende de ningún mecanismo de corrida
  programada, por lo que no hereda la restricción de portabilidad asociada a los cron jobs.
- **Idioma del dominio**: nombres de campos, columnas y textos de interfaz en español.
- **Secretos**: ninguna credencial se registra en logs; el historial de operaciones no debe contener datos
  sensibles (igual que specs 011/012/013).
- **Testing**: por el principio IV de la constitución, FR-004/FR-005 (disparo por evento sin importar el
  camino de escritura), FR-006 (no disparo fuera de alcance), FR-007 (push inmediato al cambiar de lista
  configurada), FR-009/FR-010 (reintento y registro de error), FR-011/FR-012 (cortes de escritura),
  FR-015 (no concurrencia) y FR-019/FR-020 (exclusiones sobre el cálculo de precio de Venta y el
  etiquetado) requieren tests obligatorios — FR-019 en particular, por ser la garantía central de que esta
  spec no introduce una regresión de cálculo sobre Ventas ya construidas (spec 012).

## Impacto en la documentación de dominio

Conforme al principio I de la constitución, esta spec introduce contenido que debe reflejarse en la
documentación de dominio **antes de pasar a `/speckit-tasks`**:

1. `docs/documentacion_principal_crm.md`:
   - Actualizar §3.2.bis/§5.2 (Mercado Libre) para documentar el nuevo campo Lista de Precios en la
     configuración como mecanismo de gestión de precios hacia Mercado Libre (no como etiqueta de Venta),
     aclarando explícitamente que sigue sin afectar el cálculo de precios de las Ventas convertidas.
2. `docs/modelo_datos.md`:
   - Agregar `lista_precio_id` (FK → `listas_precio`, nullable) a la tabla `ml_configuracion`.
   - Ampliar `ml_publicacion_producto` con los atributos de sincronización de precio (pendiente, último
     envío exitoso, motivo y fecha del último error), análogos a los ya agregados por la spec 013 para
     stock.
