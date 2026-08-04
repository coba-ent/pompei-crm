# Quickstart: Descuento general aplicado proporcionalmente a neto e IVA

Guía de validación manual/end-to-end. Ver [data-model.md](./data-model.md) para la fórmula exacta y
[research.md](./research.md) para el detalle de por qué cambia.

## Prerrequisitos

- Ambiente local o de homologación con Facturación Electrónica activa (Configuración & Ajustes →
  Funciones) para el Escenario 2.
- Un Cliente con Condición de IVA cargada (spec 042, FR-006).

## Escenario 1 — Venta con descuento general y una sola alícuota (caso real)

1. Crear una Venta con 3 ítems al 21% de IVA:
   - `157879.22` / `49859.48` / `91308.22` de neto (precio × cantidad, sin descuento de línea)
   - `descuento_general_pct = 15`
2. Guardar. Verificar en el detalle de la Venta:
   - `subtotal_sin_descuento` ≈ `299046.92` (sin cambios respecto a hoy)
   - `subtotal_con_descuento` ≈ `254189.88`–`254189.89` (dentro de $0.01 del valor actual — el neto
     casi no cambia, sólo puede variar por redondeo por ítem)
   - `total` ≈ `307569.76` — **menor** al actual (`316989.74`), porque ahora el IVA también está
     descontado
3. Confirmar que `total - subtotal_con_descuento` (el IVA implícito) es aproximadamente el 21% de
   `subtotal_con_descuento` (antes era ~21% del neto SIN descontar).

## Escenario 2 — Esa misma Venta ya no es rechazada al enviar a ARCA

1. Con la Venta del Escenario 1, usar "Enviar a ARCA" (menú Acciones del listado de Ventas — spec
   040/042).
2. Verificar que la respuesta ya no es el rechazo "El IVA calculado no coincide con la suma por
   alícuota" (spec 042, `ValidadorDatosFiscales`) — debe intentar el envío real a ARCA (u obtener CAE
   si el ambiente de homologación responde OK).

## Escenario 3 — No-regresión sin descuento general

1. Crear una Venta idéntica a la del Escenario 1 pero con `descuento_general_pct = 0` (o sin
   informarlo).
2. Verificar que `subtotal_sin_descuento`, `subtotal_con_descuento` y `total` dan exactamente igual
   que antes de este fix (mismos valores que produciría el código actual, sin ninguna diferencia de
   centavos).

## Escenario 4 — Presupuesto y Venta convertida dan el mismo total

1. Crear un Presupuesto con ítems y `descuento_general_pct > 0`.
2. Anotar el `total` mostrado.
3. Convertirlo a Venta.
4. Verificar que el `total` de la Venta resultante es idéntico al del Presupuesto (mismo cálculo,
   mismo servicio `CalculoComprobante`).
