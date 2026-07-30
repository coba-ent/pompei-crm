# Data Model: Sincronización de stock y precios del CRM hacia Tiendanube

**Spec**: [../spec.md](../spec.md) · **Plan**: [../plan.md](../plan.md) · **Research**: [../research.md](../research.md)

No se crean tablas nuevas. Se extienden dos tablas ya diseñadas por la spec 017
(`docs/modelo_datos.md §12`), la misma que esta feature depende de que exista en código antes o junto con
su propia implementación (ver plan.md, "Advertencia de secuencia de implementación").

## `tn_variante_producto` (columnas nuevas)

Vínculo 1:1 variante↔producto, ya existente (spec 017). Columnas nuevas:

| Campo | Tipo | Notas |
|---|---|---|
| `tn_product_id` | string(50) | Identificador del **producto** de Tiendanube al que pertenece la variante. **Necesario** porque el endpoint de actualización de stock cuelga del producto, no de la variante sola ([research.md R6](../research.md)). Se completa en el momento de crear el vínculo (FR-021/023 de la spec 017), a partir del dato ya disponible en el origen de la vinculación (línea de orden o catálogo de productos). |
| `stock_pendiente` | boolean, default `false` | `true` cuando hubo un movimiento de stock elegible sin empujar todavía a Tiendanube (FR-001/FR-003). Lo pone en `true` la rama Tiendanube de `MovimientoStockObserver`; lo vuelve a `false` `SincronizadorStock` tras un envío exitoso. |
| `stock_sincronizado_en` | timestamp, nullable | Fecha del último envío **exitoso** (FR-017). |
| `stock_error` | string(255), nullable | Motivo del último rechazo (FR-014). Se limpia (`null`) en el siguiente envío exitoso. |
| `stock_error_en` | timestamp, nullable | Fecha de ese rechazo. |

**Invariantes**:
- `stock_pendiente = false` y `stock_error` no nulo es un estado **inválido** en la práctica de negocio
  (un error deja el vínculo pendiente, FR-014) pero no se fuerza a nivel de base — es responsabilidad de
  `SincronizadorStock` no producirlo. Mismo criterio que la spec 013 dejó para `ml_publicacion_producto`.
- `tn_product_id` se completa siempre al crear el vínculo, nunca queda nulo para vínculos nuevos: no hay
  vínculos preexistentes que migrar (spec 017 sin datos en producción a la fecha de esta spec).
- No se agrega ningún índice nuevo: las consultas (`where('stock_pendiente', true)`) operan sobre un
  volumen de decenas de filas (spec 017 §Scale/Scope), sin necesidad de índice dedicado.
- Eliminar el vínculo (ya soportado por FR-026 de la spec 017) elimina estas columnas junto con la fila;
  no hay nada que reintegrar ni limpiar aparte.

## `tn_variante_producto` (columnas nuevas de precio — AMPLIACIÓN 30/07/2026)

Mismas cuatro columnas que stock (arriba), calcadas para precio — mismo patrón que `ml_publicacion_producto`
usó en la spec 016:

| Campo | Tipo | Notas |
|---|---|---|
| `precio_pendiente` | boolean, default `false` | `true` cuando hay un precio vigente en la Lista de Precios configurada para Tiendanube que todavía no se confirmó enviado (recién cambiado, envío anterior fallido, o vínculo creado después de un cambio de precio ya ocurrido, FR-027). |
| `precio_sincronizado_en` | timestamp, nullable | Fecha del último envío de precio **exitoso**. |
| `precio_error` | string(255), nullable | Motivo del último rechazo de Tiendanube al actualizar el precio (FR-031). Se limpia en el siguiente envío exitoso. |
| `precio_error_en` | timestamp, nullable | Fecha de ese rechazo. |

Migración: `add_precio_fields_to_tn_variante_producto_table`, `after('stock_error_en')` — mismas columnas
de stock y precio conviven en la misma tabla, en columnas separadas (no hay solapamiento: un vínculo puede
estar pendiente de stock, de precio, de ambos, o de ninguno, de forma completamente independiente).

**Scope nuevo** en `TiendanubeVarianteProducto`: `scopePendientesPrecio(Builder $query): Builder`
(`where('precio_pendiente', true)`), análogo a `scopePendientes()` (stock) ya agregado por esta misma spec.

**Estado derivado para la UI** (`precioEstado()` en `TiendanubeVinculacionController`, análogo a
`stockEstado()` ya agregado por esta spec): `precio_error` no vacío → "error"; si no, `precio_pendiente`
→ "pendiente"; si no → "sincronizado".

## `tn_configuracion` (columnas nuevas)

Registro único, ya existente (spec 015, extendido por la 017). Columnas nuevas — estado de la corrida
programada de stock, análogas a las ya existentes para la de órdenes (`ultima_sync_en`/
`ultima_sync_resultado`, spec 017):

| Campo | Tipo | Notas |
|---|---|---|
| `stock_ultima_sync_en` | timestamp, nullable | Cuándo corrió por última vez `SincronizadorStock` (éxito o no). Contra esta marca se compara `frecuencia_sync_minutos` (mismo campo ya existente, reutilizado). |
| `stock_ultima_sync_resultado` | string(255), nullable | Texto legible del resultado de la última corrida (mismo patrón que `ultima_sync_resultado`), para mostrar en la pantalla de configuración (FR-019). |

