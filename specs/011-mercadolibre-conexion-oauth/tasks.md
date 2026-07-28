# Tasks: Funciones Avanzadas + Conexión Mercado Libre (OAuth)

**Feature**: `011-mercadolibre-conexion-oauth` | **Fecha**: 2026-07-27

**Input**: [spec.md](./spec.md), [plan.md](./plan.md), [research.md](./research.md),
[data-model.md](./data-model.md), [contracts/](./contracts/), [quickstart.md](./quickstart.md)

## Format: `[ID] [P?] [Story] Descripción con ruta de archivo`

- **[P]**: paralelizable (archivos distintos, sin dependencias pendientes)
- **[US1..US5]**: historia de usuario a la que pertenece (sólo en fases de historia)

**Tests**: SÍ solicitados. La constitución (Principio IV) exige tests donde hay riesgo real; acá los
puntos de riesgo son la **concurrencia de renovación** (una conexión rota exige re-autorización manual)
y el **bloqueo de escrituras** (protege las publicaciones reales del negocio). Se suman los flujos de
seguridad de OAuth.

## Path Conventions

Proyecto Laravel 12 monolítico. Rutas relativas a la raíz del repositorio.

---

## Phase 1: Setup (Documentación de dominio — bloqueante por Principio I)

**⚠️ Bloqueante**: la constitución exige que la documentación de dominio esté actualizada **antes** de
escribir código.

- [X] T001 Actualizar `docs/documentacion_principal_crm.md` §5: agregar Depósitos, Funciones Avanzadas y Mercado Libre a la tabla del módulo Configuración & Ajustes
- [X] T002 Agregar `docs/documentacion_principal_crm.md` §5.1 (Funciones Avanzadas: tabla de las 10 funciones con su estado de construcción) y §5.2 (integración Mercado Libre, con la **divergencia deliberada respecto de Contagram** documentada y justificada)
- [X] T003 Agregar `docs/modelo_datos.md` §8 con las 5 entidades nuevas, y renumerar la sección de tablas descartadas a §9 aclarando que `integraciones`/`integracion_eventos`/`ml_ordenes` no se retoman

> **T001-T003 ya ejecutadas** durante `/speckit-plan`, en cumplimiento de la regla del `CLAUDE.md` de
> actualizar los docs antes de `/speckit-tasks`. Se dejan registradas para trazabilidad.

---

## Phase 2: Foundational (Prerrequisitos bloqueantes de todas las historias)

**⚠️ Ninguna historia puede empezar hasta que esta fase esté completa.**

### Migraciones

- [X] T004 [P] Crear migración `database/migrations/2026_08_01_060001_create_funciones_avanzadas_table.php` con las columnas de data-model.md §1 (unique en `clave`, índice en `orden`, FK `actualizada_por` → `users`)
- [X] T005 [P] Crear migración `database/migrations/2026_08_01_060002_create_ml_configuracion_table.php` con las columnas de data-model.md §2 (`client_secret` como `text` para alojar el valor cifrado)
- [X] T006 [P] Crear migración `database/migrations/2026_08_01_060003_create_ml_cuentas_table.php` con las columnas de data-model.md §3 (unique en `ml_user_id`, índice en `estado`, tokens como `text`, más `pendiente_expira_en` nullable para el estado intermedio de confirmación)
- [X] T007 [P] Crear migración `database/migrations/2026_08_01_060004_create_ml_solicitudes_vinculacion_table.php` con las columnas de data-model.md §4 (unique en `state`, índice en `expira_en`)
- [X] T008 [P] Crear migración `database/migrations/2026_08_01_060005_create_ml_operaciones_log_table.php` con las columnas de data-model.md §5 (índices en `created_at`, `resultado`, `operacion`; **sin** `updated_at`)

### Enum y modelos

