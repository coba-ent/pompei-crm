# Implementation Plan: Permisos granulares por informe

**Branch**: `090-permisos-granulares-informes` | **Date**: 2026-08-28 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/090-permisos-granulares-informes/spec.md`

## Summary

Reemplazar el permiso único `informes.ver` por nueve permisos (ocho por informe + uno transversal de
descarga), y cerrar de paso un agujero de autorización vigente: `informes/stock` y todo
`informes/cuenta-corriente/*` quedaron fuera del `Route::middleware('permiso:informes.ver')->group()`
de `routes/web.php:180`, así que hoy cualquier usuario autenticado los abre y exporta escribiendo la
URL a mano.

Enfoque técnico: reestructurar el bloque de rutas de Informes en sub-grupos por informe, cada uno con
su middleware `permiso:informes.<informe>`, encadenando un segundo `permiso:informes.exportar` en las
rutas de descarga (patrón ya usado en Tesorería para elevar `tesoreria.ver` → `tesoreria.editar`). Una
migración de datos reparte los permisos entre los tres roles reales según su función, y los seeders se
alinean para que una instalación limpia produzca el mismo estado.

No cambia el contenido, el cálculo ni la presentación de ningún informe: sólo quién entra a cada uno.

## Technical Context

**Language/Version**: PHP 8.2, Laravel 12

**Primary Dependencies**: Eloquent; middleware alias `permiso` →
`App\Http\Middleware\VerificarPermiso`; `Gate::before` para el rol Admin; Blade `@can`

**Storage**: MySQL — tablas existentes `permisos`, `roles` y sus pivots. **No se crean tablas ni
columnas nuevas**: la feature sólo agrega filas al catálogo de permisos y reasigna pivots.

**Testing**: PHPUnit (`tests/Feature`), extendiendo el patrón de `AutorizacionPermisoTest.php` y
`DashboardPermisosTest.php`

**Target Platform**: aplicación web Laravel (VPS de producción en uso real)

**Project Type**: aplicación web monolítica Blade + Eloquent

**Performance Goals**: sin impacto medible. El costo agregado es, a lo sumo, una verificación de
permiso extra por request en las rutas de descarga; `tienePermiso()` es un `exists()` sobre roles.

**Constraints**:
- Migración de datos **idempotente** y reversible: corre sobre una base de producción en uso.
- **Ninguna ruta del módulo Informes puede quedar sin permiso** — es el bug que se está arreglando.
- El middleware `VerificarPermiso` no se modifica: lo usan todos los módulos del sistema.

**Scale/Scope**: 3 roles y 6 usuarios reales; ~60 rutas en el módulo Informes; 9 permisos nuevos, 1
retirado; 8 vistas de informe a ajustar + sidebar + 2 `_row_actions`.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principio | Estado | Justificación |
|---|---|---|
| **I. Documentación de dominio como fuente de verdad** | ✅ Pasa | Se leyeron `documentacion_principal_crm.md` §3 (roles/permisos) y §6.1. La feature introduce un catálogo de permisos nuevo → hay que actualizar la doc de dominio **antes de `/speckit-tasks`**, según la regla del CLAUDE.md. Anotado como tarea explícita. |
| **II. Desarrollo spec-driven** | ✅ Pasa | Feature de negocio, con spec 090 completa antes del código. |
| **III. Corrección fiscal innegociable (ARCA)** | ✅ No aplica / refuerza | No toca emisión, CAE ni numeración. Refuerza el principio al restringir el acceso al Libro IVA y a IVA Digital a quien tenga `informes.contador`. |
| **IV. Testing donde hay dinero o impacto fiscal** | ✅ Pasa | Obligatorio acá: la feature gobierna el acceso a cuentas corrientes, márgenes/CMV y Libro IVA. Dos tests de feature nuevos (acceso por ruta y reparto por rol tras la migración). |
| **V. Convenciones Laravel + dominio en español** | ✅ Pasa | Códigos `modulo.accion` en español, con guiones como el módulo ya existente `otros-ingresos`; middleware declarado en rutas como el resto del proyecto; migración versionada. |

**Sin violaciones. La sección "Complexity Tracking" queda vacía a propósito.**

Re-evaluación post-Phase 1: sin cambios. El diseño no agrega tablas, servicios ni abstracciones —
usa el mecanismo de permisos existente tal cual, y la única decisión no trivial (encadenar el alias
`permiso` dos veces) evita justamente tener que modificar infraestructura compartida.

## Project Structure

### Documentation (this feature)

```text
specs/090-permisos-granulares-informes/
├── plan.md              # Este archivo
├── research.md          # Phase 0 — 7 decisiones técnicas
├── data-model.md        # Phase 1 — catálogo de permisos y reparto por rol
├── quickstart.md        # Phase 1 — guía de validación manual y automatizada
├── contracts/
│   └── rutas-permisos.md   # Phase 1 — mapa ruta → permiso(s) exigidos
├── checklists/
│   └── requirements.md
└── tasks.md             # Phase 2 — lo genera /speckit-tasks
```

### Source Code (repository root)

```text
routes/
└── web.php                          # Reestructura del bloque Informes (núcleo del cambio)

database/
├── migrations/
│   └── 2026_08_28_xxxxxx_permisos_granulares_informes.php   # NUEVA
└── seeders/
    ├── PermisoSeeder.php            # -1 permiso, +9 permisos
    └── RolSeeder.php                # Contable: informes.ver → 5 códigos nuevos

resources/views/
├── elements/sidebar.blade.php       # @can por ítem + bloque oculto si no hay ninguno
├── clientes/_row_actions.blade.php  # informes.ver → informes.cuenta-corriente-clientes
├── proveedores/_row_actions.blade.php # informes.ver → informes.cuenta-corriente-proveedores
└── informes/                        # 8 vistas: botones exportar/PDF bajo @can('informes.exportar')

app/Models/
└── InformeVista.php                 # Sólo el docblock (menciona informes.ver)

tests/Feature/
├── InformesPermisosTest.php          # NUEVO — 403 por ruta, aislamiento, descarga
└── InformesPermisosMigracionTest.php # NUEVO — reparto por rol, informes.ver retirado

docs/
└── documentacion_principal_crm.md   # §3 catálogo de permisos + §Informes (antes de /speckit-tasks)
```

**Structure Decision**: monolito Laravel existente; la feature es transversal al módulo Informes y no
introduce directorios nuevos. El peso del cambio está concentrado en `routes/web.php` (declaración de
acceso) y en una migración de datos; las vistas sólo dejan de ofrecer lo que el usuario no puede usar.

## Complexity Tracking

> Sin violaciones de la constitución que justificar.
