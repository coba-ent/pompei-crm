# Tasks: Sincronización de stock del CRM hacia Tiendanube

**Spec**: [spec.md](./spec.md) · **Plan**: [plan.md](./plan.md) · **Datos**: [data-model.md](./data-model.md) · **Contratos**: [contracts/rutas-internas.md](./contracts/rutas-internas.md) · **Validación**: [quickstart.md](./quickstart.md)

**Branch**: `018-stock-tiendanube` · **Fecha**: 2026-07-29

**Tests**: incluidos y **obligatorios** — el principio IV de la constitución los exige para lógica de
stock, que es exactamente el centro de esta spec (prevención de bucle, consolidación, piso en cero, no
concurrencia).

**Convención**: `[P]` = paralelizable (archivo distinto, sin dependencias pendientes). `[USn]` = historia
de usuario a la que pertenece.

> ⚠️ **Prerrequisito bloqueante de esta spec**: `specs/017-ventas-tiendanube/tasks.md` debe estar
> ejecutada (o ejecutarse junto con ésta) **antes** de T002, porque esta spec extiende tablas y clases
> que la 017 crea (`tn_variante_producto`, `TiendanubeVentaController`, `TiendanubeVinculacionController`,
> `TiendanubeOrden`/`TiendanubeOrdenItem`, `origen = 'tiendanube'` en `ventas`). Ver plan.md, "Advertencia
> de secuencia de implementación". Si al empezar T002 esas clases/tablas no existen todavía, implementar
> primero `specs/017-ventas-tiendanube/tasks.md`.

---

## Phase 1 — Setup

- [ ] T001 Confirmar que no hace falta ninguna dependencia nueva (Composer/NPM): toda la infraestructura
  de transporte, reintentos y kill-switch ya existe desde la spec 015 (`ClienteTiendanube`) y la 017
  (plan.md §Technical Context); confirmar además que `specs/017-ventas-tiendanube/` ya está implementada
  en código (ver advertencia arriba) antes de continuar

---

## Phase 2 — Foundational (bloquea todas las historias)

- [ ] T002 [P] Migración `add_stock_fields_to_tn_variante_producto_table` con `tn_product_id` (string(50)
  — **no** existe en el esquema de la spec 017, que sólo captura `variant_id`; la agrega esta migración),
  `stock_pendiente` (boolean, default false), `stock_sincronizado_en` (timestamp nullable), `stock_error`
  (string(255) nullable), `stock_error_en` (timestamp nullable) — data-model.md §`tn_variante_producto`
- [ ] T003 [P] Migración `add_stock_fields_to_tn_configuracion_table` con `stock_ultima_sync_en`
  (timestamp nullable) y `stock_ultima_sync_resultado` (string(255) nullable) — data-model.md
  §`tn_configuracion`
- [ ] T004 [P] Extender `app/Models/Integraciones/TiendanubeVarianteProducto.php`: agregar las 5 columnas
  nuevas de T002 (`tn_product_id` incluido) a `$fillable`, castear los timestamps, y un scope
  `pendientes()` que filtre `stock_pendiente = true`
- [ ] T005 [P] Extender `app/Models/Integraciones/TiendanubeConfiguracion.php`: agregar
  `stock_ultima_sync_en`/`stock_ultima_sync_resultado` a `$fillable` con el cast de datetime
  correspondiente
- [ ] T006 Confirmar que `TiendanubeConfiguracion::depositoEfectivo(): Deposito` ya existe (planeado por
  spec 017, calcado de `MercadoLibreConfiguracion::depositoEfectivo()`); si por algún motivo no se
  implementó ahí, agregarlo acá con la misma regla (depósito configurado si existe y está activo; si no,
  el primero activo por orden de alta) — **no** duplicar la resolución de depósito en un método propio de
  esta spec
- [ ] T007 [P] Test de regresión en `tests/Feature/Ingresos/VentaTiendanubeStockTest.php` (spec 017, si ya
  existe) o nuevo si no: confirmar que T004/T005 no cambian el depósito que usan las Ventas de Tiendanube
  ni las manuales ni las de Mercado Libre

**Checkpoint**: esquema y resolución de depósito listos — las historias de usuario pueden empezar.

---

## Phase 3 — US1: Que una Venta cargada a mano se refleje en Tiendanube (P1) 🎯 MVP

**Objetivo**: cerrar el riesgo de sobreventa para el caso base. **Test independiente**: cargar una Venta
manual sobre un producto vinculado, forzar la sincronización y comprobar que la cantidad disponible en
Tiendanube bajó lo mismo que vendió el CRM.

