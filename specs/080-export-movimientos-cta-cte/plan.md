# Implementation Plan: Exportar Movimientos de Cuenta Corriente (Clientes y Proveedores)

**Branch**: `080-export-movimientos-cta-cte` | **Date**: 2026-08-25 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/080-export-movimientos-cta-cte/spec.md`

## Summary

Agregar los botones "Exportar" (Excel) y "Exportar a PDF" a la pestaña Movimientos de los informes de
Cuenta Corriente Clientes y Proveedores. El PDF reutiliza el patrón ya construido para la pestaña
Saldos (11 columnas, ya en pantalla). El Excel es un archivo nuevo y más grande: reutiliza el motor
fiscal por comprobante ya construido para el Libro IVA del Contador (spec 077 —
`DesgloseImpositivoVenta`/`DesgloseImpositivoCompra`, `LibroIvaVentasQuery`/`LibroIvaComprasQuery`),
adaptado a filtrar por rango de fechas libre en vez de mes/año, y le agrega filas de Cobro/Pago (que
el Libro IVA no tiene) con sus propias columnas.

## Technical Context

**Language/Version**: PHP 8.2, Laravel 12

**Primary Dependencies**: `maatwebsite/excel` (ya usado por `HojaInforme` y el resto de los exports de
informes), `barryvdh/laravel-dompdf` (ya usado por el resto de los PDF de informes)

**Storage**: MySQL/MariaDB (lectura pura — `ventas`, `compras`, `cobros`, `pagos`,
`notas_credito_debito`, `venta_items`, `compra_items`, `venta_conceptos`, `compra_conceptos`,
`comprobantes_fiscales`, `clientes`, `proveedores`, `vendedores`)

**Testing**: PHPUnit/Pest (Laravel Feature tests), mismo patrón que
`tests/Feature/Informes/LibroIva*Test.php`

**Target Platform**: Web (Laravel Blade + DataTables + jQuery, template NexaDash)

**Project Type**: Web application (monolito Laravel, un solo proyecto)

**Performance Goals**: Exportar un rango de fechas típico (un mes) en menos de 5s (SC-001/SC-002 de
la spec); sin objetivo de "tiempo real" — es un export bajo demanda, no una pantalla con polling.

**Constraints**: Sólo lectura (ningún verbo de escritura, igual que el resto de los informes de Cta
Cte); el desglose fiscal NO se reimplementa (reutiliza spec 077); el PDF tiene tope de 500 filas
(FR-011), el Excel no tiene tope (FR-013 assumption).

**Scale/Scope**: Volumen típico de un negocio único (single-tenant) — cientos a pocos miles de
movimientos por mes, no big data.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **I. Documentación de dominio como fuente de verdad**: PASS. No introduce entidades ni reglas de
  negocio nuevas — reutiliza el modelo de datos y las reglas fiscales ya documentadas en
  `docs/documentacion_principal_crm.md` / `docs/modelo_datos.md` para Venta/Compra/Cobro/Pago/NC-ND y
  para el desglose de IVA (spec 077). No requiere actualizar esos docs porque no hay hechos nuevos de
  dominio, sólo una nueva forma de export de datos ya modelados.
- **II. Desarrollo spec-driven**: PASS. Es justamente lo que esta spec cumple — no se salta el flujo.
- **III. Corrección fiscal innegociable (ARCA)**: PASS. El export es de sólo lectura; no crea, edita ni
  numera comprobantes. El desglose de IVA se calcula con el mismo motor ya usado y probado para el
  Libro IVA (spec 077), sin tocar `DesgloseImpositivoVenta`/`DesgloseImpositivoCompra`,
  `LibroIvaVentasQuery`/`LibroIvaComprasQuery` — se reutilizan, no se modifican.
- **IV. Testing donde hay dinero o impacto fiscal**: APLICA. El Excel expone importes fiscales
  (netos, IVA por alícuota, percepciones) por comprobante — requiere tests Feature que verifiquen los
  valores exportados contra comprobantes con distintas alícuotas y contra NC/ND, mismo criterio que
  `LibroIvaTotalesTest`/`LibroIvaFiltrosTest`.
- **V. Convenciones Laravel + dominio en español**: PASS. Nombres de clases, rutas y columnas en
  español, siguiendo el patrón ya establecido (`CuentaCorrienteController`, `HojaInforme`,
  `informes.cuenta-corriente.*`).

Sin violaciones → no aplica Complexity Tracking.

## Project Structure

### Documentation (this feature)

```text
specs/080-export-movimientos-cta-cte/
├── plan.md              # This file
├── research.md          # Phase 0 output
├── data-model.md        # Phase 1 output
├── contracts/
│   └── endpoints.md     # Phase 1 output
├── quickstart.md        # Phase 1 output
└── tasks.md             # Phase 2 output (/speckit-tasks)
```

### Source Code (repository root)

Proyecto Laravel monolítico existente — no se crean carpetas nuevas de alto nivel, sólo archivos
dentro de la estructura ya usada por el resto de los informes de Cta Cte y del Libro IVA:

```text
app/
├── Http/Controllers/Informes/
│   ├── CuentaCorrienteController.php            # + exportarMovimientos()/pdfMovimientos()
│   └── CuentaCorrienteProveedorController.php   # + exportarMovimientos()/pdfMovimientos()
├── Services/Informes/
│   ├── MovimientosClientesQuery.php    # nuevo — reusa LibroIvaVentasQuery + agrega Cobro/saldo inicial
│   ├── MovimientosProveedoresQuery.php # nuevo — reusa LibroIvaComprasQuery + agrega Pago/saldo inicial
│   ├── LibroIvaVentasQuery.php         # existente, spec 077 — NO se modifica, se reutiliza
│   ├── LibroIvaComprasQuery.php        # existente, spec 077 — NO se modifica, se reutiliza
│   ├── DesgloseImpositivoVenta.php     # existente — NO se modifica
│   └── DesgloseImpositivoCompra.php    # existente — NO se modifica
└── Exports/Informes/
    ├── MovimientosClientesExport.php      # nuevo — hoja "Movimientos de Clientes", 34 columnas
    └── MovimientosProveedoresExport.php   # nuevo — hoja "Movimientos de Proveedores", 33 columnas

