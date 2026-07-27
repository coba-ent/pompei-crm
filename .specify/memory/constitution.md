<!--
Sync Impact Report
- Version change: (plantilla inicial) → 1.0.0
- Bump rationale: primera ratificación de la constitución (se adopta como 1.0.0).
- Principios definidos:
  1. Documentación de dominio como fuente de verdad
  2. Desarrollo spec-driven
  3. Corrección fiscal innegociable (ARCA)
  4. Testing donde hay dinero o impacto fiscal
  5. Convenciones Laravel + dominio en español
- Secciones añadidas: Restricciones técnicas; Flujo de desarrollo y calidad; Governance
- Plantillas revisadas:
  - .specify/templates/plan-template.md ✅ (Constitution Check alineado con estos principios)
  - .specify/templates/spec-template.md ✅ (sin requisitos que contradigan)
  - .specify/templates/tasks-template.md ✅ (categorías de tareas compatibles)
- Follow-ups: ninguno. Sin tokens diferidos.
-->

# Constitución de Contagram CRM

Sistema de gestión (CRM) para un **único negocio particular argentino** (single-tenant),
inspirado funcionalmente en Contagram. Backend Laravel 12 + Eloquent, MySQL, con facturación
electrónica ARCA (ex AFIP). Esta constitución fija los principios innegociables del proyecto;
toda spec, plan, tarea e implementación DEBE respetarla.

## Principios Fundamentales

### I. Documentación de dominio como fuente de verdad

`docs/documentacion_principal_crm.md` (spec funcional) y `docs/modelo_datos.md` (esquema de
datos) son la referencia de dominio autoritativa. Reglas:

- Antes de especificar o planificar un módulo, se DEBE leer la sección correspondiente de estos
  documentos; las decisiones se basan en lo ya relevado, no se reinventan.
- Si una spec, un plan o el código revelan una regla de negocio, campo o entidad nueva —o
  corrigen algo que estos documentos tenían mal— se DEBEN actualizar estos documentos **en el
  mismo cambio**, antes de continuar.
- Si una spec contradice estos documentos, la contradicción se resuelve explícitamente (se
  ajusta el doc o la spec) antes de avanzar. Está prohibido seguir con la inconsistencia en
  silencio.

Rationale: el conocimiento del dominio (fiscal, contable, de stock) es lo más costoso de
recuperar; mantenerlo centralizado y vivo evita que el código y el entendimiento diverjan.

### II. Desarrollo spec-driven

Ninguna funcionalidad de negocio se implementa sin pasar antes por el flujo de spec-kit:
`specify → (clarify) → plan → (checklist) → tasks → (analyze) → implement`.

- Cambios triviales (typos, ajustes de estilo, config) están exentos.
- Toda feature de negocio (un módulo, una pantalla, un cálculo, una integración) NO está exenta.
- El artefacto de spec vive en `specs/` y precede al código; el código sin spec asociada es
  deuda a regularizar.

Rationale: el proyecto se construye módulo por módulo sobre un dominio complejo; especificar
antes de codear reduce retrabajo y deja trazabilidad de por qué se hizo cada cosa.

### III. Corrección fiscal innegociable (ARCA)

La facturación electrónica es la parte más crítica del sistema. Reglas NO negociables:

- Un comprobante NO se considera válido ni se presenta como emitido sin **CAE** aprobado por
  ARCA. Sin CAE, el estado es `pendiente`/`rechazado`, nunca `aprobado`.
- El tipo de comprobante (A/B/C/E) se deriva de la condición de IVA del cliente y del emisor;
  no se permite emitir sin condición de IVA cargada, ni elegir el tipo "a mano" salteando esa
  regla (incluida la leyenda Ley 27.618 cuando corresponde).
- El sistema DEBE ser resiliente ante caídas de ARCA: se permite registrar la venta y reintentar
  la obtención del CAE después, sin pérdida de datos ni de la operación.
- Documentos fiscales o con impacto contable (ventas, compras, gastos, comprobantes) usan
  **soft delete**: nunca se borran físicamente, por trazabilidad.
- Se alerta proactivamente sobre el vencimiento del certificado digital (antes de que falle).

