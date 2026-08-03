# Research: Sincronización forzada y eliminación masiva de Vinculaciones

Sin `NEEDS CLARIFICATION` pendientes en el Technical Context — no hay incógnitas de stack (se reutiliza
el existente). Este documento consolida las decisiones de diseño tomadas al revisar el código actual.

## Decisión 1: Reutilizar `SincronizadorPrecios::sincronizarListaCompleta()` para precio, sin cambios

**Decisión**: La sincronización forzada de precio usa el método `sincronizarListaCompleta(int
$listaPrecioId)` que ya existe en `App\Services\MercadoLibre\SincronizadorPrecios` y
`App\Services\Tiendanube\SincronizadorPrecios`.

**Rationale**: Este método (agregado en spec 016/018 ampliación, US5) ya itera sobre **todos** los
vínculos con `MercadoLibrePublicacionProducto::with('producto')->get()` (o su equivalente en
Tiendanube) — no filtra por `pendientesPrecio()`. Ya resuelve exactamente lo que pide FR-004/FR-005
del spec: calcular precio desde la lista de precios por defecto, marcar pendiente antes del intento
(así un bloqueo no pierde el cambio), y continuar el barrido aunque falle un vínculo puntual. No hace
falta escribir un método nuevo para precio.

**Alternativas consideradas**: Escribir un método `sincronizarPrecioTodos()` nuevo, calcado de
`enviarPendientes()` pero sin el filtro — se descartó porque sería lógica duplicada 1:1 con
`sincronizarListaCompleta()`, que ya existe y ya está probada en producción.

## Decisión 2: Agregar `sincronizarTodos()` nuevo a `SincronizadorStock` (ambas integraciones)

**Decisión**: No existe hoy un equivalente de `sincronizarListaCompleta()` para stock. Se agrega un
método público `sincronizarTodos(): array` a `App\Services\MercadoLibre\SincronizadorStock` y
`App\Services\Tiendanube\SincronizadorStock`, calcado de `sincronizar()` (el método privado que ya usa
`ejecutar()`) pero recorriendo `MercadoLibrePublicacionProducto::with('producto')->get()` /
`TiendanubeVarianteProducto::with('producto')->get()` en vez del scope `pendientes()`.

**Rationale**: `sincronizar()` privado ya tiene toda la lógica de cálculo de disponibilidad
(`StockService::disponibilidad()`), envío, y manejo de error por vínculo (FR-014/FR-015 del spec 013 /
FR de spec 018) — sólo cambia la fuente de vínculos a iterar. Se extrae ese loop a un método privado
compartido (`private function procesarVinculos(iterable $vinculos): array`) reutilizado por
`sincronizar()` (pendientes) y `sincronizarTodos()` (todos), para no duplicar la lógica de
cálculo/envío/registro de error.

**Alternativas consideradas**:
- Parametrizar `ejecutar(bool $todos = false)`: se descartó porque el candado (`Cache::lock`) y los
  cortes previos (`verificarCortes()`) son iguales en ambos casos, pero mezclar dos semánticas de
  "pendientes vs todos" en un solo método con flag booleano es menos legible que dos métodos públicos
  con nombre explícito (`ejecutar()` / `sincronizarTodos()`), que es además el patrón que ya usa
  `SincronizadorPrecios` (`ejecutar()` vs `sincronizarListaCompleta()`).

## Decisión 3: Orquestación desde el controller, no un servicio nuevo compartido

**Decisión**: El método `sincronizacionForzada()` en cada `VinculacionController` (Mercado Libre y
Tiendanube) llama secuencialmente a `SincronizadorStock::sincronizarTodos()` y
`SincronizadorPrecios::sincronizarListaCompleta($configuracion->lista_precio_id)`, combina ambos
resultados (`actualizados`/`con_error` de stock + de precio) en un único JSON de respuesta, y el
front arma un toast con el resumen combinado.

**Rationale**: Stock y precio ya son servicios independientes con sus propios candados
(`ml:sincronizar_stock` vs `ml:sincronizar_precios`) — no hay necesidad de un candado combinado nuevo
ni de una clase orquestadora nueva; el controller es el lugar natural para componer dos llamadas de
servicio existentes, igual que ya hacen los controllers actuales al inyectar ambos sincronizadores
(`TiendanubeVentaController` ya inyecta `SincronizadorStock` y `SincronizadorPrecios` juntos).

**Alternativas consideradas**: Crear un `SincronizadorCompleto` nuevo que envuelva a los dos — se
descartó por ser una capa de indirección sin beneficio (el controller ya puede componer dos llamadas
sin abstracción adicional), y porque el spec no pide un candado conjunto (FR-008 pide reusar el
candado ya existente de cada sincronizador, no uno nuevo).

## Decisión 4: Eliminación masiva vive en el `VinculacionController` existente, borrado físico simple

**Decisión**: Se agrega `eliminarTodas(): JsonResponse` a `MercadoLibreVinculacionController` y
`TiendanubeVinculacionController` (los mismos controllers que ya tienen `index`, `datatable`,
`vincularAutomaticamente`, `update`, `destroy`). Usa `MercadoLibrePublicacionProducto::query()->delete()`
/ `TiendanubeVarianteProducto::query()->delete()` (borrado físico, sin filtro — FR-017/clarificación:
ignora filtros de la tabla), envuelto en el mismo candado (`Cache::lock`) que las sincronizaciones de
esa integración para evitar borrar vínculos que un sincronizador concurrente está leyendo/actualizando
(FR-018).

**Rationale**: Estos modelos no usan `SoftDeletes` (a diferencia de las entidades fiscales de la
Constitución, principio III, que sí exigen soft delete — pero un vínculo de integración no es un
documento fiscal ni contable, es un dato de sincronización recreable con "vincular automáticamente").
Un `->delete()` simple sobre el modelo (no `deleteQuietly` ni raw SQL) para que, si en el futuro se
agregan observers/eventos sobre estos modelos, se disparen igual.

**Alternativas consideradas**: Soft delete con posibilidad de deshacer — se descartó porque el spec
(Assumptions) ya definió que es un borrado irreversible con confirmación previa, no una papelera; y
porque el vínculo se puede reconstruir fácilmente con "Vincular automáticamente" (ya existente), a
diferencia de un documento fiscal.

## Decisión 5: Testing sin requests reales a las APIs externas

**Decisión**: Los tests automatizados de esta feature mockean `ClienteMercadoLibre` y
`ClienteTiendanubeRest` (o el binding de Laravel de esas clases) para simular respuestas exitosas y
fallidas, sin hacer ningún request HTTP real. La validación funcional real (contra el catálogo real
del cliente) la hace el usuario manualmente después del deploy, no un test automatizado.

**Rationale**: El proyecto está conectado en producción contra la cuenta y catálogo reales de un
cliente (Tiendanube y Mercado Libre) — un test que golpee la API real podría modificar datos reales del
negocio del cliente. Esto es una restricción operativa explícita del usuario para esta feature, no una
preferencia técnica.

**Alternativas consideradas**: Ninguna — un test contra la integración real está descartado de plano
por el contexto operativo actual (mismo criterio que ya usan los tests existentes del proyecto para
estas integraciones, que mockean el cliente HTTP).
