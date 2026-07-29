---
description: "Task list for feature implementation"
---

# Tasks: Conexión Tiendanube (Aplicación personalizada)

**Input**: Design documents from `specs/015-tiendanube-conexion/`

**Prerequisites**: [plan.md](./plan.md), [spec.md](./spec.md), [research.md](./research.md),
[data-model.md](./data-model.md), [contracts/](./contracts/), [quickstart.md](./quickstart.md)

**Tests**: incluidos. Principio IV de la constitución exige test donde hay riesgo real de dinero o de
impacto sobre el negocio; acá el riesgo equivalente es de seguridad/confiabilidad (bloqueo de
escrituras, manejo de credencial caída), tratado con el mismo rigor que Mercado Libre (spec 011).

**Organización**: tareas agrupadas por historia de usuario para poder implementar y probar cada una de
forma independiente.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: puede ejecutarse en paralelo (archivos distintos, sin dependencias pendientes)
- **[Story]**: historia de usuario a la que pertenece (US1..US4)
- Rutas de archivo exactas en cada descripción

---

## Phase 1: Setup (Documentación de dominio — bloqueante por Principio I)

**Purpose**: dejar la documentación de dominio consistente con lo que esta spec introduce, **antes**
de escribir código (constitución, Principio I; plan.md Fase A).

- [ ] T001 Actualizar `docs/documentacion_principal_crm.md` §5 con la sección de integración Tiendanube: documentar explícitamente la divergencia deliberada respecto de Contagram (Aplicación personalizada en vez del flujo de 4 pasos con partner app) y su justificación, análoga a la ya existente para Mercado Libre (§5.2)
- [ ] T002 Actualizar `docs/modelo_datos.md` agregando las entidades `tn_configuracion` y `tn_operaciones_log` (ver [data-model.md](./data-model.md))
- [ ] T003 Actualizar `docs/informe_contagram_funciones_avanzadas.md` §7 / `docs/documentacion_principal_crm.md` §7 (Módulos pendientes de re-relevamiento) para sacar "Integraciones → TiendaNube" de la lista de pendientes, igual que ya se hizo para Mercado Libre

**Checkpoint**: documentación de dominio consistente con esta spec antes de tocar código.

---

## Phase 2: Foundational (Prerrequisitos bloqueantes de todas las historias)

**Purpose**: infraestructura compartida que TODAS las historias necesitan.

**⚠️ CRITICAL**: ninguna historia de usuario puede empezar hasta completar esta fase.

- [ ] T004 [P] Crear migración `database/migrations/2026_08_05_060001_create_tn_configuracion_table.php` según [data-model.md](./data-model.md) §1 (`store_id`, `access_token` cifrado, `nombre_tienda`, `dominio`, `pais`, `moneda`, `estado`, `ultimo_error`, `modo_solo_lectura`, `credenciales_guardadas_en`, `ultima_verificacion_en`, `actualizada_por`)
- [ ] T005 [P] Crear migración `database/migrations/2026_08_05_060002_create_tn_operaciones_log_table.php` según [data-model.md](./data-model.md) §2 (mismo esquema que `ml_operaciones_log`)
- [ ] T006 [P] Crear enum `App\Enums\Tiendanube\EstadoConexion` en `app/Enums/Tiendanube/EstadoConexion.php` (`no_configurada` \| `desconectada` \| `conectada` \| `caida`, con `etiqueta()` y `color()`, mismo patrón que `App\Enums\MercadoLibre\EstadoConexion` pero sin `PendienteConfirmacion` — research.md §R6)
- [ ] T007 [P] Crear modelo `App\Models\Integraciones\TiendanubeConfiguracion` en `app/Models/Integraciones/TiendanubeConfiguracion.php`: `$table = 'tn_configuracion'`, `$hidden = ['access_token']`, cast `encrypted` sobre `access_token`, método estático `actual()` (mismo patrón que `MercadoLibreConfiguracion::actual()`), y `estaCompleta(): bool`
- [ ] T008 [P] Crear modelo `App\Models\Integraciones\TiendanubeOperacionLog` en `app/Models/Integraciones/TiendanubeOperacionLog.php` con método estático `registrar(array $datos)`, mismo patrón que `MercadoLibreOperacionLog`
- [ ] T009 Actualizar `database/seeders/FuncionAvanzadaSeeder.php`: la función `tiendanube` pasa a `disponible => true` y `ruta_configuracion => 'configuracion.tiendanube.index'` (hoy `disponible => false`, `ruta_configuracion => null`)
- [ ] T010 Crear excepción `App\Services\Tiendanube\Excepciones\ConexionCaidaException` en `app/Services/Tiendanube/Excepciones/ConexionCaidaException.php`
- [ ] T010a [P] Crear excepción `App\Services\Tiendanube\Excepciones\CredencialesIlegiblesException` en `app/Services/Tiendanube/Excepciones/CredencialesIlegiblesException.php` (edge case spec.md: el `access_token` cifrado no puede descifrarse si cambió la clave de la aplicación del entorno — mismo patrón que `CredencialesIlegiblesException` de Mercado Libre)
- [ ] T011 Crear `App\Services\Tiendanube\RespuestaTiendanube` en `app/Services/Tiendanube/RespuestaTiendanube.php`, mismo patrón que `RespuestaMercadoLibre` (factories `ok()`, `error()`, `bloqueada()`, método `fueBloqueada()`/`fallo()`)
- [ ] T012 Agregar el grupo de rutas `configuracion.tiendanube.*` en `routes/web.php`, bajo `permiso:configuracion.funciones`, prefijo `configuracion/tiendanube` (mismo bloque donde ya vive `configuracion.mercadolibre.*`)

