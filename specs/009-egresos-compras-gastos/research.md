# Research — Módulo Egresos (Compras · Gastos)

Fase 0. Decisiones técnicas previas al diseño. Sin `NEEDS CLARIFICATION` (alcance acordado con el
usuario: 2 pantallas, Compras espejo de Ventas, facturación sin emisión real, medios de pago =
Tesorería spec 007; estado de Compra siempre derivado — ver Clarifications de spec.md).

## 1. Compra como espejo de Venta, reutilizando `CalculoComprobante`

- **Decisión**: `compras` es una tabla separada de `ventas` (no una columna `es_compra` en la misma
  tabla), con estructura casi idéntica (proveedor en vez de cliente, ítems, conceptos extra, notas). El
  cálculo de subtotales/IVA/total reutiliza el mismo servicio puro `Services/Ingresos/CalculoComprobante`
  ya construido en spec 008, con un único ajuste: acepta ítems cuyo `iva_pct` sea `null` ("Elegir" sin
  preseleccionar) sin forzar un default, a diferencia de Venta que autocompleta `21`.
- **Rationale**: el relevamiento (informe_contagram_egresos.md §1, "Compras es el espejo de Ventas")
  confirma la fidelidad estructural exigida por CLAUDE.md; reutilizar el servicio evita duplicar lógica
  de dinero (Principio IV) y hereda sus tests ya existentes. Tablas separadas porque Compra tiene
  columnas propias (Contador/mes de imputación IVA Compras, Pagos en vez de Cobros) y un ciclo de vida
  distinto.
- **Alternativas descartadas**: (a) columna `es_compra` en `ventas` — mezclaría dos documentos fiscales
  con reglas de comprobante distintas (comprador vs. vendedor); (b) copiar el cálculo en `CompraController`
  — divergiría de Venta con el tiempo.

## 2. IVA sin preseleccionar en ítems de Compra

- **Decisión**: `compra_items.iva_pct` es `string(12)` **nullable, sin default** (queda en "Elegir"),
  mientras que `venta_items.iva_pct`/`presupuesto_items.iva_pct` defaultean a `21`. Mientras `iva_pct`
  sea `null`, el panel de totales muestra "Importe Neto No Gravado"; al elegirse, pasa a "Importe Neto
  Gravado".
- **Rationale**: hallazgo explícito del relevamiento (informe §2.4, "diferencia clave frente a Ventas").
  No forzar un default respeta el comportamiento real observado en la cuenta de prueba.
- **Alternativas descartadas**: defaultear a `21` como Venta — contradice directamente la captura [128]
  del informe (regla de oro: fidelidad estructural).

## 3. Totales de Compra derivados (A Pagar / Pagado / estado) — Clarifications

- **Decisión**: `Pagado = Σ pagos`; `A Pagar = Total + Σ ND − Σ NC − Pagado`; el estado de pago (A Pagar
  / Pagado) es **siempre derivado** de esos montos — no existe una columna `estado` con override manual
  (resuelto en `spec.md` → Clarifications, Session 2026-07-25). La "flecha desplegable" que el informe
  describe junto al badge es un filtro rápido de UI sobre el estado derivado, no un campo persistido.
- **Rationale**: evita que un estado forzado a mano se desincronice de los pagos reales — mismo criterio
  "derivar, no guardar" que Venta (spec 008, research §2) y Tesorería.
- **Alternativas descartadas**: columna `estado` editable con Observer de sincronización — más superficie
  de bug para replicar un comportamiento que, en la práctica, ya está cubierto por el cálculo derivado.

## 4. Integración con Tesorería (el punto crítico) — servicio `Pagos`

- **Decisión**: un `Pago` (de compra) y un `Gasto` no-pendiente generan un movimiento de tesorería
  llamando a `Tesoreria::registrarMovimiento($cuenta, -$monto, 'pago'|'gasto', $origen, ...)` dentro de
  una `DB::transaction()`. Se centraliza en un servicio nuevo `Services/Egresos/Pagos`, análogo exacto a
  `Services/Ingresos/Cobranzas` (spec 008) pero con signo de egreso.
- **Rationale**: FR-006/FR-015, SC-002. Único punto de integración testeable y reversible, igual patrón
  que Ingresos; respeta que Tesorería no conoce a Egresos (API pública `registrarMovimiento`).
- **Alternativas descartadas**: escribir directo en `movimientos_tesoreria` desde los controladores —
  saltea la regla de único-punto y duplica lógica ya resuelta en spec 008.

## 5. Reversión ante soft delete de una Compra pagada

- **Decisión**: `Compra` y `Pago` usan `SoftDeletes`. Un `CompraObserver` (análogo a `VentaObserver`)
  soft-deletea los pagos de la compra y soft-deletea/anula sus movimientos de tesorería asociados (por
  `origen`), de modo que el saldo de la cuenta vuelve a su valor previo. Todo en transacción.
- **Rationale**: SC-004 (0 saldos fantasma) + Principio III (soft delete contable). El saldo de
  Tesorería es derivado (`SUM(monto)` de movimientos no borrados), así que soft-deletear el movimiento
  lo excluye automáticamente.
