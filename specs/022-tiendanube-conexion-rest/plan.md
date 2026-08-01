# Implementation Plan: Conexión Tiendanube vía Application REST del Partner Portal (aditiva a spec 019)

**Branch**: `022-tiendanube-conexion-rest` | **Date**: 2026-07-31 | **Spec**: [spec.md](./spec.md)

## Summary

Agrega, en un apartado nuevo y aislado dentro de Configuración → Tiendanube, una segunda conexión OAuth
—esta vez contra la Application clásica registrada en el Partner Portal de Tiendanube (App ID 38015,
client_secret en `.env`)— que emite tokens para la **REST API estándar** (`api.tiendanube.com`), verificado
empíricamente como **no intercambiable** con el token del servidor MCP que usa la conexión existente (spec
019, `admin-mcp.tiendanube.com`, 401 al probarlo cruzado). Esta spec no toca `ClienteTiendanube` ni ningún
flujo de negocio (specs 017/018): sólo conecta, verifica con `GET /{store_id}/store`, y muestra un panel de
estado propio. Migrar el resto de la integración a REST queda para una spec futura.

**Enfoque técnico**: nueva tabla single-row (`tn_conexion_rest`) y su propio historial
(`tn_rest_operaciones_log`), completamente independientes de `tn_configuracion`/`tn_operaciones_log` (spec
019, sin tocar). Nuevo controlador `TiendanubeConexionRestController`, calcado en su forma de
`TiendanubeOAuthController` (spec 019) pero con OAuth clásico (`client_id`/`client_secret` fijos desde
`.env`, sin auto-registro) y verificación contra REST (`GET /store`) en vez de JSON-RPC (`list_products`).
Nuevo servicio mínimo `VerificadorConexionRest` sólo para esa llamada de verificación — no es el cliente
REST completo de Tiendanube (eso es alcance de la spec futura que migre 017/018).

## Technical Context

**Language/Version**: PHP 8.2, Laravel 12

**Primary Dependencies**: Laravel `Http` facade (Guzzle, ya en el proyecto) para OAuth y la llamada REST de
verificación. Sin dependencias nuevas.

**Externa**: `www.tiendanube.com` (autorización + canje de código por token, endpoints fijos documentados en
`contracts/api-tiendanube-rest.md`) y `api.tiendanube.com` (REST API estándar, sólo se usa `GET
/{store_id}/store` en esta spec). Cabecera no estándar `Authentication: bearer <token>` (no `Authorization`)
y `User-Agent` obligatorio — ambos verificados empíricamente en esta sesión y ya documentados en
`specs/015-tiendanube-conexion/contracts/api-tiendanube.md`.

**Storage**: MySQL (`contagram`). Se **crean dos tablas nuevas**, ninguna modifica `tn_configuracion` ni
`tn_operaciones_log` (spec 019, intactas):
- `tn_conexion_rest`: fila única (mismo patrón que `tn_configuracion`) con `access_token` cifrado,
  `store_id`, `scopes_otorgados`, `tienda_nombre`, `tienda_dominio`, `conectada_en`, `estado`,
  `ultimo_error`, `actualizada_por`.
- `tn_rest_operaciones_log`: mismo esquema que `tn_operaciones_log`, historial propio de esta conexión.

**Testing**: PHPUnit sobre SQLite en memoria, con `Http::fake()` para toda interacción simulada contra
`www.tiendanube.com`/`api.tiendanube.com`. Mismo criterio no negociable que spec 019: **ningún test
automatizado ejecuta una llamada real contra la cuenta de Tiendanube del cliente**; la validación de punta a
punta contra la cuenta real queda documentada en `quickstart.md` como procedimiento manual.

**Target Platform**: Aplicación web, hosting compartido (Hostinger), mismo entorno que el resto de la
integración Tiendanube. Sin `exec()`, sin cron, sin locks — un flujo de conexión puntual, no hay renovación
periódica que proteger de concurrencia.

**Project Type**: Web application monolítica Laravel (backend + Blade), single-tenant.

**Performance Goals**: Timeouts hacia Tiendanube: 10 s de conexión, 30 s de respuesta — mismo criterio que
spec 019/011.

**Constraints**:
- Aislamiento total de spec 019: ninguna tabla, ruta, vista, controlador ni test de la conexión MCP se
  modifica. Verificable: la suite `TiendanubeOAuthTest`/`TiendanubeConexionTest` de spec 019 sigue en verde
  sin tocarla.
- Esta conexión NO se usa desde ningún comando de sincronización, controlador de ventas/stock ni
  vinculación — sólo desde su propio controlador de conexión.
- Single-tenant: sin `empresa_id`. `tn_conexion_rest` es un registro único, mismo criterio que
  `tn_configuracion`.
- Dominio en español (Principio V), salvo términos propios del protocolo OAuth (`client_id`,
  `client_secret`, `access_token`, `scope`, `state`).
