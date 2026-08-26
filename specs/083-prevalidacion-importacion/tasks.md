# Tasks: Prevalidación y confirmación previa de la importación

**Input**: documentos de diseño de `/specs/083-prevalidacion-importacion/`

**Prerequisites**: [plan.md](./plan.md), [spec.md](./spec.md), [research.md](./research.md),
[data-model.md](./data-model.md), [contracts/](./contracts/)

**Tests**: **Obligatorios.** Esta feature decide qué se escribe sobre precios, costos y stock —
principio IV de la constitución.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: puede ir en paralelo (archivo distinto, sin dependencias)
- **[Story]**: a qué user story pertenece (US1 a US4)

## Mapeo con las fases del plan

| Fase del plan | Fases de este documento |
|---|---|
| A (extraer la validación) | Phase 1 |
| B (fórmulas calculadas) | Phase 2 |
| C (mensajes en español) | Phase 3 |
| D (prevalidación + modal) | Phase 4 |
| E (round-trip) | Phase 5 |
| F (resumen confiable) | Phase 6 |
| G (verificación) | Phase 7 |

---

## Phase 1: Foundational — extraer la validación (bloquea US1 y US2)

**Purpose**: que la prevalidación y la importación compartan las reglas *por construcción*. Todo lo
demás se apoya en esto.

- [X] **T001** Crear `app/Services/Import/ValidadorFilasImportacion.php` con la superficie del
      contrato ([contracts/validador-filas.md](./contracts/validador-filas.md)): `evaluar()` devuelve
      `modo`, `motivos`, `advertencias`, `registro_id` y `campos`.
      ⚠️ **No debe recibir `StockService` ni ninguna dependencia capaz de escribir** — es lo que hace
      que FR-002 se cumpla por construcción y no por disciplina. → **FR-002, FR-003**
- [X] **T002** Mover a `ValidadorFilasImportacion` la lógica hoy embebida en `ImportadorFilas`:
      `mapearFila()`, `resolverModoFila()`, `validarFila()`, `ajustarReglaCuit()`,
      `resolverTipoPorIndiceCuit()`. `ImportadorFilas` pasa a llamarlo. → **FR-003**
- [X] **T003** `evaluar()` devuelve **todos** los motivos de la fila, no sólo el primero: hoy
      `validarFila()` corta en `errors()->first()`. → **FR-020** (I4 del contrato)
- [X] **T004** `evaluar()` devuelve `campos`: los campos que esa fila escribiría, por su etiqueta
      visible. → **FR-005b** (I7 del contrato)
- [X] **T005** [P] Crear `tests/Unit/ValidadorFilasImportacionTest.php`: alta sin Id, actualización con
      Id existente, alta con Id inexistente (spec 027), Id no numérico, celda no numérica en campo
      numérico, advertencia por proveedor inexistente, fila con menos celdas que el encabezado.
      → **FR-003**
- [X] **T006** **El test que sostiene todo**: correr el validador y el importador sobre el mismo
      archivo y verificar que el modo previsto coincide **fila por fila** con el aplicado.
      → **FR-003, SC-003** (I2 del contrato)
- [X] **T007** Correr la suite existente de importación y confirmar que pasa sin tocar expectativas:
      `ImportadorFilasParseoTest`, `ImportadorFilasResolucionIdTest`, `ImportacionProductosStockTest`,
      `DeshacerImportacionProductosTest`, `AuditoriaPrecioProductoTest`, `ImportacionPorTandasTest`,
      `ImportacionDatosTest`. → **FR-025, FR-026, FR-027, SC-009**

**Checkpoint**: las reglas viven en un solo lugar y el importador sigue funcionando igual.

---

## Phase 2: Fórmulas de Excel calculadas (US1) 🎯

**Goal**: que una planilla guardada sin recalcular no meta basura.

**Independent test**: importar `Ferrum nuevos (2).xlsx` y verificar que el código queda
`DEPOSITO ANDINA ... 44927` y no el texto de la fórmula.

