# Feature Specification: Vinculación múltiple Producto ↔ Publicaciones (Mercado Libre y Tiendanube)

**Feature Branch**: `036-vinculacion-multiple-ml`

**Created**: 2026-08-03

**Status**: Draft

**Input**: User description: "Permitir que una publicación de Mercado Libre y otras publicaciones adicionales del mismo vendedor se vinculen al mismo Producto del CRM (modelo 1 Producto ↔ N publicaciones de ML, en vez del actual 1↔1), para que el stock y el precio de ESE producto se sincronicen automáticamente hacia TODAS sus publicaciones vinculadas en Mercado Libre, no sólo hacia una."

## Clarifications

### Session 2026-08-03

- Q: ¿El alcance es sólo Mercado Libre, o también Tiendanube (que tiene el mismo esquema 1:1 hoy)? → A: Ambos. Se confirmó que Tiendanube tiene exactamente el mismo bug estructural (mismo `unique()` en `producto_id` en `tn_variante_producto`, mismo patrón `->first()` en `MovimientoStockObserver`/`PrecioProductoObserver`, mismo motivo de rechazo `ya_vinculado` en `Tiendanube\VinculadorAutomatico`) — se amplía el alcance para cubrir las dos integraciones con el mismo cambio, evitando dejarlas inconsistentes entre sí.
- Q: ¿La Vinculación Automática debe vincular todas las publicaciones/variantes que correspondan al mismo SKU sin pedir confirmación manual, o sólo la primera y el resto a mano? → A: Automática, sin confirmación manual — si el SKU resuelve al mismo Producto de forma inequívoca, no hay razón para pedir revisión caso por caso.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Vinculación automática crea todos los vínculos correspondientes (Priority: P1)

Como usuario que corre la Vinculación Automática de Mercado Libre o de Tiendanube, cuando el catálogo real del vendedor tiene varias publicaciones (ML) o variantes (Tiendanube) activas para el mismo producto físico (mismo SKU), quiero que TODAS queden vinculadas al Producto correspondiente del CRM, no sólo la primera que el sistema encuentre.

**Why this priority**: Es la causa raíz detectada hoy: 72 productos del catálogo real de Mercado Libre (POMPEISANITARIOS) tienen 2 o más publicaciones activas con el mismo SKU, y se detectó al menos 1 caso equivalente en Tiendanube (una variante con SKU que resuelve a un producto ya vinculado por otra variante). Con el comportamiento actual, sólo una queda vinculada y las demás nunca reciben actualizaciones de stock — riesgo real de sobreventa (una publicación/variante puede seguir mostrando disponibilidad después de que el producto se vendió y quedó en 0).

**Independent Test**: Correr la Vinculación Automática (de cada integración) contra un catálogo de prueba con 2 publicaciones/variantes activas distintas que comparten el mismo SKU numérico correspondiente a un Producto existente; verificar que ambas quedan registradas como vínculos, sin que ninguna se reporte como fallida por "ya vinculado".

**Acceptance Scenarios**:

1. **Given** dos publicaciones/variantes activas con el mismo SKU, correspondiente a un Producto existente y sin vínculo previo, **When** se ejecuta la Vinculación Automática de esa integración, **Then** ambas quedan vinculadas a ese mismo Producto, y el resultado las cuenta como "vinculadas" (no como fallidas).
2. **Given** un Producto ya tiene una publicación/variante vinculada, y aparece una publicación/variante NUEVA activa con el mismo SKU, **When** se ejecuta la Vinculación Automática, **Then** la nueva se vincula también a ese Producto sin afectar el vínculo existente.
3. **Given** una publicación/variante sin SKU cargado, con SKU que no corresponde a ningún Producto, o excluida por las reglas propias de cada integración (ML: `status=closed` o con variantes de ML; Tiendanube: producto `status=closed`), **When** se ejecuta la Vinculación Automática, **Then** se sigue rechazando exactamente como hoy (sin cambios en esas exclusiones).

---

### User Story 2 - El stock de un Producto se sincroniza a todas sus publicaciones vinculadas (Priority: P1)

