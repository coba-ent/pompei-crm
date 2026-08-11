# Implementation Plan: Filtros del listado de Compras

**Branch**: `056-filtros-compras` | **Date**: 2026-08-11 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/056-filtros-compras/spec.md`

## Summary

Reemplazar el panel de filtros del listado de Compras (hoy: solo "N° de Comprobante" y "Proveedor" simple) por el set completo de 12 filtros + 2 rangos de fecha independientes (Emisión/Vencimiento) + selector de columnas visibles, replicando exactamente el patrón ya implementado y probado en Ventas (`VentaController::queryFiltrada()`, `ventas/index.blade.php`, `resources/js/ventas.js`). Requiere: (1) hacer Proveedor multi-selección con `whereIn` en backend, (2) agregar filtros nuevos server-side reutilizando el mismo criterio AND-entre-campos/OR-dentro-de-campo ya usado en Ventas, (3) agregar la relación `etiquetas()` (MorphToMany, tabla pivote `etiquetables` ya genérica) al modelo `Compra`, (4) agregar columna `creado_por_id` a `compras` (nullable, sin backfill) y setearla en `store()`, y (5) corregir `docs/documentacion_principal_crm.md` / `docs/informe_contagram_egresos.md` para que reflejen los 12 filtros reales en vez del subconjunto de 7 documentado hoy.

## Technical Context

**Language/Version**: PHP 8.2, Laravel 12

**Primary Dependencies**: Eloquent ORM, Yajra DataTables (server-side AJAX), Select2 (jQuery), daterangepicker (jQuery), Bootstrap 5 (NexaDash)

**Storage**: MySQL — tabla `compras` (agrega columna `creado_por_id`), tabla pivote genérica `etiquetables` (ya existe, sin cambios de esquema)

**Testing**: PHPUnit/Pest (Laravel), feature tests sobre `CompraController::data()` filtrando por cada campo nuevo (patrón ya usado para Ventas)

**Target Platform**: Aplicación web server-rendered (Blade) con interacciones AJAX, navegador de escritorio

**Project Type**: Web application (monolito Laravel, sin frontend separado)

**Performance Goals**: Sin requisito nuevo de performance — mismos volúmenes y patrón de consulta ya validado en Ventas (índices existentes en FKs; los filtros nuevos usan `whereIn`/`whereHas`/`whereDate` sobre columnas ya indexadas o de cardinalidad baja)

**Constraints**: No romper el comportamiento actual del filtro de Proveedor (URLs/bookmarks que hoy pasan `proveedor_id` como escalar deben seguir funcionando, ya que el patrón `(array) $request->input(...)` de Ventas tolera tanto escalar como array)

**Scale/Scope**: 1 pantalla (listado de Compras), 1 controlador, 1 modelo, 1 vista, 1 archivo JS, 1 migración, actualización de 2 documentos de dominio

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **Principio I (Documentación de dominio como fuente de verdad)**: La spec detectó que `docs/documentacion_principal_crm.md` y `docs/informe_contagram_egresos.md` documentan solo 7 de los 12 filtros reales, y que `docs/modelo_datos.md` afirma "Compras no usa etiquetas" — contradicho por la captura real (que muestra "Etiqueta" como filtro) y por el propio informe (que ya lista "Etiquetas" como columna del listado, §2.1). **Gate: PASA condicionado** a que la Fase 1 incluya explícitamente la actualización de los 3 documentos antes de considerar la feature lista para tasks (FR-015). Se resuelve a favor de la captura real, según el principio rector de CLAUDE.md.
- **Principio II (Desarrollo spec-driven)**: Cumple — esta es una spec de spec-kit, con clarify ya corrido, plan en curso, y checklist/tasks/analyze pendientes en la misma cadena.
- **Principio III (Corrección fiscal ARCA)**: No aplica — no se toca emisión de comprobantes, CAE, ni numeración fiscal. El filtro "Facturado" solo **lee** el estado ya existente de `comprobanteFiscal`, no lo modifica.
- **Principio IV (Testing donde hay dinero o impacto fiscal)**: Los filtros no calculan importes ni afectan stock/tesorería — son lectura pura sobre datos ya persistidos. Se los trata como "CRUD simple/vistas" (testing a criterio, no estricto), pero se agregan tests de filtrado por ser lógica de consulta con muchas ramas (mismo criterio que ya tienen los tests de filtros de Ventas, si existen) para evitar regresiones silenciosas.
- **Principio V (Convenciones Laravel + dominio en español)**: Cumple — nombres de columnas/relaciones en español (`creado_por_id`, `etiquetas()`), reutiliza exactamente las convenciones ya usadas en `Venta`.

**Resultado**: PASA. Sin violaciones que requieran justificación en Complexity Tracking.

## Project Structure

### Documentation (this feature)

```text
specs/056-filtros-compras/
├── plan.md              # This file (/speckit-plan command output)
├── research.md          # Phase 0 output
├── data-model.md         # Phase 1 output
├── quickstart.md         # Phase 1 output
├── contracts/
│   └── filtros-compras.md   # Contrato de query params del endpoint compras.data
└── tasks.md              # Phase 2 output (/speckit-tasks — no generado por este comando)
```

### Source Code (repository root)

```text
app/
├── Http/Controllers/
│   └── CompraController.php        # queryFiltrada() + index() (pasa catálogos a la vista) + store() (setea creado_por_id)
├── Models/
│   └── Compra.php                  # + relación etiquetas(): MorphToMany, + creado_por_id en fillable/relación creadoPor()

database/migrations/
└── {timestamp}_add_creado_por_id_a_compras_table.php   # nueva columna nullable, sin backfill

resources/
├── views/compras/
│   └── index.blade.php             # panel de filtros completo + 2 rangos de fecha + selector de columnas
└── js/
    └── compras.js                  # Select2 multi para filtros de catálogo, daterangepicker x2, colvis, wiring de queryFiltrada → params AJAX

docs/
├── documentacion_principal_crm.md  # sección Compras/Egresos: reemplazar los 7 filtros documentados por los 12 reales
├── modelo_datos.md                 # corregir nota "Compras no usa etiquetas"; documentar creado_por_id
└── informe_contagram_egresos.md    # §2.2: reemplazar la lista de 7 filtros por los 12 reales de la captura

tests/Feature/Compras/
└── FiltrosCompraTest.php           # (nuevo) un caso por filtro + combinación AND + casos de exclusión de fechas nulas
```

**Structure Decision**: Monolito Laravel existente — no se crean módulos/paquetes nuevos. Todo el trabajo cae en los mismos archivos que ya implementan Compras, siguiendo 1:1 la estructura ya usada por Ventas para el mismo tipo de feature (filtros de listado).

## Complexity Tracking

*Sin violaciones a la Constitución. Tabla omitida.*