- [ ] T008 [US1] Extender `app/Observers/MovimientoStockObserver.php` (existente desde la spec 013, con
  rama Mercado Libre) agregando una rama Tiendanube en `created(MovimientoStock $movimiento)`: resuelve
  `TiendanubeConfiguracion::actual()->depositoEfectivo()`, descarta el movimiento si no es de ese depósito
  (FR-001), busca `TiendanubeVarianteProducto::where('producto_id', ...)->first()` y descarta si no hay
  vínculo (FR-005); si pasa ambos filtros, `update(['stock_pendiente' => true])`. Ambas ramas (ML y
  Tiendanube) son independientes y conviven en el mismo método sin interferirse
- [ ] T009 [US1] Crear `app/Services/Tiendanube/SincronizadorStock.php` con candado propio
  (`Cache::lock('tn:sincronizar_stock', 300)`, FR-008, independiente del candado de órdenes de Tiendanube
  y del de stock de Mercado Libre) y los cortes previos al bucle —función desactivada, modo sólo lectura,
  conexión caída— con un único registro en el historial (FR-009, FR-010), mismo esqueleto que
  `SincronizadorOrdenes::verificarCortes()` de Tiendanube (spec 017)
- [ ] T010 [US1] Implementar en `SincronizadorStock` la iteración de
  `TiendanubeVarianteProducto::pendientes()->get()`: por cada vínculo, si `tn_product_id` está vacío,
  marcarlo con `stock_error = "Vínculo incompleto: falta el producto de Tiendanube"` y continuar con el
  siguiente sin llamar a la API (FR-005a); si está completo, calcular
  `max(0, StockService::disponibilidad($producto, null, $depositoTn))` (FR-003, FR-004) y llamar a
  `ClienteTiendanube::enviar('sincronizar_stock', 'POST', "/products/{$vinculo->tn_product_id}/variants/stock", ['action' => 'replace', 'value' => $cantidad, 'id' => $vinculo->variant_id])`
  (FR-012, research.md R6)
- [ ] T011 [US1] Implementar en `SincronizadorStock` la interpretación de la respuesta por vínculo: éxito
  → `stock_pendiente = false`, `stock_sincronizado_en = now()`, limpiar `stock_error`/`stock_error_en`;
  fallo → `stock_error`/`stock_error_en`, dejar `stock_pendiente = true`, y **continuar con el resto del
  `foreach`** sin cortar la corrida (FR-014, FR-015)
- [ ] T012 [US1] Crear `app/Console/Commands/SincronizarStockTiendanube.php` con `--forzar`, comparando
  `stock_ultima_sync_en` contra `frecuencia_sync_minutos` (mismo campo reutilizado, mismo patrón que
  `SincronizarOrdenesTiendanube` de la spec 017) (FR-006)
- [ ] T013 [US1] Registrar `tiendanube:sincronizar-stock` en `bootstrap/app.php` con
  `everyMinute()->withoutOverlapping()`, **después** de `tiendanube:sincronizar-ordenes` en el mismo
  `withSchedule()` (research.md R4)
- [ ] T014 [P] [US1] Test en `tests/Feature/Integraciones/TiendanubeMovimientoStockObserverTest.php`: una
  Venta manual sobre un producto vinculado a Tiendanube marca el vínculo pendiente; un movimiento en otro
  depósito no lo marca; un producto sin vínculo no marca nada; marcar el vínculo de Tiendanube no
  interfiere con el de Mercado Libre del mismo producto si lo tuviera (FR-001, FR-005)
- [ ] T015 [P] [US1] Test en `tests/Feature/Integraciones/TiendanubeSincronizadorStockTest.php` con
  `Http::fake()`: varias Ventas seguidas sobre el mismo producto generan **un único**
  `POST /products/.../variants/stock` con `action: replace` y el valor final (FR-003, SC-003); stock
  negativo se envía como 0 (FR-004, SC-004); éxito deja el vínculo sincronizado con fecha (FR-017); un
  vínculo con `tn_product_id` vacío se señala sin llamar a la API (FR-005a)

**Checkpoint**: una Venta manual ya actualiza Tiendanube de punta a punta (vía `--forzar` o esperando la
frecuencia configurada).

---

## Phase 4 — US2: Que una orden de Tiendanube no rebote (P1)

**Objetivo**: cerrar la ventana de bucle. **Test independiente**: convertir una orden de Tiendanube en
Venta (spec 017) y comprobar que **no** se genera ningún envío de stock por ese movimiento.

- [ ] T016 [US2] Extender la rama Tiendanube de `MovimientoStockObserver::created()` con la exclusión de
  bucle: si `$movimiento->origen_type === Venta::class`, cargar la Venta y, si `$venta->origen ===
  'tiendanube'`, no marcar el vínculo como pendiente (FR-002)