- **Alternativas descartadas**: borrado físico — viola Principio III; movimiento compensatorio inverso —
  ensucia el ledger.

## 6. Gasto: documento atómico, con SoftDeletes pero sin Observer propio

- **Decisión**: `Gasto` **sí usa `SoftDeletes`** (Principio III de la Constitución nombra "gastos"
  explícitamente entre los documentos con impacto contable que lo exigen — no es opcional). Lo que
  **no** tiene es un Observer dedicado: es un documento "todo o nada" (sin pagos parciales, sin NC/ND,
  sin ficha de detalle), así que eliminarlo revierte su único movimiento de tesorería asociado
  directamente en `GastoController::destroy()` (que hace `$gasto->delete()` — soft delete — y anula el
  movimiento en la misma transacción), igual criterio que ya usa Tesorería para sus propios movimientos
  nativos (spec 007, Saldo Inicial/Movimiento entre Cuentas, que tampoco usan Observer).
- **Rationale**: un Observer agrega indirección sin beneficio para un caso 1:1 (un gasto ↔ un
  movimiento). Reservar el patrón Observer para documentos con múltiples movimientos vinculados
  (Compra/Venta) mantiene el código proporcional a su complejidad real — pero el soft delete en sí es
  no-negociable (Constitución).
- **Alternativas descartadas**: `GastoObserver` calcado de `CompraObserver` — sobre-ingeniería para un
  caso sin cadena de documentos dependientes; **borrado físico** — descartado de plano, violaría el
  Principio III.

## 7. Categorías de Gasto: árbol propio sobre la tabla genérica `categorias`

- **Decisión**: `gastos.categoria_id` referencia `categorias` con `tipo=gasto` y usa
  `categoria_padre_id` (ya soportado por el modelo, §2 de `modelo_datos.md`) para la jerarquía
  Categoría→Subcategoría. Es una taxonomía independiente del árbol `tipo=compra` de Proveedores, aunque
  comparten la misma tabla física.
- **Rationale**: el relevamiento (informe §3.4, §4) confirma que son dos catálogos distintos ("Gastos
  usa categorías propias, independientes del árbol de Categorías de Compras"); el modelo `categorias` ya
  contemplaba el valor `gasto` en su enum `tipo` desde antes (modelo_datos.md §2), evitando crear una
  tabla nueva.
- **Alternativas descartadas**: tabla `categorias_gasto` dedicada — redundante, el mecanismo genérico ya
  alcanza.

## 8. Retenciones desde Compras (reutilización de `retenciones`, spec 008)

- **Decisión**: `retenciones.pago_id` (documentada en spec 008/modelo_datos.md §5, tabla creada recién en
  esta spec porque no había ningún flujo que la poblara) se llena desde el modal "Nueva Retención" del
  detalle de Compra. Exactamente uno de `cobro_id`/`pago_id` debe estar seteado.
- **Rationale**: el informe (§2.5, hallazgo relevante) confirma que las retenciones se materializan del
  lado de Compras/Pagos a proveedores, no en Ventas/Cobranzas. Reutilizar la tabla ya diseñada evita
  divergencia de modelo entre Ingresos y Egresos.
- **Alternativas descartadas**: tabla `retenciones_compra` separada — spec 008 ya diseñó una tabla única
  con FK dual, exactamente para este caso.

## 9. Comprobante de Compra sin ARCA (tipo/N° como dato)

- **Decisión**: `compras.tipo_comprobante` (string) y `compras.nro_comprobante` (string, secuencia
  interna simple). El documento imprimible lleva el watermark "NO VÁLIDO COMO FACTURA", igual que Venta.
- **Rationale**: decisión ya tomada y validada en spec 008; se replica sin reabrir la discusión.
- **Alternativas descartadas**: ninguna nueva — mismo criterio que Venta.

## 10. Formulario de Compra: página completa; Gasto: modal puro

- **Decisión**: "Nueva Compra" es página completa (excepción documentada al patrón modal, igual que
  Presupuesto/Venta). "Nuevo Gasto" es exclusivamente **modal**, sin ficha de detalle ni página propia
  — ni siquiera para "Ver" (clic en el Id reabre el mismo modal en modo edición).
- **Rationale**: fiel al relevamiento (informe §2.4 vs. §3.4): Compra es un documento de carga extensa
  (ítems dinámicos); Gasto es deliberadamente liviano. Gasto es, de hecho, el primer documento del
  proyecto que respeta el patrón modal-AJAX sin ninguna excepción de página completa.
- **Alternativas descartadas**: página propia para Gasto — contradice el relevamiento y agrega
  complejidad sin valor.

## 11. Frontend — reglas obligatorias CLAUDE.md

- **Decisión**: DataTables server-side en los 2 listados; Select2 para proveedor/producto/categoría/
  cuenta-de-pago (con `ajax` para catálogos grandes); Toastr; PDF (detalle de compra) en el modal PDF
  compartido (`window.AppPdf.abrir`, fallback `window.open`). Pago, Retención, NC/ND y Gasto por modal
  AJAX. Referencia: `resources/js/ventas.js` y `resources/js/otros-ingresos.js` (spec 008).
- **Rationale**: reglas innegociables del proyecto.
- **Alternativas descartadas**: ninguna.
