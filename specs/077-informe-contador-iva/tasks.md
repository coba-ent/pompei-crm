# Tasks: Información para tu Contador (Libro IVA Ventas / Compras)

**Feature**: `specs/077-informe-contador-iva/`
**Spec**: [spec.md](./spec.md) · **Plan**: [plan.md](./plan.md) · **Contratos**: [contracts/endpoints.md](./contracts/endpoints.md)

**Tests: SÍ, obligatorios.** No por preferencia de estilo: la constitución (principio IV) los exige para
toda lógica de importes e impacto fiscal, y este informe es enteramente eso. La batería mínima está en
[quickstart.md](./quickstart.md).

**Sin migraciones**: el informe es de sólo lectura sobre el esquema existente.

---

## Phase 1 — Setup

- [x] T001 Registrar las 7 rutas del informe bajo `Route::middleware('permiso:informes.ver')` en `routes/web.php`, siguiendo el contrato de `specs/077-informe-contador-iva/contracts/endpoints.md` (índice por GET; `data` y `stats` por **POST**; `exportar` por GET)
- [x] T002 [P] Agregar el pagelevel `informe-contador` en `config/dz.php` con los assets del template (DataTables, Select2, Toastr), tomando como referencia el bloque de `informe-compras`
- [x] T003 [P] Agregar la entrada "Información para tu Contador" al desplegable Informes en `resources/views/elements/sidebar.blade.php`, después de "Reporte Final"
- [x] T004 Crear `app/Http/Controllers/Informes/InformeContadorController.php` con el `index()` que renderiza la pantalla y provee los catálogos de filtros (tipos de comprobante, condiciones de IVA, cuentas de tesorería, provincias) y el rango de años con datos

---

## Phase 2 — Foundational (bloquea todas las user stories)

**Es el corazón del informe.** Nada de lo que sigue funciona sin esto.

- [x] T005 Crear `app/Services/Informes/LibroIvaQuery.php` como base común: resolución del período por año/mes, aplicación de filtros de cabecera y forma de los totales. Devuelve Query Builder (no Eloquent) para que DataTables pagine en SQL
- [x] T006 Implementar en `app/Services/Informes/LibroIvaQuery.php` la agregación del desglose impositivo **a nivel comprobante**: la base define el `GROUP BY` por comprobante y el envoltorio `SUM(...)`, y **recibe de la subclase** el servicio de desglose que corresponda (`DesgloseImpositivoVenta` o `DesgloseImpositivoCompra`) — la base no puede saber cuál usar. **No** reimplementar la clasificación de alícuotas (research §D1, §D2)
- [x] T007 Implementar en `app/Services/Informes/LibroIvaQuery.php` el cálculo de los 4 totales agregados sobre el conjunto filtrado completo, dejando `total_facturado` **fuera de SQL** (se deriva en PHP en T008) — research §D6
- [x] T008 Implementar en `app/Services/Informes/LibroIvaQuery.php` el armado del payload de totales respetando el orden de redondeo de FR-011b: redondear por comprobante → sumar para cada uno de los 4 totales → sumar los 4 en PHP para `total_facturado`. Así FR-011 se cumple por construcción
- [x] T009 Implementar en `app/Services/Informes/LibroIvaQuery.php` el desglose impositivo de las NC/ND con las 4 ramas de precedencia de FR-022d (impuestos propios → alícuota heredada del comprobante ajustado → prorrateo entre varias alícuotas → No Gravado). Ver `data-model.md §4` — **es el punto más delicado de la feature**
- [x] T010 [P] Implementar en `app/Services/Informes/LibroIvaQuery.php` la composición de la columna "Tipo" (`FEA`/`FA` tal cual para ventas y compras; `NC`/`ND` + letra para notas) — FR-020a
- [x] T010a [P] Implementar en `app/Services/Informes/LibroIvaQuery.php` la columna **Condición de IVA**, leída de la ficha del cliente/proveedor vía `condiciones_iva` — FR-020b
- [x] T010b [P] Implementar en `app/Http/Controllers/Informes/InformeContadorController.php` el cálculo del rango de años con comprobantes cargados, para poblar el `<select>` de Año — FR-005a
- [x] T011 Implementar los filtros de Medio de Cobro/Pago y Provincia en `app/Services/Informes/LibroIvaQuery.php` usando `EXISTS` (nunca `JOIN`) para no multiplicar filas — FR-031, research §D11
- [x] T012 Crear `resources/views/informes/contador/index.blade.php` con la estructura relevada: dos pestañas, combos Mes/Año, barra de 5 totales con operadores, panel de filtros colapsable, tabla y pie con paginación y leyenda de actualización
- [x] T013 Crear `resources/js/informe-contador.js` con el estado **por pestaña** (período, filtros, columnas visibles), sin compartir estado entre pestañas — FR-030
- [x] T014 [P] Registrar `resources/js/informe-contador.js` en `vite.config.js`

