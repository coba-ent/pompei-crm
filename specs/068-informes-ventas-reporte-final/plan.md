# Implementation Plan: Módulo Informes — Tanda 2 (Ventas, Reporte Final)

**Branch**: `068-informes-ventas-reporte-final` | **Date**: 2026-08-15 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/068-informes-ventas-reporte-final/spec.md`

## Summary

Dos pantallas de informe **de sólo lectura**, cada una con su ítem propio en el submenú "Informes"
del sidebar y su URL real:

1. **`/informes/ventas`** — espejo estructural del Informe de Compras de la Tanda 1: tres bloques de
   KPIs (`Ventas Creadas + ND − NC = Total Ventas`; cantidades/promedio/costo; `Precio Neto − CMV =
   Resultado`), tabla server-side con **una fila por ítem** (ventas + notas vía `UNION ALL`), panel
   de 19 filtros y export dual (Excel de dos hojas + PDF en el modal compartido).
2. **`/informes/reporte-final`** — árbol agregado de resultado del período en dos vistas: **Ventas
   Vs. Compras** (devengado) y **Cobros Vs Pagos** (caja, con un nivel extra por Cuenta de
   Tesorería), con el **simulador "Activo"** que recalcula Ingresos/Egresos/Resultado en el cliente
   al destildar categorías, y export dual que respeta el escenario simulado.

**Enfoque técnico**: cero migraciones, cero dependencias nuevas. El detalle de Ventas reusa
literalmente el patrón de `ComprasInformeQuery` (proyección homogénea + `unionAll` + `fromSub`); el
Reporte Final se resuelve con 4 queries agregadas (`GROUP BY`) por vista, no con tabla de detalle. El
CMV, que el CRM no guarda históricamente, se deriva de un promedio ponderado de compras por producto
calculado en SQL (research R2). Se reutilizan `rango-emision.js`, `HojaInforme` y
`resources/views/informes/pdf/_estilos.blade.php` de la Tanda 1.

**Las dos réplicas de bugs de origen (R1/R2 de la spec) viven exclusivamente en las clases de
export**, nunca en el servicio de cálculo: la pantalla y la hoja plana siempre usan la fórmula
correcta. Es la única forma de que la fidelidad pedida no contamine el modelo.

## Technical Context

**Language/Version**: PHP 8.2, Laravel 12

**Primary Dependencies**: Eloquent + Query Builder; `yajra/laravel-datatables` (server-side, sólo
Ventas); `maatwebsite/excel ^3.1` (`WithMultipleSheets`); `barryvdh/laravel-dompdf ^3.1` (PDF
`inline`); front sobre NexaDash — jQuery, DataTables, Select2, Toastr, bootstrap-daterangepicker,
moment. **Ninguna dependencia nueva.**

**Storage**: MySQL. **Sin migraciones**: se lee de `ventas`, `venta_items`, `venta_conceptos`,
`notas_credito_debito`, `notas_credito_debito_items`, `cobros`, `pagos`, `compras`, `compra_items`,
`gastos`, `otros_ingresos`, `categorias`, `cuentas_tesoreria`, `clientes`, `productos`,
`tipos_producto`, `etiquetas`, `vendedores`, `remitos`, `transportistas`, `users`.

**Testing**: PHPUnit. Foco obligatorio (constitución IV) en dinero: ecuación de KPIs de Ventas,
`Result. = Precio Neto − CMV` en pantalla, promedio ponderado del CMV, conciliación devengado ↔ caja
del Reporte Final, y **dos tests dedicados a las réplicas R1/R2** que fijan por escrito que la
desviación existe sólo en el Excel y no se propaga a totales.

**Target Platform**: aplicación web servida por Laravel (XAMPP local / VPS), navegadores de escritorio.

**Project Type**: aplicación web monolítica Laravel + Blade.

**Performance Goals**: < 3 s con 5.000 ventas y sus notas en el rango (SC-002). Paginación real en
SQL en el detalle de Ventas; el Reporte Final agrega en SQL y devuelve decenas de filas, no miles.

**Constraints**: ninguna operación recarga la página (regla #2); toda tabla de listado es DataTables
server-side (#1, ver excepción justificada en Complexity Tracking); Toastr para avisos (#3);
`window.AppPdf.abrir()` para PDFs (#4); Select2 en todo select dinámico (#5). Sin cambios de esquema.
`ComprasInformeQuery` y `GastosInformeQuery` **no se modifican**: el Reporte Final lee sus propias
agregaciones, para no acoplar dos informes con reglas distintas (devengado vs. caja).

**Scale/Scope**: 2 pantallas nuevas, 2 controladores, ~9 endpoints JSON, 4 clases de export (2 Excel
+ 2 PDF), 2 bundles JS, 2 entradas de sidebar, 0 migraciones.

## Constitution Check

*GATE: evaluado antes de Phase 0 y revalidado tras Phase 1.*

| Principio | Estado | Nota |
|-----------|--------|------|
| **I. Documentación de dominio como fuente de verdad** | ✅ PASS | Se leyeron `documentacion_principal_crm.md` (§4 Ingresos/Egresos, §6 Informes) y `modelo_datos.md` antes de especificar. Antes de `/speckit-tasks` hay que documentar ahí los dos informes nuevos, la definición de CMV adoptada y la brecha "3 filtros de Ventas no identificados (19 de 22)". Tarea explícita en `tasks.md`. |
| **II. Desarrollo spec-driven** | ✅ PASS | Feature de negocio con spec, plan y tasks antes del código. |
| **III. Corrección fiscal innegociable (ARCA)** | ⚠️ PASS con condición | Los informes **no emiten** nada (sólo lectura), pero muestran importes fiscales. Condiciones: (a) el borrado lógico se respeta en todas las queries (FR-009); (b) la pantalla y la hoja plana calculan NC/ND con la **misma** fórmula que las ventas, sin ramas por tipo; (c) la réplica R1 queda **confinada** a una celda de la hoja legible del Excel, marcada en el código con un comentario que la señala como réplica deliberada y con un test que verifica que no se propaga a ningún total. Sin ese confinamiento, la decisión del usuario chocaría con este principio. |
| **IV. Testing donde hay dinero o impacto fiscal** | ✅ PASS | Todo el cálculo de esta spec es dinero. Tests listados en `quickstart.md` §Tests; ningún informe se cierra sin ellos en verde. |
| **V. Convenciones Laravel + dominio en español** | ✅ PASS | Rutas, controladores, vistas y UI en español; controladores bajo `App\Http\Controllers\Informes\`; sin `empresa_id` (single-tenant). |

**Especificaciones de diseño obligatorias de CLAUDE.md**: las cinco se contemplan en Technical
Context → Constraints y se verifican en `quickstart.md`. La única desviación (regla #1 en el Reporte
Final) está justificada abajo.

**Fidelidad estructural (regla de oro de CLAUDE.md)**: las tres divergencias respecto de Contagram
—sin landing de tarjetas, Excel de doble hoja, y sin pestañas Rankings/Arma tu Informe— están
declaradas y motivadas en la spec. Ninguna otra pantalla se simplifica.

**Re-evaluación post Phase 1**: sin cambios. El diseño no introdujo migraciones, dependencias ni
patrones nuevos.

## Project Structure

### Documentation (this feature)

```text
specs/068-informes-ventas-reporte-final/
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
│   ├── InformeComprasController.php          # EXISTE (Tanda 1) — referencia, no se toca
│   ├── InformeVentasController.php           # NUEVO
│   └── ReporteFinalController.php            # NUEVO
├── Services/Informes/
│   ├── ComprasInformeQuery.php               # EXISTE — patrón a espejar, NO se modifica
│   ├── ExpresionSql.php                      # EXISTE — se reutiliza
│   ├── VentasInformeQuery.php                # NUEVO — detalle (UNION), filtros, KPIs
│   ├── CostoMercaderiaVendida.php            # NUEVO — promedio ponderado por producto (R2)
│   └── ReporteFinalQuery.php                 # NUEVO — árbol devengado + árbol caja
└── Exports/Informes/
    ├── HojaInforme.php                       # EXISTE — se reutiliza
    ├── InformeVentasExport.php               # NUEVO — 2 hojas; aloja la réplica R1
    └── ReporteFinalExport.php                # NUEVO — 2 hojas; aloja la réplica R2

