# Importación 2021-2026 — casos a revisar a futuro

Cosas que **no bloquean** el import pero que conviene mirar cuando haya tiempo. Ninguna se resolvió
inventando un dato: donde no había certeza, se eligió la opción que no descuadra la caja y se anotó
acá. Abierto el 10/08/2026. **Import ejecutado en producción el 10/08/2026 20:14.**

Referencias: `docs/importacion_2021_2026_plan_tecnico.md` (diseño) y
`docs/importacion_datos_reales_2026_bitacora.md` (análisis).

---

## 0. Bugs de la aplicación que el volumen destapó

Ninguno lo causó la migración: ya estaban en el código y sólo se hacían visibles con datos reales.
Todos comparten el mismo patrón — **traer registros a memoria y calcular ahí lo que tiene que
resolver la base**. Con 138 ventas de prueba no se notaba; con 23.521 sí.

### Corregidos

| Qué | Dónde | Efecto |
|---|---|---|
| KPIs cargaban todas las ventas y llamaban `aCobrar()` por fila (~70.000 consultas) | `VentaController::kpis()` | la pantalla pasó de **+60 s a 0,2 s** |
| El mismo bug, todavía sin manifestarse | `CompraController::kpis()` | corregido antes de que apareciera |
| Ninguna tabla transaccional tenía índice en `created_at`, que es por donde ordenan todos los listados | migración `..._indices_para_listados_con_historico` | `type=ALL + filesort` → `type=index` |
| Las filas de cobro mostraban los importes en blanco | `CuentaCorrienteController` | el monto no se veía en la cuenta corriente **de ningún cobro**, migrado o no |
| `notas_credito_debito.venta_id` era NOT NULL aunque el código lo declara nullable | migración `..._hacer_venta_id_nullable...` | **emitir una NC/ND de una compra fallaba**; nunca se había probado |
| El input de Emisión arrancaba siempre en `now()` y no se recargaba al editar | `ventas/form.blade.php`, `ventas.js`, `compras/form.blade.php` | **toda venta o compra vieja que se abriera y guardara quedaba refechada al día de hoy** (§8b) |
| La fecha de emisión de ML salía de `toDateString()` sobre un instante en UTC | `ConversorOrdenAVenta` | toda orden posterior a las 21:00 argentinas caía en el día siguiente (§8b) |

### Pendientes

1. **La pantalla de Tesorería da timeout** con los 48.150 movimientos. Confirmado en local y
   esperable en producción. Es el mismo patrón de los KPIs y hay que revisarlo igual.
2. **Otros Ingresos no genera movimiento de tesorería**: al cargar uno, el saldo de la cuenta no se
   mueve. El enum ya acepta `ingreso`, así que el arreglo es directo.
3. **Sin auditar con volumen real**: Dashboard, Informes, y las fichas de cliente y de producto, que
   ahora tienen miles de movimientos asociados.
4. **`Mastercard` cierra en -$442.152,98** con 719 cobros y 0 pagos. Una cuenta de tarjeta por
   cobrar no debería deber plata: el negativo sale de acreditaciones que superan lo migrado. Sin
   analizar — es el tipo de número que hay que entender antes de confiar en el panel (§8c).
5. **Barrido de ventas y compras refechadas al editarlas.** El bug de la fecha (§8b) estuvo activo
   hasta el 11/08/2026. Se encontraron sólo dos, buscando fechas posteriores al corte del 05/08,
   pero **ese criterio no detecta una venta de 2023 refechada a 2026-08-10**: se ve como una venta
   de 2026 cualquiera. Queda pendiente barrer por `updated_at` reciente y contrastar contra el Excel.

---

## 1. Ventas excluidas por estar borradas en Contagram

Las dos se excluyen del import (`MigrarVentasContagram::BORRADAS`).

| Venta | Fecha | Cliente | Total | Situación |
|---|---|---|---:|---|
| `2026-FC-24267` | 2026 | — | $211.581,06 | Figura en el `c/ cobro` **sin ítems**. Es la única de los 6 años en esa condición, o sea que el propio archivo confirma que estaba borrada. |
| `2021-FC-2140` | 20/12/2021 | Maria 1149368745 | $40.034,40 | Confirmada como inexistente en Contagram. **Pero en el export figura cobrada al 100%**, así que fue borrada *después* de exportarla. |

