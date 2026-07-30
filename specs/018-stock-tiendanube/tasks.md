# Tasks: Sincronización de stock y precios del CRM hacia Tiendanube

**Spec**: [spec.md](./spec.md) · **Plan**: [plan.md](./plan.md) · **Datos**: [data-model.md](./data-model.md) · **Contratos**: [contracts/rutas-internas.md](./contracts/rutas-internas.md) · **Validación**: [quickstart.md](./quickstart.md)

**Branch**: `018-stock-tiendanube` · **Fecha**: 2026-07-29 (ampliado 2026-07-30 con precios — US5 a US9, T034 en adelante)

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

- [X] T001 Confirmar que no hace falta ninguna dependencia nueva (Composer/NPM): toda la infraestructura
  de transporte, reintentos y kill-switch ya existe desde la spec 015 (`ClienteTiendanube`) y la 017
  (plan.md §Technical Context); confirmar además que `specs/017-ventas-tiendanube/` ya está implementada
  en código (ver advertencia arriba) antes de continuar

---

## Phase 2 — Foundational (bloquea todas las historias)

- [X] T002 [P] Migración `add_stock_fields_to_tn_variante_producto_table` con `tn_product_id` (string(50)
  — **no** existe en el esquema de la spec 017, que sólo captura `variant_id`; la agrega esta migración),
  `stock_pendiente` (boolean, default false), `stock_sincronizado_en` (timestamp nullable), `stock_error`
  (string(255) nullable), `stock_error_en` (timestamp nullable) — data-model.md §`tn_variante_producto`
- [X] T003 [P] Migración `add_stock_fields_to_tn_configuracion_table` con `stock_ultima_sync_en`
  (timestamp nullable) y `stock_ultima_sync_resultado` (string(255) nullable) — data-model.md
  §`tn_configuracion`
- [X] T004 [P] Extender `app/Models/Integraciones/TiendanubeVarianteProducto.php`: agregar las 5 columnas
  nuevas de T002 (`tn_product_id` incluido) a `$fillable`, castear los timestamps, y un scope
  `pendientes()` que filtre `stock_pendiente = true`
- [X] T005 [P] Extender `app/Models/Integraciones/TiendanubeConfiguracion.php`: agregar
  `stock_ultima_sync_en`/`stock_ultima_sync_resultado` a `$fillable` con el cast de datetime
  correspondiente
- [X] T006 Confirmar que `TiendanubeConfiguracion::depositoEfectivo(): Deposito` ya existe (planeado por
  spec 017, calcado de `MercadoLibreConfiguracion::depositoEfectivo()`); si por algún motivo no se
  implementó ahí, agregarlo acá con la misma regla (depósito configurado si existe y está activo; si no,
  el primero activo por orden de alta) — **no** duplicar la resolución de depósito en un método propio de
  esta spec
- [X] T007 [P] Test de regresión en `tests/Feature/Ingresos/VentaTiendanubeStockTest.php` (spec 017, si ya
  existe) o nuevo si no: confirmar que T004/T005 no cambian el depósito que usan las Ventas de Tiendanube
  ni las manuales ni las de Mercado Libre

**Checkpoint**: esquema y resolución de depósito listos — las historias de usuario pueden empezar.

---

## Phase 3 — US1: Que una Venta cargada a mano se refleje en Tiendanube (P1) 🎯 MVP

**Objetivo**: cerrar el riesgo de sobreventa para el caso base. **Test independiente**: cargar una Venta
manual sobre un producto vinculado, forzar la sincronización y comprobar que la cantidad disponible en
Tiendanube bajó lo mismo que vendió el CRM.

- [X] T008 [US1] Extender `app/Observers/MovimientoStockObserver.php` (existente desde la spec 013, con
  rama Mercado Libre) agregando una rama Tiendanube en `created(MovimientoStock $movimiento)`: resuelve
  `TiendanubeConfiguracion::actual()->depositoEfectivo()`, descarta el movimiento si no es de ese depósito
  (FR-001), busca `TiendanubeVarianteProducto::where('producto_id', ...)->first()` y descarta si no hay
  vínculo (FR-005); si pasa ambos filtros, `update(['stock_pendiente' => true])`. Ambas ramas (ML y
  Tiendanube) son independientes y conviven en el mismo método sin interferirse
