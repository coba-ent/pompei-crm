# Research: Vendedores como catálogo propio

## R1 — ¿Vendedor necesita jerarquía/tipo/es_sistema como Categoría?

**Decisión**: No. `vendedores` es una tabla plana: `id`, `nombre` (único), timestamps.

**Rationale**: `Categoria` tiene `tipo` (ingreso/venta/compra/gasto), `categoria_padre_id`
(subcategorías de Gasto) y `es_sistema` (categorías precargadas no eliminables) porque los necesita
para casos de uso reales del proyecto (árbol de Gasto, categorías del sistema protegidas). Ningún
requisito de la spec pide nada de eso para Vendedor: es un catálogo de nombres sin jerarquía ni
distinción de origen. Agregar esos campos sería complejidad especulativa sin requisito que la
sostenga.

**Alternatives considered**: reutilizar la tabla `categorias` con un nuevo `tipo = 'vendedor'` —
rechazado: mezclaría dos dominios distintos (categorías de negocio vs. vendedores) en la misma tabla,
y el ABM inline de Categorías ya tiene lógica de jerarquía/es_sistema que no aplica acá y que
complicaría el `destroy()`/`update()` en vez de simplificarlos.

## R2 — ¿Cómo migrar `vendedor_id` de `users` a `vendedores` sin perder historial?

**Decisión**: una única migración de Laravel con dos fases internas de distinta naturaleza: (1-4) el
paso de **datos** (crear `vendedores`, insertar un registro por cada `user_id` usado hoy en
`ventas.vendedor_id`/`presupuestos.vendedor_id`, y actualizar esos `vendedor_id` al nuevo id mapeado)
va envuelto en un `DB::transaction()` real; (5) el paso de **esquema** (dropear la FK vieja hacia
`users` y crear la nueva hacia `vendedores`, `nullable`, `restrictOnDelete`) es DDL separado, después
de que el paso de datos ya haya confirmado (commiteado) exitosamente.

**Corrección post-análisis**: en MySQL/MariaDB (stack del proyecto) las sentencias DDL producen
**commit implícito** — no hay atomicidad real entre el paso de datos y el de esquema dentro de una
misma "transacción", a diferencia de Postgres. Por eso el paso de datos se commitea *antes* de tocar
el esquema: si el paso de DDL fallara a mitad de camino (drop de la FK vieja exitoso, alta de la FK
nueva fallida), los datos ya migrados quedan correctos y sin riesgo — sólo faltaría completar
manualmente el cambio de esquema, nunca se pierde ni se corrompe información de negocio (SC-002 sigue
garantizado). Ambas fases viven en el mismo archivo de migración por prolijidad (research.md R2 no
cambia de decisión), pero ya no se describen como una única transacción atómica de punta a punta.

**Rationale**: es el único camino que cumple FR-008/SC-002 (100% de historial preservado) sin
intervención manual. Al ir todo en una transacción, si algo falla no queda el esquema a medio migrar.

**Alternatives considered**:
- Vaciar `vendedor_id` en todo lo existente y arrancar de cero — rechazado explícitamente por el
  usuario (respuesta a la pregunta de clarificación de alcance, ver spec.md Assumptions).
- Migrar sólo el esquema y dejar un comando `artisan` separado para migrar los datos a mano —
  rechazado: introduce una ventana donde el esquema ya cambió pero los datos no, y un paso manual
  que se puede olvidar; una migración de datos dentro de la misma migración de esquema es el patrón
  estándar de Laravel para este caso y no tiene downside acá (volumen bajo: usuarios del sistema, no
  millones de filas).

## R3 — ¿Nombres de usuario duplicados durante la migración?

**Decisión**: no se deduplica. Si dos usuarios del sistema comparten `name`, se crean dos vendedores
iguales (uno por cada `user_id` de origen).

