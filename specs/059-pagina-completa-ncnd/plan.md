# Implementation Plan: Página completa de NC/ND (corrección estructural sobre spec 057)

**Branch**: `059-pagina-completa-ncnd` | **Date**: 2026-08-11 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/059-pagina-completa-ncnd/spec.md`

## Summary

Spec 057 modeló mal la estructura real de Contagram para crear/editar NC/ND: implementó un modal de
2 pasos (todo en el mismo popup), cuando Contagram real usa un modal de 1 paso (Tipo/Documento que
Ajusta/Stock/Mes) que al continuar navega a una **página completa propia**, con la misma estructura
que "Nueva Venta"/"Nueva Compra" (confirmado con capturas reales del cliente, 11/08/2026). Este plan
corrige la UI sin tocar la lógica de negocio ya construida (validaciones, stock, bloqueo por CAE/
cadena, comprobante propio) — reutiliza el backend de spec 057 tal cual, agregando únicamente las
rutas `GET` de la página completa y reestructurando el modal + JS + una vista nueva compartida
Ventas/Compras.

## Technical Context

**Language/Version**: PHP 8.2, Laravel 12

**Primary Dependencies**: Laravel 12, Blade, jQuery + Select2 + Bootstrap 5, sin dependencias nuevas

**Storage**: MySQL — sin migraciones (cero cambios de esquema)

**Testing**: PHPUnit 11 vía Pest plugin

**Target Platform**: Web — mismo stack ya en producción

**Project Type**: Corrección de UI sobre un módulo ya existente (NC/ND, spec 039/045/057)

**Performance Goals**: N/A

**Constraints**: El backend de spec 057 (`NotaCreditoDebitoController@store/storeCompra/update/
updateCompra/destroy/destroyCompra/pdf`, FormRequests, `StockService`) NO se modifica — este spec es
estrictamente de rutas `GET` nuevas + vistas + JS

**Scale/Scope**: 1 vista Blade nueva compartida (`notas-credito-debito/form.blade.php`), 4 métodos
`GET` nuevos en `NotaCreditoDebitoController` (create/edit para Venta y Compra), recorte de
`_modal_ncnd.blade.php` (Ventas y Compras) al sólo-paso-1, reescritura de la porción de
`ventas.js`/`compras.js` que hoy maneja el paso 2 dentro del modal

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **Principio I (Documentación de dominio como fuente de verdad)**: ✅ Aplica directo — este spec
  ES la corrección de una divergencia estructural ya detectada. `docs/documentacion_principal_crm.md`
  §3.2/§7 se actualiza antes de `/speckit-tasks` describiendo la página completa (reemplaza la
  descripción del wizard de 2 pasos en modal que dejaban spec 039/045/057).
- **Principio II (Desarrollo spec-driven)**: ✅ spec → clarify (resuelto con capturas antes de
  especificar) → plan, en orden.
- **Principio III (Corrección fiscal innegociable)**: ✅ Sin cambios — el backend que gestiona CAE/
  stock/reversión es exactamente el mismo de spec 057, ya cubierto por tests. Este plan no introduce
  nueva superficie de riesgo fiscal.
- **Principio IV (Testing donde hay dinero o impacto fiscal)**: ✅ Los tests de negocio de spec 057
  (`NotaCreditoDebitoEditarTest`, `NotaCreditoDebitoEliminarTest`) siguen vigentes sin cambios (pegan
  contra las mismas rutas `PUT`/`DELETE`/`POST`); se agregan tests nuevos sólo para las rutas `GET`
  de la página completa (que existan, que precarguen bien) — no para lógica de negocio ya cubierta.
- **Principio V (Convenciones Laravel + dominio en español)**: ✅ Nombres nuevos (`create`/`edit`/
  `createCompra`/`editCompra`, `ventas.notas.create`/`ventas.notas.edit`) siguen el mismo patrón ya
  usado por `VentaController`/`CompraController`.

**Resultado**: ninguna violación. No aplica Complexity Tracking.

## Project Structure

### Documentation (this feature)

```text
specs/059-pagina-completa-ncnd/
├── plan.md
├── research.md
├── data-model.md
├── contracts/
│   └── rutas-pagina-ncnd.md
├── quickstart.md
└── tasks.md
```

### Source Code (repository root)

```text
app/
└── Http/
    └── Controllers/
        └── NotaCreditoDebitoController.php     # + create(), edit(), createCompra(), editCompra()

resources/
├── views/
│   ├── notas-credito-debito/
│   │   └── form.blade.php                       # NUEVO — página completa Crear/Editar, Ventas y Compras
│   ├── ventas/
│   │   └── _modal_ncnd.blade.php                 # recorte a sólo paso 1
│   └── compras/
│       └── _modal_ncnd.blade.php                 # recorte a sólo paso 1 (ídem Ventas)
└── js/
    ├── ventas.js                                 # inicializarNcNd(): "Siguiente" navega en vez de irAPaso(2); abrirEdicionNota() deshabilita también el radio de Stock
    └── compras.js                                # ídem

routes/web.php                                    # + GET .../notas/nueva, GET .../notas/{nota}/editar (Ventas y Compras)

docs/
└── documentacion_principal_crm.md                # actualizar §3.2/§7: página completa reemplaza wizard de 2 pasos en modal

tests/Feature/
└── NotaCreditoDebitoPaginaTest.php                # NUEVO — rutas GET, precarga, no-regresión de flujo completo
```

**Structure Decision**: extensión de `NotaCreditoDebitoController` ya existente + 1 vista nueva
compartida — no se crea ningún servicio, modelo o FormRequest nuevo (el backend de spec 057 se
reutiliza sin cambios).

## Complexity Tracking

*Sin violaciones de la Constitution Check — sección no aplica.*
