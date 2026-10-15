# Feature Specification: Cambio de tipo de publicación de Mercado Libre (Clásica ↔ Premium)

**Feature Branch**: `115-ml-cambio-tipo-publicacion`

**Created**: 2026-10-15

**Status**: Draft

**Input**: User description: "Cambiar el tipo de publicación de Mercado Libre (Clásica ↔ Premium) desde la pantalla Vinculaciones ML del CRM, en ambos sentidos; bloquear el paso a Premium si el producto no tiene precio en la lista Premium; acción individual y masiva con confirmación que avise la comisión mayor de Premium; columna Tipo con filtro; y que la tabla identifique claramente el producto del CRM vinculado (ID y código)."

> **Funcionalidad nueva sin informe relevado de Contagram**: Contagram no tiene integración directa con
> Mercado Libre (divergencia deliberada documentada en `docs/documentacion_principal_crm.md §5.2`).
> Diseño propio, respetando las reglas de diseño obligatorias de `CLAUDE.md`.

## Contexto de dominio

- Mercado Libre tiene dos tipos de publicación pagos en Argentina: **Clásica** y **Premium**. Premium
  da más exposición y cuotas sin interés, y cobra **más comisión por cada venta**. Cambiar de un tipo
  al otro **no tiene costo** y se puede hacer en cualquier momento, en los dos sentidos.
- El CRM ya publica cada vínculo con el precio de **su** lista según su tipo (spec 050, §3.2.ter.quinquies):
  Clásica → lista "ML", Premium → lista "ML Premium" (≈ ML × 1,45). Por eso un cambio de tipo es
  también, siempre, un cambio de precio: cambiar el tipo sin cambiar el precio deja la publicación
  mal cotizada (el incidente del 25/08/2026 fueron 18 Premium publicadas un 31% por debajo).
- El corte de seguridad de bajadas de precio (spec 084, §3.2.ter.septies) retiene cualquier bajada
  mayor al umbral (20% por defecto) hasta que una persona la apruebe. Pasar de Premium a Clásica
  es, por definición, una bajada de ≈31%.
- Un producto del CRM puede tener varias publicaciones vinculadas (spec 036), cada una con su propio tipo.

## Clarifications

### Session 2026-10-15

Resueltas con default razonable, sin interrumpir al usuario (regla de cadena de `CLAUDE.md`); las 4
decisiones de alcance las tomó el usuario antes de `specify`.

- Q: ¿El lote se procesa en el mismo request o en segundo plano? → A: En segundo plano, con estado consultable y resumen al terminar (mismo patrón que la Sincronización forzada); cada publicación son varias llamadas a Mercado Libre y 50 no entran en un request.
- Q: ¿La selección masiva abarca todas las páginas o sólo la visible? → A: Sólo las filas visibles en la página actual (con "seleccionar todas las de esta página"); la selección se limpia al cambiar de página o filtro.
- Q: ¿Quién puede ejecutar el cambio? → A: Quien ya puede operar Vinculaciones ML (mismo permiso de la pantalla y de aprobar retenciones de precio); sin permiso nuevo.
- Q: ¿Qué se informa como "precio que va a quedar" si la publicación no tiene precio publicado conocido? → A: Se muestra el precio destino y "Desconocido" como actual; no bloquea, porque la confirmación humana ya cubre la falta de referencia del corte de spec 084.
- Q: ¿Una publicación con otro tipo (ej. Gratuita) se puede pasar a Clásica/Premium? → A: No, queda fuera de alcance: se muestra su tipo y no ofrece la acción.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Pasar una publicación de Clásica a Premium (o al revés) (Priority: P1)

Desde Vinculaciones ML, el usuario abre el menú de una publicación y elige "Pasar a Premium" (o
"Pasar a Clásica", según el tipo actual). Se abre un modal que muestra la publicación, el producto
del CRM, el tipo actual y el nuevo, el precio publicado hoy y el precio que va a quedar (el de la
lista que corresponde al nuevo tipo) y, al pasar a Premium, el aviso de que Premium cobra más
comisión por venta. Al confirmar, el CRM cambia el tipo en Mercado Libre, publica el precio que
corresponde y la fila se actualiza en el lugar.

