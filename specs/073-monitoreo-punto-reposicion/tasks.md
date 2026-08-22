# Tasks: Monitoreo, Punto de Reposición y Notificaciones

**Feature**: 073-monitoreo-punto-reposicion | **Date**: 2026-08-21

**Input**: [spec.md](./spec.md), [plan.md](./plan.md), [research.md](./research.md),
[data-model.md](./data-model.md), [contracts/](./contracts/), [quickstart.md](./quickstart.md)

**Tests**: SÍ. La constitución §IV los exige donde hay movimientos de stock, y esta feature además
migra datos reales del negocio.

---

## Phase 1: Setup

- [X] T001 Registrar el pagelevel `monitoreo` en `config/dz.php` con DataTables, Toastr y `js/custom.js` (tomar `auditoria` como referencia; **sin** Select2 ni daterangepicker: el panel no tiene selects dinámicos ni filtros de fecha — ver checklist CHK036/CHK037)
- [X] T002 [P] Agregar `resources/js/monitoreo.js` y `resources/js/monitoreo-topbar.js` al array `input` de `vite.config.js`
- [X] T003 [P] Crear los archivos vacíos `resources/js/monitoreo.js` y `resources/js/monitoreo-topbar.js` con el patrón de módulo usado en `resources/js/auditoria.js`

---

## Phase 2: Foundational (bloquea todas las historias)

**Nada de esto entrega valor solo, pero sin esto no arranca ninguna historia.**

- [X] T004 Crear migración `database/migrations/..._agregar_punto_reposicion_a_productos.php` con `unsignedInteger('punto_reposicion')->nullable()` después de `costo`
- [X] T005 Crear migración `database/migrations/..._crear_notificaciones_leidas.php` según `data-model.md` §2 (FK `user_id` cascadeOnDelete, `clave` string(190), `leida_en` timestamp, único `(user_id, clave)`, índice por `user_id`)
- [X] T006 [P] Agregar `punto_reposicion` a `$fillable` y a `$casts` (`'integer'`) en `app/Models/Producto.php`
- [X] T007 [P] Crear `app/Models/NotificacionLeida.php` (tabla `notificaciones_leidas`, `$fillable = ['user_id','clave','leida_en']`, cast de `leida_en` a datetime)
- [X] T008 Agregar el módulo `monitoreo` con las acciones `ver` y `gestionar` al catálogo de `database/seeders/PermisoSeeder.php` (descripciones según `data-model.md` §4). **No** tocar `RolSeeder`: Admin los recibe solo
- [X] T009 Crear `app/Support/Monitoreo/Alertas.php`: arma el conjunto de alertas vigentes (reposición contra Local; publicaciones ML fallando) y sus claves (`reposicion:{producto_id}`, `ml_stock:{ml_item_id}` — **sin** timestamp, ver `data-model.md` §2). Consultas directas con `DB::table`, sin depender de servicios del resto de la app
- [X] T010 Reemplazar el grupo `monitoreo` de `routes/web.php` (líneas ~544-552) por el grupo nuevo: `permiso:monitoreo.ver` para las lecturas y `permiso:monitoreo.gestionar` para las escrituras, con todas las rutas de `contracts/monitoreo-api.md`. `monitoreo.notificaciones.leer` va bajo `monitoreo.ver`
- [X] T011 Crear `tests/Feature/Monitoreo/MonitoreoAccesoTest.php`: sin permiso → 403 en pantalla y en `resumen`; con `ver` y sin `gestionar` → 403 en cada escritura; con `ver` → puede marcar leído

**Checkpoint**: migraciones corridas, permisos sembrados, rutas protegidas, test de acceso en verde.

---

## Phase 3: User Story 2 — Punto de reposición como atributo del producto (P2)

> **Se implementa antes que la US1 a propósito**: es el cimiento de los dos controles de stock y de
> las notificaciones, y su migración de datos toca la base real. La US1 se entrega igual de rápido
> después, y así no se construye dos veces el bloque de stock.

**Goal**: el punto de reposición vive en el producto, con el dato real ya migrado y la lista de
precios eliminada.

**Independent Test**: cargar un punto de reposición desde la ficha del producto, verificar que
persiste, que los valores importados quedaron migrados, y que la lista "Punto Reposición" no aparece
en ningún selector de listas de precios ni como columna del listado.

### Tests (primero — tocan datos reales)

- [X] T012 [P] [US2] Crear `tests/Feature/Monitoreo/MigracionPuntoReposicionTest.php` con los 8 casos fijados en `contracts/migracion-punto-reposicion.md` §Tests (dry-run no escribe, migra enteros, redondea decimales, negativo y cero → null, aborta con referencias, elimina limpio, idempotente, no pisa lo cargado a mano)
- [X] T013 [P] [US2] Crear `tests/Feature/Monitoreo/PuntoReposicionTest.php`: la regla `stock <= punto_reposicion` en sus **dos** formas (Local para reponer; Local+Full sólo publicados para riesgo ML); **el caso que separa los bloques**: Local 1 / Full 50 aparece en reponer y NO en riesgo; `null`/`0` no generan alerta; sin fila en `stocks` = 0; stock negativo entra; producto con variantes compara contra el total (FR-009, FR-010a, FR-011a, FR-018, FR-019)

