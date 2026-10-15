# Tasks: Cambio de tipo de publicación de Mercado Libre (Clásica ↔ Premium)

**Input**: Design documents from `specs/115-ml-cambio-tipo-publicacion/`
**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/rutas-internas.md, quickstart.md

**Tests**: OBLIGATORIOS (constitución IV: la feature cambia precios publicados). Se escriben antes de la
implementación de cada historia y tienen que fallar primero. Todos con `Http::fake()`: ninguna llamada
real a Mercado Libre.

**Organization**: por historia de usuario (US1 P1 cambio individual · US2 P2 tabla · US3 P3 lote).

## Format: `[ID] [P?] [Story] Description`

---

## Phase 1: Setup

- [X] T001 Crear la migración `database/migrations/2026_10_15_000001_create_ml_cambios_tipo_publicacion_table.php` con las columnas, FKs (`nullOnDelete`) e índices de `data-model.md` (tabla `ml_cambios_tipo_publicacion`; usuarios en `users`, listas en `listas_precio`)
- [X] T002 [P] Crear el modelo `app/Models/Integraciones/MercadoLibreCambioTipoPublicacion.php` (`$table`, `$fillable`, casts decimales, constantes `RESULTADO_*` para los seis resultados, relaciones `vinculo()`, `producto()`, `usuario()`)

---

## Phase 2: Foundational (bloquea a todas las historias)

- [X] T003 Agregar a `app/Models/Integraciones/MercadoLibrePublicacionProducto.php` las constantes `TIPO_CLASICA = 'gold_special'` y `TIPO_PREMIUM = 'gold_pro'`, `TIPOS_SOPORTADOS`, el accessor `tipo_etiqueta` (Clásica / Premium / Sin clasificar / valor crudo) y hacer que `esPremium()` use la constante
- [X] T004 Agregar `public function listaPrecioParaTipo(string $tipo, MercadoLibreConfiguracion $c): ?int` en `app/Services/MercadoLibre/SincronizadorPrecios.php` (Premium → `lista_precio_id_premium`, Clásica → `lista_precio_id`, sin fallback) y refactorizar `resolverListaPrecio()` para que lo use conservando su fallback de spec 050 (research R4). Exponer además los cortes como `public function motivoBloqueoEscritura(): ?string` (hoy `verificarCortes()` es privado; el privado pasa a delegar en el público) para que el cambiador no los duplique
- [X] T005 Actualizar el docblock de `enviarUno()` y del comentario del corte en `app/Services/MercadoLibre/SincronizadorPrecios.php`: `omitirCorte` permitido sólo para confirmaciones humanas (aprobar retención y cambio de tipo de spec 115)
- [X] T006 [P] Test de regresión en `tests/Feature/Integraciones/SincronizadorPreciosTest.php`: `listaPrecioParaTipo()` estricto por tipo y `resolverListaPrecio()` con el mismo comportamiento de antes (Premium con precio → Premium; Premium sin precio → general; Clásica → general)

**Checkpoint**: `php artisan test tests/Feature/Integraciones` verde antes de seguir.

---

## Phase 3: User Story 1 — Cambio individual Clásica ↔ Premium (P1) 🎯 MVP

**Goal**: desde el menú de una fila, previsualizar y confirmar el cambio de tipo con su precio.

**Independent Test**: Clásica con precio en ambas listas → "Pasar a Premium" → vínculo `gold_pro` con precio Premium publicado y fila actualizada sin recargar (quickstart casos 1–10, 13).

### Tests de US1 (escribir primero, deben fallar)