- [X] T009 [P] Crear `app/Enums/MercadoLibre/EstadoConexion.php` (enum respaldado por string: `no_configurada`, `desconectada`, `conectada`, `pendiente_confirmacion`, `caida`) con método `etiqueta()` y `color()`. **Documentar en el propio enum que `no_configurada` es un valor derivado** (se calcula cuando `ml_configuracion` está incompleta) y nunca se persiste en la columna `estado`
- [X] T010 [P] Crear `app/Models/FuncionAvanzada.php` con `$fillable`, cast de `activa`/`disponible` a boolean, relación `actualizadaPor()`, y scope `ordenadas()`
- [X] T011 [P] Crear `app/Models/Integraciones/MercadoLibreConfiguracion.php` con cast `encrypted` en `client_secret`, `$hidden`, método estático `actual()` que devuelve la fila 1 creándola vacía si no existe, y accessor `estaCompleta()`
- [X] T012 [P] Crear `app/Models/Integraciones/MercadoLibreCuenta.php` con casts `encrypted` en `access_token`/`refresh_token`, `$hidden` en ambos, cast de `estado` al enum, casts `datetime`, método `tokenVencido($margenMinutos = 10)`, scopes `conectada()` y `pendienteConfirmacion()`, y método estático `descartarPendientesVencidas()` (elimina las retenidas con `pendiente_expira_en` pasado, junto con sus tokens)
- [X] T013 [P] Crear `app/Models/Integraciones/MercadoLibreSolicitudVinculacion.php` con método estático `emitir(User $usuario, string $ip)` (genera `state` de 40 caracteres, vencimiento +10 min y depura las vencidas de forma oportunista) y método `consumir()`
- [X] T014 [P] Crear `app/Models/Integraciones/MercadoLibreOperacionLog.php` con `$timestamps = false` salvo `created_at`, y método estático `registrar(array $datos)` que **sanea antes de persistir** (elimina `access_token`, `refresh_token`, `client_secret`, header `Authorization`) y dispara la depuración por retención de forma probabilística (~1 de cada 50)

### Seeder y permiso

- [X] T015 Crear `database/seeders/FuncionAvanzadaSeeder.php` con las 10 funciones de data-model.md §1 usando `updateOrCreate` por `clave` (idempotente: **no** debe pisar el estado `activa` que el usuario ya eligió)
- [X] T016 Registrar `FuncionAvanzadaSeeder` en `database/seeders/DatabaseSeeder.php`
- [X] T017 Verificar que el permiso `configuracion.funciones` exista en `database/seeders/PermisoSeeder.php` (ya presente en la línea 89, "Administrar Funciones Avanzadas") — **no crear permisos nuevos**, sólo confirmar

**Checkpoint**: `php artisan migrate --seed` corre limpio y las 10 funciones quedan sembradas.

---

## Phase 3: User Story 1 — Ver y administrar las Funciones Avanzadas (P1) 🎯 MVP

**Objetivo**: pantalla con las 10 tarjetas y toggles persistidos.

**Prueba independiente**: entrar a la pantalla, alternar una función disponible, verificar que persiste
al recargar. No requiere nada de Mercado Libre.

### Tests

- [X] T018 [P] [US1] Crear `tests/Feature/Integraciones/FuncionesAvanzadasTest.php` cubriendo: la pantalla lista las 10 funciones en el orden relevado; el toggle persiste; **activar una función con `disponible = false` devuelve 422** (validación en servidor, no sólo en la interfaz); un usuario sin `configuracion.funciones` recibe 403; se registran `actualizada_por` y `actualizada_en`; **desactivar `mercadolibre` con una cuenta conectada exige confirmación y NO desconecta la cuenta** (FR-005a)

### Implementación

