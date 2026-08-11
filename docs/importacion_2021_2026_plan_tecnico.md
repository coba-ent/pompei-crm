# Plan técnico — Importación completa 2021-2026 (ventas, cobros, compras, gastos)

Documento de diseño del importador. Complementa `docs/importacion_datos_reales_2026_bitacora.md`
(que registra el **análisis** de los archivos y las decisiones de negocio); acá va **cómo se
implementa**. Escrito el 10/08/2026, antes de programar.

> **Regla que manda sobre todo:** no se pierde ninguna venta. El criterio de aceptación son las
> cifras de control del final de este documento. Si no dan, el import no está terminado.

---

## 1. Alcance y corte

| | |
|---|---|
| Período | desde el inicio hasta el **05/08/2026 inclusive** |
| Origen | `public/imports/` (ver §2) |
| Destino | base del VPS (`contagram` en 46.202.146.102) |
| Fuera de alcance | todo lo cargado en el CRM del 06/08 en adelante — ya existe, no se toca |

**Por qué ese corte:** hasta el 05/08 el negocio operaba en Contagram; del 06/08 en adelante usa el
CRM. Verificado por `created_at`: 1 sola venta creada antes del 06/08 (la de prueba de ARCA).

---

## 2. Archivos de origen y sus particularidades

| Carpeta | Contenido | Encabezado |
|---|---|---|
| `Ventas/Ventas {2021..2026}.xlsx` | **por ítem**, 44 col. Fuente de: items, cantidades, precios, y **los importes** | fila 1; **2023 lo tiene al final** |
| `Ventas c- cobro/{2021..2026} Ventas c_ cobro.xlsx` | resumen, 50 col. Fuente de: `Cobrado`, `Estado`, `Medio de Cobro`, `Depósito`, `Usuario`, `Total NC/ND` | fila 1 |
| `Compras/{2021..2026} Compras.xlsx` | **por ítem**, 35 col. | fila 1, salvo **2021, 2025 y 2026 → fila 7** |
| `Compras c- pago/…` | resumen con `Pagado`/`Estado` | **incompleto: faltan 2021-2024** |
| `Gastos/{2021..2026} Gastos.xlsx` | 2021-2024 **agrupado**; 2025-2026 plano | ver §3.4 |
| `Cuentas/*.xlsx` (23) | movimientos de tesorería: cobros, pagos, gastos, transferencias | fila 1 |

**Clave de unión ventas:** el `Id` del Excel une el por-ítem con el c/ cobro. Verificado: 100% de
las ventas del c/ cobro tienen sus ítems (salvo la 24267, borrada en Contagram).

**El `c/ cobro` manda para el TOTAL de cabecera** (corregido el 10/08/2026 — antes decía lo
contrario, apoyado en el caso 24300; ver §3.9). El por-ítem manda para el **detalle de ítems**.

---

## 3. Gotchas de lectura (todos verificados — encapsular en un lector común)

### 3.1 Fechas invertidas (`Cuentas/` y `Compras/`)
Columnas de fecha mezcladas: celdas tipo fecha y tipo texto. Excel convirtió **sólo las ambiguas
(día ≤ 12) interpretándolas al revés**. Regla:

- celda **texto** → parsear `M/D/Y`;
- celda **fecha** con `day <= 12` → **intercambiar mes y día**;
- celda **fecha** con `day > 12` → tal cual.

*Verificación*: los archivos vienen ordenados por fecha descendente; con la regla aplicada los **25
archivos de Cuentas quedan con 0 violaciones de orden** (sin ella, 10 a 110 cada uno). En
`2026 Compras.xlsx`, sin la regla aparecen **46 compras con fecha futura**; con la regla el rango
cierra en 2026-08-05.

### 3.2 Serial numérico de Excel (`Ventas/` por ítem)
La columna `Emisión` viene como número (`46239.0`), no como fecha. Convertir con epoch
**1899-12-30** (o `Date::excelToDateTimeObject()`).

