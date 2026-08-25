# Implementation Plan: Neteo de NC/ND en Rankings del Dashboard

**Branch**: `079-neteo-nc-nd-rankings-dashboard` | **Date**: 2026-08-24 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/079-neteo-nc-nd-rankings-dashboard/spec.md`

## Summary

Los dos rankings del Dashboard (`DashboardController::rankings()`) — Clientes por monto vendido y
Productos por cantidad vendida — hoy se calculan sobre el bruto de `Venta`/`VentaItem`, sin
considerar Notas de Crédito/Débito. Se extiende el mismo criterio de neteo que ya usan
KPIs/Totales/Donas del Dashboard (`montoNetoQuery()`, spec 046, revisión 18/08/2026: **sin piso en
$0**, sin techo para ND, imputación de la NC/ND al período de la Venta que ajusta) pero agrupado
por `cliente_id` (Ranking de Clientes) y por `producto_id` a nivel de línea (Ranking de Productos),
en vez de a nivel de rango completo. No toca el Ranking de Informes (spec 069), que es un módulo
distinto (PivotTable.js) con su propia fuente de datos.

## Technical Context

**Language/Version**: PHP 8.2, Laravel 12

**Primary Dependencies**: Eloquent ORM, `DB::select` (raw SQL, mismo patrón que `montoNetoQuery()`)

**Storage**: MySQL — tablas `ventas`, `venta_items`, `notas_credito_debito`, `nota_credito_debito_items`

**Testing**: PHPUnit (Feature test sobre `DashboardController::rankings()`), en línea con Principio IV
de la constitución (cálculo de importes) — obligatorio acá porque afecta directamente un monto e
indirectamente una cantidad de stock vendido.

**Target Platform**: Servidor web Laravel existente (mismo entorno que el resto del Dashboard)

**Project Type**: Web application (Laravel monolito, Blade + AJAX) — se reutiliza el endpoint
existente de rankings, no se crea uno nuevo

**Performance Goals**: El endpoint de rankings del Dashboard debe responder en el mismo orden de
magnitud que hoy (uso interactivo, filtro de período); no se introduce un N+1 por cliente/producto,
todo el neteo se resuelve en las mismas 1-2 queries agregadas por ranking (mismo patrón de
`montoNetoQuery()`, no un query por fila del Top 10)

**Constraints**: No se modifica `montoNetoQuery()` ni el comportamiento ya vigente de
KPIs/Totales/Donas (FR-008); no se modifica el Ranking del módulo Informes (spec 069) (FR-007)

**Scale/Scope**: Cambio acotado a un método existente (`rankings()`) de un controlador ya
implementado; sin nuevas tablas ni endpoints

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **Principio I (Documentación de dominio)**: `docs/documentacion_principal_crm.md` §6.3 (línea
  ~2198-2202) describe el criterio de neteo con **piso en $0**, pero el código real
  (`montoNetoQuery()`, revisión 18/08/2026) ya no lo aplica. **Contradicción detectada y resuelta
  con el usuario** (ver Clarifications en spec.md): se sigue el código actual (sin piso). **Acción
  obligatoria de esta feature**: corregir `docs/documentacion_principal_crm.md` §6.3 para que
  refleje el criterio sin piso, y anotar ahí mismo que el Ranking de Clientes/Productos del
  Dashboard queda neteado (hoy dice explícitamente lo contrario en §7, pendientes). Se hace antes
  de `/speckit-tasks`, no después. ✅ Cumple (con acción pendiente registrada).
- **Principio II (Spec-driven)**: esta feature pasa por el flujo completo specify→clarify→plan→
  checklist→tasks→analyze. ✅ Cumple.
- **Principio III (Corrección fiscal/ARCA)**: no aplica — no toca comprobantes, CAE ni emisión
  fiscal. Las NC/ND ya existentes se leen tal cual están, no se modifican. N/A.
- **Principio IV (Testing donde hay dinero)**: el Ranking de Clientes es un monto (dinero) →
  requiere test. El Ranking de Productos es una cantidad de stock vendido, no dinero directo, pero
  se testea igual porque comparte la misma lógica de imputación de período que si tiene bug afecta
  también el cálculo de rotación de stock que el negocio usa para decisiones de compra. ✅ Cumple,
  ver Fase 1 / tasks.
- **Principio V (Convenciones Laravel + español)**: se extiende un método privado ya existente del
  controlador siguiendo su mismo estilo (raw SQL con placeholders, nombres de tabla/columna en
  español). ✅ Cumple.

**Resultado**: PASS, con una acción documental obligatoria (corregir §6.3 y §7 de
`docs/documentacion_principal_crm.md`) antes de `/speckit-tasks`.

## Project Structure

### Documentation (this feature)

```text
specs/079-neteo-nc-nd-rankings-dashboard/
├── plan.md              # This file
├── research.md          # Phase 0 output
├── data-model.md        # Phase 1 output
├── quickstart.md        # Phase 1 output
└── tasks.md             # Phase 2 output (/speckit-tasks)
```

No se genera `contracts/`: el endpoint de rankings ya existe (`GET` interno del Dashboard,
consumido por AJAX del propio `dashboard.blade.php`) y esta feature no cambia su forma de
entrada/salida (mismos parámetros de período, misma forma de respuesta JSON: `[{id, nombre, monto}]`
/ `[{id, nombre, cantidad}]`) — sólo cambia el valor calculado. `data-model.md` documenta el
contrato de datos igual, sin necesitar un archivo de contracts aparte.

### Source Code (repository root)

```text
app/
├── Http/Controllers/
│   └── DashboardController.php   # método rankings() (líneas 236-281) y montoNetoQuery() (376-430)
│                                   # se agregan dos métodos privados nuevos análogos:
│                                   # montoNetoPorClienteQuery() y cantidadNetaPorProductoQuery()
├── Models/
│   ├── NotaCreditoDebito.php      # sin cambios (venta_id, tipo, fecha_emision, monto)
│   └── NotaCreditoDebitoItem.php  # sin cambios (producto_id, cantidad) — ya tiene lo necesario

tests/Feature/
└── DashboardRankingsNeteoTest.php  # nuevo — casos de la spec (mismo período, período cruzado,
                                      # negativo sin piso, ND sin techo, notas sin ítems)

docs/
└── documentacion_principal_crm.md  # §6.3 corregido (sin piso) + §7 actualizado (deuda saldada)
```

**Structure Decision**: cambio quirúrgico dentro de `DashboardController.php`, sin nuevas capas
(no amerita un Service dedicado: el patrón ya vive como método privado del controlador y esta
feature es una extensión directa de ese mismo patrón, no una responsabilidad nueva). Sin cambios de
modelo/migración: los campos necesarios (`nota_credito_debito_items.producto_id`, `.cantidad`,
`notas_credito_debito.tipo`, `.fecha_emision`) ya existen.

## Complexity Tracking

*Sin violaciones — no aplica.*
