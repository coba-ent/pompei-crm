# Tasks: Selector de Depósito en Ventas y Compras

**Input**: Design documents from `/specs/049-deposito-ventas-compras/`
**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/endpoints.md, quickstart.md

**Tests**: no se pidieron explícitamente TDD en el spec, pero el proyecto ya tiene cobertura Feature
para stock/depósitos (`tests/Feature/DepositoPorDefectoTest.php`, `VentaStockTest.php`, etc.) — se
incluyen tasks de test por paridad con ese patrón existente, después de la implementación de cada
historia (no TDD estricto).

**Organización**: por user story, para permitir implementar y probar US1 de forma independiente antes
de US2.

## Phase 1: Setup

- [X] T001 Crear migración `add_deposito_id_to_ventas_table` (columna `deposito_id` nullable, FK → `depositos.id`, `restrictOnDelete()`) en `database/migrations/`
- [X] T002 [P] Crear migración `add_deposito_id_to_compras_table` (misma forma que T001) en `database/migrations/`
- [X] T003 [P] Crear migración `add_deposito_defaults_to_configuracion_ventas_table` (columnas `deposito_id` y `deposito_compra_id`, ambas nullable, FK → `depositos.id`, `nullOnDelete()`) en `database/migrations/`
- [X] T004 Correr `php artisan migrate` y verificar en `contagram` (MySQL local) que las tres columnas quedaron creadas

## Phase 2: Foundational (bloqueante para ambas historias)

**Objetivo**: dejar los modelos y el catálogo de depósitos disponibles antes de tocar controllers/servicios de negocio.

- [X] T005 [P] Agregar `deposito_id` a `$fillable` y relación `deposito(): BelongsTo` en `app/Models/Venta.php`
- [X] T006 [P] Agregar `deposito_id` a `$fillable` y relación `deposito(): BelongsTo` en `app/Models/Compra.php`
- [X] T007 [P] Agregar `deposito_id`, `deposito_compra_id` a `$fillable` y relaciones `deposito()`/`depositoCompra(): BelongsTo` en `app/Models/ConfiguracionVentas.php`

**Checkpoint**: modelos listos — a partir de acá las historias son independientes entre sí.

---

## Phase 3: User Story 1 - Elegir el depósito al cargar una Venta o Compra (Priority: P1) 🎯 MVP

**Goal**: el formulario "Nueva Venta"/"Nueva Compra" permite elegir el Depósito, y ese Depósito es el que efectivamente usa el movimiento de stock (alta, edición, baja).

**Independent Test**: con 2+ depósitos activos, crear una Venta y una Compra eligiendo un depósito distinto del "por defecto" (menor id) y verificar en `movimientos_stock`/`stocks` que el impacto fue sobre el depósito elegido (quickstart.md, Escenarios 1-4).

### Implementación para US1

- [X] T008 [US1] Agregar regla `'deposito_id' => 'required|integer|exists:depositos,id'` en `app/Http/Requests/StoreVentaRequest.php` y `app/Http/Requests/UpdateVentaRequest.php`
- [X] T009 [P] [US1] Agregar la misma regla en `app/Http/Requests/StoreCompraRequest.php` y `app/Http/Requests/UpdateCompraRequest.php`
- [X] T010 [US1] En `app/Http/Controllers/VentaController.php`: pasar `depositos` (`Deposito::activos()->orderBy('nombre')->get()`) a las vistas de `create()` y `edit()`; en `store()` y `update()` incluir `deposito_id` en el array persistido (`Venta::create([...])` / `$venta->update([...])`)
- [X] T011 [P] [US1] Mismos cambios que T010 pero en `app/Http/Controllers/CompraController.php` (`create()`, `edit()`, `store()`, `update()`)
- [X] T012 [US1] En `app/Services/Ingresos/StockDeVenta.php`, método `resolverDeposito()`: para el caso manual (rama `else` / fallback), usar `$venta->deposito ?? $this->depositoPorDefecto()` en vez de `$this->depositoPorDefecto()` directo
- [X] T013 [P] [US1] En `app/Services/Egresos/StockDeCompra.php`: reemplazar las tres llamadas a `$this->depositoPorDefecto()` (en `aplicarAlta`, `reintegrarPorEliminacion`, `reaplicarPorEdicion`) por `$compra->deposito ?? $this->depositoPorDefecto()`
- [X] T014 [US1] Agregar campo Depósito (select, Select2 vía `resources/js/ventas.js`, `width:'100%'`, sin `dropdownParent` por no ser modal) en `resources/views/ventas/form.blade.php`, en el bloque de datos generales de la operación
- [X] T015 [P] [US1] Mismo agregado en `resources/views/compras/form.blade.php` + `resources/js/compras.js`
- [X] T016 [US1] Validar en el frontend (JS de ventas/compras) que si `depositos` viene vacío, se bloquea el submit y se muestra un mensaje (toast) remitiendo a Configuración & Ajustes → Depósitos (FR-001b)

