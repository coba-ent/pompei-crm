# Data Model: Descuento general aplicado proporcionalmente a neto e IVA

Sin cambios de esquema de base de datos. Todos los campos usados ya existen; esta spec sólo cambia
**cómo se calcula el valor** que se persiste en ellos.

## Entidades existentes involucradas

### VentaItem / PresupuestoItem (sin cambios de esquema)

- `subtotal` (decimal, existente): pasa a incluir, además del descuento de línea (`descuento_pct`,
  sin cambios), el prorrateo del descuento general del comprobante.
- `subtotal_con_iva` (decimal, existente): misma lógica — pasa a reflejar el descuento general
  prorrateado, no sólo el de línea.
- `iva_pct` (decimal, existente): sin cambios — sigue siendo la alícuota real del ítem, usada también
  por spec 042 para armar los bloques `AlicIva`.

### Venta / Presupuesto (sin cambios de esquema)

- `subtotal_sin_descuento` (decimal, existente): sin cambio de significado — sigue siendo la suma de
  los netos de línea antes del descuento general.
- `descuento` (decimal, existente): cambia de valor (no de significado) — sigue siendo
  `subtotal_sin_descuento - subtotal_con_descuento`, pero ahora ese `subtotal_con_descuento` surge de
  sumar los netos por ítem ya con el descuento general prorrateado.
- `subtotal_con_descuento` (decimal, existente): sin cambio de significado — neto final del
  comprobante. Su valor puede diferir en centavos del actual por el redondeo por ítem (dentro de la
  tolerancia $0.01 ya aceptada en spec 042).
- `total` (decimal, existente): **cambia de valor** para comprobantes con `descuento_general_pct` > 0
  — pasa a ser menor, porque ahora el IVA que lo compone también está descontado.

## Estructura de cálculo interna (no persistida)

### `CalculoComprobante::calcular()` — mismo contrato de entrada/salida, fórmula corregida

```text
entrada: items[], descuentoGeneralPct, conceptos[]     // sin cambios de forma

por cada item:
  subtotal_linea          = bruto - bruto * descuento_pct / 100        // sin cambios
  subtotal_con_iva_linea   = subtotal_linea + subtotal_linea * iva_pct / 100   // sin cambios

  factor                  = 1 - descuentoGeneralPct / 100              // NUEVO
  subtotal_final           = round(subtotal_linea * factor, 2)          // NUEVO — antes era
                                                                            subtotal_linea sin ajustar
  subtotal_con_iva_final    = round(subtotal_con_iva_linea * factor, 2)  // NUEVO — antes era
                                                                            subtotal_con_iva_linea
                                                                            sin ajustar

salida:
  items[].subtotal          = subtotal_final           // antes: subtotal_linea
  items[].subtotal_con_iva  = subtotal_con_iva_final    // antes: subtotal_con_iva_linea
  subtotal_sin_descuento    = Σ subtotal_linea          // sin cambios
  subtotal_con_descuento    = Σ subtotal_final           // antes: subtotalSinDescuento - descuento
  descuento                 = subtotal_sin_descuento - subtotal_con_descuento
  total                     = Σ subtotal_con_iva_final + Σ conceptos.monto   // antes: Σ
                                                                                 subtotal_con_iva_linea
                                                                                 - descuento + conceptos
```

## Reglas de validación (sin cambios)

No se agregan reglas de validación nuevas — esta spec corrige un cálculo, no agrega restricciones de
entrada. Las precondiciones de ARCA (spec 042: alícuota soportada, tolerancia $0.01, Condición de IVA
del cliente) siguen viviendo en `ValidadorDatosFiscales`, sin cambios.
