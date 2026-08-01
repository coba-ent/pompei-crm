# Contrato — API externa de Tiendanube (Application REST del Partner Portal)

**Feature**: `022-tiendanube-conexion-rest`

Documenta el contrato con Tiendanube para esta spec — análogo a
`019-tiendanube-conexion-mcp/contracts/admin-mcp-tiendanube.md`, pero para la REST API clásica en vez del
servidor MCP. Ver `research.md` R1-R4 para el detalle de cómo se verificó cada punto.

---

## 1. Autorización

```
GET https://www.tiendanube.com/apps/{app_id}/authorize?state={state}
```

`{app_id}` = `client_id` de la Application (config `integraciones.tiendanube.client_id`, hoy `38015`).
`{state}` = token de un solo uso generado por el CRM, vencimiento 10 minutos (mismo criterio que spec 019).
**No** se pasa `redirect_uri` en la URL — es fijo, configurado en el Partner Portal (research.md R2).

Tiendanube redirige de vuelta a la ruta de callback configurada en el Partner Portal con
`?code={code}&state={state}`. El `code` vence a los 5 minutos.

## 2. Canje del código por token

```
POST https://www.tiendanube.com/apps/authorize/token
Content-Type: application/json

{
  "client_id": "{client_id}",
  "client_secret": "{client_secret}",
  "grant_type": "authorization_code",
  "code": "{code}"
}
```

**200** (forma esperada):
```json
{
  "access_token": "...",
  "token_type": "bearer",
  "scope": "read_products,write_products,...",
  "user_id": 6922207
}
```

`user_id` → `tn_conexion_rest.store_id`. Sin `expires_in` documentado (research.md R3) — a verificar en
`quickstart.md` contra la respuesta real.

**Error** (código ya usado/vencido/inexistente):
```json
{"error": "invalid_grant", "error_description": "Authorization code doesn't exist or is invalid for the client"}
```
Verificado empíricamente en esta sesión (código vencido, 18 minutos de antigüedad) — no dejar la conexión
como establecida ante esta respuesta (FR-004).

## 3. Autenticación de cada llamada a la REST API

```
GET https://api.tiendanube.com/v1/{store_id}/{recurso}
```

Cabeceras obligatorias:
```
Authentication: bearer {access_token}
User-Agent: Contagram CRM (contacto del negocio)
```

⚠️ **`Authentication`, no `Authorization`** (research.md R4, spec 015 §2) — verificado empíricamente en esta
sesión (`GET /products` con esta cabecera devolvió 200 con datos reales).

## 4. Verificación de conexión (FR-005)

```
GET /{store_id}/store
```

**200** (campos relevantes para esta spec):
```json
{
  "id": 6922207,
  "name": { "es": "Pompei Sanitarios" },
  "url": "https://pompeisanitarios.com",
  "original_domain": "pompeisanitarios.com",
  "country": "AR",
  "currency": "ARS"
}
```

**Mapeo a `tn_conexion_rest`**: `name.es` (o idioma principal) → `tienda_nombre`; `original_domain` →
`tienda_dominio`.

## 5. Códigos de error relevantes

| Código | Significado | Tratamiento del CRM |
|---|---|---|
| 401 | Token inválido, expirado o revocado | Conexión → `caida` (FR-009), sin reintento |
| 404 | `store_id` no corresponde al token | Mismo tratamiento que 401 |
| 429 | Límite de solicitudes excedido | Espera creciente + reintento acotado (FR-010), respetando `Retry-After` si viene |
| 5xx / timeout | Falla del lado de Tiendanube | Reintento acotado, sin marcar la conexión como caída |

## 6. Fuera de alcance de esta spec

Cualquier endpoint de catálogo (`/products` más allá de la verificación), pedidos (`/orders`), stock,
webhooks de negocio o suscripción a webhooks — quedan para la spec futura que migre specs 017/018 a REST
(ver spec.md "Alcance").
