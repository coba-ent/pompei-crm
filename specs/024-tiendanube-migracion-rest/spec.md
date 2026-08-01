# Feature Specification: Migración de la integración Tiendanube del servidor MCP a la Application REST clásica

**Feature Branch**: `024-tiendanube-migracion-rest`

**Created**: 2026-07-31

**Status**: Draft

**Input**: User description: "Migración de la integración Tiendanube (specs 017 ventas, 018 stock/precio, vinculación de productos) del servidor MCP (admin-mcp.tiendanube.com, spec 019) a la Application REST clásica del Partner Portal ya conectada y verificada en producción (spec 022, api.tiendanube.com). Cliente REST completo, vinculación por catálogo en vivo y SKU directo (mismo patrón que spec 023 para Mercado Libre), stock/precio y órdenes migrados a REST manteniendo el cronjob actual (sin webhooks todavía), y retiro completo de la integración MCP una vez validado en producción."

## Contexto y fuentes

`019-tiendanube-conexion-mcp` conectó el CRM a Tiendanube vía el servidor MCP oficial
(`admin-mcp.tiendanube.com`), y sobre esa conexión se construyeron `017-ventas-tiendanube` (sincronización
de pedidos), `018-stock-tiendanube` (push de stock y precio) y el mecanismo de vinculación de productos que
usa `020-vendedores`/`021-vinculacion-automatica-sku` como referencia de patrón. Todo eso está en producción
hoy y depende de `ClienteTiendanube` (`app/Services/Tiendanube/ClienteTiendanube.php`), que habla JSON-RPC
2.0 contra el MCP.

`022-tiendanube-conexion-rest` conectó, en paralelo y sin tocar nada de lo anterior, una segunda vía de
autenticación: una Application clásica del Partner Portal de Tiendanube, con OAuth `authorization_code`
contra `www.tiendanube.com` y token para la REST API estándar (`api.tiendanube.com`). Esa spec fue
deliberadamente chica: sólo conectar, verificar y desconectar — dejó documentado como trabajo futuro migrar
el resto de la integración a REST.

Esta spec es esa migración. Reemplaza el cliente MCP por un cliente REST completo, corrige el mecanismo de
vinculación de productos con el mismo patrón ya aplicado a Mercado Libre en
`023-mercadolibre-catalogo-vivo` (catálogo en vivo, sin depender de órdenes ya sincronizadas), migra stock,
precio y órdenes al mismo cliente REST, y — a diferencia de spec 022, que fue aditiva — **retira por
completo la integración MCP** una vez validados los cuatro frentes contra la cuenta real en producción.

**Verificado en la sesión que originó esta spec (2026-07-31)**: la REST API de Tiendanube
(`GET /v1/{store_id}/products`) devuelve cada producto con su array `variants`, y cada variante trae el
campo `sku` directo en la misma respuesta de listado paginado — sin necesitar una llamada de detalle
adicional (a diferencia de Mercado Libre, que sí requirió un multiget separado para spec 023). Esto hace que
la vinculación por catálogo en vivo sea, para Tiendanube, incluso más directa que para Mercado Libre.

**Decisión de alcance tomada con el usuario antes de especificar**:

- La conexión MCP y todo su código (`ClienteTiendanube`, `TiendanubeOAuthController`, tabla
  `tn_configuracion`, `TiendanubeOperacionLog`, el apartado MCP de la pantalla Configuración → Tiendanube) se
  **retira por completo** al final de esta spec, una vez que los cuatro frentes migrados se validaron en
  producción. No queda como código muerto en paralelo.
- La sincronización de órdenes migrada a REST **mantiene el cronjob actual (polling cada minuto)** — no se
  migra a webhooks reales en esta spec, aunque la REST API sí los soporta a diferencia del MCP. Migrar a
  webhooks queda documentado como trabajo futuro explícito, condicionado a cuando el proyecto migre de XAMPP
  local a un VPS (necesario para tener un endpoint público estable).

