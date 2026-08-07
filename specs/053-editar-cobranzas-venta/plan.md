# Implementation Plan: Editar cobranzas de una venta

**Branch**: `053-editar-cobranzas-venta` | **Date**: 2026-08-07 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `/specs/053-editar-cobranzas-venta/spec.md`

## Summary

Permitir editar una cobranza (Cobro) ya cargada en el detalle de una Venta —monto, fecha,
cuenta de tesorería y nota— reutilizando el modal de alta en modo edición, y unificar la
columna de acciones de la tabla de cobranzas al patrón de desplegable (`_row_actions.blade.php`)
usado en el resto del CRM. La edición actualiza in-place el `MovimientoTesoreria` asociado
(sin anular/recrear) y reaplica el límite de monto máximo = saldo pendiente + monto actual del
cobro editado, para no dejar la venta sobre-cobrada.

## Technical Context

**Language/Version**: PHP 8.2 (Laravel 12)

**Primary Dependencies**: Eloquent ORM, DataTables (AJAX), Bootstrap 5 modales + Select2 (NexaDash), Toastr

**Storage**: MySQL — tablas `cobros` (soft delete) y `movimientos_tesoreria` (soft delete, polimórfico vía `origen`)

**Testing**: PHPUnit/Pest (feature tests sobre `Cobranzas::actualizarCobro()` y sobre el endpoint `cobranzaUpdate`) — obligatorio por Principio IV de la constitución (lógica de saldos de tesorería)

**Target Platform**: Web server (backend Laravel + vistas Blade)

**Project Type**: Web application (monolito Laravel, sin frontend separado)

**Performance Goals**: N/A (operación CRUD de bajo volumen, sin requisito de throughput específico)

**Constraints**: Ninguna edición debe dejar el saldo de la venta negativo (sobre-cobrada); ninguna edición debe crear un `MovimientoTesoreria` duplicado ni huérfano

**Scale/Scope**: 1 modal existente extendido, 1 método de servicio nuevo, 1 endpoint nuevo, 1 vista blade + 1 archivo JS modificados, 1 partial `_row_actions` nuevo

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **Principio I (Documentación de dominio)**: `docs/documentacion_principal_crm.md` no documenta hoy una acción de "editar cobranza" separada de anular+recargar (ver informe `docs/informe_contagram_ingresos.md`). Esta feature agrega una capacidad que no está en Contagram real relevado — se documenta explícitamente como extensión propia del CRM (no una calca de Contagram) en `docs/documentacion_principal_crm.md` como parte de esta feature, para no dejar el doc desactualizado. PASA (con la actualización de doc incluida en las tareas).
- **Principio II (Spec-driven)**: cumplido — esta es una feature de negocio y pasa por specify→clarify→plan→checklist→tasks→analyze. PASA.
- **Principio III (Corrección fiscal/ARCA)**: no aplica directamente (no toca comprobantes ni CAE), pero sí aplica la regla de soft-delete y de no perder trazabilidad de dinero: la edición NO debe borrar físicamente nada, y el `MovimientoTesoreria` se actualiza (no se re-crea) para no romper el ledger. PASA.
- **Principio IV (Testing donde hay dinero/impacto fiscal)**: `actualizarCobro()` toca saldos de tesorería → requiere tests (transición de monto/cuenta, límite de sobre-cobro, cobro anulado no editable). Se incluye en tasks.md. PASA.
- **Principio V (Convenciones Laravel + español)**: nombres de método (`actualizarCobro`, `cobranzaUpdate`), rutas y vistas en español, siguiendo el patrón ya existente (`registrarCobro`, `anularCobro`, `cobranzaStore`, `cobranzaDestroy`). PASA.

No hay violaciones que requieran justificación en Complexity Tracking.

## Project Structure

### Documentation (this feature)

```text
specs/053-editar-cobranzas-venta/
├── plan.md              # This file (/speckit-plan command output)
├── research.md          # Phase 0 output (/speckit-plan command)
├── data-model.md        # Phase 1 output (/speckit-plan command)
├── quickstart.md        # Phase 1 output (/speckit-plan command)
├── contracts/           # Phase 1 output (/speckit-plan command)
└── tasks.md             # Phase 2 output (/speckit-tasks command - NOT created by /speckit-plan)
```

### Source Code (repository root)

```text
app/
├── Http/
│   ├── Controllers/VentaController.php      # + cobranzaUpdate()
│   └── Requests/
│       ├── StoreCobroRequest.php             # existente (alta)
│       └── UpdateCobroRequest.php            # nuevo (edición, excluye el propio cobro del cálculo de saldo)
├── Models/Cobro.php                          # sin cambios de esquema
└── Services/Ingresos/Cobranzas.php           # + actualizarCobro()

resources/
├── views/ventas/
│   ├── detalle.blade.php                     # tabla #tabla-cobranzas: columna de acciones → desplegable
│   ├── _modal_cobranza.blade.php              # modal de alta extendido a modo edición
│   └── _row_actions_cobranza.blade.php        # nuevo partial, patrón _row_actions.blade.php
└── js/ventas.js                               # abrirCobranza() con modo edición, nuevo handler .js-editar-cobro

routes/web.php                                 # + Route::put/patch('{venta}/cobranzas/{cobro}', ...)

docs/documentacion_principal_crm.md            # nota de extensión propia (edición de cobranza) — Principio I

tests/Feature/CobranzasTest.php (o similar)     # tests de actualizarCobro() y del endpoint
```

**Structure Decision**: monolito Laravel existente — no se agregan proyectos ni capas nuevas.
Se extienden los mismos archivos que ya implementan alta/anulación de cobranzas (mismo patrón
`Controller → FormRequest → Service → Model`), siguiendo la convención ya usada en el resto del
CRM para el desplegable de acciones de fila.

## Complexity Tracking

> **Fill ONLY if Constitution Check has violations that must be justified**

| Violation | Why Needed | Simpler Alternative Rejected Because |
|-----------|------------|-------------------------------------|
| [e.g., 4th project] | [current need] | [why 3 projects insufficient] |
| [e.g., Repository pattern] | [specific problem] | [why direct DB access insufficient] |
