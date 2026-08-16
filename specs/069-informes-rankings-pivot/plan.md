# Implementation Plan: Módulo Informes — Tanda 3 (Rankings, Arma tu Informe)

**Branch**: `069-informes-rankings-pivot` | **Date**: 2026-08-16 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/069-informes-rankings-pivot/spec.md`

## Summary

Se agrega una barra de pestañas al Informe de Ventas y al Informe de Compras con dos pestañas
nuevas —**Rankings** (5 vistas en Ventas, 4 en Compras) y **Arma tu Informe** (builder libre)— más
una pestaña por cada vista que el usuario guarde. Las tres son la misma pieza: una **tabla dinámica**
(PivotTable.js, renderer "Table" únicamente) sobre un dataset proyectado que el servidor entrega ya
filtrado con el mismo criterio que el detalle de cada informe, para que sus totales concilien al
centavo con los KPIs ya existentes.

**Enfoque técnico**: el cruce se arma y recalcula **en el cliente** (arrastrar una dimensión no va al
servidor); el servidor sólo entrega el dataset proyectado y, al exportar, escribe en Excel la matriz
que el cliente ya calculó. Una única migración (`informes_vistas`) persiste las vistas guardadas como
configuración de cruce, no como datos. Se reutilizan `VentasInformeQuery` y `ComprasInformeQuery` de
las tandas 1 y 2, ampliando su proyección; no se tocan sus fórmulas de KPIs ni sus exports existentes.

**El recorte de "Mostrar Como" es estructural, no una opción escondida**: sólo se vendoriza el
renderer "Table" de PivotTable.js. Los 7 modos descartados (mapas de calor, gráficos, histograma) no
existen en el bundle.

## Technical Context

**Language/Version**: PHP 8.2, Laravel 12; JavaScript (jQuery) en el front.

**Primary Dependencies**: Eloquent + Query Builder; `maatwebsite/excel ^3.1` (reutiliza
`HojaInforme`); front sobre NexaDash — jQuery, Select2, Toastr, bootstrap-daterangepicker.
**Dependencia nueva**: `pivottable` (PivotTable.js + su dependencia `jquery-ui` para el drag & drop),
vendorizada en `public/vendor/pivottable/` con **sólo el renderer Table** cargado (research R1).

**Storage**: MySQL. **Una migración**: `informes_vistas` (id, informe enum, descripción, `config`
JSON, `creado_por_id`, timestamps — sin soft delete, ver research R4). El dataset se lee de las
mismas tablas que las tandas 1 y 2 (`ventas`, `venta_items`, `notas_credito_debito*`, `compras`,
`compra_items`, `categorias`, `productos`, `tipos_producto`, `proveedores`, `vendedores`,
`etiquetas`).

**Testing**: PHPUnit para el dataset, los filtros y la CRUD de vistas guardadas (constitución IV: hay
dinero en las medidas del cruce). El drag & drop y el render del pivot en sí son de PivotTable.js
—librería de terceros— y no se testean unitariamente; se verifican con `quickstart.md` en el
navegador.

**Target Platform**: aplicación web servida por Laravel, navegadores de escritorio.

**Project Type**: aplicación web monolítica Laravel + Blade.

**Performance Goals**: dataset del pivot en menos de 3 s para un año de operación (SC-003); rearmar
el cruce en el cliente, imperceptible (SC-002).

**Constraints**: dataset del servidor topeado en 50.000 filas (research R2); render de pivot topeado
en 1.000 columnas (FR-019b, research R8); ninguna interacción de pivot va a red; cero recargas de
página al cambiar de pestaña, filtro o rango; Toastr para avisos; Select2 en los selects de catálogo
que ya trae cada informe (no hay selects nuevos de catálogo en esta feature).

**Scale/Scope**: 2 pantallas existentes ganan pestañas; 4 controladores nuevos (Rankings Ventas,
Rankings Compras, Vistas Ventas, Vistas Compras) o unificados en 2 si el plan de rutas lo permite
(ver Project Structure); 1 migración; 2 servicios de dataset nuevos; ampliación de 2 queries
existentes; 1 bundle JS de pivot compartido + 2 bundles de wiring por informe.

## Constitution Check

*GATE: evaluado antes de Phase 0 y revalidado tras Phase 1.*

| Principio | Estado | Nota |
|-----------|--------|------|
| **I. Documentación de dominio como fuente de verdad** | ✅ PASS | Se leyó `documentacion_principal_crm.md` §6 (ya anota la tanda 3 con su alcance acotado, actualizado el 15/08 al cerrar la tanda 2) y `modelo_datos.md`. Antes de `/speckit-tasks` hay que documentar ahí la tabla `informes_vistas` y el mapeo de dimensiones de Compras (research R9). |
| **II. Desarrollo spec-driven** | ✅ PASS | Spec → clarify → plan antes de código. |
| **III. Corrección fiscal innegociable (ARCA)** | ✅ PASS | La feature no emite ni modifica comprobantes. El dataset respeta borrado lógico (hereda el filtro de `VentasInformeQuery`/`ComprasInformeQuery`). `informes_vistas` no es documento fiscal: no lleva soft delete (justificado en research R4), y esa es la única tabla que esta spec escribe. |
| **IV. Testing donde hay dinero o impacto fiscal** | ✅ PASS | Las medidas del cruce (Total Venta, Total Venta sin impuestos, conteos) son dinero: tests obligatorios sobre el dataset y sus filtros. El drag & drop de PivotTable.js no es lógica de negocio propia — no lleva test unitario, sí quickstart manual. |
| **V. Convenciones Laravel + dominio en español** | ✅ PASS | Tabla `informes_vistas`, enum `informe` en español, controladores bajo `App\Http\Controllers\Informes\`, sin `empresa_id`. |

**Especificaciones de diseño obligatorias de CLAUDE.md**:

1. *Tablas server-side*: el pivot **no** es DataTables — es la misma excepción ya registrada para el
   Reporte Final en la tanda 2 (Complexity Tracking), por el mismo motivo: es un agregado interactivo
   que debe recalcular en el cliente, no un listado paginado.
2. *Cero recargas*: pestañas, drag & drop, cambio de Dato/Acción y guardado de vistas son todos AJAX.
3. *Toastr*: para errores de rango, dataset excedido y guardado de vistas.
4. *Modal PDF*: no aplica — esta feature no genera PDF (Contagram tampoco lo ofrece para pivots).
5. *Select2*: no introduce selects de catálogo nuevos; reutiliza el panel de filtros ya existente de
   cada informe.

**Fidelidad estructural (regla de oro de CLAUDE.md)**: la única divergencia funcional del módulo
—"Mostrar Como" fijo en Tabla, sin `/graphs`— está declarada y motivada en la spec como decisión
explícita y reafirmada del cliente, no como simplificación de costo. El resto (drag & drop, los 3
selectores, el pool de 13 dimensiones, el guardado como pestaña persistente) calca la estructura
relevada.

**Re-evaluación post Phase 1**: sin cambios. El diseño no introdujo más migraciones ni patrones
nuevos de los ya declarados acá.

## Project Structure

### Documentation (this feature)

```text
specs/069-informes-rankings-pivot/
├── plan.md              # Este archivo
├── spec.md              # Especificación funcional
├── research.md          # Phase 0 — R1..R10
├── data-model.md         # Phase 1 — entidad nueva + dataset proyectado
├── quickstart.md         # Phase 1 — guía de validación
├── contracts/
│   └── endpoints.md      # Phase 1 — contrato de rutas y payloads
├── checklists/
│   ├── requirements.md   # Checklist de calidad de la spec
│   └── fidelidad.md      # Checklist de fidelidad y recorte (generado por /speckit-checklist)
└── tasks.md               # Phase 2 — generado por /speckit-tasks
```

### Source Code (repository root)

```text
app/
├── Http/Controllers/Informes/
│   ├── InformeVentasController.php            # EXISTE (tanda 2) — se le agregan acciones de pestañas
│   ├── InformeComprasController.php            # EXISTE (tanda 1) — ídem
│   └── InformesVistasController.php            # NUEVO — CRUD de vistas guardadas (ambos informes)
├── Models/
│   └── InformeVista.php                        # NUEVO — Eloquent sobre informes_vistas
├── Services/Informes/
│   ├── VentasInformeQuery.php                  # EXISTE — se AMPLÍA la proyección (research R5)
│   ├── ComprasInformeQuery.php                 # EXISTE — ídem
│   ├── VentasPivotDataset.php                  # NUEVO — dataset proyectado + medidas de Ventas
│   ├── ComprasPivotDataset.php                 # NUEVO — ídem Compras
│   └── DimensionesPivot.php                    # NUEVO — catálogo de las 13(+) dimensiones por informe (research R9)
└── Exports/Informes/
    └── PivotExport.php                         # NUEVO — escribe la matriz recibida del cliente (HojaInforme, doble hoja)