- Ninguna credencial (`client_secret`, `access_token`) se registra en logs ni se devuelve a la interfaz.
- `redirect_uri` fijo (no viaje por query string): esta spec crea la ruta de callback real del CRM: el
  usuario debe actualizar el Partner Portal a mano para que apunte ahí (dependencia operativa, documentada
  en spec.md y en `quickstart.md`; no automatizable desde el código).

**Scale/Scope**: 1 migración (crea 2 tablas), 1 controlador nuevo (`TiendanubeConexionRestController`), 1
servicio nuevo mínimo (`VerificadorConexionRest`), 2 modelos nuevos, 1 partial de vista + JS agregados al
`index.blade.php` existente de Tiendanube (sin tocar el partial de la conexión MCP), rutas nuevas bajo el
mismo prefijo `configuracion/tiendanube`.

## Constitution Check

*GATE: debe pasar antes de Phase 0. Re-evaluado después de Phase 1.*

| Principio | Evaluación | Estado |
|---|---|---|
| **I. Documentación de dominio como fuente de verdad** | Se leyó `docs/documentacion_principal_crm.md` §5.3 y `docs/modelo_datos.md` §11 (versión vigente, describe sólo la conexión MCP) antes de especificar. La spec declara el impacto en ambos documentos como tarea bloqueante previa a `/speckit-tasks`. | ✅ PASA |
| **II. Desarrollo spec-driven** | Integración de negocio (aunque acotada a conexión) → no exenta. Se recorre `specify → clarify → plan → checklist → tasks → analyze → implement`. | ✅ PASA |
| **III. Corrección fiscal innegociable** | No aplica: no toca comprobantes, CAE ni ARCA. | ✅ N/A |
| **IV. Testing donde hay dinero o impacto fiscal** | No hay cálculo de dinero, pero sí cuenta real de producción sin sandbox (mismo riesgo que spec 019): todo test de escritura/lectura contra Tiendanube usa `Http::fake()`, nunca la cuenta real. | ✅ PASA |
| **V. Convenciones Laravel + dominio en español** | Tablas, columnas, rutas y textos en español salvo términos del protocolo OAuth. Single-tenant respetado. | ✅ PASA |
| **Restricción de secretos** | `client_secret` (config, no en tabla) y `access_token` cifrado en reposo, `$hidden` en el modelo, excluidos del historial y de los logs. | ✅ PASA |
| **Principio rector `CLAUDE.md` (fidelidad estructural)** | No aplica de forma directa: Contagram no tiene una pantalla equivalente a "conexión de integración externa" que replicar — es infraestructura propia de este CRM (igual excepción ya aceptada en specs 011/015/019). Se agrega como apartado adicional dentro de la misma tarjeta "Tiendanube" ya aceptada. | ✅ PASA (excepción ya documentada, no nueva) |
| **Especificaciones de diseño obligatorias** | Sin formularios de credenciales (botón "Conectar" + redirect externo, igual que spec 019); toasts de NexaDash para errores; panel de estado vía AJAX (`estado()` JSON), sin recarga salvo el propio ida-y-vuelta OAuth (excepción ya aceptada en spec 019/011, inherente a cualquier flujo OAuth con redirect de navegador). | ✅ PASA |

**Resultado del gate**: pasa sin violaciones que requieran justificación en Complexity Tracking.

## Project Structure

### Documentation (this feature)

```text
specs/022-tiendanube-conexion-rest/
├── plan.md              # Este archivo
├── spec.md              # Especificación funcional (con Clarifications)
├── research.md          # Phase 0 — decisiones técnicas
├── data-model.md        # Phase 1 — tn_conexion_rest / tn_rest_operaciones_log
├── quickstart.md        # Phase 1 — guía de validación manual contra la cuenta real
├── contracts/
│   ├── rutas-internas.md            # Endpoints del CRM (HTTP + JSON)
│   └── api-tiendanube-rest.md       # Contrato de la REST API clásica usada por esta spec
├── checklists/
│   └── requirements.md  # Checklist de calidad de la spec (ya validada)
└── tasks.md              # Phase 2 — generado por /speckit-tasks
```

### Source Code (repository root)

