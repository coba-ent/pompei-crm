# Feature Specification: Evitar ventas duplicadas por reconversión de órdenes de Mercado Libre y Tiendanube

**Feature Branch**: `038-evitar-ventas-duplicadas`

**Created**: 2026-08-03

**Status**: Draft

**Input**: User description: "Evitar ventas duplicadas por reconversión de órdenes de Mercado Libre y Tiendanube. Actualmente la Venta no guarda ninguna referencia al pedido de origen (ml_order_id / tn_order_id): el único vínculo vive en ml_ordenes.venta_id / tn_ordenes.venta_id (sentido orden→venta). Si esa fila de orden se borra (por error o intencionalmente) y la orden vuelve a sincronizarse desde Mercado Libre/Tiendanube, el sistema la trata como orden nueva (sin venta_id) y permite convertirla de nuevo, generando una Venta duplicada (con doble cobro en Tesorería y doble movimiento de stock) para el mismo pedido real. Se requieren dos correcciones, aplicadas por igual a Mercado Libre y a Tiendanube: (1) Red de seguridad en la Venta: agregar a `ventas` una referencia al pedido de origen, única por origen, para que el conversor pueda rechazar la conversión si ya existe una Venta con esa referencia — incluso si la fila de la orden fue borrada y recreada desde cero por una resincronización. (2) Bloqueo de borrado: no permitir eliminar una fila de ml_ordenes o tn_ordenes cuyo venta_id no sea null — hay que desvincular/eliminar la Venta asociada primero."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - La conversión rechaza un pedido que ya generó una Venta, aunque la orden se haya borrado y resincronizado (Priority: P1)

Un usuario administra las órdenes sincronizadas de Mercado Libre o Tiendanube. Una orden ya convertida en Venta se borra de la vista de órdenes (por accidente, o como limpieza de datos de prueba) y luego el sistema vuelve a traerla desde la sincronización automática, apareciendo como una orden "nueva" sin Venta asociada. El usuario (o la creación automática) intenta convertirla en Venta. El sistema detecta que el pedido de origen ya generó una Venta anteriormente y rechaza la conversión, evitando el cobro y el movimiento de stock duplicados.

**Why this priority**: Es la protección central del feature — sin ella, cualquier borrado accidental de una orden ya convertida deriva en una venta fantasma duplicada, con impacto directo en Tesorería (cobro doble) y stock (descuento doble). Es la única forma de blindar el caso incluso cuando la protección de borrado (User Story 2) fue sorteada o no existía todavía sobre datos históricos.

**Independent Test**: Con una Venta existente originada en un pedido de Mercado Libre (o Tiendanube) con número de pedido conocido, se intenta crear/convertir manualmente otra orden con el mismo número de pedido de origen. La conversión debe rechazarse con un mensaje explicando que ese pedido ya tiene una Venta asociada, sin crear una segunda Venta ni afectar Tesorería/stock.

**Acceptance Scenarios**:

1. **Given** una Venta con origen Mercado Libre asociada al pedido "2000017623055904", **When** se borra la fila de la orden en la vista de órdenes de Mercado Libre y la sincronización vuelve a traer ese mismo pedido como orden nueva, lista para convertir, **Then** al intentar convertirla (manual o automáticamente) el sistema la rechaza indicando que el pedido ya tiene una Venta asociada, y no se crea una Venta nueva.
2. **Given** el mismo escenario para un pedido de Tiendanube, **When** se repite la secuencia de borrado + resincronización + intento de conversión, **Then** el comportamiento es idéntico: se rechaza la conversión duplicada.
3. **Given** un pedido de Mercado Libre o Tiendanube que nunca generó una Venta, **When** se convierte por primera vez, **Then** la conversión se realiza con normalidad y la Venta queda con la referencia al pedido de origen guardada.

---

### User Story 2 - El sistema impide borrar una orden que ya tiene una Venta asociada (Priority: P2)

