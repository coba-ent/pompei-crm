# Contrato de UI / Ruta: Acciones Masivas en Productos

Interfaz que esta feature expone al usuario. Ruta web Laravel (Blade + AJAX), en español, dentro del
listado ya existente de `/productos`.

**Reglas de diseño obligatorias aplicadas** (ver `CLAUDE.md`):
- La acción se ejecuta por **AJAX sin recargar la página**; la DataTable se refresca en el lugar.
- Toda notificación de resultado usa **toasts de Toastr**.
- Los selects de Tipo de Producto y Proveedor dentro del modal usan **Select2** (`dropdownParent`
  al modal de Acciones Masivas).

## Ruta nueva

| Método | Ruta | Nombre | Acción | Respuesta |
|---|---|---|---|---|
| POST | `/productos/acciones-masivas` | `productos.acciones-masivas` | `ProductoController::accionesMasivas()` | JSON |

No se agregan rutas de lectura nuevas — la selección se arma en el cliente a partir de los datos que
`productos.data` ya devuelve.

## Contrato JSON

**Corrección post-implementación** (capturas `capturas/acciones masivas/*.png`): 4 de las 11 acciones
(`precio_venta`, `costo`, `iva`, `tipo_producto_id`) NO usan el campo genérico `valor` — tienen un
payload propio, documentado más abajo, que refleja su modal dedicado en Contagram real. Las otras 7
sí usan el contrato genérico original con `valor`.

### POST `productos.acciones-masivas` — contrato común a todas las acciones

```json
{
  "accion": "precio_venta | costo | mostrar_ventas | no_mostrar_ventas | mostrar_compras | no_mostrar_compras | activo | iva | tipo_producto_id | proveedor_id | eliminar",
  "ids": [1, 2, 3],
  "todos": false,
  "filtros": { "estado": "activos", "tipo": "", "tipo_producto_id": "", "proveedor_id": "", "id": "", "buscar": "", "stock_min": "", "stock_max": "" }
}
```

- `ids`: array de IDs seleccionados a mano. Se ignora si `todos` es `true`.
- `todos`: si es `true`, el backend reconstruye `queryFiltrada()` con `filtros` y opera sobre el
  resultado completo (no paginado) en vez de usar `ids`.

### Payload específico por acción

**`precio_venta` / `costo`** (modal "Edición Masiva de Precios de Venta"/"Costos" —
`accionAjustarPrecios()`):

```json
{
  "accion": "precio_venta",
  "modo": "porcentaje | fijo",
  "redondear": false,
  "campos": {
    "precio_venta": { "valor": 10, "signo": "aumentar | disminuir" },
    "lista_5": { "valor": 10, "signo": "aumentar" }
  }
}
```

- `campos`: al menos 1 entrada. Claves válidas para `precio_venta`: `precio_venta` y `lista_<id>` (una
  por cada lista de precio activa). Para `costo`: sólo `costo`.
- Ajuste **relativo al valor actual de cada producto** (no fija un valor único para el lote):
  `nuevo = max(0, actual ± (modo=porcentaje ? actual*valor/100 : valor))`, redondeado al entero si
  `redondear` es `true`.

**`iva`** (modal "Edición IVA por Defecto" — `accionIva()`):

```json
{ "accion": "iva", "valor_venta": "10.5", "valor_compra": "21" }
```

- `valor_venta`/`valor_compra`: opción de `Producto::OPCIONES_IVA`, cada una opcional pero se
  rechaza (422) si ambas vienen vacías. Sólo actualiza el/los campo(s) que trae valor — **no** se
  fuerza el mismo código en ambos.

**`tipo_producto_id`** (modal "Modificar Tipo de Producto" — `accionTipoProducto()`):

```json
{ "accion": "tipo_producto_id", "valor_producto": 3, "valor_servicio": 5 }
```

- `valor_producto`/`valor_servicio`: id de `tipos_producto`, cada uno opcional pero se rechaza (422)
  si ambos vienen vacíos. `valor_producto` se aplica sólo a los productos del lote con `tipo=producto`
  y `valor_servicio` sólo a los de `tipo=servicio` (mismo catálogo, aplicación segmentada).

**Las otras 7 acciones** (`mostrar_ventas`, `no_mostrar_ventas`, `mostrar_compras`,
`no_mostrar_compras`, `activo`, `proveedor_id`, `eliminar`) usan el contrato genérico original:

```json
{ "accion": "activo", "valor": "1" }
```

- `valor`: ausente/`null` para `mostrar_ventas`, `no_mostrar_ventas`, `mostrar_compras`,
  `no_mostrar_compras` y `eliminar` (no requieren valor adicional).

**Validaciones** (`AccionMasivaProductoRequest`):
- `accion`: `required`, `in:` las 11 claves soportadas.
- Payload condicional según `accion` — ver arriba (`campos.*`/`modo`/`redondear` para
  precio/costo; `valor_venta`/`valor_compra` para iva; `valor_producto`/`valor_servicio` para tipo de
  producto; `valor` genérico para el resto, reutilizando `ReglasProducto` donde aplica).
- `ids`: requerido si `todos` no es `true`, array de enteros existentes en `productos`.

**Respuestas**:

- Éxito (cualquier acción salvo `eliminar`): `200 { ok: true, mensaje: "N productos actualizados.", actualizados: N }`.
- Valor/payload inválido: `422 { ok: false, errors: {...} }` (ninguno se modifica).
- `eliminar`, resultado mixto: `200 { ok: true, eliminados: N, no_eliminados: [{ id, nombre, motivo }, ...] }`
  — `motivo` siempre `"tiene operaciones asociadas"` en la versión actual (única razón de exclusión
  vigente).
- Sin `accion` elegida: `422 { ok: false, errors: { accion: ["Elegí una acción."] } }`.

## Notas de UI

- La barra de selección ("N productos seleccionados. Haga click aquí para realizar acciones.
  Seleccionar los N productos.") aparece sobre la tabla, sólo cuando hay al menos 1 fila marcada;
  desaparece al vaciarse la selección.
- El modal genérico "Acciones Masivas" (`modal-dialog-centered`) con el `<select>` "Elegí una Acción"
  se usa para las 7 acciones sin modal propio. Al elegir una de las 4 con modal dedicado
  (`precio_venta`, `costo`, `iva`, `tipo_producto_id`), el modal genérico se cierra y se abre el modal
  específico (`modal-masiva-precios`, `modal-masiva-iva`, `modal-masiva-tipo-producto`) — ver
  `docs/documentacion_principal_crm.md` §2.2 para el detalle de cada uno.
- Al confirmar "Eliminar Masivamente" sobre un lote con productos que tienen operaciones asociadas,
  el toast de resultado y/o un detalle en el modal deben dejar explícito cuáles no se eliminaron y
  por qué (no un mensaje genérico de error).
