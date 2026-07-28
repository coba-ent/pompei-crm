# Tasks: Sincronización de stock del CRM hacia Mercado Libre

**Spec**: [spec.md](./spec.md) · **Plan**: [plan.md](./plan.md) · **Datos**: [data-model.md](./data-model.md) · **Contratos**: [contracts/rutas-internas.md](./contracts/rutas-internas.md) · **Validación**: [quickstart.md](./quickstart.md)

**Branch**: `013-stock-mercadolibre` · **Fecha**: 2026-07-28

**Tests**: incluidos y **obligatorios** — el principio IV de la constitución los exige para lógica de
stock, que es exactamente el centro de esta spec (prevención de bucle, consolidación, piso en cero, no
concurrencia).

**Convención**: `[P]` = paralelizable (archivo distinto, sin dependencias pendientes). `[USn]` = historia
de usuario a la que pertenece.

---

## Phase 1 — Setup

- [X] T001 Confirmar que no hace falta ninguna dependencia nueva (Composer/NPM): toda la infraestructura
  de transporte, reintentos y colas ya existe desde las specs 011/012 (plan.md §Technical Context)

---

## Phase 2 — Foundational (bloquea todas las historias)

- [X] T002 [P] Migración `add_stock_fields_to_ml_publicacion_producto_table` con `stock_pendiente`
  (boolean, default false), `stock_sincronizado_en` (timestamp nullable), `stock_error` (string(255)
  nullable), `stock_error_en` (timestamp nullable) — data-model.md §`ml_publicacion_producto`
- [X] T003 [P] Migración `add_stock_fields_to_ml_configuracion_table` con `stock_ultima_sync_en`
  (timestamp nullable) y `stock_ultima_sync_resultado` (string(255) nullable) — data-model.md
  §`ml_configuracion`
- [X] T004 [P] Extender `app/Models/Integraciones/MercadoLibrePublicacionProducto.php`: agregar las 4
  columnas nuevas a `$fillable`, castear los timestamps, y un scope `pendientes()` que filtre
  `stock_pendiente = true`
- [X] T005 [P] Extender `app/Models/Integraciones/MercadoLibreConfiguracion.php`: agregar
  `stock_ultima_sync_en`/`stock_ultima_sync_resultado` a `$fillable` con el cast de datetime
  correspondiente
- [X] T006 Agregar el método público `depositoEfectivo(): Deposito` a `MercadoLibreConfiguracion`
  (depósito configurado si existe y está activo; si no, el primero activo por orden de alta — misma
  regla que ya usa `StockDeVenta::depositoPorDefecto()`), y refactorizar
  `app/Services/Ingresos/StockDeVenta.php::resolverDeposito()` para reutilizarlo en su rama de Mercado
  Libre, **sin cambiar su comportamiento actual** (research.md R1, evita duplicar la resolución de
  depósito en dos servicios)
- [X] T007 [P] Test de regresión en `tests/Feature/Ingresos/VentaStockTest.php` (ya existente, spec 012):
  confirmar que el refactor de T006 no cambia el depósito que usan las Ventas de Mercado Libre ni las
  manuales

**Checkpoint**: esquema y resolución de depósito listos — las historias de usuario pueden empezar.

---

## Phase 3 — US1: Que una Venta cargada a mano se refleje en Mercado Libre (P1) 🎯 MVP

**Objetivo**: cerrar el riesgo de sobreventa para el caso base. **Test independiente**: cargar una Venta
manual sobre un producto vinculado, forzar la sincronización y comprobar que la cantidad disponible en
Mercado Libre bajó lo mismo que vendió el CRM.

- [X] T008 [US1] Crear `app/Observers/MovimientoStockObserver.php` con `created(MovimientoStock $movimiento)`:
  resuelve `MercadoLibreConfiguracion::actual()->depositoEfectivo()`, descarta el movimiento si no es de
  ese depósito (FR-001), busca `MercadoLibrePublicacionProducto::where('producto_id', ...)->first()` y
  descarta si no hay vínculo (FR-005); si pasa ambos filtros, `update(['stock_pendiente' => true])`
