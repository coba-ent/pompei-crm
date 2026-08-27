# Research: Orden de cuentas de tesorería por drag & drop

**Feature**: 085-orden-cuentas-tesoreria
**Fecha**: 2026-08-27

## Decisión 1 — Librería de drag & drop: jQuery UI `sortable`

**Decisión**: usar `sortable()` de jQuery UI, vendorizado en `public/vendor/jqueryui/js/jquery-ui.min.js`,
agregándolo al pagelevel `tesoreria` de `config/dz.php`.

**Rationale**:

- **Ya está en el proyecto y ya se usa para drag & drop.** El pagelevel de "Arma tu Informe"
  (`config/dz.php`, alrededor de la línea 453) carga `vendor/jqueryui/js/jquery-ui.min.js`
  explícitamente como dependencia del drag & drop de dimensiones de PivotTable. No es una librería
  nueva: es una que el proyecto ya sirve, con un precedente documentado de uso para exactamente
  este propósito.
- **Encaja con la arquitectura de assets del proyecto.** Los vendors del template NexaDash se
  cargan por pagelevel desde `config/dz.php`, no por Vite. Meter una librería nueva por npm
  (SortableJS, dragula) rompería ese patrón y agregaría una dependencia de build para un widget
  de un solo modal.
- **API adecuada al requisito.** `sortable()` soporta nativamente todo lo que pide la spec:
  `handle` (control de arrastre dedicado, FR-001), `items`, `axis: 'y'`, el evento `update` para
  persistir al soltar (FR-004), y `cancel()` para revertir visualmente ante un fallo (FR-009).
  Crucialmente, **NO** se usa la opción `connectWith`, y eso es lo que implementa FR-003: sin
  `connectWith`, cada `<tbody>` es una isla y arrastrar entre bloques es imposible por construcción,
  no por una validación que se pueda olvidar.

**Alternativas consideradas**:

- **SortableJS (npm)**: más moderna, sin dependencia de jQuery, mejor soporte táctil. Descartada
  porque exigiría sumarla al bundle de Vite mientras el resto del stack visual del modal
  (Bootstrap, Toastr, Select2) viene por pagelevel — una inconsistencia de arquitectura a cambio
  de un beneficio que esta pantalla no necesita (la spec declara escritorio con mouse como
  criterio de aceptación).
- **`nestable2`** (también vendorizado, `public/vendor/nestable2/`): pensada para árboles anidados
  con jerarquía. Sobra para una lista plana dentro de un tipo y arrastra consigo un modelo de datos
  (padres/hijos) que acá no existe.
- **HTML5 Drag & Drop nativo**: sin dependencias, pero requiere escribir a mano el indicador de
  posición, el manejo del `dragover` y el scroll automático — reimplementar lo que la librería ya
  vendorizada resuelve.

## Decisión 2 — Contrato del endpoint: un bloque por request, ids ordenados

**Decisión**: `PATCH /tesoreria/cuentas/orden` recibe `{ tipo, ids: [...] }` — el tipo del bloque y
la lista **completa** de ids de ese bloque en el orden deseado. Reasigna `orden = 1..N` en una
transacción.

**Rationale**:

- **Un solo bloque por request** mantiene el contrato chico y hace que la validación de conjunto
  (Decisión 3) sea directa: el conjunto a comparar es "todas las cuentas de ese tipo".
- **Lista completa, no un delta** (`{id, posicion_nueva}`): recalcular posiciones desde un delta en
  el servidor implica reproducir la lógica de reordenamiento que el navegador ya ejecutó, con
  riesgo de divergencia. Mandar el orden final resuelto es más simple y hace el endpoint idempotente.
- **`orden` consecutivo 1..N** (FR-006) en vez de huecos tipo 10/20/30: el bloque siempre se
  reescribe entero, así que no hay inserciones incrementales que aprovechen los huecos. Además
  normaliza los `NULL` heredados, que hoy generan el comportamiento de "sin orden va al final"
  descrito en el comentario de `scopeOrdenadas()`.
- **Verbo `PATCH`**: modifica un atributo puntual de un conjunto de recursos existentes, no crea ni
  reemplaza recursos completos.

**Alternativas consideradas**:

- **Todos los bloques en un request** (`{efectivo: [...], banco: [...]}`): innecesario, ya que un
  arrastre sólo afecta a un bloque. Ampliaría el alcance de un fallo.
- **`PUT /tesoreria/cuentas/{cuenta}` con el campo `orden`**: reutilizaría el endpoint existente,
  pero perdería la atomicidad de bloque (FR-007) — serían N requests, y un fallo a mitad de camino
  dejaría un orden inconsistente.

## Decisión 3 — Control de concurrencia por comparación de conjunto

**Decisión**: el servidor compara el conjunto de ids recibido contra `CuentaTesoreria::porTipo($tipo)->pluck('id')`.
Si difieren en cualquier sentido (id ajeno, faltante, agregado, repetido) responde **409 Conflict**
sin escribir nada. El front muestra el error y recarga el listado del modal.