Como usuario que gestiona stock desde el CRM, cuando el stock de un Producto cambia (por una venta, un ajuste, una compra), quiero que TODAS las publicaciones/variantes vinculadas a ese Producto se actualicen en Mercado Libre y/o Tiendanube, no sólo una.

**Why this priority**: Es el problema de negocio concreto que motiva la spec — sin esto, vender el último de un producto no impide que sus otras publicaciones/variantes dupliquen la venta en el marketplace.

**Independent Test**: Vincular un Producto a 2 publicaciones/variantes (de la misma integración), registrar un movimiento de stock que lo deje en 0, correr la sincronización de stock, y verificar (vía log/estado de cada vínculo) que ambas fueron marcadas para actualizar y ambas reciben la cantidad correcta.

**Acceptance Scenarios**:

1. **Given** un Producto vinculado a 2 publicaciones/variantes, **When** se registra un movimiento que cambia su stock disponible, **Then** ambas quedan marcadas como pendientes de sincronizar stock.
2. **Given** ambas pendientes, **When** corre la sincronización de stock (manual o por cron) de la integración correspondiente, **Then** las dos reciben la misma cantidad disponible actualizada, y ambas quedan registradas como sincronizadas.
3. **Given** una de las publicaciones/variantes vinculadas fue desvinculada, **When** el stock del Producto cambia después, **Then** sólo las que siguen vinculadas se actualizan; la desvinculada no se ve afectada.
4. **Given** un Producto vinculado simultáneamente a publicaciones de Mercado Libre Y variantes de Tiendanube, **When** su stock cambia, **Then** se actualizan todas las publicaciones/variantes de ambas integraciones (cada una vía su propio sincronizador existente).

---

### User Story 3 - El precio de un Producto se sincroniza a todas sus publicaciones vinculadas (Priority: P2)

Como usuario que gestiona precios desde el CRM, cuando el precio de un Producto cambia, quiero que todas sus publicaciones/variantes vinculadas en Mercado Libre y Tiendanube reciban el precio actualizado.

**Why this priority**: Mismo problema estructural que el stock (misma limitación técnica de origen, mismos observers compartidos), pero de impacto secundario respecto a la sobreventa — se prioriza después de resolver stock.

**Independent Test**: Vincular un Producto a 2 publicaciones/variantes, cambiar su precio, y verificar que ambas quedan marcadas/actualizadas con el nuevo precio.

**Acceptance Scenarios**:

1. **Given** un Producto vinculado a 2 publicaciones/variantes (de la misma o de ambas integraciones), **When** cambia su precio, **Then** todas quedan marcadas como pendientes de sincronizar precio y luego actualizadas con el mismo valor.

---

### Edge Cases

