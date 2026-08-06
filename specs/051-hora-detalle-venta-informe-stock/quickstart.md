# Quickstart: Validar Hora en Movimientos de Stock y Detalle de Venta

## Prerrequisitos

- App local corriendo (`php artisan serve` o XAMPP) contra la DB `contagram`.
- Migraciones aplicadas: `php artisan migrate`.
- Un usuario con acceso a Ventas y a Informes → Stock (`CREDENCIALES_ACCESO.txt`).
- Al menos un producto con `controla_stock = true` y stock disponible en el depósito por defecto.

## Escenario 1 — Orden por fecha y hora (User Story 1)

1. Crear una venta manual que descuente stock de un producto (hora A).
2. Esperar unos minutos y hacer un ajuste manual de stock sobre otro producto (hora B, B > A).
3. Ir a Informes → Stock, sin aplicar filtros.
4. **Esperado**: el movimiento de la venta (hora A) aparece antes que el del ajuste (hora B); la
   columna de fecha muestra el mismo día para ambos (no alcanza para diferenciarlos sin hora, la
   verificación real es el orden de filas, no el texto de fecha visible).
5. Verificar en base (`SELECT id, fecha FROM movimientos_stock ORDER BY id DESC LIMIT 2;`) que
   `fecha` incluye hora distinta de `00:00:00` en ambos registros nuevos.

## Escenario 2 — Detalle de venta en el Informe de Stock (User Story 2)

1. Crear una venta manual con un cliente asignado, tipo de comprobante y número (ej. `B
   0001-00001234`), que descuente stock de un producto.
2. Ir a Informes → Stock y ubicar la fila de esa salida (filtrar por el producto ayuda).
3. **Esperado**: la columna "Detalle" muestra `"B 0001-00001234 - {nombre del cliente}"`.
4. Eliminar esa venta (para disparar el reintegro de stock).
5. **Esperado**: aparece una nueva fila de entrada con el mismo detalle de venta.
6. Repetir el paso 1 con una venta **sin** cliente asignado.
7. **Esperado**: la columna "Detalle" muestra sólo `"B 0001-00001235"` (sin el segmento de
   cliente), sin texto roto ni "null".

## Escenario 3 — Regresión en otros orígenes

1. Registrar un ajuste manual de stock con una descripción (ej. "Rotura de mercadería").
2. Registrar una compra que dé entrada de stock.
3. Ir a Informes → Stock.
4. **Esperado**: ambas filas muestran la columna "Detalle" exactamente igual que antes de este
   cambio (el texto libre de la descripción del ajuste; el comportamiento actual para compras).

## Validación automatizada

Correr la suite de tests relevante:

```bash
php artisan test --filter=InformeStockTest
php artisan test --filter=MovimientoStockObserverTest
php artisan test --filter=VentaStockTest
```

Ver [contracts/informe-stock-data.md](contracts/informe-stock-data.md) para el shape exacto de la
respuesta JSON a validar en los tests de `InformeStockController::data()`.