---

## Phase 3 — US1: Libro IVA Ventas (P1) 🎯 MVP

**Objetivo**: elegir mes/año y obtener el Libro IVA Ventas con totales que cierran.
**Test independiente**: cargar ventas con distintas alícuotas, generar el período, y verificar que la
ecuación de totales cierra exacta.

### Tests (antes de implementar)

- [x] T015 [P] [US1] Test en `tests/Feature/Informes/LibroIvaTotalesTest.php`: la ecuación `Total Facturado = No Gravados/Exentos + Gravados + IVA Total + Perc. IVA/IIBB` cierra **exacta** (diferencia cero), con varias alícuotas y percepciones — FR-011, SC-002
- [x] T016 [P] [US1] Test en `tests/Feature/Informes/LibroIvaTotalesTest.php`: Imp. Internos e Imp. Municipales **no** entran en el Total Facturado — FR-011a
- [x] T017 [P] [US1] Test en `tests/Feature/Informes/LibroIvaTotalesTest.php`: los totales corresponden al período completo y no a la página visible — FR-012
- [x] T018 [P] [US1] Test en `tests/Feature/Informes/LibroIvaPeriodoTest.php`: una venta se ubica por su `fecha_emision` — FR-008
- [x] T019 [P] [US1] Test en `tests/Feature/Informes/LibroIvaFiltrosTest.php`: los comprobantes con borrado lógico quedan fuera — FR-022b
- [x] T020 [P] [US1] Test en `tests/Feature/Informes/LibroIvaFiltrosTest.php`: sin `mes`/`anio` los endpoints `data` y `stats` responden 422 con mensaje — FR-007

### Implementación

- [x] T021 [US1] Crear `app/Services/Informes/LibroIvaVentasQuery.php` extendiendo la base: rama de ventas (unión con la de NC/ND de venta), usando `DesgloseImpositivoVenta` y tomando el **total del concepto por comprobante sin prorratear** — research §D2
- [x] T022 [US1] Implementar `ventasData()` en `app/Http/Controllers/Informes/InformeContadorController.php`, con orden por defecto `fecha_emision` ascendente y luego `id` — FR-022a
- [x] T023 [US1] Implementar `ventasStats()` en `app/Http/Controllers/Informes/InformeContadorController.php` devolviendo el payload de totales del contrato
- [x] T024 [US1] Implementar la validación de período (422 si falta `mes` o `anio`) en `app/Http/Controllers/Informes/InformeContadorController.php` — FR-007
- [x] T025 [US1] Implementar en `resources/js/informe-contador.js` la tabla DataTables server-side por **POST** de la pestaña IVA Ventas, con las 19 columnas del contrato
- [ ] T025a [US1] Implementar en `resources/js/informe-contador.js` el pie de tabla completo: cantidad de resultados, selector "Registros por página", navegación de páginas e input **"Ir a la página"** — FR-023
- [x] T026 [US1] Implementar en `resources/js/informe-contador.js` el estado inicial vacío: sin período elegido no se dispara ninguna llamada, la tabla muestra "Utilizá los filtros y generá tu informe a medida" y los totales quedan en `$ 0,00` — FR-006, FR-007
- [x] T027 [US1] Implementar en `resources/js/informe-contador.js` los combos Mes/Año como `<select>` nativos (nunca `input type="date"`) y el refresco de tabla + totales al cambiarlos, sin recargar la página — FR-005, FR-004
- [x] T028 [US1] Implementar en `resources/js/informe-contador.js` el render de los 5 totales con sus operadores visuales `+ + + =` — FR-010

