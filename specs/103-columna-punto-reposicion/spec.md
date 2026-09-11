# Feature Specification: Columna Punto de Reposición en el listado de Productos

**Feature Branch**: `103-columna-punto-reposicion`

**Created**: 2026-09-10

**Status**: Draft

**Input**: User description: "Agregar 'Punto de Reposición' como columna en la tabla de Productos (Base de Datos → Productos), junto a la columna de Stock. El campo ya existe en el modelo de datos (productos.punto_reposicion, spec 073) y ya está expuesto en el modal de crear/editar Producto — esta spec sólo agrega la visibilidad en la tabla (listado server-side con DataTables). No incluye la importación/exportación masiva por Excel. Debe respetar el principio rector de fidelidad estructural: mostrar el valor tal como lo carga el usuario (entero, vacío/0 = 'sin control'), mismo criterio que ya usan el modal y el informe de Stock existente para 'en punto de reposición'."

## Contexto de negocio

`productos.punto_reposicion` ya existe (spec 073) y ya se carga/edita desde el modal de Producto
(alta y edición). Hoy ese dato **no se ve en ningún lado del listado de Productos**: para saber si un
producto tiene control configurado, o cuál es el número, hay que abrir el modal de edición uno por
uno. Esta spec cierra esa brecha agregando la columna al listado.

**Divergencia deliberada con Contagram real** (documentada, no accidental): el listado de Productos de
Contagram real no incluye esta columna (`docs/documentacion_principal_crm.md §2.2`). El Punto de
Reposición es un control interno de este CRM (spec 073) sin equivalente relevado en Contagram — no hay
"estructura real" que calcar acá. Se agrega por necesidad operativa del negocio y se documenta como
excepción explícita en `documentacion_principal_crm.md`, mismo patrón ya usado para la excepción del
buscador de productos (spec 071).

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Ver el Punto de Reposición de cada producto en el listado (Priority: P1)

Como usuario que gestiona el catálogo, quiero ver el Punto de Reposición de cada producto directamente
en la tabla de Productos, para saber de un vistazo qué productos tienen control configurado y cuál es
el número, sin tener que abrir el modal de edición de cada uno.

**Why this priority**: Es el único objetivo de esta spec — sin esto no hay entrega. El campo ya es
editable (spec 073); lo que falta es únicamente la visibilidad en el listado.

**Independent Test**: Abrir Base de Datos → Productos y verificar que la tabla muestra una columna
"Punto de Reposición" con el valor cargado de cada producto, sin abrir ningún modal.

**Acceptance Scenarios**:

1. **Given** un producto con `punto_reposicion = 5`, **When** se abre el listado de Productos, **Then**
   la fila de ese producto muestra "5" en la columna Punto de Reposición.
