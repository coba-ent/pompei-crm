# Contrato: `GET /informes/stock/data` (DataTables server-side)

Endpoint existente servido por `InformeStockController::data()`, consumido por
`resources/js/informe-stock.js`. No cambian ruta, verbo, ni parámetros de request (filtros y
paginación de Yajra DataTables sin modificar).

## Cambios en la respuesta (`data[]`)

| Campo | Antes | Después |
|---|---|---|
| `fecha` | `"YYYY-MM-DD"` | `"YYYY-MM-DD HH:MM:SS"` (el frontend debe seguir tomando sólo los primeros 10 caracteres para el `DD/MM/YYYY` que ya muestra; ver Frontend) |
| `descripcion` | texto libre del movimiento (puede venir vacío) | **sin cambios** — se mantiene tal cual para compatibilidad, aunque el frontend deja de usarla directamente en la columna Detalle |
| `detalle` | *(no existía)* | texto ya formateado para la columna "Detalle": datos de venta si `origen_type = Venta`, o el mismo contenido que tenía `descripcion` para el resto de los orígenes |

Resto de campos (`tipo`, `producto`, `cantidad`, `stock_saldo`, `usuario`) sin cambios.

## Frontend (`resources/js/informe-stock.js`)

- La columna que hoy usa `data: 'descripcion'` pasa a usar `data: 'detalle', defaultContent: ''`.
- El `render` de la columna `fecha` no necesita cambios de lógica (sigue haciendo
  `String(val).slice(0, 10).split('-').reverse().join('/')`), porque sigue tomando sólo la parte
  de fecha del string ISO — ahora simplemente el string de origen es más largo (incluye hora), lo
  cual no afecta el `slice(0, 10)`.
- El orden por defecto (`order: [[0, 'asc']]`) no cambia — sigue apuntando a la misma columna
  índice 0 (`fecha`), que ahora trae hora.

## Compatibilidad

- No es una ruptura de contrato para otros consumidores: el único consumidor de este endpoint es
  `informe-stock.js`. No hay integraciones externas ni otra pantalla que dependa de este JSON.
