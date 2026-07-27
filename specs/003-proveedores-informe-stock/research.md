# Research: Proveedores + Informe de Stock

## 1. Estado real de `productos.proveedor_id`

**Pregunta (Technical Context, plan.md)**: ¿la columna `proveedor_id` de `productos` tiene una FK
real a nivel de base de datos, o quedó como entero simple sin constraint?

**Decisión**: Es un entero simple (`unsignedBigInteger`, nullable, indexado) **sin FK a nivel de
base de datos**. La migración `2026_07_19_060002_create_productos_table.php` sólo agrega la
`$table->foreign('proveedor_id')->...` dentro de un `if (Schema::hasTable('proveedores'))`, y esa
migración corrió **antes** de que existiera `proveedores` (por orden de fecha: productos =
2026-07-19, proveedores original = 2026-07-20) — la condición nunca se cumplió, ni siquiera antes de
que se borrara el módulo Proveedores la primera vez.

**Rationale**: Confirmado leyendo el archivo de migración ya aplicado en la base (no se re-generó).
Como la migración de `productos` **ya corrió** en la base de datos existente, modificarla ahora no
tiene efecto — Laravel no vuelve a ejecutar migraciones ya aplicadas. Agregar la tabla `proveedores`
en una migración nueva (con timestamp posterior) tampoco agrega retroactivamente la FK a `productos`.

**Alternativas consideradas**:
- *Recrear la migración de `productos` con la FK incondicional*: rechazada — reescribir una
  migración ya corrida es peligroso (podría desincronizar el historial de migraciones si alguien
  hace `migrate:fresh`) y no resuelve el problema en la base de datos actual, que ya tiene la tabla.
- *Dejarlo sin FK de base de datos, sólo con chequeo a nivel aplicación*: **elegida como mínimo**,
  consistente con el patrón ya usado en Cliente/Producto (`tieneOperaciones()`, chequeo en el
  controller antes de `destroy()`, sin depender de `ON DELETE` de la base).
- *Agregar una migración nueva y chica que sólo agrega la FK faltante* (`ALTER TABLE productos ADD
  CONSTRAINT ... FOREIGN KEY (proveedor_id) REFERENCES proveedores(id) ON DELETE SET NULL`, corrida
  **después** de crear `proveedores`): **elegida como refuerzo adicional** (defensa en profundidad),
  no como único mecanismo — el chequeo de aplicación (FR-006) sigue siendo la fuente de verdad del
  mensaje de error al usuario ("no se puede eliminar, tiene productos asociados"), la FK de base es
  sólo para que un `DELETE` directo por SQL (fuera de la app) no deje una referencia colgando.

## 2. Cálculo de "Stock Saldo" (saldo corrido) sin romper con los filtros de pantalla

**Pregunta**: ¿cómo calcular el saldo de stock después de cada movimiento, sin que aplicar filtros
(fecha, tipo, proveedor) rompa el cálculo al excluir movimientos anteriores relevantes?

**Decisión**: Usar una función de ventana SQL (`SUM(cantidad) OVER (PARTITION BY producto_id,
variante_id, deposito_id ORDER BY fecha, id)`) calculada sobre **todo** `movimientos_stock` en una
subconsulta/CTE, y aplicar los filtros de pantalla (fecha, tipo de operación, proveedor del
producto, tipo de producto, producto puntual, estado) como condición **externa** sobre esa
proyección ya calculada — nunca como `WHERE` de la subconsulta que alimenta la ventana.

**Rationale**: MySQL 8 (ya en uso, XAMPP) soporta funciones de ventana nativamente, evitando
recorrer el histórico en PHP fila por fila (O(n) por producto en el motor, no N+1 desde la app). El
desempate por `id` además de `fecha` (ya usado en `MovimientoStock::orderByDesc('fecha')->
orderByDesc('id')` en `StockController::movimientos()`) asegura un orden determinístico cuando dos
movimientos comparten fecha exacta (Edge Case ya identificado en spec.md).

**Alternativas consideradas**:
- *Persistir el saldo corrido como columna en `movimientos_stock`*: rechazada — requeriría
  recalcular/reescribir filas históricas ante cualquier ajuste retroactivo (ej. borrar un movimiento
  antiguo, si algún día se permite), rompiendo la inmutabilidad implícita del histórico. Un valor
  derivado en la consulta es siempre consistente sin mantenimiento.
- *Calcularlo en PHP iterando los movimientos del producto en memoria*: rechazada como método
  principal por escalar mal con miles de movimientos y por reintroducir un cálculo que la base ya
  resuelve de forma nativa y más barata; se reserva sólo como fallback si en algún entorno MySQL <8
  apareciera (no es el caso de este proyecto).

## 3. Reutilización de patrones existentes (sin research adicional)

Estos puntos ya estaban resueltos por specs previas (001-clientes, 002-productos) y se reutilizan
tal cual, sin nueva decisión de diseño:

- **CRUD + validación de CUIT**: `App\Rules\CuitValido`, patrón `ReglasCliente`/`StoreClienteRequest`
  se clona a `ReglasProveedor`/`StoreProveedorRequest` sin cambios de comportamiento.
- **DataTable server-side + modal AJAX + toasts**: mismo patrón de `ClienteController`/`clientes.js`.
- **Select2 en selects dinámicos**: mismo patrón de `productos.js` (`initSelect2`, `dropdownParent`
  para selects dentro de modales).
- **KPIs en $ (Costo Total / Valor Venta Total)**: misma fórmula ya implementada en
  `ProductoController::estadisticas()` (cantidad en stock × costo/precio), reutilizada tal cual para
  los KPIs del Informe de Stock (a nivel global de los productos que matchean los filtros vigentes,
  no sólo el producto puntual cuando se entra filtrado).
