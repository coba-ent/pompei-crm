# Data Model: Sincronización de stock del CRM hacia Tiendanube

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

## Entidades no persistentes

- **Envío de stock**: no es una fila propia; es la operación de `SincronizadorStock` sobre un vínculo,
  cuyo rastro queda en `tn_operaciones_log` (spec 015, sin cambios de esquema) y en el propio estado del
  vínculo (arriba). Ver [research.md R7](../research.md).
