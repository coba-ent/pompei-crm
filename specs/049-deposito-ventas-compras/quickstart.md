# Quickstart: Selector de Depósito en Ventas y Compras

## Prerrequisitos

- Al menos 2 depósitos activos (Configuración & Ajustes → Depósitos → "+ Agregar Depósito").
- Un producto con `controla_stock` activo y stock cargado en ambos depósitos (Base de Datos →
  Productos → Ajuste de Stock → Aumento, uno por depósito).
- Migraciones corridas (`php artisan migrate`).

## Escenario 1 — Elegir depósito en una Venta nueva (US1, AC1)

1. Ir a Ingresos → Ventas → "Nueva Venta".
2. Verificar que el formulario muestra un campo "Depósito" (Select2) con los depósitos activos.
3. Completar Cliente + al menos un ítem con el producto de prueba, elegir el Depósito B (no el que
   sería el "por defecto"), guardar.
4. En Base de Datos → Productos → Historial de Movimientos, filtrar por el producto: debe aparecer un
   movimiento de salida en el Depósito B, no en el A.
5. En el listado de Ventas, filtrar por Depósito = B: la Venta recién creada debe aparecer.

## Escenario 2 — Elegir depósito en una Compra nueva (US1, AC2)

1. Ir a Egresos → Compras → "Nueva Compra".
2. Elegir Proveedor + ítem con el producto de prueba, Depósito B, guardar.
3. Verificar en Historial de Movimientos que el stock del Depósito B aumentó.

## Escenario 3 — Editar el depósito de una Venta existente (US1, AC3)

1. Abrir una Venta ya creada (Escenario 1), editarla, cambiar Depósito de B a A, guardar.
2. Verificar en Historial de Movimientos: el stock de B se reintegra (vuelve a subir) y el de A baja
   en la cantidad de la Venta.

## Escenario 4 — Eliminar una Venta/Compra respeta el depósito original (US1, AC4)

1. Cambiar el Depósito por defecto en Configuración & Ajustes (Escenario 5) a un tercer depósito C.
2. Eliminar la Venta del Escenario 3 (que quedó con Depósito A).
3. Verificar que el stock reintegrado impacta en A, no en C (el default vigente al momento de
   eliminar no debe afectar el reintegro).

## Escenario 5 — Configurar depósito por defecto de Ventas y de Compras (US2, AC1-AC3)

1. Ir a Configuración & Ajustes → tab "Ventas".
2. En la sección "Ventas", elegir un Depósito por defecto, Guardar.
3. Abrir "Nueva Venta": el campo Depósito debe abrir preseleccionado con ese valor.
4. En la misma pantalla, sección "Compras", elegir un Depósito por defecto distinto, Guardar.
5. Abrir "Nueva Compra": el campo Depósito debe abrir preseleccionado con ese otro valor.
6. Sin configurar ningún default (fila `configuracion_ventas` vacía en esos campos), "Nueva Venta" y
   "Nueva Compra" deben preseleccionar el mismo depósito que devuelve `Deposito::porDefecto()`.

## Escenario 6 — Depósito por defecto inactivado (US2, AC4)

1. Con un Depósito D configurado como default de Ventas, inactivarlo desde Configuración & Ajustes →
   Depósitos.
2. Volver al tab "Ventas": el campo debe reflejar que no hay default activo (vacío o mensaje), y
   "Nueva Venta" debe volver a preseleccionar `Deposito::porDefecto()`.

## Escenario 7 — N° de comprobante sugerido y editable en Compra (US3, AC1-AC2)

1. Ir a Egresos → Compras → "Nueva Compra".
2. Verificar que el campo N° de comprobante viene precargado con un valor tipo `0001-0000000X`
   (el mismo correlativo que hoy se autogenera).
3. Completar el resto del formulario sin tocar ese campo, guardar.
4. Verificar que la Compra quedó con ese mismo número en `compras.nro_comprobante` — comportamiento
   idéntico al actual para quien no necesita cargar un número real.

## Escenario 8 — Cargar el número real del Proveedor (US3, AC3)

1. Abrir "Nueva Compra", borrar el valor sugerido del campo N° de comprobante y escribir el número
   real de la factura del proveedor (ej. `0003-00012345`).
2. Guardar.
3. Verificar que `compras.nro_comprobante` persiste exactamente `0003-00012345`, no el correlativo
   sugerido.

## Escenario 9 — No se puede guardar sin N° de comprobante (US3, AC4)

1. Abrir "Nueva Compra", borrar completamente el campo N° de comprobante (dejarlo vacío).
2. Intentar guardar.
3. Verificar que el formulario bloquea el guardado con un mensaje de validación sobre ese campo.

## Escenario 10 — Editar el N° de comprobante de una Compra existente (US3, AC5)

1. Abrir para editar la Compra del Escenario 8.
2. Verificar que el campo N° de comprobante muestra `0003-00012345` (el valor real ya guardado).
3. Cambiarlo por otro valor y guardar; verificar que persiste el nuevo valor.

## Validación automatizada

- `php artisan test --filter=VentaDepositoTest`
- `php artisan test --filter=CompraDepositoTest`
- `php artisan test --filter=CompraNroComprobanteTest`
- `php artisan test --filter=ConfiguracionVentasDepositoTest` (o el test existente de
  `ConfiguracionVentas` extendido)
