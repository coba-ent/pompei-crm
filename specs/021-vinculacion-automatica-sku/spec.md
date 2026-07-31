# Feature Specification: Vinculación automática por SKU (Mercado Libre y Tiendanube)

**Feature Branch**: `021-vinculacion-automatica-sku`

**Created**: 2026-07-30

**Status**: Draft

**Input**: User description: "Vinculación automática por SKU (corrección de spec 021, descartada). Reemplaza el mecanismo de vinculación manual/import genérico por un diseño específico por canal, basado en hallazgos reales confirmados contra las cuentas conectadas y datos reales del negocio."

## Contexto

Reemplaza el enfoque de la spec `021-vinculacion-por-sku` original (descartada sin implementar, ver historial de la sesión). Esa spec asumía que el SKU de cada canal no tenía ninguna correspondencia utilizable con los datos del CRM, y proponía un selector manual con SKU visible más una importación masiva por Excel genérica para ambos canales por igual.

Se descartó tras verificar en vivo contra las cuentas reales conectadas (Mercado Libre y Tiendanube) y contra datos reales del negocio (`productos.xlsx`, 9101 filas; export real de productos de Tiendanube, 102 filas):

- **Mercado Libre**: el SKU del vendedor (visto en las órdenes ya sincronizadas) corresponde al identificador de un sistema anterior del negocio ("ID viejo"). Los productos que hoy están publicados en Mercado Libre todavía no existen en el CRM — se van a crear de acá en más asignándoles a propósito ese mismo "ID viejo" como `id` (clave primaria) del producto en el CRM, en vez de dejar que el sistema les asigne uno automático. No hace falta ningún campo nuevo: el "ID viejo" **es** el `id` del producto. El endpoint real de búsqueda por SKU de Mercado Libre funciona (verificado).
- **Tiendanube**: el SKU corresponde al campo `codigo` de `productos` — confirmado con 85 de 86 productos reales (98.8%) cruzando el export real de Tiendanube contra `productos.xlsx`. Pero la integración conectada (servidor MCP oficial de Tiendanube) **no expone el SKU de ningún producto o variante en ninguna de sus herramientas** — ni para buscar ni para leer —, así que el SKU nunca se puede obtener en vivo, sólo del archivo que el negocio exporta a mano desde el panel de Tiendanube. Ese mismo archivo trae además un "Identificador de URL" (slug) por fila que, confirmado con el 100% de los 102 productos reales de la tienda, coincide exacto con la URL del producto que sí devuelve la integración en vivo (`product_url`) — y esa consulta en vivo sí trae los identificadores internos reales de Tiendanube (producto y variante). Combinando ambos: el archivo aporta SKU + slug, la integración en vivo resuelve slug → identificadores reales, sin depender de que el producto haya vendido alguna vez por ese canal.

Por esta asimetría, el diseño es distinto por canal: Mercado Libre pasa a ser 100% automático; Tiendanube mantiene el alta manual ya existente (spec 017) y suma una importación masiva basada en el archivo que el propio Tiendanube ya permite exportar (no un archivo con formato inventado para esta spec).

## Clarifications

### Session 2026-07-30

- Q: ¿Cómo se dispara la vinculación automática de Mercado Libre? → A: Sólo botón manual en la pantalla — el operador decide cuándo correrla (sin corrida automática enganchada a la sincronización programada).
- Q: ¿Qué acciones quedan disponibles sobre los vínculos de Mercado Libre en la pantalla de control? → A: Se puede eliminar un vínculo y también editar a mano el producto al que apunta un vínculo ya creado, además de la vinculación automática. Sólo desaparece la creación de un vínculo nuevo eligiendo la publicación desde un selector (reemplazada por la vinculación automática).
- Q: ¿El "ID viejo" es un campo/columna nueva en `productos`? → A: No. Los productos que hoy sólo existen en Mercado Libre todavía no se cargaron en el CRM; cuando se creen, se les va a asignar a propósito ese mismo "ID viejo" como `id` (clave primaria) del producto, en vez de un autoincremental cualquiera. El "ID viejo" **es** el `id` de `productos` — no hace falta ningún campo nuevo ni migración de esquema para esta parte.
- Q: ¿La importación de Tiendanube depende de que el producto ya haya vendido por ese canal (única fuente de los ids reales, según el diseño original)? → A: No hace falta. Confirmado con el 100% (102/102) de los productos reales de la tienda: el "Identificador de URL" del archivo exportado coincide exacto con la URL que devuelve la integración en vivo de Tiendanube (`product_url`), así que los identificadores reales (producto y variante) se resuelven consultando el catálogo en vivo por ese slug — no hace falta que el producto tenga una orden sincronizada.
- Q: ¿Un producto inactivo del CRM es elegible para la vinculación automática de Mercado Libre si su `id` coincide con un SKU visto? → A: Sí, se vincula igual — a diferencia de otros selectores del CRM (venta, compra, importación) que excluyen productos inactivos, acá no se filtra por `activo`, "por si acaso".

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Vinculación automática de Mercado Libre (Priority: P1) 🎯 MVP

