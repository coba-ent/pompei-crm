# Contratos — rutas internas (spec 115)

Todas dentro del grupo existente `ingresos.mercadolibre.vinculaciones.` (middleware `permiso:ventas.ver`,
mismo permiso que la pantalla y que aprobar retenciones de precio). Las rutas estáticas del lote se
registran **antes** de las `{vinculacion}` para que no las capture el parámetro. Respuestas JSON;
`destino` ∈ {`gold_pro`, `gold_special`} (otro valor → 422).

## 1. Previsualizar cambio individual

`GET vinculaciones/{vinculacion}/cambio-tipo?destino=gold_pro` → `cambioTipo.previsualizar`

`destino` es opcional sólo para vínculos sin clasificar: se consulta el tipo real, se guarda y se
propone el opuesto. Consulta ML (tipo vigente + disponibles) y resuelve el precio destino. No escribe nada salvo
actualizar `listing_type_id` si el de ML difiere del guardado.

```json
200 {
  "ok": true,
  "puede_confirmar": true,
  "motivo_bloqueo": null,
  "vinculo": { "id": 7, "ml_item_id": "MLA123", "titulo_ml": "...",
               "producto": { "id": 4521, "codigo": "TAP-01", "nombre": "..." } },
  "tipo_actual": "gold_special", "tipo_actual_etiqueta": "Clásica",
  "tipo_destino": "gold_pro", "tipo_destino_etiqueta": "Premium",
  "precio_actual": 100000.00,          // null => la UI muestra "Desconocido"
  "precio_destino": 145350.00,
  "lista_destino": { "id": 3, "nombre": "ML Premium" },
  "sin_cambios": false,                // true si ML ya tenía el tipo destino
  "aviso_comision": true,              // sólo destino Premium
  // Ajuste 16/10/2026 (FR-019). Opciones vacías y sugerida null si no se puede confirmar.
  "cuotas_actual": "no-campaign",
  "cuotas_actual_etiqueta": "Sin cuotas (sólo las de los bancos)",
  "cuotas_opciones": [
    { "id": "3x_campaign",  "etiqueta": "3 cuotas sin interés",  "cargo": { "porcentaje": 17.5, "importe": 25436.25 } },
    { "id": "no-campaign",  "etiqueta": "6 cuotas sin interés",  "cargo": { "porcentaje": 23.5, "importe": 34157.25 } },
    { "id": "9x_campaign",  "etiqueta": "9 cuotas sin interés",  "cargo": null }   // null = ML no informó el cargo
  ],
  "cuotas_sugerida": "no-campaign"
}
```

`puede_confirmar = false` con `motivo_bloqueo` para: sin lista destino configurada, sin precio del
producto en la lista destino, destino no disponible en ML, vínculo sin producto, tipo no soportado
(ej. `free`), cortes (función desactivada / sólo lectura / sin cuenta). Error de red con ML → 502
`{ok:false, mensaje}`.

## 2. Ejecutar cambio individual

`POST vinculaciones/{vinculacion}/cambio-tipo` body `{ "destino": "gold_pro", "cuotas": "9x_campaign" }` → `cambioTipo.ejecutar`

Re-evalúa todas las validaciones de §1 (no confía en la previsualización) y ejecuta según research R2;
las cuotas, según R9. `cuotas` es opcional (null = no tocarlas); con un id que ML no admite para esa
publicación → 422 `bloqueado`; con formato inválido → 422 de validación.

```json
200 { "ok": true, "resultado": "cambiado", "mensaje": "Publicación MLA123 pasada a Premium a $ 145.350,00, 9 cuotas sin interés." }
200 { "ok": true, "resultado": "cambiado_precio_pendiente", "mensaje": "Pasada a Clásica; el precio quedó pendiente: <motivo>." }
200 { "ok": true, "resultado": "cambiado_cuotas_pendientes", "mensaje": "... pero Mercado Libre no aplicó las cuotas elegidas (<motivo>) ..." }
200 { "ok": true, "resultado": "sin_cambios", "mensaje": "La publicación ya era Premium en Mercado Libre." }
422 { "ok": false, "resultado": "bloqueado", "mensaje": "<motivo>" }
409 { "ok": false, "resultado": "bloqueado", "mensaje": "Hay una sincronización de precios en curso..." }
502 { "ok": false, "resultado": "fallido|revertido", "mensaje": "<motivo de ML>" }
```

## 3. Previsualizar lote

`POST vinculaciones/cambio-tipo-lote/previsualizar` body `{ "destino": "gold_pro", "ids": [7, 8, 9] }` → `cambioTipoLote.previsualizar`

`ids`: 1..100 enteros existentes. Evalúa las reglas **locales** (tipo guardado, lista y precio destino,
cortes) sin llamar a ML por cada una —la verificación contra ML se hace por publicación al ejecutar—.

```json
200 { "ok": true,
      "a_cambiar": [ { "id": 7, "ml_item_id": "MLA123", "producto": "#4521 · TAP-01 — ...",
                       "precio_actual": 100000.00, "precio_destino": 145350.00 } ],
      "omitidas":  [ { "id": 9, "ml_item_id": "MLA999", "motivo": "Sin precio en la lista ML Premium" } ],
      "puede_confirmar": true, "aviso_comision": true,
      "cuotas_opciones": [ { "id": "3x_campaign", "etiqueta": "3 cuotas sin interés" }, "..." ],   // lista documentada, sin cargo
      "cuotas_sugerida": "no-campaign" }
```

## 4. Ejecutar lote

`POST vinculaciones/cambio-tipo-lote` body `{ "destino": "gold_pro", "ids": [7, 8], "cuotas": "9x_campaign" }` → `cambioTipoLote.ejecutar`

`cuotas` opcional, la misma para toda la selección; viaja al job y queda en el estado del lote.

Encola `CambioTipoPublicacionLote`. `202 { ok: true, lote_id, mensaje }`; `409` si hay un lote `en_curso`.

## 5. Estado del lote

`GET vinculaciones/cambio-tipo-lote/estado` → `cambioTipoLote.estado`

Devuelve el JSON de Cache descripto en `data-model.md` o `{ "estado": "sin_datos" }`.

## 6. DataTable de vinculaciones (modificado)

`GET vinculaciones/datatable` — parámetros nuevos: `listing_type` ∈ {'', `gold_special`, `gold_pro`,
`sin_clasificar`}. Columnas nuevas en cada fila: `producto_id`, `producto_codigo`, `tipo_etiqueta`,
`listing_type_id`, `precio_publicado`. La búsqueda global encuentra por `producto.id` exacto,
`producto.codigo` y `producto.nombre`.

## UI

- Menú de fila: "Pasar a Premium" (si Clásica), "Pasar a Clásica" (si Premium), "Cambiar tipo" (si sin
  clasificar: llama a la previsualización sin `destino`; el endpoint consulta el tipo real, lo guarda y
  propone el opuesto — `destino` es opcional sólo cuando el vínculo no tiene `listing_type_id`).
- Barra sobre la tabla: botones "Pasar selección a Premium" / "Pasar selección a Clásica", habilitados
  con ≥1 casilla marcada; casilla "todas las de esta página" en el encabezado.
- Modal `#modal-cambio-tipo` (individual) y `#modal-cambio-tipo-lote` (lote, con tablas "Se van a
  cambiar" / "Omitidas" y luego progreso + resumen). Notificaciones con Toastr.
