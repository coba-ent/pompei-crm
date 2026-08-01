---

description: "Task list for 026-importador-datos-campos-completos"
---

# Tasks: Importador de Datos — Campos Completos

**Input**: Design documents from `/specs/026-importador-datos-campos-completos/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, quickstart.md

**Tests**: Incluidos — Principio IV de la constitución exige tests para `saldo_inicial` (dinero) y para
los booleanos que afectan visibilidad en Ventas/Compras de Producto.

**Organization**: Tareas agrupadas por historia de usuario (spec.md) para poder implementar y validar
cada una de forma independiente.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Puede ejecutarse en paralelo (archivos distintos, sin dependencias pendientes)
- **[Story]**: A qué historia de usuario pertenece (US1, US2, US3)

## Path Conventions

Single project Laravel — `app/`, `tests/`, `docs/` en la raíz del repo (ver plan.md §Project Structure).

---

## Phase 1: Setup

**Purpose**: no hay inicialización de proyecto nueva — `maatwebsite/excel` y el mecanismo base ya existen
(spec 006). Esta fase queda vacía a propósito.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: agregar las dos capacidades de parseo por fila (fecha, booleano) que TODAS las historias de
usuario necesitan, antes de tocar los diccionarios de campos por entidad.

**⚠️ CRITICAL**: No se puede empezar ninguna historia de usuario sin esta fase completa.

- [ ] T001 En `app/Services/Import/ImportadorFilas.php`, implementar `normalizarFecha(mixed $valor): ?string`
  que acepta fecha nativa de Excel/Carbon, texto `DD/MM/YYYY` y texto `YYYY-MM-DD`, devolviendo `Y-m-d` o
  `null` si no matchea ninguno (research.md §1)
- [ ] T002 En `app/Services/Import/ImportadorFilas.php`, implementar `normalizarBooleano(string $valor): ?bool`
  que acepta `si/no`, `1/0`, `true/false` (sin distinguir mayúsculas/acentos), devolviendo `null` si no
  matchea ninguno (research.md §2)
- [ ] T003 En `mapearFila()` de `app/Services/Import/ImportadorFilas.php`, invocar `normalizarFecha()`
  cuando el campo destino tenga `'fecha' => true` y `normalizarBooleano()` cuando tenga `'booleano' =>
  true`; si el resultado es `null` con celda no vacía, dejar el valor crudo en `$datos` para que la regla
  `date`/`boolean` de `construirReglas()` produzca el motivo de fallo (depende de T001, T002)
- [ ] T004 En `construirReglas()` de `app/Services/Import/ImportadorFilas.php`, agregar `nullable|date`
  para campos marcados `'fecha' => true` y `nullable|boolean` para campos marcados `'booleano' => true`
  que no tengan ya una regla explícita en `Reglas*Importacion`, mismo patrón que `$esNumericoDinamico`
  (data-model.md §Reglas de validación)

**Checkpoint**: las dos capacidades de parseo están listas y cubiertas por sus propios tests unitarios
antes de exponer ningún campo nuevo en el diccionario.

- [ ] T005 [P] Test unitario de `normalizarFecha()` (fecha nativa, `DD/MM/YYYY`, `YYYY-MM-DD`, texto no
  interpretable → `null`) en `tests/Unit/ImportadorFilasParseoTest.php`
- [ ] T006 [P] Test unitario de `normalizarBooleano()` (`si/no`, `1/0`, `true/false`, mayúsculas/acentos,
  valor no reconocido → `null`) en `tests/Unit/ImportadorFilasParseoTest.php`

---

## Phase 3: User Story 1 - Importar Clientes con todos sus datos comerciales y fiscales (Priority: P1) 🎯 MVP

**Goal**: la solapa Clientes ofrece los 16 campos nuevos (fiscal, saldo inicial + fecha, ML, lista de
precios, descuento, nota de ventas, página web) en el paso de mapeo, y los importa correctamente.

**Independent Test**: subir un archivo de Clientes con columnas para cada campo nuevo, mapear, confirmar,
y verificar en `/clientes` que cada cliente creado tiene esos valores — sin depender de Proveedores ni de
Productos.

### Tests for User Story 1 ⚠️

> Escribir estos tests primero y verificar que fallan antes de implementar.

- [ ] T007 [P] [US1] Feature test: importar Clientes mapeando Razón Social, bloque fiscal completo,
  Código Postal, Nota para Ventas, Descuento General, Usuario de ML y Página Web → valores persistidos
  correctamente, en `tests/Feature/ImportacionDatosTest.php`
- [ ] T008 [P] [US1] Feature test: columna "Saldo Inicial" en formato numérico argentino + columna "Fecha
  de Saldo Inicial" en los 3 formatos aceptados (fecha nativa, `DD/MM/YYYY`, `YYYY-MM-DD`) → cliente
  creado con `saldo_inicial`/`saldo_inicial_fecha` correctos; una fila con fecha no interpretable → fila
  fallida con motivo, en `tests/Feature/ImportacionDatosTest.php`
- [ ] T009 [P] [US1] Feature test: columna "Lista de Precios" con valor coincidente → cliente asociado a
  esa lista; valor sin coincidencia → cliente creado igual, reportado como advertencia (no fallo), en
  `tests/Feature/ImportacionDatosTest.php`

### Implementation for User Story 1

- [ ] T010 [US1] En `DefinicionCamposImportables::clientes()`, agregar los campos de texto simple: Razón
  Social (`razon_social`), Tipo de Documento (`tipo_documento`, sin catálogo — research.md §4),
  Domicilio/Localidad/Provincia/CP Fiscal (`domicilio_fiscal`, `localidad_fiscal`, `provincia_fiscal`,
  `cp_fiscal`), Teléfono Fiscal (`telefono_fiscal`), Teléfono Celular Fiscal
  (`telefono_celular_fiscal`), Código Postal (`cp`), Nota para Ventas (`nota_cliente`), Usuario de
  Mercado Libre (`apodo_ml`), Página Web (`pagina_web`) (depende de T001-T004)
- [ ] T011 [US1] En `DefinicionCamposImportables::clientes()`, agregar Saldo Inicial (`saldo_inicial`,
  `'numerico' => true`), Fecha de Saldo Inicial (`saldo_inicial_fecha`, `'fecha' => true`) y Descuento
  General (`descuento_general_pct`, `'numerico' => true`) (depende de T001-T004)
- [ ] T012 [US1] En `DefinicionCamposImportables::clientes()`, agregar Lista de Precios
  (`lista_precio_id`, `'fk' => ['modelo' => \App\Models\ListaPrecio::class]`), reutilizando el mecanismo
  `precargarCatalogosFk`/resolución en `mapearFila()` ya existente (research.md §3)

**Checkpoint**: importar Clientes de punta a punta con todos los campos nuevos funciona y está probado
independientemente.

---

## Phase 4: User Story 2 - Importar Proveedores con los mismos datos fiscales y de saldo (Priority: P2)

**Goal**: la solapa Proveedores ofrece el mismo bloque de campos nuevos que Clientes, excluyendo los que
no existen en `Proveedor` (ML, Nota para Ventas, Descuento General, Lista de Precios).

**Independent Test**: con la solapa Proveedores, subir un archivo de prueba con el bloque fiscal + saldo
inicial + fecha, mapear, confirmar, y verificar en `/proveedores` que los datos quedaron correctos — sin
depender de Clientes ni de Productos.

### Tests for User Story 2 ⚠️

- [ ] T013 [P] [US2] Feature test: importar Proveedores mapeando el bloque fiscal completo + Saldo
  Inicial + Fecha de Saldo Inicial → valores persistidos correctamente, en
  `tests/Feature/ImportacionDatosTest.php`
- [ ] T014 [P] [US2] Feature test: el selector de campo destino de la solapa Proveedores NO ofrece
  Usuario de Mercado Libre, Nota para Ventas, Descuento General ni Lista de Precios, en
  `tests/Feature/ImportacionDatosTest.php`

### Implementation for User Story 2

- [ ] T015 [US2] En `DefinicionCamposImportables::proveedores()` (que hoy parte de `self::clientes()`),
  excluir explícitamente `apodo_ml`, `nota_cliente`, `descuento_general_pct` y `lista_precio_id` del
  array heredado, ya que esos campos no existen en `Proveedor` (depende de T010-T012)

**Checkpoint**: Clientes y Proveedores comparten el mismo nivel de campos mapeables, respetando las
diferencias reales entre ambos modelos.

---

## Phase 5: User Story 3 - Importar Productos indicando si están activos y dónde se muestran (Priority: P3)

**Goal**: la solapa Productos & Servicios ofrece Activo, Mostrar en Ventas y Mostrar en Compras como
campos mapeables, con parseo booleano y default de columna en celda vacía.

**Independent Test**: con la solapa Productos & Servicios, subir un archivo con esas 3 columnas en
distintos formatos de valor booleano, mapear, confirmar, y verificar en `/productos` que cada producto
quedó con el estado correcto — sin depender de Clientes ni de Proveedores.

### Tests for User Story 3 ⚠️

- [ ] T016 [P] [US3] Feature test: importar Productos mapeando Activo/Mostrar en Ventas/Mostrar en
  Compras con valores `Si/No`, `1/0`, `true/false` → cada producto creado con el booleano correcto, en
  `tests/Feature/ImportacionDatosTest.php`
- [ ] T017 [P] [US3] Feature test: celda vacía en alguno de esos 3 campos → producto creado con el
  default de columna (`true`); celda con valor no reconocido → fila fallida con motivo, en
  `tests/Feature/ImportacionDatosTest.php`

### Implementation for User Story 3

- [ ] T018 [US3] En `DefinicionCamposImportables::productos()`, agregar Activo (`activo`, `'booleano' =>
  true`), Mostrar en Ventas (`mostrar_en_ventas`, `'booleano' => true`) y Mostrar en Compras
  (`mostrar_en_compras`, `'booleano' => true`) (depende de T001-T004)

**Checkpoint**: las 3 solapas quedan al mismo nivel de cobertura de campos respecto a sus modelos.

---

## Phase 6: Polish & Cross-Cutting Concerns

**Purpose**: consistencia de documentación y validación final end-to-end.

- [ ] T019 [P] Actualizar `docs/documentacion_principal_crm.md §2.6` con el listado ampliado de campos
  mapeables por entidad (Clientes/Proveedores/Productos)
- [ ] T020 [P] Agregar en `docs/documentacion_principal_crm.md §5` la brecha pendiente "Punto
  Reposición" (columna del archivo real de Productos sin campo equivalente en el modelo, fuera de
  alcance de esta feature — spec.md Assumptions)
- [ ] T021 Ejecutar la validación de `specs/026-importador-datos-campos-completos/quickstart.md` de
  punta a punta (los 10 escenarios de US1-US3)
- [ ] T022 Correr `php artisan test --filter=ImportacionDatos` y `php artisan test --filter=ImportadorFilasParseo`,
  dejar ambas suites en verde

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Fase 1)**: vacía, sin dependencias.
- **Foundational (Fase 2)**: sin dependencias de Setup — BLOQUEA las 3 historias (T001-T004 son
  prerequisito de cualquier campo `'fecha'`/`'booleano'` nuevo).
- **User Stories (Fases 3-5)**: dependen de Foundational.
  - US1 (P1) primero — agrega el bloque más grande de campos (texto, numérico, fecha, fk).
  - US2 (P2) depende de que `DefinicionCamposImportables::clientes()` ya tenga los campos nuevos (T010-
    T012), porque `proveedores()` parte de ese mismo array y sólo resta excluir 4 campos.
  - US3 (P3) sólo depende de Foundational (T001-T004) — es independiente de US1/US2.
- **Polish (Fase 6)**: al final, depende de que las 3 historias estén completas.

### Paralelizables

- Foundational: T001 y T002 en paralelo (funciones independientes); T005/T006 en paralelo entre sí
  (tests de funciones ya independientes).
- Tests de US1 (T007-T009) en paralelo entre sí.
- Tests de US2 (T013-T014) en paralelo entre sí.
- Tests de US3 (T016-T017) en paralelo entre sí.
- T019/T020 (documentación) en paralelo entre sí y con T021-T022.

---

## Parallel Example: User Story 1

```bash
# Tests de User Story 1 en paralelo:
Task: "Feature test bloque fiscal + ML + página web en tests/Feature/ImportacionDatosTest.php"
Task: "Feature test saldo inicial + fecha (3 formatos) en tests/Feature/ImportacionDatosTest.php"
Task: "Feature test lista de precios (match / no match) en tests/Feature/ImportacionDatosTest.php"
```

---

## Implementation Strategy

### MVP (recomendado)

1. Fase 1 (vacía) → Fase 2 (Foundational: parseo de fecha y booleano) → Fase 3 (US1: Clientes completo).
2. **STOP y validar**: importar el archivo real `public/imports/clientes.xlsx` (o una muestra) mapeando
   todos los campos nuevos, confirmar que el resumen no reporta fallos inesperados. Demo.

### Incremental

- + US2 (Proveedores, reutiliza el array de Clientes menos 4 campos) → + US3 (Productos: 3 booleanos,
  independiente de las otras dos). Cada historia agrega valor sin tocar el mecanismo central de spec 006.

---

## Notes

- [P] = archivos distintos o funciones independientes dentro del mismo archivo, sin dependencias
  pendientes.
- Verificar que T007-T009, T013-T014, T016-T017 fallen antes de implementar la lógica que prueban (TDD
  en lo crítico: saldo inicial es dinero, booleanos de Producto afectan visibilidad — Principio IV).
- Commit por task o grupo lógico; parar en cada checkpoint para validar la historia.
- No se agrega ninguna migración: los 19 campos de modelo nuevos (16 en Clientes, de los cuales 12 se
  comparten con Proveedores, más 3 booleanos en Productos) ya existen en
  `clientes`/`proveedores`/`productos` (data-model.md).
- No se toca `ImportacionController.php` ni las vistas Blade del asistente — el select de mapeo ya itera
  `$definicion` dinámicamente (plan.md §Structure Decision).