Un operador da de alta productos nuevos en el CRM asignándoles a propósito el mismo identificador ("ID viejo") que esos productos ya tienen cargado como SKU del vendedor en Mercado Libre. Cuando esos productos aparecen en órdenes sincronizadas, el sistema los vincula solo — sin que nadie tenga que buscar la publicación ni elegir el producto a mano.

**Why this priority**: es la corrección central que motivó todo el trabajo — hoy cada publicación se vincula a mano, publicación por publicación, pudiendo elegir un producto equivocado por similitud de título. Automatizarlo elimina el error humano y el trabajo repetitivo para el canal donde el dato para hacerlo ya existe.

**Independent Test**: con al menos un producto del CRM cuyo `id` coincida con el SKU visto en una orden sincronizada de Mercado Libre, correr la vinculación automática y confirmar que el vínculo se crea solo, sin intervención manual.

**Acceptance Scenarios**:

1. **Given** un producto del CRM cuyo `id` coincide con el SKU del vendedor visto en una orden sincronizada de Mercado Libre, **When** se ejecuta la vinculación automática, **Then** se crea el vínculo entre la publicación y el producto sin intervención manual.
2. **Given** una publicación vista en una orden sincronizada cuyo SKU de vendedor no coincide con el `id` de ningún producto, **When** se ejecuta la vinculación automática, **Then** esa publicación queda sin vincular y visible en la pantalla de control, con el motivo.
3. **Given** una publicación sin ningún SKU de vendedor cargado (dato faltante del lado de Mercado Libre), **When** se ejecuta la vinculación automática, **Then** esa publicación queda sin vincular y visible en la pantalla de control, distinguiendo este caso de "no matcheó" (no hay nada que comparar).
4. **Given** dos publicaciones cuyo SKU coincide con el `id` del mismo producto, **When** se ejecuta la vinculación automática, **Then** sólo la primera en procesarse queda vinculada; la segunda queda sin vincular con el motivo "producto ya vinculado".

---

### User Story 2 - Importación de vinculaciones de Tiendanube desde el export nativo (Priority: P2)

Un operador exporta el listado de productos directamente desde el panel de Tiendanube (funcionalidad que Tiendanube ya ofrece, sin que el CRM tenga que generar ningún archivo especial) y lo sube en la pantalla de vinculación de Tiendanube del CRM. El sistema usa el SKU de ese archivo para encontrar el producto correspondiente en el CRM y crear las vinculaciones que pueda resolver, sin tener que elegir variante por variante.

**Why this priority**: Tiendanube tiene pocos productos publicados hoy (menos de 100), así que el volumen no justifica automatizarlo por completo, pero sí evitar la carga manual uno por uno cuando el negocio ya tiene el archivo a mano.

**Independent Test**: subir el archivo exportado real de Tiendanube con una mezcla de SKU que matchean productos del CRM y SKU que no, y confirmar que el resultado lista qué se vinculó y qué no, con motivo, sin recargar la página.

**Acceptance Scenarios**:

1. **Given** el archivo de productos exportado desde Tiendanube, con filas cuyo SKU coincide exactamente con el código de un producto del CRM, y ese producto sigue publicado en el catálogo de Tiendanube, **When** se importa el archivo, **Then** se crea la vinculación usando el identificador real de Tiendanube (producto y variante) resuelto en vivo a partir del "Identificador de URL" de esa fila.
2. **Given** una fila cuyo SKU no coincide exactamente con ningún código de producto pero sí coincide con el número inicial del código de un producto (ej. SKU `27205` y código `27205 AL605028 BL`), **When** se importa el archivo, **Then** se crea la vinculación igual, usando esa coincidencia parcial.
3. **Given** una fila cuyo "Identificador de URL" ya no existe en el catálogo en vivo de Tiendanube (producto despublicado o eliminado después de exportar el archivo), **When** se importa el archivo, **Then** esa fila queda sin poder vincularse, con motivo, sin interrumpir el procesamiento de las demás filas.
4. **Given** una fila cuyo SKU no coincide con el código de ningún producto del CRM (exacto ni parcial), **When** se importa el archivo, **Then** esa fila queda sin poder vincularse, con motivo distinto al del escenario anterior.
5. **Given** una fila cuyo producto o variante ya tiene un vínculo existente, **When** se importa el archivo, **Then** esa fila queda como fallida sin modificar el vínculo ya existente.
6. **Given** el alta manual existente de vinculaciones de Tiendanube (selector con buscador), **When** un operador la usa después de esta spec, **Then** sigue funcionando exactamente igual que antes, sin cambios.

