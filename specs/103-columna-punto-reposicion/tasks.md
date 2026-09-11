# Tasks: Columna Punto de Reposición en el listado de Productos

**Input**: Design documents from `/specs/103-columna-punto-reposicion/`
**Prerequisites**: plan.md, spec.md, data-model.md, quickstart.md

`[P]` = paralelizable (archivos distintos, sin dependencia entre sí).

---

## Fase 1 — Setup

- [X] **T001** Confirmar en local que `productos.punto_reposicion` viaja en el JSON de
      `ProductoController::data()` sin ningún cambio (columna directa del modelo, ya en el `SELECT`
      implícito) — sólo para descartar que algún `select()` explícito la esté recortando antes de
      tocar nada. Sin cambios de código si se confirma.

## Fase 2 — Foundational (bloqueante para todas las historias)

*No hay tareas foundational — el dato ya existe (spec 073) y el patrón a seguir (`editColumn` +
columna JS) ya está resuelto por `stock_total`/`stock_deposito_{id}` en el mismo controller. Cada
historia de usuario es autocontenida sobre ese patrón ya vigente.*

---

## Fase 3 — User Story 1: Ver el Punto de Reposición en el listado (Priority: P1) 🎯 MVP

**Goal**: el valor de `punto_reposicion` de cada producto se ve en la tabla de Productos, con "sin
control" para `0` y para Servicios, sin abrir ningún modal.

**Independent Test**: abrir Base de Datos → Productos y confirmar que la columna nueva muestra el
valor correcto en cada fila (ver quickstart.md Escenario 1).

### Tests para User Story 1

- [X] **T002** [P] [US1] Test en `tests/Feature/ProductoListadoTest.php`: `data()` devuelve
      `punto_reposicion` como entero para un producto con valor > 0 (ej. `5`).
- [X] **T003** [P] [US1] Test en `tests/Feature/ProductoListadoTest.php`: `data()` devuelve
      `punto_reposicion = null` para un producto de Tipo = Servicio (mismo criterio que
      `stock_total`/`stock_deposito_*`, no `0`).
- [X] **T004** [P] [US1] Test en `tests/Feature/ProductoListadoTest.php`: `data()` devuelve
      `punto_reposicion = 0` (el valor crudo, no `null`) para un producto Tipo = Producto sin control
      configurado — la distinción "0 numérico" vs "null" es lo que el frontend usa para diferenciar
      Servicio (siempre sin control) de Producto sin configurar (podría configurarse).

### Implementación para User Story 1

- [X] **T005** [US1] En `app/Http/Controllers/ProductoController.php::data()`, agregar
      `editColumn('punto_reposicion', fn (Producto $p) => $p->esServicio() ? null :
      (int) $p->punto_reposicion)`, siguiendo el mismo patrón que `editColumn('stock_total', ...)`
      (línea ~260).
- [X] **T006** [US1] En `resources/views/productos/index.blade.php`, agregar
      `<th>Punto de Reposición</th>` en el `<thead>`, inmediatamente después del `@foreach
      ($depositosColumnas as $deposito)` de Stock y antes de `<th>Costo</th>` (línea ~216).
- [X] **T007** [US1] En `resources/js/productos.js`, agregar la definición de columna DataTables
      para `punto_reposicion` en el array `columns`, en la misma posición relativa (después del
      `...(cfg.depositosColumnas || []).map(...)` de stock, antes de `costo`). Render: si el valor es
      `null` o `0`, mostrar el indicador de "sin control" (ej. `<span class="text-muted">Sin
      control</span>`, igual criterio que el placeholder del modal); si no, el entero formateado sin
      decimales (`Intl.NumberFormat('es-AR')`, mismo formateador ya usado para `stock_total`).
      `orderable: true` (a diferencia de `stock_total`, que es `orderable: false` porque es una suma
      calculada — acá `punto_reposicion` es una columna directa, sí se puede ordenar server-side, ver
      T008).

**Checkpoint**: en este punto, User Story 1 es completamente funcional — la columna se ve, con el
criterio correcto de "sin control" para `0` y Servicios.

---

## Fase 4 — User Story 2: Ordenar por Punto de Reposición (Priority: P2)

**Goal**: el usuario puede ordenar el listado ascendente/descendente por esta columna, resuelto
server-side.

**Independent Test**: hacer clic en el encabezado de la columna y confirmar que la tabla se reordena
sin recargar la página (ver quickstart.md Escenario 2).

### Tests para User Story 2

- [X] **T008** [P] [US2] Test en `tests/Feature/ProductoListadoTest.php`: una petición a `data()` con
      `order` apuntando a la columna `punto_reposicion` (ascendente) devuelve las filas ordenadas
      correctamente por ese valor.

### Implementación para User Story 2

- [X] **T009** [US2] Confirmar que la columna definida en T005/T007 tiene `name: 'punto_reposicion'`
      (requisito de Yajra para poder ordenar por una columna que no es la primera del `SELECT`) y que
      no hace falta un `orderColumn()` custom — es una columna directa de `productos`, a diferencia de
      las columnas dinámicas de lista de precio/depósito que si necesitaron tratamiento especial. Si
      el test T008 falla por ambigüedad de nombre de columna en el `ORDER BY` (columna también
      presente en alguna relación `with()`), agregar `orderColumn('punto_reposicion',
      'productos.punto_reposicion $1')`.

