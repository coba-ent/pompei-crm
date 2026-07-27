# Contrato de UI / Rutas: Gestión de Depósitos

Interfaz que esta feature expone al usuario. Rutas web Laravel (Blade + AJAX), en español, bajo
Configuración & Ajustes.

**Reglas de diseño obligatorias aplicadas** (ver `CLAUDE.md`):
- Alta/renombrado/cambio de estado/eliminación por **modal Bootstrap + AJAX**, sin recargar.
- Toda notificación con **toasts de Toastr**.

## Rutas

| Método | Ruta | Nombre | Acción | Respuesta |
|---|---|---|---|---|
| GET | `/configuracion/depositos` | `configuracion.depositos.index` | Página shell + modal | HTML |
| GET | `/configuracion/depositos/data` | `configuracion.depositos.data` | Lista completa de depósitos | JSON |
| POST | `/configuracion/depositos` | `configuracion.depositos.store` | Crear depósito | JSON |
| PATCH | `/configuracion/depositos/{deposito}` | `configuracion.depositos.update` | Renombrar depósito | JSON |
| PATCH | `/configuracion/depositos/{deposito}/estado` | `configuracion.depositos.estado` | Alternar activo/inactivo | JSON |
| DELETE | `/configuracion/depositos/{deposito}` | `configuracion.depositos.destroy` | Eliminar (rechaza si tiene operaciones) | JSON |

## Contratos JSON

### GET `configuracion.depositos.data`

Response: `{ data: [{ id, nombre, activo }, ...] }` — orden por `nombre`, sin paginado (catálogo
chico, research.md).

### POST `configuracion.depositos.store`

Request: `{ nombre: string }`. Response: `200 { ok: true, deposito: {...}, mensaje: "Depósito creado." }`
o `422 { ok: false, errors: { nombre: [...] } }` si viene vacío.

### PATCH `configuracion.depositos.update`

Request: `{ nombre: string }`. Response: igual forma que `store`, mensaje "Depósito renombrado."

### PATCH `configuracion.depositos.estado`

Sin body. Response: `200 { ok: true, activo: boolean, mensaje: "Depósito activado."|"Depósito desactivado." }`

### DELETE `configuracion.depositos.destroy`

- Si `Deposito::tieneOperaciones()` → `409 { ok: false, mensaje: "Sólo puede inactivarse: el depósito tiene stock o movimientos asociados." }`
- Si no → `200 { ok: true, mensaje: "Depósito eliminado." }`

## Notas de UI

- La pantalla "Depósitos" (`configuracion/depositos.blade.php`) tiene un botón que abre el modal
  "Configuración de Depósitos" — mismo texto/estructura que Contagram real (fila por depósito:
  nombre editable inline, checkbox de activo con tooltip, ícono de editar, ícono de eliminar; "+
  Agregar Depósito" arriba; Cancelar/Guardar al pie, ambos cierran el modal sin acción adicional —
  research.md §1).
- El checkbox de activo lleva el tooltip exacto relevado: *"Al activar/desactivar el check, el
  depósito se habilitará/ocultará en el listado de depósitos y reportes."*
- Nueva entrada "Depósitos" en el sidebar, bajo "Configuración & Ajustes" (`resources/views/elements/sidebar.blade.php`).
