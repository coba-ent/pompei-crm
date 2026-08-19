# Tasks: Buscador de productos del detalle con foco persistente

**Input**: Design documents from `/specs/071-buscador-productos-detalle/`
**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/buscador-catalogo-api.md, quickstart.md

**Tests**: Incluidos. El Principio IV de la constitución exige tests donde hay dinero/impacto fiscal:
el widget alimenta las líneas de comprobantes (precio, costo, IVA). Se testea la lógica pura del
widget con el runner de Node (patrón ya existente en `tests/js/fecha-ar.test.mjs`); la interacción con
el DOM se valida a mano con `quickstart.md`.

**Organization**: Tareas agrupadas por user story (US1–US3 de spec.md).

## Phase 1: Setup

- [X] T001 Verificar que el buscador actual funciona antes de tocar nada y **anotar la línea base de paridad**: en `http://127.0.0.1:8720/ventas/nueva` (la ruta es `ventas/nueva`, no `crear` — ver `routes/web.php:275`), buscar 5 términos representativos (nombre exacto, fragmento, código, dos palabras, término inexistente) y guardar los resultados y el orden en un archivo temporal de trabajo, para comparar después (SC-003). Repetir el paso para un producto elegido en cada una de las 3 pantallas anotando descripción/cantidad/precio/IVA de la línea resultante (SC-004).

## Phase 2: Foundational (bloqueante para todas las user stories)

**Objetivo**: Tener el widget reutilizable funcionando y montado, antes de conectarle la lógica de cada pantalla.

- [X] T002 Crear `resources/js/buscador-catalogo.js` con el esqueleto del módulo: IIFE que expone `window.BuscadorCatalogo = { montar }`, siguiendo el patrón de módulo global auto-contenido de `resources/js/fecha-ar.js`. `montar(elemento, opciones)` debe ser idempotente (montar dos veces sobre el mismo input devuelve la instancia existente, sin duplicar listeners ni paneles) y devolver `{ enfocar, limpiar, cerrar, destruir }` según `contracts/buscador-catalogo-api.md`.
- [X] T003 En `resources/js/buscador-catalogo.js`, implementar el estado interno y el ciclo de búsqueda: `termino`, `abierto`, `cargando`, `error`, `items`, `resaltado` (inicial `-1`), `secuencia`; debounce configurable con default 250 ms (FR-002); e incremento de `secuencia` por consulta con descarte de respuestas cuya secuencia no sea la vigente (FR-012). Ver la tabla de transiciones en `data-model.md`.
- [X] T004 En `resources/js/buscador-catalogo.js`, implementar el renderizado del panel con sus 4 estados (buscando / con resultados / sin coincidencias / error), insertando el texto de cada fila con `textContent` y **nunca** como HTML (FR-009, FR-010, FR-011, contracts §Seguridad).
- [X] T005 En `resources/js/buscador-catalogo.js`, implementar el manejo de foco y cierre: el panel no contiene elementos focusables por tabulación; elegir una opción ejecuta en orden `onElegir(item)` → cerrar panel → vaciar input → conservar foco (FR-003); clic fuera y blur cierran sin elegir y sin borrar el término (FR-008).
- [X] T006 En `resources/js/buscador-catalogo.js`, implementar el teclado: `↓`/`↑` mueven `resaltado` con tope en los extremos (sin dar la vuelta), `Enter` confirma **sólo si `resaltado >= 0`**, `Escape` cierra conservando término y foco (FR-007, research.md Decisión 5).
- [X] T007 En `resources/js/buscador-catalogo.js`, agregar los atributos ARIA: `role="combobox"`, `aria-expanded`, `aria-controls` y `aria-activedescendant` en el input; `role="listbox"` en el panel y `role="option"` en cada fila (FR-016).
- [X] T008 [P] Agregar en `public/css/contagram-custom.css` los estilos del panel de sugerencias (posicionamiento bajo el input, `z-index` que funcione dentro de una card, scroll vertical con tope de alto, estilo de fila resaltada, estilos de los textos de buscando/sin resultados/error), respetando la altura y tipografía de los controles compactos ya definidos en ese archivo (FR-015).
- [X] T009 [P] Crear `tests/js/buscador-catalogo.test.mjs` con el runner de Node, cubriendo la lógica pura: (a) el debounce agrupa varias pulsaciones en una sola consulta, (b) una respuesta de secuencia vieja que llega tarde no pisa a la vigente, (c) el índice resaltado se mueve y hace tope en ambos extremos, (d) `Enter` con `resaltado === -1` no dispara `onElegir`. Para esto la lógica de estado debe ser exportable/instanciable sin DOM (extraerla como función pura dentro del módulo si hace falta).

**Checkpoint**: el widget existe y está testeado, pero todavía ninguna pantalla lo usa.

---

## Phase 3: User Story 1 — Cargar varios productos seguidos sin interrupciones (Priority: P1) 🎯 MVP

**Goal**: Que en las 3 pantallas el buscador conserve el foco, cierre el panel y se vacíe al elegir.

