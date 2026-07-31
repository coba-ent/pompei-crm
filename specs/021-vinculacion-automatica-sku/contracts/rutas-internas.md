# Contrato de rutas internas — Vinculación automática por SKU

Todas las rutas van dentro de los grupos ya existentes `ingresos/mercadolibre/vinculaciones` y
`ingresos/tiendanube/vinculaciones` (`routes/web.php`), mismo middleware/autenticación que el resto de
esas pantallas.

## Mercado Libre

### `POST ingresos/mercadolibre/vinculaciones/vincular-automaticamente` (nueva, reemplaza a `store`)

Sin parámetros. Dispara `VinculadorAutomatico::ejecutar()`.

Respuesta 200:

```jsonc
{
  "ok": true,
  "mensaje": "9 de 12 publicaciones vinculadas.",
  "total": 12,
  "vinculadas": 9,
  "fallidas": 3,
  "detalle_fallidas": [
    { "referencia": "MLA1927008393", "motivo": "sin_sku" },
    { "referencia": "MLA3690442970", "motivo": "producto_no_encontrado" }
  ]
}
```

### `GET ingresos/mercadolibre/vinculaciones/pendientes`

**Sin cambios** — se mantiene aunque ya no la use el frontend para un selector de alta manual (el
selector desaparece), por si algún flujo futuro la necesita; no se elimina en esta spec.

### `PATCH ingresos/mercadolibre/vinculaciones/{vinculacion}` / `DELETE .../{vinculacion}`

**Sin cambios** — editar el producto de un vínculo existente y eliminar un vínculo siguen disponibles
(clarificación de spec.md).

### `POST ingresos/mercadolibre/vinculaciones/` (store)

**Se elimina.** Ya no hay alta manual de vínculos nuevos de Mercado Libre.

## Tiendanube

### `POST ingresos/tiendanube/vinculaciones/importar` (nueva)

Request: `multipart/form-data`, campo `archivo` (el export nativo de productos de Tiendanube — xlsx/xls/
csv, hasta 10MB, mismo límite que spec 006).

Respuesta 200 (siempre 200 si el archivo se pudo leer, incluso con filas fallidas):

```jsonc
{
  "ok": true,
  "mensaje": "9 de 12 vinculaciones creadas.",
  "total": 12,
  "vinculadas": 9,
  "fallidas": 3,
  "detalle_fallidas": [
    { "referencia": "27205", "motivo": "producto_no_encontrado" },
    { "referencia": "28879 REP-TD-009-BL ZTDAC", "motivo": "tiendanube_no_encontrado" }
  ]
}
```

Respuesta 422 (archivo rechazado antes de procesar ninguna fila): archivo vacío, extensión no soportada,
o sin las columnas `SKU` / `Identificador de URL` reconocibles — mismo formato de error que el resto de
los `FormRequest` del proyecto (`errors.archivo`).

### Resto de rutas de Tiendanube (`index`, `datatable`, `pendientes`, `store`, `update`, `destroy`)

**Sin cambios** — el alta manual con selector sigue funcionando exactamente igual (clarificación de
spec.md).