---

## Phase 4 — US2: Libro IVA Compras con imputación contable (P2)

**Objetivo**: la pestaña de Compras, respetando el mes de imputación.
**Test independiente**: una compra emitida en julio e imputada a agosto aparece sólo en agosto.

### Tests

- [x] T029 [P] [US2] Test en `tests/Feature/Informes/LibroIvaPeriodoTest.php`: compra con `mes_imputacion_iva` distinto de `fecha_emision` cae en el período **imputado** y no en el de emisión — FR-009, SC-003
- [x] T030 [P] [US2] Test en `tests/Feature/Informes/LibroIvaPeriodoTest.php`: compra **sin** `mes_imputacion_iva` cae en el período de su `fecha_emision` — FR-009
- [x] T031 [P] [US2] Test en `tests/Feature/Informes/LibroIvaPeriodoTest.php`: una NC/ND cae en **su propio** `mes_imputacion`, no en el del comprobante que ajusta, en ambas pestañas — FR-009a
- [x] T032 [P] [US2] Test en `tests/Feature/Informes/LibroIvaTotalesTest.php`: la NC resta y la ND suma en los totales — FR-022
- [x] T033 [P] [US2] Test en `tests/Feature/Informes/LibroIvaNotasDesgloseTest.php`: las **4 ramas** de FR-022d producen el desglose esperado (impuestos propios / alícuota heredada / varias alícuotas prorrateadas / No Gravado) — **un test por rama**
- [x] T034 [P] [US2] Test en `tests/Feature/Informes/LibroIvaArcaManualesTest.php`: IVA Compras **ignora** los parámetros `arca`/`manuales` y devuelve siempre todos los comprobantes — FR-014a

### Implementación

- [x] T035 [US2] Crear `app/Services/Informes/LibroIvaComprasQuery.php`: rama de compras (unión con NC/ND de compra), usando `DesgloseImpositivoCompra` y resolviendo el período con `COALESCE(mes_imputacion_iva, fecha_emision)` — FR-009
- [x] T035a [US2] Implementar en `app/Services/Informes/LibroIvaComprasQuery.php` la columna N° de Comprobante leyendo `compras.nro_comprobante`, **nunca** el comprobante fiscal — FR-023a (bug ya cometido y corregido en `723b7a24`)
- [x] T036 [US2] Implementar `comprasData()` y `comprasStats()` en `app/Http/Controllers/Informes/InformeContadorController.php`
- [x] T037 [US2] Implementar en `resources/js/informe-contador.js` la pestaña IVA Compras: columnas "Proveedor" y filtro "Medio de Pago" en lugar de "Cliente"/"Medio de Cobro", y **sin** las casillas ARCA/Manuales — FR-014a
- [x] T038 [US2] Implementar en `resources/js/informe-contador.js` el cambio de pestaña sin recarga, preservando el estado propio de cada una — FR-004, FR-030

---

## Phase 5 — US3: ARCA vs. manuales (P2, sólo IVA Ventas)

**Objetivo**: separar lo firme fiscalmente de lo que no.
**Test independiente**: con una venta con CAE y otra sin, cada casilla las incluye o excluye y los
totales acompañan.

### Tests

- [x] T039 [P] [US3] Test en `tests/Feature/Informes/LibroIvaArcaManualesTest.php`: la partición es exhaustiva y sin solapamiento (sólo ARCA + sólo Manuales = ambas tildadas) — FR-017, SC-004
- [x] T040 [P] [US3] Test en `tests/Feature/Informes/LibroIvaArcaManualesTest.php`: una venta con un intento **rechazado y uno aprobado** aparece **una sola vez** y como firme — FR-018. *(Es el incidente de la Venta 24447: si falla, el filtro está usando el `morphOne` en vez del `EXISTS`.)*
- [x] T041 [P] [US3] Test en `tests/Feature/Informes/LibroIvaArcaManualesTest.php`: una venta rechazada cae en "manuales" — FR-016
- [x] T042 [P] [US3] Test en `tests/Feature/Informes/LibroIvaArcaManualesTest.php`: con ambas casillas destildadas el resultado es vacío y los totales cero, **sin** error — FR-019