### Tests para US1

- [X] T017 [P] [US1] Test Feature `tests/Feature/Ingresos/VentaDepositoTest.php`: alta de Venta con Depósito B descuenta stock de B (no del por defecto); edición cambiando de A a B reintegra A y descuenta B; baja reintegra sobre el depósito persistido, no sobre el por defecto vigente al eliminar
- [X] T018 [P] [US1] Test Feature `tests/Feature/Egresos/CompraDepositoTest.php`: mismos tres casos que T017 pero para Compra (suma en vez de resta)
- [X] T019 [P] [US1] Test Feature que cubra FR-001a (guardar con un `deposito_id` que existe pero está inactivo → 422) y FR-001b (sin depósitos activos, `create()` no ofrece opción válida) en `tests/Feature/Ingresos/VentaDepositoTest.php` / `tests/Feature/Egresos/CompraDepositoTest.php`

**Checkpoint**: US1 completo y probable de forma aislada — ya resuelve el problema central (filtro por Depósito de Ventas empieza a "cuadrar").

---

## Phase 4: User Story 2 - Configurar el depósito por defecto de Ventas y de Compras (Priority: P2)

**Goal**: Configuración & Ajustes → tab "Ventas" permite fijar un Depósito por defecto para "Nueva Venta" (sección Ventas) y otro para "Nueva Compra" (sección Compras existente), con fallback a `Deposito::porDefecto()`.

**Independent Test**: configurar un Depósito por defecto distinto en cada sección y verificar que "Nueva Venta"/"Nueva Compra" abren con ese valor preseleccionado; sin configurar nada, cae al fallback (quickstart.md, Escenarios 5-6).

### Implementación para US2

- [X] T020 [US2] Agregar `'deposito_id' => 'nullable|integer|exists:depositos,id'` y `'deposito_compra_id' => 'nullable|integer|exists:depositos,id'` a la validación en `app/Http/Controllers/Configuracion/ConfiguracionVentasController.php`
- [X] T021 [US2] En `app/Http/Controllers/VentaController.php@create()`, extender el bloque `$defaults` con `depositoId` resuelto desde `$configuracionVentas->deposito_id`, validado contra `Deposito::activos()`, con fallback a `Deposito::porDefecto()->id` si no hay match (data-model.md § Precarga de defaults)
- [X] T022 [P] [US2] Mismo agregado en `app/Http/Controllers/CompraController.php@create()`, usando `$configuracionVentas->deposito_compra_id`
- [X] T023 [US2] Agregar campo "Depósito por defecto" (Select2, `dropdownParent` no aplica por ser página completa) en la sección "Ventas" de `resources/views/configuracion/ventas/_tab.blade.php`
- [X] T024 [US2] Agregar campo "Depósito por defecto" en la sección "Compras" de la misma vista, junto a Categoría de Compra/Tipo de Comprobante/Vto. de Pago
- [X] T025 [US2] Actualizar el JS que envía el form `#form-configuracion-ventas` (AJAX) para incluir `deposito_id` y `deposito_compra_id` en el payload
- [X] T026 [US2] Aplicar precarga del `defaults.depositoId` en el JS de `ventas.js`/`compras.js` (setear el Select2 al abrir "Nueva Venta"/"Nueva Compra", igual patrón que `categoriaId`/`vendedorId` ya usan)

