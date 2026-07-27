# Data Model — Productos & Servicios (Fase 1)

Derivado de `spec.md` y consistente con `docs/modelo_datos.md` §2. Motor SQL (MySQL/MariaDB),
migraciones de Laravel 12, nombres en español snake_case, single-tenant (sin `empresa_id`).

Tablas nuevas de esta feature: `depositos`, `productos`, `producto_variantes`, `precios_producto`,
`stocks`, `movimientos_stock`. Reutilizadas de 001-clientes: `listas_precio`. Referenciada opcional:
`proveedores` (feature futura — ver research D9).

---

## `depositos`

| Campo | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| nombre | string | ej. "Principal" (sembrado por defecto) |
| activo | boolean | default true (baja lógica) |
| timestamps | | |

- Seed: un depósito "Principal" activo (`DepositoSeeder`).
- No se elimina físicamente si tiene `stocks`/`movimientos_stock` asociados.

## `productos`

| Campo | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| nombre | string | **obligatorio** (FR-002) |
| codigo | string, nullable, único | SKU del producto base. Único global producto ∪ variante (FR-010, regla `SkuUnico`) |
| tipo | enum(`producto`,`servicio`) | default `producto`; `servicio` no controla stock (FR-003/FR-019) |
| proveedor_id | FK → proveedores, nullable | opcional; FK sólo si existe la tabla (research D9) |
| descripcion | text, nullable | |
| mostrar_en_ventas | boolean | default true (FR-008) |
| precio_venta | decimal(14,2) | ≥ 0 (FR-006/FR-007) |
| iva_venta_pct | decimal(5,2) | ≥ 0, default 21.00 |
| mostrar_en_compras | boolean | default true (FR-008) |
| costo | decimal(14,2) | ≥ 0, default 0 |
| iva_compra_pct | decimal(5,2) | ≥ 0, default 21.00 |
| activo | boolean | default true — baja lógica; no se elimina con operaciones (FR-020..FR-023) |
| timestamps | | |

Índices: `unique(codigo)`, index(`nombre`), index(`activo`), index(`tipo`), index(`proveedor_id`).

Métodos de dominio (modelo `Producto`):
- `esServicio(): bool`
- `controlaStock(): bool` → `!esServicio()`
- `tieneOperaciones(): bool` → hoy: existe algún `movimiento_stock` del producto o de sus variantes.
  Costura para ventas/compras futuras (research D8).
- `stockTotal(): decimal` → suma de `stocks` del producto (y sus variantes) en todos los depósitos.
- Relaciones: `proveedor()` belongsTo (nullable), `variantes()` hasMany, `precios()` hasMany
  (`precios_producto`), `stocks()` hasMany, `movimientos()` hasMany (`movimientos_stock`).

## `producto_variantes`

| Campo | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| producto_id | FK → productos | cascade on delete |
| sku | string, nullable, único | único global producto ∪ variante (FR-010/FR-011) |
| talle | string, nullable | |
| color | string, nullable | |
| nombre | string, nullable | etiqueta libre si no aplica talle/color |
| precio_extra | decimal(14,2), nullable | diferencia sobre el precio base (opcional) |
| activo | boolean | default true |
| timestamps | | |

- Un producto sin variantes no lleva filas (FR-013). No se elimina una variante con stock/movimientos
  (FR-012 → misma regla que producto).
- Relaciones: `producto()` belongsTo, `stocks()` hasMany, `movimientos()` hasMany.

## `precios_producto`

| Campo | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| producto_id | FK → productos | cascade on delete |
| lista_precio_id | FK → listas_precio | |
| precio | decimal(14,2) | ≥ 0 |
| timestamps | | |

- Único por `(producto_id, lista_precio_id)` (FR-014). Ausencia ⇒ se usa `precio_venta` base (FR-015).

## `stocks`  (foto del stock actual)

| Campo | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| producto_id | FK → productos | cascade on delete |
| variante_id | FK → producto_variantes, nullable | si el producto tiene variantes (research D4) |
| deposito_id | FK → depositos | |
| cantidad | decimal(14,3) | puede ser negativo por ajuste manual (research D7) |
| timestamps | | |

- Único por `(producto_id, variante_id, deposito_id)`. Es la **foto**; el histórico va en
  `movimientos_stock` (research D3). Consultar un producto en un depósito sin movimientos ⇒ 0.

## `movimientos_stock`  (histórico)

| Campo | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| producto_id | FK → productos | |
| variante_id | FK → producto_variantes, nullable | |
| deposito_id | FK → depositos | |
| tipo | enum(`entrada`,`salida`,`ajuste`) | en esta feature el ajuste manual usa `ajuste` (aumento/disminución) |
| cantidad | decimal(14,3) | magnitud del movimiento (con signo según aumento/disminución) |
| descripcion | string, nullable | motivo del ajuste manual |
| origen_type / origen_id | nullable morph | polimórfico → venta_items/compra_items (futuro); null para ajuste manual |
| fecha | date | default hoy |
| usuario_id | FK → usuarios, nullable | quién generó el movimiento |
| timestamps | | |

- Índices: index(`producto_id`), index(`variante_id`), index(`deposito_id`), index(`fecha`).
- Se crea siempre junto con la actualización de `stocks`, en la misma transacción (`StockService`).

---

## Reglas de validación (desde Requirements)

| Regla | Origen | Dónde se aplica |
|---|---|---|
| `nombre` requerido | FR-002 | Store/UpdateProductoRequest |
| `tipo` in producto/servicio | FR-003 | Store/UpdateProductoRequest |
| `codigo`/`sku` único global (producto ∪ variante), ignora NULL y el propio | FR-010 | Regla `SkuUnico` + unique en BD |
| `precio_venta`, `costo` numeric ≥ 0 | FR-006/FR-007 | Store/UpdateProductoRequest |
| `iva_venta_pct`, `iva_compra_pct` numeric ≥ 0 | FR-006/FR-007 | Store/UpdateProductoRequest |
| `precios.*.precio` numeric ≥ 0; `lista_precio_id` exists | FR-014 | Store/UpdateProductoRequest |
| variante: `sku` único (FR-010); a lo sumo talle/color/nombre | FR-011 | Store/UpdateProductoRequest |
| ajuste de stock sólo sobre tipo producto (no servicio) | FR-019/SC-007 | AjusteStockRequest / StockService |
| `cantidad` de ajuste numeric ≠ 0; `deposito_id` exists | FR-017 | AjusteStockRequest |
| no eliminar producto/variante con operaciones | FR-012/FR-020 | Controller (409) vía `tieneOperaciones()` |

## Consistencia con docs de dominio

Todas las tablas y reglas ya figuran en `docs/modelo_datos.md` §2 y `docs/documentacion_principal_crm.md`
§5.2. **No se detectan campos ni reglas nuevas** que obliguen a actualizar los docs de dominio en esta
feature (Principio I). Única precisión de tipos adoptada aquí (no contradice el doc): `decimal(14,3)`
para cantidades de stock y `decimal(14,2)` para importes — si se quisiera fijar esa precisión en el doc
de modelo, sería un ajuste PATCH menor, no un cambio de dominio.
