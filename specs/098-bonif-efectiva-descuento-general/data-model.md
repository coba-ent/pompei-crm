# Data Model: Bonificación efectiva por línea con Descuento General

Sin migraciones. Todas las columnas que necesita esta feature ya existen; lo que se agrega son
métodos derivados sobre modelos existentes, que leen y combinan columnas ya persistidas — ningún
campo nuevo en base de datos.

## Entidades existentes (sin cambio de esquema)

### `presupuesto_items` / `venta_items` / `compra_items`

Ya persisten, para cada línea:

| Columna | Tipo | Rol en esta feature |
|---|---|---|
| `cantidad` | decimal:3 | Junto a `precio_unitario`, reconstruye el bruto de la línea. |
| `precio_unitario` | decimal:2 | Idem. |
| `descuento_pct` | decimal:2, nullable | Descuento propio de línea (input editable, sin cambios). |
| `subtotal` | decimal:2 | Ya calculado por `CalculoComprobante::calcular()` con línea + Descuento General combinados — es la fuente de la que se deriva el % efectivo (research.md Decisión 1). |

**Método nuevo** (idéntico en los 3 modelos — `PresupuestoItem`, `VentaItem`, `CompraItem`):

```php
public function bonifEfectivaPct(): float
// round((1 - subtotal / (cantidad * precio_unitario)) * 100, 2); 0.0 si el bruto es <= 0

public function bonifEfectivaEtiqueta(): string
// "10%", "12,5%" o "-" si bonifEfectivaPct() <= 0
```

Sin nuevos atributos ni relaciones — son accessors puros sobre columnas ya cargadas, sin queries
adicionales (no hay N+1: no tocan `producto()` ni ninguna relación).

### `presupuestos` / `ventas` / `compras`

Ya persisten `descuento_general_tipo`, `descuento_general_pct`, `descuento_general_monto`. Sin
cambios — esta feature no los lee directamente en el nuevo cálculo (research.md Decisión 1: se
deriva de `subtotal`, no de estos campos, precisamente para no fallar en modo "monto fijo").

### `nota_credito_debito_items`

Sin cambios de ningún tipo. No recibe ningún método nuevo (FR-008). Se documenta acá sólo para
dejar explícito por qué no aplica la Decisión 1: esta tabla no tiene columna `subtotal` (a
diferencia de las tres anteriores), así que la fórmula de `bonifEfectivaPct()` no es aplicable sin
agregar una columna — que la spec descarta.

### `notas_credito_debito`

Ya persiste `descuento_general_tipo`, `descuento_general_pct`, `descuento_general_monto` y
`monto` (total final, ya calculado client-side y guardado tal cual). No persiste ningún subtotal
intermedio de ítems.

**Método nuevo**:

```php
public function montoDescuentoGeneral(): float
// Replica el algoritmo de notas-credito-debito.js::recalcular() (research.md Decisión 5):
//   subtotalSinDescuento = Σ items: cantidad * precio * (1 - descuento_pct/100)
//   factor según descuento_general_tipo/_pct/_monto
//   retorna round(subtotalSinDescuento * (1 - factor), 2)
```

Requiere `$this->items` cargado (ya lo está en `NotaCreditoDebitoController::pdf()`, que hace
`->load(['items.producto', ...])`); si no está cargado, Eloquent lo trae con una query adicional
(lazy load) — aceptable en el contexto de un PDF de un solo comprobante (no es un listado).

## Sin nuevas relaciones, sin nuevos estados, sin ciclo de vida nuevo

Esta feature no introduce entidades, no cambia relaciones `belongsTo` existentes, y no agrega
ningún estado o transición — es una capa de lectura sobre datos que el sistema ya calcula y
persiste hoy.
