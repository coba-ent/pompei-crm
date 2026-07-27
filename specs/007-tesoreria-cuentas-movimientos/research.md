# Research — Módulo Tesorería (Cuentas y Movimientos)

Fase 0 del plan. Resuelve las decisiones técnicas antes del diseño. Sin `NEEDS CLARIFICATION`
pendientes (el alcance ya se acordó con el usuario antes del spec).

## 1. Saldo derivado vs. saldo almacenado

- **Decisión**: el saldo de una cuenta es **siempre derivado** por agregación SQL
  (`saldo_inicial` está modelado como el primer movimiento "Saldo Inicial", así que el saldo es
  simplemente `Σ(ingreso) − Σ(egreso)` de `movimientos_tesoreria` de la cuenta hasta la fecha de
  corte). No existe una columna `saldo_actual` mutable.
- **Rationale**: SC-003 y SC-005 exigen que el saldo coincida exactamente con el ledger y que el
  balance corrido sea consistente fila a fila. Una columna mutable se desincroniza ante ediciones/
  borrados/concurrencia (fue una causa típica de bugs de tesorería). Derivar siempre es la fuente
  única de verdad. Mismo criterio que "Stock Saldo" del Informe de Stock (spec 003), ya validado.
- **Alternativas descartadas**: (a) columna `saldo_actual` con Observer que la recalcula — frágil ante
  ediciones de movimientos históricos y ante inserciones concurrentes; (b) snapshots periódicos —
  complejidad innecesaria para el volumen del negocio.

## 2. Signo del movimiento: columna `monto` con signo vs. columnas ingreso/egreso

- **Decisión**: una sola columna `monto` **decimal con signo** (positivo = ingreso, negativo = egreso)
  + una columna `tipo` (operación). Las vistas derivan las columnas visibles "Ingreso"/"Egreso" del
  signo (`monto > 0 ? monto : null` / `monto < 0 ? -monto : null`).
- **Rationale**: simplifica el cálculo de saldo (`SUM(monto)`) y el balance corrido (`SUM(monto) OVER
  (...)`), sin `CASE`. La presentación en dos columnas es sólo de UI.
- **Alternativas descartadas**: dos columnas `ingreso`/`egreso` nullable — obliga a `COALESCE` en cada
  agregación y permite estados inválidos (ambas cargadas). Un enum `sentido` aparte — redundante con
  el signo.

## 3. Partida doble de la transferencia

- **Decisión**: una transferencia = **dos filas** en `movimientos_tesoreria` (una con `monto` negativo
  en la cuenta de salida, otra positiva en la de entrada), ambas con `tipo = movimiento_entre_cuentas`,
  vinculadas por una columna `transferencia_id` (self-group) para poder editarlas/borrarlas juntas.
  Se crean dentro de una `DB::transaction()`.
- **Rationale**: FR-016/FR-024 y el edge case "eliminar una transferencia revierte ambas patas". El
  `transferencia_id` compartido permite mostrar la contraparte en "Detalles" y borrar/editar el par
  como unidad. La transacción garantiza atomicidad (nunca media transferencia).
- **Alternativas descartadas**: una sola fila con `cuenta_origen`/`cuenta_destino` — rompe el modelo
  uniforme del ledger por cuenta (cada cuenta necesita su propia fila con su signo) y complica saldos.

## 4. Origen polimórfico para cobros/pagos/gastos futuros

- **Decisión**: `movimientos_tesoreria` tiene `origen_type` / `origen_id` (`morphTo`, nullable). Los
  movimientos nativos de Tesorería (Saldo Inicial, Movimiento entre Cuentas) tienen `origen` null; los
  que generen otros módulos apuntarán a `Cobro`, `Pago`, `Gasto`, `OtroIngreso`. El `tipo` (enum) marca
  la operación para clasificar/filtrar sin cargar el origen.
- **Rationale**: FR-030 — Tesorería no debe conocer cada módulo. El `Services/Tesoreria/Tesoreria::
  registrarMovimiento($cuenta, $monto, $tipo, $origen, ...)` es la única API que Ingresos/Egresos
  invocan. Mismo patrón polimórfico ya presente en `movimientos_stock.origen` (modelo_datos §2).
- **Alternativas descartadas**: FKs directas a cada tabla de origen (nullable múltiples) — acoplamiento
  y columnas que crecen con cada módulo nuevo.

## 5. Balance corrido (función de ventana) y su testing

- **Decisión**: la ficha/ledger calcula el balance corrido con
  `SUM(monto) OVER (PARTITION BY cuenta_tesoreria_id ORDER BY fecha, id)` — proyectado como columna
  adicional en la query server-side, igual que `InformeStockController` proyecta "Stock Saldo".
  **Testing**: la lógica de saldo (US suite) se testea con el método `Tesoreria::saldoA()` (agregación
  simple `SUM`, corre en SQLite). El balance corrido de la **ficha** se cubre con un test que reconstruye
  el acumulado en PHP y lo compara, para no depender de `OVER` en SQLite; opcionalmente un test marcado
  `@group mysql` valida el SQL real.
