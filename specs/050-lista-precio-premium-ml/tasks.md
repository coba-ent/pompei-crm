---
description: "Task list for feature 050-lista-precio-premium-ml"
---

# Tasks: Lista de Precios diferenciada para publicaciones Premium de Mercado Libre

**Input**: Design documents from `/specs/050-lista-precio-premium-ml/`

**Prerequisites**: [plan.md](plan.md), [spec.md](spec.md), [research.md](research.md), [data-model.md](data-model.md), [contracts/rutas-internas.md](contracts/rutas-internas.md), [quickstart.md](quickstart.md)

**Tests**: Incluidos — principio IV de la constitución (dinero/precio) exige tests para esta lógica.

**Organization**: Tareas agrupadas por Historia de Usuario para permitir implementación y prueba independiente de cada una.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Puede correr en paralelo (archivos distintos, sin dependencias)
- **[Story]**: A qué historia de usuario pertenece (US1, US2, US3)
- Cada tarea incluye la ruta exacta de archivo

---

## Phase 1: Setup

No aplica — se extiende la integración de Mercado Libre ya existente (specs 011/012/013/016/036), sin
inicialización de proyecto nueva.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Esquema de datos y modelos base que TODAS las historias necesitan.

**⚠️ CRITICAL**: Ninguna historia puede implementarse hasta completar esta fase.

- [X] T001 Crear migración `database/migrations/2026_08_06_070001_add_listing_type_lista_premium_mercadolibre.php`: agrega `lista_precio_id_premium` (FK nullable → `listas_precio`) y `tipo_publicacion_ultima_sync_en` (datetime nullable) a `ml_configuracion`; agrega `listing_type_id` (string(30) nullable) y `listing_type_sincronizado_en` (datetime nullable) a `ml_publicacion_producto` (data-model.md)
- [X] T002 [P] Actualizar `app/Models/Integraciones/MercadoLibreConfiguracion.php`: agregar `lista_precio_id_premium` y `tipo_publicacion_ultima_sync_en` a `$fillable`, agregar relación `listaPrecioPremium(): BelongsTo` (data-model.md)
- [X] T003 [P] Actualizar `app/Models/Integraciones/MercadoLibrePublicacionProducto.php`: agregar `listing_type_id` y `listing_type_sincronizado_en` a `$fillable`, cast datetime para `listing_type_sincronizado_en`, agregar método `esPremium(): bool` que compara `listing_type_id === 'gold_pro'` (data-model.md, research.md §R2)
- [X] T004 Actualizar `docs/documentacion_principal_crm.md` §3.2.bis y `docs/modelo_datos.md` §10 documentando `lista_precio_id_premium`/`listing_type_id` y la resolución de lista por tipo de publicación (constitución principio I — antes de continuar con las historias)

**Checkpoint**: Esquema y modelos listos — las historias pueden implementarse.

---

## Phase 3: User Story 1 - Configurar una Lista de Precios propia para publicaciones Premium (Priority: P1) 🎯 MVP parcial

**Goal**: Permitir elegir y persistir, en la pantalla de configuración de Mercado Libre, una Lista de
Precios separada para publicaciones Premium.

**Independent Test**: Configurar la Lista de Precios Premium, guardar, recargar la pantalla y confirmar
que el valor persiste — sin que todavía tenga efecto en ninguna sincronización real (eso es US2).

### Tests for User Story 1

- [X] T005 [P] [US1] Test de persistencia: guardar `lista_precio_id_premium` vía `PATCH configuracion/mercadolibre/ventas` y confirmar que `estado()` lo devuelve, y que se puede dejar en `null` — extender `tests/Feature/Integraciones/MercadoLibreConfiguracionTest.php` (crear el archivo si no existe todavía un test de configuración de ventas puntual)

### Implementation for User Story 1

- [X] T006 [US1] Agregar regla `'lista_precio_id_premium' => ['nullable', 'exists:listas_precio,id']` a `app/Http/Requests/Integraciones/GuardarConfiguracionVentasMercadoLibreRequest.php`
- [X] T007 [US1] `MercadoLibreConfiguracionController::guardarVentas()`: persistir `lista_precio_id_premium` (ya cubierto por `$configuracion->update($datos)` una vez agregado a fillable en T002) — `app/Http/Controllers/Integraciones/MercadoLibreConfiguracionController.php`
- [X] T008 [US1] `MercadoLibreConfiguracionController::estado()`: incluir `lista_precio_id_premium` en el array `configuracion` de la respuesta — `app/Http/Controllers/Integraciones/MercadoLibreConfiguracionController.php`
- [X] T009 [US1] Agregar campo Select2 "Lista de Precios ML Premium" al tab Ventas, junto al campo "Lista de Precios" ya existente, reutilizando `$listasPrecio` ya cargado por `index()` — `resources/views/configuracion/mercadolibre/index.blade.php`
- [X] T010 [US1] Leer/setear `lista_precio_id_premium` en el JS del formulario de configuración de ventas (mismo archivo/función que ya maneja `lista_precio_id`, con `.trigger('change.select2')` tras precargar el valor)

