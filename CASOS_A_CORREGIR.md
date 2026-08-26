# Casos a corregir — CRM vs Contagram

Análisis del **26/08/2026** sobre los exports de `actualziacion/`.

Cada caso está **verificado contra la base de producción**, no deducido de los informes solos.
Los que cierran al centavo dicen "confirmado"; los que necesitan que alguien mire, lo aclaran.

**Período analizado**: 18 al 25/08/2026 (cuenta corriente de clientes) y 24/07 al 25/08 (Cheque Propio).

---

## Resumen

| # | Caso | Impacto | Estado |
|---|---|---:|---|
| T1 | Cheque Propio: transferencia cargada donde iba un pago | $3.245.933,62 | confirmado |
| T2 | Venta 24624 anulada en el CRM, cobrada en Contagram | $98.212,40 | confirmado |
| T3 | Cobro anulado en el CRM, vigente en Contagram | $27.306,00 | confirmado |
| T4 | Cobro que el CRM tiene y Contagram no | $30.771,29 | confirmado |
| T5 | Dos cobros del CRM sin contraparte en Contagram | $99.714,37 | a verificar |
| T6 | ~~PAYWAY QR: una transferencia con las dos puntas desbalanceadas~~ | $105.449,74 | **CORREGIDO 26/08** |
| — | Anulaciones coherentes en los dos sistemas | — | sin acción |

**Pendiente de conciliar: $3.501.937,68** (el T6 ya está corregido), concentrado casi todo en T1.

⚠️ **El T6 no es un dato mal cargado: es un bug del CRM.** Una transferencia entre cuentas quedó con
las dos puntas por importes distintos —salieron $140.937,63 de una cuenta y entraron $246.387,37 en
la otra—, o sea que el sistema **creó $105.449,74 de la nada**. Ver el caso para el detalle.

---

## CASO T1 — Cheque Propio: una transferencia cargada donde iba un pago

```
Estado      CONFIRMADO — la cuenta cierra al centavo
Fuente      actualziacion/Cheque Propio Movimientos 26-08-2026 0012 Hs.xlsx
Impacto     $3.245.933,62 en Cheque Propio  +  $1.622.976,50 en Banco Credicoop
```

### Los saldos

```
Contagram    $1.652.957,43
CRM          $1.592.976,19      (la pantalla lo muestra en negativo por ser cuenta "a pagar")
diferencia   $3.245.933,62
```

### Los movimientos

| Fecha | Movimiento | Importe | CRM | Contagram |
|---|---|---:|:--:|:--:|
| 24/07 | Pago a Peisa — factura A 0057-61702 | −$1.622.976,50 | ✔ | ✔ |
| 28/07 | Movimiento desde Banco Credicoop | +$1.622.976,50 | ✔ | ✔ |
| 25/08 | **acá difieren** | | | |

```
Contagram   PAGO a Peisa, factura A 0057-61702        −$1.622.957,12    (salida)
CRM         MOVIMIENTO ENTRE CUENTAS desde Credicoop  +$1.622.976,50    (entrada)
```

Se cargó una **entrada** donde correspondía una **salida**. Por eso la diferencia es casi el doble
del importe: falta restar el pago y además sobra un ingreso.

### La verificación

```
CRM hoy                                          −$1.592.976,19
−$1.622.976,50  (sacar el ingreso mal cargado)
−$1.622.957,12  (poner el pago que falta)
                                                 ─────────────
                                                 −$1.652.957,43   =  Contagram ✔
```

### Arrastra al Banco Credicoop

Se cargó como **transferencia**, así que generó dos movimientos vinculados por el mismo
`transferencia_id` (`ae17fded-97d0-4c81-a1f2-4e5f164c4032`):

```
#49022   cuenta 14  Banco Credicoop   −$1.622.976,50
#49023   cuenta  2  Cheque Propio     +$1.622.976,50
```

El error también le sacó $1.622.976,50 al Banco Credicoop.

```
movimiento 49023   creado 25/08/2026 12:16:37   por Pompei1sanitarios@gmail.com
```

### Qué hacer

1. Anular la transferencia del 25/08 — **los dos** movimientos, `49022` y `49023`.
2. Registrar el pago: **Peisa**, factura **A 0057-00061702**, **$1.622.957,12**, fecha **25/08/2026**,
   cuenta **Cheque Propio**.
3. Verificar el saldo del **Banco Credicoop**: tiene que subir $1.622.976,50.

⚠️ El importe **no es el mismo** que el pago de julio: son **$19,38 menos**. Son dos pagos distintos
de la misma factura, no una duplicación. No copiar el importe anterior.

---

## CASO T2 — Venta anulada en el CRM que en Contagram está cobrada

```
Estado      CONFIRMADO
Impacto     $98.212,40
```

