# Implementation Plan: Ver/Editar producto desde el detalle de Venta, Presupuesto y Compra

**Branch**: `052-ver-editar-producto-detalle` | **Date**: 2026-08-06 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/052-ver-editar-producto-detalle/spec.md`

## Summary

Agregar, en la fila de cada producto/servicio dentro de la tabla de Conceptos/Detalle de Ventas,
Presupuestos y Compras, un desplegable (▾) con "Ver" / "Editar" que reutiliza — sin duplicarlos —
los modales ya existentes en Productos (`_modal_ver.blade.php` de sólo lectura y `_modal_form.blade.php`
de alta/edición). Enfoque técnico: extraer la porción de `productos.js` que maneja esos dos modales
(y sus dependencias: `cfg` con catálogos, rutas `show`/`update`) a un módulo compartido
`resources/js/producto-modales.js`, inicializable independientemente del DataTable de Productos.
Los partials de los modales se incluyen también en las vistas de Ventas/Presupuestos/Compras. Las
páginas de detalle delegan clicks de "Ver"/"Editar" de cada fila al módulo compartido y escuchan un
evento de "producto actualizado" para refrescar nombre/precio de la fila cuando corresponde.

## Technical Context

**Language/Version**: PHP 8.2 (Laravel 12), JavaScript (jQuery, ES5/ES6 vía Vite, sin framework SPA)

**Primary Dependencies**: Bootstrap 5 (modales), Select2, DataTables (no afectado en esta feature),
Toastr (`window.toastr`), patrón AJAX ya usado por `productos.js` / `ventas.js` / `presupuestos.js` / `compras.js`

**Storage**: MySQL — sin cambios de esquema; reutiliza `productos` y tablas relacionadas ya existentes

**Testing**: PHPUnit (Feature tests) para endpoints ya existentes de Producto (no se agregan
endpoints nuevos); verificación manual en navegador para el flujo de UI (modales AJAX, sin recarga)

**Target Platform**: Web (navegador de escritorio), backend Laravel 12 servido vía XAMPP local / VPS

**Project Type**: Web application monolítica (Laravel + Blade + Vite) — un solo proyecto

**Performance Goals**: Apertura de "Ver"/"Editar" desde el detalle en <5s (SC-001), igual a la
apertura ya existente en Productos (misma llamada AJAX a `productos.show`)

**Constraints**: No se agregan endpoints nuevos de backend (se reutiliza `ProductoController@show`
y `@update` ya existentes vía `Route::resource('productos', ...)`); no se puede duplicar la lógica
de los modales de Productos (regla explícita del usuario) — debe extraerse a un módulo compartido.

**Scale/Scope**: 3 vistas consumidoras (Ventas, Presupuestos, Compras) + 1 vista ya existente
(Productos) que pasa a consumir el mismo módulo compartido en vez de tener la lógica inline.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **I. Documentación de dominio como fuente de verdad**: la fila con menú ▾ Ver/Editar está
  documentada en `docs/informe_contagram_ingresos.md:67` (Presupuestos) y aplica por consistencia
  estructural a Ventas (mismo formulario base, `docs/informe_contagram_ingresos.md:89`) y a Compras
  (mismo patrón de tabla de Conceptos, `docs/informe_contagram_egresos.md:116`). No introduce
  entidades ni reglas de negocio nuevas → no requiere cambios en `documentacion_principal_crm.md`
  ni `modelo_datos.md` más allá de anotar la UI (se documenta al cerrar la implementación si aporta
  algo no cubierto). **PASA**.
- **II. Desarrollo spec-driven**: esta feature sigue el flujo completo (specify → clarify → plan →
  checklist → tasks → analyze). **PASA**.
- **III. Corrección fiscal (ARCA)**: no toca facturación electrónica, CAE, ni comprobantes. **N/A**.
- **IV. Testing donde hay dinero o impacto fiscal**: la feature no cambia cálculos de totales,
  precios ni impuestos — sólo agrega un punto de entrada de UI a edición ya existente y probada de
  Producto. Se agrega cobertura Feature mínima para el refresco de fila (ver Fase 1). **PASA**.
- **V. Convenciones Laravel + dominio en español**: nombres de rutas, vistas y JS en español,
  consistente con `productos.js`/`ventas.js`. **PASA**.

Reglas de diseño obligatorias del proyecto (tablas AJAX, modales Bootstrap+AJAX sin recarga,
toasts, Select2) ya están satisfechas por reutilizar los modales existentes de Productos tal cual.

**Resultado**: Sin violaciones. No aplica Complexity Tracking.

## Project Structure

### Documentation (this feature)

```text
specs/052-ver-editar-producto-detalle/
├── plan.md              # This file
├── research.md          # Phase 0 output
├── data-model.md         # Phase 1 output
├── quickstart.md         # Phase 1 output
└── tasks.md              # Phase 2 output (/speckit-tasks)
```

### Source Code (repository root)

```text
resources/
├── views/
│   ├── productos/
│   │   ├── _modal_ver.blade.php       # existente, sin cambios de contenido
│   │   └── _modal_form.blade.php      # existente, sin cambios de contenido
│   ├── ventas/
│   │   └── form.blade.php             # agrega @include de los 2 modales + botón ▾ por fila
│   ├── presupuestos/
│   │   └── form.blade.php             # ídem
│   └── compras/
│       └── form.blade.php             # ídem
└── js/
    ├── producto-modales.js            # NUEVO — módulo compartido: abre Ver/Editar, guarda,
    │                                   #         dispara evento 'producto:actualizado'
    ├── productos.js                   # refactor: delega Ver/Editar de fila al módulo compartido
    ├── ventas.js                      # agrega dropdown por fila + listener de refresco
    ├── presupuestos.js                # ídem
    └── compras.js                     # ídem

app/Http/Controllers/ProductoController.php   # sin cambios (show/update ya existen)

tests/Feature/
└── Ventas/PresupuestoVentaCompra... (nombre exacto a definir en tasks)
    # test mínimo: al editar producto desde el detalle, la fila se refresca
```

**Structure Decision**: Monolito Laravel existente. No se crean módulos ni apps nuevas — se agrega
un archivo JS compartido (`producto-modales.js`) cargado en las 4 vistas que lo necesitan (Productos,
Ventas, Presupuestos, Compras), y se incluyen los 2 partials de modal ya existentes en las 3 vistas
que hoy no los tienen. Los endpoints de backend se reutilizan sin cambios.

## Complexity Tracking

*No violations — sección no aplica.*
