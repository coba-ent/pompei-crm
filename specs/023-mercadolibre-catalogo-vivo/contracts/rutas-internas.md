# Contrato de rutas internas — Vinculación automática de Mercado Libre por catálogo en vivo

**Sin rutas nuevas ni modificadas.** Reutiliza exactamente el endpoint ya definido por la spec 021.

## `POST ingresos/mercadolibre/vinculaciones/vincular-automaticamente` (sin cambios de ruta)

Sin parámetros. Sigue disparando `VinculadorAutomatico::ejecutar()` — cambia sólo la implementación
interna de ese método (research.md, plan.md "Enfoque técnico").

### Camino exitoso (200) — idéntico a spec 021

```jsonc
{
  "ok": true,
  "mensaje": "9 de 12 publicaciones vinculadas.",
  "total": 12,
  "vinculadas": 9,
  "fallidas": 3,
  "detalle_fallidas": [
    { "referencia": "MLA1927008393", "motivo": "sin_sku" },
    { "referencia": "MLA3690442970", "motivo": "producto_no_encontrado" }
  ]
}
```

### Camino de error nuevo (502) — catálogo en vivo falló a mitad de la corrida

No existía en spec 021 (el mecanismo basado en órdenes no dependía de una llamada en vivo que pudiera
fallar a mitad de camino). Se agrega cuando `VinculadorAutomatico` lanza
`VinculacionAutomaticaFallidaException` (spec.md Assumptions, data-model.md):

```jsonc
{
  "ok": false,
  "mensaje": "No se pudo completar la vinculación automática: <mensaje de RespuestaMercadoLibre::mensajeError>."
}
```

Ningún vínculo se crea en este camino — mismo criterio que un fallo de conexión ya documentado en el resto
de la integración (no es un resumen parcial con `vinculadas`/`fallidas`).
