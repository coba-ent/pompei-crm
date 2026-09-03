# Tasks: Bonificación efectiva por línea con Descuento General

**Input**: Design documents from `/specs/098-bonif-efectiva-descuento-general/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/metodos-modelo.md, quickstart.md

**Tests**: Incluidos — obligatorio por Principio IV de la constitución (testing donde hay dinero
o impacto fiscal; esta feature toca directamente cálculo de descuentos/importes).

**Organization**: Tareas agrupadas por user story de spec.md. US1 y US2 son P1 (Presupuesto/
Venta/Compra); US3 es P2 (NC/ND, alcance distinto: no combina, sólo agrega la fila de totales).

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Puede correr en paralelo (archivos distintos, sin dependencia de una tarea sin
  terminar)
- **[Story]**: A qué user story pertenece (US1, US2, US3)

## Path Conventions

Proyecto único Laravel (ver plan.md § Project Structure) — rutas relativas a la raíz del repo.

---

## Phase 1: Setup

**Purpose**: No hay inicialización de proyecto (código existente) ni dependencias nuevas que
instalar. Esta fase queda vacía a propósito — el "setup" real de esta feature es leer el contrato
ya fijado en contracts/metodos-modelo.md, que ya se hizo en Fase 1 del plan.

*(Sin tareas — proyecto ya inicializado, sin dependencias nuevas.)*

---

## Phase 2: Foundational

**Purpose**: Nada bloqueante compartido entre las 3 user stories — cada modelo de ítem
(`PresupuestoItem`, `VentaItem`, `CompraItem`) es independiente entre sí, y NC/ND (US3) no
depende de los otros dos. No hay una tarea foundational que deba ir antes de todas las demás.

*(Sin tareas — cada user story es autocontenida sobre archivos ya existentes.)*

---

## Phase 3: User Story 1 - La fila de cada ítem refleja el Descuento General mientras se carga (Priority: P1)

**Goal**: El Subtotal/Total de cada fila de ítems en la grilla de alta/edición de Presupuesto,
Venta y Compra refleja en tiempo real el Descuento General de cabecera, sin esperar a guardar, sin
tocar el campo editable "Desc." de la fila.

**Independent Test**: Abrir "Nueva Venta", cargar dos ítems sin descuento de línea, escribir 10%
de Descuento General y verificar que el Subtotal/Total de cada fila baja un 10%, sin guardar
(quickstart.md Escenario 1).

### Implementación para User Story 1

- [X] T001 [P] [US1] Extraer `factorDescuentoGeneral()` en `resources/js/presupuestos.js`: mover el cálculo de factor que hoy vive inline al inicio de `recalcular()` a una función propia (mismo cuerpo, sin cambiar su resultado — research.md Decisión 3), y hacer que `recalcular()` la llame en vez de recalcular inline.
- [X] T002 [P] [US1] Extraer `factorDescuentoGeneral()` en `resources/js/ventas.js`, idéntico a T001.
- [X] T003 [P] [US1] Extraer `factorDescuentoGeneral()` en `resources/js/compras.js`, idéntico a T001 (mismo patrón, `ivaPct` usa `pctIva()` en vez de objeto inline — no afecta esta extracción).
- [X] T004 [US1] En `resources/js/presupuestos.js::renderItems()`, calcular `factorGeneral = factorDescuentoGeneral()` una vez antes del `items.forEach(...)` y multiplicar `subtotal` (ya con el descuento de línea aplicado) por ese factor antes de pintar la celda de Subtotal/Total de cada fila. Depende de T001.
- [X] T005 [US1] Aplicar el mismo cambio a `resources/js/ventas.js::renderItems()`. Depende de T002.
- [X] T006 [US1] Aplicar el mismo cambio a `resources/js/compras.js::renderItems()`. Depende de T003.
- [X] T007 [US1] Verificar en los 3 archivos que el campo editable "Desc." de cada fila (`data-field="descuento_pct"`) sigue leyendo/escribiendo únicamente `item.descuento_pct` — no debe agregarse ninguna reescritura de ese input con el valor combinado (FR-003). No requiere cambio de código si T004-T006 se implementaron correctamente; es una verificación explícita antes de dar la story por cerrada.

**Checkpoint**: User Story 1 queda completa y comprobable end-to-end vía quickstart.md Escenario 1, en Presupuesto, Venta y Compra, sin depender de User Story 2 ni 3.

---

## Phase 4: User Story 2 - El PDF de Presupuesto/Venta/Compra explica la bonificación real de cada línea (Priority: P1)

**Goal**: La columna "Bonif." del PDF de Presupuesto, Venta y Compra muestra el porcentaje de
descuento efectivo de cada línea (línea + general combinados), en vez de sólo el descuento propio
de línea.

**Independent Test**: Emitir el PDF de una Venta con 10% de Descuento General y dos ítems sin
bonificación de línea; verificar que "Bonif." muestra 10% en cada ítem (quickstart.md Escenario 2).
No depende de User Story 1 — el PDF se genera a partir de datos ya guardados, sin pasar por
`renderItems()`.

### Tests para User Story 2 ⚠️ Escribir antes de la implementación (Principio IV)

- [X] T008 [P] [US2] Crear `tests/Unit/BonifEfectivaCalculoTest.php` con los 7 casos de contrato de `bonifEfectivaPct()` listados en `contracts/metodos-modelo.md` (sin ningún descuento, sólo línea, sólo general, ambos combinados dando 19% no 20%, cantidad cero, precio cero, con cantidad≠1), instanciando `PresupuestoItem` en memoria (sin persistir) con los atributos `cantidad`/`precio_unitario`/`subtotal` seteados directo. Repetir la tabla para `bonifEfectivaEtiqueta()` (incluyendo el caso 12,5% → "12,5%", coma decimal).
- [X] T009 [US2] Crear `tests/Feature/PresupuestoPdfBonifTest.php`: generar un Presupuesto real (factory) con Descuento General 10% y dos ítems sin bonificación de línea, pedir su PDF (`->pdf()` o la ruta correspondiente) y verificar (via `assertSee` sobre el HTML antes de convertir a PDF, o inspeccionando la vista renderizada con `view()->make(...)->render()`) que el string "10%" aparece en la fila de cada ítem. Depende de que T012 (más abajo) ya exista el método en el modelo.
- [X] T010 [P] [US2] Crear `tests/Feature/VentaPdfBonifTest.php`, mismo patrón que T009 sobre Venta.
- [X] T011 [P] [US2] Crear `tests/Feature/CompraPdfBonifTest.php`, mismo patrón que T009 sobre Compra.

### Implementación para User Story 2

- [X] T012 [P] [US2] Agregar `bonifEfectivaPct()` y `bonifEfectivaEtiqueta()` a `app/Models/PresupuestoItem.php`, según la fórmula fijada en `contracts/metodos-modelo.md` y `research.md` Decisión 1 (`round((1 - subtotal / (cantidad * precio_unitario)) * 100, 2)`, con guarda de bruto ≤ 0 → 0.0; etiqueta "-" cuando el % es ≤ 0, si no `"N%"`/`"N,M%"` con `number_format(...,2,',','.')` recortando ceros de más).
- [X] T013 [P] [US2] Agregar los mismos dos métodos, idénticos, a `app/Models/VentaItem.php`.
- [X] T014 [P] [US2] Agregar los mismos dos métodos, idénticos, a `app/Models/CompraItem.php`.
- [X] T015 [US2] En `resources/views/presupuestos/pdf.blade.php`, reemplazar `{{ $item->descuento_pct ? $item->descuento_pct.'%' : '-' }}` por `{{ $item->bonifEfectivaEtiqueta() }}` en la columna "Bonif.". Depende de T012.
- [X] T016 [US2] Mismo cambio en `resources/views/ventas/pdf.blade.php`. Depende de T013.
- [X] T017 [US2] Mismo cambio en `resources/views/compras/pdf.blade.php`. Depende de T014.

**Checkpoint**: User Story 2 queda completa y comprobable end-to-end vía quickstart.md Escenario 2, en los 3 PDFs, independiente de User Story 1 y 3.

---

## Phase 5: User Story 3 - El PDF de NC/ND muestra el Descuento General como una fila propia de totales (Priority: P2)

**Goal**: El PDF ("Ver Detalle") de NC/ND agrega una fila "Descuento General" a su bloque de
totales con el importe correspondiente, sin tocar la columna "%Bonif." de cada línea (que sigue
mostrando sólo el descuento propio de línea, sin combinar — FR-008).

**Independent Test**: Emitir el PDF de una NC/ND con Descuento General cargado; verificar que
aparece la fila "Descuento General" con importe > $0, y que "%Bonif." de cada línea no cambia
respecto de hoy (quickstart.md Escenario 3). No depende de User Story 1 ni 2.

### Tests para User Story 3 ⚠️ Escribir antes de la implementación (Principio IV)

- [X] T018 [P] [US3] Crear `tests/Unit/NotaCreditoDebitoMontoDescuentoGeneralTest.php` con los 4 casos de contrato de `montoDescuentoGeneral()` listados en `contracts/metodos-modelo.md` (Σ=1000 con 10% → 100.0; Σ=1000 con $150 monto fijo → 150.0; Σ=0 con $150 monto fijo → 0.0 sin división por cero; Σ=1000 con 0%/null → 0.0), instanciando `NotaCreditoDebito` con `items` seteados en memoria (`setRelation('items', collect([...]))`, sin persistir).
- [X] T019 [US3] Crear `tests/Feature/NotaCreditoDebitoPdfDescuentoGeneralTest.php`: generar una NC/ND real (factory, reutilizando el patrón de `tests/Feature/PdfNotaCreditoDebitoTest.php` ya existente) con Descuento General 10% y dos ítems, pedir su PDF y verificar que el string "Descuento General" aparece en el bloque de totales junto a un importe > $0. Agregar un segundo caso en el mismo archivo para una nota sin Descuento General, verificando que la fila muestra "$0,00" sin romper. Depende de T020.

### Implementación para User Story 3

- [X] T020 [US3] Agregar `montoDescuentoGeneral()` a `app/Models/NotaCreditoDebito.php`, replicando el algoritmo de `resources/js/notas-credito-debito.js::recalcular()` (research.md Decisión 5): `subtotalSinDescuento = Σ items: cantidad * precio * (1 - descuento_pct/100)`, factor según `descuento_general_tipo`/`_pct`/`_monto` (con guarda `subtotalSinDescuento > 0` en modo monto, si no factor = 1), retorno `round(subtotalSinDescuento * (1 - factor), 2)`.
- [X] T021 [US3] En `resources/views/notas-credito-debito/pdf.blade.php`, agregar una fila `<tr><td>Descuento General</td><td class="text-end">$ {{ number_format($notaCreditoDebito->montoDescuentoGeneral(), 2, ',', '.') }}</td></tr>` al bloque de totales existente (antes o junto a la fila "Monto"), sin tocar la columna "%Bonif." de la tabla de ítems (`{{ $item->descuento_pct ?? 0 }}%` queda igual — FR-008). Depende de T020.

**Checkpoint**: User Story 3 queda completa y comprobable end-to-end vía quickstart.md Escenario 3, sin afectar a US1 ni US2.

---

## Phase 6: Polish & Cross-Cutting

**Purpose**: Verificación de los casos límite transversales (Edge Cases de spec.md) que no quedan
cubiertos por un solo test de story, y actualización de documentación ya iniciada en la Fase 1 del
plan.

- [X] T022 [P] Agregar al `tests/Unit/BonifEfectivaCalculoTest.php` (T008) un caso con 5+ ítems y Descuento General no redondo (7%), verificando que la suma de `subtotal` de los ítems coincide con el `subtotal_con_descuento` del comprobante dentro de 1 centavo (research.md Decisión 4, SC-001) — usa `CalculoComprobante::calcular()` real, no mocks, para no divergir de la fuente de verdad del backend.
- [X] T023 [P] Confirmar que `docs/documentacion_principal_crm.md` (ya actualizado en la Fase 1 del plan — ver sección "Columna 'Bonif.' del PDF" y el bloque de NC/ND) sigue siendo consistente después de la implementación; si algún detalle de la implementación real difiere de lo documentado (por ejemplo el formato exacto de `bonifEfectivaEtiqueta()`), corregir el doc en el mismo commit.
- [X] T024 Ejecutar `php artisan test --filter=Bonif` y `--filter=NotaCreditoDebitoMontoDescuentoGeneral` para confirmar que toda la suite nueva pasa en conjunto, y correr `php artisan test --filter="Presupuesto|Venta|Compra"` (suite existente, no sólo la nueva) para confirmar que no hay regresión en tests ya existentes de estos 3 módulos (SC-003: el total final no cambia).
- [X] T025 Validación manual siguiendo `quickstart.md` completo (los 4 escenarios) en local vía Chrome DevTools MCP, nunca en el VPS de producción — memoria del proyecto "Nunca probar en producción". Verificado contra datos reales: Venta 24013 (15% general, 12 ítems), Presupuesto 18 (15%→25% en vivo), Compra 2435 (3%), NC/ND 858 (5%, %Bonif. de línea separada + fila Descuento General en el PDF).
- [X] T026 Fix encontrado durante T025 (no cubierto por los tests de Feature porque éstos sólo prueban PDFs de datos ya guardados): `#f-descuento-general` escuchaba `'input'` pero llamaba `recalcular()` directo en vez de `renderItems()` en los 3 archivos (`presupuestos.js`, `ventas.js`, `compras.js`) — el pie de página se actualizaba al tocar el campo, pero el Subtotal/Total de cada FILA quedaba congelado con el factor viejo hasta que el usuario tocara cualquier campo de un ítem. Mismo bug en el toggle %/$ (`setModoDescuentoGeneral`, rama `limpiarValor`). Corregido cambiando ambos call sites a `renderItems()` (que ya llama a `recalcular()` al final) en los 3 archivos. Re-verificado en el navegador: Venta 24013 15%→20% y Presupuesto 18 15%→25% ahora actualizan cada fila en el mismo evento que el pie de página.

