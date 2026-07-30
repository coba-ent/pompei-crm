# Tasks: Gestión de precios de Mercado Libre desde una Lista de Precios del CRM

**Spec**: [spec.md](./spec.md) · **Plan**: [plan.md](./plan.md) · **Datos**: [data-model.md](./data-model.md) · **Contratos**: [contracts/rutas-internas.md](./contracts/rutas-internas.md) · **Validación**: [quickstart.md](./quickstart.md)

**Branch**: `016-lista-precio-mercadolibre` · **Fecha**: 2026-07-29 (reescritura completa — spec corregida)

**Tests**: incluidos y **obligatorios** — el principio IV de la constitución los exige: esta spec escribe
precios en publicaciones activas de venta, y FR-019/FR-020 blindan que el cálculo de Ventas de Mercado
Libre (spec 012) no se toque.

**Convención**: `[P]` = paralelizable (archivo distinto, sin dependencias pendientes). `[USn]` = historia
de usuario a la que pertenece.

---

## Phase 1 — Setup

- [X] T001 Confirmar que no hace falta ninguna dependencia nueva (Composer/NPM): toda la infraestructura
  de transporte, reintentos, `Cache::lock` y `DB::afterCommit()` ya existe en el framework/las specs
  011/012/013 (plan.md §Technical Context)

---

## Phase 2 — Foundational (bloquea todas las historias)

- [X] T002 [P] Migración `add_lista_precio_field_to_ml_configuracion_table` con `lista_precio_id`
  (unsignedBigInteger nullable, FK → `listas_precio.id`, `nullOnDelete()`) — data-model.md §`ml_configuracion`
- [X] T003 [P] Migración `add_precio_fields_to_ml_publicacion_producto_table` con `precio_pendiente`
  (boolean, default false), `precio_sincronizado_en` (datetime nullable), `precio_error` (string(255)
  nullable), `precio_error_en` (datetime nullable), `after('stock_error_en')` — data-model.md
  §`ml_publicacion_producto`
- [X] T004 [P] Extender `app/Models/Integraciones/MercadoLibrePublicacionProducto.php`: agregar las 4
  columnas nuevas a `$fillable`, castear los timestamps y `precio_pendiente` a boolean, y un scope
  `scopePendientesPrecio(Builder $query)` que filtre `precio_pendiente = true` (research.md R7)
- [X] T005 [P] Extender `app/Models/Integraciones/MercadoLibreConfiguracion.php`: agregar
  `lista_precio_id` a `$fillable` y el método `listaPrecio(): BelongsTo` (mismo patrón que
  `deposito()`/`categoriaVenta()` ya presentes en ese modelo)
- [X] T006 [P] Extender `app/Http/Requests/Integraciones/GuardarConfiguracionVentasMercadoLibreRequest.php`:
  agregar la regla `'lista_precio_id' => ['nullable', 'exists:listas_precio,id']` (research.md R9, FR-003)

**Checkpoint**: esquema y validación listos — las historias de usuario pueden empezar.

---

## Phase 3 — US1: Configurar la Lista de Precios que gestiona Mercado Libre (P1)

**Objetivo**: tener el campo de configuración operativo. **Test independiente**: entrar a Configuración →
Integraciones → Mercado Libre, elegir una Lista de Precios, guardar, y verificar que persiste al recargar.

- [X] T007 [US1] Extender `app/Http/Controllers/Integraciones/MercadoLibreConfiguracionController.php::index()`:
  pasar `$listasPrecio = ListaPrecio::where('activo', true)->orderBy('nombre')->get()` a la vista (mismo
  query ya usado en `ProductoController`/`VentaController`/`PresupuestoController`; `ListaPrecio` no
  tiene un scope `activos()` propio)
- [X] T008 [US1] Extender `resources/views/configuracion/mercadolibre/index.blade.php`: agregar un
  `<select id="ml-lista-precio-id">` en la sección "Configuración de Ventas", junto a Depósito y
  Categoría de Venta, con las opciones de `$listasPrecio`
- [X] T009 [US1] Extender `resources/js/mercadolibre.js`: incluir `#ml-lista-precio-id` en el selector
  conjunto de Select2 ya existente, en la carga de datos guardados (`.trigger('change.select2')`) y en el
  payload de `guardarVentas()`
