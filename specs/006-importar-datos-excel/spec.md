# Feature Specification: Importar Datos por Excel

**Feature Branch**: `006-importar-datos-excel`

**Created**: 2026-07-24

**Status**: Draft

**Input**: User description: "Importar Datos por Excel (Base de Datos → Clientes/Proveedores/Productos & Servicios → botón 'Importar datos'). Fuente de verdad: docs/informe_contagram_base_de_datos.md §2.6 y §4.10, con captura capturas/nuevas/28. Pantalla compartida (3 solapas en este sistema: Clientes, Proveedores, Productos & Servicios — Contagram real tiene 4, separando Productos/Servicios, pero acá es un solo modelo con campo tipo). Flujo: (1) Seleccionar Archivo .xls/.xlsx/.csv máx 10MB, (2) vista previa + mapeo de columnas del archivo a campos del sistema (incluye mapeo a 'campos personalizados' para columnas sin campo fijo correspondiente), (3) confirmar importación (cancelable en cualquier momento antes de confirmar, atómico). Para Productos, columna 'Proveedor' matcheada por nombre contra proveedores existentes/recién importados — se recomienda importar Proveedores antes que Productos. Reutiliza reglas de validación ya existentes (CuitValido, campos personalizados JSON) por fila; el asistente es una pantalla dedicada con pasos (no un modal), a diferencia del resto de la app."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Importar Clientes desde un archivo Excel (Priority: P1)

Como usuario del negocio, quiero subir un archivo Excel/CSV con mi cartera de clientes existente y
mapear sus columnas a los campos del sistema, para cargar mi base de datos de una sola vez en vez de
tipear cliente por cliente.

**Why this priority**: Es el caso de uso principal (la cartera de clientes suele ser el dato más
grande que un negocio ya tiene en otro lado al migrar a un CRM nuevo) y establece el mecanismo
completo (subir → vista previa → mapear columnas → confirmar/cancelar) que las otras dos historias
reutilizan tal cual.

**Independent Test**: Se puede probar completamente subiendo un archivo con un puñado de clientes de
prueba, mapeando sus columnas, confirmando la importación, y verificando que aparecen en el listado
de Clientes con los datos correctos — sin depender de Proveedores ni de Productos.

**Acceptance Scenarios**:

1. **Given** la pantalla "Importar Datos" en la solapa Clientes, **When** el usuario selecciona un
   archivo `.xlsx` válido de menos de 10MB, **Then** el sistema muestra una vista previa de las
   primeras filas del archivo con sus columnas detectadas.
2. **Given** la vista previa mostrada, **When** el usuario asigna cada columna del archivo a un
   campo del sistema (Nombre, Apellido, Teléfono, Email, Domicilio, CUIT, etc.) o la marca como "no
   importar", **Then** el sistema recuerda ese mapeo para el paso de confirmación.
3. **Given** una columna del archivo que no corresponde a ningún campo fijo de Cliente, **When** el
   usuario la mapea como "campo personalizado" indicando un nombre, **Then** esa columna se importa
   como un campo personalizado propio de cada cliente creado (mismo mecanismo ya usado en el alta
   manual de Cliente).
4. **Given** el mapeo de columnas completo, **When** el usuario confirma la importación, **Then** el
   sistema crea un cliente por cada fila válida del archivo, valida cada uno con las mismas reglas
   que el alta manual (ej. CUIT matemáticamente inválido si viene mapeado), y muestra un resumen:
   cuántos se importaron correctamente y cuántas filas fallaron (con el motivo por fila).
5. **Given** el asistente en cualquier paso antes de confirmar, **When** el usuario cancela,
   **Then** no se crea ningún cliente — la cancelación es completa, no deja importaciones parciales.
6. **Given** un archivo de más de 10MB o con una extensión no soportada, **When** el usuario intenta
   subirlo, **Then** el sistema lo rechaza antes de procesarlo, con un mensaje claro del límite/
   formato permitido.

---

### User Story 2 - Importar Proveedores desde un archivo Excel (Priority: P2)

Como usuario, quiero importar mi lista de proveedores de la misma forma que importo clientes, para
tener mi base de proveedores cargada antes de importar productos y poder asociarlos.

**Why this priority**: Reutiliza el mecanismo completo de la User Story 1 sin cambios de fondo (sólo
cambia el conjunto de campos destino: los de Proveedor en vez de Cliente) — bajo esfuerzo incremental
una vez que la User Story 1 está lista. Es P2 porque el negocio típicamente importa primero su
cartera de clientes.

**Independent Test**: Con la solapa Proveedores de la misma pantalla, subir un archivo de
proveedores de prueba, mapear columnas, confirmar, y verificar que aparecen en el listado de
Proveedores — sin depender de Clientes ni de Productos.

**Acceptance Scenarios**:

1. **Given** la solapa Proveedores de "Importar Datos", **When** el usuario sube un archivo y lo
   mapea a los campos de Proveedor (Proveedor/Nombre, Categoría Compras, CUIT, etc.), **Then** el
   comportamiento es idéntico al de Clientes (vista previa, mapeo, campos personalizados,
   confirmación con resumen, cancelación completa).

---

### User Story 3 - Importar Productos & Servicios, asociados a su Proveedor (Priority: P3)

Como usuario, quiero importar mi catálogo de productos incluyendo a qué proveedor le compro cada
uno, para no tener que asignar el proveedor manualmente producto por producto después.

**Why this priority**: Depende conceptualmente de que existan Proveedores cargados (User Story 2)
para que la columna "Proveedor" del archivo tenga contra qué matchear — por eso va último, aunque
técnicamente puede usarse sin proveedores (la columna simplemente queda sin asociar). Es la historia
con la regla de negocio adicional (matcheo por nombre) que las otras dos no tienen.

**Independent Test**: Con al menos un proveedor ya cargado (manual o importado), subir un archivo de
productos con una columna "Proveedor" con nombres que coincidan, confirmar la importación, y
verificar que cada producto quedó asociado al proveedor correcto en el listado de Productos.

**Acceptance Scenarios**:

1. **Given** la solapa "Productos & Servicios", **When** el usuario mapea una columna del archivo al
   campo "Proveedor", **Then** el sistema busca, para cada fila, un proveedor existente cuyo nombre
   coincida (sin distinguir mayúsculas/acentos) con el valor de esa columna.
2. **Given** una fila cuyo valor de Proveedor no matchea ningún proveedor existente, **When** se
   confirma la importación, **Then** el producto de esa fila se crea igual, sin proveedor asociado,
   y el resumen final lo informa como advertencia (no como error que bloquee la fila completa).
3. **Given** una columna mapeada a "Tipo" con valores "Producto"/"Servicio" (o ausente), **When** se
   confirma la importación, **Then** cada fila respeta el tipo indicado, o usa "Producto" por
   defecto si la columna no vino mapeada o el valor de la celda está vacío.

---

### Edge Cases

- ¿Qué pasa si el archivo no tiene columnas coincidentes con ningún campo obligatorio (ej. sin
  ninguna columna que se pueda mapear a "Nombre"/"Proveedor")? → El sistema no permite avanzar a la
  confirmación hasta que el campo obligatorio de la entidad tenga alguna columna mapeada.
- ¿Qué pasa si dos columnas del archivo se mapean accidentalmente al mismo campo del sistema? → Se
  rechaza esa combinación de mapeo con un mensaje explícito, antes de llegar a confirmar.
- ¿Qué pasa si una fila individual falla la validación (ej. CUIT inválido) pero el resto del archivo
  es válido? → Sólo esa fila se omite (no se importa); el resto del archivo se importa igual, y el
  resumen final detalla cuáles filas fallaron y por qué (falla parcial por fila, no aborta todo el
  archivo).
- ¿Qué pasa si se importa el mismo archivo dos veces? → Cada importación crea registros nuevos (no
  hay detección de duplicados en esta versión); queda documentado como comportamiento esperado, no
  como bug (ver Assumptions).
- ¿Qué pasa si el archivo tiene miles de filas? → El sistema procesa todas las filas de la
  importación confirmada; no hay un límite artificial de cantidad de filas, sólo el límite de
  tamaño de archivo (10MB) ya relevado.

## Requirements *(mandatory)*

### Functional Requirements

**Mecanismo compartido (US1, reutilizado por US2/US3)**

- **FR-001**: El sistema DEBE ofrecer una pantalla "Importar Datos" con una solapa por entidad
  (Clientes, Proveedores, Productos & Servicios), accesible desde el botón "Importar datos" ya
  esperado en cada uno de esos tres listados.
- **FR-002**: El sistema DEBE permitir subir un archivo en formato `.xls`, `.xlsx` o `.csv` de hasta
  10MB, rechazando antes de procesar cualquier archivo que no cumpla formato o tamaño.
- **FR-003**: Tras subir el archivo, el sistema DEBE mostrar una vista previa de sus primeras filas
  con las columnas detectadas, y permitir asignar cada columna a un campo del sistema de la entidad
  correspondiente, o marcarla como "no importar".
- **FR-004**: El sistema DEBE permitir mapear una columna sin campo fijo correspondiente a un "campo
  personalizado" (con nombre definido por el usuario), reutilizando el mecanismo de campos
  personalizados ya existente en Cliente/Proveedor.
- **FR-005**: El sistema NO DEBE permitir confirmar la importación sin que el campo obligatorio de
  la entidad (Nombre en Cliente/Proveedor, Nombre en Producto) tenga alguna columna mapeada, ni con
  dos columnas mapeadas al mismo campo destino.
