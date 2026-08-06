# Quickstart: Ver/Editar producto desde el detalle de Venta, Presupuesto y Compra

## Prerrequisitos

- Servidor local corriendo (`php artisan serve` o XAMPP) con la base `contagram`.
- Assets compilados (`npm run dev` o `npm run build`) después de aplicar los cambios de esta
  feature, ya que se agrega `resources/js/producto-modales.js` y se tocan `productos.js`,
  `ventas.js`, `presupuestos.js`, `compras.js`.
- Al menos un producto de catálogo con precio de venta cargado.

## Escenario 1 — Ver producto desde el detalle de una Venta (US1)

1. Ir a Ingresos → Ventas → Nueva Venta.
2. En "Seleccionar o Crear Producto/Servicio", elegir un producto → se agrega una fila a la tabla
   de Conceptos.
3. En esa fila, abrir el desplegable ▾ a la izquierda del nombre del producto.
4. Click en "Ver".
5. **Resultado esperado**: se abre el modal de sólo lectura con los mismos datos que muestra "Ver"
   en el listado de Productos (nombre, código, estado, tipo, proveedor, stock, costo, precio de
   venta, IVA, listas de precio, descripción, imagen). Cerrar el modal no altera el formulario de
   Venta (cliente, otros ítems, notas siguen igual).
6. Repetir el mismo paso en Presupuestos → Nuevo Presupuesto y en Compras → Nueva Compra.

## Escenario 2 — Editar producto desde el detalle y ver el refresco de la fila (US2)

1. En la misma pantalla de Nueva Venta, con un producto agregado al detalle, abrir el desplegable
   ▾ de la fila → "Editar".
2. **Resultado esperado**: se abre el modal de alta/edición de Productos, precargado con los datos
   de ese producto (igual que "Editar" desde el listado de Productos).
3. Cambiar el "Precio de Venta" a un valor distinto y Guardar.
4. **Resultado esperado**: el modal se cierra, aparece un toast de éxito, y la fila del detalle de
   la Venta se actualiza sola (sin recargar la página) mostrando el nuevo precio unitario y
   recalculando subtotal/total de esa fila y los totales generales.
5. Editar la cantidad/precio de otra fila del detalle manualmente (tipeando un precio distinto al
   de catálogo), luego editar ese mismo producto desde su desplegable ▾ y cambiarle el precio de
   venta en el modal. **Resultado esperado**: la fila del detalle NO pisa el precio tipeado a mano
   (FR-006) — sólo se actualiza el nombre si cambió.
6. Verificar en el listado de Productos que el precio quedó guardado — confirma que el guardado
   desde el detalle usa el mismo endpoint/lógica que editar desde Productos (FR-004).

## Escenario 3 — Fila sin producto asociado (Edge case)

1. Si el formulario admite una línea manual/libre sin producto de catálogo, verificar que esa fila
   **no** muestra el desplegable ▾ Ver/Editar (FR-008).

## Escenario 4 — Error al cargar el producto

1. Simular una falla (ej. desconectar red o interceptar la request de `GET productos/{id}`) al
   hacer click en "Ver" o "Editar" desde una fila del detalle.
2. **Resultado esperado**: toast de error, el modal no se abre, el formulario de la
   Venta/Presupuesto/Compra permanece intacto (FR-009).

## Verificación de consistencia entre módulos (SC-003)

Repetir los Escenarios 1 y 2 en Ventas, Presupuestos y Compras y confirmar que el desplegable, los
modales y el comportamiento de refresco de fila son indistinguibles entre las tres pantallas.
