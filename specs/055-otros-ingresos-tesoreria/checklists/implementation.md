# Implementation Checklist: Otros Ingresos en el informe de flujo de caja de Tesorería

**Purpose**: Validar que la implementación cumple el spec antes de dar el fix por cerrado
**Created**: 2026-08-11
**Feature**: [spec.md](../spec.md) | [plan.md](../plan.md)

## Corrección de tipo (FR-003)

- [X] `Cobranzas::registrarOtroIngreso()` genera el movimiento con `tipo = 'ingreso'` (no `'cobro'`)
- [X] `Cobranzas::conciliar()` (que delega en `registrarOtroIngreso()`) también genera `tipo = 'ingreso'` al conciliar un pendiente

## Informe de flujo de caja (FR-001, FR-002)

- [X] `Tesoreria::flujo()` incluye `tipo = 'ingreso'` en el `whereIn` de la sección Cobros
- [X] El total "Total Cobros" de la vista `tesoreria/movimientos` suma correctamente movimientos `ingreso`
- [X] El desglose por cuenta de "Cobros" incluye los montos `ingreso` en la cuenta correspondiente
- [X] El export CSV (`TesoreriaController::movimientosExport`) refleja el mismo total (hereda de `flujo()`, no requiere tocar el CSV en sí)
- [X] El export PDF (`tesoreria/pdf/movimientos.blade.php`) refleja el mismo total (mismo dato, verificar que la vista PDF no tenga su propio query separado)
- [X] El filtro de fecha del informe sigue aplicando igual sobre movimientos `ingreso`

## No regresión (FR-004, FR-005, FR-006)

- [X] El comportamiento de alta/pendiente/conciliación/edición/eliminación de Otros Ingresos no cambia (no existen tests previos de `OtroIngresoController`; se revisó el código de `Cobranzas` y sólo se tocó el literal `tipo`, no la lógica de cuándo se genera)
- [X] No se agregó ninguna migración de base de datos
- [X] `CuentaTesoreria::saldoA()` y la pestaña Saldos no cambian su resultado (código no tocado; sigue sumando todos los tipos sin filtrar)
- [X] La ficha/ledger de cuenta (`tesoreria/cuenta.blade.php`) sigue mostrando los movimientos `ingreso` igual que antes (ya los mostraba, al no filtrar por tipo)

## Verificación con datos reales

- [X] Contra la base real, con `Tesoreria::flujo()` en transacción con rollback, se confirmó `SUM(monto) WHERE tipo='ingreso' = $34.570.442,27` y que ese monto queda incluido en `total_cobros` con el fix aplicado
