---

description: "Task list for feature 056-filtros-compras"
---

# Tasks: Filtros del listado de Compras

**Input**: Design documents from `/specs/056-filtros-compras/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/filtros-compras.md, quickstart.md

**Tests**: Incluidos â€” el plan (Constitution Check) pide tests de filtrado para no reintroducir el bug de N+1 ya documentado en `kpis()`, y para blindar la lÃ³gica de combinaciÃ³n AND/OR y exclusiÃ³n de fechas nulas.

**Organization**: Tareas agrupadas por historia de usuario (spec.md): US1 = Proveedor mÃºltiple, US2 = set completo de 12 filtros, US3 = rango de Vencimiento + selector de columnas.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Puede correr en paralelo (archivos distintos, sin dependencias)
- **[Story]**: US1 / US2 / US3
- Incluye rutas de archivo exactas

## Path Conventions

Monolito Laravel existente â€” rutas relativas a la raÃ­z del repo (ver plan.md Â§ Project Structure).

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Preparar el terreno de datos que todas las historias necesitan (columna nueva + relaciÃ³n nueva), antes de tocar controller/vista/JS.

- [X] T001 Crear migraciÃ³n `database/migrations/{timestamp}_add_creado_por_id_a_compras_table.php`: agrega `creado_por_id` (bigint, nullable, FK â†’ `users.id`, `nullOnDelete()`) a `compras`. Correr `php artisan migrate`.
- [X] T002 [P] Agregar `creado_por_id` a `$fillable` y el mÃ©todo `creadoPor(): BelongsTo` (`belongsTo(User::class, 'creado_por_id')`) en `app/Models/Compra.php`, igual patrÃ³n que `Venta::creadoPor()` (`app/Models/Venta.php:70-74`).
- [X] T003 [P] Agregar el mÃ©todo `etiquetas(): MorphToMany` (`morphToMany(Etiqueta::class, 'etiquetable')`) en `app/Models/Compra.php`, igual patrÃ³n que `Venta::etiquetas()` (`app/Models/Venta.php:123-126`). Agregar el `use Illuminate\Database\Eloquent\Relations\MorphToMany;` correspondiente.
- [X] T004 En `CompraController::store()` (`app/Http/Controllers/CompraController.php:188`), agregar `'creado_por_id' => auth()->id(),` al array de `Compra::create([...])`, igual que `VentaController.php:393`.

**Checkpoint**: El modelo de datos soporta Etiquetas y Usuario creador; ninguna historia de usuario depende de nada mÃ¡s antes de arrancar.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Preparar `queryFiltrada()` y el panel de filtros para que las 3 historias puedan agregar sus filtros sin pisarse, y dejar disponibles los catÃ¡logos que la vista va a necesitar.

**âš ï¸ CRITICAL**: Ninguna historia de usuario puede completarse sin esto.

- [X] T005 En `CompraController::index()` (`app/Http/Controllers/CompraController.php:39-45`), pasar a la vista los catÃ¡logos necesarios para los `<select>` de filtros: `Categoria::compra()->activas()->orderBy('nombre')->get(['id','nombre'])` (categorÃ­as de compra â€” verificar el scope real usado hoy en `Categoria`, anÃ¡logo a `Categoria::venta()` de `VentaController.php:57`), `Etiqueta::orderBy('nombre')->get(['id','nombre'])`, `CuentaTesoreria::orderBy('nombre')->get(['id','nombre'])`, `User::orderBy('name')->get(['id','name'])`, `Deposito::activos()->orderBy('nombre')->get(['id','nombre'])` (verificar scope real de `Deposito`, anÃ¡logo al ya usado en `VentaController.php`), y pasarlos con `compact(...)` a `compras.index`.
- [X] T006 En `CompraController::queryFiltrada()` (`app/Http/Controllers/CompraController.php:88-101`), reemplazar el `where('proveedor_id', ...)` actual por `whereIn('proveedor_id', (array) $request->input('proveedor_id'))` dentro del mismo `if ($request->filled('proveedor_id'))`.
- [X] T007 En `resources/views/compras/index.blade.php`, reemplazar el `<select id="filtro-proveedor" class="form-select">` (lÃ­nea 79) por `<select id="filtro-proveedor" class="form-select" multiple></select>`.
- [X] T008 En `resources/js/compras.js`, ubicar el `initSelect2($('#filtro-proveedor'), {...})` existente (AJAX a `proveedoresOpciones`) y agregar `multiple: true` a sus opciones, igual patrÃ³n que `initSelect2($('#filtro-cliente'), {ajax: {...}})` de `resources/js/ventas.js:152-159`.

**Checkpoint**: Proveedor ya es multi-selecciÃ³n funcional de punta a punta (US1 completa). El resto de historias solo agrega filtros nuevos sobre esta misma base.

---

## Phase 3: User Story 1 - Filtrar compras por mÃºltiples proveedores a la vez (Priority: P1) ðŸŽ¯ MVP

**Goal**: El filtro Proveedor permite elegir 2+ proveedores y el listado devuelve la uniÃ³n de sus compras.

**Independent Test**: Abrir Filtros de Compras, elegir 2 proveedores, Buscar, verificar que el listado trae compras de ambos y que se pueden agregar/quitar proveedores sin perder los demÃ¡s filtros.

### Tests for User Story 1

- [X] T009 [P] [US1] Test de feature en `tests/Feature/Compras/FiltrosCompraTest.php`: `filtra_por_multiples_proveedores()` â€” crea compras de 3 proveedores distintos, filtra por 2 de ellos vÃ­a `proveedor_id[]=`, asegura que el resultado contiene solo esas dos y no la tercera.
- [X] T010 [P] [US1] Test de feature: `filtro_proveedor_acepta_escalar_por_compatibilidad()` â€” filtra con `proveedor_id=1` (sin `[]`) y asegura que sigue funcionando igual que antes de esta feature.

### Implementation for User Story 1

- Ya completada en Phase 2 (T006-T008) por ser la base compartida de todos los filtros de catÃ¡logo â€” no hay tareas de implementaciÃ³n adicionales exclusivas de US1.

**Checkpoint**: US1 funcional y testeada de forma independiente. Es el MVP mÃ­nimo entregable de esta feature.

---

## Phase 4: User Story 2 - Disponer de todos los filtros reales de Contagram en Compras (Priority: P1)

**Goal**: El panel de Filtros de Compras tiene los 12 campos reales (Id, Proveedor, CategorÃ­a de Compra, Estado del Pago, Tipo y NÂ° de Factura, Etiqueta, Facturado, Medio de pago, Usuario, Nota Interna, DepÃ³sito, Desde/Hasta Servicio), todos combinables con AND, los de catÃ¡logo con selecciÃ³n mÃºltiple.

**Independent Test**: Verificar que los 12 campos estÃ¡n presentes en el panel; probar cada uno de forma aislada contra datos de prueba; combinar 2+ filtros y verificar AND.

### Tests for User Story 2

- [X] T011 [P] [US2] Test de feature en `tests/Feature/Compras/FiltrosCompraTest.php`: un caso por cada filtro nuevo â€” `filtra_por_id()`, `filtra_por_categoria_multiple()`, `filtra_por_estado_pago()` (a_pagar/parcial/pagado), `filtra_por_tipo_y_numero_de_factura()`, `filtra_por_etiqueta_multiple()`, `filtra_por_facturado()` (casos SÃ­ **y** No â€” el caso No es el que puede fallar si se usa `filled()` en vez de `has()`, ver T019), `filtra_por_medio_de_pago()`, `filtra_por_usuario_multiple()`, `filtra_por_nota_interna()`, `filtra_por_deposito()`, `filtra_por_servicio_desde_hasta()`.
- [X] T012 [P] [US2] Test de feature: `combina_filtros_con_and()` â€” aplica CategorÃ­a + DepÃ³sito + rango de servicio a la vez y verifica que el resultado es la intersecciÃ³n exacta.
- [X] T013 [P] [US2] Test de feature: `excluye_compras_sin_servicio_cargado_cuando_filtro_activo()` â€” compra con `servicio_desde`/`servicio_hasta` nulos no debe aparecer si se filtra por ese rango, y sÃ­ debe aparecer si no se filtra por Ã©l (Edge Case de la spec).

### Implementation for User Story 2

- [X] T014 [US2] En `CompraController::queryFiltrada()` (`app/Http/Controllers/CompraController.php`, despuÃ©s del bloque de `proveedor_id`), agregar el filtro `id`: `if ($request->filled('id')) { $query->where('id', (int) $request->input('id')); }` (mÃ¡s simple que el de Ventas â€” Compra no tiene `legacy_id`).
- [X] T015 [US2] Agregar el filtro `categoria_id`: `if ($request->filled('categoria_id')) { $query->whereIn('categoria_id', (array) $request->input('categoria_id')); }`.
- [X] T016 [US2] Agregar el filtro `estado_pago` resolviendo los 3 valores (`a_pagar`/`parcial`/`pagado`) de `Compra::estadoPago()` en SQL mediante `whereRaw` con la misma subconsulta agregada ya usada en `kpis()` (`CompraController.php:58-77`) â€” **no** cargar todas las compras en PHP y filtrar con `estadoPago()` fila por fila (ver research.md DecisiÃ³n 4, evita el N+1 ya documentado).
- [X] T017 [US2] Agregar el filtro `factura_buscar` (renombrar/reemplazar el actual `buscar` de la lÃ­nea 95-98): `where(fn ($q) => $q->where('tipo_comprobante', 'like', "%{$kw}%")->orWhereHas('comprobanteFiscal', fn ($qq) => $qq->where('numero', 'like', "%{$kw}%")))`, igual patrÃ³n que `VentaController.php:190-196`.
- [X] T018 [US2] Agregar el filtro `etiqueta_id`: `whereHas('etiquetas', fn ($q) => $q->whereIn('etiquetas.id', (array) $request->input('etiqueta_id')))`.
- [X] T019 [US2] Agregar el filtro `facturado`: usar `$request->has('facturado')` (**no** `filled()`) como guarda, porque `Request::filled()` trata el string `"0"` como vacÃ­o y el filtro "Facturado = No" nunca se aplicarÃ­a; luego `'1' => whereHas('comprobanteFiscal')`, `'0' => whereDoesntHave('comprobanteFiscal')`.
- [X] T020 [US2] Agregar el filtro `medio_pago_id`: `whereHas('pagos', fn ($q) => $q->where('cuenta_tesoreria_id', $request->input('medio_pago_id')))`.
- [X] T021 [US2] Agregar el filtro `usuario_id`: `whereIn('creado_por_id', (array) $request->input('usuario_id'))` (depende de T001/T002/T004 de Phase 1).
- [X] T022 [US2] Agregar el filtro `nota_interna`: `where('nota_interna', 'like', '%'.$request->input('nota_interna').'%')`.
- [X] T023 [US2] Agregar el filtro `deposito_id`: `where('deposito_id', $request->input('deposito_id'))` (columna directa, ya existente desde spec 049 â€” mÃ¡s simple que el patrÃ³n `whereHas('movimientosStock', ...)` de Ventas).
- [X] T024 [US2] Agregar los filtros `servicio_desde`/`servicio_hasta`: `whereDate('servicio_desde', '>=', ...)` / `whereDate('servicio_hasta', '<=', ...)`.
- [X] T025 [US2] En `resources/views/compras/index.blade.php`, reemplazar el panel de filtros (lÃ­neas 70-89) por los 12 campos reales: reusar el input Id existente de Ventas como referencia (`ventas/index.blade.php:118-121`) y replicar CategorÃ­a de Compra, Estado del Pago (select simple con las 3 opciones a_pagar/parcial/pagado â€” ver Clarifications de spec.md), Tipo y NÂ° de Factura (reemplaza al actual `filtro-buscar`), Etiqueta (multi), Facturado (select simple SÃ­/No), Medio de pago (select simple), Usuario (multi), Nota Interna, DepÃ³sito (select simple), Desde/Hasta Servicio (inputs `type="date"`), usando los catÃ¡logos pasados por T005.
- [X] T026 [US2] En `resources/js/compras.js`, extender `inicializarListado()`/`initSelect2()` para: inicializar Select2 en `#filtro-categoria` (multi), `#filtro-etiqueta` (multi), `#filtro-usuario` (multi), `#filtro-estado-pago`, `#filtro-facturado`, `#filtro-medio-pago`, `#filtro-deposito` (simples); y extender la funciÃ³n que arma los `data` de DataTables (`ajax.data`) para incluir todos los query params nuevos leyendo los valores de cada campo, igual patrÃ³n que `resources/js/ventas.js` para su bloque de filtros (ver el armado de `data` en `ventas.js`, cerca de la config de la tabla).
- [X] T027 [US2] Verificar/ajustar el botÃ³n `#btn-limpiar-filtros` en `compras.js` para que tambiÃ©n resetee los campos nuevos (mismo patrÃ³n que el limpiar de Ventas).

