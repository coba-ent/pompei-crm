# Contratos: rutas internas — Transformar todas en Venta

Dos endpoints simétricos, uno por integración, siguiendo el mismo formato de respuesta que
`POST .../vinculaciones/vincular-automaticamente` (spec 021/023).

## `POST /ingresos/tiendanube/transformar-todas-en-venta`

**Nombre de ruta**: `ingresos.tiendanube.transformarTodasEnVenta`

**Controller**: `TiendanubeVentaController@transformarTodasEnVenta`

**Autenticación**: sesión web autenticada (igual que el resto de `ingresos.tiendanube.*`)

**Request**: sin body — el batch opera sobre todas las órdenes `Lista` de la conexión vigente, no
sobre una selección enviada por el cliente (FR-002).

**Response 200** (éxito, incluso con fallidas parciales):

```json
{
  "ok": true,
  "mensaje": "8 de 10 órdenes convertidas.",
  "total": 10,
  "convertidas": 8,
  "fallidas": 2,
  "detalle_fallidas": [
    { "orden": "1234", "motivo": "Más de un Cliente con el mismo email", "motivo_detalle": null }
  ]
}
```

**Response 422/409** (bloqueada por guardrail — función avanzada desactivada o modo solo lectura):

```json
{ "ok": false, "tipo": "bloqueada", "mensaje": "La función \"Tiendanube\" está desactivada en Funciones Avanzadas." }
```

Código de estado sugerido: 409 (mismo criterio ya usado por los endpoints de sincronización
existentes de la integración — confirmar contra la convención vigente de `TiendanubeVentaController`
al implementar).

## `POST /ingresos/mercadolibre/transformar-todas-en-venta`

**Nombre de ruta**: `ingresos.mercadolibre.transformarTodasEnVenta`

**Controller**: `MercadoLibreVentaController@transformarTodasEnVenta`

Idéntico contrato al de Tiendanube, operando sobre `ml_ordenes` y la conexión/configuración de
MercadoLibre vigente.

## Reglas comunes

- Ambos endpoints son idempotentes en efecto neto: correrlos dos veces seguidas la segunda vez
  devuelve `total: 0` (o sólo las que quedaron `Lista` desde la primera corrida) — no hay forma de
  "reconvertir" una orden ya `Convertida`.
- `detalle_fallidas` viaja vacío (`[]`) cuando `fallidas = 0`; el frontend decide si mostrar el modal
  igual (con el resumen) o sólo un toast — ver `quickstart.md`.
- El `mensaje` de nivel superior es apto para mostrarse en un toast de éxito; el detalle granular va
  sólo en el modal.