- [X] T019 [US1] Crear `app/Http/Controllers/Configuracion/FuncionAvanzadaController.php` con `index()` (devuelve la vista con las funciones ordenadas) y `estado(Request, FuncionAvanzada)` (valida `disponible` antes de permitir la activación, **persiste `actualizada_por` y `actualizada_en`** — FR-008, devuelve JSON según contracts/rutas-internas.md)
- [X] T019a [US1] Implementar en `estado()` la confirmación de FR-005a: al desactivar `mercadolibre` con una cuenta en estado `conectada`, exigir un parámetro `confirmado: true`; sin él, devolver 409 con el mensaje que explica que se suspenderán las operaciones y que **la vinculación se conserva**. La desactivación **nunca** borra credenciales
- [X] T020 [US1] Agregar el grupo de rutas en `routes/web.php` bajo `prefix('configuracion')` → `funciones` con middleware `permiso:configuracion.funciones` (`index` y `estado`), siguiendo el patrón del grupo de depósitos existente
- [X] T021 [US1] Crear `resources/views/configuracion/funciones.blade.php` extendiendo `layouts.default`: lista vertical de tarjetas con ícono, nombre, descripción y toggle; las no disponibles atenuadas con etiqueta "Próximamente" y control deshabilitado; las que tienen `ruta_configuracion` muestran un botón de acceso a su configuración
- [X] T022 [US1] Crear `resources/js/funciones-avanzadas.js`: envío del toggle por AJAX, toast de NexaDash con el resultado, reversión visual del control si el servidor rechaza. **Sin recarga de página** (SC-009). Ante un 409 de confirmación requerida (T019a), abrir un modal de confirmación y reenviar con `confirmado: true` si el usuario acepta
- [X] T023 [US1] Registrar el JS en `config/dz.php` (pagelevel de la vista) siguiendo el patrón de los módulos existentes
- [X] T024 [US1] Agregar la entrada "Funciones Avanzadas" al submenú "Configuración & Ajustes" en `resources/views/elements/sidebar.blade.php`, protegida con `@can('configuracion.funciones')`

**Checkpoint**: US1 entregable y demostrable de forma autónoma. **Este es el MVP.**

---

## Phase 4: User Story 2 — Configurar credenciales de la aplicación (P1)

**Objetivo**: cargar App ID, clave secreta y sitio; mostrar la Redirect URI copiable.

**Prueba independiente**: guardar credenciales, verificar que persisten cifradas y que el secreto nunca
vuelve a la interfaz.

### Tests

- [X] T025 [P] [US2] Crear `tests/Feature/Integraciones/MercadoLibreConfiguracionTest.php` cubriendo: guardado válido; **`client_secret` se persiste cifrado y NUNCA aparece en la respuesta** (SC-007); el secreto vacío al editar conserva el guardado; validaciones por campo devuelven 422; cambiar credenciales con cuenta vinculada devuelve `requiere_revinculacion: true` y marca la cuenta como `caida`

### Implementación

- [X] T026 [P] [US2] Crear `app/Http/Requests/Integraciones/GuardarConfiguracionMercadoLibreRequest.php` con las reglas de contracts/rutas-internas.md (`client_id` numérico 8-32 dígitos; `client_secret` requerido en alta y opcional en edición; `site_id` en la lista de sitios soportados) y mensajes en español
- [X] T027 [US2] Crear `app/Http/Controllers/Integraciones/MercadoLibreConfiguracionController.php` con `index()` y `guardar(GuardarConfiguracionMercadoLibreRequest)`: persiste, detecta el cambio de credenciales con cuenta vinculada y en ese caso la marca `caida` devolviendo la advertencia
- [X] T028 [US2] Agregar `estado()` al mismo controlador: devuelve el JSON de estado de contracts/rutas-internas.md. **Proyección explícita** — `secret_cargado` como booleano, nunca el valor; incluye la `redirect_uri` calculada con `route()`
- [X] T028a [US2] Implementar las **advertencias de entorno** (FR-011a) en `estado()`: comparar el host de la `redirect_uri` generada contra el host real de la petición y verificar que use conexión segura; devolver el array `advertencias` de contracts/rutas-internas.md con mensajes accionables que nombren `APP_URL`. Ataca el fallo más común de esta integración: `APP_URL` mal configurada hace que la URI mostrada difiera de la usada, y la vinculación falla con un error poco claro del proveedor
- [X] T029 [US2] Agregar las rutas de configuración de Mercado Libre en `routes/web.php` bajo `prefix('configuracion')` → `mercadolibre`, con middleware `permiso:configuracion.funciones`
- [X] T030 [US2] Crear `resources/views/configuracion/mercadolibre/index.blade.php` extendiendo `layouts.default`: panel de estado, bloque de credenciales, campo de Redirect URI en sólo lectura con botón de copiar, bloque de advertencias de entorno (T028a) y contenedor del historial
- [X] T031 [P] [US2] Crear `resources/views/configuracion/mercadolibre/_modal_credenciales.blade.php`: modal Bootstrap con App ID, clave secreta (con indicador "ya cargada" cuando corresponda) y sitio mediante **Select2** con `dropdownParent` apuntando al modal
- [X] T032 [US2] Crear `resources/js/mercadolibre.js` (base): envío del formulario de credenciales por AJAX, pintado de errores por campo sin perder lo escrito, toasts, copiado de la Redirect URI al portapapeles
- [X] T033 [US2] Registrar el JS de Mercado Libre en `config/dz.php`