- **FR-006**: Al confirmar, el sistema DEBE validar cada fila con las mismas reglas que el alta
  manual de esa entidad (ej. `CuitValido` si la columna CUIT viene mapeada), crear un registro por
  fila válida, y mostrar un resumen final: cantidad importada y detalle de las filas no importadas
  con su motivo — sin abortar el resto del archivo por una fila inválida.
- **FR-007**: El usuario DEBE poder cancelar el asistente en cualquier paso antes de confirmar, sin
  que quede ningún registro creado de esa sesión de importación.

**Proveedores (US2)**

- **FR-008**: La solapa Proveedores DEBE ofrecer el mismo mecanismo (FR-001 a FR-007) mapeando a los
  campos de Proveedor en vez de Cliente.

**Productos & Servicios (US3)**

- **FR-009**: La solapa Productos & Servicios DEBE permitir mapear una columna "Proveedor", que el
  sistema resuelve por fila buscando un proveedor existente cuyo nombre coincida (sin distinguir
  mayúsculas/acentos) con el valor de esa celda; si no encuentra coincidencia, el producto se crea
  igual sin proveedor asociado, reportado como advertencia (no como fila fallida).
- **FR-010**: La solapa Productos & Servicios DEBE permitir mapear una columna "Tipo"
  (Producto/Servicio); si no se mapea o la celda viene vacía, el producto se crea con tipo
  "Producto" por defecto.
- **FR-011**: El sistema DEBE mostrar, en algún punto visible del flujo de Productos & Servicios, la
  recomendación de importar primero Proveedores si se quiere asociar productos a su proveedor por
  defecto (mismo texto relevado en Contagram real).

### Key Entities *(include if feature involves data)*

- **Cliente, Proveedor, Producto**: entidades ya existentes, sin cambios de esquema — esta feature
  sólo agrega una vía de creación masiva por archivo, con las mismas reglas de validación que el
  alta manual.
- **Mapeo de columnas (estado transitorio del asistente, no persistido)**: asociación entre una
  columna detectada del archivo subido y un campo destino (fijo o campo personalizado) de la
  entidad elegida; vive sólo durante la sesión del asistente, se descarta al confirmar o cancelar.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Un usuario puede importar un archivo de 50 clientes, mapear sus columnas y confirmar
  la importación en menos de 3 minutos.
- **SC-002**: El 100% de las filas inválidas de un archivo (ej. CUIT matemáticamente incorrecto) se
  excluyen sin impedir que el resto de las filas válidas se importe.
- **SC-003**: El 100% de los productos importados con una columna "Proveedor" que coincide
  exactamente con un proveedor ya cargado quedan asociados a ese proveedor sin intervención manual
  posterior.
- **SC-004**: La estructura de la pantalla (solapas, "Seleccionar Archivo", formatos permitidos,
  paneles "Acerca de la importación"/"Notas Técnicas", botón para volver al listado) coincide con lo
  relevado en `docs/informe_contagram_base_de_datos.md` §2.6, salvo la unificación documentada de
  Productos/Servicios en una sola solapa.

## Assumptions

- **Divergencia documentada**: Contagram real separa Productos y Servicios en 2 solapas; este
  sistema los unifica en una ("Productos & Servicios") porque ya son un único modelo (`Producto`
  con campo `tipo`), evitando duplicar el mecanismo de mapeo de columnas para la misma entidad.
- **Sin detección de duplicados en esta versión**: importar el mismo archivo dos veces crea
  registros nuevos cada vez (no se matchea contra registros ya existentes por nombre/CUIT/código).
  Se documenta como límite conocido de v1, no como bloqueante — el usuario controla qué archivo sube
  y cuándo.
- El video "Cómo importar" y el link "Tips Para Importar" relevados en Contagram real son contenido
  de ayuda editorial (no funcionalidad) — quedan fuera de alcance de esta spec; se puede agregar un
  link a documentación propia del proyecto si se desea, sin bloquear la feature.
- Los campos por defecto esperados de cada entidad son los mismos ya usados en su alta manual
  (Cliente: los del formulario "Nuevo Cliente"; Proveedor: los de "Nuevo Proveedor"; Producto: los
  de "Nuevo Producto", incluyendo el campo "Proveedor" ya reincorporado en `003-proveedores-informe-stock`).
- El asistente de importación es la única pantalla de la app que navega por **pasos en páginas
  reales** en vez de un modal — así es la estructura relevada en Contagram real (pantalla dedicada
  `/import_data`), y se documenta como excepción intencional a la regla general de "todo por modal"
  del proyecto (`CLAUDE.md`).
- No se agrega ninguna cola/procesamiento en background para archivos grandes en esta versión — el
  procesamiento de la importación confirmada es síncrono; si el volumen real del negocio lo
  justifica más adelante, es una optimización de plan técnico, no un cambio de esta spec.
