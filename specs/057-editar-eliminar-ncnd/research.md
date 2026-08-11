# Research: Edición y eliminación de NC/ND

Todos los campos de Technical Context en `plan.md` quedaron resueltos sin `NEEDS CLARIFICATION`
(proyecto ya establecido, stack fijo). Este documento resuelve las decisiones técnicas de diseño
que la spec deja abiertas a nivel de implementación.

## 1. Reversión de stock al editar/eliminar una NC/ND

**Decisión**: recalcular desde cero (revertir el movimiento anterior completo + aplicar el nuevo
si corresponde), no aplicar un delta.

**Rationale**: `StockService::ajustar()` ya registra un `movimiento_stock` por cada ajuste
(`app/Services/Stock/StockService.php`, usado en `NotaCreditoDebitoController@store`/
`storeCompra`). Revertir = generar un movimiento inverso con el mismo motivo/monto, en vez de
mutar el movimiento original (los movimientos de stock son un log append-only en todo el resto
del sistema — mismo patrón que Ventas/Compras/Remitos). Al editar, se revierte el movimiento de
la nota tal cual estaba, y luego se aplica el nuevo ajuste (posiblemente con otro depósito u otra
cantidad) como si fuera una creación — reutiliza el mismo código de `store`/`storeCompra` en vez
de tener una rama "editar stock parcialmente" propensa a errores de arrastre.

**Alternativas consideradas**:
- *Delta (sólo ajustar la diferencia)*: rechazado — si cambia también el depósito o el producto,
  un delta no alcanza y hay que revertir+reaplicar de todos modos; mantener un solo camino es más
  simple y ya está probado por la lógica de creación existente.
- *No permitir editar cantidad/depósito, sólo monto/descripción*: rechazado — contradice la
  captura real de Contagram, donde el paso 2 de edición es un formulario completo con ítems.

## 2. Ítems con IVA/precio propio (`nota_credito_debito_items`)

**Decisión**: agregar `precio_unitario`, `descuento_pct`, `iva_pct` (nullable, con default 0) a
`nota_credito_debito_items` vía migración; dejan de ser exclusivos de `afecta_stock = true` — se
crean siempre que haya al menos un ítem con descripción/monto, igual que un renglón de
Compra/Venta.

**Rationale**: el PDF real de Contagram (`docs/informe_contagram_egresos.md` §2.5.1) muestra
Cant./Precio Unit./%Bonif./Subtotal/Alícuota IVA/Subtotal c/IVA por renglón — hoy
`nota_credito_debito_items` sólo tiene `producto_id`/`cantidad`/`precio`/`origen`, y sólo se llena
si afecta stock (`app/Models/NotaCreditoDebitoItem.php`). El `monto` global en `notas_credito_debito`
se mantiene como el total ya validado (`monto > 0` en `StoreNotaCreditoDebitoRequest`) — los
ítems son el desglose que hoy sólo vive implícito en el PDF (que hoy renderiza a partir de los
datos de la venta/compra original, no de la nota).

**Alternativas consideradas**:
- *No tocar el esquema de ítems, mantener sólo `monto` global*: rechazado — no permite reproducir
  el PDF real con desglose de IVA por renglón (User Story 3), que es parte explícita del pedido.
- *Tabla nueva separada `nota_credito_debito_conceptos` (como `venta_conceptos`)*: evaluado pero
  descartado por ahora — `nota_credito_debito_items` ya existe y cubre el mismo rol; extenderla
  evita una migración de datos y un JOIN adicional. Si en el futuro se necesitan conceptos sin
  producto asociado (servicios), se reevalúa.

## 3. Encadenamiento "Documento que Ajusta → otra NC/ND" (1 nivel)

