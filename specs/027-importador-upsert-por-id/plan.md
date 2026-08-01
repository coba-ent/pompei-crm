# Implementation Plan: Importador de Datos — Actualizar por Id (Upsert)

**Branch**: `027-importador-upsert-por-id` | **Date**: 2026-07-31 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/027-importador-upsert-por-id/spec.md`

## Summary

Agregar al asistente "Importar Datos" (spec 006/026) la capacidad de actualizar registros existentes de
Clientes/Proveedores/Productos identificados por su `id`, en vez de crear siempre uno nuevo. El paso de mapeo
ofrece un campo destino nuevo "Id" en las 3 entidades; si se mapea y la celda de una fila coincide con un id
existente, esa fila actualiza parcialmente ese registro (sólo los campos mapeados con valor no vacío); si no
coincide, la fila falla con motivo claro; si la celda está vacía, la fila sigue siendo alta nueva (sin cambios).

## Technical Context

**Language/Version**: PHP 8.2, Laravel 12

**Primary Dependencies**: Eloquent, `Illuminate\Validation` — sin dependencias nuevas

**Storage**: MySQL — sin migraciones (el `id` ya existe como primary key de `clientes`/`proveedores`/`productos`)

**Testing**: PHPUnit / `php artisan test` (se extiende `tests/Feature/ImportacionDatosTest.php`)

**Target Platform**: Web app Laravel (servidor), sin cambios de plataforma

**Project Type**: Web application (single Laravel app, Blade + Vite) — sin frontend/backend separados

**Performance Goals**: N/A — mismo procesamiento síncrono por fila de spec 006. La única diferencia es que una
fila de actualización dispara un `SELECT` por id (`findOrFail`) más las reglas dinámicas de esa fila (unicidad con
`ignore($id)`) en vez de reutilizar las reglas precomputadas de alta — el resto de las filas (alta, la mayoría en
un archivo típico) no paga ese costo.

**Constraints**: no modificar el esquema de `clientes`/`proveedores`/`productos`; no modificar las reglas de
validación del alta/edición manual (`ReglasCliente`/`ReglasProveedor`/`ReglasProducto`, compartidas con los
formularios reales) — la relajación de "obligatorio" en filas de actualización se resuelve en la capa del
importador (`Reglas*Importacion`), no en esas reglas compartidas.

**Scale/Scope**: 3 solapas existentes; 1 campo destino nuevo ("Id") por entidad; 1 capacidad nueva de
procesamiento por fila (resolver alta vs actualización según el campo Id).

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **I. Documentación de dominio como fuente de verdad**: `docs/documentacion_principal_crm.md §2.4` ya documenta
  "Importar Datos" (ampliado en spec 026). Esta feature agrega comportamiento nuevo (actualización por Id) que
  hay que documentar ahí antes de `/speckit-tasks`. ✅ Pasa, con esa actualización pendiente.
- **II. Desarrollo spec-driven**: esta feature sigue el flujo completo. ✅ Pasa.
- **III. Corrección fiscal innegociable (ARCA)**: no aplica — no toca facturación ni CAE. ✅ N/A.
- **IV. Testing donde hay dinero o impacto fiscal**: una actualización por Id puede tocar `saldo_inicial`
  (dinero, Cliente/Proveedor) y campos fiscales — requiere tests que confirmen que la actualización parcial no
  pisa ni resetea esos valores quedando en `null`/0 quando no están mapeados. ✅ Pasa, con tests obligatorios para
  ese caso.
- **V. Convenciones Laravel + dominio en español**: el campo nuevo usa el nombre real de columna `id` (ya
  existente, sin inventar nombres). ✅ Pasa.

Sin violaciones — no se llena Complexity Tracking.

## Project Structure

### Documentation (this feature)

```text
specs/027-importador-upsert-por-id/
├── plan.md              # This file
├── research.md          # Phase 0 output
├── data-model.md         # Phase 1 output
├── quickstart.md        # Phase 1 output
└── tasks.md             # Phase 2 output (/speckit-tasks)
```

No se genera carpeta `contracts/` nueva: no agrega ni cambia rutas — reutiliza las de spec 006.

### Source Code (repository root)

```text
app/
├── Services/Import/
│   ├── DefinicionCamposImportables.php   # se amplía: campo "Id" nuevo por entidad (marca 'id' => true)
│   └── ImportadorFilas.php               # se amplía: resolver alta vs actualización por fila, reglas
│                                          # dinámicas de actualización, update parcial
└── Http/Requests/Import/
    ├── ReglasClienteImportacion.php      # se agrega reglasActualizacion(?int $id): array
    ├── ReglasProveedorImportacion.php    # idem
    └── ReglasProductoImportacion.php     # idem — SkuUnico/CuitValido ya soportan excluir el propio id

tests/Feature/
└── ImportacionDatosTest.php              # se agregan casos: actualización parcial por Id (las 3 entidades),
                                           # Id no encontrado (fila fallida), Id no numérico (fila fallida),
                                           # Id vacío = alta nueva (sin cambios), unicidad no bloquea el propio
                                           # registro al reenviar el mismo valor
```

**Structure Decision**: Single Laravel app (ya existente). Esta feature toca únicamente la capa de Servicios de
importación (`app/Services/Import/`) y sus adaptadores de reglas (`app/Http/Requests/Import/`) — no agrega
controladores, rutas ni vistas nuevas; el select de mapeo sigue poblándose dinámicamente desde
`DefinicionCamposImportables` sin tocar `resources/views/importacion/mapear.blade.php`.

## Complexity Tracking

N/A — no hay violaciones de la constitución que justificar.