- [X] T009 [US1] Registrar el observer en `app/Providers/AppServiceProvider.php::boot()`
  (`MovimientoStock::observe(MovimientoStockObserver::class)`, mismo patrón que `VentaObserver`/`CompraObserver`)
- [X] T010 [US1] Crear `app/Services/MercadoLibre/SincronizadorStock.php` con candado propio
  (`Cache::lock('ml:sincronizar_stock', 300)`, FR-008) y los cortes previos al bucle —función
  desactivada, modo sólo lectura, conexión caída— con un único registro en el historial (FR-009,
  FR-010), mismo esqueleto que `SincronizadorOrdenes::verificarCortes()`
- [X] T011 [US1] Implementar en `SincronizadorStock` la iteración de `MercadoLibrePublicacionProducto::pendientes()->get()`:
  por cada vínculo, `max(0, StockService::disponibilidad($producto, null, $depositoMl))` (FR-003, FR-004)
  y `ClienteMercadoLibre::enviar('sincronizar_stock', 'PUT', "/items/{$vinculo->ml_item_id}", ['available_quantity' => $cantidad])`
  (FR-012)
- [X] T012 [US1] Implementar en `SincronizadorStock` la interpretación de la respuesta por vínculo: éxito
  → `stock_pendiente = false`, `stock_sincronizado_en = now()`, limpiar `stock_error`/`stock_error_en`;
  fallo → `stock_error`/`stock_error_en`, dejar `stock_pendiente = true`, y **continuar con el resto del
  `foreach`** sin cortar la corrida (FR-014, FR-015)
- [X] T013 [US1] Crear `app/Console/Commands/SincronizarStockMercadoLibre.php` con `--forzar`, comparando
  `stock_ultima_sync_en` contra `frecuencia_sync_minutos` (mismo campo reutilizado, mismo patrón que
  `SincronizarOrdenesMercadoLibre`) (FR-006)
- [X] T014 [US1] Registrar `mercadolibre:sincronizar-stock` en `bootstrap/app.php` con
  `everyMinute()->withoutOverlapping()`, **después** de `mercadolibre:sincronizar-ordenes` en el mismo
  `withSchedule()` (research.md R4)
- [X] T015 [P] [US1] Test en `tests/Feature/Integraciones/MovimientoStockObserverTest.php`: una Venta
  manual sobre un producto vinculado marca el vínculo pendiente; un movimiento en otro depósito no lo
  marca; un producto sin vínculo no marca nada (FR-001, FR-005)
- [X] T016 [P] [US1] Test en `tests/Feature/Integraciones/SincronizadorStockTest.php` con `Http::fake()`:
  varias Ventas seguidas sobre el mismo producto generan **un único** `PUT /items/...` con el valor final
  (FR-003, SC-003); stock negativo se envía como 0 (FR-004, SC-004); éxito deja el vínculo sincronizado
  con fecha (FR-017)

**Checkpoint**: una Venta manual ya actualiza Mercado Libre de punta a punta (vía `--forzar` o esperando
la frecuencia configurada).

---

## Phase 4 — US2: Que una orden de Mercado Libre no rebote (P1)

**Objetivo**: cerrar la ventana de bucle. **Test independiente**: convertir una orden de Mercado Libre en
Venta (spec 012) y comprobar que **no** se genera ningún envío de stock por ese movimiento.

- [X] T017 [US2] Extender `MovimientoStockObserver::created()` con la exclusión de bucle: si
  `$movimiento->origen_type === Venta::class`, cargar la Venta y, si `$venta->origen === 'mercadolibre'`,
  no marcar el vínculo como pendiente (FR-002)
- [X] T018 [P] [US2] Test en `MovimientoStockObserverTest.php`: convertir una orden de Mercado Libre en
  Venta (spec 012, `ConversorOrdenAVenta`) **no** marca pendiente el vínculo de esa publicación; una
  Venta manual sobre el mismo producto sí lo marca (regresión de US1) (FR-002, SC-002)
- [X] T019 [US2] Test de integración en `tests/Feature/Integraciones/MercadoLibreOrdenEjecucionTest.php`:
  ejecutar `mercadolibre:sincronizar-ordenes --forzar` seguido de `mercadolibre:sincronizar-stock --forzar`
  y verificar que el segundo refleja el stock ya neto de lo que trajo el primero, sin necesitar una
  segunda corrida (FR-006, research.md R4)