**Checkpoint**: US1 completa y verificable de forma independiente (persistencia sin efecto de sync todavía).

---

## Phase 4: User Story 2 - La sincronización de precios usa la lista correcta según el tipo de publicación (Priority: P1) 🎯 MVP

**Goal**: Que los tres disparadores de sincronización de precios (observer de cambio de precio, botón
"Sincronizar precios ahora", cambio de configuración) resuelvan la lista de precios por tipo de
publicación, con fallback a la lista general.

**Independent Test**: Con datos de prueba donde `listing_type_id` ya está cargado a mano en dos
vínculos (uno `gold_pro`, uno `gold_special`), disparar una sincronización y confirmar que cada uno
recibe el precio de la lista que le corresponde (no depende de que US3 esté implementada — el dato
puede sembrarse directo en la tabla para el test).

### Tests for User Story 2

- [X] T011 [P] [US2] Extender `tests/Feature/Integraciones/MercadoLibreSincronizarPreciosTest.php` con los casos: (a) publicación Premium con precio en lista Premium usa esa lista, (b) publicación Premium sin precio en la lista Premium cae a la lista general, (c) sin lista Premium configurada todas usan la general, (d) dos publicaciones del mismo producto con distinto tipo reciben cada una el precio que corresponde a su propio tipo (FR-011, vínculo 1:N spec 036)

### Implementation for User Story 2

- [X] T012 [US2] Agregar método privado `resolverListaPrecio(MercadoLibrePublicacionProducto $vinculo, MercadoLibreConfiguracion $configuracion): ?int` en `app/Services/MercadoLibre/SincronizadorPrecios.php`: si `$vinculo->esPremium()` y `$configuracion->lista_precio_id_premium` tiene precio cargado para el producto, devuelve esa lista; si no, devuelve `$configuracion->lista_precio_id` (FR-006/007/008/009)
- [X] T013 [US2] Reemplazar el uso directo de `$listaPrecioId` fijo en `enviarPendientes()` por `resolverListaPrecio()` por vínculo — `app/Services/MercadoLibre/SincronizadorPrecios.php`
- [X] T014 [US2] Adaptar `sincronizarListaCompleta(int $listaPrecioIdCambiada)`: sigue disparándose al cambiar cualquiera de las dos listas configuradas (general o Premium), pero en vez de empujar ese precio a todo vínculo con precio ahí, empuja únicamente a los vínculos cuyo `resolverListaPrecio()` (T012) resuelve exactamente a `$listaPrecioIdCambiada` — misma regla sea cual sea la lista que cambió, sin lógica especial por caso (Analyze U1) — `app/Services/MercadoLibre/SincronizadorPrecios.php`
- [X] T015 [US2] `MercadoLibreConfiguracionController::guardarVentas()`: disparar el push inmediato (ya existente para `lista_precio_id`) también cuando cambia `lista_precio_id_premium`, mismo criterio de comparación por `(int)` antes/después (FR-010) — `app/Http/Controllers/Integraciones/MercadoLibreConfiguracionController.php`

**Checkpoint**: US1 + US2 completas — la feature ya resuelve el problema original (precios Premium ya
no se pisan con la lista general) para publicaciones cuyo tipo ya esté clasificado.

---

## Phase 5: User Story 3 - El tipo de publicación se mantiene al día automáticamente (Priority: P2)

**Goal**: Clasificar y mantener actualizado `listing_type_id` de cada vínculo sin intervención manual,
incluido el backfill de las publicaciones ya vinculadas.

**Independent Test**: Correr el comando nuevo contra la cuenta real (o un fake de `ClienteMercadoLibre`
en test), confirmar que completa `listing_type_id` de vínculos con el dato en `null`, que respeta el
intervalo de 24hs sin `--forzar`, y que conserva el último valor conocido si la API falla.

### Tests for User Story 3

