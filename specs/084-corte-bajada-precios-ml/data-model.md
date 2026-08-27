# Modelo de datos — spec 084

Una tabla nueva y tres columnas nuevas sobre tablas existentes. Nada se borra ni se renombra.

---

## Tabla nueva: `retenciones_precio_ml`

Un envío de precio que quedó frenado. Guarda también las ya resueltas: el historial es un requisito
(FR-015, FR-031), no un extra.

| Columna | Tipo | Nulo | Descripción |
|---------|------|:----:|-------------|
| `id` | bigint unsigned PK | no | |
| `ml_publicacion_producto_id` | bigint unsigned FK | no | La publicación retenida |
| `precio_propuesto` | decimal(14,2) | no | Lo que se iba a publicar |
| `precio_publicado` | decimal(14,2) | **sí** | Contra qué se comparó. `NULL` cuando el motivo es `sin_referencia` |
| `caida_pct` | decimal(6,2) | sí | Caída porcentual. `NULL` cuando no se pudo calcular |
| `lista_precio_id` | bigint unsigned FK | no | Qué lista originó la propuesta. Deja ver si vino de la general o de la Premium |
| `motivo` | enum | no | `supera_umbral`, `precio_invalido`, `sin_referencia` |
| `umbral_pct` | decimal(5,2) | no | El umbral **vigente al retener**. Se copia a propósito: si mañana se cambia, la retención vieja tiene que seguir explicándose sola |
| `estado` | enum | no | `abierta`, `aprobada`, `rechazada`, `reemplazada` |
| `resuelta_en` | timestamp | sí | |
| `resuelta_por_id` | bigint unsigned FK a `users` | sí | `NULL` si la resolvió el sistema (`reemplazada`) |
| `precio_enviado` | decimal(14,2) | sí | Lo que finalmente se publicó al aprobar. Puede diferir de `precio_propuesto` (FR-014) |
| `created_at` / `updated_at` | timestamp | sí | |

**Índices**

- `ml_publicacion_producto_id`
- `estado`
- **Único parcial**: a lo sumo una fila `abierta` por publicación. MariaDB no tiene índices únicos
  parciales, así que se resuelve con una columna generada (`abierta_uk` = `ml_publicacion_producto_id`
  cuando `estado = 'abierta'`, `NULL` si no) más un índice único sobre ella. **La regla vive en la
  base, no sólo en el código** — es la que impide que dos retenciones abiertas se pisen.

**Sin soft delete**: no es un documento fiscal ni contable. El historial se conserva por `estado`, no
por `deleted_at`.

---

## Columnas nuevas en `ml_publicacion_producto`

| Columna | Tipo | Nulo | Descripción |
|---------|------|:----:|-------------|
| `precio_publicado` | decimal(14,2) | **sí** | Último precio que Mercado Libre aceptó. `NULL` = no hay referencia ⇒ **retiene** (Decisión 1) |
| `precio_publicado_en` | timestamp | sí | Cuándo se supo. Permite ver si el dato quedó viejo |

`NULL` es el estado inicial de las 270 publicaciones. El backfill (Decisión 5) las puebla antes de
activar el corte.

**No se agrega una columna `retenida`**: se deriva de la existencia de una retención `abierta`. Un
booleano duplicado se desincroniza.

---

## Columna nueva en `ml_configuracion`

| Columna | Tipo | Nulo | Default | Descripción |
|---------|------|:----:|:-------:|-------------|
| `umbral_caida_precio_pct` | decimal(5,2) | no | `20.00` | Caída máxima admitida sin aprobación |

Rango válido **0 a 100**. Los dos extremos son válidos y tienen que funcionar:

- `0` retiene toda bajada, por mínima que sea.
- `100` no retiene por porcentaje, pero **sigue reteniendo** por `precio_invalido` y por
  `sin_referencia`. No es un interruptor de apagado del corte.

`ml_configuracion` es la fila única de la integración (single-tenant), consistente con el principio V.

---

## Cómo se relaciona con lo que ya existe

```
ml_configuracion (fila única)
  ├── lista_precio_id            → lista de las Clásicas          (existente)
  ├── lista_precio_id_premium    → lista de las Premium           (existente)
  └── umbral_caida_precio_pct    → el corte                       NUEVO

ml_publicacion_producto
  ├── listing_type_id            → gold_special | gold_pro | NULL (existente)
  ├── precio_pendiente           → "hay algo que mandar"          (existente, sin cambios)
  ├── precio_error               → último rechazo de la API       (existente, sin cambios)
  ├── precio_publicado           → la referencia del corte        NUEVO
  └── retenciones()              → historial                      NUEVO

retenciones_precio_ml            → una abierta como máximo        NUEVA
```

**`precio_pendiente` no cambia de significado** (Decisión 4). Al retener se lo apaga: no hay nada que
reintentar, porque la decisión ya está tomada y espera a una persona.

---

## Transiciones de la retención

```
                    propuesta que supera el corte
                              │
                              ▼
                          abierta ──── otra propuesta ────► reemplazada
                         ╱       ╲
              aprobar   ╱         ╲   rechazar
                       ▼           ▼
                   aprobada     rechazada
              (envía el precio   (no envía
               VIGENTE, no el     nada)
               congelado)
```

**Al aprobar se envía el precio vigente de la lista, no `precio_propuesto`.** Si difieren, se avisa
antes (FR-014). Congelar el importe sería publicar un precio que el negocio ya cambió de opinión
sobre — el error opuesto al que la spec quiere evitar.

**`reemplazada` la escribe el sistema**, sin usuario: no es una decisión de nadie, es una propuesta
que quedó obsoleta.

---

## Impacto en `docs/modelo_datos.md`

Antes de `/speckit-tasks` hay que agregar ahí (principio I de la constitución):

- La tabla `retenciones_precio_ml` completa.
- Las dos columnas de `ml_publicacion_producto` y la de `ml_configuracion`.
- La nota de que `precio_pendiente` y "retenida" son estados distintos y por qué.
