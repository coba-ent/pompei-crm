# Tasks: Costo congelado en el ítem de venta para un CMV fiel a Contagram

**Feature**: `075-cmv-costo-congelado` | **Date**: 2026-08-24
**Input**: [spec.md](./spec.md), [plan.md](./plan.md), [research.md](./research.md), [data-model.md](./data-model.md), [contracts/cmv-api.md](./contracts/cmv-api.md), [quickstart.md](./quickstart.md)

**Tests**: obligatorios. El principio IV de la constitución exige tests para "cálculo de
importes/IVA/descuentos/totales", y esta feature es exactamente eso sobre el KPI que el cliente usa
para decidir.

**Nota de orden**: el orden de fases no es cosmético. La Fase 3 (US2) va **antes** que la Fase 4
(US1) a propósito: con todas las columnas en `NULL`, el `COALESCE` debe dar exactamente lo mismo que
hoy. Ese es el paso que prueba que el fallback funciona **antes** de que exista un solo dato
congelado. Si se hace al revés, un bug de regresión histórica queda enmascarado por los datos nuevos.

---

## Phase 1: Setup

- [X] T001 Tomar la línea de base ANTES de migrar: abrir Informes → Ventas para julio 2026 y para 2026 completo, y anotar `Costo Mercadería Vendida` y `Resultado` de cada rango en `specs/075-cmv-costo-congelado/linea-base.md` (sin este dato el Escenario 2 de `quickstart.md` no se puede validar y SC-003 queda sin verificar)
- [X] T002 Confirmar que la suite arranca verde antes de tocar nada: `php artisan test --filter=InformeVentas`

---

## Phase 2: Foundational — esquema y modelos

**Bloquea todo lo demás.** Al terminar esta fase nada cambió de comportamiento todavía.

- [X] T003 Crear la migración `database/migrations/2026_08_24_XXXXXX_add_costo_unitario_a_items_de_venta_y_notas.php` que agrega `costo_unitario` `decimal(14,2)` **nullable y SIN default** a `venta_items` y a `nota_credito_debito_items`, con `down()` que elimina ambas columnas. Documentar en el docblock por qué es nullable sin default (ver `data-model.md §1`) — es el error más caro de esta feature
- [X] T004 [P] Agregar `costo_unitario` a `$fillable` y castearlo a `decimal:2` en `app/Models/VentaItem.php`
- [X] T005 [P] Agregar `costo_unitario` a `$fillable` y castearlo a `decimal:2` en `app/Models/NotaCreditoDebitoItem.php`
- [X] T006 Ejecutar `php artisan migrate` y verificar en MySQL que ambas columnas quedaron `YES` en Null y `NULL` en Default: `SHOW COLUMNS FROM venta_items LIKE 'costo_unitario'`

**Checkpoint**: esquema listo, todas las filas en `NULL`, comportamiento sin cambios.

---

## Phase 3: User Story 2 — El informe sigue funcionando para las ventas históricas (P1)

**Goal**: que el `COALESCE` esté en su lugar y el informe siga dando **exactamente** lo mismo que
antes, porque todavía no hay ningún costo congelado.

**Independent Test**: abrir el informe de un período íntegramente histórico y comparar contra T001.
Debe dar idéntico.

### Tests (antes de la implementación)

- [X] T007 [P] [US2] Crear `tests/Feature/CmvCostoCongeladoTest.php` con el test del invariante **I1**: una línea con `costo_unitario = NULL` y un producto con compras registradas usa el promedio ponderado (`contracts/cmv-api.md §1.3`)
- [X] T008 [P] [US2] En el mismo archivo, test del invariante **I3**: línea sin costo congelado y producto sin compras ⇒ CMV `0`, nunca `NULL`

### Implementación

