# Feature Specification: Baja a Clásica sin depender de ML y cambios de tipo detectados desde Mercado Libre

**Feature Branch**: `116-ml-tipo-detectado-y-baja-clasica`

**Created**: 2026-10-28

**Status**: Draft

**Input**: User description: "Mejoras al cambio de tipo de publicación ML (Clásica ↔ Premium) y a la detección de cambios hechos desde Mercado Libre. (1) BUG: el paso a Clásica no debe depender de available_downgrades; (2) cuando se detecta que el tipo cambió desde ML, enviar automáticamente el precio de la lista del tipo nuevo, sin pasar por el corte de bajadas, y registrarlo; (3) la revisión de tipos corre colgada del cron de stock de ML, con su misma frecuencia; (4) el modal de pasar a Clásica avisa que se pierde la campaña de cuotas sin interés."

> **Funcionalidad sin informe relevado de Contagram**: Contagram no tiene integración directa con
> Mercado Libre (divergencia deliberada, `docs/documentacion_principal_crm.md §5.2`). Diseño propio,
> respetando las reglas de diseño obligatorias de `CLAUDE.md`. Extiende la spec 115
> (`specs/115-ml-cambio-tipo-publicacion/`, §3.2.ter.octies del doc principal).

## Contexto de dominio

- **Caso real del 28/10/2026**: Pompei intentó pasar la publicación MLA1578044032 (producto 27286) de
  Premium a Clásica desde Vinculaciones ML. El modal bloqueó con *"Mercado Libre no ofrece el tipo
  Clásica para esta publicación"*. Se hizo el cambio a mano desde la pantalla de Mercado Libre y
  funcionó.
- **Verificado contra la API real ese día**: la consulta de tipos disponibles para bajar
  (`available_downgrades`) devolvió una lista **vacía** para las 21 publicaciones Premium consultadas
  —con y sin ventas, con y sin campaña de cuotas, activas, pausadas y cerradas, todas del modelo
  User Products—. La consulta de tipos disponibles para subir (`available_upgrades`) sí responde bien.
  O sea: el chequeo previo de la spec 115 (FR-006) bloquea **el 100% de los pasos a Clásica** desde el
  CRM, aunque Mercado Libre los permite.
- **Cambio de tipo hecho desde Mercado Libre**: después del cambio manual la publicación quedó Clásica
  con el precio de Premium ($245.000 en vez de $207.420 de la lista general). Hoy el CRM:
  - se entera recién en la clasificación diaria de tipos (intervalo fijo de 24 hs);
  - cuando se entera, sólo actualiza el tipo guardado y **no reenvía el precio**: la publicación sigue
    con el precio del tipo anterior hasta que alguien edite ese producto;
  - el chequeo nocturno de precios (spec 084) lo muestra como desfasaje, pero tarde.
- El caso caro es el inverso: **Clásica → Premium hecho en ML** deja la publicación con precio de
  Clásica pagando la comisión de Premium (la misma pérdida del incidente del 25/08/2026).
- Cada publicación cotiza por la lista de su tipo (§3.2.ter.quinquies): Clásica → lista general "ML",
  Premium → lista "ML Premium" (≈ ML × 1,45). El corte de seguridad de bajadas (spec 084) retiene
  cualquier bajada mayor al umbral (20%); Premium → Clásica es una bajada de entre 15% y 31%.

## Clarifications

### Session 2026-10-28

Decisiones del usuario, tomadas antes de `specify`:

- Q: Un Premium → Clásica detectado desde ML baja el precio entre 15% y 31%. ¿Se retiene por el corte de seguridad si supera el 20%? → A: **No. Se manda siempre el precio.** El cambio de tipo hecho en Mercado Libre se toma como una decisión humana ya tomada. Es la tercera excepción autorizada al corte.
- Q: ¿Cada cuánto se revisa el tipo de las publicaciones? → A: **Colgado del cron de sincronización de stock de ML que ya corre**, con la misma frecuencia configurable en pantalla (hoy 15 minutos), en vez del intervalo fijo de 24 hs.

Resueltas con default razonable durante `clarify`, sin interrumpir al usuario (regla de cadena de `CLAUDE.md`):

