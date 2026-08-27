# Tasks: Orden de cuentas de tesorería por drag & drop

**Feature**: 085-orden-cuentas-tesoreria
**Input**: [spec.md](./spec.md), [plan.md](./plan.md), [research.md](./research.md),
[data-model.md](./data-model.md), [contracts/reordenar-cuentas-api.md](./contracts/reordenar-cuentas-api.md),
[quickstart.md](./quickstart.md)

**Nota sobre tests**: se incluyen tareas de test por el principio IV de la constitución — la feature
escribe en lote sobre una tabla de configuración de tesorería, y hay que fijar por test que los
saldos no se mueven y que el rechazo es atómico.

**Nota sobre migraciones**: **no hay ninguna**. La columna `orden` ya existe
(`2026_07_25_060001_create_cuentas_tesoreria_table.php`). Cualquier tarea que proponga una
migración está fuera de alcance.

---

## Phase 1: Setup

- [ ] T001 Agregar `'vendor/jqueryui/js/jquery-ui.min.js'` al array `js` del pagelevel `tesoreria` en `config/dz.php` (~línea 147), antes de `'js/custom.js'`, con un comentario que explique que es la dependencia de `sortable()` para el reordenamiento de cuentas — mismo patrón que el pagelevel de "Arma tu Informe" (~línea 453)

- [ ] T002 [P] Agregar en `public/css/contagram-custom.css` los estilos del reordenamiento: `.cuenta-handle-col` (columna angosta), `button.js-mover-cuenta` (sin borde ni fondo, color apagado del template, `cursor: grab` y `:active { cursor: grabbing }`, foco visible para navegación por teclado) y `.cuenta-orden-placeholder` (fila fantasma con alto de fila y fondo tenue, para indicar dónde va a caer la fila arrastrada — cubre CHK002)

---

## Phase 2: Foundational — endpoint de persistencia

**Bloquea todas las historias**: sin el endpoint no hay dónde guardar el orden.

- [ ] T003 Crear `app/Http/Requests/ReordenarCuentasRequest.php` con `authorize(): true` (el permiso lo aplica el middleware de la ruta) y las reglas del contrato: `tipo` → `required|string|in:efectivo,banco,a_cobrar,a_pagar`; `ids` → `required|array|min:1`; `ids.*` → `required|integer|distinct|exists:cuentas_tesoreria,id`. Incluir `messages()` con textos **en español** (la memoria del proyecto registra el pedido explícito de que los errores no salgan en inglés)

- [ ] T004 Agregar `reordenarCuentas(ReordenarCuentasRequest $request): JsonResponse` a `app/Http/Controllers/TesoreriaController.php`, junto a `configCuentas()`: comparar el conjunto de ids recibido contra `CuentaTesoreria::porTipo($tipo)->pluck('id')` y, si difieren en cualquier sentido, devolver **409** con el mensaje del contrato sin escribir nada; si coinciden, dentro de `DB::transaction()` asignar `orden = $i + 1` a cada id en su posición; devolver `['ok' => true, 'mensaje' => 'Orden actualizado con éxito.', 'saldos' => $this->tesoreria->saldos()]`. Docblock explicando por qué la comparación de conjunto **es** el control de concurrencia (FR-008, research.md Decisión 3)

- [ ] T005 Registrar en `routes/web.php`, dentro del grupo `tesoreria` (~línea 262) y **antes** de `Route::get('cuentas/{cuenta}', ...)`: `Route::patch('cuentas/orden', [TesoreriaController::class, 'reordenarCuentas'])->middleware('permiso:tesoreria.editar')->name('cuentas.orden')`, con un comentario de por qué eleva el permiso a `editar` frente al `ver` del grupo (research.md Decisión 7)

- [ ] T006 Crear `tests/Feature/Tesoreria/ReordenarCuentasTest.php` cubriendo: (a) reordenamiento feliz que persiste `orden` 1..N en el tipo; (b) normalización de `orden` NULL heredado a consecutivo; (c) **409** con un id de otro tipo; (d) **409** con la lista incompleta (falta una cuenta del tipo); (e) **409** con un id sobrante de una cuenta borrada; (f) **422** con id repetido; (g) atomicidad — ante el rechazo ninguna fila cambió su `orden`; (h) **403** sin permiso `tesoreria.editar`; (i) invariancia — tras reordenar, `nombre`, `tipo`, `visible`, `es_sistema`, `saldo_inicial` y los saldos derivados quedan idénticos (SC-003, SC-004, FR-011). Los casos (c), (d) y (e) son los que cubren **FR-008** en sus tres formas

**Checkpoint**: `php artisan test --filter=ReordenarCuentasTest` en verde. El orden ya se puede cambiar por API, aunque todavía no haya UI.

