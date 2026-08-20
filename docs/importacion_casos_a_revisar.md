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
| `remitos.venta_id` tenía el mismo problema (spec 064) | migración `..._hacer_venta_id_nullable_en_remitos_table` | **crear un remito de Compra fallaba**; nunca se había probado |
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
6. **Revisar si hay más tablas con el patrón `venta_id`/`compra_id` donde uno de los dos quedó
   NOT NULL en la migración** (spec 064, T038). Ya pasó dos veces —`notas_credito_debito` y
   `remitos`— y en ambos casos nunca se detectó porque el camino de Compras jamás se ejercitó. Vale
   la pena un barrido explícito de `information_schema.columns` sobre todas las tablas que declaran
   ambas FKs, en vez de esperar a que aparezca un tercer caso por accidente.

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

### A VERIFICAR CONTRA CONTAGRAM: 56 percepciones deducidas ($178.147,76)

El export **no desglosa la percepción de IVA**: la suma dentro de `Total Compra` pero deja su
columna vacía. Se ve en la NC 105 de la compra 2107:

```
Subtotal con Descuento   -29.358,50
IVA - 21%                 -6.165,29     suma:  35.523,79
Total Compra             -37.935,75     hueco:  2.411,96
```

Al abrir esa misma nota en Contagram, el hueco es exactamente una **`IVA (Percepción)` de
$2.411,96**. Sin cubrirlo, el desglose de 56 de las 149 notas no cierra contra su propio monto.

`ComprasContagram::armarConceptos()` lo carga como `IVA (Percepción)` cuando el residuo es
positivo. **Es una deducción, no un dato del archivo:**

| | |
|---|---:|
| Notas que cierran solas | 93 |
| Notas con residuo deducido | **56** |
| Importe total deducido | **$178.147,76** |
| Casos verificados contra Contagram | **2** (NC 105 y NC 110) |

Los dos verificables dan exacto ($2.411,96 y $3.365,11), pero **las otras 54 no se contrastaron**.
Queda pendiente abrirlas en Contagram y confirmar que el concepto sea percepción de IVA y no otra
cosa (IIBB, sellos, impuestos internos). Si alguna resulta distinta, se corrige el nombre del
concepto: el monto ya es correcto, porque sale del total.

Los residuos negativos (visto uno: `COMPRA-2021-NC-6`, −$21,48) **no se cargan**: serían otra cosa
y quedan sin explicar.

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

---

## 10. Catálogo real de cuentas de Contagram — relevado el 12/08/2026

Lista completa dictada por el usuario desde el panel de Saldos de Contagram (sin recortes de scroll).
Es la **referencia contra la cual tiene que calcar el catálogo de cuentas del CRM**. Los dos
"Saldo Cta Cte" son calculados, no son cuentas.

⚠️ **Los importes que muestra ese panel son un total filtrado, no el acumulado de todos los tiempos.
No sirven para conciliar saldos y no se usan acá.** La conciliación de importes se hace contra la
columna `Saldo` de cada Excel de `Cuentas/`, que sí es acumulada.

**Contagram tiene 21 cuentas; el CRM (VPS) tiene 28.** No falta ninguna: sobran 7.

| Bloque en Contagram | Cuenta | En el CRM (VPS) | Estado |
|---|---|---|---|
| A Cobrar | Cheque de Terceros | Cheque de Terceros | ✅ |
| A Cobrar | Amex | AMEX | ✅ |
| A Cobrar | Cabal Acreditaciones | existe vacía; los 54 mov entraron como **`Cabal`** | ⚠️ duplicado de nombre |
| A Cobrar | Maestro | Maestro | ✅ |
| A Cobrar | Mastercard | Mastercard | ✅ |
| A Cobrar | Nulo | Nulo, tipada `efectivo` | ⚠️ cae en Cajas, no en A Cobrar |
| A Cobrar | PAYWAY QR | PAYWAY QR | ✅ |
| A Cobrar | Retenciones | Retenciones, tipada `efectivo` | ⚠️ cae en Cajas, no en A Cobrar |
| A Cobrar | Visa | VISA | ✅ |
| A Pagar | Cheque Propio | Cheque Propio | ✅ |
| A Pagar | Cabal Credicoop | existe vacía; los 9 mov entraron como **`Cabal A Pagar`** | ⚠️ duplicado de nombre |
| A Pagar | Visa Credicoop | existe vacía; los 22 mov entraron como **`Visa Credicoop A Pagar`** | ⚠️ duplicado de nombre |
| Cajas | Caja chica gastos | existe, **0 movimientos** | ❌ falta el export de origen |
| Cajas | Caja del Local | Caja del Local | ✅ |
| Cajas | Caja General Abajo | Caja General Abajo | ✅ |
| Cajas | Juan USD Personal | existe con **1 solo movimiento** (vino de un cobro de venta) y **sin saldo inicial** | ❌ falta el export de origen |
| Bancos | Banco Credicoop | Banco Credicoop | ✅ |
| Bancos | Banco Santander Río | Banco Santander Río | ✅ |
| Bancos | Galicia | **Banco Galicia** | ⚠️ nombre distinto (cosmético) |
| Bancos | Mercado Pago | Mercado Pago | ✅ |
| Bancos | USD online | USD Online, tipada `efectivo` | ⚠️ cae en Cajas, no en Bancos |

### Las 7 cuentas que sobran en el CRM

| Cuenta | Mov. | Qué es | Qué hacer |
|---|---:|---|---|
| `Cabal` | 54 | alias de archivo de `Cabal Acreditaciones` | renombrar y borrar la vacía |
| `Cabal A Pagar` | 9 | alias de archivo de `Cabal Credicoop` | renombrar y borrar la vacía |
| `Visa Credicoop A Pagar` | 22 | alias de archivo de `Visa Credicoop` | renombrar y borrar la vacía |
| `Cabal Credicoop A Pagar` | 0 | no existe en Contagram | ocultar (`visible = 0`) |
| `Caja General` | 0 | no existe en Contagram | ocultar |
| `VISA Corporativa` | 0 | no existe en Contagram | ocultar |
| **`USD Local`** | **524** | **no existe en Contagram**, pero tiene un archivo propio (`USD local_.xlsx`) y 524 movimientos | **investigar antes de tocar** |

### ✅ RESUELTO: `USD Local` **es** `Juan USD Personal`

Confirmado el 12/08/2026 por `legacy_id` contra la ficha de la cuenta en Contagram (no por importes):
los movimientos de la cuenta `USD Local` del CRM traen exactamente los Id de Contagram del perfil de
**Juan USD Personal** — `62` (31/07, Ingreso "Actualizacion USD" $195.977,65), `3364`/`3363` (28/07,
Pago JPD AMOBLAMIENTO), `3357`/`3356`/`3355` (24/07), `3338`…`3335` (17/07, Pompei SRL).

El archivo `USD local_.xlsx` es el export de esa cuenta y llegó con otro nombre, así que el
importador creó una cuenta nueva. **Acción: fusionar `USD Local` → `Juan USD Personal`** (mover los
524 movimientos, trasladar `saldo_inicial`, borrar `USD Local`). El único movimiento que ya tiene
`Juan USD Personal` es el Id 26142 de Contagram (07/08, Cobro GARBERS ANGELES), **posterior al corte
del export**, así que no hay riesgo de duplicar.

**Ya no hace falta pedir el export de `Juan USD Personal`.**

### ⚠️ Los nombres del panel de Saldos están recortados — el nombre real está en la ficha

Hallazgo del 12/08/2026, y **cambia el plan de renombrado**. El panel de Saldos muestra
"Visa Credicoop", "Cabal Credicoop", "Cabal Acreditaciones", pero al abrir cada cuenta el título
real es con sufijo:

| Nombre en el panel | **Nombre real (ficha)** | Cuenta del CRM que tiene los datos | Mov. |
|---|---|---|---:|
| Visa Credicoop | **Visa Credicoop a Pagar** | `Visa Credicoop A Pagar` | 22 |
| Cabal Credicoop | **Cabal Credicoop a Pagar** | `Cabal A Pagar` | 9 |
| Cabal Acreditaciones | **Cabal Acreditaciones a Cobrar** | `Cabal` | 54 |
| Visa | **Visa a Cobrar** | `VISA` | 5.660 |
| Mastercard | **Mastercard a Cobrar** | `Mastercard` | 1.333 |
| PAYWAY QR | **PAYWAY QR a Cobrar** | `PAYWAY QR` | 524 |
| Nulo | **Nulo a Cobrar** | `Nulo` | 32 |

Las cajas y bancos **no** llevan sufijo: `Caja General Abajo`, `Caja chica gastos`,
`Juan USD Personal` se titulan igual en la ficha que en el panel. El sufijo es de las cuentas de
tarjeta/valores, y **confirma la retipificación pendiente**: `Nulo a Cobrar` y `Retenciones` son
`a_cobrar`, no `efectivo`.

Verificado por `legacy_id` fila por fila contra las fichas (Cabal Acreditaciones: Ids 4551, 3774,
16254, 3739, 16236, 16222, 2928, 27, 2925, 12655 — coinciden todos; los conteos también: 54, 9 y 22).

