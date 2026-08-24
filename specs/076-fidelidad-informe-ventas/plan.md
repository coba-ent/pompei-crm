# Implementation Plan: Fidelidad del Informe de Ventas contra Contagram

**Branch**: `076-fidelidad-informe-ventas` | **Date**: 2026-08-24 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `specs/076-fidelidad-informe-ventas/spec.md`

## Summary

Tres cosas, en orden de riesgo decreciente:

1. **El importe por línea** deja de ser el total del comprobante repetido y pasa a ser el importe de
   esa línea con impuestos, en pantalla, export resumen, export detallado y PDF. El motor **ya
   calcula** esa columna (`total_venta`, creada por la spec 069 para el pivot); lo que falta es
   consumirla en los cuatro lugares y agregarle el prorrateo de los conceptos extra para que la
   suma cierre contra el total del comprobante.
2. **Un tercer botón de exportación** que genera el archivo detallado de 44 columnas, con el mismo
   motor de datos que la pantalla, en una sola hoja.
3. **Cuatro correcciones de contenido de columna** (tipo de operación, código del producto, sigla
   del comprobante, formato contable de negativos).

No hay entidades nuevas, ni tablas nuevas, ni migraciones.

## Technical Context

**Language/Version**: PHP 8.2 / Laravel 12

**Primary Dependencies**: Eloquent + Query Builder (el informe se arma con Query Builder, no con
modelos hidratados), `maatwebsite/excel` para los Excel, DomPDF para el PDF, DataTables server-side
en el cliente

**Storage**: MySQL. **Sin cambios de esquema**: todas las columnas nuevas del export se derivan de
datos que ya existen.

**Testing**: PHPUnit (`tests/Feature/Informes/`). La constitución exige tests porque esto es
cálculo de importes, IVA y descuentos.

**Target Platform**: aplicación web, un solo negocio

**Project Type**: web (Laravel monolito con Blade + Vite)

**Performance Goals**: exportar 5.000 líneas con 44 columnas sin degradar respecto del export
resumen actual, que ya recorre el detalle en chunks de 1.000

**Constraints**: la suma del importe de línea por comprobante tiene que dar el total del
comprobante **exacto**, no aproximado. El motor de datos es uno solo para pantalla, los dos Excel y
el PDF.

**Scale/Scope**: ~6.000 líneas de detalle por año; 4 salidas afectadas; 1 archivo de export nuevo

## Constitution Check

*GATE: revisado antes de Phase 0 y de nuevo después del diseño.*

| Principio | Estado | Nota |
|---|---|---|
| **I. Documentación de dominio como fuente de verdad** | ⚠️ **Se corrige** | La documentación afirma que la columna va "repetido por fila, no sumable" y está demostrado que es falso. FR-005 obliga a corregirla dejando registro de qué decía y por qué estaba mal. Es el caso previsto por el principio: la spec reveló que el doc estaba equivocado, y se actualiza en el mismo cambio. |
| **II. Desarrollo spec-driven** | ✅ | Esta spec precede al código. |
| **III. Corrección fiscal innegociable (ARCA)** | ✅ | El informe es de **sólo lectura**: no emite, no anula ni modifica comprobantes. Lee `comprobantes_fiscales` para mostrar estado, punto de venta y número. Riesgo fiscal nulo. |
| **IV. Testing donde hay dinero** | ✅ | Es exactamente el caso: importes, IVA y descuentos. Cubierto con tests de la suma por comprobante, del desglose impositivo y del prorrateo de conceptos. |
| **V. Convenciones Laravel + dominio en español** | ✅ | Se extienden clases existentes con la nomenclatura vigente. |

**Reglas de diseño obligatorias del proyecto**: la #1 (DataTables server-side) y la #4 (PDF en el
modal compartido) ya están satisfechas por el informe actual y no se alteran. El botón nuevo sigue
el patrón de los dos que ya existen.

**Sin violaciones que justificar.** No hay Complexity Tracking.

## Project Structure

### Documentation (this feature)

