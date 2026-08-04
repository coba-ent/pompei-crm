# Implementation Plan: Robustez de datos fiscales en la emisión de CAE (ARCA)

**Branch**: `042-robustez-emision-arca` | **Date**: 2026-08-04 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/042-robustez-emision-arca/spec.md`

## Summary

Corregir `app/Services/Arca/MapeadorComprobante.php` para declarar un bloque `AlicIva` por cada
alícuota de IVA realmente presente en los ítems del comprobante (en vez de uno solo, fijo en 21%,
independientemente de los ítems reales) — causa raíz confirmada del rechazo ARCA código 10051 del
incidente del 04/08/2026. Agregar además `CondicionIVAReceptorId` a toda solicitud de CAE, usando
`Cliente->condicionIva->codigo_afip` (dato ya existente y ya alineado con los códigos oficiales de
ARCA), anticipándose a que ARCA vuelva ese campo obligatorio el 01/09/2026. Ambas correcciones
agregan validaciones de precondición (alícuota no soportada, inconsistencia de importes fuera de
tolerancia $0.01, Condición de IVA faltante) que rechazan el envío **antes** de contactar a ARCA,
reutilizando el mismo mecanismo de rechazo de precondición ya definido en spec 040
(`ArcaRechazoException` / respuesta 422 + toast, sin modal). No se modifica el flujo de envío manual
"Enviar a ARCA" (spec 040) ni cuándo/por qué se dispara la emisión (Ventas, Compras, NC/ND) — sólo
cómo se arma el detalle de IVA y el receptor dentro de `EmisorComprobante::emitir()`.

## Technical Context

**Language/Version**: PHP 8.2, Laravel 12

**Primary Dependencies**: Eloquent (`Venta`, `VentaItem`, `Cliente`, `CondicionIva`), servicios
existentes `App\Services\Arca\MapeadorComprobante`, `App\Services\Arca\ValidadorDatosFiscales`,
`App\Services\Arca\EmisorComprobante` (se extienden, no se reescriben)

**Storage**: MySQL/MariaDB — sin cambios de esquema (reutiliza `venta_items.iva_pct`,
`condiciones_iva.codigo_afip`, `clientes.condicion_iva_id`, todos ya existentes)

**Testing**: PHPUnit (Unit tests sobre `MapeadorComprobante`/`ValidadorDatosFiscales` para el
armado/validación de los bloques `AlicIva` y `CondicionIVAReceptorId`; Feature tests sobre
`EmisorComprobante::emitir()` con mock del cliente WSFEv1, mismo patrón que
`EmisionComprobanteRechazoTest`/`EmisionComprobanteVentaTest`)

**Target Platform**: Web server Laravel (hosting compartido + VPS), sin cambios de infraestructura

**Project Type**: Web application (Laravel monolito) — corrección interna de un servicio existente
(`app/Services/Arca/`), sin nuevas pantallas ni endpoints

**Performance Goals**: N/A — cambio de armado de payload, sin impacto de throughput

**Constraints**: la corrección DEBE ser transparente para comprobantes que hoy ya funcionan (una sola
alícuota, 21%) — no debe cambiar el resultado observable de esos casos, sólo corregir los que hoy
fallan (alícuotas mixtas o distintas de 21%) y agregar el campo nuevo obligatorio

**Scale/Scope**: cambio interno en 2-3 archivos de servicio (`MapeadorComprobante`,
`ValidadorDatosFiscales`, y el punto donde `EmisorComprobante`/controladores arman el array `$datos`
con los ítems de la Venta) — sin tablas, colas ni endpoints nuevos

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **Principio I (Documentación de dominio como fuente de verdad)**: esta spec corrige un defecto de
  implementación, no una regla de negocio documentada incorrectamente — no requiere cambios en
  `docs/documentacion_principal_crm.md` (la sección de Facturación Electrónica ya describe la emisión
  a nivel de negocio, no el detalle interno de armado del payload WSFEv1). Cumple, sin cambios de doc
  necesarios.
- **Principio II (Desarrollo spec-driven)**: corrección de lógica fiscal — pasa por el flujo completo
  de spec-kit. Cumple.
- **Principio III (Corrección fiscal innegociable — ARCA)**: esta spec **refuerza** directamente este
  principio — corrige el motivo exacto por el que un comprobante podía ser rechazado por datos mal
  armados, y agrega validaciones de precondición que evitan contactar a ARCA con datos que van a
  fallar. Mantiene la resiliencia ya exigida (registrar la operación igual si ARCA rechaza/falla) sin
  cambios. Cumple.
- **Principio IV (Testing donde hay dinero o impacto fiscal)**: se agregan tests unitarios sobre el
  armado de `AlicIva`/`CondicionIVAReceptorId` (incluyendo el caso de alícuotas mixtas que replica el
  incidente) y tests feature sobre `EmisorComprobante::emitir()` con los nuevos rechazos de
  precondición. Cumple.
- **Principio V (Convenciones Laravel + dominio en español)**: sin cambios de convención — se extiende
  el mismo servicio ya nombrado en español (`MapeadorComprobante`, `ValidadorDatosFiscales`). Cumple.

Sin violaciones. No aplica Complexity Tracking.

## Project Structure

### Documentation (this feature)

```text
specs/042-robustez-emision-arca/
├── plan.md              # This file (/speckit-plan command output)
├── research.md          # Phase 0 output (/speckit-plan command)
├── data-model.md         # Phase 1 output (/speckit-plan command)
├── quickstart.md         # Phase 1 output (/speckit-plan command)
├── contracts/
│   └── solicitud-cae.md  # Phase 1 output — forma del payload FeCAEReq corregido
├── checklists/
│   └── requirements.md   # Spec quality checklist (/speckit-specify)
└── tasks.md               # Phase 2 output (/speckit-tasks command)
```

### Source Code (repository root)

```text
app/
├── Services/Arca/
│   ├── MapeadorComprobante.php       # arma un bloque AlicIva por alícuota real de los ítems +
│   │                                   CondicionIVAReceptorId (en vez de un único bloque fijo en 21%)
│   ├── ValidadorDatosFiscales.php    # + validación de alícuota soportada, consistencia de importes
│   │                                   (tolerancia $0.01) y Condición de IVA del cliente
│   └── EmisorComprobante.php         # sin cambios de lógica — sólo recibe/propaga los nuevos datos
│                                       (ítems con iva_pct, condición de IVA del cliente) en $datos
├── Http/Controllers/
│   ├── VentaController.php           # arma $datos incluyendo los ítems (con iva_pct) y la condición
│   │                                   de IVA del cliente en vez de sólo los totales agregados
│   └── NotaCreditoDebitoController.php  # + condición de IVA del cliente (sigue sin desglose de
│                                       ítems — usa el fallback de alícuota única de MapeadorComprobante,
│                                       ver research.md; su cálculo neto/iva no cambia, fuera de alcance)

# NOTA: CompraController NO llama a EmisorComprobante::emitir() — el CAE de una Compra lo declara el
# Proveedor en su propio comprobante (ver CompraController::registrarComprobanteFiscalProveedor); no
# hay nada que corregir ahí para esta spec.

tests/
├── Unit/Services/Arca/
│   ├── MapeadorComprobanteTest.php          # nuevo — alícuota única, alícuotas mixtas, CondicionIVAReceptorId
│   └── ValidadorDatosFiscalesTest.php        # nuevo — alícuota no soportada, inconsistencia de importes, condición de IVA faltante
└── Feature/
    └── EmisionComprobanteVentaTest.php       # existente — se extiende con el caso de alícuotas mixtas
```

**Structure Decision**: corrección quirúrgica dentro del servicio existente `app/Services/Arca/` — no
se crean servicios, tablas ni endpoints nuevos. Los controladores que ya invocan
`EmisorComprobante::emitir()` (Ventas, Compras, NC/ND) ajustan el array `$datos` que arman para incluir
el detalle de ítems y la condición de IVA del cliente, sin cambiar su propio flujo de negocio.

## Complexity Tracking

*No aplica — sin violaciones de la Constitution Check.*
