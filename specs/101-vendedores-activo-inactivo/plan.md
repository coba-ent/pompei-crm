# Implementation Plan: Vendedores — activar/desactivar

**Branch**: `101-vendedores-activo-inactivo` | **Date**: 2026-09-08 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `/specs/101-vendedores-activo-inactivo/spec.md`

## Summary

Agregar una columna `activo` (boolean, default true) a `vendedores`, excluir a los inactivos de
todos los selects de asignación (Venta, Presupuesto, Tiendanube, MercadoLibre, Vendedor por
defecto), y agregar un tab nuevo "Vendedores" en Configuración & Ajustes con ABM completo
(alta/edición/activar-desactivar) vía DataTables + modal Bootstrap + AJAX + toasts. El enfoque
técnico calca al pie de la letra el ABM de Depósitos (`DepositoController`, `Deposito::scopeActivos`,
tab `configuracion/depositos/_tab.blade.php`) — mismo problema (catálogo chico, baja lógica en vez
de eliminación dura), mismo patrón, sin inventar nada nuevo.

## Technical Context

**Language/Version**: PHP 8.2, Laravel 12

**Primary Dependencies**: Eloquent, DataTables (server no aplica — catálogo chico, se sirve
completo como Depósitos), Select2 (buscadores existentes de Venta/Presupuesto), Toastr (NexaDash)

**Storage**: MySQL — tabla `vendedores` existente, se agrega columna `activo`

**Testing**: PHPUnit (Feature test de scope/exclusión de inactivos en los selects; sin impacto
fiscal/dinero, así que no es obligatorio por Principio IV, pero se agrega por ser lógica de
negocio con más de un consumidor — bajo riesgo de regresión silenciosa)

**Target Platform**: Web (Blade + Bootstrap 5 NexaDash)

**Project Type**: Web application (Laravel monolito, backend + Blade)

**Performance Goals**: N/A (catálogo de decenas de filas, sin requisitos de escala)

**Constraints**: Debe seguir las especificaciones de diseño obligatorias de CLAUDE.md (DataTables
AJAX, modales Bootstrap+AJAX sin recarga, toasts, Select2 en selects dinámicos)

**Scale/Scope**: Catálogo chico (decenas de vendedores), 1 tabla nueva de columna, 1 tab nuevo,
sin migraciones de datos complejas

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **Principio I (docs como fuente de verdad)**: `docs/documentacion_principal_crm.md` no describe
  hoy una pantalla de gestión de Vendedores en Contagram real (verificado contra los informes
  `docs/informe_contagram_*.md`); se documenta esta feature como divergencia deliberada, análoga a
  la ya aceptada para "Mi Perfil" (§5, spec 039). Se actualiza `documentacion_principal_crm.md §5`
  y `modelo_datos.md` antes de `/speckit-tasks`. **PASA** (con actualización pendiente, no bloqueante).
- **Principio II (spec-driven)**: se sigue el flujo completo specify→clarify→plan→checklist→tasks→
  analyze. **PASA**.
- **Principio III (corrección fiscal)**: no aplica — Vendedor no participa de CAE, IVA ni
  numeración de comprobantes. **PASA (N/A)**.
- **Principio IV (testing proporcional al riesgo)**: no hay dinero ni impacto fiscal; se agrega un
  test de Feature igual por el riesgo de que un vendedor inactivo se filtre en un select nuevo sin
  que se note a simple vista. **PASA**.
- **Principio V (Laravel + español)**: columna `activo` (mismo nombre que `Deposito`), scope
  `scopeActivos`, sin `empresa_id`. **PASA**.

No hay violaciones que requieran justificación en Complexity Tracking.

## Project Structure

### Documentation (this feature)

```text
specs/101-vendedores-activo-inactivo/
├── plan.md              # This file
├── research.md          # Phase 0 output
├── data-model.md         # Phase 1 output
├── quickstart.md         # Phase 1 output
├── contracts/             # Phase 1 output
└── tasks.md              # Phase 2 output (/speckit-tasks)
```

### Source Code (repository root)

```text
database/migrations/
└── 2026_09_08_XXXXXX_add_activo_to_vendedores_table.php

app/Models/
└── Vendedor.php                       # + campo activo, scopeActivos(), casts

app/Http/Controllers/
├── VendedorController.php             # + index/data/estado (calco de DepositoController);
│                                         store/update existentes se ajustan para exponer 'activo'
├── VentaController.php                # selects de asignación: Vendedor::activos()
├── PresupuestoController.php          # idem
├── Integraciones/TiendanubeConfiguracionController.php   # idem
├── Integraciones/MercadoLibreConfiguracionController.php # idem
└── Configuracion/ConfiguracionController.php             # idem + aviso vendedor por defecto inactivo

resources/views/configuracion/
├── index.blade.php                    # + entrada de tab "Vendedores"
├── _modal_vendedores.blade.php        # nuevo, calco de _modal_depositos.blade.php
└── vendedores/
    └── _tab.blade.php                 # nuevo, calco de depositos/_tab.blade.php

resources/js/
└── vendedores.js                      # nuevo (o extiende lógica ya usada en el buscador inline
                                          de venta/presupuesto), DataTable + modal + estado toggle

routes/web.php
└── grupo vendedores.* dentro de Configuración & Ajustes (index, data, estado) + store/update/destroy
    ya existentes se mantienen
```

**Structure Decision**: Laravel monolito existente; no se agregan proyectos ni carpetas nuevas de
alto nivel. Se replica 1:1 la estructura ya usada por Depósitos (mismo módulo, mismo patrón de
"catálogo con baja lógica" dentro de Configuración & Ajustes), minimizando piezas nuevas: el
`VendedorController` existente se extiende en lugar de crear un segundo controlador.

## Complexity Tracking

*Sin violaciones — sección no aplica.*