- ¿Qué pasa si la misma publicación/variante aparece dos veces en el resultado de búsqueda del catálogo dentro de la misma corrida de Vinculación Automática? No debe crear vínculos duplicados para la misma publicación/variante (la unicidad de `ml_item_id` / `variant_id` por vínculo se mantiene sin cambios).
- ¿Qué pasa si se intenta vincular manualmente una publicación/variante que ya está vinculada a OTRO producto (no al mismo)? Debe seguir rechazándose — el conflicto real (publicación/variante ya usada en otro producto) no cambia; lo que deja de ser conflicto es que el PRODUCTO ya tenga otras publicaciones/variantes.
- ¿Qué pasa si se desvincula la única publicación/variante restante de un producto? El producto queda sin ningún vínculo, como hoy — no es un caso especial nuevo.
- ¿Qué pasa si al sincronizar stock/precio una de las varias publicaciones/variantes vinculadas falla (ej. la API rechaza esa actualización puntual) mientras las demás tienen éxito? Cada una se procesa y registra de forma independiente — el fallo de una no debe impedir que las demás se actualicen (mismo criterio "no cortar el resto de la corrida" que ya aplican los sincronizadores existentes de ambas integraciones).
- ¿Un mismo Producto puede tener vínculos simultáneos en Mercado Libre Y en Tiendanube? Sí, eso ya es así hoy (son tablas de vínculo independientes, `ml_publicacion_producto` y `tn_variante_producto`) y no cambia — cada integración sincroniza sus propios vínculos de forma independiente. Esto no contradice la asunción de que "esta spec no unifica ni acopla la sincronización de ML con la de Tiendanube": esa asunción se refiere a que un sincronizador no dispara al otro (siguen siendo dos corridas independientes), no a que un Producto no pueda tener vínculos en ambas al mismo tiempo — ambas cosas son ciertas simultáneamente y no son contradictorias.
- ¿Qué pasa si una publicación/variante activa resuelve (por SKU) a un Producto que fue eliminado (soft-delete) del CRM? La Vinculación Automática NO debe crear el vínculo — se trata igual que "SKU que no corresponde a ningún Producto" (FR-004/FR-014), ya que las consultas de resolución de SKU ya excluyen productos soft-deleted (comportamiento actual sin cambios, `Producto` usa `SoftDeletes` y las queries por defecto ya excluyen los eliminados).
- ¿Qué pasa si la sincronización de stock/precio corre para un Producto con CERO vínculos (todos desvinculados)? El `foreach` sobre la colección de vínculos (R3) simplemente no itera nada — no es un error, no se marca nada pendiente, no se envía ninguna llamada a la API. Comportamiento normal, no requiere manejo especial.
- ¿Qué pasa si, al sincronizar, uno de los vínculos de un Producto apunta a una publicación/variante que ya fue cerrada/eliminada del lado de Mercado Libre o Tiendanube (no del CRM)? La llamada a la API de esa integración devuelve error/rechazo para ESE vínculo puntual; se registra igual que cualquier otro fallo de sincronización individual (mismo criterio de FR-007/FR-008/FR-017/FR-018: no corta el resto de la corrida). No se desvincula automáticamente ni se reintenta con lógica especial — eso queda fuera de alcance de esta spec (es el comportamiento de manejo de errores ya existente en cada sincronizador, sin cambios).
- ¿Qué pasa si dos ejecuciones simultáneas de la Vinculación Automática (ej. cron + click manual) intentan vincular el mismo SKU al mismo tiempo? El único punto de conflicto real es la unicidad por publicación/variante (FR-002/FR-012, `ml_item_id`/`variant_id` únicos), que ya está protegida hoy por la restricción `unique()` a nivel de base de datos — una de las dos ejecuciones concurrentes fallará por violación de constraint al intentar insertar el mismo `ml_item_id`/`variant_id` dos veces, y ese fallo se trata como cualquier otro error individual de vinculación (no aborta el resto de la corrida). No se requiere un lock explícito nuevo: la restricción de unicidad existente en la base de datos es suficiente para evitar duplicados, y no es un caso especial introducido por esta spec (ya existía con cardinalidad 1:1).

## Requirements *(mandatory)*

### Functional Requirements

**Mercado Libre**

- **FR-001**: El sistema DEBE permitir que más de una publicación de Mercado Libre esté vinculada al mismo Producto del CRM simultáneamente.
- **FR-002**: El sistema DEBE seguir impidiendo que una misma publicación de Mercado Libre esté vinculada a más de un Producto (unicidad por publicación, sin cambios respecto al comportamiento actual).
- **FR-003**: La Vinculación Automática de Mercado Libre DEBE crear un vínculo por cada publicación activa que resuelva al mismo Producto vía SKU, sin pedir confirmación manual caso por caso y sin contarlas como fallidas por el motivo "el producto ya está vinculado a otra publicación".
- **FR-004**: La Vinculación Automática de Mercado Libre DEBE seguir rechazando (sin cambios) publicaciones sin SKU, con SKU que no corresponde a ningún Producto, cerradas (`status=closed`), o con variantes de ML.
- **FR-005**: Cuando el stock disponible de un Producto cambia, el sistema DEBE marcar como pendientes de sincronización TODAS las publicaciones de Mercado Libre actualmente vinculadas a ese Producto (no sólo una).
- **FR-006**: Cuando el precio de un Producto cambia, el sistema DEBE marcar como pendientes de sincronización TODAS las publicaciones de Mercado Libre actualmente vinculadas a ese Producto (no sólo una).
- **FR-007**: La sincronización de stock hacia Mercado Libre DEBE enviar la misma cantidad disponible del Producto a cada una de sus publicaciones vinculadas, de forma independiente: el envío a cada publicación es una llamada HTTP separada, y si una de esas llamadas falla (error de red, rechazo de la API), las llamadas correspondientes a las demás publicaciones vinculadas se realizan igual — el fallo de una no aborta ni revierte el envío a las demás, ni interrumpe el resto de la corrida de sincronización.
- **FR-008**: La sincronización de precio hacia Mercado Libre DEBE enviar el mismo precio del Producto a cada una de sus publicaciones vinculadas, de forma independiente (mismo criterio de no-corte que FR-007: llamadas HTTP separadas, sin aborto ni reversión entre ellas).

