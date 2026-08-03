---

description: "Task list for feature implementation"
---

# Tasks: Bot de Mercado Libre con sugerencias de IA (Fase 1)

**Input**: Design documents from `/specs/033-bot-mercadolibre-ia/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/, quickstart.md;
`specs/032-bot-mensajeria-mercadolibre/` ya implementada (bandeja, webhook, respuesta manual)

**Tests**: Incluidas — mismo criterio de la spec 032 (Principio IV de la constitución): riesgo
reputacional/de política de ML, no de dinero, pero igual de crítico. Foco especial en no-regresión
sobre el flujo ya construido (`EnvioRespuestaMercadoLibre`, guard de doble respuesta).

**Organization**: Tareas agrupadas por user story (US1 = activar/configurar, US2 = sugerencia
automática, US3 = enviar con auditoría), según `spec.md`.

## Path Conventions

Monolito Laravel existente — se extiende la estructura de la spec 032 (`app/Services/MercadoLibre/`,
`app/Http/Controllers/Mensajeria/`, `app/Http/Controllers/Integraciones/`).

---

## Phase 1: Setup

- [X] T001 Crear migración `database/migrations/..._create_ml_sugerencias_table.php` (columnas según
      `data-model.md` § `ml_sugerencias`)
- [X] T002 Crear migración `database/migrations/..._create_ml_bot_configuracion_table.php` (fila
      única, columnas según `data-model.md` § `ml_bot_configuracion`)
- [X] T003 Crear migración `database/migrations/..._add_sugerencia_columns_to_ml_respuestas_enviadas_table.php`
      (agrega `ml_sugerencia_id` nullable FK y `sugerencia_editada` nullable boolean, sin tocar el
      índice único existente)
- [X] T004 Agregar la fila `clave='mercadolibre_bot'` a `database/seeders/FuncionAvanzadaSeeder.php`
      (mismo patrón que `mercadolibre`/`tiendanube`, ver `data-model.md`) — **incluir el campo `orden`**
      (el array real del seeder lo requiere en cada fila; usar el siguiente valor libre después de las
      10 filas ya sembradas)

**Checkpoint**: `php artisan migrate` corre limpio sobre la base ya migrada de la spec 032;
`FuncionAvanzadaSeeder` crea la fila `mercadolibre_bot` sin pisar las existentes.

---

## Phase 2: Foundational (Blocking Prerequisites)

**⚠️ CRITICAL**: ninguna user story puede implementarse sin esta fase completa.

- [X] T005 [P] Crear modelo `App\Models\Integraciones\MercadoLibreSugerencia` en
      `app/Models/Integraciones/MercadoLibreSugerencia.php` (tabla `ml_sugerencias`, relación
      `belongsTo(MercadoLibreMensaje)`)
- [X] T006 [P] Crear modelo `App\Models\Integraciones\MercadoLibreBotConfiguracion` en
      `app/Models/Integraciones/MercadoLibreBotConfiguracion.php` (tabla `ml_bot_configuracion`, método
      estático `actual()` — mismo patrón que `MercadoLibreConfiguracion::actual()`)
- [X] T007 [P] Crear interfaz `App\Services\MercadoLibre\Bot\GeneradorDeSugerencias` en
      `app/Services/MercadoLibre/Bot/GeneradorDeSugerencias.php` (método
      `generar(MercadoLibreConversacion $conversacion, MercadoLibreMensaje $mensaje, string $instrucciones): string`)
- [X] T008 [US-agnóstico] Crear `App\Services\MercadoLibre\Bot\GeneradorDeSugerenciasOpenAI` en
      `app/Services/MercadoLibre/Bot/GeneradorDeSugerenciasOpenAI.php` (implementa T007 con el SDK
      oficial de OpenAI, modelo `gpt-4o-mini`, API key desde `OPENAI_API_KEY`) y registrar el binding
      interfaz→implementación en `app/Providers/AppServiceProvider.php` (depende de T007) — **el prompt
      DEBE instruir un máximo de 350 caracteres** (R8 de `research.md`, límite real de Mercado Libre
      para mensajes de post-venta del vendedor, confirmado contra la documentación oficial vía MCP)
- [X] T009 Agregar el campo `ml_sugerencia_id` (nullable) a
      `app/Models/Integraciones/MercadoLibreRespuestaEnviada.php` (`$fillable`, relación `belongsTo`) y
      el campo `sugerencia_editada` a `$fillable`/`$casts` (depende de T003, T005)

**Checkpoint**: modelos e interfaz de IA disponibles — las user stories pueden arrancar.

---

## Phase 3: User Story 1 - Activar el bot y configurar su tono (Priority: P1) 🎯 MVP

**Goal**: Switch en Funciones Avanzadas + pantalla de configuración funcional.

**Independent Test**: activar el switch, entrar a la configuración, guardar instrucciones de tono,
verificar persistencia — sin que exista todavía ninguna sugerencia generada.

### Tests for User Story 1

- [X] T010 [P] [US1] Feature test en `tests/Feature/MercadoLibreBotConfiguracionTest.php`: activar el
      switch muestra el link de configuración (FR-001/FR-002); guardar `instrucciones_tono` persiste el
      cambio (FR-003); acceso restringido por permiso `configuracion.funciones` (FR-012)

### Implementation for User Story 1

- [X] T011 [US1] Crear `App\Http\Requests\Mensajeria\GuardarConfiguracionBotMercadoLibreRequest` en
      `app/Http/Requests/Mensajeria/GuardarConfiguracionBotMercadoLibreRequest.php` (valida
      `instrucciones_tono` nullable string, autoriza por `configuracion.funciones`)
- [X] T012 [US1] Crear `App\Http\Controllers\Integraciones\MercadoLibreBotConfiguracionController` en
      `app/Http/Controllers/Integraciones/MercadoLibreBotConfiguracionController.php` con `index()`
      (vista) y `guardar(GuardarConfiguracionBotMercadoLibreRequest $request)` (depende de T006, T011)
- [X] T013 [US1] Registrar `GET /configuracion/mercadolibre/bot` y `PUT /configuracion/mercadolibre/bot`
      en `routes/web.php` bajo `permiso:configuracion.funciones` (depende de T012)
- [X] T014 [US1] Crear vista `resources/views/configuracion/mercadolibre/bot.blade.php` (formulario de
      `instrucciones_tono`, AJAX + Toastr según reglas de diseño del CLAUDE.md)

**Checkpoint**: User Story 1 funcional de forma independiente.

---

## Phase 4: User Story 2 - Recibir una sugerencia de respuesta generada por IA (Priority: P1)

**Goal**: generación automática (switch activo) y bajo demanda (switch apagado), con estados
generando/lista/error visibles en la conversación.

**Independent Test**: activar el switch, simular la llegada de un mensaje (webhook de la spec 032),
verificar que se genera la sugerencia de forma asíncrona; con el switch apagado, pedirla bajo demanda.

### Tests for User Story 2

- [X] T015 [P] [US2] Feature test en `tests/Feature/MercadoLibreSugerenciaTest.php`: con el switch
      activo, un mensaje entrante despacha `GenerarSugerenciaMercadoLibre` y termina en `estado=lista`
      con `texto_sugerido` no vacío (mock de `GeneradorDeSugerencias`) (FR-004); con el switch apagado,
      no se despacha automáticamente (FR-006 negativo); `POST /mensajeria/{conversacion}/sugerencia`
      genera bajo demanda (FR-006); un fallo del generador dentro del Job deja `estado=error` con
      `error_mensaje` (Edge Case)

### Implementation for User Story 2

- [X] T016 [US2] Crear `App\Jobs\GenerarSugerenciaMercadoLibre` en
      `app/Jobs/GenerarSugerenciaMercadoLibre.php`: recibe `MercadoLibreMensaje`, crea/actualiza la
      `MercadoLibreSugerencia` en `generando`, arma el contexto (historial vía
      `$mensaje->conversacion->mensajes`, producto vía `publicacionProducto->producto`, estado de envío
      vía `orden?->payload['shipping']` si existe — **no hay columna dedicada de estado de envío en
      `ml_ordenes`, sólo el `payload` json crudo**, R3 de `research.md`), llama a
      `GeneradorDeSugerencias::generar()` con `MercadoLibreBotConfiguracion::actual()->instrucciones_tono`,
      y guarda `lista`/`error` según el resultado — **tratar como error una respuesta vacía o de más de
      350 caracteres** (FR-011a, R8 de `research.md`), aplicando timeout único sin reintentos a la
      llamada al proveedor (depende de T005-T009)
- [X] T017 [US2] Modificar `App\Http\Controllers\Integraciones\MercadoLibreMensajeriaWebhookController`
      (spec 032): tras persistir el mensaje, si `FuncionAvanzada::where('clave','mercadolibre_bot')->value('activa')`,
      despachar `GenerarSugerenciaMercadoLibre::dispatch($mensaje)` (R2 de `research.md`) (depende de
      T016) — **no modificar el resto del controller**
- [X] T018 [US2] Crear `App\Http\Controllers\Mensajeria\SugerenciaController` en
      `app/Http/Controllers/Mensajeria/SugerenciaController.php` con `store(MercadoLibreConversacion $conversacion)`
      (genera bajo demanda, permiso `mensajeria.ver`) (depende de T016)
- [X] T019 [US2] Registrar `POST /mensajeria/{conversacion}/sugerencia` en `routes/web.php` bajo
      `permiso:mensajeria.ver` (depende de T018)
- [X] T020 [US2] Modificar `App\Http\Controllers\Mensajeria\ConversacionController::actualizaciones()`
      (spec 032) para incluir el array `sugerencias` en la respuesta (R5 de `research.md`) — **no
      modificar la forma de `conversaciones`/`mensajes` ya existente** (depende de T005)
- [X] T021 [US2] Modificar `resources/js/mensajeria.js` (spec 032): el polling procesa el nuevo array
      `sugerencias`, mostrando el estado (generando/lista/error) en el panel de la conversación abierta
      (depende de T020)

**Checkpoint**: User Stories 1 y 2 funcionan juntas — se puede configurar el bot y ver sugerencias
generarse, aunque todavía no se puedan enviar integradas con auditoría.

---

## Phase 5: User Story 3 - Enviar una respuesta a partir de la sugerencia, con auditoría (Priority: P1)

**Goal**: integrar la sugerencia al flujo de envío ya existente, sin romper el guard de doble respuesta
ni el manejo de error de la spec 032.

**Independent Test**: enviar una sugerencia tal cual y editada, verificando en ambos casos la
auditoría; repetir los casos de doble respuesta y error de envío de la spec 032 para confirmar
no-regresión.

### Tests for User Story 3

- [X] T022 [P] [US3] Ampliar `tests/Feature/MercadoLibreEnvioRespuestaTest.php` (spec 032) con casos
      nuevos: envío con `sugerencia_id` sin editar → `ml_sugerencia_id` informado,
      `sugerencia_editada=false` (FR-010); envío con `sugerencia_id` y texto editado →
      `sugerencia_editada=true`; envío con `sugerencia_id` que pertenece a un `ml_mensaje_id` distinto
      del último mensaje del comprador en la conversación (caso del gap encontrado al revisar T023) →
      se envía igual pero `ml_sugerencia_id` queda `null`; **re-ejecutar los tests ya existentes de doble
      respuesta y error de envío sin ninguna modificación de su expectativa** — deben seguir pasando tal
      cual (no-regresión, FR-009)

### Implementation for User Story 3

- [X] T023 [US3] Modificar `App\Services\MercadoLibre\Mensajeria\EnvioRespuestaMercadoLibre::enviar()`
      (spec 032): la firma real actual es
      `enviar(MercadoLibreConversacion $conversacion, string $texto, int $usuarioId): array` — agregar
      el parámetro nuevo **al final**: `?int $sugerenciaId = null`. Si viene informado: (1) cargar
      `$sugerencia = MercadoLibreSugerencia::find($sugerenciaId)`; (2) **validar que
      `$sugerencia->ml_mensaje_id` coincide con el `id` del `$mensaje` que el método ya resuelve
      internamente** (`$conversacion->mensajes()->where('origen','comprador')->latest('enviado_en')->first()`)
      — si no coincide (llegó un mensaje nuevo entre que se generó la sugerencia y se confirmó el envío),
      guardar `ml_sugerencia_id=null`/`sugerencia_editada=null` en vez de auditar una sugerencia que no
      corresponde al mensaje efectivamente respondido; (3) si coincide, comparar `$texto` contra
      `$sugerencia->texto_sugerido` y guardar `ml_sugerencia_id`/`sugerencia_editada` en el insert ya
      existente de `MercadoLibreRespuestaEnviada` (R4 de `research.md`) — **no tocar la lógica de guard
      de doble respuesta ni el manejo de error ya existentes** (depende de T009)
- [X] T024 [US3] Modificar `App\Http\Requests\Mensajeria\EnviarRespuestaMercadoLibreRequest` (spec 032):
      agregar regla opcional `sugerencia_id` (`nullable`, `exists:ml_sugerencias,id`)
- [X] T025 [US3] Modificar `App\Http\Controllers\Mensajeria\ConversacionController::responder()` (spec
      032) para pasar `$request->validated('sugerencia_id')` a `EnvioRespuestaMercadoLibre::enviar()`
      (depende de T023, T024)
- [X] T026 [US3] Modificar `resources/views/mensajeria/index.blade.php` y `resources/js/mensajeria.js`
      (spec 032): el formulario de respuesta se pre-completa con `texto_sugerido` cuando hay una
      sugerencia `lista`, permite editarlo, y envía `sugerencia_id` junto con el texto (depende de T021)

**Checkpoint**: las tres user stories funcionan juntas de punta a punta — configurar, generar,
enviar con auditoría — sin regresión sobre lo construido en la spec 032.

---

## Phase 6: Polish & Cross-Cutting Concerns

- [X] T027 [P] Ejecutar `quickstart.md` completo (Escenarios 1-3 + verificación de no-regresión) contra
      un entorno local con datos de prueba
- [X] T028 Revisar que `docs/documentacion_principal_crm.md` §6.5 y `docs/modelo_datos.md` §15 (ya
      actualizados en esta cadena de spec-kit) sigan reflejando exactamente lo implementado
- [X] T029 Documentar en `docs/bot_mensajeria_ml/infraestructura.md` la confirmación de que el VPS con
      colas reales ya está migrado, antes de activar el switch en producción (gate operativo, no de
      código)

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: depende de que la spec 032 ya esté migrada en el entorno (tablas
  `ml_conversaciones`/`ml_mensajes`/`ml_respuestas_enviadas` existentes).
- **Foundational (Phase 2)**: depende de Setup. Bloquea las tres user stories.
- **User Story 1 (Phase 3)**: depende de Foundational. Independiente de US2/US3 (configurar el bot no
  requiere que ya haya sugerencias).
- **User Story 2 (Phase 4)**: depende de Foundational. Puede probarse sin User Story 1 completa (usando
  el valor default de `instrucciones_tono`), aunque en la práctica conviene tener US1 lista primero.
- **User Story 3 (Phase 5)**: depende de Foundational y de que existan sugerencias generadas (US2) para
  probarse de punta a punta — aunque el código de integración con el envío (T023-T025) es
  independiente en sí mismo.
- **Polish (Phase 6)**: depende de que las tres user stories estén completas.

### Parallel Opportunities

- T001-T003 (migraciones) en paralelo entre sí.
- T005-T007 (modelos + interfaz) en paralelo entre sí.
- T010 (test US1) en paralelo con el resto de Foundational.
- T015 (test US2) y T022 (test US3) pueden escribirse en paralelo entre sí (archivos distintos), aunque
  T022 depende conceptualmente de que T016-T021 existan para poder correr en verde.

---

## Implementation Strategy

### MVP First (User Story 1 únicamente)

1. Completar Phase 1 (Setup) y Phase 2 (Foundational).
2. Completar Phase 3 (User Story 1) — switch + configuración, sin sugerencias todavía.
3. **Validar** con el Escenario 1 de `quickstart.md`.

### Incremental Delivery

1. Setup + Foundational → base lista.
2. User Story 1 → validar.
3. User Story 2 → validar (sugerencias se generan y se ven, todavía no integradas al envío).
4. User Story 3 → validar con `quickstart.md` completo, **prestando especial atención a la
   verificación de no-regresión** sobre la spec 032.
5. Polish (Phase 6) — incluye confirmar el gate operativo del VPS antes de producción (T029).

---

## Notes

- Esta lista de tareas asume que `specs/032-bot-mensajeria-mercadolibre/` ya está implementada tal como
  quedó documentada (namespaces, nombres de métodos y rutas exactos, verificados contra el código real
  al planificar esta fase) — cualquier divergencia real debe reconciliarse antes de T016/T017/T020/T023.
- T017, T020 y T023 son las únicas tareas que tocan archivos de la spec 032 — tratarlas con cuidado
  quirúrgico (parámetros opcionales, ramas condicionales) es más importante que la velocidad.
- Commitear después de cada tarea o grupo lógico; parar en cada checkpoint para validar la story de
  forma independiente antes de seguir.
