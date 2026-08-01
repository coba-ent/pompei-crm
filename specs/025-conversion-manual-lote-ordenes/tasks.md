# Tasks: Conversión manual en lote de órdenes a Venta (Tiendanube y MercadoLibre)

**Input**: Design documents from `/specs/025-conversion-manual-lote-ordenes/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/rutas-internas.md, quickstart.md

**Tests**: Incluidos — la constitución del proyecto (principio IV) exige tests para toda lógica que
crea Ventas/dinero; esta feature genera Ventas, así que los tests no son opcionales.

**Organization**: Tareas agrupadas por user story (spec.md). Tiendanube y MercadoLibre son dos
stacks paralelos e independientes — la mayoría de las tareas tienen un par (ML/TN) marcado `[P]`
por tocar archivos distintos.

## Format: `[ID] [P?] [Story] Description`

## Path Conventions

Monolito Laravel — rutas relativas a la raíz del repo (`app/`, `resources/`, `routes/`, `tests/`).

---

## Phase 1: Setup

**Purpose**: Rutas nuevas, prerrequisito compartido antes de poder implementar cualquier historia.

- [X] T001 Agregar las dos rutas `POST transformar-todas-en-venta` en `routes/web.php`: una dentro
      del grupo `ingresos.mercadolibre` → `MercadoLibreVentaController@transformarTodasEnVenta`, y
      otra dentro de `ingresos.tiendanube` → `TiendanubeVentaController@transformarTodasEnVenta`.
      Ubicarlas junto a `sincronizar`/`sincronizar-stock`, **antes** de las rutas `{orden}`
      genéricas de cada grupo (mismo cuidado ya documentado ahí para `vinculaciones`, ver
      `routes/web.php` líneas 184-225).

**Checkpoint**: rutas resueltas (ambas devuelven 404/501 hasta que exista el controller — se
completa en Fase 3).

---

## Phase 2: Foundational

*No aplica.* Tiendanube y MercadoLibre son dos stacks totalmente independientes (servicios,
controllers, vistas y JS propios); no hay infraestructura compartida adicional que deba construirse
antes de las historias de usuario, más allá de las rutas de la Fase 1.

---

## Phase 3: User Story 1 - Convertir en lote cuando el modo automático está apagado (Priority: P1) 🎯 MVP

**Goal**: Botón "Transformar todas en Venta" que, en un único POST síncrono, convierte todas las
órdenes "Lista para convertir" de la conexión y muestra un resumen agregado (toast).

**Independent Test**: Con varias órdenes "Lista para convertir" y modo automático desactivado,
apretar el botón y verificar que todas quedan "Convertida" con su Venta asociada, y que aparece un
toast con el conteo correcto.

### Tests for User Story 1 ⚠️

> Escribir estos tests primero y verificar que fallan antes de implementar.

- [X] T002 [P] [US1] Feature test en `tests/Feature/Integraciones/MercadoLibreTransformarEnVentaTest.php`:
      (a) lote con N órdenes "Lista" y datos válidos → todas quedan "Convertida" con `venta_id` y
      `convertida_por` = usuario logueado, respuesta `{ok:true, total:N, convertidas:N, fallidas:0}`;
      (b) función avanzada "mercadolibre" desactivada → respuesta de bloqueo, ninguna orden cambia
      de estado; (c) `modo_solo_lectura` activo → mismo bloqueo; (d) sin órdenes "Lista" → respuesta
      `{total:0, convertidas:0, fallidas:0}` sin error; (e) órdenes en otros estados (Pendiente de
      pago, Convertida, Cancelada) quedan intactas y fuera del conteo.
- [X] T003 [P] [US1] Feature test análogo en `tests/Feature/Integraciones/TiendanubeTransformarEnVentaTest.php`
      con los mismos 5 casos contra `tn_ordenes` y la función avanzada "tiendanube".

### Implementation for User Story 1

- [X] T004 [P] [US1] Agregar `public function convertirTodasLasListas(?int $usuarioId): array` a
      `app/Services/MercadoLibre/ConversorOrdenAVenta.php`: reusa los mismos dos guardrails que
      `SincronizadorOrdenes::verificarCortes()` (función avanzada "mercadolibre" activa,
      `MercadoLibreConfiguracion::actual()->modo_solo_lectura` desactivado) — si alguno bloquea,
      devuelve `{ok:false, tipo:'bloqueada', mensaje}` sin tocar ninguna orden; si no, itera
      `MercadoLibreOrden::where('estado_conversion', EstadoConversion::Lista)->get()` y llama
      `$this->convertir($orden, $usuarioId, automatica: false)` por cada una (misma ruta que la
      conversión individual y que `intentarCreacionAutomatica`, con su candado por orden ya
      existente), acumulando `total`/`convertidas`/`detalle_fallidas` (con `orden` = `ml_order_id`,
      `motivo` = `etiqueta()` del enum, `motivo_detalle`). Devuelve
      `{ok:true, mensaje, total, convertidas, fallidas, detalle_fallidas}`.
- [X] T005 [P] [US1] Agregar método análogo `convertirTodasLasListas` en
      `app/Services/Tiendanube/ConversorOrdenAVenta.php`, iterando `TiendanubeOrden` y reusando los
      guardrails de `SincronizadorOrdenes` de Tiendanube (función avanzada "tiendanube",
      `TiendanubeConexionRest::actual()->modo_solo_lectura`), con `orden` = `tn_order_id`.
- [X] T006 [US1] Agregar acción `transformarTodasEnVenta(Request $request, \App\Services\MercadoLibre\ConversorOrdenAVenta $conversor): JsonResponse`
      en `app/Http/Controllers/Ingresos/MercadoLibreVentaController.php`: llama a
      `$conversor->convertirTodasLasListas($request->user()->id)` y devuelve el array tal cual como
      JSON (200 si `ok`, 409 si bloqueada — mismo criterio que el resto del controller).
- [X] T007 [US1] Acción análoga `transformarTodasEnVenta(Request $request, \App\Services\Tiendanube\ConversorOrdenAVenta $conversor): JsonResponse`
      en `app/Http/Controllers/Ingresos/TiendanubeVentaController.php` — namespace distinto al de
      T006, atención al inyectar la clase correcta en cada controller.
- [X] T008 [P] [US1] Agregar botón `id="btn-transformar-todas-en-venta-ml"` ("Transformar todas en
      Venta") en el header de `resources/views/ingresos/mercadolibre/index.blade.php`, junto a
      "Sincronizar ahora" / "Sincronizar stock ahora", siempre visible (sin condicional sobre
      `creacion_automatica`).
- [X] T009 [P] [US1] Botón análogo `id="btn-transformar-todas-en-venta-tn"` en
      `resources/views/ingresos/tiendanube/index.blade.php`.
- [X] T010 [P] [US1] Agregar `inicializarTransformarTodasEnVenta()` en `resources/js/mercadolibre-ventas.js`,
      calcado del patrón `inicializarVinculacionAutomatica` de `mercadolibre-vinculaciones.js`:
      deshabilita el botón al hacer click, `POST` a la ruta nueva, toast de éxito/error con
      `resp.mensaje`, recarga el DataTable (`tabla.ajax.reload(null, false)`) si `convertidas > 0`,
      rehabilita el botón en `always()`.
- [X] T011 [P] [US1] Función análoga en `resources/js/tiendanube-ventas.js`.

**Checkpoint**: US1 funcional e independientemente verificable — el botón convierte el lote y
notifica el resultado agregado por toast (sin detalle de fallos todavía).

---

## Phase 4: User Story 2 - Ver el detalle de lo que falló y por qué (Priority: P1)

**Goal**: Modal con la tabla de órdenes fallidas (identificador, motivo, explicación) cuando el
lote tuvo al menos una falla.

**Independent Test**: Con un lote que incluya una orden sabidamente no convertible (ej. cliente
ambiguo), correr el batch y verificar que el modal lista esa orden con motivo y detalle, sin que el
resto del lote se vea afectado.

### Tests for User Story 2 ⚠️

- [X] T012 [P] [US2] Ampliar `MercadoLibreTransformarEnVentaTest.php`: caso con una orden "Lista" y
      cliente ambiguo (dos `Cliente` con el mismo apodo ML) → respuesta incluye
      `detalle_fallidas: [{orden, motivo: 'Más de un Cliente con el mismo apodo de Mercado Libre', motivo_detalle}]`,
      y las demás órdenes del lote sí quedan convertidas.
- [X] T013 [P] [US2] Ampliar `TiendanubeTransformarEnVentaTest.php`: caso análogo con una orden sin
      cuenta de Tesorería configurada.

### Implementation for User Story 2

- [X] T014 [P] [US2] Agregar modal Bootstrap `#modal-resultado-transformar-venta-ml` en
      `resources/views/ingresos/mercadolibre/index.blade.php` con resumen + tabla
      (Orden/Motivo/Detalle), calcado del markup de `#modal-resultado-vinculacion-automatica`
      (`mercadolibre-vinculaciones.js`).
