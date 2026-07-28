# Feature Specification: Sincronización de stock del CRM hacia Mercado Libre

**Feature Branch**: `013-stock-mercadolibre`

**Created**: 2026-07-28

**Status**: Draft

**Input**: User description: "Integración Mercado Libre — Etapa 3: Sincronización de stock del CRM hacia Mercado Libre. Cuando el stock de un producto vinculado cambia en el CRM — por una Venta, una Compra, un ajuste manual de stock, o cualquier otro movimiento — el sistema debe actualizar la cantidad disponible de la publicación correspondiente en Mercado Libre, para cerrar el riesgo de sobreventa documentado en la spec 012. Es la contraparte inversa del flujo ya construido en la 012 (que sólo trae stock hacia abajo, de ML al CRM, nunca al revés). Debe respetar el kill-switch de modo sólo lectura y convivir con la sincronización de órdenes ya programada sin condiciones de carrera. Debe cubrir: qué eventos disparan una actualización, cómo evitar bucles, qué pasa si la publicación no está vinculada, qué pasa si Mercado Libre rechaza la actualización, y cómo se refleja en el historial de operaciones."

## Contexto y fuentes

Esta spec es la **etapa 3** del módulo de integración con Mercado Libre. Continúa directamente
`specs/012-ventas-mercadolibre/`, que dejó explícitamente pendiente esta funcionalidad:

> "**Sincronización de stock del CRM hacia Mercado Libre** → **spec 013**, encadenada inmediatamente
> después de ésta. [...] Esta spec deja construida la tabla de vinculación publicación↔producto sobre
> la que la 013 se apoya." (`specs/012-ventas-mercadolibre/spec.md`, sección Alcance)

La spec 012 documentó el riesgo que esta spec cierra:

> "⚠️ Riesgo de sobreventa hasta la spec 013: el flujo de stock es unidireccional. Una venta manual
> del CRM baja el stock local pero no reduce el stock publicado en Mercado Libre, que sigue
> ofreciendo unidades inexistentes." (`docs/documentacion_principal_crm.md §3.6`)

**Relación con la 012**: la 012 construyó el flujo **ML → CRM** (una orden de Mercado Libre descuenta
stock local, spec 012 FR-046). Esta spec construye el flujo inverso, **CRM → ML** (un movimiento de
stock local empuja la cantidad disponible hacia la publicación de Mercado Libre), reutilizando la
misma infraestructura: la vinculación `ml_publicacion_producto` (1:1, spec 012), el depósito
configurado en `ml_configuracion.deposito_id` (spec 012, FR-047), el cliente de API con renovación de
credenciales y el kill-switch de modo sólo lectura (spec 011), y el historial de operaciones
(`ml_operaciones_log`, spec 011).

**Divergencia respecto de Contagram**: no hay relevamiento propio de esta pantalla — Contagram no
documenta públicamente el detalle de su sincronización de stock hacia Mercado Libre más allá de
mencionar que existe. Esta spec no agrega pantallas nuevas: extiende la pantalla de Mercado Libre y la
de Vinculación de publicaciones ya construidas en la 012, con indicadores de estado de sincronización
de stock. La divergencia deliberada de permisos de escritura de la spec 011 (`docs §5.2`) sigue
vigente y aplica también a esta spec, porque el push de stock es, precisamente, una escritura.

**Fuentes de dominio**: `docs/documentacion_principal_crm.md` §3.6 (nota de riesgo) y §5.2 (integración
Mercado Libre), `docs/modelo_datos.md` §8 y §10, `specs/012-ventas-mercadolibre/spec.md` y
`specs/012-ventas-mercadolibre/data-model.md`, `MERCADOLIBRE_NOTAS_TECNICAS.md` §8 (trampas verificadas
de la API de Mercado Libre).

## Alcance