**Consecuencia**: `Visa Credicoop A Pagar` del CRM **ya tiene el nombre correcto** (no hay que
renombrarlo), y la cuenta vacía a eliminar es `Visa Credicoop` (id 22). Igual para Cabal: sobra
`Cabal Credicoop` (id 21) y el nombre destino de `Cabal A Pagar` es `Cabal Credicoop a Pagar`
(que en el CRM ya existe vacía como `Cabal Credicoop A Pagar`, id 28).

**Regla para el futuro: el nombre canónico de una cuenta se toma de la ficha, nunca del panel de
Saldos** — igual que la regla de que los importes del panel son un total filtrado y no sirven.

### `Caja chica gastos`: es la caja de gastos menores del local

Relevada su ficha el 12/08/2026. Patrón claro y confirmado por el usuario:

- **Se fondea con `Movimiento entre Cuenta` desde `Caja del Local`** (ej. $500.000 el 16/06/2026,
  $101.000 el 06/01/2026, $130.000, $180.000, $200.000, $300.000…).
- **Se gasta en menudeo**: Ferretería, Sube, Supermercado, Farmacia, Cabify - Uber, Otro/Otros.
- Ocasionalmente recibe algún `Cobro` (ej. Id 21330 "de nc", Id 20682).
- Los Id de los gastos (9188, 8454, 8185, 7943…) **son del mismo espacio de Id que el módulo
  Gastos**, o sea que estos movimientos deberían cruzar con los gastos ya importados de `Gastos/`.
  Al importar el archivo hay que chequear que no se duplique contra esos gastos.

**Es la única cuenta que sigue necesitando export.** La ficha existe y tiene botón Exportar, así que
el dato está disponible.

### Otros patrones útiles relevados en las fichas

- **Las cuentas de tarjeta se vacían contra el banco**: `Cabal Acreditaciones a Cobrar` liquida con
  `Movimiento entre Cuenta` hacia **Banco Credicoop** (por eso cierra en ~$0), y `Visa a Cobrar`
  hacia **Galicia** (Ids 5303, 5302, 5300, 5297, 5295…). Sirve para entender el saldo negativo de
  Mastercard (§8c pendiente): hay que ver contra qué banco liquida.
- **`Otros Ingresos` se usa como ajuste manual**: en `Cabal Acreditaciones` el Id 27 es un `Ingreso`
  de $5.719,14 con observación *"Borro cobro venta 9088. Paso a 9094"*. No es un ingreso real de
  plata, es una corrección. Hay 61 movimientos tipo `ingreso` migrados: conviene mirar sus
  observaciones antes de tratarlos como ingresos genuinos en cualquier informe.

### Cuenta sin export de origen (a pedir al usuario)

Sólo `Caja chica gastos`. Formato: las mismas 11 columnas del resto de `Cuentas/`,
con la fila de `Saldo Inicial`, y ensanchando las columnas antes de exportar para no repetir el bug
de las celdas `######`.

### Fichas relevadas el 12/08/2026 — hallazgos por cuenta

**`Caja General Abajo` — la diferencia de $1.200.000 NO es un error del import.** Su último
movimiento en Contagram es el Id 3382 (06/08/2026, Pago a Pompei SRL, −$1.200.000) que deja la
cuenta en $0. El export de `Cuentas/` corta el 05/08, así que el CRM cierra en $1.200.000: le falta
exactamente ese pago posterior al corte. Es la confirmación más limpia de que **las diferencias
grandes son corte temporal, no import**. Patrón de la cuenta: se fondea con `Movimiento entre Cuenta`
desde `Caja del Local` y se usa para pagos grandes a proveedores (Pompei SRL, JPD AMOBLAMIENTO) y
gastos fijos (Sueldos, Alquiler, Comisiones, Extras hijos, Juan Personal/Ahorro).

**`Mastercard a Cobrar` — resuelve la pregunta abierta de §8c: liquida contra Galicia.** Los
`Movimiento entre Cuenta` hacia `Galicia` (Ids 5304, 5299, 5298, 5292, 5285, 5276, 5272, 5270,
5265, 5259, 5255, 5253…) son las acreditaciones netas del posnet, con observaciones tipo
*"Mov retencion posnet"*. El saldo negativo sale de que esas transferencias a Galicia superan a los
cobros migrados en el período. **Mismo patrón en `PAYWAY QR a Cobrar` y en `Visa a Cobrar`**: las
tres tarjetas se vacían contra Galicia; `Cabal Acreditaciones a Cobrar` es la excepción, liquida
contra Banco Credicoop.

**`Nulo a Cobrar` — es una cuenta de ajustes contables, no una cuenta real de plata.** Arranca con
un **`Saldo Inicial` negativo de −$133.127,79** (08/02/2022) y sus movimientos son correcciones:
*"Se modifica pago por mal cobrado"*, *"Mov para sacar gasto ajuste caja mal…"*, más cobros del
cliente sentinela `NO USAR MAS`. Cierra en $0,13. No hay que interpretarla como caja ni como
cobranza real.

**`VISA Corporativa` — confirmado por el usuario: no existe en Contagram.** Se oculta
(`visible = 0`), no se pide export.

### Nota sobre los filtros de las fichas

Las fichas de `Mastercard a Cobrar` y `PAYWAY QR a Cobrar` se abrieron con el rango **13 Jul - 12 Ago**
aplicado. La columna `Balance` sí es acumulada (arrastra el saldo previo), pero **la lista de
movimientos está filtrada**: no confundir "22 resultados" de una ficha filtrada con el total de
movimientos de la cuenta. El conteo total sólo es confiable cuando la ficha está sin filtro de fecha
(fue el caso de Cabal Acreditaciones 54, Cabal Credicoop 9, Visa Credicoop 22).

### `Caja chica gastos` se puede reconstruir sin el export (12/08/2026)

Los `Gastos/{año} Gastos.xlsx` traen una columna **`Medio de pago`** que es la cuenta de tesorería,
y el import ya la usó: `gastos.cuenta_tesoreria_id` está poblado. Hay **120 gastos con cuenta
`Caja chica gastos` por $3.672.808,76** — el dato existe, lo que falta es el `movimiento_tesoreria`
correspondiente (el import de gastos no lo generó; los movimientos salieron todos de `Cuentas/`).

El fondeo también está: hay **50 `movimiento_entre_cuentas` por −$3.643.377,01** con detalle
`Caja chica gastos`, registrados del lado de `Caja del Local`. Sólo falta la pata de entrada.

Reconstrucción estimada: +3.643.377,01 (fondeo) −3.672.808,76 (gastos) +cobros ≈ $25.297,60 contra
los **$33.137,66** que muestra Contagram → **quedan ~$7.840 sin explicar**. Alcanza para armar la
cuenta, **no para darla por buena**: sin la columna `Saldo` del export no hay control independiente,
que es la regla que atrapó todos los errores de este import (§8). **Recomendación: reconstruir desde
`gastos` + fondeo, y pedir igual el export sólo como cifra de control.**

Aviso: la misma lógica revela que **ninguna cuenta sin archivo en `Cuentas/` tiene movimientos de
gasto**, aunque sus gastos sí estén en la tabla `gastos`. Al reconstruir hay que hacerlo sólo para
`Caja chica gastos`, o se duplican los gastos de las cuentas que sí vinieron de `Cuentas/`.

### ✅ Verificación del import de tesorería contra los Excel — 12/08/2026

Hecha sobre un **clon de la base del VPS en local**, comparando cuenta por cuenta el saldo del CRM
(sólo movimientos con `legacy_id`) contra la **última fila de la columna `Saldo`** de cada archivo de
`Cuentas/`, leyendo las fechas con la regla de día/mes invertido.

**Resultado: las 20 cuentas cierran. No falta un peso.**

- **15 cierran exacto** (13 al centavo + 2 que difieren sólo en el signo: los archivos de cuentas
  `a_pagar` muestran la deuda en positivo y el CRM la guarda en negativo — `Cabal Credicoop a Pagar`
  $212.175,83 y `Visa Credicoop a Pagar` $174.574,63. Es convención, no error).
- **5 tenían diferencia, y las 72 filas involucradas son exclusiones deliberadas del propio
  importador** (`MigrarTesoreriaContagram`), no movimientos perdidos:

| Cuenta | Filas | Causa |
|---|---:|---|
| Mercado Pago | 65 | 24 posteriores al corte + **41 cobros de ventas de ML que el CRM ya había generado** |
| Visa a Cobrar | 3 | posteriores al corte (06-07/08) |
| Mastercard a Cobrar | 2 | posteriores al corte (06/08) |
| Caja General Abajo | 1 | Id 3382, 06/08, Pago a Pompei SRL −$1.200.000 |
| Cheque Propio | 1 | Id 3320, **fechado 24/08/2026** — un cheque propio a vencer, o sea del futuro |

Las dos reglas están escritas en el importador y son correctas: `CORTE = 2026-08-05` (del 06/08 en
adelante manda el CRM, importar duplicaría lo que la app ya generó) y la exclusión de los cobros de
ML ya convertidos.

**Consecuencia práctica: la diferencia contra el panel de Contagram de hoy no es un problema del
import.** Los pesos están, por el otro camino (movimientos generados por la app, `legacy_id IS NULL`).

**Trampa metodológica anotada**: comparar el saldo total del CRM (legacy + no-legacy) hasta la fecha
de corte tampoco cierra, porque el 05/08 se solapan el final del Excel y la operación real del CRM
de ese mismo día. **La única comparación limpia es sólo-legacy contra el Excel**, tratando las 72
exclusiones como diferencia esperada y cuantificada.