**Why this priority**: es lo que pidió el cliente y entrega todo el valor por sí sola.

**Independent Test**: con una publicación Clásica vinculada a un producto que tiene precio en las dos
listas, ejecutar "Pasar a Premium" y verificar en Mercado Libre que la publicación quedó Premium con
el precio de la lista Premium, y en el CRM que el menú de la fila ofrece ahora "Pasar a Clásica" y el precio figura sincronizado.

**Acceptance Scenarios**:

1. **Given** una publicación Clásica cuyo producto tiene precio en la lista Premium, **When** el usuario
   confirma "Pasar a Premium", **Then** la publicación queda Premium en Mercado Libre, con el precio de
   la lista Premium, y la fila se actualiza sin recargar la página (su menú ofrece ahora "Pasar a Clásica").
2. **Given** una publicación Premium cuyo producto tiene precio en la lista general, **When** el usuario
   confirma "Pasar a Clásica", **Then** la publicación queda Clásica, con el precio de la lista general,
   aunque esa bajada supere el umbral del corte de seguridad (la persona ya vio ambos importes en la
   confirmación).
3. **Given** una publicación Clásica cuyo producto **no** tiene precio en la lista Premium (o no hay lista
   Premium configurada), **When** el usuario intenta "Pasar a Premium", **Then** el CRM no permite
   confirmar, explica el motivo y no envía nada a Mercado Libre.
4. **Given** el modal de confirmación de "Pasar a Premium", **When** se abre, **Then** muestra el aviso
   de que Premium cobra más comisión por venta.
5. **Given** Mercado Libre rechaza el cambio de tipo, **When** el usuario confirma, **Then** la publicación
   conserva su tipo y su precio anteriores en Mercado Libre, el CRM muestra el motivo en una notificación
   y el vínculo no queda marcado con un tipo que no tiene.

---

### User Story 2 - Ver el tipo y el producto del CRM en la tabla de Vinculaciones (Priority: P2)

La tabla de Vinculaciones ML muestra una columna "Tipo" (Clásica / Premium / Sin clasificar) con un
filtro por tipo en el panel de filtros, y la columna del producto identifica sin ambigüedad el
producto del CRM vinculado: su ID y su código, además del nombre.

**Why this priority**: sin ver el tipo no se sabe qué publicaciones conviene cambiar; sin ver el
ID/código del producto del CRM no se puede verificar a qué producto se le está cambiando el precio.
Además es el prerrequisito natural del cambio masivo.

**Independent Test**: abrir Vinculaciones ML, filtrar por "Premium" y verificar que sólo quedan las
publicaciones Premium y que cada fila muestra ID y código del producto del CRM.

**Acceptance Scenarios**:

1. **Given** publicaciones de los dos tipos, **When** el usuario filtra por "Clásica", **Then** la tabla muestra sólo las Clásicas, y el filtro persiste al recargar (igual que el resto del estado de la tabla).
2. **Given** una publicación todavía no clasificada por la sincronización de tipos, **When** se muestra, **Then** su tipo figura como "Sin clasificar" y se puede filtrar por ese valor.
3. **Given** cualquier vínculo, **When** se muestra la fila, **Then** se ve el ID del producto del CRM, su código y su nombre.
4. **Given** el usuario busca por ID o código de producto del CRM en el buscador de la tabla, **When** escribe el valor, **Then** la fila del vínculo aparece.

---

### User Story 3 - Cambiar el tipo de varias publicaciones juntas (Priority: P3)

