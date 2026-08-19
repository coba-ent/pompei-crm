# Quickstart: validar el buscador de productos con foco persistente

## Prerrequisitos

- Base local con catálogo de productos cargado (la base de trabajo actual ya tiene el histórico real).
- Assets compilados: `npm run build` (o `npm run dev` mientras se itera).
- Servidor local: `php artisan serve --port=8720` (usar un puerto propio; el 8000/8765 puede estar
  sirviendo otro proyecto — ya pasó).
- Sesión iniciada con un usuario con permisos de Ventas, Compras y Presupuestos (ver
  `CREDENCIALES_ACCESO.txt`).

## Escenario 1 — Carga en lote sin perder el foco (US1, el pedido del cliente)

1. Ir a **Ingresos → Ventas → Nueva Venta**.
2. Hacer clic **una sola vez** en "Seleccionar o Crear Producto/Servicio" y escribir un término.
3. Elegir un resultado (clic o Enter sobre una opción resaltada).
4. **Sin tocar el mouse**, escribir otro término. Repetir hasta cargar 3 productos.

**Esperado**:
- Cada producto se agregó como línea del detalle (arriba de todo, como hoy).
- Después de cada elección: el panel se cerró, el buscador quedó vacío, el cursor sigue en el
  buscador (se puede escribir directo).
- En ningún momento quedó un panel desplegado tapando el detalle.

## Escenario 2 — Paridad de búsqueda y de línea agregada (US2, no-regresión)

Es la verificación que más importa: que **filtre y cargue igual que antes**.

1. Antes de tocar nada, en la versión actual (o en producción), buscar 5 términos representativos y
   anotar los resultados: un nombre exacto, un fragmento, un código de producto, un término de dos
   palabras, y un término sin coincidencias.
2. Repetir exactamente los mismos 5 términos con el buscador nuevo.
3. Para un producto concreto, compararlo elegido en ambas versiones: mirar la línea que quedó en el
   detalle (descripción, cantidad, precio unitario, IVA).

**Esperado**:
- Mismos productos, en el mismo orden, para los 5 términos (0 diferencias).
- Cada fila muestra el mismo texto que antes: `(id) nombre (codigo)`.
- La línea agregada tiene exactamente los mismos valores que antes.

Repetir el paso 3 en las 3 pantallas, porque la línea difiere entre ellas por diseño:

| Pantalla | Precio unitario esperado | IVA esperado |
|---|---|---|
| Venta | precio de la lista de precios seleccionada | `iva_venta_pct` del producto (o 21 si está vacío) |
| Presupuesto | precio de la lista de precios seleccionada | `iva_venta_pct` del producto (o 21 si está vacío) |
| Compra, comprobante **tipo A** | costo de compra | `iva_compra_pct` del producto |
| Compra, comprobante **distinto de A** | costo de compra | vacío ("Elegir") |

## Escenario 3 — Teclado (US3)

Sin usar el mouse en ningún momento:

1. Con el foco en el buscador, escribir un término.
2. Presionar `↓` varias veces y `↑` una vez: se resalta la opción correspondiente y no "da la vuelta"
   al llegar a los extremos.
3. Presionar `Enter`: se agrega la opción resaltada.
4. Escribir otro término y presionar `Escape`: el panel se cierra, **el texto tipeado sigue ahí** y el
   foco también.
5. Con el panel abierto y **nada resaltado**, presionar `Enter`: no se agrega nada.

## Escenario 4 — Estados no-felices (FR-009/010/011, SC-007)

1. **Buscando**: escribir un término y mirar el panel en el instante previo a la respuesta — debe
   decir que está buscando.
2. **Sin coincidencias**: escribir algo que no exista (`zzzzzz`) — el panel se muestra con el mensaje
   de que no hay coincidencias (no se cierra ni queda vacío).
3. **Error**: en DevTools → Network, activar "Offline" y escribir un término — aparece el mensaje de
   error, el buscador sigue usable y **el detalle ya cargado no se pierde**. Volver a "Online" y
   reintentar: funciona.

## Escenario 5 — Nada más cambió (FR-013, SC-006)

En las mismas 3 pantallas, verificar que siguen funcionando **igual que siempre**:

- El selector de **Cliente** (Venta/Presupuesto) y de **Proveedor** (Compra): siguen siendo el
  desplegable de siempre, con su opción "Crear Cliente" y su lápiz de editar.
- El menú **▾ de cada fila del detalle** (Ver / Editar producto): abre los modales como antes.
- Categoría, Vendedor, Lista de Precios, Etiquetas: sin cambios.
- Guardar la Venta/Compra/Presupuesto: se guarda con las líneas correctas.

## Validación automatizada

```bash
node --test tests/js/buscador-catalogo.test.mjs
```

Cubre la lógica pura del widget: agrupamiento por debounce, descarte de respuestas fuera de orden y
movimiento del índice resaltado con tope en los extremos. La interacción con el DOM se valida a mano
con los escenarios de arriba.