---

## Dependencies & Execution Order

- **Phase 3 (US1)**, **Phase 4 (US2)** y **Phase 5 (US3)** son independientes entre sí — ninguna
  bloquea a las otras dos. Pueden implementarse en cualquier orden o en paralelo.
- Dentro de Phase 3: T001→T004, T002→T005, T003→T006 (extraer antes de usar); T007 al final como
  verificación.
- Dentro de Phase 4: T008 (tests unitarios) puede escribirse en paralelo a T012-T014
  (implementación) siguiendo TDD, pero T009-T011 (tests de Feature sobre el PDF) dependen de que
  T012-T014 ya existan para poder pasar en verde. T015→T012, T016→T013, T017→T014.
- Dentro de Phase 5: T018 en paralelo a T020; T019 depende de T020.
- Phase 6 depende de que Phase 3, 4 y 5 estén completas (verifica el conjunto).

## Parallel Execution Examples

**Dentro de User Story 1** (T001, T002, T003 tocan archivos distintos, sin dependencia entre sí):
```
T001 [P] resources/js/presupuestos.js
T002 [P] resources/js/ventas.js
T003 [P] resources/js/compras.js
```

**Dentro de User Story 2** (T012, T013, T014 tocan modelos distintos):
```
T012 [P] app/Models/PresupuestoItem.php
T013 [P] app/Models/VentaItem.php
T014 [P] app/Models/CompraItem.php
```

