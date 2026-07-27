# Contrato de UI / Rutas: Productos & Servicios

Interfaz que esta feature expone al usuario. Rutas web Laravel (Blade + AJAX), en español, sobre el
layout `layouts.default`. Todas bajo el prefijo `/productos`.

**Reglas de diseño obligatorias aplicadas** (ver `CLAUDE.md`):
- El listado es una **DataTable responsive con carga AJAX server-side** → endpoint `productos.data`.
- Alta/edición/eliminación y **ajuste de stock** se hacen en **modales de Bootstrap enviados por AJAX**;
  la página **nunca** se recarga. Los endpoints responden **JSON**, no redirects.
- Toda notificación (éxito/error) se muestra con **toasts de Toastr** en el front.

## Rutas

| Método | Ruta | Nombre | Acción | Respuesta | Historia |
|---|---|---|---|---|---|
| GET | `/productos` | `productos.index` | Página del listado (shell con tabla + modales) | HTML (Blade) | US7 |
| GET | `/productos/data` | `productos.data` | Datos server-side de la DataTable (paginado/orden/búsqueda/filtros) | JSON (DataTables) | US7 |
| GET | `/productos/stats` | `productos.stats` | Métricas para cards (total, activos, servicios, stock bajo/nuevos) | JSON | — |
| GET | `/productos/export` | `productos.export` | Exporta el listado filtrado a CSV/Excel (BOM UTF-8, streaming) | descarga CSV | — |
| POST | `/productos` | `productos.store` | Crear producto/servicio (modal) | JSON | US1, US2, US3, US4, US5 |
| GET | `/productos/{producto}` | `productos.show` | Datos del producto (con variantes y precios) para precargar el modal | JSON | US1 |
| PUT/PATCH | `/productos/{producto}` | `productos.update` | Actualizar producto (modal) | JSON | US1..US5 |
| DELETE | `/productos/{producto}` | `productos.destroy` | Eliminar físicamente (sólo si no tiene operaciones) | JSON | US8 |
| PATCH | `/productos/{producto}/estado` | `productos.estado` | Alternar activo/inactivo (baja lógica) | JSON | US8 |
| POST | `/productos/{producto}/stock` | `productos.stock.ajuste` | Registrar ajuste de stock (aumento/disminución) | JSON | US6 |
| GET | `/productos/{producto}/movimientos` | `productos.movimientos` | Histórico de movimientos de stock del producto | JSON (DataTables o lista) | US6 |

## Contratos JSON

### GET `productos.data` (DataTables server-side)

- Request: parámetros estándar de DataTables (`draw`, `start`, `length`, `search[value]`, `order[...]`)
  + filtros extra: `estado` (`activos`/`inactivos`/`todos`), `tipo` (`producto`/`servicio`/`todos`),
  `proveedor_id` (opcional).
- Response: `{ draw, recordsTotal, recordsFiltered, data: [ { id, nombre, codigo, tipo, precio_venta,
  stock_total, activo (bool), acciones (HTML) }, ... ] }`.
- Búsqueda global aplica sobre `nombre` y `codigo`/SKU (FR-025); filtros sobre estado y tipo (FR-026).

### POST `productos.store` / PATCH `productos.update` (form del modal, AJAX)

Campos aceptados: `nombre` (requerido), `codigo`, `tipo` (`producto`/`servicio`), `proveedor_id`,
`descripcion`, `mostrar_en_ventas` (bool), `precio_venta`, `iva_venta_pct`, `mostrar_en_compras`
(bool), `costo`, `iva_compra_pct`; `variantes` (array: `id?`, `sku`, `talle`, `color`, `nombre`,
`precio_extra`); `precios` (array: `lista_precio_id`, `precio`).

Validaciones (FormRequest):

- `nombre`: required, string, max 255.
- `codigo`: nullable, string; regla `SkuUnico` (único global producto ∪ variante, ignora el propio y
  los NULL). FR-010.