El usuario selecciona varias publicaciones con casillas en la tabla y elige "Pasar a Premium" o
"Pasar a Clásica" para la selección. El modal de confirmación lista, por cada publicación, el
cambio y los precios antes/después, y separa las que **no se pueden** cambiar con su motivo (ya son
del tipo destino, no tienen precio en la lista destino). Al confirmar se procesan las que sí se
pueden —verificando cada una contra Mercado Libre— y se muestra un resumen: cuántas se cambiaron,
cuántas no hacía falta cambiar y cuántas se bloquearon o fallaron, y por qué.

**Why this priority**: ahorra tiempo cuando el cliente decide pasar un grupo entero, pero el caso
individual ya resuelve la necesidad.

**Independent Test**: seleccionar tres publicaciones Clásicas (dos con precio Premium y una sin) y
"Pasar a Premium": el modal anticipa que una no se puede; al confirmar, dos quedan Premium y el
resumen informa la tercera como omitida.

**Acceptance Scenarios**:

1. **Given** una selección mixta, **When** se abre la confirmación, **Then** se listan por separado las que se van a cambiar y las que no, con el motivo de cada una.
2. **Given** la confirmación de un lote, **When** una publicación falla en Mercado Libre, **Then** las demás se procesan igual y el resumen informa la fallida con su motivo.
3. **Given** ninguna publicación de la selección se puede cambiar, **When** se abre la confirmación, **Then** no se puede confirmar.
4. **Given** el lote terminó, **When** se cierra el resumen, **Then** la tabla se refresca en el lugar conservando filtros y página.

---

### Edge Cases

- **Publicación sin clasificar** (`listing_type` desconocido): no se ofrece el cambio hasta conocer el tipo; el CRM lo consulta a Mercado Libre al abrir la confirmación y, si lo obtiene, lo guarda y sigue.
- **Tipo desactualizado en el CRM**: el CRM dice Clásica pero en Mercado Libre ya es Premium (alguien la cambió desde ML). Al abrir la confirmación se consulta el tipo vigente; si ya es del tipo destino, se actualiza el vínculo y se informa que no había nada que cambiar.
- **Tipo no disponible para esa publicación**: Mercado Libre no ofrece el destino para ese ítem (categoría, estado, otro tipo como "Gratuita"). No se permite confirmar y se muestra el motivo.
- **Publicación Full**: el cambio de tipo es independiente de la logística; se permite igual.
- **Producto sin precio en la lista general** al pasar a Clásica: se bloquea con el mismo criterio que Premium (no se cambia el tipo si no hay precio que publicar para el destino).
- **Precio destino = precio publicado**: se cambia sólo el tipo, sin enviar precio.
- **Falla del envío de precio después de cambiar el tipo** (o viceversa): ver FR-008; la publicación nunca queda por debajo del precio de su tipo.
- **Retención de precio abierta** (spec 084) sobre la publicación: el cambio de tipo la reemplaza, porque el precio resultante sale de la lista del nuevo tipo y la persona lo confirmó viéndolo.
- **Otra sincronización de precios en curso** (forzada, observer, pendientes): el cambio espera o se rechaza con aviso; nunca corre en paralelo sobre la misma publicación.
- **Función ML desactivada / modo sólo lectura / cuenta desconectada**: la acción se rechaza con el mismo mensaje que el resto de las escrituras hacia Mercado Libre y no se envía nada.
- **Publicación pausada, cerrada o en revisión**: se respeta lo que Mercado Libre admita; si rechaza, se informa el motivo (ver FR-006).

## Requirements *(mandatory)*

### Functional Requirements

**Cambio de tipo**

