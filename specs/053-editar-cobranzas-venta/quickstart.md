# Quickstart: Editar cobranzas de una venta

## Prerrequisitos

- App corriendo local (XAMPP + `php artisan serve` o Valet/similar), DB `contagram` con al menos
  una Venta con saldo pendiente > 0 y una Cobranza ya cargada.
- Usuario logueado (ver `CREDENCIALES_ACCESO.txt`).

## Validar User Story 1 (editar monto/fecha/cuenta/nota)

1. Ir al detalle de una Venta con al menos una cobranza (`/ventas/{id}`).
2. En la tabla de cobranzas, abrir el desplegable de acciones de una fila → "Editar".
3. Verificar que el modal se abre precargado con los valores actuales del cobro.
4. Cambiar el monto a un valor menor y guardar.
5. Verificar (sin recargar la página): la fila de la tabla muestra el nuevo monto, el saldo
   pendiente de la venta se actualiza, y aparece un toast de éxito.
6. Ir a Tesorería → cuenta correspondiente → verificar que el movimiento asociado a esa cobranza
   quedó con el nuevo monto (no hay un movimiento duplicado ni uno "fantasma").

## Validar User Story 2 (desplegable de acciones)

1. En el detalle de venta, confirmar que la primera columna de la tabla de cobranzas es un botón
   desplegable (no íconos sueltos) con "Ver recibo", "Editar", "Eliminar".
2. Confirmar que "Ver recibo" y "Eliminar" siguen funcionando igual que antes del cambio.

## Validar User Story 3 (tope de sobre-cobro)

1. Tomar una venta totalmente cobrada (saldo pendiente $0).
2. Editar su única cobranza intentando subir el monto por encima del total de la venta.
3. Verificar que se rechaza con un mensaje claro y el monto original no cambia.
4. Repetir con un valor dentro del margen disponible (monto actual + saldo pendiente) y verificar
   que se acepta.

## Casos negativos a probar

- Editar un cobro ya anulado (soft-deleted) → debe rechazarse (404/422, según implementación).
- Dejar el campo monto vacío o en 0 → debe rechazarse.
- Poner una fecha inválida → debe rechazarse, mismo criterio que en el alta.

## Referencias

- Contrato del endpoint: [contracts/cobranzas-update.md](contracts/cobranzas-update.md)
- Modelo de datos: [data-model.md](data-model.md)
