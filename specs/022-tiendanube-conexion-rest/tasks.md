---
description: "Task list for feature implementation"
---

# Tasks: Conexión Tiendanube vía Application REST del Partner Portal (aditiva a spec 019)

**Input**: Design documents from `specs/022-tiendanube-conexion-rest/`

**Prerequisites**: [plan.md](./plan.md), [spec.md](./spec.md), [research.md](./research.md),
[data-model.md](./data-model.md), [contracts/](./contracts/), [quickstart.md](./quickstart.md)

**Tests**: incluidos y **no negociables** (Principio IV de la constitución + mismo criterio que spec 019).
El riesgo real no es dinero, es que la cuenta usada es la **cuenta real del cliente en producción**: todo
test de esta spec usa `Http::fake()` — ninguno ejecuta una llamada real contra `www.tiendanube.com` ni
`api.tiendanube.com`.

**Organización**: tareas agrupadas por historia de usuario (spec.md) para poder implementar y probar cada
una de forma independiente. Se agrega además una tarea explícita de **aislamiento** respecto de spec 019 en
cada fase donde aplica, dado que ese aislamiento es el requisito central de esta spec (FR-008/FR-012/FR-013).

## Format: `[ID] [P?] [Story] Description`

- **[P]**: puede ejecutarse en paralelo (archivos distintos, sin dependencias pendientes)
- **[Story]**: historia de usuario a la que pertenece (US1..US3)

---

## Phase 1: Setup (Documentación de dominio — ya completada)

**Purpose**: dejar la documentación de dominio consistente antes de tocar código (Principio I).

