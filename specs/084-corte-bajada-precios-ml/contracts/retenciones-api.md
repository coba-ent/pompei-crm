# Contratos — spec 084

Todos responden JSON y se consumen por AJAX desde modales, sin recargar la página (CLAUDE.md §2).
Los errores de validación vuelven en JSON para mostrarse en el modal o en un toast.

---

## 1 · Listado de retenciones abiertas

```
GET /integraciones/mercadolibre/retenciones-precio
```

Server-side de DataTables (`draw`, `start`, `length`, `search[value]`, `order`).

**Respuesta**

```json
{
  "draw": 1,
  "recordsTotal": 3,
  "recordsFiltered": 3,
  "data": [
    {
      "id": 12,
      "ml_item_id": "MLA870718840",
      "producto": "ATU-TP-005-BL — Tapa inodoro Atuel",
      "tipo_publicacion": "Premium",
      "lista": "ML Premium",
      "precio_publicado": 317743.34,
      "precio_propuesto": 218607.42,
      "precio_vigente_lista": 218607.42,
      "caida_pct": 31.20,
      "umbral_pct": 20.00,
      "motivo": "supera_umbral",
      "retenida_en": "2026-08-27T14:03:22-03:00"
    }
  ],
  "resumen": { "abiertas": 3, "caida_maxima_pct": 99.90 }
}
```

`precio_vigente_lista` es lo que se enviaría **hoy** si se aprobara. Cuando difiere de
`precio_propuesto`, la pantalla lo marca (FR-014): la propuesta envejeció.

---

## 2 · Aprobar una retención

```
POST /integraciones/mercadolibre/retenciones-precio/{retencion}/aprobar
```

**Cuerpo**

```json
{ "confirma_precio_distinto": true }
```

Obligatorio **sólo** cuando `precio_vigente_lista != precio_propuesto`. Sin él, en ese caso, la
respuesta es `422` con el detalle de los dos importes: la persona aprobó un número y se enviaría
otro, y eso tiene que ser una decisión consciente.

**200**

```json
{
  "ok": true,
  "mensaje": "Precio publicado en Mercado Libre.",
  "precio_enviado": 218607.42,
  "retencion": { "id": 12, "estado": "aprobada" }
}
```

**409** — la retención ya no está abierta (otra persona la resolvió, o una propuesta nueva la
reemplazó).

**422** — falta `confirma_precio_distinto`, o Mercado Libre rechazó el envío. En el segundo caso la
retención **queda abierta**: no se da por resuelta algo que no se pudo publicar.

---

## 3 · Rechazar una retención

```
POST /integraciones/mercadolibre/retenciones-precio/{retencion}/rechazar
```

**200**

```json
{ "ok": true, "mensaje": "Retención descartada. El precio en Mercado Libre no cambió.", "retencion": { "id": 12, "estado": "rechazada" } }
```

No envía nada. `409` si ya no está abierta.

---

## 4 · Previa del cambio de lista configurada

```
POST /integraciones/mercadolibre/configuracion/ventas/previa
```

**Cuerpo** — las listas que se quieren dejar configuradas.

```json
{ "lista_precio_id": 7, "lista_precio_id_premium": 10 }
```

**200**

```json
{
  "ok": true,
  "cambia": { "general": true, "premium": false },
  "impacto": {
    "publicaciones_afectadas": 240,
    "suben": 12,
    "bajan": 221,
    "sin_cambio": 7,
    "quedarian_retenidas": 198,
    "sin_precio_en_la_lista": 5,
    "caida_maxima": { "pct": 42.10, "ml_item_id": "MLA1500482785", "de": 578111.11, "a": 334905.34 }
  },
  "umbral_pct": 20.00
}
```

**No aplica nada**: es sólo el cálculo. Se resuelve contra `precio_publicado`, sin llamar a la API
(Decisión 7), así que responde al instante.

`sin_precio_en_la_lista` es el que hay que mirar aparte: esas publicaciones **no** cambiarían de
precio, quedarían con el que ya tienen. Un número alto ahí significa que la lista elegida está
incompleta.

---

## 5 · Guardar la configuración

```
PUT /integraciones/mercadolibre/configuracion/ventas
```

Cambia respecto de hoy: cuando el cuerpo modifica alguna de las dos listas, exige
`confirma_republicacion: true`. Sin él responde **422** con el mismo cuerpo que la previa, para que
el frontend muestre el diálogo.

Si no cambia ninguna lista, se comporta igual que hoy: guarda y no republica (FR-019).

---

## 6 · Chequeo de precios publicados

```
GET  /monitoreo/precios-mercadolibre        · último resultado
POST /monitoreo/precios-mercadolibre/correr · ejecuta ahora (FR-026)
```

**Respuesta del GET**

```json
{
  "corrida_en": "2026-08-27T03:00:11-03:00",
  "resumen": { "verificadas": 270, "coinciden": 267, "difieren": 0, "retenidas": 3, "no_verificables": 0 },
  "diferencias": [],
  "retenidas": [ { "ml_item_id": "MLA870718840", "producto": "ATU-TP-005-BL", "precio_publicado": 317743.34, "precio_crm": 218607.42 } ],
  "advertencias": {
    "premium_sin_precio_en_su_lista": [],
    "sin_tipo_de_publicacion": []
  }
}
```

`retenidas` va **separado** de `difieren` (FR-023): una retenida difiere a propósito. Mezclarlas
haría que el panel muestre como problema algo que el sistema hizo bien.

`advertencias` cubre la US4: las Premium sin precio en su lista y los vínculos sin tipo conocido.

El comando equivalente para la corrida diaria:

```
php artisan ml:chequear-precios [--refrescar-publicado] [--json]
```

`--refrescar-publicado` es lo que usa el **backfill** de la Decisión 5. Es de sólo lectura contra
Mercado Libre; lo único que escribe es `precio_publicado` en la base.
