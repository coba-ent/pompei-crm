# Implementation Plan: Cada línea del comprobante es un ajuste independiente en la NC/ND

**Branch**: `096-lineas-independientes-ncnd` | **Date**: 2026-09-03 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/096-lineas-independientes-ncnd/spec.md`

## Summary

`AjustesPendientesNotaCreditoDebito::itemsDisponibles()` agrupa las líneas del comprobante de origen
por `producto_id` (`groupBy('producto_id')`), quedándose con el precio/bonificación de la **primera**
línea de cada grupo pero sumando la cantidad pendiente de todas. Cuando el mismo producto aparece en
más de una línea con precio o bonificación distintos, esto funde varias operaciones comerciales
distintas en una sola fila y el total propuesto por la NC/ND queda mal — verificado en producción:
venta 24854 (3 líneas del mismo producto, total real $94.380) proponía $47.190, la mitad.

El fix cambia la identidad de "qué se está ajustando" de `producto_id` a **la línea concreta del
comprobante** (`venta_items.id` / `compra_items.id`). Requiere: (1) una migración que agregue esa
referencia a `nota_credito_debito_items`, (2) reescribir `itemsDisponibles()` y `pendiente()` para
operar por línea con fallback agregado (FR-006, clarificación 2026-09-03) cuando ninguna nota
existente de ese producto tiene la referencia nueva, y (3) el JS del formulario, que hoy matchea por
`producto_id` al reconstruir ítems en edición, pase a matchear por la referencia de línea cuando esté
disponible.

## Technical Context

**Language/Version**: PHP 8.2 / Laravel 12

**Primary Dependencies**: Eloquent, Blade, jQuery (template NexaDash), Vite

**Storage**: MySQL. **Requiere migración**: `nota_credito_debito_items` gana una columna nullable de
referencia a la línea de origen (`venta_item_id` o `compra_item_id`, mutuamente excluyentes según si
la nota es de Venta o de Compra — ver data-model.md). Nullable porque las notas ya existentes no
tienen ese dato y no se retro-completa (spec, Assumptions).

**Testing**: PHPUnit (Feature). Principio IV: obligatorio por tocar cálculo de importes y de cantidad
pendiente de ajuste.

**Target Platform**: aplicación web, navegador de escritorio

**Project Type**: web (Laravel monolítico con Blade + JS por pantalla)

**Performance Goals**: sin impacto — mismo volumen de consultas, sólo cambia la clave de agrupación
(por id de línea en vez de por producto_id) y se agrega una columna a un JOIN/where ya existente.

**Constraints**:
- No se puede reescribir ni migrar retroactivamente las NC/ND ya existentes (spec, Assumptions):
  no hay forma de reconstruir a qué línea correspondió cada ajuste histórico.
- El fallback agregado (FR-006) tiene que convivir en el mismo método sin bifurcar en dos
  implementaciones paralelas — un único cálculo que decide su granularidad según si existe o no
  alguna nota con referencia de línea para ese producto.
- No se toca la spec 095 (cabecera/descuento general/tipo de comprobante): este fix es ortogonal,
  vive en la capa de identificación de líneas de ítems.
- La edición de una NC/ND ya existente sigue sin depender del comprobante de origen (spec 095,
  FR-011) — este fix no cambia esa regla.

**Scale/Scope**: 2 flujos (Ventas y Compras), 1 migración, 1 servicio, 1 archivo JS. Afecta 47 ventas
y 199 compras con producto repetido (verificado en producción); de ésas, 41 (3+38) ya tienen NC/ND
creada con el método agregado viejo y caen en el fallback de FR-006.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principio | Estado | Cómo se cumple |
| --- | --- | --- |
| **I. Documentación de dominio como fuente de verdad** | ✅ Pasa | El bug y su alcance (47 ventas, 199 compras, 41 con NC/ND ya creada) se verificaron con consultas directas contra la base de producción, no por criterio propio. |
| **II. Desarrollo spec-driven** | ✅ Pasa | Spec 096 con 1 clarificación resuelta antes de planear (fallback agregado para notas viejas). |
| **III. Corrección fiscal innegociable (ARCA)** | ✅ Pasa, y **corrige** un riesgo existente | El bug hacía que una NC/ND pudiera nacer por la mitad (o menos) del importe real de un comprobante fiscal cuando repetía producto. El fix no toca emisión de CAE ni soft delete; sólo corrige qué cantidad/precio se ofrece ajustar. |
| **IV. Testing donde hay dinero o impacto fiscal** | ✅ Pasa | Toca cantidad pendiente de ajuste y precio por línea: tests obligatorios sobre producto repetido (precarga, guardado, segunda nota), y el caso de fallback FR-006 sobre datos "viejos" simulados. |
| **V. Convenciones Laravel + dominio en español** | ✅ Pasa | Se extiende el servicio existente; nomenclatura de columnas sigue el patrón `venta_item_id`/`compra_item_id` ya usado en el proyecto para FKs polimórficas por tipo de comprobante. |

**Resultado**: sin violaciones. No se requiere Complexity Tracking.

**Re-evaluación post-diseño (Phase 1)**: sin cambios. El diseño confirma que basta una migración
aditiva (columnas nullable) y no requiere tocar la tabla `venta_items`/`compra_items` ni introducir
entidades nuevas.

## Project Structure

### Documentation (this feature)

```text
specs/096-lineas-independientes-ncnd/
├── plan.md              # Este archivo
├── spec.md              # Especificación con 1 clarificación
├── research.md          # Phase 0
├── data-model.md         # Phase 1
├── quickstart.md         # Phase 1
├── contracts/
│   └── items-disponibles-por-linea.md
├── checklists/
│   └── requirements.md
└── tasks.md              # Lo genera /speckit-tasks
```

### Source Code (repository root)

```text
database/migrations/
└── <timestamp>_add_referencia_linea_a_nota_credito_debito_items.php

app/
├── Models/
│   └── NotaCreditoDebitoItem.php          # $fillable + relaciones ventaItem()/compraItem()
├── Http/Controllers/
│   └── NotaCreditoDebitoController.php    # store()/storeCompra(): persisten la referencia de línea
└── Services/
    └── AjustesPendientesNotaCreditoDebito.php  # itemsDisponibles()/pendiente() por línea + fallback

resources/js/
└── notas-credito-debito.js                # match por referencia de línea al reconstruir en edición

tests/Feature/
└── NotaCreditoDebitoLineasIndependientesTest.php   # archivo nuevo, dedicado a este fix
```

**Structure Decision**: mismo patrón de la spec 095 — se extiende el servicio de precarga existente,
sin capas nuevas. La única pieza nueva de infraestructura es la migración aditiva.

## Phase 0: Research

Ver [research.md](./research.md).

## Phase 1: Design & Contracts

- [data-model.md](./data-model.md): columna nueva, nullable, en `nota_credito_debito_items`;
  regla de fallback agregado vs. por línea.
- [contracts/items-disponibles-por-linea.md](./contracts/items-disponibles-por-linea.md): forma de
  retorno de `itemsDisponibles()` (agrega `item_origen_id` al array existente) y del payload que el
  front envía al guardar.
- [quickstart.md](./quickstart.md): cómo verificar contra la venta 24854 real y contra un caso con
  fallback simulado.

## Complexity Tracking

No aplica: el Constitution Check pasó sin violaciones.
