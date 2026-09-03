# Implementation Plan: Envío Manual a ARCA para Notas de Crédito/Débito, con IVA real por línea

**Branch**: `097-envio-manual-arca-ncnd` | **Date**: 2026-09-03 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/097-envio-manual-arca-ncnd/spec.md`

## Summary

Sacar el disparo automático de emisión de CAE que hoy ocurre en `NotaCreditoDebitoController::store()`
y `storeCompra()` (al crear la nota, si el comprobante original ya tiene CAE aprobado) y reemplazarlo por
una acción manual "Enviar a ARCA" por nota, dentro del Detalle de Venta/Compra donde ya viven las NC/ND —
mismo patrón de interacción que spec 040 (confirmación explícita, modal de resultado persistente, toast
sólo para rechazos de precondición), pero con modales **propios** de NC/ND (Clarifications). Se reutiliza
sin cambios `EmisorComprobante::emitir()`. Además, se corrige el payload que arma
`emitirComprobanteFiscalNota()`: en vez de calcular neto/IVA fijo al 21% sobre el monto total, arma el
desglose real por alícuota a partir de los campos ya persistidos en cada `NotaCreditoDebitoItem`
(`precio`, `cantidad`, `descuento_pct`, `iva_pct`), aprovechando que `MapeadorComprobante::armarBloquesAlicIva()`
ya sabe recibir un array `items` y agruparlos — con fallback al bloque único agregado cuando algún ítem no
tiene línea de origen identificada (spec 096, `venta_item_id`/`compra_item_id`). Aplica por igual a NC/ND
de Venta y de Compra. Se agrega también un indicador de estado ARCA en el Detalle de Venta y en la vista de
NC/ND (hoy ausente o sólo visible por tooltip), y se corrige la documentación de dominio.

## Technical Context

**Language/Version**: PHP 8.2, Laravel 12

**Primary Dependencies**: Eloquent (`NotaCreditoDebito`, `NotaCreditoDebitoItem`, `ComprobanteFiscal`,
`FuncionAvanzada`), `App\Services\Arca\EmisorComprobante` y `App\Services\Arca\MapeadorComprobante`
(existentes, sin cambios de lógica interna — sólo cambia qué les pasa el controlador), Toastr (NexaDash,
sólo para rechazos de precondición), modales Bootstrap nuevos y propios de NC/ND (no reutilizan los de
Venta de spec 040)

**Storage**: MySQL/MariaDB — sin cambios de esquema (reutiliza `notas_credito_debito`,
`nota_credito_debito_items`, `comprobantes_fiscales`, `funciones_avanzadas`)

**Testing**: PHPUnit (Feature tests), mismo patrón que `EmisionComprobanteNotaCreditoDebitoTest`/
`EnvioManualArcaTest` (spec 034/040) con `EmisorComprobante` mockeado vía `$this->app->bind(...)`

**Target Platform**: Web server Laravel (hosting compartido + VPS), sin cambios de infraestructura

**Project Type**: Web application (Laravel monolito + Blade/Vite) — módulo existente (Ventas/Compras,
sección NC/ND del Detalle)

**Performance Goals**: N/A — acción manual, un envío HTTP por click, sin requisitos de throughput

**Constraints**: el envío es una operación real e irreversible ante ARCA; debe requerir confirmación
explícita del usuario y no debe poder dispararse por accidente (doble click, recarga, etc.) — mismo
resguardo que ya existe en `EmisorComprobante::emitir()`.

**Scale/Scope**: acción sobre la tabla de NC/ND ya existente dentro del Detalle de Venta/Compra; sin
tablas nuevas; 2 rutas nuevas (Venta/Compra) análogas a la ya existente de Venta (spec 040).

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **Principio I (Documentación de dominio como fuente de verdad)**: esta spec corrige
  `docs/documentacion_principal_crm.md` en el mismo cambio que corrige el código (FR-012) — cumple.
- **Principio II (Desarrollo spec-driven)**: corrección de lógica de negocio fiscal — pasa por el flujo
  completo de spec-kit (`specify → clarify → plan → checklist → tasks → analyze`). Cumple.
- **Principio III (Corrección fiscal innegociable — ARCA)**: refuerza el principio — corrige un envío
  automático a ARCA sin decisión explícita del usuario (mismo espíritu que motivó spec 040), y mejora la
  exactitud del IVA informado. No se toca la resiliencia ya exigida (reintento manual sin pérdida de
  datos, comprobantes con soft delete). Cumple.
- **Principio IV (Testing donde hay dinero o impacto fiscal)**: se agregan tests feature que cubren: (a)
  crear una NC/ND ya NO dispara emisión, (b) la acción manual sí la dispara, (c) el payload de IVA
  desglosa por alícuota real cuando corresponde y cae a fallback agregado cuando no, (d) paridad
  Venta/Compra. Cumple.
- **Principio V (Convenciones Laravel + dominio en español)**: sin cambios de convención — nombres de
  ruta/método en español (`notas.enviarArca`), reutiliza el controlador ya existente. Cumple.

Sin violaciones. No aplica Complexity Tracking.

## Project Structure

### Documentation (this feature)

```text
specs/097-envio-manual-arca-ncnd/
├── plan.md                      # This file
├── research.md                  # Phase 0 output
├── data-model.md                # Phase 1 output
├── quickstart.md                # Phase 1 output
├── contracts/
│   └── envio-arca-ncnd.md       # Phase 1 output
├── checklists/
│   └── requirements.md          # Spec quality checklist (/speckit-specify)
└── tasks.md                     # Phase 2 output (/speckit-tasks)
```

### Source Code (repository root)

```text
app/
├── Http/Controllers/
│   └── NotaCreditoDebitoController.php   # quitar el trigger de store()/storeCompra(); agregar
│                                          # enviarArca()/enviarArcaCompra(); emitirComprobanteFiscalNota()
│                                          # arma 'items' reales cuando todos los ítems tienen línea de origen
├── Services/Arca/
│   └── MapeadorComprobante.php           # sin cambios (ya soporta 'items')
├── Models/
│   ├── NotaCreditoDebito.php             # (posible) helper puedeEnviarseAArca(): bool, estadoArca(): string
│   └── NotaCreditoDebitoItem.php         # sin cambios de esquema

routes/
└── web.php                                # 2 rutas nuevas: ventas.notas.enviarArca, compras.notas.enviarArca

resources/
├── views/ventas/detalle.blade.php         # acción "Enviar a ARCA" + indicador de estado por nota + modales propios
├── views/ventas/_modales_arca_nota.blade.php  # nuevo — modales propios de NC/ND (confirmación + resultado)
└── js/notas-credito-debito.js             # handler AJAX + confirm + modal de resultado / toast + refresh de fila

tests/Feature/
├── EmisionComprobanteNotaCreditoDebitoTest.php  # existente — se ajusta para disparar el envío vía la
│                                                  # nueva acción manual en vez del trigger eliminado
└── EnvioManualArcaNotaCreditoDebitoTest.php      # nuevo — cubre US1/US2/US4 de esta spec

docs/
└── documentacion_principal_crm.md          # corregir sección de NC/ND en Facturación Electrónica
```

**Structure Decision**: cambio quirúrgico dentro del módulo existente de NC/ND (que vive anidado en
Ventas/Compras, sin pantalla propia — ver research.md R6) — no se crean módulos nuevos. Sigue la
estructura MVC de Laravel ya establecida, replicando el patrón de spec 040 con componentes propios donde
Clarifications lo pidió explícitamente (modales).

## Complexity Tracking

*No aplica — sin violaciones de la Constitution Check.*
