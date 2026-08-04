# Tasks: Modal "Nueva Nota de Crédito/Débito" completo (Compras y Ventas)

**Input**: Design documents from `/specs/045-modal-nc-nd-completo/`
**Prerequisites**: plan.md, spec.md, data-model.md, contracts/, research.md, quickstart.md

**Tests**: Incluidos y OBLIGATORIOS para la lógica de stock/dinero (Principio IV de la
constitución: cálculo de tope de cantidad, signo de movimiento de stock, mes_imputacion
obligatorio) — no son opcionales en esta feature.

**Organization**: Tareas agrupadas por user story para permitir implementación y prueba
independiente, según spec.md.

## Phase 1: Setup

- [X] T001 Migración `database/migrations/2026_08_04_xxxxxx_add_mes_imputacion_to_notas_credito_debito.php`: agrega columna `mes_imputacion` (date, NOT NULL) a `notas_credito_debito`, con backfill de filas existentes = `fecha_emision` normalizada al día 1 del mes (ver data-model.md)
- [X] T002 Actualizar `app/Models/NotaCreditoDebito.php`: agregar `mes_imputacion` a `$fillable` y a `$casts` (`'mes_imputacion' => 'date'`)

**Checkpoint**: Migración corrida (`php artisan migrate`), modelo actualizado — base lista para todas las user stories.

## Phase 2: Foundational (bloqueante para todas las user stories)

- [X] T003 Agregar a `app/Http/Requests/StoreNotaCreditoDebitoRequest.php`: regla `mes_imputacion` required|date; normalizar a día 1 del mes antes de persistir (via `prepareForValidation()` o en el controller)
- [X] T004 Agregar a `app/Http/Requests/StoreNotaCreditoDebitoRequest.php` (o un método `after()` del FormRequest, dado que depende de datos persistidos): validación de tope de cantidad por producto — `items.*.cantidad` no puede superar lo pendiente de ajuste de ese producto en el comprobante original (cantidad facturada en `venta_items`/`compra_items` menos lo ya ajustado por notas previas no eliminadas del mismo comprobante para ese producto), ver data-model.md "Regla derivada"
- [X] T005 Método helper compartido (ej. `app/Services/Ventas/AjustesPendientesNotaCreditoDebito.php` o similar, reutilizado por Compras y Ventas) que calcule, dado un comprobante (Venta|Compra) y un producto, la cantidad pendiente de ajuste — usado tanto por T004 (validación backend) como por el endpoint de opciones que consume el JS (T008)
- [X] T006 Endpoint `GET /compras/{compra}/notas-credito-debito/items-disponibles` y `GET /ventas/{venta}/notas-credito-debito/items-disponibles` en `NotaCreditoDebitoController` (o controller dedicado): devuelve los ítems del comprobante original con su cantidad pendiente de ajuste (usa T005), para poblar el selector "Agregar Productos"
- [X] T007 Registrar las rutas de T006 en `routes/web.php`, respetando el orden de rutas con prefijo literal antes que `{param}` de un solo segmento dentro del mismo `prefix()` (gotcha documentado en el runbook de deploy)

**Checkpoint**: Backend listo para exponer datos de productos pendientes — las user stories de UI ya pueden consumir esto.

---

## Phase 3: User Story 1 - Registrar NC/ND sin afectar stock, con Mes de Imputación (Priority: P1) 🎯 MVP

**Goal**: El modal expone Documento (solo lectura), toggle de Stock en "No" por defecto y Mes de Imputación, sin romper el flujo actual.

**Independent Test**: Crear una NC sobre una Compra existente con "afecta stock" en No; verificar que se guarda con mes de imputación y que no hay movimiento de stock.