**Incluye**: detectar los movimientos de stock del CRM que afectan a un producto vinculado a una
publicación de Mercado Libre, consolidar esos cambios y empujar la cantidad disponible resultante
hacia Mercado Libre de forma programada (con la misma cadencia configurable ya existente) y también
bajo demanda ("Sincronizar stock ahora"), evitando que un movimiento originado por una orden de
Mercado Libre rebote de vuelta hacia Mercado Libre, y dejando visible el resultado de cada intento
(éxito, pendiente, rechazado) en la pantalla de vinculación de publicaciones y en el historial de
operaciones existente.

**Excluye explícitamente**:

- Sincronización de **precio**, **título**, **descripción**, **imágenes** o **estado** (pausar/activar)
  de la publicación: sólo la cantidad disponible.
- **Comisión de Mercado Libre y costo de envío** a cargo del vendedor: siguen fuera de alcance, igual
  que en la spec 012.
- Publicaciones **con variantes**: siguen sin soportarse (spec 012, FR-027) — no pueden vincularse, por
  lo tanto no participan de esta sincronización.
- Pausar o cerrar automáticamente una publicación cuando su stock local llega a cero: Mercado Libre ya
  deja de vender solo cuando la cantidad disponible informada es cero; no hace falta una acción
  adicional del CRM.
- Sincronización de stock de productos **no vinculados**: sin vínculo no hay publicación a la que
  empujar nada (comportamiento ya establecido en la 012 para el sentido inverso).

## Clarifications

### Session 2026-07-28

Decisiones de continuidad directa con la spec 012, resueltas por decisión fundamentada sin interrumpir
al usuario (se apoyan en configuración y comportamiento ya construidos en la etapa anterior):

- Q: ¿Qué stock se publica cuando el negocio tiene varios depósitos? → A: el del **depósito configurado
  para Mercado Libre** (`ml_configuracion.deposito_id`, ya existente desde la spec 012 FR-047; el
  depósito por defecto del CRM si no se eligió ninguno). Es el mismo depósito del que ya descuentan las
  Ventas originadas en Mercado Libre — usar cualquier otro depósito como fuente daría una cantidad
  disponible inconsistente con el stock que la propia integración gestiona.
- Q: ¿Cómo se evita que una orden de Mercado Libre que descuenta stock local dispare un push de vuelta
  hacia Mercado Libre? → A: los movimientos de stock **originados por la conversión de una orden de
  Mercado Libre** (spec 012, FR-046c: todo movimiento queda referenciado a la Venta que lo originó, y
  esa Venta expone su origen "MercadoLibre", spec 012 FR-035) quedan **excluidos** de disparar
  sincronización de stock hacia Mercado Libre. Mercado Libre ya descontó esa unidad de su propio stock
  al generar la orden; empujarla de vuelta sería, en el mejor caso, redundante, y en el peor, una
  fuente de inconsistencia si llegara desfasada en el tiempo.
- Q: ¿Cada movimiento de stock dispara un llamado inmediato a la API? → A: **no, se consolidan**. Cada
  movimiento elegible marca el vínculo publicación↔producto como "con cambios pendientes de
  sincronizar"; la corrida programada (misma frecuencia configurable de la spec 012,
  `frecuencia_sync_minutos`, ejecutada inmediatamente después de traer las órdenes nuevas) empuja **un
  único valor final** por producto, sin importar cuántos movimientos hubo desde el último envío. Evita
  exceder el límite de solicitudes de Mercado Libre ante ráfagas de movimientos (varias Ventas
  seguidas, una importación) y es coherente con que esta integración ya opera con una cadencia
  programada, no en tiempo real estricto (spec 011/012, restricción de portabilidad a hosting
  compartido).

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Que una Venta cargada a mano en el CRM se refleje en Mercado Libre (Priority: P1)

Como responsable del negocio, cuando cargo una Venta en el CRM sobre un producto que tengo vinculado a
una publicación de Mercado Libre, quiero que la cantidad disponible de esa publicación baje sola en
Mercado Libre, sin que yo tenga que entrar a Mercado Libre a corregirla a mano.

**Why this priority**: es el motivo de ser de esta spec — cerrar el riesgo de sobreventa documentado en
la 012. Sin esto, el negocio sigue vendiendo en Mercado Libre unidades que ya no tiene.

