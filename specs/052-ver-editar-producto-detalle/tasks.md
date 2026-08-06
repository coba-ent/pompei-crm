# Tasks: Ver/Editar producto desde el detalle de Venta, Presupuesto y Compra

**Input**: Design documents from `specs/052-ver-editar-producto-detalle/`
**Prerequisites**: plan.md, research.md, data-model.md, quickstart.md

**Tests**: no solicitados explícitamente por el usuario más allá de "no romper el guardado
existente de Productos" (checklist ux.md CHK011) — se incluye una tarea de test Feature mínima
para esa regresión y para el refresco de fila (US2), no una suite exhaustiva.

**Organization**: agrupadas por historia de usuario (US1 = Ver, US2 = Editar) para permitir
entrega incremental. Ambas historias comparten la Fase 2 (extracción del módulo compartido), que
es prerequisito bloqueante real (no se puede "Ver" ni "Editar" desde fuera de Productos sin ella).

## Phase 1: Setup

- [x] T001 Confirmar en `resources/views/productos/_modal_ver.blade.php` y `resources/views/productos/_modal_form.blade.php` que no tienen IDs de elemento colisionantes con IDs ya usados en `resources/views/ventas/form.blade.php`, `resources/views/presupuestos/form.blade.php`, `resources/views/compras/form.blade.php` (los partials se van a incluir tal cual en esas 3 vistas); documentar cualquier colisión encontrada como nota en este archivo antes de continuar. **Sin colisiones de ID.** Dependencias Blade duras detectadas y resueltas: `_modal_form.blade.php` requiere `$proveedores` y `$depositos` en scope (`$ultimoCodigo` no, va envuelto en `empty()`) — se agregaron a `VentaController@create/edit`, `PresupuestoController@create/edit` (`$depositos` no existía ahí, se agregó) y `CompraController@create/edit`.
- [x] T002 Vite: se usa `@vite([...])` directo por vista (no pagelevels de `config/dz.php`, que sólo listan libs vendor) — se agregó `resources/js/producto-modales.js` al array `input` de `vite.config.js` y a los `@vite([...])` de `ventas/form.blade.php`, `presupuestos/form.blade.php`, `compras/form.blade.php`. `window.ProductosConfig` (rutas `show`/`store`/`listas`/`tipos`, `tiposProducto`, `proveedores`, `listasPrecio`) se agregó inline en esas 3 vistas (Compras usa `listasPrecioProductos` del controller, mapeado igual).

## Phase 2: Foundational (bloqueante para US1 y US2)

**Purpose**: extraer la lógica de los modales Ver/Editar de `productos.js` a un módulo compartido reutilizable, sin romper el comportamiento actual de la página de Productos.

- [x] T003 Crear `resources/js/producto-modales.js`: IIFE que se inicializa en `$(document).ready` si existe `#modal-producto` en el DOM (sin depender de `#tabla-productos`); movidas las funciones y handlers de `.js-producto-ver` y `.js-producto-editar` de `productos.js`, junto con `resetForm`, `refreshSelect2`/`initSelect2`, `agregarVariante`, `renderListasPrecio`, `renderTiposProducto`, `mostrarPreviewImagen`, `toggleStockSection`, `abrirModal`/`cerrarModal`, y los sub-modales de Tipo de Producto / Lista de Precios.
- [x] T004 `window.ProductoModales = { abrirVer, abrirEditar }` expuesto al final del IIFE; delegación `.js-producto-ver`/`.js-producto-editar` en `document` se mantiene para compatibilidad con Productos. El botón "Ver → Editar" ahora llama `abrirEditar(id)` directamente (antes simulaba un click sobre `$tabla`, que no existe fuera de Productos).
- [x] T005 Submit del formulario: reemplazado `tabla.ajax.reload(...)` por `document.dispatchEvent(new CustomEvent('producto:actualizado', { detail: { producto: resp.producto } }))`.
- [x] T006 `productos.js`: eliminado el bloque movido (incluyendo un segundo fragmento huérfano de `mostrarErrores`/submit que había quedado duplicado tras el primer corte — detectado y limpiado); agregado `document.addEventListener('producto:actualizado', function () { tabla.ajax.reload(null, false); refrescarStats(); })`.
- [x] T007 Verificado indirectamente: `php artisan test tests/Feature/ProductoAltaTest.php tests/Feature/ProductoBajaTest.php tests/Feature/ProductoListadoTest.php tests/Feature/ProductoOpcionesTest.php` → 18/18 verdes (cubren alta/edición/baja/listado, que ejercitan el mismo endpoint que consume el modal). No se hizo verificación manual en navegador (fuera del alcance de esta sesión).
- [ ] T008 No se creó un test Feature dedicado nuevo — en su lugar se corrió la suite existente de Productos (T007) como regresión, que cubre el mismo contrato JSON. Pendiente si se quiere cobertura explícita del contrato consumido por `producto-modales.js`.