---

### Edge Cases

- ¿Qué pasa si una publicación de Mercado Libre tiene variantes (no soportadas, spec 012)? Sigue sin vincularse, mismo criterio que la vinculación manual ya existente.
- ¿Qué pasa si se sube dos veces el mismo archivo de Tiendanube? La segunda corrida no modifica nada de lo ya vinculado: todas las filas que ya matchearon la primera vez quedan como fallidas por "ya vinculado".
- ¿Qué pasa con un archivo de Tiendanube vacío, con extensión no soportada, o sin las columnas "SKU" o "Identificador de URL"? Se rechaza antes de procesar ninguna fila, con el motivo.
- ¿Qué pasa con filas del archivo de Tiendanube completamente vacías intercaladas entre filas válidas? Se ignoran, no cuentan como fallidas.
- ¿Qué pasa si dos filas del archivo de Tiendanube tienen el mismo SKU? La primera se vincula (si corresponde); la segunda queda fallida por "ya vinculado", igual que si fuera un archivo distinto.
- ¿Qué pasa si un producto del archivo de Tiendanube ya no existe en el catálogo en vivo al momento de importar (se borró o despublicó después de exportar el archivo)? Esa fila queda sin poder vincularse, con motivo, sin afectar a las demás.

## Requirements *(mandatory)*

### Functional Requirements

**Mercado Libre — vinculación automática**

- **FR-001**: El sistema MUST ofrecer un botón manual en la pantalla de vinculación de Mercado Libre para vincular automáticamente las publicaciones vistas en órdenes ya sincronizadas que todavía no tengan vínculo. La vinculación automática NO MUST dispararse sola como parte de la sincronización programada de órdenes (spec 012) — sólo corre cuando el operador la activa.
- **FR-002**: Por cada publicación sin vínculo, el sistema MUST comparar el SKU del vendedor visto en la orden sincronizada más reciente para esa publicación contra el `id` de los productos del CRM, **sin excluir productos inactivos** (a diferencia de otros selectores del CRM que sí los excluyen).
- **FR-003**: Si hay coincidencia y ni la publicación ni el producto tienen ya un vínculo, el sistema MUST crear el vínculo automáticamente, sin intervención manual.
- **FR-004**: Si no hay coincidencia, o la publicación no tiene SKU de vendedor cargado, o cualquiera de los dos lados ya está vinculado, el sistema MUST dejar esa publicación sin vincular e informar el motivo.
- **FR-005**: El sistema MUST mostrar un resumen de la corrida (vinculadas / no vinculadas con motivo) sin recargar la página.
- **FR-006**: La pantalla de vinculación de Mercado Libre MUST dejar de ofrecer alta manual de vínculos nuevos eligiendo la publicación desde un selector — esa creación queda reemplazada por la vinculación automática (FR-001). Sobre los vínculos ya existentes, la pantalla MUST seguir permitiendo eliminarlos y editar a mano el producto al que apuntan, igual que hoy.
- **FR-007**: Publicaciones con variantes MUST seguir sin poder vincularse por esta vía (mismo criterio ya vigente, spec 012 FR-027).
- **FR-008**: El sistema NO MUST modificar ni sobrescribir vínculos ya existentes de Mercado Libre por esta vía.

**Tiendanube — importación desde el export nativo**

- **FR-009**: El sistema MUST permitir subir, desde la pantalla de vinculación de Tiendanube, el archivo de productos tal como Tiendanube lo exporta (sin exigir un formato propio de esta spec).
- **FR-010**: El sistema MUST ubicar las columnas de SKU y de "Identificador de URL" dentro del archivo subido por su nombre de encabezado, no por una posición fija.
- **FR-011**: Por cada fila con SKU, el sistema MUST intentar resolver el producto del CRM cuyo código coincida exactamente con ese SKU; si no hay coincidencia exacta, MUST intentar con el número inicial del código del producto. **Sin excluir productos inactivos** (mismo criterio que FR-002 para Mercado Libre, por consistencia entre canales).
- **FR-012**: Por cada fila con producto resuelto, el sistema MUST resolver el identificador real de Tiendanube (variante y producto) consultando el catálogo de Tiendanube en vivo y buscando el "Identificador de URL" de esa fila entre los productos publicados.
- **FR-013**: Si no se puede resolver el producto del CRM, o el "Identificador de URL" no aparece en el catálogo en vivo, o cualquiera de los dos lados ya está vinculado, el sistema MUST dejar esa fila sin vincular e informar el motivo específico, sin interrumpir el procesamiento de las filas siguientes.
- **FR-014**: El sistema MUST reportar, al finalizar la importación, un resumen que distinga cuántas filas se vincularon y cuántas no, con el motivo de cada fila fallida, identificable por el usuario en su archivo original.
- **FR-015**: El sistema MUST rechazar de forma temprana (sin procesar ninguna fila) un archivo vacío, con un formato no soportado, o sin las columnas de SKU e "Identificador de URL" reconocibles.
- **FR-016**: Las vinculaciones creadas por esta importación MUST quedar reflejadas de inmediato en la tabla de la pantalla, sin recargar la página.
- **FR-017**: La importación NO MUST modificar ni sobrescribir vínculos ya existentes de Tiendanube — una fila cuyo SKU o producto ya está vinculado se reporta como fallida.
- **FR-018**: El alta manual de vinculaciones de Tiendanube (selector con buscador, spec 017) MUST seguir disponible sin cambios de comportamiento.

