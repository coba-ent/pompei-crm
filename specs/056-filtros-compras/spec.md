# Feature Specification: Filtros del listado de Compras

**Feature Branch**: `056-filtros-compras`

**Created**: 2026-08-11

**Status**: Draft

**Input**: User description: "Corregir los filtros del listado de Compras para que coincidan exactamente con Contagram real. Reemplazar los filtros actuales (solo 'Proveedor' simple y un buscador de comprobante) por el set completo relevado en la captura real: Id, Proveedor (selección múltiple), Categoría de Compra, Estado del Pago, Tipo y N° de Factura, Etiqueta, Facturado, Medio de pago, Usuario, Nota Interna, Depósito, Desde Servicio, Hasta Servicio; más los controles superiores: selector de rango de fechas, selector de tipo de fecha ('Vencimiento'), selector de columnas visibles, y el botón 'Nueva Compra' (ya existe). El filtro de Proveedor debe ser múltiple (Select2 multiple + backend con whereIn), igual que el resto de los filtros de selección que correspondan a listas. Reutilizar el patrón ya implementado en Ventas. Si el filtro de Etiqueta requiere agregar la relación etiquetas() al modelo Compra, incluirlo en el alcance. Actualizar docs/documentacion_principal_crm.md y el informe correspondiente si están desactualizados."

## Clarifications

### Session 2026-08-11

- Q: El filtro "Estado del Pago" de Compras: ¿qué opciones debe tener? → A: Pagado / A Pagar / Parcial (los 3 estados ya calculados por `Compra::estadoPago()`, sin agregar un valor "Vencido" separado como opción de filtro).
- **Revisión post-implementación (11/08/2026)**: decisión revertida a pedido del usuario — el filtro "Estado del Pago" SÍ agrega un cuarto valor **Vencido** (compras con `fecha_vto_pago` pasada y saldo pendiente > 0, mismo criterio que ya usa la card KPI "Vencido"). Mismo cambio aplicado en el filtro "Estado del Cobro" de Ventas (gap estructural idéntico, fuera del alcance original de esta spec pero corregido en el mismo commit por consistencia).
- Q: Los filtros de rango de fecha (Desde/Hasta Servicio, y el rango de Vencimiento) sobre compras sin esa fecha cargada (nullable): ¿se incluyen o se excluyen? → A: Se excluyen del resultado cuando el filtro correspondiente está activo.
- (Corrección post-revisión de patrón en Ventas) El control superior "Vencimiento" de la captura NO es un selector que cambia el tipo de fecha del rango de Emisión: es un **segundo rango de fechas independiente**, igual al ya existente en Ventas (`filtro-rango-emision` + `filtro-rango-vencimiento`, dos date-pickers separados que se combinan con AND si ambos están activos). Se corrige FR-009 en consecuencia.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Filtrar compras por múltiples proveedores a la vez (Priority: P1)

Como usuario que gestiona compras a muchos proveedores, quiero poder filtrar el listado de Compras eligiendo varios proveedores a la vez (no solo uno), para poder revisar en una sola búsqueda, por ejemplo, todas las compras hechas a un grupo de proveedores relacionados (mismo rubro, misma cadena, etc.) sin tener que repetir la búsqueda proveedor por proveedor.

**Why this priority**: Es la corrección más visible y más pedida — hoy el filtro de Proveedor solo permite elegir uno, y ese es el bloqueo concreto que el usuario reportó primero. Sin esto, el resto de filtros nuevos son secundarios frente a la necesidad inmediata de comparar varios proveedores en una sola vista.

**Independent Test**: Se puede probar de forma aislada abriendo el panel de Filtros de Compras, seleccionando dos o más proveedores en el campo Proveedor, presionando Buscar, y verificando que el listado muestra únicamente compras de esos proveedores (unión, no intersección).

**Acceptance Scenarios**:

1. **Given** el panel de Filtros de Compras abierto, **When** el usuario busca y selecciona dos proveedores distintos en el campo Proveedor, **Then** ambos quedan visibles como tags seleccionados y el campo permite seguir agregando más sin reemplazar los ya elegidos.
2. **Given** dos o más proveedores seleccionados en el filtro Proveedor, **When** el usuario presiona Buscar, **Then** el listado muestra las compras de cualquiera de los proveedores seleccionados (unión), no solo del último elegido.
3. **Given** un filtro de Proveedor con selección múltiple aplicada, **When** el usuario quita uno de los proveedores seleccionados (tag "x") y vuelve a buscar, **Then** el listado se actualiza excluyendo las compras de ese proveedor sin perder los demás filtros activos.