### 3.3 Encabezados fuera de la fila 1
- `Ventas 2023.xlsx`: **sin encabezado al principio**, datos desde la fila 1 y el header real
  pegado al final. Usar el header canónico de otro año y descartar la fila literal.
- `Compras` 2021, 2025 y 2026: bloque de resumen arriba, encabezado en la **fila 7**.
- Detectar siempre buscando la fila cuya primera celda sea `Id`.

### 3.4 Gastos 2021-2024: informe agrupado
Estructura: resumen (filas 1-2) → por cada categoría, una fila suelta con su nombre, otra con la
subcategoría, el encabezado `Id, Fecha, Subcategoría, Descripción, Medio de pago, Total`, las filas
de gasto, y un `Total <categoría>:`. **No traen columna `Categoría`** — se toma de la última fila
separadora. **No se puede deducir por el nombre de la subcategoría**: 6 nombres existen bajo más de
un padre (`Otros`, `ABL`, `Alquiler`, `Aysa`, `Edenor`, `Personal Flow` — estos 5 últimos bajo
*Juan Personal* y *Oficina Pompei* a la vez), lo que afectaría a 607 gastos.

### 3.5 Columna `Tipo` duplicada (`Ventas/` por ítem)
Aparece dos veces (tipo de comprobante y rubro del producto). Desduplicar como hace pandas
(`Tipo`, `Tipo.1`) antes de mapear; con `array_combine` se pierde silenciosamente la primera.

### 3.6 Nombres de cliente con espacios dobles
La base tiene 377 clientes con **doble espacio** entre nombre y teléfono
(`'Alberto Diaz  1164854451'`) mientras los exports traen espacio simple. **Colapsar espacios en
ambos lados** en todo matching por nombre, si no se crean duplicados.

### 3.7 Teléfonos guardados como fórmula — **nunca evaluar fórmulas**
Los nombres de cliente traen el teléfono pegado y muchos arrancan con `+` (`+54 9 11 6973-4…`), así
que Excel los guardó como **fórmula**, no como texto. Si se lee evaluando fórmulas, PhpSpreadsheet
tarda minutos y termina abortando:

```
Calculation\Exception: Hoja 1!E4896 -> internal error
```

Reproducido en `Ventas 2025.xlsx` (E4896) y `2025 Ventas c_ cobro.xlsx` (G1670). Leer siempre con
`toArray($null, calculateFormulas: false, formatData: false)`: sin evaluar, la celda devuelve el
texto literal, que es exactamente el teléfono que se quiere. Con eso los 6 años se leen en ~75s.

### 3.8 Columnas fantasma al final (`Ventas 2026.xlsx`)
Trae 6 columnas vacías después de `Afecta Stock`. Recortadas, el encabezado de los **6 años es
idéntico columna por columna** (44), lo que habilita usar el de cualquier año como canónico
para el 2023 (§3.3).

### 3.9 Qué fuente manda para los importes — **corrección de una decisión anterior**
El plan sostenía que *"el por-ítem manda para los importes"*, apoyado en la venta 24300. **Medido
sobre los 6 años, es al revés.** Arbitrando con la suma real de los ítems
(`Subtotal con Descuento` + IVA) sobre los comprobantes donde las dos fuentes discrepan:

| Fuente que coincide con la suma real de ítems | Comprobantes | Delta |
|---|---:|---:|
| **`c/ cobro`** | **210** | $3.811.874,62 |
| por-ítem | 14 | $66.115,04 |
| ninguna (centavos de redondeo) | 35 | — |

La 24300 es una de esas 14 excepciones, no la regla. **Regla definitiva:**

1. **Total de cabecera** → columna `Total Venta` del `c/ cobro`. Si el comprobante no está ahí, el
   por-ítem.
2. **Ítems** → siempre del por-ítem.

### 3.10 `Ventas 2021.xlsx`: importes de cabecera vacíos
En 2021 el export por-ítem trae **`Total Venta`, `Subtotal con Descuento` y las columnas de IVA
vacías en 1.354 de 2.123 ventas** — sólo pobla `Cantidad` y `Precio Unitario`. Son $17,07M de los
$18,63M que faltaban. Se resuelve solo con la regla de §3.9 (el total sale del `c/ cobro`); para los
**ítems** de esas ventas hay que calcular el importe como `Cantidad × Precio Unitario`.
En 2022 y 2025 hay 6 y 12 casos de total en 0 y ésos **sí** se reconstruyen desde los ítems.

