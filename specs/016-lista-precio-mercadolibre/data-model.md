# Data Model: Lista de Precios en la configuración de Mercado Libre

**Spec**: [../spec.md](../spec.md) · **Plan**: [../plan.md](../plan.md) · **Research**: [../research.md](../research.md)

No se crea ninguna tabla. Se extiende una tabla ya existente desde la spec 011/012
(`docs/modelo_datos.md`); no se toca el esquema de `ventas` (la columna `lista_precio_id` ya existe ahí
desde la spec 008 — esta spec sólo empieza a completarla también para Ventas de origen Mercado Libre).

## `ml_configuracion` (columna nueva)

Registro único, ya existente. Columna nueva — mismo rol de clasificación que las dos ya existentes:

| Campo | Tipo | Notas |
|---|---|---|
| `lista_precio_id` | `foreignId`, nullable, FK → `listas_precio.id`, `nullOnDelete()` | Lista de Precios que se asigna a toda Venta creada al convertir una orden de Mercado Libre (FR-003). `null` = sin Lista de Precios asignada (comportamiento actual, FR-004). No influye en el cálculo de precios (FR-005). Mismo tratamiento de borrado que `deposito_id`/`categoria_venta_id` (research.md R1). |

**Invariantes**:
- No hay validación de "Lista de Precios activa" al momento de convertir (mismo criterio que
  `categoria_venta_id`, ver spec Edge Cases y research.md R1).
- No se agrega ningún índice nuevo: es un registro único (`ml_configuracion` tiene una sola fila,
  `MercadoLibreConfiguracion::actual()`), sin necesidad de índice sobre esta columna.
- Cambiar el valor no reprocesa Ventas ya convertidas (FR-006): no hay ningún job, observer ni trigger
  que reaccione a un `UPDATE` de `ml_configuracion.lista_precio_id`.

## `ventas` — sin cambios de esquema, nuevo emisor

No se agrega ninguna columna: `ventas.lista_precio_id` ya existe (spec 008,
`docs/modelo_datos.md`, tabla `ventas`). Esta spec agrega un segundo camino que la completa —
`ConversorOrdenAVenta::convertir()` (origen Mercado Libre) — junto al ya existente (formulario manual de
Ventas, que la autocompleta desde el Cliente).

| Campo usado | Para qué |
|---|---|
| `ventas.lista_precio_id` | Se asigna en `Venta::create()` desde `MercadoLibreConfiguracion::actual()->lista_precio_id` al convertir una orden (research.md R3). |

## Relación en el modelo `MercadoLibreConfiguracion`

```php
public function listaPrecio(): BelongsTo
{
    return $this->belongsTo(\App\Models\ListaPrecio::class, 'lista_precio_id');
}
```

Mismo patrón exacto que `deposito()` y `categoriaVenta()`, ya presentes en el modelo.
