# Implementation Plan: Conexión Tiendanube (Aplicación personalizada)

**Branch**: `015-tiendanube-conexion` | **Date**: 2026-07-29 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `specs/015-tiendanube-conexion/spec.md`

## Summary

Habilitar la tarjeta "Tiendanube" de Funciones Avanzadas (hoy deshabilitada) con su propia pantalla de
configuración: carga de credenciales de una **Aplicación personalizada** (identificador de tienda +
token de acceso, sin OAuth), verificación de conexión contra la API real, panel de estado con los
datos de la tienda vinculada, desconexión, kill-switch de sólo lectura e historial de operaciones
propio.

**Enfoque técnico**: análogo al `ClienteMercadoLibre` de la spec 011 —un `ClienteTiendanube` como
**punto único de salida** hacia la API, que resuelve en un solo lugar el bloqueo de escrituras en modo
sólo lectura y el registro de toda operación en el historial— pero **considerablemente más simple**:
sin flujo OAuth, sin `refresh_token`, sin renovación perezosa y sin lock de concurrencia, porque el
token de una Aplicación personalizada no vence ni se renueva (research.md §R1/§R11). Todo síncrono
dentro del request, igual de portable en hosting compartido que en servidor dedicado sin ningún
mecanismo adicional.

Esta spec **no** sincroniza catálogo, stock ni ventas: deja la base de conexión sobre la que se
apoyarán las specs siguientes (016-ventas-tiendanube, 017-stock-tiendanube), continuando el mismo
patrón de la 011 respecto de la 012/013.

## Technical Context

**Language/Version**: PHP 8.2, Laravel 12

**Primary Dependencies**: Laravel 12, Eloquent, Blade sobre template NexaDash, `Http` facade (Guzzle,
incluido en Laravel), `yajra/laravel-datatables-oracle ^12` (ya en `composer.json`). **Sin dependencias
nuevas** — no se incorpora ningún SDK de Tiendanube: el único endpoint que consume esta spec
(`GET /{store_id}/store`) no justifica una dependencia externa.

**Externa**: API de Tiendanube (`api.tiendanube.com`). Sin dominio de autorización separado (no hay
OAuth, research.md §R1/§R2).

**Storage**: MySQL (`contagram`). 2 tablas nuevas: `tn_configuracion`, `tn_operaciones_log` — bastante
menos que las 5 de Mercado Libre, porque no existe la tabla de "solicitud de vinculación pendiente" ni
la separación config/cuenta (research.md §R6). Token cifrado con cast `encrypted` (respaldado por
`APP_KEY`), mismo mecanismo que Mercado Libre.

**Testing**: PHPUnit sobre SQLite en memoria, con `Http::fake()` para simular la API de Tiendanube.
Foco según Principio IV de la constitución — no hay cálculo de dinero, pero sí un punto de riesgo real
que exige test: **bloqueo de escrituras** en modo sólo lectura (SC-003), y los flujos de error (token
inválido, tienda incorrecta, conexión caída, rate limit) por ser superficie de seguridad y
confiabilidad.

**Target Platform**: Aplicación web. A diferencia de Mercado Libre, **no requiere** una dirección
pública de retorno — sin redirect, la conexión puede probarse incluso en desarrollo local contra la
API real de Tiendanube (quickstart.md Parte 2).

**Project Type**: Web application monolítica Laravel (backend + Blade), single-tenant.

**Performance Goals**: Pantalla de configuración de carga inmediata (un único registro). El historial
pagina del lado del servidor. Timeouts hacia Tiendanube: 10 s de conexión, 30 s de respuesta — mismo
criterio que Mercado Libre, para no agotar el `max_execution_time` del hosting compartido.

**Constraints**:
- **Portabilidad de entorno**: sin procesos permanentes ni locks — no hay nada que sincronizar entre
  procesos en esta spec (research.md §R11).
- Single-tenant: sin `empresa_id`. `tn_configuracion` es un registro único.
- Dominio en español (Principio V), con la excepción de los nombres propios del proveedor
  (`access_token`, `store_id`).
- Ninguna credencial se registra en logs ni se devuelve a la interfaz.
- Cabecera de autenticación no estándar (`Authentication`, no `Authorization`) y `User-Agent`
  obligatorio — trampas verificadas en research.md §R3, deben quedar aisladas en una única constante
  del cliente HTTP.

**Scale/Scope**: 2 tablas + 2 modelos, 1 servicio de dominio (`ClienteTiendanube`), 1 controlador
(`TiendanubeConfiguracionController`), 1 vista + partials, 1 archivo JS.

## Constitution Check

*GATE: debe pasar antes de Phase 0. Re-evaluado después de Phase 1.*

