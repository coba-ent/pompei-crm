# Tasks: Módulo Informes — Tanda 2 (Ventas, Reporte Final)

**Input**: Design documents from `/specs/068-informes-ventas-reporte-final/`

**Prerequisites**: [plan.md](./plan.md), [spec.md](./spec.md), [research.md](./research.md),
[data-model.md](./data-model.md), [contracts/endpoints.md](./contracts/endpoints.md),
[quickstart.md](./quickstart.md)

**Tests**: obligatorios. Todo el cálculo de esta feature es dinero → principio IV de la constitución.

**Organización**: por user story, en orden de prioridad. US1 solo ya es un MVP entregable.

---

## Phase 1: Setup

- [X] T001 Registrar los bundles `resources/js/informe-ventas.js` y `resources/js/reporte-final.js` en el array `input` de `vite.config.js`
- [X] T002 [P] Agregar los ítems "Ventas" (primero de la lista) y "Reporte Final" al desplegable Informes en `resources/views/elements/sidebar.blade.php`, con sus rutas nombradas y el resaltado por `$CurrentPage`
- [X] T003 [P] Registrar en `config/dz.php` el pagelevel de assets (Select2, DataTables, daterangepicker) de las dos pantallas nuevas, siguiendo lo hecho para `informe-compras`

## Phase 2: Foundational (bloquea todas las user stories)

- [X] T004 Declarar las 9 rutas de `contracts/endpoints.md` en `routes/web.php` dentro del grupo `middleware('permiso:informes.ver')` existente, con los nombres `informes.ventas.*` e `informes.reporte-final.*`
- [X] T005 Crear `app/Services/Informes/CostoMercaderiaVendida.php` con la subconsulta agrupada de costo promedio ponderado por producto (research R2, data-model §Derivaciones), reutilizable por cualquier informe
- [X] T006 [P] Crear el test `tests/Feature/Informes/InformeVentasCmvTest.php` que fije: promedio ponderado sobre compras no eliminadas, producto sin compras → 0, y que `Costo Actual` y `CMV` den distinto sobre el mismo producto
- [X] T007 [P] Extender `tests/Feature/Informes/InformesAccesoTest.php` para cubrir las 9 rutas nuevas sin el permiso `informes.ver`

**Checkpoint**: rutas resueltas y el CMV calculable y testeado. Recién acá arrancan las historias.

---

## Phase 3: User Story 1 — Ver el Informe de Ventas del período (P1) 🎯 MVP

**Goal**: pantalla `/informes/ventas` con los 3 bloques de KPIs y el detalle server-side de 12
columnas, respondiendo al selector de rango.

**Independent Test**: cargar ventas + NC + ND en un período, abrir la pantalla y verificar KPIs y
detalle contra los datos cargados, cambiando el rango sin recargar.

- [X] T008 [US1] Crear `app/Services/Informes/VentasInformeQuery.php` con el método `detalle()`: proyección homogénea de `venta_items` y `notas_credito_debito_items`, `unionAll` + `fromSub('detalle')`, con el rango aplicado **dentro de cada rama** (research R1, R9) y las NC en negativo
- [X] T009 [US1] Agregar a `VentasInformeQuery` los joins de rótulos (cliente, producto, tipo de producto, proveedor, categoría, vendedor, usuario) y las columnas derivadas `costo_total_actual`, `cmv_total` (vía `CostoMercaderiaVendida`), `precio_neto` y `resultado = precio_neto − cmv_total`, sin ramas por tipo de comprobante
- [X] T010 [US1] Agregar a `VentasInformeQuery` el método `kpis()` con los 11 valores de los 3 bloques sobre el conjunto filtrado completo (data-model §KPIs), incluida la guarda de división por cero en Venta Promedio
- [X] T011 [US1] Crear `app/Http/Controllers/Informes/InformeVentasController.php` con `index()`, `data()` y `stats()`, espejando la validación de rango (`422`) de `InformeComprasController`
- [X] T012 [US1] Crear `resources/views/informes/ventas/index.blade.php` con los 3 bloques de KPIs, el selector "Emisión", la tabla de 12 columnas con scroll horizontal y los botones "Exportar Resumen" / "Exportar a PDF"
- [X] T013 [US1] Crear `resources/js/informe-ventas.js`: DataTables server-side (orden por defecto `fecha desc, id desc`), carga de KPIs desde `stats`, integración con `window.RangoEmision` y avisos por Toastr
- [X] T014 [US1] Crear `tests/Feature/Informes/InformeVentasTest.php`: ecuación de KPIs, una fila por ítem, signos de NC/ND, `Resultado = Precio Neto − CMV` en todas las filas, orden por defecto, respeto del borrado lógico, KPIs invariantes al paginar, y los edge cases de ítem sin producto (Costo Actual y CMV en 0) y de nota sin venta asociada (la fila aparece igual)

