# Data Model: Columna Punto de Reposición en el listado de Productos

Sin cambios de esquema. Esta spec sólo **expone** un atributo que ya existe.

## Producto (existente, sin cambios)

Atributo relevante, ya definido por spec 073 (`docs/modelo_datos.md`):

| Columna | Tipo | Regla |
|---|---|---|
| `punto_reposicion` | `unsignedInteger`, `NOT NULL`, default `0` | `0` = "sin control" (no genera alerta). Aplica sólo a `tipo='producto'` y `activo=true` — ver `Producto::esServicio()`. |

## Representación en el listado (DataTables/Yajra)

No es una entidad nueva: es una columna más del JSON que ya devuelve `ProductoController::data()`.

| Campo del JSON | Origen | Transformación |
|---|---|---|
| `punto_reposicion` | `productos.punto_reposicion` (columna directa del modelo, ya viaja sin tocar el `SELECT`) | `editColumn`: si `$p->esServicio()` → `null` (igual criterio que `stock_total`/`stock_deposito_*`); si no, el entero tal cual. El "sin control" para `0` se resuelve en el render del **frontend** (mismo patrón que el resto de las columnas: el backend manda el dato crudo, el JS decide cómo mostrarlo — ver `stock_deposito_*` con su formateo de negativos). |

## Relaciones

Ninguna nueva. `punto_reposicion` es un campo escalar de `productos`, sin relación con otras tablas.

## Reglas de validación (ya vigentes, sin cambios en esta spec)

- `Producto::setPuntoReposicionAttribute()` normaliza vacío → `0` (ya implementado, spec 073). Esta
  spec no toca la escritura, sólo la lectura para el listado.
- El valor nunca es negativo (columna `unsignedInteger`); no hace falta manejar ese caso en la UI.
