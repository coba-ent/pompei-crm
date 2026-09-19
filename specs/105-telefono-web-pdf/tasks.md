# Tasks: Teléfono y sitio web en el encabezado de los comprobantes impresos

**Feature**: 105-telefono-web-pdf | **Date**: 2026-09-18
**Input**: [spec.md](./spec.md), [plan.md](./plan.md), [data-model.md](./data-model.md),
[research.md](./research.md), [contracts/encabezado-emisor.md](./contracts/encabezado-emisor.md),
[quickstart.md](./quickstart.md)

**Tests**: se incluyen. No por el principio IV de la constitución (acá no hay dinero ni impacto
fiscal), sino porque FR-004 y FR-005 son regresiones silenciosas: un `@if` mal escrito produce un PDF
perfectamente válido al que le falta un dato, y nadie lo nota hasta que el comprobante ya se entregó.

---

## Phase 1: Setup

No hay setup: la feature trabaja sobre estructura ya existente (tabla, pantalla y partial). Se pasa
directo a Foundational.

---

## Phase 2: Foundational (bloqueante para las dos historias)

**Propósito**: que las dos columnas existan y sean asignables. Sin esto, ni la ficha puede guardarlas
ni el partial puede leerlas.

- [ ] T001 Crear la migración `database/migrations/2026_09_18_XXXXXX_add_telefono_sitio_web_to_datos_empresa_table.php` que agrega `telefono` y `sitio_web` como `string` nullable a `datos_empresa`, ubicados después de `ingresos_brutos`, con `down()` que las dropea. La migración debe ser puramente aditiva: no modifica ni reescribe ninguna columna existente (corre sobre una base de producción con comprobantes con CAE real).
- [ ] T002 Agregar `'telefono'` y `'sitio_web'` al `$fillable` de `app/Models/DatosEmpresa.php`. Sin casts, accessors ni mutators: son strings que se guardan y se imprimen como vinieron (FR-007).
- [ ] T003 Correr `php artisan migrate` en local y confirmar que la fila única existente conserva todos sus datos y queda con los dos campos nuevos en `NULL` (FR-010).

**Checkpoint**: la base acepta los dos datos. Todavía no hay forma de cargarlos ni de verlos.

---

## Phase 3: User Story 2 — El negocio carga sus datos de contacto (P2)

> Se implementa **antes** que la US1 pese a su prioridad menor: sin un lugar donde cargar los datos,
> la US1 no se puede ni demostrar ni testear manualmente. Es una dependencia de habilitación, no una
> inversión de prioridad — el valor para el cliente final sigue estando en la US1.

**Goal**: poder cargar y corregir teléfono y página web desde Configuración & Ajustes → Empresa.

**Independent Test**: editar los dos campos, guardar, y verlos en la ficha al volver a entrar.

- [ ] T004 [US2] Agregar las reglas `'telefono' => ['nullable', 'string', 'max:255']` y `'sitio_web' => ['nullable', 'string', 'max:255']` al `$request->validate()` de `MiPerfilController::guardar()` en `app/Http/Controllers/MiPerfilController.php`. Sin validación de formato: el teléfono admite varios números y aclaraciones, y la web se acepta con o sin protocolo (research.md, Decisión 2).
- [ ] T005 [US2] Agregar los dos campos a la **ficha de lectura** de `resources/views/configuracion/mi-perfil/index.blade.php`, siguiendo el mismo patrón `col-md-4` + `{{ $datosEmpresa->campo ?: '-' }}` que usan los campos existentes.
- [ ] T006 [US2] Agregar los dos inputs al **modal de edición** `#modal-mi-perfil` en el mismo archivo, como `<input type="text">` con `value="{{ $datosEmpresa->telefono ?? '' }}"`. Texto plano: no aplica Select2 (regla 5, es para selects dinámicos) ni `AppFecha` (regla 6, es para fechas). **No se toca `resources/js/mi-perfil.js`**: el form ya se envía entero por `FormData` y los campos nuevos viajan solos.
- [ ] T007 [US2] Agregar al `resources/views/configuracion/mi-perfil/index.blade.php` un `form-text` bajo el campo de página web aclarando que se imprime tal cual en los comprobantes, para que quien lo carga sepa dónde va a aparecer.
- [ ] T008 [US2] Extender `tests/Feature/MiPerfilTest.php` con un test que guarde teléfono y sitio web y afirme que se persisten, y otro que guarde **sin** ellos y afirme que el guardado se acepta igual (FR-002) y que no se pierden los datos preexistentes (FR-010, escenario 4 de la US2).

**Checkpoint**: los datos se cargan y se ven en la pantalla de Empresa. Todavía no salen en ningún PDF.

---

## Phase 4: User Story 1 — El cliente que recibe el comprobante puede contactar al negocio (P1)

**Goal**: que teléfono y página web aparezcan en el encabezado de los cinco comprobantes imprimibles.

**Independent Test**: con los datos cargados, imprimir un presupuesto y ver ambos datos en el
encabezado; borrarlos, reimprimir, y ver el encabezado exactamente como antes.