**Entre user stories** (si hay más de un desarrollador/sesión disponible): Phase 3, 4 y 5 completas
pueden asignarse en paralelo — no comparten ningún archivo (US1 toca sólo JS de Presupuesto/Venta/
Compra; US2 toca sólo los modelos de ítem + PDFs de esos 3; US3 toca sólo `NotaCreditoDebito.php`
y su propio PDF).

## Implementation Strategy

**MVP = User Story 1 sola**: ya resuelve el síntoma que reportó el cliente ("el descuento general
no se aplica a cada ítem") de forma visible en pantalla, sin tocar ningún PDF. Es la historia de
menor riesgo (no toca documentos que salen del sistema) y la que más urgencia tiene según la
conversación con el cliente.

**Incremento 2 = User Story 2**: cierra el mismo bug en los documentos que se le mandan a
clientes/proveedores — mayor impacto pero también mayor exposición (un PDF mal armado llega a
alguien externo), por eso va después de validar la fórmula con US1.

**Incremento 3 = User Story 3**: menor prioridad (P2 en la spec) porque no hay ninguna columna que
"mienta" en NC/ND hoy — es una fila de información faltante, no una inconsistencia visible.

Cada incremento es un PR/commit independiente y desplegable por separado si se prefiere, dado que
no comparten archivos entre sí.
