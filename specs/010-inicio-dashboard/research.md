# Research: Módulo Inicio (Dashboard)

## 1. Librería de gráficos (barras apiladas + donas)

**Decision**: ApexCharts (`vendor/apexchart/apexchart.js`), vía la entrada de configuración
`config('dz.pagelevel.home')` que **ya existe** en `config/dz.php` (línea ~44) — es la configuración
original del template NexaDash para su dashboard demo, nunca usada porque la ruta raíz (`name('home')`)
apunta hoy a `ClienteController::index`. Esa entrada ya incluye `apexchart.js`, `toastr`,
`bootstrap-daterangepicker` y `moment` — exactamente lo necesario para gráficos + selector de período.

**Rationale**: cero configuración nueva de assets; se sigue el patrón exacto de "un `$CurrentPage` por
controlador" ya usado en los 9 módulos construidos (`ClienteController` usa `'clientes'`,
`VentaController` usa `'ventas'`, etc. — ver `app/Http/Controllers/*.php`). Se reasigna el key `home`
al nuevo `DashboardController` y se libera la ruta raíz para redirigir a `/dashboard`.

**Alternatives considered**: Chart.js (`vendor/chart.js/Chart.bundle.min.js`, también bundleado) —
descartado porque ApexCharts tiene mejor soporte nativo para donas con leyenda de porcentaje y barras
apiladas con tooltip por segmento, y es el que el template ya asoció al key `home`.

## 2. Servicio de cálculo de aging de Cuenta Corriente

**Decision**: nuevo servicio `Services/Tesoreria/CuentaCorriente`, con un único método público
`aging(string $tipo, ?Carbon $fecha = null): array` (`$tipo` = `cliente` o `proveedor`). Reutiliza
métodos ya derivados y testeados:
- Clientes: `Venta::aCobrar()` (spec 008) + columna `fecha_vto_cobro`.
- Proveedores: `Compra::aPagar()` (spec 009) + columna `fecha_vto_pago`.

**Rationale**: se confirmó por lectura de código (`app/Models/Venta.php`, `app/Models/Compra.php`,
`database/seeders/CuentasTesoreriaSeeder.php`) que las cuentas de Tesorería tipo `a_cobrar`/`a_pagar`
(ej. "Cheque de Terceros", "AMEX", "VISA") son **medios de pago con clearing pendiente**, no el saldo de
deuda por Cliente/Proveedor — no hay ningún cálculo existente que agregue el aging pedido por el
informe fuente (§1.4). Construirlo como servicio de dominio (no como método privado del controlador)
lo deja reutilizable por una futura spec de Informes (Cuenta Corriente Clientes/Proveedores,
informe §2.3-2.4), consistente con el patrón "derivar, no guardar" ya usado en `Venta`/`Compra`/
`Tesoreria`.

**Alternatives considered**:
- Persistir el aging en una tabla materializada — descartado: violaría el patrón "derivar, no
  guardar" ya establecido (mismo criterio que `a_cobrar`/`a_pagar` de Venta/Compra), y el aging
  cambia con cada Cobro/Pago/NC/ND sin evento propio que lo invalide.
- Extender `Tesoreria::saldos()` para incluir este cálculo — descartado: mezclaría dos conceptos
  distintos (medios de pago con clearing vs. deuda comercial por Cliente/Proveedor) bajo el mismo
  método, dificultando el mantenimiento futuro.

## 3. Estrategia de actualización por período (FR-008)

**Decision**: `DashboardController` expone endpoints AJAX independientes por bloque
(`kpis`, `totales`, `grafico-mensual`, `donas`, `rankings`), todos aceptando `?periodo=`
(`semana|mes_actual|mes_anterior|anio_actual`). Los bloques que NO dependen de período (Tesorería,
Cuentas a Cobrar/Pagar) se cargan una sola vez en `index()` y no se re-piden al cambiar de tab.

**Rationale**: evita recalcular Tesorería/aging (costoso y sin relación con el período) en cada cambio
de tab; sigue el patrón "sin recarga de página, AJAX" de CLAUDE.md sin necesitar traer toda la página
de nuevo.

**Alternatives considered**: una sola respuesta JSON con todo el dashboard por período — descartado
por desperdiciar cómputo de servidor en bloques que nunca cambian con el período.

## 4. Resolución de "NEEDS CLARIFICATION" del Technical Context

Ninguno pendiente: todos los campos del Technical Context se resolvieron por lectura directa de
código existente (`Venta`, `Compra`, `Gasto`, `Tesoreria`, `config/dz.php`) sin necesidad de decisiones
de producto adicionales.