---

### User Story 2 - Disponer de todos los filtros reales de Contagram en Compras (Priority: P1)

Como usuario que necesita ubicar compras puntuales rápidamente, quiero contar en el listado de Compras con el mismo panel de filtros que tiene Contagram real (Id, Proveedor, Categoría de Compra, Estado del Pago, Tipo y N° de Factura, Etiqueta, Facturado, Medio de pago, Usuario, Nota Interna, Depósito, Desde Servicio, Hasta Servicio), para poder acotar la búsqueda por cualquiera de esos criterios sin tener que revisar el listado completo a mano.

**Why this priority**: Es el corazón del pedido: hoy el listado de Compras solo tiene 2 de los 12 filtros que corresponden según la estructura real de Contagram, lo que obliga a buscar manualmente. Se prioriza igual que la Historia 1 porque ambas forman parte de la misma corrección estructural y se implementan juntas.

**Independent Test**: Se puede probar de forma aislada abriendo el panel de Filtros de Compras y verificando que están presentes los 12 campos, que cada uno filtra correctamente por sí solo (con al menos un caso de prueba por campo) y que se pueden combinar varios filtros a la vez con criterio AND entre campos distintos.

**Acceptance Scenarios**:

1. **Given** el panel de Filtros de Compras, **When** se despliega, **Then** muestra los campos Id, Proveedor, Categoría de Compra, Estado del Pago, Tipo y N° de Factura, Etiqueta, Facturado, Medio de pago, Usuario, Nota Interna, Depósito, Desde Servicio y Hasta Servicio.
2. **Given** una compra con un Id conocido, **When** el usuario filtra por ese Id, **Then** el listado muestra únicamente esa compra.
3. **Given** compras con distintos estados de pago (Pagado / A Pagar / Parcial), **When** el usuario filtra por "Estado del Pago" = A Pagar, **Then** el listado muestra solo las compras sin ningún pago registrado (`estadoPago() = a_pagar`).
4. **Given** compras con y sin comprobante fiscal emitido, **When** el usuario filtra por "Facturado" = Sí, **Then** el listado muestra solo las compras con comprobante fiscal emitido asociado.
5. **Given** varios filtros aplicados a la vez (por ejemplo Categoría de Compra + Depósito + rango de fechas), **When** el usuario presiona Buscar, **Then** el listado combina todos los criterios con AND entre campos distintos (y OR dentro del mismo campo cuando admite selección múltiple).
6. **Given** el panel de filtros con algún filtro aplicado, **When** el usuario limpia los filtros (o recarga el listado sin filtros), **Then** el listado vuelve a mostrar todas las compras según el rango de fechas por defecto vigente.

---

### User Story 3 - Filtrar por Vencimiento además de Emisión, y elegir qué columnas ver (Priority: P2)

Como usuario que revisa compras periódicamente, quiero poder acotar el listado por un rango de fecha de Vencimiento (independiente del rango de Emisión ya existente) y poder mostrar u ocultar columnas del listado, para adaptar la vista a lo que necesito revisar en cada momento (por ejemplo, ver qué vence esta semana además de filtrar por cuándo se cargó).

**Why this priority**: Mejora la usabilidad del listado pero no bloquea la búsqueda puntual de compras (que ya cubren las Historias 1 y 2); por eso es P2.

**Independent Test**: Se puede probar de forma aislada eligiendo un rango en el nuevo control "Vencimiento" y verificando que el listado filtra por fecha_vto_pago dentro de ese rango, combinándose con AND si además hay un rango de Emisión activo; y por separado, abriendo el selector de columnas y tildando/destildando columnas adicionales y verificando que la tabla las muestra/oculta.

**Acceptance Scenarios**:

1. **Given** un rango de fechas elegido en el control "Vencimiento", **When** el usuario busca, **Then** el listado muestra solo compras cuya fecha de vencimiento de pago cae dentro del rango.
2. **Given** un rango de fechas elegido en el control "Emisión" y ningún rango en "Vencimiento", **When** el usuario busca, **Then** el listado muestra solo compras cuya fecha de emisión cae dentro del rango (comportamiento ya existente, sin cambios).
3. **Given** rangos activos en "Emisión" y en "Vencimiento" a la vez, **When** el usuario busca, **Then** el listado muestra solo las compras que cumplen ambos rangos simultáneamente (AND).
4. **Given** el selector de columnas visibles, **When** el usuario tilda una columna adicional (ej. CUIT, Servicio Desde), **Then** esa columna aparece en la tabla sin recargar la página.