**Checkpoint**: en este punto, User Stories 1 y 2 funcionan juntas — se ve y se puede ordenar.

---

## Fase 5 — User Story 3: Mostrar/ocultar la columna (Priority: P3)

**Goal**: la columna aparece en el selector de columnas (colvis) del listado, igual que las demás.

**Independent Test**: abrir el selector de columnas, desmarcar "Punto de Reposición", confirmar que
desaparece sin recargar; volver a marcarla (ver quickstart.md Escenario 3).

### Implementación para User Story 3

- [X] **T010** [US3] Verificar en `resources/js/productos.js` que la columna nueva (T007) **no** tiene
      la clase `no-colvis` (la misma que sí llevan explícitamente Acciones y el checkbox de
      selección, líneas ~145-150) — sin esa clase, Yajra/DataTables Buttons la incluye automáticamente
      en el selector de columnas (`extend: 'colvis'`, línea ~98-104). No requiere cambio de código si
      la columna nueva no se marcó con esa clase en T007; sólo confirmar.

**Checkpoint**: las tres historias de usuario funcionan juntas — MVP completo de esta spec.

---

## Fase 6 — Polish & Documentación (cross-cutting)

- [X] **T011** Actualizar `docs/documentacion_principal_crm.md §2.2` (línea ~163-167, "Columnas del
      listado"): agregar "Punto de Reposición" a la enumeración de columnas, con una nota explícita
      de que es una **divergencia deliberada** respecto al listado real de Contagram (que no la
      tiene), con la razón de negocio (control interno de stock, spec 073) — mismo patrón que la
      excepción ya documentada de spec 071 (FR-010).
- [X] **T012** [P] Correr la suite completa de `tests/Feature/ProductoListadoTest.php` y
      `tests/Feature/ProductoStockPorDepositoTest.php` (columna análoga, mismo criterio
      Servicio→null) para confirmar que no hay regresión en las columnas existentes. También correr
      `tests/Feature/RoundTripExportImportTest.php` (ya cubre `punto_reposicion` en el export/import,
      líneas 100/108/130/143) para confirmar que el listado nuevo no interfiere con ese camino ya
      probado.
- [ ] **T013** Verificación manual contra quickstart.md (los 3 escenarios + la verificación de no
      regresión del export CSV/Excel) en el entorno local antes de dar la spec por lista para deploy.

---

## Dependencies & Execution Order

```
Fase 1 (Setup) ──► Fase 3 (US1 - P1, MVP) ──► Fase 4 (US2 - P2) ──► Fase 5 (US3 - P3) ──► Fase 6 (Polish)
```

- **Fase 2 (Foundational)** está vacía — no bloquea nada.
- **US1 es el MVP**: entrega valor completo por sí sola (ver el dato). US2 y US3 son incrementales y
  no bloquean la entrega de US1.
- Dentro de US1: T002-T004 (tests) pueden correr en paralelo entre sí `[P]`, y todos antes de
  T005-T007 (implementación) si se sigue TDD; T005, T006, T007 tocan archivos distintos y son
  paralelizables entre sí una vez que el patrón está claro, pero T007 depende conceptualmente de T005
  (mismo nombre de campo en el JSON).
- Dentro de US2: T008 (test) antes de T009 (implementación/verificación).
- T011 (docs) no depende de código — puede hacerse en cualquier momento, pero se deja en Polish para
  no interrumpir el flujo de implementación de las historias.

## Parallel Execution Examples

**Dentro de Fase 3 (US1)**, tests en paralelo:
```
T002 [P] [US1] Test producto con punto_reposicion > 0
T003 [P] [US1] Test producto Servicio → null
T004 [P] [US1] Test producto sin control → 0 (no null)
```

**Entre fases**, una vez completado el MVP (US1), Fase 4 y Fase 5 tocan archivos parcialmente
distintos (T008/T009 son sólo backend/verificación de ordenamiento; T010 es sólo verificación de JS) y
podrían abordarse en paralelo si dos personas trabajan la spec, aunque en la práctica es una feature
chica pensada para una sola sesión de implementación.

## Implementation Strategy

**MVP primero**: implementar sólo Fase 1 + Fase 3 (User Story 1) ya entrega el valor pedido por el
usuario — ver el Punto de Reposición en la tabla. Fases 4 y 5 (ordenar, mostrar/ocultar) son mejoras
incrementales sobre ese MVP, usando mecanismos que Yajra/DataTables ya proveen sin código adicional
salvo la verificación puntual de cada tarea.

**Entrega incremental sugerida**:
1. Fase 1 + Fase 3 (US1) → demo: "ya se ve la columna" (MVP)
2. Fase 4 (US2) → demo: "ya se puede ordenar"
3. Fase 5 (US3) → demo: "ya se puede ocultar"
4. Fase 6 → cierre: docs actualizadas + regresión verificada, lista para deploy
