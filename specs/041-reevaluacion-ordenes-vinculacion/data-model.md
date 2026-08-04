# Data Model: Reevaluación automática de órdenes por vinculación tardía

No se agregan tablas, columnas ni migraciones nuevas. Esta feature sólo cambia el *momento* en
que se recalculan columnas ya existentes, reutilizando entidades y relaciones ya presentes en el
modelo de datos (`docs/modelo_datos.md`). No requiere actualización de ese documento (Principio I:
sólo se actualiza si se revela una entidad/campo/regla nueva, y no es el caso).

## Entidades existentes involucradas (sin cambios de esquema)

### `MercadoLibreOrden` (tabla `ml_ordenes`)
Campos relevantes para esta feature (ya existentes): `estado_conversion`, `motivo`,
`motivo_detalle`, `venta_id`, `cliente_nuevo`. Relación `items()` → `MercadoLibreOrdenItem`.

### `MercadoLibreOrdenItem` (tabla `ml_orden_items`)
Campos relevantes: `ml_item_id`, `sku_vendedor`, `producto_id`. Vínculo lógico (no FK) con
`MercadoLibrePublicacionProducto.ml_item_id`.

### `MercadoLibrePublicacionProducto` (tabla `ml_publicacion_producto`)
Campos relevantes: `ml_item_id`, `producto_id`. Es la entidad cuyos eventos `saved`/`deleted`
disparan el mecanismo evento-driven (FR-001).

### `TiendanubeOrden` (tabla `tn_ordenes`)
Análogo a `MercadoLibreOrden`: `estado_conversion`, `motivo`, `motivo_detalle`, `venta_id`.

### `TiendanubeOrdenItem` (tabla `tn_orden_items`)
Análogo a `MercadoLibreOrdenItem`: `variant_id`, `sku`, `producto_id`.

### `TiendanubeVarianteProducto`
Análogo a `MercadoLibrePublicacionProducto`: `variant_id`, `producto_id`. Dispara el mecanismo
evento-driven del lado TiendaNube (FR-002).

## Transiciones de estado afectadas (ya definidas por `EstadoConversion`/`MotivoRequiereAtencion`, sin cambios)

Esta feature no agrega estados ni motivos nuevos a los enums `EstadoConversion` /
`MotivoRequiereAtencion` (ML y TN). Sólo agrega dos disparadores adicionales (evento-driven,
on-view) para las mismas transiciones que hoy sólo dispara la sincronización o la conversión
manual:

```
requiere_atencion (publicacion_sin_vincular | variante_sin_vincular)
        │
        │  se crea/edita la vinculación del ml_item_id / variant_id referenciado (FR-001/FR-002)
        │  o el usuario abre el listado de pendientes del canal (FR-006/FR-007)
        ▼
   ReevaluadorOrdenes::reevaluar(orden)
        │
        ├─ sigue faltando algo más (ej. cliente_ambiguo) → requiere_atencion (otro motivo)   [FR-005 caso 2]
        └─ nada pendiente → lista
                │
                │  si canal.creacion_automatica === true (FR-004)
                ▼
           ConversorOrdenAVenta::convertir(..., automatica: true)  [reuso sin cambios]
                │
                ├─ ok → convertida (venta_id seteado)
                └─ error inesperado → requiere_atencion (motivo: error_conversion)
```

Caso de desvinculación (Edge Cases del spec, FR-010): si el evento es `deleted` sobre la
vinculación, una orden que estaba `lista` porque esa publicación/variante tenía vínculo debe
volver a evaluarse igual — `ReevaluadorOrdenes` no distingue "por qué" se disparó, siempre corre
la misma evaluación completa contra el estado actual de la vinculación (que en este caso ya no
existe), así que el evaluador la vuelve a marcar `requiere_atencion` con el motivo que
corresponda. Para que este caso quede cubierto, la query de "órdenes afectadas" que arma el
Observer (ver R4 en research.md) incluye tanto `requiere_atencion` como `lista` (no sólo
`requiere_atencion`) entre las órdenes no convertidas — así una orden `lista` que pierde su
vinculación por un `deleted` sí es recapturada y puede volver a `requiere_atencion`.

## Servicio nuevo (sin persistencia propia)

### `ReevaluadorOrdenes` (uno por canal: `App\Services\MercadoLibre\ReevaluadorOrdenes`, `App\Services\Tiendanube\ReevaluadorOrdenes`)

No es una entidad de datos — es un servicio de dominio sin estado propio. Su contrato se detalla
en `contracts/reevaluador-ordenes.md`.
