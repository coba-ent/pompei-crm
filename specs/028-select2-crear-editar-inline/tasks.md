---

description: "Task list for feature 028: crear/editar catálogo inline en selects de Presupuestos"
---

# Tasks: Crear/editar catálogo inline en selects de Presupuestos

**Input**: Design documents from `/specs/028-select2-crear-editar-inline/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/endpoints.md, quickstart.md

**Tests**: No se solicitaron tests explícitos en el spec (Constitución IV: CRUD simple/UI no exige tests estrictos); se valida con `quickstart.md` (flujo manual) y, si algún endpoint reutilizado carece hoy de cobertura, se agrega como tarea de Polish.

**Organization**: Tareas agrupadas por historia de usuario (spec.md) para permitir implementación y prueba independiente de cada una.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Puede ejecutarse en paralelo (archivos distintos, sin dependencias)
- **[Story]**: US1 (Crear) / US2 (Editar)

## Path Conventions

Proyecto único (Laravel monolito). Rutas relativas a la raíz del repo.

---

## Phase 1: Setup

**Purpose**: No hay inicialización de proyecto nueva — se reutiliza la app Laravel/Vite ya existente. Sin tareas de Setup.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Scaffolding genérico compartido por Crear (US1) y Editar (US2): el helper de Select2 que renderiza la opción fija "Crear X" y el ícono de lápiz por fila, y el modal rápido nuevo de Cliente. Sin esto, ninguna de las dos historias tiene dónde enganchar su lógica.

**⚠️ CRITICAL**: No se puede empezar US1 ni US2 sin esta fase completa

- [X] T001 Crear helper genérico `iniciarSelect2Catalogo($el, opciones)` en `resources/js/presupuestos.js` que envuelve `initSelect2`: agrega `templateResult` (fila fija "Crear {label}" con ícono `+` primera siempre, y por cada resultado real un ícono de lápiz a la derecha vía `<a class="js-editar-item" data-id>`), intercepta `select2:select` para no seleccionar la fila "crear" (dispara `opciones.onCrear()` en su lugar y revierte el valor), y delega el click del lápiz (`stopPropagation` + `opciones.onEditar(id)`) sin alterar la selección vigente (research.md R1/R2).
- [X] T002 [P] En `resources/views/presupuestos/form.blade.php`, quitar los links `#btn-renombrar-categoria`/`#btn-eliminar-categoria` y `#btn-renombrar-vendedor`/`#btn-eliminar-vendedor` del lado del label de Categoría y Vendedor (FR-006); dejar los labels simples ("Categoría", "Vendedor").
- [X] T003 [P] Crear partial `resources/views/presupuestos/_modal_cliente_rapido.blade.php`, calcado de `_modal_vendedor.blade.php` (modal Bootstrap centrado, título dinámico "Crear Cliente"/"Renombrar Cliente", campo Nombre, `invalid-feedback`, botones Cancelar/Crear-Guardar) e incluirlo en `form.blade.php` junto a `@include('presupuestos._modal_categoria')` / `_modal_vendedor` (research.md R4).

**Checkpoint**: helper de Select2 y modal de Cliente listos — US1 y US2 pueden implementarse.

---

## Phase 3: User Story 1 - Crear un cliente/categoría/vendedor nuevo sin salir del presupuesto (Priority: P1) 🎯 MVP

**Goal**: Desde el dropdown de Cliente, Categoría de Venta o Vendedor, el usuario puede crear un ítem nuevo sin salir del formulario y queda seleccionado automáticamente.

**Independent Test**: Abrir "Nuevo Presupuesto", en el dropdown de Cliente (vacío, sin escribir nada) hacer click en "Crear Cliente", cargar un nombre, confirmar, y verificar que queda seleccionado sin recargar la página. Repetir para Categoría de Venta y Vendedor (ver `quickstart.md` Escenario 1).

### Implementation for User Story 1