**General**

- **FR-019**: Mercado Libre y Tiendanube MUST seguir tratados como integraciones independientes entre sí, sin mecanismos compartidos.
- **FR-020**: El estado de sincronización de stock y precio que ya guarda cada vínculo (pendiente, sincronizado, error) NO MUST verse afectado por el mecanismo usado para crear el vínculo.

### Key Entities *(include if feature involves data)*

- **Producto**: entidad ya existente, sin cambios de estructura. Para los productos que se den de alta a partir de esta spec correspondientes a publicaciones ya existentes de Mercado Libre, su `id` (clave primaria) se asigna a propósito igual al identificador del sistema anterior del negocio, en vez de dejarlo automático — es la base del matching, no requiere ningún campo nuevo.
- **Vínculo publicación↔producto (Mercado Libre)** y **Vínculo variante↔producto (Tiendanube)**: entidades ya existentes (specs 012 y 017). No cambia su estructura ni lo que ya guardan (estado de sincronización de stock/precio); cambia únicamente el mecanismo por el que se crean.
- **Resultado de la importación de Tiendanube**: no es una entidad persistida — es el resumen de una corrida (filas vinculadas/fallidas con motivo), devuelto como respuesta, igual que el resto de las importaciones del CRM.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Un operador puede vincular automáticamente todas las publicaciones de Mercado Libre pendientes cuyo producto correspondiente ya exista en el CRM con el `id` correcto, en una sola operación, sin elegir ninguna publicación a mano.
- **SC-002**: Un operador puede importar el archivo de productos exportado directamente desde Tiendanube, sin editarlo ni adaptarlo a un formato especial, y obtener el resultado de la importación en menos de un minuto.
- **SC-003**: 100% de los vínculos de Mercado Libre y Tiendanube creados antes de esta spec siguen intactos después de desplegarla (ningún vínculo existente se modifica ni se pierde).
- **SC-004**: Reintentar la misma vinculación automática de Mercado Libre o la misma importación de Tiendanube una segunda vez dejar el 100% de lo ya vinculado sin cambios (ninguna sobrescritura).
- **SC-005**: El alta manual de vinculaciones de Tiendanube sigue funcionando exactamente igual que antes de esta spec, sin ninguna regresión.

## Assumptions

- El negocio va a asignar a propósito el `id` del producto en el CRM igual al "ID viejo" al dar de alta los productos que hoy sólo existen en Mercado Libre; los vínculos de Mercado Libre ya existentes (creados antes de esta spec) no se tocan ni se re-evalúan retroactivamente.
- El SKU de Mercado Libre sólo se puede conocer a partir de órdenes ya sincronizadas — no hay consulta al catálogo/listado de publicaciones en vivo de Mercado Libre en esta spec. Una publicación que nunca vendió no puede vincularse todavía por esta vía.
- El SKU de Tiendanube nunca se puede conocer en vivo (la integración conectada no lo expone) — sólo sale del archivo que el negocio exporta a mano. El catálogo en vivo de Tiendanube sí se consulta, pero únicamente para resolver los identificadores internos (producto y variante) a partir del "Identificador de URL", nunca para obtener el SKU.
- El archivo de Tiendanube a importar es el export de productos que la propia plataforma de Tiendanube ya ofrece generar — no un formato inventado para esta spec. Si Tiendanube cambia el formato de ese export en el futuro, la importación puede dejar de reconocer las columnas de SKU o "Identificador de URL" (fuera de alcance mantenerlo sincronizado con cambios futuros de un tercero).
- El "ID viejo" es el propio `id` de `productos` (no un campo nuevo); el campo `codigo` ya existente sigue siendo un dato distinto, sin relación fija con el `id`.
- Sin límite superior de tamaño de archivo para la importación de Tiendanube más allá de lo ya usado por el resto de las importaciones del CRM.
- Sin procesamiento asincrónico/por cola: tanto la vinculación automática de Mercado Libre como la importación de Tiendanube se resuelven en una sola operación síncrona, dado el volumen esperado (decenas a un par de cientos de productos).
