# Contrato de UI / Rutas: Proveedores + Informe de Stock

Interfaz que esta feature expone al usuario. Rutas web Laravel (Blade + AJAX), en español, sobre el
layout `layouts.default`.

**Reglas de diseño obligatorias aplicadas** (ver `CLAUDE.md`):
- Los listados son **DataTable responsive con carga AJAX server-side**.
- Alta/edición/eliminación de Proveedor se hacen en **modales de Bootstrap enviados por AJAX**; la
  página **nunca** se recarga. Los endpoints responden **JSON**, no redirects.
- Toda notificación se muestra con **toasts de Toastr**.
- Selects de datos dinámicos (Proveedor, Tipo de Producto, Usuario) usan **Select2**.
- El Informe de Stock es una **pantalla propia** (ruta independiente), no un modal — principio de
  fidelidad estructural a Contagram.

## Rutas — Proveedores (prefijo `/proveedores`, espejo de Clientes)

| Método | Ruta | Nombre | Acción | Respuesta | Historia |
|---|---|---|---|---|---|
| GET | `/proveedores` | `proveedores.index` | Página del listado (shell con tabla + modales) | HTML | US1 |
| GET | `/proveedores/data` | `proveedores.data` | Datos server-side de la DataTable | JSON (DataTables) | US1 |
| GET | `/proveedores/stats` | `proveedores.stats` | Métricas para cards (total, activos, nuevos del mes) | JSON | — |
| GET | `/proveedores/export` | `proveedores.export` | Exporta el listado filtrado a CSV | descarga CSV | — |
| POST | `/proveedores` | `proveedores.store` | Crear proveedor (modal) | JSON | US1 |
| GET | `/proveedores/{proveedor}` | `proveedores.show` | Datos del proveedor para precargar el modal | JSON | US1 |
| PUT/PATCH | `/proveedores/{proveedor}` | `proveedores.update` | Actualizar proveedor (modal) | JSON | US1 |
| DELETE | `/proveedores/{proveedor}` | `proveedores.destroy` | Eliminar físicamente (sólo si no tiene productos asociados) | JSON | US1 |
| PATCH | `/proveedores/{proveedor}/estado` | `proveedores.estado` | Alternar activo/inactivo | JSON | US1 |

## Rutas — Productos (modificación sobre `002-productos`)

| Método | Ruta | Nombre | Cambio |
|---|---|---|---|
| GET/POST | `/productos`, `/productos/{producto}` | `productos.*` | Payload de store/update acepta de nuevo `proveedor_id` (nullable, exists en `proveedores`) |
| GET | `/productos/data` | `productos.data` | Vuelve a aceptar filtro `proveedor_id` y devolver columna `proveedor` |
| GET | `/productos/{producto}/movimientos` | `productos.movimientos` | **Se reemplaza**: en vez de devolver JSON de histórico para el modal, el link del menú de fila navega a `informes.stock.index` con `?producto_id={id}` (ver abajo). La ruta AJAX vieja puede quedar de baja o redirigir. |

## Rutas — Informe de Stock (prefijo `/informes/stock`, nuevo)

| Método | Ruta | Nombre | Acción | Respuesta | Historia |
|---|---|---|---|---|---|
| GET | `/informes/stock` | `informes.stock.index` | Página del informe (shell con filtros, rango de fechas, KPIs y tabla). Acepta querystring `producto_id` para pre-cargar el filtro "Productos" | HTML | US3 |
| GET | `/informes/stock/data` | `informes.stock.data` | Datos server-side de la tabla de movimientos (con columna `stock_saldo`) | JSON (DataTables) | US3 |
| GET | `/informes/stock/stats` | `informes.stock.stats` | 3 KPIs (Unidades en Stock, Costo Total, Valor Venta Total) recalculados según filtros vigentes | JSON | US3 |

## Contratos JSON

### GET `proveedores.data` (DataTables server-side)

- Request: parámetros estándar de DataTables + filtros: `estado` (`activos`/`inactivos`/`todos`).
- Response: `{ draw, recordsTotal, recordsFiltered, data: [ { id, nombre, nombre_pila, apellido,
  email, telefono, telefono_celular, domicilio, localidad, provincia, doc_dni, doc_cuit,
  condicion_iva, nota, pagina_web, activo, acciones (HTML) }, ... ] }` — mismas 15 columnas
  relevadas en Contagram real (sin `usuario_ml`, exclusivo de Cliente).
