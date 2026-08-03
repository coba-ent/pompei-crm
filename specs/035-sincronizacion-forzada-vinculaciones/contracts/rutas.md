# Contracts: rutas HTTP

Cuatro rutas nuevas, dos por integración, dentro de los grupos ya existentes
`ingresos/mercadolibre/vinculaciones` e `ingresos/tiendanube/vinculaciones`
(`routes/web.php`, mismo middleware `permiso:ventas.ver` del grupo padre).

## Mercado Libre

```
POST /ingresos/mercadolibre/vinculaciones/sincronizacion-forzada
  → MercadoLibreVinculacionController::sincronizacionForzada()
  name: ingresos.mercadolibre.vinculaciones.sincronizacionForzada

DELETE /ingresos/mercadolibre/vinculaciones
  → MercadoLibreVinculacionController::eliminarTodas()
  name: ingresos.mercadolibre.vinculaciones.eliminarTodas
```

## Tiendanube

```
POST /ingresos/tiendanube/vinculaciones/sincronizacion-forzada
  → TiendanubeVinculacionController::sincronizacionForzada()
  name: ingresos.tiendanube.vinculaciones.sincronizacionForzada

DELETE /ingresos/tiendanube/vinculaciones
  → TiendanubeVinculacionController::eliminarTodas()
  name: ingresos.tiendanube.vinculaciones.eliminarTodas
```

**Nota de orden de rutas** (gotcha ya documentado en el proyecto para estos mismos grupos): estas
rutas van **dentro** del `Route::prefix('vinculaciones')->group(...)` ya existente, junto a `index`,
`datatable`, `vincularAutomaticamente`, `update`, `destroy` — no después del grupo, para no repetir el
bug de "ruta con `{param}` genérico matchea antes que el sub-grupo" (ya resuelto una vez en este mismo
prefix, ver comentarios existentes en `routes/web.php` líneas 229-231 y 252-253). El `DELETE /` (sin
`{vinculacion}`) del borrado masivo debe declararse **antes** de
`DELETE {vinculacion}` dentro del mismo grupo, para no correr riesgo de ambigüedad de matching.

## Request / Response — `sincronizacion-forzada`

**Request**: sin body.

**Response 200** (éxito, incluso con errores puntuales por vínculo):
```json
{
  "ok": true,
  "mensaje": "80 productos actualizados en Mercado Libre (stock), 78 productos actualizados en Mercado Libre (precio).",
  "stock": { "actualizados": 80, "con_error": 4 },
  "precio": { "actualizados": 78, "con_error": 0 }
}
```

**Response 409** (bloqueada por corte previo — función desactivada / modo sólo lectura / sin conexión,
o candado tomado por otra sincronización en curso):
```json
{
  "ok": false,
  "tipo": "bloqueada",
  "mensaje": "Bloqueada por el modo sólo lectura: las escrituras hacia Mercado Libre están deshabilitadas."
}
```
(mismo formato que ya devuelven `sincronizar()`/`sincronizarStock()`/`sincronizarPrecios()`
existentes — el front reutiliza el mismo manejador de toast de error).

## Request / Response — `eliminarTodas`

**Request**: sin body (la confirmación ya ocurrió en el modal del front antes de disparar el request).

**Response 200**:
```json
{ "ok": true, "mensaje": "84 vinculaciones eliminadas.", "eliminados": 84 }
```

**Response 409** (candado tomado por una sincronización en curso):
```json
{ "ok": false, "tipo": "salteada", "mensaje": "Ya hay una sincronización en curso." }
```
