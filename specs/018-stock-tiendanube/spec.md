# Feature Specification: Sincronización de stock del CRM hacia Tiendanube

**Feature Branch**: `018-stock-tiendanube`

**Created**: 2026-07-29

**Status**: Draft

**Input**: User description: "Integración Tiendanube — Etapa 3: Sincronización de stock del CRM hacia
Tiendanube. Cuando el stock de un producto vinculado (tabla `tn_variante_producto`, spec 017) cambia en
el CRM — por una Venta manual, un ajuste, una transferencia, o cualquier otro movimiento de stock
existente — el sistema debe actualizar la cantidad disponible de la variante correspondiente en
Tiendanube, para cerrar el riesgo de sobreventa documentado explícitamente en la spec 017. Es la
contraparte inversa del flujo ya construido en la 017 (que sólo trae órdenes hacia el CRM, nunca empuja
stock hacia Tiendanube). Debe seguir el mismo patrón estructural que la spec 013 (stock hacia Mercado
Libre) respecto de la spec 012, adaptado a las diferencias reales de Tiendanube: vinculación por variante
en vez de por publicación, límite de tasa distinto (~2 solicitudes/segundo, ráfagas de hasta 40), sin
OAuth ni token que renovar. Debe respetar el kill-switch de modo sólo lectura y la función avanzada
Tiendanube, convivir con la sincronización de órdenes ya programada sin condiciones de carrera, y cubrir:
qué eventos disparan una actualización, cómo evitar bucles con las órdenes de Tiendanube ya ingresadas,
qué pasa si la variante no está vinculada, qué pasa si Tiendanube rechaza la actualización, y cómo se
refleja en el historial de operaciones."

## Contexto y fuentes

Esta spec es la **etapa 3** del módulo de integración con Tiendanube. Continúa directamente
`specs/017-ventas-tiendanube/`, que dejó explícitamente pendiente esta funcionalidad:

> "**Sincronización de stock del CRM hacia Tiendanube** → spec posterior (018), mismo patrón que la
> spec 013 respecto de la 012. Mientras no exista, aplica el mismo riesgo de sobreventa ya documentado
> para Mercado Libre." (`specs/017-ventas-tiendanube/spec.md`, sección Alcance)

La spec 017 documentó el riesgo que esta spec cierra:

> "⚠️ Riesgo de sobreventa hasta la spec 018: mientras la sincronización de stock CRM→Tiendanube no
> exista, una Venta manual del CRM (o una Venta de Mercado Libre, o de cualquier otro origen) que
> descuente stock de un producto también vendido en Tiendanube no reduce el stock publicado en
> Tiendanube." (`specs/017-ventas-tiendanube/spec.md`, sección Advertencias; también
> `docs/documentacion_principal_crm.md` §3.2.quater)

**Relación con la 017**: la 017 construyó el flujo **Tiendanube → CRM** (una orden de Tiendanube
descuenta stock local al convertirse, spec 017 FR-046, reutilizando `StockDeVenta`). Esta spec construye
el flujo inverso, **CRM → Tiendanube** (un movimiento de stock local empuja la cantidad disponible hacia
la variante vinculada en Tiendanube), reutilizando la misma infraestructura: la vinculación
`tn_variante_producto` (1:1, spec 017), el depósito configurado en `tn_configuracion.deposito_id`
(spec 017, FR-047), el cliente de API (`ClienteTiendanube`, spec 015) con el kill-switch de modo sólo
lectura, y el historial de operaciones (`tn_operaciones_log`, spec 015).

**Es exactamente el mismo problema que la spec 013 ya resolvió para Mercado Libre**, con dos diferencias
reales de la API de Tiendanube frente a Mercado Libre (fuente: documentación oficial de
Nuvemshop/Tiendanube, `tiendanube.github.io/api-documentation`, consultada 29/07/2026), que esta spec
adapta en vez de ignorar:

1. **La vinculación es por variante (`variant_id`), no por publicación**: Tiendanube actualiza stock a
   nivel de variante (incluso los productos sin variantes reales tienen una "variante virtual" única, ya
   vinculada 1:1 por la spec 017). No existe el caso "publicación sin variantes" que la spec 012/013
   excluía en Mercado Libre — toda vinculación de la spec 017 es, por definición, una variante.