- [X] T008 [P] [US1] En `resources/views/compras/_modal_ncnd.blade.php`: cambiar el input deshabilitado "Documento que Ajusta" por un `<select disabled>` de una sola opción (el comprobante actual), agregar campo "¿Queres que afecte Stock?" (Sí/No, default No) y campo "Mes de Imputación" (input mes/año, ej. `type="month"`), precargado con el mes/año actual de la Fecha de Emisión
- [X] T009 [P] [US1] Aplicar el mismo cambio de estructura en `resources/views/ventas/_modal_ncnd.blade.php` (paridad con US3)
- [X] T010 [US1] En `resources/js/compras.js`: al abrir el modal, precargar Mes de Imputación con el mes/año de hoy; al cambiar Fecha de Emisión, sincronizar el default si el usuario no lo tocó manualmente; incluir `mes_imputacion` en el payload de guardado
- [X] T011 [US1] Aplicar el mismo cambio en `resources/js/ventas.js`
- [X] T012 [US1] En `app/Http/Controllers/NotaCreditoDebitoController.php` (`store()` y `storeCompra()`): persistir `mes_imputacion` en la creación de la nota (normalizado a día 1 del mes)
- [X] T013 [P] [US1] En `resources/views/compras/detalle.blade.php`: agregar columna "Mes de Imputación" a la tabla "Notas de Crédito y Débito"
- [X] T014 [P] [US1] Aplicar el mismo cambio en `resources/views/ventas/detalle.blade.php`
- [X] T015 [US1] Test Feature en `tests/Feature/NotaCreditoDebitoTest.php`: crear NC/ND con `afecta_stock=false` y `mes_imputacion` presente → la nota se persiste con el mes correcto (normalizado a día 1) y no se genera ningún movimiento de stock
- [X] T016 [US1] Test Feature en `tests/Feature/NotaCreditoDebitoTest.php`: crear NC/ND sin `mes_imputacion` → falla validación 422

**Checkpoint**: User Story 1 completa y verificable de forma independiente — MVP entregable.

---

## Phase 4: User Story 2 - Registrar NC/ND que SÍ afecta stock, con productos y depósito (Priority: P2)

**Goal**: Activar en el modal la lógica de `afecta_stock=true` que el backend ya soporta, con selector de productos limitado al comprobante original y validación de tope de cantidad.

**Independent Test**: Crear una ND sobre una Venta con "afecta stock" en Sí, tildar un producto con una cantidad, elegir depósito, guardar, y verificar que el stock bajó en la cantidad indicada en ese depósito.

- [X] T017 [US2] En `resources/views/compras/_modal_ncnd.blade.php`: al elegir "Sí" en "¿Queres que afecte Stock?", mostrar sección "Agregar Productos de la Compra" (tabla con checkbox + cantidad por producto, poblada vía AJAX desde el endpoint de T006) y un `<select>` Select2 de Depósito (obligatorio en este caso); si el endpoint de items-disponibles devuelve una lista vacía (comprobante sin productos, sólo conceptos/servicios), deshabilitar la opción "Sí" del toggle y mostrar un texto aclaratorio (edge case del spec)
- [X] T018 [US2] Aplicar el mismo cambio (incluyendo el deshabilitado cuando no hay productos) en `resources/views/ventas/_modal_ncnd.blade.php` ("Agregar Productos de la Venta")
- [X] T019 [US2] En `resources/js/compras.js`: lógica de mostrar/ocultar la sección de productos+depósito según el toggle; al tildar un producto, limitar el input de cantidad al máximo devuelto por el endpoint de items-disponibles (T006); al pasar de "Sí" a "No" antes de guardar, descartar la selección de productos/depósito hecha (edge case del spec); armar el payload `items[]`/`deposito_id` al guardar
- [X] T020 [US2] Aplicar el mismo cambio en `resources/js/ventas.js`
- [X] T021 [US2] Verificar en `app/Http/Controllers/NotaCreditoDebitoController.php` que el flujo existente de `afecta_stock=true` (creación de `nota_credito_debito_items` + `StockService::ajustar()`) sigue intacto — no requiere cambios de lógica, sólo queda alcanzable por primera vez desde el modal real
- [X] T022 [P] [US2] Test Feature en `tests/Feature/NotaCreditoDebitoTest.php`: crear ND sobre una Venta con `afecta_stock=true`, un producto y depósito válidos → el stock del producto en ese depósito baja en la cantidad indicada (signo ya implementado)
- [X] T023 [P] [US2] Test Feature: mismo caso para NC sobre Compra → el stock sube (signo ya implementado)
- [X] T024 [P] [US2] Test Feature: intentar cargar una cantidad mayor a la pendiente de ajuste de un producto → falla validación 422 con el máximo disponible en el mensaje
- [X] T025 [P] [US2] Test Feature: crear una segunda nota sobre el mismo comprobante y mismo producto, después de una primera nota que ya ajustó parte de la cantidad → el máximo disponible ofrecido/validado descuenta lo ya ajustado por la primera nota
- [X] T026 [P] [US2] Test Feature: intentar crear una nota con `afecta_stock=true` y sin `deposito_id` → falla validación 422
- [X] T027 [US2] Test Feature: una nota soft-deleted no debe seguir "consumiendo" cupo del producto en el cálculo de pendiente (edge case de data-model.md)