### 3.10b IVA prorrateado en 2021
Como 2021 no trae ninguna columna de IVA poblada, los ítems suman el **neto** mientras el total de
cabecera viene **con IVA**: la venta cerraba ~21% corta y daban **1.084 comprobantes descuadrados**.
Se prorratea el total real sobre el neto de cada renglón, sólo cuando ningún ítem trae IVA propio.
Resultado: **165 descuadres**, y los que quedan son ventas que en Contagram ya tienen total 0.

### 3.11 Agrupación de filas en comprobantes
La clave es **`Id` + familia de comprobante** (`FC` / `NC` / `ND`), no el `Id` solo: en **2021 el Id
se reutiliza** entre facturas y notas de crédito (11 casos). Con la clave compuesta, cliente y fecha
son consistentes dentro de cada grupo en los 6 años (0 inconsistencias).

`Tipo de Comprobante` vacío (2.095 filas / 1.520 comprobantes) **son ventas**, no basura: se tratan
como `FC`. Coinciden exactamente con `ARCA = ---`, o sea las ventas sin comprobante fiscal.

Recuento resultante: **FC 23.563 · NC 638 · ND 54**. La factura que falta contra las 23.564
esperadas es la **24267**, borrada en Contagram (aparece en el `c/ cobro` y no en el por-ítem) —
confirmado, es exactamente la única con esa condición en los 6 años.

---

## 4. Reglas de importación — Ventas

### 4.1 Qué se importa
**Todo** lo que esté en los Excel hasta el 05/08/2026: incluido el cliente sentinela
(`NO USAR MAS`, 4.063 filas), las ventas sin comprobante fiscal (9.284) y las NC/ND.
**Nada se excluye por ser "basura"** — es plata que pasó por la caja.

### 4.2 Qué NO se importa
1. Las **41 ventas de Mercado Libre ya convertidas en el CRM** (lista en
   `public/imports/revision_duplicados_ml.xlsx`, veredicto `EXCLUIR - duplicada`).
   ⚠️ **Recalcular la lista inmediatamente antes de correr el import**: aparecieron 2 nuevas entre
   el 08 y el 10/08 (órdenes viejas de ML convertidas tarde). Ver §7.
2. Las ventas **24267** y **2140**, borradas en Contagram (confirmado por el usuario).

### 4.3 Idempotencia
`legacy_id = "{año}-{Id del Excel}"`. El `Id` se repite entre años, por eso lleva el año.
Si el `legacy_id` ya existe, se saltea.

### 4.4 Numeración de comprobante
- Sólo las **7.971 con `ARCA = Aprobado`** llevan `nro_comprobante` real, formato `0005-00002304`
  (punto de venta 5 del sistema viejo). Verificado: esa serie es **100% única**.
- El resto va con `nro_comprobante = null` (las `Sin Enviar`/`Error` reusan números).
- **No colisiona** con la serie fiscal del CRM (punto de venta 9, la asigna ARCA).

### 4.5 Ítems
| Caso | Líneas | Tratamiento |
|---|---|---|
| Id del `Código` existe en `productos` | 34.605 (93,3%) | `producto_id` normal |
| Id no existe | 2.233 (6,0%) | **crear 404 productos legacy** (inactivos, con Id original) |
| Sin Id en el `Código` | 262 (0,7%) | **renglón libre**: `producto_id = null` + `descripcion` |

El Id sale del `Código` con `^(\d{3,6})\b`, o del patrón `ID:(\d+)`.
Los 404 legacy se crean con los datos del propio Excel de ventas (nombre, código, rubro, proveedor,
costo). **No hace falta ningún export adicional.**
Los 262 libres no son productos: `CODIGO LIBRE`, `9999`, `Ajuste cta cte`, `codigo no encontrado`.

