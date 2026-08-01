---

description: "Task list for 027-importador-upsert-por-id"

---

# Tasks: Importador de Datos — Actualizar por Id (Upsert)

**Input**: Design documents from `/specs/027-importador-upsert-por-id/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, quickstart.md

**Tests**: Incluidos — Principio IV de la constitución exige tests para `saldo_inicial` (dinero) y para que la
actualización parcial no pise campos no mapeados.

**Organization**: Tareas agrupadas por historia de usuario (spec.md) para poder implementar y validar cada una de
forma independiente.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Puede ejecutarse en paralelo (archivos distintos, sin dependencias pendientes)
- **[Story]**: A qué historia de usuario pertenece (US1, US2)

## Path Conventions

Single project Laravel — `app/`, `tests/`, `docs/` en la raíz del repo (ver plan.md §Project Structure).

---

## Phase 1: Setup

**Purpose**: no hay inicialización de proyecto nueva — el mecanismo base (spec 006) y los campos ampliados
(spec 026) ya existen. Esta fase queda vacía a propósito.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: agregar el campo destino "Id" al diccionario de las 3 entidades y la resolución alta/actualización
por fila que TODAS las historias de usuario necesitan.

**⚠️ CRITICAL**: No se puede empezar ninguna historia de usuario sin esta fase completa.

- [X] T001 En `DefinicionCamposImportables::clientes()`, agregar `'id' => ['etiqueta' => 'Id', 'obligatorio' =>
  false, 'id' => true]` en `app/Services/Import/DefinicionCamposImportables.php` (research.md §1)
- [X] T002 [P] En `DefinicionCamposImportables::productos()`, agregar el mismo campo "Id" (research.md §1) —
  independiente de T001 porque `productos()` no parte de `clientes()` (a diferencia de `proveedores()`)
- [X] T003 En `app/Http/Requests/Import/ReglasClienteImportacion.php`, agregar `reglasActualizacion(?int $id =
  null): array` que llama a `reglasCliente($id)` y reemplaza `'required'` por `'nullable'` en `nombre`
  (research.md §3, depende de T001)
- [X] T004 [P] En `app/Http/Requests/Import/ReglasProveedorImportacion.php`, agregar
  `reglasActualizacion(?int $id = null): array` equivalente para Proveedor (research.md §3) — independiente de
  T003 (archivo distinto)
- [X] T005 [P] En `app/Http/Requests/Import/ReglasProductoImportacion.php`, agregar
  `reglasActualizacion(?int $id = null): array` equivalente para Producto, relajando `nombre` y `tipo`
  (research.md §3) — independiente de T003/T004 (archivo distinto)
- [X] T006 En `ImportadorFilas` (`app/Services/Import/ImportadorFilas.php`), extraer la resolución de modo a un
  método privado dedicado `resolverModoFila(array $datos, string $entidad): array` (devuelve algo como
  `['modo' => 'alta'|'actualizacion'|'fallida', 'registro' => ?Model, 'motivo' => ?string]`), invocado desde
  `importar()` después de `mapearFila()`, según `$datos['id'] ?? null` (research.md §2):
  - ausente/vacío → `modo: 'alta'` (flujo actual sin cambios)
  - no numérico o no entero → `modo: 'fallida'`, motivo "Id \"{valor}\" no es un id válido" (sin llegar a
    `validarFila()`)
  - numérico entero sin match en la tabla de la entidad → `modo: 'fallida'`, motivo "Id {valor} no encontrado"
  - numérico entero con match → `modo: 'actualizacion'`, `registro` = el modelo encontrado — delega en el flujo
    de actualización de T007
  Extraerlo como método propio (en vez de dejarlo inline en `importar()`) es lo que permite testearlo
  unitariamente en T008 sin tener que montar un archivo `.xlsx` completo por caso. (depende de T001, T002)
- [X] T007 En `ImportadorFilas` (`app/Services/Import/ImportadorFilas.php`), agregar el flujo de actualización:
  quitar `id` de `$datos`, construir reglas con `reglasActualizacion($id)` del adaptador correspondiente
  (T003-T005), validar, y si es válido llamar a `$registro->update($datos)` en vez de `crear()`; si inválido,
  fila fallida con el motivo de `validarFila()` (research.md §3, depende de T003-T006)

**Checkpoint**: la resolución alta/actualización por Id está lista y cubierta por sus propios tests unitarios
antes de escribir los tests de integración por historia.

- [X] T008 [P] Test unitario de la resolución de modo por fila (`ausente`→alta, `""`→alta, `"5"` con match→
  actualización, `"5"` sin match→fallida, `"abc"`→fallida, `"5,5"`/`"5.5"`→fallida) en
  `tests/Unit/ImportadorFilasResolucionIdTest.php` (research.md §2)

---

## Phase 3: User Story 1 - Corregir datos de clientes ya cargados sin recrearlos (Priority: P1) 🎯 MVP

**Goal**: la solapa Clientes actualiza parcialmente un cliente existente cuando la columna "Id" está mapeada y
coincide, sin exigir campos obligatorios ni pisar datos no mapeados.

**Independent Test**: con clientes ya creados, subir un archivo con columna Id + una o dos columnas de dato a
corregir, mapear, confirmar, y verificar en `/clientes` que cada cliente coincidente quedó actualizado y el resto
de sus datos intacto — sin depender de Proveedores ni de Productos.

### Tests for User Story 1 ⚠️

> Escribir estos tests primero y verificar que fallan antes de implementar.

- [X] T009 [P] [US1] Feature test: columna Id mapeada + Saldo Inicial → cliente existente actualiza
  `saldo_inicial` y conserva nombre/email/domicilio/etc. sin cambios, en `tests/Feature/ImportacionDatosTest.php`
  (FR-002, FR-003, Principio IV — dinero)
- [X] T010 [P] [US1] Feature test: columna Id con valor sin match → fila fallida "Id … no encontrado", ningún
  cliente nuevo creado, en `tests/Feature/ImportacionDatosTest.php` (FR-004)
- [X] T011 [P] [US1] Feature test: columna Id con valor no numérico y con valor no entero (ej. "abc" y "5,5") →
  ambas filas fallidas, en `tests/Feature/ImportacionDatosTest.php` (FR-008, Edge Cases)
- [X] T012 [P] [US1] Feature test: fila de actualización sin mapear "Nombre" (o celda vacía) → se procesa igual,
  cliente conserva su nombre actual (no se exige obligatorio en actualización), en
  `tests/Feature/ImportacionDatosTest.php` (FR-006)
- [X] T013 [P] [US1] Feature test: fila de actualización que remapea el mismo CUIT que el cliente ya tiene → no
  falla por "ya existe" (unicidad con `ignore($id)`), en `tests/Feature/ImportacionDatosTest.php` (FR-011)
- [X] T014 [P] [US1] Feature test: columna Id con celda vacía en una fila puntual → esa fila se procesa como alta
  nueva (comportamiento sin cambios), en `tests/Feature/ImportacionDatosTest.php` (FR-005)

### Implementation for User Story 1

- [X] T015 [US1] Verificar en la vista `resources/views/importacion/mapear.blade.php` que "Id" aparece en el
  select de Clientes sin cambios de código (itera `$definicion` dinámicamente — plan.md §Structure Decision);
  si algo no matchea, ajustar `DefinicionCamposImportables::clientes()` (T001) — no la vista (depende de T001)

**Checkpoint**: actualizar Clientes por Id de punta a punta funciona y está probado independientemente.

---

## Phase 4: User Story 2 - Mismo mecanismo para Proveedores y Productos (Priority: P2)

**Goal**: la solapa Proveedores y la solapa Productos & Servicios ofrecen el mismo comportamiento de
actualización por Id que Clientes.

**Independent Test**: con proveedores y productos ya creados, subir un archivo de cada uno con columna Id + una
columna de dato a corregir, mapear, confirmar, y verificar que cada registro coincidente se actualizó — sin
depender de Clientes.

### Tests for User Story 2 ⚠️

- [X] T016 [P] [US2] Feature test: Proveedores — columna Id mapeada + Saldo Inicial → proveedor existente
  actualiza `saldo_inicial` y conserva el resto de sus datos, en `tests/Feature/ImportacionDatosTest.php`
  (FR-002, FR-003)
- [X] T017 [P] [US2] Feature test: Productos — columna Id mapeada + "Mostrar en Ventas" → producto existente
  actualiza ese campo y conserva precio/costo/stock, en `tests/Feature/ImportacionDatosTest.php` (FR-002,
  FR-003, Principio IV — visibilidad en Ventas/Compras)
- [X] T018 [P] [US2] Feature test: Productos — fila de actualización que remapea el mismo `codigo`/SKU que el
  producto ya tiene → no falla por "ya existe" (`SkuUnico($id, $id)`), en
  `tests/Feature/ImportacionDatosTest.php` (FR-011)
- [X] T019 [P] [US2] Feature test: Productos — fila de actualización que mapea Id + un campo simple pero NO
  mapea "Proveedor" (campo FK-por-nombre) → el `proveedor_id` existente del producto no se toca (FR-009), en
  `tests/Feature/ImportacionDatosTest.php`

### Implementation for User Story 2

- [X] T020 [US2] Verificar que "Id" aparece en los selects de Proveedores y Productos sin cambios de vista
  (depende de T001, T002) — si algo no matchea, ajustar `DefinicionCamposImportables::proveedores()`/
  `::productos()`

**Checkpoint**: las 3 solapas quedan con el mismo mecanismo de actualización por Id.

---

## Phase 5: Polish & Cross-Cutting Concerns

**Purpose**: consistencia de documentación y validación final end-to-end.

- [X] T021 [P] Actualizar `docs/documentacion_principal_crm.md §2.4` con el comportamiento de actualización por
  Id (campo "Id" siempre disponible, actualización parcial, obligatoriedad relajada, unicidad con `ignore`)
- [X] T022 Ejecutar la validación de `specs/027-importador-upsert-por-id/quickstart.md` de punta a punta (los 2
  escenarios de US1-US2)
- [X] T023 Correr `php artisan test --filter=ImportacionDatos` y
  `php artisan test --filter=ImportadorFilasResolucionId`, dejar ambas suites en verde

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Fase 1)**: vacía, sin dependencias.
- **Foundational (Fase 2)**: BLOQUEA las 2 historias (T001-T007 son prerequisito de cualquier fila de
  actualización).
- **User Stories (Fases 3-4)**: dependen de Foundational.
  - US1 (P1) primero — Clientes, el caso principal.
  - US2 (P2) depende de que T001/T002 ya agregaron "Id" a Proveedores/Productos, y de que T003-T007 ya resuelven
    el flujo de actualización genérico (reutilizado, sin lógica nueva por entidad más allá de T004/T005).
- **Polish (Fase 5)**: al final, depende de que las 2 historias estén completas.

### Paralelizables

- Foundational: T002 en paralelo con T001 (archivos/métodos independientes); T004/T005 en paralelo entre sí y
  con T003 (archivos distintos).
- Tests de US1 (T009-T014) en paralelo entre sí.
- Tests de US2 (T016-T019) en paralelo entre sí.
- T021 en paralelo con T022-T023.

---

## Parallel Example: User Story 1

```bash
# Tests de User Story 1 en paralelo:
Task: "Feature test actualización parcial + saldo inicial en tests/Feature/ImportacionDatosTest.php"
Task: "Feature test Id sin match → fila fallida en tests/Feature/ImportacionDatosTest.php"
Task: "Feature test Id no numérico/no entero → fila fallida en tests/Feature/ImportacionDatosTest.php"
Task: "Feature test actualización sin Nombre mapeado → no exige obligatorio en tests/Feature/ImportacionDatosTest.php"
Task: "Feature test unicidad CUIT no bloquea el propio registro en tests/Feature/ImportacionDatosTest.php"
Task: "Feature test Id vacío → alta nueva en tests/Feature/ImportacionDatosTest.php"
```

---

## Implementation Strategy

### MVP (recomendado)

1. Fase 1 (vacía) → Fase 2 (Foundational: campo Id + resolución alta/actualización) → Fase 3 (US1: Clientes
   completo).
2. **STOP y validar**: actualizar un lote de clientes reales por Id, confirmar que el resumen no reporta fallos
   inesperados y que los datos no mapeados quedaron intactos. Demo.

### Incremental

- + US2 (Proveedores + Productos, reutiliza el flujo genérico de Foundational) — cada historia agrega valor sin
  tocar el mecanismo central de spec 006/026.

---

## Notes

- [P] = archivos distintos o funciones independientes dentro del mismo archivo, sin dependencias pendientes.
- Verificar que T009-T014, T016-T018 fallen antes de implementar la lógica que prueban (TDD en lo crítico: la
  actualización parcial puede pisar dinero — Principio IV).
- Commit por task o grupo lógico; parar en cada checkpoint para validar la historia.
- No se agrega ninguna migración: `id` ya existe como primary key en `clientes`/`proveedores`/`productos`
  (data-model.md).
- No se toca `ImportacionController.php` ni las vistas Blade del asistente, ni las reglas compartidas
  `ReglasCliente`/`ReglasProveedor`/`ReglasProducto` usadas por el alta/edición manual — la relajación de
  "obligatorio" vive sólo en los adaptadores `Reglas*Importacion` (plan.md §Constraints).
