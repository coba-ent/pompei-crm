# Data Model: Importación por Excel escalable a archivos grandes

**Spec**: [spec.md](./spec.md) | **Fecha**: 2026-08-25

## Cambios de esquema: NINGUNO

Esta feature **no crea, modifica ni elimina tablas ni columnas**. `docs/modelo_datos.md` no requiere
actualización.

Se documenta explícitamente porque la alternativa descartada (tabla de staging, Decisión 1 de
research) **sí** habría requerido esquema nuevo, y conviene dejar asentado por qué no se fue por ahí:
rompía el invariante de §2.4 de que el archivo subido y el mapeo son estado transitorio que nunca
toca la base.

## Estructuras existentes que se siguen usando igual

| Tabla | Rol en la importación | Cambios |
|---|---|---|
| `importacion_corridas` | Agrupa una importación de Productos para el deshacer (spec 078). Se crea en la primera tanda y se reutiliza en las siguientes. | **Ninguno.** Una importación cortada y retomada sigue siendo **una sola** corrida (FR-010). |
| `importacion_filas_snapshot` | Estado previo de cada fila aplicada, para el deshacer. | **Ninguno en el esquema.** Se le agrega un **uso nuevo de lectura**: consultar qué `numero_fila` ya se aplicaron en esa corrida, para que un reintento saltee las filas ya procesadas (Decisión 5 de research). |
| `productos`, `precios_producto`, `stocks`, `movimientos_stock`, `logs_auditoria` | Destino real de la importación. | **Ninguno.** Las reglas de las specs 074/078 se preservan intactas (FR-013 a FR-015). |

## Artefacto transitorio nuevo: el archivo NDJSON

No es una entidad de dominio ni vive en la base. Se documenta acá porque es la estructura de datos
central de la feature.

**Ubicación**: `storage/app/private/imports/{uuid}.ndjson`, junto al `{uuid}.xlsx` que ya existe.

**Formato**: una fila del archivo original por línea, como array JSON de celdas indexadas por posición.
La **primera línea son los encabezados**.

```text
["Id","Nombre","Código/SKU","Tipo",...,"Estado"]
[12690,"Colocacion accesorio 5 a 8 agujeros","12690 12690","producto",...,"Activo"]
[12691,"Pegado y colocacion de bacha multiple","12691 12691","producto",...,"Activo"]
```

**Por qué array y no objeto con claves**: el mapeo del Paso 2 referencia las columnas **por índice
numérico** (`mapeo[0] => 'id'`). Un objeto con claves por encabezado rompería con encabezados
duplicados o vacíos, que existen en archivos reales.

**Ciclo de vida**:

| Momento | Qué pasa |
|---|---|
| Paso 1 (subir) | Se genera, una sola vez, junto al temporal. |
| Paso 2 (mapear) | Se lee sólo la primera línea (encabezados) y las primeras filas (vista previa). |
| Paso 3 (cada tanda) | Se leen sólo las líneas de esa tanda. |
| Fin de la importación | Se borra, junto con el `.xlsx`. |
| Cancelar | Se borra, junto con el `.xlsx`. |

**Tamaño esperado**: comparable al original. ~1,8 MB proyectados para 10.000 filas de Productos
(medido sobre el archivo real del incidente: 215 KB para 1.118 filas).

## Estado de sesión

Sin entidades nuevas. Se agregan dos claves al estado ya existente de `session('importacion')`:

| Clave | Para qué | Por qué en sesión y no recalculado |
|---|---|---|
| `ndjson` | Nombre del archivo volcado. | Es la fuente de filas de cada tanda. |
| `total` | Cantidad de filas de datos. | Evita recorrer el archivo en cada tanda sólo para saber el total del progreso. |

Los `columnas` y `preview` que ya se guardan hoy pasan a leerse del NDJSON en vez del `.xlsx`, sin
cambiar su forma ni su uso.
