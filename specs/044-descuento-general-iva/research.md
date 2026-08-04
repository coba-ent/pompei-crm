# Research: Descuento general aplicado proporcionalmente a neto e IVA

Sin `NEEDS CLARIFICATION` pendientes — la decisión de negocio (descuento pre-impuesto, proporcional a
neto e IVA) ya fue confirmada por el usuario antes de escribir la spec.

## 1. Fórmula actual vs corregida

**Actual** (`CalculoComprobante::calcular()`):

```text
por ítem:  subtotal = bruto - bruto*descuento_pct/100          (descuento de línea, sin cambios)
           subtotal_con_iva = subtotal + subtotal*iva_pct/100

subtotalSinDescuento = Σ subtotal
descuento = subtotalSinDescuento * descuento_general_pct / 100      ← sólo sobre neto
subtotalConDescuento = subtotalSinDescuento - descuento
total = Σ subtotal_con_iva - descuento + Σ conceptos                ← descuento restado del
                                                                        total-con-IVA sin discriminar
```

El resultado: `total - subtotalConDescuento` (el "IVA implícito" del comprobante) es el IVA
**sin** descontar — ejemplo real, Venta 0001-00016359: `subtotalConDescuento=254189.88`,
`total-subtotalConDescuento=62799.86`, que es el 21% de `299046.92` (el neto **antes** del 15% de
descuento), no de `254189.88`. Rompe la relación `BaseImp × alícuota = Importe` que ARCA exige por
cada bloque `AlicIva` — spec 042 lo detecta y rechaza antes de enviar.

**Corregida**: aplicar el factor `(1 - descuento_general_pct/100)` a `subtotal` y a
`subtotal_con_iva` de cada ítem (no sólo al `subtotal`), de forma que el descuento general reduzca
proporcionalmente tanto el neto como el IVA de cada línea:

```text
por ítem:  subtotal_linea = bruto - bruto*descuento_pct/100              (sin cambios)
           subtotal_con_iva_linea = subtotal_linea + subtotal_linea*iva_pct/100

factor = 1 - descuento_general_pct/100
subtotal_final = round(subtotal_linea * factor, 2)
subtotal_con_iva_final = round(subtotal_con_iva_linea * factor, 2)

subtotalSinDescuento = Σ subtotal_linea            (sin cambios de significado — sigue siendo el
                                                       neto antes del descuento general)
subtotalConDescuento = Σ subtotal_final
descuento = subtotalSinDescuento - subtotalConDescuento
total = Σ subtotal_con_iva_final + Σ conceptos
```

**Rationale**: el factor se aplica a nivel de línea (no como un único ajuste agregado al final) para
que, cuando hay ítems en distintas alícuotas de IVA, cada alícuota quede descontada en su propia
proporción — condición necesaria para que cada bloque `AlicIva` (spec 042) sea internamente
consistente. Aplicar el descuento general como un único monto agregado (como hace hoy) no permite
reconstruir después a qué alícuota le corresponde cada parte del descuento.

**Alternativas consideradas**:
- *Aplicar el descuento general una sola vez sobre el total agregado, sin tocar el desglose por
  ítem*: descartada — es lo que se corrige (produce el desglose por ítem inconsistente con el total,
  que es exactamente el problema detectado).
- *Prorratear el descuento general al final usando el ratio `subtotalConDescuento/subtotalSinDescuento`
  sobre los ítems ya calculados* (en vez de aplicar el factor por ítem desde el cálculo): matemáticamente
  equivalente cuando hay una sola alícuota, pero más frágil si en el futuro se agregan descuentos de
  línea combinados con redondeos distintos — se prefiere aplicar el factor directamente en el punto
  donde ya se calculan `subtotal`/`subtotal_con_iva` por ítem, un solo lugar, una sola fórmula.

## 2. Impacto en los campos persistidos

`VentaItem.subtotal` / `PresupuestoItem.subtotal` y `subtotal_con_iva` pasan a incluir el descuento
general prorrateado, no sólo el descuento de línea. Se relevó dónde se usa `VentaItem.subtotal` fuera
de `CalculoComprobante`/`VentaController`: no hay otro consumidor (informes, márgenes, stock) — el
campo es de uso exclusivo de la vista de Ventas/Presupuestos y, desde spec 042, de la solicitud de CAE
a ARCA. Bajo impacto de cambiar su significado a "neto ya con todos los descuentos aplicados,
incluido el general".

## 3. Redondeo y tolerancia

Redondear a 2 decimales por ítem (`subtotal_final`, `subtotal_con_iva_final`) puede generar una
diferencia acumulada de centavos entre la suma de ítems y lo que daría un cálculo agregado único —
mismo tipo de diferencia que ya cubre la tolerancia de $0.01 de `ValidadorDatosFiscales` (spec 042).
No se introduce una tolerancia nueva ni se cambia esa spec.

## 4. Compatibilidad con spec 042 (ARCA)

`MapeadorComprobante`/`ValidadorDatosFiscales`/`VentaController::enviarArca()` (spec 042) ya leen
`VentaItem.subtotal`/`iva_pct` tal como están persistidos — no requieren ningún cambio: al corregirse
en el origen (`CalculoComprobante`), los ítems que llegan a la solicitud de CAE ya son consistentes.
No se toca ningún archivo de `app/Services/Arca/`.