**Independent Test**: se puede probar cargando una Venta manual sobre un producto vinculado con stock
disponible, esperando el intervalo de sincronización (o forzándola), y verificando que la cantidad
disponible de la publicación en Mercado Libre bajó exactamente en la cantidad vendida.

**Acceptance Scenarios**:

1. **Given** un producto vinculado con stock disponible, **When** se carga una Venta manual del CRM que
   lo incluye, **Then** el vínculo queda marcado con cambios pendientes de sincronizar.
2. **Given** un vínculo con cambios pendientes, **When** corre la sincronización programada, **Then**
   Mercado Libre recibe la nueva cantidad disponible y el vínculo deja de estar pendiente.
3. **Given** varias Ventas seguidas sobre el mismo producto entre dos corridas, **When** corre la
   sincronización, **Then** Mercado Libre recibe un único valor, igual al stock final del CRM en ese
   momento, no una llamada por Venta.
4. **Given** el listado de vinculaciones, **When** el usuario lo mira, **Then** ve para cada vínculo si
   está sincronizado, pendiente, o con error, y cuándo fue el último envío exitoso.
5. **Given** una Compra que **incrementa** el stock de un producto vinculado, **When** corre la
   sincronización, **Then** la cantidad disponible en Mercado Libre también sube.

---

### User Story 2 - Que una orden de Mercado Libre no rebote de vuelta (Priority: P1)

Como responsable del negocio, cuando una orden de Mercado Libre descuenta stock local (spec 012), no
quiero que eso dispare un envío de vuelta hacia Mercado Libre: Mercado Libre ya sabe que esa unidad se
vendió, fue la causa del descuento.

**Why this priority**: sin esta exclusión, el sistema generaría llamadas redundantes o, peor, una
carrera entre "traer la orden que bajó el stock" y "avisar de ese mismo stock bajado" que podría
desincronizar la cantidad real. Es tan crítico como la historia 1: sin él, la sincronización no es
confiable.

**Independent Test**: se puede probar convirtiendo una orden de Mercado Libre en Venta (spec 012),
esperando la siguiente sincronización, y verificando en el historial de operaciones que no se generó
ningún envío de stock asociado a ese movimiento.

**Acceptance Scenarios**:

1. **Given** una orden de Mercado Libre convertida en Venta, **When** se descuenta el stock del producto
   vinculado, **Then** ese movimiento **no** marca el vínculo como pendiente de sincronizar.
2. **Given** la misma corrida programada, **When** trae órdenes nuevas y luego sincroniza stock,
   **Then** el orden de ejecución garantiza que el stock empujado ya contempla las órdenes recién
   traídas, sin una segunda corrida necesaria para que quede consistente.
3. **Given** un producto vinculado que tuvo tanto una Venta manual como una orden de Mercado Libre entre
   dos corridas, **When** corre la sincronización, **Then** se empuja igual, porque la Venta manual sí
   generó cambios pendientes; el valor enviado es el stock final correcto.

---

### User Story 3 - Forzar la sincronización de stock manualmente (Priority: P2)

Como responsable del negocio, quiero poder forzar el envío del stock actual hacia Mercado Libre sin
esperar al intervalo programado, igual que ya puedo forzar la sincronización de órdenes.

**Why this priority**: da control inmediato en momentos puntuales (por ejemplo, después de un ajuste de
stock grande), pero la sincronización programada de la historia 1 ya entrega el valor central sin esta
acción manual.

**Independent Test**: se puede probar presionando "Sincronizar stock ahora" con vínculos pendientes y
verificando que se envían de inmediato, sin esperar el intervalo configurado.

**Acceptance Scenarios**:

1. **Given** vínculos con cambios pendientes, **When** el usuario presiona "Sincronizar stock ahora",
   **Then** el sistema los envía de inmediato e informa por notificación cuántos se actualizaron, sin
   recargar la página.
