# Research: Ver/Editar producto desde el detalle de Venta, Presupuesto y Compra

## Contexto relevado del código actual

- `resources/js/productos.js` es una única IIFE que arranca con `const $tabla = $('#tabla-productos'); if (!$tabla.length) { return; }` (línea ~48) — **todo** el módulo, incluidos los handlers `.js-producto-ver` y `.js-producto-editar`, sólo corre en la página de Productos.
- El modal "Ver" (`#modal-producto-ver`, partial `resources/views/productos/_modal_ver.blade.php`) es de sólo lectura; se llena vía `GET productos.show` y no tiene dependencias de guardado.
- El modal "Editar" (`resources/views/productos/_modal_form.blade.php`) es el mismo formulario de alta, reutilizado para edición; su lógica de precarga (`$(document).on('click', '.js-producto-editar', ...)`, línea ~802) recorre todos los campos del formulario y usa: `resetForm()`, `refreshSelect2()`, `agregarVariante()`, `renderListasPrecio()`, `renderTiposProducto()`, `mostrarPreviewImagen()`, `toggleStockSection()`, `abrirModal()` — funciones internas privadas del cierre de `productos.js`.
- El guardado del formulario (submit) usa `rutas.store` / `rutas.update` (no vistos en el extracto leído, pero siguen el patrón estándar AJAX + toast del proyecto) y al finalizar recarga el DataTable (`tabla.ajax.reload(...)`) — ese último paso es específico de la página de Productos y **no aplica** cuando el modal se abre desde Ventas/Presupuestos/Compras.
- `cfg` (`window.ProductosConfig`) trae `rutas`, `tiposProducto`, `proveedores`, `listasPrecio` — catálogos usados para poblar selects del formulario y para las etiquetas del modal "Ver" (`nombrePorId`, `etiquetaIva`).
- Rutas de backend: `Route::resource('productos', ProductoController::class)` (routes/web.php:120) ya expone `GET productos/{producto}` (show, usado por ambos modales) y `PUT/PATCH productos/{producto}` (update) — no se necesitan endpoints nuevos.
- Las 3 páginas consumidoras (`ventas.js`, `presupuestos.js`, `compras.js`) ya tienen su propio array en memoria `items` con `{producto_id, descripcion, cantidad, precio_unitario, descuento_pct, iva_pct}` y una función `renderItems()` que reconstruye la tabla desde ese array en cada cambio (visto en `ventas.js:608-633`). Cualquier actualización de nombre/precio de fila se resuelve mutando `items[idx]` y llamando `renderItems()`.

## Decisión: extracción a módulo compartido

**Decision**: Crear `resources/js/producto-modales.js`, un módulo IIFE independiente que:
1. Se auto-inicializa en `$(document).ready` si detecta `#modal-producto-ver` y/o `#modal-producto` (formulario) en el DOM — no depende de `#tabla-productos`.
2. Expone `window.ProductoModales = { abrirVer(id), abrirEditar(id, opciones) }` para que otras páginas disparen la apertura programáticamente (no sólo por delegación de clase CSS), y además sigue escuchando `.js-producto-ver` / `.js-producto-editar` por delegación (compatibilidad con Productos).
3. Al guardar exitosamente el formulario de edición, dispara un evento DOM custom `producto:actualizado` en `document` con `detail: { producto }` (el JSON completo devuelto por el backend), en vez de asumir que existe un DataTable que recargar. `productos.js` sigue escuchando ese evento para refrescar su tabla (`tabla.ajax.reload`), y `ventas.js`/`presupuestos.js`/`compras.js` lo escuchan para actualizar la fila.
4. `cfg` para el módulo compartido se sigue leyendo de `window.ProductosConfig` (ya se carga globalmente vía `config/dz.php` pagelevel — hay que extender el pagelevel para que los catálogos necesarios (`tiposProducto`, `proveedores`, `listasPrecio`, `rutas.show`, `rutas.update`) también estén disponibles en las páginas de Ventas/Presupuestos/Compras).

**Rationale**: Es el único enfoque que cumple la restricción explícita del usuario ("hay que extraerlos/hacerlos reutilizables... sin duplicar lógica") sin reescribir el formulario de producto (que es grande: variantes, listas de precio, imagen, tipos). Mantener un solo archivo dueño de la lógica del modal evita que un fix futuro en Productos quede desincronizado en Ventas/Presupuestos/Compras.

**Alternatives considered**:
- *Duplicar los handlers en cada uno de los 4 archivos JS*: descartado explícitamente por el usuario y por el principio de mantenibilidad (4 copias divergentes de una lógica ya compleja).
- *Iframe o `window.open` hacia `/productos?ver=ID`*: descartado — viola la regla de diseño obligatoria del proyecto (todo alta/edición/vista es modal Bootstrap+AJAX, nunca navegación/recarga).
- *Cargar dinámicamente `productos.js` completo en las otras páginas*: descartado — ese archivo asume la existencia de `#tabla-productos`, `#btn-ver-totales`, listas de precio globales, acciones masivas, etc.; cargarlo entero en Ventas sería más frágil que extraer sólo lo necesario.

## Decisión: refresco de la fila del detalle

**Decision**: Cada página consumidora (`ventas.js`, `presupuestos.js`, `compras.js`) escucha `document` en `producto:actualizado` y, para cada `item` en su array `items` cuyo `producto_id` coincida con `detail.producto.id`:
- actualiza `item.descripcion` con el nuevo nombre;
- actualiza `item.precio_unitario` con el nuevo precio de venta **sólo si** `item.precio_unitario` es igual al precio de catálogo que tenía el producto al momento de agregarse a la fila (se guarda ese valor de referencia como `item._precioCatalogoOriginal` al agregar la fila, replicando el patrón ya usado para recotizar por lista de precios en `ventas.js:589-606`);
- llama a `renderItems()`.

**Rationale**: Reutiliza el mecanismo ya existente de recotización por cambio de Lista de Precios (mismo patrón de "no pisar un precio tipeado a mano"), cumpliendo FR-006 sin lógica nueva de detección de edición manual.

**Alternatives considered**: comparar por "flag booleano de editado manualmente" — más invasivo (requiere tocar el `oninput` de cada input de precio en las 3 páginas); se prefiere el enfoque de comparación de valores, ya validado en el código existente.

## Decisión: alcance en Compras

**Decision**: Se implementa igual que en Ventas/Presupuestos aunque el informe de relevamiento de Compras no documenta explícitamente el desplegable por fila (a diferencia de Presupuestos, que sí lo documenta literalmente).

**Rationale**: Instrucción explícita del usuario + mismo patrón de tabla de Conceptos en las 3 pantallas (confirmado leyendo `docs/informe_contagram_egresos.md:116`, que describe la misma tabla de Conceptos que Ventas/Presupuestos sin mencionar una estructura distinta).
