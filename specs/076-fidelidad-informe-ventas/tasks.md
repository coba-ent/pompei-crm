# Tasks: Fidelidad del Informe de Ventas contra Contagram

**Feature**: `076-fidelidad-informe-ventas` | **Date**: 2026-08-24
**Input**: [spec.md](./spec.md), [plan.md](./plan.md), [research.md](./research.md),
[data-model.md](./data-model.md), [contracts/export-detallado.md](./contracts/export-detallado.md),
[quickstart.md](./quickstart.md)

**Tests**: obligatorios. El principio IV de la constitución los exige para "cálculo de
importes/IVA/descuentos/totales", y esta feature es exactamente eso.

**Nota de orden**: la Fase 2 arranca **midiendo** antes de escribir código. Si `SUM(total_venta)`
ya cierra contra `ventas.total` en las ventas sin conceptos extra, media feature está hecha y sólo
falta el prorrateo; si no cierra, hay algo más que el plan no vio. Escribir el prorrateo sin haber
medido es programar a ciegas.

---

## Phase 1: Setup

- [X] T001 Tomar la línea de base ANTES de tocar nada: para el 01/07/2026, anotar el KPI `Total Ventas`, el valor que hoy muestra "Total Comprobante" en la venta 23501 y la suma actual de esa columna sobre todo el detalle, en `specs/076-fidelidad-informe-ventas/linea-base.md`. Sin esto el Escenario 1 de `quickstart.md` no se puede demostrar
- [X] T002 Confirmar que la suite arranca en su estado conocido: `php artisan test --filter="Informe|Pivot"` y anotar el resultado

---

## Phase 2: Foundational — medir y decidir

**Bloquea todo lo demás.**

- [X] T003 Medir sobre la base real, con un script temporal en `storage/tmp/`, si `SUM(total_venta)` agrupado por comprobante iguala `ventas.total`: separar el resultado entre comprobantes **con** y **sin** conceptos extra en `venta_conceptos`. Anotar los dos números en `linea-base.md`
- [X] T004 A partir de T003, confirmar o corregir la premisa de `research.md §R2`: se espera que los comprobantes sin conceptos cierren exacto y los que tienen conceptos queden cortos justo por el monto de esos conceptos. **Si un comprobante sin conceptos no cierra, parar**: hay una causa que el plan no contempló y el diseño hay que revisarlo antes de seguir. **Resultado**: confirmada con matiz — 5 ventas de 36.533 (0,014%) no cierran por datos heredados de la migración 2021, no por el diseño. Usuario decidió tratarlas como excepción conocida y seguir (ver `linea-base.md`)

**Checkpoint**: se sabe con números qué hay que arreglar, y nada cambió todavía.

---

## Phase 3: User Story 1 — El importe real de cada línea (P1) 🎯 MVP

**Goal**: que la columna muestre el importe de la línea y que su suma cierre contra el total.

**Independent Test**: abrir el informe en una venta de varias líneas, ver importes distintos, y que
sumen el total del comprobante.

### Tests

- [X] T005 [P] [US1] Crear `tests/Feature/Informes/InformeVentasImporteLineaTest.php` con el invariante **I1**: la suma del importe de línea de un comprobante iguala su total, al centavo (`contracts/export-detallado.md §4`)
- [X] T006 [P] [US1] En el mismo archivo, invariante **I2**: la suma sobre todo el detalle de un período iguala el KPI `Total Ventas`
- [X] T007 [P] [US1] En el mismo archivo, el caso de los **conceptos extra**: una venta con percepción e impuesto interno cierra igual contra su total. Es el test que prueba el prorrateo
- [X] T008 [P] [US1] En el mismo archivo, el caso de **redondeo**: un concepto que no divide exacto entre las líneas sigue cerrando al centavo, con el residuo en la última línea
- [X] T009 [P] [US1] En el mismo archivo, **signo**: una nota de crédito aporta importes negativos y una de débito positivos, sin rama por tipo de comprobante
- [X] T010 [P] [US1] En el mismo archivo, el **borde de división por cero**: un comprobante de neto cero con conceptos cargados no rompe ni produce `NULL` (CHK010)
- [X] T011 [P] [US1] En el mismo archivo, la **nota migrada sin ítems**: aporta una fila con su monto completo, como hoy