**Checkpoint**: US1 + US2 juntas son el cierre real del riesgo de sobreventa — ninguna orden de Mercado
Libre genera un envío redundante, y toda Venta manual sí dispara uno.

---

## Phase 5 — US3: Forzar la sincronización de stock manualmente (P2)

**Objetivo**: control inmediato sin esperar el intervalo programado. **Test independiente**: con vínculos
pendientes, presionar "Sincronizar stock ahora" y verlos actualizados sin recargar la página.

- [X] T020 [US3] Agregar la acción `sincronizarStock` a `app/Http/Controllers/Ingresos/MercadoLibreVentaController.php`
  según contracts §1 (invoca `SincronizadorStock`, devuelve `{ok, mensaje, actualizados, con_error}`)
- [X] T021 [US3] Registrar `POST ingresos/mercadolibre/sincronizar-stock` en `routes/web.php`, dentro del
  grupo ya existente con permiso `ventas.ver` y guard de función activa
- [X] T022 [US3] Extender `resources/js/mercadolibre-ventas.js` con el botón "Sincronizar stock ahora"
  (AJAX, Toastr, sin recarga — mismo patrón que "Sincronizar ahora") (FR-007, SC-007)
- [X] T023 [US3] Agregar el botón a `resources/views/ingresos/mercadolibre/index.blade.php`, junto al de
  "Sincronizar ahora" de órdenes
- [X] T024 [P] [US3] Test en `tests/Feature/Integraciones/MercadoLibreSincronizarStockTest.php`: la acción
  devuelve los contadores esperados; dos disparos simultáneos sólo ejecutan uno (FR-008); bloqueada con
  **un único registro** en el historial (no uno por vínculo pendiente) bajo modo sólo lectura, función
  desactivada, **y conexión caída** (FR-009, FR-010) — cubre los tres cortes de `SincronizadorStock::verificarCortes()`
  de T010, no sólo dos

**Checkpoint**: control manual disponible, sin depender del intervalo programado.

---

## Phase 6 — US4: Enterarse cuando Mercado Libre rechaza una actualización (P2)

**Objetivo**: visibilidad del estado real de sincronización. **Test independiente**: pausar una
publicación vinculada, generar un cambio de stock sobre su producto, sincronizar, y verificar que queda
señalada con el motivo mientras el resto de los vínculos se sincroniza con normalidad.

- [X] T025 [US4] Extender `MercadoLibreVinculacionController::datatable()` con las columnas derivadas
  `stock_estado` (`sincronizado`/`pendiente`/`error`), `stock_sincronizado_en` y `stock_error` según
  contracts §2 (FR-017)
- [X] T026 [US4] Extender `resources/views/ingresos/mercadolibre/vinculaciones.blade.php` con las
  columnas nuevas, mostrando motivo y fecha del error en un tooltip (FR-017)
- [X] T027 [US4] Mostrar `stock_ultima_sync_en`/`stock_ultima_sync_resultado` en
  `resources/views/configuracion/mercadolibre/index.blade.php`, junto al panel ya existente de la
  sincronización de órdenes (FR-019)
- [X] T028 [P] [US4] Test en `SincronizadorStockTest.php`: el rechazo de un vínculo (publicación pausada,
  simulado con `Http::fake()`) no interrumpe el envío del resto de los vínculos pendientes de la misma
  corrida, y deja ese vínculo con `stock_error` y `stock_pendiente = true` para reintentar (FR-014,
  FR-015, SC-006)
- [X] T029 [P] [US4] Test de reintento ante 429/5xx: `SincronizadorStock` no implementa reintento propio
  —ya lo cubre `ClienteMercadoLibre::ejecutarConReintentos()` (research.md R7)— verificar con
  `Http::fake()` una secuencia 429→200 que el vínculo termina sincronizado sin marcarlo como error
  (FR-013)

**Checkpoint**: el usuario puede confiar en la pantalla de vinculaciones para saber qué está realmente
sincronizado, sin tener que entrar a Mercado Libre a comprobarlo.

---

