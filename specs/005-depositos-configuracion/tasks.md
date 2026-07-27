---
description: "Task list — Gestión de Depósitos"
---

# Tasks: Gestión de Depósitos

**Input**: Design documents from `specs/005-depositos-configuracion/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/depositos-rutas.md, quickstart.md

**Tests**: Se incluye test para la protección de "no eliminar con stock/movimientos asociados"
(Principio IV de la constitución — impacto en integridad de valorización de inventario). El resto
(alta, renombrado, toggle) lleva cobertura liviana agrupada en el mismo archivo, sin necesidad de un
test por acción dado el bajo riesgo (mismo patrón ya validado en `ListaPrecioController`).

**Diseño obligatorio** (CLAUDE.md): modal Bootstrap + AJAX sin recargar · toasts de Toastr ·
guardado inmediato por fila/acción, sin mecanismo de lote nuevo (research.md §1).

**Reuso**: espejo de `ListaPrecioController`/`TipoProductoController` (catálogos chicos ya
construidos en `002-productos`), con el agregado de `Deposito::tieneOperaciones()` (mismo patrón que
`Cliente`/`Proveedor`/`Producto`).

**Organización**: una sola user story (P1) — no hay historias adicionales que priorizar.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: puede correr en paralelo (archivos distintos, sin dependencias pendientes)
- **[Story]**: US1 (única historia de spec.md)

---

## Phase 1: Setup

**Purpose**: sin dependencias nuevas — Bootstrap modal + Toastr ya disponibles globalmente; sólo
falta el pagelevel de assets para la vista nueva.

- [X] T001 Agregar el entry JS `resources/js/configuracion-depositos.js` a `vite.config.js` (array
  `input`) para que Vite lo compile
- [X] T002 Agregar la entrada de página `configuracion-depositos` en `config/dz.php` (pagelevel),
  reutilizando Toastr (ya cargado en otras pantallas de Configuración)

**Checkpoint**: Vite compila el entry nuevo; Toastr disponible en la página.

---

## Phase 2: Foundational (Prerrequisitos bloqueantes)

**Purpose**: modelo, ruteo y shell de vista — la única user story no puede empezar hasta terminar
esta fase.

- [X] T003 [P] Agregar `tieneOperaciones(): bool` a `App\Models\Deposito` (existe stock con
  `cantidad != 0` o algún movimiento de stock con ese `deposito_id` — data-model.md) en
  `app/Models/Deposito.php`
- [X] T004 `App\Http\Controllers\DepositoController` (`index`, `data`, `store`, `update`, `estado`,
  `destroy` — espejo de `ListaPrecioController`, `destroy` usa `tieneOperaciones()` para el 409) en
  `app/Http/Controllers/DepositoController.php` (depende de T003)
- [X] T005 Registrar las rutas de Depósitos en `routes/web.php` según
  `contracts/depositos-rutas.md`, bajo el prefijo `configuracion/depositos`, gateadas con el
  middleware `permiso:configuracion.funciones` (ya seedeado en `PermisoSeeder`, sin permiso nuevo
  que crear) (depende de T004)
- [X] T006 [P] Agregar la entrada "Depósitos" al submenú "Configuración & Ajustes" del sidebar
  (`resources/views/elements/sidebar.blade.php`), apuntando a `configuracion.depositos.index`,
  detrás de `@can('configuracion.funciones')`
- [X] T007 [P] Vista shell `resources/views/configuracion/depositos.blade.php` extendiendo
  `layouts.default`: botón "Configurar Depósitos" + include del modal (vacío) + `@section('local-js')`
  cargando `configuracion-depositos.js`

**Checkpoint**: `/configuracion/depositos` carga sin errores, ruteo resuelto, modelo con la regla de
negocio lista.

---

## Phase 3: User Story 1 - Administrar el catálogo de Depósitos (Priority: P1) 🎯 MVP

**Goal**: alta, renombrado, activar/desactivar y eliminar depósitos desde una pantalla propia,
reemplazando la gestión actual por seeder/DB directa.

**Independent Test**: crear, renombrar, desactivar, reactivar y eliminar un depósito desde
Configuración & Ajustes → Depósitos, y verificar que el filtro "Depósito" de Productos refleja cada
cambio, sin depender de ningún otro módulo nuevo.

### Tests for User Story 1 ⚠️

- [X] T008 [P] [US1] Feature test: alta, renombrado y toggle de estado de un depósito persisten
  correctamente y son consumidos por `Deposito::activos()` (FR-003, FR-004, FR-008) en
  `tests/Feature/DepositoTest.php`
- [X] T009 [P] [US1] Feature test: no se puede eliminar físicamente un depósito con stock
  (`cantidad != 0`) o movimientos de stock asociados — espera HTTP 409 y mensaje "sólo puede
  inactivarse"; sí se puede eliminar uno sin asociaciones (FR-005, Principio IV) en
  `tests/Feature/DepositoTest.php`

### Implementation for User Story 1

- [X] T010 [US1] `DepositoController@index` (vista) y `@data` (lista completa sin paginado,
  ordenada por nombre — research.md, catálogo chico) en `app/Http/Controllers/DepositoController.php`
  (depende de T004, cubre parte de T008)
- [X] T011 [US1] `DepositoController@store`/`@update` (valida `nombre` requerido, crea/renombra) en
  `app/Http/Controllers/DepositoController.php` (depende de T010, cubre T008)
- [X] T012 [US1] `DepositoController@estado`/`@destroy` (toggle activo/inactivo; `destroy` rechaza
  con 409 si `tieneOperaciones()`, si no elimina físicamente) en
  `app/Http/Controllers/DepositoController.php` (depende de T010, cubre T008, T009)
- [X] T013 [US1] `resources/views/configuracion/_modal_depositos.blade.php` ("Configuración de
  Depósitos": lista editable inline con nombre/checkbox activo con tooltip/editar/eliminar, "+
  Agregar Depósito", Cancelar/Guardar — completa el shell de T007)
- [X] T014 [US1] `resources/js/configuracion-depositos.js` (carga la lista al abrir el modal, alta
  inline, renombrado inline, toggle de activo, eliminar con confirmación, cada uno por AJAX
  inmediato con su propio toast — research.md §1)

**Checkpoint**: Depósitos funciona de punta a punta; el filtro y el selector de depósito de
Productos reflejan los cambios sin tocar código de Productos.

---

## Phase 4: Polish & Cross-Cutting Concerns

**Purpose**: consistencia de documentación y validación final.

- [X] T015 [P] Actualizar `docs/documentacion_principal_crm.md` §2.2: reemplazar la mención de "alta/
  baja vía seeder/DB directa" por una sección activa describiendo Configuración & Ajustes →
  Depósitos (Principio I de la constitución)
- [X] T016 [P] Agregar en `docs/modelo_datos.md` la nota sobre `Deposito::tieneOperaciones()`
- [X] T017 Ejecutar la validación de `specs/005-depositos-configuracion/quickstart.md` de punta a
  punta (los 9 escenarios)
- [X] T018 Correr `php artisan test --filter=Deposito` y dejar la suite en verde
- [X] T019 `npm run build` final y `php artisan route:list` para confirmar que todas las rutas de
  `contracts/depositos-rutas.md` están registradas sin errores

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Fase 1)**: sin dependencias.
- **Foundational (Fase 2)**: depende de Setup — BLOQUEA la única user story.
- **User Story (Fase 3)**: depende de Foundational.
- **Polish (Fase 4)**: al final.

### Paralelizables

- Foundational: T003, T006, T007 en paralelo (T004/T005 son secuenciales entre sí, dependen de T003).
- Tests de US1 (T008, T009) en paralelo entre sí.
- T015/T016 (documentación) en paralelo con T017-T019.

---

## Implementation Strategy

### MVP (recomendado)

1. Fase 1 (Setup) → Fase 2 (Foundational) → Fase 3 (US1).
2. **STOP y validar**: se puede crear/renombrar/activar/desactivar/eliminar un depósito y verlo
   reflejado en Productos. Demo.

### Incremental

- No aplica — feature de una sola historia. La siguiente iteración natural es otra feature
  (Acciones Masivas o Importar Datos), no una extensión de ésta.

---

## Notes

- [P] = archivos distintos, sin dependencias pendientes.
- Verificar que T008 y T009 fallen antes de implementar la lógica que prueban (TDD en lo crítico:
  integridad de stock, Principio IV).
- Commit por task o grupo lógico; parar en el checkpoint para validar la historia.
- Respetar SIEMPRE: modal Bootstrap + AJAX sin recargar, toasts de Toastr.
- No introducir un mecanismo de "guardar en lote" nuevo (research.md §1) — cada acción del modal es
  su propia llamada AJAX inmediata, ya planificado en T014.