**Fuente de dominio**: `docs/documentacion_principal_crm.md` §5.3, `docs/modelo_datos.md` §11,
`specs/017-ventas-tiendanube`, `specs/018-stock-tiendanube`, `specs/019-tiendanube-conexion-mcp` (a
retirar), `specs/021-vinculacion-automatica-sku` y `specs/023-mercadolibre-catalogo-vivo` (patrón de
vinculación por catálogo en vivo a replicar), `specs/022-tiendanube-conexion-rest` (conexión REST ya
validada en producción, punto de partida de esta spec).

## Clarifications

### Session 2026-07-31

- Q: ¿Qué pasa con la conexión MCP y su código una vez migrados los cuatro frentes a REST? → A: se retira
  por completo (código, tabla, UI del apartado MCP) — no queda en paralelo como fallback ni como código
  muerto.
- Q: ¿La sincronización de órdenes migrada a REST pasa a webhooks (que la REST API sí soporta, a diferencia
  del MCP) o mantiene el cronjob actual? → A: mantiene el cronjob cada minuto, sin cambio de comportamiento
  observable. Migrar a webhooks queda para una spec futura, cuando el proyecto migre de XAMPP local a un
  VPS con endpoint público estable.
- Q: ¿La vinculación de productos por catálogo en vivo compara el SKU de Tiendanube contra qué del CRM? → A:
  contra el `id` del producto del CRM (comparación directa, mismo criterio ya validado en spec 021/023 para
  Mercado Libre) — no contra el campo `codigo` que sí usa hoy la importación manual por Excel
  (`ImportadorVinculaciones`, que queda reemplazada por este mecanismo).

## Alcance

**Incluye**: un cliente REST completo para Tiendanube (generalización de `VerificadorConexionRest` de spec
022) que reemplaza a `ClienteTiendanube` (MCP) como dependencia de los sincronizadores existentes; un nuevo
mecanismo de vinculación de productos Tiendanube↔CRM por catálogo en vivo y SKU directo, que reemplaza por
completo tanto la resolución desde `tn_orden_items` como la importación manual por Excel; la migración de
la sincronización de stock y precio (push CRM→Tiendanube) al cliente REST; la migración de la
sincronización de órdenes al cliente REST, manteniendo el cronjob cada minuto sin cambios de comportamiento
observable; y el retiro completo de la integración MCP (conexión, cliente, historial, UI) una vez validados
los cuatro frentes en producción.

**Excluye explícitamente**: la conexión REST en sí (`022-tiendanube-conexion-rest`, ya construida y
validada — esta spec la consume, no la modifica); migrar a webhooks de órdenes (queda documentado como
trabajo futuro, condicionado a migrar a VPS); cualquier cambio a la conexión o a los flujos de negocio de
Mercado Libre; cambios a `TiendanubeVarianteProducto` más allá de cómo se resuelve el vínculo (la estructura
de la entidad no cambia).

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Vincular un producto de Tiendanube que nunca vendió, por SKU directo (Priority: P1) 🎯 MVP

Un operador da de alta en el CRM un producto que ya está publicado en Tiendanube pero que todavía no vendió
nada por ese canal, y pone el `id` de ese producto del CRM como SKU de la variante en Tiendanube. Al correr
la vinculación automática, el sistema encuentra el match aunque esa variante nunca haya generado un pedido
sincronizado.

**Why this priority**: es la corrección central que motivó esta spec — hoy este caso no se puede resolver
por vinculación manual desde el selector (que sólo conoce variantes vistas en pedidos ya sincronizados), y
la única alternativa es una importación por Excel que matchea por slug, no por SKU.

**Independent Test**: con una variante de Tiendanube cuyo SKU coincide con el `id` de un producto del CRM,
sin ningún pedido sincronizado que la mencione, correr la vinculación automática y confirmar que el vínculo
se crea solo.

**Acceptance Scenarios**:

1. **Given** una variante de Tiendanube cuyo SKU coincide con el `id` de un producto del CRM, sin ningún
   pedido sincronizado para esa variante, **When** se ejecuta la vinculación automática, **Then** se crea el
   vínculo entre la variante y el producto.
2. **Given** una variante cuyo SKU no coincide con el `id` de ningún producto del CRM, **When** se ejecuta la
   vinculación automática, **Then** esa variante queda sin vincular, con el motivo.
