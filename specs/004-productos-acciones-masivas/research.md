# Research: Selección Múltiple y Acciones Masivas en Productos

## 1. Cómo manejar la selección de filas sin agregar una librería nueva

**Pregunta**: DataTables tiene una extensión oficial "Select" para checkboxes de fila. ¿La
agregamos, o alcanza con JS plano?

**Decisión**: JS plano (sin la extensión "Select" de DataTables). Se agrega una columna de checkbox
**renderizada en cliente** (`data: null`, no viene del servidor) y se mantiene un `Set` de IDs
seleccionados en memoria, más un flag `seleccionarTodosLosQueMatchean` (booleano) para el modo
"Seleccionar los N productos".

**Rationale**:
- El proyecto ya tiene un patrón propio de selector de columnas "casero" en `productos.js`
  (`construirMenuColumnas()`, sin usar el plugin ColVis de DataTables) — es consistente seguir el
  mismo criterio de "JS mínimo antes que sumar un plugin" para una necesidad puntual.
- La extensión "Select" de DataTables gestiona bien la selección de filas **visibles**, pero el modo
  "seleccionar todos los N que matchean el filtro" (más allá de la página actual) igual requiere
  lógica custom por encima de la extensión — no elimina la necesidad de código propio, sólo cambia
  dónde vive una parte de él. No vale la pena la dependencia nueva para el resto que sí resuelve.
- El checkbox de header ("seleccionar todo") sólo afecta la página visible (FR-002, confirmado
  contra Contagram real) — comportamiento trivial de replicar con jQuery puro sobre las filas
  actuales del DOM.

**Alternativas consideradas**:
- *Agregar `datatables.net-select`*: rechazada — dependencia nueva para un problema que ya se
  resuelve con `<40` líneas de JS, y que de todos modos no cubre el modo "todos los que matchean el
  filtro" sin código adicional.
- *Persistir la selección en el servidor (sesión)*: rechazada — la spec (FR-004) exige que la
  selección se limpie al cambiar de página/filtro/orden; no hace falta persistencia de servidor
  para eso, sólo estado en memoria del cliente.

## 2. Cómo resolver "seleccionar los N que matchean el filtro" sin mandar miles de IDs

**Pregunta**: si el usuario elige "Seleccionar los N productos" (más allá de la página visible),
¿cómo le llegan esos IDs a la acción masiva sin mandar un array gigante por POST?

**Decisión**: el frontend NO arma la lista de IDs en ese modo. En vez de eso, manda un flag
`todos: true` + los mismos parámetros de filtro/búsqueda que ya usa `productos.data` (estado, tipo,
tipo_producto_id, proveedor_id, id, buscar, stock_min, stock_max). El backend, en
`ProductoController::accionesMasivas()`, si recibe `todos: true`, reconstruye el mismo query que
`queryFiltrada()` ya usa para la DataTable y aplica la acción sobre ese resultado completo
(sin paginar). Si no viene `todos`, usa el array explícito de `ids[]` seleccionados a mano.

**Rationale**: reutiliza `queryFiltrada()` tal cual (ya centraliza todos los filtros del panel), es
consistente por construcción con lo que el usuario está viendo en pantalla en ese momento, y evita
un payload de red proporcional a la cantidad de productos.

**Alternativas consideradas**:
- *Mandar todos los IDs igual, aunque sean miles*: rechazada — no escala bien y es responsabilidad
  duplicada (el frontend ya no tiene por qué "saber" cuáles son todos los IDs; el backend ya sabe
  resolver ese filtro).

## 3. Atomicidad del lote: todo-o-nada vs. por-producto

**Pregunta**: si dentro de un lote de 20 productos, uno de ellos hace fallar la validación (ej. un
precio negativo por algún dato corrupto), ¿se aplica a los otros 19 o se aborta todo?

**Decisión**: dos comportamientos distintos según el tipo de acción (ya definido en Assumptions del
spec):
- Acciones de **valor único** (precio, costo, estado, IVA, tipo de producto, proveedor, mostrar en
  ventas/compras): **todo o nada**. Se valida el valor UNA vez (no depende de cada producto
  individual — es el mismo valor para todos), así que si el valor es inválido, ninguno se
  actualiza. Implementación: `StoreProductoRequest`/`ReglasProducto` ya validan el valor en
  aislado (ej. `precio_venta` ≥ 0); se reutiliza esa regla contra el valor recibido antes de tocar
  la base, dentro de una `DB::transaction()`.
- **Eliminar Masivamente**: **por producto**. La condición de exclusión (`tieneOperaciones()`) es
  una propiedad de cada producto, no del valor enviado — no tiene sentido abortar todo el lote
  porque uno de ellos tiene movimientos de stock.

**Rationale**: refleja la naturaleza de cada operación — hay un solo input (mismo precio) vs. N
condiciones independientes (cada producto puede o no tener operaciones).

## 4. Reuso de patrones existentes (sin research adicional)

- **DataTable server-side + modal AJAX + toasts**: mismo patrón de `ProductoController`/
  `productos.js` ya construido en 002-productos.
- **Select2 en los selects del modal** (Tipo de Producto, Proveedor): mismo patrón ya usado en el
  modal de alta/edición de Producto (`dropdownParent` al modal de Acciones Masivas).
- **Validación de precio/costo/IVA**: reutiliza las reglas ya definidas en
  `ReglasProducto::reglasProducto()` en vez de duplicarlas.
- **Regla "no eliminar con operaciones asociadas"**: reutiliza `Producto::tieneOperaciones()` ya
  existente, mismo criterio que `ProductoController::destroy()`.
