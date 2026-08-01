# Tasks: Migración de la integración Tiendanube del servidor MCP a la Application REST clásica

**Spec**: [spec.md](./spec.md) · **Plan**: [plan.md](./plan.md) · **Datos**: [data-model.md](./data-model.md)
· **Contratos**: [contracts/api-tiendanube-rest.md](./contracts/api-tiendanube-rest.md),
[contracts/rutas-internas.md](./contracts/rutas-internas.md) · **Validación**: [quickstart.md](./quickstart.md)

**Branch**: `024-tiendanube-migracion-rest` · **Fecha**: 2026-07-31

**Tests**: incluidos y **obligatorios** — mismo criterio que specs 017/018/021/023 (plan.md §Constitution
Check, principio IV): esta migración toca qué producto se descuenta de stock al convertir órdenes y qué
Venta se crea automáticamente.

**Convención**: `[P]` = paralelizable (archivo distinto, sin dependencias pendientes). `[USn]` = historia de
usuario a la que pertenece.

---

## Phase 1 — Setup

- [ ] T001 Confirmar que no hace falta ninguna dependencia nueva de Composer: `Illuminate\Support\Facades\Http`
  ya se usa en `VerificadorConexionRest` y alcanza para `ClienteTiendanubeRest` (plan.md §Technical Context)
- [ ] T002 Confirmar en `config/integraciones.php` que `client_id`/`client_secret` de la Application REST
  (spec 022) ya están cargados — esta spec no agrega credenciales nuevas, sólo consume la conexión existente

---

## Phase 2 — Foundational (bloquea las 3 historias)

- [ ] T003 [P] Migración `database/migrations/2026_XX_XX_add_configuracion_negocio_to_tn_conexion_rest_table.php`:
  agrega a `tn_conexion_rest` las columnas de data-model.md §1 (`modo_solo_lectura` boolean default false,
  `creacion_automatica` boolean default false, `frecuencia_sync_minutos` integer nullable, `deposito_id`
  foreignId nullable → `depositos` nullOnDelete, `categoria_venta_id` foreignId nullable → `categorias`
  nullOnDelete, `cuenta_tesoreria_id` foreignId nullable → `cuentas_tesoreria` nullOnDelete,
  `dias_primera_sync` integer nullable, `ultima_sync_en` timestamp nullable, `ultima_sync_resultado` string
  nullable, `stock_ultima_sync_en` timestamp nullable, `stock_ultima_sync_resultado` string nullable,
  `lista_precio_id` foreignId nullable → `listas_precio` nullOnDelete, `vendedor_id` foreignId nullable →
  `vendedores` nullOnDelete)
- [ ] T004 Migración de datos `database/migrations/2026_XX_XX_migrar_configuracion_tn_configuracion_a_tn_conexion_rest.php`:
  en `up()`, si existe una fila en `tn_configuracion`, copiar los 12 campos de T003 a la fila única de
  `tn_conexion_rest` (creándola si no existe, `TiendanubeConexionRest::actual()`) — **idempotente**:
  sobreescritura directa de columnas por `id=1`, no inserción (spec.md Assumptions); `down()` no revierte el
  copiado (no destructivo, sólo deja de tener sentido si `tn_configuracion` ya no existe) — depende de T003
- [ ] T005 [P] Editar `App\Models\Integraciones\TiendanubeConexionRest`
  (`app/Models/Integraciones/TiendanubeConexionRest.php`): agregar los 12 campos de T003 a `$fillable`, sus
  casts (`boolean`/`datetime`), y los métodos `deposito()`, `categoriaVenta()`, `cuentaTesoreria()`,
  `vendedor()`, `listaPrecio()`, `depositoEfectivo()`/`depositoEfectivoONulo()` (copiados tal cual desde
  `TiendanubeConfiguracion`, data-model.md §1) — depende de T003
