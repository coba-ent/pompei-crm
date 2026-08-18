# Contratos: endpoints del Dashboard (post-filtrado por permisos)

Los 5 endpoints AJAX ya existentes (rutas en `routes/web.php`, prefijo `dashboard.`) mantienen su
URL, verbo, parámetros de query (`periodo`) y forma general de la respuesta. Lo que cambia es que
las claves de rubros sin permiso **se omiten** de la respuesta (nunca `null`, nunca `0` como señal
de "no autorizado" — un `0` sigue siendo un valor real y válido cuando el usuario sí tiene permiso
pero el rubro no tuvo movimientos).

Todos los ejemplos asumen un usuario con `ventas.ver` pero **sin** `otros-ingresos.ver`,
`compras.ver`, `gastos.ver`, `clientes.ver`, `productos.ver` ni `tesoreria.ver`.

## `GET /dashboard/kpis?periodo=mes_actual`

Hoy devuelve siempre `ventas_creadas`, `venta_promedio`, `cantidad_ventas`, `resultado`.

Con el usuario de ejemplo:

```json
{
  "ventas_creadas": { "valor": 123456.78, "variacion_pct": 4.2 },
  "venta_promedio": { "valor": 4567.89, "variacion_pct": -1.1 },
  "cantidad_ventas": { "valor": 27, "variacion_pct": 8.0 }
}
```

`resultado` está ausente (falta `otros-ingresos.ver`/`compras.ver`/`gastos.ver` → no cumple la
regla de Decisión 3 en `research.md`).

## `GET /dashboard/totales?periodo=mes_actual`

Hoy devuelve siempre `ventas`, `otros_ingresos`, `compras`, `gastos`.

Con el usuario de ejemplo:

```json
{ "ventas": 123456.78 }
```

## `GET /dashboard/grafico-mensual`

Hoy devuelve `labels` (fijo, 12 meses) y `series` con las 4 claves de rubro.

Con el usuario de ejemplo:

```json
{
  "labels": ["2025-09", "2025-10", "...", "2026-08"],
  "series": { "ventas": [/* 12 valores */] }
}
```

`labels` siempre se envía completo (no es información sensible por sí sola). Dentro de `series`,
sólo están las claves de rubro con permiso.

## `GET /dashboard/donas?periodo=mes_actual`

Hoy devuelve `ventas`, `compras`, `gastos` (array de `{categoria, monto}`).

Con el usuario de ejemplo:

```json
{ "ventas": [{ "categoria": "Indumentaria", "monto": 50000 }, { "categoria": "Sin categoría", "monto": 12000 }] }
```

## `GET /dashboard/rankings?periodo=mes_actual`

Hoy devuelve `clientes` y `productos`.

Con el usuario de ejemplo (tiene `ventas.ver` pero no `clientes.ver` ni `productos.ver`):

```json
{}
```

Si además tuviera `clientes.ver` (pero no `productos.ver`):

```json
{ "clientes": [{ "nombre": "...", "monto": 10000 }] }
```

## `GET /dashboard` (vista `index`)

Sigue respondiendo 200 para cualquier usuario autenticado (FR-001/FR-012). La vista recibe la
variable `permisos` (el array de `research.md`/`data-model.md`) además de las variables ya
existentes (`saldos`, `movimientosRecientes`, `cuentasACobrar`, `cuentasAPagar` — estas 4 sólo se
calculan si `permisos['tesoreria']` es `true`; si es `false`, se pasan como colecciones/arrays
vacíos y la vista igual las envuelve en `@if($permisos['tesoreria'])`, así que nunca se renderizan).

## Compatibilidad

No hay breaking change de contrato para un usuario Admin o con los 7 permisos: la respuesta es
idéntica a la actual. El cambio de forma (claves ausentes) sólo es observable para usuarios con
permisos parciales, que hoy de todos modos no deberían estar viendo esos datos.