- Búsqueda global sobre cualquier dato del proveedor (mismo patrón que `clientes.data`).

### POST `proveedores.store` / PATCH `proveedores.update`

Campos aceptados: los mismos que Cliente (ver `contracts` de `001-clientes` si existiera; en su
defecto, espejo de `ReglasCliente`) **salvo**: sin `apodo_ml`; `categoria_id` referencia categorías
`tipo=compra` (etiqueta "Categoría Compras"); sin `lista_precio_id`; `nota_cliente` se reemplaza por
`nota_interna`.

Validaciones (`ReglasProveedor`, clon de `ReglasCliente`):
- `nombre`: required, string, max 255.
- `cuit`: nullable; si `tipo_documento` es CUIT/CUIL, regla `CuitValido`; unique en `proveedores`
  ignorando el propio registro.
- `categoria_id`: nullable, exists en `categorias` (implícitamente `tipo=compra`, filtrado en la UI).
- `contactos.*`: mismo shape que `cliente_contactos`.
- `campos_personalizados.*`: mismo shape que en Cliente.

Respuestas: mismo contrato que `clientes.store`/`clientes.update` (200 `{ok, mensaje, proveedor}` /
422 `{ok:false, errors}`).

### DELETE `proveedores.destroy`

- Si `proveedor->tieneOperaciones()` (existe algún `Producto` con `proveedor_id` = este proveedor)
  → HTTP 409 `{ ok: false, mensaje: "Sólo puede inactivarse: el proveedor tiene productos
  asociados." }`. FR-006.
- Si no → HTTP 200 `{ ok: true, mensaje: "Proveedor eliminado." }`.

### GET `informes.stock.data` (DataTables server-side)

- Request: parámetros estándar de DataTables + filtros: `usuario_id`, `operacion` (`ajuste` |
  `transferencia`), `proveedor_id`, `tipo_producto_id`, `producto_id`, `estado`
  (`activos`/`inactivos`/`todos`), `fecha_desde`, `fecha_hasta`.
- Response: `{ draw, recordsTotal, recordsFiltered, data: [ { id, fecha, tipo, descripcion,
  producto, deposito, cantidad, stock_saldo, usuario }, ... ] }`.
- **`stock_saldo`** se calcula sobre el histórico completo del producto+variante+depósito (ver
  data-model.md), no sobre el subconjunto ya filtrado por fecha/tipo/etc. — los filtros sólo deciden
  qué filas se **muestran**, nunca alteran el valor acumulado de una fila mostrada.
- Si la request llega con `producto_id` (desde el link "Movimientos" de Productos), el filtro
  "Productos" del panel viene pre-seleccionado con ese valor, pero el usuario puede cambiarlo o
  quitarlo sin recargar la página.

### GET `informes.stock.stats`

- Mismos filtros que `informes.stock.data` (sin paginado).
- Response: `{ unidades_en_stock: number, costo_total: number, valor_venta_total: number }` — misma
  fórmula que `ProductoController::estadisticas()`, acotada a los productos que matchean los
  filtros vigentes (no siempre el catálogo completo).

## Notas de UI

- El listado de Proveedores es una **DataTable** con "Registros por página", selector de columnas y
  botón Exportar — mismo patrón visual que Clientes/Productos.
- El modal "Nuevo Proveedor"/"Editar Proveedor" reutiliza la estructura del modal de Cliente
  (`_modal_form.blade.php`), renombrando el bloque "Ventas"→"Compras" y quitando "Apodo ML" y "Lista
  de Precios".
- El modal de Producto (`_modal_form.blade.php`) reincorpora el selector "Proveedor" (Select2 con
  buscador, `dropdownParent` = el modal), en la misma posición que tenía antes de la limpieza.
- La pantalla "Informe de Stock" (`informes/stock/index.blade.php`) sigue el mismo layout que el
  listado de Productos: fila de KPIs arriba, panel de Filtros colapsable (con selector de rango de
  fechas vía `bootstrap-daterangepicker`, ya cargado por el template para el pagelevel `home`),
  DataTable abajo con la columna "Stock Saldo" alineada a la derecha (numérica).
- La acción "Movimientos" del menú de fila de Productos deja de abrir un modal y pasa a ser un link
  (`<a href="{{ route('informes.stock.index', ['producto_id' => $producto->id]) }}">`) — cambio de
  patrón deliberado y documentado (spec.md, User Story 3): esta es la única pantalla del módulo que
  navega en vez de abrir modal, porque así es la estructura real de Contagram relevada con capturas.