- Q: ¿Dónde ve el usuario que el CRM detectó un cambio de tipo hecho en ML? → A: En la columna Tipo de Vinculaciones ML (ya existente), en el historial de cambios de tipo (origen "Mercado Libre") y en el log de operaciones de ML. Sin pantalla ni notificación nueva (el aviso proactivo es del módulo Notificaciones futuro).
- Q: ¿Qué resultados usa el historial para un cambio detectado? → A: Los mismos de la spec 115: `cambiado` (precio enviado), `cambiado_precio_pendiente` (ML rechazó el precio) y `sin_cambios` (el precio del tipo nuevo ya era el publicado). Lo distingue el origen, no el resultado.
- Q: ¿La revisión de tipos corre si la integración está en sólo lectura o desactivada? → A: En sólo lectura, sí lee; lo que respeta el corte es el envío de precio, y por FR-008 mientras esté bloqueado el cambio no se consolida. Con la función desactivada no lee, porque el cliente de ML bloquea toda operación (FR-005b de spec 012).
- Q: ¿Qué es la "revisión manual a demanda"? → A: El comando existente de revisión de tipos ejecutado a mano (`--forzar`); no hay botón en pantalla, ni se agrega.
- Q: ¿Se revisan también las publicaciones pausadas o cerradas? → A: Sí, todas las vinculadas, como hoy: una publicación pausada puede reactivarse con el tipo cambiado.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Pasar a Clásica desde el CRM aunque ML no lo informe como disponible (Priority: P1)

Desde Vinculaciones ML, el usuario elige "Pasar a Clásica" en una publicación Premium. El modal ya no
bloquea por la consulta de tipos disponibles: muestra la confirmación de siempre (tipo, precios, lista,
cuotas) y, al confirmar, el CRM le pide el cambio a Mercado Libre. Si ML lo acepta, la publicación
queda Clásica con el precio de la lista general. Si lo rechaza, se muestra el motivo real que devolvió
ML y no se toca nada.

**Why this priority**: es el bug que hoy deja inutilizable la mitad de la funcionalidad de la spec 115
para todas las publicaciones de la cuenta.

**Independent Test**: con respuestas simuladas de Mercado Libre donde la lista de tipos disponibles para
bajar viene vacía y el cambio se acepta, confirmar "Pasar a Clásica" y verificar que la publicación
queda Clásica con el precio de la lista general.

**Acceptance Scenarios**:

1. **Given** una publicación Premium con precio en la lista general, y ML devuelve la lista de tipos disponibles para bajar vacía, **When** el usuario abre "Pasar a Clásica", **Then** el modal permite confirmar (no muestra "Mercado Libre no ofrece el tipo Clásica").
2. **Given** esa misma publicación, **When** el usuario confirma y ML acepta el cambio, **Then** la publicación queda Clásica, con el precio de la lista general y las cuotas elegidas, igual que en la spec 115.
3. **Given** ML rechaza el cambio de tipo, **When** el usuario confirma, **Then** la publicación conserva tipo y precio, el CRM muestra el mensaje que devolvió ML y el intento queda en el historial como fallido con ese mensaje.
4. **Given** una publicación Clásica, **When** el usuario abre "Pasar a Premium" y ML no informa Premium como disponible para subir, **Then** se sigue bloqueando como hoy (el chequeo de subida no cambia).
5. **Given** un lote de "Pasar a Clásica", **When** se procesa, **Then** cada publicación sigue la misma regla: no se bloquea por la consulta de tipos disponibles; se informa como fallida si ML rechaza el cambio.

---

### User Story 2 - Precio correcto cuando el tipo se cambia desde Mercado Libre (Priority: P1)

Alguien cambia el tipo de una publicación directamente en Mercado Libre (Premium → Clásica o
Clásica → Premium). En la siguiente corrida de la sincronización, el CRM detecta que el tipo cambió,
actualiza el tipo guardado del vínculo y publica el precio de la lista que corresponde al tipo nuevo,
sin que nadie toque el producto. El evento queda en el historial de cambios de tipo como detectado en
Mercado Libre.

**Why this priority**: el sentido Clásica → Premium hecho en ML deja la publicación cobrando por debajo
de su tipo; es exactamente la pérdida del 25/08/2026 y hoy puede durar más de un día.

**Independent Test**: con una publicación guardada como Premium y respuestas simuladas donde ML la
informa como Clásica, correr la sincronización y verificar que el vínculo queda Clásica, que se envió el
precio de la lista general (aunque baje más del 20%) y que hay una fila en el historial con origen
Mercado Libre.