- [X] **T008** [US1] `FuenteFilasImportacion::volcar()`: leer con PhpSpreadsheet pidiendo el **valor
      calculado** (`toArray(null, true, false, false)`) en vez de `Excel::toArray()`.
      Medido: 51 ms / 148 filas, ~3,3 s proyectado para 9.632. → **FR-011**
- [X] **T009** [US1] Capturar el fallo de cálculo **por celda**: una fórmula no evaluable marca esa
      celda, **sin abortar el volcado del archivo entero**. → **FR-012**
- [X] **T010** [US1] Una celda marcada como no evaluable produce **error de fila** en el validador,
      nombrando la columna. Nunca se guarda el texto de la fórmula. → **FR-012, FR-013**
- [X] **T011** [P] [US1] Crear `tests/Feature/FormulasExcelImportacionTest.php`: `.xlsx` con fórmulas
      sin cachear en una columna de texto y en una numérica; verificar que entran los valores.
      → **FR-011, SC-004**
- [X] **T012** [P] [US1] Test: una fórmula rota (referencia circular o función inexistente) reporta
      error de esa fila y **no** guarda texto que empiece con el signo igual. → **FR-012, FR-013**
- [X] **T013** [P] [US1] Test de no-regresión: un archivo **sin** fórmulas se sigue interpretando
      igual que antes, y el volcado no se vuelve significativamente más lento. → **FR-025**

**Checkpoint**: entregable solo. Ya evita el peor de los cuatro defectos.

---

## Phase 3: Mensajes en español (US2)

**Goal**: que el error diga qué corregir.

- [X] **T014** [US2] Mensajes de validación propios del importador, en español, **sin cambiar
      `APP_LOCALE`** (research Decisión 4: cambiarlo afectaría toda la app y es otra feature).
      → **FR-018**
- [X] **T015** [US2] Nombres de atributo tomados del **mapeo real** de esa importación: la etiqueta
      del campo destino o el encabezado del archivo. → **FR-019**
- [X] **T016** [P] [US2] Test: una celda no numérica en la columna mapeada como "AHORA 3" produce un
      motivo que **contiene "AHORA 3"** y **no** contiene `precio_lista_`. → **FR-019**
- [X] **T017** [P] [US2] Test de barrido: forzar un error de cada tipo y verificar que **ningún**
      motivo contiene palabras en inglés (`The `, ` field `, ` must be `) ni guiones bajos.
      → **FR-018, SC-006**
- [X] **T018** [P] [US2] Test: una fila con tres problemas informa los tres. → **FR-020**

**Checkpoint**: US1 + US2. El bloqueo de la fase siguiente ya es accionable.

---

## Phase 4: Prevalidación por tandas y modal de confirmación (US1) 🎯 CORE

**Goal**: el pedido central.

- [X] **T019** [US1] Crear `app/Services/Import/InformePrevalidacion.php`: acumula `altas`,
      `actualizaciones`, `campos_afectados`, `errores`, `advertencias`, `procesadas`/`total` y la
      `huella`. → **FR-001, FR-005b**
- [X] **T020** [US1] Decidir **con una medición**, no a ojo, dónde vive el informe: sesión o disco
      junto al NDJSON. El caso que manda es 10.000 filas **todas** con error — eso no entra cómodo en
      sesión ([data-model.md](./data-model.md)). → **FR-007, FR-010**
- [X] **T021** [US1] Endpoint de prevalidación por tandas, reutilizando el mecanismo de la spec 082
      (no inventar uno nuevo). → **FR-007, FR-008**
- [X] **T022** [US1] Crear `resources/views/importacion/_modal-confirmacion.blade.php`: conteos,
      campos a modificar con su cantidad, detalle de errores desplazable, barra de progreso.
      Regla de diseño #2 del proyecto: modal de Bootstrap + AJAX, sin recargar la página.
      → **FR-001, FR-004, FR-005b, FR-010**
- [X] **T023** [US1] `mapear.blade.php`: "Confirmar importación" abre el modal y dispara la
      prevalidación, en vez de importar directamente. → **FR-001**
- [X] **T024** [US1] Bloquear el botón de confirmar del modal si hay al menos un error, y **habilitarlo
      con cero errores** para que la importación proceda como antes. → **FR-005, FR-006**
