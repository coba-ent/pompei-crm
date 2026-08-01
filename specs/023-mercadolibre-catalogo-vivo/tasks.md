# Tasks: Vinculación automática de Mercado Libre por catálogo en vivo

**Spec**: [spec.md](./spec.md) · **Plan**: [plan.md](./plan.md) · **Datos**: [data-model.md](./data-model.md)
· **Contratos**: [contracts/rutas-internas.md](./contracts/rutas-internas.md) · **Validación**:
[quickstart.md](./quickstart.md)

**Branch**: `023-mercadolibre-catalogo-vivo` · **Fecha**: 2026-07-31

**Tests**: incluidos y **obligatorios** — mismo criterio que spec 021 (plan.md §Constitution Check,
principio IV): una vinculación mal resuelta afecta qué producto se descuenta de stock al convertir órdenes.

**Convención**: `[P]` = paralelizable (archivo distinto, sin dependencias pendientes). `[USn]` = historia de
usuario a la que pertenece.

---

## Phase 1 — Setup

- [X] T001 Confirmar que no hace falta ninguna dependencia nueva: `App\Services\MercadoLibre\
  ClienteMercadoLibre::obtener()` ya existente alcanza para las dos llamadas nuevas (scan search, multiget)
  — sin cliente HTTP nuevo (plan.md §Technical Context)

---

## Phase 2 — Foundational

- [X] T002 [P] Crear `App\Services\MercadoLibre\Excepciones\VinculacionAutomaticaFallidaException`
  (`app/Services/MercadoLibre/Excepciones/VinculacionAutomaticaFallidaException.php`): excepción simple
  (mismo patrón que `VinculacionRechazadaException`/`ConexionCaidaException` del mismo namespace), con
  mensaje libre — la lanza `VinculadorAutomatico` cuando el catálogo en vivo falla a mitad de la corrida
  (data-model.md, contracts/rutas-internas.md)

**Checkpoint**: Foundational listo — bloquea el resto de las fases (la excepción la usan tanto la
implementación de US1 como el catch del controlador).

---

## Phase 3 — User Story 1: Vincular una publicación que nunca vendió (Priority: P1) 🎯 MVP

**Goal**: reemplazar la fuente del SKU de `VinculadorAutomatico` — deja de leer `ml_orden_items` y pasa a
resolver contra el catálogo en vivo del vendedor conectado, cubriendo publicaciones sin ninguna orden
sincronizada.

**Independent Test**: con una publicación activa en Mercado Libre cuyo SKU coincide con el `id` de un
producto del CRM, sin ninguna orden sincronizada para esa publicación, apretar "Vincular automáticamente" y
confirmar que el vínculo se crea solo.

### Tests for User Story 1

- [X] T003 [US1] Reescribir `tests/Feature/Integraciones/MercadoLibreVinculacionAutomaticaTest.php` con
  `Http::fake()` sobre `api.mercadolibre.com/users/*/items/search` (modo `scan`, primera llamada sin
  `scroll_id` y siguiente con el `scroll_id` devuelto) y `api.mercadolibre.com/items` (multiget): match
  exacto de `id` crea el vínculo **sin ninguna orden sincronizada** para esa publicación (Acceptance
  Scenario 1, US1); SKU sin match deja la publicación pendiente con motivo `producto_no_encontrado`
  (Scenario 2, US1); publicación sin `SELLER_SKU` en `attributes[]`, motivo `sin_sku`; dos publicaciones con
  el mismo SKU (caso real `KO-23423`) — sólo la primera se vincula, la segunda `ya_vinculado` (detalle
  `producto`); publicación con `variations` no vacío excluida (FR-007); publicación `status=closed`
  excluida, `status=paused` incluida igual que `active` (FR-003); producto inactivo se vincula igual
  (FR-004); reintentar la corrida no modifica lo ya vinculado (SC-004); recorrido `scan` de más de una
  página (dos llamadas encadenadas, la segunda con el `scroll_id` de la primera) se agota completo antes de
  procesar (FR-002); si `ClienteMercadoLibre::obtener()` devuelve `fallo()` en cualquier llamada del
  recorrido, la corrida lanza `VinculacionAutomaticaFallidaException` sin crear ningún vínculo
  (spec.md Assumptions); un vínculo `ml_publicacion_producto` ya existente (de otra publicación, sin
  relación con las que matchean en esta corrida) no se toca ni se modifica (SC-003)

