# Tasks: Sincronización forzada y eliminación masiva de Vinculaciones

**Input**: Design documents from `/specs/035-sincronizacion-forzada-vinculaciones/`
**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/rutas.md, quickstart.md

**Tests**: incluidos — la Constitución (principio IV) exige tests para lógica de stock/precio, y esta
feature toca ambas. Mockean el cliente HTTP de cada integración (ver research.md Decisión 5); ningún
test golpea la API real.

**Organización**: por historia de usuario (US1–US4 del spec), en orden de prioridad P1 → P2. US1 y US2
son ambas P1 (comparten toda la infraestructura de "sincronización forzada" — feliz + bloqueo), US3 es
P2 y reutiliza el mismo camino técnico que US1 sin tareas propias adicionales, US4 (eliminar todas) es
P2 e independiente.

## Phase 1: Setup

- [X] T001 Confirmar que `CACHE_STORE=database` en el entorno de trabajo local (requisito ya
      documentado para `Cache::lock`, ver `.claude/skills/deploy/SKILL.md` sección `CACHE_STORE`) —
      sin cambios de código, sólo verificación de `.env` local.

## Phase 2: Foundational (bloqueante para todas las historias)

**Objetivo**: extraer el loop compartido de `SincronizadorStock` para no duplicar lógica entre
`sincronizar()` (pendientes) y el `sincronizarTodos()` nuevo, en ambas integraciones.

- [X] T002 [P] Refactorizar `app/Services/MercadoLibre/SincronizadorStock.php`: extraer el cuerpo del
      `foreach` de `sincronizar()` a un método privado `procesarVinculos(iterable $vinculos): array`
      que reciba la colección de vínculos a procesar y devuelva `['actualizados' => int, 'con_error' =>
      int]`; `sincronizar()` pasa a llamarlo con `MercadoLibrePublicacionProducto::pendientes()->with('producto')->get()`.
      No debe cambiar el comportamiento observable de `sincronizar()`/`ejecutar()` existentes.
- [X] T003 [P] Mismo refactor en `app/Services/Tiendanube/SincronizadorStock.php`: extraer
      `procesarVinculos(iterable $vinculos): array` desde el `foreach` de `sincronizar()`, preservando
      el trato especial de `blank($vinculo->tn_product_id)` (FR-005a) y de producto eliminado.
- [X] T004 [P] Test de regresión en `tests/Feature/MercadoLibre/SincronizadorStockTest.php` (crear si no
      existe): confirmar que `ejecutar()` sigue procesando sólo pendientes después del refactor de T002
      (mock de `ClienteMercadoLibre`, 1 vínculo pendiente + 1 no pendiente, sólo el pendiente se envía).
- [X] T005 [P] Test de regresión equivalente en `tests/Feature/Tiendanube/SincronizadorStockTest.php`
      (crear si no existe) para el refactor de T003.

**Checkpoint**: con T002-T005 en verde, el comportamiento actual de "Sincronizar stock ahora" y el cron
no cambió — recién ahí se puede construir `sincronizarTodos()` sobre el método compartido.

---

## Phase 3: User Story 1 + 2 — Sincronización forzada (camino feliz y bloqueo) (Priority: P1) 🎯 MVP

**Goal**: Botón "Sincronización forzada" en ambas pantallas de Vinculaciones que recorre todos los
vínculos (stock + precio) y respeta los cortes existentes (función desactivada / modo sólo lectura /
sin conexión / candado tomado).

**Independent Test**: con vínculos sin flags de pendiente, accionar el botón actualiza stock y precio
de todos igual; con modo sólo lectura activo, accionar el botón no dispara ningún request y muestra el
toast de bloqueo correspondiente.

### Tests (antes de la implementación, ver Constitución principio IV)

- [X] T006 [P] [US1] Test en `tests/Feature/MercadoLibre/SincronizacionForzadaTest.php`: con N vínculos
      sin `stock_pendiente`/`precio_pendiente`, `POST
      ingresos/mercadolibre/vinculaciones/sincronizacion-forzada` actualiza los N vínculos (mock del
      cliente HTTP con respuesta OK) y devuelve `actualizados = N` en `stock` y `precio`.
- [X] T007 [P] [US1] Test en el mismo archivo: con `modo_solo_lectura = true`, el mismo endpoint
      devuelve 409, no se dispara ningún request al cliente HTTP mockeado (assert de que el mock no fue
      llamado), y se registra un único `MercadoLibreOperacionLog` con `resultado = 'bloqueada'`.