- [X] T009 [US2] Extender la firma a `sqlCmv(string $columnaCantidad, ?string $columnaCostoCongelado = null)` en `app/Services/Informes/CostoMercaderiaVendida.php`, devolviendo `COALESCE(<costoCongelado>, costo_compras.costo_promedio, 0) * (<cantidad>)` cuando se pasa la columna, y el comportamiento actual cuando es `null`. **Prohibido** usar `NULLIF(costo_unitario, 0)` (rompe I2)
- [X] T010 [US2] Reescribir el docblock de clase de `CostoMercaderiaVendida.php`: hoy afirma como verdad la premisa refutada ("la única derivación compatible con lo que muestra Contagram es el promedio ponderado"). Debe explicar que el promedio es el **fallback**, que la regla es el costo congelado, y citar la evidencia de `research.md §R1`
- [X] T011 [US2] Pasar `'venta_items.costo_unitario'` a `sqlCmv()` en la rama `queryItems()` de `app/Services/Informes/VentasInformeQuery.php`
- [X] T012 [US2] Pasar `'nota_credito_debito_items.costo_unitario'` a `sqlCmv()` en la rama `queryNotas()` de `app/Services/Informes/VentasInformeQuery.php`, respetando que el `LEFT JOIN` a los ítems puede no traer fila (nota migrada sin detalle): el `COALESCE` debe seguir dando 0 y no romper el KPI de "Total Nota de Crédito"
- [X] T013 [US2] Correr `php artisan test --filter=InformeVentas` — los tests existentes de la spec 068 deben seguir verdes **sin tocarlos**. Si alguno se rompe, el `COALESCE` está mal armado
- [X] T014 [US2] Validar el Escenario 2 de `quickstart.md` en el navegador contra la línea de base de T001: los KPIs históricos deben dar idénticos. **Gate de la fase**: si acá hay diferencia, no seguir a la Fase 4

**Checkpoint**: fallback probado, cero regresión, todavía sin datos congelados.

---

## Phase 4: User Story 1 — El dueño ve el resultado real de sus ventas nuevas (P1)

**Goal**: que toda venta nueva congele el costo y el CMV deje de moverse.

**Independent Test**: crear una venta, cambiar después el costo del producto, verificar que el CMV de
esa venta no se movió.

### Tests

- [X] T015 [P] [US1] En `tests/Feature/CmvCostoCongeladoTest.php`, test del invariante **I2**: una línea con `costo_unitario = 0` sobre un producto **que sí tiene compras registradas** aporta CMV `0`, NO el promedio de compras. Es el test que detecta el `NULLIF` prohibido
- [X] T016 [P] [US1] Test de inmutabilidad (FR-004, SC-002): crear venta, cambiar `productos.costo`, verificar que el CMV del informe no cambió y que el "Costo Actual" sí
- [X] T017 [P] [US1] Crear `tests/Feature/CmvEdicionVentaTest.php` con los casos de FR-009: (a) editar sólo la cabecera conserva el costo; (b) agregar una línea nueva congela el costo del día; (c) venta con el mismo producto en dos líneas conserva ambos costos al editar
- [X] T018 [P] [US1] Test de reproducción del patrón de Contagram (US1 escenario 3): dos ventas del mismo producto separadas por un cambio de costo mantienen cada una su propio CMV

### Implementación

- [X] T019 [US1] Congelar el costo al armar cada línea en `app/Services/Ingresos/CalculoComprobante::calcular()`: agregar `costo_unitario` al array del ítem con `productos.costo` vigente, y `0` cuando no hay `producto_id` o el producto no tiene costo (FR-007). Es el punto por el que ya pasan el alta manual y la edición
- [X] T020 [US1] Conservar el costo a través del borrado y recreación de ítems en `app/Http/Controllers/VentaController::update()` (líneas ~538-568): reusar `$itemsAnteriores`, que **ya se captura** antes del `delete()` para `StockDeVenta::reaplicarPorEdicion()`. Correspondencia por `producto_id`, consumiendo cada costo anterior una sola vez; las líneas sin correspondencia congelan el costo del día (`contracts/cmv-api.md §2`). **Es el punto más filoso de la feature** (`research.md §R5`)
- [X] T021 [P] [US1] Congelar el costo al convertir la orden en `app/Services/MercadoLibre/ConversorOrdenAVenta.php` (línea ~315), con el costo vigente al crear la venta en el CRM
- [X] T022 [P] [US1] Congelar el costo al convertir la orden en `app/Services/Tiendanube/ConversorOrdenAVenta.php` (línea ~232), ídem
- [X] T023 [US1] Verificar que la conversión **presupuesto → venta** queda cubierta sin código extra: pasa por `VentaController::store()` (`presupuesto_id` en líneas ~446 y ~480), o sea por `CalculoComprobante` de T019. **No existe un punto de creación de ítems propio de la conversión** — confirmado el 24/08/2026; `PresupuestoController` sólo crea `presupuesto_items`. No buscar un punto que no existe
- [X] T023b [US1] Verificar que **no** se toca ninguno de los tres comandos de migración (`ImportarVentasHistoricas`, `MigrarVentasContagram`, `RefrescarVentasEditadas`): deben seguir dejando `costo_unitario` en `NULL` para que el fallback los tome (`data-model.md §1`)
- [X] T024 [US1] Validar los Escenarios 1, 3, 4 y 6 de `quickstart.md` en el navegador, incluida la query de cobertura por `origen` (SC-004)

**Checkpoint**: MVP funcional. Las ventas nuevas ya dan el CMV correcto.

---

