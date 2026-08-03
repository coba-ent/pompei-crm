# Implementation Plan: Facturación Electrónica (ARCA/AFIP)

**Branch**: `034-facturacion-electronica-arca` | **Date**: 2026-08-02 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `/specs/034-facturacion-electronica-arca/spec.md`

## Summary

Conectar Ventas y Compras a los webservices reales de ARCA (WSAA para autenticación con
certificado propio del negocio + WSFEv1 para solicitud de CAE), reemplazando la numeración local
sin validez fiscal por comprobantes con CAE real, sin re-emisión retroactiva de lo ya emitido.
Sigue el mismo patrón arquitectónico ya usado para integraciones externas (Mercado Libre spec 011,
Tiendanube spec 019/022): un `Service` por responsabilidad bajo `app/Services/Arca/`, credenciales
sensibles cifradas con el helper `encrypted` de Eloquent (mismo patrón que
`MercadoLibreConfiguracion`/`TiendanubeConexionRest`), y un log de auditoría propio por operación.

## Technical Context

**Language/Version**: PHP 8.2, Laravel 12

**Primary Dependencies**: Eloquent ORM; cliente SOAP nativo de PHP (`SoapClient`) para WSAA/WSFEv1
(estándar de la industria para estos webservices ARCA, sin paquete de terceros necesario); librería
de firma XML/CMS (`openssl_pkcs7_sign` nativo de PHP) para el TRA (Ticket de Requerimiento de
Acceso) de WSAA.

**Storage**: MySQL (misma DB `contagram`); certificado `.crt`/`.key` cifrado en disco fuera del
webroot (`storage/app/arca/`, no público), referenciado por ruta desde la tabla
`certificados_fiscales`; campos sensibles de configuración cifrados con `encrypted` cast de
Eloquent.

**Testing**: PHPUnit (Pest no está en uso en este proyecto — confirmar con `composer.json`); tests
de integración contra el ambiente de **homologación** de ARCA con datos de prueba, mockeando
`SoapClient` en tests unitarios para no depender de red.

**Target Platform**: Servidor Linux (mismo VPS Hostinger del demo), Laravel corriendo vía PHP-FPM.

**Project Type**: Web (Laravel monolito Blade + Vite, sin frontend separado).

**Performance Goals**: Emisión de un comprobante (incluida ida y vuelta a WSFEv1) en menos de 15s
en condiciones normales (SC-001); reutilización del Ticket de Acceso WSAA evita re-autenticar en
cada solicitud (WSAA sólo tolera pocas autenticaciones por día por servicio).

**Constraints**: Certificado ARCA del negocio no disponible aún (prerequisito operativo externo,
ver spec Assumptions) — el desarrollo y los tests de integración usan el ambiente de homologación
de ARCA con un certificado de prueba propio hasta que el certificado de producción esté disponible.
Sin reintento automático (decisión de clarify): todo reintento post-error es una acción explícita
del usuario.

**Scale/Scope**: Single-tenant, un único Punto de Venta activo por defecto; volumen esperado del
negocio (decenas de comprobantes/día), muy por debajo de los límites de rate-limit de WSFEv1.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **Principio I (Documentación de dominio como fuente de verdad)**: PASS con nota. La spec se basó
  en `documentacion_principal_crm.md` §3.2/§3.5/§4.1/§4.3/§7 y `modelo_datos.md`. No existe
  `docs/informe_contagram_facturacion.md` con capturas reales — brecha documentada explícitamente en
  spec Assumptions, no se inventó estructura de pantalla no relevada. Antes de `/speckit-tasks` se
  actualizan `documentacion_principal_crm.md` §7 y `modelo_datos.md` §9 con las entidades nuevas
  (ver Fase 1).
- **Principio II (Desarrollo spec-driven)**: PASS — este plan es parte de la cadena
  specify→clarify→plan→checklist→tasks→analyze en curso.
