# Data Model: Selector de Depósito en Ventas y Compras

## Entidades modificadas (no se crean entidades nuevas)

### Venta (`ventas`)

| Columna | Tipo | Regla |
|---|---|---|
| `deposito_id` | `bigint unsigned`, nullable, FK → `depositos.id` | Nueva. Obligatoria en el formulario de alta/edición (validación a nivel Request), pero **nullable en DB** para no romper registros históricos (R4). `onDelete` restrictivo/`nullOnDelete` según regla de negocio: como `Deposito::tieneOperaciones()` ya impide borrar físicamente un depósito con movimientos, y una Venta con `deposito_id` implica movimiento, se usa `restrictOnDelete()` — coherente con que ese depósito no debería poder eliminarse mientras esta Venta exista. |

### Compra (`compras`)

| Columna | Tipo | Regla |
|---|---|---|
| `deposito_id` | `bigint unsigned`, nullable, FK → `depositos.id` | Igual que en Venta. |
| `nro_comprobante` | `string`, ya existente — **cambia de comportamiento, no de tipo/columna** | Deja de calcularse siempre server-side vía `Compra::siguienteNroComprobante()`; ese método pasa a usarse sólo para precargar el campo editable del formulario. El valor final que se persiste es el que llega en el request (`required\|string\|max:20`), sugerido o editado por el usuario (US3). |

### ConfiguracionVentas (`configuracion_ventas`, fila única — spec 043/044)

| Columna | Tipo | Regla |
|---|---|---|
| `deposito_id` | `bigint unsigned`, nullable, FK → `depositos.id`, `nullOnDelete()` | Nueva. Default para "Nueva Venta". Si el depósito referenciado se inactiva o elimina, cae a `null` y el sistema vuelve al fallback `Deposito::porDefecto()` (spec User Story 2, escenario 4). |
| `deposito_compra_id` | `bigint unsigned`, nullable, FK → `depositos.id`, `nullOnDelete()` | Nueva. Default para "Nueva Compra". Mismo comportamiento. Nombrada distinto de `deposito_id` porque ambas conviven en la misma fila/tabla (mismo patrón ya usado por `categoria_id`/`categoria_compra_id`). |

No se agrega columna nueva a `movimientos_stock`: esa tabla ya tiene `deposito_id` desde spec 030 y no
cambia — el movimiento generado por una Venta/Compra simplemente ahora recibe el `deposito_id` de la
operación en vez de siempre el de `Deposito::porDefecto()`.

## Relaciones nuevas

- `Venta::deposito(): BelongsTo` → `Deposito`
- `Compra::deposito(): BelongsTo` → `Deposito`
- `ConfiguracionVentas::deposito(): BelongsTo` → `Deposito` (usa `deposito_id`)
- `ConfiguracionVentas::depositoCompra(): BelongsTo` → `Deposito` (usa `deposito_compra_id`)

## Reglas de resolución (aplican en `StockDeVenta`/`StockDeCompra`)

```
resolverDeposito(Venta $venta):
  si $venta->origen === 'mercadolibre' → MercadoLibreConfiguracion::actual()->depositoEfectivo()  [sin cambios]
  si $venta->origen === 'tiendanube'   → TiendanubeConexionRest::actual()->depositoEfectivo()      [sin cambios]
  si no (manual)                       → $venta->deposito ?? Deposito::porDefecto()

resolverDeposito(Compra $compra):
  → $compra->deposito ?? Deposito::porDefecto()
```

En la práctica, para Ventas/Compras creadas después de este feature, `deposito_id` siempre viene
seteado desde el Request (campo obligatorio) — el `?? Deposito::porDefecto()` es sólo una red de
seguridad para registros históricos con `deposito_id = null` que de alguna forma disparen de nuevo el
cálculo de stock (edge case defensivo, no un flujo esperado ya que el histórico no se toca).

## Validación (Form Requests)

- `StoreVentaRequest` / `UpdateVentaRequest`: agregar `'deposito_id' => 'required|integer|exists:depositos,id'`.
- `StoreCompraRequest` / `UpdateCompraRequest`: agregar `'deposito_id' => 'required|integer|exists:depositos,id'` y `'nro_comprobante' => 'required|string|max:20'` (esta última ya existía como columna pero nunca como input validado del request — antes se calculaba en el controller, ahora viene del formulario).
- `ConfiguracionVentasController@guardar`: agregar `'deposito_id' => 'nullable|integer|exists:depositos,id'` y `'deposito_compra_id' => 'nullable|integer|exists:depositos,id'` (opcionales, igual que el resto de los campos de esa pantalla).

## Precarga de defaults (Controllers)

- `VentaController@create`: agregar `depositoId` al array `$defaults`, resuelto como
  `$configuracionVentas->deposito_id` filtrado contra `Deposito::activos()` (mismo patrón ya usado
  para `categoriaId`/`vendedorId`/`listaPrecioId`); si no hay match, `null` → el JS/Blade cae al
  primer depósito activo (`Deposito::porDefecto()` equivalente en la vista, ya que la vista sólo lista
  depósitos activos y puede preseleccionar el primero cuando no hay default).
- `CompraController@create`: ídem con `$configuracionVentas->deposito_compra_id`.
- Ambos `edit()`: no aplican defaults (ya lo dice el comentario existente en el código — "sólo alta
  nueva, sólo catálogos vigentes/activos"); el depósito mostrado es el que la operación ya tiene
  persistido (`$venta->deposito_id` / `$compra->deposito_id`).

## Precarga del N° de comprobante sugerido (US3)

- `CompraController@create`: agregar `nroComprobanteSugerido` al array `$defaults`, calculado con
  `Compra::siguienteNroComprobante($configuracionVentas->tipo_comprobante_compra ?? 'B')` (mismo tipo
  de comprobante que ya se usa como default) — es sólo un valor de partida, el usuario lo ve
  precargado en el input y puede dejarlo o sobrescribirlo.
- `CompraController@edit()`: no recalcula nada — el input se precarga con `$compra->nro_comprobante`
  ya persistido, igual que el resto de los campos en edición.
- `CompraController@store()`: usa `$datos['nro_comprobante']` (validado, obligatorio) en vez de volver
  a llamar `Compra::siguienteNroComprobante()` para construir el registro.
