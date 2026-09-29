# La publicación que vendió también recibe el stock del CRM

**Spec**: 112 | **Fecha**: 2026-09-29 | **Estado**: listo para planificar

## El problema

El 28/09/2026 la publicación **MLA1808325052** (Botiquín tríptico, producto 27198) quedó
**ofreciendo 21 unidades cuando el CRM tenía 20**. No dio ningún error: el vínculo figuraba
sincronizado, sin pendientes y sin marca de intervención.

Lo detectó el chequeo de rutina comparando contra la API de Mercado Libre. Ningún indicador interno
lo denunciaba.

## Cómo se produce

Hacen falta **dos cosas a la vez**. Ninguna sola alcanza.

### 1. El stock que se empuja puede estar viejo

Las órdenes se importan **cada 5 minutos** (`ml_configuracion.frecuencia_sync_minutos`). Entre una
pasada y la siguiente, el CRM todavía no sabe de las ventas que ya ocurrieron allá.

### 2. La publicación que vendió queda excluida de futuras marcas

`MovimientoStockObserver::ramaMercadoLibre()` marca todas las publicaciones del producto **menos
aquellas por las que se vendió** (`whereNotIn`).

### La secuencia real del 28/09

| Hora | Qué pasó |
|---|---|
| 17:53:46 | Venta por **MLA818901919** (la otra publicación) |
| 18:10:59 | Otra venta por **MLA818901919** |
| 18:15:48 | Venta por **MLA1808325052** — ML descuenta de su lado |
| 18:19:03 | El cron le empuja **22** a MLA1808325052: el CRM aún no había importado las ventas |
| 18:24:03 | El CRM ya tiene 20 y se lo empuja a MLA818901919 |
| — | **MLA1808325052 quedó en 21 y nadie se lo corrigió** |

El **22 salió del propio CRM**, con datos viejos. Mercado Libre le restó 1 y quedó en 21. Como esa
publicación estaba excluida por haber vendido, no hubo segunda oportunidad.

**No hay ninguna contabilidad paralela de Mercado Libre**: hay un número nuestro desactualizado que
nadie volvió a corregir.

## Por qué la exclusión existe (y por qué ya no aplica)

Esta regla tiene historia, y conviene conocerla antes de tocarla.

**Spec 013 (agosto) — nació como FR-002, "evitar bucles".** La pregunta original fue: *"¿cómo se
evita que una orden de Mercado Libre que descuenta stock local dispare un push de vuelta hacia
Mercado Libre?"*. La respuesta fue saltear **el producto entero** cuando el movimiento venía de una
orden de ML.

**Spec 036 (03/08) — vinculación múltiple.** Se descubrió que el CRM sólo permitía una publicación
por producto: 72 casos mal vinculados, con riesgo de sobreventa. Se habilitó vincular varias.

**Commit `4b7a0e66` (11/08) — primera corrección de esto mismo.** Se detectó que saltear el producto
entero dejaba **las otras publicaciones desfasadas para siempre**. Se cambió "saltear todo" por el
`whereNotIn` actual: marcar todas **menos la que vendió**.

Esta spec es **el escalón que quedó**: se arregló para las otras publicaciones, pero la que vendió
sigue excluida.

### El motivo original ya no se sostiene

**No existe el bucle que FR-002 quería evitar.** Un bucle requeriría que empujar stock generara otro
movimiento de stock. `SincronizadorStock::procesarVinculos()` hace un `PUT` a Mercado Libre y
escribe **sólo columnas de control del vínculo** (`stock_pendiente`, `stock_error`,
`ultimo_stock_publicado`, `stock_sincronizado_en`). **No crea ningún `MovimientoStock`.** Sin
movimiento nuevo, el observer no vuelve a dispararse.

La segunda razón que daba la spec 013 —*"en el peor caso, una fuente de inconsistencia si llegara
desfasada en el tiempo"*— es exactamente lo que ocurrió, pero **al revés**: la inconsistencia la
produjo **no** empujar.

## Por qué es seguro empujar de más

`procesarVinculos()` calcula la cantidad **dentro del loop**, en el momento del envío:

```php
$cantidad = (int) max(0, $this->stock->disponibilidad($vinculo->producto, null, $depositoMl));
```

No usa un valor guardado al marcar. Entonces marcar de más **nunca publica un número viejo**:
publica el que el CRM tiene en ese instante. Un PUT redundante lleva siempre el dato correcto.

Esto ya está declarado como principio en la spec 013, **FR-003**: consolidar y enviar el stock
actual *"aunque el valor final coincida con el último enviado... es más simple y más seguro que
arriesgar una divergencia silenciosa por una comparación de más"*. Esta spec aplica ese mismo
criterio al caso que quedó afuera.

