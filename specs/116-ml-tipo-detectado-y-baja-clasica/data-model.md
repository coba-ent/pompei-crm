# Data Model: spec 116

## `ml_cambios_tipo_publicacion` (existente, spec 115): columna nueva

| Campo | Tipo | Notas |
|---|---|---|
| origen | string(20), not null, default `crm` | `crm` = cambio confirmado desde Vinculaciones ML (spec 115). `mercadolibre` = cambio hecho en Mercado Libre y detectado por el CRM: en la revisión de tipos (`usuario_id` null) o al confirmar un cambio que ML ya tenía hecho (`usuario_id` = quien confirmó). Indexada. |

Migración `2026_10_28_000001_add_origen_to_ml_cambios_tipo_publicacion`. Las filas existentes quedan en `crm`.

Constantes en el modelo `MercadoLibreCambioTipoPublicacion`: `ORIGEN_CRM`, `ORIGEN_MERCADOLIBRE`.

**Resultados posibles con origen `mercadolibre`**: `cambiado` (tipo actualizado y precio enviado),
`cambiado_precio_pendiente` (tipo actualizado, ML rechazó el precio: queda `precio_pendiente = 1` con
`precio_error`), `sin_cambios` (tipo actualizado, el precio del tipo nuevo ya era el publicado, o no hay
precio para publicar; el motivo lo dice).

## `ml_publicacion_producto` (existente): sin columnas nuevas

Transición de `listing_type_id` ante la revisión de tipos:

| Guardado | Vigente en ML | Qué pasa |
|---|---|---|
| `null` | cualquiera | Se guarda (primera clasificación, como hoy). Sin precio ni historial. |
| fuera de alcance (`free`, `gold_premium`…) o vigente fuera de alcance | cualquiera | Se guarda, como hoy. Sin precio ni historial. |
| igual al vigente | — | Solo se refresca `listing_type_sincronizado_en`, como hoy. |
| `gold_special` ↔ `gold_pro` (distinto) | el otro | **Cambio detectado**. Si hay corte o candado tomado: no se toca (se reintenta en la próxima revisión). Si no: `ProcesadorCambioTipoDetectado` actualiza tipo y fecha, publica el precio y registra en el historial. |

Los campos de logística (`logistic_type`, `inventory_id`, `user_product_id`, `logistica_sincronizada_en`)
se siguen actualizando en todos los casos.

## `ml_configuracion` (existente): sin columnas nuevas

- `tipo_publicacion_ultima_sync_en`: se sigue usando como marca de la última revisión. El freno cambia
  de 24 h fijas a `frecuencia_sync_minutos`.
- `frecuencia_sync_minutos`: ahora gobierna también la revisión de tipos.

## `retenciones_precio_ml` (existente)

Una retención abierta del vínculo queda `reemplazada` cuando el procesador publica el precio
(`enviarUno()` ya la cierra al aceptar). Si el precio del tipo nuevo ya era el publicado, el procesador
la cierra explícitamente.
