# Implementation Plan: Base de Datos — Productos & Servicios

**Branch**: `002-productos` | **Date**: 2026-07-17 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `specs/002-productos/spec.md`

## Summary

Implementar el ABM (alta, baja lógica, modificación) y listado de **Productos & Servicios** del
negocio, con: datos económicos (precio de venta e IVA venta, costo e IVA compra, mostrar en
ventas/compras), código/SKU único (clave futura de sync TiendaNube), **variantes** con SKU propio,
**precios por lista de precio** (reutilizando `listas_precio` del módulo Clientes), **depósitos
múltiples** con **stock** por producto/variante+depósito y **ajustes de stock manuales** con histórico
de **movimientos**. Es la segunda entidad del módulo Base de Datos y prerrequisito de Ventas y Compras.

Enfoque técnico: reutilizar el patrón ya probado en Clientes (controlador que responde JSON,
FormRequests, DataTable server-side vía `yajra`, modal Bootstrap por AJAX, toasts Toastr). Modelos
Eloquent + migraciones para `productos`, `producto_variantes`, `precios_producto`, `depositos`,
`stocks`, `movimientos_stock`. La unicidad global de SKU (producto ∪ variante) se resuelve con una
regla de validación dedicada (`SkuUnico`). Los ajustes de stock se encapsulan en un `StockService`
que actualiza la foto (`stocks`) y registra el histórico (`movimientos_stock`) de forma atómica.
Tests de feature/unit en la lógica crítica: unicidad de SKU, ajuste de stock (foto vs histórico),
regla "no eliminar con operaciones", validación de importes/IVA no negativos, servicio no controla
stock.

## Technical Context

**Language/Version**: PHP 8.2, Laravel 12

**Primary Dependencies**: Laravel Framework 12, Eloquent ORM, Blade; template NexaDash (Bootstrap 5)
para UI; Vite para assets. Ya disponibles del módulo Clientes: **`yajra/laravel-datatables-oracle`**
(server-side), **DataTables** (+ responsive), **Toastr**, **jQuery**, **Bootstrap bundle** (modales).
Sin librerías nuevas.

**UX/UI OBLIGATORIO** (reglas del proyecto, ver `CLAUDE.md`): (1) el listado es una **DataTable
responsive con datos por AJAX server-side**; (2) alta/edición/eliminación y ajuste de stock se hacen en
**modales de Bootstrap enviados por AJAX**, sin recargar nunca la página; (3) toda notificación
(éxito/error) usa **toasts de Toastr**. El controlador responde **JSON** en las operaciones (no
redirects); la validación devuelve errores en JSON para el modal/toast.

**Storage**: MySQL/MariaDB (XAMPP local, DB `contagram`). Migraciones versionadas de Laravel.

**Testing**: PHPUnit 11 sobre SQLite en memoria (config de `phpunit.xml`). Feature tests para flujos
HTTP y reglas de negocio; unit tests para la validación de SKU único y la lógica de ajuste de stock.

**Target Platform**: Aplicación web (servida por `php artisan serve` en dev; navegador de escritorio).

**Project Type**: Web application monolítica Laravel (backend + Blade en el mismo proyecto).

**Performance Goals**: Listado usable con ≥1.000 productos; búsqueda por nombre/SKU con respuesta
percibida <5 s (SC-005). Sin metas de alta concurrencia (single-tenant, pocos usuarios internos).

**Constraints**: Single-tenant (sin `empresa_id`). Un servicio nunca controla stock (SC-007). El SKU
no vacío es único considerando productos ∪ variantes (SC-002). Foto de stock y movimientos siempre
consistentes (SC-003). Los importes/IVA son ≥ 0 (SC-006).

**Scale/Scope**: Un negocio, pocos usuarios internos concurrentes; catálogo esperable de cientos a
pocos miles de productos. Alcance: 1 entidad principal (Producto) + 5 tablas de soporte
(`producto_variantes`, `precios_producto`, `depositos`, `stocks`, `movimientos_stock`), ~1 controlador
resource + endpoints de stock, ~4-5 vistas/parciales, 2 FormRequests, 1 regla (`SkuUnico`), 1 servicio
(`StockService`).

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **I. Documentación de dominio como fuente de verdad**: ✅ La spec y este plan se basan en
  `docs/documentacion_principal_crm.md` §5.2 y `docs/modelo_datos.md` §2 (productos, producto_variantes,
  precios_producto, depositos, stocks, movimientos_stock). El data-model.md derivado es consistente; si
  algo cambia (campo/regla nueva), se actualizan los docs de dominio en el mismo cambio.
- **II. Desarrollo spec-driven**: ✅ Se sigue el flujo specify → plan → tasks → implement.
- **III. Corrección fiscal innegociable (ARCA)**: ✅ (aplicación indirecta) No se emiten comprobantes
  en esta feature. Se preparan datos que la facturación consumirá: IVA de venta/compra por producto
  (base del Libro IVA) validados como no negativos, y precios consistentes. No hay lógica de CAE aquí.