resources/views/informes/pdf/
├── movimientos-cuenta-corriente.blade.php             # nuevo — landscape, 11 columnas (Clientes)
└── movimientos-cuenta-corriente-proveedores.blade.php # nuevo — landscape, 11 columnas (Proveedores)

resources/views/informes/
├── cuenta-corriente/index.blade.php              # + botones Exportar/Exportar a PDF en tab Movimientos
└── cuenta-corriente-proveedores/index.blade.php  # + botones Exportar/Exportar a PDF en tab Movimientos

resources/js/
├── informe-cuenta-corriente.js              # + handlers de exportar/pdf de Movimientos
└── informe-cuenta-corriente-proveedores.js  # + handlers de exportar/pdf de Movimientos

routes/web.php   # + 4 rutas: cuenta-corriente/movimientos/{exportar,pdf} y su espejo proveedores

tests/Feature/Informes/
├── MovimientosClientesExportTest.php
└── MovimientosProveedoresExportTest.php
```

**Structure Decision**: se sigue exactamente el patrón de directorios ya usado por los informes
existentes (Cta Cte Saldos, Libro IVA) — controllers en `Http/Controllers/Informes`, lógica de query
en `Services/Informes`, exports en `Exports/Informes`, vistas PDF en `views/informes/pdf`. Los nuevos
`MovimientosClientesQuery`/`MovimientosProveedoresQuery` son **composición**, no reimplementación: por
dentro llaman a `LibroIvaVentasQuery::detalle()`/`LibroIvaComprasQuery::detalle()` para las filas de
Venta/Compra + NC/ND, y agregan aparte las filas de Cobro/Pago/Saldo Inicial con sus propias columnas.

## Complexity Tracking

*Sin violaciones a la Constitution Check — sección no aplica.*