Rationale: un error acá no es un bug de UI, es un problema legal/impositivo para el negocio.

### IV. Testing donde hay dinero o impacto fiscal

El testing es obligatorio, con foco proporcional al riesgo:

- Se DEBEN escribir tests (preferentemente antes de la implementación) para toda lógica que
  involucre: cálculo de importes/IVA/descuentos/totales, emisión y numeración de comprobantes,
  CAE, movimientos de stock, y saldos de cuentas corrientes/tesorería.
- CRUD simple y vistas pueden no requerir tests estrictos, a criterio, pero un bug reportado en
  esas áreas obliga a agregar el test que lo cubra.
- Ningún cambio en lógica fiscal o de dinero se da por terminado sin su test en verde.

Rationale: concentrar el esfuerzo de testing donde un error tiene consecuencias reales, sin
frenar el desarrollo de lo que no las tiene.

### V. Convenciones Laravel + dominio en español

- El dominio se nombra en **español**: tablas, columnas, modelos, rutas, vistas y textos de UI
  (`ventas`, `clientes`, `comprobantes_fiscales`, etc.), snake_case en la base de datos.
- Las convenciones propias del framework se respetan tal cual (estructura MVC de Laravel,
  Eloquent, migraciones versionadas, FormRequests, Observers/Services para recálculos, relaciones
  polimórficas estándar). No se pelea contra el framework.
- El sistema es single-tenant: **no** existe `empresa_id` ni Global Scope por empresa; la
  configuración del negocio vive en la fila única de la tabla `empresa`.

Rationale: nombres en el idioma del negocio hacen el código legible para quien conoce el dominio;
seguir las convenciones de Laravel mantiene el proyecto mantenible y predecible.

## Restricciones Técnicas

- **Stack**: Laravel 12 (PHP 8.2+), Eloquent, MySQL/MariaDB. Frontend sobre el template Bootstrap
  5 NexaDash (Blade), con Vite para assets. Toda vista extiende `layouts.default`.
- **Base visual**: se reutilizan los componentes del template (`resources/views/elements/`); el
  sidebar ya está wireado con los 8 módulos del CRM.
- **ARCA**: integración vía SOAP (WSAA para token/sign válido 12 hs + WSFEv1 para CAE). Certificados
  X.509 y claves privadas se guardan de forma segura (clave privada encriptada / fuera del repo).
- **Secretos**: certificados, claves y `.env` nunca se commitean.
- **Jobs/colas**: tareas asincrónicas (reintentos de CAE, reportes por mail, abonos recurrentes)
  van por colas de Laravel.

## Flujo de Desarrollo y Calidad

- Se trabaja módulo por módulo siguiendo el mapa de los 8 módulos documentados.
- Cada módulo: leer docs → spec → plan → tasks → implement, actualizando docs si algo cambió.
- Las reglas de negocio críticas ya detectadas (sección 11 del doc principal) se tratan como
  requisitos, no como opcionales (ej.: stock se afecta al vender/comprar no al remitir;
  presupuestos y gastos no afectan stock; una cuenta de tesorería con operaciones no se elimina).
- Los commits describen el "qué" y el "por qué"; los cambios de dominio incluyen la actualización
  de docs correspondiente.

## Governance

- Esta constitución prevalece sobre cualquier otra práctica. Ante conflicto entre una decisión
  puntual y un principio, gana el principio (o se enmienda la constitución explícitamente).
- **Enmiendas**: se documentan en este archivo, con bump de versión y actualización del Sync
  Impact Report. Requieren justificación del cambio.
- **Versionado** (semver de la constitución):
  - MAJOR: remoción o redefinición incompatible de un principio de gobierno.
  - MINOR: nuevo principio o sección, o expansión material de guía existente.
  - PATCH: aclaraciones, correcciones de redacción, ajustes no semánticos.
- **Cumplimiento**: specs, planes y revisiones verifican consistencia con estos principios. La
  complejidad que se aparte de un principio debe justificarse; si no se justifica, se simplifica.
- Guía operativa de runtime para el agente: `CLAUDE.md` en la raíz del proyecto.

**Version**: 1.0.0 | **Ratified**: 2026-07-17 | **Last Amended**: 2026-07-17
