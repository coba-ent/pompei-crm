# Research: Reevaluación automática de órdenes por vinculación tardía

No quedaron `NEEDS CLARIFICATION` en Technical Context — el patrón a seguir ya existe en el
proyecto y se relevó directamente del código. Este documento deja registradas las decisiones y
por qué se descartaron alternativas.

## R1. Mecanismo evento-driven: Eloquent Observer + `DB::afterCommit`

**Decision**: usar un `Observer` de Eloquent sobre `MercadoLibrePublicacionProducto` (eventos
`saved` y `deleted`) y sobre `TiendanubeVarianteProducto` (mismos eventos), registrados en
`AppServiceProvider::boot()`, envolviendo la reevaluación en `DB::afterCommit(fn () => ...)`.

**Rationale**: es exactamente el patrón que el proyecto ya usa para el caso estructuralmente
idéntico de "un cambio en una entidad dispara efectos en otras" —
`app/Observers/PrecioProductoObserver.php` reacciona a `PrecioProducto::saved()` y dispara envío
de precios a ML/TN después del commit. Reutilizar el patrón evita introducir Jobs/Events/Listeners
nuevos que el resto del código no usa (ver R2), y `afterCommit` es necesario porque la vinculación
recién existe de forma consistente en la base una vez terminada la transacción del controlador
(`VinculadorAutomatico::ejecutar()` puede crear varias vinculaciones dentro de una misma
transacción — no se quiere reevaluar a mitad de un lote).

**Alternatives considered**:
- *Laravel Events + Listeners*: el proyecto no tiene `EventServiceProvider` con listeners de
  dominio; hubiera introducido un patrón nuevo sin precedente para un caso que Observers ya
  resuelve. Descartado por consistencia (Principio V: no pelear contra las convenciones ya
  adoptadas).
- *Job en cola*: la reevaluación es una operación local (lecturas/escrituras SQL, sin llamadas a
  APIs externas de ML/TN), rápida incluso para cientos de órdenes; encolarla agrega latencia
  percibida (el usuario no ve el efecto inmediato) y complejidad operativa sin beneficio. Se
  reserva colas para lo que el proyecto ya usa colas (reintentos de CAE, mails, abonos
  recurrentes — Restricciones Técnicas de la constitución). Si en el futuro el volumen crece
  mucho, se puede mover el mismo servicio a un Job sin cambiar su contrato.

## R2. Punto de disparo: `saved`/`deleted` del modelo de vinculación, no en los controladores

**Decision**: instrumentar los eventos del modelo (Observer), no agregar llamadas manuales en
cada método de `MercadoLibreVinculacionController`/`TiendanubeVinculacionController`.

**Rationale**: hay múltiples puntos de mutación por controlador (`vincularAutomaticamente` →
`VinculadorAutomatico::ejecutar()` con creación masiva, `update()`, `destroy()`,
`eliminarTodas()` con `MercadoLibrePublicacionProducto::query()->delete()` — este último es un
borrado masivo por query que **no dispara eventos de Eloquent individuales**). Atar la lógica a
los eventos del modelo cubre `vincularAutomaticamente`, `update()` y `destroy()`
automáticamente sin tocar esos controladores. `eliminarTodas()` es la única vía que no dispara
`deleted` (borrado masivo), y ya hoy no reevalúa nada — se documenta como límite conocido (ver
Edge Cases del spec: no está en el alcance de FR-001/FR-002 porque un borrado masivo de todas las
vinculaciones no es el escenario reportado por el usuario, que fue vincular, no desvincular en
masa). Si se detecta que `eliminarTodas()` necesita el mismo tratamiento, es una extensión de
alcance a evaluar aparte.

**Alternatives considered**:
- *Instrumentar en los controladores*: más explícito pero duplicado en 2 controladores × varios
  métodos × 2 canales; con Observer se instrumenta una vez por canal y cubre todos los paths de
  escritura vía Eloquent automáticamente.

## R3. Servicio reusable `ReevaluadorOrdenes` (uno por canal)

**Decision**: extraer a `App\Services\{MercadoLibre,Tiendanube}\ReevaluadorOrdenes` la secuencia
"resolver cliente (para `clienteEsAmbiguo`) → `EvaluadorConvertibilidad::evaluar()` → persistir
`estado_conversion`/`motivo`/`motivo_detalle` → si quedó `Lista` y `creacion_automatica` está
activo, intentar `ConversorOrdenAVenta::convertir(..., automatica: true)` con el mismo try/catch
que ya existe en `SincronizadorOrdenes::intentarCreacionAutomatica()`", parametrizada por una
orden puntual.

