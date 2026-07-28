# Data Model: Sincronización de stock del CRM hacia Mercado Libre

**Spec**: [../spec.md](../spec.md) · **Plan**: [../plan.md](../plan.md) · **Research**: [../research.md](../research.md)

No se crean tablas nuevas. Se extienden dos tablas ya existentes desde la spec 012
(`docs/modelo_datos.md §10`).

## `ml_publicacion_producto` (columnas nuevas)

Vínculo 1:1 publicación↔producto, ya existente. Columnas nuevas — estado de sincronización de stock de
**este** vínculo:

| Campo | Tipo | Notas |
|---|---|---|
| `stock_pendiente` | boolean, default `false` | `true` cuando hubo un movimiento de stock elegible sin empujar todavía a Mercado Libre (FR-001/FR-003). Lo pone en `true` `MovimientoStockObserver`; lo vuelve a `false` `SincronizadorStock` tras un envío exitoso. |
| `stock_sincronizado_en` | timestamp, nullable | Fecha del último envío **exitoso** (FR-017). |
| `stock_error` | string(255), nullable | Motivo del último rechazo (FR-014). Se limpia (`null`) en el siguiente envío exitoso. |
| `stock_error_en` | timestamp, nullable | Fecha de ese rechazo. |

**Invariantes**:
- `stock_pendiente = false` y `stock_error` no nulo es un estado **inválido** en la práctica de negocio
  (un error deja el vínculo pendiente, FR-014) pero no se fuerza a nivel de base — es responsabilidad de
  `SincronizadorStock` no producirlo.
- No se agrega ningún índice nuevo: las consultas (`where('stock_pendiente', true)`) operan sobre un
  volumen de decenas de filas (spec 012 §Scale/Scope), sin necesidad de índice dedicado.
- Eliminar el vínculo (ya soportado por FR-026 de la spec 012) elimina estas columnas junto con la fila;
  no hay nada que reintegrar ni limpiar aparte.

## `ml_configuracion` (columnas nuevas)

Registro único, ya existente. Columnas nuevas — estado de la corrida programada de stock, análogas a las
ya existentes para la de órdenes (`ultima_sync_en`/`ultima_sync_resultado`):

| Campo | Tipo | Notas |
|---|---|---|
| `stock_ultima_sync_en` | timestamp, nullable | Cuándo corrió por última vez `SincronizadorStock` (éxito o no). Contra esta marca se compara `frecuencia_sync_minutos` (mismo campo ya existente, reutilizado — Clarifications Q3). |
| `stock_ultima_sync_resultado` | string(255), nullable | Texto legible del resultado de la última corrida (mismo patrón que `ultima_sync_resultado`), para mostrar en la pantalla de configuración (FR-019). |

**No se agrega** ninguna columna de "activar/desactivar" esta sincronización: sigue el mismo apagado que
ya gobierna toda la integración (función avanzada "Mercado Libre" + modo sólo lectura, spec 011),
coherente con que el push de stock es, igual que la sincronización de órdenes, parte del mismo alcance
de la integración — no una función independiente.

## `movimientos_stock` — sin cambios de esquema, nuevo consumidor

No se agrega ninguna columna. Esta spec **lee** `movimientos_stock` (vía el Observer, R1) usando las
columnas ya existentes:

| Campo usado | Para qué |
|---|---|
| `producto_id` | Resolver el vínculo `ml_publicacion_producto` afectado |
| `deposito_id` | Filtrar sólo movimientos del depósito configurado para Mercado Libre |
| `origen_type` / `origen_id` | Exclusión de bucle (R2): si es una `Venta` con `origen = 'mercadolibre'`, no dispara |

## Ciclo de vida de `stock_pendiente` (por vínculo)

```text
(vínculo recién creado) ──► stock_pendiente = false, sin sincronizar aún
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
  cuyo rastro queda en `ml_operaciones_log` (spec 011, sin cambios de esquema) y en el propio estado del
  vínculo (arriba). Ver [research.md R7](../research.md).