3. **Given** dos variantes distintas con el mismo SKU cargado en Tiendanube, **When** se ejecuta la
   vinculación automática, **Then** la primera en procesarse se vincula (si el SKU coincide con un
   producto) y la segunda queda sin vincular con motivo "producto ya vinculado".

---

### User Story 2 - Sincronizar pedidos y stock/precio sin depender del servidor MCP (Priority: P1)

Como responsable técnico, quiero que la sincronización de pedidos (spec 017) y el push de stock y precio
(spec 018) sigan funcionando exactamente igual que hoy —mismos horarios, mismo comportamiento observable,
misma UI— pero hablando con la REST API de Tiendanube en vez del servidor MCP, porque el MCP va a dejar de
usarse.

**Why this priority**: sin esto, retirar el MCP (User Story 3) dejaría sin funcionar los flujos de negocio
que hoy dependen de él — es la migración funcional real, más allá de la vinculación.

**Independent Test**: con la conexión REST (spec 022) activa y la conexión MCP desconectada manualmente,
correr el cronjob de órdenes y el de stock, y confirmar que ambos sincronizan contra la cuenta real
exactamente igual que antes (mismos pedidos importados, mismo stock actualizado en Tiendanube).

**Acceptance Scenarios**:

1. **Given** la conexión REST activa, **When** corre el cronjob de sincronización de órdenes, **Then** los
   pedidos nuevos de Tiendanube se traen y procesan igual que hoy (misma ventana de tiempo, misma detección
   de convertibilidad a Venta), sin usar el servidor MCP.
2. **Given** un movimiento de stock que deja pendiente una variante vinculada, **When** corre el cronjob de
   sincronización de stock, **Then** el stock se empuja a Tiendanube vía REST, con el mismo criterio de
   loteo y reintentos que tenía la versión MCP.
3. **Given** un cambio de precio en la Lista de Precios configurada, **When** se dispara la sincronización de
   precio, **Then** el precio se empuja a Tiendanube vía REST, igual que hoy.
4. **Given** la conexión REST caída o el token revocado, **When** corre cualquiera de los tres flujos,
   **Then** el sistema lo trata igual que una caída de conexión hoy (sin excepciones no controladas, registro
   en el historial, reintento acotado en 429/5xx).

---

### User Story 3 - Retirar la integración MCP una vez validado todo en producción (Priority: P2)

Como responsable técnico, una vez que confirmé que la vinculación por catálogo en vivo, la sincronización de
órdenes y el push de stock/precio funcionan correctamente vía REST contra la cuenta real, quiero que el
código, la tabla y la pantalla de la conexión MCP dejen de existir, para no mantener dos integraciones vivas
del mismo proveedor.

**Why this priority**: es una limpieza necesaria pero que depende de que las Historias 1 y 2 estén validadas
primero — retirar el MCP antes de tiempo dejaría sin poder revertir si algo de la migración REST falla en
producción.

**Independent Test**: después del retiro, verificar que no queda ninguna referencia funcional al servidor
MCP en el código (`ClienteTiendanube`, `TiendanubeOAuthController`, tabla `tn_configuracion`), que la
pantalla Configuración → Tiendanube sólo muestra el apartado REST, y que los tres flujos de negocio (órdenes,
stock, precio, vinculación) siguen funcionando igual que en la Historia 2.

**Acceptance Scenarios**:

1. **Given** los tres flujos de negocio migrados y validados en producción, **When** se retira la integración
   MCP, **Then** `ClienteTiendanube`, `TiendanubeOAuthController`, la tabla `tn_configuracion` y su historial
   dejan de existir en el código y la base de datos.
2. **Given** el retiro completado, **When** un operador entra a Configuración → Tiendanube, **Then** sólo ve
   el apartado de la conexión REST (spec 022), sin ningún rastro del apartado MCP anterior.
3. **Given** el retiro completado, **When** corren los cronjobs de órdenes y stock, **Then** siguen
   funcionando igual que en la Historia 2 — el retiro del MCP no afecta la migración ya hecha.

---

### Edge Cases