### Implementación

- [X] T014 [US2] Crear `app/Console/Commands/MigrarPuntoReposicion.php` (`migracion:punto-reposicion`) con `--aplicar` y `--eliminar-lista`, **dry-run por defecto**, resumen verificable, transacción, idempotencia y verificación previa contra las 7 columnas de `contracts/migracion-punto-reposicion.md`. **Sin modo forzado**
- [X] T015 [US2] Agregar el campo **Punto de Reposición** al bloque de stock de `resources/views/productos/_modal_form.blade.php` (input numérico, opcional, con ayuda breve de qué significa vacío)
- [X] T016 [US2] Agregar `punto_reposicion` a la validación (`nullable|integer|min:0`) y al guardado en `app/Http/Controllers/ProductoController.php`, devolviendo el 422 de Laravel para que el modal lo muestre sin recargar (FR-004)
- [X] T017 [US2] Ocultar/deshabilitar el campo cuando el producto es de tipo servicio en `resources/js/productos.js` (FR-010, historia 2 escenario 4)
- [X] T018 [US2] Correr `migracion:punto-reposicion` en dry-run, revisar el resumen y **recién con OK explícito del usuario** correr `--aplicar` y luego `--aplicar --eliminar-lista` (quickstart §Escenario 0)

**Checkpoint**: el punto de reposición se carga y persiste desde la ficha; el dato real está migrado;
la lista de precios ya no existe.

---

## Phase 4: User Story 1 — Publicaciones de ML que no actualizan stock (P1, la crítica)

**Goal**: el bloque que el negocio marcó como imprescindible, funcionando con las reglas de diseño
del proyecto.

**Independent Test**: dejar una publicación con error de stock y verificar que se lista con su
motivo, antigüedad y stock real vs. publicado, y que Destrabar la deja encolada.

- [X] T019 [US1] Reescribir `resources/views/monitoreo/index.blade.php` para que extienda `layouts.default` con `@section('content')`, en lugar del HTML autocontenido actual (research §Decisión 1). Estructura de bloques según `contracts/monitoreo-api.md`, con anclas para `?bloque=`
- [X] T020 [US1] Reescribir `app/Http/Controllers/Monitoreo/MonitoreoController.php`: eliminar `UMBRAL_STOCK_BAJO` y `datos()`, y dejar `index()` + endpoint `publicaciones()` server-side con Yajra (`contracts/monitoreo-api.md`). Conservar las consultas directas (`DB::table`) y `DIAS_VELOCIDAD`/`MINUTOS_SIN_SYNC`
- [X] T021 [US1] Implementar la tabla DataTables server-side de publicaciones fallando en `resources/js/monitoreo.js`, con la marca visual de **moderación** (`under_review`/`forbidden`) que además oculta Destrabar (FR-017)
- [X] T022 [US1] Portar las acciones `destrabar()` y `reactivar()` al contrato nuevo (`{ok, mensaje}`) y conectarlas desde el JS con Toastr y refresco de la fila en el lugar, sin recargar (FR-021, FR-022, FR-023)
- [X] T023 [US1] Implementar el estado vacío explicativo del bloque ("todas las publicaciones sincronizadas") — historia 1 escenario 4

- [X] T023b [US1] **Anti-regresión**: portar en esta misma fase `pulso()`, `sinStock()`, `ordenes()` y `ventas()` (tareas T024 y T027 de la fase siguiente) **o** no desplegar hasta completar la US3. T019/T020 reescriben la vista y el controlador enteros, así que entre el fin de la US1 y el fin de la US3 el panel **pierde** bloques que hoy ya funcionan. No dejar esa ventana abierta en producción

**Checkpoint**: entrando a `/monitoreo` se ven y se destraban las publicaciones fallidas, sin
recargas. Entregable al negocio **siempre que se haya cerrado T023b** — si no, el panel queda peor
que hoy para todo lo que no sean publicaciones.

---

## Phase 5: User Story 3 — Panel completo rediseñado (P3)

**Goal**: el resto de los bloques, con el pulso, los dos controles de stock separados y todo lo que
el panel actual ya hacía.

**Independent Test**: entrar con y sin permiso; recorrer cada bloque; verificar que ninguna acción
recarga y que la falla de un bloque no tumba la pantalla.

