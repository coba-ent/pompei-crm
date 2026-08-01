# Research: Cuenta Corriente Clientes

## R1 — Extender `CuentaCorriente::aging()` en vez de escribir un servicio nuevo

**Decision**: Agregar `CuentaCorriente::porCliente(string $tipo, ?Carbon $fecha): \Illuminate\Support\Collection` que reutiliza el mismo bucketing por antigüedad (`a_vencer`/`vencido` con subdivisión 0-30/31-60/61-90/+90) que `aging()`, pero acumulando por `cliente_id` en vez de en un único total. El `aging()` global existente queda intacto (lo sigue usando el Dashboard, spec 010) — `porCliente()` es la pieza nueva que faltaba.

**Rationale**: `aging()` ya recorre `Venta::with(['cobros', 'notasCreditoDebito'])->get()` y calcula `aCobrar()` por documento — el único cambio real es la clave de acumulación (global vs. por cliente). Reescribir desde cero duplicaría la lógica de buckets (los mismos 6 buckets, la misma regla de "vencido si `fecha_vto_cobro` < hoy") y arriesgaría que las dos vistas (Dashboard vs. esta pantalla) diverjan con el tiempo — justo el problema que SC-003 quiere evitar **entre esta pantalla y el Dashboard** (ambos comparten esta misma fuente de cálculo). Importante: esto NO garantiza coincidencia con el "Total A Cobrar" de Tesorería, que se calcula por un camino contable independiente (research.md R6) — ese chequeo queda como reconciliación manual, no como invariante de código.

**Alternatives considered**: Calcular el aging por cliente con una query SQL agregada (`CASE WHEN` por bucket + `GROUP BY cliente_id`) directamente en el controller. Se descarta por ahora: el volumen de Ventas es manejable (mismo orden de magnitud que ya maneja `aging()` hoy sin problema de performance reportado), y mantener el cálculo en un solo lugar (el servicio) es más importante que la micro-optimización de mover el trabajo a SQL. Si el volumen crece, es un refactor interno del servicio sin cambiar el contrato de `porCliente()`.

## R2 — "Movimientos" como UNION de Ventas + Cobros + Notas, servido con `DataTables::of()`

**Decision**: Construir la query de "Movimientos" como una UNION (vía `DB::query()->fromSub(...)`, mismo mecanismo que `InformeStockController::baseQuery()` ya usa para su ventana SQL) de tres SELECT con la misma forma de columnas (`id`, `fecha_emision`, `cliente_id`, `operacion`, `categoria`, `total_venta`, `cobrado`, `a_cobrar`, `nro_comprobante`, `medio_cobro`, `descripcion`), una por cada uno de Venta/Cobro/NotaCreditoDebito, con columnas no aplicables en `NULL`. La query resultante se sirve con `DataTables::of($query)` para filtros/orden/paginación server-side.

**Rationale**: Es el mismo patrón ya validado en el proyecto para "listados combinados con columnas calculadas" (Informe de Stock). Evita traer todo a PHP y paginar en memoria (lo que sí hacía el servicio `aging()` para su caso, aceptable ahí por ser un agregado, pero no aceptable acá porque "Movimientos" es un historial que puede crecer sin límite por cliente).

**Alternatives considered**: Traer los 3 modelos por separado con Eloquent, mezclarlos en una Collection PHP, ordenar/paginar manualmwith. Se descarta: no server-side real (rompe la regla de diseño obligatoria de DataTables con AJAX/server-side processing para volúmenes de datos que crecen), y es exactamente el enfoque que el `CuentaCorrientePerformanceTest` huérfano (descartado) parecía estar tratando de prevenir.

## R3 — Punto de entrada en el sidebar: bajo "Informes"

**Decision**: Agregar "Cuenta Corriente" al submenú ya existente "Informes" (junto a "Stock"), no a "Base de Datos".