- **IV. Testing donde hay dinero o impacto fiscal**: ✅ Se planifican tests para: unicidad de SKU,
  ajuste de stock (foto `stocks` vs histórico `movimientos_stock`), regla "no eliminar con operaciones",
  importes/IVA no negativos y "servicio no controla stock". El CRUD trivial de campos no
  económicos no lleva test obligatorio.
- **V. Convenciones Laravel + dominio en español**: ✅ Tablas/columnas/modelos/rutas/vistas en español
  (`productos`, `producto_variantes`, `movimientos_stock`, etc.), snake_case; convenciones estándar de
  Laravel (resource controller, FormRequest, migraciones, seeders, Service para el recálculo de stock).
  Sin `empresa_id`. Regla de negocio de la sección 11 respetada: un producto con operaciones no se
  elimina (se inactiva).

**Resultado del gate**: PASS. Sin violaciones que justificar (Complexity Tracking vacío).

## Project Structure

### Documentation (this feature)

```text
specs/002-productos/
├── plan.md              # Este archivo
├── spec.md              # Especificación (ya creada)
├── research.md          # Fase 0 (este comando)
├── data-model.md        # Fase 1 (este comando)
├── quickstart.md        # Fase 1 (este comando)
├── contracts/           # Fase 1 (este comando) — contrato de UI/rutas
│   └── productos-rutas.md
├── checklists/
│   └── requirements.md  # Checklist de calidad de la spec (ya creado)
└── tasks.md             # Fase 2 (/speckit-tasks — NO lo crea este comando)
```

### Source Code (repository root)

Monolito Laravel existente. Archivos nuevos de esta feature en la estructura estándar de Laravel:

```text
app/
├── Models/
│   ├── Producto.php
│   ├── ProductoVariante.php
│   ├── PrecioProducto.php
│   ├── Deposito.php
│   ├── Stock.php
│   └── MovimientoStock.php
├── Http/
│   ├── Controllers/
│   │   ├── ProductoController.php          # resource + data/stats/export/estado
│   │   └── StockController.php             # ajuste de stock + histórico de movimientos
│   └── Requests/
│       ├── StoreProductoRequest.php
│       ├── UpdateProductoRequest.php
│       └── AjusteStockRequest.php
├── Services/
│   └── Stock/
│       └── StockService.php               # aplica ajuste: actualiza stocks + registra movimiento (atómico)
└── Rules/
    └── SkuUnico.php                        # unicidad global de SKU (productos ∪ variantes)

database/
├── migrations/
│   ├── xxxx_create_depositos_table.php
│   ├── xxxx_create_productos_table.php
│   ├── xxxx_create_producto_variantes_table.php
│   ├── xxxx_create_precios_producto_table.php
│   ├── xxxx_create_stocks_table.php
│   └── xxxx_create_movimientos_stock_table.php
├── factories/
│   └── ProductoFactory.php
└── seeders/
    ├── DepositoSeeder.php                  # depósito "Principal" por defecto
    ├── ProductosDemoSeeder.php             # ~1.000 productos para validar SC-005
    └── DatabaseSeeder.php                  # (actualizado para llamar a DepositoSeeder)

resources/views/productos/
├── index.blade.php        # listado: DataTable AJAX server-side + filtros + botón "Nuevo Producto"
├── _modal_form.blade.php  # modal de alta/edición (datos, económicos, variantes, precios por lista)
├── _modal_stock.blade.php # modal de ajuste de stock + histórico de movimientos
└── _row_actions.blade.php # acciones por fila (editar/stock/inactivar/eliminar)

resources/js/productos.js  # DataTable + submit AJAX del modal + variantes dinámicas + ajuste stock + toasts

routes/web.php             # Route::resource('productos', ...) parcial + rutas AJAX:
                           #   GET productos/data, GET productos/stats, GET productos/export,
                           #   PATCH {producto}/estado, POST {producto}/stock, GET {producto}/movimientos

tests/
├── Feature/
│   ├── ProductoAltaTest.php               # alta/edición básica vía HTTP (nombre requerido)
│   ├── ProductoSkuTest.php                # unicidad global de SKU (producto y variante)
│   ├── ProductoListadoTest.php            # data server-side: búsqueda nombre/SKU, filtros estado/tipo
│   ├── ProductoBajaTest.php               # estado (toggle) + destroy (409 con operaciones)
│   └── StockAjusteTest.php                # ajuste aumento/disminución: foto vs histórico; servicio sin stock
└── Unit/
    └── SkuUnicoTest.php                    # regla de validación pura
```

**Structure Decision**: Estructura monolítica estándar de Laravel, reutilizando el patrón de Clientes
(`001-clientes`). Las **listas de precio** (`listas_precio`) ya existen y se reutilizan. Los
**depósitos** se crean aquí con un seeder de un depósito "Principal" por defecto para simplificar el
alta inicial de stock. El **stock** se modela con dos tablas: `stocks` (foto actual, único por
producto+variante+depósito) y `movimientos_stock` (histórico), mantenidas consistentes por
`StockService` dentro de una transacción. Los **proveedores** se referencian de forma opcional; su ABM
propio es una feature aparte (si aún no existe la tabla al implementar, la FK queda nullable y el
selector, vacío).

## Complexity Tracking

> No aplica — el Constitution Check pasó sin violaciones. Sin desviaciones que justificar.