- [X] T004 [US1] En `resources/js/presupuestos.js`, aplicar `iniciarSelect2Catalogo` a `#f-categoria` con `onCrear` que abre `#modal-nueva-categoria` en modo "crear" (reutilizando la lógica ya existente detrás de `abrirModalCategoria('crear', ...)`), reemplazando el disparador que hoy no existe para alta desde el dropdown.
- [X] T005 [US1] En `resources/js/presupuestos.js`, aplicar `iniciarSelect2Catalogo` a `#f-vendedor` con `onCrear` que abre `#modal-nuevo-vendedor` en modo "crear" (reutilizando `abrirModalVendedor('crear', ...)`).
- [X] T006 [US1] En `resources/js/presupuestos.js`, aplicar `iniciarSelect2Catalogo` a `#f-cliente` (además de su config `ajax` existente) con `onCrear` que abre el nuevo `#modal-cliente-rapido` (T003) en modo "crear".
- [X] T007 [US1] Implementar el submit del modal `#modal-cliente-rapido` en modo "crear": POST a `rutas.clientesStore` (agregar esta ruta a `window.PresupuestosConfig.rutas` en `resources/views/presupuestos/form.blade.php`, apuntando a `clientes.store`) con `{ nombre }`; en éxito, insertar `new Option(cliente.nombre, cliente.id, true, true)` en `#f-cliente`, refrescar Select2, cerrar el modal y mostrar toast de éxito (contracts/endpoints.md, research.md R5); en error 422, mostrar el mensaje de validación en `#cliente-rapido-error`.
- [X] T008 [US1] Verificar que el alta de Categoría de Venta y de Vendedor desde el dropdown (T004/T005) sigue insertando y seleccionando el nuevo ítem en el select correspondiente sin recargar, igual que hoy lo hace el flujo por link (reutilizar el código ya existente en el callback de éxito de `btn-crear-categoria`/`btn-crear-vendedor`, sin duplicarlo).
- [X] T009 [US1] Confirmar que la fila "Crear X" se sigue mostrando primera cuando el usuario escribe un texto de búsqueda sin resultados en los tres selects (Edge Case del spec), y que ese texto se precarga como sugerencia inicial del campo Nombre del modal correspondiente.

**Checkpoint**: User Story 1 funcional de punta a punta para Cliente, Categoría de Venta y Vendedor — validar con `quickstart.md` Escenario 1 y 3.

---

## Phase 4: User Story 2 - Editar un cliente/categoría/vendedor existente desde la lista, sin seleccionarlo primero (Priority: P2)

**Goal**: Desde el ícono de lápiz de cualquier fila del dropdown, el usuario puede renombrar ese ítem puntual sin alterar la selección vigente del formulario.

**Independent Test**: Con un ítem ya seleccionado en Categoría de Venta, abrir el dropdown, hacer click en el lápiz de OTRO ítem, renombrarlo, confirmar, y verificar que la selección del formulario no cambió pero la lista sí refleja el nuevo nombre (ver `quickstart.md` Escenario 2).

### Implementation for User Story 2

- [X] T010 [US2] En `resources/js/presupuestos.js`, conectar el `onEditar(id)` de `#f-categoria` (desde T001/T004) para abrir `#modal-nueva-categoria` en modo "renombrar" con los datos de la fila clickeada (no del valor seleccionado del formulario), reutilizando `abrirModalCategoria('renombrar', id, nombre)`.
- [X] T011 [US2] En `resources/js/presupuestos.js`, conectar el `onEditar(id)` de `#f-vendedor` para abrir `#modal-nuevo-vendedor` en modo "renombrar" con los datos de la fila clickeada, reutilizando `abrirModalVendedor('renombrar', id, nombre)`.
- [X] T012 [US2] En `resources/js/presupuestos.js`, conectar el `onEditar(id)` de `#f-cliente` para abrir `#modal-cliente-rapido` (T003) en modo "renombrar" con el nombre actual del cliente de esa fila, tomado directamente del objeto `data` del resultado de Select2 que `templateResult` ya recibe (`data.cliente.nombre` / `data.text`, ver R2 en research.md) — no hace falta ninguna request adicional, ese dato ya está en memoria mientras el dropdown está abierto.
- [X] T013 [US2] Implementar el submit del modal `#modal-cliente-rapido` en modo "renombrar": PATCH/PUT a `clientes.update` (agregar `clientesUpdateBase` a `window.PresupuestosConfig.rutas`) con `{ nombre }`; en éxito, actualizar el texto de la opción en `#f-cliente` (y si era la seleccionada, refrescar la selección visible) sin recargar, cerrar el modal y mostrar toast de éxito.
- [X] T014 [US2] Confirmar que renombrar Categoría de Venta o Vendedor desde el lápiz de una fila NO seleccionada no modifica el valor actualmente elegido en `#f-categoria`/`#f-vendedor` del formulario (Acceptance Scenario 1-3 de User Story 2), y que si la fila editada SÍ era la seleccionada, la selección visible se actualiza con el nombre nuevo.
- [X] T015 [US2] Confirmar el manejo de "cancelar" en los tres modales (Cliente/Categoría/Vendedor): no debe aplicarse ningún cambio ni alterar el dropdown (Acceptance Scenario 4 de User Story 2).

