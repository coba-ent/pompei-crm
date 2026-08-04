# Contrato: crear Nota de Crédito/Débito (ampliado)

Endpoints ya existentes, sin cambio de ruta ni de forma de respuesta — se amplía el payload de
entrada y las reglas de validación.

## `POST /ventas/{venta}/notas-credito-debito` y `POST /compras/{compra}/notas-credito-debito`

### Request (nuevo payload)

```json
{
  "tipo": "credito | debito",
  "fecha_emision": "YYYY-MM-DD",
  "monto": 1234.56,
  "mes_imputacion": "YYYY-MM-01",
  "afecta_stock": true,
  "deposito_id": 3,
  "items": [
    { "producto_id": 10, "cantidad": 2, "precio": 500.00 }
  ],
  "descripcion": "opcional si afecta_stock=true, obligatoria si false"
}
```

### Reglas de validación (agregadas a `StoreNotaCreditoDebitoRequest`)

- `mes_imputacion`: **required**, `date`. Se normaliza al día 1 del mes antes de persistir.
- `afecta_stock`: sin cambio (boolean).
- `deposito_id`: sin cambio (`required_if:afecta_stock,1,true`).
- `items`: sin cambio de forma, pero se agrega validación **por ítem**: `cantidad` no puede superar
  el pendiente de ajuste de ese `producto_id` en el comprobante (`venta`/`compra`) que se está
  ajustando (ver data-model.md, "Regla derivada"). Si se excede, error 422 con mensaje indicando
  el máximo disponible.
- `descripcion`: sin cambio (`required_unless:afecta_stock,1,true`).

### Response

Sin cambios de forma — sigue devolviendo `{ ok, mensaje, nota, a_cobrar|a_pagar, ... }`. `nota`
ahora incluye `mes_imputacion` entre sus atributos.

### Errores nuevos posibles

- `422` con `errors.items.N.cantidad`: "La cantidad máxima disponible para ajustar es X" (cuando se
  excede el pendiente).
- `422` con `errors.mes_imputacion`: "El mes de imputación es obligatorio" (cuando falta).