```text
app/
├── Http/
│   └── Controllers/
│       └── Integraciones/
│           └── TiendanubeConexionRestController.php   # NUEVO: conectar() (redirige a /apps/{app_id}/authorize), callback() (valida state, canjea código, verifica con GET /store, guarda), estado(), desconectar()
├── Models/
│   └── Integraciones/
│       ├── TiendanubeConexionRest.php       # NUEVO: fila única, access_token cifrado, $hidden
│       └── TiendanubeRestOperacionLog.php   # NUEVO: historial propio, mismo patrón que TiendanubeOperacionLog
├── Services/
│   └── Tiendanube/
│       └── VerificadorConexionRest.php      # NUEVO: única responsabilidad — GET /{store_id}/store con headers correctos, devuelve nombre/dominio o error. NO es un cliente REST completo (eso es alcance de una spec futura).

database/
└── migrations/
    └── 2026_08_07_060001_create_tn_conexion_rest_y_log.php   # crea las 2 tablas nuevas, no toca tn_configuracion ni tn_operaciones_log

resources/
├── views/configuracion/tiendanube/
│   ├── index.blade.php                    # SE MODIFICA: se agrega el include del partial nuevo, debajo/al lado del panel MCP existente (sin tocar ese partial)
│   └── _panel_estado_rest.blade.php       # NUEVO: card propia, botón "Conectar", estado, datos de tienda, botón "Desconectar"
└── js/
    └── tiendanube.js                      # SE MODIFICA: se agrega el manejo de estado/desconexión de este apartado nuevo (mismo patrón AJAX + toasts que ya usa el panel MCP), sin tocar el código existente de ese panel

routes/web.php   # SE MODIFICA: agrega configuracion.tiendanube.conectarRest/callbackRest/estadoRest/desconectarRest, dentro del mismo prefix('tiendanube') existente

config/integraciones.php   # SIN CAMBIOS DE CÓDIGO: las claves TN_CLIENT_ID/TN_CLIENT_SECRET ya existían (sin uso, desde spec 015) — sólo pasan a tener valor real en `.env` (T007), y a usarse de verdad desde TiendanubeConexionRestController

tests/Feature/Integraciones/
├── TiendanubeConexionRestTest.php          # NUEVO: conectar → authorize URL correcta; callback con state válido/inválido/código reusado/error access_denied; verificación GET /store exitosa y fallida; NO deja "Conectada" si la verificación falla
├── TiendanubeConexionRestErroresTest.php   # NUEVO: 401 → Caída sin reintento; 429/5xx → reintento con backoff acotado
└── TiendanubeConexionRestAislamientoTest.php  # NUEVO: conectar/desconectar esta conexión NO modifica tn_configuracion ni el estado de la conexión MCP, y viceversa — prueba explícita del requisito de aislamiento (FR-008/FR-013)
```

**Structure Decision**: mismo patrón de carpetas que specs 011/015/019
(`Services/Tiendanube/`, `Models/Integraciones/`, `Controllers/Integraciones/`). Todo lo de esta spec es
código **nuevo** que convive junto al de spec 019 sin modificarlo — ningún archivo existente de la conexión
MCP se toca, salvo `index.blade.php` (se le agrega un `@include` nuevo) y `tiendanube.js` (se le agrega
código nuevo al final, sin tocar el existente) y `routes/web.php` (se agregan rutas nuevas dentro del mismo
grupo). `TiendanubeConexionRestTest*` son archivos nuevos — la suite existente de spec 019
(`TiendanubeOAuthTest.php`, etc.) no se modifica ni un carácter.

## Complexity Tracking

> Sólo se completa si el Constitution Check tiene violaciones que requieran justificación.

**No aplica**: el Constitution Check pasó sin violaciones. Crear dos tablas nuevas en vez de reutilizar
`tn_configuracion`/`tn_operaciones_log` podría verse como duplicación, pero está justificado explícitamente
en spec.md (Key Entities): son credenciales de dos sistemas de autenticación distintos y **no
intercambiables** (verificado empíricamente, 401 cruzado) — mezclarlas en la misma tabla/historial
generaría ambigüedad real sobre a qué sistema pertenece cada dato, exactamente el riesgo que FR-008/FR-011
de spec.md piden evitar.

## Post-Design Constitution Re-Check

*Re-evaluado tras generar `data-model.md`, `contracts/` y `quickstart.md`.*

| Verificación | Resultado |
|---|---|
| ¿El diseño introdujo entidades o reglas de negocio nuevas no documentadas? | Sí: `tn_conexion_rest` y `tn_rest_operaciones_log`. Cubierto por la Fase A (actualizar `documentacion_principal_crm.md` §5.3 y `modelo_datos.md` §11), bloqueante antes de `/speckit-tasks`. |
| ¿El diseño requiere dependencias nuevas? | No. Se usa `Http` de Laravel, ya presente. |
| ¿Se introdujo complejidad no justificada? | No. Dos tablas nuevas están justificadas por la no intercambiabilidad de tokens (ver Complexity Tracking). |
| ¿El diseño sigue siendo portable entre hosting compartido y VPS? | Sí — sin locks, sin cron, sin procesos persistentes, igual que spec 019. |
| ¿Algún secreto queda expuesto en el diseño? | No. `access_token` cifrado, `$hidden`, excluido del historial; `client_secret` vive sólo en `.env`/`config()`, nunca en base de datos. |
| ¿El diseño respeta la restricción de no tocar la cuenta real en tests? | Sí — mismo criterio que spec 019: `Http::fake()` en toda la suite, validación real documentada como manual en `quickstart.md`. |
| ¿El diseño deja intacta la conexión MCP de spec 019? | Sí — ninguna tabla, archivo de controlador/modelo/servicio, ruta ni test de spec 019 se modifica. `TiendanubeConexionRestAislamientoTest.php` lo verifica explícitamente. |

**Resultado**: el diseño mantiene la conformidad. Sin cambios necesarios en el plan.
