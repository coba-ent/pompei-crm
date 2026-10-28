# Research: Baja a Clásica y cambios de tipo detectados desde Mercado Libre (spec 116)

## R1 — `available_downgrades` no sirve para esta cuenta

- **Evidencia (28/10/2026, producción, solo lectura)**: `GET /items/{id}/available_downgrades` devolvió
  `[]` (HTTP 200, sin error) para MLA1578044032 y para otras 20 publicaciones Premium. La muestra cubría
  publicaciones con ventas (69, 53, 24…) y sin ventas, con y sin `3x_campaign`, y en estado activo,
  pausado, cerrado y en revisión. Todas tenían el tag `user_product_listing`. En la misma corrida,
  `available_upgrades` de MLA1578044032 (ya Clásica) devolvió `[{gold_pro}, {gold_premium}]`. El cambio
  manual desde la pantalla de ML funcionó.
- **Decision**: el paso a Clásica no consulta `available_downgrades`. El rechazo, si lo hay, sale del
  `POST /items/{id}/listing_type`, que `cambiarTipo()` ya informa con `mensajeError` de ML. La subida
  conserva `available_upgrades`, que sí responde.
- **Rationale**: una consulta que devuelve vacío siempre no protege nada, solo bloquea. El orden
  "cambiar el tipo → bajar el precio" de la spec 115 (FR-008b) sigue cubriendo el caso de un rechazo: si
  el POST falla no se tocó nada.
- **Alternatives considered**: (a) Tratar `[]` como "sin información" y seguir solo en ese caso. Es
  equivalente en la práctica y agrega una rama que nunca toma el otro camino. (b) Consultar
  `/items/{id}/listing_types`. No está documentado para disponibilidad por ítem.
- **Riesgo no verificado**: que el POST acepte la baja por API igual que la pantalla. Solo se comprueba
  con un cambio real. La primera prueba en producción requiere OK explícito.

## R2 — Dónde vive la detección

- **Decision**: `SincronizadorTiposPublicacion::consultarYPersistir()` separa, en cada multiget, los
  vínculos cuyo tipo guardado ∈ {Clásica, Premium} y cuyo tipo vigente es el otro de los dos. Esos no se
  actualizan en el `update()` masivo de `listing_type_id`: los demás campos (logística, inventario,
  user product) sí se actualizan. Al final de la tanda, cada uno pasa por
  `ProcesadorCambioTipoDetectado::procesar()`. Los demás casos (primera clasificación, tipos fuera de
  alcance, sin cambios) siguen como hoy.
- **Rationale**: el multiget ya trae `listing_type_id`. Detectar ahí no cuesta llamadas nuevas, y el
  mismo servicio sirve al comando manual (FR-015).
- **Alternatives considered**: (a) Observer sobre `listing_type_id`. El `update()` masivo no dispara
  eventos de Eloquent, y además un observer escribiría precios desde cualquier camino que toque el tipo.
  (b) Notificaciones de ML (webhook `items`). No hay infraestructura de webhooks en el CRM; queda para el
  módulo Notificaciones futuro.

## R3 — Tercera excepción al corte de seguridad (spec 084)

- **Decision**: `ProcesadorCambioTipoDetectado` envía con `enviarUno($vinculo, $precio, omitirCorte: true)`.
  La excepción se acota en el código y en la doc a:
  1. el tipo vigente lo informó ML en esa misma corrida (multiget o `GET /items/{id}` de la confirmación);
  2. es distinto del guardado y los dos ∈ {gold_special, gold_pro};
  3. el precio es el de `resolverListaPrecio()` evaluada con el tipo nuevo, sin otro origen.
  El docblock de `enviarUno()` y §3.2.ter.septies pasan de "dos únicas" a "tres" excepciones, nombradas.
- **Rationale**: decisión del usuario (Clarifications). Premium → Clásica es siempre una baja de entre 15%
  y 31%. Con el corte, la mayoría quedaría retenida y la publicación seguiría con el precio Premium. El
  riesgo que protege el corte (un precio absurdo de la lista) no aumenta: el precio sale de la misma
  lista que la sincronización normal, y la baja es la diferencia conocida entre listas.
- **Alternatives considered**: respetar el corte (descartado por el usuario); un umbral propio para este
  caso (complejidad sin pedido).

## R4 — FR-008: no consolidar el tipo si no se puede enviar el precio

- **Decision**: los cambios detectados se **acumulan** durante todas las tandas del multiget. Recién al
  final de la revisión se verifica `motivoBloqueoEscritura()` y se toma
  `Cache::lock(SincronizadorPrecios::LOCK_KEY, 300)->get()` (no bloqueante), solo para procesarlos. Así
  el candado de precios no queda tomado durante las ~14 consultas, que bloquearían al observer de
  precios y a la confirmación de la spec 115 (espera 10 s). Si hay corte o el candado está tomado, esos vínculos **no** se tocan: ni tipo ni historial.
  Queda una línea en el log de operaciones, en vez de una fila de historial por vínculo. La próxima
  revisión los vuelve a detectar. Con el candado tomado, el procesador actualiza el tipo y después
  intenta el precio: si ML rechaza, el tipo queda (es el real) y `enviarUno()` ya dejó
  `precio_pendiente = true` y `precio_error` (FR-009).
