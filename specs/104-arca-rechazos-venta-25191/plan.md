# Plan técnico — spec 104

## Enfoque

Dos arreglos independientes que comparten una sola venta bloqueada. El del IVA es **una línea** en
un servicio que toca todo el sistema; el de la Condición de IVA es sobre todo **averiguar** —no hay
nada que diseñar hasta saber qué responde ARCA.

## 1. El IVA — la línea que cambia

En `app/Services/Ingresos/CalculoComprobante.php`:

```php
// HOY: el con-IVA se redondea por su cuenta y después se le aplica el factor
$subtotalConIvaLinea = round($subtotalLinea + ($subtotalLinea * $ivaPct / 100), 2);
$subtotalFinal       = round($subtotalLinea * $factor, 2);
$subtotalConIvaFinal = round($subtotalConIvaLinea * $factor, 2);

// QUEDA: el con-IVA se deriva del neto que realmente se guarda
$subtotalFinal       = round($subtotalLinea * $factor, 2);
$subtotalConIvaFinal = round($subtotalFinal + ($subtotalFinal * $ivaPct / 100), 2);
```

`$subtotalConIvaLinea` deja de existir como paso intermedio.

**Por qué esto cierra y lo de hoy no**: el validador de ARCA recalcula el IVA como
`round(neto × alícuota, 2)` sobre el neto **que se le declara**. Si el `subtotal_con_iva` se deriva
de ese mismo neto, las dos cuentas son la misma cuenta. Hoy salen de dos redondeos distintos, y
ningún ajuste de tolerancia cambia eso — sólo lo tapa.

**Alcance real del cambio** (verificado con grep, no supuesto): `CalculoComprobante` lo usan
`VentaController`, `CompraController`, `PresupuestoController` y **los dos conversores de órdenes**
—Mercado Libre y Tiendanube—, que crean ventas por el mismo camino. Es un cambio de una línea con
superficie grande.

Las **Notas de Crédito/Débito no entran**: su tabla de ítems no tiene `subtotal_con_iva`. El IVA se
deriva al consultarse, así que el cambio no las alcanza (FR-005a).

## 2. La Condición de IVA — primero averiguar

**No hay diseño hasta tener el dato.** El orden es:

1. Consultar `FEParamGetCondicionIvaReceptor` contra el ARCA de **producción**, con el certificado
   real. Es un método de consulta: no emite nada.
2. Volcar la respuesta a `contracts/condiciones-iva-arca.md` — código, descripción y clase de
   comprobante de cada fila.
3. Comparar con las cinco filas de `condiciones_iva` y corregir las que no coincidan.
4. Recién ahí decidir si hace falta validación previa (FR-009) y con qué criterio.

Hipótesis a **confirmar o descartar**, no a asumir: ARCA distingue qué condiciones son válidas
según la clase del comprobante (A/B/C), y una Factura A a un Responsable Inscripto debería aceptar
el código 1. Que lo rechace sugiere que el código correcto es otro o que el campo viaja con un
formato que ARCA no toma. El log de la respuesta cruda lo va a decir.

## Qué NO se toca

- Las 521 ventas existentes (FR-006).
- El cálculo del descuento general.
- El redondeo a 2 decimales.
- `ValidadorDatosFiscales`: su tolerancia de $0,01 **se deja como está**. Es la que detectó este
  bug; ampliarla sería apagar el detector.

## Orden de trabajo

1. Test que reproduce la 25191: 4 líneas, 15% general, 21% → hoy falla.
2. El cambio de la línea.
3. Test de todas las alícuotas (FR-003).
4. Test de los cuatro comprobantes (FR-005).
5. Verificar que las 521 no se movieron (FR-006/SC-003).
6. Consultar ARCA y documentar la tabla.
7. Corregir el mapeo.
8. La 25191, contra ARCA real.

## Riesgos

| Riesgo | Mitigación |
|---|---|
| **Descuadrar informes que hoy cierran** | No se reescribe nada histórico; se verifica Libro IVA y Cta Cte antes/después |
| Romper Compra/Presupuesto/Notas | FR-005 con test propio por comprobante |
| Que el fix del IVA no alcance y ARCA siga rechazando | Se prueba contra ARCA real antes de dar por cerrado |
| Suponer el código de Condición de IVA | FR-007: se consulta, no se deduce |

## Verificación

**Antes de tocar**: guardar el conteo de 521 y el total del Libro IVA del período.
**Después**: los dos idénticos, y una venta nueva con descuento general que cierre exacta.

La 25191 es la prueba final: tiene que salir con CAE.
