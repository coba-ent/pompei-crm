# Tasks: Baja a Clásica sin depender de ML y cambios de tipo detectados desde Mercado Libre

**Input**: Design documents from `specs/116-ml-tipo-detectado-y-baja-clasica/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/contratos-internos.md, quickstart.md

**Tests**: OBLIGATORIOS. Constitución IV: la feature publica precios. Van antes de la implementación de
cada historia y tienen que fallar primero.

**Organization**: Tareas agrupadas por historia de usuario. US1 y US2 son P1 e independientes entre sí.
US3 depende de US2, porque engancha la revisión que ya detecta. US4 es independiente.

## Format: `[ID] [P?] [Story] Description`

## Phase 1: Setup

- [X] T001 Crear la migración `database/migrations/2026_10_28_000001_add_origen_to_ml_cambios_tipo_publicacion.php`: columna `origen` string(20) not null default `crm`, índice, `down()` que la quita (data-model.md).
- [X] T002 En `app/Models/Integraciones/MercadoLibreCambioTipoPublicacion.php`: constantes `ORIGEN_CRM = 'crm'` y `ORIGEN_MERCADOLIBRE = 'mercadolibre'` y `origen` en `$fillable`. En `CambiadorTipoPublicacion::registrar()`, `app/Services/MercadoLibre/CambiadorTipoPublicacion.php`, pasar `origen = ORIGEN_CRM` explícito.

## Phase 2: Foundational

- [X] T003 Actualizar el docblock de `enviarUno()` y el comentario del corte en `app/Services/MercadoLibre/SincronizadorPrecios.php` a **tres** usos autorizados de `omitirCorte`; el tercero es `ProcesadorCambioTipoDetectado` (spec 116, research R3). Sin cambio de firma.

**Checkpoint**: migración corrida; `php artisan test --filter=CambioTipo` sigue verde.

---

## Phase 3: User Story 1 - Pasar a Clásica aunque ML no lo informe disponible (P1) 🎯 MVP

**Goal**: el paso a Clásica no se bloquea por `available_downgrades`; el rechazo real de ML se informa al confirmar.

**Independent Test**: quickstart §1.

### Tests (primero, tienen que fallar)

- [X] T004 [P] [US1] En `tests/Feature/Integraciones/CambioTipoPublicacionTest.php`: (a) `test_pasar_a_clasica_con_available_downgrades_vacio_cambia`: vínculo `gold_pro` con precio en lista general, `fakeMl('gold_pro', disponibles: [])` → `cambiado`, POST `listing_type` con `gold_special` y PUT con el precio de la lista general. (b) `test_pasar_a_clasica_rechazada_por_ml_informa_su_mensaje`: POST rechazado con mensaje de ML → `fallido`, el mensaje en la respuesta y en el historial, sin PUT de precio y con el vínculo todavía `gold_pro`. (c) `test_previsualizar_a_clasica_no_consulta_available_downgrades`: sin llamada GET a `/available_downgrades`. Reescribir la segunda mitad de `test_cada_intento_queda_en_el_historial_con_usuario` (hoy espera `bloqueado` con `disponibles: []` hacia Clásica): ahora espera `cambiado`. Mantener `test_destino_no_disponible_en_ml_bloquea` (subida, FR-003).
- [X] T005 [P] [US1] En `tests/Feature/Integraciones/CambioTipoPublicacionLoteTest.php`: un lote "Pasar a Clásica" con `available_downgrades` vacío en el fake termina con las publicaciones cambiadas.

### Implementation

- [X] T006 [US1] En `CambiadorTipoPublicacion::previsualizar()`, `app/Services/MercadoLibre/CambiadorTipoPublicacion.php`: consultar `available_upgrades` y bloquear con "no ofrece" **solo si** el destino es Premium. Con destino Clásica, no hay consulta de disponibilidad. Actualizar el docblock de `idsDisponibles()` y el del método.

**Checkpoint**: T004/T005 verdes; la subida sigue bloqueando como antes.

---

## Phase 4: User Story 2 - Precio correcto cuando el tipo se cambia desde ML (P1)

**Goal**: un cambio Clásica ↔ Premium detectado consolida el tipo y publica el precio de su lista, sin corte, sin "consumirse" y sin consolidarse si el envío está bloqueado.

**Independent Test**: quickstart §2.

### Tests (primero)

- [X] T007 [P] [US2] Crear `tests/Feature/Integraciones/CambioTipoDetectadoTest.php` (mismo armado de `Http::fake()` que `CambioTipoPublicacionLoteTest`, multiget `/items?ids=`), con un caso por escenario:
  1. `gold_pro` → ML `gold_special`: vínculo `gold_special`, PUT con el precio de la lista general aunque baje >20% (umbral configurado), sin retención creada, historial `origen=mercadolibre`, `usuario_id` null, `cambiado`, `precio_anterior`/`precio_nuevo`/`lista_precio_id` correctos.
  2. `gold_special` → `gold_pro` con precio Premium: PUT con el precio de la lista Premium.
  3. `gold_special` → `gold_pro` sin precio Premium: PUT con la lista general y el mensaje del historial menciona "lista Premium".
  4. Precio del tipo nuevo igual a `precio_publicado`: sin PUT, `sin_cambios`, una retención abierta previa queda `reemplazada`.
  5. Modo sólo lectura: vínculo sigue `gold_pro`, sin PUT, sin fila de historial, con línea `bloqueada` en el log de operaciones. Al desactivarlo y re-correr se procesa.
  6. Candado `SincronizadorPrecios::LOCK_KEY` tomado: igual que 5.
  7. PUT rechazado: vínculo `gold_special`, `precio_pendiente = 1`, `cambiado_precio_pendiente`.
  8. `null` → `gold_pro` y `gold_pro` → `gold_premium`: tipo guardado, sin PUT ni historial.
  9. Vínculo sin producto: tipo actualizado, `sin_cambios` con motivo "sin precio para publicar".
  10. Retención abierta + cambio con PUT aceptado: la retención queda `reemplazada`.
- [X] T008 [P] [US2] En `tests/Feature/Integraciones/CambioTipoPublicacionTest.php`: (a) `test_previsualizar_no_consolida_un_cambio_hecho_en_ml`: guardado `gold_special` y ML `gold_pro` → tras `previsualizar()` el vínculo sigue `gold_special`. (b) `test_confirmar_cuando_ml_ya_tiene_el_destino_publica_el_precio`: guardado `gold_special`, ML `gold_pro`, confirmar "Pasar a Premium" → resultado `sin_cambios`, PUT con el precio Premium, vínculo `gold_pro`, **una** fila de historial con `origen=mercadolibre` y el usuario. Ajustar `test_codigos_http_de_la_ejecucion` si su último paso cambia (sigue esperando `sin_cambios`).

### Implementation

- [X] T009 [US2] Crear `app/Services/MercadoLibre/ProcesadorCambioTipoDetectado.php` según contracts §1: precondiciones (lanza `InvalidArgumentException` si no es Clásica ↔ Premium distinto), consolidar el tipo, resolver la lista con `SincronizadorPrecios::resolverListaPrecio()` **después** de consolidar el tipo (usa `esPremium()` del vínculo, sin cambio de firma), los casos sin precio o precio igual (cerrar la retención abierta como `reemplazada`), `enviarUno(..., omitirCorte: true)`, y registrar en `MercadoLibreCambioTipoPublicacion` con `origen = mercadolibre`. Docblock con la acotación de la excepción (research R3).
- [X] T010 [US2] En `SincronizadorTiposPublicacion::consultarYPersistir()`, `app/Services/MercadoLibre/SincronizadorTiposPublicacion.php`: apartar los cambios detectados (data-model, tabla de transiciones) del `update()` masivo de `listing_type_id`, dejando los campos de logística. Devolverlos a `sincronizarTodos()`, que los acumula de **todas** las tandas y, recién al terminar el multiget, si `motivoBloqueoEscritura()` es null, toma el candado de precios **una sola vez** con `get()` no bloqueante, procesa y lo libera en `finally`. El candado no se toma durante las consultas. Se siguen revisando todos los vínculos, cualquiera sea su estado en ML (FR-015a): no filtrar. y devuelve los contadores `cambios_detectados`, `cambios_procesados` y `cambios_postergados`. Los postergados escriben una línea en `MercadoLibreOperacionLog` (`sincronizar_tipo_publicacion`, `bloqueada`). Actualizar el docblock de la clase (ya no "diaria").
- [X] T011 [US2] En `CambiadorTipoPublicacion`, `app/Services/MercadoLibre/CambiadorTipoPublicacion.php`: (a) `previsualizar()` persiste el tipo vigente solo si el guardado es `null` o está fuera de alcance (R5). (b) En `ejecutarConCandado()`, si `sin_cambios` y el tipo guardado difiere del vigente, delegar en `ProcesadorCambioTipoDetectado::procesar($vinculo, $tipoVigente, $usuarioId)` (el candado ya está tomado) y devolver `sin_cambios` con su mensaje, sin un segundo `registrar()`. Inyectar el procesador por constructor.

**Checkpoint**: T007/T008 verdes; `php artisan test --filter='CambioTipo|PrecioProductoObserverPremium|Retencion'` verde.

---

## Phase 5: User Story 3 - Detección en minutos (P2)

**Goal**: la revisión de tipos corre dentro del comando de stock, frenada por `frecuencia_sync_minutos`.

**Independent Test**: quickstart §3.

### Tests (primero)

- [X] T012 [P] [US3] Crear `tests/Feature/Integraciones/RevisionTiposEnCronStockTest.php` con estos casos:
  - `frecuencia_sync_minutos = 15` y `tipo_publicacion_ultima_sync_en` hace 16 min → `artisan('mercadolibre:sincronizar-stock')` hace el multiget de tipos. Hace 5 min → no lo hace.
  - Sólo lectura (stock bloqueado) → dos ejecuciones seguidas hacen el multiget una sola vez y el exit code es 0.
  - Falla del multiget → el exit code del comando de stock no cambia.
  - Sin cuenta conectada → no hay multiget.
  - Modo sólo lectura con cuenta conectada → **sí** hay multiget (lectura, FR-010a); los cambios detectados quedan postergados. Función ML desactivada → no hay multiget (FR-005b de spec 012 bloquea también lecturas). El stock que explota (sin depósito) → la revisión corre igual.
  - `mercadolibre:sincronizar-tipos-publicacion --forzar` sigue funcionando y detecta.
  - El schedule ya no incluye `mercadolibre:sincronizar-tipos-publicacion`: inspeccionar `app(Schedule::class)->events()`.

### Implementation

- [X] T013 [US3] En `SincronizadorTiposPublicacion`, agregar `sincronizarSiCorresponde(): ?array` (contracts §2): freno por `frecuencia_sync_minutos` contra `tipo_publicacion_ultima_sync_en`, cuenta conectada requerida y candado propio `ml:sincronizar_tipos` con `get()`.
- [X] T014 [US3] En `app/Console/Commands/SincronizarStockMercadoLibre.php`: inyectar `SincronizadorTiposPublicacion` y, en **todos** los caminos de `handle()` (no corresponde por frecuencia, bloqueada, salteada, error, ok), llamar a `sincronizarSiCorresponde()` al final, informar el resultado por la salida y no tocar el exit code. Conviene reestructurar con un único punto de salida.
- [X] T015 [US3] En `app/Console/Commands/SincronizarTiposPublicacionMercadoLibre.php`: `correspondeEjecutar()` compara contra `frecuencia_sync_minutos` en vez de 24 h; descripción y docblock actualizados (uso manual). Informar además los contadores de cambios.
- [X] T016 [US3] En `bootstrap/app.php`: quitar el `$schedule->command('mercadolibre:sincronizar-tipos-publicacion')` y dejar un comentario que remita a la spec 116 (la revisión la dispara el comando de stock).

**Checkpoint**: T012 verde; `php artisan schedule:list` sin entrada propia de tipos.

---

## Phase 6: User Story 4 - Aviso de cuotas al pasar a Clásica (P3)

**Goal**: los modales avisan qué cuotas sin interés se pierden.

**Independent Test**: quickstart §4.

### Tests (primero)

- [X] T017 [P] [US4] En `tests/Feature/Integraciones/CambioTipoPublicacionTest.php`: `previsualizar(gold_pro → gold_special)` con tag `3x_campaign` → `aviso_pierde_cuotas = '3 cuotas sin interés'`. Sin campaña → `'6 cuotas sin interés'`. Hacia Premium → `null`. En `CambioTipoPublicacionLoteTest.php`: `previsualizarLote(..., 'gold_special')` → `aviso_pierde_cuotas_lote = true`; hacia Premium → `false`.

### Implementation

- [X] T018 [US4] En `CambiadorTipoPublicacion::previsualizar()` y `previsualizarLote()`: agregar `aviso_pierde_cuotas` y `aviso_pierde_cuotas_lote` (research R7, con `CampaniasCuotas::etiqueta(cuotas_actual, gold_pro)`).
- [X] T019 [US4] En `resources/views/ingresos/mercadolibre/vinculaciones.blade.php`: agregar `div.alert.alert-warning.small.d-none#cambio-tipo-aviso-cuotas` junto a `#cambio-tipo-aviso-comision` y `#cambio-tipo-lote-aviso-cuotas` junto a `#cambio-tipo-lote-aviso-comision`, con un texto base.
- [X] T020 [US4] En `resources/js/mercadolibre-vinculaciones.js`: en el render de la previsualización (junto a la línea del `aviso_comision`) mostrar u ocultar `#cambio-tipo-aviso-cuotas` con "Al pasar a Clásica, la publicación pierde las {aviso_pierde_cuotas}." (oculto si hay `motivo_bloqueo` o `sin_cambios`). En el lote, mostrar u ocultar `#cambio-tipo-lote-aviso-cuotas` con "Las publicaciones con cuotas sin interés las pierden al pasar a Clásica.". Después, `npm run build`.