- [ ] T006 [US1,US2] Crear `App\Services\Tiendanube\ClienteTiendanubeRest`
  (`app/Services/Tiendanube/ClienteTiendanubeRest.php`): mismo esquema de reintentos/backoff que
  `VerificadorConexionRest` (1/2/4s, hasta 3 intentos en 429/5xx, `Retry-After`, 401/404 sin reintento →
  marca `TiendanubeConexionRest::actual()->estado = Caida`), mismos headers (`Authentication: bearer`,
  `User-Agent`); constructor sin dependencias de `TiendanubeConexionRest` (la resuelve internamente vía
  `actual()`, mismo patrón que `ClienteTiendanube`); métodos públicos `leer(string $recurso, array $query =
  []): RespuestaTiendanube` (GET) y `escribir(string $metodo, string $recurso, array $payload = []):
  RespuestaTiendanube` (POST/PUT); guards de función avanzada activa y `modo_solo_lectura` (leído de
  `TiendanubeConexionRest::actual()`, campo migrado en T003) antes de cualquier escritura; cada operación se
  registra en `TiendanubeRestOperacionLog::registrar()` (research.md R5) — depende de T005
- [ ] T007 [P] Crear `App\Services\Tiendanube\Excepciones\VinculacionAutomaticaFallidaException`
  (`app/Services/Tiendanube/Excepciones/VinculacionAutomaticaFallidaException.php`): excepción simple, mismo
  patrón que la homónima de `App\Services\MercadoLibre\Excepciones` (spec 023) — la lanza `VinculadorAutomatico`
  cuando el catálogo en vivo falla a mitad de la corrida

**Checkpoint**: Foundational listo — `ClienteTiendanubeRest` y la configuración de negocio migrada quedan
disponibles para las 3 historias.

---

## Phase 3 — User Story 1: Vincular un producto de Tiendanube que nunca vendió, por SKU directo (Priority: P1) 🎯 MVP

**Goal**: reemplazar tanto el selector manual (fuente: `tn_orden_items`) como la importación por Excel
(fuente: slug) por un único mecanismo de catálogo en vivo que compara `variants[].sku` contra `Producto::id`.

**Independent Test**: con una variante de Tiendanube cuyo SKU coincide con el `id` de un producto del CRM,
sin ningún pedido sincronizado que la mencione, apretar "Vincular automáticamente" y confirmar que el
vínculo se crea solo.

### Tests for User Story 1

- [ ] T008 [US1] Crear `tests/Feature/Integraciones/TiendanubeVinculacionAutomaticaTest.php` con
  `Http::fake()` sobre `api.tiendanube.com/v1/*/products` (paginado `page`/`per_page`, contracts/api-tiendanube-rest.md
  §2): SKU que matchea el `id` de un producto crea el vínculo **sin ningún pedido sincronizado** (Acceptance
  Scenario 1); SKU sin match deja la variante pendiente con motivo `producto_no_encontrado` (Scenario 2); dos
  variantes distintas con el mismo SKU — sólo la primera se vincula, la segunda `ya_vinculado` detalle
  `producto` (Scenario 3); variante sin `sku` cargado, motivo `sin_sku` (Edge Cases); producto con múltiples
  variantes evalúa cada una por separado, sin excluir el producto completo (Edge Cases, research.md R7);
  producto `status: closed` excluido, `paused` incluido igual que `published` (Edge Cases); producto
  inactivo del CRM se vincula igual (mismo criterio spec 021); reintentar la corrida no modifica lo ya
  vinculado (SC-004); recorrido de más de una página se agota completo antes de procesar (FR-007); si
  `ClienteTiendanubeRest::leer()` devuelve `fallo()` en cualquier página, la corrida lanza
  `VinculacionAutomaticaFallidaException` sin crear ningún vínculo (spec.md Edge Cases)

### Implementation for User Story 1

- [ ] T009 [US1] Crear `App\Services\Tiendanube\VinculadorAutomatico`
  (`app/Services/Tiendanube/VinculadorAutomatico.php`): constructor con `ClienteTiendanubeRest` inyectado;
  `ejecutar(?User $usuario): array` — excluir variantes ya vinculadas (`TiendanubeVarianteProducto::pluck('variant_id')`);
  recorrer `leer('products', ['page' => $p, 'per_page' => 50])` hasta página con menos de 50 resultados
  (research.md R1); si `fallo()` en cualquier página, lanzar `VinculacionAutomaticaFallidaException` y
  abortar sin crear nada (depende de T007); por cada producto con `status !== 'closed'`, por cada
  `variants[]`: tomar `sku` directo (research.md R2, sin llamada de detalle adicional); mismo flujo de
  validación/creación que spec 021 (`sin_sku`, `Producto::find((int) $sku)` sin excluir inactivos,
  `ya_vinculado` con detalle `sku`/`producto`, crear `TiendanubeVarianteProducto` con `variant_id`,
  `tn_product_id`, `producto_id`, `vinculada_por`); mismo formato de resumen
  (`total`/`vinculadas`/`fallidas`/`detalle_fallidas`) — depende de T006, T007
