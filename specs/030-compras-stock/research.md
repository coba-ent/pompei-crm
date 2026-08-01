# Research: Compras suman stock

## R1: ¿Cómo resolvió Ventas el mismo problema (referencia)?

- **Decisión**: reutilizar exactamente el mismo patrón que `App\Services\Ingresos\StockDeVenta`: un
  servicio dedicado con tres métodos (`aplicarAlta`, `reintegrarPorEliminacion`, `reaplicarPorEdicion`),
  invocado desde el controlador en los puntos de alta/edición/baja, que delega el movimiento atómico en
  `StockService`.
- **Rationale**: es el precedente directo del mismo problema (documentos comerciales cuyo guardado debe
  mover stock) resuelto en el mismo proyecto; mantener el mismo nombre de métodos y estructura reduce
  carga cognitiva para quien mantenga ambos servicios en paralelo.
- **Alternativas consideradas**:
  - *Observer sobre `Compra`/`CompraItem`* (como `MovimientoStockObserver`/`VentaObserver`): descartado
    porque el ajuste de stock necesita conocer los ítems **anteriores** en una edición (para reintegrar
    antes de reaplicar), y el controlador ya captura eso explícitamente antes de `items()->delete()`
    (igual que hace `VentaController` para `StockDeVenta::reaplicarPorEdicion`); un Observer de modelo no
    tiene ese antes/después de forma natural sin acoplarse al ciclo de vida de Eloquent de una forma más
    frágil que la que ya usa Ventas.
  - *Lógica inline en `CompraController`*: descartado por duplicar la responsabilidad que ya está
    extraída a un servicio para Ventas — rompería el principio de "una sola forma de hacer lo mismo" y
    dificultaría testear el ajuste de stock de forma aislada.

## R2: Depósito de destino

- **Decisión**: `Deposito::porDefecto()`, exactamente como usa `StockDeVenta::depositoPorDefecto()` para
  Ventas manuales. Sin campo nuevo en el formulario de Compra (ver spec.md Assumptions — el informe de
  Contagram no muestra ese selector).
- **Rationale**: mismo criterio ya validado y en producción para el caso análogo (documentos manuales sin
  origen ML/TN). Evita divergencia de UX no relevada.
- **Alternativas consideradas**: selector de depósito en el formulario de Compra (descartado por
  Assumptions de spec.md — sin evidencia en el informe relevado); depósito por ítem (descartado por
  decisión de negocio, ver AskUserQuestion previa a esta cadena de specs).

## R3: Fecha del movimiento de stock

- **Decisión**: `fecha_emision` de la Compra (Clarifications, spec.md). Requiere agregar un parámetro
  `?string $fecha = null` a `StockService::registrarEntrada()`/`registrarSalida()` (y al método privado
  `mover()` que ambos comparten), con default `now()->toDateString()` para no alterar el comportamiento
  actual de Ventas (que no pasa fecha explícita).
- **Rationale**: `StockService::ajustar()` ya soporta un parámetro `$fecha` opcional con el mismo default
  — se alinea `mover()` al mismo patrón en vez de introducir uno nuevo.
- **Alternativas consideradas**: fecha de guardado (hoy) — descartada explícitamente en `/speckit-clarify`
  porque no refleja correctamente compras cargadas con demora o de forma retroactiva en los informes de
  stock por fecha.

## R4: NC/ND de Compra

- **Decisión**: ninguna — ya está implementado (`NotaCreditoDebitoController::storeCompra`, spec 009).
  Confirmado en `/speckit-clarify` que no es una brecha. Fuera de alcance de esta feature.
- **Rationale**: evitar duplicar trabajo ya hecho; documentado únicamente para dejar constancia de que se
  revisó.

## R5: Variantes de producto en `CompraItem`

- **Decisión**: fuera de alcance. `CompraItem` no tiene `variante_id` hoy; el movimiento de stock por
  Compra usa siempre variante `null`, igual alcance que la grilla de ítems de Compra actual.
- **Rationale**: agregar `variante_id` a `CompraItem` sería una feature de mayor alcance (UI de grilla,
  migración, `StoreCompraRequest`/`UpdateCompraRequest`) no pedida ni relevada; se documenta como brecha
  pendiente en `docs/documentacion_principal_crm.md §4.3` en vez de resolverse por elisión.
- **Alternativas consideradas**: agregar `variante_id` ahora — descartado por alcance no solicitado.