- [X] T015 [P] [US2] Modal análogo `#modal-resultado-transformar-venta-tn` en
      `resources/views/ingresos/tiendanube/index.blade.php`.
- [X] T016 [US2] En `resources/js/mercadolibre-ventas.js`, agregar
      `renderResultadoTransformarEnVenta(resp)` (calcado de `renderResultadoVinculacionAutomatica`)
      y mostrar el modal cuando `resp.fallidas > 0`; cuando `fallidas === 0` sólo queda el toast de
      la Fase 3 (sin abrir modal). (depende de T010)
- [X] T017 [US2] Función análoga en `resources/js/tiendanube-ventas.js`. (depende de T011)

**Checkpoint**: US1 + US2 completas — el botón informa tanto el resumen como el detalle de fallos,
en ambas integraciones.

---

## Phase 5: User Story 3 - Uso del botón como "forzar ya" con modo automático activo (Priority: P2)

**Goal**: Confirmar que el botón funciona igual (sin bloquearse ni ocultarse) cuando la creación
automática está activa, y que no genera Ventas duplicadas si compite con una sincronización
automática en curso.

**Independent Test**: Con `creacion_automatica = true` y una orden recién pasada a "Lista", apretar
el botón manualmente y verificar que convierte igual; simular una carrera con
`intentarCreacionAutomatica` y verificar que no se duplica la Venta.

