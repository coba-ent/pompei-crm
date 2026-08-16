# Implementation Plan: Módulo Informes — Tanda 1 (Compras, Gastos, Cta Cte Proveedores)

**Branch**: `067-informes-compras-gastos-ctacte-proveedores` | **Date**: 2026-08-14 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/067-informes-compras-gastos-ctacte-proveedores/spec.md`

## Summary

Tres pantallas de informe **de sólo lectura**, cada una con su ítem propio en el submenú "Informes"
del sidebar y su URL real:

1. **`/informes/compras`** — KPIs con la ecuación Compras Creadas + ND − NC = Total Compras, tabla
   server-side con una fila por ítem de compra, panel de 12 filtros, y selector de columnas que
   expone el desglose impositivo AFIP completo **en pantalla** (divergencia deliberada: Contagram
   sólo lo vuelca al Excel).
2. **`/informes/gastos`** — total del período con agrupación Categoría → Subcategoría resuelta con
   DataTables RowGroup sobre un endpoint paginado, con subtotales calculados en el servidor.
3. **`/informes/cuenta-corriente-proveedores`** — espejo estructural del informe de Cta Cte Clientes
   (spec 029), con tabs Saldos / Movimientos, modal de ficha de sólo lectura, y deep-link desde el
   menú de fila de Compras (cierra el "Próximamente" de `documentacion_principal_crm.md §4.3`).

Los tres con exportación dual: **Excel de dos hojas** (formateada + plana) y **PDF en el modal
compartido**.

**Enfoque técnico**: cero migraciones, cero dependencias nuevas. Todo el desglose impositivo se
deriva de `compra_items.iva_pct` y `compra_conceptos.tipo` (research R6); el aging de proveedores
reutiliza `CuentaCorriente::porCliente('proveedor')`, que ya soporta ese caso, **sin tocar el
servicio** (R7). El único asset nuevo es la extensión RowGroup de DataTables.

## Technical Context

**Language/Version**: PHP 8.2, Laravel 12

**Primary Dependencies**: Eloquent + Query Builder; `yajra/laravel-datatables` (server-side);
`maatwebsite/excel ^3.1` (Excel de doble hoja, `WithMultipleSheets`); `barryvdh/laravel-dompdf ^3.1`
(PDF `inline`); front sobre el template NexaDash — jQuery, DataTables (+ Buttons, colVis, **RowGroup
a vendorizar**), Select2, Toastr, bootstrap-daterangepicker, moment.

**Storage**: MySQL. **Sin migraciones**: se lee de `compras`, `compra_items`, `compra_conceptos`,
`notas_credito_debito`, `pagos`, `gastos`, `categorias`, `proveedores`, `cuentas_tesoreria`,
`productos`, `tipos_producto`, `etiquetas`.

**Testing**: PHPUnit. Foco obligatorio (constitución IV) en los cálculos de dinero: ecuación de KPIs
de Compras, desglose de IVA por alícuota, clasificación de percepciones, subtotales de Gastos,
buckets de aging de proveedores y la invariante Saldos ↔ Movimientos.

**Target Platform**: aplicación web servida por Laravel (XAMPP local / VPS), navegadores de
escritorio.

**Project Type**: aplicación web monolítica Laravel + Blade (sin frontend separado).

**Performance Goals**: cada informe responde en < 3 s con 5.000 compras y 5.000 gastos en el rango
(SC-006). Paginación real en SQL para las tres tablas de detalle.

**Constraints**: ninguna operación recarga la página (regla obligatoria #2); toda tabla es DataTables
server-side (#1); toda notificación va por Toastr (#3); todo PDF por `window.AppPdf.abrir()` (#4);
todo select dinámico usa Select2 (#5). Sin cambios de esquema. `App\Services\Tesoreria\CuentaCorriente`
**no se modifica** — lo comparten el Dashboard y el informe de clientes ya en producción.

**Scale/Scope**: 3 pantallas nuevas, 3 controladores, ~8 endpoints JSON, 6 clases de export (3 Excel
+ 3 PDF), 3 bundles JS + 1 helper compartido, 1 modal nuevo, 3 entradas de sidebar.

## Constitution Check

*GATE: evaluado antes de Phase 0 y revalidado tras Phase 1.*

| Principio | Estado | Nota |
|-----------|--------|------|
| **I. Documentación de dominio como fuente de verdad** | ✅ PASS | Se leyeron `documentacion_principal_crm.md` §4.1, §4.2, §4.3, §6.4 y `modelo_datos.md` antes de especificar. Antes de `/speckit-tasks` hay que agregar a `documentacion_principal_crm.md` las secciones de los tres informes nuevos y **cerrar** la brecha "Cta Cte de proveedores" que §4.3 y §6.4 declaran abierta. Tarea explícita en `tasks.md`. |
| **II. Desarrollo spec-driven** | ✅ PASS | Es una feature de negocio y tiene su spec, plan y tasks antes del código. |
| **III. Corrección fiscal innegociable (ARCA)** | ✅ PASS (con cuidado) | Los informes **no emiten** nada: son de lectura. Pero muestran importes fiscales, así que aplica: (a) se respeta el soft delete — nada eliminado aparece ni suma (FR-021); (b) el desglose de IVA por alícuota debe reconstruir exactamente el total del comprobante, con test; (c) las NC/ND se calculan con la misma fórmula que las compras normales, sin ramas por tipo (FR-016) — el bug de signos que el relevamiento encontró en Contagram no se replica. |
| **IV. Testing donde hay dinero o impacto fiscal** | ✅ PASS | Todo el cálculo de esta spec es dinero. Tests obligatorios listados en `quickstart.md` §Tests. Ningún informe se da por terminado sin ellos en verde. |
| **V. Convenciones Laravel + dominio en español** | ✅ PASS | Rutas, controladores, vistas y UI en español; controladores bajo `App\Http\Controllers\Informes\` como los ya existentes; sin `empresa_id` (single-tenant). |

**Especificaciones de diseño obligatorias de CLAUDE.md**: las cinco se contemplan explícitamente en
Technical Context → Constraints y se verifican en `quickstart.md`.

**Violaciones que requieran justificación**: ninguna. La sección "Complexity Tracking" queda vacía a
propósito.

**Re-evaluación post Phase 1**: sin cambios. El diseño no introdujo migraciones, dependencias ni
patrones fuera de los ya usados en el proyecto; la única incorporación es un asset front (RowGroup)
de la misma familia de extensiones DataTables ya vendorizadas.

## Project Structure

### Documentation (this feature)

```text
specs/067-informes-compras-gastos-ctacte-proveedores/
├── plan.md              # Este archivo
├── spec.md              # Especificación funcional
├── research.md          # Phase 0 — R1..R10
├── data-model.md        # Phase 1 — entidades leídas y derivaciones
├── quickstart.md        # Phase 1 — guía de validación
├── contracts/
│   └── endpoints.md     # Phase 1 — contrato de rutas y payloads
├── checklists/
│   └── requirements.md  # Checklist de calidad de la spec
└── tasks.md             # Phase 2 — generado por /speckit-tasks
```

### Source Code (repository root)

```text
app/
├── Http/Controllers/Informes/
│   ├── CuentaCorrienteController.php              # EXISTE (clientes) — no se toca
│   ├── InformeStockController.php                 # EXISTE — no se toca
│   ├── InformeComprasController.php               # NUEVO
│   ├── InformeGastosController.php                # NUEVO
│   └── CuentaCorrienteProveedorController.php     # NUEVO
├── Services/Informes/
│   ├── ComprasInformeQuery.php                    # NUEVO — query base + filtros + KPIs
│   ├── DesgloseImpositivoCompra.php               # NUEVO — IVA por alícuota + clasif. percepciones (R6)
│   └── GastosInformeQuery.php                     # NUEVO — query agrupada + subtotales
├── Services/Tesoreria/
│   └── CuentaCorriente.php                        # EXISTE — SE REUTILIZA, NO SE MODIFICA (R7)
└── Exports/Informes/
    ├── InformeComprasExport.php                   # NUEVO — WithMultipleSheets
    ├── InformeGastosExport.php                    # NUEVO — WithMultipleSheets
    └── CuentaCorrienteProveedorExport.php         # NUEVO — WithMultipleSheets

