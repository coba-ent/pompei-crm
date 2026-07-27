# Quickstart: Módulo Inicio (Dashboard)

## Prerrequisitos

- Specs 007 (Tesorería), 008 (Ingresos) y 009 (Egresos) ya implementadas y con datos de prueba
  cargados (cuentas de tesorería, al menos una Venta con saldo pendiente vencido, una Compra con saldo
  pendiente, un Gasto y un Otro Ingreso).
- Servidor local corriendo: `php artisan serve` + `npm run dev` (Vite) + MySQL local (`contagram`).
- Usuario autenticado (login vía `/login`, credenciales en `CREDENCIALES_ACCESO.txt`).

## Validación end-to-end

1. **Login y redirect**: entrar a `/` estando autenticado → debe redirigir a `/dashboard` (ya no a
   Clientes).
2. **KPIs del mes actual**: en `/dashboard`, verificar que las 4 tarjetas (Ventas Creadas, Venta
   Promedio, Cantidad de Ventas, Resultado) muestran cifras > 0 si hay ventas del mes en curso, con
   flecha verde/roja de variación vs. mes anterior.
3. **Panel de totales**: verificar que las 4 barras (Ventas/Otros Ingresos/Compras/Gastos) suman
   proporciones consistentes con los montos mostrados al lado de cada una.
4. **Gráfico mensual**: verificar que el gráfico de 12 meses (ApexCharts, barras apiladas) muestra una
   barra por cada uno de los últimos 12 meses, incluso los que no tuvieron operaciones (en cero).
5. **Tesorería**: verificar que Total Disponible = Total Cajas + Total Bancos, y que coincide con lo
   que muestra `/tesoreria` (vista Saldos) para las mismas cuentas.
6. **Cuentas a Cobrar/Pagar**: con una Venta de prueba con `fecha_vto_cobro` 45 días atrás y saldo
   pendiente, verificar que su monto aparece en el bucket "31 a 60" del bloque "Ventas a Cobrar", y que
   el total del bloque coincide con la suma de `Venta::aCobrar()` de todas las ventas con saldo
   pendiente.
7. **Selector de período**: cambiar a "Año Actual" y confirmar que KPIs/totales/gráfico mensual/donas/
   rankings se recalculan (request AJAX a los endpoints de `contracts/dashboard-rutas.md`), sin recargar
   la página; confirmar que Tesorería y Cuentas a Cobrar/Pagar **no** cambian con el período.
8. **Donas por categoría**: con ventas en al menos 2 categorías, verificar que la dona de Ventas suma
   100% entre las porciones mostradas.
9. **Rankings**: verificar que el Ranking de Clientes lista al cliente con más monto vendido primero, y
   el Ranking de Productos al producto con más cantidad vendida primero.
10. **Estado vacío**: en una base de datos recién migrada (sin seeders de negocio), visitar
    `/dashboard` y verificar que no hay errores 500 ni pantallas en blanco — todo se muestra en cero.

## Comandos útiles

```bash
php artisan test --filter=Dashboard   # corre los 3 tests Feature de este módulo
php artisan route:list --name=dashboard
```
