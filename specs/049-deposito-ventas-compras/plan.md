# Implementation Plan: Selector de Depósito y Número de Comprobante Real en Ventas y Compras

**Branch**: `049-deposito-ventas-compras` | **Date**: 2026-08-06 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/049-deposito-ventas-compras/spec.md`

## Summary

Ventas y Compras manuales hoy mueven stock siempre contra `Deposito::porDefecto()` (el depósito
activo de menor `id`), sin que el usuario pueda elegir otro — inconsistente con que el sistema ya
expone filtro por Depósito en el listado de Ventas y selectores explícitos en Ajuste de Stock,
Movimiento entre Depósitos y NC/ND de Compra. Este feature agrega un campo Depósito obligatorio a
los formularios "Nueva Venta"/"Nueva Compra" (columna `deposito_id` en `ventas` y `compras`), hace
que `StockDeVenta`/`StockDeCompra` usen ese valor en vez de `Deposito::porDefecto()` para altas,
ediciones y bajas, y agrega dos columnas de default (`deposito_id`, `deposito_compra_id`) a la fila
única `configuracion_ventas` (ya reutilizada por Ventas/Presupuestos/Compras desde spec 043/044),
exponiéndolas en la sección "Ventas" y en la sección "Compras" del tab "Ventas" de Configuración &
Ajustes ya existente. Cambio sólo hacia adelante — Ventas/Compras/movimientos ya cargados no se
tocan.

Además (User Story 3, ampliación de la misma sesión), el N° de comprobante de Compra
(`compras.nro_comprobante`) deja de ser un correlativo interno autogenerado y no editable
(`Compra::siguienteNroComprobante()`) para pasar a ser un campo de texto editable en "Nueva
Compra"/"Editar Compra", precargado con ese mismo correlativo como valor sugerido pero obligatorio
(no se puede guardar vacío). Es independiente del feature de Depósito — convive en el mismo
formulario, sin dependencias entre sí — y no toca los campos ya existentes
`punto_venta_proveedor`/`numero_comprobante_proveedor`/`cae_proveedor` del flujo de Facturación
Electrónica con CAE del Proveedor.

## Technical Context

**Language/Version**: PHP 8.2, Laravel 12

**Primary Dependencies**: Eloquent ORM, Select2 (vendor/select2, vía `config/dz.php` pagelevel),
DataTables (server-side/AJAX), Toastr (notificaciones)

**Storage**: MySQL (`contagram`) — migraciones sobre `ventas`, `compras`, `configuracion_ventas`

**Testing**: PHPUnit (Feature tests, `tests/Feature/...`), siguiendo el patrón ya usado por
`tests/Feature/DepositoPorDefectoTest.php`, `tests/Feature/Ingresos/VentaStockTest.php` y
`tests/Feature/Egresos` (Compras) para stock

**Target Platform**: Web (Laravel Blade + Vite/Tailwind + Bootstrap 5 NexaDash), servidor Linux
(hosting compartido Hostinger para el demo, VPS propio en paralelo)

**Project Type**: Web application monolítica (Laravel full-stack, sin frontend separado)

**Performance Goals**: N/A — operación CRUD estándar de bajo volumen (altas manuales de Venta/Compra)

**Constraints**: Debe respetar el patrón ya establecido por `StockDeVenta`/`StockDeCompra`/
`VentaObserver`/`CompraObserver` (alta/edición/baja de stock) sin romper el comportamiento de
Mercado Libre/Tiendanube (que resuelven su propio depósito vía `depositoEfectivo()`); reglas de
diseño obligatorias del proyecto (DataTables+AJAX, modales Bootstrap+AJAX, Toastr, Select2 con
`dropdownParent` en modales — no aplica aquí porque Nueva Venta/Compra son páginas completas, no
modales, pero el select igual debe ir con Select2 por ser dato dinámico)

**Scale/Scope**: Single-tenant, volumen de depósitos activos típicamente bajo (2-5)

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

`.specify/memory/constitution.md` no define principios propios más allá de remitir a
`CLAUDE.md`/`docs/documentacion_principal_crm.md` como fuente de verdad de negocio. Chequeo contra
el principio rector de CLAUDE.md ("fidelidad estructural a Contagram"):

- **No hay capturas reales de Contagram** (`docs/informe_contagram_ingresos.md`,
  `docs/informe_contagram_egresos.md`) que muestren un campo Depósito en Nueva Venta/Nueva Compra ni
  en configuración de valores por defecto. Este feature es una **divergencia deliberada y
  documentada** (ver spec.md → Assumptions), motivada por una inconsistencia interna ya detectada
  (el filtro por Depósito de Ventas no reflejaba nada real), no por una simplificación no
  fundamentada. Se registra explícitamente en `docs/documentacion_principal_crm.md` al implementar,
  como exige la regla de retroalimentación docs↔specs del proyecto.
- No se resuelve "simplificando" ninguna dependencia entre módulos: se reutiliza la infraestructura
  de Depósitos (spec 005), Configuración de Ventas/Compras (spec 043/044) y los servicios de stock ya
  existentes (spec 030), sin atajos.

**Resultado**: PASS (divergencia documentada, no vacío de justificación).

## Project Structure

### Documentation (this feature)

```text
specs/049-deposito-ventas-compras/
├── plan.md              # This file (/speckit-plan command output)
├── research.md          # Phase 0 output (/speckit-plan command)
├── data-model.md        # Phase 1 output (/speckit-plan command)
├── quickstart.md        # Phase 1 output (/speckit-plan command)
├── contracts/           # Phase 1 output (/speckit-plan command) — endpoints AJAX afectados
└── tasks.md             # Phase 2 output (/speckit-tasks command - NOT created by /speckit-plan)
```

### Source Code (repository root)

```text
database/migrations/
├── 2026_08_06_xxxxxx_add_deposito_id_to_ventas_table.php
├── 2026_08_06_xxxxxx_add_deposito_id_to_compras_table.php
└── 2026_08_06_xxxxxx_add_deposito_defaults_to_configuracion_ventas_table.php

