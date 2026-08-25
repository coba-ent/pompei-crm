# Implementation Plan: Información para tu Contador (Libro IVA Ventas / Compras)

**Branch**: `077-informe-contador-iva` | **Date**: 2026-08-24 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `specs/077-informe-contador-iva/spec.md`

---

## Summary

Nueva pantalla de **sólo lectura** que arma el **Libro IVA Ventas** y el **Libro IVA Compras** de un
período mes/año, con el desglose impositivo completo por comprobante (netos por clase, IVA por alícuota,
percepciones), una barra de cinco totales cuya ecuación cierra exacta, y exportación a Excel. Calca la
pantalla `/accountant_reports` de Contagram relevada con capturas el 24/08/2026.

**Enfoque técnico**: no se crea ninguna tabla ni migración. Se agrega un servicio de query que **agrega a
nivel comprobante** (a diferencia de los informes de Ventas y Compras, que trabajan a nivel ítem)
reutilizando las expresiones SQL de `DesgloseImpositivoVenta` / `DesgloseImpositivoCompra` para que la
clasificación fiscal no pueda divergir entre informes. El período se resuelve con una expresión distinta
según el origen de la fila (venta → emisión, compra → mes de imputación con respaldo, NC/ND → su propio
mes de imputación). Los datos van por **POST** para no repetir el 414 que rompió el informe de Compras en
producción.

---

## Technical Context

**Language/Version**: PHP 8.2 / Laravel 12

**Primary Dependencies**: Eloquent + Query Builder, `yajra/laravel-datatables` (server-side),
`maatwebsite/excel` (export), Blade sobre template NexaDash, jQuery + DataTables + Select2 + Toastr en el
front

**Storage**: MySQL / MariaDB. **Sin migraciones**: el informe es de sólo lectura sobre el esquema
existente

**Testing**: PHPUnit (Feature + Unit). Foco obligatorio en la ecuación de totales y en la resolución del
período (constitución, principio IV)

**Target Platform**: aplicación web, servida por Nginx + PHP-FPM en el VPS

**Project Type**: aplicación web monolítica (Laravel + Blade)

**Performance Goals**: volumen real del negocio ≈ 30 comprobantes de venta y 20 de compra por mes; el
informe pagina en SQL y los totales salen de una query agregada. Sin degradación perceptible en períodos
de un mes

**Constraints**:
- La ecuación `Total Facturado = suma de los otros cuatro` debe cerrar **exacta**, sin tolerancia
- El endpoint de datos no puede depender del tamaño de buffer de Nginx (lección del 414 del 24/08/2026)
- La clasificación impositiva no puede duplicarse: se reutilizan los servicios existentes

**Scale/Scope**: 1 pantalla, 2 pestañas, 19 columnas, 8 filtros, 7 endpoints, 0 migraciones

---

## Constitution Check

*GATE: revisado antes de Phase 0 y de nuevo después del diseño de Phase 1.*

| Principio | Estado | Justificación |
|---|---|---|
| **I. Documentación de dominio como fuente de verdad** | ✅ | La spec se basa en el relevamiento con capturas (`docs/informe_contagram_contador/`) y en `modelo_datos.md`. El plan detectó dos correcciones que se aplicaron a los documentos antes de seguir: (a) el hub de Informes tiene **9** tarjetas, no 8 como decía el relevamiento del 14/08; (b) `notas_credito_debito.mes_imputacion` (spec 045) es consumido por este informe, algo que ningún doc registraba. Ambas se reflejan en `documentacion_principal_crm.md` y `modelo_datos.md` antes de `/speckit-tasks`. |
| **II. Desarrollo spec-driven** | ✅ | Cadena completa corrida antes de escribir código: specify → clarify → plan → checklist → tasks → analyze. |
| **III. Corrección fiscal innegociable (ARCA)** | ✅ | El informe **no emite** comprobantes: sólo los lee. Respeta la regla de que sin CAE aprobado nada es firme (FR-015/FR-016) y usa `EXISTS` sobre `estado = 'aprobado'`, evitando el bug documentado del `morphOne` (Venta 24447). Los comprobantes con borrado lógico quedan fuera, preservando la trazabilidad. |
| **IV. Testing donde hay dinero o impacto fiscal** | ✅ | Es un informe **enteramente** de dinero e impacto fiscal. El servicio de query vive fuera del controlador justamente para poder testear los importes sin HTTP. La batería mínima está enumerada en `quickstart.md`, encabezada por la ecuación de totales y la resolución del período. |
| **V. Convenciones Laravel + dominio en español** | ✅ | `LibroIvaQuery`, `InformeContadorController`, rutas `informes/contador/...`, vistas `informes/contador/`. Single-tenant, sin `empresa_id`. Servicios en `app/Services/Informes/`, siguiendo el patrón ya establecido por las specs 067/068/069. |

