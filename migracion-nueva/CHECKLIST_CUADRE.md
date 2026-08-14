# Checklist de cuadre por año — antes de sumar el año siguiente

Se corre después de importar cada año a `contagram_migracion`, **antes** de pasar al año
siguiente (ver plan en `APRENDIZAJES.md`). Si algo no cuadra, se para ahí — no se sigue
acumulando años sobre una base que ya tiene una diferencia sin explicar.

## 1. Totales de facturación/cobro del año

Fuente: columnas de control del propio Excel `Ventas c- cobro/{año} Ventas c_ cobro.xlsx` (trae su
propio total, no hay que calcularlo aparte — mismo método que ya validó la bitácora al 99,94-100%
para 2021-2026).

Comparar contra la base importada:
- **Facturado** = suma de `ventas.total` del año (por `fecha_emision`).
- **Cobrado** = suma de `cobros.monto` vinculados a ventas del año.
- **A cobrar** = Facturado − Cobrado (o el campo de saldo si existe).

Tolerancia: **0,5%** — mismo criterio usado en la bitácora para las diferencias de redondeo ya
conocidas y documentadas (no investigar de nuevo esas, están explicadas en
`docs/importacion_datos_reales_2026_bitacora.md`).

## 2. Cantidad de comprobantes y de líneas

- Cantidad de ventas del año en `contagram_migracion` == cantidad de filas del `c/ cobro` del año
  (restando las ventas confirmadas como borradas en Contagram, ver
  `docs/importacion_casos_a_revisar.md §1`).
- Cantidad de líneas en `venta_items` del año == cantidad de filas del Excel "por ítem" de ese año
  (restando NC/ND si se cuentan aparte).
- Cualquier diferencia se lista explícitamente (no se asume "está bien" sin decir cuántas y cuáles).

## 3. Caja por fecha

Esto es lo que falló la vez pasada — el punto más importante de este checklist.

- Reconstruir el saldo de caja día por día del año (movimientos de `movimientos_tesoreria`:
  cobros, pagos, gastos, transferencias entre cuentas) y compararlo contra el saldo que trae
  `Cuentas/*.xlsx` en su columna `Saldo` para las mismas fechas.
- Aplicar la regla de fechas invertidas (BUG 2 de la bitácora) **antes** de comparar — si no, el
  desfase de día/mes va a aparecer como descuadre falso.
- Reportar la primera fecha donde el saldo diverge, si la hay (no sólo el saldo final del año — un
  descuadre puntual a mitad de año puede compensarse por casualidad al cierre y pasar desapercibido).

## 4. Cta Cte de clientes

- Saldo pendiente por cliente al cierre del año (31/12) en `contagram_migracion` vs. lo que se
  pueda reconstruir del Excel (ventas no cobradas del año y anteriores, netas de NC/ND).
- Ojo con lo ya documentado en `docs/importacion_casos_a_revisar.md §11` (NC/ND sin comprobante
  asociado inflando la Cta Cte) y `§12` (aging que ignoraba la fecha de corte) — son bugs ya
  corregidos en el código de la app, pero hay que confirmar que la base nueva no los vuelve a pisar
  si se reimportan NC/ND de la misma forma que la vez pasada.

## Qué hacer si algo no cuadra

1. No seguir con el año siguiente.
2. Documentar la diferencia acá mismo (agregar una sección "Año YYYY — resultado" abajo) con el
   monto/cantidad exacta y en qué categoría (1-4) apareció.
3. Investigar contra Contagram directamente (no contra el Excel — el Excel puede estar desactualizado,
   ya pasó una vez con el `c/cobro` de 2026 vencido, ver bitácora 10/08).
4. Recién con la causa identificada, corregir el import (script o datos de origen) y volver a correr
   el checklist completo del año antes de avanzar.

---

## Resultados por año (ir completando)

### 2021
**Huecos conocidos ANTES de importar (13/08/2026):**
- ✅ **RESUELTO — no había hueco real, era el arranque del negocio en Contagram.** La venta más
  antigua de todo `Ventas/Ventas 2021.xlsx` es del **18/06/2021** (Id 8) — coincide exacto con el
  inicio de la cobertura de los 12 archivos de cobros que el usuario consiguió
  (`migracion-nueva/excel-origen/2021/`, tandas del 18/06 al 31/08/2021). Antes de esa fecha no hay
  operación real en el sistema, por eso Contagram no tenía nada para exportar. **Cobertura de
  cobros detallados para 2021: completa**, sin fallback necesario.
- **A verificar, no bloqueante**: 4 huecos de un solo día entre tandas consecutivas (30/06, 25/07,
  15/08, 22/08) — probablemente días sin cobros, no un corte de exportación. Confirmar si el
  checklist de cuadre de ese mes no cierra.
- **Ojo al importar**: estas 12 tandas se solapan en fechas (02/08 al 31/08) con el archivo grande
  ya existente (`cobros/...1126 Hs.xlsx`). Deduplicar por `Id` al cargar, no sumar ambos a ciegas.
- ✅ **RESUELTO (13/08/2026) — NC/ND de 2021 con vínculo directo.** El usuario consiguió
  `migracion-nueva/excel-origen/2021/Informe Cuentas Corrientes Movimientos de Clientes
  13-08-2026 1903 Hs.xlsx`: 17 notas (16 Nota de Crédito + 1 Nota de Débito, 06/07/2021 a
  13/12/2021), con columna **`Id Venta` explícita** — mejor que el método de cruce por
  `Total NC`/`Total ND` que se usó en el VPS (donde esa columna no existía y hubo que
  reconstruir el vínculo indirectamente). Para 2021 se usa este archivo directo, no el cruce.

**✅ IMPORTADO Y CUADRADO (13/08/2026)** — `php artisan migracion:cuadre --anio=2021`

| | Excel | Base | Diferencia | Explicación |
|---|---:|---:|---:|---|
| Ventas | 2.123 | 2.122 | 1 | La venta **2140**, borrada en Contagram (confirmada por el usuario, está en `MigrarVentasContagram::BORRADAS`) |
| Facturado | 24.868.575,75 | 24.828.541,93 | 40.033,82 | La misma venta 2140 ($40.034,3988) menos **$0,58** de redondeo acumulado: el Excel guarda 4 decimales y la base 2, sobre 2.122 filas |
| Notas de crédito | 995.806,47 | 782.793,40 | 213.013,07 | 6 ventas de 2021 cuya NC se emitió en un año posterior — todavía no importado |
| Notas de débito | 8.057,81 | 2.318,24 | 5.739,57 | Ídem, 4 ventas (incluye la 1803, que tiene NC importada + ND pendiente del mismo importe) |

- **Cobros: 2.271, $22.827.478,28**, con **fecha y cuenta reales** (no la fecha de emisión ni el
  primer medio, que es lo que rompió el import anterior). Los 11 medios de cobro del año resolvieron
  a cuentas existentes: **0 cuentas creadas**, 0 cobros sin cuenta.
- **Notas: 17 (16 NC + 1 ND), las 17 vinculadas a su venta. 0 sueltas.** Comparar con el VPS, donde
  645 NC sin `venta_id` inflaban la Cta Cte de Clientes en $57 M.
