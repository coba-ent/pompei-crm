# Modelo de datos — spec 093

**Ninguna tabla nueva.** Tres columnas sobre una tabla existente, y una nota importante sobre otra.

---

## Columnas nuevas en `importacion_corridas`

| Columna | Tipo | Nulo | Descripción |
|---------|------|:----:|-------------|
| `archivo_guardado_ruta` | varchar(255) | **sí** | Ruta relativa dentro del disco `local`. `null` = nunca se guardó (corrida anterior a esta spec, o el guardado falló) |
| `archivo_guardado_en` | timestamp | sí | Cuándo se guardó la copia. Es lo que la limpieza compara contra el plazo |
| `archivo_vencido_en` | timestamp | sí | Cuándo la limpieza lo eliminó. Distingue **"venció"** de **"nunca se guardó"** |

**Los tres estados del archivo se derivan, no se guardan como enum:**

```
archivo_guardado_ruta = null  y  archivo_vencido_en = null   →  nunca se guardó
archivo_guardado_ruta ≠ null  y  archivo_vencido_en = null   →  disponible
                                 archivo_vencido_en ≠ null   →  venció por antigüedad
```

Un enum aparte se desincronizaría con el archivo real el día que alguien lo borre a mano.

⚠️ **El nombre del archivo NO es la clave.** `archivo_original` guarda el nombre que subió el
usuario y **puede repetirse entre corridas** (`productos_20260825_175146.xlsx` aparece en las
corridas 1 y 2). La ruta guardada es única por corrida (FR-017).

---

## `importacion_filas_snapshot` — no cambia, pero cambia su significado

**No se agrega ninguna columna.** Lo que cambia es qué es esta tabla:

> Nació (spec 078) como insumo del **deshacer**, con vida útil de 48 horas. Desde la spec 093 es
> además la **única fuente del informe de cambios**, y por lo tanto **no es temporal**: sus filas
> tienen que sobrevivir indefinidamente al vencimiento del deshacer.
>
> **Nadie debe purgarla.** Hoy nada la borra —verificado el 28/08/2026: ninguna consulta de purga en
> el código, y las corridas 2 y 3 conservan sus filas con el deshacer vencido— pero el nombre y el
> origen invitan a creer que es descartable. Purgarla deja sin informe a todas las corridas viejas.
>
> Volumen medido: **1.605 filas, 1,33 MB**. No es un problema de espacio.

### Formato de las columnas JSON — leerlo mal da resultados absurdos

```json
estado_anterior     {"id":27136,"nombre":"Peinador...","codigo":"27136 PT5070-1M BL","costo":...}
precios_anteriores  [{"lista_precio_id":1,"precio":"213506.29"}, {"lista_precio_id":2,...}]
stock_anterior      [{"deposito_id":5,"cantidad":"0.000"}]
```

`precios_anteriores` y `stock_anterior` son **arrays de objetos**, no mapas `id => valor`. Leerlos
como mapa hace que el índice del array se tome por id de lista: el primer intento de armar este
informe reportó *"192 productos cambiaron en las 11 listas"* cuando la respuesta real era
**ninguno**. Hay un test que fija el formato.

---

## Configuración del plazo

El plazo de conservación (**90 días** por defecto, FR-019) vive junto al resto de la configuración de
importaciones. No se crea una tabla para un único número.

---

## Los archivos huérfanos actuales

Los 23 archivos sueltos en `storage/app/private/imports/` (9,2 MB, el más viejo del 06/08/2026)
**no se pueden asociar** a ninguna corrida: sus nombres son UUID que no quedaron registrados en
ningún lado. La limpieza se los lleva por antigüedad. No hay backfill posible ni conviene inventarlo.