**Checkpoint**: User Story 2 completa y verificable de forma independiente, sobre la base de US1.

---

## Phase 5: User Story 3 - Consistencia visual entre Compras y Ventas (Priority: P3)

**Goal**: Confirmar y dejar documentada la paridad estructural exacta entre ambos modales.

**Independent Test**: Abrir el modal desde una Compra y desde una Venta y confirmar que la estructura de campos, orden y textos (salvo terminología propia del módulo) es idéntica.

- [X] T028 [US3] Revisión manual cruzada de `resources/views/compras/_modal_ncnd.blade.php` vs `resources/views/ventas/_modal_ncnd.blade.php`: mismo orden de campos (Tipo, Documento, ¿Afecta Stock?, [Productos+Depósito], Mes de Imputación, Fecha, Monto, Descripción), mismos labels salvo terminología Compra/Venta y Proveedor/Cliente
- [X] T029 [US3] Ejecutar el Escenario 3 de `quickstart.md` (paridad Compras/Ventas) y dejar constancia en el checklist de implementación

**Checkpoint**: Las 3 user stories completas — feature lista end-to-end.

---

## Phase 6: Polish & Cross-Cutting

- [X] T030 [P] Ejecutar `php artisan test --filter=NotaCreditoDebito` completo y confirmar que todos los tests (T015, T016, T022-T027) pasan en verde
- [X] T031 [P] Recorrer manualmente los 3 escenarios de `quickstart.md` en local antes de dar la feature por terminada
- [X] T032 Confirmar que `docs/documentacion_principal_crm.md` y `docs/modelo_datos.md` (ya actualizados durante `/speckit-plan`) siguen reflejando fielmente el comportamiento final implementado; ajustar si algo cambió durante la implementación

## Dependencies & Execution Order

- **Setup (Phase 1)** → bloquea todo lo demás (la columna `mes_imputacion` debe existir).
- **Foundational (Phase 2)** → bloquea User Story 2 (necesita el endpoint de items-disponibles y la validación de tope); User Story 1 sólo depende de Setup, no de Phase 2 completa (T003 sí aplica a US1 por el `mes_imputacion` required, pero T004-T007 son específicos de "afecta stock" y sólo bloquean US2).
- **User Story 1 (Phase 3)**: depende de Setup + T003. Es el MVP.
- **User Story 2 (Phase 4)**: depende de Setup + Foundational completo (T003-T007) + la estructura de modal creada en US1 (T008/T009), ya que agrega una sección condicional al mismo modal.
- **User Story 3 (Phase 5)**: depende de que US1 y US2 estén implementadas en ambos módulos (es una revisión de paridad, no código nuevo).
- **Polish (Phase 6)**: depende de todas las anteriores.

## Parallel Execution Examples

Dentro de Phase 3 (US1), en paralelo (archivos distintos, sin dependencia entre sí):
```
T008 (compras/_modal_ncnd.blade.php) + T009 (ventas/_modal_ncnd.blade.php)
T013 (compras/detalle.blade.php) + T014 (ventas/detalle.blade.php)
```

Dentro de Phase 4 (US2), los tests son independientes entre sí una vez que T017-T021 están hechos:
```
T022 + T023 + T024 + T025 + T026 (todos en el mismo archivo de test pero casos independientes — marcarlos [P] asume que se agregan como métodos de test separados, no como el mismo método)
```

## Implementation Strategy

**MVP first**: Phase 1 + Phase 2 (parcial, sólo T003) + Phase 3 (User Story 1) entregan valor
solo: el modal ya coincide más con Contagram (Documento visible como selector, Mes de Imputación
funcionando) sin tocar todavía la lógica de stock, que es la parte de mayor riesgo/testing.

**Incremental delivery**: User Story 2 se agrega después, activando por primera vez la
funcionalidad de stock que hoy está "muerta" en el backend — es la pieza de mayor valor pero
también la que exige más tests por el Principio IV de la constitución. User Story 3 cierra con una
verificación de consistencia, sin código nuevo.