### Implementación

- [x] T043 [US3] Implementar en `app/Services/Informes/LibroIvaVentasQuery.php` la clasificación firme/manual con `EXISTS` sobre `comprobantes_fiscales` con `estado = 'aprobado'`, **sin** usar la relación `morphOne` `comprobanteFiscal()` — data-model §3
- [x] T044 [US3] Implementar en `resources/js/informe-contador.js` las dos casillas (ARCA tildada, Manuales destildada por defecto) y su refresco de tabla + totales — FR-014

---

## Phase 6 — US4: Filtros y columnas visibles (P3)

**Objetivo**: acotar el informe y ajustar la tabla a la pantalla.

### Tests

- [x] T045 [P] [US4] Test en `tests/Feature/Informes/LibroIvaFiltrosTest.php`: filtrar por Condición de IVA acota tabla **y** totales — FR-027
- [x] T046 [P] [US4] Test en `tests/Feature/Informes/LibroIvaFiltrosTest.php`: una venta con **varios cobros** filtrada por medio de cobro aparece una sola vez y sus importes no se multiplican — FR-031
- [x] T047 [P] [US4] Test en `tests/Feature/Informes/LibroIvaFiltrosTest.php`: los filtros de N° de Comprobante y N° de CUIT coinciden parcialmente — FR-028

### Implementación

- [x] T048 [US4] Implementar los 8 filtros en `app/Services/Informes/LibroIvaQuery.php` combinados con `AND` y siempre dentro del período — FR-026, FR-027
- [x] T049 [US4] Montar el panel de filtros en `resources/views/informes/contador/index.blade.php` con los 8 campos, rotulados según la pestaña
- [x] T050 [US4] Inicializar Select2 en `resources/js/informe-contador.js` para Tipo de Comprobante, Condición de IVA, Medio de Cobro/Pago y Provincia, y con `ajax` para Cliente y Proveedor (catálogo grande) — CLAUDE.md #5
- [x] T051 [US4] Implementar en `resources/js/informe-contador.js` la persistencia de los filtros al cambiar de período — FR-029
- [x] T052 [US4] Implementar el selector de columnas visibles en `resources/js/informe-contador.js` (colvis), que no debe alterar los totales — FR-025

---

## Phase 7 — US5: Exportación a Excel (P3)

**Objetivo**: llevarse el libro del período en una planilla.

### Tests

- [x] T053 [P] [US5] Test en `tests/Feature/Informes/LibroIvaExportTest.php`: el export respeta período, filtros y casillas, y sus importes coinciden con los de pantalla — FR-033, SC-006
- [x] T054 [P] [US5] Test en `tests/Feature/Informes/LibroIvaExportTest.php`: el archivo trae las **19 columnas** aunque haya columnas ocultas en pantalla — FR-034
- [x] T055 [P] [US5] Test en `tests/Feature/Informes/LibroIvaExportTest.php`: exportar sin período responde 422 y no genera archivo — FR-036

### Implementación

- [x] T056 [US5] Crear `app/Exports/Informes/LibroIvaExport.php`: una hoja con el bloque de totales arriba y el detalle completo debajo — research §D12
- [x] T057 [US5] Implementar `ventasExportar()` y `comprasExportar()` en `app/Http/Controllers/Informes/InformeContadorController.php`, con nombre de archivo `Libro IVA {Ventas|Compras} MM-AAAA.xlsx` — FR-035
- [x] T058 [US5] Implementar el disparo de la descarga en `resources/js/informe-contador.js` sobre la URL con los filtros (sin el descriptor de columnas de DataTables), y el aviso por Toastr si no hay período — FR-036, research §D9

