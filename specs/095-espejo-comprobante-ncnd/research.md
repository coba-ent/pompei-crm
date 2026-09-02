# Research: Espejo del comprobante de origen al crear una NC/ND

**Feature**: 095-espejo-comprobante-ncnd | **Date**: 2026-09-02

## Decisión 1 — El descuento general va en la cabecera de la nota, no prorrateado en las líneas

**Decision**: replicar `descuento_general_tipo` / `_pct` / `_monto` del comprobante en los campos
homónimos de la nota, dejando el descuento de cada línea intacto.

**Rationale**: relevamiento directo de Contagram real (02/09/2026, venta 34925956). Su formulario de
NC mantiene **dos niveles separados** y no los mezcla:

- `note[discount]` — descuento general de cabecera
- `note[note_products_attributes][N][discount]` — descuento por línea
- más `line_discount` y `general_discount` como campos por producto

Es decir: Contagram **no prorratea** el general en las líneas. Nuestra tabla ya tiene exactamente
esos campos de cabecera, así que replicar es también el camino de menor cambio.

**Alternatives considered**:

- *Prorratear el general entre las líneas*: da el mismo total, pero cambia cómo se ve el documento
  impreso y cómo lo lee ARCA, y dejaría muerto el campo de cabecera que la nota ya tiene. Descartado
  por contradecir lo observado.
- *Convertir monto fijo a porcentaje al precargar*: descartado en la clarificación Q2 — altera un
  dato que el usuario cargó explícitamente como importe.

## Decisión 2 — La cabecera viaja por el HTML ya renderizado, no por una llamada nueva

**Decision**: ampliar `comprobanteOrigen` dentro de `window.NotaFormData` (que la vista ya emite) con
los campos de cabecera. El endpoint `items-disponibles` sigue ocupándose **sólo de los ítems**.

**Rationale**: el controlador ya tiene el comprobante cargado en `create()` / `createCompra()` y la
vista ya emite `comprobanteOrigen` con tres campos. Sumar los que faltan no agrega latencia ni una
segunda llamada. Además mantiene una separación limpia: la cabecera es estática y se conoce al
renderizar; los ítems dependen de lo pendiente de ajuste y por eso son asíncronos.

**Alternatives considered**:

- *Ampliar la respuesta de `items-disponibles`*: mezclaría cabecera con detalle en un endpoint cuyo
  nombre y contrato son sobre ítems, y retrasaría la precarga hasta que resuelva el AJAX.
- *Un endpoint nuevo de cabecera*: una llamada más para datos que ya están en memoria al renderizar.

## Decisión 3 — Los conceptos se traducen de tabla a JSON al precargar

**Decision**: al precargar percepciones e impuestos internos, leer las filas de `venta_conceptos` /
`compra_conceptos` y entregarlas con la forma que la nota ya usa (`tipo`, `concepto`, `monto`).

**Rationale**: los comprobantes guardan sus conceptos en tablas propias, mientras que la nota los
guarda en la columna JSON `impuestos`. La forma de cada elemento coincide (`tipo`, `concepto`,
`monto`), así que la traducción es directa y no necesita mapeo especial. El front de la nota ya
consume `conceptos` con esa forma.

**Alternatives considered**:

- *No precargar conceptos*: dejaría una NC que anula "completa" pero sin revertir las percepciones,
  o sea anulada de menos. Contradice FR-007.

## Decisión 4 — El tipo de comprobante advierte en vez de bloquear

**Decision**: precargar el tipo del comprobante y, si el usuario elige otro, advertir antes de
guardar sin impedirlo (clarificación Q3, FR-004a).

**Rationale**: el principio III de la constitución pide que el tipo se derive y no se elija a mano, y
en la base ya hay 13 notas con el tipo cruzado respecto de su venta. Pero bloquearlo del todo sería
más estricto que Contagram y podría trabar comprobantes migrados donde el tipo legítimo difiere. La
advertencia corrige el caso masivo (nace bien por defecto) sin crear un callejón sin salida.

**Alternatives considered**:

- *Editable sin aviso*: es el comportamiento que produjo las 13 notas cruzadas.
- *Bloqueado*: seguro, pero sin escape para datos migrados.

## Decisión 5 — Sin ítems se precarga sólo la cabecera

**Decision**: en una nota con "afecta stock = No", precargar fechas, tipo, tercero y categoría;
dejar monto y descripción vacíos (clarificación Q4, FR-013).

**Rationale**: sin ítems no hay subtotal del cual derivar un importe, y ese tipo de nota suele
ajustar por un valor distinto al del comprobante. Precargar un monto ahí sería adivinar.

## Riesgo conocido, fuera de alcance

La **edición** de una NC/ND valida menos que la eliminación: al editar sólo se bloquea por CAE
aprobado, mientras que al eliminar se valida además el crédito ya aplicado y las notas encadenadas.
Hay 2 notas reales (856 y 859) sin CAE, con crédito aplicado a otros comprobantes, hoy editables sin
traba: bajarles el monto dejaría el saldo aplicado por encima del monto de la nota.

No se resuelve acá. Queda registrado para un spec propio.
