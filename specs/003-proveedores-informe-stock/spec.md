# Feature Specification: Proveedores + Informe de Stock

**Feature Branch**: `003-proveedores-informe-stock`

**Created**: 2026-07-24

**Status**: Draft

**Input**: User description: "Módulo Base de Datos → Proveedores + Informe de Stock (vista "Movimientos" de Productos). Re-relevamiento fiel a Contagram real, basado en docs/informe_contagram_base_de_datos.md (§3 Proveedores, §4.9 Informe de Stock) y docs/documentacion_principal_crm.md (§4.2, §5). Alcance: (1) CRUD completo de Proveedores como espejo de Clientes (mismos campos/patrones, con las diferencias documentadas: sin Apodo ML, "Categoría Compras" en vez de "Categoría Ventas", "Nota Interna" en vez de "Nota para el Cliente", sin lista_precio_id); (2) selector "Proveedor" de vuelta en el modal de Producto; (3) pantalla propia "Informe de Stock" (no un modal) con filtros (Usuario, Operación, Proveedor, Tipo de Producto, Productos, Estado del Producto), selector de rango de fechas, 3 KPIs (Unidades en Stock, Costo Total, Valor Venta Total) y tabla de movimientos con columna "Stock Saldo" (saldo corrido), accesible desde la acción "Movimientos" del menú de fila de Productos, filtrada por ese producto al entrar."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Alta y gestión de Proveedores (Priority: P1)

Como usuario del negocio, quiero cargar y administrar mi base de proveedores (datos comerciales,
de contacto y fiscales) para poder asociarlos a los productos que compro, igual que ya hago con
Clientes.

**Why this priority**: Es la base de datos que habilita todo lo demás del spec — sin Proveedores
no hay selector de Proveedor en Productos ni filtro de Proveedor en el Informe de Stock. Además es
funcionalmente independiente y demostrable por sí sola (un listado + alta/edición/baja funcionando
es valor entregable aunque nada más del spec estuviera listo).

**Independent Test**: Se puede probar completamente creando, editando, inactivando y eliminando un
proveedor desde Base de Datos → Proveedores, sin depender de Productos ni del Informe de Stock.

**Acceptance Scenarios**:

1. **Given** el listado de Proveedores vacío, **When** el usuario carga un proveedor con sólo el
   campo "Proveedor" (nombre/razón social) completo, **Then** el proveedor se crea y aparece
   resaltado en el listado (igual que en Clientes).
2. **Given** un proveedor cargado con CUIT inválido, **When** el usuario intenta guardarlo,
   **Then** el sistema no bloquea el guardado si el campo CUIT quedó vacío, pero si se completó
   con un CUIT matemáticamente inválido, rechaza el guardado con el mensaje de error
   correspondiente (mismo comportamiento que ya existe en Clientes vía `CuitValido`).
