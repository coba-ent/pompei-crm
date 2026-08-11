# Implementation Plan: Estado "Vencido" en Compras + ítems con cantidad negativa

**Branch**: `058-compras-vencido-item-negativo` | **Date**: 2026-08-11 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/058-compras-vencido-item-negativo/spec.md`

## Summary

Feedback directo de un cliente sobre Compras (real, no relevamiento): (1) el badge de estado de cada
fila y el filtro "Estado del Pago" no distinguen "Vencido" (compra con vto. de pago pasado y saldo
pendiente) — el KPI agregado ya existe, y el filtro backend también (spec 056), pero el badge de fila
usa `Compra::estadoPago()`, que nunca devuelve `'vencido'`; (2) el formulario de Compra rechaza ítems
con cantidad negativa, necesarios para cargar bonificaciones del proveedor dentro de la misma factura
(confirmado con captura de Contagram real: el campo Cantidad admite negativos, el Precio no). El
segundo punto esconde un bug de signo en `StockDeCompra` que hay que corregir para que el stock
resultante sea correcto, no sólo permitir la carga.

## Technical Context

**Language/Version**: PHP 8.2, Laravel 12

**Primary Dependencies**: Laravel 12, Blade, jQuery + DataTables (server-side), sin dependencias nuevas

**Storage**: MySQL — sin migraciones (ningún cambio de esquema, ver data-model.md)

**Testing**: PHPUnit 11 vía Pest plugin, `Tests\Feature\*Test extends TestCase` con `RefreshDatabase`

**Target Platform**: Web (mismo stack ya en producción)

**Project Type**: Extensión de módulo Compras ya existente — sin proyecto/capa nueva

**Performance Goals**: N/A — cambios de lógica de bajo volumen (badges, validación de formulario)

**Constraints**: Sin recarga de página (regla de diseño obligatoria); el filtro "Vencido" debe usar
exactamente la misma condición que el KPI ya existente (no puede divergir — SC-002)

**Scale/Scope**: 1 modelo (`Compra::estadoPago()`), 1 servicio (`StockDeCompra`), 2 FormRequests
(`Store`/`UpdateCompraRequest`), 2 vistas Blade (`index.blade.php`, `_row_actions.blade.php`) — sin
JS nuevo (research.md §4: el cálculo de totales en `compras.js` ya soporta cantidad negativa)

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **Principio I (Documentación de dominio como fuente de verdad)**: ✅ El pedido de "Vencido" viene
  de feedback directo del cliente sobre el CRM ya en producción (no de un relevamiento de Contagram
  real vía capturas) — no aplica actualizar `informe_contagram_*.md`. El pedido de cantidad negativa
  sí se confirmó contra una captura real de Contagram (aportada por el cliente durante esta sesión) —
  se documenta en `docs/documentacion_principal_crm.md` §4.1 (Compras) que el campo Cantidad admite
  negativos, con nota de precedencia (captura real 11/08/2026).
- **Principio II (Desarrollo spec-driven)**: ✅ spec → clarify (preguntas directas al usuario,
  resueltas antes de `/speckit-specify`) → plan, en orden.
- **Principio III (Corrección fiscal innegociable)**: ✅ Aplica al punto de stock — FR-009 exige que
  el movimiento de stock generado por un ítem negativo tenga el signo correcto (no se "pierde" nada,
  ver research.md §5); sin esta corrección el stock quedaría mal de forma silenciosa, que es
  justamente lo que este principio busca evitar.
- **Principio IV (Testing donde hay dinero o impacto fiscal)**: ✅ Aplica — toca movimientos de stock
  y el total de la compra; requiere tests Feature para: badge/filtro "Vencido" (incluye el caso
  "pagada con vto. pasado" que NO debe ser vencido), ítem negativo con stock correcto, precio negativo
  sigue rechazado.
- **Principio V (Convenciones Laravel + dominio en español)**: ✅ Reutiliza nombres y patrones ya
  existentes (`estadoPago()`, `StockDeCompra`), sin naming nuevo que definir.

**Resultado**: ninguna violación. No aplica Complexity Tracking.

## Project Structure

### Documentation (this feature)

```text
specs/058-compras-vencido-item-negativo/
├── plan.md              # This file
├── research.md          # Phase 0 output
├── data-model.md         # Phase 1 output
├── quickstart.md         # Phase 1 output
└── tasks.md              # Phase 2 output (/speckit-tasks)
```

### Source Code (repository root)

```text
app/
├── Models/
│   └── Compra.php                          # estadoPago() + rama 'vencido'
├── Http/
│   └── Requests/
│       ├── StoreCompraRequest.php          # items.*.cantidad: gt:0 → not_in:0
│       └── UpdateCompraRequest.php         # idem
└── Services/
    └── Egresos/
        └── StockDeCompra.php               # entrada/salida según signo de cantidad

resources/
└── views/
    └── compras/
        ├── index.blade.php                 # <option value="vencido"> en filtro Estado del Pago
        └── _row_actions.blade.php          # rama 'vencido' => danger/'Vencido' en el match

docs/
└── documentacion_principal_crm.md          # nota: Cantidad admite negativo en Compras (§4.1)

tests/Feature/
├── CompraVencidoTest.php                   # NUEVO
└── CompraItemNegativoTest.php              # NUEVO
```

**Structure Decision**: extensión pura de archivos ya existentes — no se crea ningún controlador,
ruta o vista nueva. `resources/js/compras.js` no requiere cambios (research.md §4).

## Complexity Tracking

*Sin violaciones de la Constitution Check — sección no aplica.*