2. **Límite de tasa distinto**: Tiendanube usa un esquema de leaky bucket (~2 solicitudes/segundo,
   ráfagas de hasta 40, ya documentado y usado en la spec 017 FR-020), en vez del límite propio de
   Mercado Libre. El mecanismo de espera creciente ante rechazo por exceso de solicitudes se reutiliza,
   ajustado a este límite.
3. **Sin OAuth ni token que vencer**: a diferencia de Mercado Libre (spec 011), el `access_token` de
   Tiendanube no vence (spec 015) — el único corte de conexión relevante para esta spec es "caída" por
   revocación/regeneración manual del token, detectada igual que ya lo hace la sincronización de órdenes
   (spec 017, FR-018).

**Divergencia respecto de Contagram**: no hay relevamiento propio de esta pantalla, igual que la 017 —
Contagram no documenta públicamente el detalle de su sincronización de stock hacia Tiendanube. Esta spec
no agrega pantallas nuevas: extiende la pantalla de Tiendanube y la de Vinculación de variantes ya
construidas en la 017, con indicadores de estado de sincronización de stock — mismo criterio que la spec
013 aplicó sobre la 012.

**Fuentes de dominio**: `docs/documentacion_principal_crm.md` §3.2.quater (nota de riesgo) y §5.3
(integración Tiendanube), `docs/modelo_datos.md` §11 y §12, `specs/017-ventas-tiendanube/spec.md` y
`specs/017-ventas-tiendanube/data-model.md`, `specs/013-stock-mercadolibre/spec.md` (patrón estructural
de referencia directo).

## Alcance

**Incluye**: detectar los movimientos de stock del CRM que afectan a un producto vinculado a una
variante de Tiendanube, consolidar esos cambios y empujar la cantidad disponible resultante hacia
Tiendanube de forma programada (con la misma cadencia configurable ya existente) y también bajo demanda
("Sincronizar stock ahora"), evitando que un movimiento originado por una orden de Tiendanube rebote de
vuelta hacia Tiendanube, y dejando visible el resultado de cada intento (éxito, pendiente, rechazado) en
la pantalla de vinculación de variantes y en el historial de operaciones existente.

**Excluye explícitamente**:

- Sincronización de **precio**, **nombre**, **descripción**, **imágenes** o **visibilidad/estado** de la
  variante o el producto: sólo la cantidad disponible.
- **Comisión de Tiendanube y costo de envío** a cargo del vendedor: siguen fuera de alcance, igual que en
  la spec 017.
- Sincronización de stock de productos **no vinculados**: sin vínculo no hay variante a la que empujarle
  nada (comportamiento ya establecido en la 017 para el sentido inverso).
- Despausar, republicar o modificar el estado de publicación de un producto cuando su stock vuelve a ser
  positivo, o pausarlo/despublicarlo cuando llega a cero: informar cantidad cero ya alcanza para que
  Tiendanube deje de vender esa variante.
- Importación masiva de catálogo, sincronización de precios, webhooks de negocio: mismas exclusiones ya
  vigentes desde la spec 017.

## Clarifications

### Session 2026-07-29

Decisiones de continuidad directa con la spec 013 (mismo problema, aplicado a la API real de Tiendanube),
resueltas por decisión fundamentada sin interrumpir al usuario:

- Q: ¿Qué stock se publica cuando el negocio tiene varios depósitos? → A: el del **depósito configurado
  para Tiendanube** (`tn_configuracion.deposito_id`, ya existente desde la spec 017 FR-047; el depósito
  por defecto del CRM si no se eligió ninguno). Es el mismo depósito del que ya descuentan las Ventas
  originadas en Tiendanube — usar otro depósito como fuente daría una cantidad disponible inconsistente
  con el stock que la propia integración gestiona. Mismo criterio que la spec 013 fijó para Mercado
  Libre.
