# Research: Costo congelado en el ítem de venta (spec 075)

**Fecha**: 2026-08-24

Este documento registra las decisiones técnicas tomadas antes de diseñar, con su fundamento y las
alternativas descartadas. Todo lo que acá se afirma sobre Contagram está respaldado por exports
reales, no por inferencia.

---

## R1. La regla del CMV de Contagram

**Decisión**: `CMV de la línea = costo unitario del producto congelado al crear la venta × cantidad`.

**Fundamento**: procesados los 15 archivos de `actualziacion/julio/Informe_de_Ventas_Detallado_*.xlsx`
(1.016 líneas únicas, 738 ventas, julio 2026). El export cuadra al centavo con las cards de Contagram
(Costo Actual 40.871.161,68 vs 40.871.161; CMV 40.574.923,05 vs 40.574.923; Precio de Venta
80.511.740,62 vs 80.511.740,62), lo que lo valida como fuente.

El ratio `CMV Total / Costo Total Actual` calculado línea por línea es **discreto y agrupado por
proveedor**, no continuo:

| Proveedor | Líneas | Ratios distintos |
|---|---|---|
| FV | 262 | 4 (0,96617 / 0,96618 / 0,96619 / 1,0) |
| JPD AMOBLAMIENTO | 211 | 2 (0,96154 / 1,0) |
| KURYMAR | 31 | 2 (0,92593 / 1,0) |
| Ferrum, Ideal, Pompei SRL, GOOD LOOKING, Mauricio, RAO | 274 | 1 (1,0) |
| Peirano | 10 | 4, incluyendo **>1** (1,11195 / 1,20573) |

Y depende de la fecha de emisión: FV usa 0,96618 en ventas del 01/07–24/07 y 1,0 del 20/07–31/07;
KURYMAR 0,92593 hasta el 07/07 y 1,0 desde el 16/07. Son aumentos de lista por proveedor. Los ratios
mayores a 1 de Peirano son productos cuyo costo bajó después de la venta.

**Alternativas descartadas**:
- *Promedio ponderado de compras* (lo que hace hoy la spec 068): no puede generar ratios discretos
  alineados a proveedor y fecha. Además da $24,6M contra $40,57M reales en julio.
- *Costo actual del producto*: daría ratio 1,0 en el 100% de las líneas, y no lo da.

---

## R2. Por qué la spec 068 se equivocó (para no repetirlo)

La spec 068 observó en la cuenta demo que "los ítems del Id 5 tienen Costo Total Actual > 0 y CMV 0"
y **concluyó** que era porque esos productos nunca se habían comprado. Esa fue una **inferencia sobre
una causa**, no un dato observado: el relevamiento nunca vio de dónde salía el número.

La hipótesis alternativa —"esos productos no tenían costo cargado cuando se hicieron esas ventas"—
explica lo mismo, y con datos reales explica **más**: en el export de julio hay 45 líneas de 1.016
(4,4%) con CMV 0, y el CRM tiene 227 productos con costo 0.

**Lección para el proyecto**: una regla de cálculo no se deriva de un caso único en una cuenta demo
vacía. Se valida contra un export con volumen, cruzando al menos dos variables independientes (acá:
proveedor y fecha).

---

## R3. El Informe de Compras NO tiene el problema — verificado, no asumido

**Decisión**: Compras queda fuera de alcance.

**Fundamento**: contrastado contra `migracion-nueva/excel-origen/Compras/2026 Compras.xlsx`
(1.736 líneas, 354 compras, 700 códigos de producto):

- `SUM(Costo × Cantidad) = 194.444.921,65` coincide con la card "Costo Actual" ($194.444.921) ⇒ la
  card es costo vigente × cantidad, exactamente lo que ya hace el CRM.
- **699 de 700 productos tienen un único valor de `Costo` en todo el año**, sin variar con la fecha de
  compra. Si estuviera congelado por línea variaría con los aumentos de lista, como pasa en Ventas.
- El Informe de Compras **no tiene card de CMV**: sus KPIs son Total Compras Creadas / ND / NC / Total
  Compras y Cantidad Prod./Serv. / Cantidad Compras Creadas / Compra Promedio / Costo Actual.

Además, el costo real de una compra ya vive en `compra_items.precio_unitario`. No hay nada que
congelar.

---

## R4. Dónde se crean las líneas de venta (superficie a tocar)

Relevado sobre el código actual. Hay **cuatro** puntos de creación en producción y **tres** en
comandos de migración:

| Punto | Archivo | Nota |
|---|---|---|
| Alta manual | `VentaController::guardarItems()` (línea 849), llamado desde `store` (474) | Recibe `$resultado['items']` de `CalculoComprobante::calcular()` |
| Edición | `VentaController::update()` (566-568) | **Borra y recrea** los ítems — ver R5 |
| Mercado Libre | `Services/MercadoLibre/ConversorOrdenAVenta.php:315` | `$venta->items()->create($item)` |
| Tiendanube | `Services/Tiendanube/ConversorOrdenAVenta.php:232` | ídem |
| Migración histórica | `ImportarVentasHistoricas.php:285`, `MigrarVentasContagram.php:345`, `RefrescarVentasEditadas.php:125` | Fuera de alcance (sin backfill) |

**Decisión**: el costo se congela en **`CalculoComprobante::calcular()`**, que es el único lugar por el
que ya pasan el alta manual y la edición, y se agrega explícitamente en los dos conversores (ML y TN),
que arman el array de ítems por su cuenta.

