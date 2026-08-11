# Research: Toggle %/monto fijo para el Descuento General

## R1: Cómo convertir "monto fijo" en la fórmula de prorrateo ya existente

**Decisión**: `CalculoComprobante::calcular()` recibe `descuentoGeneralTipo` (`'porcentaje'|'monto'`)
y `descuentoGeneralValor` (float). Internamente, si el tipo es `monto`, se convierte a un porcentaje
efectivo:

```
$pctEfectivo = $subtotalSinDescuento > 0
    ? min(100, ($valor / $subtotalSinDescuento) * 100)
    : 0;
```

y de ahí en adelante corre exactamente el mismo algoritmo que hoy (factor `1 - pct/100` aplicado por
línea, spec 044 ya validado).

**Rationale**: evita duplicar la lógica de prorrateo proporcional a neto e IVA que spec 044 corrigió
específicamente para no romper la consistencia fiscal (`BaseImp × alícuota = Importe` por bloque). Un
monto fijo de descuento general "vale" el mismo % que representa sobre el subtotal sin descuento — matemáticamente
equivalente a cargar ese % directamente, así que reutilizar el camino ya probado es la opción de menor
riesgo.

**Alternatives considered**:
- *Restar el monto fijo directamente del total al final, sin prorratear por ítem/alícuota*: descartado
  — reproduciría exactamente el bug que spec 044 corrigió (IVA declarado inconsistente con el neto
  descontado), bloqueando de nuevo el envío a ARCA.
- *Introducir una segunda fórmula paralela para monto fijo*: descartado — duplica lógica fiscal
  sensible en dos lugares, mayor superficie de bugs y de mantenimiento futuro.

## R2: Dónde persistir tipo + valor

**Decisión**: dos columnas nuevas por tabla (`ventas`, `presupuestos`, `compras`,
`notas_credito_debito`):
- `descuento_general_tipo` ENUM(`porcentaje`,`monto`) NOT NULL DEFAULT `porcentaje`
- `descuento_general_monto` DECIMAL(12,2) NULLABLE

La columna `descuento_general_pct` ya existente (DECIMAL 5,2) se mantiene sin cambios y sigue
guardando el valor cuando el tipo es `porcentaje`. `notas_credito_debito` no tiene hoy ninguna columna
de descuento general — se agregan las tres (`descuento_general_pct` nueva ahí, más las dos de arriba).

**Rationale**: `descuento_general_pct` es DECIMAL(5,2) — tope 999.99 — insuficiente para un monto en
pesos (ej. $50.000 de descuento). Ensanchar esa columna mezclaría semánticamente pesos y porcentajes
en el mismo campo; una columna dedicada (`descuento_general_monto`) es más clara y evita conversiones
con pérdida de precisión. Default `porcentaje` para `descuento_general_tipo` preserva el comportamiento
de todas las filas existentes sin necesidad de backfill (spec Assumptions).

**Alternatives considered**:
- *Una sola columna JSON `descuento_general` con `{tipo, valor}`*: descartado — el proyecto no usa
  este patrón en ningún otro lado para campos simples de 2 valores, y complica filtros/reportes futuros
  sobre el monto de descuento (rompería consistencia con el resto del esquema, que usa columnas
  tipadas).
- *Ensanchar `descuento_general_pct` a DECIMAL(12,2) y reusarla para ambos modos*: descartado por
  claridad semántica (una columna que a veces es % y a veces $ es más propensa a bugs de
  interpretación en reportes/exports que ya leen ese campo, ej. `AuditoriaController::exportar`,
  informes).

## R3: Validación de "monto fijo mayor al subtotal" (FR-007)

**Decisión**: regla condicional en cada FormRequest (`StoreVentaRequest`, `StorePresupuestoRequest`,
`StoreCompraRequest`, y sus `Update*`) vía `withValidator()`: cuando `descuento_general_tipo ===
'monto'` y hay ítems, se sub-suma el bruto de los ítems (`cantidad*precio - descuento de línea`) y se
rechaza si `descuento_general_monto` lo supera. Mismo patrón que ya usan estos FormRequest para otras
reglas cruzadas entre campos (ej. validación de descuento de línea existente).

**Rationale**: falla rápido, con mensaje claro, antes de llegar a `CalculoComprobante` — evita que
`min(100, ...)` de R1 enmascare el error convirtiendo silenciosamente un monto absurdo en "descuento
100%" en vez de avisar al usuario que se equivocó.

**Alternatives considered**:
- *Dejar que `CalculoComprobante` capee el pct efectivo en 100 sin avisar (comportamiento silencioso)*:
  descartado — el usuario cargaría un monto mayor al que puede aplicarse sin ningún error, violando
  FR-007 explícitamente.

## R4: Notas de Crédito/Débito — alcance del cambio

**Decisión**: NC/ND recibe las mismas columnas de persistencia (R2) y el mismo control de UI/JS, pero
**no** se le agrega recálculo server-side del `monto` final a partir de ítems + descuento general — se
mantiene la arquitectura actual donde `monto` es el valor final ya calculado en el navegador
(`resources/js/notas-credito-debito.js::recalcular()`) y enviado tal cual al backend, hoy sin persistir
el desglose de descuento general.

**Rationale**: introducir un recálculo fiscal server-side completo para NC/ND es un cambio de
arquitectura mayor (NC/ND no depende de `CalculoComprobante`, spec 044 lo dejó fuera de alcance
explícitamente) que excede el pedido puntual del usuario (agregar el toggle también ahí, con
persistencia para poder reabrir en el mismo modo). Se documenta como deuda preexistente, no
introducida ni agravada por este spec.

**Alternatives considered**:
- *Migrar NC/ND a usar `CalculoComprobante` en este mismo spec*: descartado por alcance — es un cambio
  de arquitectura de otro tamaño, candidato a spec propia si se decide más adelante.

## R5: Comportamiento del botón al alternar modo

**Decisión**: el botón limpia el valor del campo al cambiar de modo (confirmado en spec, Assumptions).
En JS, esto es un simple `.val('')` sobre el input y actualización del texto/ícono del botón, seguido
de un recálculo de totales con el campo vacío (equivalente a $0/0% de descuento).

**Rationale**: ya resuelto en la fase de spec — documentado acá sólo para trazabilidad del research
formal.
