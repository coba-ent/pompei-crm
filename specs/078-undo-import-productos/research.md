# Research: Deshacer Import de Productos

## R1 — Nomenclatura de las tablas nuevas: español vs. inglés técnico

**Decision**: `importacion_corridas` (import run) e `importacion_filas_snapshot` (row snapshot),
en español, siguiendo el Principio V de la constitución ("dominio en español").

**Rationale**: `docs/modelo_datos.md` usa nombres de tabla en español en el 100% de los casos
existentes (`productos`, `stocks`, `movimientos_stock`, `logs_auditoria`). No hay precedente de
tabla en inglés en el esquema. Mantener la convención evita fricción para quien lea el modelo de
datos por primera vez.

**Alternatives considered**: `import_runs`/`import_row_snapshots` (inglés, más cercano a
terminología de la industria) — rechazado por romper la convención uniforme del proyecto sin
ninguna ganancia real (no hay integración externa que dependa de ese nombre).

## R2 — Qué guardar en el snapshot de una fila

**Decision**: el snapshot de una fila de actualización guarda una copia JSON de los atributos
completos del producto (`Producto::toArray()` sin relaciones) más los precios por lista
(`precios_producto` de ese producto) y el stock por depósito (`stocks` de ese producto) vigentes
en el momento inmediatamente anterior a aplicar la fila. El snapshot de una fila de alta guarda
un flag `existia = false` sin más datos (no había nada que capturar).

**Rationale**: el import pisa campos de `productos`, `precios_producto` (vía `updateOrCreate`) y
`stocks` (vía `StockService::fijar()`) — son los 3 lugares que el undo necesita poder restaurar.
Guardar el registro completo (no sólo los campos mapeados en esa fila) simplifica la restauración:
no hace falta reconstruir qué campos tocó el mapeo, sólo volcar el snapshot entero sobre los
campos que el import efectivamente había cambiado (comparando snapshot vs. estado post-import se
sabe qué cambió, pero para el undo alcanza con "restaurar snapshot completo" salvo stock, que usa
`fijar()` con el valor deseado = valor del snapshot).

**Alternatives considered**: guardar sólo el diff (campo: valor_anterior) — más liviano en
espacio, pero exige que `ImportadorFilas` calcule y serialice ese diff por campo, acoplando más
lógica al hot path del import. Con volúmenes de miles de filas el espacio en JSON no es un
problema real (un producto son ~15 columnas), así que se prefiere la copia completa por
simplicidad de implementación y de la lógica de undo.

## R3 — Cuándo tomar el snapshot: antes de aplicar cada fila, dentro de la misma tanda

**Decision**: el snapshot se toma inmediatamente antes de `actualizarProducto()`/`crearProducto()`
en `ImportadorFilas::procesarFilas()`, dentro del mismo bucle de fila — no como un paso separado
antes de toda la corrida.

**Rationale**: el import ya procesa en tandas de hasta 1.000 filas por request (research.md de
spec 006, límite de proxy en hosting compartido). Un snapshot "de toda la corrida" tomado al
principio no es viable con ese modelo de tandas (la corrida completa no está en memoria de una
sola vez). Tomar el snapshot fila por fila, justo antes de tocarla, es el único punto donde se
tiene certeza del estado "justo antes de esta fila" sin re-leer nada.

**Alternatives considered**: snapshot de toda la tabla antes de arrancar — descartado explícitamente
por el usuario (demasiado costoso, y unrelacionado con lo tocado); snapshot al final de la tanda
comparando con un `SELECT` previo cacheado — más complejo y con la misma ventana de concurrencia
que `StockService::fijar()` ya vino a resolver.

## R4 — Mecanismo de undo de stock: reutilizar `StockService::fijar()`

**Decision**: `DeshacerImportacionService` llama a `StockService::fijar($producto, null,
$deposito, $cantidadSnapshot, 'Ajuste (deshacer import)', $usuario)` por cada depósito que el
snapshot tenía registrado, exactamente igual que como el propio import llama a `fijar()` en
`actualizarProducto()`.

**Rationale**: es el mecanismo que la spec 074 ya blindó contra el *lost update* (lectura+cálculo+
escritura bajo `lockForUpdate()` en una transacción). Reimplementar la lógica de "restaurar a un
valor absoluto" sin ese lock reintroduciría el mismo bug de concurrencia que motivó la spec 074.

**Alternatives considered**: comparar snapshot vs. estado actual y aplicar `ajustar()` con el
delta calculado afuera de una transacción — es exactamente el patrón que `fijar()` fue creado para
reemplazar; descartado.

**Detección de "operación posterior" que bloquea el undo de stock (FR-008)**: se compara la
cantidad de `movimientos_stock` con `id` mayor al último movimiento que existía en el momento del
snapshot (capturado también en el snapshot: `ultimo_movimiento_stock_id` por depósito) contra el
estado actual. Si hay movimientos nuevos de tipo `entrada`/`salida`/`transferencia` con
`origen_type` distinto de la propia importación (es decir, generados por venta/compra/transferencia/
ajuste manual posterior), esa fila no se revierte automáticamente y se reporta.

## R5 — Detección de "operaciones posteriores" que bloquean el undo de un alta (FR-005)

**Decision**: reutilizar el mismo helper que ya usa el resto del sistema para decidir si un
producto "tiene operaciones" antes de permitir su eliminación —análogo a
`Deposito::tieneOperaciones()`/`Proveedor::tieneOperaciones()` documentados en
`docs/modelo_datos.md`. Se agrega `Producto::tieneOperaciones()` si no existe ya con ese alcance
(ventas, compras, NC/ND, remitos, movimientos de stock no generados por el propio import), y se
usa tanto para bloquear el soft-delete del undo como, potencialmente, para el "no eliminar
producto con operaciones" ya vigente en el alta/edición manual.

**Rationale**: consistencia con el patrón ya establecido en el proyecto para esta misma pregunta
("¿este registro tiene actividad real encima?"), evita reinventar la detección.

## R6 — Ventana de tiempo y expiración (FR-004, FR-015)

**Decision**: `import_run.deshacer_disponible_hasta` se calcula y persiste al confirmar la
corrida (`confirmado_en + 48 horas`), no se recalcula en cada request. La disponibilidad de la
acción "Deshacer" se resuelve comparando ese timestamp contra `now()` en el momento de renderizar
el historial/resumen y, de nuevo, server-side al ejecutar el undo (defensa en profundidad: no
confiar en que el botón esté oculto en el cliente).

**Rationale**: timestamp fijo es más simple y auditable que recalcular una ventana relativa; evita
inconsistencias si el usuario tiene la pantalla abierta mucho tiempo.

## R7 — Snapshot y volumen: impacto en el import existente

**Decision**: el INSERT de snapshots se hace en el mismo estilo de buffer/batch que ya usa
`AuditoriaService::iniciarBuffer()`/`vaciarBuffer()` para los eventos de precio (spec 074) — se
acumulan las filas de la tanda en memoria y se persisten con un único `insert()` múltiple al final
de la tanda, en vez de un INSERT por fila.

**Rationale**: mismo patrón ya validado en producción (spec 074, 9.187 productos) para evitar N
inserts individuales degradando el tiempo de importación.

**Alternatives considered**: INSERT por fila — simple pero ya demostrado como problema de
performance en spec 074 para el caso análogo de auditoría de precios; descartado.