**A revisar:** si esos $40.034,40 de la 2140 entraron de verdad a la caja, la venta debería
importarse igual (el estado "Cobrado" del export sugiere que sí se cobró). Ítems: 1x *Marcos c/
repisa Bl80 x 70 JPD AMOBLAMIENTO* $7.876 + 1x *Maral 50 CM BL Loza* $31.987.

---

## 2. Diferencia de $2.318,24 en notas de débito

Migrado $2.201.067,13 contra $2.203.385,37 de control — **0,1%**. Sin identificar cuál de las 54
notas la explica. No afecta el facturado ni el cobrado, que cierran exactos.

---

## 3. 165 comprobantes cuyos ítems no suman el total

Bajaron de 1.084 al prorratear el IVA de 2021 (plan §3.10b). Los que quedan son mayormente ventas
que **en Contagram ya tienen total 0** con renglones cargados — por ejemplo `2025-FC-20288`
(*Reparacion JPD*, total 0, ítems $1.346.035,12). Se importan tal cual están en el origen: el total
de cabecera es el que suma la caja, así que respetarlo mantiene el cuadre.

**A revisar:** si alguna de ésas debería tener total distinto de 0, es plata que hoy no está contada.

---

## 4. Un solo cobro por venta

El export `c/ cobro` trae el `Cobrado` total pero no el desglose de los pagos parciales, así que se
crea **un cobro por venta**. Consecuencias:

- La **fecha del cobro** es la de emisión de la venta (la real está en `Cuentas/`).
- La **cuenta** es la del primer medio listado. El 2,4% de las ventas mezcla medios distintos
  (ej. `Visa - Caja del Local`), y en esos casos todo el monto queda en el primero.

Se corrige cuando se importen los movimientos de `Cuentas/`, que sí traen fecha y cuenta reales.

---

## 5. Notas de crédito y débito sin venta asociada

Las 692 NC/ND se importan con `venta_id = null`: el export no dice qué venta corrigen. Tampoco se
les cargan ítems, porque `nota_credito_debito_items.producto_id` es NOT NULL y el 6% de los
renglones no tiene producto identificable.

**Efecto práctico:** el total de NC/ND está bien a nivel global, pero el *A Cobrar* de las ventas
individuales que fueron corregidas por una NC no la descuenta.

---

## 6. Cobros que en Contagram quedaron a cuenta del cliente

499 ventas tienen `Cobrado = 0` y **485 de ellas figuran `Estado = Cobrado`**. No es un error del
export: en Contagram esos cobros quedaron **a cuenta del cliente**, sin imputar a la factura. La
plata está en `Cuentas/` (verificado caso por caso). Es el motivo por el que la imputación del plan
§5 es por cliente y no por factura.

---

## 6b. 19 gastos sin categoría padre

De los 9.215 gastos importados, **19 quedaron colgando de una categoría raíz** que en realidad es
una subcategoría (`Comisiones`, `Sueldo`, `Alquiler`, `Cabal`, `Extras hijos`, `Cabify - Uber`,
`Embalaje`, `JM envio local`, `Otros`, `Librería`, `Limpieza`, `Supermercado`). Son bloques del
informe agrupado donde no apareció la fila de categoría antes de la de subcategoría.

Es el 0,2% y no afecta el total. Los 1.161 de `Juan Personal` que también figuran en la raíz **sí
están bien**: ahí la categoría y la subcategoría son la misma en el archivo de origen.

## 6c. Otros Ingresos no impacta en tesorería

Bug del CRM, ajeno a la migración: al cargar un "Otro Ingreso" **no se genera movimiento de
tesorería**, así que el saldo de la cuenta no se mueve. Se detectó al mapear los 61 movimientos de
operación `Ingreso` de Contagram (Aportes de Socios, Préstamos Financieros, Otros Ingresos).
El enum de `movimientos_tesoreria` ya acepta `ingreso`, así que el arreglo es directo.

## 7. Pendientes de origen (archivos)

- **Compras 2021-2024 en formato "c/ pago"** (40 columnas, con `Pagado`/`Estado`/`Medio de Pago`).
  Sin eso no se puede imputar la parte de los pagos que no trae referencia de comprobante.
- **2 ventas dudosas de Mercado Libre** — hoja 3 de `public/imports/revision_pendientes_ventas.xlsx`.

---

## 8. Lecciones del proceso

Vale la pena dejarlas escritas porque se pagaron caro:

- **Probar con volumen real, no con datos de prueba.** Los cinco bugs de arriba estaban desde
  siempre y ninguno se veía con 138 ventas. Bajar la base de producción a local para trabajar fue
  la decisión que los expuso a tiempo.
- **La clave de idempotencia se verifica con datos, no se supone.** El `Id` de Contagram parecía
  identificar un movimiento de tesorería y tiene **22.823 colisiones** sobre 48.222 filas. Se
  probaron cinco combinaciones hasta encontrar una única.
- **El índice `unique` sobre `legacy_id` es lo que hace seguro el import.** Cortó en seco una
  corrida con la clave mal armada. Sin él, habría "funcionado" saltéandose movimientos en silencio.
- **No cambiar el código con un import a medio correr.** Un proceso viejo siguió vivo con el código
  anterior en memoria e insertó los mismos movimientos con otra clave: los saldos quedaron al doble.
  Se detectó sólo porque había un control de saldos contra el origen.
- **Los controles de aceptación tienen que ser independientes del importador.** Las cifras de
  control y la columna `Saldo` de cada cuenta detectaron todos los errores de este proceso. Un
  import sin un número contra el cual verificar es un import que no se puede dar por bueno.
- **Nunca borrar "lo que no está en uso" después de vaciar la tabla que lo usaba.** Así se
  eliminaron 35 categorías de gasto legítimas; se recuperaron del backup.
- **Los Excel de origen mienten de formas silenciosas**: fechas con día y mes invertidos, teléfonos
  guardados como fórmula, encabezados fuera de la fila 1, un archivo entero sin encabezado, columnas
  duplicadas, importes de cabecera vacíos y dos formatos distintos en el mismo módulo. Cada uno
  está documentado con su evidencia en el plan técnico §3.

---

## 8b. Fechas de emisión — revisado el 11/08/2026

Se sospechó que la migración había traído fechas mal. **No es así**: las 23.521 ventas migradas
tienen en `fecha_emision` exactamente lo que dice la columna `Emisión` del Excel, verificado una
por una (0 diferencias). Se descartó también la inversión día/mes: el 61,1 % tiene día > 12, que es
lo que da una distribución real de fechas (si se estuviera leyendo mes/día sería 0 %).

Nota sobre el alcance de esa verificación: se hizo con el mismo lector que usó el importador, así
que prueba que la base **transcribe** fielmente el Excel, no que el Excel se interprete bien. El
test del día > 12 es el único control independiente del parser.

Lo que sí estaba mal, y se corrigió:

- **Dos ventas legacy refechadas al editarlas** (`2026-FC-23512` → 01/07, `2026-FC-24300` → 05/08).
  Causa: el input de Emisión arrancaba en `now()` y no se recargaba con la fecha del registro, así
  que el update la pisaba. Afectaba a Ventas y Compras; Presupuestos ya lo hacía bien. Corregido en
  `b33ac9b`. **Si se editaron otras ventas viejas entre el 10 y el 11/08/2026, hay que revisarlas.**
- **Venta de ML `id 23746`** fechada 11/08 siendo del 10/08 23:52. Causa: el día se sacaba con
  `toDateString()` sobre un instante en UTC. Corregido en `b33ac9b`.

### Resuelto contra la API de Mercado Libre

`ml_ordenes.fecha_creada` tenía dos criterios conviviendo (unas en UTC real, otras 4 horas atrás),
y sobre órdenes de medianoche esa diferencia decide el día de la venta. No se podía inferir desde
la base, así que **se le preguntó la fecha real a la API** orden por orden, usando el token que ya
tiene el CRM: las 115 respondieron, 0 fallos.

- **49 órdenes** estaban desfasadas, todas exactamente **+240 minutos**. Normalizadas a UTC.
- **4 ventas** tenían la emisión un día antes: `64` → 01/08, `57` → 03/08, `47` → 04/08,
  `17` → 06/08. La `66` ya estaba bien.
- Re-verificado después de aplicar: **0 diferencias contra la API**.

No se hizo un `UPDATE +4h` masivo, que era la salida "obvia": cuatro órdenes del 06/08 ya estaban
correctas y habrían quedado rotas. Ante dos criterios mezclados, la corrección tiene que salir de
la fuente, no de un patrón promedio.

## 8c. Tipificación de cuentas de tesorería — corregido el 11/08/2026

