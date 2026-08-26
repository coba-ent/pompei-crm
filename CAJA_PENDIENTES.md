# Caja — pendientes y dudas al 26/08/2026

Para la puesta al día. Sólo lo que **falta resolver**: lo que hay que corregir y lo que hay que
decidir. Lo ya cerrado está al final, en dos líneas, para no volver a mirarlo.

Cada número está verificado contra la base de producción y contra el export de Contagram.

---

## 0 · FOTO AL 26/08 — todas las cuentas, las dos pantallas

Comparación cuenta por cuenta de las dos pantallas de Tesorería del mismo día. Es el punto de
partida: lo que no está acá, coincide.

```
                            Contagram              CRM          diferencia
A COBRAR
  Saldo Cta Cte Clientes   9.859.324,61     9.505.935,79      −353.388,82   ⚠ NUEVO
  Mastercard a Cobrar       −175.928,19      −193.928,20       −18.000,01   ver 1.1
  Amex                       227.959,76       227.959,76             0,00
  PAYWAY QR                  448.172,61       448.172,60             0,01   resuelto
  Cabal / Maestro / Nulo         =                =                  0,00
  TOTAL                   15.952.324,55    15.584.401,00      −367.923,55

A PAGAR
  Cta Cte Proveedores     17.490.974,77    17.490.974,53            −0,24   ✔ cuadra
  Cheque Propio            1.652.957,43    −1.592.976,19    −3.245.933,62   ver 2.1
  Cabal Credicoop            212.175,83       212.175,83             0,00
  Visa Credicoop             174.574,63       174.574,63             0,00
  TOTAL                   19.530.682,66    16.284.748,80    −3.245.933,86

CAJAS
  Caja del Local             600.143,15       681.199,44       +81.056,29   ⚠ NUEVO
  Caja chica gastos           33.137,66        33.137,66             0,00
  Juan USD Personal          850.600,00       850.600,00             0,00
  Caja General Abajo               0,00             0,00             0,00
  TOTAL                    1.483.880,81     1.564.937,10       +81.056,29

BANCOS
  Banco Credicoop          3.206.795,98     1.194.012,41    −2.012.783,57   ⚠ NUEVO
  Banco Galicia            2.191.145,00       −22.835,56    −2.213.980,56   ⚠ NUEVO
  Mercado Pago            19.527.812,25    21.926.276,47    +2.398.464,22   ⚠ NUEVO
  USD Online               3.991.824,57     3.991.824,57             0,00
  Banco Santander Río         −9.147,72        −9.147,72             0,00
  TOTAL                   28.908.430,08    27.080.130,17    −1.828.299,91
```

### Lo que cuadra y ya no hay que mirar

**Cuenta Corriente de Proveedores: $0,24 de diferencia** sobre $17,5 millones. Está al día.

También Amex, Cabal, Maestro, Nulo, USD Online, Santander, Caja chica, Juan USD y Caja General
Abajo: **cero diferencia**. Y PAYWAY quedó en un centavo después de la corrección de anoche.

### Los tres bloques que faltan diagnosticar

**Galicia y Credicoop ya están diagnosticados** — ver 2.4 y 2.5. Los dos cierran al centavo.

**Mercado Pago: +$2.398.464,22, todavía sin diagnosticar.** Falta el export de movimientos. Hay una
hipótesis fuerte, ver 2.4.

**Cuenta Corriente de Clientes: −$353.388,82 — y NO viene del período comparado.** Ver 3.3.

**Caja del Local: +$81.056,29.** El CRM tiene más. No estaba en el análisis de anoche.

---

## 1 · HAY QUE DECIDIR — cuál de los dos sistemas tiene razón

Estos no se pueden resolver desde el CRM: hace falta mirar el extracto del banco o el comprobante.

### 1.1 · Mastercard — transferencia del 21/08 con los dígitos dados vuelta

