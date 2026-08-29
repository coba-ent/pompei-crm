# Investigación y decisiones — spec 093

Todo lo de acá está verificado contra producción el 28/08/2026, no inferido.

---

## Hallazgo 1 — El archivo ya se guarda, pero por accidente

`ImportacionController::limpiarTemporales()` borra el archivo subido al confirmar. Pero se llama
desde tres puntos y **no cubre todos los caminos**: en el VPS quedaron **23 archivos, 9,2 MB**, el
más viejo del 06/08/2026.

```
storage/app/private/imports/3143b97d-0f31-4619-b24b-7e4d54a85a35.xlsx
storage/app/private/imports/0e40bb91-b492-4553-be19-c4a824397510.xlsx
...
```

Nombres UUID, sin ninguna relación con la corrida. **El costo de almacenamiento ya se está pagando
y no se obtiene ningún beneficio.** Guardarlos a propósito no agrega costo: ordena un desorden que
ya existe.

**Consecuencia para el diseño**: los huérfanos actuales **no se pueden asociar** retroactivamente a
su corrida —el UUID no está guardado en ningún lado— así que la limpieza se los lleva. La feature
empieza a acumular archivos identificables recién desde su instalación.

---

## Hallazgo 2 — Los datos del informe ya están todos

`importacion_filas_snapshot` guarda por fila:

| Columna | Contenido real verificado |
|---|---|
| `estado_anterior` | JSON con **todos** los campos del producto (`{"id":27136,"nombre":"...","codigo":"...","costo":...}`) |
| `precios_anteriores` | `[{"lista_precio_id":1,"precio":"213506.29"}, ...]` — las 11 listas |
| `stock_anterior` | `[{"deposito_id":5,"cantidad":"0.000"}]` |

```
1.605 filas   1,33 MB
```

**No hace falta capturar nada nuevo ni tocar el importador.** El informe es una pantalla sobre datos
existentes — de hecho ya se produjo a mano el 28/08 con un script suelto para contestar "qué cambió
la corrida 5".

**Ojo con el formato**: `precios_anteriores` y `stock_anterior` son **arrays de objetos**, no mapas
`id => valor`. Leerlos como mapa da resultados absurdos —al hacerlo mal, el primer intento reportó
"192 productos cambiaron en las 11 listas" cuando la respuesta real era **ninguno**—. El informe
debe recorrer el array y leer `lista_precio_id` / `deposito_id` de cada elemento.

---

## Decisión 1 — El informe mide "qué cambió desde la importación", no "qué hizo la importación"

**El problema**: el snapshot guarda el estado **anterior**. El **posterior** no se guarda en ningún
lado. Comparar el anterior contra el producto de hoy mezcla lo que hizo la importación con todo lo
que pasó después: una venta que movió stock, una edición manual de precio.

**Alternativa descartada — guardar también el estado posterior**: daría un informe exacto e inmune
al paso del tiempo, pero duplica el almacenamiento de snapshots para cubrir un caso marginal, y
obliga a tocar el importador, que esta spec quiere dejar intacto.

**Qué se eligió**: comparar contra el estado actual, **decirlo en el título del informe**, y marcar
las filas con actividad posterior. El sistema **ya sabe detectarla**: `limite_movimiento_stock_id`,
`limite_venta_item_id` y `limite_compra_item_id` del snapshot guardan los ids máximos al momento de
aplicar la fila, y existen justamente para que el deshacer sepa si algo pasó después. Se reusan.

**Por qué importa la honestidad del título**: un informe que dice "esto hizo la importación" y en
realidad muestra "esto cambió desde entonces" es peor que no tener informe — le hace atribuir a la
importación un movimiento que hizo una venta.

---

## Decisión 2 — "Sin detalle" y "sin cambios" son cosas distintas

La corrida 1 existe con **0 filas de snapshot** (es anterior a que se capturaran). Un informe que
dijera "0 cambios" haría parecer inofensiva una importación de la que no se sabe nada.

Se distinguen explícitamente. Vale para cualquier corrida anterior a la captura de snapshots, y
también para el archivo: **"nunca se guardó"** y **"venció"** son estados distintos de
**"disponible"**.

---

## Decisión 3 — Conservar 90 días, configurable

**Qué se eligió**: 90 días por defecto, editable.

**Por qué**: cubre un trimestre de auditoría. A 9 MB por tres semanas, el régimen ronda los 40 MB —
irrelevante frente al valor de rastrear una importación. Sin límite tampoco sería un problema real a
este volumen, pero un plazo explícito evita la conversación incómoda dentro de dos años.

La limpieza también se lleva los **huérfanos** que hoy nadie borra, que es la mitad del valor de
esta historia.

---

## Decisión 4 — Guardar el archivo nunca puede hacer fallar una importación

El archivo es un **respaldo**, no parte de la operación. Si el disco está lleno o el guardado falla,
la importación tiene que terminar igual y registrarse que la copia no se pudo guardar.

Es la misma lógica que ya rige la rama de auditoría de `PrecioProductoObserver`: *documenta, no
gatea*. Invertirlo significaría que un problema de disco impide actualizar precios.

---

## Decisión 5 — La descarga hereda el permiso de la pantalla de importación

Un archivo de importación de productos tiene **precios de costo y márgenes**. No puede quedar más
accesible que la pantalla que lo originó. Se usa el permiso que ya gobierna importaciones; no se
inventa uno nuevo, que sería una superficie más para desalinearse.

---

## Riesgo conocido a futuro

Si algún día se decide **purgar los snapshots vencidos** para ahorrar espacio, esta feature se queda
sin fuente y el informe deja de existir para las corridas viejas. Hoy nada los borra (verificado:
ninguna consulta de purga en el código, y las corridas 2 y 3 conservan sus filas con el deshacer
vencido). Conviene que quede escrito en el modelo de datos para que nadie los borre creyendo que son
temporales.