## Phase 5: Notas de crédito y débito (FR-008)

**Goal**: que anular una venta nueva deje el Resultado en cero.

**Depende de**: Fase 4 (las ventas tienen que congelar antes de que una NC pueda copiar su costo).

### Tests

- [X] T025 [P] Crear `tests/Feature/CmvNotaCreditoTest.php`: NC total sobre una venta con costo congelado ⇒ el `Resultado` neto de venta + NC es exactamente `0`
- [X] T026 [P] En el mismo archivo: línea con `origen = 'nuevo'` congela el costo vigente, y NC **sin** `venta_id` no falla y congela el costo vigente (`data-model.md §2`)
- [X] T027 [P] En el mismo archivo: NC con `origen = 'venta_original'` sobre una venta **histórica** (sin costo congelado) cae al fallback, igual que la venta que revierte

### Implementación

- [X] T028 Centralizar el armado del ítem de nota en un método privado de `app/Http/Controllers/NotaCreditoDebitoController.php` y usarlo en los **6** puntos de creación (líneas ~136, 162, 282, 305, 386, 408), en lugar de repetir la regla seis veces (`research.md §R6`)
- [X] T029 Implementar en ese método la resolución del costo según `data-model.md §2`: `origen = 'venta_original'` con `venta_id` ⇒ copiar el `costo_unitario` de la línea de la venta original con el mismo `producto_id` (primera no consumida); en cualquier otro caso ⇒ `productos.costo` vigente. Guardar siempre **en positivo**: el signo lo aporta la cantidad (invariante I5)
- [X] T030 Validar el Escenario 5 de `quickstart.md` en el navegador

---

## Phase 6: User Story 3 — La documentación de dominio deja de mentir (P2)

> ✅ **Fase ya ejecutada el 24/08/2026**, antes de generar estas tareas, porque CLAUDE.md exige
> actualizar la documentación de dominio **antes** de `/speckit-tasks` (principio I de la
> constitución). Se deja registrada para trazabilidad.

- [x] T031 [US3] Corregir la regla del CMV en `docs/documentacion_principal_crm.md`: regla vigente (costo congelado) + qué decía la spec 068 y por qué estaba mal + la lección metodológica + la verificación de que Compras no tiene el problema
- [x] T032 [US3] Marcar como resuelta la deuda de modelo de `venta_items.costo_unitario` en `docs/modelo_datos.md §Deuda de modelo`
- [x] T033 [US3] Marcar `docs/modelo_datos.md §21.1` como superado por la spec 075, aclarando que sigue vigente **como fallback**
- [x] T034 [US3] Agregar `docs/modelo_datos.md §23` con las dos columnas nuevas, el porqué de nullable-sin-default, la expresión del CMV, la tabla de cuándo se congela, el método de backfill futuro y la verificación de Compras
- [x] T035 [US3] Agregar la nota de corrección al encabezado de `specs/068-informes-ventas-reporte-final/spec.md`
- [x] T035b [US3] Dejar documentado el método exacto de backfill futuro (FR-012), con sus limitaciones conocidas, en `research.md §R9` y `docs/modelo_datos.md §23.5` — para que una spec futura no tenga que redescubrirlo

> **Exclusión sin tareas asociadas**: FR-010 (Informe de Compras) no genera trabajo. Se verificó
> contra el export real que su card "Costo Actual" ya es correcta y que no tiene CMV. Que no tenga
> tareas es el resultado esperado, no una brecha de cobertura.

---

## Phase 7: Polish & cierre

- [X] T036 [P] Revisar que el docblock de la rama `queryNotas()` en `VentasInformeQuery.php` siga siendo exacto después de T012 (hoy dice que "las columnas que sólo viven en el ítem —unidades, costo, CMV— quedan en cero hasta que se migre ese detalle": sigue siendo cierto, pero conviene mencionar el costo congelado)
- [X] T037 [P] Verificar que el export Excel (`app/Exports/Informes/InformeVentasExport.php`) y el PDF (`resources/views/informes/pdf/ventas.blade.php`) reflejan el CMV nuevo sin tocarlos — ambos consumen `VentasInformeQuery`, así que deberían heredarlo. Si no lo hacen, hay una segunda fuente de cálculo que hay que unificar
- [X] T038 [P] Verificar que Rankings y "Arma tu Informe" heredan el cambio por el mismo motor, sin rediseño
- [ ] T039 Correr la suite completa: `php artisan test`
- [X] T040 Decidir e implementar (o descartar explícitamente) una aclaración al usuario de que el informe convive con dos criterios de CMV mientras haya ventas históricas — un pie de tabla o un agregado al tooltip existente de la card "Costo Mercadería Vendida" (`plan.md §Riesgos`, CHK015)
- [X] T041 Validar el Escenario 7 de `quickstart.md` cuando exista un período íntegramente posterior al despliegue: contrastar contra un export de Contagram y confirmar SC-001 (diferencia < 0,1%; línea de base rota: 39%)
- [ ] T042 Actualizar `CREDENCIALES_ACCESO.txt` si alguna validación manual requirió crear o resetear un acceso (regla de CLAUDE.md)

