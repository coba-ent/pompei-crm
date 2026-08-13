# Casos que hay que revisar a mano

Lo que quedó abierto de la conciliación del import (13/08/2026). Cada punto dice **qué mirar**,
**dónde** y **qué hacer según lo que aparezca**. El detalle técnico está en
`importacion_casos_a_revisar.md` §10-§18.

Lo demás está cerrado: tesorería, NC/ND, Cta Cte de Proveedores y los cobros migrados.

---

## 1. Tres ventas cuyo cobro Contagram borró — $427.526,91

**Es lo único que separa a Mercado Pago de estar cerrada.**

| Cliente | Venta CRM | Id Contagram | Fecha | Comprobante | Importe |
|---|---|---|---|---|---:|
| Micaela Echeverría | 20853 | 2026-FC-23661 | 08/07 | B s/nº | $79.096,49 |
| Emanuel Gutiérrez | 20363 | 2026-FC-24159 | 30/07 | B **0005-00005650** | $171.818,79 |
| Martín González | 20360 | 2026-FC-24162 | 30/07 | B **0005-00005652** | $176.611,63 |

Existían en Contagram cuando importamos y se borraron después: sus Ids son huecos en la numeración
(está el 25418-25422 pero falta el 25421) y tampoco figuran en el informe de cobros del 13/08. Dos
fuentes independientes.

**Qué mirar**: buscar esas tres ventas en Contagram.

- **Si la venta ya no existe** → se anuló. Los dos del 30/07 tienen comprobante fiscal emitido, así
  que en el CRM la baja va **por nota de crédito**, nunca borrando la venta. Micaela Echeverría no
  tiene número, esa sí se puede eliminar.
- **Si la venta existe pero figura impaga** → el cobro se borró por error en Contagram y hay que
  recargarlo allá; el CRM está bien.

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

## 4. Cuenta Corriente de Clientes: $2.068.382,64 al 05/08

CRM $9.440.682,65 contra Contagram $7.372.300,01.

Todo el saldo del CRM está en ventas de 2026 ($10.412.488,81); los años anteriores dan saldos
chicos y negativos (a favor del cliente), que el aging netea.

Parte de la diferencia son los puntos 1 a 3 de esta lista ($1.425.512,02 entre los tres). El resto
no se puede ubicar sin el detalle de Contagram.

**Qué pedir**: el informe de **Cuenta Corriente de Clientes por cliente al 05/08** desde Contagram.
Con eso se cruza cliente por cliente y sale la diferencia exacta.

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