**Checkpoint**: User Stories 1 y 2 funcionan de punta a punta para los tres catálogos — validar con `quickstart.md` Escenario 2 y 3.

---

## Phase 5: Polish & Cross-Cutting Concerns

**Purpose**: Cierre de documentación y validación end-to-end.

- [X] T016 [P] Actualizar `docs/documentacion_principal_crm.md` §3.1 (Presupuestos) con una nota que documente el patrón real de creación/edición inline en los selects de catálogo (Cliente/Categoría de Venta/Vendedor: opción "Crear X" + lápiz por fila dentro del dropdown), reemplazando cualquier referencia desactualizada al mecanismo de links "Renombrar"/"Eliminar" al lado del label (Constitución I).
- [X] T017 Ejecutar manualmente los 3 escenarios de `specs/028-select2-crear-editar-inline/quickstart.md` en el navegador (alta, edición, y verificación de "sin recargas / toasts") y dejar registrado el resultado.
- [X] T018 Revisar visualmente el dropdown resultante contra las capturas `docs/capturas/saldos/WhatsApp Image 2026-07-30 at 12.16.07/12.16.30/12.16.49/12.17.17 PM.jpeg` (regla de oro de fidelidad estructural — posición de "Crear X", ícono "+", resaltado, lápiz por fila, estructura del modal).

---

## Dependencies & Execution Order

### Phase Dependencies

- **Foundational (Phase 2)**: sin dependencias previas — bloquea Phase 3 y Phase 4.
- **User Story 1 (Phase 3)**: depende de Phase 2. Sin dependencia de US2.
- **User Story 2 (Phase 4)**: depende de Phase 2. Reutiliza el mismo helper de Phase 2 que US1 pero es independientemente testeable (no requiere que US1 esté "terminada", sólo que el helper T001 exista).
- **Polish (Phase 5)**: depende de que US1 y US2 estén completas (T017/T018 validan ambas).

### Parallel Opportunities

- T002 y T003 (Phase 2) son paralelos entre sí y con T001 (archivos distintos).
- T004, T005, T006 (Phase 3) son paralelos entre sí (cada uno configura un select distinto en el mismo archivo JS, pero son ediciones independientes — coordinar si se implementan en simultáneo por distintas personas).
- T010, T011, T012 (Phase 4) son paralelos entre sí por el mismo motivo.
- T016 (Phase 5) es paralelo al resto de Polish.

---

## Implementation Strategy

### MVP First (User Story 1 Only)

1. Completar Phase 2 (Foundational).
2. Completar Phase 3 (User Story 1 — Crear).
3. Validar con `quickstart.md` Escenario 1.
4. Esto ya resuelve el problema más frecuente (alta de cliente/categoría/vendedor sin salir del presupuesto) y es demostrable solo.

### Incremental Delivery

1. Foundational → listo.
2. User Story 1 (Crear) → validar → esto es el MVP.
3. User Story 2 (Editar) → validar → cierra el segundo punto de fricción relevado.
4. Polish → documentación y verificación final contra capturas.
