# Implementation Plan: Bonificación efectiva por línea con Descuento General

**Branch**: `098-bonif-efectiva-descuento-general` | **Date**: 2026-09-03 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/098-bonif-efectiva-descuento-general/spec.md`

## Summary

Presupuesto, Venta y Compra calculan bien el total final (a pie de página y al guardar), pero la
fila individual de cada ítem en pantalla no refleja el Descuento General de cabecera mientras se
carga, y el PDF muestra "-" en la columna "Bonif." cuando el descuento real de esa línea vino del
Descuento General en vez de un descuento propio de línea. Se corrige agregando el factor del
Descuento General al render de cada fila (mismo cálculo que ya usa `recalcular()`, extraído a una
función compartida dentro de cada archivo JS) y derivando el % de bonificación efectivo de cada
línea, en el PDF, a partir de `subtotal` ya guardado — sin tocar ningún cálculo de negocio ni
ninguna columna nueva en base de datos.

NC/ND es un caso aparte por decisión explícita (clarificada en la spec): mantiene el descuento de
línea y el Descuento General separados, igual que Contagram. Ahí el fix es sólo agregar la fila
"Descuento General" que falta en el bloque de totales del PDF — la columna "%Bonif." de línea no
cambia.

## Technical Context

**Language/Version**: PHP 8.2 (Laravel 12), JavaScript (jQuery, sin build de módulos — cada
`resources/js/*.js` es un IIFE autocontenido cargado por Vite, sin imports entre ellos).

**Primary Dependencies**: Eloquent (modelos `PresupuestoItem`, `VentaItem`, `CompraItem`,
`NotaCreditoDebitoItem`), Blade (templates PDF ya existentes), jQuery + Select2 (sin cambios en
esta feature — no se toca ningún `<select>`).

**Storage**: MySQL. Sin migraciones: todos los campos necesarios (`precio_unitario`/`precio`,
`cantidad`, `descuento_pct`, `subtotal` donde existe, `descuento_general_tipo/_pct/_monto`) ya
existen.

**Testing**: PHPUnit (Feature tests) para el cálculo de porcentaje efectivo en los modelos de
ítem y para el contenido del PDF; sin test de JS existente en el proyecto para estas pantallas
(no hay `tests/js/` para presupuestos.js/ventas.js/compras.js/notas-credito-debito.js — se
verifica el cálculo en el modelo, que es la fuente de verdad que también usa el PDF, y el
comportamiento de pantalla se valida manualmente en local per CLAUDE.md).

**Target Platform**: Web (navegador de escritorio), servidor Laravel existente.

**Project Type**: Web application (Laravel + Blade + Vite), un solo proyecto — no aplica
frontend/backend separados.

**Performance Goals**: Sin objetivo nuevo; el cálculo es aritmética simple sobre listas ya en
memoria (ítems de un comprobante, típicamente < 100 líneas), sin queries adicionales.

**Constraints**: No introducir una segunda fuente de verdad para el cálculo de totales — el
total final (pie de página y backend) no puede cambiar de valor como resultado de esta feature
(FR-002, SC-003). No agregar columnas a `nota_credito_debito_items` (no tiene `subtotal`; no se
necesita, según decisión de la spec).

**Scale/Scope**: 4 pantallas de alta/edición (Presupuesto, Venta, Compra, NC/ND) + 4 PDFs, todas
ya construidas — este es un fix acotado sobre código existente, no un módulo nuevo.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **Principio I (Documentación de dominio como fuente de verdad)**: `documentacion_principal_crm.md`
  ya documenta (spec 095) que Contagram mantiene el descuento de línea y el Descuento General de
  NC/ND separados — esta feature no lo contradice, lo confirma y completa (agrega la fila de
  totales que faltaba mencionar). Para Presupuesto/Venta/Compra, el doc no cubre hoy el detalle de
  la columna "Bonif." combinada — se agrega esa precisión en el mismo cambio, antes de `tasks`. ✅ Cumple (con acción pendiente en Fase 1: actualizar el doc).
- **Principio II (Desarrollo spec-driven)**: cadena completa specify→clarify→plan→...→analyze en
  curso. ✅ Cumple.
- **Principio III (Corrección fiscal ARCA)**: no aplica directamente — no se toca CAE, numeración
  ni condición de IVA. El único punto de contacto es que el PDF de Venta puede llevar CAE ya
  aprobado; esta feature no cambia el `total` ni el `subtotal_con_iva` que ya se usó para pedir el
  CAE, sólo la etiqueta de la columna "Bonif." — no hay riesgo de inconsistencia con lo ya
  presentado a ARCA. ✅ Cumple.
- **Principio IV (Testing donde hay dinero o impacto fiscal)**: esta feature toca directamente
  presentación de descuentos/importes — requiere tests. Se agregan tests de Feature sobre el
  cálculo de porcentaje efectivo en los modelos de ítem (casos: sólo general, sólo línea, ambos
  combinados, precio/cantidad cero) y sobre el contenido de los 4 PDFs. ✅ Cumple (ver Fase 1).
- **Principio V (Convenciones Laravel + dominio en español)**: nombres de método en español
  (`bonifEfectivaPct`, `bonifEfectivaEtiqueta`), sin nuevas tablas ni convenciones ajenas al
  proyecto. ✅ Cumple.

**Resultado**: PASS. Sin violaciones que requieran justificación en Complexity Tracking.

**Re-check post Fase 1**: el diseño final (data-model.md, contracts/) no introdujo ningún campo
nuevo en base de datos ni ninguna dependencia externa. El único método backend no anticipado en el
Summary inicial es `NotaCreditoDebito::montoDescuentoGeneral()` (research.md Decisión 5) — no
representa una violación: replica un algoritmo ya existente en JS, sin nueva tabla ni nueva regla
de negocio. Constitution Check se mantiene PASS sin cambios.

## Project Structure

### Documentation (this feature)

```text
specs/098-bonif-efectiva-descuento-general/
├── plan.md              # This file
├── research.md          # Phase 0 output
├── data-model.md         # Phase 1 output
├── quickstart.md         # Phase 1 output
├── contracts/             # Phase 1 output
└── tasks.md              # Phase 2 output (/speckit-tasks, not created here)
```

### Source Code (repository root)

```text
app/
├── Models/
│   ├── PresupuestoItem.php       # + bonifEfectivaPct(), bonifEfectivaEtiqueta()
│   ├── VentaItem.php              # + bonifEfectivaPct(), bonifEfectivaEtiqueta()
│   ├── CompraItem.php             # + bonifEfectivaPct(), bonifEfectivaEtiqueta()
│   │   (NotaCreditoDebitoItem.php NO se toca — FR-008)
│   └── NotaCreditoDebito.php     # + montoDescuentoGeneral() (research.md Decisión 5)

resources/
├── js/
│   ├── presupuestos.js            # renderItems() usa factorDescuentoGeneral(); recalcular() lo reusa
│   ├── ventas.js                  # idem
│   ├── compras.js                 # idem
│   └── notas-credito-debito.js    # NO se toca (FR-008) — renderItems()/recalcular() sin cambios
└── views/
    ├── presupuestos/pdf.blade.php       # columna "Bonif." usa bonifEfectivaEtiqueta()
    ├── ventas/pdf.blade.php             # idem
    ├── compras/pdf.blade.php            # idem
    └── notas-credito-debito/pdf.blade.php  # + fila "Descuento General" en el bloque de totales

tests/
├── Unit/
│   ├── BonifEfectivaCalculoTest.php     # nuevo — cálculo de % efectivo en memoria, sin persistir (casos límite incluidos)
│   └── NotaCreditoDebitoMontoDescuentoGeneralTest.php  # nuevo — cálculo del importe de Descuento General, en memoria
└── Feature/
    ├── PresupuestoPdfBonifTest.php          # nuevo — PDF real con Descuento General
    ├── VentaPdfBonifTest.php                # idem
    ├── CompraPdfBonifTest.php               # idem
    └── NotaCreditoDebitoPdfDescuentoGeneralTest.php  # nuevo — fila de totales en el PDF real

docs/
└── documentacion_principal_crm.md  # se agrega el detalle de "Bonif. combinada" en Presupuesto/
                                      # Venta/Compra, junto al bloque ya existente de NC/ND
```

**Structure Decision**: Proyecto único Laravel + Blade + Vite (sin frontend/backend separados).
Se añaden métodos a los 3 modelos de ítem que comparten estructura (`PresupuestoItem`,
`VentaItem`, `CompraItem`) en vez de crear un trait o clase compartida: cada modelo ya duplica su
propia lógica simple (mismo patrón que el proyecto usa hoy — no hay abstracción compartida entre
estos tres modelos, y forzar una ahora sería una complejidad no pedida por la spec). Mismo
criterio en JS: `factorDescuentoGeneral()` se define una vez por archivo (`presupuestos.js`,
`ventas.js`, `compras.js`), no como módulo importado — consistente con que estos archivos ya son
IIFEs independientes sin sistema de imports entre ellos en el proyecto.

## Complexity Tracking

*Sin violaciones de la constitución — tabla no aplica.*
