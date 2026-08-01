# Implementation Plan: Importador de Datos — Campos Completos

**Branch**: `026-importador-datos-campos-completos` | **Date**: 2026-07-31 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/026-importador-datos-campos-completos/spec.md`

## Summary

Ampliar el diccionario de campos destino del asistente "Importar Datos" (spec 006) para que Clientes,
Proveedores y Productos & Servicios ofrezcan, en el paso de mapeo, todos los campos que ya existen en sus
modelos (`Cliente`, `Proveedor`, `Producto`) y que hoy quedan sin destino disponible — sin agregar ninguna
columna nueva a esas tablas ni tocar el mecanismo compartido (subir/preview/confirmar/cancelar/resumen).
Se agregan dos capacidades nuevas de parseo por fila en `ImportadorFilas`: interpretar fechas (fecha nativa
Excel, `DD/MM/YYYY`, `YYYY-MM-DD`) y booleanos (`Si/No`, `1/0`, `true/false`).

## Technical Context

**Language/Version**: PHP 8.2, Laravel 12

**Primary Dependencies**: `maatwebsite/excel` (ya instalado, sin cambios de versión), Eloquent, `Illuminate\Validation`

**Storage**: MySQL — sin migraciones (todos los campos ya existen en `clientes`, `proveedores`, `productos`)

**Testing**: PHPUnit / `php artisan test` (Feature tests sobre `tests/Feature/ImportacionDatosTest.php` ya existente, se extiende)

**Target Platform**: Web app Laravel (servidor), sin cambios de plataforma

**Project Type**: Web application (single Laravel app, Blade + Vite) — sin frontend/backend separados

**Performance Goals**: N/A — mismo procesamiento síncrono por fila ya vigente en spec 006 (miles de filas, `set_time_limit(0)`); esta feature no cambia el modelo de performance

**Constraints**: no modificar el esquema de `clientes`/`proveedores`/`productos`; no modificar rutas ni la estructura de pantalla del asistente (subir → mapear → confirmar → resumen, con páginas reales entre pasos, excepción ya documentada en spec 006)

**Scale/Scope**: 3 solapas existentes; 19 campos de modelo distintos quedan expuestos como destino de mapeo (16 en Clientes, de los cuales 12 se comparten con Proveedores, más 3 booleanos en Productos), más 2 capacidades de parseo por fila (fecha, booleano) reutilizadas entre bloques

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **I. Documentación de dominio como fuente de verdad**: `docs/documentacion_principal_crm.md` ya
  documenta "Importar Datos" como sección activa (agregada en spec 006, tarea T030). Esta feature no
  introduce reglas de negocio nuevas (los campos ya existen y ya están documentados en el modelo de
  datos) — sólo hay que agregar una nota breve en `docs/documentacion_principal_crm.md §2.6` listando
  los campos ampliados, y una nota en `docs/documentacion_principal_crm.md §5` documentando "Punto
  Reposición" como brecha pendiente (ya decidido con el usuario). ✅ Pasa, con esa actualización pendiente
  antes de `/speckit-tasks`.
- **II. Desarrollo spec-driven**: esta feature es justamente el resultado de seguir ese flujo. ✅ Pasa.
- **III. Corrección fiscal innegociable (ARCA)**: no aplica — no toca facturación ni CAE. ✅ N/A.
- **IV. Testing donde hay dinero o impacto fiscal**: `saldo_inicial`/`saldo_inicial_fecha` son datos de
  cuenta corriente (dinero) — requieren test. Los campos fiscales (razón social, domicilio fiscal, tipo
  de documento) no calculan importes pero alimentan facturación futura — se cubren con tests de
  importación estándar (mismo nivel que CUIT hoy), no tests de cálculo. ✅ Pasa, con tests obligatorios
  para saldo inicial + fecha, y para los 3 booleanos de Producto (afectan visibilidad en Ventas/Compras).
- **V. Convenciones Laravel + dominio en español**: los nuevos campos destino reutilizan snake_case y
  nombres ya existentes en los modelos (no se inventan nombres nuevos). ✅ Pasa.

Sin violaciones — no se llena Complexity Tracking.

## Project Structure

### Documentation (this feature)

```text
specs/026-importador-datos-campos-completos/
├── plan.md              # This file
├── research.md          # Phase 0 output
├── data-model.md        # Phase 1 output
├── quickstart.md        # Phase 1 output
└── tasks.md             # Phase 2 output (/speckit-tasks)
```

No se genera carpeta `contracts/` nueva: esta feature no agrega ni cambia rutas — reutiliza tal cual las
ya documentadas en `specs/006-importar-datos-excel/contracts/importacion-rutas.md`.

### Source Code (repository root)

```text
app/
├── Services/Import/
│   ├── DefinicionCamposImportables.php   # se amplía: nuevos campos por entidad + helpers de parseo
│   └── ImportadorFilas.php               # se amplía: parseo de fecha y de booleano por campo
├── Http/Requests/Import/
│   ├── ReglasClienteImportacion.php      # sin cambios de reglas nuevas (los campos ya validan igual que el alta manual)
│   ├── ReglasProveedorImportacion.php    # idem
│   └── ReglasProductoImportacion.php     # idem — activo/mostrar_en_ventas/mostrar_en_compras ya son boolean en la regla de alta manual, se reutiliza
└── Http/Controllers/
    └── ImportacionController.php         # sin cambios (el mecanismo de mapeo/confirmación no cambia)

tests/Feature/
└── ImportacionDatosTest.php              # se agregan casos: fiscal/saldo/ML/lista de precios en Clientes,
                                           # fiscal/saldo en Proveedores, booleanos en Productos, fechas y
                                           # booleanos inválidos como fila fallida
```

**Structure Decision**: Single Laravel app (ya existente). Esta feature toca únicamente la capa de
Servicios de importación (`app/Services/Import/`) y su test de Feature — no agrega controladores, rutas,
vistas ni migraciones nuevas. El paso de mapeo (`resources/views/importacion/mapear.blade.php`) no
necesita cambios porque ya itera dinámicamente el diccionario de `DefinicionCamposImportables` para
poblar los selects — más campos en el diccionario significa más opciones en el select sin tocar la vista.

## Complexity Tracking

N/A — no hay violaciones de la constitución que justificar.