- `tipo`: required, in `producto,servicio`. Default `producto`.
- `proveedor_id`: nullable, exists en `proveedores` (si la tabla existe).
- `precio_venta`, `costo`: nullable→0, numeric, `min:0`. FR-007.
- `iva_venta_pct`, `iva_compra_pct`: nullable, numeric, `min:0`. FR-007.
- `variantes.*.sku`: nullable, regla `SkuUnico`; único también dentro del propio payload.
- `precios.*.lista_precio_id`: required con la fila, exists en `listas_precio`; único por lista dentro
  del payload. `precios.*.precio`: numeric, `min:0`. FR-014.

Respuestas:

- Éxito → HTTP 200 `{ ok: true, mensaje: "...", producto: {...} }`. El front cierra el modal, recarga
  la DataTable y muestra un toast de éxito.
- Error de validación → HTTP 422 `{ ok: false, errors: { campo: ["mensaje", ...] } }`. El front
  muestra los errores en el modal y/o un toast de error, sin recargar.

### GET `productos.show`

- Response: `{ producto: { ...campos..., variantes: [...], precios: [ { lista_precio_id, precio } ] } }`
  para precargar el modal de edición.

### DELETE `productos.destroy`

- Si `producto->tieneOperaciones()` → HTTP 409 `{ ok: false, mensaje: "Sólo puede inactivarse: el
  producto tiene operaciones asociadas." }` (toast de error). FR-020.
- Si no → HTTP 200 `{ ok: true, mensaje: "Producto eliminado." }` (toast de éxito, fila removida).
  FR-023.

### PATCH `productos.estado` (toggle activo)

- Alterna `activo`; HTTP 200 `{ ok: true, activo: bool, mensaje: "..." }`. El front actualiza la fila y
  muestra toast. FR-021/FR-022.

### POST `productos.stock.ajuste` (ajuste de stock — modal, AJAX)

- Request: `{ deposito_id, variante_id? (si el producto tiene variantes), operacion:
  "aumento"|"disminucion", cantidad (>0), descripcion? }`.
- Validaciones (`AjusteStockRequest`): el producto debe ser tipo `producto` (no servicio → 422 si es
  servicio, FR-019/SC-007); `deposito_id` exists; `cantidad` numeric > 0; `variante_id` requerido si el
  producto tiene variantes, exists y perteneciente al producto.
- Efecto (dentro de transacción, `StockService`): actualiza/crea la fila de `stocks`
  (producto+variante+depósito) sumando/restando la cantidad, y registra un `movimiento_stock` tipo
  `ajuste` con la descripción y el usuario. Un ajuste puede dejar el stock negativo (research D7).
- Response: HTTP 200 `{ ok: true, mensaje: "Stock ajustado.", stock_actual: number }`. El front
  actualiza la vista de stock y la fila de la tabla, y muestra toast. FR-017/SC-003.

### GET `productos.movimientos`

- Request: `producto` (path) + opcional filtro por `deposito_id`/`variante_id`.
- Response: lista/DataTables de movimientos `{ fecha, tipo, deposito, variante?, cantidad, descripcion,
  usuario }` ordenada por fecha desc. FR-018.

## Notas de UI

- El listado usa una **DataTable** (patrón NexaDash, igual que Clientes), responsive, con "Registros por
  página" y botón de exportar. Columnas: nombre, código/SKU, tipo, precio de venta, stock total, estado,
  acciones.
- El botón "Nuevo Producto" abre el **modal** de alta; el ícono de editar por fila abre el mismo modal
  precargado (`_modal_form.blade.php`). El modal tiene secciones: Datos básicos, Económicos (precios/IVA
  + mostrar en ventas/compras), **Variantes** (filas dinámicas talle/color/SKU), **Precios por lista**.
- El ícono de **stock** por fila abre `_modal_stock.blade.php`: ajuste (depósito, variante si aplica,
  aumento/disminución, cantidad, descripción) + histórico de movimientos del producto. Oculto/deshabilitado
  para servicios.
- Indicador de tipo (producto/servicio) y de stock total por fila; badge de inactivo.
- Toda respuesta (éxito/error/validación) se comunica con **toasts de Toastr**.
