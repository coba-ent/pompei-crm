# Phase 0 — Research: Conexión Tiendanube vía Application REST del Partner Portal

**Feature**: `022-tiendanube-conexion-rest` | **Fecha**: 2026-07-31

Las decisiones R1-R3 fueron **verificadas empíricamente en esta sesión**, de punta a punta contra la cuenta
real del cliente (Pompei Sanitarios), con la Application "pompei" (App ID 38015) recién creada en el Partner
Portal de Tiendanube. No son suposiciones de documentación pública.

---

## R1. Los tokens de la Application REST y del servidor MCP (spec 019) NO son intercambiables

**Decisión**: tratar ambas conexiones como sistemas de autenticación completamente separados, con
almacenamiento, historial y ciclo de vida independientes — ninguna infraestructura se comparte entre ellas.

**Verificado**: se completó el flujo `authorization_code` clásico (`www.tiendanube.com/apps/38015/authorize`
→ `POST www.tiendanube.com/apps/authorize/token`), obteniendo un `access_token` real. Ese token:
- Devolvió **401 `invalid_token`** al invocar `tools/call` contra `admin-mcp.tiendanube.com` (el servidor que
  usa `ClienteTiendanube` de spec 019).
- Devolvió **200 OK** con datos reales del catálogo al invocar `GET
  https://api.tiendanube.com/v1/{store_id}/products`.

**Rationale**: confirma que son dos audiencias de token distintas — el servidor MCP sólo acepta tokens de su
propio cliente auto-registrado (Dynamic Client Registration, spec 019 R1), no tokens de una Application
clásica del Partner Portal. No hay forma de que esta spec "alimente" al `ClienteTiendanube` existente con
este token; intentarlo sería incorrecto incluso si pareciera funcionar en algún caso aislado.

**Alternativas descartadas**: usar un único almacenamiento (`tn_configuracion`) con una columna que indique
el "tipo" de conexión — descartado porque mezclaría en la misma tabla dos ciclos de vida y dos formatos de
credencial distintos, con el riesgo real de que una futura operación lea el token equivocado para el
transporte equivocado.

---

## R2. Flujo de autorización: OAuth clásico con `redirect_uri` fijo en el Partner Portal

**Decisión**: `GET https://www.tiendanube.com/apps/{app_id}/authorize?state={state}` — **sin** parámetro
`redirect_uri` en la URL (a diferencia del flujo PKCE de spec 019). El destino de la redirección de vuelta
es el que esté configurado en el Partner Portal para esa Application, no algo que el CRM pueda variar por
request.

**Verificado**: doc pública de Tiendanube (`tiendanube.github.io/api-documentation/authentication`) más
confirmación práctica: la Application "pompei" tiene hoy configurado
`https://partners.tiendanube.com/applications/authentication/38015` (una pantalla propia del Partner Portal,
autocompletada al crear la app) — no una URL del CRM.

**Rationale**: implica una dependencia operativa real (documentada en spec.md y en `quickstart.md`): el
usuario debe entrar al Partner Portal y cambiar manualmente el `redirect_uri` a la ruta de callback que esta
spec crea (`route('configuracion.tiendanube.callbackRest')`) antes de poder probar el flujo completo desde
el CRM. Sin ese cambio, el flujo sólo puede probarse manualmente vía la pantalla de prueba del Partner
Portal (como se hizo en esta sesión para la verificación de R1).

**Alternativas descartadas**: ninguna — es una restricción del proveedor, no una decisión de diseño propia.

---

## R3. Canje de código por token: endpoint fijo, no por tienda

**Decisión**: `POST https://www.tiendanube.com/apps/authorize/token`, body JSON
`{"client_id", "client_secret", "grant_type": "authorization_code", "code"}` (no `application/x-www-form-urlencoded`
como el flujo PKCE de spec 019 — este endpoint espera JSON).

**Verificado**: documentación pública de Tiendanube, y confirmado indirectamente en esta sesión (la propia
pantalla de confirmación del Partner Portal hizo este mismo canje puertas adentro al completar la
autorización de prueba).

**Respuesta esperada**: `{"access_token", "token_type": "bearer", "scope", "user_id"}` — **sin
`expires_in`** documentado para este modelo (a diferencia de Mercado Libre y del propio servidor MCP de
spec 019, que sí informa `expires_in`). `user_id` es el `store_id` que después va en la ruta de cada llamada
REST.

