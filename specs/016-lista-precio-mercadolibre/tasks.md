# Tasks: Lista de Precios en la configuración de Mercado Libre

**Spec**: [spec.md](./spec.md) · **Plan**: [plan.md](./plan.md) · **Datos**: [data-model.md](./data-model.md) · **Contratos**: [contracts/rutas-internas.md](./contracts/rutas-internas.md) · **Validación**: [quickstart.md](./quickstart.md)

**Branch**: `016-lista-precio-mercadolibre` · **Fecha**: 2026-07-29

**Tests**: incluidos y **obligatorios** — el principio IV de la constitución y la spec (§Restricciones de
diseño y entorno) los exigen para FR-003/FR-004/FR-005/FR-006, por tocar la creación de la Venta desde
Mercado Libre. FR-005 (no alterar precios) es la garantía central: sin su test, un futuro cambio en
`ConversorOrdenAVenta` podría introducir una regresión de cálculo sin que nada la detecte.

**Convención**: `[P]` = paralelizable (archivo distinto, sin dependencias pendientes). `[USn]` = historia
de usuario a la que pertenece.

---

## Phase 1 — Setup

- [ ] T001 Confirmar que no hace falta ninguna dependencia nueva (Composer/NPM): Select2, Toastr,
  DataTables y toda la infraestructura de Mercado Libre ya existen desde las specs 011/012/013
  (plan.md §Technical Context)

---

## Phase 2 — Foundational (bloquea todas las historias)

- [ ] T002 Migración `add_lista_precio_field_to_ml_configuracion_table`: agrega
  `lista_precio_id` (`$table->foreignId('lista_precio_id')->nullable()->after('categoria_venta_id')->constrained('listas_precio')->nullOnDelete();`),
  con `down()` simétrico (`dropConstrainedForeignId('lista_precio_id')`) — data-model.md
  §`ml_configuracion`, research.md R1
- [ ] T003 [P] Extender `app/Models/Integraciones/MercadoLibreConfiguracion.php`: agregar
  `lista_precio_id` a `$fillable` y el método `listaPrecio(): BelongsTo` (`belongsTo(\App\Models\ListaPrecio::class, 'lista_precio_id')`),
  mismo patrón que `deposito()`/`categoriaVenta()` ya presentes — data-model.md §Relación

**Checkpoint**: columna y modelo listos — las historias de usuario pueden empezar.

---

## Phase 3 — US1: Configurar la Lista de Precios de Mercado Libre (Priority: P1)

**Objetivo**: que el usuario pueda elegir, desde la pantalla de configuración de Mercado Libre, qué
Lista de Precios se va a asignar a las Ventas convertidas. **Test independiente**: entrar a
Configuración → Integraciones → Mercado Libre, elegir una Lista de Precios, guardar, y verificar que
persiste al recargar (quickstart.md §Escenario 1).

### Tests para US1

- [ ] T004 [P] [US1] Feature test en `tests/Feature/Integraciones/MercadoLibreProgramacionTest.php`
  (extender el existente — es el único archivo que hoy ejercita
  `route('configuracion.mercadolibre.ventas.configurar')`; **no** usar `MercadoLibreConfiguracionTest.php`,
  que cubre la pantalla de credenciales OAuth, dominio distinto): `PATCH /configuracion/mercadolibre/ventas`
  con `lista_precio_id` válido persiste el valor (FR-001); con el campo vacío/`null` guarda sin error
  (FR-002); con un id que no existe en `listas_precio`, la request se rechaza con 422 y **no** modifica
  el resto de la configuración (FR-007) — contracts/rutas-internas.md

### Implementación US1

- [ ] T005 [US1] Extender `MercadoLibreConfiguracionController::index()`
  (`app/Http/Controllers/Integraciones/MercadoLibreConfiguracionController.php`): agregar
  `$listasPrecio = \App\Models\ListaPrecio::where('activo', true)->orderBy('nombre')->get();` y pasarlo a
  la vista junto a `$depositos`/`$categoriasVenta` — mismo query que ya usa para `$categoriasVenta`
- [ ] T006 [US1] Extender `app/Http/Requests/Integraciones/GuardarConfiguracionVentasMercadoLibreRequest.php`:
  agregar `'lista_precio_id' => ['nullable', 'exists:listas_precio,id']` a `rules()`, mismo patrón que
  `categoria_venta_id` (FR-002, FR-007)
- [ ] T007 [US1] Extender `resources/views/configuracion/mercadolibre/index.blade.php`: agregar
  `<select id="ml-lista-precio-id" class="form-select" style="width:100%">` con opción `"" => "Sin lista
  de precios"` + `@foreach ($listasPrecio as $lista)`, en la misma sección "Configuración de Ventas"
  junto a Depósito y Categoría de Venta (FR-001)