```text
specs/076-fidelidad-informe-ventas/
├── plan.md              # Este archivo
├── spec.md
├── research.md          # Phase 0
├── data-model.md        # Phase 1
├── quickstart.md        # Phase 1
├── contracts/
│   └── export-detallado.md
├── checklists/
│   └── requirements.md
└── tasks.md             # lo genera /speckit-tasks
```

### Source Code (repository root)

```text
app/
├── Services/Informes/
│   └── VentasInformeQuery.php          # + columnas del detallado; + prorrateo de conceptos en
│                                       #   total_venta; sigla del comprobante
├── Exports/Informes/
│   ├── InformeVentasExport.php         # importe de línea + sigla (conserva sus 2 hojas)
│   └── InformeVentasDetalladoExport.php # NUEVO — 1 hoja, 44 columnas
└── Http/Controllers/Informes/
    └── InformeVentasController.php     # + acción de export detallado

resources/
├── js/
│   └── informe-ventas.js               # columna total_venta; formato contable de negativos;
│                                       #   botón nuevo
└── views/informes/
    ├── ventas/index.blade.php          # botón "Exportar Excel Detallado"
    └── pdf/ventas.blade.php            # importe de línea

routes/web.php                          # + ruta del export detallado

tests/Feature/Informes/
├── InformeVentasTest.php               # se DA VUELTA el test del total repetido (research §R5)
├── InformeVentasImporteLineaTest.php   # NUEVO — suma por comprobante, conceptos, notas
└── InformeVentasDetalladoExportTest.php # NUEVO — 44 columnas, desglose impositivo

docs/
├── documentacion_principal_crm.md      # corrección de la afirmación falsa + 3er botón
└── modelo_datos.md                     # nota sobre el importe de línea derivado
```

**Structure Decision**: se extienden las clases existentes en lugar de crear un servicio nuevo. El
único archivo nuevo de producción es el export detallado, porque es un formato de salida distinto
(una hoja, 44 columnas) que no cabe en la clase del resumen sin volverla condicional. El motor de
datos sigue siendo uno solo (`VentasInformeQuery`), que es lo que garantiza SC-004.

## Orden de ejecución y por qué

El orden no es cosmético:

1. **Medir primero** si `SUM(total_venta)` cierra contra `ventas.total` sobre datos reales
   (research §R2). Determina si hace falta el prorrateo de conceptos o no. Hacerlo al revés
   significa escribir el prorrateo sin saber si resuelve algo.
2. **Importe de línea (US1)** antes que el export detallado, porque el detallado también lleva esa
   columna: si se hace después, hay que tocar el archivo nuevo dos veces.
3. **Export detallado (US2)** después, ya con la columna correcta.
4. **Correcciones de contenido (US3)** al final: son independientes y de bajo riesgo.

## Riesgos

| Riesgo | Mitigación |
|---|---|
| El join a `comprobantes_fiscales` multiplica filas y rompe todos los totales (una venta puede tener un rechazo y un reintento) | Subconsulta de una sola fila, nunca join directo — el mismo patrón que la proyección ya usa para las etiquetas (research §R3) |
| Los centavos del prorrateo no cierran contra el total | La última línea absorbe el residuo, como ya hacen los conversores de ML y Tiendanube |
| Cambiar `total_venta` rompe el motor de tablas dinámicas, que ya la consume | El cambio (sumarle los conceptos) va en la dirección correcta también para el pivot; se corren sus tests y se actualizan los valores esperados si se mueven (research §R7) |
| Un período grande con 44 columnas agota memoria | Se reusa el recorrido en chunks de 1.000 que ya tiene el export resumen |
| La suite verde no garantiza producción: los tests corren en SQLite y producción es MySQL con `ONLY_FULL_GROUP_BY` | Validación obligatoria en navegador antes de dar por cerrada la feature, contra el export real de Contagram |

## Complexity Tracking

Sin violaciones de la constitución. Sin dependencias nuevas. Sin cambios de esquema. Un solo archivo
de producción nuevo.
