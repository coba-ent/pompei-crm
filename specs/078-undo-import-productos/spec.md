# Feature Specification: Deshacer Import de Productos

**Feature Branch**: `078-undo-import-productos`

**Created**: 2026-08-24

**Status**: Draft

**Input**: User description: "Permitir deshacer (rollback) un import de Productos & Servicios (Paso 3 del asistente de Importar Datos, §2.4) dentro de una ventana de tiempo limitada. El import de Productos toca precios y stock que se están vendiendo en vivo. Snapshot del estado previo de cada fila tocada antes de confirmar el import; registro de import (usuario, archivo, conteos); acción 'Deshacer import' con ventana de tiempo; altas se eliminan (soft-delete) salvo que ya tengan operaciones posteriores; actualizaciones se restauran fila por fila respetando StockService::fijar (spec 074); todo el undo queda auditado. Alcance: sólo Productos & Servicios, no Clientes ni Proveedores."

## Clarifications

### Session 2026-08-24

- Q: ¿Cuánto debe durar la ventana de tiempo para poder deshacer una corrida de import? → A: 48 horas desde la confirmación (default razonable para "corregir un error detectado poco después"; sin restricción explícita del usuario, se mantiene el valor ya documentado en Assumptions, ajustable si se pide otro).
- Q: ¿Qué universo de "operaciones posteriores" bloquea la reversión de una fila? → A: ventas, compras, NC/ND, remitos, ajustes/transferencias de stock, y sincronizaciones ML/Tiendanube que hayan escrito sobre el producto (ya definido en Assumptions/Edge Cases; se confirma como alcance definitivo, no parcial).
- Q: Cuando dos corridas de import vigentes tocaron el mismo producto, ¿en qué orden se pueden deshacer? → A: sólo en orden inverso (más reciente primero); deshacer la más antigua primero deja esa fila sin revertir y reportada con motivo (FR-016), sin bloquear el resto del undo.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Deshacer un import recién hecho por error (Priority: P1)

Un usuario importa una planilla de Productos & Servicios (Paso 3 del asistente) y, al revisar el resumen o el listado de Productos, se da cuenta de que mapeó una columna equivocada (por ejemplo, "Costo" quedó mapeada a "Precio de Venta") y cientos de productos quedaron con precios o stock incorrectos, ya visibles para la venta. Necesita revertir esa corrida completa a su estado anterior sin tener que corregir fila por fila a mano ni pedir ayuda técnica.

**Why this priority**: Es el corazón de la feature — sin esto no hay forma de recuperarse de un import erróneo, que es exactamente el riesgo que la motiva. Un precio de venta mal cargado que queda expuesto en el catálogo en vivo es el escenario de mayor impacto de negocio.

**Independent Test**: Se puede probar de forma completa importando una planilla de prueba que actualiza productos existentes con valores distintos, confirmando el import, y accionando "Deshacer" — se verifica que los productos vuelven a sus valores previos exactos.

**Acceptance Scenarios**:

1. **Given** un import de Productos & Servicios recién confirmado (dentro de la ventana de tiempo permitida) que actualizó 50 productos existentes, **When** el usuario acciona "Deshacer import" sobre esa corrida, **Then** los 50 productos vuelven a los valores que tenían antes del import (precio, costo, stock por depósito, categoría, proveedor, etc.) y el sistema confirma cuántas filas se revirtieron.
2. **Given** un import que además dio de alta 10 productos nuevos, **When** se deshace esa corrida, **Then** los 10 productos dados de alta quedan inactivos/eliminados (soft-delete) y ya no aparecen en el listado activo de Productos.
3. **Given** una corrida de import ya deshecha, **When** el usuario vuelve a la pantalla de esa corrida, **Then** la acción "Deshacer" ya no está disponible y se indica que la corrida fue revertida (con fecha/usuario que la revirtió).

---

### User Story 2 - Deshacer parcialmente cuando algunas filas ya no se pueden revertir (Priority: P2)

