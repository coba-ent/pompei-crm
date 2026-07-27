# Research — Módulo Ingresos (Presupuestos · Ventas · Otros Ingresos)

Fase 0. Decisiones técnicas previas al diseño. Sin `NEEDS CLARIFICATION` (alcance acordado con el
usuario: 3 pantallas, sin Abonos; facturación sin emisión real; medios de cobro = Tesorería spec 007).

## 1. Presupuesto y Venta: entidades espejo con cálculo compartido

- **Decisión**: `presupuestos` y `ventas` son tablas separadas con estructura casi idéntica (cliente,
  ítems, conceptos extra, descuento, etiquetas, notas). La lógica de totales vive en un servicio puro
  `Services/Ingresos/CalculoComprobante` que recibe ítems + descuento + conceptos y devuelve subtotales/
  IVA/total, usado por ambos.
- **Rationale**: el relevamiento (§informe 2.5/3, obs. 8) confirma que comparten el formulario; duplicar
  el cálculo invitaría a divergencias en dinero (Principio IV). Tablas separadas (no una sola con
  `es_venta`) porque Venta tiene columnas propias (comprobante, cobros, "creada desde") y ciclos de vida
  distintos.
- **Alternativas descartadas**: (a) una sola tabla polimórfica comprobante — mezcla estados incompatibles;
  (b) copiar el cálculo en cada controlador — frágil.

## 2. Totales de venta derivados (A Cobrar / Cobrado / estado)

- **Decisión**: `Cobrado = Σ cobros`; `A Cobrar = Total + Σ ND − Σ NC − Cobrado`; estado del cobro
  (Sin cobrar / Parcial / Cobrada) derivado. No se almacenan como columnas mutables; se calculan por
  agregación (o accesores con `withSum`).
- **Rationale**: SC-003 exige que A Cobrar siempre cuadre con cualquier combinación de cobros/notas. Un
  valor guardado se desincroniza al editar/borrar un cobro o nota. Mismo criterio "derivar, no guardar"
  que Tesorería.
- **Alternativas descartadas**: columnas `cobrado`/`a_cobrar` con Observer — más superficie de bug; se
  acepta guardar un `total` snapshot del comprobante (los ítems sí se congelan al emitir), pero los
  agregados de cobro se derivan.

## 3. Integración con Tesorería (el punto crítico)

- **Decisión**: un `Cobro` (de venta) y un `OtroIngreso` no-pendiente generan un movimiento de tesorería
  llamando a `Tesoreria::registrarMovimiento($cuenta, +$monto, 'cobro', $origen=$cobro, ...)` dentro de
  una `DB::transaction()`. Este llamado se centraliza en `Services/Ingresos/Cobranzas`. El
  `movimientos_tesoreria.origen` polimórfico apunta al `Cobro`/`OtroIngreso`.
- **Rationale**: FR-012/FR-021, SC-002. Un único punto de integración testeable y reversible. Respeta
  FR-030 de la spec 007 (Tesorería no conoce a Ingresos; Ingresos llama la API pública).
- **Alternativas descartadas**: escribir directo en `movimientos_tesoreria` desde el controlador —
  saltea la regla de partida/único-punto y duplica lógica.

## 4. Reversión ante soft delete (sin saldo fantasma)

- **Decisión**: `Venta` y `Cobro` usan `SoftDeletes`. Un `VentaObserver` (o método en `Cobranzas`)
  soft-deletea los cobros de la venta y **soft-deletea/anula** sus movimientos de tesorería asociados
  (por `origen`), de modo que el saldo de la cuenta vuelve a su valor previo. Todo en transacción.
- **Rationale**: SC-005 (0 saldos fantasma) + Principio III (soft delete contable). Como el saldo de
  Tesorería es derivado (`SUM(monto)` de movimientos no borrados), soft-deletear el movimiento lo
  excluye automáticamente del saldo — no hace falta "restar" a mano.
- **Alternativas descartadas**: borrado físico — viola principio III; movimiento compensatorio inverso —
  ensucia el ledger con asientos espejo.

## 5. Comprobante sin ARCA (tipo/N° como dato)

