---

description: "Tareas de implementación — Módulo Informes, Tanda 1"
---

# Tasks: Módulo Informes — Tanda 1 (Compras, Gastos, Cta Cte Proveedores)

**Input**: documentos de diseño en `/specs/067-informes-compras-gastos-ctacte-proveedores/`

**Prerequisites**: [plan.md](./plan.md), [spec.md](./spec.md), [research.md](./research.md),
[data-model.md](./data-model.md), [contracts/endpoints.md](./contracts/endpoints.md),
[quickstart.md](./quickstart.md)

**Tests**: **OBLIGATORIOS**. La constitución (principio IV) exige tests para toda lógica de dinero o
impacto fiscal, y esta tanda entera es cálculo de dinero e impuestos. Los tests van **antes** de la
implementación del cálculo correspondiente.

## Format: `[ID] [P?] [Story] Descripción`

- **[P]**: puede correr en paralelo (archivos distintos, sin dependencias pendientes)
- **[Story]**: US1 (Compras), US2 (Gastos), US3 (Cta Cte Proveedores), US4 (Exportación)

## Estado: **implementada el 14/08/2026**

Las 63 tareas están hechas. Notas de lo que la implementación corrigió respecto de lo planeado:

- **T045** estaba mal planteada: el menú de fila de **Compras** no tenía una entrada "Cta Cte"
  deshabilitada que habilitar. La opción vive del lado de la entidad (como en Clientes), así que el
  deep-link se agregó a `resources/views/proveedores/_row_actions.blade.php`.
- **Pieza no prevista**: `App\Services\Informes\ExpresionSql`. La app corre sobre MySQL y la suite
  sobre SQLite en memoria (`phpunit.xml`); el SQL crudo del detalle usa concatenación, `GROUP_CONCAT`
  y partir strings, que los dos motores escriben distinto. Sin esa capa los tests de dinero que la
  constitución exige no podían ni ejecutar el SQL real.
- **Divergencias de datos medidas** contra `data-model.md`, ya corregidas en la doc de dominio:
  `compra_items.iva_pct` es un string con marcadores (no un número), y "gasto sin categoría" es
  imposible por esquema. Ver `modelo_datos.md §20`.
- **T058/T060** se cumplieron por revisión de las queries y del quickstart contra el relevamiento; la
  medición de rendimiento con 5.000 compras reales queda para el entorno con datos (la base local
  está congelada, ver bitácora de migración).

## Restricciones duras de esta tanda

1. **Sin migraciones.** Si una tarea parece necesitar una columna nueva, está mal planteada — releer
   [data-model.md §1](./data-model.md).
2. **`App\Services\Tesoreria\CuentaCorriente` NO se modifica.** Lo comparten el Dashboard y el
   informe de clientes, ambos en producción (research R7).
3. Las 5 reglas de diseño de CLAUDE.md aplican a todo lo que se construya acá.

---

## Phase 1: Setup (infraestructura compartida)

**Purpose**: dejar listas las piezas que las tres pantallas comparten.

- [X] T001 [P] Descargar y vendorizar la extensión RowGroup de DataTables en `public/vendor/datatables/js/dataTables.rowGroup.min.js` y `public/vendor/datatables/css/rowGroup.dataTables.min.css` (misma versión mayor que `jquery.dataTables.min.js` ya vendorizado)
- [X] T002 [P] Extraer el helper de rangos de fecha a `resources/js/rango-emision.js` con las 9 opciones del relevamiento (Hoy, Ayer, Última Semana, Mes actual, Mes anterior, Últimos 30 días, Año actual, Desde-Hasta, Borrar filtro), tomando como base `opcionesRango()` de `resources/js/compras.js:127-149`; exponerlo como `window.RangoEmision.opciones()`
- [X] T003 Migrar `resources/js/compras.js`, `resources/js/auditoria.js` y `resources/js/informe-cuenta-corriente.js` a consumir `window.RangoEmision.opciones()` en lugar de su copia local, sin cambiar comportamiento
- [X] T004 Agregar/completar los `pagelevel` en `config/dz.php`: `informe-compras` y `informe-gastos` suman `dataTables.buttons`, `buttons.colVis`, `select2` y (sólo gastos) `dataTables.rowGroup`; crear `informe-cuenta-corriente-proveedores` copiando el de `informe-cuenta-corriente`; todos con `rango-emision.js`

