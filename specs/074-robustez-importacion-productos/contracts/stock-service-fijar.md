# Contrato: `StockService::fijar()`

**Feature**: 074-robustez-importacion-productos | Cubre FR-001 a FR-005

Operación nueva del servicio de stock: **fijar la cantidad de un producto en un depósito a un valor
absoluto**, resolviendo lectura, cálculo del delta y escritura del movimiento de forma atómica.

---

## Firma

```php
namespace App\Services\Stock;

public function fijar(
    Producto $producto,
    ?ProductoVariante $variante,
    Deposito $deposito,
    float $cantidadDeseada,
    ?string $descripcion = null,
    ?User $usuario = null,
): float
```

**Devuelve**: la cantidad resultante en ese depósito para esa variante (que, salvo carrera resuelta a
favor de otra operación posterior, es `$cantidadDeseada`).

## Semántica

1. Abre una única `DB::transaction()`.
2. Dentro de ella, obtiene la fila de `stocks` de `(producto_id, variante_id, deposito_id)` **con
   `lockForUpdate()`**.
3. Calcula `diferencia = cantidadDeseada - cantidadActual` con la cantidad leída **bajo ese lock**.
4. Si `diferencia == 0` → **no escribe nada** (ni `stocks` ni `movimientos_stock`) y devuelve la cantidad
   actual.
5. Si `diferencia != 0` → actualiza `stocks.cantidad` y crea un `MovimientoStock` con
   `tipo = 'ajuste'`, `cantidad = diferencia` (con signo), la `descripcion` recibida, `fecha = now()` y
   `usuario_id` del usuario recibido.
6. Commit. El lock se libera al cerrar la transacción.

**El invariante central**: entre el paso 2 y el paso 5 ninguna otra transacción puede modificar esa fila
de `stocks`. Ese es exactamente el invariante que hoy falta y el que esta operación existe para
garantizar.

## Precondiciones

- `$producto` controla stock (`controlaStock() === true`). El llamador es responsable de no invocar la
  operación para servicios; el servicio no lo revalida (coherente con `ajustar()`).
- `$deposito` existe.

## Postcondiciones

- `stocks.cantidad` para esa clave es igual a `$cantidadDeseada`, salvo que otra operación haya commiteado
  después de esta transacción (en cuyo caso el valor refleja ambas operaciones aplicadas en secuencia).
- La suma de `movimientos_stock.cantidad` del histórico de esa clave sigue reconciliando con
  `stocks.cantidad`.
- Se creó **exactamente un** `MovimientoStock`, o **ninguno** si no había diferencia.

## Casos borde

| Caso | Comportamiento |
|---|---|
| No existe fila de `stocks` todavía | Se crea, tomando la cantidad actual como `0`. Hereda de `ajustar()` la limitación conocida: con la fila inexistente no hay nada que bloquear; la unicidad de `(producto_id, variante_id, deposito_id)` protege contra la doble inserción. |
| `cantidadDeseada` negativa | Permitido, igual que `ajustar()` (research D7 de la spec de stock original: un ajuste puede dejar el stock negativo). |
| `cantidadDeseada` igual a la actual | No se escribe nada. Devuelve la cantidad sin cambios. |
| Operación concurrente sobre la misma clave | Una de las dos transacciones espera al lock de la otra. Ninguna se pierde: el resultado equivale a una ejecución secuencial. |
| Producto sin variantes | `$variante = null`, igual que hoy en el importador. |

## Relación con `ajustar()`

`ajustar()` **no se modifica ni se deprecia**: sigue siendo la operación correcta cuando el llamador ya
conoce el delta (ajuste manual desde la UI, NC/ND, reintegros). `fijar()` es la operación correcta cuando
el llamador conoce el **valor final deseado** y necesita que el delta se derive de forma segura.

Regla para el futuro: **cualquier llamador que hoy combine `disponibilidad()` + `ajustar()` para llegar a
un valor absoluto tiene el mismo bug y debe migrar a `fijar()`.**

## Uso desde el importador

`ImportadorFilas::actualizarProducto()` reemplaza:

```php
$actual = $this->stockService->disponibilidad($producto, null, $deposito);
$diferencia = $cantidadDeseada - $actual;
if ($diferencia === 0.0) { continue; }
$this->stockService->ajustar($producto, null, $deposito, $diferencia, 'Ajuste (importación)', $usuario);
```

por:

```php
$this->stockService->fijar($producto, null, $deposito, $cantidadDeseada, 'Ajuste (importación)', $usuario);
```

El `continue` por diferencia cero deja de ser responsabilidad del importador: pasa a estar garantizado
por el paso 4 del contrato.

`crearProducto()` (alta) **no cambia**: ahí el producto es nuevo, el stock parte de cero, no hay lectura
previa y por lo tanto no hay carrera. Sigue usando `ajustar()` con
`'Registro inicial (importación)'`.

## Criterios de verificación

- **CV-1**: fijar 50 sobre un stock de 10 deja la cantidad en 50 y crea un movimiento de `+40`.
- **CV-2**: fijar 10 sobre un stock de 10 no crea ningún movimiento.
- **CV-3**: fijar 5 sobre un stock de 10 crea un movimiento de `-5`.
- **CV-4**: con una operación concurrente sobre la misma clave, la suma del histórico sigue
  reconciliando con `stocks.cantidad` y ambos movimientos existen (ninguno se perdió).
- **CV-5**: el movimiento generado conserva `tipo = 'ajuste'`, la descripción exacta
  `'Ajuste (importación)'` y el `usuario_id` recibido.
