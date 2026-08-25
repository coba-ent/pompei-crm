# Tasks: Importación por Excel escalable a archivos grandes

**Input**: Design documents from `/specs/082-importacion-archivos-grandes/`

**Prerequisites**: [plan.md](./plan.md), [spec.md](./spec.md), [research.md](./research.md),
[data-model.md](./data-model.md), [contracts/](./contracts/)

**Tests**: **Obligatorios.** La importación toca precios, costos y stock — principio IV de la
constitución. Los tests no son opcionales en esta feature.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: puede ir en paralelo (archivo distinto, sin dependencias)
- **[Story]**: a qué user story pertenece (US1, US2, US3)
- Cada tarea cita el/los requisito(s) que cubre, para trazabilidad.

## Mapeo con las fases del plan

| Fase del plan | Fases de este documento |
|---|---|
| A (fuente de filas) | Phase 1 |
| B (enganchar importador) + D (controlador) | Phase 2 |
| C (idempotencia) + E (frontend) | Phase 3 |
| — (alcance multi-entidad) | Phase 4 |
| F (verificación end-to-end) | Phase 5 |

---

## Phase 1: Foundational (bloquea todo lo demás)

**Purpose**: la fuente de filas es la base de la feature entera. Nada de US1/US2/US3 se puede hacer
sin esto.

- [X] **T001** Crear `app/Services/Import/FuenteFilasImportacion.php` con la superficie del contrato
      ([contracts/fuente-filas-importacion.md](./contracts/fuente-filas-importacion.md)):
      `volcar()`, `total()`, `encabezados()`, `leerRango()`.
      Volcado con `Excel::toArray()` **una sola vez**; lectura con `SplFileObject` salteando líneas
      sin decodificar el JSON de las que no entran en el rango. → **FR-001, FR-002**
- [X] **T002** [P] Crear `tests/Unit/FuenteFilasImportacionTest.php` con los casos borde del
      contrato: archivo sólo con encabezados, archivo de 1 fila, `offset` más allá del final,
      `limite` que excede el final, `limite = null`, fila con menos celdas que el encabezado, celdas
      con valores no serializables, orden preservado (I3) e índices numéricos preservados (I4).
      → **FR-002**
- [X] **T003** [P] Test de memoria plana: leer un rango de 250 filas de un archivo de 5.000 no puede
      consumir significativamente más que el mismo rango de un archivo de 500
      (`memory_get_peak_usage`). → **FR-003**

**Checkpoint**: la fuente de filas anda sola y sus bordes están cubiertos.

---

## Phase 2: User Story 1 — Importar el catálogo completo sin que se corte (P1) 🎯 MVP

**Goal**: que una planilla de 9.000+ filas termine sola.

**Independent test**: importar 9.000+ filas por el asistente y verificar que el resumen reporta el
total del archivo, sin filas sin procesar.

### Backend

- [X] **T004** [US1] Modificar `ImportadorFilas::importar()`
      (`app/Services/Import/ImportadorFilas.php:59`): dejar de hacer `Excel::toArray()` por tanda y
      tomar las filas de la fuente ya volcada.
      ⚠️ Preservar la semántica de `$limite = null` (procesa todo e **ignora** el `offset`) — ver
      Decisión 7 de research: es sutil y ya causó un error durante la resolución del incidente.
      → **FR-001, FR-002**
- [X] **T005** [US1] Test que fija el comportamiento heredado de `$limite = null` + `offset`, para
      que el refactor no lo cambie sin querer. → **FR-018**
- [X] **T006** [US1] `ImportacionController::subir()`: volcar a NDJSON después de guardar el
      temporal; guardar en sesión el nombre del `.ndjson` y el `total`
      ([data-model.md](./data-model.md) → Estado de sesión). `columnas` y `preview` pasan a leerse
      del NDJSON, sin cambiar su forma. → **FR-001**
- [X] **T007** [US1] `ImportacionController`: bajar `FILAS_POR_LOTE` de 1000 a **250**, como
      constante nombrada y ajustable. → **FR-005**