- [X] T009 [US1] Crear `app/Services/Tiendanube/SincronizadorStock.php` con candado propio
  (`Cache::lock('tn:sincronizar_stock', 300)`, FR-008, independiente del candado de órdenes de Tiendanube
  y del de stock de Mercado Libre) y los cortes previos al bucle —función desactivada, modo sólo lectura,
  conexión caída— con un único registro en el historial (FR-009, FR-010), mismo esqueleto que
  `SincronizadorOrdenes::verificarCortes()` de Tiendanube (spec 017)
- [X] T010 [US1] Implementar en `SincronizadorStock` la iteración de
  `TiendanubeVarianteProducto::pendientes()->get()`: por cada vínculo, si `tn_product_id` está vacío,
  marcarlo con `stock_error = "Vínculo incompleto: falta el producto de Tiendanube"` y excluirlo del lote
  sin llamar a la API (FR-005a); si está completo, calcular
  `max(0, StockService::disponibilidad($producto, null, $depositoTn))` (FR-003, FR-004) y armar
  `['product_id' => $vinculo->tn_product_id, 'variant_id' => $vinculo->variant_id, 'stock' => $cantidad]`.
  **Corrección post-019**: no se llama a `ClienteTiendanube` por vínculo — se agrupan los vínculos
  resueltos en `array_chunk($actualizaciones, 50)` (límite real de la tool, research.md R6) y se llama a
  `ClienteTiendanube::escribir('update_stock_and_price', ['updates' => $lote])` una vez por chunk
  (FR-012, research.md R6)
- [X] T011 [US1] Implementar en `SincronizadorStock` la interpretación de la respuesta de cada chunk, por
  vínculo: éxito → `stock_pendiente = false`, `stock_sincronizado_en = now()`, limpiar
  `stock_error`/`stock_error_en`; fallo de un ítem puntual → `stock_error`/`stock_error_en` en **ese**
  vínculo, dejar `stock_pendiente = true`, y **continuar con el resto** del chunk y con los chunks
  siguientes sin cortar la corrida (FR-014, FR-015)
- [X] T012 [US1] Crear `app/Console/Commands/SincronizarStockTiendanube.php` con `--forzar`, comparando
  `stock_ultima_sync_en` contra `frecuencia_sync_minutos` (mismo campo reutilizado, mismo patrón que
  `SincronizarOrdenesTiendanube` de la spec 017) (FR-006)
- [X] T013 [US1] Registrar `tiendanube:sincronizar-stock` en `bootstrap/app.php` con
  `everyMinute()->withoutOverlapping()`, **después** de `tiendanube:sincronizar-ordenes` en el mismo
  `withSchedule()` (research.md R4)
- [X] T014 [P] [US1] Test en `tests/Feature/Integraciones/TiendanubeMovimientoStockObserverTest.php`: una
  Venta manual sobre un producto vinculado a Tiendanube marca el vínculo pendiente; un movimiento en otro
  depósito no lo marca; un producto sin vínculo no marca nada; marcar el vínculo de Tiendanube no
  interfiere con el de Mercado Libre del mismo producto si lo tuviera (FR-001, FR-005)
- [X] T015 [P] [US1] Test en `tests/Feature/Integraciones/TiendanubeSincronizadorStockTest.php` con
  `Http::fake()`: varias Ventas seguidas sobre el mismo producto generan **una única** entrada en el
  `updates` del lote enviado a `update_stock_and_price`, con el valor final (corrección post-019: no
  `POST /products/.../variants/stock`) (FR-003, SC-003); stock negativo se envía como 0 (FR-004, SC-004);
  éxito deja el vínculo sincronizado con fecha (FR-017); un vínculo con `tn_product_id` vacío se señala
  sin entrar al lote (FR-005a); con >50 vínculos pendientes, se envían **dos** llamadas a
  `update_stock_and_price` (research.md R6, corrección de loteo)

**Checkpoint**: una Venta manual ya actualiza Tiendanube de punta a punta (vía `--forzar` o esperando la
frecuencia configurada).

---

## Phase 4 — US2: Que una orden de Tiendanube no rebote (P1)

**Objetivo**: cerrar la ventana de bucle. **Test independiente**: convertir una orden de Tiendanube en
Venta (spec 017) y comprobar que **no** se genera ningún envío de stock por ese movimiento.