2. **Given** un producto con `punto_reposicion = 0` (o nunca configurado), **When** se abre el listado,
   **Then** la fila muestra un indicador de "sin control" (no un "0" que confunda con "hay que reponer
   ya"), consistente con el criterio ya usado en el modal (`placeholder="Sin control"`) y en el informe
   de Stock existente.
3. **Given** un producto de Tipo = Servicio, **When** se abre el listado, **Then** la columna muestra
   el mismo indicador de "sin control" (el punto de reposición no aplica a servicios, mismo criterio
   que la regla de negocio ya vigente en `documentacion_principal_crm.md §2.2`).

---

### User Story 2 - Ordenar y filtrar por Punto de Reposición (Priority: P2)

Como usuario, quiero poder ordenar el listado por Punto de Reposición para agrupar rápidamente los
productos con control configurado, o los de mayor exigencia.

**Why this priority**: Mejora el flujo de trabajo pero no es indispensable para la entrega mínima
(User Story 1 ya resuelve "ver el dato"); se prioriza después porque el ordenamiento server-side es
mecánico una vez que la columna existe.

**Independent Test**: Hacer clic en el encabezado de la columna Punto de Reposición y verificar que la
tabla se reordena de menor a mayor y viceversa, sin recargar la página.

**Acceptance Scenarios**:

1. **Given** el listado de Productos con varias filas, **When** se hace clic en el encabezado "Punto de
   Reposición", **Then** las filas se reordenan ascendente por ese valor (0/sin control primero).
2. **Given** el listado ya ordenado ascendente por esa columna, **When** se vuelve a hacer clic en el
   encabezado, **Then** el orden se invierte a descendente.

---

### User Story 3 - Mostrar/ocultar la columna (Priority: P3)

Como usuario, quiero poder ocultar la columna Punto de Reposición desde el selector de columnas del
listado si no la uso habitualmente, igual que con el resto de las columnas.

**Why this priority**: Consistencia con el comportamiento ya existente del selector de columnas
(colvis) — valor agregado menor, no bloquea la entrega de las historias anteriores.

**Independent Test**: Abrir el selector de columnas (ícono de columnas en la toolbar), desmarcar "Punto
de Reposición" y verificar que la columna desaparece de la tabla sin recargar la página; volver a
marcarla y verificar que reaparece.

**Acceptance Scenarios**:

1. **Given** el listado de Productos, **When** se abre el selector de columnas, **Then** "Punto de
   Reposición" aparece listada como una columna que se puede mostrar/ocultar.

---

### Edge Cases

- **Producto con `punto_reposicion` negativo**: no puede ocurrir — la columna de base de datos es
  `unsignedInteger` (spec 073); no hace falta manejarlo en la UI.
- **Producto de Tipo = Servicio**: nunca tiene punto de reposición aplicable (regla ya vigente); la
  columna muestra el mismo indicador de "sin control" que un producto con `0`.
- **Filtrado/búsqueda global del listado**: el valor numérico de Punto de Reposición no participa de la
  búsqueda de texto libre existente (mismo criterio que Stock total, que tampoco es buscable por texto)
  — sólo es ordenable (User Story 2). Si en el futuro se pide filtrar por rango de Punto de Reposición,
  queda para una spec aparte (mismo patrón que "Stock menor que" / "Stock mayor que" en el panel de
  Filtros).
- **Reordenar columnas o cambiar su ancho**: fuera de alcance; se usa el comportamiento default de
  DataTables ya vigente en el resto de columnas del listado.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: El listado de Productos (Base de Datos → Productos) MUST mostrar una columna "Punto de
  Reposición" con el valor de `productos.punto_reposicion` de cada fila.
- **FR-002**: La columna MUST ubicarse inmediatamente después de las columnas de Stock (Stock total +
  columnas de stock por depósito) y antes de Costo, siguiendo el orden actual del listado.
- **FR-003**: Cuando `punto_reposicion` es `0` (valor por defecto, "sin control"), la columna MUST
  mostrar un indicador distinto de un simple "0" numérico (ej. un guion o texto "Sin control"), para no
  confundir "sin control configurado" con "hay que reponer urgente". Mismo criterio ya usado en el
  placeholder del modal ("Sin control").
- **FR-004**: Cuando `punto_reposicion` es mayor a `0`, la columna MUST mostrar el número entero tal
  cual está cargado, sin decimales ni formateo adicional.
- **FR-005**: La columna MUST ser ordenable (ascendente/descendente) por el usuario, resuelto del lado
  del servidor (mismo mecanismo server-side que ya usan las columnas ordenables existentes del
  listado).
- **FR-006**: La columna MUST aparecer en el selector de columnas (mostrar/ocultar) del listado, con el
  mismo comportamiento que las columnas existentes.
- **FR-007**: La columna NO MUST participar de la búsqueda de texto libre del listado (mismo criterio
  que Stock total, que tampoco es buscable por texto).
- **FR-008**: Esta spec NO incluye cambios a la importación ni exportación masiva de Productos por
  Excel — sólo la visibilidad en el listado. Fuera de alcance.
- **FR-009**: Esta spec NO agrega ni modifica ningún filtro nuevo en el panel de Filtros del listado
  (ej. "Punto de reposición menor/mayor que") — fuera de alcance, spec aparte si se pide.
- **FR-010**: `docs/documentacion_principal_crm.md §2.2` MUST actualizarse para documentar esta columna
  como una divergencia deliberada respecto al listado real de Contagram, con la razón de negocio (mismo
  patrón que la excepción documentada de spec 071).

### Key Entities

- **Producto**: entidad existente, sin cambios de esquema. Sólo se expone en el listado el atributo ya
  existente `punto_reposicion` (spec 073).

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Un usuario puede identificar el Punto de Reposición de cualquier producto visible en el
  listado sin abrir ningún modal.
- **SC-002**: El 100% de los productos con `punto_reposicion = 0` muestran el mismo indicador de "sin
  control" en el listado — no hay ambigüedad entre "sin configurar" y "0 unidades es el límite".
- **SC-003**: Ordenar el listado por la columna nueva devuelve resultados correctos aun con miles de
  productos (resuelto server-side, sin degradar el tiempo de carga del listado respecto al estado
  actual).

## Assumptions

- El campo `productos.punto_reposicion` y su edición en el modal (spec 073) ya funcionan correctamente
  y no se modifican en esta spec — sólo se lee y se muestra.
- "Junto a la columna de Stock" (pedido del usuario) se interpreta como inmediatamente después de las
  columnas de stock (Stock total + stock por depósito) y antes de Costo, siguiendo el orden ya
  establecido del listado (confirmado con el usuario).
- No se pide en esta spec ningún filtro nuevo por Punto de Reposición en el panel de Filtros — sólo
  visibilidad y ordenamiento en la tabla (confirmado con el usuario: alcance acotado a modal+tabla).
- La importación/exportación masiva de Productos por Excel queda fuera de alcance (confirmado con el
  usuario) — si se necesita en el futuro, es una spec aparte.
- Esta columna es una divergencia deliberada y documentada respecto al listado real de Contagram
  (confirmado con el usuario), no un error de relevamiento.