| Principio | Evaluación | Estado |
|---|---|---|
| **I. Documentación de dominio como fuente de verdad** | Se leyó `docs/documentacion_principal_crm.md` §5.2 (Mercado Libre, como precedente directo) y `docs/informe_contagram_funciones_avanzadas.md` §4 (Tiendanube) antes de especificar. La spec declara el impacto en ambos documentos, y el plan lo convierte en tarea **bloqueante previa a la implementación** (ver Fase A de tasks). | ✅ PASA |
| **II. Desarrollo spec-driven** | Integración de negocio → no exenta. Se recorre el flujo completo `specify → clarify → plan → checklist → tasks → analyze → implement`. Sin código previo de Tiendanube. | ✅ PASA |
| **III. Corrección fiscal innegociable** | No aplica: esta spec no toca comprobantes, CAE ni ARCA. Se registra explícitamente para que no se interprete como omisión. | ✅ N/A |
| **IV. Testing donde hay dinero o impacto fiscal** | No hay cálculo de importes ni movimiento de stock en esta etapa. El riesgo real es de confiabilidad/seguridad (bloqueo de escrituras, manejo de credencial inválida), tratado con el mismo criterio de rigor que Mercado Libre. | ✅ PASA |
| **V. Convenciones Laravel + dominio en español** | Tablas, columnas, rutas y textos en español; estructura MVC estándar; sin pelear contra el framework. Single-tenant respetado. | ✅ PASA |
| **Restricción de secretos** | Token cifrado en reposo, `$hidden` en el modelo, excluido del historial y de los logs. Nada se versiona. | ✅ PASA |
| **Principio rector `CLAUDE.md` (fidelidad estructural)** | La pantalla contenedora Funciones Avanzadas **no** se modifica (ya construida en spec 011). La divergencia respecto de Contagram está **acotada al contenido de la tarjeta de Tiendanube**, es deliberada (decisión explícita del usuario, sesión 2026-07-29), y se documenta como excepción explícita — mismo tratamiento que Mercado Libre. | ✅ PASA con excepción documentada |
| **Especificaciones de diseño obligatorias** | DataTables server-side para el historial; modales/formulario Bootstrap + AJAX sin recarga; toasts de NexaDash. Sin PDFs ni Select2 en esta spec (no hay catálogos grandes que buscar todavía). | ✅ PASA |

**Resultado del gate**: pasa sin violaciones que requieran justificación en Complexity Tracking.

**Nota sobre la excepción al principio rector**: igual que en la spec 011, no es una violación de la
constitución sino del principio rector operativo del `CLAUDE.md`, que exige que las divergencias
respecto de Contagram estén **documentadas** en lugar de improvisadas. La tarea de actualizar
`documentacion_principal_crm.md` es lo que la convierte en conforme, y por eso es bloqueante.

## Project Structure

### Documentation (this feature)

```text
specs/015-tiendanube-conexion/
├── plan.md              # Este archivo
├── spec.md              # Especificación funcional
├── research.md          # Phase 0 — decisiones técnicas (R1..R11)
├── data-model.md        # Phase 1 — esquema de las 2 tablas nuevas
├── quickstart.md        # Phase 1 — guía de validación de punta a punta
├── contracts/
│   ├── rutas-internas.md        # Endpoints del CRM (HTTP + JSON)
│   └── api-tiendanube.md        # Contrato con el proveedor externo
├── checklists/
│   └── requirements.md  # Checklist de calidad de la spec
└── tasks.md             # Phase 2 — generado por /speckit-tasks
```

### Source Code (repository root)

```text
app/
├── Http/
│   └── Controllers/
│       └── Integraciones/
│           └── TiendanubeConfiguracionController.php   # credenciales, estado, probar, desconectar, historial
├── Models/
│   └── Integraciones/
│       ├── TiendanubeConfiguracion.php
│       └── TiendanubeOperacionLog.php
├── Services/
│   └── Tiendanube/
│       ├── ClienteTiendanube.php          # punto ÚNICO de salida: kill-switch + log (sin renovación)
│       ├── RespuestaTiendanube.php        # objeto de resultado (ok / bloqueada / error), mismo patrón que RespuestaMercadoLibre
│       └── Excepciones/
│           ├── ConexionCaidaException.php
│           └── CredencialesIlegiblesException.php   # DecryptException al leer access_token (edge case spec.md)
└── Enums/
    └── Tiendanube/
        └── EstadoConexion.php             # no_configurada | desconectada | conectada | caida (sin PendienteConfirmacion)

database/
├── migrations/
│   ├── 2026_08_05_060001_create_tn_configuracion_table.php
│   └── 2026_08_05_060002_create_tn_operaciones_log_table.php
└── seeders/
    └── FuncionAvanzadaSeeder.php          # se actualiza: 'tiendanube' pasa a disponible=true, ruta_configuracion

resources/
├── views/configuracion/
│   └── tiendanube/
│       ├── index.blade.php                # configuración + estado + historial
│       ├── _panel_estado.blade.php
│       └── _modal_credenciales.blade.php
└── js/
    └── tiendanube.js

routes/web.php                             # grupo configuracion.tiendanube bajo permiso:configuracion.funciones

# Archivo COMPARTIDO que esta feature modifica (no crea):
app/Http/Controllers/Configuracion/FuncionAvanzadaController.php   # + rama clave==='tiendanube' en estado() (FR-006a, mismo patrón que la rama 'mercadolibre' ya existente)

tests/Feature/Integraciones/
├── TiendanubeConfiguracionTest.php
├── TiendanubeConexionTest.php
├── TiendanubeManejoErroresTest.php        # 401 / 404 / 429 / 5xx
├── TiendanubeModoSoloLecturaTest.php      # SC-003: bloqueo de escrituras
└── TiendanubeFuncionDesactivadaTest.php   # FR-006b
```

