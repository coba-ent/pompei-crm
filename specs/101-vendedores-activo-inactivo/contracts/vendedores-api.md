# Contract: Endpoints de Vendedores

Todos dentro del grupo `Route::middleware('admin')` de Configuración & Ajustes, prefijo `vendedores.*`.
Respuestas JSON, consumidas por AJAX (sin recarga de página), notificadas vía toast.

## GET `vendedores/data` (nuevo)

Lista completa para la tabla del tab (client-side DataTables, catálogo chico — ver research.md R5).

**Response 200**:
```json
{
  "data": [
    { "id": 1, "nombre": "Juan Pérez", "activo": true },
    { "id": 2, "nombre": "Ex Vendedor", "activo": false }
  ]
}
```

## GET `vendedores/opciones` (nuevo, o extensión de `data` con filtro)

Usado por los Select2 de asignación (Venta, Presupuesto, Tiendanube, MercadoLibre, Vendedor por
defecto). Sólo activos.

**Response 200**:
```json
{ "data": [ { "id": 1, "nombre": "Juan Pérez" } ] }
```
*(equivalente a `Vendedor::activos()->orderBy('nombre')->get(['id','nombre'])`, ya usado hoy
inline vía Blade `compact('vendedores')` en los controllers de Venta/Presupuesto/Configuración —
no necesariamente requiere una ruta AJAX nueva si esos controllers ya inyectan la colección
filtrada en la vista)*

## POST `vendedores` (existente, sin cambios de contrato)

```json
// Request
{ "nombre": "Nuevo Vendedor" }
// Response 201
{ "ok": true, "mensaje": "Vendedor creado correctamente.", "vendedor": { "id": 3, "nombre": "Nuevo Vendedor", "activo": true } }
```

## PATCH `vendedores/{vendedor}` (existente, sin cambios de contrato salvo incluir `activo` en la respuesta)

```json
// Request
{ "nombre": "Vendedor Renombrado" }
// Response 200
{ "ok": true, "mensaje": "Vendedor renombrado.", "vendedor": { "id": 3, "nombre": "Vendedor Renombrado", "activo": true } }
```

## PATCH `vendedores/{vendedor}/estado` (nuevo — calco de `depositos/{deposito}/estado`)

Alterna `activo`. Sin restricción por uso (a diferencia de `destroy`).

**Response 200**:
```json
{ "ok": true, "activo": false, "mensaje": "Vendedor desactivado." }
```

## DELETE `vendedores/{vendedor}` (existente, sin cambios)

Sigue pudiendo fallar 422 si hay FK en uso — comportamiento ya existente, no se modifica.

```json
// Response 422 (en uso)
{ "ok": false, "mensaje": "No se puede eliminar: está en uso." }
// Response 200 (éxito)
{ "ok": true, "mensaje": "Vendedor eliminado." }
```
