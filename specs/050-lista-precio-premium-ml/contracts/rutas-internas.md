# Contrato de rutas internas — Lista de Precios diferenciada para publicaciones Premium (spec 050)

**Spec**: [../spec.md](../spec.md) · **Plan**: [../plan.md](../plan.md)

Extiende `specs/016-lista-precio-mercadolibre/contracts/rutas-internas.md` (§3). Sin rutas nuevas: se
agrega un campo al payload ya existente.

## 1. Configuración de Mercado Libre — `PATCH configuracion/mercadolibre/ventas` (extensión)

```jsonc
// Request (agrega lista_precio_id_premium al payload ya existente de spec 016)
{
  "deposito_id": 1,
  "categoria_venta_id": 3,
  "lista_precio_id": 2,
  "lista_precio_id_premium": 5 // nuevo, nullable — FR-001
}
```

```jsonc
// Response (agrega lista_precio_id_premium a la configuración devuelta)
{
  "ok": true,
  "mensaje": "Configuración de ventas guardada.",
  "configuracion": { /* … */ "lista_precio_id": 2, "lista_precio_id_premium": 5 }
}
```

**Efecto adicional al guardar (FR-010)**: igual que ya ocurre con `lista_precio_id` (spec 016, contracts
§3), si `lista_precio_id_premium` cambió respecto del valor anterior y el nuevo valor no es `null`, se
dispara de inmediato el envío del precio vigente de esa lista a las publicaciones Premium vinculadas que
tengan precio cargado ahí. Mismos cortes de kill-switch/modo sólo lectura/conexión caída que cualquier
otro envío — si está bloqueado, esos vínculos quedan `precio_pendiente = true` para el próximo intento
válido, sin que el guardado de la configuración falle por eso.

## 2. Vinculación de publicaciones — sin cambios de contrato

**Fuera de alcance** (spec Assumptions): esta feature no agrega una columna visible de tipo de
publicación a la datatable de `/ingresos/mercadolibre/vinculaciones`. El dato (`listing_type_id`)
existe y se usa internamente en la sincronización de precios, pero mostrarlo en pantalla queda como
ampliación futura si se pide explícitamente.

## 3. Comando programado nuevo (sin ruta HTTP)

`mercadolibre:sincronizar-tipos-publicacion`, registrado en `bootstrap/app.php` junto a los comandos ya
programados de Mercado Libre (`everyMinute()->withoutOverlapping()`, decide internamente si corresponde
correr según `tipo_publicacion_ultima_sync_en` y el intervalo fijo de 24hs — FR-004, research.md §R3).
Sin acción manual expuesta en pantalla para esta feature (Assumptions de la spec: no hace falta
visibilidad de UI del tipo de publicación para el alcance actual).
