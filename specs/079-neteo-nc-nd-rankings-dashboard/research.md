# Research: Neteo de NC/ND en Rankings del Dashboard

## Decisión 1: Criterio de neteo a reutilizar

**Decisión**: Extender exactamente el criterio de `DashboardController::montoNetoQuery()` (spec 046,
revisión 18/08/2026): **sin piso en $0**, sin techo para ND, y la NC/ND se imputa al período de la
fecha de la Venta que ajusta (no a la fecha de emisión de la propia nota). El cálculo tiene dos
componentes, igual que el método existente:
- Componente 1: ventas del propio período ± sus notas cuya `fecha_emision` también cae en el período.
- Componente 2: notas cuya `fecha_emision` cae en el período pero cuya venta base cayó en otro
  período (ajuste "suelto").

**Rationale**: El usuario pidió originalmente un criterio con piso en $0, pero ese criterio fue
descartado explícitamente el 18/08/2026 para KPIs/Totales/Donas tras verificar contra Contagram real
(caso compra 2424 de Pompei SRL, ver comentario en `DashboardController.php:376-397`). Aplicar un
criterio distinto a los Rankings generaría una inconsistencia nueva dentro del propio Dashboard
(SC-001 exige que el total del Ranking de Clientes concilie centavo a centavo con el total de KPIs).
Confirmado con el usuario en `/speckit-clarify` (ver spec.md § Clarifications).

**Alternativas consideradas**:
- Piso en $0 por cliente/producto (lo pedido originalmente): descartada por la inconsistencia con
  KPIs/Totales y por replicar un bug ya corregido.
- Piso en $0 sólo para Productos, sin piso para Clientes (híbrido): descartada, no hay ninguna razón
  de negocio para tratar cantidad de stock distinto que monto — ambos son la misma naturaleza de
  cálculo (agregado neteado por dimensión).

## Decisión 2: Nivel de agregación (cabecera vs. ítem)

**Decisión**: El Ranking de Clientes se neteA a nivel de cabecera de NC/ND (`notas_credito_debito.monto`,
igual que `montoNetoQuery()`, agrupado por `cliente_id` de la Venta de origen). El Ranking de Productos
se netea a nivel de línea (`nota_credito_debito_items.cantidad`, agrupado por `producto_id`), porque
sólo ahí existe el desglose por producto.

**Rationale**: `notas_credito_debito` no tiene `cliente_id` propio — se obtiene siempre vía join con
`ventas.cliente_id` de la venta que la nota ajusta (`venta_id`). Replica el join que ya usa
`AjustesPendientesNotaCreditoDebito::pendiente()`. `nota_credito_debito_items.producto_id` sí existe
(confirmado en `database/migrations/2026_07_30_060006_create_notas_credito_debito_tables.php`), así
que el Ranking de Productos puede resolverse sin tocar cabecera.

**Alternativas consideradas**:
- Netear Productos a nivel de cabecera prorrateando por línea de la venta original: descartada,
  innecesariamente compleja cuando el dato exacto (`producto_id`, `cantidad`) ya está en el ítem de
  la nota.

## Decisión 3: Notas de Crédito/Débito sin ítems desglosados

**Decisión**: Una NC/ND sin ítems (nota global) afecta el Ranking de Clientes (vía su `monto` de
cabecera) pero no afecta el Ranking de Productos (no hay `producto_id` a qué imputarle la cantidad).

**Rationale**: Mismo comportamiento ya aceptado para el Informe de Compras (spec 067) ante el mismo
caso. No inventa un criterio nuevo, y matemáticamente es correcto: si no se sabe qué producto se
devolvió, no se le puede restar cantidad a ningún producto puntual sin adivinar.

**Alternativas consideradas**:
- Prorratear la nota global entre todos los productos de la venta de origen: descartada, produce
  cantidades fraccionarias artificiales que no representan una devolución real de stock.

## Decisión 4: Recomputar Top 10 sobre el conjunto neteado

**Decisión**: El corte a `TOP_N_RANKING = 10` se aplica **después** de netear todos los
clientes/productos del período, no antes. Un cliente/producto puede entrar o salir del Top 10 por
efecto del neteo.

**Rationale**: Es la única forma matemáticamente correcta de que el ranking refleje "los 10 más
grandes en términos netos reales" — cortar antes del neteo daría un Top 10 basado en un criterio
(bruto) distinto al que se muestra (neto), pudiendo dejar afuera a un cliente que en neto sí entraría.

**Alternativas consideradas**:
- Cortar a Top 10 sobre el bruto y sólo ajustar esos 10 montos: descartada por el motivo anterior.

## Decisión 5: Alcance de performance

**Decisión**: Se resuelve con 2 queries agregadas adicionales por ranking (mismo patrón de 2
componentes que `montoNetoQuery()`), sin loop por fila ni N+1. Se opera sobre el mismo rango de
fechas que ya usa `rankings()` hoy.

**Rationale**: Consistencia con el patrón ya probado en producción para KPIs/Totales; evita
introducir un patrón de performance nuevo y no validado.