- **Una variante de Tiendanube tiene SKU vacío**: queda sin vincular, con motivo "sin SKU cargado" — mismo
  criterio que spec 021/023.
- **Un producto de Tiendanube tiene variantes múltiples** (talles/colores): cada variante se evalúa por
  separado, cada una con su propio SKU — a diferencia de Mercado Libre (spec 023), donde una publicación con
  variantes queda directamente excluida; en Tiendanube el modelo de catálogo ya usa variante como unidad de
  vinculación (`TiendanubeVarianteProducto`), así que no aplica esa exclusión.
- **El catálogo de Tiendanube tiene más de una página de productos**: se recorre completo, paginando hasta
  agotarlo, sin cortar antes.
- **Un vínculo ya existente al correr de nuevo la vinculación automática**: no se toca ni se re-evalúa —
  mismo criterio que spec 021/023.
- **La consulta al catálogo en vivo falla a mitad del recorrido** (rate limit sostenido, error del proveedor,
  token caído) después de agotar los reintentos del cliente REST: la corrida se aborta sin crear ningún
  vínculo nuevo, se informa el error, el operador puede reintentar — mismo criterio que spec 023.
- **Durante la migración, ambas conexiones (MCP y REST) están activas a la vez**: los sincronizadores usan
  exclusivamente el cliente REST desde que esta spec se implementa; la conexión MCP queda sin ningún
  consumidor de negocio (sólo su propia pantalla de estado) hasta que se retira en la Historia 3.
- **Falla la migración de un frente (por ejemplo, órdenes) después de haber retirado el MCP**: no aplica — el
  retiro del MCP (Historia 3) es el último paso, condicionado explícitamente a que los frentes anteriores ya
  estén validados en producción; si algo falla antes de eso, el MCP todavía existe como para investigar
  comparando comportamientos.
- **Un SKU de Tiendanube coincide con el `id` de un producto ya vinculado a otra variante o a una publicación
  de Mercado Libre**: se trata igual que "ya vinculado" — no se crea un segundo vínculo, mismo criterio que
  ya usa `TiendanubeVarianteProducto` (relación única variante↔producto).

## Requirements *(mandatory)*

### Functional Requirements — Cliente REST

- **FR-001**: El sistema DEBE contar con un cliente REST de Tiendanube que reemplace a `ClienteTiendanube`
  (MCP) como dependencia de los sincronizadores de órdenes, stock, precio y vinculación, usando la conexión
  ya establecida en spec 022 (`access_token`/`store_id` de `tn_conexion_rest`).
- **FR-002**: El cliente REST DEBE aplicar el mismo esquema de reintentos con espera creciente ante 429/5xx
  (acotado, respetando `Retry-After` si el proveedor lo envía) y marcar la conexión como caída ante 401/404
  sin reintentar — mismo criterio ya validado en spec 022 y ya usado por el cliente MCP.
- **FR-003**: El cliente REST DEBE recorrer catálogos y listados paginando hasta agotarlos, sin asumir que
  una sola página alcanza.
- **FR-004**: El sistema NUNCA DEBE exponer el `access_token` de esta conexión en claro, en la interfaz, en
  el historial, ni en ningún log de aplicación — mismo criterio ya vigente para todas las conexiones
  externas del CRM.
- **FR-005**: Cada operación del cliente REST (lectura o escritura) DEBE quedar registrada en un historial
  consultable, con resultado y detalle del error si lo hubo, sin registrar nunca el `access_token`.

### Functional Requirements — Vinculación por catálogo en vivo

- **FR-006**: El sistema DEBE resolver el SKU de cada variante de Tiendanube consultando el catálogo en vivo
  del vendedor conectado, sin depender de que la variante tenga algún pedido ya sincronizado.
- **FR-007**: El sistema DEBE recorrer el catálogo completo de productos y variantes del vendedor conectado
  en cada corrida de la vinculación automática.
- **FR-008**: Por cada variante sin vínculo todavía, el sistema DEBE comparar su SKU vigente contra el `id`
  de los productos del CRM, sin excluir productos inactivos — mismo criterio que spec 021/023.
- **FR-009**: Si hay coincidencia y ni la variante ni el producto tienen ya un vínculo, el sistema DEBE crear
  el vínculo automáticamente.
