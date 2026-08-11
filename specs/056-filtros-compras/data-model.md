# Data Model: Filtros del listado de Compras

Esta feature no crea entidades nuevas. Extiende `compras` con una columna y una relación, y le agrega a `Compra` una relación polimórfica ya soportada por el esquema existente.

## `compras` (tabla existente — un cambio)

| Campo | Tipo | Notas |
|---|---|---|
| `creado_por_id` | bigint, nullable, FK → `users.id`, `nullOnDelete()` | **Columna nueva.** Usuario que creó la Compra. Se setea únicamente en `store()` a partir de esta feature (`auth()->id()`); las compras existentes quedan con `NULL` (sin backfill — no hay forma confiable de reconstruir ese dato para el histórico). Habilita el filtro "Usuario" del listado. Mismo criterio ya usado para `deposito_id` en spec 049. |

Sin cambios en el resto de columnas de `compras` (ver `docs/modelo_datos.md` §7 para el esquema completo vigente).

## Relación nueva: `Compra` ↔ `Etiqueta` (many-to-many polimórfica)

- **Tabla pivote**: `etiquetables` (ya existe, `database/migrations/2026_07_30_060001_create_etiquetas_tables.php`) — `etiqueta_id`, `etiquetable_type`, `etiquetable_id`, unique compuesta. **Sin cambios de esquema**: la tabla ya es genérica y admite cualquier tipo etiquetable.
- **Método nuevo en `App\Models\Compra`**: `etiquetas(): MorphToMany` → `$this->morphToMany(Etiqueta::class, 'etiquetable')`, idéntico a `Venta::etiquetas()`.
- **Relación inversa**: `Etiqueta` ya expone sus `morphedByMany` genéricos (usados por Venta); no requiere cambios para admitir Compra como nuevo tipo etiquetable.

## Relación nueva: `Compra` → `User` (creador)

- **Método nuevo en `App\Models\Compra`**: `creadoPor(): BelongsTo` → `$this->belongsTo(User::class, 'creado_por_id')`, idéntico a `Venta::creadoPor()`.

## Superficie de filtrado (no persistida — solo query params del listado)

No son columnas nuevas, son criterios de consulta sobre datos/relaciones ya existentes:

| Filtro | Resuelto contra |
|---|---|
| Id | `compras.id` (igualdad exacta) |
| Proveedor (multi) | `compras.proveedor_id IN (...)` |
| Categoría de Compra (multi) | `compras.categoria_id IN (...)` |
| Estado del Pago | derivado — `Compra::estadoPago()` (a_pagar / parcial / pagado), resuelto en SQL vía subconsulta agregada (ver research.md Decisión 4) |
| Tipo y N° de Factura | `compras.tipo_comprobante LIKE` OR `comprobantes_fiscales.numero LIKE` (vía `comprobanteFiscal`) |
| Etiqueta (multi) | `etiquetables` vía `Compra::etiquetas()` |
| Facturado | `whereHas`/`whereDoesntHave('comprobanteFiscal')` |
| Medio de pago | `pagos.cuenta_tesoreria_id` (vía `Compra::pagos()`) |
| Usuario (multi) | `compras.creado_por_id IN (...)` |
| Nota Interna | `compras.nota_interna LIKE` |
| Depósito | `compras.deposito_id` (columna directa, ya existente desde spec 049) |
| Desde/Hasta Servicio | `compras.servicio_desde` / `compras.servicio_hasta` (rango) |
| Rango Emisión (existente) | `compras.fecha_emision` (rango) |
| Rango Vencimiento (nuevo) | `compras.fecha_vto_pago` (rango) |

Todos combinados con AND entre campos distintos; los de selección múltiple usan OR dentro del propio campo (`whereIn`).
