---

description: "Task list — Base de Datos — Clientes"
---

# Tasks: Base de Datos — Clientes

**Input**: Design documents from `specs/001-clientes/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/clientes-rutas.md

**Tests**: Se incluyen tasks de test SÓLO para la lógica con impacto fiscal/dinero (validación de
CUIT, unicidad, "apto para facturar", regla de no-eliminación), según Principio IV de la constitución.
El CRUD trivial de campos no fiscales no lleva test obligatorio.

**Diseño obligatorio** (CLAUDE.md): DataTable AJAX server-side · alta/edición/baja en modales
Bootstrap por AJAX (sin recargar) · notificaciones con toasts de Toastr.

**Organización**: por user story, en orden de prioridad, para entrega incremental.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: puede correr en paralelo (archivos distintos, sin dependencias pendientes)
- **[Story]**: US1..US6 (mapea a las historias de spec.md)

---

## Phase 1: Setup (Infraestructura compartida)

**Purpose**: dependencias y andamiaje de assets/UI para todo el módulo.

- [X] T001 Instalar `yajra/laravel-datatables-oracle` vía composer (server-side DataTables) y verificar autodescubrimiento del provider
- [X] T002 [P] Crear el entry JS `resources/js/clientes.js` y registrarlo en `vite.config.js` (input) para que Vite lo compile
- [X] T003 [P] Agregar la entrada de página `clientes` en `config/dz.php` (pagelevel) cargando los assets del template: DataTables (css/js + responsive), Toastr (css/js) y bootstrap-select si se usa en el modal
- [X] T004 [P] Inicializar Toastr con una configuración global reutilizable (posición, timeouts) en `resources/js/clientes.js` o un helper JS compartido

**Checkpoint**: Vite compila; DataTables y Toastr disponibles en la página de clientes.

---

## Phase 2: Foundational (Prerrequisitos bloqueantes)

**Purpose**: esquema de datos, modelos base, ruteo y shell de la vista. NINGUNA historia puede
empezar hasta terminar esta fase.

**⚠️ CRITICAL**: bloquea todas las user stories.

### Base de datos y modelos de soporte

- [X] T005 [P] Migración `create_condiciones_iva_table` (id, nombre, codigo_afip nullable, requiere_cuit bool) en `database/migrations/`
- [X] T006 [P] Migración `create_categorias_table` (id, tipo enum venta/compra/producto/gasto, categoria_padre_id nullable, nombre) en `database/migrations/`
- [X] T007 [P] Migración `create_listas_precio_table` (id, nombre, activo) en `database/migrations/`
- [X] T008 Migración `create_clientes_table` con todos los campos del data-model, FKs a condiciones_iva/categorias/listas_precio, `unique(cuit)`, índices (nombre, cuit, activo, categoria_id), `campos_personalizados` json, `activo` default true, timestamps (depende de T005-T007)
- [X] T009 [P] Modelo `App\Models\CondicionIva` (fillable, relación hasMany clientes) en `app/Models/CondicionIva.php`
- [X] T010 [P] Modelo `App\Models\Categoria` (fillable, scope tipo=venta) en `app/Models/Categoria.php`
- [X] T011 [P] Modelo `App\Models\ListaPrecio` en `app/Models/ListaPrecio.php`
- [X] T012 Modelo `App\Models\Cliente` (fillable, casts: campos_personalizados=array, saldo_inicial/descuento decimal, activo bool; relaciones condicionIva/categoria/listaPrecio) en `app/Models/Cliente.php` (depende de T009-T011)
- [X] T013 [P] Seeder `CondicionIvaSeeder` con el catálogo fijo (Responsable Inscripto, Monotributista, Consumidor Final, Exento, No Categorizado) + codigo_afip + requiere_cuit; registrarlo en `database/seeders/DatabaseSeeder.php`

### Ruteo, verificador ARCA y shell de vista

- [X] T014 Regla de validación `App\Rules\CuitValido` (11 dígitos + prefijo + DV módulo 11) en `app/Rules/CuitValido.php`
- [X] T015 [P] Contrato `App\Services\Arca\VerificadorCuit` + implementación provisoria `VerificadorCuitStub` (valida formato, devuelve null); binding en un service provider en `app/Services/Arca/`
- [X] T016 `ClienteController` (resource parcial + data/estado/verificarCuit) esqueleto con métodos vacíos en `app/Http/Controllers/ClienteController.php`
- [X] T017 Registrar rutas de clientes en `routes/web.php` según `contracts/clientes-rutas.md` (index, data, store, show, update, destroy, estado, verificar-cuit)
- [X] T018 [P] Actualizar el submenú "Base de Datos → Clientes" del sidebar (`resources/views/elements/sidebar.blade.php`) para apuntar a `clientes.index`
- [X] T019 Vista shell `resources/views/clientes/index.blade.php` extendiendo `layouts.default`: contenedor de la DataTable + botón "Nuevo Cliente" + include del modal (aún vacío) + `@section('local-js')` cargando `clientes.js`

**Checkpoint**: `/clientes` carga sin errores, migraciones + seed corren, ruteo resuelto.

---

## Phase 3: User Story 1 — Alta de cliente básico (Priority: P1) 🎯 MVP

**Goal**: dar de alta y editar un cliente (mínimo: nombre) desde un modal AJAX, con toast de
resultado, sin recargar.

**Independent Test**: crear un cliente sólo con nombre desde el modal → aparece en la tabla; intentar
guardar sin nombre → toast/errores de validación en el modal.

### Tests for User Story 1

- [X] T020 [P] [US1] Feature test: crear cliente con nombre válido responde 200 JSON y persiste; crear sin nombre responde 422 con error en `nombre`, en `tests/Feature/ClienteAltaTest.php`

### Implementation for User Story 1

- [X] T021 [US1] `StoreClienteRequest` con reglas del contrato (nombre required; email/campos opcionales) en `app/Http/Requests/StoreClienteRequest.php`
- [X] T022 [US1] `UpdateClienteRequest` (reglas de update, unique cuit ignorando el propio) en `app/Http/Requests/UpdateClienteRequest.php`
- [X] T023 [US1] Implementar `ClienteController@store` (valida, crea, responde JSON `{ok, mensaje, cliente}` / 422) en `app/Http/Controllers/ClienteController.php`
- [X] T024 [US1] Implementar `ClienteController@show` (JSON del cliente para precargar edición) y `@update` (JSON) en `app/Http/Controllers/ClienteController.php`
- [X] T025 [US1] Modal de alta/edición `resources/views/clientes/_modal_form.blade.php` con los campos básicos (nombre, apellido, contacto, domicilio) y estructura para las secciones de US2/US5/US6
- [X] T026 [US1] En `resources/js/clientes.js`: abrir modal (nuevo/editar), submit AJAX de store/update, mostrar errores 422 en el form, cerrar modal + recargar DataTable + toast de éxito

**Checkpoint**: alta y edición básicas funcionando por modal AJAX con toasts, sin recargar.

---

## Phase 4: User Story 2 — Datos de facturación / apto para facturar (Priority: P1)

**Goal**: cargar CUIT (con "Verificar"), condición de IVA y tipo de comprobante; regla "apto para
facturar" obligando condición de IVA.

**Independent Test**: cargar CUIT válido + condición IVA + tipo comprobante → cliente "apto"; quitar
condición IVA → deja de ser apto y el sistema lo indica; CUIT con DV inválido → rechazado.

### Tests for User Story 2

- [X] T027 [P] [US2] Unit test `CuitValidoTest` (acepta CUIT válido, rechaza DV/longitud/prefijo inválidos) en `tests/Unit/CuitValidoTest.php`
- [X] T028 [P] [US2] Unit test `ClienteAptoFacturarTest` (apto sólo con condición IVA; RI/Monotributo exigen CUIT) en `tests/Unit/ClienteAptoFacturarTest.php`
- [X] T029 [P] [US2] Feature test: unicidad de CUIT (rechaza duplicado presente, permite varios sin CUIT) y verificar-cuit devuelve estructura esperada, en `tests/Feature/ClienteFacturacionTest.php`

### Implementation for User Story 2

- [X] T030 [US2] Método `Cliente::esAptoParaFacturar()` y `Cliente::requiereCuitParaFacturar()` según condición de IVA, en `app/Models/Cliente.php`
- [X] T031 [US2] Aplicar regla `CuitValido` + unique(ignorando propio/NULL) en Store/UpdateClienteRequest; validar `condicion_iva_id`, `tipo_comprobante_defecto` in A,B,C,E
- [X] T032 [US2] Implementar `ClienteController@verificarCuit` usando `VerificadorCuit` (JSON valido/datos/null, resiliente a falla) en `app/Http/Controllers/ClienteController.php`
- [X] T033 [US2] Sección "Datos de Facturación" en `_modal_form.blade.php` (razón social, N° Doc/CUIT + botón Verificar, condición IVA select, tipo comprobante, domicilio fiscal) + indicador "apto para facturar"
- [X] T034 [US2] En `resources/js/clientes.js`: acción del botón "Verificar" (fetch a verificar-cuit, autocompletar si hay datos, toast si CUIT inválido, no bloquear si ARCA no responde)

**Checkpoint**: cliente puede quedar "apto para facturar"; nunca se habilita sin condición de IVA.

---

## Phase 5: User Story 3 — Listar, buscar y filtrar (Priority: P2)

**Goal**: DataTable server-side por AJAX con búsqueda (nombre/CUIT) y filtros (estado, categoría).

**Independent Test**: con varios clientes, buscar por nombre y por CUIT, filtrar por activos/inactivos
y por categoría; verificar resultados correctos y performance en cartera grande.

### Tests for User Story 3

- [X] T035 [P] [US3] Feature test: `clientes.data` devuelve formato DataTables, respeta búsqueda por nombre/CUIT y filtros estado/categoría, en `tests/Feature/ClienteListadoTest.php`

### Implementation for User Story 3

- [X] T036 [US3] Implementar `ClienteController@data` (yajra: columnas, búsqueda global nombre/CUIT, filtros estado/categoria_id, columna acciones + badge apto/activo) en `app/Http/Controllers/ClienteController.php`
- [X] T037 [US3] Parcial `resources/views/clientes/_row_actions.blade.php` (botones editar / inactivar-activar / eliminar por fila)
- [X] T038 [US3] En `resources/js/clientes.js`: inicializar la DataTable responsive server-side apuntando a `clientes.data`, con controles de filtro (estado, categoría) que recarguen la tabla
- [X] T039 [US3] Controles de filtro (estado, categoría) y buscador en `resources/views/clientes/index.blade.php`

**Checkpoint**: listado completo, buscable y filtrable, cargado por AJAX.

---

## Phase 6: User Story 4 — Baja lógica y eliminación (Priority: P2)

**Goal**: inactivar/reactivar (baja lógica) y eliminar físicamente sólo si no hay operaciones.

**Independent Test**: inactivar un cliente (sale de selectores, sigue en filtro inactivos,
reactivable); eliminar cliente sin operaciones (se borra); intentar eliminar con operaciones
(rechazado).

### Tests for User Story 4

- [X] T040 [P] [US4] Feature test: `estado` alterna activo; `destroy` elimina sin operaciones y rechaza (409) con operaciones, en `tests/Feature/ClienteBajaTest.php`

### Implementation for User Story 4

- [X] T041 [US4] Método extensible `Cliente::tieneOperaciones()` (hoy false; costura para módulos futuros) en `app/Models/Cliente.php`
- [X] T042 [US4] Implementar `ClienteController@estado` (toggle activo, JSON) y `@destroy` (chequea tieneOperaciones → 409 o borra → 200) en `app/Http/Controllers/ClienteController.php`
- [X] T043 [US4] En `resources/js/clientes.js`: acciones AJAX de inactivar/activar y eliminar (con confirmación en modal), actualizar fila/tabla + toast según respuesta

**Checkpoint**: baja lógica y eliminación segura funcionando, con trazabilidad preservada.

---

## Phase 7: User Story 5 — Datos comerciales por defecto (Priority: P3)

**Goal**: asignar categoría, lista de precio, descuento general y saldo inicial.

**Independent Test**: asignar lista de precio + descuento + saldo inicial y verificar persistencia.

- [X] T044 [US5] Validación de `descuento_general_pct` (0–100) y `saldo_inicial` numeric en los FormRequests
- [X] T045 [US5] Sección "Ventas" en `_modal_form.blade.php` (Categoría Ventas, Lista de Precios, Descuento General, Nota, + Saldo Inicial plegable) con selects poblados desde categorías/listas
- [X] T046 [US5] Persistir y precargar estos campos en store/update/show del controlador

**Checkpoint**: datos comerciales por defecto guardados y editables.

---

## Phase 8: User Story 6 — Campos personalizados (Priority: P3)

**Goal**: agregar campos a medida (clave/valor) a la ficha del cliente.

**Independent Test**: definir un campo personalizado con valor, guardar y verlo al reabrir la ficha.

- [X] T047 [US6] UI "+ Agregar Nuevo campo" (pares clave/valor dinámicos) en `_modal_form.blade.php` + manejo en `resources/js/clientes.js`
- [X] T048 [US6] Normalizar y persistir `campos_personalizados` (json) en store/update; renderizar en show, en `app/Http/Controllers/ClienteController.php`

**Checkpoint**: campos personalizados persistidos y editables.

---

## Phase 9: Polish & Cross-Cutting

- [X] T049 [P] Actualizar `docs/modelo_datos.md`: agregar el campo `requiere_cuit` a `condiciones_iva` (detectado en el diseño — Principio I)
- [X] T050 [P] Revisar responsividad de la DataTable y del modal en pantallas chicas
- [X] T051 [P] Factory `ClienteFactory` + `ClientesDemoSeeder` (~1.000 clientes) para validar SC-005 (performance del listado), en `database/factories/` y `database/seeders/`
- [X] T052 Ejecutar la validación de `specs/001-clientes/quickstart.md` de punta a punta (incluye la prueba de performance SC-005 con el seeder de demo)
- [X] T053 Correr `php artisan test --filter=Cliente` y dejar toda la suite en verde

---

## Phase 10: Extensión post-captura (formulario real de Contagram)

**Purpose**: tareas ejecutadas DESPUÉS de la implementación inicial, al relevar el formulario real
(`capturas/crea cliente formulario.png`) y el informe de investigación. Cierran los gaps de campos y
agregan personas de contacto. Todas completadas.

- [X] T054 [US7] Migración `add_extra_fields_to_clientes_table` (nombre_pila, apellido, apodo_ml, pagina_web, nota, nota_cliente, razon_social, tipo_documento, domicilio/localidad/provincia/cp/telefono/telefono_celular fiscales) en `database/migrations/`
- [X] T055 [US7] Migración + modelo `cliente_contactos` (1..N, cascade on delete) en `database/migrations/` y `app/Models/ClienteContacto.php`
- [X] T056 [US7] Actualizar `Cliente` (fillable de campos nuevos + relación `contactos()` hasMany) en `app/Models/Cliente.php`
- [X] T057 [US2/US7] Trait `ReglasCliente` con validación de todos los campos nuevos + contactos + CUIT condicional por `tipo_documento` (DV sólo CUIT/CUIL), usado por Store/UpdateClienteRequest en `app/Http/Requests/Concerns/ReglasCliente.php`
- [X] T058 [US7] `ClienteController` store/update/show sincronizan contactos (`sincronizarContactos`) y persisten/precargan los campos nuevos; fix `saldo_inicial` null→0 en `app/Http/Controllers/ClienteController.php`
- [X] T059 [US7] Rehacer `_modal_form.blade.php` con las secciones del formulario real (datos básicos 2 columnas, personas de contacto dinámicas, Ventas, bloque de Datos de Facturación fiscal)
- [X] T060 [US7] `resources/js/clientes.js`: alta/quita dinámica de personas de contacto, toggle de saldo inicial, carga de contactos en edición
- [X] T061 [US7] Tests: DNI no exige DV, persistencia/reemplazo/cascade de contactos, regresión `saldo_inicial` vacío, en `tests/Feature/`
- [X] T062 Actualizar docs de dominio (`docs/modelo_datos.md` tabla `clientes` + `cliente_contactos`; `docs/documentacion_principal_crm.md` §5.1) — Principio I

> **Nota (cards + import/export)**: adicionalmente se agregaron a la vista índice cards informativas
> (endpoint `clientes.stats`), exportación a CSV/Excel (`clientes.export`) y un botón "Importar datos"
> (placeholder — la importación masiva es feature aparte). El fix global de paginación DataTables vive
> en `public/css/contagram-custom.css`.

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Fase 1)**: sin dependencias.
- **Foundational (Fase 2)**: depende de Setup — BLOQUEA todas las historias.
- **User Stories (Fases 3-8)**: dependen de Foundational.
  - US1 (P1) y US2 (P1) primero (MVP fiscal). US2 usa el modal de US1.
  - US3 (P2) usa `data` + acciones; US4 (P2) agrega estado/destroy.
  - US5 y US6 (P3) extienden el modal.
- **Polish (Fase 9)**: al final.

### Dependencias entre historias

- US1: base — modal + store/update. Sin dependencias de otras historias.
- US2: extiende el modal de US1 (sección facturación) y agrega lógica fiscal. Testeable aparte.
- US3: listado AJAX. Independiente (necesita clientes cargados, que US1 provee).
- US4: baja/eliminación. Usa las acciones de fila de US3 pero la lógica es independiente.
- US5, US6: extienden el modal; independientes entre sí.

### Paralelizables

- Setup: T002, T003, T004 en paralelo.
- Foundational: migraciones de soporte T005-T007 en paralelo; modelos T009-T011 en paralelo; T013,
  T015, T018 en paralelo con otras.
- Tests marcados [P] dentro de cada historia corren en paralelo.

---

## Implementation Strategy

### MVP (recomendado)

1. Fase 1 (Setup) → Fase 2 (Foundational) → Fase 3 (US1) → Fase 4 (US2).
2. **STOP y validar**: se puede dar de alta/editar clientes con datos fiscales y marca "apto para
   facturar" — el mínimo que desbloquea Ventas/Facturación. Demo.

### Incremental

- + US3 (listado AJAX) → + US4 (baja/eliminación) → + US5 (comercial) → + US6 (campos custom).
- Cada historia agrega valor sin romper las anteriores.

---

## Notes

- [P] = archivos distintos, sin dependencias pendientes.
- Verificar que los tests fiscales fallen antes de implementar la lógica (TDD en lo crítico).
- Commit por task o grupo lógico; parar en cada checkpoint para validar la historia.
- Respetar SIEMPRE: DataTable AJAX server-side, modales AJAX sin recargar, toasts de Toastr.