### Cambios aplicados en local (clon del VPS) — 12/08/2026

Pendientes de subir al VPS. La base local es un clon exacto del VPS del 12/08 00:53.

1. **Fusiones** — las cuentas "vacías" no lo estaban: tenían cobros/pagos/gastos del import de
   ventas/compras/gastos, que usa el nombre del Excel de origen y por eso creó ids paralelos.
   - `USD Local` (26) → `Juan USD Personal` (13): 524 movimientos.
   - `Cabal Acreditaciones` (17, con 17 cobros) → `Cabal` (25).
   - `Cabal Credicoop` (21, 2 pagos) + `Cabal Credicoop A Pagar` (28, 4 gastos) → `Cabal A Pagar` (24).
   - `Visa Credicoop` (22, 9 pagos) → `Visa Credicoop A Pagar` (27).
2. **Nombres canónicos** (los de la ficha de Contagram): `Cabal Acreditaciones a Cobrar`,
   `Cabal Credicoop a Pagar`, `Visa Credicoop a Pagar`, `Visa a Cobrar`, `Mastercard a Cobrar`,
   `PAYWAY QR a Cobrar`, `Nulo a Cobrar`.
3. **Tipos**: `Nulo a Cobrar` y `Retenciones` → `a_cobrar`; `USD Online` → `banco`.
4. **Borradas**: `Caja General` y `VISA Corporativa`. No existen en Contagram (confirmado por el
   usuario el 12/08/2026) y estaban limpias de verdad — 0 referencias en las 6 tablas que apuntan a
   `cuenta_tesoreria_id`, saldo inicial 0, `es_sistema = 0`. Primero se habían ocultado por la
   lección de §8 ("nunca borrar lo que no está en uso"), pero esa lección aplica a lo que *puede*
   estar en uso: acá se verificó que no lo está. **Revierte la decisión de §8c de dejar visibles las
   cuentas sin movimientos**, que se tomó antes de saber que no existían en Contagram.
5. **`Caja chica gastos` reconstruida**: 120 movimientos de gasto desde `gastos` + 50 contrapartidas
   de fondeo (36 desde `Caja del Local`, 13 desde `Caja General Abajo`, 1 desde `Juan USD Personal`),
   todos con `legacy_id` prefijado **`RECON-19-`** para poder distinguirlos y revertirlos.
   Cierra en **−$29.431,75** contra los $33.137,66 de Contagram: **faltan los cobros**. Los 3 cobros
   que la tabla `cobros` asigna a esa cuenta ($630.929,41) **no son confiables** — vienen del import
   de ventas, que usa "primer medio de pago" y fecha de emisión (§5). **No se cargaron.**
   Sigue haciendo falta el export de Contagram, aunque sea sólo como cifra de control.

De 28 cuentas se pasó a **21: exactamente las 21 de Contagram**.

**Pendiente de confirmar contra ficha antes de renombrar**: `AMEX`, `Cheque de Terceros`,
`Cheque Propio`, `Retenciones`, `Maestro` (¿llevan sufijo "a Cobrar"/"a Pagar"?) y `Banco Galicia`
(el panel lo llama `Galicia`). No se tocaron: sólo se renombró lo verificado en una ficha.

## 11. Las NC/ND sin comprobante asociado inflan la Cta Cte de Clientes — 12/08/2026

Detectado a partir de una observación del usuario ("hay unas 10 notas sin compra asociada"). El
problema existe pero es **mucho más grande y está del lado de Ventas**: los 10 pendientes eran de
Compras, y ahí el mapeo llegó a 149 de 149.

Estado real de `notas_credito_debito` (859 notas):

| tipo | total | con `venta_id` | con `compra_id` | **sueltas** | monto suelto |
|---|---:|---:|---:|---:|---:|
| crédito | 780 | 5 | 130 | **645** | **$56.977.170,21** |
| débito | 79 | 1 | 19 | **59** | $2.259.667,47 |

**Esto explica el descuadre de la Cuenta Corriente de Clientes.** El `aging` calcula
`ventas.total + ND − NC − cobros`, y una NC sin `venta_id` no descuenta de ninguna factura:

```
Cta Cte Clientes CRM      $67.033.584,52
Cta Cte Clientes Contagram $8.579.530,87
diferencia                $58.454.053,65
NC de venta sin asociar   $56.977.170,21   → explica el 97,5 %
```

No es un problema de tesorería ni de saldos de cuenta (que cierran 19/20 contra los Excel), es de
**imputación**: la plata está bien, lo que está mal es a qué comprobante se le resta.

El precedente de Compras dice que se puede resolver: allá se reconstruyó el vínculo nota→compra sin
que el export lo trajera (ver §8d), y quedó 149/149.

### Mapeo ejecutado el 12/08/2026 — 598 de 692 (86,4 %)

Mismo método que Compras: el export `Ventas c- cobro` trae **`Total NC` y `Total ND` por venta**, y
se busca qué nota (o qué subconjunto de notas) del mismo cliente suma exactamente ese total. La suma
de control cierra: $56.972.371,64 en el `c/ cobro` contra $56.977.170,21 en las notas.

| Paso | Criterio | Notas mapeadas |
|---|---|---:|
| 1 | candidato único del mismo cliente con monto exacto | — |
| 2 | + desambiguación y subconjuntos de 2 a 4 notas | 594 |
| 3 | monto exacto y mismo año, sin exigir cliente (sólo si es único) | +4 |
| | **Total** | **598 de 692 (86,4 %)** |

**Efecto medido en la Cta Cte de Clientes: de $67.033.584,52 a $29.541.374,69.**

**Gotcha que costó 16 puntos de cobertura**: `Ventas/Ventas 2023.xlsx` trae el **encabezado en la
última fila** (7066 de 7067), no en la primera. Leyéndolo como los demás archivos, las 117 notas de
2023 quedaban sin cliente y no se podían matchear. Un lector de estos Excel tiene que buscar la fila
de encabezado en cualquier posición, no asumir la primera.

### Las 93 notas que quedan ($17.545.403,37)

No se resuelven con este método: sus montos no coinciden con el `Total NC` pendiente de ninguna
venta, ni por cliente ni por monto dentro del mismo año. Las ventas que las reclaman son en su
mayoría de **2021**, donde el export por-ítem trae sólo 16 notas contra las 17 de la base — o sea el
origen mismo está incompleto para ese año.

El vínculo real existe en Contagram, en la columna **"Documento que Ajusta"** de la pantalla de la
nota, que **ningún export trae**. Para cerrar el 13,6 % restante hace falta ese dato, o revisarlas a
mano. Son 93 notas: es viable.

Mientras tanto la Cta Cte de Clientes queda en $29,5 M contra los $8,58 M de Contagram. Las notas
sin mapear explican $17,5 M de esa diferencia; **los ~$3,4 M restantes son otra causa, todavía sin
identificar** (probablemente la imputación de cobros por cliente y no por factura, §5).

## 12. El aging de Cuenta Corriente ignoraba la fecha de corte — corregido el 12/08/2026

Detectado comparando el panel de Tesorería a tres fechas distintas (01/07, 03/08 y 05/08): los dos
"Saldo Cta Cte" devolvían **exactamente el mismo número en las tres** ($67.033.584,52 clientes y
$23.371.408,79 proveedores), mientras que en Contagram sí se movían.

`CuentaCorriente::bucketsEnSql()` recibía `$fecha` pero la usaba **sólo para clasificar en buckets
de vencimiento** (`DATEDIFF`, `a_vencer`). Los importes salían de todas las ventas/compras con todos
sus cobros/pagos/notas, sin corte: el resultado era siempre el saldo de hoy.

Corregido: se filtran por `<= fecha` los comprobantes (`fecha_emision`), los cobros/pagos (`fecha`),
las notas (`fecha_emision`) y el saldo inicial de cliente/proveedor (`saldo_inicial_fecha`, tratando
el nulo como siempre vigente). Ahora el total varía con el corte, como debe.

### Lo que el arreglo dejó a la vista: las fechas de pagos y cobros migrados no reconstruyen el pasado

Con el corte aplicado, Proveedores da **$1.894.946,66 al 01/07** contra los **$17.160.519,48** de
Contagram, y **sube** hacia agosto mientras que en Contagram **baja**. Pero **sin corte (o sea a
hoy) da $23.371.408,79 contra $23.841.392,08 de Contagram: 2 % de diferencia**.

O sea: **el saldo actual de proveedores está bien; lo que no se puede reconstruir es el saldo a una
fecha pasada.** Es el mismo defecto ya documentado en §5 para los cobros — el import les asignó la
fecha de emisión del comprobante y no la del pago real. Mientras esas fechas no se corrijan, el
filtro de fecha del panel es confiable para las cuentas de tesorería (que cierran 19/20 contra los
Excel) pero **no** para las dos filas de Cuenta Corriente.

Clientes es otro caso: ahí ni siquiera el saldo de hoy cierra ($67 M contra $8,58 M), y la causa son
las 645 notas de crédito sin `venta_id` (§11).

## 13. `Caja chica gastos` reconstruida desde la ficha — 12/08/2026

