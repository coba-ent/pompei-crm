---
description: "Task list for feature implementation"
---

# Tasks: Conexión Tiendanube vía OAuth/MCP (corrección de spec 015)

**Input**: Design documents from `specs/019-tiendanube-conexion-mcp/`

**Prerequisites**: [plan.md](./plan.md), [spec.md](./spec.md), [research.md](./research.md),
[data-model.md](./data-model.md), [contracts/](./contracts/), [quickstart.md](./quickstart.md)

**Tests**: incluidos y **no negociables** (Principio IV de la constitución + restricción crítica de esta
spec). El riesgo real no es dinero, es que la cuenta usada es la **cuenta real del cliente en
producción**: todo test de escritura, y también los de lectura del flujo OAuth/MCP, usan `Http::fake()`
— ningún test de esta feature puede ejecutar una llamada real contra `admin-mcp.tiendanube.com`.

**Organización**: tareas agrupadas por historia de usuario para poder implementar y probar cada una de
forma independiente.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: puede ejecutarse en paralelo (archivos distintos, sin dependencias pendientes)
- **[Story]**: historia de usuario a la que pertenece (US1..US4)

---

## Phase 1: Setup (Documentación de dominio — ya completada)

**Purpose**: dejar la documentación de dominio consistente antes de tocar código (Principio I).

- [x] T001 Actualizar `docs/documentacion_principal_crm.md` §5.3 (ya hecho: documenta la corrección de
  spec 015 → OAuth/MCP, con las razones)
- [x] T002 Actualizar `docs/modelo_datos.md` §11 (ya hecho: esquema de `tn_configuracion` ajustado a los
  campos OAuth)

**Checkpoint**: documentación de dominio ya consistente — se puede pasar directo a Foundational.

---

## Phase 2: Foundational (Prerrequisitos bloqueantes de todas las historias)

**Purpose**: infraestructura compartida que TODAS las historias necesitan.

**⚠️ CRITICAL**: ninguna historia de usuario puede empezar hasta completar esta fase.

- [x] T003 Crear migración `database/migrations/2026_08_06_060001_update_tn_configuracion_para_oauth_mcp.php`
  según [data-model.md](./data-model.md) §1: agrega `client_id`, `client_secret` (texto, se cifra vía
  cast en el modelo), `scopes_otorgados`, `productos_total`, `conectada_en`; quita `store_id`,
  `nombre_tienda`, `dominio`, `pais`, `moneda`, `ultima_verificacion_en`, `credenciales_guardadas_en`.
  `down()` simétrico.
- [x] T004 Actualizar `App\Models\Integraciones\TiendanubeConfiguracion`
  (`app/Models/Integraciones/TiendanubeConfiguracion.php`): `$fillable`/`$casts` con los campos nuevos
  (`client_secret` y `access_token` cifrados, `conectada_en` datetime), `$hidden` agrega `client_secret`,
  `estaCompleta()` redefinida como presencia de `access_token` (sin `store_id`) — data-model.md "Notas de
  implementación"
- [x] T005 [P] Actualizar `App\Enums\Tiendanube\EstadoConexion`
  (`app/Enums/Tiendanube/EstadoConexion.php`) si hiciera falta ajustar etiquetas — sin cambios de valores
  (`no_configurada`/`conectada`/`caida` ya existían; se deja de usar el valor `desconectada` como estado
  persistido propio, spec.md FR-006)
- [x] T006 Crear `App\Services\Tiendanube\RegistradorClienteOAuth` en
  `app/Services/Tiendanube/RegistradorClienteOAuth.php`: método `registrarSiHaceFalta(): TiendanubeConfiguracion`
  que hace `POST /register` (Dynamic Client Registration, contracts/admin-mcp-tiendanube.md §2) sólo si
  `client_id` todavía no está guardado, y persiste `client_id`/`client_secret` (research.md R1)
- [x] T007 Reescribir `App\Services\Tiendanube\ClienteTiendanube` (`app/Services/Tiendanube/ClienteTiendanube.php`)
  por dentro: `peticion()` arma JSON-RPC (`tools/call`) contra `admin-mcp.tiendanube.com` con header
  `Authorization: Bearer {access_token}` (research.md R2, **no** `Authentication: bearer` como la REST
  anterior de spec 015 — ver contracts/admin-mcp-tiendanube.md §5), parsea la respuesta SSE de un único
  evento (extraer línea `data: `, `json_decode`, leer `result.structuredContent`), distingue error
  HTTP/JSON-RPC de `result.isError: true` (research.md R6). El kill-switch de modo sólo lectura y el
  registro en `TiendanubeOperacionLog` se mantienen en el mismo punto único ya existente — no se
  reescriben, sólo se adaptan a los nombres de tool en vez de endpoints REST