- [X] T008 [P] [US1] Test en el mismo archivo: con la función avanzada "mercadolibre" desactivada,
      mismo resultado que T007 (409, sin requests, un solo log de bloqueo).
- [X] T009 [P] [US1] Test en el mismo archivo: con un vínculo cuyo cliente HTTP responde error de stock
      Y de precio, el resto de los vínculos igual se procesan (continuidad, FR-006) y la respuesta
      informa `con_error >= 1` sin marcar `ok: false` general.
- [X] T009a [P] [US1] Test en el mismo archivo: dentro del batch de `sincronizarTodos()`, un vínculo
      cuyo `producto` fue eliminado del CRM (FR-010) se saltea sin request al cliente HTTP mockeado, y
      no corta el procesamiento del resto de los vínculos del batch.
- [X] T010 [P] [US1] Test en el mismo archivo: con el candado `ml:sincronizar_stock` ya tomado
      (simulado con `Cache::lock(...)->get()` previo en el test), el endpoint devuelve 409 con mensaje
      "Ya hay una sincronización en curso" y no procesa nada.
- [X] T011 [P] [US1] Tests equivalentes a T006-T009a en `tests/Feature/Tiendanube/SincronizacionForzadaTest.php`
      contra `POST ingresos/tiendanube/vinculaciones/sincronizacion-forzada` (mock de
      `ClienteTiendanubeRest`) — incluyendo el caso propio de Tiendanube de FR-009 (`blank($vinculo->tn_product_id)`
      se saltea sin request, equivalente a T009a pero para vínculo incompleto en vez de producto eliminado).

### Implementación

- [X] T012 [US1] Agregar `sincronizarTodos(): array` a `app/Services/MercadoLibre/SincronizadorStock.php`:
      mismo `verificarCortes()` + candado (`self::LOCK_KEY`) que `ejecutar()`, pero llama a
      `procesarVinculos(MercadoLibrePublicacionProducto::with('producto')->get())` (sin filtro
      `pendientes()`) y actualiza `stock_ultima_sync_en`/`stock_ultima_sync_resultado` igual que
      `sincronizar()`.
- [X] T013 [US1] Mismo método `sincronizarTodos()` en `app/Services/Tiendanube/SincronizadorStock.php`,
      usando `TiendanubeVarianteProducto::with('producto')->get()`.
- [X] T014 [US1] Agregar `sincronizacionForzada(): JsonResponse` a
      `app/Http/Controllers/Ingresos/MercadoLibreVinculacionController.php`: inyecta
      `SincronizadorStock` y `SincronizadorPrecios`, llama `sincronizarTodos()` y
      `sincronizarListaCompleta($configuracion->lista_precio_id)`. Si `lista_precio_id` es `null`, NO
      se llama a `sincronizarListaCompleta()` (evitar el error interno que devuelve ese método) — la
      respuesta combinada debe incluir `precio: null` (en vez de un objeto `{actualizados, con_error}`)
      para que el front distinga "no se intentó precio" de "se intentó y no hubo nada que actualizar".
      Combina resultados en el JSON de respuesta (ver contracts/rutas.md), status 200/409 según `ok`.
- [X] T015 [US1] Mismo método `sincronizacionForzada()` en
      `app/Http/Controllers/Ingresos/TiendanubeVinculacionController.php`, mismo criterio de
      `lista_precio_id` null que T014.
- [X] T016 [US1] Agregar las rutas `POST vinculaciones/sincronizacion-forzada` en ambos grupos de
      `routes/web.php` (dentro del `Route::prefix('vinculaciones')` existente, junto a las demás — ver
      contracts/rutas.md nota de orden de rutas).
- [X] T017 [P] [US1] Agregar el botón "Sincronización forzada" en
      `resources/views/ingresos/mercadolibre/vinculaciones/index.blade.php`, con estado de carga
      (spinner) mientras dura el request AJAX y toast de resumen/error al terminar (Toastr, patrón ya
      usado por los botones "Sincronizar ahora" existentes en esa misma vista).
- [X] T018 [P] [US1] Mismo botón en `resources/views/ingresos/tiendanube/vinculaciones/index.blade.php`.
- [X] T019 [US1] Actualizar `docs/documentacion_principal_crm.md` (secciones de Vinculación Mercado
      Libre ~línea 545 y Vinculación Tiendanube ~línea 670): documentar el botón "Sincronización
      forzada" junto a los ya listados ("Sincronizar ahora", "Sincronizar stock ahora", "Sincronizar
      precios ahora"), explicando que recorre todos los vínculos en vez de sólo pendientes (principio I
      de la constitución — doc y spec no pueden divergir).

