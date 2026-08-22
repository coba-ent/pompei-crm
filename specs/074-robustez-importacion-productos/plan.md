# Implementation Plan: Robustez del importador de Productos (stock concurrente y auditoría de precios)

**Branch**: `074-robustez-importacion-productos` | **Date**: 2026-08-22 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/074-robustez-importacion-productos/spec.md`

## Summary

Dos correcciones de robustez sobre el circuito real de edición masiva (exportar Productos a Excel →
editar → reimportar):

1. **Stock atómico**: el importador hoy lee el stock actual fuera de la transacción que después lo
   escribe, así que una venta concurrente puede perderse. Se agrega `StockService::fijar()`, que recibe
   la cantidad **deseada** y resuelve lectura + cálculo del delta + escritura del movimiento dentro de
   una única transacción con `lockForUpdate()`. El importador pasa a usarla.

2. **Auditoría de precios**: los precios por lista se sobrescriben sin dejar rastro del valor anterior.
   Se registra un evento de auditoría por cada creación, modificación o eliminación de precio,
   implementado en `PrecioProductoObserver` — el punto único por el que ya pasan todas las escrituras de
   precio hechas vía modelo. Eso cubre de una sola vez la importación, la edición manual, la **edición
   masiva de precios/costos** del listado (el camino de mayor riesgo, detectado durante el relevamiento y
   ausente del pedido original) y la copia de producto. Se reutiliza el mecanismo de spec 054
   (`LogAuditoria` / `AuditoriaService`), sumando el tipo de operación `precio_producto`.

Dos consecuencias del relevamiento que el plan asume explícitamente: el borrado de precios de
`sincronizarPrecios()` debe pasar de mass delete a borrado por modelo (si no, no dispara eventos y ese
caso queda sin auditar), y el registro de auditoría durante la importación se agrupa en lote para no
comerse el presupuesto de tiempo de la tanda.

## Technical Context

**Language/Version**: PHP 8.2 / Laravel 12

**Primary Dependencies**: Eloquent (Observers, transacciones, `lockForUpdate`); Maatwebsite Excel (ya en
uso por el importador, sin cambios en esta feature)

**Storage**: MySQL — tablas involucradas: `stocks`, `movimientos_stock`, `precios_producto`,
`logs_auditoria`

**Testing**: PHPUnit (`tests/Feature`, `tests/Unit`)

**Target Platform**: aplicación web Laravel single-tenant (XAMPP local; VPS en producción)

**Project Type**: aplicación web monolítica (Laravel + Blade)

**Performance Goals**: una tanda de 1.000 filas del asistente sigue completándose dentro del margen
actual, incluyendo el registro de auditoría de todas las listas de precio activas de esas filas
(SC-005). Referencia dura: el proxy delante de PHP-FPM corta ~60 s.

**Constraints**: la auditoría nunca aborta ni revierte la operación auditada (FR-012); la UI del
asistente de importación no cambia (FR-015); la sincronización de precios hacia Mercado Libre/Tiendanube
sigue funcionando igual (FR-017)

**Scale/Scope**: planillas de miles de productos; unidades de listas de precio y depósitos activos

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principio | Evaluación | Estado |
|---|---|---|
| **I — Documentación de dominio como fuente de verdad** | La feature agrega un valor al enum `tipo_operacion` de `logs_auditoria` y un tipo de operación auditable nuevo. **Obliga** a actualizar `docs/modelo_datos.md` (§`logs_auditoria`) y `docs/documentacion_principal_crm.md` **antes de `/speckit-tasks`**. Además debe documentarse la excepción de FR-009a (escrituras de precio por query builder crudo que quedan sin auditar). | ⚠️ Acción requerida, planificada |
| **II — Desarrollo spec-driven** | La feature entra por el flujo completo de spec-kit; no es un cambio trivial exento. | ✅ |
| **III — Corrección fiscal (ARCA)** | No toca comprobantes, CAE, numeración ni condición de IVA. No aplica. | ✅ N/A |
| **IV — Testing donde hay dinero o impacto fiscal** | Aplica de lleno: la feature toca **movimientos de stock** y **precios**, ambos listados explícitamente como áreas de testing obligatorio. Se exigen tests de: atomicidad de `fijar()` bajo concurrencia, no-generación de movimiento cuando no hay diferencia, y auditoría de precio en los cuatro orígenes + el caso "sin cambio no audita". | ✅ Planificado |
| **V — Convenciones Laravel + dominio en español** | Nombres en español (`fijar`, `OrigenCambioPrecio`, `precio_producto`); se usa el patrón Observer/Service ya vigente en el proyecto en lugar de pelear contra el framework; sin `empresa_id` (single-tenant). | ✅ |

**Resultado del gate**: pasa. Sin violaciones que justificar → *Complexity Tracking* vacío.

**Re-evaluación post-Phase 1**: sin cambios. El diseño no agrega proyectos, capas ni patrones nuevos:
extiende un servicio existente (`StockService`), un observer existente (`PrecioProductoObserver`) y un
servicio de auditoría existente (`AuditoriaService`). La única clase nueva es un contexto chico de
origen (`OrigenCambioPrecio`), justificada en research.md D4.

## Project Structure

### Documentation (this feature)

```text
specs/074-robustez-importacion-productos/
├── plan.md              # Este archivo
├── spec.md              # Especificación funcional
├── research.md          # Fase 0 — relevamiento y decisiones D1-D9
├── data-model.md        # Fase 1 — cambios de esquema y forma de los eventos
├── quickstart.md        # Fase 1 — guía de validación manual y automatizada
├── contracts/
│   ├── stock-service-fijar.md      # Contrato de la nueva operación de stock
│   └── auditoria-precio-producto.md # Contrato del evento de auditoría de precio
├── checklists/
│   └── requirements.md  # Checklist de calidad de la spec
└── tasks.md             # Fase 2 — generado por /speckit-tasks
```

### Source Code (repository root)

```text
app/
├── Services/
│   ├── Stock/
│   │   └── StockService.php              # [MOD] + fijar(): stock a valor absoluto, atómico
│   ├── Import/
│   │   └── ImportadorFilas.php           # [MOD] usa fijar(); activa el buffer de auditoría
│   └── AuditoriaService.php              # [MOD] + modo buffer (iniciarBuffer/vaciarBuffer)
├── Observers/
│   └── PrecioProductoObserver.php        # [MOD] + auditoría en saved() y nuevo deleted()
├── Support/
│   └── OrigenCambioPrecio.php            # [NUEVO] contexto de origen del cambio de precio
├── Http/Controllers/
│   ├── ProductoController.php            # [MOD] borrado de precios por modelo; declara origen
│   └── AuditoriaController.php           # [MOD] + label 'precio_producto' en LABELS_OPERACION
└── Models/
    └── LogAuditoria.php                  # sin cambios (el enum vive en la migración)