- [x] T008 [P] Crear excepción `App\Services\Tiendanube\Excepciones\RegistroClienteFallidoException` en
  `app/Services/Tiendanube/Excepciones/RegistroClienteFallidoException.php` (el auto-registro `POST
  /register` falla o el servidor no está disponible — edge case de spec.md)

**Checkpoint**: modelo, migración y cliente base listos — las historias de usuario pueden empezar.

---

## Phase 3: User Story 1 — Conectar la tienda por OAuth, sin cargar ningún dato a mano (Priority: P1) 🎯 MVP

**Goal**: el usuario presiona "Conectar con Tiendanube", aprueba en el navegador, y vuelve al CRM con la
conexión establecida y verificada.

**Independent Test**: presionar "Conectar", aprobar en el navegador (validación manual, quickstart.md
Parte 2) y verificar que el CRM queda con la conexión establecida.

### Tests for User Story 1 (todos con `Http::fake()`, spec.md restricción crítica)

- [x] T009 [P] [US1] Test de auto-registro: primera conexión dispara `POST /register` y guarda
  `client_id`/`client_secret`; una segunda conexión (tras desconectar) reutiliza el `client_id` ya
  guardado sin volver a registrar — en `tests/Feature/Integraciones/TiendanubeOAuthTest.php`
- [x] T010 [P] [US1] Test de que `conectar()` arma la URL de `/authorize` con PKCE (`code_challenge`
  S256), `client_id`, todos los scopes (research.md R5) y un `state` de un solo uso guardado en sesión —
  en `tests/Feature/Integraciones/TiendanubeOAuthTest.php`
- [x] T011 [P] [US1] Test de `callback()` exitoso: `state` válido + código válido → intercambio → `POST
  /token` (fake) → `list_products` (fake, FR-003a) exitoso → `estado = conectada`, `productos_total`
  guardado, `access_token` cifrado en la fila cruda — en `tests/Feature/Integraciones/TiendanubeOAuthTest.php`
- [x] T012 [P] [US1] Test de `callback()` con `state` inválido o código reusado → rechazo, `estado` no
  cambia a conectada — en `tests/Feature/Integraciones/TiendanubeOAuthTest.php`
- [x] T013 [P] [US1] Test de `callback()` con intercambio de token exitoso pero verificación FR-003a
  fallida (HTTP error o `isError: true` en `list_products`) → `estado` NO queda conectada — en
  `tests/Feature/Integraciones/TiendanubeOAuthTest.php`
- [x] T014 [P] [US1] Test de que un usuario sin `configuracion.funciones` recibe 403 al acceder a
  `conectar()`/`callback()` — en `tests/Feature/Integraciones/TiendanubeOAuthTest.php`

### Implementation for User Story 1

- [x] T015 [US1] Crear `App\Http\Controllers\Integraciones\TiendanubeOAuthController` en
  `app/Http/Controllers/Integraciones/TiendanubeOAuthController.php` con la acción `conectar()`: llama a
  `RegistradorClienteOAuth::registrarSiHaceFalta()`, genera PKCE (`code_verifier`/`code_challenge`) y
  `state` (guardados en sesión, vencimiento 10 min — spec.md FR-002), redirige a
  `admin-mcp.tiendanube.com/authorize` — calcado de `MercadoLibreOAuthController::conectar()` (spec 011)
  sin la lógica de sitio/país que no aplica acá
- [x] T016 [US1] Agregar la acción `callback()` a `TiendanubeOAuthController`: valida `state` contra
  sesión, intercambia `code` por token (`POST /token` con `code_verifier`), invoca `list_products`
  (`page_size: 1`, `omitir_guard_funcion: true`) como verificación FR-003a, guarda
  `access_token`/`scopes_otorgados`/`productos_total`/`conectada_en`/`estado = conectada` si todo salió
  bien, o informa el error sin dejar la conexión como conectada
