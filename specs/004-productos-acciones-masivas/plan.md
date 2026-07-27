# Implementation Plan: Selección Múltiple y Acciones Masivas en Productos

**Branch**: `004-productos-acciones-masivas` | **Date**: 2026-07-24 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `specs/004-productos-acciones-masivas/spec.md`

## Summary

Agregar selección múltiple (checkbox por fila + "seleccionar todo" de la página + "seleccionar los
N que matchean el filtro") al listado de Productos, y un modal "Acciones Masivas" con 11 operaciones
en lote (precio, costo, mostrar en ventas/compras, estado, IVA por defecto, tipo de producto,
proveedor, eliminar masivamente) — fiel a `docs/informe_contagram_base_de_datos.md` §4.1/§4.4 y las
capturas `capturas/nuevas/50` y `51`.

Enfoque técnico: sin librerías nuevas. Selección manejada con JS plano (Set de IDs en memoria,
limpiado en cada redraw de la DataTable) más un modo "todos los que matchean el filtro" que viaja al
backend como una bandera + los filtros vigentes (no una lista de miles de IDs). El backend reutiliza
`ProductoController::queryFiltrada()` ya existente para resolver ese modo, y aplica cada acción con
un `foreach` + `DB::transaction()` por producto (falla atómica de todo el lote si algún valor no
pasa validación, salvo "Eliminar Masivamente" que evalúa cada producto independientemente por su
propia naturaleza — no es "todo o nada").

## Technical Context

**Language/Version**: PHP 8.2, Laravel 12

**Primary Dependencies**: Laravel Framework 12, Eloquent ORM, Blade; NexaDash (Bootstrap 5); Vite.
Ya disponibles de 002-productos: `yajra/laravel-datatables-oracle`, DataTables (+ responsive),
Toastr, jQuery, Select2, Bootstrap. **Sin librerías nuevas** — no se agrega la extensión "Select" de
DataTables; la selección se maneja con JS plano (ver Summary).

**Storage**: MySQL (XAMPP local, DB `contagram`). Sin migraciones nuevas — la feature opera sobre
columnas ya existentes de `productos` (`precio_venta`, `costo`, `mostrar_en_ventas`,
`mostrar_en_compras`, `activo`, `iva_venta_pct`, `iva_compra_pct`, `tipo_producto_id`,
`proveedor_id`).

**UX/UI OBLIGATORIO** (`CLAUDE.md`): el modal "Acciones Masivas" se abre y se ejecuta por **AJAX sin
recargar la página**; toda notificación de resultado usa **toasts de Toastr**; los selects de
Tipo de Producto/Proveedor dentro del modal usan **Select2** (con `dropdownParent` al modal).

**Testing**: PHPUnit 11 sobre SQLite en memoria. Feature tests para: cada una de las 11 acciones
aplicada a un lote de productos, la protección de "no eliminar con operaciones asociadas" en el
contexto de eliminación masiva (Principio IV de la constitución — hay impacto de dinero en
precio/costo), y el modo "todos los que matchean el filtro" resolviendo vía `queryFiltrada()`.

**Target Platform**: Aplicación web (navegador de escritorio, `php artisan serve` en dev).

**Project Type**: Web application monolítica Laravel (backend + Blade en el mismo proyecto).

**Performance Goals**: Un lote de accion masiva sobre unos pocos cientos de productos (volumen
esperado del negocio) se percibe como una operación instantánea (<2 s). No se pagina ni se limita
artificialmente la cantidad de productos por lote.

**Constraints**: Single-tenant (sin `empresa_id`). Las acciones de valor único (precio, costo,
estado, IVA, tipo de producto, proveedor, mostrar en ventas/compras) son atómicas para todo el lote
(si un valor no pasa validación, no se aplica a ninguno). "Eliminar Masivamente" es la excepción: se
evalúa producto por producto (mismo patrón que el `destroy()` individual ya existente), porque la
razón de exclusión (tiene operaciones asociadas) es una propiedad de cada producto, no del lote.

**Scale/Scope**: 1 endpoint nuevo (`productos.acciones-masivas`), sin controlador nuevo (se agrega
un método a `ProductoController` ya existente), 1 modal nuevo + checkbox por fila en la vista/JS ya
existente de Productos. Sin modelos, migraciones ni tablas nuevas.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **I. Documentación de dominio como fuente de verdad**: ✅ Este plan se basa en
  `docs/documentacion_principal_crm.md` §2.2 (Productos) y se actualizará esa sección para
  documentar Selección Múltiple + Acciones Masivas como funcionalidad activa al cerrar la feature,
  en el mismo cambio que el código (no en un commit aparte).
- **II. Desarrollo spec-driven**: ✅ Se sigue el flujo specify → plan → tasks → analyze → implement.
- **III. Corrección fiscal innegociable (ARCA)**: N/A — esta feature no emite comprobantes ni toca
  facturación electrónica.
- **IV. Testing donde hay dinero o impacto fiscal**: ✅ Se planifican tests para las acciones que
  modifican precio/costo (impacto económico directo) y para la protección de "no eliminar con
  operaciones asociadas" (protege integridad del historial de stock/valorización).
- **V. Convenciones Laravel + dominio en español**: ✅ Nombres de rutas/métodos en español
  (`acciones-masivas`, `ProductoController::accionesMasivas()`), sin `empresa_id`, reutiliza
  `ProductoController`/`Producto` existentes sin duplicar código de validación (reusa
  `ReglasProducto` donde aplica).

**Resultado del gate**: PASS. Sin violaciones que justificar (Complexity Tracking vacío).

## Project Structure

### Documentation (this feature)

```text
specs/004-productos-acciones-masivas/
├── plan.md              # Este archivo
├── spec.md              # Especificación (ya creada)
├── research.md          # Fase 0 (este comando)
├── data-model.md        # Fase 1 (este comando)
├── quickstart.md         # Fase 1 (este comando)
├── contracts/            # Fase 1 (este comando) — contrato de la ruta nueva
│   └── acciones-masivas-rutas.md
├── checklists/
│   └── requirements.md  # Checklist de calidad de la spec (ya creado)
└── tasks.md              # Fase 2 (/speckit-tasks — NO lo crea este comando)
```

### Source Code (repository root)

Monolito Laravel existente. Archivos nuevos/modificados de esta feature:

```text
app/
└── Http/
    ├── Controllers/
    │   └── ProductoController.php        # MODIFICADO: nuevo método accionesMasivas()
    └── Requests/
        └── AccionMasivaProductoRequest.php  # NUEVO: valida la acción elegida + su valor

resources/views/
└── productos/
    ├── index.blade.php        # MODIFICADO: checkbox de header + barra de selección + include del modal
    └── _modal_acciones_masivas.blade.php   # NUEVO: modal "Acciones Masivas"

resources/js/
└── productos.js              # MODIFICADO: checkboxes de fila/header, barra de selección,
                               # "seleccionar todos los N", submit del modal por AJAX

routes/web.php                  # MODIFICADO: agrega
                                 # POST productos/acciones-masivas → ProductoController@accionesMasivas

tests/
└── Feature/
    └── ProductoAccionesMasivasTest.php   # NUEVO: las 11 acciones, atomicidad del lote,
                                           # protección de eliminar con operaciones, modo "todos"
```

**Structure Decision**: Extensión mínima sobre 002-productos — no se crea ningún controlador,
modelo ni vista nueva de nivel superior; todo vive dentro de los archivos ya existentes de Productos
más un FormRequest y un test file nuevos. Esto refleja que la feature es una capa de UI/orquestación
sobre operaciones (`update`, `delete`) que `ProductoController` ya sabe hacer una por una — el
método nuevo `accionesMasivas()` es, en esencia, un `foreach` con las mismas reglas de negocio ya
vigentes, no lógica nueva de dominio.

## Complexity Tracking

> No aplica — el Constitution Check pasó sin violaciones. Sin desviaciones que justificar.