```
Mastercard a Cobrar
  CRM         −$193.928,20
  Contagram   −$175.928,19
  diferencia    $18.000,01
```

La diferencia viene de una sola transferencia:

```
21/08   de Mastercard a Banco Galicia
  CRM         −$264.676,77
  Contagram   −$246.676,77
```

**264 / 246** — los dígitos están transpuestos. La diferencia es exactamente $18.000,00, que es lo
que da dar vuelta esas dos cifras. Uno de los dos tiene un error de tipeo, **y no sabemos cuál**.

La transferencia del CRM está **balanceada** (las dos patas dicen 264.676,77), así que no es el bug
que se arregló ayer: es el importe con el que se cargó. Creada el **24/08 12:44:57 por
Pompei1sanitarios@gmail.com**.

**Cómo saldarlo**: mirar la liquidación de Mastercard del 21/08, o el extracto de Banco Galicia. El
que coincida con el banco es el correcto.

**Si el correcto es $246.676,77** (Contagram): corregir la transferencia en el CRM. Al editarla desde
la pantalla ahora se actualizan las dos cuentas solas.
**Si el correcto es $264.676,77** (CRM): corregirlo en Contagram.

⚠️ En cualquier caso **arrastra al Banco Galicia**: hoy tiene $18.000 de diferencia por el mismo motivo.

*(Queda además $0,01 de diferencia en el cobro de vicente 1136123337 del 21/08 — el CRM dice
$311.062,14 y Contagram $311.062,15. El del CRM coincide exacto con el total de la venta. No vale la
pena tocarlo.)*

### 1.2 · Venta 24624 — anulada en el CRM, cobrada en Contagram

```
$98.212,40
```

```
CRM         venta 24624   FLORENCIA 1159751732   ANULADA 21/08 15:52 por "Juan"
Contagram   venta #24630  Freddy 1124594187      VIGENTE  y COBRADA (Mercado Pago)
```

⚠️ **Los clientes no coinciden**: FLORENCIA en el CRM, Freddy en Contagram. Mismo importe, misma
fecha. Hay que definir de quién era la operación antes de tocar nada.

**Cómo saldarlo**: ver el comprobante y el pago de Mercado Libre. Si la anulación es correcta, anular
también en Contagram con su cobro. Si no, revertirla en el CRM.

---

## 2 · HAY QUE CORREGIR — el diagnóstico ya está cerrado

### 2.1 · Cheque Propio — una transferencia cargada donde iba un pago

```
Cheque Propio
  Contagram   $1.652.957,43
  CRM         $1.592.976,19
  diferencia  $3.245.933,62      ← el más grande de todos
```

Los dos sistemas coinciden en el pago del 24/07 y en el movimiento del 28/07. Difieren en el tercero:

```
25/08
  Contagram   PAGO a Peisa, factura A 0057-61702        −$1.622.957,12    (salida)
  CRM         MOVIMIENTO ENTRE CUENTAS desde Credicoop  +$1.622.976,50    (entrada)
```

Se cargó una **entrada** donde iba una **salida**. Por eso la diferencia es casi el doble del importe.

**Verificación:**

```
CRM hoy                                          −$1.592.976,19
−$1.622.976,50  (sacar el ingreso mal cargado)
−$1.622.957,12  (poner el pago que falta)
                                                 ─────────────
                                                 −$1.652.957,43   =  Contagram ✔
```

**Qué hacer:**

1. Anular la transferencia del 25/08 — **las dos patas**: `#49022` (Banco Credicoop) y `#49023`
   (Cheque Propio).
2. Cargar el pago: **Peisa**, factura **A 0057-00061702**, **$1.622.957,12**, fecha **25/08/2026**,
   cuenta **Cheque Propio**.
3. Verificar el **Banco Credicoop**: tiene que subir $1.622.976,50.

⚠️ El importe **no es el mismo** que el pago de julio: son **$19,38 menos**. No copiar el anterior.