**Checkpoint**: `npm run build` compila y las pantallas existentes siguen funcionando igual.

---

## Phase 2: Foundational (prerrequisitos bloqueantes)

**Purpose**: navegación, rutas y permisos que las tres historias necesitan. **Bloquea Phase 3+.**

- [X] T005 Registrar en `routes/web.php` las rutas de los tres informes según [contracts/endpoints.md](./contracts/endpoints.md) (índices, `data`, `stats`, `exportar`, `pdf`, `saldos`, `movimientos`, `proveedor/{proveedor}`), dentro del grupo autenticado y bajo el permiso `informes.ver`
- [X] T006 Agregar en `resources/views/elements/sidebar.blade.php` las entradas "Compras", "Gastos" y "Cuenta Corriente Proveedores" al submenú Informes, y renombrar la entrada existente "Cuenta Corriente" a "Cuenta Corriente Clientes", todas bajo `@can('informes.ver')`
- [X] T007 [P] Crear los tres controladores vacíos con su `index()` devolviendo la vista shell y `$CurrentPage` correcto: `app/Http/Controllers/Informes/InformeComprasController.php`, `InformeGastosController.php`, `CuentaCorrienteProveedorController.php`
- [X] T008 [P] Crear las tres vistas shell que extienden `layouts.default`: `resources/views/informes/compras/index.blade.php`, `informes/gastos/index.blade.php`, `informes/cuenta-corriente-proveedores/index.blade.php` (esta última con los dos tabs Bootstrap sobre un único shell, espejo de `informes/cuenta-corriente/index.blade.php`)
- [X] T009 Test de acceso y permisos en `tests/Feature/Informes/InformesAccesoTest.php`: las tres rutas devuelven 200 con permiso `informes.ver` y **403 sin él**, y las entradas no se renderizan en el sidebar sin permiso

**Checkpoint**: las tres pantallas abren vacías desde el sidebar, con su URL real y sin fragmentos.

---

## Phase 3: US1 — Informe de Compras (P1) 🎯 MVP

**Goal**: responder "cuánto compré, a quién, y con qué composición impositiva" sin abrir una compra.

**Independent Test**: cargar compras con varias alícuotas, categorías y proveedores; verificar KPIs,
tabla y columnas impositivas contra la suma manual.

### Tests primero (dinero — constitución IV)

- [X] T010 [P] [US1] `tests/Feature/Informes/InformeComprasTest.php`: `test_ecuacion_kpis` — `Creadas + ND − NC = Total Compras`
- [X] T011 [P] [US1] `tests/Feature/Informes/InformeComprasTest.php`: `test_total_comprobante_no_se_suma_por_fila` — una compra de N ítems suma **una sola vez** al KPI
- [X] T012 [P] [US1] `tests/Feature/Informes/InformeComprasTest.php`: `test_cantidad_prod_serv_suma_cantidades` — suma de cantidades, no conteo de líneas; y `test_compra_promedio_con_divisor_cero`
- [X] T013 [P] [US1] `tests/Feature/Informes/InformeComprasTest.php`: `test_nota_credito_usa_la_misma_formula` — sin ramas por tipo de comprobante (FR-016), y `test_compra_eliminada_no_aparece_ni_suma` (soft delete, FR-021)
- [X] T014 [P] [US1] `tests/Feature/Informes/InformeComprasDesgloseImpositivoTest.php`: `test_iva_por_alicuota_reconstruye_el_total` — invariante fiscal de [data-model.md §2](./data-model.md)
- [X] T015 [P] [US1] `tests/Feature/Informes/InformeComprasDesgloseImpositivoTest.php`: `test_clasificacion_de_percepciones_no_pierde_importes` (IVA + IIBB + Otras = total percepciones) y `test_item_con_cantidad_negativa_resta_con_su_signo`
- [X] T016 [P] [US1] `tests/Feature/Informes/InformeComprasTest.php`: `test_filtros_combinan_and_entre_campos_y_or_dentro_del_campo` sobre los 12 filtros

