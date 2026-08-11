# Quickstart: validar "Vencido" en Compras + ítems con cantidad negativa

## Prerrequisitos

- Servidor local levantado, con al menos una Compra con `fecha_vto_pago` pasada y sin pagos, y un
  producto que controle stock (para probar el ítem negativo).

## Escenario 1 — Badge "Vencido" en el listado

1. Ubicar (o crear, cambiando `fecha_vto_pago` a una fecha pasada) una Compra sin pagos con vto.
   vencido.
2. Ir a `/compras`.
3. **Esperado**: el badge de esa fila muestra "Vencido" en rojo (no "A Pagar").
4. Registrar un pago que cubra el 100% del saldo.
5. **Esperado**: el badge pasa a "Pagado" aunque `fecha_vto_pago` siga en el pasado (FR-004).

## Escenario 2 — Filtro por "Vencido"

1. En `/compras`, abrir el panel de Filtros → "Estado del Pago".
2. **Esperado**: aparece la opción "Vencido" (hoy sólo A Pagar/Parcial/Pagado).
3. Seleccionarla y aplicar.
4. **Esperado**: la tabla muestra únicamente compras vencidas (Escenario 1, paso 3) — el total
   filtrado debe coincidir con lo que ya muestra el KPI "Vencido" de la barra superior (SC-002).

## Escenario 3 — Ítem de compra con cantidad negativa (bonificación)

1. Anotar el stock actual de un producto en un depósito.
2. Crear una Compra con dos ítems del mismo producto: uno con cantidad `3` (precio $100) y otro con
   cantidad `-1` (mismo precio $100).
3. **Esperado**: el subtotal del segundo renglón es `-$100` (no rechaza la carga), y el total de la
   compra descuenta ese importe.
4. Guardar.
5. **Esperado**: el stock del depósito sube exactamente en `+2` respecto al valor anotado (3 − 1), no
   en `+4` (que sería el bug de signo perdido, ver research.md §5).

## Escenario 4 — Precio negativo sigue bloqueado

1. En el mismo formulario, cargar un ítem con precio unitario `-50`.
2. **Esperado**: 422 / error de validación — sólo la cantidad admite negativo, el precio no (FR-006).

## Validación automatizada

```bash
php artisan test --filter=CompraVencido
php artisan test --filter=CompraItemNegativo
```

Debe cubrir, como mínimo, los 4 escenarios de arriba.