El usuario pasó capturas de la **ficha completa** de la cuenta en Contagram, y con eso se cerró sin
necesidad del export. Lo que faltaba no eran gastos (los 120 ya estaban en la tabla `gastos`) sino
**tres cobros y un pago**:

| Id Contagram | Fecha | Concepto | Importe |
|---|---|---|---:|
| 21330 | 27/10/2025 | Cobro Juan Ignacio (obs. *"de nc"*) | +44.729,35 |
| 17283 | 09/01/2025 | Cobro Maria Elena (obs. *"Cobro Mauricio"*) | +32.889,06 |
| 20682 | 05/09/2025 | Cobro Maria | +10.000,00 |
| 2268 | 09/01/2025 | Pago Ferreteria La de Olleros | −25.050,00 |

Resultado: la cuenta pasó de **−$29.431,75 a $33.136,66** contra los **$33.137,66** de Contagram —
**queda $1,00 de diferencia**, que debe estar en los movimientos anteriores al 24/07/2024 (la ficha
capturada no llega hasta el principio: su fila más vieja deja balance −$15.368,09, o sea que había
saldo antes).

Efecto en el panel al 01/07/2026: **Total Bancos cierra exacto** ($17.370.690,61) y **Total Cajas
queda a $1,00** ($12.796.428,68 contra $12.796.429,68).

### Dos pruebas directas del defecto de imputación de cobros (§5)

Al cruzar esos cobros con la tabla `cobros` aparecieron, en un universo de tres, dos casos del
problema ya documentado:

- **Fecha equivocada**: el cobro de $32.889,06 está en la base con fecha **2024-12-14** y en
  Contagram es del **09/01/2025**. Es la fecha de emisión de la venta, no la del cobro.
- **Cuenta y monto equivocados**: el cobro `id 16639` figura con **$553.311,00** en `Caja chica
  gastos`, pero a esa caja entraron **$10.000** (Id 20682 de Contagram). El resto se cobró por otro
  medio: el import asignó **el total al primer medio de pago listado**.

O sea que el defecto de §5 no es teórico y afecta importes por cuenta, no sólo fechas. **Ya no hace
falta pedir el export de `Caja chica gastos`.**

## 14. Cierre de la sesión del 12/08/2026 — qué quedó y qué falta

Todo lo de abajo está aplicado en el VPS a través de `contagram:normalizar-tesoreria`, idempotente
y verificado sobre un clon limpio de la base de producción antes de cada corrida.

### Resultados

| | Antes | Ahora | Control |
|---|---:|---:|---|
| Disponible al 01/07 | — | **$30.167.119,29** | Contagram $30.167.120,29 → **$1** |
| Cta Cte Clientes (hoy) | $67.033.584,52 | **$11.231.037,82** | Excel "A Cobrar" $11.086.350,32 |
| NC/ND sin comprobante | 704 ($59,2 M) | **2 ($0,00)** | — |
| Cuentas de tesorería | 28 | **21** | las 21 de Contagram |

Las 2 notas que quedan sueltas valen $0,00: la `2021-ND-1` (ningún comprobante la reclama) y la
`2022-NC-43`, que figura en $0,00 **también en Contagram**.

### El defecto de multi-renglón apareció en tres lugares

El importador tomaba el `Total Venta`/`Total Compra` de **una fila** del comprobante en vez de sumar
los renglones. Ya estaba documentado para las notas de compra (§8d) y volvió a aparecer en:

- **NC de venta 234** ($212.560,92 → $311.628,81), **498** ($54.179,66 → $108.359,32) y
  **534** ($65.409,49 → $130.818,99).
- **NC de compra 46** ($89.867,94 → $179.735,88, exactamente el doble).

Ninguna de esas podía matchearse por importe justamente porque el importe estaba mal. Si alguna vez
se re-importa un comprobante, revisar esto primero.

### Cta Cte de Proveedores: mejorada, no cerrada

2.343 de los 2.346 pagos migrados tenían la **fecha de emisión de la compra** en vez de la del pago.
Se re-fecharon **802** cruzando contra los movimientos de `Cuentas/` (que traen el `nro_comprobante`
de la compra que cancelan), con 0 ambigüedades. Al 01/07/2026 el saldo pasó de $1.035.345,99 a
**$11.350.848,54**, contra $17.160.519,48 de Contagram.

**Techo**: 1.202 pagos no tienen ningún movimiento que los respalde —esos movimientos vinieron sin
`nro_comprobante`— y siguen con la fecha de emisión. Sin eso, el saldo de Proveedores **a una fecha
pasada** no se puede terminar de reconstruir.

### ✅ El saldo de Proveedores de HOY cierra al centavo (12/08/2026)

Corrección: se venía arrastrando de §7 que faltaban las **compras 2021-2024 en formato "c/ pago"**.
**No faltan** — están en `public/imports/Compras c- pago/`, sólo que los archivos de 2021 a 2024 se
llaman `Compras c_ cobro.xlsx` (y uno `Comrpas`, con la r cambiada) aunque el contenido es el
formato de pago: mismas 40 columnas, con `Pagado`, `A Pagar` y `Medio de Pago`.

Con la columna `A Pagar` como control independiente, sobre las 2.380 compras del resumen:

```
Excel "A Pagar"                      $19.039.453,15
- pagos cargados en el CRM el 11/08     $467.123,48
                                     ───────────────
                                     $18.572.329,67  = el saldo de la base, exacto
```

Los tres pagos son de compras posteriores al corte (`COMPRA-2026-FC-2375/2377/2379`), mismo caso que
las 8 ventas de más arriba. Y **sólo 3 de 2.380 compras tienen el `Pagado` distinto al de la base**:
la imputación de pagos por comprobante está bien. Lo único que sigue mal es **la fecha** de 1.202 de
esos pagos.

### La diferencia entre los dos exports de Compras

Mismo par que en Ventas, y conviene no confundirlos:

| Carpeta | Granularidad | Trae |
|---|---|---|
| `Compras/` | una fila **por renglón** (1.158 filas en 2021) | `Producto/Servicio`, `Código`, `Cantidad`, `Costo`, `Precio unitario`, `Afecta Stock` |
| `Compras c- pago/` | una fila **por comprobante** (322 en 2021) | `Total ND`, `Total NC`, `Pagado`, `A Pagar`, `Estado`, `Medio de Pago` |

El resumen **no trae la fecha de cada pago**, sólo el medio y el acumulado pagado. Por eso la fecha
real sigue saliendo únicamente de `Cuentas/`.

### Pendientes

1. **$144.687,50** de diferencia en Cta Cte Clientes contra el Excel.
3. **56 percepciones deducidas** ($178.147,76) en notas de compra, a verificar (§8d).
5. **$1,00** en Caja chica, en movimientos anteriores al 24/07/2024.
6. **16 tests fallando con 403** — preexistentes, de permisos, no de lógica.

## `VentaController::destroy()` permite eliminar una Venta con comprobante fiscal emitido

Hallazgo del 12/08/2026, al implementar la spec 063 (cancelaciones de Mercado Libre posteriores a
la venta). No es parte de esa spec —que sólo detecta y avisa, no toca el circuito fiscal (Principio
III)— pero apareció al revisar cómo se resuelve un aviso y hay que dejarlo registrado por separado
(research.md §R7 de esa spec).

`VentaController::destroy()` hoy hace `$venta->delete()` sin ninguna verificación de si la Venta
tiene un comprobante ya autorizado por ARCA (CAE emitido). Eso significa que **el sistema permite
eliminar una factura ya autorizada**, cuando lo correcto para revertir una venta facturada es emitir
una nota de crédito (el comprobante no debe desaparecer). La eliminación sin control debería quedar
reservada a ventas sin comprobante fiscal emitido.

Pendiente: agregar la verificación en `VentaController::destroy()` (o donde corresponda del dominio)
antes de permitir el `delete()`, y decidir si se bloquea del todo o se exige confirmación explícita.
No se resuelve en la spec 063 para no ampliar su alcance.

## 15. Cuenta Corriente de Proveedores conciliada — 12/08/2026

**Alcance: la comparación contra Contagram vale hasta el 01/08/2026.** Después los dos ambientes
divergen por operación propia del CRM (ventas de Mercado Libre desde el 22/07, más las cargadas a
mano). Para verificar el import, cualquier corte anterior sirve; posteriores, no.

| Corte | CRM | Contagram | Diferencia |
|---|---:|---:|---:|
| 01/07/2026 | $17.165.169,44 | $17.160.519,48 | +$4.649,96 |
| 01/08/2026 | $14.157.574,03 | $14.152.924,06 | +$4.649,97 |

Venía de **+$9,8 M**. Tres correcciones, en este orden:

1. **Re-armado de pagos** con el informe "Cuentas Corrientes - Movimientos de Proveedores" filtrado
   por `Operación = Pago`, que trae `Id Compra`, fecha real y medio. 222 fechas corregidas y 635
   compras rearmadas (el importador consolidaba en un pago lo que en Contagram son varios): 3.333
   pagos contra 2.346, **misma suma total y mismo `Pagado` por compra**.
2. **Anticipos**: un pago anterior a la emisión de su comprobante resta de la deuda. Un solo pago a
   Mercado Libre de $9.535.004,71 (31/07, facturado el 04/08) inflaba el saldo al 01/08 en esa cifra.
3. **Neteo de saldos a favor** en el total.

### La diferencia que queda no es del CRM