**Checkpoint**: se pueden cargar credenciales y la pantalla muestra "Desconectada".

---

## Phase 5: User Story 3 — Vincular la cuenta y ver el estado (P1)

**Objetivo**: flujo OAuth completo y panel de estado con datos reales de la cuenta.

**Prueba independiente**: vincular con un usuario de prueba de Mercado Libre y ver los datos reales.

### Tests

- [X] T034 [P] [US3] Crear `tests/Feature/Integraciones/MercadoLibreOAuthTest.php` con `Http::fake()` cubriendo: canje exitoso persiste tokens cifrados y datos de cuenta; **`state` inexistente / vencido / ya consumido → rechazo** (FR-016); cuenta de sitio distinto al configurado → rechazo **sin persistir nada** (FR-019); `error=access_denied` → mensaje sin datos parciales; **retorno repetido es idempotente y no rompe la conexión** (FR-021)
- [X] T034a [P] [US3] Crear `tests/Feature/Integraciones/MercadoLibreReemplazoCuentaTest.php` cubriendo FR-022: autorizar con la **misma** cuenta vigente actualiza tokens sin pedir confirmación; autorizar con una cuenta **distinta** crea una fila `pendiente_confirmacion` **sin tocar la vigente**, que sigue operando; confirmar activa la nueva y desconecta la anterior **en una sola transacción** (nunca dos `conectada` a la vez); descartar elimina la pendiente dejando la vigente intacta; una pendiente vencida se descarta y confirmarla devuelve 409

### Implementación