- [X] T016 [US2] Extender la rama Tiendanube de `MovimientoStockObserver::created()` con la exclusión de
  bucle: si `$movimiento->origen_type === Venta::class`, cargar la Venta y, si `$venta->origen ===
  'tiendanube'`, no marcar el vínculo como pendiente (FR-002)
- [X] T017 [P] [US2] Test en `TiendanubeMovimientoStockObserverTest.php`: convertir una orden de
  Tiendanube en Venta (spec 017, `ConversorOrdenAVenta`) **no** marca pendiente el vínculo de esa variante;
  una Venta manual sobre el mismo producto sí lo marca (regresión de US1) (FR-002, SC-002)
- [X] T018 [US2] Test de integración en `tests/Feature/Integraciones/TiendanubeOrdenEjecucionTest.php`:
  ejecutar `tiendanube:sincronizar-ordenes --forzar` seguido de `tiendanube:sincronizar-stock --forzar` y
  verificar que el segundo refleja el stock ya neto de lo que trajo el primero, sin necesitar una segunda
  corrida (FR-006, research.md R4)

**Checkpoint**: US1 + US2 juntas son el cierre real del riesgo de sobreventa — ninguna orden de Tiendanube
genera un envío redundante, y toda Venta manual sí dispara uno.

---

## Phase 5 — US3: Forzar la sincronización de stock manualmente (P2)

**Objetivo**: control inmediato sin esperar el intervalo programado. **Test independiente**: con vínculos
pendientes, presionar "Sincronizar stock ahora" y verlos actualizados sin recargar la página.

- [X] T019 [US3] Agregar la acción `sincronizarStock` a
  `app/Http/Controllers/Ingresos/TiendanubeVentaController.php` según contracts §1 (invoca
  `SincronizadorStock`, devuelve `{ok, mensaje, actualizados, con_error}`)
- [X] T020 [US3] Registrar `POST ingresos/tiendanube/sincronizar-stock` en `routes/web.php`, dentro del
  grupo ya existente con permiso `ventas.ver` y guard de función activa
- [X] T021 [US3] Extender `resources/js/tiendanube-ventas.js` con el botón "Sincronizar stock ahora"
  (AJAX, Toastr, sin recarga — mismo patrón que "Sincronizar ahora") (FR-007, SC-007)
- [X] T022 [US3] Agregar el botón a `resources/views/ingresos/tiendanube/index.blade.php`, junto al de
  "Sincronizar ahora" de órdenes
- [X] T023 [P] [US3] Test en `tests/Feature/Integraciones/TiendanubeSincronizarStockTest.php`: la acción
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

- [X] T024 [US4] Extender `TiendanubeVinculacionController::datatable()` con las columnas derivadas
  `stock_estado` (`sincronizado`/`pendiente`/`error`), `stock_sincronizado_en` y `stock_error` según
  contracts §2 (FR-017)
- [X] T025 [US4] Extender `resources/views/ingresos/tiendanube/vinculaciones.blade.php` con las columnas
  nuevas, mostrando motivo y fecha del error en un tooltip (FR-017)
- [X] T026 [US4] Mostrar `stock_ultima_sync_en`/`stock_ultima_sync_resultado` en
  `resources/views/configuracion/tiendanube/index.blade.php`, junto al panel ya existente de la
  sincronización de órdenes (FR-019)
- [X] T027 [P] [US4] Test en `TiendanubeSincronizadorStockTest.php`: el rechazo de un vínculo (producto
  eliminado, simulado con `Http::fake()` devolviendo un resultado de fallo para ese ítem dentro de una
  respuesta de `update_stock_and_price` — formato asumido por analogía con `bulk_delete_products`,
  research.md R6, a confirmar en T032a) no interrumpe el envío del resto de los vínculos del mismo chunk
  ni de los chunks siguientes, y deja ese vínculo con `stock_error` y `stock_pendiente = true` para
  reintentar (FR-014, FR-015, SC-006)
- [X] T028 [P] [US4] Test de reintento ante 429/5xx: `SincronizadorStock` no implementa reintento propio
  —ya lo cubre `ClienteTiendanube::ejecutarConReintentos()` (research.md R7)— verificar con `Http::fake()`
  una secuencia 429→200 que el vínculo termina sincronizado sin marcarlo como error (FR-013)

