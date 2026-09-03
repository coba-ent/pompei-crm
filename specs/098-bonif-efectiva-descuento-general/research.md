# Research: Bonificación efectiva por línea con Descuento General

Sin `NEEDS CLARIFICATION` pendientes en el Technical Context del plan — el bug y su causa raíz ya
estaban confirmados leyendo el código antes de llegar a esta fase (`CalculoComprobante.php`, los 4
`renderItems()`/`recalcular()`, los 4 PDF, `AjustesPendientesNotaCreditoDebito.php`). Este
documento fija las decisiones de diseño puntuales que sí había que resolver.

## Decisión 1: fórmula del porcentaje efectivo de línea (PDF de Presupuesto/Venta/Compra)

**Decisión**: `bonifEfectivaPct() = round((1 - subtotal / (cantidad * precio_unitario)) * 100, 2)`,
calculado en cada modelo de ítem a partir de columnas ya guardadas (`subtotal`, `cantidad`,
`precio_unitario`), sin depender de `descuento_general_pct` de la cabecera ni de reconstruir el
`factor` del comprobante.

**Rationale**: `subtotal` ya sale de `CalculoComprobante::calcular()` con el descuento de línea Y
el Descuento General aplicados (`app/Services/Ingresos/CalculoComprobante.php:73`:
`$subtotalFinal = round($subtotalLinea * $factor, 2)`). Comparar ese valor final contra el bruto
(`cantidad * precio_unitario`) da directamente el % efectivo combinado, sin tener que leer ni
recalcular el Descuento General de cabecera por separado. Esto tiene una ventaja importante: es
**resistente a que el comprobante esté en modo "monto fijo" ($)** — en ese modo
`descuento_general_pct` de la cabecera queda `null` (ver `PresupuestoController.php:262`), así que
cualquier fórmula que dependiera de leer ese campo se rompería justo en ese caso. Comparar contra
`subtotal` ya guardado no tiene ese problema: el monto fijo ya fue convertido a un factor y
aplicado a cada línea antes de guardar.

**Alternatives considered**:
- *Sumar `descuento_pct` de línea + `descuento_general_pct` de cabecera*: descartada — no es
  matemáticamente correcta (dos descuentos del 10% no dan 20%, dan 19%: `1 - 0.9*0.9`), y además
  falla en modo "monto fijo" porque `descuento_general_pct` es `null` en ese caso.
- *Guardar el % efectivo como columna nueva en cada ítem al calcular*: descartada — es un dato
  derivado 100% reconstruible desde columnas existentes; agregar una columna redundante viola el
  principio de una sola fuente de verdad y obligaría a backfill de comprobantes históricos, algo
  que la spec explícita (FR-007) que no hace falta.

## Decisión 2: por qué NO se replica esta fórmula en NC/ND

**Decisión**: `NotaCreditoDebitoItem` no recibe ningún método de porcentaje efectivo. La columna
"%Bonif." de NC/ND sigue leyendo `descuento_pct` crudo, sin cambios.

**Rationale**: decisión de negocio ya tomada en la clarificación de la spec (Session 2026-09-03),
apoyada en documentación ya verificada contra el propio formulario de Contagram (spec 095): NC/ND
mantiene el descuento de línea y el Descuento General como dos campos separados
(`discount` / `note[discount]`), sin fundirlos — a diferencia de Presupuesto/Venta/Compra, que sí
los funden en `subtotal`. Además, técnicamente sería más costoso: `nota_credito_debito_items` no
tiene columna `subtotal`, así que la fórmula de la Decisión 1 no es aplicable sin agregar una
columna nueva (que la spec descarta explícitamente).

**Alternatives considered**:
- *Igualar el comportamiento a las otras 3 pantallas por consistencia interna*: descartada — el
  principio rector del proyecto (CLAUDE.md) es fidelidad estructural a Contagram, no consistencia
  interna entre módulos del CRM. Donde Contagram distingue, el CRM distingue.

## Decisión 3: dónde vive el cálculo del factor en pantalla (JS)

**Decisión**: se extrae una función `factorDescuentoGeneral()` dentro de cada archivo
(`presupuestos.js`, `ventas.js`, `compras.js`) que hoy ya tiene ese cálculo duplicado dentro de
`recalcular()`. `renderItems()` la llama para multiplicar el Subtotal/Total de cada fila antes de
pintarlo; `recalcular()` pasa a llamar a la misma función en vez de recalcular el factor de nuevo.

