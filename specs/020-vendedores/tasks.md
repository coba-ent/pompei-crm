# Tasks: Vendedores como catálogo propio

**Spec**: [spec.md](./spec.md) · **Plan**: [plan.md](./plan.md) · **Datos**: [data-model.md](./data-model.md) · **Contratos**: [contracts/rutas-internas.md](./contracts/rutas-internas.md) · **Validación**: [quickstart.md](./quickstart.md)

**Branch**: `020-vendedores` · **Fecha**: 2026-07-30

**Tests**: incluidos para las dos áreas de riesgo real identificadas en el Constitution Check del plan
(principio IV) — integridad de la migración de datos históricos (irreversible sobre datos reales) y el
bloqueo de borrado por uso (regla de integridad referencial compartida por 4 tablas). El resto del ABM
es CRUD simple calcado de un patrón ya probado (Categorías) y no requiere tests estrictos por
constitución, aunque se agrega uno de humo por prolijidad.

**Convención**: `[P]` = paralelizable (archivo distinto, sin dependencias pendientes). `[USn]` = historia
de usuario a la que pertenece.

---

## Phase 1 — Setup

- [ ] T001 Confirmar que no hace falta ninguna dependencia nueva: Eloquent, Yajra DataTables, Select2,
  Bootstrap modales y Toastr ya están en uso desde las specs 008/009 (plan.md §Technical Context) — sin
  acción, sólo checkpoint

---

## Phase 2 — Foundational (bloquea todas las historias)

### 2.a Esquema de datos

- [ ] T002 Migración `create_vendedores_table`: `id`, `nombre` string(255) **unique**, timestamps
  (data-model.md §`vendedores`)
- [ ] T003 Migración `migrar_vendedor_id_de_users_a_vendedores_en_ventas_y_presupuestos` — un único
  archivo con dos fases (research.md R2, corregido en análisis; data-model.md §Migración de datos
  históricos): fase de **datos**, envuelta en `DB::transaction()` real — (1) selecciona la unión de
  `vendedor_id` distintos no nulos de `ventas` y `presupuestos`; (2) inserta un `Vendedor` por cada
  `user_id` de ese conjunto con `nombre = users.name`, guardando el mapeo `user_id → vendedores.id`;
  (3) actualiza `ventas.vendedor_id`/`presupuestos.vendedor_id` con el id mapeado — y fase de
  **esquema** (DDL, separada y no atómica con la anterior en MySQL): (4) dropea la FK hacia `users` y
  crea la nueva FK hacia `vendedores` (`nullable`, `restrictOnDelete`) en ambas tablas. Si (4) fallara,
  los datos de (1)-(3) ya quedaron a salvo — FR-008, SC-002
- [ ] T004 [P] Migración `add_vendedor_id_to_tn_configuracion_table`: columna `vendedor_id` (FK →
  vendedores, nullable, `restrictOnDelete`) — data-model.md §`tn_configuracion`, FR-010
- [ ] T005 [P] Migración `add_vendedor_id_to_ml_configuracion_table`: columna `vendedor_id` (FK →
  vendedores, nullable, `restrictOnDelete`) — data-model.md §`ml_configuracion`, FR-010

### 2.b Modelos

- [ ] T006 [P] Crear `app/Models/Vendedor.php`: `$fillable = ['nombre']`, sin scopes especiales
  (research.md R1)
- [ ] T007 Extender `app/Models/Venta.php:58-61` — `vendedor(): BelongsTo` pasa de
  `User::class` a `Vendedor::class` (FR-007, plan.md §Enfoque técnico 6)
- [ ] T008 Extender `app/Models/Presupuesto.php:54-57` — idéntico cambio que T007

### 2.c Correcciones inmediatas por el retarget de FK (rompen si no se hacen junto con T002-T003)

- [ ] T009 [P] `app/Http/Controllers/VentaController.php:46` — `->with([... 'vendedor:id,name' ...])`
  pasa a `'vendedor:id,nombre'`; línea 89 `->addColumn('vendedor', fn ($v) => optional($v->vendedor)->name)`
  pasa a `->nombre`
- [ ] T010 [P] Buscar y corregir el mismo patrón (`with(['...vendedor:id,name'])` /
  `optional(...->vendedor)->name`) en `app/Http/Controllers/PresupuestoController.php` (listado/datatable)
- [ ] T011 [P] `resources/views/ventas/detalle.blade.php:129` — `optional($venta->vendedor)->name` →
  `->nombre`
- [ ] T012 [P] `resources/views/ventas/pdf.blade.php:47` — idéntico cambio que T011
- [ ] T013 [P] `resources/views/presupuestos/documento.blade.php:47` — idéntico cambio que T011
- [ ] T014 [P] `resources/views/presupuestos/pdf.blade.php:45` — idéntico cambio que T011
- [ ] T015 Corregir `->load([...'vendedor'...])` en `VentaController::show()`/`pdf()`
  (`app/Http/Controllers/VentaController.php:258,267`) y `PresupuestoController::show()`/`pdf()` — sin
  cambio de código real (siguen cargando la relación `vendedor`), sólo confirmar que compilan tras T007/T008

