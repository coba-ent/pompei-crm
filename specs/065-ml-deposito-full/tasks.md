# Tasks: Depósito para publicaciones y órdenes Full de Mercado Libre

**Feature**: 065-ml-deposito-full | **Branch**: `065-ml-deposito-full`
**Input**: [spec.md](./spec.md), [plan.md](./plan.md), [research.md](./research.md),
[data-model.md](./data-model.md), [contracts/](./contracts/), [quickstart.md](./quickstart.md)

> ⚠️ **Los tests NO son opcionales en esta feature.** El principio IV de la constitución exige tests
> —preferentemente antes de la implementación— para toda lógica que involucre **movimientos de
> stock**, que es exactamente el corazón de esta feature. Las tareas de test están marcadas 🧪 y
> preceden a su implementación.

> ✅ **Prerrequisito de docs ya cumplido**: `docs/modelo_datos.md` y
> `docs/documentacion_principal_crm.md` §3.2.ter.bis fueron actualizados antes de generar estas
> tareas (principio I de la constitución).

---

## Phase 1: Setup

- [X] T001 Crear migración `database/migrations/2026_08_1X_XXXXXX_add_logistica_full_mercadolibre.php` con las **4** columnas: en `ml_publicacion_producto` → `logistic_type` (string 40, nullable, indexada), `inventory_id` (string 40, nullable, indexada) y **`logistica_sincronizada_en` (datetime, nullable)**; en `ml_configuracion` → `deposito_full_id` (FK → `depositos`, nullable, `nullOnDelete`)
- [X] T002 Ejecutar `php artisan migrate` y confirmar que la suite existente de Mercado Libre sigue en verde (`php artisan test --filter=MercadoLibre`) — línea base de no-regresión antes de tocar nada

---

## Phase 2: Foundational — clasificación de logística

**Bloquea a TODAS las user stories**: sin `logistic_type` persistido no se puede distinguir Full de
logística propia en ningún punto del sistema.

- [X] T003 [P] Agregar a `app/Models/Integraciones/MercadoLibrePublicacionProducto.php` los campos nuevos en `$fillable`, el cast de `logistica_sincronizada_en` a datetime, el método `esFull(): bool` (compara contra `fulfillment`, **único** lugar que traduce el valor crudo) y los scopes `esFull()` / `noFull()`
- [X] T004 [P] Agregar a `app/Models/Integraciones/MercadoLibreConfiguracion.php` el `$fillable` de `deposito_full_id`, la relación `depositoFull()` y el método `depositoFullEfectivoONulo(): ?Deposito` — **sin fallback a `Deposito::porDefecto()`**, devuelve `null` si no está configurado o el depósito está inactivo (data-model §ml_configuracion)
- [X] T005 🧪 Escribir `tests/Feature/Integraciones/MercadoLibreLogisticaFullTest.php` cubriendo: persistencia de `logistic_type` e `inventory_id` desde el multiget; conservación del último valor conocido ante fallo de chunk (FR-004); `null` y valor desconocido tratados como no-Full (FR-005/FR-005a). Mockear con `Http::fake()` usando las respuestas reales de `contracts/api-mercadolibre.md`
- [X] T006 (FR-001) Extender `app/Services/MercadoLibre/SincronizadorTiposPublicacion.php::consultarYPersistir()` para persistir también `logistic_type` (de `shipping.logistic_type`) e `inventory_id` del mismo body, más `logistica_sincronizada_en`. **Sin agregar ninguna llamada nueva** a la API (research R8). Mantener la política de no pisar con `null` ante fallo
- [ ] T007 Correr el comando `mercadolibre:sincronizar-tipos-publicacion` en local contra datos de prueba y verificar que clasifica sin romper el `listing_type_id` existente (no-regresión de la spec 050). Confirma FR-002 (refresco periódico): la corrida diaria de ese comando es el mecanismo, no se agrega cron nuevo
- [X] T007a ⚠️ [Deuda preexistente — FR-003] `SincronizadorTiposPublicacion::sincronizarUno()` está definido pero **no se invoca desde ningún punto del repo**: es código muerto desde la spec 050. Como consecuencia, hoy una publicación recién vinculada **no se clasifica** hasta la corrida diaria. Cablearlo desde `VinculadorAutomatico` (y desde el alta de vínculo del controlador) para que `logistic_type` y `listing_type_id` se determinen al vincular
- [X] T007b 🧪 Test de FR-003: al crear un vínculo nuevo, `logistic_type` queda determinado sin esperar a la corrida diaria. Cubre también la regresión de `listing_type_id` que T007a arrastra