- [x] T017 [US1] Agregar el grupo de rutas `configuracion.tiendanube.conectar`/`.callback` en
  `routes/web.php`, bajo `permiso:configuracion.funciones` (contracts/rutas-internas.md), **quitando**
  las rutas `.credenciales` y `.probar` de spec 015 que ya no existen
- [x] T018 [US1] Reescribir `resources/views/configuracion/tiendanube/_panel_estado.blade.php`: botón
  "Conectar con Tiendanube" (link a `configuracion.tiendanube.conectar`) en vez de link a modal de
  credenciales; eliminar `resources/views/configuracion/tiendanube/_modal_credenciales.blade.php` (ya no
  hay formulario de credenciales manuales)
- [x] T019 [US1] Adaptar `resources/views/configuracion/tiendanube/index.blade.php`: quitar la card de
  "Credenciales de la Aplicación personalizada" de spec 015
- [x] T020 [US1] Adaptar `resources/js/tiendanube.js`: quitar el submit del formulario de credenciales;
  pintar en el panel los scopes otorgados y `productos_total` en vez de nombre/dominio/moneda de tienda

**Checkpoint**: el usuario puede conectar la tienda por OAuth y ver la conexión verificada — historia 1
completa y demostrable (MVP).

---

## Phase 4: User Story 2 — Ver que la conexión funciona y poder desconectar (Priority: P1)

**Goal**: panel de estado con scopes + cantidad de productos, y acción "Desconectar" que conserva el
cliente OAuth ya registrado.

**Independent Test**: con la tienda conectada (Historia 1), verificar que el panel muestra "Conectada" y
que "Desconectar" borra el token sin borrar el historial ni el `client_id`/`client_secret`.

### Tests for User Story 2

- [x] T021 [P] [US2] Test de que `estado()` devuelve `configuracion: null` cuando no hay conexión, y los
  campos correctos (`conectada_en`, `scopes_otorgados`, `productos_total`, `modo_solo_lectura`) cuando
  está conectada — en `tests/Feature/Integraciones/TiendanubeConexionTest.php`
- [x] T022 [P] [US2] Test de "Desconectar": borra `access_token`, conserva `client_id`/`client_secret`
  (FR-007) y el historial, deja `estado = no_configurada` — en
  `tests/Feature/Integraciones/TiendanubeConexionTest.php`
- [x] T023 [P] [US2] Test de reconexión tras desconectar: no dispara un nuevo `POST /register` (reutiliza
  `client_id` conservado) — en `tests/Feature/Integraciones/TiendanubeConexionTest.php`
- [x] T024 [P] [US2] Test de que ningún endpoint de la superficie (`estado()`, `historial()`) expone
  `client_secret` ni `access_token` — en `tests/Feature/Integraciones/TiendanubeConexionTest.php`
  (SC-003)

### Implementation for User Story 2

- [x] T025 [US2] Adaptar la acción `estado()` de `TiendanubeConfiguracionController`
  (`app/Http/Controllers/Integraciones/TiendanubeConfiguracionController.php`) a los campos nuevos de
  `tn_configuracion` (contracts/rutas-internas.md)
- [x] T026 [US2] Adaptar la acción `desconectar()` de `TiendanubeConfiguracionController`: borra sólo
  `access_token`, conserva `client_id`/`client_secret`, deja `estado = no_configurada`
- [x] T027 [US2] Quitar del controlador las acciones `credenciales()` y `probar()` de spec 015 (ya no
  existen como endpoints separados — la verificación quedó dentro de `callback()`, T016)

**Checkpoint**: conexión verificable y reversible de punta a punta — historias 1 y 2 constituyen el
producto mínimo utilizable.

---

## Phase 5: User Story 3 — Operar con seguridad: modo sólo lectura y diagnóstico (Priority: P2)

**Goal**: confirmar que el kill-switch y el historial, ya construidos en spec 015, siguen funcionando
igual con el `ClienteTiendanube` basado en MCP.

**Independent Test**: activar el modo sólo lectura y verificar que una tool de escritura (ej.
`update_stock_and_price`) queda bloqueada y registrada, igual que antes.

### Tests for User Story 3

- [x] T028 [P] [US3] Re-verificar (adaptando de spec 015) que con modo sólo lectura activo toda tool de
  escritura del servidor MCP se bloquea y se registra, y las tools de lectura se ejecutan normalmente —
  en `tests/Feature/Integraciones/TiendanubeModoSoloLecturaTest.php`
