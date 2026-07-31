# Tasks: Vinculación automática por SKU (Mercado Libre y Tiendanube)

**Spec**: [spec.md](./spec.md) · **Plan**: [plan.md](./plan.md) · **Datos**: [data-model.md](./data-model.md)
· **Contratos**: [contracts/rutas-internas.md](./contracts/rutas-internas.md) · **Validación**:
[quickstart.md](./quickstart.md)

**Branch**: `021-vinculacion-automatica-sku` · **Fecha**: 2026-07-30

**Tests**: incluidos y **obligatorios** — una vinculación mal resuelta afecta qué producto se descuenta
de stock al convertir órdenes (specs 012/017/018), mismo criterio que la spec 021 original (plan.md
§Constitution Check, principio IV).

**Convención**: `[P]` = paralelizable (archivo distinto, sin dependencias pendientes). `[USn]` = historia
de usuario a la que pertenece. Mercado Libre y Tiendanube son independientes entre sí (sin clases
compartidas, plan.md §Constitution Check).

---

## Phase 1 — Setup

- [X] T001 Confirmar que no hace falta ninguna dependencia nueva: `maatwebsite/excel`,
  `App\Services\MercadoLibre\ClienteMercadoLibre`, `App\Services\Tiendanube\ClienteTiendanube` ya están
  en uso (plan.md §Technical Context)

---

## Phase 2 — Foundational

*(vacío — esta spec no agrega columnas ni tablas, ver data-model.md. Mercado Libre y Tiendanube no
comparten ningún prerequisito: pueden implementarse en cualquier orden o en paralelo.)*

---

## Phase 3 — User Story 1: Vinculación automática de Mercado Libre (Priority: P1) 🎯 MVP

**Goal**: reemplazar el alta manual por selector de la vinculación publicación↔producto por un botón que
la resuelve sola, comparando el SKU del vendedor visto en órdenes ya sincronizadas contra el `id` de
`productos`.

**Independent Test**: con un producto cuyo `id` coincida con el `sku_vendedor` de una orden de ML ya
sincronizada, apretar "Vincular automáticamente" y confirmar que el vínculo se crea solo.

### Tests for User Story 1

- [X] T002 [P] [US1] Test de `App\Services\MercadoLibre\VinculadorAutomatico` en
  `tests/Feature/Integraciones/MercadoLibreVinculacionAutomaticaTest.php`: match exacto de `id` crea el
  vínculo (Acceptance Scenario 1); SKU sin match deja la publicación pendiente con motivo (Scenario 2);
  publicación sin `sku_vendedor` cargado, motivo distinto a "sin match" (Scenario 3); dos publicaciones
  con el mismo `id` — sólo la primera se vincula, la segunda "ya vinculado" (Scenario 4); publicación con
  variante (`ml_variation_id` no nulo) excluida (FR-007); producto inactivo se vincula igual (FR-002,
  clarificación); reintentar la corrida no modifica lo ya vinculado (SC-004); el vínculo creado queda con
  `stock_pendiente`/`precio_pendiente` en su valor por defecto, sin verse afectado por el mecanismo
  (FR-020)
- [X] T003 [US1] Actualizar `tests/Feature/Integraciones/MercadoLibreVinculacionTest.php`: eliminar los
  tests que ejercitan `store()` vía la ruta `ingresos.mercadolibre.vinculaciones.store`
  (`test_vincula_publicacion_con_producto`,
  `test_rechaza_una_publicacion_que_no_existe_en_mercado_libre`,
  `test_rechaza_una_publicacion_de_otra_cuenta_de_mercado_libre`,
  `test_rechaza_vincular_la_misma_publicacion_a_un_segundo_producto`,
  `test_rechaza_vincular_un_producto_ya_vinculado_a_otra_publicacion`,
  `test_rechaza_publicaciones_con_variantes` — la ruta deja de existir); dejar intactos
  `test_la_cardinalidad_1a1_se_garantiza_a_nivel_de_base_de_datos` (no usa la ruta) y
  `test_eliminar_vinculacion_con_ordenes_convertidas_advierte_y_no_modifica_ventas` (usa `destroy`, sin
  cambios)

