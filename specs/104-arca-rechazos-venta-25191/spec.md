# La Factura A vuelve a salir: IVA que cierra y Condición de IVA que ARCA acepta

**Spec**: 104 | **Fecha**: 2026-09-18 | **Estado**: listo para planificar

## El problema

La venta **25191** (FLORDANA S R L, Factura A, $2.049.281,54) no se puede emitir. Da dos rechazos
distintos, y hay que resolver los dos: arreglar uno solo la deja igual de trabada.

1. **ARCA, error 10243**: *"El campo Condicion IVA receptor no es valido para la clase de
   comprobante informado"*.
2. **Validación del CRM**: *"El IVA calculado no coincide con la suma por alícuota"*.

## Problema 1 — El IVA se va un centavo por línea

### Lo medido, no supuesto

La venta tiene 15% de descuento general y cuatro líneas. El IVA que el CRM guardó y el que sale de
multiplicar el neto final por 21% no coinciden:

| Ítem | Neto final | 21% del neto | IVA guardado | |
|---|---|---|---|---|
| 38196 | 395.394,38 | 83.032,82 | 83.032,82 | ✓ |
| 38197 | 210.640,64 | 44.234,53 | 44.234,**54** | +0,01 |
| 38198 | 754.178,68 | 158.377,52 | 158.377,**53** | +0,01 |
| 38199 | 333.407,39 | 70.015,55 | 70.015,**56** | +0,01 |

Suma por línea: **355.660,42**. Guardado: **355.660,45**. La tolerancia del validador es **$0,01**,
así que rebota.

### La causa

En `CalculoComprobante::calcular()` cada línea se redondea **dos veces por caminos separados**:

```php
$subtotalLinea      = round($bruto - ($bruto * $descuentoPct / 100), 2);
$subtotalConIvaLinea = round($subtotalLinea + ($subtotalLinea * $ivaPct / 100), 2);

$subtotalFinal       = round($subtotalLinea * $factor, 2);        // neto con desc. general
$subtotalConIvaFinal = round($subtotalConIvaLinea * $factor, 2);  // ← redondea el con-IVA aparte
```

El neto y el neto-con-IVA se calculan cada uno por su lado y **después** se les aplica el factor de
descuento general. Los dos redondeos independientes no vuelven a encontrarse: el IVA implícito
(`subtotal_con_iva − subtotal`) queda un centavo arriba del que sale del neto ya descontado.

Reproducido en aislado con los números reales de la venta: da exactamente los mismos centavos.

**No es un dato mal cargado.** Es aritmética del CRM, y le pega a cualquier Factura A con descuento
general y varias líneas.

### Lo que el alcance descartó

- **521 ventas** tienen hoy este desvío.
- **511 son migradas** de Contagram: el dato refleja lo que Contagram tenía.
- **10 nacieron en el CRM**, y **6 después del 13/08/2026** — el bug está vivo, no es herencia.

## Problema 2 — La Condición de IVA del receptor

FLORDANA es **Responsable Inscripto** (`condiciones_iva.id=1`, `codigo_afip='1'`), que es lo
correcto para una Factura A. El mapeo local se ve bien: `resolverCondicionIvaReceptor()` toma el
`codigo_afip` del cliente y lo manda como `CondicionIVAReceptorId`.

Pero el rechazo viene **de ARCA**, no del CRM: es el error 10243 y lo tiró el servicio. Así que el
código que mandamos no es el que ARCA espera **para clase A**, o la tabla cambió.

**Esto todavía no está diagnosticado.** El propio mensaje dice qué hacer: consultar
`FEParamGetCondicionIvaReceptor`. Esa consulta es parte del trabajo de esta spec, no un supuesto
que se pueda dar por resuelto de antemano.

Dato de contexto: los otros cuatro códigos de la tabla (`6` Monotributista, `5` Consumidor Final,
`4` Exento, `7` No Categorizado) se verifican en la misma pasada — si la tabla de ARCA cambió, no
hay razón para pensar que sólo cambió la fila 1.

## Requisitos funcionales

### El IVA

- **FR-001** El IVA implícito de cada línea (`subtotal_con_iva − subtotal`) debe coincidir con
  `round(subtotal × alícuota / 100, 2)`, **con o sin descuento general**.
