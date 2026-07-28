# Implementation Plan: Funciones Avanzadas + Conexión Mercado Libre (OAuth)

**Branch**: `011-mercadolibre-conexion-oauth` | **Date**: 2026-07-27 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `specs/011-mercadolibre-conexion-oauth/spec.md`

## Summary

Dos entregables encadenados:

1. **Pantalla "Funciones Avanzadas"** (`/configuracion/funciones`), que hoy no existe: lista vertical
   de las 10 tarjetas relevadas de Contagram con toggle Sí/No persistido, reutilizando el permiso
   `configuracion.funciones` que ya existe en el proyecto.
2. **Integración Mercado Libre — capa de conexión**: configuración de la aplicación propia del
   DevCenter, vinculación por OAuth 2.0 (authorization code, sin PKCE), panel de estado con los datos
   reales de la cuenta, prueba de conexión, desconexión, kill-switch de sólo lectura e historial de
   operaciones.

**Enfoque técnico**: un `ClienteMercadoLibre` como **punto único de salida** hacia la API, que resuelve
en un solo lugar tres cosas que si se dispersan generan bugs: (a) la renovación perezosa del token bajo
lock atómico —el `refresh_token` de Mercado Libre es de un solo uso y dos renovaciones concurrentes
matan la conexión—, (b) el bloqueo de escrituras en modo sólo lectura, y (c) el registro de toda
operación en el historial. Todo sincrónico dentro del request: nada en esta spec necesita un proceso
permanente, lo que garantiza que corra igual en hosting compartido y en VPS.

Esta spec **no** sincroniza publicaciones, stock ni ventas: deja la base de conexión sobre la que se
apoyarán las specs siguientes.

## Technical Context

**Language/Version**: PHP 8.2, Laravel 12

**Primary Dependencies**: Laravel 12, Eloquent, Blade sobre template NexaDash, `Http` facade (Guzzle,
incluido en Laravel), `yajra/laravel-datatables-oracle ^12` (ya en `composer.json`). **Sin dependencias
nuevas** — no se incorpora ningún SDK de Mercado Libre: los tres endpoints que consume esta spec
(`/oauth/token`, `/users/me`, autorización) no justifican una dependencia externa que además obligaría
a seguir su ciclo de releases.

**Externa**: API de Mercado Libre (`api.mercadolibre.com`) y dominio de autorización por sitio
(`auth.mercadolibre.com.ar` para MLA).

**Storage**: MySQL (`contagram`). 5 tablas nuevas: `funciones_avanzadas`, `ml_configuracion`,
`ml_cuentas`, `ml_solicitudes_vinculacion`, `ml_operaciones_log`. Secretos cifrados con casts
`encrypted` (respaldados por `APP_KEY`).

**Testing**: PHPUnit sobre SQLite en memoria, con `Http::fake()` para simular la API de Mercado Libre.
Foco según Principio IV de la constitución — acá no hay cálculo de dinero, pero sí dos puntos de riesgo
real que exigen test: **concurrencia de renovación** (SC-004) y **bloqueo de escrituras** (SC-005). Se
suman los flujos de error de OAuth (state inválido, vencido, reusado; sitio incorrecto; autorización
cancelada) por ser superficie de seguridad.

**Target Platform**: Aplicación web. **Requiere dirección pública con conexión segura** para el retorno
de autorización — Mercado Libre no admite direcciones locales ni sin cifrar. En desarrollo local el
flujo OAuth no puede completarse; se prueba con `Http::fake()` y de punta a punta en el entorno
publicado.

**Project Type**: Web application monolítica Laravel (backend + Blade), single-tenant.

**Performance Goals**: Pantalla de configuración de carga inmediata (sólo lee registros únicos). El
historial pagina del lado del servidor. Timeouts hacia Mercado Libre: 10 s de conexión, 30 s de
respuesta, para no agotar el `max_execution_time` del hosting compartido.