- [X] T007 [P] [US1] Crear `tests/Feature/Integraciones/CambioTipoPublicacionTest.php` con los casos de servicio: →Premium feliz con orden PUT precio → POST `/items/{id}/listing_type` (`Http::assertSentInOrder` o secuencia registrada); →Clásica feliz con caída 31% y `corte_precios_activo = true` sin crear retención y orden POST listing_type → PUT precio; precio destino igual al publicado → sólo POST listing_type
- [X] T008 [P] [US1] En el mismo archivo, casos de bloqueo sin escrituras: sin lista Premium configurada; producto sin precio en la lista Premium; →Clásica sin precio en la lista general; destino ausente de `available_upgrades`/`available_downgrades`; tipo `free`; vínculo sin producto; modo sólo lectura; función desactivada; candado `ml:sincronizar_precios` tomado (409)
- [X] T009 [P] [US1] En el mismo archivo, casos de falla y recuperación: →Premium con POST listing_type rechazado → re-envío del precio anterior, resultado `revertido`, `listing_type_id` intacto; ídem sin `precio_publicado` previo → `precio_pendiente = 1`; →Clásica con PUT precio rechazado → `cambiado_precio_pendiente`, `precio_pendiente = 1`, tipo `gold_special`; ML ya tenía el destino → `sin_cambios`, vínculo actualizado, cero escrituras; retención abierta → `reemplazada`; una fila de historial por intento con `usuario_id`
- [X] T010 [P] [US1] En el mismo archivo, casos HTTP de `contracts/rutas-internas.md` §1 y §2: forma del JSON de previsualización (`precio_actual` null, `aviso_comision` sólo a Premium, `puede_confirmar`/`motivo_bloqueo`), 422 con `destino` inválido, códigos 200/409/422/502 del POST

### Implementación de US1

- [X] T011 [US1] Crear `app/Services/MercadoLibre/CambiadorTipoPublicacion.php` con `previsualizar(MercadoLibrePublicacionProducto $v, string $destino): array` — valida tipo soportado y producto, verifica cortes con `SincronizadorPrecios::motivoBloqueoEscritura()`, `GET /items/{id}` (tipo vigente; si difiere del guardado actualiza `listing_type_id`/`listing_type_sincronizado_en`), `GET /items/{id}/available_upgrades|available_downgrades`, resuelve lista y precio destino con `listaPrecioParaTipo()` y arma el payload de contracts §1
- [X] T012 [US1] En el mismo servicio, `ejecutar(MercadoLibrePublicacionProducto $v, string $destino, ?int $usuarioId, ?string $loteId = null): array` — toma `Cache::lock(SincronizadorPrecios::LOCK_KEY, 60)->block(10)`, re-ejecuta `previsualizar()`, aplica el orden de research R2 usando `SincronizadorPrecios::enviarUno(..., omitirCorte: true)` para el precio y `ClienteMercadoLibre::enviar('cambiar_tipo_publicacion', 'POST', "/items/{id}/listing_type", ['id' => $destino])` para el tipo, guarda el tipo en el vínculo al confirmar ML, maneja `revertido` / `cambiado_precio_pendiente` y registra siempre una fila en `MercadoLibreCambioTipoPublicacion`; devuelve `{ok, resultado, mensaje}`
- [X] T013 [US1] Crear `app/Http/Controllers/Ingresos/MercadoLibreCambioTipoController.php` con `previsualizar(Request, MercadoLibrePublicacionProducto)` y `ejecutar(Request, MercadoLibrePublicacionProducto)` (validación `destino` ∈ tipos soportados → 422, opcional en la previsualización sólo si el vínculo no tiene `listing_type_id`: se resuelve el tipo real y se propone el opuesto; mapeo resultado → código HTTP de contracts §2; `usuarioId = $request->user()->id`)
- [X] T014 [US1] Registrar en `routes/web.php`, dentro del grupo `ingresos.mercadolibre.vinculaciones.`, `GET {vinculacion}/cambio-tipo` → `cambioTipo.previsualizar` y `POST {vinculacion}/cambio-tipo` → `cambioTipo.ejecutar`
- [X] T015 [US1] Agregar al menú `resources/views/ingresos/mercadolibre/_row_actions_vinculacion.blade.php` el ítem "Pasar a Premium" / "Pasar a Clásica" / "Cambiar tipo" según `listing_type_id` (clase `js-cambiar-tipo`, `data-id`, `data-destino`), sin ofrecerlo para tipos no soportados
- [X] T016 [US1] Agregar en `resources/views/ingresos/mercadolibre/vinculaciones.blade.php` el modal `#modal-cambio-tipo` (publicación, producto `#ID · código — nombre`, tipo actual → destino, precio actual/"Desconocido" → destino, lista, aviso de comisión, motivo de bloqueo, botón Confirmar) y las rutas `cambioTipoPrevisualizar`/`cambioTipoEjecutar` en el objeto de rutas del JS (con placeholder de id)
- [X] T017 [US1] En `resources/js/mercadolibre-vinculaciones.js`: handler de `.js-cambiar-tipo` (abre modal con spinner, GET previsualización, Confirmar deshabilitado si `!puede_confirmar`), POST de confirmación con `window.AppBtn.loading`, Toastr según `resultado`, `tabla.ajax.reload(null, false)` y cierre del modal

