# Data Model: Crear/editar catálogo inline en selects de Presupuestos

No se agregan entidades, tablas ni columnas nuevas. Se reutilizan tal cual las entidades ya existentes:

## Cliente (reutilizada, sin cambios de esquema)

- Campos relevantes a este spec: `id`, `nombre` (único campo que el modal rápido de alta/edición inline gestiona).
- Regla ya vigente (no se modifica): `nombre` es el único campo `required` en `StoreClienteRequest`/`UpdateClienteRequest` (vía `App\Http\Requests\Concerns\ReglasCliente`); todos los demás campos (razón social, CUIT, domicilio, contactos, etc.) son `nullable` y quedan sin completar tras un alta rápida desde este flujo.

## Categoría (de venta) (reutilizada, sin cambios de esquema)

- Campos relevantes: `id`, `nombre`, `tipo` (fijo a "venta" al crear desde este contexto, vía `categorias.venta.store`), `es_sistema` (determina si se puede editar/eliminar — sin cambios de regla).

## Vendedor (reutilizada, sin cambios de esquema)

- Campos relevantes: `id`, `nombre` (único campo del modelo).

## Estado de UI (no persistido)

- **Opción sintética "Crear X"** dentro de los `results` de Select2: no es una entidad de dominio, es un objeto transitorio `{ id: '__crear__', text: '<label>', esCrear: true }` generado en el cliente (JS) para renderizar la fila fija del dropdown; nunca se envía al backend ni se persiste.