- [X] **T008** [US1] `ImportacionController::confirmarLote()`: agregar `ini_set('memory_limit', ...)`
      explícito, igual que ya hace `subir()` — no depender del default del servidor
      (Decisión 4 de research). → **FR-003**
- [X] **T009** [US1] Borrar el `.ndjson` junto con el `.xlsx` al terminar la importación y al
      cancelar. → **FR-004** (I5 del contrato)

### Tests

- [X] **T010** [US1] Crear `tests/Feature/ImportacionPorTandasTest.php`: archivo multi-tanda que se
      procesa completo por tandas sucesivas, con los totales correctos y sin filas salteadas.
      → **FR-002, SC-001**
- [X] **T011** [P] [US1] Test: un archivo que entra en una sola tanda se comporta idénticamente a
      antes. → **FR-018**
- [X] **T012** [P] [US1] Test: reimportar una planilla sin cambios genera **cero** eventos de
      auditoría de precio y **cero** movimientos de stock. → **FR-014, SC-005**
- [X] **T013** [P] [US1] Test: cada tanda devuelve el progreso (`procesadas` sobre `total`) con el
      total correcto leído de la fuente, de punta a punta de un archivo multi-tanda. → **FR-006**
- [X] **T014** [P] [US1] Test: el automapeo por encabezado y alias sigue funcionando tras el cambio
      de fuente de filas, incluidos `Stock {depósito}` ("Stock Local", "Stock Full") y `Punto de
      Reposición`. Los encabezados ahora vienen del NDJSON, así que este camino cambió de origen.
      → **FR-017**
- [X] **T015** [P] [US1] Test: las reglas de mapeo del Paso 2 siguen vigentes — campo obligatorio
      mapeado, sin dos columnas al mismo campo, CUIT admite dos columnas. → **FR-016**
- [X] **T016** [US1] Correr la suite existente completa y confirmar que pasa **sin tocar ninguna
      expectativa**: `ImportadorFilasParseoTest`, `ImportadorFilasResolucionIdTest`,
      `ImportacionProductosStockTest`, `DeshacerImportacionProductosTest`,
      `AuditoriaPrecioProductoTest`. → **FR-011, FR-012, FR-013, FR-015, SC-007**

**Checkpoint**: US1 entregable por sí sola. Ya resuelve el incidente que motivó la spec.

---

## Phase 3: User Story 2 — No perder lo ya procesado si algo se corta (P2)

**Goal**: reintento automático y retoma manual.

**Independent test**: forzar el fallo de una tanda intermedia y verificar que reintenta; forzarlo de
forma persistente y verificar que se puede retomar sin repetir ni saltear filas.

### Idempotencia (backend — hacer ANTES que el frontend)

- [X] **T017** [US2] `ImportadorFilas`: al arrancar una tanda con `corridaId` existente, consultar
      los `numero_fila` ya presentes en `importacion_filas_snapshot` para esa corrida dentro del
      rango, y **saltear** esas filas (Decisión 5 de research). Sólo aplica a
      `entidad = 'productos'`. → **FR-009**
- [X] **T018** [US2] Test: reprocesar el mismo `offset` dos veces **no** duplica snapshots
      (`COUNT(*)` == `COUNT(DISTINCT numero_fila)`) **ni** recuenta las filas en los contadores de la
      corrida. → **FR-009, FR-010, SC-004**
- [X] **T019** [US2] Test: el deshacer de una corrida que tuvo un reintento restaura **todas** las
      filas correctamente (lo que un snapshot duplicado habría roto). → **FR-010, FR-013**

### Frontend

- [X] **T020** [US2] `resources/views/importacion/mapear.blade.php`: reintento automático de la tanda
      fallida hasta 3 veces, esperando 2 s, 4 s y 8 s.
      ⚠️ Reintentar **sólo** ante fallo de red o respuesta 5xx. **No** reintentar un 422 (error de
      mapeo): es determinístico y sólo repetiría el mismo error. → **FR-007**
