---
description: "Task list for 014-verificacion-documento-fiscal"
---

# Tasks: Verificación de documento fiscal (CUIT/CUIL)

**Input**: Design documents from `/specs/014-verificacion-documento-fiscal/`
**Prerequisites**: [plan.md](./plan.md), [spec.md](./spec.md), [research.md](./research.md), [data-model.md](./data-model.md), [contracts/verificar-documento.md](./contracts/verificar-documento.md)

**Tests**: Incluidos. Principio IV de la constitución ("Testing donde hay dinero o impacto fiscal")
los hace obligatorios para User Story 2 (deriva el tipo de comprobante y qué CUIT queda persistido en
un Cliente). Para User Story 1 se agrega un test liviano por ser superficie nueva de backend (el
endpoint), aunque sea puramente UX sobre una regla ya testeada.

**Organization**: Agrupadas por user story de spec.md, para que cada una sea implementable y
testeable de forma independiente.

## Path Conventions

Proyecto Laravel monolítico existente (ver plan.md § Project Structure) — no aplican convenciones de
monorepo/frontend-backend separados. Todas las rutas de archivo son relativas a la raíz del repo.

---

## Phase 1: Setup

**Purpose**: Inicialización de proyecto.

No aplica — proyecto Laravel ya establecido, sin dependencias nuevas (ver plan.md § Technical
Context). Se pasa directo a Foundational.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Infraestructura compartida bloqueante para todas las user stories.

Ninguna. `App\Rules\CuitValido` ya existe y no cambia (research.md R1) — es la única pieza compartida
entre User Story 1 y User Story 2, y no requiere ningún trabajo previo. Las dos historias son
completamente independientes entre sí y pueden implementarse en cualquier orden o en paralelo.

**Checkpoint**: no hay bloqueo — arrancar directo por User Story 1 (P1, MVP) o User Story 2.

---

## Phase 3: User Story 1 - Verificar el documento al cargar un Cliente o Proveedor (Priority: P1) 🎯 MVP

**Goal**: botón "Verificar" + auto-formato con guiones en el campo N° de Doc de los modales de
Cliente y Proveedor (alta y edición), reusando `CuitValido` vía un endpoint liviano.

**Independent Test**: abrir el modal de Nuevo Cliente (o Nuevo Proveedor), tipear un CUIT inválido en
N° de Doc, apretar "Verificar" y confirmar que aparece el error sin recargar ni guardar nada — según
`quickstart.md` § Escenario 1.

### Tests for User Story 1

- [X] T001 [P] [US1] Feature test del endpoint `clientes.verificar-documento` en
  `tests/Feature/VerificacionDocumentoClienteTest.php`: casos (a) CUIT válido → `{aplica:true,
  valido:true}`, (b) CUIT inválido → `{aplica:true, valido:false, mensaje:...}`, (c) tipo_documento=DNI
  → `{aplica:false}`, (d) número vacío → `{aplica:false}`, (e) falta algún query param → 422. Ver
  contrato exacto en `contracts/verificar-documento.md`.
- [X] T002 [P] [US1] Feature test del endpoint `proveedores.verificar-documento` en
  `tests/Feature/VerificacionDocumentoProveedorTest.php` — mismos casos que T001, contra Proveedor.

### Implementation for User Story 1

- [X] T003 [US1] Agregar rutas `GET clientes/verificar-documento` y
  `GET proveedores/verificar-documento` en `routes/web.php`, junto a las rutas de utilidad ya
  existentes de cada módulo (`clientes/opciones`, `proveedores/opciones` — mismo bloque, antes del
  `Route::resource(...)` correspondiente; no hay middleware `permiso:` que agregar, esas rutas hoy
  sólo requieren `auth`, ver research.md R2).
