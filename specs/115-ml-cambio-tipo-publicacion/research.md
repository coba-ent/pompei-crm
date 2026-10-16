# Research: Cambio de tipo de publicación ML (spec 115)

## R1 — API de Mercado Libre para el cambio de tipo

- **Decision**: `POST /items/{ITEM_ID}/listing_type` con cuerpo `{"id": "gold_pro"}` o `{"id": "gold_special"}`.
  Antes de confirmar: `GET /items/{ITEM_ID}` (tipo vigente `listing_type_id` y `status`) y
  `GET /items/{ITEM_ID}/available_upgrades` (destino Premium) o `/available_downgrades` (destino Clásica).
  Un destino ausente de esa lista = bloqueo con motivo "Mercado Libre no ofrece ese tipo para esta publicación".
- **Rationale**: documentación oficial de ML Argentina ("Tipos de publicación y actualizaciones de
  artículos", consultada 15/10/2026): el cambio entre `gold_special` y `gold_pro` es sin cargo y en
  cualquier momento; en MLA el downgrade se admite con status ACTIVE, NOT_YET_ACTIVE, UNDER_REVIEW y PAUSED.
  La respuesta del POST devuelve el ítem completo con el `listing_type_id` nuevo: se usa para confirmar.
- **Alternatives considered**: `PUT /items/{id}` con `listing_type_id` (no documentado para cambio de
  tipo, rechazado); `POST /marketplace/items/{id}/listing_type` (es el recurso de marketplace global
  MLB/MCO, no aplica a MLA).

## R2 — Orden de operaciones y rollback (FR-008)

- **Decision**:
  - **→ Premium**: (1) enviar precio de la lista Premium (si difiere del publicado); (2) POST listing_type.
    Si (2) falla: re-enviar el precio anterior (`precio_publicado` previo) para dejar la Clásica con su
    precio; si no había precio anterior conocido, marcar `precio_pendiente` (el próximo envío lo
    resuelve por la lista general, y si es bajada mayor al umbral queda retenida → visible).
  - **→ Clásica**: (1) POST listing_type; (2) enviar precio de la lista general. Si (1) falla no se
    tocó nada. Si (2) falla queda `precio_pendiente = 1` con el error (la publicación cobra de más,
    nunca de menos) y se informa como "cambiada con precio pendiente".
- **Rationale**: en ninguna transición intermedia la publicación queda Premium con precio de Clásica,
  que es exactamente la pérdida del incidente del 25/08/2026.
- **Alternatives considered**: cambiar tipo y dejar que la sincronización empuje el precio después
  (ventana con precio incorrecto, rechazado); transacción distribuida (no existe del lado de ML).

## R3 — Corte de seguridad de spec 084

- **Decision**: el precio se envía con `SincronizadorPrecios::enviarUno($vinculo, $precio, omitirCorte: true)`
  en los dos sentidos. Se actualiza el docblock de `enviarUno()` y la doc de §3.2.ter.septies:
  `omitirCorte` queda permitido **sólo** para confirmaciones humanas que muestran ambos importes
  (aprobar retención y cambio de tipo). El job de lote lo usa porque cada publicación del lote fue
  confirmada viendo su precio antes/después (FR-014).
- **Rationale**: Premium→Clásica es siempre una bajada ≈31% (> umbral 20%) y quedaría retenida;
  además "sin referencia se retiene" frenaría también subidas de vínculos sin `precio_publicado`.
  El principio de 084 es "ninguna bajada llega a ML sin que una persona la haya visto": la
  confirmación del cambio de tipo cumple ese principio. `enviarUno()` ya cierra como `reemplazada`
  una retención abierta tras un envío exitoso (cubre el edge case).
- **Alternatives considered**: dejar que se retenga y pedir una segunda aprobación (doble paso para
  la misma decisión, rechazado); replicar el PUT de precio en el servicio nuevo (viola "un solo punto
  de PUT", causa raíz del incidente del 25/08 — rechazado).

## R4 — Resolución de la lista destino

- **Decision**: nuevo `SincronizadorPrecios::listaPrecioParaTipo(string $tipo, MercadoLibreConfiguracion $c): ?int`
  que devuelve `lista_precio_id_premium` para `gold_pro` y `lista_precio_id` para `gold_special`,
  **sin fallback**. `resolverListaPrecio()` pasa a usarlo (conserva su fallback de spec 050 FR-008
  para la sincronización normal). El cambiador usa la versión estricta: sin lista o sin precio en
  ella → bloqueo (FR-005).
- **Rationale**: la regla "qué lista corresponde a qué tipo" sigue viviendo en un solo lugar; el
  fallback de spec 050 es correcto para la sincronización automática pero es justo lo que FR-005
  prohíbe en el cambio de tipo.
- **Alternatives considered**: duplicar el mapeo en el cambiador (rechazado, dos reglas que pueden divergir).

## R5 — Lote en segundo plano

- **Decision**: job `CambioTipoPublicacionLote` (cola `database`, ya en uso por la Sincronización
  forzada), con `lote_id` UUID, estado en Cache `ml:cambio_tipo_lote:estado` (`en_curso` / `terminado`
  / `error` + progreso + resumen), TTL 30 min. Un segundo lote se rechaza con 409 mientras el estado
  sea `en_curso`. El job toma el candado `ml:sincronizar_precios` por publicación (no por todo el
  lote) para no bloquear el observer de precios durante minutos.
- **Rationale**: mismo patrón probado de spec 035; 50 publicaciones × ~4 llamadas no entran en un request.
- **Alternatives considered**: request síncrono (timeout); Bus::batch (requiere tabla `job_batches`, más complejidad sin beneficio).

## R6 — Candado y concurrencia

- **Decision**: cada `ejecutar()` toma `Cache::lock(SincronizadorPrecios::LOCK_KEY, 60)` con `block(10)`;
  si no lo obtiene → "Hay una sincronización de precios en curso, probá en unos minutos" (409). Los
  cortes de función desactivada / sólo lectura / sin cuenta los aplica `ClienteMercadoLibre::peticion()`
  (punto único) y además se verifican al previsualizar para no ofrecer una acción que va a fallar.
- **Rationale**: FR-011; reutiliza el candado existente que ya comparten precios y forzada.

## R7 — Tabla de Vinculaciones

- **Decision**: columna de casillas fija (`no-colvis`, `orderable:false`), Acciones fija
  (`fixedColumnsLeft: 2`); columna "Tipo" (`tipo_etiqueta`, name `listing_type_id`); columna Producto
  renderiza `#ID · código — nombre`, con `filterColumn` que busca por `producto.id`, `producto.codigo`
  y `producto.nombre`. Filtro Select2 `#filtro-tipo` (Todos/Clásica/Premium/Sin clasificar) enviado al
  endpoint y guardado junto con el de logística en `stateSaveParams`/`stateLoadParams`. Se reemplaza el
  `colvis` por índice (`idx !== 0`) por `columns: ':not(.no-colvis)'` (regla #1 de `CLAUDE.md`).
- **Rationale**: FR-016..FR-018 y reglas de diseño obligatorias.

## R8 — Riesgo conocido, fuera de alcance

- La doc de ML menciona restricciones nuevas al `PUT /items` sólo con `price` (marzo 2026). El envío de
  precio en producción funciona hoy (spec 084 operativa); esta spec reutiliza `enviarUno()` sin cambiar
  cómo envía. Si ML cambia ese contrato, impacta a toda la sincronización de precios y se trata aparte.

## R9 — Cuotas (ajuste 16/10/2026, FR-019..FR-021)

- **Fuente**: "Campañas con cuotas para Marketplace" de ML Argentina
  (https://developers.mercadolibre.com.ar/es_ar/campana-con-cuotas-para-marketplace, consultada 16/10/2026).
- **Modelo de ML**: las cuotas no son un campo, son un **tag** en `/items`, una sola campaña por
  publicación y canal. `gold_pro` sin tag = 6 cuotas al mismo precio (lo que ML pone al crear o pasar a
  Premium: el comportamiento que reportó el usuario); `3x_campaign` / `9x_campaign` / `12x_campaign`.
  `gold_special` sin tag = sin cuotas; `pcj-co-funded` = 3 a 12 con interés bajo (+4%). En el CRM "sin
  tag" se representa con `no-campaign`, el mismo id que devuelve ML. Mapa en `App\Services\MercadoLibre\CampaniasCuotas`
  (reutilizable por la futura creación de publicaciones).
- **Decision**:
  - Opciones: `GET /special_installments/campaigns?category_id=…&listing_type_id=…` con la categoría del
    `GET /items` que ya hace la previsualización. Si falla, la lista documentada. Una campaña nueva que
    ML devuelva y el CRM no conozca se ofrece con la descripción de ML.
  - Cargo por opción (sólo en la previsualización individual, best-effort):
    `GET /sites/MLA/listing_prices?price=<precio destino>&listing_type_id=…&tags=…&domain_id=…` →
    `sale_fee_details.percentage_fee` y `sale_fee_amount`. Si no responde, la opción se muestra sin cargo.
  - Aplicar: `PUT /items/{id}` con `{"tags": [...]}` **después** del POST `listing_type`, partiendo de los
    tags que devuelve ese POST (o un `GET /items` si no vienen): se quitan las campañas y se agrega la
    elegida. ML reemplaza la lista entera, así que si los tags no se pueden leer **no se envía nada**
    (mandar sólo la campaña borraría los demás). Si la campaña elegida ya es la vigente, no hay PUT.
  - Este PUT sólo lleva `tags`: no es un envío de precio y no pasa por `enviarUno()`.
  - Rechazo de las cuotas → `cambiado_cuotas_pendientes` (HTTP 200, toast de advertencia), sin revertir.
  - `cuotas` en la entrada: sólo se valida la forma (`^[a-z0-9_-]{1,40}$`); la disponibilidad la decide
    ML al ejecutar (FR-021). `null` = no tocar las cuotas (comportamiento anterior al ajuste).
- **Alternatives considered**: valor por defecto configurable en Configuración (descartado por el
  usuario: quiere elegir en el momento); revertir tipo y precio si fallan las cuotas (rechazado: deshace
  lo que protege FR-008 por algo que no afecta el precio); consultar campañas por publicación en la
  previsualización del lote (rechazado: el lote no llama a ML al previsualizar, R5).
- **Riesgo a verificar con un usuario de test de ML**: que el `PUT` con todos los tags no sea rechazado
  por algún tag que ML maneje internamente. Si lo rechaza, el resultado es "cambiada sin las cuotas
  elegidas", no una publicación rota.