**Checkpoint**: base de datos, modelos y rutas listos — las historias de usuario pueden empezar.

---

## Phase 3: User Story 1 — Activar la función y cargar la Aplicación personalizada (Priority: P1) 🎯 MVP

**Goal**: el usuario activa la tarjeta "Tiendanube" en Funciones Avanzadas y carga `store_id` +
`access_token`, guardados cifrados.

**Independent Test**: activar la tarjeta, cargar credenciales, guardar, y verificar que persisten
cifradas y que el token nunca se muestra en claro al volver a la pantalla.

### Tests for User Story 1

- [ ] T013 [P] [US1] Test de guardado de credenciales (campos obligatorios, formato de `store_id`, token nunca expuesto en la respuesta JSON) en `tests/Feature/Integraciones/TiendanubeConfiguracionTest.php`
- [ ] T014 [P] [US1] Test de que activar la función "Tiendanube" habilita el acceso a su configuración y de que un usuario sin `configuracion.funciones` recibe 403, en `tests/Feature/Integraciones/TiendanubeConfiguracionTest.php`

### Implementation for User Story 1

- [ ] T015 [US1] Crear `App\Http\Requests\Integraciones\GuardarCredencialesTiendanubeRequest` en `app/Http/Requests/Integraciones/GuardarCredencialesTiendanubeRequest.php` (valida `store_id` numérico y `access_token` no vacío cuando vienen; al menos uno de los dos presente — contracts/rutas-internas.md)
- [ ] T016 [US1] Crear `App\Http\Controllers\Integraciones\TiendanubeConfiguracionController` en `app/Http/Controllers/Integraciones/TiendanubeConfiguracionController.php` con las acciones `index()` y `credenciales()` (guardar/reemplazar `store_id`/`access_token`, normalizando espacios — edge case de spec.md)
- [ ] T017 [US1] FR-005: al reemplazar el `access_token` con una conexión `conectada`, marcar `estado = desconectada` y devolver la advertencia de que la conexión anterior queda invalidada hasta volver a probarla
- [ ] T017a [US1] FR-006a: agregar en `App\Http\Controllers\Configuracion\FuncionAvanzadaController::estado()` (`app/Http/Controllers/Configuracion/FuncionAvanzadaController.php`) una rama `$funcion->clave === 'tiendanube'` análoga a la ya existente para `'mercadolibre'` (FR-005a): si se desactiva con `TiendanubeConfiguracion::actual()->estado === conectada` y no viene `confirmado`, responder 409 con `requiere_confirmacion: true` y el mensaje de que se suspenden las operaciones pero la configuración se conserva
- [ ] T017b [P] [US1] Test de la rama `tiendanube` de `FuncionAvanzadaController::estado()` (409 sin confirmar, 200 confirmando, sin cambios si no hay conexión activa) en `tests/Feature/Configuracion/FuncionesAvanzadasTest.php`
- [ ] T018 [US1] Crear vista `resources/views/configuracion/tiendanube/index.blade.php` con el formulario de credenciales (store_id visible, indicador de "token cargado" sin mostrar el valor, botón para reemplazarlo)
- [ ] T019 [US1] Crear `resources/js/tiendanube.js`: guardar credenciales por AJAX, mostrar toasts de éxito/error, sin recarga de página (SC-006)
- [ ] T020 [P] [US1] Test de que la tarjeta "Tiendanube" aparece habilitada (no deshabilitada) en Funciones Avanzadas tras el seeder de T009, reutilizando el mismo assert genérico de `FuncionesAvanzadasTest` ya usado para verificar que Mercado Libre no aparece como "no disponible" (FR-004 de la spec 011 ya la habilita automáticamente vía `disponible = true`; esta tarea sólo confirma que no hace falta ningún cambio adicional en la vista/JS de Funciones Avanzadas)

