# Research — spec 063

Lo que se verificó **en el código y en la base de producción** antes de planificar. Cada decisión
apunta a reutilizar lo que ya existe: casi toda la infraestructura necesaria está construida.

## R1 — La reversión ya está construida: no se toca

**Decisión**: esta feature **no construye ni modifica** ningún mecanismo de reversión. Detecta,
avisa y conduce a la Venta; la persona resuelve con lo que ya existe.

**Rationale**: las dos vías ya están hechas y funcionan:

- **Nota de crédito** (specs 045/059/061) — con `afecta_stock` ya repone el inventario, con sus
  reglas de cantidad pendiente por producto. Es la vía correcta para una factura emitida: el
  comprobante autorizado por ARCA no debe desaparecer, se compensa con otro.
- **Eliminación** — `$venta->delete()` dispara `VentaObserver::deleting()`, que revierte cobros,
  Tesorería y stock de forma atómica, e incluso contempla que las ventas migradas no reintegren
  stock. Queda disponible como está hoy.

**Dos correcciones de enfoque durante la spec**: primero se planteó construir una anulación con
reversión propia; después, emitir la nota de crédito precargada desde el aviso. Las dos inventaban
lo que ya estaba hecho. El diseño final sólo agrega el aviso.

**Consecuencia sobre el cobro**: a diferencia del borrado, la nota de crédito **no revierte el
cobro**. La factura queda compensada y el cliente con saldo a favor, que es el reflejo correcto: el
dinero lo devolvió Mercado Libre descontándolo de la cuenta, no el CRM. Registrar ese egreso es
parte de la conciliación de la cuenta y queda fuera de alcance.

**Nota sobre las ventas migradas**: el problema de que no deben reintegrar stock —que `VentaObserver`
resuelve para el borrado— **no aplica acá**, porque las ventas de Mercado Libre no son migradas. Si
alguna vez se emitiera una nota de crédito con stock sobre una venta migrada, ahí sí habría que
mirarlo (§8d del registro de casos ya documenta la regla).

## R2 — Dónde vive la marca del aviso

**Decisión**: el aviso se registra **en la orden** (`ml_ordenes`), no en la Venta.

**Rationale**: la orden ya tiene `estado_conversion`, `motivo` (enum `MotivoRequiereAtencion`) y
`motivo_detalle`, y ya es la entidad que la pantalla de Órdenes de Mercado Libre lista y filtra. El
estado `RequiereAtencion` ya existe en `EstadoConversion`, y su máquina de estados ya admite la
transición desde `Convertida`:

```php
self::Convertida => [self::Cancelada],
```

Poner la marca en `ventas` obligaría a agregar columnas a una tabla central del sistema para un caso
que sólo aplica a ventas de Mercado Libre.

**Consecuencia**: hay que ampliar la transición `Convertida → RequiereAtencion` (hoy sólo permite
`Convertida → Cancelada`) y sumar motivos nuevos al enum.

**Alternativas descartadas**: tabla nueva de avisos — desproporcionada para 12 casos históricos y
duplicaría el concepto de "requiere atención" que ya existe.

## R3 — Reembolso parcial y mediación hoy se pierden

**Decisión**: `EstadoOrden::desdeCrudo()` debe dejar de colapsar los tres estados.

**Rationale**: hoy hace

```php
'cancelled', 'pending_cancel', 'partially_refunded' => self::Cancelada,
```

Con lo cual un reembolso parcial es indistinguible de una cancelación, y **la mediación no aparece
en absoluto**: el estado `in_mediation` vive en el pago (`payments[].status`), no en el estado de la
orden. La venta 65 está `partially_refunded` y la 112 tiene un pago `in_mediation`, ambas verificadas
contra la API.

Para cumplir FR-004 hay que distinguir los tres motivos, leyendo también el estado del pago.

**Alternativas descartadas**: tratar todo como cancelación — el usuario lo rechazó explícitamente,
porque anular una mediación en curso es peor que no anularla.

## R4 — Clasificación de errores de sincronización

**Decisión**: se clasifica por el mensaje que devuelve Mercado Libre, y se corta a los 5 intentos
consecutivos con el mismo error.

**Rationale**: los errores reales medidos en producción son de dos clases distintas:

```
Cannot update item MLA1489377153 [status:under_review, has_bids:false]   ← permanente
Cannot update item MLA762900978                                          ← indeterminado
```

Los `under_review` son bloqueos del marketplace: reintentar no puede funcionar. Los demás no
declaran causa, así que no se los puede clasificar de entrada — por eso el corte por cantidad de
intentos, que cubre ambos casos sin depender de adivinar el motivo.

**Alternativas descartadas**: clasificar sólo por código HTTP (todos son 400, no discrimina) y
backoff exponencial (sigue golpeando la API para siempre, no resuelve FR-016).

## R5 — El conteo de intentos necesita persistirse

**Decisión**: agregar a `ml_publicacion_producto` el conteo de intentos fallidos consecutivos y la
fecha de la primera falla.

**Rationale**: hoy la tabla tiene `stock_error` y `stock_error_en`, que guardan **el último** error,
pero no cuántas veces falló ni desde cuándo. Sin eso no se puede cumplir FR-014 (mostrar intentos
acumulados y primera falla) ni FR-015 (cortar a los 5).

## R6 — Volumen y performance

**Decisión**: no se optimiza para volumen.

**Rationale**: medido en producción — 126 órdenes de Mercado Libre en total, 12 canceladas, 3 con
venta asociada, 270 publicaciones vinculadas. La detección recorre órdenes ya traídas por la
sincronización existente, que además ya hace una pasada dedicada a canceladas (FR-012a). No se
agregan llamadas a la API.

## R7 — Interacción con ARCA

**Decisión**: esta feature no toca ningún comprobante fiscal. Sólo avisa.

**Rationale**: el Principio III de la constitución prohíbe alterar comprobantes autorizados. Como el
aviso no ejecuta ninguna acción sobre la Venta, no puede violarlo. La spec deja escrito cuál es la
vía recomendada —nota de crédito, que compensa sin hacer desaparecer la factura— pero no restringe:
la eliminación sigue disponible, como hoy.

**Hallazgo que excede esta spec**: `VentaController::destroy()` es hoy un `$venta->delete()` sin
ninguna verificación de comprobante fiscal, o sea que **el sistema permite eliminar una factura ya
autorizada por ARCA**. Esta feature ya no depende de eso —porque no borra nada— pero el agujero
existe y hay que registrarlo por separado en el registro de casos.
