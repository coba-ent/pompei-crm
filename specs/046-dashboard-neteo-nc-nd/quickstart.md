# Quickstart: Validar el neteo de NC/ND en el Dashboard

## Prerrequisitos

- CRM corriendo local (XAMPP: MySQL + `php artisan serve` o el vhost configurado), o el VPS de
  staging/producción.
- Al menos un Cliente, un Producto y una Categoría ya cargados (reutilizar datos existentes).

## Escenario 1 — Venta con NC total (Historia 1, Acceptance Scenario 1)

1. Crear una Venta de $100.000 con `fecha_emision` = hoy, categoría cualquiera.
2. Emitirle una Nota de Crédito de $100.000, `fecha_emision` = hoy (mismo período que la venta).
3. Abrir `/dashboard`, período "Mes Actual" (o "Hoy").
4. **Esperado**: "Ventas Creadas" no incluye ese $100.000 (aporta $0 neto). "Resultado" tampoco lo
   incluye. La barra de "Ventas" del mes en el gráfico de Evolución Mensual tampoco lo incluye. La
   dona de Ventas por categoría no muestra esa venta en la categoría correspondiente.

## Escenario 2 — Venta con NC parcial (Acceptance Scenario 2)

1. Crear una Venta de $100.000, emitirle una NC de $30.000 (mismo período).
2. **Esperado**: "Ventas Creadas" suma $70.000 por esa venta.

## Escenario 3 — Venta con ND (Acceptance Scenario 3)

1. Crear una Venta de $100.000, emitirle una ND de $10.000 (mismo período).
2. **Esperado**: "Ventas Creadas" suma $110.000 por esa venta.

## Escenario 4 — NC de período distinto al de la venta (Acceptance Scenario 4)

1. Crear una Venta de $100.000 con `fecha_emision` = mes pasado.
2. Emitirle una NC de $100.000 con `fecha_emision` = este mes.
3. Filtrar el Dashboard por "Mes Actual".
4. **Esperado**: "Ventas Creadas" del mes actual resta esos $100.000 (queda negativo respecto de
   otras ventas del mes, o en $0/negativo si no hay más ventas — sin piso $0 en este caso, ver
   research.md Decisión 1), aunque la venta original no pertenezca a este período. Al filtrar por
   "Mes Anterior" (el mes de la venta), "Ventas Creadas" de ese mes sigue mostrando el bruto de
   $100.000 sin descontar la NC (la NC pertenece al mes actual, no al anterior).

## Escenario 5 — Caso real (venta reconstruida en VPS)

1. En el VPS de producción, abrir `/dashboard` filtrado por "Mes Actual" (agosto 2026).
2. **Esperado**: "Ventas Creadas" y "Resultado" no incluyen el monto de la venta B 0009-00000001
   ($307.569,76), anulada al 100% por su NC (ver memoria de proyecto
   `vps-factura-prueba-real-no-borrar`).

## Escenario 6 — Simetría en Compras (Historia 2)

1. Crear una Compra de $50.000, emitirle una NC de $50.000 (mismo período).
2. **Esperado**: el total de "Compras" del panel de Totales del Período no incluye esos $50.000.
3. Repetir con una ND de $5.000 sobre una Compra de $50.000 → total de Compras suma $55.000.

## Escenario 7 — Filtro "Hoy" (Historia 4)

1. Abrir el selector de período del Dashboard.
2. **Esperado**: aparece la opción "Hoy" junto a las 4 existentes.
3. Seleccionarla con operaciones cargadas hoy y en otras fechas.
4. **Esperado**: KPIs, Totales y donas recalculan sólo con operaciones de hoy; el gráfico mensual y
   el aging de Cta Cte no cambian; la variación % de cada KPI se calcula contra "Ayer".

## Escenario 8 — Regresión sin notas (SC-005)

1. Con datos que no tengan ninguna NC/ND cargada, comparar los valores del Dashboard antes y
   después de este cambio (o contra un ambiente sin el fix).
2. **Esperado**: valores idénticos — el cambio no debe alterar el caso sin notas.

## Verificación automatizada

Correr el test Feature nuevo (ver plan.md → `tests/Feature/DashboardNeteoNotasTest.php`):

```bash
php artisan test --filter=DashboardNeteoNotasTest
```