---

## Phase 3: User Story 1 — dejar de pisar el stock que gobierna Mercado Libre (P1) 🎯 MVP

**Objetivo**: que el CRM deje de enviar existencias a las publicaciones Full.

**Test independiente**: ejecutar una sincronización de stock con al menos una publicación Full
vinculada y confirmar que no salió ningún `PUT /items/{id}` hacia ella, mientras sí salió hacia las
de logística propia.

- [X] T008 🧪 [US1] Agregar a `MercadoLibreLogisticaFullTest.php` los casos de exclusión: una publicación Full no genera `PUT` (FR-006); no queda marcada con error ni contada como fallo (FR-007); su `stock_pendiente` queda limpio; una publicación de logística propia se sigue enviando **exactamente igual que hoy** (SC-007)
- [X] T009 [US1] Modificar `app/Services/MercadoLibre/SincronizadorStock.php::procesarVinculos()` para saltear los vínculos Full antes de calcular cantidad: incrementar contador `omitidos`, limpiar `stock_pendiente` (FR-007) y `continue`. **No tocar el camino de las publicaciones no-Full**
- [X] T010 [US1] Extender la firma de retorno de `sincronizar()` y `sincronizarTodos()` para incluir `omitidos`, y sumarlo a los mensajes de resultado y a `stock_ultima_sync_resultado` (FR-008). **Verificar que el mensaje sea idéntico al actual cuando `omitidos === 0`** (contracts §rutas-internas, SC-007)
> ℹ️ El test de regresión del ciclo (FR-013) **se movió a T021a**, en Phase 5: verifica una propiedad
> que se apoya en la validación `different` de T018, así que correrlo antes daría un falso verde.

---

## Phase 4: User Story 2 — ver qué publicaciones están en Full (P1)

**Objetivo**: hacer visible la clasificación, para que la exclusión de US1 no parezca un bug.

**Test independiente**: abrir Vinculaciones y confirmar badge FULL en las Full, etiqueta legible en
las demás, y que el filtro por tipo de logística acota el listado.

