# Quickstart — Vinculación automática de Mercado Libre por catálogo en vivo

Guía de validación end-to-end tras implementar la spec. Requiere una cuenta de Mercado Libre conectada con
al menos una publicación activa o pausada.

## 1. Publicación que nunca vendió (caso central de esta corrección)

1. Crear un producto en el CRM (Base de Datos → Productos) forzando su `id` a coincidir con el SKU que le
   vas a poner a una publicación real de Mercado Libre que **nunca vendió** (sin ninguna orden sincronizada
   para ella).
2. En Mercado Libre, editar el SKU de esa publicación para que coincida con ese `id`.
3. Ir a Ingresos → Mercado Libre → Vinculaciones. Apretar "Vincular automáticamente".
4. Confirmar:
   - El resumen muestra esa publicación como vinculada, aunque nunca haya tenido una orden.
   - `ml_publicacion_producto` tiene la fila nueva con el `producto_id` correcto.
   - `ml_orden_items` de esa publicación sigue sin ninguna fila (o con `sku_vendedor` distinto/nulo) — no
     hizo falta ninguna orden para vincularla.

## 2. El SKU corregido en Mercado Libre se refleja en la próxima corrida

1. Con una publicación ya vista antes por el sistema (vinculada o no), cambiarle el SKU en Mercado Libre a
   un valor distinto.
2. Correr "Vincular automáticamente" de nuevo.
3. Confirmar que el resultado usa el SKU nuevo (si matchea un producto distinto, se vincula contra ese; si
   ya estaba vinculada de antes por otro SKU, el vínculo existente no se toca — FR-008).

## 3. Publicaciones pausadas

1. Pausar una publicación con un SKU que coincide con un producto sin vincular.
2. Correr "Vincular automáticamente". Confirmar que se vincula igual que si estuviera activa (spec.md
   Clarifications).

## 4. SKU duplicado entre dos publicaciones distintas

1. Poner el mismo SKU en dos publicaciones activas/pausadas distintas, ambas sin vínculo.
2. Correr "Vincular automáticamente". Confirmar que sólo una queda vinculada; la otra aparece con motivo
   "producto ya vinculado" (detalle "producto").

## 5. Caso de error: falla del catálogo en vivo

Difícil de forzar manualmente contra la cuenta real — cubierto por el test automatizado
(`Http::fake()` simulando una respuesta de error en medio del recorrido `scan`/multiget). Confirmar en el
test que la respuesta es `502` con `ok:false` y que no se crea ningún vínculo nuevo.

Detalle de contrato: [contracts/rutas-internas.md](./contracts/rutas-internas.md). Detalle de resolución
del SKU: [data-model.md](./data-model.md).
