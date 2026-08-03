# Implementation Plan: Consulta al Padrón Fiscal de ARCA

**Branch**: `037-padron-arca-cuit` | **Date**: 2026-08-03 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/037-padron-arca-cuit/spec.md`

## Summary

Agregar consulta al servicio de padrón de contribuyentes de ARCA (`ws_sr_padron_a13`), reusando
la autenticación WSAA y el certificado fiscal ya implementados en spec 034. Se integra en dos
puntos: (1) el botón "Verificar" del modal de cliente, que hoy sólo valida el dígito verificador
localmente, pasa a consultar además el padrón real cuando el documento es CUIT/CUIL y ofrece
autocompletar razón social/domicilio fiscal/condición de IVA, editable; (2) internamente, sin UI,
durante la conversión de una orden de Tiendanube o MercadoLibre en venta — cuando el comprador no
tiene condición de IVA ya resuelta (cliente nuevo o sin `condicion_iva_id`), se consulta el padrón
con el CUIT de la orden para reemplazar la aproximación actual por longitud de documento, y si el
cliente creado/actualizado no tenía datos fiscales, se completan con lo que devuelve el padrón. En
todos los casos, la indisponibilidad de ARCA degrada al comportamiento actual sin bloquear.

## Technical Context

**Language/Version**: PHP 8.2, Laravel 12

**Primary Dependencies**: `ext-soap` (mismo mecanismo que `ClienteWsfev1`), `App\Services\Arca\ClienteWsaa` (WSAA ya implementado, spec 034), `App\Models\CertificadoFiscal`

**Storage**: MySQL — no se agregan tablas nuevas; se reutilizan `clientes` (columnas `cuit`, `razon_social`, `domicilio_fiscal`, `localidad_fiscal`, `condicion_iva_id` ya existentes) y `condiciones_iva`. El resultado de una consulta al padrón es transitorio (no se persiste como entidad propia), salvo lo que se escribe en `clientes` según FR-002/FR-007b.

**Testing**: PHPUnit (Feature/Unit), mismo patrón que spec 034 (`tests/Feature/Integraciones/...`, mocks de `SoapClient`/del servicio de padrón sin llamar a ARCA real en tests)

**Target Platform**: Servidor Laravel (mismo demo/VPS existente)

**Project Type**: Web application (Laravel monolito, Blade + AJAX) — single project, no hay frontend/backend separados

**Performance Goals**: Respuesta de la consulta al padrón visible en el modal de cliente en menos de 5s (SC-001, ver quickstart). No aplica a la conversión de órdenes en lote más que "no degradar visiblemente el tiempo de conversión por orden" (sin métrica dura: se acota con el mismo timeout de conexión de 15s usado en `ClienteWsaa`/`ClienteWsfev1`, ajustado a un valor menor para no demorar conversiones en lote — ver research.md).

**Constraints**: Debe reusar el certificado fiscal activo único (`CertificadoFiscal::activo()`, single-tenant); no debe requerir configuración/credenciales adicionales; debe degradar sin excepción no controlada ante indisponibilidad de ARCA (Constitución III: resiliencia ante caídas de ARCA); no debe alterar el estado `aprobado`/CAE de ningún comprobante (esta feature no toca la emisión, sólo la elección de tipo de comprobante antes de emitir).

**Scale/Scope**: Volumen bajo — negocio single-tenant, consultas puntuales (alta/edición manual de cliente) y por orden convertida (no en lote masivo simultáneo más allá de "convertir todas las listas", que ya es secuencial hoy).

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **I. Documentación de dominio como fuente de verdad**: `docs/documentacion_principal_crm.md` (líneas 69-79, 1543-1550) y `specs/014-verificacion-documento-fiscal/spec.md` (Assumptions) ya documentan este pendiente como diferido hasta contar con WSAA. Se actualizarán ambos documentos antes de `/speckit-tasks` para reflejar que el padrón ahora está en alcance (spec 037) — tarea pendiente en este mismo plan. **PASA** (con acción pendiente, no bloqueante para el plan).
- **II. Desarrollo spec-driven**: esta feature sigue el flujo completo specify→clarify→plan→checklist→tasks→analyze. **PASA**.
- **III. Corrección fiscal innegociable (ARCA)**: el tipo de comprobante sigue derivándose de la condición de IVA (ahora also informada por el padrón cuando no hay una ya cargada) — no se salta la regla de "no elegir el tipo a mano". La consulta al padrón es resiliente (degrada sin bloquear) igual que exige la constitución para las caídas de ARCA. No se toca CAE ni aprobación de comprobantes. **PASA**.
- **IV. Testing donde hay dinero o impacto fiscal**: la determinación de tipo de comprobante (FR-007/FR-007a) tiene impacto fiscal directo → requiere tests (mock del servicio de padrón) cubriendo: cliente nuevo con CUIT válido en padrón (A), cliente nuevo con CUIT no encontrado (B, fallback), cliente existente con condición de IVA ya cargada (padrón no se consulta o no pisa), ARCA no disponible (fallback sin excepción). Se incluye en tasks.md. **PASA**.
- **V. Convenciones Laravel + dominio en español**: nuevo servicio se llama en español (`ClientePadron`), sin `empresa_id` ni multi-tenant. **PASA**.

No hay violaciones que requieran justificación en Complexity Tracking.

## Project Structure

### Documentation (this feature)

```text
specs/037-padron-arca-cuit/
├── plan.md              # This file (/speckit-plan command output)
├── research.md          # Phase 0 output (/speckit-plan command)
├── data-model.md        # Phase 1 output (/speckit-plan command)
├── quickstart.md        # Phase 1 output (/speckit-plan command)
├── contracts/           # Phase 1 output (/speckit-plan command)
└── tasks.md             # Phase 2 output (/speckit-tasks command - NOT created by /speckit-plan)
```

### Source Code (repository root)

```text
# Laravel monolito existente — single project, se extiende in-place

