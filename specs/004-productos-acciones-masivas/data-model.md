# Data Model — Selección Múltiple y Acciones Masivas en Productos (Fase 1)

Sin tablas ni columnas nuevas. Esta feature opera enteramente sobre la entidad `Producto` ya
existente (ver `002-productos`), aplicando en lote las mismas operaciones que ya existen para un
producto individual.

## Entidad operada: `Producto` (existente, sin cambios de esquema)

Campos afectados por cada acción masiva:

| Acción | Campo(s) afectado(s) | Validación reutilizada |
|---|---|---|
| Modificar Precio de Venta | `precio_venta` **y** `precios_producto.precio` de cada lista elegida — ajuste relativo (% o $, aumentar/disminuir) sobre el valor actual de cada producto, no un valor único para el lote | `['required','numeric']` por campo (`campos.*.valor`), `modo` en `porcentaje\|fijo`, `redondear` booleano |
| Modificar Costo | `costo` — mismo mecanismo de ajuste relativo que Precio de Venta, sin listas de precio | ídem, un solo campo `costo` |
| Mostrar en Ventas | `mostrar_en_ventas = true` | — (booleano fijo, sin input) |
| No Mostrar en Ventas | `mostrar_en_ventas = false` | — (booleano fijo, sin input) |
| Mostrar en Compras | `mostrar_en_compras = true` | — (booleano fijo, sin input) |
| No Mostrar en Compras | `mostrar_en_compras = false` | — (booleano fijo, sin input) |
| Modificar Estado | `activo` (Activo/Inactivo) | `['boolean']` |
| Modificar IVA por defecto | `iva_venta_pct` **e** `iva_compra_pct` — **selects independientes** (`valor_venta`/`valor_compra`), NO se fuerza el mismo valor en ambos (corrige la asunción original) | `Rule::in(array_keys(Producto::OPCIONES_IVA))` por campo, ambos opcionales pero al menos uno requerido |
| Modificar Tipo de Producto | `tipo_producto_id` — **dos valores independientes** (`valor_producto`/`valor_servicio`), aplicados según el `tipo` (producto/servicio) de cada fila del lote | `['nullable','integer','exists:tipos_producto,id']` por campo, al menos uno requerido |
| Modificar Proveedor | `proveedor_id` | `['nullable','integer','exists:proveedores,id']` |
| Eliminar Masivamente | elimina el registro (o lo omite) | `Producto::tieneOperaciones()` por producto |

**Corrección post-implementación (capturas `capturas/acciones masivas/*.png`):** las primeras 4 filas de
esta tabla (Precio de Venta, Costo, IVA por defecto, Tipo de Producto) tenían un modelo de "un único
valor aplicado igual a todo el lote" que **no coincide con Contagram real**. Ver
`docs/informe_contagram_base_de_datos.md` §4.4 (sección de corrección) y
`contracts/acciones-masivas-rutas.md` para el contrato JSON correcto de cada una.

No se agrega ningún campo a `productos` ni se persiste el "lote" en ningún lado — la selección vive
sólo en memoria del cliente (JS) durante la sesión de uso de la pantalla, y el payload de la
petición (`ids[]` o `todos + filtros`) se resuelve y se descarta en el mismo request.

## Resolución de "todos los que matchean el filtro" (sin entidad nueva)

Cuando el request llega con `todos: true`, el backend reconstruye la misma query que ya arma
`ProductoController::queryFiltrada()` (con los mismos filtros: `estado`, `tipo`, `tipo_producto_id`,
`proveedor_id`, `id`, `buscar`, `stock_min`, `stock_max`) y opera sobre el resultado completo de esa
query — no hay una tabla ni un estado intermedio que represente "el lote", es una consulta que se
ejecuta y se resuelve en el momento de aplicar la acción.

## Reglas de validación (desde Requirements)

| Regla | Origen | Dónde se aplica |
|---|---|---|
| Se debe elegir una acción antes de confirmar | FR-005, acceptance scenario US2.7 | Frontend (deshabilita "Confirmar" sin acción elegida) + `AccionMasivaProductoRequest` (rechaza si `accion` es null/vacío) |
| El valor de la acción (precio/costo/IVA/tipo/proveedor/estado) se valida igual que en el alta individual | FR-010 | `AccionMasivaProductoRequest`, reutilizando reglas de `ReglasProducto` |
| No eliminar físicamente un producto con operaciones asociadas | FR-008 (ya vigente desde 002-productos) | `ProductoController::accionesMasivas()`, rama `eliminar_masivo`, por producto vía `tieneOperaciones()` |
| Acciones de valor único son atómicas para todo el lote | Assumptions | Validación del valor **antes** de iterar el lote, dentro de una única `DB::transaction()` |
| "Eliminar Masivamente" se evalúa por producto, no todo-o-nada | Assumptions | `foreach` con `try/catch` (o chequeo previo) por producto, sin abortar el resto del lote |
