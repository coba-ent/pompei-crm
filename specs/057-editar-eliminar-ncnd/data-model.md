# Data Model: Edición y eliminación de NC/ND

## `notas_credito_debito` (existente — extendida)

Campos actuales (sin cambios): `id`, `legacy_id`, `venta_id` (FK nullable), `compra_id` (FK
nullable), `tipo` (enum `credito`/`debito`), `afecta_stock` (bool), `mes_imputacion` (date),
`fecha_emision` (date), `monto` (decimal 14,2), `tipo_comprobante` (string), `descripcion` (text
nullable), `impuestos` (json nullable), `created_at`/`updated_at`/`deleted_at`.

**Campos nuevos** (migración de esta feature):

| Campo | Tipo | Notas |
|---|---|---|
| `nro_comprobante` | string, nullable | Número propio de la nota (editable en el wizard). `tipo_comprobante` ya existía y pasa a ser editable junto con éste. |
| `nota_ajustada_id` | FK nullable → `notas_credito_debito.id` | "Documento que Ajusta" cuando apunta a otra NC/ND en vez de al comprobante original. Exactamente uno de `venta_id`/`compra_id`/`nota_ajustada_id` debe estar seteado (regla de negocio validada en el FormRequest, no constraint de DB — mismo criterio que ya rige `venta_id`/`compra_id`). |

**Regla de integridad (aplicación, no DB)**: `nota_ajustada_id` sólo puede apuntar a una nota cuyo
propio `nota_ajustada_id` sea `NULL` (limita el encadenamiento a 1 nivel — FR-013).

**Regla de eliminación (aplicación)**: no se permite `delete()` sobre una nota mientras exista
otra `NotaCreditoDebito` no eliminada con `nota_ajustada_id` apuntándole (FR-006).

**Regla de bloqueo por CAE**: no se permite `update()` ni `delete()` si
`$nota->comprobanteFiscal?->aprobado() === true` (FR-011).

### Relaciones nuevas en `NotaCreditoDebito`

```php
public function notaAjustada(): BelongsTo
{
    return $this->belongsTo(NotaCreditoDebito::class, 'nota_ajustada_id');
}

public function notasQueLaAjustan(): HasMany
{
    return $this->hasMany(NotaCreditoDebito::class, 'nota_ajustada_id');
}
```

## `nota_credito_debito_items` (existente — extendida)

Campos actuales: `id`, `nota_credito_debito_id`, `producto_id`, `cantidad`, `precio`, `origen`
(enum `venta_original`/`nuevo`).

**Campos nuevos**:

| Campo | Tipo | Notas |
|---|---|---|
| `descuento_pct` | decimal(5,2), nullable, default 0 | % Bonif. del renglón, igual que `venta_items`/`compra_items`. |
| `iva_pct` | decimal(5,2), nullable | Alícuota IVA del renglón (mismo catálogo que Venta/Compra: 2.5/5/10.5/21/27). |

`precio` ya existe y pasa a interpretarse como "Precio Unitario" del renglón (hoy sólo se llenaba
si `afecta_stock = true`; pasa a llenarse siempre que haya ítems, independientemente del flag).

**Regla de negocio**: los ítems dejan de estar condicionados a `afecta_stock = true` — se guardan
si el usuario cargó al menos un renglón en el paso 2 del wizard (con o sin `producto_id`, para
soportar conceptos de servicio sin producto asociado — ver Edge Cases del spec sobre el 6% de
renglones sin producto identificable en la migración histórica, mismo criterio aplicado acá).
**Confirmado**: `producto_id` es hoy `foreignId()` **NOT NULL** en el esquema actual
(`2026_07_30_060006_create_notas_credito_debito_tables.php:31`) — la migración de esta feature
debe hacerlo `nullable()`.

## Entidades sin cambios de esquema, pero con nueva lógica

- **`ComprobanteFiscal`** (polimórfica `comprobantable`): sin cambios de esquema. Se consulta
  (`$nota->comprobanteFiscal?->aprobado()`) para el gate de bloqueo por CAE (FR-011). Ninguna nota
  con CAE aprobado puede pasar por `update()`/`destroy()`.
- **`movimientos_stock`** / **`stocks`**: sin cambios de esquema. La reversión de stock (research.md
  §1) genera un movimiento inverso nuevo en vez de mutar uno existente — mismo patrón append-only
  ya usado en el resto del sistema.

## Validaciones (resumen, detalle en `contracts/`)

| Regla | Origen |
|---|---|
| Tipo (crédito/débito) no editable una vez creada | Assumptions del spec — captura real muestra el campo deshabilitado en edición |
| `nro_comprobante`+`tipo_comprobante` únicos contra Venta/Compra/NotaCreditoDebito | FR-012 |
| Cantidad de ítems con stock no puede superar "pendiente" excluyendo la nota en edición | FR-005 |
| No editar/eliminar si `comprobanteFiscal.aprobado() === true` | FR-011 |
| No eliminar si existen `notasQueLaAjustan()` no eliminadas | FR-006 |
| `nota_ajustada_id` sólo puede apuntar a una nota de "nivel 0" (sin su propio `nota_ajustada_id`) | FR-013 |