**Structure Decision**: se sigue la misma estructura MVC ya vigente para Mercado Libre (spec 011), con
la subcarpeta `Integraciones/` ya prevista en el plan de la 011 ("Mercado Libre es la primera de al
menos tres integraciones previstas") y el prefijo `tn_` en las tablas (mismo criterio que `ml_`, evita
colisión de nombres con el dominio propio del CRM). No se crea `app/Http/Controllers/Integraciones/TiendanubeOAuthController.php`
porque no existe flujo OAuth que controlar (research.md §R1).

## Fases de implementación

Orden derivado de dependencias reales, no de comodidad:

| Fase | Contenido | Dependencia |
|---|---|---|
| **A. Documentación de dominio** | Actualizar `documentacion_principal_crm.md` (sección de integración Tiendanube con la divergencia documentada) y `modelo_datos.md` (2 entidades nuevas) | **Bloqueante** por Principio I: debe estar antes de escribir código |
| **B. Persistencia** | 2 migraciones + 2 modelos + actualizar `FuncionAvanzadaSeeder` (tiendanube → disponible=true) + enum de estado propio | A |
| **C. Configuración de la integración** | Controlador, request de validación, vista, cifrado del token, tests (User Story 1) | B |
| **D. Cliente y verificación de conexión** | `ClienteTiendanube` (kill-switch + log, sin renovación), acción "Probar conexión", panel de estado, tests (User Story 2) | C |
| **E. Sólo lectura, historial y manejo de credencial caída** | Kill-switch, historial con DataTables server-side, retención, detección de credencial inválida/revocada, tests (User Stories 3 y 4) | D |
| **F. Validación de punta a punta** | Ejecución de `quickstart.md` contra una tienda de Tiendanube real (o de prueba) | D, E |

Las fases C y D son secuenciales (D depende de que existan credenciales que probar); E depende de que
el cliente de D ya registre operaciones.

## Complexity Tracking

> Sólo se completa si el Constitution Check tiene violaciones que requieran justificación.

**No aplica**: el Constitution Check pasó sin violaciones. La única desviación —la divergencia respecto
de Contagram (Aplicación personalizada en vez del flujo de 4 pasos con partner app)— está contemplada y
autorizada por el principio rector, que exige documentarla (Fase A), no evitarla.

## Post-Design Constitution Re-Check

*Re-evaluado tras generar `data-model.md`, `contracts/` y `quickstart.md`.*

| Verificación | Resultado |
|---|---|
| ¿El diseño introdujo entidades o reglas de negocio nuevas no documentadas? | Sí: `tn_configuracion` y `tn_operaciones_log`. Cubierto por la Fase A (actualizar `modelo_datos.md`), bloqueante antes de implementar. |
| ¿El diseño requiere dependencias nuevas? | No. Se usa lo ya presente en el proyecto. |
| ¿Se introdujo complejidad no justificada? | No. El diseño es deliberadamente **más simple** que Mercado Libre (una sola tabla de configuración, sin lock), justificado por la ausencia del ciclo OAuth (research.md §R1/§R6/§R11). |
| ¿El diseño sigue siendo portable entre hosting compartido y VPS? | Sí, trivialmente: no hay ningún mecanismo (lock, proceso permanente) que dependa del entorno. |
| ¿Algún secreto queda expuesto en el diseño? | No. Token cifrado en reposo, `$hidden` en el modelo, proyecciones explícitas en respuestas, exclusión previa a persistir en el historial. |
| ¿El diseño respeta el idioma del dominio? | Sí, con la excepción de los identificadores propios del proveedor (`access_token`, `store_id`), conservados por ser nombres del contrato externo. |

**Resultado**: el diseño mantiene la conformidad. Sin cambios necesarios en el plan.
