# Quickstart: validar los filtros de Compras

## Prerrequisitos

- Migración de `creado_por_id` corrida: `php artisan migrate`
- Al menos 2 proveedores con compras cargadas, algunas con Etiqueta, Depósito, pagos con distintos medios, y al menos una con comprobante fiscal emitido (Facturado).

## Escenarios a validar manualmente en el navegador

1. **Proveedor múltiple**: abrir `/compras`, panel Filtros, elegir 2 proveedores en el campo Proveedor, Buscar → el listado debe traer compras de ambos.
2. **Cada filtro nuevo por separado**: probar Id, Categoría de Compra, Estado del Pago, Tipo y N° de Factura, Etiqueta, Facturado, Medio de pago, Usuario, Nota Interna, Depósito, Desde/Hasta Servicio uno por uno, verificando que el resultado se acota correctamente.
3. **Combinación AND**: aplicar 2+ filtros a la vez (ej. Categoría + Depósito) y verificar que el resultado es la intersección, no la unión.
4. **Rango de Vencimiento**: elegir un rango en el nuevo control "Vencimiento" (separado de "Emisión") y verificar que filtra por `fecha_vto_pago`; combinarlo con un rango de Emisión y verificar AND.
5. **Compras sin fecha cargada**: verificar que una compra sin `fecha_vto_pago` no aparece cuando el rango de Vencimiento está activo, y que sí aparece cuando no lo está.
6. **Selector de columnas**: abrir el selector de columnas, tildar una columna adicional (ej. CUIT) y verificar que aparece en la tabla sin recargar.
7. **Botón Nueva Compra**: verificar que sigue funcionando sin cambios.
8. **Usuario en compra nueva**: crear una compra nueva, luego filtrar por Usuario = el usuario logueado, y verificar que aparece; verificar que compras cargadas antes de esta feature no aparecen al filtrar por ningún Usuario (quedaron con `creado_por_id = NULL`).

## Validación automatizada

- `tests/Feature/Compras/FiltrosCompraTest.php`: un caso por filtro (assert de que el resultado incluye/excluye lo esperado) + un caso de combinación AND + casos de exclusión por fecha nula (servicio y vencimiento).
- Correr: `php artisan test --filter=FiltrosCompraTest`