**Checkpoint**: el Informe de Ventas se puede usar y demostrar por sí solo.

---

## Phase 4: User Story 2 — Filtrar y exportar el Informe de Ventas (P2)

**Goal**: los 19 filtros del panel y la exportación dual Excel + PDF.

**Independent Test**: aplicar cada filtro por separado y comprobar que tabla, KPIs y los dos archivos
responden al mismo conjunto filtrado.

- [X] T015 [US2] Agregar `aplicarFiltros()` a `VentasInformeQuery` con los 12 filtros directos de research R7 (id, producto, tipo de producto, cliente, vendedor, categoría —raíz o hija—, proveedor, etiqueta, tipo y n° de factura, usuario, notas, tipo de operación), AND entre campos y OR dentro de cada multi-valor
- [X] T016 [US2] Agregar los 7 filtros derivados de agregados con `whereExists`/subconsulta: Productos (con o sin producto asociado), Facturado, Estado del Cobro, Remitos, Tipo y N° de Remito, Transportista
- [X] T017 [US2] Cargar en `InformeVentasController::index()` los catálogos de los Select2 y renderizar el panel "Filtros" de 5 filas con su botón "Buscar" en `resources/views/informes/ventas/index.blade.php`
- [X] T018 [US2] Cablear el panel de filtros en `resources/js/informe-ventas.js`: Select2 con `width:'100%'`, envío de los filtros a `data` y `stats`, y recarga sin refrescar la página
- [X] T019 [US2] Crear `app/Exports/Informes/InformeVentasExport.php` (`WithMultipleSheets`, reutilizando `HojaInforme`) con la hoja legible (KPIs + 12 columnas del export) y la hoja plana "Ventas"
- [X] T020 [US2] Implementar la **réplica R1** en un método privado y comentado de `InformeVentasExport`: sólo la celda `Resultado` de las filas NC de la hoja legible usa `Precio + CMV`; la hoja plana y los totales quedan intactos
- [X] T021 [P] [US2] Crear `resources/views/informes/pdf/ventas.blade.php` reutilizando `_estilos.blade.php`, y el método `pdf()` del controlador con `Content-Disposition: inline`
- [X] T022 [US2] Agregar los handlers de exportación en `resources/js/informe-ventas.js`: Excel por descarga directa y PDF vía `window.AppPdf.abrir(url, titulo)` con `window.open` sólo como fallback
- [X] T023 [US2] Ampliar `tests/Feature/Informes/InformeVentasTest.php` con la cobertura de filtros clave (cliente, categoría por raíz, estado del cobro, tipo de operación, facturado) y la coincidencia export ↔ pantalla

**Checkpoint**: el Informe de Ventas queda cerrado y contrastable contra el relevamiento.

---

## Phase 5: User Story 3 — Consultar el Reporte Final del período (P3)

**Goal**: las dos vistas del Reporte Final con su jerarquía, el simulador "Activo" y la exportación
dual.

**Independent Test**: cargar ventas con cobros parciales, compras con pagos, otros ingresos y gastos
(pendientes y pagados) y verificar que cada vista arma sus totales según su base y que el simulador
recalcula al vuelo.

- [X] T024 [US3] Crear `app/Services/Informes/ReporteFinalQuery.php` con la vista **devengado**: agregaciones de Ventas, Otros Ingresos, Compras y Gastos (Categoría → Subcategoría, **con** pendientes) imputadas por fecha de comprobante, devolviendo el árbol en positivo con `naturaleza`
- [X] T025 [US3] Agregar a `ReporteFinalQuery` la vista **caja**: Ventas Cobradas y Compras Pagadas por Categoría → Cuenta de Tesorería imputadas por fecha de cobro/pago, Gastos por Categoría → Subcategoría → Cuenta **sin** pendientes, y Otros Ingresos por Categoría → Cuenta (research R5)
- [X] T026 [US3] Implementar en `ReporteFinalQuery` los rótulos de fallback ("Sin categoría", "Sin subcategoría", "Sin cuenta de tesorería") y el listado de **todas** las cuentas activas dentro de una categoría con actividad, aunque su monto sea 0 (FR-038)
- [X] T027 [US3] Crear `app/Http/Controllers/Informes/ReporteFinalController.php` con `index()` y `data()` (parámetro `vista`), con la misma validación de rango
- [X] T028 [US3] Crear `resources/views/informes/reporte-final/index.blade.php`: cabecera Desde/Hasta/Total Ingresos/Total Egresos/Resultado, las dos vistas, los banners informativos descartables y el árbol expandible con la columna "Activo"
- [X] T029 [US3] Crear `resources/js/reporte-final.js`: integración con `window.RangoEmision` (las 9 opciones del selector Emisión, FR-002), render del árbol, cambio de vista sin recargar, y el **simulador** que recalcula subtotales, totales y Resultado en el cliente al destildar una categoría, sin petición de red (FR-034)
- [X] T030 [US3] Crear `app/Exports/Informes/ReporteFinalExport.php` con la hoja legible (layout de Contagram) y la hoja plana `Detalle`, aplicando `excluidas[]` del simulador
- [X] T031 [US3] Implementar la **réplica R2** en `ReporteFinalExport`, comentada y acotada a la hoja legible: signos y fórmula de `Resultado` distintos por vista, subtotales de bloque negativos y líneas de cuenta positivas en la vista caja; Desde/Hasta siempre completos
- [X] T032 [P] [US3] Crear `resources/views/informes/pdf/reporte-final.blade.php` y el método `pdf()`, con los signos de **pantalla** (egresos en positivo) y respetando `excluidas[]`
- [X] T033 [US3] Cablear en `resources/js/reporte-final.js` la exportación a Excel y el PDF por `window.AppPdf.abrir()`, enviando siempre la vista activa y las categorías destildadas
- [X] T034 [US3] Crear `tests/Feature/Informes/ReporteFinalTest.php`: jerarquía de cada vista, pendientes sólo en devengado, imputación por fecha de cobro/pago, rótulos de fallback (incluido "Sin categoría", que en ventas/compras es un caso real por ser `categoria_id` nullable), cuentas visibles en $0,00 listadas, respeto del borrado lógico (FR-009) y la invariante caja ≤ devengado
- [X] T035 [US3] Crear `tests/Feature/Informes/ReporteFinalSimuladorTest.php`: `excluidas[]` reduce los totales del export exactamente en el monto de las categorías excluidas, y con todas excluidas el archivo sale en $0,00 sin error