### Implementation for User Story 1

- [X] T004 [US1] Crear `App\Services\MercadoLibre\VinculadorAutomatico`
  (`app/Services/MercadoLibre/VinculadorAutomatico.php`): recorre `MercadoLibreOrdenItem` con
  `ml_variation_id` null agrupado por `ml_item_id` (más reciente, mismo criterio que
  `publicacionesPendientes()`) excluyendo ya vinculados; por cada uno, `Producto::find((int)
  $skuVendedor)` sin filtrar por `activo` (FR-002); valida que ni la publicación ni el producto ya estén
  vinculados; crea `MercadoLibrePublicacionProducto` (mismos campos que el `store()` eliminado); devuelve
  el resumen estructurado (data-model.md §Resultado de las corridas)
- [X] T005 [US1] Editar `MercadoLibreVinculacionController`
  (`app/Http/Controllers/Ingresos/MercadoLibreVinculacionController.php`): quitar sólo el método
  `store()` — **no** tocar el import de `VincularPublicacionRequest`, sigue siendo la FormRequest de
  `update()`; agregar `vincularAutomaticamente()` que delega a `VinculadorAutomatico` y devuelve el JSON
  de resumen (contracts/rutas-internas.md) — depende de T004
- [X] T006 [US1] Editar `routes/web.php`: quitar `Route::post('/', [MercadoLibreVinculacionController::class,
  'store'])` del grupo `ingresos/mercadolibre/vinculaciones`; agregar
  `Route::post('vincular-automaticamente', [MercadoLibreVinculacionController::class,
  'vincularAutomaticamente'])->name('vincularAutomaticamente')` — depende de T005
- [X] T007 [P] [US1] Editar
  `resources/views/ingresos/mercadolibre/vinculaciones.blade.php`: quitar el botón "Nueva vinculación" y
  el modal de alta (selector de publicaciones pendientes); agregar botón "Vincular automáticamente"; el
  modal de edición existente queda, pero ya no se abre desde un alta nueva
- [X] T008 [US1] Editar `resources/js/mercadolibre-vinculaciones.js`: quitar el handler de
  `#btn-nueva-vinculacion` y el uso de `mostrarSelectPublicacion()` para alta (se mantiene para el flujo
  de edición, con el select deshabilitado, igual que hoy); agregar handler del botón "Vincular
  automáticamente" (AJAX a `rutas.vincularAutomaticamente`, muestra el resumen con Toastr/modal simple, y
  `tabla.ajax.reload(null, false)` si hubo al menos una vinculación creada) — depende de T006

**Checkpoint**: Historia 1 completa y testeable de forma independiente.

---

## Phase 4 — User Story 2: Importación de vinculaciones de Tiendanube desde el export nativo (Priority: P2)

**Goal**: desde la pantalla de vinculación de Tiendanube, subir el archivo de productos que Tiendanube ya
permite exportar y crear las vinculaciones que se puedan resolver, sin tocar el alta manual existente.

**Independent Test**: subir el archivo exportado real de Tiendanube con una mezcla de SKU que matchean
productos del CRM y SKU que no, y confirmar que el resultado lista qué se vinculó y qué no, con motivo.

### Tests for User Story 2

- [X] T009 [P] [US2] Test de `App\Services\Tiendanube\ImportadorVinculaciones` en
  `tests/Feature/Integraciones/TiendanubeImportadorVinculacionesTest.php` (con `Http::fake()` sobre
  `admin-mcp.tiendanube.com` simulando `list_products` paginado): match exacto de `codigo` (Acceptance
  Scenario 1); match por número inicial del `codigo` (Scenario 2, ej. SKU `27205` / código
  `27205 AL605028 BL`); "Identificador de URL" no encontrado en el catálogo en vivo simulado (Scenario
  3); SKU sin match de `codigo` ni exacto ni parcial (Scenario 4); fila ya vinculada, por SKU y por
  producto (Scenario 5); una fila fallida no interrumpe el procesamiento de las siguientes (FR-013);
  reintentar la misma importación dos veces no sobrescribe lo ya vinculado (SC-004); filas completamente
  vacías se ignoran sin contar como fallidas; el vínculo creado queda con `stock_pendiente`/
  `precio_pendiente` en su valor por defecto (FR-020)