**Checkpoint**: el usuario puede confiar en la pantalla de vinculación de variantes para saber qué está
realmente sincronizado, sin tener que entrar a Tiendanube a comprobarlo.

---

## Phase 7 — US5: Configurar la Lista de Precios que gestiona Tiendanube (P1)

**Objetivo**: prerrequisito de todo el flujo de precios. **Test independiente**: configurar una Lista de
Precios en Tiendanube, guardar, recargar y verificar que persiste.

- [X] T034 [P] Migración `add_lista_precio_field_to_tn_configuracion_table` con `lista_precio_id`
  (unsignedBigInteger, nullable, FK → `listas_precio.id`, `nullOnDelete()`) — data-model.md
  §`tn_configuracion` (precio)
- [X] T035 [P] Migración `add_precio_fields_to_tn_variante_producto_table` con `precio_pendiente`
  (boolean, default false), `precio_sincronizado_en` (timestamp nullable), `precio_error` (string(255)
  nullable), `precio_error_en` (timestamp nullable), `after('stock_error_en')` — data-model.md
  §`tn_variante_producto` (precio)
- [X] T036 [P] Extender `app/Models/Integraciones/TiendanubeConfiguracion.php`: agregar `lista_precio_id`
  a `$fillable` y la relación `listaPrecio(): BelongsTo`
- [X] T037 [P] Extender `app/Models/Integraciones/TiendanubeVarianteProducto.php`: agregar las 4 columnas
  de T035 a `$fillable`, castear timestamps, y un scope `pendientesPrecio()` que filtre
  `precio_pendiente = true`
- [X] T038 [US5] Extender `app/Http/Requests/Integraciones/GuardarConfiguracionVentasTiendanubeRequest.php`
  con `'lista_precio_id' => ['nullable', 'exists:listas_precio,id']` (FR-021/FR-022/FR-023)
- [X] T039 [US5] Extender `TiendanubeConfiguracionController::index()` para pasar `$listasPrecio =
  ListaPrecio::where('activo', true)->orderBy('nombre')->get()` a la vista
- [X] T040 [US5] Extender `resources/views/configuracion/tiendanube/index.blade.php` con el `<select>`
  Lista de Precios (Select2, regla obligatoria del proyecto), junto a Depósito/Categoría/Cuenta de
  Tesorería ya existentes
- [X] T041 [US5] Extender `resources/js/tiendanube.js`: leer/guardar `lista_precio_id` en el
  formulario de configuración de ventas
- [X] T042 [P] [US5] Test de configuración: guardar y persistir `lista_precio_id`; rechazar un valor que
  no exista; permitir guardar sin ninguna seleccionada (FR-021/FR-022/FR-023)

**Checkpoint**: campo de configuración listo — el resto de las historias de precio dependen de él.

---

## Phase 8 — US6: Que un cambio de precio en esa lista se refleje solo en Tiendanube (P1)

**Objetivo**: motivo de ser de la ampliación. **Test independiente**: con la Lista de Precios configurada
y un producto vinculado, cambiar su precio en esa lista y verificar que Tiendanube lo recibe sin acción
manual.

- [X] T043 [US6] Extender `app/Observers/PrecioProductoObserver.php` (existente desde la spec 016, con
  rama Mercado Libre) agregando una rama Tiendanube en `saved(PrecioProducto $precio)`: si
  `$precio->lista_precio_id === TiendanubeConfiguracion::actual()->lista_precio_id` (no null) y existe
  `TiendanubeVarianteProducto::where('producto_id', $precio->producto_id)->first()`, registra
  `DB::afterCommit()` que marca `precio_pendiente = true` y llama a
  `app(Tiendanube\SincronizadorPrecios::class)->enviarUno($vinculo, (float) $precio->precio)` — rama
  independiente de la de Mercado Libre (FR-024, FR-026, FR-027)
- [X] T044 [US6] Crear `app/Services/Tiendanube/SincronizadorPrecios.php` con `enviarUno(TiendanubeVarianteProducto
  $vinculo, float $precio): bool` — marca `precio_pendiente = true` incondicionalmente antes de evaluar
  cortes (mismo criterio que la spec 016 R4), aplica los cortes de función desactivada/sólo lectura/
  conexión caída con un único registro si bloquea, y si no, llama a
  `ClienteTiendanube::escribir('update_stock_and_price', ['updates' => [['product_id' => $vinculo->tn_product_id,
  'variant_id' => $vinculo->variant_id, 'price' => $precio]]])` (FR-029, un solo ítem, sin lote — distinto
  de `SincronizadorStock`)