**Checkpoint**: los dos informes de la tanda funcionan de punta a punta.

---

## Phase 6: Polish & Cross-Cutting

- [X] T036 Crear `tests/Feature/Informes/ReplicasContagramTest.php` que fije **R1** (celda desviada presente en la hoja legible, hoja plana y KPIs intactos) y **R2** (signos y fórmula de Resultado por hoja) — es la red que evita que un revisor futuro las "corrija"
- [X] T037 [P] Verificar el objetivo de rendimiento de SC-002 con ~5.000 ventas en el rango sobre `data` y `stats`, y ajustar si el filtro de rango no está entrando dentro de cada rama del `UNION ALL`
- [X] T038 [P] Recorrer `quickstart.md` completo en el navegador y contrastar las dos pantallas contra las capturas del relevamiento (columnas exactas, orden, rótulos de botones, filas del panel de filtros, bloques de KPIs) — regla de oro de CLAUDE.md
- [X] T039 [P] Confirmar el cumplimiento de las 5 reglas de diseño obligatorias en las dos pantallas: DataTables server-side en Ventas, cero recargas, Toastr, PDF en el modal compartido, Select2 en todo select de catálogo
- [X] T040 Correr `php artisan test --filter=Informes` completo (incluidos los tests de la Tanda 1) y dejar todo en verde antes de dar la feature por cerrada

---

## Dependencias

```
Setup (T001–T003)
   └─> Foundational (T004–T007)   ← bloquea todo
          ├─> US1 (T008–T014)     ← MVP
          │      └─> US2 (T015–T023)   (necesita la query y la pantalla de US1)
          └─> US3 (T024–T035)     ← independiente de US1/US2
                 └─> Polish (T036–T040)
```

- **US1 y US3 son independientes entre sí**: se pueden desarrollar en paralelo una vez cerrada la
  fase Foundational.
- **US2 depende de US1** (extiende su query, su vista y su bundle).
- T036 depende de T020 y T031 (las dos réplicas ya implementadas).

## Oportunidades de paralelismo

- T002 y T003 en paralelo con T001.
- T006 y T007 en paralelo entre sí.
- T021 (PDF de Ventas) en paralelo con T019/T020 (Excel de Ventas): archivos distintos.
- T032 (PDF del Reporte Final) en paralelo con T030/T031.
- T037, T038 y T039 en paralelo al cierre.

## Estrategia de implementación

1. **MVP**: Setup + Foundational + US1 → Informe de Ventas usable y demostrable.
2. **Incremento 2**: US2 → el informe queda completo (filtros + exports).
3. **Incremento 3**: US3 → Reporte Final.
4. **Cierre**: Polish, con T036 y T040 como condición de salida obligatoria.

## Nota de documentación (constitución I)

`docs/documentacion_principal_crm.md §6.6` y `docs/modelo_datos.md §21` **ya fueron actualizados**
antes de generar estas tareas: se registró la tanda 2, la definición del CMV, el cambio de criterio
sobre las réplicas R1/R2, las divergencias deliberadas y la brecha de los 3 filtros no identificados.
Si durante la implementación aparece una regla nueva, se actualizan **en el mismo cambio**.
