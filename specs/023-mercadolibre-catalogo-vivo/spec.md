# Feature Specification: Vinculación automática de Mercado Libre por catálogo en vivo

**Feature Branch**: `023-mercadolibre-catalogo-vivo`

**Created**: 2026-07-31

**Status**: Draft

**Input**: User description: "Corrección de spec 021 (vinculación automática por SKU, Mercado Libre): reemplazar por completo el mecanismo actual de matching —que compara el SKU visto en órdenes ya sincronizadas contra el id de productos— por un escaneo del catálogo en vivo de Mercado Libre, sin depender de que la publicación haya vendido alguna vez."

## Contexto

La spec 021 (vinculación automática por SKU) quedó implementada comparando el SKU del vendedor visto en `ml_orden_items` (líneas de órdenes ya sincronizadas) contra el `id` de `productos`. Ese mecanismo tiene dos limitaciones reales, detectadas al usarlo contra la cuenta conectada:

1. **Sólo conoce publicaciones que ya vendieron**: si una publicación nunca generó una orden, no hay ninguna fila en `ml_orden_items` de la que sacar el SKU, así que nunca puede vincularse por esta vía — aunque el operador haya cargado el SKU correcto en Mercado Libre.
2. **El SKU es un snapshot histórico**: el valor guardado es el que tenía la orden al momento de sincronizarse, no el que tiene la publicación *hoy*. Si el operador corrige el SKU de una publicación en Mercado Libre después de esa sincronización, la vinculación automática sigue comparando contra el valor viejo.

Caso real que expuso el problema (31/07/2026, cuenta de prueba conectada TESTUSER686149248667814311): el operador puso el SKU `9006` en la publicación `MLA1927008393` (que coincide con el producto real `id=9006` del CRM) para que se vinculara sola. La publicación nunca había vendido, así que `ml_orden_items` no tenía ningún registro de ella — la vinculación automática la reportó como "sin SKU cargado" pese a que el SKU correcto ya estaba puesto en Mercado Libre.

Se verificó empíricamente contra la cuenta real conectada que el catálogo completo del vendedor se puede recorrer sin depender de órdenes:

- El SKU vigente de cada publicación está disponible vía la propia API de publicaciones, en el detalle del ítem (dentro de sus atributos, con el identificador `SELLER_SKU`) — no en el campo legado que usaban integraciones más viejas (ése vino vacío).
- Se puede listar el `id` de todas las publicaciones del vendedor paginando sin filtro (confirmado con las 5 publicaciones reales de la cuenta de prueba en una sola página).
- Se puede pedir el detalle de varias publicaciones a la vez (hasta 20 por llamada) en vez de una request por publicación — mismo criterio de eficiencia que ya usa la integración de Tiendanube con su catálogo en vivo.
- El catálogo real de prueba trajo también un caso de SKU duplicado entre dos publicaciones activas *distintas* (`KO-23423` en ambas) — el mecanismo tiene que decidir qué hacer ahí (ver Edge Cases).

## Clarifications

### Session 2026-07-31

- Q: ¿El mecanismo por catálogo en vivo reemplaza al basado en órdenes, o coexisten? → A: Reemplaza por completo — la vinculación automática de Mercado Libre deja de leer `ml_orden_items` para esto; el SKU se resuelve siempre contra el catálogo en vivo.
- Q: ¿Las publicaciones pausadas (`paused`) se consideran vinculables junto con las activas? → A: Sí — pausada suele significar "temporalmente sin stock", no descontinuada; el alta manual existente tampoco distingue por estado.
- Q: ¿Cuál es el volumen real de publicaciones del vendedor? → A: Miles (no "decenas a un par de cientos" como asumía el borrador inicial) — esto obliga a usar el modo `scan` del buscador de Mercado Libre en vez de paginado simple por `offset` (tope de 1000 resultados), y a asumir corridas de varios minutos.
- Q: ¿La vinculación automática puede tardar varios minutos en responder mientras el operador espera con la pantalla cargando, o necesita correr en background con progreso/resultado consultable después? → A: Puede tardar varios minutos con el operador esperando, sin problema — no hace falta background ni indicador de progreso independiente.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Vincular una publicación que nunca vendió (Priority: P1) 🎯 MVP

Un operador da de alta en el CRM un producto que ya está publicado en Mercado Libre pero que todavía no vendió nada por ese canal, cargándole el `id` correcto. Después pone ese mismo valor como SKU de la publicación en Mercado Libre. Al correr la vinculación automática, el sistema encuentra el match aunque la publicación no tenga ninguna orden sincronizada.