**Checkpoint**: Los 12 filtros funcionan de punta a punta, combinables con AND, con tests en verde.

---

## Phase 5: User Story 3 - Filtrar por Vencimiento y elegir columnas visibles (Priority: P2)

**Goal**: Segundo rango de fechas independiente (Vencimiento, sobre `fecha_vto_pago`) ademÃ¡s del ya existente de EmisiÃ³n, y selector de columnas visibles.

**Independent Test**: Elegir un rango en el nuevo control "Vencimiento" y verificar que filtra por `fecha_vto_pago`, combinÃ¡ndose con AND si ademÃ¡s hay un rango de EmisiÃ³n activo; abrir el selector de columnas y verificar que se pueden mostrar/ocultar columnas sin recargar.

### Tests for User Story 3

- [X] T028 [P] [US3] Test de feature: `filtra_por_rango_de_vencimiento()` â€” compra con `fecha_vto_pago` dentro del rango aparece, una fuera del rango no.
- [X] T029 [P] [US3] Test de feature: `excluye_compras_sin_vencimiento_cuando_ese_rango_esta_activo()` â€” compra con `fecha_vto_pago` nula no aparece si el rango de Vencimiento estÃ¡ activo.
- [X] T030 [P] [US3] Test de feature: `combina_rango_emision_y_vencimiento_con_and()`.

