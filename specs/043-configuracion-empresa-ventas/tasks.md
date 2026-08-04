---

description: "Task list for Reorganización de Configuración & Ajustes (Empresa + acceso Admin + defaults de Ventas)"
---

# Tasks: Reorganización de Configuración & Ajustes (Empresa + acceso Admin + defaults de Ventas)

**Input**: Design documents from `/specs/043-configuracion-empresa-ventas/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/configuracion-ventas.md, quickstart.md

**Tests**: Incluidos de forma acotada por Principio IV de la constitución (impacto en Cta. Cte./Tesorería del cálculo de Vto. de Cobro) y porque el gate de acceso Admin es seguridad crítica — no se agregan tests exhaustivos de CRUD simple.

**Organization**: Tareas agrupadas por user story (spec.md) para permitir implementación y prueba independiente de cada una.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Puede ejecutarse en paralelo (archivos distintos, sin dependencias)
- **[Story]**: US1 = Gestión de usuarios en Empresa, US2 = Acceso Admin + pantalla única con tabs, US3 = Defaults de Ventas

## Path Conventions

Monolito Laravel existente (ver plan.md → Project Structure). Rutas relativas a la raíz del repo.

---

## Phase 1: Setup (Shared Infrastructure)

- [ ] T001 Crear migración `database/migrations/xxxx_create_configuracion_ventas_table.php` (columnas: `categoria_id` nullable FK `categorias.id` `nullOnDelete`, `vendedor_id` nullable FK `vendedores.id` `nullOnDelete`, `lista_precio_id` nullable FK `listas_precio.id` `nullOnDelete`, `tipo_comprobante` nullable enum A/B/C/E, `dias_vto_cobro` unsigned smallint nullable, timestamps)
- [ ] T002 [P] Crear modelo `app/Models/ConfiguracionVentas.php` con relaciones `belongsTo` a `Categoria`, `Vendedor`, `ListaPrecio` (ver data-model.md)
- [ ] T003 [P] Crear middleware `app/Http/Middleware/SoloAdmin.php` que verifica `$request->user()->esAdmin()` y hace `abort(403)` si no, registrarlo como alias `admin` en `bootstrap/app.php` (o `app/Http/Kernel.php` según versión de Laravel 12 usada en el proyecto)

**Checkpoint**: Infraestructura base lista para todas las user stories.

---

## Phase 2: Foundational (Blocking Prerequisites)

**⚠️ CRITICAL**: Ninguna user story puede completarse sin esta fase, porque las tres dependen de la reestructuración de rutas/middleware y de la nueva pantalla contenedora.

- [ ] T004 En `routes/web.php`, reemplazar el middleware `permiso:configuracion.usuarios` / `permiso:configuracion.roles` / `permiso:configuracion.funciones` por `SoloAdmin` (alias `admin`) en todos los sub-grupos de `Route::prefix('configuracion')` (usuarios, roles, depositos, funciones, mercadolibre, tiendanube, mi-perfil, arca). No se borran las filas de esos permisos granulares en `PermisoSeeder`/DB ni sus asignaciones a roles no-Admin — quedan sin uso pero no se limpian en esta feature (fuera de alcance)
- [ ] T005 En `routes/web.php`, eliminar la ruta `configuracion.usuarios.index` (GET `/`) del sub-grupo `usuarios` — conservar únicamente las rutas AJAX (`data`, `store`, `show`, update, etc.) bajo `SoloAdmin`
- [ ] T006 En `routes/web.php`, agregar `Route::get('/', [ConfiguracionController::class, 'index'])->name('configuracion.index')` bajo `SoloAdmin`, y `Route::put('ventas', [ConfiguracionVentasController::class, 'guardar'])->name('configuracion.ventas.guardar')`. Nota: `ConfiguracionVentasController` se implementa recién en T022 (US3) — para que la app no rompa antes de esa tarea, crear en este mismo paso un stub mínimo de la clase con el método `guardar` vacío (`return response()->json(['ok' => true])`), que T022 completa con la lógica real
- [ ] T007 Crear controlador `app/Http/Controllers/Configuracion/ConfiguracionController.php` con `index()`: carga `FuncionAvanzada::ordenadas()->get()`, `ConfiguracionVentas::first()`, `Categoria::venta()->activas()->orderBy('nombre')->get()`, `Vendedor::orderBy('nombre')->get()`, `ListaPrecio::where('activo', true)->orderBy('nombre')->get()`, y retorna la vista `configuracion.index` con todo eso
- [ ] T008 Extraer el contenido actual de `resources/views/configuracion/depositos/index.blade.php`, `funciones/index.blade.php`, `mercadolibre/index.blade.php`, `tiendanube/index.blade.php`, `arca/index.blade.php` a partials `_tab.blade.php` en cada carpeta (sin cambiar su lógica/JS interno), dejando los `index.blade.php` originales intactos por si alguna ruta directa los sigue usando internamente para AJAX
- [ ] T009 Crear `resources/views/configuracion/index.blade.php`: `@extends('layouts.default')`, estructura `nav-tabs` (Bootstrap) con ícono por tab (reutilizando `FuncionAvanzada->icono` para Depósitos/Mercado Libre/Tiendanube/Facturación Electrónica, ícono propio para "Funciones Avanzadas" y "Ventas"), tab "Funciones Avanzadas" activo por defecto, tabs de Depósitos/Mercado Libre/Tiendanube/Facturación Electrónica renderizados condicionalmente (`@if($funciones->firstWhere('clave','depositos')?->activa)` etc.), incluyendo cada `_tab.blade.php` correspondiente
- [ ] T010 En `resources/views/elements/sidebar.blade.php`, eliminar el bloque `<li>` completo de "Configuración & Ajustes" (líneas ~184-213: ícono SVG + `<ul>` de sub-ítems)
- [ ] T011 En `resources/views/elements/header.blade.php`, dentro de `header-profile2` (`card-body` del dropdown, línea ~95-104), envolver el ítem "Mi Perfil" (renombrado a "Empresa") y agregar debajo un nuevo ítem de link único "Configuración & Ajustes" → `route('configuracion.index')`, ambos dentro de `@if(auth()->user()->esAdmin())`
- [ ] T012 [P] Feature test `tests/Feature/Configuracion/AccesoAdminConfiguracionTest.php`: un usuario sin rol Admin recibe 403 en `configuracion.index`, `configuracion.mi-perfil.index`, `configuracion.depositos.index`, `configuracion.mercadolibre.index`, `configuracion.tiendanube.index`, `configuracion.arca.index`; un usuario con rol Admin recibe 200 en todas

**Checkpoint**: Rutas reestructuradas, pantalla contenedora con tabs funcionando, gate de Admin activo — las user stories pueden implementarse encima.

---

## Phase 3: User Story 1 - Gestión de usuarios centralizada en "Empresa" (Priority: P1) 🎯 MVP

**Goal**: La pantalla "Empresa" (ex "Mi Perfil") muestra datos fiscales + tabla de usuarios + alta de usuarios + acceso a Roles y Permisos, sin pantalla separada de "Usuarios y Permisos".

**Independent Test**: Como Admin, entrar a "Empresa", ver la tabla de usuarios, dar de alta uno nuevo vía modal AJAX, y confirmar que la URL vieja de "Usuarios y Permisos" ya no existe (404).

### Implementation for User Story 1

- [ ] T013 [US1] En `app/Http/Controllers/MiPerfilController.php` método `index()`: además de los datos fiscales ya existentes, pasar a la vista los datos que hoy pasa `UsuarioController@index` a `usuarios/index.blade.php` (roles disponibles para el modal, rutas AJAX de usuarios)
- [ ] T014 [US1] En `resources/views/configuracion/mi-perfil/index.blade.php`: renombrar el título de "Mi Perfil" a "Empresa"; agregar debajo de la tarjeta de datos fiscales una nueva tarjeta "Usuarios" con la tabla `#tabla-usuarios` (columnas Nombre/Email/Roles/Estado/Acciones), el botón "Nuevo Usuario" y el link "Roles y Permisos" (con su `@can`/gate actual), tal como estaban en `usuarios/index.blade.php`
- [ ] T015 [US1] Incluir en `mi-perfil/index.blade.php` el modal `@include('configuracion.usuarios._modal_form')` y cargar `resources/js/configuracion-usuarios.js` vía `@vite`, exponiendo `window.UsuariosConfig` con las mismas rutas (`data`, `store`, `show`) que hoy arma `usuarios/index.blade.php`
- [ ] T016 [US1] Eliminar `resources/views/configuracion/usuarios/index.blade.php` (la vista de listado propia) — conservar `_modal_form.blade.php` porque se reutiliza desde `mi-perfil/index.blade.php`
- [ ] T017 [US1] Verificar/actualizar cualquier otro `<a href="{{ route('configuracion.usuarios.index') ...">` remanente en el proyecto (helpers, seeders de menú, tests) para que apunte a `configuracion.mi-perfil.index`