- [ ] T010 [US1] Editar `App\Http\Controllers\Ingresos\TiendanubeVinculacionController`
  (`app/Http/Controllers/Ingresos/TiendanubeVinculacionController.php`): agregar
  `vincularAutomaticamente(Request $request, VinculadorAutomatico $vinculador): JsonResponse` (mismo patrón
  que `MercadoLibreVinculacionController::vincularAutomaticamente()`, catch de
  `VinculacionAutomaticaFallidaException` → JSON `{"ok": false, "mensaje": ...}` 502); eliminar `store()`,
  `importar()`, `variantesPendientes()` (contracts/rutas-internas.md §1) — depende de T009
- [ ] T011 [US1] Eliminar `App\Services\Tiendanube\ImportadorVinculaciones`
  (`app/Services/Tiendanube/ImportadorVinculaciones.php`) y
  `App\Http\Requests\Integraciones\ImportarVinculacionesTiendanubeRequest` — reemplazados por T009
- [ ] T012 [US1] Editar `routes/web.php` (grupo `ingresos/tiendanube/vinculaciones`, líneas ~212-219):
  eliminar rutas `pendientes`, `store` (`POST /`), `importar`; agregar
  `Route::post('vincular-automaticamente', [TiendanubeVinculacionController::class, 'vincularAutomaticamente'])->name('vincularAutomaticamente')`
  (contracts/rutas-internas.md §1) — depende de T010
- [ ] T013 [US1] Editar `resources/views/ingresos/tiendanube/vinculaciones.blade.php` (y su JS asociado):
  reemplazar el selector con buscador + botón de importación por Excel por un único botón "Vincular
  automáticamente" que llama a la ruta de T012 y refresca el DataTable con el resumen (mismo patrón visual
  que la vista equivalente de Mercado Libre, spec 023) — depende de T012
- [ ] T014 [US1] Eliminar tests obsoletos que cubrían el mecanismo retirado: buscar y borrar/reescribir
  cualquier test de `variantesPendientes()`/`store()`/`ImportadorVinculaciones` en
  `tests/Feature/Integraciones/` (ej. tests de importación por Excel de spec 021) que ya no aplican tras
  T009-T011

**Checkpoint**: Historia 1 completa y testeable de forma independiente — botón "Vincular automáticamente"
vincula variantes sin depender de que hayan vendido, usando el catálogo REST en vivo.

---

## Phase 4 — User Story 2: Sincronizar pedidos y stock/precio sin depender del servidor MCP (Priority: P1)

**Goal**: `SincronizadorOrdenes`, `SincronizadorStock`, `SincronizadorPrecios` pasan a usar
`ClienteTiendanubeRest` en vez de `ClienteTiendanube` (MCP), con el mismo comportamiento observable.

**Independent Test**: con la conexión REST activa y la MCP desconectada manualmente, correr el cronjob de
órdenes y el de stock, y confirmar que ambos sincronizan contra la cuenta real igual que antes.

### Tests for User Story 2

- [ ] T015 [P] [US2] Reescribir/crear `tests/Feature/Integraciones/TiendanubeSincronizacionOrdenesRestTest.php`:
  `Http::fake()` sobre `GET api.tiendanube.com/v1/*/orders` (contracts/api-tiendanube-rest.md §3) — paginado
  hasta agotar, `storefront: meli` descartada (FR-012a heredado), upsert por `tn_order_id`, resolución de
  `producto_id` vía `TiendanubeVarianteProducto`, evaluación de convertibilidad y creación automática de
  Venta sin cambios de comportamiento respecto de la versión MCP; cortes por función desactivada/modo sólo
  lectura/conexión caída leídos ahora de `TiendanubeConexionRest`
- [ ] T016 [P] [US2] Reescribir/crear `tests/Feature/Integraciones/TiendanubeSincronizacionStockRestTest.php`:
  `Http::fake()` sobre `PUT api.tiendanube.com/v1/*/products/*/variants/*` (contracts/api-tiendanube-rest.md
  §4) — una request por vínculo pendiente (sin batch, research.md R4), continuidad ante el rechazo de un
  vínculo puntual (el resto del bucle sigue), vínculo incompleto (`tn_product_id` vacío) se señala sin
  llamar a la API
