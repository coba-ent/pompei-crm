# Implementation Plan: Proveedores + Informe de Stock

**Branch**: `003-proveedores-informe-stock` | **Date**: 2026-07-24 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `specs/003-proveedores-informe-stock/spec.md`

## Summary

Reconstruir el módulo **Proveedores** (descartado en la limpieza anterior) como espejo de Clientes,
reactivar el selector "Proveedor" en el modal de Producto (la columna `productos.proveedor_id` ya
existe en el esquema, no se tocó), y reemplazar el modal simple de histórico de stock por una
pantalla propia **Informe de Stock** con filtros, rango de fechas, 3 KPIs de valorización y una
columna de **saldo corrido** ("Stock Saldo") por movimiento — fiel a la estructura relevada con
capturas reales en `docs/informe_contagram_base_de_datos.md` §3 y §4.9.

Enfoque técnico: reutilizar exactamente el patrón ya probado en Clientes/Productos (FormRequest +
controller que responde JSON, DataTable server-side vía `yajra`, modal Bootstrap por AJAX, toasts).
El "Stock Saldo" se calcula con una suma corrida (`SUM() OVER (PARTITION BY producto_id, variante_id,
deposito_id ORDER BY fecha, id)`) sobre el **histórico completo** de `movimientos_stock` (no sobre el
subconjunto ya filtrado), para que el saldo mostrado sea siempre correcto aunque el usuario acote la
tabla por fecha/tipo/proveedor — los filtros de pantalla se aplican como una capa externa sobre ese
cálculo, nunca reduciendo la base sobre la que se acumula.

## Technical Context

**Language/Version**: PHP 8.2, Laravel 12

**Primary Dependencies**: Laravel Framework 12, Eloquent ORM, Blade; template NexaDash (Bootstrap 5)
para UI; Vite para assets. Ya disponibles de Clientes/Productos: **`yajra/laravel-datatables-oracle`**
(server-side), **DataTables** (+ responsive), **Toastr**, **jQuery**, **Select2**, **Bootstrap
bundle** (modales), **bootstrap-daterangepicker** (ya cargado por `config/dz.php` en el pagelevel
`home`, se reutiliza el mismo vendor para el selector de rango de fechas del Informe de Stock). Sin
librerías nuevas.

**UX/UI OBLIGATORIO** (reglas del proyecto, ver `CLAUDE.md`): (1) los listados son **DataTable
responsive con datos por AJAX server-side**; (2) alta/edición/eliminación de Proveedor se hacen en
**modales de Bootstrap enviados por AJAX**, sin recargar nunca la página; (3) toda notificación usa
**toasts de Toastr**; (4) selects de datos dinámicos (Proveedor, Tipo de Producto, Usuario) usan
**Select2**; (5) **principio de fidelidad estructural a Contagram** (`CLAUDE.md`): el Informe de
Stock es una **pantalla propia** (ruta independiente, no un modal), calcando filtros/KPIs/columnas
relevados en el informe con capturas — no una versión simplificada.

**Storage**: MySQL (XAMPP local, DB `contagram`). Migraciones versionadas de Laravel. La tabla
`productos` ya tiene la columna `proveedor_id` (nullable, del esquema original no eliminado); este
plan **no** crea una migración nueva para esa columna, sólo reactiva el modelo/relación/UI. Se debe
verificar en Phase 0 si esa columna tiene o no una FK real a nivel de base (`foreign key constraint`)
o quedó como entero simple sin constraint (la tabla `proveedores` no existía desde que se borró el
módulo) — research.md resuelve esto.

**Testing**: PHPUnit 11 sobre SQLite en memoria. Feature tests para CRUD de Proveedor (espejo de los
de Cliente), la reactivación del selector en Producto, y el cálculo de "Stock Saldo" (caso crítico:
debe dar el mismo resultado con y sin filtros de fecha/tipo aplicados sobre la pantalla).

**Target Platform**: Aplicación web (servida por `php artisan serve` en dev; navegador de escritorio).

**Project Type**: Web application monolítica Laravel (backend + Blade en el mismo proyecto).

**Performance Goals**: Listado de Proveedores usable con volumen equivalente a Clientes (cientos a
pocos miles). Informe de Stock: cálculo de saldo corrido con rendimiento aceptable (<3 s percibidos)
hasta varios miles de movimientos históricos, vía window function nativa de MySQL 8 (no cálculo en
PHP fila por fila).