- [X] T045 [US6] Implementar en `SincronizadorPrecios::enviarUno()` la interpretación del resultado: éxito
  → `precio_pendiente = false`, `precio_sincronizado_en = now()`, limpiar `precio_error`/`precio_error_en`;
  rechazo no transitorio → `precio_error`/`precio_error_en`, mantener `precio_pendiente = true` (FR-031)
- [X] T046 [P] [US6] Test en `tests/Feature/Integraciones/TiendanubePrecioProductoObserverTest.php`: un
  cambio de precio en la lista configurada, sobre un producto vinculado, dispara el envío; un cambio en
  otra lista, o sobre un producto sin vínculo, no dispara nada; sin ninguna lista configurada, tampoco
  (FR-024, FR-026)
- [X] T047 [P] [US6] Test de disparo por importación masiva (`ImportadorFilas.php`) sobre un producto
  vinculado, dentro de la lista configurada: mismo resultado que la edición manual (FR-025)

**Checkpoint**: US5 + US6 — flujo automático de precio operativo de punta a punta.

---

## Phase 9 — US7: Sincronizar precios manualmente (P2)

**Objetivo**: red de seguridad del flujo automático. **Test independiente**: provocar una falla, presionar
"Sincronizar precios ahora" en Productos, verificar que se reintenta.

- [X] T048 [US7] Agregar la acción `sincronizarPrecios` a
  `app/Http/Controllers/Ingresos/TiendanubeVentaController.php` (invoca
  `Tiendanube\SincronizadorPrecios::ejecutar()`, candado propio `Cache::lock('tn:sincronizar_precios', 300)`)
  según contracts §1a — **ruta y controlador propios de Tiendanube, no se toca
  `MercadoLibreVentaController`** (research.md R10)
- [X] T049 [US7] Registrar `POST productos/sincronizar-precios-tn` → `productos.sincronizarPreciosTn` en
  `routes/web.php`
- [X] T050 [US7] Extender `resources/js/productos.js`: el handler del botón "Sincronizar precios ahora"
  (ya existente, spec 016) dispara **también** la request a `productos.sincronizarPreciosTn` (en paralelo
  con la de `productos.sincronizarPreciosMl`) y combina ambos resultados en un único toast (research.md
  R10) — el botón sigue siendo uno solo en la vista
- [X] T051 [P] [US7] Test en `tests/Feature/Integraciones/TiendanubeSincronizarPreciosTest.php`: la acción
  devuelve los contadores esperados; dos disparos simultáneos sólo ejecutan uno (FR-036); bloqueada con un
  único registro bajo modo sólo lectura, función desactivada, y conexión caída (FR-032/FR-033); sin Lista
  de Precios configurada, responde 409 con el motivo (contracts §1a)

**Checkpoint**: control manual de precios disponible, igual que ya lo tiene stock.

---

## Phase 10 — US8: Enterarse cuando Tiendanube rechaza una actualización de precio (P2)

**Objetivo**: visibilidad del estado real. **Test independiente**: despublicar el producto de prueba,
cambiar su precio, sincronizar, y verificar que queda señalado sin afectar al resto.

- [X] T052 [US8] Extender `TiendanubeVinculacionController::datatable()` con las columnas derivadas
  `precio_estado` (`sincronizado`/`pendiente`/`error`, vía un método `precioEstado()` análogo a
  `stockEstado()`), `precio_sincronizado_en` y `precio_error` según contracts §2 (FR-038)
- [X] T053 [US8] Extender `resources/views/ingresos/tiendanube/vinculaciones.blade.php` con las columnas
  de precio, mostrando motivo y fecha del error en un tooltip (FR-038)
- [X] T054 [P] [US8] Test en `TiendanubeSincronizarPreciosTest.php` (o archivo dedicado): el rechazo de un
  vínculo (variante despublicada, simulado con `Http::fake()`) no interrumpe otros envíos de precio, deja
  ese vínculo con `precio_error` y `precio_pendiente = true` para reintentar (FR-031, SC-012)

**Checkpoint**: la pantalla de vinculación de variantes ahora muestra el estado real de stock **y**
precio, sin tener que entrar a Tiendanube.

