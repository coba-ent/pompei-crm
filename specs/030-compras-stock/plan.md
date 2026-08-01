# Implementation Plan: Compras suman stock

**Branch**: `030-compras-stock` | **Date**: 2026-08-01 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `/specs/030-compras-stock/spec.md`

## Summary

Cerrar la brecha simétrica a research.md §R1 de Ventas (spec 008): hoy dar de alta, editar o eliminar
una Compra no mueve stock. Se agrega un servicio `StockDeCompra` (espejo de `StockDeVenta`) que, en
`CompraController::store/update/destroy`, suma/reintegra stock por cada `CompraItem` cuyo producto
controla stock, contra el depósito por defecto del CRM, usando la `fecha_emision` de la Compra como
fecha del movimiento (Clarifications). Reutiliza `StockService` sin cambiar su contrato público salvo
agregar un parámetro `$fecha` opcional a `registrarEntrada`/`registrarSalida` (ya existe en `ajustar()`,
se alinean). Las NC/ND de Compra ya mueven stock (`NotaCreditoDebitoController::storeCompra`, spec 009)
y quedan fuera de alcance.

## Technical Context

**Language/Version**: PHP 8.2, Laravel 12

**Primary Dependencies**: Eloquent (Compra, CompraItem, Stock, MovimientoStock, Deposito), `StockService`
existente (`app/Services/Stock/StockService.php`)

**Storage**: MySQL — no requiere migración nueva (no se agrega ningún campo a `compras` ni
`compra_items`; se reutilizan `stocks` y `movimientos_stock` tal cual)

**Testing**: PHPUnit (Feature tests), siguiendo el patrón de `tests/Feature/` existente para Ventas↔Stock
y para `NotaCreditoDebitoCompraTest.php`

**Target Platform**: aplicación web Laravel (backend); sin impacto en frontend/Blade — el formulario de
Compra no cambia (ver Assumptions de spec.md, FR-006)

**Project Type**: web application (monolito Laravel + Blade existente)

**Performance Goals**: N/A — mismo orden de magnitud que Ventas (decenas de ítems por documento, no hay
requisito de throughput especial)

**Constraints**: la operación de guardar/editar/eliminar una Compra y sus movimientos de stock asociados
debe seguir siendo atómica (misma transacción DB), igual criterio que `StockDeVenta`

**Scale/Scope**: 1 servicio nuevo (`StockDeCompra`), cambios puntuales en `CompraController` (3 métodos:
store/update/destroy) y una extensión menor de `StockService` (parámetro `$fecha` opcional)

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **Principio I (docs como fuente de verdad)**: aplica. `docs/documentacion_principal_crm.md §4.1/§4.3`
  y `docs/modelo_datos.md` se actualizan en el mismo cambio para reflejar que Compras ahora mueve stock
  (y que NC/ND de Compra ya lo hacía). PASA (se ejecuta como parte de esta cadena, antes de `/speckit-tasks`).
- **Principio II (spec-driven)**: aplica, es justamente el flujo que se está siguiendo. PASA.
- **Principio III (corrección fiscal ARCA)**: no aplica — esta feature no toca comprobantes fiscales,
  CAE ni condición de IVA. N/A.
- **Principio IV (testing donde hay dinero/impacto fiscal)**: aplica directamente — "movimientos de
  stock" está listado explícitamente como área que DEBE tener tests. El plan incluye Feature tests para
  las 3 user stories (alta/edición/baja) antes de dar la feature por terminada. PASA (gate cumplido en
  Phase 1 con `quickstart.md` + tests en tasks).
- **Principio V (convenciones Laravel + español)**: `StockDeCompra` en español, mismo patrón de servicio
  que `StockDeVenta`; sin `empresa_id` ni multi-tenant. PASA.
- **Restricción de "Flujo de Desarrollo y Calidad"**: "stock se afecta al vender/comprar no al remitir"
  ya está en la constitución como regla de negocio crítica **ya detectada** — esta feature es
  precisamente su implementación pendiente para el lado de Compras (el lado de Ventas ya está resuelto).
  PASA — no es una regla nueva, es cerrar una implementación pendiente de una regla ya reconocida.

Sin violaciones. No aplica Complexity Tracking.

## Project Structure

### Documentation (this feature)

```text
specs/030-compras-stock/
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
├── Http/Controllers/
│   └── CompraController.php          # store/update: invocan StockDeCompra::aplicarAlta/reaplicarPorEdicion
├── Observers/
│   └── CompraObserver.php            # deleting(): se agrega StockDeCompra::reintegrarPorEliminacion (ya existe, revierte pagos)
├── Models/
│   ├── Compra.php                    # sin cambios de esquema
│   └── CompraItem.php                # sin cambios de esquema
└── Services/
    ├── Egresos/
    │   └── StockDeCompra.php         # NUEVO — espejo de Ingresos/StockDeVenta.php
    └── Stock/
        └── StockService.php          # extender registrarEntrada/registrarSalida con $fecha opcional

tests/
└── Feature/
    └── CompraStockTest.php           # NUEVO — alta/edición/baja suman/reintegran stock (US1/US2/US3)
```

**Structure Decision**: se sigue exactamente la estructura existente del proyecto (monolito Laravel).
`StockDeCompra` va en `app/Services/Egresos/` (nuevo namespace paralelo a `app/Services/Ingresos/` donde
vive `StockDeVenta`), reflejando que Compras pertenece al módulo Egresos. No se crean controladores,
rutas ni vistas nuevas — se reutiliza el `CompraController` y el formulario de Compra existentes.

## Complexity Tracking

*Sin violaciones de la Constitution Check — sección no aplica.*