- **Rationale**: SQLite (usado por defecto en tests) soporta window functions desde 3.25, pero para
  evitar divergencias de dialecto en el ordering/tie-break, se testea el invariante en PHP y se deja el
  SQL de ventana para la vista. El proyecto ya asume MySQL 8 en runtime (spec 003).
- **Alternativas descartadas**: calcular el balance corrido en PHP también en la vista — no escala con
  DataTables server-side (paginación); la ventana SQL lo resuelve en la misma query paginada.

## 6. Saldo a fecha de corte (pestaña Saldos "Buscar por Fecha")

- **Decisión**: `saldoA($cuenta, $fecha)` = `SUM(monto) WHERE cuenta = ? AND fecha <= ?`. La vista de
  Saldos calcula todos los saldos con un solo `groupBy(cuenta)` filtrado por la fecha de corte (default
  hoy). Bloques A Cobrar/A Pagar/Disponible se arman agrupando por `tipo` de cuenta.
- **Rationale**: FR-012/FR-014. Una sola query agregada para toda la pantalla.
- **Alternativas descartadas**: N+1 (un query por cuenta) — innecesario.

## 7. Reglas de borrado y cuentas del sistema

- **Decisión**: `CuentaTesoreria::tieneOperaciones()` = existe algún movimiento de la cuenta cuyo `tipo`
  **no** sea `saldo_inicial` (o cualquier movimiento si se prefiere el criterio estricto — ver
  data-model). El `destroy()` del controlador bloquea si `tieneOperaciones()` o si `es_sistema`. Las
  cuentas del sistema (`es_sistema = true`) tampoco se editan. Mismo patrón que
  `Deposito::tieneOperaciones()`/`Cliente`/`Proveedor`.
- **Rationale**: FR-006/FR-007/FR-008/SC-004/SC-007. Consistencia con el resto del proyecto.
- **Alternativas descartadas**: soft-delete de la cuenta en vez de bloqueo — el relevamiento muestra
  "ocultar" como la baja lógica esperada; el borrado físico se reserva a cuentas sin historial.

## 8. Soft delete de movimientos con impacto contable

- **Decisión**: `movimientos_tesoreria` usa `SoftDeletes`. Los movimientos nativos (saldo inicial,
  transferencia) se pueden borrar de verdad desde su ficha (revirtiendo el par en la transferencia);
  los movimientos originados en documentos fiscales (cobros/pagos, cuando existan) se **soft-deletean**
  para preservar trazabilidad (principio III de la constitución).
- **Rationale**: la constitución exige soft delete en documentos con impacto contable. Modelarlo desde
  ya evita una migración futura.
- **Alternativas descartadas**: sin soft delete — violaría el principio III cuando lleguen los cobros.

## 9. Frontend — reglas obligatorias de CLAUDE.md

- **Decisión**: DataTables server-side para el ledger de la ficha y para la tabla de configuración de
  cuentas; modales Bootstrap + AJAX para alta/edición de cuenta y para la transferencia; Toastr para
  notificaciones; Select2 en los selectores de cuenta de la transferencia, mostrando el saldo actual de
  cada cuenta como parte del texto de la opción (FR-017); el "Exportar a PDF" del informe Movimientos
  usa el modal PDF compartido (`window.AppPdf.abrir`). Las secciones expandibles Cobros/Pagos del
  informe y los checkboxes "Activo" recalculan el total del lado del cliente (JS) sobre datos ya
  cargados.
- **Rationale**: son reglas innegociables del proyecto (CLAUDE.md §1-5). El patrón de referencia es
  `resources/js/productos.js` (Select2 + DataTables) y el modal PDF de presupuestos.
- **Alternativas descartadas**: ninguna — son obligatorias.

## 10. Vista de Saldos: ¿tabla estática o no?

- **Decisión**: la vista de Saldos **no** es una DataTable — es un panel de tarjetas/listas por bloque
  (A Cobrar / A Pagar / Disponible) con scroll interno, calculado en el controlador y renderizado en
  Blade (los datos son pocos: las cuentas del negocio). No viola la regla de "listados con DataTables"
  porque no es un listado tabular paginable sino un panel de KPIs/saldos (equivalente a los KPIs de
  Productos). Las **tablas** reales del módulo (ledger, configuración) sí son DataTables server-side.
- **Rationale**: fidelidad al relevamiento (capturas 144) — Saldos es un dashboard, no una grilla.
- **Alternativas descartadas**: forzar DataTables sobre Saldos — no aporta y diverge del diseño real.