- **FR-001**: El sistema MUST permitir cambiar el tipo de una publicación vinculada de Clásica a Premium y de Premium a Clásica, desde Vinculaciones ML.
- **FR-002**: El menú de fila de cada vínculo MUST ofrecer sólo la acción que corresponde a su tipo actual ("Pasar a Premium" si es Clásica, "Pasar a Clásica" si es Premium); para vínculos sin clasificar ofrece "Cambiar tipo", que consulta el tipo real a Mercado Libre, lo guarda y propone como destino el tipo opuesto.
- **FR-003**: Antes de confirmar, el sistema MUST mostrar en un modal: publicación (ID y título), producto del CRM (ID, código, nombre), tipo actual y destino, precio publicado actual y precio que va a quedar, y la lista de precios de la que sale.
- **FR-004**: Al pasar a Premium, la confirmación MUST avisar que Premium cobra más comisión por venta que Clásica.
- **FR-005**: El sistema MUST bloquear el cambio cuando el producto vinculado no tiene precio cargado en la lista de precios que corresponde al tipo destino (lista Premium para Premium, lista general para Clásica), o cuando esa lista no está configurada. El bloqueo se explica en el modal y no se envía nada a Mercado Libre.
- **FR-006**: Antes de confirmar, el sistema MUST verificar contra Mercado Libre el tipo vigente de la publicación y que el tipo destino esté disponible para ella; si no está disponible, bloquea con el motivo; si ya es del tipo destino, actualiza el vínculo y no envía nada.
- **FR-007**: Tras un cambio exitoso, el sistema MUST dejar el vínculo con el nuevo tipo registrado de inmediato (sin esperar la sincronización diaria de tipos) y publicado con el precio de la lista que le corresponde al nuevo tipo.
- **FR-008**: El orden de las operaciones MUST garantizar que la publicación **nunca quede, ni siquiera transitoriamente, con un precio menor al de su tipo**: al pasar a Premium, primero se sube el precio y después se cambia el tipo; al pasar a Clásica, primero se cambia el tipo y después se baja el precio. Si el segundo paso falla, el sistema MUST: (a) al pasar a Premium, restituir el precio anterior a la publicación, que sigue Clásica (si no había precio anterior conocido, el precio queda pendiente de envío por la lista general, sujeto al corte de spec 084, y se informa); (b) al pasar a Clásica, dejar el precio pendiente de envío, informándolo (la publicación queda Clásica con precio de Premium: cobra de más, no de menos).
- **FR-009**: La bajada de precio de un "Pasar a Clásica" confirmado MUST publicarse aunque supere el umbral del corte de seguridad (spec 084), porque una persona vio y confirmó ambos importes. Ningún camino automático puede usar esta excepción. Si había una retención de precio abierta para esa publicación, queda cerrada como reemplazada.
- **FR-010**: Si Mercado Libre rechaza el cambio de tipo, el vínculo MUST conservar su tipo y su precio anteriores y el sistema MUST mostrar el motivo devuelto en una notificación.
- **FR-011**: La acción MUST respetar los mismos cortes que el resto de las escrituras hacia Mercado Libre (función desactivada, modo sólo lectura, cuenta desconectada) y MUST NOT correr en paralelo con otra sincronización de precios.
- **FR-012**: Cada intento de cambio (exitoso, sin cambios, bloqueado, fallido o revertido) MUST quedar registrado en un historial propio de cambios de tipo, con usuario, publicación, producto, tipo anterior y destino, precio anterior y nuevo, resultado y motivo. Las llamadas a Mercado Libre quedan además en el log de operaciones existente.

**Cambio masivo**

- **FR-013**: La tabla MUST permitir seleccionar varias publicaciones con casillas y aplicar "Pasar a Premium" o "Pasar a Clásica" a la selección.
- **FR-014**: La confirmación del lote MUST listar por separado las publicaciones que se van a cambiar (con precio antes/después) y las **omitidas** con su motivo, y MUST NOT permitir confirmar si no queda ninguna para cambiar. Las omitidas son las que se pueden anticipar con datos del CRM (ya son del tipo destino según el CRM, sin precio en la lista destino, lista no configurada, sin producto, tipo fuera de alcance, cortes). Lo que sólo Mercado Libre puede decir (tipo vigente distinto, destino no disponible, rechazo) se verifica al procesar cada publicación y aparece en el resumen como **sin cambios**, **bloqueada** o **fallida**.
- **FR-015**: El lote MUST procesar cada publicación de forma independiente (la falla de una no frena a las demás), aplicando a cada una las mismas reglas que el cambio individual, y al terminar MUST mostrar un resumen con cambiadas, omitidas y fallidas con su motivo.
- **FR-015a**: El lote MUST ejecutarse en segundo plano; la pantalla consulta su estado sin recargar y muestra el resumen al terminar. No puede haber dos lotes de cambio de tipo en curso a la vez.
- **FR-015b**: La selección abarca sólo las filas visibles de la página actual (con opción "seleccionar todas las de esta página"), hasta 100 publicaciones por lote, y se limpia al cambiar de página, filtro o búsqueda.
- **FR-015c**: Si una publicación no tiene precio publicado conocido, la confirmación MUST mostrarlo como "Desconocido" junto al precio destino; esto no bloquea el cambio.