### Implementation for User Story 1

- [X] T004 [US1] Reescribir `App\Services\MercadoLibre\VinculadorAutomatico`
  (`app/Services/MercadoLibre/VinculadorAutomatico.php`): agregar `ClienteMercadoLibre` como dependencia de
  constructor (`__construct(private ClienteMercadoLibre $cliente) {}`, mismo patrón que
  `App\Services\Tiendanube\ImportadorVinculaciones` — la implementación actual de spec 021 no la tiene,
  sólo lee de la base local); en `ejecutar()`, quitar toda lectura de `MercadoLibreOrdenItem`; resolver
  `seller_id` desde `MercadoLibreCuenta::conectada()`; recorrer
  `ClienteMercadoLibre::obtener(..., "/users/{seller_id}/items/search", ['search_type' => 'scan'])`
  paginando con el `scroll_id` de la respuesta **anterior** hasta que `results` viene vacío (research.md
  R1) — si `fallo()` en cualquier llamada, lanzar `VinculacionAutomaticaFallidaException` y abortar sin
  crear nada; excluir los `ml_item_id` ya vinculados (`MercadoLibrePublicacionProducto::pluck`) antes del
  multiget; pedir el detalle en chunks de 20 vía `ClienteMercadoLibre::obtener(..., '/items', ['ids' =>
  ...])` (research.md R2); por cada entrada: excluir `status === 'closed'`, excluir `variations` no vacío
  (FR-003/FR-007); resolver `$sku` desde `attributes[]` con `id === 'SELLER_SKU'` → `value_name`
  (research.md R3); mismo flujo de validación/creación que ya tenía el servicio (`sin_sku`,
  `Producto::find((int) $sku)` sin excluir inactivos, `ya_vinculado` con detalle `sku`/`producto`, crear
  `MercadoLibrePublicacionProducto`); mismo formato de resumen devuelto — depende de T002
- [X] T005 [US1] Editar `MercadoLibreVinculacionController::vincularAutomaticamente()`
  (`app/Http/Controllers/Ingresos/MercadoLibreVinculacionController.php`): envolver la llamada a
  `$vinculador->ejecutar()` en un `try/catch` de `VinculacionAutomaticaFallidaException` → responder JSON
  `{"ok": false, "mensaje": "..."}` con status 502 (contracts/rutas-internas.md) — depende de T004

**Checkpoint**: Historia 1 completa y testeable de forma independiente — botón "Vincular automáticamente"
ya vincula publicaciones sin depender de que hayan vendido. Sin cambios en la ruta, la vista ni el JS de la
spec 021 (mismo contrato de éxito).

---

## Phase 4 — User Story 2: El SKU corregido en Mercado Libre se refleja en la próxima corrida (Priority: P2)

**Goal**: confirmar que la resolución del SKU siempre lee el catálogo en vivo en el momento de la corrida —
no queda ningún valor cacheado ni derivado de una corrida anterior.

**Independent Test**: cambiar el SKU de una publicación ya vista antes por el sistema (con un SKU distinto
al anterior) y confirmar que la siguiente corrida de vinculación automática usa el valor nuevo.

### Tests for User Story 2

- [X] T006 [US2] Agregar caso a `tests/Feature/Integraciones/MercadoLibreVinculacionAutomaticaTest.php`:
  primera corrida con `Http::fake()` devolviendo SKU `A` (sin match, publicación queda `producto_no_
  encontrado`); segunda corrida con `Http::fake()` devolviendo SKU `B` (que sí matchea un producto) para la
  misma publicación — confirmar que la segunda corrida vincula usando `B`, sin rastro del intento fallido
  con `A` (Acceptance Scenario 1, US2) — depende de T004 (la implementación ya resuelve esto por diseño: no
  hay caching entre corridas)

