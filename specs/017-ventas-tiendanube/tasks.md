# Tasks: Ventas de Tiendanube

**Spec**: [spec.md](./spec.md) · **Plan**: [plan.md](./plan.md) · **Datos**: [data-model.md](./data-model.md) · **Contratos**: [contracts/rutas-internas.md](./contracts/rutas-internas.md) · **Validación**: [quickstart.md](./quickstart.md)

**Branch**: `017-ventas-tiendanube` · **Fecha**: 2026-07-29

**Tests**: incluidos y **obligatorios** — el principio IV de la constitución los exige para toda lógica
de importes, comprobantes, stock y saldos de tesorería, que es exactamente el centro de esta spec.

**Convención**: `[P]` = paralelizable (archivo distinto, sin dependencias pendientes). `[USn]` = historia
de usuario a la que pertenece.

---

## Phase 1 — Setup

- [X] T001 Crear los directorios `app/Http/Controllers/Ingresos/` (si no existe tras la spec 012),
  `app/Services/Tiendanube/` y `resources/views/ingresos/tiendanube/`, siguiendo plan.md §Project
  Structure
- [X] T002 [P] Confirmar que no hace falta ninguna dependencia nueva: `Http`, `Cache::lock`, DataTables,
  Select2, Toastr ya están en uso desde las specs 011/012/015 (plan.md §Technical Context)

---

## Phase 2 — Foundational (bloquea todas las historias)

### 2.a Esquema de datos

- [X] T003 [P] Migración `create_tn_ordenes_table` con todos los campos e índices de data-model.md §1,
  incluidos los **únicos** en `tn_order_id` y `venta_id` (FR-013, FR-032b)
- [X] T004 [P] Migración `create_tn_orden_items_table` per data-model.md §2
- [X] T005 [P] Migración `create_tn_variante_producto_table` con los **dos índices únicos** que
  garantizan la cardinalidad 1:1 (FR-022)
- [X] T006 [P] Migración `add_ventas_fields_to_tn_configuracion_table` con las columnas de
  data-model.md §4 (incluida `cuenta_tesoreria_id`, nueva respecto de Mercado Libre — research.md R5)
- [X] T007 [P] Migración `add_tn_customer_id_to_clientes_table` con índice, mismo patrón que
  `add_ml_user_id_to_clientes_table` (spec 012) — FR-036/036a
- [X] T008 [P] Migración `add_tiendanube_to_origen_enum_ventas_table` (o el mecanismo ya usado por la
  spec 012 para el enum `origen`) agregando el valor `tiendanube` (FR-035)

### 2.b Enums y modelos

- [X] T009 [P] Crear `app/Enums/Tiendanube/EstadoConversion.php` con los cinco estados y transiciones
  válidas de data-model.md §1 — **no** reutilizar el enum de Mercado Libre (research.md R6)