- [x] T029 [P] [US3] Re-verificar que con la función "Tiendanube" desactivada toda operación se rechaza
  sin alterar el estado de la conexión, salvo la verificación FR-003a del propio `callback()`
  (`omitir_guard_funcion: true`) — en `tests/Feature/Integraciones/TiendanubeFuncionDesactivadaTest.php`
- [x] T030 [P] [US3] Test de que ningún dato sensible (`access_token`, `client_secret`) aparece en
  `tn_operaciones_log` tras invocar tools de éxito, error y bloqueadas — en
  `tests/Feature/Integraciones/TiendanubeConexionTest.php` (FR-005)
- [x] T030a [P] [US3] Re-correr (adaptando a los campos nuevos de `TiendanubeConfiguracion`) el test ya
  existente de spec 015 que cubre FR-014: desactivar la función "Tiendanube" con una conexión activa
  exige confirmación y no altera la conexión — en `tests/Feature/Integraciones/FuncionesAvanzadasTest.php`
  (hallazgo C1 de `/speckit-analyze`)

### Implementation for User Story 3

- [x] T031 [US3] Confirmar (y ajustar si hiciera falta) que el kill-switch de modo sólo lectura dentro de
  `ClienteTiendanube::peticion()` (T007) sigue verificándose en el mismo único punto, ahora sobre el
  nombre de la tool en vez del método HTTP+endpoint REST
- [x] T032 [US3] Confirmar que la acción `historial()` de `TiendanubeConfiguracionController` y la tabla
  en `index.blade.php` siguen funcionando sin cambios (DataTables server-side ya construido en spec 015)

**Checkpoint**: modo sólo lectura y diagnóstico re-verificados sobre el nuevo cliente — historia 3
completa.

---

## Phase 6: User Story 4 — Enterarse cuando la conexión se cae (Priority: P2)

**Goal**: detectar token inválido/revocado y marcar la conexión como "Caída", con "Conectar con
Tiendanube" (flujo completo) como única salida.

**Independent Test**: simular un 401 del servidor MCP (`Http::fake()`) y verificar que la conexión pasa a
"Caída" con la acción de reconectar visible; simular 429/5xx y verificar reintento sin caída.

### Tests for User Story 4

- [x] T033 [P] [US4] Test de que un 401 en cualquier llamada MCP marca `estado = caida` con
  `ultimo_error` descriptivo, sin reintento, **y de que ese `ultimo_error` es un mensaje legible (no el
  cuerpo crudo de la respuesta HTTP ni una traza de excepción)** — en
  `tests/Feature/Integraciones/TiendanubeManejoErroresTest.php` (SC-004, hallazgo C2 de `/speckit-analyze`)
- [x] T034 [P] [US4] Test de que tras una conexión caída, el usuario sólo puede recuperarse rehaciendo el
  flujo completo (T015/T016) — no existe una acción de "recargar sólo el token" (a diferencia de spec
  015) — en `tests/Feature/Integraciones/TiendanubeManejoErroresTest.php`
- [x] T035 [P] [US4] Test de espera creciente ante 429 (respetando `Retry-After` si viene) y de reintento
  acotado ante 5xx/error de conexión, sin marcar la conexión como caída en ninguno de los dos casos — en
  `tests/Feature/Integraciones/TiendanubeManejoErroresTest.php`
- [x] T036 [P] [US4] Test de que `result.isError: true` (HTTP 200) se registra como error en el historial
  sin afectar el estado de la conexión (research.md R6) — en `tests/Feature/Integraciones/TiendanubeManejoErroresTest.php`

### Implementation for User Story 4

- [x] T037 [US4] Completar en `ClienteTiendanube` (T007) el manejo de 401 (marca `estado = caida` +
  `ultimo_error`, no reintenta) y la distinción de `isError: true` vs. error HTTP/JSON-RPC
- [x] T038 [US4] Completar en `ClienteTiendanube` la espera creciente ante 429 y el reintento acotado ante
  5xx/`ConnectionException`
- [x] T039 [US4] Mostrar en `_panel_estado.blade.php` (T018) la acción destacada "Conectar con
  Tiendanube" cuando `estado = caida`, con el texto explicando que hay que rehacer el flujo completo (sin
  ningún campo de token que recargar)