Calculando el saldo **desde el propio informe de Contagram** con nuestro mismo criterio:

```
              desde el Excel      nuestro aging     panel de Contagram
01/07        17.165.169,25       17.165.169,44        17.160.519,48
01/08        14.157.573,83       14.157.574,03        14.152.924,06
```

Reproducimos el informe con **19 centavos** de redondeo. Los $4.649,96 están **entre el informe de
Contagram y su propio panel**. Es la segunda vez que aparece ese desfasaje: en Mercado Pago el export
daba $16.382.756,42 al 01/08 y el panel $15.955.229,51 ($427.526,91 de diferencia).

**Regla que se desprende: el panel de Saldos de Contagram no reproduce sus propios exports. Para
conciliar hay que usar los exports, nunca el panel.**

### Verificación por comprobante

- **2.318 compras** con saldo idéntico al 01/07 contra el informe: **0 diferencias**.
- Agregados de compras, pagos, NC y ND: coinciden al centavo.
- Único faltante real: **la compra Id 85 del 24/04/2021 (PERSONAL, $683,08) no se importó**. Su pago
  tampoco, así que se cancelan y no afectan ningún saldo, pero el comprobante falta.

### Pendiente: Clientes

Sigue sin cerrar (−$221.731,28 al 01/07 y +$3.132.875,97 al 01/08 — oscila, no es una constante).
La causa está identificada: **23.213 de los 23.228 cobros tienen la fecha de emisión de su venta**,
no la del cobro real ($1.522 M). Es el defecto de §5, que del lado de pagos ya se resolvió.

Se cierra con el mismo método: el informe **"Movimientos de Clientes" filtrado por `Operación = Cobro`**.
El `Ventas c- cobro` **no sirve** para esto: es una fila por venta, con `Cobrado` acumulado y los
medios concatenados (`Mercado Pago - Mercado Pago`), sin fecha ni importe por cobro.

## 16. Tesorería cerrada — y hasta qué fecha se puede comparar (13/08/2026)

**Verificado por el usuario: las cajas cierran exacto al 05/08/2026.** Todo lo que difiere después
es operación registrada en un sistema y no en el otro, no error de migración.

### Cada archivo de `Cuentas/` tiene su propio corte

Es el hallazgo que faltaba y explica los últimos descuadres:

| Archivo | Llega hasta |
|---|---|
| `Caja abajo.xlsx` | **06/08** — trae el pago Id 3382 a Pompei SRL |
| `Caja local.xlsx` | **05/08** — el pago Id 3383 no está en ningún archivo |

El importador usa un corte único (`CORTE = 2026-08-05`) con un criterio correcto —"del 06/08 en
adelante manda el CRM"— pero que **sólo vale para los cobros**, porque la app los genera sola al
convertir órdenes de Mercado Libre. **Para los pagos a proveedores no vale**: no los genera nadie,
así que quedaron en `pagos` sin movimiento de tesorería. Descontaban de la deuda del proveedor pero
no de la caja: `Caja General Abajo` mostraba $1.200.000 y `Caja del Local` $500.000 que ya no
estaban.

Resuelto como regla en `contagram:normalizar-tesoreria`: todo pago migrado posterior al corte que no
tenga ya un movimiento equivalente genera el suyo. Fueron 5, por $2.169.538,13.

### La contracara: por qué NO se importa el resto

De los 31 movimientos del export posteriores al corte, **28 ya estaban cubiertos**:

- **12** coinciden exacto con un movimiento propio del CRM.
- **16 son los mismos cobros con UN CENTAVO de diferencia**: el Excel trae `253.464,20` donde el CRM
  tiene `253.464,19`, y el nombre real del cliente donde el CRM tiene el apodo de ML
  (`Alexiana Wolf` / `ALEXIANAWOLF`). **Importarlos duplicaba $2,6 M en Mercado Pago.**

Un cruce por importe exacto no los detecta. Es el mismo patrón de centavos que ya había aparecido
con las notas de crédito.

### Hasta qué fecha vale comparar contra Contagram

| Qué | Fecha límite | Por qué |
|---|---|---|
| Cajas y bancos | **05/08** | después, el CRM registra operación propia |
| Mercado Pago | **22/07** | desde ahí el CRM sincroniza Mercado Libre y Contagram no |
| Cta Cte Proveedores | **01/08** | ídem, más los pagos cargados a mano en el CRM |

Comparar más allá de esas fechas es perseguir un número que **no debe coincidir**. Al 13/08 la única
caja que difiere es `Caja del Local` (+$77.145,55), y esa cuenta tiene 69 movimientos posteriores al
06/08, **todos propios del CRM**.

### Estado

- **Cajas y bancos**: cierran al 05/08. Único desvío estructural: $1,00 en `Caja chica gastos`.
- **Cta Cte Proveedores**: conciliada hasta el 01/08 ($4.649,96, y esa diferencia está entre el
  informe de Contagram y su propio panel, no en el CRM).
- **NC/ND**: 2 sueltas de 704, ambas en $0,00.
- **Cta Cte Clientes**: pendiente del informe de Movimientos de Clientes filtrado por `Cobro`.

## 17. Cuenta Corriente de Clientes — cobros reconstruidos (13/08/2026)

Cerrado con el informe **"Cuentas Corrientes → Movimientos de Clientes" filtrado por
`Operación = Cobro`** (`public/imports/cobros/`), 24.828 filas del 02/08/2021 al 12/08/2026.

### El informe sirve, y es la mejor fuente que apareció

- **100% de las filas traen `Id Venta`.** Sin huérfanos.
- La columna `Emisión` es **la fecha real del cobro**, no la de la factura: 2.683 filas difieren de
  `Fecha Factura Aplicada`. Eso es justo lo que faltaba.
- El `Id` del informe **cruza 1-a-1 con el `legacy_id` de `movimientos_tesoreria`**: 24.623 coinciden
  por Id, importe y fecha. Es llave exacta, no cruce por importe (que nunca funciona, ver §16).

### El hallazgo: tesorería y cobros son dos capas independientes

Esto explica por qué las cajas cerraban con el desglose de cobros mal:

| Capa | Origen | Estado |
|---|---|---|
| `movimientos_tesoreria` | extractos de `Cuentas/` | correcta, verificada cuenta por cuenta |
| `cobros` | importador de ventas | consolidada y mal fechada |

**Los cobros migrados no generan movimiento de tesorería**: de 25.022 movimientos de tipo `cobro`,
todos tienen `origen_type` nulo salvo 214 (los que creó la app después del corte). Por eso se puede
reconstruir `cobros` entero sin mover un peso de ningún saldo — y así fue: los 21 saldos al 05/08 y
el conteo de movimientos de cada cuenta quedaron idénticos antes y después, en local y en el VPS.

**No confundir con una verificación de cajas.** Comparar "total cobrado por cuenta" del informe
contra `cobros` da diferencias grandes (Juan USD Personal $7,0 M, Mercado Pago $6,1 M) que **no son
plata faltante**: son la cuenta declarada en la capa de cobros, que no alimenta los saldos.

### Qué estaba mal

El importador **consolidó en un solo cobro los parciales de cada venta** y lo fechó con la emisión
de la factura:

- **1.690 ventas** con varios cobros en Contagram y uno solo acá — misma suma, distinto desglose. En
  **561** de ellas los parciales van a **medios distintos**.
- **1.046 cobros** 1-a-1 con la fecha equivocada, hasta 353 días de desvío (el grueso, 1 a 7 días).
- En cambio la **cuenta** estaba bien: 0 discrepancias en 20.940 cobros 1-a-1. El mapeo de medios de
  `self::MEDIOS` es correcto.

Sumas por venta: **22.633 de 22.851 coinciden exactas**. El saldo global nunca estuvo mal; lo que
estaba mal era la distribución por fecha, que es lo que usa el aging.

### El efecto: cambia la foto histórica, no el saldo de hoy

El total de hoy **no se mueve** ($10,62 M): a esta altura todos los cobros ya ocurrieron, con la
fecha vieja o la nueva. Lo que estaba mal era la Cta Cte **a cualquier fecha pasada**, donde un
parcial de junio figuraba con la fecha de la factura de marzo y hacía aparecer marzo como cobrado:

| Corte | Antes | Después |
|---|---:|---:|
| 31/12/2025 | $214.743,75 | $1.482.244,59 |
| 31/03/2026 | $3.898.950,52 | $12.196.957,20 |
| 31/05/2026 | $6.362.777,98 | $9.270.646,51 |
| 30/06/2026 | $6.372.023,59 | $12.143.138,41 |

Aplicado en el VPS el 13/08/2026 (`reconstruirCobros()`): 1.048 corregidos, 1.690 ventas rearmadas,
23.244 → 25.209 cobros, suma total sin cambios ($1.527.312.529,14). Backup previo en
`/root/backups/contagram_pre_cobros_20260813_1317.sql.gz`.

### Lo que falta

El informe **arranca el 02/08/2021** y la operación empieza en febrero de ese año: quedan **358
ventas** con cobro anteriores a esa fecha, el grueso de las 434 diferencias pre-corte. Pedir el
**mismo informe del 01/01/2021 al 01/08/2021**, regenerar `database/data/cobros_contagram.json` y
volver a correr el comando — es idempotente y sólo toca ventas que estén en el JSON.