- [X] T004 [P] [US1] Agregar acción `verificarDocumento(Request $request): JsonResponse` en
  `app/Http/Controllers/ClienteController.php`: valida `tipo_documento` y `numero` requeridos (422 si
  falta alguno), devuelve `{"aplica": false}` si `tipo_documento` no es CUIT/CUIL o `numero` está
  vacío, si no normaliza el número (quitar todo lo que no sea dígito) y devuelve
  `{"aplica": true, "valido": bool}` usando `App\Rules\CuitValido::esValido()` (agregar `mensaje`
  cuando `valido` es `false`, tomando el texto tal cual del `$fail(...)` de `CuitValido`: "El CUIT
  ingresado no es válido." — no inventar un texto nuevo, ver hallazgo I1 de `/speckit-analyze`).
- [X] T005 [P] [US1] Agregar la acción equivalente `verificarDocumento()` en
  `app/Http/Controllers/ProveedorController.php` (mismo comportamiento que T004, sin relación de
  código forzada entre ambos controllers — están bien como dos métodos triviales independientes, ver
  research.md R2).
- [X] T006 [P] [US1] En `resources/views/clientes/_modal_form.blade.php`, agregar el botón "Verificar"
  (ícono de refresh, `type="button"`) dentro del `input-group` del campo N° de Doc (junto al `<input
  name="cuit">`, línea ~195), reusando el contenedor `.invalid-feedback[data-field="cuit"]` ya
  existente (línea ~197) para mostrar el resultado.
- [X] T007 [P] [US1] Mismo cambio de T006 en `resources/views/proveedores/_modal_form.blade.php`.
- [X] T008 [US1] En `resources/js/clientes.js`: (a) input mask que auto-formatea `name="cuit"` con
  guiones mientras se tipea (`XX-XXXXXXXX-X`, sólo visual — el valor normalizado ya lo limpia el
  backend, ver research.md R3); (b) handler de click del botón "Verificar" que llama por AJAX a la
  ruta de T003/T004 con los valores actuales de `tipo_documento` y `cuit`, y pinta el resultado en
  `.invalid-feedback[data-field="cuit"]`; (c) limpiar ese feedback en cuanto cambien `tipo_documento` o
  `cuit` (FR-010). Depende de T003, T004, T006.
- [X] T009 [US1] Mismo cambio de T008 en `resources/js/proveedores.js`, apuntando a la ruta de
  Proveedor (T003, T005, T007).

**Checkpoint**: User Story 1 funcional e independientemente testeable — botón "Verificar" y
auto-formato operativos en Cliente y Proveedor, alta y edición.

---

## Phase 4: User Story 2 - La conversión automática de Mercado Libre no confía ciegamente en un documento inválido (Priority: P2)

**Goal**: la conversión automática de una orden de Mercado Libre descarta un CUIT/CUIL matemáticamente
inválido antes de usarlo para derivar el comprobante o persistirlo en un Cliente nuevo — sin bloquear
la conversión.

**Independent Test**: simular una orden con `comprador_condicion_iva` vacío, `comprador_doc_tipo =
"CUIT"` y un `comprador_doc_numero` con dígito verificador incorrecto; confirmar que la conversión
automática se completa con comprobante B y que el Cliente creado queda con `cuit = null` — según
`quickstart.md` § Escenario 2.

### Tests for User Story 2

- [X] T010 [US2] En `tests/Feature/Integraciones/MercadoLibreClienteNuevoTest.php`, agregar los casos
  nuevos (siguiendo el patrón ya usado en ese archivo — `ordenCruda()`, `sincronizar()`, más la
  conversión automática vía `ConversorOrdenAVenta`/`intentarCreacionAutomatica`, ver tests existentes
  como referencia de fixture):
  - CUIT inválido, sin condición de IVA informada → comprobante B, Venta creada, orden NO queda
    "Requiere atención" (Acceptance Scenarios 1 y 3 de User Story 2).
  - Mismo caso → el Cliente nuevo creado tiene `cuit === null` (Acceptance Scenario 2).
  - CUIL inválido (en vez de CUIT), sin condición de IVA → mismo resultado que con CUIT (Acceptance
    Scenario 4).
  - CUIT **válido**, sin condición de IVA → sin cambios respecto al comportamiento actual: comprobante
    A, Cliente creado con ese CUIT (Acceptance Scenario 5 — test de no-regresión).
  - Condición de IVA **sí** informada + `doc_numero` inválido → el Cliente nuevo igual se crea sin
    CUIT, aunque el comprobante se derive de la condición de IVA y no del documento (Acceptance
    Scenario 6 — cubre el fix de FR-007 incondicional, CHK004).

### Implementation for User Story 2

- [X] T011 [US2] En `app/Services/MercadoLibre/DerivadorComprobante.php`, agregar el helper privado
  `sanearDocumento(?string $tipo, ?string $numero): array` descrito en research.md R4 (devuelve
  `[null, null]` si `$tipo` es `CUIT`/`CUIL` y `$numero` no pasa `CuitValido::esValido()`; devuelve
  `[$tipo, $numero]` sin tocar en cualquier otro caso) y aplicarlo, antes de construir el array de
  retorno, en **los dos** `return` de `derivar()` que hoy propagan `doc_tipo`/`doc_numero` crudos: la
  rama con condición de IVA informada (línea ~38-46) y la rama de aproximación por documento (línea
  ~49-56, FR-040c). La tercera rama (FR-040a, sin datos) no necesita cambios. Este único cambio cubre
  FR-005, FR-006 y FR-007 a la vez (ver research.md R4 — no hace falta tocar `ResolutorCliente.php`).

**Checkpoint**: User Stories 1 y 2 funcionan de forma independiente entre sí.

---

## Phase 5: Polish & Cross-Cutting

**Purpose**: Verificación final que atraviesa ambas historias.

- [X] T012 Correr la suite completa de `tests/Feature/Integraciones/` (no sólo los tests nuevos) para
  confirmar que el cambio en `DerivadorComprobante` no rompe ningún test existente de spec 012/013
  (`MercadoLibreConversionTest` y similares, si aplican FR-039/FR-040 originales).
- [X] T013 Ejecutar manualmente `quickstart.md` § Escenario 1 (Cliente y Proveedor) y § Escenario 2
  (vía test, ya cubierto por T010, pero conviene correrlo una vez más como humo final).
- [X] T014 [P] Actualizar `docs/documentacion_principal_crm.md` §2.1 si la implementación reveló algún
  detalle de UI no anticipado en la spec (ej. texto exacto del botón) — principio I de la
  constitución. Si no hay novedades respecto a lo ya documentado el 29/07/2026, no hace falta tocar
  nada.

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup**: N/A.
- **Foundational**: N/A — no bloquea nada.
- **User Story 1 (Phase 3)** y **User Story 2 (Phase 4)**: totalmente independientes entre sí, pueden
  hacerse en cualquier orden o en paralelo (no comparten ningún archivo).
- **Polish (Phase 5)**: depende de que ambas historias estén completas.

### Dentro de User Story 1

- T001, T002 (tests) antes de T004-T009 (implementación) si se sigue TDD — no dependen entre sí.
- T003 (rutas) bloquea a T004/T005 (controllers) en el sentido de que las rutas deben apuntar a un
  método que exista, pero como es un solo archivo por lado, en la práctica T003+T004 y T003+T005 se
  hacen juntos sin problema.
- T006/T007 (blade) independientes de T004/T005 (controller) — se pueden hacer en paralelo.
- T008 depende de T003, T004, T006 (necesita la ruta, el endpoint y el botón ya en el DOM).
- T009 depende de T003, T005, T007.

### Dentro de User Story 2

- T010 (test) antes de T011 (implementación) si se sigue TDD.
- T011 es un solo cambio en un solo archivo — no hay paralelismo interno.

---

## Parallel Example: User Story 1

```bash
# Tests (independientes entre sí):
Task: "Feature test clientes.verificar-documento en tests/Feature/VerificacionDocumentoClienteTest.php"
Task: "Feature test proveedores.verificar-documento en tests/Feature/VerificacionDocumentoProveedorTest.php"

# Controllers + vistas (archivos distintos, sin dependencias cruzadas):
Task: "verificarDocumento() en app/Http/Controllers/ClienteController.php"
Task: "verificarDocumento() en app/Http/Controllers/ProveedorController.php"
Task: "Botón Verificar en resources/views/clientes/_modal_form.blade.php"
Task: "Botón Verificar en resources/views/proveedores/_modal_form.blade.php"
```

---

## Implementation Strategy

### MVP First (User Story 1)

1. Fase 3 completa (User Story 1) → validar con `quickstart.md` § Escenario 1 → es un incremento
   demostrable por sí solo (botón + auto-formato), sin depender de la parte de Mercado Libre.

### Incremental Delivery

1. User Story 1 → validar → (opcional) desplegar — mejora de UX autocontenida.
2. User Story 2 → validar con `quickstart.md` § Escenario 2 → desplegar — endurece la conversión
   automática de Mercado Libre.
3. Phase 5 (Polish) al final, una sola vez, sobre ambas.

---

## Notes

- Ninguna tarea de User Story 1 toca archivos de User Story 2 y viceversa — son seguras de paralelizar
  entre dos personas (o dos sesiones) sin conflictos de merge.
- El hallazgo de la checklist de consistencia fiscal (CHK004, ver
  `checklists/consistencia-fiscal.md`) ya está reflejado en T011 y en el Acceptance Scenario 6 de
  User Story 2 — no quedó como deuda pendiente.
- Commitear después de cada tarea o grupo lógico, como en el resto del proyecto.