### 4.6 Clientes
Matching por **nombre exacto con espacios colapsados**. Si no existe, se crea.

---

## 5. Reglas de importación — Cobros

**Imputación por cliente, no por factura.** Fundamento medido: a nivel cliente, el `Cobrado` del
Excel de ventas y la suma de cobros de `Cuentas/` coinciden en **15.114 de 15.124 clientes (99,9%)**;
diferencia total $332.972,82 sobre $1.506 millones (**0,02%**).

Algoritmo:
1. Cobros con referencia de comprobante que resuelven unívocos → imputar directo (6.350).
2. El resto → por cliente, en orden cronológico (lo más viejo primero), respetando el `Cobrado`
   que ya trae cada venta.

**Ojo con el `Cobrado = 0`:** 499 ventas lo tienen en 0 pero **485 están marcadas `Estado =
Cobrado`**. No es un error: en Contagram esos cobros quedaron **a cuenta del cliente**, sin imputar
a la factura. La plata está en `Cuentas/` (verificado caso por caso). Es exactamente por esto que la
imputación va por cliente.

**Deuda real: sólo 33 ventas** con saldo (31 vencidas + 2 a cobrar), **$9.882.255,64**.

---

## 6. Cifras de control (criterio de aceptación)

| Concepto | Valor esperado |
|---|---|
| Ventas en los Excel | 23.564 |
| **Total facturado** | **$1.570.665.960,38** |
| **Cobrado** | **$1.506.014.720,12** |
| **A cobrar** | **$9.882.255,64** |
| Líneas de ítem | 37.100 |
| Notas de crédito | **638** ($56.207.437,48) — corregido: eran 629 por agrupar sólo por `Id`; con la clave `Id`+familia (§3.11) aparecen 9 más, todas de 2021 |
| Notas de débito | 54 ($2.203.385,37) |
| Cobros | 25.086 |
| Compras | 2.526 comprobantes / 11.871 líneas |
| Gastos | 9.394 |

Restar de facturado/cobrado lo correspondiente a las ventas excluidas (§4.2) antes de comparar.

**Verificado el 10/08/2026** con `ComprobantesContagram` (lectura completa de los 6 años, sin tocar
la base). Aplicando el corte del 05/08/2026 quedan **23.546 FC · 627 NC · 54 ND**; las 28 restantes
(17 FC + 11 NC) caen fuera del corte, y con ellas el recuento vuelve exactamente a 23.563 / 638 / 54.
Los **262 renglones libres** coinciden clavados con §4.5, y la **24267 es la única** de los 6 años
que figura en el `c/ cobro` sin ítems — confirmación independiente de que estaba borrada.

**Diferencias conocidas y aceptadas:** 13 ventas cuyos ítems no suman el total, $643.924,56
(**0,04%**) — 11 son de junio 2021, la semana en que estrenaron el sistema. Detalle en
`public/imports/revision_pendientes_ventas.xlsx`.

---

## 6b. Resultado del dry-run (10/08/2026)

`php artisan migracion:ventas --dry-run` — comando `MigrarVentasContagram`, apoyado en
`ComprobantesContagram`. Reemplaza a `ventas:importar-historicas`, marcado obsoleto.

| | Migrado | Control | |
|---|---:|---:|---|
| Ventas | **23.563** | 23.563 | exacto |
| Notas de crédito | **638** | 638 | exacto |
| Notas de débito | **54** | 54 | exacto |
| Líneas de ítem | 36.224 + 876 de NC/ND = **37.100** | 37.100 | exacto |
| **Cobrado** | **$1.506.014.720,12** | $1.506.014.720,12 | **exacto** |
| **Notas de crédito ($)** | **$56.207.437,48** | $56.207.437,48 | **exacto** |
| **Facturado** | **$1.570.454.381,11** | $1.570.665.960,38 | −$211.579,27 = **la 24267** ($211.581,06), excluida a propósito |
| Notas de débito ($) | $2.201.067,13 | $2.203.385,37 | −$2.318,24 (0,1%), pendiente |