- Q: ¿Cómo se evita que una orden de Tiendanube que descuenta stock local dispare un push de vuelta hacia
  Tiendanube? → A: los movimientos de stock **originados por la conversión de una orden de Tiendanube**
  (spec 017, FR-046: todo movimiento queda referenciado a la Venta que lo originó, y esa Venta expone su
  origen "tiendanube", spec 017 FR-035/FR-035a) quedan **excluidos** de disparar sincronización de stock
  hacia Tiendanube. Tiendanube ya descontó esa unidad de su propio stock al generar la orden; empujarla
  de vuelta sería, en el mejor caso, redundante, y en el peor, una fuente de inconsistencia si llegara
  desfasada en el tiempo. Mismo mecanismo que la spec 013 (FR-002) construyó para el origen
  "mercadolibre".
- Q: ¿Cada movimiento de stock dispara un llamado inmediato a la API? → A: **no, se consolidan**, igual
  que la spec 013. Cada movimiento elegible marca el vínculo variante↔producto como "con cambios
  pendientes de sincronizar"; la corrida programada (misma frecuencia configurable de la spec 017,
  `frecuencia_sync_minutos`, ejecutada inmediatamente después de traer las órdenes nuevas) empuja **un
  único valor final** por variante, sin importar cuántos movimientos hubo desde el último envío. Evita
  exceder el límite de solicitudes de Tiendanube (~2/segundo, ráfagas de hasta 40) ante ráfagas de
  movimientos (varias Ventas seguidas, una importación).
- Q: ¿Cómo se interpreta un rechazo puntual de Tiendanube al actualizar el stock de una variante (variante
  eliminada, producto despublicado o inexistente)? → A: se trata como **rechazo definitivo** de ese
  vínculo puntual (mismo tratamiento que la spec 013 dio a una publicación pausada/cerrada de Mercado
  Libre): se registra el motivo y la fecha, el vínculo queda con cambios pendientes para reintentar en la
  próxima corrida, y el resto de los vínculos de la misma corrida se sincroniza sin verse afectado.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Que una Venta cargada a mano en el CRM se refleje en Tiendanube (Priority: P1)

Como responsable del negocio, cuando cargo una Venta en el CRM sobre un producto que tengo vinculado a
una variante de Tiendanube, quiero que la cantidad disponible de esa variante baje sola en Tiendanube,
sin que yo tenga que entrar a Tiendanube a corregirla a mano.

**Why this priority**: es el motivo de ser de esta spec — cerrar el riesgo de sobreventa documentado en
la 017. Sin esto, el negocio sigue vendiendo en Tiendanube unidades que ya no tiene.

**Independent Test**: se puede probar cargando una Venta manual sobre un producto vinculado con stock
disponible, esperando el intervalo de sincronización (o forzándola), y verificando que la cantidad
disponible de la variante en Tiendanube bajó exactamente en la cantidad vendida.

**Acceptance Scenarios**:

1. **Given** un producto vinculado con stock disponible, **When** se carga una Venta manual del CRM que
   lo incluye, **Then** el vínculo queda marcado con cambios pendientes de sincronizar.
2. **Given** un vínculo con cambios pendientes, **When** corre la sincronización programada, **Then**
   Tiendanube recibe la nueva cantidad disponible de la variante y el vínculo deja de estar pendiente.
3. **Given** varias Ventas seguidas sobre el mismo producto entre dos corridas, **When** corre la
   sincronización, **Then** Tiendanube recibe un único valor, igual al stock final del CRM en ese
   momento, no una llamada por Venta.
4. **Given** el listado de vinculaciones de variantes, **When** el usuario lo mira, **Then** ve para cada
   vínculo si está sincronizado, pendiente, o con error, y cuándo fue el último envío exitoso.
5. **Given** un ajuste de stock que **incrementa** el stock de un producto vinculado, **When** corre la
   sincronización, **Then** la cantidad disponible en Tiendanube también sube.

---

### User Story 2 - Que una orden de Tiendanube no rebote de vuelta (Priority: P1)

Como responsable del negocio, cuando una orden de Tiendanube descuenta stock local (spec 017), no quiero
que eso dispare un envío de vuelta hacia Tiendanube: Tiendanube ya sabe que esa unidad se vendió, fue la
causa del descuento.