- [X] T010 [P] [US1] Test en `tests/Feature/Integraciones/MercadoLibreProgramacionTest.php` (extender — es
  el archivo que ya ejercita `route('configuracion.mercadolibre.ventas.configurar')`; **no**
  `MercadoLibreConfiguracionTest.php`, que cubre la pantalla de credenciales OAuth, dominio distinto):
  guardar con una Lista de Precios activa persiste el valor (FR-001, SC-001); guardar sin ninguna
  seleccionada no da error (FR-002); guardar con un `lista_precio_id` inexistente devuelve 422 sin tocar
  el resto de la configuración (FR-003)

**Checkpoint**: el campo de configuración existe y persiste — prerrequisito de todo lo demás.

---

## Phase 4 — US2: Que un cambio de precio en esa lista actualice Mercado Libre (P1) 🎯 MVP

**Objetivo**: el motivo de ser de esta spec. **Test independiente**: con la lista configurada y un
producto vinculado, cambiar su precio en el modal de Producto y verificar que la publicación de Mercado
Libre queda con el nuevo precio, sin acción manual.

- [X] T011 [US2] Crear `app/Services/MercadoLibre/SincronizadorPrecios.php` con
  `enviarUno(MercadoLibrePublicacionProducto $vinculo, float $precio): bool`: **primero** marca
  `$vinculo->update(['precio_pendiente' => true])`, **incondicionalmente y antes de evaluar ningún
  corte** — así un intento bloqueado (ver abajo) queda igual "conservando el pendiente para el próximo
  intento válido" tal como exige FR-011/FR-012, en vez de perder el cambio porque nada lo marcó pendiente
  todavía (research.md R4: el pendiente es el mecanismo de respaldo, no sólo el disparador). Recién
  después aplica los mismos cortes previos que `SincronizadorStock::verificarCortes()` (función
  desactivada / modo sólo lectura / conexión caída — FR-011, FR-012, con un único registro en el
  historial por corte); si está bloqueado, corta acá (el vínculo ya quedó pendiente); si no, llama a
  `ClienteMercadoLibre::enviar('sincronizar_precio', 'PUT', "/items/{$vinculo->ml_item_id}", ['price' =>
  $precio])` (FR-008, research.md R5/R6); interpreta la `RespuestaMercadoLibre`: éxito → `precio_pendiente
  = false`, `precio_sincronizado_en = now()`, limpia `precio_error`/`precio_error_en`; fallo no transitorio
  → `precio_error`/`precio_error_en`, deja `precio_pendiente = true` (FR-010)
- [X] T012 [US2] Crear `app/Observers/PrecioProductoObserver.php` con `saved(PrecioProducto $precio)`:
  descarta si `$precio->lista_precio_id !== MercadoLibreConfiguracion::actual()->lista_precio_id` o si la
  configuración no tiene ninguna lista (FR-006); busca
  `MercadoLibrePublicacionProducto::where('producto_id', $precio->producto_id)->first()`, descarta si no
  hay vínculo (FR-006); si pasa ambos filtros, registra `DB::afterCommit(fn () =>
  app(SincronizadorPrecios::class)->enviarUno($vinculo, (float) $precio->precio))` — el envío **fuera**
  de cualquier transacción abierta por el llamador (FR-004, research.md R1/R2)
- [X] T013 [US2] Registrar el observer en `app/Providers/AppServiceProvider.php::boot()`
  (`PrecioProducto::observe(PrecioProductoObserver::class)`, mismo patrón que
  `MovimientoStock::observe(MovimientoStockObserver::class)` de la spec 013)
- [X] T014 [P] [US2] Test en `tests/Feature/Integraciones/PrecioProductoObserverTest.php`: cambiar el
  precio de un producto vinculado en la lista configurada (vía `$producto->precios()->updateOrCreate()`,
  camino del modal — FR-005) dispara el envío; cambiar el precio de un producto **sin** vínculo no
  dispara nada (FR-006); cambiar el precio en una lista **distinta** a la configurada no dispara nada
  (FR-006); sin ninguna Lista de Precios configurada, ningún cambio dispara nada (FR-002 aplicado a este
  flujo) — estos tres últimos casos son la cobertura de SC-003
- [X] T015 [P] [US2] Test en `tests/Feature/Integraciones/PrecioProductoObserverTest.php`: el mismo
  escenario disparado vía `app/Services/Import/ImportadorFilas.php` (`$producto->precios()->create(...)`,
  camino de importación masiva) produce el mismo resultado que T014 (FR-005)
- [X] T016 [P] [US2] Test en `tests/Feature/Integraciones/SincronizadorPreciosTest.php` con
  `Http::fake()`: `enviarUno()` hace el `PUT /items/{id}` con `price` correcto y deja el vínculo
  sincronizado con fecha (FR-008, SC-002); bloqueado por modo sólo lectura/función desactivada/conexión
  caída no genera ningún `PUT` y conserva `precio_pendiente = true` (FR-011, FR-012)
