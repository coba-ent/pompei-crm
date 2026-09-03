# Plan técnico — spec 099

## Enfoque

El fix es chico y está acotado a **una decisión**: qué método de `AjustesPendientesNotaCreditoDebito`
usa la validación. Todo lo demás ya existe.

No hay migraciones, no hay contrato nuevo con el frontend, y no se toca el guardado.

## La pieza central

`AjustesPendientesNotaCreditoDebito` gana un método que decide el modo, en vez de que cada request
elija a mano:

```php
public function topeDelRenglon(
    Venta|Compra $comprobante,
    int $productoId,
    ?int $itemOrigenId,
    ?NotaCreditoDebito $excluir = null,
): float
```

- Con `itemOrigenId` → busca esa línea **dentro del comprobante** y devuelve `pendienteDeLinea()`.
- Sin `itemOrigenId`, o si el id no pertenece al comprobante → `pendiente()`, el agregado de hoy.

Que la decisión viva acá y no en los requests es lo que evita que vuelvan a divergir: hoy el bug
existe justamente porque `itemsDisponibles()` y la validación eligen el modo cada uno por su lado.

**El id que no pertenece al comprobante cae al agregado y no lanza** (FR-003): un renglón manipulado
queda validado por el criterio más restrictivo, que es el efecto deseado. Rechazar con excepción
convertiría un dato viejo en un error 500.

## Los dos requests

`StoreNotaCreditoDebitoRequest` y `UpdateNotaCreditoDebitoRequest` tienen hoy el **mismo bloque
duplicado**, y sólo difieren en el comprobante de origen y en la nota a excluir. Los dos pasan a
llamar a `topeDelRenglon()`, mandando `$item['item_origen_id'] ?? null`.

Se deja la duplicación del bucle: unificarla es un refactor de otro alcance y esta spec toca la
validación de un comprobante fiscal — cuanto menos superficie, mejor.

## El mensaje de error (FR-005)

Hoy: `"La cantidad máxima disponible para ajustar es 0."`

En modo por línea el usuario necesita saber **cuál** línea topó, porque puede haber varias del mismo
producto. El mensaje pasa a nombrar el importe de la línea, que es lo que la distingue en pantalla:

> `La cantidad máxima disponible para ajustar en esta línea ($4.616.354,23) es 0.`

En modo agregado el texto no cambia. Y el importe se muestra **sólo cuando la línea pertenece a este
comprobante**: con un `item_origen_id` ajeno (FR-003) el modo cae al agregado y va el texto de
siempre — nombrar el importe de una línea de otro comprobante sería filtrar un dato ajeno.

## La pantalla (FR-006)

`itemsDisponibles()` **ya devuelve** `pendiente` y `precio` por fila. Falta sólo que el selector los
muestre cuando hay más de una línea del mismo producto.

Sin eso, la compra 2478 ofrece tres renglones que dicen "99999" y nada más.

## Qué NO se toca

- `pendiente()` — sigue siendo el fallback agregado.
- `pendienteDeLinea()` y `productoEnModoPorLinea()` — ya funcionan; se verificó en producción.
- El guardado y el frontend — ya mandan y persisten `item_origen_id`.
- Ventas — fuera de alcance por decisión del usuario.

## Orden de trabajo

1. Test que reproduce la compra 2478 (3 líneas, una negativa, una ya ajustada). **Tiene que fallar
   antes del fix.**
2. `topeDelRenglon()` con sus casos: con línea, sin línea, con línea ajena.
3. Los dos requests pasan a usarlo.
4. Mensaje de error por línea.
5. Selector con importe y pendiente.
6. Verificación contra la compra 2478 real, en la copia local.

## Riesgos

| Riesgo | Mitigación |
|---|---|
| **Debilitar el tope y permitir notas por más de lo facturado** | SC-002 y FR-009: el test del rechazo pesa tanto como el de la aceptación |
| Un `item_origen_id` de otro comprobante saltea el tope | FR-003: cae al agregado, que es más restrictivo |
| Romper comprobantes sin producto repetido | FR-008: con una línea por producto, los dos modos dan igual |
| Divergir de nuevo entre pantalla y validación | La decisión del modo queda en un solo método del servicio |

## Verificación

El caso real está en producción y se puede reproducir sobre la copia local: compra **2478**, nota
**883** ya emitida sobre la línea **12023**, línea **12022** libre por $4.616.354.

Antes del fix la validación da 0. Después tiene que dar 1 para la 12022 y seguir dando 0 para la
12023.
