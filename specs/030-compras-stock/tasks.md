---

description: "Task list template for feature implementation"
---

# Tasks: Compras suman stock

**Input**: Design documents from `/specs/030-compras-stock/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/stock-de-compra.md, quickstart.md

**Tests**: Incluidos — la Constitución (Principio IV) exige tests para "movimientos de stock" sin
excepción.

**Organization**: Tareas agrupadas por user story (US1 alta, US2 edición, US3 baja), sobre una base
Foundational compartida (no tiene sentido dividir `StockDeCompra`/`StockService` entre historias: los
tres flujos comparten el mismo servicio y los mismos movimientos atómicos).

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Se puede ejecutar en paralelo (archivos distintos, sin dependencias)
- **[Story]**: A qué user story pertenece (US1, US2, US3)

## Path Conventions

Proyecto único Laravel (monolito): `app/`, `tests/` en la raíz del repo — ver plan.md §Project Structure.

---

## Phase 1: Setup

**Purpose**: No hay inicialización de proyecto nueva (Laravel ya está armado). Fase vacía — se omite.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Extender `StockService` y crear el esqueleto de `StockDeCompra` con el método común
(`aplicarAlta`) que las tres user stories necesitan.

**⚠️ CRITICAL**: Ninguna user story puede completarse sin esta fase.

- [X] T001 En `app/Services/Stock/StockService.php`, agregar `?string $fecha = null` como **último**
  parámetro (después de `$usuario`) de `registrarEntrada()`, `registrarSalida()` y del método privado
  `mover()` que ambos delegan — así ninguna llamada posicional existente de `StockDeVenta` (que no pasa
  `$usuario` ni `$fecha`) se rompe; usar `$fecha ?: now()->toDateString()` en el
  `MovimientoStock::create([...'fecha' => ...])` dentro de `mover()`.
- [X] T002 [P] Crear `app/Services/Egresos/StockDeCompra.php` (namespace `App\Services\Egresos`, espejo
  de `App\Services\Ingresos\StockDeVenta`): constructor con `StockService`; método privado
  `itemsQueMuevenStock(Collection $items)` que filtra `fn (CompraItem $item) => $item->producto &&
  $item->producto->controlaStock()`; método privado `depositoPorDefecto()` que devuelve
  `Deposito::porDefecto() ?? throw new \RuntimeException('No hay ningún depósito activo.')`.

**Checkpoint**: `StockService` extendido y `StockDeCompra` con su esqueleto — las user stories pueden
empezar.

---

## Phase 3: User Story 1 - Alta de Compra suma stock (Priority: P1) 🎯 MVP

**Goal**: Al guardar una Compra nueva, sumar stock por cada ítem cuyo producto controla stock, con la
`fecha_emision` de la Compra y origen polimórfico hacia la Compra.

**Independent Test**: Crear una Compra con un ítem de un producto que controla stock, cantidad 10;
verificar que el stock del depósito por defecto subió en 10 y que hay un `MovimientoStock` `tipo=entrada`
con `origen_type=Compra`.

### Tests for User Story 1 ⚠️

> Escribir primero, deben fallar antes de implementar T005/T006.

- [X] T003 [P] [US1] Test en `tests/Feature/CompraStockTest.php`:
  `test_alta_de_compra_suma_stock_de_items_que_controlan_stock()` — crea Compra con 1 ítem de producto
  `controla_stock=true` cantidad 10, asert stock depósito por defecto +10, y `MovimientoStock` existe con
  `tipo=entrada`, `cantidad=10`, `origen_type=Compra::class`, `origen_id=$compra->id`,
  `fecha=$compra->fecha_emision`.
- [X] T004 [P] [US1] Test en `tests/Feature/CompraStockTest.php`:
  `test_alta_de_compra_no_mueve_stock_de_items_que_no_controlan_stock()` — ítem de producto
  `controla_stock=false`, assert cero `MovimientoStock` generados para ese ítem.

### Implementation for User Story 1

- [X] T005 [US1] Agregar método público `aplicarAlta(Compra $compra): void` a `StockDeCompra`
  (`app/Services/Egresos/StockDeCompra.php`): filtra ítems con `itemsQueMuevenStock($compra->items)`; si
  está vacío, `return`; si no, resuelve `depositoPorDefecto()` y por cada ítem llama
  `$this->stock->registrarEntrada($item->producto, null, $deposito, (float) $item->cantidad, $compra,
  fecha: $compra->fecha_emision->toDateString())`.
- [X] T006 [US1] En `app/Http/Controllers/CompraController.php`, inyectar `StockDeCompra` en el
  constructor (junto a `CalculoComprobante`/`Pagos` existentes) y, en `store()`, después de
  `$this->guardarItems($compra, $resultado['items']);` (dentro de la misma transacción `DB::transaction`),
  llamar `$this->stockDeCompra->aplicarAlta($compra->load('items.producto'));`.

**Checkpoint**: Alta de Compra suma stock de forma atómica y testeada — MVP entregable.

---

## Phase 4: User Story 2 - Edición de Compra reajusta stock (Priority: P2)

**Goal**: Al editar una Compra, reintegrar el stock de la versión anterior y aplicar el de la nueva, neto
correcto.

**Independent Test**: Compra con ítem cantidad 10 (US1 la deja en +10); editar a cantidad 6; verificar
stock neto atribuible a la Compra = +6.

### Tests for User Story 2 ⚠️

- [X] T007 [P] [US2] Test en `tests/Feature/CompraStockTest.php`:
  `test_edicion_de_compra_reintegra_cantidad_anterior_y_aplica_la_nueva()` — Compra con ítem cantidad 10;
  editar a cantidad 6; assert stock neto de la Compra es +6 (no +16 ni +10), y quedan los movimientos de
  reintegro (−10) y reaplicación (+6) en `movimientos_stock`.
- [X] T008 [P] [US2] Test en `tests/Feature/CompraStockTest.php`:
  `test_edicion_de_compra_reemplaza_producto_del_item()` — Compra con ítem de producto A cantidad 5;
  editar reemplazando por producto B cantidad 5; assert stock de A vuelve a su valor previo y stock de B
  sube en 5.

### Implementation for User Story 2

- [X] T009 [US2] Agregar método público `reaplicarPorEdicion(Compra $compra, Collection $itemsAnteriores):
  void` a `StockDeCompra`: reintegra (via `registrarSalida`, mismo depósito por defecto, misma fecha
  `now()`) los ítems de `$itemsAnteriores` que muevan stock, y luego llama `$this->aplicarAlta($compra)`.
- [X] T010 [US2] En `CompraController::update()`, capturar `$itemsAnteriores =
  $compra->items()->with('producto')->get();` ANTES de `$compra->items()->delete();` (mismo patrón que
  `VentaController::update()`), y después de `$this->guardarItems($compra, $resultado['items']);` llamar
  `$this->stockDeCompra->reaplicarPorEdicion($compra->load('items.producto'), $itemsAnteriores);`.

**Checkpoint**: Alta + edición cubiertas y testeadas.

---

## Phase 5: User Story 3 - Baja de Compra reintegra stock (Priority: P2)

**Goal**: Al eliminar (soft-delete) una Compra, revertir todo el stock que había sumado.

**Independent Test**: Compra con ítem cantidad 10 (+10 en stock); eliminarla; verificar que el stock
vuelve al valor previo.

### Tests for User Story 3 ⚠️

- [X] T011 [P] [US3] Test en `tests/Feature/CompraStockTest.php`:
  `test_baja_de_compra_reintegra_stock_sumado()` — Compra con ítem cantidad 10; `$compra->delete()`;
  assert stock del producto vuelve al valor previo al alta, y queda `MovimientoStock` `tipo=salida`
  `cantidad=-10` (reintegro) con `origen_type=Compra` (corregido de "tipo=entrada": `registrarSalida()`
  siempre persiste `tipo=salida`, T012).

### Implementation for User Story 3

- [X] T012 [US3] Agregar método público `reintegrarPorEliminacion(Compra $compra): void` a
  `StockDeCompra`: filtra ítems que mueven stock y por cada uno llama `registrarSalida(...)` con la
  misma cantidad que había sumado `aplicarAlta` (sin `$fecha` explícito → usa el default `now()`).
- [X] T013 [US3] En `app/Observers/CompraObserver.php`, dentro del `DB::transaction` existente del método
  `deleting()` (después de revertir los pagos), agregar
  `App::make(\App\Services\Egresos\StockDeCompra::class)->reintegrarPorEliminacion($compra->load('items.producto'));`
  — mismo patrón exacto que `VentaObserver::deleting()` usa para `StockDeVenta`.

**Checkpoint**: Las tres user stories (alta/edición/baja) funcionan de forma independiente y testeada.

---

## Phase 6: Polish & Cross-Cutting Concerns

**Purpose**: Cierre de la feature — validar atomicidad de punta a punta y dejar corrido el quickstart.

- [X] T014 [P] Test en `tests/Feature/CompraStockTest.php`:
  `test_alta_de_compra_es_atomica_con_movimientos_de_stock()` — forzar un fallo de validación en un ítem
  posterior de la misma request (o simular excepción dentro de la transacción) y assert que no queda
  ningún `MovimientoStock` huérfano ni la Compra persistida (FR-007, Edge Cases).
- [ ] T015 Ejecutar `php artisan test --filter=CompraStockTest` y confirmar los 6 tests en verde.
- [ ] T016 Correr manualmente los 4 escenarios de `quickstart.md` contra el entorno local (XAMPP) para
  confirmar el comportamiento end-to-end, incluido el caso de ítem sin control de stock.

---

## Dependencies & Execution Order

### Phase Dependencies

- **Foundational (Phase 2)**: sin dependencias — bloquea todas las user stories.
- **US1 (Phase 3)**: depende de Phase 2. Es el MVP.
- **US2 (Phase 4)**: depende de Phase 2 y de que exista `aplicarAlta` (T005, de US1) porque
  `reaplicarPorEdicion` la reutiliza internamente — en la práctica se implementa después de US1 aunque
  conceptualmente es una historia independiente.
- **US3 (Phase 5)**: depende de Phase 2 y de `aplicarAlta` (T005) para tener stock que reintegrar en los
  tests, pero su implementación (`reintegrarPorEliminacion` + `CompraObserver`) es independiente de US2.
- **Polish (Phase 6)**: depende de que US1/US2/US3 estén completas.

### Parallel Opportunities

- T001 y T002 (Foundational) — archivos distintos, en paralelo.
- Dentro de cada user story, los tests marcados [P] (T003+T004, T007+T008) van en paralelo entre sí (van
  al mismo archivo `CompraStockTest.php` pero son métodos de test independientes sin dependencias
  compartidas de estado — ejecutar en paralelo aquí significa "escribirlos juntos", no correrlos
  concurrentemente, ya que PHPUnit corre un archivo en un solo proceso).
- T006 (US1), T010 (US2) y T013 (US3) tocan `CompraController.php`/`CompraObserver.php` en secuencia, no
  en paralelo entre sí.

---

## Implementation Strategy

### MVP First (User Story 1)

1. Phase 2 (Foundational): T001, T002.
2. Phase 3 (US1): T003–T006.
3. **Parar y validar**: correr T003/T004, confirmar verdes, probar Escenario 1 de quickstart.md a mano.
4. Con esto ya hay valor: las Compras nuevas suman stock (aunque editar/eliminar una Compra vieja todavía
   no reajuste — se agrega en US2/US3).

### Incremental Delivery

1. Foundational → US1 (MVP: alta suma stock) → US2 (edición reajusta) → US3 (baja reintegra) → Polish.
2. Cada fase deja el sistema en un estado consistente y testeado antes de pasar a la siguiente.

---

## Notes

- No hay Setup (Phase 1): no se agregan dependencias, migraciones ni configuración nueva.
- Sin tareas de UI/frontend: el formulario de Compra no cambia (spec.md FR-006/Assumptions).
- Todos los tests van en un único archivo `tests/Feature/CompraStockTest.php` (nuevo) siguiendo el
  patrón de `tests/Feature/NotaCreditoDebitoCompraTest.php` ya existente en el proyecto.
- Al terminar la implementación, correr `php artisan test` completo (no sólo el filtro) para confirmar
  que no se rompió nada en Ventas por el cambio de firma de `StockService::registrarEntrada/registrarSalida`.