---

## Phase 3: User Story 1 + User Story 2 (P1) — arrastrar y ver el resultado

**Goal**: reordenar arrastrando dentro del modal, que se guarde al soltar, y que las cards de fondo
reflejen el orden nuevo sin recargar.

**Por qué van juntas**: US2 no es una historia separable en la práctica — el refresco de las cards es
la misma línea de código que sigue al éxito del guardado de US1. Separarlas produciría un incremento
intermedio (guardar sin mostrar) que nadie querría entregar.

**Independent Test**: arrastrar la última cuenta de un bloque a la primera posición; ver el toast,
las cards actualizadas sin recarga, y el orden conservado tras F5.

- [ ] T007 [US1] Exponer la ruta al front: agregar `cuentasOrden: @json(route('tesoreria.cuentas.orden')),` al objeto `TesoreriaConfig.rutas` en `resources/views/tesoreria/saldos.blade.php` (bloque `@section('local-js')`)

- [ ] T008 [US1] En `resources/js/tesoreria.js`, función `renderGrupos()`: agregar como **primera** celda de cada `<tr>` un `<td class="cuenta-handle-col">` con `<button type="button" class="js-mover-cuenta" aria-label="Reordenar {nombre}"><i class="fas fa-grip-vertical"></i></button>`, sumar el `<th>` vacío correspondiente al `<thead>`, y marcar cada `<tbody>` con `data-tipo="{tipo}"` (FR-001)

- [ ] T009 [US1] En `resources/js/tesoreria.js`, tras renderizar los grupos: inicializar `sortable()` sobre cada `<tbody data-tipo>` que tenga 2 o más filas, con `handle: 'button.js-mover-cuenta'`, `items: '> tr'`, `axis: 'y'`, `containment: 'parent'`, `placeholder: 'cuenta-orden-placeholder'`, un `helper` que fije el ancho de las celdas al levantar la fila, `start` que capture el orden previo del bloque, y `update` que llame a `persistirOrden($tbody)`. Comentar explícitamente que **no se usa `connectWith`**, y que esa ausencia es lo que hace imposible arrastrar entre bloques (FR-002, FR-003, research.md Decisión 1)

- [ ] T010 [US1] En `resources/js/tesoreria.js`, implementar `persistirOrden($tbody)`: leer `data-id` de cada `<tr>` para armar `ids`; si el resultado es idéntico al orden previo capturado, **retornar sin enviar nada ni mostrar toast** (FR-005); si cambió, abortar el request en vuelo de ese tipo y enviar `PATCH` a `rutas.cuentasOrden` con `{tipo, ids}` (research.md Decisión 6, FR-015)

- [ ] T011 [US1] [US2] En `resources/js/tesoreria.js`, manejar el **éxito** de `persistirOrden`: toast verde con el `mensaje` de la respuesta (FR-004, FR-005) y repintar las cards de saldos sin recargar la página, reutilizando el `saldos` de la respuesta o `window.TesoreriaSaldos.recargar()` si hay una fecha de corte distinta de hoy (FR-010, research.md Decisión 4)

- [ ] T012 [US1] En `resources/js/tesoreria.js`, manejar los **fallos** de `persistirOrden` según el contrato: en **409**, toast de error con el mensaje del servidor y `cargarConfigCuentas()` para refrescar el listado; en 422/403/5xx/red caída, toast de error y **restaurar el orden previo en el DOM** (FR-009, SC-006); si `statusText === 'abort'`, no hacer nada (fue reemplazado por un arrastre posterior)

**Checkpoint**: pasos 2, 3, 5 y 6 de [quickstart.md](./quickstart.md) validados en navegador, incluido que el orden sobrevive a un F5 (SC-002). **Aquí ya está el MVP entregable.**

---

## Phase 4: User Story 3 (P2) — no se puede cruzar de bloque

**Goal**: garantizar que un arrastre nunca cambie el tipo de una cuenta.

**Independent Test**: arrastrar una cuenta de Efectivo sobre el bloque Banco y confirmar que vuelve a
su lugar, sin request ni cambio de tipo.

**Nota**: la defensa principal (ausencia de `connectWith`) ya quedó en T009 y la del servidor en
T004/T006. Esta fase es la **verificación** de que ambas capas efectivamente lo impiden.

- [ ] T013 [US3] Validar en navegador el paso 4 de [quickstart.md](./quickstart.md): arrastrar entre bloques no mueve la fila, no dispara request (pestaña Red vacía) y no cambia el `tipo` en base (FR-003, SC-004)

- [ ] T014 [P] [US3] Confirmar que el test (c) de `ReordenarCuentasTest` cubre la defensa del servidor con un caso explícito: un `ids` que mezcla una cuenta de otro tipo devuelve 409 y deja los `orden` de ambos tipos intactos

