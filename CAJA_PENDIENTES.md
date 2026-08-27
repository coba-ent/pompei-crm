# Caja — pendientes al 26/08/2026

Todo lo que falta corregir o decidir, en un solo lugar. Lo que ya está regularizado no figura.

Cada número está verificado contra la base de producción y contra los exports de Contagram del 26/08.

---

## FOTO — dónde no coinciden los dos sistemas

```
                            Contagram              CRM          diferencia
  Cheque Propio            1.652.957,43        29.980,93    −1.622.976,50   → 1  (corregido el 26/08)
  Mercado Pago            19.527.812,25    21.926.276,47    +2.398.464,22   → 2
  Banco Galicia            2.191.145,00       −22.835,56    −2.213.980,56   → 2
  Banco Credicoop          3.206.795,98     1.194.012,41    −2.012.783,57   → 3
  Saldo Cta Cte Clientes   9.859.324,61     9.505.935,79      −353.388,82   → 13
  Mastercard a Cobrar       −175.928,19      −193.928,20       −18.000,01   → 6
  Caja del Local             600.143,15       681.199,44       +81.056,29   → 14
```

Todas las demás cuentas coinciden y no hay que mirarlas: Cta Cte Proveedores ($0,24 sobre
$17,5 M), PAYWAY QR, Amex, Cabal, Maestro, Nulo, USD Online, Santander, Caja chica gastos,
Juan USD Personal, Caja General Abajo, Cabal Credicoop, Visa Credicoop.

---

# A · HAY QUE CORREGIR — el diagnóstico está cerrado

## 1 · Cheque Propio — falta el movimiento de un cheque diferido · $3.245.933,62

La compra **2376 a Peisa** ($4.868.910,12) se pagó con tres cheques a 30 y 60 días. El circuito es
el mismo en los dos sistemas: al emitir el cheque se carga un **pago** contra la cuenta Cheque
Propio, y **cuando el cheque vence** se hace un **movimiento entre cuentas** de Banco Credicoop →
Cheque Propio, que es cuando la plata sale del banco de verdad.

```
                                    pago (emisión)        acreditación (vencimiento)
  24/06   Mercado Pago      −$1.622.976,50   ✔            —
  24/07   Cheque Propio     −$1.622.976,50   ✔            28/07  +$1.622.976,50   ✔
  24/08   Cheque Propio     −$1.622.957,12   ✘ FALTA      25/08  +$1.622.976,50   ✔
```

**El pago del 24/08 existe** en el CRM (`pago 3194`, compra 2376) — por eso la Cuenta Corriente de
Proveedores cuadra al centavo — **pero nunca generó su movimiento de tesorería.** La cuenta Cheque
Propio recibió la acreditación sin haber recibido nunca la emisión, y por eso quedó al revés.

**Causa:** la migración trae los pagos desde `Compras/` y los movimientos de tesorería desde
`Cuentas/`, que son dos exports distintos. Ese cheque vencía el **24/08**, diez días después del
export del 14/08: en Contagram el movimiento todavía no existía, así que no había nada que importar.
**Barrida toda la base: es el único pago migrado sin su movimiento** — no hay más casos.

### ✔ HECHO el 26/08 — el movimiento ya está creado

Se creó el movimiento faltante en producción: `#49067`, Cheque Propio, 24/08/2026, `pago`,
−$1.622.957,12, "Peisa", comprobante 0057-00061702, **ligado al `pago 3194`**. Con el vínculo
puesto, si mañana editan o anulan ese pago desde la pantalla, el movimiento se actualiza o se borra
solo — los movimientos migrados no tienen ese vínculo y no lo harían.

```
Cheque Propio    antes  +1.592.976,19  (dado vuelta)
                 ahora     −29.980,93
```

Backup previo de la tabla en el VPS: `/root/backup_movimientos_20260826_1015.sql`.

⚠️ **No se cargó un pago nuevo a Peisa**: el pago ya estaba. Crearlo otra vez habría duplicado la
compra 2376 y roto la Cuenta Corriente de Proveedores, que cuadra en $0,24.
⚠️ **No se anuló la transferencia del 25/08**: es correcta, es la acreditación del cheque.

### Lo que quedó pendiente de este caso