**El corte es inclusive**: los 28 comprobantes del propio 05/08/2026 entran. Comparar la fecha como
texto `Y-m-d` y no como instante Carbon no es cosmético — `CarbonImmutable::parse()` resuelve en la
timezone de la app y esos 28 quedaban adentro o afuera según desde dónde se invocara el chequeo.

---

## 7. Procedimiento de ejecución

0. **Borrar las ventas del import viejo.** `ventas:importar-historicas` usaba `legacy_id` =
   `{año}-{Id}` y el nuevo usa `{año}-{familia}-{Id}`: la comprobación de idempotencia **no las
   reconoce** y se duplicarían $1.500 millones sin un solo error visible. `migracion:ventas` aborta
   solo si las detecta (hay que pasarle `--force` para saltearlo, y no hay que hacerlo).
1. **Backup de la base del VPS** (`mysqldump`), como en cada import anterior.
2. **Recalcular la lista de duplicados de ML.** Hecho el 10/08/2026 contra la base del VPS:
   **41 duplicados** ($5.602.095,64), en `public/imports/exclusiones_ml.json`. Coincide con el
   número del análisis original, ahora confirmado de forma independiente. Las 56 ventas del CRM con
   `fecha_emision <= 2026-08-05` siguen siendo 56, o sea que no aparecieron órdenes nuevas.

   **Cruzar por nombre + monto con tolerancia de $1**, no por monto exacto: el CRM y Contagram
   redondean distinto y difieren hasta en un centavo ($171.818,78 vs $171.818,79). Con igualdad
   exacta se encontraban sólo 20 de los 41.

   Las 56 se reparten en: 41 duplicados + 1 venta de prueba de ARCA + **14 órdenes de ML cargadas
   con el nickname** (`NOYAISABEL`, `FALVAR2009`), que no cruzan por nombre. **No son duplicados**,
   demostrado por balance agregado: el 05/08 Contagram facturó 4 ventas de categoría Mercadolibre y
   las 4 ya están entre los 41, así que **no queda ninguna venta de ML de ese día por importar** —
   esas órdenes nunca entraron a Contagram. (Por monto era indecidible: $171.818,79 se repite en
   decenas de ventas y daba 5 candidatos por orden. Por nombre real tampoco: ML no lo entrega,
   `comprador_nombre` viene null.)
3. `php artisan migracion:ventas --dry-run --excluir=…` — no escribe nada; reporta las cifras.
4. Revisar el reporte **contra la tabla de §6b**.
5. `php artisan migracion:ventas --excluir=…` y después `php artisan migracion:cobros`.
   Los cobros van **segundos y aparte**: las ventas tienen que estar bien antes de imputarles plata,
   y el comando saltea toda venta que no encuentre migrada (así un duplicado de ML excluido no
   recibe su cobro).
6. Verificación post-import contra las mismas cifras + control de que **no se movió stock ni
   tesorería**: `movimientos_stock` y `movimientos_tesoreria` no deben crecer.

**Probado end-to-end en local con el año 2021** (10/08/2026): 2.123 ventas · 16 NC · 1 ND ·
2.104 cobros; facturado $24.868.576,33 = exactamente el del `c/ cobro`; **0 movimientos de stock y
0 de tesorería**. La idempotencia se verificó sola: la primera corrida falló a mitad por
`mes_imputacion`, y al repetirla salteó las 70 ya escritas en vez de duplicarlas.

---

## 8. Pendientes conocidos (no bloquean)

- **Compras 2021-2024 en formato "c/ pago"** (40 columnas, con `Pagado`/`Estado`): sin eso no se
  puede imputar la parte de los pagos que no trae referencia de comprobante.
- **2 ventas dudosas de Mercado Libre** (hoja 3 de `revision_pendientes_ventas.xlsx`).
- **~1.446 clientes con `created_at` con día y mes invertidos** (del import anterior, cosmético).
- **Bug ARCA `10051`** ("los importes de AlicIVA no se corresponden con los porcentajes"), visto una
  vez el 03/08. A vigilar cuando se facturen ventas con IVA mixto.
