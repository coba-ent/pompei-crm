# Implementation Plan: Envío Manual a ARCA desde el listado de Ventas

**Branch**: `040-envio-manual-arca` | **Date**: 2026-08-04 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/040-envio-manual-arca/spec.md`

## Summary

Sacar el disparo automático de emisión de CAE que hoy ocurre en `VentaController::cobranzaStore`
(al confirmar el primer cobro de una Venta) y reemplazarlo por una acción manual "Enviar a ARCA" por
fila en el listado de Ventas, protegida por el permiso `ventas.ver` (spec 040 §Clarifications),
disponible sólo para Ventas A/B/C sin `ComprobanteFiscal` aprobado, con confirmación explícita antes
de enviar y actualización de la fila vía AJAX/toast. Se reutiliza sin cambios el servicio
`EmisorComprobante::emitir()` (WSAA/WSFEv1, manejo de `ArcaRechazoException`/`ArcaNoDisponibleException`,
reconciliación vía `verificarPendiente()`). Se corrige además la documentación (`FR-004` de la spec
034 y `docs/documentacion_principal_crm.md`) para dejar de describir el envío como automático, y se
deja registrado el incidente del 04/08/2026 que motivó esta corrección.

## Technical Context

**Language/Version**: PHP 8.2, Laravel 12

**Primary Dependencies**: Eloquent (Venta, ComprobanteFiscal, FuncionAvanzada), `App\Services\Arca\EmisorComprobante` (existente, sin cambios), Yajra DataTables (listado de Ventas), Toastr (NexaDash, sólo para rechazos de precondición — FR-007a), modal Bootstrap nuevo y específico para el resultado real de ARCA (FR-007) — no reutiliza `modal-pdf.blade.php` (no es un documento imprimible)

**Storage**: MySQL/MariaDB — sin cambios de esquema (reutiliza `ventas`, `comprobantes_fiscales`, `funciones_avanzadas`)

**Testing**: PHPUnit (Feature tests), mismo patrón que `EmisionComprobanteNotaCreditoDebitoTest`/`EmisionComprobanteRechazoTest` (spec 034) con `EmisorComprobante` mockeado vía `$this->app->bind(...)`

**Target Platform**: Web server Laravel (hosting compartido + VPS), sin cambios de infraestructura

**Project Type**: Web application (Laravel monolito + Blade/Vite) — módulo existente (Ventas)

**Performance Goals**: N/A — acción manual, un envío HTTP por click, sin requisitos de throughput

**Constraints**: el envío es una operación real e irreversible ante ARCA; debe requerir confirmación
explícita del usuario y no debe poder dispararse por accidente (doble click, recarga, etc.)

**Scale/Scope**: acción de fila sobre el listado ya existente de Ventas (DataTables server-side); sin
nuevas tablas ni endpoints masivos

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **Principio I (Documentación de dominio como fuente de verdad)**: esta spec corrige
  `docs/documentacion_principal_crm.md` y `specs/034-.../spec.md` en el mismo cambio que corrige el
  código — cumple. La corrección es justamente restaurar la fidelidad con el dominio real
  (Contagram), que había quedado mal documentada.
- **Principio II (Desarrollo spec-driven)**: esta es una corrección de lógica de negocio (emisión
  fiscal), no un cambio trivial — pasa por el flujo completo de spec-kit. Cumple.
- **Principio III (Corrección fiscal innegociable — ARCA)**: sin cambios al comportamiento de
  resiliencia ya exigido (registrar la Venta igual si ARCA falla, reintento manual sin pérdida de
  datos) — esta spec **refuerza** el principio, ya que corrige un envío automático que lo violaba en
  espíritu (una emisión fiscal no debe ocurrir sin decisión explícita del usuario). Cumple.
- **Principio IV (Testing donde hay dinero o impacto fiscal)**: se agregan/ajustan tests feature que
  cubren: (a) que confirmar un cobro YA NO dispara emisión, (b) que la acción manual sí la dispara
  correctamente, (c) que la acción no está disponible para Ventas ya aprobadas o sin tipo A/B/C.
  Cumple.
- **Principio V (Convenciones Laravel + dominio en español)**: sin cambios de convención — se
  reutiliza el controlador y las rutas ya nombradas en español (`ventas.*`). Cumple.

Sin violaciones. No aplica Complexity Tracking.

## Project Structure

### Documentation (this feature)

```text
specs/040-envio-manual-arca/
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
├── Http/Controllers/
│   └── VentaController.php          # quitar el trigger automático de cobranzaStore(); agregar acción enviarArca()
├── Models/
│   └── Venta.php                    # (posible) helper puedeEnviarseAArca(): bool
routes/
└── web.php                           # nueva ruta ventas.{venta}.enviarArca (POST)

resources/
├── views/ventas/index.blade.php      # agregar acción de fila "Enviar a ARCA" + modal nuevo de resultado (FR-007)
└── js/ventas.js                      # handler AJAX + confirm() + abrir modal de resultado (o toast si es rechazo de precondición, FR-007a) + refresh de fila/tabla

tests/Feature/
├── EmisionComprobanteRechazoTest.php        # existente (spec 034) — verificar que sigue pasando
├── EmisionComprobanteNotaCreditoDebitoTest.php  # existente — se ajusta (research.md §5) para disparar el envío vía la nueva acción manual en vez del trigger automático eliminado; la lógica de NC/ND en sí no cambia
└── EnvioManualArcaTest.php                   # nuevo — cubre US1 de esta spec

docs/
├── documentacion_principal_crm.md    # corregir sección de Facturación Electrónica (envío manual)
```

**Structure Decision**: cambio quirúrgico dentro del módulo Ventas ya existente — no se crean
directorios ni módulos nuevos. Sigue la estructura MVC de Laravel ya establecida en el proyecto
(controlador existente, vista/JS existentes del listado de Ventas).

## Complexity Tracking

*No aplica — sin violaciones de la Constitution Check.*