**Acceptance Scenarios**:

1. **Given** un vínculo guardado como Premium que ML informa como Clásica, **When** corre la sincronización, **Then** el vínculo queda Clásica y se publica el precio de la lista general, aunque la bajada supere el umbral del corte de seguridad.
2. **Given** un vínculo guardado como Clásica que ML informa como Premium y su producto tiene precio en la lista Premium, **When** corre la sincronización, **Then** el vínculo queda Premium y se publica el precio de la lista Premium.
3. **Given** un vínculo guardado como Clásica que ML informa como Premium y su producto **no** tiene precio en la lista Premium, **When** corre la sincronización, **Then** el vínculo queda Premium, se aplica la misma regla de respaldo que el resto de la sincronización de precios (lista general) y el historial lo registra con el motivo "sin precio en la lista Premium".
4. **Given** el precio de la lista del tipo nuevo es igual al publicado, **When** se detecta el cambio, **Then** se actualiza el tipo y no se envía precio.
5. **Given** el envío del precio está bloqueado en ese momento (función desactivada, modo sólo lectura, cuenta desconectada u otra sincronización de precios en curso), **When** se detecta el cambio, **Then** el tipo guardado **no** se actualiza y el cambio se vuelve a detectar en la próxima corrida, hasta que el precio se pueda enviar.
6. **Given** ML rechaza el envío del precio, **When** se detecta el cambio, **Then** el tipo guardado se actualiza igual (es el tipo real de la publicación), el precio queda pendiente de envío y el historial registra el cambio con precio pendiente y el motivo de ML.
7. **Given** un cambio de tipo hecho desde el CRM (spec 115), **When** corre la sincronización después, **Then** no se registra como cambio detectado en ML (el tipo guardado ya coincide).
8. **Given** el chequeo nocturno de precios corre después de una detección, **When** compara la publicación, **Then** ya no la informa como desfasada.

---

### User Story 3 - Detección en minutos, no en un día (Priority: P2)

La revisión del tipo de las publicaciones corre en el mismo cron que ya sincroniza el stock con
Mercado Libre y con su misma frecuencia configurable (hoy cada 15 minutos). Ya no hay un intervalo
fijo de 24 horas para el tipo.

**Why this priority**: sin esto la detección de la US2 sigue tardando hasta un día; con esto la ventana
de precio incorrecto queda en el orden de la frecuencia de stock.

**Independent Test**: con la frecuencia de sincronización en 15 minutos, cambiar el tipo en las
respuestas simuladas y verificar que la corrida siguiente de la sincronización de stock lo detecta.

**Acceptance Scenarios**:

1. **Given** la frecuencia de sincronización configurada en N minutos, **When** corre la sincronización de stock de ML, **Then** en esa misma corrida se revisan los tipos de todas las publicaciones vinculadas.
2. **Given** los tipos se revisaron hace menos de N minutos (la frecuencia configurada), **When** pasa un minuto del cron, **Then** no se vuelven a revisar, aunque el empuje de stock esté bloqueado y reintente en cada minuto (la frecuencia es la misma configurada para stock; no hay otro valor que configurar).
3. **Given** el empuje de stock falla o está bloqueado en esa corrida, **When** corre la sincronización, **Then** la revisión de tipos se ejecuta igual si la lectura es posible, y su resultado no cambia el resultado de la corrida de stock.
4. **Given** la revisión de tipos de una tanda de publicaciones falla (error de ML), **When** corre la sincronización, **Then** esas publicaciones conservan su tipo guardado y se reintentan en la corrida siguiente, sin frenar al resto.
5. **Given** la revisión manual a demanda que ya existe, **When** se ejecuta, **Then** sigue funcionando y aplica la misma detección y envío de precio.

---

### User Story 4 - Aviso de que se pierden las cuotas sin interés al pasar a Clásica (Priority: P3)

Al abrir "Pasar a Clásica" en una publicación Premium, el modal avisa que se pierden las cuotas sin
interés que tiene hoy (3, 6, 9 o 12; toda Premium tiene alguna, porque sin campaña ML le aplica 6),
nombrándolas.