---

## Phase 5: User Story 4 (P3) — reordenar por teclado

**Goal**: permitir mover una cuenta una posición con el teclado, con el mismo guardado.

**Independent Test**: con el foco en el handle de una cuenta que no es la primera, `ArrowUp` la sube
y la guarda; en la primera, no hace nada.

- [ ] T015 [US4] En `resources/js/tesoreria.js`, agregar un handler `keydown` delegado sobre `button.js-mover-cuenta`: `ArrowUp`/`ArrowDown` mueven el `<tr>` una posición dentro de su `<tbody>` (sin salirse de los extremos), hacen `preventDefault()`, devuelven el foco al mismo botón tras mover el nodo, y llaman a la **misma** `persistirOrden($tbody)` de T010 para que no existan dos caminos de guardado (FR-013, research.md Decisión 5)

- [ ] T016 [US4] Validar en navegador el paso 8 de [quickstart.md](./quickstart.md): sube y guarda con toast; en la primera fila del bloque no pasa nada ni se dispara request

---

## Phase 6: Polish & validación final

- [ ] T017 [P] Recorrer los casos borde del paso 9 de [quickstart.md](./quickstart.md): bloque de una sola cuenta (sin request), cuenta oculta que participa del orden y aparece en su posición al volverla visible, y cuenta de sistema reordenable pese al badge. Confirmar también **FR-014**: los bloques entre sí no ofrecen arrastre — el orden A Cobrar / A Pagar / Cajas / Bancos y el de los encabezados del modal quedan fijos, sin handle a nivel de bloque

- [ ] T018 [P] Validar FR-012/SC-008 con el paso 3 de [quickstart.md](./quickstart.md): tras reordenar, el selector de cuenta de "Movimiento entre Cuentas" muestra el mismo orden que la card

- [ ] T019 Validar el conflicto por cambio en paralelo (paso 7 de [quickstart.md](./quickstart.md)) con dos pestañas: el 409 refresca el listado y **ningún** `orden` del bloque quedó modificado (FR-007, FR-008). Medir además, en un reordenamiento normal, que desde el drop hasta ver el orden nuevo en modal y cards pasan **menos de 2 s** (SC-005)

- [ ] T020 Verificar en base el paso 10 de [quickstart.md](./quickstart.md): `orden` consecutivo desde 1, sin huecos ni NULL para el tipo reordenado, y el resto de los campos de cada cuenta sin cambios (FR-006, FR-011)

- [ ] T021 Correr la suite completa (`php artisan test`) para confirmar que la ruta y el permiso nuevos no rompieron tests existentes de Tesorería

- [ ] T022 [P] Compilar assets (`npm run build`) y confirmar que `tesoreria.js` se construye sin errores

---

## Dependencies

```
Phase 1 (T001-T002)  ─┐
                      ├─► Phase 3 (T007-T012) ─► Phase 4 (T013-T014) ─► Phase 5 (T015-T016) ─► Phase 6
Phase 2 (T003-T006)  ─┘
```

- **T001 bloquea T009**: sin jQuery UI cargado, `sortable()` no existe.
- **T003 → T004 → T005 → T006**: cadena estricta (request → controlador → ruta → test).
- **T007 bloquea T010**: sin la ruta en `TesoreriaConfig.rutas` no hay a dónde enviar.
- **T008 bloquea T009**: `sortable()` necesita el handle y el `data-tipo` ya renderizados.
- **T010 bloquea T011, T012 y T015**: los tres cuelgan de la misma función de persistencia.
- **Phase 4 y 5 dependen de Phase 3**, pero son independientes **entre sí**.

## Parallel Opportunities

- **T002** (CSS) es independiente de toda la Phase 2 y puede hacerse en paralelo al backend.
- **T014, T017, T018, T022** están marcadas `[P]`: tocan archivos o pantallas distintas y no compiten.
- Phase 1 y Phase 2 pueden avanzar en paralelo (assets/CSS vs. backend), ya que no comparten archivos.

## Implementation Strategy

**MVP = Phase 1 + Phase 2 + Phase 3** (T001–T012). Con eso el usuario ya puede reordenar
arrastrando, se guarda solo, y las cards reflejan el cambio sin recargar — que es exactamente lo que
pidió. Phase 4 verifica la salvaguarda, Phase 5 suma accesibilidad y Phase 6 cierra los bordes.

**Orden sugerido de entrega**:

1. Backend + tests (Phase 2) → verificable con `php artisan test` sin tocar la UI.
2. Assets (Phase 1) + UI (Phase 3) → primera demo real al usuario.
3. Phases 4–6 → verificación, accesibilidad y cierre.
