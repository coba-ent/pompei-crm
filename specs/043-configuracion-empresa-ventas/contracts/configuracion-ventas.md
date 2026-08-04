# Contrato: tab "Ventas" de Configuración & Ajustes

Sigue el patrón AJAX + modal/formulario in-page + Toastr obligatorio (CLAUDE.md §Especificaciones de
diseño). No hay tabla (DataTables) porque es un formulario de fila única, no un listado.

## `GET configuracion.index` (pantalla contenedora, no específico de Ventas)

Devuelve la vista `configuracion/index.blade.php` con los datos de todos los tabs, incluyendo:

```php
[
  'funciones' => FuncionAvanzada::ordenadas()->get(),
  'configuracionVentas' => ConfiguracionVentas::first(), // puede ser null
  'categoriasVenta' => Categoria::venta()->activas()->orderBy('nombre')->get(),
  'vendedores' => Vendedor::orderBy('nombre')->get(),
  'listasPrecio' => ListaPrecio::where('activo', true)->orderBy('nombre')->get(),
]
```

## `PUT configuracion.ventas.guardar` (JSON, AJAX)

**Request** (todos los campos opcionales/nullable):

```json
{
  "categoria_id": 3,
  "vendedor_id": null,
  "lista_precio_id": null,
  "tipo_comprobante": "B",
  "dias_vto_cobro": 15
}
```

**Validación**:
- `categoria_id`: `nullable|integer|exists:categorias,id`
- `vendedor_id`: `nullable|integer|exists:vendedores,id`
- `lista_precio_id`: `nullable|integer|exists:listas_precio,id`
- `tipo_comprobante`: `nullable|in:A,B,C,E`
- `dias_vto_cobro`: `nullable|integer|min:0`

**Response 200**:

```json
{ "ok": true, "mensaje": "Configuración de Ventas guardada." }
```

**Response 422**: errores de validación estándar Laravel (`{"message": "...", "errors": {...}}`), consumidos
por el JS del tab para mostrarlos vía Toastr, sin recargar la página (comportamiento ya usado en el resto
del CRM, ver `resources/js/mi-perfil.js` como referencia de patrón).

## Efecto en `GET ventas.create`

No es un endpoint nuevo — `VentaController@create` (ya existente) pasa además al `window.VentaFormData`
un bloque `defaults`:

```json
{
  "defaults": {
    "categoriaId": 3,
    "vendedorId": null,
    "listaPrecioId": null,
    "tipoComprobante": "B",
    "fechaVtoCobro": "2026-08-19"
  }
}
```

Sólo se calcula/incluye cuando la venta es un alta nueva (`!$venta && !$presupuestoOrigen`). El JS
(`resources/js/ventas.js`) aplica estos defaults **antes** de que el usuario elija un Cliente; si el
Cliente elegido trae su propio `tipo_comprobante_defecto`, ese valor sigue pisando al default global
(orden de prioridad ya existente, sin cambios).
