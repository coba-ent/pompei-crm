# Research: Reorganización de Configuración & Ajustes

## Decisión 1 — Gate de acceso Admin

**Decisión**: Crear middleware `SoloAdmin` que verifica `$request->user()->esAdmin()` y reemplazar, en
todos los sub-grupos de `configuracion.*` (usuarios/empresa, roles, depósitos, funciones, mercadolibre,
tiendanube, mi-perfil/empresa, arca, ventas-defaults), el middleware actual `permiso:configuracion.*`.

**Rationale**: `App\Models\User::esAdmin()` y `tienePermiso()` ya existen (`app/Models/User.php:58-72`) y
`VerificarPermiso` ya documenta en su docblock que "el rol Admin pasa siempre (Gate::before)". Es decir,
el rol Admin **ya tiene acceso hoy** a todo lo que gatean los permisos granulares — el cambio pedido no
es agregar un permiso nuevo, es **quitarle el acceso a quien NO es Admin** pero sí tenía un permiso
granular asignado (ej. un rol "Vendedor" con el permiso `configuracion.funciones` otorgado a mano). Un
middleware dedicado deja esa intención explícita en las rutas, en vez de depender de que ningún otro rol
tenga jamás esos permisos granulares asignados.

**Alternatives considered**:
- Reutilizar `permiso:` con un código ficticio que sólo Admin puede tener (ej. `permiso:solo-admin` con
  ese permiso asignado únicamente al rol Admin): rechazado porque agrega una fila de permiso en la DB
  que existe sólo para simular lo que `esAdmin()` ya resuelve directamente, y sería borrable/asignable
  por error desde la UI de Roles y Permisos.
- Gate de Laravel (`Gate::define('admin', ...)` + `->can('admin')` en rutas): funcionalmente equivalente
  a un middleware dedicado; se prefiere el middleware por consistencia con el patrón `permiso:` ya usado
  en todas las demás rutas de este grupo.

## Decisión 2 — Fusión de "Usuarios y Permisos" dentro de "Empresa"

**Decisión**: La vista `configuracion/mi-perfil/index.blade.php` (servida por
`App\Http\Controllers\MiPerfilController`) incorpora la tabla de usuarios y su modal de alta/edición
(`configuracion/usuarios/_modal_form.blade.php`), reutilizando el mismo `UsuarioController`
(`App\Http\Controllers\Configuracion\UsuarioController`, con `data`, `store`, `show`, etc.) y el mismo `resources/js/configuracion-usuarios.js`
sin modificarlos — sólo cambia la vista Blade que los incluye. Se elimina `usuarios/index.blade.php` y la
ruta `configuracion.usuarios.index`; las rutas AJAX (`configuracion.usuarios.data`, `.store`, `.show`,
etc.) se mantienen bajo el mismo prefijo `usuarios/*` porque son consumidas por el JS ya existente vía
`window.UsuariosConfig.rutas`, sólo que ahora se cargan desde la página "Empresa".

**Rationale**: Minimiza el diff — controlador, JS y modal de alta se reutilizan tal cual (el pedido del
usuario fue "esa tabla con ese botón", no rehacer la lógica de usuarios). El botón "Nuevo Usuario" y el
link "Roles y Permisos" (hoy con `@can('configuracion.roles')`) se llevan literalmente a la cabecera de
la nueva sección "Usuarios" dentro de "Empresa".

**Alternatives considered**:
- Reescribir la gestión de usuarios como un componente Livewire/Blade separado incluido vía `@include`:
  es lo que se hace, pero sin tocar el controlador ni el JS — sólo se mueve el `@include` y el script de
  la vista `usuarios/index.blade.php` actual a `mi-perfil/index.blade.php`.

## Decisión 3 — Ubicación del acceso: topbar en vez de sidebar

**Decisión**: El dropdown de usuario ya existente en `resources/views/elements/header.blade.php`
(`header-profile2`, línea ~83-116) ya contiene un único ítem "Mi Perfil" apuntando a
`route('configuracion.mi-perfil.index')`. Se renombra ese ítem a "Empresa" y se agrega, debajo, un
**único** ítem de link "Configuración & Ajustes" (no un submenú/dropdown anidado) que apunta a una nueva
ruta `configuracion.index`, envolviendo ambos ítems en `@if(auth()->user()->esAdmin())`. Esa nueva
pantalla `configuracion/index.blade.php` organiza Funciones Avanzadas, Depósitos, Mercado Libre,
Tiendanube, Facturación Electrónica y Ventas como **tabs Bootstrap client-side** (`nav-tabs` +
`tab-content`, sin fragmento `#` en la URL del navegador, cambiando sólo el contenido visible dentro de
la misma página) dentro de esa única vista, cada tab con su ícono. En `sidebar.blade.php` se elimina por
completo el bloque `<li>` que hoy contiene "Configuración & Ajustes" (líneas ~184-213), incluyendo su
ícono SVG y el `<ul>` de sub-ítems.

