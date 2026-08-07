# Data Model: Editar cobranzas de una venta

No se agregan columnas ni tablas nuevas. Se documentan las entidades existentes que participan
en la edición, sin cambios de esquema.

## Cobro (`app/Models/Cobro.php`, tabla `cobros`)

| Campo | Tipo | Notas para esta feature |
|---|---|---|
| `id` | bigint | PK |
| `venta_id` | FK → `ventas` | inmutable en edición (no se reasigna un cobro a otra venta) |
| `fecha` | date | editable |
| `cuenta_tesoreria_id` | FK → `cuentas_tesoreria` | editable |
| `monto` | decimal:2 | editable, sujeto a `monto <= aCobrar(venta) + monto_actual` |
| `nota` | string, nullable | editable |
| `deleted_at` | timestamp, nullable (SoftDeletes) | un cobro con `deleted_at != null` NO es editable (FR-006) |

Relaciones relevantes: `venta()` BelongsTo Venta; `cuentaTesoreria()` BelongsTo CuentaTesoreria;
`movimientoTesoreria()` MorphOne MovimientoTesoreria (origen polimórfico).

## MovimientoTesoreria (`app/Models/MovimientoTesoreria.php`, tabla `movimientos_tesoreria`)

Al editar un `Cobro`, si existe su `movimientoTesoreria` (no debería faltar en operación normal,
ver Edge Cases del spec), se actualizan sus campos `monto`, `cuenta_tesoreria_id` y `fecha` para
igualarlos a los del cobro editado. No se toca `tipo` (`'cobro'`), ni `origen_type`/`origen_id`
(sigue apuntando al mismo `Cobro`).

## Transición de estado

```
Cobro activo (editable) --editar--> Cobro activo (con nuevos valores)
Cobro activo (editable) --anular--> Cobro anulado (soft-deleted, ya NO editable)
Cobro anulado --editar--> RECHAZADO (FR-006)
```

## Reglas de validación (edición)

- `monto`: requerido, numérico, > 0, `<= aCobrar(venta) + monto_actual_del_cobro`.
- `fecha`: requerida, fecha válida (misma regla que alta).
- `cuenta_tesoreria_id`: requerida, debe existir en `cuentas_tesoreria`.
- `nota`: opcional, string.
- El `Cobro` a editar debe pertenecer a la `Venta` de la ruta (`404` si no) y no estar
  soft-deleted (`422`/`404` si lo está).