### Reglas de diseño obligatorias de `CLAUDE.md` verificadas

| Regla | Cómo se cumple |
|---|---|
| #1 DataTables server-side por AJAX | Tabla server-side, paginación en SQL |
| #2 Sin recarga de página | Todo por AJAX: pestaña, período, filtros, columnas (FR-004) |
| #3 Toastr | Errores `422` en JSON mostrados por toast, sin flash ni recarga |
| #4 PDFs en el modal compartido | **No aplica**: esta spec no genera PDF (las capturas no muestran botón de PDF) |
| #5 Select2 en selects dinámicos | Cliente, Proveedor, Tipo de Comprobante, Condición de IVA, Medio de Cobro/Pago y Provincia. Cliente/Proveedor con `ajax` por catálogo grande |
| #6 Nunca `input type="date"` | El período son dos `<select>` Mes/Año — que además es lo que muestran las capturas. No hay ningún input de fecha en esta pantalla |

**Resultado del gate (post-Phase 1)**: ✅ **sin violaciones**. La sección "Complexity Tracking" queda
vacía a propósito.

---

## Project Structure

### Documentation (this feature)

```text
specs/077-informe-contador-iva/
├── plan.md                     # Este archivo
├── spec.md                     # Qué y por qué
├── research.md                 # 12 decisiones técnicas
├── data-model.md               # Derivación de columnas (sin migraciones)
├── quickstart.md               # 8 escenarios de validación + tests esperados
├── contracts/
│   └── endpoints.md            # 7 endpoints, contrato de request/response
├── checklists/
│   └── requirements.md         # Calidad de la spec
└── tasks.md                    # (lo genera /speckit-tasks)
```

### Source Code (repository root)

```text
app/
├── Http/Controllers/Informes/
│   └── InformeContadorController.php      # NUEVO — index, data, stats, exportar (×2 pestañas)
├── Services/Informes/
│   ├── LibroIvaQuery.php                  # NUEVO — base común: período, filtros, totales
│   ├── LibroIvaVentasQuery.php            # NUEVO — rama Ventas + NC/ND de venta + ARCA/manuales
│   ├── LibroIvaComprasQuery.php           # NUEVO — rama Compras + NC/ND de compra
│   ├── DesgloseImpositivoVenta.php        # EXISTE — se reutiliza, no se modifica
│   ├── DesgloseImpositivoCompra.php       # EXISTE — se reutiliza, no se modifica
│   └── ExpresionSql.php                   # EXISTE — helpers SQL portables
└── Exports/Informes/
    └── LibroIvaExport.php                 # NUEVO — Excel de una hoja

resources/
├── views/informes/contador/
│   └── index.blade.php                    # NUEVO — dos pestañas, totales, filtros, tabla
└── js/
    └── informe-contador.js                # NUEVO — estado por pestaña, DataTables, Select2

routes/web.php                             # +7 rutas bajo permiso:informes.ver
config/dz.php                              # +pagelevel 'informe-contador' (assets del template)
resources/views/elements/sidebar.blade.php # +entrada en Informes

tests/Feature/Informes/
├── LibroIvaTotalesTest.php                # NUEVO — la ecuación cierra exacta
├── LibroIvaPeriodoTest.php                # NUEVO — imputación vs. emisión (compras y NC/ND)
├── LibroIvaArcaManualesTest.php           # NUEVO — partición y venta reintentada
├── LibroIvaNotasDesgloseTest.php          # NUEVO — las 4 ramas de FR-022d, una por test
├── LibroIvaFiltrosTest.php                # NUEVO — cardinalidad de medio de cobro, exclusiones
└── LibroIvaExportTest.php                 # NUEVO — el Excel coincide con la pantalla
```

