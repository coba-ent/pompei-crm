# Contratos: Lista de Precios en la configuración de Mercado Libre

**Spec**: [../spec.md](../spec.md) · **Plan**: [../plan.md](../plan.md)

No se agrega ninguna ruta nueva. Se extiende el body de una ruta ya existente (spec 012,
`specs/012-ventas-mercadolibre/contracts/rutas-internas.md §3`).

## Configuración de ventas de Mercado Libre (extendida)

Permiso: `configuracion.funciones` (sin cambios).

| Método | Ruta | Nombre | Propósito | FR |
|---|---|---|---|---|
| PATCH | `/configuracion/mercadolibre/ventas` | `mercadolibre.ventas.configurar` | Guardar opciones de ventas | FR-001, FR-002 (esta spec) + FR-010/FR-047/FR-050 (spec 012, sin cambios) |

Request body — se agrega `lista_precio_id`, mismo nivel que `deposito_id`/`categoria_venta_id`:

```jsonc
{
  "creacion_automatica": false,
  "frecuencia_sync_minutos": 15,
  "deposito_id": 3,
  "categoria_venta_id": null,
  "lista_precio_id": null,            // NUEVO — FR-001/FR-002 — null ⇒ Venta convertida sin Lista de Precios
  "dias_primera_sync": 30
}
```

Validación agregada al `FormRequest` existente: `'lista_precio_id' => ['nullable', 'exists:listas_precio,id']`
(idéntica a la ya existente para `categoria_venta_id`).

Respuesta — sin cambios de forma, `configuracion` en el JSON de respuesta ahora incluye `lista_precio_id`:

```jsonc
{ "ok": true, "mensaje": "Configuración de ventas guardada.", "configuracion": { "...": "...", "lista_precio_id": null } }
```

## Endpoint de estado (`GET /configuracion/mercadolibre/estado`, ya existente)

Sin cambios de ruta. El bloque `configuracion` de la respuesta agrega `lista_precio_id`, mismo patrón que
`deposito_id`/`categoria_venta_id` ya expuestos ahí (`MercadoLibreConfiguracionController::estado()`).

## Sin impacto en rutas de conversión de órdenes

`POST /ingresos/mercadolibre/{orden}/convertir` (spec 012) no cambia de contrato: el efecto de esta spec
es interno a `ConversorOrdenAVenta::convertir()` (la Venta resultante trae `lista_precio_id` asignado),
no un campo nuevo del request ni de la respuesta de esa ruta.