- [X] T017 [P] [US2] Test de regresión — **cubre FR-019/FR-020, la garantía central de esta spec**
  (quickstart.md §Suite automatizada): correr `tests/Feature/Integraciones/MercadoLibreConversionTest.php`,
  `tests/Feature/Integraciones/MercadoLibreImportesTest.php` (verifica que importes/IVA de la Venta
  coinciden con lo pagado en Mercado Libre — la prueba más directa de que esta spec no toca el cálculo) y
  `tests/Feature/Integraciones/MercadoLibreCreacionAutomaticaTest.php`, y confirmar que los tres siguen en
  verde **sin ningún ajuste**: los precios de línea siguen saliendo exclusivamente del importe pagado, y
  la Venta queda sin `lista_precio_id` (SC-004) — esta spec **no debe tocar**
  `app/Services/MercadoLibre/ConversorOrdenAVenta.php` en ningún punto

**Checkpoint**: un cambio de precio manual en la lista configurada ya llega solo a Mercado Libre, de
punta a punta, sin tocar el cálculo de Ventas de Mercado Libre.

---

## Phase 5 — US3: Sincronizar precios manualmente (P2)

**Objetivo**: red de seguridad del flujo automático. **Test independiente**: con vínculos pendientes o
con error, presionar "Sincronizar precios ahora" y verlos actualizados sin recargar la página.

- [X] T018 [US3] Extender `app/Services/MercadoLibre/SincronizadorPrecios.php` con
  `ejecutar(): array`: candado propio `Cache::lock('ml:sincronizar_precios', 300)` (FR-015); aplica su
  propio chequeo de cortes (función desactivada / sólo lectura / conexión caída / sin Lista de Precios
  configurada) **antes** del `foreach`, con **un único registro** en el historial si está bloqueado y
  **sin llamar a `enviarUno()`** en ese caso (research.md R5 — evita un registro de bloqueo por cada
  vínculo pendiente, mismo criterio que `SincronizadorStock::verificarCortes()`); si no está bloqueado,
  itera `MercadoLibrePublicacionProducto::pendientesPrecio()->with('producto')->get()`, resuelve el
  precio vigente de cada producto en la lista configurada (`$producto->precios()->where('lista_precio_id',
  $listaId)->value('precio')`) y llama a `enviarUno()`; si el producto no tiene precio en esa lista, lo
  saltea sin marcar error; devuelve `{ok, mensaje, actualizados, con_error}`
- [X] T019 [US3] Agregar `SincronizadorPrecios $sincronizadorPrecios` al constructor de
  `app/Http/Controllers/Ingresos/MercadoLibreVentaController.php` (mismo patrón de inyección que
  `$sincronizadorStock`) y la acción `sincronizarPrecios(): JsonResponse` que llama a
  `$this->sincronizadorPrecios->ejecutar()` y devuelve `response()->json($resultado, $resultado['ok'] ? 200 : 409)`
  (contracts §1)
- [X] T020 [US3] Registrar `POST productos/sincronizar-precios-ml` en `routes/web.php`, en la sección de
  rutas de Productos (sin `permiso:` adicional, mismo criterio que el resto de `/productos/*`), apuntando
  al mismo `MercadoLibreVentaController::sincronizarPrecios` — **corrección de UX (30/07/2026)**: se había
  registrado originalmente dentro del grupo `ingresos/mercadolibre` (gateado por `permiso:ventas.ver`,
  junto a `sincronizar-stock`) replicando sin cuestionar el patrón de la spec 013; el usuario pidió
  explícitamente que viviera en Productos, no en la pantalla de órdenes de Mercado Libre (contracts §1)
- [X] T021 [US3] Extender `resources/js/productos.js` con el botón "Sincronizar precios ahora" (AJAX,
  Toastr, sin recarga — mismo patrón que el resto de las acciones de esa pantalla), dentro del mismo
  bloque `$(function(){ if (!$('#tabla-productos').length) return; ... })` ya existente — **corrección de
  UX (30/07/2026)**: movido desde `resources/js/mercadolibre-ventas.js`, ver T020
- [X] T022 [US3] Agregar el botón a `resources/views/productos/index.blade.php`, junto a "Importar datos"
  / "Nuevo Producto" — **corrección de UX (30/07/2026)**: movido desde
  `resources/views/ingresos/mercadolibre/index.blade.php` (FR-018)