- **2 cobros no imputados**, ambos esperables: el de la venta 2140 (borrada) y uno de la venta
  **2187**, que es una venta de 2022 (emitida 05/01/2022) con una **seña cobrada en 2021**. Este
  último entra al re-correr el informe después de importar 2022 — el comando es idempotente.
- Ventas de 2021 esperando nota de año posterior: **707, 1102, 1103, 1116, 1226, 1401, 1623, 1741,
  1803**. Se cierran solas al avanzar con los años siguientes.

### 2021 — compras, gastos y tesorería (13/08/2026)

| | Resultado |
|---|---|
| **Compras** | 321 (ids 4→345, **= Id de Contagram**), 1.148 ítems, 4 NC. $14.471.934,06 |
| **Pagos** | 318, con **fecha y cuenta reales** del informe de Movimientos de Proveedores. $13.250.119,27 |
| **Gastos** | 527 — **$8.669.775,46, coincide al centavo** con la cifra de control de la bitácora |
| **Tesorería** | 48.191 movimientos (los 6 años; sus archivos no se pueden partir por año porque el control es el saldo acumulado) |

- **✅ Punto 3 del checklist — CAJA POR FECHA: 15 de 15 cuentas cuadran al 31/12/2021** contra la
  columna `Saldo` del Excel (las otras 6 no tenían movimientos a esa fecha). Es justo lo que falló
  en el import anterior. El saldo final de los 6 años también cuadra: **20 de 20**.
- **Punto 4 — Cta Cte Clientes: $1.219.072,60 contra −$6,74 de Contagram.** No es un descuadre: la
  diferencia está explicada **al centavo** por datos que todavía no se importaron.

  | Concepto | Importe |
  |---|---:|
  | Cobros de ventas 2021 hechos en 2022 o después | +1.053.356,13 |
  | NC de ventas 2021 emitidas en 2022 o después | +213.013,07 |
  | Venta 2140 (borrada en Contagram, no se importa) | −40.034,40 |
  | ND de ventas 2021 emitidas en 2022 o después | −5.739,57 |
  | **Total** | **1.220.595,23** ← la diferencia exacta contra −6,74 |

  Converge a ~0 al importar los años siguientes. **Ojo con el método**: Contagram muestra −$6,74 *al
  31/12/2021* aunque esos cobros son de 2022+, o sea que su panel filtra los comprobantes por fecha
  pero **no los cobros** — el mismo defecto de aging que en el CRM se corrigió (bitácora §12). Vale
  la regla ya conocida: para conciliar, los exports; nunca el panel.
- **Cajas y bancos al 31/12/2021: idénticos a Contagram** (Total $581.551,02, Cajas −$86.896,59,
  Bancos $668.447,61), cuenta por cuenta.
- **1 compra faltante en el origen**: la Id 85 (24/04/2021, PERSONAL, $683,08) no está en el Excel de
  Compras, así que su pago tampoco se pudo imputar. **Es el mismo caso que ya documentó la bitácora
  §15 en el VPS** — no es un problema nuevo, y no afecta saldos porque compra y pago se cancelan.

### ⚠️ BUG ENCONTRADO Y CORREGIDO: deduplicar por `Id` se comía cobros reales

Detectado al comparar el panel de Tesorería contra Contagram al 31/12/2021 (el usuario lo pidió).

**El `Id` de Contagram no identifica un movimiento.** El informe de Movimientos de Clientes trae dos
filas con el cobro **Id 1239**, y son dos cobros distintos: **$22.348,00** a la venta 1226 y
**$1.515,89** a la venta **1227**. `migracion:informe-clientes` deduplicaba por `operación:Id` —para
descartar la repetición que producen los rangos de exportación solapados— y con eso **descartaba el
segundo cobro en silencio**. Es el mismo fenómeno ya medido en tesorería, donde el Id repite en
22.823 de 48.222 filas.

**Corregido** en los dos comandos de informe: la clave de deduplicación (y la marca de idempotencia
en `nota`) ahora llevan **Id + comprobante + importe + fecha**. Se borraron y reimportaron los cobros
y pagos de 2021. Resultado: **2.272 cobros** (antes 2.271) por **$22.828.994,17**.

**Control que lo hace verificable**: cobros y pagos tienen que cerrar contra `movimientos_tesoreria`,
que viene de los extractos y es una fuente independiente. Ahora cierran exacto:

```
COBROS  tesorería 22.871.451,47 − tabla 22.828.994,17 = 42.457,30
        = venta 2140 (borrada) 20.000,00 + venta 2187 (es de 2022) 22.457,30   ✔
PAGOS   tesorería 13.250.802,35 − tabla 13.250.119,27 =    683,08
        = compra 85, la que falta en el Excel de origen                        ✔
```

**Lección para los años que siguen**: nunca deduplicar un movimiento de Contagram por su `Id` solo, y
contrastar siempre cobros/pagos contra tesorería, que es la fuente independiente.

### ⚠️ Dos cosas más que aparecieron al revisar, y cómo se resolvieron

1. **Se creó una cuenta de tesorería duplicada (`Mastercard`)** durante el import de gastos, porque
   `CuentasDeTesoreria::MAPEO` apuntaba a los nombres viejos (`Mastercard`) y **el servicio creaba
   la cuenta en silencio si no la encontraba** — el catálogo nuevo usa los canónicos
   (`Mastercard a Cobrar`). Es exactamente el defecto que dejó 12 cuentas duplicadas en el VPS.
   **Resuelto**: se corrigió el mapeo a los nombres canónicos, se reasignó el único gasto afectado y
   se borró la cuenta duplicada (21 cuentas de nuevo). Además **el servicio ya no crea cuentas**:
   ahora corta con una excepción explicativa salvo que se lo habilite con `permitirCrear()`.
2. **91 movimientos de stock** (`Ajuste por conteo real – Sotck 17_08 cierre del dia.xlsx`) que **no
   vienen de la migración**: no están en la base vieja ni en el dump del catálogo, se crearon en la
   base nueva el 13/08 a las 22:43 desde la app. **Pendiente de confirmar con el usuario** si fue una
   carga suya intencional.

**Orden de comandos que dejó 2021 cerrado** (reproducible):

```bash
php artisan migracion:ventas   --anio=2021 --preservar-id
php artisan migracion:informe-clientes --anio=2021 \
    --dir=migracion-nueva/excel-origen/2021 --dir=migracion-nueva/excel-origen/cobros
php artisan migracion:compras  --anio=2021 --preservar-id --sin-pagos
php artisan migracion:informe-proveedores --anio=2021
php artisan migracion:gastos   --anio=2021
php artisan migracion:tesoreria          # completo, no por año
php artisan migracion:cuadre   --anio=2021
```

### 2022
**✅ IMPORTADO Y CUADRADO (13/08/2026)**

| | Excel | Base | Diferencia |
|---|---:|---:|---:|
| **Ventas** | 4.029 | **4.029** | **✔ 0** |
| **Facturado** | 64.803.249,34 | 64.803.249,35 | **−0,01** (redondeo) |

