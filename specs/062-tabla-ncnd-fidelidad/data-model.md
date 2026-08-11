# Data Model: Fidelidad estructural de la tabla NC/ND

## Entidad modificada: `NotaCreditoDebito` (tabla `notas_credito_debito`)

Campo nuevo:

| Campo | Tipo | Nulable | Default | Notas |
|---|---|---|---|---|
| `nota_interna` | `text` | Sí | `null` | Texto libre, opcional. Igual criterio que `nota_interna` en `ventas`/`compras`. No participa en el cálculo de montos ni en la lógica fiscal. |

Sin cambios en el resto de columnas/relaciones existentes (`tipo`, `afecta_stock`,
`mes_imputacion`, `fecha_emision`, `monto`, `tipo_comprobante`, `nro_comprobante`, `descripcion`,
`impuestos`, `nota_ajustada_id`, descuento general, `venta_id`/`compra_id`, `legacy_id`).

## Relaciones reutilizadas (sin cambios de esquema)

- `NotaCreditoDebito belongsTo comprobanteFiscal` (Morph1) — ya existe.
- `NotaCreditoDebito belongsTo notaAjustada` (self, `nota_ajustada_id`) — ya existe (spec 057).
- `Venta belongsTo comprobanteFiscal` / `Compra belongsTo comprobanteFiscal` (Morph1) — ya existen.

## Vista derivada: fila de la tabla "Notas de Crédito y Débito"

Cada fila se arma (en el controller o directamente en Blade, a decidir en tasks) a partir de:

| Columna UI | Origen del dato |
|---|---|
| Estado | Sin cambios — ya existe, sigue siendo el disparador del menú de fila (Editar/Eliminar/Ver Detalle), igual que en Contagram real |
| ID | `$nota->id` (sin cambios) |
| Emisión | `$nota->fecha_emision` (sin cambios de formato) |
| Comprobante | Sin cambios — ya existe, tipo de comprobante de la propia nota ("Nota de Crédito"/"Nota de Débito") |
| N° Comprobante | **Nuevo**: `$nota->comprobanteFiscal?->numero` o `$nota->nro_comprobante`; "-" si ninguno |
| Documento que Ajusta | Ver research.md R4 (prioridad: `notaAjustada` → comprobante fiscal de Venta/Compra original → "-") |
| Total | `$nota->monto` (sin cambios de formato) |
| Nota Interna | `$nota->nota_interna`; "-" o vacío si null |
| (acciones) | Ver Detalle / Editar / Eliminar — mismo comportamiento, disparador movido a columna dedicada (research.md R6) |

## Validación (FormRequests)

`StoreNotaCreditoDebitoRequest` / `UpdateNotaCreditoDebitoRequest` agregan:

```text
'nota_interna' => 'nullable|string',
```

Sin reglas adicionales — es un campo informativo, no participa en el cálculo de descuentos/montos ya
validado por esas Requests.