- [ ] T017 [P] [US2] Reescribir/crear `tests/Feature/Integraciones/TiendanubeSincronizacionPreciosRestTest.php`:
  `Http::fake()` sobre el mismo endpoint `PUT .../variants/{id}` con `{"price": ...}` — disparo por evento
  (`PrecioProductoObserver`) y manual (`ejecutar()`/`sincronizarListaCompleta()`) sin cambios de flujo

### Implementation for User Story 2

- [ ] T018 [US2] Editar `App\Services\Tiendanube\SincronizadorOrdenes`
  (`app/Services/Tiendanube/SincronizadorOrdenes.php`): reemplazar dependencia `ClienteTiendanube` por
  `ClienteTiendanubeRest`; `verificarCortes()` lee `TiendanubeConexionRest::actual()` en vez de
  `TiendanubeConfiguracion::actual()` (función activa, `modo_solo_lectura`, `estaCompleta()`/`estado`); en
  `sincronizar()`, `dias_primera_sync`/`ultima_sync_en`/`ultima_sync_resultado` se leen/escriben sobre
  `TiendanubeConexionRest` (campos migrados en T003); en `paginarYProcesar()`, reemplazar
  `$this->cliente->leer('list_orders', [...])` por `$this->clienteRest->leer('orders', ['page' => ...,
  'per_page' => 50, 'created_at_min' => ..., 'created_at_max' => ..., 'status' => [...]])`
  (contracts/api-tiendanube-rest.md §3); resto de `procesarOrden()` sin cambios — depende de T006, T015
- [ ] T019 [US2] Editar `App\Services\Tiendanube\SincronizadorStock`
  (`app/Services/Tiendanube/SincronizadorStock.php`): reemplazar dependencia y lectura de cortes igual que
  T018 (`stock_ultima_sync_en`/`stock_ultima_sync_resultado` sobre `TiendanubeConexionRest`); reemplazar
  `enviarLote()` (batch `update_stock_and_price`) por una iteración `PUT products/{tn_product_id}/variants/{variant_id}`
  con `{"stock": $cantidad}` por cada vínculo pendiente, aplicando el resultado directo a cada
  `TiendanubeVarianteProducto` (sin parseo de `datos['results']`, research.md R4); **cierra la pregunta
  abierta de plan.md §Enfoque técnico 3**: `TAMANO_LOTE`/`enviarLote()` se eliminan por completo — no hay
  agrupación de requests, cada vínculo pendiente dispara su propia `PUT` dentro del mismo `foreach` — depende
  de T006, T016
- [ ] T020 [US2] Editar `App\Services\Tiendanube\SincronizadorPrecios`
  (`app/Services/Tiendanube/SincronizadorPrecios.php`): reemplazar dependencia y lectura de cortes igual que
  T018; `enviarUno()` reemplaza la llamada a `update_stock_and_price` por `PUT products/{tn_product_id}/variants/{variant_id}`
  con `{"price": $precio}` — depende de T006, T017
- [ ] T021 [US2] Editar `App\Http\Controllers\Integraciones\TiendanubeConfiguracionController`
  (`app/Http/Controllers/Integraciones/TiendanubeConfiguracionController.php`): los métodos `ventas()`
  (configura depósito/categoría/cuenta/lista de precios/vendedor) y `modo-solo-lectura` pasan a leer/escribir
  sobre `TiendanubeConexionRest::actual()` en vez de `TiendanubeConfiguracion::actual()` — mismo formulario,
  mismo comportamiento observable (contracts/rutas-internas.md §3) — depende de T005
- [ ] T022 [US2] Editar `resources/views/configuracion/tiendanube/index.blade.php` y/o
  `_panel_estado_rest.blade.php`: el formulario de configuración de ventas/stock (depósito, categoría,
  cuenta, lista de precios, vendedor, modo sólo lectura) pasa a mostrarse dentro del apartado REST en vez
  del apartado MCP — depende de T021

**Checkpoint**: Historia 2 completa y testeable de forma independiente — los tres cronjobs/eventos de
negocio funcionan sobre REST, con la conexión MCP desconectable sin romper nada.

---

## Phase 5 — User Story 3: Retirar la integración MCP una vez validado todo en producción (Priority: P2)

**Goal**: eliminar `ClienteTiendanube`, `TiendanubeOAuthController`, `tn_configuracion`,
`tn_operaciones_log` y el apartado MCP de Configuración → Tiendanube — **sólo después de que el usuario
confirme manualmente que las Historias 1 y 2 están validadas en producción** (spec.md Assumptions).