### Tests para US2

- [X] T027 [P] [US2] Extender/crear `tests/Feature/Configuracion/ConfiguracionVentasDepositoTest.php`: guardar `deposito_id`/`deposito_compra_id`, verificar persistencia y que `create()` de Venta/Compra los precarga
- [X] T028 [P] [US2] Test del fallback: sin configuración, `create()` de Venta/Compra usa `Deposito::porDefecto()`; con el depósito configurado inactivado, vuelve al mismo fallback (US2 AC3/AC4)

**Checkpoint**: ambas historias completas e independientemente verificables.

---

## Phase 5: User Story 3 - Cargar el N° de comprobante real del Proveedor en una Compra (Priority: P1)

**Goal**: el campo N° de comprobante de "Nueva Compra"/"Editar Compra" pasa de autogenerado-oculto a
editable-obligatorio, precargado con el correlativo interno como sugerencia.

**Independent Test**: crear una Compra sin tocar el campo (persiste el sugerido); crear otra editando
el valor a un número real (persiste exactamente ese valor); intentar guardar con el campo vacío (se
bloquea). No depende de US1/US2 — puede implementarse y probarse en cualquier orden respecto de ellas.

### Implementación para US3

- [X] T033 [US3] Agregar `'nro_comprobante' => 'required|string|max:20'` a la validación en `app/Http/Requests/StoreCompraRequest.php` y `app/Http/Requests/UpdateCompraRequest.php`
- [X] T034 [US3] En `app/Http/Controllers/CompraController.php@create()`: agregar `nroComprobanteSugerido` al array `$defaults`, calculado con `Compra::siguienteNroComprobante($configuracionVentas->tipo_comprobante_compra ?? 'B')` (data-model.md § Precarga del N° de comprobante sugerido)
- [X] T035 [US3] En `app/Http/Controllers/CompraController.php@store()`: reemplazar `'nro_comprobante' => Compra::siguienteNroComprobante($datos['tipo_comprobante'] ?? '')` por `'nro_comprobante' => $datos['nro_comprobante']` (el valor validado del request)
- [X] T036 [P] [US3] En `resources/views/compras/form.blade.php`: agregar input de texto editable para N° de comprobante (reemplaza cualquier visualización no editable existente), precargado por JS con `defaults.nroComprobanteSugerido` en alta, o con `compra.nro_comprobante` en edición
- [X] T037 [P] [US3] Actualizar `resources/js/compras.js` para incluir `nro_comprobante` en el payload AJAX de `store`/`update` y aplicar la precarga (T036)

### Tests para US3

- [X] T038 [P] [US3] Test Feature `tests/Feature/Egresos/CompraNroComprobanteTest.php`: alta sin tocar el campo persiste el sugerido; alta editando el campo persiste el valor real cargado; alta con el campo vacío devuelve 422; edición muestra y permite cambiar el valor ya persistido

**Checkpoint**: las tres historias completas e independientemente verificables entre sí.

---

## Phase 6: Polish & Cross-Cutting

