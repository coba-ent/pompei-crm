# Contrato de rutas internas — Vendedores (spec 020)

**Spec**: [../spec.md](../spec.md) · **Plan**: [../plan.md](../plan.md)

Todas las rutas viven bajo `Route::middleware('auth')`. Responden **JSON**, sin recarga de página
(CLAUDE.md §5). Misma convención de respuesta que Categorías (`app/Http/Controllers/CategoriaController.php`):

```jsonc
// Éxito
{ "ok": true, "mensaje": "…", "vendedor": { "id": 1, "nombre": "…" } }
// Error de validación (nombre vacío/duplicado) → HTTP 422
{ "message": "…", "errors": { "nombre": ["…"] } }
// Eliminación rechazada por estar en uso → HTTP 422
{ "ok": false, "mensaje": "No se puede eliminar: está en uso." }
```

## ABM inline de Vendedores — usado desde Venta, Presupuesto, config. Tiendanube y config. MercadoLibre

| Método | Ruta | Nombre | Propósito | FR |
|---|---|---|---|---|
| POST | `/vendedores` | `vendedores.store` | Crear vendedor desde cualquiera de los 4 selects | FR-004 |
| PATCH | `/vendedores/{vendedor}` | `vendedores.update` | Renombrar vendedor | FR-005 |
| DELETE | `/vendedores/{vendedor}` | `vendedores.destroy` | Eliminar vendedor (si no está en uso) | FR-006 |

Un único conjunto de rutas/controlador sirve a los cuatro puntos de uso (Venta, Presupuesto, config.
Tiendanube, config. MercadoLibre) — no hay una ruta por pantalla, igual que Categorías tiene rutas
`store*` separadas por origen sólo porque necesita fijar el `tipo`; Vendedor no tiene `tipo`, así que
un único `store` alcanza.

### `POST /vendedores`

Body: `{ "nombre": "string, requerido, único" }`

| Situación | HTTP | Cuerpo |
|---|---|---|
| Correcta | 201 | `{ ok: true, mensaje: "Vendedor creado correctamente.", vendedor: {...} }` |
| Nombre vacío o duplicado | 422 | `{ message: "...", errors: { nombre: ["..."] } }` |

### `PATCH /vendedores/{vendedor}`

Body: `{ "nombre": "string, requerido, único (ignorando el propio id)" }`

| Situación | HTTP | Cuerpo |
|---|---|---|
| Correcta | 200 | `{ ok: true, mensaje: "Vendedor renombrado.", vendedor: {...} }` |
| Nombre vacío o duplicado | 422 | `{ message: "...", errors: { nombre: ["..."] } }` |

### `DELETE /vendedores/{vendedor}`

Sin cuerpo.

| Situación | HTTP | Cuerpo |
|---|---|---|
| Correcta (sin uso) | 200 | `{ ok: true, mensaje: "Vendedor eliminado." }` |
| En uso (Venta, Presupuesto, o default de alguna integración) | 422 | `{ ok: false, mensaje: "No se puede eliminar: está en uso." }` |

## Formularios de Venta y Presupuesto (extensión de contratos existentes)

`POST /ventas`, `PATCH /ventas/{venta}`, `POST /presupuestos`, `PATCH /presupuestos/{presupuesto}`
agregan el campo opcional:

| Campo | Regla | FR |
|---|---|---|
| `vendedor_id` | `nullable\|integer\|exists:vendedores,id` | FR-003 |

Sin cambios en el resto del contrato de esos endpoints (formato de respuesta, demás campos).

## Configuración de Tiendanube / MercadoLibre (extensión de contratos existentes)

`POST /configuracion/tiendanube/ventas` y `POST /configuracion/mercadolibre/ventas` (nombres de ruta
`configuracion.tiendanube.ventas.guardar` / `configuracion.mercadolibre.ventas.guardar`, ya
existentes) agregan el campo opcional:

| Campo | Regla | FR |
|---|---|---|
| `vendedor_id` | `nullable\|integer\|exists:vendedores,id` | FR-010 |

`GET /configuracion/tiendanube/estado` y su equivalente de MercadoLibre agregan `vendedor_id` al
bloque `configuracion` de la respuesta JSON (mismo lugar donde ya viaja `categoria_venta_id`).
