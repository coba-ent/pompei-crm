# Implementation Plan: Descuento general aplicado proporcionalmente a neto e IVA

**Branch**: `044-descuento-general-iva` | **Date**: 2026-08-04 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/044-descuento-general-iva/spec.md`

## Summary

Corregir `app/Services/Ingresos/CalculoComprobante.php` para que `descuento_general_pct` se aplique
proporcionalmente tanto al neto como al IVA de cada ítem (factor `1 - pct/100` sobre
`subtotal` y `subtotal_con_iva` de cada línea), en vez del comportamiento actual, que resta el
descuento general (calculado sólo sobre el neto) directamente del total-con-IVA sin descontar,
dejando el IVA facturado sin relación matemática real con el neto declarado. Esto es la causa raíz de
que spec 042 (`ValidadorDatosFiscales`) rechace por inconsistencia de importes cualquier Venta con
descuento general antes de contactar a ARCA. Afecta a Presupuestos y Ventas por igual (mismo servicio
compartido). No se toca nada de `app/Services/Arca/` (spec 042) ni de NC/ND (que no depende de este
servicio) — el fix vive enteramente en `CalculoComprobante`.

## Technical Context

**Language/Version**: PHP 8.2, Laravel 12

**Primary Dependencies**: `App\Services\Ingresos\CalculoComprobante` (único punto de cálculo,
consumido por `VentaController::store()`/`update()` y `PresupuestoController` equivalente)

**Storage**: MySQL/MariaDB — sin cambios de esquema (mismos campos `subtotal`, `subtotal_con_iva` en
`venta_items`/`presupuesto_items`, y `subtotal_sin_descuento`/`descuento`/`subtotal_con_descuento`/
`total` en `ventas`/`presupuestos`; sólo cambia el valor calculado, no la forma)

**Testing**: PHPUnit (Unit test sobre `CalculoComprobante::calcular()` cubriendo el caso real de
referencia — Venta 0001-00016359 — y el caso sin descuento general como no-regresión; Feature test
extendiendo `EmisionComprobanteVentaTest` de spec 042 para confirmar que una Venta con descuento
general ya no es rechazada por `ValidadorDatosFiscales`)

**Target Platform**: Web server Laravel (hosting compartido + VPS), sin cambios de infraestructura

**Project Type**: Web application (Laravel monolito) — corrección interna de un servicio de cálculo
puro existente, sin nuevas pantallas ni endpoints

**Performance Goals**: N/A — mismo volumen de cálculo, sin impacto de throughput

**Constraints**: la corrección DEBE ser transparente (mismo resultado exacto) para comprobantes sin
descuento general (`descuento_general_pct` 0 o ausente) — sólo cambia el resultado para comprobantes
que sí tienen descuento general, y siempre hacia un total menor (nunca mayor) al actual

**Scale/Scope**: cambio interno en 1 archivo de servicio (`CalculoComprobante.php`) — sin tablas,
colas ni endpoints nuevos; efecto observable en 2 controladores que ya lo consumen sin cambiarlos
(`VentaController`, `PresupuestoController`)

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **Principio I (Documentación de dominio como fuente de verdad)**: el cálculo de descuento general no
  está descripto en detalle en `docs/documentacion_principal_crm.md` más allá de mencionar el campo —
  no requiere actualizar la doc de dominio, es una corrección de fórmula interna. Cumple.
- **Principio II (Desarrollo spec-driven)**: corrección de lógica de cálculo fiscal — pasa por el
  flujo completo de spec-kit (esta spec). Cumple.
- **Principio III (Corrección fiscal innegociable — ARCA)**: esta spec **refuerza** directamente este
  principio — corrige la causa raíz por la cual una Venta con descuento general no puede declarar un
  IVA matemáticamente consistente ante ARCA (bloqueado desde spec 042). Cumple.
- **Principio IV (Testing donde hay dinero o impacto fiscal)**: se agrega test unitario sobre
  `CalculoComprobante` con el caso real que disparó el hallazgo (Venta 0001-00016359) y test feature
  confirmando que spec 042 ya no rechaza esa Venta. Cumple.
- **Principio V (Convenciones Laravel + dominio en español)**: sin cambios de convención — se corrige
  el mismo servicio ya nombrado en español (`CalculoComprobante`). Cumple.

Sin violaciones. No aplica Complexity Tracking.

## Project Structure

### Documentation (this feature)

```text
specs/044-descuento-general-iva/
├── plan.md              # This file (/speckit-plan command output)
├── research.md          # Phase 0 output (/speckit-plan command)
├── data-model.md         # Phase 1 output (/speckit-plan command)
├── quickstart.md         # Phase 1 output (/speckit-plan command)
├── checklists/
│   └── requirements.md   # Spec quality checklist (/speckit-specify)
└── tasks.md               # Phase 2 output (/speckit-tasks command)
```

### Source Code (repository root)

```text
app/
└── Services/Ingresos/
    └── CalculoComprobante.php   # aplica el descuento general proporcionalmente a neto e IVA
                                   por ítem, en vez de restarlo del total ya con IVA sumado

# NOTA: VentaController y PresupuestoController NO cambian — ya consumen
# CalculoComprobante::calcular() y persisten lo que ese método devuelve tal cual (guardarItems()).
# EmisorComprobante/MapeadorComprobante/ValidadorDatosFiscales (spec 042) tampoco cambian — ya
# construyen la solicitud a ARCA a partir de venta_items.subtotal/iva_pct, que ahora vienen
# correctos desde el origen.

tests/
├── Unit/Services/Ingresos/
│   └── CalculoComprobanteTest.php   # nuevo — caso real (Venta 0001-00016359), no-regresión sin
│                                      descuento general, caso con múltiples alícuotas
└── Feature/
    └── EmisionComprobanteVentaTest.php   # existente (spec 042) — se extiende con el caso de
                                            Venta con descuento general que hoy es rechazada
```

**Structure Decision**: corrección quirúrgica de un único servicio de cálculo puro
(`CalculoComprobante`), sin tocar los controladores que lo consumen ni los servicios de ARCA de spec
042 — éstos ya leen los valores persistidos de `venta_items`/`ventas`, que pasan a ser correctos
desde el origen.

## Complexity Tracking

*No aplica — sin violaciones de la Constitution Check.*