**Rationale**: `documentacion_principal_crm.md` §7 ya lista "Cuenta Corriente" dentro de la enumeración "Informes (Ventas, Compras, Cuenta Corriente, Gastos, Contador, Ranking, Reporte Final)" — es la clasificación de dominio ya acordada, y el submenú "Informes" ya existe en el sidebar actual con un solo ítem ("Stock"), lista para crecer.

**Alternatives considered**: Colgarlo de "Base de Datos" (donde están Clientes/Proveedores). Se descarta porque el documento de dominio ya la clasifica como informe, no como ficha maestra.

## R4 — Descartar el exportador/tests huérfanos; sin exportación en esta iteración

**Decision**: No se reutiliza `app/Exports/CuentaCorrienteCsvExport.php` ni los tests `CuentaCorrienteInformeTest`/`CuentaCorrienteExportTest`/`CuentaCorrientePerformanceTest`/`CuentaCorrienteServiceTest` encontrados en el commit inicial del proyecto (eliminados como parte de este spec — decisión confirmada con el usuario). Su contrato de rutas (`cuentas-corrientes.clientes.data`, columna "Saldo" plana sin aging) no coincide con la estructura real relevada en las capturas nuevas (`docs/capturas/saldos/`). No se agrega exportación CSV/PDF en esta iteración — no hay evidencia en las capturas de un botón de exportar en esta pantalla puntual (spec.md Assumptions).

**Rationale**: Regla de oro del proyecto (`CLAUDE.md`) — la estructura de pantalla tiene que calcar la real relevada con capturas, no una versión inventada de una pasada anterior. Mantener esos archivos habría dejado tests rojos apuntando a un diseño distinto del que se está construyendo ahora.

**Alternatives considered**: Adaptar los tests existentes al nuevo diseño (opción presentada al usuario y descartada explícitamente a favor de empezar de cero).

## R5 — Nomenclatura de rutas

**Decision**: `informes.cuenta-corriente.index`, `.saldos.data`, `.movimientos.data` (kebab-case en la URL, dot-case en el nombre de ruta) — mismo estilo que `informes.stock.index`/`.data`/`.stats` ya usado en el proyecto.

**Rationale**: Consistencia con la convención ya establecida (Constitución V); evita introducir un segundo estilo de nombres para rutas de informes.

## R6 — Por qué el Total de esta pantalla puede NO coincidir con el "Total A Cobrar" de Tesorería (hallazgo de `/speckit-analyze`)

**Decision**: Documentar explícitamente que son dos cálculos independientes y no prometer coincidencia exacta entre ambos (spec.md SC-003 ajustado).

**Rationale**: `TesoreriaController::saldosData()` → `Tesoreria::saldos()` calcula "A Cobrar" como el saldo de una **cuenta de tesorería propia** (`CuentaTesoreria` con `tipo = 'a_cobrar'`, balance de `movimientos_tesoreria` vía `CuentaTesoreria::saldoA()`), que se actualiza cada vez que algún flujo llama a `Tesoreria::registrarMovimiento()`. Esta feature, en cambio, deriva el total **directamente de `Venta::aCobrar()`** (Total + ND − NC − Cobrado), sin pasar por `movimientos_tesoreria`. No existe hoy ningún test que ate ambos cálculos, y no hay garantía de que todo evento que afecta `aCobrar()` (p. ej. una Nota de Crédito) tenga su espejo exacto como movimiento de tesorería. Prometer coincidencia exacta como Success Criteria testeado sería una afirmación no verificada — se deja como chequeo manual informativo en `quickstart.md`, y como una posible brecha de reconciliación preexistente a investigar aparte si se detecta diferencia (fuera de alcance de este spec, que es de sólo lectura).

**Alternatives considered**: Reescribir esta pantalla para leer el total desde `CuentaTesoreria` (tipo `a_cobrar`) en vez de `CuentaCorriente`. Se descarta: perdería el aging por antigüedad (Tesorería no lo calcula, sólo un saldo plano) y el detalle por documento que "Movimientos" necesita.