**Why this priority**: evita una sorpresa comercial (la publicación deja de mostrar "cuotas sin
interés"), pero no cambia ni precios ni tipos.

**Independent Test**: abrir "Pasar a Clásica" en una publicación con 3 cuotas sin interés y verificar
que el modal muestra el aviso; abrirlo en una sin campaña y verificar que no aparece.

**Acceptance Scenarios**:

1. **Given** una publicación Premium con 3 cuotas sin interés, **When** se abre "Pasar a Clásica", **Then** el modal muestra que va a perder las 3 cuotas sin interés.
2. **Given** una publicación Premium sin campaña de cuotas (Mercado Libre le aplica por defecto 6 cuotas sin interés), **When** se abre "Pasar a Clásica", **Then** el modal avisa que va a perder las 6 cuotas sin interés.
2a. **Given** el modal de "Pasar a Premium", **When** se abre, **Then** no se muestra este aviso.
3. **Given** un lote de "Pasar a Clásica", **When** se abre la confirmación, **Then** se muestra un aviso general de que las publicaciones con cuotas sin interés las pierden.

---

### Edge Cases

- **Primera clasificación** (vínculo sin tipo guardado que ML informa por primera vez): se guarda el tipo como hoy; no se considera un "cambio detectado en ML" y no dispara envío de precio por esta feature (es el resquicio ya documentado de vínculos recién creados, §3.2.ter.quinquies).
- **Tipo fuera de alcance** (ej. Gratuita, Oro Premium): si ML informa un tipo que no es Clásica ni Premium, se guarda el tipo como hoy, pero no se envía precio ni se registra como cambio detectado.
- **Publicación pausada o cerrada** cuyo tipo cambió: se aplica la misma detección; el envío de precio respeta lo que ML admita y, si lo rechaza, queda pendiente (US2 escenario 6).
- **Retención de precio abierta** (spec 084) sobre la publicación al detectarse el cambio: queda cerrada como reemplazada, porque el precio que corresponde ahora es el de la lista del tipo nuevo.
- **Varios vínculos del mismo producto**: cada uno se evalúa por separado con su propio tipo.
- **Vínculo sin producto o producto sin precio en ninguna de las dos listas** cuyo tipo cambió en ML: se actualiza el tipo (es el real), no se envía precio y el historial lo registra como `sin_cambios` con el motivo "sin precio para publicar".
- **Lote de la spec 115 con publicaciones que ya cambiaron en ML**: cada una que ML informe ya en el tipo destino se procesa como cambio detectado (FR-011a), igual que en el cambio individual.
- **El tipo cambia dos veces entre corridas** (Premium → Clásica → Premium): el CRM sólo ve el tipo vigente; si coincide con el guardado no hay cambio.
- **Cambio de tipo desde el CRM en curso** (lote de la spec 115) mientras corre la detección: las dos operaciones no corren en paralelo sobre precios; la detección respeta el candado de la sincronización de precios (US2 escenario 5).
- **ML acepta el paso a Clásica pero rechaza las cuotas**: se mantiene la regla de la spec 115 (cambiada sin las cuotas elegidas).
- **Paso a Clásica rechazado por ML por un motivo que antes se anticipaba** (publicación de catálogo, categoría sin Clásica): ahora se informa con el mensaje de ML después de confirmar, en vez de antes.

## Requirements *(mandatory)*

### Functional Requirements

**Paso a Clásica desde el CRM (corrige spec 115 FR-006)**

- **FR-001**: Al pasar una publicación a Clásica (individual o en lote), el sistema MUST NOT bloquear el cambio en base a la consulta de tipos disponibles para bajar que informa Mercado Libre. El resto de los chequeos previos de la spec 115 se mantienen (tipo vigente consultado a ML, tipo dentro de alcance, precio en la lista destino, cortes, candado, cuotas admitidas).
- **FR-002**: Si Mercado Libre rechaza el cambio de tipo, el sistema MUST conservar tipo y precio de la publicación, mostrar el mensaje que devolvió ML y registrar el intento como fallido con ese mensaje (regla vigente de la spec 115 FR-010, que ahora pasa a ser la vía normal de informar que ML no admite el cambio).
- **FR-003**: Al pasar a Premium, el sistema MUST seguir verificando que Mercado Libre informa Premium como disponible para subir, como hoy.

**Cambio de tipo detectado desde Mercado Libre**

- **FR-004**: Cuando la revisión de tipos encuentra un vínculo cuyo tipo guardado es Clásica o Premium y Mercado Libre informa el otro de los dos, el sistema MUST tratarlo como **cambio de tipo detectado en Mercado Libre**.
- **FR-005**: Ante un cambio detectado, el sistema MUST publicar el precio que corresponde al tipo nuevo según la regla única de selección de lista de la sincronización de precios (lista Premium si es Premium y el producto tiene precio ahí; si no, la lista general). Si ese precio es igual al publicado, no envía precio.
- **FR-006**: El envío de precio de un cambio detectado MUST publicarse **sin pasar por el corte de seguridad de bajadas** (spec 084), aunque la bajada supere el umbral. Es la **tercera excepción autorizada** al corte, junto con aprobar una retención y confirmar un cambio de tipo desde el CRM, y está acotada a este caso: sólo cuando Mercado Libre informó en esa misma corrida un tipo distinto del guardado, entre Clásica y Premium. Ningún otro camino automático puede usarla.
- **FR-007**: Si había una retención de precio abierta para esa publicación, MUST quedar cerrada como reemplazada cuando se publica el precio del cambio detectado.
- **FR-008**: Si en ese momento no se puede enviar el precio (función desactivada, modo sólo lectura, cuenta desconectada, otra sincronización de precios en curso), el sistema MUST NOT actualizar el tipo guardado del vínculo, para que el cambio se vuelva a detectar en la corrida siguiente. No se registra en el historial hasta que se pueda procesar.
- **FR-009**: Si el envío se intenta y Mercado Libre lo rechaza, el sistema MUST actualizar igual el tipo guardado (es el tipo real), dejar el precio pendiente de envío y registrar el cambio como "cambiado con precio pendiente" con el motivo.
- **FR-010**: Cada cambio detectado y procesado MUST quedar en el historial de cambios de tipo con: publicación, producto, tipo anterior y nuevo, precio anterior y nuevo, lista, resultado (`cambiado`, `cambiado_precio_pendiente` o `sin_cambios`), motivo, usuario (**ninguno** cuando lo detecta la revisión de tipos; quien confirmó cuando se detecta al confirmar un cambio desde el CRM, FR-011a) y con un **origen** que lo distinga de los cambios hechos desde el CRM. Los registros existentes y los cambios desde el CRM quedan con origen "CRM".
- **FR-010a**: La lectura del tipo vigente MUST ejecutarse siempre que haya una cuenta de ML conectada y la función ML esté activa, aunque la integración esté en sólo lectura; el modo sólo lectura sólo posterga el envío de precio (FR-008). Con la función desactivada no se consulta: la regla vigente (FR-005b, spec 012) bloquea en ese caso también las lecturas. *(Corregido durante la implementación: la versión anterior pedía leer también con la función desactivada, que contradecía esa regla.)*
- **FR-011**: Un cambio de tipo hecho desde el CRM (spec 115) MUST NOT volver a registrarse como cambio detectado en Mercado Libre.
- **FR-011a**: Las consultas que hace el cambio de tipo desde el CRM (spec 115) al tipo vigente MUST NOT "consumir" un cambio hecho en ML sin enviar su precio. Abrir la confirmación no consolida el tipo nuevo en el vínculo cuando es un cambio entre Clásica y Premium; y si al confirmar el tipo vigente ya es el destino ("sin cambios"), el sistema MUST procesarlo como un cambio detectado en ML (FR-005..FR-010): actualiza el tipo y publica el precio del tipo vigente. Hoy ese caso actualizaba el tipo sin enviar precio.

**Frecuencia de la revisión de tipos**

- **FR-012**: La revisión de tipos de todas las publicaciones vinculadas MUST ejecutarse dentro de la corrida de sincronización de stock de Mercado Libre, a lo sumo una vez por cada período de su misma frecuencia configurable (medido desde la última revisión de tipos, para que un empuje de stock bloqueado que reintenta cada minuto no multiplique las consultas), y MUST dejar de depender del intervalo fijo de 24 horas.
- **FR-013**: La revisión de tipos MUST ejecutarse aunque el empuje de stock de esa corrida haya fallado o esté bloqueado (siempre que la lectura hacia ML sea posible), y su resultado MUST NOT cambiar el resultado informado por la corrida de stock.
- **FR-014**: Una falla de Mercado Libre al revisar una tanda de publicaciones MUST conservar el tipo guardado de esas publicaciones y MUST NOT frenar a las demás tandas (regla vigente).
- **FR-015**: La ejecución manual a demanda de la revisión de tipos (el comando existente, forzado a mano; sin botón en pantalla) MUST seguir disponible y aplicar la misma detección y envío de precio.
- **FR-015a**: La revisión MUST abarcar todas las publicaciones vinculadas, cualquiera sea su estado en ML (activas, pausadas, cerradas, en revisión), como hoy.

**Aviso de cuotas**

- **FR-016**: La confirmación individual de "Pasar a Clásica" de una publicación Premium MUST avisar que se pierden las cuotas sin interés que tiene hoy, nombrándolas (sin campaña, las 6 cuotas que ML aplica por defecto a Premium).
- **FR-017**: La confirmación de un lote de "Pasar a Clásica" MUST mostrar un aviso general de que las publicaciones con cuotas sin interés las pierden.

### Key Entities

- **Vínculo publicación ↔ producto** (existente): guarda el tipo de publicación informado por ML y cuándo se clasificó; ahora también se actualiza a partir de un cambio detectado en ML, sólo si el precio se pudo procesar (FR-008).
- **Historial de cambios de tipo** (existente, spec 115): suma el **origen** del cambio (desde el CRM / detectado en Mercado Libre); los detectados quedan sin usuario.
- **Listas de precios de Mercado Libre** (existentes): general y Premium; definen el precio del tipo nuevo.
- **Retención de precio** (existente, spec 084): se cierra como reemplazada ante un cambio detectado que publica precio.
- **Configuración de la integración** (existente): la frecuencia de sincronización de stock pasa a gobernar también la revisión de tipos.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: El usuario puede pasar a Clásica desde el CRM cualquier publicación Premium que Mercado Libre admita cambiar desde su propia pantalla; cero bloqueos por "Mercado Libre no ofrece el tipo Clásica" cuando ML acepta el cambio.
- **SC-002**: Tras un cambio de tipo hecho desde Mercado Libre, la publicación queda con el precio de la lista de su tipo nuevo dentro de una frecuencia de sincronización (hoy 15 minutos), sin intervención humana, en el 100% de los casos en que el envío no está bloqueado.
- **SC-003**: Cero publicaciones Premium con precio de Clásica originadas por un cambio de tipo hecho en ML que dure más de una frecuencia de sincronización (salvo bloqueos de la integración, que se reintentan en cada corrida).
- **SC-004**: El chequeo nocturno de precios no informa desfasajes atribuibles a cambios de tipo hechos en ML que ya fueron detectados.
- **SC-005**: Cada cambio detectado queda en el historial con su origen, sin necesidad de reconstruirlo desde los logs.
- **SC-006**: El usuario ve, antes de confirmar un paso a Clásica, si la publicación pierde una campaña de cuotas sin interés.

## Assumptions

- La consulta de tipos disponibles para bajar de ML no es confiable para esta cuenta (modelo User Products): se asume que el cambio de tipo por la API admite el paso a Clásica igual que la pantalla de ML. **No verificado** contra la API real (sólo se puede comprobar con un cambio real en producción); por eso FR-002 hace que un rechazo se informe con el mensaje de ML y no deje nada a medias. La primera prueba real requiere OK explícito del usuario (memoria "nunca probar en producción").
- Revisar todos los tipos en cada corrida de stock cuesta del orden de 14 consultas a ML por corrida (≈280 vínculos en tandas de 20), aceptable para la cuota de la API.
- El precio del cambio detectado usa la misma regla con respaldo de la sincronización de precios (spec 050 FR-008), no la versión estricta del cambio desde el CRM: si no hay precio Premium, cae en la lista general, que es lo mismo que haría cualquier envío de precio posterior. Se registra el motivo para que se vea.
- Permisos: no hay pantalla nueva ni acción nueva para el usuario; el aviso de cuotas vive en el modal existente.
- Pruebas: locales con respuestas simuladas de Mercado Libre; en producción sólo lectura.
- Fuera de alcance: una pantalla para ver el historial de cambios de tipo (hoy sólo vive en la base), avisos proactivos (módulo Notificaciones futuro), la primera clasificación de vínculos nuevos y Tiendanube.