- **FR-010**: Si no hay coincidencia, o la variante no tiene SKU cargado, o cualquiera de los dos lados ya
  está vinculado, el sistema DEBE dejar esa variante sin vincular e informar el motivo específico.
- **FR-011**: El sistema NO DEBE modificar ni sobrescribir vínculos ya existentes por esta vía.
- **FR-012**: El sistema DEBE dejar de resolver candidatos de vinculación desde `tn_orden_items` (pedidos ya
  sincronizados) y desde la importación manual por Excel — ambos mecanismos quedan reemplazados por el
  catálogo en vivo. `tn_orden_items.sku_vendedor`, si existe, no se elimina ni deja de sincronizarse, sólo
  deja de usarse para este propósito.
- **FR-013**: El sistema DEBE mostrar un resumen de cada corrida (vinculadas / no vinculadas con motivo),
  igual que hoy.

### Functional Requirements — Órdenes, stock y precio

- **FR-014**: El sistema DEBE sincronizar pedidos de Tiendanube vía el cliente REST, manteniendo el mismo
  cronjob (cada minuto) y el mismo comportamiento observable que la versión MCP (spec 017) — sin migrar a
  webhooks en esta spec.
- **FR-015**: El sistema DEBE empujar el stock de variantes vinculadas y pendientes a Tiendanube vía el
  cliente REST, manteniendo el mismo cronjob y criterio de loteo que la versión MCP (spec 018).
- **FR-016**: El sistema DEBE empujar el precio de variantes vinculadas a Tiendanube vía el cliente REST ante
  cambios en la Lista de Precios configurada o de forma manual, manteniendo el mismo comportamiento
  observable (evento + manual, sin cron) que la versión MCP.
- **FR-017**: Ninguno de los tres flujos anteriores DEBE invocar al servidor MCP una vez completada esta
  migración.

### Functional Requirements — Retiro de la integración MCP

- **FR-018**: Una vez validados en producción los flujos de órdenes, stock, precio y vinculación sobre REST,
  el sistema DEBE retirar por completo el código, los datos y la interfaz de la conexión MCP: cliente MCP,
  controlador OAuth MCP, tabla de configuración MCP, su historial, y el apartado correspondiente en
  Configuración → Tiendanube.
- **FR-019**: El retiro del MCP NO DEBE afectar el funcionamiento de los flujos ya migrados a REST (órdenes,
  stock, precio, vinculación).
- **FR-020**: El sistema DEBE conservar, tras el retiro, los datos de negocio ya generados por el MCP
  mientras estuvo activo (pedidos, vínculos, movimientos) — el retiro elimina la infraestructura de conexión
  MCP, no el historial de negocio derivado de ella.

### Key Entities *(include if feature involves data)*

- **Cliente REST de Tiendanube**: reemplaza a `ClienteTiendanube` como punto único de acceso a la REST API
  para operaciones de negocio (lectura de catálogo y pedidos, escritura de stock/precio), reutilizando la
  conexión de spec 022.
- **Variante de Tiendanube**: ya no se conoce sólo a través de pedidos sincronizados — a partir de esta spec
  se conoce también (y para vinculación automática, exclusivamente) a través del catálogo en vivo del
  vendedor conectado. `TiendanubeVarianteProducto` no cambia de estructura, sólo la fuente del SKU que
  decide qué vínculo crear.
- **Conexión MCP**: entidad y almacenamiento de spec 019, retirados al final de esta spec una vez validada
  la migración.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Un operador puede vincular automáticamente una variante de Tiendanube que nunca vendió, con
  sólo cargarle el SKU correcto en Tiendanube y correr la vinculación automática — sin necesitar ninguna
  venta previa.
- **SC-002**: Los pedidos, el stock y el precio se siguen sincronizando entre el CRM y Tiendanube con el
  mismo comportamiento observable (horarios, resultados) que antes de la migración, verificable contra la
  cuenta real en producción.
- **SC-003**: 100% de los vínculos ya existentes antes de esta migración siguen intactos después de
  desplegarla.