### Tests for User Story 3 ⚠️

- [X] T018 [P] [US3] Test en `MercadoLibreTransformarEnVentaTest.php`: con
      `MercadoLibreConfiguracion::actual()->creacion_automatica = true`, el endpoint igual procesa
      el lote completo (el botón no depende de ese flag).
- [X] T019 [P] [US3] Test análogo en `TiendanubeTransformarEnVentaTest.php`.
- [X] T020 [P] [US3] Test de no-duplicación en `MercadoLibreTransformarEnVentaTest.php`: con una
      orden "Lista", invocar `intentarCreacionAutomatica`-equivalente y `convertirTodasLasListas`
      sobre la misma orden (simulando que el candado de la segunda llamada ya está tomado o que la
      primera ya creó la Venta) y verificar que sólo existe una `Venta` asociada, sin excepción no
      controlada.
- [X] T021 [P] [US3] Test análogo de no-duplicación en `TiendanubeTransformarEnVentaTest.php`.

### Implementation for User Story 3

No requiere código nuevo: el botón ya se implementó siempre visible (T008/T009, sin condicional
sobre `creacion_automatica`) y la no-duplicación ya la garantiza el candado por orden existente en
`convertir()` (reusado por T004/T005). Esta fase es puramente de verificación vía tests.

**Checkpoint**: las tres historias de usuario quedan cubiertas y verificadas de forma independiente.

---

## Phase 6: Polish & Cross-Cutting Concerns

- [X] T022 [P] Correr los 5 escenarios de `specs/025-conversion-manual-lote-ordenes/quickstart.md`
      en el ambiente local (XAMPP) contra datos de prueba, para ambas integraciones.