**Constraints**: Single-tenant (sin `empresa_id`). No se elimina físicamente un proveedor con
productos asociados (mismo patrón "no eliminar con operaciones/relaciones" ya usado en Cliente y
Producto). El Informe de Stock es de sólo lectura (no edita ni elimina movimientos). El filtro
"Operación" del Informe de Stock sólo lista los tipos que el sistema genera hoy (`ajuste`,
`transferencia`) — no incluye `entrada`/`salida` de Compras/Ventas, que no existen aún.

**Scale/Scope**: 1 entidad principal nueva (Proveedor) + 1 tabla de soporte (`proveedor_contactos`),
1 columna reactivada en Producto (relación, no migración), 1 pantalla de informe nueva (sin tabla
propia — proyección sobre `movimientos_stock`/`stocks`/`productos`/`proveedores`/`usuarios`). ~2
controladores (`ProveedorController`, `InformeStockController`), 1 trait de reglas de validación
(`ReglasProveedor`, espejo de `ReglasCliente`), ~4 vistas/parciales de Proveedores + 1 vista de
Informe de Stock.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **I. Documentación de dominio como fuente de verdad**: ✅ La spec y este plan se basan en
  `docs/documentacion_principal_crm.md` §2.1 (Clientes, patrón espejo), §4.2 y §5, y en
  `docs/modelo_datos.md` §2. El `data-model.md` derivado mantiene consistencia; si el research revela
  algo nuevo sobre la columna `proveedor_id` (ver Technical Context), se actualiza `modelo_datos.md`
  en el mismo cambio.
- **II. Desarrollo spec-driven**: ✅ Se sigue el flujo specify → plan → tasks → analyze → implement.
- **III. Corrección fiscal innegociable (ARCA)**: ✅ (aplicación indirecta) Proveedor reutiliza el
  mismo bloque de datos de facturación y validación de CUIT ya construido para Cliente
  (`CuitValido`, sin bloquear si está vacío). No se emiten comprobantes en esta feature.
- **IV. Testing donde hay dinero o impacto fiscal**: ✅ Se planifican tests para: CRUD de Proveedor
  (espejo de Cliente), regla "no eliminar con productos asociados", y el cálculo de "Stock Saldo"
  (movimientos de dinero/valorización indirecta vía Costo Total/Valor Venta Total del informe).
- **V. Convenciones Laravel + dominio en español**: ✅ Tablas/columnas/modelos/rutas/vistas en
  español (`proveedores`, `proveedor_contactos`), snake_case, sin `empresa_id`. Se reutiliza
  `StockService`/`MovimientoStock` existentes sin modificarlos (el informe es sólo lectura sobre
  datos que ya se generan).

**Resultado del gate**: PASS. Sin violaciones que justificar (Complexity Tracking vacío).

## Project Structure

### Documentation (this feature)

```text
specs/003-proveedores-informe-stock/
├── plan.md              # Este archivo
├── spec.md              # Especificación (ya creada)
├── research.md          # Fase 0 (este comando)
├── data-model.md        # Fase 1 (este comando)
├── quickstart.md         # Fase 1 (este comando)
├── contracts/            # Fase 1 (este comando) — contrato de rutas
│   └── proveedores-informe-stock-rutas.md
├── checklists/
│   └── requirements.md  # Checklist de calidad de la spec (ya creado)
└── tasks.md              # Fase 2 (/speckit-tasks — NO lo crea este comando)
```

### Source Code (repository root)

Monolito Laravel existente. Archivos nuevos/modificados de esta feature:

```text
app/
├── Models/
│   ├── Proveedor.php                       # espejo de Cliente.php
│   └── ProveedorContacto.php                # espejo de ClienteContacto.php
├── Http/
│   ├── Controllers/
│   │   ├── ProveedorController.php          # resource + data/stats/export/estado (espejo ClienteController)
│   │   ├── Informes/
│   │   │   └── InformeStockController.php    # pantalla + endpoint AJAX del informe
│   │   └── ProductoController.php            # MODIFICADO: reincorporar proveedor_id (fillable, relación, filtro, columna)
│   └── Requests/
│       ├── StoreProveedorRequest.php
│       ├── UpdateProveedorRequest.php
│       └── Concerns/
│           └── ReglasProveedor.php           # espejo de ReglasCliente.php
└── Models/Producto.php                       # MODIFICADO: relación proveedor() reincorporada

database/
├── migrations/
│   ├── xxxx_create_proveedores_table.php     # espejo de create_clientes_table (sin lista_precio_id)
│   └── xxxx_create_proveedor_contactos_table.php
├── factories/
│   └── ProveedorFactory.php
└── seeders/
    └── ProveedoresDemoSeeder.php             # (opcional, para probar el filtro Proveedor del informe)

resources/views/
├── proveedores/
│   ├── index.blade.php        # listado: DataTable AJAX server-side + buscador + Nuevo Proveedor
│   ├── _modal_form.blade.php  # modal alta/edición (bloque "Compras" en vez de "Ventas")
│   └── _row_actions.blade.php # Ver / Editar / Inactivar-Reactivar / Eliminar
├── productos/
│   └── _modal_form.blade.php  # MODIFICADO: selector "Proveedor" reincorporado
│   └── index.blade.php        # MODIFICADO: columna + filtro "Proveedor" reincorporados
└── informes/
    └── stock/
        └── index.blade.php    # pantalla del Informe de Stock: filtros + rango de fechas + 3 KPIs + tabla

resources/js/
├── proveedores.js              # espejo de clientes.js
├── informe-stock.js            # DataTable + filtros + KPIs del informe
└── productos.js                # MODIFICADO: selector Proveedor con Select2, filtro, columna

routes/web.php                  # MODIFICADO: agrega
                                # Route::resource('proveedores', ...) + data/stats/export/estado (espejo clientes)
                                # GET informes/stock, GET informes/stock/data, GET informes/stock/stats
                                # productos/{producto}/movimientos → ahora redirige/enlaza a informes/stock?producto_id=

tests/
├── Feature/
│   ├── ProveedorAltaTest.php            # alta/edición básica (espejo ClienteAltaTest)
│   ├── ProveedorBajaTest.php            # estado (toggle) + destroy (409 si tiene productos asociados)
│   ├── ProveedorListadoTest.php         # data server-side: búsqueda, columnas (sin Usuario ML)
│   ├── ProductoProveedorTest.php        # asociar/editar proveedor_id desde el modal de Producto
│   └── InformeStockTest.php             # filtros, KPIs, y el cálculo de "Stock Saldo" con/sin filtros
└── Unit/
    └── (ninguno nuevo — el cálculo de saldo corrido se prueba a nivel Feature contra la DB real)
```

**Structure Decision**: Estructura monolítica estándar de Laravel, reutilizando el patrón de
`001-clientes` para Proveedor (mismo controller/request/vista shape) y de `002-productos` para el
informe (mismo estilo de DataTable + filtros + KPIs ya usado en el listado de Productos). El
**Informe de Stock** no introduce tablas nuevas: es una consulta sobre `movimientos_stock` con un
`addSelect` de ventana (`SUM() OVER (...)`) para el saldo corrido, análoga a los `addSelect` de
subconsulta ya usados en `ProductoController::queryFiltrada()` para las columnas de lista de precio.
`ProductoController`/`Producto` se **modifican** (no se recrean) para reincorporar lo que ya existía
antes de la limpieza (relación `proveedor()`, columna del listado, filtro del panel), evitando
duplicar código ya escrito una vez.

## Complexity Tracking

> No aplica — el Constitution Check pasó sin violaciones. Sin desviaciones que justificar.

## Constitution Check (post-diseño, tras Fase 1)

Re-chequeado tras generar `research.md`, `data-model.md`, `contracts/` y `quickstart.md`:

- **I**: ✅ `data-model.md` deja explícito el pendiente de actualizar `docs/modelo_datos.md` y
  `docs/documentacion_principal_crm.md` durante `/speckit-implement` (movimiento de "Tablas
  descartadas" a sección activa) — no se cierra la feature sin ese paso.
- **II**: ✅ Sin cambios, se sigue el flujo.
- **III**: ✅ Sin impacto nuevo — Proveedor reutiliza la validación de CUIT ya existente.
- **IV**: ✅ El cálculo de "Stock Saldo" (research.md §2) queda cubierto por `InformeStockTest`
  (quickstart.md), incluyendo el caso crítico de que los filtros no alteren el saldo ya calculado.
- **V**: ✅ Nombres en español, sin `empresa_id`, reutiliza `StockService`/`MovimientoStock` sin
  modificarlos.

**Resultado**: PASS. Ninguna decisión de diseño introdujo una violación nueva.
