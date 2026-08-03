---

description: "Task list for feature implementation"
---

# Tasks: Mensajería de Mercado Libre (lectura y respuesta manual)

**Input**: Design documents from `/specs/032-bot-mensajeria-mercadolibre/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/, quickstart.md

**Tests**: Incluidas — la constitución del proyecto (Principio IV) exige tests para lógica con impacto
reputacional/de política de Mercado Libre; `plan.md` → Testing las marca obligatorias para idempotencia
del webhook (FR-004) y el guard de doble respuesta (FR-007).

**Organization**: Tareas agrupadas por user story (US1 = bandeja, US2 = responder con auditoría), según
`spec.md`.

## Path Conventions

Monolito Laravel existente — `app/`, `resources/`, `routes/web.php`, `database/`, `tests/Feature/` en la
raíz del repo (ver `plan.md` → Project Structure).

---

## Phase 1: Setup

**Purpose**: Preparar la base de datos y el catálogo de permisos que todas las user stories necesitan.

- [X] T001 Crear migración `database/migrations/..._create_ml_conversaciones_table.php` (columnas y
      constraint único según `data-model.md` § `ml_conversaciones`)
- [X] T002 Crear migración `database/migrations/..._create_ml_mensajes_table.php` (columnas e índice
      único sobre `ml_id` según `data-model.md` § `ml_mensajes`)
- [X] T003 Crear migración `database/migrations/..._create_ml_respuestas_enviadas_table.php` (columnas
      e índice único `(ml_mensaje_id)` con `resultado=exito` según `data-model.md` § `ml_respuestas_enviadas`)
- [X] T004 Agregar el módulo `mensajeria` (acciones `ver`, `responder`) al catálogo de
      `database/seeders/PermisoSeeder.php`, siguiendo el patrón existente (ej. módulo `ventas`)

**Checkpoint**: `php artisan migrate` corre limpio; `php artisan db:seed --class=PermisoSeeder` crea
los permisos `mensajeria.ver` y `mensajeria.responder`.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Modelos Eloquent y verificación del endpoint de mensajería post-venta — bloquean ambas
user stories.

**⚠️ CRITICAL**: Ninguna user story puede implementarse sin esta fase completa.

- [X] T005 [P] Crear modelo `App\Models\Integraciones\MercadoLibreConversacion` en
      `app/Models/Integraciones/MercadoLibreConversacion.php` (tabla `ml_conversaciones`, relaciones a
      `MercadoLibrePublicacionProducto` y `MercadoLibreOrden`, scopes `pendientes()`/`porTipo()`)
- [X] T006 [P] Crear modelo `App\Models\Integraciones\MercadoLibreMensaje` en
      `app/Models/Integraciones/MercadoLibreMensaje.php` (tabla `ml_mensajes`, relación
      `belongsTo(MercadoLibreConversacion)`)
- [X] T007 [P] Crear modelo `App\Models\Integraciones\MercadoLibreRespuestaEnviada` en
      `app/Models/Integraciones/MercadoLibreRespuestaEnviada.php` (tabla `ml_respuestas_enviadas`,
      relaciones `belongsTo(MercadoLibreMensaje)` y `belongsTo(User)`)
- [X] T008 Verificar contra la documentación vigente del DevCenter de Mercado Libre (o con el MCP de
      Mercado Libre ya autorizado por el usuario) el endpoint exacto de lectura/envío de Mensajería
      post-venta (riesgo abierto `research.md` R2); actualizar `contracts/webhook-mercadolibre.md` con
      el shape confirmado antes de continuar con T011/T014

**Checkpoint**: modelos disponibles y endpoint de post-venta confirmado — las user stories pueden
arrancar.

---

## Phase 3: User Story 1 - Ver todos los mensajes de compradores en un solo lugar (Priority: P1) 🎯 MVP

**Goal**: Bandeja unificada "Mensajería" con historial completo por conversación, alimentada por el
webhook de notificaciones de ML.

**Independent Test**: Simular la llegada de una pregunta y un mensaje post-venta vía el webhook y
verificar que ambos aparecen en la bandeja con su información asociada, incluido el reintento
duplicado que no debe generar una segunda entrada.

### Tests for User Story 1

- [X] T009 [P] [US1] Feature test de idempotencia y asociación en
      `tests/Feature/MercadoLibreMensajeriaWebhookTest.php`: notificación `questions` nueva crea
      Conversación+Mensaje asociados a `MercadoLibrePublicacionProducto` cuando existe vinculación;
      notificación repetida (reintento) no duplica nada (FR-004); notificación `messages` post-venta
      crea/asocia a `MercadoLibreOrden` por `pack_id`; `application_id` no coincide → 401

### Implementation for User Story 1

- [X] T010 [P] [US1] Crear `App\Services\MercadoLibre\Mensajeria\RecepcionMensajeMercadoLibre` en
      `app/Services/MercadoLibre/Mensajeria/RecepcionMensajeMercadoLibre.php`: recibe el payload de
      notificación, identifica `topic`, hace `GET` del detalle a la API de ML vía `ClienteMercadoLibre`,
      y hace upsert de `MercadoLibreConversacion`/`MercadoLibreMensaje` por clave natural (R4 de
      `research.md`) — para Preguntas agrupa por comprador+publicación, para post-venta por
      `pack_id`/orden (ver `data-model.md`)
- [X] T011 [US1] Crear `App\Http\Controllers\Integraciones\MercadoLibreMensajeriaWebhookController` en
      `app/Http/Controllers/Integraciones/MercadoLibreMensajeriaWebhookController.php` (método
      `recibir(Request)`): valida `application_id`, delega a `RecepcionMensajeMercadoLibre`, responde
      `200 {"ok": true}` según `contracts/webhook-mercadolibre.md` (depende de T010)
- [X] T012 [US1] Registrar la ruta `POST /webhooks/mercadolibre` en `routes/web.php` (fuera del grupo
      `auth`/con CSRF exceptuado, mismo patrón que `webhooks/tiendanube/*`), apuntando a
      `MercadoLibreMensajeriaWebhookController::recibir`
- [X] T013 [P] [US1] Crear `App\Http\Controllers\Mensajeria\ConversacionController` en
      `app/Http/Controllers/Mensajeria/ConversacionController.php` con `index()` (vista principal) y
      `datatable()` (listado server-side de conversaciones vía `Yajra\DataTables`, columnas según
      `contracts/ui-endpoints.md`)
- [X] T014 [US1] Agregar a `ConversacionController` el método `show(MercadoLibreConversacion $conversacion)`
      (detalle AJAX: historial completo de mensajes en orden cronológico) y `actualizaciones(Request $request)`
      (polling — conversaciones/mensajes nuevos desde `?desde=<timestamp>`, R6 de `research.md`) (depende de T013)
- [X] T015 [US1] Registrar las rutas `GET /mensajeria`, `GET /mensajeria/datatable`,
      `GET /mensajeria/{conversacion}`, `GET /mensajeria/actualizaciones` en `routes/web.php` bajo
      `permiso:mensajeria.ver` (depende de T013, T014)
- [X] T016 [P] [US1] Crear vista `resources/views/mensajeria/index.blade.php`, adaptando
      `template/Laravel-NexaDash-v1.0-28_May_2025/package/resources/views/chat.blade.php` al layout del
      proyecto (`layouts.default`): panel de bandeja (DataTables) + panel de conversación (lista de
      mensajes)
- [X] T017 [US1] Crear `resources/js/mensajeria.js`: inicializa el DataTable de la bandeja, carga el
      detalle de una conversación al hacer clic, y arranca el polling periódico de
      `GET /mensajeria/actualizaciones` (depende de T016)
- [X] T018 [US1] Agregar el desplegable "Mensajería" (`@can('mensajeria.ver')`) a
      `resources/views/elements/sidebar.blade.php`, apuntando a `route('mensajeria.index')`

**Checkpoint**: la User Story 1 es funcional y testeable de forma independiente (bandeja + historial,
sin respuesta todavía).

---

## Phase 4: User Story 2 - Responder manualmente con aprobación humana y auditoría (Priority: P1)

**Goal**: Redactar y confirmar el envío real de una respuesta desde la conversación, con auditoría y
protección contra doble respuesta.

**Independent Test**: Escribir una respuesta y confirmarla; verificar que se envía a Mercado Libre (o
al mock en tests) con el texto exacto y que queda auditada; verificar que un segundo intento sobre la
misma conversación ya respondida se rechaza; verificar que un fallo de la API de ML no marca la
conversación como respondida.

### Tests for User Story 2

- [X] T019 [P] [US2] Feature test en `tests/Feature/MercadoLibreEnvioRespuestaTest.php`: envío exitoso
      registra `MercadoLibreRespuestaEnviada` con texto/usuario/fecha y pasa la conversación a
      `respondida` (FR-006); segundo intento sobre la misma conversación devuelve `422` (FR-007); fallo
      simulado de `ClienteMercadoLibre` deja la conversación en `pendiente` y registra
      `resultado=error` sin marcar éxito falso (FR-008)

### Implementation for User Story 2

- [X] T020 [P] [US2] Crear `App\Http\Requests\Mensajeria\EnviarRespuestaMercadoLibreRequest` en
      `app/Http/Requests/Mensajeria/EnviarRespuestaMercadoLibreRequest.php` (valida `texto` requerido,
      autoriza por permiso `mensajeria.responder`)
- [X] T021 [US2] Crear `App\Services\MercadoLibre\Mensajeria\EnvioRespuestaMercadoLibre` en
      `app/Services/MercadoLibre/Mensajeria/EnvioRespuestaMercadoLibre.php`: dentro de una transacción,
      verifica que no exista ya una `MercadoLibreRespuestaEnviada` con `resultado=exito` para ese
      mensaje (FR-007), llama a `ClienteMercadoLibre::enviar()` (`POST /answers` o el endpoint de
      post-venta confirmado en T008), registra el resultado (éxito o error) y actualiza el estado de la
      conversación (depende de T008, T020)
- [X] T022 [US2] Agregar a `ConversacionController` el método
      `responder(EnviarRespuestaMercadoLibreRequest $request, MercadoLibreConversacion $conversacion)`
      que delega en `EnvioRespuestaMercadoLibre`, devolviendo `422` en caso de doble respuesta y el
      error correspondiente si falla el envío (depende de T021)
- [X] T023 [US2] Registrar la ruta `POST /mensajeria/{conversacion}/responder` en `routes/web.php` bajo
      `permiso:mensajeria.responder` (depende de T022)
- [X] T024 [US2] Agregar al panel de conversación en `resources/views/mensajeria/index.blade.php` el
      formulario de respuesta (textarea + botón "Enviar"), visible sólo con permiso
      `mensajeria.responder` (depende de T016)
- [X] T025 [US2] Agregar a `resources/js/mensajeria.js` el envío AJAX del formulario de respuesta,
      mostrando Toastr de éxito/error y refrescando la conversación tras un envío exitoso (depende de
      T017, T024)

**Checkpoint**: las User Stories 1 y 2 funcionan juntas de punta a punta — leer, historial, responder,
auditoría, protección de doble respuesta.

---

## Phase 5: Polish & Cross-Cutting Concerns

**Purpose**: Cierre de la implementación, no ligado a una sola user story.

- [X] T026 [P] Ejecutar `quickstart.md` completo (Escenarios 1 y 2) contra un entorno local con datos
      de prueba, confirmando los resultados esperados en cada paso
- [X] T027 Revisar que `docs/documentacion_principal_crm.md` §6.5 y `docs/modelo_datos.md` §14 (ya
      actualizados en esta cadena de spec-kit) sigan reflejando exactamente lo implementado; ajustar si
      la implementación divergió de lo planeado
- [X] T028 Actualizar `CREDENCIALES_ACCESO.txt` si se crea o modifica algún usuario/rol de prueba para
      validar los permisos `mensajeria.ver`/`mensajeria.responder` (regla del CLAUDE.md del proyecto)

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: sin dependencias — puede arrancar de inmediato.
- **Foundational (Phase 2)**: depende de Setup (necesita las tablas creadas en T001-T003 para que los
  modelos de T005-T007 tengan contra qué mapear). Bloquea ambas user stories.
- **User Story 1 (Phase 3)**: depende de Foundational. No depende de User Story 2.
- **User Story 2 (Phase 4)**: depende de Foundational y de T008 (endpoint de post-venta confirmado).
  Reutiliza la vista y el JS de User Story 1 (T016/T017), por lo que en la práctica conviene
  implementarla después, aunque el guard de doble respuesta y el servicio de envío (T019-T023) son
  independientes del código de la bandeja.
- **Polish (Phase 5)**: depende de que ambas user stories estén completas.

### Parallel Opportunities

- T001-T003 (migraciones) en paralelo entre sí.
- T005-T007 (modelos) en paralelo entre sí, una vez migradas las tablas.
- T009 (test US1) en paralelo con T010 mientras no se corra todavía (TDD: el test debe fallar antes de
  implementar).
- T019 (test US2) en paralelo con el resto de Foundational/US1 si el equipo tiene más de una persona.

---

## Parallel Example: Foundational

```bash
Task: "Crear modelo MercadoLibreConversacion en app/Models/Integraciones/MercadoLibreConversacion.php"
Task: "Crear modelo MercadoLibreMensaje en app/Models/Integraciones/MercadoLibreMensaje.php"
Task: "Crear modelo MercadoLibreRespuestaEnviada en app/Models/Integraciones/MercadoLibreRespuestaEnviada.php"
```

---

## Implementation Strategy

### MVP First (User Story 1 únicamente)

1. Completar Phase 1 (Setup) y Phase 2 (Foundational).
2. Completar Phase 3 (User Story 1) — bandeja + historial, sin poder responder todavía.
3. **Validar** con el Escenario 1 de `quickstart.md`.
4. Recién ahí sumar Phase 4 (User Story 2) para poder responder.

### Incremental Delivery

1. Setup + Foundational → base lista.
2. User Story 1 → validar → (opcionalmente) mostrar avance.
3. User Story 2 → validar con `quickstart.md` completo → feature lista para uso real.
4. Polish (Phase 5).

---

## Notes

- Ninguna tarea de esta lista crea Jobs, colas asíncronas, tabla de sugerencias, o pantalla de
  configuración de bot — eso es la Fase 1 (spec futura), explícitamente fuera de alcance aquí (ver
  `spec.md` → Assumptions).
- T008 es la única tarea que puede reabrir una decisión de research.md (el endpoint exacto de
  mensajería post-venta) — hacerla temprano evita descubrir el problema recién en User Story 2.
- Commitear después de cada tarea o grupo lógico; parar en cada checkpoint para validar la story de
  forma independiente antes de seguir.