- [X] T023 [P] Correr la suite completa de tests (`php artisan test` o `./vendor/bin/pest`) y
      confirmar verde, incluidos los tests nuevos de T002/T003/T012/T013/T018-T021.
- [X] T024 Revisar que el botón, al deshabilitarse durante el procesamiento (FR-011), no pueda
      disparar un segundo POST concurrente desde el mismo navegador (doble click) — verificación
      manual rápida en el navegador, ya que es un detalle de UI difícil de cubrir con Feature tests
      de backend.

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: sin dependencias — rutas nuevas.
- **Foundational (Phase 2)**: no aplica.
- **US1 (Phase 3)**: depende de Phase 1 (rutas). Es el MVP.
- **US2 (Phase 4)**: depende de US1 (reusa el mismo endpoint y el mismo JS de inicialización — T016/T017 extienden T010/T011).
- **US3 (Phase 5)**: depende de US1 (reusa el endpoint) — sin dependencia real de US2, podría hacerse en paralelo a la Fase 4.
- **Polish (Phase 6)**: depende de que US1/US2/US3 estén completas.

### Parallel Opportunities

- T002/T003 (tests ML/TN) en paralelo entre sí.
- T004/T005 (servicios ML/TN) en paralelo entre sí, una vez existen T002/T003.
- T008/T009 y T010/T011 (vistas/JS ML vs TN) en paralelo entre sí.
- Toda la Fase 4 (US2) puede empezar en paralelo a la Fase 5 (US3) una vez cerrada la Fase 3.
- Dentro de cada fase, las tareas ML y TN son independientes entre sí (archivos distintos) — sólo
  no son paralelas entre sí las tareas que tocan el **mismo** archivo (ej. T006 y T007 son ambas
  `[US1]` pero sobre controllers distintos, así que sí son paralelas en la práctica aunque no estén
  marcadas `[P]` por prolijidad del listado).

---

## Parallel Example: User Story 1

```bash
# Tests (después de Phase 1):
Task: "Feature test lote ML en tests/Feature/Integraciones/MercadoLibreTransformarEnVentaTest.php"
Task: "Feature test lote TN en tests/Feature/Integraciones/TiendanubeTransformarEnVentaTest.php"

# Servicios (después de que los tests fallen en rojo):
Task: "convertirTodasLasListas en app/Services/MercadoLibre/ConversorOrdenAVenta.php"
Task: "convertirTodasLasListas en app/Services/Tiendanube/ConversorOrdenAVenta.php"

# Vistas + JS:
Task: "Botón + JS en index.blade.php / mercadolibre-ventas.js"
Task: "Botón + JS en index.blade.php / tiendanube-ventas.js"
```

---

## Implementation Strategy

### MVP First (User Story 1 Only)

1. Phase 1: Setup (rutas).
2. Phase 3: User Story 1 — botón + conversión en lote + toast de resumen.
3. **Validar**: correr escenarios 1, 3 y 4 de `quickstart.md`.
4. Deploy/demo si se considera suficiente como primera entrega.

### Incremental Delivery

1. Setup → Phase 3 (US1, MVP) → validar → demo.
2. Phase 4 (US2) → agrega el modal de detalle de fallos → validar con escenario 2 de `quickstart.md`.
3. Phase 5 (US3) → tests de "forzar ya" y no-duplicación → validar con el escenario de
   no-duplicación de `quickstart.md`.
4. Phase 6 → polish, corrida completa de tests y quickstart.

---

## Notes

- No hay tareas de infraestructura nueva (sin colas, sin migraciones) — decisión explícita del
  usuario (ejecución síncrona, spec.md Assumptions).
- Los tests se escriben antes que la implementación de cada historia (principio IV de la
  constitución, dinero de por medio).
- `docs/documentacion_principal_crm.md` §3.2.bis/§3.2.quater ya se actualizó como parte del flujo
  de esta spec (antes de `/speckit-tasks`, principio I de la constitución) — no requiere tarea
  propia.
