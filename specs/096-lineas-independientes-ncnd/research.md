# Research: Cada línea del comprobante es un ajuste independiente en la NC/ND

**Feature**: 096-lineas-independientes-ncnd | **Date**: 2026-09-03

## Decisión 1 — Cómo identificar "qué línea se está ajustando"

**Decisión**: agregar una columna nullable a `nota_credito_debito_items` que referencia la línea de
origen: `venta_item_id` (FK a `venta_items.id`, nullable) cuando la nota es de Venta, o
`compra_item_id` (FK a `compra_items.id`, nullable) cuando es de Compra. Sólo una de las dos se llena
según el tipo de nota, igual que hoy `NotaCreditoDebito` ya distingue `venta_id`/`compra_id`.

**Rationale**: es el patrón ya usado en el proyecto (`NotaCreditoDebito.venta_id` / `.compra_id`
mutuamente excluyentes). Usar el `id` propio de `venta_items`/`compra_items` es la única forma de
distinguir dos líneas del mismo producto sin ambigüedad — cualquier otra clave (producto_id + precio,
producto_id + orden) es una heurística sobre datos que ya tienen una clave natural perfecta: el `id`
de la línea.

**Alternatives considered**:
- **Componer una clave sintética** (`producto_id` + posición/orden en el comprobante): fräil ante
  reordenamientos o ediciones del comprobante origen; el `id` real no tiene ese problema.
- **JSON con snapshot de la línea origen** en vez de FK: pierde la capacidad de hacer joins/filtros
  eficientes para calcular "pendiente", y duplica datos que ya viven en `venta_items`/`compra_items`.

## Decisión 2 — Cálculo de "pendiente" con datos mixtos (líneas viejas sin referencia + nuevas con referencia)

**Decisión** (clarificación 2026-09-03, refinada durante la implementación): por producto, dentro de
un mismo comprobante, el cálculo de pendiente usa **una función de dos modos**:

1. **Modo agregado (fallback)**: si EXISTE AL MENOS UNA `NotaCreditoDebitoItem` (no eliminada) de
   ese producto en ese comprobante SIN la referencia de línea nueva, se calcula exactamente como
   hoy — cantidad facturada del producto (sumada entre todas sus líneas) menos cantidad ya ajustada
   del producto (sumada entre todas las notas, sin distinguir línea). Aplica aunque además exista
   alguna nota CON referencia: mientras coexistan, no hay forma de saber qué línea puntual consumió
   la nota vieja, y mezclar modos ahí contaría mal el pendiente.
2. **Modo por línea**: sólo cuando NINGUNA nota (no eliminada) de ese producto carece de la
   referencia — sin notas todavía, o con todas las existentes ya trayéndola — el cálculo se hace
   por línea individual: cantidad de esa línea específica menos lo que las notas que referencian
   **esa misma línea** ya ajustaron.

**Rationale**: es la opción que el usuario eligió explícitamente ("a lo bruto") frente a repartir
proporcionalmente el historial viejo o bloquear el ajuste hasta revisión manual. Preserva el
comportamiento actual para los 41 comprobantes que ya tienen NC/ND vieja sobre un producto repetido
(3 ventas + 38 compras, verificado en producción), y activa automáticamente el cálculo preciso por
línea apenas se usa el sistema arreglado sobre ese producto — sin necesidad de una migración de datos
ni de intervención manual.

**Alternatives considered** (presentadas al usuario, descartadas):
- Repartir proporcionalmente el ajuste histórico entre las líneas del producto: heurística sobre
  datos que no tienen esa información real; más compleja sin garantía de exactitud.
- Bloquear el ajuste automático en los 41 casos hasta revisión manual: más segura en abstracto pero
  agrega trabajo manual real sobre casos que hoy funcionan (aunque de forma imprecisa) sin generar
  quejas.

## Decisión 3 — Dónde vive el fallback: en el servicio, no en dos rutas de código

**Decisión**: `pendiente()` recibe la línea de origen (`VentaItem|CompraItem`) en vez de un
`producto_id` suelto, y decide internamente si hay o no referencia de línea disponible entre las
notas ya creadas para ese producto/comprobante. `itemsDisponibles()` deja de agrupar por
`producto_id` y en cambio itera **cada línea del comprobante** (`whereNotNull('producto_id')`, sin
`groupBy`), llamando a `pendiente()` una vez por línea.

**Rationale**: mantiene una sola función de cálculo de pendiente, evita que el fallback se convierta
en una rama de código paralela que hay que mantener sincronizada con la principal. El costo es que
`itemsDisponibles()` ahora puede devolver dos (o más) filas para el mismo `producto_id` cuando el
comprobante lo repite — comportamiento deseado (FR-001) — y una sola cuando no lo repite (FR-008,
sin cambios visibles).

**Alternatives considered**:
- Mantener `itemsDisponibles()` agrupado y añadir un método nuevo separado para el caso de líneas
  repetidas: duplica lógica de precio/descuento/IVA entre dos métodos y es más frágil a divergencias.

## Decisión 4 — Frontend: matching en edición

**Decisión**: en `notas-credito-debito.js`, donde hoy se reconstruyen ítems al editar buscando por
`producto_id` (`itemsDisponibles.find((d) => d.producto_id === it.producto_id)`), se agrega el campo
`item_origen_id` a la respuesta de `itemsDisponibles()` y al ítem persistido de la nota, y el matching
usa esa referencia cuando está presente (fallback a `producto_id` si no lo está — notas viejas o
casos de fallback agregado).

**Rationale**: sin este cambio, precargar correctamente la cabecera y el listado de líneas
disponibles no alcanza — la reconstrucción de qué línea corresponde a cuál al editar seguiría
fundiendo por producto. Es la misma causa raíz aplicada al flujo de edición.

**Alternatives considered**: dejar la edición con el comportamiento viejo (matching por producto_id)
y sólo arreglar el alta. Se descarta porque deja una asimetría entre alta y edición que reintroduce
el mismo bug al reabrir una nota nueva para editarla.