database/migrations/
└── 2026_08_16_xxxxxx_create_informes_vistas_table.php   # NUEVO — única migración

resources/
├── js/
│   ├── informes-pivot.js                       # NUEVO — wrapper de PivotTable.js: recorte de renderer, tope de columnas, Accion↔Dato, export
│   ├── informe-ventas.js                        # EXISTE — se le agrega el wiring de pestañas
│   └── informe-compras.js                       # EXISTE — ídem
└── views/informes/
    ├── ventas/index.blade.php                   # EXISTE — se le agrega la barra de pestañas
    ├── compras/index.blade.php                   # EXISTE — ídem
    └── partials/
        └── pivot.blade.php                       # NUEVO — markup compartido del contenedor de pivot + 3 selectores + modal Guardar

public/vendor/pivottable/
├── pivot.min.js                                  # NUEVO — vendorizado, sólo core + renderer Table
├── pivot.min.css
└── jquery-ui.min.js                              # NUEVO — dependencia del drag & drop

config/dz.php                                      # + pagelevel de assets de pivot para informe-ventas / informe-compras
routes/web.php                                      # + rutas de dataset/export/vistas bajo permiso informes.ver
vite.config.js                                       # + informes-pivot.js

tests/Feature/Informes/
├── VentasPivotDatasetTest.php                    # NUEVO — dataset, medidas, signos de NC/ND, borrado lógico
├── ComprasPivotDatasetTest.php                   # NUEVO — ídem Compras
├── InformesVistasTest.php                        # NUEVO — CRUD de vistas guardadas, pertenencia por informe, permiso
└── PivotExportTest.php                           # NUEVO — el Excel reproduce la matriz recibida
```

**Structure Decision**: se extienden los dos controladores de informe existentes en vez de crear
controladores de "Rankings" separados, porque Rankings no tiene estado propio server-side — es el
mismo dataset con una dimensión inicial distinta, resuelta en el cliente (research R1, R6). Sólo las
vistas guardadas necesitan su propio controlador, porque son la única escritura de la feature.

## Complexity Tracking

*Única desviación de las reglas obligatorias de CLAUDE.md, heredada del mismo caso ya aceptado en la
tanda 2.*

| Regla | Desviación | Por qué es necesaria | Alternativa descartada |
|-------|-----------|----------------------|------------------------|
| #1 — "toda tabla es DataTables server-side" | El pivot de Rankings / Arma tu Informe **no** usa DataTables. | Es un agregado interactivo (arrastrar dimensiones, cambiar Dato/Acción) que debe recalcular en memoria del cliente sin ida y vuelta al servidor (FR-011, SC-002) — el mismo argumento ya aceptado para el Reporte Final en `specs/068-.../plan.md`. | Recalcular en el servidor a cada arrastre: rompe la instantaneidad exigida y multiplica los viajes de red por cada gesto del usuario. |

El detalle de cada informe (la pestaña que no es Rankings ni Arma tu Informe) sigue siendo DataTables
server-side, sin cambios: esta spec no la toca.
