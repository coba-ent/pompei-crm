# Research: Fidelidad del Informe de Ventas contra Contagram (spec 076)

**Fecha**: 2026-08-24

---

## R1. El importe por línea ya existe en el motor — y ya sabíamos que era lo correcto

**Decisión**: la pantalla, el export resumen y el PDF pasan a mostrar la columna `total_venta` que
la proyección **ya calcula**, en lugar de `total_comprobante`.

**Fundamento**: `VentasInformeQuery::proyeccion()` emite las dos columnas. Y el comentario que
acompaña a `total_venta`, escrito durante la spec 069 para el motor de tablas dinámicas, dice
textualmente:

> *"Importe de la línea CON impuestos, que es la medida 'Total Venta' del pivot. No se usa
> `total_comprobante`: ese se repite en cada línea y sumarlo lo contaría una vez por ítem."*

O sea: **el proyecto ya había llegado a la conclusión correcta**, pero sólo la aplicó al pivot. La
pantalla, el export y el PDF se quedaron con la columna vieja, y la documentación de dominio se
quedó con la afirmación vieja ("repetido por fila, no sumable"). Esta spec termina de aplicar lo
que ya estaba resuelto.

**Consecuencia práctica**: el grueso de la User Story 1 no es cálculo nuevo, es cambiar qué columna
se lee en cuatro lugares. El riesgo real no está ahí, está en R2.

**Alternativas descartadas**:
- *Calcular el prorrateo en el cliente*: rompe el orden y el filtrado en SQL de DataTables, y
  dejaría el Excel y el PDF con otro número.
- *Dejar las dos columnas visibles*: Contagram tiene una sola, y agregar una columna que no existe
  viola el principio rector de fidelidad estructural.

---

## R2. `total_venta` hoy NO incluye los conceptos extra — hay que verificar si cierra

**Decisión**: antes de cambiar nada, **medir** si `SUM(total_venta)` por comprobante iguala
`ventas.total` sobre datos reales, y sólo agregar el prorrateo de conceptos si no cierra.

**Fundamento**: `total_venta` se calcula como `venta_items.subtotal * (1 + iva_pct / 100)`. Eso
cubre el neto con los dos descuentos ya aplicados (de línea y general, porque `subtotal` los trae
incorporados) más el IVA de la línea. Lo que **no** cubre son los conceptos extra del comprobante,
que viven en `venta_conceptos` (`tipo` ∈ percepción / impuesto interno / interés) y se suman a
`ventas.total` fuera de las líneas.

Entonces:

- En una venta **sin** conceptos extra, la suma debería cerrar exacta.
- En una venta **con** conceptos, la suma va a quedar corta por el monto de los conceptos.

El requisito FR-002 exige que cierre siempre. Y la evidencia del export real de Contagram lo
respalda: en el archivo detallado, las columnas `Perc. IVA`, `Perc. IIBB` e `Imp. Internos` existen
**a nivel línea**, así que Contagram efectivamente reparte esos conceptos entre las líneas.

**Criterio de prorrateo elegido** (Clarifications de la spec): **proporcional al neto de cada
línea**. Es el mismo criterio que `CalculoComprobante` ya usa para repartir un descuento general
cargado como monto fijo, así que el sistema no estrena una regla de reparto: reusa la que ya tiene.

**Riesgo de redondeo**: repartir un monto entre N líneas con `round(, 2)` puede dejar una
diferencia de centavos contra el total. Hay que decidir dónde se absorbe. **Decisión**: la última
línea del comprobante absorbe el residuo, que es el mismo patrón que ya usan los conversores de
Mercado Libre y Tiendanube para conciliar el total de la orden al centavo. Así FR-002 se cumple
exactamente y no "casi".

**Alternativas descartadas**:
- *Prorratear por cantidad de líneas (partes iguales)*: no reproduce a Contagram, cuyo `Perc. IVA`
  por línea es claramente proporcional al importe.
- *Dejar los conceptos fuera y aceptar que no cierre*: incumple FR-002 y reintroduce el problema
  que la spec vino a resolver.

---

## R3. Las columnas que faltan para el detallado, y de dónde salen

**Decisión**: las 44 columnas se resuelven **extendiendo la proyección existente**, no con una
consulta nueva.

**Fundamento**: FR-013 exige que el detallado use el mismo motor que la pantalla, para que los
totales coincidan. La proyección ya emite 12 de las 44 columnas para el detalle y otras 8 como
técnicas o dimensiones del pivot. El mapeo del resto:

| Grupo | Columnas | Origen |
|---|---|---|
| Ya en la proyección | Id, Emisión, Cliente, Cantidad, Precio Unitario, Costo Total Actual, CMV Total, Precio de Venta, Resultado, Total Venta, Categoría, Vendedor, Tipo (de producto), Proveedor, Etiquetas | sin trabajo |
| Joins ya presentes | Código (`productos.codigo`) | agregar a la proyección |
| Joins nuevos, uno-a-uno | CUIT / DNI (cliente), Lista de Precios, Nota para el Cliente, Nota Interna, Vencimiento, Afecta Stock | `LEFT JOIN` |
| Comprobante fiscal | ARCA, Punto de Venta, N° Factura | `LEFT JOIN` polimórfico a `comprobantes_fiscales` |
| Desglose impositivo | Subtotal sin/con Descuento, Descuento en $, los 3 netos, las 5 alícuotas de IVA, Exento, No Gravado | derivadas de `venta_items` + `iva_pct` |
| Conceptos | Perc. IVA, Perc. IIBB, Imp. Internos | de `venta_conceptos`, prorrateados (R2) |

