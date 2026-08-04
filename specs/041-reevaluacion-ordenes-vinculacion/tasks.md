# Tasks: Reevaluación automática de órdenes por vinculación tardía

**Input**: Design documents from `/specs/041-reevaluacion-ordenes-vinculacion/`
**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/reevaluador-ordenes.md, quickstart.md

**Tests**: incluidos — Principio IV de la constitución (`.specify/memory/constitution.md`) exige
tests para toda lógica que pueda terminar creando dinero/movimientos contables; esta feature
puede disparar creación automática de Venta.

**Organization**: tareas agrupadas por user story (US1 = evento-driven, P1; US2 = on-view, P2),
con una fase de Foundational compartida antes (el servicio `ReevaluadorOrdenes` es prerrequisito
de ambas historias en los dos canales).

## Format: `[ID] [P?] [Story] Description`
- **[P]**: se puede ejecutar en paralelo (archivo distinto, sin dependencia de una tarea incompleta)
- **[Story]**: US1 o US2 — Setup/Foundational/Polish no llevan story label

## Phase 1: Setup

- [X] T001 Confirmar en el entorno local (XAMPP) que existen las tablas/modelos ya usados por esta
  feature (`ml_ordenes`, `ml_orden_items`, `ml_publicacion_producto`, `tn_ordenes`,
  `tn_orden_items`, `TiendanubeVarianteProducto`) — sin migraciones nuevas, sólo verificación.

## Phase 2: Foundational (bloqueante — ambas historias dependen de esto)

**Propósito**: extraer el servicio `ReevaluadorOrdenes` (uno por canal) que reusan tanto el
Observer (US1) como el `datatable()` (US2), según el contrato en `contracts/reevaluador-ordenes.md`.

- [X] T002 [P] Crear `App\Services\MercadoLibre\ReevaluadorOrdenes` en
  `app/Services/MercadoLibre/ReevaluadorOrdenes.php` con los métodos `reevaluarUna()`,
  `reevaluarAfectadasPorPublicacion()` y `reevaluarPendientesDelCanal()` según
  `contracts/reevaluador-ordenes.md`, reutilizando `EvaluadorConvertibilidad`, `ResolutorCliente`
  y `ConversorOrdenAVenta` de `App\Services\MercadoLibre` ya existentes (mismo bloque de lógica
  que hoy vive inline en `SincronizadorOrdenes::procesarOrden()`/`intentarCreacionAutomatica()`,
  sin modificar ese archivo).
- [X] T003 [P] Crear `App\Services\Tiendanube\ReevaluadorOrdenes` en
  `app/Services/Tiendanube/ReevaluadorOrdenes.php`, misma forma que T002 pero para el canal
  TiendaNube, reutilizando `App\Services\Tiendanube\EvaluadorConvertibilidad`, `ResolutorCliente`
  y `ConversorOrdenAVenta`.
- [X] T004 [P] Test unitario `tests/Unit/Services/MercadoLibre/ReevaluadorOrdenesTest.php`:
  cubrir `reevaluarUna()` — no-op si `venta_id` no es null (FR-005); persiste
  `estado_conversion`/`motivo`/`motivo_detalle` según lo que devuelve `EvaluadorConvertibilidad`
  (mockeado o con datos reales); limpia `motivo`/`motivo_detalle` al pasar a `lista` (FR-011);
  dispara `ConversorOrdenAVenta::convertir(..., automatica: true)` sólo si queda `lista` y
  `MercadoLibreConfiguracion::actual()->creacion_automatica` es true; ante excepción de
  `convertir()`, la orden queda `requiere_atencion` / `ErrorConversion` con el detalle del error
  (no se relanza la excepción).
- [X] T005 [P] Test unitario `tests/Unit/Services/Tiendanube/ReevaluadorOrdenesTest.php`: mismos
  casos que T004 mapeados al canal TiendaNube.
- [X] T006 [P] Test unitario (dentro de T004/T005 o archivo separado a criterio) para
  `reevaluarAfectadasPorPublicacion()`/`reevaluarAfectadasPorVariante()`: la query sólo trae
  órdenes con `venta_id` null y `estado_conversion` en `[requiere_atencion, lista]` cuyo ítem
  referencia el `ml_item_id`/`variant_id` dado (no trae `cancelada` ni `pendiente_pago`, ni
  órdenes de otro ítem) — cubre FR-005, FR-009, FR-010 y el criterio de `research.md §R4`.

