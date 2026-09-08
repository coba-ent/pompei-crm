# Data Model: Vendedores — activar/desactivar

## Vendedor (tabla `vendedores`, existente — se modifica)

| Campo | Tipo | Notas |
|---|---|---|
| `id` | bigint, PK | existente |
| `nombre` | string(255), unique | existente |
| `activo` | boolean, default `true`, not null | **nuevo** (spec 101). Migración agrega la columna con default `true`, por lo que todos los vendedores existentes quedan Activos sin necesidad de backfill manual. |
| `created_at`/`updated_at` | timestamps | existentes |

**Reglas de negocio**:
- `activo = true` en el alta, tanto desde el tab Vendedores como desde el buscador inline de
  Venta/Presupuesto (FR-001, FR-007).
- La unicidad de `nombre` sigue aplicando sin distinción de estado (FR-009): no se puede reutilizar
  el nombre de un vendedor inactivo mientras el registro exista.
- No hay transición de estado restringida por uso: un vendedor con Ventas/Presupuestos asociados
  puede desactivarse y reactivarse libremente (FR-002) — a diferencia de `destroy`, que sigue
  pudiendo fallar por FK en uso (comportamiento ya existente, sin cambios).

**Relaciones** (sin cambios): `Venta.vendedor_id`, `Presupuesto.vendedor_id`,
`ConfiguracionVentas.vendedor_id`, `MercadoLibreConfiguracion.vendedor_id`,
`TiendanubeConexionRest.vendedor_id` — todas `belongsTo(Vendedor::class)`, todas siguen resolviendo
igual sin importar el estado del vendedor referenciado (FR-004).

**Scope nuevo**:
```php
public function scopeActivos(Builder $query): Builder
{
    return $query->where('activo', true);
}
```

## Sin entidades nuevas

No se agregan tablas ni modelos nuevos. El cambio es aditivo sobre `vendedores` y de lectura sobre
los consumidores existentes listados en `plan.md`.
