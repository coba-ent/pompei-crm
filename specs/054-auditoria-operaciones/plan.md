# Implementation Plan: Módulo de Auditoría (Log de Operaciones)

**Branch**: `054-auditoria-operaciones` | **Date**: 2026-08-07 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/054-auditoria-operaciones/spec.md`

## Summary

Log transversal de solo lectura que registra automáticamente quién creó/modificó/eliminó/anuló
cada operación transaccional del CRM (Venta, Presupuesto, Cobro, Gasto, Compra, Movimiento de
Tesorería, Movimiento de Stock), incluyendo acciones de origen automático (integraciones ML/TN).
Se implementa como una tabla `logs_auditoria` poblada vía Eloquent Observers sobre los modelos ya
existentes, y una pantalla nueva (`/configuracion/auditoria` o similar, accesible desde el menú de
usuario) con DataTable server-side + filtros (Id, Operación, Usuario, fecha) + exportación,
replicando la estructura observada en Contagram real (pantalla "Operaciones").

## Technical Context

**Language/Version**: PHP 8.2, Laravel 12

**Primary Dependencies**: Eloquent ORM, yajra/laravel-datatables (ya usado en el resto del CRM para
listados server-side — ver módulos Ventas/Gastos/Clientes), maatwebsite/excel (exportación, ya usado
en otros listados del CRM), Select2 (filtro de Usuario/Operación), Bootstrap 5 modales (no aplica:
esta pantalla es de solo lectura, no tiene alta/edición/eliminación vía modal)

**Storage**: MySQL (tabla nueva `logs_auditoria`)

**Testing**: PHPUnit/Pest (feature tests de Laravel) — foco en que los Observers generan el evento
correcto para cada entidad y en que los filtros del listado funcionan

**Target Platform**: Servidor Linux (mismo hosting compartido/VPS que el resto del CRM)

**Project Type**: Aplicación web monolítica Laravel (Blade + Vite/Tailwind), single-tenant

**Performance Goals**: Listado responde en <2s con miles de registros acumulados (SC-003) —
requiere índices en `logs_auditoria` sobre `created_at`, `usuario_id`, `tipo_operacion`

**Constraints**: Registro síncrono (el evento de auditoría debe quedar persistido junto con la
operación que lo origina, dentro de la misma transacción cuando aplique); no debe degradar
perceptiblemente el guardado de Ventas/Gastos/Cobros/etc. al agregar el observer. Si la escritura en
`logs_auditoria` falla, la escritura de la operación de negocio (Venta/Gasto/etc.) NO debe abortarse
por eso — se registra el error de auditoría en el log técnico de la aplicación (`storage/logs`) y se
continúa, priorizando no bloquear operaciones críticas de negocio por un fallo del log secundario
(criterio: la Auditoría documenta, no gatea, las operaciones).

**Scale/Scope**: Volumen esperado: decenas de operaciones por día en la cuenta real (ver ejemplo con
Id ~26000 en captura real) → miles/decenas de miles de filas acumuladas en el tiempo; sin borrado
automático por antigüedad (retención indefinida, a diferencia de los logs técnicos `ml_operaciones_log`)

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **I. Documentación de dominio como fuente de verdad**: cumple — `docs/documentacion_principal_crm.md`
  §7 ya documenta la estructura relevada de la pantalla antes de esta spec; este plan no introduce
  reglas de negocio nuevas fuera de lo ya documentado. Se actualizará `docs/modelo_datos.md` con la
  tabla `logs_auditoria` antes de `/speckit-tasks` (requisito de la constitución, no opcional).
- **II. Desarrollo spec-driven**: cumple — se está siguiendo el flujo completo specify→clarify→plan→
  checklist→tasks→analyze.
- **III. Corrección fiscal innegociable (ARCA)**: no aplica directamente — la Auditoría no emite
  comprobantes ni calcula CAE. Sí debe registrar eventos sobre entidades fiscales (Venta, Gasto,
  Compra) sin interferir con su lógica de emisión/soft-delete existente (el observer es aditivo, no
  reemplaza el soft delete real de esas entidades).
- **IV. Testing donde hay dinero o impacto fiscal**: la Auditoría en sí no calcula montos, pero
  referencia `total` de operaciones con impacto fiscal/monetario — se testea que el monto registrado
  coincide con el de la operación de origen al momento del evento (no un total recalculado después).
- **V. Convenciones Laravel + dominio en español**: cumple — tabla `logs_auditoria`, columnas en
  español, Observers de Laravel (patrón ya usado en el proyecto), sin `empresa_id` (single-tenant).

Sin violaciones. No aplica Complexity Tracking.

## Project Structure

### Documentation (this feature)

```text
specs/054-auditoria-operaciones/
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
├── Models/
│   └── LogAuditoria.php
├── Observers/
│   ├── VentaAuditoriaObserver.php
│   ├── PresupuestoAuditoriaObserver.php
│   ├── CobroAuditoriaObserver.php
│   ├── GastoAuditoriaObserver.php
│   ├── CompraAuditoriaObserver.php
│   ├── MovimientoTesoreriaAuditoriaObserver.php
│   └── MovimientoStockAuditoriaObserver.php
├── Services/
│   └── AuditoriaService.php        # registrarEvento() reusado por todos los observers
├── Http/Controllers/
│   └── AuditoriaController.php     # index (vista), datatable (AJAX), exportar
└── Providers/
    └── AppServiceProvider.php      # registro de los observers (Model::observe())

database/migrations/
└── [timestamp]_create_logs_auditoria_table.php

resources/views/
└── auditoria/
    └── index.blade.php

resources/js/
└── auditoria.js                    # init DataTable + filtros + Select2

routes/
└── web.php                         # rutas /auditoria (index, datatable, exportar)

tests/Feature/
└── AuditoriaTest.php
```

**Structure Decision**: Laravel monolito estándar ya usado en todo el proyecto (Option 1 de single
project, adaptado a la convención MVC de Laravel). No hay frontend separado: la pantalla es Blade +
DataTables AJAX, siguiendo la especificación de diseño obligatoria del CLAUDE.md del proyecto
(tablas con DataTables server-side, sin alta/edición porque el módulo es de solo lectura, toasts para
mensajes de error de exportación).

## Complexity Tracking

*Sin violaciones de la Constitution Check — sección no aplica.*