### Implementation for User Story 3

- [X] T031 [US3] Agregar los filtros `vencimiento_desde`/`vencimiento_hasta` en `CompraController::queryFiltrada()`: `whereDate('fecha_vto_pago', '>=', ...)` / `whereDate('fecha_vto_pago', '<=', ...)`, igual patrÃ³n que `VentaController.php:230-235`.
- [X] T032 [US3] En `resources/views/compras/index.blade.php`, agregar junto al control de rango existente (lÃ­nea ~67) el segundo `<input id="filtro-rango-vencimiento">` + botÃ³n de limpiar, igual estructura que `ventas/index.blade.php:107-110`.
- [X] T033 [US3] En `resources/views/compras/index.blade.php`, agregar el `<span id="dt-buttons-compras">` ya existente (lÃ­nea 67) â€” verificar que el botÃ³n `colvis` de DataTables ya estÃ¡ configurado en `compras.js`; si no, agregarlo con las columnas adicionales mÃ­nimas (CUIT, Servicio Desde, Servicio Hasta, TelÃ©fono, Mail) igual patrÃ³n que el `colvis` de `resources/js/ventas.js` (lÃ­nea ~319).
- [X] T034 [US3] En `resources/js/compras.js`, agregar el `daterangepicker` sobre `#filtro-rango-vencimiento` (apply/cancel/blur/limpiar), igual patrÃ³n que `ventas.js:229-249` para su propio `filtro-rango-vencimiento`, y sumar `vencimiento_desde`/`vencimiento_hasta` al armado de `data` de DataTables.
- [X] T035 [US3] Si las columnas adicionales (CUIT, TelÃ©fono, Mail del Proveedor) no estÃ¡n hoy expuestas por `CompraController::data()`, agregarlas como `addColumn` en `data()` (`CompraController.php:103-`), leyendo del `Proveedor` relacionado.

