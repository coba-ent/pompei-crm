# Research: Modal NC/ND completo

Sin incógnitas técnicas — la spec no dejó ningún `[NEEDS CLARIFICATION]` y el stack, las
convenciones y el patrón de módulos espejados (Compras/Ventas) ya están fijados por la
constitución del proyecto y por el código existente. Este documento deja registradas las
decisiones de diseño tomadas al planificar, con su alternativa descartada.

## Decisión: tope de cantidad se valida en el backend, no sólo en el JS

**Decisión**: `StoreNotaCreditoDebitoRequest` valida server-side que la cantidad de cada
`item.cantidad` no supere lo pendiente de ajustar en el comprobante original (cantidad facturada
menos lo ya ajustado por notas previas de ese mismo comprobante para ese producto).

**Rationale**: el JS ya limita visualmente la cantidad máxima en el selector, pero por
Principio IV de la constitución (dinero + stock) la validación real tiene que vivir en el
backend — el JS es sólo UX, no la fuente de verdad. Evita además condiciones de carrera entre dos
notas creadas casi al mismo tiempo sobre el mismo comprobante.

**Alternativas consideradas**: validar sólo en JS (rechazada: no es confiable como control de
integridad de stock); bloquear a nivel de base de datos con un trigger (rechazada: fuera del
patrón del proyecto, que resuelve estas reglas en el FormRequest/Service, no en SQL).

## Decisión: "Documento que Ajusta" sigue siendo de sólo lectura

**Decisión**: el nuevo selector muestra el comprobante ya fijado (no permite elegir otro), sólo
cambia su apariencia visual de "input deshabilitado" a "selector" para calzar con Contagram.

**Rationale**: ya documentado como Assumption en el spec — la ruta actual anida la creación de la
nota bajo un comprobante puntual (`/compras/{compra}/notas-credito-debito`,
`/ventas/{venta}/notas-credito-debito`); permitir elegir otro documento requeriría rediseñar el
punto de entrada al modal (dejar de abrirse desde el detalle de un comprobante puntual), que está
fuera del alcance de este pedido.

**Alternativas consideradas**: selector funcional multi-documento (rechazada por alcance: ese es
un cambio de flujo de entrada, no de completar campos faltantes); dejarlo como input deshabilitado
tal cual está hoy (rechazada: no cumple el pedido explícito de que "coincida estructuralmente con
Contagram real").

## Decisión: `mes_imputacion` se guarda como `date` con día fijo (día 1 del mes)

**Decisión**: columna `mes_imputacion` tipo `date`, se persiste siempre con día `01` (ej.
`2026-08-01` para "Agosto 2026"), y se muestra formateada como "Mes Año" en la UI.

**Rationale**: evita agregar un tipo de dato ad-hoc (year+month como dos columnas separadas o un
string libre) cuando Laravel/MySQL ya modelan bien un período mensual con un `date` normalizado al
primer día — permite ordenar/filtrar con las herramientas estándar de Eloquent (`whereMonth`,
`whereYear`) sin parsing adicional, y es coherente con cómo el resto del proyecto guarda fechas
(`date`, no strings).

**Alternativas consideradas**: dos columnas `int` (`mes_imputacion_mes`, `mes_imputacion_anio`)
(rechazada: más columnas para el mismo dato, sin ganancia real ya que Eloquent resuelve
mes/año sobre un `date` igual de bien); string libre "08/2026" (rechazada: no es un tipo real,
complica ordenamiento y validación).