**a) Los $19,38.** La transferencia del 25/08 (`#49022` / `#49023`) está cargada por
**$1.622.976,50**, que es el importe del cheque de **julio**; el de agosto es **$1.622.957,12**.
Por eso el saldo quedó en $29.980,93 y no en los $30.000,31 históricos.
→ Ver el extracto de Credicoop del 25/08: cuánto debitó el banco. Si fue $1.622.957,12, corregir la
transferencia desde la pantalla (edita las dos patas sola).

**b) La acreditación falta en Contagram.** Contagram muestra $1.652.957,43 = $30.000,31 + el cheque
de agosto todavía vivo: **no registró el movimiento entre cuentas del 25/08**. Es la misma carga que
encabeza el caso 3.

## 2 · Galicia y Mercado Pago — los tres pagos a Ferrum del 25/08 · $2.213.980,56

El CRM los tiene en **Banco Galicia** y Contagram no los tiene ahí:

```
#49047    −$526.036,05
#49048     −$44.215,06
#49049  −$1.661.729,45
        ─────────────
        −$2.231.980,56    + $18.000,00 de Mastercard (caso 6)  =  la diferencia de Galicia ✔
```

**No son pagos faltantes.** La Cta Cte de Proveedores cuadra entre los dos sistemas: si el CRM
tuviera $2,2 M más de pagos a Ferrum, proveedores tendría que diferir. Están cargados **desde otra
cuenta** en Contagram, casi seguro Mercado Pago — que es justo donde el CRM tiene $2.398.464,22 de más.

```
diferencia de Mercado Pago    $2.398.464,22
menos los pagos a Ferrum      $2.231.980,56
                              ─────────────
quedaría sin explicar           $166.483,66
```

**Pasos:** confirmar desde qué cuenta salió el pago a Ferrum del 25/08 y mover los tres movimientos
a la cuenta correcta en el sistema que los tenga mal. Para cerrar los $166.483,66 restantes hace
falta el **export de movimientos de Mercado Pago**.

*(El 13/08 hay otra diferencia en Galicia que no es tal: Contagram tiene la liquidación de Visa en
dos líneas —$117.136,72 y $848.213,40— y el CRM en una sola de $965.350,12.)*

## 3 · Banco Credicoop — cuatro movimientos que sólo tiene el CRM · $2.012.783,57

```
25/08   #49022   movimiento entre cuentas → Cheque Propio   −$1.622.976,50   ← ver caso 1
25/08   #49024   pago a "Contagram"                           −$384.659,00
24/08   #49021   gasto Ley 25413                                 −$4.548,07
25/08   #49025   gasto Otros                                       −$600,00
```

Contagram no tiene ningún movimiento que le falte al CRM. **Los cuatro son correctos y hay que
cargarlos en Contagram**, no sacarlos del CRM:

- **el más grande es la acreditación del cheque de Peisa** (caso 1). Va con el importe corregido:
  **$1.622.957,12**, no $1.622.976,50.
- el pago a "Contagram" de $384.659,00 — es la suscripción al sistema, y coincide con el cartel de
  falta de pago que muestra la pantalla de ellos.
- Ley 25413 (impuesto al cheque) $4.548,07 y Otros $600,00.

Total a cargar en Contagram: **$2.012.764,19**.

## 4 · Cobros que están en un sistema y no en el otro

Falta en **Contagram** (el CRM los tiene) — $135.804,82:

```
19/08   FLORENCIA 1159751732   Visa           $30.771,29   venta CRM 24582 / CG 24579
21/08   CONCIMAT SACIFYM       Mercado Pago   $50.090,97   venta CRM 24635 / CG 24630
24/08   Carlos 1153161973      Mercado Pago   $49.623,40   venta CRM 24604
25/08   INES 1140948619        Mercado Pago    $5.319,16   venta CRM 24696 / CG 24691
```

Falta en el **CRM** (Contagram lo tiene) — $27.306,00:

```
20/08   FLORENCIA 1159751732   Visa           $27.306,00   cobro CG 26429, venta CG 24607 / CRM 24608
```

Ese cobro se anuló en el CRM (`cobro 27674`, 21/08 15:16) y en Contagram sigue vigente: allá figuran
$27.306,00 cobrados que acá están impagos.

**Pasos:** decidir en cada caso si se carga o se anula, y dejar los dos sistemas iguales.

⚠️ El de CONCIMAT viene editado: se cargó $48.121,43, se corrigió a $50.090,00 y dos horas después
otro usuario lo dejó en $50.090,97.