**Checkpoint**: Las 3 historias de usuario funcionan juntas; el listado de Compras iguala estructuralmente al de Contagram real.

---

## Phase 6: Polish & Cross-Cutting Concerns

**Purpose**: Cerrar el principio I de la constituciÃ³n (documentaciÃ³n de dominio) y dejar la feature lista para `analyze`.

- [X] T036 [P] Actualizar `docs/documentacion_principal_crm.md`: reemplazar la lista de filtros de Compras documentada hoy por los 12 filtros reales (FR-001 de spec.md), y documentar el nuevo rango de Vencimiento.
- [X] T037 [P] Actualizar `docs/informe_contagram_egresos.md` Â§2.2: reemplazar los 7 filtros listados por los 12 reales.
- [X] T038 [P] Actualizar `docs/modelo_datos.md` Â§7: corregir la nota "Compras no usa etiquetas (no confirmado...)" y documentar la columna nueva `creado_por_id` en la tabla `compras` (mismo formato que la fila de `deposito_id`, spec 049).
- [X] T039 Correr `php artisan test --filter=FiltrosCompraTest` y confirmar que todos los tests de T009-T013 y T028-T030 pasan.
- [ ] T040 Ejecutar manualmente el `quickstart.md` completo (los 8 escenarios) en el navegador, sobre datos de prueba locales.

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: sin dependencias â€” arranca de inmediato.
- **Foundational (Phase 2)**: depende de Setup (T001-T004) solo para T005/T021 (catÃ¡logo de Usuario y filtro `usuario_id` necesitan la columna `creado_por_id`); T006-T008 (Proveedor mÃºltiple) no dependen de Setup y podrÃ­an arrancar antes, pero se agrupan en Foundational por ser la base compartida del panel de filtros.
- **US1 (Phase 3)**: depende de Foundational (T006-T008) â€” es prÃ¡cticamente su verificaciÃ³n con tests.
- **US2 (Phase 4)**: depende de Foundational completo (necesita T005 para los catÃ¡logos de los `<select>` y T001-T004 para Etiqueta/Usuario).
- **US3 (Phase 5)**: depende de Foundational; independiente de US2 en el cÃ³digo (toca otros campos/columnas) pero comparte los mismos archivos (`CompraController.php`, `index.blade.php`, `compras.js`) â€” coordinar para evitar conflictos de ediciÃ³n si se trabaja en paralelo.
- **Polish (Phase 6)**: depende de que US1+US2+US3 estÃ©n implementadas.