---

### Edge Cases

- ¿Qué pasa si el usuario aplica un filtro (por ejemplo Categoría de Compra) que no tiene ningún valor cargado en la base? El listado debe mostrar el estado vacío estándar de la tabla ("sin resultados"), no un error.
- ¿Qué pasa si el usuario selecciona varios proveedores y además escribe un Id de una compra que pertenece a un proveedor no incluido en esa selección? El filtro de Id (si apunta a una compra puntual existente) se combina con AND respecto al filtro de Proveedor: si la compra de ese Id no pertenece a ninguno de los proveedores seleccionados, el resultado es vacío — no se ignora el filtro de proveedor.
- ¿Qué pasa si "Desde Servicio" es posterior a "Hasta Servicio"? El sistema debe mostrar el listado vacío (rango inválido) sin error, igual que el comportamiento ya existente para el rango de fechas superior.
- ¿Qué pasa con compras sin fecha_vto_pago, sin servicio_desde o sin servicio_hasta cargados frente a los filtros de esas fechas? Quedan excluidas del resultado mientras el filtro correspondiente esté activo (ver Clarifications 2026-08-11).
- ¿Qué pasa con compras cargadas antes de esta corrección que no tienen Depósito, Etiqueta o Usuario asociado (columnas nuevas o antes no completadas)? Deben quedar excluidas cuando se filtra explícitamente por un valor de ese campo, y deben aparecer normalmente cuando ese filtro no se usa.
- ¿Qué pasa si el usuario filtra por "Medio de pago" en una compra que tiene varios pagos parciales con medios de pago distintos? La compra debe listarse si **alguno** de sus pagos usó el medio de pago filtrado.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: El panel de Filtros de Compras DEBE incluir exactamente estos campos, en línea con la estructura real de Contagram: Id, Proveedor, Categoría de Compra, Estado del Pago, Tipo y N° de Factura, Etiqueta, Facturado, Medio de pago, Usuario, Nota Interna, Depósito, Desde Servicio, Hasta Servicio.
- **FR-002**: El filtro Proveedor DEBE permitir seleccionar más de un proveedor a la vez, combinando los proveedores seleccionados con criterio OR (unión) al buscar.
- **FR-003**: Los filtros que representan catálogos/listas (Categoría de Compra, Etiqueta, Estado del Pago, Medio de pago, Usuario, Depósito) DEBEN permitir selección múltiple, con el mismo criterio OR dentro del propio filtro.
- **FR-004**: El filtro Id DEBE ubicar una compra por su número identificador exacto.
- **FR-005**: El filtro "Tipo y N° de Factura" DEBE buscar coincidencias tanto en el tipo de comprobante como en el número de comprobante de la compra (interno o el asignado por el comprobante fiscal, si existe).
- **FR-006**: El filtro "Facturado" DEBE permitir distinguir compras que tienen un comprobante fiscal (ARCA) emitido asociado de las que no lo tienen.
- **FR-007**: El filtro Nota Interna DEBE buscar coincidencias parciales de texto dentro del campo de nota interna de la compra.
- **FR-008**: Los filtros Desde Servicio / Hasta Servicio DEBEN acotar el listado a compras cuyo rango de servicio (servicio_desde/servicio_hasta) se solape con el rango indicado por el usuario; las compras sin servicio_desde/servicio_hasta cargado quedan excluidas cuando cualquiera de estos filtros está activo.
- **FR-009**: El listado DEBE seguir contando con el control superior de rango de fechas por Emisión ya existente y, además, DEBE sumar un segundo control independiente de rango de fechas por Vencimiento (Vto. del Pago) — mismo patrón de dos rangos ya implementado en Ventas. Ambos rangos se combinan con AND cuando los dos están activos. Las compras sin fecha_vto_pago cargada quedan excluidas del resultado mientras el rango de Vencimiento esté activo.
- **FR-009a**: El filtro "Estado del Pago" DEBE ofrecer los 3 valores derivados por `Compra::estadoPago()` (Pagado, A Pagar, Parcial) **más** un cuarto valor **Vencido** (revisión 11/08/2026): `fecha_vto_pago` pasada y saldo pendiente (A Pagar real, con NC/ND) > 0 — mismo criterio que la card KPI "Vencido".
- **FR-010**: El listado DEBE contar con un selector de columnas visibles que permita mostrar u ocultar columnas adicionales (como mínimo: CUIT, Servicio Desde, Servicio Hasta, Teléfono, Mail) sin recargar la página.
- **FR-011**: Todos los filtros aplicados simultáneamente DEBEN combinarse con criterio AND entre campos distintos (cada filtro reduce el resultado de los demás).
- **FR-012**: El botón "Nueva Compra" DEBE seguir disponible y funcionando igual que hoy (sin cambios de comportamiento).
- **FR-013**: El modelo de Compra DEBE soportar asociarse a Etiquetas (relación muchos-a-muchos), de forma equivalente a como ya lo hace Venta, para que el filtro y la columna de Etiquetas del listado de Compras funcionen.
- **FR-014**: El sistema DEBE registrar de forma consultable qué usuario creó cada compra nueva, para que el filtro Usuario pueda aplicarse sobre compras cargadas a partir de esta corrección.
- **FR-015**: La documentación de dominio del proyecto (`docs/documentacion_principal_crm.md` y, si corresponde, `docs/informe_contagram_egresos.md`) DEBE actualizarse para reflejar el set de filtros real de Compras documentado en esta spec, resolviendo la discrepancia hoy existente entre ambos documentos y la estructura real relevada.

