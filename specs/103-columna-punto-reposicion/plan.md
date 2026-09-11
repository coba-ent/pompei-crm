# Implementation Plan: Columna Punto de Reposición en el listado de Productos

**Branch**: `103-columna-punto-reposicion` | **Date**: 2026-09-10 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `/specs/103-columna-punto-reposicion/spec.md`

## Summary

Exponer `productos.punto_reposicion` (columna ya existente, spec 073) como una columna más del
listado server-side de Productos (DataTables + Yajra), ubicada después de Stock total y las columnas
de stock por depósito, antes de Costo. El valor ya viaja en el modelo Eloquent sin tocar el `SELECT`
base — sólo hace falta: header de tabla, definición de columna en el JS (con su render de "sin
control" para `0`), un `editColumn` en el backend para que los Servicios muestren `null` (mismo
criterio que Stock), y actualizar la documentación de dominio con la divergencia deliberada respecto
al listado real de Contagram (confirmada con el usuario).

## Technical Context

**Language/Version**: PHP 8.2 (Laravel 12), JavaScript (jQuery + DataTables, sin build de TS)

**Primary Dependencies**: Yajra DataTables (`yajra/laravel-datatables-oracle`), Select2 (no aplica acá,
no es un select), template NexaDash (Blade)

**Storage**: MySQL — columna `productos.punto_reposicion` ya existe (`unsignedInteger`, `NOT NULL
default 0`, spec 073). Sin migraciones nuevas.

**Testing**: PHPUnit (Feature tests contra el endpoint `productos.data`, patrón ya usado para las
demás columnas del listado — ver `tests/Feature/ProductoDatatableTest.php` o equivalente)

**Target Platform**: Web, servidor Laravel existente (demo + VPS)

**Project Type**: Web application (monolito Laravel + Blade), cambio acotado a un módulo existente

**Performance Goals**: Sin degradación del tiempo de carga del listado actual — no se agrega ningún
JOIN ni subquery nueva (a diferencia de las columnas de lista de precio / stock por depósito, que sí
usan `addSelect` con subquery correlacionada, `punto_reposicion` es una columna directa de
`productos`).

**Constraints**: Debe convivir con las columnas dinámicas ya existentes (listas de precio, stock por
depósito) sin romper su orden ni su alineación con el export CSV existente.

**Scale/Scope**: Cambio de un solo módulo (Productos): 1 vista Blade, 1 archivo JS, 1 método de
controller. Sin cambios de esquema.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **Principio I (Documentación de dominio como fuente de verdad)**: `docs/documentacion_principal_crm.md
  §2.2` ya fue leído (ver spec). La columna de listado real de Contagram no incluye Punto de
  Reposición — se agrega como **divergencia deliberada y documentada** (confirmada con el usuario), no
  como corrección de un relevamiento incorrecto. FR-010 de la spec obliga a actualizar §2.2 con esta
  excepción en el mismo cambio. ✅ Cumple (con excepción documentada, mismo patrón que spec 071).
- **Principio II (Desarrollo spec-driven)**: esta spec (103) sigue el flujo completo
  specify→clarify→plan→checklist→tasks→analyze. ✅ Cumple.
- **Principio III (Corrección fiscal ARCA)**: no aplica — sin impacto fiscal, sin comprobantes
  involucrados. N/A.
- **Principio IV (Testing donde hay dinero o impacto fiscal)**: Punto de Reposición no mueve dinero,
  IVA, stock ni CAE — es un dato informativo de control interno. No es de testing obligatorio por
  este principio, pero se agrega un test acotado (mismo criterio que "CRUD simple y vistas pueden no
  requerir tests estrictos, a criterio") porque el caso "0 = sin control, no debe mostrarse como 0" es
  fácil de romper accidentalmente y ya causó confusión en el pasado con Stock (`esServicio()` → null).
  ✅ Cumple.
- **Principio V (Convenciones Laravel + dominio en español)**: nombre de columna en español ("Punto de
  Reposición"), mismo patrón `editColumn`/`addColumn` que el resto del controller, sin Global Scope ni
  `empresa_id`. ✅ Cumple.

**Regla de oro (fidelidad estructural a Contagram)**: la única divergencia de esta spec respecto a
Contagram real es agregar esta columna. Está identificada, confirmada con el usuario, y su
justificación de negocio queda documentada en `documentacion_principal_crm.md §2.2` (FR-010) — no se
"simplifica en silencio" ninguna otra parte de la pantalla.

**Resultado**: PASS. Sin violaciones sin justificar.

## Project Structure

### Documentation (this feature)

```text
specs/103-columna-punto-reposicion/
├── plan.md              # This file
├── data-model.md         # Phase 1 output
├── quickstart.md         # Phase 1 output
├── checklists/
│   └── requirements.md
└── tasks.md              # Phase 2 output (/speckit-tasks)
```

No se genera `research.md` (sin incógnitas técnicas: patrón idéntico al ya usado por las columnas
`stock_total`/`stock_deposito_*` del mismo controller) ni `contracts/` (no hay interfaz pública nueva;
la única superficie es una columna más en el JSON que ya devuelve `ProductoController::data()`,
consumido únicamente por el JS de la misma pantalla).

### Source Code (repository root)

Proyecto único (monolito Laravel). Archivos que toca esta feature (todos existentes, sin altas de
directorio):

```text
app/Http/Controllers/ProductoController.php     # editColumn('punto_reposicion', ...) en data()
resources/views/productos/index.blade.php        # <th>Punto de Reposición</th> en el thead
resources/js/productos.js                        # definición de columna DataTables + render

docs/documentacion_principal_crm.md               # §2.2: columna + divergencia documentada (FR-010)

tests/Feature/ProductoListadoTest.php             # tests del endpoint data() — se agregan los casos
                                                    de esta columna acá (archivo ya existente)
```

**Structure Decision**: cambio quirúrgico dentro del módulo Productos ya existente — mismo patrón
exacto que las columnas `stock_total` y `stock_deposito_{id}` (que ya resuelven "producto de tipo
Servicio → mostrar `—` en vez de `0`", el mismo caso de borde que aplica acá). No se crean
controladores, rutas, modelos ni vistas nuevas.

**Nota de consistencia verificada**: el export a Excel de Productos (`app/Exports/ProductosExport.php`
línea 140) **ya** escribe `punto_reposicion` (documentado en `documentacion_principal_crm.md
§2.2`, "El export de Productos escribe siempre un número"). Esta spec sólo cierra la brecha que
faltaba: el listado en pantalla. No hay riesgo de que el export y la pantalla queden inconsistentes
entre sí — el export ya estaba adelantado.

## Complexity Tracking

*Sin violaciones de la Constitution Check. Tabla omitida (no aplica).*
