# Contrato: Historial y Undo de Importaciones (Productos & Servicios)

Todas las rutas van bajo el mismo grupo de middleware/prefijo que `routes/web.php` ya usa para
`importar-datos/{entidad}` (login + permisos vigentes del módulo Base de Datos → Importar Datos).
Respuestas AJAX en JSON (Regla obligatoria #1/#2 del proyecto: DataTables + modales AJAX, sin
recarga de página).

## `GET /importar-datos/productos/historial`

Página del historial de corridas (DataTables server-side, spec-kit Regla #1).

**Respuesta (vista)**: tabla vacía inicial, poblada por AJAX contra el endpoint de datos.

## `GET /importar-datos/productos/historial/datos` (DataTables server-side)

Query estándar de DataTables (`draw`, `start`, `length`, `search`, `order`).

**Response 200**:
```json
{
  "draw": 1,
  "recordsTotal": 12,
  "recordsFiltered": 12,
  "data": [
    {
      "id": 45,
      "confirmado_en": "2026-08-24 10:15:00",
      "usuario": "federico",
      "archivo_original": "productos_agosto.xlsx",
      "filas_creadas": 10,
      "filas_actualizadas": 340,
      "filas_fallidas": 2,
      "estado": "vigente",
      "deshacer_disponible_hasta": "2026-08-26 10:15:00",
      "puede_deshacer": true
    }
  ]
}
```

`estado` ∈ `vigente` | `deshecho` | `vencido`. `puede_deshacer` es `true` sólo si
`estado = vigente` — el frontend nunca decide esto por su cuenta, siempre refleja lo que manda el
backend.

## `POST /importar-datos/productos/historial/{corrida}/deshacer`

Ejecuta el undo de una corrida. Confirmación previa vía modal estándar del template (Regla #2).

**Validaciones server-side** (devuelven 422 con mensaje para toast de error, Regla #3):
- La corrida debe existir y `entidad = productos`.
- `estado` debe ser `vigente` (ni ya deshecha ni vencida).

**Response 200** (éxito, total o parcial):
```json
{
  "revertidas": 338,
  "no_revertidas": [
    { "producto_id": 812, "numero_fila": 47, "motivo": "El producto tiene una venta posterior al import" },
    { "producto_id": 901, "numero_fila": 103, "motivo": "El producto fue modificado por una corrida de import más reciente" }
  ],
  "mensaje": "Se revirtieron 338 de 340 filas. 2 no se pudieron deshacer."
}
```

El frontend muestra `mensaje` en un toast (éxito si `no_revertidas` está vacío, advertencia si no)
y refresca la fila de esa corrida en la tabla de historial sin recargar la página.

## `GET /importar-datos/productos/resumen` (existente, se extiende)

El resumen que ya se muestra al terminar el Paso 3 (`resumen.blade.php`) agrega, cuando la corrida
recién confirmada tiene `puede_deshacer = true`, un botón "Deshacer este import" que dispara el
mismo endpoint de arriba — atajo para el caso más común (deshacer inmediatamente después de
notar el error), sin obligar a ir al historial.
