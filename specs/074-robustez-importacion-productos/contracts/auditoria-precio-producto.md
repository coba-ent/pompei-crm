# Contrato: auditoría de cambios de precio por lista

**Feature**: 074-robustez-importacion-productos | Cubre FR-006 a FR-014

Todo cambio de precio de un producto en una lista, hecho a través del modelo, genera un evento inmutable
en `logs_auditoria`.

---

## 1. Punto de captura

`App\Observers\PrecioProductoObserver`, ya registrado en `AppServiceProvider`. Se extiende el hook
`saved()` existente y se agrega `deleted()`.

**Por qué acá**: es el único punto por el que pasan las escrituras de `precios_producto` hechas vía
modelo, sin importar el camino que las originó. Cubre en una sola implementación los cuatro orígenes de
FR-008/FR-009.

**Independencia de ramas**: la auditoría es una rama nueva del observer, **completamente independiente**
de las ramas de Mercado Libre y Tiendanube que ya viven ahí. Un fallo en una rama no debe impedir que las
otras corran (FR-017: la sincronización existente sigue funcionando igual).

## 2. Mapeo de eventos

| Evento Eloquent | Condición | `tipo_accion` | Precio anterior | `total` |
|---|---|---|---|---|
| `saved` | `wasRecentlyCreated === true` | `creo` | *(ninguno)* | precio nuevo |
| `saved` | `!wasRecentlyCreated && wasChanged('precio')` | `modifico` | `getOriginal('precio')` | precio nuevo |
| `saved` | `!wasChanged('precio')` | *(no se registra nada)* | — | — |
| `deleted` | siempre | `elimino` | `precio` del modelo borrado | `null` |

**Comparación de "cambió"**: sobre el valor normalizado a 2 decimales (`precios_producto.precio` es
`decimal(14,2)`). `100` y `100.00` **no** cuentan como cambio — sin esto, cada reimportación de una
planilla sin modificaciones generaría miles de eventos espurios y violaría FR-010/SC-004.

> **Por qué `getOriginal()` funciona dentro de `saved()` (no lo "corrijas"):** en Laravel,
> `Model::finishSave()` dispara el evento `saved` **antes** de llamar a `syncOriginal()`. Por eso, dentro
> del hook, `getOriginal('precio')` todavía devuelve el valor **previo** al guardado y `wasChanged()`
> refleja el cambio recién hecho. Es correcto y deliberado. Si alguien "arregla" esto leyendo el valor
> anterior con una consulta extra, agrega una query por evento sin ninguna necesidad; si lo mueve a
> `updated()`/`saving()`, cambia la semántica. Conviene dejar el comentario en el código.

## 3. Origen del cambio

Contexto explícito `App\Support\OrigenCambioPrecio`, declarado por cada punto de entrada y leído por el
observer.

| Constante | Rótulo en `detalle` | Punto de entrada |
|---|---|---|
| `IMPORTACION` | `importación` | `ImportadorFilas` (alta y actualización) |
| `MANUAL` | `edición manual` | `ProductoController::store()` / `update()` → `sincronizarPrecios()` |
| `EDICION_MASIVA` | `edición masiva` | `ProductoController::accionAjustarPrecios()` |
| `COPIA` | `copia de producto` | `ProductoController::copia()` |
| `DESCONOCIDO` | `origen no identificado` | **valor por defecto** |

API mínima:

```php
OrigenCambioPrecio::durante(string $origen, callable $fn): mixed  // set + restore garantizado
OrigenCambioPrecio::actual(): string                              // DESCONOCIDO si nadie lo declaró
```

`durante()` debe restaurar el origen previo incluso si el callable lanza excepción (bloque `finally`),
para no contaminar el resto de la request.

**El default es deliberado**: un camino de escritura nuevo que olvide declarar su origen igual queda
auditado, con rótulo `origen no identificado`. Falla hacia el lado seguro — se pierde precisión del
rótulo, nunca el registro.

## 4. Forma del registro

Ver [data-model.md §2](../data-model.md) para la tabla completa de campos. Resumen:

- `tipo_operacion` = `precio_producto`
- `entidad_tipo` = `App\Models\Producto`, `entidad_id` = `producto_id`
- `detalle` = `"{Producto} — {Lista}: {anterior} → {nuevo} ({origen})"`, con `sin precio` cuando falta un
  extremo, recortado a 255 caracteres sacrificando el nombre del producto y nunca los importes
- `total` = precio nuevo (`null` en borrados)
- `origen_sistema` = `null` (reservado para acciones sin usuario humano; el origen del cambio de precio
  va en `detalle`)

## 5. Garantías no funcionales

| Garantía | Requisito |
|---|---|
| **Nunca aborta la operación** | El registro va envuelto en `try/catch`; un fallo se loguea en `storage/logs` y la ejecución continúa. Hereda el contrato ya vigente de `AuditoriaService::registrarEvento()`. | FR-012 |
| **No altera los datos auditados** | La auditoría es escritura paralela; no modifica el precio guardado ni el retorno de la operación. | FR-014 |
| **Inmutable** | Sólo INSERT. No se expone UPDATE ni DELETE desde la aplicación. | FR-013 |
| **No rompe las integraciones** | Las ramas ML/Tiendanube del observer siguen ejecutándose igual. | FR-017 |

## 6. Registro en lote durante la importación

`AuditoriaService` gana un modo buffer para absorber el volumen de una importación (SC-005):

```php
AuditoriaService::iniciarBuffer(): void   // los eventos se acumulan en memoria
AuditoriaService::vaciarBuffer(): void    // los persiste con un INSERT múltiple
```

Reglas:

- **Sólo el importador** activa el buffer, alrededor de cada tanda. El resto de la aplicación sigue
  escribiendo evento por evento, sin cambios de comportamiento.
- Vaciado automático cada **200 eventos** además del vaciado explícito al terminar la tanda, para acotar
  la ventana de pérdida.
- El vaciado hereda el contrato de "nunca lanza": un fallo se loguea y se descarta el buffer.
- **Ventana de pérdida aceptada**: si el proceso muere en medio de una tanda, los eventos todavía
  buffereados se pierden aunque los precios sí se hayan guardado. Es coherente con el principio de spec
  054 — la auditoría documenta, no gatea.

## 7. Consulta

Se agrega la entrada `'precio_producto' => 'Precio de producto'` a
`AuditoriaController::LABELS_OPERACION`. Esa constante alimenta simultáneamente el `<select>` de filtro
de la vista y la columna "Operación" del DataTable, así que **la vista no se toca** (FR-011).

`AuditoriaController::detalle()` resuelve el modal con un `match` sobre `entidad_tipo` que tiene
`default => null`: los eventos de precio muestran el texto de `detalle` sin panel extendido, sin
necesidad de cambios.

## 8. Criterios de verificación

- **CV-1**: importar una planilla que sube el precio de una lista genera un evento `modifico` con el
  precio anterior, el nuevo y el rótulo `importación`.
- **CV-2**: editar el precio desde la ficha del producto genera un evento con rótulo `edición manual`.
- **CV-3**: aplicar un aumento porcentual con la edición masiva genera **un evento por cada precio
  efectivamente modificado**, con rótulo `edición masiva`.
- **CV-4**: guardar el producto quitando una lista de precios genera un evento `elimino` con el valor que
  tenía.
- **CV-5**: reimportar la misma planilla sin cambios **no genera ningún evento** (FR-010 / SC-004).
- **CV-6**: asignar precio a una lista que no tenía genera un evento `creo` sin precio anterior.
- **CV-7**: con la escritura de auditoría forzada a fallar, la importación y el guardado del producto
  terminan igual de bien y el precio queda correctamente guardado (FR-012).
- **CV-8**: los eventos aparecen en la pantalla de Auditoría y se pueden filtrar por el tipo de operación
  nuevo.
- **CV-9**: un cambio de precio en la lista configurada para Mercado Libre/Tiendanube sigue disparando su
  sincronización (FR-017).
