# Contratos: endpoints reutilizados (sin cambios de contrato)

Todos los endpoints usados por esta feature ya existen y no se modifica su firma. Se documentan acá sólo como referencia de integración para el frontend nuevo.

## Cliente

| Acción | Método | Ruta con nombre | Body mínimo | Respuesta 2xx |
|---|---|---|---|---|
| Crear (alta rápida) | POST | `clientes.store` | `{ nombre }` | `{ ok: true, cliente: { id, nombre, ... } }` |
| Editar (rename rápido) | PUT/PATCH | `clientes.update` (`{cliente}`) | `{ nombre }` | `{ ok: true, cliente: { id, nombre, ... } }` |

Nota: el modal rápido sólo envía `nombre`; el resto de los campos del cliente quedan como estaban (o `null` si es alta) — comportamiento ya soportado hoy por `StoreClienteRequest`/`UpdateClienteRequest` sin cambios.

## Categoría de venta

| Acción | Método | Ruta con nombre | Body mínimo | Respuesta 2xx |
|---|---|---|---|---|
| Crear | POST | `categorias.venta.store` | `{ nombre }` | `{ ok: true, categoria: { id, nombre, tipo: 'venta', es_sistema: false } }` |
| Editar | PATCH | `categorias.update` (`{categoria}`) | `{ nombre }` | `{ ok: true, categoria: {...} }` |

Sin cambios respecto del comportamiento actual (ya usado por `#btn-renombrar-categoria` hoy); sólo cambia desde dónde se dispara en el frontend.

## Vendedor

| Acción | Método | Ruta con nombre | Body mínimo | Respuesta 2xx |
|---|---|---|---|---|
| Crear | POST | `vendedores.store` | `{ nombre }` | `{ ok: true, vendedor: { id, nombre } }` |
| Editar | PATCH | `vendedores.update` (`{vendedor}`) | `{ nombre }` | `{ ok: true, vendedor: {...} }` |

Sin cambios respecto del comportamiento actual; sólo cambia desde dónde se dispara en el frontend.

## Fuera de contrato (no se agregan)

- No se agrega ningún endpoint de "eliminar" nuevo ni se expone `vendedores.destroy` / `categorias.destroy` desde este formulario (FR-006).
- No se agrega ningún parámetro nuevo a `clientes.opciones` (se sigue usando tal cual para la búsqueda del dropdown).