**Why this priority**: sin esta exclusión, el sistema generaría llamadas redundantes o, peor, una carrera
entre "traer la orden que bajó el stock" y "avisar de ese mismo stock bajado" que podría desincronizar la
cantidad real. Es tan crítico como la historia 1: sin él, la sincronización no es confiable.

**Independent Test**: se puede probar convirtiendo una orden de Tiendanube en Venta (spec 017), esperando
la siguiente sincronización, y verificando en el historial de operaciones que no se generó ningún envío
de stock asociado a ese movimiento.

**Acceptance Scenarios**:

1. **Given** una orden de Tiendanube convertida en Venta, **When** se descuenta el stock del producto
   vinculado, **Then** ese movimiento **no** marca el vínculo como pendiente de sincronizar.
2. **Given** la misma corrida programada, **When** trae órdenes nuevas y luego sincroniza stock, **Then**
   el orden de ejecución garantiza que el stock empujado ya contempla las órdenes recién traídas, sin una
   segunda corrida necesaria para que quede consistente.
3. **Given** un producto vinculado que tuvo tanto una Venta manual como una orden de Tiendanube entre dos
   corridas, **When** corre la sincronización, **Then** se empuja igual, porque la Venta manual sí generó
   cambios pendientes; el valor enviado es el stock final correcto.

---

### User Story 3 - Forzar la sincronización de stock manualmente (Priority: P2)

Como responsable del negocio, quiero poder forzar el envío del stock actual hacia Tiendanube sin esperar
al intervalo programado, igual que ya puedo forzar la sincronización de órdenes.

**Why this priority**: da control inmediato en momentos puntuales (por ejemplo, después de un ajuste de
stock grande), pero la sincronización programada de la historia 1 ya entrega el valor central sin esta
acción manual.

**Independent Test**: se puede probar presionando "Sincronizar stock ahora" con vínculos pendientes y
verificando que se envían de inmediato, sin esperar el intervalo configurado.

**Acceptance Scenarios**:

1. **Given** vínculos con cambios pendientes, **When** el usuario presiona "Sincronizar stock ahora",
   **Then** el sistema los envía de inmediato e informa por notificación cuántos se actualizaron, sin
   recargar la página.
2. **Given** el modo sólo lectura activo o la función Tiendanube desactivada, **When** el usuario busca la
   acción, **Then** no está disponible, con el motivo visible.
3. **Given** una sincronización de stock ya en curso, **When** el usuario dispara otra, **Then** sólo una
   se ejecuta y la otra se descarta, sin enviar la misma variante dos veces en simultáneo.

---

### User Story 4 - Enterarse cuando Tiendanube rechaza una actualización (Priority: P2)

Como responsable del negocio, si Tiendanube rechaza la actualización de stock de una variante (porque el
producto está despublicado, eliminado, o por cualquier otro motivo), quiero verlo señalado con el motivo,
sin que eso bloquee la sincronización del resto de mis productos vinculados.

**Why this priority**: sin visibilidad del rechazo, el CRM cree que el stock está sincronizado cuando no
lo está, reintroduciendo en silencio el mismo riesgo de sobreventa que esta spec busca cerrar.

**Independent Test**: se puede probar vinculando una variante que luego se elimina o despublica en
Tiendanube, generando un movimiento de stock sobre su producto, sincronizando, y verificando que queda
señalada con el motivo del rechazo mientras el resto de los vínculos se sincroniza con normalidad.

**Acceptance Scenarios**:

1. **Given** una variante eliminada o un producto despublicado en Tiendanube, **When** su producto
   vinculado tiene un cambio de stock pendiente, **Then** el envío se rechaza, el vínculo queda señalado
   con el motivo concreto, y el resto de los vínculos de esa misma corrida se sincroniza sin verse
   afectado.
2. **Given** un rechazo por exceso de solicitudes (leaky bucket, ~2/segundo, ráfagas de hasta 40) o una
   falla temporal de red, **When** ocurre, **Then** el sistema reintenta con espera creciente antes de
   marcarlo como error, sin descartar el pendiente.
