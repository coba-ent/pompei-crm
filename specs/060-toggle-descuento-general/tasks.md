---

description: "Task list for 060-toggle-descuento-general"
---

# Tasks: Toggle %/monto fijo para el Descuento General

**Input**: Design documents from `/specs/060-toggle-descuento-general/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/toggle-descuento-general.md, quickstart.md

**Tests**: Incluidos — la constitución del proyecto (Principio IV) exige testing obligatorio para
lógica de cálculo de importes/IVA/descuentos/totales, que es exactamente lo que toca este spec.

**Organization**: Tasks agrupadas por user story (US1/US2/US3 de spec.md). US1 y US2 se implementan
primero sobre **Ventas** (módulo de referencia, el de mayor riesgo fiscal por ARCA); US3 replica el
mismo patrón ya probado a Presupuestos, Compras y Notas de Crédito/Débito.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Puede correr en paralelo (archivos distintos, sin dependencias entre sí)
- **[Story]**: US1, US2 o US3 según spec.md
- Rutas de archivo exactas en cada descripción

---

## Phase 1: Setup

- [ ] T001 Confirmar entorno local levantado (XAMPP + MySQL `contagram`, `npm run dev` disponible) — sin cambios de archivo, sólo prerrequisito operativo antes de migrar.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Columnas nuevas + cambio del servicio de cálculo compartido — bloquea las 3 user stories, porque las 3 dependen de que exista dónde persistir tipo/valor y de que `CalculoComprobante` sepa interpretarlos.

**⚠️ CRITICAL**: Ninguna user story puede implementarse hasta que esta fase esté completa.

- [ ] T002 [P] Migración `add_descuento_general_tipo_to_ventas_table` en `database/migrations/` — agrega `descuento_general_tipo` ENUM('porcentaje','monto') NOT NULL DEFAULT 'porcentaje' y `descuento_general_monto` DECIMAL(12,2) NULLABLE a `ventas` (data-model.md).
- [ ] T003 [P] Migración `add_descuento_general_tipo_to_presupuestos_table` en `database/migrations/` — mismas 2 columnas en `presupuestos`.
- [ ] T004 [P] Migración `add_descuento_general_tipo_a_compras_table` en `database/migrations/` — mismas 2 columnas en `compras`.
- [ ] T005 [P] Migración `add_descuento_general_a_notas_credito_debito_table` en `database/migrations/` — agrega las 3 columnas en `notas_credito_debito` (`descuento_general_tipo`, `descuento_general_pct` nueva ahí, `descuento_general_monto`), ya que esa tabla no tenía ninguna hoy.
- [ ] T006 Correr `php artisan migrate` y confirmar el esquema resultante contra data-model.md.
- [ ] T007 Cambiar la firma de `calcular()` en `app/Services/Ingresos/CalculoComprobante.php` para recibir `string $descuentoGeneralTipo, float|string|null $descuentoGeneralValor` en vez de `float|string|null $descuentoGeneralPct`, agregando la conversión monto→pct efectivo descripta en research.md R1 y data-model.md (pre-cálculo del subtotal bruto cuando el tipo es `monto`, luego mismo algoritmo de spec 044 sin cambios).
- [ ] T008 [P] Actualizar `tests/Unit/Services/Ingresos/CalculoComprobanteTest.php` con casos: modo `monto` con una alícuota, modo `monto` con dos alícuotas (prorrateo, ver spec 044 US1 escenario 3), modo `monto` con subtotal $0, monto igual al subtotal (borde válido), y confirmar que los casos existentes en modo `porcentaje` siguen pasando sin cambios (no-regresión FR-008).
- [ ] T009 [P] Agregar `descuento_general_tipo`, `descuento_general_monto` a `$fillable`/`$casts` de `app/Models/Venta.php`, `app/Models/Presupuesto.php`, `app/Models/Compra.php`.
- [ ] T010 [P] Agregar `descuento_general_tipo`, `descuento_general_pct`, `descuento_general_monto` a `$fillable`/`$casts` de `app/Models/NotaCreditoDebito.php`.
- [x] T011 Actualizar `docs/modelo_datos.md` con las columnas nuevas en las 4 tablas (Principio I de la constitución — documentación de dominio se actualiza en el mismo cambio). **Hecho durante la planificación (11/08/2026)**, antes de generar tasks.md, como exige la regla del proyecto.
- [x] T012 Actualizar `docs/documentacion_principal_crm.md` (sección de Presupuestos/Ventas/Compras/NC-ND) describiendo el toggle %/monto fijo del descuento general como parte del flujo de carga. **Hecho durante la planificación (11/08/2026)**, ídem T011.

**Checkpoint**: Migraciones aplicadas, `CalculoComprobante` acepta y calcula correctamente ambos modos, tests unitarios del servicio en verde. A partir de acá pueden empezar las user stories.

---

## Phase 3: User Story 1 - Cargar el descuento general como monto fijo (Priority: P1) 🎯 MVP

**Goal**: En el formulario de alta de Venta, poder alternar el descuento general a modo $ y que el cálculo/persistencia sea correcto.

**Independent Test**: Ver quickstart.md Escenario 1 y 3 (alta con monto fijo + validación FR-007), acotado a Ventas.

### Tests for User Story 1 ⚠️

- [ ] T013 [P] [US1] Extender `tests/Unit/TotalesVentaTest.php` con un caso de descuento general en modo `monto` (total resultante y prorrateo de IVA correctos).
- [ ] T014 [P] [US1] Extender `tests/Feature/EmisionComprobanteVentaTest.php` (o el test feature de alta de Venta correspondiente) con un caso de alta con `descuento_general_tipo = 'monto'` que persiste `descuento_general_monto` y deja `descuento_general_pct` en `null`.
- [ ] T015 [P] [US1] Test feature nuevo (o extensión del existente) para el rechazo 422 de FR-007: monto fijo mayor al subtotal de ítems.

### Implementation for User Story 1

- [ ] T016 [US1] Agregar reglas `descuento_general_tipo` (`nullable|in:porcentaje,monto`) y `descuento_general_monto` (`nullable|numeric|min:0` + regla condicional de FR-007 vía `withValidator()`, research.md R3) a `app/Http/Requests/StoreVentaRequest.php`.
- [ ] T017 [US1] Actualizar `app/Http/Controllers/VentaController.php::store()` para pasar tipo+valor a `$this->calculo->calcular(...)` y persistir `descuento_general_tipo`/`descuento_general_monto` junto con el resto de los campos ya guardados.
- [ ] T018 [US1] Agregar el botón inline %/$ junto al campo de descuento general en `resources/views/ventas/form.blade.php` (bloque `Descuento General`, ~línea 143), inicializado en modo `%` cuando no hay datos previos (alta).
- [ ] T019 [US1] En `resources/js/ventas.js`: wiring del click del botón (alterna texto/ícono %↔$, limpia el input, dispara el recálculo de totales existente usando el modo activo — mismo criterio de conversión que T007 mirrorado en JS para el preview client-side, aunque el server sea la fuente de verdad al guardar).
- [ ] T020 [US1] Incluir `descuento_general_tipo` y `descuento_general_monto` (además del `descuento_general_pct` ya enviado) en el payload AJAX de alta de `resources/js/ventas.js`.

**Checkpoint**: Alta de Venta con descuento general en modo $ funciona end-to-end (UI + validación + cálculo + persistencia).

---

## Phase 4: User Story 2 - Ver el mismo modo y valor al reabrir para editar (Priority: P1)

**Goal**: El formulario de edición de Venta muestra el modo y valor exactos con los que se guardó.

**Independent Test**: Ver quickstart.md Escenario 2, acotado a Ventas.

### Tests for User Story 2 ⚠️

- [ ] T021 [P] [US2] Extender el test feature de edición de Venta (`tests/Feature/...VentaTest.php` correspondiente) con: crear en modo $, editar sin tocar el descuento general, y confirmar que `descuento_general_tipo`/`descuento_general_monto` no cambiaron.
- [ ] T022 [P] [US2] Test feature: crear en modo %, reabrir edición, confirmar que el JSON de `edit`/el recurso devuelto trae `descuento_general_tipo = 'porcentaje'` y el valor porcentual correcto (no convertido).

### Implementation for User Story 2

- [ ] T023 [US2] Agregar las mismas reglas de T016 a `app/Http/Requests/UpdateVentaRequest.php`.
- [ ] T024 [US2] Actualizar `app/Http/Controllers/VentaController.php::update()` (mismo criterio que T017): pasa tipo+valor al servicio y persiste ambas columnas, limpiando explícitamente la que no corresponde al modo enviado (contracts/toggle-descuento-general.md).
- [ ] T025 [US2] En `resources/views/ventas/form.blade.php`, precargar el botón %/$ y el valor del input desde `$venta->descuento_general_tipo`/`pct`/`monto` cuando el formulario abre en modo edición (mismo bloque de datos iniciales ya usado para el resto de los campos, ~línea 180).
- [ ] T026 [US2] En `resources/js/ventas.js`, al inicializar el formulario con datos existentes, setear el botón en el modo persistido y el input con el valor correspondiente, sin disparar la limpieza de T019 (esa limpieza sólo aplica a un click explícito del usuario, no a la carga inicial).

**Checkpoint**: Alta y edición de Venta con el toggle funcionan de punta a punta, con persistencia consistente entre ambos flujos (US1 + US2 = MVP completo para el módulo de referencia).

---

## Phase 5: User Story 3 - Mismo comportamiento en los cuatro módulos (Priority: P2)

**Goal**: Replicar el patrón ya probado en Ventas (US1+US2) a Presupuestos, Compras y Notas de Crédito/Débito.

**Independent Test**: Ver quickstart.md Escenario 4, en los 3 módulos restantes.

### Tests for User Story 3 ⚠️

- [ ] T027 [P] [US3] Extender `tests/Unit/TotalesPresupuestoTest.php` y `tests/Feature/PresupuestoCalculoTest.php` con los mismos casos de T013/T014/T015/T021/T022 adaptados a Presupuestos.
- [ ] T028 [P] [US3] Extender `tests/Unit/TotalesCompraTest.php` y `tests/Feature/CompraCalculoTest.php` con los mismos casos adaptados a Compras.
- [ ] T029 [P] [US3] Test feature nuevo para Notas de Crédito/Débito: alta y edición (Venta y Compra) con descuento general en modo $ y %, confirmando persistencia de las 3 columnas nuevas (`descuento_general_tipo/pct/monto`) en `notas_credito_debito`.
- [ ] T030 [US3] Test feature: convertir un Presupuesto con descuento general en modo $ a Venta, confirmar que la Venta resultante conserva modo y valor sin reconvertir (Edge Case de spec.md).

### Implementation for User Story 3

- [ ] T031 [P] [US3] Presupuestos — mismas 5 tareas que T016/T017/T018/T019/T020 más T023/T024/T025/T026, aplicadas a `StorePresupuestoRequest.php`, `UpdatePresupuestoRequest.php`, `PresupuestoController.php`, `resources/views/presupuestos/form.blade.php`, `resources/js/presupuestos.js`.
- [ ] T032 [P] [US3] Compras — mismo patrón aplicado a `StoreCompraRequest.php`, `UpdateCompraRequest.php`, `CompraController.php`, `resources/views/compras/form.blade.php`, `resources/js/compras.js`.
- [ ] T033 [US3] Notas de Crédito/Débito — agregar las reglas de validación (`descuento_general_tipo`, `descuento_general_pct`, `descuento_general_monto`, con la misma validación condicional de FR-007 comparada contra el subtotal de ítems/descripción libre) a `app/Http/Requests/StoreNotaCreditoDebitoRequest.php` y `UpdateNotaCreditoDebitoRequest.php`.
- [ ] T034 [US3] Notas de Crédito/Débito — actualizar `app/Http/Controllers/NotaCreditoDebitoController.php` (`store`, `storeCompra`, `update`, `updateCompra`) para persistir las 3 columnas nuevas tal cual llegan del formulario (sin pasar por `CalculoComprobante`, research.md R4).
- [ ] T035 [US3] Notas de Crédito/Débito — agregar el botón inline %/$ en `resources/views/notas-credito-debito/form.blade.php` (bloque "Descuento General (%)", ~línea 139), con precarga en modo edición igual que T025.
- [ ] T036 [US3] Notas de Crédito/Débito — en `resources/js/notas-credito-debito.js`, wiring del botón (alterna modo, limpia valor, recalcula con `recalcular()` ya existente adaptado a monto fijo) e inclusión de las 3 columnas en el payload AJAX de `store`/`update`.
- [ ] T037 [US3] Verificación visual cruzada: confirmar que el botón, su posición relativa al campo y su comportamiento son indistinguibles entre los 4 módulos (SC-004) — recorrido manual de los 4 formularios de alta y los 4 de edición.

**Checkpoint**: Los 4 módulos tienen el toggle funcionando de punta a punta, con paridad de comportamiento.

---

## Phase 6: Polish & Cross-Cutting Concerns

- [ ] T038 [P] Actualizar `public/css/contagram-custom.css` si el botón inline necesita un ajuste de estilo puntual para verse compacto/parejo con el resto de los controles (mismo criterio ya documentado para form-controls compactos del proyecto).
- [ ] T039 Correr `php artisan test --filter=CalculoComprobanteTest && php artisan test --filter=TotalesVentaTest && php artisan test --filter=TotalesPresupuestoTest && php artisan test --filter=CompraCalculoTest` y confirmar 0 regresiones.
- [ ] T040 Ejecutar manualmente los 5 escenarios de `quickstart.md` en los 4 módulos.
- [ ] T041 `npm run build` y verificación final de que los 4 JS compilan sin errores.

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: sin dependencias.
- **Foundational (Phase 2)**: depende de Setup — bloquea las 3 user stories (migraciones + servicio de cálculo compartido).
- **US1 (Phase 3)**: depende de Foundational. Es el MVP — Ventas en modo alta.
- **US2 (Phase 4)**: depende de Foundational; en la práctica se implementa después de US1 sobre el mismo módulo (comparten controller/vista/JS), pero es independientemente verificable (edición vs. alta).
- **US3 (Phase 5)**: depende de Foundational; reutiliza el patrón validado en US1+US2 sobre Ventas, replicándolo a los otros 3 módulos — no depende de que US1/US2 estén "cerradas" como tarea formal, pero en la práctica conviene hacerlas primero para no replicar un patrón todavía no probado.
- **Polish (Phase 6)**: depende de que las user stories que se vayan a entregar estén completas.

### Parallel Opportunities

- T002-T005 (las 4 migraciones) en paralelo.
- T009/T010 (modelos) en paralelo entre sí, y en paralelo con T008 (tests del servicio) una vez que T007 esté commiteado.
- Dentro de US3, T031/T032 (Presupuestos y Compras) pueden hacerse en paralelo entre sí; T033-T036 (NC/ND) es una cadena propia pero paralelizable respecto de T031/T032 (archivos totalmente distintos).

---

## Implementation Strategy

### MVP First (User Story 1 + 2, sólo Ventas)

1. Completar Phase 1 y Phase 2 (Foundational).
2. Completar Phase 3 (US1) y Phase 4 (US2) — Ventas con el toggle funcionando en alta y edición.
3. **Parar y validar** con quickstart.md Escenarios 1-3 antes de replicar a los otros módulos.
4. Deploy/demo si se decide entregar el MVP acotado a Ventas primero.

### Incremental Delivery

1. Foundational → Ventas (US1+US2, MVP) → validar → Presupuestos/Compras/NC-ND (US3) → validar → Polish.

## Notes

- [P] = archivos distintos, sin dependencias entre sí.
- Los tests de cálculo (T008, T013-T015, T021-T022, T027-T030) son la prioridad de calidad de este
  spec, por el Principio IV de la constitución (testing obligatorio donde hay dinero/impacto fiscal) —
  no se deben saltear aunque el resto de las tareas de UI parezcan más urgentes.
- Comprobantes con CAE ya aprobado no se recalculan retroactivamente (spec 042/044) — ninguna tarea de
  este plan debe tocar comprobantes históricos.