- **Ventas**: 4.029 (ids = Id de Contagram), 6.422 ítems, 38 NC. **0 clientes y 0 productos creados**.
- **Cobros**: 4.390 por $64.802.214,56, con fecha y cuenta reales. 0 sin cuenta.
- **Compras**: 507 + 2.278 ítems, 21 NC + 1 ND. **Pagos: 699, sin un solo problema.**
- **Gastos**: 1.726 — **$23.474.840,65, coincide al centavo** con la cifra de control.
- **Notas: 38 de venta + 22 de compra, 0 sueltas** ✔
- **Caja al 31/12/2022: 17 de 17 cuentas cuadran** contra la columna `Saldo` del Excel.
- **4 cobros no imputados**: la venta 2140 (borrada) y tres señas de 2022 aplicadas a ventas de
  **2023** (4792, 6452, 10808) — entran al reimportar el informe después de importar 2023.
- Ventas de 2022 esperando nota de un año posterior: 18 (NC $84.751,25, ND $92.462,74).

**El archivo que hizo falta pedir**: `migracion-nueva/excel-origen/2022/` — informe de Movimientos de
Clientes filtrado por Nota de Crédito/Débito (38 NC, con `Id Venta`). Sin él las 38 notas quedaban
sueltas. **De paso cerró 4 de las 9 pendientes de 2021** (ventas 1103, 1623, 1741, 1401).

### La convergencia de la Cuenta Corriente funciona como estaba previsto

Es la comprobación de que el método es correcto: al importar 2022, el saldo pendiente de las ventas
de **2021** se desplomó, porque entraron sus cobros y notas posteriores.

| Cta Cte Clientes de ventas 2021 | Importe |
|---|---:|
| Con sólo 2021 importado | 1.219.072,60 |
| **Con 2022 importado** | **24.741,93** |
| Objetivo (Contagram) | −6,74 |

Los $24.748,67 que faltan están explicados **al centavo**: NC pendientes $28.578,26 − ND pendientes
$5.739,57 + cobros pendientes $1.909,98. Las 5 ventas de 2021 que siguen esperando nota son la
707, 1102, 1116, 1226 y 1803.

Cta Cte de las ventas de 2022, con 2022 importado: $493.776,16 — converge igual al seguir.

### ⚠️ Bug corregido en el propio comando de cuadre

`migracion:cuadre` sumaba **las notas de compra junto con las de venta** (comparten la tabla
`notas_credito_debito`), y eso hacía aparecer una diferencia de $720.856,64 en 2022 que no existía.
Corregido: la comparación filtra por `compra_id IS NULL` y el reporte ahora dice cuántas notas son de
venta y cuántas de compra.

### ⚠️ Corregido en el verificador de caja

Mercado Pago viene **partido en un archivo por año** (`2021 MP`, `2022 MP`…). El script comparaba
cuenta contra *cada* archivo, así que al cortar en 2022 lo enfrentaba al saldo final del archivo de
2021 y marcaba una diferencia falsa de $89.831,57. Corregido: ahora toma la fila más reciente dentro
del corte **entre todos** los archivos de esa cuenta.

### 2023
**✅ IMPORTADO Y CUADRADO (13/08/2026)**

| | Excel | Base | Diferencia |
|---|---:|---:|---:|
| **Ventas** | 4.845 | **4.845** | **✔ 0** |
| **Facturado** | 161.480.676,19 | 161.480.676,32 | **−0,13** (redondeo) |

- **Ventas**: 4.845 + 6.921 ítems, 92 NC + 25 ND (coinciden exacto con el informe). 0 clientes y 0
  productos creados.
- **Cobros**: 5.173 por $156.946.292,92. 0 sin cuenta.
- **Compras**: 390 + 2.053 ítems, 15 NC + 4 ND. **Pagos: 562, sin un solo problema.**
- **Gastos**: 1.866 — **$75.798.822,81, coincide al centavo** con la cifra de control.
- **Notas: 117 de venta + 19 de compra, 0 sueltas** ✔
- **Caja al 31/12/2023: 18 de 18 cuentas cuadran.**
- 3 cobros no imputados: señas de 2023 aplicadas a ventas de 2024 (11060, 11425, 11858).
- Al reimportar el informe de 2022 entraron **3 cobros más** ($158.256,36): eran señas de 2022 para
  ventas de 2023, que recién ahora tienen a qué imputarse. Confirma que reimportar el año anterior
  después de cada import es parte del procedimiento.

### 🎯 La proyección se cumplió al centavo

Antes de importar 2023 se predijo que el saldo de Cuenta Corriente de las ventas de **2021** pasaría
de $24.741,93 a **−$1.411,49** (por la NC 70 de la venta 1102 y la ND 15 de la venta 1803, ambas
emitidas en 2023). El resultado real:

| Corte | Antes de 2023 | Después de 2023 | Predicho |
|---|---:|---:|---:|
| 31/12/2021 | 24.741,93 | **−1.411,49** | **−1.411,49** ✔ |

Es la validación más fuerte que tiene la migración hasta ahora: no sólo cuadra, sino que **se puede
predecir de antemano** qué documento va a mover qué importe.

Lo que falta para llegar a los −$6,74 de Contagram sigue identificado y es exacto:
`−1.411,49 + 3.314,73 (ND de las ventas 707, 1116 y 1226) − 1.909,98 (cobros pendientes) = −6,74`.

### Estado acumulado al cerrar 2023

| | |
|---|---:|
| Ventas | 10.996 |
| Cobros | 11.839 |
| Compras | 1.218 |
| Pagos | 1.579 |
| Gastos | 4.119 |
| Notas | 217, **0 sueltas** |
| Cuentas de tesorería | 21 (las de Contagram) |

Integridad: **0 cobros huérfanos, 0 pagos huérfanos, 0 notas sueltas**.

### 2024
**✅ IMPORTADO Y CUADRADO (13/08/2026)**

| | Excel | Base | Diferencia |
|---|---:|---:|---:|
| **Ventas** | 4.393 | **4.393** | **✔ 0** |
| **Facturado** | 356.739.542,06 | 356.739.541,73 | **0,33** (redondeo) |

- **Ventas**: 4.393 + 6.708 ítems, 165 NC + 13 ND. 0 clientes y 0 productos creados.
- **Cobros**: 4.629 por $339.300.071,88. 0 sin cuenta.
- **Compras**: 376 + 2.334 ítems, 16 NC. **Pagos: 628, sin un solo problema.**
- **Gastos**: 2.062 — **$156.036.390,25, coincide al centavo** con la cifra de control.
- **Notas: 178 de venta + 16 de compra, 0 sueltas** ✔
- **Caja al 31/12/2024: 20 de 20 cuentas cuadran.**
- Al reimportar 2023 entraron 3 cobros más ($206.176,48): señas de 2023 para ventas de 2024.

### ⚠️ HALLAZGO: las retenciones no vienen en ningún export

Cerrado el circuito de 2021, quedó un residuo de **$1.909,98** en dos ventas del mismo cliente
(Lucio De Gennaro, ventas 1319 y 1656). No es un error de import: **son retenciones**.

| Venta | Falta | % del total |
|---|---:|---:|
| 1319 | 1.461,60 | **2,479 %** |
| 1656 | 448,38 | **2,479 %** |

El porcentaje idéntico lo delata. Contagram las carga aparte (el botón "Agregar Retención" de la
ficha de la venta), y **el informe de Movimientos de Clientes filtrado por `Operación = Cobro` no las
trae**. Tampoco aparecen en `Cuentas/`: la cuenta `Retenciones` sólo tiene 5 movimientos, todos de
2024 en adelante, y la tabla `retenciones` quedó vacía.

