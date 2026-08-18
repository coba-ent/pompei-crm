# Implementation Plan: Dashboard filtrado por permisos

**Branch**: `070-dashboard-permisos` | **Date**: 2026-08-18 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `/specs/070-dashboard-permisos/spec.md`

## Summary

El `DashboardController` (spec 010) hoy calcula y devuelve todos los rubros (Ventas, Otros
Ingresos, Compras, Gastos, Tesorería, Rankings) sin mirar los permisos del usuario logueado. Se
agrega una capa de filtrado por permiso, aplicada en el backend (para que ningún endpoint AJAX
exponga en el JSON un rubro que el usuario no puede ver) y reflejada en el frontend (Blade + JS)
para que el widget correspondiente directamente no se renderice. Se reutiliza el mecanismo
existente `User::tienePermiso($codigo)` sobre el catálogo de permisos ya sembrado por
`PermisoSeeder` — no se agregan permisos nuevos ni tablas nuevas.

## Technical Context

**Language/Version**: PHP 8.2, Laravel 12

**Primary Dependencies**: Eloquent ORM, Blade, jQuery + AJAX (patrón ya usado en el resto del Dashboard), DataTables no aplica (el Dashboard no tiene tablas paginadas)

**Storage**: MySQL — no hay cambios de esquema; se reutilizan las tablas `permisos`, `roles`, `rol_usuario`, `permiso_rol` ya existentes

**Testing**: PHPUnit (Feature tests), ya existe `tests/Feature/Dashboard*Test.php` (Kpis, Totales/Rankings, Neteo, PeriodoHoy, EmptyState, Donas, TesoreriaResumen, GraficoMensual)

**Target Platform**: Web app Laravel (server-side), navegador de escritorio

**Project Type**: Web application monolítica (Laravel + Blade), un solo proyecto

**Performance Goals**: Sin cambio respecto al Dashboard actual — el filtrado sólo evita ejecutar queries de rubros sin permiso (si acaso, mejora levemente el tiempo de respuesta al saltear cálculos)

**Constraints**: No se puede introducir un permiso nuevo (los 7 permisos `.ver` relevantes ya existen); el filtrado debe aplicarse en el backend, no sólo ocultar en el DOM

**Scale/Scope**: 1 controller (`DashboardController`, 5 endpoints AJAX + `index`), 1 vista (`dashboard/index.blade.php`) y su JS asociado; sin nuevas entidades de dominio

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **I. Documentación de dominio como fuente de verdad**: aplica. `docs/documentacion_principal_crm.md` describe la spec 010 del Dashboard; se actualiza para documentar que cada widget ahora respeta permisos granulares (ver tarea de docs en `/speckit-tasks`). PASS (con acción pendiente, no bloqueante).
- **II. Desarrollo spec-driven**: cumplido — esta feature pasa por specify→clarify→plan→checklist→tasks→analyze antes de implementar. PASS.
- **III. Corrección fiscal innegociable (ARCA)**: no aplica — el Dashboard es de sólo lectura, no emite comprobantes ni CAE. N/A.
- **IV. Testing donde hay dinero o impacto fiscal**: aplica — el Dashboard calcula importes/totales (ventas, compras, gastos, resultado). Los tests Feature existentes deben extenderse para cubrir el filtrado por permiso (un usuario sin `compras.ver` no debe recibir el monto de compras en `kpis`/`totales`/`grafico-mensual`/`donas`). PASS (cubierto en tasks).
- **V. Convenciones Laravel + dominio en español**: se reutiliza `User::tienePermiso()` y los códigos de permiso ya en español (`ventas.ver`, etc.); no se introduce nomenclatura nueva. PASS.

No hay violaciones de la constitución. No se requiere `Complexity Tracking`.

## Project Structure

### Documentation (this feature)

```text
specs/070-dashboard-permisos/
├── plan.md              # This file
├── research.md          # Phase 0 output
├── data-model.md         # Phase 1 output
├── quickstart.md        # Phase 1 output
├── contracts/
│   └── dashboard-endpoints.md
└── tasks.md              # Phase 2 output (/speckit-tasks)
```

### Source Code (repository root)

```text
app/
├── Http/Controllers/
│   └── DashboardController.php     # se modifica: filtrado por permiso en index() y en los 5 endpoints AJAX
├── Models/
│   └── User.php                     # sin cambios; se reutiliza tienePermiso()

resources/
├── views/dashboard/
│   └── index.blade.php              # se modifica: ocultar bloques Blade (KPIs/Totales/tesorería) según permiso, vía @can-like helper o variable ya calculada en el controller
├── js/
│   └── dashboard.js (o el JS embebido en la vista)  # se modifica: no asumir presencia de claves ausentes en las respuestas JSON; ocultar tarjetas de gráfico/donas/rankings dinámicamente si el rubro no vino

tests/Feature/
├── DashboardKpisTest.php            # se extiende: casos con permisos parciales
├── DashboardTesoreriaResumenTest.php
├── DashboardGraficoMensualTest.php
├── DashboardDonasTest.php
├── DashboardRankingsTest.php
└── DashboardPermisosTest.php        # nuevo: casos end-to-end de ocultamiento por permiso (US1-US4 de la spec)
```

**Structure Decision**: Proyecto único Laravel monolítico ya existente — no se agregan módulos ni
proyectos nuevos. El cambio es localizado a `DashboardController`, la vista `dashboard/index.blade.php`
y su JS, más un helper reutilizable para resolver "qué rubros puede ver este usuario" que evita
duplicar la lógica de permisos en cada uno de los 5 métodos del controller.

## Complexity Tracking

*Sin violaciones a justificar.*