- [ ] T008 [US1] Extender `MercadoLibreConfiguracionController::estado()`: agregar `lista_precio_id` al
  bloque `configuracion` de la respuesta JSON (contracts/rutas-internas.md §Endpoint de estado) —
  **precede a T009**: la carga de valores existentes del `<select>` en el JS lee este campo de la
  respuesta de `estado()`, así que sin este cambio T009 no tendría de dónde prefijar el valor guardado
- [ ] T009 [US1] Extender `resources/js/mercadolibre.js`: agregar `#ml-lista-precio-id` al selector
  conjunto de Select2 (línea ~106), a la carga de valores existentes (línea ~134, mismo patrón
  `.val(conf.lista_precio_id || '').trigger('change.select2')`, leyendo el campo que T008 agrega a
  `estado()`) y al payload de guardado (línea ~204, `lista_precio_id: $('#ml-lista-precio-id').val() || null`)

**Checkpoint**: US1 funcional y testeable de forma independiente — el campo se configura y persiste,
aunque todavía no tenga ningún efecto sobre las Ventas convertidas.

---

## Phase 4 — US2: Que la Venta convertida quede etiquetada con esa Lista de Precios (Priority: P1)

**Objetivo**: que la Venta creada al convertir una orden de Mercado Libre quede clasificada bajo la
Lista de Precios configurada, sin alterar ningún precio. **Test independiente**: configurar una Lista de
Precios (US1), convertir una orden, y verificar que la Venta trae esa Lista asignada con los mismos
precios de línea que antes de esta spec (quickstart.md §Escenario 2).

### Tests para US2

- [ ] T010 [P] [US2] Feature test en `tests/Feature/Integraciones/MercadoLibreConversionTest.php`
  (extender el existente — es el archivo dedicado a la conversión orden→Venta, misma suite que ya cubre
  derivación de comprobante, cliente y no-duplicación; **no** `MercadoLibreClienteNuevoTest.php`, que es
  específico de la detección de cliente nuevo, dominio distinto): con Lista de Precios configurada,
  convertir una orden (manual) asigna esa Lista a `venta.lista_precio_id` (FR-003)
- [ ] T011 [P] [US2] Feature test en `tests/Feature/Integraciones/MercadoLibreCreacionAutomaticaTest.php`
  (extender el existente — es el archivo dedicado al camino de creación automática): convertir la
  **misma** orden vía creación automática (`ConversorOrdenAVenta::convertir(..., automatica: true)`)
  produce idéntico `lista_precio_id` que la conversión manual de T010 (FR-003, sin distinción
  manual/automática)
- [ ] T012 [P] [US2] Feature test en `tests/Feature/Integraciones/MercadoLibreConversionTest.php` (mismo
  archivo que T010): sin ninguna Lista de Precios configurada (`lista_precio_id` null en
  `ml_configuracion`), la Venta se crea con `lista_precio_id` null y sin error (FR-004)
- [ ] T013 [P] [US2] Feature test en `tests/Feature/Integraciones/MercadoLibreImportesTest.php` (extender
  el existente — es el archivo dedicado exclusivamente a verificar que los importes/IVA de la Venta
  coinciden con lo pagado en Mercado Libre; es la home natural de esta prueba, no uno nuevo) — **crítico,
  cubre FR-005**: con Lista de Precios configurada, el `total`, el `subtotal_con_descuento` y el
  `precio_unitario` de cada línea de la Venta convertida son **idénticos** a los que produce el mismo
  escenario sin ninguna Lista de Precios configurada (mismo fixture de orden, dos conversiones, comparar
  montos) — demuestra que la Lista de Precios no participa del cálculo
- [ ] T014 [P] [US2] Feature test en `tests/Feature/Integraciones/MercadoLibreConversionTest.php` (mismo
  archivo que T010/T012): cambiar `ml_configuracion.lista_precio_id` **después** de convertir una orden
  no modifica el `lista_precio_id` de la Venta ya creada; una nueva conversión posterior usa el valor
  nuevo (FR-006, SC-005)

### Implementación US2

- [ ] T015 [US2] Extender `ConversorOrdenAVenta::convertir()`
  (`app/Services/MercadoLibre/ConversorOrdenAVenta.php`, dentro del array de `Venta::create()`, línea
  ~149-161): agregar `'lista_precio_id' => MercadoLibreConfiguracion::actual()->lista_precio_id,` junto a
  `'categoria_id' => ...` — research.md R3. **No tocar** `armarLineas()` ni ningún cálculo de precio/IVA
  (FR-005)