- [X] T012 [P] [US2] (FR-024) Agregar a `MercadoLibrePublicacionProducto` el accessor de etiqueta legible según la tabla de traducciones de `contracts/rutas-internas.md` (`fulfillment` → "Full", `xd_drop_off` → "Colecta", `self_service` → "Flex", `custom` → "A cargo del vendedor", `not_specified` → "Sin especificar", `null` → "Sin clasificar"). Un valor desconocido se muestra **tal cual** (FR-005a), no se descarta
- [X] T013 [US2] (FR-024/FR-025) Agregar en `app/Http/Controllers/Ingresos/MercadoLibreVinculacionController.php::datatable()` las columnas `logistic_type`, `logistica_etiqueta` y `es_full`, y el filtro server-side por `logistic_type` (incluyendo el valor `sin_clasificar` para `NULL`)
- [X] T014 [US2] Agregar en `resources/views/ingresos/mercadolibre/vinculaciones.blade.php` la columna de logística y el control de filtro (Select2, por ser select de datos dinámicos — regla obligatoria #5 del proyecto)
- [X] T015 [US2] Agregar en `resources/js/mercadolibre-vinculaciones.js` el render del badge destacado **FULL** y el envío del filtro al endpoint server-side, **sin recargar la página** (regla obligatoria #2)
- [X] T016 🧪 [US2] Test del endpoint `datatable`: devuelve las columnas nuevas y el filtro por tipo de logística acota correctamente, incluyendo `sin_clasificar`

---

## Phase 5: User Story 3 — configurar el depósito de Full (P2)

**Objetivo**: habilitador de US4 y US5. Por sí solo no cambia comportamiento.

**Test independiente**: elegir un depósito, guardar sin recarga, y confirmar que persiste al reabrir.

- [X] T017 🧪 [US3] Test de validación en `tests/Feature/Integraciones/`: guardar `deposito_full_id` igual a `deposito_id` devuelve **422** (FR-017); distinto guarda OK; vacío guarda OK (FR-016). Es la validación más importante de la feature
- [X] T018 [US3] (FR-017) Agregar en `app/Http/Requests/Integraciones/GuardarConfiguracionVentasMercadoLibreRequest.php` (usado por `MercadoLibreConfiguracionController@guardarVentas`, ruta `PATCH configuracion/mercadolibre/ventas`) la regla `'deposito_full_id' => ['nullable', 'exists:depositos,id', 'different:deposito_id']` con el **mensaje en español que explica el motivo** (texto exacto en `contracts/rutas-internas.md`)
- [X] T019 [US3] (FR-015) Agregar el selector "Depósito para publicaciones Full" con **Select2** (`width:'100%'`, `dropdownParent` = el modal, `.trigger('change.select2')` tras setear por código), replicando el patrón del `#ml-deposito-id` existente. ⚠️ **El selector está duplicado en DOS vistas** y hay que tocar **ambas**, o la pantalla queda inconsistente: `resources/views/configuracion/mercadolibre/index.blade.php` (~L133) y `resources/views/configuracion/mercadolibre/_tab.blade.php` (~L127). **Ojo**: la etiqueta del campo general dice "Usar el depósito por defecto" — el de Full **no lleva esa opción**, porque no tiene fallback (data-model §ml_configuracion)
- [X] T020 [US3] (FR-019) Guardado por AJAX con **Toastr** de éxito y render de errores 422 en el formulario, sin recargar la página (reglas obligatorias #2 y #3). Extender el controlador que sirve esas vistas para pasar el depósito Full efectivo, igual que hoy pasa `$depositoEfectivo` / `$depositoEfectivoMl`
- [X] T021 [US3] Agregar el aviso de configuración incompleta (FR-026): mostrar advertencia cuando existan vinculaciones Full y `deposito_full_id` sea `null` o apunte a un depósito inactivo, con el texto de `contracts/rutas-internas.md`. En las dos vistas de T019
- [X] T021a 🧪 [US3] (FR-013 · movida desde Phase 3) Test de regresión del ciclo, research R7: un ajuste de stock en el depósito Full **no** marca ningún vínculo como pendiente. Vive acá y no en Phase 3 porque la propiedad se apoya en la validación `different` de T018; correrla antes daría un falso verde

---

## Phase 6: User Story 4 — que el CRM sepa cuánto hay en el depósito de Mercado Libre (P2)

**Objetivo**: reflejar ML → CRM. **Es la parte con más riesgo de la feature**: escribe movimientos de
stock.

**Test independiente**: sincronizar y confirmar que la existencia del depósito Full coincide con la
informada por Mercado Libre.

- [X] T022 🧪 [US4] Escribir `tests/Feature/Integraciones/MercadoLibreStockFullTest.php` con los casos: reflejo básico deja la existencia igual a la de Mercado Libre (FR-009); sólo se computa lo vendible, no `not_available_quantity` (FR-009); **idempotencia** — segunda corrida no genera movimientos (FR-012); sólo cambia el depósito Full (FR-011); recorre todos los Full aunque no estén pendientes (FR-009a)
- [X] T023 🧪 [US4] Tests de los casos borde: deduplicación por `inventory_id` compartido (FR-009b); inventario compartido por **productos distintos** no se refleja y se reporta (FR-014c); vínculo con producto inexistente se saltea sin error (FR-014b); sin depósito configurado no refleja y avisa sin abortar (FR-014); **modo sólo lectura NO bloquea el reflejo** (FR-014a)
- [X] T024 🧪 [US4] Test del caso real de `quickstart.md` §Escenario 4: producto con publicación Full (4 u.) y publicación propia (3 u.) queda **4 en el depósito Full y 3 en el general** — nunca 7 juntas ni 4 en ambos
- [X] T025 [US4] Refactorizar `SincronizadorStock` separando los cortes previos: `verificarCortes()` (escritura, mantiene el corte por **modo sólo lectura**) y `verificarCortesLectura()` (función avanzada activa + cuenta conectada, **sin** ese corte) — research R6. No alterar el comportamiento del push
- [X] T026 [US4] Crear `app/Services/MercadoLibre/SincronizadorStockFull.php`: corta si no hay depósito Full activo (FR-014); toma los vínculos Full; agrupa por `inventory_id` distinto (FR-009b); consulta `GET /inventories/{id}/stock/fulfillment` por inventario; calcula el delta contra `StockService::disponibilidad(...)` y llama a `StockService::ajustar()` **sólo si difiere** (FR-012), con origen trazable (FR-010). **Nunca escribe hacia Mercado Libre** (FR-009c)
- [X] T027 [US4] Registrar las llamadas nuevas en `MercadoLibreOperacionLog` como operaciones de **lectura**, coherente con el resto de la integración
- [X] T028 [US4] Invocar `SincronizadorStockFull` desde el cron de stock (después del push) y desde `app/Jobs/SincronizacionForzadaMercadoLibre.php`, sumando su resultado al mensaje de estado en caché según `contracts/rutas-internas.md` §3

---

## Phase 7: User Story 5 — que la venta de una orden Full descuente del depósito correcto (P2)

**Objetivo**: corrección contable del stock de las Ventas.

**Test independiente**: convertir una orden Full y confirmar que el descuento impactó en el depósito
Full.

- [X] T029 🧪 [US5] Escribir `tests/Feature/Integraciones/MercadoLibreVentaFullDepositoTest.php`: orden íntegramente Full → depósito Full (FR-020); orden de logística propia → depósito general, sin cambios (FR-021); orden **mixta** → depósito general (FR-020a); sin depósito Full configurado → general y **la Venta se crea igual** (FR-022); orden de publicación no vinculada → general
- [X] T030 🧪 [US5] Test de que el depósito imputado a la Venta y el usado por el descuento de existencias son **el mismo** (FR-020b), verificando el `deposito_id` de la Venta contra el `deposito_id` del movimiento generado
- [X] T031 [US5] Agregar en `app/Services/MercadoLibre/ConversorOrdenAVenta.php` un método privado que resuelva el depósito: depósito Full **sólo si todas** las líneas mapean a vínculos Full **y** hay depósito Full activo; en cualquier otro caso `depositoEfectivo()`. Reemplazar el `depositoEfectivo()` fijo de la creación de la Venta por esa llamada
- [X] T032 [US5] Verificar que `StockDeVenta::aplicarAlta()` toma el depósito **de la Venta** (no recalcula), garantizando FR-020b sin duplicar la lógica de resolución
- [X] T033 [US5] Registrar el criterio de imputación aplicado en `MercadoLibreOperacionLog` para auditoría (FR-023), de modo que se pueda responder por qué una Venta descontó de un depósito y no del otro

---

## Phase 8: Polish & Cross-Cutting

- [X] T034 [P] Ejecutar la regresión completa `php artisan test --filter=MercadoLibre` y confirmar que las 260 publicaciones de logística propia mantienen comportamiento idéntico (SC-007)
- [ ] T035 [P] Recorrer el checklist de cierre de [quickstart.md](./quickstart.md) end-to-end en local, con el depósito `Mercado Libre Full` dado de alta a mano
- [X] T036 [P] Marcar como implementada la spec 065 en `docs/documentacion_principal_crm.md` §3.2.ter.bis y en `docs/modelo_datos.md` (hoy dicen "especificada")
- [X] T037 Verificar que no queda ninguna ruta de código capaz de escribir la existencia del centro de distribución de Mercado Libre (FR-009c) — revisión dirigida de todos los `PUT /items` del módulo. Confirmar además que ningún camino crea depósitos automáticamente (FR-018)
- [X] T038 Confirmar que `CREDENCIALES_ACCESO.txt` no requiere cambios (esta feature no crea ni modifica accesos)

---

## Notas de implementación (14/08/2026)

Desvíos respecto de lo planeado, con el motivo. Todo lo demás salió como estaba escrito.

| Tarea | Qué se hizo distinto y por qué |
|---|---|
| **T003** | El scope se llama **`soloFull()`**, no `esFull()`. Un `scopeEsFull` es inalcanzable: `Model::esFull()` resuelve primero al método de instancia y explota con *"cannot be called statically"*. El scope complementario sí quedó como `noFull()`. |
| **T007a** | **La premisa del plan era incorrecta**: `VinculadorAutomatico` —el **único** punto del repo que crea vínculos— ya persistía `listing_type_id` de su propio multiget, así que la spec 050 no tenía la deuda que se le atribuía. FR-003 se resolvió del mismo modo (leer `shipping.logistic_type` e `inventory_id` de **ese** body) en vez de cablear `sincronizarUno()`, que habría costado un `GET /items` extra por publicación para traer datos ya disponibles. `sincronizarUno()` sigue sin invocarse; quedó documentado como tal en el código. |
| **T019** | En vez de duplicar el selector en las dos vistas, se extrajo a `resources/views/configuracion/mercadolibre/_deposito_full.blade.php` e incluido desde ambas. Elimina de raíz el riesgo que la tarea advertía. |
| **T025** | `verificarCortesLectura()` vive en `SincronizadorStockFull`, no en `SincronizadorStock`. Son clases distintas (no se puede compartir un método privado) y tocar los cortes del push era exactamente el riesgo de regresión que el plan quería evitar sobre las publicaciones de logística propia. |
| **T032** | **No era una verificación, era un bug**: `StockDeVenta::resolverDeposito()` **recalculaba** `depositoEfectivo()` para las Ventas de Mercado Libre en vez de leer el de la Venta. Sin corregirlo, una orden Full quedaba imputada al depósito Full pero descontaba del general. Ahora toma el de la Venta, con fallback al configurado para las Ventas viejas sin `deposito_id`. |
| **T029** | El caso "orden de publicación **no vinculada**" no se puede testear como estaba escrito: la conversión rechaza esas órdenes por una regla previa a esta feature. Se cubrió el equivalente real: publicación vinculada pero **sin clasificar** (`logistic_type = null`), que arrastra la orden al depósito general. |

**Pendientes de validación manual** (requieren la cuenta real de Mercado Libre y un navegador,
no reproducibles en esta sesión):

- **T007** — corrida real de `mercadolibre:sincronizar-tipos-publicacion`. La lógica está cubierta
  por tests con `Http::fake()` sobre las respuestas reales de `contracts/api-mercadolibre.md`,
  incluida la no-regresión de `listing_type_id`.
- **T035** — checklist end-to-end de `quickstart.md` en el navegador.

**No-regresión medida** (T034): la suite completa quedó en **302 fallos / 1038 pasando**, contra un
baseline de **302 fallos / 994 pasando** sobre el árbol sin la feature — mismos fallos preexistentes,
ninguno nuevo, +44 tests. En `--filter=MercadoLibre`: 13 fallos preexistentes antes y después
(`MercadoLibreSincronizacionForzadaTest`, `…MensajeriaWebhookTest`, `…BotConfiguracionTest`,
`…SugerenciaTest`), ajenos a esta feature.

## Dependencias entre fases

```text
Phase 1 (Setup)
   └─> Phase 2 (Clasificación)  ← BLOQUEA TODO
          ├─> Phase 3 (US1 · P1) ──┐
          ├─> Phase 4 (US2 · P1)   │  US1 y US2 son independientes entre sí
          └─> Phase 5 (US3 · P2)   │
                 ├─> Phase 6 (US4 · P2)  ← necesita depósito Full configurado
                 └─> Phase 7 (US5 · P2)  ← necesita depósito Full configurado
                        └─> Phase 8 (Polish)
```

**Detalle**:

- **Phase 2 bloquea todo**: sin `logistic_type` persistido no hay forma de distinguir Full.
- **US1 y US2 son independientes** entre sí y ambas P1 — se pueden hacer en paralelo tras Phase 2.
- **US4 y US5 dependen de US3** (necesitan `deposito_full_id` configurable), pero **son independientes
  entre sí**: una refleja stock, la otra imputa Ventas.
- **El test del ciclo vive en T021a (Phase 5)**, no en Phase 3: verifica una propiedad que se apoya en
  la validación `different` de T018, así que correrlo antes daría un falso verde.
- **T007a/T007b (FR-003) son deuda preexistente**: `sincronizarUno()` nunca se invocó desde la spec
  050. No bloquean el MVP (Phases 1–3), pero sin ellas una publicación recién vinculada queda sin
  clasificar hasta la corrida diaria — y por lo tanto recibiría stock indebidamente si fuera Full.
  Conviene hacerlas junto con Phase 2.

## Oportunidades de paralelización

| Momento | Tareas en paralelo |
|---|---|
| Tras T002 | T003 y T004 (modelos distintos) |
| Tras Phase 2 | Toda la Phase 3 y toda la Phase 4 (US1 y US2, archivos disjuntos) |
| Tras Phase 5 | Phase 6 y Phase 7 (servicios distintos) |
| Phase 8 | T034, T035 y T036 |

## Estrategia de implementación

**MVP sugerido — Phases 1 + 2 + 3 (US1)**: detiene el daño activo. El CRM deja de mandarle stock a
Mercado Libre en las publicaciones Full, que es lo único incorrecto que hoy está ocurriendo de forma
silenciosa. No requiere que el usuario configure nada.

**Incremento 2 — Phase 4 (US2)**: hace visible la clasificación, para que la exclusión no parezca un
bug. Con esto la mitad P1 queda cerrada y entregable.

**Incremento 3 — Phases 5 + 6 + 7**: la parte de gestión (depósito Full, reflejo de stock e
imputación de Ventas). Aporta el valor contable, pero requiere que el usuario dé de alta y configure
el depósito.

> ⚠️ **Riesgo mayor de la feature**: Phase 6 escribe movimientos de stock reales. Sus tests (T022,
> T023, T024) se escriben **antes** que T026, sin excepción — principio IV de la constitución.
