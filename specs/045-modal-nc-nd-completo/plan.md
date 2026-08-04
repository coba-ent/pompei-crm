# Implementation Plan: Modal "Nueva Nota de Crédito/Débito" completo (Compras y Ventas)

**Branch**: `045-modal-nc-nd-completo` | **Date**: 2026-08-04 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/045-modal-nc-nd-completo/spec.md`

## Summary

Completar el modal "Nueva Nota de Crédito/Débito" (Compras y Ventas) con los tres campos que hoy
le faltan frente a Contagram real: "Documento que Ajusta" como selector de sólo lectura (en vez
de input deshabilitado), "¿Queres que afecte Stock?" con selector de productos/cantidades y
depósito (activando por primera vez la lógica `afecta_stock`/`items`/`deposito_id` que el backend
ya soporta pero nunca se usa), y "Mes de Imputación" (campo nuevo, columna nueva en
`notas_credito_debito`). Enfoque: extender el wizard de 2 pasos existente (`_modal_ncnd.blade.php`
+ `compras.js`/`ventas.js` + `NotaCreditoDebitoController`) sin rediseñar su arquitectura.

## Technical Context

**Language/Version**: PHP 8.2, Laravel 12

**Primary Dependencies**: Eloquent ORM, Blade, Bootstrap 5 (NexaDash), jQuery + Select2, DataTables (no aplica a este modal en particular, pero es el patrón de frontend del proyecto)

**Storage**: MySQL/MariaDB — nueva columna `mes_imputacion` en `notas_credito_debito` (migración)

**Testing**: PHPUnit (Feature tests sobre `NotaCreditoDebitoController`), foco en la lógica de stock/cantidad-máxima por ser dinero+stock (Principio IV de la constitución)

**Target Platform**: Web (mismo hosting/VPS ya documentado en CREDENCIALES_ACCESO.txt)

**Project Type**: Aplicación web monolítica Laravel + Blade (no hay separación frontend/backend como proyectos distintos)

**Performance Goals**: N/A — modal de uso manual, sin requisitos de throughput

**Constraints**: Reusar el wizard de 2 pasos existente; no modificar el flujo de emisión de CAE de la nota; mantener paridad exacta de campos entre el modal de Compras y el de Ventas (User Story 3)

**Scale/Scope**: 2 vistas de modal (Compras, Ventas), 1 controller compartido (`NotaCreditoDebitoController`), 1 migración, 1 FormRequest, JS de ambos módulos

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **Principio I (Documentación de dominio como fuente de verdad)**: `docs/documentacion_principal_crm.md` y `docs/modelo_datos.md` describen `notas_credito_debito` sin `mes_imputacion` — el campo nuevo se agrega a ambos docs en este mismo plan (ver Fase 1 y checklist de tasks), antes de `/speckit-tasks`. PASA (con la actualización pendiente marcada explícitamente).
- **Principio II (Desarrollo spec-driven)**: esta feature sigue el flujo completo specify→clarify→plan→checklist→tasks→analyze. PASA.
- **Principio III (Corrección fiscal ARCA)**: no se toca el cálculo de neto/IVA ni la emisión de CAE de la nota (`emitirComprobanteFiscalNota` queda intacto); el `mes_imputacion` es un dato informativo para el Contador, no fiscal-ARCA. PASA, sin impacto.
- **Principio IV (Testing donde hay dinero o impacto fiscal)**: la nueva lógica de "afecta stock" mueve dinero (nota) y stock real — requiere tests Feature sobre: tope de cantidad por producto (no exceder lo pendiente del comprobante original), signo del movimiento de stock según tipo+módulo (ya cubierto en parte por tests existentes de `NotaCreditoDebitoController`, hay que extenderlos), y validación de depósito obligatorio cuando afecta_stock=true. Se listan como tareas obligatorias, no opcionales.
- **Principio V (Convenciones Laravel + español)**: nueva columna `mes_imputacion` (snake_case, español), sin `empresa_id` ni scopes multi-tenant. PASA.

Sin violaciones. No se requiere la tabla de Complexity Tracking.

## Project Structure

### Documentation (this feature)

```text
specs/045-modal-nc-nd-completo/
├── plan.md              # This file
├── research.md          # Phase 0 output
├── data-model.md         # Phase 1 output
├── quickstart.md         # Phase 1 output
├── contracts/            # Phase 1 output (endpoint ya existente, se documenta el contrato ampliado)
└── tasks.md              # Phase 2 output (/speckit-tasks — no se crea acá)
```

### Source Code (repository root)

Aplicación Laravel monolítica única (no hay separación de proyectos backend/frontend):

```text
app/
├── Http/
│   ├── Controllers/NotaCreditoDebitoController.php      # store() y storeCompra(): agregar mes_imputacion, exponer afecta_stock real
│   └── Requests/StoreNotaCreditoDebitoRequest.php        # reglas: mes_imputacion, tope de cantidad por producto
├── Models/
│   ├── NotaCreditoDebito.php                              # fillable/casts: + mes_imputacion
│   └── Compra.php / Venta.php                             # posible helper "itemsPendientesDeAjuste()" reutilizado por ambos módulos

database/migrations/
└── 2026_08_04_xxxxxx_add_mes_imputacion_to_notas_credito_debito.php

resources/
├── views/
│   ├── compras/_modal_ncnd.blade.php                      # + selector Documento (solo lectura), toggle Stock, selector productos+depósito, Mes de Imputación
│   ├── ventas/_modal_ncnd.blade.php                        # mismo agregado, paridad de estructura (User Story 3)
│   ├── compras/detalle.blade.php                           # tabla NC/ND: + columna Mes de Imputación
│   └── ventas/detalle.blade.php                             # ídem
└── js/
    ├── compras.js                                           # wizard: paso "afecta stock" con Select2 de productos del comprobante, cálculo de máximo disponible
    └── ventas.js                                             # ídem, mismo comportamiento

tests/Feature/
└── NotaCreditoDebitoTest.php                               # (existente o nuevo) casos: afecta_stock true/false, tope de cantidad, mes_imputacion obligatorio, paridad Compras/Ventas
```

**Structure Decision**: Se extiende la estructura ya existente (controller único compartido entre
Compras y Ventas, un modal Blade por módulo, un archivo JS por módulo) — no se introduce ninguna
carpeta ni patrón nuevo. Es el mismo patrón usado por el resto del proyecto para funcionalidades
espejadas entre ambos módulos.

## Complexity Tracking

*No aplica — sin violaciones de la Constitution Check.*
