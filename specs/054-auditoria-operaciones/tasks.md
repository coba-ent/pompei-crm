# Tasks: Módulo de Auditoría (Log de Operaciones)

**Input**: Design documents from `/specs/054-auditoria-operaciones/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/auditoria.md, quickstart.md

**Tests**: Incluidos — la constitución del proyecto (principio IV) exige tests para lógica con
impacto fiscal/monetario, y los eventos de auditoría referencian `total` de operaciones de ese tipo.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Puede ejecutarse en paralelo (archivos distintos, sin dependencias entre sí)
- **[Story]**: A qué historia de usuario pertenece (US1, US2, US3)

---

## Phase 1: Setup

- [X] T001 Crear migración `create_logs_auditoria_table` en `database/migrations/` con el esquema de
      `data-model.md` (columnas, enums, índices `(created_at)`, `(usuario_id)`, `(tipo_operacion)`,
      `(entidad_tipo, entidad_id)`)
- [X] T002 [P] Crear migración/seeder que agrega el permiso `auditoria.ver` a la tabla `permisos` y lo
      asigna al rol Admin (`es_sistema = true`), según research.md Decisión 6
- [X] T003 [P] Crear modelo `App\Models\LogAuditoria` en `app/Models/LogAuditoria.php` (sin
      `updated_at`, casts de enums, relación `belongsTo` a `usuario`, scopes de filtro por Id/
      Operación/Usuario/rango de fecha)
- [X] T004 Registrar la ruta `/auditoria` (grupo con middleware de permiso `auditoria.ver`) en
      `routes/web.php`, apuntando a `AuditoriaController` (aún sin crear)

**Checkpoint**: tabla y permiso listos; nada todavía visible para el usuario.

---

## Phase 2: Foundational (bloqueante para todas las historias)

**Objetivo**: el mecanismo de captura de eventos (columna vertebral de todo el módulo — sin esto
ninguna historia de usuario tiene datos que mostrar).

- [X] T005 Crear `App\Services\AuditoriaService` en `app/Services/AuditoriaService.php` con el método
      `registrarEvento(string $tipoAccion, string $tipoOperacion, Model $entidad, string $detalle, ?float $total = null, ?string $origenSistema = null)`
      que resuelve `usuario_id`/`usuario_nombre` desde `auth()->user()` (o `$origenSistema` si es
      null) y crea el `LogAuditoria`, según research.md Decisión 2 y 3. Si la escritura falla, loguea
      el error en `storage/logs` y no relanza la excepción (research.md, plan.md Constraints)
- [X] T006 [P] Crear `App\Observers\VentaAuditoriaObserver` en
      `app/Observers/VentaAuditoriaObserver.php`: `created` → "creo", `updated` filtrado a los campos
      de negocio relevantes (estado, total, cliente_id — no campos derivados internos, research.md
      Decisión 7) → "modifico", transición a estado anulado → "anulo", `deleted` → "elimino". Detalle:
      cliente + nº de comprobante
- [X] T007 [P] Crear `App\Observers\PresupuestoAuditoriaObserver` en
      `app/Observers/PresupuestoAuditoriaObserver.php` (mismo patrón que T006, adaptado a Presupuesto)
- [X] T008 [P] Crear `App\Observers\CobroAuditoriaObserver` en
      `app/Observers/CobroAuditoriaObserver.php` (mismo patrón; Detalle: cliente + forma de cobro).
      El `updated` filtrado DEBE incluir monto, fecha, `cuenta_tesoreria_id` y nota — son exactamente
      los campos editables desde "Editar cobranza" (spec 053) y deben generar `tipo_accion = modifico`
- [X] T009 [P] Crear `App\Observers\GastoAuditoriaObserver` en
      `app/Observers/GastoAuditoriaObserver.php` (mismo patrón; Detalle: proveedor/concepto de gasto)
- [X] T010 [P] Crear `App\Observers\CompraAuditoriaObserver` en
      `app/Observers/CompraAuditoriaObserver.php` (mismo patrón; Detalle: proveedor + nº de
      comprobante)
- [X] T011 [P] Crear `App\Observers\MovimientoTesoreriaAuditoriaObserver` en
      `app/Observers/MovimientoTesoreriaAuditoriaObserver.php` (mismo patrón; Detalle: cuenta +
      concepto del movimiento). Cubre también la actualización in-place que dispara "Editar cobranza"
      (spec 053 FR-005: mismo movimiento, monto/cuenta/fecha actualizados) — debe generar
      `tipo_accion = modifico` igual que cualquier otro `updated`, sin tratamiento especial
- [X] T012 [P] Crear `App\Observers\MovimientoStockAuditoriaObserver` en
      `app/Observers/MovimientoStockAuditoriaObserver.php` (mismo patrón; Detalle: producto +
      depósito; sin `total` — nullable)
- [X] T013 Registrar los 7 observers (T006-T012) en `app/Providers/AppServiceProvider.php` vía
      `Model::observe()`
- [X] T014 Poblar `origen_sistema` en las creaciones automáticas de Venta desde las integraciones ML/
      TN ya existentes (`app/Services/MercadoLibre/*`, `app/Services/Tiendanube/*` — localizar el
      punto donde hoy se crea la Venta sin usuario autenticado) para que `VentaAuditoriaObserver`
      registre `origen_sistema = 'mercadolibre'` / `'tiendanube'` en vez de dejarlo nulo sin motivo

**Checkpoint**: crear/editar/anular/eliminar cualquier operación en alcance ya genera su fila en
`logs_auditoria` (verificable por Tinker/DB), aunque todavía no hay pantalla para verlo.

---

## Phase 3: User Story 1 - Ver el historial completo de operaciones (Priority: P1) 🎯 MVP

**Goal**: pantalla de Auditoría con el listado cronológico de operaciones, sin filtros todavía.

**Independent Test**: crear una Venta, un Gasto y un Cobro desde el CRM; verificar que las tres
aparecen en `/auditoria` con usuario, fecha/hora, tipo "Creó" y detalle correctos (Acceptance
Scenarios 1-3 de spec.md).

- [X] T015 [P] [US1] Test feature: `tests/Feature/AuditoriaListadoTest.php` — crear una Venta/Gasto/
      Cobro y verificar que `LogAuditoria::count()` aumenta y los campos son correctos; caso de venta
      con `origen_sistema` no nulo también aparece con su label; caso FR-009: editar dos veces la
      misma Venta y verificar que se generan 2 filas distintas con `tipo_accion = modifico` (no se
      pisa el evento anterior)
- [X] T016 [US1] Crear `App\Http\Controllers\AuditoriaController@index` en
      `app/Http/Controllers/AuditoriaController.php` — renderiza la vista con los filtros vacíos y el
      selector de fecha en el día actual (FR-005)
- [X] T017 [US1] Crear `App\Http\Controllers\AuditoriaController@datatable` — endpoint AJAX server-
      side (yajra/laravel-datatables) que devuelve el listado ordenado por `created_at` desc, filtrado
      por defecto al día actual, mapeando `tipo_accion`/`tipo_operacion` a sus labels en español
      (Creó/Modificó/Eliminó/Anuló; Venta/Presupuesto/Cobro/Gasto/Compra/Movimiento)
- [X] T018 [US1] Crear vista `resources/views/auditoria/index.blade.php` extendiendo
      `layouts.default`, con la tabla (columnas Id, Fecha y Hora, Usuario, Tipo, Operación, Detalle,
      Total) y el selector de fecha, siguiendo la estructura relevada en
      `docs/documentacion_principal_crm.md` §7 (entrada Auditoría)
- [X] T019 [US1] Crear `resources/js/auditoria.js` — inicializa el DataTable server-side apuntando a
      `/auditoria/datatable`, con las columnas de T017
- [X] T020 [US1] Agregar el ítem "Auditoría" al dropdown de usuario en
      `resources/views/elements/header.blade.php`, **inmediatamente debajo del link "Configuración &
      Ajustes"** (línea ~111, dentro del mismo bloque `@if (auth()->user()->esAdmin())` que ya
      envuelve "Empresa" y "Configuración & Ajustes"), enlazando a `route('auditoria.index')`, visible
      sólo si el usuario tiene el permiso `auditoria.ver` (además del `esAdmin()` ya existente, si el
      permiso lo tiene alguien fuera de Admin más adelante, evaluar `@can` en vez del `@if` actual)

**Checkpoint**: US1 completa y demostrable de forma independiente — MVP entregable.

---

## Phase 4: User Story 2 - Filtrar el historial (Priority: P2)

**Goal**: agregar los filtros Id, Operación, Usuario y rango de fecha, combinables.

**Independent Test**: con datos ya cargados (de US1 o de datos de prueba), aplicar cada filtro y
combinaciones, y verificar que el listado se acota correctamente (Acceptance Scenarios 1-5 de US2).

- [X] T021 [P] [US2] Test feature: `tests/Feature/AuditoriaFiltrosTest.php` — cubre filtro por Id,
      por Operación, por Usuario, por rango de fecha, y la combinación AND de Operación + Usuario
- [X] T022 [US2] Extender `AuditoriaController@datatable` (T017) para aceptar y aplicar los query
      params `id`, `operacion`, `usuario_id`, `fecha_desde`, `fecha_hasta` de forma combinable (AND),
      según `contracts/auditoria.md`
- [X] T023 [US2] Agregar el panel "Filtros" (botón + colapsable) a
      `resources/views/auditoria/index.blade.php`: input Id, Select2 de Operación (catálogo fijo de
      `tipo_operacion`), Select2 de Usuario (ajax sobre `usuarios` + orígenes de sistema como opciones
      adicionales), y el selector de fecha ya existente de US1 pasa a ser funcional como filtro
- [X] T024 [US2] Actualizar `resources/js/auditoria.js` para leer los valores de los filtros y
      recargar el DataTable (`table.ajax.reload()`) al cambiar cualquiera de ellos

**Checkpoint**: US2 completa — el listado de US1 ahora es filtrable, sin romper la funcionalidad de
US1.

---

## Phase 5: User Story 3 - Exportar el historial filtrado (Priority: P3)

**Goal**: botón "Exportar" que descarga exactamente el resultado filtrado actual.

**Independent Test**: aplicar un filtro que acote el listado a un subconjunto conocido y exportar;
verificar que el archivo contiene exactamente esas filas (Acceptance Scenarios 1-2 de US3).

- [X] T025 [P] [US3] Test feature: `tests/Feature/AuditoriaExportarTest.php` — exportar con un filtro
      aplicado y verificar que el contenido del archivo coincide con el filtro (no con el total de la
      tabla); caso de filtro sin resultados devuelve un error manejable, no un archivo vacío
- [X] T026 [US3] Crear `App\Exports\AuditoriaExport` en `app/Exports/AuditoriaExport.php`
      (maatwebsite/excel) que reusa el mismo query builder de filtros que `AuditoriaController@datatable`
      (extraer ese query a un método compartido, ej. `AuditoriaService::queryFiltrado(array $filtros)`,
      para evitar duplicar la lógica de filtros — research.md Decisión 4)
- [X] T027 [US3] Crear `AuditoriaController@exportar` — valida que el resultado filtrado no esté
      vacío (si lo está, responde JSON de error para que el frontend muestre un toast, según
      especificación de diseño obligatoria del CLAUDE.md del proyecto) y devuelve la descarga
- [X] T028 [US3] Agregar el botón "Exportar" a `resources/views/auditoria/index.blade.php` y su
      manejo AJAX en `resources/js/auditoria.js` (descarga vía `window.location` al endpoint con los
      filtros actuales serializados, con manejo de toast de error si la respuesta es el JSON de "sin
      datos")

**Checkpoint**: US3 completa — las 3 historias de usuario funcionan de forma independiente y
combinada.

---

## Phase 6: Polish & Cross-Cutting Concerns

- [X] T029 [P] Test feature: `tests/Feature/AuditoriaUsuarioBajaTest.php` — dar de baja
      (`activo = false`) a un usuario con eventos de auditoría ya generados y verificar que
      `usuario_nombre` se sigue mostrando correctamente (Edge Case de spec.md)
- [X] T029a [P] Test feature: `tests/Feature/AuditoriaEditarCobranzaTest.php` — editar una cobranza
      con movimiento de tesorería asociado (spec 053) y verificar que se generan 2 eventos de
      auditoría distintos (`cobro` modificado + `movimiento_tesoreria` modificado), no uno solo ni
      duplicados
- [X] T030 [P] Test feature: `tests/Feature/AuditoriaPermisoTest.php` — un usuario sin el permiso
      `auditoria.ver` recibe 403 al acceder a `/auditoria` y a sus endpoints AJAX
- [X] T031 [P] Revisar que ninguna ruta de `AuditoriaController` expone edición/borrado de
      `logs_auditoria` (FR-007) — no debe haber métodos `update`/`destroy` en el controller ni rutas
      correspondientes
- [X] T032 Ejecutar el `quickstart.md` completo (5 escenarios) manualmente contra el ambiente de
      desarrollo antes de dar la feature por terminada
- [X] T033 [P] Test/medición de performance (SC-003): sembrar ~10.000 filas en `logs_auditoria`
      (seeder o factory) y verificar que `AuditoriaController@datatable` responde en menos de 2
      segundos con un filtro combinado (Operación + Usuario + fecha) aplicado

---

## Dependencies & Execution Order

- **Phase 1 (Setup)** → **Phase 2 (Foundational)**: bloqueante, sin datos no hay nada que mostrar.
- **Phase 3 (US1)** depende de Phase 2. Es el único requisito para un MVP demostrable.
- **Phase 4 (US2)** depende de Phase 3 (extiende el mismo `AuditoriaController@datatable` y la misma
  vista) — no es independiente a nivel de código, aunque sí es una historia de usuario separada a
  nivel de valor entregado.
- **Phase 5 (US3)** depende de Phase 3 (reusa el query de datatable) — puede desarrollarse en
  paralelo a Phase 4 si dos personas trabajan en el feature, ya que toca archivos distintos
  (`AuditoriaExport`, no el datatable en sí) más allá de agregar el botón a la misma vista.
- **Phase 6 (Polish)** depende de que Phase 3 y el permiso de Phase 1 (T002) ya existan.

## Parallel Execution Examples

- Dentro de Phase 2: T006-T012 (los 7 Observers) son `[P]` entre sí — cada uno toca un archivo
  distinto y no depende de los demás, sólo de T005 (`AuditoriaService`).
- Dentro de Phase 1: T002 y T003 son `[P]` entre sí.
- T015, T021, T025, T029, T030, T031 (tasks de test) pueden escribirse en paralelo a la
  implementación de su propia historia si se sigue un enfoque TDD, o en paralelo entre sí si se
  posponen al final de cada fase.

## Implementation Strategy

**MVP primero**: Phase 1 + Phase 2 + Phase 3 (US1) ya constituyen un módulo de Auditoría usable —
ver el historial completo de operaciones, sin filtros ni exportación. Es el corte recomendado si se
necesita entregar valor incremental.

**Entrega incremental sugerida**: MVP (US1) → US2 (filtros, alto valor práctico dado el volumen de
operaciones esperado) → US3 (exportar, conveniencia) → Polish.