**Independent Test**: tras el retiro, ninguna referencia funcional a `admin-mcp.tiendanube.com` en el
código; la pantalla Configuración → Tiendanube muestra sólo el apartado REST; los cronjobs de órdenes/stock
y la vinculación automática siguen funcionando igual que en la Historia 2.

**⚠️ Gate manual**: no iniciar esta fase sin la confirmación explícita del responsable técnico de que las
Historias 1 y 2 llevan un tiempo funcionando correctamente en producción (spec.md Assumptions) — este gate
no es una tarea automatizable.

### Tests for User Story 3

- [ ] T023 [US3] Crear `tests/Feature/Integraciones/TiendanubeRetiroMcpTest.php`: confirmar que
  `SincronizadorOrdenes`, `SincronizadorStock`, `SincronizadorPrecios`, `VinculadorAutomatico` funcionan
  correctamente sin que exista `App\Services\Tiendanube\ClienteTiendanube` en el árbol de clases cargado
  (test de humo post-retiro); confirmar que `Configuración → Tiendanube` (`GET tiendanube`) sólo devuelve el
  apartado REST

### Implementation for User Story 3

- [ ] T024 [US3] **Backup de base de datos** antes de aplicar la migración destructiva siguiente (paso
  operativo manual, spec.md Assumptions — no automatizable, documentar en el checklist de deploy)
- [ ] T025 [US3] Migración `database/migrations/2026_XX_XX_drop_tn_configuracion_y_tn_operaciones_log_tables.php`:
  `Schema::dropIfExists('tn_configuracion')` y `Schema::dropIfExists('tn_operaciones_log')` — depende de
  T004 (datos ya migrados) y de la confirmación del gate manual
- [ ] T026 [P] [US3] Eliminar `App\Services\Tiendanube\ClienteTiendanube`
  (`app/Services/Tiendanube/ClienteTiendanube.php`) y `App\Services\Tiendanube\Excepciones\CredencialesIlegiblesException`
  si sólo la usaba esa clase — depende de T018, T019, T020 (ya no tienen esta dependencia)
- [ ] T027 [P] [US3] Eliminar `App\Http\Controllers\Integraciones\TiendanubeOAuthController`
  (`app/Http/Controllers/Integraciones/TiendanubeOAuthController.php`)
- [ ] T028 [P] [US3] Eliminar `App\Models\Integraciones\TiendanubeConfiguracion`
  (`app/Models/Integraciones/TiendanubeConfiguracion.php`) y `App\Models\Integraciones\TiendanubeOperacionLog`
  (`app/Models/Integraciones/TiendanubeOperacionLog.php`) — depende de T025
- [ ] T029 [US3] Editar `routes/web.php` (grupo `tiendanube`, líneas ~332-341): eliminar rutas `conectar`,
  `callback`, `estado`, `desconectar` (MCP); mantener `modo-solo-lectura`, `ventas`, `historial` apuntando
  ahora al apartado REST (o redirigir `historial` a `tn_rest_operaciones_log`, contracts/rutas-internas.md
  §3) — depende de T027
- [ ] T030 [US3] Editar `App\Http\Controllers\Integraciones\TiendanubeConfiguracionController`: eliminar
  `estado()`/`desconectar()` (MCP); `historial()` pasa a consultar `TiendanubeRestOperacionLog` — depende de
  T029
- [ ] T031 [US3] Editar `resources/views/configuracion/tiendanube/index.blade.php`: eliminar el apartado
  visual de la conexión MCP, dejando sólo el apartado REST (spec 022) con el formulario de configuración de
  negocio ya trasladado en T022 — depende de T030
- [ ] T032 [US3] Buscar y eliminar tests obsoletos de la conexión MCP (`tests/Feature/Integraciones/Tiendanube*`
  relacionados a `TiendanubeOAuthController`/`TiendanubeConfiguracion` que ya no aplican) — depende de T026-T031

**Checkpoint**: Historia 3 completa — la integración MCP deja de existir en código y base de datos, sin
afectar el funcionamiento ya migrado de las Historias 1 y 2.

---

## Phase 6 — Polish & Cross-Cutting Concerns

