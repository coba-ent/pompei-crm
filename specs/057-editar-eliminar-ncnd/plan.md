# Implementation Plan: Edición y eliminación de Notas de Crédito y Débito (NC/ND)

**Branch**: `057-editar-eliminar-ncnd` | **Date**: 2026-08-11 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/057-editar-eliminar-ncnd/spec.md`

## Summary

Hoy el CRM sólo permite **crear** una NC/ND (Ventas y Compras). Este feature agrega **editar**,
**eliminar** y **ver detalle (PDF)** — este último faltaba también en Compras — replicando la
estructura real de Contagram: menú de fila (Estado → Editar/Eliminar/Ver Detalle), wizard de
edición de 2 pasos con comprobante propio (tipo+número) editable y encadenamiento de 1 nivel en
"Documento que Ajusta" hacia otra NC/ND, bloqueo total de edición/eliminación si la nota ya tiene
CAE aprobado, y reversión exacta de cualquier ajuste de stock al editar/eliminar. Enfoque técnico:
extender `NotaCreditoDebitoController` con `update`/`destroy`/`pdf` (ya existe para Ventas, falta
para Compras), reusar el wizard/modal existente agregándole el paso de edición completo, agregar
`nota_ajustada_id` (auto-referencial, nullable) + `tipo_comprobante_propio`/`nro_comprobante`
propios a `notas_credito_debito` vía migración, y un `StockService`/reversión análoga a la ya
usada en creación.

## Technical Context

**Language/Version**: PHP 8.2, Laravel 12 (Eloquent ORM)

**Primary Dependencies**: Laravel 12, Blade, jQuery + DataTables (server-side AJAX) + Select2 +
Bootstrap 5 (template NexaDash), Barryvdh/DomPDF (documentos imprimibles), Endroid QrCode (QR
AFIP en PDFs fiscales)

**Storage**: MySQL (single-tenant, sin `empresa_id` — regla V de la constitución)

**Testing**: PHPUnit 11 vía Pest plugin, estilo `Tests\Feature\*Test extends TestCase` con
`RefreshDatabase` (ver `tests/Feature/AbonosTest.php` como referencia de convención del proyecto)

**Target Platform**: Web (hosting compartido + VPS propio, ver `.claude/skills/deploy`)

**Project Type**: Web application monolítica (Laravel MVC, sin frontend separado)

**Performance Goals**: N/A — operación CRUD de bajo volumen (altas de NC/ND son manuales, no hay
tráfico masivo); mismo perfil de performance que Crear NC/ND ya existente

**Constraints**: Alta/edición/eliminación por AJAX + modal, sin recarga de página (regla de
diseño obligatoria del proyecto); tablas DataTables server-side; toda validación de negocio
(CAE aprobado, duplicado de comprobante, pendiente de ajuste excluyendo la nota en edición,
bloqueo de eliminación por cadena) vive en `FormRequest`/`Controller`, no en el cliente

**Scale/Scope**: 2 controladores (Venta/Compra comparten `NotaCreditoDebitoController`), ~3
vistas Blade a extender (`_modal_ncnd` de Ventas y Compras + `pdf.blade.php`), 1 migración nueva,
1 servicio nuevo de reversión de stock (o extensión de `StockService` existente)

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **Principio I (Documentación de dominio como fuente de verdad)**: ✅ Cumplido — la estructura
  real de Contagram ya fue relevada con capturas propias y volcada a
  `docs/informe_contagram_egresos.md` §2.5.1 y `docs/documentacion_principal_crm.md` §3.2/§7
  **antes** de este plan (mismo día, 11/08/2026). `docs/modelo_datos.md` ya tiene la nota de
  brecha del esquema actual vs. el real.
- **Principio II (Desarrollo spec-driven)**: ✅ Cumplido — spec → clarify ya completados antes de
  este plan.
- **Principio III (Corrección fiscal innegociable — ARCA)**: ✅ Aplica directo — FR-011 bloquea
  edición/eliminación de cualquier NC/ND con CAE aprobado (nunca se toca un comprobante fiscal ya
  emitido). La eliminación usa **soft delete** (`notas_credito_debito.deleted_at` ya existe en el
  esquema, ver `docs/modelo_datos.md`), consistente con "documentos fiscales o con impacto
  contable... nunca se borran físicamente". Sin violación.
- **Principio IV (Testing donde hay dinero o impacto fiscal)**: ✅ Aplica — este feature toca
  cálculo de montos/IVA de la nota, movimientos de stock y saldos de cuenta corriente
  (A Cobrar/A Pagar); requiere tests Feature para: reversión de stock al editar/eliminar,
  bloqueo por CAE aprobado, bloqueo por cadena de encadenamiento, recálculo de "pendiente de
  ajuste" excluyendo la nota en edición, y rechazo de comprobante duplicado.
- **Principio V (Convenciones Laravel + dominio en español)**: ✅ Nombres nuevos en español
  (`nota_ajustada_id`, no `parent_note_id`); reutiliza FormRequest/Eloquent/Blade ya existentes.

**Resultado**: ninguna violación. No aplica Complexity Tracking.

## Project Structure

### Documentation (this feature)

```text
specs/057-editar-eliminar-ncnd/
├── plan.md              # This file (/speckit-plan command output)
├── research.md          # Phase 0 output (/speckit-plan command)
├── data-model.md        # Phase 1 output (/speckit-plan command)
├── quickstart.md        # Phase 1 output (/speckit-plan command)
├── contracts/           # Phase 1 output (/speckit-plan command)
└── tasks.md             # Phase 2 output (/speckit-tasks command - NOT created by /speckit-plan)
```

### Source Code (repository root)

```text
app/
├── Http/
│   ├── Controllers/
│   │   └── NotaCreditoDebitoController.php     # + update(), destroy(), pdf() ya soporta Venta,
│   │                                             #   se extiende para Compra
│   └── Requests/
│       ├── StoreNotaCreditoDebitoRequest.php    # ya existe (crear)
│       └── UpdateNotaCreditoDebitoRequest.php   # NUEVO — valida edición (CAE bloqueado, dup.
│                                                 #   comprobante, pendiente excl. la nota misma)
├── Models/
│   ├── NotaCreditoDebito.php                    # + notaAjustada()/notasQueLoAjustan(), + campos
│   │                                             #   tipo_comprobante_propio/nro_comprobante
│   └── NotaCreditoDebitoItem.php                # + precio/IVA propio (si Fase 1 lo confirma)
└── Services/
    ├── AjustesPendientesNotaCreditoDebito.php   # + excluir nota en edición del cálculo
    └── Stock/
        └── StockService.php                     # + revertir(NotaCreditoDebito) o equivalente