Un usuario intenta eliminar una orden de Mercado Libre o Tiendanube desde la vista de órdenes (o mediante una operación de mantenimiento sobre la base de órdenes). Si esa orden ya generó una Venta, el sistema impide el borrado y explica que primero hay que desvincular o eliminar la Venta asociada.

**Why this priority**: Complementa a la User Story 1 cerrando el camino más común hacia el escenario problemático (el borrado accidental de una orden convertida) antes de que ocurra, en vez de sólo detectar el duplicado después. Es de prioridad P2 porque la User Story 1 ya garantiza que, aunque este bloqueo se sortee, no se genera una Venta duplicada.

**Independent Test**: Con una orden de Mercado Libre o Tiendanube que tiene una Venta asociada, se intenta borrarla desde la vista correspondiente. La operación debe rechazarse con un mensaje claro. Con una orden sin Venta asociada, el borrado debe funcionar sin cambios respecto del comportamiento actual.

**Acceptance Scenarios**:

1. **Given** una orden de Mercado Libre con una Venta asociada, **When** un usuario intenta eliminarla por cualquier vía de borrado disponible en el sistema (hoy: operaciones de mantenimiento; a futuro, si se agrega un botón de borrado en la vista de órdenes, también por ahí), **Then** el sistema rechaza el borrado y muestra un mensaje indicando que la orden tiene una Venta asociada y debe desvincularse/eliminarse primero.
2. **Given** una orden de Tiendanube con una Venta asociada, **When** se intenta el mismo borrado, **Then** el comportamiento es idéntico al de Mercado Libre.
3. **Given** una orden de Mercado Libre o Tiendanube sin Venta asociada, **When** un usuario la elimina, **Then** el borrado se realiza sin restricciones, igual que hoy.

> Nota: hoy no existe un botón de borrado en la vista de órdenes de Mercado Libre/Tiendanube (se comprobó que no hay ruta HTTP `destroy` registrada para `ml_ordenes`/`tn_ordenes`); el único camino de borrado real es una operación de mantenimiento (por ejemplo, `tinker` o un script). El guard se implementa a nivel de modelo para que valga hoy contra ese camino y, sin cambios adicionales, contra un futuro botón de borrado en la UI.

---

### Edge Cases

- ¿Qué pasa si dos conversiones del mismo pedido (misma orden, no reconstruida) se disparan casi al mismo tiempo? Ya está cubierto por el candado por orden existente; esta feature agrega una segunda capa que además cubre el caso de la orden reconstruida desde cero.
- ¿Qué pasa con las Ventas ya existentes al momento de desplegar esta corrección, que se originaron en Mercado Libre/Tiendanube pero no tienen la nueva referencia al pedido de origen guardada? Deben completarse (backfill) a partir del vínculo actual `orden.venta_id`, para que la red de seguridad funcione también sobre datos históricos.
- ¿Qué pasa si la Venta asociada a una orden se elimina (soft delete) sin desvincular la orden? La referencia guardada en la Venta debe seguir bloqueando una reconversión del mismo pedido, salvo que un usuario decida explícitamente habilitar una reconversión (fuera de alcance de esta feature: no se define un flujo de "reconvertir a propósito").
- ¿Aplica el bloqueo de borrado a una eliminación masiva o de mantenimiento (no sólo la acción individual de la UI)? Sí, el bloqueo debe valer para cualquier camino de borrado, no sólo el botón individual.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: El sistema MUST guardar en cada Venta originada en Mercado Libre una referencia al pedido de origen (identificador del pedido de Mercado Libre) al momento de la conversión.
- **FR-002**: El sistema MUST guardar en cada Venta originada en Tiendanube una referencia al pedido de origen (identificador del pedido de Tiendanube) al momento de la conversión.
- **FR-003**: El sistema MUST impedir que exista más de una Venta con la misma referencia de pedido de origen para el mismo canal (Mercado Libre o Tiendanube), incluyendo Ventas eliminadas lógicamente (soft delete): una Venta borrada sigue "ocupando" su referencia de pedido de origen, consistente con el Edge Case sobre Ventas eliminadas sin desvincular la orden.
- **FR-004**: Antes de convertir una orden de Mercado Libre o Tiendanube en Venta, el sistema MUST verificar si ya existe una Venta con la referencia de ese mismo pedido de origen, y en ese caso rechazar la conversión (manual o automática) sin crear una Venta nueva ni afectar Tesorería o stock.
- **FR-005**: El mensaje de rechazo de una conversión duplicada MUST indicar explícitamente que el pedido ya tiene una Venta asociada, de forma consistente con el mensaje ya usado cuando la orden vigente tiene `venta_id` cargado.
- **FR-006**: El sistema MUST impedir eliminar una orden de Mercado Libre (`ml_ordenes`) cuando tiene una Venta asociada (`venta_id` no nulo), sin importar si la eliminación se dispara desde la vista de órdenes o desde una operación de mantenimiento/limpieza sobre esa tabla.
- **FR-007**: El sistema MUST impedir eliminar una orden de Tiendanube (`tn_ordenes`) cuando tiene una Venta asociada (`venta_id` no nulo), con el mismo alcance que FR-006.
- **FR-008**: Cuando el borrado de una orden con Venta asociada se rechaza, el sistema MUST comunicar al usuario que debe desvincular o eliminar la Venta asociada antes de poder eliminar la orden.
- **FR-009**: El sistema MUST permitir seguir eliminando sin restricciones una orden de Mercado Libre o Tiendanube que no tiene Venta asociada, igual que el comportamiento actual.
- **FR-010**: El sistema MUST completar (backfill) la referencia al pedido de origen en las Ventas ya existentes que se originaron en Mercado Libre o Tiendanube y cuya orden vinculada sigue identificable, para que la protección de FR-003/FR-004 también cubra datos históricos.

