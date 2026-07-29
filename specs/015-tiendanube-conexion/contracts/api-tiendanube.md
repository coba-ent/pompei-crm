# Contrato — API externa de Tiendanube

**Feature**: `015-tiendanube-conexion`

Documenta el contrato con el proveedor externo, análogo a
`011-mercadolibre-conexion-oauth/contracts/api-mercadolibre.md`. A verificar contra la documentación
oficial vigente al momento de implementar (research.md §R1/§R3 marcan los puntos con menor certeza).

---

## 1. Base y direccionamiento

```
https://api.tiendanube.com/v1/{store_id}/{recurso}
```

`{store_id}` es el identificador numérico cargado por el usuario (research.md §R2). No hay dominio de
autorización separado: no existe pantalla de autorización externa en este modelo (research.md §R1).

## 2. Autenticación de cada llamada

Cabeceras obligatorias en toda request:

```
Authentication: bearer {access_token}
User-Agent: Contagram CRM (contacto@negocio.com)
Content-Type: application/json
```

⚠️ **`Authentication`, no `Authorization`** (research.md §R3) — trampa verificada y documentada para
que la implementación no la redescubra. El `User-Agent` debe identificar la aplicación y un contacto;
Tiendanube puede limitar o rechazar llamadas sin uno.

## 3. Verificación de conexión

```
GET /{store_id}/store
```

**200** (forma esperada, campos relevantes para esta spec):
```json
{
  "id": 1234567,
  "name": { "es": "Mi Tienda" },
  "url": "https://mitienda.mitiendanube.com",
  "original_domain": "mitienda.mitiendanube.com",
  "country": "AR",
  "currency": "ARS"
}
```

**Mapeo a `tn_configuracion`**: `name.es` (o el idioma principal de la tienda) → `nombre_tienda`;
`original_domain` → `dominio`; `country` → `pais`; `currency` → `moneda`.

## 4. Códigos de error relevantes

| Código | Significado | Tratamiento del CRM |
|---|---|---|
| 401 | Token inválido, expirado o revocado | Conexión → `caida` (FR-012), no se reintenta |
| 404 | `store_id` inexistente o no corresponde al token | Mismo tratamiento que 401 (edge case de spec.md) |
| 422 | Payload inválido (no aplica a esta spec: sólo se hacen lecturas) | — |
| 429 | Límite de solicitudes excedido | Espera creciente + reintento acotado (FR-013), respetando `Retry-After` si el proveedor lo envía |
| 5xx | Falla del lado de Tiendanube | Reintento acotado sin marcar la conexión como caída (FR-014) |

## 5. Límite de solicitudes

**A verificar al implementar**: Tiendanube documenta un límite de solicitudes por tienda (del orden de
pocas por segundo). El cliente debe leer el header de límite que el proveedor exponga en cada
respuesta (análogo a `Retry-After` ya manejado por `ClienteMercadoLibre`) en lugar de asumir un número
fijo en el código — mismo criterio que FR-028a de Mercado Libre para no hardcodear valores que la
documentación pueda tener desactualizados.

## 6. Fuera de alcance de esta spec

Cualquier endpoint de catálogo (`/products`), órdenes (`/orders`) o webhooks queda para las specs 016
(ventas) y 017 (stock), continuación directa de ésta — mismo patrón que Mercado Libre (011 → 012 → 013).
