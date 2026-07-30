# Contrato — Servidor MCP oficial de Tiendanube (observado, sin doc pública)

**Feature**: `019-tiendanube-conexion-mcp`

Documenta el contrato con `admin-mcp.tiendanube.com`, **observado empíricamente** en la sesión previa a
esta spec (sin documentación pública oficial disponible al momento de escribir esto — es un producto
reciente de Tiendanube, visible como la app "AdminMCP" en Aplicaciones de la tienda). A reverificar contra
documentación oficial si Tiendanube la publica más adelante.

---

## 1. Descubrimiento OAuth

```
GET https://admin-mcp.tiendanube.com/.well-known/oauth-protected-resource
GET https://admin-mcp.tiendanube.com/.well-known/oauth-authorization-server
```

Ambos son públicos, sin autenticación, y devuelven metadata estándar (RFC 9728 / RFC 8414). Ver
research.md R1 para la respuesta completa observada.

## 2. Auto-registro de cliente (Dynamic Client Registration, RFC 7591)

```
POST https://admin-mcp.tiendanube.com/register
Content-Type: application/json

{
  "redirect_uris": ["https://contagramdemo.devstudioweb.com/configuracion/tiendanube/callback"],
  "client_name": "Contagram CRM",
  "grant_types": ["authorization_code", "refresh_token"],
  "response_types": ["code"],
  "token_endpoint_auth_method": "client_secret_post"
}
```

**200**:
```json
{
  "client_id": "...",
  "client_secret": "...",
  "client_id_issued_at": 1785363478,
  "redirect_uris": ["..."],
  "grant_types": ["authorization_code", "refresh_token"],
  "response_types": ["code"],
  "token_endpoint_auth_method": "client_secret_post"
}
```

Sin autenticación previa requerida. Se hace **una sola vez**: el `client_id`/`client_secret` obtenidos se
guardan y reutilizan en conexiones/desconexiones posteriores (no se vuelve a registrar salvo que no haya
ninguno guardado).

## 3. Autorización

```
GET https://admin-mcp.tiendanube.com/authorize
  ?response_type=code
  &client_id={client_id}
  &redirect_uri={redirect_uri}
  &scope=read_products+write_products+read_orders+write_orders+read_customers+write_customers+read_content+write_content+read_coupons+write_coupons+write_scripts+write_shipping
  &state={state_de_un_solo_uso}
  &code_challenge={S256(code_verifier)}
  &code_challenge_method=S256
```

El usuario aprueba en el navegador, logueado con la cuenta de la tienda que quiera conectar (no hay
selección de tienda por identificador — la aprobación determina la tienda). Tiendanube redirige a
`redirect_uri` con `?code=...&state=...` o `?error=...`.

## 4. Intercambio de token

```
POST https://admin-mcp.tiendanube.com/token
Content-Type: application/x-www-form-urlencoded

grant_type=authorization_code
&code={code}
&redirect_uri={redirect_uri}
&client_id={client_id}
&client_secret={client_secret}
&code_verifier={code_verifier}
```

**200**:
```json
{ "access_token": "...", "token_type": "Bearer", "expires_in": 31536000, "scope": "read_products write_products read_orders ..." }
```

⚠️ **Sin `refresh_token` en la práctica** (research.md R3), a pesar de que el authorization server anuncia
soporte para ese grant type. `expires_in` observado: 31.536.000 s (365 días).

## 5. Protocolo MCP (JSON-RPC 2.0 + SSE de un evento)

Toda llamada posterior es un único endpoint:

```
POST https://admin-mcp.tiendanube.com/
Authorization: Bearer {access_token}
Content-Type: application/json
Accept: application/json, text/event-stream

{"jsonrpc":"2.0","id":N,"method":"...","params":{...}}
```

**Métodos usados por esta spec**:

- `initialize` — handshake inicial (opcional para esta spec: no hace falta mantener sesión entre
  requests, cada llamada puede ser independiente, pero se documenta por si una spec futura lo necesita).
- `tools/call` con `params: {"name": "list_products", "arguments": {"page": 1, "page_size": 1, "fields_needed": ["id"]}}`
  — usado para FR-003a.

**Formato de respuesta** (SSE, un único evento):
```
event: message
data: {"jsonrpc":"2.0","id":N,"result":{"content":[...],"structuredContent":{...},"isError":false}}
```

**Parseo**: extraer la línea que empieza con `data: `, `json_decode` esa porción, leer
`result.structuredContent` (JSON ya parseado, preferible a `result.content[0].text` que es el mismo dato
pero como string a decodificar de nuevo).

## 6. Códigos de error relevantes

| Código | Significado | Tratamiento del CRM |
|---|---|---|
| 401 (en cualquier llamada, incluida `/`) | Token inválido, expirado o revocado — el propio servidor lo confirma con `WWW-Authenticate: Bearer error="invalid_token"` | Conexión → `caida` (FR-008), no se reintenta |
| 429 | Límite de solicitudes excedido | Espera creciente + reintento acotado, respetando `Retry-After` si el proveedor lo envía |
| 5xx / error de conexión | Falla del lado de Tiendanube | Reintento acotado sin marcar la conexión como caída |
| 200 con `result.isError: true` | La tool se ejecutó pero falló (ej. argumento inválido) | Se registra como error en el historial con el mensaje del resultado; no afecta el estado de la conexión |

## 7. Rate limiting

**No verificado específicamente para AdminMCP** (research.md deja esto explícito) — se asume, como piso
conservador, el mismo límite documentado para la API REST estándar de Tiendanube: *leaky bucket*, ráfaga
de 40 requests, 2 req/s sostenido por tienda+app (x10 si el plan de la tienda es Next/Evolution). El
cliente debe leer y respetar cualquier header de límite que el servidor MCP exponga en sus respuestas, en
vez de asumir un número fijo hardcodeado.

## 8. Fuera de alcance de esta spec

Cualquier tool de escritura (`update_stock_and_price`, `create_product`, `update_product`, etc.) queda
para las specs 017 (ventas) y 018 (stock) que continúan el trabajo de Tiendanube — esta spec sólo agrega
la capacidad de conexión sobre la que esas specs se apoyarán, mismo patrón que 011 → 012/013 para
Mercado Libre.
