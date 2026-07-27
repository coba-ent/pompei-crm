---
description: "Task list — Importar Datos por Excel"
---

# Tasks: Importar Datos por Excel

**Input**: Design documents from `specs/006-importar-datos-excel/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/importacion-rutas.md, quickstart.md

**Tests**: Se incluyen tests para la validación por fila (CUIT, campos económicos de Producto) y la
atomicidad "por fila, no por archivo" (Principio IV de la constitución). La resolución de FK por
nombre (Proveedor/Categoría/Condición de IVA/Tipo de Producto) lleva un test representativo
(Proveedor, por ser la única con acceptance scenario explícito) en vez de uno por cada campo FK.

**Diseño obligatorio** (CLAUDE.md): única pantalla de la app con navegación por páginas reales entre
pasos (excepción documentada) · reutiliza `maatwebsite/excel` ya instalado, sin librerías nuevas ·
reutiliza `ReglasCliente`/`ReglasProveedor`/`ReglasProducto` existentes, sin duplicar validación.

**Reuso**: `DefinicionCamposImportables` centraliza el diccionario de campos por entidad;
`ImportadorFilas` aplica el mapeo fila por fila reutilizando esas definiciones — evita 3
controladores/servicios duplicados para la misma mecánica.

**Organización**: por user story, en orden de prioridad, para entrega incremental.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: puede correr en paralelo (archivos distintos, sin dependencias pendientes)
- **[Story]**: US1..US3 (mapea a las historias de spec.md)

---

## Phase 1: Setup

**Purpose**: confirmar que la dependencia ya instalada resuelve, y preparar el disco de archivos
temporales.

- [X] T001 Confirmar que `maatwebsite/excel` resuelve (`php artisan tinker --execute="echo class_exists('Maatwebsite\Excel\Facades\Excel');"`)
  y, si el proveedor de servicio no está publicado/registrado, publicarlo (`php artisan vendor:publish --provider="Maatwebsite\Excel\ExcelServiceProvider"` si hiciera falta)
- [X] T002 [P] Configurar el disco `local` (`storage/app/private/imports/`) — crear el directorio si
  no existe y confirmar que está fuera del disco `public` (no debe ser accesible por URL directa)

**Checkpoint**: `Excel::toArray()` puede invocarse desde un controlador de prueba sin error.

---

## Phase 2: Foundational (Prerrequisitos bloqueantes)

**Purpose**: el diccionario de campos por entidad y el controlador base del asistente — ninguna
historia puede empezar hasta terminar esta fase.

**⚠️ CRITICAL**: bloquea las 3 user stories.

- [X] T003 [P] `App\Services\Import\DefinicionCamposImportables` — clase con un método por entidad
  (`clientes()`, `proveedores()`, `productos()`) que devuelve el diccionario de campos destino
  (clave, etiqueta, si es obligatorio, si es FK-por-nombre, regla de validación) según data-model.md
  en `app/Services/Import/DefinicionCamposImportables.php`
- [X] T004 [P] `App\Http\Requests\SubirArchivoImportacionRequest` (valida `archivo`:
  `mimes:xls,xlsx,csv`, `max:10240`) en `app/Http/Requests/SubirArchivoImportacionRequest.php`
- [X] T005 `App\Http\Controllers\ImportacionController` — esqueleto (`index`, `subir`, `mapear`,
  `confirmar`, `cancelar`, `resumen`), validando `{entidad}` contra
  `clientes|proveedores|productos` (404 si no matchea) en
  `app/Http/Controllers/ImportacionController.php` (depende de T003, T004)
- [X] T006 Registrar las rutas de Importar Datos en `routes/web.php` según
  `contracts/importacion-rutas.md` (depende de T005)
- [X] T007 [P] Vista shell `resources/views/importacion/index.blade.php` extendiendo
  `layouts.default`: solapas Clientes/Proveedores/Productos & Servicios + "Seleccionar Archivo" +
  paneles "Acerca de la importación"/"Notas Técnicas" (vacíos)

**Checkpoint**: `/importar-datos/clientes` carga sin errores, ruteo resuelto, diccionario de campos
listo para las 3 historias.

---

## Phase 3: User Story 1 - Importar Clientes desde un archivo Excel (Priority: P1) 🎯 MVP

**Goal**: mecanismo completo (subir → vista previa + mapeo → confirmar/cancelar → resumen)
funcionando de punta a punta para Clientes.

**Independent Test**: subir un archivo de clientes de prueba, mapear columnas (incluyendo un campo
personalizado), confirmar, y verificar que aparecen en `/clientes` con los datos correctos.

### Tests for User Story 1 ⚠️

- [X] T008 [P] [US1] Feature test: subir un archivo válido de clientes devuelve la vista previa con
  las columnas detectadas; un archivo de más de 10MB o con extensión no soportada se rechaza antes
  de procesar (FR-002, FR-003) en `tests/Feature/ImportacionDatosTest.php`
- [X] T009 [P] [US1] Feature test: confirmar un mapeo válido crea un cliente por fila válida,
  incluyendo el mapeo a campo personalizado; una fila con CUIT matemáticamente inválido se omite
  reportada en el resumen sin abortar las demás filas (FR-004, FR-006, Principio IV) en
  `tests/Feature/ImportacionDatosTest.php`
- [X] T010 [P] [US1] Feature test: confirmar sin mapear el campo obligatorio (Nombre), o con dos
  columnas mapeadas al mismo campo destino, rechaza antes de crear ningún registro (FR-005) en
  `tests/Feature/ImportacionDatosTest.php`
- [X] T011 [P] [US1] Feature test: cancelar el asistente antes de confirmar no crea ningún cliente y
  borra el archivo temporal (FR-007) en `tests/Feature/ImportacionDatosTest.php`

### Implementation for User Story 1

- [X] T012 [US1] `ImportacionController@subir` — guarda el archivo en
  `storage/app/private/imports/{uuid}.{ext}` (research.md §2), lee las primeras filas con
  `Excel::toArray()`, guarda la referencia en sesión, redirige a `mapear` (depende de T005, T006;
  cubre T008)
- [X] T013 [US1] `ImportacionController@mapear` — recupera la vista previa de sesión, arma las
  opciones de campo destino desde `DefinicionCamposImportables` para `{entidad}` (depende de T012)
- [X] T014 [US1] `App\Services\Import\ImportadorFilas` — aplica el mapeo recibido fila por fila:
  valida contra `ReglasCliente`/`ReglasProveedor`/`ReglasProducto` según `{entidad}`, arma el array
  de `campos_personalizados` para las columnas mapeadas como tal, crea el registro si es válido,
  acumula fallidos/advertencias — todo dentro de una transacción corta por fila (research.md §4) en
  `app/Services/Import/ImportadorFilas.php` (depende de T003; cubre T009)
- [X] T015 [US1] `ImportacionController@confirmar` — valida el mapeo contra FR-005 (campo
  obligatorio mapeado, sin duplicados) antes de invocar `ImportadorFilas`; si el mapeo no pasa,
  vuelve a `mapear` con el error; si pasa, ejecuta la importación, borra el archivo temporal, guarda
  el resultado en sesión, redirige a `resumen` (depende de T014; cubre T010)
- [X] T016 [US1] `ImportacionController@cancelar` — borra el archivo temporal y la referencia de
  sesión sin tocar la base de datos, redirige a `index` (depende de T012; cubre T011)
- [X] T017 [US1] `ImportacionController@resumen` (vista con el resultado: importados/fallidos con
  motivo/advertencias) en `app/Http/Controllers/ImportacionController.php` (depende de T015)
- [X] T018 [US1] `resources/views/importacion/mapear.blade.php` (vista previa de filas + select de
  campo destino por columna, con la opción "Campo personalizado" mostrando un input de nombre) —
  completa T013
- [X] T019 [US1] `resources/views/importacion/resumen.blade.php` (resultado + botón para volver al
  listado de `{entidad}`) — completa T017
- [X] T020 [US1] En `resources/views/clientes/index.blade.php`, agregar el botón "Importar datos"
  apuntando a `importacion.index` con `entidad=clientes` (no existe hoy — verificado en plan.md)

**Checkpoint**: Importar Clientes funciona de punta a punta, independiente de Proveedores y
Productos.

---

## Phase 4: User Story 2 - Importar Proveedores desde un archivo Excel (Priority: P2)

**Goal**: mismo mecanismo de US1, aplicado a la solapa Proveedores.

**Independent Test**: subir un archivo de proveedores de prueba desde la solapa Proveedores,
mapear, confirmar, y verificar que aparecen en `/proveedores` — sin depender de Clientes ni de
Productos.

### Implementation for User Story 2

- [X] T021 [P] [US2] Feature test: confirmar un mapeo válido de Proveedores crea un proveedor por
  fila válida, con las diferencias de campos ya vigentes (Categoría Compras, Nota Interna, sin
  Apodo ML) en `tests/Feature/ImportacionDatosTest.php` (depende de T003, T014)
- [X] T022 [US2] Verificar que `DefinicionCamposImportables::proveedores()` (ya definida en T003)
  alimenta correctamente el paso de mapeo (`ImportacionController@mapear`) y la creación de filas
  (`ImportadorFilas`) para `entidad=proveedores`, sin requerir cambios de controller/servicio
  adicionales — ajustar sólo si el reuso no es 1 a 1 (depende de T013, T014, T021)
- [X] T023 [US2] En `resources/views/proveedores/index.blade.php`, agregar el botón "Importar datos"
  apuntando a `importacion.index` con `entidad=proveedores`

**Checkpoint**: Importar Proveedores funciona de punta a punta, reutilizando el mecanismo de US1 sin
tocar `ImportacionController`/`ImportadorFilas`.

---

## Phase 5: User Story 3 - Importar Productos & Servicios, asociados a Proveedor (Priority: P3)

**Goal**: mismo mecanismo, con la regla adicional de resolver "Proveedor" (y demás FKs) por nombre.

**Independent Test**: con al menos un proveedor ya cargado, subir un archivo de productos con
columna "Proveedor", confirmar, y verificar que los productos quedan asociados correctamente.

### Tests for User Story 3 ⚠️

- [X] T024 [P] [US3] Feature test: una fila de Productos con "Proveedor" que coincide (sin distinguir
  mayúsculas/acentos) con un proveedor existente queda asociada a ese `proveedor_id`; una fila con un
  valor que no matchea ningún proveedor se crea igual, sin proveedor asociado, reportada como
  advertencia (no como fallo) (FR-009, research.md §3) en `tests/Feature/ImportacionDatosTest.php`
- [X] T025 [P] [US3] Feature test: una fila sin la columna "Tipo" mapeada, o con la celda vacía, crea
  el producto con `tipo = producto` por defecto (FR-010) en `tests/Feature/ImportacionDatosTest.php`

### Implementation for User Story 3

- [X] T026 [US3] En `ImportadorFilas`, implementar la resolución de campos FK-por-nombre
  (Proveedor, Categoría, Condición de IVA, Tipo de Producto): normaliza el valor de la celda
  (minúsculas + sin acentos), busca el registro existente por `nombre`, deja `null` si no hay
  coincidencia y agrega una advertencia a la fila en vez de marcarla fallida (depende de T014; cubre
  T024)
- [X] T027 [US3] En `DefinicionCamposImportables::productos()`, marcar `tipo` con default
  `'producto'` cuando la columna no viene mapeada o la celda está vacía (depende de T003; cubre T025)
- [X] T028 [US3] En `resources/views/importacion/index.blade.php`, agregar el texto de "Notas
  Técnicas" recomendando importar primero Proveedores cuando la solapa activa es Productos &
  Servicios (FR-011) — completa T007
- [X] T029 [US3] En `resources/views/productos/index.blade.php`, agregar el botón "Importar datos"
  apuntando a `importacion.index` con `entidad=productos`

**Checkpoint**: las 3 user stories funcionan de punta a punta e independientemente entre sí (US3 sólo
necesita que existan proveedores si se quiere probar la asociación, no bloquea el resto del flujo).

---

## Phase 6: Polish & Cross-Cutting Concerns

**Purpose**: consistencia de documentación y validación final.

- [X] T030 [P] Documentar "Importar Datos" como sección activa nueva en
  `docs/documentacion_principal_crm.md`, quitando la mención de brecha pendiente en Clientes/
  Proveedores/Productos (Principio I de la constitución)
- [X] T031 Ejecutar la validación de `specs/006-importar-datos-excel/quickstart.md` de punta a punta
  (los 11 escenarios)
- [X] T032 Correr `php artisan test --filter=ImportacionDatos` y dejar la suite en verde
- [X] T033 `npm run build` final (si hubo cambios de JS en los botones agregados) y
  `php artisan route:list` para confirmar que todas las rutas de
  `contracts/importacion-rutas.md` están registradas sin errores

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Fase 1)**: sin dependencias.
- **Foundational (Fase 2)**: depende de Setup — BLOQUEA las 3 historias.
- **User Stories (Fases 3-5)**: dependen de Foundational.
  - US1 (P1) primero — construye el mecanismo completo (subir/mapear/confirmar/cancelar/resumen).
  - US2 (P2) depende de que `DefinicionCamposImportables::proveedores()` exista (T003, dentro de
    Foundational) y del mecanismo de US1 (T012-T017) — no agrega controlador/servicio nuevo, sólo un
    botón de entrada y un test.
  - US3 (P3) depende del mecanismo de US1 y agrega la resolución de FK-por-nombre sobre
    `ImportadorFilas` ya construido en US1.
- **Polish (Fase 6)**: al final.

### Paralelizables

- Setup: T001, T002 en paralelo.
- Foundational: T003, T004, T007 en paralelo (T005/T006 secuenciales, dependen de T003/T004).
- Tests de US1 (T008-T011) en paralelo entre sí.
- Tests de US3 (T024, T025) en paralelo entre sí.
- T030 (documentación) en paralelo con T031-T033.

---

## Parallel Example: User Story 1

```bash
# Tests de User Story 1 en paralelo:
Task: "Feature test subir archivo + vista previa en tests/Feature/ImportacionDatosTest.php"
Task: "Feature test confirmar mapeo + fila inválida no aborta el resto en tests/Feature/ImportacionDatosTest.php"
Task: "Feature test rechazo de mapeo incompleto/duplicado en tests/Feature/ImportacionDatosTest.php"
Task: "Feature test cancelar sin dejar registros en tests/Feature/ImportacionDatosTest.php"
```

---

## Implementation Strategy

### MVP (recomendado)

1. Fase 1 (Setup) → Fase 2 (Foundational) → Fase 3 (US1).
2. **STOP y validar**: se puede importar un archivo de Clientes de punta a punta, con mapeo, campos
   personalizados, filas inválidas reportadas, y cancelación limpia. Demo.

### Incremental

- + US2 (Proveedores) → + US3 (Productos & Servicios con resolución de Proveedor). Cada historia
  agrega valor sin tocar el mecanismo central construido en US1.

---

## Notes

- [P] = archivos distintos, sin dependencias pendientes.
- Verificar que T008, T009, T010, T011, T021, T024 y T025 fallen antes de implementar la lógica que
  prueban (TDD en lo crítico: validación de CUIT/económicos, atomicidad por fila, Principio IV).
- Commit por task o grupo lógico; parar en cada checkpoint para validar la historia.
- Respetar SIEMPRE: esta es la única pantalla con navegación por páginas reales entre pasos —no
  convertir el asistente en un modal ni en AJAX puro, sería divergir de la estructura relevada.
- No agregar ninguna librería de Excel/CSV nueva — `maatwebsite/excel` ya está instalado
  (research.md §1).