- **Principio III (Corrección fiscal innegociable)**: PASS, gate central de este módulo.
  Verificado explícitamente:
  - CAE obligatorio para estado `aprobado` — modelado en `data-model.md` (`ComprobanteFiscal.estado`
    ∈ {pendiente, aprobado, rechazado}, nunca `aprobado` sin `cae` no-nulo).
  - Tipo de comprobante A/B/C ya se deriva de la Condición de IVA del cliente/emisor desde spec 008
    (`documentacion_principal_crm.md` §3.6) — este módulo no reintroduce selección manual, sólo
    transmite el tipo ya validado a WSFEv1; FR-009 bloquea la emisión si faltan datos fiscales
    mínimos para ese tipo.
  - Resiliencia ante caída de ARCA: FR-010/FR-011 — la Venta/Compra se guarda igual (no se pierde la
    operación), sólo el comprobante fiscal queda `pendiente` hasta reintentar manualmente.
  - Soft delete de documentos fiscales: `comprobantes_fiscales` usa `SoftDeletes` igual que
    `ventas`/`compras` existentes — ningún comprobante con CAE se borra físicamente.
  - Alerta de vencimiento de certificado: FR nueva a incorporar (ver Fase 1 — se agrega
    `FR-016` sobre aviso proactivo de vencimiento de certificado, conectando con el módulo de
    Notificaciones ya anotado en `documentacion_principal_crm.md` §7).
- **Principio IV (Testing donde hay dinero o impacto fiscal)**: PASS — plan incluye tests
  unitarios de cálculo/mapeo de datos hacia WSFEv1 y tests de integración contra homologación (ver
  Technical Context > Testing); se detallan en `tasks.md`.
- **Principio V (Convenciones Laravel + dominio en español)**: PASS — nombres de tablas/modelos en
  español (`puntos_venta`, `certificados_fiscales`, `comprobantes_fiscales`), sin `empresa_id`
  (single-tenant), servicios bajo `app/Services/Arca/` siguiendo el mismo patrón de
  `app/Services/MercadoLibre` y `app/Services/Tiendanube`.

**Resultado**: Sin violaciones. No se requiere Complexity Tracking.

## Project Structure

### Documentation (this feature)

```text
specs/034-facturacion-electronica-arca/
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
│   ├── PuntoVenta.php
│   ├── CertificadoFiscal.php
│   └── ComprobanteFiscal.php
├── Services/
│   └── Arca/
│       ├── ClienteWsaa.php           # Autenticación WSAA, cachea Ticket de Acceso
│       ├── ClienteWsfev1.php         # Cliente SOAP de WSFEv1 (FECAESolicitar, FECompConsultar)
│       ├── EmisorComprobante.php     # Orquesta: valida datos fiscales, arma request, guarda ComprobanteFiscal
│       ├── MapeadorComprobante.php   # Traduce Venta/Compra/NC-ND del CRM al formato WSFEv1
│       ├── ValidadorDatosFiscales.php # FR-009: CUIT válido según tipo de comprobante
│       └── Excepciones/
│           ├── ArcaNoDisponibleException.php
│           ├── ArcaRechazoException.php
│           └── CertificadoNoConfiguradoException.php
├── Http/Controllers/
│   └── FacturacionElectronicaController.php   # CRUD Puntos de Venta + carga de certificado (controladores del proyecto son planos, sin subcarpetas — ver VentaController.php, CompraController.php)
├── Console/Commands/
│   └── ArcaRenovarTicketAcceso.php    # opcional: pre-calienta el TA antes de que expire
└── Jobs/                              # sin colas nuevas (reintento es manual, FR-010) — N/A

database/migrations/
├── ..._create_puntos_venta_table.php
├── ..._create_certificados_fiscales_table.php
├── ..._create_comprobantes_fiscales_table.php
└── ..._create_arca_logs_auditoria_table.php

resources/views/configuracion/facturacion-electronica/
└── index.blade.php                    # modal Bootstrap + AJAX, Select2 si aplica (reglas CLAUDE.md)

tests/
├── Unit/Services/Arca/
│   ├── MapeadorComprobanteTest.php
│   └── ValidadorDatosFiscalesTest.php
└── Feature/
    ├── EmisionComprobanteVentaTest.php
    ├── EmisionComprobanteRechazoTest.php
    └── FacturacionElectronicaConfiguracionTest.php
```

**Structure Decision**: Laravel monolito existente (Opción 1 adaptada). Se reutiliza la estructura
`app/Services/<Integracion>/` ya validada en Mercado Libre (spec 011-013) y Tiendanube (spec
019/022) para mantener el mismo patrón de integración externa: cliente HTTP/SOAP de bajo nivel +
servicio orquestador + mapeador de datos + excepciones tipadas. No se agrega un directorio
`frontend/` separado — las vistas nuevas son Blade dentro de `resources/views/configuracion/` y
`resources/views/ingresos/`/`egresos/` existentes (modal de "Ver Detalle" ganan el bloque CAE).

## Complexity Tracking

*Sin violaciones de la constitución — sección no aplica.*
