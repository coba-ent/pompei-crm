# Research: Ventas de Tiendanube

## R1 — Mapeo de estados: `status` + `payment_status` → 5 estados de conversión, `fulfillment_status` fuera del mapeo

> ⚠️ **Corrección post-019**: la tool real `list_orders` llama `fulfillment_status` al campo que este
> research (y la primera versión de la spec) llamaba `shipping_status`. Mismo concepto, nombre distinto
> — corregido en todo lo que sigue.

**Pregunta**: Tiendanube expone tres campos de estado independientes (`status`, `payment_status`,
`fulfillment_status`). Mercado Libre expone uno solo. ¿Cómo se preserva el mismo conjunto cerrado de cinco
estados de conversión (FR-007a) sin perder información ni inventar estados nuevos?

**Decisión**: derivar el estado de conversión únicamente de `status` + `payment_status` (tabla FR-007a
del spec). `fulfillment_status` se persiste como columna informativa en `tn_ordenes`, mostrada en el
listado (FR-005), pero no participa de la derivación.

**Rationale**: el estado de conversión responde a una sola pregunta de negocio — "¿está pronta para
facturarse?" — que depende de si está paga y no cancelada, no de si ya se despachó. Meter
`fulfillment_status` en la derivación introduciría estados espurios (ej. "pagada pero no despachada" no
es un motivo válido para no poder facturarla) y rompería la paridad con el conjunto ya validado en la
spec 012.

**Alternativas consideradas**:
- *Agregar un sexto estado "Pagada, pendiente de envío"*: rechazado — no es un estado de **conversión**
  (no cambia si puede facturarse o no), es información de logística. Se muestra igual, pero como columna
  separada, no como estado excluyente.

## R2 — Exclusión de `storefront=meli`: post-proceso, no filtro en la consulta (CORREGIDO post-019)

> ⚠️ **Corrección post-019 (verificado 30/07/2026 contra la cuenta real)**: la decisión original de este
> research (filtrar `channels` en `GET /orders`, la API REST pública de Tiendanube) **no aplica** — el
> CRM habla contra el servidor MCP (`admin-mcp.tiendanube.com`), y su tool real `list_orders` **no tiene
> ningún parámetro `channels` ni equivalente**. No hay forma de excluir un canal en la propia consulta.
> La "defensa en dos capas" que describía este research (filtro en la API + descarte en
> `TraductorOrdenes`) queda en **una sola capa**: sólo el descarte explícito post-fetch. Esto es un
> requisito de negocio crítico (una orden `meli` no descartada duplica una venta real), así que
> `TraductorOrdenes` es la **única** línea de defensa — sin red de seguridad aguas arriba que la
> respalde. El texto original de la decisión queda abajo tachado conceptualmente, como registro de qué
> se asumió y por qué estaba mal.

**Pregunta**: ¿cómo se implementa la exclusión de órdenes del canal Mercado Libre integrado (spec.md,
"Riesgo de duplicación")?

**Decisión (corregida)**: `SincronizadorOrdenes` trae todas las órdenes de la ventana consultada (no hay
forma de pedirle a Tiendanube que excluya un canal) y `TraductorOrdenes` descarta explícitamente,
**antes de persistir nada**, toda orden con `storefront === 'meli'` exacto (FR-012a). La ausencia del
campo o cualquier otro valor no se trata como `meli`.

**Rationale**: sin parámetro de exclusión en la API, no hay alternativa — el costo de traer alguna orden
`meli` de más (en volumen bajo, spec.md §Scale/Scope) es aceptable frente al riesgo de no poder
implementar el filtro en absoluto. `TraductorOrdenes` sigue siendo un traductor separado (no inline en
el sincronizador) para poder testear la exclusión de forma aislada y determinística.

**Decisión original (29/07/2026, basada en documentación REST pública — no aplica)**: usar el parámetro
`channels` de `GET /orders` para pedir explícitamente sólo los canales distintos de `meli`, en vez de
traer todas las órdenes y descartar en el CRM las que tengan `storefront=meli`. Esa decisión asumía un
endpoint y un parámetro que **la tool MCP real no tiene**.

## R3 — Vinculación por `variant_id`, tabla propia `tn_variante_producto`

**Pregunta**: ¿se reutiliza el modelo de vinculación de Mercado Libre (`ml_publicacion_producto`) o se
crea uno nuevo?

**Decisión**: tabla nueva `tn_variante_producto` (`variant_id` único, `producto_id` único — mismo patrón
1:1 con doble índice único que `ml_publicacion_producto`), no una tabla compartida ni una extensión de la
de Mercado Libre.

**Rationale**: son claves de dominios externos completamente distintos (`ml_item_id` vs. `variant_id` de
Tiendanube) sin relación entre sí; una tabla compartida obligaría a una columna discriminadora
(`integracion`) y a lidiar con dos formatos de identificador en la misma tabla, sin ningún beneficio —
ninguna consulta necesita cruzar vínculos de ambas integraciones a la vez.

