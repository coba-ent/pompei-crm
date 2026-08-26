# Data Model: Prevalidación y confirmación previa de la importación

**Spec**: [spec.md](./spec.md) | **Fecha**: 2026-08-26

## Cambios de esquema: NINGUNO

Esta feature **no crea, modifica ni elimina tablas ni columnas**. `docs/modelo_datos.md` no requiere
actualización.

Se deja asentado porque es una decisión, no un olvido: la prevalidación es **estado transitorio** —
como el archivo subido y el mapeo elegido (invariante de §2.4). Guardarla en base habría sido la vía
fácil para acumularla entre tandas, y habría roto ese invariante.

## Estructuras existentes que se siguen usando igual

| Tabla | Rol | Cambios |
|---|---|---|
| `importacion_corridas` | Registro real de una importación de Productos (spec 078). | **Ninguno en el esquema.** Uso nuevo de lectura: pasa a ser la **fuente del resumen** para Productos, en vez de la sesión (FR-021 a FR-024). |
| `importacion_filas_snapshot` | Estado previo de cada fila, para el deshacer. | Ninguno. |
| `productos`, `clientes`, `proveedores`, `precios_producto`, `stocks` | Destino de la importación. | Ninguno. La prevalidación **los lee** para resolver alta vs actualización; nunca los escribe. |

## Artefacto transitorio nuevo: el informe de prevalidación

No es una entidad de dominio ni vive en la base.

**Qué contiene**:

| Dato | Para qué |
|---|---|
| `altas` | Cuántas filas crearían un registro nuevo |
| `actualizaciones` | Cuántas filas modificarían uno existente |
| `campos_afectados` | Por cada campo que se va a escribir en actualizaciones, a **cuántos registros** afecta — con la etiqueta visible del campo (FR-005b) |
| `errores` | Lista de `{fila, motivo}` — el número de fila del archivo y el motivo en español |
| `advertencias` | Lista de `{fila, motivo}` — no bloquean (ej. proveedor no encontrado) |
| `procesadas` / `total` | Progreso de la prevalidación entre tandas |
| `huella` | Del archivo + el mapeo prevalidados, para rechazar una confirmación que ya no corresponde (FR-009) |

**Dónde vive**: junto al NDJSON de la spec 082 en `storage/app/private/imports/`, o en sesión si el
volumen lo permite. Se decide al implementar la Fase D con una medición real: un archivo de 10.000
filas todas malas daría 10.000 errores, y eso **no** entra cómodo en una sesión.

**Ciclo de vida**: nace al prevalidar, se descarta al confirmar, al cancelar o al subir un archivo
nuevo. Igual que el `.xlsx` y el `.ndjson`, con los que se borra en conjunto.

## Estado de sesión

Sin entidades nuevas. Los cambios son sobre el estado que ya existe:

| Clave | Cambio | Motivo |
|---|---|---|
| `importacion_resultado_parcial` | **Se ata a la importación en curso**: al empezar una importación nueva se descarta el acumulado anterior. | Es la causa raíz del resumen contaminado (research Decisión 6), reproducida con test: 1000 residuales + 2 importados informaban 1002. |
| `importacion_corrida_ref` | **Nueva**: identificador de la importación en curso, generado en el Paso 1. | Permite validar que el resumen que se muestra corresponde a esa importación. Necesario para Clientes y Proveedores, que **no** tienen `ImportacionCorrida` (spec 078 sólo cubre Productos). |
| `prevalidacion` | **Nueva**: referencia al informe y su huella. | Sostiene el paso de revisión entre tandas y habilita (o no) el botón de confirmar. |

## Nota sobre el deshacer (comportamiento existente, no se cambia)

El deshacer de la spec 078 sobre filas dadas de alta las deja `activo = false`: **no** borra el
registro, **no** libera el id del auto-increment y **no** borra las filas de `precios_producto`.
Verificado el 26/08/2026 deshaciendo una corrida de 124 productos. Se documenta acá porque sorprendió
durante las pruebas; **cambiarlo está fuera del alcance de esta spec**.