3. **Given** un vínculo marcado con error, **When** el usuario lo revisa en la pantalla de vinculación de
   variantes, **Then** ve el motivo del último rechazo y cuándo ocurrió.
4. **Given** un vínculo con error persistente, **When** vuelve a tener un cambio de stock pendiente y
   corre la sincronización, **Then** el sistema lo vuelve a intentar (el error no lo excluye
   permanentemente de futuras corridas).

---

### Edge Cases

- **Stock local negativo** (por ejemplo, tras una orden de Tiendanube que vendió de más, spec 017
  FR-046d): el sistema **nunca** publica una cantidad negativa; empuja **cero**.
- **Movimiento de stock en un depósito distinto al configurado para Tiendanube**: no dispara
  sincronización — sólo importa el stock del depósito configurado (ver Clarifications).
- **Vínculo eliminado con un envío pendiente**: el pendiente se descarta; no hay variante vigente a la
  que empujarle nada.
- **Producto vinculado inactivado en el CRM**: sigue empujando su stock real (que puede ser cero); no se
  despublica ni se elimina la variante (fuera de alcance).
- **Cambio de depósito configurado para Tiendanube**: todos los vínculos existentes quedan marcados como
  pendientes, para que la próxima corrida sincronice contra el stock del nuevo depósito.
- **La sincronización de stock se interrumpe a mitad de camino**: los vínculos ya enviados no se
  reenvían de más; los que quedaron pendientes se retoman en la corrida siguiente.
- **Conexión con Tiendanube caída** (token revocado o regenerado, spec 015): no se ejecuta el envío,
  igual que la sincronización de órdenes (spec 017, FR-018); el pendiente se conserva para cuando se
  restablezca.
- **Tiendanube rechaza por exceso de solicitudes**: reintento con espera creciente, reutilizando el mismo
  mecanismo que la spec 017 (FR-020), sin descartar pendientes.
- **El proceso que ejecuta la sincronización se interrumpe a mitad de camino** (caída del worker/proceso,
  no una interrupción lógica): los vínculos ya enviados exitosamente en esa corrida quedan sincronizados;
  los que no llegaron a procesarse siguen `stock_pendiente = true` y se retoman en la corrida siguiente,
  sin necesidad de una marca de "corrida en progreso" adicional a la del candado (FR-008) — el candado ya
  se libera solo si el proceso muere, permitiendo que la próxima corrida programada continúe.
- **Reintentos agotados sin éxito**: tras el número acotado de reintentos con espera creciente (FR-013),
  el vínculo queda con `stock_error`/`stock_error_en` (FR-014), sin un límite adicional de "cuántas
  corridas puede seguir reintentándose": mientras el vínculo exista y tenga cambios pendientes, cada
  corrida programada o manual lo vuelve a intentar (mismo criterio ya fijado por FR-014, última viñeta).
- **Vínculo sin `tn_product_id` completo** (por ejemplo, por una edición manual de la base de datos que lo
  dejara vacío): el sistema NO DEBE intentar el envío para ese vínculo — lo trata como error de datos
  propio (no un rechazo de Tiendanube), lo señala con un motivo distintivo ("vínculo incompleto") y no
  bloquea el resto de la corrida, mismo tratamiento que un rechazo de Tiendanube (FR-014/FR-015).

## Requirements *(mandatory)*

> **Nota de numeración**: los identificadores FR-### se mantienen **a propósito** alineados con los de la
> spec 013 cuando el requisito es el mismo o una adaptación directa, para que sea trivial comparar ambas
> specs lado a lado.

### Functional Requirements — Disparo y consolidación

- **FR-001**: El sistema DEBE marcar un vínculo variante↔producto (`tn_variante_producto`) como "con
  cambios pendientes de sincronizar" cada vez que el stock del producto vinculado cambie en el depósito
  configurado para Tiendanube (`tn_configuracion.deposito_id`, o el depósito por defecto si no hay
  ninguno elegido), sin importar el origen del movimiento (Venta, ajuste manual, transferencia, o
  cualquier otro movimiento de stock existente en el CRM).
