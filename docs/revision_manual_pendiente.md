# Casos que hay que revisar a mano

Lo que quedó abierto de la conciliación del import (13/08/2026). Cada punto dice **qué mirar**,
**dónde** y **qué hacer según lo que aparezca**. El detalle técnico está en
`importacion_casos_a_revisar.md` §10-§18.

Lo demás está cerrado: tesorería, NC/ND, Cta Cte de Proveedores y los cobros migrados.

---

## 1. Seis notas de crédito de agosto — RESUELTO (13/08/2026)

Las tres ventas cuyo cobro "Contagram había borrado" no se borraron: se les **emitió nota de
crédito** el 10/08 y se anuló el cobro. Por eso desaparecían del informe y sus Ids quedaban como
huecos en la numeración.

Del informe de NC/ND de 2026 (`public/imports/nc nd 2026/`): de 155 notas, **148 están en los dos
sistemas con el importe idéntico** y 6 estaban sólo en Contagram. **Ya se cargaron en el CRM**
(`notasPosterioresAlCorte()`):

| Id NC | Fecha | Venta CRM | Cliente | Importe |
|---|---|---|---|---:|
| 733 | 11/08 | 23756 | Jacinto 1157350697 | $227.357,99 |
| 729 | 10/08 | 20758 | Paloma 1161840539 | $212.706,70 |
| 731 | 10/08 | 20360 | Martín González | $176.611,63 |
| 732 | 10/08 | 20363 | Emanuel Gutiérrez | $171.818,79 |
| 730 | 10/08 | 20853 | Micaela Echeverría | $79.096,49 |
| 728 | 07/08 | 20415 | CAROLINA 1158929779 | $19.290,86 |

La Cta Cte de Clientes de hoy bajó **$886.882,46** (de $10.622.591,23 a $9.735.708,77). Al 01/05 y
al 05/08 no cambió nada, porque son posteriores a esos cortes.

**Nada que revisar acá.** Queda como referencia de qué pasó.

---

## 1b. Tres notas de crédito del CRM — verificado, están bien

Están vinculadas a ventas propias del CRM (por eso mi primera consulta las mostraba "sin venta": esas
ventas no tienen legacy de Contagram, se cargaron a mano después del corte).

| Nota | Fecha | Importe | Venta | Cliente |
|---|---|---:|---|---|
| 1 | 05/08 | $307.569,76 | 1 | TANIA 1157822317 |
| 2 | 07/08 | $129.061,49 | 8 | GRACIELA 1136562338 |
| 860 | 12/08 | $112.802,25 | 23761 | JUAN 2257505195 |

La 860 es la **Id 734 de Contagram** (venta 24396, mismo cliente y mismo importe): la misma
operación cargada en los dos sistemas, cada uno contra su propia venta. Correcto.

---

## 2. Dos cobros de Mercado Pago que Contagram no tiene — $437.933,68

| Detalle | Fecha | Importe |
|---|---|---:|
| `GLOOLIVARES` | 05/08 | $389.934,68 |
| `FALVAR2009` | 05/08 | $47.999,00 |

No aparecen en el export de Contagram ni por Id, ni por nombre, ni por importe, en todo el rango
01/07–10/08. Los otros 13 del mismo lote sí están, fechados entre el 06/08 y el 10/08.

**Qué mirar**: son nicknames de Mercado Libre. Buscar esas dos ventas en el CRM y ver si se
cargaron también en Contagram con el nombre real del cliente en vez del nickname. Si no están,
hay que cargarlas en Contagram.

---

## 3. Cuatro ventas de órdenes de Mercado Libre canceladas — $560.051,43

Ventas **116, 62, 65 y 95**. La orden de ML se canceló pero la venta sigue figurando cobrada.

**Qué mirar**: si corresponde anularlas. Ojo con el comprobante fiscal, igual que en el punto 1.

**Además hay un bug de fondo**: el CRM detecta la cancelación de la orden pero no toca la venta ni
avisa. Habría que decidir qué debería hacer (anular sola, marcarla, notificar) — se conecta con el
módulo de Notificaciones que está anotado como pendiente en `documentacion_principal_crm.md §7`.

---

## 4. Cuenta Corriente de Clientes: $5.075.376,90 al 01/05 (y $2.068.382,64 al 05/08)

CRM $10.501.640,11 contra Contagram $5.426.263,21 al 01/05.