**Checkpoint**: US1+US2 completas y testeadas → botón funcional en ambas pantallas, es el MVP de esta
feature (resuelve el caso real que la disparó: catálogo importado sin sincronizar).

---

## Phase 4: User Story 3 — Resincronización puntual (Priority: P2)

**Goal**: Mismo botón de US1 reutilizado en cualquier momento, no sólo en la carga inicial.

**Independent Test**: con vínculos ya sincronizados (sin pendientes), accionar el botón igual reenvía
stock/precio actual.

- [X] T020 [US3] Sin tareas de implementación nuevas — ya cubierto por T012/T013 (`sincronizarTodos()`
      no filtra por estado previo, así que reintenta vínculos ya sincronizados igual). Agregar
      únicamente el caso de test explícito en `tests/Feature/MercadoLibre/SincronizacionForzadaTest.php`
      y su equivalente Tiendanube: vínculos con `stock_sincronizado_en` reciente y sin error previo
      también se reenvían al accionar `sincronizarTodos()`.

**Checkpoint**: US3 verificada como consecuencia directa de la implementación de US1 — sin código nuevo.

---

## Phase 5: User Story 4 — Eliminar todas las vinculaciones (Priority: P2)

**Goal**: Botón "Eliminar todas las vinculaciones" en ambas pantallas, con confirmación previa, borrado
atómico del lado CRM únicamente, respetando el candado de concurrencia (no los cortes de función
desactivada/modo sólo lectura, ver FR-020).

**Independent Test**: con vínculos existentes, accionar + confirmar deja la tabla vacía sin ningún
request de escritura hacia la plataforma externa.

### Tests

- [X] T021 [P] [US4] Test en `tests/Feature/MercadoLibre/EliminarTodasVinculacionesTest.php`: con N
      vínculos existentes, `DELETE ingresos/mercadolibre/vinculaciones` los borra todos
      (`MercadoLibrePublicacionProducto::count() === 0` después), devuelve `eliminados: N`, y el mock
      del cliente HTTP no recibe ningún request (assert de cero llamadas).
- [X] T022 [P] [US4] Test en el mismo archivo: con el candado `ml:sincronizar_stock` (o el que
      corresponda) tomado, el endpoint devuelve 409 "Ya hay una sincronización en curso" y no borra
      nada.
- [X] T023 [P] [US4] Test en el mismo archivo: simular una excepción de base de datos a mitad del
      borrado (ej. mock que lanza en el segundo delete) y confirmar que la transacción revierte — el
      conteo de vínculos queda igual al inicial (FR-021, atomicidad).
- [X] T024 [P] [US4] Test en el mismo archivo: sin conexión establecida con la integración, el endpoint
      corta y no ejecuta el borrado (FR-020).
- [X] T025 [P] [US4] Tests equivalentes T021-T024 en
      `tests/Feature/Tiendanube/EliminarTodasVinculacionesTest.php` contra
      `DELETE ingresos/tiendanube/vinculaciones`.

### Implementación

- [X] T026 [US4] Agregar `eliminarTodas(): JsonResponse` a
      `app/Http/Controllers/Ingresos/MercadoLibreVinculacionController.php`: corte de conexión no
      establecida (sin cortes de función desactivada/sólo lectura, FR-020), candado
      `Cache::lock(SincronizadorStock::LOCK_KEY, ...)` (reutilizar la misma constante, no crear una
      nueva), `DB::transaction()` que hace `MercadoLibrePublicacionProducto::count()` y luego
      `->delete()`, registra un único `MercadoLibreOperacionLog` (operación
      `eliminar_todas_vinculaciones`, `sentido` interno/no aplica) con la cantidad eliminada (FR-022),
      responde `{ ok: true, eliminados: N }`.
- [X] T027 [US4] Mismo método `eliminarTodas()` en
      `app/Http/Controllers/Ingresos/TiendanubeVinculacionController.php`.
- [X] T028 [US4] Agregar las rutas `DELETE vinculaciones` en ambos grupos de `routes/web.php`, **antes**
      de la ruta existente `DELETE {vinculacion}` dentro del mismo `Route::prefix('vinculaciones')` (ver
      contracts/rutas.md, riesgo de ambigüedad de matching).
