# Research: Comprobante por defecto derivado de la Condición de IVA

## R1 — Dónde enganchar la derivación

**Decision**: Escuchar el evento `change` de `select[name="condicion_iva_id"]` (delegado sobre `$form`,
mismo patrón ya usado para `.js-provincia` y el resto de los listeners del modal) y, en el handler,
completar `select[name="tipo_comprobante_defecto"]` si no fue tocado a mano. Esto cubre tanto la
selección manual del usuario en el desplegable como el autocompletado del botón "Verificar"
(`autocompletarDesdePadron()`), porque ese último también hace `$select.val(...)` sobre
`condicion_iva_id` — para que dispare el mismo `change` hace falta agregar `.trigger('change')` ahí (hoy
no lo hace, es justamente por qué otros campos derivados de un `select` no reaccionan a ese
autocompletado).

**Rationale**: Un único punto de enganche (el evento `change` del campo origen) en vez de duplicar la
llamada a la función derivadora en dos lugares (`autocompletarDesdePadron()` y el handler manual de
selección) — más simple y evita que un tercer punto futuro que también toque `condicion_iva_id` se
olvide de derivar el comprobante.

**Alternatives considered**: Duplicar la llamada explícita en `autocompletarDesdePadron()` además del
handler de `change` — descartado, es exactamente el tipo de duplicación que un simple
`.trigger('change')` evita.

## R2 — Regla de derivación (mapeo texto → A/B)

**Decision**: Igual que el backend (`ResolutorCliente::CONDICION_IVA_FACTURA_A` /
`DerivadorComprobante::MAPEO_CONDICION_IVA_CRM`): sólo si el texto de la opción seleccionada de
`condicion_iva_id` es exactamente "Responsable Inscripto" (comparación por el texto visible de la
`<option>`, ya que el `<select>` no expone el nombre en un `data-*` hoy) deriva "A"; cualquier otro
valor (incluido vacío) deriva "B". Se agrega `data-nombre` a cada `<option>` de
`condicion_iva_id` en `_modal_form.blade.php` si hiciera falta un match más robusto que el texto —
evaluado y descartado: el texto visible ya es el `nombre` de `condiciones_iva` sin transformación
(`{{ $condicion->nombre }}`), es un match directo y confiable, no hace falta el atributo extra.

**Rationale**: Cero nueva fuente de verdad — la lista de condiciones y sus nombres ya vive en
`condiciones_iva` (BD) y se renderiza tal cual en las `<option>`; comparar por texto visible es
suficiente y evita depender de que los IDs numéricos coincidan con ningún significado especial.

**Alternatives considered**: Mapear por `id` numérico de `condiciones_iva` — descartado, acoplaría el JS
a un ID específico de seed en vez del nombre semántico ya usado en backend, y se rompería si el ID
cambiara entre ambientes.

## R3 — Interacción con el mecanismo de "tocado" existente

**Decision**: Se agrega `'tipo_comprobante_defecto'` a `CAMPOS_PADRON` (el array ya usado para trackear
qué campos edita el usuario a mano) — aunque el origen del autocompletado no sea el padrón de ARCA sino
`condicion_iva_id`, es el mismo mecanismo de "no pisar lo que el usuario ya tocó" y reusarlo evita
introducir un segundo sistema de flags paralelo.

**Rationale**: Consistencia — un solo mecanismo de "tocado" para los 5 campos autocompletables del
modal (`razon_social`, `domicilio_fiscal`, `provincia_fiscal`, `localidad_fiscal`,
`condicion_iva_id`, `tipo_comprobante_defecto`), en vez de una implementación paralela sólo para este
campo.

**Alternatives considered**: Un flag dedicado (`tocadoComprobante`) fuera de `CAMPOS_PADRON` —
descartado, sin ninguna ventaja sobre reusar el array existente (el listener genérico
`$form.on('input change', '[name="' + campo + '"]', ...)` ya cubre cualquier campo que se agregue a la
lista).

## R4 — Edición de cliente existente

**Decision**: Al precargar un cliente existente en el modal (edición), `resetearTocadoPadron()` ya se
llama al abrir el modal (mismo ciclo de vida que los otros campos) — el listener de `change` sobre
`condicion_iva_id` no se dispara sólo por precargar valores vía JS (`.val(...)` sin `.trigger('change')`
en la función de precarga de datos del cliente), así que el `tipo_comprobante_defecto` ya guardado del
cliente no se recalcula al abrir el modal, sólo si el usuario cambia la condición de IVA después
(FR-005).

**Rationale**: Mismo comportamiento ya vigente para los otros campos (no se recalculan al abrir edición,
sólo ante una interacción nueva) — consistencia sin código adicional.

**Alternatives considered**: Ninguna — comportamiento ya garantizado por cómo está armada la precarga
actual, sólo hace falta no romperlo.