---

## Phase 8 — Polish y validación final

- [x] T059 [P] Implementar la leyenda "Actualizado el DD/MM/AAAA a las HH:MM" al pie en `resources/views/informes/contador/index.blade.php` — FR-024
- [x] T060 [P] Implementar el manejo de errores por Toastr en `resources/js/informe-contador.js` para todas las respuestas 422 — CLAUDE.md #3
- [x] T061 Verificar que la columna Imp. Municipales se emite en `0` en tabla y export, y que no participa de ningún total — FR-011a
- [ ] T062 **Validación en navegador contra MySQL** siguiendo los 8 escenarios de `specs/077-informe-contador-iva/quickstart.md`. **No es opcional**: la suite corre en SQLite y MySQL aplica `ONLY_FULL_GROUP_BY`; este informe usa `GROUP BY` intensivamente, así que verde en tests no garantiza que funcione
- [ ] T063 Verificar en DevTools que la llamada a `data` es **POST** con URL corta, y que no aparece ningún 414 — quickstart escenario 8
- [ ] T064 Verificar la fidelidad estructural contra las capturas de `docs/informe_contagram_contador/`: orden exacto de las 19 columnas, los 5 totales con sus operadores, y que las casillas ARCA/Manuales **no** aparecen en IVA Compras

---

## Dependencias

```
Phase 1 (Setup)
   ↓
Phase 2 (Foundational) ← BLOQUEA TODO
   ↓
Phase 3 (US1 · P1) ← MVP entregable por sí solo
   ↓
   ├─→ Phase 4 (US2 · P2)  ─┐
   ├─→ Phase 5 (US3 · P2)  ─┤ independientes entre sí
   ├─→ Phase 6 (US4 · P3)  ─┤
   └─→ Phase 7 (US5 · P3)  ─┘
                             ↓
                      Phase 8 (Polish)
```

**Notas de dependencia**:
- T009 (desglose de NC/ND) bloquea T032 y T033, y condiciona la exactitud de todos los totales.
- T043 (`EXISTS` de ARCA) bloquea toda la Phase 5.
- US2 a US5 son independientes entre sí: se pueden encarar en paralelo una vez cerrada la US1.

---

## Oportunidades de paralelización

- **Phase 1**: T002, T003 y T004 en paralelo (archivos distintos).
- **Phase 2**: T010 y T014 en paralelo con el resto.
- **Tests dentro de cada fase**: todos marcados `[P]` — archivos de test distintos, sin dependencias entre sí.
- **Phases 4 a 7**: equipos distintos pueden tomar una user story cada uno tras cerrar la Phase 3.

---

## Estrategia de implementación

**MVP = Phase 1 + Phase 2 + Phase 3 (US1)**. Entrega el Libro IVA Ventas funcionando de punta a punta:
el contador ya puede liquidar el IVA de ventas de un período. Las demás fases suman valor de forma
incremental sin romper lo anterior.

**Orden recomendado**: MVP → US2 (completa el otro libro, que es la otra mitad del pedido) → US3 →
US5 (el export es lo que efectivamente llega al contador) → US4 → Polish.

**Dónde está el riesgo**: T009 (desglose de NC/ND) y T043 (`EXISTS` de ARCA). Son las dos tareas donde un
error no se ve en pantalla pero falsea un número fiscal. Ambas tienen tests dedicados —conviene
escribirlos primero.

---

## Resumen

| | |
|---|---|
| Tareas totales | 70 |
| Setup + Foundational | 17 |
| US1 (P1, MVP) | 15 (6 tests) |
| US2 (P2) | 11 (6 tests) |
| US3 (P2) | 6 (4 tests) |
| US4 (P3) | 8 (3 tests) |
| US5 (P3) | 6 (3 tests) |
| Polish | 6 |
| **Tests** | **22** |
| Migraciones | **0** |

*(Recuento actualizado tras `/speckit-analyze`: se agregaron T010a, T010b, T025a y T035a para cubrir
FR-020b, FR-005a, FR-023 y FR-023a, que habían quedado sin tarea.)*
