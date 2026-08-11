# Research: Filtros del listado de Compras

No hay incógnitas tecnológicas: la feature reutiliza 1:1 un patrón ya implementado y en producción en Ventas. Este documento deja constancia de las decisiones tomadas por reutilización directa de ese patrón, en vez de investigación desde cero.

## Decisión 1: Backend — un `if ($request->filled(...))` por filtro dentro de `queryFiltrada()`

- **Decisión**: Igual que `VentaController::queryFiltrada()` (`app/Http/Controllers/VentaController.php:140-244`), cada filtro nuevo se agrega como un bloque independiente `if ($request->filled('campo')) { ... }` dentro de `CompraController::queryFiltrada()` (`app/Http/Controllers/CompraController.php:88-101`).
- **Rationale**: Es el patrón ya validado en producción para el mismo problema (filtros de listado con DataTables server-side + Select2 múltiple). Mantiene el código de Compras consistente con Ventas, reduciendo la carga cognitiva de mantenimiento del proyecto (fidelidad estructural, CLAUDE.md).
- **Alternativas consideradas**: Un objeto "Filtro" reusable/genérico entre Ventas y Compras — descartado por alcance: implicaría refactorizar Ventas (que ya funciona) solo para esta feature, violando "no repetir el error de simplificar de más" y aumentando el blast radius sin necesidad.

## Decisión 2: Selección múltiple — `whereIn('campo', (array) $request->input('campo'))`

- **Decisión**: Mismo cast `(array)` que ya usan `cliente_id`, `categoria_id`, `vendedor_id`, `usuario_id` en Ventas (`VentaController.php:157,182,201,216`). Tolera tanto un escalar (URL vieja con un solo proveedor) como un array (selección múltiple nueva).
- **Rationale**: Resuelve además el requisito de compatibilidad hacia atrás de la Technical Context sin código adicional.
- **Alternativas consideradas**: Forzar siempre array desde el frontend (`proveedor_id[]`) — es lo que hará Select2 `multiple` de todos modos, pero el cast en backend es la red de seguridad que ya usa Ventas; se mantiene por consistencia.

## Decisión 3: Filtros con relación (`whereHas`) para Etiqueta, Medio de pago, Facturado

- **Decisión**: Etiqueta → `whereHas('etiquetas', fn ($q) => $q->whereIn('etiquetas.id', (array) ...))` (idéntico a Ventas, `VentaController.php:198`). Medio de pago → `whereHas('pagos', fn ($q) => $q->where('cuenta_tesoreria_id', ...))` (idéntico a `medio_cobro_id` de Ventas, `VentaController.php:213`, pero sobre `pagos` en vez de `cobros`). Facturado → `whereHas('comprobanteFiscal')` / `whereDoesntHave('comprobanteFiscal')` (mismo criterio binario que `estado_factura = sin_emitir` de Ventas, `VentaController.php:186`).
- **Rationale**: Reutilización directa de relaciones ya existentes en `Compra` (`pagos()`, `comprobanteFiscal()`) y de la nueva `etiquetas()` a agregar (Decisión 5).

## Decisión 4: Filtro "Estado del Pago" — 3 valores derivados, sin persistir estado

- **Decisión**: El filtro usa los 3 valores que ya calcula `Compra::estadoPago()` (`app/Models/Compra.php:108-117`): `a_pagar`, `parcial`, `pagado`. Se resuelve en SQL con la misma subconsulta agregada ya usada en `kpis()` (`CompraController.php:58-86`) adaptada a `WHERE` en vez de `SELECT`, para no traer todas las compras a memoria y evaluar `estadoPago()` fila por fila (mismo problema de N+1 ya documentado y evitado en `kpis()`, líneas 47-57).
- **Rationale**: Evita reintroducir el bug de rendimiento que el propio código de `kpis()` ya dejó documentado como corregido (2.526 compras × 3 queries si se recorriera en PHP).
- **Alternativas consideradas**: Cargar todas las compras filtradas parcialmente y filtrar el resultado en PHP con `estadoPago()` — descartado por el mismo motivo de performance que documenta `kpis()`.

## Decisión 5: Relación `etiquetas()` en `Compra` — reutilizar tabla pivote genérica existente

- **Decisión**: Agregar `public function etiquetas(): MorphToMany { return $this->morphToMany(Etiqueta::class, 'etiquetable'); }` a `app/Models/Compra.php`, idéntica a `Venta::etiquetas()` (`app/Models/Venta.php:123-126`). La tabla pivote `etiquetables` (`database/migrations/2026_07_30_060001_create_etiquetas_tables.php`) ya es polimórfica (`etiquetable_type`/`etiquetable_id`) y genérica — **no requiere migración nueva**, solo el método de relación en el modelo.
- **Rationale**: El esquema ya fue diseñado para soportar múltiples tipos de entidad etiquetable; agregar Compra es un cambio de una sola línea de código sin impacto de esquema.
- **Nota de documentación**: `docs/modelo_datos.md:470` afirma hoy "Compras no usa etiquetas (no confirmado en el relevamiento para este documento)" — queda desactualizado frente a la captura real y al propio `docs/informe_contagram_egresos.md` (que ya documenta "Etiquetas" como columna del listado de Compras, §2.1). Se corrige como parte de esta feature (ver plan.md, Constitution Check).

## Decisión 6: `creado_por_id` — nueva columna, sin backfill, mismo criterio que `deposito_id` (spec 049)

- **Decisión**: Migración que agrega `creado_por_id` (bigint, nullable, FK → `users`, `nullOnDelete()`) a `compras`, agregada a `$fillable` y seteada en `store()` con `auth()->id()` (mismo punto que `VentaController.php:393`). Se agrega también `creadoPor(): BelongsTo` al modelo, igual que `Venta::creadoPor()` (`Venta.php:70-74`).
- **Rationale**: Replica exactamente el precedente ya sentado en spec 049 (columna `deposito_id` agregada a `compras` como nullable sin backfill, ver `docs/modelo_datos.md:337`) para el mismo tipo de cambio (columna nueva en tabla con histórico).
- **Alternativas consideradas**: Backfill con un usuario "genérico"/"sistema" para compras históricas — descartado: no hay forma confiable de saber quién cargó cada compra histórica, y forzar un valor inventado sería un dato falso, no una migración de datos real.

## Decisión 7: Frontend — Select2 multi + daterangepicker doble + colvis, todo ya empaquetado

- **Decisión**: `compras.js` replica `initSelect2()`/`refreshSelect2()` y el wiring de filtros de `resources/js/ventas.js:34-250` (mismas funciones, mismos ids de patrón `filtro-*`, mismo bloque de `daterangepicker` para `#filtro-rango-emision` + nuevo `#filtro-rango-vencimiento`, mismo botón `colvis` de DataTables agregado a `#dt-buttons-compras`).
- **Rationale**: Cero dependencias nuevas — Select2, daterangepicker y el extension `colvis` de DataTables ya están cargados globalmente por el template NexaDash (`config/dz.php`, per CLAUDE.md §Especificaciones de diseño obligatorias) y en uso en Ventas.