Fuera de 2021 quedan 8 casos sueltos (2022, 2023, 2025) y 50 de 2026 cerca del corte.

## 18. Mercado Pago explicado al peso (13/08/2026)

Al 05/08 el panel mostraba $19.728.852,82 en el CRM contra $17.020.619,08 en Contagram:
**$2.708.233,74**. Se cerró con el export de movimientos de la cuenta en tandas
(`public/imports/MERCADO PAGO DESDE EL 1/7/`, 684 movimientos del 01/07 al 10/08), cruzando por
`Id` contra el `legacy_id` de `movimientos_tesoreria`.

| Concepto | Importe |
|---|---:|
| 3 cobros que Contagram borró después del import | $427.526,91 |
| Doble carga manual del 22/07 al 05/08, con fechas distintas en cada sistema | $2.280.706,83 |
| **Total** | **$2.708.233,74** |

### Del 01/07 al 21/07 el CRM y Contagram son idénticos

349 movimientos en ambos, **importe idéntico en los 349**, cero movimientos sólo-Contagram y **uno**
sólo-CRM. El import de Mercado Pago está bien hecho: antes de que empezara la doble carga no hay
un solo desvío estructural.

### Los 3 cobros que Contagram borró

Sus Ids **no existen en el export aunque sus vecinos sí** (25418-25422 pero no 25421; 25951-25955
pero no 25954), y no aparecen por nombre ni por importe. Existían al momento del import —de ahí su
`legacy_id`— y se eliminaron de Contagram después. Suman los $427.526,91 que figuraban como
diferencia inexplicada de Mercado Pago.

| Cliente | Venta | legacy_id | Fecha | Comprobante | Importe |
|---|---|---|---|---|---:|
| Micaela Echeverría | 20853 | 2026-FC-23661 | 08/07 | B s/nº | $79.096,49 |
| Emanuel Gutiérrez | 20363 | 2026-FC-24159 | 30/07 | B 0005-00005650 | $171.818,79 |
| Martín González | 20360 | 2026-FC-24162 | 30/07 | B 0005-00005652 | $176.611,63 |

**Decisión pendiente del usuario**: si esas ventas se anularon en Contagram, hay que anularlas
también en el CRM. Los dos del 30/07 tienen comprobante fiscal emitido, así que la baja va por nota
de crédito, no por borrado.

### La doble carga del 22/07 en adelante

De los 55 movimientos propios del CRM en el tramo, 39 son las mismas operaciones que los 40
"sólo Contagram" con otro Id, y de los 16 restantes **13 están en Contagram fechados entre el 06/08
y el 10/08** (Andrea Srur $81.000 el 06/08 contra 05/08 en el CRM; Domingo Esquivel $52.023,08
ídem; Daniela $279.589,50 ídem). Se cargaron a mano en el CRM el 06/08 entre las 12:59 y las 15:16
con fecha retroactiva al 05/08, y en Contagram el día que se cargaron.

Sólo 2 no aparecen en Contagram por ningún lado: `GLOOLIVARES` $389.934,68 y `FALVAR2009`
$47.999,00, ambos del 05/08.

### Correcciones a §16

- **El panel de Saldos de Contagram SÍ reproduce su export en esta cuenta**: el saldo calculado
  desde el export nuevo da $17.020.619,08, exacto al panel. La afirmación contraria salió de leer
  mal el export viejo (ver punto siguiente) y no vale para Mercado Pago.
- **`Cuentas/2026 MP.xlsx` tiene las fechas día/mes ambiguas**: su fila más reciente figura como
  "08/06" pero su Id (26136) corresponde al 07/08. El archivo llega a agosto, no a junio. Los
  exports nuevos por rango no tienen el problema: traen la fecha como fecha, no como texto.
  **Para cualquier comparación futura usar los exports por rango, no los de `Cuentas/`.**

## 19. Bug: editar un gasto conciliado no movía la caja — corregido el 13/08/2026

`Pagos::conciliarGasto()` salía sin hacer nada cuando el gasto ya tenía movimiento de tesorería. El
método estaba pensado sólo para el alta diferida (quitar "pendiente" genera el movimiento recién
ahí), pero `GastoController::update()` lo llama en **toda** edición. Resultado: cambiarle la cuenta
a un gasto ya conciliado lo dejaba descontando de la anterior, y cambiarle el monto o la fecha no
se reflejaba en ningún saldo.

En el VPS quedó **un solo caso** de toda la base: el gasto 9246 ("Ley 25413", $660,80 del
10/08/2026), creado el 11/08 en `Caja del Local` y reasignado el 12/08 a `Banco Credicoop`, con el
movimiento quedado en la caja vieja. Corregido: Caja del Local +$660,80, Banco Credicoop −$660,80.
No afecta los saldos al 05/08 porque es posterior al corte.

Se verificó que **pagos y cobros no tienen el mismo problema**: 0 movimientos desfasados.

### Por qué no lo agarró la suite

`tests/Feature/GastoEdicionBajaTest.php` tiene el test exacto que lo cubría
(`test_cambiar_cuenta_mueve_el_importe_entre_cuentas`), pero apunta a `App\Services\Gastos\Gastos`
y a los campos `importe`/`estado`, de una implementación que ya no existe: el archivo falla al
construirse y nunca llegó a correr.

**Esa familia entera de tests está contra la API vieja** (`GastoAltaTest` usa `cuenta_origen_id`,
`estado`, `importe`). Los 16 tests "en rojo por permisos" que veníamos arrastrando son en buena
parte esto, no un problema de permisos. Vale la pena revisarlos: mientras estén rotos, la suite no
cubre gastos.

El fix nuevo va con `GastoEdicionSincronizaTesoreriaTest`, verificado en rojo antes de aplicarlo.

## 20. Fechas de gastos con día y mes cambiados — corregido el 13/08/2026

Detectado al comparar el panel al **01/05/2026**: `Caja chica gastos` mostraba $101.566,66 contra
$810,66 de Contagram, y todo lo demás cerraba.

En los Excel de `Gastos/` la fecha viene en formato **mes/día**. Cuando el día es ≤ 12 se interpretó
al revés: el gasto 8184 ("Caja chica", $77.757) figura el 01/06/2026 y es del **06/01/2026**. Se ve
en el propio archivo — los "días" van sólo del 1 al 8 y los meses del 1 al 12, imposible en un año
de gastos. Las filas con día > 12 vienen como texto (`'1/13/2026'`) y quedaron bien.

### Los movimientos de tesorería NO estaban afectados

Se importaron de los extractos de `Cuentas/`, que traen la fecha como fecha. Por eso los bancos
cierran a cualquier corte (verificado al 01/05: los 5 idénticos, total $6.641.221,35).

La excepción es **`Caja chica gastos`**, la única cuenta sin export propio: se reconstruye desde
`gastos` (§13) y por eso heredó las fechas malas.

### Cómo se corrigió, y por qué es seguro

- **945 gastos** contra su propio movimiento del extracto, que tiene la fecha buena. Sólo cuando el
  cruce es inequívoco: un único movimiento con ese Id de Contagram, mismo importe, y fecha igual a
  la del gasto con día y mes intercambiados.
- **17 gastos de Caja chica** por la regla del formato, porque no tienen extracto contra el cual
  cruzar. La regla se validó contra los otros: **de 945 casos ambiguos, los 945 estaban invertidos
  y cero quedaron derechos** — sin contraejemplos. La lista va explícita y versionada en
  `database/data/gastos_caja_chica_refecha.json` para poder auditarla gasto por gasto.

`Caja chica gastos` al 01/05 pasa de $101.566,66 a **$809,66** (Contagram $810,66: queda el mismo
$1,00 estructural de §13, que ahora se ve en todos los cortes en vez de aparecer sólo al final).
Ninguna otra cuenta se movió, ni al 01/05 ni al 05/08.

### Lo que queda

El cruce sólo alcanza a los gastos que tienen movimiento en un extracto. Los que no —además de los
17 ya corregidos— quedan con la fecha del Excel: **no afectan tesorería**, pero sí los informes del
módulo de Gastos. Si aparece otra cuenta sin export, revisar esto antes de reconstruirla.

## 21. Las ventas "borradas" tenían nota de crédito — 13/08/2026

Cierra el caso abierto en §18: los tres cobros de Mercado Pago que Contagram había perdido
(Micaela Echeverría, Emanuel Gutiérrez, Martín González, $427.526,91) no se borraron por error. A
las tres ventas se les **emitió nota de crédito el 10/08** y se anuló el cobro — por eso el cobro
desaparecía del informe y su Id quedaba como hueco en la numeración.

Salió del informe de NC/ND de 2026 (`public/imports/nc nd 2026/`, 155 notas). El resultado del
cruce contra el CRM:

- **148 notas en ambos sistemas con el importe idéntico** — cero diferencias. El import de NC/ND
  está bien.
- **6 sólo en Contagram**, todas emitidas entre el 07 y el 11/08, o sea después del corte. Ya
  cargadas en el CRM por `notasPosterioresAlCorte()`.
- **3 sólo en el CRM**, verificadas: están vinculadas a ventas propias del CRM. Una de ellas (la
  860, JUAN 2257505195, $112.802,25) es la Id 734 de Contagram, la misma operación cargada de los
  dos lados contra su propia venta.