- **SC-004**: Reintentar la vinculación automática una segunda vez deja el 100% de lo ya vinculado sin
  cambios.
- **SC-005**: Después de retirar la integración MCP, ningún flujo de negocio de Tiendanube depende de
  `admin-mcp.tiendanube.com`, verificable por inspección de código y por funcionamiento continuo en
  producción.

## Assumptions

- El vendedor conectado vía la Application REST (spec 022) es el mismo vendedor que hoy usa la conexión MCP
  — no hay cambio de cuenta durante la migración.
- El volumen de catálogo y pedidos de Tiendanube es manejable con paginado REST estándar dentro de una
  corrida síncrona, sin necesitar el modo `scan` que sí hizo falta para Mercado Libre en spec 023 (volumen
  de productos de Tiendanube muy por debajo de "miles").
- El campo de SKU relevante es el que la REST API expone como `sku` en cada variante del listado de
  productos — no requiere ninguna llamada de detalle adicional.
- La migración se implementa y valida en el ambiente local (XAMPP) contra la cuenta real, igual criterio que
  specs 017/018/019/022 — sin ambiente de prueba/sandbox de Tiendanube disponible.
- El retiro de la integración MCP (Historia 3) se ejecuta recién después de confirmar en producción que los
  tres flujos de negocio migrados funcionan correctamente — no es un paso automático dentro del mismo
  despliegue que la migración funcional. "Validado en producción" es una confirmación manual explícita del
  responsable técnico (no un plazo fijo ni una condición que el sistema evalúe solo) — mismo criterio de
  decisión humana que ya usa el proyecto para dar por cerrada cualquier otra spec contra la cuenta real.
- La migración de datos de `tn_configuracion` a `tn_conexion_rest` (ver plan.md §"Enfoque técnico" punto 4)
  es idempotente: puede reintentarse ante un deploy fallido sin duplicar ni corromper los valores (misma
  fila única `id=1`, sobreescritura directa de columnas, no inserción).
- Para minimizar la ventana en la que un operador podría editar configuración de negocio desde la pantalla
  vieja (apartado MCP) después de que ya se migró a `tn_conexion_rest`: la migración de datos y el cambio de
  la pantalla de Configuración → Tiendanube para leer/escribir sobre `tn_conexion_rest` se despliegan en el
  mismo cambio, no en pasos separados en el tiempo.
- Antes de correr la migración que elimina `tn_configuracion`/`tn_operaciones_log` (Historia 3), se toma un
  backup de la base de datos — es una operación destructiva (`DROP TABLE`) sin mecanismo de reversión
  automática una vez aplicada; el backup es la vía de recuperación ante una regresión post-retiro no
  detectada durante la validación previa.
- Permiso reutilizado: se mantiene `configuracion.funciones`, mismo criterio que specs 015/019/022.

## Dependencies

- **Interna**: conexión REST ya establecida y validada en producción (spec 022) — esta spec no la modifica,
  sólo la consume como fuente de credenciales para el nuevo cliente REST.
- **Interna**: patrón de vinculación por catálogo en vivo ya implementado para Mercado Libre (spec 023) —
  referencia de diseño directa, adaptada a que Tiendanube expone `sku` sin llamada de detalle adicional.
- **Interna**: `ClienteTiendanube` (MCP) y su historial (`TiendanubeOperacionLog`) como referencia de
  comportamiento a igualar en el cliente REST, antes de ser retirados en la Historia 3.

## Impacto en la documentación de dominio

Antes de pasar a `/speckit-tasks`, corresponde actualizar:

1. `docs/documentacion_principal_crm.md` §5.3: reemplazar la descripción de la integración basada en MCP por
   la basada en REST (cliente, vinculación por catálogo en vivo, retiro del apartado MCP de Configuración →
   Tiendanube).
2. `docs/modelo_datos.md` §11: retirar `tn_configuracion`/`tn_operaciones_log` (MCP) del esquema vigente,
   dejando sólo `tn_conexion_rest` (spec 022) y las entidades de negocio (`TiendanubeOrden`,
   `TiendanubeOrdenItem`, `TiendanubeVarianteProducto`) sin cambios de estructura.
