# Research: Percepciones/Impuestos Internos/Intereses funcionales en NC/ND

## 1. Persistencia: columna ya existente, sin migración

**Decisión**: usar `notas_credito_debito.impuestos` (json, nullable, `casts => 'array'` en el
modelo desde `2026_07_30_060006_create_notas_credito_debito_tables.php`) para persistir
`[{tipo, concepto, monto}, ...]` — misma forma que `venta_conceptos`/`compra_conceptos`/
`presupuesto_conceptos`, pero embebida en JSON en vez de tabla propia (porque la columna ya nació
así, sin uso hasta ahora).

**Rationale**: cero migración nueva, cero tabla nueva — el campo ya estaba previsto para esto
(`docs/modelo_datos.md` ya lo describía como "mismo patrón que `presupuesto_conceptos`" antes de
este spec, sólo que nunca se conectó a una UI).

**Alternativa considerada**: crear `nota_credito_debito_conceptos` como tabla propia (igual a
`venta_conceptos`) — rechazada, sobre-ingeniería dado que la columna JSON ya existe y cubre el caso
sin necesitar joins ni migraciones.

## 2. Validación backend: mismo patrón que StoreVentaRequest/StoreCompraRequest

**Decisión**: agregar a `StoreNotaCreditoDebitoRequest`/`UpdateNotaCreditoDebitoRequest`:

```php
'conceptos' => 'nullable|array',
'conceptos.*.tipo' => 'required_with:conceptos|in:percepcion,impuesto_interno,interes',
'conceptos.*.concepto' => 'required_with:conceptos|string|max:255',
'conceptos.*.monto' => 'required_with:conceptos|numeric',
```

**Rationale**: copia exacta de `StoreVentaRequest`/`StoreCompraRequest` (`app/Http/Requests/
StoreVentaRequest.php` líneas 60-63) — mismo dominio de datos, misma forma.

## 3. Controller: guardar `impuestos` en store/storeCompra/aplicarEdicion

**Decisión**: en `NotaCreditoDebitoController@store`/`@storeCompra`, agregar `'impuestos' =>
$datos['conceptos'] ?? []` al `create()` de la nota (mismo array ya validado, sin transformación).
En `aplicarEdicion()`, agregar `'impuestos' => $datos['conceptos'] ?? []` al `$nota->update([...])`.

**Rationale**: consistente con cómo ya se persisten `venta_conceptos` en `VentaController@store`
(mismo shape, sólo cambia el destino: columna JSON en vez de relación `hasMany`).

## 4. Frontend: reusar el catálogo PERCEPCIONES y el patrón renderConceptos de ventas.js

**Decisión**: en `notas-credito-debito.js`, agregar el mismo array `PERCEPCIONES` (27 entradas: IVA
Percepción, Ganancias, Sellos, IIBB × 24 jurisdicciones) y una función `renderConceptos()` que
siga exactamente el patrón ya usado en `resources/js/ventas.js` (función `renderConceptos`, líneas
~719-741): selector de percepción para tipo `percepcion`, input de texto libre para
`impuesto_interno`/`interes`, input de Monto, botón de eliminar. Los 3 enlaces reemplazan
`js-concepto-noop` por `js-add-concepto` con `data-tipo` (mismo atributo ya usado en Ventas/Compras).

**Rationale**: cero UI nueva que inventar — se copia el componente ya construido y validado en
Ventas, ajustando sólo el nombre de la variable de estado (`conceptos` en vez de compartir con
`items`) para no pisar la lista de ítems de la nota.

**Alternativa considerada**: extraer `PERCEPCIONES`/`renderConceptos` a un módulo JS compartido
(`resources/js/conceptos.js`) para no duplicar el array de 27 percepciones en 4 archivos
(ventas.js/compras.js/presupuestos.js/notas-credito-debito.js) — deseable a futuro, pero fuera de
alcance de este spec (no lo pide ningún requisito funcional); se documenta como posible mejora
técnica, no se bloquea la feature por esto.

## 5. Cálculo del Total: conceptos se suman después del subtotal con descuento

**Decisión**: en `notas-credito-debito.js`, `totalActual()`/`recalcular()` agregan
`conceptos.reduce((acc, c) => acc + (Number(c.monto) || 0), 0)` al total ya calculado (subtotal de
ítems con descuento general aplicado + IVA), mismo orden que `ventas.js` (línea ~811:
`const totalConceptos = conceptos.reduce(...)`).

**Rationale**: mismo criterio matemático ya validado en Ventas/Compras/Presupuestos — no hay
motivo para que NC/ND calcule distinto.

## 6. Precarga en edición

**Decisión**: `form.blade.php` agrega `conceptos: @json($notaCreditoDebito->impuestos ?? [])` a
`window.NotaFormData`, y `notas-credito-debito.js` inicializa `let conceptos =
Array.isArray(data.conceptos) && data.conceptos.length ? data.conceptos.slice() : [];` — mismo
patrón que `ventas.js` línea 451.

**Rationale**: copia directa del patrón ya usado; el cast `'impuestos' => 'array'` en el modelo
`NotaCreditoDebito` ya devuelve un array PHP listo para `@json()`.