**Why this priority**: es la corrección central — hoy este caso, que es el más común para dar de alta productos "viejos" que ya estaban en Mercado Libre, no se puede resolver de ninguna forma con el mecanismo actual.

**Independent Test**: con una publicación activa en Mercado Libre cuyo SKU coincide con el `id` de un producto del CRM, y sin ninguna orden sincronizada para esa publicación, correr la vinculación automática y confirmar que el vínculo se crea solo.

**Acceptance Scenarios**:

1. **Given** una publicación activa en Mercado Libre cuyo SKU coincide con el `id` de un producto del CRM, sin ninguna orden sincronizada para esa publicación, **When** se ejecuta la vinculación automática, **Then** se crea el vínculo entre la publicación y el producto.
2. **Given** una publicación activa cuyo SKU no coincide con el `id` de ningún producto del CRM, **When** se ejecuta la vinculación automática, **Then** esa publicación queda sin vincular, con el motivo.

---

### User Story 2 - El SKU corregido en Mercado Libre se refleja en la próxima corrida (Priority: P2)

Un operador corrige el SKU de una publicación directamente en Mercado Libre (porque lo había cargado mal, o porque decide alinearlo recién ahora con el `id` de un producto del CRM). La próxima vez que corre la vinculación automática, usa el SKU corregido, no uno viejo.

**Why this priority**: evita que un dato desactualizado bloquee la vinculación en silencio — el operador no tiene forma de saber, mirando la pantalla del CRM, que el sistema está comparando contra un SKU que ya no es el real.

**Independent Test**: cambiar el SKU de una publicación ya vista antes por el sistema (con un SKU distinto al anterior) y confirmar que la siguiente corrida de vinculación automática usa el valor nuevo.

**Acceptance Scenarios**:

1. **Given** una publicación cuyo SKU en Mercado Libre cambió desde la última vez que se corrió la vinculación automática, **When** se vuelve a ejecutar, **Then** el resultado refleja el SKU vigente ahora, no el anterior.

---

### Edge Cases

- ¿Qué pasa si dos publicaciones *distintas* tienen el mismo SKU cargado en Mercado Libre (caso real confirmado, `KO-23423`)? La primera en procesarse se vincula (si el SKU coincide con un producto); la segunda queda sin vincular con el motivo "producto ya vinculado" — mismo criterio que ya usa el mecanismo actual para el caso análogo (spec 021, Acceptance Scenario 4).
- ¿Qué pasa con las publicaciones pausadas (`paused`)? Se consideran igual que las activas — el operador puede tener productos pausados temporalmente que de todos modos quiere vinculados de antemano. Sólo se excluyen las publicaciones cerradas/finalizadas (`closed`), que ya no representan una oferta vigente.
- ¿Qué pasa con una publicación con variantes? Sigue sin poder vincularse por esta vía, mismo criterio ya vigente (spec 021, FR-007) — se detecta inspeccionando el detalle de la publicación, sin ninguna llamada adicional.
- ¿Qué pasa si el catálogo del vendedor tiene más de una página de publicaciones? Se recorre hasta agotarlo, sin cortar antes (mismo criterio que ya usa la importación de Tiendanube, spec 021).
- ¿Qué pasa con una publicación sin ningún SKU cargado en Mercado Libre? Queda sin vincular, con motivo "sin SKU cargado" — mismo criterio que hoy.
- ¿Qué pasa con un vínculo ya existente al correr de nuevo la vinculación automática? No se toca ni se re-evalúa — mismo criterio que hoy (spec 021, FR-008): reintentar no sobrescribe lo ya vinculado.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: El sistema MUST resolver el SKU de cada publicación de Mercado Libre consultando el catálogo en vivo del vendedor conectado, sin depender de que la publicación tenga alguna orden ya sincronizada.
- **FR-002**: El sistema MUST recorrer el catálogo completo de publicaciones del vendedor conectado (paginando hasta agotarlo) en cada corrida de la vinculación automática.
- **FR-003**: El sistema MUST considerar tanto las publicaciones activas como las pausadas; MUST excluir las publicaciones cerradas/finalizadas.
- **FR-004**: Por cada publicación sin vínculo todavía, el sistema MUST comparar su SKU vigente (el que tiene puesto en Mercado Libre en el momento de la corrida) contra el `id` de los productos del CRM, sin excluir productos inactivos (mismo criterio que spec 021, FR-002).
- **FR-005**: Si hay coincidencia y ni la publicación ni el producto tienen ya un vínculo, el sistema MUST crear el vínculo automáticamente.
- **FR-006**: Si no hay coincidencia, o la publicación no tiene SKU cargado, o cualquiera de los dos lados ya está vinculado, el sistema MUST dejar esa publicación sin vincular e informar el motivo específico (mismos motivos que spec 021: sin SKU, SKU sin match, ya vinculado).
- **FR-007**: Publicaciones con variantes MUST seguir sin poder vincularse por esta vía (mismo criterio ya vigente, spec 021 FR-007) — determinado a partir del propio detalle de la publicación traído del catálogo en vivo.
- **FR-008**: El sistema NO MUST modificar ni sobrescribir vínculos ya existentes por esta vía.
- **FR-009**: El sistema MUST dejar de usar las líneas de órdenes ya sincronizadas (`ml_orden_items`) como fuente del SKU para este mecanismo.
- **FR-010**: El sistema MUST mostrar un resumen de la corrida (vinculadas / no vinculadas con motivo), igual que hoy.