## R4 — `TiendanubeConfiguracion::depositoEfectivo()`: mismo patrón, sin fallback compartido

**Pregunta**: ¿`TiendanubeConfiguracion` reutiliza el `depositoEfectivo()` de `MercadoLibreConfiguracion`
o define el suyo?

**Decisión**: método propio en `TiendanubeConfiguracion`, con el mismo cuerpo (depósito configurado si
existe y está activo; si no, `Deposito::porDefecto()`).

**Rationale**: son configuraciones de integraciones independientes (`ml_configuracion` vs.
`tn_configuracion`); acoplarlas —por ejemplo, que una dependa de la otra— violaría la independencia que
la spec 015 ya estableció explícitamente para toda la integración de Tiendanube frente a la de Mercado
Libre (historiales separados, tablas separadas, kill-switch separado). Es tres líneas de código
duplicadas a cambio de cero acoplamiento entre dos integraciones que deben poder evolucionar (o fallar)
sin afectarse.

## R5 — Cuenta de Tesorería configurable por FK, no hardcodeada por nombre

**Pregunta**: `ConversorOrdenAVenta` de Mercado Libre resuelve la cuenta de cobranza con
`CuentaTesoreria::where('nombre', 'Mercado Pago')->first()` — un lookup por nombre, no una configuración
real. ¿Se replica ese patrón para Tiendanube?

**Decisión**: no. `tn_configuracion.cuenta_tesoreria_id` es una FK **configurable** desde la pantalla de
configuración (mismo patrón de selector que depósito/categoría), resuelta por id, no por nombre.

**Rationale**: Mercado Libre tiene una única pasarela real (Mercado Pago), así que hardcodear el nombre
"funciona" aunque no sea configurable. Tiendanube admite múltiples medios de pago (`gateway`:
`offline`/`not-provided`/proveedor externo) sin una pasarela canónica equivalente — no hay ningún nombre
razonable para hardcodear. Un lookup por nombre fijo sería además frágil: si el usuario nombra su cuenta
de Tesorería distinto ("Mercado Pago Tienda" en vez de "Mercado Pago"), la conversión de Mercado Libre ya
sufre ese riesgo hoy; no hace falta heredarlo acá cuando la solución correcta (FK configurable) es
igual de simple de implementar.

## R6 — Enums propios `Tiendanube\EstadoConversion`/`MotivoRequiereAtencion`, no reutilizar los de Mercado Libre

**Pregunta**: ¿`TiendanubeOrden.estado_conversion` reutiliza el enum `App\Enums\MercadoLibre\EstadoConversion`?

**Decisión**: no — enum propio `App\Enums\Tiendanube\EstadoConversion`, con los mismos 5 valores/
transiciones que el de Mercado Libre pero definido de forma independiente. Igual criterio para
`MotivoRequiereAtencion`, cuyos motivos concretos difieren (no hay `PublicacionConVariantes` porque toda
variante de Tiendanube es vinculable; no hay `AlertaFraude`, ver spec.md Assumptions).

**Rationale**: aunque el *valor de negocio* de los 5 estados es idéntico, son conceptos que pertenecen a
integraciones independientes (mismo argumento que R4). Compartir el enum crearía una dependencia cruzada
entre los namespaces `MercadoLibre` y `Tiendanube` que la spec 015 evitó deliberadamente a nivel de
tablas e historial — mantenerla a nivel de enums rompería esa misma independencia por una vía distinta.
El costo es duplicar ~40 líneas de un enum; el beneficio es que un cambio futuro en los estados de
Mercado Libre (por ejemplo, si esa integración necesitara un sexto estado) no obliga a re-evaluar
Tiendanube, y viceversa.

## R7 — Sin webhooks: reafirmación, ahora porque el servidor MCP no los expone

> ⚠️ **Corrección post-019**: el motivo original ("Tiendanube ofrece webhooks, pero conectarlos exigiría
> infraestructura pública") ya no es la razón principal — verificado que el servidor MCP
> `admin-mcp.tiendanube.com` **no tiene ninguna tool de gestión de webhooks** (24 tools confirmadas, spec
> 019 research.md R7). No es que se evite un webhook disponible: no hay ningún webhook al que suscribirse
> desde este transporte. Cualquier sincronización en tiempo real futura tendría que ser por *polling* más
> frecuente, no por push.

**Pregunta**: Tiendanube ofrece webhooks para `order/created`, `order/paid`, etc. ¿Se usan para bajar la
latencia de sincronización?

**Decisión**: no, se mantiene consulta programada + manual (ya resuelto en Clarifications del spec).

**Rationale**: el servidor MCP contra el que habla el CRM no expone tools de webhooks (ver arriba). Aun
si las expusiera, un webhook entrante requeriría que el CRM sea alcanzable públicamente para recibirlo,
reintroduciendo para una parte de la integración exactamente la restricción que el resto evitó a
propósito (spec 019).
