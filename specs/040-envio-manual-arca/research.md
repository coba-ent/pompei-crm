# Research: Envío Manual a ARCA desde el listado de Ventas

## 1. Dónde vive hoy el trigger a reemplazar

**Decision**: el trigger automático a eliminar es la llamada a
`$this->emitirComprobanteFiscal($venta)` dentro de `VentaController::cobranzaStore()`
(`app/Http/Controllers/VentaController.php`), agregada en la spec 034. El método privado
`emitirComprobanteFiscal()` (que arma el payload y llama a `EmisorComprobante::emitir()`) se
**reutiliza tal cual**, sólo cambia desde dónde se invoca.

**Rationale**: mínimo blast radius — no se toca la lógica de armado del payload fiscal ni el manejo
de excepciones (`ArcaRechazoException`, `ArcaNoDisponibleException`, `CertificadoNoConfiguradoException`),
que ya está testeada en `EmisionComprobanteRechazoTest.php` y `EmisionComprobanteNotaCreditoDebitoTest.php`.

**Alternativas consideradas**: reescribir el flujo de emisión desde cero en un Service dedicado —
descartada, fuera de alcance (la spec explícitamente no toca la lógica de emisión, sólo quién y
cuándo la dispara).

## 2. Dónde y cómo exponer la acción manual

**Decision**: nueva acción `enviarArca(Venta $venta)` en `VentaController` (mismo controlador que ya
tiene `emitirComprobanteFiscal()` privado), expuesta vía `POST ventas/{venta}/enviar-arca`, protegida
por el mismo middleware `permiso:ventas.ver` que ya protege el grupo de rutas de Ventas (spec 040
§Clarifications — sin permiso nuevo). En el listado (`resources/views/ventas/index.blade.php` +
`resources/js/ventas.js`), se agrega la acción al menú de fila existente de la tabla DataTables,
siguiendo el mismo patrón AJAX + `confirm()` que ya usan `.js-eliminar-cobro`/`.js-eliminar-pago`
en el Detalle de Venta/Compra — con la diferencia de que el **resultado** se muestra en un modal nuevo
(FR-007), no en un toast (ver punto 6).

**Rationale**: consistencia con los patrones ya establecidos en el proyecto (AJAX sin recarga, toast
de resultado, confirm nativo del navegador para acciones destructivas/irreversibles — mismo patrón
que "¿Anular esta cobranza?").

**Alternativas consideradas**:
- Modal de confirmación Bootstrap en vez de `confirm()` nativo — se prefiere `confirm()` nativo por
  consistencia con las acciones de fila ya existentes en Ventas/Compras (no hay precedente de modal
  de confirmación para acciones de una sola fila en este proyecto).
- Endpoint en un controlador ARCA dedicado — se descarta; no existe `ArcaController` en el proyecto,
  y la emisión de Venta ya vive en `VentaController` (igual que NC/ND vive en
  `NotaCreditoDebitoController`).

## 3. Cómo determinar si la acción está disponible para una fila

**Decision**: disponible cuando `venta.tipo_comprobante` ∈ {A, B, C} **y** no existe un
`comprobante_fiscal` asociado con `estado = 'aprobado'`. Se calcula en el backend (al construir los
datos de la fila para DataTables) y se manda como flag booleano (`puede_enviarse_arca`) en la
respuesta AJAX del listado, para que el JS decida si renderiza la acción — evita depender de lógica
de negocio duplicada en el JS.

**Rationale**: mismo patrón que otros flags calculados server-side ya usados en el listado de Ventas
(ej. `estado_cobro`). Además respeta FR-008 (si la Función Avanzada está desactivada, no se ofrece la
acción) evaluando `FuncionAvanzada::activa('facturacion_electronica')` en el mismo cálculo.

**Alternativas consideradas**: calcular la disponibilidad en el cliente a partir de campos crudos
(tipo_comprobante + presencia de CAE) — descartado, duplica la regla de negocio en JS y es más frágil
ante cambios futuros del criterio.

## 4. Protección contra doble envío / doble click

**Decision**: se reutiliza el guard ya existente en el service — `EmisorComprobante::emitir()` no se
modifica, y el endpoint nuevo, al igual que el trigger anterior, sólo tiene efecto si todavía no hay
un `ComprobanteFiscal` aprobado para esa Venta (mismo chequeo que FR-003). Adicionalmente, el botón se
deshabilita en el cliente (JS) mientras la request está en vuelo, para evitar el caso trivial de doble
click.