**Checkpoint**: con esto completo, Productos sigue funcionando igual, y `producto-modales.js` está listo para ser incluido en otras páginas.

---

## Phase 3: User Story 1 - Ver el producto de una fila del detalle (Priority: P1) 🎯 MVP

**Goal**: desde Ventas, Presupuestos y Compras, poder ver los datos completos de un producto de una fila del detalle sin salir del formulario.

**Independent Test**: agregar un producto al detalle de una Venta nueva, abrir el desplegable de la fila, click en "Ver", confirmar que se ve la misma info que "Ver" en Productos, y que al cerrar el formulario de Venta sigue intacto.

- [x] T009 [P] [US1] Incluidos `@include('productos._modal_form')`, `_modal_ver`, `_modal_listas`, `_modal_tipos` en las 3 vistas (junto con T015, ya que el desplegable siempre ofrece ambas opciones).
- [x] T010 [US1] `ventas.js` `renderItems()`: celda de descripción ahora es un dropdown Bootstrap (caret + "Ver"/"Editar") cuando `item.producto_id` existe; texto plano si no.
- [x] T011 [P] [US1] Replicado en `presupuestos.js`.
- [x] T012 [P] [US1] Replicado en `compras.js`.
- [x] T013 [US1] Ya cubierto: `abrirVer`/`abrirEditar` en `producto-modales.js` usan `.fail(function () { toast('error', 'No se pudo cargar el producto.'); })`, sin abrir el modal.
- [ ] T014 [US1] No verificado en navegador real (sin entorno gráfico en esta sesión) — verificado por lectura de código + `view()->render()` vía tinker (las 3 vistas renderizan sin error) + build de Vite exitoso. Pendiente de prueba manual del usuario.

**Checkpoint**: US1 entregable de forma independiente — "Ver" funcionando en Ventas, Presupuestos y Compras.

---

## Phase 4: User Story 2 - Editar el producto de una fila y ver el cambio reflejado (Priority: P2)

**Goal**: desde el mismo desplegable, poder editar el producto y que la fila del detalle se actualice sola (nombre/precio) sin pisar un precio tipeado a mano.

**Independent Test**: agregar un producto al detalle, editar su precio desde "Editar" de la fila, guardar, confirmar que la fila se actualiza sin recargar la página; repetir con un precio de fila tipeado a mano y confirmar que no se pisa.