**La diferencia NO está en los cobros.** Se descartó reconstruyendo la Cta Cte desde cero con las
ventas del CRM y **los cobros del informe de Contagram**: da $15,4 M al 01/05, todavía más lejos de
los $5,4 M que muestra Contagram. Si el problema fueran los cobros del CRM, esa reconstrucción
tendría que haber dado el número de Contagram. Está del lado de las **ventas o las notas de
crédito**.

Tampoco son fechas invertidas como en los gastos (§20): los Excel de Ventas y el informe de cobros
traen días hasta 31, y en la base los días 1-12 pesan 39,4 % en ventas y 39,6 % en cobros — lo
esperable para una distribución pareja.

### 46 ventas concentran $10.229.977,85 de saldo al 01/05

Y **tres explican $8,67 M**:

| Venta (Id Contagram) | Fecha | Total | Cobrado al 01/05 | Saldo | Cobrado en total |
|---|---|---:|---:|---:|---:|
| **21846** | 26/03/2026 | 11.500.000,00 | 5.750.000,00 | **5.750.000,00** | 9.500.000,00 |
| **21790** | 21/03/2026 | 3.511.308,51 | 1.660.000,00 | **1.851.308,51** | 1.660.000,00 |
| **21793** | 21/03/2026 | 2.623.410,53 | 1.550.000,00 | **1.073.410,53** | 2.623.410,53 |

El saldo de la 21846 sola ($5.750.000) es casi exactamente la diferencia con Contagram
($5.075.376,90).

**Qué mirar**: esas tres ventas en Contagram, sobre todo la 21846. Comparar el total facturado, los
cobros que tiene aplicados y si hay alguna nota de crédito que el CRM no tenga. Es lo primero, antes
de pedir ningún informe: puede resolver el 90 % de la diferencia.

Las otras 43 suman $1,56 M y valen una segunda pasada.

**Si con eso no cierra**, ahí sí pedir el informe de **Cuenta Corriente de Clientes por cliente** al
01/05, que permite cruzar cliente por cliente.

---

## 5. El tramo de cobros de 2021 que falta en el informe

El informe de "Movimientos de Clientes" arranca el **02/08/2021** y la operación empieza en febrero.
Quedan **358 ventas** cobradas antes de esa fecha con el cobro fechado como la factura.

No afecta los saldos de hoy (son ventas cobradas hace años, fuera del aging), pero deja la foto
histórica de 2021 mal.

**Qué pedir**: el mismo informe (Cuentas Corrientes → Movimientos de Clientes, `Operación = Cobro`)
del **01/01/2021 al 01/08/2021**. Se regenera `database/data/cobros_contagram.json` y se corre de
nuevo `php artisan contagram:normalizar-tesoreria` — es idempotente y sólo toca lo que esté en el
JSON.

---

## 6. Cuenta Corriente de Proveedores: $6.226,52

Estaba conciliada al 01/08 con $4.649,96, y esa diferencia estaba **entre el informe de Contagram y
su propio panel**, no en el CRM. Ahora son $6.226,52 al 05/08.

**Qué mirar**: si molesta, pedir el informe de Movimientos de Proveedores al 05/08 y repetir el
cruce. Importe chico sobre $25,9 M.

---

## 7. Diferencias menores, ya identificadas

| Qué | Importe | Estado |
|---|---:|---|
| `Caja chica gastos` | $1,00 | Desvío estructural conocido, viene de la ficha de Contagram (§13). |
| Compra Id 85 del 24/04/2021 | $683,08 | No se importó nunca. Cargarla a mano si importa. |
| Mercado Pago al 10/08 | $18.105,12 | Residuo después de descontar el punto 1. Probablemente más desfasaje de carga; para confirmarlo hace falta el export de MP posterior al 10/08. |

---

## 8. Las 15 diferencias de Caja del Local (del 06/08 en adelante)

El listado completo está en `public/imports/diferencias_caja_del_local.csv`.

Lo que más pesa:

- **CAROLINA $700.000** — sólo en Contagram.
- **VIVIANA** $36.169,69 en un sistema contra $170.642,49 en el otro.
- **Jacinto** $30.332,07 contra $257.690,06.

**Qué mirar**: son movimientos posteriores al corte, cargados a mano en cada sistema. Hay que
decidir cuál de los dos tiene el importe bueno.

---

## 9. Tests en rojo

16 tests fallan con 403. Son de permisos y **ya fallaban antes** de todo este trabajo — no los
introdujo la conciliación. Conviene arreglarlos en algún momento para que la suite vuelva a servir
de red de seguridad.