Después de un import, algunos de los productos tocados por esa corrida ya tuvieron ventas o ajustes de stock posteriores (el negocio no se detiene mientras el usuario decide si deshacer). El usuario igual quiere deshacer todo lo que se pueda, y necesita saber con claridad qué filas no se pudieron revertir y por qué, para corregirlas a mano.

**Why this priority**: Sin este comportamiento, un solo producto con actividad posterior bloquearía el undo completo, dejando al usuario sin ninguna salida — la reversión parcial es lo que hace la feature utilizable en un negocio que opera en vivo.

**Independent Test**: Se puede probar importando una corrida, generando una venta o ajuste de stock sobre uno de los productos tocados, y luego deshaciendo la corrida — se verifica que el resto de las filas se revierte igual y que la fila con actividad posterior queda reportada como no revertida, sin abortar el resto.

**Acceptance Scenarios**:

1. **Given** una corrida de import que actualizó 3 productos, de los cuales 1 tuvo una venta después del import, **When** el usuario deshace la corrida, **Then** los 2 productos sin actividad posterior vuelven a su estado anterior, el producto con venta posterior queda sin modificar, y el resumen del undo lista ese producto con el motivo ("tiene una venta posterior al import").
2. **Given** una corrida de import que dio de alta un producto que ya fue vendido antes de deshacer, **When** el usuario deshace la corrida, **Then** ese producto no se elimina (queda activo) y se reporta como "no se pudo deshacer: tiene ventas asociadas".

---

### User Story 3 - Ver el historial de imports y su estado de reversión (Priority: P3)

Un usuario quiere revisar los imports de Productos & Servicios hechos en los últimos días — quién los hizo, cuándo, cuántas filas afectaron, y si ya fueron deshechos o siguen vigentes — antes de decidir si deshacer uno.

**Why this priority**: Es soporte para las historias anteriores (da visibilidad y el punto de entrada a la acción "Deshacer"), pero no es indispensable el primer día: sin esta pantalla el usuario igual podría deshacer desde el resumen que ve justo después de importar. Se prioriza más bajo porque el valor incremental es de descubribilidad, no de capacidad nueva.

**Independent Test**: Se puede probar haciendo 2-3 imports de prueba y verificando que la pantalla lista cada corrida con sus datos correctos y el estado (vigente / deshecho / vencido) coincidiendo con la realidad de cada uno.

**Acceptance Scenarios**:

1. **Given** que existen imports previos de Productos & Servicios, **When** el usuario abre el historial de imports, **Then** ve cada corrida con fecha/hora, usuario, archivo origen, cantidad de filas creadas/actualizadas/fallidas, y su estado actual (vigente para deshacer, deshecho, o vencido).
2. **Given** una corrida cuya ventana de tiempo para deshacer ya venció, **When** el usuario la ve en el historial, **Then** figura como "vencido" y la acción "Deshacer" no está disponible.

---

### Edge Cases