**Checkpoint**: User Story 1 funcional y testeable de forma independiente.

---

## Phase 4: User Story 2 - Acceso a Empresa y Configuración restringido al rol Admin, en la topbar (Priority: P1)

**Goal**: Sólo Admin ve/accede a "Empresa" y "Configuración & Ajustes"; el acceso vive en el dropdown de la topbar como un único link a una pantalla con tabs (Funciones Avanzadas por defecto, gate de disponibilidad de tabs según toggles).

**Independent Test**: Con un usuario no-Admin, confirmar ausencia total de accesos (sidebar/topbar/URL directa). Con Admin, confirmar navegación al tab por defecto y aparición/desaparición de tabs al activar/desactivar funciones.

### Implementation for User Story 2

> Nota: gran parte de esta user story ya quedó resuelta en la fase Foundational (T004-T011) porque el gate de acceso y la pantalla con tabs son prerequisito compartido. Las tareas de esta fase son las específicas de comportamiento de tabs que faltan.

- [ ] T018 [US2] En `resources/views/configuracion/index.blade.php`, agregar el JS necesario (inline `@section('local-js')` o archivo dedicado `resources/js/configuracion.js`) para que, al togglear una Función Avanzada a "No" desde el tab "Funciones Avanzadas" (reutilizando el endpoint AJAX ya existente `FuncionAvanzadaController@estado`), el tab correspondiente desaparezca de la lista de tabs sin recargar la página, y si era el tab activo, se vuelva a mostrar "Funciones Avanzadas"
- [ ] T019 [US2] Mismo JS de T018: al activar una función a "Sí", su tab debe aparecer disponible en la lista de tabs sin recargar la página
- [ ] T020 [P] [US2] Feature test `tests/Feature/Configuracion/TabsConfiguracionTest.php`: la vista `configuracion.index` renderiza el tab "Funciones Avanzadas" como activo por defecto; con una función en `activa=false` su tab no aparece en el HTML; con `activa=true` sí aparece