**Checkpoint**: esquema y modelos migrados; la app sigue funcionando exactamente igual que antes (mismos
datos, mismas pantallas) — las historias de usuario pueden empezar.

---

## Phase 3 — US1: Elegir vendedor al cargar una Venta o Presupuesto (Priority: P1) 🎯 MVP

**Objetivo**: reemplazar el autocompletado silencioso por un select explícito y opcional en ambos
formularios. **Test independiente**: abrir Nueva Venta, elegir un vendedor ya existente (de los
migrados en T003), guardar, y confirmar que el listado/detalle/PDF lo muestran.

### Implementación para US1

- [ ] T016 [P] [US1] `app/Http/Requests/StoreVentaRequest.php` / `UpdateVentaRequest.php` — agregar regla
  `'vendedor_id' => 'nullable|integer|exists:vendedores,id'` (contracts/rutas-internas.md §Formularios)
- [ ] T017 [P] [US1] `app/Http/Requests/StorePresupuestoRequest.php` / `UpdatePresupuestoRequest.php` —
  idéntica regla que T016
- [ ] T018 [US1] `app/Http/Controllers/VentaController.php:162` — reemplazar
  `'vendedor_id' => $request->user()?->id` por `'vendedor_id' => $datos['vendedor_id'] ?? null` en
  `store()` (FR-009); agregar el mismo campo en `update()` si corresponde
- [ ] T019 [US1] `app/Http/Controllers/PresupuestoController.php:103,139` — idéntico cambio que T018
  (reemplazar `$vendedorId = $request->user()?->id` por tomarlo de `$datos['vendedor_id'] ?? null`)
- [ ] T020 [US1] `app/Http/Controllers/VentaController.php` — en `create()` (línea ~100-118) y `edit()`
  (línea ~190-199), agregar `'vendedores' => Vendedor::orderBy('nombre')->get()` al `compact(...)` que se
  pasa a la vista, junto a `categoriasVenta`
- [ ] T021 [US1] `app/Http/Controllers/PresupuestoController.php` — idéntico cambio que T020 en sus
  `create()`/`edit()`
- [ ] T022 [US1] `resources/views/ventas/form.blade.php` — agregar el bloque de campo "Vendedor": un
  `<select id="f-vendedor">` junto al de Categoría, sin los botones de renombrar/eliminar todavía (se
  agregan en US2); pasar `$vendedores` a `window.VentaFormData` (mismo patrón que `categoriasVenta` en la
  línea 180 actual)
- [ ] T023 [US1] `resources/views/presupuestos/form.blade.php` — idéntico bloque que T022
- [ ] T024 [US1] `resources/js/ventas.js` — inicializar Select2 en `#f-vendedor` (`initSelect2`, mismo
  patrón que `#f-lista-precio`: `placeholder: 'Seleccioná un Vendedor', allowClear: true`), poblar las
  opciones desde `cfg.vendedores`, precargar el valor si el formulario es de edición, e incluir
  `vendedor_id: $('#f-vendedor').val() || null` en `payload()`
- [ ] T025 [US1] `resources/js/presupuestos.js` — idéntico cambio que T024

**Checkpoint**: Venta y Presupuesto permiten elegir Vendedor de una lista ya poblada por la migración;
guardan y muestran el valor correctamente. Sin ABM inline todavía (US2).

---

## Phase 4 — US2: Crear, renombrar y eliminar vendedores desde el mismo select (Priority: P1)

**Objetivo**: ABM inline calcado de Categorías. **Test independiente**: desde el select de Vendedor de
cualquiera de los dos formularios, crear un vendedor nuevo, renombrarlo, y eliminarlo (con y sin uso).

### Tests para US2 (integridad de la regla de bloqueo por uso — constitución principio IV)

- [ ] T026 [P] [US2] `tests/Feature/VendedorTest.php`: crear (nombre único, rechaza duplicado), renombrar,
  eliminar sin uso (éxito), eliminar con una Venta asociada (422 "está en uso"), eliminar con un
  Presupuesto asociado (422) — FR-002, FR-004, FR-005, FR-006

### Implementación para US2

- [ ] T027 [US2] Crear `app/Http/Controllers/VendedorController.php` calcado de
  `app/Http/Controllers/CategoriaController.php` (líneas 63-116): `store()` (nombre único),
  `update()` (`Rule::unique(...)->ignore($id)`), `destroy()` (`delete()` + `catch (QueryException)` → 422
  "No se puede eliminar: está en uso.") — **sin** lógica de `tipo`/`es_sistema`/jerarquía (research.md R4)
