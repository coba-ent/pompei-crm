# Contrato: Rutas de Edición/Eliminación/PDF de NC/ND

Todas las rutas nuevas siguen el patrón AJAX + JSON ya usado por `notas.store` (ver
`routes/web.php` líneas 207-209 para Ventas y 296-297 para Compras). Alta/edición/eliminación
nunca recargan la página (regla de diseño obligatoria del proyecto).

## Ventas

```
PUT    ventas/{venta}/notas/{notaCreditoDebito}        NotaCreditoDebitoController@update       name: ventas.notas.update
DELETE ventas/{venta}/notas/{notaCreditoDebito}        NotaCreditoDebitoController@destroy      name: ventas.notas.destroy
GET    ventas/notas/{notaCreditoDebito}/pdf            NotaCreditoDebitoController@pdf           name: ventas.notas.pdf   (ya existe)
```

## Compras

```
PUT    compras/{compra}/notas/{notaCreditoDebito}       NotaCreditoDebitoController@updateCompra  name: compras.notas.update
DELETE compras/{compra}/notas/{notaCreditoDebito}       NotaCreditoDebitoController@destroyCompra name: compras.notas.destroy
GET    compras/notas/{notaCreditoDebito}/pdf            NotaCreditoDebitoController@pdf            name: compras.notas.pdf  (NUEVO)
```

> Nota: `pdf()` se generaliza (research.md §6) para resolver `venta` o `compra` según cuál esté
> seteada en la nota — no hace falta un método `pdfCompra` separado, sólo la ruta nueva bajo el
> prefijo `compras.` apuntando al mismo método.

## `update` / `updateCompra` — Request

```
PUT /ventas/{venta}/notas/{nota}
Content-Type: application/json

{
  "tipo": "credito" | "debito",           // NO EDITABLE: debe coincidir con el tipo actual de la
                                            // nota. Se envía sólo para redundancia/validación del
                                            // lado del cliente; si difiere del valor persistido,
                                            // el servidor responde 422 sin aplicar ningún cambio.
  "documento_ajusta": {
    "tipo": "comprobante_original" | "nota",
    "nota_ajustada_id": 123                // sólo si tipo = "nota"
  },
  "afecta_stock": true | false,
  "deposito_id": 5,                        // requerido si afecta_stock = true
  "items": [
    { "producto_id": 41036, "cantidad": 2, "precio": 1500.50, "descuento_pct": 0, "iva_pct": 21 }
  ],
  "mes_imputacion": "2026-08",
  "fecha_emision": "2026-08-11",
  "tipo_comprobante": "A",
  "nro_comprobante": "0001-00000123",
  "monto": 3000,
  "descripcion": "..."
}
```

### Respuestas

- **200**: `{ "ok": true, "mensaje": "...", "nota": {...}, "a_cobrar": ..., "comprobante_fiscal": null }`
- **422 (validación)**: `{ "ok": false, "errors": { "campo": ["mensaje"] } }` — incluye los casos:
  - `tipo`: "El tipo de la nota (Crédito/Débito) no se puede modificar." (si llega distinto al actual)
  - `nro_comprobante`: "Ya existe un comprobante {tipo}-{numero} en Ventas/Compras/Notas."
  - `items.N.cantidad`: "La cantidad máxima disponible para ajustar es {pendiente}." (excluyendo
    esta misma nota del cálculo de pendiente)
  - `documento_ajusta.nota_ajustada_id`: "No se puede ajustar una nota que ya ajusta a otra." (regla de 1 nivel)
- **409 (bloqueo por CAE)**: `{ "ok": false, "mensaje": "No se puede editar: la nota ya tiene un comprobante fiscal aprobado por ARCA. Cargá una nueva NC/ND que la ajuste." }`

## `destroy` / `destroyCompra` — Request

```
DELETE /ventas/{venta}/notas/{nota}
```

### Respuestas

- **200**: `{ "ok": true, "mensaje": "Nota eliminada correctamente.", "a_cobrar": ... }` — soft
  delete (`deleted_at`), revierte stock si `afecta_stock = true`.
- **409 (bloqueo por CAE)**: mismo mensaje que en `update`.
- **409 (bloqueo por cadena)**: `{ "ok": false, "mensaje": "No se puede eliminar: las notas #124, #126 la ajustan a ésta. Eliminalas primero." }`

## `pdf` (generalizado)

```
GET /ventas/notas/{nota}/pdf
GET /compras/notas/{nota}/pdf
```

Devuelve el PDF inline (`Content-Disposition: inline`), consumido por
`window.AppPdf.abrir(url, titulo)` (modal compartido, regla de diseño obligatoria del proyecto —
nunca `target="_blank"` como primera opción).