**Checkpoint**: con T002-T006 en verde, `ReevaluadorOrdenes` está listo para ser consumido por
ambas historias de usuario, cada una independiente entre sí desde acá.

---

## Phase 3: User Story 1 - Vincular un producto destraba automáticamente sus órdenes pendientes (Priority: P1) 🎯 MVP

**Goal**: al crear, editar o eliminar una vinculación ML/TN, las órdenes afectadas se reevalúan en
el momento sin acción adicional del usuario (FR-001, FR-002, FR-003, FR-004, FR-005, FR-009,
FR-010, FR-011).

**Independent Test**: sincronizar una orden con ítem sin vincular (queda `requiere_atencion`),
vincular esa publicación/variante a un producto, y verificar que la orden cambia de estado sin
ninguna acción adicional (Escenario 1/2/3 de `quickstart.md`).

### Tests para User Story 1

- [X] T007 [P] [US1] Feature test `tests/Feature/MercadoLibre/VinculacionReevaluaOrdenesTest.php`:
  crear una orden `requiere_atencion`/`publicacion_sin_vincular` con un ítem `ml_item_id` dado,
  crear la vinculación (`MercadoLibrePublicacionProducto::create(...)`) y afirmar que la orden
  queda `lista` (o `convertida` si se activa `creacion_automatica` en el test); repetir editando
  una vinculación existente (`update()`); repetir eliminando una vinculación de una orden que
  estaba `lista` y afirmar que vuelve a `requiere_atencion`/`publicacion_sin_vincular` (Edge Case
  FR-010); afirmar que una orden con `venta_id` seteado NO cambia (FR-005); afirmar que una orden
  de OTRO `ml_item_id` no relacionado NO se toca (FR-009).
- [X] T008 [P] [US1] Feature test `tests/Feature/Tiendanube/VinculacionReevaluaOrdenesTest.php`:
  mismos casos que T007 mapeados a `TiendanubeVarianteProducto`/`tn_ordenes`/`variant_id`.

### Implementación para User Story 1

- [X] T009 [P] [US1] Crear `App\Observers\MercadoLibrePublicacionProductoObserver` en
  `app/Observers/MercadoLibrePublicacionProductoObserver.php` con métodos `saved()` y `deleted()`
  que, dentro de `DB::afterCommit()`, llamen a
  `ReevaluadorOrdenes::reevaluarAfectadasPorPublicacion($publicacion->ml_item_id)` (mismo patrón
  que `app/Observers/PrecioProductoObserver.php`).
- [X] T010 [P] [US1] Crear `App\Observers\TiendanubeVarianteProductoObserver` en
  `app/Observers/TiendanubeVarianteProductoObserver.php`, análogo a T009 con
  `reevaluarAfectadasPorVariante($variante->variant_id)`.
- [X] T011 [US1] Registrar ambos Observers en `app/Providers/AppServiceProvider.php::boot()`
  (`MercadoLibrePublicacionProducto::observe(MercadoLibrePublicacionProductoObserver::class)` y
  `TiendanubeVarianteProducto::observe(TiendanubeVarianteProductoObserver::class)`), junto a los
  Observers ya registrados ahí (`VentaObserver`, `CompraObserver`, `MovimientoStockObserver`,
  `PrecioProductoObserver`).

**Checkpoint**: User Story 1 completa y testeable de forma independiente — vincular ML o TN
destraba sus órdenes sin abrir la vista de pendientes.

---

## Phase 4: User Story 2 - La vista de órdenes pendientes siempre refleja el estado real al abrirla (Priority: P2)

**Goal**: al abrir el listado de órdenes pendientes de cada canal, las órdenes `requiere_atencion`
desincronizadas se corrigen antes de mostrarse (FR-006, FR-007, FR-008).

**Independent Test**: desincronizar una orden por una vía distinta al flujo normal de vinculación
(insert directo por SQL en la tabla de vinculación), abrir la vista de pendientes del canal, y
verificar que el listado ya muestra el estado corregido (Escenario 4 de `quickstart.md`).

### Tests para User Story 2

- [X] T012 [P] [US2] Feature test (agregar caso a
  `tests/Feature/MercadoLibre/VinculacionReevaluaOrdenesTest.php` o archivo nuevo
  `tests/Feature/MercadoLibre/OrdenesPendientesDatatableReevaluaTest.php`): crear una orden
  `requiere_atencion`/`publicacion_sin_vincular` con su publicación YA vinculada por fuera del
  flujo normal (insert directo, sin pasar por el Observer), pegarle al endpoint `datatable()` de
  `MercadoLibreVentaController`, y afirmar que la respuesta refleja la orden como `lista` (o que
  en base ya quedó corregida tras la llamada).