- [ ] T028 [US2] `routes/web.php` — agregar, cerca del bloque de rutas de `categorias-venta` (líneas
  215-217/247-248): `POST vendedores` (`vendedores.store`), `PATCH vendedores/{vendedor}`
  (`vendedores.update`), `DELETE vendedores/{vendedor}` (`vendedores.destroy`) — **sin** el sufijo
  `-venta` (research.md R4, corregido en análisis: Vendedor no tiene `tipo`, un único endpoint sirve a
  los 4 puntos de uso) — contracts/rutas-internas.md
- [ ] T029 [US2] `resources/views/ventas/form.blade.php` — agregar al bloque de T022: opción
  "＋ Crear Vendedor" en el select, botones renombrar/eliminar, y los modales de crear/renombrar y de
  confirmación de borrado (duplicar los modales `#modal-nueva-categoria`/`#modal-categoria-eliminar` como
  `#modal-nuevo-vendedor`/`#modal-vendedor-eliminar`)
- [ ] T030 [US2] `resources/views/presupuestos/form.blade.php` — idéntico cambio que T029
- [ ] T031 [US2] `resources/js/ventas.js` — extender el bloque de T024 duplicando línea por línea el
  bloque "Categoría de ventas" (líneas 159-428 actuales) para Vendedor: `renderVendedores()`,
  `abrirModalVendedor()`, handlers de crear/renombrar/eliminar contra las rutas de T028 — **sin** manejo
  de `es_sistema` (ningún vendedor es del sistema)
- [ ] T032 [US2] `resources/js/presupuestos.js` — idéntico cambio que T031

**Checkpoint**: Vendedor tiene el mismo ABM inline que Categoría en ambos formularios, sin pantalla de
administración separada (FR-012).

---

## Phase 5 — US3: Vendedor por defecto para ventas automáticas de Tiendanube y MercadoLibre (Priority: P2)

**Objetivo**: extender el mecanismo ya existente de "categoría de venta por defecto" con "vendedor por
defecto", independiente por integración. **Test independiente**: configurar un vendedor por defecto en
Tiendanube, convertir una orden de prueba a Venta, y confirmar que queda asignado (repetir en
MercadoLibre, confirmando independencia).

### Tests para US3

- [ ] T033 [P] [US3] `tests/Feature/Integraciones/VendedorPorDefectoTest.php`: con vendedor por defecto
  configurado en Tiendanube, `ConversorOrdenAVenta` asigna ese `vendedor_id`; sin default configurado, la
  Venta se crea con `vendedor_id` null; repetir para MercadoLibre; confirmar que cambiar el default de una
  integración no afecta a la otra — FR-010, FR-011, SC-004

### Implementación para US3

- [ ] T034 [P] [US3] `app/Models/Integraciones/TiendanubeConfiguracion.php` — agregar `vendedor_id` a
  `$fillable` (línea 26) y el método `vendedor(): BelongsTo(Vendedor::class, 'vendedor_id')` calcado de
  `categoriaVenta()` (líneas 81-84)
- [ ] T035 [P] [US3] `app/Models/Integraciones/MercadoLibreConfiguracion.php` — idéntico cambio que T034
  (`$fillable` línea 20, método calcado de `categoriaVenta()` líneas 71-74)
- [ ] T036 [P] [US3] `app/Http/Requests/Integraciones/GuardarConfiguracionVentasTiendanubeRequest.php` —
  agregar `'vendedor_id' => 'nullable|integer|exists:vendedores,id'`
- [ ] T037 [P] [US3] `app/Http/Requests/Integraciones/GuardarConfiguracionVentasMercadoLibreRequest.php` —
  idéntica regla que T036
- [ ] T038 [US3] `app/Http/Controllers/Integraciones/TiendanubeConfiguracionController.php::index()`
  (líneas 28-41) — agregar `'vendedores' => Vendedor::orderBy('nombre')->get()` al `compact(...)`;
  `estado()` (líneas 43-88) — agregar `'vendedor_id' => $configuracion->vendedor_id` al bloque
  `configuracion` de la respuesta (junto a `categoria_venta_id`, línea 72)
- [ ] T039 [US3] `app/Http/Controllers/Integraciones/MercadoLibreConfiguracionController.php` — idéntico
  cambio que T038 en su `index()`/`estado()`
- [ ] T040 [US3] `resources/views/configuracion/tiendanube/index.blade.php` — agregar el select "Vendedor
  por defecto" junto al de "Categoría de venta por defecto", reutilizando el mismo patrón de ABM inline
  de US2 (rutas de T028)
- [ ] T041 [US3] `resources/views/configuracion/mercadolibre/index.blade.php` — idéntico cambio que T040
- [ ] T042 [US3] `resources/js/tiendanube.js` — incluir `vendedor_id` en el payload de `guardarVentas()` y
  poblar/renderizar el select nuevo (mismo patrón que `categoria_venta_id`)