## Phase 7 — Polish y transversales

- [X] T030 [P] Verificar que ningún dato sensible se agrega al historial de operaciones por esta spec —
  reutiliza el saneado ya existente de `ClienteMercadoLibre::registrarLog()` (spec 011), sin lógica
  propia que loguear
- [X] T031 [P] Actualizar `CREDENCIALES_ACCESO.txt` si alguna prueba manual de esta spec cambia un acceso
  (regla de `CLAUDE.md`) — no aplica si toda la prueba se hace con `Http::fake()`/factories
- [X] T031a [P] Verificar la portabilidad (FR-011) ejecutando la suite de esta spec con el almacén de
  caché de archivos y con el de base de datos, extendiendo el mismo test de portabilidad que la spec 012
  ya corrió para `SincronizadorOrdenes` (`tests/Feature/Integraciones/`, T089 de `specs/012-.../tasks.md`)
  para cubrir también el candado de `SincronizadorStock`
- [ ] T032 **PENDIENTE — requiere la cuenta real conectada**: verificar contra `GET /users/{id}` si la
  cuenta real trae el tag `warehouse_management`; si lo trae, adaptar `SincronizadorStock` al flujo de
  stock multi origen en lugar de `PUT /items/{id}` (research.md R6)
- [ ] T033 **PENDIENTE — requiere la cuenta real y un navegador**: recorrer `quickstart.md` de punta a
  punta con una publicación real, confirmando que la cantidad disponible en Mercado Libre baja
  exactamente lo que indica el CRM

---

## Cobertura heredada (requisitos sin tarea propia, por diseño)

Estos requisitos **no** tienen tarea porque ya los satisface infraestructura existente y verificada de
las specs 011/012. Duplicarlos sería reimplementar lógica crítica ya probada (research.md R7).

| Requisito | Quién lo cubre | Verificación |
|---|---|---|
| FR-013 — espera creciente ante 429/5xx | `ClienteMercadoLibre::ejecutarConReintentos()`, sin código propio | T029 |
| FR-016 — registrar cada envío en el historial de operaciones | `ClienteMercadoLibre::registrarLog()`, disparado en cada `enviar()`, sin código propio | T030 |
| FR-020 — sin tabla de historial propia | Requisito negativo: no se construye ninguna tabla nueva de eventos (research.md R3) | Revisión en T002 |

> **FR-009/FR-010 NO están en esta tabla a propósito**: a diferencia de FR-013/FR-016, no quedan
> cubiertos sólo por `ClienteMercadoLibre` — necesitan el corte previo propio de `SincronizadorStock`
> (T010), por la misma razón por la que `SincronizadorOrdenes` (spec 012) necesitó el suyo. Ver
> research.md R7 (corregido).

## Dependencias entre historias

```
Setup (T001)
   ↓
Foundational (T002-T007)
   ↓
US1 (T008-T016)  ─── MVP: cierra el caso base del riesgo de sobreventa
   ↓
US2 (T017-T019)  ← requiere US1 (extiende el mismo Observer)
   ↓
US3 (T020-T024)  ← requiere US1 (reutiliza SincronizadorStock)
   ↓
US4 (T025-T029)  ← requiere US1 (expone el estado que US1 ya persiste)
   ↓
Polish (T030-T033)
```

**US3 y US4 pueden desarrollarse en paralelo** — ambas sólo dependen de US1, no entre sí.

## Oportunidades de paralelización

- **Fase 2**: T002-T005 son `[P]` (migraciones y modelos, archivos distintos).
- **Tests**: casi todos son `[P]` entre sí — distinto archivo, sin estado compartido.
- **Fases 5 y 6** (US3 y US4) pueden avanzar en paralelo una vez completa US1.

## Estrategia de implementación

**MVP sugerido**: Fases 1-4 (hasta T019). Cierra el riesgo de sobreventa de punta a punta —marcar,
consolidar, enviar y no rebotar— con visibilidad mínima vía `--forzar` y logs, sin las pantallas de
comodidad (US3/US4).

**Entrega completa**: Fases 1-6 (hasta T029). Agrega el control manual y la visibilidad de errores que
hacen el módulo confiable de usar sin mirar los logs del servidor.