---

## Dependencias

```
Phase 1 (Setup)
   ↓
Phase 2 (Esquema y modelos)  ← bloquea todo
   ↓
Phase 3 (US2 · fallback)     ← GATE: cero regresión antes de seguir
   ↓
Phase 4 (US1 · congelamiento) ← MVP
   ↓
Phase 5 (NC/ND)              ← necesita que las ventas ya congelen
   ↓
Phase 7 (Polish)

Phase 6 (US3 · docs) — ya ejecutada, sin dependencias
```

### Oportunidades de paralelismo

| Grupo | Tareas | Por qué son paralelas |
|---|---|---|
| Modelos | T004, T005 | Archivos distintos |
| Tests de US2 | T007, T008 | Mismo archivo pero casos independientes; se pueden escribir a la vez |
| Tests de US1 | T015, T016, T017, T018 | T017 va en archivo propio |
| Canales externos | T021, T022 | ML y Tiendanube son archivos distintos y no se tocan entre sí |
| Tests de NC | T025, T026, T027 | Casos independientes |
| Cierre | T036, T037, T038 | Verificaciones de lectura sobre archivos distintos |

**No paralelizables** (secuencia obligatoria): T009 → T011 → T012 (misma cadena de cálculo);
T019 → T020 (la conservación en la edición depende de que el alta ya congele).

---

## Estrategia de entrega

**MVP** = Fases 1 a 4. Con eso las ventas nuevas ya dan el CMV correcto y las históricas siguen
intactas. Es desplegable por sí solo.

**Incremento 2** = Fase 5 (notas de crédito). Sin esto, anular una venta nueva deja un residuo en el
Resultado.

**Cierre** = Fase 7.

### Antes de deployar al VPS

- Correr la suite completa (T039) **y** validar en navegador: los tests corren en SQLite y producción
  es MySQL con `ONLY_FULL_GROUP_BY`; está documentado en el proyecto que la suite verde no garantiza
  nada.
- El VPS está congelado desde el 13/08/2026: pedir **OK explícito** del usuario antes de tocarlo.
- Hacer backup de la base antes de la migración, aunque no haya `UPDATE` de datos.

---

## Resumen

| Fase | Tareas | Estado |
|---|---|---|
| 1. Setup | T001-T002 | ✅ Hecha |
| 2. Esquema y modelos | T003-T006 | ✅ Hecha |
| 3. US2 · fallback | T007-T014 | ✅ Hecha |
| 4. US1 · congelamiento | T015-T024 (incl. T023b) | ✅ Hecha (validada en navegador) |
| 5. Notas de crédito | T025-T030 | ✅ Hecha (validada en navegador; ver hallazgo) |
| 6. US3 · documentación | T031-T035b | ✅ Hecha |
| 7. Polish | T036-T042 | ✅ Hecha · T041 validada contra el export real (0,0000%) |

**Total**: 44 tareas · 42 ejecutadas · 2 pendientes (T039 se recorre al deployar, T042 n/a)

---

## Hallazgo de la validación en navegador (24/08/2026)

Las pruebas manuales de T024/T030 encontraron un bug que **ningún test de la fase 5 detectaba**,
porque todos armaban la nota con una línea por línea de venta:

**El formulario de NC/ND agrupa por producto los ítems de la venta original.** Una venta con el
mismo producto en dos líneas de costos distintos (34.320,61 y 99.999,99) genera en la nota **una
sola línea de cantidad 2**. La cola de costos consumía un único valor, la NC revertía
2 × 34.320,61 = 68.641,22 contra los 134.320,60 que aportó la venta, y anular dejaba **$65.679,38
de residuo** en el Resultado — exactamente lo que FR-008 prohíbe.

**Corrección**: la cola pasó a guardar `{cantidad, costo}` y el costo de la línea de nota es el
**promedio ponderado** de las líneas de venta que consume hasta cubrir su cantidad. En el caso de
arriba congela (34.320,61 + 99.999,99) / 2 = 67.160,30, y el CMV revertido iguala al aportado.
Cubierto por `test_una_linea_de_nota_que_agrupa_dos_lineas_de_venta_promedia_sus_costos`, que va
por el endpoint real y no por el helper de tests.