**Rationale** (fijado en la sesión de clarify del 27/08/2026):

- **La validación funcional que FR-008 ya exige es, en sí misma, el control de concurrencia.** Se
  necesita comparar el conjunto de todos modos para garantizar el 1..N completo; que esa misma
  comparación detecte altas y bajas hechas en paralelo sale gratis.
- **Sin versionado ni timestamps**: agregar una columna de versión al bloque o comparar
  `updated_at` sería infraestructura nueva para un escenario que en una instalación single-tenant,
  con un puñado de cuentas y un operador de tesorería, es prácticamente inexistente.
- **409 y no 422**: no es un error de forma del payload sino un choque con el estado actual del
  recurso, y el front reacciona distinto (recargar el listado, no marcar campos inválidos).

**Alternativas consideradas**:

- **Versionado optimista por hash del bloque**: correcto pero desproporcionado; se descartó
  explícitamente en clarify.
- **Aplicar el orden a los ids que existan e ignorar los faltantes**: viola FR-007 (atomicidad) y
  deja al usuario creyendo que guardó un orden que en realidad quedó distinto.

## Decisión 4 — Refresco de las cards sin recargar: reutilizar `TesoreriaSaldos.recargar`

**Decisión**: tras un guardado exitoso, el front llama a `window.TesoreriaSaldos.recargar()`, que ya
existe (`resources/js/tesoreria.js`, expuesto al final del bloque de Saldos) y repinta las cuatro
tablas de cards desde `tesoreria.saldos.data`.

**Rationale**: el orden de las cards lo determina el backend vía `scopeOrdenadas()`, así que
refetchear los saldos ya devuelve el orden nuevo sin lógica de reordenamiento duplicada en el
cliente. Cumple FR-010 (sin recarga de página) reutilizando el mecanismo existente en lugar de
inventar uno.

**Alternativas consideradas**:

- **Reordenar los `<tr>` de las cards en el cliente** espejando el movimiento del modal: evita un
  request, pero duplica la regla de orden en dos lugares y se desincroniza en cuanto el backend
  cambie el criterio de desempate.

## Decisión 5 — Accesibilidad por teclado: el handle es un `<button>`

**Decisión**: el control de arrastre es un `<button type="button" class="js-mover-cuenta">` con
`aria-label`, que responde a `ArrowUp`/`ArrowDown` moviendo la fila una posición y persistiendo con
el mismo endpoint (FR-013).

**Rationale**: un `<button>` es enfocable y anunciable por lectores de pantalla sin `tabindex`
manual, y `sortable({handle: 'button.js-mover-cuenta'})` lo acepta como manija sin conflicto. El
handler de teclado reordena el DOM y llama a la misma función `persistirOrden(tipo)` que el `update`
de sortable, así que no hay dos caminos de guardado que puedan divergir.

**Alternativas consideradas**:

- **Botones separados ▲/▼ en cada fila**: más descubribles, pero agregan dos controles por fila a
  una tabla que ya tiene tres columnas, y el usuario pidió específicamente drag & drop.
- **Omitir el soporte de teclado**: la spec lo pone como P3, es barato de agregar sobre el handler
  que ya se necesita, y sin él el modal queda sin ninguna vía de reordenamiento accesible.

## Decisión 6 — Serialización de arrastres sucesivos

**Decisión**: mantener por bloque una referencia al request en vuelo; al disparar uno nuevo se
aborta el anterior (`jqXHR.abort()`) antes de enviar. El estado visual del bloque se captura
**antes** de cada arrastre para poder revertir (FR-009).

**Rationale**: cumple FR-015 sin una cola. Como cada request lleva el orden completo y final del
bloque, el último en enviarse contiene la verdad; abortar los anteriores elimina la posibilidad de
que una respuesta tardía de un orden viejo dispare un refresco de cards desactualizado.

**Alternativas consideradas**:

- **Debounce del guardado**: retrasaría la confirmación al usuario y dejaría una ventana donde
  cerrar el modal pierde el cambio.
- **Ignorar respuestas fuera de orden con un contador de secuencia**: equivalente en efecto pero
  deja requests inútiles viajando; abortar es más directo.

## Decisión 7 — Permiso requerido: `tesoreria.editar`

**Decisión**: la ruta va dentro del grupo `tesoreria` existente pero con `middleware('permiso:tesoreria.editar')`.

**Rationale**: el grupo entero está bajo `permiso:tesoreria.ver`, que es de lectura. Reordenar
modifica configuración persistente del negocio, así que corresponde el permiso de edición, igual que
`cuentas.update`. La spec asume que no se crea un permiso nuevo (Assumptions), y `tesoreria.editar`
ya existe en `PermisoSeeder`.

**Alternativas consideradas**:

- **Sólo `tesoreria.ver`**: dejaría que un usuario de consulta cambie cómo ve la tesorería todo el
  negocio, ya que el orden es global y no por usuario.