Creado el 25/08 12:16:37 por Pompei1sanitarios@gmail.com.

### 2.2 · Cobro anulado en el CRM que en Contagram sigue vigente

```
$27.306,00
```

```
CRM         cobro 27674   venta 24608   FLORENCIA   Visa   ANULADO 21/08 15:16
Contagram   cobro #26429  FLORENCIA     Visa   VIGENTE (20/08)
```

La venta existe en los dos; el cobro se anuló sólo en el CRM. **Contagram muestra $27.306,00 cobrados
que el CRM da por impagos.**

**Qué hacer**: anular el cobro en Contagram, o recrearlo en el CRM.

### 2.3 · Cobro que el CRM tiene y Contagram no

```
$30.771,29
```

```
CRM         cobro 27673   ANULADO 20/08   +   cobro 27688   VIGENTE   (venta 24582, FLORENCIA, Visa)
Contagram   venta #24579  FLORENCIA  $30.771,29  sin ningún cobro
```

En el CRM se anuló y se volvió a cargar. En Contagram nunca se registró.

**Qué hacer**: cargar el cobro en Contagram — $30.771,29, Visa, sobre la venta de FLORENCIA del 19/08.

---

### 2.4 · Banco Galicia — tres pagos a Ferrum que sólo tiene el CRM

```
Banco Galicia
  Contagram    $2.191.145,00
  CRM            −$22.835,56
  diferencia  −$2.213.980,56
```

Cruzando los 96 movimientos del export contra los 98 del CRM, la diferencia sale de dos cosas y
**cierra exacta**:

```
25/08   tres pagos a Ferrum que el CRM tiene y Contagram no
          #49047    −$526.036,05
          #49048     −$44.215,06
          #49049  −$1.661.729,45
                  ─────────────
                  −$2.231.980,56

21/08   Mastercard con los dígitos transpuestos (ver 1.1)
                     +$18.000,00
                  ─────────────
verificación      −$2.213.980,56   = la diferencia ✔
```

Los tres pagos se cargaron el **25/08 20:51 por Pompei1sanitarios@gmail.com**.

⚠️ **Hipótesis fuerte, y explicaría también Mercado Pago**: la Cuenta Corriente de **Proveedores
cuadra** entre los dos sistemas ($0,24 de diferencia). Si el CRM tuviera $2,2 millones más de pagos a
Ferrum, proveedores tendría que diferir en ese monto. Como no difiere, **lo más probable es que
Contagram sí tenga esos pagos, pero cargados desde otra cuenta** — casi seguro Mercado Pago, que es
justamente donde el CRM tiene $2.398.464,22 de más.

```
diferencia de Mercado Pago    $2.398.464,22
menos los pagos a Ferrum      $2.231.980,56
                              ─────────────
quedaría sin explicar           $166.483,66
```

**Qué hacer**: confirmar desde qué cuenta se pagó a Ferrum el 25/08. Si en Contagram salió de Mercado
Pago y en el CRM de Galicia, no falta ningún movimiento: **están en la cuenta equivocada en uno de los
dos**, y hay que moverlos.

*(El 13/08 aparece otra diferencia que NO es tal: Contagram tiene la liquidación de Visa en dos
líneas —$117.136,72 y $848.213,40— y el CRM en una sola de $965.350,12. Misma plata, distinto corte.)*

### 2.5 · Banco Credicoop — cuatro movimientos que sólo tiene el CRM

```
Banco Credicoop
  Contagram    $3.206.795,98
  CRM          $1.194.012,41
  diferencia  −$2.012.783,57
```

Contagram **no tiene ningún movimiento que le falte al CRM**. Los cuatro que sobran del lado del CRM
suman exactamente la diferencia:

```
25/08   #49022   movimiento entre cuentas → Cheque Propio   −$1.622.976,50   ← es el caso 2.1
25/08   #49024   pago a "Contagram"                           −$384.659,00
24/08   #49021   gasto Ley 25413                                 −$4.548,07
25/08   #49025   gasto Otros                                       −$600,00
                                                             ─────────────
                                                             −$2.012.783,57   = la diferencia ✔
```