- [X] T010 [P] [US2] Test del endpoint `POST .../vinculaciones/importar` en
  `tests/Feature/Integraciones/TiendanubeImportarVinculacionesEndpointTest.php`: archivo vacío, extensión
  no soportada, o sin las columnas `SKU`/`Identificador de URL` reconocibles → 422 antes de procesar
  ninguna fila (FR-015); archivo real (separador `;`, codificación ISO-8859-1, formato confirmado en
  research.md R6) devuelve el resumen JSON completo; el alta manual existente (`store`/`update`/
  `destroy`/`pendientes`) sigue funcionando sin cambios (SC-005)

### Implementation for User Story 2

- [X] T011 [P] [US2] Crear `App\Http\Requests\Integraciones\ImportarVinculacionesTiendanubeRequest`
  (`app/Http/Requests/Integraciones/ImportarVinculacionesTiendanubeRequest.php`): valida `archivo`
  requerido, `mimes:xlsx,xls,csv`, `max:10240` (10MB, mismo límite que spec 006); `withValidator()`
  rechaza temprano si el archivo está vacío o no tiene las columnas `SKU`/`Identificador de URL`
  reconocibles por encabezado (FR-015), mismo patrón que `SubirArchivoImportacionRequest`
- [X] T012 [US2] Crear `App\Services\Tiendanube\ImportadorVinculaciones`
  (`app/Services/Tiendanube/ImportadorVinculaciones.php`): parsea el archivo con `Excel::toArray()`
  ubicando `SKU`/`Identificador de URL` por nombre de encabezado; resuelve producto por `codigo` (exacto,
  luego `LIKE '$sku %'`); resuelve `product_id`/`variant_id` reales consultando
  `ClienteTiendanube::leer('list_products', ['page_size' => 50, 'page' => N, 'fields_needed' => ['id',
  'product_url']])` paginado hasta agotar `pagination.total_pages`, cacheado en memoria por slug durante
  la corrida (research.md R5); valida que ni el `variant_id` ni el `producto_id` ya estén vinculados;
  crea `TiendanubeVarianteProducto`; devuelve el resumen estructurado — depende de T011
- [X] T013 [US2] Agregar acción `importar()` a `TiendanubeVinculacionController`
  (`app/Http/Controllers/Ingresos/TiendanubeVinculacionController.php`): valida con
  `ImportarVinculacionesTiendanubeRequest`, delega a `ImportadorVinculaciones`, devuelve el JSON de
  resumen (contracts/rutas-internas.md) — depende de T011, T012
- [X] T014 [US2] Editar `routes/web.php`: agregar `Route::post('importar',
  [TiendanubeVinculacionController::class, 'importar'])->name('importar')` dentro del grupo
  `ingresos/tiendanube/vinculaciones` — depende de T013
- [X] T015 [P] [US2] Editar `resources/views/ingresos/tiendanube/vinculaciones.blade.php`: agregar botón
  "Importar desde Tiendanube" + modal con input de archivo (sin plantilla propia — el archivo es el
  export nativo); tras la respuesta, renderizar vinculadas/fallidas con motivo dentro del propio modal;
  el botón/modal de alta manual existente queda igual
- [X] T016 [US2] Editar `resources/js/tiendanube-vinculaciones.js`: agregar handler del modal de
  importación (submit AJAX `multipart/form-data`, render del resumen dentro del modal, y
  `tabla.ajax.reload(null, false)` apenas llega la respuesta —con el modal todavía abierto— si hubo al
  menos una vinculación creada; FR-016 exige reflejo inmediato) — depende de T014

