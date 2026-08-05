# Implementation Plan: Comprobante por defecto derivado de la Condición de IVA

**Branch**: `048-comprobante-defecto-condicion-iva` | **Date**: 2026-08-05 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `/specs/048-comprobante-defecto-condicion-iva/spec.md`

## Summary

En el modal de alta/edición de Cliente (`resources/js/cliente-modal.js`), cuando el usuario elige (o el
autocompletado del padrón de ARCA completa) la Condición de IVA, el campo "Comprobante por defecto" debe
autocompletarse con el mismo criterio binario ya usado en backend para la conversión de órdenes
(`ResolutorCliente`/`DerivadorComprobante`): Responsable Inscripto → Factura A, cualquier otra condición →
Factura B. Es una feature puramente de frontend — no hay llamada a red nueva ni cambio de contrato de API;
el criterio ya existe en dos lugares del backend, acá sólo se replica en JS para ahorrarle el paso manual
al usuario. Se agrega al mecanismo de "tocado" que ya usan razón social/domicilio/condición de IVA para no
pisar una edición manual.

## Technical Context

**Language/Version**: JavaScript (jQuery, patrón ya usado en `cliente-modal.js`), sin cambios de backend.

**Primary Dependencies**: Ninguna nueva — reusa el DOM ya existente del modal (`_modal_form.blade.php`):
`select[name="condicion_iva_id"]` y `select[name="tipo_comprobante_defecto"]`.

**Storage**: Sin cambios — `tipo_comprobante_defecto` ya es una columna existente de `clientes`, ya se
guarda como cualquier otro campo del formulario al hacer submit.

**Testing**: No hay suite de tests de JS en el proyecto (confirmado — sólo PHPUnit); verificación manual en
navegador vía quickstart.md, igual que el resto de `cliente-modal.js` (spec 037/047).

**Target Platform**: Navegador (mismo modal Bootstrap ya usado en Base de Datos > Clientes).

**Project Type**: Web monolítica Laravel + Blade — cambio acotado a un asset JS existente.

**Performance Goals**: Instantáneo (sin red) — SC-001 del spec exige <1s, en la práctica es síncrono.

**Constraints**: No debe romper el mecanismo de "tocado" ya vigente para los otros 4 campos
autocompletables (`razon_social`, `domicilio_fiscal`, `provincia_fiscal`/`localidad_fiscal`,
`condicion_iva_id`) ni el submit del formulario.

**Scale/Scope**: Un solo archivo (`resources/js/cliente-modal.js`) y su build (`npm run build`); sin
cambios de vista salvo, si hiciera falta, algún `data-*` attribute adicional en `_modal_form.blade.php`.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **Principio I (Documentación de dominio como fuente de verdad)**: Aplica. `documentacion_principal_crm.md`
  §2.1 no documenta hoy esta derivación automática — se agrega en el mismo cambio antes de `/speckit-tasks`.
  PASA (acción pendiente registrada).
- **Principio II (Desarrollo spec-driven)**: Cumplido — specify → clarify → plan → checklist → tasks →
  analyze.
- **Principio III (Corrección fiscal innegociable)**: El criterio de derivación no es nuevo — ya está
  vigente y probado en `ResolutorCliente`/`DerivadorComprobante` para las conversiones automáticas; acá sólo
  se replica en el modal manual, sin alterar ninguna regla existente. El usuario siempre puede corregir a
  mano antes de guardar (FR-004), así que no hay riesgo de emisión incorrecta forzada. PASA.
- **Principio IV (Testing donde hay dinero o impacto fiscal)**: Es un autocompletado de UX que el usuario
  ve y puede corregir antes de guardar — el dato que efectivamente impacta la facturación sigue siendo el
  que el usuario confirma al enviar el formulario (igual que hoy). No hay lógica de emisión de comprobantes
  nueva que requiera test de PHPUnit; se verifica manualmente en navegador (quickstart.md), igual criterio
  que el resto de `cliente-modal.js` (spec 037/047, que tampoco tiene tests de JS).
- **Principio V (Convenciones Laravel + dominio en español)**: Nombres de función en español, mismo estilo
  ya usado en `cliente-modal.js` (`autocompletarDesdePadron`, `cargarLocalidades`, etc.).

No hay violaciones que requieran la sección de Complexity Tracking.

## Project Structure

### Documentation (this feature)

```text
specs/048-comprobante-defecto-condicion-iva/
├── plan.md              # This file (/speckit-plan command output)
├── research.md          # Phase 0 output (/speckit-plan command)
├── data-model.md         # Phase 1 output (/speckit-plan command)
├── quickstart.md        # Phase 1 output (/speckit-plan command)
└── tasks.md             # Phase 2 output (/speckit-tasks command - NOT created by /speckit-plan)
```

### Source Code (repository root)

```text
resources/js/cliente-modal.js          # Extender: derivar tipo_comprobante_defecto desde condicion_iva_id
resources/views/clientes/_modal_form.blade.php  # Sin cambios de estructura (los selects ya existen)
docs/documentacion_principal_crm.md    # Extender §2.1 con la regla de derivación
```

**Structure Decision**: Cambio acotado a un único archivo JS ya existente (`cliente-modal.js`), siguiendo
el mismo patrón de "tocado" (`CAMPOS_PADRON`/`tocadoPadron`) que ya usan los otros 4 campos
autocompletables del mismo modal — no se introduce ningún mecanismo nuevo, sólo se extiende el ya vigente
con un quinto campo derivado (`tipo_comprobante_defecto`) cuyo origen no es el padrón de ARCA sino el
propio `condicion_iva_id` ya resuelto (manual o por padrón).

## Complexity Tracking

*No aplica — sin violaciones de la Constitution Check.*
