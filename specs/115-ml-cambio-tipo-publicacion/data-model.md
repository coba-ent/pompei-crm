# Data Model: spec 115

## Tabla nueva `ml_cambios_tipo_publicacion` (historial de intentos)

| Columna | Tipo | Nota |
|---|---|---|
| id | bigint PK | |
| ml_publicacion_producto_id | FK nullable → `ml_publicacion_producto.id`, `nullOnDelete` | el vínculo puede borrarse después |
| ml_item_id | string(30) | copia, para que el historial sobreviva al vínculo |
| producto_id | FK nullable → `productos.id`, `nullOnDelete` | |
| tipo_anterior | string(30) nullable | `gold_special` / `gold_pro` / null (sin clasificar) |
| tipo_destino | string(30) | `gold_special` / `gold_pro` |
| precio_anterior | decimal(15,2) nullable | `precio_publicado` al momento del intento |
| precio_nuevo | decimal(15,2) nullable | precio de la lista destino |
| lista_precio_id | FK nullable → `listas_precio.id` | lista de la que salió el precio |
| resultado | string(30) | `cambiado` · `cambiado_precio_pendiente` · `sin_cambios` · `bloqueado` · `fallido` · `revertido` |
| mensaje | text nullable | motivo de bloqueo/falla (el de ML si lo hubo) |
| lote_id | uuid nullable, index | null = cambio individual |
| usuario_id | FK nullable → `users.id` | quien confirmó (en el job se pasa explícito) |
| created_at / updated_at | timestamps | |

Índices: `(ml_publicacion_producto_id, created_at)`, `lote_id`.

Estados de `resultado`:
- `cambiado`: tipo y precio OK.
- `cambiado_precio_pendiente`: →Clásica con tipo cambiado y precio no enviado (queda `precio_pendiente = 1`).
- `sin_cambios`: ML ya tenía el tipo destino; sólo se actualizó el vínculo.
- `bloqueado`: no se envió nada (sin precio destino, tipo no disponible, cortes, candado).
- `fallido`: ML rechazó el cambio de tipo antes de que se tocara el precio.
- `revertido`: →Premium con precio subido y tipo rechazado; se restituyó el precio anterior.

## `ml_publicacion_producto` (existente, sin columnas nuevas)

- `listing_type_id` / `listing_type_sincronizado_en`: se actualizan en el momento al confirmar un cambio
  (o al detectar en la previsualización que el tipo vigente de ML difiere del guardado).
- `precio_publicado`, `precio_pendiente`, `precio_error`: los maneja `SincronizadorPrecios::enviarUno()`.
- Modelo: constantes `TIPO_CLASICA = 'gold_special'`, `TIPO_PREMIUM = 'gold_pro'`, accessor
  `tipo_etiqueta` (Clásica / Premium / Sin clasificar / valor crudo para otros tipos).

## `retenciones_precio_ml` (existente)

Sin cambios de esquema: una retención abierta se cierra como `reemplazada` cuando el cambio de tipo
publica el precio (lo hace `enviarUno()`).

## Estado del lote (Cache, no tabla)

Clave `ml:cambio_tipo_lote:estado`, TTL 30 min:

```json
{ "estado": "en_curso|terminado|error", "lote_id": "uuid", "destino": "gold_pro",
  "total": 12, "procesadas": 5,
  "resumen": { "cambiado": 9, "cambiado_precio_pendiente": 0, "sin_cambios": 1, "bloqueado": 1, "fallido": 1, "revertido": 0 },
  "detalle": [ { "ml_item_id": "MLA...", "resultado": "fallido", "mensaje": "..." } ],
  "terminado_en": "ISO8601" }
```

`detalle` incluye sólo las no exitosas (las exitosas se cuentan).
