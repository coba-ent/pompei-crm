---
description: "Task list — Selección Múltiple y Acciones Masivas en Productos"
---

# Tasks: Selección Múltiple y Acciones Masivas en Productos

**Input**: Design documents from `specs/004-productos-acciones-masivas/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/acciones-masivas-rutas.md, quickstart.md

**Tests**: Se incluyen tests para las acciones con impacto económico directo (Principio IV de la
constitución): precio, costo, y la protección de "no eliminar con operaciones asociadas" en el
contexto de eliminación masiva. Las acciones de flags simples (mostrar/no mostrar en ventas/compras)
llevan cobertura liviana agrupada, sin repetir exhaustivamente lo ya validado en `002-productos`
para el alta/edición individual.

**Diseño obligatorio** (CLAUDE.md): todo por AJAX sin recargar · toasts de Toastr · Select2 en los
selects de Tipo de Producto/Proveedor del modal (`dropdownParent` al modal) · sin librerías nuevas
de selección de filas (research.md §1).

**Reuso**: extiende `ProductoController`/`productos.js`/`productos/index.blade.php` ya construidos
en `002-productos`; reutiliza `ReglasProducto` para validar los valores de cada acción y
`Producto::tieneOperaciones()` para la protección de eliminación.

**Organización**: por user story, en orden de prioridad, para entrega incremental.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: puede correr en paralelo (archivos distintos, sin dependencias pendientes)
- **[Story]**: US1..US2 (mapea a las historias de spec.md)

---

## Phase 1: Setup (Infraestructura compartida)

**Purpose**: sin dependencias nuevas — no hay librerías/assets que agregar (research.md §1: sin
extensión "Select" de DataTables, sin cambios en `config/dz.php`/`vite.config.js`).

- [X] T001 Verificar que `resources/js/productos.js` y `resources/views/productos/index.blade.php`
  compilan y cargan sin cambios previos rotos (`npm run build` + `php artisan test --filter=Producto`
  en verde) antes de empezar, como línea base.

**Checkpoint**: línea base verde confirmada, sin necesidad de nueva infraestructura de assets.

---

## Phase 2: Foundational (Prerrequisitos bloqueantes)

**Purpose**: el FormRequest de validación y el método base del controller que ambas user stories
necesitan (la selección sin acciones no tiene endpoint que probar; las acciones sin selección no
tienen quién las dispare — por eso comparten esta fase mínima).

**⚠️ CRITICAL**: bloquea ambas user stories.

- [X] T002 [P] `App\Http\Requests\AccionMasivaProductoRequest` (valida `accion` contra las 11 claves
  soportadas, `ids`/`todos`/`filtros` según contrato, y el `valor` con reglas condicionales
  reutilizando `ReglasProducto` para precio/costo/IVA/tipo_producto_id/proveedor_id/activo) en
  `app/Http/Requests/AccionMasivaProductoRequest.php`
- [X] T003 Registrar la ruta `POST productos/acciones-masivas` → `ProductoController@accionesMasivas`
  en `routes/web.php` (depende de T002)
- [X] T004 `ProductoController@accionesMasivas` — esqueleto: resuelve el conjunto de productos
  (`ids` explícitos o `todos + filtros` vía `queryFiltrada()`, research.md §2), despacha según
  `accion` a métodos privados por operación (aún vacíos) en `app/Http/Controllers/ProductoController.php`
  (depende de T003)

**Checkpoint**: la ruta responde (aunque las acciones individuales todavía no hagan nada), lista
para que ambas user stories avancen en paralelo sobre ella.

---

## Phase 3: User Story 1 - Seleccionar productos del listado (Priority: P1) 🎯 MVP parcial

**Goal**: checkbox por fila + "seleccionar todo" de la página + barra de selección con
"seleccionar los N que matchean el filtro", sin ejecutar ninguna acción todavía.

**Independent Test**: marcar filas y el checkbox de header, verificar el conteo de la barra y que
"Seleccionar los N" amplía la selección al total filtrado, sin necesidad de que Acciones Masivas
(US2) esté implementada.

### Implementation for User Story 1

- [X] T005 [US1] En `resources/views/productos/index.blade.php`, agregar columna de checkbox al
  `<thead>` de la tabla (con el checkbox "seleccionar todo") y el contenedor de la barra de
  selección (oculta por defecto) sobre la tabla — completa el shell existente
- [X] T006 [US1] En `resources/js/productos.js`, agregar la columna de checkbox renderizada en
  cliente (`data: null`, sin tocar las columnas ya definidas), un `Set` de IDs seleccionados en
  memoria, el handler del checkbox de header (marca/desmarca sólo las filas de la página visible),
  y la actualización de la barra de selección al cambiar la selección (depende de T005)
- [X] T007 [US1] En `resources/js/productos.js`, agregar el link "Seleccionar los N productos" de la
  barra (activa el modo `todos: true` + snapshot de los filtros vigentes) y la limpieza automática
  de la selección en el evento `draw`/`xhr` de la DataTable cuando cambian filtros, búsqueda, orden
  o página (FR-004) (depende de T006)
- [X] T008 [P] [US1] Feature test: `productos.data` no se ve afectado por esta historia (no hay
  endpoint nuevo que probar todavía — la selección es 100% cliente); en su lugar, test de humo que
  confirma que `GET /productos` sigue devolviendo 200 con la columna de checkbox presente en el
  HTML en `tests/Feature/ProductoListadoTest.php` (agregar un caso a los ya existentes, no un
  archivo nuevo)

**Checkpoint**: se puede seleccionar/deseleccionar filas y ver el conteo correcto (incluyendo
"todos los N"), de forma demostrable, aunque el botón de Acciones Masivas todavía no hospedra un
modal funcional.

---

## Phase 4: User Story 2 - Aplicar una acción masiva sobre los seleccionados (Priority: P1)

**Goal**: modal "Acciones Masivas" con las 11 operaciones, ejecutadas por AJAX sobre la selección de
la User Story 1.

**Independent Test**: con productos ya seleccionables (US1), abrir el modal, elegir cada una de las
11 acciones, confirmar, y verificar el efecto en la tabla y la respuesta del servidor — incluyendo
el caso especial de "Eliminar Masivamente" con productos protegidos.

### Tests for User Story 2 ⚠️

- [X] T009 [P] [US2] Feature test: `accionesMasivas` con `accion=precio_venta` aplica el nuevo precio
  a todos los productos del lote (`ids` explícitos), y rechaza (422, ningún producto modificado) si
  el valor es negativo (FR-006, FR-010, Principio IV) en `tests/Feature/ProductoAccionesMasivasTest.php`
- [X] T010 [P] [US2] Feature test: `accionesMasivas` con `accion=costo` — mismo patrón que T009,
  valida atomicidad del lote (Assumptions) en `tests/Feature/ProductoAccionesMasivasTest.php`
- [X] T011 [P] [US2] Feature test: `accionesMasivas` con `accion=eliminar` sobre un lote mixto
  (productos con y sin movimientos de stock) elimina sólo los que no tienen operaciones asociadas y
  devuelve el detalle de los no eliminados con motivo (FR-008, Principio IV) en
  `tests/Feature/ProductoAccionesMasivasTest.php`
- [X] T012 [P] [US2] Feature test: `accionesMasivas` con `todos: true` + `filtros` resuelve el mismo
  conjunto que `productos.data` devolvería con esos filtros (research.md §2) en
  `tests/Feature/ProductoAccionesMasivasTest.php`
- [X] T013 [P] [US2] Feature test: `accionesMasivas` con `accion=iva` actualiza tanto `iva_venta_pct`
  como `iva_compra_pct` de todos los productos del lote al mismo valor, y rechaza (422) un valor
  fuera de las opciones fijas (FR-009) en `tests/Feature/ProductoAccionesMasivasTest.php`
- [X] T014 [P] [US2] Feature test: `accionesMasivas` sin `accion` devuelve 422 con el mensaje "Elegí
  una acción" (acceptance scenario US2.7) en `tests/Feature/ProductoAccionesMasivasTest.php`

### Implementation for User Story 2

- [X] T015 [US2] Implementar en `ProductoController@accionesMasivas` las ramas de acciones de valor
  único (`precio_venta`, `costo`, `activo`, `iva` → actualiza `iva_venta_pct` e `iva_compra_pct`,
  `tipo_producto_id`, `proveedor_id`, `mostrar_ventas`/`no_mostrar_ventas`/`mostrar_compras`/
  `no_mostrar_compras`) dentro de una `DB::transaction()` — todo o nada por lote (data-model.md;
  depende de T004; cubre T009, T010, T013)
- [X] T016 [US2] Implementar en `ProductoController@accionesMasivas` la rama `eliminar` — evalúa
  cada producto del conjunto resuelto vía `tieneOperaciones()`, elimina los que no la tienen,
  acumula los que sí en la respuesta con motivo (depende de T004; cubre T011)
- [X] T017 [US2] `resources/views/productos/_modal_acciones_masivas.blade.php` (select "Elegí una
  Acción" con las 11 opciones en el orden relevado + contenedor del control de valor, oculto hasta
  elegir una acción con valor; Select2 para Tipo de Producto/Proveedor con `dropdownParent` al
  modal) — incluir desde `productos/index.blade.php`
- [X] T018 [US2] En `resources/js/productos.js`: abrir el modal desde el texto "Haga click aquí para
  realizar acciones" de la barra de selección, mostrar/ocultar el control de valor según la acción
  elegida, armar el payload (`ids`/`todos+filtros`, `accion`, `valor`) y hacer submit por AJAX a
  `productos.acciones-masivas`, refrescar la DataTable + stats y mostrar el resultado con toast
  (detalle de no-eliminados si aplica) (depende de T007, T017; cubre T012, T014)

**Checkpoint**: las dos user stories funcionan de punta a punta juntas — selección + acción masiva
completa, incluyendo la protección de eliminación y el modo "todos los que matchean el filtro".

---

## Phase 5: Polish & Cross-Cutting Concerns

**Purpose**: consistencia de documentación y validación final.

- [X] T019 [P] Documentar Selección Múltiple + Acciones Masivas como sección activa de
  `docs/documentacion_principal_crm.md` §2.2 (Productos), reemplazando la mención de brecha conocida
  en §4.1 (Principio I de la constitución)
- [X] T020 Ejecutar la validación de `specs/004-productos-acciones-masivas/quickstart.md` de punta a
  punta (los 12 escenarios)
- [X] T021 Correr `php artisan test --filter=ProductoAccionesMasivas` + `--filter=ProductoListado` +
  `--filter=ProductoAlta` y dejar toda la suite en verde
- [X] T022 `npm run build` final y revisión visual rápida en pantallas chicas de la barra de
  selección y el modal (reusar `public/css/contagram-custom.css`)

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Fase 1)**: sin dependencias.
- **Foundational (Fase 2)**: depende de Setup — BLOQUEA ambas historias.
- **User Stories (Fases 3-4)**: dependen de Foundational.
  - US1 (P1) primero — se puede demostrar sola (seleccionar/deseleccionar), aunque sin efecto real
    todavía.
  - US2 (P1) depende de US1 para tener algo que ejecutar sobre lo seleccionado, y de Foundational
    para el endpoint. Ambas son P1 porque juntas forman el único MVP con valor real: no tiene
    sentido entregar la selección sin las acciones, ni viceversa.
- **Polish (Fase 5)**: al final.

### Paralelizables

- Foundational: T002 en paralelo (único task de esa fase antes de la ruta/controller secuenciales).
- Tests de US2 (T009-T014) en paralelo entre sí (archivos/casos distintos dentro del mismo test
  file, sin dependencias pendientes entre ellos).
- T019 (documentación) en paralelo con T020-T022.

---

## Parallel Example: User Story 2

```bash
# Tests de User Story 2 en paralelo:
Task: "Feature test accion precio_venta (atomicidad) en tests/Feature/ProductoAccionesMasivasTest.php"
Task: "Feature test accion costo (atomicidad) en tests/Feature/ProductoAccionesMasivasTest.php"
Task: "Feature test accion eliminar (protección + detalle) en tests/Feature/ProductoAccionesMasivasTest.php"
Task: "Feature test modo todos+filtros en tests/Feature/ProductoAccionesMasivasTest.php"
Task: "Feature test accion iva (actualiza ambos campos) en tests/Feature/ProductoAccionesMasivasTest.php"
Task: "Feature test sin accion elegida (422) en tests/Feature/ProductoAccionesMasivasTest.php"
```

---

## Implementation Strategy

### MVP (recomendado)

1. Fase 1 (Setup) → Fase 2 (Foundational) → Fase 3 (US1) → Fase 4 (US2).
2. **STOP y validar**: se puede seleccionar un lote de productos y aplicar cualquiera de las 11
   acciones, con la protección de eliminación funcionando. Demo.

### Incremental

- No hay incremento posterior razonable dentro de esta feature — US1 y US2 son P1 porque forman un
  único MVP. La siguiente iteración natural es otra feature (Importar Datos, Depósitos, o el
  próximo módulo grande), no una extensión de ésta.

---

## Notes

- [P] = archivos distintos, sin dependencias pendientes.
- Verificar que T009, T010, T011, T012, T013 y T014 fallen antes de implementar la lógica que
  prueban (TDD en lo crítico: dinero e integridad de eliminación, Principio IV).
- Commit por task o grupo lógico; parar en cada checkpoint para validar la historia.
- Respetar SIEMPRE: todo por AJAX sin recargar, toasts de Toastr, Select2 en los selects dinámicos
  del modal.
- No agregar la extensión "Select" de DataTables ni ningún otro plugin nuevo (research.md §1) — la
  selección se resuelve con JS plano ya planificado en T006-T007.