**Independent Test**: Cargar 3 productos consecutivos en Venta sin tocar el mouse entre uno y otro, verificando que no queda ningún panel abierto.

### Implementación para User Story 1

- [X] T010 [US1] En `resources/views/ventas/form.blade.php` (línea ~107), reemplazar `<select id="f-producto" class="form-select" style="width:100%"></select>` por `<input type="text" id="f-producto" class="form-control" autocomplete="off" placeholder="Buscar producto...">`. Dejar la etiqueta del campo como está (la brecha "Crear" se documenta aparte, ver T024).
- [X] T011 [P] [US1] Idem en `resources/views/compras/form.blade.php` (línea ~95).
- [X] T012 [P] [US1] Idem en `resources/views/presupuestos/form.blade.php` (línea ~81).
- [X] T013 [US1] En `resources/js/ventas.js`, reemplazar el `initSelect2($('#f-producto'), {...})` (línea ~591) y el handler `$('#f-producto').on('select2:select', ...)` (línea ~667) por una única llamada a `window.BuscadorCatalogo.montar('#f-producto', { buscar, formatear, onElegir })`. `onElegir` debe hacer **exactamente** lo que hace hoy el handler: `items.unshift({...})` con los mismos campos + `renderItems()`. Ver `data-model.md` §Línea del detalle.
- [X] T014 [US1] En `resources/js/compras.js`, idem: reemplazar `initSelect2($('#f-producto'), ...)` (línea ~365) y el handler `select2:select` (línea ~420), conservando la lógica de `costo` y de IVA condicionado a comprobante tipo A.
- [X] T015 [US1] En `resources/js/presupuestos.js`, idem: reemplazar `initSelect2($('#f-producto'), ...)` (línea ~432) y el handler `select2:select` (línea ~488), conservando `precio` y `lista_precio_id`.
- [X] T016 [US1] Eliminar la función `reabrirBuscador()` y su llamada de `resources/js/ventas.js` (líneas ~109-120 y ~672), `resources/js/compras.js` (~50 y ~426) y `resources/js/presupuestos.js` (~96 y ~501): queda sin uso, porque el widget nuevo ya no necesita reabrir nada para conservar el foco. **Verificar antes que no la use ningún otro select de esos archivos.**

### Verificación para User Story 1

- [X] T016b [US1] Ejecutar el **Escenario 1 de `quickstart.md` en las 3 pantallas** (Venta, Compra, Presupuesto), que es el Independent Test de esta user story y el pedido literal del cliente: cargar 3 productos consecutivos sin tocar el mouse entre uno y otro, y confirmar en cada elección que (a) la línea se agregó, (b) el panel se cerró, (c) el buscador quedó vacío, (d) el foco sigue en el buscador, y (e) al terminar no quedó ningún panel abierto tapando el detalle (SC-001, SC-002). Confirmar también que el tiempo hasta ver resultados se siente igual que antes del cambio (SC-005). **Sin esta tarea el MVP quedaría sin ninguna validación propia** (gap C1 detectado en `/speckit-analyze`).

**Checkpoint**: el comportamiento pedido por el cliente ya funciona y está verificado en las 3 pantallas.

---

## Phase 4: User Story 2 — La búsqueda encuentra y carga exactamente lo mismo que antes (Priority: P1)

**Goal**: Cero regresión de búsqueda y de línea agregada respecto del buscador anterior.

**Independent Test**: Comparar contra la línea base tomada en T001: mismos resultados y mismo orden para los 5 términos, y misma línea agregada en las 3 pantallas.

### Implementación para User Story 2

- [X] T017 [US2] Verificar en `resources/js/ventas.js`, `compras.js` y `presupuestos.js` que el callback `buscar` de cada pantalla manda **exactamente** los mismos parámetros que mandaba Select2, según la tabla de `research.md` Decisión 4: Venta y Presupuesto → `q`, `incluir_servicios: 1`, `lista_precio_id` (de `#f-lista-precio`, o `null`); Compra → `q`, `incluir_servicios: 1` (sin `lista_precio_id`). No modificar `ProductoController::opciones`.
- [X] T018 [US2] Verificar que el callback `formatear` de las 3 pantallas produce el mismo texto que hoy: `'(' + p.id + ') ' + p.nombre + (p.codigo ? ' (' + p.codigo + ')' : '')` (FR-005).

### Verificación de no-regresión para User Story 2

- [X] T019 [US2] Ejecutar el Escenario 2 de `quickstart.md` contra la línea base de T001: los 5 términos deben devolver el mismo conjunto y orden (SC-003), y la línea agregada debe tener los mismos descripción/cantidad/precio/IVA en las 3 pantallas, incluido el caso de Compra con comprobante tipo A y con uno distinto de A (SC-004).
- [X] T020 [US2] Ejecutar el Escenario 5 de `quickstart.md`: confirmar que el selector de Cliente/Proveedor sigue siendo Select2 con su "Crear Cliente" y su lápiz, que el menú ▾ de la fila del detalle sigue abriendo Ver/Editar producto, y que guardar el comprobante persiste las líneas correctas (FR-013, FR-018, SC-006).

