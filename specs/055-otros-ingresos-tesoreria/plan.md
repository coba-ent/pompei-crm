# Implementation Plan: Otros Ingresos en el informe de flujo de caja de Tesorería

**Branch**: `055-otros-ingresos-tesoreria` | **Date**: 2026-08-11 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/055-otros-ingresos-tesoreria/spec.md`

## Summary

El circuito Otros Ingresos → Tesorería ya existe (spec 008: `Cobranzas::registrarOtroIngreso()`/`conciliar()`/`anularOtroIngreso()`, `OtroIngresoController`). El saldo de cuenta ya es correcto porque `CuentaTesoreria::saldoA()` suma todos los tipos de movimiento. El bug real, verificado contra código y datos de producción, es que **`Tesoreria::flujo()`** (el informe "Movimientos" de Tesorería) sólo mira `tipo IN ('cobro')` para la sección "Cobros", dejando afuera `tipo = 'ingreso'` — a pesar de que el banner de esa misma pantalla ya declara que Otros Ingresos deben sumar ahí. Esto deja invisibles $34.570.442,27 de los 61 movimientos históricos migrados. Además, `Cobranzas::registrarOtroIngreso()` genera movimientos nuevos con `tipo = 'cobro'` en lugar de `'ingreso'`, divergiendo del criterio ya usado por el histórico.

Enfoque técnico: dos cambios puntuales, sin migración de datos ni cambio de esquema.
1. `Tesoreria::flujo()`: incluir `'ingreso'` junto a `'cobro'` en el `whereIn` de la sección Cobros.
2. `Cobranzas::registrarOtroIngreso()`: usar `'ingreso'` como tercer argumento de `registrarMovimiento()` en vez de `'cobro'`.

## Technical Context

**Language/Version**: PHP 8.2, Laravel 12

**Primary Dependencies**: Eloquent ORM (query builder para el desglose por cuenta en `Tesoreria::flujo()`), Yajra DataTables (no afectado), sin dependencias nuevas.

**Storage**: MySQL — sin cambios de esquema. `movimientos_tesoreria.tipo` ya acepta `ingreso` desde la migración `2026_08_18_060005_add_legacy_id_e_ingreso_a_movimientos_tesoreria.php`.

**Testing**: PHPUnit (Feature tests de Laravel) — se agregan/ajustan tests sobre `Tesoreria::flujo()` y `Cobranzas::registrarOtroIngreso()`.

**Target Platform**: Web app server-side (Laravel Blade + AJAX), mismo entorno que el resto del CRM.

**Project Type**: Aplicación web monolítica Laravel (no aplica la distinción frontend/backend separada).

**Performance Goals**: N/A — cambio de un `whereIn` con un valor más y un literal de string; sin impacto de performance medible.

**Constraints**: No romper el comportamiento ya vigente de Saldos, ficha/ledger de cuenta, ni el circuito de alta/edición/baja de Otros Ingresos (FR-004 del spec). Sin migración de datos (FR-005).

**Scale/Scope**: 2 archivos de producción tocados (`app/Services/Tesoreria/Tesoreria.php`, `app/Services/Ingresos/Cobranzas.php`) + tests.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **Fidelidad estructural a Contagram**: no aplica cambio de pantalla/estructura — el banner de `tesoreria/movimientos.blade.php` ya describe el comportamiento correcto; este fix hace que el código lo cumpla. Sin cambios de UI.
- **Specs y docs se retroalimentan**: no se detectó ninguna regla de negocio nueva ni entidad nueva — el enum `ingreso` y el comportamiento de Otros Ingresos ya estaban documentados en `docs/modelo_datos.md` §19 y `docs/documentacion_principal_crm.md` §3.3/§3.7. Se agrega una nota aclaratoria puntual sobre el informe de flujo de caja (ver sección Post-Design abajo).
- **DataTables/modales/AJAX/Toastr/Select2**: no aplica — no hay UI nueva, ni tabla, ni modal, ni formulario nuevo.

PASS — sin violaciones que requieran justificar en Complexity Tracking.

## Project Structure

### Documentation (this feature)

```text
specs/055-otros-ingresos-tesoreria/
├── plan.md              # This file
├── spec.md              # Feature spec (revisado tras verificar código real)
├── checklists/
│   └── requirements.md
└── tasks.md              # Phase 2 output (/speckit-tasks)
```

### Source Code (repository root)

```text
app/
├── Services/
│   ├── Tesoreria/
│   │   └── Tesoreria.php           # flujo(): agregar 'ingreso' al desglose de Cobros
│   └── Ingresos/
│       └── Cobranzas.php           # registrarOtroIngreso(): tipo 'cobro' → 'ingreso'
└── Http/Controllers/
    └── TesoreriaController.php     # sin cambios (consume flujo() ya corregido)

tests/
└── Feature/
    ├── Tesoreria/
    │   └── FlujoCajaTest.php       # nuevo o extendido: movimientos 'ingreso' cuentan en Cobros
    └── Ingresos/
        └── OtroIngresoTesoreriaTest.php  # nuevo o extendido: tipo generado es 'ingreso'
```

**Structure Decision**: Cambio quirúrgico dentro de la estructura de servicios ya existente (`app/Services/Tesoreria`, `app/Services/Ingresos`) — no se crean módulos, controladores ni vistas nuevas. No aplica ninguna de las opciones de "aplicación web con frontend/backend separado" del template: este proyecto es un monolito Laravel Blade.

## Complexity Tracking

*Sin violaciones — tabla omitida.*