- [X] T035 [P] [US3] Crear `app/Services/MercadoLibre/RespuestaMercadoLibre.php`: objeto de resultado con `exito`, `bloqueada`, `datos`, `codigoHttp`, `mensajeError`, y helpers `fueBloqueada()` / `fallo()`
- [X] T036 [P] [US3] Crear las excepciones en `app/Services/MercadoLibre/Excepciones/`: `ConexionCaidaException`, `CredencialesIlegiblesException`, `EscrituraBloqueadaException`
- [X] T037 [US3] Crear `app/Services/MercadoLibre/ClienteMercadoLibre.php` — **núcleo del módulo**. Método `peticion(string $metodo, string $endpoint, array $opciones)` que resuelve en un punto único: (a) asegurar token vigente delegando en la renovación, (b) kill-switch de escrituras, (c) ejecución con timeouts 10s/30s, (d) política de reintentos de contracts/api-mercadolibre.md distinguiendo 401 de 403, (e) registro en el historial. Métodos de conveniencia `obtener()` y `enviar()`
- [X] T038 [US3] Implementar en `ClienteMercadoLibre` la renovación perezosa: si `tokenVencido(10)`, tomar `Cache::lock('ml:refresh', 15)->block(20, ...)`, **releer la cuenta desde la base dentro del lock** antes de decidir si todavía hace falta renovar, ejecutar la renovación y **persistir el nuevo `refresh_token` pisando el anterior** (FR-029, FR-030). ⚠️ Calcular `token_expira_en` como `now() + expires_in` **de la respuesta**, nunca con una constante — la documentación del proveedor es contradictoria (declara 6 h, los ejemplos muestran 3 h) y hardcodear produce fallos intermitentes (FR-028a)
- [X] T039 [US3] Implementar el manejo de fallo irrecuperable de renovación: ante `invalid_grant`, marcar la cuenta como `caida` con `ultimo_error` legible y **dejar de reintentar** (FR-031). Capturar `DecryptException` y traducirla a `CredencialesIlegiblesException` → estado `caida` con mensaje comprensible
- [X] T040 [US3] Crear `app/Services/MercadoLibre/VinculacionMercadoLibre.php` con: `urlAutorizacion()` (deriva el dominio del sitio configurado mediante el mapa de R2, emite la solicitud y arma la URL; **rechaza si la configuración está incompleta nombrando el dato faltante** — FR-013), `canjearCodigo(string $code, string $state)` (valida la solicitud, la marca consumida **antes** de canjear, canjea, obtiene `/users/me`, **valida el sitio**, y deriva a la resolución de cuenta de T040a) y `desconectar()`
- [X] T040a [US3] Implementar en `VinculacionMercadoLibre` la resolución de cuenta tras el canje (**FR-022**), con tres caminos: (a) **sin cuenta previa** → persiste como `conectada`; (b) **mismo `ml_user_id` que la vigente** → actualiza tokens de la fila existente, sin pedir confirmación (no hay reemplazo); (c) **`ml_user_id` distinto** → descarta cualquier pendiente anterior, crea una fila `pendiente_confirmacion` con los tokens y `pendiente_expira_en = now()+15min`, y **deja la cuenta vigente intacta y operativa**
- [X] T040b [US3] Implementar `confirmarReemplazo()` y `descartarPendiente()` en `VinculacionMercadoLibre`. La confirmación es **atómica** (`DB::transaction`): activa la pendiente como `conectada` y pasa la anterior a `desconectada` limpiando sus credenciales, garantizando que nunca existan dos filas `conectada`. Registra el cambio de cuenta en el historial. Si la pendiente venció, devuelve error sin aplicar nada
- [X] T041 [US3] Crear `app/Http/Controllers/Integraciones/MercadoLibreOAuthController.php` con `conectar()` (redirige a Mercado Libre; si la configuración está incompleta vuelve con un mensaje que **nombra el dato faltante** — FR-013) y `callback(Request)` (siempre redirige a la pantalla con un mensaje, nunca renderiza vista propia; cuando el canje deja una pendiente de confirmación, redirige con la señal para abrir el modal de reemplazo)
- [X] T041a [US3] Agregar al `MercadoLibreConfiguracionController` los endpoints `pendiente()`, `confirmarReemplazo()` y `descartarPendiente()` según contracts/rutas-internas.md, delegando en T040b
- [X] T042 [US3] Implementar en el controlador OAuth la **traducción de errores del proveedor** a mensajes accionables en español (SC-006), cubriendo los códigos verificados en contracts/api-mercadolibre.md: `invalid_client`, `invalid_grant`, `invalid_scope`, `invalid_request`, `unauthorized_client`, **`invalid_operator_user_id`** ("autorizá con la cuenta principal, no con un colaborador") y **`unauthorized_application`** ("la aplicación está bloqueada por Mercado Libre"). El usuario nunca ve el error crudo
- [X] T042a [US3] Agregar a la pantalla de configuración el bloque informativo de **permisos funcionales** (FR-015a): qué habilitar en el DevCenter según la etapa, con la advertencia de que el CRM no puede otorgárselos a sí mismo. Incluir el aviso de FR-015b sobre autorizar con la cuenta principal
- [X] T042b [US3] Implementar el manejo del 403 por permiso funcional faltante (`PA_UNAUTHORIZED_RESULT_FROM_POLICIES`): mensaje que nombre el permiso a habilitar en el DevCenter, **sin** disparar renovación de token
- [X] T043 [US3] Agregar `probar()` y `desconectar()` al `MercadoLibreConfiguracionController`: la prueba ejecuta `GET /users/me` real e informa por toast; la desconexión limpia credenciales **conservando** datos de cuenta e historial (FR-027)
- [X] T044 [US3] Agregar las rutas `conectar`, `callback`, `probar`, `desconectar`, `pendiente`, `pendiente/confirmar` y `pendiente` (DELETE) en `routes/web.php`
- [X] T045 [P] [US3] Crear `resources/views/configuracion/mercadolibre/_panel_estado.blade.php`: badge de estado con su color, datos de la cuenta cuando está conectada, aviso destacado con acción de re-vinculación cuando está `caida`
- [X] T046 [P] [US3] Crear `resources/views/configuracion/mercadolibre/_modal_desconectar.blade.php`: modal de confirmación explicando qué se conserva y qué se borra
- [X] T046a [P] [US3] Crear `resources/views/configuracion/mercadolibre/_modal_reemplazo_cuenta.blade.php` (**FR-022**): modal que muestra lado a lado la cuenta vigente y la recién autorizada (apodo, identificador, correo), advierte que confirmar sustituye la conexión actual, e indica hasta cuándo es válida la autorización retenida. Dos acciones: Confirmar reemplazo / Descartar
- [X] T047 [US3] Extender `resources/js/mercadolibre.js`: acciones de probar y desconectar por AJAX, refresco del panel de estado sin recargar, y apertura del modal de reemplazo cuando el retorno del callback señala una autorización pendiente, cableando sus dos acciones contra los endpoints de T041a