- **FR-002**: El sistema NO DEBE marcar como pendiente un movimiento de stock que se originó en la
  conversión de una orden de Tiendanube en Venta (spec 017, FR-046), para no generar un envío redundante
  o inconsistente hacia el mismo origen del que vino el dato.
- **FR-003**: El sistema DEBE consolidar todos los cambios pendientes de un mismo producto ocurridos
  entre dos sincronizaciones en un único valor a enviar: el stock actual del producto en el depósito
  configurado en el momento de la corrida, no un acumulado de movimientos. Esto aplica **aunque el valor
  final coincida con el último enviado** (por ejemplo, movimientos que se cancelan entre sí): el sistema
  igual lo envía, en lugar de intentar detectar que "no cambió nada".
- **FR-004**: El sistema DEBE tratar el stock negativo como **cero** al momento de enviarlo a Tiendanube,
  sin alterar el valor real que se muestra dentro del CRM.
- **FR-005**: El sistema NO DEBE generar cambios pendientes para productos sin vínculo vigente con una
  variante de Tiendanube.
- **FR-005a**: El sistema NO DEBE intentar el envío de un vínculo cuyo `tn_product_id` esté vacío o
  incompleto; DEBE señalarlo con un motivo distintivo de error de datos (no un rechazo de Tiendanube) y
  continuar con el resto de los vínculos pendientes de la misma corrida, sin bloquearla.

### Functional Requirements — Ejecución y concurrencia

- **FR-006**: El sistema DEBE ejecutar la sincronización de stock de forma programada, con la misma
  frecuencia configurable ya existente para las órdenes (`tn_configuracion.frecuencia_sync_minutos`,
  spec 017), como parte de la misma corrida, **después** de traer y procesar las órdenes nuevas.
- **FR-007**: El sistema DEBE ofrecer una acción manual "Sincronizar stock ahora" que envíe de inmediato
  los vínculos con cambios pendientes, informando el resultado por notificación sin recargar la página.
- **FR-008**: El sistema DEBE garantizar que dos sincronizaciones de stock no se ejecuten
  simultáneamente: si una está en curso, la siguiente (programada o manual) se descarta. Este control es
  **independiente** del que ya impide dos sincronizaciones de órdenes simultáneas (spec 017, FR-014): un
  envío de stock en curso no bloquea una sincronización de órdenes, y viceversa.
- **FR-009**: El sistema NO DEBE ejecutar la sincronización de stock mientras la función "Tiendanube" esté
  desactivada o el modo sólo lectura esté activo, dado que es una operación de escritura hacia Tiendanube;
  DEBE registrar el intento bloqueado en el historial de operaciones existente, **conservando los cambios
  pendientes** para el próximo intento en que ninguno de los dos esté activo.
- **FR-010**: El sistema NO DEBE ejecutar la sincronización de stock mientras la conexión con Tiendanube
  esté caída o no configurada, conservando los cambios pendientes para el próximo intento válido.
- **FR-011**: El sistema DEBE funcionar de forma equivalente en un entorno sin procesos permanentes
  (hosting compartido) y en uno con procesamiento en segundo plano, sin cambios en el código, reutilizando
  el mismo mecanismo de portabilidad ya construido para la sincronización de órdenes (spec 017, FR-011).

### Functional Requirements — Envío y manejo de errores

- **FR-012**: El sistema DEBE actualizar, por cada vínculo con cambios pendientes, la cantidad disponible
  de la variante correspondiente en Tiendanube con el valor consolidado (FR-003/FR-004).
- **FR-013**: El sistema DEBE aplicar espera creciente ante rechazos por exceso de solicitudes (leaky
  bucket, ~2 solicitudes/segundo, ráfagas de hasta 40, mismo límite documentado y usado en la spec 017
  FR-020) y reintentar un número acotado de veces ante fallas temporales, sin descartar el pendiente ni
  bloquear el envío del resto de los vínculos de la misma corrida.
- **FR-014**: El sistema DEBE registrar, por cada vínculo cuyo envío fue rechazado de forma no transitoria
  (variante eliminada, producto despublicado o inexistente, u otro rechazo definitivo informado por
  Tiendanube), el motivo concreto y el momento del rechazo, dejando el vínculo con cambios pendientes para
  reintentarlo en la próxima corrida en lugar de descartarlo.
