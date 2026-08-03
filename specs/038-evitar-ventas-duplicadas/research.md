# Research: Evitar ventas duplicadas por reconversión de órdenes ML/Tiendanube

## R1 — Dónde guardar la referencia al pedido de origen

**Decision**: Dos columnas nullable en `ventas`: `ml_order_id` (string, único) y `tn_order_id`
(string, único). Sólo una de las dos se completa según `ventas.origen`.

**Rationale**: `ventas.origen` ya es un enum con 4 valores (`manual`, `presupuesto`,
`mercadolibre`, `tiendanube`); una Venta nunca tiene más de un origen externo simultáneo, así
que dos columnas nullable son más simples de indexar (dos índices únicos independientes) y más
legibles que una columna genérica `referencia_pedido_origen` + `canal_origen` que requeriría un
índice único compuesto y una capa extra de interpretación al leer el dato. Se reusa literalmente
el nombre `ml_order_id`/`tn_order_id` ya usado en `ml_ordenes`/`tn_ordenes` para no introducir un
término nuevo (principio V de la constitución).

**Alternatives considered**:
- Columna única `pedido_origen_id` + `pedido_origen_canal`: más "genérica" pero agrega
  indirección sin necesidad real (no hay un tercer canal en el horizonte inmediato) y complica el
  índice único (tendría que ser compuesto sobre las dos columnas).
- Guardar sólo el `id` de `ml_ordenes`/`tn_ordenes` en vez del `ml_order_id`/`tn_order_id`: no
  sirve — ese `id` es autoincremental de la fila, que es justamente la que se borra y se
  regenera con otro `id` al resincronizar. La referencia tiene que ser el identificador estable
  del pedido en el canal externo, no la PK local de la orden.

## R2 — Cómo blindar el borrado de `ml_ordenes`/`tn_ordenes`

**Decision**: Evento Eloquent `deleting` en `boot()` de `MercadoLibreOrden` y `TiendanubeOrden`,
que aborta el borrado (retornando `false` desde el listener, o lanzando una excepción de dominio
capturada por el controller) si `venta_id` no es null.

**Rationale**: FR-006/FR-007 exigen que el bloqueo valga "sin importar si la eliminación se
dispara desde la vista de órdenes o desde una operación de mantenimiento/limpieza". Un guard en
el controller HTTP sólo cubriría la UI; un guard en el modelo (evento `deleting`) cubre además
cualquier `Model::destroy()`, `$orden->delete()` o borrado en lote hecho vía `tinker`/comando
artisan — que es exactamente el canal que se usó para el borrado manual de las 7 órdenes de
prueba que motivó esta feature. No cubre un `DELETE` SQL directo fuera de Eloquent (fuera de
alcance: la app no expone ese camino hoy).

**Alternatives considered**:
- Sólo validar en el controller antes de llamar a `destroy()`: más simple pero deja
  desprotegidos scripts de mantenimiento y no es consistente con el precedente ya usado en el
  repo (`CuentaTesoreria::tieneOperaciones()`, `Cliente`/`Producto` con el mismo patrón), que sí
  valida en el controller — para mantener homogeneidad se replica el mismo patrón de
  controller (`destroy()` con chequeo explícito) EN VEZ de un evento de modelo, ver decisión
  final abajo.

**Decisión final (ajustada tras revisar el repo)**: para ser consistente con el patrón ya
establecido en `CuentaTesoreriaController::destroy()`, `ClienteController::destroy()` y
`ProductoController::destroy()` (todos validan en el controller, no con eventos de modelo), el
guard se implementa como un método `tieneVentaAsociada()` en cada modelo de orden (mismo estilo
que `CuentaTesoreria::tieneOperaciones()`), consultado explícitamente por el controller/comando
que borre. Esto mantiene el mismo lenguaje de código en todo el proyecto. Como hoy no existe un
endpoint HTTP de borrado para `ml_ordenes`/`tn_ordenes` (se comprobó que no hay rutas `destroy`
registradas para estos recursos), la Tarea de esta feature agrega el método de guard en el
modelo y lo aplica en el único punto de borrado que existe hoy (comando/acción de mantenimiento),
dejando el modelo listo para que un futuro botón de borrado en la UI lo reutilice sin volver a
tocar esta regla.

## R3 — Cómo evitar duplicados en la conversión (además de la unicidad de columna)

**Decision**: Antes de crear la `Venta` dentro de `convertirBajoCandado()`, cada
`ConversorOrdenAVenta` (ML y Tiendanube) consulta `Venta::withTrashed()->where('ml_order_id'
/'tn_order_id', $ordenId)->exists()` y, si ya existe, devuelve el mismo rechazo que hoy devuelve
`if ($orden->venta_id)` ("Esta orden ya tiene una Venta asociada."). El índice único de la
columna actúa además como respaldo de carrera (mismo patrón que ya existe hoy con el índice
único de `ml_ordenes.venta_id`, comentado en el código como "FR-032b").

**Rationale**: `withTrashed()` es necesario porque `ventas` usa soft delete — una Venta borrada
lógicamente por error no debe habilitar una reconversión silenciosa (edge case del spec: "la
referencia guardada en la Venta debe seguir bloqueando... salvo que un usuario decida
explícitamente habilitar una reconversión", fuera de alcance de esta feature).

**Alternatives considered**: Confiar sólo en el índice único de la base y capturar la excepción
de SQL (como ya hace el bloque `catch (QueryException $e)` existente para el candado de
`venta_id`): se descarta como único mecanismo porque el mensaje de error sería menos claro para
el flujo de "creación automática" (que necesita decidir el motivo de rechazo antes de intentar
el insert, no después de que falle) — pero SÍ se mantiene como respaldo de carrera, igual que hoy.

## R4 — Backfill de Ventas históricas

**Decision**: Comando artisan de un solo uso (`php artisan ventas:backfill-referencia-pedido`)
que recorre `MercadoLibreOrden::whereNotNull('venta_id')` y `TiendanubeOrden::whereNotNull('venta_id')`,
y completa `ventas.ml_order_id`/`tn_order_id` a partir de `orden.ml_order_id`/`orden.tn_order_id`
para las Ventas que todavía no tengan el campo cargado.

**Rationale**: Es una operación de datos, una sola vez, sobre datos de producción — no amerita UI
ni un job en cola. Se ejecuta a mano después del deploy (mismo criterio que otras migraciones de
datos puntuales ya hechas en el proyecto, ver `docs/` y memoria de migración VPS).

**Alternatives considered**: Backfill dentro de la propia migración (`up()`): se descarta porque
mezclar DDL con lógica de negocio (recorrer relaciones, `Model::query()`) en una migración es
frágil ante cambios futuros del modelo, y el proyecto ya tiene precedente de comandos artisan
para tareas operativas puntuales.
