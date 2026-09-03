# Data Model: Envío Manual a ARCA para NC/ND, con IVA real por línea

Sin cambios de esquema. Se reutilizan entidades existentes (`notas_credito_debito`,
`nota_credito_debito_items`, `comprobantes_fiscales`).

## NotaCreditoDebito (existente — sin cambios de esquema)

Se agrega un campo **calculado** (no persistido) en la respuesta que arma la vista/AJAX del Detalle de
Venta/Compra, usado para decidir si la fila de esa nota muestra la acción "Enviar a ARCA" y qué estado
mostrar (US1, US4):

| Campo (respuesta) | Tipo | Regla |
|---|---|---|
| `puede_enviarse_arca` | boolean | `true` cuando `tipo_comprobante ∈ {A,B,C}` **y** no existe `comprobanteFiscal` propio con `estado='aprobado'` **y** el comprobante original (`venta`/`compra`) sí tiene `comprobanteFiscal` con `estado='aprobado'` **y** `FuncionAvanzada::activa('facturacion_electronica')` es `true` (FR-003, FR-007). |
| `estado_arca` | string | Uno de `sin_enviar` \| `pendiente` \| `aprobado` \| `rechazado`, derivado de `comprobanteFiscal?->estado` (null → `sin_enviar`) (FR-015). |

No se persisten — se recalculan en cada carga de la vista a partir de datos ya existentes
(`NotaCreditoDebito::comprobanteFiscal()`, `venta.comprobanteFiscal`/`compra.comprobanteFiscal`,
`FuncionAvanzada`). Mismo patrón que `Venta::puede_enviarse_arca` de spec 040 (`data-model.md` de esa
spec), extendido con la condición adicional de "comprobante original aprobado".

## NotaCreditoDebitoItem (existente, spec 096 — sin cambios de esquema)

Sin cambios de columnas. Se reutilizan `cantidad`, `precio`, `descuento_pct`, `iva_pct` (ya persistidos por
línea) como fuente del desglose real de IVA (R3). `venta_item_id`/`compra_item_id` (spec 096) se usan sólo
como **indicador de elegibilidad** para el desglose por línea (R4): si algún ítem de la nota tiene ambos
en `null`, la nota completa cae al fallback agregado (FR-010a).

## ComprobanteFiscal (existente — sin cambios de esquema)

Sin cambios. Se sigue creando/actualizando exclusivamente vía `EmisorComprobante::emitir()`, ahora
invocado desde la nueva acción manual de NC/ND en vez del trigger automático de `store()`/`storeCompra()`.
Es también la fuente de `estado_arca` (FR-014, FR-015) vía la relación ya existente y ordenada
`comprobanteFiscal()` (`morphOne`, ver research.md R7).

## FuncionAvanzada (existente — sin cambios de esquema)

Se lee (no se escribe) la fila `clave='facturacion_electronica'` para decidir disponibilidad de la acción
(FR-007), igual que ya hace spec 040 para Venta.

## Payload hacia ARCA (`MapeadorComprobante::mapear()` — sin cambios de firma)

El único cambio de datos es **qué le pasa** `NotaCreditoDebitoController` al array `$datos` que arma antes
de llamar a `emitir()`:

| Antes (actual) | Después (esta spec) |
|---|---|
| `neto` = `round($nota->monto / 1.21, 2)` (siempre) | `neto`/`iva` agregados sólo se usan como fallback (R5) cuando aplica FR-010/FR-010a |
| `iva` = `$nota->monto - neto` (siempre) | ídem |
| sin `items` | `items` = array de `['neto' => ..., 'iva_pct' => ...]` por cada `NotaCreditoDebitoItem`, sólo cuando **todos** los ítems tienen `venta_item_id`/`compra_item_id` no nulo (FR-009, FR-010a) |

`MapeadorComprobante::armarBloquesAlicIva()` ya sabe consumir `items` (contrato ya usado por Venta/Compra)
— no requiere cambios.