**A verificar en `quickstart.md`** (no se pudo confirmar en esta sesión porque el canje real lo hizo la
pantalla del Partner Portal, que no expuso el JSON crudo de la respuesta): si Tiendanube en algún momento
incluye `expires_in`/`refresh_token` para este modelo de Application. Mientras tanto, se asume sin
expiración (spec.md, sección Assumptions) — el único evento que invalida el token es una revocación manual
desde Tiendanube o una desconexión desde el CRM.

---

## R4. Verificación de la conexión: `GET /{store_id}/store`

**Decisión**: usar `GET https://api.tiendanube.com/v1/{store_id}/store` como llamada de verificación
inmediatamente después del canje — igual rol que `list_products` en spec 019 (FR-003a), pero acá sí existe
un endpoint de "info de tienda" real (a diferencia del servidor MCP, que no lo tiene).

**Verificado**: documentado en `specs/015-tiendanube-conexion/contracts/api-tiendanube.md` §3 (spec previa,
nunca desplegada a producción por la restricción de plan que esta spec sortea) — devuelve `name.es`, `url`,
`original_domain`, `country`, `currency`. Confirmado indirectamente en esta sesión: la misma cuenta y la
misma REST API respondieron 200 con datos reales al pedir `/products`, mismo mecanismo de autenticación.

**Rationale**: a diferencia de `list_products` (spec 019 R4, elegido a falta de algo mejor), `GET /store` da
un dato mucho más representativo para el panel de estado (nombre y dominio real de la tienda, SC-005 de
spec.md) sin necesitar cargar ningún catálogo.

**Cabeceras obligatorias** (spec 015 R3, re-verificar en `quickstart.md` contra la Application nueva):
```
Authentication: bearer {access_token}
User-Agent: Contagram CRM (contacto del negocio)
```
`Authentication`, no `Authorization` — trampa ya documentada en spec 015, se repite acá para que la
implementación no la redescubra.

**Alternativas descartadas**: `GET /products?per_page=1` (mismo patrón que MCP) — descartado porque `GET
/store` da un dato más rico (nombre/dominio) sin ninguna desventaja (misma cantidad de llamadas, mismo
costo).

---

## R5. Manejo de errores: mismo vocabulario que spec 019, adaptado a HTTP plano

**Decisión**: sin la distinción "HTTP vs. protocolo MCP" de spec 019 (acá no hay JSON-RPC) — un único nivel
de error, mapeado igual que `specs/015-tiendanube-conexion/contracts/api-tiendanube.md` §4:

| Código | Tratamiento |
|---|---|
| 401 | Conexión → "Caída", sin reintento |
| 404 | Mismo tratamiento que 401 (store_id inválido o token no corresponde) |
| 429 | Espera creciente + reintento acotado, respetando `Retry-After` si viene |
| 5xx / error de conexión | Reintento acotado, sin marcar la conexión como caída |

**Rationale**: reutiliza las mismas constantes ya usadas en `ClienteTiendanube` (`ESPERAS_SEGUNDOS = [1, 2,
4]`, `MAX_INTENTOS_TRANSITORIOS = 3`) como referencia de magnitud, implementadas de forma independiente
dentro de `VerificadorConexionRest` (sin importar ni depender de `ClienteTiendanube`, para no crear ningún
acoplamiento entre las dos conexiones).

---

## R6. Credenciales de la Application: estáticas desde `.env`, sin auto-registro

**Decisión**: `client_id` (App ID 38015) y `client_secret` se cargan una única vez desde `config('integraciones.tiendanube.client_id'/'client_secret')`
(ya existían sin usar desde spec 015) — nunca se auto-registran ni se guardan en base de datos.

**Rationale**: a diferencia de spec 019 (Dynamic Client Registration contra el servidor MCP), el modelo de
Application del Partner Portal exige alta manual previa (ya hecha en esta sesión) — no existe un endpoint
público de auto-registro para este modelo.

---

## R7. Portabilidad de entorno

**Decisión**: sin procesos permanentes, sin locks, sin cron — mismo criterio que spec 019. El único estado
compartido es el `access_token` guardado en la fila única de `tn_conexion_rest`, leído y usado dentro del
mismo request.

---

## R8. Testing sin tocar la cuenta real

**Decisión**: igual restricción no negociable que spec 019 — ningún test automatizado de esta spec ejecuta
una llamada real contra `www.tiendanube.com`/`api.tiendanube.com`. Todo el testing automatizado usa
`Http::fake()` para simular la respuesta de `POST /apps/authorize/token` y de `GET /{store_id}/store`
(incluyendo 401/404/429/5xx). La validación de punta a punta contra la cuenta real (que ya se hizo una vez
manualmente en esta sesión para R1) queda documentada en `quickstart.md` como procedimiento manual.