**Alternativa descartada**: un *model event* (`creating`) en `VentaItem`. Sería el punto único más
tentador, pero congelaría el costo también en los comandos de migración —que deben quedar en `null`
para que el fallback los tome— y volvería la regla invisible en el código de negocio. Se prefiere que
el congelamiento sea explícito y testeable.

---

## R5. El problema difícil: la edición borra y recrea los ítems

`VentaController::update()` hace `$venta->items()->delete()` y después `guardarItems()` con los ítems
recalculados. Con eso, "conservar el costo congelado" (FR-009) **no sale gratis**: los ítems viejos ya
no existen cuando se crean los nuevos.

**Decisión**: capturar el costo congelado **antes** del `delete()` y re-aplicarlo a los ítems nuevos
que correspondan a la misma línea; las líneas realmente nuevas congelan el costo del día de la
edición.

A favor: `update()` **ya captura** `$itemsAnteriores = $venta->items()->with('producto')->get()` en la
línea 538, para `StockDeVenta::reaplicarPorEdicion()`. La información ya está en memoria en el momento
justo; sólo hay que usarla también para esto.

**Criterio de correspondencia**: por `producto_id`, consumiendo cada costo anterior una sola vez
(si la venta tenía 2 líneas del producto X y la edición deja 3, las dos primeras conservan su costo y
la tercera congela el del día). Para líneas sin `producto_id` no hay costo que conservar.

**Alternativas descartadas**:
- *Cambiar `update()` a un diff incremental en vez de borrar y recrear*: es la solución de fondo, pero
  toca stock, conceptos y numeración; excede el alcance de esta spec y agrega riesgo a un flujo que
  hoy funciona.
- *Recongelar todo en la edición*: rechazado por el usuario en `/speckit-clarify` — haría que corregir
  el nombre del cliente de una venta de marzo alterara el Resultado de marzo.

---

## R6. Notas de crédito: de dónde sale el costo

**Decisión** (de `/speckit-clarify`): `origen = 'venta_original'` copia el costo congelado de la línea
de la venta que revierte; `origen = 'nuevo'` y las NC/ND sin venta asociada congelan el costo vigente.

**Detalle técnico relevado**: `nota_credito_debito_items` guarda `producto_id` pero **no** una
referencia al `venta_item` de origen. La correspondencia se resuelve por `nota.venta_id` +
`producto_id` (la nota sí referencia la venta). `notas_credito_debito.venta_id` es nullable
(migración `2026_08_18_060002_hacer_venta_id_nullable_en_notas_credito_debito`), así que el caso "sin
venta asociada" es real y hay que manejarlo.

Si la venta original es histórica y su línea no tiene costo congelado, la línea de la NC tampoco lo
tendrá y caerá al fallback de FR-003 — lo que mantiene coherentes las dos puntas (la venta y su
reversión usan el mismo criterio).

**Puntos a tocar**: `NotaCreditoDebitoController` tiene **6** llamadas a `$nota->items()->create()`
(líneas 136, 162, 282, 305, 386, 408), que son los pares con-stock / sin-stock de tres flujos
distintos (alta con venta, alta sin venta, edición). Conviene centralizar el armado del ítem en un
método privado en vez de repetir la regla seis veces.

---

## R7. Cómo convive el costo congelado con el fallback en SQL

**Decisión**: una sola expresión SQL por línea, sin ramas por tipo de comprobante:

```
CMV = COALESCE(<costo_congelado>, <costo_promedio_compras>, 0) * <cantidad_con_signo>
```

`CostoMercaderiaVendida::sqlCmv()` ya recibe la expresión de cantidad con el signo aplicado (para que
las NC salgan negativas sin una rama especial, FR-016 de la spec 068). Se extiende esa misma firma
para que reciba también la columna del costo congelado, y el `leftJoinSub` del promedio se conserva
tal cual para el fallback.

**Distinción crítica**: `NULL` significa "esta línea no tiene costo congelado ⇒ usá el fallback", y
`0` significa "esta línea tiene costo congelado y vale cero" (producto sin costo cargado, FR-007). Por
eso la columna **debe ser nullable y no tener default 0**: un default 0 haría que toda venta nueva de
un producto sin costo fuera indistinguible de una venta histórica, y el `COALESCE` nunca llegaría al
fallback. Es el mismo tipo de error que ya está documentado en `docs/modelo_datos.md` para
`compra_items.iva_pct`.

---

## R8. Rendimiento

**Decisión**: sin cambios de estrategia. Leer una columna de la propia línea es más barato que el
`leftJoinSub` actual; el join se conserva sólo porque el fallback lo necesita. A medida que las
ventas nuevas dominen el volumen, el CMV se vuelve progresivamente más barato de calcular, nunca más
caro.

---

## R9. Backfill histórico (documentado, NO implementado)

Aunque está fuera de alcance, se deja registrado que es **viable y exacto**, para que una spec futura
no tenga que redescubrirlo:

- El export "Informe de Ventas Detallado" de Contagram trae `CMV Total` y `Cantidad` por línea.
- `costo_unitario = CMV Total ÷ Cantidad` es división exacta (verificado: cantidad 2,0 con CMV
  189.744,04 → 94.872,02).
- Llave de correspondencia disponible: `Id` de venta + `Código` de producto (sólo 1 línea de 1.016
  viene sin código).
- Limitación conocida: 45 de 1.016 líneas (4,4%) tienen CMV 0 y no hay costo que recuperar; y las
  ventas creadas en el CRM después del corte del 13/08/2026 no existen en Contagram.
