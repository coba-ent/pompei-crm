# Implementation Plan: Reorganización de Configuración & Ajustes (Empresa + acceso Admin + defaults de Ventas)

**Branch**: `043-configuracion-empresa-ventas` | **Date**: 2026-08-04 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/043-configuracion-empresa-ventas/spec.md`

## Summary

Se fusiona la gestión de usuarios (hoy "Usuarios y Permisos") dentro de la pantalla "Mi Perfil", que se
renombra a "Empresa". El acceso a "Empresa" y a toda la sección "Configuración & Ajustes" pasa a
depender exclusivamente del rol `Admin` (ya existente vía `User::esAdmin()` / `Gate::before`), en vez
de los permisos granulares actuales (`configuracion.usuarios`, `configuracion.funciones`, `configuracion.roles`).
El bloque "Configuración & Ajustes" se retira del sidebar; en su lugar, el dropdown de usuario de la
topbar pasa a mostrar (sólo a Admin) un ítem "Empresa" y un único ítem "Configuración & Ajustes" que
navega a **una sola pantalla nueva** (`configuracion.index`) con Funciones Avanzadas, Depósitos, Mercado
Libre, Tiendanube, Facturación Electrónica y el nuevo tab "Ventas" organizados como **tabs Bootstrap
client-side** (con ícono cada uno), sin submenú desplegable en la topbar y sin hash en la URL. El tab
por defecto es "Funciones Avanzadas"; desde ahí el Admin activa/desactiva cada función, y eso determina
qué otros tabs (Depósitos/Mercado Libre/Tiendanube/Facturación Electrónica) están disponibles — el tab
"Ventas" siempre está disponible. El tab "Ventas" contiene un formulario único (fila global
`configuracion_ventas`, patrón ya usado por `datos_empresa`) para definir Categoría/Vendedor/Lista de
Precios/Tipo de Comprobante por defecto y días por defecto de Vto. de Cobro, que `VentaController@create`
inyecta como defaults en `ventas.form` sólo para altas nuevas.

## Technical Context

**Language/Version**: PHP 8.2 (Laravel 12), JavaScript (jQuery + Select2, sin build de componentes)

**Primary Dependencies**: Eloquent ORM, Blade + NexaDash (Bootstrap 5), DataTables (server-side AJAX), Select2, Toastr

**Storage**: MySQL — se reutiliza el patrón de "fila única" ya usado por `empresa` (tabla `configuracion_ventas`, sin FK a ningún tenant)

**Testing**: PHPUnit (Feature tests para middleware de acceso Admin y para el precargado de defaults en `ventas.form`; Unit test para el cálculo de `fecha_vto_cobro` a partir de días configurados)

**Target Platform**: Aplicación web server-rendered (Blade), un solo negocio (single-tenant), sin apps móviles

**Project Type**: Web application (monolito Laravel, un solo proyecto)

**Performance Goals**: N/A — pantallas de configuración de bajo volumen (CRUD administrativo, sin carga de tráfico relevante)

**Constraints**: No romper URLs/rutas ya bookmarkeadas de `configuracion.mi-perfil.index` (se mantiene el nombre de ruta interno); el override de `tipo_comprobante_defecto` por Cliente (ya existente en `ventas.js:414`) debe seguir ganando por sobre el nuevo default global cuando el usuario selecciona un cliente

**Scale/Scope**: 1 tabla nueva (`configuracion_ventas`, fila única), 1 middleware nuevo (`SoloAdmin`), reemplazo de middleware en ~7 grupos de rutas existentes, 1 controlador nuevo (`ConfiguracionVentasController`), fusión de 2 vistas Blade existentes, cambios en sidebar.blade.php y header.blade.php

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **Principio I (docs como fuente de verdad)**: `docs/documentacion_principal_crm.md §5` ya documenta el módulo Configuración & Ajustes y "Mi Perfil" (spec 039); se actualiza esa sección con la reorganización (Empresa, gate Admin, ítem Ventas) antes de `/speckit-tasks`. PASS (con acción pendiente registrada).
- **Principio II (spec-driven)**: esta feature sigue el flujo completo specify→clarify→plan→checklist→tasks→analyze. PASS.
- **Principio III (corrección fiscal ARCA)**: no toca emisión de comprobantes, CAE ni numeración; el Tipo de Comprobante por defecto configurado es sólo una preselección de UI en el alta manual, no cambia la regla de derivación fiscal ya existente (condición de IVA del cliente/emisor). PASS — sin impacto fiscal.
- **Principio IV (testing con dinero/impacto fiscal)**: el cálculo de `fecha_vto_cobro` no es un cálculo de importes/IVA/CAE, pero al ser una fecha de cobro con impacto en Cta. Cte./Tesorería se cubre igual con un test unitario simple. PASS.
- **Principio V (Laravel + español, single-tenant)**: tabla nueva `configuracion_ventas` sin `empresa_id` (fila única, mismo patrón que `empresa`); nombres en español. PASS.

No hay violaciones que requieran justificación en Complexity Tracking.

## Project Structure

### Documentation (this feature)

```text
specs/043-configuracion-empresa-ventas/
├── plan.md              # This file
├── research.md          # Phase 0 output
├── data-model.md        # Phase 1 output
├── quickstart.md        # Phase 1 output
├── contracts/
│   └── configuracion-ventas.md
└── tasks.md             # Phase 2 output (/speckit-tasks)
```

### Source Code (repository root)

```text
app/
├── Http/
│   ├── Middleware/
│   │   └── SoloAdmin.php                         # nuevo: reemplaza los `permiso:*` de Configuración & Ajustes
│   └── Controllers/
│       ├── MiPerfilController.php                 # se extiende: pasa también los datos de usuarios (tabla, roles) a la vista
│       └── Configuracion/
│           ├── UsuarioController.php              # se mantiene igual (data/store/show), sólo cambia desde qué vista se consume
│           ├── RolController.php                  # se mantiene igual
│           ├── ConfiguracionController.php        # nuevo: index único de "Configuración & Ajustes" — arma los datos de todos los tabs (funciones avanzadas + su estado activa/inactiva para gatear Depósitos/Mercado Libre/Tiendanube/Facturación Electrónica) y renderiza la vista con tabs
│           └── ConfiguracionVentasController.php  # nuevo: guardar el tab Ventas (fila única)
├── Models/
│   ├── DatosEmpresa.php                           # existente, sin cambios
│   └── ConfiguracionVentas.php                    # nuevo: fila única de defaults de Ventas
└── Http/Controllers/VentaController.php            # create(): inyecta defaults de ConfiguracionVentas cuando no hay $venta/$presupuestoOrigen