La de Jacinto además cierra una de las diferencias de `Caja del Local`: la venta es de $257.690,06
y **$257.690,06 − $227.357,99 = $30.332,07**, exactamente lo que Contagram tenía cobrado.

Efecto: la Cta Cte de Clientes de hoy baja $886.882,46 (a $9.735.708,77). Al 01/05 y al 05/08 no
cambia nada.

### Lección

**Un cobro que desaparece de un informe no significa que se haya borrado la venta.** Antes de
concluir que falta algo, mirar si hay una nota de crédito que lo explique — es la forma normal de
revertir una venta ya cobrada, y no deja rastro en el informe de cobros.

## 22. Las ventas de 2026 y los cobros del 06/08 en adelante — 13/08/2026

Con el informe de **ventas de 2026** (`public/imports/movmientos ventas 2026/`, 3.674 filas del
01/01 al 12/08) se pudo cruzar la última pieza que faltaba.

### Las ventas están bien

| | |
|---|---:|
| ventas de Contagram sin equivalente en el CRM | 187 (todas del 06/08 en adelante) |
| ventas del CRM sin equivalente en Contagram | **0** |
| con fecha distinta | **0** |
| con total distinto | **7** ($310.600,57) |

Las 187 son la doble carga ya conocida: el CRM tiene 175 propias en ese mismo tramo
($23.871.580,37 contra $23.617.887,55). No falta plata.

### La Cta Cte de Clientes del CRM está bien — el panel de Contagram es el que no cierra

Reconstruida **con datos exclusivamente de Contagram** (sus ventas, sus cobros, sus notas):

| Corte | Reconstruida | CRM | Panel de Contagram |
|---|---:|---:|---:|
| 01/05 | 11.880.950,99 | **10.501.640,11** | 5.426.263,21 |
| 05/08 | 9.869.890,64 | **9.440.682,65** | 7.372.300,01 |

El CRM queda a $429 mil de los propios números de Contagram al 05/08; el panel de Contagram, a
$2,5 millones. **Los $2 M y los $5 M que se venían persiguiendo no eran un descuadre del CRM.**
Quinta aparición del patrón de §16: el panel no reproduce sus propios movimientos.

### Los 7 casos reales, y su efecto en la caja

Son ventas **editadas en Contagram después del import**, y sus cobros también divergieron:

| Venta | Cliente | Contagram | CRM | Efecto |
|---|---|---:|---:|---|
| 24209 | CAROLINA | 4 cobros | sólo 900.000 | faltaban $700.000 en Caja del Local y $219.355,86 en Mercado Pago |
| 24173 | VIVIANA | 36.169,69 | 170.642,49 | sobraban $134.472,80 en Caja del Local |
| 23953 | VERONICA | sólo 200.000 | + 230.686,45 MP | el CRM tiene un cobro que Contagram no |
| 24300 | Aurelio | 99.123,29 | 99.123,98 | $0,69 |

Aplicado (`cobrosPosterioresAlCorte()`): **Caja del Local +$565.526,51** y **Mercado Pago
+$219.355,86**. Todo del 07 y el 10/08, así que **los saldos al 05/08 no se movieron**. Eso cierra
las dos diferencias más grandes de `Caja del Local` — el cobro de $700.000 de CAROLINA, que
figuraba como "sólo en Contagram", y los dos importes de VIVIANA.

### Lo que quedó fuera a propósito

- **El cobro de $230.686,45 de VERONICA** (Mercado Pago): lo tiene el CRM y no Contagram. Borrarlo
  dejaría la venta impaga, y bien puede ser que allá todavía no lo cargaron. **Decisión pendiente.**
- **El cobro de $19.290,86 de CAROLINA**: existe en el CRM aplicado a su otra venta (24103) en vez
  de a la 24209. Cambia a qué venta imputa, no la caja.
- **El total de las 7 ventas**: corregirlo obliga a rehacer sus renglones. **Decisión pendiente.**

### Otros Ingresos: ya estaban

El export de `Listado de Ingresos` (61 filas, $34.570.442,27) cruza **uno a uno** con los 61
movimientos de tipo `ingreso` del CRM: mismo Id, fecha, importe y cuenta, cero diferencias. No
aporta nada a la caja.

Lo que sí falta es el registro en el módulo: **`otros_ingresos` está vacía**, así que la pantalla de
Otros Ingresos no muestra nada aunque la plata esté bien. Si se cargan, tiene que ser por el comando
enlazando al movimiento existente — hacerlo desde la pantalla duplicaría los $34,5 M.

## §9 — Auditoría de stock del 14/08/2026: un bug real y dos falsos positivos

> ⚠️ **Parcialmente retractada — ver §11.** Lo que esta sección dice sobre las ediciones de Ventas
> migradas ("unidades fantasma", "+18 acreditadas de más") está mal: esas ediciones movieron el delta
> correcto. El bug de la Nota de Crédito y el signo invertido del 43491 siguen siendo válidos.

Disparador: al comparar el stock del VPS contra el `Listado de Productos y Servicios` de Contagram
del 14/08 20:18 Hs aparecieron **24 diferencias en Local + Full** sobre 18.220 comparaciones. Como
ambas plataformas están operando en paralelo con las mismas ventas, cobros y compras, una diferencia
de 2 unidades **es un error, no deriva** — ese fue el criterio para investigar todas.

### Trampa previa: los ids de Contagram se renumeraron

El export nuevo trae una columna **`ID VIEJOS`**. El VPS conserva los ids viejos; Contagram los
reasignó. Cruzar por `Id` compara productos distintos entre sí:

```
Contagram Id 42985  →  ID VIEJO 12700  →  MIXER      (en el VPS es 12700)
Contagram Id 12700  →  ID VIEJO 29022  →  TAPA DEP   (otro producto)
```

Comparando por `Id` daban **115 diferencias y 7.625 "faltantes"**; por `ID VIEJOS`, **24 y 53**.
Cualquier comparación futura contra exports nuevos tiene que usar `ID VIEJOS` como clave.

### BUG CONFIRMADO — la Nota de Crédito repone el stock en el depósito equivocado

Cuatro movimientos del 14/08 lo dejan a la vista:

```
#276   34400   depósito 6 (FULL)   +2   "Nota de Crédito venta"          NC 851
#277   40605   depósito 6 (FULL)   +2   "Nota de Crédito venta"          NC 851
#278   40356   depósito 6 (FULL)   +2   "Nota de Crédito venta"          NC 851
#279   34434   depósito 6 (FULL)   +1   "Nota de Crédito venta 0001-20"  NC 852
```

Las Ventas originales habían descontado de **Local**; las NC repusieron en **Full**. Ninguno de esos
cuatro productos se vende por Full.

Causa, en tres partes que se suman:

1. `NotaCreditoDebitoController` toma el depósito del formulario (`Deposito::findOrFail($datos['deposito_id'])`),
   sin deducirlo de la Venta que se está ajustando.
2. El listado va `Deposito::orderBy('nombre')` — alfabéticamente **`Full` cae antes que `Local`**.
3. El `<select>` no tenía opción vacía, así que el navegador preseleccionaba la primera.

El JS sólo preseleccionaba depósito al **editar** una nota existente; al crear devolvía `null`. La
validación `if (!$('#f-deposito').val())` nunca se disparaba porque siempre había un valor.

