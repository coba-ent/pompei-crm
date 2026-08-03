# Tasks: Vinculación múltiple Producto ↔ Publicaciones (Mercado Libre y Tiendanube)

**Input**: Design documents from `/specs/036-vinculacion-multiple-ml/`
**Prerequisites**: plan.md, spec.md, research.md, data-model.md, quickstart.md

**Tests**: incluidos y obligatorios — la Constitución (Principio IV) exige tests para toda lógica de
movimientos de stock, y este feature cambia directamente cómo se propagan esos movimientos hacia dos
integraciones externas.

**Organization**: tareas agrupadas por user story (spec.md), en orden de prioridad P1 → P1 → P2.

## Phase 1: Setup

- [X] T001 Confirmar nombres reales de los índices únicos a eliminar corriendo
  `php artisan tinker --execute="print_r(DB::select('SHOW INDEX FROM ml_publicacion_producto'));"` y lo
  mismo para `tn_variante_producto`, para usar los nombres exactos en la migración de T002 (research.md R1)

## Phase 2: Foundational (bloqueante para todas las user stories)

**Objetivo**: quitar la restricción 1:1 a nivel de base de datos — sin esto, ninguna user story puede
completarse (los vínculos adicionales seguirían siendo rechazados por la base de datos aunque el código
ya no los rechace).

- [X] T002 Crear migración `database/migrations/2026_08_03_XXXXXX_quitar_unique_producto_id_vinculaciones.php`
  que elimina el índice único sobre `producto_id` en `ml_publicacion_producto` y en
  `tn_variante_producto` (usando `dropUnique` con los nombres confirmados en T001), preservando la FK
  (`constrained()->cascadeOnDelete()`) y el índice único de `ml_item_id`/`variant_id` sin cambios;
  incluir `down()` que restaura ambos únicos
- [X] T003 Correr `php artisan migrate` en el entorno local/de desarrollo y verificar con
  `SHOW INDEX FROM ml_publicacion_producto` / `SHOW INDEX FROM tn_variante_producto` que sólo quedó el
  único de `ml_item_id`/`variant_id`

**Checkpoint**: a partir de acá, la base de datos permite N filas con el mismo `producto_id` en ambas
tablas — las user stories pueden implementarse y probarse de forma independiente.

---

## Phase 3: User Story 1 - Vinculación automática crea todos los vínculos correspondientes (Priority: P1)

**Goal**: que `VinculadorAutomatico` (ML y Tiendanube) vincule TODAS las publicaciones/variantes que
resuelvan al mismo producto, sin rechazar por "ya_vinculado".

**Independent Test**: correr la Vinculación Automática de cada integración contra un catálogo con 2
publicaciones/variantes que comparten SKU; verificar que ambas quedan vinculadas.

### Tests (US1)

- [X] T004 [P] [US1] Test en `tests/Feature/Integraciones/MercadoLibreVinculacionMultipleTest.php`:
  con `Http::fake()` simulando 2 publicaciones activas con el mismo SKU correspondiente a un Producto
  existente sin vínculo previo, correr `VinculadorAutomatico::ejecutar()` y verificar que se crean 2
  filas en `ml_publicacion_producto` con el mismo `producto_id`, y que el resultado no reporta ninguna
  como fallida por `ya_vinculado`
- [X] T005 [P] [US1] Test en el mismo archivo de T004: con un Producto que YA tiene una publicación
  vinculada, simular una publicación nueva activa con el mismo SKU, correr la Vinculación Automática, y
  verificar que se agrega el segundo vínculo sin afectar el primero
- [X] T006 [P] [US1] Test en el mismo archivo: verificar que las exclusiones existentes (sin SKU, SKU
  sin producto correspondiente, `status=closed`, con variantes de ML) se siguen rechazando exactamente
  igual que antes (test de regresión explícito, no asumir que sigue andando)
- [X] T007 [P] [US1] Test en `tests/Feature/Integraciones/TiendanubeVinculacionMultipleTest.php`:
  mismo caso que T004 pero para `Tiendanube\VinculadorAutomatico` y `tn_variante_producto` (2 variantes
  activas con el mismo SKU)
- [X] T008 [P] [US1] Test en el mismo archivo de T007: mismo caso que T005 para Tiendanube (variante
  nueva sobre producto ya vinculado)
- [X] T009 [P] [US1] Test en el mismo archivo: regresión de exclusiones existentes de Tiendanube (sin
  SKU, SKU sin producto, producto `status=closed`)