- [ ] T043 [US3] `resources/js/mercadolibre.js` — idéntico cambio que T042
- [ ] T044 [US3] `app/Services/Tiendanube/ConversorOrdenAVenta.php:144` (línea con
  `'categoria_id' => TiendanubeConfiguracion::actual()->categoria_venta_id`) — agregar
  `'vendedor_id' => TiendanubeConfiguracion::actual()->vendedor_id` (FR-011)
- [ ] T045 [US3] `app/Services/MercadoLibre/ConversorOrdenAVenta.php` — idéntico cambio que T044 en la
  línea equivalente que asigna `categoria_id`

**Checkpoint**: las Ventas automáticas de ambas integraciones quedan asignadas al vendedor por defecto
configurado, de forma independiente entre integraciones.

---

## Phase 6 — Polish & Cross-Cutting Concerns

- [ ] T046 [P] `tests/Feature/VendedorMigracionDatosTest.php`: sembrar Ventas/Presupuestos de prueba con
  `vendedor_id` apuntando a `users` **antes** de correr T003 (usando un estado de base previo a la
  migración, o revirtiendo/re-corriendo en un entorno de test aislado) y confirmar que, después de
  migrar, el 100% conserva un Vendedor equivalente por nombre (SC-002) — este es el test de mayor riesgo
  real de la spec, por ser una migración irreversible sobre datos existentes (constitución principio IV)
- [ ] T047 Correr `specs/020-vendedores/quickstart.md` de punta a punta manualmente (los 5 bloques de
  validación) antes de dar la feature por terminada
- [ ] T048 Revisar que ningún otro punto del código además de los listados en T009-T015 siga leyendo
  `->vendedor->name` o `vendedor:id,name` (grep de `vendedor.*name` sobre `app/` y `resources/views/`)

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: sin dependencias.
- **Foundational (Phase 2)**: depende de Setup — **bloquea** las tres historias de usuario (crea la
  tabla `vendedores`, retarget de FK, y corrige todo lo que se rompería con el retarget).
- **User Stories (Phase 3-5)**: todas dependen de Foundational. US3 (Phase 5) además depende de que
  `VendedorController`/rutas de US2 (Phase 4, T027-T028) ya existan, porque reutiliza el mismo ABM inline
  para el select de "vendedor por defecto" — es la única dependencia cruzada entre historias.
- **Polish (Phase 6)**: depende de que las historias que se vayan a entregar estén completas.

### User Story Dependencies

- **US1 (P1)**: puede empezar apenas termina Foundational. Sin dependencia de US2/US3 (los vendedores
  para elegir ya existen por la migración de T003).
- **US2 (P1)**: puede empezar apenas termina Foundational, en paralelo con US1 si hay dos personas
  (tocan bloques distintos de los mismos archivos de formulario — ver nota de conflicto abajo).
- **US3 (P2)**: depende de Foundational **y** de que US2 haya creado `VendedorController`/rutas
  (T027-T028); no depende de US1.

**Nota de conflicto de archivos**: US1 (T022-T025) y US2 (T029-T032) tocan los mismos 4 archivos
(`ventas/form.blade.php`, `presupuestos/form.blade.php`, `resources/js/ventas.js`,
`resources/js/presupuestos.js`). No son tareas `[P]` entre sí — si se trabajan en paralelo por dos
personas, coordinar el orden de merge (US1 primero, US2 extiende encima).

### Parallel Opportunities

- T002, T004, T005 (migraciones de esquema) son `[P]` entre sí (T003 depende de que T002 exista primero,
  no es paralelo a T002).
- T006 es `[P]` respecto de las migraciones.
- T009-T014 son `[P]` entre sí (archivos distintos).
- T016-T017 son `[P]` entre sí; T034-T037 son `[P]` entre sí.
- US1 y US2 pueden trabajarse en paralelo por personas distintas respetando la nota de conflicto de
  archivos de arriba.

---

## Implementation Strategy

### MVP primero (US1 solamente)

1. Completar Phase 1 (Setup) y Phase 2 (Foundational) — sin esto no compila nada.
2. Completar Phase 3 (US1): ya se puede elegir un vendedor migrado al cargar una Venta/Presupuesto.
3. **Parar y validar** con el bloque 2 de `quickstart.md`.

### Entrega incremental

1. Setup + Foundational → base lista.
2. US1 → validar → (opcional) demo: el campo Vendedor ya es útil aunque el catálogo no crezca todavía.
3. US2 → validar → demo: el catálogo se puede hacer crecer sin salir del formulario.
4. US3 → validar → demo: las integraciones automáticas dejan de depender de asignación manual.
5. Polish (tests de migración + verificación final).