database/migrations/
├── 2026_08_XX_XXXXXX_agregar_precio_producto_a_tipo_operacion.php  # [NUEVO] ALTER sólo bajo MySQL
└── 2026_08_07_155244_create_logs_auditoria_table.php               # [MOD] + valor en el enum, para SQLite (tests)

tests/
└── Feature/
    ├── StockFijarConcurrenciaTest.php    # [NUEVO] atomicidad + no-movimiento sin diferencia
    ├── AuditoriaPrecioProductoTest.php   # [NUEVO] los 4 orígenes + sin-cambio-no-audita
    └── ImportacionProductosStockTest.php # [MOD/NUEVO] no-regresión del importador

docs/
├── modelo_datos.md                       # [MOD] §logs_auditoria: nuevo valor de enum
└── documentacion_principal_crm.md        # [MOD] auditoría de precios + excepción FR-009a
```

**Structure Decision**: no se introduce estructura nueva. La feature es una corrección quirúrgica sobre
tres servicios y un observer ya existentes, siguiendo el layout estándar de Laravel que el proyecto ya
usa. La única incorporación es `app/Support/OrigenCambioPrecio.php`; se elige `app/Support/` por ser
código de infraestructura de dominio que no es ni modelo, ni servicio de negocio, ni controlador.

## Complexity Tracking

> Sin violaciones de la Constitución que justificar. Sección intencionalmente vacía.
