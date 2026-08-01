# Feature Specification: Importador de Datos — Actualizar por Id (Upsert)

**Feature Branch**: `027-importador-upsert-por-id`

**Created**: 2026-07-31

**Status**: Draft

**Input**: User description: "Agregar al asistente 'Importar Datos' (Clientes, Proveedores, Productos & Servicios) la
capacidad de actualizar registros existentes durante la importación, no sólo crear nuevos. En el paso de mapeo de
columnas, el selector de campo destino debe ofrecer siempre la opción 'Id' (el id interno del sistema, primary key
de cada entidad) además de los campos ya existentes. Si el usuario mapea una columna a 'Id' y confirma la
importación: por cada fila, si el valor de esa celda coincide con el id de un registro existente de esa entidad, el
registro se actualiza (upsert) con los demás campos mapeados en esa fila en vez de crearse uno nuevo; si el valor no
coincide con ningún id existente, la fila se trata según la regla ya vigente para 'no encontrado'. Alcance: sólo las
3 entidades ya soportadas por el importador (Clientes, Proveedores, Productos & Servicios). No se toca el mecanismo
de resolución de FK por nombre ya existente (Proveedor/Categoría/Condición de IVA/Tipo de Producto/Lista de
Precios), que sigue funcionando igual."

## Clarifications

### Session 2026-07-31 (segunda pasada — /speckit-clarify)

- Q: En una fila de actualización, si el campo mapeado con un valor no vacío es uno con regla de unicidad (ej.
  CUIT de Cliente/Proveedor) y ese valor coincide con el que **ya tiene ese mismo registro**, ¿la validación debe
  rechazarlo por "ya existe" o aceptarlo por tratarse del mismo registro? → A: **Debe aceptarlo** — la validación de
  unicidad en una fila de actualización se evalúa excluyendo al propio registro que se está actualizando (mismo
  criterio que ya usa el alta manual al editar, `Rule::unique(...)->ignore($id)`), para no bloquear una fila que en
  los hechos no cambia el CUIT.

### Session 2026-07-31

- Q: Si una columna está mapeada a "Id" y el valor de una fila no coincide con ningún registro existente de esa
  entidad, ¿qué pasa con esa fila? → A: **Se marca como fila fallida** (mismo criterio que cualquier otro valor
  inválido — motivo claro "Id X no encontrado"), sin crear un alta nueva. Evita crear registros "fantasma" con un
  id de sistema anterior que no corresponde a nada real, y hace que un error de tipeo en la columna Id no genere
  silenciosamente un duplicado.
- Q: En una fila que actualiza un registro existente (Id matcheado), los campos del modelo que el usuario **no**
  mapeó en esa corrida (o cuya celda vino vacía) ¿qué pasan a valer? → A: **Se dejan sin tocar** (actualización
  parcial — sólo se pisan los campos efectivamente mapeados con valor no vacío en esa fila). Es el comportamiento
  esperado de "actualizar", no de "reemplazar": el usuario típicamente sube un archivo con sólo las columnas que
  quiere corregir (ej. sólo Id + Saldo Inicial), y no espera que el resto de los datos ya cargados en el sistema se
  borren.
- Q: Las reglas de validación de "campo obligatorio" (ej. Nombre en Cliente/Proveedor, Nombre en Producto) que hoy
  aplican siempre en el alta manual y en el importador (spec 006/026), ¿aplican igual en una fila de actualización
  por Id? → A: **No aplican en una fila de actualización** (Id matcheado): el campo obligatorio ya existe en el
  registro a actualizar, así que no hace falta volver a mandarlo en esa fila. La regla de obligatoriedad sigue
  aplicando tal cual hoy para las filas que **no** mapean Id (alta nueva).

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Corregir datos de clientes ya cargados sin recrearlos (Priority: P1)