**Checkpoint**: T017 verde; validación manual en el navegador local (quickstart §4).

---

## Phase 7: Polish & Cross-Cutting

- [X] T021 Correr toda la suite: `php artisan test` (no solo el filtro). Revisar en particular `SincronizadorTiposPublicacion*`, `SincronizarStock*`, `ChequeoPrecios*` y `PrecioProductoObserver*`, que comparten servicios tocados.
- [X] T022 Validación manual en el navegador local (MySQL real, no SQLite, por la memoria "MySQL estricto vs tests en SQLite"): abrir Vinculaciones ML, "Pasar a Clásica" en un vínculo Premium con respuestas simuladas, ver el aviso de cuotas y confirmar que no aparece "no ofrece". Correr `php artisan mercadolibre:sincronizar-stock` dos veces y ver un solo multiget. *(Hecho 28/10: el modal se validó en el navegador local con respuestas interceptadas. El comando de stock NO se corrió en local, porque la base local es un clon de producción con cuenta ML vinculada y empujaría stock real; queda cubierto por `RevisionTiposEnCronStockTest`.)*
- [X] T023 Confirmar que `docs/documentacion_principal_crm.md` (§3.2.ter.quinquies, §3.2.ter.septies "tercera excepción" y §3.2.ter.octies) y `docs/modelo_datos.md` (`origen`, `tipo_publicacion_ultima_sync_en`) reflejan lo implementado. Si la implementación se apartó, corregirlos.
- [ ] T024 Antes de deployar: la primera baja a Clásica real por API requiere OK explícito del usuario y una publicación elegida por él (Assumptions de la spec; memoria "nunca probar en producción"). Post-deploy, solo lectura (quickstart §5).

## Dependencies & Execution Order

- Setup (T001–T002) → Foundational (T003) → historias.
- **US1** (T004–T006): independiente.
- **US2** (T007–T011): independiente de US1. T011 toca el mismo archivo que T006 y T018, así que van secuenciales.
- **US3** (T012–T016): depende de T010 (la revisión ya detecta).
- **US4** (T017–T020): independiente; T018 comparte archivo con T006/T011.
- Polish al final.

### Parallel Opportunities

- T004, T005, T007, T008, T012 y T017 son archivos de test distintos o casos nuevos: se pueden escribir en paralelo. T004, T008 y T017 tocan el mismo archivo, así que se coordinan.
- T009 (archivo nuevo) en paralelo con T006.
- T019 (Blade) en paralelo con T018 (servicio).

## Implementation Strategy

1. **MVP = US1** (T001–T006): destraba la baja a Clásica desde el CRM, que hoy no funciona nunca. Se
   puede deployar solo.
2. **US2 + US3**: cierran la ventana de precio incorrecto ante cambios hechos en ML. Conviene deployarlas
   juntas: sin US3, la detección corre igual pero con la revisión diaria.
3. **US4**: cosmética de la confirmación; cualquier momento.