- [X] T009b [P] [US1] Test de integridad en ambos archivos de test (T004/T007): intentar insertar
  directamente un segundo vínculo con el mismo `ml_item_id` (y, en el archivo de Tiendanube, el mismo
  `variant_id`) y verificar que la base de datos lo rechaza con una excepción de integridad — hallazgo
  de `/speckit-analyze` (E2): SC-005/FR-002/FR-012 no tenían ningún test que detecte una regresión si
  la migración de T002 llegara a tocar el índice único equivocado

### Implementation (US1)

- [X] T010 [US1] En `app/Services/MercadoLibre/VinculadorAutomatico.php`, método `procesar()`: eliminar
  el bloque que retorna `['referencia' => ..., 'motivo' => 'ya_vinculado', 'detalle' => 'producto']`
  cuando `isset($productosVinculados[$producto->id])`; simplificar/eliminar el array
  `$productosVinculados` si deja de tener otro uso en el método
- [X] T011 [US1] En `app/Services/Tiendanube/VinculadorAutomatico.php`, método `procesar()`: mismo
  cambio que T010, sobre `$productosVinculados` y el motivo `ya_vinculado`

**Checkpoint**: T004-T009 en verde → la Vinculación Automática de ambas integraciones ya crea todos los
vínculos correspondientes. Corriendo la Vinculación Automática real de Mercado Libre en este punto
debería resolver los 72 casos detectados en el catálogo de POMPEISANITARIOS (SC-001).

---

## Phase 4: User Story 2 - El stock de un Producto se sincroniza a todas sus publicaciones vinculadas (Priority: P1)

**Goal**: que un cambio de stock marque como pendientes TODOS los vínculos de un producto (ML y
Tiendanube), y que la sincronización efectivamente envíe la cantidad a cada uno.

**Independent Test**: vincular un Producto a 2 publicaciones, cambiar su stock, correr el
sincronizador, verificar que ambas reciben la cantidad correcta.

### Tests (US2)

- [X] T012 [P] [US2] Test en `tests/Unit/Observers/MovimientoStockObserverTest.php` (crear si no
  existe, o extender si ya existe uno de la spec 013/018): con un Producto vinculado a 2 filas de
  `ml_publicacion_producto`, registrar un `MovimientoStock` en el depósito efectivo de ML y verificar
  que AMBAS filas quedan con `stock_pendiente = true`
- [X] T013 [P] [US2] Mismo test que T012 pero para 2 filas de `tn_variante_producto` (depósito efectivo
  de Tiendanube)
- [X] T014 [P] [US2] Test en `tests/Feature/Integraciones/MercadoLibreSincronizacionStockMultipleTest.php`:
  con 2 vínculos pendientes del mismo producto y `Http::fake()`, correr `SincronizadorStock::ejecutar()`
  y verificar 2 llamadas `PUT /items/{id}` (una por `ml_item_id`) con la misma `available_quantity`, y
  que ambas filas quedan `stock_pendiente = false` con `stock_sincronizado_en` seteado
- [X] T015 [P] [US2] Mismo test que T014 para el sincronizador de stock de Tiendanube (localizar el
  servicio equivalente, ej. `App\Services\Tiendanube\SincronizadorStock`, y su endpoint de stock)
- [X] T016 [P] [US2] Test de desvinculación (Mercado Libre): con un Producto vinculado a 2
  publicaciones, desvincular una (borrar la fila correspondiente), cambiar el stock, y verificar que
  sólo la fila restante queda `stock_pendiente = true` — la desvinculada no existe más y no se ve
  afectada
- [X] T016b [P] [US2] Mismo test que T016 pero para Tiendanube (2 variantes vinculadas, desvincular una,
  verificar que sólo la restante queda `stock_pendiente = true`) — hallazgo de `/speckit-analyze` (E1):
  FR-009 no tenía cobertura de test del lado Tiendanube

### Implementation (US2)

- [X] T017 [US2] En `app/Observers/MovimientoStockObserver.php`, método `ramaMercadoLibre()`: cambiar
  `MercadoLibrePublicacionProducto::where('producto_id', $movimiento->producto_id)->first()` por
  `->get()`, envolver el chequeo de vacío (`if ($vinculos->isEmpty()) return;`) y el
  `$vinculo->update(['stock_pendiente' => true])` en un `foreach ($vinculos as $vinculo)`
- [X] T018 [US2] En el mismo archivo, método `ramaTiendanube()`: mismo cambio que T017, sobre
  `TiendanubeVarianteProducto`

**Checkpoint**: T012-T016 en verde → una venta que deja stock en 0 se refleja en el 100% de las
publicaciones/variantes vinculadas de ambas integraciones (SC-003). US1 + US2 completas ya cubren el
caso de negocio principal que motivó la spec (riesgo de sobreventa).

---

