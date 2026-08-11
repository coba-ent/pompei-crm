# Research: Estado "Vencido" en Compras + ítems con cantidad negativa

## 1. Estado "Vencido" — dónde vive hoy

**Decisión**: extender `Compra::estadoPago()` (app/Models/Compra.php) para devolver `'vencido'` cuando
`fecha_vto_pago` está seteada, es anterior a hoy, y `aPagar() > 0.005` — mismo umbral que ya usa el
método para distinguir "parcial" de "pagado". La prioridad de evaluación es: `pagado` > `vencido` >
`parcial`/`a_pagar` (una compra 100% pagada nunca es "vencida" aunque la fecha haya pasado — FR-004).

**Rationale**: el KPI "Vencido" de `CompraController::kpisData()` (líneas ~74-88) ya usa exactamente
esta regla en SQL crudo (`fecha_vto_pago < CURDATE() AND a_pagar > 0.005`) — extender el método del
modelo con la misma condición evita divergencia entre el KPI agregado y el badge de fila (SC-002).

**Alternativas consideradas**:
- *Columna `vencido` calculada en la query del DataTable (`CompraController@data`)*: rechazado —
  duplicaría la lógica que ya vive en `estadoPago()`, usado también en `_row_actions.blade.php`
  (mismo patrón que Ventas). Mejor un solo lugar de verdad.
- *Nueva columna persistida `estado_pago`*: rechazado — mismo principio que ya aplica el
  Assumption existente de `estadoPago()` ("Nunca forzable", derivado siempre).

## 2. Dónde tocar el badge de fila y el filtro

**Hallazgo (cambia el alcance)**: `CompraController@data` (líneas 112-124) **ya tiene una rama
`'vencido'`** en el filtro `estado_pago` (agregada en spec 056, filtros de Compras) — el backend de
filtrado funciona si se le manda `estado_pago=vencido`. Lo que falta es exclusivamente:

1. **`resources/views/compras/index.blade.php`**: el `<select id="filtro-estado-pago">` no tiene la
   `<option value="vencido">Vencido</option>` — el usuario no puede seleccionar algo que el backend ya
   sabe responder.
2. **`app/Models/Compra.php`**: `estadoPago()` nunca devuelve `'vencido'` (sólo `a_pagar`/`parcial`/
   `pagado`) — es el método que alimenta tanto el badge de fila (`_row_actions.blade.php`) como la
   columna `estado_pago` del DataTable (`addColumn('estado_pago', ...)`, línea 184) y el JSON del
   detalle (líneas 428/470). Hay que extender su lógica con la misma condición que ya usa el filtro
   SQL (§1) para que el badge y el filtro queden consistentes.
3. **`resources/views/compras/_row_actions.blade.php`**: agregar la rama `'vencido' => 'danger'` /
   `'Vencido'` al `match ($compra->estadoPago())` que ya arma color y label del badge.

**Rationale**: el filtro backend (lo más "nuevo" conceptualmente) ya está — este feature es
mayormente exponerlo en la UI (option del select) y hacer que `estadoPago()` (la fuente de verdad del
badge) sepa devolver el mismo estado que el filtro ya sabe consultar.

## 3. Ítems con cantidad negativa — validación

**Decisión**: cambiar `items.*.cantidad` de `'required|numeric|gt:0'` a `'required|numeric|not_in:0'`
en `StoreCompraRequest` y `UpdateCompraRequest` (ambas idénticas hoy). `precio_unitario` queda **sin
cambios** (`'required|numeric|gte:0'` ya rechaza negativos, cumple FR-006).

**Rationale**: `not_in:0` es la forma más simple de "cualquier número menos cero" con las reglas de
Laravel; ya se usa `gt:0`/`gte:0` en el resto del proyecto así que mantiene el mismo estilo de reglas
declarativas en vez de un closure custom.

**Alternativa considerada**: closure de validación (`function ($attr, $value, $fail) {...}`) —
rechazada por más verbosa sin necesidad; `not_in:0` alcanza porque el único caso a excluir es cero
exacto (`gt:0` cambia a `not_in:0` en vez de sacarse del todo, para seguir rechazando cantidad 0).

## 4. Cálculo de subtotal/total del ítem — ya soporta negativos sin cambios

**Decisión**: no tocar `resources/js/compras.js` (líneas ~463-469, función `renderItems()`) — el
cálculo `bruto = cant * precio; subtotal = bruto - descuento; subtotalConIva = subtotal + iva` ya
propaga el signo de `cant` correctamente sin ningún `Math.abs()` de por medio. El input de cantidad ya
es un `<input type="text" inputmode="decimal">` sin `min="0"` ni normalización que descarte el signo
(`normalizarDecimal()` sólo reemplaza coma por punto). Cumple FR-007/FR-008 tal cual está.

**Rationale**: confirmado leyendo el código — el único bloqueo real es la validación de backend (§3) y
el manejo de stock (§5), no el cálculo de totales.

## 5. Movimiento de stock — el bug real a corregir

**Decisión**: `App\Services\Egresos\StockDeCompra::aplicarAlta()` hoy llama SIEMPRE a
`$this->stock->registrarEntrada(...)` para cada ítem que mueve stock, sin importar el signo de
`cantidad`. `StockService::registrarEntrada()`/`registrarSalida()` internamente hacen `abs($cantidad)`
antes de aplicar el signo fijo de la dirección (`mover()`, `$signo = $tipo === 'salida' ? -1 : 1`) —
es decir, **hoy un ítem con cantidad -2 sumaría +2 al stock** (el signo se pierde), en vez de restar 2.
Hay que corregir `StockDeCompra` para que, por cada ítem, elija `registrarEntrada` si `cantidad > 0` o
`registrarSalida` (con `abs(cantidad)`) si `cantidad < 0` — tanto en `aplicarAlta()` como en el
`reintegrarPorEliminacion()`/`reaplicarPorEdicion()` correspondientes (misma corrección, dirección
invertida en cada uno, ya que "reintegrar" deshace lo que "aplicar" hizo).

**Rationale**: es el único cambio no trivial del feature — sin él, FR-009 (el renglón negativo resta
stock) queda roto en silencio (la validación pasaría, el total de la compra sería correcto, pero el
stock terminaría mal).

**Alternativas consideradas**:
- *Agregar un parámetro `$signo` a `StockService::registrarEntrada`*: rechazado — cambiaría la firma
  de un método ya usado en Ventas/Remitos/otros módulos; más simple resolver la dirección en
  `StockDeCompra` (que ya conoce el contexto de "esto es una compra") antes de llamar al servicio
  genérico.