**Tab por defecto y gate de disponibilidad de tabs**: el tab activo al cargar la pantalla es siempre
"Funciones Avanzadas" (`nav-tabs` con esa pestaña `active` por defecto, sin depender de un hash de URL).
Los demás tabs con pantalla propia — Depósitos, Mercado Libre, Tiendanube, Facturación Electrónica —
sólo se muestran habilitados si su `FuncionAvanzada` correspondiente (`clave` = `depositos`,
`mercadolibre`, `tiendanube`, `facturacion_electronica`; ver `database/seeders/FuncionAvanzadaSeeder.php`)
tiene `activa = true`; si está en `false`, ese tab no aparece (o aparece deshabilitado con indicación de
"activalo primero desde Funciones Avanzadas"). Esto reutiliza el campo `activa` que **ya existe y ya se
controla** desde la pantalla "Funciones Avanzadas" (`FuncionAvanzadaController@estado`) — no se agrega
ningún campo nuevo para esto, sólo se lee `FuncionAvanzada::activa('depositos')` etc. al renderizar la
lista de tabs. El tab "Ventas" (nuevo, alcance de esta feature) no tiene una `FuncionAvanzada` asociada
y se muestra siempre. El tab "Funciones Avanzadas" en sí mismo (la lista de 10 tarjetas con toggles) se
muestra siempre, sea cual sea su propio estado, porque es el control desde donde se decide todo lo demás.

**Íconos de los tabs**: cada `FuncionAvanzada` ya tiene un campo `icono` (clase Font Awesome, ej.
`fas fa-warehouse` para Depósitos, `fas fa-store` para Mercado Libre, `fas fa-shopping-bag` para
Tiendanube, `fas fa-file-invoice-dollar` para Facturación Electrónica) — se reutiliza ese mismo ícono en
el tab correspondiente. El tab "Funciones Avanzadas" usa un ícono propio (ej. `fas fa-sliders-h`) y el
tab nuevo "Ventas" usa un ícono acorde (ej. `fas fa-file-invoice` o `fas fa-cash-register`, a definir en
implementación siguiendo el set Font Awesome ya usado en el resto del sidebar/tabs).

**Rationale**: Reutiliza el dropdown existente en vez de crear un mecanismo de navegación nuevo. El
usuario pidió explícitamente que **no** sea un submenú desplegable dentro del dropdown de la topbar, sino
un solo link a una vista propia con las distintas configuraciones divididas en tabs — esto es
consistente con la convención ya establecida en el proyecto ("un solo link de menú + tabs client-side
normales dentro del shell" es el modelo permitido; lo prohibido es tener varios links de menú apuntando
al mismo shell a togglear una tab, que no es este caso).

**Alternatives considered**:
- Submenú/dropdown anidado dentro del dropdown de la topbar con un ítem por cada sub-pantalla: es lo que
  se había planteado inicialmente y el usuario lo descartó explícitamente — se reemplaza por la pantalla
  única con tabs.
- Navegación por tabs con hash en la URL (`configuracion#ventas`): descartada por la convención ya
  fijada en el proyecto de no usar fragmentos `#` para navegar entre secciones.
- Dropdown Bootstrap independiente en la topbar (fuera del ya existente): rechazado, duplicaría un
  patrón de UI que ya existe.

## Decisión 4 — Defaults de Ventas: modelo de datos y aplicación

**Decisión**: Nueva tabla `configuracion_ventas` de fila única (mismo patrón que `empresa`, sin
`empresa_id`, sin timestamps de auditoría por-usuario): `categoria_id` (nullable, FK a `categorias`),
`vendedor_id` (nullable, FK a `vendedores`), `lista_precio_id` (nullable, FK a `listas_precio`),
`tipo_comprobante` (nullable, enum A/B/C/E), `dias_vto_cobro` (nullable, entero ≥ 0). `VentaController@create`
consulta esta fila (con `firstOrNew`/`first()`) sólo cuando **no** hay `$venta` (edición) ni
`$presupuestoOrigen` (conversión), y pasa los defaults a la vista para precargar los `<select>` y calcular
`fecha_vto_cobro = hoy + dias_vto_cobro` (cuando ese default existe). Las FK usan `->nullOnDelete()` (o
verificación en el controlador antes de usar el default) para que borrar una Categoría/Vendedor/Lista de
Precios referenciada como default no rompa "Crear Venta" (FR-013): si el `id` referenciado ya no existe
en su catálogo, ese campo simplemente no se precarga.

**Rationale**: Sigue el patrón de "fila única" ya validado en el proyecto para configuración global
single-tenant (tabla `datos_empresa` / modelo `DatosEmpresa`, ver Principio V de la constitución: "no
existe `empresa_id`... la configuración del negocio vive en la fila única"). Nullable en todos los
campos porque cada uno es independientemente opcional (FR-009).

**Interacción con el override de Cliente ya existente**: `resources/js/ventas.js:414` ya setea
`$('#f-tipo-comprobante')` a `cliente.tipo_comprobante_defecto` cuando se selecciona un Cliente que tiene
ese campo cargado. Ese override debe seguir ganando: el default global de Configuración & Ajustes sólo
aplica como preselección inicial (antes de elegir cliente); si el Cliente elegido trae su propio default
de tipo de comprobante, ese valor pisa al default global, igual que hoy pisa al hardcodeado "B".

**Alternatives considered**:
- Guardar los defaults como filas en la tabla `configuraciones` genérica tipo key-value (si existiera):
  no existe tal tabla en el proyecto; se descarta para no introducir un mecanismo genérico nuevo cuando
  el patrón de fila única ya es el establecido.