### Tests de renovación (riesgo crítico)

- [X] T048 [US3] Crear `tests/Feature/Integraciones/MercadoLibreRenovacionTokenTest.php` cubriendo: token vencido se renueva de forma transparente antes de operar; el nuevo `refresh_token` reemplaza al anterior; **SC-004 — 10 intentos concurrentes con el token vencido producen exactamente UNA renovación** y ninguna operación falla por credencial inválida; renovación irrecuperable → estado `caida` sin reintentos posteriores
- [X] T049 [P] [US3] Crear `tests/Feature/Integraciones/MercadoLibreManejoErroresTest.php` cubriendo la política de contracts/api-mercadolibre.md: 401 renueva y reintenta una vez; **403 NO dispara renovación** (es falta de permisos, no credencial vencida); 429 aplica espera creciente hasta 3 intentos; 5xx reintenta sin marcar la conexión como caída

**Checkpoint**: vinculación de punta a punta funcional. Es el hito que el usuario definió como condición
para avanzar con el resto del módulo.

---

## Phase 6: User Story 4 — Modo sólo lectura y diagnóstico (P2)

**Objetivo**: kill-switch de escrituras e historial consultable.

**Prueba independiente**: activar el modo y verificar que una escritura queda registrada como bloqueada
en lugar de ejecutarse.

### Tests

- [X] T050 [P] [US4] Crear `tests/Feature/Integraciones/MercadoLibreModoSoloLecturaTest.php` cubriendo: **SC-005 — con el modo activo, ninguna escritura alcanza a Mercado Libre** (verificado con `Http::fake()` sin peticiones registradas), queda una fila con `resultado = bloqueada` y `payload_bloqueado`, y el llamador recibe una respuesta que lo indica; las lecturas siguen funcionando; el cambio del interruptor tiene efecto inmediato sin reiniciar

### Implementación

- [X] T051 [US4] Verificar que el kill-switch de T037 esté aplicado en **un único punto** (el método que ejecuta la petición) y no disperso por los servicios llamadores — es lo que hace garantizable SC-005 (R7)
- [X] T051a [US4] Implementar en el mismo punto único el guard de **FR-005b**: si la función avanzada `mercadolibre` está desactivada, rechazar toda operación (lectura y escritura) registrándola en el historial como bloqueada, **sin alterar el estado de la conexión**. Excepción: las operaciones del propio flujo de vinculación y de prueba de conexión, que deben poder ejecutarse para reconfigurar
- [X] T052 [US4] Agregar `modoSoloLectura()` al `MercadoLibreConfiguracionController` y su ruta en `routes/web.php`
- [X] T053 [US4] Agregar `operaciones()` al mismo controlador: historial en formato DataTables **server-side** con yajra, filtrable por `desde`, `hasta` y `resultado`
- [X] T054 [US4] Agregar a `resources/views/configuracion/mercadolibre/index.blade.php` la tabla del historial con DataTables por AJAX y sus controles de filtro, más el aviso permanente y visible cuando el modo sólo lectura está activo (FR-038)
- [X] T055 [US4] Extender `resources/js/mercadolibre.js`: inicialización del DataTable del historial (server-side), filtros, y toggle del modo sólo lectura por AJAX con toast
- [X] T056 [US4] Implementar la depuración por retención en `MercadoLibreOperacionLog` (30 días o 5.000 registros, oportunista y probabilística) y su test de que no borra registros dentro de la ventana

**Checkpoint**: el módulo es seguro de apuntar contra la cuenta real y diagnosticable.

---

## Phase 7: Cierre de User Story 5 — visibilidad del ciclo de vida en la interfaz (P2)