- **FR-015**: El sistema DEBE continuar sincronizando el resto de los vínculos pendientes de una corrida
  aunque uno de ellos sea rechazado.
- **FR-016**: El sistema DEBE registrar cada envío de stock (exitoso, rechazado o bloqueado) en el
  historial de operaciones ya existente (`tn_operaciones_log`, spec 015), como operación de sentido
  "escritura", sin incluir datos sensibles (el `access_token`).

### Functional Requirements — Visibilidad

- **FR-017**: El sistema DEBE mostrar, en la pantalla de "Vinculación de variantes" (spec 017, FR-024),
  por cada vínculo, su estado de sincronización de stock: sincronizado, con cambios pendientes, o con
  error; la fecha del último envío exitoso; y, cuando el estado sea "con error", el **motivo concreto del
  último rechazo y la fecha en que ocurrió** (mismo nivel de detalle que ya exige FR-007b de la spec 017
  para las órdenes que requieren atención).
- **FR-018**: El sistema DEBE ofrecer la acción "Sincronizar stock ahora" (FR-007) desde la misma pantalla
  de Tiendanube donde ya existe "Sincronizar ahora" para órdenes (spec 017, FR-009).
- **FR-019**: El sistema DEBE mostrar, en la pantalla de configuración de Tiendanube, la fecha y el
  resultado de la última sincronización de stock, análogo a lo ya expuesto para la de órdenes.

### Functional Requirements — Retención

- **FR-020**: El sistema NO DEBE requerir una tabla de historial propia para los envíos de stock más allá
  del historial de operaciones ya existente (spec 015): el estado vigente de cada vínculo
  (pendiente/sincronizado/error) es el único dato que se conserva de forma persistente y mutable sobre el
  propio vínculo.

### Key Entities

- **Vinculación variante ↔ producto** (`tn_variante_producto`, ya existente desde la spec 017): se le
  agregan el identificador del **producto** de Tiendanube dueño de la variante (necesario para poder
  actualizar su stock, FR-005a) y atributos de sincronización de stock — indicador de cambios pendientes,
  fecha del último envío exitoso, motivo del último error (si lo hubo) y fecha de ese error. No es una
  entidad nueva, es una extensión de la ya construida en la 017.
- **Envío de stock**: no es una entidad persistente propia; es la operación (registrada en el historial
  de operaciones ya existente) que consolida y transmite el estado de un vínculo en un momento dado.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Una Venta manual del CRM sobre un producto vinculado se refleja en la cantidad disponible
  de Tiendanube dentro del intervalo de sincronización configurado, sin ninguna acción manual del usuario
  en Tiendanube.
- **SC-002**: Ninguna orden de Tiendanube ya ingresada al CRM (spec 017) genera un envío de stock de
  vuelta hacia Tiendanube por ese mismo movimiento, verificable revisando que el historial de operaciones
  no registra un envío de escritura asociado a ese movimiento puntual.
- **SC-003**: Ante múltiples movimientos de stock del mismo producto entre dos corridas, Tiendanube
  recibe exactamente un envío con el valor final, no uno por movimiento.
- **SC-004**: La cantidad disponible publicada en Tiendanube nunca es negativa, verificable en el 100% de
  los casos donde el stock local del CRM cayó por debajo de cero.
- **SC-005**: Con el modo sólo lectura activo o la función Tiendanube desactivada, cero envíos de stock
  llegan a Tiendanube, y el 100% de los intentos bloqueados queda registrado en el historial de
  operaciones.
- **SC-006**: El rechazo de una variante individual (eliminada, producto despublicado) no impide que el
  resto de los vínculos de la misma corrida se sincronice con normalidad.
- **SC-007**: El usuario puede forzar una sincronización de stock manual y ver su resultado, sin recargar
  la página, en menos de un minuto.

## Assumptions

- **Fuente única de stock**: el depósito configurado para Tiendanube (`tn_configuracion.deposito_id`,
  spec 017) es la única fuente de la cantidad publicada; se documenta como decisión en Clarifications.