**Efecto**: la caja **no** se ve afectada (esa plata nunca entró — por eso los saldos cuadran 20/20);
lo que queda mal es que esas facturas figuran parcialmente impagas cuando en Contagram están
saldadas.

**Magnitud medida** (Excel `Cobrado` vs lo importado, por año de la venta):

| Año | Diferencia | Composición |
|---|---:|---|
| 2021 | 41.944,38 | $40.034,40 de la venta 2140 (borrada) + **$1.909,98 de retenciones** |
| 2022 | 7.722,93 | retenciones + cobros de años posteriores |
| 2023 | 520.471,19 | mayormente cobros de 2025/2026 todavía no importados |
| 2024 | 1.019.014,32 | ídem |

Para 2021, que ya está cerrado, el residuo real es **$1.909,98 sobre $23,88 M cobrados = 0,008 %**.
En los años recientes la diferencia todavía está dominada por cobros de años sin importar, así que la
magnitud real de las retenciones se va a poder medir recién al terminar 2026.

**✅ RESUELTO (13/08/2026) — llegó el export de retenciones.**
`migracion-nueva/excel-origen/retenciones hisotircas/`: 13 filas con `Id Venta`, de 2022-07-07 a
2025-07-01, con las operaciones `Retención de IVA`, `de Ganancias`, `de Ingresos Brutos Capital
Federal / Buenos Aires`, `de Seguridad Social` y `de Municipal`.

`migracion:informe-clientes` ahora las importa: se cargan como cobro contra la cuenta `Retenciones`
—que es como las modela Contagram— porque su `Medio de Cobro` viene vacío. **No tocan la caja**: esa
cuenta es de tipo `a_cobrar`, y los cobros migrados no generan movimiento de tesorería.

Se importaron **12 por $528.194,12** (la 13ª es de una venta de 2025, entra con ese año). Efecto:

| Corte | Antes de las retenciones | Después |
|---|---:|---:|
| 31/12/2022 | −663,94 | **−8.386,87** |
| 31/12/2023 | 519.802,32 | **−8.391,80** |
| 31/12/2024 | 1.981.607,06 | 1.453.412,94 |

**✅ CERRADO — llegaron las retenciones 1 y 2.** Eran exactamente las predichas:

| Id | Fecha | Operación | Venta | Importe |
|---|---|---|---|---:|
| 1 | 04/11/2021 | Retención de Ingresos Brutos Capital Federal | **1319** | 1.461,60 |
| 2 | 04/12/2021 | Retención de Ingresos Brutos Catamarca | **1656** | 448,38 |

## 🎯 2021 CIERRA IDÉNTICO A CONTAGRAM

Con esas dos retenciones, el saldo de Cuenta Corriente de Clientes al 31/12/2021 quedó en
**−$6,74 — el mismo número que muestra Contagram, al centavo.**

| Control al 31/12/2021 | CRM | Contagram | |
|---|---:|---:|---|
| Cta Cte Clientes | **−6,74** | −6,74 | ✔ **exacto** |
| Cta Cte Proveedores | 1.199.345,88 | 1.194.695,87 | $4.650,01 (desfasaje interno de Contagram, §15) |
| Caja y bancos | 15 de 15 cuentas | | ✔ **exacto** |
| Ventas | 2.122 | 2.123 | la 2140, borrada en Contagram |

La caja no se movió al importar las retenciones, como debía ser: van contra la cuenta `Retenciones`
(tipo `a_cobrar`) y los cobros migrados no generan movimiento de tesorería.

**Evolución del saldo de 2021 a medida que entraron los años** — cada paso fue predicho antes de
correrlo:

| | Importado | Saldo al 31/12/2021 |
|---|---|---:|
| Sólo 2021 | | 1.219.072,60 |
| +2022 | cobros y NC posteriores | 24.741,93 |
| +2023 | NC 70 (venta 1102) y ND 15 (venta 1803) | −1.411,49 |
| +2024 | ND de las ventas 707, 1116 y 1226 | 1.903,24 |
| +retenciones | Ids 1 y 2 | **−6,74** ✔ |

### Cuenta Corriente de Clientes — convergencia medida

| Corte | Sólo 2021 | +2022 | +2023 | +2024 | Contagram |
|---|---:|---:|---:|---:|---:|
| **31/12/2021** | 1.219.072,60 | 24.741,93 | **−1.411,49** | **1.903,24** | **−6,74** |

Los $1.909,98 que separan el 1.903,24 final de los −6,74 son exactamente las retenciones de arriba.
Todo lo demás cerró, y cada paso fue predicho antes de correrlo.

### 2025
**✅ IMPORTADO Y CUADRADO (13/08/2026)**

| | Excel | Base | Diferencia |
|---|---:|---:|---:|
| **Ventas** | 4.645 | **4.645** | **✔ 0** |
| **Facturado** | 496.829.115,61 | 496.829.116,21 | **−0,60** (redondeo) |

- **Ventas**: 4.645 + 7.280 ítems, 189 NC + 5 ND. 0 clientes y 0 productos creados, 0 conflictos de
  número de comprobante.
- **Cobros**: 4.871 por $476.426.819,64, más la retención 15. 0 sin cuenta.
- **Compras**: 472 + 2.202 ítems, 40 NC + 7 ND. **Pagos: 668** (1 sin compra, la Id 85 ya conocida).
- **Gastos**: 1.890 por $228.787.048,14. *(2025 y 2026 son de formato plano y no traen bloque de
  control propio, a diferencia de 2021-2024 que cerraron al centavo.)*
- **Notas: 194 de venta + 47 de compra, 0 sueltas** ✔
- **Caja al 31/12/2025: 20 de 20 cuentas cuadran.**
- Al reimportar 2024 entraron **9 cobros más** ($1.687.017,58): señas de 2024 para ventas de 2025.

### Cuenta Corriente — estado con 5 años importados

| Corte | Clientes | Proveedores |
|---|---:|---:|
| 31/12/2021 | **−6,74** ✔ igual a Contagram | 1.199.345,88 |
| 31/12/2022 | −10.296,85 | 731.899,42 |
| 31/12/2023 | −10.301,78 | 5.758.483,11 |
| 31/12/2024 | −258.069,18 | 25.541.499,22 |
| 31/12/2025 | 2.250.891,11 | 9.255.359,83 |

Los años cerrados quedan en cero o levemente negativo (saldo a favor), y el último importado siempre
queda alto porque le faltan los cobros del año siguiente. 2024 pasó de $1.451.502,96 a **−$258.069,18**
al entrar 2025, siguiendo el mismo patrón que ya se verificó tres veces.

### 2026 — importado hasta el 13/08/2026 inclusive
**✅ IMPORTADO Y CUADRADO (13/08/2026, adelantado: se decidió hacerlo esa misma noche)**

| | Excel | Base | Diferencia |
|---|---:|---:|---:|
| **Ventas** | 3.702 | **3.701** | 1 — la **24267**, borrada en Contagram |
| **Facturado** | 489.967.150,64 | 489.755.570,37 | 211.580,27 = esa misma venta + $0,79 de redondeo |

- **Ventas**: 3.701 + 5.605 ítems, 146 NC + 10 ND. **82 clientes nuevos** (cargados en Contagram esos
  días; los dos sistemas se cargaban por separado).