### Parallel Opportunities

- T002 y T003 (Phase 1) tocan el mismo archivo (`Compra.php`) â€” no marcar `[P]` entre sÃ­ pese a ser conceptualmente independientes; sÃ­ pueden ir en un solo commit.
- T009-T013 (tests US2) son `[P]` entre sÃ­ (mismo archivo de test pero casos independientes â€” verificar que el runner de Pest/PHPUnit no bloquee escritura concurrente; si se prefiere estrictamente en paralelo, dividir en archivos de test separados por filtro).
- T028-T030 (tests US3) son `[P]` entre sÃ­ por el mismo criterio.
- T036-T038 (documentaciÃ³n) son totalmente `[P]` â€” archivos distintos, sin dependencias de cÃ³digo.

---

## Implementation Strategy

### MVP First (User Story 1 Only)

1. Completar Phase 1 (Setup) â€” necesaria igual para US2/US3, pero US1 en sÃ­ no depende de T001-T004.
2. Completar Phase 2 (Foundational, T006-T008) â†’ Proveedor multi funcional.
3. Completar Phase 3 (US1, tests).
4. **Parar y validar**: probar Proveedor mÃºltiple en el navegador â€” es el problema puntual reportado primero por el usuario.

### Incremental Delivery

1. Setup + Foundational â†’ Proveedor mÃºltiple listo (MVP, US1).
2. Agregar US2 â†’ los 12 filtros completos â†’ validar independientemente.
3. Agregar US3 â†’ Vencimiento + columnas â†’ validar independientemente.
4. Phase 6 â†’ documentaciÃ³n al dÃ­a, tests en verde, listo para `/speckit-analyze`.