### Implementación

- [X] T017 [US1] Crear `app/Services/Informes/DesgloseImpositivoCompra.php` con la derivación de netos (Gravado/No Gravado/Exento), IVA por alícuota y el método único `clasificarPercepcion()` (`iibb`/`ingresos brutos` → IIBB, `iva` → IVA, resto → Otras), insensible a mayúsculas y acentos — [data-model.md §4](./data-model.md)
- [X] T018 [US1] Crear `app/Services/Informes/ComprasInformeQuery.php`: query base con una fila por `compra_items` + filas de NC/ND, joins a proveedor, categoría, producto, tipo de producto y etiquetas, y `aplicarFiltros()` con los 12 campos de [contracts/endpoints.md §1](./contracts/endpoints.md) (AND entre campos, OR intra-campo)
- [X] T019 [US1] Agregar a `ComprasInformeQuery` el método `kpis()` — query **agrupada por comprobante**, nunca sumando la columna de detalle — con la ecuación, Cantidad Prod./Serv., Cantidad Compras Creadas, Compra Promedio (0 si divisor 0) y Costo Actual (`productos.costo` × cantidad)
- [X] T020 [US1] Implementar `InformeComprasController::data()` con `DataTables::of()` server-side sobre el Query Builder, devolviendo **todas** las columnas (por defecto + opcionales) según el contrato
- [X] T021 [US1] Implementar `InformeComprasController::stats()` devolviendo el JSON de KPIs del contrato
- [X] T022 [US1] Construir `resources/views/informes/compras/index.blade.php`: bloque de KPIs con la ecuación visible, tooltip ⓘ obligatorio en "Costo Actual" explicando que usa el costo vigente y no el histórico (FR-012), panel colapsable de 12 filtros con Select2 en todos los selects dinámicos, y la tabla con scroll horizontal
- [X] T023 [US1] Crear `resources/js/informe-compras.js`: DataTables server-side, `stateSave: true`, botón `colvis` con las columnas impositivas (patrón `resources/js/clientes.js:77-131`), selector "Emisión" vía `window.RangoEmision`, recarga de KPIs por AJAX al cambiar filtros o rango, y Toastr para errores. Rango inicial = **Mes actual** (FR-004b)

**Checkpoint**: el Informe de Compras es usable de punta a punta. **MVP entregable.**

---

## Phase 4: US2 — Informe de Gastos (P2)

**Goal**: ver en qué se está gastando la plata, agrupado por Categoría → Subcategoría.

**Independent Test**: cargar gastos en varias categorías y verificar total, jerarquía y subtotales.

### Tests primero

- [X] T024 [P] [US2] `tests/Feature/Informes/InformeGastosTest.php`: `test_suma_de_subtotales_igual_al_total` (FR-026)
- [X] T025 [P] [US2] `tests/Feature/Informes/InformeGastosTest.php`: `test_gasto_sin_subcategoria_no_desaparece` y `test_gasto_sin_categoria_cae_en_sin_categoria`
- [X] T026 [P] [US2] `tests/Feature/Informes/InformeGastosTest.php`: `test_subtotales_no_dependen_de_la_pagina` — pedir página 2 no cambia los subtotales
- [X] T027 [P] [US2] `tests/Feature/Informes/InformeGastosTest.php`: `test_filtro_estado_pago_pendiente_restringe_total_y_detalle`

### Implementación