- [X] T023 [P] [US3] Test en `tests/Feature/Integraciones/MercadoLibreSincronizarPreciosTest.php`: la
  acción devuelve los contadores esperados sin recargar la página (FR-014, SC-006); dos disparos
  simultáneos sólo ejecutan uno (FR-015);
  bloqueada con modo sólo lectura o función desactivada, motivo visible, sin ejecutar (FR-016); con
  **varios** vínculos pendientes y modo sólo lectura activo, el historial de operaciones registra **un
  único** bloqueo, no uno por vínculo (research.md R5); un producto que se vinculó **después** de un
  cambio de precio ya ocurrido (por lo tanto `precio_pendiente` nunca se marcó por evento) queda cubierto
  igual al vincularlo con precio pendiente desde el alta del vínculo, o se sincroniza en el primer
  "Sincronizar precios ahora" (User Story 3, escenario 4)

**Checkpoint**: control manual disponible como reintento y como respaldo, sin depender de un nuevo cambio
de precio.

---

## Phase 6 — US4: Enterarse cuando Mercado Libre rechaza una actualización de precio (P2)

**Objetivo**: visibilidad del estado real. **Test independiente**: pausar una publicación vinculada,
cambiar el precio de su producto, y verificar que queda señalada con el motivo mientras el resto se
sincroniza con normalidad.

- [X] T024 [US4] Extender `app/Http/Controllers/Ingresos/MercadoLibreVinculacionController.php::datatable()`
  con las columnas derivadas `precio_estado` (`sincronizado`/`pendiente`/`error`, vía un método privado
  `precioEstado()` análogo a `stockEstado()` ya existente), `precio_sincronizado_en` y `precio_error`
  según contracts §2 (FR-017)
- [X] T025 [US4] Extender `resources/views/ingresos/mercadolibre/vinculaciones.blade.php` con las
  columnas nuevas, mostrando motivo y fecha del error en un tooltip (FR-017)
- [X] T026 [P] [US4] Test en `tests/Feature/Integraciones/SincronizadorPreciosTest.php`: el rechazo de un
  vínculo (publicación pausada, simulado con `Http::fake()`) no interrumpe el envío del resto de los
  vínculos de la misma operación (evento u "Sincronizar precios ahora"), y deja ese vínculo con
  `precio_error` y `precio_pendiente = true` para reintentar (FR-010, FR-013, SC-005)
- [X] T027 [P] [US4] Test de reintento ante 429/5xx: `SincronizadorPrecios` no implementa reintento
  propio — ya lo cubre `ClienteMercadoLibre::ejecutarConReintentos()` (research.md R6, igual que
  `SincronizadorStock`) — verificar con `Http::fake()` una secuencia 429→200 que el vínculo termina
  sincronizado sin marcarlo como error (FR-009)

**Checkpoint**: el usuario puede confiar en la pantalla de vinculaciones para saber qué precio está
realmente sincronizado, sin tener que entrar a Mercado Libre a comprobarlo.

---

## Phase 7 — US5: Cambiar la Lista de Precios configurada actualiza Mercado Libre de una vez (P2)

**Objetivo**: evitar que un cambio de lista deje publicaciones desactualizadas hasta su próximo cambio de
precio individual. **Test independiente**: con productos vinculados con precio en dos listas distintas,
cambiar la lista configurada de una a la otra y verificar que todos los vínculos reciben de inmediato el
precio de la nueva lista.

- [X] T028 [US5] Extender `app/Services/MercadoLibre/SincronizadorPrecios.php` con
  `sincronizarListaCompleta(int $listaPrecioId): array`: mismo chequeo de cortes previo que `ejecutar()`
  (T018) — un único registro si está bloqueado, sin iterar ningún vínculo en ese caso; si no está
  bloqueado, recorre `MercadoLibrePublicacionProducto::with('producto')->get()`, resuelve el precio de
  cada producto en `$listaPrecioId` (saltea sin error los que no tienen precio en esa lista) y llama a
  `enviarUno()` (FR-007, research.md R5)
- [X] T029 [US5] Extender
  `app/Http/Controllers/Integraciones/MercadoLibreConfiguracionController.php::guardarVentas()`: capturar
  el `lista_precio_id` previo antes del `update()`; si el valor efectivamente cambió y el nuevo no es
  `null`, llamar a `app(SincronizadorPrecios::class)->sincronizarListaCompleta($nuevoValor)` después de
  persistir (`SincronizadorPrecios` se resuelve del contenedor, no es un método estático — mismo patrón
  que usa `PrecioProductoObserver` en T012), sin que un bloqueo (kill-switch/conexión caída) haga fallar
  el guardado de la configuración — contracts §3