**Tiendanube**

- **FR-011**: El sistema DEBE permitir que más de una variante de Tiendanube esté vinculada al mismo Producto del CRM simultáneamente.
- **FR-012**: El sistema DEBE seguir impidiendo que una misma variante de Tiendanube esté vinculada a más de un Producto (unicidad por variante, sin cambios respecto al comportamiento actual).
- **FR-013**: La Vinculación Automática de Tiendanube DEBE crear un vínculo por cada variante activa que resuelva al mismo Producto vía SKU, sin pedir confirmación manual caso por caso y sin contarla como fallida por el motivo "el producto ya está vinculado a otra publicación".
- **FR-014**: La Vinculación Automática de Tiendanube DEBE seguir rechazando (sin cambios) variantes sin SKU, con SKU que no corresponde a ningún Producto, o pertenecientes a un producto `status=closed`.
- **FR-015**: Cuando el stock disponible de un Producto cambia, el sistema DEBE marcar como pendientes de sincronización TODAS las variantes de Tiendanube actualmente vinculadas a ese Producto (no sólo una).
- **FR-016**: Cuando el precio de un Producto cambia, el sistema DEBE marcar como pendientes de sincronización TODAS las variantes de Tiendanube actualmente vinculadas a ese Producto (no sólo una).
- **FR-017**: La sincronización de stock hacia Tiendanube DEBE enviar la misma cantidad disponible del Producto a cada una de sus variantes vinculadas, de forma independiente (mismo criterio que FR-007: llamadas HTTP separadas, sin aborto ni reversión entre ellas).
- **FR-018**: La sincronización de precio hacia Tiendanube DEBE enviar el mismo precio del Producto a cada una de sus variantes vinculadas, de forma independiente (mismo criterio que FR-007/FR-017).

**Comunes a ambas integraciones**

- **FR-009**: El sistema DEBE permitir desvincular una publicación/variante de un Producto sin afectar las demás publicaciones/variantes vinculadas a ese mismo Producto (de la misma o de la otra integración).
- **FR-010**: Cualquier pantalla del CRM que liste las publicaciones/variantes vinculadas a un Producto (o el producto vinculado a una publicación/variante) DEBE reflejar correctamente la relación 1 a muchos (mostrar todas las vinculadas a un Producto, no asumir una sola), tanto para Mercado Libre como para Tiendanube.

### Key Entities *(include if feature involves data)*