- **Cobros**: 3.888 · **Compras**: 318 + 1.768 ítems · **Pagos**: 456 · **Gastos**: 1.371
- **Notas: 156 de venta + 41 de compra, 0 sueltas** ✔
- **Caja al 13/08: 9 de 9 cuentas cuadran** contra el `Saldo` de los extractos ✅

**El tramo 06→13/08 llegó en archivos aparte** (los export por año cortan el 05/08): "Informe de
Ventas Detallado" (173 ventas + 8 NC con ítems, **mismas 44 columnas** que el por-ítem, encabezado en
la fila 10), "Informe de Compras", "Informe de Gastos", los extractos por cuenta y los informes de
Movimientos de Clientes. Los comandos aceptan ahora `--extra-item`, `--extra-resumen`, `--extra`,
`--dir` y `--corte` para incorporarlos sin duplicar lógica.

### ⚠️ Tres defectos encontrados y corregidos al importar 2026

1. **Gastos con día y mes invertidos en el formato plano.** El código afirmaba que gastos no
   arrastraba ese defecto; **sí lo arrastra en 2025-2026**. Se estaban **descartando 165 gastos** por
   caer entre septiembre y diciembre, con el archivo cortado el 05/08. Prueba en un sentido: leído
   invertido, 2026 no deja ninguno después de agosto. Prueba en el otro: 2021 es formato **agrupado**
   y ahí invertir inventaría 20 gastos en enero, cuando el negocio arrancó el 18/06/2021. Corregido
   por formato y reimportados 2025 y 2026.
2. **Los extractos nuevos NO traen el defecto de fechas.** Aplicarles la corrección de `Cuentas/` los
   rompía (05/08 → 08/05). Se resolvió con el flag explícito `--fechas-directas` en vez de
   autodetectarlo: en un archivo de uno o dos movimientos las dos lecturas son indistinguibles y
   equivocarse corrompe fechas en silencio. Queda un control de orden que avisa si se elige mal.
3. **Mercado Pago difería $480.000,71.** Eran **4 cobros que Contagram borró** porque a esas ventas
   se les emitió nota de crédito — los cuatro clientes coinciden con NC importadas el mismo día
   (Micaela Echeverría, Martín González, Emanuel Gutiérrez, VALERIA TATE). Eliminados; la cuenta
   quedó en $19.849.387,21, exacto.

---

## ✅ VERIFICACIÓN FINAL CONTRA EL PANEL DE CONTAGRAM

### Al 31/12/2025 (con los 6 años importados)

| | CRM | Contagram | |
|---|---:|---:|---|
| **Total Cajas** | 252.946,37 | 252.946,37 | ✅ **exacto** |
| **Total Bancos** | 8.309.620,07 | 8.309.620,07 | ✅ **exacto** |
| **Cta Cte Clientes** | −706.739,81 | −706.739,83 | **$0,02** |
| Cta Cte Proveedores | 7.162.849,65 | 7.158.199,63 | $4.650,02 — el desfasaje interno de Contagram (§15) |

### Al 31/12/2021

| | CRM | Contagram | |
|---|---:|---:|---|
| Cta Cte Clientes | **−6,74** | −6,74 | ✅ **exacto** |
| Caja y bancos | 15 de 15 cuentas | | ✅ **exacto** |

### Dos defectos que sólo aparecieron al comparar 2025

1. **Faltaba un pago de $2.092.510,18.** Es de 2025 pero su **compra es de 2026**: al importar 2025
   esa compra todavía no existía, así que el pago quedó afuera. Es el mismo fenómeno de las señas del
   lado de cobros. **Regla que queda**: al terminar todos los años hay que **reimportar los informes
   de clientes y de proveedores completos**, para recoger los movimientos que cruzan de un año a otro.
   Después de hacerlo, Proveedores pasó de $9.255.359,83 a $7.162.849,65.
2. **`Caja chica gastos` estaba en $0.** Es la única cuenta sin archivo en `Cuentas/` — el mismo hueco
   que en el VPS, donde hubo que reconstruirla desde capturas y quedó con $1,00 sin explicar. Acá el
   usuario consiguió su export real (**176 movimientos de 2023-10-19 a 2026-06-16, con `Saldo
   Inicial`**), así que la cuenta cierra **al centavo**: −$1.608,34 al 31/12/2025 y $33.137,66 al
   13/08/2026.

### Al 13/08/2026 (estado al cierre de la sesión)

| | CRM | Contagram | |
|---|---:|---:|---|
| **Total Cajas** | 878.321,53 | 878.321,53 | ✅ **exacto** |
| **Total Bancos** | 22.988.690,48 | 22.988.690,48 | ✅ **exacto** |
| Cta Cte Clientes | 9.991.004,53 | 9.767.952,15 | 223.052,38 |
| Cta Cte Proveedores | 32.993.157,14 | 26.798.404,53 | 6.194.752,61 |

**Estado tras incorporar los informes del 12→14/08** (`excel-origen/2026/ultimo/`), que trajeron
6 pagos y confirmaron que no falta ninguna venta ni compra:

| | CRM | Contagram | Diferencia |
|---|---:|---:|---|
| Cta Cte Proveedores | 28.421.361,49 | 26.798.404,53 | **1.622.956,96 — explicado** |
| Cta Cte Clientes | 9.991.004,53 | 9.767.952,15 | 223.052,38 — **abierto** |

**✅ Proveedores: RESUELTO — era un cheque diferido.**

La diferencia de $1.622.956,96 era **un solo documento**: el pago de **$1.622.957,12 a Peisa**
(compra 2376, factura A 0057-00061702 del 24/06/2026), con cuenta `Cheque Propio` y fecha **24/08**.

La compra se pagó en tres cuotas —24/06 por Mercado Pago, 24/07 y 24/08 con cheque—, o sea que los
cheques se entregaron el día de la compra. Contagram considera saldada la deuda con el proveedor
cuando se entrega el cheque, pero deja el movimiento de caja en la fecha de vencimiento; por eso su
Cta Cte lo cuenta y su cuenta `Cheque Propio` no.

**Solución aplicada**: se re-fechó el pago al **24/06/2026** (la entrega), dejando constancia en su
nota. No toca la caja: los pagos migrados no generan movimiento de tesorería, así que `Cheque Propio`
sigue en $30.000,31 al 14/08, igual que Contagram. Verificado que **es el único pago con fecha futura
en toda la base**.

**Se descartó el otro camino, con evidencia**: quitarle el filtro de fecha a los pagos (poner
`ESTILO_CONTAGRAM['proveedor'] = true`) hace coincidir hoy pero **rompe todas las fechas pasadas** —
al 31/12/2021 da −$1,00 contra los $1.194.695,87 de Contagram. Sin filtro sólo coincide "hoy",
cuando ya ocurrió todo.

**Resultado en las tres fechas de control:**

| Corte | CRM | Contagram | Diferencia |
|---|---:|---:|---:|
| 31/12/2021 | 1.199.345,88 | 1.194.695,87 | 4.650,01 |
| 31/12/2025 | 7.162.849,65 | 7.158.199,63 | 4.650,02 |
| 13/08/2026 | 26.798.404,37 | 26.798.404,53 | **−0,16** |

Los $4.650 son **idénticos en 2021 y 2025**: es el desfasaje constante entre el informe de Contagram
y su propio panel que la bitácora §15 ya había medido ($4.649,96). No está en el CRM.