### Key Entities

- **Venta**: documento de venta del CRM. Incorpora una referencia al pedido de origen de Mercado Libre y otra al pedido de origen de Tiendanube (una de las dos, según el `origen` de la Venta), usada para detectar reconversiones duplicadas del mismo pedido.
- **Orden de Mercado Libre / Orden de Tiendanube**: pedido sincronizado desde el canal de venta externo. Mantiene su vínculo existente hacia la Venta que generó (cuando aplica); ahora ese vínculo también se protege contra el borrado mientras exista.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Ante un pedido de Mercado Libre o Tiendanube ya convertido cuya orden fue borrada y resincronizada, el 100% de los intentos de reconversión (manual o automática) son rechazados sin generar una segunda Venta.
- **SC-002**: El 100% de los intentos de borrado de una orden de Mercado Libre o Tiendanube con Venta asociada son rechazados, en cualquier vía de borrado disponible.
- **SC-003**: Cero Ventas duplicadas (mismo pedido de origen, mismo canal) detectadas después de desplegada la corrección, incluyendo sobre las Ventas históricas ya migradas con la referencia completada.

## Assumptions

- La referencia al pedido de origen que se guarda en la Venta es el identificador del pedido tal como lo entrega cada canal (`ml_order_id` de Mercado Libre, `tn_order_id` de Tiendanube), consistente con el identificador ya usado hoy en `ml_ordenes`/`tn_ordenes`.
- El backfill de Ventas históricas (FR-010) se resuelve a partir del vínculo `orden.venta_id` existente hoy; las Ventas de Mercado Libre/Tiendanube cuya orden de origen ya fue borrada antes de esta feature (por ejemplo, las 7 órdenes de prueba eliminadas el 03/08/2026) no tienen forma de recuperar esa referencia y quedan fuera del backfill — no representan riesgo porque su orden de origen (cuenta de test) no puede volver a sincronizar.
- "Desvincular la Venta asociada" (FR-008) se resuelve reutilizando el flujo de gestión de Ventas ya existente para borrar/anular una Venta; esta feature no define una pantalla nueva para ese paso, sólo el bloqueo del lado de la orden.
- No se define en esta feature un flujo explícito para "reconvertir a propósito" un pedido cuya Venta fue eliminada (por ejemplo, tras anular una Venta por error) — queda fuera de alcance y puede tratarse como una feature separada si se necesita.
