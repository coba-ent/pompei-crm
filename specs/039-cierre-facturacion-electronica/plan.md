# Implementation Plan: Cierre de Facturación Electrónica — PDF NC/ND, Mi Perfil y Recibos

**Branch**: `039-cierre-facturacion-electronica` | **Date**: 2026-08-03 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `/specs/039-cierre-facturacion-electronica/spec.md`

## Summary

Cerrar tres pendientes ligados a Facturación Electrónica (spec 034): (1) un PDF propio para
Notas de Crédito/Débito (spec 008) que muestre su CAE real y referencie el comprobante de Venta
que ajustan, reutilizando el patrón ya validado en `resources/views/ventas/pdf.blade.php`; (2)
una pantalla "Mi Perfil" en Configuración & Ajustes para cargar los datos fiscales del propio
negocio (Razón Social, CUIT, Domicilio Fiscal, Condición de IVA, Ingresos Brutos, logo), que se
inyectan como encabezado emisor en los PDFs de Venta y NC/ND; (3) un documento imprimible de
Recibo (no fiscal) para Cobranzas de Venta y Pagos a Proveedores, documentado como mejor esfuerzo
por no existir informe con capturas reales de Contagram para esta pantalla.

## Technical Context

**Language/Version**: PHP 8.2, Laravel 12

**Primary Dependencies**: `barryvdh/laravel-dompdf` (ya en uso para `ventas/pdf.blade.php`,
reutilizado sin cambios); Select2/DataTables/Toastr del template NexaDash (reglas de diseño
obligatorias del proyecto).

**Storage**: MySQL (misma DB `contagram`). Logo de Mi Perfil como archivo de imagen en
`storage/app/public/empresa/` (disco público, análogo a otros uploads de imagen ya existentes en
el proyecto — p. ej. imágenes de Productos), referenciado por ruta desde la tabla
`datos_empresa`.

**Testing**: PHPUnit, mismo patrón que spec 034/008 — tests Feature para generación de PDF (assert
sobre contenido del comprobante, no pixel-perfect) y tests Unit para el mapeo de datos hacia la
vista.

**Target Platform**: Servidor Linux (mismo VPS Hostinger del demo/producción), Laravel vía PHP-FPM.

**Project Type**: Web (Laravel monolito Blade + Vite, sin frontend separado).

**Performance Goals**: Generación de cualquiera de los 3 documentos (NC/ND, Recibo) en menos de
2s, consistente con el PDF de Venta ya existente (no involucra red externa, a diferencia de la
emisión de CAE que ya ocurrió antes en spec 034/008).

**Constraints**: Recibos y Mi Perfil no tienen informe con capturas reales de Contagram — se
construyen siguiendo el patrón visual ya usado en el proyecto (modal + AJAX, estructura de PDF
igual a `ventas/pdf.blade.php`), dejando la brecha documentada explícitamente (Principio I de la
constitución) en vez de inventar una estructura no verificada como si fuera relevada.

**Scale/Scope**: Single-tenant; una sola fila de `datos_empresa` (no hay multi-empresa). Volumen
de NC/ND y Recibos acotado al mismo volumen de Ventas/Compras/Cobranzas/Pagos ya existente.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **Principio I (Documentación de dominio como fuente de verdad)**: PASS con nota. Spec basada en
  `documentacion_principal_crm.md` §3.5 (Recibos, Mi Perfil mencionados como pendientes), §7 y en
  spec 034 (T027, `ComprobanteFiscal`). No existe `informe_contagram_*` con capturas para Mi
  Perfil ni Recibos — brecha documentada explícitamente en spec Assumptions y en User Story 2/3,
  no se inventa estructura no verificada. Antes de `/speckit-tasks` se actualiza
  `documentacion_principal_crm.md` §5/§7 y `modelo_datos.md` con `datos_empresa` y el cierre de
  T027 (Principio I).
- **Principio II (Desarrollo spec-driven)**: PASS — parte de la cadena
  specify→clarify→plan→checklist→tasks→analyze en curso.
- **Principio III (Corrección fiscal innegociable)**: PASS con aclaración. Esta feature NO emite
  comprobantes fiscales nuevos (reutiliza `ComprobanteFiscal` ya emitido por spec 034/008 para
  NC/ND); el Recibo es explícitamente no-fiscal (FR-012) y no toca `EmisorComprobante` ni WSFEv1.
  Ningún gate fiscal nuevo se introduce; se preserva el ya existente (CAE obligatorio para ocultar
  watermark, inmutabilidad de comprobantes aprobados).
- **Principio IV (Testing donde hay dinero o impacto fiscal)**: PASS — aunque no hay impacto fiscal
  nuevo, se agregan tests Feature para verificar que el PDF de NC/ND expone correctamente el CAE
  ya emitido (evitar regresión sobre un dato fiscal ya validado en spec 034).
- **Principio V (Convenciones Laravel + dominio en español)**: PASS — nombres de tabla/campos en
  español (`datos_empresa`, `razon_social`, `domicilio_fiscal`), consistente con el resto del
  esquema (`certificados_fiscales`, `comprobantes_fiscales`).

## Project Structure

### Documentation (this feature)

```text
specs/039-cierre-facturacion-electronica/
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
├── Models/
│   └── DatosEmpresa.php                         # nuevo — Mi Perfil (single-row)
├── Http/Controllers/
│   ├── MiPerfilController.php                   # nuevo — CRUD AJAX de Mi Perfil
│   ├── NotaCreditoDebitoController.php           # existente — se agrega acción pdf()
│   ├── VentaController.php                       # existente — se agrega acción reciboCobranza()
│   └── CompraController.php                      # existente — se agrega acción reciboPago()
database/migrations/
└── xxxx_create_datos_empresa_table.php           # nuevo

resources/views/
├── configuracion/mi-perfil/index.blade.php       # nuevo — modal Bootstrap + AJAX
├── notas-credito-debito/pdf.blade.php            # nuevo — reutiliza estructura de ventas/pdf.blade.php
└── recibos/pdf.blade.php                         # nuevo — Cobranza o Pago según contexto

resources/js/
└── mi-perfil.js                                  # nuevo — AJAX del modal + preview de logo

tests/
├── Feature/
│   ├── PdfNotaCreditoDebitoTest.php               # nuevo
│   ├── MiPerfilTest.php                           # nuevo
│   └── ReciboPdfTest.php                          # nuevo
```

**Structure Decision**: Laravel monolito existente (Blade + Vite), sin proyecto nuevo. Se sigue el
mismo patrón ya usado por `ventas/pdf.blade.php` (spec 034) para los dos PDFs nuevos, y el mismo
patrón modal+AJAX+Select2 de otras pantallas de Configuración & Ajustes (p. ej.
`configuracion/arca/index.blade.php`, spec 034) para Mi Perfil.

## Complexity Tracking

*Sin violaciones a la constitución que requieran justificación.*