### Key Entities *(include if feature involves data)*

- **Compra**: documento de compra a un Proveedor. Pasa a poder asociarse a una o varias Etiquetas y a registrar qué Usuario la creó; ambos datos habilitan nuevos criterios de filtrado del listado.
- **Etiqueta**: clasificación libre ya usada en Ventas; se extiende su uso para poder asociarse también a Compras.
- **Usuario**: quien crea una Compra; pasa a quedar identificado en cada compra nueva para poder filtrar el listado por ese criterio.
- **Proveedor, Categoría de Compra, Estado del Pago, Medio de pago, Depósito**: criterios de filtro ya existentes como datos en el sistema, que pasan a poder seleccionarse de a varios a la vez (Proveedor) o a incorporarse como filtro nuevo del listado de Compras.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Un usuario puede localizar cualquier compra puntual del sistema (conociendo al menos uno de sus datos: Id, proveedor, número de factura, etc.) usando únicamente los filtros del listado, sin necesidad de recorrer manualmente el listado completo.
- **SC-002**: Un usuario puede obtener en una sola búsqueda todas las compras de un conjunto de 2 o más proveedores elegidos, sin tener que repetir la búsqueda una vez por proveedor.
- **SC-003**: El panel de Filtros de Compras contiene el 100% de los campos de filtro presentes en el listado equivalente de Contagram real (12 campos listados en FR-001), verificable por inspección visual directa.
- **SC-004**: Elegir un rango en el nuevo control de Vencimiento (solo, o combinado con el de Emisión) y volver a buscar actualiza el resultado sin que el usuario tenga que recargar la página.

## Assumptions

- El criterio de combinación entre filtros es AND entre campos distintos y OR dentro de un mismo filtro con selección múltiple (mismo patrón ya usado en Ventas).
- "Facturado" se interpreta como "tiene comprobante fiscal (ARCA) emitido asociado" (relación `comprobanteFiscal` de la Compra), en línea con el mismo concepto ya usado en Ventas.
- El filtro "Medio de pago" se resuelve contra los medios de pago usados en los pagos ya registrados de cada compra (una compra puede tener pagos con distintos medios; alcanza con que uno coincida).
- El filtro "Usuario" solo puede aplicarse de forma significativa a compras cargadas a partir de esta corrección, ya que hoy no se registra qué usuario creó una compra; las compras históricas anteriores quedarán sin ese dato (sin backfill), igual que ocurrió con otras columnas nuevas agregadas a Compras en specs previas (ej. Depósito en spec 049).
- El filtro Etiqueta reutiliza el mismo catálogo de Etiquetas ya existente en el sistema (compartido con Ventas), no se crea un catálogo separado para Compras.
- Se resuelve a favor de la captura real de Contagram (provista por el usuario) la discrepancia detectada entre `docs/documentacion_principal_crm.md` / `docs/informe_contagram_egresos.md` (que documentan un subconjunto de 7 filtros) y la estructura real de 12 filtros — ambos documentos se corrigen como parte de esta spec (FR-015).
- El selector de columnas visibles adicionales toma como referencia mínima las columnas mencionadas en el informe existente (CUIT, Servicio Desde, Servicio Hasta, Teléfono, Mail); no se exige una lista cerrada exhaustiva más allá de esas.
- Esta spec cubre únicamente el listado/filtros de Compras. No incluye cambios al flujo de alta/edición/eliminación de Notas de Crédito y Débito de Compras, que se resuelve en un spec aparte.