2. **Given** el modo sólo lectura activo o la función Mercado Libre desactivada, **When** el usuario
   busca la acción, **Then** no está disponible, con el motivo visible.
3. **Given** una sincronización de stock ya en curso, **When** el usuario dispara otra, **Then** sólo
   una se ejecuta y la otra se descarta, sin enviar el mismo producto dos veces en simultáneo.

---

### User Story 4 - Enterarse cuando Mercado Libre rechaza una actualización (Priority: P2)

Como responsable del negocio, si Mercado Libre rechaza la actualización de stock de una publicación
(porque está pausada, cerrada, o por cualquier otro motivo), quiero verlo señalado con el motivo, sin
que eso bloquee la sincronización del resto de mis productos vinculados.

**Why this priority**: sin visibilidad del rechazo, el CRM cree que el stock está sincronizado cuando
no lo está, reintroduciendo en silencio el mismo riesgo de sobreventa que esta spec busca cerrar.

**Independent Test**: se puede probar vinculando una publicación que luego se pausa en Mercado Libre,
generando un movimiento de stock sobre su producto, sincronizando, y verificando que queda señalada con
el motivo del rechazo mientras el resto de los vínculos se sincroniza con normalidad.

**Acceptance Scenarios**:

1. **Given** una publicación pausada o cerrada en Mercado Libre, **When** su producto vinculado tiene un
   cambio de stock pendiente, **Then** el envío se rechaza, el vínculo queda señalado con el motivo
   concreto, y el resto de los vínculos de esa misma corrida se sincroniza sin verse afectado.
2. **Given** un rechazo por exceso de solicitudes o una falla temporal de red, **When** ocurre, **Then**
   el sistema reintenta con espera creciente antes de marcarlo como error, sin descartar el pendiente.
3. **Given** un vínculo marcado con error, **When** el usuario lo revisa en la pantalla de vinculaciones,
   **Then** ve el motivo del último rechazo y cuándo ocurrió.
4. **Given** un vínculo con error persistente, **When** vuelve a tener un cambio de stock pendiente y
   corre la sincronización, **Then** el sistema lo vuelve a intentar (el error no lo excluye
   permanentemente de futuras corridas).

---

### Edge Cases

- **Stock local negativo** (por ejemplo, tras una orden de Mercado Libre que vendió de más, spec 012
  FR-046d): el sistema **nunca** publica una cantidad negativa; empuja **cero**.
- **Movimiento de stock en un depósito distinto al configurado para Mercado Libre**: no dispara
  sincronización — sólo importa el stock del depósito configurado (ver Clarifications).
- **Vínculo eliminado con un envío pendiente**: el pendiente se descarta; no hay publicación vigente a
  la que empujarle nada.
- **Producto vinculado inactivado en el CRM**: sigue empujando su stock real (que puede ser cero); no
  se pausa ni se cierra la publicación (fuera de alcance).
- **Cambio de depósito configurado para Mercado Libre**: todos los vínculos existentes quedan marcados
  como pendientes, para que la próxima corrida sincronice contra el stock del nuevo depósito.
- **La sincronización de stock se interrumpe a mitad de camino**: los vínculos ya enviados no se
  reenvían de más; los que quedaron pendientes se retoman en la corrida siguiente.
- **Conexión con Mercado Libre caída**: no se ejecuta el envío, igual que la sincronización de órdenes
  (spec 012, FR-018); el pendiente se conserva para cuando se restablezca.
- **Mercado Libre rechaza por exceso de solicitudes**: reintento con espera creciente, reutilizando el
  mismo mecanismo que la spec 012 (FR-020), sin descartar pendientes.

## Requirements *(mandatory)*

### Functional Requirements — Disparo y consolidación

- **FR-001**: El sistema DEBE marcar un vínculo publicación↔producto (`ml_publicacion_producto`) como
  "con cambios pendientes de sincronizar" cada vez que el stock del producto vinculado cambie en el
  depósito configurado para Mercado Libre (`ml_configuracion.deposito_id`, o el depósito por defecto si
  no hay ninguno elegido), sin importar el origen del movimiento (Venta, Compra, ajuste manual,
  transferencia, o cualquier otro movimiento de stock existente en el CRM).
