# Implementation Plan: Percepciones/Impuestos Internos/Intereses funcionales en NC/ND

**Branch**: `061-conceptos-ncnd` | **Date**: 2026-08-11 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/061-conceptos-ncnd/spec.md`

## Summary

Los bloques "+Percepciones/+Impuestos Internos/+Intereses" de la página completa de NC/ND (spec
059) hoy son decorativos (`js-concepto-noop`). Se les da la misma funcionalidad real que ya tienen
Ventas/Compras/Presupuestos: agregar filas de concepto (percepción del catálogo fijo de 27, o texto
libre para impuesto interno/interés) + monto, sumarlas al Total, y persistirlas. La persistencia usa
la columna `impuestos` (json, nullable) que `notas_credito_debito` ya tiene desde spec 039/045 pero
nunca se conectó a ninguna UI — sin migración de esquema nueva.

## Technical Context

**Language/Version**: PHP 8.2, Laravel 12

**Primary Dependencies**: Laravel 12, Blade, jQuery — sin dependencias nuevas

**Storage**: MySQL — sin migraciones (columna `notas_credito_debito.impuestos` ya existe)

**Testing**: PHPUnit 11 vía Pest plugin

**Target Platform**: Web — mismo stack ya en producción

**Project Type**: Extensión de UI + persistencia sobre un módulo ya existente (NC/ND, spec 039/045/057/059)

**Performance Goals**: N/A

**Constraints**: No modificar lógica de stock/CAE/comprobante ya construida — los conceptos son un
agregado puro de monto sobre el Total ya persistido en `monto`.

**Scale/Scope**: 1 archivo JS nuevo/extendido (`notas-credito-debito.js`: `renderConceptos`, catálogo
`PERCEPCIONES`, wiring de `.js-add-concepto`), controller (`NotaCreditoDebitoController@store/
storeCompra/update` ya existentes, agregar manejo de `conceptos` en el payload), FormRequests
(`Store`/`UpdateNotaCreditoDebitoRequest`, agregar validación de `conceptos.*`), vista
(`notas-credito-debito/form.blade.php`, reemplazar `js-concepto-noop` por `js-add-concepto` +
contenedor `#conceptos-body`, igual a `compras/form.blade.php`).

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **Principio I (Documentación de dominio como fuente de verdad)**: ✅ `docs/modelo_datos.md` ya
  documenta la columna `impuestos` de `notas_credito_debito` como "mismo patrón que
  `presupuesto_conceptos`" — este spec la conecta tal cual estaba prevista, sin divergencia. Se
  actualiza esa entrada para reflejar que ya no está sin usar.
- **Principio II (Desarrollo spec-driven)**: ✅ specify → clarify (sin ambigüedades de alto impacto,
  resueltas antes de escribir la spec) → plan, en orden.
- **Principio III (Corrección fiscal innegociable)**: ✅ Los conceptos no participan del cálculo de
  CAE/ARCA (mismo criterio ya vigente en Ventas/Compras/Presupuestos, que tampoco los mandan a
  ARCA) — sólo suman al `monto` total ya persistido y validado por el backend existente.
- **Principio IV (Testing donde hay dinero o impacto fiscal)**: ✅ Los conceptos afectan un campo
  monetario (`monto`) — se agregan tests Feature que verifiquen persistencia, precarga en edición, y
  que el total incluya la suma de conceptos.
- **Principio V (Convenciones Laravel + dominio en español)**: ✅ Reusa exactamente los mismos
  nombres/patrones ya establecidos (`conceptos`, `tipo`/`concepto`/`monto`, `js-add-concepto`,
  `renderConceptos`).

**Resultado**: ninguna violación. No aplica Complexity Tracking.

## Project Structure

### Documentation (this feature)

```text
specs/061-conceptos-ncnd/
├── plan.md
├── research.md
├── data-model.md
├── contracts/
│   └── payload-conceptos-ncnd.md
├── quickstart.md
└── tasks.md
```

### Source Code (repository root)

```text
app/
└── Http/
    ├── Controllers/
    │   └── NotaCreditoDebitoController.php   # store/storeCompra/aplicarEdicion: persisten `impuestos`
    └── Requests/
        ├── StoreNotaCreditoDebitoRequest.php  # + validación conceptos.*
        └── UpdateNotaCreditoDebitoRequest.php # + validación conceptos.*

resources/
├── views/
│   └── notas-credito-debito/
│       └── form.blade.php                     # reemplaza js-concepto-noop por bloque real (igual compras/form.blade.php)
└── js/
    └── notas-credito-debito.js                # + PERCEPCIONES, conceptos[], renderConceptos(), wiring .js-add-concepto

docs/
└── modelo_datos.md                            # actualizar nota de `notas_credito_debito.impuestos`: ya conectada a UI

tests/Feature/
└── NotaCreditoDebitoConceptosTest.php          # NUEVO — persistencia, precarga en edición, total incluye conceptos
```

**Structure Decision**: extensión de archivos ya existentes del módulo NC/ND — no se crea ningún
servicio, modelo, migración ni vista nueva.

## Complexity Tracking

*Sin violaciones de la Constitution Check — sección no aplica.*