- [X] **T025** [US1] Cancelar el modal vuelve al mapeo con la selección intacta y sin efectos.
      → **FR-005c**
- [X] **T026** [US1] Verificar la huella al confirmar: si el archivo o el mapeo cambiaron respecto de
      lo prevalidado, se rechaza. → **FR-009**
- [X] **T027** [P] [US1] Crear `tests/Feature/PrevalidacionImportacionTest.php`: conteos correctos de
      altas y actualizaciones en un archivo mixto, visibles **antes** de escribir nada.
      → **FR-001, SC-001**
- [X] **T028** [P] [US1] **Test central**: después de prevalidar, `COUNT(*)` de productos, clientes,
      proveedores, precios, stocks y movimientos **no cambió**. → **FR-002**
- [X] **T029** [P] [US1] Test: con filas inválidas, el endpoint de confirmación **rechaza** la
      importación aunque se lo llame directo, no sólo por UI, y **no escribe ni una fila**.
      → **FR-005, SC-002**
- [X] **T030** [P] [US1] Test: `campos_afectados` lista exactamente los campos mapeados con valor y
      sus cantidades por campo. → **FR-005b**
- [X] **T031** [P] [US1] Test: confirmar con una huella que no corresponde se rechaza. → **FR-009**
- [X] **T032** [P] [US1] Test: archivo sólo con encabezados da 0/0/0 sin error; archivo sin ninguna
      fila válida lista todos los errores y deja la confirmación bloqueada; archivo **totalmente
      válido** deja confirmar y termina bien. → edge cases, **FR-006**

**Checkpoint**: el pedido central, entregable.

---

## Phase 5: Round-trip exportación ↔ importación (US3)

- [X] **T033** [US3] Agregar a `DefinicionCamposImportables` el alias `Precio venta` para
      `precio_venta`, y **todos** los demás que falten. → **FR-014**
- [X] **T034** [US3] **El test que evita la próxima vez**: comparar **todos** los encabezados de
      `ProductosExport::headings()` contra las etiquetas y alias de `DefinicionCamposImportables`, y
      fallar **listando las columnas huérfanas**. → **FR-016**
- [X] **T035** [P] [US3] Test de round-trip: exportar productos, reimportar sin modificar, verificar
      **cero** diferencias en precio de venta, costo, stock y punto de reposición. → **FR-017, SC-005**
- [X] **T036** [US3] Confirmar que **no existe** export de Clientes ni Proveedores (verificado el
      26/08/2026: sólo hay `ProductosExport`) y dejarlo registrado en la spec. → **FR-015**

---

## Phase 6: Resumen confiable (US4)

- [X] **T037** [US4] Atar el acumulado a la importación en curso: al empezar una importación nueva se
      **descarta** cualquier acumulado anterior. Es la causa raíz reproducida. → **FR-021, FR-022**
- [X] **T038** [US4] `importacion_corrida_ref` en sesión, generado en el Paso 1, validado al mostrar
      el resumen — necesario para Clientes y Proveedores, que no tienen `ImportacionCorrida`.
      → **FR-021**
- [X] **T039** [US4] El resumen de Productos se arma desde la `ImportacionCorrida` (archivo, fecha,
      contadores), no desde un número suelto en sesión. → **FR-023, FR-024**
- [X] **T040** [US4] `resumen.blade.php`: mostrar nombre de archivo y fecha/hora de la corrida.
      → **FR-023**
- [X] **T041** [P] [US4] Crear `tests/Feature/ResumenImportacionTest.php` con **el caso reproducido**:
      sembrar 1000 en el acumulado, importar 2 y verificar que el resumen informa **2**.
      → **FR-021, SC-007**
- [X] **T042** [P] [US4] Test: abandonar una importación a mitad y arrancar otra no arrastra nada.
      → **FR-022**
- [X] **T043** [P] [US4] Test: los números del resumen coinciden con lo que quedó en la base.
      → **FR-024**

---

## Phase 7: Verificación end-to-end y cierre