- **Rationale**: si se consolidara el tipo sin el precio, el cambio dejaría de detectarse y el precio solo
  se arreglaría cuando alguien edite el producto, que es el bug original. Un pendiente de
  `enviarPendientes()` además pasaría por el corte y quedaría retenido.
- **Alternatives considered**: consolidar y dejar el pendiente (descartado por lo anterior); esperar el
  candado con `block()`. La revisión corre en el cron, que tiene `withoutOverlapping`, y esperar un
  candado de una sincronización forzada larga arrastraría el minuto.

## R5 — Camino "sin cambios" de la confirmación (FR-011a)

- **Hallazgo**: hoy `previsualizar()` escribe `listing_type_id` cuando el de ML difiere del guardado
  (líneas 88-90). Si en ML ya es el destino, `ejecutar()` registra `sin_cambios` **sin enviar precio**.
  Abrir el modal sobre una publicación cambiada en ML "consume" el cambio y deja el precio del tipo
  anterior: el mismo bug, por otro camino.
- **Decision**: `previsualizar()` solo persiste el tipo cuando el guardado es `null` (primera
  clasificación) o está fuera de alcance. Para un Clásica ↔ Premium devuelve el tipo vigente sin
  guardarlo. En `ejecutarConCandado()`, el caso `sin_cambios` llama a
  `ProcesadorCambioTipoDetectado::procesar($vinculo, $tipoVigente, usuarioId: $usuarioId)`, ya con el
  candado tomado. Registra una sola fila (origen `mercadolibre`, con usuario) y responde `sin_cambios`
  con el mensaje del procesador.
- **Rationale**: un solo lugar decide qué pasa cuando el tipo cambió fuera del CRM.

## R6 — Frecuencia: colgado del cron de stock

- **Decision**:
  - `SincronizarStockMercadoLibre::handle()` llama, al final y en **todos** los caminos (incluso cuando
    el stock no corresponde, está bloqueado o salteado), a
    `SincronizadorTiposPublicacion::sincronizarSiCorresponde()`. Ese método compara
    `tipo_publicacion_ultima_sync_en` contra `frecuencia_sync_minutos` y toma un candado propio
    `ml:sincronizar_tipos` (`get()` no bloqueante) para no solaparse con una ejecución manual.
  - El resultado se informa por la salida y no cambia el exit code del comando de stock (FR-013).
  - Se quita de `bootstrap/app.php` el schedule de `mercadolibre:sincronizar-tipos-publicacion`. El
    comando queda para uso manual, y su `correspondeEjecutar()` pasa de 24 h a `frecuencia_sync_minutos`
    (`--forzar` lo saltea).
  - La revisión necesita cuenta conectada para leer: si no hay, no consulta.
- **Rationale**: es lo que pidió el usuario ("aprovechar el cron que ya ejecutamos"). El freno propio es
  imprescindible porque `stock_ultima_sync_en` **no** se actualiza cuando el stock está bloqueado: con la
  integración en sólo lectura el comando de stock entra cada minuto, y sin freno serían ~14 GET por
  minuto.
- **Alternatives considered**: revisar dentro de `SincronizadorStock::sincronizar()` (solo corre cuando
  el stock no está bloqueado, y FR-013 pide que corra igual); un schedule propio con
  `everyFifteenMinutes()` (un segundo reloj que no sigue la frecuencia de la pantalla).
- **Costo**: ~280 vínculos / 20 = 14 GET cada 15 min ≈ 1.350 GET por día, contra 14 por día hoy.
  Aceptable para la cuota de ML (miles de llamadas por hora por aplicación).

## R7 — Aviso de cuotas

- **Decision**: `previsualizar()` agrega `aviso_pierde_cuotas` (string|null). Con destino Clásica y tipo
  vigente Premium, vale `CampaniasCuotas::etiqueta(cuotas_actual, gold_pro)`, por ejemplo
  "3 cuotas sin interés" o "6 cuotas sin interés" (sin campaña). En los demás casos vale `null`.
  `previsualizarLote()` agrega `aviso_pierde_cuotas_lote: true` con destino Clásica. El JS muestra una
  alerta `alert-warning` en cada modal.
- **Rationale**: en Premium, `no-campaign` también son cuotas sin interés (6, el default de ML), así que
  toda Premium pierde algo al bajar.

## R8 — Historial: columna `origen`

- **Decision**: `origen` string(20), not null, default `crm`, valores `crm` / `mercadolibre`. Las filas
  existentes quedan en `crm` por el default. Un cambio detectado por la revisión va con
  `usuario_id = null`. El que se detecta al confirmar (R5) lleva el usuario que confirmó.
- **Alternatives considered**: un resultado nuevo `detectado_en_ml` (mezcla qué pasó con quién lo
  originó, y duplicaría cada resultado).