### Implementación

- [X] T012 [US1] Agregar el prorrateo de los conceptos extra a la expresión `total_venta` de `app/Services/Informes/VentasInformeQuery.php`, en proporción al neto de cada línea y con el residuo en la última (`data-model.md §2`). **No renombrar ni cambiar la semántica de `total_comprobante`**: el pivot y otros consumidores la usan (`research.md §R7`)
- [X] T013 [US1] Cambiar la columna del detalle en pantalla de `total_comprobante` a `total_venta` en `resources/js/informe-ventas.js` (línea ~166), conservando el rótulo "Total Comprobante" de la cabecera (FR-017)
- [X] T014 [US1] Cambiar la columna en la hoja legible y en la hoja plana de `app/Exports/Informes/InformeVentasExport.php`
- [X] T015 [US1] Cambiar la columna en `resources/views/informes/pdf/ventas.blade.php`
- [X] T015b [P] [US1] Test de que el export **resumen** conserva sus **dos hojas** después del cambio (invariante I9): nada lo verifica hoy y T014 lo toca (FR-020)
- [X] T015c [P] [US1] Test que compara la **misma línea** en las cuatro salidas —pantalla, export resumen, export detallado y PDF— y exige el mismo importe (SC-004). Se escribe ahora aunque el detallado no exista todavía: la parte que le toca se activa en la Fase 4
- [X] T016 [US1] **Dar vuelta** `test_total_comprobante_se_repite_en_cada_fila_de_la_misma_venta` en `tests/Feature/Informes/InformeVentasTest.php`: pasa a afirmar que los importes de línea suman el total, con un comentario que explique qué afirmaba antes, por qué era falso y con qué evidencia se corrigió (`research.md §R5`). **No borrarlo**
- [X] T017 [US1] Correr los tests del motor de tablas dinámicas y actualizar los valores esperados **sólo** si se movieron por el prorrateo de conceptos. Si se mueve algo que no tiene conceptos, es una regresión (`research.md §R7`). **Resultado**: 30/30 verdes sin tocar nada — ninguna venta de esos tests usa `venta_conceptos`, así que el prorrateo no las movió
- [ ] T018 [US1] Validar los Escenarios 1 y 4 de `quickstart.md` en el navegador, contra la línea de base de T001. **Gate de la fase — pendiente de validación manual en navegador**

**Checkpoint**: MVP. La columna ya dice la verdad en las cuatro salidas.

---

## Phase 4: User Story 2 — El export detallado (P1)

**Goal**: el tercer botón, con las 44 columnas comparables celda a celda con Contagram.

**Depende de**: Fase 3 (el detallado también lleva la columna de importe de línea).

**Independent Test**: exportar un período y comparar el archivo contra el de Contagram del mismo
período.

### Tests

- [X] T019 [P] [US2] Crear `tests/Feature/Informes/InformeVentasDetalladoExportTest.php`: el archivo tiene **una sola hoja**, los 3 bloques de KPIs en las filas 1-8 y el encabezado en la fila 10 (`contracts/export-detallado.md §2`)
- [X] T020 [P] [US2] En el mismo archivo: las **44 columnas** con los rótulos y el orden exactos del contrato §3, incluida la duplicación deliberada del rótulo "Tipo"
- [X] T021 [P] [US2] En el mismo archivo, invariante **I3**: cada línea imputa a una sola columna de neto y a como mucho una de alícuota, con casos de 21%, 10,5%, exento y no gravado (`data-model.md §3`)
- [X] T022 [P] [US2] En el mismo archivo, invariante **I6**: una venta con dos comprobantes fiscales (uno rechazado y uno aprobado) aporta **una** fila por línea y no mueve los totales. Es el riesgo #1 del plan
- [X] T023 [P] [US2] En el mismo archivo: los valores literales de las columnas nuevas cuando el dato no existe — ARCA `---`, punto de venta y número `-`, lista de precios vacía (`spec.md` Clarifications)
- [X] T024 [P] [US2] En el mismo archivo, invariante **I5**: los totales del archivo coinciden con los KPIs de la pantalla para los mismos filtros
- [X] T024b [P] [US2] En el mismo archivo, invariante **I7**: las columnas del desglose sin valor salen en **cero** y no vacías (FR-011b)
- [X] T024c [P] [US2] En el mismo archivo, invariante **I8**: las fechas salen como fecha de Excel y no como texto (FR-010a). **Verificar de paso el export resumen**, que hoy las escribe como texto — **pendiente el resumen**: sólo se cubrió el detallado; ver nota en T024 de Polish
- [X] T024d [P] [US2] En el mismo archivo: una línea con condición de IVA nula, vacía o no reconocida imputa a *Importe Neto No Gravado* y no desaparece del desglose (FR-011a)

