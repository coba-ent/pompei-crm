# Phase 0 Research: Neteo de NC/ND en el Dashboard

## Decisión 1: Criterio de neteo por período de la nota vs. por venta individual

**Decision**: El neteo se calcula como dos componentes aditivos independientes dentro del mismo
rango de fechas `[$desde, $hasta]`:

1. Bruto: `SUM(Venta.total WHERE fecha_emision BETWEEN $desde AND $hasta)`
2. Ajuste: `SUM(NotaCreditoDebito.monto WHERE tipo='debito' AND fecha_emision BETWEEN $desde AND $hasta) - SUM(NotaCreditoDebito.monto WHERE tipo='credito' AND fecha_emision BETWEEN $desde AND $hasta)`

Resultado = Bruto + Ajuste, con **el piso de $0 (FR-007) aplicado por Venta individual antes de
agregar**, usando una subconsulta correlacionada (no dos sumas globales separadas) para poder
acotar cada fila en 0 sin perder la agrupación por período de la propia nota. Concretamente:

```sql
SELECT COALESCE(SUM(
    GREATEST(0, ventas.total
        + COALESCE((SELECT SUM(n.monto) FROM notas_credito_debito n
                     WHERE n.venta_id = ventas.id AND n.tipo = 'debito'
                       AND n.deleted_at IS NULL
                       AND n.fecha_emision BETWEEN :desde AND :hasta), 0)
        - COALESCE((SELECT SUM(n.monto) FROM notas_credito_debito n
                     WHERE n.venta_id = ventas.id AND n.tipo = 'credito'
                       AND n.deleted_at IS NULL
                       AND n.fecha_emision BETWEEN :desde AND :hasta), 0)
    )
), 0)
FROM ventas
WHERE ventas.fecha_emision BETWEEN :desde AND :hasta
```

Esto cubre el caso normal (venta y su NC en el mismo período) con el piso de $0 correctamente
aplicado. Para el caso de borde de la Historia 1 / Acceptance Scenario 4 (NC de un mes distinto al
de la venta), la nota **no aparece en absoluto** en este cálculo si la venta no está en el rango
(porque el `WHERE ventas.fecha_emision BETWEEN` filtra la fila completa) — así que se necesita un
segundo componente que capture "notas cuyo período propio cae en el rango pero cuya venta cayó en
un rango distinto":

```sql
SELECT COALESCE(SUM(CASE WHEN n.tipo = 'debito' THEN n.monto ELSE -n.monto END), 0)
FROM notas_credito_debito n
JOIN ventas ON ventas.id = n.venta_id
WHERE n.fecha_emision BETWEEN :desde AND :hasta
  AND n.deleted_at IS NULL
  AND ventas.fecha_emision NOT BETWEEN :desde AND :hasta
```

Este segundo componente **no lleva piso $0** (no hay "base" de esa venta en este período contra la
cual acotar) — aporta el ajuste crudo, consistente con Acceptance Scenario 4 de la spec ("el monto
de esa NC igual se resta del total de Ventas del período de la NC").

**Rationale**: Reconcilia dos requisitos que en el caso general (misma fecha) coinciden pero en el
caso de borde (fechas cruzadas) divergen: FR-007 (piso $0 por venta) sólo tiene sentido cuando la
venta base está en el mismo período que se está calculando; cuando no lo está, tratar la nota como
un evento independiente (igual que ya hace el aging de Cuenta Corriente con NC de períodos
distintos al vencimiento original) es el único criterio consistente disponible.

**Alternatives considered**:
- *Sólo componente 1 (ignorar notas de período cruzado)*: más simple, pero contradice
  explícitamente Acceptance Scenario 4 de la spec.
- *Sólo componente 2 (sumar todas las notas del período sin piso ni atarlas a si la venta está en
  rango)*: no permite aplicar el piso $0 de FR-007 en el caso normal (mismo período), que es el más
  frecuente.
- *Recalcular todo por fecha de venta, ignorando fecha de la nota*: es el comportamiento textual
  original de spec 010 sin este fix — descartado, es exactamente el bug que motiva esta spec.

## Decisión 2: Reutilización del patrón SQL de `CuentaCorriente::documentosParaAging()`

**Decision**: El componente 1 (subconsulta correlacionada con `GREATEST(0, ...)`) es una variante
acotada en $0 del patrón ya usado en `CuentaCorriente::documentosParaAging()` (líneas 81-82 de
`app/Services/Tesoreria/CuentaCorriente.php`), que hace la misma suma de NC/ND por
`venta_id`/`compra_id` pero sin filtrar por período de la nota (porque el aging usa el saldo total
acumulado, no un rango). Se implementa como método(s) privado(s) nuevo(s) en `DashboardController`
en lugar de extender `CuentaCorriente`, porque el criterio de "filtrar la nota por su propio rango
de fechas" es específico de este cálculo (el aging nunca filtra notas por fecha, usa el acumulado a
hoy).

**Rationale**: Mantiene consistencia de estilo (subconsultas correlacionadas vía `selectRaw`, ya
usado en el proyecto) sin acoplar `CuentaCorriente` (servicio de Tesorería) a una necesidad de
Dashboard.

**Alternatives considered**: Crear un servicio de dominio nuevo (`App\Services\Dashboard\Neteo` o
similar) — descartado por sobre-ingeniería: el alcance son 3-4 métodos privados dentro de un único
controller, no justifica una capa nueva (ver Constitution Check / Structure Decision en plan.md).

## Decisión 3: Filtro "Hoy" y su período de comparación

**Decision**: `rangoPeriodo()` agrega el caso `'hoy' => [$hoy, $hoy]`, con
`$desdeAnterior = $hastaAnterior = $hoy->copy()->subDay()` (Ayer) — no requiere cambios en la
fórmula genérica de "período anterior equivalente" (`$dias = $desde->diffInDays($hasta) + 1` ya da
`1` día para "Hoy", y el cálculo genérico de `$hastaAnterior`/`$desdeAnterior` ya produce "Ayer"
sin necesidad de un caso especial). Confirmado en clarify (sesión 2026-08-05).

**Rationale**: El método `rangoPeriodo()` ya es genérico en `$dias` — agregar `'hoy'` a
`PERIODOS_VALIDOS` y al `switch` con `$desde = $hasta = Carbon::today()` reutiliza el cálculo de
período anterior existente sin lógica especial. Verificado manualmente: `diffInDays` para el mismo
día da `0` → `$dias = 1` → `$hastaAnterior = hoy - 1 día` → `$desdeAnterior = $hastaAnterior - 0
días` = mismo día = Ayer. Correcto sin cambios adicionales.

**Alternatives considered**: Ninguna — el mecanismo existente ya resuelve el caso sin ambigüedad
una vez confirmado el criterio en clarify.