**Structure Decision**: se sigue exactamente el layout ya establecido por las specs 067/068/069 para los
informes: controlador fino en `app/Http/Controllers/Informes/`, la lógica de dinero en un servicio de
`app/Services/Informes/` (para poder testearla sin HTTP, principio IV), export en
`app/Exports/Informes/`, y un JS por pantalla en `resources/js/`.

La única decisión estructural nueva es partir la query en **una base más dos ramas**
(`LibroIvaQuery` + `...VentasQuery` / `...ComprasQuery`) en lugar de un único servicio con un parámetro
`libro`. Motivo: las dos pestañas comparten el 80% (período, filtros, forma de los totales) pero difieren
en cosas que no son un simple `if` — las casillas ARCA/manuales existen sólo en Ventas (FR-014a), el
período se resuelve con columnas distintas (FR-008/FR-009), y el desglose viene de servicios distintos.
Un solo servicio ramificando por dentro terminaría en el tipo de condicional disperso que hace que un
informe fiscal se vuelva difícil de auditar.

---

## Fases del diseño

- **Phase 0 — Research** ✅ `research.md`: 12 decisiones, ninguna incógnita abierta. Las de mayor impacto:
  agregar por comprobante (D1), reutilizar el desglose sin prorrateo (D2), expresión de período por
  origen (D3), `EXISTS` para ARCA (D4), Total Facturado en PHP (D6) y **POST** para los datos (D9).
- **Phase 1 — Design & Contracts** ✅ `data-model.md` (derivación de las 19 columnas, sin migraciones),
  `contracts/endpoints.md` (7 endpoints con el invariante de totales como parte del contrato) y
  `quickstart.md` (8 escenarios + tabla de tests esperados).
- **Phase 2 — Tasks**: lo genera `/speckit-tasks`.

---

## Riesgos y mitigaciones

| Riesgo | Mitigación |
|---|---|
| **`ONLY_FULL_GROUP_BY`**: el informe usa `GROUP BY` intensivamente y la suite corre en SQLite, más permisivo. Verde en tests no garantiza que funcione en MySQL | Está anotado en `quickstart.md`: validación obligatoria en navegador contra MySQL antes de cerrar. Es un gotcha ya registrado en la memoria del proyecto |
| **Deriva de centavos** en los totales | `Total Facturado` se calcula en PHP como suma de los cuatro componentes ya redondeados (D6), no como un quinto `SUM`. Test dedicado |
| **Duplicación de filas** por `JOIN` a `cobros`/`pagos` o a `comprobantes_fiscales` | `EXISTS` en los dos casos (D4, D11), con tests de cardinalidad |
| **414 en producción** al crecer las columnas | Datos por `POST` (D9). El fix de Nginx aplicado el 24/08 ayuda, pero el código no depende de él |
| **Divergencia fiscal entre informes** si alguien toca la clasificación | Se reutilizan `DesgloseImpositivo*` sin copiar reglas (D2) |
| **Imp. Municipales sin respaldo en el modelo** | Se emite `0` y queda fuera de la ecuación de totales (FR-011a), así que no descuadra nada. Brecha anotada en docs §5 |

---

## Complexity Tracking

Sin violaciones a la constitución que justificar. Sección vacía a propósito.