- [ ] T033 Actualizar `docs/documentacion_principal_crm.md` §5.3 y `docs/modelo_datos.md` §11: reemplazar la
  descripción del modelo MCP por el modelo REST (cliente, vinculación por catálogo en vivo, configuración de
  negocio en `tn_conexion_rest`, retiro del apartado MCP) — hacer **antes** de dar la spec por completa
  (principio I de la constitución)
- [ ] T034 Correr la suite completa de PHPUnit (`php artisan test`) y confirmar que no hay regresiones en
  `tests/Feature/Integraciones/` existentes (specs 011/012/013/016/020/022/023), en particular
  `TiendanubeConexionRestTest`/`TiendanubeConexionRestErroresTest`/`TiendanubeConexionRestAislamientoTest`
  (spec 022, sin cambios de comportamiento esperados)
- [ ] T035 Ejecutar manualmente [quickstart.md](./quickstart.md) completo contra la cuenta real conectada:
  Historia 1 (vinculación sin venta previa, SKU corregido, SKU duplicado), Historia 2 (órdenes/stock/precio
  con MCP desconectado), y — sólo tras la confirmación del gate manual — Historia 3 (retiro)

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: sin dependencias.
- **Foundational (Phase 2)**: sin dependencias — bloquea las 3 historias.
- **Historia 1 (Phase 3)**: depende de Foundational (T006, T007).
- **Historia 2 (Phase 4)**: depende de Foundational (T005, T006) — independiente de Historia 1 (servicios
  distintos), puede implementarse en paralelo.
- **Historia 3 (Phase 5)**: depende de que Historias 1 y 2 estén **completas y validadas en producción**
  (gate manual, no sólo "código mergeado") — es la única historia con una dependencia de negocio, no sólo
  técnica, sobre las anteriores.
- **Polish (Phase 6)**: depende de que las 3 historias estén completas (T033 de docs puede adelantarse antes
  de Historia 3 si el contenido de esa fase ya se conoce, pero el reporte final la incluye).

### Dentro de cada historia

- Tests antes de la implementación (T008 antes de T009; T015-T017 antes de T018-T020; T023 antes de
  T024-T032).
- Migraciones antes de los modelos/servicios que dependen de sus columnas (T003→T004→T005→T006).

### Parallel Opportunities

- T003, T007 (Foundational) no dependen entre sí — paralelizables.
- T015, T016, T017 (tests de Historia 2, archivos distintos) — paralelizables entre sí.
- T026, T027, T028 (retiros de Historia 3, archivos/clases distintos) — paralelizables entre sí, una vez
  cumplida su dependencia común (T025 para T028).
- Historia 1 (Phase 3) e Historia 2 (Phase 4) pueden implementarse en paralelo por personas distintas — no
  comparten archivos de implementación, sólo la base de Foundational.

---

## Implementation Strategy

### MVP First (Historia 1 solamente)

1. Completar Phase 1: Setup.
2. Completar Phase 2: Foundational (config migrada + `ClienteTiendanubeRest` + excepción).
3. Completar Phase 3: Historia 1 (vinculación por catálogo en vivo).
4. **Parar y validar**: quickstart.md §1.
5. La corrección central de vinculación (motivo explícito del usuario al pedir esta spec) ya está
   entregada — productos que nunca vendieron por Tiendanube ya se pueden vincular por SKU directo.

### Incremental Delivery

1. Setup + Foundational → base lista (cliente REST + configuración migrada).
2. Historia 1 → validar independientemente → vinculación deja de depender de `tn_orden_items`/Excel.
3. Historia 2 → validar independientemente → órdenes/stock/precio dejan de depender del MCP en tiempo de
   ejecución (aunque el código MCP siga existiendo, sin consumidores).
4. **Pausa de validación en producción** (no una tarea, un período de observación real).
5. Historia 3 → sólo tras confirmación manual → retiro completo del MCP.
6. Polish → suite completa en verde, quickstart validado de punta a punta, docs actualizadas.

---

## Notes

- `[P]` = archivos distintos, sin dependencias pendientes entre sí.
- `[USn]` = mapea la tarea a su historia de usuario para trazabilidad.
- Historia 3 es la única con un gate no técnico (confirmación manual de producción) — no se debe interpretar
  "todas las tareas de código listas" como luz verde para esa fase.
- Verificar que los tests fallan antes de implementar.
- No se genera ninguna tarea de `implement` — la cadena de spec-kit de este proyecto termina en `analyze`
  (CLAUDE.md); `/speckit-implement` queda a criterio del usuario, después de ese reporte.
