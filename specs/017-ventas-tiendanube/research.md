# Research: Ventas de Tiendanube

## R1 — Mapeo de estados: `status` + `payment_status` → 5 estados de conversión, `shipping_status` fuera del mapeo

**Pregunta**: Tiendanube expone tres campos de estado independientes (`status`, `payment_status`,
`shipping_status`). Mercado Libre expone uno solo. ¿Cómo se preserva el mismo conjunto cerrado de cinco
estados de conversión (FR-007a) sin perder información ni inventar estados nuevos?

**Decisión**: derivar el estado de conversión únicamente de `status` + `payment_status` (tabla FR-007a
del spec). `shipping_status` se persiste como columna informativa en `tn_ordenes`, mostrada en el
listado (FR-005), pero no participa de la derivación.

**Rationale**: el estado de conversión responde a una sola pregunta de negocio — "¿está pronta para
facturarse?" — que depende de si está paga y no cancelada, no de si ya se despachó. Meter
`shipping_status` en la derivación introduciría estados espurios (ej. "pagada pero no despachada" no es
un motivo válido para no poder facturarla) y rompería la paridad con el conjunto ya validado en la spec
012.

**Alternativas consideradas**:
- *Agregar un sexto estado "Pagada, pendiente de envío"*: rechazado — no es un estado de **conversión**
  (no cambia si puede facturarse o no), es información de logística. Se muestra igual, pero como columna
  separada, no como estado excluyente.

## R2 — Exclusión de `storefront=meli`: filtro en la propia consulta, no post-proceso

**Pregunta**: ¿cómo se implementa la exclusión de órdenes del canal Mercado Libire integrado (spec.md,
"Riesgo de duplicación")?

**Decisión**: usar el parámetro `channels` de `GET /orders` para pedir explícitamente sólo los canales
distintos de `meli` (o, si la API no admite negación directa, pedir la lista completa de canales válidos
del negocio excluyendo `meli`), en vez de traer todas las órdenes y descartar en el CRM las que tengan
`storefront=meli`.

**Rationale**: con un límite de ~2 solicitudes/segundo, no tiene sentido gastar cupo de API trayendo
datos que se van a descartar siempre. Además, filtrar en el servidor de Tiendanube es una garantía más
fuerte que filtrar en el CRM: un bug futuro en el filtrado local podría dejar pasar una orden `meli` sin
que nada lo impida aguas arriba.

**Alternativas consideradas**:
- *Traer todo y filtrar en `TraductorOrdenes`*: mantiene un filtro de todos modos como red de seguridad
  (ver FR-012a — el requisito es "nunca sincronizar", no "filtrar en la consulta"), pero como filtro
  primario se prefiere el de la API. Se implementan **ambos**: el de la consulta como optimización, y un
  descarte explícito en `TraductorOrdenes` como garantía — defensa en profundidad sobre un requisito que,
  si falla, duplica ventas reales.

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

## R7 — Sin webhooks: reafirmación de la decisión de la spec 015

**Pregunta**: Tiendanube ofrece webhooks para `order/created`, `order/paid`, etc. ¿Se usan para bajar la
latencia de sincronización?

**Decisión**: no, se mantiene consulta programada + manual (ya resuelto en Clarifications del spec).

**Rationale**: un webhook entrante requiere que el CRM sea alcanzable públicamente para recibirlo — la
spec 015 conectó Tiendanube **específicamente** para no necesitar esa infraestructura
(`docs/documentacion_principal_crm.md §5.3`, "Sin restricción de infraestructura pública"). Agregar un
webhook acá reintroduciría, para una parte de la misma integración, exactamente la restricción que el
resto de la integración evitó a propósito.