**Checkpoint**: Historia 2 completa y testeable de forma independiente; Historias 1 y 2 no se pisan entre
sí (canales distintos, sin clases compartidas).

---

## Phase 5 — Polish & Cross-Cutting Concerns

- [x] T017 Actualizar `docs/documentacion_principal_crm.md` (§5.2 etapa 5, §5.3 etapa 4, §3.2.bis,
  §3.2.quater) y `docs/modelo_datos.md` (§10 y sección Tiendanube) con el mecanismo de vinculación
  automática y el de importación — hecho antes de esta fase, por el principio I de la constitución (se
  actualiza antes de `/speckit-tasks`, no después)
- [X] T018 Correr la suite completa de PHPUnit (`php artisan test`) y confirmar que no hay regresiones en
  `tests/Feature/Integraciones/` existentes (specs 012/013/016/017/018/020), en particular
  `MercadoLibreVinculacionTest.php` tras T003 y `TiendanubeVinculacionTest.php` sin cambios
- [ ] T019 Ejecutar manualmente [quickstart.md](./quickstart.md) completo contra un entorno con cuentas
  reales conectadas (vinculación automática de ML con un producto de `id` conocido, importación de TN
  con el export real, reintento idempotente de ambas, casos de error)

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: sin dependencias.
- **Foundational (Phase 2)**: vacía — no bloquea nada.
- **Historia 1 (Phase 3)** e **Historia 2 (Phase 4)**: ambas dependen sólo de Setup, no entre sí — se
  pueden implementar en cualquier orden o en paralelo (canales independientes, sin clases compartidas).
  Se listan en este orden porque así aparecen en spec.md (P1/P2).
- **Polish (Phase 5)**: depende de que Historias 1 y 2 estén completas.

### Dentro de cada historia

- Tests antes de la implementación (T002/T003 antes de T004-T008; T009/T010 antes de T011-T016).
- Dentro de un mismo canal: servicio → controlador → rutas → vista/JS (los `[P]` reflejan sólo
  independencia de archivo).

### Parallel Opportunities

- Historia 1 e Historia 2 completas se pueden paralelizar entre sí (dos personas, canales distintos).
- Dentro de la Historia 1: T002 en paralelo con la preparación de T003 (archivos distintos); T007 en
  paralelo con T004-T006 (vista vs. backend).
- Dentro de la Historia 2: T009/T010 en paralelo (tests distintos); T011 en paralelo con la preparación
  de T012; T015 en paralelo con T011-T014 (vista vs. backend).

---

## Implementation Strategy

### MVP First (Historia 1 solamente)

1. Completar Phase 1: Setup.
2. Completar Phase 3: Historia 1 (vinculación automática de Mercado Libre).
3. **Parar y validar**: quickstart.md §1.
4. La corrección central (motivo de esta spec) ya está entregada — Tiendanube sigue funcionando con su
   alta manual de siempre, sin ninguna regresión, aunque la Historia 2 no esté.

### Incremental Delivery

1. Setup → base lista (sin Foundational, no aplica).
2. Historia 1 → validar independientemente → Mercado Libre deja de requerir alta manual.
3. Historia 2 → validar independientemente → Tiendanube suma la importación en lote.
4. Polish → suite completa en verde, quickstart validado de punta a punta.

---

## Notes

- `[P]` = archivos distintos, sin dependencias pendientes entre sí.
- `[USn]` = mapea la tarea a su historia de usuario para trazabilidad.
- Mercado Libre y Tiendanube se implementan como código independiente en todo momento (sin clases
  compartidas) — ver plan.md §Constitution Check y research.md.
- Verificar que los tests fallan antes de implementar.
- No se genera ninguna tarea de `implement` — la cadena de spec-kit de este proyecto termina en
  `analyze` (CLAUDE.md); `/speckit-implement` queda a criterio del usuario, después de ese reporte.
