# Implementation Plan: Hora en Movimientos de Stock y Detalle de Venta en Informe de Stock

**Branch**: `051-hora-detalle-venta-informe-stock` | **Date**: 2026-08-06 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `/specs/051-hora-detalle-venta-informe-stock/spec.md`

## Summary

Los movimientos de stock (`movimientos_stock`) hoy sólo guardan `fecha` (DATE, sin hora), y el
Informe de Stock ordena y calcula el saldo corrido sólo por esa fecha + id. Se pasa la columna
`fecha` a DATETIME para que capture el momento real del movimiento (venta, compra, ajuste,
transferencia), sin tocar la lógica de saldo corrido más que el tipo de columna que ya usa como
criterio de orden. Además, la columna "Detalle" del Informe de Stock se enriquece: cuando el
movimiento tiene `origen_type = App\Models\Venta`, se arma un texto con tipo+número de
comprobante y cliente de esa venta (vía join adicional en `InformeStockController::baseQuery()`),
sin alterar el comportamiento para compras/ajustes/transferencias.

## Technical Context

**Language/Version**: PHP 8.2 (Laravel 12)

**Primary Dependencies**: Eloquent ORM, Yajra DataTables (server-side), DataTables.js (frontend)

**Storage**: MySQL (tabla `movimientos_stock`, join de sólo lectura contra `ventas` y `clientes`)

**Testing**: PHPUnit (Feature tests, patrón ya usado en `tests/Feature/InformeStockTest.php`, `tests/Feature/Integraciones/MovimientoStockObserverTest.php`)

**Target Platform**: Servidor web Laravel (demo Hostinger + VPS), navegador para el Informe de Stock

**Project Type**: Web application (Laravel monolito, Blade + DataTables server-side)

**Performance Goals**: Sin degradación perceptible respecto al Informe de Stock actual (el join adicional es sobre `ventas`/`clientes` por PK, con volumen acotado al de movimientos filtrados)

**Constraints**: No romper el saldo corrido (`stock_saldo`) ya calculado con función de ventana SQL; no romper movimientos existentes sin hora real

**Scale/Scope**: Alcance acotado a `movimientos_stock` + `InformeStockController` + su vista/JS; no toca otras pantallas de Ventas/Compras salvo el punto donde generan el movimiento (fecha/hora)

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **I. Documentación de dominio como fuente de verdad**: `docs/documentacion_principal_crm.md` y
  `docs/modelo_datos.md` documentan hoy `movimientos_stock.fecha` como DATE y no describen una
  columna "Detalle" enriquecida con datos de venta. Este plan actualiza ambos documentos antes de
  `/speckit-tasks` (ver Fase 1). **PASA** (con la actualización pendiente incluida como tarea).
- **II. Desarrollo spec-driven**: se está siguiendo la cadena completa specify→clarify→plan.
  **PASA**.
- **III. Corrección fiscal innegociable (ARCA)**: el cambio no toca emisión de comprobantes, CAE
  ni numeración; sólo lectura de `tipo_comprobante`/`nro_comprobante` ya emitidos para mostrarlos
  en una columna de sólo lectura. **PASA** (no aplica riesgo fiscal nuevo).
- **IV. Testing donde hay dinero o impacto fiscal**: los movimientos de stock están explícitamente
  incluidos como área que requiere test. Se agregan/ajustan tests de: (a) orden por fecha+hora y
  saldo corrido, (b) contenido de la columna Detalle para origen Venta y para otros orígenes.
  **PASA** (tests planificados en Fase 1 / tasks).
- **V. Convenciones Laravel + dominio en español**: nombres de columnas y relaciones en español,
  se reutiliza el patrón de `baseQuery()` con joins explícitos ya existente. **PASA**.

No hay violaciones que requieran justificar en Complexity Tracking.

## Project Structure

### Documentation (this feature)

```text
specs/051-hora-detalle-venta-informe-stock/
├── plan.md              # This file (/speckit-plan command output)
├── research.md          # Phase 0 output (/speckit-plan command)
├── data-model.md        # Phase 1 output (/speckit-plan command)
├── quickstart.md        # Phase 1 output (/speckit-plan command)
├── contracts/
│   └── informe-stock-data.md   # Contrato de la respuesta JSON de InformeStockController::data()
└── tasks.md             # Phase 2 output (/speckit-tasks command - NOT created by /speckit-plan)
```

### Source Code (repository root)

```text
database/migrations/
└── 2026_08_06_000001_add_hora_a_movimientos_stock.php   # ALTER fecha DATE -> DATETIME (nuevo)

app/Models/
└── MovimientoStock.php               # cast 'fecha' => 'datetime'

app/Services/Stock/
└── StockService.php                  # registrar fecha+hora real (now()) al crear el movimiento

app/Http/Controllers/Informes/
└── InformeStockController.php        # baseQuery(): join a ventas/clientes cuando origen_type=Venta; columna 'detalle'

resources/js/
└── informe-stock.js                  # columna Detalle usa 'detalle' (fallback a 'descripcion'); formato de fecha ahora incluye hora

resources/views/informes/stock/
└── index.blade.php                   # encabezado de columna "Fecha y Hora" si corresponde

tests/Feature/
└── InformeStockTest.php              # casos: orden por fecha+hora, detalle de venta, detalle de otros orígenes

docs/
├── documentacion_principal_crm.md    # actualizar sección de Informe de Stock (columna Detalle enriquecida)
└── modelo_datos.md                   # actualizar movimientos_stock.fecha: DATE -> DATETIME
```

**Structure Decision**: Laravel monolito existente (Opción "Single project" del template, sin
frontend/backend separados). Todos los cambios caen dentro de las carpetas estándar de Laravel ya
usadas por el resto del proyecto (`app/Models`, `app/Services`, `app/Http/Controllers`,
`resources/js`, `resources/views`, `database/migrations`, `tests/Feature`); no se introduce
ninguna carpeta ni capa nueva.

## Complexity Tracking

*Sin violaciones a justificar — tabla omitida.*