**No se agrega** ninguna columna de "activar/desactivar" esta sincronización: sigue el mismo apagado que
ya gobierna toda la integración (función avanzada "Tiendanube" + modo sólo lectura, spec 015), coherente
con que el push de stock es, igual que la sincronización de órdenes, parte del mismo alcance de la
integración — no una función independiente.

## `tn_configuracion` (columna nueva de precio — AMPLIACIÓN)

| Campo | Tipo | Notas |
|---|---|---|
| `lista_precio_id` | unsignedBigInteger, nullable, FK → `listas_precio.id`, `nullOnDelete()` | Lista de Precios que gestiona los precios de las variantes vinculadas de Tiendanube. Opcional: `null` = sin sincronización de precio. Sin reserva "por defecto del CRM" — mismo criterio que `categoria_venta_id` (spec 017). Sin validación de "activa" al usarse (research.md, mismo criterio que la spec 016 R9). |

**Sin columnas de "última sincronización" para precio** (mismo criterio que `ml_configuracion`, spec 016
R8): a diferencia de stock, no hay corrida programada cuyo resultado persistir acá — el estado vive
por-vínculo y la acción manual informa su resultado en el momento.

**Relación Eloquent nueva** en `TiendanubeConfiguracion`: `listaPrecio(): BelongsTo` (mismo patrón que
`deposito()`/`categoriaVenta()`/`cuentaTesoreria()` ya presentes en ese modelo, spec 017).

## `movimientos_stock` — sin cambios de esquema, nuevo consumidor

No se agrega ninguna columna. Esta spec **lee** `movimientos_stock` (vía la rama Tiendanube del Observer,
R1) usando las columnas ya existentes:

| Campo usado | Para qué |
|---|---|
| `producto_id` | Resolver el vínculo `tn_variante_producto` afectado |
| `deposito_id` | Filtrar sólo movimientos del depósito configurado para Tiendanube |
| `origen_type` / `origen_id` | Exclusión de bucle (R2): si es una `Venta` con `origen = 'tiendanube'`, no dispara |

## Ciclo de vida de `stock_pendiente` (por vínculo)

```text
(vínculo recién creado, con tn_product_id ya completo) ──► stock_pendiente = false, sin sincronizar aún
        │
        │ movimiento de stock elegible (FR-001/FR-002/FR-005)
        ▼
stock_pendiente = true ──► SincronizadorStock intenta el envío
        │                           │
        │                  éxito    │   rechazo definitivo (FR-014)
        ▼                           ▼
stock_pendiente = false    stock_pendiente sigue true
stock_sincronizado_en = now()   stock_error = motivo, stock_error_en = now()
stock_error = null                  │
                                     │ próxima corrida reintenta (FR-014, última viñeta)
                                     ▼
                              (vuelve al estado "pendiente, con error registrado" hasta que tenga éxito)
```

No hay transición a un estado "descartado": mientras el vínculo exista, un cambio pendiente siempre
termina en éxito o queda reintentándose (FR-014/FR-015) — nunca se pierde en silencio.

## Ciclo de vida de `precio_pendiente` / `precio_error*` (AMPLIACIÓN)

```text
[cambio de precio en la Lista de Precios configurada, producto vinculado a una variante de Tiendanube]
        │
        ▼
  SincronizadorPrecios::enviarUno($vinculo, $precio)
        │
        ▼
  precio_pendiente = true   (primer paso, incondicional — mismo criterio que la spec 016 R4)
        │
        ▼
  ¿bloqueado? (función desactivada / sólo lectura / conexión caída)
        │
   ┌────┴────┐
   │ sí       │ no
   ▼          ▼
corta acá:     update_stock_and_price({product_id, variant_id, price}) vía ClienteTiendanube
precio_pendiente        │
permanece true,    ┌────┴────┐
precio_error        │ éxito   │ rechazo de Tiendanube
sin tocar           ▼         ▼
              precio_pendiente = false   precio_pendiente permanece true
              precio_sincronizado_en     precio_error = motivo
                = now()                  precio_error_en = now()
              precio_error = null
              precio_error_en = null
```

Mismo invariante que la spec 016 estableció: un vínculo con `precio_error` no vacío es, por definición,
también `precio_pendiente = true`. El corte por bloqueo (kill-switch) no setea `precio_error`.

## Entidades no persistentes

- **Envío de stock**: no es una fila propia; es la operación de `SincronizadorStock` sobre un vínculo,
  cuyo rastro queda en `tn_operaciones_log` (spec 015, sin cambios de esquema) y en el propio estado del
  vínculo (arriba). Ver [research.md R7](../research.md).
- **Envío de precio**: no es una fila propia; es la operación de `SincronizadorPrecios` sobre un vínculo,
  registrada en `tn_operaciones_log` con `operacion = 'sincronizar_precio'`, `sentido = 'escritura'`. Ver
  [research.md R8-R10](../research.md).
- **`Venta`**: sin cambios. Esta ampliación no toca `venta.lista_precio_id` en ningún escenario de
  Tiendanube (FR-039/FR-040) — mismo criterio que la spec 016 fijó para Mercado Libre.