### Key Entities *(include if feature involves data)*

- **Publicación de Mercado Libre**: ya no se conoce sólo a través de órdenes sincronizadas — a partir de esta spec se conoce también (y para este mecanismo, exclusivamente) a través del catálogo en vivo del vendedor conectado. Sin cambios de estructura en el CRM.
- **Vínculo publicación↔producto**: entidad ya existente (spec 012), sin cambios de estructura. Cambia únicamente la fuente del SKU que decide qué vínculo crear.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Un operador puede vincular automáticamente una publicación de Mercado Libre que nunca vendió, con sólo cargarle el SKU correcto en Mercado Libre y correr la vinculación automática — sin necesitar ninguna venta previa.
- **SC-002**: Si el SKU de una publicación cambia en Mercado Libre, la siguiente corrida de la vinculación automática ya refleja ese cambio, sin ninguna acción adicional del operador.
- **SC-003**: 100% de los vínculos ya existentes antes de esta corrección siguen intactos después de desplegarla.
- **SC-004**: Reintentar la vinculación automática una segunda vez deja el 100% de lo ya vinculado sin cambios.
- **SC-005**: Un catálogo de varios miles de publicaciones se recorre completo en una sola corrida, sin cortarse antes de tiempo ni requerir que el operador la repita manualmente por partes.

## Assumptions

- El vendedor conectado (cuenta real vinculada en Configuración > Funciones Avanzadas > Mercado Libre) es el único catálogo relevante — no hay multi-cuenta.
- **El volumen real del negocio es de miles de publicaciones** (confirmado por el usuario, no "decenas a un par de cientos" como asumía un borrador anterior de esta spec). Esto tiene dos consecuencias técnicas a resolver en la fase de planificación:
  - El buscador paginado por `offset`/`limit` de Mercado Libre sólo alcanza hasta 1000 resultados (`offset+limit ≤ 1000`, límite documentado de la propia API) — para catálogos más grandes hace falta su modo de recorrido completo (`scan`, basado en cursor, sin ese tope). A verificar contra la cuenta real cuántas llamadas y cuánto tiempo insume en la práctica.
  - Aun estando muy por debajo del rate limit documentado (~1500 req/min por vendedor, spec 021 research.md R1), recorrer miles de publicaciones son cientos de llamadas encadenadas (paginado + multiget de a 20) — la corrida puede tardar varios minutos.
- La vinculación automática sigue siendo una operación síncrona disparada por un botón: el operador puede esperar varios minutos con la pantalla cargando mientras corre — no hace falta procesamiento en background ni un indicador de progreso independiente.
- El campo de SKU relevante es el que Mercado Libre expone como atributo `SELLER_SKU` en el detalle de cada publicación; el campo legado (`seller_custom_field`) no se usa por venir vacío en la cuenta real verificada.
- `ml_orden_items.sku_vendedor` no se elimina ni deja de sincronizarse — sólo deja de leerse para este mecanismo específico; puede seguir usándose para otros fines (ej. reportes históricos) sin cambios.
- Si la consulta al catálogo en vivo falla a mitad del recorrido (rate limit sostenido, error del proveedor, o el token que se cae) después de agotar los reintentos ya existentes de `ClienteMercadoLibre`, la corrida completa se aborta sin crear ningún vínculo nuevo — se informa el error y el operador puede reintentar. Evita vincular contra un catálogo incompleto que podría faltar justo la publicación que resuelve un caso de SKU duplicado.
