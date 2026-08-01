# Implementation Plan: Cuenta Corriente Clientes

**Branch**: `029-cuenta-corriente-clientes` | **Date**: 2026-07-31 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/029-cuenta-corriente-clientes/spec.md`

## Summary

Pantalla nueva de sólo lectura `/informes/cuenta-corriente` con dos tabs (Saldos Clientes / Movimientos), fiel a las capturas reales. "Saldos Clientes" es una extensión del servicio ya existente `App\Services\Tesoreria\CuentaCorriente::aging()` (hoy calcula un único agregado global) para que además pueda agrupar por cliente. "Movimientos" es un listado combinado (UNION) de Ventas + Cobros + Notas de Crédito/Débito, servido vía DataTables server-side, siguiendo el mismo patrón ya usado en `InformeStockController` (subconsulta + `DataTables::of()`). No se reutiliza el exportador/tests huérfanos encontrados en el commit inicial del proyecto (diseño de "Saldo" plano sin aging, descartado — ver spec.md Assumptions); tampoco se agrega exportación en esta iteración.

## Technical Context

**Language/Version**: PHP 8.2 (Laravel 12) + Blade + JavaScript (jQuery/DataTables/Select2)

**Primary Dependencies**: `yajra/laravel-datatables` (ya usado en Informe de Stock, Clientes, etc.), Select2 (filtro Cliente), Bootstrap 5 tabs (NexaDash) — nada nuevo.

**Storage**: MySQL — sin migraciones nuevas; se reutilizan `ventas`, `cobros`, `notas_credito_debito`, `clientes`.

**Testing**: PHPUnit Feature tests para: coincidencia de Total por cliente entre ambos tabs (SC-002), coincidencia del total general contra Tesorería/Dashboard (SC-003), y que "Saldos Clientes"/"Movimientos" filtran y ordenan correctamente. Constitución IV aplica (cuenta corriente = "dinero"): estos tests SÍ son obligatorios, no opcionales.

**Target Platform**: Web (mismo alcance que el resto del CRM)

**Project Type**: Web application (Laravel monolito) — pantalla nueva de informe de sólo lectura

**Performance Goals**: Sin N+1 por fila en "Saldos Clientes" (agregación en pocas queries, no una por cliente) y "Movimientos" resuelto en una sola query UNION paginada server-side — mismo estándar que Informe de Stock (el intento anterior descartado tenía un `CuentaCorrientePerformanceTest` justamente para esto; se preserva esa intención, ver research.md R4).

**Constraints**: Debe cumplir las especificaciones de diseño obligatorias del proyecto (DataTables responsive server-side AJAX; sin vistas estáticas Blade para el listado).

**Scale/Scope**: 1 pantalla nueva con 2 tabs, 1 controller nuevo (`Informes\CuentaCorrienteController`), 1 extensión al servicio existente `CuentaCorriente`, sin tocar Ventas/Cobros/Tesorería/Dashboard (sólo los consume).

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **I. Documentación de dominio como fuente de verdad**: `documentacion_principal_crm.md` §7 ya marca "Cuenta Corriente" como pendiente y aclara que `CuentaCorriente::aging()` es el cálculo mínimo reutilizable. Este plan actualiza ese documento (Phase 1) para reflejar que Cuenta Corriente Clientes ya está implementada, moviéndola de §7 a una sección propia. PASA.
- **II. Desarrollo spec-driven**: flujo completo specify→clarify→plan→checklist→tasks→analyze. PASA.
- **III. Corrección fiscal (ARCA)**: no aplica — no emite comprobantes ni CAE, sólo lee ventas/cobros ya existentes. N/A.
- **IV. Testing donde hay dinero o impacto fiscal**: esta feature calcula **saldos de cuenta corriente** — está explícitamente en el alcance obligatorio de testing de la constitución. Se agregan tests de Feature (ver Technical Context). GATE: no se puede cerrar `/speckit-tasks` sin tareas de test para el cálculo de aging por cliente y la coincidencia de totales (SC-002/SC-003).
- **V. Convenciones Laravel + dominio en español**: rutas/nombres en español (`informes.cuenta-corriente.*`), sigue la convención ya usada por `informes.stock.*`. PASA.

Sin violaciones. No aplica Complexity Tracking.

## Project Structure

### Documentation (this feature)

```text
specs/029-cuenta-corriente-clientes/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
└── tasks.md
```

### Source Code (repository root)

```text
app/Http/Controllers/Informes/
└── CuentaCorrienteController.php   # NUEVO — index (shell 2 tabs), saldosData, movimientosData

app/Services/Tesoreria/CuentaCorriente.php
└── agregar método porCliente(string $tipo, ?Carbon $fecha): Collection  # NUEVO, junto al aging() global ya existente

resources/views/informes/cuenta-corriente/
└── index.blade.php   # NUEVO — shell con tabs Bootstrap "Saldos Clientes"/"Movimientos", filtros, DataTables

resources/js/
└── informe-cuenta-corriente.js   # NUEVO — init DataTables server-side + filtro Select2 Cliente + filtro Operación

resources/views/elements/sidebar.blade.php
└── agregar ítem "Cuenta Corriente" bajo el submenú ya existente "Informes" (junto a "Stock")

routes/web.php
└── informes/cuenta-corriente            (GET, index)                  -> informes.cuenta-corriente.index
    informes/cuenta-corriente/saldos     (GET, JSON DataTables)        -> informes.cuenta-corriente.saldos.data
    informes/cuenta-corriente/movimientos (GET, JSON DataTables)       -> informes.cuenta-corriente.movimientos.data

docs/documentacion_principal_crm.md
└── mueve "Cuenta Corriente" de §7 (pendiente) a una sección propia §6.4, documentando la estructura real
    (2 tabs, columnas, filtros) contrastada contra las capturas nuevas en docs/capturas/saldos/
```

**Structure Decision**: Cambio acotado — un controller nuevo bajo el namespace `Informes` ya existente (mismo patrón que `InformeStockController`), una extensión al servicio de dominio ya existente (`CuentaCorriente`), y una vista/JS nuevos. No se toca el modelo de datos (Venta/Cobro/NotaCreditoDebito/Cliente ya tienen todo lo necesario). Proveedores queda fuera (FR-010).

## Complexity Tracking

*(vacío — sin violaciones de la constitución que requieran justificar)*