- [X] **T021** [US2] Tras agotar los reintentos: mostrar el error con el toast del template (regla de
      diseño #3) y un botón **"Reanudar desde la fila N"** que retoma desde el último `offset`
      confirmado. → **FR-008**
- [X] **T022** [US2] Al retomar, validar que los encabezados del archivo siguen coincidiendo con los
      del mapeo; si no, informar y pedir rehacer el mapeo en vez de escribir en columnas equivocadas.
      → edge case + Clarification
- [X] **T023** [US2] Manejar "el archivo temporal ya no está": mensaje claro de "volvé a subir el
      archivo", sin dejar la pantalla colgada. → edge case

**Checkpoint**: US1 + US2 completas. El corte deja de requerir intervención técnica.

---

## Phase 4: User Story 3 — La misma robustez en Clientes y Proveedores (P3)

**Goal**: verificar que las otras dos entidades quedan cubiertas por el mismo motor.

- [X] **T024** [P] [US3] Test de tandas para **Clientes**, verificando sus reglas propias: CUIT/DNI
      en dos columnas mapeadas al mismo campo, saldo inicial con fecha, lista de precios por nombre.
      → **FR-019, SC-006**
- [X] **T025** [P] [US3] Test de tandas para **Proveedores**, verificando sus reglas propias.
      → **FR-019, SC-006**
- [X] **T026** [US3] Verificar que las entidades sin corrida/snapshot (Clientes y Proveedores) no se
      rompen con el salteo de filas de T017, que es específico de Productos. → **FR-019**

**Checkpoint**: las tres entidades cubiertas.

---

## Phase 5: Verificación end-to-end y cierre

- [ ] **T027** Prueba manual con archivo real de 9.000+ filas siguiendo [quickstart.md](./quickstart.md)
      pasos 2 a 6. **En LOCAL, nunca en producción.** Medir el tiempo total.
      → **SC-001, SC-002, SC-006**
- [ ] **T028** Prueba con un archivo de **10.000 filas** para confirmar el margen de crecimiento
      declarado, sin fallar por tamaño ni memoria. → **SC-003**
- [X] **T029** Recorrer [checklists/robustez.md](./checklists/robustez.md) entero y marcarlo.
- [X] **T030** Confirmar que §2.4 de `docs/documentacion_principal_crm.md` sigue alineado con el
      código final (se actualizó en la fase de plan). Documentar el paso opcional de
      `fastcgi_read_timeout`. **No ejecutarlo** sin autorización explícita del usuario.

---

## Dependencies

```text
Phase 1 (T001-T003)  ──> bloquea todo
                          │
                          ├──> Phase 2 / US1 (T004-T016)   ── MVP entregable
                          │       │
                          │       └──> Phase 3 / US2 (T017-T023)
                          │               (T017-T019 antes que T020-T023)
                          │
                          └──> Phase 4 / US3 (T024-T026)   ── independiente de US2
                                  │
                                  └──> Phase 5 (T027-T030)
```

**Orden crítico**: **T017 (idempotencia) va antes que T020 (reintento)**. Si se implementa el
reintento sin el salteo de filas ya aplicadas, un reintento del caso real del incidente (PHP terminó
la tanda, nginx cortó la respuesta) duplicaría los snapshots de deshacer y dejaría el undo
inconsistente. Es el error más fácil de cometer en esta feature.

## Parallel opportunities

- **T002 + T003**: tests de la fuente, archivos distintos.
- **T011 + T012 + T013 + T014 + T015**: tests de no-regresión independientes entre sí.
- **T024 + T025**: Clientes y Proveedores, archivos de test distintos.

## Implementation strategy

**MVP = Phase 1 + Phase 2 (US1)**. Con eso el incidente que motivó la spec está resuelto: la
importación del catálogo completo termina sola.

US2 (resiliencia) y US3 (otras entidades) son incrementos independientes que se pueden entregar
después sin rehacer nada.
