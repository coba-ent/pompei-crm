# Implementation Plan: Conexión Tiendanube vía OAuth/MCP (corrección de spec 015)

**Branch**: `019-tiendanube-conexion-mcp` | **Date**: 2026-07-29 | **Spec**: [spec.md](./spec.md)

## Summary

Reemplaza el mecanismo de conexión de la integración Tiendanube (spec 015, modelo de "Aplicación
personalizada" con token manual) por OAuth 2.1 + Dynamic Client Registration contra el servidor MCP
oficial de Tiendanube (`admin-mcp.tiendanube.com`), verificado de punta a punta en la sesión previa a
esta spec con un cliente standalone sin ningún LLM de por medio. El motivo del reemplazo: el modelo de
Aplicación personalizada requiere plan Tiendanube Escala/Evolución, que la tienda real del cliente no
tiene; el servidor MCP no tiene esa restricción.

**Enfoque técnico**: se conserva casi toda la infraestructura de spec 015 (`tn_operaciones_log` y su
retención, el kill-switch de modo sólo lectura verificado en un único punto dentro de
`ClienteTiendanube`, la pantalla de configuración, el wiring con Funciones Avanzadas). Lo que cambia es
el transporte (`ClienteTiendanube` pasa de REST con header `Authentication: bearer` a JSON-RPC 2.0 sobre
HTTP con respuesta en formato SSE de un único evento) y la forma de obtener el token (de un campo pegado
a mano, a un flujo `authorization_code` + PKCE con auto-registro de cliente, calcado de
`MercadoLibreOAuthController` de spec 011 pero sin renovación: el token dura ~1 año y no hay
`refresh_token` en la práctica).

## Technical Context

**Language/Version**: PHP 8.2, Laravel 12

**Primary Dependencies**: Laravel `Http` facade (Guzzle, ya en el proyecto) para las llamadas OAuth y
JSON-RPC. **Sin dependencias nuevas** — no existe un cliente MCP para PHP maduro y el protocolo
(JSON-RPC 2.0 + parseo de un evento SSE) es lo bastante simple como para no justificar una librería
externa; se implementa como un método privado más de `ClienteTiendanube`, mismo criterio que spec 015.

**Externa**: `admin-mcp.tiendanube.com` — servidor MCP oficial de Tiendanube (first-party, sin
restricción de plan, verificado empíricamente en la sesión previa). Sin documentación pública detallada
al momento de escribir esta spec; el contrato se documenta en `research.md` a partir de lo observado.

**Storage**: MySQL (`contagram`). Se **modifica** `tn_configuracion` (no se crea tabla nueva): se
agregan `client_id`, `client_secret` (cifrado), `scopes_otorgados`, `productos_total` (de la
verificación FR-003a), `conectada_en`; se quitan `store_id`, `nombre_tienda`, `dominio`, `pais`,
`moneda`, `ultima_verificacion_en` (ya no aplican sin el `GET /store` de la spec 015).
`tn_operaciones_log` **no cambia**.

**Testing**: PHPUnit sobre SQLite en memoria, con `Http::fake()` para **toda** interacción simulada
contra `admin-mcp.tiendanube.com` (registro, authorize/token, llamadas JSON-RPC). Restricción no
negociable de esta spec (ver spec.md "Restricción crítica"): **ningún test ejecuta una escritura real
contra la cuenta de Tiendanube del cliente** — ni siquiera las de lectura se prueban contra la cuenta
real dentro de la suite automatizada; la validación de punta a punta contra la cuenta real queda para
`quickstart.md`, ejecutada manualmente por el usuario.

**Target Platform**: Aplicación web, hosting compartido (Hostinger, mismo entorno que Mercado Libre en
producción). Confirmado compatible sin trabajo adicional: sin `exec()`, sin cron, sin `Cache::lock()`
(no hace falta — no hay renovación de token que proteger de concurrencia), sin conexiones persistentes
(la respuesta SSE del servidor MCP es un único evento por request, no un stream abierto).

**Project Type**: Web application monolítica Laravel (backend + Blade), single-tenant.

**Performance Goals**: Timeouts hacia `admin-mcp.tiendanube.com`: 10 s de conexión, 30 s de respuesta —
mismo criterio que spec 015/011, para no agotar el `max_execution_time` del hosting compartido.

**Constraints**:
- Portabilidad de entorno: sin procesos permanentes ni locks — igual que spec 015, ahora también sin
  lock de renovación (spec 015 tampoco lo necesitaba, pero esta spec lo confirma explícitamente porque
  ni siquiera hay ciclo de refresh que renueve).
- Single-tenant: sin `empresa_id`. `tn_configuracion` sigue siendo un registro único.
- Dominio en español (Principio V), con la excepción de los términos propios del protocolo (`client_id`,
  `client_secret`, `access_token`, `scope`, `state`, `code_verifier`).
- Ninguna credencial (`client_secret`, `access_token`) se registra en logs ni se devuelve a la interfaz.
- Redirect URI pública HTTPS: reutiliza `APP_URL` (ya configurado y funcionando para Mercado Libre en
  `contagramdemo.devstudioweb.com`).

**Scale/Scope**: 1 migración (altera `tn_configuracion`), 1 controlador nuevo
(`TiendanubeOAuthController`), 1 servicio nuevo (`RegistradorClienteOAuth` para el auto-registro), 1
servicio reescrito por dentro (`ClienteTiendanube`), ajustes en vistas/JS/rutas existentes de spec 015.

## Constitution Check

*GATE: debe pasar antes de Phase 0. Re-evaluado después de Phase 1.*

| Principio | Evaluación | Estado |
|---|---|---|
| **I. Documentación de dominio como fuente de verdad** | Se leyó `docs/documentacion_principal_crm.md` §5.3 y `docs/modelo_datos.md` §11 (la versión que describe la spec 015) antes de especificar. La spec declara el impacto en ambos documentos como tarea bloqueante previa a `/speckit-tasks` (Fase A). | ✅ PASA |
| **II. Desarrollo spec-driven** | Corrección de una integración de negocio → no exenta. Se recorre `specify → clarify → plan → checklist → tasks → analyze → implement`. | ✅ PASA |
| **III. Corrección fiscal innegociable** | No aplica: no toca comprobantes, CAE ni ARCA. | ✅ N/A |
| **IV. Testing donde hay dinero o impacto fiscal** | No hay cálculo de dinero, pero sí un riesgo real superior al de spec 015: la cuenta usada es la **cuenta real de producción del cliente**, sin sandbox. Se trata con el máximo rigor: todo test de escritura usa `Http::fake()`, nunca la cuenta real (spec.md SC-005, restricción no negociable). | ✅ PASA |
| **V. Convenciones Laravel + dominio en español** | Tablas, columnas, rutas y textos en español, salvo términos propios del protocolo OAuth/MCP. Single-tenant respetado. | ✅ PASA |
| **Restricción de secretos** | `client_secret` y `access_token` cifrados en reposo, `$hidden` en el modelo, excluidos del historial y de los logs. | ✅ PASA |
| **Principio rector `CLAUDE.md` (fidelidad estructural)** | Misma excepción ya documentada y aceptada en spec 015 — esta spec no reabre esa discusión, sólo corrige el mecanismo de conexión dentro de la misma tarjeta "Tiendanube" ya aceptada como divergencia deliberada. | ✅ PASA (excepción ya documentada, no nueva) |
| **Especificaciones de diseño obligatorias** | Modales/formulario Bootstrap + AJAX sin recarga (se simplifican: ya no hay formulario de credenciales, sólo un botón); toasts de NexaDash; DataTables server-side para el historial (sin cambios). | ✅ PASA |

**Resultado del gate**: pasa sin violaciones que requieran justificación en Complexity Tracking.

## Project Structure

### Documentation (this feature)

```text
specs/019-tiendanube-conexion-mcp/
├── plan.md              # Este archivo
├── spec.md              # Especificación funcional (con Clarifications)
├── research.md          # Phase 0 — decisiones técnicas (R1..R11)
├── data-model.md        # Phase 1 — esquema ajustado de tn_configuracion
├── quickstart.md        # Phase 1 — guía de validación manual contra la cuenta real
├── contracts/
│   ├── rutas-internas.md        # Endpoints del CRM (HTTP + JSON)
│   └── admin-mcp-tiendanube.md  # Contrato observado del servidor MCP (OAuth + JSON-RPC)
├── checklists/
│   └── requirements.md  # Checklist de calidad de la spec (ya validada)
└── tasks.md             # Phase 2 — generado por /speckit-tasks
```

### Source Code (repository root)

```text
app/
├── Http/
│   └── Controllers/
│       └── Integraciones/
│           ├── TiendanubeConfiguracionController.php   # SE MODIFICA: estado()/desconectar() se adaptan a los nuevos campos; se elimina credenciales() (ya no hay formulario manual) y se ajusta probar() → ya no existe como acción separada (la verificación queda dentro del callback, FR-003a)
│           └── TiendanubeOAuthController.php            # NUEVO: conectar() (auto-registra si hace falta + redirige a /authorize) y callback() (valida state, intercambia código, verifica con list_products, guarda)
├── Models/
│   └── Integraciones/
│       └── TiendanubeConfiguracion.php   # SE MODIFICA: fillable/casts nuevos (client_id, client_secret cifrado, scopes_otorgados, productos_total, conectada_en); se quitan store_id y los campos de "datos de tienda" de spec 015
├── Services/
│   └── Tiendanube/
│       ├── ClienteTiendanube.php          # SE REESCRIBE POR DENTRO: peticion() arma JSON-RPC (tools/call) y parsea SSE de un evento, en vez de REST con header Authentication; kill-switch y registro en historial se mantienen igual (mismo punto único)
│       ├── RegistradorClienteOAuth.php    # NUEVO: POST /register (Dynamic Client Registration), guarda client_id/client_secret la primera vez
│       ├── RespuestaTiendanube.php        # SIN CAMBIOS
│       └── Excepciones/
│           ├── ConexionCaidaException.php         # SIN CAMBIOS
│           └── CredencialesIlegiblesException.php # SIN CAMBIOS

database/
└── migrations/
    └── 2026_08_06_060001_update_tn_configuracion_para_oauth_mcp.php   # altera columnas (agrega/quita), no crea tabla

resources/
├── views/configuracion/tiendanube/
│   ├── index.blade.php                # SE MODIFICA: sin card de "credenciales" con formulario
│   ├── _panel_estado.blade.php        # SE MODIFICA: botón "Conectar con Tiendanube" en vez de link a modal de credenciales; muestra scopes + cantidad de productos
│   └── _modal_desconectar.blade.php   # SIN CAMBIOS
│   # _modal_credenciales.blade.php SE ELIMINA (ya no hay campos que cargar a mano)
└── js/
    └── tiendanube.js                  # SE MODIFICA: sin submit de formulario de credenciales; agrega manejo de redirect a conectar()

routes/web.php   # SE MODIFICA: agrega configuracion.tiendanube.conectar/callback; quita configuracion.tiendanube.credenciales y .probar

tests/Feature/Integraciones/
├── TiendanubeOAuthTest.php             # NUEVO: auto-registro (una sola vez, reutilizado en llamadas siguientes), authorize con PKCE+state, callback con state inválido/código reusado, verificación FR-003a exitosa y fallida
├── TiendanubeConexionTest.php          # SE ADAPTA: ya no prueba store_id/token manual; prueba conexión vía callback simulado y desconexión
├── TiendanubeManejoErroresTest.php     # SE ADAPTA: 401 del servidor MCP → Caída sin intento de renovación (no hay refresh_token); 429/5xx → reintento con backoff
├── TiendanubeModoSoloLecturaTest.php   # SIN CAMBIOS DE INTENCIÓN: se re-verifica contra el nuevo ClienteTiendanube (JSON-RPC) en vez del REST anterior
└── TiendanubeFuncionDesactivadaTest.php # SIN CAMBIOS DE INTENCIÓN: mismo guard, se re-verifica contra el nuevo cliente
```

**Structure Decision**: se mantiene la misma estructura de carpetas de spec 015 (`Services/Tiendanube/`,
`Models/Integraciones/`, `Controllers/Integraciones/`) — esta es una corrección del mecanismo de
conexión, no una reestructuración. `TiendanubeOAuthController` es un archivo nuevo, calcado en su forma
de `MercadoLibreOAuthController` (spec 011), pero sin las acciones de renovación que ese controlador no
tiene expuestas como endpoint (la renovación de ML es interna a `ClienteMercadoLibre`) — acá directamente
no existen porque no hay `refresh_token`.

## Complexity Tracking

> Sólo se completa si el Constitution Check tiene violaciones que requieran justificación.

**No aplica**: el Constitution Check pasó sin violaciones. La corrección reduce complejidad respecto de
spec 015 en el aspecto de renovación (no hay), pero agrega el auto-registro OAuth y el parseo de
JSON-RPC/SSE — ambos justificados por ser la única vía viable dado el plan de la tienda real (ver spec.md
"Contexto y fuentes").

## Post-Design Constitution Re-Check

*Re-evaluado tras generar `data-model.md`, `contracts/` y `quickstart.md`.*

| Verificación | Resultado |
|---|---|
| ¿El diseño introdujo entidades o reglas de negocio nuevas no documentadas? | Sí: los campos nuevos de `tn_configuracion`. Cubierto por la Fase A (actualizar `documentacion_principal_crm.md` §5.3 y `modelo_datos.md` §11), bloqueante antes de implementar. |
| ¿El diseño requiere dependencias nuevas? | No. Se usa `Http` de Laravel, ya presente. |
| ¿Se introdujo complejidad no justificada? | No. El auto-registro OAuth y el parseo JSON-RPC/SSE son la única vía viable verificada; no hay alternativa más simple dado el plan de la tienda. |
| ¿El diseño sigue siendo portable entre hosting compartido y VPS? | Sí — más portable que spec 015 incluso, al no requerir ningún mecanismo de renovación ni lock. |
| ¿Algún secreto queda expuesto en el diseño? | No. `client_secret` y `access_token` cifrados, `$hidden`, excluidos del historial. |
| ¿El diseño respeta la restricción de no tocar la cuenta real en tests? | Sí — todo el diseño de testing en `research.md`/`quickstart.md` separa explícitamente "tests automatizados con `Http::fake()`" de "validación manual contra la cuenta real". |

**Resultado**: el diseño mantiene la conformidad. Sin cambios necesarios en el plan.