**Checkpoint**: el usuario puede activar la función, cargar credenciales, y verlas persistidas
(cifradas) al volver a la pantalla — historia 1 completa y demostrable.

---

## Phase 4: User Story 2 — Verificar la conexión y ver los datos de la tienda vinculada (Priority: P1)

**Goal**: "Probar conexión" contra la API real de Tiendanube, panel de estado con los datos de la
tienda, y "Desconectar".

**Independent Test**: con una tienda de prueba, cargar credenciales, presionar "Probar conexión" y
verificar que el panel muestra los datos reales de esa tienda.

### Tests for User Story 2

- [ ] T021 [P] [US2] Test de "Probar conexión" exitosa con `Http::fake()` (mapeo de `GET /{store_id}/store` a `nombre_tienda`/`dominio`/`pais`/`moneda`, estado pasa a `conectada`) en `tests/Feature/Integraciones/TiendanubeConexionTest.php`
- [ ] T022 [P] [US2] Test de rechazo por token inválido/`store_id` incorrecto (404/401 → conexión no queda `conectada`) en `tests/Feature/Integraciones/TiendanubeConexionTest.php`
- [ ] T023 [P] [US2] Test de "Desconectar" (borra `access_token`, conserva datos de la tienda, estado pasa a `desconectada`, queda en el historial) en `tests/Feature/Integraciones/TiendanubeConexionTest.php`
- [ ] T024 [P] [US2] Test de que "Probar conexión" con configuración incompleta devuelve el motivo concreto sin llamar a la API (FR-004) en `tests/Feature/Integraciones/TiendanubeConfiguracionTest.php`

### Implementation for User Story 2

- [ ] T025 [US2] Crear `App\Services\Tiendanube\ClienteTiendanube` en `app/Services/Tiendanube/ClienteTiendanube.php`: punto único de salida hacia la API (`obtener()`/`enviar()`/`peticion()`), cabecera `Authentication: bearer {token}` + `User-Agent` (research.md §R3), timeouts 10s/30s, mapeo de errores de research.md §R5 (401/404 → Caída, 429/5xx → reintento con espera creciente, resto → error no reintentable)
- [ ] T025a [US2] En `ClienteTiendanube`, capturar `Illuminate\Contracts\Encryption\DecryptException` al leer `access_token` y relanzar `CredencialesIlegiblesException` con un mensaje comprensible, marcando `estado = caida` (edge case spec.md: clave de aplicación del entorno cambiada — mismo patrón que `ClienteMercadoLibre::renovarToken`)
- [ ] T025b [P] [US2] Test de `CredencialesIlegiblesException` ante un `access_token` ilegible (simulando `APP_KEY` distinta) en `tests/Feature/Integraciones/TiendanubeManejoErroresTest.php`
- [ ] T026 [US2] Implementar en `ClienteTiendanube` el guard de función avanzada desactivada (equivalente a FR-005b de Mercado Libre): toda operación se bloquea salvo `omitir_guard_funcion: true`, reutilizado por "Probar conexión" disparado desde el guardado de credenciales
- [ ] T027 [US2] Implementar la operación `probarConexion()` en `ClienteTiendanube` (`GET /{store_id}/store`), actualizando `nombre_tienda`/`dominio`/`pais`/`moneda`/`estado`/`ultima_verificacion_en` en `TiendanubeConfiguracion` cuando responde con éxito (contracts/api-tiendanube.md §3)
- [ ] T028 [US2] Agregar las acciones `estado()`, `probar()` y `desconectar()` a `TiendanubeConfiguracionController` (contracts/rutas-internas.md)
- [ ] T029 [US2] Crear `resources/views/configuracion/tiendanube/_panel_estado.blade.php` con el panel de estado (No configurada/Desconectada/Conectada/Caída, datos de la tienda, fechas) y el modal/confirmación de "Desconectar"
- [ ] T030 [US2] Extender `resources/js/tiendanube.js`: "Probar conexión" y "Desconectar" por AJAX, refresco del panel de estado sin recarga de página (SC-006)

