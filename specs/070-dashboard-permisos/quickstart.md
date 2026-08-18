# Quickstart: validar el Dashboard filtrado por permisos

## Prerrequisitos

- Base local con los seeders de permisos/roles corridos (`PermisoSeeder`, `RolSeeder`).
- Al menos un usuario de prueba con un rol acotado. Si no existe, crear uno rápido:

```php
// tinker o seeder puntual
$rol = \App\Models\Rol::firstOrCreate(['nombre' => 'Vendedor Prueba']);
$rol->permisos()->sync(\App\Models\Permiso::where('codigo', 'ventas.ver')->pluck('id'));
$user = \App\Models\User::where('email', 'vendedor.prueba@test.local')->first()
    ?? \App\Models\User::factory()->create(['email' => 'vendedor.prueba@test.local']);
$user->roles()->sync([$rol->id]);
```

Recordar anotar cualquier alta/reset de credencial de prueba en `CREDENCIALES_ACCESO.txt` (regla
del proyecto).

## Escenario 1 — Usuario con `ventas.ver` únicamente (US1)

1. Loguearse como el usuario de prueba de arriba.
2. Ir a `/dashboard`.
3. **Esperado**: se ven KPIs de Ventas (ventas creadas, venta promedio, cantidad de ventas) y la
   barra de Totales de Ventas. No se ve el KPI "Resultado", ni Totales/KPIs de Otros
   Ingresos/Compras/Gastos, ni el bloque de tesorería (Saldos/Movimientos/Cuentas a
   Cobrar-Pagar), ni los Rankings (porque además de `ventas.ver` hacen falta `clientes.ver`/
   `productos.ver`).
4. Abrir las herramientas de red del navegador, cambiar el período (ej. a "Semana") y confirmar
   que `GET /dashboard/kpis?periodo=semana` no trae `resultado`, y que `GET /dashboard/totales`
   sólo trae la clave `ventas`.

## Escenario 2 — Backend nunca expone lo que el usuario no puede ver (US2)

1. Con la sesión del mismo usuario, llamar directamente (ej. pegando la URL o con `curl` con la
   cookie de sesión) a `GET /dashboard/totales?periodo=mes_actual`.
2. **Esperado**: la respuesta JSON no contiene las claves `otros_ingresos`, `compras` ni `gastos`
   bajo ninguna forma (ni con el valor real, ni con `0`, ni con `null`).
3. Repetir con `GET /dashboard/donas` y `GET /dashboard/rankings` — mismas ausencias.

## Escenario 3 — Admin no ve ningún cambio (US3)

1. Loguearse como un usuario Admin (o con los 7 permisos `.ver`).
2. Ir a `/dashboard`.
3. **Esperado**: se ve exactamente lo mismo que antes de este cambio — todos los KPIs, Totales,
   gráfico mensual completo, las 3 donas, ambos rankings y el bloque de tesorería.

## Escenario 4 — Usuario sin ningún permiso relevante (US4)

1. Crear/usar un usuario cuyo único permiso sea, por ejemplo, `mensajeria.ver`.
2. Ir a `/dashboard`.
3. **Esperado**: la página responde 200 (no 403, no redirección) y no muestra ningún widget de
   datos financieros — el layout queda prácticamente vacío de contenido de negocio.

## Validación automatizada

```bash
php artisan test --filter=Dashboard
```

Debe incluir el nuevo `DashboardPermisosTest` (cubre los 4 escenarios de arriba) y las extensiones
de permiso parcial agregadas a los tests Feature existentes de cada endpoint.