**Checkpoint**: User Stories 1 y 2 funcionan juntas de forma independiente.

---

## Phase 5: User Story 3 - Configurar valores por defecto de "Crear Venta" (Priority: P2)

**Goal**: El tab "Ventas" permite definir Categoría/Vendedor/Lista de Precios/Tipo de Comprobante/Días de Vto. de Cobro por defecto, y "Crear Venta" los usa al dar de alta.

**Independent Test**: Configurar los 5 valores, abrir "Crear Venta" y confirmar precarga; editar una venta existente y confirmar que NO se ven afectados sus valores.

### Implementation for User Story 3

- [ ] T021 [P] [US3] Crear `resources/views/configuracion/ventas/_tab.blade.php`: formulario con selects Select2 (Categoría, Vendedor, Lista de Precios — con `dropdownParent` apuntando al contenedor del tab, no un modal), select simple Tipo de Comprobante (A/B/C/E + opción vacía), input numérico Días de Vto. de Cobro; cada select con opción explícita "Sin valor por defecto"
- [ ] T022 [US3] Crear `app/Http/Controllers/Configuracion/ConfiguracionVentasController.php` método `guardar(Request $request)`: valida (`categoria_id` nullable exists, `vendedor_id` nullable exists, `lista_precio_id` nullable exists, `tipo_comprobante` nullable in A,B,C,E, `dias_vto_cobro` nullable integer min:0), hace `ConfiguracionVentas::updateOrCreate([], $validado)`, responde JSON `{ok:true, mensaje:...}` (contrato en contracts/configuracion-ventas.md)
- [ ] T023 [P] [US3] JS del tab Ventas (`resources/js/configuracion-ventas.js` o inline): inicializa Select2 de los 3 catálogos, envía el formulario por AJAX a `configuracion.ventas.guardar`, muestra Toastr de éxito/error sin recargar
- [ ] T024 [US3] En `app/Http/Controllers/VentaController.php` método `create()`: cuando `!$venta && !$presupuestoOrigen`, cargar `ConfiguracionVentas::first()`, filtrar cada FK contra su catálogo actual (si el registro referenciado ya no existe, tratar como null) y calcular `fechaVtoCobro = $default->dias_vto_cobro !== null ? now()->addDays($default->dias_vto_cobro)->format('Y-m-d') : null`; agregar todo esto a `window.VentaFormData.defaults` (ver contracts/configuracion-ventas.md)
- [ ] T025 [US3] En `resources/js/ventas.js`, al inicializar el formulario para alta nueva, aplicar `data.defaults` (categoría, vendedor, lista de precios, tipo de comprobante, fecha de vto. de cobro) **antes** de que el usuario elija Cliente, preservando que `cliente.tipo_comprobante_defecto` siga pisando ese valor al seleccionar un Cliente (no tocar esa lógica ya existente en la línea ~414)
- [ ] T026 [P] [US3] Unit test `tests/Unit/ConfiguracionVentasTest.php`: dado `dias_vto_cobro = 15` y una fecha de emisión fija, el cálculo de `fecha_vto_cobro` resultante es la esperada; dado `dias_vto_cobro = null`, no se calcula fecha
- [ ] T027 [P] [US3] Feature test `tests/Feature/Configuracion/ConfiguracionVentasDefaultsTest.php`: configurar defaults y verificar que `ventas.create` los precarga; verificar que editar una Venta existente o convertir un Presupuesto no aplica los defaults; verificar que si el `categoria_id` configurado como default se borra del catálogo, `ventas.create` no falla y no precarga ese campo