Como usuario del negocio, después de una importación inicial (spec 026) detecto que faltó cargar o corregir algún
dato de un grupo de clientes ya existentes (ej. el saldo inicial de una tanda, o la lista de precios asignada).
Quiero subir un archivo nuevo con la columna Id (el id que el sistema le asignó a cada cliente) más sólo las
columnas que necesito corregir, mapear Id al campo "Id" y el resto a sus campos correspondientes, confirmar, y que
cada cliente existente se actualice con esos valores sin duplicar ni tocar los datos que no mapeé.

**Why this priority**: Es el caso de uso principal que motiva la feature — corregir datos post-importación masiva
sin tener que editar cliente por cliente a mano ni volver a cargar el archivo completo (que hoy generaría
duplicados, spec 006 Assumptions).

**Independent Test**: Con clientes ya creados (por ejemplo, vía spec 026), subir un archivo con columna Id + una o
dos columnas de dato a corregir, mapear Id a "Id", confirmar, y verificar en `/clientes` que cada cliente
coincidente quedó con el dato nuevo y el resto de sus datos igual que antes — sin depender de Proveedores ni de
Productos.

**Acceptance Scenarios**:

1. **Given** el paso de mapeo de cualquiera de las 3 solapas (Clientes, Proveedores, Productos & Servicios),
   **When** el usuario abre el selector de campo destino de una columna, **Then** aparece siempre la opción "Id"
   entre los campos disponibles, junto a los ya existentes.
2. **Given** una columna mapeada a "Id" con un valor que coincide con el id de un cliente existente, y otra columna
   mapeada a "Saldo Inicial" con un valor nuevo, **When** se confirma la importación, **Then** ese cliente queda
   con el saldo inicial actualizado y el resto de sus campos (nombre, email, domicilio, etc.) sin cambios.
3. **Given** una columna mapeada a "Id" con un valor que no coincide con ningún cliente existente, **When** se
   confirma la importación, **Then** esa fila se reporta como fallida con motivo "Id [valor] no encontrado", y no
   se crea ningún cliente nuevo para esa fila.
4. **Given** una fila de actualización (Id matcheado) que no mapea la columna "Nombre" (o la mapea con la celda
   vacía), **When** se confirma la importación, **Then** la fila se procesa igual (no se exige Nombre en filas de
   actualización) y el cliente conserva su nombre actual.

---

### User Story 2 - Mismo mecanismo para Proveedores y Productos (Priority: P2)

Como usuario, quiero el mismo comportamiento de actualización por Id en las solapas Proveedores y Productos &
Servicios, para corregir datos ya cargados de cualquiera de las 3 entidades con el mismo mecanismo.

**Why this priority**: Reutiliza el mismo mecanismo que la User Story 1 una vez validado — bajo esfuerzo
incremental, sin lógica nueva por entidad más allá de repetir el patrón.

**Independent Test**: Con proveedores y productos ya creados, subir un archivo de cada uno con columna Id + una
columna de dato a corregir, mapear, confirmar, y verificar que cada registro coincidente se actualizó — sin
depender de Clientes.

**Acceptance Scenarios**:

1. **Given** un archivo de Proveedores con columna Id mapeada y una columna de dato a corregir, **When** se
   confirma, **Then** el proveedor coincidente se actualiza igual que en la User Story 1.
2. **Given** un archivo de Productos con columna Id mapeada y una columna "Mostrar en Ventas" con un valor nuevo,
   **When** se confirma, **Then** el producto coincidente se actualiza sin tocar sus otros campos (precio, costo,
   stock, etc.).

---

### Edge Cases

- ¿Qué pasa si en la misma corrida hay filas con Id mapeado y coincidente (actualización) mezcladas con filas donde
  la celda Id vino vacía? → Las filas con celda Id vacía se tratan como alta nueva (comportamiento ya vigente,
  spec 006/026, sin cambios); las filas con Id no vacío se tratan como actualización con las reglas de esta
  feature.
