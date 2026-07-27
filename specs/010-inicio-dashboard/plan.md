# Implementation Plan: Módulo Inicio (Dashboard)

**Branch**: `010-inicio-dashboard` | **Date**: 2026-07-25 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `specs/010-inicio-dashboard/spec.md`

## Summary

Pantalla de aterrizaje `/dashboard` que agrega, en modo **sólo lectura**, datos ya existentes de
Ventas/Presupuestos/Otros Ingresos (spec 008), Compras/Gastos (spec 009) y Tesorería (spec 007): 4 KPIs
con variación %, panel de totales + gráfico apilado de 12 meses, resumen de Tesorería (reusa
`Tesoreria::saldos()`), panel de Cuentas a Cobrar/Pagar con aging (servicio nuevo), donas por categoría
y rankings de Clientes/Productos, con selector de período (Última Semana/Mes Actual/Mes Anterior/Año
Actual).

Enfoque técnico: **un solo controlador de agregación** (`DashboardController`), sin tablas nuevas
propias salvo el servicio de aging. Se confirmó por lectura de código que `CuentaTesoreria` tipo
`a_cobrar`/`a_pagar` (ej. "Cheque de Terceros", "AMEX") son medios de pago con clearing pendiente —
**no** el saldo de deuda por Cliente/Proveedor — así que el aging de Cuenta Corriente es un cálculo
nuevo, no un duplicado de Tesorería. Se apoya en métodos ya derivados y testeados: `Venta::aCobrar()` +
`fecha_vto_cobro`, y `Compra::aPagar()` + `fecha_vto_pago`.

## Technical Context

**Language/Version**: PHP 8.2, Laravel 12

**Primary Dependencies**: Laravel 12, Eloquent, Blade, Chart.js (gráfico apilado + donas — sin
librería nueva de gráficos si el template NexaDash ya trae una; confirmar en Phase 0). **Depende de**
spec 007 (`Tesoreria::saldos()`, `CuentaTesoreria`, `MovimientoTesoreria`), spec 008 (`Venta::aCobrar()`,
`Venta::cobrado()`, `OtroIngreso`, `Categoria`, `Cliente`, `Producto`) y spec 009
(`Compra::aPagar()`, `Compra::pagado()`, `Gasto`, `Proveedor`).

**Storage**: MySQL (`contagram`). **Sin tablas ni migraciones nuevas** — el dashboard sólo lee. El
servicio de aging de Cuenta Corriente consulta `ventas`/`compras` existentes (campos `fecha_vto_cobro`/
`fecha_vto_pago` ya presentes), sin persistir nada nuevo.

**Testing**: PHPUnit sobre SQLite en memoria. Foco (Principio IV): cálculo de aging por bucket (A
Vencer/Vencido/0-30/31-60/61-90/+90), invariante de que el total del aging = suma de `aCobrar()`/
`aPagar()` de los documentos con saldo pendiente, cálculo de variación % con período anterior en cero
(no `NaN`/`Infinity`), agrupación "Sin categoría" cuando la categoría fue eliminada, y que el gráfico de
12 meses incluya meses sin operaciones.

**Target Platform**: Aplicación web (navegador de escritorio, `php artisan serve`).

**Project Type**: Web application monolítica Laravel (backend + Blade).

**Performance Goals**: Página de aterrizaje — debe responder rápido incluso con miles de comprobantes
históricos. Todas las agregaciones (KPIs, totales, gráfico mensual, donas, rankings) se calculan con
consultas agregadas indexadas por fecha (`SUM`/`COUNT`/`GROUP BY`), nunca cargando colecciones completas
en PHP.

**Constraints**: Single-tenant (sin `empresa_id`). Sólo lectura: ningún endpoint de este módulo crea,
edita ni elimina Ventas/Compras/Gastos/Movimientos — toda mutación sigue en su módulo de origen. El
aging de Cuentas a Cobrar/Pagar **no** se filtra por el selector de período (siempre es "a hoy"),
según FR-008 de la spec. Sin IA, sin banner de trial/suscripción (no aplican al modelo single-tenant).

**Scale/Scope**: 1 controlador nuevo (`DashboardController`, un único método `index` + endpoints AJAX
de datos parciales para no recargar toda la página al cambiar el período), 1 servicio de dominio nuevo
(`Services/Tesoreria/CuentaCorriente` — aging por Cliente/Proveedor), 1 vista + partials (KPIs, gráfico
mensual, panel tesorería, aging, donas, rankings), 1 entrada de sidebar/ruta raíz a redirigir a
`/dashboard`.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **I. Documentación de dominio como fuente de verdad**: ✅ Basado en
  `docs/informe_contagram_inicio_informes_ajustes.md §1` (capturas 163-166) y `docs/modelo_datos.md`
  (confirma que Cuenta Corriente está marcada "no implementada" — se resuelve el gap explícitamente en
  esta spec, ver Clarificaciones). Al cierre se documenta en `documentacion_principal_crm.md` el nuevo
  servicio de aging y se lo saca de la lista de "Módulos pendientes de re-relevamiento" en lo que
  respecta a este cálculo mínimo (las pantallas completas de Cta Cte siguen pendientes).
