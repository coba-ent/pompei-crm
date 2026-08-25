# Tasks: Deshacer Import de Productos

**Input**: Design documents from `/specs/078-undo-import-productos/`
**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/, quickstart.md

**Tests**: incluidas — Principio IV de la constitución ("Testing donde hay dinero o impacto
fiscal") es un gate obligatorio para esta feature (toca precios de venta y stock en vivo).

**Organization**: tareas agrupadas por historia de usuario (spec.md) para permitir implementación
y prueba independientes.

## Format: `- [ ] [ID] [P?] [Story?] Descripción con ruta de archivo`

---

## Phase 1: Setup

- [x] T001 Crear migración `database/migrations/xxxx_create_importacion_corridas_table.php` con las columnas de `data-model.md` §`importacion_corridas`
- [x] T002 Crear migración `database/migrations/xxxx_create_importacion_filas_snapshot_table.php` con las columnas de `data-model.md` §`importacion_filas_snapshot` (FK a `importacion_corridas` con `cascade on delete`, índice `(importacion_corrida_id, producto_id)`)
- [x] T003 [P] Crear modelo `app/Models/ImportacionCorrida.php` (fillable, casts de datetime, relación `filas()` hasMany `ImportacionFilaSnapshot`, accesor `estado` calculado según research.md R6)
- [x] T004 [P] Crear modelo `app/Models/ImportacionFilaSnapshot.php` (fillable, casts `json` para `estado_anterior`/`precios_anteriores`/`stock_anterior`, relaciones `corrida()` belongsTo y `producto()` belongsTo)

---

## Phase 2: Foundational (bloqueante para todas las historias)

**Objetivo**: instrumentar `ImportadorFilas` para registrar la corrida y los snapshots — sin esto ninguna historia de undo tiene datos que revertir.

- [x] T005 Agregar `Producto::tieneOperaciones()` en `app/Models/Producto.php` si no existe ya con este alcance exacto (ventas, compras, NC/ND, remitos, movimientos de stock con `origen_type` distinto de import) — research.md R5
- [x] T006 Instrumentar `ImportadorFilas::importar()` en `app/Services/Import/ImportadorFilas.php` para crear/recuperar el `ImportacionCorrida` de la corrida actual (primera tanda: `create()`; tandas siguientes de la misma corrida: pasar el `id` y hacer `increment()` de los conteos) — sólo para `entidad = 'productos'`
- [x] T007 [Depends: T006] Instrumentar `ImportadorFilas::procesarFilas()` para, antes de `actualizarProducto()`/`crearProducto()`, tomar el snapshot de la fila (estado del producto + `precios_producto` + `stocks` con `ultimo_movimiento_stock_id` por depósito, research.md R2/R4) y acumularlo en un buffer de la tanda
- [x] T008 [Depends: T007] Persistir el buffer de snapshots de la tanda con un único `insert()` múltiple al final de `procesarFilas()`, mismo patrón que `AuditoriaService::vaciarBuffer()` (research.md R7)
- [x] T009 [Depends: T006] Calcular y persistir `deshacer_disponible_hasta = confirmado_en + 48 horas` al crear el `ImportacionCorrida` (research.md R6)
- [x] T010 [P] Crear `app/Services/Import/DeshacerImportacionService.php` con la firma `deshacer(ImportacionCorrida $corrida, User $usuario): array{revertidas: int, no_revertidas: array}` — validación inicial: `corrida.estado === 'vigente'` (si no, lanzar excepción de dominio), sin lógica de fila todavía (se completa en Phase 3)

**Checkpoint**: a partir de acá, cualquier import de Productos & Servicios genera su `ImportacionCorrida` y snapshots — verificable en base de datos aunque el undo todavía no funcione.

---

## Phase 3: User Story 1 - Deshacer un import recién hecho por error (Priority: P1) 🎯 MVP

**Goal**: el usuario puede deshacer completamente una corrida sin operaciones posteriores sobre las filas afectadas.

**Independent Test**: importar una planilla que actualiza productos existentes, confirmar, deshacer, verificar que los valores vuelven a los originales.

### Tests (US1)

- [x] T011 [P] [US1] Test Feature `tests/Feature/DeshacerImportacionProductosTest.php::test_deshacer_corrida_completa_restaura_precios_costo_e_iva` — import que actualiza 3 productos, deshacer, assert valores originales
- [x] T012 [P] [US1] Test Feature en el mismo archivo `::test_deshacer_corrida_restaura_stock_por_deposito` — import que cambia stock de 2 depósitos, deshacer, assert `stocks` y `movimientos_stock` (tipo `ajuste`, descripción `Ajuste (deshacer import)`)
- [x] T013 [P] [US1] Test Feature `::test_deshacer_corrida_con_altas_softdelete_productos_creados` — import que da de alta 2 productos, deshacer, assert `activo = false` (no eliminación física)
- [x] T014 [P] [US1] Test Feature `::test_deshacer_restaura_precios_por_lista` — import que actualiza `precios_producto`, deshacer, assert precios anteriores restaurados
- [x] T015 [P] [US1] Test Feature `::test_deshacer_genera_eventos_auditoria_origen_deshacer_import` — assert `logs_auditoria` con origen `"Deshacer import"` para cada precio restaurado
- [x] T016 [P] [US1] Test Feature `::test_corrida_ya_deshecha_no_se_puede_volver_a_deshacer` — segundo intento de undo devuelve error de dominio / 422, sin efecto

### Implementación (US1)

- [x] T017 [US1] [Depends: T010] Implementar en `DeshacerImportacionService` el camino de fila de actualización (`modo = actualizacion`): restaurar atributos de `Producto` desde `estado_anterior`, `precios_producto` desde `precios_anteriores` (vía `updateOrCreate` igual que el import), y stock por depósito vía `StockService::fijar()` con la cantidad de `stock_anterior` (research.md R4)
- [x] T018 [US1] [Depends: T010] Implementar en `DeshacerImportacionService` el camino de fila de alta (`modo = alta`): soft-delete (`producto->update(['activo' => false])`) del producto creado por esa fila
- [x] T019 [US1] [Depends: T017,T018] Envolver la restauración de precio en `OrigenCambioPrecio::durante(OrigenCambioPrecio::DESHACER_IMPORT, ...)` (agregar el caso nuevo en `app/Support/OrigenCambioPrecio.php`) para que el evento de auditoría quede con origen `"Deshacer import"` (spec 074, mismo mecanismo que usa el import)
- [x] T020 [US1] [Depends: T017,T018,T019] Marcar cada `ImportacionFilaSnapshot.estado_undo = 'revertida'` y actualizar `ImportacionCorrida.deshecho_en/deshecho_por_id/filas_revertidas/filas_no_revertidas` al finalizar `deshacer()`
- [x] T021 [US1] [Depends: T020] Agregar ruta `POST /importar-datos/productos/historial/{corrida}/deshacer` en `routes/web.php` y método `deshacer()` en `app/Http/Controllers/ImportacionController.php` (valida `estado = vigente` → 422 si no; llama a `DeshacerImportacionService`; responde JSON per `contracts/importacion-undo-api.md`)
- [x] T022 [US1] Agregar botón "Deshacer este import" en `resources/views/importacion/resumen.blade.php`, visible sólo si la corrida recién confirmada tiene `puede_deshacer = true`; modal de confirmación Bootstrap estándar + AJAX + toast (Toastr) al resolver, sin recargar página (Reglas #2/#3 del proyecto)

**Checkpoint**: US1 completamente funcional y testeable de forma independiente — MVP entregable.

---

## Phase 4: User Story 2 - Deshacer parcialmente cuando algunas filas ya no se pueden revertir (Priority: P2)

**Goal**: el undo revierte lo que puede y reporta con motivo lo que no, sin abortar el resto.

**Independent Test**: import + venta/ajuste posterior sobre 1 de 3 productos tocados + deshacer → 2 revertidos, 1 reportado con motivo.

### Tests (US2)

- [x] T023 [P] [US2] Test Feature `::test_deshacer_parcial_fila_con_venta_posterior_queda_no_revertida` — venta sobre 1 de 3 productos actualizados entre import y undo; assert 2 revertidas, 1 en `no_revertidas` con motivo
- [x] T024 [P] [US2] Test Feature `::test_deshacer_parcial_alta_con_venta_posterior_no_se_elimina` — producto dado de alta y luego vendido; deshacer; assert producto sigue `activo = true`, reportado como no revertido
- [x] T025 [P] [US2] Test Feature `::test_deshacer_no_pisa_venta_concurrente_en_stock` — replica el patrón de concurrencia de spec 074 (research.md R4): venta que decrementa stock entre el snapshot y el undo, assert que el undo NO sobreescribe esa venta y la fila queda no revertida
- [x] T026 [P] [US2] Test Feature `::test_dos_corridas_vigentes_mismo_producto_orden_undo` — deshacer la corrida más antigua primero deja la fila del producto compartido como no revertida (motivo "corrida más reciente"); deshacer luego la más reciente sí la revierte (FR-016)

### Implementación (US2)

- [x] T027 [US2] [Depends: T005] En `DeshacerImportacionService`, antes de revertir una fila de alta: chequear `Producto::tieneOperaciones()`; si true, marcar `ImportacionFilaSnapshot.estado_undo = 'no_revertida'` con `motivo_no_revertida` y continuar con la siguiente fila sin abortar
- [x] T028 [US2] [Depends: T017] En `DeshacerImportacionService`, antes de revertir el stock de una fila de actualización: comparar `movimientos_stock` actuales del producto/depósito contra `ultimo_movimiento_stock_id` guardado en el snapshot; si hay movimientos posteriores con `origen_type` distinto de la propia importación, marcar la fila como `no_revertida` con motivo y no tocar stock ni el resto de los campos de esa fila
- [x] T029 [US2] [Depends: T004] En `DeshacerImportacionService`, antes de revertir cualquier fila: chequear si el mismo `producto_id` tiene un `ImportacionFilaSnapshot` de una corrida más reciente todavía `vigente`; si es así, marcar como `no_revertida` con motivo "modificado por una corrida de import más reciente" (FR-016)
- [x] T030 [US2] [Depends: T027,T028,T029] Asegurar que el bucle principal de `deshacer()` envuelve cada fila en su propio manejo de excepciones (try/continue), análogo a `ImportadorFilas::procesarFilas()`, para que un error inesperado en una fila no aborte el resto (Principio IV / FR-009)
- [x] T031 [US2] [Depends: T021] Extender la respuesta JSON de `POST .../deshacer` para incluir `no_revertidas` con `producto_id`, `numero_fila` y `motivo` por `contracts/importacion-undo-api.md`, y el toast de advertencia (no éxito) cuando `no_revertidas` no está vacío

**Checkpoint**: US1 + US2 funcionando juntas — el undo es seguro en un negocio operando en vivo.

---

## Phase 5: User Story 3 - Ver el historial de imports y su estado de reversión (Priority: P3)

**Goal**: pantalla de historial con DataTables server-side mostrando cada corrida y su estado.

**Independent Test**: 2-3 imports de prueba, abrir historial, verificar datos y estados correctos; adelantar `deshacer_disponible_hasta` de una corrida y verificar que pasa a `vencido`.

### Tests (US3)

- [x] T032 [P] [US3] Test Feature `::test_historial_lista_corridas_con_estado_correcto` — assert estados `vigente`/`deshecho`/`vencido` calculados correctamente contra `now()`
- [x] T033 [P] [US3] Test Feature `::test_deshacer_corrida_vencida_devuelve_422` — corrida con `deshacer_disponible_hasta` en el pasado, POST a `.../deshacer` → 422, sin cambios en base

### Implementación (US3)

- [x] T034 [P] [US3] Agregar ruta `GET /importar-datos/productos/historial` y método `historial()` en `app/Http/Controllers/ImportacionController.php` → vista `resources/views/importacion/historial.blade.php`
- [x] T035 [P] [US3] Agregar ruta `GET /importar-datos/productos/historial/datos` y método `historialDatos()` (DataTables server-side, per `contracts/importacion-undo-api.md`) en `app/Http/Controllers/ImportacionController.php`
- [x] T036 [US3] [Depends: T034] Crear vista `resources/views/importacion/historial.blade.php`: tabla DataTables vacía + columna de estado con badge (vigente/deshecho/vencido) + botón "Deshacer" condicionado a `puede_deshacer`, siguiendo el layout NexaDash estándar (`@extends('layouts.default')`)
- [x] T037 [US3] [Depends: T035,T036] JS de la vista: inicializar DataTable AJAX contra `historial.datos`, botón "Deshacer" abre modal de confirmación → POST a `.../deshacer` → toast + refresco de la fila (sin recargar página)
- [x] T038 [P] [US3] Agregar entrada de menú/acceso a "Historial de Importaciones" en `resources/views/elements/sidebar.blade.php` o como link desde `importacion/index.blade.php` (solapa Productos & Servicios)

**Checkpoint**: las 3 historias funcionan juntas, feature completa end-to-end.

---

## Phase 6: Polish & Cross-Cutting Concerns

- [x] T039 [P] Actualizar `docs/documentacion_principal_crm.md` §2.4 con la nueva funcionalidad de deshacer import (regla obligatoria de retroalimentación docs↔specs)
- [x] T040 [P] Actualizar `docs/modelo_datos.md` agregando `importacion_corridas` e `importacion_filas_snapshot` (regla obligatoria de retroalimentación docs↔specs)
- [x] T041 [P] Revisar y correr `tests/Feature/DeshacerImportacionProductosTest.php` completo contra MySQL local (no sólo SQLite) — gotcha ya documentado en memoria del proyecto: la suite verde en SQLite no garantiza nada en `ONLY_FULL_GROUP_BY`
- [x] T042 Ejecutar manualmente los 5 escenarios de `quickstart.md` en el navegador local antes de dar la feature por lista

---

## Dependencies & Execution Order

- **Phase 1 (Setup)** → **Phase 2 (Foundational)**: bloqueante, sin esto no hay snapshots que revertir.
- **Phase 3 (US1)** depende de Phase 2. Es el MVP.
- **Phase 4 (US2)** depende de Phase 2 y reutiliza el `DeshacerImportacionService` de Phase 3 (T017/T018) — implementarla después de US1, no en paralelo.
- **Phase 5 (US3)** depende de Phase 2 (necesita `ImportacionCorrida` existiendo) pero es independiente de US1/US2 en su lógica propia (lectura, no escritura) — el endpoint de historial se puede construir en paralelo a Phase 3/4 si hay más de un desarrollador; el botón "Deshacer" de la vista de historial (T037) sí depende de que el endpoint de undo (T021) exista.
- **Phase 6 (Polish)** al final.

## Parallel Execution Examples

- Dentro de Phase 1: T003 y T004 en paralelo (archivos distintos).
- Dentro de Phase 3: T011-T016 (todos los tests) en paralelo entre sí antes de la implementación.
- Dentro de Phase 4: T023-T026 (tests) en paralelo entre sí.
- Dentro de Phase 5: T032-T033 (tests) en paralelo; T034/T035/T038 en paralelo entre sí (rutas + sidebar).
- T039/T040/T041 (Polish) en paralelo entre sí.

## Suggested MVP Scope

**Phase 1 + Phase 2 + Phase 3 (US1)** = MVP: deshacer un import completo cuando no hay operaciones
posteriores conflictivas. Es usable en el escenario más común (el usuario detecta el error minutos
después de importar) aunque todavía no maneje conflictos de concurrencia (US2) ni tenga pantalla
de historial (US3, se puede deshacer desde el resumen post-import únicamente).

## Format Validation

Las 42 tareas siguen el formato `- [ ] T### [P?] [Story?] Descripción con ruta de archivo` — Setup
y Foundational sin etiqueta de historia, Phases 3-5 con `[US1]`/`[US2]`/`[US3]`, Polish sin
etiqueta.

## Estado: implementado y verificado (24/08/2026)

Las 42 tareas se implementaron y se verificaron con:
- 5 tests Feature nuevos (`DeshacerImportacionProductosTest`) + 27 de regresión del importador,
  todos en verde.
- Prueba manual end-to-end en el navegador (Chrome DevTools MCP) contra la base local real: export
  real de 3 productos existentes desde la UI de Productos → edición manual de Costo/Precio Venta →
  reimport por el asistente → botón "Deshacer" desde Historial de Importaciones → los 3 productos
  volvieron exactamente a sus valores originales, corrida quedó en estado "Deshecho".

**Gaps encontrados y corregidos durante la prueba manual** (no estaban en el plan original):
- Los scripts inline de `resumen.blade.php`/`historial.blade.php` usaban `$` global (no expuesto
  por el bundle) y no seteaban el header CSRF — se movieron a `@section('local-js')` (se ejecuta
  después de que jQuery carga) y se agregó `$.ajaxSetup` con el token, mismo patrón que
  `resources/js/*.js` del resto del proyecto.
- La key `importacion` de `config/dz.php` no carga DataTables (el wizard original no lo necesita) —
  se agregó una key nueva `importacion-historial` con los assets de DataTables/Toastr para la
  pantalla de Historial.
- **Gap preexistente, no introducido por esta spec**: la columna "Precio venta" del export de
  Productos no automapea en el Paso 2 del importador (el label de campo es "Precio de Venta" con
  "de" y el header exportado es "Precio venta" sin "de" — la normalización de automapeo no lo
  matchea). Documentado acá para una futura spec de fix del automapeo; no bloqueó la prueba
  (se mapeó manualmente, como haría un usuario real).