resources/
├── views/informes/
│   ├── compras/index.blade.php                    # NUEVO
│   ├── gastos/index.blade.php                     # NUEVO
│   ├── cuenta-corriente-proveedores/
│   │   ├── index.blade.php                        # NUEVO
│   │   └── _modal_ficha.blade.php                 # NUEVO — ficha sólo lectura (R9)
│   └── pdf/
│       ├── compras.blade.php                      # NUEVO
│       ├── gastos.blade.php                       # NUEVO
│       └── cuenta-corriente-proveedores.blade.php # NUEVO
└── js/
    ├── rango-emision.js                           # NUEVO — helper compartido de las 9 opciones (R1)
    ├── informe-compras.js                         # NUEVO
    ├── informe-gastos.js                          # NUEVO
    └── informe-cuenta-corriente-proveedores.js    # NUEVO

resources/views/elements/sidebar.blade.php         # MODIFICAR — 3 entradas nuevas + renombrar la de clientes
resources/js/compras.js                            # MODIFICAR — habilitar "Cta Cte" del menú de fila (R10)
config/dz.php                                      # MODIFICAR — pagelevel de los 3 informes
routes/web.php                                     # MODIFICAR — rutas nuevas
public/vendor/datatables/                          # AGREGAR — dataTables.rowGroup (JS + CSS)