**Checkpoint**: US1 y US2 funcionan juntas — la Venta convertida queda clasificada por Lista de Precios
sin ningún cambio en sus montos.

---

## Phase 5 — Polish & Cross-Cutting Concerns

- [ ] T016 [P] Correr la suite completa de `tests/Feature/Integraciones/` (en particular los tests ya
  existentes de `ConversorOrdenAVenta` de la spec 012) y confirmar que sigue en verde — regresión mínima
  (quickstart.md §Regresión mínima)
- [ ] T017 Ejecutar `quickstart.md` end-to-end contra el entorno local (migración, build de assets,
  Escenario 1 y Escenario 2) antes de dar la feature por terminada

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: sin dependencias — arranca de inmediato.
- **Foundational (Phase 2)**: depende de Setup — **bloquea** ambas historias de usuario.
- **US1 (Phase 3)** y **US2 (Phase 4)**: ambas dependen sólo de Foundational. US2 no depende
  funcionalmente de que US1 esté implementada (`ConversorOrdenAVenta` lee la columna directamente de la
  base, no de la pantalla), pero probarla de punta a punta requiere poder configurar el valor primero —
  por eso se ordenan secuencialmente en este documento.
- **Polish (Phase 5)**: depende de que US1 y US2 estén completas.

### Parallel Opportunities

- T003 (modelo) puede correr en paralelo con nada más en Phase 2 — T002 (migración) debe completarse
  antes, porque el `fillable`/relación referencian una columna que la migración crea.
- Dentro de US1: T004 (test) en paralelo con nada de implementación (debe fallar primero); T005/T006/T007
  tocan archivos distintos y sin dependencias entre sí; T009 (JS) depende de que T007 (blade, crea el
  `<select>` que el JS referencia) **y** T008 (endpoint `estado()`, expone el valor a prefijar) estén
  hechos antes — no marcado `[P]` respecto de ninguno de los dos.
- Dentro de US2: T010-T014 (todos tests) tocan sólo 3 archivos existentes (T010/T012/T014 comparten
  `MercadoLibreConversionTest.php`; T011 y T013 van cada uno en el suyo) y pueden escribirse en paralelo
  entre sí antes de T015; T015 es la única tarea de implementación de la fase.
- T016/T017 (Polish) en paralelo entre sí.

---

## Parallel Example: Foundational

```bash
# T002 primero (crea la columna); T003 después (la referencia):
Task: "Migración add_lista_precio_field_to_ml_configuracion_table"
# luego:
Task: "Extender MercadoLibreConfiguracion.php: fillable + listaPrecio()"
```

## Parallel Example: US2 (tests)

```bash
Task: "Test: conversión manual asigna Lista de Precios (FR-003)"
Task: "Test: conversión automática produce el mismo resultado (FR-003)"
Task: "Test: sin configuración, Venta sin Lista de Precios (FR-004)"
Task: "Test: precios de línea idénticos con y sin Lista de Precios configurada (FR-005)"
Task: "Test: cambio de configuración no es retroactivo (FR-006)"
```

---

## Implementation Strategy

### MVP First (US1 + US2 — ambas P1)

A diferencia de specs anteriores con un US1 aislado como MVP, acá **ambas historias son P1** y ninguna
entrega valor real por separado: US1 sin US2 deja un campo de configuración sin ningún efecto; US2 sin
US1 no tiene forma de configurarse desde la interfaz (aunque técnicamente podría probarse escribiendo el
valor directo en la base, como hacen los tests de T010-T014). El MVP de esta spec es **Setup +
Foundational + US1 + US2 completas**, que es además el alcance total de la spec — no hay una entrega
incremental más chica que tenga sentido de negocio.

### Incremental Delivery

1. Setup + Foundational → columna y modelo listos.
2. US1 → pantalla de configuración operativa (sin efecto visible todavía en Ventas).
3. US2 → efecto completo: Ventas convertidas quedan clasificadas.
4. Polish → confirmar ausencia de regresión y validar quickstart completo.

---

## Notes

- `[P]` = archivos distintos, sin dependencias pendientes.
- `[USn]` mapea la tarea a su historia de usuario para trazabilidad.
- FR-008 (no sincronizar precios hacia Mercado Libre) no genera una tarea propia: es un límite negativo
  que ya se cumple por **no** tocar ningún código de publicación/sincronización de Mercado Libre — no
  hay nada que implementar para cumplirlo, sólo abstenerse de tocar esos archivos.
- Commit después de cada tarea o grupo lógico.