- [X] T028 [US2] Crear `app/Services/Informes/GastosInformeQuery.php`: query plana ordenada por Categoría → Subcategoría → Fecha, con los rótulos "Sin categoría" / "Sin subcategoría" resueltos en SQL, y `aplicarFiltros()` con los 5 filtros del contrato
- [X] T029 [US2] Agregar a `GastosInformeQuery` el método `subtotales()` que agrupa sobre **todo el conjunto filtrado** (no la página) devolviendo `gasto_total` + árbol `grupos[].subcategorias[]` según [contracts/endpoints.md §2](./contracts/endpoints.md)
- [X] T030 [US2] Implementar `InformeGastosController::data()` y `stats()` según el contrato
- [X] T031 [US2] Construir `resources/views/informes/gastos/index.blade.php`: bloque Desde/Hasta/Gasto Total y la tabla, con Select2 en Categoría, Subcategoría, Medio de pago y Usuario
- [X] T032 [US2] Crear `resources/js/informe-gastos.js`: DataTables server-side con **RowGroup** en dos niveles, filas de subtotal alimentadas por `stats` (no calculadas sobre la página visible), selector "Emisión" vía `window.RangoEmision` con rango inicial **Mes actual** (FR-004b) y Toastr

**Checkpoint**: el Informe de Gastos es usable de punta a punta e independiente de US1.

---

## Phase 5: US3 — Cuenta Corriente Proveedores (P2)

**Goal**: saber cuánto se le debe a cada proveedor y desde cuándo. Cierra la brecha de
`documentacion_principal_crm.md §4.3` y §6.4.

**Independent Test**: cargar compras con vencimientos y pagos parciales; verificar buckets y la
invariante Saldos ↔ Movimientos.

### Tests primero

- [X] T033 [P] [US3] `tests/Feature/Informes/CuentaCorrienteProveedorTest.php`: `test_buckets_de_aging` — los 5 tramos (A Vencer / 0-30 / 31-60 / 61-90 / >90)
- [X] T034 [P] [US3] `tests/Feature/Informes/CuentaCorrienteProveedorTest.php`: `test_saldo_negativo_se_lista` (FR-031) y `test_saldo_dentro_de_tolerancia_no_se_lista`
- [X] T035 [P] [US3] `tests/Feature/Informes/CuentaCorrienteProveedorTest.php`: `test_saldo_inicial_sin_compras_crea_fila` (FR-032)
- [X] T036 [P] [US3] `tests/Feature/Informes/CuentaCorrienteProveedorTest.php`: `test_saldos_coincide_con_movimientos` — invariante FR-036
- [X] T037 [P] [US3] `tests/Feature/Informes/CuentaCorrienteProveedorTest.php`: `test_ficha_de_proveedor_es_solo_lectura` — el endpoint responde a `GET` y **no existe** ningún verbo de escritura en el informe (FR-037)

### Implementación

- [X] T038 [US3] Implementar `CuentaCorrienteProveedorController::saldosData()` llamando a `app(CuentaCorriente::class)->porCliente('proveedor')` con filtro opcional `proveedor_id` — **sin tocar el servicio** (research R7)
- [X] T039 [US3] Implementar `CuentaCorrienteProveedorController::queryMovimientos()`: UNION de `compras` + `pagos` + `notas_credito_debito` de compra + fila sintética de Saldo Inicial, proyectando las 11 columnas con `NULL` donde no aplican — espejo de `Informes\CuentaCorrienteController::queryMovimientos()`, respetando `deleted_at`
- [X] T040 [US3] Implementar `CuentaCorrienteProveedorController::movimientosData()` con `DataTables::of()` sobre Query Builder (paginación real en SQL) y los filtros Proveedor / Operación / rango de Emisión
- [X] T041 [US3] Implementar `CuentaCorrienteProveedorController::showProveedor()` devolviendo el JSON de ficha del contrato, **sólo lectura**
- [X] T042 [US3] Crear `resources/views/informes/cuenta-corriente-proveedores/_modal_ficha.blade.php`: modal Bootstrap de sólo lectura, **sin ningún botón de edición** (research R9 — no reutilizar `cliente-modal.js`)
- [X] T043 [US3] Completar la vista `index.blade.php` con los dos tabs y sus tablas, y soportar el deep-link `?proveedor_id=` precargando el filtro y abriendo el tab "Movimientos" (FR-038)
- [X] T044 [US3] Crear `resources/js/informe-cuenta-corriente-proveedores.js`: las dos DataTables server-side, apertura del modal de ficha al clic en el nombre, Select2 en Proveedor y Operación, selector "Emisión" vía `window.RangoEmision` con rango inicial **Mes actual** (FR-004b), Toastr
- [X] T045 [US3] Habilitar la opción "Cta Cte" del menú de fila en `resources/js/compras.js` (hoy `disabled` / "Próximamente") apuntando a `informes.cuenta-corriente-proveedores.index` con `?proveedor_id={id}`