**Rationale**: es exactamente lo que la spec asume (Assumptions: "si dos usuarios distintos tienen
el mismo name, se crea un vendedor por cada uno igual — evaluar unicidad... fuera del alcance de
negocio de esta spec"). Deduplicar automáticamente arriesgaría fusionar el historial de dos personas
distintas sin que el negocio lo haya decidido explícitamente; ese es un problema de limpieza de datos
que el negocio puede resolver manualmente después (fusionar/renombrar vendedores) si hace falta.

**Alternatives considered**: deduplicar por nombre exacto al migrar — rechazado, mismo motivo.

## R4 — ¿Cómo estructurar el ABM inline (controlador, rutas, JS)?

**Decisión**: replicar el patrón de `CategoriaController` casi literalmente en un `VendedorController`
nuevo — `store()` (nombre único), `update()` (renombrar), `destroy()` (bloqueo por
`QueryException` si está en uso). Rutas nuevas bajo `vendedores` (store) y `vendedores/{vendedor}`
(update/destroy) — **corrección post-análisis**: sin el sufijo `-venta` que sí tiene
`categorias-venta`, porque ese sufijo existe en Categoría para fijar `tipo = 'venta'` en el alta
(`CategoriaController::storeVenta()`); Vendedor no tiene `tipo`, así que un único endpoint sirve por
igual a los 4 puntos de uso (Venta, Presupuesto, config. Tiendanube, config. MercadoLibre) sin
necesidad de distinguir "sabor". En el frontend,
duplicar el bloque "Categoría de ventas" de `resources/js/ventas.js`/`presupuestos.js` (líneas
159-428 de `ventas.js`) reemplazando "categoría" por "vendedor", sin el manejo de `es_sistema` (no
aplica: ningún vendedor es del sistema).

**Rationale**: el proyecto ya tiene este patrón validado y en uso diario (Categorías); replicarlo
exactamente minimiza sorpresas y mantiene consistencia de UX (mismos textos, mismos modales, mismo
comportamiento de Toastr) entre "Categoría" y "Vendedor" en el mismo formulario.

**Alternatives considered**: generalizar un componente/trait común "ABM inline de catálogo simple"
que sirva para Categoría, Vendedor, y futuros catálogos — rechazado por ahora (regla del proyecto:
no diseñar para necesidades hipotéticas); si en el futuro aparece un tercer catálogo con el mismo
patrón, ahí se evalúa extraer lo común.

## R5 — ¿Cómo enchufar "vendedor por defecto" en Tiendanube/MercadoLibre?

**Decisión**: replicar exactamente el patrón de `categoria_venta_id`: columna `vendedor_id` (FK
nullable) en `tn_configuracion`/`ml_configuracion`, relación `vendedor(): BelongsTo(Vendedor::class)`
en ambos modelos de configuración, campo en `GuardarConfiguracionVentas*Request`
(`nullable|exists:vendedores,id`), select en la vista de configuración con el mismo ABM inline
(`VendedorController`, reutilizado desde ahí también), y en `ConversorOrdenAVenta` de cada
integración, asignar `'vendedor_id' => *Configuracion::actual()->vendedor_id` junto a la línea ya
existente de `categoria_id`.

**Rationale**: FR-010/FR-011 piden explícitamente "mismo patrón que categoría de venta por defecto";
el mecanismo ya está probado en producción (categoría) y es independiente por integración (cada una
tiene su propio default, sin fallback compartido — mismo criterio ya usado para `deposito_id` en
spec 015, "independencia deliberada").

**Alternatives considered**: un vendedor por defecto único y global para todas las integraciones —
rechazado: rompería la independencia deliberada entre integraciones ya establecida en el proyecto
(cada `*Configuracion` es autónoma) y no fue lo que pidió el usuario (pidió "en ambas
configuraciones", no una config compartida).

## R6 — Integridad referencial: ¿bloqueo de borrado en base o sólo en controlador?

**Decisión**: ambos. La FK se crea con `onDelete: restrict` (o el default de MySQL, que ya es
restrict) para que la base rechace el borrado si hay cualquier referencia (Ventas, Presupuestos, o
el default de alguna integración); el controlador captura la `QueryException` resultante y devuelve
un 422 con mensaje claro — mismo patrón que `CategoriaController::destroy()`.

**Rationale**: depender sólo del controlador (por ejemplo, sólo chequeando
`Venta::where('vendedor_id', $id)->exists()`) obligaría a acordarse de chequear las tres tablas que
pueden referenciar un vendedor (ventas, presupuestos, tn_configuracion, ml_configuracion) cada vez
que se agregue un nuevo lugar que referencie vendedores; la restricción de base es la garantía real
y de una sola fuente, el `catch` sólo la traduce a un mensaje amigable — exactamente el patrón que
ya usa `CategoriaController::destroy()`.

**Alternatives considered**: soft-delete de vendedores (como categorías inactivas) en vez de bloqueo
duro — rechazado: la spec no pide ocultar vendedores "inactivos", pide bloquear el borrado cuando
está en uso (spec FR-006, Edge Cases); agregar soft-delete sería un campo/comportamiento no pedido.