## 5 · Venta que Contagram tiene y el CRM no

```
15/08   Daniel Barrios   $262.748,22   venta CG 24509 + cobro CG 26332
```

Es anterior al período cruzado el 26/08 (18 al 25/08), así que no está confirmada. **Verificar y
cargarla en el CRM si sigue faltando.**

---

# B · HAY QUE DECIDIR — no se resuelve desde el sistema

## 6 · Mastercard — transferencia del 21/08 con los dígitos dados vuelta · $18.000,01

```
21/08   de Mastercard a Banco Galicia
  CRM         −$264.676,77
  Contagram   −$246.676,77
```

**264 / 246**: dígitos transpuestos, diferencia exacta de $18.000,00. La transferencia del CRM está
balanceada, así que es el importe con el que se cargó, no un bug. Creada el 24/08 12:44:57 por
Pompei1sanitarios@gmail.com.

**Cómo saldarlo:** mirar la liquidación de Mastercard del 21/08 o el extracto de Galicia. El que
coincida con el banco es el correcto; corregir el otro. ⚠️ Arrastra al Banco Galicia (caso 2).

## 7 · Venta de $98.212,40 del 21/08 — anulada en un lado, cobrada en el otro

```
CRM         venta 24624   FLORENCIA 1159751732   ANULADA 21/08 15:52 por "Juan"
Contagram   venta 24630   Freddy 1124594187      VIGENTE y COBRADA (Mercado Pago, cobro 26451)
```

⚠️ **Los clientes no coinciden.** Además, del lado del CRM la misma plata está partida en dos:
nota de crédito de $48.121,43 (venta 24627) + cobro de $50.090,97 (venta 24635) = $98.212,40 exacto.

**Cómo saldarlo:** ver el comprobante y el pago de Mercado Libre. Definir de quién era la operación
antes de tocar nada.

## 8 · Ventas con distinto importe en cada sistema

Existen en los dos lados, con el mismo id, y aun así difieren. **No aparecen en ningún cruce de
"qué falta cargar" y mueven todos los cortes desde su fecha.**

**8.1 · Venta 22416 — "Reparación JPD" — 29/04 — $896.835,78** ← la más grande

```
CRM $1.707.510,40   /   Contagram $2.604.346,18
```

Editada tres veces desde la migración, siempre en el CRM: 14/08 a $2.006.455,66, 14/08 a
$2.454.873,55 y 24/08 a $1.707.510,40. Es manual, sin cobrar un peso. Cabecera e items coinciden.
Tiene un item "99999" en $0,00 (producto comodín) que conviene revisar.

→ Actualizarla en Contagram al valor nuevo: con eso cierran abril, mayo, junio y julio.
→ **Si el trabajo sigue abierto**, dejarla quieta en los dos sistemas hasta cerrarlo.

**8.2 · Venta 24100 — LILIANA 1168881830 — 28/07 — $3.711,08 cobrados de más**

El 18/08 quedó cargado el producto de al lado: puso boquilla **AIREADORA 24107** $20.744,01, era
boquilla **AHORRADORA 24759** $17.677,00. CRM $25.100,25 / Contagram $21.389,17.
→ **Preguntar al vendedor qué producto se llevó el cliente.** Contagram tiene el dato correcto.

**8.3 · Venta 24134 — FLOR 1163354614 — 29/07 — $15.166,03**

CRM $199.531,73 / Contagram $184.365,70. Sobra un flexible mallado 1/2 x20 en el CRM.
→ ¿Se vendió? **Sí:** agregarlo en Contagram. **No:** sacarlo del CRM — ⚠️ eso mueve stock.

**8.4 · Venta 24371 — MAURICIO ALBERTO LAURENS — $77.625,00**

Le cambiaron la fecha de emisión del 10/08 al 18/08 y el cobro quedó en el 10/08, ocho días antes
que la venta. → ¿Fue a propósito?

**8.5 · Venta 23103 — Mónica Rocca — 12/06**

Editada el 21/08 y cobrada entera el 24/08 por $166.177,76. → Verificar que el total coincida con
Contagram.

**8.6 · Venta 24571 / CG 24570 — ALEXIS GURNY — 19/08 — $1,23**

Contagram $137.925,06 / CRM $137.923,83. Más grande que el redondeo habitual de ML (1 centavo).
→ ¿Cuál de los dos importes es el correcto?

## 9 · Notas de crédito en duda