- [X] T024 [US3] Implementar el endpoint `pulso()` (estado de las 2 syncs con su alerta de 15 min, interruptores, conteos) en `MonitoreoController` según el contrato
- [X] T025 [P] [US3] Implementar el endpoint `reponer()` (DataTables server-side, stock **Local** ≤ punto de reposición, todo el catálogo, con `stockFull`, `faltan` y proveedor) usando `App\Support\Monitoreo\Alertas`
- [X] T026 [P] [US3] Implementar el endpoint `riesgoMl()` (DataTables server-side, sólo publicados en ML, **Local + Full** ≤ punto de reposición, con desglose, `porDia`/`dias` y orden por urgencia, `null` al final). **No** usar `ml_configuracion.deposito_id` como si fuera un depósito distinto del Local: es el mismo (id 5)
- [X] T027 [P] [US3] Portar los endpoints `sinStock()`, `ordenes()` y `ventas()` al contrato nuevo, conservando la explicación de motivos en castellano y la marca `accionable` (FR-020)
- [X] T028 [US3] Implementar `sincronizar()` con el parámetro `que` (`ordenes`/`stock`), respondiendo `{ok, mensaje}` (FR-021)
- [X] T029 [US3] Implementar el endpoint y el modal de edición del punto de reposición desde el panel: `resources/views/monitoreo/_modal_punto_reposicion.blade.php` + `puntoReposicion()` en el controlador, devolviendo la fila reevaluada o `null` si el producto sale de la lista (FR-003)
- [X] T030 [US3] Montar en `resources/js/monitoreo.js` todas las tablas restantes, el pulso, el modal de punto de reposición y los estados vacíos de **cada** bloque (FR-024a)
- [X] T031 [US3] Implementar el aislamiento de fallas: cada bloque maneja su propio error sin tumbar la pantalla, y los bloques que dependen de ML informan cuando la integración está desconectada o sin depósito configurado (FR-024, FR-024b)
- [X] T032 [US3] Ocultar con `@can('monitoreo.gestionar')` todos los controles de escritura, para que no se rendericen a quien no puede usarlos (quickstart §Escenario 8)
- [ ] T033 [US3] Verificar legibilidad en pantalla de teléfono, que es desde donde más se consulta el panel hoy (FR-024c)
- [X] T034 [US3] Medir con el catálogo real (~8.400 productos) el `EXPLAIN` de las consultas de los dos bloques de stock y **sólo entonces** decidir si hace falta el índice `(deposito_id, cantidad)` en `stocks` (research §Decisión 5)

**Checkpoint**: panel completo, con todo lo que el panel viejo hacía más los dos controles nuevos.

---

## Phase 6: User Story 4 — Acceso rápido desde la barra superior (P4)

**Goal**: ver el problema sin entrar a ninguna pantalla.

**Independent Test**: provocar las tres condiciones y verificar que el desplegable las refleja y
lleva al bloque correcto.

- [X] T035 [US4] Crear `app/Http/Controllers/Monitoreo/MonitoreoResumenController.php` con el endpoint `resumen` del contrato: conteos + muestra de 5 por bloque. **Barato**: se llama desde todas las pantallas
- [X] T036 [US4] Agregar el indicador de Monitoreo y su desplegable de tres bloques a `resources/views/elements/header.blade.php`, envuelto en `@can('monitoreo.ver')`. **Sin** el bloque de órdenes sin venta (FR-027)
- [X] T037 [US4] Implementar en `resources/js/monitoreo-topbar.js` el fetch del resumen, el pintado del desplegable, el resaltado del indicador sólo cuando hay algo que atender, y el refresco cada 5 minutos (FR-029, FR-037a)
- [X] T038 [US4] Cargar `monitoreo-topbar.js` desde `resources/views/layouts/default.blade.php` **sólo** con `@can('monitoreo.ver')`, para que sin permiso no haya ni una llamada (FR-025)
- [X] T039 [US4] Soportar `?bloque=` en `monitoreo.index` para abrir posicionado en el bloque elegido desde el desplegable (FR-028)

**Checkpoint**: el problema se ve desde cualquier pantalla del sistema.

---

## Phase 7: User Story 5 — Notificaciones (P5)

**Goal**: la campanita avisa sola, y lo leído no vuelve a molestar — pero un problema que reaparece
sí vuelve a avisar.

**Independent Test**: bajar un producto bajo su punto, marcar leído, reponer, volver a bajar, y
verificar que reaparece **como no leída**.

### Tests (primero — acá está el bug más fácil de introducir)

- [X] T040 [P] [US5] Crear `tests/Feature/Monitoreo/NotificacionesTest.php`: la alerta aparece; marcar leída baja el contador de ese usuario y no el de otro; al resolverse desaparece sola y su marca se descarta; **al reaparecer cuenta como no leída** (FR-035, historia 5 escenario 6); **una venta que deja al producto igual de bajo NO vuelve a marcarla como no leída** (el defecto que tenía la clave con timestamp, ver `research.md` §Decisión 3); "marcar todas" no silencia lo que apareció después (FR-036a)