- **FR-002**: El sistema NO DEBE marcar como pendiente un movimiento de stock que se originó en la
  conversión de una orden de Mercado Libre en Venta (spec 012, FR-046), para no generar un envío
  redundante o inconsistente hacia el mismo origen del que vino el dato.
- **FR-003**: El sistema DEBE consolidar todos los cambios pendientes de un mismo producto ocurridos
  entre dos sincronizaciones en un único valor a enviar: el stock actual del producto en el depósito
  configurado en el momento de la corrida, no un acumulado de movimientos. Esto aplica **aunque el valor
  final coincida con el último enviado** (por ejemplo, movimientos que se cancelan entre sí): el sistema
  igual lo envía, en lugar de intentar detectar que "no cambió nada" — es más simple y más seguro que
  arriesgar una divergencia silenciosa por una comparación de más.
- **FR-004**: El sistema DEBE tratar el stock negativo como **cero** al momento de enviarlo a Mercado
  Libre, sin alterar el valor real que se muestra dentro del CRM.
- **FR-005**: El sistema NO DEBE generar cambios pendientes para productos sin vínculo vigente con una
  publicación de Mercado Libre.

### Functional Requirements — Ejecución y concurrencia

- **FR-006**: El sistema DEBE ejecutar la sincronización de stock de forma programada, con la misma
  frecuencia configurable ya existente para las órdenes (`ml_configuracion.frecuencia_sync_minutos`,
  spec 012), como parte de la misma corrida, **después** de traer y procesar las órdenes nuevas.
- **FR-007**: El sistema DEBE ofrecer una acción manual "Sincronizar stock ahora" que envíe de inmediato
  los vínculos con cambios pendientes, informando el resultado por notificación sin recargar la página.
- **FR-008**: El sistema DEBE garantizar que dos sincronizaciones de stock no se ejecuten
  simultáneamente: si una está en curso, la siguiente (programada o manual) se descarta. Este control es
  **independiente** del que ya impide dos sincronizaciones de órdenes simultáneas (spec 012, FR-014): un
  envío de stock en curso no bloquea una sincronización de órdenes, y viceversa — son operaciones
  distintas que sólo deben ejecutarse una a la vez **cada una consigo misma**.
- **FR-009**: El sistema NO DEBE ejecutar la sincronización de stock mientras la función "Mercado Libre"
  esté desactivada o el modo sólo lectura esté activo, dado que es una operación de escritura hacia
  Mercado Libre; DEBE registrar el intento bloqueado en el historial de operaciones existente,
  **conservando los cambios pendientes** para el próximo intento en que ninguno de los dos esté activo
  (mismo tratamiento que FR-010 para la conexión caída — ninguno de los dos cortes debe perder un
  cambio pendiente).
- **FR-010**: El sistema NO DEBE ejecutar la sincronización de stock mientras la conexión con Mercado
  Libre esté caída o no configurada, conservando los cambios pendientes para el próximo intento válido.
- **FR-011**: El sistema DEBE funcionar de forma equivalente en un entorno sin procesos permanentes
  (hosting compartido) y en uno con procesamiento en segundo plano, sin cambios en el código,
  reutilizando el mismo mecanismo de portabilidad ya construido para la sincronización de órdenes
  (spec 012, FR-011).

### Functional Requirements — Envío y manejo de errores

- **FR-012**: El sistema DEBE actualizar, por cada vínculo con cambios pendientes, la cantidad
  disponible de la publicación correspondiente en Mercado Libre con el valor consolidado (FR-003/FR-004).
- **FR-013**: El sistema DEBE aplicar espera creciente ante rechazos por exceso de solicitudes y
  reintentar un número acotado de veces ante fallas temporales, sin descartar el pendiente ni bloquear
  el envío del resto de los vínculos de la misma corrida.
