# Data Model: Buscador de productos del detalle con foco persistente

**No hay cambios de base de datos**: ni tablas, ni columnas, ni migraciones, ni modelos Eloquent. La
feature es exclusivamente de front-end y consume en modo lectura el catálogo que ya existe.

## Entidad consumida (sin modificar): Producto

Del JSON que devuelve el endpoint de catálogo, el widget y las 3 pantallas usan:

| Campo | Uso |
|---|---|
| `id` | Identificador de la línea (`producto_id`) y primer dato visible de la fila |
| `nombre` | Texto de la fila y `descripcion` de la línea del detalle |
| `codigo` | Se muestra entre paréntesis al final de la fila **si existe** |
| `precio` | Precio unitario de la línea en **Venta** y **Presupuesto** (ya resuelto por lista de precios del lado del servidor) |
| `costo` | Precio unitario de la línea en **Compra** |
| `iva_venta_pct` | Alícuota de IVA de la línea en Venta/Presupuesto (con `'21'` como valor por defecto si viene vacío) |
| `iva_compra_pct` | Alícuota de IVA de la línea en Compra, **sólo cuando el comprobante es tipo A** |

## Estructuras en memoria (no persistidas)

### `ItemSugerencia` — lo que el widget muestra en el panel

Estructura mínima que el widget necesita; el objeto completo del producto viaja en `datos` para que
el callback `onElegir` pueda armar la línea sin volver a consultar.

```js
{
  id: number,       // identificador, usado como clave de la fila
  texto: string,    // lo que se ve en la fila, ya formateado por el llamador
  datos: object,    // el producto crudo tal como vino del catálogo
}
```

### `EstadoBuscador` — estado interno del widget (uno por instancia montada)

```js
{
  termino: string,          // texto vigente del input
  abierto: boolean,         // si el panel está desplegado
  cargando: boolean,        // hay una consulta en curso
  error: boolean,           // la última consulta falló
  items: ItemSugerencia[],  // resultados vigentes
  resaltado: number,        // índice resaltado; -1 = ninguno (ver research.md Decisión 5)
  secuencia: number,        // contador para descartar respuestas fuera de orden (FR-012)
}
```

**Transiciones relevantes** (las que fijan requisitos, no el detalle de implementación):

| Desde | Evento | Hacia |
|---|---|---|
| cerrado | el usuario tipea (tras el debounce) | abierto + `cargando: true` |
| abierto + cargando | llega la respuesta **de la secuencia vigente** | abierto con `items` (o estado *sin coincidencias*) |
| abierto + cargando | llega una respuesta **de secuencia vieja** | se descarta, el estado no cambia (FR-012) |
| abierto + cargando | la consulta falla | abierto + `error: true` (FR-011) |
| abierto | flecha ↑/↓ | cambia `resaltado` (tope en los extremos, sin dar la vuelta) |
| abierto | Enter con `resaltado >= 0` | se ejecuta `onElegir(item)` → cerrado, `termino: ''`, `resaltado: -1`, **el input conserva el foco** (FR-003) |
| abierto | Enter con `resaltado === -1` | no pasa nada (research.md Decisión 5) |
| abierto | Escape | cerrado, **conservando `termino` y el foco** (FR-007) |
| abierto | clic fuera / blur | cerrado, sin agregar nada, conservando `termino` (FR-008) |
| cerrado con `termino` | el usuario vuelve a tipear | abierto (FR-004) |

### Línea del detalle que se agrega — **sin cambios**

El objeto que `onElegir` inserta en el array `items` de cada pantalla es exactamente el que se
construye hoy en el handler `select2:select` de cada archivo. Se transcribe acá sólo como contrato de
no-regresión (FR-006/SC-004); no se rediseña:

- **Venta**: `{ producto_id, descripcion: nombre, cantidad: 1, precio_unitario: precio || 0, descuento_pct: null, iva_pct: iva_venta_pct || '21', _precioCatalogoOriginal: precio || 0 }`
- **Presupuesto**: misma forma que Venta.
- **Compra**: `{ producto_id, descripcion: nombre, cantidad: 1, precio_unitario: costo || 0, descuento_pct: null, iva_pct: (tipo === 'A' ? iva_compra_pct || null : null), _precioCatalogoOriginal: costo || 0 }`

En las 3 pantallas la línea nueva se inserta **al principio** del detalle (`unshift`), igual que hoy.
