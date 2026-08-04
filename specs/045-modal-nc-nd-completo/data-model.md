# Data Model: Modal NC/ND completo

## `notas_credito_debito` (existente, +1 columna)

| Campo | Tipo | Notas |
|---|---|---|
| id | bigint PK | — |
| venta_id | FK → ventas, nullable | exactamente uno de venta_id/compra_id seteado |
| compra_id | FK → compras, nullable | ídem |
| tipo | enum(`credito`,`debito`) | — |
| afecta_stock | boolean, default false | **ya existía**, hoy siempre llega `false` porque el modal no lo expone — esta feature lo activa |
| **mes_imputacion** | **date, NOT NULL** | **NUEVO.** Se persiste con día fijado a `01` (representa "mes/año"), precargado por defecto con el mes/año de `fecha_emision` al abrir el modal, editable |
| fecha_emision | date | — |
| monto | decimal(14,2) | — |
| tipo_comprobante | string | — |
| descripcion | text, nullable | obligatoria si `afecta_stock = false` (regla ya existente) |
| impuestos | json, nullable | — |
| timestamps + soft deletes | — | — |

Migración: `ALTER TABLE notas_credito_debito ADD COLUMN mes_imputacion DATE NOT NULL AFTER
afecta_stock`. Para filas ya existentes (creadas antes de esta feature), backfill con el valor de
`fecha_emision` normalizado al día 1 del mismo mes (no hay ambigüedad de negocio: es el mejor
default disponible con los datos ya cargados).

## `nota_credito_debito_items` (existente, sin cambios de estructura)

Ya modelada: `producto_id`, `cantidad`, `precio`, `origen` (`venta_original`/`nuevo`). Esta feature
es la primera en poblarla activamente desde el modal (antes sólo existía en el modelo/migración,
sin flujo de UI que la usara). Se mantiene `origen = 'venta_original'` para todos los ítems
agregados desde el selector "Agregar Productos de la [Compra|Venta]", ya que sólo se ofrecen
productos presentes en el comprobante original (ver Assumption del spec).

## Regla derivada: cantidad pendiente de ajuste por producto/comprobante

No es una columna nueva, es una regla de validación calculada en el momento de guardar:

```
pendiente(producto, comprobante) = cantidad_facturada(producto, comprobante)
                                    - Σ cantidad ya ajustada por notas previas
                                      (no eliminadas) de ese mismo comprobante
                                      para ese producto
```

- `cantidad_facturada`: la cantidad del ítem en `venta_items`/`compra_items` (según el módulo) del
  comprobante que se está ajustando.
- La suma excluye notas soft-deleted (consistente con Principio III: soft delete para trazabilidad,
  pero una nota borrada no debe seguir "consumiendo" cupo).
- Se valida en `StoreNotaCreditoDebitoRequest` (o un `after()` hook del FormRequest, dado que
  depende de datos ya persistidos, no sólo de la forma de los campos).

## Sin nuevas entidades

No se agregan modelos nuevos. `Deposito` y `Producto` ya existen y se reutilizan tal cual (mismo
patrón que usa `StockService::ajustar()` hoy).