app/Models/
├── Venta.php                          # + relación deposito(), fillable deposito_id
├── Compra.php                         # + relación deposito(), fillable deposito_id
└── ConfiguracionVentas.php            # + fillable deposito_id, deposito_compra_id, relaciones

app/Http/
├── Controllers/VentaController.php            # create()/store()/edit()/update(): pasar depositos, defaults, validar/persistir deposito_id
├── Controllers/CompraController.php            # ídem para Compra + US3: create() precarga nro_comprobante sugerido, store()/update() usan el valor del form en vez de siguienteNroComprobante()
├── Controllers/Configuracion/ConfiguracionVentasController.php  # validar deposito_id/deposito_compra_id
├── Requests/StoreVentaRequest.php / UpdateVentaRequest.php      # + regla deposito_id: required|exists:depositos,id
└── Requests/StoreCompraRequest.php / UpdateCompraRequest.php    # + regla deposito_id; + nro_comprobante: required|string|max:20 (deja de ser calculado server-side)

app/Models/
└── Compra.php                         # siguienteNroComprobante() pasa de "valor final" a "valor sugerido" — se sigue usando, pero sólo como default de precarga, no como fuente de verdad al guardar

app/Services/
├── Ingresos/StockDeVenta.php          # resolverDeposito(): origen manual usa $venta->deposito_id (con fallback) en vez de depositoPorDefecto() a secas
└── Egresos/StockDeCompra.php          # ídem, usa $compra->deposito_id

resources/views/
├── ventas/form.blade.php + resources/js/ventas.js         # select Depósito (Select2) en datos generales
├── compras/form.blade.php + resources/js/compras.js       # select Depósito (Select2) + input de texto editable para N° de comprobante (US3), precargado por JS con el valor sugerido que devuelve create()
└── configuracion/ventas/_tab.blade.php + resources/js correspondiente  # campo Depósito en sección "Ventas" y sección "Compras"

tests/Feature/
├── Ingresos/VentaStockTest.php (o nuevo VentaDepositoTest.php)
├── Egresos/CompraDepositoTest.php (nuevo, junto a los tests de StockDeCompra existentes)
├── Egresos/CompraNroComprobanteTest.php (nuevo — US3: sugerido/editado/vacío)
└── Configuracion/ConfiguracionVentasDepositoTest.php (nuevo o extendiendo el existente)
```

**Structure Decision**: Laravel monolítico estándar del proyecto — no hay separación
frontend/backend; se extiende el patrón ya usado por spec 030 (stock) y spec 043/044 (config de
defaults), sin introducir tablas ni controladores nuevos más allá de las migraciones de columnas.

## Complexity Tracking

*(Sin violaciones a justificar — no se agregan proyectos, capas ni patrones nuevos; se extiende
infraestructura existente.)*