**Constraints**:
- **Portabilidad de entorno** (SC-010): sin procesos permanentes ni almacenamiento en memoria. Los
  locks usan el driver de cache `database` (tabla `cache_locks`, ya creada por la migración
  `0001_01_01_000001_create_cache_table.php` — verificado).
- Single-tenant: sin `empresa_id`. `ml_configuracion` y `ml_cuentas` son registros únicos.
- Dominio en español (Principio V), con la excepción de los nombres propios del proveedor.
- Ninguna credencial se registra en logs ni se devuelve a la interfaz.

**Scale/Scope**: 5 tablas + 5 modelos, 1 servicio de dominio (`ClienteMercadoLibre`) + 1 servicio de
OAuth (`VinculacionMercadoLibre`), 3 controladores (`FuncionAvanzadaController`,
`MercadoLibreConfiguracionController`, `MercadoLibreOAuthController`), 2 vistas + partials, 1 archivo JS.

## Constitution Check

*GATE: debe pasar antes de Phase 0. Re-evaluado después de Phase 1.*

| Principio | Evaluación | Estado |
|---|---|---|
| **I. Documentación de dominio como fuente de verdad** | Se leyeron `docs/informe_contagram_funciones_avanzadas.md` (§1 estructura de pantalla, §3 Mercado Libre) y el estado actual del CRM antes de especificar. La spec declara el impacto en `documentacion_principal_crm.md` y `modelo_datos.md`, y el plan lo convierte en tareas **bloqueantes previas a la implementación** (ver Fase A de tasks). | ✅ PASA |
| **II. Desarrollo spec-driven** | Integración de negocio → no exenta. Se recorre el flujo completo `specify → plan → tasks → analyze → implement`. Sin código previo. | ✅ PASA |
| **III. Corrección fiscal innegociable** | No aplica: esta spec no toca comprobantes, CAE ni ARCA. Se registra explícitamente para que no se interprete como omisión. | ✅ N/A |
| **IV. Testing donde hay dinero o impacto fiscal** | No hay cálculo de importes. Sí hay dos riesgos que justifican test obligatorio por criterio equivalente (pérdida de servicio y daño sobre datos reales del negocio): concurrencia de renovación y bloqueo de escrituras. Se suman los flujos de seguridad de OAuth. | ✅ PASA |
| **V. Convenciones Laravel + dominio en español** | Tablas, columnas, rutas y textos en español; estructura MVC estándar; sin pelear contra el framework. Single-tenant respetado (registros únicos, sin `empresa_id`). | ✅ PASA |
| **Restricción de secretos** | Credenciales cifradas en reposo, `$hidden` en los modelos, excluidas del historial y de los logs. Nada se versiona. | ✅ PASA |
| **Principio rector `CLAUDE.md` (fidelidad estructural)** | La pantalla contenedora **sí** calca a Contagram (10 tarjetas, orden relevado). La divergencia está **acotada al contenido de la tarjeta de Mercado Libre**, es deliberada, está autorizada por el usuario y se documenta como excepción explícita. No es una simplificación silenciosa — que es lo que el principio prohíbe. | ✅ PASA con excepción documentada |
| **Especificaciones de diseño obligatorias** | DataTables server-side para el historial; modales Bootstrap + AJAX sin recarga; toasts de NexaDash; Select2 para el selector de sitio. Sin PDFs en esta spec. | ✅ PASA |

**Resultado del gate**: pasa sin violaciones que requieran justificación en Complexity Tracking.

**Nota sobre la excepción al principio rector**: no es una violación de la constitución sino del
principio rector operativo del `CLAUDE.md`, que exige que las divergencias respecto de Contagram estén
**documentadas** en lugar de improvisadas. La tarea A2 (actualizar `documentacion_principal_crm.md`)
es lo que la convierte en conforme, y por eso es bloqueante.

## Project Structure

### Documentation (this feature)