---

## Phase 11 — US9: Cambiar la Lista de Precios configurada actualiza Tiendanube de una vez (P2)

**Objetivo**: evitar inconsistencia silenciosa al cambiar de lista. **Test independiente**: con productos
vinculados con precio en dos listas distintas, cambiar la lista configurada y verificar el push inmediato.

- [X] T055 [US9] Implementar en `SincronizadorPrecios` el método `sincronizarListaCompleta(int
  $listaPrecioId): array` — mismo chequeo de cortes previo que `ejecutar()` (único registro si bloqueado,
  sin iterar ningún vínculo en ese caso); si no bloqueado, recorre
  `TiendanubeVarianteProducto::with('producto')->get()`, resuelve el precio de cada producto en
  `$listaPrecioId` y llama a `enviarUno()` para los que tengan precio cargado ahí (FR-028)
- [X] T056 [US9] Extender `TiendanubeConfiguracionController::guardarVentas()`: detectar si
  `lista_precio_id` cambió (comparar el valor previo antes del `update()`) y, si cambió y el nuevo valor no
  es `null`, llamar a `SincronizadorPrecios::sincronizarListaCompleta($nuevoValor)` después de persistir
  (FR-028, contracts §2a)
- [X] T057 [P] [US9] Test: cambiar la Lista de Precios configurada empuja de inmediato el precio vigente a
  todos los productos vinculados con precio en la nueva lista; un producto vinculado sin precio en la
  nueva lista no se sincroniza y no queda marcado como error; con modo sólo lectura activo, la
  configuración se guarda pero el push no se ejecuta, quedando pendiente (FR-028, SC-013)

**Checkpoint**: las 5 historias de precio (US5-US9) completas — paridad funcional con la spec 016 de
Mercado Libre.

---

## Phase 12 — Polish y transversales

- [X] T029 [P] Verificar que ningún dato sensible se agrega al historial de operaciones por esta spec —
  reutiliza el saneado ya existente de `ClienteTiendanube::registrarLog()` (spec 019), sin lógica propia
  que loguear
- [X] T030 [P] Actualizar `CREDENCIALES_ACCESO.txt` si alguna prueba manual de esta spec cambia un acceso
  (regla de `CLAUDE.md`) — no aplica si toda la prueba se hace con `Http::fake()`/factories
- [X] T031 [P] Verificar la portabilidad (FR-011) ejecutando la suite de esta spec con el almacén de caché
  de archivos y con el de base de datos, extendiendo el mismo test de portabilidad ya usado para
  `SincronizadorStock` de Mercado Libre (spec 013, T031a) para cubrir también el candado de
  `SincronizadorStock` de Tiendanube
- [ ] T032a **NUEVO (post-019) — requiere la tienda real conectada**: confirmar contra una llamada real a
  `update_stock_and_price` con un lote que mezcle un `product_id` válido y uno inválido, el formato
  exacto de la respuesta (¿resultado por ítem, como se asume por analogía con `bulk_delete_products`, o
  falla el lote entero?). Ajustar `T011`/`SincronizadorStock` si el formato real difiere del asumido —
  bloqueante antes de dar esta spec por verificada de punta a punta (research.md R6, plan.md nota de
  verificación pendiente)
- [ ] T032 **PENDIENTE — requiere la tienda real conectada**: confirmar si `update_stock_and_price` tiene
  un límite de tasa efectivo (no documentado públicamente, research.md R6 corregido); ajustar
  `ESPERAS_SEGUNDOS` de `ClienteTiendanube` si hiciera falta un valor específico para esta tool
- [ ] T033 **PENDIENTE — requiere la tienda real y un navegador**: recorrer `quickstart.md` de punta a
  punta con una variante real, confirmando que la cantidad disponible en Tiendanube baja exactamente lo
  que indica el CRM
- [X] T058 [P] Test de regresión crítico (FR-039/FR-040): convertir una orden de Tiendanube en Venta
  (spec 017) con la Lista de Precios de Tiendanube configurada y con precio cargado para ese producto en
  esa lista — confirmar que el total/precio de línea de la Venta sigue derivándose 100% del importe
  pagado en la orden (no de la Lista de Precios) y que la Venta **no** queda con `lista_precio_id`
  asignado