### Clientes: la causa son ventas editadas en Contagram después del export

Los Excel son una foto. Una venta cargada en julio puede editarse en agosto —cambiarle el producto,
el precio o el descuento— y el archivo con el que se importó sigue mostrando el valor viejo. El
`c/cobro` de 2026 se exportó el **07/08**, así que ninguna edición posterior llegó.

**Caso testigo, verificado abriendo la venta en Contagram**: la **24019** (24/07) figuraba como
"Botiquín tríptico 63 / $191.033,86" y hoy es "Botiquín 40 Recto / $115.409,99". Y su contracara
apareció sola: el 14/08 se creó la venta **24476** con el botiquín tríptico original, mismo precio y
mismo importe, bajo el cliente sentinela `NO USAR MAS`. O sea que alguien reorganizó la operación.

**Detección y corrección**: `migracion:refrescar-ventas` compara el total de cada venta contra un
"Informe de Ventas Detallado" fresco y actualiza cabecera e ítems donde difiere, **sin tocar los
cobros** (lo cobrado es un hecho aparte del contenido del comprobante). Con el informe del 01/07 al
06/08 aparecieron **7 ventas por $149.398,60**:

| Venta | Fecha | Antes | Ahora |
|---|---|---:|---:|
| 24173 | 30/07 | 190.642,49 | 56.169,69 |
| 24019 | 24/07 | 191.033,86 | 115.409,99 |
| 24209 | 01/08 | 1.838.646,72 | 1.902.809,97 |
| 24300 | 05/08 | 68.115,28 | 99.123,29 |
| 23957 | 22/07 | 37.983,03 | 18.991,52 |
| 23512 | 01/07 | 74.187,30 | 63.059,21 |
| 23626 | 06/07 | 8.707,18 | 4.353,59 |

Tres de ellas (24209, 24300, 24173) ya estaban documentadas en la bitácora §22 como divergentes.

**⚠️ Bug propio, encontrado antes de escribir**: la primera versión del comando detectaba 10 ventas,
y tres eran falsas. Sumaba los valores **únicos** de `Total Venta` en vez de todos los renglones, así
que una venta con dos ítems del mismo importe perdía uno. La venta **24330** lo destapó: tiene
58.314,23 + 58.314,23 + 38.720,00 = **$155.348,46**, y el cálculo daba $97.034,23 — le habría roto el
total a una venta **con CAE emitido por ese importe** (B 0009-00000003). Se detectó comparando dos
exports distintos de la misma venta, que resultaron idénticos: el error era del código, no del dato.
**El mismo patrón está en `ComprobantesContagram::armar()`**; ahí no afecta a las facturas porque su
total lo manda el `c/cobro`, pero conviene tenerlo presente.

### Estado final de Clientes

| Corte | CRM | Contagram | Diferencia |
|---|---:|---:|---:|
| 13/08 | 9.894.079,73 | 9.767.952,15 | **126.127,58** |
| 14/08 | 10.085.113,59 | 9.958.986,01 | **126.127,58** |

**La diferencia es idéntica en las dos fechas**, que es la comprobación de que no hay nada nuevo
descuadrado: sólo queda el hueco conocido. (Antes de importar la venta 24476 del 14/08, las dos
fechas daban distinto y eso confundía — con los mismos datos de los dos lados, la diferencia tiene
que ser la misma.)

**Lo que falta para cerrarlo**: el mismo Informe de Ventas Detallado del **01/01 al 30/06/2026**, para
encontrar las ediciones más viejas. Son $126.127,58 sobre $1.570 M facturados: **0,008 %**.

### La regla que se confirmó cuatro veces: reimportar los informes al final

Los movimientos que cruzan de un año a otro (una seña de 2025 para una venta de 2026, un pago de 2025
para una compra de 2026) se rechazan cuando se importa su año, porque el comprobante todavía no
existe. **Al terminar todos los años hay que reimportar los informes completos.** Pasó con:

| Caso | Efecto al reimportar |
|---|---|
| Cobros de 2021 → ventas de 2022 | 1 cobro ($22.457,30) |
| Cobros de 2022 → ventas de 2023 | 3 cobros ($158.256,36) |
| Cobros de 2023/2024 → ventas siguientes | 12 cobros ($1,89 M) |
| Pago de 2025 → compra de 2026 | 1 pago ($2.092.510,18) |
| Cobros de 2025 → ventas de 2026 | Cta Cte Clientes: de $989.380,77 de diferencia a $223.052,38 |

### El `c/cobro` de 2026 está desactualizado (se exportó el 07/08)

Las 12 ventas que difieren contra ese archivo son todas casos donde **la base está más al día**:
incluyen notas de crédito importadas el 13/08 (la 735 de VALERIA TATE, la 729 de Paloma, la de
Jacinto). No hay nada que corregir ahí — el archivo es la foto vieja.

---

## 🔜 PENDIENTES — lo que queda después de cerrar los 6 años

### 1. Comprobantes fiscales con CAE (no resuelto, decisión pendiente)

Hay **14 comprobantes autorizados por ARCA** en el punto de venta 9, emitidos entre el 04/08 y el
13/08, que **sólo existen en el CRM viejo**: se facturaron desde ahí y muchas de esas ventas **nunca
se cargaron en Contagram**, así que no vienen en ningún export.

| Tipo | Numeración | Cantidad |
|---|---|---|
| A | 0009-00000001 → 0009-00000008 | 8 |
| B | 0009-00000001 → 0009-00000005 | 5 + 1 nota de crédito |

**Matching contra las ventas ya importadas** (por cliente + monto + fecha):

- **8 resuelven solas**: B-2→venta 24325, B-3→24330, A-2→24384, A-3→24386, A-4→24391, A-5→24398,
  B-5→24437.
- **5 sin match**, y la explicación es la del usuario: son ventas creadas en el CRM que nunca
  llegaron a Contagram. Hay que **traerlas de la base vieja**, no matchearlas.
  - B 0009-00000001 — TANIA 1157822317, $307.569,76 (04/08) + su nota de crédito
  - A 0009-00000001 — ROBERTO 1162714317, $227.086,52 (07/08)
  - A 0009-00000006 — Carlos 1144702571, $30.774,49 (12/08)
  - **A 0009-00000007 y 0009-00000008 — STEPCZUKCARLOSJACOBO, $41.388,32 (13/08): dos comprobantes
    con CAE para la misma venta y el mismo importe.** Parece doble emisión; hay que decidir cuál
    queda y si la otra necesita nota de crédito.
- **1 a confirmar**: B 0009-00000004 — Valentin 1157505257, $18.733,29 (10/08). Hay una venta con el
  mismo monto y fecha pero **otro nombre de cliente**.

**Ojo con los ids al traerlas**: las ventas del CRM viejo tienen ids (1, 68, 71, 87, 122, 136, 211,
212, 219…) que en la base nueva **ya pertenecen a ventas de 2021**. Hay que darles ids nuevos por
encima de 24.301.

**Y lo crítico**: el próximo comprobante que emita el CRM tiene que continuar en **A 0009-00000009** y
**B 0009-00000006**. ARCA valida contra su propio registro, no contra nuestra base.

### 2. Revincular las órdenes de Mercado Libre