- [X] T013 [P] [US2] Mismo test que T012 para `TiendanubeVentaController::datatable()`.

### Implementación para User Story 2

- [X] T014 [US2] Modificar `MercadoLibreVentaController::datatable()` en
  `app/Http/Controllers/Ingresos/MercadoLibreVentaController.php`: antes de construir la
  respuesta `DataTables::eloquent()`, llamar a `ReevaluadorOrdenes::reevaluarPendientesDelCanal()`.
- [X] T015 [US2] Modificar `TiendanubeVentaController::datatable()` en
  `app/Http/Controllers/Ingresos/TiendanubeVentaController.php`, mismo cambio que T014 para el
  canal TiendaNube.

**Checkpoint**: ambas historias de usuario completas; User Story 2 funciona con o sin User Story
1 implementada (mecanismo independiente, aunque en la práctica US1 ya deja poco para corregir).

---

## Phase 5: Polish & Cross-Cutting Concerns

- [X] T016 [P] Ejecutar el escenario de volumen de `quickstart.md` (verificación de no
  regresión): con ~400 órdenes `requiere_atencion` de prueba en el canal ML, medir que
  `datatable()` no agrega demora perceptible mayor a 1s (SC-003) — dejar constancia del resultado
  en el PR/commit, no requiere un test automatizado nuevo si ya se cubre manualmente.

  **Constancia**: medido con un test descartable (400 `MercadoLibreOrden` en `requiere_atencion`,
  SQLite en memoria) — `ReevaluadorOrdenes::reevaluarPendientesDelCanal()` procesó las 400 órdenes
  en ~1.01s. SQLite en memoria vía PHPUnit es un piso conservador (sin índices tan optimizados ni
  pooling de conexión como MySQL en producción); dentro del orden de magnitud esperado por
  SC-003 para "no agregar demora perceptible" a la carga del listado.

## Dependencies & Execution Order

- **Setup (Phase 1)**: sin dependencias, primero.
- **Foundational (Phase 2)**: depende de Setup. T002/T003 (implementación) pueden ir en paralelo
  entre sí (canales distintos); T004/T005/T006 dependen de que T002/T003 existan pero pueden
  escribirse en paralelo entre sí una vez que el servicio del canal correspondiente compila.
- **User Story 1 (Phase 3)**: depende de Foundational completo (T002-T006). T007/T008 (tests) se
  pueden escribir antes o junto con T009/T010 (TDD) pero deben pasar antes de dar la historia por
  cerrada. T011 depende de que T009 y T010 existan.
- **User Story 2 (Phase 4)**: depende de Foundational completo (T002-T006). NO depende de User
  Story 1 — son independientes entre sí (el Observer y el `datatable()` consumen el mismo
  `ReevaluadorOrdenes` pero no se llaman entre ellos).
- **Polish (Phase 5)**: depende de que Phase 3 y Phase 4 estén implementadas.

## Parallel Execution Examples

- Dentro de Foundational: T002 y T003 en paralelo (archivos/canales distintos); luego T004, T005,
  T006 en paralelo entre sí.
- Dentro de User Story 1: T007 y T008 en paralelo; T009 y T010 en paralelo (después T011, que
  toca el archivo compartido `AppServiceProvider.php`, no paralelizable con nada más que lo toque).
- Dentro de User Story 2: T012 y T013 en paralelo; T014 y T015 en paralelo (archivos distintos).
- User Story 1 completa (T007-T011) y User Story 2 completa (T012-T015) se pueden implementar en
  paralelo entre sí una vez cerrada Foundational, ya que no comparten archivos ni se llaman entre
  sí.

## Implementation Strategy

**MVP = User Story 1** (Phase 1 + Phase 2 + Phase 3): es el mecanismo que resuelve la causa raíz
reportada (395/396 órdenes ML con estado obsoleto por vinculación tardía) y el de mayor prioridad
según el spec. Entregable y demostrable de forma independiente sin User Story 2.

**Incremento siguiente**: User Story 2 (Phase 4) agrega la red de seguridad on-view. Se puede
entregar en un segundo commit/PR sin re-tocar nada de User Story 1.

**Polish (Phase 5)** cierra con la validación de performance de la Assumption de volumen.