**Checkpoint**: las cuatro historias de usuario funcionan de punta a punta e independientemente.

---

## Phase 7: Polish & Cross-Cutting Concerns

- [x] T040 [P] Revisar que ningún log de aplicación (`storage/logs/laravel.log`) ni respuesta JSON exponga
  `access_token` o `client_secret` bajo ningún escenario de error (SC-003)
- [x] T041 Ejecutar la Parte 1 de [quickstart.md](./quickstart.md) (`php artisan test --filter=Tiendanube`)
  y confirmar que pasa en verde **sin ninguna llamada HTTP real** (revisar que todo test declara
  `Http::fake()`)
- [ ] T042 **Requiere aprobación manual del usuario en el navegador, cuenta real — spec.md restricción
  crítica**: ejecutar la Parte 2 de [quickstart.md](./quickstart.md) de punta a punta (conexión real,
  desconexión, reconexión) y anotar en el propio `quickstart.md` los hallazgos marcados como "a
  verificar" en research.md
- [x] T043 Revisar los 23 ítems de [checklists/security.md](./checklists/security.md) contra el resultado
  final de la implementación y cerrar los que correspondan (7 ya resueltos en la spec durante
  `/speckit-checklist`)
- [x] T044 Eliminar del repositorio los archivos que spec 015 dejó y esta spec ya no usa:
  `resources/views/configuracion/tiendanube/_modal_credenciales.blade.php` (si no se borró en T018),
  confirmar que no queda ninguna referencia rota a `configuracion.tiendanube.credenciales`/`.probar` en
  vistas o JS

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: ya completada (docs actualizadas durante `/speckit-plan`)
- **Foundational (Phase 2)**: bloquea TODAS las historias
- **User Story 1 (Phase 3, P1)**: depende de Foundational — MVP
- **User Story 2 (Phase 4, P1)**: depende de que exista una conexión que ver/desconectar (Phase 3)
- **User Story 3 (Phase 5, P2)**: depende de que `ClienteTiendanube` exista con su transporte MCP (Phase
  3) — el kill-switch y el log se re-verifican sobre ese cliente
- **User Story 4 (Phase 6, P2)**: depende de `ClienteTiendanube` (Phase 3) — el manejo de errores se
  agrega al mismo cliente
- **Polish (Phase 7)**: depende de que las historias que se quieran validar estén completas

### Parallel Opportunities

- T005, T006, T008 (Foundational) en paralelo entre sí — T003→T004 son secuenciales (la migración debe
  existir antes de que el modelo referencie sus columnas), T007 depende de que T004/T006 existan
- T009-T014 (tests de US1) en paralelo entre sí
- T021-T024 (tests de US2) en paralelo entre sí
- T028-T030 (tests de US3) en paralelo entre sí
- T033-T036 (tests de US4) en paralelo entre sí

---

## Implementation Strategy

### MVP First (User Story 1 + 2)

1. Completar Phase 2: Foundational (bloqueante)
2. Completar Phase 3: User Story 1 (conectar por OAuth)
3. Completar Phase 4: User Story 2 (ver estado + desconectar)
4. **STOP y VALIDAR**: correr Parte 1 de quickstart.md (automatizada), y recién ahí pedirle al usuario que
   ejecute la Parte 2 manualmente contra la cuenta real (spec.md restricción crítica — nunca automatizado)
5. Deploy si está listo

### Entrega incremental

1. Foundational → base lista
2. US1 + US2 → conexión verificable y reversible (MVP)
3. US3 → modo sólo lectura + historial re-verificados
4. US4 → manejo de conexión caída
5. Cada historia agrega valor sin romper las anteriores

---

## Notes

- [P] = archivos distintos, sin dependencias pendientes
- La etiqueta [Story] mapea cada tarea a su historia de usuario para trazabilidad
- **Ningún test de esta spec ejecuta una llamada real contra `admin-mcp.tiendanube.com`** — verificar
  esto explícitamente en T041 antes de dar la spec por terminada
- Esta spec corrige `015-tiendanube-conexion` (no la reemplaza como directorio — ambas quedan en
  `specs/` para trazabilidad histórica) y es antecesora directa de `017-ventas-tiendanube` y
  `018-stock-tiendanube`, que deben construirse sobre el resultado de esta spec, no sobre 015
- Commit después de cada tarea o grupo lógico