tests/Feature/Informes/
├── InformesAccesoTest.php                         # NUEVO — rutas y permisos
├── InformeComprasTest.php                         # NUEVO
├── InformeComprasDesgloseImpositivoTest.php       # NUEVO
├── InformeGastosTest.php                          # NUEVO
├── CuentaCorrienteProveedorTest.php               # NUEVO
├── InformesExportTest.php                         # NUEVO
└── InformesConciliacionTest.php                   # NUEVO — totales vs. pantallas de origen (SC-004)
```

**Structure Decision**: se sigue la estructura Laravel ya vigente. Los tres controladores van al
namespace `App\Http\Controllers\Informes\`, donde ya viven los dos informes existentes. La lógica de
consulta y cálculo se aísla en `App\Services\Informes\` (en vez de engordar los controladores) para
que los tests de dinero — exigidos por el principio IV — puedan ejercitar el cálculo sin pasar por
HTTP, igual que hacen los tests del servicio de Cuenta Corriente.

## Fases de implementación

| Fase | Contenido | Historia de la spec |
|------|-----------|---------------------|
| **F1** | Andamiaje común: helper `rango-emision.js`, vendorizar RowGroup, pagelevel de `config/dz.php`, rutas, 3 entradas de sidebar | FR-001 a FR-009 |
| **F2** | Informe de Compras: query base, filtros, KPIs, desglose impositivo, tabla + colvis | US1 (P1) |
| **F3** | Informe de Gastos: query agrupada, subtotales server-side, RowGroup | US2 (P2) |
| **F4** | Cta Cte Proveedores: tabs, saldos vía servicio existente, movimientos UNION, modal de ficha, deep-link desde Compras | US3 (P2) |
| **F5** | Exportación: 3 clases Excel de doble hoja + 3 vistas PDF en el modal compartido | US4 (P3) |
| **F6** | Actualización de `documentacion_principal_crm.md` (secciones nuevas + cierre de las brechas §4.3/§6.4) | Principio I |

Cada fase entrega una pantalla usable de punta a punta: F2, F3 y F4 son independientes entre sí y
pueden implementarse y validarse por separado.

## Riesgos y mitigaciones

| Riesgo | Mitigación |
|--------|------------|
| Tocar `CuentaCorriente` rompe Dashboard e informe de clientes en producción | Regla dura de esta spec: el servicio no se modifica (R7). Los tests existentes `CuentaCorrientePorClienteTest` y `CuentaCorrienteSaldoInicialTest` deben seguir verdes sin cambios. |
| "Total Comprobante" repetido en cada fila de ítem se suma de más en los KPIs | Los KPIs se calculan en una query aparte agrupada por comprobante, nunca sumando la columna de la tabla de detalle. Test dedicado con una compra de varios ítems. |
| Percepción no clasificable imputada a la columna equivocada | Tercera columna "Otras Percepciones" (FR-015b) y test de que la suma de las tres iguala el total de percepciones. |
| El PDF de un período grande revienta memoria | Tope de filas de detalle en el PDF + leyenda que remite al Excel (R5). |
| El tab Saldos de proveedores hereda el problema de memoria del de clientes | Se hereda a conciencia, documentado en R7; no se agrava. Queda anotado en la doc de dominio como la misma brecha, ahora en dos pantallas. |

## Complexity Tracking

Sin violaciones a la constitución. Tabla no aplica.