- [X] T030 [P] [US5] Test en `tests/Feature/Integraciones/MercadoLibreProgramacionTest.php` (extender —
  mismo archivo que T010, **no** `MercadoLibreConfiguracionTest.php`):
  cambiar `lista_precio_id` de A a B sincroniza de inmediato los vínculos con precio en B (FR-007, SC-007);
  un vínculo sin precio en B no se sincroniza ni queda con error (User Story 5, escenario 2); guardar el
  mismo valor que ya estaba configurado (sin cambio real) no dispara ningún envío; guardar con modo sólo
  lectura activo persiste la configuración igual, dejando los vínculos con precio en la nueva lista
  `precio_pendiente = true` para el próximo intento válido (User Story 5, escenario 3)

**Checkpoint**: cambiar de lista configurada deja Mercado Libre consistente de inmediato, sin depender de
que cada producto tenga, además, un cambio de precio futuro.

---

## Phase 8 — Polish y transversales

- [X] T031 [P] Verificar que ningún dato sensible se agrega al historial de operaciones por esta spec —
  reutiliza el saneado ya existente de `ClienteMercadoLibre::registrarLog()` (spec 011), sin lógica
  propia que loguear (FR-013)
- [X] T032 [P] Actualizar `CREDENCIALES_ACCESO.txt` si alguna prueba manual de esta spec cambia un acceso
  (regla de `CLAUDE.md`) — no aplica si toda la prueba se hace con `Http::fake()`/factories
- [ ] T033 **PENDIENTE — requiere la cuenta real conectada**: recorrer `quickstart.md` de punta a punta
  con una publicación real, confirmando que el precio en Mercado Libre cambia exactamente al valor
  cargado en el CRM, incluyendo el escenario de rechazo (publicación pausada) y el de cambio de lista
  configurada (US5)

---

## Cobertura heredada (requisitos sin tarea propia, por diseño)

Estos requisitos **no** tienen tarea porque ya los satisface infraestructura existente y verificada de
las specs 011/012/013. Duplicarlos sería reimplementar lógica crítica ya probada (research.md R6).

| Requisito | Quién lo cubre | Verificación |
|---|---|---|
| FR-009 — espera creciente ante 429/5xx | `ClienteMercadoLibre::ejecutarConReintentos()`, sin código propio | T027 |
| FR-013 — registrar cada envío en el historial de operaciones | `ClienteMercadoLibre::registrarLog()`, disparado en cada `enviar()`, sin código propio | T031 |

> **FR-011/FR-012 NO están en esta tabla a propósito**: igual que en la spec 013, no quedan cubiertos
> sólo por `ClienteMercadoLibre` — necesitan el corte previo propio de `SincronizadorPrecios::enviarUno()`
> (T011), por la misma razón por la que `SincronizadorStock` necesitó el suyo.

## Dependencias entre historias

```
Setup (T001)
   ↓
Foundational (T002-T006)
   ↓
US1 (T007-T010)  ← configura el campo, prerrequisito de todo lo demás
   ↓
US2 (T011-T017)  🎯 MVP — cierra el motivo de ser de esta spec
   ↓
   ├── US3 (T018-T023)  ← requiere US2 (reutiliza SincronizadorPrecios::enviarUno)
   ├── US4 (T024-T027)  ← requiere US2 (expone el estado que US2 ya persiste)
   └── US5 (T028-T030)  ← requiere US2 (reutiliza SincronizadorPrecios::enviarUno)
   ↓
Polish (T031-T033)
```

**US3, US4 y US5 pueden desarrollarse en paralelo** — las tres sólo dependen de US2, no entre sí.

## Oportunidades de paralelización

- **Fase 2**: T002-T006 son `[P]` (migraciones, modelos y Request, archivos distintos).
- **Tests**: casi todos son `[P]` entre sí — distinto archivo, sin estado compartido.
- **Fases 5, 6 y 7** (US3, US4, US5) pueden avanzar en paralelo una vez completa US2.

## Estrategia de implementación

**MVP sugerido**: Fases 1-4 (hasta T017). Cierra de punta a punta el motivo de ser de la spec — un cambio
de precio en la lista configurada llega solo a Mercado Libre — sin las comodidades de reintento manual,
visibilidad de error o push al cambiar de lista (US3/US4/US5).

**Entrega completa**: Fases 1-7 (hasta T030). Agrega el control manual, la visibilidad de errores y el
push inmediato al cambiar de lista que hacen el módulo confiable de usar sin mirar los logs del servidor.
