# Research: Permisos granulares por informe (spec 090)

**Fecha**: 2026-08-28

Todas las incógnitas técnicas se resolvieron leyendo el código vigente. No quedan
`NEEDS CLARIFICATION`.

---

## Decisión 1 — Cómo exigir dos permisos (informe + descarga) en una misma ruta

**Decisión**: encadenar el alias `permiso` dos veces en la misma ruta:
`->middleware(['permiso:informes.ventas', 'permiso:informes.exportar'])`.

**Rationale**: `App\Http\Middleware\VerificarPermiso` recibe **un solo** código por parámetro
(`handle(Request, Closure, string $codigo)`) y aborta con 403 si falta. Laravel ejecuta la pila de
middleware en orden, así que dos instancias del mismo alias con parámetros distintos producen
exactamente la conjunción buscada: pasa sólo quien tiene ambos. No hace falta tocar el middleware
ni inventar una sintaxis de lista.

Esto satisface FR-003 de forma natural: `informes.exportar` sin el permiso del informe no alcanza,
porque el primer middleware de la cadena ya corta.

**Alternativas rechazadas**:
- *Extender `VerificarPermiso` a una lista separada por coma con semántica AND*: cambia un
  middleware usado por todos los módulos del sistema para un caso que la composición ya resuelve.
- *Un middleware nuevo `permiso.todos`*: duplica lógica existente.
- *Verificar en el controlador con `tienePermiso()`*: dispersa la regla de acceso en 8 controladores
  en vez de dejarla declarada en `routes/web.php`, donde hoy vive y donde es auditable de un vistazo.

---

## Decisión 2 — Estructura del bloque de rutas de Informes

**Decisión**: reestructurar todo el módulo dentro de un único `Route::prefix('informes')` con
**sub-grupos por informe**, cada uno con su `middleware('permiso:informes.<informe>')`, y las rutas
de descarga (`exportar`, `pdf`, `iva-digital`, `pivot/exportar`) elevando el permiso con un
`->middleware('permiso:informes.exportar')` adicional a nivel de ruta.

**Rationale**: es el patrón ya usado en el proyecto — ver el bloque de Tesorería en
`routes/web.php`, donde `cuentas/orden` eleva el permiso del grupo (`tesoreria.ver`) a
`tesoreria.editar` con un `->middleware()` por ruta. Reusar ese patrón mantiene la coherencia y
hace que el bloque se lea como la tabla de permisos de la spec.

Esto además cierra estructuralmente el bug de FR-011: hoy `informes/stock` y
`informes/cuenta-corriente/*` están **antes** del `Route::middleware('permiso:informes.ver')->group()`
de la línea 180, y por eso quedaron sin control. Al no existir más un bloque "suelto" antes del
grupo, la clase de error deja de ser posible: toda ruta del módulo nace dentro de un sub-grupo con
permiso.

**Alternativas rechazadas**:
- *Dejar la estructura actual y sólo mover las dos rutas huérfanas adentro del grupo*: arregla el
  síntoma pero deja el módulo con un único permiso, que es el pedido central.
- *Middleware a nivel de controlador (constructor)*: Laravel 12 favorece la declaración en rutas y el
  resto del proyecto lo hace así; mezclar estilos complica auditar quién protege qué.

---

## Decisión 3 — Códigos de permiso y su encaje en la pantalla de Roles

**Decisión**: nueve permisos nuevos en el módulo `informes`, con estos códigos:

| Código | Descripción (la que verá el admin) |
|---|---|
| `informes.ventas` | Ver el Informe de Ventas (incluye sus rankings y "Arma tu Informe") |
| `informes.compras` | Ver el Informe de Compras (incluye sus rankings y "Arma tu Informe") |
| `informes.gastos` | Ver el Informe de Gastos |
| `informes.stock` | Ver el Informe de Stock |
| `informes.cuenta-corriente-clientes` | Ver la Cuenta Corriente de Clientes |
| `informes.cuenta-corriente-proveedores` | Ver la Cuenta Corriente de Proveedores |
| `informes.reporte-final` | Ver el Reporte Final (incluye márgenes y costo de mercadería vendida) |
| `informes.contador` | Ver Información para tu Contador (Libro IVA, IVA Digital, envío al contador) |
| `informes.exportar` | Exportar a Excel y generar PDF de los informes que ya pueda ver |

**Rationale**: respetan el formato `modulo.accion` del catálogo (`PermisoSeeder`), quedan agrupados
bajo `modulo = 'informes'`, y usan guiones dentro de la acción igual que el módulo ya existente
`otros-ingresos`. La pantalla de Roles (`RolController::permisos()`) arma la matriz con
`Permiso::orderBy('modulo')->orderBy('codigo')->get()->groupBy('modulo')`, es decir **totalmente
dinámica**: absorbe los nueve permisos sin ningún cambio de código en el controlador ni en el modal.
Eso cubre FR-006 y SC-006.

Nota de orden: al ordenarse por `codigo`, `informes.exportar` queda alfabéticamente en el medio de la
lista, no al final. Es aceptable —la descripción lo identifica— y no justifica agregar una columna de
orden al catálogo de permisos sólo para este módulo.

**Alternativas rechazadas**:
- *`informes.ver.ventas`* (tres niveles): rompe el formato `modulo.accion` que asume el catálogo.
- *Módulo propio por informe* (`informe-ventas.ver`): fragmentaría la pantalla de Roles en ocho
  grupos de un permiso cada uno, ilegible.