- **Vínculo Producto↔Publicación ML**: relación entre un Producto del CRM y una publicación de Mercado Libre (identificada por su `item_id`). Pasa de ser 1:1 (un producto tiene a lo sumo un vínculo) a 1:N (un producto puede tener varios vínculos, cada uno a una publicación distinta). Cada publicación sigue perteneciendo a un único Producto.
- **Vínculo Producto↔Variante Tiendanube**: relación equivalente entre un Producto del CRM y una variante de Tiendanube (identificada por su `variant_id`). Mismo cambio 1:1 → 1:N. Cada variante sigue perteneciendo a un único Producto.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Al correr la Vinculación Automática de Mercado Libre sobre el catálogo real, el 100% de las publicaciones activas con SKU válido correspondiente a un Producto existente quedan vinculadas, incluyendo los casos donde varias publicaciones comparten el mismo SKU (hoy: 96 publicaciones sin vincular por este motivo sobre 273 pendientes; el objetivo es que ese motivo de rechazo deje de producirse).
- **SC-002**: Al correr la Vinculación Automática de Tiendanube sobre el catálogo real, el 100% de las variantes activas con SKU válido correspondiente a un Producto existente quedan vinculadas, incluyendo los casos donde varias variantes comparten el mismo SKU.
- **SC-003**: Una venta que deja el stock de un Producto en 0 se refleja como 0 unidades disponibles en el 100% de sus publicaciones/variantes vinculadas (Mercado Libre y Tiendanube), sin intervención manual.
- **SC-004**: Un cambio de precio de un Producto se refleja en el 100% de sus publicaciones/variantes vinculadas (Mercado Libre y Tiendanube), sin intervención manual.
- **SC-005**: Ninguna publicación/variante queda vinculada a más de un Producto, ni un Producto puede "robar" el vínculo de otro (se preserva la integridad 1 publicación/variante → 1 producto) en ninguna de las dos integraciones. Esto queda garantizado a nivel de datos, no sólo de comportamiento de código, por FR-002/FR-012 (unicidad de `ml_item_id`/`variant_id` mantenida sin cambios como restricción única en base de datos — ver research.md R1) — es decir, ni siquiera un bug en la lógica de la aplicación podría violar SC-005, porque la base de datos lo rechaza.
- **SC-006**: El criterio "sin pedir confirmación manual caso por caso" (FR-003/FR-013) es verificable de forma objetiva: en una corrida de prueba con N publicaciones/variantes activas con SKU válido compartiendo el mismo Producto, la Vinculación Automática crea exactamente N vínculos sin ninguna interacción del usuario durante la corrida (sin prompts, sin pausas), y el resultado reporta las N como "vinculadas".

## Assumptions

- El SKU cargado en cada publicación de Mercado Libre / variante de Tiendanube sigue siendo la única señal usada para resolver a qué Producto corresponde (sin cambios en la lógica de resolución de SKU de ninguno de los dos `VinculadorAutomatico`, más allá de dejar de rechazar duplicados por producto).
- No se requiere una migración de datos que reconstruya vínculos históricos retroactivamente más allá de correr la Vinculación Automática de nuevo — los casos detectados hoy (72 en ML, 1+ en Tiendanube) se resuelven ejecutando el flujo existente una vez desplegado el cambio.
- La UI existente que hoy asume "una publicación/variante por producto" (si la hubiera) se ajusta para listar múltiples sin rediseñar la pantalla completa — el alcance visual se limita a reflejar correctamente la relación, no a un rediseño de las secciones de vinculación.
- Ambas integraciones mantienen sincronizadores de stock/precio separados e independientes entre sí (como hoy); esta spec no unifica ni acopla la sincronización de Mercado Libre con la de Tiendanube, sólo corrige el 1:N dentro de cada una. (Esto es compatible con que un mismo Producto tenga vínculos en ambas integraciones a la vez — ver Edge Cases.)
- No se establece un límite máximo a la cantidad de publicaciones/variantes que pueden vincularse a un mismo Producto. Se asume explícitamente que no hace falta uno para el volumen actual del catálogo (decenas de publicaciones por producto como máximo, no miles) — de aparecer un caso patológico en el futuro, se evalúa como spec aparte.
- Esta spec depende de y no debe romper el comportamiento ya construido en las specs 012 (vinculación ML), 013 (sincronización stock ML), 016/017/018 (integración Tiendanube), 021/023/024 (sincronizadores Tiendanube) y 035 (sincronización forzada y eliminación masiva de vínculos) — en particular, los criterios de exclusión de la Vinculación Automática (SKU inválido, `closed`, variantes de ML) y el comportamiento de `pendientes()`/reintentos de los sincronizadores existentes se mantienen sin cambios, sólo se corrige la cardinalidad.
