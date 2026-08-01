# Data Model: Conversión manual en lote de órdenes a Venta

Esta feature **no agrega columnas ni tablas nuevas**. Reutiliza por completo el modelo de datos ya
existente de `tn_ordenes` (Tiendanube) y `ml_ordenes` (MercadoLibre), documentado en
`docs/modelo_datos.md`.

## Entidades existentes reutilizadas

### `TiendanubeOrden` (tabla `tn_ordenes`)

Campos relevantes para esta feature (ya existentes, sin cambios):

| Campo | Tipo | Uso en esta feature |
|---|---|---|
| `tn_order_id` | string | Identificador de orden mostrado en el detalle de fallos del modal |
| `estado_conversion` | enum (`EstadoConversion`) | Filtro del lote: sólo se procesan las que están en `Lista` |
| `motivo` | enum (`MotivoRequiereAtencion`) | Se muestra (vía `etiqueta()`) en el detalle de fallos si la conversión no prosperó |
| `motivo_detalle` | string\|null | Texto explicativo adicional, se muestra junto al motivo |
| `venta_id` | FK a `ventas` | Se completa al convertir; usado para saber si ya está convertida |
| `convertida_por` | FK a `users` | Se setea con el usuario logueado que ejecutó el batch |
| `convertida_en` | timestamp | Se setea al momento de la conversión |

### `MercadoLibreOrden` (tabla `ml_ordenes`)

Mismos campos equivalentes: `ml_order_id`, `estado_conversion`, `motivo`, `motivo_detalle`,
`venta_id`, `convertida_por`, `convertida_en`.

## Estructura transitoria (no persistida): Resultado del lote

Devuelta por `ConversorOrdenAVenta::convertirTodasLasListas()` y expuesta tal cual por el endpoint
como JSON. No se persiste en ninguna tabla — vive sólo en memoria durante el request y se descarta
al responder.

```text
{
  ok: bool,
  mensaje: string,
  total: int,               // cantidad de órdenes que estaban en estado "Lista" al arrancar
  convertidas: int,         // cantidad que terminó con Venta creada
  fallidas: int,            // total - convertidas
  detalle_fallidas: [
    {
      orden: string,        // tn_order_id / ml_order_id, identificador visible al usuario
      motivo: string,       // etiqueta() del enum MotivoRequiereAtencion correspondiente
      motivo_detalle: string|null,
    },
    ...
  ]
}
```

## Reglas de validación / invariantes (heredadas, no nuevas)

- Sólo se procesan órdenes con `estado_conversion = Lista` — determinado por `EvaluadorConvertibilidad`
  (ya existente), no por esta feature.
- Cada conversión es atómica e independiente (transacción propia dentro de `convertir()`); una falla
  en una orden no revierte ni bloquea las demás.
- Una orden no puede terminar con dos `Venta` asociadas — garantizado por el candado por orden +
  índice único ya existentes en `convertir()`.