**Decisión**: agregar `nota_ajustada_id` (FK auto-referencial a `notas_credito_debito.id`,
nullable) a la tabla. Exactamente uno de `venta_id`/`compra_id`/`nota_ajustada_id` debe estar
seteado (regla de negocio, no constraint de DB — mismo patrón ya usado para `venta_id`/`compra_id`
mutuamente excluyentes). El selector "Documento que Ajusta" sólo ofrece como opción una
`NotaCreditoDebito` cuando su propio `nota_ajustada_id` es `NULL` (evita 3+ niveles, cumple
FR-013).

**Rationale**: coincide con la captura real (el select lista comprobante original + NC/ND
existentes) y es la forma más simple de modelar "ajusta a X", reusando el mismo concepto que ya
existe para `venta_id`/`compra_id` en vez de una tabla de relación aparte.

**Alternativas consideradas**:
- *Tabla pivote `notas_credito_debito_ajustes` (many-to-many)*: rechazado por sobre-ingeniería —
  la spec y las capturas confirman una relación 1 a 1 (una nota ajusta a un solo documento), no
  N a N.
- *Guardar sólo el comprobante ajustado como texto libre*: rechazado — impide validar
  FR-006 (bloqueo de eliminación por cadena) y FR-013 (límite de 1 nivel) de forma confiable.

## 4. Comprobante propio (`tipo_comprobante_propio` + `nro_comprobante`) y validación de duplicados

**Decisión**: agregar `nro_comprobante` (string, nullable) a `notas_credito_debito` — el campo
`tipo_comprobante` ya existe (hoy se llena con el tipo heredado del original; pasa a ser editable
en el wizard). La validación de duplicados en `UpdateNotaCreditoDebitoRequest` replica el patrón
ya usado en `MigrarVentasContagram::nroComprobante()`: rechaza si el par
`tipo_comprobante`+`nro_comprobante` ya existe en otra `Venta`, `Compra` **o** `NotaCreditoDebito`
(consulta `orWhereHas`/`exists()` contra las tres tablas).

**Rationale**: cumple FR-012 y reutiliza un criterio ya validado en el proyecto en vez de inventar
uno nuevo.

## 5. Bloqueo de edición/eliminación cuando hay CAE aprobado

**Decisión**: en `UpdateNotaCreditoDebitoRequest` y en el método `destroy()`, verificar
`$nota->comprobanteFiscal?->aprobado()` (mismo método ya usado en
`NotaCreditoDebitoController@store` línea 90 para decidir si emitir a ARCA) y devolver 422/403 con
mensaje explícito si es `true`.

**Rationale**: reutiliza el mismo helper `aprobado()` ya existente en `ComprobanteFiscal`, sin
duplicar lógica de qué significa "tiene CAE".

## 6. PDF de NC/ND en Compras

**Decisión**: generalizar `NotaCreditoDebitoController@pdf` y la vista
`notas-credito-debito/pdf.blade.php` para cargar `venta` **o** `compra` según cuál esté seteada
(`$notaCreditoDebito->venta ?? $notaCreditoDebito->compra`), en vez de duplicar el controller/vista
para Compras. Se agrega la ruta `GET {compra}/notas/{notaCreditoDebito}/pdf` con el mismo `name`
pattern (`compras.notas.pdf`) que ya usa Ventas (`ventas.notas.pdf`), y el link "Ver Detalle" en
`compras/detalle.blade.php` (hoy ausente).

**Rationale**: evita divergencia entre dos templates casi idénticos; el patrón ya existe en el
resto de la app (un solo controller para ambas entidades vía `venta_id`/`compra_id`).

## 7. Soft delete

**Decisión**: `NotaCreditoDebito` ya tiene `deleted_at` en el esquema (`docs/modelo_datos.md`,
migración `2026_07_30_060006_create_notas_credito_debito_tables.php`) pero el modelo no usa el
trait `SoftDeletes` todavía (confirmar en Fase de implementación) — se agrega si falta. `destroy()`
usa `$nota->delete()` (soft) nunca `forceDelete()`, cumpliendo Principio III de la constitución.

**Rationale**: directo de la constitución — documentos con impacto contable nunca se borran
físicamente.