- [ ] **T044** Prueba manual siguiendo [quickstart.md](./quickstart.md) con `Ferrum nuevos (2).xlsx`:
      ver los conteos **antes** de escribir y comprobar que una planilla con errores no escribe nada.
      **En LOCAL, nunca en producción.** → **SC-001, SC-002**
- [ ] **T045** Prueba manual con el catálogo completo (9.632 filas): prevalidación con progreso,
      tiempo total, y el round-trip completo. → **SC-008**
- [ ] **T046** Prueba manual de actualización masiva para ver el listado de campos afectados.
      → **FR-005b, SC-002b**
- [X] **T047** Verificar que las tres solapas se comportan igual. → **FR-028**
- [ ] **T048** Recorrer [checklists/calidad.md](./checklists/calidad.md) entero y marcarlo.
- [X] **T049** Confirmar que §2.4 de `docs/documentacion_principal_crm.md` sigue alineado con el
      código final (se actualizó en la fase de plan).
- [ ] **T050** Restaurar la base local de las pruebas siguiendo el paso 9 del quickstart (el
      "Deshacer" **no** borra: deja inactivos, no libera ids y no borra precios).

---

## Dependencies

```text
Phase 1 (T001-T007)  ──> bloquea US1 y US2
   │
   ├──> Phase 2 / US1 (T008-T013)  ── entregable solo: evita el peor defecto
   │       │
   │       └──> Phase 3 / US2 (T014-T018)
   │               │
   │               └──> Phase 4 / US1 (T019-T032)  ── EL PEDIDO CENTRAL
   │
   ├──> Phase 5 / US3 (T033-T036)  ── independiente
   └──> Phase 6 / US4 (T037-T043)  ── independiente
                                        │
                                        └──> Phase 7 (T044-T050)
```

**Orden crítico**: **T001-T002 antes que todo lo demás**. Si la prevalidación se escribe con su propia
copia de las reglas en vez de compartirlas, FR-003 se vuelve imposible de garantizar y el modal
termina mintiendo — que es el peor resultado posible de esta feature.

**Orden dentro de las fases 3 y 4**: los mensajes en español (T014-T015) van **antes** del modal
(T022), porque el modal muestra esos mensajes. Al revés habría que rehacer la pantalla.

## Parallel opportunities

- **T005 + T011 + T012 + T013**: tests de archivos distintos.
- **T016 + T017 + T018**: tests de mensajes, independientes.
- **T027 a T032**: tests de prevalidación, independientes entre sí.
- **Phase 5 y Phase 6** son independientes entre sí y de la Phase 4: se pueden hacer en paralelo.

## Implementation strategy

**MVP = Phase 1 + Phase 2**. Con eso ya no entra basura por fórmulas sin calcular, que es el defecto
que más daño hizo (124 productos con el código roto).

**El pedido central es la Phase 4**, y necesita las fases 1 a 3 debajo.

**Phase 5 y Phase 6** son arreglos acotados, independientes, que se pueden entregar cuando convenga.

---

## Estado de la implementación (26/08/2026)

**T001–T043, T047 y T049: hechas.** Suite verde:
`ValidadorFilasImportacionTest` (11), `PrevalidacionImportacionTest` (14),
`FormulasExcelImportacionTest` (4), `RoundTripExportImportTest` (3),
`ResumenImportacionTest` (3), más la suite de no-regresión de las specs 026/027/074/078/082.

**Pendientes, todas de verificación manual en el navegador (fase 7): T044, T045, T046, T048, T050.**
No se pueden cerrar desde la implementación: necesitan la app corriendo en local con
`Ferrum nuevos (2).xlsx` y con el catálogo completo de 9.632 filas. **En local, nunca en producción.**

Nota sobre el alcance del bloqueo de FR-005: vive en `confirmarLote()` —el camino del navegador— y en
el backend, no sólo en la pantalla. El endpoint heredado `importacion.confirmar` (documentado desde la
spec 026 como "usado por tests/llamadas directas", no alcanzable desde la UI) conserva la tolerancia
por fila de las specs 006/026; es lo que permite que la suite de no-regresión siga fijando ese
comportamiento sin contradecir FR-005.
