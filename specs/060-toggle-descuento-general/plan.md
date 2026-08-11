# Implementation Plan: Toggle %/monto fijo para el Descuento General

**Branch**: `main` (sin rama propia, mismo criterio que specs recientes del proyecto) | **Date**: 2026-08-11 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `/specs/060-toggle-descuento-general/spec.md`

## Summary

Agregar un botón inline (%/$) junto al campo "Descuento General" en los formularios de alta y
edición de Presupuestos, Ventas, Compras y Notas de Crédito/Débito, permitiendo cargar ese descuento
como monto fijo además de porcentaje. Enfoque técnico: en vez de duplicar la lógica de prorrateo
proporcional a neto e IVA (spec 044), `CalculoComprobante::calcular()` (compartido por Presupuestos,
Ventas y Compras) pasa a recibir tipo+valor del descuento general y convierte internamente el monto
fijo a un porcentaje efectivo (`valor / subtotal_sin_descuento * 100`) antes de aplicar exactamente el
mismo algoritmo ya validado. Persistencia: se agrega una columna `descuento_general_tipo` (enum
porcentaje/monto) a las cuatro tablas, y `descuento_general_monto` donde falte, para reabrir el
formulario mostrando el mismo modo/valor cargado (FR-004/FR-005). NC/ND no usa `CalculoComprobante`
(cálculo propio client-side, `monto / 1.21`) — se le agregan las mismas columnas sólo para
persistencia/reapertura (FR-010), sin tocar su arquitectura de cálculo actual.

## Technical Context

**Language/Version**: PHP 8.2 (Laravel 12), JavaScript ES2019 (vanilla + jQuery, sin build de
componentes)

**Primary Dependencies**: Eloquent ORM, Blade, Bootstrap 5 (NexaDash), jQuery + Select2 (no aplica a
este campo puntual), Vite para el build de `resources/js/*`

**Storage**: MySQL (XAMPP local / mismo esquema en demo e VPS) — 4 migraciones nuevas (una por tabla:
`ventas`, `presupuestos`, `compras`, `notas_credito_debito`)

**Testing**: PHPUnit — `tests/Unit/TotalesVentaTest.php`, `TotalesPresupuestoTest.php`,
`TotalesCompraTest.php`, `tests/Unit/Services/Ingresos/CalculoComprobanteTest.php`,
`tests/Feature/CompraCalculoTest.php`, `tests/Feature/PresupuestoCalculoTest.php` — todos ya cubren el
modo porcentaje y son la base para los casos nuevos en modo monto fijo

**Target Platform**: Web (Laravel Blade, hosting compartido demo + VPS propio)

**Project Type**: Web-service monolítico (backend + Blade, sin frontend separado)

**Performance Goals**: N/A — cálculo síncrono sobre a lo sumo decenas de ítems por comprobante, sin
impacto de performance distinto al ya existente

**Constraints**: El servidor SIEMPRE recalcula al guardar y nunca confía en los totales enviados por
el cliente (regla ya documentada en `CalculoComprobante`, docblock) — el modo monto fijo debe respetar
esa misma garantía para Presupuestos/Ventas/Compras. Para NC/ND, que hoy sí confía en el `monto` final
calculado en el navegador (arquitectura preexistente, fuera de alcance de este spec cambiarla), sólo
se agrega persistencia de tipo/valor — no se introduce recálculo server-side nuevo ahí.

**Scale/Scope**: 4 tablas (migraciones), 1 servicio compartido (`CalculoComprobante`), 3 controllers +
3 pares de FormRequest (Venta/Presupuesto/Compra, Store+Update), 1 controller + su FormRequest
(NotaCreditoDebito, Store+Update ambos módulos Venta/Compra), 4 vistas Blade (form de cada módulo,
alta+edición comparten la misma vista en los 4 casos), 4 archivos JS (`ventas.js`, `presupuestos.js`,
`compras.js`, `notas-credito-debito.js`).

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **Principio I (Documentación de dominio)**: `docs/documentacion_principal_crm.md` y
  `docs/modelo_datos.md` deben actualizarse con el nuevo campo de descuento general (tipo + monto) en
  los 4 módulos antes de `/speckit-tasks` — ver Fase 1 más abajo. ✅ Planificado.
- **Principio II (Spec-driven)**: esta feature de negocio (cambia cálculo de totales/IVA) pasa por el
  flujo completo specify→clarify→plan→checklist→tasks→analyze. ✅ En curso.