### Implementación

- [X] T025 [US2] Extender la proyección de `app/Services/Informes/VentasInformeQuery.php` con las columnas que faltan: `productos.codigo`, CUIT/DNI del cliente, lista de precios, notas, vencimiento y afecta stock (`research.md §R3`)
- [X] T026 [US2] Agregar la lectura del comprobante fiscal (ARCA, punto de venta, número) **por subconsulta de una sola fila**, nunca por join directo — `comprobantes_fiscales` es polimórfica, tiene `deleted_at` y una venta puede tener varias filas (`data-model.md §4`). Es el punto más filoso de la fase
- [X] T027 [US2] Agregar las columnas derivadas del desglose impositivo (3 netos, 5 alícuotas, exento, no gravado) a partir de `venta_items.iva_pct`, imputando a una sola columna por grupo (`data-model.md §3`) — vía nueva clase `DesgloseImpositivoVenta`, espejando `DesgloseImpositivoCompra`
- [X] T028 [US2] Agregar las columnas de percepciones, IIBB e impuestos internos, prorrateadas con el mismo criterio que T012
- [X] T029 [US2] Crear `app/Exports/Informes/InformeVentasDetalladoExport.php`: una hoja, KPIs arriba, 44 columnas, recorrido en chunks de 1.000 como el export resumen — **corregido post-deploy el 24/08/2026** contra el archivo real: ver `hallazgos-post-deploy.md` (filas en blanco que Maatwebsite descartaba, estructura rótulo/valor del bloque de KPIs, fechas como texto en vez de serial de Excel). Agregado `test_el_archivo_real_tiene_el_encabezado_en_la_fila_10_y_fechas_como_excel()` como red de seguridad permanente contra el primero
- [X] T030 [US2] Agregar la acción `exportarDetallado` a `app/Http/Controllers/Informes/InformeVentasController.php` y su ruta en `routes/web.php`, aceptando exactamente los mismos filtros que las otras dos (`contracts/export-detallado.md §1`)
- [X] T031 [US2] Agregar el botón "Exportar Excel Detallado" en `resources/views/informes/ventas/index.blade.php`, entre "Exportar Resumen" y "Exportar a PDF", y su handler en `resources/js/informe-ventas.js`
- [ ] T032 [US2] Validar los Escenarios 2, 3, 6 y 7 de `quickstart.md` en el navegador, contrastando contra `Informe_de_Ventas_Detallado_24-08-2026_1429_Hs.xlsx` — **pendiente de validación manual en navegador**

**Checkpoint**: el contador ya puede cerrar el mes sin entrar a Contagram.

---

## Phase 5: User Story 3 — Contenido de las columnas (P2)

**Goal**: que las columnas digan lo mismo que en Contagram.

**Independent Test**: comparar una captura del detalle contra la de Contagram.

### Tests

- [X] T033 [P] [US3] En `tests/Feature/Informes/InformeVentasTest.php`, test de la columna de comprobante: una venta da "Venta", una NC da "Nota de Crédito" (FR-014) — la traducción es un mapeo en JS/blade (`tipo_operacion` ya proyectado); el test cubre lo verificable server-side
- [X] T034 [P] [US3] Test de la columna de producto: una línea de catálogo trae el código antes del nombre; una de concepto libre, sólo la descripción (FR-015)
- [X] T035 [P] [US3] Test de la sigla del comprobante en los exports: `FCB`, `FCA`, `NCB`, y no la letra sola (FR-021)

### Implementación