**Checkpoint**: conexión verificable de punta a punta contra una tienda real — historias 1 y 2
constituyen el producto mínimo utilizable.

---

## Phase 5: User Story 3 — Operar con seguridad: modo sólo lectura y diagnóstico (Priority: P2)

**Goal**: kill-switch de escrituras + historial de operaciones consultable.

**Independent Test**: activar el interruptor y verificar que una operación de escritura queda
registrada como bloqueada en vez de ejecutarse.

### Tests for User Story 3

- [ ] T031 [P] [US3] Test de que con modo sólo lectura activo toda escritura se bloquea y se registra, y las lecturas se ejecutan normalmente, en `tests/Feature/Integraciones/TiendanubeModoSoloLecturaTest.php` (SC-003)
- [ ] T032 [P] [US3] Test de que con la función "Tiendanube" desactivada toda operación se rechaza sin alterar el estado de la conexión, en `tests/Feature/Integraciones/TiendanubeFuncionDesactivadaTest.php` (FR-006b)
- [ ] T033 [P] [US3] Test de que ningún dato sensible (el token) aparece en `tn_operaciones_log`, en `tests/Feature/Integraciones/TiendanubeConexionTest.php` (FR-015)

### Implementation for User Story 3

- [ ] T034 [US3] Implementar en `ClienteTiendanube` el kill-switch de modo sólo lectura (bloquea `sentido = escritura` cuando `TiendanubeConfiguracion::actual()->modo_solo_lectura`, registra en el historial con el detalle de lo que se habría enviado)
- [ ] T035 [US3] Implementar el registro de toda operación (éxito/error/bloqueada) en `TiendanubeOperacionLog` desde `ClienteTiendanube`, excluyendo siempre el token del `mensaje_error`/`payload_bloqueado`
- [ ] T036 [US3] Agregar la acción `modoSoloLectura()` (PATCH) a `TiendanubeConfiguracionController` y el aviso visible/permanente en `_panel_estado.blade.php` mientras el modo esté activo
- [ ] T037 [US3] Agregar la acción `historial()` a `TiendanubeConfiguracionController` con DataTables server-side (`yajra/laravel-datatables-oracle`, ya en el proyecto), filtrable por fecha y resultado
- [ ] T038 [US3] Crear la tabla de historial en `resources/views/configuracion/tiendanube/index.blade.php` (o partial dedicado), reutilizando el patrón visual ya usado en la pantalla de Mercado Libre
- [ ] T039 [US3] Implementar la purga oportunista de `tn_operaciones_log` (30 días o 5.000 filas, lo que se alcance primero — research.md §R8), replicando el mismo mecanismo (sin tarea `Schedule` dedicada) ya usado para `ml_operaciones_log`

**Checkpoint**: modo sólo lectura y diagnóstico operativos — historia 3 completa.

---

## Phase 6: User Story 4 — Enterarse cuando la credencial deja de ser válida (Priority: P2)

**Goal**: detectar credencial inválida/revocada y marcar la conexión como "Caída", con la acción de
recargar el token visible.

**Independent Test**: invalidar el token guardado (o simular un 401/404 con `Http::fake()`) y verificar
que la siguiente operación marca la conexión como caída con la acción de recargar credenciales visible.

### Tests for User Story 4

- [ ] T040 [P] [US4] Test de que un 401/404 marca `estado = caida` con `ultimo_error` descriptivo, en `tests/Feature/Integraciones/TiendanubeManejoErroresTest.php`
- [ ] T041 [P] [US4] Test de que tras recargar un token válido, "Probar conexión" vuelve a dejar `estado = conectada` sin tener que recrear el resto de la configuración, en `tests/Feature/Integraciones/TiendanubeManejoErroresTest.php`
- [ ] T042 [P] [US4] Test de espera creciente ante 429 y de reintento acotado ante 5xx/fallas de conexión, sin marcar la conexión como caída en el caso de 5xx, en `tests/Feature/Integraciones/TiendanubeManejoErroresTest.php`

### Implementation for User Story 4

- [ ] T043 [US4] Completar en `ClienteTiendanube` el manejo de 401/404 (marca `estado = caida` + `ultimo_error`, deja de reintentar) según research.md §R5
- [ ] T044 [US4] Completar en `ClienteTiendanube` la espera creciente ante 429 (respetando `Retry-After` si el proveedor lo envía) y el reintento acotado ante 5xx/`ConnectionException`, sin marcar la conexión como caída en esos casos
- [ ] T045 [US4] Mostrar en `_panel_estado.blade.php` la acción destacada "Recargar token" cuando `estado = caida`, reutilizando el mismo formulario de credenciales de la historia 1

