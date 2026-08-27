# Investigación y decisiones — spec 084

Cada decisión dice qué se eligió, contra qué se la comparó y por qué se descartó lo otro. Lo que hay
acá es lo que hace falta saber para no "arreglar" mañana algo que está así a propósito.

---

## Decisión 1 — El precio publicado se recuerda, no se consulta

**Qué se eligió**: una columna `precio_publicado` en `ml_publicacion_producto`, escrita en cada envío
que Mercado Libre acepta, y refrescada por el chequeo diario (US3). El corte compara contra ese
valor. Si está en `NULL`, **retiene** (FR-005).

**Alternativa descartada — consultar `GET /items/{id}` antes de cada envío**: es lo más fiel a la
realidad, pero pone una llamada HTTP en el camino crítico. La importación del 25/08 movió 1.056
precios de golpe; con la lista completa serían miles de llamadas sincrónicas dentro del request de
importación. Medido en producción, cada `GET /items` tarda ~180 ms: 270 publicaciones son ~50
segundos, y ese número crece con el catálogo. Inaceptable dentro de una importación.

**Por qué el valor recordado alcanza**: el CRM sabe con certeza qué precio publicó, porque lo mandó
él y la API respondió 200. La única forma de que se desactualice es que alguien cambie el precio
**dentro de Mercado Libre**, y para eso está el chequeo diario, que lo detecta y lo corrige.

