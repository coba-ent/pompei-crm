# Research: Conversión manual en lote de órdenes a Venta

No quedan `NEEDS CLARIFICATION` en el Technical Context — el Technical Context se resolvió por
completo a partir del código ya existente (dos stacks paralelos, TN y ML, con el mismo patrón).
Este documento registra las decisiones de diseño tomadas para reusar al máximo lo ya construido.

## R1 — Dónde vive la orquestación del lote

**Decisión**: agregar un método público `convertirTodasLasListas(?int $usuarioId): array` a cada
`ConversorOrdenAVenta` (Tiendanube y MercadoLibre), que internamente itera
`TiendanubeOrden::where('estado_conversion', EstadoConversion::Lista)->get()` (análogo en ML) y
llama a `$this->convertir($orden, $usuarioId, automatica: false)` por cada una — el mismo método ya
usado por la conversión individual y por `SincronizadorOrdenes::intentarCreacionAutomatica`.

**Rationale**: `convertir()` ya encapsula candado por orden, evaluación de convertibilidad,
transacción atómica y persistencia de `motivo`/`motivo_detalle` en caso de rechazo — no hay que
reimplementar ninguna regla de negocio, sólo el bucle y el resumen. Poner el bucle en el propio
servicio (no en el controller) mantiene el controller fino y es simétrico a cómo
`VinculadorAutomatico::ejecutar()` encapsula su propio bucle (spec 021).

**Alternativas consideradas**:
- *Loop en el Controller*: rechazada — dejaría lógica de negocio (qué órdenes entran al lote) fuera
  del servicio, inconsistente con el resto del código de conversión.
- *Nuevo servicio `ConversionEnLote` separado*: rechazada — no aporta nada frente a un método más en
  la clase que ya orquesta la conversión; multiplicaría clases sin necesidad (regla de simplicidad
  del proyecto).

## R2 — Guardrails antes de iterar el lote

**Decisión**: el método de lote reusa exactamente los mismos dos chequeos que
`SincronizadorOrdenes::verificarCortes()` hace hoy antes de sincronizar: función avanzada
(`FuncionAvanzada::where('clave', 'tiendanube'|'mercadolibre')->value('activa')`) y
`modo_solo_lectura` de la conexión/configuración vigente. Si cualquiera bloquea, el método devuelve
inmediatamente `{ok: false, tipo: 'bloqueada', mensaje: ...}` sin tocar ninguna orden.

**Rationale**: son exactamente los guardrails que la spec (FR-005) pide replicar; ya existen como
código probado en `SincronizadorOrdenes`, sólo se invocan desde el nuevo método (duplicando el
chequeo puntual de dos líneas, no extrayendo una abstracción nueva que no se pidió).

**Alternativas consideradas**: extraer un trait/objeto compartido de "guardrails de integración" —
rechazada por ahora: sólo dos call sites por integración (sync + batch manual), no justifica la
abstracción; se puede revisar si aparece un tercer consumidor.

## R3 — Concurrencia con la sincronización automática

**Decisión**: no se agrega ningún lock nuevo a nivel de lote. Cada llamada a `convertir()` ya toma
`Cache::lock("tn:convertir_orden:{id}", 30)` (o `ml:convertir_orden:{id}`) por orden individual; si
la sincronización automática está convirtiendo esa misma orden en paralelo, el batch manual recibe
el rechazo ya contemplado ("Esta orden ya se está convirtiendo...") o, si llegó un instante después,
el `QueryException` de índice único ya capturado como "ya tiene una Venta asociada". En ambos casos
la spec (Edge Cases) permite tratarlo como falla informativa sin romper el resto del lote — no hace
falta prevenir la carrera, sólo no bombardear al usuario con un error confuso.

**Rationale**: el mecanismo anti-duplicado ya es correcto y suficiente (spec.md SC-003); agregar un
lock de "todo el lote" sería redundante y además bloquearía innecesariamente la sincronización
automática mientras el batch manual esté corriendo (cientos de órdenes) — peor UX que dejar que el
candado por orden haga su trabajo.

**Alternativas consideradas**: lock global tipo `SincronizadorOrdenes::LOCK_KEY` para todo el batch —
rechazada, ver rationale.

## R4 — Forma de la respuesta del endpoint

**Decisión**: mismo shape que `VinculadorAutomatico::ejecutar()` ya devuelve y que
`mercadolibre-vinculaciones.js`/`tiendanube-vinculaciones.js` ya saben pintar:
`{ok: true, mensaje, total, convertidas, fallidas, detalle_fallidas: [{orden, motivo, motivo_detalle}]}`.
El JS nuevo (`inicializarTransformarTodasEnVenta`) es una copia mínima de
`inicializarVinculacionAutomatica`, ajustando el nombre de los campos (`orden` en vez de
`referencia`) y usando la `etiqueta()` que los enums `MotivoRequiereAtencion` de cada integración ya
exponen (no hace falta un diccionario de motivos en JS como el `MOTIVOS_ML` actual de vinculaciones,
porque el backend puede mandar la etiqueta ya resuelta en español).

**Rationale**: minimiza la superficie nueva de JS/CSS — reusa un patrón visual ya validado por el
usuario en las pantallas de vinculación automática (spec 021/023), consistente con la regla de
notificaciones/[modal Bootstrap] del proyecto.

## R5 — Identificador de orden en el detalle de fallos

**Decisión**: el campo `orden` del detalle expone el número de orden visible al usuario (`numero`
en Tiendanube / `ml_order_id` o el número mostrado en el listado de ML), no el `id` interno de la
tabla — para que el usuario pueda ubicarla en el listado sin traducir un ID técnico.

**Rationale**: consistente con cómo ya se identifica la orden en el resto de la UI de ventas
(columna "Orden" del DataTable existente).