database/migrations/
└── 2026_08_11_xxxxxx_add_edicion_a_notas_credito_debito.php   # NUEVO

resources/
├── views/
│   ├── ventas/_modal_ncnd.blade.php             # + paso 2 completo (comprobante propio, items
│   │                                             #   con IVA), + botón Eliminar
│   ├── ventas/detalle.blade.php                 # + menú de fila (Editar/Eliminar/Ver Detalle)
│   ├── compras/_modal_ncnd.blade.php            # ídem Ventas
│   ├── compras/detalle.blade.php                # + columna/menú + link Ver Detalle (no existe)
│   └── notas-credito-debito/pdf.blade.php       # + soporte para `compra` (hoy sólo `venta`)
├── js/
│   ├── ventas.js                                # + editar()/eliminar() sobre el wizard NC/ND
│   └── compras.js                               # ídem Ventas

routes/web.php                                    # + PUT/DELETE {venta}/notas/{nota},
                                                    #   {compra}/notas/{nota}; + GET
                                                    #   {compra}/notas/{nota}/pdf (o ruta unificada)

tests/Feature/
└── NotaCreditoDebitoEdicionTest.php              # NUEVO — cubre principio IV
```

**Structure Decision**: Laravel monolítico ya establecido — no se introduce ningún proyecto o
capa nueva. Se extienden los archivos existentes de la feature de Crear NC/ND (spec 039/045) en
vez de duplicar controladores/vistas por separado para Ventas y Compras, manteniendo el patrón
espejo ya usado (`NotaCreditoDebitoController` atiende ambas entidades vía `venta_id`/`compra_id`
polimórfico-por-columna, no una tabla por módulo).

## Complexity Tracking

*Sin violaciones de la Constitution Check — sección no aplica.*