## Phase 5: User Story 3 - El precio de un Producto se sincroniza a todas sus publicaciones vinculadas (Priority: P2)

**Goal**: que un cambio de precio marque/despache la sincronización hacia TODOS los vínculos de un
producto (ML y Tiendanube).

**Independent Test**: vincular un Producto a 2 publicaciones/variantes, cambiar su precio, verificar
que ambas reciben el nuevo precio.

### Tests (US3)

- [X] T019 [P] [US3] Test en `tests/Unit/Observers/PrecioProductoObserverTest.php` (crear o extender):
  con un Producto vinculado a 2 filas de `ml_publicacion_producto`, guardar un `PrecioProducto` en la
  lista configurada como efectiva de ML, y verificar (con `Http::fake()`/mock del sincronizador) que
  `enviarUno()` se invoca una vez por cada uno de los 2 vínculos, con el mismo precio
- [X] T020 [P] [US3] Mismo test que T019 pero para 2 filas de `tn_variante_producto` (lista de precio
  efectiva de Tiendanube)

### Implementation (US3)

- [X] T021 [US3] En `app/Observers/PrecioProductoObserver.php`, método `ramaMercadoLibre()`: cambiar
  `MercadoLibrePublicacionProducto::where('producto_id', $precio->producto_id)->first()` por `->get()`,
  y envolver el chequeo de vacío + el `DB::afterCommit(...)` con `enviarUno($vinculo, ...)` en un
  `foreach ($vinculos as $vinculo)`, disparando un `afterCommit` por cada vínculo
- [X] T022 [US3] En el mismo archivo, método `ramaTiendanube()`: mismo cambio que T021, sobre
  `TiendanubeVarianteProducto`

**Checkpoint**: T019-T020 en verde → un cambio de precio se refleja en el 100% de las
publicaciones/variantes vinculadas de ambas integraciones (SC-004).

---

## Phase 6: Polish & Regresión

- [X] T023 [P] Correr la suite completa de tests existentes de las specs 012/013/016/017/018/021/023/024
  (vinculación automática y sincronización de stock/precio de ambas integraciones) y confirmar que
  siguen en verde — el caso 1:1 original sigue siendo válido, sólo deja de ser el único soportado
- [ ] T024 Ejecutar manualmente el Escenario 1 de `quickstart.md` contra el catálogo real de Mercado
  Libre (cuenta POMPEISANITARIOS) corriendo la Vinculación Automática, y confirmar que los 72 productos
  detectados con publicaciones duplicadas quedan correctamente vinculados (SC-001)

## Dependencies & Execution Order

- **Phase 1 (Setup)** → **Phase 2 (Foundational)**: bloqueante, sin la migración ninguna user story
  puede completarse contra una base de datos real.
- **Phase 2** → habilita **Phase 3 (US1)**, **Phase 4 (US2)** y **Phase 5 (US3)** en paralelo entre sí
  (son independientes: US1 no depende de US2/US3, aunque en la práctica conviene el orden P1→P1→P2 de
  prioridad).
- Dentro de cada fase de user story: los tests (marcados [P]) pueden escribirse en paralelo entre sí
  (archivos distintos); la implementación de cada archivo (T010/T011, T017/T018, T021/T022) es
  secuencial dentro del mismo archivo pero paralela entre ML y Tiendanube (archivos distintos).
- **Phase 6 (Polish)** depende de que las tres user stories estén completas.

## Parallel Example: Phase 3 (US1)

```text
T004 [P] Test ML: 2 publicaciones mismo SKU → 2 vínculos
T005 [P] Test ML: producto ya vinculado + publicación nueva → 2do vínculo
T006 [P] Test ML: regresión de exclusiones existentes
T007 [P] Test TN: 2 variantes mismo SKU → 2 vínculos
T008 [P] Test TN: producto ya vinculado + variante nueva → 2do vínculo
T009 [P] Test TN: regresión de exclusiones existentes
T009b [P] Test de integridad: unicidad de ml_item_id/variant_id preservada
```

Todos corren en paralelo (archivos de test distintos, sin dependencias entre sí). T010/T011
(implementación) se hacen después, uno por archivo de servicio.

## Implementation Strategy

**MVP = User Story 1 + User Story 2** (ambas P1): resuelven el problema de negocio real (riesgo de
sobreventa por publicaciones duplicadas sin sincronizar). User Story 3 (precio) es P2 — mismo patrón de
cambio, menor urgencia, puede entregarse en un segundo paso si se quisiera partir el trabajo.

Orden sugerido: Setup → Foundational → US1 → US2 → US3 → Polish, validando cada checkpoint antes de
avanzar al siguiente.