**⚠️ Esta fase toca UN SOLO archivo de producción.** Si la implementación necesita editar alguno de
los cinco `*/pdf.blade.php` o sus controladores, algo se hizo mal: los cinco ya incluyen el partial y
ya reciben `$datosEmpresa` (research.md, Decisión 1).

- [ ] T009 [US1] Agregar al partial `resources/views/pdf/partials/encabezado-emisor.blade.php`, **después** del bloque de Condición de IVA, dos líneas envueltas cada una en su `@if`: `Tel: {{ $datosEmpresa->telefono }}` y `{{ $datosEmpresa->sitio_web }}`. Seguir el orden fijado en `contracts/encabezado-emisor.md` §Salida, que implementa FR-011 (contacto después de lo fiscal). Escapar con `{{ }}`, nunca `{!! !!}` (contrato R4). No envolver `sitio_web` en un `<a href>`: es un documento impreso (contrato R2).
- [ ] T010 [US1] Verificar FR-012 (maquetación con valores largos): cargar un teléfono con dos números y aclaraciones más una dirección web extensa, generar un comprobante y confirmar que el texto acomoda en varias líneas dentro de su celda sin desplazar el logo ni superponerse con los datos fiscales (contrato R5).
- [ ] T011 [US1] Crear `tests/Feature/EncabezadoEmisorPdfTest.php` con un test que renderice el encabezado **con** los dos datos cargados y afirme que ambos aparecen en el HTML, y otro que lo renderice con los campos vacíos y afirme que **no** aparece ni el valor ni la etiqueta `Tel:` (FR-005). Renderizar la vista, no el PDF binario: el output de DomPDF está comprimido y no permite `assertStringContainsString` (research.md, Decisión 3).
- [ ] T012 [US1] Agregar al mismo test la cobertura de los **cinco** comprobantes (FR-004): que cada una de las cinco vistas de PDF incluya el encabezado con los datos de contacto. Es el test que impide que la consistencia entre comprobantes se rompa en el futuro.
- [ ] T013 [US1] Agregar un test del caso de **un solo campo cargado** (teléfono sí, web no): aparece el que está, no aparece nada del que falta.

**Checkpoint**: la feature está completa y entregable.

---

## Phase 5: Polish & verificación cruzada

- [ ] T014 Correr `php artisan test --filter="MiPerfil|EncabezadoEmisor"` y confirmar verde.
- [ ] T015 Correr la suite de comprobantes (`php artisan test --filter="Pdf|Presupuesto|Recibo|Remito|NotaCredito"`) y comparar contra la baseline **previa al cambio**: no debe haber ninguna falla nueva. La suite del proyecto tiene fallas preexistentes ajenas a esta feature; el criterio es "ninguna nueva", no "todo verde" (SC-003).
- [ ] T016 Validación manual en local siguiendo [quickstart.md](./quickstart.md): cargar los datos, imprimir los cinco comprobantes, borrarlos y reimprimir. El paso 3 del quickstart (campos vacíos) es el que más fácil se pasa por alto.
- [ ] T017 Verificar SC-004 sobre una venta con CAE ya emitido: importes, tipo de comprobante, numeración, CAE, vencimiento y QR idénticos antes y después. Lo único distinto debe ser el bloque de contacto.

> **Documentación**: `docs/modelo_datos.md` y `docs/documentacion_principal_crm.md` **ya fueron
> actualizados** durante la fase de planificación, como exige el principio I de la constitución
> (columnas nuevas + las dos correcciones detectadas: el encabezado lo consumen 5 PDFs y no 2, y
> faltaba `mail_contador`). No queda tarea pendiente de documentación.

---

## Dependencies

```
Phase 2 (T001-T003)  ← bloqueante para todo
        ↓
Phase 3 US2 (T004-T008)  ← habilita poder cargar los datos
        ↓
Phase 4 US1 (T009-T013)  ← el valor para el cliente
        ↓
Phase 5 (T014-T017)
```

- **T001 → T002 → T003**: estrictamente secuenciales (la columna debe existir antes de asignarla).
- **T004, T005, T006** pueden hacerse en paralelo entre sí sólo parcialmente: T005 y T006 tocan el
  **mismo archivo**, así que no se marcan `[P]`.
- **T009** depende de T001-T002 (los campos deben existir), no de la Phase 3.
- **T011, T012, T013** tocan el mismo archivo de test: secuenciales.

## Parallel opportunities

Pocas, y es esperado: la feature toca cuatro archivos en total y dos de las tareas de UI caen en el
mismo Blade. Lo único genuinamente paralelizable:

- [P] T004 (controlador) en paralelo con T005/T006 (vista) — archivos distintos.
- [P] T008 (test de Mi Perfil) en paralelo con T011 (test del encabezado) — archivos distintos.

## Implementation Strategy

**MVP**: Phase 2 + Phase 4 (US1) sería técnicamente el mínimo que cumple el pedido del cliente… pero
sin la Phase 3 nadie puede cargar los datos, así que el MVP real es **Phase 2 + 3 + 4**. Son 13 tareas
sobre 4 archivos de producción; no tiene sentido partirlo en entregas.

**Orden recomendado**: lineal, T001 → T017. La feature es chica y el orden de fases ya resuelve las
dependencias.
