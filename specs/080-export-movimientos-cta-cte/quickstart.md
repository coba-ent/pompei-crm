# Quickstart: Exportar Movimientos de Cuenta Corriente

## Prerequisitos

- Base local con datos de Ventas/Cobros/Compras/Pagos/NC-ND cargados (ej. la copia de referencia ya
  usada en el resto de la suite).
- Rutas de esta spec implementadas (`/speckit-tasks` + `/speckit-implement`).

## Validar PDF (US1)

1. `php artisan serve` (o el vhost local).
2. Ir a Informes → Cuenta Corriente Clientes → tab Movimientos.
3. Filtrar por un rango de fechas con al menos una Venta y su Cobro.
4. Clickear "Exportar a PDF" → confirmar: título "Informe - Movimientos de Clientes", apaisado, 11
   columnas, header teal repetido por página, pie "Pág. X / Y".
5. Repetir en Cuenta Corriente Proveedores → tab Movimientos.

## Validar Excel (US2)

1. Mismo filtro que arriba, clickear "Exportar".
2. Abrir el `.xlsx` y verificar:
   - Una sola hoja "Movimientos de Clientes" (34 columnas) / "Movimientos de Proveedores" (33).
   - Una fila de Venta/Compra con ítems a distintas alícuotas (ej. 21% y 10,5%) tiene los importes
     repartidos en las columnas de alícuota correspondientes.
   - La fila de Cobro/Pago de esa misma operación tiene las columnas fiscales en blanco (no 0) y
     `Cobrado`/`Pagado` con el importe.
   - Los totales de neto/IVA del rango coinciden con los que muestra el Libro IVA del Contador para el
     mismo período (spec 077) — misma fuente de cálculo.
3. Repetir con un rango que incluya una NC/ND y confirmar que aparece como fila propia con signo
   correcto en sus columnas fiscales.

## Tests automatizados

```
php artisan test --filter=MovimientosClientesExportTest
php artisan test --filter=MovimientosProveedoresExportTest
```