- **Sin sincronización en tiempo real estricto**: el envío ocurre en la corrida programada (misma
  cadencia que la de órdenes) o bajo demanda manual, no de forma instantánea al momento exacto del
  movimiento — coherente con la restricción de portabilidad a hosting compartido ya vigente desde la
  spec 015/017.
- **Sin despublicado automático**: llegar a cantidad disponible cero alcanza para que Tiendanube deje de
  vender esa variante; no se agrega una acción adicional de pausar, despublicar o eliminar.
- **Una sola tienda de Tiendanube**: se mantiene el supuesto single-tenant ya vigente desde la spec 015.
- **Reintentos acotados**: se reutiliza el mismo criterio de reintento con espera creciente y tope ya
  definido en la spec 017 (FR-020), sin un nuevo mecanismo propio, ajustado al límite real de Tiendanube
  (~2 solicitudes/segundo, ráfagas de hasta 40).
- **Las Compras todavía no mueven stock**: es una brecha ya documentada y ajena a esta spec
  (`docs/documentacion_principal_crm.md`, nota de §6.2) — el sistema reacciona a **cualquier** movimiento
  de stock (FR-001), pero hoy sólo las Ventas y los ajustes los generan. El día que Egresos resuelva esa
  brecha, las Compras quedan cubiertas por esta spec sin cambios adicionales.

## Dependencies

- **Interna — spec 017 (implementada)**: tabla `tn_variante_producto` (vinculación 1:1), movimientos de
  stock con referencia a la Venta que los originó, depósito configurado
  (`tn_configuracion.deposito_id`), y sincronización programada de órdenes, sobre la que esta spec
  agrega el paso de push de stock.
- **Interna — spec 015 (implementada)**: cliente de API `ClienteTiendanube`, kill-switch de modo sólo
  lectura, historial de operaciones (`tn_operaciones_log`).
- **Interna — spec 005 (implementada)**: Depósitos.
- **Interna — spec 002 (implementada)**: Productos y su stock.
- **Externa**: permisos de escritura sobre stock de la Aplicación personalizada de Tiendanube ya
  configurada (spec 015).
- **Patrón de referencia — spec 013 (implementada)**: mismo problema ya resuelto para Mercado Libre;
  esta spec reutiliza su estructura de requisitos y decisiones, adaptada a las diferencias reales de la
  API de Tiendanube documentadas arriba.

## Restricciones de diseño y entorno

- **Especificaciones de diseño obligatorias del proyecto** (`CLAUDE.md`): el estado de sincronización de
  stock se muestra dentro de la tabla ya existente de vinculación de variantes (DataTables, carga por
  demanda); "Sincronizar stock ahora" usa el mismo patrón AJAX sin recarga de página y notificaciones
  toast que "Sincronizar ahora" de órdenes (spec 017).
- **Portabilidad de entorno**: igual que la spec 017 — mismo código en hosting compartido y en servidor
  dedicado.
- **Idioma del dominio**: nombres de columnas, rutas y textos de interfaz en español.
- **Secretos**: ninguna credencial se registra en logs; el historial de operaciones no debe contener
  datos sensibles (igual que specs 015/017).
- **Testing**: por el principio IV de la constitución, la exclusión de movimientos originados en
  Tiendanube (FR-002), la consolidación de cambios pendientes (FR-003), el tope en cero (FR-004) y la no
  concurrencia (FR-008) requieren tests obligatorios.

## Impacto en la documentación de dominio

Conforme al principio I de la constitución, esta spec introduce contenido que debe reflejarse en la
documentación de dominio **antes de pasar a `/speckit-tasks`**:

1. `docs/documentacion_principal_crm.md`:
   - Actualizar §3.2.quater y §5.3 para reflejar que el riesgo de sobreventa documentado queda cerrado
     por esta spec, describiendo el sentido CRM → Tiendanube y su cadencia.
2. `docs/modelo_datos.md`:
   - Ampliar `tn_variante_producto` (§12) con los atributos nuevos de sincronización de stock
     (pendiente, último envío exitoso, motivo y fecha del último error).