**El join a `comprobantes_fiscales` es el único delicado**: es polimórfico
(`comprobantable_type` / `comprobantable_id`), tiene `deleted_at`, y una venta puede tener **más de
un** comprobante fiscal a lo largo del tiempo (un rechazo y un reintento aprobado). Un `LEFT JOIN`
directo multiplicaría filas y **rompería todos los totales del informe**, que es exactamente el
problema que la proyección ya resuelve para las etiquetas con una subconsulta. **Decisión**: mismo
patrón que las etiquetas — subconsulta que devuelve una sola fila (el comprobante vigente), nunca
un join directo.

**Valores de las columnas nuevas**, tomados del archivo real y no inventados: ARCA es `Aprobado`,
`Sin Enviar` o `---`; Afecta Stock es `Si` / `No`; Punto de Venta y N° Factura son `-` cuando no hay
comprobante emitido; Lista de Precios queda vacía si la venta no tiene lista.

---

## R4. El desglose impositivo se deriva, no se guarda

**Decisión**: las columnas de neto por condición y de IVA por alícuota se calculan en SQL desde
`venta_items.iva_pct`, sin agregar columnas a la base.

**Fundamento**: `venta_items` guarda `iva_pct` como texto, que puede ser un porcentaje (`'21'`,
`'10.5'`) o una condición (`'exento'`, `'no_gravado'`). Con eso alcanza para imputar cada línea a
una sola columna de neto y a una sola de alícuota (FR-011):

- `iva_pct = 'exento'` → el neto va a *Importe Neto Exento*, y también a la columna *Exento*.
- `iva_pct = 'no_gravado'` → a *Importe Neto No Gravado* y a *No Gravado*.
- `iva_pct` numérico → a *Importe Neto Gravado*, y el IVA calculado a la columna de esa alícuota.

Se verificó contra el archivo real: en una línea al 21% con neto 2.700, la columna *IVA - 21%*
trae 567 y las otras cuatro cero; una línea no gravada de 13.423,50 tiene ese importe en *Importe
Neto No Gravado* y cero IVA. El `Total Venta` de esas líneas es 3.267 y 13.423,50 respectivamente,
que confirma la fórmula de R2.

**Alternativas descartadas**: *guardar el desglose en columnas nuevas de `venta_items`* — sería
duplicar información derivable y agregar deuda de sincronización, sin beneficio: el informe no
tiene un problema de performance que lo justifique.

---

## R5. El test de la spec 068 que hay que dar vuelta

**Decisión**: se modifica `test_total_comprobante_se_repite_en_cada_fila_de_la_misma_venta` en
`tests/Feature/Informes/InformeVentasTest.php`, y se deja escrito en el propio test por qué cambió.

**Fundamento**: ese test afirma hoy lo contrario de lo que hace Contagram, y su comentario lo llama
*"la trampa principal del informe"*. Borrarlo en silencio sería perder la única traza de que alguna
vez creímos eso. Se reemplaza por su inverso —los importes de línea de una venta suman su total— con
una nota que cite la evidencia (la captura del 24/08/2026, venta 23501 con 12 líneas).

Es el mismo procedimiento que siguió la spec 075 con el docblock de `CostoMercaderiaVendida`, y por
el mismo motivo: un error de premisa documentado se corrige dejando rastro, no borrándolo.

---

## R6. Qué NO se toca

- **`InformeVentasExport` sigue con dos hojas.** Es una divergencia deliberada del módulo, ya
  registrada. La única corrección que recibe es la del importe de línea y la sigla del comprobante.
- **El export detallado sí tiene una sola hoja**, porque así lo tiene Contagram y porque es un
  archivo nuevo: no hay coherencia previa que romper.
- **La réplica R1 del Resultado en las notas de crédito se conserva** en la hoja legible del export
  resumen. Esta spec no la revisa.
- **El Informe de Compras queda fuera**, aunque probablemente comparta el defecto de importe por
  línea. Si al implementar se confirma, se registra como brecha y se trata aparte.

---

## R7. Riesgo principal: el pivot ya consume `total_venta`

**Decisión**: no se renombra ni se cambia la semántica de `total_venta`; sólo se agregan
consumidores.

**Fundamento**: el motor de tablas dinámicas de la spec 069 usa `total_venta` como su medida "Total
Venta", y `total_comprobante` sigue siendo necesario para otros usos (por ejemplo, mostrar el total
del comprobante en un tooltip o en el PDF de una venta puntual). Tocar la definición de cualquiera
de las dos para "arreglar" la pantalla rompería el pivot, que hoy está bien.

La regla para la implementación: **agregar el prorrateo de conceptos a `total_venta` mejora también
al pivot** (hoy su medida tampoco incluye los conceptos), así que el cambio va en la dirección
correcta para los dos consumidores. Hay que verificar que los tests del pivot sigan verdes y
actualizar sus valores esperados si el prorrateo los mueve.
