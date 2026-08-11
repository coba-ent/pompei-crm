# Implementation Plan: Fidelidad estructural de la tabla NC/ND en Compra y Venta

**Branch**: `062-tabla-ncnd-fidelidad` | **Date**: 2026-08-11 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `/specs/062-tabla-ncnd-fidelidad/spec.md`

## Summary

Corregir la tabla "Notas de Crédito y Débito" en el detalle de Venta y de Compra para que replique
la estructura de columnas de Contagram real (Estado, ID, Emisión, Comprobante, N° Comprobante,
Documento que Ajusta, Total, Nota Interna), separando el estado fiscal real del menú de acciones, y
agregar el campo `nota_interna` (nuevo, vía migración) a `notas_credito_debito`, expuesto en los
formularios de alta/edición y en la tabla. Enfoque: reutilizar `ComprobanteFiscal` (estado/CAE) y la
relación `notaAjustada` ya existentes (spec 057) sin tocar su lógica; el trabajo es de presentación
(Blade + controller que arma los datos) más una migración chica y su wiring en Request/JS.

## Technical Context

**Language/Version**: PHP 8.2 (Laravel 12)

**Primary Dependencies**: Eloquent ORM, yajra/laravel-datatables (server-side AJAX), Bootstrap 5
NexaDash (Blade), Select2 (no aplica acá — no hay selects dinámicos nuevos), Vite

**Storage**: MySQL — tabla `notas_credito_debito` (agrega columna `nota_interna`)

**Testing**: PHPUnit (Feature tests) — se agrega/actualiza cobertura sobre el armado de columnas
(Estado/Comprobante/Documento que Ajusta) en el detalle de Venta y Compra, siguiendo el principio IV
de la constitución sólo donde hay impacto fiscal real (columna Estado depende de `ComprobanteFiscal`)

**Target Platform**: Web (navegador), servidor Laravel existente (demo hosting compartido + VPS)

**Project Type**: Web application (monolito Laravel + Blade, sin frontend separado)

**Performance Goals**: N/A — no agrega queries N+1 (se usa eager loading de las relaciones ya
cargadas en `show()`/`detalle()` de Venta y Compra); sin metas de performance específicas más allá de
no degradar el tiempo de carga actual del detalle.

**Constraints**: Debe mantener retrocompatibilidad con NC/ND migradas de Contagram (con `legacy_id`)
que no tienen `ComprobanteFiscal` en el CRM actual — columnas deben quedar vacías, no romper.

**Scale/Scope**: 2 vistas (`ventas/detalle.blade.php`, `compras/detalle.blade.php`), sus 2
controllers, 1 migración, 2 FormRequests (alta/edición NC/ND de Venta y de Compra), JS asociado.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **Principio I (Documentación de dominio como fuente de verdad)**: PASA. La spec se basó en
  `docs/documentacion_principal_crm.md` (sección NC/ND ya documentada) y en
  `docs/Contagram-Informe-NC-ND-Ventas-y-Compras.md` (informe con verificación práctica). Se
  actualizará `docs/modelo_datos.md` para sumar `nota_interna` a `notas_credito_debito` antes de
  `/speckit-tasks`, y se corregirá el bullet de `docs/documentacion_principal_crm.md` que describe
  la columna "Estado" actual como disparador del menú (línea ~493) para reflejar la corrección.
- **Principio II (Desarrollo spec-driven)**: PASA. Se sigue la cadena completa specify → clarify →
  plan → checklist → tasks → analyze antes de implementar.
- **Principio III (Corrección fiscal ARCA)**: PASA sin riesgo — no se toca la lógica de emisión ni
  de estados de `ComprobanteFiscal`; sólo se lee y se muestra el número real (CAE) ya aprobado.
- **Principio IV (Testing con impacto fiscal)**: APLICA. N° Comprobante depende de la lectura
  correcta de `ComprobanteFiscal`; se agrega test de que el número mostrado coincide con el real, y
  de que NC/ND sin comprobante fiscal no rompen el render.
- **Principio V (Convenciones Laravel + español)**: PASA. `nota_interna` sigue el nombre ya usado en
  Venta/Compra (`nota_interna`), snake_case, sin `empresa_id`.

**Resultado**: Sin violaciones. No aplica Complexity Tracking.

## Project Structure

### Documentation (this feature)

```text
specs/062-tabla-ncnd-fidelidad/
├── plan.md              # This file (/speckit-plan command output)
├── research.md          # Phase 0 output (/speckit-plan command)
├── data-model.md        # Phase 1 output (/speckit-plan command)
├── quickstart.md        # Phase 1 output (/speckit-plan command)
└── tasks.md             # Phase 2 output (/speckit-tasks command - NOT created by /speckit-plan)
```

No se genera `contracts/`: no hay una interfaz externa nueva — la feature reutiliza los endpoints
`show()`/`detalle()` de Venta/Compra (ya renderizan la vista con los datos necesarios) y los
endpoints de alta/edición de NC/ND ya existentes (spec 057/061), a los que sólo se les suma un campo.

### Source Code (repository root)

```text
app/
├── Models/
│   └── NotaCreditoDebito.php          # agrega 'nota_interna' a $fillable
├── Http/
│   ├── Controllers/
│   │   ├── VentaController.php        # arma datos de la tabla NC/ND para la vista (o el propio detalle())
│   │   └── CompraController.php       # ídem
│   └── Requests/
│       ├── StoreNotaCreditoDebitoVentaRequest.php   # (nombre real a confirmar en Phase 0) + nota_interna
│       └── StoreNotaCreditoDebitoCompraRequest.php  # ídem
resources/
├── views/
│   ├── ventas/detalle.blade.php       # nueva estructura de columnas de la tabla NC/ND
│   ├── ventas/_modal_ncnd.blade.php   # campo Nota Interna en el formulario (si existe modal dedicado)
│   ├── compras/detalle.blade.php      # ídem Compra
│   └── compras/_modal_ncnd.blade.php  # ídem
└── js/
    ├── ventas.js                      # si arma el payload de alta/edición de NC/ND
    └── compras.js                     # ídem
database/
└── migrations/
    └── <timestamp>_add_nota_interna_to_notas_credito_debito_table.php
tests/
└── Feature/
    └── NotaCreditoDebitoTablaDetalleTest.php  # cobertura de columnas Estado/Comprobante/Documento que Ajusta
```

**Structure Decision**: Monolito Laravel existente — no se crean módulos ni carpetas nuevas, se
extienden los archivos ya responsables de Venta/Compra y NC/ND. Los nombres exactos de los archivos
de modal/Request de NC/ND (creados en specs 057/059/061) se confirman en Phase 0 (research.md) antes
de tocarlos.

## Complexity Tracking

*Sin violaciones — sección no aplica.*