```text
specs/011-mercadolibre-conexion-oauth/
├── plan.md              # Este archivo
├── spec.md              # Especificación funcional
├── research.md          # Phase 0 — decisiones técnicas (R1..R12)
├── data-model.md        # Phase 1 — esquema de las 5 tablas nuevas
├── quickstart.md        # Phase 1 — guía de validación de punta a punta
├── contracts/
│   ├── rutas-internas.md        # Endpoints del CRM (HTTP + JSON)
│   └── api-mercadolibre.md      # Contrato con el proveedor externo
├── checklists/
│   └── requirements.md  # Checklist de calidad de la spec
└── tasks.md             # Phase 2 — generado por /speckit-tasks
```

### Source Code (repository root)

```text
app/
├── Http/
│   ├── Controllers/
│   │   ├── Configuracion/
│   │   │   └── FuncionAvanzadaController.php        # pantalla + toggles
│   │   └── Integraciones/
│   │       ├── MercadoLibreConfiguracionController.php  # credenciales, estado, probar, desconectar, historial
│   │       └── MercadoLibreOAuthController.php          # iniciar vinculación + retorno
│   ├── Requests/
│   │   └── Integraciones/
│   │       └── GuardarConfiguracionMercadoLibreRequest.php
│   └── Middleware/                                   # (sin middleware nuevo: se reutiliza 'permiso')
├── Models/
│   ├── FuncionAvanzada.php
│   └── Integraciones/
│       ├── MercadoLibreConfiguracion.php
│       ├── MercadoLibreCuenta.php
│       ├── MercadoLibreSolicitudVinculacion.php
│       └── MercadoLibreOperacionLog.php
├── Services/
│   └── MercadoLibre/
│       ├── ClienteMercadoLibre.php        # punto ÚNICO de salida: renovación + kill-switch + log
│       ├── VinculacionMercadoLibre.php    # armado de URL de autorización, canje, desvinculación
│       ├── RespuestaMercadoLibre.php      # objeto de resultado (ok / bloqueada / error)
│       └── Excepciones/
│           ├── ConexionCaidaException.php
│           ├── CredencialesIlegiblesException.php
│           └── EscrituraBloqueadaException.php
└── Enums/
    └── MercadoLibre/
        └── EstadoConexion.php             # no_configurada | desconectada | conectada | caida

database/
├── migrations/
│   ├── 2026_08_01_060001_create_funciones_avanzadas_table.php
│   ├── 2026_08_01_060002_create_ml_configuracion_table.php
│   ├── 2026_08_01_060003_create_ml_cuentas_table.php
│   ├── 2026_08_01_060004_create_ml_solicitudes_vinculacion_table.php
│   └── 2026_08_01_060005_create_ml_operaciones_log_table.php
└── seeders/
    └── FuncionAvanzadaSeeder.php          # las 10 funciones relevadas, en orden

resources/
├── views/configuracion/
│   ├── funciones.blade.php                # lista de 10 tarjetas
│   └── mercadolibre/
│       ├── index.blade.php                # configuración + estado + historial
│       ├── _panel_estado.blade.php
│       ├── _modal_credenciales.blade.php
│       └── _modal_desconectar.blade.php
└── js/
    ├── funciones-avanzadas.js
    └── mercadolibre.js

routes/web.php                             # grupo configuracion.funciones + configuracion.mercadolibre

tests/Feature/Integraciones/
├── FuncionesAvanzadasTest.php
├── MercadoLibreConfiguracionTest.php
├── MercadoLibreOAuthTest.php              # state inválido/vencido/reusado, sitio incorrecto, cancelación
├── MercadoLibreRenovacionTokenTest.php    # SC-004: concurrencia
├── MercadoLibreModoSoloLecturaTest.php    # SC-005: bloqueo de escrituras
└── MercadoLibreManejoErroresTest.php      # 401 / 403 / 429 / 5xx
```

**Structure Decision**: se sigue la estructura MVC estándar de Laravel ya vigente en el proyecto. Dos
decisiones de organización:

- **Subcarpeta `Integraciones/`** en controladores y modelos: Mercado Libre es la primera de al menos
  tres integraciones previstas (Tiendanube y ARCA vienen después). Agruparlas evita que `app/Models/`
  se llene de clases con prefijo. Los servicios van en `app/Services/MercadoLibre/` siguiendo el patrón
  ya usado por `Services/Tesoreria`, `Services/Ingresos`, `Services/Egresos`.
- **Prefijo `ml_` en las tablas**: agrupa visualmente el esquema de la integración y evita colisión con
  nombres del dominio propio (`cuentas` ya se usa en Tesorería como `cuentas_tesoreria`). `funciones_avanzadas`
  **no** lleva prefijo porque es del CRM, no de la integración.

## Fases de implementación

Orden derivado de dependencias reales, no de comodidad:

| Fase | Contenido | Dependencia |
|---|---|---|
| **A. Documentación de dominio** | Actualizar `documentacion_principal_crm.md` (pantalla Funciones Avanzadas + sección Mercado Libre con la divergencia documentada) y `modelo_datos.md` (5 entidades nuevas) | **Bloqueante** por Principio I: debe estar antes de escribir código |
| **B. Persistencia** | 5 migraciones + 5 modelos + seeder de funciones + enum de estado | A |
| **C. Funciones Avanzadas** | Controlador, ruta, vista de 10 tarjetas, JS, entrada de sidebar, tests | B — entregable independiente y demostrable (User Story 1) |
| **D. Configuración de la integración** | Request de validación, controlador de configuración, vista, modal de credenciales, cifrado, tests | B — (User Story 2) |
| **E. Cliente y OAuth** | `ClienteMercadoLibre` (renovación + lock + kill-switch + log), `VinculacionMercadoLibre`, controlador OAuth, panel de estado, tests de concurrencia y de errores | D — (User Stories 3 y 5) |
| **F. Sólo lectura e historial** | Kill-switch, historial con DataTables server-side, retención, tests | E — (User Story 4) |
| **G. Validación de punta a punta** | Ejecución de `quickstart.md` contra el entorno publicado con usuarios de prueba de Mercado Libre | E, F |

Las fases C y D son independientes entre sí y podrían encararse en cualquier orden; ambas dependen de B.

## Complexity Tracking

> Sólo se completa si el Constitution Check tiene violaciones que requieran justificación.

**No aplica**: el Constitution Check pasó sin violaciones. La única desviación —la divergencia respecto
de Contagram— está contemplada y autorizada por el principio rector, que exige documentarla (tarea A2),
no evitarla.

## Post-Design Constitution Re-Check

*Re-evaluado tras generar `data-model.md`, `contracts/` y `quickstart.md`.*

| Verificación | Resultado |
|---|---|
| ¿El diseño introdujo entidades o reglas de negocio nuevas no documentadas? | Sí: las 5 entidades. Cubierto por la tarea A3 (actualizar `modelo_datos.md`), bloqueante antes de implementar. |
| ¿El diseño requiere dependencias nuevas? | No. Se usa lo ya presente en el proyecto. |
| ¿Se introdujo complejidad no justificada? | No. La única estructura no obvia es el lock de renovación, justificado por un modo de fallo real y verificable (R4, SC-004). |
| ¿El diseño sigue siendo portable entre hosting compartido y VPS? | Sí. Todo es sincrónico y los locks usan el driver `database`, ya disponible. Verificado que la tabla `cache_locks` existe. |
| ¿Algún secreto queda expuesto en el diseño? | No. Cifrado en reposo, `$hidden` en modelos, proyecciones explícitas en respuestas, exclusión previa a persistir en el historial. |
| ¿El diseño respeta el idioma del dominio? | Sí, con la excepción de los identificadores propios del proveedor (`access_token`, `site_id`), que se conservan por ser nombres del contrato externo. |

**Resultado**: el diseño mantiene la conformidad. Sin cambios necesarios en el plan.