- [X] T029 [P] [US4] Agregar el botón "Eliminar todas las vinculaciones" + modal de confirmación
      Bootstrap (texto explícito de que es irreversible y cuántos vínculos se van a borrar) en
      `resources/views/ingresos/mercadolibre/vinculaciones/index.blade.php`; al confirmar, dispara el
      DELETE por AJAX, vacía la tabla (recarga del DataTable server-side) y muestra el toast de resumen.
- [X] T030 [P] [US4] Mismo botón + modal en
      `resources/views/ingresos/tiendanube/vinculaciones/index.blade.php`.
- [X] T031 [US4] Actualizar `docs/documentacion_principal_crm.md` (mismas secciones que T019) con el
      botón "Eliminar todas las vinculaciones" y su comportamiento (borrado sólo CRM, no despublica).

**Checkpoint**: US4 completa y testeada — funcionalidad independiente de US1-US3, puede implementarse
en paralelo por otro desarrollador una vez completada Phase 2.

---

## Phase 6: Polish & Cross-Cutting

- [X] T032 [P] Revisar `specs/035-sincronizacion-forzada-vinculaciones/quickstart.md` contra el
      comportamiento final implementado y corregir cualquier desvío antes de la validación manual del
      usuario en el entorno real (ver Assumptions del spec — sin tests automatizados contra la API
      real).
- [X] T033 [P] Confirmar en ambas vistas que los botones nuevos siguen las specs de diseño obligatorias
      del proyecto (CLAUDE.md): toasts vía Toastr, modal de confirmación Bootstrap + AJAX (sin recargar
      página), sin `window.confirm()` nativo.

## Nota de trazabilidad (post-`/speckit-analyze`)

- **FR-014** (registrar en el historial cada actualización/error/corte de la sincronización forzada) no
  tiene una tarea de implementación propia: `ClienteMercadoLibre::enviar()` /
  `ClienteTiendanubeRest::escribir()` ya registran un `OperacionLog` por cada request individual (éxito
  o error), y `sincronizarTodos()`/`sincronizarListaCompleta()` reutilizan esos clientes tal cual — el
  requisito queda satisfecho automáticamente sin código nuevo.

## Dependencies & Execution Order

- **Phase 1 (Setup)** → sin dependencias.
- **Phase 2 (Foundational)** → depende de Phase 1. Bloquea Phase 3 (US1/US2) porque
  `sincronizarTodos()` se construye sobre `procesarVinculos()`. NO bloquea Phase 5 (US4, eliminación),
  que es independiente del refactor de stock.
- **Phase 3 (US1+US2, P1)** → depende de Phase 2. Es el MVP.
- **Phase 4 (US3, P2)** → depende de Phase 3 (reutiliza su implementación, sin tareas propias de
  código).
- **Phase 5 (US4, P2)** → depende sólo de Phase 1. Puede implementarse en paralelo con Phase 2-4 por
  otra persona/sesión, ya que no toca `SincronizadorStock`/`SincronizadorPrecios`.
- **Phase 6 (Polish)** → depende de que Phase 3 y Phase 5 estén completas.

## Parallel Execution Examples

- Dentro de Phase 2: T002 y T003 en paralelo (archivos distintos, integraciones distintas); T004 y T005
  en paralelo entre sí (y pueden empezar en cuanto termine su refactor correspondiente, T002/T003
  respectivamente).
- Dentro de Phase 3: T006-T011 (todos los tests) en paralelo entre sí antes de tocar implementación;
  T017 y T018 (las dos vistas) en paralelo una vez que T014-T016 están listos.
- Dentro de Phase 5: T021-T025 (todos los tests) en paralelo; T029 y T030 (las dos vistas) en paralelo.
- **Cross-phase**: todo Phase 5 (US4) puede correr en paralelo con Phase 2-4 (US1/US2/US3) — no
  comparten archivos de servicio, sólo comparten `routes/web.php` y los mismos dos archivos blade (ahí
  sí hay que coordinar el orden de edición si son sesiones distintas, para no pisarse el mismo archivo).

## Implementation Strategy

**MVP primero**: Phase 1 → Phase 2 → Phase 3 (US1+US2) es el entregable mínimo que resuelve el
problema real (catálogo importado sin sincronizar, con feedback claro si el modo sólo lectura bloquea
la acción). Phase 4 (US3) no agrega código, ya viene incluida. Phase 5 (US4, eliminar todas) es
valiosa pero no bloqueante — se puede entregar en una iteración separada si hace falta priorizar.