- [ ] T059 **PENDIENTE — requiere la tienda real y un navegador**: recorrer los Escenarios 6-9 de
  `quickstart.md` (precios) de punta a punta con el producto de prueba oculto, confirmando el push
  inmediato por evento y el push completo al cambiar de lista

---

## Cobertura heredada (requisitos sin tarea propia, por diseño)

Estos requisitos **no** tienen tarea porque ya los satisface infraestructura existente y verificada de
las specs 015/017. Duplicarlos sería reimplementar lógica crítica ya probada (research.md R7).

| Requisito | Quién lo cubre | Verificación |
|---|---|---|
| FR-013 — espera creciente ante rechazos por límite de tasa | `ClienteTiendanube::ejecutarConReintentos()`, sin código propio | T028 |
| FR-016 — registrar cada envío en el historial de operaciones | `ClienteTiendanube::registrarLog()`, disparado en cada `enviar()`, sin código propio | T029 |
| FR-020 — sin tabla de historial propia | Requisito negativo: no se construye ninguna tabla nueva de eventos (research.md R3) | Revisión en T002 |
| FR-030 — espera creciente ante rechazos de precio (ampliación) | Mismo `ClienteTiendanube::ejecutarConReintentos()`, sin código propio — `enviarUno()` llama a `escribir()` igual que el flujo de stock | T046/T047 (ejercitan el mismo camino de envío) |
| FR-034 — registrar cada envío de precio en el historial (ampliación) | `ClienteTiendanube::registrarLog()`, mismo mecanismo que FR-016, sin código propio | T046/T047 |

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
US1 (T008-T015)  ─── MVP stock: cierra el caso base del riesgo de sobreventa
   ↓
US2 (T016-T018)  ← requiere US1 (extiende el mismo Observer)
   ↓
US3 (T019-T023)  ← requiere US1 (reutiliza SincronizadorStock)
   ↓
US4 (T024-T028)  ← requiere US1 (expone el estado que US1 ya persiste)
   ↓
US5 (T034-T042)  ← sólo requiere Foundational — independiente de US1-US4 en el código (ampliación, precio)
   ↓
US6 (T043-T047)  ← requiere US5 (necesita lista_precio_id configurado para tener efecto real)
   ↓
US7 (T048-T051)  ← requiere US6 (reutiliza SincronizadorPrecios)
   ↓
US8 (T052-T054)  ← requiere US6 (expone el estado que US6 ya persiste)
   ↓
US9 (T055-T057)  ← requiere US5 y US6
   ↓
Polish (T029-T033, T058-T059)
```

**US3 y US4 pueden desarrollarse en paralelo** — ambas sólo dependen de US1, no entre sí.
**US5 puede desarrollarse en paralelo con US1-US4** — la configuración de precio no depende del flujo de
stock (comparten sólo la Fase 2 Foundational). **US7 y US8 pueden desarrollarse en paralelo** una vez
completa US6, igual que US3/US4 para stock.

## Oportunidades de paralelización

- **Fase 2**: T002-T005 son `[P]` (migraciones y modelos, archivos distintos).
- **Tests**: casi todos son `[P]` entre sí — distinto archivo, sin estado compartido.
- **Fases 5 y 6** (US3 y US4) pueden avanzar en paralelo una vez completa US1.
- **Fase 7** (US5) puede avanzar en paralelo con las fases 3-6 (stock) — no comparten archivos salvo
  `TiendanubeConfiguracionController`/vista de configuración, donde los cambios son bloques
  independientes (stock vs. precio).
- **Fases 9 y 10** (US7 y US8) pueden avanzar en paralelo una vez completa US6.

## Estrategia de implementación

**MVP sugerido**: Fases 1-4 (hasta T018). Cierra el riesgo de sobreventa de punta a punta —marcar,
consolidar, enviar y no rebotar— con visibilidad mínima vía `--forzar` y logs, sin las pantallas de
comodidad (US3/US4) ni el flujo de precios (US5-US9).

**Entrega completa de stock**: Fases 1-6 (hasta T028). Agrega el control manual y la visibilidad de
errores que hacen el módulo confiable de usar sin mirar los logs del servidor.

**Entrega completa (stock + precios)**: Fases 1-11 (hasta T057), más Polish (T029-T033, T058-T059).
Paridad funcional completa con Mercado Libre (specs 012/013/016) para la integración de Tiendanube.