```
CRM         venta 24624   FLORENCIA 1159751732   $98.212,40
            ANULADA el 21/08 15:52:08 por el usuario "Juan"

Contagram   venta #24630  Freddy 1124594187      $98.212,40   VIGENTE
            cobro #26451  Freddy 1124594187      $98.212,40   VIGENTE  (Mercado Pago)
```

La venta se anuló en el CRM y en Contagram sigue viva **y además cobrada**.

⚠️ Los clientes no coinciden: **FLORENCIA** en el CRM, **Freddy** en Contagram. Mismo importe, misma
fecha. Hay que definir de quién era la operación antes de replicar la anulación.

### Qué hacer

Definir si la venta corresponde o no. Si la anulación del CRM es correcta, **anularla también en
Contagram** junto con su cobro. Si no, revertirla en el CRM.

---

## CASO T3 — Cobro anulado en el CRM, vigente en Contagram

```
Estado      CONFIRMADO
Impacto     $27.306,00
```

```
CRM         cobro 27674   venta 24608   FLORENCIA 1159751732   Visa   $27.306,00
            ANULADO el 21/08 15:16:31 por Pompei2sanitarios@gmail.com

Contagram   cobro #26429  FLORENCIA 1159751732   Visa   $27.306,00   VIGENTE  (20/08)
            venta #24607  FLORENCIA 1159751732          $27.306,00
```

La venta existe en los dos. El cobro se anuló sólo en el CRM.

### Qué hacer

Anular el cobro en Contagram, o recrearlo en el CRM. **Contagram muestra $27.306,00 cobrados que el
CRM da por impagos.**

---

## CASO T4 — Cobro que el CRM tiene y Contagram no

```
Estado      CONFIRMADO
Impacto     $30.771,29
```

```
CRM         cobro 27673   venta 24582   FLORENCIA   Visa   $30.771,29   ANULADO 20/08 20:03
            cobro 27688   venta 24582   FLORENCIA   Visa   $30.771,29   VIGENTE

Contagram   venta #24579  FLORENCIA   $30.771,29   sin ningún cobro asociado
```

En el CRM el cobro se anuló y **se volvió a cargar** (por eso hay dos ids). En Contagram nunca se
registró ninguno.

### Qué hacer

Cargar el cobro en Contagram: **$30.771,29**, Visa, sobre la venta de FLORENCIA del 19/08.

---

## CASO T5 — Dos cobros del CRM sin contraparte en Contagram

```
Estado      A VERIFICAR — puede ser que en Contagram estén con otra fecha o importe
Impacto     $99.714,37
```

```
CRM   cobro 27701   venta 24635   CONCIMAT SACIFYM      Mercado Pago   $50.090,97   21/08
CRM   cobro 27753   venta 24604   Carlos 1153161973     Mercado Pago   $49.623,40   24/08
```

Ninguno de los dos importes aparece en los informes de Contagram del período.

⚠️ El de CONCIMAT tiene historia: se cargó como $48.121,43, se corrigió a $50.090,00 medio minuto
después, y dos horas más tarde **otro usuario** lo ajustó a $50.090,97. El movimiento de tesorería
además dice **"Freddy 1124594187"** como detalle, que no es el cliente de esa venta.

### Qué hacer

Buscarlos en Contagram por cliente y fecha antes de darlos por faltantes. Si efectivamente no están,
cargarlos.

---

## CASO T6 — PAYWAY QR: una transferencia con las dos puntas desbalanceadas

```
Estado      CORREGIDO el 26/08/2026 — dato arreglado en producción y bug cerrado (`19935c9`)
Fuente      Downloads/PAYWAY QR Movimientos 26-08-2026 0035 Hs.xlsx
Impacto     $105.449,74 de más en PAYWAY QR a Cobrar
```

### Los saldos

```
Contagram    $448.172,61
CRM          $553.622,34
diferencia   $105.449,73
```

### Dónde está

Del 18 al 24/08 los dos sistemas tienen **exactamente los mismos 14 movimientos**, salvo uno:

```
18/08   transferencia de PAYWAY QR a Banco Galicia

CRM         −$140.937,63
Contagram   −$246.387,37
diferencia   $105.449,74
```

Todo lo demás —los cobros de LILIANA, MARIA, Luis, FRANCISCO, ROMINA, Ana, ABEL, VIRGINIA y las
otras cuatro transferencias a Galicia— coincide al centavo.

### El bug

La transferencia `8d801286-bed3-4a0d-aac9-7a47516c46c5` tiene **las dos puntas por importes
distintos**:

```
#48912   PAYWAY QR a Cobrar   −$140.937,63
#48913   Banco Galicia        +$246.387,37
                              ─────────────
descuadre                      $105.449,74
```

Salió una cantidad de una cuenta y entró otra distinta en la otra. **El sistema creó $105.449,74 de
la nada.**

La auditoría muestra cómo:

```
24/08 12:27:52   creó  #48912   −$140.937,63   (PAYWAY)
24/08 12:27:52   creó  #48913   +$140.937,63   (Galicia)   ← nace balanceada
24/08 12:54:28   MODIFICÓ #48913 → $246.387,37             ← se edita UNA sola punta
```

Todo por `Pompei1sanitarios@gmail.com`. Media hora después de crear la transferencia, se corrigió el
importe **del lado de Galicia solamente**, y la punta de PAYWAY quedó con el valor viejo.

**Editar un movimiento entre cuentas tiene que editar las dos puntas o ninguna.** Hoy permite dejarlas
desbalanceadas sin ningún aviso.

### Cuál es el correcto

El de **Contagram y Banco Galicia**: $246.387,37. Los dos coinciden, y es la liquidación real de
PAYWAY. El que quedó mal es el de PAYWAY QR en el CRM.

### La verificación

```
CRM hoy                   $553.622,34
−$105.449,74  (completar la salida que faltó)
                          ─────────────
                          $448.172,60   ≈  Contagram $448.172,61   ✔  (1 centavo de redondeo)
```

### Qué se hizo — 26/08/2026

1. **Dato corregido en producción**: el movimiento `#48912` pasó de −$140.937,63 a −$246.387,37.
   La transferencia ahora suma cero y PAYWAY QR quedó en **$448.172,60** (Contagram: $448.172,61,
   un centavo de redondeo). Backup en `/root/pre_fix_transferencia_48912.sql`.

2. **Bug cerrado** (`19935c9`): `CuentaTesoreriaController::updateMovimiento()` ahora edita **las dos
   patas** en una transacción — el importe de la otra es siempre el opuesto y la fecha se mantiene
   igual en ambas. Es el mismo criterio que ya usaba `destroyMovimiento()`, que borra las dos.
   Con test de regresión que verifica el invariante: la transferencia tiene que sumar cero.

### Barrido: ¿hay más transferencias así?

Se revisaron **todas** las transferencias de la base:

```
transferencias desbalanceadas: 1   (ésta)
```

Es la única. Pero mientras el bug siga, puede volver a pasar cada vez que alguien corrija el importe
de una transferencia ya cargada.

---

## Anulaciones que SÍ están bien — no requieren acción

Se anularon en el CRM y **nunca existieron en Contagram**, así que los dos sistemas coinciden:

```
venta 24622   FLORENCIA 1159751732   $191.033,86   anulada 21/08
venta 24669   FLORENCIA 1159751732   $181.500,00   anulada 24/08
venta 24671   Tania prueba           $105.000,00   anulada 24/08   (registro de prueba)
cobro 27619   ZETTI INGENIERIA       $117.436,65   anulado 18/08
cobro 27700   CONCIMAT SACIFYM        $48.121,43   anulado 21/08
```

Se listan para que nadie los vuelva a investigar.

---

## Totales del período (18 al 25/08)

```
              Contagram          CRM            diferencia
ventas    $26.845.425,53   $25.879.312,53     −$966.113,00
cobros    $22.739.843,25   $21.337.555,02   −$1.402.288,23
```

⚠️ **Estos totales son orientativos, no un diagnóstico.** El apareo se hace por importe —los dos
sistemas no comparten identificador para lo posterior a la migración— y hay importes repetidos que
confunden el cruce: cinco ventas de Mercado Libre aparecían como "faltantes" y en realidad existen,
con el mismo importe que otra del mismo período.

Los casos T2 a T5 explican **$256.004,06** de esa diferencia. El resto necesita un cruce por
comprobante, no por importe.

---

## Método y trampas — para quien continúe

**No comparten identificador.** Sólo las ventas migradas tienen el mismo id en los dos sistemas. Las
posteriores a la migración tienen id propio en cada uno, y hay que aparearlas por importe.

**Los importes de Mercado Libre difieren en centavos** entre los dos sistemas (redondeo de IVA).
Apareando con tolerancia de $0,02 aparecen como faltantes en los dos lados a la vez. Hay que hacer
una segunda pasada con tolerancia de unos pesos.

**Los importes repetidos rompen el apareo.** Varias ventas de ML del mismo producto tienen exactamente
el mismo total. Si una queda fuera de la ventana comparada, el matcher aparea la que no es y marca
como faltante una que sí existe. **Verificar siempre contra la base antes de reportar un faltante.**

**Las fechas se guardan en UTC** y la app muestra en hora argentina. Un informe de las 20:18 corta en
las 23:18 UTC.

**Contagram renumeró los productos**: para stock, cruzar por la columna `ID VIEJOS`, nunca por `Id`.

---

## Archivos relacionados

- `PENDIENTE REGULARIZAR.txt` — descuadres de ventas y cuenta corriente anteriores al 18/08
- `casos finales a comaprar en contagram.txt` — historial y análisis de cada caso viejo
- `.claude/skills/chequeo-stock/` — runbook del chequeo diario de stock
- `actualziacion/` — los exports que respaldan este análisis