**Rationale**: no se necesita un lock nuevo — la spec 034 (Principio III de la constitución) ya exige
resiliencia ante reintentos, y el criterio de disponibilidad (FR-003) ya excluye Ventas con CAE
aprobado.

## 5. Impacto en tests existentes

**Decision**: `EmisionComprobanteNotaCreditoDebitoTest.php` (spec 034) actualmente crea una Venta y
**depende del trigger automático en `cobranzaStore`** para obtener el `ComprobanteFiscal` de la Venta
antes de crear la NC/ND. Al eliminar ese trigger, ese test deja de poder obtener el comprobante de la
Venta sólo con `cobranzaStore` — **debe actualizarse** para llamar explícitamente a la nueva acción
`enviarArca` antes de crear la NC/ND. Se trata como parte del alcance de esta spec (no se puede dejar
un test roto).

**Rationale**: Principio IV de la constitución — ningún cambio en lógica fiscal se da por terminado
sin sus tests en verde.

## 6. Modal de resultado vs. toast (FR-007 / FR-007a)

**Decision**: se distinguen dos tipos de feedback según el clarify del 04/08/2026:
- **Rechazo de precondición** (Venta no elegible, Función Avanzada desactivada, certificado no
  configurado) → nunca se llega a contactar ARCA → se informa por **toast** (patrón ya existente en el
  proyecto, CLAUDE.md #3).
- **Resultado real de un intento contra ARCA** (aprobado con CAE, o rechazado/timeout) → modal
  Bootstrap **nuevo**, específico de esta feature (`#modal-resultado-arca` en
  `resources/views/ventas/index.blade.php`), que se abre con el JSON de respuesta del endpoint
  (`contracts/enviar-arca.md`) y permanece visible hasta que el usuario lo cierra.

**Rationale**: el usuario (dueño del negocio) pidió explícitamente que el resultado de un envío real a
un ente fiscal no dependa de un toast efímero — quiere poder leer con calma el CAE obtenido o el
motivo exacto del rechazo antes de que desaparezca. No se reutiliza `modal-pdf.blade.php` porque ese
modal está diseñado para mostrar un `<iframe>` con un documento imprimible, no un resultado de texto.

**Alternativas consideradas**:
- Reutilizar `modal-pdf.blade.php` renderizando el resultado como HTML dentro del iframe — descartado,
  es forzar un mecanismo pensado para documentos a un caso que no lo es (URL/PDF real).
- Un único modal genérico reutilizable para "resultado de acción" en `elements/` (análogo a
  `modal-pdf`) — se descarta por ahora: sería la primera vez que se necesita este patrón en el
  proyecto, y generalizarlo sin un segundo caso de uso real es sobre-ingeniería prematura; si aparece
  una segunda necesidad similar, se extrae en ese momento.

## 7. `CertificadoNoConfiguradoException` en el flujo manual

**Decision**: `emitirComprobanteFiscal()` (privado, reutilizado sin cambios de lógica interna) atrapa
`CertificadoNoConfiguradoException` y devuelve `null` — silencio total, pensado para que el trigger
automático de la spec 034 (al confirmar un cobro) no interrumpa al usuario con un error sobre algo que
no decidió hacer a propósito. Para el flujo manual de esta spec, ese silencio es inaceptable: el
usuario clickeó "Enviar a ARCA" a propósito y necesita saber si funcionó o no. Por eso `enviarArca()`
(T004) valida `CertificadoFiscal::activo()` como precondición explícita **antes** de llamar a
`emitirComprobanteFiscal()`, devolviendo 422/toast si no hay certificado — sin necesidad de tocar el
método privado ni su manejo de excepciones existente.

**Rationale**: preserva el principio de "no modificar la lógica de emisión interna" (FR-006) evitando
a la vez que la spec 034 se meta en su propio pie: un silencio que tenía sentido para un trigger
automático no tiene sentido para un click explícito del usuario.

**Alternativas consideradas**: modificar `emitirComprobanteFiscal()` para que reciba un flag
"es manual" y decida si silenciar o no — descartado, viola FR-006 (no tocar la lógica de emisión) por
una razón que se resuelve más simple con una precondición en el controlador.