**Checkpoint**: Las tres user stories funcionan de forma independiente y en conjunto.

---

## Phase 6: Polish & Cross-Cutting Concerns

- [ ] T028 Actualizar `docs/documentacion_principal_crm.md §5` (Módulo Configuración & Ajustes): reflejar el rótulo "Empresa" (ex "Mi Perfil"), la fusión con gestión de usuarios, el gate único por rol Admin (reemplazo de permisos granulares), la pantalla única con tabs y el nuevo tab "Ventas" con sus defaults
- [ ] T029 [P] Actualizar `docs/modelo_datos.md` con la tabla nueva `configuracion_ventas`
- [ ] T030 Correr `quickstart.md` completo (los 4 escenarios) manualmente contra el entorno local antes de dar la feature por terminada
- [ ] T031 Revisar que ningún otro lugar del código (breadcrumbs, tests existentes, `CREDENCIALES_ACCESO.txt` si aplica) siga referenciando "Usuarios y Permisos" o "Mi Perfil" como pantallas separadas

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: sin dependencias — puede arrancar de inmediato.
- **Foundational (Phase 2)**: depende de Setup (necesita `ConfiguracionVentas`, `ConfiguracionVentasController` referenciado en rutas, y `SoloAdmin`). Bloquea las 3 user stories.
- **User Story 1 (Phase 3)**: depende sólo de Foundational.
- **User Story 2 (Phase 4)**: depende de Foundational; gran parte de su comportamiento base ya la deja lista Foundational (T004-T011), esta fase agrega el detalle dinámico de mostrar/ocultar tabs sin reload.
- **User Story 3 (Phase 5)**: depende de Foundational (necesita `ConfiguracionController`/vista con tabs para tener dónde alojar el tab Ventas) y de T001-T002 (tabla/modelo `ConfiguracionVentas`). No depende de US1 ni US2 funcionalmente (podría probarse con el gate de acceso todavía en permisos viejos), pero comparte la misma pantalla contenedora de US2.
- **Polish (Phase 6)**: depende de que al menos US1+US2+US3 estén completas.

### Parallel Opportunities

- T002 y T003 en paralelo (modelos/middleware, archivos distintos).
- T012 puede escribirse en paralelo a T008-T011 (test vs. vistas), pero corre después de T004 para tener sentido.
- Dentro de US3: T021 y T023 en paralelo; T026 y T027 en paralelo entre sí y con T021-T025 (tests distintos de implementación, aunque T027 valida el resultado de T024).
- US1 y US3 pueden implementarse en paralelo por distintos desarrolladores una vez completada Foundational (no comparten archivos, salvo la vista contenedora `configuracion/index.blade.php` que ya la deja lista Foundational).

---

## Implementation Strategy

### MVP First (User Story 1)

1. Setup → Foundational → User Story 1 → validar independientemente (Empresa con usuarios) → demo.

### Incremental Delivery

1. Setup + Foundational → gate de Admin y pantalla con tabs listos (sin contenido de Ventas todavía).
2. + User Story 1 → Empresa reemplaza a Usuarios y Permisos → demo.
3. + User Story 2 (detalle de tabs dinámicos) → demo.
4. + User Story 3 → defaults de Ventas → demo.

Cada incremento no rompe el anterior: US1 no depende de US3, y viceversa.