El `tipo` de cada cuenta decide en qué bloque del panel cae su saldo (Disponible Cajas/Bancos,
A Cobrar, A Pagar). No afecta ningún movimiento ni importe, sólo dónde se muestra y cómo se suma.
Venía mal desde el alta y la migración lo dejó a la vista:

| Cuenta | Antes | Ahora | Por qué |
|---|---|---|---|
| Banco Credicoop | `a_cobrar` | `banco` | 575 pagos y 2.187 gastos: es la segunda cuenta de gastos del negocio |
| Maestro, Cabal, Cabal Acreditaciones, Cabal Credicoop, Visa Credicoop | `banco` | `a_cobrar` | Se acreditan a plazo, igual que VISA/Mastercard/AMEX/PAYWAY, que ya eran `a_cobrar` |
| Juan USD Personal | `a_cobrar` | `efectivo` | Es una caja, no un saldo a cobrar |

Efecto en el panel: Disponible pasó de $30.646.344,48 a **$30.912.813,99** y A Cobrar de
$4.164.415,63 a **$3.897.946,11**. El total general quedó idéntico ($34.394.009,33), como debía.

Las 7 cuentas sin movimientos (VISA Corporativa, Visa Credicoop, Caja chica gastos, Caja General,
Cabal Credicoop A Pagar, Cabal Acreditaciones, Cabal Credicoop) se dejaron visibles por decisión
del usuario.

### Las cuentas "USD" están en pesos

`USD Online` ($3.991.824,57) y `USD Local` ($235.600) registran operaciones en dólares pero
**valuadas en pesos**, así que sumarlas al Disponible es correcto y no hace falta un campo de
moneda. La prueba: hay `movimiento_entre_cuentas` entre `USD Local` y `Caja del Local` (una caja en
pesos) **sin conversión alguna** — imposible si fueran monedas distintas.

### Pendiente: Mastercard con saldo negativo

`Mastercard` cierra en **-$442.152,98** con 719 cobros y 0 pagos. Una cuenta de tarjeta por cobrar
no debería deber plata; el negativo sale de acreditaciones que superan lo migrado. No es un problema
de tipificación y quedó sin analizar.

## 8d. Notas de crédito/débito de Compras — diagnóstico del 11/08/2026

### El importador las creó sólo con la cabecera

`ComprasContagram` detecta bien los tipos (`str_contains($tc, 'crédito')`, etc.). El problema está
en `MigrarComprasContagram::importarNota()`, que crea la nota con **monto, tipo y fecha y nada más**:

```php
/** NC/ND de compra: sin `compra_id` porque el export no dice a qué comprobante corrigen. */
'compra_id' => null,
```

El `compra_id` en null fue una decisión consciente y documentada. Lo que quedó sin justificación es
que tampoco se levantaron **`nro_comprobante`, los renglones ni las percepciones**, que el Excel sí
traía. Estado de las notas migradas:

```
id    legacy_id             nro_comprobante   impuestos   items
836   COMPRA-2026-ND-15     NULL              NULL          0
841   COMPRA-2026-NC-106    NULL              NULL          0
```

No es una limitación del origen: el archivo `Compras/{año} Compras.xlsx` tiene `Punto de Venta`,
`N° Factura`, `Producto/Servicio`, `Código`, `Cantidad`, `Precio unitario`, `Perc. IVA` y
`Perc. IIBB` en cada fila de nota. **Se puede recuperar todo reimportando.**

### El vínculo nota → compra no está en el export, pero se reconstruye

El Excel de Compras **no tiene** la columna `Documento que Ajusta` que sí se ve en la pantalla de
Contagram. Y —verificado con un caso de control— el `N° Factura` de una nota es **su propio
número**, no el de la compra que ajusta:

```
NOTA DE DÉBITO   Id 15     PV 57    N° 67262      ← A-0057-00067262, número de la nota
COMPRA           Id 2147   PV 11    N° 4800615    ← A-0011-04800615, la compra que ajusta
```

Las notas aparecen **intercaladas** junto a las compras del mismo proveedor y fecha, lo que hace
parecer que se corresponden por posición; es sólo el orden del listado.

**La llave real está en el otro archivo.** `Compras c- pago/{año}` trae, por compra, las columnas
`Total NC` y `Total ND`. Cruzándolas con los importes de las notas del mismo proveedor y buscando
qué subconjunto suma exactamente ese total, el vínculo queda **deducido, no adivinado**:

| | |
|---|---:|
| Notas de compra en los Excel | 149 |
| Compras con notas | 100 |
| Grupos resueltos sin ambigüedad | **103** |
| Grupos ambiguos | 4 |
| Grupos sin solución | 10 |
| **Notas asociables** | **132 de 149** |

### Segunda pasada: 139 de 149

La primera pasada dejaba 10 grupos sin solución. La causa no era el método sino **cómo se calculaba
el importe de la nota**: varias tienen más de un renglón y se estaba tomando el `Total Compra` de la
primera fila, así que entraban cortas y nunca cerraban. Aplicando el mismo criterio que en ventas
—si el total se repite igual en todas las filas es uno solo, si difiere se suma— se resolvieron 9
de esos 10:

| | 1ª pasada | 2ª pasada |
|---|---:|---:|
| Notas mapeadas | 132 | **139** de 149 |
| Conflictos (nota en 2 compras) | 0 | **0** |
| Grupos ambiguos | 4 | 6 |
| Grupos sin solución | 10 | **1** |

Control: las 5 notas verificables contra las capturas de Contagram (compras 2107 y 2147) siguen
mapeando correcto en ambas pasadas.

**Las 10 notas que quedan fuera** — conviene cargarlas a mano, no seguir refinando el emparejador:

```
sin solución
  2024  compra 1300  FV              NC = 518.623,28

ambiguos (2 combinaciones posibles cada uno)
  2025  compra 1649  FV              NC = 327.306,98
  2025  compra 1929  FV              ND =  52.885,30
  2025  compra 1772  MERCADO LIBRE   ND =   2.396,45
  2025  compra 2035  MERCADO LIBRE   ND =   2.396,41
  2026  compra 2147  MERCADO LIBRE   ND =     461,09   ← su ND es la 15 (verificado)
  2026  compra 2230  MERCADO LIBRE   ND =     461,09
```

Los cuatro de Mercado Libre son las notas de "ANULACIÓN BONIF. POR USO DE LA PLATAFORMA", de
**importe idéntico** entre sí, indistinguibles por monto: se desempatan por fecha, cada nota es del
mismo día que su compra. Los dos de FV aparecieron recién en la segunda pasada y están sin analizar.

Gotcha de archivos: `2023 Comrpas c_ cobro.xlsx` tiene el nombre mal escrito ("Comrpas"), y los de
2021-2024 dicen `c_ cobro` en vez de `c_ pago`. Un glob por `{año}*c_*.xlsx` los cubre a todos.

### Regla: una nota sobre un comprobante migrado NO debe afectar stock

Los comprobantes del histórico se importaron **sin generar movimientos de stock**, para que el
inventario quedara exactamente como estaba. Una nota que ajuste stock sobre uno de ellos descuenta
algo que nunca entró por sistema. Pasó: una NC cargada a mano sobre la compra 2381 dejó el producto
100015 en **−1**; al borrarla volvió a 0.

La pregunta correcta no es "¿la nota afecta stock?" sino **"¿el comprobante que ajusta había movido
stock?"**. Para todo lo migrado, la respuesta es no.

### El aviso de la pantalla miente

`compras.js` decide con `const sinProductos = (resp.data || []).length === 0;` sobre el resultado de
`itemsDisponibles()`, que sólo devuelve productos con `pendiente > 0`. El cartel resultante dice
*"Este comprobante no tiene productos (sólo conceptos/servicios)"*, pero **colapsa tres situaciones
distintas**:

1. el comprobante no tiene productos (el único caso donde el texto es cierto);
2. tiene productos pero **ya fueron ajustados** por notas anteriores;
3. es un comprobante migrado, donde además **no corresponde** ajustar stock.

Mismo código duplicado en `ventas/_modal_ncnd.blade.php`. Sin corregir.

### Pendiente menor

El movimiento de stock **431** (−1 del producto 100015) quedó en la tabla aunque su nota fue
borrada. El saldo está bien (0, la columna se revirtió), pero aparece como un ajuste huérfano en el
informe de movimientos.

## 9. Cosméticos

- **~1.446 clientes con `created_at` con día y mes invertidos**, del import anterior.
- **Bug ARCA `10051`** ("los importes de AlicIVA no se corresponden con los porcentajes"), visto una
  vez el 03/08/2026. A vigilar cuando se facturen ventas con IVA mixto.