**Objetivo**: completar US5 de cara al usuario.

> **Nota de alcance**: la mecánica de renovación de US5 no vive en esta fase — se implementa en
> T038-T039 y se testea en T048, porque es inseparable del cliente HTTP. Esta fase cubre únicamente lo
> que falta para que la historia sea completa desde la interfaz. No es una historia pendiente entera.

- [X] T057 [US5] Ajustar el panel de estado para mostrar `token_expira_en` y `ultimo_refresh_en` en formato legible, con indicación de cuánto falta para el vencimiento (FR-024)
- [X] T058 [US5] Implementar el aviso destacado y persistente en la pantalla cuando el estado es `caida`, con el motivo (`ultimo_error`) y el botón de re-vinculación como acción principal (FR-031)
- [X] T059 [P] [US5] Agregar al test de renovación el escenario verificable de SC-003: una cuenta vinculada con el token vencido opera sin intervención, y **sobrevive a 3 renovaciones consecutivas** manteniendo la cadena de `refresh_token` intacta (es la parte de SC-003 comprobable de forma automatizada; los 7 días corridos se verifican de forma manual diferida según quickstart.md)

**Checkpoint**: las 5 historias completas.

---

## Phase 8: Polish & validación final

- [X] T060 [P] Verificar en toda la superficie que ningún endpoint devuelve `client_secret`, `access_token` ni `refresh_token` — revisión manual de cada respuesta JSON del controlador más un test que lo afirme (SC-007)
- [X] T061 [P] Verificar que ninguna vista dispare recarga de página en ninguna acción (SC-009)
- [X] T062 Ejecutar `php artisan test --filter=Integraciones` con `CACHE_STORE=database` y confirmar que pasa completa — es el perfil de hosting compartido y valida la portabilidad (SC-010)
- [X] T063 Ejecutar la Parte 1 de [quickstart.md](./quickstart.md) (validación local) y registrar el resultado
- [ ] T064 **PENDIENTE — requiere entorno publicado**: ejecutar la Parte 2 de [quickstart.md](./quickstart.md) (validación con usuarios de prueba reales de Mercado Libre), incluyendo los 5 escenarios de error y la renovación forzada. No ejecutable desde este entorno de desarrollo local (el flujo OAuth exige dirección pública con conexión segura — ver plan.md "Target Platform")
- [ ] T065 **PENDIENTE — depende de T064**: anotar en `CREDENCIALES_ACCESO.txt` los usuarios de prueba de Mercado Libre creados (nickname, email, contraseña), según la regla del `CLAUDE.md`
- [X] T066 Revisar que `docs/documentacion_principal_crm.md` §5.2 y `docs/modelo_datos.md` §8 sigan coincidiendo con lo efectivamente implementado; corregir cualquier desvío surgido durante la implementación — se encontró y corrigió una omisión: faltaba el estado `pendiente_confirmacion` y la columna `pendiente_expira_en` (FR-022) en ambos documentos
- [ ] T067 [P] **PENDIENTE — depende de T064**: verificar el flujo de reemplazo de cuenta (FR-022) de punta a punta en el entorno publicado con los **dos** usuarios de prueba: vincular con el vendedor, luego autorizar con el comprador, comprobar que se pide confirmación y que la cuenta vigente sigue operando mientras tanto

---

## Dependencies & Execution Order

### Dependencias entre fases

```
Phase 1 (docs) ─── ya completada
      ↓
Phase 2 (persistencia) ─── BLOQUEANTE para todo
      ↓
      ├─→ Phase 3 (US1: Funciones Avanzadas) ─── independiente 🎯 MVP
      │
      └─→ Phase 4 (US2: credenciales)
                ↓
          Phase 5 (US3: OAuth + cliente) ─── núcleo
                ↓
                ├─→ Phase 6 (US4: sólo lectura + historial)
                └─→ Phase 7 (US5: ciclo de vida)
                          ↓
                    Phase 8 (validación final)
```

### Dependencias entre historias