- **FR-002** `subtotal_con_iva` se **deriva del neto final ya descontado**, no se redondea por un
  camino propio.
- **FR-003** Vale para **todas** las alícuotas (10,5%, 21%, 27%, 5%, 2,5%), no sólo 21%.
- **FR-004** El total del comprobante sigue siendo la suma de los `subtotal_con_iva` de sus líneas
  más los conceptos extra. Puede moverse **centavos** respecto del cálculo viejo: es el precio de
  que cierre.
- **FR-005** Aplica a Venta, Presupuesto y Compra — los tres pasan por el mismo
  `CalculoComprobante`. Alcanza también a las ventas que nacen de **Mercado Libre y Tiendanube**,
  que convierten sus órdenes por el mismo servicio.
- **FR-005a** Las **Notas de Crédito/Débito quedan fuera**: `nota_credito_debito_items` no tiene la
  columna `subtotal_con_iva` (guarda `precio`, `cantidad`, `iva_pct` y calcula el IVA por alícuota
  al consultarse). No las toca este cambio, y hay que verificar que siga siendo así.

### Las 521 existentes

- **FR-006** **No se tocan.** Ni las 511 migradas ni las 10 del CRM. Decisión del usuario: las
  migradas reflejan lo que Contagram tenía y varias ya están facturadas.
- **FR-006a** El arreglo corrige **de acá en adelante**: ventas nuevas y ediciones que recalculen.
- **FR-006b** Una venta vieja que se edite queda corregida por el recálculo, como efecto natural. No
  se fuerza ningún barrido.

### La Condición de IVA

- **FR-007** Se consulta `FEParamGetCondicionIvaReceptor` **contra el ARCA de producción** y se
  documenta la tabla real: código, descripción y para qué clase de comprobante aplica cada uno.
- **FR-008** El mapeo de `condiciones_iva.codigo_afip` se corrige contra esa tabla, **las cinco
  filas**, no sólo la de FLORDANA.
- **FR-009** Si un código no aplica a la clase de comprobante que se está emitiendo, el CRM lo
  detecta **antes** de enviar y lo dice en castellano, nombrando cliente y condición — no se manda
  para que ARCA lo rechace.

### Lo que no cambia

- **FR-010** El redondeo a 2 decimales sigue siendo el criterio: no se guardan más decimales.
- **FR-011** El descuento general sigue calculándose igual (porcentaje o monto convertido a
  porcentaje). Lo que cambia es **de dónde sale el IVA**, no el descuento.

## Criterios de éxito

- **SC-001** La venta 25191 se emite y obtiene CAE.
- **SC-002** Una Factura A nueva con descuento general y varias líneas pasa la validación: el IVA
  por alícuota cierra exactamente.
- **SC-003** Las 521 ventas existentes quedan **exactamente como están** — mismo total, mismo IVA.
- **SC-004** Ninguna venta creada después del fix aparece con desvío. La consulta que hoy devuelve
  521 no debe crecer.
- **SC-005** Las cinco condiciones de IVA quedan verificadas contra la tabla real de ARCA.

## Casos de borde

| Caso | Tratamiento |
|---|---|
| Sin descuento general (factor = 1) | El resultado no cambia: hoy ya cierra |
| Descuento general en monto | Se convierte a porcentaje antes; mismo tratamiento |
| Descuento por línea + general | Los dos se aplican al neto; el IVA sale del neto final |
| Alícuota 0% / exento | IVA cero; `subtotal_con_iva` = `subtotal` |
| Línea con importe negativo | Misma fórmula; el signo se conserva |
| Venta vieja que se edita | Se recalcula y queda corregida (FR-006b) |
| Cliente sin condición de IVA | Ya se rechaza hoy con mensaje propio; no cambia |

## Riesgo

El cálculo de totales es **el núcleo del sistema**: lo tocan Venta, Compra, Presupuesto y Notas, y
alimenta el Libro IVA, los informes y la cuenta corriente, que ya concilian peso por peso contra
Contagram.

El cambio mueve **centavos** en comprobantes nuevos con descuento general. Eso es deseado —es lo que
hace que ARCA acepte— pero obliga a verificar que ningún informe que hoy cierra se descuadre. La
mitigación es FR-006: no se reescribe nada histórico, así que lo ya conciliado no se mueve.