## Cuándo se corrige solo hoy (y cuándo no)

La exclusión aplica **sólo** a movimientos originados en órdenes de Mercado Libre, y sólo a la
publicación que vendió:

| Evento | ¿Corrige el desfase? |
|---|---|
| Venta manual, compra, ajuste, nota de crédito | **Sí** |
| Venta de ML por **otra** publicación del mismo producto | **Sí** |
| Venta de ML por **esa misma** publicación | **No** |

El desfase dura **hasta el próximo movimiento del producto**, no para siempre. Queda clavado sólo
cuando la última actividad fue una venta por esa misma publicación y después no pasó nada más.

## Alcance medido (29/09/2026)

- **75 productos** con más de una publicación, sumando **172 publicaciones**
- **203 ventas** de Mercado Libre en 30 días sobre esos productos
- En el chequeo de hoy, **1 sola publicación** efectivamente desfasada por esta causa

## Qué se quiere

Que ninguna publicación quede ofreciendo un stock distinto al del CRM porque el sistema decidió no
escribirle.

## Requisitos funcionales

- **FR-001** Un movimiento de stock marca como pendientes **todas** las publicaciones vinculadas al
  producto, **incluida** aquella por la que se vendió. **Reemplaza a FR-002 de la spec 013.**
- **FR-002** El stock que se publica es el del CRM al momento del envío, no el del momento de la
  marca. (Ya es así; queda fijado con un test.)
- **FR-003** Un envío redundante **no es un error**: es el comportamiento buscado, mismo criterio
  que FR-003 de la spec 013.
- **FR-004** Vale igual para **Tiendanube**: `ramaTiendanube()` tiene la misma exclusión.

### Lo que no cambia

- **FR-005** La cadena sigue igual: movimiento → observer → vínculo pendiente → cron → plataforma.
- **FR-006** El corte por depósito se conserva: un movimiento en un depósito distinto al configurado
  **no marca nada**.
- **FR-007** El depósito Full sigue siendo espejo de Mercado Libre.
- **FR-008** La frecuencia de importación de órdenes no se modifica.

## Criterios de éxito

- **SC-001** Tras una venta de Mercado Libre, **todas** las publicaciones del producto quedan
  `stock_pendiente = 1`, incluida la que vendió.
- **SC-002** Tras la corrida siguiente, las dos publicaciones de un producto multi-publicación
  muestran en Mercado Libre **el mismo número que el CRM**.
- **SC-003** Reproducir la secuencia del 28/09 no deja ninguna publicación desfasada.
- **SC-004** Los movimientos que hoy **ya** marcan bien (venta manual, compra, ajuste) siguen
  comportándose **idénticamente**.
- **SC-005** El volumen de escrituras sube a lo sumo **una llamada por venta de Mercado Libre**.

## Casos de borde

| Caso | Tratamiento |
|---|---|
| Producto con **una sola** publicación vendida por ML | Pasa a marcarse. Un PUT extra por venta |
| Producto con varias publicaciones | Todas marcadas, incluida la que vendió |
| Venta manual / compra / ajuste | Sin cambios: ya marcaba todas |
| Movimiento en depósito ≠ el de la integración | Sin cambios: corta antes (FR-006) |
| Publicación `under_review` | Sin cambios: el envío falla y queda registrado |
| Publicación Full sin `user_product_id` | Sin cambios: `procesarVinculos()` la saltea y limpia el pendiente |
| Producto eliminado | Sin cambios: se limpia el pendiente |

## Riesgo

**Es el punto más sensible del sistema.** El observer corre en **cada** movimiento de stock, y vive
dentro del guardado de ventas y compras: un error acá no rompe la sincronización, rompe **guardar
una venta**.

Mitigación: el cambio **quita** una condición, no agrega lógica. El camino que hoy funciona —ventas
manuales, compras, ajustes: la mayoría de los movimientos— queda **literalmente igual**, porque para
esos la exclusión ya devolvía `[]` y no filtraba nada.

**Sube el volumen de llamadas**: un PUT adicional por venta de Mercado Libre, sobre una API con
límite de rate. El cliente ya tiene backoff ante 429. Se mide antes y después.

**Hay 3 tests en verde que exigen el comportamiento actual.** No se borran: se reescriben afirmando
la regla nueva, con la justificación de por qué cambió. Son la documentación viva de esta decisión.

**Lo que esta spec NO resuelve**: que el stock empujado pueda estar desactualizado si el cron corre
entre la venta en Mercado Libre y su importación. Esta spec quita el segundo factor para que **la
próxima pasada siempre corrija**. El primero se atacaría con la mejora 7.y (publicar el stock por
evento), documentada y postergada.
