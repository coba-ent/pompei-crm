# Implementation Plan: Condición de IVA en el autocompletado del Padrón de ARCA

**Branch**: `047-condicion-iva-padron-constancia` | **Date**: 2026-08-05 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `/specs/047-condicion-iva-padron-constancia/spec.md`

## Summary

`ws_sr_padron_a13` (spec 037) sólo devuelve identidad y domicilios — nunca condición de IVA (confirmado
contra el WSDL real). Se suma una segunda consulta SOAP, independiente y best-effort, al servicio
**`ws_sr_constancia_inscripcion`** (ya adherido al certificado — confirmado en producción, "la
autorización ya existe"), cuyo WSDL real es `personaServiceA5`. Se creará un wrapper `ClienteConstanciaInscripcion`
(mismo patrón que `ClientePadron`) y se extenderá `ResultadoConsultaPadron` para fusionar ambas respuestas,
derivando la condición de IVA de `datosRegimenGeneral.impuesto[]` / `datosMonotributo` en vez de un texto
plano de condición (estructura real distinta de lo que asumía research.md de la spec 037).

## Technical Context

**Language/Version**: PHP 8.2 (Laravel 12)

**Primary Dependencies**: `ext-soap` (`SoapClient`), `App\Services\Arca\ClienteWsaa` (WSAA, ya existente),
`App\Models\CertificadoFiscal` (ya existente)

**Storage**: MySQL/MariaDB — sin nuevas tablas; el resultado sigue siendo transitorio (no se persiste como
entidad propia), igual que la spec 037

**Testing**: PHPUnit (`php artisan test`), mocks de `SoapClient`/`ClienteConstanciaInscripcion` con fixtures
basadas en las respuestas reales capturadas contra ARCA producción (ver research.md)

**Target Platform**: Servidor Linux (VPS de producción + hosting compartido demo), mismo entorno que el
resto del backend Laravel

**Project Type**: Aplicación web monolítica (Laravel + Blade) — extensión de un módulo backend ya existente

**Performance Goals**: Igual que spec 037 (R3): timeout corto (best effort), <5s percibidos por el usuario
en el modal de cliente incluyendo ambas consultas en paralelo/secuencial

**Constraints**: No debe agregar latencia perceptible al guardado de cliente ni a la conversión de órdenes
en lote; debe degradar sin bloquear si `ws_sr_constancia_inscripcion` falla, aunque `ws_sr_padron_a13` haya
respondido bien (y viceversa)

**Scale/Scope**: Mismo volumen que spec 037 — consultas puntuales por CUIT, no hay sincronización masiva

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **Principio I (Documentación de dominio como fuente de verdad)**: Aplica. `docs/documentacion_principal_crm.md`
  y la spec 037 ya documentan la integración de padrón; este plan actualiza esos documentos con el hallazgo
  de que A13 no expone condición de IVA y con el nuevo servicio sumado, en el mismo cambio, antes de `/speckit-tasks`. PASA (con acción pendiente registrada, no violación).
- **Principio II (Desarrollo spec-driven)**: Cumplido — esta feature sigue el flujo completo specify → clarify → plan → checklist → tasks → analyze.
- **Principio III (Corrección fiscal innegociable)**: La condición de IVA determina el tipo de comprobante (A/B). Esta feature no cambia esa regla, sólo corrige que el dato le llegue; se degrada sin bloquear ante fallas de ARCA (ya exigido por spec 037 y reafirmado acá). PASA.
- **Principio IV (Testing donde hay dinero o impacto fiscal)**: La condición de IVA impacta directamente la emisión de comprobantes (Factura A vs B) — REQUIERE tests: parseo de `ResultadoConsultaPadron` con la estructura real de `ws_sr_constancia_inscripcion`, y los flujos de conversión de orden (extendiendo los tests ya existentes de spec 037: `MercadoLibreConversionPadronTest`, `TiendanubeConversionPadronTest`, `ClienteVerificarPadronTest`).
- **Principio V (Convenciones Laravel + dominio en español)**: `ClienteConstanciaInscripcion`, mismo namespace `App\Services\Arca`, nombres en español donde corresponde (ya establecido por spec 037/034).

No hay violaciones que requieran la sección de Complexity Tracking.

## Project Structure

### Documentation (this feature)

```text
specs/047-condicion-iva-padron-constancia/
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
├── Services/Arca/
│   ├── ClientePadron.php                    # Ya existente (spec 037) — sin cambios de contrato
│   ├── ClienteConstanciaInscripcion.php      # NUEVO — wrapper SOAP de ws_sr_constancia_inscripcion (personaServiceA5)
│   ├── ResultadoConsultaPadron.php           # Extender: fusiona persona (A13) + datosGenerales/datosRegimenGeneral/datosMonotributo (constancia)
│   └── Excepciones/ArcaNoDisponibleException.php  # Reusado tal cual
├── Http/Controllers/ClienteController.php    # Extender consultarPadron(): segunda consulta best-effort
└── Services/{Tiendanube,MercadoLibre}/ResolutorCliente.php  # Extender: pasar por la misma consulta combinada

config/arca.php                               # Agregar entrada wsdl.ws_sr_constancia_inscripcion

tests/
├── Unit/Services/Arca/
│   ├── ClienteConstanciaInscripcionTest.php  # NUEVO
│   └── ResultadoConsultaPadronTest.php       # Extender casos con datosRegimenGeneral/datosMonotributo reales
└── Feature/
    ├── ClienteVerificarPadronTest.php                          # Extender
    └── Integraciones/{MercadoLibre,Tiendanube}ConversionPadronTest.php  # Extender
```

**Structure Decision**: Se sigue el patrón ya establecido por spec 037/034 — un wrapper delgado por WSDL
(`ClienteConstanciaInscripcion`, mismo molde que `ClientePadron`/`ClienteWsfev1`), sin introducir una capa
de abstracción nueva. `ResultadoConsultaPadron` pasa a aceptar (opcionalmente) la respuesta de la segunda
consulta para fusionar los datos, en lugar de crear una clase de resultado paralela — mantiene un único
punto de verdad para "los datos fiscales resueltos de un CUIT" en los dos puntos de integración ya
existentes.

## Complexity Tracking

*No aplica — sin violaciones de la Constitution Check.*