**Tabla de Vinculaciones**

- **FR-016**: La tabla MUST mostrar una columna "Tipo" con los valores Clásica, Premium o Sin clasificar.
- **FR-017**: El panel de filtros MUST incluir un filtro por tipo (Todos / Clásica / Premium / Sin clasificar), resuelto del lado del servidor y persistido con el estado de la tabla.
- **FR-018**: La columna del producto MUST identificar el producto del CRM con su ID, su código y su nombre, y el buscador de la tabla MUST encontrar un vínculo por ID o código del producto del CRM.

### Key Entities

- **Vínculo publicación ↔ producto** (existente): ya guarda el tipo de publicación informado por Mercado Libre y la fecha en que se clasificó; esta feature lo actualiza también al cambiar el tipo desde el CRM.
- **Listas de precios de Mercado Libre** (existentes): la general (Clásica) y la Premium, configuradas en la integración; definen el precio destino del cambio.
- **Historial de cambios de tipo** (nuevo): un registro por intento —publicación, producto, tipo anterior y destino, precios, lista, resultado, motivo, lote y usuario—.
- **Log de operaciones de Mercado Libre** (existente): sigue registrando cada llamada a la API.
- **Retención de precio** (existente, spec 084): se cierra como reemplazada si el cambio de tipo publica un precio nuevo.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: El usuario cambia el tipo de una publicación desde el CRM en menos de 30 segundos, sin entrar a Mercado Libre.
- **SC-002**: El 100% de las publicaciones cambiadas desde el CRM quedan publicadas con el precio de la lista de su nuevo tipo (verificable con el chequeo diario CRM ↔ API de precios, sin desfasajes nuevos atribuibles a esta feature).
- **SC-003**: Cero casos de publicaciones Premium con precio de Clásica originados por esta feature, incluso ante fallas a mitad del proceso.
- **SC-004**: Un lote de 50 publicaciones se procesa y reporta en menos de 2 minutos, informando cada omitida o fallida con su motivo.
- **SC-005**: Desde la tabla, el usuario identifica el producto del CRM de cualquier vínculo (ID y código) y filtra por tipo sin salir de la pantalla.

## Assumptions

- El cambio de tipo no tiene costo para el vendedor (documentación de ML Argentina); la diferencia económica real es la comisión por venta, que se avisa pero el CRM no calcula.
- Sólo se contemplan Clásica (`gold_special`) y Premium (`gold_pro`); otros tipos (Gratuita) quedan fuera: una publicación con otro tipo figura con ese tipo y no ofrece la acción.
- Permiso: alcanza con el mismo permiso que ya habilita Vinculaciones ML y la aprobación de retenciones de precio (ver pantalla); no se crea un permiso nuevo.
- El precio destino se toma de la misma regla única que ya decide qué lista corresponde a cada vínculo (spec 050), evaluada con el tipo destino.
- Las pruebas funcionales contra Mercado Libre se hacen fuera de la cuenta real del cliente (usuario de prueba de ML o local con respuestas simuladas); en producción sólo validación de lectura (memoria "nunca probar en producción").
- Fuera de alcance: crear publicaciones nuevas en Mercado Libre desde un producto (feature aparte, conversada con el cliente) y el cambio de tipo desde la pantalla de Productos.