137 órdenes copiadas con `venta_id` en NULL (respaldo del mapeo viejo en
`scripts-import/ml_ordenes_venta_id_previo_20260813.tsv`). Se hace **después** de tener todas las
ventas, cruzando por `ml_order_id`. Sin apuro: en local no corre la creación automática.

### ✅ Origen de las ventas (canal) — resuelto el 14/08/2026

El importador dejaba todas las ventas con `origen = 'manual'`, así que el filtro **"Creada Desde"** de
la pantalla mostraba "Venta" en las 23.736 y no servía para nada.

**El dato sí estaba**: Contagram lo trae en la columna `Categoría`, que el import ya había cargado en
`ventas.categoria_id`. Se derivó el origen de ahí:

| Origen | Ventas | Regla | Período |
|---|---:|---|---|
| `mercadolibre` | **9.140** | `Categoría = Mercadolibre` | 2021→2026, pico en 2023 (4.107) |
| `tiendanube` | **92** | `Categoría = Web` (confirmado por el usuario) | sobre todo 2024 (49) y 2023 (17) |
| `manual` | 14.504 | el resto (Local, Online, Obras, Redes) | |

Salvedad menor: 6 ventas "Web" son de 2021-2022, cuando probablemente ese rótulo era la web propia y
no Tiendanube. Son $104.698,41 en total; se dejaron como están porque es lo que dice el dato.

**El vínculo con presupuestos NO se puede reconstruir.** El export `Listado de Presupuestos` (68
filas, sólo del 01/08 al 11/08) **no trae ninguna columna que referencie la venta** — se verificaron
las 39. Sabe que 40 se convirtieron (`Estado = Venta`) pero no en cuál. Decisión: no se importan los
presupuestos y `Creada Desde` nunca va a decir "Presupuesto N" en las migradas. No se intentó cruzar
por cliente y monto, siguiendo la regla de no inventar un dato.

**Calidad de datos detectada de paso** (no afecta saldos): 3 ventas tienen el **nombre de un cliente
en el campo Categoría** ("Cecilia scattini 1154230873", "Claudia 1157513292", "Luis 1554089848") y 51
quedaron sin categoría, todas de octubre de 2023.

### 3. Clientes

**Criterio acordado y ya implementado**: el importador matchea por nombre (colapsando espacios)
contra los existentes y, si no encuentra, crea el cliente **con el nombre tal cual viene en la
venta** — que en las de Mercado Libre es el apodo de ML, porque así lo registra Contagram. Los 82
clientes nuevos de 2026 se crearon con ese criterio. Si más adelante llega un export de clientes,
se les puede completar CUIT, condición de IVA y contacto.

---

## Órdenes de Mercado Libre — revinculadas (14/08/2026)

Estado final: **141 órdenes, 134 vinculadas a su venta, 0 apuntando a una venta inexistente.**

### De dónde salieron

Las 137 que había venían de la copia de la base local; el 14/08 se trajeron del VPS las **4 nuevas
(416–419)** con `mysqldump --where='id >= 416'` — sólo lectura sobre el VPS, sin tocar nada ahí.
Dump en `scripts-import/ml_ordenes_nuevas_vps_20260814.sql`.

Entraron **sin `venta_id`**: en el VPS las órdenes 416 y 417 apuntaban a las ventas 24067 y 24068,
creadas por el CRM anoche, cuyos id acá pertenecen a ventas de Contagram completamente distintas.
Se les limpió `venta_id`, `convertida_en` y `convertida_por`. **Esas dos ventas del VPS no existen en
esta base** — se suman a las que sólo viven en el CRM.

### Cómo se resolvió el vínculo

`ml_ordenes` guarda sólo el apodo (`comprador_apodo`) y Contagram guarda al cliente con el nombre
real: `STRICKERKARIN20230409152855` contra `Karin Stricker`. Sin ese puente el único criterio es
importe+fecha, y **el 30/07 hay 21 ventas del mismo importe el mismo día** — un match así se equivoca
y se comprobó que se equivocaba.

El puente es el **"Listado de Órdenes de Mercado Libre"** (`excel-origen/ordenes/`, 7 archivos que
cubren 16/06 → 13/08), que trae `Orden ID` + `Nombre` + `Apellido`. Cubre las 141.

Comando: `migracion:vincular-ordenes-ml`.

```
php artisan migracion:vincular-ordenes-ml --dir=migracion-nueva/excel-origen/ordenes   --extra=414:24469 --extra=102:24399 --extra=124:24420 --facturada-en=101:24399
```

Dos cosas del importe que hubo que contemplar:

1. **Contagram redondea.** La orden de $389.934,68 queda facturada en $389.785,63 — $149,05 de
   diferencia. Por eso la tolerancia es relativa (0,5%) y no de centavos; con centavos daban
   "sin venta" 21 órdenes que sí la tenían.
2. **Contagram agrupa.** $14.204,19 + $27.755,20 = $41.959,39 exacto, dos órdenes en una factura.
   Pero **`ml_ordenes.venta_id` es UNIQUE**, así que sólo la primera del grupo queda vinculada y la
   otra se lista aparte con la venta que le corresponde.

### Las 7 sin vincular

| Orden | Fecha | Importe | Motivo |
|---|---|---:|---|
| o62 | 06/08 | 171.818,78 | **cancelada en ML** — correcto que no tenga venta |
| o80 | 08/08 | 286.881,58 | **cancelada en ML** — idem |
| o94 | 09/08 | 262.748,21 | facturada en la venta 24372, que ya tomó la o93 (UNIQUE) |
| o124 | 12/08 | 27.755,20 | facturada en la venta 24420, que ya tomó la o123 (UNIQUE) |
| o101 | 10/08 | 143.440,79 | facturada en la venta 24399 junto con la o102; en Contagram figura **Pendiente** |
| o418 | 13/08 | 389.934,68 | **todavía no facturada** (confirmado por el usuario) |
| o419 | 14/08 | 171.818,78 | **todavía no facturada** (confirmado por el usuario) |

**El caso Canales quedó resuelto.** Canceló una compra de $286.881,58 (o80) y la rehizo partida en
dos de $143.440,79 el 10/08. En Contagram el listado de órdenes muestra la o102 como **Venta** y la
o101 como **Pendiente**: agrupó las dos en una sola factura de 2 unidades y sólo marcó una. El
usuario verificó a qué venta apunta la o102 — **la 24399** — y se vinculó con `--extra=102:24399`.

Queda una observación que **no es de migración**: las dos ventas de $286.881,59 del 10/08 (`v24361`
y `v24399`) están **las dos cobradas**, o sea $573.763,18 a un cliente que compró $286.881,58, sin
nota de crédito. La `v24361` no corresponde a ninguna orden viva — probablemente salió de la o80
antes de cancelarse. Es un tema a mirar del lado del negocio.

El caso **o414 (`Yanina Andrea Zelada`)** se vinculó a mano con `--extra=414:24469`: importe y fecha
coinciden y no hay otra candidata, pero el cliente de esa venta quedó cargado como `2317456491` —un
DNI en el campo nombre— así que el match por nombre no lo agarraba solo.


### Estado de conversión de las que quedaron sin venta

Las que no tienen `venta_id` no pueden quedar diciendo `convertida` —lo heredaban del VPS— porque es
mentira y confunde. El comando las pasa a **`requiere_atencion`** con el motivo escrito en
`motivo_detalle`. Ese estado es el correcto también por seguridad: `EstadoConversion::habilitaCrearVenta()`
sólo devuelve `true` para `lista`, así que el cron **no las va a convertir de nuevo** y no hay riesgo
de que duplique ventas.