**Checkpoint**: Historia 2 completa y testeable de forma independiente — no requiere implementación nueva
más allá de la de US1 (T004 ya resuelve el SKU en vivo en cada corrida por diseño), sólo confirma el
comportamiento con un test dedicado.

---

## Phase 5 — Polish & Cross-Cutting Concerns

- [x] T007 Actualizar `docs/documentacion_principal_crm.md` (§3.2.bis, §5.2 etapa 5) y `docs/modelo_datos.md`
  (nota de mecanismo de vinculación de `ml_publicacion_producto`) con el cambio de fuente del SKU — hecho
  antes de esta fase, por el principio I de la constitución (se actualiza antes de `/speckit-tasks`, no
  después)
- [X] T008 Correr la suite completa de PHPUnit (`php artisan test`) y confirmar que no hay regresiones en
  `tests/Feature/Integraciones/` existentes (specs 011/012/013/016/020/021), en particular
  `MercadoLibreVinculacionTest.php` (alta manual eliminada en spec 021, sin cambios acá) y
  `TiendanubeImportadorVinculacionesTest.php`/`TiendanubeImportarVinculacionesEndpointTest.php` (canal
  independiente, sin relación con este cambio)
- [ ] T009 Ejecutar manualmente [quickstart.md](./quickstart.md) completo contra un entorno con la cuenta
  real conectada (publicación sin ventas vinculada por SKU, SKU corregido reflejado en la siguiente
  corrida, publicación pausada incluida, caso de SKU duplicado, y — si el volumen real de miles de
  publicaciones está disponible en ese momento — medir cuánto tarda en la práctica una corrida completa,
  research.md R5)

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: sin dependencias.
- **Foundational (Phase 2)**: sin dependencias — bloquea Historia 1 y (indirectamente) Historia 2.
- **Historia 1 (Phase 3)**: depende de Foundational (T002).
- **Historia 2 (Phase 4)**: depende de que T004 (implementación de Historia 1) esté completa — no agrega
  implementación propia, sólo un test que confirma un comportamiento que T004 ya resuelve por diseño.
- **Polish (Phase 5)**: depende de que Historias 1 y 2 estén completas.

### Dentro de cada historia

- Tests antes de la implementación (T003 antes de T004/T005).
- T004 (servicio) antes de T005 (controlador, que depende de la excepción que T004 lanza).

### Parallel Opportunities

- T002 (Foundational) no tiene dependencias — puede arrancar de inmediato.
- T003 (test) se puede preparar en paralelo con T002, aunque no se pueda correr en verde hasta que exista
  la excepción (T002) y la implementación (T004).

---

## Implementation Strategy

### MVP First (Historia 1 solamente)

1. Completar Phase 1: Setup.
2. Completar Phase 2: Foundational (excepción nueva).
3. Completar Phase 3: Historia 1 (reescritura del servicio + catch en el controlador).
4. **Parar y validar**: quickstart.md §1.
5. La corrección central (motivo de esta spec) ya está entregada — publicaciones que nunca vendieron ya se
   pueden vincular.

### Incremental Delivery

1. Setup + Foundational → base lista.
2. Historia 1 → validar independientemente → el botón deja de depender de órdenes sincronizadas.
3. Historia 2 → validar independientemente (sólo test adicional, sin implementación nueva) → confirma que
   un SKU corregido en Mercado Libre se refleja solo, sin acción manual.
4. Polish → suite completa en verde, quickstart validado de punta a punta (incluida una medición real de
   tiempo si el volumen de miles de publicaciones está disponible).

---

## Notes

- `[P]` = archivos distintos, sin dependencias pendientes entre sí.
- `[USn]` = mapea la tarea a su historia de usuario para trazabilidad.
- Corrección quirúrgica sobre spec 021 ya implementada: un solo servicio reescrito, una excepción nueva, un
  catch en el controlador existente — sin tocar rutas, vista ni JS (plan.md §Structure Decision).
- Verificar que los tests fallan antes de implementar.
- No se genera ninguna tarea de `implement` — la cadena de spec-kit de este proyecto termina en `analyze`
  (CLAUDE.md); `/speckit-implement` queda a criterio del usuario, después de ese reporte.
