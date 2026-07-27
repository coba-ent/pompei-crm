# Contrato de rutas: Módulo Inicio (Dashboard)

Todas dentro de `Route::middleware('auth')->group(...)` (ya existente en `routes/web.php`). **Sin**
middleware `permiso:` — a diferencia del resto de los módulos (ver Assumptions de spec.md: todavía no
hay Roles y Permisos con granularidad sobre el dashboard; es la pantalla de aterrizaje y debe quedar
visible para cualquier usuario autenticado, igual criterio que hoy tiene la ruta raíz `name('home')`).

| Método | Ruta | Nombre | Controlador@método | Devuelve |
|---|---|---|---|---|
| GET | `/dashboard` | `dashboard.index` | `DashboardController@index` | Vista `dashboard.index` (incluye Tesorería y Cuentas a Cobrar/Pagar ya resueltas server-side; período por defecto Mes Actual) |
| GET | `/dashboard/kpis` | `dashboard.kpis` | `DashboardController@kpis` | JSON: 4 KPIs + variación %, según `?periodo=` |
| GET | `/dashboard/totales` | `dashboard.totales` | `DashboardController@totales` | JSON: Total Ventas/Otros Ingresos/Compras/Gastos, según `?periodo=` |
| GET | `/dashboard/grafico-mensual` | `dashboard.grafico-mensual` | `DashboardController@graficoMensual` | JSON: serie de 12 meses (4 series) — **no** usa `?periodo=`, siempre últimos 12 meses fijos |
| GET | `/dashboard/donas` | `dashboard.donas` | `DashboardController@donas` | JSON: 3 donas (Ventas/Compras/Gastos por categoría), según `?periodo=` |
| GET | `/dashboard/rankings` | `dashboard.rankings` | `DashboardController@rankings` | JSON: Ranking Clientes + Ranking Productos, según `?periodo=` |

`?periodo=` acepta: `semana` \| `mes_actual` (default) \| `mes_anterior` \| `anio_actual`. Valor
inválido o ausente → `mes_actual`.

Tesorería y Cuentas a Cobrar/Pagar **no** tienen endpoint AJAX propio: se resuelven una sola vez dentro
de `index()` (no dependen de `?periodo=`, ver research.md §3), evitando trabajo de servidor innecesario
en cada cambio de tab del selector de período.

## Cambios en rutas existentes

- `Route::get('/', ...)->name('home')` (hoy `ClienteController::index`) pasa a
  `Route::redirect('/', '/dashboard')` — Clientes deja de ser la pantalla de aterrizaje; el sidebar ya
  la referencia por su propia ruta `clientes.index`, no por `/`.
- `config('dz.pagelevel.home')` (ya existente, con ApexCharts/Toastr/daterangepicker/moment) se
  reutiliza para el `$CurrentPage = 'home'` de `DashboardController` — sin renombrar la key, para no
  romper la configuración original del template.

## Ejemplo de respuesta — `GET /dashboard/kpis?periodo=mes_actual`

```json
{
  "ventas_creadas": { "valor": 458200.00, "variacion_pct": 12.4 },
  "venta_promedio": { "valor": 15273.33, "variacion_pct": -3.1 },
  "cantidad_ventas": { "valor": 30, "variacion_pct": 20.0 },
  "resultado": { "valor": 96450.00, "variacion_pct": null }
}
```

`variacion_pct: null` indica "sin datos previos" (el período anterior tuvo valor 0) — el front lo
renderiza sin flecha de color, con el texto de la spec (US1-AC2).

## Ejemplo de respuesta — `GET /dashboard/index` (bloque de Cuentas a Cobrar, embebido server-side)

```json
{
  "cuentas_a_cobrar": {
    "total": 128400.50,
    "buckets": {
      "a_vencer": 40000.00, "vencido": 88400.50,
      "0_30": 30000.00, "31_60": 28400.50, "61_90": 15000.00, "mas_90": 15000.00
    }
  }
}
```
