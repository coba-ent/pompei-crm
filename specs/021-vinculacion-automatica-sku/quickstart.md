# Quickstart — Vinculación automática por SKU

Guía de validación end-to-end tras implementar la spec. Requiere una cuenta de Mercado Libre y una
conexión de Tiendanube activas (reales o de prueba) y al menos una orden sincronizada de cada canal.

## 1. Mercado Libre — vinculación automática

1. Crear un producto en el CRM (Base de Datos → Productos) forzando su `id` a coincidir con un
   `sku_vendedor` real visto en `ml_orden_items` (o sincronizar una orden real de Mercado Libre cuyo
   producto ya exista en el CRM con ese `id`).
2. Ir a Ingresos → Mercado Libre → Vinculaciones. Confirmar que **no** hay botón "Nueva vinculación" ni
   selector — sólo el botón "Vincular automáticamente" y la tabla de vínculos existentes.
3. Apretar "Vincular automáticamente". Confirmar:
   - El resumen muestra al menos 1 vinculada.
   - La publicación aparece en la tabla, sin recargar la página.
   - `ml_publicacion_producto` tiene la fila nueva con el `producto_id` correcto.
4. Apretar "Vincular automáticamente" de nuevo. Confirmar que el resumen no vuelve a crear esa misma
   vinculación (SC-004 — no sobrescribe).
5. Confirmar que "Editar" y "Eliminar" siguen disponibles por fila (clarificación de spec.md).

## 2. Tiendanube — importación desde el export nativo

1. Desde el panel real de Tiendanube (o un archivo de prueba con las mismas columnas: `Identificador de
   URL`, `Nombre`, …, `SKU`, …, separador `;`), exportar el listado de productos.
2. Ir a Ingresos → Tiendanube → Vinculaciones. Confirmar que el botón "Nueva vinculación" (selector
   manual) sigue estando, y que se agregó un botón/acción de importar.
3. Subir el archivo exportado. Confirmar:
   - El resumen distingue vinculadas de fallidas, con motivo por fila.
   - Las filas cuyo SKU coincide con `productos.codigo` (exacto o por el número inicial) y cuyo
     "Identificador de URL" existe en el catálogo en vivo de Tiendanube quedan vinculadas.
   - La tabla se actualiza sin recargar la página.
4. Reintentar la importación con el mismo archivo. Confirmar que todas las filas ya vinculadas la vez
   anterior ahora fallan por "ya vinculado", sin duplicar ni modificar el vínculo existente (SC-004).
5. Probar el selector manual de siempre (elegir una variante pendiente + un producto) y confirmar que
   sigue funcionando exactamente igual que antes de esta spec (SC-005).

## 3. Casos de error

- Subir un archivo vacío o con extensión no soportada a la importación de Tiendanube → 422, sin procesar
  ninguna fila.
- Subir un archivo sin las columnas `SKU` o `Identificador de URL` → 422.
- Producto inactivo en el CRM cuyo `id` matchea un SKU de Mercado Libre → se vincula igual (clarificación
  — no se excluyen inactivos para este canal).

Detalle de contrato de cada endpoint: [contracts/rutas-internas.md](./contracts/rutas-internas.md).
Detalle de resolución de SKU: [data-model.md](./data-model.md).