**Checkpoint**: US1 + US2 completas — el cambio es seguro de entregar.

---

## Phase 5: User Story 3 — Operar el buscador enteramente con el teclado (Priority: P2)

**Goal**: Que el flujo completo de carga se pueda hacer sin mouse.

**Independent Test**: Cargar un producto usando sólo teclado (tipear → `↓` → `Enter`) y confirmar que el foco sigue en el buscador.

### Verificación para User Story 3

- [X] T021 [US3] Ejecutar el Escenario 3 de `quickstart.md`: `↓`/`↑` con tope en los extremos, `Enter` sobre la opción resaltada, `Escape` conservando texto y foco, y `Enter` sin resaltado que no agrega nada (FR-007).
- [X] T022 [US3] Ejecutar el Escenario 4 de `quickstart.md`: los tres estados no-felices (buscando / sin coincidencias / error con Network offline) son distinguibles entre sí, el buscador queda utilizable tras el error y el detalle ya cargado no se pierde (FR-009, FR-010, FR-011, SC-007).

---

## Phase 6: Polish & Cross-Cutting Concerns

- [X] T023 Actualizar `CLAUDE.md` §5 ("Selects con buscador" / "Carga en lote"): documentar que el buscador de productos del detalle de Venta/Compra/Presupuesto es la **excepción documentada** a la regla de usar Select2, con su motivo (foco independiente del panel, imposible en Select2), y reemplazar la prescripción del `setTimeout(() => $el.select2('open'), 0)` — que queda obsoleta para esos 3 casos — dejándola vigente para cualquier otro select con carga en lote que siga usando Select2.
- [X] T024 [P] Registrar en `docs/documentacion_principal_crm.md` §5 (módulos/brechas pendientes) la inconsistencia detectada: la etiqueta "Seleccionar o Crear Producto/Servicio" de las 3 pantallas promete una creación rápida de producto desde el buscador que nunca se implementó (sí existe para Cliente). Anotar las dos salidas posibles (implementarla en una spec futura, o corregir la etiqueta) sin resolverla en esta feature.
- [X] T025 [P] Actualizar `docs/documentacion_principal_crm.md` en la sección del detalle de Venta/Compra/Presupuesto para describir el comportamiento nuevo del buscador (foco persistente, panel que se cierra al elegir).
- [X] T026 Correr `node --test tests/js/buscador-catalogo.test.mjs` y `npm run build`, y confirmar que no hay errores de consola en las 3 pantallas (DevTools abierto durante el Escenario 1 de `quickstart.md`).
- [X] T027 Revisar que no haya quedado código muerto: `grep -rn "select2:select" resources/js/{ventas,compras,presupuestos}.js` no debe devolver ninguna línea referida a `#f-producto`, y `grep -rn "reabrirBuscador" resources/js/` no debe devolver nada.

## Dependencies & Execution Order

- **Setup (T001)** → sin dependencias. **Importante hacerlo primero**: T019 compara contra esa línea base, y una vez reemplazado el widget ya no se puede tomar.
- **Foundational (T002-T009)** → depende de T001; bloquea todas las user stories. T008 y T009 son paralelizables entre sí y con T002-T007 (archivos distintos), pero T009 depende de que T003/T006 hayan definido la forma de la lógica pura.
- **US1 (T010-T016b)** → depende de Foundational completo. Es el MVP. T016b es su verificación propia y no debe saltearse aunque se entregue sólo el MVP.
- **US2 (T017-T020)** → depende de US1 (verifica lo que US1 implementó). T017/T018 son de implementación-verificación y conviene hacerlos junto con T013-T015.
- **US3 (T021-T022)** → depende de Foundational (T006, T004) y de US1 estar montado.
- **Polish (T023-T027)** → depende de todas las user stories completas.

## Parallel Example

Dentro de US1, los 3 cambios de Blade tocan archivos distintos y no dependen entre sí:

```
T010 [US1] resources/views/ventas/form.blade.php
T011 [P] [US1] resources/views/compras/form.blade.php
T012 [P] [US1] resources/views/presupuestos/form.blade.php
```

Los 3 cambios de JS (T013-T015) también son de archivos distintos, pero conviene hacer **primero
Venta completa (T010+T013)** y validarla a mano antes de replicar en Compra y Presupuesto: si el
contrato del widget necesita un ajuste, se descubre con un tercio del trabajo hecho, no con todo.

## Implementation Strategy

**MVP**: Foundational (T002-T009) + US1 (T010-T016b) — ya entrega exactamente lo que pidió el cliente, con su verificación incluida.

**No entregar sin US2** (T017-T020): son las verificaciones de no-regresión de búsqueda y de línea
agregada. Como el widget alimenta líneas de comprobantes fiscales, entregar US1 sin la comprobación de
US2 es el riesgo real de esta feature, no el foco.

**Orden sugerido dentro de US1**: Venta primero de punta a punta (Blade + JS + prueba manual), después
Compra y Presupuesto, que son el mismo patrón con su propia línea.