**El detalle que hace segura la decisión**: `NULL` retiene. Un vínculo nuevo, o uno cuyo precio nunca
se pudo publicar, no tiene contra qué compararse — y la lectura ingenua ("no hay referencia, entonces
no supera el umbral, publicá") es exactamente la peligrosa. Falla cerrado.

---

## Decisión 2 — El corte va dentro de `enviarUno()`, no en cada llamador

**Qué se eligió**: `SincronizadorPrecios::enviarUno()` es el único lugar del sistema que hace el
`PUT /items/{id}` con un precio. Los tres caminos —el observer de cambio de precio,
`enviarPendientes()` y `sincronizarListaCompleta()`— terminan todos ahí. El evaluador se inserta
**dentro** de ese método, después de `verificarCortes()` y antes del PUT.

**Alternativa descartada — validar en cada llamador**: es justo la forma del bug del 25/08. El
observer tenía su propia copia de la lógica de resolución de lista, se desactualizó respecto de
`resolverListaPrecio()`, y por ahí se coló el error. **Duplicar una regla de seguridad en tres
lugares garantiza que algún día sean tres reglas distintas.** Un embudo único no se puede saltear
por olvido.

**Consecuencia deseada**: cualquier camino de envío que se escriba en el futuro queda cubierto sin
que su autor tenga que acordarse de nada.

**Orden respecto de los cortes existentes**: el evaluador corre **después** de `verificarCortes()`
(función desactivada / sólo lectura / conexión caída). Esos cortes conservan el pendiente para el
próximo intento válido; el corte de precio es una decisión distinta y no debe pisarlos. Los tests
de `SincronizadorPreciosTest` tienen que seguir pasando **sin modificarlos**: si hay que tocarlos, el
orden está mal.

---

## Decisión 3 — La retención es una entidad con historial

**Qué se eligió**: tabla `retenciones_precio_ml` con una fila por retención, incluidas las ya
resueltas. El vínculo apunta a la retención abierta, si tiene alguna.

**Alternativa descartada — tres columnas en `ml_publicacion_producto`** (`precio_retenido`,
`retenido_en`, `motivo_retencion`): más simple, pero pierde el historial. FR-015 pide registrar quién
aprobó o rechazó y con qué importes, y FR-031 pide que quede en el historial de operaciones. Con
columnas sueltas, aprobar una retención borra la evidencia de que existió — y la evidencia es
justamente lo que faltó para entender los dos incidentes.

**Regla de integridad**: a lo sumo **una** retención sin resolver por publicación. Una propuesta
nueva sobre una publicación ya retenida **reemplaza** a la anterior, que queda marcada como
`reemplazada` (FR-010). No se acumulan.

---

## Decisión 4 — "Retenida" y "pendiente" son estados distintos

**Qué se eligió**: `precio_pendiente` conserva su significado actual —"hay un precio que mandar y
todavía no se pudo"— y la retención es otra cosa: "hay un precio que **no se va a mandar** hasta que
alguien lo apruebe".

**Por qué no reusar `precio_pendiente`**: `enviarPendientes()` recorre los pendientes y los reintenta.
Si una retención se marcara como pendiente, el reintento la mandaría, que es precisamente lo que hay
que impedir. Serían dos significados opuestos sobre el mismo campo.

**Cómo conviven**: al retener se apaga `precio_pendiente` (no hay nada que reintentar) y se abre la
retención. Al aprobar se envía y se cierra. Al rechazar se cierra sin enviar. Una publicación
retenida **no** aparece en el reintento de pendientes.

---

## Decisión 5 — Backfill antes de activar el corte

**El problema**: el día que se active, `precio_publicado` está en `NULL` para las 270 publicaciones.
Por la Decisión 1, `NULL` retiene — o sea que **el primer cambio de precio retendría todo**. El corte
nacería inutilizable y la reacción natural sería desactivarlo.

**Qué se eligió**: un comando de backfill que consulta la API una vez, puebla `precio_publicado` en
las 270 y recién entonces se activa el corte. Es la misma operación que hace el chequeo diario, así
que no es código extra: es el chequeo corriendo por primera vez.

**Orden de rollout**, no negociable:

1. Migraciones (columnas y tabla vacías, nada cambia de comportamiento).
2. Chequeo de precios, que puebla `precio_publicado` — **de sólo lectura**, no puede romper nada.
3. Verificar en el monitoreo que las 270 tienen precio publicado conocido.
4. Recién ahí, activar el corte.

**Umbral inicial**: el valor por defecto es 20%, pero conviene arrancar mirando el panel un par de
días antes de confiar en él.

---

## Decisión 6 — El corte es sólo para bajadas

**Qué se eligió**: una subida no se retiene nunca, cualquiera sea su magnitud.

**Por qué**: el riesgo que motiva la spec es **vender barato**. Un precio de más no hace perder
dinero en una venta: hace que no se venda, lo cual es visible y reversible. Y las subidas son el
movimiento habitual en un contexto inflacionario — retenerlas convertiría cada actualización de
lista en una cola de aprobaciones y el corte terminaría desactivado por molesto.

**Lo que se pierde**: un error de escala hacia arriba (×1000) pasaría. Se acepta: es visible en el
acto porque la publicación deja de vender, y el chequeo diario lo muestra igual.

---

## Decisión 7 — La previa del cambio de lista se calcula sin llamar a la API

**Qué se eligió**: los conteos de la confirmación (US2) se calculan comparando la lista nueva contra
`precio_publicado`, que ya está en la base. Sin llamadas a Mercado Libre.

**Por qué**: la previa tiene que aparecer al instante cuando la persona aprieta Guardar. 270 llamadas
HTTP antes de mostrar un diálogo es medio minuto de espera, y el usuario lo interpretaría como que
se colgó.

**Qué se acepta**: la previa usa el precio publicado conocido, que puede estar hasta 24 horas viejo.
Para un conteo orientativo —"29 bajan, 8 quedarían retenidas"— alcanza. La decisión fina la toma el
corte, publicación por publicación, en el momento del envío real.

---

## Decisión 8 — Un vínculo sin tipo de publicación no recibe precio

**Qué se eligió**: si `listing_type_id` es `NULL`, no se publica precio; el vínculo queda pendiente
hasta que la sincronización de tipos lo complete.

**Alternativa descartada — asumir Clásica**: es el comportamiento actual y es el que abre la ventana.
Si el vínculo resulta ser Premium, se publica un 31% barato — el mismo daño del incidente del 25/08,
por otra puerta.

**Alternativa descartada — consultar el tipo a la API en ese momento**: resuelve el caso pero mete
una llamada sincrónica en el observer, que es justo lo que la Decisión 1 evita.

**Por qué esperar es aceptable**: la sincronización de tipos ya existe y corre periódicamente. Un
vínculo recién creado espera a lo sumo un ciclo. Y no publicar precio en un vínculo nuevo no rompe
nada: la publicación conserva el precio que ya tenía en Mercado Libre.

---

## Decisión 9 — El chequeo compara por tipo, y esto no es un detalle

**Qué se eligió**: cada publicación se compara contra la lista que le corresponde por su
`listing_type_id`, reusando `SincronizadorPrecios::resolverListaPrecio()`.

**Por qué está escrito como decisión**: durante el diagnóstico del 26/08 se comparó todo contra la
lista general y **las 30 Premium aparecieron como desfasadas**. Un panel que muestra 30 falsos
positivos todos los días se vuelve ruido, la gente lo ignora, y el día que aparezca uno verdadero
nadie lo va a ver. El falso positivo no es un defecto cosmético: **destruye el valor del panel**.

**Restricción**: prohibido reimplementar la resolución de lista en el chequeo. Una segunda definición
que se desactualice reproduce la causa raíz del incidente.