---

## Decisión 4 — Migración de datos: reparto por rol, no traslado uniforme

**Decisión**: una migración que, en `up()`:
1. Inserta los nueve permisos nuevos (idempotente, por si el seeder ya corrió).
2. Asigna al rol **Contable**: `informes.compras`, `informes.gastos`,
   `informes.cuenta-corriente-proveedores`, `informes.contador`, `informes.exportar`.
3. Al rol **Vendedor**: nada.
4. A **cualquier otro rol** que tenga `informes.ver` y no sea Admin/Vendedor/Contable: los ocho
   permisos de informe + `informes.exportar` (preserva su acceso vigente, FR-024).
5. Borra `informes.ver` del catálogo; el pivot se limpia por la FK en cascada o explícitamente antes.

**Rationale**: el estado real de la base (verificado con Tinker) es Admin 3 usuarios / Vendedor 2
usuarios / Contable 0 usuarios, y sólo Contable tiene `informes.ver`. Un traslado uniforme
("a todos los que tenían el viejo, dales los ocho") le dejaría al Contable el Reporte Final y la Cta
Cte de Clientes, que es justamente lo que la feature quiere evitar. Como Contable no tiene usuarios
asignados, reducir su alcance no rompe a nadie en uso.

Al rol Admin no se le toca nada: `User::tienePermiso()` corta antes por `esAdmin()`, así que pasa
cualquier permiso sin necesidad de tenerlo asignado. (El `RolSeeder` igual le sincroniza todos los
permisos existentes, y eso sigue funcionando solo.)

**Alternativas rechazadas**:
- *Traslado uniforme de los 8 a todo rol con `informes.ver`*: contradice FR-022 y el objetivo de la
  feature; le daría al Contable informes que exceden su función.
- *Comando de artisan manual post-deploy*: en producción alguien tiene que acordarse de correrlo; una
  migración corre sola con el deploy y queda registrada.
- *Sólo cambiar el seeder*: los seeders no vuelven a correr sobre una base en producción con datos.

---

## Decisión 5 — Idempotencia y coherencia entre migración y seeders

**Decisión**: la migración es la fuente de verdad para bases existentes; `PermisoSeeder` y
`RolSeeder` se actualizan para que una instalación desde cero produzca **el mismo estado** (FR-028).
`PermisoSeeder` ya usa `updateOrCreate` por código y `RolSeeder` usa `sync()`, así que ambos son
idempotentes y no hace falta cambiar su mecánica, sólo sus datos.

**Rationale**: sin esto, una base nueva (tests, ambiente limpio) tendría un reparto por rol distinto
al de producción, y los tests de la migración pasarían mientras el sistema real diverge. Es el
mismo riesgo que ya está anotado en memoria sobre "la suite verde no garantiza nada" cuando el
seeder y la migración no cuentan la misma historia.

**Nota para el `RolSeeder`**: hoy sincroniza al rol Contable con una lista que incluye `informes.ver`.
Esa entrada se reemplaza por los cinco códigos nuevos del reparto. Como usa `sync()`, un rol Contable
preexistente en una base de desarrollo queda alineado si se vuelve a correr el seeder.

---

## Decisión 6 — Ocultar los botones de exportación sin permiso (FR-018)

**Decisión**: envolver los controles de exportar/PDF de cada vista de informe en
`@can('informes.exportar')`.

**Rationale**: `Gate` ya está wireado con el catálogo de permisos (el sidebar y los `_row_actions`
usan `@can` con códigos de permiso), así que no hace falta infraestructura nueva. La protección real
sigue siendo el middleware de la ruta (FR-010); el `@can` es sólo para no ofrecer un botón que
fallaría — que es exactamente la lección del bug que esta spec arregla: el `@can` **nunca** es el
control de acceso, es cosmética.

**Alternativas rechazadas**:
- *Deshabilitar el botón en vez de ocultarlo*: muestra una capacidad que el usuario no tiene y invita
  a preguntar por qué; el resto del sistema oculta.

---

## Decisión 7 — Alcance de los tests

**Decisión**: un test de feature nuevo, `tests/Feature/InformesPermisosTest.php`, con:
- Un caso por ruta del módulo verificando 403 sin el permiso correspondiente (con foco explícito en
  las rutas hoy desprotegidas de Stock y Cta Cte Clientes, que son la regresión que se está
  arreglando).
- Casos de descarga: informe sí / exportar no → 403; exportar sí / informe no → 403; ambos → 200.
- Casos de aislamiento: un usuario con un solo informe no accede a los otros siete.
Más `tests/Feature/InformesPermisosMigracionTest.php` verificando el reparto por rol tras la
migración y que `informes.ver` ya no existe.

**Rationale**: la constitución (principio IV) exige tests donde hay impacto de dinero o datos
sensibles; acá se trata de acceso a cuentas corrientes, márgenes y Libro IVA. Además el bug que
motivó la spec es exactamente el tipo de error que un test de ruta hubiera atrapado.

**Nota de entorno**: hay memoria del proyecto sobre MySQL estricto vs. SQLite en tests. Estos tests
son de autorización (códigos de estado), no de agregaciones SQL, así que no los afecta el
`ONLY_FULL_GROUP_BY`. Aun así, la validación final se hace también en el navegador con un usuario
real por rol, según el quickstart.