- [X] T036 [US3] Cambiar la expresión `comprobante` de las dos ramas de `app/Services/Informes/VentasInformeQuery.php` (líneas ~86 y ~171) para que devuelva el tipo de operación en lugar de tipo + número. **Verificar que no rompa el filtro por tipo y número de comprobante**, que usa las columnas técnicas y no ésta — **decisión de diseño**: NO se tocó la columna compartida `comprobante` (la usa la hoja plana del export resumen con su propio sentido — el identificador crudo — y cambiarla ahí duplicaría/rompería su columna "Tipo de Operación" ya existente). En cambio, la pantalla y el PDF pasaron a leer `tipo_operacion` (ya proyectada) con un mapeo a etiqueta ("Venta"/"Nota de Crédito"/"Nota de Débito") en JS y en el blade del PDF respectivamente. Cumple FR-014 sin tocar el motor SQL ni arriesgar los exports
- [X] T037 [US3] Cambiar la expresión `producto` de las dos ramas para anteponer `productos.codigo` cuando exista — **decisión de diseño**: por el alcance explícito de FR-015 ("la pantalla y sólo la pantalla"), no se tocó la columna compartida `producto` (rompería el export/PDF, que deben conservar sólo el nombre). Se agregó `codigo` como columna propia de la proyección (ya existía para el detallado) y la pantalla antepone el código en JS (`productoPantalla`)
- [X] T038 [US3] Agregar la sigla completa del comprobante (`FC`/`NC`/`ND` + letra) como columna de la proyección y usarla en los dos exports — agregada como `sigla_comprobante`, usada también por el export detallado (ya la necesitaba desde la Fase 4)
- [X] T039 [P] [US3] Aplicar el formato contable a los negativos **sólo en pantalla** (`resources/js/informe-ventas.js` y el CSS que haga falta): rojo y entre paréntesis. En los archivos exportados siguen siendo números negativos (FR-016)
- [X] T039b [US3] Verificar que el **PDF** heredó el cambio de la columna de comprobante de T036: consume la misma proyección, así que debería, pero ninguna tarea de la fase lo mira — el PDF es el espejo imprimible de pantalla (mismas 12 columnas, incluida "Comprobante"), así que se actualizó igual que la pantalla, con el mismo mapeo tipo_operación → etiqueta
- [ ] T039c [US3] Al validar, confirmar la sigla real de una **nota de débito**: la spec asume `NDA`/`NDB`/`ND` por simetría, pero los exports del 01/07/2026 no traían ninguna (`spec.md` Assumptions). Si difiere, corregir T038 — **pendiente**: requiere abrir el archivo real de Contagram, no disponible en este entorno de agente
- [ ] T040 [US3] Validar el Escenario 5 de `quickstart.md` en el navegador — **pendiente de validación manual en navegador**

---

## Phase 6: Documentación de dominio (FR-005)

> ✅ **Fase ya ejecutada el 24/08/2026**, antes de generar estas tareas, porque CLAUDE.md y el
> principio I de la constitución exigen actualizar la documentación de dominio **antes** de
> `/speckit-tasks`. Se deja registrada para trazabilidad.

- [x] T041 Corregir en `docs/documentacion_principal_crm.md` § Informe de Ventas la afirmación falsa ("repetido por fila, no sumable") y el conteo de botones, dejando registro de qué decía antes, con qué evidencia se corrigió y la lección metodológica
- [x] T042 Registrar en el mismo documento la excepción al estándar de doble hoja: el detallado sale en una sola hoja, como Contagram
- [x] T043 Agregar `docs/modelo_datos.md §24` con la regla del importe por línea, el criterio de prorrateo, el desglose impositivo derivado y el patrón de lectura del comprobante fiscal

---

## Phase 7: Polish & cierre