- **Principio III (Corrección fiscal ARCA)**: el modo monto fijo para Ventas/Presupuestos reutiliza el
  mismo prorrateo proporcional neto/IVA que spec 044 ya validó contra `ValidadorDatosFiscales` — no se
  introduce una fórmula nueva de IVA, sólo una conversión previa monto→pct efectivo. Comprobantes con
  CAE ya aprobado no se recalculan retroactivamente (mismo criterio de spec 042/044). ✅ Sin
  violaciones.
- **Principio IV (Testing donde hay dinero/impacto fiscal)**: el cálculo de descuento/IVA/total está
  exactamente en ese perímetro — se extienden los tests unitarios de `CalculoComprobante` y los tests
  de totales de los 3 módulos con casos de modo monto fijo (incluida la validación de monto mayor al
  subtotal, FR-007). ✅ Planificado en tasks.
- **Principio V (Convenciones Laravel + dominio en español)**: nombres de columnas/campos en español,
  siguiendo el patrón ya existente (`descuento_general_pct`, `descuento_general_tipo`,
  `descuento_general_monto`). ✅ Sin violaciones.

No hay violaciones que requieran justificación — sección "Complexity Tracking" no aplica.

## Project Structure

### Documentation (this feature)

```text
specs/060-toggle-descuento-general/
├── plan.md              # This file (/speckit-plan command output)
├── research.md          # Phase 0 output (/speckit-plan command)
├── data-model.md         # Phase 1 output (/speckit-plan command)
├── quickstart.md        # Phase 1 output (/speckit-plan command)
├── contracts/           # Phase 1 output (/speckit-plan command)
│   └── toggle-descuento-general.md
└── tasks.md             # Phase 2 output (/speckit-tasks command - NOT created by /speckit-plan)
```

### Source Code (repository root)

```text
app/
├── Services/Ingresos/
│   └── CalculoComprobante.php          # cambia firma de calcular(): tipo+valor de descuento general
├── Http/Controllers/
│   ├── VentaController.php             # store/update: pasa tipo+valor al servicio, persiste columnas nuevas
│   ├── PresupuestoController.php       # ídem
│   ├── CompraController.php            # ídem
│   └── NotaCreditoDebitoController.php # store/storeCompra/update/updateCompra: persiste tipo+valor
├── Http/Requests/
│   ├── StoreVentaRequest.php / UpdateVentaRequest.php
│   ├── StorePresupuestoRequest.php / UpdatePresupuestoRequest.php
│   ├── StoreCompraRequest.php / UpdateCompraRequest.php
│   └── StoreNotaCreditoDebitoRequest.php / UpdateNotaCreditoDebitoRequest.php
│       # validan descuento_general_tipo (in:porcentaje,monto) + regla condicional de FR-007
└── Models/
    ├── Venta.php / Presupuesto.php / Compra.php / NotaCreditoDebito.php
        # $fillable + casts para las columnas nuevas

database/migrations/
├── <timestamp>_add_descuento_general_tipo_to_ventas_table.php
├── <timestamp>_add_descuento_general_tipo_to_presupuestos_table.php
├── <timestamp>_add_descuento_general_tipo_a_compras_table.php
└── <timestamp>_add_descuento_general_a_notas_credito_debito_table.php

resources/
├── views/ventas/form.blade.php
├── views/presupuestos/form.blade.php
├── views/compras/form.blade.php
├── views/notas-credito-debito/form.blade.php
│   # el botón inline %/$ + wiring de datos iniciales (modo + valor) en los 4
└── js/
    ├── ventas.js
    ├── presupuestos.js
    ├── compras.js
    └── notas-credito-debito.js
        # toggle de modo, limpieza de valor al alternar, recálculo con el modo activo

tests/
├── Unit/Services/Ingresos/CalculoComprobanteTest.php   # casos de monto fijo + prorrateo IVA
├── Unit/TotalesVentaTest.php / TotalesPresupuestoTest.php / TotalesCompraTest.php
└── Feature/*CalculoTest.php, *PaginaTest.php            # alta/edición end-to-end con ambos modos
```

**Structure Decision**: Se reutiliza la estructura MVC + servicios ya existente del proyecto (sin
proyectos/paquetes nuevos). El único punto de cálculo compartido (`CalculoComprobante`) concentra el
cambio de lógica para 3 de los 4 módulos; NC/ND se toca en su controller/request/vista/JS propios sin
pasar por ese servicio, respetando su arquitectura actual (cálculo client-side, sólo se persiste
metadata nueva).

## Complexity Tracking

*(Sin violaciones al Constitution Check — sección no aplica.)*