- ¿Qué pasa si se reimporta la misma planilla dos veces seguidas y luego se deshace sólo la segunda corrida? → Cada corrida guarda su propio snapshot "antes de esa corrida específica"; deshacer la segunda restaura al estado que había quedado después de la primera, no al estado original previo a ambas.
- ¿Qué pasa si un producto fue tocado por dos corridas de import distintas (ambas vigentes) y se deshace la más antigua primero? → Esa fila se reporta como no revertida (ya fue modificada por una corrida más reciente todavía vigente) para no pisar el resultado del import más nuevo; el usuario debe deshacer en orden inverso (más reciente primero) si quiere revertir ambas.
- ¿Qué pasa si se intenta deshacer una fila de alta cuyo producto ya fue eliminado manualmente por el usuario después del import? → Se reporta como "no aplica" (ya no existe) sin error, y no cuenta como fallo del undo.
- ¿Qué pasa si el import original falló a mitad de proceso (algunas filas creadas, otras no llegaron a procesarse)? → El undo sólo actúa sobre las filas que efectivamente se crearon/actualizaron (las que tienen snapshot registrado); no hay nada que revertir para las filas que ya habían fallado en el import original.
- ¿Qué pasa con los precios por lista (`precios_producto`) tocados por el import? → Se restauran igual que cualquier otro campo pisado, quedando auditados como cualquier cambio de precio.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: El sistema DEBE registrar, antes de ejecutar el Paso 3 (confirmar) de un import de la solapa Productos & Servicios, una "corrida de import" con fecha/hora, usuario, nombre del archivo origen y cantidad de filas creadas, actualizadas y fallidas.
- **FR-002**: El sistema DEBE guardar, para cada fila que el import vaya a crear o actualizar, un snapshot del estado del producto inmediatamente anterior a esa corrida (el registro completo, o la indicación de que el producto no existía, para las altas).
- **FR-003**: El sistema NO DEBE tomar snapshot de productos no tocados por la corrida (sólo de las filas efectivamente creadas o actualizadas).
- **FR-004**: El sistema DEBE ofrecer una acción "Deshacer import" para cada corrida de import de Productos & Servicios, disponible únicamente dentro de una ventana de 48 horas desde que la corrida se confirmó.
- **FR-005**: Al deshacer una corrida, el sistema DEBE, para cada fila que fue un alta (producto creado por esa corrida): eliminar (soft-delete / marcar inactivo) ese producto, salvo que tenga operaciones posteriores que lo referencien (ventas, compras, movimientos de stock, notas de crédito/débito, remitos, etc.), en cuyo caso esa fila puntual no se revierte.
- **FR-006**: Al deshacer una corrida, el sistema DEBE, para cada fila que fue una actualización: restaurar los campos que el import había modificado (precio de venta, costo, IVA, categoría, proveedor, activo, mostrar en ventas/compras, stock por depósito, precios por lista, etc.) a los valores guardados en el snapshot, salvo que el producto tenga operaciones posteriores al import que impidan la restauración exacta de stock (ver FR-007).
- **FR-007**: La restauración de stock por depósito DEBE calcularse y aplicarse como un valor final deseado (no como un delta ciego), usando el mismo mecanismo de lectura+cálculo+escritura bajo lock que usa el import (`StockService::fijar()`, spec 074), para no pisar ventas, compras o ajustes de stock ocurridos después del import y antes del undo.
- **FR-008**: Si al deshacer una fila de actualización el sistema detecta que el stock del producto fue modificado por una operación posterior al import (venta, compra, ajuste, transferencia), esa fila puntual NO DEBE revertirse automáticamente; debe reportarse como no revertida con el motivo, sin abortar el resto del undo.
- **FR-009**: El undo de una corrida DEBE ser parcial cuando corresponda: procesar todas las filas que sí se pueden revertir y reportar individualmente las que no, sin abortar el proceso completo por el bloqueo de una sola fila.
- **FR-010**: El sistema DEBE presentar al usuario, al finalizar un undo, un resumen con cantidad de filas revertidas y cantidad de filas no revertidas (con motivo por cada una).
- **FR-011**: Toda restauración de precio realizada por un undo DEBE quedar auditada de la misma forma que un cambio de precio manual (pantalla de Auditoría, spec 074), registrando precio anterior, precio nuevo y origen ("Deshacer import").
- **FR-012**: Toda restauración de stock realizada por un undo DEBE generar un movimiento de stock de tipo `ajuste`, igual que el propio import, distinguible en su descripción como originado por un undo.
- **FR-013**: Una corrida de import ya deshecha (total o parcialmente) NO DEBE poder deshacerse nuevamente; el sistema debe indicar que esa corrida ya fue revertida, con fecha y usuario que ejecutó el undo.
- **FR-014**: El sistema DEBE ofrecer una pantalla de historial de imports de Productos & Servicios que liste cada corrida con fecha/hora, usuario, archivo origen, conteos (creadas/actualizadas/fallidas) y estado (vigente para deshacer / deshecho / vencido).
- **FR-015**: Cuando la ventana de 48 horas de una corrida vence, la acción "Deshacer" DEBE dejar de estar disponible para esa corrida, y su estado en el historial DEBE reflejar "vencido".
- **FR-016**: Si dos corridas de import distintas tocaron el mismo producto y ambas siguen vigentes, deshacer la corrida más antigua NO DEBE revertir las filas de ese producto (para no pisar el resultado de la corrida más reciente); esas filas se reportan como no revertidas con el motivo correspondiente.
- **FR-017**: Esta feature aplica únicamente a la solapa Productos & Servicios del asistente de Importar Datos. Clientes y Proveedores quedan fuera de alcance y deben documentarse como pendiente para una spec futura si se decide extenderlo.
- **FR-018**: Los datos de snapshot de una corrida de import DEBEN conservarse (no purgarse automáticamente) al menos durante la ventana de 48 horas, independientemente de si el usuario finalmente deshace la corrida o no.