**El más grande ya está contemplado**: es la transferencia mal cargada del caso 2.1. Al anularla,
Credicoop sube $1.622.976,50 solo.

**Quedan $389.807,07** en tres movimientos que el CRM tiene y Contagram no:

- **el pago a "Contagram" de $384.659,00** — parece la suscripción al sistema
- Ley 25413 (impuesto al cheque) $4.548,07
- Otros $600,00

**Qué hacer**: cargarlos en Contagram, o confirmar que allá están en otra cuenta.

---

## 3 · A VERIFICAR — puede que no sea nada

### 3.1 · Tres cobros del CRM que no están en Contagram

```
$105.033,53
```

```
cobro 27701   venta 24635   CONCIMAT SACIFYM     Mercado Pago   $50.090,97   21/08
cobro 27753   venta 24604   Carlos 1153161973    Mercado Pago   $49.623,40   24/08
cobro 27761   venta 24696   INES 1140948619      Mercado Pago    $5.319,16   25/08
```

Verificado contra los seis informes de Contagram del 26/08: **ninguno de los tres importes aparece**.

En el caso de INES, Contagram **sí tiene la venta** (#24691, $5.319,16 del 25/08) pero **sin el cobro**
— igual que el caso 2.3.

⚠️ El de CONCIMAT tiene historia: se cargó como $48.121,43, se corrigió a $50.090,00 medio minuto
después, y dos horas más tarde **otro usuario** lo ajustó a $50.090,97. Además el movimiento de
tesorería dice **"Freddy 1124594187"** como detalle, que no es el cliente de esa venta.

### 3.3 · Cuenta Corriente de Clientes — la diferencia es vieja, no de esta semana

```
Contagram    $9.859.324,61
CRM          $9.505.935,79
diferencia    −$353.388,82
```

Se cruzaron los seis informes del 26/08 (18 al 25/08) contra producción, movimiento por movimiento:

```
VENTAS   181 en Contagram   →   0 faltan en el CRM        ✔
COBROS   181 en Contagram   →   2 faltan en el CRM        $125.518,40   (casos 1.2 y 2.2)
                                4 faltan en Contagram     $135.804,82   (casos 2.3 y 3.1)
```

**Efecto neto sobre el saldo: apenas $10.286,42.**

```
diferencia real            $353.388,82
menos lo del período        $10.286,42
                           ───────────
sin explicar               $343.102,40   ← es de ANTES del 18/08
```

El saldo de cuenta corriente es **acumulado desde siempre**, no del período. Comparar sólo la última
semana nunca iba a explicarlo: los movimientos de estos ocho días están prácticamente al día entre
los dos sistemas.

**Qué hacer**: para ubicar los $343.102,40 hay que pedir informes de cuenta corriente de **períodos
anteriores** e ir cerrando por corte, como se hizo en `PENDIENTE REGULARIZAR.txt` — que documenta
descuadres del mismo tipo en abril y julio, con la misma causa: ventas viejas editadas en el CRM y no
replicadas en Contagram.

⚠️ **Trampa del método, ya pisada dos veces**: comparar por importe dentro de una ventana fija da
falsos faltantes. Una venta de Mercado Libre puede estar fechada distinto en cada sistema —el id del
CRM suele ser el de Contagram **+1**— y hay muchos importes repetidos ($276.577,07 aparece tres veces
en Contagram, $253.464,19 cinco veces). **Antes de reportar un faltante hay que buscar el importe en
todo el export, no sólo en la ventana.**

---

### 3.2 · El detalle "Freddy 1124594187" aparece en clientes que no son él

Se repite en varios movimientos de Mercado Pago de esta semana, sobre ventas de **CONCIMAT** y de
**FLORENCIA**. No existe ningún cliente llamado Freddy en el sistema.

Puede ser el nombre de quien efectivamente transfirió, o un dato que se está arrastrando mal de un
cobro a otro. **Si es lo segundo, afecta a más movimientos de los que vimos.**

---

## 4 · Saldos del CRM al 26/08 — para cotejar mañana

```
A COBRAR                              A PAGAR
  Visa a Cobrar        5.587.012,76     Cheque Propio          1.592.976,19  ⚠ ver 2.1
  PAYWAY QR a Cobrar     448.172,60     Cabal Credicoop         −212.175,83
  AMEX                   227.959,76     Visa Credicoop          −174.574,63
  Retenciones              9.248,17
  Nulo a Cobrar                0,13   BANCOS
  Cabal Acreditaciones         0,00     Mercado Pago          21.926.276,47
  Maestro                      0,00     USD Online             3.991.824,57
  Cheque de Terceros          −0,01     Banco Credicoop        1.194.012,41  ⚠ ver 2.1
  Mastercard a Cobrar   −193.928,20  ⚠  Banco Galicia            −22.835,56  ⚠ ver 1.1
                                        Banco Santander Río       −9.147,72
EFECTIVO
  Juan USD Personal      850.600,00
  Caja del Local         681.199,44
  Caja chica gastos       33.137,66
  Caja General Abajo           0,00
```

Las marcadas con ⚠ son las que tienen algo pendiente. **Las demás no se compararon todavía** contra
Contagram — si mañana aparece otra diferencia, se suma acá.

---

## 5 · Ya resuelto — no volver a mirar

**PAYWAY QR** *(26/08)* — una transferencia tenía las dos patas por importes distintos y el sistema
había creado $105.449,74 de la nada. Dato corregido en producción y **bug cerrado** (`19935c9`):
editar una transferencia ahora actualiza las dos cuentas. Un barrido de toda la base confirmó que era
la única descuadrada. Saldo actual: **$448.172,60** contra $448.172,61 de Contagram.

**Anulaciones coherentes** — se anularon en el CRM y nunca existieron en Contagram, así que los dos
sistemas coinciden. No requieren acción:

```
venta 24622   FLORENCIA        $191.033,86    anulada 21/08
venta 24669   FLORENCIA        $181.500,00    anulada 24/08
venta 24671   Tania prueba     $105.000,00    anulada 24/08   (registro de prueba)
cobro 27619   ZETTI            $117.436,65    anulado 18/08
cobro 27700   CONCIMAT          $48.121,43    anulado 21/08
```

---

## Resumen

```
diagnosticado y con pasos concretos
  Cheque Propio                    $3.245.933,62     ver 2.1
  cobros descalzados                  $158.077,66     ver 2.2, 2.3, 3.1
  Mastercard (falta decidir)           $18.000,01     ver 1.1
  venta 24624 (falta decidir)          $98.212,40     ver 1.2

diagnosticado, bancos
  Banco Galicia                     $2.213.980,56     ver 2.4  (pagos a Ferrum + Mastercard)
  Banco Credicoop                   $2.012.783,57     ver 2.5  (1,6 M es el caso 2.1)

sin diagnosticar
  Mercado Pago                      $2.398.464,22     ver 2.4 — hay hipótesis, falta el export
  Cta Cte Clientes                    $343.102,40     ver 3.3 — es anterior al 18/08
  Caja del Local                       $81.056,29     ver 0
```

El **Cheque Propio (2.1)** sigue siendo el más grande y ya tiene los pasos escritos. Después de ése,
lo que más pesa son los **bancos**, y para esos necesito los exports de movimientos. Si se resuelve sólo ése, la caja queda casi al día.

---

*Detalle técnico y método de comparación en `CASOS_A_CORREGIR.md`.
Descuadres de ventas anteriores al 18/08 en `PENDIENTE REGULARIZAR.txt`.
Exports que respaldan este análisis en `actualziacion/`.*
