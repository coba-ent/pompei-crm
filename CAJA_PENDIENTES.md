# Caja — pendientes y dudas al 26/08/2026

Para la puesta al día. Sólo lo que **falta resolver**: lo que hay que corregir y lo que hay que
decidir. Lo ya cerrado está al final, en dos líneas, para no volver a mirarlo.

Cada número está verificado contra la base de producción y contra el export de Contagram.

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

## 3 · A VERIFICAR — puede que no sea nada

### 3.1 · Dos cobros del CRM que no aparecen en Contagram

```
$99.714,37
```

```
cobro 27701   venta 24635   CONCIMAT SACIFYM     Mercado Pago   $50.090,97   21/08
cobro 27753   venta 24604   Carlos 1153161973    Mercado Pago   $49.623,40   24/08
```

Ninguno de los dos importes aparece en los informes de Contagram del período. **Buscarlos por cliente
y fecha antes de darlos por faltantes** — pueden estar con otro importe.

⚠️ El de CONCIMAT tiene historia: se cargó como $48.121,43, se corrigió a $50.090,00 medio minuto
después, y dos horas más tarde **otro usuario** lo ajustó a $50.090,97. Además el movimiento de
tesorería dice **"Freddy 1124594187"** como detalle, que no es el cliente de esa venta.

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
hay que decidir       $116.212,41     Mastercard $18.000,01 + venta 24624 $98.212,40
                                      (más el arrastre al Banco Galicia)
hay que corregir    $3.304.010,91     Cheque Propio $3.245.933,62 + $27.306,00 + $30.771,29
a verificar            $99.714,37
                    ─────────────
total               $3.519.937,69
```

El **Cheque Propio (2.1)** es el 92% de todo. Si se resuelve sólo ése, la caja queda casi al día.

---

*Detalle técnico y método de comparación en `CASOS_A_CORREGIR.md`.
Descuadres de ventas anteriores al 18/08 en `PENDIENTE REGULARIZAR.txt`.
Exports que respaldan este análisis en `actualziacion/`.*