**Checkpoint**: las cuatro historias de usuario funcionan de punta a punta e independientemente.

---

## Phase 7: Polish & Cross-Cutting Concerns

- [ ] T046 [P] Revisar que ningún log de aplicación (`storage/logs/laravel.log`) ni respuesta JSON exponga `access_token` bajo ningún escenario de error (SC-005)
- [ ] T047 Ejecutar la Parte 1 de [quickstart.md](./quickstart.md) (suite completa `php artisan test --filter=Tiendanube`) y confirmar que pasa en verde
- [ ] T048 **Requiere una tienda de Tiendanube real o de prueba**: ejecutar la Parte 2 de [quickstart.md](./quickstart.md) de punta a punta (conexión real, regeneración de token, desconexión) y anotar en el propio `quickstart.md` los hallazgos marcados como "a verificar" en research.md §R1/§R3/§R5
- [ ] T049 **Depende de T048**: si se usó una tienda/token de prueba, anotarlo en `CREDENCIALES_ACCESO.txt` según la regla del `CLAUDE.md`
- [ ] T050 Revisar los 24 ítems de [checklists/security.md](./checklists/security.md) contra el resultado final de la implementación y cerrar los que correspondan

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: sin dependencias — documentación de dominio, bloqueante por Principio I
- **Foundational (Phase 2)**: depende de Phase 1 — bloquea TODAS las historias
- **User Story 1 (Phase 3, P1)**: depende de Foundational — MVP
- **User Story 2 (Phase 4, P1)**: depende de Foundational y de que existan credenciales que probar (Phase 3) — no tiene sentido probar conexión sin credenciales cargadas
- **User Story 3 (Phase 5, P2)**: depende de que `ClienteTiendanube` exista (Phase 4) — el kill-switch y el log se implementan sobre ese cliente
- **User Story 4 (Phase 6, P2)**: depende de `ClienteTiendanube` (Phase 4) — el manejo de errores se agrega al mismo cliente
- **Polish (Phase 7)**: depende de que las historias que se quieran validar estén completas

### Parallel Opportunities

- Todas las tareas [P] de la Fase 2 (T004-T008) pueden correr en paralelo (archivos distintos)
- T013-T014 (tests de US1) en paralelo entre sí
- T021-T024 (tests de US2) en paralelo entre sí
- T031-T033 (tests de US3) en paralelo entre sí
- T040-T042 (tests de US4) en paralelo entre sí

---

## Parallel Example: Foundational

```bash
Task: "Crear migración tn_configuracion en database/migrations/2026_08_05_060001_create_tn_configuracion_table.php"
Task: "Crear migración tn_operaciones_log en database/migrations/2026_08_05_060002_create_tn_operaciones_log_table.php"
Task: "Crear enum Tiendanube\\EstadoConexion en app/Enums/Tiendanube/EstadoConexion.php"
Task: "Crear modelo TiendanubeConfiguracion en app/Models/Integraciones/TiendanubeConfiguracion.php"
Task: "Crear modelo TiendanubeOperacionLog en app/Models/Integraciones/TiendanubeOperacionLog.php"
```

---

## Implementation Strategy

### MVP First (User Story 1 + 2)

1. Completar Phase 1: documentación de dominio
2. Completar Phase 2: Foundational (bloqueante)
3. Completar Phase 3: User Story 1 (activar función + cargar credenciales)
4. Completar Phase 4: User Story 2 (probar conexión + estado + desconectar)
5. **STOP y VALIDAR**: probar de punta a punta contra una tienda real (quickstart.md Parte 2) — ya
   posible en este punto, sin esperar a las historias 3 y 4
6. Deploy/demo si está listo

### Entrega incremental

1. Setup + Foundational → base lista
2. US1 + US2 → conexión verificable de punta a punta (MVP)
3. US3 → modo sólo lectura + historial (salvaguarda operativa)
4. US4 → manejo de credencial caída (robustez)
5. Cada historia agrega valor sin romper las anteriores

---

## Notes

- [P] = archivos distintos, sin dependencias pendientes
- La etiqueta [Story] mapea cada tarea a su historia de usuario para trazabilidad
- Verificar que los tests fallan antes de implementar
- Commit después de cada tarea o grupo lógico
- Esta spec es continuación directa de `011-mercadolibre-conexion-oauth` (mismo patrón de diseño) y
  antecesora directa de las specs 016 (ventas Tiendanube) y 017 (stock Tiendanube), que no se
  implementan acá