- ¿Qué pasa si se mapea "Id" y además se mapea dos veces el mismo campo destino (ej. dos columnas al campo
  "Nombre")? → Se aplica la regla ya vigente (FR-005 de spec 006): no se puede confirmar con dos columnas mapeadas
  al mismo campo destino, "Id" incluido — es un campo de mapeo más a estos fines.
- ¿Qué pasa si la columna "Id" trae un valor que no es numérico (texto, vacío en una fila puntual)? → Celda vacía:
  alta nueva (ver arriba). Valor no numérico: fila fallida, mismo criterio que cualquier otro valor no
  interpretable ("Id [valor] no es un id válido"). Un valor numérico pero no entero (ej. "5,5") se trata igual
  que "no numérico" — un id de sistema siempre es entero.
- ¿Qué pasa con los campos FK-por-nombre (Proveedor, Categoría, Condición de IVA, Tipo de Producto, Lista de
  Precios) en una fila de actualización? → Sin cambios: si la columna FK está mapeada con un valor, se resuelve
  igual que hoy (match exacto sin distinguir mayúsculas/acentos, advertencia si no matchea); si no está mapeada o
  la celda está vacía, el campo FK existente del registro no se toca (mismo criterio de actualización parcial).
- ¿Qué pasa si dos filas del mismo archivo mapean el mismo Id (ej. el usuario corrige el mismo registro dos veces
  por error)? → Se procesan en el orden en que aparecen en el archivo; la segunda fila vuelve a actualizar el
  mismo registro (gana el último valor de esa corrida) — mismo criterio de "última fila gana" que ya aplicaría
  si dos filas de alta trajeran el mismo dato, sin detección de duplicados adicional (ver Assumptions).
- ¿Qué pasa si se intenta actualizar un producto/cliente/proveedor inactivo (soft-state, no eliminado)? → Se
  actualiza igual — el estado Activo/Inactivo es un campo más, no bloquea la actualización de otros datos.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: El sistema DEBE ofrecer, en el paso de mapeo de las 3 solapas (Clientes, Proveedores, Productos &
  Servicios), el campo destino "Id" además de los campos ya disponibles hoy.
- **FR-002**: Si la columna "Id" está mapeada y la celda de una fila tiene un valor que coincide con el id de un
  registro existente de esa entidad, el sistema DEBE actualizar ese registro con los demás campos mapeados en esa
  fila, en vez de crear un registro nuevo.
- **FR-003**: La actualización por Id DEBE ser parcial: sólo se pisan los campos de la fila que están mapeados y
  cuya celda no está vacía; cualquier campo del registro existente que no esté mapeado (o cuya celda esté vacía en
  esa fila) permanece sin cambios.