### Implementación

- [X] T041 [US5] Extender `App\Support\Monitoreo\Alertas` para emitir las notificaciones con `clave`, `tipo`, `titulo`, `detalle`, `cuando` y `url`, cruzadas con `notificaciones_leidas` del usuario autenticado
- [X] T042 [US5] Agregar el bloque `notificaciones` al endpoint `resumen`, con `sinLeer` como total real (no el de la muestra) y la limpieza oportunista de marcas huérfanas (`data-model.md` §2)
- [X] T043 [US5] Implementar `POST monitoreo/notificaciones/leer` (una clave, varias, o `todas`) devolviendo el `sinLeer` actualizado (FR-034)
- [X] T044 [US5] Activar la campanita en `resources/views/elements/header.blade.php`: quitar `d-none`, borrar el contenido de demostración del template y dejar el contenedor que llena el JS (FR-030)
- [X] T045 [US5] Pintar las notificaciones y el badge desde `resources/js/monitoreo-topbar.js` (misma llamada que el indicador — research §Decisión 7), con marcar-una y marcar-todas, y navegación al destino de cada una (FR-037)
- [X] T046 [US5] Manejar la pérdida de permiso con la sesión abierta: en el siguiente refresco los indicadores desaparecen sin romper la pantalla (FR-036)

**Checkpoint**: la feature completa.

---

## Phase 8: Polish & cierre

- [X] T047 [P] Ratificar que `docs/documentacion_principal_crm.md` (§2 Punto de Reposición, §5.1 Monitoreo, §7 nota sobre el módulo Notificaciones pendiente) y `docs/modelo_datos.md` (columna, `notificaciones_leidas`, baja de la lista de precios, módulo de permisos) reflejen lo realmente implementado (FR-038)
- [X] T048 [P] Correr `php artisan test --filter=Monitoreo` y dejar la suite en verde
- [ ] T049 Ejecutar los 10 escenarios de [quickstart.md](./quickstart.md) **en el navegador contra MySQL**. La suite verde no alcanza: corre en SQLite y MySQL es estricto con `ONLY_FULL_GROUP_BY` — los `groupBy` de velocidad de venta son exactamente el tipo de consulta que se rompe ahí
- [X] T050 Si para probar se creó o reseteó algún acceso, anotarlo en `CREDENCIALES_ACCESO.txt` en el mismo cambio
- [X] T051 Revisar el checklist [calidad.md](./checklists/calidad.md) contra lo implementado y marcar lo que quedó cubierto

---

## Dependencias

```
Setup (T001-T003)
   └─> Foundational (T004-T011)   ← bloquea todo
          ├─> US2 (T012-T018)     ← primero: cimiento + migración de datos reales
          │      └─> US3 bloques de stock (T025, T026, T029)
          ├─> US1 (T019-T023)     ← entregable al negocio por sí solo
          │      └─> US3 resto del panel (T024, T027-T034)
          │             └─> US4 (T035-T039)
          │                    └─> US5 (T040-T046)
          └─> Polish (T047-T051)
```

- **US1 depende de US2** sólo para los bloques de stock; su propio bloque (publicaciones ML) no usa
  el punto de reposición y se puede hacer en paralelo.
- **US4 depende de US3** para tener bloques a los que navegar, y de US2 para el conteo "a reponer".
- **US5 depende de US4**: comparte el endpoint y el archivo JS.

## Paralelizables

| Grupo | Tareas |
|---|---|
| Setup | T002, T003 |
| Foundational | T006, T007 |
| Tests de US2 | T012, T013 |
| Endpoints de US3 | T025, T026, T027 |
| Polish | T047, T048 |

## MVP sugerido

**Foundational + US2 + US1, incluyendo T023b** (T001-T023b, que arrastra T024 y T027). Entrega: el
punto de reposición como dato real del negocio (con la lista de precios ya limpiada) y el bloque de
publicaciones de ML que no actualizan stock — lo único que el negocio ya usaba a diario y lo que
marcó como imprescindible — **sin perder** ninguno de los bloques que el panel actual ya tiene.

> El MVP **no** puede ser T001-T023 a secas: T019/T020 reescriben la vista y el controlador enteros,
> así que desplegar ahí dejaría el panel sin pulso, sin órdenes sin venta, sin últimas ventas y sin
> el bloque de sin stock. Sería un cambio que le saca funcionalidad al usuario.

## Recuento

| Fase | Tareas |
|---|---|
| Setup | 3 |
| Foundational | 8 |
| US2 (P2) | 7 |
| US1 (P1) | 5 |
| US3 (P3) | 11 |
| US4 (P4) | 5 |
| US5 (P5) | 7 |
| Polish | 5 |
| **Total** | **51** |
