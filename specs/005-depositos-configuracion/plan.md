# Implementation Plan: Gestión de Depósitos

**Branch**: `005-depositos-configuracion` | **Date**: 2026-07-24 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `specs/005-depositos-configuracion/spec.md`

## Summary

Agregar la primera UI de gestión sobre la tabla `depositos` ya existente (hoy poblada sólo por
`DepositoSeeder`): una pantalla "Depósitos" en Configuración & Ajustes que abre un modal con lista
editable inline (nombre, activo, editar, eliminar) + "+ Agregar Depósito", fiel a
`docs/informe_contagram_funciones_avanzadas.md` §10 y las capturas 117/118 — sin reproducir la
advertencia de "operación larga" de Contagram real (divergencia documentada: este sistema ya es
multi-depósito desde el modelo de datos original, no hay migración que ejecutar).

Enfoque técnico: mismo patrón ya usado para los catálogos chicos de Producto (`ListaPrecioController`,
`TipoProductoController`) — cada alta/renombrado/cambio de estado/eliminación es su propia llamada
AJAX inmediata con su propio toast, sin un mecanismo de "guardar todo el lote" nuevo. Los botones
"Cancelar"/"Guardar" del modal son de cierre (no hay un diff pendiente que reconciliar: cada fila ya
quedó persistida al editarla/agregarla/tildarla).

## Technical Context

**Language/Version**: PHP 8.2, Laravel 12

**Primary Dependencies**: Laravel Framework 12, Eloquent ORM, Blade; NexaDash (Bootstrap 5); Vite.
Sin librerías nuevas — Bootstrap modal + Toastr ya disponibles globalmente.

**Storage**: MySQL (XAMPP local, DB `contagram`). **Sin migraciones nuevas** — la tabla `depositos`
y el modelo `Deposito` ya existen (creados en `002-productos`, poblados hoy por `DepositoSeeder`).

**UX/UI OBLIGATORIO** (`CLAUDE.md`): alta/renombrado/cambio de estado/eliminación por **modal
Bootstrap + AJAX, sin recargar la página**; toda notificación con **toasts de Toastr**.

**Testing**: PHPUnit 11 sobre SQLite en memoria. Feature tests para: alta, renombrado, toggle de
estado, eliminación sin asociaciones, y el rechazo de eliminación con stock/movimientos asociados
(Principio IV de la constitución — impacto en integridad de stock/valorización).

**Target Platform**: Aplicación web (navegador de escritorio, `php artisan serve` en dev).

**Project Type**: Web application monolítica Laravel (backend + Blade en el mismo proyecto).

**Performance Goals**: Catálogo chico (se espera un puñado de depósitos, no cientos) — sin
necesidad de DataTable server-side ni paginado; toda la lista se carga de una vez al abrir el modal.

**Constraints**: Single-tenant (sin `empresa_id`). No se elimina físicamente un depósito con stock
cargado o movimientos asociados — mismo patrón ya vigente para Cliente/Proveedor/Producto.

**Scale/Scope**: 1 controlador nuevo (`DepositoController`, espejo de `ListaPrecioController` con el
agregado de la regla de "no eliminar con operaciones asociadas"), 1 vista de página + 1 modal, 1
entrada nueva de sidebar bajo Configuración & Ajustes. Sin modelos ni migraciones nuevas.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **I. Documentación de dominio como fuente de verdad**: ✅ Este plan se basa en
  `docs/documentacion_principal_crm.md` §2.2 (menciona explícitamente el pendiente de esta UI) y
  `docs/modelo_datos.md` (tabla `depositos` ya documentada); ambos se actualizan al cerrar la
  feature para reflejar que la gestión ya no es "vía seeder/DB directa".
- **II. Desarrollo spec-driven**: ✅ Se sigue el flujo specify → plan → tasks → analyze → implement.
- **III. Corrección fiscal innegociable (ARCA)**: N/A — sin impacto fiscal ni de facturación.
- **IV. Testing donde hay dinero o impacto fiscal**: ✅ Se planifica test para la protección de "no
  eliminar depósito con stock/movimientos asociados" (protege integridad de valorización de
  inventario, que si se pierde silenciosamente rompe KPIs de Productos/Informe de Stock).
- **V. Convenciones Laravel + dominio en español**: ✅ Nombres en español (`depositos`,
  `DepositoController`), sin `empresa_id`, reutiliza el modelo `Deposito` existente sin duplicar
  esquema.

**Resultado del gate**: PASS. Sin violaciones que justificar (Complexity Tracking vacío).

## Project Structure

### Documentation (this feature)

```text
specs/005-depositos-configuracion/
├── plan.md              # Este archivo
├── spec.md              # Especificación (ya creada)
├── research.md          # Fase 0 (este comando)
├── data-model.md        # Fase 1 (este comando)
├── quickstart.md         # Fase 1 (este comando)
├── contracts/            # Fase 1 (este comando) — contrato de rutas
│   └── depositos-rutas.md
├── checklists/
│   └── requirements.md  # Checklist de calidad de la spec (ya creado)
└── tasks.md              # Fase 2 (/speckit-tasks — NO lo crea este comando)
```

### Source Code (repository root)

Monolito Laravel existente. Archivos nuevos/modificados de esta feature:

```text
app/
├── Http/Controllers/
│   └── DepositoController.php        # NUEVO: index/store/update/estado/destroy (espejo de ListaPrecioController + protección de eliminación)
└── Models/Deposito.php                # MODIFICADO: método tieneOperaciones() (stock/movimientos asociados)

resources/views/
├── configuracion/
│   └── depositos.blade.php            # NUEVO: página shell + include del modal
└── elements/sidebar.blade.php          # MODIFICADO: nueva entrada "Depósitos" bajo Configuración & Ajustes

resources/js/
└── configuracion-depositos.js          # NUEVO: alta/renombrado/toggle/eliminar por AJAX, toasts

routes/web.php                          # MODIFICADO: agrega
                                         # GET configuracion/depositos (vista)
                                         # GET configuracion/depositos/data (lista)
                                         # POST/PATCH/DELETE configuracion/depositos/{deposito}

tests/
└── Feature/
    └── DepositoTest.php                # NUEVO: alta, renombrado, toggle, eliminar (con y sin asociaciones)
```

**Structure Decision**: Extensión mínima siguiendo el patrón ya usado por `ListaPrecioController`/
`TipoProductoController` (catálogos chicos de Producto), pero como pantalla propia dentro de
Configuración & Ajustes en vez de embebida en el modal de Producto — porque en Contagram real
Depósitos vive en Configuración (Funciones Avanzadas), no dentro del alta de Producto. Se reutiliza
el modelo `Deposito` existente sin cambios de esquema, sólo se le agrega el método de dominio
`tieneOperaciones()` (mismo patrón ya usado en `Cliente`/`Proveedor`/`Producto`).

## Complexity Tracking

> No aplica — el Constitution Check pasó sin violaciones. Sin desviaciones que justificar.
