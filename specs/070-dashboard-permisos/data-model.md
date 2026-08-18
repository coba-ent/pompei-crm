# Data Model: Dashboard filtrado por permisos

No se agregan entidades, columnas ni tablas nuevas. Esta feature consume el modelo de
permisos ya existente en el proyecto:

## Entidades existentes reutilizadas

### User (`app/Models/User.php`)
- `roles()`: `BelongsToMany` a `Rol` vía `rol_usuario`.
- `esAdmin()`: `true` si el usuario tiene el rol `Admin`.
- `tienePermiso(string $codigo): bool`: `true` si es Admin, o si alguno de sus roles tiene el
  permiso `$codigo` asignado. Es el único punto de entrada que esta feature usa para decidir
  visibilidad — no se agrega un método nuevo al modelo, sólo se lo invoca 7 veces (una por rubro)
  desde el controller.

### Rol (`app/Models/Rol.php`)
- Relación `permisos()` (many-to-many vía `permiso_rol`) — sin cambios.

### Permiso (`app/Models/Permiso.php`)
- Catálogo ya sembrado por `database/seeders/PermisoSeeder.php`. Los 7 códigos relevantes para
  esta feature (todos ya existentes, ninguno nuevo):

| Código | Rubro/widget del Dashboard que habilita |
|---|---|
| `ventas.ver` | KPIs de ventas, barra de Totales Ventas, serie Ventas (gráfico + dona), Ranking Clientes (junto con `clientes.ver`), Ranking Productos (junto con `productos.ver`) |
| `otros-ingresos.ver` | Barra de Totales Otros Ingresos, serie Otros Ingresos (gráfico) |
| `compras.ver` | KPIs/Totales Compras, serie Compras (gráfico + dona) |
| `gastos.ver` | KPIs/Totales Gastos, serie Gastos (gráfico + dona) |
| `clientes.ver` | Ranking de Clientes (junto con `ventas.ver`) |
| `productos.ver` | Ranking de Productos (junto con `ventas.ver`) |
| `tesoreria.ver` | Saldos, Movimientos recientes, Cuentas a Cobrar/Pagar |

## Estructura en memoria (no persistida): `permisosRubros`

Array asociativo calculado por request en `DashboardController` (ver `research.md` Decisión 1),
usado tanto por la vista Blade como por cada endpoint AJAX para decidir qué calcular y exponer:

```php
[
    'ventas' => bool,
    'otros_ingresos' => bool,
    'compras' => bool,
    'gastos' => bool,
    'clientes' => bool,
    'productos' => bool,
    'tesoreria' => bool,
]
```

Regla derivada (no un campo propio, ver `research.md` Decisión 3): `resultado_visible` = `ventas
&& otros_ingresos && compras && gastos`.

No hay transiciones de estado ni ciclo de vida — esta estructura vive sólo durante el request, se
recalcula en cada llamada (no se cachea entre requests, ya que el rol de un usuario puede cambiar).