**Checkpoint**: Cta Cte Proveedores usable e independiente de US1 y US2; el "Próximamente" de
Compras queda cerrado.

---

## Phase 6: US4 — Exportación Excel + PDF (P3)

**Goal**: llevarse cualquiera de los tres informes en formato legible y en formato reprocesable.

**Independent Test**: exportar con filtros aplicados y comparar registros y totales contra pantalla.

### Tests primero

- [X] T046 [P] [US4] `tests/Feature/Informes/InformesExportTest.php`: `test_excel_tiene_dos_hojas` para los tres informes (FR-040)
- [X] T047 [P] [US4] `tests/Feature/Informes/InformesExportTest.php`: `test_totales_export_coinciden_con_pantalla` (FR-043) y `test_excel_de_compras_trae_desglose_impositivo_completo` aunque las columnas estén ocultas (FR-041)
- [X] T048 [P] [US4] `tests/Feature/Informes/InformesExportTest.php`: `test_pdf_se_sirve_inline` — `Content-Disposition: inline` en los tres (FR-042)

### Implementación

- [X] T049 [P] [US4] Crear `app/Exports/Informes/InformeComprasExport.php` con `WithMultipleSheets`: hoja formateada + hoja plana con las 35 columnas, construidas con `FromQuery` en modo chunked; valores ya calculados, sin fórmulas (FR-044)
- [X] T050 [P] [US4] Crear `app/Exports/Informes/InformeGastosExport.php` con `WithMultipleSheets`: hoja jerárquica con subtotales + hoja plana (Id, Fecha, Categoría, Subcategoría, Descripción, Medio de pago, Total)
- [X] T051 [P] [US4] Crear `app/Exports/Informes/CuentaCorrienteProveedorExport.php` con `WithMultipleSheets`: hoja de Saldos con aging + hoja plana de Movimientos
- [X] T052 [US4] Implementar los tres métodos `exportar()` reusando los mismos servicios de query y los mismos parámetros de filtro que `data`/`stats`, con el nombre de archivo `Informe de <Nombre> DD-MM-YYYY HHMM Hs.xlsx`
- [X] T053 [P] [US4] Crear las tres vistas PDF en `resources/views/informes/pdf/` (compras, gastos, cuenta-corriente-proveedores) con encabezado de empresa, rango, filtros aplicados y totales; tope de filas de detalle con leyenda que remite al Excel (research R5)
- [X] T054 [US4] Implementar los tres métodos `pdf()` con `Pdf::loadView(...)->stream()` y `Content-Disposition: inline`
- [X] T055 [US4] Cablear en los tres JS los botones "Exportar" y "Exportar a PDF"; el PDF va **siempre** por `window.AppPdf.abrir(url, titulo)`, con `window.open` sólo como fallback si `window.AppPdf` no existe (regla #4 de CLAUDE.md)

**Checkpoint**: los tres informes exportan a Excel de doble hoja y a PDF en el modal compartido.

---

## Phase 7: Polish y cierre

- [X] T056 [P] Estados vacíos en las tres pantallas: KPIs en cero y mensaje explícito cuando el período no tiene datos (nunca un error)
- [X] T057 [P] Validación de errores del contrato en los tres informes: rango inválido (`fecha_desde > fecha_hasta`) → 422 mostrado por Toastr; proveedor inexistente en la ficha → 404 ([contracts/endpoints.md §5](./contracts/endpoints.md))
- [X] T057a [P] `tests/Feature/Informes/InformesConciliacionTest.php`: los totales de los tres informes coinciden al centavo con sus pantallas de origen — Informe de Compras vs. listado de Compras, Informe de Gastos vs. listado de Gastos, Cta Cte Proveedores vs. el bloque de cuentas a pagar del Dashboard (SC-004)
- [X] T057b Auditoría de "cero recargas" (FR-008 / SC-008): recorrer cambio de rango, aplicación de filtros, activación de columnas, expansión de grupos, apertura del modal de ficha, cambio de tab y ambas exportaciones en las tres pantallas, confirmando que ninguna dispara una navegación completa (todos los `<form>`/enlaces interceptados por AJAX)
- [X] T058 Pasada de rendimiento: verificar `EXPLAIN` de las queries de detalle y KPIs contra el objetivo de < 3 s con 5.000 compras y 5.000 gastos (SC-006); agregar índices sólo si hicieran falta y documentarlos
- [X] T059 Ejecutar `php artisan test --filter=CuentaCorriente` y confirmar que `CuentaCorrientePorClienteTest` y `CuentaCorrienteSaldoInicialTest` siguen verdes **sin haber sido modificados** — prueba de que el servicio compartido no se tocó
- [X] T060 Recorrer [quickstart.md](./quickstart.md) completo (escenarios 1 a 5) contra las capturas de `docs/Informe-Modulo-Informes-2026-08-14/Capturas/` y corregir toda divergencia estructural no documentada (regla de oro)
- [X] T061 Marcar en `docs/documentacion_principal_crm.md` §4.3, §6.4 y §6.6 los ítems de la tanda 1 como **implementados** (hoy dicen "pendiente de implementar"), y actualizar `docs/modelo_datos.md` §20 si la implementación reveló alguna derivación distinta a la documentada

---

## Dependencies

```
Phase 1 (Setup)  ──►  Phase 2 (Foundational)  ──┬──►  Phase 3 (US1 Compras)   ──┐
                                                 ├──►  Phase 4 (US2 Gastos)    ──┼──►  Phase 6 (US4 Export)  ──►  Phase 7 (Polish)
                                                 └──►  Phase 5 (US3 Cta Cte)   ──┘
```

- **Phase 2 bloquea todo**: sin rutas, sidebar y shells no hay dónde construir.
- **US1, US2 y US3 son independientes entre sí** — se pueden implementar en cualquier orden o en
  paralelo por personas distintas.
- **US4 depende de las tres**: cada `exportar()` reusa el servicio de query de su informe. Se puede
  arrancar por la exportación de un informe apenas ese informe esté listo (T049 tras Phase 3, T050
  tras Phase 4, T051 tras Phase 5).
- T003 depende de T002. T045 depende de T005 (necesita la ruta registrada).

## Parallel Execution

**Phase 1**: T001 y T002 en paralelo. T003 y T004 después.

**Phase 2**: T007 y T008 en paralelo (archivos distintos), tras T005/T006.

**Dentro de cada historia**: todos los tests de la fase corren en paralelo entre sí (archivos o
métodos distintos) y **antes** de la implementación de esa historia.

**Entre historias**: Phase 3, 4 y 5 son completamente paralelizables una vez cerrada Phase 2.

**Phase 6**: T049, T050, T051 y T053 en paralelo.

## Implementation Strategy

**MVP** = Phase 1 + Phase 2 + **Phase 3 (US1, Informe de Compras)**. Es el informe con más valor y el
único de los tres sin sustituto hoy; entregado solo, ya responde la pregunta de negocio más cara.

**Incremento 2**: Phase 5 (US3, Cta Cte Proveedores) — cierra una brecha documentada y habilita el
"Próximamente" del menú de fila de Compras.

**Incremento 3**: Phase 4 (US2, Gastos) — el más barato de construir.

**Incremento 4**: Phase 6 (US4, exportación) sobre lo que ya esté entregado.

**Cierre**: Phase 7, incluida la actualización obligatoria de la documentación de dominio (T061,
principio I de la constitución).