- [x] T029 Actualizar `docs/documentacion_principal_crm.md` (§3.2 Ventas, §4.1 Compras, §5 Configuración & Ajustes) y `docs/modelo_datos.md` (§`ventas`, §`compras`, §17 `configuracion_ventas`): documentar el nuevo campo Depósito, marcando explícitamente que es una divergencia deliberada sin confirmación contra capturas reales de Contagram — **hecho durante planning (06/08/2026), antes de generar tasks, según la regla de retroalimentación docs↔specs de CLAUDE.md**
- [x] T029b Actualizar `docs/documentacion_principal_crm.md` §4.1 (Compras) y `docs/modelo_datos.md` §`compras`: documentar que `nro_comprobante` pasó de autogenerado a editable-obligatorio (US3, spec 049), con el correlativo interno como valor sugerido de partida — **hecho durante planning (06/08/2026), antes de implementar**
- [X] T030 [P] Test de regresión (analyze finding E1): una Venta manual con `deposito_id` distinto del `ml_configuracion.deposito_id`/`tn_configuracion.deposito_id` NO debe marcar el vínculo ML/Tiendanube como "con cambios pendientes de sincronizar" — agregar caso en `tests/Feature/Integraciones/MovimientoStockObserverTest.php` (o `TiendanubeMovimientoStockObserverTest.php`)
- [X] T031 [P] Correr `php artisan test --filter=Deposito` completo (todas las suites nuevas + `DepositoPorDefectoTest.php` existente) y confirmar que nada regresó
- [X] T031b [P] Correr `php artisan test --filter=CompraNroComprobante` y confirmar que nada regresó
- [ ] T032 Probar manualmente en navegador los 10 escenarios de `quickstart.md` (crear/editar/eliminar Venta y Compra con distintos depósitos, configurar defaults, depósito inactivado, N° de comprobante sugerido/editado/vacío)

## Dependencies & Execution Order

- **Setup (Phase 1)** → **Foundational (Phase 2)**: bloquean todo lo demás (columnas y relaciones deben existir antes de tocar controllers/servicios/vistas)
- **User Story 1 (Phase 3)**: depende sólo de Foundational. Es el MVP — entrega valor completo por sí sola.
- **User Story 2 (Phase 4)**: depende de Foundational y, funcionalmente, de que exista el selector de US1 (si no hay campo Depósito en el form, precargarlo no tiene efecto visible) — implementar después de US1, aunque técnicamente T020/T023/T024/T025 (backend de configuración) podrían adelantarse en paralelo.
- **User Story 3 (Phase 5)**: depende sólo de Foundational (ni siquiera de Depósito) — puede implementarse en paralelo con US1/US2 por otro desarrollador, ya que toca los mismos archivos de Compra (`CompraController`, `compras/form.blade.php`, `compras.js`) pero secciones distintas; si es la misma persona, conviene secuenciar con US1 para evitar conflictos de merge en esos archivos.
- **Polish (Phase 6)**: después de las tres historias.

## Parallel Example

```
# Foundational, en paralelo (T005, T006, T007 tocan archivos distintos):
T005 Venta.php · T006 Compra.php · T007 ConfiguracionVentas.php

# Dentro de US1, en paralelo:
T009 (Compra Requests) mientras T008 (Venta Requests)
T011 (CompraController) mientras T010 (VentaController)
T013 (StockDeCompra) mientras T012 (StockDeVenta)
T015 (vista Compra) mientras T014 (vista Venta)
T017/T018/T019 (tests, archivos distintos)

# Dentro de US2, en paralelo:
T022 (CompraController defaults) mientras T021 (VentaController defaults)
T027/T028 (tests, mismo archivo pero secciones independientes — evaluar si conviene secuencial)

# Dentro de US3, en paralelo:
T036 (vista Compra) mientras T037 (JS Compra) — mismo objetivo, archivos distintos
T038 (test) en paralelo con cualquiera de las anteriores una vez T033-T035 están listas
```

## Implementation Strategy

**MVP = User Story 1 (Phases 1-3)**: resuelve el problema reportado (el filtro por Depósito de Ventas
no cuadraba) sin necesidad de tocar Configuración & Ajustes. Se puede entregar y validar en producción
antes de encarar US2.

**User Story 3 es independiente de US1/US2** (Depósito) — resuelve un problema distinto (N° de
comprobante ficticio en Compras) en el mismo formulario. Puede implementarse antes, después o en
paralelo con las otras dos; se sugiere secuenciarla junto con US1 porque ambas tocan
`CompraController`/`compras/form.blade.php`/`compras.js`, para minimizar conflictos de merge.

**Incremental**: Setup → Foundational → US1 (test independiente) → US2 (test independiente) → US3
(test independiente, puede adelantarse) → Polish.