database/migrations/
└── xxxx_create_configuracion_ventas_table.php

resources/views/configuracion/
├── mi-perfil/index.blade.php   # renombrado conceptualmente a "Empresa": agrega sección de usuarios (tabla, modal alta, link Roles y Permisos)
├── usuarios/                    # pantalla/ruta separada eliminada (se retira index.blade.php + ruta), el modal de alta pasa a incluirse desde mi-perfil
├── index.blade.php              # nueva pantalla única "Configuración & Ajustes": nav-tabs (con íconos) + tab-content, tab activo por defecto "Funciones Avanzadas"
├── funciones/_tab.blade.php     # contenido actual de funciones/index.blade.php, extraído a partial para incluir como tab
├── depositos/_tab.blade.php     # ídem, incluido sólo si FuncionAvanzada::activa('depositos')
├── mercadolibre/_tab.blade.php  # ídem, sólo si FuncionAvanzada::activa('mercadolibre')
├── tiendanube/_tab.blade.php    # ídem, sólo si FuncionAvanzada::activa('tiendanube')
├── arca/_tab.blade.php          # ídem (Facturación Electrónica), sólo si FuncionAvanzada::activa('facturacion_electronica')
└── ventas/_tab.blade.php        # nuevo contenido del tab Ventas (siempre visible)

resources/views/elements/
├── sidebar.blade.php   # se retira el bloque <li> de "Configuración & Ajustes"
└── header.blade.php    # dropdown de topbar: "Mi Perfil" → "Empresa" + nuevo ítem (link único) "Configuración & Ajustes" → route('configuracion.index'), ambos sólo si esAdmin()

routes/web.php
└── grupo `configuracion.*`: cada sub-grupo cambia su middleware de `permiso:configuracion.*` a `SoloAdmin`; se agrega `Route::get('/', [ConfiguracionController::class,'index'])->name('configuracion.index')` y sub-grupo `ventas`; se elimina el sub-grupo `usuarios` como pantalla propia (se conserva sólo como namespace de rutas AJAX consumidas desde Empresa, sin vista `index` propia); las rutas AJAX ya existentes de depósitos/mercadolibre/tiendanube/arca/funciones se mantienen intactas (las consume el JS de cada tab igual que antes, sólo cambia qué vista los incluye)

tests/Feature/Configuracion/
├── AccesoAdminConfiguracionTest.php   # nuevo
└── ConfiguracionVentasDefaultsTest.php # nuevo
tests/Unit/
└── ConfiguracionVentasTest.php         # nuevo (cálculo fecha_vto_cobro)
```

**Structure Decision**: Monolito Laravel existente; no se agrega ningún proyecto ni carpeta nueva de alto
nivel. Se reutiliza el namespace `App\Http\Controllers\Configuracion` ya existente, agregando un
controlador (`ConfiguracionVentasController`) y un middleware (`SoloAdmin`) junto a los ya presentes.

## Complexity Tracking

*Sin violaciones a justificar.*