resources/
├── js/
│   ├── rango-emision.js                      # EXISTE — se reutiliza tal cual
│   ├── informe-ventas.js                     # NUEVO
│   └── reporte-final.js                      # NUEVO
└── views/informes/
    ├── ventas/index.blade.php                # NUEVO
    ├── reporte-final/index.blade.php         # NUEVO
    └── pdf/
        ├── _estilos.blade.php                # EXISTE — se reutiliza
        ├── ventas.blade.php                  # NUEVO
        └── reporte-final.blade.php           # NUEVO

routes/web.php                                # + 9 rutas bajo permiso `informes.ver`
resources/views/elements/sidebar.blade.php    # + 2 ítems en el desplegable Informes
vite.config.js                                # + 2 entradas de bundle
config/dz.php                                 # + pagelevel de assets de las 2 pantallas
tests/Feature/Informes/
├── InformeVentasTest.php                     # NUEVO
├── InformeVentasCmvTest.php                  # NUEVO
├── ReporteFinalTest.php                      # NUEVO
├── ReporteFinalSimuladorTest.php             # NUEVO
└── ReplicasContagramTest.php                 # NUEVO — fija R1 y R2 y su no-propagación
```

**Structure Decision**: monolito Laravel + Blade existente. Se replica exactamente la disposición de
archivos que dejó la Tanda 1 para que los siete informes del módulo se lean igual.

## Complexity Tracking

*Única desviación de las reglas obligatorias de CLAUDE.md, con su justificación.*

| Regla | Desviación | Por qué es necesaria | Alternativa descartada |
|-------|-----------|----------------------|------------------------|
| #1 — "toda tabla es DataTables server-side" | El **Reporte Final** no usa DataTables: renderiza un árbol expandible de bloques → categorías → (subcategorías) → cuentas de tesorería, alimentado por un único endpoint JSON. | No es un listado: es un agregado de decenas de filas con jerarquía de 3-4 niveles, subtotales por bloque y checkboxes de simulación que deben recalcular **en el cliente, sin red** (FR-034). Paginar o filtrar por servidor rompería el simulador y la lectura del árbol. | Forzar RowGroup como en Gastos: ahí había miles de filas de detalle que paginar; acá no hay detalle, sólo totales, y el checkbox de simulación no tiene lugar en el modelo de RowGroup. |

El detalle del **Informe de Ventas** sí es DataTables server-side, sin excepción.