**Rationale**: el cálculo ya existe y está probado en producción (es el que arma el total de a pie
de página, que siempre estuvo bien) — extraerlo evita una segunda implementación que pueda
divergir en un caso límite (redondeo, modo monto fijo) de la que ya se usa para el total. Mantener
la función en cada archivo JS (no un módulo compartido) es consistente con que estos 3 archivos ya
son IIFEs independientes sin sistema de imports entre sí — el proyecto no tiene una capa de
utilidades JS compartida para esta clase de cálculo (`pctIva()`, por ejemplo, también está
duplicada en `compras.js` y `notas-credito-debito.js`).

**Alternatives considered**:
- *Calcular el Subtotal de fila pidiéndolo al backend por AJAX en cada tecla*: descartada — el
  comprobante se arma en memoria en el cliente antes de guardar (no hay id todavía en alta), y
  además introduciría latencia perceptible tecla a tecla donde hoy el recálculo es instantáneo.
- *Un módulo JS compartido (`descuento-general.js`) importado por los 3 archivos*: descartada por
  ahora — el proyecto no tiene un mecanismo de import entre estos archivos Vite (son entradas
  independientes), y crear uno sería una refactorización de infraestructura no pedida por esta
  spec. Si en el futuro se repite una tercera vez este patrón en un módulo nuevo, ahí se justifica
  extraerlo.

## Decisión 4: redondeo — tolerancia de 1 centavo, no exactitud absoluta

**Decisión**: SC-001 fija una tolerancia de 1 centavo entre la suma de los Subtotales de fila (ya
con el factor aplicado) y el "Subtotal con Descuento" de pie de página, en vez de exigir
coincidencia exacta.

**Rationale**: `CalculoComprobante` ya redondea cada línea a 2 decimales de forma independiente
(`round($subtotalLinea * $factor, 2)` por ítem) y después suma esos valores ya redondeados — es el
mismo comportamiento que va a replicar el render de pantalla. Con varias líneas, la suma de
redondeos individuales puede diferir en 1 centavo del redondeo de la suma total; ese
comportamiento ya existe hoy en el backend (es el que efectivamente se guarda) y esta feature no
lo cambia, sólo lo hace visible línea por línea antes de guardar.

## Decisión 5: fila "Descuento General" en el PDF de NC/ND (FR-009)

**Decisión**: se agrega una fila `<tr><td>Descuento General</td><td>...</td></tr>` al bloque de
totales del PDF de NC/ND. A diferencia de Presupuesto/Venta/Compra, `NotaCreditoDebito` **no**
persiste `subtotal_sin_descuento` ni `subtotal_con_descuento` — sólo el `monto` final ya
calculado. El controlador (`NotaCreditoDebitoController::pdf()`) tampoco recibe esos totales: sólo
manda el modelo con sus ítems cargados. Así que hace falta un método nuevo, `NotaCreditoDebito::montoDescuentoGeneral()`
(o equivalente), que replique en PHP exactamente el mismo algoritmo que ya usa
`notas-credito-debito.js::recalcular()` client-side:

```
subtotalSinDescuento = Σ items: cantidad * precio * (1 - descuento_pct/100)
factor = tipo === 'monto'
    ? max(0, 1 - (descuento_general_monto / subtotalSinDescuento))   // si subtotalSinDescuento > 0, si no 1
    : 1 - (descuento_general_pct / 100)
montoDescuentoGeneral = round(subtotalSinDescuento * (1 - factor), 2)
```

**Rationale**: replicar el algoritmo del JS (no inventar una fórmula distinta) es necesario porque
NC/ND es la única de las 4 pantallas donde el monto final SALE del cliente ya calculado y el
servidor no lo recalcula (`NotaCreditoDebitoController` guarda `$datos['monto']` tal cual llega,
sin pasar por un `CalculoComprobante` propio — ver plan.md Technical Context). Si el PDF calculara
el descuento general con una fórmula distinta a la que produjo el `monto` guardado, la fila nueva
podría no cuadrar con el total ya impreso más abajo, generando exactamente la clase de
inconsistencia que esta spec busca eliminar. El sitio natural para este método es el modelo
`NotaCreditoDebito` (mismo patrón que `PresupuestoItem::bonifEfectivaPct()` en el Decisión 1: el
Blade no calcula, sólo llama a un método del modelo).

**Alternatives considered**:
- *Guardar `subtotal_sin_descuento` en la nota al crearla (persistir en vez de derivar)*:
  descartada — agregar columnas a `notas_credito_debito` está fuera del alcance que fija la spec
  (FR-007/no-migración) y no es necesario: el dato es 100% reconstruible desde los ítems ya
  persistidos, igual que ya vale para los otros 3 comprobantes.
- *Calcular `monto - (monto sin descuento)` en el Blade directamente*: descartada — pondría lógica
  de negocio (la fórmula del factor, con su rama de modo "monto fijo") dentro de una vista, en vez
  de en el modelo, que es donde vive el resto de estos cálculos en el proyecto.