- **FR-004**: Si la columna "Id" está mapeada y la celda de una fila tiene un valor que no coincide con ningún
  registro existente de esa entidad, esa fila DEBE marcarse como fallida con un motivo claro ("Id [valor] no
  encontrado"), sin crear ni actualizar ningún registro para esa fila.
- **FR-005**: Si la columna "Id" está mapeada y la celda de una fila está vacía, esa fila DEBE procesarse como alta
  nueva (comportamiento ya vigente de spec 006/026, sin cambios).
- **FR-006**: Las reglas de "campo obligatorio" (ej. Nombre) NO DEBEN exigirse en una fila de actualización por Id
  (Id mapeado y coincidente); SÍ DEBEN seguir exigiéndose, sin cambios, en cualquier fila que se procese como alta
  nueva.
- **FR-007**: El campo "Id" NO DEBE participar de la regla "dos columnas mapeadas al mismo campo destino" de forma
  distinta a cualquier otro campo — se aplica la misma regla ya vigente (spec 006 FR-005).
- **FR-008**: Si la celda de la columna "Id" en una fila tiene un valor no numérico (y no vacío), esa fila DEBE
  marcarse como fallida con motivo claro ("Id [valor] no es un id válido").
- **FR-009**: El mecanismo de resolución de campos FK-por-nombre (Proveedor, Categoría, Condición de IVA, Tipo de
  Producto, Lista de Precios) NO DEBE cambiar de comportamiento — sigue aplicando igual tanto en filas de alta
  como de actualización, con la salvedad de FR-003 (si no está mapeado en una fila de actualización, el valor
  existente no se toca).
- **FR-010**: El resumen de la importación (spec 006) DEBE seguir distinguiendo importados/fallidos/advertencias;
  esta feature no agrega una categoría nueva al resumen — una actualización exitosa cuenta como fila procesada
  igual que un alta exitosa (no hace falta distinguir visualmente "creado" vs "actualizado" en el resumen para
  esta primera versión).
- **FR-011**: Las reglas de unicidad (ej. CUIT de Cliente/Proveedor) DEBEN evaluarse en una fila de actualización
  excluyendo al propio registro que se está actualizando (mismo criterio que el alta manual al editar), para no
  rechazar una fila que reenvía el mismo valor que el registro ya tiene.

### Key Entities *(include if feature involves data)*

- **Cliente, Proveedor, Producto**: entidades ya existentes (spec 006/026) — esta feature agrega la posibilidad de
  actualizar un registro existente identificado por su `id` (primary key), en vez de crear siempre un registro
  nuevo.
- **Mapeo de columnas**: se amplía el diccionario de campos destino con un campo nuevo, "Id", presente en las 3
  entidades — mismo ciclo de vida transitorio ya descrito en spec 006/026.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Un usuario puede corregir un dato de un grupo de clientes/proveedores/productos ya cargados subiendo
  un archivo con sólo la columna Id + las columnas a corregir, sin tener que volver a cargar el resto de los datos
  de cada registro ni editarlos uno por uno a mano.
- **SC-002**: El 100% de las filas cuya columna Id no matchea ningún registro existente se reportan como fila
  fallida con motivo claro, sin crear registros nuevos ni duplicados por error de tipeo en el Id.
- **SC-003**: El 100% de los campos no mapeados (o con celda vacía) en una fila de actualización conservan su valor
  previo después de la importación — ninguna actualización por Id borra datos que el usuario no quiso tocar.

## Assumptions

- **El id es el id interno del sistema actual, no un id de un sistema anterior**: esta feature resuelve
  actualización contra la primary key real de `clientes`/`proveedores`/`productos` de este CRM. Un archivo con la
  columna "Id" de la planilla del sistema viejo (ver spec 026 Assumptions, columna "Id" documentada como metadata
  sin destino) sigue sin tener destino útil salvo que el usuario haya exportado antes un archivo con los ids reales
  de este sistema (por ejemplo, el propio export/listado de Clientes/Proveedores/Productos de este CRM).
- **No se agrega exportación nueva**: esta feature no incluye agregar una columna "Id" al export/listado existente
  de Clientes/Proveedores/Productos si no la tuviera ya — se asume que el id ya es visible hoy (columna "Id" ya
  documentada en el listado de Productos, `docs/documentacion_principal_crm.md §2.2`; verificar lo mismo para
  Clientes/Proveedores al planear).
- **Sin detección de duplicados adicional**: fuera de la actualización por Id explícita de esta feature, sigue sin
  haber detección de duplicados por otros criterios (email, CUIT, nombre) — eso permanece fuera de alcance, igual
  que en spec 006.
- **Riesgo aceptado — Id de la entidad equivocada**: la búsqueda de "Id" siempre se hace contra la tabla de la
  solapa activa (`clientes`/`proveedores`/`productos` respectivamente) — un id pegado por error en la solapa
  equivocada (ej. un id de Proveedor en un archivo de Clientes) puede coincidir por casualidad con un Cliente real
  distinto y actualizarlo sin que el sistema tenga forma de detectar la confusión (los 3 id son secuencias
  independientes, sin ningún marcador que indique a qué entidad pertenecen). Se acepta como riesgo inherente al
  mecanismo de "actualizar por id" sin agregar una confirmación extra por fila — mismo nivel de confianza que se
  le da hoy al usuario en el alta manual.