**Checkpoint**: US1 completa y testeada por sí sola (MVP).

---

## Phase 4: User Story 2 — Tipo y producto del CRM en la tabla (P2)

**Goal**: columna Tipo con filtro y producto identificado por ID + código + nombre, buscable.

**Independent Test**: filtrar por Premium y por Sin clasificar; buscar por ID y por código de producto (quickstart caso 12).

### Tests de US2

- [X] T018 [P] [US2] Crear `tests/Feature/Integraciones/MercadoLibreVinculacionTablaTipoTest.php`: el datatable devuelve `producto_id`, `producto_codigo`, `tipo_etiqueta`, `listing_type_id`, `precio_publicado`; filtro `listing_type` = `gold_pro` / `gold_special` / `sin_clasificar`; búsqueda global por ID exacto, código y nombre de producto

### Implementación de US2

- [X] T019 [US2] En `app/Http/Controllers/Ingresos/MercadoLibreVinculacionController.php::datatable()`: filtro `listing_type` (`sin_clasificar` → `whereNull`), columnas `producto_id`/`producto_codigo`/`tipo_etiqueta`, `with('producto:id,nombre,codigo')` ya existente, y `filterColumn('producto.nombre', ...)` que busque por `productos.id` exacto (si es numérico), `codigo` y `nombre` vía `whereHas`
- [X] T020 [US2] En `resources/views/ingresos/mercadolibre/vinculaciones.blade.php`: encabezado "Tipo" (orden final: Selección*, Acciones, Publicación (ID), Título, Producto, Tipo, Fecha, Logística, Stock publicado, Sincronización, Diferencia, Precio) y select `#filtro-tipo` (Todos/Clásica/Premium/Sin clasificar) junto al filtro de logística. *La columna de selección la agrega US3.
- [X] T021 [US2] En `resources/js/mercadolibre-vinculaciones.js`: columna Producto renderizada como `#ID · código — nombre` (con `name: 'producto.nombre'`), columna Tipo (`data: 'tipo_etiqueta', name: 'listing_type_id'`, badge para Premium, texto atenuado para Sin clasificar), `d.listing_type` en `ajax.data`, Select2 en `#filtro-tipo` con recarga de la tabla, persistencia de ambos filtros en `stateSaveParams`/`stateLoadParams`, y reemplazar el `colvis` por índice por `columns: ':not(.no-colvis)'` marcando Acciones con `className: 'no-colvis'` (regla #1 de `CLAUDE.md`)

**Checkpoint**: US1 y US2 funcionan juntas e independientes.

---

## Phase 5: User Story 3 — Cambio masivo (P3)

**Goal**: seleccionar varias publicaciones y cambiarlas juntas en segundo plano con resumen.

**Independent Test**: tres Clásicas (dos con precio Premium, una sin) → la confirmación anticipa la omitida; al terminar, dos `gold_pro` y resumen correcto (quickstart caso 11).

### Tests de US3

- [X] T022 [P] [US3] Crear `tests/Feature/Integraciones/CambioTipoPublicacionLoteTest.php`: previsualización del lote separa `a_cambiar`/`omitidas` con motivos locales sin llamar a ML; `ids` vacío o > 100 → 422; ejecutar encola el job (`Queue::fake`) y responde 202 con `lote_id`; segundo lote con estado `en_curso` → 409; el job (ejecutado sincrónicamente con `Http::fake`) procesa cada publicación independiente, deja el resumen en Cache con conteos por resultado y `detalle` sólo de no exitosas, y las filas de historial llevan `lote_id` y `usuario_id`

### Implementación de US3

- [X] T023 [US3] En `app/Services/MercadoLibre/CambiadorTipoPublicacion.php` agregar `previsualizarLote(array $ids, string $destino): array` con las reglas locales de FR-014 (sin requests a ML)
- [X] T024 [US3] Crear `app/Jobs/CambioTipoPublicacionLote.php` (`ShouldQueue`, `$tries = 1`, timeout 600): recibe `loteId`, `ids`, `destino`, `usuarioId`; escribe estado `en_curso` con `procesadas` en `ml:cambio_tipo_lote:estado` (TTL 30 min) tras cada publicación; llama a `ejecutar()` por publicación capturando excepciones por ítem; al final `terminado` con `resumen`/`detalle`/`terminado_en`; ante excepción general `error`
- [X] T025 [US3] En `app/Http/Controllers/Ingresos/MercadoLibreCambioTipoController.php` agregar `previsualizarLote`, `ejecutarLote` (valida `ids` array 1..100 de `ml_publicacion_producto.id`, rechaza 409 si estado `en_curso`, pone `en_curso` antes del dispatch, 202) y `estadoLote`
- [X] T026 [US3] Registrar en `routes/web.php` `POST cambio-tipo-lote/previsualizar`, `POST cambio-tipo-lote` y `GET cambio-tipo-lote/estado` **antes** de las rutas `{vinculacion}` del grupo de vinculaciones
- [X] T027 [US3] En `resources/views/ingresos/mercadolibre/vinculaciones.blade.php`: primer encabezado de casilla "todas las de esta página" (`no-colvis`), botones "Pasar selección a Premium" / "Pasar selección a Clásica" (deshabilitados sin selección, con contador) y modal `#modal-cambio-tipo-lote` (tablas "Se van a cambiar" y "Omitidas", aviso de comisión, Confirmar, luego barra de progreso y resumen)
- [X] T028 [US3] En `resources/js/mercadolibre-vinculaciones.js`: columna de casilla (`orderable:false`, `searchable:false`, `className:'no-colvis'`), `colReorder: { fixedColumnsLeft: 2 }`, set de ids seleccionados que se limpia en `page.dt`/`search.dt`/cambio de filtros, previsualización del lote, POST de ejecución y polling de `estadoLote` cada 2 s hasta `terminado`/`error` (patrón de la Sincronización forzada en el mismo archivo), resumen en el modal, Toastr y `tabla.ajax.reload(null, false)`

**Checkpoint**: las tres historias funcionan independientemente.

---

## Phase 6: Polish & Cross-Cutting

- [X] T029 [P] Correr `php artisan test tests/Feature/Integraciones` completo (regresión observer Premium, retenciones, sincronización de precios y tipos)
- [X] T030 [P] `npm run build` y validación manual local según `quickstart.md` (incluido un lote de 50 con `Http::fake` en `CambioTipoPublicacionLoteTest` que verifique que termina en < 2 min, SC-004) (cuenta ML en modo sólo lectura para bloqueos; sin escrituras contra la cuenta real del cliente), consola sin errores
- [X] T031 Marcar spec 115 como implementada en `docs/documentacion_principal_crm.md` §3.2.ter.octies y en `docs/modelo_datos.md` (`ml_cambios_tipo_publicacion`), ajustando cualquier detalle que haya cambiado al implementar

---

## Dependencies & Execution Order

- **Setup (T001–T002)** → **Foundational (T003–T006)** → historias.
- **US1 (T007–T017)**: depende sólo de Foundational. MVP.
- **US2 (T018–T021)**: depende sólo de Foundational; independiente de US1 (toca el datatable y la tabla, US1 el menú y un modal).
- **US3 (T022–T028)**: depende de US1 (reusa `ejecutar()`) y de US2 (agrega la casilla sobre la tabla ya reordenada, `fixedColumnsLeft` pasa de 1 a 2).
- Dentro de cada historia: tests → servicio → controlador → rutas → vista → JS.
- T016/T020/T027 tocan la misma vista y T017/T021/T028 el mismo JS: secuenciales entre sí.

## Parallel Opportunities

- T002 en paralelo con T001.
- T006 en paralelo con T005.
- US1: T007, T008, T009, T010 en paralelo (mismo archivo nuevo, secciones distintas — o escribir juntos).
- US2 completa en paralelo con US1 (archivos distintos salvo la vista/JS: coordinar T016↔T020 y T017↔T021).
- T029 y T030 en paralelo.

## Implementation Strategy

1. **MVP**: Setup + Foundational + US1 → ya resuelve el pedido del cliente (de a una publicación).
2. **+US2**: la tabla muestra tipo y producto del CRM (lo pidió el usuario explícitamente).
3. **+US3**: cambio masivo en segundo plano.
4. Deploy al VPS sólo con OK explícito; validación post-deploy de lectura (memoria "nunca probar en producción").