- [x] T001 Actualizar `docs/documentacion_principal_crm.md` §5.3 (ya hecho: agrega la "Etapa 5 — Conexión
  vía Application REST del Partner Portal", explícita sobre el aislamiento respecto de spec 019)
- [x] T002 Actualizar `docs/modelo_datos.md` §11 (ya hecho: documenta `tn_conexion_rest` y
  `tn_rest_operaciones_log` como tablas nuevas e independientes)

**Checkpoint**: documentación de dominio ya consistente — se puede pasar directo a Foundational.

---

## Phase 2: Foundational (Prerrequisitos bloqueantes de todas las historias)

**Purpose**: infraestructura compartida que TODAS las historias necesitan, sin tocar nada de spec 019.

**⚠️ CRITICAL**: ninguna historia de usuario puede empezar hasta completar esta fase.

- [X] T003 Crear migración `database/migrations/2026_08_07_060001_create_tn_conexion_rest_y_log.php`
  (data-model.md): crea `tn_conexion_rest` (fila única — `access_token` text nullable, `store_id`
  string(50) nullable, `scopes_otorgados` string(255) nullable, `tienda_nombre` string(255) nullable,
  `tienda_dominio` string(255) nullable, `estado` string(20) default `no_configurada`, `ultimo_error` text
  nullable, `conectada_en` timestamp nullable, `actualizada_por` FK→users nullable, timestamps) y
  `tn_rest_operaciones_log` (mismas columnas que `tn_operaciones_log`: `operacion`, `metodo`, `endpoint`,
  `sentido`, `resultado`, `codigo_http`, `duracion_ms`, `mensaje_error`, `usuario_id` FK→users nullable,
  `created_at` sin `updated_at`). **No modifica** `tn_configuracion` ni `tn_operaciones_log` — `down()`
  simétrico, sólo dropea las dos tablas nuevas.
- [X] T004 [P] Crear `App\Models\Integraciones\TiendanubeConexionRest` en
  `app/Models/Integraciones/TiendanubeConexionRest.php`: `$fillable` con las columnas de T003,
  `$casts` (`access_token` => `encrypted`, `estado` => `App\Enums\Tiendanube\EstadoConexion` — se reutiliza
  el Enum PHP existente, no la tabla), `$hidden` = `['access_token']`, método estático `actual()` (mismo
  patrón perezoso que `TiendanubeConfiguracion::actual()`), `estaCompleta()` (`filled(access_token) &&
  filled(store_id)`)
- [X] T005 [P] Crear `App\Models\Integraciones\TiendanubeRestOperacionLog` en
  `app/Models/Integraciones/TiendanubeRestOperacionLog.php`: calcado de `TiendanubeOperacionLog`
  (`$fillable`, `$timestamps = false`, método estático `registrar()` con el mismo saneado de campos
  sensibles y la misma política de retención) — **sin heredar ni compartir código con
  `TiendanubeOperacionLog`**, copia independiente para no crear ningún acoplamiento entre las dos
  conexiones (research.md R1)
- [X] T006 [P] Crear `App\Services\Tiendanube\VerificadorConexionRest` en
  `app/Services/Tiendanube/VerificadorConexionRest.php`: método `verificar(string $accessToken, string
  $storeId): array` que hace `GET https://api.tiendanube.com/v1/{store_id}/store` con cabeceras
  `Authentication: bearer {token}` (no `Authorization`) y `User-Agent` (contracts/api-tiendanube-rest.md
  §3-4), con la misma espera creciente/reintento acotado que `ClienteTiendanube` (research.md R5,
  constantes propias — sin importar `ClienteTiendanube`), devuelve `['ok' => bool, 'nombre' => ?string,
  'dominio' => ?string, 'codigo_http' => ?int, 'mensaje_error' => ?string]`
- [X] T007 [P] Agregar a `.env`/`.env.example` las claves ya previstas en `config/integraciones.php`
  (`TN_CLIENT_ID=38015`, `TN_CLIENT_SECRET`, valor real sólo en `.env` local y de producción — nunca en
  `.env.example`) — confirmar que `config('integraciones.tiendanube.client_id'/'client_secret')` las
  resuelve correctamente

**Checkpoint**: tablas, modelos y servicio de verificación listos, sin ninguna dependencia de
`tn_configuracion`/`ClienteTiendanube` — las historias de usuario pueden empezar.

---

## Phase 3: User Story 1 — Conectar la Application REST sin afectar la conexión existente (Priority: P1) 🎯 MVP

**Goal**: el usuario presiona "Conectar" en el apartado nuevo, aprueba en el navegador, y vuelve al CRM con
esta conexión establecida y verificada — sin que la conexión MCP existente se vea afectada.

**Independent Test**: presionar "Conectar" en el apartado nuevo, aprobar en el navegador (validación manual,
quickstart.md Parte 2) y verificar que ese apartado queda "Conectado" mientras el panel de la conexión MCP
sigue mostrando exactamente el mismo estado que antes.

### Tests for User Story 1 (todos con `Http::fake()`, spec.md restricción crítica)

- [X] T008 [P] [US1] Test de que `conectarRest()` arma la URL de `/apps/{app_id}/authorize` con `state` de
  un solo uso, **sin** `redirect_uri` en la query (research.md R2) — en
  `tests/Feature/Integraciones/TiendanubeConexionRestTest.php`
- [X] T009 [P] [US1] Test de `callbackRest()` exitoso: `state` válido + código válido → `POST
  /apps/authorize/token` (fake, body JSON) → `GET /{store_id}/store` (fake, FR-005) exitoso → `estado =
  conectada`, `store_id`/`scopes_otorgados`/`tienda_nombre`/`tienda_dominio`/`conectada_en` guardados,
  `access_token` cifrado en la fila cruda, **y se crearon las filas correspondientes en
  `tn_rest_operaciones_log` para las operaciones `conectar` y `verificar` (FR-011)** — en
  `tests/Feature/Integraciones/TiendanubeConexionRestTest.php`
- [X] T010 [P] [US1] Test de `callbackRest()` con `state` inválido o código reusado/vencido → rechazo,
  `estado` no cambia a "conectada" — en `tests/Feature/Integraciones/TiendanubeConexionRestTest.php`
- [X] T011 [P] [US1] Test de `callbackRest()` con `error=access_denied` en la query → rechazo sin mostrar el
  error crudo al usuario — en `tests/Feature/Integraciones/TiendanubeConexionRestTest.php`
- [X] T012 [P] [US1] Test de `callbackRest()` con intercambio exitoso pero `GET /store` (FR-005) fallido
  (HTTP error o payload incompleto) → `estado` NO queda "conectada" aunque el token se haya obtenido — en
  `tests/Feature/Integraciones/TiendanubeConexionRestTest.php`
- [X] T013 [P] [US1] Test de que un usuario sin `configuracion.funciones` recibe 403 al acceder a
  `conectarRest()`/`callbackRest()` — en `tests/Feature/Integraciones/TiendanubeConexionRestTest.php`
- [X] T014 [P] [US1] **Aislamiento**: test de que completar `callbackRest()` con éxito no modifica ni una
  columna de `tn_configuracion` ni dispara ninguna llamada a `admin-mcp.tiendanube.com` (`Http::fake()` +
  `Http::assertNotSent` sobre esa URL) — en
  `tests/Feature/Integraciones/TiendanubeConexionRestAislamientoTest.php`

### Implementation for User Story 1

- [X] T015 [US1] Crear `App\Http\Controllers\Integraciones\TiendanubeConexionRestController` en
  `app/Http/Controllers/Integraciones/TiendanubeConexionRestController.php` con la acción `conectarRest()`:
  genera `state` de un solo uso (guardado en sesión con clave propia, distinta de la que usa
  `TiendanubeOAuthController`, vencimiento 10 min — spec.md FR-002), redirige a
  `https://www.tiendanube.com/apps/{client_id}/authorize?state=...` (contracts/api-tiendanube-rest.md §1)
- [X] T016 [US1] Agregar la acción `callbackRest()`: valida `state` contra sesión, intercambia `code` por
  token (`POST https://www.tiendanube.com/apps/authorize/token`, body JSON — contracts/api-tiendanube-rest.md
  §2), invoca `VerificadorConexionRest::verificar()` (T006, FR-005), guarda
  `access_token`/`store_id`/`scopes_otorgados`/`tienda_nombre`/`tienda_dominio`/`conectada_en`/`estado =
  conectada` en `tn_conexion_rest` si todo salió bien, o informa el error sin dejar la conexión como
  conectada
- [X] T017 [US1] Agregar el grupo de rutas `configuracion.tiendanube.conectarRest`/`.callbackRest` en
  `routes/web.php`, dentro del mismo `prefix('tiendanube')` existente, bajo
  `permiso:configuracion.funciones` (contracts/rutas-internas.md) — **sin tocar** las rutas
  `.conectar`/`.callback` existentes de spec 019
- [X] T018 [US1] Crear `resources/views/configuracion/tiendanube/_panel_estado_rest.blade.php`: card propia
  con botón "Conectar" (link a `configuracion.tiendanube.conectarRest`), separada visualmente del panel MCP
  existente
- [X] T019 [US1] Incluir el partial nuevo (T018) en `resources/views/configuracion/tiendanube/index.blade.php`
  mediante un `@include` agregado, **sin modificar** el `@include` ni el contenido existente del panel MCP

**Checkpoint**: el usuario puede conectar esta Application por OAuth y ver la conexión verificada, sin que
la conexión MCP se vea afectada — historia 1 completa y demostrable (MVP).

---

## Phase 4: User Story 2 — Ver el estado real de esta conexión y poder desconectarla (Priority: P1)

**Goal**: panel de estado propio (nombre/dominio de tienda, scopes, fecha) y acción "Desconectar" que sólo
afecta esta conexión.

**Independent Test**: con ambas conexiones activas, desconectar sólo la Application REST y verificar que la
conexión MCP sigue "Conectada" sin ninguna interrupción.

### Tests for User Story 2

- [X] T020 [P] [US2] Test de que `estadoRest()` devuelve `conexion: null` cuando no hay conexión, y los
  campos correctos (`conectada_en`, `scopes_otorgados`, `tienda_nombre`, `tienda_dominio`) cuando está
  conectada — en `tests/Feature/Integraciones/TiendanubeConexionRestTest.php`
- [X] T021 [P] [US2] Test de "Desconectar": limpia `access_token`/`store_id`/`scopes_otorgados`/
  `tienda_nombre`/`tienda_dominio`/`conectada_en` de `tn_conexion_rest`, deja `estado = no_configurada`,
  registra en `tn_rest_operaciones_log` — en `tests/Feature/Integraciones/TiendanubeConexionRestTest.php`
- [X] T022 [P] [US2] Test de que ningún endpoint de esta conexión (`estadoRest()`, y las respuestas de error
  de `callbackRest()`) expone `access_token` **ni `client_secret`** (el valor de
  `config('integraciones.tiendanube.client_secret')` no debe aparecer en ningún body de respuesta JSON) —
  en `tests/Feature/Integraciones/TiendanubeConexionRestTest.php` (SC-003, FR-006)
- [X] T023 [P] [US2] **Aislamiento**: test de que desconectar esta conexión no modifica `tn_configuracion`
  ni su historial, y de que desconectar la conexión MCP (spec 019, `configuracion.tiendanube.desconectar`)
  no modifica `tn_conexion_rest` ni `tn_rest_operaciones_log` — en
  `tests/Feature/Integraciones/TiendanubeConexionRestAislamientoTest.php`

### Implementation for User Story 2

- [X] T024 [US2] Agregar la acción `estadoRest()` a `TiendanubeConexionRestController` (T015):
  no configurada / conectada / caída (contracts/rutas-internas.md)
- [X] T025 [US2] Agregar la acción `desconectarRest()`: limpia los campos de `tn_conexion_rest`, deja
  `estado = no_configurada`, registra en `tn_rest_operaciones_log`
- [X] T026 [US2] Agregar la ruta `configuracion.tiendanube.desconectarRest` en `routes/web.php`, mismo
  grupo que T017
- [X] T027 [US2] Agregar a `resources/js/tiendanube.js` el manejo AJAX de `estadoRest()`/`desconectarRest()`
  para el panel nuevo (T018) — mismo patrón de polling + toasts que ya usa el panel MCP existente, **código
  agregado al final del archivo, sin tocar el manejo existente**
- [X] T028 [US2] Completar `_panel_estado_rest.blade.php` (T018) con los datos de conexión (nombre/dominio
  de tienda, scopes, fecha) y el botón "Desconectar" con confirmación previa (modal Bootstrap, mismo patrón
  que el resto del CRM)

**Checkpoint**: conexión verificable y reversible de punta a punta, sin interferir con la conexión MCP —
historias 1 y 2 constituyen el producto mínimo utilizable.

---

## Phase 5: User Story 3 — Enterarse cuando esta conexión se cae o falla (Priority: P2)

**Goal**: detectar token inválido/revocado y marcar sólo este apartado como "Caída", sin afectar el estado
de la conexión MCP.

**Independent Test**: simular un 401 en la verificación (`Http::fake()`) y verificar que sólo este apartado
pasa a "Caída" con la acción de reconectar visible, mientras la conexión MCP permanece intacta.

### Tests for User Story 3

- [X] T029 [P] [US3] Test de que un 401/404 en la verificación marca `estado = caida` con `ultimo_error`
  legible (no el cuerpo crudo de la respuesta), sin reintento, **y que queda registrada la fila de error
  correspondiente en `tn_rest_operaciones_log` (FR-011)** — en
  `tests/Feature/Integraciones/TiendanubeConexionRestErroresTest.php`
- [X] T030 [P] [US3] Test de espera creciente ante 429 (respetando `Retry-After` si viene) y de reintento
  acotado ante 5xx/error de conexión durante la verificación, sin marcar la conexión como caída en ninguno
  de los dos casos — en `tests/Feature/Integraciones/TiendanubeConexionRestErroresTest.php`
- [X] T031 [P] [US3] **Aislamiento**: test de que una conexión caída de esta Application (401 simulado) no
  cambia el `estado` de `tn_configuracion` (conexión MCP), y viceversa (401 simulado contra MCP no cambia
  `tn_conexion_rest.estado`) — en `tests/Feature/Integraciones/TiendanubeConexionRestAislamientoTest.php`

### Implementation for User Story 3

- [X] T032 [US3] Completar en `VerificadorConexionRest` (T006) el manejo de 401/404 (devuelve `ok: false`
  con mensaje legible) y la espera creciente ante 429/5xx (research.md R5)
- [X] T033 [US3] En `TiendanubeConexionRestController::estadoRest()` (T024) y en cualquier verificación
  futura de esta conexión: si `VerificadorConexionRest` devuelve `ok: false` por 401/404, actualizar
  `tn_conexion_rest.estado = caida` + `ultimo_error`
- [X] T034 [US3] Mostrar en `_panel_estado_rest.blade.php` (T018/T028) la acción destacada "Conectar" cuando
  `estado = caida`, independiente del estado mostrado en el panel MCP

**Checkpoint**: las tres historias de usuario funcionan de punta a punta e independientemente, sin ninguna
interferencia mutua con la conexión MCP existente.

---

## Phase 6: Polish & Cross-Cutting Concerns

- [X] T035 [P] Revisar que ningún log de aplicación (`storage/logs/laravel.log`) ni respuesta JSON de esta
  spec exponga `access_token` bajo ningún escenario de error (SC-003)
- [X] T036 Ejecutar la Parte 1 de [quickstart.md](./quickstart.md)
  (`php artisan test --filter=TiendanubeConexionRest`) y confirmar que pasa en verde **sin ninguna llamada
  HTTP real** (revisar que todo test declara `Http::fake()`)
- [X] T037 Correr la suite existente de spec 019 sin modificarla
  (`php artisan test --filter=TiendanubeOAuth`, `php artisan test --filter=TiendanubeConexion`) y confirmar
  que sigue en verde exactamente igual que antes de esta spec (SC-002)
- [X] T038 Revisar los 18 ítems de
  [checklists/aislamiento-seguridad.md](./checklists/aislamiento-seguridad.md) contra el resultado final de
  la implementación
- [ ] T039 **Paso manual, fuera del código**: actualizar en `partners.tiendanube.com` el `redirect_uri` de
  la Application "pompei" a `https://contagramdemo.devstudioweb.com/configuracion/tiendanube/callback-rest`
  (research.md R2, quickstart.md Parte 0) — anotar el cambio en `CREDENCIALES_ACCESO.txt` si corresponde
- [ ] T040 **Requiere aprobación manual del usuario en el navegador, cuenta real**: ejecutar la Parte 2 de
  [quickstart.md](./quickstart.md) de punta a punta (conexión real, verificación en paralelo de que la
  conexión MCP no se alteró, desconexión, reconexión) y anotar en el propio `quickstart.md` los hallazgos
  marcados como "a verificar" en research.md (en particular R3: si la respuesta real trae `expires_in`)

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: ya completada (docs actualizadas durante `/speckit-plan`)
- **Foundational (Phase 2)**: bloquea TODAS las historias — no depende de nada de spec 019
- **User Story 1 (Phase 3, P1)**: depende de Foundational — MVP
- **User Story 2 (Phase 4, P1)**: depende de que exista una conexión que ver/desconectar (Phase 3)
- **User Story 3 (Phase 5, P2)**: depende de `VerificadorConexionRest` (Phase 2) y de `estadoRest()` (Phase 4)
- **Polish (Phase 6)**: depende de que las historias que se quieran validar estén completas; T039 es
  prerrequisito de T040

### Parallel Opportunities

- T004, T005, T006, T007 (Foundational) en paralelo entre sí — todos dependen de T003 (la migración debe
  existir antes de que el modelo referencie sus columnas)
- T008-T014 (tests de US1) en paralelo entre sí
- T020-T023 (tests de US2) en paralelo entre sí
- T029-T031 (tests de US3) en paralelo entre sí

---

## Implementation Strategy

### MVP First (User Story 1 + 2)

1. Completar Phase 2: Foundational (bloqueante)
2. Completar Phase 3: User Story 1 (conectar por OAuth)
3. Completar Phase 4: User Story 2 (ver estado + desconectar)
4. **STOP y VALIDAR**: correr Parte 1 de quickstart.md (automatizada) + la suite existente de spec 019
   (T037), y recién ahí pedirle al usuario que complete T039 (paso manual en el Partner Portal) y ejecute la
   Parte 2 de quickstart.md manualmente contra la cuenta real
5. Si la validación real es exitosa, evaluar (en una spec futura) migrar el resto de la integración a REST

### Entrega incremental

1. Foundational → base lista, sin tocar nada de spec 019
2. US1 + US2 → conexión verificable y reversible, aislada (MVP)
3. US3 → manejo de conexión caída, aislado
4. Cada historia agrega valor sin romper ni las anteriores ni la conexión MCP existente

---

## Notes

- [P] = archivos distintos, sin dependencias pendientes
- La etiqueta [Story] mapea cada tarea a su historia de usuario para trazabilidad
- **Ningún test de esta spec ejecuta una llamada real contra Tiendanube** — verificar esto explícitamente en
  T036 antes de dar la spec por terminada
- Cada fase incluye al menos un test de **aislamiento** explícito (T014, T023, T031) porque ese aislamiento
  es el requisito central de esta spec, no un efecto secundario a asumir
- Esta spec es aditiva a `019-tiendanube-conexion-mcp` (no la reemplaza ni la corrige) — es la base para una
  eventual spec futura que migre specs 017/018 de MCP a REST, condicionada a que T040 (validación real) sea
  exitosa
- **FR-012 (no usar esta conexión desde código de negocio) no tiene tarea de test dedicada** —
  `/speckit-analyze` (hallazgo E3) lo marcó como aceptado sin tarea nueva: es un requisito de *ausencia* de
  código, mejor verificable por revisión (T038, checklist) que por un test automatizado de bajo valor.
- Commit después de cada tarea o grupo lógico
