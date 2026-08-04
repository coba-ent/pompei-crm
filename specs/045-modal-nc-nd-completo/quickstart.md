# Quickstart: validar el modal NC/ND completo

## Prerrequisitos

- Migración corrida: `php artisan migrate` (agrega `mes_imputacion` con backfill).
- Al menos una Compra y una Venta existentes con ítems de producto, y al menos un Depósito
  configurado (Configuración > Depósitos).

## Escenario 1 — NC/ND sin afectar stock (User Story 1)

1. Ir al detalle de una Compra existente → "Notas de Crédito y Débito" → "+ Agregar".
2. Confirmar que "Documento que Ajusta" muestra el comprobante de esa Compra.
3. Elegir Tipo = "Nota de Crédito", dejar "¿Queres que afecte Stock?" en "No".
4. Confirmar que "Mes de Imputación" viene precargado con el mes/año de hoy (o de la fecha de
   emisión elegida).
5. Completar Fecha, Monto, Descripción → Guardar.
6. **Esperado**: la nota aparece en la tabla del detalle con su Mes de Imputación visible; el
   stock de ningún producto cambia (verificar en Informe de Stock).

## Escenario 2 — NC/ND que afecta stock (User Story 2)

1. Desde el detalle de una Venta con al menos un ítem de producto → "Crear NC/ND".
2. Tipo = "Nota de Débito", cambiar "¿Queres que afecte Stock?" a "Sí".
3. Confirmar que aparece "Agregar Productos de la Venta" con los ítems del comprobante original y
   su cantidad facturada, y un selector de Depósito.
4. Tildar un producto, cargar una cantidad **mayor** a la facturada → confirmar que el sistema lo
   rechaza y muestra el máximo disponible.
5. Cargar una cantidad válida, elegir Depósito, completar Fecha/Monto/Mes de Imputación → Guardar.
6. **Esperado**: la nota se crea; en Informe de Stock, el producto/depósito elegido refleja el
   movimiento (ND de venta descuenta stock, ver signo ya implementado en
   `NotaCreditoDebitoController`).
7. Repetir el flujo para una segunda nota sobre el mismo comprobante y mismo producto: el máximo
   disponible ofrecido debe ser menor (descontando lo ya ajustado por la nota del paso anterior).

## Escenario 3 — Paridad Compras/Ventas (User Story 3)

1. Abrir el modal desde una Compra y, en otra pestaña, desde una Venta.
2. **Esperado**: mismo orden de campos, mismos controles (Tipo, Documento, ¿Afecta Stock?,
   [Productos + Depósito], Mes de Imputación, Fecha, Monto, Descripción), sólo cambia la
   terminología Proveedor/Cliente y Compra/Venta donde corresponde.

## Verificación automatizada

`php artisan test --filter=NotaCreditoDebito` — cubre: tope de cantidad por producto, depósito
obligatorio cuando afecta_stock=true, mes_imputacion obligatorio, signo correcto del movimiento de
stock en los 4 casos (NC/ND × Venta/Compra).
