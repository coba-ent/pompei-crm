# Quickstart — Validación end-to-end

**Feature**: `024-tiendanube-migracion-rest`

Sin ambiente de prueba/sandbox de Tiendanube disponible (spec.md Assumptions) — validar contra la cuenta
real conectada vía la Application REST de spec 022, ya en producción.

## Prerrequisitos

- Conexión REST activa: `Configuración → Tiendanube` muestra el apartado REST (spec 022) en "Conectada".
- Al menos un producto del CRM con `id` conocido (ej. `9006`) y su variante correspondiente en Tiendanube
  con ese mismo valor cargado como SKU (sin necesidad de que haya vendido nunca).
- Migración de datos de configuración de negocio ya corrida (`tn_conexion_rest` tiene `deposito_id`,
  `lista_precio_id`, etc. poblados con los mismos valores que tenía `tn_configuracion` antes de la
  migración).

## Historia 1 — Vinculación por catálogo en vivo

1. En Tiendanube: confirmar que la variante del producto de prueba tiene el SKU `9006` (o el `id` del
   producto elegido) cargado.
2. En el CRM: `Ingresos → Tiendanube → Vinculaciones` → botón "Vincular automáticamente".
3. **Esperado**: la corrida recorre el catálogo completo (puede haber más de una página), y al finalizar
   el resumen indica al menos 1 vinculada. La fila aparece en el listado con el producto correcto.
4. Repetir el paso 2 sin cambiar nada: el resumen debe mostrar 0 vinculaciones nuevas (el vínculo ya
   existente no se toca, spec.md Edge Cases) — confirma SC-004.
5. Cambiar el SKU de esa variante en Tiendanube por uno que no matchea ningún producto, vincular otra
   variante distinta, correr de nuevo: confirmar que la variante sin match queda en `detalle_fallidas` con
   motivo `producto_no_encontrado`.

## Historia 2 — Órdenes, stock y precio sobre REST

1. Confirmar en `Configuración → Tiendanube` que **sólo** la conexión REST está activa (desconectar
   manualmente la MCP si todavía sigue conectada en paralelo durante la validación).
2. `Ingresos → Tiendanube` → botón "Sincronizar ahora": confirmar que trae los mismos pedidos que traía
   antes de la migración (comparar cantidad/fechas contra lo esperado).
3. Generar un movimiento de stock sobre un producto vinculado (ej. un ajuste manual) y esperar al próximo
   minuto del cronjob `tiendanube:sincronizar-stock` (o correrlo manualmente con `--forzar`): confirmar en
   Tiendanube que el stock de la variante se actualizó.
4. Cambiar el precio del producto vinculado en la Lista de Precios configurada: confirmar que el precio se
   actualiza en Tiendanube (evento `PrecioProductoObserver`, sin esperar ningún cronjob).
5. Revisar `tn_rest_operaciones_log`: debe mostrar las operaciones de negocio (`orders`, `products`,
   `variants`) junto a las de conexión, sin usar `tn_operaciones_log`.

## Historia 3 — Retiro del MCP (sólo después de validar 1 y 2 en producción durante un período razonable)

1. Confirmar que no queda ningún consumidor de negocio apuntando a `ClienteTiendanube` (búsqueda en código).
2. Correr la migración que elimina `tn_configuracion`/`tn_operaciones_log`.
3. `Configuración → Tiendanube`: confirmar que sólo se ve el apartado REST.
4. Repetir los pasos 2-4 de la Historia 2: todo sigue funcionando exactamente igual.

## Reversión

Si algo falla durante la Historia 1 o 2, no hay retiro que revertir todavía (Historia 3 es el último paso,
condicionado explícitamente a que las anteriores ya estén validadas — spec.md Assumptions). Revertir es
simplemente corregir el código de la migración sin tocar `tn_configuracion`/MCP, que sigue intacto como
referencia de comportamiento hasta ese punto.