- **FR-014**: El sistema DEBE registrar, por cada vínculo cuyo envío fue rechazado de forma no
  transitoria (publicación pausada, cerrada, inexistente, u otro rechazo definitivo informado por
  Mercado Libre), el motivo concreto y el momento del rechazo, dejando el vínculo con cambios
  pendientes para reintentarlo en la próxima corrida en lugar de descartarlo.
- **FR-015**: El sistema DEBE continuar sincronizando el resto de los vínculos pendientes de una corrida
  aunque uno de ellos sea rechazado.
- **FR-016**: El sistema DEBE registrar cada envío de stock (exitoso, rechazado o bloqueado) en el
  historial de operaciones ya existente (`ml_operaciones_log`, spec 011), como operación de sentido
  "escritura", sin incluir datos sensibles.

### Functional Requirements — Visibilidad

- **FR-017**: El sistema DEBE mostrar, en la pantalla de "Vinculación de publicaciones" (spec 012,
  FR-024), por cada vínculo, su estado de sincronización de stock: sincronizado, con cambios pendientes,
  o con error; la fecha del último envío exitoso; y, cuando el estado sea "con error", el **motivo
  concreto del último rechazo y la fecha en que ocurrió** (mismo nivel de detalle que ya exige FR-007b
  para las órdenes que requieren atención).
- **FR-018**: El sistema DEBE ofrecer la acción "Sincronizar stock ahora" (FR-007) desde la misma
  pantalla de Mercado Libre donde ya existe "Sincronizar ahora" para órdenes (spec 012, FR-009).
- **FR-019**: El sistema DEBE mostrar, en la pantalla de configuración de Mercado Libre, la fecha y el
  resultado de la última sincronización de stock, análogo a lo ya expuesto para la de órdenes.

### Functional Requirements — Retención

- **FR-020**: El sistema NO DEBE requerir una tabla de historial propia para los envíos de stock más
  allá del historial de operaciones ya existente (spec 011): el estado vigente de cada vínculo
  (pendiente/sincronizado/error) es el único dato que se conserva de forma persistente y mutable sobre
  el propio vínculo.

### Key Entities

- **Vinculación publicación ↔ producto** (`ml_publicacion_producto`, ya existente desde la spec 012):
  se le agregan atributos de sincronización de stock — indicador de cambios pendientes, fecha del
  último envío exitoso, motivo del último error (si lo hubo) y fecha de ese error. No es una entidad
  nueva, es una extensión de la ya construida en la 012.
- **Envío de stock**: no es una entidad persistente propia; es la operación (registrada en el historial
  de operaciones ya existente) que consolida y transmite el estado de un vínculo en un momento dado.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Una Venta manual del CRM sobre un producto vinculado se refleja en la cantidad disponible
  de Mercado Libre dentro del intervalo de sincronización configurado, sin ninguna acción manual del
  usuario en Mercado Libre.
- **SC-002**: Ninguna orden de Mercado Libre ya ingresada al CRM (spec 012) genera un envío de stock de
  vuelta hacia Mercado Libre por ese mismo movimiento, verificable revisando que el historial de
  operaciones no registra un envío de escritura asociado a ese movimiento puntual.
- **SC-003**: Ante múltiples movimientos de stock del mismo producto entre dos corridas, Mercado Libre
  recibe exactamente un envío con el valor final, no uno por movimiento.
- **SC-004**: La cantidad disponible publicada en Mercado Libre nunca es negativa, verificable en el
  100% de los casos donde el stock local del CRM cayó por debajo de cero.
- **SC-005**: Con el modo sólo lectura activo o la función Mercado Libre desactivada, cero envíos de
  stock llegan a Mercado Libre, y el 100% de los intentos bloqueados queda registrado en el historial de
  operaciones.
- **SC-006**: El rechazo de una publicación individual (pausada, cerrada) no impide que el resto de los
  vínculos de la misma corrida se sincronice con normalidad.
- **SC-007**: El usuario puede forzar una sincronización de stock manual y ver su resultado, sin
  recargar la página, en menos de un minuto.

## Assumptions

