# Implementation Plan: Neteo de Notas de Crédito/Débito en el Dashboard de Inicio

**Branch**: `046-dashboard-neteo-nc-nd` | **Date**: 2026-08-05 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/046-dashboard-neteo-nc-nd/spec.md`

## Summary

`DashboardController` (spec 010) calcula "Ventas Creadas", "Venta Promedio", "Resultado", el panel
de Totales del Período, el gráfico de Evolución Mensual y las donas de composición por categoría
sumando `Venta.total`/`Compra.total` en bruto, sin restar las Notas de Crédito ni sumar las de
Débito asociadas — sólo el aging de Cuentas a Cobrar/Pagar (`CuentaCorriente::aging()`) ya lo hace
correctamente. Esta feature agrega el neteo (simétrico Ventas/Compras) a `metricasRango()`,
`graficoMensual()` y `composicionPorCategoria()`, reutilizando el mismo patrón SQL de subconsulta
correlacionada que ya usa `CuentaCorriente::documentosParaAging()`. Además agrega la opción "Hoy"
al selector de período (`rangoPeriodo()`), comparada contra "Ayer".

## Technical Context

**Language/Version**: PHP 8.2 (Laravel 12)

**Primary Dependencies**: Eloquent ORM, `App\Models\Venta`/`Compra`/`NotaCreditoDebito`
(existentes), `App\Services\Tesoreria\CuentaCorriente` (patrón SQL de referencia, no se modifica)

**Storage**: MySQL — tablas `ventas`, `compras`, `notas_credito_debito` (existentes, sin
migraciones nuevas)

**Testing**: PHPUnit (Feature tests contra `DashboardController`, siguiendo el patrón ya usado en
`tests/Feature` para specs previas del dashboard/NC)

**Target Platform**: Servidor Linux (VPS/hosting compartido), app web Laravel Blade + AJAX

**Project Type**: Web application (monolito Laravel, sin frontend separado)

**Performance Goals**: Sin objetivo distinto al ya vigente en spec 010 — los endpoints AJAX del
dashboard deben responder en tiempos comparables a los actuales (no se introduce N+1 ni
recorridos completos de tabla adicionales; el neteo se resuelve con subconsultas SQL agregadas,
mismo criterio que `CuentaCorriente`).

**Constraints**: Ninguna regla de negocio fiscal (ARCA) involucrada — es un cálculo de reporting
interno, no afecta comprobantes ni envío a ARCA.

**Scale/Scope**: Alcance acotado a `DashboardController` (4 endpoints: `kpis`, `totales`,
`graficoMensual`, `donas`) y su método privado `rangoPeriodo()`. No toca `rankings()` (fuera de
alcance, ver spec).

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **I. Documentación de dominio como fuente de verdad**: `docs/documentacion_principal_crm.md` §8
  (Dashboard) ya documenta el aging con neteo NC/ND como precedente; se actualiza esa sección para
  reflejar que ahora KPIs/Totales/Gráfico/Donas de Ventas y Compras también netean. Se hace en el
  mismo cambio, antes de `/speckit-tasks` (regla del proyecto). ✅ Planificado.
- **II. Desarrollo spec-driven**: esta es una feature de negocio (cambia un cálculo que ve el
  usuario) → pasa por el flujo completo specify→clarify→plan→checklist→tasks→analyze, que es
  justamente lo que está en curso. ✅ Cumple.
- **III. Corrección fiscal (ARCA)**: no aplica — el dashboard es reporting interno derivado, no
  genera ni modifica comprobantes fiscales ni interactúa con WSFEv1/ARCA. ✅ N/A, sin riesgo.
- **IV. Testing donde hay dinero o impacto fiscal**: los KPIs y totales del dashboard son montos
  de dinero (aunque no fiscales) — corresponde tests Feature que verifiquen el neteo con casos de
  NC total, parcial, ND, y período distinto al de la venta/compra (los 4 escenarios de la Historia
  1 + los 2 de la Historia 2). ✅ Planificado en tasks.
- **V. Convenciones Laravel + dominio en español**: nombres de método/variable existentes ya están
  en español (`ventasCreadas`, `rangoPeriodo`, etc.) — se mantiene el idioma y el estilo del
  archivo. ✅ Cumple.

No hay violaciones que requieran justificar en Complexity Tracking.

## Project Structure

### Documentation (this feature)

```text
specs/046-dashboard-neteo-nc-nd/
├── plan.md              # This file
├── research.md          # Phase 0 output
├── data-model.md         # Phase 1 output
├── quickstart.md        # Phase 1 output
└── tasks.md             # Phase 2 output (/speckit-tasks, no creado por este comando)
```

### Source Code (repository root)

```text
app/
├── Http/Controllers/
│   └── DashboardController.php     # kpis(), totales(), graficoMensual(), donas(), rangoPeriodo(), metricasRango(), composicionPorCategoria() — se modifican
├── Models/
│   ├── Venta.php                   # totalCredito()/totalDebito() ya existentes, se reutilizan como referencia de criterio
│   ├── Compra.php                  # ídem
│   └── NotaCreditoDebito.php       # sin cambios (sólo se consulta)
└── Services/Tesoreria/
    └── CuentaCorriente.php         # sin cambios — sólo referencia de patrón SQL (documentosParaAging())

resources/views/dashboard/
├── index.blade.php                 # sin cambios estructurales (los endpoints AJAX ya alimentan estos widgets; no cambia el contrato JSON expuesto al frontend salvo agregar "hoy" como valor de período válido)
└── _periodo.blade.php              # selector de período: botones HTML estáticos con data-periodo — agrega un botón data-periodo="hoy" (mismo patrón que los 4 existentes: semana/mes_actual/mes_anterior/anio_actual)

resources/js/
└── dashboard.js                    # sin cambios — ya lee data-periodo de los botones existentes vía delegación de eventos; el botón nuevo se engancha solo

tests/Feature/
└── DashboardNeteoNotasTest.php     # nuevo — casos de neteo Venta/Compra, floor $0, período cruzado, filtro "Hoy" vs "Ayer"
```

**Structure Decision**: Monolito Laravel existente — no se agregan carpetas ni capas nuevas. Todo
el cambio vive en `DashboardController` (lógica de agregación) más un test Feature nuevo. Se
reutiliza el patrón SQL ya validado en `CuentaCorriente::documentosParaAging()` en vez de crear un
servicio de dominio nuevo, porque el alcance (4 métodos de un controller) no justifica una capa de
abstracción adicional.

## Complexity Tracking

*No violations — table omitted.*