- [X] T044 [P] Verificar que Rankings y "Arma tu Informe" siguen funcionando y que su medida "Total Venta" sólo se movió en comprobantes con conceptos extra (CHK003) — 30/30 tests de Pivot/Ranking verdes sin cambios
- [X] T045 [P] Verificar si el **Informe de Compras** tiene el mismo defecto de importe por línea. Si lo tiene, **no arreglarlo acá**: registrarlo como brecha en `docs/documentacion_principal_crm.md §5` para una spec propia (CHK036, `spec.md` Assumptions) — **confirmado que lo tiene** (mismo patrón en `InformeComprasExport`/`informe-compras.js`); registrado en `docs/documentacion_principal_crm.md` § Informe de Compras
- [X] T046 Correr la suite completa: `php artisan test`, y comparar contra el resultado de T002. Los 267 fallos preexistentes por clases de servicio inexistentes son ajenos a esta feature — **Resultado**: `267 failed, 1487 passed` — coincide exacto con el número de fallos preexistentes documentado; cero regresiones en toda la suite (no sólo en Informes)
- [ ] T047 Validar el Escenario 8 de `quickstart.md`: nada de lo que ya andaba se rompió — **pendiente de validación manual en navegador**
- [X] T048 Borrar los datos de prueba **por los endpoints de la aplicación**, no por SQL, y verificar que el `Total Ventas` del período volvió al valor de T001 — **no aplica**: no se creó ningún dato de prueba contra la base real durante la implementación (sólo lecturas puntuales por tinker, de sólo lectura); los tests automatizados corren contra SQLite en memoria, no tocan la base real
- [X] T049 Actualizar `CREDENCIALES_ACCESO.txt` si alguna validación manual requirió crear o resetear un acceso (regla de CLAUDE.md) — **no aplica**: no se creó ni reseteó ningún acceso

---

## Dependencias

```
Phase 1 (Setup)
   ↓
Phase 2 (Medir)            ← GATE: si un comprobante sin conceptos no cierra, parar
   ↓
Phase 3 (US1 · importe)    ← MVP
   ↓
Phase 4 (US2 · detallado)  ← necesita la columna ya corregida
   ↓
Phase 5 (US3 · contenido)  ← independiente, bajo riesgo
   ↓
Phase 7 (Polish)

Phase 6 (Documentación) — ya ejecutada, sin dependencias
```

### Oportunidades de paralelismo

| Grupo | Tareas | Por qué |
|---|---|---|
| Tests de US1 | T005-T011 | Mismo archivo, casos independientes |
| Tests de US2 | T019-T024 | Ídem |
| Tests de US3 | T033-T035 | Ídem |
| Cierre | T044, T045 | Verificaciones de lectura sobre módulos distintos |

**No paralelizables**: T012 → T013/T014/T015 (los tres consumen la columna que T012 corrige);
T025 → T026 → T027 → T028 (misma proyección); T029 → T030 → T031 (el botón necesita la ruta y la
ruta el export).

---

## Estrategia de entrega

**MVP** = Fases 1 a 3. Con eso la columna deja de mentir en las cuatro salidas. Desplegable solo.

**Incremento 2** = Fase 4 (export detallado). Es lo que el cliente pidió explícitamente.

**Incremento 3** = Fase 5 (contenido de columnas). Cosmético, no bloquea nada.

### Antes de deployar al VPS

- La suite verde no alcanza: los tests corren en SQLite y producción es MySQL con
  `ONLY_FULL_GROUP_BY`. Validar en navegador.
- **Nunca probar en producción**: el cliente la está usando. La verificación post-deploy es sólo de
  lectura, comparando KPIs contra una línea de base tomada antes de subir.
- Pedir OK explícito antes de tocar el VPS.

---

## Resumen

| Fase | Tareas | Estado |
|---|---|---|
| 1. Setup | T001-T002 | Pendiente |
| 2. Medir | T003-T004 | Pendiente |
| 3. US1 · importe por línea | T005-T018 (incl. T015b, T015c) | Pendiente |
| 4. US2 · export detallado | T019-T032 (incl. T024b-T024d) | Pendiente |
| 5. US3 · contenido de columnas | T033-T040 (incl. T039b, T039c) | Pendiente |
| 6. Documentación | T041-T043 | ✅ Hecha |
| 7. Polish | T044-T049 | Pendiente |

**Total**: 57 tareas · 3 ya ejecutadas · 54 pendientes

> Las 8 tareas con sufijo (T015b, T015c, T024b-d, T039b, T039c) las agregó `/speckit-analyze`: son
> huecos de cobertura que el pase de tareas no había visto, no alcance nuevo.