- [ ] T017 [P] [US2] Test en `TiendanubeMovimientoStockObserverTest.php`: convertir una orden de
  Tiendanube en Venta (spec 017, `ConversorOrdenAVenta`) **no** marca pendiente el vínculo de esa variante;
  una Venta manual sobre el mismo producto sí lo marca (regresión de US1) (FR-002, SC-002)
- [ ] T018 [US2] Test de integración en `tests/Feature/Integraciones/TiendanubeOrdenEjecucionTest.php`:
  ejecutar `tiendanube:sincronizar-ordenes --forzar` seguido de `tiendanube:sincronizar-stock --forzar` y
  verificar que el segundo refleja el stock ya neto de lo que trajo el primero, sin necesitar una segunda
  corrida (FR-006, research.md R4)

**Checkpoint**: US1 + US2 juntas son el cierre real del riesgo de sobreventa — ninguna orden de Tiendanube
genera un envío redundante, y toda Venta manual sí dispara uno.

---

## Phase 5 — US3: Forzar la sincronización de stock manualmente (P2)

**Objetivo**: control inmediato sin esperar el intervalo programado. **Test independiente**: con vínculos
pendientes, presionar "Sincronizar stock ahora" y verlos actualizados sin recargar la página.

- [ ] T019 [US3] Agregar la acción `sincronizarStock` a
  `app/Http/Controllers/Ingresos/TiendanubeVentaController.php` según contracts §1 (invoca
  `SincronizadorStock`, devuelve `{ok, mensaje, actualizados, con_error}`)
- [ ] T020 [US3] Registrar `POST ingresos/tiendanube/sincronizar-stock` en `routes/web.php`, dentro del
  grupo ya existente con permiso `ventas.ver` y guard de función activa
- [ ] T021 [US3] Extender `resources/js/tiendanube-ventas.js` con el botón "Sincronizar stock ahora"
  (AJAX, Toastr, sin recarga — mismo patrón que "Sincronizar ahora") (FR-007, SC-007)
- [ ] T022 [US3] Agregar el botón a `resources/views/ingresos/tiendanube/index.blade.php`, junto al de
  "Sincronizar ahora" de órdenes
- [ ] T023 [P] [US3] Test en `tests/Feature/Integraciones/TiendanubeSincronizarStockTest.php`: la acción
  devuelve los contadores esperados; dos disparos simultáneos sólo ejecutan uno (FR-008); bloqueada con
  **un único registro** en el historial (no uno por vínculo pendiente) bajo modo sólo lectura, función
  desactivada, y conexión caída (FR-009, FR-010) — cubre los tres cortes de
  `SincronizadorStock::verificarCortes()` de T009, no sólo dos

**Checkpoint**: control manual disponible, sin depender del intervalo programado.

---

## Phase 6 — US4: Enterarse cuando Tiendanube rechaza una actualización (P2)

**Objetivo**: visibilidad del estado real de sincronización. **Test independiente**: eliminar o
despublicar el producto de una variante vinculada, generar un cambio de stock sobre ese producto,
sincronizar, y verificar que queda señalada con el motivo mientras el resto de los vínculos se sincroniza
con normalidad.

- [ ] T024 [US4] Extender `TiendanubeVinculacionController::datatable()` con las columnas derivadas
  `stock_estado` (`sincronizado`/`pendiente`/`error`), `stock_sincronizado_en` y `stock_error` según
  contracts §2 (FR-017)
- [ ] T025 [US4] Extender `resources/views/ingresos/tiendanube/vinculaciones.blade.php` con las columnas
  nuevas, mostrando motivo y fecha del error en un tooltip (FR-017)
- [ ] T026 [US4] Mostrar `stock_ultima_sync_en`/`stock_ultima_sync_resultado` en
  `resources/views/configuracion/tiendanube/index.blade.php`, junto al panel ya existente de la
  sincronización de órdenes (FR-019)
- [ ] T027 [P] [US4] Test en `TiendanubeSincronizadorStockTest.php`: el rechazo de un vínculo (producto
  eliminado, simulado con `Http::fake()` devolviendo 404 "Product with such id does not exist") no
  interrumpe el envío del resto de los vínculos pendientes de la misma corrida, y deja ese vínculo con
  `stock_error` y `stock_pendiente = true` para reintentar (FR-014, FR-015, SC-006)
- [ ] T028 [P] [US4] Test de reintento ante 429/5xx: `SincronizadorStock` no implementa reintento propio
  —ya lo cubre `ClienteTiendanube::ejecutarConReintentos()` (research.md R7)— verificar con `Http::fake()`
  una secuencia 429→200 que el vínculo termina sincronizado sin marcarlo como error (FR-013)

