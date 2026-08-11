# Contrato: `conceptos` en el payload de NC/ND

Sin rutas nuevas — se extiende el payload ya aceptado por las rutas existentes de spec 057/059:

```
POST ventas/{venta}/notas          (ventas.notas.store)
PUT  ventas/{venta}/notas/{nota}   (ventas.notas.update)
POST compras/{compra}/notas        (compras.notas.store)
PUT  compras/{compra}/notas/{nota} (compras.notas.update)
```

## Campo nuevo en el payload

```json
{
  "...": "resto del payload ya existente (tipo, afecta_stock, items, monto, etc.) sin cambios",
  "conceptos": [
    { "tipo": "percepcion", "concepto": "IIBB Buenos Aires", "monto": 1250.50 },
    { "tipo": "impuesto_interno", "concepto": "Combustibles", "monto": 80 }
  ]
}
```

- `conceptos` es opcional (`nullable|array`) — si se omite o va vacío, la nota se guarda sin
  conceptos (comportamiento actual sin cambios).
- Validación (`StoreNotaCreditoDebitoRequest`/`UpdateNotaCreditoDebitoRequest`):
  - `conceptos.*.tipo`: requerido si hay `conceptos`, uno de `percepcion|impuesto_interno|interes`.
  - `conceptos.*.concepto`: requerido si hay `conceptos`, string, max 255.
  - `conceptos.*.monto`: requerido si hay `conceptos`, numeric.

## Respuesta (sin cambios de forma)

La respuesta ya existente de `store`/`update` (`{ ok, mensaje, nota, ... }`) ahora incluye
`nota.impuestos` con el array persistido, ya que es un campo `fillable`/`casts=array` del modelo
`NotaCreditoDebito` serializado por defecto.

## Precarga en Editar (sin ruta nueva)

`GET ventas/{venta}/notas/{nota}/editar` (`ventas.notas.edit`) ya devuelve `$notaCreditoDebito` al
Blade — `window.NotaFormData.conceptos` se arma en el controller/vista con
`$notaCreditoDebito->impuestos ?? []`.
