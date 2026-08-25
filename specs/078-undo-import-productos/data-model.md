# Data Model: Deshacer Import de Productos

## `importacion_corridas`

Una fila por cada corrida de Paso 3 confirmada en la solapa Productos & Servicios (una corrida
puede abarcar varias tandas/requests de 1.000 filas — todas comparten el mismo `import_run`,
creado en la primera tanda y actualizado en cada tanda siguiente).

| Campo | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| entidad | string | siempre `productos` en esta spec; columna presente por si se extiende a Clientes/Proveedores más adelante |
| usuario_id | FK → usuarios, nullable | quién ejecutó el import |
| archivo_original | string | nombre del archivo subido (Paso 1) |
| confirmado_en | datetime | momento en que arrancó el Paso 3 (primera tanda) |
| deshacer_disponible_hasta | datetime | `confirmado_en + 48h`, calculado una sola vez (research.md R6) |
| filas_creadas | unsignedInteger, default 0 | acumulado a través de todas las tandas |
| filas_actualizadas | unsignedInteger, default 0 | ídem |
| filas_fallidas | unsignedInteger, default 0 | ídem — informativo, no participa del undo |
| deshecho_en | datetime, nullable | null = corrida vigente/no deshecha |
| deshecho_por_id | FK → usuarios, nullable | quién ejecutó el undo |
| filas_revertidas | unsignedInteger, nullable | resultado del undo, sólo si `deshecho_en` no es null |
| filas_no_revertidas | unsignedInteger, nullable | ídem |

**Estado derivado** (no columna, calculado): `vigente` (deshecho_en null y now() <
deshacer_disponible_hasta) / `deshecho` (deshecho_en no null) / `vencido` (deshecho_en null y
now() >= deshacer_disponible_hasta).

## `importacion_filas_snapshot`

Una fila por cada producto creado o actualizado por una corrida — no se generan filas para
productos fallidos (nada que revertir) ni para productos no tocados.

| Campo | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| importacion_corrida_id | FK → importacion_corridas, cascade on delete | |
| producto_id | FK → productos, nullable | nulo sólo si el undo ya eliminó físicamente... **no aplica** (siempre soft-delete, así que siempre queda un id válido); nullable por consistencia con `nullOnDelete` si algún día se permite borrado físico |
| modo | enum(`alta`,`actualizacion`) | qué hizo el import con esta fila |
| existia | boolean | `false` si `modo = alta`; `true` si `modo = actualizacion` |
| estado_anterior | json, nullable | snapshot completo de `productos` (atributos), null si `existia = false` |
| precios_anteriores | json, nullable | `[{lista_precio_id, precio}]` vigentes antes de esta fila, null si `existia = false` |
| stock_anterior | json, nullable | `[{deposito_id, cantidad, ultimo_movimiento_stock_id}]` vigente antes de esta fila (ver research.md R4 para `ultimo_movimiento_stock_id`), null si `existia = false` |
| numero_fila | unsignedInteger | número de fila del archivo original (para trazar el resumen del undo contra el resumen del import) |
| estado_undo | enum(`pendiente`,`revertida`,`no_revertida`), default `pendiente` | actualizado al ejecutar el undo |
| motivo_no_revertida | string, nullable | sólo si `estado_undo = no_revertida` |

**Índice**: `(importacion_corrida_id, producto_id)` — para localizar rápido, al deshacer, si un
producto ya fue tocado por una corrida más reciente (research.md / FR-016).

## Relaciones con entidades existentes

- **Producto** (`docs/modelo_datos.md` §`productos`): entidad objetivo del snapshot/undo. Sin
  columnas nuevas en `productos`.
- **MovimientoStock**: el undo de stock genera nuevas filas `tipo = ajuste`, descripción `Ajuste
  (deshacer import)`, mismo mecanismo que el import (`StockService::fijar()`).
- **LogAuditoria** (spec 054/074, `docs/modelo_datos.md` §`logs_auditoria`): el undo de precio
  genera eventos "Precio de producto" con origen `"Deshacer import"`, análogo al origen
  `"Importación"` que ya existe.

## Reglas de integridad / invariantes

- Una `importacion_corrida` con `deshecho_en` no null nunca vuelve a `deshecho_en = null` (no hay
  "rehacer" en esta spec).
- `filas_revertidas + filas_no_revertidas` (cuando la corrida fue deshecha) debe ser igual a
  `filas_creadas + filas_actualizadas` de esa misma corrida.
- Un `importacion_filas_snapshot` con `estado_undo != pendiente` es inmutable (el undo no se
  reintenta fila por fila una vez resuelto; para volver a intentar una fila que quedó
  `no_revertida` habría que corregirla a mano, fuera de alcance de esta spec).