- **Decisión**: `ventas.tipo_comprobante` (enum A/B/C/E) y `ventas.nro_comprobante` (string, secuencia
  interna simple por tipo, ej. `0001-00000003`). El documento imprimible lleva el watermark "NO VÁLIDO
  COMO FACTURA". No hay validación de condición de IVA ni CAE en esta spec.
- **Rationale**: decisión explícita del usuario; espeja el comportamiento real de la cuenta de prueba de
  Contagram. El modelo queda listo para que Facturación Electrónica agregue CAE/estado fiscal sin
  rehacer columnas.
- **Alternativas descartadas**: exigir condición de IVA/derivar el tipo ahora — pertenece a la spec de
  ARCA (Principio III se cumple allí); forzarlo acá bloquearía ventas sin aportar valor todavía.

## 6. Formularios de página completa (excepción documentada al patrón modal)

- **Decisión**: "Nuevo Presupuesto" y "Nueva Venta" son **páginas completas** (no modales), fieles al
  relevamiento (§2.5/3.1). El resto de operaciones (Cobranza, NC/ND, Otro Ingreso, crear categoría/
  etiqueta) sí son modales AJAX. Es la misma excepción ya aceptada para Importar Datos (spec 006).
- **Rationale**: son documentos de carga extensos (tabla de conceptos dinámica, dos columnas); un modal
  no da el espacio. El relevamiento lo confirma. CLAUDE.md permite la excepción documentada.
- **Alternativas descartadas**: forzar modal gigante — mala UX y diverge del relevamiento.

## 7. Cálculo en vivo del formulario (frontend)

- **Decisión**: el recálculo de subtotales/descuento/IVA/total mientras se cargan ítems se hace en JS
  (presupuestos.js/ventas.js) para feedback inmediato, pero la **fuente de verdad** al guardar es el
  servidor (`CalculoComprobante`) — el back recalcula y no confía en los totales del cliente.
- **Rationale**: UX fluida + integridad (nunca se persiste un total calculado sólo en el navegador).
- **Alternativas descartadas**: sólo servidor (round-trip por cada cambio) — lento; sólo cliente —
  inseguro.

## 8. Idempotencia del guardado (evitar duplicado por doble clic)

- **Decisión**: token de submit único por formulario (campo oculto + verificación en el store, o
  deshabilitar el botón al primer submit y `POST` idempotente por token). Evita la race condition que el
  relevamiento detectó en Contagram real (§2.5, hallazgo).
- **Rationale**: FR-008, SC-007 (0 duplicados).
- **Alternativas descartadas**: sólo deshabilitar el botón en JS — insuficiente ante doble submit real/
  reintento; se refuerza en backend.

## 9. NC/ND que afecta stock

- **Decisión**: el wizard NC/ND, cuando "¿Afecta Stock? = Sí", genera movimientos de stock sobre los
  productos incluidos vía el `StockService`/`movimientos_stock` ya existente (specs 002/003). NC repone
  stock (entrada), ND lo descuenta según corresponda al ajuste.
- **Rationale**: FR-024; reutiliza la infraestructura de stock existente en vez de duplicarla.
- **Alternativas descartadas**: tocar `stocks` directo — saltea el histórico de movimientos.

## 10. Otros Ingresos "pendiente"

- **Decisión**: `otros_ingresos.pendiente` (bool). Si `pendiente=true`, NO se llama a Tesorería (no hay
  movimiento) hasta conciliar; al conciliar (editar quitando pendiente) se genera el movimiento.
- **Rationale**: FR-021, SC-006 (0 impactos prematuros). Coherente con el informe de Tesorería, que
  excluye pendientes del flujo.
- **Alternativas descartadas**: crear el movimiento y marcarlo pendiente — ensucia el saldo.

## 11. Frontend — reglas obligatorias CLAUDE.md

- **Decisión**: DataTables server-side en los 3 listados; Select2 para cliente/producto/categoría/
  cuenta-de-cobro (con `ajax` para catálogos grandes de producto/cliente); Toastr; PDFs (detalle de
  venta, presupuesto, ticket) en el modal PDF compartido (`window.AppPdf.abrir`, fallback `window.open`).
  Cobranza, NC/ND y Otro Ingreso por modal AJAX. Referencia: `resources/js/productos.js` y el modal PDF
  de la referencia histórica de presupuestos.
- **Rationale**: reglas innegociables del proyecto.
- **Alternativas descartadas**: ninguna.