3. **Given** un proveedor con productos asociados (`productos.proveedor_id`), **When** el usuario
   intenta eliminarlo físicamente, **Then** el sistema rechaza la eliminación física y sólo permite
   inactivarlo (mismo patrón que Cliente/Producto: "no se puede eliminar con operaciones/relaciones
   asociadas").
4. **Given** el formulario de alta de Proveedor, **When** el usuario lo compara campo a campo con
   el de Cliente, **Then** encuentra los mismos bloques (datos generales, personas de contacto,
   campos personalizados, saldo inicial, datos de facturación) salvo las diferencias documentadas:
   sin "Apodo ML"; el bloque "Ventas" se llama "Compras" con "Categoría Compras" (categorías
   `tipo=compra`) y sin "Lista de Precios"; "Nota para el Cliente" se llama "Nota Interna".

---

### User Story 2 - Asociar un Proveedor a un Producto (Priority: P2)

Como usuario, quiero elegir el proveedor habitual de un producto al cargarlo o editarlo, para
saber a quién comprarle cuando haga falta reponer stock.

**Why this priority**: Depende de que exista la base de Proveedores (User Story 1), pero es un
cambio acotado sobre una pantalla ya construida (Productos) — el modelo/columna `proveedor_id` y
la migración ya existen (no se tocaron cuando se descartó el módulo Proveedores en su momento,
sólo se quitaron la relación Eloquent, la UI y el filtro). Reactivarlo es de bajo esfuerzo una vez
lista la User Story 1.

**Independent Test**: Con al menos un proveedor activo cargado, se puede abrir el modal "Nuevo
Producto"/"Editar Producto", elegir un proveedor del selector, guardar, y verificar que la columna
"Proveedor" del listado y el filtro por Proveedor del panel de Filtros de Productos reflejan esa
asociación.

**Acceptance Scenarios**:

1. **Given** al menos un proveedor activo, **When** el usuario abre "Nuevo Producto", **Then** ve
   un selector "Proveedor" (Select2 con buscador) poblado con los proveedores activos, opcional.
2. **Given** un producto con proveedor asignado, **When** el usuario lo edita, **Then** el
   selector de Proveedor viene precargado con el proveedor actual.
3. **Given** el listado de Productos, **When** el usuario aplica el filtro "Proveedor", **Then**
   la tabla muestra sólo los productos de ese proveedor, y la columna "Proveedor" del listado
   muestra el nombre correspondiente.

---

### User Story 3 - Informe de Stock (pantalla propia, ex-"Movimientos") (Priority: P3)

Como usuario, quiero ver el historial completo de movimientos de stock de mis productos —con
filtros, rango de fechas, KPIs de valorización y el saldo de stock después de cada movimiento— en
una pantalla de informe dedicada, en vez de un modal simple sin filtros, para poder auditar cómo
llegó un producto a su stock actual y comparar valorización de inventario en distintos períodos.

**Why this priority**: Depende de la User Story 2 (el filtro "Proveedor" del informe necesita que
Productos tenga proveedor asociado) y reemplaza una pantalla que hoy ya funciona de forma más
simple (el modal de ajuste+histórico actual no se elimina como *funcionalidad* de ajuste, sólo se
reemplaza la parte de "ver histórico" por esta pantalla nueva). Por eso va último: es la pieza más
grande y la que menos bloquea al resto si quedara para una iteración siguiente.

**Independent Test**: Desde el menú de fila de un producto en Productos, la acción "Movimientos"
navega a `/informes/stock` (o ruta equivalente) pre-filtrada por ese producto, mostrando de
inmediato sus movimientos con saldo corrido, sin pasar por Proveedores ni por la User Story 2 si
el producto no tiene proveedor asignado (el filtro Proveedor simplemente queda vacío/"Todos").

**Acceptance Scenarios**:

1. **Given** un producto con movimientos de stock (ajustes, transferencias, registro inicial),
   **When** el usuario elige "Movimientos" desde su menú de fila, **Then** navega a la pantalla
   "Informe de Stock" con el filtro "Productos" pre-cargado con ese producto y la tabla ya muestra
   sus movimientos ordenados por fecha.
2. **Given** la pantalla de Informe de Stock, **When** el usuario aplica un rango de fechas y un
   filtro de Proveedor, **Then** la tabla y los 3 KPIs (Unidades en Stock, Costo Total, Valor Venta
   Total) se recalculan sólo sobre los productos/movimientos que matchean los filtros vigentes.
3. **Given** una fila de la tabla de movimientos, **When** el usuario la revisa, **Then** además de
   Fecha, Operación, Detalle, Producto y Cantidad, ve la columna **"Stock Saldo"**: el stock
   resultante de ese producto (y depósito) inmediatamente después de aplicarse ese movimiento.
4. **Given** el filtro "Operación" del Informe de Stock, **When** el usuario lo abre, **Then** ve
   únicamente los tipos de operación que el sistema genera hoy (Ajuste, Transferencia, Registro
   inicial) — no aparecen tipos como "Compra" o "Venta" todavía, porque esos módulos no existen.
   Ver nota en Assumptions sobre esta extensión futura.

---

### Edge Cases

- ¿Qué pasa si se intenta borrar físicamente un proveedor con productos asociados (`proveedor_id`
  apuntando a él)? → Debe rechazarse (o el producto debe quedar con `proveedor_id = null` vía
  `nullOnDelete`, a decidir en el plan técnico) — nunca dejar una FK huérfana silenciosa.
- ¿Qué pasa si un producto tiene proveedor asignado y ese proveedor se inactiva (no se elimina)? →
  El producto conserva la referencia; el proveedor simplemente deja de aparecer en el selector de
  "Elija Proveedor" para **nuevas** asignaciones (mismo patrón que clientes/productos inactivos).
- ¿Qué pasa si el Informe de Stock se abre sin filtrar por ningún producto (acceso directo, no
  desde "Movimientos" de un producto puntual)? → Debe mostrar el histórico completo de todos los
  productos, paginado, con los filtros disponibles para acotar.
- ¿Qué pasa con productos/variantes que tienen movimientos en más de un depósito? → El "Stock
  Saldo" de cada fila es el saldo del depósito de **ese** movimiento puntual, no el stock total del
  producto sumando todos los depósitos (ese dato ya existe aparte, en la columna "Stock total" del
  listado de Productos).
- ¿Qué pasa si dos movimientos del mismo producto/depósito comparten exactamente la misma fecha?
  → El saldo corrido se calcula respetando el orden de creación (`id`) como desempate, no sólo la
  fecha, para que el cálculo sea determinístico.

## Requirements *(mandatory)*

### Functional Requirements

**Proveedores (US1)**

- **FR-001**: El sistema DEBE permitir crear, ver, editar, inactivar/reactivar y eliminar
  proveedores, con el campo "Proveedor" (nombre/razón social) como único obligatorio.
- **FR-002**: El sistema DEBE incluir en el formulario de Proveedor los mismos bloques que
  Cliente (datos generales, personas de contacto ilimitadas, campos personalizados propios del
  proveedor, saldo inicial, datos de facturación), con estas diferencias respecto de Cliente:
  sin "Apodo ML"; el bloque "Ventas" se reemplaza por "Compras" con "Categoría Compras" (categorías
  `tipo=compra`) y sin selector de Lista de Precios; "Nota para el Cliente" se reemplaza por "Nota
  Interna".
- **FR-003**: El sistema DEBE validar el CUIT/CUIL del proveedor con la misma regla que Cliente
  (rechaza sólo si está completo y es matemáticamente inválido; vacío es válido).
- **FR-004**: El listado de Proveedores DEBE mostrar las mismas 15 columnas relevadas en Contagram
  real (Id, Proveedor, Nombre, Apellido, Mail, Teléfono, Teléfono Celular, Domicilio, Localidad,
  Provincia, DNI, CUIT, Condición de IVA, Nota, Página Web — sin "Usuario de Mercado Libre", que es
  exclusivo de Clientes), con buscador global, selector de columnas y exportación a Excel/CSV.
- **FR-005**: El menú de fila de Proveedores DEBE incluir Ver, Editar, Inactivar/Reactivar y
  Eliminar (Eliminar rechaza si el proveedor tiene productos asociados). La acción "Cta Cte" de
  Contagram real queda fuera de alcance (depende de Compras/Tesorería, no implementados) y se
  documenta como brecha conocida, no como bug.
- **FR-006**: El sistema NO DEBE permitir eliminar físicamente un proveedor que tenga al menos un
  producto con `proveedor_id` apuntando a él.

**Selector de Proveedor en Productos (US2)**

- **FR-007**: El modal de alta/edición de Producto DEBE incluir un selector "Proveedor" con
  buscador, opcional, poblado con proveedores activos, reemplazando la relación que fue removida al
  descartarse el módulo Proveedores.
- **FR-008**: El listado de Productos DEBE volver a mostrar la columna "Proveedor" y el filtro por
  Proveedor en el panel de Filtros (ambos habían sido retirados junto con el modelo Proveedor).

**Informe de Stock (US3)**

- **FR-009**: El sistema DEBE proveer una pantalla propia de "Informe de Stock" (no un modal),
  accesible como ruta independiente, con: panel de Filtros (Usuario, Operación, Proveedor, Tipo de
  Producto, Productos, Estado del Producto/Servicio), selector de rango de fechas, y una DataTable
  server-side de movimientos.
- **FR-010**: El Informe de Stock DEBE mostrar 3 KPIs recalculados según los filtros vigentes:
  Unidades en Stock, Costo Total y Valor Venta Total (misma fórmula ya usada en los KPIs de
  Productos: cantidad en stock × costo / precio de venta).
- **FR-011**: La tabla de movimientos del Informe de Stock DEBE incluir, por fila: Fecha,
  Operación (tipo de movimiento), Detalle/Descripción, Producto, Cantidad, **Stock Saldo** (el
  stock del producto+depósito+variante inmediatamente después de ese movimiento) y Usuario.
- **FR-012**: La acción "Movimientos" del menú de fila de Productos DEBE navegar al Informe de
  Stock con el filtro "Productos" pre-cargado con ese producto específico.
- **FR-013**: El filtro "Operación" del Informe de Stock DEBE listar únicamente los tipos de
  movimiento que el sistema genera actualmente (`ajuste`, `transferencia`, y el caso particular de
  "Registro inicial" dentro de `ajuste`) — queda como extensión futura (no bloqueante de este spec)
  agregar los tipos `entrada`/`salida` que se generarán cuando existan Compras y Ventas.
- **FR-014**: El sistema DEBE seguir soportando el ajuste manual de stock (Aumento/Disminución/
  Transferencia) exactamente como hoy — este spec agrega la pantalla de informe, no reemplaza el
  flujo de carga de ajustes.

### Key Entities *(include if feature involves data)*

- **Proveedor**: espejo de Cliente — datos comerciales, personas de contacto (`proveedor_contactos`),
  campos personalizados propios (JSON), datos de facturación, saldo inicial, categoría de compra.
  Se relaciona opcionalmente con Producto (`productos.proveedor_id`, columna ya existente).
- **ProveedorContacto**: personas de contacto de un proveedor (1..N), espejo de `cliente_contactos`.
- **Informe de Stock (vista, no tabla nueva)**: proyección sobre `movimientos_stock` + `stocks` +
  `productos` + `proveedores` (vía `producto.proveedor_id`) + `usuarios`, con el saldo corrido
  calculado por fila (no persistido como columna nueva salvo que el plan técnico determine que
  conviene cachearlo).

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Un usuario puede cargar un proveedor nuevo con sólo el campo obligatorio en menos de
  15 segundos, igual que hoy puede hacerlo con un cliente.
- **SC-002**: El 100% de los productos existentes pueden asociarse a un proveedor sin perder datos
  ni requerir recarga de página (alta AJAX, sin refresh).
- **SC-003**: Al abrir "Movimientos" desde cualquier producto con historial de stock, el usuario ve
  el saldo corrido correcto de cada movimiento (verificable sumando manualmente contra el stock
  actual del producto) en el 100% de los casos probados.
- **SC-004**: Los 3 KPIs del Informe de Stock coinciden, en un caso sin filtros aplicados, con la
  suma exacta de stock/costo/precio de venta de todos los productos activos (mismo cálculo que las
  cards de Productos, verificable por comparación directa).
- **SC-005**: La estructura de pantalla (columnas del listado de Proveedores, campos del modal,
  filtros del Informe de Stock) coincide 1 a 1 con lo relevado en
  `docs/informe_contagram_base_de_datos.md` §3 y §4.9, salvo las diferencias documentadas
  explícitamente en este spec.

## Assumptions

- El modelo `Proveedor`/`ProveedorContacto` y sus migraciones se reconstruyen desde cero (fueron
  borrados junto con el código descartado); no hay datos previos que migrar.
- La columna `productos.proveedor_id` y su migración original **no fueron eliminadas** al
  descartarse el módulo Proveedores (sólo se quitó la relación Eloquent, la UI y el filtro) — este
  spec asume que sigue existiendo en el esquema actual y la reutiliza tal cual; el plan técnico debe
  verificarlo antes de escribir migraciones nuevas para evitar duplicar la columna.
- El Informe de Stock es una pantalla de sólo lectura (filtros + tabla + KPIs); no incluye edición
  ni eliminación de movimientos desde ahí — el ajuste sigue siendo por la acción "Ajuste de Stock"
  ya existente.
- **Dependencia futura reconocida y aceptada (no bloqueante):** el filtro "Operación" y el tipo de
  movimiento del Informe de Stock hoy sólo cubren `ajuste`/`transferencia` (incluyendo "Registro
  inicial"). Cuando se construyan Compras y Ventas, esos módulos generarán movimientos `entrada`/
  `salida` que el Informe de Stock deberá empezar a listar también — se documenta como punto de
  conexión futuro (no como brecha a resolver ahora) para no perderlo de vista al especificar Compras
  más adelante.
- "Cta Cte" del menú de fila de Proveedores queda fuera de alcance de este spec (depende de
  Compras/Tesorería), igual que ya está documentado para Clientes.
- El campo "Usuario" del filtro del Informe de Stock filtra por el usuario que generó el
  movimiento (`movimientos_stock.usuario_id`), reutilizando la tabla `usuarios` ya existente — no
  requiere ninguna entidad nueva.
- Toda pantalla nueva de este spec reutiliza el patrón ya vigente en Clientes/Productos: DataTable
  AJAX server-side para listados, alta/edición/baja por modal Bootstrap vía AJAX sin recargar,
  notificaciones con toasts, y selects con buscador para datos dinámicos (reglas de diseño
  obligatorias de `CLAUDE.md`).
- Por el principio de fidelidad estructural a Contagram (`CLAUDE.md`), la estructura de pantalla de
  Proveedores y del Informe de Stock se valida contra `docs/informe_contagram_base_de_datos.md` §3
  y §4.9 antes de dar el spec por implementado, no sólo contra estos Functional Requirements.
