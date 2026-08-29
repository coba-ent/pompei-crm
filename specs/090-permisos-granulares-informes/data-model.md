# Data Model: Permisos granulares por informe (spec 090)

**Fecha**: 2026-08-28

## Alcance del cambio de datos

**No se crean ni modifican tablas ni columnas.** La feature opera enteramente sobre filas de las
tablas existentes del módulo de Usuarios y Permisos:

- `permisos` — se agregan 9 filas, se elimina 1.
- `permiso_rol` (pivot) — se reasignan filas según el reparto por rol.

Por eso `docs/modelo_datos.md` **no requiere cambios de esquema**; sí se actualiza la enumeración del
catálogo de permisos si la lista está transcripta ahí.

## Entidad: Permiso (existente)

| Campo | Tipo | Nota |
|---|---|---|
| `id` | PK | |
| `codigo` | string, único | Formato `modulo.accion` |
| `descripcion` | string | Texto en español que ve el admin en la matriz de roles |
| `modulo` | string | Agrupador de la pantalla de Roles |

### Filas a eliminar

| `codigo` | `modulo` |
|---|---|
| `informes.ver` | `informes` |

### Filas a crear

| `codigo` | `descripcion` | `modulo` |
|---|---|---|
| `informes.ventas` | Ver el Informe de Ventas (incluye sus rankings y "Arma tu Informe") | `informes` |
| `informes.compras` | Ver el Informe de Compras (incluye sus rankings y "Arma tu Informe") | `informes` |
| `informes.gastos` | Ver el Informe de Gastos | `informes` |
| `informes.stock` | Ver el Informe de Stock | `informes` |
| `informes.cuenta-corriente-clientes` | Ver la Cuenta Corriente de Clientes | `informes` |
| `informes.cuenta-corriente-proveedores` | Ver la Cuenta Corriente de Proveedores | `informes` |
| `informes.reporte-final` | Ver el Reporte Final (incluye márgenes y costo de mercadería vendida) | `informes` |
| `informes.contador` | Ver Información para tu Contador (Libro IVA, IVA Digital, envío al contador) | `informes` |
| `informes.exportar` | Exportar a Excel y generar PDF de los informes que ya pueda ver | `informes` |

**Reglas de validación**: `codigo` único (ya lo impone el esquema); `modulo` debe ser `informes` para
que la matriz de Roles los agrupe correctamente (FR-006).

## Entidad: Rol (existente) — estado objetivo del pivot

Estado real de la base al 2026-08-28, verificado con Tinker:

| Rol | `es_sistema` | Usuarios | Tiene `informes.ver` hoy |
|---|---|---|---|
| Admin | sí | 3 | sí (por `sync` de todos los permisos) |
| Vendedor | no | 2 | **no** |
| Contable | no | 0 | **sí** |

### Reparto objetivo

| Rol | Permisos de informe asignados | `informes.exportar` |
|---|---|---|
| **Admin** | los 8 (irrelevante en la práctica: `esAdmin()` corta antes en `tienePermiso()`) | sí |
| **Contable** | `informes.compras`, `informes.gastos`, `informes.cuenta-corriente-proveedores`, `informes.contador` | sí |
| **Vendedor** | ninguno | no |
| **Cualquier otro rol con `informes.ver`** | los 8 | sí |

Criterio del reparto (FR-022): a cada rol, los informes de los módulos que ese rol ya administra.
Contable administra compras, gastos, proveedores y tesorería → recibe Compras, Gastos, Cta Cte
Proveedores y el bloque del contador. No recibe Ventas, Stock, Cta Cte Clientes ni Reporte Final.

**Efecto neto**: Contable pasa de 65 rutas accesibles a 37. Vendedor sigue en 0. Admin en 65.

## Transiciones de estado (migración)

`up()` — orden obligatorio, todo dentro de una transacción:

1. Insertar las 9 filas nuevas en `permisos` (idempotente: `updateOrCreate` por `codigo`, por si el
   `PermisoSeeder` ya corrió en ese ambiente).
2. Resolver el id de `informes.ver`. Si no existe, saltar los pasos 3–5 (base nueva ya seeded).
3. Asignar al rol **Contable** sus 5 códigos (attach idempotente, sin `sync`: no debe tocar sus
   permisos de compras/gastos/proveedores/tesorería).
4. Para todo rol que tenga `informes.ver` y **no** sea Admin, Vendedor ni Contable: asignar los 9.
5. Borrar las filas de `permiso_rol` con ese `permiso_id`, y luego la fila de `permisos`.

`down()` — reversible:

1. Recrear `informes.ver`.
2. Asignarlo a todo rol que tenga al menos un permiso de informe de los nuevos.
3. Borrar los pivots de los 9 y luego las 9 filas de `permisos`.

> No es una reversión perfecta a nivel de qué informes veía cada rol (el reparto es una decisión de
> negocio, no un dato derivable), pero devuelve el sistema a un estado funcional equivalente al
> anterior: quien tenía informes vuelve a tener acceso a todos.

## Entidad: InformeVista (existente, sin cambios de esquema)

Sólo cambia la **regla de acceso**, que vive en las rutas (FR-019): las vistas de `informe = 'ventas'`
se rigen por `informes.ventas` y las de `informe = 'compras'` por `informes.compras`. El docblock del
modelo (`app/Models/InformeVista.php:17`) menciona `informes.ver` y debe actualizarse.

Se mantiene la regla de la spec 069 FR-042: **sin permiso propio de escritura** (FR-020).

## Consistencia migración ↔ seeders (FR-028)

| Archivo | Cambio |
|---|---|
| `database/seeders/PermisoSeeder.php` | En el catálogo, reemplazar la entrada `'informes' => ['ver' => ...]` por las 9 acciones nuevas |
| `database/seeders/RolSeeder.php` | En el `sync()` del rol Contable, reemplazar `'informes.ver'` por los 5 códigos del reparto. El rol Vendedor no se toca. Admin sigue con `sync(Permiso::pluck('id'))`, que absorbe los nuevos solo |

Una instalación desde cero debe terminar con exactamente el mismo estado que produce la migración
sobre la base existente. Los tests deben verificar el reparto partiendo del seeder, no de fixtures
propias, para que esa divergencia no pase inadvertida.