- **US1** (Funciones Avanzadas): independiente de todo lo de Mercado Libre. Se puede entregar sola.
- **US2** (credenciales): requiere sólo la persistencia.
- **US3** (OAuth): requiere US2 — sin credenciales no hay vinculación posible.
- **US4** (sólo lectura + historial): requiere US3 — el kill-switch vive dentro del cliente HTTP.
- **US5** (ciclo de vida): requiere US3 — la renovación se implementa junto al cliente.

### Oportunidades de paralelización

**Fase 2** — las 5 migraciones (T004-T008) y los 5 modelos + enum (T009-T014) son archivos distintos y
van todos en paralelo. Es el mayor bloque paralelizable del plan.

**Fase 3 vs Fase 4** — US1 y US2 no se tocan: si hay dos personas, una hace la pantalla de Funciones
Avanzadas mientras la otra arranca configuración.

**Dentro de la Fase 5** — T035 (objeto de resultado), T036 (excepciones), T045 y T046 (partials) son
paralelizables. T037-T039 **no**: son el mismo archivo y el orden importa.

**Tests** — todos los archivos de test son independientes entre sí y paralelizables.

### Ejemplo de ejecución paralela (Fase 2)

```
En paralelo: T004, T005, T006, T007, T008   (migraciones)
Luego en paralelo: T009, T010, T011, T012, T013, T014   (enum + modelos)
Luego secuencial: T015 → T016 → T017   (seeder y verificación de permiso)
```

---

## Implementation Strategy

### MVP primero (US1 sola)

Fase 1 + Fase 2 + Fase 3 = pantalla de Funciones Avanzadas funcionando. Entregable, demostrable y con
valor propio: es una pantalla que el CRM le debe a Contagram independientemente de Mercado Libre.

### Entrega incremental

1. **US1** → pantalla de Funciones Avanzadas → demostrable
2. **US2** → se pueden cargar credenciales → estado "Desconectada" visible
3. **US3** → **vinculación real funcionando** → *este es el hito que el usuario pidió alcanzar antes de seguir diseñando el resto del módulo*
4. **US4** → seguro para apuntar a la cuenta real
5. **US5** → conexión sostenible en el tiempo

### Punto de control con el usuario

Al terminar la **Fase 5 (US3)** conviene frenar y validar de punta a punta contra Mercado Libre real
antes de seguir. Es explícitamente lo que pidió el usuario: *"primero me gustaría que diseñemos esto,
nos aseguremos de que estemos conectados, que recibamos los datos, etcétera, y después seguimos"*.

### Riesgos a vigilar durante la implementación

| Riesgo | Dónde se manifiesta | Mitigación |
|---|---|---|
| Renovación concurrente rompe la conexión | T038 | Lock atómico + T048 (SC-004) |
| Kill-switch disperso deja agujeros | T037, T051 | Punto único de control + T050 (SC-005) |
| 403 tratado como token vencido | T037 | Distinción explícita + T049 |
| Secretos filtrados en respuestas | T028, T060 | Proyecciones explícitas + test |
| Redirect URI mal registrada | T042, T064 | Traducción del error + campo copiable |
| `APP_URL` mal configurada → URI mostrada ≠ URI usada | T028a | Advertencia de entorno en pantalla |
| Reemplazo accidental de la cuenta del negocio | T040a, T040b | Estado intermedio + confirmación explícita + T034a |
| Dos cuentas `conectada` simultáneas | T040b | Confirmación atómica en transacción + T034a |

---

## Notes

- **Total: 77 tareas** (3 ya completadas), distribuidas en 8 fases, cubriendo 49 requisitos funcionales.
- El contrato con la API fue **verificado contra la documentación oficial** de Mercado Libre (27/07/2026)
  vía el servidor MCP del proveedor. Las correcciones que surgieron de esa verificación están marcadas
  con ⚠️ en `contracts/api-mercadolibre.md` y `research.md` (R13, R14).
- Los tests están incluidos por exigencia del Principio IV de la constitución aplicado a los puntos de
  riesgo reales de esta feature (pérdida de servicio y daño sobre datos del negocio), no por TDD dogmático.
- El flujo OAuth **no puede probarse en local**: T064 requiere el entorno publicado con conexión segura.
  Todo lo demás se valida con `Http::fake()`.
- Ninguna tarea introduce dependencias nuevas en `composer.json` ni en `package.json`.
