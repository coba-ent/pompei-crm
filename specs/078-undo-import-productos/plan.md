# Implementation Plan: Deshacer Import de Productos

**Branch**: `078-undo-import-productos` | **Date**: 2026-08-24 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/078-undo-import-productos/spec.md`

## Summary

Agregar snapshot-y-undo a la solapa Productos & Servicios del asistente de Importar Datos
(`ImportadorFilas`, spec 006/026/027/074). Antes de procesar el Paso 3 se registra una
`import_run` (corrida) y, por cada fila que va a crearse o actualizarse, un `import_row_snapshot`
con el estado previo del producto (o "no existía"). Una acción "Deshacer import", disponible
48 horas desde la confirmación, revierte fila por fila: las altas se soft-deletean (`activo =
false`) salvo que el producto ya tenga operaciones posteriores; las actualizaciones restauran
los campos pisados usando `StockService::fijar()` para el stock (mismo mecanismo bajo lock que ya
usa el import, spec 074) y quedan auditadas igual que un cambio de precio manual. El undo es
parcial: lo que no se puede revertir se reporta con motivo, sin abortar el resto.

## Technical Context

**Language/Version**: PHP 8.2, Laravel 12

**Primary Dependencies**: Eloquent ORM, `maatwebsite/excel` (ya usado por el importador),
`StockService` (`app/Services/Stock/StockService.php`), `AuditoriaService`
(`app/Services/AuditoriaService.php`), DataTables + Select2 + Toastr (stack estándar del proyecto)

**Storage**: MySQL (misma DB `contagram`) — 2 tablas nuevas: `import_runs`, `import_row_snapshots`

**Testing**: PHPUnit (Feature tests), siguiendo el patrón ya usado para `ImportadorFilas`
(Principio IV de la constitución: testing obligatorio donde hay dinero/stock en juego)

**Target Platform**: Web (Laravel Blade + NexaDash), mismo entorno que el resto del CRM

**Project Type**: Web application monolítica (Laravel), single-tenant

**Performance Goals**: soportar corridas de miles de filas (el import ya procesa en tandas de
1.000 filas, research.md de spec 006) sin que el snapshot degrade el tiempo total de forma
perceptible — el snapshot se escribe en el mismo INSERT múltiple por tanda, no fila por fila

**Constraints**: el undo de stock NO puede pisar ventas/compras/ajustes ocurridos después del
import (mismo problema de concurrencia que motivó `StockService::fijar()` en spec 074); el undo
debe ser parcial (no todo-o-nada) porque el negocio sigue operando en vivo entre el import y el
undo

**Scale/Scope**: sólo solapa Productos & Servicios; catálogo real de referencia ~9.200 productos
(spec 074); ventana de undo 48h

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **I. Documentación de dominio como fuente de verdad**: spec basada en §2.4 de
  `docs/documentacion_principal_crm.md` y en el modelo de `productos`/`stocks`/`movimientos_stock`/
  `logs_auditoria` de `docs/modelo_datos.md`. Ambos documentos se actualizan antes de `/speckit-tasks`
  (regla obligatoria del proyecto) con las 2 tablas nuevas y la extensión de §2.4. **PASS**.
- **II. Desarrollo spec-driven**: se sigue la cadena completa specify→clarify→plan→checklist→
  tasks→analyze sin saltar pasos. **PASS**.
- **III. Corrección fiscal (ARCA)**: no aplica — Productos no genera comprobantes fiscales
  directamente; el undo no toca ARCA/WSFEv1. **N/A**.
- **IV. Testing donde hay dinero o impacto fiscal**: el undo toca precios de venta y stock en
  vivo → requiere tests de Feature que cubran: undo completo, undo parcial (fila bloqueada por
  operación posterior), concurrencia de stock (venta entre import y undo), y no-doble-undo.
  **Gate obligatorio, cubierto en Phase 1 (quickstart.md) y en tasks.md**.
- **V. Convenciones Laravel + dominio en español**: nombres de tabla/columnas en español
  siguiendo el patrón ya vigente en `docs/modelo_datos.md` (`import_runs`/`import_row_snapshots` en
  inglés técnico es la única excepción a evaluar — se decide en Phase 0 research por consistencia
  con el resto del esquema, que es mayormente en español). **A resolver en research.md**.

No hay violaciones que requieran `Complexity Tracking`.

## Project Structure

### Documentation (this feature)

```text
specs/078-undo-import-productos/
├── plan.md              # This file
├── research.md          # Phase 0 output
├── data-model.md         # Phase 1 output
├── quickstart.md        # Phase 1 output
├── contracts/           # Phase 1 output
└── tasks.md             # Phase 2 output (/speckit-tasks)
```

### Source Code (repository root)

```text
app/
├── Models/
│   ├── ImportacionCorrida.php          # nuevo — tabla import_runs
│   ├── ImportacionFilaSnapshot.php     # nuevo — tabla import_row_snapshots
│   └── Producto.php                    # existente — se agrega tieneOperaciones() si no existe con este alcance (research.md R5)
├── Services/
│   └── Import/
│       ├── ImportadorFilas.php         # existente — se instrumenta para registrar corrida + snapshots
│       └── DeshacerImportacionService.php  # nuevo — orquesta el undo (altas/actualizaciones, parcial, auditoría)
├── Support/
│   └── OrigenCambioPrecio.php          # existente — se agrega el caso DESHACER_IMPORT (mismo mecanismo que IMPORTACION, spec 074)
├── Http/
│   └── Controllers/
│       └── ImportacionController.php   # existente — nuevas acciones: historial, historialDatos, deshacer
└── Console/ (sin cambios)

database/migrations/
├── xxxx_create_import_runs_table.php
└── xxxx_create_import_row_snapshots_table.php

resources/views/importacion/
├── historial.blade.php   # nueva pantalla — listado de corridas (DataTables + AJAX)
└── resumen.blade.php     # existente — se agrega botón "Deshacer" cuando corresponde

routes/web.php            # nuevas rutas: importacion.historial, importacion.deshacer

tests/Feature/
└── DeshacerImportacionProductosTest.php  # nuevo
```

**Structure Decision**: Se reutiliza la estructura Laravel monolítica ya vigente (Controllers +
Services + Models + Blade views), sin nuevos módulos ni separación en subproyectos — coherente con
el resto del CRM. El undo vive como servicio dedicado (`DeshacerImportacionService`) en vez de
métodos sueltos en `ImportacionController`, siguiendo el mismo patrón que `StockService`/
`AuditoriaService` (lógica de negocio en `app/Services/`, controller delgado).

## Complexity Tracking

*Sin violaciones a justificar.*