- [X] T016 [P] [US3] Crear `tests/Feature/Integraciones/MercadoLibreSincronizarTiposPublicacionTest.php`: casos (a) backfill completa `listing_type_id` de vínculos existentes, (b) falla de la API conserva el último valor conocido sin romper la corrida, (c) sin `--forzar` no vuelve a golpear la API si no pasaron 24hs desde `tipo_publicacion_ultima_sync_en`, (d) con `--forzar` ignora esa comparación

### Implementation for User Story 3

- [X] T017 [US3] Crear `app/Services/MercadoLibre/SincronizadorTiposPublicacion.php`: consulta `GET /items?ids=...` en chunks de hasta 20 (vía `ClienteMercadoLibre::obtener()`, research.md §R1) para todos los vínculos de `ml_publicacion_producto`, persiste `listing_type_id`/`listing_type_sincronizado_en` por vínculo, y ante fallo de un chunk conserva el valor previo de esos vínculos sin abortar el resto
- [X] T018 [US3] Crear `app/Console/Commands/SincronizarTiposPublicacionMercadoLibre.php` (`mercadolibre:sincronizar-tipos-publicacion {--forzar}`), mismo patrón que `SincronizarStockMercadoLibre`: compara `ml_configuracion.tipo_publicacion_ultima_sync_en` contra un intervalo fijo de 24 horas salvo `--forzar`, actualiza esa columna al terminar
- [X] T019 [US3] Registrar el comando en `bootstrap/app.php` dentro de `withSchedule()`: `$schedule->command('mercadolibre:sincronizar-tipos-publicacion')->everyMinute()->withoutOverlapping()` (research.md §R3 — el propio comando decide si corresponde correr)
- [X] T020 [US3] Completar `listing_type_id` inicial al vincular una publicación nueva (FR-003): localizar el flujo de vinculación existente (`app/Services/MercadoLibre/VinculacionMercadoLibre.php` o el controlador de vinculaciones de `/ingresos/mercadolibre`) y, tras crear el `MercadoLibrePublicacionProducto`, consultar y persistir su `listing_type_id` reutilizando `SincronizadorTiposPublicacion`

**Checkpoint**: Las tres historias completas — el sistema clasifica y mantiene al día el tipo de
publicación sin intervención manual, incluidas las 270 publicaciones ya vinculadas hoy.

---

## Phase 6: Polish & Cross-Cutting Concerns

- [ ] T021 Correr manualmente `php artisan mercadolibre:sincronizar-tipos-publicacion --forzar` contra la cuenta real tras el deploy, para completar el backfill de las 270 publicaciones ya vinculadas (quickstart.md, Validación 4) — verificación operativa, no código
- [ ] T022 Ejecutar las 5 validaciones de [quickstart.md](quickstart.md) de punta a punta contra el entorno con datos reales (Pompei Sanitarios)

---

## Dependencies & Execution Order

### Phase Dependencies

- **Foundational (Phase 2)**: sin dependencias — bloquea todas las historias
- **US1 (Phase 3)**: depende de Foundational. Sin dependencia de US2/US3
- **US2 (Phase 4)**: depende de Foundational. Depende de que exista el campo `lista_precio_id_premium`
  (T002/T006) para poder resolverlo, pero NO depende de que US1 esté "terminada" en UI — puede probarse
  seteando el campo directo en DB/factory
- **US3 (Phase 5)**: depende de Foundational (columnas `listing_type_id`). Independiente de US1/US2 en
  su lógica interna, pero sin US3 los vínculos quedan con `listing_type_id = null` y US2 siempre cae al
  fallback de lista general (comportamiento correcto igual, sólo que sin diferenciación real hasta que
  corra T021)
- **Polish (Phase 6)**: depende de las tres historias completas

### Parallel Opportunities

- T002 y T003 en paralelo (modelos distintos)
- Dentro de cada historia, las tareas de test marcadas [P] pueden correr junto con las de otra historia
  si hay más de un desarrollador, siempre después de Foundational

---

## Implementation Strategy

### MVP (resuelve el problema original)

1. Phase 2 (Foundational)
2. Phase 3 (US1) — configurar la lista Premium
3. Phase 4 (US2) — la sincronización la usa de verdad
4. **STOP y VALIDAR**: con `listing_type_id` sembrado a mano en un par de vínculos de prueba, confirmar
   que el problema original (precios Premium pisados por la lista general) está resuelto

### Incremental

5. Phase 5 (US3) — automatiza la clasificación para que no dependa de sembrar el dato a mano
6. Phase 6 (Polish) — backfill real + validación end-to-end contra la cuenta de Pompei Sanitarios