Quedaron así: o62 y o80 en `cancelada`; o94, o101 y o124 en `requiere_atencion`; o418 y o419 en
`lista`, que es lo que corresponde — están pagadas y esperando factura.


### Cuál de las dos órdenes agrupadas lleva la venta: lo decide Contagram, no la fecha

El paso 2 elegía la primera orden del grupo por fecha. **Está mal**: el usuario verificó en Contagram
que la orden marcada como "Venta" para la 24420 es la **o124 de $27.755,20**, no la o123 de
$14.204,19 que había elegido el comando. Mismo caso en Canales: la marcada es la o102.

No hay regla derivable del dato (ni la fecha, ni el importe, ni el orden de llegada lo predicen), así
que se resuelve con `--extra` mirando el listado de órdenes de Contagram, donde el estado de cada
orden dice **Venta** o **Pendiente**. La orden "Pendiente" del grupo se marca con `--facturada-en`.

**Falta verificar así el grupo o93/o94 (ANA FERNANDEZ TUÑON, venta 24372)**: hoy está vinculada la
o93 porque es la primera por fecha, que es exactamente el criterio que resultó equivocado en los
otros dos grupos. Si en Contagram la marcada es la o94, se corrige con
`--extra=94:24372 --facturada-en=93:24372`.


## Órdenes de Tiendanube (14/08/2026)

Son sólo **4** y quedó **1 vinculada**. Ninguna estaba atada a una venta.

| Orden | Fecha | Importe | Comprador | Resultado |
|---|---|---:|---|---|
| 3 (`2021422587`) | 17/07 | 180.861,88 | Alejandro viva | **venta 23842** — coincide fecha, importe exacto, cliente y `origen='tiendanube'` |
| 4 (`1924064728`) | 17/07 | 202.936,69 | Alejandro viva | **cancelada** — no debe tener venta |
| 2 (`2032024787`) | 30/07 | 135.591,00 | Leandro gorosito | **sin venta en Contagram** — no existe el cliente ni una venta por ese importe |
| 1 (`2032274136`) | 30/07 | 262.252,00 | Pompei Sanitarios | **sin venta** — el comprador es la propia tienda (`pompei2sanitarios@gmail.com`, `storefront: form`), parece un pedido de prueba cargado desde el panel |

Las 4 traían `motivo = cuenta_tesoreria_no_configurada` ("No hay una cuenta de Tesorería configurada
para Tiendanube"), heredado del VPS. **Eso hay que configurarlo antes de poner esta base en
producción** o el cron no va a poder convertir ninguna orden de Tiendanube.

Nota aparte: hay otras **2 ventas con `origen='tiendanube'` en 2026** (23301 del 23/06 y 23406 del
28/06) que no tienen orden en `tn_ordenes` — la sincronización con Tiendanube arrancó recién en
agosto, así que de esas no hay registro de orden y no se puede vincular nada.


## Depósito Tiendanube eliminado (14/08/2026)

El depósito `Depósito Tiendanube` (id 7) se borró a pedido del usuario. **No estaba vacío**, así que
antes se movió todo lo que colgaba de él al depósito **Local** (id 5):

- **7 ventas** (todas `origen='tiendanube'`: 21750, 21751, 21752, 22310, 23301, 23406, 23842)
  pasaron a `deposito_id = 5`.
- **28 filas de `stocks`** se consolidaron sumando la cantidad a la fila del Local. Las 28 ya
  existían en Local, así que fue suma y borrado, no reasignación — el `UNIQUE (producto_id,
  variante_id, deposito_id)` no admitía moverlas tal cual.

**Efecto sobre el stock del Local: −16 unidades netas**, repartidas en 17 productos. Es real, no un
error: ese depósito sólo registraba salidas por venta y nunca recibió un ingreso ni una
transferencia, así que su saldo era negativo. Los dos casos más grandes ya venían negativos en Local
por su cuenta ("Materiales extra Mauricio" −81 → −82, "Colocación múltiple monocomando" −22 → −23).

Se hizo en una transacción y quedó verificado en cero: ninguna de las 9 columnas `deposito_id` de la
base referencia al 7.

Respaldo previo por si hay que revertir:
`scripts-import/stocks_deposito_tiendanube_previo_20260814.sql` (filas de los depósitos 5 y 7 antes
de la suma) y `scripts-import/ventas_deposito_tiendanube_previo_20260814.tsv`.


## Comparación completa contra el VPS (14/08/2026)

Se compararon las **78 tablas** (son las mismas de los dos lados) con `COUNT(*)` exacto. Sólo lectura
sobre el VPS.

**Riesgo central detectado**: casi todo lo que falta referencia ventas y compras **por id del CRM**, y
esos id acá son otros documentos. Ejemplo verificado:

```
compra 4     VPS: 10/08/2026  $754.935,31     local: 24/06/2021  $10.944,00
compra 2385  VPS: 11/08/2026  $2.144.564,86   local: 15/07/2026  $4.872.530,85
```

Traer cualquiera de esas tablas tal cual pegaría los datos a documentos equivocados. **Cada una hay
que remapearla a mano.**

### Tablas con datos en el VPS y vacías acá

| Tabla | Filas | Decisión |
|---|---:|---|
| `certificados_fiscales` | 1 | **TRAÍDA** (ver abajo) |
| `comprobantes_fiscales` | 26 (14 con CAE) | pendiente — se resuelve junto con las ventas del CRM |
| `compra_conceptos` | 16 | pendiente — percepciones IIBB/IVA sobre 6 compras de agosto, hay que remapear |
| `presupuestos` + `presupuesto_items` | 65 + 161 | pendiente — creados en el CRM 06/08–13/08, distintos de los de Contagram |
| `remitos` + `remito_items` | 6 + 7 | pendiente — apuntan a las ventas 2 y 17942 del CRM |
| `nota_credito_debito_items` | 172 | pendiente — items de las NC creadas en el CRM |
| `transportistas` | 1 | **descartada** — el único registro se llama "Pruieba" |
| `ml_operaciones_log` | 5.009 | descartada — log técnico |
| `arca_logs_auditoria` | 29 | descartada — log técnico |
| `tn_rest_operaciones_log`, `cache` | — | descartadas — log técnico |

### Certificado fiscal ARCA — traído

Es lo único que se trajo, por decisión del usuario. Sin esto no se puede facturar.

- Fila de `certificados_fiscales`: CUIT 20273351249, ambiente **producción**, vence **02/08/2028**.
- `storage/app/private/arca/cert_1785785032.crt` (el que apunta `ruta_certificado`).
- `storage/app/arca/pompei_crm.key` (clave privada).
- `puntos_venta` ya coincidía exactamente: PV 9 "POMPEI CRM", tipo WS, por defecto.

Respaldo del dump: `scripts-import/certificado_fiscal_vps_20260814.sql`.

**Pendiente al retomar**: los 14 CAE reales (A hasta `0009-00000008`, B hasta `0009-00000006` más 1 NC).
La numeración tiene que continuar en **A 0009-00000009** y **B 0009-00000007**. De los 14, 8 matchean
ventas importadas y 5 son ventas que sólo existen en el CRM.