- **II. Desarrollo spec-driven**: ✅ specify → plan → tasks → analyze → implement (clarify no fue
  necesario: la spec no dejó `[NEEDS CLARIFICATION]` abiertos).
- **III. Corrección fiscal innegociable (ARCA)**: ✅ N/A directa — el dashboard no emite ni modifica
  comprobantes fiscales, sólo lee totales ya calculados por Ventas/Compras (que ya cumplen el principio
  en sus propias specs). No hay riesgo nuevo introducido.
- **IV. Testing donde hay dinero o impacto fiscal**: ✅ Tests obligatorios sobre el cálculo de aging
  (dinero real pendiente de cobro/pago) y sobre el cálculo de KPIs/Resultado (Ventas+Otros Ingresos−
  Compras−Gastos). Vistas y gráficos en sí no requieren test estricto (son de solo lectura sobre datos
  ya testeados en sus specs de origen).
- **V. Convenciones Laravel + dominio en español**: ✅ `DashboardController`, servicio en
  `Services/Tesoreria/CuentaCorriente` (vive en el namespace de Tesorería porque opera sobre el mismo
  dominio de saldos/dinero), ruta `/dashboard`, vistas en español, sin `empresa_id`.

**Resultado del gate**: PASS sin excepciones.

## Project Structure

### Documentation (this feature)

```text
specs/010-inicio-dashboard/
├── plan.md              # Este archivo
├── spec.md              # Especificación (ya creada)
├── research.md          # Fase 0 (este comando)
├── data-model.md         # Fase 1 (este comando)
├── quickstart.md         # Fase 1 (este comando)
├── contracts/            # Fase 1 (este comando)
│   └── dashboard-rutas.md
├── checklists/
│   └── requirements.md   # Checklist de calidad (ya creado)
└── tasks.md               # Fase 2 (/speckit-tasks)
```

### Source Code (repository root)

Monolito Laravel. Nuevos/modificados:

```text
app/
├── Http/Controllers/
│   └── DashboardController.php    # NUEVO: index (vista) / kpis / totales / grafico-mensual /
│                                   #        tesoreria / cuentas-corrientes / donas / rankings
│                                   #        (endpoints AJAX por bloque, parametrizados por período)
├── Services/Tesoreria/
│   └── CuentaCorriente.php        # NUEVO: aging(tipo: 'cliente'|'proveedor') → saldo agrupado +
│                                   #        buckets (a_vencer/vencido/0-30/31-60/61-90/+90)
│                                   #        reutilizable después por Informes → Cta Cte

resources/js/
├── dashboard.js                    # NUEVO: Chart.js (barras apiladas mensual + donas), tabs de
                                     #        período vía AJAX, sin recarga de página

resources/views/
├── dashboard/
│   ├── index.blade.php             # NUEVO: layout de la pantalla, incluye los partials
│   ├── _kpis.blade.php             # NUEVO: 4 tarjetas KPI
│   ├── _totales.blade.php          # NUEVO: panel de totales + barra de progreso
│   ├── _grafico-mensual.blade.php  # NUEVO: contenedor del canvas Chart.js
│   ├── _tesoreria.blade.php        # NUEVO: resumen + mini-tabla movimientos recientes
│   ├── _cuentas-corrientes.blade.php # NUEVO: bloques Cobrar/Pagar + aging
│   ├── _donas.blade.php            # NUEVO: 3 donas por categoría
│   └── _rankings.blade.php         # NUEVO: ranking Clientes / Productos

routes/web.php                      # MODIFICADO: Route::get('/', ...) pasa a redirigir a
                                     #             dashboard.index; nuevo grupo de rutas /dashboard/*
resources/views/elements/sidebar.blade.php  # MODIFICADO: ítem "Inicio" apunta a dashboard.index

tests/
└── Feature/
    ├── DashboardKpisTest.php               # NUEVO: KPIs + variación %, división por cero
    ├── DashboardCuentaCorrienteTest.php     # NUEVO: buckets de aging, exclusión de saldo cero,
    │                                        #        impacto de NC/ND
    └── DashboardGraficoMensualTest.php      # NUEVO: 12 meses siempre presentes, mes vacío en cero
```

**Structure Decision**: un único `DashboardController` con varios endpoints AJAX pequeños (uno por
bloque visual) en vez de una sola respuesta monolítica — permite que el selector de período (FR-008)
recalcule sólo KPIs/totales/gráfico/donas/rankings sin re-pedir Tesorería ni el aging (que no dependen
del período), evitando trabajo de servidor innecesario en cada cambio de tab. El aging vive en
`Services/Tesoreria/CuentaCorriente` (no en `Services/Ingresos` ni `Services/Egresos`) porque conceptualmente
es un cálculo de saldo/dinero transversal a Clientes y Proveedores, mismo criterio de ubicación que
`Services/Tesoreria/Tesoreria`, y queda listo para que una futura spec de Informes (Cta Cte
Clientes/Proveedores) lo reutilice sin duplicar lógica.

## Complexity Tracking

> No hay violaciones de la Constitución que requieran justificación en esta spec.