### Key Entities *(include if feature involves data)*

- **Corrida de Import (Import Run)**: representa una ejecución del Paso 3 del asistente de Importar Datos para la solapa Productos & Servicios. Atributos clave: fecha/hora de confirmación, usuario que la ejecutó, nombre del archivo origen, cantidad de filas creadas/actualizadas/fallidas, estado (vigente / deshecho / vencido), y — si fue deshecha — fecha/hora y usuario del undo.
- **Snapshot de Fila (Import Row Snapshot)**: representa el estado de un producto inmediatamente antes de que una Corrida de Import lo creara o modificara. Vinculado a una Corrida de Import y a un producto. Contiene el estado previo completo relevante (o "no existía" para altas) y si esa fila puntual ya fue revertida, no se pudo revertir (con motivo), o sigue sin procesar.
- **Producto**: entidad ya existente en el sistema (ver `docs/modelo_datos.md` §`productos`), afectada por el import y por su reversión.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Un usuario puede revertir completamente un import erróneo de Productos & Servicios (sin operaciones posteriores sobre las filas afectadas) a su estado anterior en menos de 2 minutos desde que detecta el error, sin intervención técnica.
- **SC-002**: El 100% de las filas revertidas por un undo recuperan exactamente los valores de precio, costo y stock por depósito que tenían antes del import.
- **SC-003**: Un import cuyas filas tienen actividad posterior (ventas, ajustes) permite igualmente deshacer el 100% de las filas sin esa actividad, sin que ninguna fila bloqueada impida procesar el resto.
- **SC-004**: El usuario puede identificar, sin ambigüedad y sin soporte técnico, qué filas de una corrida no se pudieron revertir y el motivo, a partir del resumen que el sistema le muestra.
- **SC-005**: El historial de imports refleja el estado real (vigente/deshecho/vencido) de cada corrida en el 100% de los casos, sin desfasajes entre lo mostrado y las acciones efectivamente disponibles.

## Assumptions

- La ventana para deshacer un import es de **48 horas** desde la confirmación de la corrida (no se ofreció al usuario un valor específico; se adopta un default razonable acorde a "corregir un error detectado poco después", documentado aquí para ajuste posterior si el usuario lo pide).
- "Operaciones posteriores que impiden revertir" incluye: ventas, compras, notas de crédito/débito, remitos, ajustes manuales de stock, transferencias entre depósitos, y vínculos con Mercado Libre/Tiendanube que hayan escrito sobre el producto después del import — cualquier evento que haya modificado stock o que dependa de la existencia del producto.
- El soft-delete de productos dados de alta y luego deshechos usa el mismo mecanismo ya vigente en el sistema (`productos.activo = false`, sin eliminar físicamente el registro), coherente con el resto del CRM.
- La restauración de precios por lista (`precios_producto`) se trata como parte del snapshot de la fila, con el mismo criterio de auditoría que el resto de los cambios de precio (spec 074).
- No se requiere una confirmación adicional tipo "¿está seguro?" más allá del patrón estándar de modales de confirmación ya usado en el resto del CRM para acciones destructivas/irreversibles.
- El undo es una operación manual iniciada por el usuario (no hay reversión automática por ningún criterio); no hay límite de cantidad de undos por día ni restricción de permisos adicional a los ya vigentes sobre el módulo de Importar Datos / Productos.
- Fuera de alcance de esta spec: extender este mecanismo de snapshot/undo a las solapas de Clientes y Proveedores del mismo asistente (queda documentado como pendiente en `docs/documentacion_principal_crm.md` §5 si se decide abordarlo).
