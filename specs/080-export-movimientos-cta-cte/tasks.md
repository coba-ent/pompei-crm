# Tasks: Exportar Movimientos de Cuenta Corriente (Clientes y Proveedores)

**Input**: Design documents from `/specs/080-export-movimientos-cta-cte/`
**Prerequisites**: plan.md, research.md, data-model.md, contracts/endpoints.md, quickstart.md

**Tests**: incluidos — la constitución (Principio IV) exige tests para lógica fiscal/de dinero, y este
feature expone importes fiscales (netos, IVA por alícuota) por comprobante.

**Organization**: por user story (US1 = PDF, US2 = Excel), como pide la spec (P1/P1).

## Phase 1: Setup

- [X] T001 Confirmar que no hace falta ninguna migración/config nueva (feature de sólo lectura sobre
      tablas existentes) — sin tarea de código, sólo checkpoint antes de arrancar Foundational.

## Phase 2: Foundational (bloqueante para ambas user stories)

- [X] T002 Extender `LibroIvaQuery::rangoPeriodo()` en `app/Services/Informes/LibroIvaQuery.php` para
      aceptar `fecha_desde`/`fecha_hasta` del request como alternativa a `mes`/`anio` (research.md D1)
      — rama aditiva, sin tocar el comportamiento existente por mes/año que usa el Libro IVA del
      Contador (spec 077). Actualizar el docblock del método.
- [X] T003 [P] Test de regresión: `tests/Feature/Informes/LibroIvaPeriodoTest.php` sigue en verde tras
      T002 (correr la suite existente, no agregar caso nuevo acá — es sólo el gate de "no rompí nada").
- [X] T004 [P] Crear `app/Services/Informes/MovimientosClientesQuery.php`: compone
      `LibroIvaVentasQuery::detalle()` (Venta/Compra + NC/ND, con desglose fiscal) + una query nueva de
      `cobros` (JOIN `ventas`, columnas fiscales en blanco per FR-010) + Saldo Inicial, con las
      columnas extra de data-model.md (Categoría, Tipo de Comprobante/Punto de Venta separados,
      Vendedor sólo en fila Venta, Subtotal sin/con Descuento, Descuento en $, Id Venta). Filtro por
      `cliente_id[]`, `operacion[]`, `fecha_desde`/`fecha_hasta` (mismos params que `movimientosData`
      hoy).
- [X] T005 [P] Crear `app/Services/Informes/MovimientosProveedoresQuery.php`: espejo de T004 sobre
      `LibroIvaComprasQuery`/`pagos`/`compras`, sin Vendedor, con Sellos=0 (FR-017).

**Checkpoint**: con T002-T005 listos, ambas user stories pueden implementarse en paralelo.

---

## Phase 3: User Story 1 - Exportar a PDF los Movimientos filtrados (Priority: P1)

**Goal**: botón "Exportar a PDF" en ambas pestañas Movimientos, PDF apaisado de 11 columnas calcado
del real.

**Independent Test**: filtrar fechas en Movimientos Clientes, exportar a PDF, verificar columnas/
título/paginación contra el PDF real relevado.

### Tests (US1)

- [X] T006 [P] [US1] Test Feature `tests/Feature/Informes/MovimientosClientesPdfTest.php`: request al
      endpoint PDF devuelve `200` y `Content-Type: application/pdf`; con datos de prueba (venta+cobro)
      el PDF contiene las 11 columnas esperadas (aserción sobre el HTML pre-render de la vista Blade,
      no sobre el binario del PDF).
- [X] T007 [P] [US1] Test Feature `tests/Feature/Informes/MovimientosProveedoresPdfTest.php`: espejo
      de T006 del lado Proveedores.

### Implementation (US1)

- [X] T008 [US1] Crear vista `resources/views/informes/pdf/movimientos-cuenta-corriente.blade.php`:
      landscape, título "Informe - Movimientos de Clientes", 11 columnas (Id, Emisión, Cliente,
      Operación, Categoría, Total Venta, Cobrado, A Cobrar, N° de Comprobante, Medio de Cobro,
      Descripción), header teal repetido por página, pie "Pág. X / Y", tope 500 filas con aviso
      (FR-011) — mismo patrón de `resources/views/informes/pdf/cuenta-corriente.blade.php` (Saldos)
      pero landscape y con estas columnas.