- **Fuente única de stock**: el depósito configurado para Mercado Libre (`ml_configuracion.deposito_id`,
  spec 012) es la única fuente de la cantidad publicada; se documenta como decisión en Clarifications.
- **Sin sincronización en tiempo real estricto**: el envío ocurre en la corrida programada (misma
  cadencia que la de órdenes) o bajo demanda manual, no de forma instantánea al momento exacto del
  movimiento — coherente con la restricción de portabilidad a hosting compartido ya vigente desde la
  spec 011/012.
- **Sin pausado automático de publicaciones**: llegar a cantidad disponible cero alcanza para que
  Mercado Libre deje de vender esa publicación; no se agrega una acción adicional de pausar o cerrar.
- **Una sola cuenta de Mercado Libre**: se mantiene el supuesto single-tenant ya vigente desde la spec
  011.
- **Reintentos acotados**: se reutiliza el mismo criterio de reintento con espera creciente y tope ya
  definido en la spec 012 (FR-020), sin un nuevo mecanismo propio.
- **Las Compras todavía no mueven stock**: es una brecha ya documentada y ajena a esta spec
  (`docs/documentacion_principal_crm.md`, nota de §6.2) — el sistema reacciona a **cualquier** movimiento
  de stock (FR-001), pero hoy sólo las Ventas los generan. El día que Egresos resuelva esa brecha, las
  Compras quedan cubiertas por esta spec sin cambios adicionales.

## Dependencies

- **Interna — spec 012 (implementada)**: tabla `ml_publicacion_producto` (vinculación 1:1), movimientos
  de stock con referencia a la Venta que los originó, depósito configurado
  (`ml_configuracion.deposito_id`), y sincronización programada de órdenes, sobre la que esta spec
  agrega el paso de push de stock.
- **Interna — spec 011 (implementada)**: cliente de API con renovación de credenciales, kill-switch de
  modo sólo lectura, historial de operaciones (`ml_operaciones_log`).
- **Interna — spec 005 (implementada)**: Depósitos.
- **Interna — spec 002 (implementada)**: Productos y su stock.
- **Externa**: permisos funcionales de "Publicación y sincronización" ya habilitados en la aplicación
  del DevCenter de Mercado Libre (`MERCADOLIBRE_NOTAS_TECNICAS.md` §2), necesarios para escribir
  cantidad disponible sobre una publicación.

## Restricciones de diseño y entorno

- **Especificaciones de diseño obligatorias del proyecto** (`CLAUDE.md`): el estado de sincronización de
  stock se muestra dentro de la tabla ya existente de vinculaciones (DataTables, carga por demanda);
  "Sincronizar stock ahora" usa el mismo patrón AJAX sin recarga de página y notificaciones toast que
  "Sincronizar ahora" de órdenes (spec 012).
- **Portabilidad de entorno**: igual que la spec 012 — mismo código en hosting compartido y en servidor
  dedicado.
- **Idioma del dominio**: nombres de columnas, rutas y textos de interfaz en español.
- **Secretos**: ninguna credencial se registra en logs; el historial de operaciones no debe contener
  datos sensibles (igual que specs 011/012).
- **Testing**: por el principio IV de la constitución, la exclusión de movimientos originados en
  Mercado Libre (FR-002), la consolidación de cambios pendientes (FR-003), el tope en cero (FR-004) y la
  no concurrencia (FR-008) requieren tests obligatorios.

## Impacto en la documentación de dominio

Conforme al principio I de la constitución, esta spec introduce contenido que debe reflejarse en la
documentación de dominio **antes de pasar a `/speckit-tasks`**:

1. `docs/documentacion_principal_crm.md`:
   - Actualizar §3.6 y §5.2 para reflejar que el riesgo de sobreventa documentado queda cerrado por esta
     spec, describiendo el sentido CRM → Mercado Libre y su cadencia.
2. `docs/modelo_datos.md`:
   - Ampliar `ml_publicacion_producto` (§10) con los atributos nuevos de sincronización de stock
     (pendiente, último envío exitoso, motivo y fecha del último error).
