# Contratos — spec 093

Todo responde JSON y se consume por AJAX, sin recargar la página. La descarga es la única respuesta
binaria.

---

## 1 · Historial (existente, se le agregan campos)

```
GET /importar-datos/{entidad}/historial/datos
```

A cada fila se le suman:

```json
{
  "archivo": {
    "estado": "disponible",
    "descargable": true,
    "nombre": "productos_20260828_151041.xlsx",
    "guardado_en": "2026-08-28T15:31:40-03:00",
    "vencido_en": null
  },
  "informe_disponible": true
}
```

`estado` es uno de **`disponible`**, **`nunca_guardado`**, **`vencido`**. Tres estados y no un
booleano: "no está porque venció" y "no está porque nunca se guardó" le dicen cosas distintas a
quien audita.

`informe_disponible` es `false` cuando la corrida no tiene filas de snapshot — la pantalla muestra
*"sin detalle disponible"* y **no** un informe vacío (FR-007).

---

## 2 · Informe de cambios

```
GET /importar-datos/{entidad}/historial/{corrida}/informe
```

**200**

```json
{
  "ok": true,
  "corrida": {
    "id": 5,
    "archivo_original": "productos_20260828_151041.xlsx",
    "confirmado_en": "2026-08-28T15:31:40-03:00",
    "usuario": "Pompei1sanitarios@gmail.com",
    "deshecha_en": null,
    "filas_totales": 192,
    "filas_con_detalle": 192
  },
  "advertencia_metodo": "Compara el estado guardado antes de la importación contra el producto de hoy. Un cambio posterior (una venta, una edición) también aparece acá.",
  "resumen": {
    "productos_con_algun_cambio": 18,
    "productos_sin_cambios": 174,
    "con_actividad_posterior": 3,
    "productos_eliminados": 0
  },
  "campos": [
    { "campo": "costo", "productos": 12, "ejemplo": { "codigo": "27198 BTR6363 BL", "antes": "41000.00", "ahora": "43500.00" } }
  ],
  "precios": [
    { "lista_precio_id": 8, "lista": "ML", "productos": 4,
      "ejemplo": { "codigo": "27218 BCU5070 BL", "antes": 167206.75, "ahora": 172000.00, "variacion_pct": 2.87 } }
  ],
  "stock": [
    { "producto_id": 41527, "codigo": "41527 EMB", "nombre": "EMBALAJE JPD",
      "deposito": "Local", "antes": 263, "ahora": 82, "diferencia": -181,
      "actividad_posterior": false, "producto_eliminado": false }
  ]
}
```

`stock` viene **ordenado por magnitud** de la diferencia (FR-004): el −181 aparece primero, que es
justamente el que uno quiere ver.

`advertencia_metodo` viaja en la respuesta y no está escrita en la vista a propósito: es una
limitación real del dato y tiene que llegar junto con él, no depender de que alguien la deje puesta
en el HTML.

**200 con `informe_disponible: false`** — corrida sin filas de snapshot:

```json
{ "ok": true, "informe_disponible": false,
  "motivo": "Esta importación es anterior al registro de detalle. No hay información de qué cambió." }
```

**No es un 404**: la corrida existe, lo que no existe es su detalle.

---

## 3 · Descarga del archivo

```
GET /importar-datos/{entidad}/historial/{corrida}/archivo
```

Devuelve el archivo con su **nombre original** (`Content-Disposition: attachment`).

- **403** — sin permiso sobre importaciones (FR-014).
- **404** — la corrida no existe.
- **410 Gone** — el archivo venció por antigüedad. Código distinto del 404 a propósito: existió y ya
  no está, que es información útil para quien audita.
- **422** — está registrado pero no se puede leer del disco (borrado a mano, corrupto). Nunca se
  devuelve un archivo vacío.

---

## 4 · Limpieza

```
php artisan importaciones:limpiar-archivos [--dias=90] [--dry-run]
```

Agendado diario. Elimina:

1. Archivos de corridas cuyo `archivo_guardado_en` supera el plazo → marca `archivo_vencido_en`.
2. Archivos **sueltos** sin corrida asociada, más viejos que el plazo — los 23 actuales entran acá.

**No toca** los archivos de importaciones sin confirmar (FR-022): una importación en curso tiene su
archivo en el mismo directorio y borrarlo la rompería.

`--dry-run` lista sin borrar. Dado que la operación es destructiva y barre archivos que no están
referenciados por ninguna fila, la primera corrida en producción se hace con `--dry-run`.