Es la misma familia que `20cab20` ("las ventas de integraciones no guardaban el depósito, y el
selector mentía"), que arregló Ventas y Compras y **dejó las NC sin tocar**. Acá es más grave: allá
el selector mostraba mal un dato ya guardado, acá decide dónde vuelve la mercadería.

**Arreglado el 14/08/2026**: opción vacía en el `<select>`, `comprobanteOrigen.depositoId` expuesto
en la vista, y `depositoInicial()` cayendo a ese valor. Si la Venta original no tiene depósito
—hay 188 así, ver abajo— el selector queda en blanco y la validación obliga a elegir.

### NO es bug — las 188 Ventas sin `deposito_id`

```
manual         105   todas con legacy_id  → importadas de Contagram
mercadolibre    83   06/08 al 13/08       → anteriores al fix 20cab20
```

Las 105 manuales **tienen `legacy_id`**: son de la reconstrucción de la base, no cargadas a mano.
Los `created_at` lo confirman — espaciados a intervalos exactos (18 min, 10 min 23 s), marcas
generadas por el importador, y 89 con `creado_por_id = 0`. La migración no mueve inventario a
propósito, así que sin movimiento no hay depósito que guardar.

Las 83 de ML son el fix de ayer aplicado hacia adelante: **ninguna venta de ML posterior al 13/08
quedó sin depósito**.

### NO es bug — "las ventas manuales no descuentan stock"

Falso positivo mío. La consulta usaba `origen_type = 'App\Models\Venta'` con el escapado de más
que impone pasar por SSH + `mysql -e "…"`, y **nunca matcheaba**. Con `origen_type LIKE '%Venta'`:
las 18 ventas manuales del 06/08 en adelante con depósito **sí movieron stock, todas**.

Lección operativa: contra este VPS, filtrar `origen_type` con `LIKE '%Venta'` en vez de escribir la
clase completa.

### Signo invertido en el ajuste del 13/08 — producto 43491

`Herraje REP0IMP001 Global`. Único movimiento propio además de una venta de 1 unidad:

```
13/08 22:43   ajuste  Local  +22   "Ajuste por conteo real — Sotck 17_08…"
14/08 13:54   salida  Local   −1   venta 24481
```

Despejando: tenía **−11** antes del ajuste. La hoja traía `11` donde el valor real era `−11`, el
comando calculó `11 − (−11) = +22` y mandó el doble en la dirección contraria. La diferencia contra
Contagram era exactamente 22 = 2 × 11, firma inconfundible del signo dado vuelta.

Revisados los otros 88 ajustes de esa corrida buscando `ajuste = −2 × valor_previo`: **es el único
caso**. Corregido a −12 el 14/08.

**Pendiente**: `AjustarStockDesdeHoja` no tiene defensa contra esto. Un conteo que llegue con el
signo perdido se aplica al doble y al revés. Convendría avisar cuando `|ajuste| ≈ 2 × |previo|` y
los signos son opuestos.

### Resultado

```
antes    18.196 coinciden  /  24 difieren
después  18.217 coinciden  /   3 difieren
```

Las 3 restantes son deliberadas: `27198` vendió 1 unidad después del export, y `12700` / `43005` en
Full los gobierna el inventario de Mercado Libre, no Contagram.

Correcciones aplicadas con `UPDATE` directo, sin generar movimientos (`movimientos_stock` quedó en
357 antes y después). Backups: `/root/pre_correccion_final_20260814_2200.sql`.

### Full: por qué nunca descontó, hasta hoy

`logistic_type` estaba **NULL en las 270 publicaciones vinculadas**, así que `esFull()` devolvía
siempre `false` y `resolverDeposito()` mandaba **todas** las órdenes al depósito general. El spec 065
estaba bien escrito; nunca tuvo el dato.

No era un bug de código sino de secuencia: los vínculos se crearon el 06/08 con el código viejo, la
última corrida de `SincronizadorTiposPublicacion` fue el 13/08 20:46, y el código de spec 065 llegó
al VPS el **14/08 03:19** — siete horas después. Y el comando tiene `INTERVALO_HORAS = 24`, así que
no iba a reintentar hasta las 20:46 del 14/08.

Resuelto con `mercadolibre:sincronizar-tipos-publicacion --forzar`: 270 actualizadas, 0 con error.
Full real: **3 publicaciones** (`MLA823877533` → 43005, `MLA762900978` → 12700, `MLA1424068727` → 41363).

Confirmación de que quedó bien: la venta **24506** (21:03 UTC, orden `2000017938763700`) se creó con
`deposito_id = 6` y descontó de Full, mientras que la **24509** del mismo período, de Colecta, fue a
Local.

Efecto colateral esperado: a las 18:32 `SincronizadorStockFull` empezó a reflejar el inventario real
de ML sobre el depósito Full (`ajuste` con descripción "Reflejo de stock Full de Mercado Libre"),
pisando los valores cargados a mano. **Para Full la fuente de verdad es ML, no Contagram.**

## §10 — Bitácora de los chequeos de stock (14 al 19/08/2026)

> ⚠️ **Parcialmente retractada — ver §11.** El apartado "Ediciones de ventas migradas: sigue
> ocurriendo" describe como problema algo que es el comportamiento correcto.

Rutina que se viene aplicando cada mañana, y lo que fue apareciendo. Sirve como procedimiento para
repetirlo y como registro de qué se descartó ya, para no volver a investigarlo.

### El procedimiento

1. **Movimientos nuevos desde el último corte** (`movimientos_stock.id > N`), agrupados por origen,
   tipo y depósito. Cualquier `ajuste` sin descripción o sin `origen_type` merece explicación.
2. **Auditoría venta por venta**: cada ítem contra los movimientos que generó, comparando por **neto**
   —una edición produce entrada + salida que se cancelan— y verificando producto, depósito y cantidad.
   También el caso inverso: movimientos sobre productos que no están en los ítems.
3. **Estado de las integraciones**: última corrida de órdenes y de stock, publicaciones pendientes y
   bloqueadas, órdenes sin venta.
4. **Cruce contra la API de Mercado Libre** cuando hay dudas: stock del CRM contra el que ML publica
   de verdad. Es el único chequeo que detecta una publicación congelada.

Cortes registrados: `358` (14/08), `388` (15/08), `392` (16/08), `394` (16/08), `401` (17/08).

### Trampas del procedimiento (pisadas y resueltas)

- **`origen_type` con escapado de más.** Filtrar `origen_type = 'App\Models\Venta'` a través de
  SSH + `mysql -e "…"` **nunca matchea**. Usar `origen_type LIKE '%Venta'`. Me hizo reportar en falso
  que "ninguna venta manual descuenta stock".
- **Sumar sólo las salidas infla el ranking de más vendidos.** Las ediciones generan pares
  entrada+salida; hay que netear. Un producto daba 41 unidades vendidas cuando eran 7.
- **El depósito a comparar depende de la publicación.** Para una publicación Full el stock relevante
  es el del depósito Full, no el Local. Comparar siempre contra Local marcaba como "desfasadas" a las
  tres Full sin que lo estuvieran (y tapaba el desfasaje real, que era otro).
- **Los `created_at` están en UTC.** Un export de Contagram de las 20:18 hora argentina corresponde al
  corte 23:18 UTC. Ver §7.x de `documentacion_principal_crm.md`.
- **Los ids de Contagram se renumeraron.** Cruzar por `ID VIEJOS`, nunca por `Id` (ver §9).

### Ediciones de ventas migradas: sigue ocurriendo

Confirmado que las ediciones hechas desde sesiones de trabajo (`usuario_id` NULL) sobre Ventas con
`legacy_id` **generan movimientos de stock**, pese a que la instrucción explícita era no tocar
inventario. `VentaObserver::deleting()` tiene el guard de `legacy_id`; `StockDeVenta::reaplicarPorEdicion()`
**no**.

Casos posteriores a los ocho del 14/08 documentados en §9:

```
venta 24371   migrada   18/08 12:25   neto 0    sin daño
venta 24429   migrada   19/08 13:38   neto 0    sin daño
venta 24100   migrada   18/08 18:39   neto ±1   dejó dos boquillas cruzadas
```

La 24100 cambió `24759` (boquilla ahorradora) por `24107` (boquilla aireadora) — nombres casi
idénticos. Contagram no tiene ese cambio, así que **una unidad de cada una está atribuida a un
producto distinto en cada sistema**. Pendiente: definir cuál se vendió realmente.

### Cruce final contra Contagram (export del 19/08 01:42)

```
18.212 coinciden  /  8 difieren
  4  ventas posteriores al export (el VPS está más al día)
  2  de Full, gobernadas por el inventario de ML
  2  las boquillas cruzadas de arriba
```

### Lo que quedó descartado como problema

- Las **188 Ventas sin `deposito_id`** no son un bug: 105 son migradas y 83 anteriores al fix `20cab20`.
- Las **ventas manuales sí descuentan stock** (era el falso positivo del escapado).
- Los **62 productos en negativo son 42**: veinte son mano de obra cargada como producto
  (Colocación, Visita, Traslado), que no lleva inventario.


## §11 — Corrección: editar una Venta migrada SÍ debe mover stock (20/08/2026)

Las §9 y §10 dieron por bug que las ediciones de Ventas con `legacy_id` generaran movimientos de
stock, y hablaron de "unidades fantasma". **Estaba mal, y se retracta acá.**

**El criterio correcto.** La migración no generó movimientos porque el conteo real ya reflejaba todo
el histórico — esa decisión sigue siendo válida y aplica a cualquier importación masiva de
comprobantes viejos. Pero una **edición hecha hoy** es una operación de hoy: si la venta tenía 1
unidad y se edita a 3, dos unidades más están saliendo ahora y el stock tiene que bajar 2.

El sistema revierte los ítems anteriores (entrada) y aplica los nuevos (salida), así que el neto es
exactamente **el delta de la edición**, y cero si los ítems no cambiaron. Es el comportamiento correcto.

**El error de método.** Se auditó comparando *"lo que la venta movió"* contra *"los ítems que tiene
hoy"*, esperando que coincidieran. Esa vara sólo vale para una Venta normal, que descontó su total al
crearse. Una migrada nunca lo descontó, así que la comparación da negativo siempre.

**Casos que se reportaron mal:**

- *Venta 22416 (14/08)*: se reportó "+18 unidades acreditadas de más". El producto 27198 pasó de 9 a 5
  unidades en la venta — cuatro menos saliendo — y el sistema acreditó +4. Correcto.
- *Las 8 ediciones del 14/08 (§9)*: se reportó un neto de −10 "sin explicación". Ese neto es la suma de
  los deltas de esas ediciones, no un error.
- *Las 6 del 18 al 20/08 (§10)*: revisadas una por una con la vara correcta, las 6 están bien. Tres no
  cambiaron ítems (neto 0), dos sumaron un producto (sólo su salida) y una cambió un producto por otro
  (entrada del viejo + salida del nuevo).

**Consecuencia sobre las diferencias con Contagram.** Las boquillas cruzadas (`24107` / `24759`) y el
Tubo plástico (`43055`) no son errores del CRM: son correcciones aplicadas acá que Contagram no tiene.
En esos tres productos **el CRM está más al día**.

**Lo que queda como regla**: al auditar una Venta migrada editada, la vara es el **delta**. Y al
importar histórico, la importación no debe generar movimientos.