**Rationale**: esa secuencia ya existe, pero está inline dentro de
`SincronizadorOrdenes::procesarOrden()`/`intentarCreacionAutomatica()` (ML) y su análogo TN — no
es invocable desde otro lugar sin duplicar el bloque completo. El Observer y el `datatable()`
on-view necesitan exactamente esa misma secuencia, para exactamente una orden a la vez (Observer)
o para un conjunto de órdenes (on-view, iterando). Extraerla evita divergencia futura entre "cómo
se decide que una orden queda lista" en sync vs. en reevaluación reactiva.

**Alternatives considered**:
- *Duplicar el bloque en el Observer*: rechazado — viola DRY y arriesga que sync y reevaluación
  reactiva terminen tomando decisiones distintas ante el mismo estado si alguien cambia una sin
  la otra.
- *Refactorizar `SincronizadorOrdenes` para que también use el servicio nuevo, en esta misma
  feature*: fuera de alcance — el plan explícitamente no modifica `SincronizadorOrdenes` para
  no arriesgar regresiones en el flujo de sincronización, que no es el problema reportado. Queda
  como mejora futura natural (el propio `SincronizadorOrdenes` podría llamar al mismo servicio),
  pero no es necesaria para resolver esta feature.

## R4. Alcance de la query "órdenes afectadas por este ítem/variante"

**Decision**: `MercadoLibreOrden::whereNull('venta_id')->whereIn('estado_conversion', [EstadoConversion::RequiereAtencion->value, EstadoConversion::Lista->value])->whereHas('items', fn ($q) => $q->where('ml_item_id', $mlItemId))->get()`
(análogo para TN con `variant_id`), ejecutada dentro del servicio `ReevaluadorOrdenes`, no como
scope público reusable en el modelo (evita acoplar el modelo a un caso de uso específico; si en
el futuro se repite se puede promover a scope).

**Rationale**: `whereNull('venta_id')` cubre FR-005 (no tocar convertidas). Incluir tanto
`RequiereAtencion` como `Lista` (no sólo `RequiereAtencion`) es necesario por FR-010: si se
**desvincula** una publicación/variante de la que dependía una orden que ya estaba `Lista` (pero
todavía no convertida a venta), esa orden tiene que poder volver a `requiere_atencion` — si sólo
se reevaluaran las que ya están en `requiere_atencion`, ese caso de desvinculación quedaría sin
cubrir. `PendientePago` queda fuera porque ese estado no depende de vinculación (es previo a
cualquier evaluación de convertibilidad). `Cancelada` queda fuera porque es terminal.

## R5. Reevaluación on-view: dónde y con qué alcance

**Decision**: dentro de `datatable()` de `MercadoLibreVentaController`/`TiendanubeVentaController`,
antes de construir la respuesta de `DataTables::eloquent()`, obtener las órdenes en
`requiere_atencion` no convertidas del canal y pasarlas una por una al mismo
`ReevaluadorOrdenes` (sin el guard de "sólo si el ítem específico cambió" — acá se re-chequea
todo el conjunto, es la red de seguridad).

**Rationale**: es el único punto donde ambos controladores arman el listado (spec FR-006/FR-007);
hacerlo ahí, antes del query que alimenta la tabla, garantiza que lo que el usuario ve ya está
corregido. No se condiciona a que el usuario esté filtrando por `requiere_atencion` — se
reevalúa siempre que se abre el datatable, porque es barato (ver R6) y evita casos donde el
usuario filtra por otro estado y no dispara la corrección.

**Alternatives considered**:
- *Sólo en `index()` (la vista, no el AJAX)*: `index()` no trae los datos, sólo renderiza el
  shell de la tabla (DataTables carga por AJAX según las Especificaciones de diseño obligatorias
  del proyecto) — reevaluar ahí no serviría porque el primer `datatable()` llega inmediatamente
  después y podría correr contra estado no reevaluado si hay alguna condición de carrera; más
  simple y correcto reevaluar directamente en `datatable()`.

## R6. Costo de performance de la barrida on-view

**Decision**: aceptable reevaluar sin paginar (recorrer todas las `requiere_atencion` del canal)
en cada `datatable()`, dado el volumen real observado (396 y 3 respectivamente en producción al
momento de este plan) y el `Assumption` ya validado en el spec (cientos, no decenas de miles).

**Rationale**: cada reevaluación es 1-2 queries SQL locales (sin llamadas HTTP a ML/TN) por
orden; con cientos de órdenes el costo es del orden de milisegundos por request, sin impacto
perceptible (SC-003). Si el volumen creciera órdenes de magnitud, se podría acotar a "sólo las
`requiere_atencion` cuya publicación/variante tiene un vínculo más reciente que la última
reevaluación" — no se implementa ahora porque no hay evidencia de que haga falta (YAGNI).