**9.1 · Nota de OSVADO — $4.171,31** — el CRM la imputa a la venta 24498, Contagram a la 24495.
→ ¿Cuál es la correcta?

**9.2 · Nota de POMPEI SRL — $72.828,74** — CRM nota 850 = Contagram nota 138/2026, sobre la compra
2424 de **$54.504,80**. La nota es **mayor que la compra**, único caso así en toda la historia.
→ Preguntar al proveedor si cubre varias compras.

---

# C · DENTRO DEL CRM

## 10 · Venta 24013 — Javier 1141479212 — 24/07 — $85,95

Cabecera $297.043,36 / suma de sus productos $296.957,41. Difieren dentro del propio CRM, y el
21/08 le cargaron el cobro por la cabecera: quedó cobrada por más de lo que valen sus productos.

## 11 · El detalle "Freddy 1124594187" aparece en clientes que no son él

Se repite en varios movimientos de Mercado Pago de esta semana, sobre ventas de **CONCIMAT** y de
**FLORENCIA**. No existe ningún cliente llamado Freddy en el sistema. Puede ser quien transfirió, o
un dato que se arrastra mal de un cobro a otro — **si es lo segundo afecta a más movimientos**.

## 12 · Buscador de productos sin código — decidir si se hace

Que el detalle de venta muestre el **código** además del nombre, para distinguir "24759" de "24107"
de un vistazo. Es la causa del caso 8.2, que costó $3.711,08.
Archivo: `resources/js/buscador-catalogo.js`.

---

# D · FALTA INFORMACIÓN PARA CERRAR

## 13 · Cuenta Corriente de Clientes — $343.102,40, anterior al 18/08

Cruzados los seis informes del 26/08 (18 al 25/08) movimiento por movimiento: las 181 ventas están
en los dos sistemas y los únicos cobros descalzados son los del caso 4. **Efecto neto del período:
apenas $10.286,42.** El resto de los $353.388,82 es de antes — el saldo es acumulado desde siempre.

→ **Pedir el export de Movimientos de Clientes de Contagram de todo 2026** (o por trimestres). Con
eso se cruzan las ~4.000 ventas del año por id y salen en minutos todas las que tienen distinto
importe, o sea cualquier otro caso 8.1 escondido.

⚠️ **Trampa del método, ya pisada dos veces:** comparar por importe dentro de una ventana fija da
falsos faltantes. Una venta de ML puede estar fechada distinto en cada sistema —el id del CRM suele
ser el de Contagram **+1**— y hay importes repetidos ($276.577,07 tres veces, $253.464,19 cinco
veces). Hay que buscar el importe en **todo** el export, no sólo en la ventana.

## 14 · Caja del Local — $81.056,29

El CRM tiene más. Sin diagnosticar. → Falta el export de movimientos de la cuenta.

## 15 · Mercado Pago — $166.483,66 sin explicar

Ver caso 2: hay hipótesis para $2.231.980,56 de los $2.398.464,22. → Falta el export de la cuenta.

---

## Diferencias conocidas y aceptadas — NO TOCAR

```
$4.650    Ajuste de conciliación (Contagram se contradice a sí mismo)
~$137     Redondeo de Mercado Libre acumulado
$0,01     Cobro de vicente 1136123337 del 21/08 (el del CRM coincide con el total de la venta)
CMV       Método de cálculo distinto entre los dos sistemas
```

---

## Resumen

```
con pasos escritos, se puede hacer ya
  1 · Cheque Propio                  $1.622.976,50   ✔ arreglado el CRM el 26/08
                                                     falta la acreditación en Contagram + $19,38
  3 · Credicoop                      $2.012.764,19   se carga en Contagram
  4 · cobros descalzados               $163.110,82
  5 · venta de Daniel Barrios          $262.748,22

falta una definición del negocio
  2 · Ferrum: Galicia o Mercado Pago $2.231.980,56
  8 · ventas con distinto importe       $915.713,04
  7 · venta de FLORENCIA o Freddy        $98.212,40
  6 · Mastercard 264 / 246               $18.000,01

falta información
  13 · Cta Cte Clientes                 $343.102,40   export de todo 2026
  15 · Mercado Pago                     $166.483,66   export de la cuenta
  14 · Caja del Local                    $81.056,29   export de la cuenta
```

El **caso 1** es el más grande y no depende de nadie: se puede hacer primero.