app/
├── Services/
│   ├── Arca/
│   │   ├── ClienteWsaa.php            # existente (spec 034) — reusado tal cual, servicio='ws_sr_padron_a13'
│   │   ├── ClienteWsfev1.php          # existente (spec 034) — sin cambios
│   │   └── ClientePadron.php          # NUEVO — wrapper SOAP de ws_sr_padron_a13 (consultarConstancia)
│   ├── Tiendanube/
│   │   └── ResolutorCliente.php       # tipoComprobante() consulta ClientePadron cuando no hay condicion_iva_id
│   └── MercadoLibre/
│       └── ResolutorCliente.php (o su derivador de tipo de comprobante análogo) — mismo cambio
├── Http/Controllers/
│   └── ClienteController.php          # verificarDocumento() consulta ClientePadron para CUIT/CUIL
├── Models/
│   └── CertificadoFiscal.php          # sin cambios estructurales
config/
└── arca.php                            # + entrada wsdl.ws_sr_padron_a13 (homologación/producción)
resources/
├── views/clientes/_modal_form.blade.php  # sin cambios estructurales (mismo botón "Verificar")
└── js/clientes.js                      # pintarResultadoVerificacion() extendido para autocompletar campos

tests/
└── Feature/
    ├── ClienteVerificarPadronTest.php              # NUEVO
    └── Integraciones/
        └── TiendanubeConversionPadronTest.php        # NUEVO (y análogo MercadoLibre)
```

**Structure Decision**: Se mantiene la estructura Laravel monolítica existente (Option 1, sin
separación frontend/backend). El único componente nuevo es `App\Services\Arca\ClientePadron`,
hermano de `ClienteWsfev1` dentro de `app/Services/Arca/`, siguiendo el mismo patrón (constructor
con `CertificadoFiscal`, método `llamar()` privado con manejo de `SoapFault` → `ArcaNoDisponibleException`).
Los puntos de integración (`ClienteController::verificarDocumento`, `ResolutorCliente::tipoComprobante`
de Tiendanube y su análogo de MercadoLibre) se modifican in-place, sin nuevas capas de abstracción.

## Complexity Tracking

*Sin violaciones a justificar — no aplica.*