- [x] T015 [P] [US2] Ver T009 (incluido junto).
- [x] T016 [US2] Opción "Editar" agregada al mismo dropdown (T010), invoca `window.ProductoModales.abrirEditar(item.producto_id)`.
- [x] T017 [P] [US2] Replicado en `presupuestos.js`.
- [x] T018 [P] [US2] Replicado en `compras.js`.
- [x] T019 [US2] `ventas.js`: `_precioCatalogoOriginal` seteado al agregar producto (`precio_venta`) y actualizado en la recotización por Lista de Precios.
- [x] T020 [P] [US2] Replicado en `presupuestos.js`.
- [x] T021 [P] [US2] Replicado en `compras.js` — usa `producto.costo` (no `precio_venta`), consistente con que Compras cotiza por costo.
- [x] T022 [US2] Listener `producto:actualizado` agregado en `ventas.js`: actualiza `descripcion` siempre, `precio_unitario` sólo si coincide con `_precioCatalogoOriginal`, y llama `renderItems()`.
- [x] T023 [P] [US2] Replicado en `presupuestos.js`.
- [x] T024 [P] [US2] Replicado en `compras.js` (comparando contra `costo` en vez de `precio_venta`).
- [ ] T025 [US2] No se creó un test Feature dedicado nuevo — mismo criterio que T008 (se corrió la suite existente de Productos como regresión del contrato JSON). La lógica de refresco de fila es JS puro de cliente, sin cobertura automatizada.
- [ ] T026 [US2] No verificado en navegador real — ver nota de T014. `php artisan test` sobre las suites de Ventas/Presupuestos/Compras relevantes no mostró regresiones nuevas atribuibles a este cambio (los fallos preexistentes se confirmaron iguales en `main` sin estos cambios, vía `git stash`).

**Checkpoint**: US2 entregable — "Editar" funcionando con refresco correcto en las 3 pantallas.

---

## Phase 5: Polish & Cross-Cutting Concerns

- [ ] T027 [P] No verificado visualmente en navegador (mismo motivo que T014/T026); el markup de las 3 páginas es idéntico byte a byte (mismo snippet de dropdown), así que la consistencia estructural (FR-010) está garantizada a nivel de código, pendiente de confirmación visual.
- [x] T028 [P] Ya hecho antes de `tasks` (durante `analyze`): `docs/documentacion_principal_crm.md` líneas ~398 y ~1071 anotadas con referencia a spec 052.
- [x] T029 Corrido `php artisan test` sobre Productos, Ventas, Presupuestos y Compras: sin regresiones nuevas. Fallos preexistentes (`CompraEdicionBajaTest`, `PresupuestoAltaTest`, `VentaStockTest`, `ImportadorTest`, `ResolvedorSkuTest`) confirmados idénticos en `main` sin este cambio (vía `git stash`) — no relacionados con esta feature.

## Dependencies & Execution Order

- **Phase 1 (Setup)** → **Phase 2 (Foundational)**: bloqueante, sin excepción — nada de US1/US2 puede empezar sin `producto-modales.js` extraído y sin regresión en Productos verificada (T007).
- **Phase 3 (US1)** depende de Phase 2. Es el MVP: entregable solo, sin Phase 4.
- **Phase 4 (US2)** depende de Phase 2 y reutiliza el desplegable creado en Phase 3 (T010-T012) — en la práctica conviene implementarla junto con US1 por pantalla (T010+T016 en el mismo archivo), pero son independientemente verificables.
- **Phase 5 (Polish)** depende de que Phase 3 y Phase 4 estén completas en las 3 pantallas.

## Parallel Example

Dentro de la Phase 3, T010/T011/T012 tocan archivos distintos (`ventas.js`, `presupuestos.js`, `compras.js`) y pueden ejecutarse en paralelo una vez que T009 (includes de blade) está hecho. Igual patrón en Phase 4 para T016-T018, T019-T021 y T022-T024.

## Implementation Strategy

1. Completar Phase 1 + Phase 2 (extracción del módulo compartido) — es el riesgo técnico principal; validar contra Productos antes de tocar las otras 3 pantallas.
2. Entregar Phase 3 (US1 - "Ver") en las 3 pantallas como primer incremento demostrable — menor riesgo, sin escritura.
3. Entregar Phase 4 (US2 - "Editar" + refresco de fila) en las 3 pantallas.
4. Cerrar con Phase 5.