**Checkpoint**: el usuario puede confiar en la pantalla de vinculación de variantes para saber qué está
realmente sincronizado, sin tener que entrar a Tiendanube a comprobarlo.

---

## Phase 7 — Polish y transversales

- [ ] T029 [P] Verificar que ningún dato sensible se agrega al historial de operaciones por esta spec —
  reutiliza el saneado ya existente de `ClienteTiendanube::registrarLog()` (spec 015), sin lógica propia
  que loguear
- [ ] T030 [P] Actualizar `CREDENCIALES_ACCESO.txt` si alguna prueba manual de esta spec cambia un acceso
  (regla de `CLAUDE.md`) — no aplica si toda la prueba se hace con `Http::fake()`/factories
- [ ] T031 [P] Verificar la portabilidad (FR-011) ejecutando la suite de esta spec con el almacén de caché
  de archivos y con el de base de datos, extendiendo el mismo test de portabilidad ya usado para
  `SincronizadorStock` de Mercado Libre (spec 013, T031a) para cubrir también el candado de
  `SincronizadorStock` de Tiendanube
- [ ] T032 **PENDIENTE — requiere la tienda real conectada**: confirmar contra la documentación/soporte de
  Tiendanube si el token bucket ponderado del endpoint `POST /products/{id}/variants/stock` impone un
  límite efectivo distinto al leaky bucket de lectura (~2/s); ajustar `ESPERAS_SEGUNDOS` de
  `ClienteTiendanube` si hiciera falta uno específico para este endpoint (research.md R6)
- [ ] T033 **PENDIENTE — requiere la tienda real y un navegador**: recorrer `quickstart.md` de punta a
  punta con una variante real, confirmando que la cantidad disponible en Tiendanube baja exactamente lo
  que indica el CRM

---

## Cobertura heredada (requisitos sin tarea propia, por diseño)

Estos requisitos **no** tienen tarea porque ya los satisface infraestructura existente y verificada de
las specs 015/017. Duplicarlos sería reimplementar lógica crítica ya probada (research.md R7).

| Requisito | Quién lo cubre | Verificación |
|---|---|---|
| FR-013 — espera creciente ante rechazos por límite de tasa | `ClienteTiendanube::ejecutarConReintentos()`, sin código propio | T028 |
| FR-016 — registrar cada envío en el historial de operaciones | `ClienteTiendanube::registrarLog()`, disparado en cada `enviar()`, sin código propio | T029 |
| FR-020 — sin tabla de historial propia | Requisito negativo: no se construye ninguna tabla nueva de eventos (research.md R3) | Revisión en T002 |

> **FR-009/FR-010 NO están en esta tabla a propósito**: a diferencia de FR-013/FR-016, no quedan
> cubiertos sólo por `ClienteTiendanube` — necesitan el corte previo propio de `SincronizadorStock`
> (T009), por la misma razón por la que `SincronizadorOrdenes` de Tiendanube (spec 017) y
> `SincronizadorStock` de Mercado Libre (spec 013) necesitaron el suyo. Ver research.md R7.

## Dependencias entre historias

```
Prerrequisito externo: specs/017-ventas-tiendanube/tasks.md implementada
   ↓
Setup (T001)
   ↓
Foundational (T002-T007)
   ↓
US1 (T008-T015)  ─── MVP: cierra el caso base del riesgo de sobreventa
   ↓
US2 (T016-T018)  ← requiere US1 (extiende el mismo Observer)
   ↓
US3 (T019-T023)  ← requiere US1 (reutiliza SincronizadorStock)
   ↓
US4 (T024-T028)  ← requiere US1 (expone el estado que US1 ya persiste)
   ↓
Polish (T029-T033)
```

**US3 y US4 pueden desarrollarse en paralelo** — ambas sólo dependen de US1, no entre sí.

## Oportunidades de paralelización

- **Fase 2**: T002-T005 son `[P]` (migraciones y modelos, archivos distintos).
- **Tests**: casi todos son `[P]` entre sí — distinto archivo, sin estado compartido.
- **Fases 5 y 6** (US3 y US4) pueden avanzar en paralelo una vez completa US1.

## Estrategia de implementación

**MVP sugerido**: Fases 1-4 (hasta T018). Cierra el riesgo de sobreventa de punta a punta —marcar,
consolidar, enviar y no rebotar— con visibilidad mínima vía `--forzar` y logs, sin las pantallas de
comodidad (US3/US4).

**Entrega completa**: Fases 1-6 (hasta T028). Agrega el control manual y la visibilidad de errores que
hacen el módulo confiable de usar sin mirar los logs del servidor.