- [X] T009 [P] [US1] Crear vista `resources/views/informes/pdf/movimientos-cuenta-corriente-proveedores.blade.php`:
      espejo de T008 (Proveedor/Total Compra/Pagado/A Pagar/Medio de Pago, título "Informe -
      Movimientos de Proveedores").
- [X] T010 [US1] Agregar método `pdfMovimientos(Request $request)` a
      `app/Http/Controllers/Informes/CuentaCorrienteController.php`: usa `MovimientosClientesQuery`
      (T004) limitado a 500 filas, renderiza T008.
- [X] T011 [P] [US1] Agregar método `pdfMovimientos(Request $request)` a
      `app/Http/Controllers/Informes/CuentaCorrienteProveedorController.php`: usa
      `MovimientosProveedoresQuery` (T005), renderiza T009.
- [X] T012 [US1] Agregar rutas `GET informes/cuenta-corriente/movimientos/pdf` y
      `GET informes/cuenta-corriente-proveedores/movimientos/pdf` en `routes/web.php`
      (contracts/endpoints.md).
- [X] T013 [US1] Agregar botón "Exportar a PDF" en el tab Movimientos de
      `resources/views/informes/cuenta-corriente/index.blade.php` (esquina inferior derecha de la
      tabla, mismo lugar que en Saldos) + ruta en `window.InformeCuentaCorrienteConfig.rutas`.
- [X] T014 [P] [US1] Ídem en `resources/views/informes/cuenta-corriente-proveedores/index.blade.php` +
      `window.InformeCuentaCorrienteProveedoresConfig.rutas`.
- [X] T015 [US1] Handler del botón en `resources/js/informe-cuenta-corriente.js`: arma querystring con
      los filtros activos del tab Movimientos (cliente/operación/fechas) y abre la URL vía
      `window.AppPdf.abrir(...)` (fallback `window.open`), mismo patrón que el botón de Saldos.
- [X] T016 [P] [US1] Ídem en `resources/js/informe-cuenta-corriente-proveedores.js`.

**Checkpoint**: US1 exportable y testeable de forma independiente (sin Excel).

---

## Phase 4: User Story 2 - Exportar a Excel con desglose fiscal completo (Priority: P1)

**Goal**: botón "Exportar" (Excel) con las 34/33 columnas reales, reutilizando el motor fiscal del
Libro IVA.

**Independent Test**: exportar Movimientos Clientes con ventas a distintas alícuotas + sus cobros;
verificar las 34 columnas y que los totales coinciden con el Libro IVA del mismo período.

### Tests (US2)

- [X] T017 [P] [US2] Test Feature `tests/Feature/Informes/MovimientosClientesExportTest.php`: una
      venta con ítems a 21% y 10,5% + su cobro completo → assert de las columnas de alícuota, de que
      la fila de Cobro tiene las columnas fiscales en blanco (no 0, FR-010), y de que
      "Aplicada en N° de Factura"/"Fecha Factura Aplicada" van vacías (FR-012).
- [X] T018 [P] [US2] Test Feature `tests/Feature/Informes/MovimientosProveedoresExportTest.php`:
      espejo de T017 con Compra/Pago, más assert de que "Sellos" es siempre 0 (FR-017) y no existe
      columna "Vendedor".
- [X] T019 [P] [US2] Test Feature `tests/Feature/Informes/MovimientosClientesTotalesIgualesLibroIvaTest.php`:
      para un rango que coincide con un mes calendario completo, los totales agregados (neto/IVA/perc)
      del export de Movimientos coinciden con los del Libro IVA Ventas del mismo mes (FR-015).
- [X] T020 [P] [US2] Test Feature: NC/ND dentro del rango aparece con su propio desglose fiscal con
      signo correcto y el resto de sus columnas en blanco (FR-016) — puede ir en
      `MovimientosClientesExportTest.php`.

### Implementation (US2)

- [X] T021 [US2] Crear `app/Exports/Informes/MovimientosClientesExport.php`: una sola hoja "Movimientos
      de Clientes" vía `HojaInforme`, 34 columnas en el orden de data-model.md, usando
      `WithStrictNullComparison` (ceros reales no desaparecen) y dejando en blanco (no 0) las columnas
      fiscales de las filas de Cobro/NC-ND según corresponda (FR-010/FR-016).
- [X] T022 [P] [US2] Crear `app/Exports/Informes/MovimientosProveedoresExport.php`: espejo de T021,
      33 columnas (sin Vendedor, con Sellos=0 antes de Total Compra).
- [X] T023 [US2] Agregar método `exportarMovimientos(Request $request)` a
      `CuentaCorrienteController.php`: `Excel::download(new MovimientosClientesExport(...), nombre)`
      con el patrón de nombre de FR-014.
- [X] T024 [P] [US2] Ídem `exportarMovimientos(Request $request)` en
      `CuentaCorrienteProveedorController.php`.
- [X] T025 [US2] Agregar rutas `GET informes/cuenta-corriente/movimientos/exportar` y
      `GET informes/cuenta-corriente-proveedores/movimientos/exportar` en `routes/web.php`.
- [X] T026 [US2] Agregar botón "Exportar" junto al de PDF (T013) en
      `resources/views/informes/cuenta-corriente/index.blade.php` + ruta en config JS.
- [X] T027 [P] [US2] Ídem en `resources/views/informes/cuenta-corriente-proveedores/index.blade.php`.
- [X] T028 [US2] Handler del botón en `resources/js/informe-cuenta-corriente.js`:
      `window.location.assign(rutas.exportarMovimientos + '?' + $.param(filtros(), true))`, mismos
      filtros que T015.
- [X] T029 [P] [US2] Ídem en `resources/js/informe-cuenta-corriente-proveedores.js`.

**Checkpoint**: ambas user stories completas e independientemente verificables.

---

## Phase 5: Polish & Cross-Cutting

- [X] T030 [P] Ejecutar `npm run build` (assets de los 2 JS tocados) y `php artisan route:list --name=cuenta-corriente` para confirmar que las 4 rutas nuevas quedaron registradas sin colisión.
- [X] T031 [P] Correr toda la suite de `tests/Feature/Informes/` (Libro IVA + Movimientos nuevos) para
      confirmar que T002 no rompió nada del lado del Contador.
- [ ] T032 Validar manualmente en navegador (`run` skill) los 2 PDF + 2 Excel contra los 4 archivos
      reales relevados (mismo criterio de "no dar por cerrado sin contrastar contra la captura real",
      CLAUDE.md) antes de reportar el feature como terminado.

## Dependencies

- Foundational (T002-T005) bloquea Phase 3 y Phase 4 — ambas dependen de
  `MovimientosClientesQuery`/`MovimientosProveedoresQuery` y de que `LibroIvaQuery` acepte rango de
  fechas.
- Dentro de cada Phase, tests (T006-T007, T017-T020) antes que su implementación equivalente si se
  sigue TDD; si no, pueden ir en paralelo con `[P]` porque tocan archivos distintos a los de
  implementación.
- Phase 3 (US1, PDF) y Phase 4 (US2, Excel) son independientes entre sí una vez pasado Foundational —
  se pueden implementar en cualquier orden o en paralelo.
- Phase 5 depende de que 3 y 4 estén terminadas.

## Parallel Example

```
# Tras T002-T005 (Foundational), en paralelo:
T006, T007 (tests PDF)  ⟂  T017-T020 (tests Excel)
T008, T009 (vistas PDF) ⟂  T021, T022 (exports Excel)
T013, T014 (botón PDF)  ⟂  T026, T027 (botón Excel) — mismo archivo Blade, mismo commit sugerido
```

## Implementation Strategy

**MVP** = Phase 1 + 2 + Phase 3 (User Story 1, PDF): entrega el botón de PDF funcionando en ambas
pantallas, que ya es el 100% de lo que la pestaña Saldos ya tiene hoy y es lo más simple/seguro.
Phase 4 (Excel con desglose fiscal) es la parte grande y se agrega después, sin romper lo ya entregado
— exactamente el motivo de dividir en dos user stories P1 independientes en vez de una sola.