- [X] T010 [P] Crear `app/Enums/Tiendanube/MotivoRequiereAtencion.php` con los motivos propios de
  Tiendanube (variante sin vincular, cliente ambiguo, producto inexistente, moneda inválida, cuenta de
  Tesorería no configurada, datos incompletos, error de conversión — **sin** "con variantes" ni "alerta
  de fraude", que no aplican acá, spec.md Assumptions)
- [X] T011 [P] Crear `app/Models/Integraciones/TiendanubeOrden.php` con relaciones, casts y scopes por
  estado de conversión
- [X] T012 [P] Crear `app/Models/Integraciones/TiendanubeOrdenItem.php`
- [X] T013 [P] Crear `app/Models/Integraciones/TiendanubeVarianteProducto.php` con la relación a
  Producto
- [X] T014 Extender `app/Models/Integraciones/TiendanubeConfiguracion.php` (spec 015) con los campos
  nuevos, sus casts, y `depositoEfectivo()` calcado de `MercadoLibreConfiguracion::depositoEfectivo()`
  (research.md R4, FR-047)
- [X] T015 [P] Extender `app/Models/Venta.php` con `tiendanube` en el scope/enum de `origen` existente
  (FR-035)

### 2.c Cliente de API y traducción

- [X] T016 Crear `app/Services/Tiendanube/TraductorOrdenes.php` como único punto que interpreta el
  formato de Tiendanube, mapeando `status`+`payment_status` a `EstadoConversion` según la tabla de
  FR-007a (research.md R1)
- [X] T017 Implementar en `TraductorOrdenes` el descarte explícito de órdenes `storefront=meli` como
  segunda capa de defensa, independiente del filtro de la consulta (FR-012a, research.md R2) — tratar
  `storefront` ausente/vacío como **no** `meli`
- [X] T018 [P] Test de `TraductorOrdenes` en `tests/Feature/Integraciones/TiendanubeTraductorOrdenesTest.php`:
  cada combinación de `status`/`payment_status` mapea al estado de conversión correcto de la tabla
  FR-007a; una orden `storefront=meli` nunca se persiste, incluso si llegara desde la consulta
  (FR-012a, SC-010)
- [X] T019 [P] Extender `app/Services/Ingresos/StockDeVenta.php::resolverDeposito()` con la rama
  `$venta->origen === 'tiendanube' → TiendanubeConfiguracion::actual()->depositoEfectivo()` (plan.md §5,
  FR-047)
- [X] T020 [P] Test de regresión en `tests/Feature/Ingresos/VentaStockTest.php` (ya existente, spec 012):
  confirmar que la rama nueva de T019 no cambia el depósito que usan las Ventas manuales ni las de
  Mercado Libre

**Checkpoint**: esquema, modelos y traducción listos — las historias de usuario pueden empezar.

---

## Phase 3 — US1: Ver las órdenes de Tiendanube (P1) 🎯 MVP

**Objetivo**: traer las órdenes y mostrarlas, excluyendo el canal Mercado Libre. **Test independiente**:
presionar "Sincronizar ahora" con la tienda conectada y ver las órdenes reales, sin convertir ninguna.

- [X] T021 [US1] Crear `app/Services/Tiendanube/SincronizadorOrdenes.php`: llama a
  `ClienteTiendanube::leer('list_orders', [...])` con `completed_at_from`/`completed_at_to` acotando la
  ventana de `dias_primera_sync` días **en cada corrida** (no sólo la primera — corrección post-019,
  spec.md FR-013/FR-016: la tool real no tiene `updated_at_min`/`created_at_min` ni `channels`),
  paginación (`page`/`limit`), *upsert* por `tn_order_id` = `id` de la tool (no `number`); exclusión de
  `storefront=meli` queda para `TraductorOrdenes` (T017), no en esta consulta (FR-012, FR-013, FR-016)
- [X] T022 [US1] Implementar en `SincronizadorOrdenes` el candado propio con `Cache::lock`, independiente
  del de Mercado Libre (FR-014)
- [X] T023 [US1] Implementar los cortes previos a paginar: función "Tiendanube" desactivada, modo sólo
  lectura, conexión caída o no configurada, con un único registro en `tn_operaciones_log` (FR-017,
  FR-018)
- [X] T024 [US1] Implementar persistencia de avance por página para retomar sin reprocesar ante una
  interrupción (FR-015)
- [X] T025 [US1] Implementar espera creciente ante 429 y reintento acotado ante fallas temporales —
  reutiliza `ClienteTiendanube` (spec 019); sin número de tasa propio verificado para `list_orders`
  (corrección post-019, ver FR-020) (FR-020)
- [X] T025a [US1] Registrar **cada** corrida de sincronización (éxito, error, bloqueada) en
  `tn_operaciones_log`, no sólo los intentos bloqueados de T023 — mismo criterio que
  `ml_operaciones_log` en la spec 012 (FR-019)
- [X] T026 [US1] Crear `app/Http/Controllers/Ingresos/TiendanubeVentaController.php` con `index`,
  `datatable` y `sincronizar` según contracts §1
- [X] T027 [US1] Registrar las rutas del grupo Ingresos → Tiendanube en `routes/web.php` con el permiso
  `ventas.ver` y el guard de función activa (FR-002, FR-003)
- [X] T028 [US1] Crear la vista del listado en `resources/views/ingresos/tiendanube/index.blade.php`
  extendiendo `layouts.default`, con columnas `status`/`payment_status`/`fulfillment_status` (corrección
  post-019: no `shipping_status`) (FR-005)
- [X] T028a [US1] Distinguir visualmente en el listado las órdenes en "Requiere atención", mostrando el
  motivo concreto (`motivo_detalle`) sin que el usuario tenga que abrir el detalle para verlo (FR-007,
  FR-007b) — mismo criterio que la spec 012 (FR-007/FR-007b)
- [X] T029 [US1] Crear `resources/js/tiendanube-ventas.js` con la DataTable server-side, panel de
  filtros y botón "Sincronizar ahora" con Toastr, sin recarga de página (FR-004, FR-006, FR-009)
- [X] T030 [US1] Registrar el bundle nuevo en `vite.config.js` y el pagelevel en `config/dz.php`
- [X] T031 [US1] Agregar la entrada condicional "Tiendanube" al menú Ingresos en
  `resources/views/elements/sidebar.blade.php`, visible sólo con la función activa (FR-002)
- [X] T032 [P] [US1] Test en `tests/Feature/Integraciones/TiendanubeSincronizacionTest.php`:
  sincronización trae órdenes, re-sincronizar no duplica, corrida interrumpida se retoma, órdenes
  `storefront=meli` jamás aparecen en el listado (FR-013, FR-015, SC-004, SC-010, SC-014)
- [X] T033 [P] [US1] Test de los tres cortes de bloqueo y del acceso denegado sin permiso (FR-017,
  FR-018, FR-003)

**Checkpoint**: US1 funcional — listado poblado, sin exposición del canal `meli`.

---

## Phase 4 — US2: Vincular variantes con productos (P1)

**Objetivo**: relación 1:1 persistente por variante. **Test independiente**: vincular una variante y
comprobar que la siguiente orden que la incluya ya viene resuelta.

- [X] T034 [US2] Crear `app/Http/Controllers/Ingresos/TiendanubeVinculacionController.php` con `index`,
  `datatable`, `store`, `update` y `destroy` según contracts §2
- [X] T035 [US2] Crear el FormRequest de vinculación validando la cardinalidad 1:1 con mensajes por
  campo (FR-022)
- [X] T036 [US2] Implementar en `destroy` la advertencia por órdenes ya convertidas, sin modificar las
  Ventas existentes (FR-026, FR-062)
- [X] T037 [US2] Registrar las rutas de vinculación en `routes/web.php`
- [X] T038 [US2] Crear la vista `resources/views/ingresos/tiendanube/vinculaciones.blade.php` con
  DataTable server-side (FR-024, FR-025)
- [X] T039 [P] [US2] Test en `tests/Feature/Integraciones/TiendanubeVinculacionTest.php`: crear, editar,
  eliminar vínculo; cardinalidad 1:1 rechazada en ambos sentidos (FR-022, SC-006, SC-007)

**Checkpoint**: US1 + US2 — listado y vinculación operativos.

---

## Phase 5 — US3: Convertir manualmente una orden en Venta (P1)

**Objetivo**: conversión completa con cobranza y stock. **Test independiente**: convertir una orden
pagada y vinculada, verificar Venta con total exacto, cobrada y stock descontado.

- [X] T040 [US3] Crear `app/Services/Tiendanube/ResolutorCliente.php`: emparejar por `tn_customer_id`,
  luego por `comprador_email`; crear Cliente nuevo si no existe; marcar ambiguo si hay más de un Cliente
  con el mismo email (FR-036, FR-036a, FR-037, FR-038)
- [X] T041 [US3] Implementar en `ResolutorCliente` la derivación del tipo de comprobante: primero la
  condición de IVA ya cargada en el Cliente emparejado; si no la tiene, aproximar por **longitud de
  `customer.cpf_cnpj`** según la tabla de FR-040 corregida (11 dígitos→A, 7-8 dígitos u otro/ausencia→B;
  corrección post-019: no existe `billing_document_type`, y `cpf_cnpj` está vacío en las 9 órdenes reales
  de la tienda, así que B es el caso dominante en la práctica) —
  FR-039/FR-040/FR-040a/FR-040d
- [X] T041a [US3] Implementar en `ResolutorCliente` que **no** se sobrescriba la condición de IVA ni los
  datos fiscales que un Cliente ya tenía cargados cuando Tiendanube informe algo distinto: completar
  únicamente los campos vacíos (FR-041a) — mismo criterio que la spec 012 (FR-041a)
- [X] T042 [US3] Crear `app/Services/Tiendanube/ConversorOrdenAVenta.php`: candado por orden
  (`Cache::lock`), revalidación, transacción única que crea Venta+ítems+cobranza+stock (FR-032a, FR-048)
- [X] T043 [US3] Implementar en `ConversorOrdenAVenta` la desagregación de IVA reutilizando el mismo
  mecanismo que Mercado Libre (FR-030a) sin descuento general ni conceptos extra (FR-030c), rechazando
  moneda distinta (FR-030d)
- [X] T044 [US3] Implementar la resolución de la cuenta de Tesorería **configurada** por FK (no por
  nombre fijo, research.md R5) y el rechazo con motivo claro si no existe o está inactiva (FR-045,
  FR-045a)
- [X] T045 [US3] Implementar el respaldo de unicidad orden→Venta a nivel de datos sobre `tn_ordenes.venta_id`
  (ya cubierto por el índice de T003, FR-032b) y el manejo del `QueryException` de carrera (FR-032a)
- [X] T046 [US3] Agregar las acciones `convertir`/`convertirGuardar` a `TiendanubeVentaController`
  reutilizando el formulario de página completa "Nueva Venta" precargado (contracts §1, FR-028, FR-029)
- [X] T046a [US3] Implementar en el formulario de conversión el selector con buscador para vincular
  **sobre la marcha** una variante sin producto asociado, creando el vínculo en `tn_variante_producto`
  al guardar (FR-023) — mismo patrón inline que la spec 012 (FR-023)
- [X] T047 [US3] Registrar las rutas de conversión en `routes/web.php`
- [X] T048 [US3] Extender el listado de Ventas: columna/filtro "Creada Desde" agrega "Tiendanube"
  (FR-035a) — **no** crear un filtro separado
- [X] T049 [P] [US3] Test en `tests/Feature/Integraciones/TiendanubeConversionTest.php`: conversión
  crea Venta con total exacto, cobrada, stock descontado; reintento sobre orden convertida rechaza sin
  duplicar; conversión concurrente produce una sola Venta (FR-030, FR-032, SC-003, SC-004a, SC-009)
- [X] T050 [P] [US3] Test de derivación de comprobante en
  `tests/Feature/Integraciones/TiendanubeComprobanteTest.php`: `cpf_cnpj` de 11 dígitos→A, 7-8/otro/sin
  dato→B, Cliente ya con condición de IVA cargada usa esa condición en vez de aproximar (corrección
  post-019: casos armados con `cpf_cnpj`, no `billing_document_type`) (FR-039, FR-040, SC-015)
- [X] T051 [P] [US3] Test de precondiciones: variante sin vincular, cliente ambiguo, moneda distinta,
  sin cuenta de Tesorería configurada — cada una rechaza la conversión con el motivo correcto

**Checkpoint**: US1 + US2 + US3 — producto mínimo utilizable (conversión manual completa).

---

## Phase 6 — US4: Configurar la sincronización y su comportamiento (P2)

**Objetivo**: pantalla de configuración operativa. **Test independiente**: cambiar frecuencia, depósito
y cuenta de Tesorería, confirmar que persisten y que la sincronización programada los respeta.

- [X] T052 [US4] Extender `TiendanubeConfiguracionController` (spec 015) con el endpoint
  `guardarVentas` (contracts §3): `deposito_id`, `categoria_venta_id`, `cuenta_tesoreria_id`,
  `frecuencia_sync_minutos`, `dias_primera_sync`, `creacion_automatica`
- [X] T053 [US4] Crear el FormRequest de validación, con `cuenta_tesoreria_id` **nullable pero
  bloqueante al convertir** (FR-045a) — distinto de `deposito_id`/`categoria_venta_id`, que sí tienen
  fallback o ausencia tolerada
- [X] T054 [US4] Extender la vista de configuración de Tiendanube (spec 015) con los selectores nuevos
  (Select2, regla obligatoria del proyecto) y la advertencia permanente de riesgo de sobreventa hasta la
  spec 018 (spec.md, Advertencias)
- [X] T055 [US4] Crear `app/Console/Commands/SincronizarOrdenesTiendanube.php`, mismo mecanismo de
  portabilidad hosting-compartido/VPS que `mercadolibre:sincronizar-ordenes` (FR-011)
- [X] T056 [P] [US4] Test de configuración: persistencia de cada campo, bloqueo de conversión sin cuenta
  de Tesorería, advertencia de sobreventa visible (FR-045a, spec.md Advertencias)

**Checkpoint**: US1-US4 — módulo desatendido y configurable.

---

## Phase 7 — US5: Crear las ventas automáticamente (P2)

**Objetivo**: automatización sin supervisión. **Test independiente**: activar el interruptor,
sincronizar con una orden pagada y vinculada, verificar que la Venta aparece sola.

- [X] T057 [US5] Implementar en `SincronizadorOrdenes` la delegación a `ConversorOrdenAVenta` cuando
  `creacion_automatica` está activo, por cada orden en estado "Lista para convertir" (FR-051)
- [X] T058 [US5] Implementar el marcado "Requiere atención" con motivo concreto cuando la orden no sea
  resoluble automáticamente, sin descontar stock (FR-052, FR-058 no aplica acá — ver US6)
- [X] T059 [US5] Registrar en la Venta creada automáticamente el indicador y momento de creación
  automática (FR-054)
- [X] T060 [US5] Implementar el manejo de fallo durante la creación automática: orden marcada con
  motivo, error registrado, sin Venta parcial (FR-055)
- [X] T061 [P] [US5] Test en `tests/Feature/Integraciones/TiendanubeCreacionAutomaticaTest.php`:
  interruptor activo convierte solas las resolubles, deja señaladas las no resolubles sin mover stock,
  interruptor desactivado no crea ninguna (FR-051, FR-052, FR-056, SC-005)

**Checkpoint**: US1-US5 — paridad funcional completa con Mercado Libre (spec 012).

---

## Phase 8 — US6: Enterarse de cancelaciones y reembolsos posteriores (P3)

**Objetivo**: salvaguarda de consistencia. **Test independiente**: cambiar el estado de una orden ya
convertida y verificar que el listado lo refleja sin tocar la Venta.

- [X] T062 [US6] Implementar en `SincronizadorOrdenes`/`TraductorOrdenes` la detección de `status`
  `cancelled` o `payment_status` `refunded`/`partially_refunded`/`voided` sobre una orden ya convertida,
  sin modificar la Venta (FR-057, FR-058)
- [X] T063 [US6] Mostrar en el listado, de forma destacada, la orden convertida cuyo estado cambió
  después, con acceso a la Venta (US6, Acceptance Scenarios)
- [X] T064 [US6] Deshabilitar la conversión de una orden cancelada antes de convertirse (FR-059)
- [X] T065 [P] [US6] Test en `tests/Feature/Integraciones/TiendanubeCancelacionesTest.php`: orden
  convertida que se cancela/reembolsa no modifica la Venta; orden no convertida que se cancela
  deshabilita "Crear Venta"

**Checkpoint**: las 6 historias de usuario completas.

---

## Phase 9 — Polish & Cross-Cutting Concerns

- [X] T066 [P] Correr la suite completa de `tests/Feature/Integraciones/` (specs 011-013, 015, 016) y
  confirmar que sigue en verde — regresión mínima (quickstart.md §Regresión mínima)
- [X] T067 [P] Actualizar `CREDENCIALES_ACCESO.txt` si el testing manual requirió datos de acceso nuevos
  (CLAUDE.md, regla de credenciales)
- [X] T068 Ejecutar `quickstart.md` end-to-end contra el entorno local (migraciones, build de assets,
  Escenarios 1-5) antes de dar la feature por terminada

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: sin dependencias.
- **Foundational (Phase 2)**: depende de Setup — **bloquea** las 6 historias.
- **US1 (Phase 3)**: depende sólo de Foundational — 🎯 MVP.
- **US2 (Phase 4)**: depende de Foundational; independiente de US1 en el código, pero probarla de punta
  a punta es más natural con el listado ya andando.
- **US3 (Phase 5)**: depende de Foundational **y** de US2 (necesita variantes vinculadas para convertir
  sin pedir el producto en cada prueba, aunque el flujo inline de FR-023 técnicamente no lo exige).
- **US4 (Phase 6)**: depende de Foundational; independiente de US1-US3 en el código (es sólo
  configuración), pero sin US3 no hay nada que la cuenta de Tesorería configurada afecte en la práctica.
- **US5 (Phase 7)**: depende de US3 (reutiliza `ConversorOrdenAVenta`) y de US4 (el interruptor vive en
  la configuración).
- **US6 (Phase 8)**: depende de US1 (listado) y US3 (necesita órdenes ya convertidas para tener algo que
  cancelar).
- **Polish (Phase 9)**: depende de que todas las historias deseadas estén completas.

### Parallel Opportunities

- Todas las migraciones de Foundational (T003-T008) son `[P]` entre sí — archivos distintos, sin
  dependencias entre ellas (ninguna referencia a una columna que otra cree).
- Los enums y modelos (T009-T015) son `[P]` entre sí, pero T014 (extender `TiendanubeConfiguracion`)
  depende de que T006 (migración de columnas) haya corrido.
- T016-T018 (traducción) son secuenciales dentro de sí (T017 extiende lo que T016 crea; T018 testea
  ambos).
- Los tests de cada historia marcados `[P]` pueden escribirse en paralelo entre sí antes de la
  implementación de esa historia (TDD), y en paralelo con los tests de otra historia si dos personas
  trabajan simultáneamente.

---

## Parallel Example: Foundational — esquema

```bash
Task: "Migración create_tn_ordenes_table"
Task: "Migración create_tn_orden_items_table"
Task: "Migración create_tn_variante_producto_table"
Task: "Migración add_ventas_fields_to_tn_configuracion_table"
Task: "Migración add_tn_customer_id_to_clientes_table"
Task: "Migración add_tiendanube_to_origen_enum_ventas_table"
```

## Parallel Example: US3 (tests, antes de implementar)

```bash
Task: "Test: conversión crea Venta con total exacto, cobrada, stock descontado"
Task: "Test: derivación de comprobante — CUIT/DNI/otro/Cliente ya cargado"
Task: "Test: precondiciones rechazan conversión con el motivo correcto"
```

---

## Implementation Strategy

### MVP First (US1)

1. Setup + Foundational.
2. US1 → listado poblado, con exclusión de `storefront=meli` verificada.
3. **STOP y VALIDAR**: correr quickstart.md Escenario 1.

### Incremental Delivery

1. Setup + Foundational → esquema y traducción listos.
2. US1 → ver órdenes (MVP).
3. US2 → vincular variantes.
4. US3 → conversión manual (producto mínimo utilizable — junto con US1+US2).
5. US4 → configuración completa.
6. US5 → automatización.
7. US6 → salvaguarda de cancelaciones.
8. Polish → regresión y validación end-to-end.

---

## Notes

- `[P]` = archivos distintos, sin dependencias pendientes.
- `[USn]` mapea la tarea a su historia de usuario para trazabilidad.
- FR-046/FR-046a/FR-046d (stock) no generan tareas de implementación propias más allá de T019: se
  reutiliza `StockDeVenta` tal cual la dejó la spec 012 — a diferencia de esa spec, acá no hay brecha que
  cerrar.
- Commit después de cada tarea o grupo lógico.
